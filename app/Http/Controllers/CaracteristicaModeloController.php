<?php

namespace App\Http\Controllers;

use App\Models\CaracteristicaModelo;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\TipoEquipo;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CaracteristicaModeloController extends Controller
{
    use \App\Traits\ConvertsImageToWebp;

    /** Campos string que se normalizan a MAYÚSCULAS antes de persistir. */
    private const UPPERCASE_FIELDS = [
        'MODELO', 'TIPO', 'MOTOR', 'COMBUSTIBLE', 'CONSUMO_PROMEDIO',
        'ACEITE_MOTOR', 'ACEITE_CAJA', 'LIGA_FRENO', 'REFRIGERANTE', 'TIPO_BATERIA',
    ];

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('can:equipos.create')->only(['store', 'update', 'uploadFoto']);
        $this->middleware('can:super.admin')->only(['destroy', 'deleteFoto']);
    }

    /** Reglas de validación compartidas por store y update. */
    private function validationRules(): array
    {
        return [
            'MODELO'             => 'required|max:50',
            'TIPO'               => 'nullable|max:35',
            'ANIO_ESPEC'         => 'required|integer',
            'MOTOR'              => 'nullable|max:150',
            'COMBUSTIBLE'        => 'nullable|max:100',
            'CONSUMO_PROMEDIO'   => 'nullable|max:50',
            'ACEITE_MOTOR'       => 'nullable|max:100',
            'ACEITE_CAJA'        => 'nullable|max:100',
            'LIGA_FRENO'         => 'nullable|max:50',
            'REFRIGERANTE'       => 'nullable|max:100',
            'TIPO_BATERIA'       => 'nullable|max:100',
        ];
    }

    /** Normaliza a MAYÚSCULAS los campos definidos en UPPERCASE_FIELDS. */
    private function applyUppercaseFields(array &$validated): void
    {
        foreach (self::UPPERCASE_FIELDS as $field) {
            if (isset($validated[$field]) && is_string($validated[$field])) {
                $validated[$field] = strtoupper($validated[$field]);
            }
        }
    }

    /**
     * Catálogo UNIFICADO: muestra VEHÍCULOS (caracteristicas_modelo) y AUXILIARES
     * (equipos_auxiliares agrupados) en una sola grilla con el mismo estilo de tarjeta.
     * Los filtros Tipo y Modelo van agrupados VEHÍCULOS/AUXILIARES (igual que el
     * tipo_activo de /admin/fallas): valores tipo_eq:{id} / tipo_aux:{TIPO} y
     * modelo_eq:{modelo} / modelo_aux:{modelo}. El filtro Año aplica a ambos.
     */
    public function index(Request $request)
    {
        $tipoFiltro   = (string) $request->input('tipo', '');     // '' | tipo_eq:{id} | tipo_aux:{TIPO}
        $modeloFiltro = (string) $request->input('modelo', '');   // '' | modelo_eq:{m} | modelo_aux:{m}
        $anio         = (string) $request->input('anio', '');

        // Qué clases mostrar según el filtro Tipo/Modelo (si apunta a una clase, solo esa).
        $verVehiculos  = ($tipoFiltro === '' || str_starts_with($tipoFiltro, 'tipo_eq:'))
                       && !str_starts_with($modeloFiltro, 'modelo_aux:');
        $verAuxiliares = ($tipoFiltro === '' || str_starts_with($tipoFiltro, 'tipo_aux:'))
                       && !str_starts_with($modeloFiltro, 'modelo_eq:');

        $items = $this->buildCatalogoItems($verVehiculos, $verAuxiliares, $tipoFiltro, $modeloFiltro, $anio);

        $totalCount       = $items->count();
        $countVehiculos   = $items->where('clase', 'VEHICULO')->count();
        $countAuxiliares  = $items->where('clase', 'AUXILIAR')->count();

        // Paginación manual en bloques de 12 (el cliente los carga por scroll infinito).
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = 24; // lotes más grandes → la mitad de peticiones al hacer scroll infinito
        $catalogos = new LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $totalCount, $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // ── Listas para los filtros agrupados (todas las opciones, no auto-limitadas) ──
        $tiposVehiculo = TipoEquipo::whereIn('nombre', function ($q) {
                $q->select('TIPO')->from('caracteristicas_modelo')->whereNotNull('TIPO');
            })->orderBy('nombre')->get(['id', 'nombre']);
        $tiposAux = $this->tiposAuxLabels();
        $modelosVehiculo = CaracteristicaModelo::whereNotNull('MODELO')->where('MODELO', '!=', '')
            ->distinct()->orderBy('MODELO')->pluck('MODELO');
        $modelosAux = EquipoAuxiliar::whereNotNull('MODELO')->where('MODELO', '!=', '')
            ->distinct()->orderBy('MODELO')->pluck('MODELO');
        $aniosVehiculo = CaracteristicaModelo::whereNotNull('ANIO_ESPEC')->distinct()->pluck('ANIO_ESPEC');
        $aniosAux      = EquipoAuxiliar::whereNotNull('ANIO')->where('ANIO', '!=', 0)->distinct()->pluck('ANIO');
        $availableAnios = $aniosVehiculo->merge($aniosAux)->unique()->sortDesc()->values();

        if ($request->wantsJson() && $request->has('ajax_load')) {
            return response()->json([
                'html'    => view('admin.catalogo.partials.table_rows', compact('catalogos'))->render(),
                'hasMore' => $catalogos->hasMorePages(),
                'page'    => $catalogos->currentPage(),
            ]);
        }

        return view('admin.catalogo.index', compact(
            'catalogos', 'totalCount', 'countVehiculos', 'countAuxiliares',
            'tiposVehiculo', 'tiposAux', 'modelosVehiculo', 'modelosAux', 'availableAnios'
        ));
    }

    /**
     * Construye la colección unificada de items (arrays normalizados) del catálogo.
     * Cada item: clase, id, tipo, modelo, marca, anio, foto_url, placeholder, total, specs.
     */
    private function buildCatalogoItems(bool $verVehiculos, bool $verAuxiliares, string $tipoFiltro, string $modeloFiltro, string $anio)
    {
        $items = collect();

        // ── VEHÍCULOS (caracteristicas_modelo) ──
        if ($verVehiculos) {
            $q = CaracteristicaModelo::query();
            if (str_starts_with($tipoFiltro, 'tipo_eq:')) {
                $nombre = TipoEquipo::where('id', (int) substr($tipoFiltro, 8))->value('nombre');
                $q->where('TIPO', $nombre ? strtoupper(trim($nombre)) : '__none__');
            }
            if (str_starts_with($modeloFiltro, 'modelo_eq:')) {
                $q->where('MODELO', substr($modeloFiltro, 10));
            }
            if ($anio !== '' && $anio !== 'all') {
                $q->where('ANIO_ESPEC', $anio);
            }
            foreach ($q->orderBy('MODELO')->get() as $cm) {
                $driveId = $cm->FOTO_REFERENCIAL
                    ? basename(str_replace('/storage/google/', '', explode('?', $cm->FOTO_REFERENCIAL)[0]))
                    : null;
                $items->push([
                    'clase'       => 'VEHICULO',
                    'id'          => $cm->ID_ESPEC,
                    'tipo'        => $cm->TIPO,
                    'modelo'      => $cm->MODELO,
                    'marca'       => null,
                    'anio'        => $cm->ANIO_ESPEC,
                    'foto_url'    => $driveId ? url('/storage/google/' . $driveId . '?sz=w300') : null,
                    'placeholder' => 'precision_manufacturing',
                    'total'       => null,
                    'specs'       => array_filter([
                        'Motor'        => $cm->MOTOR,
                        'Combustible'  => $cm->COMBUSTIBLE,
                        'Consumo'      => $cm->CONSUMO_PROMEDIO ? $cm->CONSUMO_PROMEDIO . ' L/día' : null,
                        'Batería'      => $cm->TIPO_BATERIA,
                        'Aceite Motor' => $cm->ACEITE_MOTOR,
                        'Aceite Caja'  => $cm->ACEITE_CAJA,
                        'Liga Freno'   => $cm->LIGA_FRENO,
                        'Refrigerante' => $cm->REFRIGERANTE,
                    ], fn ($v) => $v !== null && $v !== ''),
                    'sort'        => '0_' . $cm->MODELO,
                ]);
            }
        }

        // ── AUXILIARES (equipos_auxiliares agrupados por TIPO+MARCA+MODELO+AÑO) ──
        if ($verAuxiliares) {
            $base = EquipoAuxiliar::query();
            if ($user = auth()->user()) {
                $user->aplicarScopeFrentesEquipos($base, 'ID_FRENTE_ACTUAL');
            }
            if (str_starts_with($tipoFiltro, 'tipo_aux:')) {
                $base->where('TIPO', substr($tipoFiltro, 9));
            }
            if (str_starts_with($modeloFiltro, 'modelo_aux:')) {
                $base->where('MODELO', substr($modeloFiltro, 11));
            }
            if ($anio !== '' && $anio !== 'all') {
                $base->where('ANIO', $anio);
            }

            $grupos = (clone $base)
                ->selectRaw("
                    TIPO,
                    COALESCE(NULLIF(TRIM(MARCA), ''), '—') as MARCA_KEY,
                    COALESCE(NULLIF(TRIM(MODELO), ''), '—') as MODELO_KEY,
                    COALESCE(ANIO, 0) as ANIO_KEY,
                    COUNT(*) as total
                ")
                ->groupBy('TIPO', 'MARCA_KEY', 'MODELO_KEY', 'ANIO_KEY')
                ->orderBy('TIPO')->orderBy('MARCA_KEY')->orderBy('MODELO_KEY')->orderBy('ANIO_KEY', 'desc')
                ->get();

            $fotos = (clone $base)
                ->whereNotNull('FOTO')->where('FOTO', '!=', '')
                ->selectRaw('TIPO, MARCA, MODELO, ANIO, FOTO, CAPACIDAD, ID_AUXILIAR')
                ->orderByDesc('ID_AUXILIAR')->get()
                ->reduce(function ($carry, $a) {
                    $key = mb_strtoupper(trim(($a->TIPO ?? '') . '|' . ($a->MARCA ?? '—') . '|' . ($a->MODELO ?? '—') . '|' . ($a->ANIO ?? 0)));
                    if (!isset($carry[$key])) $carry[$key] = ['foto' => $a->FOTO, 'capacidad' => $a->CAPACIDAD];
                    return $carry;
                }, []);

            $tiposMap = $this->tiposAuxLabels();

            foreach ($grupos as $g) {
                $key  = mb_strtoupper(trim(($g->TIPO ?? '') . '|' . ($g->MARCA_KEY ?? '—') . '|' . ($g->MODELO_KEY ?? '—') . '|' . ($g->ANIO_KEY ?? 0)));
                $info = $fotos[$key] ?? ['foto' => null, 'capacidad' => null];
                $foto = $info['foto'] ?? null;
                if ($foto && !str_starts_with($foto, 'http') && !str_starts_with($foto, '/')) {
                    $foto = '/storage/' . ltrim($foto, '/');
                }
                $items->push([
                    'clase'       => 'AUXILIAR',
                    'id'          => null,
                    'tipo'        => $tiposMap[$g->TIPO] ?? $g->TIPO,
                    'tipo_raw'    => $g->TIPO,
                    'modelo'      => $g->MODELO_KEY,
                    'marca'       => $g->MARCA_KEY !== '—' ? $g->MARCA_KEY : null,
                    'anio'        => $g->ANIO_KEY ? (int) $g->ANIO_KEY : null,
                    'foto_url'    => $foto,
                    'placeholder' => 'construction',
                    'total'       => (int) $g->total,
                    'specs'       => array_filter([
                        'Marca'     => $g->MARCA_KEY !== '—' ? $g->MARCA_KEY : null,
                        'Capacidad' => $info['capacidad'],
                    ], fn ($v) => $v !== null && $v !== ''),
                    'sort'        => '1_' . $g->TIPO . '_' . $g->MODELO_KEY,
                ]);
            }
        }

        // VEHÍCULOS primero, luego AUXILIARES; dentro de cada clase, por modelo.
        return $items->sortBy('sort')->values();
    }

    /** Mapa TIPO => etiqueta legible de auxiliares (mismo criterio que el módulo aux). */
    private function tiposAuxLabels(): array
    {
        $tipos = EquipoAuxiliar::tiposLabel();
        foreach (EquipoAuxiliar::whereNotNull('TIPO')->where('TIPO', '!=', '')->distinct()->orderBy('TIPO')->pluck('TIPO') as $t) {
            if (!isset($tipos[$t])) {
                $tipos[$t] = ucwords(mb_strtolower(str_replace('_', ' ', $t)));
            }
        }
        return $tipos;
    }

    public function create()
    {
        $catalogo = new CaracteristicaModelo(); // Empty object for create mode
        
        // Optimización: Cache de Modelos (lista pesada) x 10 min
        $modelosList = \Illuminate\Support\Facades\Cache::remember('equipos_modelos_list', 600, function () {
            return Equipo::select('MODELO')
                ->distinct()
                ->whereNotNull('MODELO')
                ->where('MODELO', '!=', '')
                ->orderBy('MODELO')
                ->pluck('MODELO');
        });

        // NOTA: La lista de años ($aniosList) ya no se carga aquí.
        // Se cargará dinámicamente vía AJAX según el modelo seleccionado para mayor velocidad y precisión.
        $aniosList = [];

        // Tipos de equipo para el autocompletado del campo TIPO del catálogo.
        $tipos = \App\Models\TipoEquipo::orderBy('nombre')->pluck('nombre');

        return view('admin.catalogo.create', compact('catalogo', 'modelosList', 'aniosList', 'tipos'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate(
            $this->validationRules(),
            $this->validationMessages(),
            $this->validationAttributes()
        );

        try {
            $catalogo = null;

            // La foto NO se sube desde este formulario (se gestiona con click en la tarjeta →
            // uploadFoto). Aquí solo se crean los datos del modelo.
            DB::transaction(function () use (&$validated, &$catalogo) {
                $this->applyUppercaseFields($validated);
                $catalogo = CaracteristicaModelo::create($validated);
            });

            // AUTO-LINK INTELIGENTE: vincular equipos sin catálogo, huérfanos, o con catálogo viejo sin foto
            if ($catalogo) {
                $this->autoLinkEquiposToCatalogo($catalogo, $validated['MODELO'], $validated['ANIO_ESPEC'], 'create');

                // Auditoria: registro de creacion (snapshot de campos relevantes)
                \App\Models\CatalogoAuditLog::registrar(
                    $catalogo->ID_ESPEC,
                    'create',
                    $catalogo->MODELO,
                    $catalogo->ANIO_ESPEC !== null ? (int) $catalogo->ANIO_ESPEC : null,
                    $validated
                );
            }

            if ($request->wantsJson()) {
                // El JS usa sessionStorage + navigateTo SPA para mostrar el toast
                // en la pagina destino sin parpadeo. Ver public/js/maquinaria/catalogo_create.js
                // y el hook global en resources/views/layouts/estructura_base.blade.php.
                return response()->json([
                    'success'  => true,
                    'message'  => 'Modelo registrado correctamente en el catálogo.',
                    'redirect' => route('catalogo.index'),
                ], 200);
            }

            return redirect()->route('catalogo.index')->with('success', 'Modelo registrado correctamente.');
        } catch (\Exception $e) {
            Log::error('Error registrando modelo en catálogo: ' . $e->getMessage());
            
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error al registrar el modelo: ' . $e->getMessage()
                ], 500);
            }

            return back()->withInput()->with('error', 'Error al registrar el modelo: ' . $e->getMessage());
        }
    }

    public function edit($id)
    {
        $catalogo = CaracteristicaModelo::findOrFail($id);

        // Listas para los autocompletados del formulario (TIPO/MODELO/AÑO).
        $tipos = \App\Models\TipoEquipo::orderBy('nombre')->pluck('nombre');
        $modelosList = \Illuminate\Support\Facades\Cache::get('equipos_modelos_list', collect());
        $aniosList = [];

        return view('admin.catalogo.edit', compact('catalogo', 'tipos', 'modelosList', 'aniosList'));
    }

    public function update(Request $request, $id)
    {
        $catalogo = CaracteristicaModelo::findOrFail($id);

        $validated = $request->validate(
            $this->validationRules(),
            $this->validationMessages(),
            $this->validationAttributes()
        );

        try {
            $oldModelo = $catalogo->MODELO;
            $oldAnio = $catalogo->ANIO_ESPEC;
            // Snapshot original para diff de auditoria (solo campos auditables)
            $auditFields = ['MODELO','ANIO_ESPEC','MOTOR','COMBUSTIBLE','CONSUMO_PROMEDIO',
                            'ACEITE_MOTOR','ACEITE_CAJA','LIGA_FRENO','REFRIGERANTE','TIPO_BATERIA'];
            $originalSnapshot = collect($auditFields)->mapWithKeys(fn($f) => [$f => $catalogo->{$f} ?? null])->toArray();

            // La foto NO se actualiza desde este formulario (se gestiona con click en la
            // tarjeta → uploadFoto). Aquí solo se actualizan los datos del modelo.
            DB::transaction(function () use (&$validated, $catalogo) {
                $this->applyUppercaseFields($validated);
                $catalogo->update($validated);
            });

            // AUTO-UNLINK: Si el modelo o el año cambió, desvincular los equipos que ya no coinciden
            // para evitar que un equipo se quede con la foto/especificaciones de un modelo diferente.
            $modeloChanged = ($oldModelo !== $validated['MODELO']);
            $anioChanged = ($oldAnio !== $validated['ANIO_ESPEC']);
            
            if ($modeloChanged || $anioChanged) {
                $equiposToUnlink = \App\Models\Equipo::where('ID_ESPEC', $catalogo->ID_ESPEC)
                    ->where(function($q) use ($validated) {
                        $q->where('MODELO', '!=', $validated['MODELO'])
                          ->orWhere('ANIO', '!=', $validated['ANIO_ESPEC']);
                    })->get();
                    
                foreach ($equiposToUnlink as $eq) {
                    $eq->ID_ESPEC = null;
                    $eq->save(); // Dispara Observer (Auditoría + Caché)
                }
            }

            // AUTO-LINK INTELIGENTE: Evaluar siempre (por si se subió una foto nueva o cambió modelo/año)
            $this->autoLinkEquiposToCatalogo($catalogo, $validated['MODELO'], $validated['ANIO_ESPEC'], 'update');

            // Auditoria: diff de campos editados (solo los que realmente cambiaron)
            $diff = [];
            foreach ($auditFields as $f) {
                $before = $originalSnapshot[$f] ?? null;
                $after  = $validated[$f] ?? null;
                if ((string)$before !== (string)$after) {
                    $diff[$f] = ['antes' => $before, 'despues' => $after];
                }
            }
            if (!empty($diff)) {
                \App\Models\CatalogoAuditLog::registrar(
                    $catalogo->ID_ESPEC,
                    'edit',
                    $catalogo->MODELO,
                    $catalogo->ANIO_ESPEC !== null ? (int) $catalogo->ANIO_ESPEC : null,
                    $diff
                );
            }

            if ($request->wantsJson()) {
                // El toast sale en destino via sessionStorage (ver catalogo_create.js).
                return response()->json(['message' => 'Modelo actualizado exitosamente', 'redirect' => route('catalogo.index')]);
            }

            return redirect()->route('catalogo.index')->with('success', 'Modelo actualizado exitosamente');
        } catch (\Exception $e) {
            Log::error('Error actualizando modelo en catálogo: ' . $e->getMessage());
            
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Error al actualizar el modelo: ' . $e->getMessage()], 500);
            }

            return back()->withInput()->with('error', 'Error al actualizar el modelo: ' . $e->getMessage());
        }
    }

    /**
     * Punto ÚNICO de la lógica de foto del catálogo (store / update / uploadFoto):
     * sube el WebP a Drive, apunta FOTO_REFERENCIAL a la nueva, y SOLO entonces
     * borra la anterior de Drive + caché (best-effort). Devuelve false si Drive
     * falla — el caller decide loguear (store/update) o abortar (uploadFoto).
     * Debe llamarse DENTRO de la transacción que persiste el catálogo.
     *
     * @param  string|null $oldFileId  File-id de Drive de la foto previa a borrar (null = no borrar).
     */
    private function reemplazarFotoCatalogo(CaracteristicaModelo $catalogo, $webpFile, ?string $oldFileId = null): bool
    {
        $driveService = GoogleDriveService::getInstance();
        $folderId = config('filesystems.disks.google.catalog_folder');
        $filename = 'catalog_' . (int)(microtime(true) * 1000) . '_' . $catalogo->ID_ESPEC . '.webp';

        $driveFile = $driveService->uploadFile($folderId, $webpFile, $filename, 'image/webp');
        if (!$driveFile || !isset($driveFile->id)) {
            return false;
        }

        // Apuntar a la foto nueva ANTES de borrar la vieja.
        $catalogo->update(['FOTO_REFERENCIAL' => '/storage/google/' . $driveFile->id]);

        // Borrar la anterior solo tras subir + persistir la nueva (best-effort).
        if ($oldFileId && $oldFileId !== $driveFile->id) {
            try {
                $driveService->deleteFile($oldFileId);
                \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $oldFileId);
                \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $oldFileId);
            } catch (\Exception $e) {
                Log::warning('No se pudo borrar la foto anterior de Drive: ' . $oldFileId . ' - ' . $e->getMessage());
            }
        }

        return true;
    }

    /**
     * Subida de foto SOLO (sin abrir el formulario de edición). Se dispara al hacer
     * click en la foto de cada tarjeta en /admin/catalogo — misma UX que el catálogo
     * de auxiliares. Reusa reemplazarFotoCatalogo() y re-evalúa el auto-link para que
     * los equipos del modelo hereden la nueva imagen.
     */
    public function uploadFoto(Request $request, $id)
    {
        @set_time_limit(120);

        $request->validate(
            ['foto' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120'],
            [
                'foto.required' => 'Selecciona una imagen.',
                'foto.image'    => 'El archivo debe ser una imagen.',
                'foto.mimes'    => 'Formatos permitidos: JPG, PNG o WEBP.',
                'foto.max'      => 'La imagen no debe superar los 5 MB.',
            ]
        );

        $catalogo = CaracteristicaModelo::findOrFail($id);

        try {
            // Convertir a WebP ANTES de la transacción (evita problemas de $this en closures).
            $webpResult   = $this->convertToWebp($request->file('foto'));
            $webpFile     = $webpResult['file'];
            $tempWebpPath = $webpResult['tempPath'];
            $oldFileId    = $catalogo->FOTO_REFERENCIAL
                ? str_replace('/storage/google/', '', explode('?', $catalogo->FOTO_REFERENCIAL)[0])
                : null;

            DB::transaction(function () use ($catalogo, $webpFile, $oldFileId) {
                if (!$this->reemplazarFotoCatalogo($catalogo, $webpFile, $oldFileId)) {
                    throw new \RuntimeException('La subida a Google Drive falló.');
                }
            });
            $nuevaUrl = $catalogo->FOTO_REFERENCIAL; // ya apuntada a la nueva por el helper

            // Limpiar archivo temporal WebP del servidor si se creó.
            if ($tempWebpPath && file_exists($tempWebpPath)) {
                @unlink($tempWebpPath);
            }

            // Re-evaluar auto-link: equipos del modelo sin foto heredan la nueva imagen.
            $this->autoLinkEquiposToCatalogo($catalogo, $catalogo->MODELO, $catalogo->ANIO_ESPEC, 'update');

            \App\Models\CatalogoAuditLog::registrar(
                $catalogo->ID_ESPEC,
                'upload_foto',
                $catalogo->MODELO,
                $catalogo->ANIO_ESPEC !== null ? (int) $catalogo->ANIO_ESPEC : null,
                ['foto' => ['antes' => $oldFileId ? '/storage/google/' . $oldFileId : null, 'despues' => $nuevaUrl]]
            );

            return response()->json([
                'success' => true,
                'message' => 'Foto del modelo actualizada correctamente.',
                'foto'    => $nuevaUrl,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error subiendo foto de catálogo ID ' . $id . ': ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar la foto: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteFoto(Request $request, $id)
    {
        $catalogo = CaracteristicaModelo::findOrFail($id);

        if (!$catalogo->FOTO_REFERENCIAL) {
            return response()->json(['success' => false, 'message' => 'Este modelo no tiene foto.'], 422);
        }

        $fileId = str_replace('/storage/google/', '', explode('?', $catalogo->FOTO_REFERENCIAL)[0]);

        try {
            DB::transaction(function () use ($catalogo) {
                $catalogo->update(['FOTO_REFERENCIAL' => null]);
            });

            \App\Models\CatalogoAuditLog::registrar(
                $catalogo->ID_ESPEC,
                'delete_foto',
                $catalogo->MODELO,
                $catalogo->ANIO_ESPEC !== null ? (int) $catalogo->ANIO_ESPEC : null,
                ['foto' => ['antes' => '/storage/google/' . $fileId, 'despues' => null]]
            );

            // Drive + caché: diferido para que la respuesta sea inmediata.
            if ($fileId) {
                defer(function () use ($fileId) {
                    try {
                        GoogleDriveService::getInstance()->deleteFile($fileId);
                        \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $fileId);
                        \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $fileId);
                    } catch (\Exception $e) {
                        Log::warning("Drive delete failed for catalog photo: {$fileId} - " . $e->getMessage());
                    }
                });
            }

            return response()->json(['success' => true, 'message' => 'Foto eliminada correctamente.']);
        } catch (\Throwable $e) {
            Log::error('Error eliminando foto de catálogo ID ' . $id . ': ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo eliminar la foto.'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        $catalogo = CaracteristicaModelo::findOrFail($id);

        // Snapshot antes de tocar BD (auditoría + Drive)
        $snapshotModelo = $catalogo->MODELO;
        $snapshotAnio   = (int) $catalogo->ANIO_ESPEC;
        $snapshotId     = $catalogo->ID_ESPEC;
        $fileId = $catalogo->FOTO_REFERENCIAL
            ? str_replace('/storage/google/', '', $catalogo->FOTO_REFERENCIAL)
            : null;

        try {
            // 1) Transacción atómica en BD: desvincular equipos + borrar catálogo
            DB::transaction(function () use ($snapshotId, $catalogo) {
                $equiposAsociados = \App\Models\Equipo::where('ID_ESPEC', $snapshotId)->get();
                foreach ($equiposAsociados as $eq) {
                    $eq->ID_ESPEC = null;
                    $eq->save(); // dispara EquipoObserver (auditoría + caché)
                }
                $catalogo->delete();
            });

            // 2) Solo si la transacción BD fue exitosa: borrar Drive (operación irreversible)
            if ($fileId) {
                try {
                    GoogleDriveService::getInstance()->deleteFile($fileId);
                    \Illuminate\Support\Facades\Storage::disk('local')->delete('google_cache/' . $fileId);
                    \Illuminate\Support\Facades\Cache::forget('gdrive_meta_' . $fileId);
                } catch (\Exception $e) {
                    Log::warning("Drive delete failed after DB commit (file orphaned in Drive): {$fileId} - " . $e->getMessage());
                }
            }

            // 3) Auditoría tras commit
            \App\Models\CatalogoAuditLog::registrar(
                $snapshotId,
                'delete',
                $snapshotModelo,
                $snapshotAnio,
                []
            );

            if ($request->wantsJson()) {
                return response()->json(['message' => 'Modelo eliminado del catálogo', 'redirect' => route('catalogo.index')]);
            }
            return redirect()->route('catalogo.index')->with('success', 'Modelo eliminado del catálogo');
        } catch (\Exception $e) {
            Log::error('Error eliminando modelo del catálogo: ' . $e->getMessage());
            if ($request->wantsJson()) {
                return response()->json(['message' => 'Error al eliminar el modelo. Intente nuevamente.'], 500);
            }
            return back()->with('error', 'Error al eliminar el modelo. Intente nuevamente.');
        }
    }

    /**
     * Vincula automáticamente equipos al catálogo dado, considerando 3 casos:
     *  - Equipos sin catálogo (ID_ESPEC = NULL).
     *  - Equipos HUÉRFANOS (ID_ESPEC apunta a un catálogo que ya no existe).
     *  - Equipos con catálogo viejo SIN foto, si el nuevo SÍ tiene foto.
     *
     * Cada save() dispara EquipoObserver (auditoría + caché).
     * @param  CaracteristicaModelo $catalogo
     * @param  string  $modelo
     * @param  int     $anio
     * @param  string  $context  'create' | 'update' (solo para el log)
     */
    private function autoLinkEquiposToCatalogo(CaracteristicaModelo $catalogo, string $modelo, $anio, string $context = 'create'): void
    {
        $query = Equipo::where('MODELO', $modelo)->where('ANIO', $anio);

        $query->where(function ($q) use ($catalogo) {
            $q->whereNull('ID_ESPEC')
              ->orWhereDoesntHave('especificaciones');

            if ($catalogo->FOTO_REFERENCIAL) {
                $q->orWhereHas('especificaciones', function ($subq) {
                    $subq->whereNull('FOTO_REFERENCIAL');
                });
            }
        });

        $linkedCount = 0;
        foreach ($query->get() as $eq) {
            if ($eq->ID_ESPEC !== $catalogo->ID_ESPEC) {
                $eq->ID_ESPEC = $catalogo->ID_ESPEC;
                $eq->save();
                $linkedCount++;
            }
        }

        if ($linkedCount > 0) {
            $contextLabel = $context === 'update' ? 'after catalog update' : '';
            Log::info(trim("Auto-linked {$linkedCount} equipos {$contextLabel} to catalog ID {$catalogo->ID_ESPEC} ({$modelo} {$anio})"));
        }
    }

    private function validationMessages()
    {
        return [
            'required' => 'El campo :attribute es obligatorio.',
            'integer' => 'El campo :attribute debe ser un número entero.',
            'max' => 'El campo :attribute no debe exceder los :max caracteres o kilobytes.',
            'image' => 'El campo :attribute debe ser una imagen.',
            'mimes' => 'El campo :attribute debe ser de tipo: :values.',
        ];
    }

    private function validationAttributes()
    {
        return [
            'MODELO' => 'Modelo',
            'TIPO' => 'Tipo de Equipo',
            'ANIO_ESPEC' => 'Año de Ficha',
            'MOTOR' => 'Motor',
            'COMBUSTIBLE' => 'Combustible',
            'CONSUMO_PROMEDIO' => 'Consumo Promedio',
            'ACEITE_MOTOR' => 'Aceite de Motor',
            'ACEITE_CAJA' => 'Aceite de Caja',
            'LIGA_FRENO' => 'Liga de Freno',
            'REFRIGERANTE' => 'Refrigerante',
            'TIPO_BATERIA' => 'Tipo de Batería',
        ];
    }

    /**
     * Get distinct brands from equipos table for catalog autocomplete
     */
    public function getBrandsFromEquipos(Request $request)
    {
        $query = $request->input('query', '');
        $tipo  = trim($request->input('tipo', ''));

        $brands = \App\Models\Equipo::select('MARCA')
            ->distinct()
            ->whereNotNull('MARCA')
            ->where('MARCA', 'LIKE', "%{$query}%")
            // Si se indica TIPO, recomendar solo marcas de equipos de ese tipo.
            ->when($tipo !== '', function ($q) use ($tipo) {
                $q->whereHas('tipo', fn ($t) => $t->whereRaw('UPPER(nombre) = ?', [strtoupper($tipo)]));
            })
            ->orderBy('MARCA', 'asc')
            ->limit(20)
            ->pluck('MARCA');

        return response()->json($brands);
    }

    /**
     * Get distinct models from equipos table for catalog autocomplete
     */
    public function getModelsFromEquipos(Request $request)
    {
        $query = $request->input('query', '');
        $tipo  = trim($request->input('tipo', ''));

        $models = \App\Models\Equipo::select('MODELO')
            ->distinct()
            ->whereNotNull('MODELO')
            ->where('MODELO', 'LIKE', "%{$query}%")
            // Si se indica TIPO, recomendar solo modelos de equipos de ese tipo.
            ->when($tipo !== '', function ($q) use ($tipo) {
                $q->whereHas('tipo', fn ($t) => $t->whereRaw('UPPER(nombre) = ?', [strtoupper($tipo)]));
            })
            ->orderBy('MODELO', 'asc')
            ->limit(20)
            ->pluck('MODELO');

        return response()->json($models);
    }

    /**
     * Get distinct years from equipos for a specific model
     */
    public function getYearsFromEquipos(Request $request)
    {
        $model = $request->input('model');
        
        if (!$model) {
            return response()->json([]);
        }

        $years = \App\Models\Equipo::select('ANIO')
            ->distinct()
            ->whereNotNull('ANIO')
            ->where('MODELO', $model)
            ->orderBy('ANIO', 'desc')
            ->pluck('ANIO');

        return response()->json($years);
    }
}
