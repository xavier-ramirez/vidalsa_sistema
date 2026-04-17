<?php
$req = Illuminate\Http\Request::create('/admin/equipos/bulk-mobilize', 'POST', ['ids'=>[10], 'destination'=>'BARCELONA', 'generar_pdf'=>false]);
app()->instance('request', $req);
$controller = new App\Http\Controllers\MovilizacionController();
$res = $controller->bulkStore($req);
echo $res->getContent();
