<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$equipos = \App\Models\Equipo::whereNotNull('ID_ANCLAJE')->take(2)->get();
if ($equipos->isEmpty()) {
    echo "No anchored items.\n";
    exit;
}

$id1 = $equipos[0]->ID_EQUIPO;
$id2 = $equipos[0]->ID_ANCLAJE;

echo "Anchored pair: $id1 <-> $id2\n";

\Illuminate\Support\Facades\DB::beginTransaction();
try {
    \App\Models\Equipo::whereIn('ID_EQUIPO', [$id1, $id2])->update(['ID_ANCLAJE' => null]);
    \Illuminate\Support\Facades\DB::commit();
    echo "Unanchored $id1 and $id2 successfully.\n";
} catch (\Exception $e) {
    \Illuminate\Support\Facades\DB::rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}

$check1 = \App\Models\Equipo::find($id1);
$check2 = \App\Models\Equipo::find($id2);

echo "Check $id1: anchor is " . var_export($check1->ID_ANCLAJE, true) . "\n";
echo "Check $id2: anchor is " . var_export($check2->ID_ANCLAJE, true) . "\n";
