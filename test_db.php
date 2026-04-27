<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$res = DB::select("SELECT 'equipos' as t, ID_EQUIPO, PLACA_INVENTARIO FROM equipos WHERE FOTO_EQUIPO LIKE '%1SZu0LWRc459olSLi4M38jf6v96GHqf6T%' UNION SELECT 'equipos_auxiliares' as t, ID_AUXILIAR, CODIGO_INTERNO FROM equipos_auxiliares WHERE FOTO_AUXILIAR LIKE '%1SZu0LWRc459olSLi4M38jf6v96GHqf6T%'");
echo "\n==== RESULTS ====\n";
echo json_encode($res, JSON_PRETTY_PRINT);
echo "\n==== END ====\n";
