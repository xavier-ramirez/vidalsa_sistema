<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\FrenteTrabajo;
use App\Http\Requests\FrenteRequest;
use App\Http\Controllers\FrenteTrabajoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

try {
    $request = FrenteRequest::create('/admin/frentes', 'POST', [
        'NOMBRE_FRENTE'=>'TEST FRENTE ' . rand(1, 9999),
        'UBICACION'=>'TEST UBI',
        'TIPO_FRENTE'=>'OPERACION',
        'ESTATUS_FRENTE'=>'ACTIVO',
        'RESP_1_NOM'=>'JUAN',
        'RESP_1_CAR'=>'BOSS'
    ]);
    
    // Simulate resolution of request by laravel
    $request->setContainer($app);
    $request->setRedirector($app->make(\Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    $controller = new FrenteTrabajoController();
    $response = $controller->store($request);
    
    echo "SUCCESS: " . get_class($response) . "\n";
} catch (ValidationException $e) {
    echo "VALIDATION FAILED: \n";
    print_r($e->errors());
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

