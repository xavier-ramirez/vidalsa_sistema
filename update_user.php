<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$u = App\Models\Usuario::where('CORREO_ELECTRONICO','2@cvidalsa27.com')->first();
$u->PASSWORD_HASH = Hash::make('12345678');
$u->ESTATUS = 'ACTIVO';
$u->save();

if (method_exists($u, 'hasPermissionTo')) {
    echo $u->hasPermissionTo('super.admin') ? 'is_super' : 'no_super';
} else if (method_exists($u, 'hasRole')) {
    echo $u->hasRole('super.admin') ? 'is_super' : 'no_super';
} else {
    echo $u->NIVEL_ACCESO == 'super.admin' ? 'is_super' : 'no_super';
}
