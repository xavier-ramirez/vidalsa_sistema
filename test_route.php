<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Anchor 36 and 392 again for testing
\App\Models\Equipo::where('ID_EQUIPO', 36)->update(['ID_ANCLAJE' => 392]);
\App\Models\Equipo::where('ID_EQUIPO', 392)->update(['ID_ANCLAJE' => 36]);
echo "Re-anchored 36 and 392.\n";

// Now test the clear-anchor HTTP route
$request = \Illuminate\Http\Request::create('/admin/equipos/clear-anchor', 'POST', [
    'ids' => [36, 392]
]);

$response = app()->handle($request);
echo "Response status: " . $response->getStatusCode() . "\n";
echo "Response body: " . $response->getContent() . "\n";

$check1 = \App\Models\Equipo::find(36);
echo "Check 36: ID_ANCLAJE = " . var_export($check1->ID_ANCLAJE, true) . "\n";
