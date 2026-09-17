<?php

namespace App\Support;

use App\Models\CompresionPdf;
use App\Models\VerificacionDocumento;
use App\Services\CompresorPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Los datos de las dos pestañas de documentos de Control de Auditoría
 * (/admin/historial-documentos): "Compresión" (docs:comprimir) y "Títulos y pólizas"
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
        ];
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
            // De la otra pestaña solo hace falta su contador, que se ve en el propio botón.
            'docs'        => null,
            'resumenDocs' => VerificacionDocumento::select('ESTADO', DB::raw('COUNT(*) as n'))->groupBy('ESTADO')->pluck('n', 'ESTADO'),
            'estadoDoc'   => null,
            'tipoDoc'     => null,
            'ultimaLectura'  => null,
            'pendientesDocs' => null,
        ];
    }

    /**
     * Pestaña "Títulos y pólizas": filas paginadas, cuantas hay de cada estado, cuando fue la
     * ultima lectura y cuantos documentos faltan por leer.
     */
    private static function datosDocumentos(Request $request, string $buscar): array
    {
        $estados = [VerificacionDocumento::COINCIDE, VerificacionDocumento::DIFIERE,
                    VerificacionDocumento::ILEGIBLE, VerificacionDocumento::SIN_ARCHIVO, VerificacionDocumento::ERROR];
        // 'revisar' junta en un filtro los montones que mira una persona.
        $pedido = $request->input('estado_doc');
        $estadoDoc = ($pedido === 'revisar' || in_array($pedido, $estados, true)) ? $pedido : null;
        $tipoDoc = in_array($request->input('tipo_doc'), [VerificacionDocumento::PROPIEDAD, VerificacionDocumento::POLIZA], true)
            ? $request->input('tipo_doc') : null;

        $filas = VerificacionDocumento::query()
            ->when($estadoDoc === 'revisar', fn ($q) => $q->whereIn('ESTADO', VerificacionDocumento::A_REVISAR))
            ->when($estadoDoc && $estadoDoc !== 'revisar', fn ($q) => $q->where('ESTADO', $estadoDoc))
            ->when($tipoDoc, fn ($q) => $q->where('TIPO', $tipoDoc))
            ->when($buscar !== '', function ($q) use ($buscar) {
                $like = '%' . addcslashes($buscar, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('PLACA', 'like', $like)->orWhere('SERIAL', 'like', $like)
                    ->orWhere('LEIDO', 'like', $like)->orWhere('MOTIVO', 'like', $like));
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
            'estadoDoc'      => $estadoDoc,
            'tipoDoc'        => $tipoDoc,
            'ultimaLectura'  => VerificacionDocumento::max('updated_at'),
            'pendientesDocs' => self::pendientes(),
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
     * Documentos cargados que el verificador todavia no ha leido, contando los dos enlaces.
     * Se cuentan solo los que apuntan de verdad a un archivo de Drive y los que aun pueden
     * reintentarse: el mismo criterio del comando, para que este numero pueda llegar a cero.
     */
    private static function pendientes(): int
    {
        $total = 0;
        foreach ([VerificacionDocumento::PROPIEDAD => 'LINK_DOC_PROPIEDAD',
                  VerificacionDocumento::POLIZA    => 'LINK_POLIZA_SEGURO'] as $tipo => $col) {
            $idEnlace = "SUBSTRING_INDEX(SUBSTRING_INDEX(d.$col, '/storage/google/', -1), '?', 1)";
            $total += DB::table('documentacion as d')
                ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
                ->whereNull('e.deleted_at')
                ->where("d.$col", 'like', '/storage/google/%')
                ->whereRaw("$idEnlace <> ''")
                ->whereNotExists(fn ($s) => $s->from('verificacion_documento_registro as v')
                    ->whereColumn('v.ID_EQUIPO', 'd.ID_EQUIPO')->where('v.TIPO', $tipo)->whereRaw("v.DRIVE_ID = $idEnlace")
                    ->where(fn ($w) => $w->whereNotIn('v.ESTADO', [VerificacionDocumento::ILEGIBLE, VerificacionDocumento::ERROR])
                        ->orWhere('v.INTENTOS', '>=', VerificacionDocumento::MAX_INTENTOS)))
                ->count();
        }
        return $total;
    }
}
