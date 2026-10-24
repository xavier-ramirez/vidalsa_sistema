<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class EquipoAuxiliarController extends Controller
{
    use \App\Traits\ConvertsImageToWebp;

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Devuelve [isLocalUser, frentesPermitidos]. Aplicado en cualquier query
     * que liste auxiliares para usuarios con NIVEL_ACCESO_EQUIPOS=2 (LOCAL).
     */
    private function userScope(): array
    {
        $user = auth()->user();
        // "Restringido" = NO ve todos los frentes (criterio ÚNICO: Usuario::veTodosLosFrentesEquipos).
        $isLocalUser = $user ? !$user->veTodosLosFrentesEquipos() : false;
        $frentesPermitidos = $user ? $user->getFrentesIds() : [];
        return [$isLocalUser, $frentesPermitidos];
    }

    /**
     * Barrera COMPLETA de visibilidad por frente sobre $query/$columna: lista blanca
     * (LOCAL → solo sus frentes) + lista negra de bloqueados (whereNotIn, también GLOBAL).
     * Reemplaza el patrón whereIn/whereRaw('1=0') que estaba repetido por todo el módulo.
     */
    private function scopeFrentes($query, string $columna = 'ID_FRENTE_ACTUAL'): void
    {
        $user = auth()->user();
        if ($user) {
            $user->aplicarScopeFrentesEquipos($query, $columna);
        }
    }

    /**
     * Query de los frentes ACTIVOS que el usuario puede usar (lista blanca LOCAL +
     * lista negra de bloqueados), ordenada por nombre. Fuente UNICA para todo lo que
     * OFRECE frentes al usuario o los ACEPTA de vuelta: formularios de alta/edicion,
     * plantilla Excel, previsualizacion del lote y su guardado. Antes cada sitio
     * repetia el where+scopeFrentes, y la plantilla/preview del bulk se lo saltaban:
     * ofrecian frentes prohibidos que luego el alta rechazaba.
     */
    private function frentesActivosPermitidos()
    {
        $query = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE');
        $this->scopeFrentes($query, 'ID_FRENTE');
        return $query;
    }

    /**
     * True si $frenteId está en la lista negra del usuario actual. Se usa en las
     * acciones de ESCRITURA (crear/mover auxiliar) para que nadie —ni GLOBAL— pueda
     * dar de alta o movilizar hacia un frente bloqueado.
     */
    private function frenteEstaBloqueado($frenteId): bool
    {
        $user = auth()->user();
        if (!$user || $frenteId === null || $frenteId === '') {
            return false;
        }
        return in_array((string) $frenteId, array_map('strval', $user->getFrentesBloqueadosIds()), true);
    }

    /**
     * Aborta con 404 si el auxiliar pertenece a un frente fuera del scope
     * del usuario LOCAL. Usado para no filtrar la existencia del registro
     * via URLs directas. (EquipoController no tiene equivalente: su buscarEquipoOFallar solo
     * busca el equipo, no comprueba frentes.)
     */
    private function authorizeAuxScope(EquipoAuxiliar $aux): void
    {
        $user = auth()->user();
        if (!$user) return;
        $auxFrente = $aux->ID_FRENTE_ACTUAL !== null ? (string) $aux->ID_FRENTE_ACTUAL : null;

        // Lista negra: nadie (ni GLOBAL) puede abrir un auxiliar de un frente bloqueado.
        $bloqueados = array_map('strval', $user->getFrentesBloqueadosIds());
        if ($auxFrente !== null && in_array($auxFrente, $bloqueados, true)) {
            abort(404);
        }

        // Lista blanca: el LOCAL solo ve sus frentes asignados.
        if ($user->veTodosLosFrentesEquipos()) return;
        $permitidos = array_map('strval', $user->getFrentesIds());
        if (!in_array($auxFrente, $permitidos, true)) {
            abort(404);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // LISTADO
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        // El modulo de SOLO auxiliares ya no existe: equipos y auxiliares se ven juntos en
        // /admin/equipos (los pinta el navegador desde la copia local). La ruta se
        // conserva para que un marcador o enlace viejo lleve al modulo unificado.
        return redirect()->route('equipos.index');
    }

    /**
     * Verificación de unicidad en vivo (segundo plano) para el formulario de create
     * unificado: comprueba si un SERIAL o CODIGO_INTERNO ya existe en otro auxiliar.
     * Espejo de EquipoController::checkUniqueness. SoftDeletes excluye automáticamente
     * los registros en papelera (coherente con la regla unique de validateData, que
     * también ignora deleted_at). El valor se compara en MAYÚSCULAS porque así se guarda.
     */
    public function checkUnique(Request $request)
    {
        $field = $request->input('field');
        $value = trim((string) $request->input('value'));
        $id    = $request->input('id'); // exclusión en update (paridad; el create no lo usa)

        $allowed = ['SERIAL', 'CODIGO_INTERNO'];
        if (!in_array($field, $allowed, true)) {
            return response()->json(['error' => 'Invalid field'], 400);
        }
        if ($value === '') {
            return response()->json(['exists' => false]);
        }

        $query = EquipoAuxiliar::where($field, mb_strtoupper($value));
        if ($id) {
            $query->where('ID_AUXILIAR', '!=', $id);
        }

        return response()->json(['exists' => $query->exists()]);
    }

    /**
     * Mapa TIPO => label de los tipos de auxiliar presentes en el scope del
     * usuario (whitelist LOCAL + blacklist bloqueados). Solo incluye tipos que
     * existen en BD; aplica el label bonito del enum si el codigo existe alli.
     * Usado por el filtro del listado y por el dropdown combinado de /admin/equipos.
     */
    public function buildTiposMap(): array
    {
        $tiposEnDB = EquipoAuxiliar::select('TIPO')
            ->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->tap(fn ($q) => $this->scopeFrentes($q, 'ID_FRENTE_ACTUAL'))
            ->distinct()->orderBy('TIPO')->pluck('TIPO');
        $tipos = [];
        foreach ($tiposEnDB as $t) {
            $tipos[$t] = EquipoAuxiliar::etiquetaTipo($t);
        }
        asort($tipos);
        return $tipos;
    }

    /**
     * El catálogo de auxiliares se FUSIONÓ con el de equipos: /admin/catalogo ahora
     * muestra VEHÍCULOS y AUXILIARES juntos (CaracteristicaModeloController::index, que
     * arma los items de ambas fuentes). Esta ruta redirige allí para que haya un único
     * catálogo. La subida de foto de auxiliares sigue viva en uploadCatalogoPhoto().
     */
    public function catalogo(Request $request)
    {
        return redirect()->route('catalogo.index');
    }

    /**
     * Sube/actualiza la foto representativa de un grupo del catalogo
     * (tipo+marca+modelo+anio). Misma logica que CaracteristicaModeloController:
     * convierte la imagen a WebP, sube a Google Drive (catalog_folder) y aplica
     * la URL resultante a TODAS las unidades de ese modelo+anio en una sola
     * UPDATE bulk. Permiso requerido: equipos.create.
     */
    public function uploadCatalogoPhoto(Request $request)
    {
        // Drive uploads pueden tardar — subir el limite por si el servidor
        // tiene una conexion lenta a la API.
        @set_time_limit(120);

        $validated = $request->validate([
            'tipo'   => 'required|string|max:60',
            'marca'  => 'required|string|max:80',
            'modelo' => 'required|string|max:80',
            'anio'   => 'nullable|integer',
            'foto'   => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        // Scope LOCAL: solo si tiene auxiliares de ese modelo+ano en su scope.
        [$isLocalUser, $frentesPermitidos] = $this->userScope();

        $tipoKey   = $validated['tipo'];
        $marcaKey  = mb_strtoupper(trim($validated['marca']));
        $modeloKey = mb_strtoupper(trim($validated['modelo']));
        $anio      = $validated['anio'] ?? null;

        // Construir el query del grupo (modelo+ano). Lo usamos primero para
        // obtener la(s) FOTO(s) anteriores y borrar el archivo viejo de Drive
        // antes de subir el nuevo (evita acumulacion de huerfanos).
        // MISMA normalización que catalogo() (COALESCE(NULLIF(TRIM(x),''),'—')) para que
        // el grupo matchee SIEMPRE — antes usaba COALESCE(MARCA,'—') que NO capturaba
        // marcas/modelos en cadena VACÍA (solo NULL) → la tarjeta los agrupaba como '—'
        // pero el update no encontraba filas → $updated=0 y la foto no se veía.
        $groupQ = EquipoAuxiliar::query()
            ->where('TIPO', $tipoKey)
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MARCA), ''), '—')) = ?", [$marcaKey])
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MODELO), ''), '—')) = ?", [$modeloKey]);
        if ($anio !== null) {
            $groupQ->where('ANIO', $anio);
        } else {
            $groupQ->whereNull('ANIO');
        }
        // LOCAL sin frentes asignados: no tiene auxiliares que agrupar (se conserva el 403 UX).
        if ($isLocalUser && count($frentesPermitidos) === 0) {
            return response()->json(['success' => false, 'message' => 'No tienes auxiliares en tu scope para este modelo.'], 403);
        }
        // Barrera por frente: whitelist LOCAL + blacklist de bloqueados (también GLOBAL).
        $this->scopeFrentes($groupQ, 'ID_FRENTE_ACTUAL');

        // Guard: si NO hay unidades en el grupo, no subimos nada (evita un huérfano en
        // Drive y un "éxito" silencioso de 0 unidades) y avisamos claro al usuario.
        if ((clone $groupQ)->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron unidades de ese modelo (en tu alcance) para asociar la foto.',
            ], 422);
        }

        // FOTOs anteriores (drive ids unicos) — para borrar despues de exito
        $oldDriveIds = (clone $groupQ)
            ->whereNotNull('FOTO')->where('FOTO', '!=', '')
            ->pluck('FOTO')->unique()
            ->map(function ($url) { return basename(str_replace('/storage/google/', '', explode('?', $url)[0])); })
            ->filter()->values()->all();

        // Convertir a WebP en disco temporal
        $webpResult = $this->convertToWebp($request->file('foto'));
        $webpFile    = $webpResult['file'];
        $tempWebpPath = $webpResult['tempPath'];

        try {
            $driveService = \App\Services\GoogleDriveService::getInstance();
            $folderId = config('filesystems.disks.google.catalog_folder');
            $filename = 'aux_catalog_' . (int)(microtime(true) * 1000) . '_' . preg_replace('/[^A-Za-z0-9_]/', '_', $tipoKey . '_' . $marcaKey . '_' . $modeloKey . '_' . ($anio ?? 'NA')) . '.webp';
            $driveFile = $driveService->uploadFile($folderId, $webpFile, $filename, 'image/webp');

            if (!$driveFile || !isset($driveFile->id)) {
                return response()->json(['success' => false, 'message' => 'No se pudo subir la foto a Google Drive.'], 500);
            }

            // CRITICO: hacer el archivo publico para que <img src="https://drive.google.com/thumbnail?id=..."
            // funcione sin autenticacion. La carpeta puede no propagar permisos
            // a archivos nuevos en algunos casos (Shared Drives, propietario distinto).
            $driveService->makePublic($driveFile->id);

            // Cache busting con ?v= timestamp en la URL guardada — asi al
            // recargar la pagina, el browser no reusa la imagen vieja.
            $fotoUrl = '/storage/google/' . $driveFile->id . '?v=' . time();

            $updated = $groupQ->update(['FOTO' => $fotoUrl]);

            // Borrar las fotos anteriores de Drive (best-effort, no fatal si falla)
            foreach ($oldDriveIds as $oldId) {
                if ($oldId && $oldId !== $driveFile->id) {
                    try { $driveService->enviarAPapelera($oldId); } catch (\Throwable $e) { /* silent */ }
                }
            }
        } finally {
            if ($tempWebpPath && file_exists($tempWebpPath)) @unlink($tempWebpPath);
        }

        \App\Models\CatalogoAuditLog::registrar(
            null,
            'upload_foto_aux',
            $modeloKey,
            $anio ? (int) $anio : null,
            ['tipo' => $tipoKey, 'marca' => $marcaKey, 'unidades' => $updated]
        );

        return response()->json([
            'success' => true,
            'message' => "Foto actualizada para {$updated} unidad(es).",
            'foto'    => $fotoUrl,
            'updated' => $updated,
        ]);
    }

    public function deleteCatalogoPhoto(Request $request)
    {
        $validated = $request->validate([
            'tipo'   => 'required|string|max:60',
            'marca'  => 'required|string|max:80',
            'modelo' => 'required|string|max:80',
            'anio'   => 'nullable|integer',
        ]);

        $tipoKey   = $validated['tipo'];
        $marcaKey  = mb_strtoupper(trim($validated['marca']));
        $modeloKey = mb_strtoupper(trim($validated['modelo']));
        $anio      = $validated['anio'] ?? null;

        $groupQ = EquipoAuxiliar::query()
            ->where('TIPO', $tipoKey)
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MARCA), ''), '—')) = ?", [$marcaKey])
            ->whereRaw("UPPER(COALESCE(NULLIF(TRIM(MODELO), ''), '—')) = ?", [$modeloKey]);
        if ($anio !== null) {
            $groupQ->where('ANIO', $anio);
        } else {
            $groupQ->whereNull('ANIO');
        }
        $this->scopeFrentes($groupQ, 'ID_FRENTE_ACTUAL');

        $oldDriveIds = (clone $groupQ)
            ->whereNotNull('FOTO')->where('FOTO', '!=', '')
            ->pluck('FOTO')->unique()
            ->map(function ($url) { return basename(str_replace('/storage/google/', '', explode('?', $url)[0])); })
            ->filter()->values()->all();

        if (empty($oldDriveIds)) {
            return response()->json(['success' => false, 'message' => 'Este modelo no tiene foto.'], 422);
        }

        $updated = $groupQ->update(['FOTO' => null]);

        \App\Models\CatalogoAuditLog::registrar(
            null,
            'delete_foto_aux',
            $modeloKey,
            $anio ? (int) $anio : null,
            ['tipo' => $tipoKey, 'marca' => $marcaKey, 'unidades' => $updated]
        );

        // Drive: diferido para que la respuesta sea inmediata.
        defer(function () use ($oldDriveIds) {
            foreach ($oldDriveIds as $oldId) {
                try {
                    \App\Services\GoogleDriveService::getInstance()->enviarAPapelera($oldId);
                } catch (\Throwable $e) { /* silent */ }
            }
        });

        return response()->json(['success' => true, 'message' => "Foto eliminada de {$updated} unidad(es)."]);
    }

    // convertToWebp() viene del trait ConvertsImageToWebp (use al inicio de la clase).

    /**
     * Detalles completos del auxiliar (modal "Ver detalles"). El listado ya los trae de la
     * copia local (window.auxDetailsMap); esto es el respaldo si alguno no está.
     */
    public function details($id)
    {
        $aux = EquipoAuxiliar::with(EquipoAuxiliar::conDetalles())->findOrFail($id);
        $this->authorizeAuxScope($aux);
        $user = auth()->user();
        return response()->json($aux->toDetailsPayload() + [
            'edit_url'       => route('equipos-auxiliares.edit', $aux->ID_AUXILIAR),
            'can_upload_pdf' => $user->can('user.edit'),
        ]);
    }

    /**
     * Lista los equipos auxiliares anclados a un equipo host especifico.
     * Usado por el modal de detalles de /admin/equipos para mostrar los
     * auxiliares en la seccion "Sub-activos vinculados".
     */
    public function byHost($hostId)
    {
        $auxQuery = EquipoAuxiliar::where('ID_EQUIPO_HOST', $hostId);
        $this->scopeFrentes($auxQuery, 'ID_FRENTE_ACTUAL');
        $auxiliares = $auxQuery
            ->orderBy('TIPO')
            ->get()
            ->map(function ($a) {
                return [
                    'id'             => $a->ID_AUXILIAR,
                    'tipo'           => $a->TIPO,
                    'serial'         => $a->SERIAL,
                    'marca'          => $a->MARCA,
                    'modelo'         => $a->MODELO,
                    'capacidad'      => $a->CAPACIDAD,
                'combustible'    => $a->COMBUSTIBLE,
                'consumo'        => Equipo::consumoFormateado($a->CONSUMO_PROMEDIO),
                    'anio'           => $a->ANIO,
                    'estado'         => $a->ESTADO_OPERATIVO,
                    // Foto del auxiliar para el modal de detalles del equipo host
                    // (seccion "Sub-activos vinculados"). Null si no hay foto.
                    'foto'           => $a->FOTO ? asset($a->FOTO) : null,
                    // Documentos del auxiliar: se muestran (desplegables) DENTRO de la seccion
                    // "Sub-activos vinculados" del modal del equipo host, para poder ver el PDF
                    // sin tener que buscar el auxiliar y abrir su propio modal. Se guardan como
                    // ruta '/storage/...' directa (misma forma que usa el modal de auxiliares).
                    'link_doc_propiedad' => $a->LINK_DOC_PROPIEDAD ?: null,
                    'link_certificado'   => $a->LINK_CERTIFICADO ?: null,
                ];
            });

        return response()->json(['ok' => true, 'data' => $auxiliares]);
    }

    // ═══════════════════════════════════════════════════════════
    // CRUD
    // ═══════════════════════════════════════════════════════════
    public function create()
    {
        // El formulario de creacion vive ahora en /admin/equipos/create (unificado: registra
        // equipos y auxiliares desde el mismo sitio). Esta ruta se mantiene para que los
        // enlaces y marcadores viejos no den 404, pero lleva alli.
        return redirect()->route('equipos.create');
    }

    /**
     * Invalida las listas cacheadas que alimentan filtros y formularios (marcas, modelos,
     * anios...). Hay que llamarla en TODA escritura de auxiliares: sin esto, la marca de
     * un auxiliar recien registrado aparecia en el formulario en menos de un minuto pero
     * tardaba hasta 20 minutos en salir en el filtro de la misma pantalla, porque las
     * claves aux_*_dropdown viven 1.200 s y nadie las borraba.
     *
     * La lista de claves vive en EquipoController::CLAVES_LISTAS_CACHEADAS: una sola.
     */
    private function olvidarListasCacheadas(): void
    {
        foreach (\App\Http\Controllers\EquipoController::CLAVES_LISTAS_CACHEADAS as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        // LOCAL: solo puede crear auxiliares en sus frentes asignados.
        [$isLocalUser, $frentesPermitidos] = $this->userScope();
        if ($isLocalUser && !empty($data['ID_FRENTE_ACTUAL'])) {
            if (!in_array((string) $data['ID_FRENTE_ACTUAL'], array_map('strval', $frentesPermitidos), true)) {
                abort(403, 'No tiene permisos para registrar auxiliares en este frente.');
            }
        }
        // Nadie (ni GLOBAL) puede crear en un frente BLOQUEADO.
        if (!empty($data['ID_FRENTE_ACTUAL']) && $this->frenteEstaBloqueado($data['ID_FRENTE_ACTUAL'])) {
            abort(403, 'No tiene permisos para registrar auxiliares en este frente.');
        }

        $data['CREADO_POR'] = auth()->id();
        // Todos los campos de texto (select o input) se guardan en MAYUSCULAS
        // para consistencia (reportes, busquedas, filtros case-insensitive).
        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD', 'OBSERVACIONES'] as $f) {
            if (!empty($data[$f])) $data[$f] = mb_strtoupper(trim($data[$f]));
        }

        // Remover claves de archivos del array antes de create (Eloquent no sabe manejarlos).
        unset($data['doc_propiedad'], $data['certificado']);
        // Normalizar nombre de campo fecha.
        if (!empty($data['fecha_vencimiento_cert'])) {
            $data['FECHA_VENCIMIENTO_CERT'] = $data['fecha_vencimiento_cert'];
        }
        unset($data['fecha_vencimiento_cert']);

        // PDFs a Drive ANTES de crear la fila (ver subirDocsFormulario).
        $links = $this->subirDocsFormulario($request);

        try {
            $auxiliar = EquipoAuxiliar::create($data);
        } catch (\Throwable $e) {
            array_map([EquipoAuxiliar::class, 'olvidarDoc'], $links); // no dejarlos huérfanos en Drive
            throw $e;
        }

        // Auditoría de REGISTRO de auxiliar. Reutiliza CatalogoAuditLog, el mismo canal
        // que ya audita eventos de auxiliares (p.ej. 'upload_foto_aux'), porque los
        // auxiliares no tienen tabla de auditoría propia. El serial/tipo van en CAMBIOS
        // para que el módulo de historial muestre qué unidad se creó.
        \App\Models\CatalogoAuditLog::registrar(
            null,
            'create_aux',
            $auxiliar->MODELO,
            $auxiliar->ANIO ? (int) $auxiliar->ANIO : null,
            [
                'tipo'         => $auxiliar->TIPO,
                'marca'        => $auxiliar->MARCA,
                'serial'       => $auxiliar->SERIAL,
                'id_auxiliar'  => $auxiliar->ID_AUXILIAR,
            ]
        );

        // Los links van en un update aparte (y después de 'create_aux') para que el observer
        // registre la subida de cada PDF en el historial, como cuando se sube desde la ficha.
        if ($links) {
            $auxiliar->update($links);
        }

        // Por defecto se vuelve al modulo UNIFICADO (/admin/equipos), que es donde se ven
        // equipos y auxiliares juntos. `__unified_redirect` (o el ?ref= del formulario)
        // sigue mandando si viene. Antes el defecto era el modulo VIEJO de solo-auxiliares,
        // asi que guardar sin ese parametro te sacaba del modulo unificado sin pedirlo.
        $redirectUrl = $request->input('__unified_redirect', route('equipos.index'));

        if ($request->wantsJson()) {
            $this->olvidarListasCacheadas();
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar registrado correctamente.',
                'redirect' => $redirectUrl,
            ]);
        }
        return redirect($redirectUrl)->with('success', 'Equipo auxiliar registrado correctamente.');
    }

    public function edit(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        $frentes = $this->frentesActivosPermitidos()->get();
        // TIPOS dinamicos: base del enum + los tipos custom guardados en DB.
        $tipos    = $this->getTiposDinamicos();
        $estados  = EquipoAuxiliar::estadosLabel();
        // URL de retorno: la pasa el JS del módulo origen (equipos o equipos-auxiliares).
        $ref = $request->input('ref', '');
        return view('admin.equipos_auxiliares.edit', compact('auxiliar', 'frentes', 'tipos', 'estados', 'ref'));
    }

    /**
     * Combina los TIPOS del enum (con labels bonitos) con los tipos custom
     * que el usuario haya registrado (existentes en la BD). Para los custom,
     * genera un label legible a partir del codigo (ej. "GENERADOR_DIESEL" ->
     * "Generador Diesel"). Asi el filtro y los comboboxes reflejan siempre
     * el estado real de tipos en uso, no una lista hardcoded.
     */
    private function getTiposDinamicos(): array
    {
        $tipos = EquipoAuxiliar::tiposLabel();
        $tiposEnDB = EquipoAuxiliar::select('TIPO')
            ->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->distinct()->orderBy('TIPO')->pluck('TIPO');
        foreach ($tiposEnDB as $t) {
            if (!isset($tipos[$t])) {
                $tipos[$t] = ucwords(mb_strtolower(str_replace('_', ' ', $t)));
            }
        }
        return $tipos;
    }

    public function update(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        $data = $this->validateData($request, false);

        // El frente NO se cambia por edición de datos: reasignar un auxiliar de frente
        // es trabajo de MOVILIZACIÓN (que además deja CONFIRMADO_EN_SITIO=0). El selector
        // de frente del formulario solo aplica al CREAR; en edición va bloqueado. Lo
        // descartamos aquí para conservar SIEMPRE el frente y la confirmación actuales,
        // incluso si alguien manipulara el form (por eso ya no hacen falta los checks de
        // reasignación por scope/bloqueo: el frente simplemente no se toca).
        unset($data['ID_FRENTE_ACTUAL']);

        // Todos los campos de texto se normalizan a MAYUSCULAS (consistencia
        // con store y con el resto de la app).
        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD', 'OBSERVACIONES'] as $f) {
            if (!empty($data[$f])) $data[$f] = mb_strtoupper(trim($data[$f]));
        }

        unset($data['doc_propiedad'], $data['certificado']);
        if (array_key_exists('fecha_vencimiento_cert', $data)) {
            $data['FECHA_VENCIMIENTO_CERT'] = $data['fecha_vencimiento_cert'] ?: null;
            unset($data['fecha_vencimiento_cert']);
        }

        // PDFs a Drive ANTES de tocar la fila (ver subirDocsFormulario). Los que se reemplazan
        // se borran recién después de guardar; antes se quedaban para siempre en el disco.
        $links      = $this->subirDocsFormulario($request);
        $anteriores = $auxiliar->only(array_keys($links));

        try {
            $auxiliar->update(array_merge($data, $links));
        } catch (\Throwable $e) {
            array_map([EquipoAuxiliar::class, 'olvidarDoc'], $links); // no dejarlos huérfanos en Drive
            throw $e;
        }
        array_map([EquipoAuxiliar::class, 'olvidarDoc'], $anteriores);
        $this->olvidarListasCacheadas();

        // Por defecto se vuelve al modulo UNIFICADO (/admin/equipos), que es donde se ven
        // equipos y auxiliares juntos. `__unified_redirect` (o el ?ref= del formulario)
        // sigue mandando si viene. Antes el defecto era el modulo VIEJO de solo-auxiliares,
        // asi que guardar sin ese parametro te sacaba del modulo unificado sin pedirlo.
        $redirectUrl = $request->input('__unified_redirect', route('equipos.index'));

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar actualizado correctamente.',
                'redirect' => $redirectUrl,
            ]);
        }
        return redirect($redirectUrl)->with('success', 'Equipo auxiliar actualizado correctamente.');
    }

    public function destroy(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        // Soft-delete con auditoria de quien borro (mismo patron que equipos): el apunte es la
        // fila "Eliminación de Equipo" del historial, igual que al borrar un equipo.
        \App\Models\EquipoAuditLog::registrarAux($auxiliar->ID_AUXILIAR, 'aux_delete', [
            'codigo_interno' => $auxiliar->CODIGO_INTERNO,
            'serial'         => $auxiliar->SERIAL,
        ], $auxiliar->etiquetaAuditoria());
        $auxiliar->deleted_by = auth()->id();
        $auxiliar->save();
        $auxiliar->delete();

        if ($request->wantsJson()) {
            $this->olvidarListasCacheadas();
            return response()->json(['success' => true]);
        }
        return redirect()->route('equipos.index')->with('success', 'Equipo auxiliar eliminado.');
    }

    /**
     * Borrado masivo (soft-delete) por IDs. Usado desde el menu Acciones del
     * index cuando hay auxiliares seleccionados via checkbox.
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer|exists:equipos_auxiliares,ID_AUXILIAR',
        ]);

        $userId = auth()->id();
        $borrados = 0;
        DB::transaction(function () use ($request, $userId, &$borrados) {
            $auxiliares = EquipoAuxiliar::whereIn('ID_AUXILIAR', $request->ids)->lockForUpdate()->get();
            foreach ($auxiliares as $aux) {
                \App\Models\EquipoAuditLog::registrarAux($aux->ID_AUXILIAR, 'aux_delete', [
                    'codigo_interno' => $aux->CODIGO_INTERNO,
                    'serial'         => $aux->SERIAL,
                    'bulk'           => true,
                ], $aux->etiquetaAuditoria());
                $aux->deleted_by = $userId;
                $aux->save();
                $aux->delete();
                $borrados++;
            }
        });

        $this->olvidarListasCacheadas();
        return response()->json([
            'success' => true,
            'message' => "Se eliminaron {$borrados} auxiliar(es). Recuperables desde la papelera.",
            'count'   => $borrados,
        ]);
    }

    /**
     * Lista de auxiliares en papelera (soft-deleted) con metadata de quien
     * borro y cuando — para mostrar en /admin/historial-documentos. Endpoint
     * JSON simple, no pagina (la papelera no suele crecer demasiado).
     */
    public function papelera(Request $request)
    {
        $items = EquipoAuxiliar::onlyTrashed()
            ->with(['frente:ID_FRENTE,NOMBRE_FRENTE'])
            ->orderByDesc('deleted_at')
            ->get();

        $userIds = $items->pluck('deleted_by')->filter()->unique()->values()->all();
        $usuarios = !empty($userIds)
            ? \App\Models\Usuario::whereIn('ID_USUARIO', $userIds)
                ->pluck('NOMBRE_COMPLETO', 'ID_USUARIO')->toArray()
            : [];

        $tipos = $this->getTiposDinamicos();
        $rows = $items->map(function ($a) use ($usuarios, $tipos) {
            $fotoDriveId = $a->FOTO ? basename(str_replace('/storage/google/', '', explode('?', $a->FOTO)[0])) : null;
            return [
                'id'             => $a->ID_AUXILIAR,
                'tipo'           => $tipos[$a->TIPO] ?? $a->TIPO,
                'marca'          => $a->MARCA,
                'modelo'         => $a->MODELO,
                'serial'         => $a->SERIAL,
                'frente'         => optional($a->frente)->NOMBRE_FRENTE,
                'foto_drive_id'  => $fotoDriveId,
                // Los documentos cargados, para verlos desde la papelera sin restaurar.
                'documentos'     => collect(['propiedad' => ['Propiedad', $a->LINK_DOC_PROPIEDAD], 'certificado' => ['Certificado', $a->LINK_CERTIFICADO]])
                    ->filter(fn ($d) => trim((string) $d[1]) !== '')
                    ->map(fn ($d, $tipo) => ['tipo' => $tipo, 'nombre' => $d[0], 'link' => trim($d[1])])
                    ->values()->all(),
                'deleted_at'     => optional($a->deleted_at)->format('d/m/Y H:i'),
                'deleted_by'     => $a->deleted_by ? ($usuarios[$a->deleted_by] ?? '#' . $a->deleted_by) : null,
            ];
        });

        return response()->json(['success' => true, 'items' => $rows]);
    }

    /**
     * Restaura un auxiliar borrado (soft-delete -> activo). Limpia deleted_by
     * para que el audit trail quede consistente.
     */
    public function restoreAuxiliar(Request $request, $id)
    {
        $aux = EquipoAuxiliar::onlyTrashed()->where('ID_AUXILIAR', $id)->firstOrFail();
        $aux->deleted_by = null;
        $aux->save();
        $aux->restore();

        return response()->json(['success' => true, 'message' => 'Auxiliar restaurado.']);
    }

    /** Borra PERMANENTEMENTE un auxiliar de la papelera (forceDelete) — irreversible, exclusivo super.admin. */
    public function forceDeleteAuxiliar(int $id)
    {
        $aux = EquipoAuxiliar::onlyTrashed()->where('ID_AUXILIAR', $id)->first();
        if (!$aux) {
            return response()->json(['success' => false, 'message' => 'Auxiliar no encontrado en la papelera.'], 404);
        }

        try {
            $aux->forceDelete();
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar permanentemente: el auxiliar tiene registros asociados.',
            ], 422);
        }

        return response()->json(['success' => true, 'message' => 'Auxiliar eliminado permanentemente.']);
    }

    // ═══════════════════════════════════════════════════════════
    // ANCHOR 1:N (tope 2 auxiliares por equipo host)
    // ═══════════════════════════════════════════════════════════
    public function anchor(Request $request, $id)
    {
        $request->validate([
            'id_equipo_host' => 'required|exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::transaction(function () use ($request, $id) {
                $auxiliar = EquipoAuxiliar::lockForUpdate()->findOrFail($id);
                $this->authorizeAuxScope($auxiliar);
                $hostId   = $request->id_equipo_host;

                // Verificar tope: no mas de N auxiliares por equipo host (excluyendo este mismo)
                $actuales = EquipoAuxiliar::where('ID_EQUIPO_HOST', $hostId)
                    ->where('ID_AUXILIAR', '!=', $id)
                    ->lockForUpdate()
                    ->count();

                if ($actuales >= EquipoAuxiliar::ANCHOR_MAX_PER_HOST) {
                    throw new \RuntimeException(
                        'El equipo host ya tiene el maximo permitido de ' .
                        EquipoAuxiliar::ANCHOR_MAX_PER_HOST . ' auxiliares anclados.'
                    );
                }

                $auxiliar->update(['ID_EQUIPO_HOST' => $hostId]);
            });

            return response()->json(['success' => true, 'message' => 'Equipo auxiliar anclado correctamente.']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('EquipoAuxiliar anchor falló: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al anclar el equipo.'], 500);
        }
    }

    public function unanchor($id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($auxiliar);
        $auxiliar->update(['ID_EQUIPO_HOST' => null]);
        return response()->json(['success' => true, 'message' => 'Equipo auxiliar desanclado.']);
    }

    /**
     * Confirma (o quita) la presencia física del auxiliar en su frente actual.
     * Espeja EquipoController::confirmarSitio. Mismo ciclo: el despacho deja el
     * auxiliar en 0 (pendiente) y aquí se tilda en 1 (confirmado en sitio).
     */
    public function confirmarSitio(Request $request, $id)
    {
        $request->validate([
            'confirmado' => 'required|boolean',
        ]);
        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $aux->CONFIRMADO_EN_SITIO = $request->boolean('confirmado') ? 1 : 0;
        $aux->save();

        return response()->json([
            'success'    => true,
            'confirmado' => (int) $aux->CONFIRMADO_EN_SITIO,
        ]);
    }

    /**
     * Cambio rapido de estado operativo (inline desde la tabla del index).
     * Validacion minima: solo ESTADO_OPERATIVO. No toca otros campos required.
     */
    public function changeStatus(Request $request, $id)
    {
        $estados = array_keys(EquipoAuxiliar::estadosLabel());
        $request->validate([
            'ESTADO_OPERATIVO' => 'required|string|in:' . implode(',', $estados),
        ]);

        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);

        // Si el equipo tiene un reporte de falla ABIERTO, su estado lo gobierna ese
        // reporte (quedó INOPERATIVO al crearlo): no se cambia a mano desde aquí. Para
        // cambiarlo hay que CERRAR el reporte (lo que lo devuelve a OPERATIVO).
        // Devolvemos los datos del reporte para que el front muestre el modal de cierre.
        $falla = \App\Models\Falla::where('ACTIVO_TIPO', 'equipo_auxiliar')
            ->where('ACTIVO_ID', $aux->ID_AUXILIAR)
            ->where('ESTADO_REPORTE', 'abierto')
            ->latest('FECHA_EMISION')
            ->first();

        if ($falla) {
            return \App\Models\Falla::respuestaReporteAbierto($falla, $aux);
        }

        $aux->ESTADO_OPERATIVO = $request->input('ESTADO_OPERATIVO');
        $aux->save();

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado.',
            'estado'  => $aux->ESTADO_OPERATIVO,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // SEARCH — autocomplete para anclaje
    // ═══════════════════════════════════════════════════════════
    public function search(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        $tiposMap = $this->getTiposDinamicos();
        $searchQuery = EquipoAuxiliar::with('equipoHost.documentacion')
            ->where(function ($w) use ($q) {
                $w->where('SERIAL', 'like', "%{$q}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$q}%")
                  ->orWhere('MARCA', 'like', "%{$q}%")
                  ->orWhere('MODELO', 'like', "%{$q}%");
            });
        $this->scopeFrentes($searchQuery, 'ID_FRENTE_ACTUAL');
        $results = $searchQuery
            ->limit(20)
            ->get()
            ->map(function ($a) use ($tiposMap) {
                return [
                    'id'          => $a->ID_AUXILIAR,
                    'tipo'        => $a->TIPO,
                    'tipo_label'  => $tiposMap[$a->TIPO] ?? $a->TIPO,
                    'marca'       => $a->MARCA,
                    'modelo'      => $a->MODELO,
                    'serial'      => $a->SERIAL,
                    'host_id'     => $a->ID_EQUIPO_HOST,
                    'host_codigo' => optional($a->equipoHost)->CODIGO_PATIO,
                    'host_placa'  => optional(optional($a->equipoHost)->documentacion)->PLACA,
                ];
            });

        return response()->json($results);
    }

    public function searchHosts(Request $request)
    {
        $q = trim($request->get('q', ''));
        $recommend = $request->boolean('recommend');

        // Modo busqueda: requiere min 2 chars. Modo recommend: vacio OK.
        if (!$recommend && strlen($q) < 2) return response()->json([]);

        // Busqueda ampliada: serial chasis, serial motor, placa (docum.),
        // codigo patio, marca y modelo. Join con documentacion para PLACA.
        $hostQuery = Equipo::with(['documentacion', 'tipo', 'equiposAuxiliares', ...Equipo::conFoto('', true), 'frenteActual'])
            ->leftJoin('documentacion as doc_host', 'equipos.ID_EQUIPO', '=', 'doc_host.ID_EQUIPO')
            ->select('equipos.*');
        $this->scopeFrentes($hostQuery, 'equipos.ID_FRENTE_ACTUAL');

        // Modo recomendacion: FLOTA LIVIANA con cupo disponible. Los auxiliares
        // (compresores, soldadoras, etc.) tipicamente se anclan a unidades de
        // flota liviana que las transportan, asi que sugerimos esas primero.
        if ($recommend) {
            $hostQuery->where('equipos.CATEGORIA_FLOTA', 'FLOTA LIVIANA');
        } elseif ($q !== '') {
            $hostQuery->where(function ($w) use ($q) {
                $w->where('equipos.CODIGO_PATIO', 'like', "%{$q}%")
                  ->orWhere('equipos.SERIAL_CHASIS', 'like', "%{$q}%")
                  ->orWhere('equipos.SERIAL_DE_MOTOR', 'like', "%{$q}%")
                  ->orWhere('equipos.MARCA', 'like', "%{$q}%")
                  ->orWhere('equipos.MODELO', 'like', "%{$q}%")
                  ->orWhere('doc_host.PLACA', 'like', "%{$q}%");
            });
        }

        $results = $hostQuery
            ->distinct()
            ->orderBy('equipos.CODIGO_PATIO')
            ->limit($recommend ? 30 : 20)
            ->get()
            ->map(function ($e) {
                // Foto: Equipo::fotoParaMostrar (color → modelo → propia).
                $foto = $e->foto;
                return [
                    'id'             => $e->ID_EQUIPO,
                    'codigo'         => $e->CODIGO_PATIO,
                    'placa'          => optional($e->documentacion)->PLACA,
                    'serial_chasis'  => $e->SERIAL_CHASIS,
                    'serial_motor'   => $e->SERIAL_DE_MOTOR,
                    'tipo'           => optional($e->tipo)->nombre,
                    'marca'          => $e->MARCA,
                    'modelo'         => $e->MODELO,
                    'marca_modelo'   => trim(($e->MARCA ?? '') . ' ' . ($e->MODELO ?? '')),
                    'foto'           => $foto,
                    'frente_nombre'  => optional($e->frenteActual)->NOMBRE_FRENTE,
                    'auxiliares_anclados' => $e->equiposAuxiliares->count(),
                    'disponible'     => $e->equiposAuxiliares->count() < EquipoAuxiliar::ANCHOR_MAX_PER_HOST,
                ];
            });

        return response()->json($results);
    }


    /**
     * Sube/reemplaza un PDF puntual desde el modal de detalles.
     * Endpoint: POST /admin/equipos-auxiliares/{id}/upload-doc
     * Permiso: equipos.edit (gateado en routes/web.php).
     * doc_type: propiedad | certificado
     */
    public function uploadDoc(Request $request, $id)
    {
        // PDFs grandes: mismos límites que EquipoController::uploadDoc.
        set_time_limit(600);
        \App\Support\Memoria::alMenos('512M');

        $request->validate([
            // El tope lo manda GoogleDriveService::MAX_PDF_KB, la comprobacion central por
            // la que pasan TODOS los PDF. Aqui se repite para avisar ANTES de subir a Drive
            // un archivo que se va a rechazar igual.
            'file'     => 'required|file|mimes:pdf|max:' . \App\Services\GoogleDriveService::MAX_PDF_KB,
            'doc_type' => 'required|in:' . implode(',', array_keys(EquipoAuxiliar::DOCS)),
            'fecha_vencimiento_cert' => $this->reglaFechaCert($request->input('doc_type') === 'certificado'),
        ], self::MENSAJES_FECHA_CERT + [
            'file.required' => 'Debe seleccionar un archivo.',
            'file.file'     => 'El documento no es válido.',
            'file.mimes'    => 'Solo se aceptan archivos en formato PDF.',
            'file.max'      => 'El archivo supera el tamaño máximo permitido ('
                               . number_format(\App\Services\GoogleDriveService::MAX_PDF_KB, 0, ',', '.') . ' KB).',
        ]);

        $aux  = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('doc_type');
        $col  = EquipoAuxiliar::DOCS[$type];

        // Mismo camino que el formulario de crear/editar: Drive + proxy /storage/google/{id}
        // (ver EquipoAuxiliar::subirDocADrive).
        try {
            $driveService = \App\Services\GoogleDriveService::getInstance();
            $anterior     = $aux->$col;

            $aux->$col = EquipoAuxiliar::subirDocADrive($driveService, $type, $request->file('file'));
            // La fecha viaja con el certificado nuevo (obligatoria, validada arriba): nunca
            // se queda con la del anterior.
            if ($type === 'certificado') {
                $aux->FECHA_VENCIMIENTO_CERT = $request->input('fecha_vencimiento_cert');
            }
            $aux->save();

            // El archivo viejo se borra SOLO tras guardar el nuevo (safety first).
            EquipoAuxiliar::olvidarDoc($anterior);

            return response()->json([
                'success' => true,
                'message' => 'PDF cargado correctamente.',
                'link'    => $aux->$col,
                'fecha_vencimiento_cert' => $aux->FECHA_VENCIMIENTO_CERT,
            ]);
        } catch (\App\Exceptions\PdfNoValido $e) {
            // El archivo no sirve (cortado, vacío o no es PDF) → 422 con SU mensaje: es el
            // único status en el que la pantalla muestra lo que dice el servidor. Con el 500
            // del catch de abajo el usuario leía "Error del servidor" y no sabía que lo que
            // tenía que hacer era volver a escanear el documento.
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Error subiendo documento de auxiliar a Google Drive: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al subir archivo: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Elimina un documento (propiedad/certificado) del auxiliar: borra el archivo de
     * Google Drive y limpia la columna en BD (+ la fecha de venc. si es certificado).
     * Espejo de uploadDoc. Permiso: super.admin (igual que equipos.deleteDoc).
     */
    public function deleteDoc(Request $request, $id)
    {
        $request->validate([
            'doc_type' => 'required|in:' . implode(',', array_keys(EquipoAuxiliar::DOCS)),
        ]);

        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('doc_type');
        $col  = EquipoAuxiliar::DOCS[$type];

        // Primero se limpia la BD y DESPUÉS se borra el archivo (Drive o, en los antiguos,
        // disco 'public'). A prueba de fallos: si el archivo ya no existe igual queda limpio.
        $anterior  = $aux->$col;
        $aux->$col = null;
        if ($type === 'certificado') {
            $aux->FECHA_VENCIMIENTO_CERT = null; // sin certificado no tiene sentido la fecha
        }
        $aux->save();
        EquipoAuxiliar::olvidarDoc($anterior);

        return response()->json([
            'success' => true,
            'message' => 'Documento eliminado correctamente.',
        ]);
    }

    /**
     * Actualiza solo la fecha de vencimiento del certificado, sin reemplazar
     * el PDF. Endpoint llamado desde el visor del PDF. Permiso: user.edit.
     */
    public function updateCertExpiry(Request $request, $id)
    {
        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        // Con certificado cargado la fecha no se puede vaciar (ver reglaFechaCert).
        $request->validate([
            'fecha_vencimiento_cert' => $this->reglaFechaCert(!empty($aux->LINK_CERTIFICADO)),
        ], self::MENSAJES_FECHA_CERT);
        // Y sin certificado no se puede poner (ver vetoFechaSinCertificado).
        if ($motivo = $this->vetoFechaSinCertificado($aux, $request->input('fecha_vencimiento_cert'))) {
            return response()->json(['success' => false, 'message' => $motivo], 422);
        }
        $aux->FECHA_VENCIMIENTO_CERT = $request->input('fecha_vencimiento_cert') ?: null;
        $aux->save();
        return response()->json([
            'success' => true,
            'message' => 'Fecha de vencimiento actualizada.',
            'fecha_vencimiento_cert' => $aux->FECHA_VENCIMIENTO_CERT,
        ]);
    }

    /**
     * Devuelve la metadata del aux para el panel lateral del visor PDF.
     * Estructura imitando EquipoController::metadata: {success, data}.
     * - propiedad: SERIAL, MARCA, MODELO, CAPACIDAD, ANIO, TIPO (datos de la
     *   ficha del aux — no hay tabla documentacion paralela como en vehiculos).
     * - certificado: FECHA_VENCIMIENTO_CERT + datos basicos para contexto.
     */
    public function metadata(Request $request, $id)
    {
        $aux  = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('type');
        $data = [];

        switch ($type) {
            case 'propiedad':
                $data = [
                    'serial'    => $aux->SERIAL ?? '',
                    'codigo'    => $aux->CODIGO_INTERNO ?? '',
                    'tipo'      => $aux->TIPO ?? '',
                    'marca'     => $aux->MARCA ?? '',
                    'modelo'    => $aux->MODELO ?? '',
                    'capacidad' => $aux->CAPACIDAD ?? '',
                    'anio'      => $aux->ANIO ?? '',
                ];
                break;

            case 'certificado':
                $data = [
                    'fecha_vencimiento' => $aux->FECHA_VENCIMIENTO_CERT
                        ? \Carbon\Carbon::parse($aux->FECHA_VENCIMIENTO_CERT)->format('Y-m-d')
                        : '',
                    'serial'    => $aux->SERIAL ?? '',
                    'tipo'      => $aux->TIPO ?? '',
                    'marca'     => $aux->MARCA ?? '',
                    'modelo'    => $aux->MODELO ?? '',
                    'capacidad' => $aux->CAPACIDAD ?? '',
                ];
                break;
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Guarda los datos editados desde el panel lateral del visor PDF.
     * doc_type=propiedad    => SERIAL, CODIGO_INTERNO, TIPO, MARCA, MODELO, CAPACIDAD y ANIO,
     *                          pero SOLO los que vengan en la peticion (ver abajo).
     * doc_type=certificado  => FECHA_VENCIMIENTO_CERT y nada mas.
     * Permiso: user.edit (gateado en routes/web.php).
     */
    public function updateMetadata(Request $request, $id)
    {
        $aux = EquipoAuxiliar::findOrFail($id);
        $this->authorizeAuxScope($aux);
        $type = $request->input('doc_type');

        $upd = [];
        if ($type === 'propiedad') {
            // SOLO se escriben los campos que vienen en la peticion, igual que en
            // EquipoController::updateMetadata. Antes se escribian todos tomando el que faltaba
            // como vacio, asi que una peticion incompleta —un cliente viejo, un reintento a
            // medias— BORRABA en silencio el SERIAL, y el SERIAL es justo el dato con el que
            // CargaMasivaDocumentos comprueba que un PDF es de este auxiliar: sin el, ese
            // auxiliar deja de poder verificarse.
            // Que un campo venga VACIO sigue queriendo decir "borralo": eso es el usuario
            // limpiando la casilla a proposito, y se distingue de que no venga.
            $enMayusculas = function (string $campo) use ($request): ?string {
                $v = trim((string) $request->input($campo, ''));
                return $v === '' ? null : mb_strtoupper($v);
            };
            // TIPO es la unica NOT NULL de las seis: vaciarla guarda cadena vacia, no null.
            foreach ([
                'serial' => 'SERIAL', 'codigo' => 'CODIGO_INTERNO', 'tipo' => 'TIPO',
                'marca' => 'MARCA', 'modelo' => 'MODELO', 'capacidad' => 'CAPACIDAD',
            ] as $campo => $columna) {
                if (! $request->has($campo)) {
                    continue;
                }
                $valor = $enMayusculas($campo);
                $upd[$columna] = ($valor === null && $columna === 'TIPO') ? '' : $valor;
            }
            if ($request->has('anio')) {
                $anio = trim((string) $request->input('anio', ''));
                $upd['ANIO'] = $anio === '' ? null : (int) $anio;
            }
        } elseif ($type === 'certificado') {
            // Con certificado cargado la fecha no se puede vaciar (ver reglaFechaCert). JSON
            // a mano: este POST llega por apiFetch sin 'Accept: application/json', y
            // validate() respondería con un redirect que el visor no sabe leer.
            $v = \Illuminate\Support\Facades\Validator::make(
                ['fecha_vencimiento_cert' => $request->input('fecha_vencimiento')],
                ['fecha_vencimiento_cert' => $this->reglaFechaCert(!empty($aux->LINK_CERTIFICADO))],
                self::MENSAJES_FECHA_CERT
            );
            if ($v->fails()) {
                return response()->json(['success' => false, 'message' => $v->errors()->first()], 422);
            }
            // Sin certificado cargado no se puede fijar su vencimiento.
            if ($motivo = $this->vetoFechaSinCertificado($aux, $request->input('fecha_vencimiento'))) {
                return response()->json(['success' => false, 'message' => $motivo], 422);
            }
            $upd['FECHA_VENCIMIENTO_CERT'] = $request->input('fecha_vencimiento') ?: null;
        } else {
            return response()->json(['success' => false, 'message' => 'Tipo no valido'], 400);
        }

        $aux->update($upd);

        return response()->json([
            'success' => true,
            'message' => 'Datos actualizados.',
            'data'    => $upd,
        ]);
    }

    /**
     * Asigna DETALLE_UBICACION_ACTUAL a un lote de auxiliares (mismo patron
     * que EquipoController::bulkUbicacion). Valida:
     *  - Permiso equipos.assign.
     *  - Que TODOS los auxiliares esten en el mismo frente (sino la
     *    "ubicacion especifica dentro del frente" pierde sentido).
     *  - Scope LOCAL del usuario (descarta IDs fuera de sus frentes).
     */
    public function bulkUbicacion(Request $request)
    {
        if (! auth()->user()?->can('equipos.assign')) {
            return response()->json(['success' => false, 'message' => 'Sin permisos'], 403);
        }

        $request->validate([
            'ids'               => 'required|array|min:1',
            'ids.*'             => 'exists:equipos_auxiliares,ID_AUXILIAR',
            // nullable: permite cadena vacía para borrar la ubicación existente.
            'detalle_ubicacion' => 'nullable|string|max:150',
        ]);

        $rawValor = $request->input('detalle_ubicacion', '');
        // Guardar NULL cuando el valor llega vacío (borra la ubicación en BD)
        $valor = ($rawValor !== null && trim($rawValor) !== '')
            ? mb_strtoupper(trim($rawValor))
            : null;

        return DB::transaction(function () use ($request, $valor) {
            $auxQuery = EquipoAuxiliar::whereIn('ID_AUXILIAR', $request->ids)
                ->lockForUpdate();
            // Solo auxiliares visibles: whitelist LOCAL + blacklist de bloqueados.
            $this->scopeFrentes($auxQuery, 'ID_FRENTE_ACTUAL');
            $auxiliares = $auxQuery->get(['ID_AUXILIAR', 'ID_FRENTE_ACTUAL']);

            if ($auxiliares->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'No se encontraron los auxiliares.'], 404);
            }

            $frentesUnicos = $auxiliares->pluck('ID_FRENTE_ACTUAL')->unique();
            if ($frentesUnicos->count() > 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'Todos los auxiliares deben estar en el mismo frente.',
                ], 422);
            }
            if ($frentesUnicos->first() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Los auxiliares seleccionados no tienen un frente asignado.',
                ], 422);
            }

            $count = EquipoAuxiliar::whereIn('ID_AUXILIAR', $auxiliares->pluck('ID_AUXILIAR'))
                ->update(['DETALLE_UBICACION_ACTUAL' => $valor]);

            return response()->json([
                'success'                  => true,
                'count'                    => $count,
                'DETALLE_UBICACION_ACTUAL' => $valor,
            ]);
        });
    }

    /**
     * PDFs del formulario de crear/editar, ya subidos a Drive: [columna => link], vacío si
     * no vino ninguno. Se llama ANTES de escribir en la BD (mismo orden que
     * EquipoController::store): si Drive falla no se guarda nada y ninguna fila queda
     * esperando a Google. Drive solo se conecta si vino algún archivo: getInstance() hace
     * red, y guardar un auxiliar sin PDFs no debe caerse por una falla de internet.
     */
    private function subirDocsFormulario(Request $request): array
    {
        $archivos = [];
        foreach (['doc_propiedad' => 'propiedad', 'certificado' => 'certificado'] as $campo => $tipo) {
            if ($request->hasFile($campo) && $request->file($campo)->isValid()) {
                $archivos[$tipo] = $request->file($campo);
            }
        }
        if (!$archivos) {
            return [];
        }

        try {
            $drive = \App\Services\GoogleDriveService::getInstance();
        } catch (\Throwable $e) {
            Log::error('Formulario de auxiliar: sin conexión con Google Drive: ' . $e->getMessage());
            abort(503, 'No hay conexión con Google Drive: el PDF no se pudo subir y el equipo auxiliar NO se guardó. Reintente en un momento.');
        }

        $links = [];
        try {
            foreach ($archivos as $tipo => $archivo) {
                $links[EquipoAuxiliar::DOCS[$tipo]] = EquipoAuxiliar::subirDocADrive($drive, $tipo, $archivo);
            }
        } catch (\App\Exceptions\PdfNoValido $e) {
            // Lo que rechaza el ARCHIVO (un PDF cortado a medias, vacío, o que no es un PDF):
            // no es un problema de conexión y decir "reintente" haría que la persona subiera
            // el mismo archivo roto una y otra vez. Va su mensaje, que dice qué hacer.
            array_map([EquipoAuxiliar::class, 'olvidarDoc'], $links);
            Log::warning('Formulario de auxiliar: el PDF no se pudo aceptar: ' . $e->getMessage());
            abort(422, $e->getMessage() . ' El equipo auxiliar NO se guardó.');
        } catch (\Throwable $e) {
            array_map([EquipoAuxiliar::class, 'olvidarDoc'], $links); // el que sí subió quedaría huérfano
            Log::error('Formulario de auxiliar: fallo subiendo PDF a Google Drive: ' . $e->getMessage());
            abort(503, 'No se pudo subir el PDF a Google Drive, así que el equipo auxiliar NO se guardó. Reintente en un momento.');
        }
        return $links;
    }

    // ═══════════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════════
    /**
     * Regla de FECHA_VENCIMIENTO_CERT. El certificado es el documento del auxiliar que
     * VENCE: con PDF de certificado (el que se sube o el que ya tiene) la fecha es
     * obligatoria. Un solo sitio para el formulario (validateData), la subida desde la
     * ficha (uploadDoc) y la edicion desde el visor (updateMetadata/updateCertExpiry).
     */
    private function reglaFechaCert(bool $hayCertificado): array
    {
        return ['nullable', 'date', Rule::requiredIf($hayCertificado)];
    }

    /**
     * Una fecha de vencimiento SIN certificado cargado no se guarda.
     *
     * Antes se podia fijar el vencimiento de un certificado que no existe: el listado lo
     * daba por no cargado (mira el enlace) y las alertas lo reclamaban vencido (miran la
     * fecha), asi que un auxiliar sin papeles aparecia en "por vencer" sin que nadie
     * entendiera por que. Es la otra mitad de la regla: fecha y documento van juntos o no
     * van ninguno.
     *
     * Devuelve el motivo del rechazo, o null si se puede seguir. Lo miran las puertas que
     * tocan esta fecha sin subir archivo: updateCertExpiry y updateMetadata; el formulario
     * (store/update) lo comprueba igual en validateData.
     */
    private function vetoFechaSinCertificado(EquipoAuxiliar $aux, $fecha): ?string
    {
        if (!empty($aux->LINK_CERTIFICADO) || !filled($fecha)) {
            return null;
        }

        return 'No hay certificado cargado: primero sube el PDF y su fecha va con el.';
    }

    private const MENSAJES_FECHA_CERT = [
        'fecha_vencimiento_cert.required' => 'La fecha de vencimiento del certificado es obligatoria.',
        'fecha_vencimiento_cert.date'     => 'La fecha de vencimiento del certificado no es válida.',
    ];

    private function validateData(Request $request, bool $isCreate = true): array
    {
        // ID del auxiliar actual (para excluirlo del check unique en update)
        $currentId = $request->route('id');

        // Normalizar ANTES de validar: uppercase + trim en campos donde
        // guardamos uppercase, para que el check unique compare consistente
        // (sino "ms-01" pasa unique aunque la BD tenga "MS-01" y al
        // guardar con strtoupper se crearia un duplicado logico).
        foreach (['SERIAL', 'CODIGO_INTERNO', 'MARCA', 'MODELO', 'CAPACIDAD', 'COMBUSTIBLE'] as $f) {
            if ($request->filled($f)) {
                $request->merge([$f => mb_strtoupper(trim($request->input($f)))]);
            }
        }

        // TIPO: si el usuario seleccionó una etiqueta legible del datalist
        // (ej. "Maquina de Soldar"), mapearla al codigo enum correspondiente
        // (MAQUINA_DE_SOLDAR) para preservar la consistencia con los registros
        // existentes. Comparacion case-insensitive. Si no hay match, se
        // normaliza como tipo custom (uppercase + underscores) — y las claves de
        // tiposLabel() estan escritas para COINCIDIR con esa normalizacion, para
        // que las dos vias produzcan el MISMO codigo y no se dupliquen tipos.
        if ($request->filled('TIPO')) {
            $input = trim($request->input('TIPO'));
            $labels = EquipoAuxiliar::tiposLabel(); // [code => label]
            $code = null;
            foreach ($labels as $k => $label) {
                if (mb_strtolower($label) === mb_strtolower($input)) { $code = $k; break; }
            }
            $request->merge(['TIPO' => $code ?? mb_strtoupper(preg_replace('/\s+/', '_', $input))]);
        }

        // TIPO y ESTADO_OPERATIVO son SIEMPRE requeridos, tanto en create como
        // en update: representan estado fundamental del registro. Si dejamos
        // 'sometimes|required|...' en update permitiria pasar string vacio via
        // JSON y el required no dispara. Mejor mantenerlos fuera del sometimes.
        // SERIAL: unique ignorando self en update (cuando esta presente).
        // max:60 en TIPO porque los codigos de tipos custom pueden superar 30 chars
        // (ej. GENERADOR_DIESEL_PORTATIL = 27 chars, pero nombres mas largos existen).
        $rules = [
            'TIPO'             => 'required|string|max:60',
            'ESTADO_OPERATIVO' => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::estadosLabel())),
            'MARCA'            => 'required|string|max:80',
            'MODELO'           => 'required|string|max:80',
            // FIX: Use Rule::unique() instead of string rule to exclude soft-deleted records
            // from the uniqueness check. String rule (unique:table,col) queries ALL rows
            // including deleted_at IS NOT NULL, which causes a false 422 when a deleted
            // auxiliary has the same SERIAL (banner appears with no visible red fields).
            'SERIAL'         => [
                'required', 'string', 'max:100',
                Rule::unique('equipos_auxiliares', 'SERIAL')
                    ->whereNull('deleted_at')
                    ->when($currentId, fn ($r) => $r->ignore($currentId, 'ID_AUXILIAR')),
            ],
            'CODIGO_INTERNO' => [
                'nullable', 'string', 'max:80',
                Rule::unique('equipos_auxiliares', 'CODIGO_INTERNO')
                    ->whereNull('deleted_at')
                    ->when($currentId, fn ($r) => $r->ignore($currentId, 'ID_AUXILIAR')),
            ],
            'CAPACIDAD'        => 'nullable|string|max:80',
            'ANIO'             => 'nullable|integer|min:1950|max:2100',
            // MISMA lista que `equipos`: una sola fuente para las dos tablas.
            'COMBUSTIBLE'      => 'nullable|in:' . implode(',', \App\Models\Equipo::COMBUSTIBLES),
            'CONSUMO_PROMEDIO' => 'nullable|numeric|min:0|max:99999',
            // FIX: exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL without ESTATUS_FRENTE=ACTIVO filter:
            // a record may be assigned to a frente that was deactivated later. The FK
            // just needs to exist in the table — active-only filtering belongs in the UI,
            // not in the save validation (otherwise editing becomes impossible).
            'ID_FRENTE_ACTUAL' => 'required|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL',
            'ID_EQUIPO_HOST'   => 'nullable|exists:equipos,ID_EQUIPO',
            'OBSERVACIONES'    => 'nullable|string|max:500',
            // Documentacion (opcional). En UPDATE aceptamos fecha pasada para no
            // bloquear edicion de registros con certificados ya vencidos. La fecha es
            // obligatoria si se sube certificado o si el auxiliar ya tiene uno.
            // Mismo tope central que el resto (ver MAX_PDF_KB): antes eran 10 MB aqui y
            // 3.000 KB en la comprobacion que corre despues de subir.
            'doc_propiedad'          => 'nullable|file|mimes:pdf|max:' . \App\Services\GoogleDriveService::MAX_PDF_KB,
            'certificado'            => 'nullable|file|mimes:pdf|max:' . \App\Services\GoogleDriveService::MAX_PDF_KB,
            'fecha_vencimiento_cert' => array_merge(
                $this->reglaFechaCert(
                    $request->hasFile('certificado')
                    || (!$isCreate && $currentId && EquipoAuxiliar::whereKey($currentId)->value('LINK_CERTIFICADO'))
                ),
                $isCreate ? ['after_or_equal:today'] : []
            ),
        ];

        // En update hacemos sometimes SOLO los nullable; required se mantiene.
        // FIX: handle both string rules and array rules (SERIAL/CODIGO_INTERNO use arrays).
        if (!$isCreate) {
            foreach ($rules as $k => $v) {
                $isNullable = is_array($v)
                    ? in_array('nullable', $v, true)
                    : (is_string($v) && strpos($v, 'nullable') !== false);
                if ($isNullable) {
                    $rules[$k] = is_array($v)
                        ? array_merge(['sometimes'], $v)
                        : 'sometimes|' . $v;
                }
            }
            // El frente NO se edita por datos (va por Movilización; update() lo descarta).
            // Opcional en update para no exigirlo y poder editar auxiliares SIN ASIGNAR.
            $rules['ID_FRENTE_ACTUAL'] = 'nullable|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL';
        }

        // Un CERTIFICADO NUEVO no se queda con la fecha del anterior.
        //
        // Hace falta comprobarlo aparte porque el bucle de arriba antepone `sometimes` a
        // todas las reglas con `nullable`, y `sometimes` NO evalua nada cuando la clave ni
        // siquiera viene: el `requiredIf` de reglaFechaCert se lo saltaba. Resultado medido:
        // un PATCH con el PDF del certificado y SIN la clave fecha_vencimiento_cert
        // respondia 200, cambiaba el enlace al documento nuevo y dejaba la fecha del viejo.
        // Con la clave vacia si fallaba, asi que el agujero solo se abria al omitirla.
        //
        // El `sometimes` se queda: es lo que permite editar la marca de un auxiliar sin
        // reenviar todos sus campos. Lo que se exige aqui es lo minimo — la fecha SOLO
        // cuando llega un certificado nuevo. Mismo criterio que EquipoController::update(),
        // que ya lo comprobaba asi para la poliza, el ROTC y el RACDA.
        if (!$isCreate && $request->hasFile('certificado') && !$request->filled('fecha_vencimiento_cert')) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'fecha_vencimiento_cert' => 'La fecha de vencimiento es obligatoria al cargar el certificado.',
            ]);
        }

        // Y la otra mitad (ver vetoFechaSinCertificado): el formulario tampoco pone la fecha de
        // un certificado que no hay. En la edicion solo si la fecha CAMBIA: una fecha huerfana
        // que ya estaba en la base vuelve rellena en el formulario, y rechazarla impediria
        // editar la marca de ese auxiliar.
        if ($request->filled('fecha_vencimiento_cert') && !$request->hasFile('certificado')) {
            $actual = $isCreate ? null : EquipoAuxiliar::whereKey($currentId)->first(['LINK_CERTIFICADO', 'FECHA_VENCIMIENTO_CERT']);
            $cambia = $isCreate || substr((string) $actual?->FECHA_VENCIMIENTO_CERT, 0, 10) !== substr((string) $request->input('fecha_vencimiento_cert'), 0, 10);
            if ($cambia && empty($actual?->LINK_CERTIFICADO)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'fecha_vencimiento_cert' => 'No hay certificado cargado: primero sube el PDF y su fecha va con el.',
                ]);
            }
        }

        $validated = $request->validate($rules, [
            'SERIAL.unique'         => 'El serial ingresado ya está registrado en otro equipo auxiliar.',
            'CODIGO_INTERNO.unique' => 'El código interno ingresado ya está registrado en otro equipo auxiliar.',
        ] + self::MENSAJES_FECHA_CERT);

        // Normaliza TIPO: uppercase + espacios por guiones_bajos para mantener consistencia
        // con los codigos existentes (MAQUINA_DE_SOLDAR, etc.) cuando el usuario escribe uno nuevo.
        if (isset($validated['TIPO'])) {
            $validated['TIPO'] = mb_strtoupper(preg_replace('/\s+/', '_', trim($validated['TIPO'])));
        }

        // Proteger tope ANCHOR_MAX_PER_HOST en create/update: si ID_EQUIPO_HOST
        // viene seteado y el host ya tiene N auxiliares distintos al actual,
        // rechazar. El endpoint anchor() tambien lo valida con lockForUpdate,
        // pero validar aqui tambien protege el set directo via form.
        if (!empty($validated['ID_EQUIPO_HOST'])) {
            $auxiliarId = $request->route('id');
            $existentes = EquipoAuxiliar::where('ID_EQUIPO_HOST', $validated['ID_EQUIPO_HOST'])
                ->when($auxiliarId, fn($q) => $q->where('ID_AUXILIAR', '!=', $auxiliarId))
                ->count();
            if ($existentes >= EquipoAuxiliar::ANCHOR_MAX_PER_HOST) {
                abort(422, 'El equipo host ya tiene el maximo de ' . EquipoAuxiliar::ANCHOR_MAX_PER_HOST . ' auxiliares anclados.');
            }
        }

        return $validated;
    }

    // ═══════════════════════════════════════════════════════════
    // CARGA MASIVA (Excel) — patron identico a /admin/equipos
    // ═══════════════════════════════════════════════════════════
    /**
     * Headers canonicos de la plantilla. El orden es vinculante: lo usa tanto
     * el generador (bulkTemplate) como el parser (bulkPreview) para validar.
     */
    private function bulkHeaderKeys(): array
    {
        return ['tipo', 'marca', 'modelo', 'serial', 'capacidad', 'año', 'frente de trabajo', 'estado'];
    }

    private function bulkHeaderLabels(): array
    {
        return ['Tipo', 'Marca', 'Modelo', 'Serial', 'Capacidad', 'Año', 'Frente de Trabajo', 'Estado'];
    }

    /**
     * Descarga plantilla XLSX para bulk upload. Incluye hoja oculta "_listas"
     * con data validation (dropdowns) para Tipo/Frente/Estado. Tipos custom
     * son permitidos (validation soft): el Excel muestra sugerencias pero no
     * bloquea escribir uno nuevo — se crea al guardar.
     */
    public function bulkTemplate(Request $request)
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Vidalsa')->setTitle('Plantilla Bulk Equipos Auxiliares');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Auxiliares');
        $sheet->fromArray([$this->bulkHeaderLabels()], null, 'A1');

        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF0067B1']],
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
        ]);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:H1');

        $colWidths = ['A' => 22, 'B' => 16, 'C' => 16, 'D' => 18, 'E' => 14, 'F' => 8, 'G' => 25, 'H' => 16];
        foreach ($colWidths as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }

        // Hoja oculta con listas para dropdowns
        $listSheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, '_listas');
        $spreadsheet->addSheet($listSheet);

        $tiposArr   = array_values(array_map(fn($l) => mb_strtoupper($l), $this->getTiposDinamicos()));
        // Solo los frentes que el usuario puede usar: la lista desplegable de la plantilla no
        // debe sugerir frentes que luego el guardado rechazará con 403.
        $frentesArr = $this->frentesActivosPermitidos()->pluck('NOMBRE_FRENTE')->toArray();
        $estadosArr = array_keys(EquipoAuxiliar::estadosLabel());

        $listSheet->fromArray([['Tipos']], null, 'A1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $tiposArr), null, 'A2');
        $listSheet->fromArray([['Frentes']], null, 'B1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $frentesArr), null, 'B2');
        $listSheet->fromArray([['Estados']], null, 'C1');
        $listSheet->fromArray(array_map(fn($v) => [$v], $estadosArr), null, 'C2');
        $listSheet->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);

        $addListValidation = function (string $column, string $formula, bool $soft = false, string $prompt = '') use ($sheet) {
            $v = $sheet->getCell($column . '2')->getDataValidation();
            $v->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
            $v->setErrorStyle($soft
                ? \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_INFORMATION
                : \PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
            $v->setAllowBlank(true);
            $v->setShowInputMessage(true);
            $v->setShowErrorMessage(!$soft);
            $v->setShowDropDown(true);
            if ($soft && $prompt) {
                $v->setPromptTitle('Tipo');
                $v->setPrompt($prompt);
            } else {
                $v->setErrorTitle('Valor no permitido');
                $v->setError('Selecciona un valor de la lista.');
            }
            $v->setFormula1($formula);
            $v->setSqref($column . '2:' . $column . '1001');
        };

        if (count($tiposArr) > 0) {
            $addListValidation('A', '_listas!$A$2:$A$' . (count($tiposArr) + 1), true, 'Selecciona de la lista o escribe uno nuevo (se creara al guardar).');
        }
        if (count($frentesArr) > 0) {
            $addListValidation('G', '_listas!$B$2:$B$' . (count($frentesArr) + 1));
        }
        $addListValidation('H', '_listas!$C$2:$C$' . (count($estadosArr) + 1));

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $filename = 'plantilla_equipos_auxiliares_' . now()->format('Y-m-d') . '.xlsx';
        $response = new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $response;
    }

    /**
     * Parsea el XLSX y devuelve JSON con filas + errores por fila. No crea
     * nada en BD. Resuelve TIPO/FRENTE a code/id para que el frontend edite
     * con selects y luego mande el batch final limpio.
     */
    public function bulkPreview(Request $request)
    {
        $request->validate([
            'archivo_excel' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $path = $request->file('archivo_excel')->getRealPath();
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('Auxiliares') ?? $spreadsheet->getActiveSheet();

        // Headers
        $expected = $this->bulkHeaderKeys();
        $actual = [];
        foreach (range('A', 'H') as $col) {
            $actual[] = mb_strtolower(trim((string) $sheet->getCell($col . '1')->getValue()));
        }
        if ($actual !== $expected) {
            return response()->json([
                'success' => false,
                'message' => 'Headers invalidos. Descarga la plantilla nuevamente.',
            ], 422);
        }

        $highestRow = $sheet->getHighestDataRow();
        if ($highestRow - 1 > 500) {
            return response()->json([
                'success' => false,
                'message' => 'El archivo supera 500 filas de datos.',
            ], 422);
        }

        // Lookups en memoria (1 llamada c/u, no por fila)
        $tiposMap = $this->getTiposDinamicos();            // [CODE => Label]
        $tiposByCodeLower  = [];
        $tiposByLabelLower = [];
        foreach ($tiposMap as $code => $label) {
            $tiposByCodeLower[mb_strtolower($code)]   = $code;
            $tiposByLabelLower[mb_strtolower($label)] = $code;
        }
        // Barrera de frentes (lista blanca LOCAL + lista negra de bloqueados), igual que el
        // bulk de equipos: un frente fuera del scope no se resuelve → la fila da error. Sin
        // esto la previa aceptaba cualquier frente activo y el store lo guardaba (el alta
        // individual sí abortaba con 403).
        $frentesMap = $this->frentesActivosPermitidos()->get()
            ->keyBy(fn($f) => mb_strtolower(trim($f->NOMBRE_FRENTE)));
        $validEstados = array_keys(EquipoAuxiliar::estadosLabel());

        // Pre-scan para detectar duplicados en archivo y contra BD
        $allSeriales = [];
        for ($n = 2; $n <= $highestRow; $n++) {
            $s = mb_strtoupper(trim((string) $sheet->getCell('D' . $n)->getValue()));
            if ($s !== '') $allSeriales[] = $s;
        }
        $duplicateSeriales = array_keys(array_filter(array_count_values($allSeriales), fn($c) => $c > 1));
        $existingSerialesBD = !empty($allSeriales)
            ? DB::table('equipos_auxiliares')
                // FIX: exclude soft-deleted records from the unique check.
                // Deleted records in the 'papelera' have the same SERIAL but are no
                // longer active — a new import should be allowed to reuse that serial.
                ->whereNull('deleted_at')
                ->whereIn(DB::raw('UPPER(SERIAL)'), $allSeriales)
                ->pluck('SERIAL')->map(fn($v) => mb_strtoupper($v))->toArray()
            : [];

        $rows = [];
        for ($n = 2; $n <= $highestRow; $n++) {
            $rawTipo    = trim((string) $sheet->getCell('A' . $n)->getValue());
            $rawMarca   = trim((string) $sheet->getCell('B' . $n)->getValue());
            $rawModelo  = trim((string) $sheet->getCell('C' . $n)->getValue());
            $rawSerial  = trim((string) $sheet->getCell('D' . $n)->getValue());
            $rawCap     = trim((string) $sheet->getCell('E' . $n)->getValue());
            $rawAnio    = trim((string) $sheet->getCell('F' . $n)->getValue());
            $rawFrente  = trim((string) $sheet->getCell('G' . $n)->getValue());
            $rawEstado  = trim((string) $sheet->getCell('H' . $n)->getValue());

            // Skip filas vacias
            if ($rawTipo === '' && $rawMarca === '' && $rawModelo === '' && $rawSerial === '') continue;

            $errors = [];
            $serialUpper = mb_strtoupper($rawSerial);
            $estadoUpper = mb_strtoupper($rawEstado);

            // Requeridos
            foreach (['tipo' => $rawTipo, 'marca' => $rawMarca, 'modelo' => $rawModelo, 'serial' => $rawSerial, 'estado' => $rawEstado] as $field => $val) {
                if ($val === '') $errors[$field] = 'Campo requerido.';
            }

            // TIPO: resolver a code. Match por label o por code (case-insensitive).
            // Si no matchea, normalizar como custom (UPPERCASE + _).
            $tipoCodigo = null;
            if ($rawTipo !== '') {
                $key = mb_strtolower($rawTipo);
                if (isset($tiposByCodeLower[$key])) {
                    $tipoCodigo = $tiposByCodeLower[$key];
                } elseif (isset($tiposByLabelLower[$key])) {
                    $tipoCodigo = $tiposByLabelLower[$key];
                } else {
                    $tipoCodigo = mb_strtoupper(preg_replace('/\s+/', '_', $rawTipo));
                }
            }

            // ESTADO
            if ($rawEstado !== '' && !in_array($estadoUpper, $validEstados)) {
                $errors['estado'] = 'Valor no valido. Opciones: ' . implode(', ', $validEstados) . '.';
            }

            // FRENTE (opcional)
            $idFrenteResuelto = null;
            if ($rawFrente !== '') {
                $fKey = mb_strtolower(trim($rawFrente));
                if (isset($frentesMap[$fKey])) {
                    $idFrenteResuelto = $frentesMap[$fKey]->ID_FRENTE;
                } else {
                    $errors['frente_de_trabajo'] = 'Frente no encontrado o inactivo.';
                }
            }

            // SERIAL unique
            if ($serialUpper !== '') {
                if (in_array($serialUpper, $existingSerialesBD)) {
                    $errors['serial'] = 'Ya registrado en BD.';
                } elseif (in_array($serialUpper, $duplicateSeriales)) {
                    $errors['serial'] = 'Duplicado dentro del archivo.';
                }
            }

            $anio = $rawAnio !== '' ? (int) $rawAnio : null;
            if ($anio !== null && ($anio < 1950 || $anio > 2100)) {
                $errors['año'] = 'Debe estar entre 1950 y 2100.';
            }

            $rows[] = [
                'row_index' => $n,
                'data' => [
                    'tipo'               => mb_strtoupper($rawTipo),
                    'tipo_codigo'        => $tipoCodigo,
                    'marca'              => mb_strtoupper($rawMarca),
                    'modelo'             => mb_strtoupper($rawModelo),
                    'serial'             => $serialUpper,
                    'capacidad'          => mb_strtoupper($rawCap),
                    'anio'               => $anio,
                    'frente'             => mb_strtoupper($rawFrente),
                    'id_frente_resuelto' => $idFrenteResuelto,
                    'estado'             => $estadoUpper,
                ],
                'errors' => $errors,
            ];
        }

        return response()->json([
            'success' => true,
            'rows'    => $rows,
            'options' => [
                'tipos'   => array_values(array_map(fn($l) => mb_strtoupper($l), $tiposMap)),
                // Mismo conjunto que acepta el store: el <select> de la tabla editable no
                // puede ofrecer un frente que al guardar devolverá 403.
                'frentes' => $this->frentesActivosPermitidos()
                                ->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
                                ->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]),
                'estados' => $validEstados,
            ],
        ]);
    }

    /**
     * Recibe el batch ya editado en el frontend y lo inserta en transaccion.
     * Si hay errores de BD en alguna fila, hace rollback completo y reporta
     * los fallos. Tipos custom nuevos simplemente se guardan con su code
     * normalizado (no requiere tabla de catalogo).
     */
    public function bulkStoreBatch(Request $request)
    {
        set_time_limit(600);

        $validator = \Validator::make($request->all(), [
            'rows'                       => 'required|array|min:1|max:500',
            'rows.*.tipo_codigo'         => 'required|string|max:60',
            'rows.*.marca'               => 'required|string|max:80',
            'rows.*.modelo'              => 'required|string|max:80',
            'rows.*.serial'              => 'required|string|max:100',
            'rows.*.capacidad'           => 'nullable|string|max:80',
            'rows.*.anio'                => 'nullable|integer|min:1950|max:2100',
            'rows.*.id_frente_resuelto'  => 'nullable|integer|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL',
            'rows.*.estado'              => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::estadosLabel())),
        ], [
            // Mensajes en español por fila (regla :input/:position se reemplaza por el numero de fila +2 = numero real en el Excel)
            'rows.*.tipo_codigo.required'        => 'Fila :position: el Tipo es obligatorio.',
            'rows.*.tipo_codigo.max'             => 'Fila :position: el Tipo no puede exceder 60 caracteres.',
            'rows.*.marca.required'              => 'Fila :position: la Marca es obligatoria.',
            'rows.*.marca.max'                   => 'Fila :position: la Marca no puede exceder 80 caracteres.',
            'rows.*.modelo.required'             => 'Fila :position: el Modelo es obligatorio.',
            'rows.*.modelo.max'                  => 'Fila :position: el Modelo no puede exceder 80 caracteres.',
            'rows.*.serial.required'             => 'Fila :position: el Serial es obligatorio.',
            'rows.*.serial.max'                  => 'Fila :position: el Serial no puede exceder 100 caracteres.',
            'rows.*.capacidad.max'               => 'Fila :position: la Capacidad no puede exceder 80 caracteres.',
            'rows.*.anio.integer'                => 'Fila :position: el Año debe ser un número entero.',
            'rows.*.anio.min'                    => 'Fila :position: el Año debe ser mayor o igual a 1950.',
            'rows.*.anio.max'                    => 'Fila :position: el Año debe ser menor o igual a 2100.',
            'rows.*.id_frente_resuelto.integer'  => 'Fila :position: el Frente seleccionado no es válido.',
            'rows.*.id_frente_resuelto.exists'   => 'Fila :position: el Frente seleccionado no existe o está inactivo.',
            'rows.*.estado.required'             => 'Fila :position: el Estado es obligatorio.',
            'rows.*.estado.in'                   => 'Fila :position: el Estado seleccionado no es válido.',
            'rows.required'                      => 'Debes incluir al menos una fila para cargar.',
            'rows.array'                         => 'El formato del lote es inválido.',
            'rows.min'                           => 'Debes incluir al menos una fila para cargar.',
            'rows.max'                           => 'Solo se pueden cargar hasta 500 filas por lote.',
        ]);

        if ($validator->fails()) {
            $errs   = $validator->errors();
            $first  = $errs->first();
            $count  = count($errs->all());
            $summary = $first;
            if ($count > 1) {
                $extra = $count - 1;
                $summary .= ' (y ' . $extra . ' error' . ($extra > 1 ? 'es' : '') . ' más)';
            }
            return response()->json([
                'success' => false,
                'message' => $summary,
                'errors'  => $errs->toArray(),
            ], 422);
        }
        $data = $validator->validated();

        // Unicidad de SERIAL cross-batch y contra BD (defensa final server-side)
        $seriales = array_map(fn($r) => mb_strtoupper(trim($r['serial'])), $data['rows']);
        $dupEnBatch = array_keys(array_filter(array_count_values($seriales), fn($c) => $c > 1));
        if (!empty($dupEnBatch)) {
            return response()->json([
                'success' => false,
                'message' => 'Hay seriales duplicados en el batch: ' . implode(', ', $dupEnBatch),
            ], 422);
        }
        $conflictsBD = DB::table('equipos_auxiliares')
            // FIX: exclude soft-deleted records — same fix as bulkPreview.
            // A serial from the 'papelera' should not block a fresh import.
            ->whereNull('deleted_at')
            ->whereIn(DB::raw('UPPER(SERIAL)'), $seriales)
            ->pluck('SERIAL')->toArray();
        if (!empty($conflictsBD)) {
            return response()->json([
                'success' => false,
                'message' => 'Algun serial ya existe en BD: ' . implode(', ', $conflictsBD),
            ], 422);
        }

        // Frentes: `id_frente_resuelto` llega del CLIENTE (el JS lo saca de un data-attr de
        // la fila), así que `exists:frentes_trabajo` no basta — un id manipulado, o el de un
        // frente fuera del scope/bloqueado, entraba tal cual. El alta individual sí lo corta
        // con 403 (ver store()). Aquí resolvemos el conjunto permitido UNA vez y rechazamos
        // el lote completo si alguna fila apunta fuera: mismo criterio de barrera que el
        // bulk de equipos (lista blanca LOCAL + lista negra de bloqueados, también GLOBAL).
        $frentesPermitidos = $this->frentesActivosPermitidos()->pluck('ID_FRENTE')
            ->map(fn ($id) => (int) $id)->all();

        $filasFuera = [];
        foreach ($data['rows'] as $i => $row) {
            $idFrente = $row['id_frente_resuelto'] ?? null;
            if ($idFrente === null) continue; // sin frente = "Sin Asignar", permitido
            if (!in_array((int) $idFrente, $frentesPermitidos, true)) {
                $filasFuera[] = $i + 2; // +2 = nº de fila real en el Excel (cabecera + base 0)
            }
        }
        if (!empty($filasFuera)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para registrar auxiliares en el frente indicado (fila '
                    . implode(', ', $filasFuera) . ').',
            ], 403);
        }

        $creadoPor = auth()->id();
        $now = now();

        DB::beginTransaction();
        try {
            $batch = [];
            foreach ($data['rows'] as $row) {
                $batch[] = [
                    'TIPO'             => mb_strtoupper(preg_replace('/\s+/', '_', $row['tipo_codigo'])),
                    'MARCA'            => mb_strtoupper(trim($row['marca'])),
                    'MODELO'           => mb_strtoupper(trim($row['modelo'])),
                    'SERIAL'           => mb_strtoupper(trim($row['serial'])),
                    'CAPACIDAD'        => !empty($row['capacidad']) ? mb_strtoupper(trim($row['capacidad'])) : null,
                    'ANIO'             => $row['anio'] ?? null,
                    'ID_FRENTE_ACTUAL' => $row['id_frente_resuelto'] ?? null,
                    'ESTADO_OPERATIVO' => $row['estado'],
                    'CREADO_POR'       => $creadoPor,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ];
            }
            EquipoAuxiliar::insert($batch);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Se registraron ' . count($batch) . ' equipo(s) auxiliar(es).',
                'count'   => count($batch),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('bulkStoreBatch auxiliares: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al guardar el batch: ' . $e->getMessage(),
            ], 500);
        }
    }
}
