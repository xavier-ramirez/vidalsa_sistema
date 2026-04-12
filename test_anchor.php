<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$equipos = \App\Models\Equipo::whereNotNull('ID_ANCLAJE')->get();
$mapped = $equipos->pluck('ID_ANCLAJE', 'ID_EQUIPO')->toArray();
echo json_encode($mapped, JSON_PRETTY_PRINT);
