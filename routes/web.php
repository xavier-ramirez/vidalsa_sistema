<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [App\Http\Controllers\SystemController::class, 'loginPage'])->name('login');

Route::get('/login', [App\Http\Controllers\SystemController::class, 'loginRedirect']);

// Lightweight route to refresh CSRF token (Handshake)
Route::get('/refresh-csrf', [App\Http\Controllers\SystemController::class, 'refreshCsrf']);

// Previsualizar las páginas de error con su diseño branded (404/403/405/419/500/503).
// Solo renderiza la vista; no dispara el error real. Útil para revisar el diseño.
Route::get('/preview/error/{code}', function (string $code) {
    abort_unless(in_array($code, ['403', '404', '405', '419', '500', '503'], true), 404);
    return response()->view("errors.{$code}", [], (int) $code);
})->name('preview.error');

// Service Worker con CACHE_VERSION dinámico: cada deploy cambia el filemtime del
// sw.js → nuevo nombre de cache → activate purga los caches viejos automáticamente.
// Sin esto __CACHE_VERSION__ queda literal y los caches nunca se invalidan.
// La plantilla vive en resources/ (FUERA del docroot) a propósito: si estuviera
// en public/, cualquier servidor que sirva estáticos antes que Laravel (nginx,
// Apache, Caddy) la entregaría cruda con el placeholder sin reemplazar.
Route::get('/sw.js', function () {
    $path = resource_path('sw.js');

    // CACHE_VERSION = commit git actual + filemtime(sw.js). Así CADA deploy invalida el
    // caché del SW AUTOMÁTICAMENTE (git pull → nuevo commit → nueva versión), sin tener
    // que editar sw.js a mano; y en local, editar sw.js también bumpea (por el filemtime).
    // Sin esto, un deploy que no tocara sw.js dejaba a los usuarios con el caché viejo.
    $hash = null;
    try {
        $gitDir = base_path('.git');
        $head = @file_get_contents($gitDir . '/HEAD');
        if ($head !== false) {
            if (strpos($head, 'ref:') === 0) {
                $ref     = trim(substr($head, 4));          // p.ej. "refs/heads/main"
                $refFile = $gitDir . '/' . $ref;
                if (is_file($refFile)) {
                    $hash = trim(@file_get_contents($refFile));
                } else {                                    // ref empaquetada (git gc)
                    $packed = @file_get_contents($gitDir . '/packed-refs');
                    if ($packed !== false && preg_match('/^([0-9a-f]{40})\s+' . preg_quote($ref, '/') . '$/m', $packed, $m)) {
                        $hash = $m[1];
                    }
                }
            } else {
                $hash = trim($head);                        // HEAD detached → hash directo
            }
        }
    } catch (\Throwable $e) {
        $hash = null;
    }
    $version = ($hash ? substr($hash, 0, 12) . '-' : '') . (string) @filemtime($path);
    $version = preg_replace('/[^0-9a-zA-Z-]/', '', $version); // nombre de caché seguro

    $content = str_replace('__CACHE_VERSION__', $version, file_get_contents($path));
    return response($content, 200)
        ->header('Content-Type', 'application/javascript')
        ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->header('Service-Worker-Allowed', '/');
});

Route::post('/', [App\Http\Controllers\Auth\LoginController::class, 'login'])->name('login.post');
Route::redirect('/home', '/menu');

// ── WebAuthn (huella / biometría) ──────────────────────────────────────
// Login-options y login son públicos (el usuario aún no está autenticado);
// la seguridad la da el challenge criptográfico, no el CSRF.
Route::post('/webauthn/login-options', [App\Http\Controllers\Auth\WebAuthnController::class, 'loginOptions'])->name('webauthn.loginOptions');
Route::post('/webauthn/login',         [App\Http\Controllers\Auth\WebAuthnController::class, 'login'])->name('webauthn.login');

Route::middleware(['auth'])->group(function () {
    // Password Change Routes (Excluded from password check loop)
    Route::get('/admin/cambiar-clave', [App\Http\Controllers\Auth\ChangePasswordController::class, 'show'])->name('password.change');
    Route::post('/admin/cambiar-clave', [App\Http\Controllers\Auth\ChangePasswordController::class, 'update'])->name('password.update');

    // WebAuthn registro (requiere auth: el usuario ya inició sesión con contraseña)
    Route::post('/webauthn/register-options', [App\Http\Controllers\Auth\WebAuthnController::class, 'registerOptions'])->name('webauthn.registerOptions');
    Route::post('/webauthn/register',         [App\Http\Controllers\Auth\WebAuthnController::class, 'register'])->name('webauthn.register');

    Route::middleware(['password.change.check'])->group(function () {
        Route::get('/menu', [App\Http\Controllers\DashboardController::class, 'index'])->name('menu');
        // Modulo de Mapa: pagina nueva abierta desde el boton "Mapa" del tablero (/menu).
        Route::get('/mapa', [App\Http\Controllers\MapaController::class, 'index'])->name('mapa');
        // Oleoductos del mapa (proyectos de puntos + linea). API JSON que consume mapa_index.js.
        Route::get   ('/mapa/oleoductos',              [App\Http\Controllers\OleoductoController::class, 'index'])->name('mapa.oleoductos.index');
        // Escritura del mapa (crear punto, asociar/dibujar, borrar punto/proyecto): SOLO con el
        // PERMISO 'super.admin' (depende del permiso en usuarios.PERMISOS, no del rol). Ver el
        // mapa e index (GET) queda abierto a todos los usuarios con acceso al módulo.
        Route::post  ('/mapa/oleoductos/frente/{idFrente}/puntos', [App\Http\Controllers\OleoductoController::class, 'addPuntoFrente'])->middleware('can:super.admin')->name('mapa.oleoductos.addPuntoFrente');
        // Ubicación SUELTA: punto con nombre sin proyecto (grupo reservado suelto = true).
        Route::post  ('/mapa/oleoductos/puntos',       [App\Http\Controllers\OleoductoController::class, 'addPuntoSuelto'])->middleware('can:super.admin')->name('mapa.oleoductos.addPuntoSuelto');
        Route::post  ('/mapa/oleoductos/{id}/recorrido', [App\Http\Controllers\OleoductoController::class, 'saveRecorrido'])->middleware('can:super.admin')->name('mapa.oleoductos.recorrido');
        // Un punto puede estar en VARIOS proyectos: por eso ambas rutas llevan el proyecto.
        // vincular = meter un punto que ya existe en otro proyecto (sin duplicar la coordenada).
        // destroyPunto = quitarlo DE ESE proyecto; si era el ultimo, el punto se borra.
        // Compartir un punto que YA existe con otro proyecto. Se direcciona por FRENTE, no por
        // proyecto: un proyecto solo nace al guardar su primer punto, así que por-proyecto solo
        // se podía compartir con frentes que ya tuvieran puntos. Si el frente no tiene proyecto,
        // se crea aquí (mismo grupoUnico que addPuntoFrente, sin riesgo de duplicarlo).
        Route::post  ('/mapa/oleoductos/frente/{idFrente}/puntos/{idPunto}/vincular', [App\Http\Controllers\OleoductoController::class, 'vincularPuntoFrente'])->middleware('can:super.admin')->name('mapa.oleoductos.vincularPuntoFrente');
        Route::delete('/mapa/oleoductos/{idOleoducto}/puntos/{idPunto}', [App\Http\Controllers\OleoductoController::class, 'destroyPunto'])->middleware('can:super.admin')->name('mapa.oleoductos.destroyPunto');
        Route::delete('/mapa/oleoductos/{id}',         [App\Http\Controllers\OleoductoController::class, 'destroy'])->middleware('can:super.admin')->name('mapa.oleoductos.destroy');
        Route::post('/system/reset-cache', [App\Http\Controllers\DashboardController::class, 'resetCache'])->name('system.reset-cache');
        Route::get('/dashboard/alerts-html', [App\Http\Controllers\DashboardController::class, 'getAlertsHtml'])->name('dashboard.alertsHtml');
        Route::post('/dashboard/iniciar-gestion', [App\Http\Controllers\DashboardController::class, 'iniciarGestion'])->name('dashboard.iniciarGestion');
        Route::get('/dashboard/export-documents-pdf', [App\Http\Controllers\DashboardController::class, 'exportDocumentsPDF'])->name('dashboard.exportDocumentsPDF');

        // ── Modo OFFLINE (Fase 1: consulta sin internet) ──────────────────────────
        // version(): huella barata para que el teléfono sepa si hay datos nuevos.
        // snapshot(): copia de solo lectura, acotada a los almacenes visibles del usuario.
        Route::get('/offline/version',  [App\Http\Controllers\OfflineController::class, 'version'])->name('offline.version');
        Route::get('/offline/snapshot', [App\Http\Controllers\OfflineController::class, 'snapshot'])->name('offline.snapshot');
        // Fase 2 (escritura offline): el outbox de la PWA sube aquí las acciones
        // hechas sin internet (movilizar/estado/recepción). Gates por acción dentro
        // del controller; ruta WEB (sesión + CSRF), no la API Sanctum del APK.
        Route::post('/offline/sync', [App\Http\Controllers\OfflineSyncController::class, 'sync'])->name('offline.sync');

        Route::prefix('admin')->group(function () {
            // Ruta de perfil propio (disponible para TODOS los usuarios autenticados)
            Route::get('usuarios/mi-perfil', [App\Http\Controllers\UserController::class, 'miPerfil'])->name('usuarios.miPerfil');
            Route::put('usuarios/mi-perfil', [App\Http\Controllers\UserController::class, 'actualizarMiClave'])->name('usuarios.actualizarMiClave');

            Route::get('usuarios/roles/unused', [App\Http\Controllers\UserController::class, 'getUnusedRoles'])->name('usuarios.unused-roles');
            Route::delete('usuarios/roles/unused', [App\Http\Controllers\UserController::class, 'deleteUnusedRoles'])->name('usuarios.delete-unused-roles');
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
                Route::resource('frentes', App\Http\Controllers\FrenteTrabajoController::class)->except(['show']);
            });

            // Catalog Linking API Routes (Must be before resource to avoid ID conflict)
            Route::get('equipos/all-models', [App\Http\Controllers\EquipoController::class, 'getAllModels'])->name('equipos.allModels');
            Route::get('equipos/search-catalog', [App\Http\Controllers\EquipoController::class, 'searchCatalogMatch'])->name('equipos.searchCatalog');
            Route::get('catalogo/brands-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getBrandsFromEquipos'])->name('catalogo.brandsFromEquipos');
            Route::get('catalogo/models-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getModelsFromEquipos'])->name('catalogo.modelsFromEquipos');
            Route::get('catalogo/years-from-equipos', [App\Http\Controllers\CaracteristicaModeloController::class, 'getYearsFromEquipos'])->name('catalogo.yearsFromEquipos');
            Route::patch('equipos/{id}/status', [App\Http\Controllers\EquipoController::class, 'changeStatus'])->name('equipos.changeStatus');
            // Confirmar presencia física del equipo en su frente (CONFIRMADO_EN_SITIO).
            Route::patch('equipos/{id}/confirmar-sitio', [App\Http\Controllers\EquipoController::class, 'confirmarSitio'])->name('equipos.confirmarSitio');
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
            Route::patch ('equipos/{id}/restore',    [App\Http\Controllers\EquipoController::class, 'restoreEquipo'])->middleware('can:super.admin')->name('equipos.restore');
            Route::delete('equipos/{id}/permanente', [App\Http\Controllers\EquipoController::class, 'forceDeleteEquipo'])->whereNumber('id')->middleware('can:super.admin')->name('equipos.forceDelete');
            Route::post ('equipos/store-unified',   [App\Http\Controllers\EquipoController::class, 'storeUnified'])->middleware('can:equipos.create')->name('equipos.storeUnified');
            // except('store'): el alta entra SIEMPRE por store-unified (es quien decide entre
            // equipo y auxiliar segun __modo). El POST /admin/equipos del resource quedaba
            // como segundo endpoint de creacion, alcanzable y sin usar por ninguna vista.
            Route::resource('equipos', App\Http\Controllers\EquipoController::class)->except(['store']);
            Route::post('movilizaciones/bulk-delete', [App\Http\Controllers\MovilizacionController::class, 'bulkDestroy'])->name('movilizaciones.bulkDestroy');
            Route::post('movilizaciones/recepcion-directa', [App\Http\Controllers\MovilizacionController::class, 'recepcionDirecta'])->name('movilizaciones.recepcionDirecta');
            Route::get('movilizaciones/buscar-equipos-recepcion', [App\Http\Controllers\MovilizacionController::class, 'buscarEquiposParaRecepcion'])->name('movilizaciones.buscarEquipos');
            Route::get('movilizaciones/subdivisiones/{id}', [App\Http\Controllers\MovilizacionController::class, 'getSubdivisiones'])->name('movilizaciones.subdivisiones');
            Route::get('movilizaciones/{id}/acta-traslado', [App\Http\Controllers\MovilizacionController::class, 'generarActaTraslado'])->name('movilizaciones.actaTraslado');
            // Misma acción vía POST: descarga el acta aplicando las ediciones manuales
            // (override de origen/firmas) hechas en la vista previa. La descarga normal
            // del historial sigue usando el GET de arriba (sin overrides).
            Route::post('movilizaciones/{id}/acta-traslado', [App\Http\Controllers\MovilizacionController::class, 'generarActaTraslado'])->name('movilizaciones.actaTrasladoEdit');
            // Vista previa del acta desde el borrador del modal (sin commitear). El registro
            // real lo hace equipos/bulk-mobilize al confirmar en la vista previa.
            Route::post('movilizaciones/preview-acta', [App\Http\Controllers\MovilizacionController::class, 'previewActaLote'])->name('movilizaciones.previewActa');
            Route::post('movilizaciones/preview-acta-meta', [App\Http\Controllers\MovilizacionController::class, 'previewActaMeta'])->name('movilizaciones.previewActaMeta');
            // Deshacer una movilización: devuelve el equipo/auxiliar a su frente de ORIGEN y borra
            // el registro (como si nunca hubiera ocurrido). Destructivo → super.admin (gateado
            // también en el constructor del controlador).
            Route::post('movilizaciones/{id}/deshacer', [App\Http\Controllers\MovilizacionController::class, 'deshacer'])->name('movilizaciones.deshacer');
            // Resource route al final para que sus wildcards no capturen las rutas estáticas de arriba.
            // Solo 'index' (el listado). create/store/destroy eran un flujo INDIVIDUAL legacy
            // (página create.blade.php ya sin enlazar) INCOHERENTE con el flujo real: bulkStore deja
            // auditoría + bump de dashboard, store() no; y destroy() borraba el registro SIN devolver
            // el equipo a su origen (deshacer() sí). Eliminados. show/edit/update nunca se implementaron.
            Route::resource('movilizaciones', App\Http\Controllers\MovilizacionController::class)
                ->only(['index']);

            // Subida de foto desde la tarjeta del catálogo (sin abrir el form de edición).
            // ANTES del resource para que su wildcard {catalogo} no capture esta ruta.
            Route::post('catalogo/{id}/photo', [App\Http\Controllers\CaracteristicaModeloController::class, 'uploadFoto'])->name('catalogo.uploadFoto');
            Route::delete('catalogo/{id}/photo', [App\Http\Controllers\CaracteristicaModeloController::class, 'deleteFoto'])->middleware('can:super.admin')->name('catalogo.deleteFoto');
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
            Route::get   ('equipos-auxiliares/{id}/details',   [App\Http\Controllers\EquipoAuxiliarController::class, 'details'])->name('equipos-auxiliares.details');
            Route::get   ('equipos-auxiliares/export',         [App\Http\Controllers\EquipoAuxiliarController::class, 'export']) ->name('equipos-auxiliares.export');
            Route::get   ('equipos-auxiliares/by-host/{id}',   [App\Http\Controllers\EquipoAuxiliarController::class, 'byHost']) ->name('equipos-auxiliares.byHost');
            Route::get   ('equipos-auxiliares/search',         [App\Http\Controllers\EquipoAuxiliarController::class, 'search']) ->name('equipos-auxiliares.search');
            Route::get   ('equipos-auxiliares/hosts/search',   [App\Http\Controllers\EquipoAuxiliarController::class, 'searchHosts'])->name('equipos-auxiliares.searchHosts');
            // Verificación de unicidad en vivo (SERIAL / CODIGO_INTERNO) para el form de create
            // unificado. Espejo de equipos/check-unique (read-only, sin gating extra).
            Route::get   ('equipos-auxiliares/check-unique',   [App\Http\Controllers\EquipoAuxiliarController::class, 'checkUnique'])->name('equipos-auxiliares.checkUnique');
            // Catalogo agregado por TIPO+MARCA+MODELO+CAPACIDAD (vista de solo lectura)
            Route::get   ('equipos-auxiliares/catalogo',       [App\Http\Controllers\EquipoAuxiliarController::class, 'catalogo'])->name('equipos-auxiliares.catalogo');
            Route::post  ('equipos-auxiliares/catalogo/photo', [App\Http\Controllers\EquipoAuxiliarController::class, 'uploadCatalogoPhoto'])->middleware('can:equipos.create')->name('equipos-auxiliares.catalogo.uploadPhoto');
            Route::delete('equipos-auxiliares/catalogo/photo', [App\Http\Controllers\EquipoAuxiliarController::class, 'deleteCatalogoPhoto'])->middleware('can:super.admin')->name('equipos-auxiliares.catalogo.deletePhoto');
            Route::post  ('equipos-auxiliares/bulk-delete',    [App\Http\Controllers\EquipoAuxiliarController::class, 'bulkDelete'])->middleware('can:user.delete')->name('equipos-auxiliares.bulkDelete');
            Route::get   ('equipos-auxiliares/papelera',       [App\Http\Controllers\EquipoAuxiliarController::class, 'papelera'])->middleware('can:user.delete')->name('equipos-auxiliares.papelera');
            Route::patch ('equipos-auxiliares/{id}/restore',    [App\Http\Controllers\EquipoAuxiliarController::class, 'restoreAuxiliar'])->middleware('can:user.delete')->name('equipos-auxiliares.restore');
            Route::delete('equipos-auxiliares/{id}/permanente', [App\Http\Controllers\EquipoAuxiliarController::class, 'forceDeleteAuxiliar'])->whereNumber('id')->middleware('can:super.admin')->name('equipos-auxiliares.forceDelete');
            // Listado y export de auxiliares anclados a equipos host (modal Acciones).
            Route::get   ('equipos-auxiliares/anchored',          [App\Http\Controllers\EquipoAuxiliarController::class, 'anchoredList'])->name('equipos-auxiliares.anchoredList');
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
            // Confirmar presencia física del auxiliar en su frente (CONFIRMADO_EN_SITIO), espejo de equipos.
            Route::patch ('equipos-auxiliares/{id}/confirmar-sitio', [App\Http\Controllers\EquipoAuxiliarController::class, 'confirmarSitio'])->middleware('can:equipos.edit')->name('equipos-auxiliares.confirmarSitio');
            Route::post  ('equipos-auxiliares/{id}/upload-doc',     [App\Http\Controllers\EquipoAuxiliarController::class, 'uploadDoc'])     ->middleware('can:user.edit')->name('equipos-auxiliares.uploadDoc');
            // Borrar un documento (propiedad/certificado) del auxiliar. EXCLUSIVO super.admin,
            // igual que equipos.deleteDoc (el visor PDF gatea el botón con CAN_DELETE_DOCS=super.admin).
            Route::delete('equipos-auxiliares/{id}/delete-doc',     [App\Http\Controllers\EquipoAuxiliarController::class, 'deleteDoc'])     ->middleware('can:super.admin')->name('equipos-auxiliares.deleteDoc');
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
            // Vista previa del acta SIN guardar (flujo Editar/Confirmar del modal).
            Route::post ('fallas/preview',        [App\Http\Controllers\FallaController::class, 'previewPdf'])   ->name('fallas.preview');
            Route::post ('fallas',                [App\Http\Controllers\FallaController::class, 'store'])        ->name('fallas.store');
            Route::get  ('fallas/{id}/pdf',       [App\Http\Controllers\FallaController::class, 'pdf'])          ->name('fallas.pdf');
            Route::patch('fallas/{id}/close',     [App\Http\Controllers\FallaController::class, 'close'])        ->name('fallas.close');
            // Borrado DURO de un reporte — EXCLUSIVO super.admin (irreversible, sin rastro).
            Route::delete('fallas/{id}',          [App\Http\Controllers\FallaController::class, 'destroy'])      ->name('fallas.destroy')->middleware('can:super.admin');
            Route::get  ('fallas',                [App\Http\Controllers\FallaController::class, 'index'])        ->name('fallas.index');

            // ── Almacén / Inventario ─────────────────────────────────────────
            // Permisos (claves en columna PERMISOS, gateados en AlmacenController::__construct):
            //   super.admin (CRUD almacenes) · almacen.productos (CRUD catalogo) · almacen.movimiento
            //   (entradas/salidas/ajustes/traspasos + confirmar recepcion).
            // (la consulta básica solo exige 'auth'; el alcance se acota con Almacen::visiblesPara,
            //  que depende sólo de usuarios.NIVEL_ACCESO_ALMACEN — ningún permiso da "ver todos los almacenes".)
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
            // Catálogo de productos para el buscador FuzzySearch del cliente. Antes se embebía
            // inline en la página (~500 KB de 1155 productos) → abría lenta. Ahora se carga por
            // AJAX tras renderizar, así el módulo abre de una. Read-only, mismo criterio que el índice.
            Route::get   ('almacen/productos-autocomplete',       [App\Http\Controllers\AlmacenController::class, 'productosAutocomplete'])->name('almacen.productos-autocomplete');

            // Datos (JSON) — el kardex de movimientos lo consume el modal "Movimientos".
            Route::get   ('almacen/movimientos',                  [App\Http\Controllers\AlmacenController::class, 'movimientos'])      ->name('almacen.movimientos');
            // Dashboard de Consumo (JSON para Chart.js) — alimenta el modal abrible desde el
            // botón Acciones de /admin/almacen y /admin/almacen/movimientos. Solo lectura.
            Route::get   ('almacen/consumo-dashboard',            [App\Http\Controllers\AlmacenController::class, 'consumoDashboard'])  ->name('almacen.consumoDashboard');
            // Compatibilidad de un filtro: equivalencias (nº de parte) + equipos que lo usan.
            // Se carga al abrir "Detalles del producto".
            Route::get   ('almacen/productos/{id}/compatibilidad', [App\Http\Controllers\AlmacenController::class, 'productoCompatibilidad'])->whereNumber('id')->name('almacen.productos.compatibilidad');
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
            // Papelera: rutas estáticas ANTES de las de {id} (orden de coincidencia).
            Route::get   ('almacen/productos/papelera',           [App\Http\Controllers\AlmacenController::class, 'papeleraProductos'])->name('almacen.productos.papelera');
            Route::post  ('almacen/productos/{id}/restaurar',     [App\Http\Controllers\AlmacenController::class, 'restaurarProducto'])->whereNumber('id')->name('almacen.productos.restaurar');
            // Borrado PERMANENTE desde la papelera (forceDelete) — EXCLUSIVO super.admin.
            Route::delete('almacen/productos/{id}/permanente',    [App\Http\Controllers\AlmacenController::class, 'eliminarPermanenteProducto'])->whereNumber('id')->name('almacen.productos.eliminarPermanente');
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
                Route::delete('historial-documentos/registro', [App\Http\Controllers\HistorialDocumentosController::class, 'deleteRegistro'])->name('historial-documentos.deleteRegistro');
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
