<?php

namespace App\Console\Commands;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Services\InventarioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparte el saldo YA EXISTENTE de un almacén multi-proyecto entre sus proyectos.
 *
 * Contexto: al añadir ID_FRENTE a `almacen_stock`, todas las filas quedaron con frente 0
 * (la bolsa común del almacén). Este comando las atribuye a su proyecto usando lo único
 * que lo sabe: el kardex, que sí guarda ID_FRENTE desde siempre.
 *
 * Un saldo se atribuye solo si es INEQUÍVOCO: todos los movimientos de ese producto en
 * ese almacén que llevan frente apuntan al MISMO frente, y ese frente es del almacén.
 * Cualquier duda —ningún movimiento con frente (los AJUSTE nunca lo llevan), o repartido
 * entre varios— se queda en la bolsa común, que es exactamente para lo que existe: material
 * del almacén que todavía no es de nadie y que cualquier proyecto puede consumir.
 *
 * NO toca el kardex ni genera movimientos: no hay mercancía moviéndose, solo se está
 * diciendo de quién es el saldo que ya estaba ahí. Por eso reasigna la fila directamente
 * (a diferencia de almacen:separar-frentes, que sí movía material entre almacenes
 * distintos y debía dejar rastro).
 *
 * Simulación por defecto; --ejecutar aplica.
 */
class AtribuirStockAProyectos extends Command
{
    protected $signature = 'almacen:atribuir-stock
                            {--id-almacen= : Solo este almacén (por defecto, todos los multi-proyecto)}
                            {--ejecutar : Aplica los cambios. Sin este flag es una simulación}';

    protected $description = 'Reparte el saldo existente de un almacén multi-proyecto entre sus proyectos, según el kardex';

    public function handle(): int
    {
        $ejecutar = (bool) $this->option('ejecutar');

        $this->newLine();
        $this->line($ejecutar
            ? '<fg=green>APLICANDO CAMBIOS</>'
            : '<fg=yellow>SIMULACIÓN. Añade --ejecutar para aplicar.</>');

        $almacenes = Almacen::with('frentes')
            ->when($this->option('id-almacen'), fn ($q) => $q->where('ID_ALMACEN', (int) $this->option('id-almacen')))
            ->get()
            ->filter(fn ($a) => $a->separaPorProyecto());

        if ($almacenes->isEmpty()) {
            $this->info('No hay almacenes que separen por proyecto. Nada que hacer.');
            return self::SUCCESS;
        }

        foreach ($almacenes as $almacen) {
            $this->procesar($almacen, $ejecutar);
        }

        if (!$ejecutar) {
            $this->newLine();
            $this->line('<fg=yellow>Nada se ha modificado. Repite con --ejecutar cuando el plan sea correcto.</>');
        }

        return self::SUCCESS;
    }

    private function procesar(Almacen $almacen, bool $ejecutar): void
    {
        $this->newLine();
        $this->line("<options=bold>Almacén #{$almacen->ID_ALMACEN} — {$almacen->NOMBRE}</> ({$almacen->frentes->count()} proyectos)");

        $idsFrentes = $almacen->frentes->pluck('ID_FRENTE')->map(fn ($v) => (int) $v)->all();
        $nombres    = $almacen->frentes->pluck('NOMBRE_FRENTE', 'ID_FRENTE');

        // Solo se reparte lo que está en la bolsa común: si una fila ya tiene proyecto es
        // porque un movimiento posterior la creó ahí, y esa atribución manda sobre esta.
        $filas = AlmacenStock::where('ID_ALMACEN', $almacen->ID_ALMACEN)
            ->where('ID_FRENTE', InventarioService::FRENTE_BOLSA_COMUN)
            ->where('CANTIDAD', '<>', 0)
            ->get();

        $plan = [];
        $quedan = [];

        foreach ($filas as $fila) {
            $producto = ProductoInventario::withTrashed()->find($fila->ID_PRODUCTO);
            $codigo   = $producto->CODIGO ?? ('#' . $fila->ID_PRODUCTO);

            $frentes = MovimientoInventario::where('ID_ALMACEN', $almacen->ID_ALMACEN)
                ->where('ID_PRODUCTO', $fila->ID_PRODUCTO)
                ->whereNotNull('ID_FRENTE')
                ->distinct()
                ->pluck('ID_FRENTE')
                ->map(fn ($v) => (int) $v)
                ->all();

            $delAlmacen = array_values(array_intersect($frentes, $idsFrentes));

            if (count($frentes) === 0) {
                $quedan[] = [$codigo, $fila->CANTIDAD, 'ningún movimiento lleva proyecto'];
                continue;
            }
            if (count($delAlmacen) === 0) {
                $quedan[] = [$codigo, $fila->CANTIDAD, 'sus movimientos apuntan a proyectos de otro almacén'];
                continue;
            }
            if (count($delAlmacen) > 1) {
                $quedan[] = [$codigo, $fila->CANTIDAD, 'repartido entre ' . count($delAlmacen) . ' proyectos: hay que decidirlo a mano'];
                continue;
            }

            $plan[] = ['fila' => $fila, 'codigo' => $codigo, 'frente' => $delAlmacen[0]];
        }

        if ($plan) {
            $this->line('  <options=bold>Se atribuyen</>');
            foreach ($plan as $p) {
                $this->line(sprintf('    %s (%s) → %s', $p['codigo'],
                    rtrim(rtrim(number_format((float) $p['fila']->CANTIDAD, 3, ',', '.'), '0'), ','),
                    $nombres[$p['frente']] ?? ('frente ' . $p['frente'])));
            }
        }
        if ($quedan) {
            $this->line('  <options=bold>Se quedan sin asignar</> (bolsa común, disponible para cualquier proyecto)');
            foreach ($quedan as [$cod, $cant, $razon]) {
                $this->line(sprintf('    <fg=yellow>%s</> (%s) — %s', $cod,
                    rtrim(rtrim(number_format((float) $cant, 3, ',', '.'), '0'), ','), $razon));
            }
        }
        if (!$plan && !$quedan) {
            $this->line('  <fg=gray>Sin saldo en la bolsa común: no hay nada que repartir.</>');
            return;
        }

        if (!$ejecutar) {
            return;
        }

        // El total del almacén NO puede cambiar: esto reasigna, no mueve mercancía.
        $antes = (float) AlmacenStock::where('ID_ALMACEN', $almacen->ID_ALMACEN)->sum('CANTIDAD');

        DB::transaction(function () use ($plan) {
            foreach ($plan as $p) {
                $fila = $p['fila'];

                // Si el producto YA tiene fila en ese proyecto (por un movimiento
                // posterior), se suma allí y se vacía la de la bolsa común: dos filas con
                // el mismo (almacén, producto, frente) violarían el índice único.
                $destino = AlmacenStock::where('ID_ALMACEN', $fila->ID_ALMACEN)
                    ->where('ID_PRODUCTO', $fila->ID_PRODUCTO)
                    ->where('ID_FRENTE', $p['frente'])
                    ->lockForUpdate()
                    ->first();

                if ($destino) {
                    $destino->CANTIDAD = (float) $destino->CANTIDAD + (float) $fila->CANTIDAD;
                    $destino->save();
                    $fila->CANTIDAD = 0;
                    $fila->save();
                } else {
                    $fila->ID_FRENTE = $p['frente'];
                    $fila->save();
                }
            }
        });

        $despues = (float) AlmacenStock::where('ID_ALMACEN', $almacen->ID_ALMACEN)->sum('CANTIDAD');

        if (abs($antes - $despues) > 0.0005) {
            $this->error(sprintf('  ATENCIÓN: el total del almacén cambió (%.3f → %.3f). Revisa antes de seguir.', $antes, $despues));
            return;
        }
        $this->info(sprintf('  Hecho. Total del almacén intacto: %s unidades.',
            rtrim(rtrim(number_format($despues, 3, ',', '.'), '0'), ',')));
    }
}
