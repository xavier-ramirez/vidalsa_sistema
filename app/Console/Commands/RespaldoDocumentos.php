<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\GoogleDriveService;
use App\Models\Documentacion;
use App\Models\EquipoAuxiliar;

/**
 * Respaldo LOCAL de todos los PDF de documentos guardados en Google Drive.
 *
 * Baja cada documento (propiedad, póliza, ROTC, RACDA, certificado, compraventa) de
 * VEHÍCULOS y AUXILIARES a una carpeta local, organizada por equipo, y genera un
 * índice CSV (se abre en Excel) que relaciona SERIAL + PLACA + TIPO → RUTA del archivo.
 * Así el usuario busca el equipo en el Excel y encuentra dónde está su PDF.
 *
 * Uso:
 *   php artisan docs:respaldo
 *   php artisan docs:respaldo --destino="D:/respaldo_vidalsa"
 *   php artisan docs:respaldo --solo-indice        (solo el CSV, sin bajar los PDF)
 *   php artisan docs:respaldo --limite=20           (para probar con pocos)
 */
class RespaldoDocumentos extends Command
{
    protected $signature = 'docs:respaldo
                            {--destino= : Carpeta destino (por defecto storage/app/respaldo_documentos_FECHA)}
                            {--solo-indice : Solo genera el índice CSV, sin descargar los PDF}
                            {--limite=0 : Máx. de equipos a procesar (0 = todos; útil para probar)}';

    protected $description = 'Respalda localmente todos los PDF de Google Drive + índice CSV (serial/placa → archivo).';

    /** Documentos de VEHÍCULO: etiqueta => columna del LINK en la tabla documentacion. */
    private const DOCS_VEHICULO = [
        'PROPIEDAD'   => 'LINK_DOC_PROPIEDAD',
        'POLIZA'      => 'LINK_POLIZA_SEGURO',
        'ROTC'        => 'LINK_ROTC',
        'RACDA'       => 'LINK_RACDA',
        'CERTIFICADO' => 'LINK_DOC_ADICIONAL',
        'COMPRAVENTA' => 'LINK_DOC_ADICIONAL_2',
    ];

    /** Documentos de AUXILIAR: etiqueta => columna del LINK en equipos_auxiliares. */
    private const DOCS_AUXILIAR = [
        'PROPIEDAD'   => 'LINK_DOC_PROPIEDAD',
        'CERTIFICADO' => 'LINK_CERTIFICADO',
    ];

    public function handle()
    {
        $soloIndice = (bool) $this->option('solo-indice');
        $limite     = (int) $this->option('limite');

        $destino = $this->option('destino')
            ?: storage_path('app/respaldo_documentos_' . date('Y-m-d_His'));
        $destino = rtrim(str_replace('\\', '/', $destino), '/');

        if (!is_dir($destino) && !@mkdir($destino, 0777, true)) {
            $this->error("❌ No se pudo crear la carpeta destino: {$destino}");
            return 1;
        }

        // Conexión a Drive (solo si vamos a descargar).
        $drive = null;
        if (!$soloIndice) {
            try {
                $drive = GoogleDriveService::getInstance();
            } catch (\Throwable $e) {
                $this->error("❌ No se pudo conectar a Google Drive: " . $e->getMessage());
                $this->warn("   Puedes generar solo el índice con:  php artisan docs:respaldo --solo-indice");
                return 1;
            }
        }

        $this->info("📦 Respaldo de documentos → {$destino}");
        $this->line($soloIndice ? "   Modo: solo índice (no se descargan PDF)." : "   Descargando PDF desde Google Drive…");
        $this->newLine();

        // Filas del índice CSV.
        $filas = [];
        $filas[] = ['CLASE', 'SERIAL', 'PLACA / CODIGO', 'MARCA', 'MODELO', 'DOCUMENTO', 'ARCHIVO', 'DRIVE_ID', 'ESTADO'];

        $stats = ['ok' => 0, 'error' => 0, 'sin_archivo' => 0, 'equipos' => 0];

        // ── 1) VEHÍCULOS ──────────────────────────────────────────────────────
        $docsQuery = Documentacion::with(['equipo' => function ($q) { $q->withTrashed(); }]);
        if ($limite > 0) $docsQuery->limit($limite);
        $docs = $docsQuery->get();

        $bar = $this->output->createProgressBar($docs->count());
        $bar->start();
        foreach ($docs as $doc) {
            $eq     = $doc->equipo; // puede ser null si el documento quedó sin equipo asociado
            $serial = $eq?->SERIAL_CHASIS ?? '';
            $placa  = $doc->PLACA ?: ($eq?->CODIGO_PATIO ?? '');
            $marca  = $eq?->MARCA ?? '';
            $modelo = $eq?->MODELO ?? '';
            $carpeta = $this->nombreCarpeta('VEH', $placa, $serial, $doc->ID_EQUIPO);

            foreach (self::DOCS_VEHICULO as $etq => $col) {
                $res = $this->procesarDoc($drive, $destino, $carpeta, $etq, $doc->{$col}, $soloIndice, $stats);
                $filas[] = ['VEHICULO', $serial, $placa, $marca, $modelo, $etq, $res['archivo'], $res['drive_id'], $res['estado']];
            }
            $stats['equipos']++;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        // ── 2) AUXILIARES ─────────────────────────────────────────────────────
        $auxQuery = EquipoAuxiliar::withTrashed();
        if ($limite > 0) $auxQuery->limit($limite);
        $auxes = $auxQuery->get();

        if ($auxes->count()) {
            $this->line("   Auxiliares…");
            $bar = $this->output->createProgressBar($auxes->count());
            $bar->start();
            foreach ($auxes as $ax) {
                $serial = $ax->SERIAL ?? '';
                $codigo = $ax->CODIGO_INTERNO ?? '';
                $carpeta = $this->nombreCarpeta('AUX', $codigo, $serial, $ax->getKey());

                foreach (self::DOCS_AUXILIAR as $etq => $col) {
                    $res = $this->procesarDoc($drive, $destino, $carpeta, $etq, $ax->{$col}, $soloIndice, $stats);
                    $filas[] = ['AUXILIAR', $serial, $codigo, $ax->MARCA ?? '', $ax->MODELO ?? '', $etq, $res['archivo'], $res['drive_id'], $res['estado']];
                }
                $stats['equipos']++;
                $bar->advance();
            }
            $bar->finish();
            $this->newLine(2);
        }

        // ── 3) Índice CSV (con BOM para que Excel muestre bien los acentos) ────
        $csvPath = $destino . '/INDICE.csv';
        $fh = fopen($csvPath, 'w');
        fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8
        foreach ($filas as $fila) {
            fputcsv($fh, $fila, ';'); // ';' = separador que Excel-ES abre en columnas directo
        }
        fclose($fh);

        // ── Resumen ───────────────────────────────────────────────────────────
        $this->info("✅ Respaldo terminado.");
        $this->line("   Equipos procesados : {$stats['equipos']}");
        if (!$soloIndice) {
            $this->line("   PDF descargados    : {$stats['ok']}");
            $this->line("   Con error          : {$stats['error']}");
        }
        $this->line("   Sin documento      : {$stats['sin_archivo']}");
        $this->newLine();
        $this->info("📄 Índice (Excel/CSV): {$csvPath}");
        $this->info("📁 Carpeta con los PDF: {$destino}");

        return 0;
    }

    /**
     * Descarga (o solo indexa) un documento. Devuelve ['archivo','drive_id','estado'].
     * ESTADO: OK | SIN ARCHIVO | SOLO INDICE | ERROR: <motivo>.
     */
    private function procesarDoc($drive, string $destino, string $carpeta, string $etiqueta, ?string $url, bool $soloIndice, array &$stats): array
    {
        $driveId = $this->extraerDriveId($url);
        if (!$driveId) {
            $stats['sin_archivo']++;
            return ['archivo' => '', 'drive_id' => '', 'estado' => 'SIN ARCHIVO'];
        }

        $rutaRel = $carpeta . '/' . $etiqueta . '.pdf';
        if ($soloIndice) {
            return ['archivo' => $rutaRel, 'drive_id' => $driveId, 'estado' => 'SOLO INDICE'];
        }

        $dir = $destino . '/' . $carpeta;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $rutaAbs = $dir . '/' . $etiqueta . '.pdf';

        try {
            $stream = $drive->getStreamById($driveId);
            file_put_contents($rutaAbs, $stream->getContents());
            usleep(80000); // 80ms para no saturar la API de Drive
            $stats['ok']++;
            return ['archivo' => $rutaRel, 'drive_id' => $driveId, 'estado' => 'OK'];
        } catch (\Throwable $e) {
            $stats['error']++;
            return ['archivo' => '', 'drive_id' => $driveId, 'estado' => 'ERROR: ' . $this->corto($e->getMessage())];
        }
    }

    /** Extrae el ID de Drive de una URL "/storage/google/<id>?v=...". */
    private function extraerDriveId(?string $url): ?string
    {
        if (!$url || !str_starts_with($url, '/storage/google/')) return null;
        $id = str_replace('/storage/google/', '', (string) parse_url($url, PHP_URL_PATH));
        return $id !== '' ? $id : null;
    }

    /** Nombre de carpeta legible y seguro para el equipo: prefiere placa/código, luego serial. */
    private function nombreCarpeta(string $prefijo, ?string $placa, ?string $serial, $id): string
    {
        $base = trim((string) ($placa ?: $serial));
        if ($base === '') $base = $prefijo . '_' . $id;
        // Quitar caracteres inválidos en nombres de archivo (Windows/Unix).
        $base = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $base);
        $base = preg_replace('/\s+/', '_', trim($base));
        return $prefijo . '_' . mb_substr($base, 0, 60);
    }

    private function corto(string $s): string
    {
        $s = preg_replace('/\s+/', ' ', $s);
        return mb_strlen($s) > 80 ? mb_substr($s, 0, 80) . '…' : $s;
    }
}
