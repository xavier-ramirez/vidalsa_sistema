<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$u = App\Models\Usuario::where('CORREO_ELECTRONICO', 'Like', '%fsanchez%')->first();
if ($u) {
    if(!Hash::check('14041404', $u->PASSWORD_HASH)) {
        $u->PASSWORD_HASH = Hash::make('14041404');
        $u->save();
        echo "Password updated for " . $u->CORREO_ELECTRONICO;
    } else {
        echo "Password already correct for ". $u->CORREO_ELECTRONICO;
    }
} else {
    echo "User not found!!";
}
