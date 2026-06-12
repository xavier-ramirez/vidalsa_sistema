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
        Route::post('/dashboard/iniciar-gestion', [App\Http\Controllers\DashboardController::class, 'iniciarGestion'])->name('dashboard.iniciarGestion');
        Route::get('/dashboard/export-documents-pdf', [App\Http\Controllers\DashboardController::class, 'exportDocumentsPDF'])->name('dashboard.exportDocumentsPDF');

        // ── Modo OFFLINE (Fase 1: consulta sin internet) ──────────────────────────
        // version(): huella barata para que el teléfono sepa si hay datos nuevos.
        // snapshot(): copia de solo lectura, acotada a los almacenes visibles del usuario.
        Route::get('/offline/version',  [App\Http\Controllers\OfflineController::class, 'version'])->name('offline.version');
        Route::get('/offline/snapshot', [App\Http\Controllers\OfflineController::class, 'snapshot'])->name('offline.snapshot');

        Route::prefix('admin')->group(function () {
            // Ruta de perfil propio (disponible para TODOS los usuarios autenticados)
            Route::get('usuarios/mi-perfil', [App\Http\Controllers\UserController::class, 'miPerfil'])->name('usuarios.miPerfil');
            Route::put('usuarios/mi-perfil', [App\Http\Controllers\UserController::class, 'actualizarMiClave'])->name('usuarios.actualizarMiClave');

            Route::resource('usuarios', App\Http\Controllers\UserController::class)->except(['show']);
            // Autocomplete de frentes (/admin/frentes/buscar): lo consume uicomponents.js en
            // formularios de OTROS módulos (equipos/usuarios/almacén), así que NO va gateado
            // por super.admin — solo el módulo de gestión de frentes (abajo) lo está.
            Route::get('frentes/buscar', [App\Http\Controllers\FrenteTrabajoController::class, 'search'])->name('frentes.search');
            // Módulo "Frentes de trabajo": EXCLUSIVO super.admin (clave literal en PERMISOS,
            // independiente del rol — Gate::before en AppServiceProvider). El gate protege
            // server-side; el menú además muestra un toast si un usuario sin la clave intenta abrirlo.
            Route::middleware('can:super.admin')->group(function () {
                // Frentes "papelera": listado de finalizados + restore. Definidos antes del
                // resource para que el segmento literal /finalizados no choque con {frente}.
                Route::get('frentes/finalizados', [App\Http\Controllers\FrenteTrabajoController::class, 'finalizados'])->name('frentes.finalizados');
                Route::get('frentes/sin-equipos', [App\Http\Controllers\FrenteTrabajoController::class, 'sinEquipos'])->name('frentes.sinEquipos');
                Route::patch('frentes/{frente}/restore', [App\Http\Controllers\FrenteTrabajoController::class, 'restore'])->name('frentes.restore');
                Route::patch('frentes/{frente}/finalizar', [App\Http\Controllers\FrenteTrabajoController::class, 'finalizar'])->name('frentes.finalizar');
                Route::resource('frentes', App\Http\Controllers\FrenteTrabajoController::class)->except(['show']);
            });

            // Catalog Linking API Routes (Must be before resource to avoid ID conflict)
            Route::get('equipos/all-models', [App\Http\Controllers\EquipoController::class, 'getAllModels'])->name('equipos.allModels');
            Route::get('equipos/search-catalog', [App\Http\Controllers\EquipoController::class, 'searchCatalogMatch'])->name('equipos.searchCatalog');
            Route::get('catalogo/brands-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getBrandsFromEquipos'])->name('catalogo.brandsFromEquipos');
            Route::get('catalogo/models-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getModelsFromEquipos'])->name('catalogo.modelsFromEquipos');
            Route::get('catalogo/years-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getYearsFromEquipos'])->name('catalogo.yearsFromEquipos');
            Route::patch('equipos/{id}/status', [App\Http\Controllers\EquipoController::class, 'changeStatus'])->name('equipos.changeStatus');
            Route::post('equipos/{id}/upload-doc', [App\Http\Controllers\EquipoController::class, 'uploadDoc'])->name('equipos.uploadDoc');
            // Borrar documento del equipo: destructivo (borra del Drive + BD), solo super.admin.
            Route::delete('equipos/{id}/delete-doc', [App\Http\Controllers\EquipoController::class, 'deleteDoc'])
                ->middleware('can:super.admin')
                ->name('equipos.deleteDoc');
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
            // Bulk lookup: recibe placas/seriales pegados y devuelve frente actual + faltantes.
            Route::post('equipos/bulk-lookup',        [App\Http\Controllers\EquipoController::class, 'bulkLookup'])->name('equipos.bulkLookup');
            // Papelera de equipos (soft-delete + restore con auditoria de quien borro).
            // Definidos ANTES del resource para que /papelera no choque con {id}.
            Route::get  ('equipos/papelera',          [App\Http\Controllers\EquipoController::class, 'papelera'])->name('equipos.papelera');
            Route::post ('equipos/bulk-delete',       [App\Http\Controllers\EquipoController::class, 'bulkDelete'])->middleware('can:user.delete')->name('equipos.bulkDelete');
            Route::patch('equipos/{id}/restore',      [App\Http\Controllers\EquipoController::class, 'restoreEquipo'])->middleware('can:super.admin')->name('equipos.restore');
            Route::resource('equipos', App\Http\Controllers\EquipoController::class);
            Route::post('movilizaciones/bulk-delete', [App\Http\Controllers\MovilizacionController::class, 'bulkDestroy'])->name('movilizaciones.bulkDestroy');
            Route::post('movilizaciones/recepcion-directa', [App\Http\Controllers\MovilizacionController::class, 'recepcionDirecta'])->name('movilizaciones.recepcionDirecta');
            Route::get('movilizaciones/buscar-equipos-recepcion', [App\Http\Controllers\MovilizacionController::class, 'buscarEquiposParaRecepcion'])->name('movilizaciones.buscarEquipos');
            Route::get('movilizaciones/subdivisiones/{id}', [App\Http\Controllers\MovilizacionController::class, 'getSubdivisiones'])->name('movilizaciones.subdivisiones');
            Route::get('movilizaciones/{id}/acta-traslado', [App\Http\Controllers\MovilizacionController::class, 'generarActaTraslado'])->name('movilizaciones.actaTraslado');
            // Misma acción vía POST: descarga el acta aplicando las ediciones manuales
            // (override de origen/firmas) hechas en la vista previa. La descarga normal
            // del historial sigue usando el GET de arriba (sin overrides).
            Route::post('movilizaciones/{id}/acta-traslado', [App\Http\Controllers\MovilizacionController::class, 'generarActaTraslado'])->name('movilizaciones.actaTrasladoEdit');
            Route::get('movilizaciones/find-by-codigo', [App\Http\Controllers\MovilizacionController::class, 'findByCodigoControl'])->name('movilizaciones.findByCodigo');
            // Vista previa del acta desde el borrador del modal (sin commitear). El registro
            // real lo hace equipos/bulk-mobilize al confirmar en la vista previa.
            Route::post('movilizaciones/preview-acta', [App\Http\Controllers\MovilizacionController::class, 'previewActaLote'])->name('movilizaciones.previewActa');
            Route::post('movilizaciones/preview-acta-meta', [App\Http\Controllers\MovilizacionController::class, 'previewActaMeta'])->name('movilizaciones.previewActaMeta');
            // Deshacer una movilización: devuelve el equipo/auxiliar a su frente de ORIGEN y borra
            // el registro (como si nunca hubiera ocurrido). Destructivo → super.admin (gateado
            // también en el constructor del controlador).
            Route::post('movilizaciones/{id}/deshacer', [App\Http\Controllers\MovilizacionController::class, 'deshacer'])->name('movilizaciones.deshacer');
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
            // Ruta consumibles.auditoriaCatalogo removida: el panel de
            // "Auditoría de Catálogo" se elimino del modulo de graficos.
            // El historial de cambios sigue accesible desde /admin/catalogo
            // (cada modelo tiene su propio audit log).
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
            // Catalogo agregado por TIPO+MARCA+MODELO+CAPACIDAD (vista de solo lectura)
            Route::get   ('equipos-auxiliares/catalogo',       [App\Http\Controllers\EquipoAuxiliarController::class, 'catalogo'])->name('equipos-auxiliares.catalogo');
            Route::post  ('equipos-auxiliares/catalogo/photo', [App\Http\Controllers\EquipoAuxiliarController::class, 'uploadCatalogoPhoto'])->middleware('can:equipos.create')->name('equipos-auxiliares.catalogo.uploadPhoto');
            Route::post  ('equipos-auxiliares/bulk-delete',    [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkDelete'])->middleware('can:user.delete')->name('equipos-auxiliares.bulkDelete');
            Route::get   ('equipos-auxiliares/papelera',       [App\Http\Controllers\EquipoAuxiliarController::class, 'papelera'])->middleware('can:user.delete')->name('equipos-auxiliares.papelera');
            Route::patch ('equipos-auxiliares/{id}/restore',   [App\Http\Controllers\EquipoAuxiliarController::class, 'restoreAuxiliar'])->middleware('can:user.delete')->name('equipos-auxiliares.restore');
            // Listado y export de auxiliares anclados a equipos host (modal Acciones).
            Route::get   ('equipos-auxiliares/anchored',          [App\Http\Controllers\EquipoAuxiliarController::class, 'anchoredList'])->name('equipos-auxiliares.anchoredList');
            // Historial de movilizaciones del modulo de aux: lista las
            // movilizaciones donde ID_AUXILIAR != null en movilizacion_historial.
            Route::get   ('equipos-auxiliares/movilizaciones',    [App\Http\Controllers\EquipoAuxiliarController::class, 'historialMovilizaciones'])->name('equipos-auxiliares.historialMovilizaciones');
            Route::get   ('equipos-auxiliares/export-anclajes',   [App\Http\Controllers\EquipoAuxiliarController::class, 'exportAnclajes'])->name('equipos-auxiliares.exportAnclajes');
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
            Route::post  ('equipos-auxiliares/{id}/upload-doc',     [App\Http\Controllers\EquipoAuxiliarController::class, 'uploadDoc'])     ->middleware('can:user.edit')->name('equipos-auxiliares.uploadDoc');
            Route::patch ('equipos-auxiliares/{id}/cert-expiry',    [App\Http\Controllers\EquipoAuxiliarController::class, 'updateCertExpiry'])->middleware('can:user.edit')->name('equipos-auxiliares.updateCertExpiry');
            Route::get   ('equipos-auxiliares/{id}/metadata',        [App\Http\Controllers\EquipoAuxiliarController::class, 'metadata'])       ->name('equipos-auxiliares.metadata');
            Route::post  ('equipos-auxiliares/{id}/update-metadata', [App\Http\Controllers\EquipoAuxiliarController::class, 'updateMetadata']) ->middleware('can:user.edit')->name('equipos-auxiliares.updateMetadata');
            Route::post  ('equipos-auxiliares/bulk-move',      [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkMove'])->middleware('can:equipos.assign')->name('equipos-auxiliares.bulkMove');
            Route::post  ('equipos-auxiliares/bulk-ubicacion', [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkUbicacion'])->middleware('can:equipos.assign')->name('equipos-auxiliares.bulkUbicacion');

            // Carga masiva via Excel (patron identico a /admin/equipos)
            Route::get   ('equipos-auxiliares/bulk-template',     [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkTemplate'])->middleware('can:equipos.create')->name('equipos-auxiliares.bulkTemplate');
            Route::post  ('equipos-auxiliares/bulk-preview',      [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkPreview'])->middleware('can:equipos.create')->name('equipos-auxiliares.bulkPreview');
            Route::post  ('equipos-auxiliares/bulk-store-batch', [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkStoreBatch'])->middleware('can:equipos.create')->name('equipos-auxiliares.bulkStoreBatch');

            // ── Reporte de Fallas ────────────────────────────────────────────────
            // Permiso global: equipos.edit (gateado en FallaController::__construct).
            // Rutas estáticas ANTES de wildcards para evitar colisiones de segmento.
            Route::get  ('fallas/search-activos', [App\Http\Controllers\FallaController::class, 'searchActivos'])->name('fallas.searchActivos');
            Route::post ('fallas',                [App\Http\Controllers\FallaController::class, 'store'])        ->name('fallas.store');
            Route::get  ('fallas/{id}/pdf',       [App\Http\Controllers\FallaController::class, 'pdf'])          ->name('fallas.pdf');
            Route::patch('fallas/{id}/close',     [App\Http\Controllers\FallaController::class, 'close'])        ->name('fallas.close');
            Route::get  ('fallas',                [App\Http\Controllers\FallaController::class, 'index'])        ->name('fallas.index');

            // ── Almacén / Inventario ─────────────────────────────────────────
            // Permisos (claves en columna PERMISOS, gateados en AlmacenController::__construct):
            //   super.admin (CRUD almacenes) · almacen.productos (CRUD catalogo) · almacen.movimiento
            //   (entradas/salidas/ajustes/traspasos + confirmar recepcion).
            // (la consulta básica solo exige 'auth'; el alcance se acota con Almacen::visiblesPara,
            //  que depende sólo de usuarios.NIVEL_ACCESO — ningún permiso da "ver todos los almacenes".)
            // Rutas estáticas ANTES de wildcards. Los {id*} se restringen a numéricos.
            Route::get   ('almacen',                              [App\Http\Controllers\AlmacenController::class, 'index'])            ->name('almacen.index');
            // Exportación XLSX del inventario (sigue el patrón de /admin/equipos/export). Si hay
            // ?id_almacen=N exporta sólo ese almacén con una columna de stock; si no, una columna
            // por cada almacén visible + columna TOTAL.
            Route::get   ('almacen/export',                       [App\Http\Controllers\AlmacenController::class, 'export'])           ->name('almacen.export');

            // ── Etiquetas QR + escaneo (como las etiquetas de góndola del supermercado) ──
            // Etiquetas QR imprimibles. El QR codifica el CODIGO del producto
            // (ProductoInventario::qr_payload). GET porque SOLO lee el catálogo y produce
            // un PDF — mismo nivel de acceso que el export (cualquier usuario que ve el
            // módulo); no muta nada. Parámetros:
            //   ?ids=1,2,3   → solo esos productos (selección de filas / "imprimir 1").
            //   ?categoria=X → todos los activos de esa categoría (cuando no hay ids).
            //   ?formato=carta|50x30|40x25 → hoja A4 en grilla (impresora normal) o una
            //                   etiqueta por página al tamaño exacto del rollo (impresora
            //                   térmica tipo Zebra/Brother). Default: carta. El motor es
            //                   el mismo TCPDF de la Nota de Entrega; solo cambia la página.
            Route::get   ('almacen/etiquetas',                    [App\Http\Controllers\AlmacenController::class, 'etiquetasPdf'])     ->name('almacen.etiquetas');
            // Resolver de escaneo: ?codigo=000123 → JSON { found, producto } con match
            // EXACTO sobre CODIGO y SOLO activos (el índice UNIQUE incluye soft-deleted,
            // por eso se filtra). Read-only, sin permiso (igual que la consulta del módulo).
            Route::get   ('almacen/buscar-codigo',                [App\Http\Controllers\AlmacenController::class, 'resolverPorCodigo'])->name('almacen.buscar-codigo');

            // Datos (JSON) — el kardex de movimientos lo consume el modal "Movimientos".
            Route::get   ('almacen/movimientos',                  [App\Http\Controllers\AlmacenController::class, 'movimientos'])      ->name('almacen.movimientos');
            // Dashboard de Consumo (JSON para Chart.js) — alimenta el modal abrible desde el
            // botón Acciones de /admin/almacen y /admin/almacen/movimientos. Solo lectura.
            Route::get   ('almacen/consumo-dashboard',            [App\Http\Controllers\AlmacenController::class, 'consumoDashboard'])  ->name('almacen.consumoDashboard');
            // Vista alterna de la bitácora agrupada por NUMERO_NOTA — una fila por Nota de
            // Entrega (SALIDA / TRASPASO_SALIDA con N° NE-YYYY-NNNN); clic en la fila abre el
            // PDF oficial. Acceso desde el botón "Bitácora por Nota" del menú Acciones de
            // /admin/almacen/movimientos.
            Route::get   ('almacen/notas',                        [App\Http\Controllers\AlmacenController::class, 'notas'])             ->name('almacen.notas');

            // Movimientos de inventario: SIEMPRE en lote (1+ líneas en una transacción).
            // Maneja ENTRADA (Recepción → entrada directa), SALIDA (selección de filas → Nota de
            // Entrega) y AJUSTE (Auditoría de Inventario del modal de detalles).
            Route::post  ('almacen/movimientos-lote',             [App\Http\Controllers\AlmacenController::class, 'registrarMovimientoLote'])->name('almacen.movimientos.lote');
            // Preview PDF de la Nota de Entrega ANTES de confirmar la salida — genera
            // el documento sin tocar BD para que el usuario lo revise antes de guardar.
            // Si aprieta "Confirmar" se llama el endpoint regular movimientos-lote.
            Route::post  ('almacen/salida/preview-pdf',           [App\Http\Controllers\AlmacenController::class, 'previewSalidaPdf'])->name('almacen.salida.preview');
            // Nota de Entrega (PDF, VID-FO-GEN-019).
            //   ?numero=NE-2026-0001  → recupera el lote por NUMERO_NOTA.
            //   ?ids=10,11,12         → recupera por IDs (lo que devuelve registrarMovimientoLote
            //                           inmediatamente tras crear la nota).
            Route::get   ('almacen/nota-entrega',                 [App\Http\Controllers\AlmacenController::class, 'notaEntregaPdf'])    ->name('almacen.nota-entrega');
            // DELETE: borra la Nota completa por código y revierte el stock vía ENTRADA inversa.
            // Requiere la clave almacen.nota.eliminar: una nota borrada no se recupera y el stock se mueve.
            Route::delete('almacen/nota-entrega',                 [App\Http\Controllers\AlmacenController::class, 'eliminarNota'])      ->middleware('can:almacen.nota.eliminar')->name('almacen.nota-entrega.destroy');
            // DESHACER un movimiento individual del kardex — EXCLUSIVO super.admin.
            // Borrado DURO sin rastro: elimina la fila, revierte el stock y RECALCULA el
            // saldo de los movimientos posteriores del mismo producto+almacén para que el
            // kardex quede coherente (como si nunca hubiera ocurrido). En traspasos deshace
            // AMBAS patas del par. Irreversible — por eso va tras el gate can:super.admin.
            Route::delete('almacen/movimientos/{id}',             [App\Http\Controllers\AlmacenController::class, 'eliminarMovimiento'])->whereNumber('id')->middleware('can:super.admin')->name('almacen.movimientos.destroy');
            // ELIMINAR solo del historial — EXCLUSIVO super.admin. Igual que el destroy de
            // arriba PERO sin tocar el stock: borra la fila (y su contraparte de traspaso)
            // del kardex y NO revierte ni recalcula el saldo. Irreversible.
            Route::delete('almacen/movimientos/{id}/solo-historial', [App\Http\Controllers\AlmacenController::class, 'eliminarMovimientoSoloHistorial'])->whereNumber('id')->middleware('can:super.admin')->name('almacen.movimientos.destroyHistorial');
            Route::patch ('almacen/almacenes/{idAlmacen}/minimo',        [App\Http\Controllers\AlmacenController::class, 'actualizarMinimo'])->whereNumber('idAlmacen')->name('almacen.minimo');

            // Productos (catálogo global)
            Route::post  ('almacen/productos',                    [App\Http\Controllers\AlmacenController::class, 'storeProducto'])   ->name('almacen.productos.store');
            Route::patch ('almacen/productos/{id}',               [App\Http\Controllers\AlmacenController::class, 'updateProducto'])  ->whereNumber('id')->name('almacen.productos.update');
            Route::delete('almacen/productos/{id}',               [App\Http\Controllers\AlmacenController::class, 'destroyProducto']) ->whereNumber('id')->name('almacen.productos.destroy');

            // Almacenes (CRUD + asociación de frentes)
            Route::post  ('almacen/almacenes',                    [App\Http\Controllers\AlmacenController::class, 'storeAlmacen'])    ->name('almacen.almacenes.store');
            Route::patch ('almacen/almacenes/{id}',               [App\Http\Controllers\AlmacenController::class, 'updateAlmacen'])   ->whereNumber('id')->name('almacen.almacenes.update'); // los frentes asociados se mandan en el body de este PATCH
            Route::delete('almacen/almacenes/{id}',               [App\Http\Controllers\AlmacenController::class, 'destroyAlmacen'])  ->whereNumber('id')->name('almacen.almacenes.destroy');

            // ── Recepción de Materiales (envíos por confirmar + historial) ────
            // Los envíos a otros proyectos se generan desde /admin/almacen con el botón
            // único "Salida": AlmacenController::registrarMovimientoLote detecta que el
            // frente destino tiene un almacén distinto y delega a TraspasoService internamente
            // (crea borrador + envía + estampa NUMERO_NOTA). Este módulo lista los pedidos
            // pendientes para que el destinatario los confirme.
            // Permisos: almacen.movimiento (cubre cancelar + confirmar recepción tras el merge).
            // Rutas estáticas ANTES del wildcard {id}. `store` sigue como endpoint público
            // (API/clientes externos); el frontend interno ya no la consume.
            Route::get   ('almacen/recepcion',                       [App\Http\Controllers\TraspasoController::class, 'index'])   ->name('almacen.recepcion.index');
            Route::post  ('almacen/recepcion',                       [App\Http\Controllers\TraspasoController::class, 'store'])   ->name('almacen.recepcion.store');
            // Pagina dedicada "Registrar entrada directa" (compras / devoluciones / conteo inicial).
            // Reemplaza al viejo modal #entModal — misma funcionalidad pero como pantalla propia
            // con autocomplete de producto por codigo o descripcion. POSTea al endpoint existente
            // almacen.movimientos.lote (tipo=ENTRADA), no requiere backend nuevo.
            // Recepcion ODC (Registrar entrada directa): la PANTALLA es accesible sin
            // permiso especial — abrir el formulario no expulsa a nadie. El gate
            // 'almacen.movimiento' se aplica al EJECUTAR la operacion (submit POST
            // almacen.movimientos-lote, ver AlmacenController@registrarMovimientoLote),
            // que responde con un toast claro nombrando la clave si el usuario no la tiene.
            Route::get   ('almacen/recepcion/nueva',                 [App\Http\Controllers\TraspasoController::class, 'nuevaEntrada'])->name('almacen.recepcion.nueva');
            Route::get   ('almacen/recepcion/{id}',                  [App\Http\Controllers\TraspasoController::class, 'show'])    ->whereNumber('id')->name('almacen.recepcion.show');
            Route::patch ('almacen/recepcion/{id}',                  [App\Http\Controllers\TraspasoController::class, 'update'])  ->whereNumber('id')->name('almacen.recepcion.update');
            Route::delete('almacen/recepcion/{id}',                  [App\Http\Controllers\TraspasoController::class, 'destroy']) ->whereNumber('id')->name('almacen.recepcion.destroy');
            Route::post  ('almacen/recepcion/{id}/enviar',           [App\Http\Controllers\TraspasoController::class, 'enviar'])  ->whereNumber('id')->name('almacen.recepcion.enviar');
            Route::post  ('almacen/recepcion/{id}/recibir',          [App\Http\Controllers\TraspasoController::class, 'recibir']) ->whereNumber('id')->name('almacen.recepcion.recibir');
            Route::post  ('almacen/recepcion/{id}/cancelar',         [App\Http\Controllers\TraspasoController::class, 'cancelar'])->whereNumber('id')->name('almacen.recepcion.cancelar');

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

// GET /logout → redirección limpia. El logout REAL es POST (anti-CSRF, lo hace el form
// del header). Si alguien cae en /logout por GET —típico: pestaña vieja con la sesión
// expirada que recargó esa URL— en vez del 405 genérico de Symfony lo mandamos a la
// pantalla de login. NO cierra sesión vía GET (eso seguiría siendo solo por POST).
Route::get('/logout', fn () => redirect()->route('login'));

// Route replaced by root POST
Route::post('/logout', [App\Http\Controllers\Auth\LoginController::class, 'logout'])->name('logout');

// Google Drive File Proxy (Extreme Optimization with Full Range Support)
Route::middleware(['auth'])->get('storage/google/{path}', [App\Http\Controllers\GoogleDriveController::class, 'proxy'])
    ->where('path', '.*')
    ->name('drive.file');
