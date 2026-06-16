<?php

namespace App\Http\Controllers;

use App\Models\CaracteristicaModelo;
use App\Models\Equipo;
use App\Services\GoogleDriveService;
use Illuminate\Http\Request;
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
        $this->middleware('can:super.admin')->only(['destroy']);
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

    public function index(Request $request)
    {
        $query = CaracteristicaModelo::query();

        // 1. Filter by Model
        if ($request->filled('modelo') && trim($request->modelo) !== '' && $request->modelo !== 'all') {
            $query->where('MODELO', 'like', "%{$request->modelo}%");
        }

        // 2. Filter by Year
        if ($request->filled('anio') && trim($request->anio) !== '' && $request->anio !== 'all') {
            $query->where('ANIO_ESPEC', $request->anio);
        }

        // 3. Filter by Tipo de Equipo. El catálogo ahora guarda su TIPO en columna propia
        //    (caracteristicas_modelo.TIPO, nombre en MAYÚSCULAS). El filtro de la UI envía el
        //    id del tipo; lo mapeamos a su nombre para comparar contra la columna real.
        if ($request->filled('id_tipo') && $request->id_tipo !== 'all') {
            $tipoNombre = \App\Models\TipoEquipo::where('id', $request->id_tipo)->value('nombre');
            if ($tipoNombre) {
                $query->where('TIPO', strtoupper(trim($tipoNombre)));
            }
        }

        // Stats Calculation (Based on current filters)
        $statsQuery = $query->clone();
        $totalCount = $statsQuery->count();

        // Group by Model (for Sidebar Stats)
        $modelCounts = $query->clone()
            ->select('MODELO', DB::raw('count(*) as count'))
            ->groupBy('MODELO')
            ->orderBy('count', 'desc')
            ->get();

        // Paginado en bloques de 12 — el cliente los carga por scroll infinito.
        $catalogos = $query->orderBy('MODELO', 'asc')->paginate(12);

        // --- Standardized Lists (Not Context-Aware to avoid confusion) ---
        // This matches Equipo logic: Load all available options regardless of current filter
        $availableModelos = CaracteristicaModelo::select('MODELO')->distinct()->orderBy('MODELO')->pluck('MODELO');
        $availableAnios = CaracteristicaModelo::select('ANIO_ESPEC')->distinct()->orderBy('ANIO_ESPEC', 'desc')->pluck('ANIO_ESPEC');
        // Tipos disponibles para el filtro: los que realmente tiene asignado algún catálogo
        // (columna TIPO). Se devuelve id+nombre porque la UI del filtro trabaja con el id.
        $availableTipos = \App\Models\TipoEquipo::whereIn('nombre', function ($q) {
                $q->select('TIPO')->from('caracteristicas_modelo')->whereNotNull('TIPO');
            })
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        // JSON Response for AJAX (scroll infinito: el cliente AGREGA las
        // tarjetas al final del grid; hasMore indica si quedan páginas).
        if ($request->wantsJson() && $request->has('ajax_load')) {
            $tableHtml = view('admin.catalogo.partials.table_rows', compact('catalogos'))->render();
            $statsHtml = view('admin.catalogo.partials.stats_sidebar', compact('totalCount', 'modelCounts'))->render();

            return response()->json([
                'html'    => $tableHtml,
                'hasMore' => $catalogos->hasMorePages(),
                'page'    => $catalogos->currentPage(),
                'stats'   => $statsHtml,
            ]);
        }

        return view('admin.catalogo.index', compact('catalogos', 'availableModelos', 'availableAnios', 'availableTipos', 'totalCount', 'modelCounts'));
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
