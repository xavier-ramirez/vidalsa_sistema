<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$affected = DB::table('almacen_stock')
    ->where('CANTIDAD', '<=', 0)
    ->orWhereNull('CANTIDAD')
    ->update([
        'CANTIDAD' => DB::raw('FLOOR(RAND() * (150 - 20 + 1)) + 20'),
        'CANTIDAD_MINIMA' => DB::raw('FLOOR(RAND() * (20 - 5 + 1)) + 5')
    ]);

echo "Stock actualizado para " . $affected . " productos.\n";
