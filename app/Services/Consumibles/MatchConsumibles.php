<?php

namespace App\Services\Consumibles;

use App\Http\Controllers\ConsumiblesController;
use App\Models\Consumible;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\DB;

/**
 * Identifica el equipo de los consumos PENDIENTES y POR IDENTIFICAR (antes, el botón
 * «Ejecutar Match» de la lista). Lo hace el servidor solo:
 *  - al guardar filas en Cargar consumibles y al editar el identificador (solo esas filas);
 *  - el programador de tareas (consumibles:match): cada minuto si quedó algo PENDIENTE (p. ej.
 *    una fila escrita a mano en la base) y cada hora todo lo POR IDENTIFICAR, por si ya se
 *    registró el equipo o su placa.
 */
class MatchConsumibles
{
    /**
     * @param  array<int>|null  $ids  Solo esos consumos; null = todos.
     * @return array{total:int,confirmados:int,sin_match:int}
     */
    public function ejecutar(?array $ids = null): array
    {
        // Un PENDIENTE sin identificador no tiene con qué buscarse: queda «Por identificar»
        // (se le escribe el identificador a mano y vuelve a PENDIENTE). Antes se quedaba
        // pendiente para siempre y el contador del Match nunca bajaba.
        $sinIdentificador = Consumible::where('ESTADO_EQUIPO', 'PENDIENTE')
            ->when($ids !== null, fn ($q) => $q->whereIn('ID_CONSUMIBLE', $ids))
            ->where(fn ($q) => $q->whereNull('IDENTIFICADOR')->orWhere('IDENTIFICADOR', ''))
            ->update(['ESTADO_EQUIPO' => 'SIN_MATCH']);

        // Procesa PENDIENTE y SIN_MATCH — permite reintentar los no encontrados
        $pendientes = Consumible::whereIn('ESTADO_EQUIPO', ['PENDIENTE', 'SIN_MATCH'])
            ->when($ids !== null, fn ($q) => $q->whereIn('ID_CONSUMIBLE', $ids))
            ->whereNotNull('IDENTIFICADOR')
            ->where('IDENTIFICADOR', '!=', '')
            ->get();
        if ($pendientes->isEmpty()) {
            if ($sinIdentificador) {
                CacheVersion::bump(ConsumiblesController::DATA_VER_KEY);
            }

            return ['total' => 0, 'confirmados' => 0, 'sin_match' => $sinIdentificador];
        }

        // Índices en memoria, una sola vez (no hasta 5 consultas por fila). Caben de sobra:
        // ~1.000 placas y ~1.200 equipos, solo las columnas que el match necesita.
        $equipos = DB::table('equipos')
            ->select('ID_EQUIPO', 'CODIGO_PATIO', 'SERIAL_CHASIS', 'MARCA', 'MODELO')
            ->get()->keyBy('ID_EQUIPO');

        $norm = fn ($v) => mb_strtoupper(trim((string) $v));
        // «_», «-», «/»: relleno, no una placa ni un serial (hay placas guardadas así). Ni
        // se indexan ni se buscan: si no, «_» se emparejaba con el equipo de esa placa.
        $util = fn (string $v) => preg_match('/[\p{L}\p{N}]/u', $v) === 1;

        // placa normalizada -> ID_EQUIPO (y la lista completa para el parcial)
        $placas = DB::table('documentacion')
            ->whereNotNull('PLACA')->where('PLACA', '!=', '')
            ->select('ID_EQUIPO', 'PLACA')->get()
            ->map(fn ($d) => (object) ['id' => $d->ID_EQUIPO, 'txt' => $norm($d->PLACA)])
            ->filter(fn ($d) => $util($d->txt))->values();
        $porPlaca = [];
        foreach ($placas as $d) { $porPlaca[$d->txt] ??= $d->id; }

        $porCodigo = $porSerial = [];
        foreach ($equipos as $e) {
            $cp = $norm($e->CODIGO_PATIO); if ($util($cp)) { $porCodigo[$cp] ??= $e->ID_EQUIPO; }
            $sc = $norm($e->SERIAL_CHASIS); if ($util($sc)) { $porSerial[$sc] ??= $e->ID_EQUIPO; }
        }

        $confirmados = 0;
        $sinMatch = 0;
        // Se acumulan para aplicarlos en bloque al final, no un UPDATE por fila.
        $aConfirmar = [];   // [ID_EQUIPO => [ID_CONSUMIBLE, ...]]
        $aConfirmarAux = [];// [ID_AUXILIAR => [ID_CONSUMIBLE, ...]]
        $sinEquipo  = [];   // [ID_CONSUMIBLE, ...] enseñados como «no es un equipo»
        $aSinMatch  = [];   // [ID_CONSUMIBLE, ...]

        // Los que vinieron de un Excel de gasoil (Cargar → subir Excel) se resuelven con las MISMAS reglas y alias de
        // esa carga (IdentificadorConsumo): su IDENTIFICADOR es el texto del Excel, no una placa
        // limpia, y la coincidencia parcial de abajo podría dar un equipo de otro tipo.
        $deExcel = $pendientes->whereNotNull('ID_IMPORTACION');
        $pendientes = $pendientes->whereNull('ID_IMPORTACION');
        $ident = $deExcel->isNotEmpty() ? new IdentificadorConsumo() : null;
        foreach ($deExcel as $c) {
            $r = $ident->resolverEquipo(['desc' => $c->IDENTIFICADOR]);
            if ($r['estado'] === 'SIN_MATCH') {
                $aSinMatch[] = $c->ID_CONSUMIBLE;
                $sinMatch++;
            } else {
                if ($r['id_equipo']) {
                    $aConfirmar[$r['id_equipo']][] = $c->ID_CONSUMIBLE;
                } elseif ($r['id_auxiliar']) {
                    $aConfirmarAux[$r['id_auxiliar']][] = $c->ID_CONSUMIBLE;
                } else {
                    $sinEquipo[] = $c->ID_CONSUMIBLE;
                }
                $confirmados++;
            }
        }

        foreach ($pendientes as $c) {
            $id = $norm($c->IDENTIFICADOR);
            $equipo = null;
            if (!$util($id)) {
                $aSinMatch[] = $c->ID_CONSUMIBLE;
                $sinMatch++;
                continue;
            }

            // 1. Por placa EXACTA
            if (isset($porPlaca[$id])) {
                $equipo = $equipos->get($porPlaca[$id]);
            }
            // 2. Por CODIGO_PATIO exacto (etiqueta interna)
            if (!$equipo && isset($porCodigo[$id])) {
                $equipo = $equipos->get($porCodigo[$id]);
            }
            // 3. Por serial de chasis exacto
            if (!$equipo && isset($porSerial[$id])) {
                $equipo = $equipos->get($porSerial[$id]);
            }

            // 4. Coincidencia PARCIAL (minimo 4 caracteres, para evitar falsos positivos).
            // Misma regla que antes -el identificador contenido en la placa, el serial o el
            // codigo de patio- y sigue exigiendo que resuelva a UN solo equipo.
            if (!$equipo && mb_strlen($id) >= 4) {
                $idsDoc = [];
                foreach ($placas as $d) {
                    if (str_contains($d->txt, $id)) { $idsDoc[$d->id] = true; }
                }
                $idsEq = [];
                foreach ($equipos as $e) {
                    if (str_contains($norm($e->SERIAL_CHASIS), $id) || str_contains($norm($e->CODIGO_PATIO), $id)) {
                        $idsEq[$e->ID_EQUIPO] = true;
                    }
                }
                $todos = array_keys($idsDoc + $idsEq);
                if (count($todos) === 1) {
                    $equipo = $equipos->get($todos[0]);
                }
            }

            // — Actualizar el registro —
            if ($equipo) {
                $aConfirmar[$equipo->ID_EQUIPO][] = $c->ID_CONSUMIBLE;
                $confirmados++;
            } else {
                $aSinMatch[] = $c->ID_CONSUMIBLE;
                $sinMatch++;
            }
        }

        // Los cambios, en bloque: un UPDATE por equipo confirmado y uno para los que pasan a
        // POR IDENTIFICAR (los que ya lo estaban no se tocan). Consumible no tiene observer.
        DB::transaction(function () use ($aConfirmar, $aConfirmarAux, $sinEquipo, $aSinMatch) {
            foreach ($aConfirmar as $idEquipo => $idsConsumible) {
                Consumible::whereIn('ID_CONSUMIBLE', $idsConsumible)->update([
                    'ID_EQUIPO'     => $idEquipo,
                    'ID_AUXILIAR'   => null,
                    'ESTADO_EQUIPO' => 'CONFIRMADO',
                ]);
            }
            foreach ($aConfirmarAux as $idAux => $idsConsumible) {
                Consumible::whereIn('ID_CONSUMIBLE', $idsConsumible)->update([
                    'ID_EQUIPO'     => null,
                    'ID_AUXILIAR'   => $idAux,
                    'ESTADO_EQUIPO' => 'CONFIRMADO',
                ]);
            }
            if ($sinEquipo) {
                Consumible::whereIn('ID_CONSUMIBLE', $sinEquipo)->update(['ID_EQUIPO' => null, 'ID_AUXILIAR' => null, 'ESTADO_EQUIPO' => 'CONFIRMADO']);
            }
            if ($aSinMatch) {
                Consumible::whereIn('ID_CONSUMIBLE', $aSinMatch)->where('ESTADO_EQUIPO', '!=', 'SIN_MATCH')->update(['ESTADO_EQUIPO' => 'SIN_MATCH']);
            }
        });

        // Los gráficos tienen caché: el match cambia los equipos de los consumos. Si todo
        // siguió POR IDENTIFICAR no cambió nada.
        if ($confirmados || $sinIdentificador || $pendientes->merge($deExcel)->contains('ESTADO_EQUIPO', 'PENDIENTE')) {
            CacheVersion::bump(ConsumiblesController::DATA_VER_KEY);
        }

        return ['total' => $deExcel->count() + $pendientes->count(), 'confirmados' => $confirmados, 'sin_match' => $sinMatch + $sinIdentificador];
    }
}
