<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$pdf_path = 'C:\Users\dell12\Downloads\Bol de 11 torres de luz .pdf';

if (!file_exists($pdf_path)) {
    echo "Error: File does not exist at $pdf_path\n";
    exit;
}

$luminarias = DB::table('equipos_auxiliares')
    ->where('MODELO', 'LIKE', '%X CUBE%')
    ->get(['ID_AUXILIAR']);

$count = 0;
foreach($luminarias as $l) {
    $id = $l->ID_AUXILIAR;
    $time = time();
    $name = "propiedad_{$time}.pdf";
    $dir = storage_path("app/public/equipos_auxiliares/{$id}");
    
    if (!File::exists($dir)) {
        File::makeDirectory($dir, 0755, true);
    }
    
    $dest = "{$dir}/{$name}";
    File::copy($pdf_path, $dest);
    
    $db_path = "/storage/equipos_auxiliares/{$id}/{$name}";
    DB::table('equipos_auxiliares')
        ->where('ID_AUXILIAR', $id)
        ->update(['LINK_DOC_PROPIEDAD' => $db_path]);
    
    echo "Updated ID $id: $db_path\n";
    $count++;
}

echo "Total updated: $count\n";
