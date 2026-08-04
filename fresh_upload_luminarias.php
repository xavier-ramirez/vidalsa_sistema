<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

$pdf_path = 'C:\Users\dell12\Downloads\Bol de 11 torres de luz  (1).pdf';

if (!file_exists($pdf_path)) {
    echo "Error: El archivo PDF no existe en $pdf_path\n";
    exit;
}

// Los IDs de las 13 luminarias (incluyendo el del error de tipeo)
$ids = [40, 41, 124, 127, 129, 130, 131, 132, 133, 134, 135, 136, 137];

$luminarias = DB::table('equipos_auxiliares')
    ->whereIn('ID_AUXILIAR', $ids)
    ->get(['ID_AUXILIAR', 'LINK_DOC_PROPIEDAD']);

$sql = "";
$time = time(); // Usar un nuevo timestamp fresco para todos
$count = 0;

foreach($luminarias as $l) {
    $id = $l->ID_AUXILIAR;
    
    // Primero, vamos a borrar los PDFs viejos de esta luminaria si los hay, para limpiar el "caché/basura"
    $dir = storage_path("app/public/equipos_auxiliares/{$id}");
    if (File::exists($dir)) {
        // Borrar archivos previos que empiecen con propiedad_
        $files = File::files($dir);
        foreach($files as $file) {
            if (strpos($file->getFilename(), 'propiedad_') === 0) {
                File::delete($file->getPathname());
            }
        }
    } else {
        File::makeDirectory($dir, 0755, true);
    }
    
    // Ahora montamos el nuevo PDF con un nuevo nombre fresco (timestamp nuevo)
    $name = "propiedad_{$time}.pdf";
    $dest = "{$dir}/{$name}";
    
    File::copy($pdf_path, $dest);
    
    // La nueva ruta para la BD
    $db_path = "/storage/equipos_auxiliares/{$id}/{$name}";
    
    // Actualizamos BD local
    DB::table('equipos_auxiliares')
        ->where('ID_AUXILIAR', $id)
        ->update(['LINK_DOC_PROPIEDAD' => $db_path]);
        
    // Generamos el SQL para el servidor
    $sql .= "UPDATE equipos_auxiliares SET LINK_DOC_PROPIEDAD = '{$db_path}' WHERE ID_AUXILIAR = {$id};\n";
    
    echo "Subido correctamente: $db_path\n";
    $count++;
}

file_put_contents('C:\Users\dell12\Desktop\actualizar_luminarias_NUEVO.sql', $sql);

echo "\nTotal actualizados: $count\n";
echo "Nuevo SQL generado en el escritorio!\n";
