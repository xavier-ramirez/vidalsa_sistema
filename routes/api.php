<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\EquipoController;
use App\Http\Controllers\MovilizacionController;
use App\Http\Controllers\FrenteTrabajoController;

/*
|--------------------------------------------------------------------------
| API Routes - Vidalsa Mobile App
|--------------------------------------------------------------------------
*/

// ── Rutas PÚBLICAS (sin token – para descarga inicial de datos) ─────────
Route::post('/mobile/login',  [LoginController::class, 'mobileLogin']);
Route::get('/mobile/equipos', [EquipoController::class, 'mobileIndex']);
Route::get('/mobile/equipos/{id}', [EquipoController::class, 'mobileShow']);
Route::get('/mobile/frentes', [FrenteTrabajoController::class, 'mobileIndex']);

// ── Rutas PROTEGIDAS (requieren token Sanctum) ──────────────────────────
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/mobile/logout', [LoginController::class, 'mobileLogout']);
    Route::get('/mobile/user',    function (Request $request) { return $request->user(); });
    Route::put('/mobile/user/password', [LoginController::class, 'mobileChangePassword']);

    // Equipos del frente del usuario (descarga selectiva con metadata de versionado de PDFs)
    Route::get('/mobile/mis-equipos', [EquipoController::class, 'mobileMisEquipos']);

    // Frentes asignados al usuario (para selects de registrar/editar equipos)
    Route::get('/mobile/frentes-asignados', [EquipoController::class, 'mobileFrentesAsignados']);

    // CRUD móvil simplificado de equipos (campos básicos, sin documentos ni fotos)
    Route::post('/mobile/equipos',       [EquipoController::class, 'mobileCreate']);
    Route::put( '/mobile/equipos/{id}',  [EquipoController::class, 'mobileUpdate']);

    // Descarga de PDF binario por tipo: propiedad | poliza | rotc | racda
    Route::get('/mobile/equipos/{id}/pdf/{tipo}', [EquipoController::class, 'mobilePdfBinary']);

    // Historial de movilizaciones de un equipo
    Route::get('/mobile/equipos/{id}/movilizaciones', [EquipoController::class, 'mobileMovilizacionesByEquipo']);

    // Movilizaciones (solo usuarios autenticados pueden registrar)
    Route::get('/mobile/movilizaciones',  [MovilizacionController::class, 'mobileIndex']);
    Route::post('/mobile/movilizaciones', [MovilizacionController::class, 'mobileStore']);
});
