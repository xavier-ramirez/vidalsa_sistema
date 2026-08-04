<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
use Illuminate\Support\Facades\DB;

$luminarias = DB::table('equipos_auxiliares')
    ->whereIn('ID_AUXILIAR', [40, 41, 124, 127, 129, 130, 131, 132, 133, 134, 135, 136, 137])
    ->get(['ID_AUXILIAR', 'LINK_DOC_PROPIEDAD']);

$sql = "";
foreach($luminarias as $l) {
    if ($l->LINK_DOC_PROPIEDAD) {
        $sql .= "UPDATE equipos_auxiliares SET LINK_DOC_PROPIEDAD = '{$l->LINK_DOC_PROPIEDAD}' WHERE ID_AUXILIAR = {$l->ID_AUXILIAR};\n";
    }
}

file_put_contents('C:\Users\dell12\Desktop\actualizar_luminarias.sql', $sql);
echo "SQL File generated!\n";
