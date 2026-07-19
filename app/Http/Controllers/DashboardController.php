<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\CaracteristicaModelo;

class DashboardController extends Controller
{
    /**
     * Versión de los datos cacheados del dashboard. La incrementan los observers
     * de Equipo/Documentacion, FrenteTrabajo::booted Y los mass-updates por query
     * builder (whereIn->update / saveQuietly, que NO disparan observers — esos
     * sitios llaman bumpDataVersion() explícitamente). Al ir la versión EN la
     * clave, el bump invalida la caché de TODOS los usuarios a la vez (el
     * Cache::forget per-user de antes solo limpiaba la del usuario que editaba,
     * por eso el TTL tenía que ser de 1 minuto).
     */
    public const DATA_VER_KEY = 'dashboard_data_ver';

    public static function bumpDataVersion(): void
    {
        \App\Support\CacheVersion::bump(self::DATA_VER_KEY);
    }

    public function index()
    {
        $user      = auth()->user();
        // null = ve todos (GLOBAL) | [] = local sin frentes | [ids] (Usuario::frentesVisiblesEquiposIds).
        $frentesVisibles = $user ? $user->frentesVisiblesEquiposIds() : [];
        // Lista negra (se resta SIEMPRE, también a GLOBAL).
        $frentesBloqueados = $user ? $user->getFrentesBloqueadosIds() : [];
        $userId    = $user ? $user->ID_USUARIO : 'guest';

        // Clave por usuario (los datos van acotados a sus frentes) + versión
        // global (ver DATA_VER_KEY): TTL largo sin sacrificar frescura.
        $ver = \App\Support\CacheVersion::current(self::DATA_VER_KEY);
        $cacheKey = "dashboard_user_data_{$userId}_v{$ver}";

        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(10), function () use ($frentesVisibles, $frentesBloqueados) {
            // 1. Pending Mobilizations (disabled since transit is instant)
            $pendientes = 0;

            // 2. Alerts List — LOCAL ve solo sus frentes; bloqueados se ocultan a todos.
            $expiredList = $this->generateAlertsList($frentesVisibles, $frentesBloqueados);
            $totalAlerts  = $expiredList->count();

            // 3. Frentes activos (modal de Recepción Directa) — oculta los no visibles
            //    (whitelist LOCAL + blacklist de bloqueados) para no recibir en frente prohibido.
            $frentesQuery = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
            \App\Models\Usuario::aplicarScopeIds($frentesQuery, $frentesVisibles, 'ID_FRENTE');
            \App\Models\Usuario::aplicarBloqueoIds($frentesQuery, $frentesBloqueados, 'ID_FRENTE');
            $frentes = $frentesQuery->get();

            // 4. Salud operacional — base query excluyendo DESINCORPORADO y frentes ESPECIAL.
            // Los 3 conteos (total / operativos / mantenimiento) se resuelven en UNA sola
            // consulta con agregación condicional, en vez de 3 count() separados (3 round-trips
            // → 1). Ayuda al rebuild frío del dashboard, sobre todo con BD remota en producción.
            $salud = Equipo::where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')
                ->excludeEspecial()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN ESTADO_OPERATIVO = 'OPERATIVO' THEN 1 ELSE 0 END) as operativos")
                // "MANTENIMIENTO" agrupa "MANTENIMIENTO" y "EN MANTENIMIENTO" (inconsistencia en BD).
                ->selectRaw("SUM(CASE WHEN ESTADO_OPERATIVO LIKE '%MANTENIMIENTO%' THEN 1 ELSE 0 END) as mantenimiento")
                ->first();

            $totalFlotaActiva     = (int) $salud->total;
            $equiposOperativos    = (int) $salud->operativos;
            $equiposMantenimiento = (int) $salud->mantenimiento;
            // Inoperativos = resto (INOPERATIVO + DESCONOCIDO + otros) para que los 3 chips sumen exacto
            $equiposInoperativos  = max(0, $totalFlotaActiva - $equiposOperativos - $equiposMantenimiento);

            // Eager-load SOLO las columnas necesarias del equipo (PK + FK ID_ESPEC + MARCA):
            // antes with('equipos') traía TODAS las columnas de TODOS los equipos de los 7
            // modelos, solo para saber si hay equipos (isEmpty) y sacar la MARCA del primero.
            // Con el select el payload cargado es mínimo (la lógica de abajo es idéntica).
            $catalogosDestacados = CaracteristicaModelo::with(['equipos' => fn ($q) => $q->select('ID_EQUIPO', 'ID_ESPEC', 'MARCA')])
                ->whereNotNull('FOTO_REFERENCIAL')
                ->orderBy('ID_ESPEC', 'desc')
                ->limit(7)
                ->get();

            // Garantizar que la MARCA se obtenga incluso si este ID_ESPEC no tiene equipos asignados directamente
            $marcasFallback = $this->marcasPorModelo($catalogosDestacados);
            foreach ($catalogosDestacados as $cat) {
                $cat->marca_calculada = $cat->equipos->isNotEmpty()
                    ? $cat->equipos->first()->MARCA
                    : ($marcasFallback[mb_strtoupper(trim((string) $cat->MODELO))] ?? null);
            }

            return compact(
                'pendientes', 'totalAlerts',
                'expiredList', 'frentes', 'totalFlotaActiva',
                'equiposOperativos', 'equiposInoperativos', 'equiposMantenimiento',
                'catalogosDestacados'
            );
        });

        // Sembrar window.equiposData para los equipos de las alertas, así el modal de
        // detalles abierto desde /menu muestra TODOS los campos (igual que en /admin/equipos)
        // y no solo el subconjunto de data-*. Fuente única: Equipo::toDetailsPayload().
        $data['equiposData'] = collect($data['expiredList'] ?? [])
            ->pluck('equipo')->filter()->unique('ID_EQUIPO')
            ->mapWithKeys(fn ($e) => [$e->ID_EQUIPO => $e->toDetailsPayload()]);

        return view('menu', $data);
    }

    /**
     * API mobile: devuelve los 3 catalogos destacados (modelos con FOTO_REFERENCIAL)
     * con la foto en base64 para que la APK los cachee en SQLite y los muestre
     * en el dashboard sin necesidad de internet posterior. Espejo del bloque
     * `@if(isset($catalogosDestacados))` de menu.blade.php pero limitado a 3.
     */
    public function mobileCatalogosDestacados()
    {
        $destacados = CaracteristicaModelo::with('equipos')
            ->whereNotNull('FOTO_REFERENCIAL')
            ->orderBy('ID_ESPEC', 'desc')
            ->limit(3)
            ->get();

        $payload = [];
        $marcasFallback = $this->marcasPorModelo($destacados);
        foreach ($destacados as $cat) {
            $marca = $cat->equipos->isNotEmpty()
                ? $cat->equipos->first()->MARCA
                : ($marcasFallback[mb_strtoupper(trim((string) $cat->MODELO))] ?? null);

            // Extraer el Drive file ID del FOTO_REFERENCIAL — mismo parsing
            // que hace menu.blade.php:897-899.
            $driveFileId = basename(str_replace('/storage/google/', '', explode('?', $cat->FOTO_REFERENCIAL)[0]));
            $fotoBase64 = $this->fetchDriveThumbBase64($driveFileId, 'w300');

            $payload[] = [
                'id_espec'    => $cat->ID_ESPEC,
                'modelo'      => $cat->MODELO,
                'marca'       => $marca,
                'anio'        => $cat->ANIO_ESPEC,
                'motor'       => $cat->MOTOR,
                'combustible' => $cat->COMBUSTIBLE,
                'foto_base64' => $fotoBase64, // null si no se pudo bajar
            ];
        }

        return response()->json($payload);
    }

    /**
     * MARCA de fallback por MODELO para los catálogos SIN equipos ligados
     * directamente, en UNA sola consulta whereIn (antes: un Equipo::where()
     * ->value() POR catálogo dentro del loop = N+1). Devuelve [MODELO => MARCA].
     * Usado por index() y mobileCatalogosDestacados().
     */
    private function marcasPorModelo($catalogos): array
    {
        $modelos = $catalogos->filter(fn ($c) => $c->equipos->isEmpty())
            ->pluck('MODELO')->filter()->unique()->values();
        if ($modelos->isEmpty()) {
            return [];
        }
        // Claves normalizadas (MAYÚSCULAS + trim): el WHERE de SQL compara con
        // collation case-insensitive pero el lookup del array PHP es byte a byte —
        // sin normalizar, un catálogo 'Cat 320D' no encontraría equipos 'CAT 320D'.
        return Equipo::whereIn('MODELO', $modelos)
            ->whereNotNull('MARCA')
            ->where('MARCA', '!=', '')
            ->pluck('MARCA', 'MODELO')
            ->mapWithKeys(fn ($marca, $modelo) => [mb_strtoupper(trim((string) $modelo)) => $marca])
            ->toArray();
    }

    /**
     * Reusa el cache local de GoogleDriveController::proxy para no re-bajar el
     * thumbnail. Si no esta cacheado, lo baja desde drive.google.com/thumbnail
     * (mismo endpoint publico que usa proxy()) y lo guarda. Devuelve null si
     * falla — el cliente mostrara un placeholder.
     */
    private function fetchDriveThumbBase64($driveFileId, $sz = 'w300')
    {
        if (!$driveFileId) return null;

        $cachePath = 'google_cache/thumb_' . preg_replace('/[^A-Za-z0-9_-]/', '', $sz) . '_' . $driveFileId;

        if (\Illuminate\Support\Facades\Storage::disk('local')->exists($cachePath)) {
            $bytes = \Illuminate\Support\Facades\Storage::disk('local')->get($cachePath);
        } else {
            $thumbUrl = 'https://drive.google.com/thumbnail?id=' . urlencode($driveFileId) . '&sz=' . urlencode($sz);
            $ctx = stream_context_create([
                'http' => ['timeout' => 8, 'follow_location' => 1],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $bytes = @file_get_contents($thumbUrl, false, $ctx);
            if ($bytes === false || strlen($bytes) < 100) return null;
            \Illuminate\Support\Facades\Storage::disk('local')->put($cachePath, $bytes);
        }

        return 'data:image/jpeg;base64,' . base64_encode($bytes);
    }

    public function resetCache()
    {
        try {
            // 1. Clear Application Cache
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
            \Illuminate\Support\Facades\Artisan::call('config:clear');
            \Illuminate\Support\Facades\Artisan::call('view:clear');

            // 2. Specific Google Drive Circuit Breaker (Redundant but safe)
            \Illuminate\Support\Facades\Cache::forget('google_drive_connection_error');

            return back()->with('success', 'Sistema reiniciado correctamente. Las conexiones han sido restablecidas.');
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Reset Cache Error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Error al reiniciar el sistema: ' . $e->getMessage()]);
        }
    }

    public function getAlertsHtml()
    {
        $user        = auth()->user();
        // frentesVisiblesEquiposIds(): null = ve todos (GLOBAL) | [] = local sin frentes | [ids].
        // Es justo lo que generateAlertsList() espera como filtro por frente. Los bloqueados
        // (lista negra) se restan a todos, también a GLOBAL.
        $expiredList = $this->generateAlertsList(
            $user ? $user->frentesVisiblesEquiposIds() : [],
            $user ? $user->getFrentesBloqueadosIds() : []
        );
        $totalAlerts = $expiredList->count();

        return response()->json([
            'html'        => view('partials.dashboard_alerts', compact('expiredList'))->render(),
            'totalAlerts' => $totalAlerts
        ]);
    }

    /**
     * Generate alerts list for expired and expiring documents.
     * Shared by index(), getAlertsHtml().
     *
     * @param array|null $frenteIds    When set, only returns alerts for equipment in those frentes (LOCAL users).
     * @param array      $bloqueados   Frentes a OCULTAR siempre (lista negra; aplica también a GLOBAL).
     */
    public function generateAlertsList(?array $frenteIds = null, array $bloqueados = [])
    {
        $now      = \Carbon\Carbon::now();
        $in30Days = $now->copy()->addDays(30);

        $query = Equipo::whereHas('documentacion', function ($q) use ($in30Days) {
            $q->where('FECHA_VENC_POLIZA', '<', $in30Days)
              ->orWhere('FECHA_ROTC', '<', $in30Days)
              ->orWhere('FECHA_RACDA', '<', $in30Days)
              ->orWhere('FECHA_ADICIONAL', '<', $in30Days); // Certificado (= documento adicional)
        })
        ->whereNotIn('ESTADO_OPERATIVO', ['DESINCORPORADO', 'INOPERATIVO'])
        // Relaciones del listado de alertas + las que necesita Equipo::toDetailsPayload()
        // (especificaciones, documentacion.seguro, cadena ancladoA) para sembrar
        // window.equiposData sin N+1, y withCount para el badge de auxiliares del modal.
        ->with([
            'documentacion.frenteGestionPoliza',
            'documentacion.frenteGestionRotc',
            'documentacion.frenteGestionRacda',
            'documentacion.frenteGestionAdicional',
            'documentacion.seguro',
            'tipo',
            'frenteActual',
            'especificaciones',
            'ancladoA.documentacion',
            'ancladoA.tipo',
            'ancladoA.especificaciones',
        ])
        ->withCount('equiposAuxiliares');

        // Excluir equipos en frentes TIPO_FRENTE=ESPECIAL (asignaciones especiales no son flota propia).
        $query->excludeEspecial();

        // Barrera por frente: lista blanca (null = ve todo / [] = local sin frentes →
        // nada / [ids] = whereIn) + lista negra de bloqueados (whereNotIn, también GLOBAL).
        \App\Models\Usuario::aplicarScopeIds($query, $frenteIds, 'ID_FRENTE_ACTUAL');
        \App\Models\Usuario::aplicarBloqueoIds($query, $bloqueados, 'ID_FRENTE_ACTUAL');

        $equipos = $query->get();

        $alerts = collect();

        foreach ($equipos as $equipo) {
            $doc = $equipo->documentacion;
            
            // Poliza
            if ($doc->FECHA_VENC_POLIZA) {
                $fechaPoliza = \Carbon\Carbon::parse($doc->FECHA_VENC_POLIZA);
                
                // Determine Status
                $status = $fechaPoliza->lt($now) ? 'expired' : ($fechaPoliza->lt($in30Days) ? 'warning' : 'valid');
                
                if ($status !== 'valid') {
                    $alerts->push((object)[
                        'equipo' => $equipo,
                        'type_key' => 'poliza',
                        'label' => $status === 'expired' ? 'Póliza Vencida' : 'Póliza Por Vencer',
                        'fecha' => $doc->FECHA_VENC_POLIZA,
                        'current_link' => $doc->LINK_POLIZA_SEGURO,
                        'status' => $status,
                        'gestionado_por' => $doc->frenteGestionPoliza ? $doc->frenteGestionPoliza->NOMBRE_FRENTE : null,
                        'fecha_gestion' => $doc->poliza_gestion_fecha
                    ]);
                }
            }

            // ROTC
            if ($doc->FECHA_ROTC) {
                $fechaRotc = \Carbon\Carbon::parse($doc->FECHA_ROTC);
                $status = $fechaRotc->lt($now) ? 'expired' : ($fechaRotc->lt($in30Days) ? 'warning' : 'valid');
                
                if ($status !== 'valid') {
                    $alerts->push((object)[
                        'equipo' => $equipo,
                        'type_key' => 'rotc',
                        'label' => $status === 'expired' ? 'ROTC Vencido' : 'ROTC Por Vencer',
                        'fecha' => $doc->FECHA_ROTC,
                        'current_link' => $doc->LINK_ROTC,
                        'status' => $status,
                        'gestionado_por' => $doc->frenteGestionRotc ? $doc->frenteGestionRotc->NOMBRE_FRENTE : null,
                        'fecha_gestion' => $doc->rotc_gestion_fecha
                    ]);
                }
            }

            // RACDA
            if ($doc->FECHA_RACDA) {
                $fechaRacda = \Carbon\Carbon::parse($doc->FECHA_RACDA);
                $status = $fechaRacda->lt($now) ? 'expired' : ($fechaRacda->lt($in30Days) ? 'warning' : 'valid');
                
                if ($status !== 'valid') {
                    $alerts->push((object)[
                        'equipo' => $equipo,
                        'type_key' => 'racda',
                        'label' => $status === 'expired' ? 'RACDA Vencido' : 'RACDA Por Vencer',
                        'fecha' => $doc->FECHA_RACDA,
                        'current_link' => $doc->LINK_RACDA,
                        'status' => $status,
                        'gestionado_por' => $doc->frenteGestionRacda ? $doc->frenteGestionRacda->NOMBRE_FRENTE : null,
                        'fecha_gestion' => $doc->racda_gestion_fecha
                    ]);
                }
            }

            // Certificado (= Documento Adicional en la BD, "Certificado Asociado" en la UI).
            // Mismo flujo y MISMOS botones que poliza/rotc/racda (es un documento del equipo).
            if ($doc->FECHA_ADICIONAL) {
                $fechaCert = \Carbon\Carbon::parse($doc->FECHA_ADICIONAL);
                $status = $fechaCert->lt($now) ? 'expired' : ($fechaCert->lt($in30Days) ? 'warning' : 'valid');

                if ($status !== 'valid') {
                    $alerts->push((object)[
                        'equipo' => $equipo,
                        'type_key' => 'adicional',
                        'label' => $status === 'expired' ? 'Certificado Vencido' : 'Certificado Por Vencer',
                        'fecha' => $doc->FECHA_ADICIONAL,
                        'current_link' => $doc->LINK_DOC_ADICIONAL,
                        'status' => $status,
                        'gestionado_por' => $doc->frenteGestionAdicional ? $doc->frenteGestionAdicional->NOMBRE_FRENTE : null,
                        'fecha_gestion' => $doc->adicional_gestion_fecha
                    ]);
                }
            }
        }

        // ── Filtro por PERMISOS (visibilidad POR USUARIO) ────────────────────────────
        // Qué tipos de documento ve cada usuario en el panel de alertas se controla con
        // claves alertas.ver.* en usuarios.PERMISOS (asignables desde el editor de usuarios,
        // SIN tocar código ni redeploy). Regla: si el usuario tiene AL MENOS una clave
        // alertas.ver.*, ve SOLO esos tipos; si no tiene ninguna, ve TODOS (default).
        // Se lee el LITERAL en PERMISOS (no vía can()) para que super.admin NO quede
        // auto-restringido por su grant — un super.admin sin claves alertas.ver.* ve todo.
        $mapaPermisos = [
            'alertas.ver.poliza'      => 'poliza',
            'alertas.ver.rotc'        => 'rotc',
            'alertas.ver.racda'       => 'racda',
            'alertas.ver.certificado' => 'adicional', // "certificado" = documento adicional
        ];
        $raw = optional(auth()->user())->PERMISOS;
        $permisosUser = is_array($raw) ? $raw : (is_string($raw) ? explode(',', $raw) : []);
        $permisosUser = array_map('strtolower', array_map('trim', array_filter($permisosUser, 'is_string')));
        $tiposPermitidos = [];
        foreach ($mapaPermisos as $clave => $tipo) {
            if (in_array($clave, $permisosUser, true)) { $tiposPermitidos[] = $tipo; }
        }
        if (!empty($tiposPermitidos)) {
            $alerts = $alerts->whereIn('type_key', $tiposPermitidos)->values();
        }

        // Separate expired from warnings
        $expired = $alerts->where('status', 'expired')->sortBy('fecha')->values();
        $warnings = $alerts->where('status', 'warning')->sortBy('fecha')->values();

        // Combine: expired first (los más vencidos arriba), then warnings (próximos a vencer)
        return $expired->concat($warnings)->values();
    }

    /**
     * Start management of a document
     */
    public function iniciarGestion(Request $request)
    {
        $request->validate([
            'equipo_id' => 'required|exists:equipos,ID_EQUIPO',
            'doc_type'  => 'required|in:poliza,rotc,racda,adicional'
        ]);

        $user = auth()->user();

        // Permiso requerido: editar equipos o super.admin
        if (!$user->can('equipos.edit')) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para realizar esta acción.'
            ], 403);
        }

        $frentes = $user->getFrentesIds();
        if (empty($frentes)) {
            return response()->json([
                'success' => false,
                'message' => 'Debes pertenecer a un frente de trabajo para iniciar gestión.'
            ], 403);
        }

        $doc = \App\Models\Documentacion::where('ID_EQUIPO', $request->equipo_id)->first();
        if (!$doc) {
            return response()->json(['success' => false, 'message' => 'Documentación no encontrada.'], 404);
        }

        $frenteField = $request->doc_type . '_gestion_frente_id';
        $fechaField  = $request->doc_type . '_gestion_fecha';

        $doc->$frenteField = $frentes[0];
        $doc->$fechaField  = now();
        // El save dispara DocumentacionObserver::updated → bumpDataVersion():
        // invalida el dashboard cacheado de TODOS los usuarios, no solo este.
        $doc->save();

        return response()->json(['success' => true, 'message' => 'Gestión iniciada correctamente.']);
    }
    
    /**
     * Generate PDF Report of Expired & Expiring Documents
     */
    public function exportDocumentsPDF()
    {
        try {
            // Get current user info
            $user = auth()->user();
            $nombreUsuario = $user->NOMBRE_COMPLETO ?? 'Sistema';
            $nombreFrente = $user->frenteAsignado ? $user->frenteAsignado->NOMBRE_FRENTE : 'Sin Frente Asignado';
            $fechaEmision = \Carbon\Carbon::now()->locale('es')->isoFormat('DD [de] MMMM [de] YYYY - HH:mm');

            // Get alerts list — ACOTADA a los frentes visibles del usuario y restando su lista
            // negra, igual que index() y getAlertsHtml(). Sin estos argumentos, generateAlertsList()
            // usaba su default ($frenteIds=null = ve TODOS los frentes), así que un usuario LOCAL
            // exportaba el PDF con los documentos de frentes que no le corresponden.
            $alertsList = $this->generateAlertsList(
                $user ? $user->frentesVisiblesEquiposIds() : [],
                $user ? $user->getFrentesBloqueadosIds() : []
            );
            
            // Separar por estado y ordenar cada tabla AGRUPANDO por frente: todas las filas
            // de un mismo frente quedan juntas (p. ej. primero todo PATIO MATURIN, luego el
            // siguiente) y, dentro de cada frente, por tipo de equipo. Los equipos sin frente
            // van al final ('ZZZ'). Pedido del cliente: leer el reporte por proyecto/frente.
            $ordenFrenteTipo = function ($alert) {
                $frente = optional(optional($alert->equipo)->frenteActual)->NOMBRE_FRENTE ?? 'ZZZ';
                $tipo   = optional(optional($alert->equipo)->tipo)->nombre ?? '';
                return mb_strtoupper($frente . '|' . $tipo);
            };

            $vencidos = $alertsList->filter(function($alert) {
                return $alert->status === 'expired';
            })->sortBy($ordenFrenteTipo)->values();

            $proximos = $alertsList->filter(function($alert) {
                return $alert->status === 'warning';
            })->sortBy($ordenFrenteTipo)->values();

            // Calculate totals
            $totalVencidos = $vencidos->count();
            $totalProximos = $proximos->count();
            
            // Get unique equipment count
            $totalEquipos = $alertsList->pluck('equipo.ID_EQUIPO')->unique()->count();

            // MANUAL LOADING OF TCPDF (Emergency Mode)
            // If the package is physically present but not autoloaded yet
            if (!class_exists('TCPDF')) {
                $tcpdfPath = base_path('vendor/tecnickcom/tcpdf/tcpdf.php');
                if (file_exists($tcpdfPath)) {
                    require_once($tcpdfPath);
                }
            }

            // Render View to HTML
            $html = view('reports.documentos_vencidos_pdf', compact(
                'vencidos',
                'proximos',
                'nombreUsuario',
                'nombreFrente',
                'fechaEmision',
                'totalVencidos',
                'totalProximos',
                'totalEquipos'
            ))->render();

            if (class_exists('TCPDF')) {
                $pdf = new ReportePDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
                
                // Set document information
                $pdf->SetCreator('Sistema de Gestión');
                $pdf->SetAuthor($nombreUsuario);
                $pdf->SetTitle('Reporte de Documentos Vencidos');

                // Configuración de Márgenes (2cm)
                // Configuración de Márgenes
                $pdf->setPrintHeader(true); 
                $pdf->setPrintFooter(true); 
                $pdf->SetMargins(25, 40, 25); // Margen superior 4cm para bajar el contenido y no chocar con el header grande
                $pdf->SetHeaderMargin(10); // Header a 1cm del borde
                $pdf->SetAutoPageBreak(TRUE, 25);
                
                // Add a page
                $pdf->AddPage();
                
                // Write HTML
                $pdf->writeHTML($html, true, false, true, false, '');
                
                // Download
                $filename = 'Reporte_Documentos_' . \Carbon\Carbon::now()->format('Y-m-d_His') . '.pdf';
                
                return response($pdf->Output($filename, 'S')) // S = Return as string
                    ->header('Content-Type', 'application/pdf')
                    ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
            } else {
                 throw new \Exception('La librería TCPDF no se encuentra instalada correctamente.');
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('PDF Export Error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Error al generar PDF: ' . $e->getMessage()]);
        }
    }
}

// Clase para personalizar el pie de página del PDF
if (class_exists('TCPDF') && !class_exists('ReportePDF')) {
    class ReportePDF extends \TCPDF {
        public function Header() {
            // Imagen a 1cm (10mm) del borde superior y 2.5cm (25mm) del izquierdo
            $image_file = public_path('img/imagen_uno.jpg');
            // Image(file, x, y, w, h) -> h=25mm (Más grande)
            if (file_exists($image_file)) {
                $this->Image($image_file, 25, 10, 0, 25, 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);
            }

            // Texto a la derecha, alineado con la base de la foto (Y=27 aprox para base en 35)
            $this->SetFont('helvetica', '', 8.5);
            $html = '<div style="text-align: right;"><strong>FECHA DE EMISIÓN:</strong> ' . \Carbon\Carbon::now()->format('d/m/Y') . '<br>EMITIDO POR SISTEMA DE GESTIÓN DE FLOTA</div>';
            
            // Renderizar HTML Cell
            $this->writeHTMLCell(0, 0, 25, 27, $html, 0, 1, 0, true, 'R', true);
        }

        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica', '', 8.5); // Sin cursiva y tamaño 8.5
            $this->Cell(0, 10, 'Página '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(), 0, false, 'R', 0, '', 0, false, 'T', 'M');
        }
    }
}
