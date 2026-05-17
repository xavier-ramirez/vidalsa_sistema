<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$sqlFilePath = 'C:\\Users\\dell12\\Downloads\\vidalsa2302 (1).sql';

if (!file_exists($sqlFilePath)) {
    die("File not found: " . $sqlFilePath . "\n");
}

echo "Wiping database...\n";
\Illuminate\Support\Facades\Artisan::call('db:wipe');
echo "Database wiped.\n";

echo "Reading SQL file...\n";
$sql = file_get_contents($sqlFilePath);

echo "Executing SQL script (this might take a while)...\n";
try {
    DB::unprepared($sql);
    echo "Import completed successfully.\n";
} catch (\Exception $e) {
    echo "Error during import: " . $e->getMessage() . "\n";
}
