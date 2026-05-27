<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\MovilizacionController;
use App\Http\Controllers\FrenteTrabajoController;

/*
|--------------------------------------------------------------------------
| API Routes - Vidalsa Mobile App (Expo + SQLite)
|--------------------------------------------------------------------------
| Endpoints minimos que la APK consume:
|   - login/logout para autenticar (Sanctum)
|   - GET equipos/frentes/catalogos-destacados para descargar el dump inicial
|     al SQLite local (foto del hero + 3 modelos destacados con foto base64)
|   - GET/POST movilizaciones para historial + sincronizacion del outbox
*/

// Publicas (sin token: descarga inicial post-login)
Route::post('/mobile/login',                [LoginController::class, 'mobileLogin']);
Route::get( '/mobile/equipos',              [EquipoController::class, 'mobileIndex']);
Route::get( '/mobile/frentes',              [FrenteTrabajoController::class, 'mobileIndex']);
Route::get( '/mobile/catalogos-destacados', [DashboardController::class, 'mobileCatalogosDestacados']);

// Protegidas con Sanctum
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mobile/logout',         [LoginController::class, 'mobileLogout']);
    Route::get( '/mobile/movilizaciones', [MovilizacionController::class, 'mobileIndex']);
    Route::post('/mobile/movilizaciones', [MovilizacionController::class, 'mobileStore'])
        ->middleware('can:equipos.create');
    // Cambio de estado operativo desde la APK (sincronizacion de outbox).
    // Mismo permiso que el dropdown inline del web: equipos.edit.
    Route::post('/mobile/equipos/{id}/status', [EquipoController::class, 'mobileChangeStatus'])
        ->middleware('can:equipos.edit');
});
