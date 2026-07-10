<?php

namespace App\Console\Commands;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Services\InventarioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Separa un almacén PROYECTO que sirve a VARIOS frentes en un almacén por frente.
 *
 * Por qué: `almacen_stock` es UNIQUE (ID_ALMACEN, ID_PRODUCTO) — un producto tiene UN
 * saldo por almacén, sin frente. Un almacén con N frentes mezcla el inventario de N
 * proyectos en un solo número. El resto del sistema ya asume "un frente → un almacén"
 * (ver AlmacenController::registrarSalidaViaTraspaso, que aborta si un frente tiene más
 * de un almacén PROYECTO), así que separar los almacenes pone los datos donde el código
 * ya los espera, sin tocar InventarioService ni el kardex.
 *
 * Qué hace, por cada almacén PROYECTO con más de un frente:
 *   1. Elige un frente ANCLA que se queda en el almacén original (el de más movimientos).
 *   2. Crea un almacén PROYECTO por cada frente restante, ligado sólo a ese frente.
 *   3. Reapunta los traspasos aún abiertos (BORRADOR/ENVIADO) al almacén de su
 *      ID_FRENTE_DESTINO — el dato ya existe en cada traspaso.
 *   4. Traslada el stock ATRIBUIBLE con movimientos reales (SALIDA en el origen +
 *      ENTRADA en el destino), no con un UPDATE: así el kardex de ambos almacenes
 *      explica el saldo y nada queda "aparecido de la nada".
 *
 * Un saldo es ATRIBUIBLE si TODOS los movimientos de ese producto en ese almacén que
 * llevan ID_FRENTE apuntan a un mismo frente, y ese frente es uno de los del almacén.
 * Lo demás se reporta y se queda en el ancla: el sistema no puede adivinar de quién es
 * (los AJUSTE de un almacén multi-frente nunca guardan frente, a propósito — ver
 * AlmacenController::frenteImplicitoDelAlmacen).
 *
 * El kardex histórico NO se reescribe: los movimientos viejos siguen apuntando al
 * almacén original, que es donde de verdad ocurrieron.
 */
class SepararAlmacenesPorFrente extends Command
{
    protected $signature = 'almacen:separar-frentes
                            {--id-almacen= : Sólo este almacén (por defecto, todos los PROYECTO multi-frente)}
                            {--ancla= : ID del frente que se queda en el almacén original}
                            {--ejecutar : Aplica los cambios. Sin este flag es una simulación}';

    protected $description = 'Separa un almacén PROYECTO multi-frente en un almacén por frente (inventario por proyecto)';

    public function handle(InventarioService $inventario): int
    {
        $ejecutar = (bool) $this->option('ejecutar');

        $almacenes = Almacen::with('frentes')
            ->where('TIPO', Almacen::TIPO_PROYECTO)
            ->when($this->option('id-almacen'), fn ($q) => $q->where('ID_ALMACEN', (int) $this->option('id-almacen')))
            ->get()
            ->filter(fn ($a) => $a->frentes->count() > 1);

        if ($almacenes->isEmpty()) {
            $this->info('No hay almacenes PROYECTO con más de un frente. Nada que separar.');
            return self::SUCCESS;
        }

        $this->line($ejecutar
            ? '<fg=red>MODO EJECUCIÓN — se van a aplicar los cambios.</>'
            : '<fg=yellow>SIMULACIÓN (--dry-run implícito). Añade --ejecutar para aplicar.</>');

        foreach ($almacenes as $almacen) {
            $this->newLine();
            $this->line("<options=bold>Almacén #{$almacen->ID_ALMACEN} — {$almacen->NOMBRE}</> ({$almacen->frentes->count()} frentes)");

            $ancla = $this->resolverAncla($almacen);
            $this->line("  Frente ANCLA (se queda aquí): <fg=cyan>{$ancla->ID_FRENTE} {$ancla->NOMBRE_FRENTE}</>");

            $otros = $almacen->frentes->where('ID_FRENTE', '!=', $ancla->ID_FRENTE);

            // ── Plan de stock: qué es atribuible y a quién ──────────────────────────
            $plan = $this->planDeStock($almacen, $ancla);

            $this->newLine();
            $this->line('  <options=bold>Stock</>');
            foreach ($plan['movibles'] as $m) {
                $this->line("    mover  {$m['codigo']} ({$m['cantidad']}) → frente {$m['frente']->NOMBRE_FRENTE}");
            }
            foreach ($plan['sin_atribucion'] as $m) {
                $this->line("    <fg=yellow>queda en el ancla</>  {$m['codigo']} ({$m['cantidad']}) — {$m['razon']}");
            }
            if (empty($plan['movibles']) && empty($plan['sin_atribucion'])) {
                $this->line('    (sin saldos)');
            }

            // ── Plan de traspasos abiertos ──────────────────────────────────────────
            $traspasos = Traspaso::where('ID_ALMACEN_DESTINO', $almacen->ID_ALMACEN)
                ->whereIn('ESTADO', [Traspaso::ESTADO_BORRADOR, Traspaso::ESTADO_ENVIADO])
                ->whereNotNull('ID_FRENTE_DESTINO')
                ->where('ID_FRENTE_DESTINO', '!=', $ancla->ID_FRENTE)
                ->get();

            $this->newLine();
            $this->line('  <options=bold>Traspasos abiertos a reapuntar</>');
            foreach ($traspasos->groupBy('ID_FRENTE_DESTINO') as $idFrente => $g) {
                $nom = optional($otros->firstWhere('ID_FRENTE', (int) $idFrente))->NOMBRE_FRENTE ?? "frente {$idFrente}";
                $this->line("    {$g->count()} traspaso(s) → {$nom}");
            }
            if ($traspasos->isEmpty()) {
                $this->line('    (ninguno)');
            }

            $this->newLine();
            $this->line('  <options=bold>Almacenes a crear</>');
            foreach ($otros as $f) {
                $this->line("    + {$f->NOMBRE_FRENTE}  (PROYECTO, ligado sólo al frente {$f->ID_FRENTE})");
            }

            if (!$ejecutar) {
                continue;
            }

            $this->aplicar($almacen, $ancla, $otros, $plan, $traspasos, $inventario);
            $this->info("  ✔ Almacén #{$almacen->ID_ALMACEN} separado.");
        }

        $this->newLine();
        if (!$ejecutar) {
            $this->line('<fg=yellow>Nada se ha modificado. Repite con --ejecutar cuando el plan sea correcto.</>');
        }

        return self::SUCCESS;
    }

    /** El frente ancla es el que más movimientos tiene en el almacén: mover el resto cuesta menos. */
    private function resolverAncla(Almacen $almacen): FrenteTrabajo
    {
        if ($forzado = $this->option('ancla')) {
            $f = $almacen->frentes->firstWhere('ID_FRENTE', (int) $forzado);
            if (!$f) {
                // Excepción, no exit(): exit() mata el proceso sin desmontar el contenedor ni
                // cerrar la conexión, y dentro de una transacción dejaría el lock colgado.
                throw new \InvalidArgumentException("El frente {$forzado} no pertenece al almacén {$almacen->ID_ALMACEN}.");
            }
            return $f;
        }

        $conteos = MovimientoInventario::where('ID_ALMACEN', $almacen->ID_ALMACEN)
            ->whereNotNull('ID_FRENTE')
            ->selectRaw('ID_FRENTE, COUNT(*) n')
            ->groupBy('ID_FRENTE')
            ->pluck('n', 'ID_FRENTE');

        return $almacen->frentes
            ->sortByDesc(fn ($f) => $conteos[$f->ID_FRENTE] ?? 0)
            ->first();
    }

    /**
     * Un saldo se mueve sólo si su atribución es INEQUÍVOCA: todos los movimientos con
     * frente de ese producto en ese almacén apuntan al mismo frente, y ese frente es del
     * almacén. Cualquier duda → se queda en el ancla y se reporta.
     */
    private function planDeStock(Almacen $almacen, FrenteTrabajo $ancla): array
    {
        $idsFrentes = $almacen->frentes->pluck('ID_FRENTE')->map(fn ($v) => (int) $v)->all();
        $movibles = [];
        $sinAtribucion = [];

        $filas = AlmacenStock::where('ID_ALMACEN', $almacen->ID_ALMACEN)->where('CANTIDAD', '>', 0)->get();

        foreach ($filas as $fila) {
            $producto = ProductoInventario::withTrashed()->find($fila->ID_PRODUCTO);
            $codigo   = $producto->CODIGO ?? ('#' . $fila->ID_PRODUCTO);

            // El traslado se hace con registrarSalida/registrarEntrada, y esas exigen un
            // producto vivo y ACTIVO (InventarioService::cargarProducto usa find(), que
            // excluye los soft-deleted, y rechaza los inactivos). Sin este corte, un solo
            // producto en la papelera abortaba TODA la transacción del --ejecutar.
            if (!$producto || $producto->trashed()) {
                $sinAtribucion[] = ['codigo' => $codigo, 'cantidad' => $fila->CANTIDAD, 'razon' => 'producto en la papelera: restáuralo antes de moverlo'];
                continue;
            }
            if ($producto->ESTATUS !== 'ACTIVO') {
                $sinAtribucion[] = ['codigo' => $codigo, 'cantidad' => $fila->CANTIDAD, 'razon' => 'producto inactivo: actívalo antes de moverlo'];
                continue;
            }

            $frentes = MovimientoInventario::where('ID_ALMACEN', $almacen->ID_ALMACEN)
                ->where('ID_PRODUCTO', $fila->ID_PRODUCTO)
                ->whereNotNull('ID_FRENTE')
                ->distinct()
                ->pluck('ID_FRENTE')
                ->map(fn ($v) => (int) $v)
                ->all();

            if (count($frentes) === 0) {
                $sinAtribucion[] = ['codigo' => $codigo, 'cantidad' => $fila->CANTIDAD, 'razon' => 'ningún movimiento lleva frente'];
                continue;
            }
            if (count($frentes) > 1) {
                $sinAtribucion[] = ['codigo' => $codigo, 'cantidad' => $fila->CANTIDAD, 'razon' => 'movimientos de varios frentes: ' . implode(',', $frentes)];
                continue;
            }
            $idFrente = $frentes[0];
            if (!in_array($idFrente, $idsFrentes, true)) {
                $sinAtribucion[] = ['codigo' => $codigo, 'cantidad' => $fila->CANTIDAD, 'razon' => "su frente ({$idFrente}) no pertenece a este almacén"];
                continue;
            }
            if ($idFrente === (int) $ancla->ID_FRENTE) {
                continue; // ya está donde toca
            }

            $movibles[] = [
                'codigo'      => $codigo,
                'id_producto' => (int) $fila->ID_PRODUCTO,
                'cantidad'    => (float) $fila->CANTIDAD,
                'frente'      => $almacen->frentes->firstWhere('ID_FRENTE', $idFrente),
            ];
        }

        return ['movibles' => $movibles, 'sin_atribucion' => $sinAtribucion];
    }

    private function aplicar(Almacen $almacen, FrenteTrabajo $ancla, $otros, array $plan, $traspasos, InventarioService $inventario): void
    {
        DB::transaction(function () use ($almacen, $ancla, $otros, $plan, $traspasos, $inventario) {
            // 1. Un almacén nuevo por frente (salvo el ancla).
            $nuevos = [];
            foreach ($otros as $f) {
                $nuevo = Almacen::create([
                    'NOMBRE'  => $f->NOMBRE_FRENTE,
                    'TIPO'    => Almacen::TIPO_PROYECTO,
                    'ESTATUS' => 'ACTIVO',
                    'NOTAS'   => "Separado de «{$almacen->NOMBRE}» para llevar el inventario por proyecto.",
                ]);
                $nuevo->frentes()->attach($f->ID_FRENTE);
                $almacen->frentes()->detach($f->ID_FRENTE);
                $nuevos[(int) $f->ID_FRENTE] = $nuevo;
            }

            // 2. Traspasos aún abiertos → al almacén de su frente destino.
            foreach ($traspasos as $t) {
                $destino = $nuevos[(int) $t->ID_FRENTE_DESTINO] ?? null;
                if ($destino) {
                    $t->ID_ALMACEN_DESTINO = $destino->ID_ALMACEN;
                    $t->save();
                }
            }

            // 3. Stock atribuible → SALIDA en el origen + ENTRADA en el destino, con kardex.
            //    No es un UPDATE del saldo: así ambos almacenes pueden explicar su número.
            foreach ($plan['movibles'] as $m) {
                $destino = $nuevos[(int) $m['frente']->ID_FRENTE];
                $motivo  = "Separación de «{$almacen->NOMBRE}» por proyecto";

                $inventario->registrarSalida($almacen->ID_ALMACEN, $m['id_producto'], $m['cantidad'], [
                    'id_frente' => (int) $m['frente']->ID_FRENTE,
                    'motivo'    => $motivo,
                    'notas'     => "Traslado del saldo al almacén «{$destino->NOMBRE}».",
                ]);
                $inventario->registrarEntrada($destino->ID_ALMACEN, $m['id_producto'], $m['cantidad'], [
                    'id_frente' => (int) $m['frente']->ID_FRENTE,
                    'motivo'    => $motivo,
                    'notas'     => "Saldo trasladado desde «{$almacen->NOMBRE}».",
                ]);
            }
        });
    }
}
