<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\ProductoInventario;

echo "Iniciando actualizacion a mayusculas de productos y categorias...\n";

$productos = ProductoInventario::all();
$count = 0;

foreach ($productos as $producto) {
    $nombreOriginal = $producto->NOMBRE;
    $categoriaOriginal = $producto->CATEGORIA;
    
    // mb_strtoupper para soportar tildes y eñes sin corromperlas
    $nombreUpper = mb_strtoupper($nombreOriginal ?? '', 'UTF-8');
    $categoriaUpper = mb_strtoupper($categoriaOriginal ?? '', 'UTF-8');
    
    if ($nombreOriginal !== $nombreUpper || $categoriaOriginal !== $categoriaUpper) {
        $producto->NOMBRE = $nombreUpper;
        $producto->CATEGORIA = $categoriaUpper;
        // Evitamos que guarde timestamps si no es necesario, aunque si queremos registrar el cambio no pasa nada.
        // Usaremos saveQuietly() si no queremos disparar eventos, pero save() está bien.
        $producto->save();
        $count++;
    }
}

echo "Actualizacion completada. {$count} productos actualizados a mayusculas.\n";
