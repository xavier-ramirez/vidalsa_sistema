<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$sql = file_get_contents('import_temp.sql');
// Remove comments
$sql = preg_replace('/--.*$/m', '', $sql);
try {
    DB::unprepared($sql);
    echo 'EXITO: ' . DB::table('equipos')->count() . ' equipos, ' . DB::table('frentes_trabajo')->count() . ' frentes, ' . DB::table('movilizacion_historial')->count() . ' historial.';
} catch (\Exception $e) {
    echo 'ERROR: ' . $e->getMessage();
}

