<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$request = Illuminate\Http\Request::create('/admin/equipos/bulk-mobilize', 'POST', [
    'ids' => [1],
    'destination' => 'BARCELONA',
    'generar_pdf' => false
]);
app()->instance('request', $request);

$controller = new App\Http\Controllers\MovilizacionController();
try {
    $response = $controller->bulkStore($request);
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Content: " . $response->getContent() . "\n";
} catch (\Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
