<?php

namespace App\Http\Controllers;

use App\Support\PanelDocumentos;
use Illuminate\Http\Request;
use App\Models\Documentacion;
use Carbon\Carbon;

class HistorialDocumentosController extends Controller
{
    /**
     * Margen, en segundos, para dar por suya la correccion anexa de un evento del
     * historial. anexarDoc guarda el anexo y su log seguidos, en la misma peticion:
     * medido sobre los registros reales de esta base, la diferencia es 0 s cuando el
     * anexo sigue vivo, y de HORAS cuando el que le tocaba se borro. Cinco segundos
     * separan los dos casos de sobra sin llegar a rozar el siguiente anexo.
     */
    private const ANEXO_MARGEN_SEG = 5;

    /** Margen para dar por hecha "a la vez" la subida de un PDF y el guardado de sus datos. */
    private const MARGEN_SUBIDA_SEG = 120;

    /**
     * Construye el identificador de equipo para mostrar en la tabla del historial,
     * consistente entre los 3 loops (docs, equipos creados, audit logs). Prefiere
     * Placa → Serial Chasis → ID. Acepta tanto Equipo como (Equipo + placa string).
     */
    private function buildEquipoId($equipo, ?string $placa = null): string
    {
        if (!$equipo) return '#';
        if (!empty($placa)) {
            return 'Placa: ' . $placa;
        }
        // Fallback a la relacion documentacion si esta cargada.
        if (!empty(optional($equipo->documentacion ?? null)->PLACA)) {
            return 'Placa: ' . $equipo->documentacion->PLACA;
        }
        if (!empty($equipo->SERIAL_CHASIS)) {
            return 'Serial Chasis: ' . $equipo->SERIAL_CHASIS;
        }
        return 'ID: ' . $equipo->ID_EQUIPO;
    }

    /**
     * Los apuntes de auditoria de EQUIPOS para construirEventos, sin convertir cada fila en
     * un modelo de Eloquent. Son miles (4.080 hoy, y la verificacion nocturna de documentos
     * añade unos 1.900) y hidratarlos como modelos, con sus casts y sus relaciones, era lo
     * mas caro de toda la pantalla: medido el 18-09-2026, 344 ms y 20 MB contra 62 ms y 8 MB
     * leyendolos crudos.
     *
     * Devuelve objetos con las MISMAS propiedades que el modelo EquipoAuditLog que lee el
     * bucle —CAMBIOS ya decodificado (su cast 'array'), created_at como fecha (su cast
     * 'datetime'), ->equipo con su tipo y su documentacion y ->usuario—, asi que el bucle no
     * cambia. El equipo y el usuario SI se cargan como modelos: son unos pocos cientos y asi
     * conservan sus propias reglas (accesores, casts); lo que se ahorra es hidratar los miles
     * de apuntes.
     */
    private function apuntesLigeros($query): \Illuminate\Support\Collection
    {
        $filas = $query->toBase()->get();

        // Las mismas relaciones que cargaba el with() de antes: equipo CON los borrados
        // (para que un 'delete' siga diciendo que equipo era) y su tipo y su documentacion.
        $equipos = \App\Models\Equipo::withTrashed()->with(['tipo', 'documentacion'])
            ->whereIn('ID_EQUIPO', $filas->pluck('ID_EQUIPO')->filter()->unique()->values())
            ->get()->keyBy('ID_EQUIPO');
        $usuarios = \App\Models\Usuario::whereIn('ID_USUARIO', $filas->pluck('ID_USUARIO')->filter()->unique()->values())
            ->get()->keyBy('ID_USUARIO');

        return $filas->map(function ($f) use ($equipos, $usuarios) {
            $f->CAMBIOS    = is_string($f->CAMBIOS) ? json_decode($f->CAMBIOS, true) : $f->CAMBIOS;
            $f->created_at = $f->created_at ? \Illuminate\Support\Facades\Date::parse($f->created_at) : null;
            $f->equipo     = $f->ID_EQUIPO ? ($equipos[$f->ID_EQUIPO] ?? null) : null;
            $f->usuario    = $f->ID_USUARIO ? ($usuarios[$f->ID_USUARIO] ?? null) : null;
            return $f;
        });
    }

    /**
     * Mapeo tabla-driven de los 6 tipos de documento que la tabla `documentacion`
     * registra via flags FECHA_SUBIDA/SUBIDO_POR. Usado para evitar 6 bloques
     * foreach copiados-pegados en index(). Cada entrada describe la columna de
     * fecha, autor, link, la relacion del usuario y el doc_key/label legacy.
     */
    private const DOC_FIELD_MAP = [
        'propiedad' => [
            'fecha_col' => 'PROPIEDAD_FECHA_SUBIDA',
            'autor_col' => 'PROPIEDAD_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_PROPIEDAD',
            'user_rel'  => 'usuarioPropiedad',
            'label'     => 'Título de Propiedad',
        ],
        'poliza' => [
            'fecha_col' => 'POLIZA_FECHA_SUBIDA',
            'autor_col' => 'POLIZA_SUBIDO_POR',
            'link_col'  => 'LINK_POLIZA_SEGURO',
            'user_rel'  => 'usuarioPoliza',
            'label'     => 'Póliza de Seguro',
        ],
        'rotc' => [
            'fecha_col' => 'ROTC_FECHA_SUBIDA',
            'autor_col' => 'ROTC_SUBIDO_POR',
            'link_col'  => 'LINK_ROTC',
            'user_rel'  => 'usuarioRotc',
            'label'     => 'ROTC',
        ],
        'racda' => [
            'fecha_col' => 'RACDA_FECHA_SUBIDA',
            'autor_col' => 'RACDA_SUBIDO_POR',
            'link_col'  => 'LINK_RACDA',
            'user_rel'  => 'usuarioRacda',
            'label'     => 'RACDA',
        ],
        'adicional' => [
            'fecha_col' => 'ADICIONAL_FECHA_SUBIDA',
            'autor_col' => 'ADICIONAL_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_ADICIONAL',
            'user_rel'  => 'usuarioAdicional',
            'label'     => 'Certificado Asociado',
        ],
        'adicional_2' => [
            'fecha_col' => 'ADICIONAL_2_FECHA_SUBIDA',
            'autor_col' => 'ADICIONAL_2_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_ADICIONAL_2',
            'user_rel'  => 'usuarioAdicional2',
            'label'     => 'Compraventa',
        ],
    ];

    /**
     * Versión de la caché de eventos del historial (ver construirEventos): cambia
     * con cada escritura y hace que la entrada cacheada se considere obsoleta.
     *
     * Quién la bumpea:
     *   · EquipoAuditLog::booted y CatalogoAuditLog::booted (created/deleted) — cubren
     *     las subidas/borrados de PDF (upload_X / delete_X), las ediciones de equipo y
     *     de PLACA ('edit', vía EquipoObserver / DocumentacionObserver) y el catálogo.
     *   · EquipoObserver created/deleted — el alta y la baja de un equipo cambian la
     *     fila "Registro de Vehículo"; hay caminos que NO dejan auditoría (importación
     *     masiva) o que borran los logs por query builder (forceDeleteEquipo).
     *   · deleteRegistro — borra logs por query builder, sin eventos de modelo.
     *
     * Queda fuera a propósito: renombrar un usuario o un auxiliar cambia solo la
     * ETIQUETA mostrada, no el conjunto de eventos; se refresca al vencer el TTL.
     *
     * A DIFERENCIA del dashboard (DashboardController::DATA_VER_KEY), aquí la versión
     * NO va en la clave sino DENTRO del valor: el payload pesa ~2 MB y con la versión
     * en la clave cada bump dejaría una fila huérfana de 2 MB en la tabla `cache`
     * (el driver database solo purga una entrada caducada cuando alguien la lee, y a
     * esa clave vieja ya nadie vuelve). Con la clave estable, cada rebuild sobrescribe
     * su propia fila.
     */
    public const DATA_VER_KEY = 'historial_docs_ver';

    public static function bumpDataVersion(): void
    {
        \App\Support\CacheVersion::bump(self::DATA_VER_KEY);
        self::programarCalentado();
    }

    /** Ya se programo el calentado en ESTA peticion (una subida crea varios apuntes). */
    private static bool $calentadoProgramado = false;

    /**
     * Rehacer la lista DESPUES de responder, no cuando alguien abre la pantalla.
     *
     * Armarla cuesta ~2 s en el servidor (medido: 1.950 ms de los 2,5 s que tardaba el modulo
     * en abrir) y se invalida en CADA cambio del historial. Ese precio lo paga —despues de que
     * su respuesta ya salio— la misma peticion o comando que hizo el cambio, y la deja lista
     * para TODOS los que pueden abrir el modulo (ver calentar). Antes solo se calentaba la del
     * usuario que hizo el cambio, y nunca tras los cambios de la tarea nocturna de documentos:
     * cualquier otro que entrara pagaba los 2 s.
     *
     * Solo para la vista SIN filtros, que es como se abre el modulo. Una busqueda concreta
     * se arma cuando se pide, como siempre.
     */
    private static function programarCalentado(): void
    {
        if (self::$calentadoProgramado) return;
        self::$calentadoProgramado = true;

        app()->terminating(function () {
            self::$calentadoProgramado = false;
            try {
                app(self::class)->calentar();
            } catch (\Throwable $e) {
                // Que falle el calentado no puede romper nada: la pantalla la armara sola.
                \Illuminate\Support\Facades\Log::warning('No se pudo calentar el historial: ' . $e->getMessage());
            }
        });
    }

    /**
     * Deja en cache la vista SIN filtros de cada alcance distinto (frentes visibles y
     * bloqueados) entre los usuarios que pueden abrir el modulo: super.admin, el mismo permiso
     * de la ruta. Casi todos ven lo mismo, asi que suele ser una sola lista.
     */
    public function calentar(): void
    {
        $ver     = \App\Support\CacheVersion::current(self::DATA_VER_KEY);
        $almacen = \Illuminate\Support\Facades\Cache::store('file');

        $alcances = \App\Models\Usuario::all()
            ->filter(fn ($u) => \Illuminate\Support\Facades\Gate::forUser($u)->allows('super.admin'))
            ->map(fn ($u) => [$u->frentesVisiblesEquiposIds(), $u->getFrentesBloqueadosIds()])
            ->unique(fn ($alcance) => json_encode($alcance));

        foreach ($alcances as [$frentesVisibles, $frentesBloqueados]) {
            $clave    = self::claveDe($frentesVisibles, $frentesBloqueados, []);
            $guardado = $almacen->get($clave);
            if (is_array($guardado) && ($guardado['ver'] ?? null) === $ver) continue;   // ya estaba al dia

            $events = collect($this->construirEventos(new Request(), $frentesVisibles, $frentesBloqueados, null, null, ''));
            $almacen->put($clave, ['ver' => $ver, 'events' => $events->all()], now()->addHours(6));
        }
    }

    /**
     * La clave del conjunto de eventos: el scope de frentes (asi un cambio de permisos cambia
     * la clave sola) + la firma de los filtros. NO el usuario: la lista solo depende de lo que
     * se puede ver, y compartirla entre quienes ven lo mismo es lo que permite dejarla hecha
     * para todos. `page` NO entra: es lo que se reutiliza. UN solo sitio, porque la usan
     * index() y calentar() y tienen que coincidir exactamente.
     */
    private static function claveDe($frentesVisibles, $frentesBloqueados, array $filtros): string
    {
        return 'historial_docs_' . md5(json_encode([$frentesVisibles, $frentesBloqueados, $filtros]));
    }

    public function index(Request $request)
    {
        // Control de Auditoría tiene tres pestañas: el historial (esta) y las dos de
        // documentos, que pinta admin/compresion_pdf/panel.blade.php con los datos de
        // App\Support\PanelDocumentos. Cuando el usuario esta en una de esas, NO se arma la
        // lista de eventos: es el trabajo caro de esta pantalla y no se ve.
        $pestana = $request->input('pestana');
        if (PanelDocumentos::esPestana($pestana)) {
            return view('admin.historial_documentos.index', PanelDocumentos::datos($request, $pestana));
        }

        // ── Scope LOCAL ─────────────────────────────────────────────────────
        // Usuarios NIVEL_ACCESO_EQUIPOS=2 (local) solo ven el historial de equipos en
        // los frentes que tienen asignados. Sin frentes => ven nada.
        // Los super.admin / global ven todo el historial.
        $user            = auth()->user();
        // null = ve todo (global) | [] = local sin frentes | [ids] (Usuario::frentesVisiblesEquiposIds).
        $frentesVisibles = $user ? $user->frentesVisiblesEquiposIds() : [];
        // Lista negra: frentes a OCULTAR siempre (también a GLOBAL).
        $frentesBloqueados = $user ? $user->getFrentesBloqueadosIds() : [];

        // Pre-filtros que se aplican en SQL (no en memoria) para escalar bien.
        // Si el dataset crece a decenas de miles, esto evita cargar todo a RAM.
        $fechaDesdeSql = $request->filled('fecha_desde')
            ? Carbon::parse($request->fecha_desde)->startOfDay()
            : null;
        $fechaHastaSql = $request->filled('fecha_hasta')
            ? Carbon::parse($request->fecha_hasta)->endOfDay()
            : null;
        $searchEquipoSql = trim((string) $request->input('search_equipo', ''));

        // ── CACHÉ de la lista completa de eventos ───────────────────────────
        // Construirla cuesta ~1.8 s de request completo (4 queries de hasta 5000 filas
        // + dedup + sort en PHP) y ANTES se rehacía entera en CADA clic de paginación
        // solo para devolver 15 filas — de ahí la lentitud al navegar entre páginas.
        // Ahora pasar de página es un slice en memoria sobre la lista ya cacheada
        // (~250 ms de request, medido end-to-end en el navegador).
        // La clave identifica el CONJUNTO de eventos: el scope de frentes + la firma de
        // todos los filtros que afectan al resultado (ver claveDe). La versión
        // (DATA_VER_KEY) viaja en el VALOR, no en la clave — ver el comentario de la
        // constante.
        $ver      = \App\Support\CacheVersion::current(self::DATA_VER_KEY);
        $cacheKey = self::claveDe($frentesVisibles, $frentesBloqueados,
            $request->only(['fecha_desde', 'fecha_hasta', 'search_equipo', 'search_correo', 'search_tipo', 'hd_ids']));

        // Esta lista va al caché de ARCHIVO, no al de base de datos (el de por defecto).
        // Pesa ~3 MB y medido en esta maquina: guardarla en MySQL cuesta 116 ms y en archivo
        // 34 ms; leerla cuesta lo mismo en los dos (~42 ms). Son 82 ms menos cada vez que
        // hay que reconstruirla, que es en cada cambio del historial. De paso desaparece el
        // problema de las filas huerfanas de 3 MB que explica el comentario de DATA_VER_KEY.
        $almacen = \Illuminate\Support\Facades\Cache::store('file');

        $cached = $almacen->get($cacheKey);
        if (is_array($cached) && ($cached['ver'] ?? null) === $ver) {
            $events = collect($cached['events']);
        } else {
            $events = collect($this->construirEventos(
                $request, $frentesVisibles, $frentesBloqueados,
                $fechaDesdeSql, $fechaHastaSql, $searchEquipoSql
            ));
            $almacen->put(
                $cacheKey,
                ['ver' => $ver, 'events' => $events->all()],
                // 6 horas, no 10 minutos: la version va DENTRO del valor, asi que una lista
                // pasada de fecha NUNCA se sirve (se compara y se rehace). Con 10 minutos se
                // enfriaba sola en cada pausa y habia que pagar los 2 s otra vez sin que
                // hubiera cambiado nada.
                now()->addHours(6)
            );
        }

        $total = $events->count();

        // Paginación manual (LengthAwarePaginator) sobre la colección de eventos.
        $perPage = 15;
        $page = $request->input('page', 1);

        $paginatedEvents = new \Illuminate\Pagination\LengthAwarePaginator(
            $events->forPage($page, $perPage)->values(), // important: values() to reset keys for blade loop
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // La respuesta AJAX (paginación y filtros) solo necesita filas + paginador,
        // por eso sale ANTES de consultar IPs bloqueadas, sesiones activas y autores:
        // esos 3 queries solo alimentan la carga inicial de la vista completa.
        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('admin.historial_documentos.partials.table_rows', ['events' => $paginatedEvents])->render(),
                'pagination' => $paginatedEvents->links('vendor.pagination.custom-sliding')->toHtml(),
                'total' => $total
            ]);
        }

        // Autores (nombre + correo) → autocompletado del filtro "Buscar por nombre o
        // correo del autor". Se sugieren ambos para que el usuario ubique por cualquiera.
        $autoresSugeridos = \App\Models\Usuario::whereNotNull('CORREO_ELECTRONICO')
            ->where('CORREO_ELECTRONICO', '!=', '')
            ->orderBy('NOMBRE_COMPLETO')
            ->get(['NOMBRE_COMPLETO', 'CORREO_ELECTRONICO'])
            ->map(fn ($u) => [
                'nombre' => (string) ($u->NOMBRE_COMPLETO ?? ''),
                'correo' => (string) $u->CORREO_ELECTRONICO,
            ])
            ->values();

        return view('admin.historial_documentos.index', [
            'pestana'          => 'historial',
            // Solo para el numero del boton "Documentos" (cuantos hay que mirar a mano).
            'docsParaRevisar'  => \App\Models\VerificacionDocumento::paraRevisar()->count(),
            'events'           => $paginatedEvents,
            'total'            => $total,
            'autoresSugeridos' => $autoresSugeridos,
        ]);
    }

    /**
     * Construye la lista COMPLETA de eventos del historial, ya deduplicada, ordenada
     * y filtrada. Mezcla 4 fuentes: subidas de documento (flags en `documentacion`),
     * creación de equipos, auditoría de equipos/auxiliares y auditoría del catálogo.
     *
     * Es el trabajo caro de la pantalla; vive separado de index() para que ese pueda
     * saltárselo cuando la lista ya está en caché (ver DATA_VER_KEY).
     *
     * @param  array|null  $frentesVisibles  null = ve todo (GLOBAL) | [] = local sin frentes | [ids]
     * @return array  Eventos (stdClass) ordenados por fecha desc.
     */
    private function construirEventos(
        Request $request,
        ?array $frentesVisibles,
        array $frentesBloqueados,
        ?Carbon $fechaDesdeSql,
        ?Carbon $fechaHastaSql,
        string $searchEquipoSql
    ): array {
        // Helper que aplica el scope LOCAL a un query Eloquent que tiene relacion
        // 'equipo'. Usado en Documentacion y EquipoAuditLog (que SI tienen relacion).
        $applyLocalScopeViaWhereHas = function ($query) use ($frentesVisibles, $frentesBloqueados) {
            // Lista blanca (whitelist LOCAL):
            if ($frentesVisibles !== null) {       // null = global → ve todo (sin whitelist)
                if (empty($frentesVisibles)) {
                    $query->whereRaw('1 = 0'); // local sin frentes => sin resultados
                    return;                    // ya no ve nada, la lista negra es irrelevante
                }
                $query->whereHas('equipo', function ($q) use ($frentesVisibles) {
                    $q->withTrashed()->whereIn('ID_FRENTE_ACTUAL', $frentesVisibles);
                });
            }
            // Lista negra (también para GLOBAL): ocultar historial de equipos en frentes bloqueados.
            if (!empty($frentesBloqueados)) {
                $query->whereHas('equipo', function ($q) use ($frentesBloqueados) {
                    $q->withTrashed()->whereNotIn('ID_FRENTE_ACTUAL', $frentesBloqueados);
                });
            }
        };

        // Helper que aplica el filtro search_equipo (placa/serial/codigo) en SQL.
        $applySearchEquipoViaWhereHas = function ($query) use ($searchEquipoSql) {
            if ($searchEquipoSql === '') return;
            $like = '%' . strtoupper($searchEquipoSql) . '%';
            $query->whereHas('equipo', function ($q) use ($like) {
                $q->withTrashed()->where(function ($w) use ($like) {
                    $w->whereRaw('UPPER(SERIAL_CHASIS) like ?', [$like])
                      ->orWhereRaw('UPPER(CODIGO_PATIO) like ?', [$like])
                      ->orWhereHas('documentacion', function ($qd) use ($like) {
                          $qd->whereRaw('UPPER(PLACA) like ?', [$like]);
                      });
                });
            });
        };

        // 1. Documentacion con upload — pre-filtros + LIMIT.
        $docsQuery = Documentacion::with([
                'equipo' => function ($q) { $q->withTrashed()->with('tipo'); },
                'usuarioPropiedad', 'usuarioPoliza', 'usuarioRotc',
                'usuarioRacda',     'usuarioAdicional', 'usuarioAdicional2',
            ])
            ->where(function ($q) {
                foreach (self::DOC_FIELD_MAP as $cfg) {
                    $q->orWhereNotNull($cfg['fecha_col']);
                }
            });

        $applyLocalScopeViaWhereHas($docsQuery);
        $applySearchEquipoViaWhereHas($docsQuery);

        // Filtro fecha en SQL: si hay rango, exigimos que AL MENOS una de las 6
        // fechas de subida caiga en el rango. Acelera muchisimo en datasets grandes.
        if ($fechaDesdeSql || $fechaHastaSql) {
            $docsQuery->where(function ($q) use ($fechaDesdeSql, $fechaHastaSql) {
                foreach (self::DOC_FIELD_MAP as $cfg) {
                    $q->orWhere(function ($qq) use ($cfg, $fechaDesdeSql, $fechaHastaSql) {
                        $qq->whereNotNull($cfg['fecha_col']);
                        if ($fechaDesdeSql) $qq->where($cfg['fecha_col'], '>=', $fechaDesdeSql);
                        if ($fechaHastaSql) $qq->where($cfg['fecha_col'], '<=', $fechaHastaSql);
                    });
                }
            });
        }

        $docs = $docsQuery->limit(5000)->get();

        // 2. Parse them into a flat array of "upload events".
        // Las 6 entradas de DOC_FIELD_MAP eliminan 6 bloques foreach copiados.
        // Las fechas vienen como Carbon gracias a los casts del modelo Documentacion
        // (PROPIEDAD_FECHA_SUBIDA, etc. = 'datetime'), no requiere Carbon::parse.
        $events = collect();

        foreach ($docs as $doc) {
            $eName = $doc->equipo
                ? ($doc->equipo->tipo->nombre ?? 'Equipo') . ' ' . $doc->equipo->MARCA . ' ' . $doc->equipo->MODELO
                : 'Equipo Eliminado';
            // La documentacion del equipo ES $doc (relacion hasOne). Se inyecta para
            // que el fallback de buildEquipoId la lea de aqui en vez de dispararla
            // como lazy load: sin esto eran ~134 queries extra (`select * from
            // documentacion where ID_EQUIPO = ?`), el 60% del SQL de la pagina.
            if ($doc->equipo) {
                $doc->equipo->setRelation('documentacion', $doc);
            }
            $eId   = $this->buildEquipoId($doc->equipo, $doc->PLACA ?? null);

            foreach (self::DOC_FIELD_MAP as $docKey => $cfg) {
                $fecha = $doc->{$cfg['fecha_col']};
                $autorId = $doc->{$cfg['autor_col']};
                if (!$fecha || !$autorId) continue;

                $autorRel = $doc->{$cfg['user_rel']};
                $autor = $autorRel ? $autorRel->CORREO_ELECTRONICO : $autorId;
                // Nombre del autor → permite filtrar/ubicar por nombre además del correo.
                $autorNombre = $autorRel ? ($autorRel->NOMBRE_COMPLETO ?? '') : '';

                $events->push((object)[
                    'doc_key'      => $docKey,
                    'tipo'         => $cfg['label'],
                    'autor'        => $autor,
                    'autor_nombre' => $autorNombre,
                    'fecha'        => $fecha,
                    'link'         => $doc->{$cfg['link_col']},
                    'equipo_nombre'=> $eName,
                    'equipo_id'    => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null,
                    'cambios'      => [],
                    // NO es registro propio: el "evento" es una bandera en `documentacion`.
                    // deleteRegistro lo BLOQUEA (borrarlo quitaría el documento real).
                    'del_source'   => 'doc',
                    'del_id'       => null,
                ]);
            }
        }

        // Eventos de "Registro de Vehiculo" (creacion). Eager-load del creador
        // restringido a las columnas necesarias + pre-filtros en SQL + LIMIT.
        $equiposQuery = \App\Models\Equipo::with([
                'tipo:id,nombre',
                'creador:ID_USUARIO,CORREO_ELECTRONICO,NOMBRE_COMPLETO',
                'documentacion:ID_EQUIPO,PLACA',
            ])
            ->whereNotNull('CREADO_POR');

        // Scope en SQL: lista blanca (LOCAL) + lista negra de bloqueados (también GLOBAL).
        \App\Models\Usuario::aplicarScopeIds($equiposQuery, $frentesVisibles, 'ID_FRENTE_ACTUAL');
        \App\Models\Usuario::aplicarBloqueoIds($equiposQuery, $frentesBloqueados, 'ID_FRENTE_ACTUAL');

        // search_equipo en SQL (placa/serial/codigo).
        if ($searchEquipoSql !== '') {
            $like = '%' . strtoupper($searchEquipoSql) . '%';
            $equiposQuery->where(function ($q) use ($like) {
                $q->whereRaw('UPPER(SERIAL_CHASIS) like ?', [$like])
                  ->orWhereRaw('UPPER(CODIGO_PATIO) like ?', [$like])
                  ->orWhereHas('documentacion', function ($qd) use ($like) {
                      $qd->whereRaw('UPPER(PLACA) like ?', [$like]);
                  });
            });
        }

        // Filtro fecha en SQL aplicado a created_at del equipo.
        if ($fechaDesdeSql) $equiposQuery->where('created_at', '>=', $fechaDesdeSql);
        if ($fechaHastaSql) $equiposQuery->where('created_at', '<=', $fechaHastaSql);

        $equiposCreados = $equiposQuery->orderByDesc('created_at')->limit(5000)->get();

        foreach ($equiposCreados as $equipo) {
            $events->push((object)[
                'doc_key'      => 'creacion',
                'tipo'         => 'Registro de Vehículo',
                'autor'        => $equipo->creador ? $equipo->creador->CORREO_ELECTRONICO : 'Usuario Desconocido',
                'autor_nombre' => $equipo->creador ? ($equipo->creador->NOMBRE_COMPLETO ?? '') : '',
                'fecha'        => $equipo->created_at,
                'link'         => null,
                'equipo_nombre'=> ($equipo->tipo->nombre ?? 'Equipo') . ' ' . $equipo->MARCA . ' ' . $equipo->MODELO,
                'equipo_id'    => $this->buildEquipoId($equipo),
                'equipo_db_id' => $equipo->ID_EQUIPO,
                'cambios'      => [],
                // NO es registro propio: borrarlo sería borrar el VEHÍCULO. deleteRegistro lo BLOQUEA.
                'del_source'   => 'equipo_creacion',
                'del_id'       => $equipo->ID_EQUIPO,
            ]);
        }
        // IDs de equipos que YA produjeron una fila 'creacion' aquí. Sirve para no
        // duplicar la creación con el log 'create' del audit (ver loop de auditoría).
        $creacionEquipoIds = array_flip($equiposCreados->pluck('ID_EQUIPO')->all());

        // Eventos de AUDITORIA de equipos (ediciones, cambios de metadata, ubicacion).
        // Se cargan desde la tabla `equipo_audit_log` con eager loading de
        // equipo + tipo + documentacion + usuario para evitar N+1 y poder mostrar
        // PLACA en el equipo_id (consistente con los otros loops).
        try {
            // withTrashed: incluye equipos soft-deleted asi los logs de
            // tipo 'delete' tambien muestran tipo/marca/modelo del equipo
            // borrado en lugar de un generico "Equipo Eliminado".
            // Sin with(): el equipo y el usuario de cada apunte los pone apuntesLigeros(),
            // que es la forma barata de traer miles de apuntes (ver su comentario).
            $auditQuery = \App\Models\EquipoAuditLog::query()
                ->whereNull('ID_AUXILIAR')   // los logs de auxiliar se procesan en su propio loop
                ->where('ACCION', '!=', 'movilizacion')
                ->orderByDesc('created_at');

            // Scope LOCAL + search_equipo + rango de fechas en SQL.
            $applyLocalScopeViaWhereHas($auditQuery);
            $applySearchEquipoViaWhereHas($auditQuery);
            if ($fechaDesdeSql) $auditQuery->where('created_at', '>=', $fechaDesdeSql);
            if ($fechaHastaSql) $auditQuery->where('created_at', '<=', $fechaHastaSql);

            $auditLogs = $this->apuntesLigeros($auditQuery->limit(5000));

            // ── PDF de las CORRECCIONES ANEXAS ──────────────────────────────────────
            // El audit log guarda el evento pero no la URL, asi que la fila salia sin boton
            // y la correccion no se podia abrir desde este modulo. El enlace se lee de
            // documento_anexos y NO se copia al log a proposito: asi un anexo borrado deja
            // de ofrecer un PDF que ya no existe, en vez de apuntar a un archivo muerto.
            //
            // Se casan por TIEMPO, no por etiqueta: anexarDoc crea el anexo y escribe el log
            // seguido, en la misma peticion, asi que el registro que le corresponde esta a
            // menos de un segundo. La etiqueta NO sirve de clave — se numera contando los
            // anexos vivos, asi que al borrar uno y anexar otro el numero se repite: en esta
            // misma base hay tres eventos distintos con 'Correccion 1' y casarlos por ahi le
            // colgaria a los tres el PDF del ultimo, que es OTRO archivo. Un modulo de
            // auditoria no puede enseñar un documento que no es el del evento.
            //
            // Una sola consulta para todo el lote, agrupada por equipo+tipo.
            $anexosPorEquipoTipo = collect();
            $equiposConAnexo = $auditLogs
                ->filter(fn ($l) => \Illuminate\Support\Str::startsWith($l->ACCION, 'anexo_'))
                ->pluck('ID_EQUIPO')->filter()->unique()->all();
            if (!empty($equiposConAnexo)) {
                $anexosPorEquipoTipo = \App\Models\DocumentoAnexo::whereIn('ID_EQUIPO', $equiposConAnexo)
                    ->get(['ID_ANEXO', 'ID_EQUIPO', 'TIPO_DOC', 'LINK', 'created_at'])
                    ->groupBy(fn ($a) => $a->ID_EQUIPO . '|' . $a->TIPO_DOC);
            }

            foreach ($auditLogs as $log) {
                $eq = $log->equipo;

                // Dedup de la CREACIÓN: el log 'create' del audit duplica la fila 'creacion'
                // del loop anterior (mismo vehículo, misma hora). Si el equipo ya salió ahí, se
                // omite. Los equipos BORRADOS no aparecen en $equiposCreados (no withTrashed),
                // así que su 'create' SÍ se conserva → su creación no se pierde.
                if ($log->ACCION === 'create' && $eq && isset($creacionEquipoIds[$eq->ID_EQUIPO])) {
                    continue;
                }

                $eName = $eq ? (($eq->tipo->nombre ?? 'Equipo') . ' ' . $eq->MARCA . ' ' . $eq->MODELO) : 'Equipo Eliminado';
                $eId   = $this->buildEquipoId($eq);
                // Mapping cubre solo las ACCION values que el codigo realmente
                // registra (verificado por busqueda de EquipoAuditLog::registrar
                // en EquipoController + EquipoObserver). Si en el futuro se agregan
                // nuevas acciones (status_change, ubicacion individual, etc.) se
                // deben mapear aqui Y agregar como opcion al dropdown del filtro.
                $tipoLabel = [
                    'edit'                 => 'Edición de Datos',
                    'metadata_propiedad'   => 'Edición Metadata Propiedad',
                    'metadata_poliza'      => 'Edición Metadata Póliza',
                    'metadata_rotc'        => 'Edición Metadata ROTC',
                    'metadata_racda'       => 'Edición Metadata RACDA',
                    'metadata_adicional'   => 'Edición Metadata Certificado',
                    'metadata_adicional_2' => 'Edición Metadata Compraventa',
                    'upload_propiedad'     => 'Subida Propiedad',
                    'upload_poliza'        => 'Subida Póliza',
                    'upload_rotc'          => 'Subida ROTC',
                    'upload_racda'         => 'Subida RACDA',
                    'upload_adicional'     => 'Subida Certificado',
                    'upload_adicional_2'   => 'Subida Compraventa',
                    'delete_propiedad'     => 'Borrado Propiedad',
                    'delete_poliza'        => 'Borrado Póliza',
                    'delete_rotc'          => 'Borrado ROTC',
                    'delete_racda'         => 'Borrado RACDA',
                    'delete_adicional'     => 'Borrado Certificado',
                    'delete_adicional_2'   => 'Borrado Compraventa',
                    // Correcciones anexas (anexarDoc). NO son subidas del principal: ese
                    // endpoint solo AÑADE y nunca pisa el archivo anterior, por eso llevan
                    // verbo propio y categoria propia en el filtro ('cat_anexos').
                    // Se rotulan con el MISMO nombre que la pestaña del visor
                    // (EquipoController::ROTULO_ANEXO) y se les añade a que documento
                    // corrigen, que es lo unico que distingue una fila de otra: esta
                    // columna es el unico sitio de la tabla donde sale el documento.
                    'anexo_propiedad'      => EquipoController::ROTULO_ANEXO . ' · Propiedad',
                    'anexo_poliza'         => EquipoController::ROTULO_ANEXO . ' · Póliza',
                    'anexo_rotc'           => EquipoController::ROTULO_ANEXO . ' · ROTC',
                    'anexo_racda'          => EquipoController::ROTULO_ANEXO . ' · RACDA',
                    'anexo_adicional'      => EquipoController::ROTULO_ANEXO . ' · Certificado',
                    'anexo_adicional_2'    => EquipoController::ROTULO_ANEXO . ' · Compraventa',
                    // Borrado de una correccion (eliminarAnexo). Estaba registrandose sin
                    // rotulo aqui, asi que la primera que alguien borrara habria salido como
                    // "Delete anexo propiedad" — el respaldo generico de mas abajo. Solo estos
                    // dos tipos porque son los unicos que admiten anexos
                    // (EquipoController::TIPOS_CON_ANEXOS).
                    'delete_anexo_propiedad' => 'Borrado ' . EquipoController::ROTULO_ANEXO . ' · Propiedad',
                    'delete_anexo_poliza'    => 'Borrado ' . EquipoController::ROTULO_ANEXO . ' · Póliza',
                    'bulk_ubicacion'       => 'Detalle Masivo',
                    'create'               => 'Registro de Vehículo',
                    'delete'               => 'Eliminación de Equipo',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $cambiosRaw = is_array($log->CAMBIOS) ? $log->CAMBIOS : (is_string($log->CAMBIOS) ? json_decode($log->CAMBIOS, true) : []);

                // El historial de documentos NO muestra CONFIRMACIONES (CONFIRMADO_EN_SITIO)
                // ni MOVILIZACIONES. Aparte del log explícito 'movilizacion' (ya excluido en
                // el query), el EquipoObserver registra el cambio de frente/ubicación al
                // movilizar como un 'edit'; lo filtramos de los cambios. Si un 'edit' se queda
                // sin cambios visibles tras el filtro, se omite por completo.
                if (is_array($cambiosRaw)) {
                    unset(
                        $cambiosRaw['CONFIRMADO_EN_SITIO'],
                        $cambiosRaw['ID_FRENTE_ACTUAL'],
                        $cambiosRaw['DETALLE_UBICACION_ACTUAL']
                    );
                }
                if ($log->ACCION === 'edit' && empty($cambiosRaw)) {
                    continue;
                }

                // ── Cambios de ESTADO OPERATIVO con etiqueta propia ──────────────────
                // Parar un equipo, devolverlo a trabajar o desincorporarlo es lo que la
                // operación necesita ver de un vistazo, y hasta aquí caía en el genérico
                // "Edición de Datos" mezclado con cualquier otra edición (cambiar el modelo,
                // el código de patio…): no había forma de filtrarlos.
                //
                // Se clasifica al LEER y no al escribir a propósito:
                //   · el diff con ESTADO_OPERATIVO ya viene guardado por EquipoObserver, así
                //     que TODO el historial anterior queda reetiquetado sin migrar nada;
                //   · no hace falta un segundo log por cada guardado (sería un evento
                //     duplicado para el mismo cambio).
                // Cubre TODAS las vías porque todas guardan el modelo: el chip de la lista,
                // el modal de detalles, la APK (mobileChangeStatus) y el paso automático a
                // INOPERATIVO / OPERATIVO al abrir y cerrar un reporte de falla.
                // Si el guardado tocó además otros campos, el evento se rotula por el estado
                // (es lo importante) y la columna "ver cambios" sigue mostrándolos todos.
                if ($log->ACCION === 'edit' && is_array($cambiosRaw) && isset($cambiosRaw['ESTADO_OPERATIVO'])) {
                    $estAntes   = mb_strtoupper(trim((string) ($cambiosRaw['ESTADO_OPERATIVO']['antes'] ?? '')));
                    $estDespues = mb_strtoupper(trim((string) ($cambiosRaw['ESTADO_OPERATIVO']['despues'] ?? '')));
                    $tipoLabel  = match (true) {
                        $estDespues === 'DESINCORPORADO' => 'Desincorporación',
                        $estAntes   === 'DESINCORPORADO' => 'Reincorporación',
                        default                          => 'Cambio de Estado',
                    };
                }

                // El PDF de una correccion anexa (ver la nota de arriba). Para el resto de
                // acciones del audit log no hay enlace que enseñar: la tabla solo pinta el
                // boton cuando 'link' viene lleno. Si no hay ningun anexo dentro del margen,
                // el suyo se borro y la fila se queda sin boton, que es lo correcto.
                $linkEvento = null;
                if (\Illuminate\Support\Str::startsWith($log->ACCION, 'anexo_')) {
                    $candidatos = $anexosPorEquipoTipo->get(
                        $log->ID_EQUIPO . '|' . substr($log->ACCION, strlen('anexo_')),
                        collect()
                    );
                    $mejorDif = null;
                    foreach ($candidatos as $a) {
                        if (!$a->created_at) continue;
                        $dif = abs($a->created_at->diffInSeconds($log->created_at));
                        if ($dif <= self::ANEXO_MARGEN_SEG && ($mejorDif === null || $dif < $mejorDif)) {
                            $mejorDif   = $dif;
                            $linkEvento = $a->LINK;
                        }
                    }
                }

                $events->push((object)[
                    'doc_key'       => $log->ACCION,
                    'tipo'          => $tipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ($log->ID_USUARIO ? 'Usuario #' . $log->ID_USUARIO . ' (eliminado)' : 'Sistema'),
                    'autor_nombre'  => $log->usuario ? ($log->usuario->NOMBRE_COMPLETO ?? '') : '',
                    'fecha'         => $log->created_at,
                    'link'          => $linkEvento,
                    'equipo_nombre' => $eName,
                    'equipo_id'     => $eId,
                    'equipo_db_id'  => $eq ? $eq->ID_EQUIPO : null,
                    'cambios'       => $cambiosRaw,
                    // Borrado de registro (solo super.admin, ver deleteRegistro): esta fila
                    // SÍ es un registro propio (equipo_audit_log) → se puede borrar.
                    'del_source'    => 'equipo_audit',
                    'del_id'        => $log->ID_LOG,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Tabla no existente / driver distinto → no rompe la vista, solo skip.
            \Illuminate\Support\Facades\Log::warning('audit log read failed: ' . $e->getMessage());
        }

        // Eventos de AUDITORIA de equipos AUXILIARES (ediciones + subidas/borrados de PDF).
        // Viven en la MISMA tabla equipo_audit_log pero con ID_AUXILIAR != null (ID_EQUIPO
        // null): un auxiliar puede no estar anclado a ningún vehículo host. Por eso el scope
        // de visibilidad se resuelve por el FRENTE PROPIO del auxiliar, no por un equipo host.
        try {
            $auxAuditQuery = \App\Models\EquipoAuditLog::with([
                    'auxiliar' => function ($q) { $q->withTrashed(); },
                    'usuario',
                ])
                ->whereNotNull('ID_AUXILIAR')
                ->orderByDesc('created_at');

            // Scope LOCAL (lista blanca) + lista negra por el ID_FRENTE_ACTUAL del auxiliar.
            if ($frentesVisibles !== null) {
                if (empty($frentesVisibles)) {
                    $auxAuditQuery->whereRaw('1 = 0'); // local sin frentes => nada
                } else {
                    $auxAuditQuery->whereHas('auxiliar', function ($q) use ($frentesVisibles) {
                        $q->withTrashed()->whereIn('ID_FRENTE_ACTUAL', $frentesVisibles);
                    });
                }
            }
            if (!empty($frentesBloqueados)) {
                $auxAuditQuery->whereHas('auxiliar', function ($q) use ($frentesBloqueados) {
                    $q->withTrashed()->whereNotIn('ID_FRENTE_ACTUAL', $frentesBloqueados);
                });
            }
            // search_equipo por SERIAL / CÓDIGO INTERNO del propio auxiliar.
            if ($searchEquipoSql !== '') {
                $like = '%' . strtoupper($searchEquipoSql) . '%';
                $auxAuditQuery->whereHas('auxiliar', function ($q) use ($like) {
                    $q->withTrashed()->where(function ($w) use ($like) {
                        $w->whereRaw('UPPER(SERIAL) like ?', [$like])
                          ->orWhereRaw('UPPER(CODIGO_INTERNO) like ?', [$like]);
                    });
                });
            }
            if ($fechaDesdeSql) $auxAuditQuery->where('created_at', '>=', $fechaDesdeSql);
            if ($fechaHastaSql) $auxAuditQuery->where('created_at', '<=', $fechaHastaSql);

            // Labels de tipo de auxiliar (código → etiqueta legible), resueltos una vez.
            $auxTiposLabel = \App\Models\EquipoAuxiliar::tiposLabel();

            $auxAuditLogs = $auxAuditQuery->limit(5000)->get();
            foreach ($auxAuditLogs as $log) {
                $ax = $log->auxiliar;

                // Label "Edición de Datos (Auxiliar)" contiene "Edición de Datos" a propósito:
                // así el filtro de Tipo "Edición de Datos" (match por substring) lo incluye.
                $tipoLabel = [
                    'aux_edit'               => 'Edición de Datos (Auxiliar)',
                    'aux_upload_propiedad'   => 'Subida Propiedad (Auxiliar)',
                    'aux_upload_certificado' => 'Subida Certificado (Auxiliar)',
                    'aux_delete_propiedad'   => 'Borrado Propiedad (Auxiliar)',
                    'aux_delete_certificado' => 'Borrado Certificado (Auxiliar)',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $cambiosRaw = is_array($log->CAMBIOS) ? $log->CAMBIOS : (is_string($log->CAMBIOS) ? json_decode($log->CAMBIOS, true) : []);
                $auxLabel = $cambiosRaw['_aux_label'] ?? '';
                if (is_array($cambiosRaw)) {
                    unset(
                        $cambiosRaw['_aux_label'],
                        $cambiosRaw['CONFIRMADO_EN_SITIO'],
                        $cambiosRaw['ID_FRENTE_ACTUAL'],
                        $cambiosRaw['DETALLE_UBICACION_ACTUAL']
                    );
                }
                // Un aux_edit que solo tocó frente/confirmación queda sin cambios visibles → se omite.
                if ($log->ACCION === 'aux_edit' && empty($cambiosRaw)) {
                    continue;
                }

                if ($ax) {
                    $tipoTxt = $ax->TIPO ? ($auxTiposLabel[$ax->TIPO] ?? $ax->TIPO) : '';
                    // Sin el prefijo "Auxiliar": el tipo (Luminaria, Soldadora...) ya lo dice.
                    $eName   = trim($tipoTxt . ' ' . $ax->MARCA . ' ' . $ax->MODELO);
                    if (!empty($ax->CODIGO_INTERNO))      $eId = 'Código: ' . $ax->CODIGO_INTERNO;
                    elseif (!empty($ax->SERIAL))          $eId = 'Serial: ' . $ax->SERIAL;
                    else                                  $eId = 'ID Aux: #' . $log->ID_AUXILIAR;
                } else {
                    // Auxiliar borrado en duro: usar el label embebido como fallback.
                    $eName = $auxLabel !== '' ? $auxLabel : 'Auxiliar Eliminado';
                    $eId   = 'ID Aux: #' . $log->ID_AUXILIAR;
                }

                $events->push((object)[
                    'doc_key'       => $log->ACCION,
                    'tipo'          => $tipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ($log->ID_USUARIO ? 'Usuario #' . $log->ID_USUARIO . ' (eliminado)' : 'Sistema'),
                    'autor_nombre'  => $log->usuario ? ($log->usuario->NOMBRE_COMPLETO ?? '') : '',
                    'fecha'         => $log->created_at,
                    'link'          => null,
                    'equipo_nombre' => $eName,
                    'equipo_id'     => $eId,
                    'equipo_db_id'  => null,
                    'cambios'       => is_array($cambiosRaw) ? $cambiosRaw : [],
                    // Registro propio (equipo_audit_log) → borrable por super.admin.
                    'del_source'    => 'equipo_audit',
                    'del_id'        => $log->ID_LOG,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::warning('aux audit log read failed: ' . $e->getMessage());
        }

        // Eventos de AUDITORIA del CATÁLOGO (ediciones de modelos, subida de foto).
        // Se omiten cuando hay filtro search_equipo activo (catálogo no tiene equipo).
        if ($searchEquipoSql === '') {
        try {
            $catAuditQuery = \App\Models\CatalogoAuditLog::with(['usuario', 'modelo'])
                ->orderByDesc('created_at');

            if ($fechaDesdeSql) $catAuditQuery->where('created_at', '>=', $fechaDesdeSql);
            if ($fechaHastaSql) $catAuditQuery->where('created_at', '<=', $fechaHastaSql);

            $catAuditLogs = $catAuditQuery->limit(5000)->get();
            foreach ($catAuditLogs as $log) {
                $catTipoLabel = [
                    'create'          => 'Registro de Modelo',
                    'create_aux'      => 'Registro de Auxiliar',
                    'edit'            => 'Edición de Modelo',
                    'upload_foto'     => 'Foto de Modelo',
                    'upload_foto_aux' => 'Foto de Auxiliar',
                    'delete'          => 'Eliminación de Modelo',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $cambios = is_array($log->CAMBIOS) ? $log->CAMBIOS : (is_string($log->CAMBIOS) ? json_decode($log->CAMBIOS, true) : []);
                $modeloNombre = $log->MODELO ?? ($log->modelo ? $log->modelo->MODELO : 'Modelo Eliminado');
                $tipoEquipo = $cambios['tipo'] ?? ($log->modelo ? $log->modelo->TIPO : null);
                $anioVal = $log->ANIO_ESPEC;

                $parts = array_filter([$tipoEquipo, $modeloNombre, $anioVal], fn($v) => $v !== null && $v !== '');
                $equipoLabel = implode(' · ', $parts) ?: 'Modelo Eliminado';

                $events->push((object)[
                    'doc_key'       => 'catalogo_' . $log->ACCION,
                    'tipo'          => $catTipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ($log->ID_USUARIO ? 'Usuario #' . $log->ID_USUARIO . ' (eliminado)' : 'Sistema'),
                    'autor_nombre'  => $log->usuario ? ($log->usuario->NOMBRE_COMPLETO ?? '') : '',
                    'fecha'         => $log->created_at,
                    'link'          => null,
                    'equipo_nombre' => $equipoLabel,
                    'equipo_id'     => '',
                    'equipo_db_id'  => null,
                    'cambios'       => $cambios,
                    // Registro propio (catalogo_audit_log) → borrable.
                    'del_source'    => 'catalogo_audit',
                    'del_id'        => $log->ID_LOG,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::warning('catalogo audit log read failed: ' . $e->getMessage());
        }
        } // fin if searchEquipoSql === ''

        // (Las recepciones de notas de traspaso de ALMACÉN ya NO se listan aquí: el
        // historial de documentos es de DOCUMENTOS/equipos, no de confirmaciones ni
        // movimientos de inventario. La trazabilidad de recepciones vive en el módulo
        // de Almacén.)

        // ── DEDUPLICACION legacy ↔ audit log ──────────────────────────────────
        // Cada subida de documento genera DOS eventos:
        //   1) "Título de Propiedad" desde el flag PROPIEDAD_FECHA_SUBIDA (loop docs).
        //      Tiene link al PDF y label amigable.
        //   2) "Subida Propiedad"   desde el audit log (loop audit).
        //      Sin link al PDF (audit log no guarda URL).
        // Mantener el evento LEGACY (con link) y descartar el audit log "upload_X"
        // cuando exista equivalente en el legacy. El boton "Ver PDF" funciona
        // porque conserva el link del legacy. Si solo hay audit log (caso edge sin
        // legacy), el evento se preserva pero no muestra boton PDF.
        $legacyKeys = [];
        $uploadToLegacy = [
            'upload_propiedad'   => 'propiedad',
            'upload_poliza'      => 'poliza',
            'upload_rotc'        => 'rotc',
            'upload_racda'       => 'racda',
            'upload_adicional'   => 'adicional',
            'upload_adicional_2' => 'adicional_2',
        ];
        foreach ($events as $e) {
            if (in_array($e->doc_key, $uploadToLegacy, true)) {
                $legacyKeys[$e->equipo_db_id . '|' . $e->doc_key . '|' . $e->fecha->format('Y-m-d')] = true;
            }
        }
        $events = $events->filter(function ($e) use ($uploadToLegacy, $legacyKeys) {
            if (isset($uploadToLegacy[$e->doc_key])) {
                $key = $e->equipo_db_id . '|' . $uploadToLegacy[$e->doc_key] . '|' . $e->fecha->format('Y-m-d');
                return !isset($legacyKeys[$key]);
            }
            return true;
        });

        // ── La subida y sus datos, en UNA fila ────────────────────────────────
        // Subir un PDF pide la fecha, asi que dejaba dos filas casi a la vez: el archivo y sus
        // datos. Se queda la del archivo (con Ver PDF), que hereda los cambios. Tiene que ser
        // la misma ficha, documento y autor, y a menos de MARGEN_SUBIDA_SEG: editar las fechas
        // mas tarde sigue siendo su propia fila.
        $subidas = [];
        foreach ($events as $e) {
            if (in_array($e->doc_key, $uploadToLegacy, true)) {
                $subidas[$e->equipo_db_id . '|' . $e->doc_key . '|' . $e->autor][] = $e;
            }
        }
        $events = $events->filter(function ($e) use ($subidas) {
            if (!\Illuminate\Support\Str::startsWith($e->doc_key, 'metadata_')) return true;
            $tipo = substr($e->doc_key, strlen('metadata_'));
            foreach ($subidas[$e->equipo_db_id . '|' . $tipo . '|' . $e->autor] ?? [] as $subida) {
                if (abs($subida->fecha->diffInSeconds($e->fecha)) > self::MARGEN_SUBIDA_SEG) continue;
                $subida->cambios = $e->cambios;
                $subida->con_metadata = true;   // para el filtro "Edición de datos del documento"
                return false;
            }
            return true;
        });

        // 3. Sort descending by date
        $events = $events->sortByDesc('fecha')->values();

        // 4. Filtros finales en memoria sobre los eventos ya construidos.
        //    - search_correo y search_tipo: NO se pueden hacer en SQL porque
        //      'autor'/'autor_nombre' son correo/nombre derivados de 6 relaciones
        //      distintas + el label 'tipo' es un string construido en PHP (no en DB).
        //      search_correo ubica por nombre O correo (ver más abajo).
        //    - fecha_desde/hasta: se aplico en SQL para REDUCIR el dataset, pero
        //      hay que repetir aqui porque un Documentacion tiene 6 fechas y solo
        //      necesitamos que UNA caiga en rango para traerlo; los eventos de las
        //      OTRAS fechas del mismo doc deben filtrarse aqui.
        //    - search_equipo y scope LOCAL ya se filtraron en SQL completamente.
        $hasInMemoryFilter = $request->filled('search_correo')
                          || $request->filled('search_tipo')
                          || $fechaDesdeSql || $fechaHastaSql;
        if ($hasInMemoryFilter) {
            $normalize = fn ($s) => mb_strtolower(\Illuminate\Support\Str::ascii((string) $s));

            $search_correo = $normalize($request->search_correo);
            $search_tipo   = $normalize($request->search_tipo);

            // doc_key de las subidas legacy (loop docs). El dropdown agrupa las 6
            // subidas / 6 borrados / 6 ediciones de metadata en UNA opcion por accion
            // (valores 'cat_*'), reduciendo la lista de ~24 a ~9. Aqui se mapea cada
            // 'cat_*' al doc_key del evento. Cualquier OTRO valor (acciones del equipo
            // o deep-links antiguos con el label exacto) cae al match por substring.
            $catUploadKeys = ['propiedad', 'poliza', 'rotc', 'racda', 'adicional', 'adicional_2'];

            $events = $events->filter(function ($event) use ($normalize, $search_correo, $search_tipo, $fechaDesdeSql, $fechaHastaSql, $catUploadKeys) {
                // Ubicar por NOMBRE o CORREO del autor: el término casa si está en
                // cualquiera de los dos (autor = correo, autor_nombre = nombre completo).
                if ($search_correo
                    && strpos($normalize($event->autor), $search_correo) === false
                    && strpos($normalize($event->autor_nombre ?? ''), $search_correo) === false) {
                    return false;
                }
                if ($search_tipo && $search_tipo !== 'all') {
                    if ($search_tipo === 'cat_uploads') {
                        $okTipo = in_array($event->doc_key, $catUploadKeys, true)
                               || \Illuminate\Support\Str::startsWith($event->doc_key, 'upload_')
                               || \Illuminate\Support\Str::startsWith($event->doc_key, 'aux_upload_');
                    } elseif ($search_tipo === 'cat_borrados') {
                        $okTipo = \Illuminate\Support\Str::startsWith($event->doc_key, 'delete_')
                               || \Illuminate\Support\Str::startsWith($event->doc_key, 'aux_delete_');
                    } elseif ($search_tipo === 'cat_metadatos') {
                        // con_metadata: subida que absorbio los datos guardados con ella.
                        $okTipo = \Illuminate\Support\Str::startsWith($event->doc_key, 'metadata_')
                               || !empty($event->con_metadata);
                    } elseif ($search_tipo === 'cat_anexos') {
                        // Correcciones anexas. Categoria aparte y NO dentro de cat_uploads:
                        // anexar y sustituir son operaciones distintas —la primera solo
                        // añade, la segunda pisa el archivo de Drive— y quien filtra por
                        // "Subida de documento" busca lo segundo.
                        $okTipo = \Illuminate\Support\Str::startsWith($event->doc_key, 'anexo_');
                    } else {
                        // Acciones del equipo (Registro / Edición de Datos / Detalle
                        // Masivo / Eliminación) o label exacto legacy → substring.
                        $okTipo = strpos($normalize($event->tipo), $search_tipo) !== false;
                    }
                    if (!$okTipo) return false;
                }
                if ($fechaDesdeSql && $event->fecha->lt($fechaDesdeSql)) return false;
                if ($fechaHastaSql && $event->fecha->gt($fechaHastaSql)) return false;
                return true;
            })->values();
        }

        // 4b. "Ver solo seleccionados" (contador de la barra flotante): whitelist de
        //     eventos por su hash md5 — el MISMO que genera el partial en data-hd-id
        //     (equipo_id + tipo + timestamp). Replica el ids_in del módulo Equipos para
        //     esta vista de eventos heterogéneos sin clave primaria única. La seguridad
        //     (scope por frentes permitidos) ya se aplicó en SQL y se mantiene.
        if ($request->filled('hd_ids')) {
            $hdIdsSet = array_flip(array_filter(array_map('trim', explode(',', (string) $request->input('hd_ids')))));
            if (!empty($hdIdsSet)) {
                $events = $events->filter(function ($event) use ($hdIdsSet) {
                    return isset($hdIdsSet[md5($event->equipo_id . $event->tipo . $event->fecha->timestamp)]);
                })->values();
            }
        }

        return $events->all();
    }

    /**
     * Desbloquear IP. La tarjeta que la llama vive en /admin/usuarios (el JS compartido
     * historial_documentos_index.js apunta a esta ruta); en Control de Auditoría ya no se
     * muestra. El permiso 'super.admin' se valida en el group de rutas de routes/web.php.
     */
    public function unlockIp($id)
    {
        try {
            $bloqueo = \App\Models\BloqueoIp::findOrFail($id);
            $ip = $bloqueo->DIRECCION_IP;
            $bloqueo->delete();

            return response()->json([
                'success' => true,
                'message' => "La IP {$ip} ha sido desbloqueada exitosamente."
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('unlockIp failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al desbloquear la IP.'
            ], 500);
        }
    }

    /**
     * Eliminar un registro del historial (solo super.admin — gateado en routes/web.php).
     *
     * El historial mezcla 4 orígenes (ver index). SOLO los de AUDITORÍA son registros
     * propios borrables:
     *   - equipo_audit   → fila de `equipo_audit_log`.
     *   - catalogo_audit → fila de `catalogo_audit_log`.
     * Los otros dos se BLOQUEAN a propósito (borrarlos tocaría datos reales):
     *   - doc            → es una bandera en `documentacion`; borrarlo quitaría el documento.
     *   - equipo_creacion→ es el created_at del equipo; borrarlo sería borrar el vehículo.
     */
    public function deleteRegistro(Request $request)
    {
        $data = $request->validate([
            'source' => 'required|string|in:equipo_audit,catalogo_audit,doc,equipo_creacion',
            'id'     => 'nullable',
        ]);

        // Casos bloqueados: el botón aparece en todas las filas, pero estos no se borran
        // para no afectar datos reales (documento / vehículo). Mensaje claro al usuario.
        if ($data['source'] === 'doc') {
            return response()->json([
                'success' => false,
                'message' => 'Esta fila es una subida de documento real. Para quitarlo, bórralo desde el documento del equipo, no desde el historial.',
            ], 422);
        }
        if ($data['source'] === 'equipo_creacion') {
            return response()->json([
                'success' => false,
                'message' => 'Esta fila es el registro de creación de un vehículo. Para eliminarlo usa el módulo de Equipos.',
            ], 422);
        }

        try {
            $modelo = $data['source'] === 'equipo_audit'
                ? \App\Models\EquipoAuditLog::query()
                : \App\Models\CatalogoAuditLog::query();

            $borrados = $modelo->where('ID_LOG', $data['id'])->delete();

            if (!$borrados) {
                return response()->json(['success' => false, 'message' => 'El registro ya no existe.'], 404);
            }

            // Borrado por query builder: NO dispara el evento `deleted` del modelo,
            // así que la caché de eventos se invalida aquí a mano.
            self::bumpDataVersion();

            return response()->json(['success' => true, 'message' => 'Registro eliminado del historial.']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('deleteRegistro failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo eliminar el registro.'], 500);
        }
    }
}
