<?php

namespace Database\Seeders;

use App\Models\ProductoInventario;
use App\Models\ProductoKitComponente;
use Illuminate\Database\Seeder;

/**
 * Fase D: arma la lista de materiales (BOM) de los KITS de filtro. Agrupa por
 * CONJUNTO (ej. AIRE-346): el producto con ES_KIT=true es el kit, y las piezas
 * con ROL PRIMARIO/SECUNDARIO son sus componentes. Idempotente.
 *
 * Requiere que FiltrosSeeder (fases A/B) ya haya corrido. Lee el mismo JSON.
 */
class FiltrosKitsSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/filtros_seed.json');
        if (! is_file($path)) {
            $this->command->error("No se encontró {$path}");
            return;
        }

        $data = json_decode(file_get_contents($path), true);

        // Agrupar por CONJUNTO (solo los que lo tienen).
        $grupos = [];
        foreach ($data as $f) {
            if (! empty($f['conjunto'])) {
                $grupos[$f['conjunto']][] = $f;
            }
        }

        // codigo → ID_PRODUCTO (una sola consulta).
        $codigos = array_column($data, 'codigo');
        $idPorCodigo = ProductoInventario::whereIn('CODIGO', $codigos)
            ->pluck('ID_PRODUCTO', 'CODIGO');

        $nKits = 0;
        $nComp = 0;

        foreach ($grupos as $conjunto => $items) {
            $kit = collect($items)->first(fn ($x) => stripos((string) ($x['rol'] ?? ''), 'KIT') !== false);
            $componentes = collect($items)->filter(
                fn ($x) => in_array(strtoupper((string) ($x['rol'] ?? '')), ['PRIMARIO', 'SECUNDARIO'], true)
            )->values();

            if (! $kit || $componentes->isEmpty()) {
                $this->command->warn("Conjunto {$conjunto}: sin kit o sin componentes, se omite.");
                continue;
            }

            $idKit = $idPorCodigo[$kit['codigo']] ?? null;
            if (! $idKit) continue;

            $nKits++;
            foreach ($componentes as $orden => $comp) {
                $idComp = $idPorCodigo[$comp['codigo']] ?? null;
                if (! $idComp || $idComp === $idKit) continue;

                ProductoKitComponente::updateOrCreate(
                    ['ID_PRODUCTO_KIT' => $idKit, 'ID_PRODUCTO_COMPONENTE' => $idComp],
                    ['CANTIDAD' => 1, 'ROL' => strtoupper($comp['rol']), 'ORDEN' => $orden]
                );
                $nComp++;
            }
        }

        $this->command->info("KITS armados: {$nKits} | componentes vinculados: {$nComp}.");
    }
}
