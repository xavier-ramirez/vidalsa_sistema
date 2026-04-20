<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = App\Models\Usuario::where('CORREO_ELECTRONICO', 'fsanchez@cvidalsa27.com')->first();
$user->PASSWORD_HASH = Illuminate\Support\Facades\Hash::make('password123');
$user->save();
echo "Done";
