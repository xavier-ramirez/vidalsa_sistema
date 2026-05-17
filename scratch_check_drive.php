<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$driveService = \App\Services\GoogleDriveService::getInstance();
$drive = $driveService->getDrive();

// Get all documents with property links
$docs = DB::table('documentacion')
    ->join('equipos', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
    ->select('documentacion.LINK_DOC_PROPIEDAD', 'equipos.SERIAL_CHASIS', 'equipos.ID_EQUIPO')
    ->whereNotNull('documentacion.LINK_DOC_PROPIEDAD')
    ->where('documentacion.LINK_DOC_PROPIEDAD', '!=', '')
    ->get();

$broken = [];

echo "Iniciando chequeo de " . count($docs) . " enlaces de documentos de propiedad...\n";

foreach ($docs as $doc) {
    $link = $doc->LINK_DOC_PROPIEDAD;
    // URL is like /storage/google/FILE_ID?v=timestamp
    $path = parse_url($link, PHP_URL_PATH); // returns /storage/google/FILE_ID
    $fileId = basename($path);

    try {
        $file = $drive->files->get($fileId, [
            'fields' => 'id',
            'supportsAllDrives' => true
        ]);
        // Si no tira error, existe.
    } catch (\Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '404') !== false || strpos($msg, 'File not found') !== false) {
            $broken[] = [
                'SERIAL_CHASIS' => $doc->SERIAL_CHASIS,
                'ID_EQUIPO' => $doc->ID_EQUIPO,
                'FILE_ID' => $fileId
            ];
            echo "ERROR: Archivo no encontrado para CHASIS: " . $doc->SERIAL_CHASIS . "\n";
        } else {
            echo "ERROR DESCONOCIDO (" . $msg . ") para CHASIS: " . $doc->SERIAL_CHASIS . "\n";
        }
    }
}

echo "Chequeo finalizado.\n";
echo "Total de enlaces rotos: " . count($broken) . "\n";

if (count($broken) > 0) {
    $csvPath = __DIR__ . '/scratch_broken_links.csv';
    $fp = fopen($csvPath, 'w');
    fputcsv($fp, ['SERIAL_CHASIS', 'ID_EQUIPO', 'FILE_ID']);
    foreach ($broken as $b) {
        fputcsv($fp, $b);
    }
    fclose($fp);
    echo "Reporte CSV guardado en: " . $csvPath . "\n";
}
