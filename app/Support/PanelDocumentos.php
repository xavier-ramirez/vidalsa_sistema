<?php

namespace App\Support;

use App\Models\CompresionPdf;
use App\Models\VerificacionDocumento;
use App\Services\CompresorPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los datos de las dos pestañas de documentos de Control de Auditoría
 * (/admin/historial-documentos): "Compresión" (docs:comprimir) y "Documentos"
 * (docs:verificar-documentos). La vista es admin/compresion_pdf/panel.blade.php.
 *
 * Vive aparte de los controladores porque lo piden dos: la pantalla de auditoría, que lo
 * muestra, y CompresionPdfController, que aplica la correccion y vuelve a ella.
 *
 * Cada pestaña consulta SOLO lo suyo; de la otra se devuelven los valores vacios que la
 * vista necesita para no romperse (y el contador de "para revisar", que va en la pestaña).
 */
class PanelDocumentos
{
    /** Las dos pestañas de documentos. La pantalla de auditoría añade la suya ('historial'). */
    public const COMPRESION = 'compresion';
    public const DOCUMENTOS = 'documentos';

    /** ¿Es una de las dos pestañas de este panel? */
    public static function esPestana(?string $v): bool
    {
        return in_array($v, [self::COMPRESION, self::DOCUMENTOS], true);
    }

    /**
     * Todo lo que necesita panel.blade.php para la pestaña pedida. Las lecturas son las mismas
     * para todos los que entran; qué botones salen lo decide la vista con los permisos.
     *
     * $soloLista: lo pide un filtro (cpdfFiltrar), que solo repinta los @fragment del panel
     * —la tabla, las páginas, las cuentas y las opciones—. "Por dónde va la revisión" no
     * depende de los filtros y es la consulta cara de la pestaña: ahí no se hace.
     */
    public static function datos(Request $request, string $pestana, bool $soloLista = false): array
    {
        $buscar = trim((string) $request->input('buscar', ''));

        // Si la tarea corre en ESTE equipo y por que: es lo primero que hay que poder mirar
        // tras desplegar, sin entrar al servidor.
        [$activa, $motivoActiva] = EnlacesDocumentos::esBaseDelServidor();
        // La misma zona con la que el programador decide la hora: schedule_timezone y, si no, app.timezone.
        $zona = config('app.schedule_timezone', config('app.timezone'));

        $propias = $pestana === self::DOCUMENTOS
            ? self::datosDocumentos($request, $buscar, $soloLista)
            : self::datosCompresion($request, $buscar);

        return $propias + [
            'pestana'      => $pestana,
            'buscar'       => $buscar,
            'activa'       => $activa,
            'conFechas'    => $request->filled('fecha_desde') || $request->filled('fecha_hasta'),
            'motivoActiva' => $motivoActiva,
            'zona'         => $zona,
            // Los horarios salen de los comandos (su HORARIO), los mismos que usa el programador.
            'horarioLectura'    => self::horario(\App\Console\Commands\VerificarDocumentos::HORARIO),
            'horarioCompresion' => self::horario(\App\Console\Commands\ComprimirDocumentos::HORARIO),
            'lecturaPedida'     => \App\Console\Commands\VerificarDocumentos::pedidaAhora(),
            'lecturaPedidaTipos' => \App\Console\Commands\VerificarDocumentos::pedidaAhoraTipos(),
        ];
    }

    /** ['20:00', '00:00'] -> "de 8:00 p.m. a 12:00 a.m." */
    private static function horario(array $franja): string
    {
        $hora = fn (string $h) => str_replace(['am', 'pm'], ['a.m.', 'p.m.'], \Carbon\Carbon::createFromFormat('H:i', $h)->format('g:i a'));
        return 'de ' . $hora($franja[0]) . ' a ' . $hora($franja[1]);
    }

    /**
     * Filtros Avanzados (fecha_desde / fecha_hasta, partials/filtro_fechas): deja solo lo de esos
     * días en `$columna`, la fecha que muestra cada lista. Una fecha mal escrita no filtra.
     */
    private static function entreFechas($q, Request $request, string $columna): void
    {
        foreach (['fecha_desde' => '>=', 'fecha_hasta' => '<='] as $param => $op) {
            if (!$request->filled($param)) continue;
            try {
                $dia = \Carbon\Carbon::parse((string) $request->input($param));
            } catch (\Throwable $e) {
                continue;
            }
            $q->where($columna, $op, $op === '>=' ? $dia->startOfDay() : $dia->endOfDay());
        }
    }

    /**
     * "Ver solo las elegidas" (contador partials/chip_seleccion): ?ids=1,2,3 deja solo esas filas
     * (ID_REGISTRO). Con tope, como el hd_ids del Historial; lo que no es un número se ignora.
     */
    private static function soloElegidas($q, Request $request): void
    {
        if (!$request->filled('ids')) return;
        $ids = array_slice(array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids'))))), 0, 500);
        $q->whereIn('ID_REGISTRO', $ids ?: [0]);
    }

    /** Pestaña "Compresión": filas, filtros y resumen de docs:comprimir. */
    private static function datosCompresion(Request $request, string $buscar): array
    {
        // Un valor que no esta en su lista (p. ej. 'all', "todos") es como no filtrar.
        $estado = in_array($request->input('estado'), [CompresionPdf::COMPRIMIDO, CompresionPdf::SALTADO, CompresionPdf::ERROR], true)
            ? $request->input('estado') : null;
        $documentos = CompresionPdf::query()->distinct()->orderBy('DOCUMENTO')->pluck('DOCUMENTO');
        $documento  = $documentos->contains($request->input('documento')) ? $request->input('documento') : null;

        // Los filtros de la lista MENOS el estado: el resumen cuenta cada estado con ellos, así
        // que elegir "Saltados" no deja en cero a los comprimidos ni a los de error.
        $filtrar = function ($q) use ($request, $documento, $buscar) {
            self::entreFechas($q, $request, 'created_at');
            self::soloElegidas($q, $request);
            $q->when($documento, fn ($q) => $q->where('DOCUMENTO', $documento))
              ->when($buscar !== '', function ($q) use ($buscar) {
                  $like = '%' . addcslashes($buscar, '%_\\') . '%';
                  $q->where(fn ($w) => $w->where('SERIAL', 'like', $like)->orWhere('DOCUMENTO', 'like', $like));
              });
        };

        $resumen = CompresionPdf::query()->tap($filtrar)
            ->select('ESTADO', DB::raw('COUNT(*) as n'), DB::raw('SUM(BYTES_ANTES) as antes'), DB::raw('SUM(BYTES_DESPUES) as despues'))
            ->groupBy('ESTADO')->get()->keyBy('ESTADO');

        $filas = CompresionPdf::query()->tap($filtrar)
            ->when($estado, fn ($q) => $q->where('ESTADO', $estado))
            ->orderByDesc('created_at')->orderByDesc('ID_REGISTRO')
            ->paginate(50)->withQueryString();

        return [
            'resumen'     => $resumen,
            'ultimaNoche' => CompresionPdf::where('ORIGEN', 'noche')->max('created_at'),
            'filas'       => $filas,
            'estado'      => $estado,
            'documentos'  => $documentos,
            'documento'   => $documento,
            'ghostscript' => app(CompresorPdf::class)->disponible(),
            // De la otra pestaña solo hace falta el numero de su botón.
            'docs'        => null,
            'vinesFaltan' => [],
            'unidadesFaltan' => [],
            'avanceDocs'  => [],
            'docsParaRevisar' => VerificacionDocumento::paraRevisar()->count(),
            'estadoDoc'   => null,
            'tipoDoc'     => null,
            'ultimaLectura'  => null,
        ];
    }

    /**
     * Pestaña "Documentos" (titulos, polizas, ROTC y RACDA): filas paginadas, cuantas hay de cada estado, cuando fue la
     * ultima lectura y cuantos documentos faltan por leer.
     */
    private static function datosDocumentos(Request $request, string $buscar, bool $soloLista): array
    {
        // Los cinco de la revision nocturna mas los de la carga masiva (DE_LA_CARGA_TODOS): las
        // dos procedencias comparten esta tabla, que es la unica del modulo donde se ve el
        // estado de un documento.
        $estados = array_merge(
            [VerificacionDocumento::COINCIDE, VerificacionDocumento::DIFIERE,
             VerificacionDocumento::ILEGIBLE, VerificacionDocumento::SIN_ARCHIVO, VerificacionDocumento::ERROR],
            VerificacionDocumento::DE_LA_CARGA_TODOS
        );
        // Dos filtros que no son un estado de la tabla, sino los dos montones que se miran
        // distinto: 'revisar' (lo que decide una persona, y que cuenta su tarjeta) y
        // 'corregibles' (lo que la tarea todavia puede poner sola), que se pide desde el
        // desplegable o por la URL.
        $pedido = $request->input('estado_doc');
        $estadoDoc = (in_array($pedido, ['revisar', 'corregibles'], true) || in_array($pedido, $estados, true)) ? $pedido : null;
        // Los tipos de documento, los mismos que ofrece el desplegable de la vista.
        $tipoDoc = array_key_exists((string) $request->input('tipo_doc'),
                                    VerificacionDocumento::NOMBRES + \App\Services\CargaMasivaDocumentos::NOMBRES)
            ? $request->input('tipo_doc') : null;

        // El buscador recorre el JSON de cada lectura (LEIDO, PROPUESTA): es lo caro de filtrar
        // (~80 ms por consulta). Se busca UNA vez y la página y sus cuentas usan esos ID.
        $encontrados = $buscar === '' ? null : VerificacionDocumento::query()
            ->where(function ($w) use ($buscar) {
                $like = '%' . addcslashes($buscar, '%_\\') . '%';
                // LEIDO es JSON y Laravel lo guarda con los acentos escapados ("PIRÁMIDE" ->
                // "PIR\u00c1MIDE"): para buscar un nombre acentuado hay que buscar tambien esa forma.
                $json = json_encode($buscar, JSON_INVALID_UTF8_SUBSTITUTE);
                $likeJson = $json === false ? $like : '%' . addcslashes(trim($json, '"'), '%_\\') . '%';
                $w->where('PLACA', 'like', $like)->orWhere('SERIAL', 'like', $like)
                    ->orWhere('MOTIVO', 'like', $like)
                    // Por el nombre del PDF: es como se busca lo que uno acaba de soltar.
                    ->orWhere('ARCHIVO', 'like', $like)
                    ->orWhere('LEIDO', 'like', $like)->orWhere('LEIDO', 'like', $likeJson)
                    // Lo de la carga masiva, tambien por lo que leyo del PDF: un VIN, una placa o
                    // un serial que falta (el motivo ya no los nombra: los dice la linea de cada uno).
                    ->orWhere(fn ($c) => $c->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)
                        ->where(fn ($p) => $p->where('PROPUESTA', 'like', $like)->orWhere('PROPUESTA', 'like', $likeJson)));
            })
            ->pluck('ID_REGISTRO')->all();

        // Los filtros de la lista MENOS el estado: con ellos se cuenta cada estado (las opciones
        // del desplegable y las tarjetas), así que las cuentas siguen al tipo, al buscador y a
        // las fechas, y elegir un estado no deja en cero a los demás.
        $filtrar = function ($q) use ($request, $tipoDoc, $encontrados) {
            self::entreFechas($q, $request, 'updated_at');
            self::soloElegidas($q, $request);
            $q->when($tipoDoc, fn ($q) => $q->where('TIPO', $tipoDoc))
              ->when($encontrados !== null, fn ($q) => $q->whereIn('ID_REGISTRO', $encontrados ?: [0]));
        };

        $filas = VerificacionDocumento::query()->tap($filtrar)
            ->when($estadoDoc === 'revisar', fn ($q) => $q->paraRevisar())
            ->when($estadoDoc === 'corregibles', fn ($q) => $q->corregibles())
            ->when($estadoDoc && !in_array($estadoDoc, ['revisar', 'corregibles'], true),
                fn ($q) => $q->where('ESTADO', $estadoDoc))
            // Lo soltado en la carga masiva en las ULTIMAS 24 HORAS va PRIMERO, lo mas nuevo
            // arriba, este como este: se enlaza solo y queda "Aplicado", y lo aplicado va al
            // final, detras de miles de filas de la noche; sin esto habia que filtrar para ver
            // lo que uno acababa de subir.
            ->orderByRaw('CASE WHEN ORIGEN = ? AND created_at >= ? THEN 0 ELSE 1 END',
                [VerificacionDocumento::DE_CARGA_MASIVA, now()->subDay()])
            ->orderByRaw('CASE WHEN ORIGEN = ? AND created_at >= ? THEN ID_REGISTRO END DESC',
                [VerificacionDocumento::DE_CARGA_MASIVA, now()->subDay()])
            // Despues, primero lo que hay que resolver; dentro de cada montón, lo ultimo leido
            // arriba. El ID desempata: el comando escribe varias filas en el mismo segundo y
            // sin el una misma fila podia salir en dos paginas (o en ninguna).
            ->orderByRaw("FIELD(ESTADO, '" . VerificacionDocumento::POR_ENGANCHAR . "', '" . VerificacionDocumento::SIN_FICHA
                . "', '" . VerificacionDocumento::OTRO_DOCUMENTO
                . "', '" . VerificacionDocumento::DIFIERE . "', '" . VerificacionDocumento::ILEGIBLE
                . "', '" . VerificacionDocumento::SIN_ARCHIVO . "', '" . VerificacionDocumento::ERROR . "', '" . VerificacionDocumento::COINCIDE
                // Lo ya aplicado de la carga, al final: FIELD da 0 a lo que no esta en la lista y
                // 0 va PRIMERO, asi que sin nombrarlo tapaba lo que quedo sin enlazar.
                . "', '" . VerificacionDocumento::APLICADO . "')")
            ->orderByDesc('updated_at')->orderByDesc('ID_REGISTRO')
            // El serial de motor no se guarda en la fila: sale de la ficha, de toda la pagina en
            // una consulta (ver VerificacionDocumento::identificador). Tambien de un equipo borrado.
            ->with(['equipo' => fn ($q) => $q->withTrashed()->select('ID_EQUIPO', 'SERIAL_DE_MOTOR')])
            ->paginate(50)->withQueryString();

        // Las filas de la carga masiva de la pagina (las demas, null), por su ID.
        $deLaCarga = $filas->getCollection()->mapWithKeys(fn ($d) => [$d->ID_REGISTRO =>
            $d->ORIGEN === VerificacionDocumento::DE_CARGA_MASIVA ? $d : null])->all();
        $carga = app(\App\Services\CargaMasivaDocumentos::class);

        return [
            'docs'           => $filas,
            // Los VIN de cada BL de la carga que no casaron con ninguna ficha, de toda la pagina
            // de una vez (la vista los pinta con su boton de enlazar a un equipo).
            'vinesFaltan'    => $carga->vinesSinAtarDeVarias(array_map(fn ($d) => $d?->PROPUESTA, $deLaCarga)),
            // Y las unidades de cada RACDA, ROTC o poliza de flota que se enlazan a mano (la placa
            // o el serial que falta, o un equipo suyo que no entro), cada una con su boton.
            'unidadesFaltan' => $carga->unidadesSinAtar($deLaCarga),
            'resumenDocs'    => VerificacionDocumento::query()->tap($filtrar)
                ->select('ESTADO', DB::raw('COUNT(*) as n'))->groupBy('ESTADO')->pluck('n', 'ESTADO'),
            // Los dos montones que se miran distinto: lo que la tarea todavia pone sola y lo
            // que pide una persona (ilegible, sin archivo, de otro vehiculo o leido a medias).
            'cuentaRevisar'   => VerificacionDocumento::query()->tap($filtrar)->paraRevisar()->count(),
            'docsCorregibles' => VerificacionDocumento::query()->tap($filtrar)->corregibles()->count(),
            // El número de la pestaña es lo pendiente de TODO, sin filtros.
            'docsParaRevisar' => VerificacionDocumento::paraRevisar()->count(),
            // Contra este total se dice "de cuántos" cuando la lista está filtrada.
            'totalDocs'      => VerificacionDocumento::count(),
            'estadoDoc'      => $estadoDoc,
            'tipoDoc'        => $tipoDoc,
            'ultimaLectura'  => VerificacionDocumento::max('updated_at'),
            'avanceDocs'     => $soloLista ? [] : self::avance(),
            // Las de la otra pestaña: no se consultan, pero la vista las recibe siempre.
            'resumen'     => collect(),
            'ultimaNoche' => null,
            'filas'       => null,
            'estado'      => null,
            'documentos'  => collect(),
            'documento'   => null,
            'ghostscript' => true,
        ];
    }

    /**
     * Por donde va la revision de CADA documento: cuantos hay cargados, cuantos se han leido y
     * cuantos faltan. Es lo que contesta "¿ya termino con todos?" sin entrar al servidor: el
     * dia que las cuatro filas digan "listo", la revision acabo.
     */
    private static function avance(): array
    {
        $avance = [];
        // Los cuatro tipos salen de UNA consulta (ver VerificacionDocumento::avances).
        foreach (VerificacionDocumento::avances() as $tipo => ['total' => $total, 'faltan' => $faltan]) {
            $avance[$tipo] = [
                'nombre' => VerificacionDocumento::NOMBRES[$tipo] ?? $tipo,
                'total'  => $total,
                'leidos' => max(0, $total - $faltan),
                'faltan' => $faltan,
            ];
        }
        return $avance;
    }
}
