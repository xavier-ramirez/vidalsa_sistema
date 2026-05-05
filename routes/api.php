<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\MovilizacionController;
use App\Http\Controllers\FrenteTrabajoController;

/*
|--------------------------------------------------------------------------
| API Routes - Vidalsa Mobile App (Expo + SQLite)
|--------------------------------------------------------------------------
| Endpoints minimos que la APK consume:
|   - login/logout para autenticar (Sanctum)
|   - GET equipos/frentes para descargar el dump al SQLite local
|   - GET/POST movilizaciones para historial + sincronizacion del outbox
*/

// Publicas (sin token: descarga inicial post-login)
Route::post('/mobile/login',  [LoginController::class, 'mobileLogin']);
Route::get('/mobile/equipos', [EquipoController::class, 'mobileIndex']);
Route::get('/mobile/frentes', [FrenteTrabajoController::class, 'mobileIndex']);

// Protegidas con Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mobile/logout',         [LoginController::class, 'mobileLogout']);
    Route::get( '/mobile/movilizaciones', [MovilizacionController::class, 'mobileIndex']);
    Route::post('/mobile/movilizaciones', [MovilizacionController::class, 'mobileStore'])
        ->middleware('can:equipos.create');
});
