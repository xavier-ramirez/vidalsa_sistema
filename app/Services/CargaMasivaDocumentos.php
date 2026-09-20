<?php

namespace App\Services;

use App\Models\Documentacion;
use App\Models\DocumentoAnexo;
use App\Models\Equipo;
use App\Models\EquipoAuditLog;
use App\Models\VerificacionDocumento;
use App\Support\DocumentacionDeEquipo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Carga masiva de documentos: sube VARIOS PDF y cada uno se enlaza solo a su equipo.
 *
 * El trabajo va en DOS pasos separados a proposito, y el usuario decide entre uno y otro:
 *
 *   1) analizar()  Sube el PDF a Drive, lo LEE y propone: que tipo de documento es, de que
 *                  equipo es y que fechas trae. NO toca la ficha.
 *   2) aplicar()   Con el visto bueno, escribe el enlace y las fechas en `documentacion`.
 *
 * Por que hay que subirlo para leerlo: el texto lo saca el OCR de Google Drive
 * (LectorDocumentoPdf::texto recibe un id de Drive, no un archivo). Asi que el PDF viaja
 * primero y por eso el archivo de una propuesta DESCARTADA se borra de Drive (descartar()).
 *
 * Un archivo por peticion: leer cuesta ~8 s y treinta en una sola peticion se caerian por
 * timeout. La pantalla los manda de uno en uno y va pintando el resultado de cada uno.
 *
 * Lo que NUNCA hace solo:
 *   · Pisar un documento que la ficha ya tiene. Hace falta decirselo (pisar=true).
 *   · Poner un documento ANTERIOR al que ya esta (la regla de VerificacionDocumento::
 *     documentoAnterior, la misma de la revision nocturna): eso retrocederia un
 *     vencimiento bueno. Ahi se niega incluso con pisar=true.
 */
class CargaMasivaDocumentos
{
    /** Los 4 tipos que esta pantalla sabe repartir. 'adicional' no: no se reconoce solo. */
    public const TIPOS = [
        LectorDocumentoPdf::PROPIEDAD,
        LectorDocumentoPdf::POLIZA,
        LectorDocumentoPdf::ROTC,
        LectorDocumentoPdf::RACDA,
    ];

    /** Como se llama cada tipo en pantalla. */
    public const NOMBRES = [
        LectorDocumentoPdf::PROPIEDAD => 'Titulo de propiedad',
        LectorDocumentoPdf::POLIZA    => 'Poliza de seguro',
        LectorDocumentoPdf::ROTC      => 'ROTC',
        LectorDocumentoPdf::RACDA     => 'RACDA',
    ];

    /** Prefijo del archivo en Drive, por tipo (mismo que usa uploadDoc). */
    private const PREFIJOS = [
        LectorDocumentoPdf::PROPIEDAD => 'doc_propiedad_',
        LectorDocumentoPdf::POLIZA    => 'poliza_seguro_',
        LectorDocumentoPdf::ROTC      => 'rotc_',
        LectorDocumentoPdf::RACDA     => 'racda_',
    ];

    /**
     * Un RACDA es de la EMPRESA y nombra muchas unidades; enlazarlo a cientos de fichas de
     * un golpe desde aqui seria una operacion enorme detras de un solo clic. Se propone
     * hasta este tope y, si hay mas, se avisa: para la flota entera esta la revision
     * nocturna, que ya lo reparte.
     */
    private const TOPE_RACDA = 40;

    public function __construct(private LectorDocumentoPdf $lector) {}

    // ── Paso 1: subir, leer y proponer ────────────────────────────────────────────

    /**
     * Sube $archivo a Drive, lo lee y devuelve la propuesta. $tipoPedido fuerza el tipo
     * (el usuario dijo "estos son polizas"); si es null se reconoce por el texto.
     *
     * Devuelve SIEMPRE una propuesta, tambien cuando no se pudo leer: la pantalla la pinta
     * igual con su motivo, para que el usuario vea que paso con cada archivo.
     */
    public function analizar(UploadedFile $archivo, ?string $tipoPedido = null): array
    {
        $nombre = $archivo->getClientOriginalName();
        $prefijo = self::PREFIJOS[$tipoPedido] ?? 'doc_masivo_';

        try {
            $link = GoogleDriveService::getInstance()->subirPdf($archivo, $prefijo . time() . '_' . mt_rand(1000, 9999) . '.pdf');
        } catch (\Throwable $e) {
            Log::error('Carga masiva: no se pudo subir a Drive', ['archivo' => $nombre, 'error' => $e->getMessage()]);
            return $this->fallo($nombre, null, 'No se pudo subir el archivo a Drive.');
        }

        $driveId = DocumentoAnexo::driveIdDeLink($link);
        if (!$driveId) return $this->fallo($nombre, $link, 'El archivo se subio pero Drive no devolvio un enlace utilizable.');

        try {
            $texto = $this->lector->texto($driveId);
        } catch (\Throwable $e) {
            Log::warning('Carga masiva: fallo la lectura', ['archivo' => $nombre, 'error' => $e->getMessage()]);
            return $this->fallo($nombre, $link, 'No se pudo leer el PDF.');
        }

        if (trim($texto) === '') {
            return $this->fallo($nombre, $link, 'El PDF no tiene texto legible (esta escaneado muy bajo o en blanco).');
        }

        $tipo = $tipoPedido ?: $this->detectarTipo($texto);
        if (!$tipo) {
            return $this->fallo($nombre, $link, 'No se reconoce que documento es. Elige el tipo arriba y vuelve a subirlo.');
        }

        $leido = $this->lector->extraer($tipo, $texto);
        if ($tipo === LectorDocumentoPdf::POLIZA) {
            $catalogo = $this->catalogoAseguradoras();
            $idSeguro = $this->lector->aseguradoraEnTexto($texto, $catalogo);
            $leido['aseguradora'] = $idSeguro ? $catalogo[$idSeguro] : null;
        }

        $equipos = $tipo === LectorDocumentoPdf::RACDA
            ? $this->equiposDelRacda($leido)
            : $this->equipoDelDocumento($leido);

        $propuesta = [
            'archivo' => $nombre,
            'link'    => $link,
            'tipo'    => $tipo,
            'tipo_nombre' => self::NOMBRES[$tipo],
            'vence'   => $leido['vence'] ?? null,
            'emision' => $leido['emision'] ?? null,
            'titular' => $leido['titular'] ?? null,
            'nro'     => $leido['nro'] ?? null,
            'aseguradora' => $leido['aseguradora'] ?? null,
            'equipos' => $equipos,
            'estado'  => 'listo',
            'aviso'   => null,
        ];

        if (!$equipos) {
            $propuesta['estado'] = 'sin_equipo';
            $propuesta['aviso'] = $tipo === LectorDocumentoPdf::RACDA
                ? 'Se leyo la providencia pero ninguna de sus placas esta registrada.'
                : 'Se leyo el documento pero no dice de que equipo es (ni placa ni serial reconocidos).';
            return $propuesta;
        }

        // Un documento que vence sin su fecha no se puede aplicar: es el dato que vigila la app
        // y uploadDoc tampoco lo acepta. Se propone igual para que el usuario la escriba.
        if (isset(DocumentacionDeEquipo::VENCIMIENTO[$tipo]) && !$propuesta['vence']) {
            $propuesta['estado'] = 'revisar';
            $propuesta['aviso'] = 'No se leyo la fecha de vencimiento. Escribela para poder aplicarlo.';
        }

        return $propuesta;
    }

    /** Propuesta que no llego a ninguna parte, con su motivo. El archivo ya esta en Drive. */
    private function fallo(string $archivo, ?string $link, string $motivo): array
    {
        return [
            'archivo' => $archivo, 'link' => $link, 'tipo' => null, 'tipo_nombre' => null,
            'vence' => null, 'emision' => null, 'titular' => null, 'nro' => null,
            'aseguradora' => null, 'equipos' => [], 'estado' => 'ilegible', 'aviso' => $motivo,
        ];
    }

    // ── Reconocer que documento es ────────────────────────────────────────────────

    /**
     * De que tipo es el PDF, por lo que dice de si mismo. Se mira en este orden porque los
     * rotulos se solapan: una providencia RACDA nombra polizas y vehiculos, y un ROTC trae
     * "Fecha de Vencimiento" igual que una poliza. El mas especifico manda.
     */
    public function detectarTipo(string $texto): ?string
    {
        $t = preg_replace('/\s+/u', ' ', mb_strtoupper($texto, 'UTF-8'));

        return match (true) {
            (bool) preg_match('/PROVIDENCIA ADMINISTRATIVA|RACDA|REGISTRO NACIONAL DE TRANSPORTE TERRESTRE/u', $t) => LectorDocumentoPdf::RACDA,
            (bool) preg_match('/\bROTC\b|CERTIFICADO DE CIRCULACI[OÓ]N/u', $t) => LectorDocumentoPdf::ROTC,
            (bool) preg_match('/P[OÓ]LIZA|POLIZA|CUADRO RECIBO|ASEGURAD/u', $t) => LectorDocumentoPdf::POLIZA,
            (bool) preg_match('/CERTIFICADO DE REGISTRO DE VEH[IÍ]CULO|T[IÍ]TULO DE PROPIEDAD|INTT/u', $t) => LectorDocumentoPdf::PROPIEDAD,
            default => null,
        };
    }

    // ── De que equipo es ──────────────────────────────────────────────────────────

    /**
     * El equipo al que pertenece un titulo / poliza / ROTC. Primero por SERIAL (17
     * caracteres, no se repite) y si no, por PLACA. Si el serial apunta a uno y la placa a
     * otro, manda el serial y se avisa: el serial es el que no se confunde.
     *
     * Devuelve una lista (de 0 o 1) para que quien lo use no distinga este caso del RACDA.
     */
    private function equipoDelDocumento(array $leido): array
    {
        $seriales = array_filter(array_merge(
            [$leido['serial'] ?? null],
            $leido['seriales'] ?? [],
            $leido['seriales_flota'] ?? []
        ));
        $placas = array_filter(array_merge([$leido['placa'] ?? null], $leido['placas'] ?? []));

        $fila = $seriales ? $this->buscarPorSerial($seriales) : null;
        if (!$fila && $placas) $fila = $this->buscarPorPlaca($placas);

        return $fila ? [$this->ficha($fila)] : [];
    }

    /** Los equipos que nombra la lista de placas de una providencia RACDA. */
    private function equiposDelRacda(array $leido): array
    {
        $placas = $leido['placas'] ?? [];
        if (!$placas) return [];

        return $this->fichas($this->filasPorPlaca($placas, self::TOPE_RACDA));
    }

    private function buscarPorSerial(array $seriales): ?object
    {
        return $this->consulta()
            ->whereIn(DB::raw('UPPER(e.SERIAL_CHASIS)'), array_map('strtoupper', array_values($seriales)))
            ->first();
    }

    private function buscarPorPlaca(array $placas): ?object
    {
        return $this->filasPorPlaca($placas, 1)->first();
    }

    private function filasPorPlaca(array $placas, int $tope)
    {
        // La placa se compara sin guiones ni espacios: en la ficha puede estar "A50AB1D" y en
        // el PDF "A50-AB1D". Es la misma normalizacion que usa el lector (placaEnLista).
        $limpias = array_values(array_unique(array_map(
            fn ($p) => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $p)),
            $placas
        )));
        $limpias = array_filter($limpias);
        if (!$limpias) return collect();

        return $this->consulta()
            ->whereIn(DB::raw("UPPER(REPLACE(REPLACE(REPLACE(d.PLACA, '-', ''), ' ', ''), '.', ''))"), $limpias)
            ->limit($tope)
            ->get();
    }

    /** Base comun: la ficha viva con su documentacion. */
    private function consulta()
    {
        return DB::table('documentacion as d')
            ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->whereNull('e.deleted_at')
            ->select([
                'd.ID_EQUIPO', 'd.PLACA', 'e.SERIAL_CHASIS', 'e.MODELO', 'e.MARCA',
                'd.LINK_DOC_PROPIEDAD', 'd.LINK_POLIZA_SEGURO', 'd.LINK_ROTC', 'd.LINK_RACDA',
                'd.FECHA_VENC_POLIZA', 'd.FECHA_ROTC', 'd.FECHA_RACDA',
            ]);
    }

    private function fichas($filas): array
    {
        return $filas->map(fn ($f) => $this->ficha($f))->values()->all();
    }

    /** Lo que la pantalla necesita saber de un equipo candidato. */
    private function ficha(object $f): array
    {
        return [
            'id'     => (int) $f->ID_EQUIPO,
            'placa'  => $f->PLACA,
            'serial' => $f->SERIAL_CHASIS,
            'nombre' => trim(($f->MARCA ?? '') . ' ' . ($f->MODELO ?? '')) ?: ('Equipo #' . $f->ID_EQUIPO),
            'links'  => [
                LectorDocumentoPdf::PROPIEDAD => (bool) $f->LINK_DOC_PROPIEDAD,
                LectorDocumentoPdf::POLIZA    => (bool) $f->LINK_POLIZA_SEGURO,
                LectorDocumentoPdf::ROTC      => (bool) $f->LINK_ROTC,
                LectorDocumentoPdf::RACDA     => (bool) $f->LINK_RACDA,
            ],
            'vence_ficha' => [
                LectorDocumentoPdf::POLIZA => $this->soloFecha($f->FECHA_VENC_POLIZA),
                LectorDocumentoPdf::ROTC   => $this->soloFecha($f->FECHA_ROTC),
                LectorDocumentoPdf::RACDA  => $this->soloFecha($f->FECHA_RACDA),
            ],
        ];
    }

    private function soloFecha($v): ?string
    {
        return $v ? substr((string) $v, 0, 10) : null;
    }

    /** id => nombre de las aseguradoras, para reconocer la del membrete. */
    private function catalogoAseguradoras(): array
    {
        return \App\Models\CatalogoSeguro::pluck('NOMBRE_ASEGURADORA', 'ID_SEGURO')->all();
    }

    // ── Paso 2: aplicar lo aprobado ───────────────────────────────────────────────

    /**
     * Escribe el documento en la ficha del equipo. Devuelve ['ok'=>bool, 'mensaje'=>string].
     *
     * $pisar es la unica forma de reemplazar un documento que ya esta; sin el, se niega y
     * lo dice. Y un documento ANTERIOR al que ya tiene la ficha no entra ni con $pisar.
     *
     * $ensayo = MODO ENSAYO: pasa por TODAS las comprobaciones y dice que haria, pero no
     * escribe ni una fila ni borra nada de Drive. Es la forma de probar la pantalla contra
     * los datos de verdad sin tocarlos: lo que niega en ensayo lo negaria igual de verdad,
     * y lo que aceptaria lo cuenta campo por campo.
     */
    public function aplicar(int $idEquipo, string $tipo, string $link, ?string $vence, ?string $emision, bool $pisar = false, bool $ensayo = false): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'mensaje' => 'Tipo de documento no valido.'];
        }

        $equipo = Equipo::with('documentacion')->find($idEquipo);
        if (!$equipo) return ['ok' => false, 'mensaje' => 'El equipo ya no existe.'];

        $doc = $equipo->documentacion;
        $colLink = DocumentacionDeEquipo::COLUMNAS[$tipo]['link'];
        $colVence = DocumentacionDeEquipo::VENCIMIENTO[$tipo] ?? null;

        // 1) Ya tiene uno: no se pisa sin permiso explicito.
        $anterior = $doc?->$colLink;
        if ($anterior && !$pisar) {
            return ['ok' => false, 'requiere_pisar' => true,
                    'mensaje' => 'Este equipo ya tiene ese documento. Marca "reemplazar" si quieres cambiarlo.'];
        }

        // 2) Ni con permiso se retrocede un vencimiento: misma regla que la revision nocturna.
        if ($colVence && $vence) {
            $motivo = VerificacionDocumento::documentoAnterior($this->soloFecha($doc?->$colVence), $vence);
            if ($motivo) return ['ok' => false, 'mensaje' => $motivo];
        }

        // 3) Un documento que vence no se guarda sin su fecha (igual que uploadDoc).
        if ($colVence && !$vence) {
            return ['ok' => false, 'mensaje' => 'Falta la fecha de vencimiento.'];
        }

        $datos = [$colLink => $link];
        if ($colVence && $vence) $datos += DocumentacionDeEquipo::datosVencimiento($tipo, $vence);
        if ($emision && isset(DocumentacionDeEquipo::EMISION[$tipo])) {
            $datos[DocumentacionDeEquipo::EMISION[$tipo]] = $emision;
        }
        $datos[DocumentacionDeEquipo::COLUMNAS[$tipo]['autor']] = auth()->user()->ID_USUARIO;
        $datos[DocumentacionDeEquipo::COLUMNAS[$tipo]['fecha']] = now();

        // El diff se saca ANTES de guardar: es lo que ve el historial.
        $diff = DocumentacionDeEquipo::diff($doc, $datos);

        // MODO ENSAYO: hasta aqui llegan solo los casos que de verdad se aplicarian. Se
        // devuelve lo que se escribiria y se sale sin tocar nada.
        if ($ensayo) {
            return ['ok' => true, 'ensayo' => true, 'cambios' => $diff,
                    'mensaje' => $this->resumenEnsayo($tipo, $anterior, $diff)];
        }

        if ($doc) {
            $doc->update($datos);
        } else {
            Documentacion::create($datos + ['ID_EQUIPO' => $equipo->ID_EQUIPO]);
        }

        // El PDF que estaba se borra DESPUES de guardar el nuevo, igual que en uploadDoc.
        if ($anterior && $anterior !== $link && ($viejoId = DocumentoAnexo::driveIdDeLink($anterior))) {
            GoogleDriveService::borrarTrasResponder($viejoId);
        }

        EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'upload_' . $tipo, [
            'archivo' => basename($link),
            'origen'  => 'carga masiva',
        ]);
        if ($diff) EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'metadata_' . $tipo, $diff);

        return ['ok' => true, 'mensaje' => 'Aplicado.'];
    }

    /** Lo que el ensayo HARIA, en una linea, para que se lea en la fila. */
    private function resumenEnsayo(string $tipo, ?string $anterior, array $diff): string
    {
        $campos = [];
        foreach ($diff as $campo => $valores) {
            if ($campo === DocumentacionDeEquipo::COLUMNAS[$tipo]['link']) continue;
            if (in_array($campo, [DocumentacionDeEquipo::COLUMNAS[$tipo]['autor'],
                                  DocumentacionDeEquipo::COLUMNAS[$tipo]['fecha']], true)) continue;
            $campos[] = $campo . ': ' . ($valores['antes'] ?? 'vacío') . ' → ' . ($valores['despues'] ?? 'vacío');
        }

        return 'ENSAYO — ' . ($anterior ? 'reemplazaría el PDF que ya tiene' : 'enlazaría el PDF')
            . ($campos ? ' y pondría ' . implode('; ', $campos) : '')
            . '. No se escribió nada.';
    }

    /**
     * Borra de Drive el PDF de una propuesta que el usuario descarto. Sin esto, analizar
     * treinta y aplicar cinco dejaria veinticinco archivos huerfanos en Drive.
     */
    public function descartar(?string $link): void
    {
        if ($id = DocumentoAnexo::driveIdDeLink($link)) {
            GoogleDriveService::borrarTrasResponder($id);
        }
    }
}
