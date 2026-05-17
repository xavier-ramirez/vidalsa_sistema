<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$sqlFilePath = 'C:\\Users\\dell12\\Downloads\\vidalsa2302 (1).sql';

echo "Reading SQL file...\n";
$sql = file_get_contents($sqlFilePath);

echo "Disabling foreign key checks...\n";
DB::statement('SET FOREIGN_KEY_CHECKS=0;');

echo "Executing SQL script...\n";
try {
    // Some PDO drivers might have issues with multiple statements, but Laravel handles it
    // if PDO::MYSQL_ATTR_MULTI_STATEMENTS is true.
    DB::unprepared($sql);
    echo "Import completed successfully.\n";
} catch (\Exception $e) {
    echo "Error during import: " . $e->getMessage() . "\n";
}

DB::statement('SET FOREIGN_KEY_CHECKS=1;');
