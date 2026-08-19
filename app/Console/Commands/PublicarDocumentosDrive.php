<?php

namespace App\Console\Commands;

use App\Services\GoogleDriveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da permiso de LECTURA PÚBLICA a los documentos que ya están en Drive.
 *
 *   php artisan documentos:publicar --dry-run   (solo cuenta, no toca nada)
 *   php artisan documentos:publicar
 *
 * PARA QUÉ. El visor abre los PDF directamente contra Drive, sin que el archivo pase por
 * este servidor. Antes iba: Drive -> servidor -> disco -> navegador, y el navegador no veía
 * ni un byte hasta que el archivo entero estaba aquí. Yendo directo, Google se lo sirve al
 * navegador desde su propia red, que es por lo que en drive.google.com se abren de una.
 *
 * QUÉ IMPLICA, DICHO CLARO. Un archivo "público" en Drive es legible por CUALQUIERA que
 * tenga el enlace, sin iniciar sesión. Los enlaces llevan el id de Drive, que no se adivina,
 * pero quien lo reenvíe está compartiendo el documento con quien sea, para siempre. Esto se
 * decidió a propósito para ganar la velocidad; no es un efecto secundario.
 *
 * Es IDEMPOTENTE: volver a correrlo sobre un archivo ya público no rompe nada (Drive
 * responde 400 y makePublic se lo traga). Se puede cortar y retomar.
 */
class PublicarDocumentosDrive extends Command
{
    protected $signature = 'documentos:publicar {--dry-run : Solo cuenta los archivos, sin tocar Drive}';

    protected $description = 'Hace públicos en Drive los documentos ya subidos, para que el visor los abra sin pasar por el servidor';

    /** Columnas con enlaces a documentos, por tabla. */
    private const COLUMNAS = [
        'documentacion' => [
            'LINK_DOC_PROPIEDAD', 'LINK_POLIZA_SEGURO', 'LINK_ROTC',
            'LINK_RACDA', 'LINK_DOC_ADICIONAL', 'LINK_DOC_ADICIONAL_2',
        ],
        'equipos_auxiliares' => ['LINK_DOC_PROPIEDAD', 'LINK_CERTIFICADO'],
    ];

    public function handle(): int
    {
        $ids = $this->recolectarIds();
        $total = count($ids);

        if ($total === 0) {
            $this->info('No hay documentos en Drive que publicar.');
            return self::SUCCESS;
        }

        $this->info("Documentos en Drive encontrados: {$total} (ids únicos).");

        if ($this->option('dry-run')) {
            $this->comment('--dry-run: no se tocó nada en Drive.');
            return self::SUCCESS;
        }

        // getInstance() abre conexión con Drive; si no hay credenciales o no hay internet
        // esto revienta aquí y no a mitad del recorrido.
        try {
            $drive = GoogleDriveService::getInstance();
        } catch (\Throwable $e) {
            $this->error('No se pudo conectar con Google Drive: ' . $e->getMessage());
            return self::FAILURE;
        }

        $ok = 0;
        $fallos = [];
        $barra = $this->output->createProgressBar($total);
        $barra->start();

        foreach ($ids as $id) {
            try {
                $drive->makePublic($id) ? $ok++ : $fallos[] = $id;
            } catch (\Throwable $e) {
                $fallos[] = $id;
            }
            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);
        $this->info("Publicados: {$ok} de {$total}.");

        if ($fallos) {
            $this->warn('No se pudo con ' . count($fallos) . ':');
            foreach (array_slice($fallos, 0, 20) as $f) $this->line('  ' . $f);
            if (count($fallos) > 20) $this->line('  … y ' . (count($fallos) - 20) . ' más.');
            $this->warn('Esos documentos seguirán sin abrirse en el visor. Vuelve a correr el comando: es idempotente.');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Ids de Drive de TODOS los documentos guardados. Se ignoran los enlaces que no apuntan
     * a Drive: hay auxiliares con el documento en el disco de este servidor
     * (/storage/equipos_auxiliares/...), que ya se sirven solos y no tienen nada que publicar.
     */
    private function recolectarIds(): array
    {
        $ids = [];

        foreach (self::COLUMNAS as $tabla => $columnas) {
            foreach ($columnas as $col) {
                $enlaces = DB::table($tabla)
                    ->whereNotNull($col)
                    ->where($col, 'like', '/storage/google/%')
                    ->pluck($col);

                foreach ($enlaces as $enlace) {
                    // /storage/google/<id>?v=<timestamp>  ->  <id>
                    $id = basename(parse_url($enlace, PHP_URL_PATH) ?: '');
                    if ($id !== '') $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
