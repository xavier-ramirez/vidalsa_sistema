<?php

namespace Database\Seeders;

use App\Models\CaracteristicaModelo;
use App\Models\ModeloFiltro;
use App\Models\ProductoInventario;
use Illuminate\Database\Seeder;

/**
 * Fase C: compatibilidad filtro ↔ MODELO de equipo (modelo_filtro), a partir de
 * los formatos de solicitud de flota. Solo enlaza los modelos cuyo código CASA
 * EXACTO (normalizado) con uno ya registrado en caracteristicas_modelo. Los que
 * no casan se listan al final para decidir (crear el modelo o mapear variante).
 * Idempotente.
 */
class FiltrosModeloSeeder extends Seeder
{
    private function norm(?string $s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $s));
    }

    public function run(): void
    {
        $path = database_path('seeders/data/filtros_seed.json');
        if (! is_file($path)) {
            $this->command->error("No se encontró {$path}");
            return;
        }
        $data = json_decode(file_get_contents($path), true);

        $idPorCodigo = ProductoInventario::whereIn('CODIGO', array_column($data, 'codigo'))
            ->pluck('ID_PRODUCTO', 'CODIGO');

        // modelo normalizado → ID_ESPEC
        $modelos = [];
        foreach (CaracteristicaModelo::all(['ID_ESPEC', 'MODELO']) as $m) {
            $modelos[$this->norm($m->MODELO)] = $m->ID_ESPEC;
        }

        $nLinks = 0;
        $sinMatch = [];

        foreach ($data as $f) {
            $idProd = $idPorCodigo[$f['codigo']] ?? null;
            if (! $idProd) continue;

            $yaVistos = [];
            foreach (($f['equipos'] ?? []) as $e) {
                $key = $this->norm($e['modelo'] ?? '');
                if ($key === '') continue;

                $idEspec = $modelos[$key] ?? null;
                if (! $idEspec) {
                    $etq = trim(($e['tipo'] ?? '').' | '.($e['marca'] ?? '').' | '.($e['modelo'] ?? ''), ' |');
                    $sinMatch[$etq] = true;
                    continue;
                }
                if (isset($yaVistos[$idEspec])) continue;
                $yaVistos[$idEspec] = true;

                ModeloFiltro::updateOrCreate(
                    ['ID_ESPEC' => $idEspec, 'ID_PRODUCTO' => $idProd],
                    ['CANTIDAD' => 1]
                );
                $nLinks++;
            }
        }

        $this->command->info("Vínculos filtro↔modelo creados: {$nLinks}.");
        $this->command->warn('Modelos SIN match (no vinculados): '.count($sinMatch));
        foreach (array_keys($sinMatch) as $m) {
            $this->command->line("   · {$m}");
        }
    }
}
