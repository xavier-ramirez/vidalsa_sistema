<?php

use Illuminate\Support\Facades\Route;

Route::get('/', [App\Http\Controllers\SystemController::class, 'loginPage'])->name('login');

Route::get('/login', [App\Http\Controllers\SystemController::class, 'loginRedirect']);

// Lightweight route to refresh CSRF token (Handshake)
Route::get('/refresh-csrf', [App\Http\Controllers\SystemController::class, 'refreshCsrf']);

Route::post('/', [App\Http\Controllers\Auth\LoginController::class, 'login'])->name('login.post');
Route::redirect('/home', '/menu');

// TEMP: Seeder de fallas para mantenimiento (eliminar después de usar)
Route::get('/system/seed-fallas-temp', function () {
    $frentes = [28, 15, 8, 12, 14]; // ARECUNA, BARCELONA, CHUTO+BATEA, PATIO MATURIN, ASIG PDVSA DAL
    $user = \App\Models\Usuario::first();
    if (!$user) return response()->json(['error' => 'No hay usuarios en la BD']);

    $equipos = \App\Models\Equipo::whereIn('ID_FRENTE_ACTUAL', $frentes)->get()->groupBy('ID_FRENTE_ACTUAL');
    $allEquipos = \App\Models\Equipo::limit(100)->get();
    if ($allEquipos->isEmpty()) return response()->json(['error' => 'No hay equipos en la BD']);

    $tipos = ['MECANICA', 'ELECTRICA', 'HIDRAULICA', 'NEUMATICA', 'ESTRUCTURAL'];
    $prioridades = ['CRITICA', 'ALTA', 'MEDIA', 'BAJA'];
    $sistemas = ['Motor', 'Transmisión', 'Sistema hidráulico', 'Sistema eléctrico', 'Frenos', 'Dirección', 'Chasis', 'Sistema de enfriamiento', 'Sistema de escape', 'Tren de rodaje'];

    $descripciones = [
        'Fuga de aceite en empaquetadura del cárter del motor',
        'Alternador no genera carga, batería se descarga constantemente',
        'Cilindro de levante presenta fuga por retén inferior',
        'Compresor de frenos no alcanza presión mínima de operación',
        'Fisura en el bastidor lateral derecho cerca del punto de articulación',
        'Sobrecalentamiento del motor al operar bajo carga sostenida',
        'Válvula de control principal no responde al accionar palanca derecha',
        'Correa del ventilador presenta desgaste excesivo y patinaje',
        'Manguera hidráulica de retorno reventada, pérdida total de fluido',
        'Sensor de temperatura del refrigerante envía lecturas erráticas',
        'Rodamiento del turbo presenta juego axial excesivo y ruido',
        'Bomba de combustible pierde cebado después de parada prolongada',
        'Zapatas del tren de rodaje desgastadas por debajo del límite',
        'Switch de arranque intermitente, requiere múltiples intentos',
        'Fuga de refrigerante por manguera superior del radiador',
        'Pines y bocinas del cucharón con desgaste severo',
        'Luz de advertencia del filtro hidráulico encendida permanentemente',
        'Cadena del tren de rodaje izquierdo presenta elongación excesiva',
        'Sistema de climatización inoperante, compresor no embraga',
        'Válvula de alivio del circuito de dirección ajustada incorrectamente',
        'Motor presenta humo negro excesivo bajo carga',
        'Escape de aire por conexión rápida del sistema neumático',
        'Cuchilla del ripper con desgaste irregular en un solo lado',
        'Pedal de freno con recorrido largo, requiere sangrado del sistema',
        'Luces delanteras de trabajo fundidas ambos lados',
        'Aceite hidráulico contaminado con agua, aspecto lechoso',
        'Radiador de aceite hidráulico con aletas aplastadas 40%',
        'Arrancador gira pero no engrana con el volante del motor',
        'Sello de la tapa de combustible deteriorado, entra agua al tanque',
        'Balde de excavación con soldadura fisurada en los laterales',
    ];

    $resoluciones = [
        'Se reemplazó empaquetadura y se verificó torque de pernos',
        'Alternador reemplazado por uno reacondicionado, se verificó carga',
        'Se cambió kit de sellos del cilindro y se purgó el sistema',
        'Compresor reemplazado, válvulas de freno calibradas',
        'Soldadura de reparación con refuerzo estructural aprobada por ingeniería',
        'Radiador limpiado, termostato reemplazado, mangueras nuevas',
        'Válvula de control desarmada, se limpiaron carretes y resortes',
        'Correa reemplazada y tensores ajustados a especificación',
        'Manguera reemplazada, sistema hidráulico purgado y rellenado',
        'Sensor de temperatura reemplazado y cableado verificado',
    ];

    $created = [];
    $frenteIndex = 0;

    for ($i = 0; $i < 30; $i++) {
        $frenteId = $frentes[$frenteIndex % 5];
        $frenteIndex++;

        $reporte = \App\Models\ReporteDiario::firstOrCreate(
            ['ID_FRENTE' => $frenteId, 'FECHA_REPORTE' => now()->toDateString()],
            ['ID_USUARIO_CREA' => $user->ID_USUARIO, 'ESTADO_REPORTE' => 'ABIERTO']
        );

        $frenteEquipos = $equipos->get($frenteId);
        if ($frenteEquipos && $frenteEquipos->count() > 0) {
            $equipo = $frenteEquipos->random();
        } else {
            $equipo = $allEquipos->random();
        }

        $horas = ['06:15','06:45','07:00','07:30','08:00','08:20','08:45','09:10','09:30','10:00',
                   '10:15','10:45','11:00','11:30','12:00','13:15','13:45','14:00','14:30','15:00',
                   '15:20','15:45','16:00','16:30','17:00','17:15','17:45','18:00','18:30','19:00'];

        $falla = \App\Models\RegistroFalla::create([
            'ID_REPORTE' => $reporte->ID_REPORTE,
            'ID_EQUIPO' => $equipo->ID_EQUIPO,
            'TIPO_FALLA' => $tipos[array_rand($tipos)],
            'SISTEMA_AFECTADO' => $sistemas[array_rand($sistemas)],
            'DESCRIPCION_FALLA' => $descripciones[$i],
            'PRIORIDAD' => $prioridades[array_rand($prioridades)],
            'ESTADO_FALLA' => 'ABIERTA',
            'HORA_REGISTRO' => $horas[$i],
            'ID_USUARIO_REGISTRA' => $user->ID_USUARIO,
        ]);

        $created[] = $falla->ID_FALLA;
    }

    // 12 resueltas (IDs 0-11)
    $toResolve = array_slice($created, 0, 12);
    foreach ($toResolve as $idx => $fallaId) {
        \App\Models\RegistroFalla::where('ID_FALLA', $fallaId)->update([
            'ESTADO_FALLA' => 'RESUELTA',
            'DESCRIPCION_RESOLUCION' => $resoluciones[$idx % count($resoluciones)],
            'FECHA_RESOLUCION' => now(),
        ]);
    }

    // 5 en proceso (IDs 12-16)
    $toProcess = array_slice($created, 12, 5);
    foreach ($toProcess as $fallaId) {
        \App\Models\RegistroFalla::where('ID_FALLA', $fallaId)->update([
            'ESTADO_FALLA' => 'EN_PROCESO',
        ]);
    }

    return response()->json([
        'success' => true,
        'created' => count($created),
        'resolved' => count($toResolve),
        'en_proceso' => count($toProcess),
        'abiertas' => 30 - count($toResolve) - count($toProcess),
        'falla_ids' => $created,
    ]);
});

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
            Route::get ('consumibles',                    [App\Http\Controllers\ConsumiblesController::class, 'index'])          ->name('consumibles.index');
            Route::get ('consumibles/cargar',             [App\Http\Controllers\ConsumiblesController::class, 'cargar'])         ->name('consumibles.cargar');
            Route::post('consumibles/guardar-lote',       [App\Http\Controllers\ConsumiblesController::class, 'guardarLote'])    ->name('consumibles.guardarLote');
            Route::patch('consumibles/{id}/estado',       [App\Http\Controllers\ConsumiblesController::class, 'updateEstado'])   ->name('consumibles.updateEstado');
            Route::patch('consumibles/{id}/identificador',[App\Http\Controllers\ConsumiblesController::class, 'updateIdentificador'])->name('consumibles.updateIdentificador');
            Route::patch('consumibles/{id}/frente',       [App\Http\Controllers\ConsumiblesController::class, 'updateFrente'])        ->name('consumibles.updateFrente');
            Route::delete('consumibles/{id}',             [App\Http\Controllers\ConsumiblesController::class, 'destroy'])        ->name('consumibles.destroy');
            // API
            Route::get ('consumibles/buscar-frente',      [App\Http\Controllers\ConsumiblesController::class, 'buscarFrente'])   ->name('consumibles.buscarFrente');
            Route::get ('consumibles/graficos-data',      [App\Http\Controllers\ConsumiblesController::class, 'graficosData'])   ->name('consumibles.graficosData');
            Route::get ('consumibles/graficos',           [App\Http\Controllers\ConsumiblesController::class, 'graficos'])       ->name('consumibles.graficos');
            Route::get ('consumibles/exportar-csv',       [App\Http\Controllers\ConsumiblesController::class, 'exportarCsv'])    ->name('consumibles.exportarCsv');
            Route::post('consumibles/match-automatico',   [App\Http\Controllers\ConsumiblesController::class, 'matchAutomatico'])->name('consumibles.matchAutomatico');

            // ── Sub-activos (Herramientas / Equipos Menores) ─────────────────
            Route::get ('sub-activos',        [App\Http\Controllers\SubActivoController::class, 'index'])  ->name('sub-activos.index');
            Route::get ('sub-activos/count',  [App\Http\Controllers\SubActivoController::class, 'count'])  ->name('sub-activos.count');
            Route::post('sub-activos',        [App\Http\Controllers\SubActivoController::class, 'store'])  ->name('sub-activos.store');
            Route::patch('sub-activos/{id}',  [App\Http\Controllers\SubActivoController::class, 'update']) ->name('sub-activos.update');
            Route::delete('sub-activos/{id}', [App\Http\Controllers\SubActivoController::class, 'destroy'])->name('sub-activos.destroy');

            // ── Mantenimiento Integral ───────────────────────────────────────
            Route::prefix('mantenimiento')->name('mantenimiento.')->group(function () {
                Route::get('/',                     [App\Http\Controllers\MantenimientoController::class, 'index'])->name('index');
                Route::get('/reportes',             [App\Http\Controllers\MantenimientoController::class, 'getReportesDiarios'])->name('reportes');
                Route::get('/reporte/{id}',         [App\Http\Controllers\MantenimientoController::class, 'showReporteDiario'])->name('reporte.show');
                Route::post('/reporte-hoy',         [App\Http\Controllers\MantenimientoController::class, 'getOrCreateReporte'])->name('reporte.hoy');
                Route::post('/reporte/{id}/cerrar', [App\Http\Controllers\MantenimientoController::class, 'cerrarReporte'])->name('reporte.cerrar');
                Route::post('/falla',               [App\Http\Controllers\MantenimientoController::class, 'storeFalla'])->name('falla.store');
                Route::put('/falla/{id}',           [App\Http\Controllers\MantenimientoController::class, 'updateFalla'])->name('falla.update');
                Route::get('/falla/{id}',           [App\Http\Controllers\MantenimientoController::class, 'showFalla'])->name('falla.show');
                Route::get('/consolidado',          [App\Http\Controllers\MantenimientoController::class, 'consolidadoDiario'])->name('consolidado');
                Route::get('/buscar-equipos',        [App\Http\Controllers\MantenimientoController::class, 'searchEquipos'])->name('buscarEquipos');
                Route::get('/timeline/{equipoId}',  [App\Http\Controllers\MantenimientoController::class, 'timeline'])->name('timeline');
                Route::get('/recomendar/{equipoId}',[App\Http\Controllers\MantenimientoController::class, 'recomendarMateriales'])->name('recomendar');
                Route::post('/falla/{id}/material', [App\Http\Controllers\MantenimientoController::class, 'storeMaterial'])->name('material.store');
                Route::get('/falla/{id}/pdf',       [App\Http\Controllers\MantenimientoController::class, 'exportPdfIndividual'])->name('falla.pdf');
                Route::post('/reporte/{id}/pdf',    [App\Http\Controllers\MantenimientoController::class, 'exportPdfLote'])->name('reporte.pdf');
                Route::get('/consolidado/pdf',      [App\Http\Controllers\MantenimientoController::class, 'exportPdfConsolidado'])->name('consolidado.pdf');
                Route::get('/stats',                [App\Http\Controllers\MantenimientoController::class, 'statsWidget'])->name('stats');
            });

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
        });

    });
});

// Route replaced by root POST
Route::post('/logout', [App\Http\Controllers\Auth\LoginController::class, 'logout'])->name('logout');

// Google Drive File Proxy (Extreme Optimization with Full Range Support)
Route::middleware(['auth'])->get('storage/google/{path}', [App\Http\Controllers\GoogleDriveController::class, 'proxy'])
    ->where('path', '.*')
    ->name('drive.file');

// RUTA DE EMERGENCIA: REPARAR Y TIPO LOCAL (ORDENAR COLUMNAS)
Route::get('/system/force-fix-db/vidalsa123', [App\Http\Controllers\SystemController::class, 'forceFixDb']);
