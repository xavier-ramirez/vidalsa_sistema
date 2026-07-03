<?php

namespace App\Console\Commands;

use App\Models\CaracteristicaModelo;
use App\Models\ModeloFiltro;
use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga (bootstrap) del catálogo de filtros desde el Excel maestro consolidado
 * ("MAESTRO Filtros - Equivalencias y Equipos.xlsx"). Idempotente: se puede
 * re-correr; usa CODIGO / (ID_PRODUCTO+NUMERO_PARTE) / (ID_ESPEC+ID_PRODUCTO)
 * como llaves, así que no duplica. Lee 3 hojas: Filtros (Maestro), Equivalencias
 * y Compatibilidad (Modelo-Filtro).
 */
class ImportarFiltros extends Command
{
    protected $signature   = 'filtros:importar {archivo? : Ruta del Excel maestro}';
    protected $description = 'Importa filtros, equivalencias y compatibilidad modelo↔filtro desde el Excel maestro';

    private function norm(?string $s): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', (string) $s)));
    }

    public function handle(): int
    {
        $ruta = $this->argument('archivo')
            ?: 'C:/Users/dell12/Downloads/MAESTRO Filtros - Equivalencias y Equipos.xlsx';

        if (!is_file($ruta)) {
            $this->error("No encontré el archivo: {$ruta}");
            return self::FAILURE;
        }

        $this->info("Leyendo: {$ruta}");
        $book = IOFactory::load($ruta);

        $maestro = $book->getSheetByName('Filtros (Maestro)')?->toArray();
        $equivs  = $book->getSheetByName('Equivalencias')?->toArray();
        $compat  = $book->getSheetByName('Compatibilidad (Modelo-Filtro)')?->toArray();

        if (!$maestro || !$equivs || !$compat) {
            $this->error('Falta alguna hoja: "Filtros (Maestro)", "Equivalencias" o "Compatibilidad (Modelo-Filtro)".');
            return self::FAILURE;
        }

        // Mapa MODELO normalizado -> ID_ESPEC (para vincular la compatibilidad).
        $modeloMap = [];
        foreach (CaracteristicaModelo::all(['ID_ESPEC', 'MODELO']) as $m) {
            $modeloMap[$this->norm($m->MODELO)] = $m->ID_ESPEC;
        }

        $nProd = 0; $nEquiv = 0; $nCompat = 0;
        $modelosNoEncontrados = [];
        $codigoToId = [];

        DB::transaction(function () use (
            $maestro, $equivs, $compat, $modeloMap,
            &$nProd, &$nEquiv, &$nCompat, &$modelosNoEncontrados, &$codigoToId
        ) {
            // 1) Filtros -> productos_inventario (categoría FILTROS)
            foreach (array_slice($maestro, 1) as $row) {
                $cod = trim((string) ($row[0] ?? ''));
                if ($cod === '') continue;
                $nombre = trim((string) ($row[1] ?? '')) ?: 'FILTRO (sin descripción)';
                $p = ProductoInventario::updateOrCreate(
                    ['CODIGO' => $cod],
                    ['NOMBRE' => $nombre, 'CATEGORIA' => 'FILTROS', 'UM' => 'UND', 'ESTATUS' => 'ACTIVO']
                );
                $codigoToId[$cod] = $p->ID_PRODUCTO;
                $nProd++;
            }

            // 2) Equivalencias -> producto_equivalencias
            foreach (array_slice($equivs, 1) as $row) {
                $cod = trim((string) ($row[0] ?? ''));
                $np  = trim((string) ($row[2] ?? ''));
                if ($cod === '' || $np === '' || !isset($codigoToId[$cod])) continue;
                $principal = $this->norm($row[3] ?? '') === 'SÍ' || $this->norm($row[3] ?? '') === 'SI';
                ProductoEquivalencia::firstOrCreate(
                    ['ID_PRODUCTO' => $codigoToId[$cod], 'NUMERO_PARTE' => $np],
                    ['ES_PRINCIPAL' => $principal]
                );
                $nEquiv++;
            }

            // 3) Compatibilidad -> modelo_filtro (solo lo que tenga modelo y código válidos)
            foreach (array_slice($compat, 1) as $row) {
                $modelo = trim((string) ($row[2] ?? ''));
                $cod    = trim((string) ($row[4] ?? ''));
                $cant   = (int) ($row[7] ?? 1) ?: 1;
                if ($cod === '' || $cod === '—' || !isset($codigoToId[$cod])) continue;

                $idEspec = $modeloMap[$this->norm($modelo)] ?? null;
                if (!$idEspec) {
                    $modelosNoEncontrados[$modelo] = true;
                    continue;
                }
                ModeloFiltro::updateOrCreate(
                    ['ID_ESPEC' => $idEspec, 'ID_PRODUCTO' => $codigoToId[$cod]],
                    ['CANTIDAD' => $cant]
                );
                $nCompat++;
            }
        });

        $this->newLine();
        $this->info("Filtros (productos):      {$nProd}");
        $this->info("Equivalencias:            {$nEquiv}");
        $this->info("Vínculos modelo↔filtro:   {$nCompat}");
        if ($modelosNoEncontrados) {
            $this->warn('Modelos del Excel que NO existen en caracteristicas_modelo (no se vincularon):');
            $this->line('  ' . implode(' · ', array_keys($modelosNoEncontrados)));
            $this->line('  → Créalos en el catálogo de modelos y vuelve a correr este comando.');
        }

        return self::SUCCESS;
    }
}
