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
        'MODELO', 'TIPO', 'MOTOR',
        'ACEITE_MOTOR', 'ACEITE_CAJA', 'LIGA_FRENO', 'REFRIGERANTE', 'TIPO_BATERIA',
    ];

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('can:equipos.create')->only(['store', 'update', 'uploadFoto', 'asegurarFicha']);
        // elegir: el modal "Vincular a una ficha" de /admin/equipos, que solo abre super.admin.
        $this->middleware('can:super.admin')->only(['destroy', 'deleteFoto', 'elegir']);
    }

    /** Reglas de validación compartidas por store y update. */
    private function validationRules(): array
    {
        return [
            'MODELO'             => 'required|max:50',
            'TIPO'               => 'nullable|max:35',
            'ANIO_ESPEC'         => 'required|integer',
            'MOTOR'              => 'nullable|max:150',
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
        // Los vehículos cuentan las fichas Y los equipos sin ficha, que también tienen su
        // tarjeta (modelosSinFicha): un filtro que no los ofreciera los dejaría inalcanzables.
        $tiposVehiculo = TipoEquipo::where(fn ($w) => $w
                ->whereIn('nombre', fn ($q) => $q->select('TIPO')->from('caracteristicas_modelo')->whereNotNull('TIPO'))
                ->orWhereIn('id', fn ($q) => $q->select('id_tipo_equipo')->from('equipos')->whereNull('deleted_at')))
            ->orderBy('nombre')->get(['id', 'nombre']);
        $tiposAux = $this->tiposAuxLabels();
        $modelosVehiculo = CaracteristicaModelo::whereNotNull('MODELO')->where('MODELO', '!=', '')->pluck('MODELO')
            ->merge(Equipo::whereNotNull('MODELO')->where('MODELO', '!=', '')->distinct()->pluck('MODELO'))
            ->map(fn ($m) => mb_strtoupper(trim($m)))->unique()->sort()->values();
        $modelosAux = EquipoAuxiliar::whereNotNull('MODELO')->where('MODELO', '!=', '')
            ->distinct()->orderBy('MODELO')->pluck('MODELO');
        $aniosVehiculo = CaracteristicaModelo::whereNotNull('ANIO_ESPEC')->distinct()->pluck('ANIO_ESPEC')
            ->merge(Equipo::whereNotNull('ANIO')->where('ANIO', '!=', 0)->distinct()->pluck('ANIO'));
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
            $q = $this->consultaFichas();
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
            $items = $this->tarjetasDeFichas($q->orderBy('MODELO')->get());

            // Los modelos que tienen equipos pero todavía NO tienen ficha también salen, como
            // en los auxiliares: el catálogo se arma solo con lo registrado. Ver modelosSinFicha().
            $items = $items->merge($this->modelosSinFicha($tipoFiltro, $modeloFiltro, $anio));
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

    /**
     * Fichas con lo que necesitan sus tarjetas. withCount('equipos'): nº de equipos ligados
     * a cada modelo (por ID_ESPEC), en UNA subconsulta (sin N+1) — la cantidad de la
     * tarjeta, como las unidades de los auxiliares. with('colores'): la foto de cada color.
     */
    private function consultaFichas()
    {
        return CaracteristicaModelo::withCount('equipos')->with('colores:ID_COLOR,ID_ESPEC,COLOR,FOTO');
    }

    /**
     * Tarjetas VEHÍCULO de unas fichas (de consultaFichas). Las usan el catálogo y el modal
     * "Vincular a una ficha" de /admin/equipos (elegir): así las dos muestran lo mismo.
     */
    private function tarjetasDeFichas($fichas)
    {
        // Colores de las unidades de estas fichas, en UNA consulta: [ID_ESPEC => filas].
        $coloresUnidades = Equipo::whereIn('ID_ESPEC', $fichas->pluck('ID_ESPEC'))
            ->whereNotNull('COLOR')->where('COLOR', '!=', '')
            ->selectRaw('ID_ESPEC, COLOR, COUNT(*) AS n')
            ->groupBy('ID_ESPEC', 'COLOR')
            ->get()->groupBy('ID_ESPEC');

        // toBase(): sin fichas, el map de Eloquent devuelve una colección Eloquent vacía y el
        // merge con las tarjetas SIN FICHA (arrays) fallaría buscando getKey().
        return $fichas->toBase()->map(fn ($cm) => [
            'clase'       => 'VEHICULO',
            'id'          => $cm->ID_ESPEC,
            'tipo'        => $cm->TIPO,
            'modelo'      => $cm->MODELO,
            'marca'       => null,
            'anio'        => $cm->ANIO_ESPEC,
            'foto_url'    => $this->miniatura($cm->FOTO_REFERENCIAL),
            'placeholder' => 'precision_manufacturing',
            'total'       => $cm->equipos_count, // nº de equipos ligados a este modelo
            'colores'     => $this->coloresDeTarjeta($coloresUnidades->get($cm->ID_ESPEC, collect()), $cm->colores),
            'specs'       => array_filter([
                'Motor'        => $cm->MOTOR,
                'Batería'      => $cm->TIPO_BATERIA,
                'Aceite Motor' => $cm->ACEITE_MOTOR,
                'Aceite Caja'  => $cm->ACEITE_CAJA,
                'Liga Freno'   => $cm->LIGA_FRENO,
                'Refrigerante' => $cm->REFRIGERANTE,
            ], fn ($v) => $v !== null && $v !== ''),
            'sort'        => '0_' . $cm->MODELO,
        ])->values();
    }

    /** Máximo de fichas por búsqueda del modal "Vincular a una ficha". */
    private const MAX_ELEGIR = 60;

    /**
     * Fichas para el modal "Vincular a una ficha" de /admin/equipos (doble clic en la foto
     * de un equipo, solo super.admin). Busca por modelo o tipo (contiene) y por año, y
     * devuelve las mismas tarjetas del catálogo. El vínculo lo guarda
     * EquipoController::vincularFicha. La lista de años para el filtro va solo si se pide
     * (con_anios=1): el modal la carga una vez, no en cada tecla.
     */
    public function elegir(Request $request)
    {
        $texto = mb_strtoupper(trim((string) $request->input('q', '')));
        $anio  = (int) $request->input('anio', 0);

        $q = $this->consultaFichas();
        if ($texto !== '') {
            $q->where(fn ($w) => $w->where('MODELO', 'like', '%' . $texto . '%')->orWhere('TIPO', 'like', '%' . $texto . '%'));
        }
        if ($anio > 0) {
            $q->where('ANIO_ESPEC', $anio);
        }
        $fichas = $q->orderBy('MODELO')->orderByDesc('ANIO_ESPEC')->limit(self::MAX_ELEGIR + 1)->get();

        return response()->json(array_filter([
            'items'   => $this->tarjetasDeFichas($fichas->take(self::MAX_ELEGIR)),
            'hay_mas' => $fichas->count() > self::MAX_ELEGIR,
            'anios'   => $request->boolean('con_anios')
                ? CaracteristicaModelo::whereNotNull('ANIO_ESPEC')->distinct()->orderByDesc('ANIO_ESPEC')->pluck('ANIO_ESPEC')
                : null,
        ], fn ($v) => $v !== null));
    }

    /** Miniatura (300 px) de una foto del catálogo guardada como /storage/google/{id}. */
    private function miniatura(?string $ruta): ?string
    {
        $id = CaracteristicaModelo::idDrive($ruta);
        return $id ? url('/storage/google/' . $id . '?sz=w300') : null;
    }

    /**
     * Colores de una tarjeta: los que tienen sus unidades (con cuántas) y los que tienen
     * foto en el catálogo aunque hoy no haya unidades de ese color. Nombres normalizados
     * (CatalogoColor::normalizar: ROJA y ROJO son el mismo). De más a menos unidades.
     *
     * @param  \Illuminate\Support\Collection  $unidades  filas {COLOR, n}
     * @param  \Illuminate\Support\Collection  $fotos     CatalogoColor de la ficha (vacío sin ficha)
     */
    private function coloresDeTarjeta($unidades, $fotos): array
    {
        $colores = [];
        foreach ($unidades as $u) {
            $c = \App\Models\CatalogoColor::normalizar($u->COLOR);
            if ($c !== null) {
                $colores[$c]['total'] = ($colores[$c]['total'] ?? 0) + (int) $u->n;
            }
        }
        foreach ($fotos as $f) {
            $colores[$f->COLOR]['foto'] = $f->FOTO;
        }

        return collect($colores)->map(fn ($v, $c) => [
            'color'    => $c,
            'total'    => $v['total'] ?? 0,
            'foto_url' => $this->miniatura($v['foto'] ?? null),
            'muestra'  => \App\Models\CatalogoColor::muestra($c),
        ])->sortBy([['total', 'desc'], ['color', 'asc']])->values()->all();
    }

    /**
     * Modelos de equipo que tienen unidades registradas pero ninguna ficha: una tarjeta por
     * TIPO + MARCA + MODELO + AÑO, igual que el catálogo de auxiliares, que se arma solo con
     * lo registrado. Al subirle una foto o pulsar "Crear ficha" se crea la ficha y se le
     * enlazan sus unidades (asegurarFicha).
     *
     * Si ya hay una ficha con ese MODELO + año (unidades registradas después de crearla, que
     * el enganche no alcanzó) la tarjeta lo dice y el botón las enlaza a ella en vez de crear
     * otra: no se vuelven a duplicar fichas.
     */
    private function modelosSinFicha(string $tipoFiltro, string $modeloFiltro, string $anio)
    {
        $base = Equipo::query()
            ->leftJoin('tipo_equipos as t', 't.id', '=', 'equipos.id_tipo_equipo')
            ->where(fn ($q) => $q->whereNull('equipos.ID_ESPEC')
                ->orWhereNotIn('equipos.ID_ESPEC', CaracteristicaModelo::select('ID_ESPEC')))
            ->whereNotNull('equipos.MODELO')->where('equipos.MODELO', '!=', '');
        // Mismo alcance que los auxiliares: el usuario ve los modelos de sus frentes.
        if ($user = auth()->user()) {
            $user->aplicarScopeFrentesEquipos($base, 'equipos.ID_FRENTE_ACTUAL');
        }
        if (str_starts_with($tipoFiltro, 'tipo_eq:')) {
            $base->where('t.id', (int) substr($tipoFiltro, 8));
        }
        if (str_starts_with($modeloFiltro, 'modelo_eq:')) {
            $base->whereRaw('UPPER(TRIM(equipos.MODELO)) = ?', [mb_strtoupper(trim(substr($modeloFiltro, 10)))]);
        }
        if ($anio !== '' && $anio !== 'all') {
            $base->where('equipos.ANIO', $anio);
        }

        $clave = "UPPER(TRIM(COALESCE(t.nombre, ''))), UPPER(TRIM(COALESCE(equipos.MARCA, ''))), UPPER(TRIM(equipos.MODELO)), COALESCE(equipos.ANIO, 0)";
        $grupos = (clone $base)
            ->selectRaw("UPPER(TRIM(COALESCE(t.nombre, ''))) AS TIPO_KEY, UPPER(TRIM(COALESCE(equipos.MARCA, ''))) AS MARCA_KEY,"
                . " UPPER(TRIM(equipos.MODELO)) AS MODELO_KEY, COALESCE(equipos.ANIO, 0) AS ANIO_KEY,"
                . " COUNT(*) AS total, MAX(equipos.FOTO_EQUIPO) AS FOTO")
            ->groupByRaw($clave)
            ->get();
        if ($grupos->isEmpty()) {
            return collect();
        }

        $colores = (clone $base)->whereNotNull('equipos.COLOR')->where('equipos.COLOR', '!=', '')
            ->selectRaw("UPPER(TRIM(COALESCE(t.nombre, ''))) AS TIPO_KEY, UPPER(TRIM(COALESCE(equipos.MARCA, ''))) AS MARCA_KEY,"
                . " UPPER(TRIM(equipos.MODELO)) AS MODELO_KEY, COALESCE(equipos.ANIO, 0) AS ANIO_KEY, equipos.COLOR AS COLOR, COUNT(*) AS n")
            ->groupByRaw($clave . ', equipos.COLOR')
            ->get()
            ->groupBy(fn ($r) => "{$r->TIPO_KEY}|{$r->MARCA_KEY}|{$r->MODELO_KEY}|{$r->ANIO_KEY}");

        // Fichas que ya existen para esos MODELO + año (una consulta).
        $fichas = CaracteristicaModelo::whereIn('MODELO', $grupos->pluck('MODELO_KEY')->unique())
            ->get(['ID_ESPEC', 'MODELO', 'ANIO_ESPEC'])
            ->keyBy(fn ($f) => mb_strtoupper(trim($f->MODELO)) . '|' . (int) $f->ANIO_ESPEC);

        return $grupos->map(function ($g) use ($colores, $fichas) {
            $anio = (int) $g->ANIO_KEY ?: null;
            return [
                'clase'          => 'VEHICULO',
                'id'             => null,
                'sin_ficha'      => true,
                'ficha_existente'=> $fichas->get($g->MODELO_KEY . '|' . (int) $g->ANIO_KEY)?->ID_ESPEC,
                'tipo'           => $g->TIPO_KEY !== '' ? $g->TIPO_KEY : null,
                'modelo'         => $g->MODELO_KEY,
                'marca'          => $g->MARCA_KEY !== '' ? $g->MARCA_KEY : null,
                'anio'           => $anio,
                'foto_url'       => $this->miniatura($g->FOTO),
                'placeholder'    => 'precision_manufacturing',
                'total'          => (int) $g->total,
                'colores'        => $this->coloresDeTarjeta(
                    $colores->get("{$g->TIPO_KEY}|{$g->MARCA_KEY}|{$g->MODELO_KEY}|{$g->ANIO_KEY}", collect()), collect()),
                'specs'          => array_filter(['Marca' => $g->MARCA_KEY !== '' ? $g->MARCA_KEY : null]),
                // Con las fichas, por modelo: una tarjeta sin ficha se lee junto a las demás.
                'sort'           => '0_' . $g->MODELO_KEY,
            ];
        })->values();
    }

    /**
     * POST catalogo/asegurar-ficha — la ficha de un MODELO + año: la que ya existe o una
     * nueva con solo tipo, modelo y año (lo técnico se completa después con "Editar"). En
     * los dos casos se le enlazan sus unidades sueltas. Lo usa el catálogo al subir una foto
     * o pulsar "Crear ficha" en una tarjeta de un modelo que todavía no tenía ficha.
     */
    public function asegurarFicha(Request $request)
    {
        $data = $request->validate([
            'modelo' => 'required|string|max:50',
            'anio'   => 'required|integer|min:1900|max:2100',
            'tipo'   => 'nullable|string|max:35',
        ]);
        $modelo = mb_strtoupper(trim($data['modelo']));
        $anio   = (int) $data['anio'];

        $catalogo = CaracteristicaModelo::where('MODELO', $modelo)->where('ANIO_ESPEC', $anio)->orderBy('ID_ESPEC')->first();
        $creada   = false;
        if (!$catalogo) {
            $catalogo = CaracteristicaModelo::create([
                'MODELO'     => $modelo,
                'ANIO_ESPEC' => $anio,
                'TIPO'       => !empty($data['tipo']) ? mb_strtoupper(trim($data['tipo'])) : null,
            ]);
            $creada = true;
            \App\Models\CatalogoAuditLog::registrar($catalogo->ID_ESPEC, 'create', $modelo, $anio, ['MODELO' => $modelo, 'ANIO_ESPEC' => $anio]);
        }
        $this->autoLinkEquiposToCatalogo($catalogo, $modelo, $anio, $creada ? 'create' : 'update');

        return response()->json([
            'success' => true,
            'id'      => $catalogo->ID_ESPEC,
            'creada'  => $creada,
            'message' => $creada ? "Ficha de {$modelo} {$anio} creada." : "Unidades enlazadas a la ficha de {$modelo} {$anio}.",
        ]);
    }

    /**
     * Mapa TIPO => etiqueta de auxiliares para el catálogo, EN MAYÚSCULAS y solo con los
     * tipos que EXISTEN de verdad — mismo criterio que $tiposVehiculo, que también se acota
     * a los tipos presentes en el catálogo (y con el mismo scope de frentes que la lista, así
     * el filtro no ofrece opciones que a ese usuario le darían cero resultados).
     *
     * Antes se partía del mapa fijo EquipoAuxiliar::tiposLabel() y se le añadían los tipos
     * sueltos, lo que dejaba dos defectos en el filtro "Tipo":
     *   · Opciones muertas: 'OTRO' no lo usa ningún auxiliar.
     *   · Los auxiliares se veían en minúscula ("Planta Eléctrica") junto a los vehículos,
     *     que van en mayúscula porque TipoEquipo.nombre se guarda así.
     * El mapa fijo se sigue usando como fuente de la etiqueta bonita (CONTAINER → CONTENEDOR);
     * para los tipos que no están en él, el propio TIPO sin guiones bajos.
     */
    private function tiposAuxLabels(): array
    {
        $fijos = EquipoAuxiliar::tiposLabel();

        $q = EquipoAuxiliar::query();
        if ($user = auth()->user()) {
            $user->aplicarScopeFrentesEquipos($q, 'ID_FRENTE_ACTUAL');
        }
        $presentes = $q->whereNotNull('TIPO')->where('TIPO', '!=', '')
            ->distinct()->orderBy('TIPO')->pluck('TIPO');

        $tipos = [];
        foreach ($presentes as $t) {
            $tipos[$t] = mb_strtoupper($fijos[$t] ?? str_replace('_', ' ', $t));
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
        if ($r = $this->rechazarFichaRepetida($request, $validated)) {
            return $r;
        }

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
        if ($r = $this->rechazarFichaRepetida($request, $validated, (int) $catalogo->ID_ESPEC)) {
            return $r;
        }

        try {
            $oldModelo = $catalogo->MODELO;
            $oldAnio = $catalogo->ANIO_ESPEC;
            // Snapshot original para diff de auditoria (solo campos auditables)
            $auditFields = ['MODELO','ANIO_ESPEC','MOTOR',
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
     * Una ficha por MODELO + año. Otra igual solo servía para tener otra foto (así nacieron
     * las 5 copias de la pick-up SINOTRUK); ahora la foto de cada color va dentro de la ficha
     * (catalogo_colores), y una copia volvería a dejar las unidades sin saber cuál es la suya
     * (el enganche automático se bloquea con dos fichas). Devuelve la respuesta de rechazo o
     * null si se puede guardar.
     */
    private function rechazarFichaRepetida(Request $request, array $validated, ?int $exceptoId = null)
    {
        $existe = CaracteristicaModelo::where('MODELO', mb_strtoupper(trim($validated['MODELO'])))
            ->where('ANIO_ESPEC', $validated['ANIO_ESPEC'])
            ->when($exceptoId, fn ($q) => $q->where('ID_ESPEC', '!=', $exceptoId))
            ->exists();
        if (!$existe) {
            return null;
        }

        $msg = 'Ya existe la ficha de ese modelo y año. Para otro color no hace falta otra ficha: '
             . 'su foto se agrega en la tarjeta del catálogo, eligiendo el color.';
        return $request->wantsJson()
            ? response()->json(['success' => false, 'message' => $msg, 'errors' => ['MODELO' => [$msg]]], 422)
            : back()->withInput()->withErrors(['MODELO' => $msg]);
    }

    /**
     * Punto ÚNICO de la lógica de foto del catálogo: sube el WebP a Drive, apunta la foto
     * del modelo —o la del $color, si se indica— a la nueva (CaracteristicaModelo::
     * guardarFoto) y SOLO entonces borra la anterior de Drive + caché (best-effort).
     * Devuelve la ruta nueva, o null si Drive falla, para que el caller aborte en vez de
     * dejar el catálogo apuntando a una foto que no existe.
     * Debe llamarse DENTRO de la transacción que persiste el catálogo.
     * Lo llama uploadFoto(); store()/update() no tocan la foto (ver sus comentarios).
     *
     * @return array{nueva:string, anterior:?string}|null
     */
    private function reemplazarFotoCatalogo(CaracteristicaModelo $catalogo, $webpFile, ?string $color = null): ?array
    {
        $driveService = GoogleDriveService::getInstance();
        $folderId = config('filesystems.disks.google.catalog_folder');
        $filename = 'catalog_' . (int)(microtime(true) * 1000) . '_' . $catalogo->ID_ESPEC . '.webp';

        $driveFile = $driveService->uploadFile($folderId, $webpFile, $filename, 'image/webp');
        if (!$driveFile || !isset($driveFile->id)) {
            return null;
        }

        // Apuntar a la foto nueva ANTES de borrar la vieja.
        $nueva    = '/storage/google/' . $driveFile->id;
        $anterior = $catalogo->guardarFoto($nueva, $color);

        // Borrar la anterior solo tras subir + persistir la nueva, y solo si ninguna otra foto
        // del catálogo la usa (la del modelo y la de un color pueden ser el mismo archivo).
        $idAnterior = CaracteristicaModelo::idDrive($anterior);
        if ($idAnterior && $idAnterior !== $driveFile->id && !CaracteristicaModelo::fotoSigueEnUso($idAnterior)) {
            try {
                $driveService->deleteFile($idAnterior);
                \App\Services\GoogleDriveService::olvidarCopiaLocal($idAnterior);
            } catch (\Exception $e) {
                Log::warning('No se pudo borrar la foto anterior de Drive: ' . $idAnterior . ' - ' . $e->getMessage());
            }
        }

        return ['nueva' => $nueva, 'anterior' => $anterior];
    }

    /**
     * Subida de foto SOLO (sin abrir el formulario de edición). Se dispara al hacer
     * click en la foto de cada tarjeta en /admin/catalogo — misma UX que el catálogo
     * de auxiliares. Con `color` la foto es la de ESE color del modelo (catalogo_colores);
     * sin él, la del modelo. Reusa reemplazarFotoCatalogo() y re-evalúa el auto-link para
     * que los equipos del modelo hereden la nueva imagen.
     */
    public function uploadFoto(Request $request, $id)
    {
        @set_time_limit(120);

        $request->validate(
            [
                'foto'  => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
                'color' => 'nullable|string|max:50',
            ],
            [
                'foto.required' => 'Selecciona una imagen.',
                'foto.image'    => 'El archivo debe ser una imagen.',
                'foto.mimes'    => 'Formatos permitidos: JPG, PNG o WEBP.',
                'foto.max'      => 'La imagen no debe superar los 5 MB.',
            ]
        );

        $catalogo = CaracteristicaModelo::findOrFail($id);
        $color    = \App\Models\CatalogoColor::normalizar($request->input('color'));

        try {
            // Convertir a WebP ANTES de la transacción (evita problemas de $this en closures).
            $webpResult   = $this->convertToWebp($request->file('foto'));
            $webpFile     = $webpResult['file'];
            $tempWebpPath = $webpResult['tempPath'];

            $fotos = DB::transaction(function () use ($catalogo, $webpFile, $color) {
                $fotos = $this->reemplazarFotoCatalogo($catalogo, $webpFile, $color);
                if (!$fotos) {
                    throw new \RuntimeException('La subida a Google Drive falló.');
                }
                return $fotos;
            });
            $nuevaUrl = $fotos['nueva'];

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
                [($color ? 'foto ' . $color : 'foto') => ['antes' => $fotos['anterior'], 'despues' => $nuevaUrl]]
            );

            return response()->json([
                'success' => true,
                'message' => $color ? "Foto del color {$color} actualizada." : 'Foto del modelo actualizada correctamente.',
                'foto'    => $nuevaUrl,
                'color'   => $color,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error subiendo foto de catálogo ID ' . $id . ': ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar la foto: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Borra la foto del modelo o, con ?color=X, la de ese color (la fila del color se va:
     * sin foto no aporta nada). El archivo de Drive solo se borra si ninguna otra foto
     * del catálogo lo sigue usando.
     */
    public function deleteFoto(Request $request, $id)
    {
        $catalogo = CaracteristicaModelo::findOrFail($id);
        $color    = \App\Models\CatalogoColor::normalizar($request->input('color'));
        $filaColor = $color ? $catalogo->colores()->where('COLOR', $color)->first() : null;
        $ruta     = $color ? $filaColor?->FOTO : $catalogo->FOTO_REFERENCIAL;

        if (!$ruta) {
            return response()->json(['success' => false, 'message' => $color ? "El color {$color} no tiene foto." : 'Este modelo no tiene foto.'], 422);
        }

        $fileId = CaracteristicaModelo::idDrive($ruta);

        try {
            DB::transaction(function () use ($catalogo, $filaColor) {
                if ($filaColor) {
                    $filaColor->delete();
                } else {
                    $catalogo->update(['FOTO_REFERENCIAL' => null]);
                }
            });

            \App\Models\CatalogoAuditLog::registrar(
                $catalogo->ID_ESPEC,
                'delete_foto',
                $catalogo->MODELO,
                $catalogo->ANIO_ESPEC !== null ? (int) $catalogo->ANIO_ESPEC : null,
                [($color ? 'foto ' . $color : 'foto') => ['antes' => $ruta, 'despues' => null]]
            );

            // Drive + caché: diferido para que la respuesta sea inmediata. No si otra foto
            // del catálogo es el mismo archivo.
            if ($fileId && !CaracteristicaModelo::fotoSigueEnUso($fileId)) {
                defer(function () use ($fileId) {
                    try {
                        GoogleDriveService::getInstance()->deleteFile($fileId);
                        \App\Services\GoogleDriveService::olvidarCopiaLocal($fileId);
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
        // La foto del modelo y las de sus colores (las filas de color se van en cascada con
        // la ficha; sus archivos de Drive hay que borrarlos aparte).
        $fileIds = collect([$catalogo->FOTO_REFERENCIAL])
            ->merge($catalogo->colores()->pluck('FOTO'))
            ->map(fn ($ruta) => CaracteristicaModelo::idDrive($ruta))
            ->filter()->unique()->values();

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

            // 2) Solo si la transacción BD fue exitosa: borrar Drive (operación irreversible),
            //    y solo los archivos que ninguna otra ficha use.
            foreach ($fileIds as $fileId) {
                if (CaracteristicaModelo::fotoSigueEnUso($fileId)) {
                    continue;
                }
                try {
                    GoogleDriveService::getInstance()->deleteFile($fileId);
                    \App\Services\GoogleDriveService::olvidarCopiaLocal($fileId);
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
        // CANDADO: si ese modelo+año tiene MÁS DE UNA ficha, no se engancha nada.
        //
        // Hoy ya no debería pasar: el color vive en la unidad (equipos.COLOR), la foto de
        // cada color dentro de la ficha (catalogo_colores), las copias que existían se unieron
        // (migración unificar_fichas_repetidas_por_color) y store()/update() no dejan crear
        // otra (rechazarFichaRepetida). Se conserva por si una base trae fichas repetidas de
        // antes: con varias, cuál le toca a cada unidad no se puede adivinar.
        //
        // Por qué existieron: separar unidades que llevaban FOTOS distintas (distinto color)
        // cuando la ficha solo guardaba una foto y la unidad no decía su color.
        //
        // Sin este candado, enganchaba igual: agarra todo equipo del modelo+año con
        // ID_ESPEC nulo y lo pega a la ficha que se esté guardando. O sea que editar UNA
        // ficha —aunque fuese solo para corregir un aceite— se llevaba de golpe a todas las
        // unidades sueltas de ese modelo y las sacaba a todas con la misma foto, borrando
        // en silencio el trabajo de separarlas. Medido cuando se puso esto: un modelo con 5
        // fichas tenía 104 unidades sueltas listas para ser absorbidas por la primera
        // edición que alguien hiciera.
        //
        // No adivinar es la respuesta correcta: es preferible que una unidad se quede sin
        // ficha (y muestre su propia foto) a que se muestre con la foto de otro color.
        $fichasDelModelo = CaracteristicaModelo::where('MODELO', $modelo)
            ->where('ANIO_ESPEC', $anio)
            ->count();

        if ($fichasDelModelo > 1) {
            Log::info("Auto-link OMITIDO para {$modelo} {$anio}: hay {$fichasDelModelo} fichas "
                . 'y no se puede saber cuál corresponde a cada unidad. Se asignan a mano.');
            return;
        }

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
