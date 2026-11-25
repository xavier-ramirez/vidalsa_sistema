<?php

namespace App\Services\Consumibles;

use App\Http\Controllers\ConsumiblesController;
use App\Models\Consumible;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Identifica el equipo de los consumos PENDIENTES y POR IDENTIFICAR (antes, el botón
 * «Ejecutar Match» de la lista). Lo hace el servidor solo:
 *  - al guardar filas en Cargar consumibles y al editar el identificador (solo esas filas);
 *  - al registrar, corregir o restaurar un equipo, su placa o un auxiliar, o al enseñar un
 *    alias (pedirReintento): queda una marca y el programador lo reintenta todo en el minuto,
 *    en segundo plano (no al final de la petición: con Apache mod_php eso hacía esperar a quien
 *    guardaba); la del minuto no se encima consigo misma (withoutOverlapping) y si cae justo
 *    antes de la de la hora, esta no repite la pasada completa (COMPLETA_RECIENTE);
 *  - el programador de tareas: cada minuto si quedó algo PENDIENTE o hay marca, y cada hora todo
 *    lo POR IDENTIFICAR (lo que entró sin eventos de modelo).
 * El vigía en tiempo real (cambios:avisar) ve el cambio en la tabla y la lista se repinta sola
 * en todos los navegadores.
 */
class MatchConsumibles
{
    /** Pedido de reintento (pedirReintento): lo atiende la pasada del minuto. */
    public const MARCA_REINTENTO = 'consumibles:reintentar-match';

    /** Hubo una pasada completa hace menos de 5 minutos: la de la hora no la repite. */
    public const COMPLETA_RECIENTE = 'consumibles:match-completo-reciente';

    /** Cambió algo con lo que se identifica un equipo: el programador reintenta en el minuto. */
    public static function pedirReintento(): void
    {
        try {
            Cache::put(self::MARCA_REINTENTO, true, now()->addDay());
        } catch (\Throwable $e) {
            report($e);   // nunca rompe el guardado del equipo; la pasada de cada hora lo cubre
        }
    }

    /** Lo que corre el programador cada minuto: lo pedido (todo) o, si no, solo lo PENDIENTE. */
    public static function pasadaDelMinuto(): void
    {
        if (Cache::has(self::MARCA_REINTENTO)) {
            Cache::forget(self::MARCA_REINTENTO);
            try {
                (new self())->ejecutar();
            } catch (\Throwable $e) {
                self::pedirReintento();   // falló (deadlock, BD caída): se reintenta el minuto siguiente
                throw $e;
            }

            return;
        }
        (new self())->ejecutar(Consumible::where('ESTADO_EQUIPO', 'PENDIENTE')->pluck('ID_CONSUMIBLE')->all());
    }

    /** La de cada hora: no se repite si la del minuto acaba de hacer la pasada completa. */
    public static function pasadaDeLaHora(): void
    {
        if (!Cache::has(self::COMPLETA_RECIENTE)) {
            (new self())->ejecutar();
        }
    }

    public static function hayTrabajoDelMinuto(): bool
    {
        return Cache::has(self::MARCA_REINTENTO)
            || Consumible::where('ESTADO_EQUIPO', 'PENDIENTE')->exists();
    }

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
        // Solo equipos activos: a uno dado de baja no se le asignan consumos nuevos (los que ya
        // tenía los conserva: Consumible::equipo() lo trae con withTrashed).
        $equipos = DB::table('equipos')->whereNull('deleted_at')
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
            ->filter(fn ($d) => $util($d->txt) && $equipos->has($d->id))->values();
        $porPlaca = [];
        foreach ($placas as $d) { $porPlaca[$d->txt] ??= $d->id; }

        $porCodigo = $porSerial = $textosEquipo = [];
        foreach ($equipos as $e) {
            $cp = $norm($e->CODIGO_PATIO); if ($util($cp)) { $porCodigo[$cp] ??= $e->ID_EQUIPO; }
            $sc = $norm($e->SERIAL_CHASIS); if ($util($sc)) { $porSerial[$sc] ??= $e->ID_EQUIPO; }
            $textosEquipo[$e->ID_EQUIPO] = [$sc, $cp];   // normalizados una vez (el parcial los recorre por consumo)
        }

        // Auxiliares (máquina de soldar, planta, compresor…): por su código o serial EXACTO.
        // Sin esto, lo escrito a mano con el código de un auxiliar quedaba siempre por identificar
        // (lo del Excel sí los encontraba, por IdentificadorConsumo).
        $porAuxiliar = [];
        foreach (DB::table('equipos_auxiliares')->whereNull('deleted_at')->select('ID_AUXILIAR', 'CODIGO_INTERNO', 'SERIAL')->get() as $a) {
            foreach ([$a->CODIGO_INTERNO, $a->SERIAL] as $v) {
                $v = $norm($v); if ($util($v)) { $porAuxiliar[$v] ??= $a->ID_AUXILIAR; }
            }
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

            // 4. Código o serial EXACTO de un auxiliar: antes que el parcial, que si no se lo lleva
            // el equipo cuyo código CONTIENE ese texto.
            if (!$equipo && isset($porAuxiliar[$id])) {
                $aConfirmarAux[$porAuxiliar[$id]][] = $c->ID_CONSUMIBLE;
                $confirmados++;
                continue;
            }

            // 5. Coincidencia PARCIAL (minimo 4 caracteres, para evitar falsos positivos).
            // Misma regla que antes -el identificador contenido en la placa, el serial o el
            // codigo de patio- y sigue exigiendo que resuelva a UN solo equipo.
            if (!$equipo && mb_strlen($id) >= 4) {
                $idsDoc = [];
                foreach ($placas as $d) {
                    if (str_contains($d->txt, $id)) { $idsDoc[$d->id] = true; }
                }
                $idsEq = [];
                foreach ($textosEquipo as $idE => [$sc, $cp]) {
                    if (str_contains($sc, $id) || str_contains($cp, $id)) {
                        $idsEq[$idE] = true;
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
        // Cada UPDATE solo toca el consumo si sigue como se leyó (sin confirmar y con el mismo
        // identificador): si entretanto alguien corrigió el identificador, no se le pisa.
        $leidos = $pendientes->merge($deExcel)->pluck('IDENTIFICADOR', 'ID_CONSUMIBLE');
        $comoSeLeyo = fn (array $ids) => Consumible::whereIn('ID_CONSUMIBLE', $ids)
            ->whereIn('ESTADO_EQUIPO', ['PENDIENTE', 'SIN_MATCH'])
            ->whereIn('IDENTIFICADOR', array_values(array_unique(array_map(fn ($i) => $leidos[$i], $ids))));
        DB::transaction(function () use ($aConfirmar, $aConfirmarAux, $sinEquipo, $aSinMatch, $comoSeLeyo) {
            foreach ($aConfirmar as $idEquipo => $idsConsumible) {
                $comoSeLeyo($idsConsumible)->update([
                    'ID_EQUIPO'     => $idEquipo,
                    'ID_AUXILIAR'   => null,
                    'ESTADO_EQUIPO' => 'CONFIRMADO',
                ]);
            }
            foreach ($aConfirmarAux as $idAux => $idsConsumible) {
                $comoSeLeyo($idsConsumible)->update([
                    'ID_EQUIPO'     => null,
                    'ID_AUXILIAR'   => $idAux,
                    'ESTADO_EQUIPO' => 'CONFIRMADO',
                ]);
            }
            if ($sinEquipo) {
                $comoSeLeyo($sinEquipo)->update(['ID_EQUIPO' => null, 'ID_AUXILIAR' => null, 'ESTADO_EQUIPO' => 'CONFIRMADO']);
            }
            if ($aSinMatch) {
                $comoSeLeyo($aSinMatch)->where('ESTADO_EQUIPO', 'PENDIENTE')->update(['ESTADO_EQUIPO' => 'SIN_MATCH']);
            }
        });

        // Los gráficos tienen caché: el match cambia los equipos de los consumos. Si todo
        // siguió POR IDENTIFICAR no cambió nada.
        if ($confirmados || $sinIdentificador || $pendientes->merge($deExcel)->contains('ESTADO_EQUIPO', 'PENDIENTE')) {
            CacheVersion::bump(ConsumiblesController::DATA_VER_KEY);
        }

        if ($ids === null) {
            Cache::put(self::COMPLETA_RECIENTE, true, now()->addMinutes(5));   // la de la hora no la repite
        }

        return ['total' => $deExcel->count() + $pendientes->count(), 'confirmados' => $confirmados, 'sin_match' => $sinMatch + $sinIdentificador];
    }
}
