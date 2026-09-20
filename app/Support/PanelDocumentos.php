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

    /** Todo lo que necesita panel.blade.php para la pestaña pedida. */
    public static function datos(Request $request, string $pestana): array
    {
        $buscar = trim((string) $request->input('buscar', ''));

        // Si la tarea corre en ESTE equipo y por que: es lo primero que hay que poder mirar
        // tras desplegar, sin entrar al servidor.
        [$activa, $motivoActiva] = EnlacesDocumentos::esBaseDelServidor();
        // La misma zona con la que el programador decide la hora: schedule_timezone y, si no, app.timezone.
        $zona = config('app.schedule_timezone', config('app.timezone'));

        $propias = $pestana === self::DOCUMENTOS
            ? self::datosDocumentos($request, $buscar)
            : self::datosCompresion($request, $buscar);

        return $propias + [
            'pestana'      => $pestana,
            'buscar'       => $buscar,
            'activa'       => $activa,
            'motivoActiva' => $motivoActiva,
            'zona'         => $zona,
            'horaApp'      => now($zona),
            // Los horarios salen de los comandos (su HORARIO), los mismos que usa el programador.
            'horarioLectura'    => self::horario(\App\Console\Commands\VerificarDocumentos::HORARIO),
            'horarioCompresion' => self::horario(\App\Console\Commands\ComprimirDocumentos::HORARIO),
            'lecturaPedida'     => \App\Console\Commands\VerificarDocumentos::pedidaAhora(),
        ];
    }

    /** ['20:00', '00:00'] -> "de 8:00 p.m. a 12:00 a.m." */
    private static function horario(array $franja): string
    {
        $hora = fn (string $h) => str_replace(['am', 'pm'], ['a.m.', 'p.m.'], \Carbon\Carbon::createFromFormat('H:i', $h)->format('g:i a'));
        return 'de ' . $hora($franja[0]) . ' a ' . $hora($franja[1]);
    }

    /** Pestaña "Compresión": filas, filtros y resumen de docs:comprimir. */
    private static function datosCompresion(Request $request, string $buscar): array
    {
        // Un valor que no esta en su lista (p. ej. 'all', "todos") es como no filtrar.
        $estado = in_array($request->input('estado'), [CompresionPdf::COMPRIMIDO, CompresionPdf::SALTADO, CompresionPdf::ERROR], true)
            ? $request->input('estado') : null;
        $documentos = CompresionPdf::query()->distinct()->orderBy('DOCUMENTO')->pluck('DOCUMENTO');
        $documento  = $documentos->contains($request->input('documento')) ? $request->input('documento') : null;

        $resumen = CompresionPdf::query()
            ->select('ESTADO', DB::raw('COUNT(*) as n'), DB::raw('SUM(BYTES_ANTES) as antes'), DB::raw('SUM(BYTES_DESPUES) as despues'))
            ->groupBy('ESTADO')->get()->keyBy('ESTADO');

        $filas = CompresionPdf::query()
            ->when($estado, fn ($q) => $q->where('ESTADO', $estado))
            ->when($documento, fn ($q) => $q->where('DOCUMENTO', $documento))
            ->when($buscar !== '', function ($q) use ($buscar) {
                $like = '%' . addcslashes($buscar, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('SERIAL', 'like', $like)->orWhere('DOCUMENTO', 'like', $like));
            })
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
    private static function datosDocumentos(Request $request, string $buscar): array
    {
        $avance = self::avance();
        $estados = [VerificacionDocumento::COINCIDE, VerificacionDocumento::DIFIERE,
                    VerificacionDocumento::ILEGIBLE, VerificacionDocumento::SIN_ARCHIVO, VerificacionDocumento::ERROR];
        // Dos filtros que no son un estado de la tabla, sino los dos montones que se miran
        // distinto y que cuentan las tarjetas: 'revisar' (lo que decide una persona) y
        // 'corregibles' (lo que la tarea todavia puede poner sola). Cada tarjeta enlaza al
        // filtro que enseña EXACTAMENTE lo que ella cuenta.
        $pedido = $request->input('estado_doc');
        $estadoDoc = (in_array($pedido, ['revisar', 'corregibles'], true) || in_array($pedido, $estados, true)) ? $pedido : null;
        // Los cuatro documentos, los mismos que ofrece el desplegable de la vista.
        $tipoDoc = array_key_exists((string) $request->input('tipo_doc'), VerificacionDocumento::NOMBRES)
            ? $request->input('tipo_doc') : null;

        $filas = VerificacionDocumento::query()
            ->when($estadoDoc === 'revisar', fn ($q) => $q->paraRevisar())
            ->when($estadoDoc === 'corregibles', fn ($q) => $q->corregibles())
            ->when($estadoDoc && !in_array($estadoDoc, ['revisar', 'corregibles'], true),
                fn ($q) => $q->where('ESTADO', $estadoDoc))
            ->when($tipoDoc, fn ($q) => $q->where('TIPO', $tipoDoc))
            ->when($buscar !== '', function ($q) use ($buscar) {
                $like = '%' . addcslashes($buscar, '%_\\') . '%';
                // LEIDO es JSON y Laravel lo guarda con los acentos escapados ("PIRÁMIDE" ->
                // "PIR\u00c1MIDE"): para buscar un nombre acentuado hay que buscar tambien esa forma.
                $json = json_encode($buscar, JSON_INVALID_UTF8_SUBSTITUTE);
                $likeJson = $json === false ? $like : '%' . addcslashes(trim($json, '"'), '%_\\') . '%';
                $q->where(fn ($w) => $w->where('PLACA', 'like', $like)->orWhere('SERIAL', 'like', $like)
                    ->orWhere('MOTIVO', 'like', $like)
                    ->orWhere('LEIDO', 'like', $like)->orWhere('LEIDO', 'like', $likeJson));
            })
            // Primero lo que hay que resolver; dentro de cada montón, lo ultimo leido arriba.
            // El ID desempata: el comando escribe varias filas en el mismo segundo y sin el
            // una misma fila podia salir en dos paginas (o en ninguna).
            ->orderByRaw("FIELD(ESTADO, '" . VerificacionDocumento::DIFIERE . "', '" . VerificacionDocumento::ILEGIBLE
                . "', '" . VerificacionDocumento::SIN_ARCHIVO . "', '" . VerificacionDocumento::ERROR . "', '" . VerificacionDocumento::COINCIDE . "')")
            ->orderByDesc('updated_at')->orderByDesc('ID_REGISTRO')
            ->paginate(50)->withQueryString();

        return [
            'docs'           => $filas,
            'resumenDocs'    => VerificacionDocumento::select('ESTADO', DB::raw('COUNT(*) as n'))->groupBy('ESTADO')->pluck('n', 'ESTADO'),
            // Los dos montones que se miran distinto: lo que la tarea todavia pone sola y lo
            // que pide una persona (ilegible, sin archivo, de otro vehiculo o leido a medias).
            'docsParaRevisar' => VerificacionDocumento::paraRevisar()->count(),
            'docsCorregibles' => VerificacionDocumento::corregibles()->count(),
            'estadoDoc'      => $estadoDoc,
            'tipoDoc'        => $tipoDoc,
            'ultimaLectura'  => VerificacionDocumento::max('updated_at'),
            'avanceDocs'     => $avance,
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
     * dia que las cuatro filas digan "faltan 0", la revision acabo.
     *
     * De aqui sale tambien el total de "faltan por leer", para no contar dos veces lo mismo.
     */
    private static function avance(): array
    {
        $avance = [];
        foreach (VerificacionDocumento::ENLACES as $tipo => $col) {
            $faltan = VerificacionDocumento::pendientes($tipo, $col)->count();
            $total  = VerificacionDocumento::conEnlace($col)->count();
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
