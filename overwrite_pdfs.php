<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$pdf_path = 'C:\Users\dell12\Downloads\Bol de 11 torres de luz  (1).pdf';

if (!file_exists($pdf_path)) {
    echo "Error: File does not exist at $pdf_path\n";
    exit;
}

$luminarias = DB::table('equipos_auxiliares')
    ->whereIn('ID_AUXILIAR', [40, 41, 124, 127, 129, 130, 131, 132, 133, 134, 135, 136, 137])
    ->get(['ID_AUXILIAR', 'LINK_DOC_PROPIEDAD']);

$count = 0;
foreach($luminarias as $l) {
    $id = $l->ID_AUXILIAR;
    
    if ($l->LINK_DOC_PROPIEDAD) {
        // e.g. /storage/equipos_auxiliares/40/propiedad_1785855684.pdf
        $relative_path = str_replace('/storage/', '', $l->LINK_DOC_PROPIEDAD);
        $full_path = storage_path("app/public/{$relative_path}");
        
        File::copy($pdf_path, $full_path);
        echo "Overwritten: $full_path\n";
        $count++;
    }
}

echo "Total overwritten: $count\n";
