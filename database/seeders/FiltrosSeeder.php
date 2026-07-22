<?php

namespace Database\Seeders;

use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use Illuminate\Database\Seeder;

/**
 * Carga el catálogo de FILTROS al inventario, desde el consolidado depurado del
 * Excel (database/seeders/data/filtros_seed.json). Idempotente: usa
 * updateOrCreate por CODIGO, así que re-ejecutarlo NO duplica.
 *
 * Fase A → productos_inventario  (el filtro como producto, categoría FILTRO)
 * Fase B → producto_equivalencias (los nº de parte cross-reference del filtro)
 *
 * Las fases C (modelo_filtro) y D (kits) se cargan en seeders aparte.
 */
class FiltrosSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/filtros_seed.json');

        if (! is_file($path)) {
            $this->command->error("No se encontró {$path}");
            return;
        }

        $data = json_decode(file_get_contents($path), true);

        $nProd = 0;
        $nEquiv = 0;

        foreach ($data as $f) {
            // ── Fase A: el filtro como producto ──────────────────────
            $prod = ProductoInventario::updateOrCreate(
                ['CODIGO' => $f['codigo']],
                [
                    'NOMBRE'    => $f['nombre'],
                    'UM'        => $f['um'] ?? 'UND',
                    'CATEGORIA' => 'FILTROS',
                    'ES_KIT'    => (bool) ($f['es_kit'] ?? false),
                    'ESTATUS'   => 'ACTIVO',
                ]
            );
            $nProd++;

            // ── Fase B: sus equivalencias (el primero = principal) ────
            foreach (($f['equivalencias'] ?? []) as $i => $numeroParte) {
                ProductoEquivalencia::updateOrCreate(
                    ['ID_PRODUCTO' => $prod->ID_PRODUCTO, 'NUMERO_PARTE' => $numeroParte],
                    ['ES_PRINCIPAL' => $i === 0]
                );
                $nEquiv++;
            }
        }

        $this->command->info("FILTROS cargados: {$nProd} productos | {$nEquiv} equivalencias.");
    }
}
