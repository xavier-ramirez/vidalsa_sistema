<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use App\Services\GoogleDriveService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\File;

$pdf_path = 'C:\Users\dell12\Downloads\Bol de 11 torres de luz  (1).pdf';

if (!file_exists($pdf_path)) {
    echo "Error: PDF not found\n";
    exit;
}

try {
    $driveService = GoogleDriveService::getInstance();
    $folderId = $driveService->getRootFolderId();
    echo "Folder ID: $folderId\n";

    $file = new File($pdf_path);
    $filename = 'propiedad_luminarias_' . time() . '.pdf';

    echo "Uploading to Google Drive...\n";
    $driveFile = $driveService->uploadFile($folderId, $file, $filename, 'application/pdf');

    if ($driveFile && isset($driveFile->id)) {
        echo "Successfully uploaded! Drive ID: " . $driveFile->id . "\n";
        
        $db_path = '/storage/google/' . $driveFile->id . '?v=' . time();
        echo "DB Path: $db_path\n";

        // Update DB
        $ids = [40, 41, 124, 127, 129, 130, 131, 132, 133, 134, 135, 136, 137];
        DB::table('equipos_auxiliares')
            ->whereIn('ID_AUXILIAR', $ids)
            ->update(['LINK_DOC_PROPIEDAD' => $db_path]);
            
        // Generate SQL
        $sql = "";
        foreach ($ids as $id) {
            $sql .= "UPDATE equipos_auxiliares SET LINK_DOC_PROPIEDAD = '{$db_path}' WHERE ID_AUXILIAR = {$id};\n";
        }
        file_put_contents('C:\Users\dell12\Desktop\actualizar_luminarias_DRIVE.sql', $sql);
        echo "SQL file generated on Desktop: actualizar_luminarias_DRIVE.sql\n";

    } else {
        echo "Upload failed (No ID returned).\n";
    }

} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
