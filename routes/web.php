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
            Route::get('equipos/export-anchors', [App\Http\Controllers\EquipoController::class, 'exportAnclajes'])->middleware('can:equipos.edit')->name('equipos.exportAnclajes');
            Route::post('equipos/bulk-anchor', [App\Http\Controllers\EquipoController::class, 'bulkAnchor'])->middleware('can:equipos.assign')->name('equipos.bulkAnchor');
            Route::post('equipos/clear-anchor', [App\Http\Controllers\EquipoController::class, 'clearAnchor'])->middleware('can:equipos.assign')->name('equipos.clearAnchor');
            Route::patch('equipos/{id}/ubicacion', [App\Http\Controllers\EquipoController::class, 'updateUbicacion'])->name('equipos.updateUbicacion');
            Route::post('equipos/bulk-ubicacion', [App\Http\Controllers\EquipoController::class, 'bulkUbicacion'])->name('equipos.bulkUbicacion');
            Route::get('equipos/bulk-template', [App\Http\Controllers\EquipoController::class, 'bulkTemplate'])->name('equipos.bulkTemplate');
            Route::post('equipos/bulk-preview', [App\Http\Controllers\EquipoController::class, 'bulkPreview'])->name('equipos.bulkPreview');
            Route::post('equipos/bulk-store-batch', [App\Http\Controllers\EquipoController::class, 'bulkStoreBatch'])->name('equipos.bulkStoreBatch');
            Route::resource('equipos', App\Http\Controllers\EquipoController::class);
            Route::post('movilizaciones/bulk-delete', [App\Http\Controllers\MovilizacionController::class, 'bulkDestroy'])->name('movilizaciones.bulkDestroy');
            Route::post('movilizaciones/recepcion-directa', [App\Http\Controllers\MovilizacionController::class, 'recepcionDirecta'])->name('movilizaciones.recepcionDirecta');
            Route::get('movilizaciones/buscar-equipos-recepcion', [App\Http\Controllers\MovilizacionController::class, 'buscarEquiposParaRecepcion'])->name('movilizaciones.buscarEquipos');
            Route::get('movilizaciones/subdivisiones/{id}', [App\Http\Controllers\MovilizacionController::class, 'getSubdivisiones'])->name('movilizaciones.subdivisiones');
            Route::get('movilizaciones/{id}/acta-traslado', [App\Http\Controllers\MovilizacionController::class, 'generarActaTraslado'])->name('movilizaciones.actaTraslado');
            Route::get('movilizaciones/find-by-codigo', [App\Http\Controllers\MovilizacionController::class, 'findByCodigoControl'])->name('movilizaciones.findByCodigo');
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
            Route::get ('consumibles/auditoria-catalogo', [App\Http\Controllers\ConsumiblesController::class, 'auditoriaCatalogoData'])->middleware('can:super.admin')->name('consumibles.auditoriaCatalogo');
            Route::get ('consumibles/exportar-csv',       [App\Http\Controllers\ConsumiblesController::class, 'exportarCsv'])    ->name('consumibles.exportarCsv');
            Route::post('consumibles/guardar-lote',       [App\Http\Controllers\ConsumiblesController::class, 'guardarLote'])    ->middleware('can:super.admin')->name('consumibles.guardarLote');
            Route::post('consumibles/match-automatico',   [App\Http\Controllers\ConsumiblesController::class, 'matchAutomatico'])->name('consumibles.matchAutomatico');
            // Rutas con wildcard {id} al final para no capturar segmentos estáticos
            Route::patch('consumibles/{id}/estado',       [App\Http\Controllers\ConsumiblesController::class, 'updateEstado'])   ->name('consumibles.updateEstado');
            Route::patch('consumibles/{id}/identificador',[App\Http\Controllers\ConsumiblesController::class, 'updateIdentificador'])->name('consumibles.updateIdentificador');
            Route::patch('consumibles/{id}/frente',       [App\Http\Controllers\ConsumiblesController::class, 'updateFrente'])   ->name('consumibles.updateFrente');
            Route::delete('consumibles/{id}',             [App\Http\Controllers\ConsumiblesController::class, 'destroy'])        ->name('consumibles.destroy');

            // ── Equipos Auxiliares (maquinas de soldar, luminarias, compresores, etc) ──
            // Reemplaza el antiguo modulo "sub-activos" con logica de anclaje 1:N
            // (un camion de soldadura puede llevar hasta 2 maquinas de soldar).
            // NOTA permisos: el proyecto no tiene un gate 'equipos.view' — la
            // convencion es que cualquier user autenticado lee el listado de
            // equipos (el middleware 'auth' del group padre lo cubre). Los
            // endpoints de modificacion si requieren can:equipos.edit.
            // Los endpoints de autocomplete (search/searchHosts) quedan tambien
            // solo bajo auth porque alimentan la UI de anclaje, pero el anchor
            // real si requiere edit.
            Route::get   ('equipos-auxiliares',                [App\Http\Controllers\EquipoAuxiliarController::class, 'index'])  ->name('equipos-auxiliares.index');
            Route::get   ('equipos-auxiliares/count',          [App\Http\Controllers\EquipoAuxiliarController::class, 'count'])  ->name('equipos-auxiliares.count');
            Route::get   ('equipos-auxiliares/{id}/details',   [App\Http\Controllers\EquipoAuxiliarController::class, 'details'])->name('equipos-auxiliares.details');
            Route::get   ('equipos-auxiliares/export',         [App\Http\Controllers\EquipoAuxiliarController::class, 'export']) ->name('equipos-auxiliares.export');
            Route::get   ('equipos-auxiliares/by-host/{id}',   [App\Http\Controllers\EquipoAuxiliarController::class, 'byHost']) ->name('equipos-auxiliares.byHost');
            Route::get   ('equipos-auxiliares/search',         [App\Http\Controllers\EquipoAuxiliarController::class, 'search']) ->name('equipos-auxiliares.search');
            Route::get   ('equipos-auxiliares/hosts/search',   [App\Http\Controllers\EquipoAuxiliarController::class, 'searchHosts'])->name('equipos-auxiliares.searchHosts');
            Route::get   ('equipos-auxiliares/create',         [App\Http\Controllers\EquipoAuxiliarController::class, 'create'])->middleware('can:equipos.create')->name('equipos-auxiliares.create');
            Route::post  ('equipos-auxiliares',                [App\Http\Controllers\EquipoAuxiliarController::class, 'store']) ->middleware('can:equipos.create')->name('equipos-auxiliares.store');
            Route::get   ('equipos-auxiliares/{id}/edit',      [App\Http\Controllers\EquipoAuxiliarController::class, 'edit']) ->middleware('can:equipos.edit')->name('equipos-auxiliares.edit');
            Route::patch ('equipos-auxiliares/{id}',           [App\Http\Controllers\EquipoAuxiliarController::class, 'update'])->middleware('can:equipos.edit')->name('equipos-auxiliares.update');
            Route::delete('equipos-auxiliares/{id}',           [App\Http\Controllers\EquipoAuxiliarController::class, 'destroy'])->middleware('can:super.admin')->name('equipos-auxiliares.destroy');
            // Vincular/desvincular (anchor/unanchor) + movilizacion: flujos de
            // asignacion fisica -> permiso equipos.assign (coherente con el
            // modulo /admin/equipos: anclar y movilizar van en la misma clave).
            Route::post  ('equipos-auxiliares/{id}/anchor',    [App\Http\Controllers\EquipoAuxiliarController::class, 'anchor'])  ->middleware('can:equipos.assign')->name('equipos-auxiliares.anchor');
            Route::post  ('equipos-auxiliares/{id}/unanchor',  [App\Http\Controllers\EquipoAuxiliarController::class, 'unanchor'])->middleware('can:equipos.assign')->name('equipos-auxiliares.unanchor');
            Route::patch ('equipos-auxiliares/{id}/estado',    [App\Http\Controllers\EquipoAuxiliarController::class, 'changeStatus'])->middleware('can:equipos.edit')->name('equipos-auxiliares.estado');
            Route::post  ('equipos-auxiliares/{id}/upload-doc',[App\Http\Controllers\EquipoAuxiliarController::class, 'uploadDoc'])->middleware('can:user.edit')->name('equipos-auxiliares.uploadDoc');
            Route::post  ('equipos-auxiliares/bulk-move',      [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkMove'])->middleware('can:equipos.assign')->name('equipos-auxiliares.bulkMove');

            // Carga masiva via Excel (patron identico a /admin/equipos)
            Route::get   ('equipos-auxiliares/bulk-template',     [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkTemplate'])->middleware('can:equipos.create')->name('equipos-auxiliares.bulkTemplate');
            Route::post  ('equipos-auxiliares/bulk-preview',      [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkPreview'])->middleware('can:equipos.create')->name('equipos-auxiliares.bulkPreview');
            Route::post  ('equipos-auxiliares/bulk-store-batch', [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkStoreBatch'])->middleware('can:equipos.create')->name('equipos-auxiliares.bulkStoreBatch');

            // ── Reporte de Fallas (placeholder: modulo pendiente de definicion) ──
            // El usuario pidio el boton en el navbar pero aun no confirmo alcance
            // (opciones A/B/C/D en propuesta anterior). Ruta temporal que muestra
            // vista "En construccion" para evitar 404 desde el boton.
            Route::get('fallas', function () {
                return view('admin.fallas.placeholder');
            })->name('fallas.index');



            // ── Auditoría Documental ─────────────────────────────────────────
            Route::middleware('can:super.admin')->group(function () {
                Route::get('historial-documentos', [App\Http\Controllers\HistorialDocumentosController::class, 'index'])->name('historial-documentos.index');
                Route::delete('historial-documentos/unlock-ip/{id}', [App\Http\Controllers\HistorialDocumentosController::class, 'unlockIp'])->name('historial-documentos.unlock-ip');
            });

            // Ruta de emergencia `force-fix-db` removida: los ajustes de schema ahora
            // viven solo en migraciones idempotentes bajo `php artisan migrate`.
        });

    });
});

// Route replaced by root POST
Route::post('/logout', [App\Http\Controllers\Auth\LoginController::class, 'logout'])->name('logout');

// Google Drive File Proxy (Extreme Optimization with Full Range Support)
Route::middleware(['auth'])->get('storage/google/{path}', [App\Http\Controllers\GoogleDriveController::class, 'proxy'])
    ->where('path', '.*')
    ->name('drive.file');
