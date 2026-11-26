<?php

namespace App\Http\Controllers;

use App\Models\Consumible;
use App\Models\ConsumibleImportacion;
use App\Models\FrenteTrabajo;
use App\Services\Consumibles\IdentificadorConsumo;
use App\Services\Consumibles\ImportadorCombustible;
use App\Services\Consumibles\MatchConsumibles;
use Illuminate\Http\Request;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\MensajeError;

class ConsumiblesController extends Controller
{
    /**
     * Versión de los datos de consumibles. Viaja DENTRO de la clave del caché de
     * gráficos, así que subirla invalida de golpe todas las combinaciones de filtros.
     *
     * Va por CacheVersion y no por Cache::increment() suelto: increment() sobre una
     * clave que NO existe devuelve false y no la crea (comprobado con el driver
     * `database` de este proyecto). Como nadie creaba esta clave, la versión se quedaba
     * en el valor por defecto PARA SIEMPRE y los gráficos seguían mostrando datos viejos
     * hasta que vencía su TTL de 30 minutos — justo lo contrario de lo que decía el
     * comentario que había aquí.
     */
    public const DATA_VER_KEY = 'consumibles_data_version';

    use \App\Traits\ExcelLogoCorporativo;

    // ══════════════════════════════════════════════════════════════
    // INDEX — Lista + pendientes
    // ══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        self::limpiarFiltros($request);
        // La lista abre en Gasoil y siempre muestra un tipo (no hay «Todos los tipos»).
        if (!$request->filled('tipo')) {
            $request->merge(['tipo' => 'GASOIL']);
        }

        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        // Sin 'suministro': la tabla suministros_origen esta VACIA y ningun consumible
        // tiene ID_SUMINISTRO (el registro de consumible lo escribe siempre null), asi que
        // era una consulta mas en cada carga para traer nada. Ninguna vista la lee.
        $query = Consumible::with(['equipo.frenteActual', 'equipo.tipo', 'equipo.documentacion', 'auxiliar', 'frente'])
            ->orderBy('FECHA', 'desc')
            ->orderBy('ID_CONSUMIBLE', 'desc');

        self::filtrar($query, $request, true);

        $consumibles = (clone $query)->paginate(50)->onEachSide(3)->withQueryString();
        // Pasada la última página (se borró lo último que había en ella): la última que queda.
        if ($consumibles->isEmpty() && $consumibles->currentPage() > 1) {
            $consumibles = $query->paginate(50, ['*'], 'page', $consumibles->lastPage())->onEachSide(3)->withQueryString();
        }

        // ── Contadores de estado — 1 sola query en lugar de 3 ──────────────────
        // Los mismos filtros que la lista, menos el de estado: cada contador cuenta el suyo y al
        // pulsarlo la lista muestra justo esos.
        $conteos = tap(DB::table('consumibles'), fn ($q) => self::filtrar($q, $request, false))
            ->selectRaw("
                SUM(CASE WHEN ESTADO_EQUIPO = 'PENDIENTE'  THEN 1 ELSE 0 END) as pendientes,
                SUM(CASE WHEN ESTADO_EQUIPO = 'SIN_MATCH'  THEN 1 ELSE 0 END) as sinMatch,
                SUM(CASE WHEN ESTADO_EQUIPO = 'CONFIRMADO' THEN 1 ELSE 0 END) as confirmados
            ")->first();
        $pendientes = (int) ($conteos->pendientes ?? 0);
        $sinMatch = (int) ($conteos->sinMatch ?? 0);
        $confirmados = (int) ($conteos->confirmados ?? 0);

        // ── Gasoil por frente (debajo de los contadores) ────────────────────────
        // Los mismos filtros que la lista menos el estado, y siempre gasoil (aunque la lista
        // muestre otro tipo). De mayor a menor; lo cargado sin frente va aparte, al final.
        $filtroGasoil = $request->duplicate();
        $filtroGasoil->merge(['tipo' => 'GASOIL']);
        $filas = tap(DB::table('consumibles'), fn ($q) => self::filtrar($q, $filtroGasoil, false))
            ->selectRaw('consumibles.ID_FRENTE as id, SUM(consumibles.CANTIDAD) as litros')
            ->groupBy('consumibles.ID_FRENTE')->get();
        $nombres = FrenteTrabajo::withTrashed()->whereIn('ID_FRENTE', $filas->pluck('id')->filter())->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
        $gasoilPorFrente = $filas->map(fn ($f) => [
            'nombre' => $f->id ? ($nombres[$f->id] ?? 'Frente ' . $f->id) : null,
            'litros' => (float) $f->litros,
        ])->sortBy([fn ($a, $b) => ($a['nombre'] === null) <=> ($b['nombre'] === null), fn ($a, $b) => $b['litros'] <=> $a['litros']])->values();

        return view('admin.consumibles.index', compact(
            'consumibles',
            'frentes',
            'pendientes',
            'sinMatch',
            'confirmados',
            'gasoilPorFrente'
        ));
    }

    // ══════════════════════════════════════════════════════════════
    // CARGAR CONSUMIBLES — la pantalla (Excel de gasoil arriba, tabla a mano debajo)
    // ══════════════════════════════════════════════════════════════
    public function cargar()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        $tipos = Consumible::tiposLabel();
        $unidades = ['LITROS' => 'Litros', 'GALONES' => 'Galones', 'UNIDADES' => 'Unidades', 'KG' => 'Kg'];
        // Las cargas de Excel de gasoil guardadas (para deshacerlas): solo las ve super.admin. Un
        // borrador es un Excel subido a la tabla y aún sin guardar: no se lista.
        $cargas = auth()->user()->can('super.admin')
            ? ConsumibleImportacion::with('usuario:ID_USUARIO,NOMBRE_COMPLETO')
                ->where('ESTADO', '!=', ConsumibleImportacion::BORRADOR)
                ->orderByDesc('ID_IMPORTACION')->limit(30)->get()
            : collect();

        // Modal «Cupos de Gasoil» (CuposCombustibleController): $mes, $meses y $patios.
        return view('admin.consumibles.cargar', compact('frentes', 'tipos', 'unidades', 'cargas')
            + \App\Http\Controllers\CuposCombustibleController::datos());
    }

    /**
     * POST consumibles/frentes-de-textos — el frente de cada texto de proyecto de la tabla de
     * Cargar consumibles (lo pegado trae uno por fila), con el reconocedor del Excel de gasoil
     * (IdentificadorConsumo::resolverFrente). {frentes: {texto: {id, nombre} | null},
     * sugeridos: {identificador: {id, nombre, equipo}}} — el frente donde está el equipo de la fila.
     */
    public function frentesDeTextos(Request $request)
    {
        $request->validate([
            'textos'        => 'required|array|max:500', 'textos.*' => 'nullable|string|max:191',
            // Identificadores de las filas: para las de proyecto sin reconocer se sugiere el frente
            // donde está registrado su equipo.
            'equipos'       => 'nullable|array|max:500', 'equipos.*' => 'nullable|string|max:191',
        ]);
        $ident = new IdentificadorConsumo();
        $r = [];
        foreach (array_unique(array_filter(array_map('trim', $request->input('textos')))) as $t) {
            $id = $ident->resolverFrente(['proyecto' => $t])['id_frente'];
            $r[$t] = $id !== null ? ['id' => $id, 'nombre' => $ident->nombreFrente($id)] : null;
        }
        $sug = [];
        foreach (array_unique(array_filter(array_map('trim', $request->input('equipos', []) ?? []))) as $e) {
            $sug[$e] = $ident->frenteSugerido($ident->resolverEquipo(['desc' => $e]));
        }

        return response()->json(['frentes' => (object) $r, 'sugeridos' => (object) array_filter($sug)]);
    }

    // ══════════════════════════════════════════════════════════════
    // GUARDAR LOTE
    // ══════════════════════════════════════════════════════════════
    public function guardarLote(Request $request)
    {
        // Permiso 'super.admin' validado en el middleware de la ruta
        // (routes/web.php: middleware('can:super.admin') en consumibles.guardarLote).

        // ── 1. Filtrar filas vacías ANTES de validar ──────────────
        $filasFiltradas = collect($request->input('filas', []))
            ->filter(fn($f) => !empty($f['fecha']) && !empty($f['cantidad']))
            ->values()
            ->all();

        if (empty($filasFiltradas)) {
            $msg = 'No se enviaron filas con fecha y cantidad válidas.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg, 'errors' => ['error' => [$msg]]], 422);
            }
            return back()->withErrors(['error' => $msg])->withInput();
        }

        $request->merge(['filas' => $filasFiltradas]);

        // ── 2. Validar solo filas con datos ──────────────────────
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'tipo_consumible' => 'required|in:GASOIL,GASOLINA,ACEITE,CAUCHO',
            'unidad' => 'required|in:LITROS,GALONES,UNIDADES,KG',
            // El de arriba vale para las filas sin proyecto propio; cada fila puede traer el suyo.
            'id_frente' => 'nullable|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL',
            'filas' => 'required|array|min:1',
            'filas.*.fecha' => 'required|date',
            'filas.*.frente' => 'nullable|string|max:191',
            // Que el frente exista se mira de una vez más abajo: con exists, un Excel de miles
            // de filas hacía una consulta por fila.
            'filas.*.id_frente' => 'nullable|integer',
            'filas.*.cantidad' => 'required|numeric|min:0.01',
            // Los largos de sus columnas: más largo, la base lo rechazaba con un error crudo.
            'filas.*.identificador' => 'nullable|string|max:100',
            'filas.*.resp_nombre' => 'nullable|string|max:150',
            'filas.*.resp_ci' => 'nullable|string|max:20',
            'filas.*.especificacion' => 'nullable|string|max:30',
            // Fila que vino de un Excel subido: su carga (borrador) y su lugar en ella. Que vengan
            // las dos se mira abajo: required_with con miles de filas tardaba más de 10 s.
            'filas.*.imp' => 'nullable|integer',
            'filas.*.ref' => 'nullable|integer|min:0',
            'filas.*.sugerido' => 'nullable|in:1',
        ]);
        $validator->after(function ($v) use ($request) {
            $ids = collect($request->input('filas'))->pluck('id_frente')->filter()->map(fn ($id) => (int) $id)->unique();
            if ($ids->isNotEmpty() && DB::table('frentes_trabajo')->whereIn('ID_FRENTE', $ids)->whereNull('deleted_at')->count() !== $ids->count()) {
                $v->errors()->add('id_frente', 'Una de las filas tiene un frente que ya no existe: elígelo de nuevo.');
            }
            if (collect($request->input('filas'))->contains(fn ($f) => empty($f['imp']) !== (($f['ref'] ?? '') === ''))) {
                $v->errors()->add('filas', 'Una fila del Excel llegó incompleta: vuelve a subir el archivo.');
            }
            if ($request->tipo_consumible !== 'GASOIL' && collect($request->input('filas'))->contains(fn ($f) => !empty($f['imp']))) {
                $v->errors()->add('tipo_consumible', 'Las filas del Excel subido son de gasoil: elige el tipo Gasoil.');
            }
        });

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Error de validación.',
                    'errors' => $validator->errors(),
                ], 422);
            }
            return back()->withErrors($validator)->withInput();
        }

        $tipo = $request->tipo_consumible;
        $unidad = $request->unidad;

        // ── 3. Frente de cada fila ───────────────────────────────
        // El elegido en la fila; si no, el que se reconoce de su texto de proyecto (las mismas
        // reglas que el Excel de gasoil: nombre mal escrito, reglas fijas, lo ya enseñado); sin
        // texto, el de arriba. Un texto que no se reconoce y no tiene frente elegido NO toma el
        // de arriba: sería cargar ese consumo en otro proyecto.
        $ident = null;
        $frentes = $sinFrente = $ensenar = [];
        foreach ($request->filas as $i => $fila) {
            $texto = trim((string) ($fila['frente'] ?? ''));
            $elegido = !empty($fila['id_frente']) ? (int) $fila['id_frente'] : null;
            $reconocido = ['id_frente' => null, 'clave' => ''];
            if ($texto !== '') {
                $ident ??= new IdentificadorConsumo();
                $reconocido = $ident->resolverFrente(['proyecto' => $texto]);
            }
            $frentes[$i] = $elegido ?? $reconocido['id_frente'] ?? ($texto === '' && $request->id_frente ? (int) $request->id_frente : null);
            if ($frentes[$i] === null) {
                $sinFrente[$texto] = true;
            }
            // Elegido a mano para un texto que no se reconocía (o se reconocía como otro): queda
            // aprendido para la tabla y el Excel. No si se tomó la sugerencia (el frente donde está
            // el equipo de esa fila): vale para esa fila, no para todo lo que traiga ese texto.
            if ($elegido !== null && $texto !== '' && $reconocido['id_frente'] !== $elegido && empty($fila['sugerido'])) {
                $ensenar[$reconocido['clave'] !== '' ? $reconocido['clave'] : $texto] = $elegido;
            }
        }
        if ($sinFrente) {
            $sinTexto = isset($sinFrente['']);
            unset($sinFrente['']);
            $partes = [];
            if ($sinFrente) {
                $partes[] = 'No se reconoce el proyecto ' . implode(', ', array_map(fn ($t) => '«' . $t . '»', array_keys($sinFrente))) . ': elige su frente en la fila.';
            }
            if ($sinTexto) {
                $partes[] = 'Hay filas sin proyecto: elige el frente de arriba o escríbelo en la fila.';
            }
            $msg = implode(' ', $partes);
            if ($request->expectsJson()) {
                return response()->json(['message' => $msg, 'errors' => ['id_frente' => [$msg]]], 422);
            }
            return back()->withErrors(['id_frente' => $msg])->withInput();
        }

        $tiposConEspec = ['ACEITE', 'CAUCHO'];
        $importador = new ImportadorCombustible();
        // Las filas de un Excel subido, por carga: índice en el borrador → lo que quedó en la tabla.
        $deExcel = [];
        foreach ($request->filas as $i => $fila) {
            if (!empty($fila['imp'])) {
                $deExcel[(int) $fila['imp']][(int) $fila['ref']] = [
                    'fecha'         => $fila['fecha'],
                    'identificador' => $fila['identificador'] ?? null,
                    'resp_nombre'   => $fila['resp_nombre'] ?? null,
                    'resp_ci'       => $fila['resp_ci'] ?? null,
                    'cantidad'      => $fila['cantidad'],
                    'id_frente'     => $frentes[$i],
                ];
            }
        }

        $guardar = function () use ($request, $tipo, $unidad, $frentes, $ensenar, $deExcel, $importador, $tiposConEspec) {
            return DB::transaction(function () use ($request, $tipo, $unidad, $frentes, $ensenar, $deExcel, $importador, $tiposConEspec) {
                // Primero lo enseñado: el frente elegido para un texto cuenta ya al buscar repetidos.
                if ($ensenar) {
                    $importador->ensenar($ensenar, auth()->id());
                }
                $nuevos = [];   // los que identifica el match de abajo
                foreach ($request->filas as $i => $fila) {
                    if (!empty($fila['imp'])) {
                        continue;
                    }
                    $especFila = null;
                    if (in_array($tipo, $tiposConEspec)) {
                        $raw = trim($fila['especificacion'] ?? '');
                        $especFila = $raw !== '' ? $raw : null;
                    }

                    $nuevos[] = Consumible::create([
                        'FECHA' => $fila['fecha'],
                        'IDENTIFICADOR' => isset($fila['identificador']) ? trim($fila['identificador']) : null,
                        'RESP_NOMBRE' => isset($fila['resp_nombre']) ? trim($fila['resp_nombre']) : null,
                        'RESP_CI' => isset($fila['resp_ci']) ? trim($fila['resp_ci']) : null,
                        'CANTIDAD' => $fila['cantidad'],
                        'TIPO_CONSUMIBLE' => $tipo,
                        'ESPECIFICACION' => $especFila,
                        'UNIDAD' => $unidad,
                        'ID_FRENTE' => $frentes[$i],
                        'ID_EQUIPO' => null,
                        'ID_SUMINISTRO' => null,
                        'ESTADO_EQUIPO' => 'PENDIENTE',
                    ])->ID_CONSUMIBLE;
                }
                // Lo del Excel: solo lo que no estaba (en la base, en lo de arriba o en otro archivo).
                $insertados = count($nuevos);
                $saltados = 0;
                foreach ($deExcel as $idImp => $tabla) {
                    $imp = ConsumibleImportacion::find($idImp);
                    if (!$imp) {
                        throw new \App\Services\Consumibles\CargaRechazada('La lectura del Excel ya no está (pasaron más de dos días o se guardó en otra pestaña). Vuelve a subir el archivo.');
                    }
                    $r = $importador->aplicar($imp, $tabla);
                    array_push($nuevos, ...$r['pendientes']);
                    $insertados += $r['insertados'];
                    $saltados += $r['saltados'];
                }

                return [$nuevos, $insertados, $saltados];
            });
        };

        try {
            @set_time_limit(180);
            // Con filas de Excel, de a una carga en todo el sistema (ImportadorCombustible::deUnoEnUno).
            [$nuevos, $insertados, $saltados] = $deExcel ? $importador->deUnoEnUno($guardar) : $guardar();
        } catch (\App\Services\Consumibles\CargaRechazada $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        } catch (\Exception $e) {
            $errMsg = 'Error al guardar: ' . MensajeError::para($e, MensajeError::REINTENTAR);
            if ($request->expectsJson()) {
                return response()->json(['message' => $errMsg], 500);
            }
            return back()->withErrors(['error' => $errMsg])->withInput();
        }

        // El equipo de cada fila lo identifica el servidor ya, sin botón.
        (new MatchConsumibles())->ejecutar($nuevos);
        CacheVersion::bump(self::DATA_VER_KEY);

        $mensaje = "$insertados registros cargados exitosamente."
            . ($saltados ? ' ' . number_format($saltados, 0, ',', '.') . ($saltados === 1 ? ' se saltó porque ya estaba cargado o repetido en otro archivo.' : ' se saltaron porque ya estaban cargados o repetidos en otro archivo.') : '');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensaje,
                'insertados' => $insertados,
                'saltados' => $saltados,
            ]);
        }

        return redirect()->route('consumibles.index')->with('success', $mensaje);
    }


    // ══════════════════════════════════════════════════════════════
    // ACTUALIZAR IDENTIFICADOR — y se vuelve a identificar el equipo
    // ══════════════════════════════════════════════════════════════
    public function updateIdentificador(Request $request, int $id)
    {
        $consumible = Consumible::findOrFail($id);

        $request->validate([
            'identificador' => 'required|string|max:100',
        ]);

        $consumible->update([
            'IDENTIFICADOR' => strtoupper(trim($request->identificador)),
            'ESTADO_EQUIPO' => 'PENDIENTE',   // se vuelve a identificar ya, con el texto nuevo
            'ID_EQUIPO' => null,
            'ID_AUXILIAR' => null,
        ]);
        // ejecutar() ya invalida la caché de la lista y los gráficos.
        (new MatchConsumibles())->ejecutar([$consumible->ID_CONSUMIBLE]);

        return response()->json(['ok' => true, 'identificador' => $consumible->IDENTIFICADOR]);
    }

    // ══════════════════════════════════════════════════════════════
    // ACTUALIZAR FRENTE — Cambia el frente de trabajo del registro
    // ══════════════════════════════════════════════════════════════
    public function updateFrente(Request $request, int $id)
    {
        $consumible = Consumible::findOrFail($id);

        $request->validate([
            'id_frente' => 'required|exists:frentes_trabajo,ID_FRENTE,deleted_at,NULL',
        ]);

        $consumible->update(['ID_FRENTE' => $request->id_frente]);

        // Invalidar caché de gráficos
        CacheVersion::bump(self::DATA_VER_KEY);

        $nombreFrente = FrenteTrabajo::find($request->id_frente)?->NOMBRE_FRENTE ?? '—';

        return response()->json(['ok' => true, 'nombre_frente' => $nombreFrente]);
    }

    // ══════════════════════════════════════════════════════════════
    // DESTROY — Eliminar un registro (AJAX → JSON, form → redirect)
    // ══════════════════════════════════════════════════════════════
    public function destroy(Request $request, int $id)
    {
        // Pide equipos.edit, como editar (la ruta); ya no super.admin, a pedido del usuario.
        Consumible::findOrFail($id)->delete();

        // Invalidar caché de gráficos
        CacheVersion::bump(self::DATA_VER_KEY);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Registro eliminado.');
    }

    // ══════════════════════════════════════════════════════════════
    // GRÁFICOS — Vista
    // ══════════════════════════════════════════════════════════════
    public function graficos()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')->get();

        return view('admin.consumibles.graficos', compact('frentes'));
    }

    // ══════════════════════════════════════════════════════════════
    // API — Datos para gráficos (JSON)
    // ══════════════════════════════════════════════════════════════
    public function graficosData(Request $request)
    {
        set_time_limit(120); // queries pesadas — evita timeout de 60s

        $desde = $request->get('desde');
        $hasta = $request->get('hasta');
        $idFrente = $request->get('id_frente');
        $tipo = $request->get('tipo');

        // ── Clave de caché única por combinación de filtros + versión de datos ──
        // La versión sube cada vez que se escriben datos → invalida todo el caché anterior.
        $version = CacheVersion::current(self::DATA_VER_KEY);
        // "f6": el top es de 4 equipos POR PROYECTO (antes 20 en total); un valor guardado con la forma
        // vieja no debe servirse.
        $cacheKey = 'graficos_f6_v' . $version
            . '_d' . ($desde ?? 'null')
            . '_h' . ($hasta ?? 'null')
            . '_f' . ($idFrente ?? 'all')
            . '_t' . ($tipo ?? 'all');

        $resultado = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($desde, $hasta, $idFrente, $tipo) {

            // ── Filtros base — SIN filtrar por estado (incluye SIN_MATCH y PENDIENTE) ──
            $filtrosTodos = function ($q) use ($desde, $hasta, $idFrente, $tipo) {
                if ($desde)
                    $q->where('consumibles.FECHA', '>=', $desde);
                if ($hasta)
                    $q->where('consumibles.FECHA', '<=', $hasta);
                if ($idFrente)
                    $q->where('consumibles.ID_FRENTE', $idFrente);
                if ($tipo)
                    $q->where('consumibles.TIPO_CONSUMIBLE', $tipo);
            };

            // ── Filtros para queries que requieren equipo identificado ────────────────
            $filtrosConfirmados = function ($q) use ($filtrosTodos) {
                $filtrosTodos($q);
                $q->where('consumibles.ESTADO_EQUIPO', 'CONFIRMADO');
            };

            // ── 2. Top 4 equipos de mayor consumo DE CADA PROYECTO — solo CONFIRMADOS ──
            // El proyecto es el del SURTIDO (consumibles.ID_FRENTE); si el surtido no lo trae, el
            // frente al que pertenece el equipo. Antes se agrupaba siempre por el frente ACTUAL del
            // equipo y un surtido de Oleoducto salía bajo otro proyecto. Un equipo que surtió en
            // dos proyectos sale en los dos, cada uno con sus litros y sus surtidos.
            // Orden: litros y, a igual litros, más surtidos primero.
            $proyecto = DB::raw('COALESCE(consumibles.ID_FRENTE, equipos.ID_FRENTE_ACTUAL)');
            $topEquipos = DB::table('consumibles')
                ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', $proyecto)
                ->where(function ($q) use ($filtrosConfirmados) {
                    $filtrosConfirmados($q);
                })
                ->select(
                    'equipos.ID_EQUIPO',
                    'equipos.CODIGO_PATIO',
                    'equipos.MARCA',
                    'equipos.MODELO',
                    'equipos.SERIAL_CHASIS',
                    DB::raw("(SELECT MAX(d.PLACA) FROM documentacion d WHERE d.ID_EQUIPO = equipos.ID_EQUIPO) as PLACA"),
                    DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo"),
                    DB::raw("MAX(frentes_trabajo.NOMBRE_FRENTE) as frente"),
                    DB::raw('COALESCE(consumibles.ID_FRENTE, equipos.ID_FRENTE_ACTUAL) as id_proyecto'),
                    'consumibles.TIPO_CONSUMIBLE',
                    DB::raw('SUM(consumibles.CANTIDAD) as total'),
                    DB::raw('COUNT(*) as despachos'),
                    DB::raw('MAX(consumibles.UNIDAD) as unidad')
                )
                ->groupBy(
                    'equipos.ID_EQUIPO',
                    'equipos.CODIGO_PATIO',
                    'equipos.MARCA',
                    'equipos.MODELO',
                    'equipos.SERIAL_CHASIS',
                    'tipo_equipos.nombre',
                    'consumibles.TIPO_CONSUMIBLE',
                    $proyecto
                )
                ->orderBy('total', 'desc')
                ->orderBy('despachos', 'desc')
                ->get()
                // Los 4 de cada proyecto (ya vienen de mayor a menor); los proyectos, por su equipo de más
                // consumo (el orden en que aparecen). Antes eran 20 en total y los proyectos chicos no salían.
                ->groupBy(fn ($e) => (int) $e->id_proyecto)
                ->flatMap(fn ($equipos) => $equipos->take(4))
                ->values();

            // ── 6. Equipos individuales × frente — solo CONFIRMADOS ──────────────────
            // Muestra qué equipos específicos (modelo + código) surtieron en cada frente.
            $equiposPorFrente = DB::table('consumibles')
                ->join('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
                ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->where(function ($q) use ($filtrosConfirmados) {
                    $filtrosConfirmados($q);
                })
                ->whereNotNull('consumibles.ID_EQUIPO')
                ->select(
                    'equipos.ID_EQUIPO',
                    'frentes_trabajo.NOMBRE_FRENTE',
                    'equipos.MODELO',
                    'equipos.CODIGO_PATIO',
                    'equipos.SERIAL_CHASIS',
                    DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo_equipo"),
                    DB::raw('SUM(consumibles.CANTIDAD) as total'),
                    DB::raw('COUNT(*) as despachos'),
                    DB::raw('MAX(consumibles.UNIDAD) as unidad')
                )
                ->groupBy(
                    'equipos.ID_EQUIPO',
                    'frentes_trabajo.NOMBRE_FRENTE',
                    'equipos.MODELO',
                    'equipos.CODIGO_PATIO',
                    'equipos.SERIAL_CHASIS',
                    'tipo_equipos.nombre'
                )
                ->orderBy('frentes_trabajo.NOMBRE_FRENTE')
                ->orderBy('total', 'desc')
                ->get();

            // ── 7. Aceite por viscosidad — solo con el filtro ACEITE ───────
            // (El desglose de cauchos por modelo se quitó a pedido.) Otro tipo → vacío → secciones ocultas.
            $tipoEspec = $tipo === 'ACEITE' ? 'ACEITE' : null;

            if ($tipoEspec) {
                // Por frente × especificacion
                $especFrente = DB::table('consumibles')
                    ->join('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
                    ->where('consumibles.TIPO_CONSUMIBLE', $tipoEspec)
                    ->whereNotNull('consumibles.ESPECIFICACION')
                    ->where('consumibles.ESPECIFICACION', '<>', '')
                    ->when($desde, fn($q) => $q->where('consumibles.FECHA', '>=', $desde))
                    ->when($hasta, fn($q) => $q->where('consumibles.FECHA', '<=', $hasta))
                    ->when($idFrente, fn($q) => $q->where('consumibles.ID_FRENTE', $idFrente))
                    ->select(
                        'frentes_trabajo.NOMBRE_FRENTE',
                        'consumibles.ESPECIFICACION',
                        DB::raw('SUM(consumibles.CANTIDAD) as total'),
                        DB::raw('COUNT(*) as despachos')
                    )
                    ->groupBy('frentes_trabajo.NOMBRE_FRENTE', 'consumibles.ESPECIFICACION')
                    ->orderBy('frentes_trabajo.NOMBRE_FRENTE')
                    ->orderBy('total', 'desc')
                    ->get();

                // Por equipo × especificacion — solo CONFIRMADOS
                // Usa $filtrosConfirmados como base para respetar fecha, frente y tipo igual que el resto
                $especEquipo = DB::table('consumibles')
                    ->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                    ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                    ->leftJoin('documentacion', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
                    ->where(function ($q) use ($filtrosConfirmados) {
                        $filtrosConfirmados($q);
                    })
                    ->where('consumibles.TIPO_CONSUMIBLE', $tipoEspec)
                    ->whereNotNull('consumibles.ESPECIFICACION')
                    ->where('consumibles.ESPECIFICACION', '<>', '')
                    ->whereNotNull('consumibles.ID_EQUIPO')
                    ->select(
                        'equipos.ID_EQUIPO',
                        'equipos.CODIGO_PATIO',
                        'equipos.MODELO',
                        'equipos.SERIAL_CHASIS',
                        DB::raw("MAX(documentacion.PLACA) as PLACA"),
                        DB::raw("MAX(consumibles.IDENTIFICADOR) as identificador"),
                        DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo_equipo"),
                        'consumibles.ESPECIFICACION',
                        DB::raw('SUM(consumibles.CANTIDAD) as total'),
                        DB::raw('COUNT(*) as despachos')
                    )
                    ->groupBy(
                        'equipos.ID_EQUIPO',
                        'equipos.CODIGO_PATIO',
                        'equipos.MODELO',
                        'equipos.SERIAL_CHASIS',
                        'tipo_equipos.nombre',
                        'consumibles.ESPECIFICACION'
                    )
                    ->orderBy('total', 'desc')
                    ->get();

            } else {
                $especFrente = collect();
                $especEquipo = collect();
            }

            // ── 8. Equipos Inoperativos en el frente seleccionado ────────────
            // (Bloque "Cauchos por Tipo de Equipo y Medida" removido a pedido —
            // el panel se elimino del modulo de graficos.)
            $inoperativos = [];
            if ($idFrente) {
                $inoperativos = DB::table('equipos')
                    ->where('ESTADO_OPERATIVO', 'INOPERATIVO')
                    ->where('ID_FRENTE_ACTUAL', $idFrente)
                    ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                    ->leftJoin('documentacion', 'documentacion.ID_EQUIPO', '=', 'equipos.ID_EQUIPO')
                    ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'equipos.ID_FRENTE_ACTUAL')
                    ->leftJoin('consumibles', function ($join) use ($desde, $hasta, $tipo) {
                        $join->on('consumibles.ID_EQUIPO', '=', 'equipos.ID_EQUIPO');
                        if ($desde)
                            $join->where('consumibles.FECHA', '>=', $desde);
                        if ($hasta)
                            $join->where('consumibles.FECHA', '<=', $hasta);
                        if ($tipo)
                            $join->where('consumibles.TIPO_CONSUMIBLE', $tipo);
                        $join->where('consumibles.ESTADO_EQUIPO', 'CONFIRMADO');
                    })
                    ->select(
                        'equipos.ID_EQUIPO',
                        'equipos.SERIAL_CHASIS',
                        'frentes_trabajo.NOMBRE_FRENTE as frente_nombre',
                        DB::raw("MAX(documentacion.PLACA) as PLACA"),
                        DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo"),
                        DB::raw('SUM(consumibles.CANTIDAD) as total'),
                        DB::raw('COUNT(consumibles.ID_CONSUMIBLE) as despachos'),
                        DB::raw('MAX(consumibles.UNIDAD) as unidad')
                    )
                    ->groupBy(
                        'equipos.ID_EQUIPO',
                        'equipos.SERIAL_CHASIS',
                        'frentes_trabajo.NOMBRE_FRENTE',
                        'tipo_equipos.nombre'
                    )
                    ->get();

                // FOTO: la de toda la app (Equipo::fotoParaMostrar: color → modelo → propia),
                // resuelta aquí y no en el navegador, que antes repetía la regla por su cuenta.
                $fotos = \App\Models\Equipo::with(\App\Models\Equipo::conFoto())
                    ->whereIn('ID_EQUIPO', $inoperativos->pluck('ID_EQUIPO'))
                    ->get(['ID_EQUIPO', 'ID_ESPEC', 'COLOR', 'FOTO_EQUIPO'])
                    ->mapWithKeys(fn ($e) => [$e->ID_EQUIPO => $e->fotoParaMostrar()]);
                foreach ($inoperativos as $fila) {
                    $fila->FOTO = $fotos[$fila->ID_EQUIPO] ?? null;
                }
            }

            return [
                'equipos_por_frente' => $equiposPorFrente,
                'top_equipos' => $topEquipos,
                'espec_frente' => $especFrente,
                'espec_equipo' => $especEquipo,
                'inoperativos' => $inoperativos,
            ];
        }); // fin Cache::remember


        return response()->json($resultado);
    }


    // auditoriaCatalogoData() removido: el panel "Auditoría de Catálogo"
    // se elimino del modulo de graficos. La auditoria de cambios sigue
    // accesible directamente desde /admin/catalogo (cada modelo expone
    // su propio historial). Si en el futuro vuelve, restaurar este metodo
    // junto a la ruta consumibles.auditoriaCatalogo y al panel Blade.

    /**
     * Filtros de la URL mal formados se ignoran (?estado=FOO, ?tipo[]=x, ?identificador[]=x):
     * la vista los pinta tal cual y con un arreglo o un valor desconocido daba error 500.
     */
    private static function limpiarFiltros(Request $request): void
    {
        foreach (['tipo', 'id_frente', 'identificador', 'fecha_desde', 'fecha_hasta', 'estado'] as $campo) {
            if ($request->has($campo) && !is_string($request->input($campo))) {
                $request->merge([$campo => null]);
            }
        }
        if ($request->filled('estado') && !self::estadoValido($request->input('estado'))) {
            $request->merge(['estado' => null]);
        }
        if ($request->filled('tipo') && !array_key_exists($request->input('tipo'), Consumible::tiposLabel())) {
            $request->merge(['tipo' => null]);
        }
    }

    /** Los filtros de la lista, también para sus contadores (sin estado) y para el export. */
    private static function filtrar($q, Request $request, bool $conEstado): void
    {
        if ($request->filled('tipo')) {
            $q->where('consumibles.TIPO_CONSUMIBLE', $request->input('tipo'));
        }
        if ($request->filled('id_frente')) {
            $q->where('consumibles.ID_FRENTE', $request->input('id_frente'));
        }
        if ($conEstado && $request->filled('estado')) {
            $q->where('consumibles.ESTADO_EQUIPO', $request->input('estado'));
        }
        if ($request->filled('fecha_desde')) {
            $q->where('consumibles.FECHA', '>=', $request->input('fecha_desde'));
        }
        if ($request->filled('fecha_hasta')) {
            $q->where('consumibles.FECHA', '<=', $request->input('fecha_hasta'));
        }
        if (trim((string) $request->input('identificador')) !== '') {
            self::buscarIdentificador($q, $request->input('identificador'));
        }
    }

    private static function estadoValido($estado): bool
    {
        return is_string($estado) && in_array($estado, ['PENDIENTE', 'CONFIRMADO', 'SIN_MATCH'], true);
    }

    /**
     * «Buscar placa/serial» de la lista y del export: lo escrito en el consumo o la placa, el
     * serial o el código del equipo (o auxiliar) emparejado, que es lo que muestra la tabla.
     */
    private static function buscarIdentificador($query, string $texto): void
    {
        // «_» y «%» son letras para quien busca (hay placas con «_»), no comodines de LIKE.
        $t = '%' . addcslashes(trim($texto), '\\%_') . '%';
        $query->where(function ($w) use ($t) {
            $w->where('consumibles.IDENTIFICADOR', 'like', $t)
                ->orWhereIn('consumibles.ID_EQUIPO', fn($s) => $s->select('ID_EQUIPO')->from('equipos')
                    ->where('SERIAL_CHASIS', 'like', $t)->orWhere('CODIGO_PATIO', 'like', $t))
                ->orWhereIn('consumibles.ID_EQUIPO', fn($s) => $s->select('ID_EQUIPO')->from('documentacion')
                    ->where('PLACA', 'like', $t))
                ->orWhereIn('consumibles.ID_AUXILIAR', fn($s) => $s->select('ID_AUXILIAR')->from('equipos_auxiliares')
                    ->where('CODIGO_INTERNO', 'like', $t)->orWhere('SERIAL', 'like', $t));
        });
    }

    // ══════════════════════════════════════════════════════════════
    // PRESENTACIÓN DE GASOIL — los datos del PowerPoint que arma Gráficos (consumibles_presentacion.js)
    // ══════════════════════════════════════════════════════════════
    /**
     * Lámina 1: gasoil por proyecto en el período (todos los estados, como la lista). Lámina 2: un proyecto,
     * sus 6 meses hasta el último del período y sus 4 equipos de mayor consumo en el período (solo los
     * confirmados con equipo, del proyecto del SURTIDO, igual que sus barras y sus meses, para que las cifras
     * cuadren). Sin fechas, el período es el mes en curso.
     */
    public function presentacionData(Request $request)
    {
        $request->validate([
            'desde' => 'nullable|date', 'hasta' => 'nullable|date',
            'id_proyecto' => 'nullable|integer', 'id_frente' => 'nullable|integer',
        ]);
        $gasoil = fn () => DB::table('consumibles')->where('consumibles.TIPO_CONSUMIBLE', 'GASOIL');

        if (!$gasoil()->exists()) {
            return response()->json(['vacio' => true]);
        }
        // Sin filtro de fechas: el mes en curso (el más actual, como Cupos); otro mes se elige con los filtros.
        $hasta = $request->filled('hasta') ? \Carbon\Carbon::parse($request->hasta) : now()->endOfMonth();
        $desde = $request->filled('desde') ? \Carbon\Carbon::parse($request->desde) : $hasta->copy()->startOfMonth();
        $rango = [$desde->toDateString(), $hasta->toDateString()];

        $filas = $gasoil()->whereBetween('FECHA', $rango)
            ->selectRaw('ID_FRENTE as id, SUM(CANTIDAD) as litros')->groupBy('ID_FRENTE')->get();
        $nombres = FrenteTrabajo::withTrashed()->whereIn('ID_FRENTE', $filas->pluck('id')->filter())->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
        $proyectos = $filas->filter(fn ($f) => $f->id)
            ->map(fn ($f) => ['id' => (int) $f->id, 'nombre' => $nombres[$f->id] ?? 'Frente ' . $f->id, 'litros' => (float) $f->litros])
            ->sortByDesc('litros')->values();
        $sinFrente = (float) ($filas->firstWhere('id', null)->litros ?? 0);

        $idProyecto = (int) ($request->input('id_proyecto') ?: $request->input('id_frente') ?: ($proyectos[0]['id'] ?? 0));
        $proyecto = null;
        if ($idProyecto) {
            // Los 6 meses que terminan en el último del período.
            $inicio = $hasta->copy()->startOfMonth()->subMonths(5);
            $porMes = $gasoil()->where('ID_FRENTE', $idProyecto)->whereBetween('FECHA', [$inicio->toDateString(), $rango[1]])
                ->selectRaw("DATE_FORMAT(FECHA, '%Y-%m') as mes, SUM(CANTIDAD) as litros")->groupBy('mes')->pluck('litros', 'mes');
            $meses = [];
            for ($m = $inicio->copy(); $m->lte($hasta); $m->addMonth()) {
                $meses[] = ['mes' => mb_strtoupper($m->locale('es')->translatedFormat('F')), 'litros' => (float) ($porMes[$m->format('Y-m')] ?? 0)];
            }

            $top = $gasoil()->join('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
                ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
                ->where('consumibles.ESTADO_EQUIPO', 'CONFIRMADO')
                ->whereBetween('consumibles.FECHA', $rango)
                ->where('consumibles.ID_FRENTE', $idProyecto)
                ->select(
                    'equipos.ID_EQUIPO', 'equipos.SERIAL_CHASIS', 'equipos.CODIGO_PATIO',
                    DB::raw('(SELECT MAX(d.PLACA) FROM documentacion d WHERE d.ID_EQUIPO = equipos.ID_EQUIPO) as PLACA'),
                    DB::raw("COALESCE(tipo_equipos.nombre, 'S/T') as tipo"),
                    DB::raw('SUM(consumibles.CANTIDAD) as litros'), DB::raw('COUNT(*) as despachos')
                )
                ->groupBy('equipos.ID_EQUIPO', 'equipos.SERIAL_CHASIS', 'equipos.CODIGO_PATIO', 'tipo_equipos.nombre')
                ->orderByDesc('litros')->orderByDesc('despachos')->limit(4)->get()
                ->map(function ($e) {
                    // La placa; sin placa, el serial o el código (como la lista).
                    $id = collect([$e->PLACA, $e->SERIAL_CHASIS, $e->CODIGO_PATIO])
                        ->map(fn ($v) => trim((string) $v))->first(fn ($v) => preg_match('/[\p{L}\p{N}]/u', $v) === 1);
                    return ['tipo' => mb_strtoupper($e->tipo), 'id' => $id ?? '—', 'litros' => (float) $e->litros, 'despachos' => (int) $e->despachos];
                });

            $proyecto = [
                'id' => $idProyecto,
                'nombre' => FrenteTrabajo::withTrashed()->where('ID_FRENTE', $idProyecto)->value('NOMBRE_FRENTE') ?? 'Frente ' . $idProyecto,
                'meses' => $meses,
                'top' => $top->values(),
            ];
        }

        return response()->json([
            'desde' => $desde->format('d/m/Y'),
            'hasta' => $hasta->format('d/m/Y'),
            'total' => (float) $proyectos->sum('litros') + $sinFrente,
            'sin_frente' => $sinFrente,
            'proyectos' => $proyectos,
            'proyecto' => $proyecto,
            // Cisternas, cupos, histórico y cronograma del mes del período (modal Cupos de Gasoil).
            'cisternas' => CuposCombustibleController::presentacion($hasta),
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    // EXPORTAR CSV — Descarga directa de datos confirmados
    // ══════════════════════════════════════════════════════════════
    public function exportarCsv(Request $request)
    {
        // Gráficos manda desde/hasta y id_frente=all; la lista, fecha_desde/fecha_hasta. Sin tipo
        // (Gráficos con «todos») salen todos los tipos.
        $request->merge([
            'fecha_desde' => $request->input('fecha_desde') ?: $request->input('desde'),
            'fecha_hasta' => $request->input('fecha_hasta') ?: $request->input('hasta'),
        ]);
        if ($request->input('id_frente') === 'all') {
            $request->merge(['id_frente' => null]);
        }
        self::limpiarFiltros($request);
        $idFrente = $request->input('id_frente');
        $tipo = $request->input('tipo');

        $query = DB::table('consumibles')
            ->leftJoin('frentes_trabajo', 'frentes_trabajo.ID_FRENTE', '=', 'consumibles.ID_FRENTE')
            ->leftJoin('equipos', 'equipos.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO')
            ->leftJoin('tipo_equipos', 'tipo_equipos.id', '=', 'equipos.id_tipo_equipo')
            // Los consumos de un auxiliar (máquina de soldar, planta…) llevan ID_AUXILIAR, no ID_EQUIPO.
            ->leftJoin('equipos_auxiliares', 'equipos_auxiliares.ID_AUXILIAR', '=', 'consumibles.ID_AUXILIAR')
            // La placa del equipo (una por equipo), para el mismo identificador que muestra la tabla.
            ->leftJoinSub(
                DB::table('documentacion')->select('ID_EQUIPO', DB::raw('MAX(PLACA) AS PLACA'))->groupBy('ID_EQUIPO'),
                'doc', 'doc.ID_EQUIPO', '=', 'consumibles.ID_EQUIPO'
            );

        self::filtrar($query, $request, true);

        $filas = $query->select(
            'consumibles.FECHA',
            DB::raw("COALESCE(frentes_trabajo.NOMBRE_FRENTE, 'S/F') as FRENTE"),
            'consumibles.TIPO_CONSUMIBLE',
            'consumibles.ESPECIFICACION',
            // Como la tabla: placa, serial o código del equipo (o del auxiliar); sin equipo, lo escrito
            // (abajo, sin la descripción del Excel, que va en TIPO). Las de relleno («,,,», «_», «....»)
            // no cuentan: no tienen ni una letra ni un número.
            DB::raw("COALESCE(" . implode(', ', array_map(
                fn ($col) => "CASE WHEN {$col} REGEXP '[[:alnum:]]' THEN {$col} END",
                ['doc.PLACA', 'equipos.SERIAL_CHASIS', 'equipos.CODIGO_PATIO', 'equipos_auxiliares.CODIGO_INTERNO', 'equipos_auxiliares.SERIAL']
            )) . ") as EQUIPO_PLACA"),
            'consumibles.IDENTIFICADOR',
            'consumibles.DESC_EQUIPO',
            DB::raw('(consumibles.ID_EQUIPO IS NOT NULL OR consumibles.ID_AUXILIAR IS NOT NULL) as CON_EQUIPO'),
            DB::raw("COALESCE(equipos.MODELO, equipos_auxiliares.MODELO, 'S/M') as MODELO"),
            // Sin equipo asociado: lo que dice el Excel del vehículo.
            DB::raw("COALESCE(tipo_equipos.nombre, REPLACE(equipos_auxiliares.TIPO, '_', ' '), consumibles.DESC_EQUIPO, 'S/T') as TIPO_EQUIPO"),
            'consumibles.CANTIDAD',
            'consumibles.UNIDAD',
            'consumibles.RESP_NOMBRE',
            'consumibles.RESP_CI',
            'consumibles.ESTADO_EQUIPO'
        )
            ->orderBy('consumibles.FECHA', 'desc')
            ->get();

        $nombreFrente = 'TODOS LOS FRENTES';
        if ($idFrente) {
            $frenteData = DB::table('frentes_trabajo')->where('ID_FRENTE', $idFrente)->first();
            if ($frenteData) {
                $nombreFrente = mb_strtoupper($frenteData->NOMBRE_FRENTE);
            }
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Consumibles');

        $spreadsheet->getProperties()
            ->setCreator('Sistema de Gestión de Consumibles')
            ->setLastModifiedBy('Administrador')
            ->setTitle('Reporte de Consumibles')
            ->setSubject('Reporte Exportado')
            ->setDescription('Generado automáticamente por el Sistema - C.VIDALSA 27, C.A.');

        $spreadsheet->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);

        $sheet->getRowDimension('1')->setRowHeight(35);
        $sheet->getRowDimension('2')->setRowHeight(35);
        $sheet->getRowDimension('3')->setRowHeight(35);

        // Fila 1 a 3 - Título Empresa
        $sheet->mergeCells('A1:B3');
        $sheet->getStyle('A1:B3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        // Logo centrado en A1:B3 (trait ExcelLogoCorporativo)
        $this->insertarLogoCorporativo($sheet, ['A','B'], [1,2,3]);
        $sheet->mergeCells('C1:E3');

        $subTitle = $nombreFrente !== 'TODOS LOS FRENTES' ? 'PROYECTO: "' . $nombreFrente . '"' : 'REPORTE GENERAL';
        $tituloBase = "CONTROL DE CONSUMO GENERAL";
        if ($tipo) {
            $tituloBase = "CONTROL DE CONSUMO DE " . mb_strtoupper($tipo);
        }
        $titleText = $tituloBase . "\n" . $subTitle;

        $sheet->setCellValue('C1', $titleText);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true);
        $sheet->getStyle('C1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('C1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $sheet->getStyle('C1:E3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F1:L1');
        $sheet->setCellValue('F1', 'EDICIÓN: 1');
        $sheet->getStyle('F1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F1')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F1')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F1:L1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F2:L2');
        $sheet->setCellValue('F2', 'REVISIÓN: 0');
        $sheet->getStyle('F2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F2')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F2')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F2:L2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        $sheet->mergeCells('F3:L3');
        $sheet->setCellValue('F3', 'FECHA DE IMPRESIÓN: ' . date('d/m/Y'));
        $sheet->getStyle('F3')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('F3')->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('F3')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('F3:L3')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');

        // Bordes de la cabecera
        $sheet->getStyle('A1:L3')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        $rowNum = 6;

        $sheet->setCellValue('A' . ($rowNum - 1), 'EMITIDO POR EL SISTEMA DE GESTIÓN DE EQUIPOS OPERACIONALES | Total de registros: ' . count($filas));
        $sheet->getStyle('A' . ($rowNum - 1))->getFont()->setBold(true)->getColor()->setARGB('FF475569');

        $headers = [
            'A' => 'FECHA',
            'B' => 'FRENTE',
            'C' => 'TIPO CONSUMIBLE',
            'D' => 'ESPECIFICACIÓN',
            'E' => 'IDENTIFICADOR/PLACA',
            'F' => 'MODELO',
            'G' => 'TIPO EQUIPO',
            'H' => 'CANTIDAD',
            'I' => 'UNIDAD',
            'J' => 'RESPONSABLE (NOMBRE)',
            'K' => 'RESPONSABLE (C.I.)',
            'L' => 'ESTADO MATCH'
        ];

        foreach ($headers as $col => $title) {
            $sheet->setCellValue($col . $rowNum, $title);
        }

        $sheet->getStyle('A' . $rowNum . ':L' . $rowNum)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A' . $rowNum . ':L' . $rowNum)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1E293B');
        $sheet->getStyle('A' . $rowNum . ':L' . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $rowNum++;
        $startDataRow = $rowNum;

        foreach ($filas as $f) {
            $sheet->setCellValue('A' . $rowNum, \Carbon\Carbon::parse($f->FECHA)->format('d/m/Y'));
            $sheet->setCellValue('B' . $rowNum, $f->FRENTE);
            $sheet->setCellValue('C' . $rowNum, $f->TIPO_CONSUMIBLE);
            $sheet->setCellValue('D' . $rowNum, $f->ESPECIFICACION);
            $sheet->setCellValue('E' . $rowNum, $f->EQUIPO_PLACA ?? ($f->CON_EQUIPO
                ? $f->IDENTIFICADOR
                : Consumible::escritoSinDescripcion($f->IDENTIFICADOR, $f->DESC_EQUIPO)));
            $sheet->setCellValue('F' . $rowNum, $f->MODELO);
            $sheet->setCellValue('G' . $rowNum, $f->TIPO_EQUIPO);
            $sheet->setCellValue('H' . $rowNum, $f->CANTIDAD);
            $sheet->setCellValue('I' . $rowNum, $f->UNIDAD);
            $sheet->setCellValue('J' . $rowNum, $f->RESP_NOMBRE);
            $sheet->setCellValue('K' . $rowNum, $f->RESP_CI);
            $sheet->setCellValue('L' . $rowNum, $f->ESTADO_EQUIPO === 'SIN_MATCH' ? 'POR IDENTIFICAR' : $f->ESTADO_EQUIPO);

            $sheet->getStyle('H' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');

            if ($f->ESTADO_EQUIPO == 'CONFIRMADO') {
                $sheet->getStyle('L' . $rowNum)->getFont()->getColor()->setARGB('FF059669');
            } else {
                $sheet->getStyle('L' . $rowNum)->getFont()->getColor()->setARGB('FFDC2626');
            }

            $rowNum++;
        }

        if ($startDataRow < $rowNum) {
            $sheet->getStyle('A' . $startDataRow . ':L' . ($rowNum - 1))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

        foreach (range('A', 'L') as $columnID) {
            if ($columnID === 'A') {
                $sheet->getColumnDimension('A')->setWidth(14);
            } else {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'export_consumibles_');
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($tempFile);

        $name = 'Exportacion_Consumibles_' . date('Y-m-d_H-i') . '.xlsx';

        return response()->download($tempFile, $name)->deleteFileAfterSend(true);
    }
}
