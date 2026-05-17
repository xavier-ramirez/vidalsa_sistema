<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

DB::table('almacenes')->insertOrIgnore([
    'ID_ALMACEN' => 22,
    'CODIGO' => 'ALM-022',
    'NOMBRE' => 'VIDALSA BARCELONA',
    'TIPO' => 'GENERAL',
    'UBICACION' => 'Barcelona, Anzoátegui',
    'ESTATUS' => 'ACTIVO',
    'NOTAS' => 'Almacén principal',
    'CREADO_POR' => 1,
    'created_at' => now(),
    'updated_at' => now()
]);

echo "Almacen 22 created.\n";
