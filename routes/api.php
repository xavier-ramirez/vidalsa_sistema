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

    // CRUD móvil de equipos — gateado por permiso (igual que /admin/equipos web).
    // 'equipos.create' para registrar, 'user.edit' para editar (Gate::before resuelve super.admin).
    Route::post('/mobile/equipos',      [EquipoController::class, 'mobileCreate'])->middleware('can:equipos.create');
    Route::put( '/mobile/equipos/{id}', [EquipoController::class, 'mobileUpdate'])->middleware('can:user.edit');

    // Descarga de PDF binario por tipo: propiedad | poliza | rotc | racda
    Route::get('/mobile/equipos/{id}/pdf/{tipo}', [EquipoController::class, 'mobilePdfBinary']);

    // Historial de movilizaciones de un equipo
    Route::get('/mobile/equipos/{id}/movilizaciones', [EquipoController::class, 'mobileMovilizacionesByEquipo']);

    // Acciones desde modal CASILLERO (Tarea 2). Reportar falla queda abierto a
    // cualquier autenticado (todos los operadores reportan averias).
    Route::patch('/mobile/equipos/{id}/estado',       [EquipoController::class, 'mobileChangeEstado'])->middleware('can:equipos.edit');
    Route::post( '/mobile/equipos/{id}/falla',        [EquipoController::class, 'mobileReportarFalla']);
    Route::get(  '/mobile/equipos/{id}/responsables', [EquipoController::class, 'mobileResponsablesByEquipo']);

    // Acciones masivas (Tarea 4 — selección múltiple en la APK)
    Route::post('/mobile/equipos/bulk-movilizar', [EquipoController::class, 'mobileBulkMovilizar'])->middleware('can:equipos.create');
    Route::post('/mobile/equipos/bulk-ubicacion', [EquipoController::class, 'mobileBulkUbicacion'])->middleware('can:equipos.edit');
    Route::post('/mobile/equipos/bulk-anchor',    [EquipoController::class, 'mobileBulkAnchor'])->middleware('can:equipos.edit');
    Route::post('/mobile/equipos/bulk-unanchor',  [EquipoController::class, 'mobileBulkUnanchor'])->middleware('can:equipos.edit');

    // Movilizaciones (registrar requiere mismo permiso que en la web: equipos.create).
    Route::get( '/mobile/movilizaciones', [MovilizacionController::class, 'mobileIndex']);
    Route::post('/mobile/movilizaciones', [MovilizacionController::class, 'mobileStore'])->middleware('can:equipos.create');
});
