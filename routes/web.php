<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [App\Http\Controllers\SystemController::class, 'loginPage'])->name('login');

Route::get('/login', [App\Http\Controllers\SystemController::class, 'loginRedirect']);

// Lightweight route to refresh CSRF token (Handshake)
Route::get('/refresh-csrf', [App\Http\Controllers\SystemController::class, 'refreshCsrf']);

Route::post('/', [App\Http\Controllers\Auth\LoginController::class, 'login'])->name('login.post');
Route::redirect('/home', '/menu');

Route::middleware(['auth'])->group(function () {
    // Password Change Routes (Excluded from password check loop)
    Route::get('/admin/cambiar-clave', [App\Http\Controllers\Auth\ChangePasswordController::class, 'show'])->name('password.change');
    Route::post('/admin/cambiar-clave', [App\Http\Controllers\Auth\ChangePasswordController::class, 'update'])->name('password.update');

    Route::middleware(['password.change.check'])->group(function () {
        Route::get('/menu', [App\Http\Controllers\DashboardController::class, 'index'])->name('menu');
        Route::post('/system/reset-cache', [App\Http\Controllers\DashboardController::class, 'resetCache'])->name('system.reset-cache');
        Route::get('/dashboard/alerts-html', [App\Http\Controllers\DashboardController::class, 'getAlertsHtml'])->name('dashboard.alertsHtml');
        Route::get('/dashboard/pending-movs-html', [App\Http\Controllers\DashboardController::class, 'getPendingMovsHtml'])->name('dashboard.pendingMovsHtml');
        Route::post('/dashboard/iniciar-gestion', [App\Http\Controllers\DashboardController::class, 'iniciarGestion'])->name('dashboard.iniciarGestion');
        Route::get('/dashboard/export-documents-pdf', [App\Http\Controllers\DashboardController::class, 'exportDocumentsPDF'])->name('dashboard.exportDocumentsPDF');


        Route::prefix('admin')->group(function () {
            // Ruta de perfil propio (disponible para TODOS los usuarios autenticados)
            Route::get('usuarios/mi-perfil', [App\Http\Controllers\UserController::class, 'miPerfil'])->name('usuarios.miPerfil');
            Route::put('usuarios/mi-perfil', [App\Http\Controllers\UserController::class, 'actualizarMiClave'])->name('usuarios.actualizarMiClave');

            Route::resource('usuarios', App\Http\Controllers\UserController::class)->except(['show']);
            Route::get('frentes/buscar', [App\Http\Controllers\FrenteTrabajoController::class, 'search'])->name('frentes.search');
            Route::resource('frentes', App\Http\Controllers\FrenteTrabajoController::class)->except(['show']);

            // Catalog Linking API Routes (Must be before resource to avoid ID conflict)
            Route::get('equipos/all-models', [App\Http\Controllers\EquipoController::class, 'getAllModels'])->name('equipos.allModels');
            Route::get('equipos/search-catalog', [App\Http\Controllers\EquipoController::class, 'searchCatalogMatch'])->name('equipos.searchCatalog');
            Route::get('catalogo/brands-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getBrandsFromEquipos'])->name('catalogo.brandsFromEquipos');
            Route::get('catalogo/models-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getModelsFromEquipos'])->name('catalogo.modelsFromEquipos');
            Route::get('catalogo/years-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getYearsFromEquipos'])->name('catalogo.yearsFromEquipos');
            Route::patch('equipos/{id}/status', [App\Http\Controllers\EquipoController::class, 'changeStatus'])->name('equipos.changeStatus');
            Route::post('equipos/{id}/upload-doc', [App\Http\Controllers\EquipoController::class, 'uploadDoc'])->name('equipos.uploadDoc');
            Route::delete('equipos/{id}/delete-doc', [App\Http\Controllers\EquipoController::class, 'deleteDoc'])->name('equipos.deleteDoc');
            Route::get('equipos/export', [App\Http\Controllers\EquipoController::class, 'export'])->name('equipos.export');
            Route::get('equipos/search-field', [App\Http\Controllers\EquipoController::class, 'searchField'])->name('equipos.searchField');
            Route::get('equipos/search-specs', [App\Http\Controllers\EquipoController::class, 'searchSpecs'])->name('equipos.searchSpecs');
            Route::get('equipos/check-unique', [App\Http\Controllers\EquipoController::class, 'checkUniqueness'])->name('equipos.checkUnique');
            Route::get('equipos/{id}/metadata', [App\Http\Controllers\EquipoController::class, 'metadata'])->name('equipos.metadata');
            Route::post('equipos/{id}/update-metadata', [App\Http\Controllers\EquipoController::class, 'updateMetadata'])->name('equipos.updateMetadata');
            Route::get('equipos/{id}/responsables', [App\Http\Controllers\EquipoController::class, 'getResponsables'])->name('equipos.getResponsables');
            Route::post('equipos/{id}/responsables', [App\Http\Controllers\EquipoController::class, 'storeResponsable'])->name('equipos.storeResponsable');

            Route::get('equipos/fleet-stats', [App\Http\Controllers\EquipoController::class, 'fleetStats'])->name('equipos.fleetStats');
            Route::get('equipos/fleet-export', [App\Http\Controllers\EquipoController::class, 'fleetExport'])->name('equipos.fleetExport');
            Route::post('equipos/bulk-mobilize', [App\Http\Controllers\MovilizacionController::class, 'bulkStore'])->name('equipos.bulkMobilize');
            Route::get('equipos/get-equipos-by-frente', [App\Http\Controllers\EquipoController::class, 'getEquiposByFrente'])->name('equipos.getByFrente');
            Route::get('equipos/get-anchors', [App\Http\Controllers\EquipoController::class, 'getAnchoredEquipos'])->name('equipos.getAnchors');
            Route::post('equipos/bulk-anchor', [App\Http\Controllers\EquipoController::class, 'bulkAnchor'])->name('equipos.bulkAnchor');
            Route::post('equipos/clear-anchor', [App\Http\Controllers\EquipoController::class, 'clearAnchor'])->name('equipos.clearAnchor');
            Route::patch('equipos/{id}/ubicacion', [App\Http\Controllers\EquipoController::class, 'updateUbicacion'])->name('equipos.updateUbicacion');
            Route::resource('equipos', App\Http\Controllers\EquipoController::class);
            // Rutas específicas de Movilizaciones ANTES del resource (evita conflicto de wildcard)
            Route::post('movilizaciones/recepcion-directa', [App\Http\Controllers\MovilizacionController::class, 'recepcionDirecta'])->name('movilizaciones.recepcionDirecta');
            Route::get('movilizaciones/buscar-equipos-recepcion', [App\Http\Controllers\MovilizacionController::class, 'buscarEquiposParaRecepcion'])->name('movilizaciones.buscarEquipos');
            Route::get('movilizaciones/subdivisiones/{id}', [App\Http\Controllers\MovilizacionController::class, 'getSubdivisiones'])->name('movilizaciones.subdivisiones');
            Route::patch('movilizaciones/{id}/status', [App\Http\Controllers\MovilizacionController::class, 'updateStatus'])->name('movilizaciones.updateStatus');
            Route::get('movilizaciones/{id}/acta-traslado', [App\Http\Controllers\MovilizacionController::class, 'generarActaTraslado'])->name('movilizaciones.actaTraslado');
            // Resource route al final para que sus wildcards no capturen las rutas estáticas de arriba
            Route::resource('movilizaciones', App\Http\Controllers\MovilizacionController::class);

            Route::resource('catalogo', App\Http\Controllers\CaracteristicaModeloController::class);

            // ── Consumibles ──────────────────────────────────────────────────
            // IMPORTANTE: rutas estáticas ANTES de wildcards ({id}) para evitar colisión
            Route::get ('consumibles',                    [App\Http\Controllers\ConsumiblesController::class, 'index'])          ->name('consumibles.index');
            Route::get ('consumibles/cargar',             [App\Http\Controllers\ConsumiblesController::class, 'cargar'])         ->name('consumibles.cargar');
            Route::get ('consumibles/buscar-frente',      [App\Http\Controllers\ConsumiblesController::class, 'buscarFrente'])   ->name('consumibles.buscarFrente');
            Route::get ('consumibles/graficos-data',      [App\Http\Controllers\ConsumiblesController::class, 'graficosData'])   ->name('consumibles.graficosData');
            Route::get ('consumibles/graficos',           [App\Http\Controllers\ConsumiblesController::class, 'graficos'])       ->name('consumibles.graficos');
            Route::get ('consumibles/exportar-csv',       [App\Http\Controllers\ConsumiblesController::class, 'exportarCsv'])    ->name('consumibles.exportarCsv');
            Route::post('consumibles/guardar-lote',       [App\Http\Controllers\ConsumiblesController::class, 'guardarLote'])    ->name('consumibles.guardarLote');
            Route::post('consumibles/match-automatico',   [App\Http\Controllers\ConsumiblesController::class, 'matchAutomatico'])->name('consumibles.matchAutomatico');
            // Rutas con wildcard {id} al final para no capturar segmentos estáticos
            Route::patch('consumibles/{id}/estado',       [App\Http\Controllers\ConsumiblesController::class, 'updateEstado'])   ->name('consumibles.updateEstado');
            Route::patch('consumibles/{id}/identificador',[App\Http\Controllers\ConsumiblesController::class, 'updateIdentificador'])->name('consumibles.updateIdentificador');
            Route::patch('consumibles/{id}/frente',       [App\Http\Controllers\ConsumiblesController::class, 'updateFrente'])   ->name('consumibles.updateFrente');
            Route::delete('consumibles/{id}',             [App\Http\Controllers\ConsumiblesController::class, 'destroy'])        ->name('consumibles.destroy');

            // ── Sub-activos (Herramientas / Equipos Menores) ─────────────────
            Route::get ('sub-activos',        [App\Http\Controllers\SubActivoController::class, 'index'])  ->name('sub-activos.index');
            Route::get ('sub-activos/count',  [App\Http\Controllers\SubActivoController::class, 'count'])  ->name('sub-activos.count');
            Route::post('sub-activos',        [App\Http\Controllers\SubActivoController::class, 'store'])  ->name('sub-activos.store');
            Route::patch('sub-activos/{id}',  [App\Http\Controllers\SubActivoController::class, 'update']) ->name('sub-activos.update');
            Route::delete('sub-activos/{id}', [App\Http\Controllers\SubActivoController::class, 'destroy'])->name('sub-activos.destroy');

            // ── Herramientas Manuales ────────────────────────────────────────
            Route::get('herramientas/consolidado-manual', function () {
                return view('admin.herramientas.consolidado_manual');
            })->name('herramientas.consolidadoManual');

            Route::get('herramientas/calculadora-filtros', function () {
                return view('admin.herramientas.calculadora_filtros');
            })->name('herramientas.calculadoraFiltros');

            Route::get('herramientas/calculadora-equipos-frentes', function () {
                $frentes = \App\Models\FrenteTrabajo::orderBy('NOMBRE_FRENTE')->pluck('NOMBRE_FRENTE')->toArray();
                return view('admin.herramientas.calculadora_frentes', compact('frentes'));
            })->name('herramientas.calculadoraFrentes');

            // RUTA DE EMERGENCIA: REPARAR ESQUEMA BD
            // Requiere autenticación (heredada) + permiso explícito super.admin
            Route::get('system/force-fix-db/vidalsa123', function () {
                if (!auth()->user()->can('super.admin')) {
                    abort(403, 'Acceso denegado.');
                }
                return app(App\Http\Controllers\SystemController::class)->forceFixDb();
            });
        });

    });
});

// Route replaced by root POST
Route::post('/logout', [App\Http\Controllers\Auth\LoginController::class, 'logout'])->name('logout');

// Google Drive File Proxy (Extreme Optimization with Full Range Support)
Route::middleware(['auth'])->get('storage/google/{path}', [App\Http\Controllers\GoogleDriveController::class, 'proxy'])
    ->where('path', '.*')
    ->name('drive.file');
