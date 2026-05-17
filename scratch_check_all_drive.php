<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$driveService = \App\Services\GoogleDriveService::getInstance();
$drive = $driveService->getDrive();

$columnsToCheck = [
    'LINK_DOC_PROPIEDAD',
    'LINK_POLIZA_SEGURO',
    'LINK_ROTC',
    'LINK_RACDA',
    'LINK_DOC_ADICIONAL',
    'LINK_DOC_ADICIONAL_2'
];

$broken = [];

foreach ($columnsToCheck as $col) {
    $docs = DB::table('documentacion')
        ->join('equipos', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
        ->select("documentacion.{$col} as link", 'equipos.SERIAL_CHASIS', 'equipos.ID_EQUIPO')
        ->whereNotNull("documentacion.{$col}")
        ->where("documentacion.{$col}", '!=', '')
        ->get();

    echo "Chequeando {$col} (" . count($docs) . " enlaces)...\n";

    foreach ($docs as $doc) {
        $link = $doc->link;
        if (!$link) continue;
        
        $path = parse_url($link, PHP_URL_PATH);
        $fileId = basename($path);

        try {
            $drive->files->get($fileId, ['fields' => 'id', 'supportsAllDrives' => true]);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (strpos($msg, '404') !== false || strpos($msg, 'File not found') !== false) {
                $broken[] = [
                    'SERIAL_CHASIS' => $doc->SERIAL_CHASIS,
                    'TIPO_DOC' => $col,
                    'FILE_ID' => $fileId
                ];
            }
        }
    }
}

echo "Total de enlaces rotos encontrados: " . count($broken) . "\n";
if (count($broken) > 0) {
    foreach ($broken as $b) {
        echo "ROTO -> CHASIS: {$b['SERIAL_CHASIS']} | DOC: {$b['TIPO_DOC']}\n";
    }
}
