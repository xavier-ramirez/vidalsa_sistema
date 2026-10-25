<?php

use Illuminate\Support\Facades\Route;

// La PC pregunta qué escaneó el teléfono de su usuario (App\Services\LectorRemoto). Aquí y no
// en web.php para que NO pase por la sesión: cada pregunta la renovaría y nunca se cerraría por
// inactividad. La identidad la da la llave firmada de la cabecera X-Lector-Canal.
Route::get('/lector-remoto', [App\Http\Controllers\LectorRemotoController::class, 'pendientes'])->name('lector-remoto.pendientes');
