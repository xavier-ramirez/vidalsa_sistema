<?php

namespace App\Services;

use App\Models\Documentacion;
use App\Models\DocumentoAnexo;
use App\Models\Embarque;
use App\Models\Equipo;
use App\Models\EquipoAuditLog;
use App\Models\EquipoAuxiliar;
use App\Models\VerificacionDocumento;
use App\Support\BillOfLading;
use App\Support\DocumentacionDeEquipo;
use App\Support\EnlacesDocumentos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Carga masiva de documentos: sube VARIOS PDF y cada uno se enlaza solo a su equipo.
 *
 * El trabajo va en DOS pasos separados a proposito, y el usuario decide entre uno y otro:
 *
 *   1) analizar()  Sube el PDF a Drive, lo LEE y propone de que equipo es y que fechas trae.
 *                  El TIPO lo elige el usuario antes de soltar los archivos, y aqui solo se
 *                  COMPRUEBA que el PDF sea de verdad ese documento: si es otro, no se asocia a
 *                  nada y queda en la tabla como "Otro documento" (ver esOtroDocumento). NO toca
 *                  la ficha.
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
    /** El "Certificado asociado" y la "Compraventa" de la ficha (LINK_DOC_ADICIONAL y _2). */
    public const CERTIFICADO = 'adicional';
    public const COMPRAVENTA = 'adicional_2';

    /**
     * El documento de EMBARQUE (Bill of Lading). No es una casilla de la ficha: un BL ampara a
     * muchas unidades y vive en su propia tabla (Embarque), ver aplicarEmbarque.
     */
    public const EMBARQUE = 'embarque';

    /**
     * Los documentos que esta pantalla sabe repartir: los seis de la ficha del equipo y el de
     * embarque. El usuario elige SIEMPRE cual va a cargar. De los que tienen rotulo (ROTULOS)
     * se comprueba que el PDF lo sea; el certificado y la compraventa no traen un rotulo fijo y
     * se toman como el usuario dice.
     */
    public const TIPOS = [
        LectorDocumentoPdf::PROPIEDAD,
        LectorDocumentoPdf::POLIZA,
        LectorDocumentoPdf::ROTC,
        LectorDocumentoPdf::RACDA,
        self::CERTIFICADO,
        self::COMPRAVENTA,
        self::EMBARQUE,
    ];

    /** Como se llama cada tipo en pantalla (los mismos rotulos que la ficha). */
    public const NOMBRES = [
        LectorDocumentoPdf::PROPIEDAD => 'Titulo de propiedad',
        LectorDocumentoPdf::POLIZA    => 'Poliza de seguro',
        LectorDocumentoPdf::ROTC      => 'ROTC',
        LectorDocumentoPdf::RACDA     => 'RACDA',
        self::CERTIFICADO             => 'Certificado asociado',
        self::COMPRAVENTA             => 'Compraventa',
        self::EMBARQUE                => 'Documento de embarque (BL)',
    ];

    /**
     * Los documentos que un AUXILIAR puede tener, y como se llama cada uno en su ficha. Un
     * auxiliar no tiene placa, ni poliza, ni ROTC, ni RACDA: solo el titulo y el certificado
     * (EquipoAuxiliar::DOCS, la fuente de siempre). Lo que aqui se llama 'adicional'
     * —"Certificado asociado" del equipo— alli se llama 'certificado': este mapa es el UNICO
     * sitio donde se traducen los dos vocabularios.
     */
    private const TIPOS_AUXILIAR = [
        LectorDocumentoPdf::PROPIEDAD => 'propiedad',
        self::CERTIFICADO             => 'certificado',
    ];

    /** Prefijo del archivo en Drive, por tipo (mismo que usa uploadDoc). */
    private const PREFIJOS = [
        LectorDocumentoPdf::PROPIEDAD => 'doc_propiedad_',
        LectorDocumentoPdf::POLIZA    => 'poliza_seguro_',
        LectorDocumentoPdf::ROTC      => 'rotc_',
        LectorDocumentoPdf::RACDA     => 'racda_',
        self::CERTIFICADO             => 'doc_adicional_',
        self::COMPRAVENTA             => 'doc_adicional_2_',
        self::EMBARQUE                => 'embarque_',
    ];

    /**
     * Un RACDA es de la EMPRESA y nombra muchas unidades; enlazarlo a cientos de fichas de
     * un golpe desde aqui seria una operacion enorme detras de un solo clic. Se propone
     * hasta este tope y, si hay mas, se avisa: para la flota entera esta la revision
     * nocturna, que ya lo reparte.
     */
    private const TOPE_RACDA = 40;

    /**
     * Un BL de SINOTRUK nombra hasta ~90 unidades (el HCLKGT03, 84). Enlazar cada una es solo
     * una fila en embarque_equipo —no se sube ni se arma nada por equipo—, asi que el tope es
     * mas alto que el del RACDA.
     */
    private const TOPE_EMBARQUE = 150;

    /** Lo que cabe en verificacion_documento_registro.MOTIVO (varchar 255). */
    private const MOTIVO_MAX = 255;

    /**
     * Lo que la busqueda de fichas tiene que contar en la propuesta: que el documento nombra
     * varias unidades y se propuso solo una, que el RACDA paso del tope... Lo llenan
     * equipoDelDocumento / auxiliarDelDocumento / equiposDelRacda y lo lee analizar(), que lo
     * vacia al empezar cada archivo.
     */
    private ?string $avisoFichas = null;

    /**
     * Lo que dijo la IA del archivo que se esta analizando: false = todavia no se le pregunto.
     * Se guarda porque se le puede preguntar dos veces por el mismo PDF (para confirmar el tipo
     * y para rellenar lo que falto) y cada pregunta gasta cupo y ~6 s. Lo vacia analizar().
     */
    private array|false|null $vistoIa = false;

    /** "No se pudo confirmar que sea X": no impide aplicar, pero la propuesta no sale "listo". */
    private ?string $avisoTipo = null;

    public function __construct(private LectorDocumentoPdf $lector, private LectorGemini $ia, private ?RotcDeFlota $rotcFlota = null) {}

    private function rotcFlota(): RotcDeFlota
    {
        return $this->rotcFlota ??= app(RotcDeFlota::class);
    }

    // ── Paso 1: subir, leer y proponer ────────────────────────────────────────────

    /**
     * Sube $archivo a Drive, lo lee y devuelve la propuesta. $tipoPedido es el documento que el
     * usuario dice que va a cargar ("estos son titulos"): se busca ESE documento y, si el PDF
     * resulta ser otro, la propuesta queda como "otro_documento" y no se asocia a ninguna ficha.
     *
     * Devuelve SIEMPRE una propuesta, tambien cuando no se pudo leer: la pantalla la pinta
     * igual con su motivo, para que el usuario vea que paso con cada archivo.
     */
    public function analizar(UploadedFile $archivo, string $tipoPedido): array
    {
        $subido = $this->subir($archivo, $tipoPedido);
        return isset($subido['estado']) ? $subido : $this->leer($archivo, $tipoPedido, $subido);
    }

    /**
     * Paso 1a, el rapido: sube $archivo a Drive. Devuelve lo que necesita leer() (nombre, link,
     * driveId, md5) o, si no se pudo subir, la propuesta fallida (con 'estado'), que no deja
     * fila en la tabla. La pantalla de carga masiva solo espera a esto; la lectura va despues
     * de responder (ver CargaMasivaDocumentosController::analizar).
     */
    public function subir(UploadedFile $archivo, string $tipoPedido): array
    {
        $nombre = $archivo->getClientOriginalName();
        $prefijo = self::PREFIJOS[$tipoPedido] ?? 'doc_masivo_';
        // La huella del archivo: con ella se sabe si ESTE MISMO PDF ya se habia soltado antes
        // (ver yaSeSolto). Se saca antes de subirlo.
        $md5 = @md5_file($archivo->getRealPath()) ?: null;

        try {
            $link = GoogleDriveService::getInstance()->subirPdf($archivo, $prefijo . time() . '_' . mt_rand(1000, 9999) . '.pdf');
        } catch (\App\Exceptions\PdfNoValido $e) {
            // Lo que rechaza el propio archivo (un PDF cortado a medias, uno vacio, algo que no
            // es un PDF): su mensaje dice QUE hacer —volver a escanearlo— y es justo lo que la
            // persona necesita leer, porque sigue delante de la pantalla. Un "no se pudo subir"
            // generico la haria reintentar el mismo archivo roto una y otra vez.
            Log::warning('Carga masiva: el archivo no se pudo aceptar', ['archivo' => $nombre, 'error' => $e->getMessage()]);
            return $this->fallo($nombre, null, $e->getMessage());
        } catch (\Throwable $e) {
            Log::error('Carga masiva: no se pudo subir a Drive', ['archivo' => $nombre, 'error' => $e->getMessage()]);
            return $this->fallo($nombre, null, 'No se pudo subir el archivo a Drive.');
        }

        $driveId = DocumentoAnexo::driveIdDeLink($link);
        if (!$driveId) return $this->fallo($nombre, $link, 'El archivo se subio pero Drive no devolvio un enlace utilizable.');

        return ['nombre' => $nombre, 'link' => $link, 'driveId' => $driveId, 'md5' => $md5];
    }

    /**
     * Paso 1b, el lento (OCR de Drive, ~8 s): lee el PDF que subir() ya puso en Drive, arma su
     * propuesta y la anota en la tabla. $archivo es el MISMO PDF en el disco (la IA y el ROTC
     * de flota lo leen de ahi).
     */
    public function leer(UploadedFile $archivo, string $tipoPedido, array $subido): array
    {
        $this->avisoFichas = null;
        $this->avisoTipo = null;
        $this->vistoIa = false;
        ['nombre' => $nombre, 'link' => $link, 'driveId' => $driveId, 'md5' => $md5] = $subido;

        // Lo que falle de aqui en adelante (una consulta, anotar la fila) dejaria el PDF en
        // Drive sin fila en la tabla: nadie podria aplicarlo ni descartarlo. Vuelve a la
        // papelera y queda en el log.
        try {
            return $this->proponer($archivo, $tipoPedido, $nombre, $link, $driveId, $md5);
        } catch (\Throwable $e) {
            Log::error('Carga masiva: fallo al analizar un PDF ya subido', ['archivo' => $nombre, 'error' => $e->getMessage()]);
            GoogleDriveService::borrarTrasResponder($driveId);
            return $this->fallo($nombre, null, 'No se pudo analizar el archivo. Vuelve a subirlo.');
        }
    }

    /** Lee el PDF ya subido y arma su propuesta (ver analizar). */
    private function proponer(UploadedFile $archivo, string $tipoPedido, string $nombre, string $link, string $driveId, ?string $md5): array
    {
        // El motivo por el que la lectura de siempre no llego a nada: es el que se le enseña al
        // usuario si la IA tampoco lo resuelve (o no esta puesta). Primero "no se pudo leer" y
        // luego "esta en blanco".
        $motivo = null;
        try {
            $texto = trim($this->lector->texto($driveId));
        } catch (\Throwable $e) {
            Log::warning('Carga masiva: fallo la lectura', ['archivo' => $nombre, 'error' => $e->getMessage()]);
            [$texto, $motivo] = ['', 'No se pudo leer el PDF.'];
        }
        if ($texto === '' && !$motivo) {
            $motivo = 'El PDF no tiene texto legible (esta escaneado muy bajo o en blanco).';
        }

        // ¿Es de verdad el documento que se eligio? Si es otro no se busca su equipo: se anota
        // tal cual en la tabla y ahi se queda, sin asociarse a nada.
        if ($otro = $this->esOtroDocumento($archivo, $tipoPedido, $texto)) {
            return $this->anotar($this->propuestaDeOtroDocumento($nombre, $link, $md5, $tipoPedido, $otro), $driveId);
        }

        // El documento de embarque no es de UN equipo sino de todos los VIN de su anexo.
        if ($tipoPedido === self::EMBARQUE) {
            return $this->anotar($this->propuestaDeEmbarque($archivo, $nombre, $link, $md5, $driveId, $texto), $driveId);
        }

        // Sin texto el tipo queda en el aire: si la IA lee el PDF lo pone ella (apoyarConIa, con
        // el tipo pedido) y si no, sale "no tiene texto legible".
        $tipo = $texto !== '' ? $tipoPedido : null;

        // El ROTC ENTERO de la flota (portada, tabla de toda la flota y los certificados): no
        // es de UN equipo sino de todos los de su tabla, y a cada uno le va SU parte (ver
        // RotcDeFlota). Se reparte como un RACDA: una ficha por vehiculo registrado.
        if ($tipo === LectorDocumentoPdf::ROTC && ($flota = $this->leerFlota($archivo))) {
            return $this->anotar($this->propuestaDeFlota($nombre, $link, $md5, $driveId, $flota), $driveId);
        }

        $leido = $tipo ? $this->lector->extraer($tipo, $texto) : [];
        // Todos los codigos de la hoja: con ellos se reconoce un AUXILIAR aunque su serial sea
        // corto o no vaya detras de un rotulo de chasis (ver auxiliarDelDocumento).
        if ($leido) $leido['codigos'] = $this->codigosEnTexto($texto);
        if ($tipo === LectorDocumentoPdf::POLIZA) {
            $catalogo = $this->catalogoAseguradoras();
            $idSeguro = $this->lector->aseguradoraEnTexto($texto, $catalogo);
            $leido['aseguradora'] = $idSeguro ? $catalogo[$idSeguro] : null;
        }
        $equipos = $this->equiposDeLoLeido($tipo, $leido);

        // ── Apoyo de la IA ────────────────────────────────────────────────────────
        // Solo cuando la lectura de siempre (OCR de Drive + reglas) no alcanzo: sin texto,
        // sin saber que documento es, sin dar con el equipo o sin la fecha que hace falta.
        // Si esta lo resuelve todo, la IA ni se entera: no se gasta cupo ni tiempo.
        $conIa = false;
        $notaIa = null;
        if (!$tipo || !$equipos || $this->faltaFechaQueAlguienPodriaLeer($tipo, $leido)) {
            [$tipo, $leido, $equipos, $conIa, $notaIa] = $this->apoyarConIa($archivo, $tipoPedido, $tipo, $leido, $equipos);
        }

        // Sin tipo (en blanco y la IA no lo leyo) no hay nada que proponer: se devuelve el motivo
        // de la lectura de siempre, porque es el que explica que paso con el archivo.
        if (!$tipo) {
            return $this->anotar($this->fallo($nombre, $link, $motivo ?: 'No se pudo leer el PDF.'), $driveId);
        }

        // ROTC de flota: el vencimiento de ESTE equipo es el de SU fila en la tabla, como lo lee
        // la revision de la noche (LectorDocumentoPdf::filaRotc). El certificado de debajo trae
        // sus propias fechas y pueden ser viejas: en un ROTC real (25-09-2026) el certificado
        // vencia el 30/05/2026 y la tabla, renovada, decia 03/07/2027.
        if ($tipo === LectorDocumentoPdf::ROTC && count($equipos) === 1 && empty($equipos[0]['auxiliar'])) {
            $fila = $this->lector->filaRotc($equipos[0]['placa'], $equipos[0]['serial'], $leido);
            if ($fila || (!empty($leido['vence_flota']) && $this->enLaHoja($equipos[0], $leido))) {
                // Sin la fila (la tabla no salio fila por fila) pero con el equipo en la hoja,
                // vale la fecha de la cabecera de la flota. Y la emision, la de ESA hoja: la del
                // certificado va con su propio vencimiento, no con este.
                $leido['vence'] = $fila['vence'] ?? $leido['vence_flota'];
                if (!empty($leido['emision_flota'])) $leido['emision'] = $leido['emision_flota'];
            }
        }

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
            'ia'      => $conIa,
            'md5'     => $md5,
        ];

        if (!$equipos) {
            $propuesta['estado'] = 'sin_equipo';
            $propuesta['aviso'] = match (true) {
                $tipo === LectorDocumentoPdf::RACDA
                    => 'Se leyo la providencia pero ninguna de sus placas esta registrada.',
                // El serial SI cuadro, pero con un auxiliar, y un auxiliar no guarda ese
                // documento: decir "no se sabe de quien es" haria buscar el fallo donde no esta.
                !isset(self::TIPOS_AUXILIAR[$tipo]) && $this->auxiliarDelDocumento($leido)
                    => 'Es de un equipo auxiliar, y los auxiliares solo guardan titulo de propiedad y certificado.',
                default
                    => 'Se leyo el documento pero no dice de que equipo es (ni placa ni serial reconocidos).',
            };
            return $this->anotar($propuesta, $driveId);
        }

        // Un documento que vence sin su fecha no se puede aplicar: es el dato que vigila la app
        // y uploadDoc tampoco lo acepta. Se propone igual para que el usuario la escriba.
        if ($this->faltaVencimiento($tipo, $leido)) {
            $propuesta['estado'] = 'revisar';
            $propuesta['aviso'] = 'No se leyo la fecha de vencimiento. Escribela para poder aplicarlo.';
        } elseif ($conIa) {
            $propuesta['estado'] = 'revisar';
            $propuesta['aviso'] = trim('Leido con apoyo de inteligencia artificial: comprueba el equipo y las fechas antes de aplicar. ' . $notaIa);
        }

        // Lo que la busqueda de fichas quiere que se mire (varias unidades, tope del RACDA) y
        // el mismo archivo soltado otra vez: no impiden aplicar, pero no puede salir "listo".
        $avisos = array_filter([$this->avisoTipo, $this->avisoFichas, $this->yaSeSolto($md5, $driveId)]);
        if ($avisos) {
            $propuesta['estado'] = 'revisar';
            $propuesta['aviso'] = trim(implode(' ', $avisos) . ' ' . ($propuesta['aviso'] ?? ''));
        }

        return $this->anotar($propuesta, $driveId);
    }

    /**
     * Deja la propuesta en la tabla de Revision de documentos, que es DONDE SE VE el estado de
     * lo que se sube (el modal solo sirve para soltar archivos). Una fila por PDF, con
     * ORIGEN='carga_masiva' para no confundirla con lo que lee la tarea de la noche.
     *
     * NO escribe en ninguna ficha: es una propuesta esperando que alguien pulse "Aplicar".
     * Si falla al anotarla, la excepcion sube a analizar(), que devuelve el PDF a la papelera:
     * sin su fila nadie podria aplicarlo ni descartarlo.
     */
    private function anotar(array $propuesta, ?string $driveId): array
    {
        if (!$driveId) return $propuesta;

        $ficha = $propuesta['equipos'][0] ?? null;
        try {
            VerificacionDocumento::updateOrCreate(
                ['DRIVE_ID' => $driveId, 'ORIGEN' => VerificacionDocumento::DE_CARGA_MASIVA],
                [
                    'ID_EQUIPO'   => ($ficha && !$ficha['auxiliar']) ? $ficha['id'] : null,
                    'ID_AUXILIAR' => ($ficha && $ficha['auxiliar']) ? $ficha['id'] : null,
                    'TIPO'        => $propuesta['tipo'],
                    'PLACA'       => $ficha['placa'] ?? null,
                    'SERIAL'      => $ficha['serial'] ?? null,
                    'ARCHIVO'     => $propuesta['archivo'],
                    'PROPUESTA'   => $propuesta,
                    'ESTADO'      => match (true) {
                        ($propuesta['estado'] ?? null) === self::OTRO_DOCUMENTO => VerificacionDocumento::OTRO_DOCUMENTO,
                        (bool) $ficha => VerificacionDocumento::POR_ENGANCHAR,
                        default       => VerificacionDocumento::SIN_FICHA,
                    },
                    'MOTIVO'      => mb_substr($this->motivoDeLaPropuesta($propuesta), 0, self::MOTIVO_MAX),
                    'A_MANO'      => true,
                    'INTENTOS'    => 0,
                ]
            );
        } catch (\Throwable $e) {
            // Sin su fila el PDF no se podria aplicar ni descartar: que lo recoja analizar().
            Log::warning('Carga masiva: no se pudo anotar la propuesta', [
                'archivo' => $propuesta['archivo'], 'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $propuesta;
    }

    /**
     * El PDF ya esta en su ficha: su fila de la tabla pasa a "Aplicado". No se borra, para que
     * se vea que paso con cada archivo que se solto; cuando la tarea de la noche relea ese
     * documento —que ya es de la ficha— la convertira en una lectura suya.
     */
    public function cerrarPropuesta(string $link): void
    {
        if (!$driveId = DocumentoAnexo::driveIdDeLink($link)) return;

        VerificacionDocumento::where('DRIVE_ID', $driveId)
            ->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)
            ->update([
                'ESTADO'       => VerificacionDocumento::APLICADO,
                'A_MANO'       => false,
                'APLICADO_POR' => auth()->id(),
                'APLICADO_EN'  => now(),
                'updated_at'   => now(),
            ]);
    }

    // ── ROTC de flota ─────────────────────────────────────────────────────────────

    /**
     * Lo que dice RotcDeFlota del PDF, si es un ROTC de flota ENTERO: dos o mas hojas de la
     * tabla, o dos o mas certificados. Una PARTE ya separada (portada, una hoja y un
     * certificado, como las que se armaban a mano) es de un solo equipo y sigue el camino de
     * siempre. Sin Ghostscript, o si no se puede leer, tambien: null.
     */
    private function leerFlota(UploadedFile $archivo): ?array
    {
        try {
            if (!$this->rotcFlota()->disponible()) return null;
            $flota = $this->rotcFlota()->leer($archivo->getRealPath());
        } catch (\Throwable $e) {
            Log::warning('Carga masiva: no se pudo leer el ROTC como flota', ['archivo' => $archivo->getClientOriginalName(), 'error' => $e->getMessage()]);
            return null;
        }
        if (!$flota) return null;

        $hojas = count(array_unique(array_column($flota['filas'], 'pagina')));
        return ($hojas >= 2 || count($flota['certificados']) >= 2) ? $flota : null;
    }

    /**
     * La propuesta de un ROTC de flota: todos los equipos registrados de su tabla, cada uno con
     * DONDE esta lo suyo (la hoja de su fila y su certificado, si viene) y SUS fechas (las de su
     * fila; la emision, la de su certificado o la de la hoja). aplicar() arma su parte con eso.
     */
    private function propuestaDeFlota(string $nombre, string $link, ?string $md5, ?string $driveId, array $flota): array
    {
        $filas = collect($flota['filas']);
        // SOLO por el serial de chasis (N.I.V.): no se repite y no cambia de vehiculo. La
        // placa si: una que paso a otro camion le daria a esta ficha la fila, el certificado
        // y el vencimiento de ese otro. Un equipo sin serial en el sistema no se reparte.
        // Sin la clave VACIA: un serial que la tabla del ROTC trae en blanco o solo con rayas se
        // reduce a '' igual que los 5 equipos cuyo SERIAL_CHASIS es basura, y sin este filtro se
        // repartirian entre ellos el certificado y el vencimiento de ese vehiculo.
        $porSerial = $filas->keyBy(fn ($f) => $this->lector->codigo($f['serial']))->forget('');
        $certSerial = collect($flota['certificados'])->keyBy(fn ($c) => $this->lector->codigo($c['serial']))->forget('');

        $registrados = $porSerial->isEmpty() ? collect() : $this->consulta()
            ->whereIn(DB::raw(self::sqlCodigo('e.SERIAL_CHASIS')), $porSerial->keys()->all())
            ->orderBy('e.ID_EQUIPO')->get();

        $fichas = [];
        $conCertificado = 0;
        foreach ($registrados as $r) {
            $fila = $porSerial->get($this->lector->codigo((string) $r->SERIAL_CHASIS));
            if (!$fila) continue;
            $porQue = 'el serial ' . $r->SERIAL_CHASIS;

            $cert = $certSerial->get($this->lector->codigo($fila['serial']));
            if ($cert) $conCertificado++;
            $fichas[] = ['coincide_por' => $porQue] + $this->ficha($r) + ['rotc' => [
                'tabla'   => $fila['pagina'],
                'cert'    => $cert,
                'vence'   => $fila['vence'],
                'emision' => $cert['emision'] ?? $flota['emision'],
            ]];
        }

        $total = $porSerial->count();
        $propuesta = [
            'archivo' => $nombre, 'link' => $link, 'tipo' => LectorDocumentoPdf::ROTC,
            'tipo_nombre' => self::NOMBRES[LectorDocumentoPdf::ROTC],
            'vence' => $flota['vence'], 'emision' => $flota['emision'],
            'titular' => null, 'nro' => null, 'aseguradora' => null,
            'equipos' => $fichas, 'estado' => 'listo', 'aviso' => null, 'ia' => false, 'md5' => $md5,
            // Lo que aplicar() necesita para armar la parte de cada equipo (ver parteDeFlota).
            'flota_rotc' => ['portada' => $flota['portada']],
        ];

        if (!$fichas) {
            $propuesta['estado'] = 'sin_equipo';
            $propuesta['aviso'] = "ROTC de flota con $total vehiculos: ninguno esta registrado.";
            return $propuesta;
        }

        $sinCert = count($fichas) - $conCertificado;
        $propuesta['aviso'] = "ROTC de flota: $total vehiculos en la tabla, " . count($fichas) . ' registrados'
            . ($total > count($fichas) ? ' (los demas no estan en el sistema)' : '') . '. '
            . 'A cada uno se le enlaza SU parte: la portada, su hoja de la tabla'
            . ($conCertificado ? " y su certificado ($conCertificado lo traen" . ($sinCert ? "; $sinCert no, y van sin certificado)" : ')') : ' (el documento no trae certificados)')
            . '.';
        if ($otra = $this->yaSeSolto($md5, $driveId)) {
            $propuesta['estado'] = 'revisar';
            $propuesta['aviso'] = $otra . ' ' . $propuesta['aviso'];
        }
        return $propuesta;
    }

    /** La propuesta que se anoto en la tabla para el PDF de $link (ver anotar), o null. */
    private function propuestaGuardada(string $link): ?array
    {
        if (!$driveId = DocumentoAnexo::driveIdDeLink($link)) return null;
        $p = VerificacionDocumento::where('DRIVE_ID', $driveId)
            ->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)->value('PROPUESTA');
        $p = is_string($p) ? json_decode($p, true) : $p;
        return is_array($p) ? $p : null;
    }

    /** La parte de este equipo en una propuesta de ROTC de flota, o null si no es de esas. */
    private function parteDeFlota(string $link, int $idEquipo): ?array
    {
        $p = $this->propuestaGuardada($link);
        if (empty($p['flota_rotc'])) return null;

        foreach ($p['equipos'] ?? [] as $f) {
            if ((int) $f['id'] === $idEquipo && empty($f['auxiliar']) && isset($f['rotc'])) {
                return ['ficha' => $f, 'portada' => $p['flota_rotc']['portada'] ?? [],
                        'pieza' => $p['flota_rotc']['piezas'][$idEquipo] ?? null];
            }
        }
        return null;
    }

    /**
     * Aplicar la parte de UN equipo de un ROTC de flota. Las MISMAS puertas que cualquier
     * documento (no pisa sin permiso, no retrocede un vencimiento), probadas ANTES de armar
     * nada: solo si pasan se arma su PDF, se sube y se enlaza. Asi un "¿reemplazarlo?" o un
     * documento anterior no dejan partes sueltas en Drive.
     */
    private function aplicarParteDeFlota(int $idEquipo, string $link, array $parte, bool $pisar, bool $ensayo, bool $cerrar): array
    {
        $rotc = $parte['ficha']['rotc'];

        // Ya tiene SU parte de esta misma propuesta (otra pestaña, o repetir tras un corte).
        $actual = Documentacion::where('ID_EQUIPO', $idEquipo)->value('LINK_ROTC');
        if ($parte['pieza'] && $this->mismoArchivo($actual, $parte['pieza'])) {
            if (!$ensayo && $cerrar) $this->cerrarPropuesta($link);
            return ['ok' => true, 'mensaje' => 'Ya estaba enlazado.'];
        }

        $prueba = $this->aplicarEnEquipo($idEquipo, LectorDocumentoPdf::ROTC, $link, $rotc['vence'], $rotc['emision'], $pisar, true, false);
        if (!$prueba['ok'] || $ensayo) return $prueba;

        try {
            $pieza = $this->subirParte($link, $parte);
        } catch (\Throwable $e) {
            Log::error('Carga masiva: no se pudo armar la parte del ROTC de flota', ['equipo' => $idEquipo, 'error' => $e->getMessage()]);
            return ['ok' => false, 'mensaje' => 'No se pudo preparar la parte de este equipo del ROTC. Vuelve a intentarlo.'];
        }

        $r = $this->aplicarEnEquipo($idEquipo, LectorDocumentoPdf::ROTC, $pieza, $rotc['vence'], $rotc['emision'], $pisar, false, false);
        if (!$r['ok']) {
            // Algo cambio entre la prueba y ahora: la parte recien subida no se queda suelta.
            if ($id = DocumentoAnexo::driveIdDeLink($pieza)) GoogleDriveService::borrarTrasResponder($id);
            return $r;
        }

        $this->anotarPieza($link, $idEquipo, $pieza);
        if ($cerrar) $this->cerrarPropuesta($link);
        return $r;
    }

    /** Arma la parte del equipo con el ROTC original (la copia local, o de Drive) y la sube. */
    private function subirParte(string $link, array $parte): string
    {
        $idOriginal = DocumentoAnexo::driveIdDeLink($link);
        $ruta = GoogleDriveService::rutaCopiaLocal($idOriginal);
        $disco = Storage::disk('local');
        if (!$disco->exists($ruta)) {
            $disco->put($ruta, (string) GoogleDriveService::getInstance()->getStreamById($idOriginal)->getContents());
        }

        $rotc = $parte['ficha']['rotc'];
        $destino = tempnam(sys_get_temp_dir(), 'rotc_parte_');
        try {
            $this->rotcFlota()->parte($disco->path($ruta), ['portada' => $parte['portada']], (int) $rotc['tabla'], $rotc['cert'] ?? null, $destino);
            $placa = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($parte['ficha']['placa'] ?? ''))) ?: 'equipo';
            return GoogleDriveService::getInstance()->subirPdf(new \Illuminate\Http\File($destino), 'rotc_' . time() . '_' . $placa . '.pdf');
        } finally {
            @unlink($destino);
        }
    }

    /** Recuerda que parte se le enlazo a cada equipo (para "Ya estaba enlazado"). */
    private function anotarPieza(string $link, int $idEquipo, string $pieza): void
    {
        $fila = VerificacionDocumento::where('DRIVE_ID', DocumentoAnexo::driveIdDeLink($link))
            ->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)->first();
        if (!$fila) return;
        $p = $fila->PROPUESTA;
        $p['flota_rotc']['piezas'][$idEquipo] = $pieza;
        $fila->update(['PROPUESTA' => $p]);
    }

    /**
     * ¿Alguna ficha tiene ya este PDF, o una parte suya (ROTC de flota)? Con eso la propuesta
     * queda resuelta aunque la ULTIMA ficha no entrara (ver el controlador).
     */
    public function yaSeAplicoAlgo(string $link): bool
    {
        if (!$id = DocumentoAnexo::driveIdDeLink($link)) return false;
        if (EnlacesDocumentos::sigueEnUso($id)) return true;

        return !empty($this->propuestaGuardada($link)['flota_rotc']['piezas']);
    }

    /** Lo que se lee en la columna "Que dice la ficha y que dice el documento" de la tabla. */
    private function motivoDeLaPropuesta(array $p): string
    {
        $ficha = $p['equipos'][0] ?? null;
        // Sin ficha, lo unico que hay que contar es POR QUE no se supo de quien es.
        if (!$ficha) return (string) ($p['aviso'] ?: 'Subido en carga masiva. Falta saber de que ficha es.');

        $otros = count($p['equipos']) > 1 ? ' y ' . (count($p['equipos']) - 1) . ' unidad(es) mas' : '';

        // El POR QUE va SIEMPRE primero, tambien cuando hubo un aviso (fecha que falta, lectura
        // con ayuda de la IA...). Antes el aviso lo tapaba, y era justo cuando mas falta hace
        // saber que dato del PDF cuadro con la ficha.
        //
        // Y CABEN LOS DOS: la columna son 255 caracteres, asi que si el aviso no entra entero se
        // recorta la parte de delante —el nombre del equipo, que ya se ve en su propia columna—
        // y nunca el aviso, que es lo que dice que hay que hacer.
        $aviso = trim((string) ($p['aviso'] ?: 'Falta aplicarlo a la ficha.'));
        $porQue = 'Reconocido por ' . ($ficha['coincide_por'] ?? 'lo que dice el PDF')
            . ', que es de ' . $ficha['nombre'] . $otros
            . ($p['vence'] ? '. Vence el ' . implode('/', array_reverse(explode('-', $p['vence']))) : '')
            . '. ';

        $sobra = mb_strlen($porQue) + mb_strlen($aviso) - self::MOTIVO_MAX;
        if ($sobra > 0) $porQue = mb_substr($porQue, 0, max(0, mb_strlen($porQue) - $sobra - 1)) . '… ';

        return trim($porQue . $aviso);
    }

    /** Propuesta que no llego a ninguna parte, con su motivo. El archivo ya esta en Drive. */
    private function fallo(string $archivo, ?string $link, string $motivo): array
    {
        return [
            'archivo' => $archivo, 'link' => $link, 'tipo' => null, 'tipo_nombre' => null,
            'vence' => null, 'emision' => null, 'titular' => null, 'nro' => null,
            'aseguradora' => null, 'equipos' => [], 'estado' => 'ilegible', 'aviso' => $motivo,
            'ia' => false,
        ];
    }

    /** Los equipos que nombra lo leido, segun sea una providencia (varios) o no (uno). */
    private function equiposDeLoLeido(?string $tipo, array $leido): array
    {
        if (!$tipo || !$leido) return [];
        if ($tipo === LectorDocumentoPdf::RACDA) return $this->equiposDelRacda($leido);

        $fichas = $this->equipoDelDocumento($leido);
        // Si ningun equipo lo reconoce, puede ser de un AUXILIAR. Solo se mira para los dos
        // documentos que un auxiliar puede guardar (titulo y certificado): buscarle una poliza
        // o un ROTC no tiene sentido, no tiene donde ponerlos.
        if (!$fichas && isset(self::TIPOS_AUXILIAR[$tipo])) {
            $fichas = $this->auxiliarDelDocumento($leido);
        }

        return $fichas;
    }

    /** ¿Es de los que vencen y no se leyo su fecha? Sin ella no se puede aplicar. */
    private function faltaVencimiento(?string $tipo, array $leido): bool
    {
        return $tipo && isset(DocumentacionDeEquipo::VENCIMIENTO[$tipo]) && empty($leido['vence']);
    }

    /**
     * Lo mismo, pero SOLO para los documentos cuya fecha alguien sabe leer. Un "Certificado
     * asociado" vence, pero ni las reglas ni la IA tienen forma de sacarle la fecha: no hay
     * dos formatos iguales y su rotulo no es fijo. Preguntarle a la IA por esa fecha seria
     * gastar cupo y ~6 s en CADA certificado para no obtener nada nunca: esa fecha se teclea.
     */
    private function faltaFechaQueAlguienPodriaLeer(?string $tipo, array $leido): bool
    {
        return isset(self::FECHA_LEGIBLE[$tipo]) && $this->faltaVencimiento($tipo, $leido);
    }

    /** Los documentos a los que el lector (y la IA) saben sacarles el vencimiento. */
    private const FECHA_LEGIBLE = [
        LectorDocumentoPdf::POLIZA => true,
        LectorDocumentoPdf::ROTC   => true,
        LectorDocumentoPdf::RACDA  => true,
    ];

    // ── Apoyo de la IA ────────────────────────────────────────────────────────────

    /**
     * Le da el PDF entero a Gemini y rellena SOLO lo que falto. Lo que la lectura de siempre
     * ya saco no se toca: si el OCR dio una fecha, esa manda; la IA solo pone huecos.
     *
     * El resultado sigue siendo una PROPUESTA: la pantalla la marca como "revisar" y nada se
     * escribe en la ficha hasta que el usuario pulse Aplicar.
     *
     * @return array{0:?string,1:array,2:array,3:bool,4:?string}  [tipo, leido, equipos, ayudo, nota]
     */
    private function apoyarConIa(UploadedFile $archivo, string $tipoPedido, ?string $tipo, array $leido, array $equipos): array
    {
        $comoEstaba = [$tipo, $leido, $equipos, false, null];
        $visto = $this->vistoPorIa($archivo, $tipoPedido);
        if (!$visto) return $comoEstaba;

        // El tipo es el que se eligio: esOtroDocumento ya comprobo que el PDF lo sea.
        $tipoIa = $tipoPedido;

        $nuevo = $this->mezclarLoDeIa($tipoIa, $leido, $visto);
        $equiposIa = $this->equiposDeLoLeido($tipoIa, $nuevo);

        // Solo se acepta si sirvio de algo: enganchar el equipo, saber que documento es o
        // poner la fecha que faltaba. Si no aporto nada, se deja tal cual estaba.
        $ayudo = ($equiposIa && !$equipos)
            || (!$tipo && $tipoIa)
            || ($this->faltaVencimiento($tipoIa, $leido) && !$this->faltaVencimiento($tipoIa, $nuevo));
        if (!$ayudo) return $comoEstaba;

        // Lo que la IA dice que NO pudo leer bien, tal cual, para que se mire justo eso.
        $nota = !$visto['seguro']
            ? trim('El escaneo no deja leerlo con seguridad. ' . ($visto['nota'] ?? ''))
            : $visto['nota'];

        return [$tipoIa, $nuevo, $equiposIa, true, $nota ?: null];
    }

    /**
     * Lo que dice la IA del PDF, preguntado UNA sola vez por archivo (ver $vistoIa). Se le dice
     * que documento se esta buscando: si el archivo trae varios (un titulo y una poliza), lee
     * los datos de ESE. Null si no hay IA, no hay cupo o no contesto.
     */
    private function vistoPorIa(UploadedFile $archivo, string $tipoPedido): ?array
    {
        if ($this->vistoIa !== false) return $this->vistoIa;
        if (!$this->ia->disponible()) return $this->vistoIa = null;

        $pdf = (string) @file_get_contents($archivo->getRealPath());
        // Un solo intento: esto corre dentro de una peticion del navegador, que tiene su
        // propio tope de tiempo (ver CargaMasivaDocumentosController). Reintentar aqui
        // acabaria en un "error de red" con el archivo ya subido.
        return $this->vistoIa = ($pdf === '' ? null : $this->ia->leer($pdf, 1, $tipoPedido));
    }

    /** Lo de la IA debajo de lo del OCR: rellena huecos, nunca pisa lo ya leido. */
    private function mezclarLoDeIa(string $tipo, array $leido, array $visto): array
    {
        foreach (['placa', 'serial', 'titular', 'nro', 'emision', 'vence'] as $campo) {
            if (empty($leido[$campo]) && !empty($visto[$campo])) $leido[$campo] = $visto[$campo];
        }

        // Los que amparan varios (providencia RACDA, poliza o ROTC de flota) vienen en lista.
        // El serial de MOTOR no entra: 'seriales' son los de CARROCERIA y es contra esa columna
        // contra la que se busca el equipo (buscarPorSerial).
        $placas   = array_filter(array_column($visto['vehiculos'], 'placa'));
        $seriales = array_filter(array_column($visto['vehiculos'], 'serial'));

        $leido['placas']   = array_values(array_unique(array_merge($leido['placas'] ?? [], $placas)));
        $leido['seriales'] = array_values(array_unique(array_merge($leido['seriales'] ?? [], $seriales)));

        if ($tipo === LectorDocumentoPdf::POLIZA && empty($leido['aseguradora']) && !empty($visto['aseguradora'])) {
            $catalogo = $this->catalogoAseguradoras();
            $id = $this->lector->aseguradoraEnTexto($visto['aseguradora'], $catalogo);
            $leido['aseguradora'] = $id ? $catalogo[$id] : null;
        }

        return $leido;
    }

    // ── Documento de embarque (BL) ────────────────────────────────────────────────

    /**
     * La propuesta de un BL: todos los equipos de su anexo, reconocidos SOLO por el VIN (serial
     * de chasis: la placa no existia cuando se embarcaron). Los VIN que no estan en el sistema
     * se nombran, porque es justo lo que se busca al revisar un embarque. Si el PDF no trae
     * texto (escaneado), la IA lee la lista.
     */
    private function propuestaDeEmbarque(UploadedFile $archivo, string $nombre, string $link, ?string $md5, ?string $driveId, string $texto): array
    {
        $bl = BillOfLading::leer($texto);
        $conIa = false;
        if (!$bl['vins'] && !$bl['vins_partidos'] && ($visto = $this->vistoPorIa($archivo, self::EMBARQUE))) {
            $bl['vins'] = array_values(array_unique(array_filter(array_column($visto['vehiculos'], 'serial'))));
            $bl['nro'] ??= $visto['nro'];
            $bl['fecha'] ??= $visto['emision'];
            $conIa = (bool) $bl['vins'];
        }

        // Ademas de los VIN, cualquier codigo de la hoja que sea EXACTAMENTE el serial de un
        // equipo: las maquinas (LOVOL, SHANTUI) no siempre traen 17 caracteres. De cada uno se
        // guarda como lo IMPRIME el BL (comparado, "LZZWADG46STS01046" es "...5TS01046").
        $impreso = [];
        foreach (array_merge($bl['vins'], $bl['vins_partidos'], $texto !== '' ? $this->codigosEnTexto($texto) : []) as $v) {
            $impreso[$this->lector->codigo($v)] ??= $v;
        }
        $buscar = array_keys(array_filter($impreso, fn ($v, $c) => $c !== '', ARRAY_FILTER_USE_BOTH));
        $filas = $buscar
            ? $this->consulta()->whereIn(DB::raw(self::sqlCodigo('e.SERIAL_CHASIS')), $buscar)
                ->orderBy('e.ID_EQUIPO')->limit(self::TOPE_EMBARQUE + 1)->get()
            : collect();
        if ($filas->count() > self::TOPE_EMBARQUE) {
            $filas = $filas->take(self::TOPE_EMBARQUE);
            $this->avisoFichas = 'El BL nombra mas de ' . self::TOPE_EMBARQUE . ' unidades registradas: se proponen ' . self::TOPE_EMBARQUE . '.';
        }

        $enSistema = $filas->map(fn ($f) => $this->lector->codigo((string) $f->SERIAL_CHASIS))->flip();
        $noEstan = array_values(array_filter($bl['vins'], fn ($v) => !isset($enSistema[$this->lector->codigo($v)])));
        // Las que nombra el BL: las registradas (por VIN, VIN partido o serial de maquina) mas
        // los VIN enteros que no estan en el sistema.
        $unidades = $filas->count() + count($noEstan);

        $fichas = $filas->map(fn ($f) => ['coincide_por' => 'el VIN ' . $f->SERIAL_CHASIS] + $this->ficha($f)
            + ['vin_bl' => $impreso[$this->lector->codigo((string) $f->SERIAL_CHASIS)] ?? $f->SERIAL_CHASIS])->values()->all();
        $rotulo = 'BL ' . ($bl['nro'] ?? 'sin numero') . ($bl['buque'] ? ' (' . $bl['buque'] . ')' : '');

        $propuesta = [
            'archivo' => $nombre, 'link' => $link, 'tipo' => self::EMBARQUE,
            'tipo_nombre' => self::NOMBRES[self::EMBARQUE],
            'vence' => null, 'emision' => $bl['fecha'], 'titular' => null, 'nro' => $bl['nro'], 'aseguradora' => null,
            'equipos' => $fichas, 'estado' => 'listo', 'aviso' => null, 'ia' => $conIa, 'md5' => $md5,
            // Lo que aplicarEmbarque escribe en la tabla embarques (ver datosDeEmbarque).
            'embarque' => [
                'nro' => $bl['nro'], 'buque' => $bl['buque'],
                'puerto_carga' => $bl['puerto_carga'], 'puerto_descarga' => $bl['puerto_descarga'],
                'fecha' => $bl['fecha'], 'unidades' => $unidades, 'no_registrados' => $noEstan,
            ],
        ];

        if (!$fichas) {
            $propuesta['estado'] = 'sin_equipo';
            $propuesta['aviso'] = $unidades
                ? "$rotulo: ninguna de sus $unidades unidades esta registrada."
                : "$rotulo: no se leyo ningun VIN.";
            return $propuesta;
        }

        $propuesta['aviso'] = "$rotulo: $unidades unidades, " . count($fichas) . ' registradas.'
            . ($noEstan ? ' No estan en el sistema: ' . implode(', ', array_slice($noEstan, 0, 5))
                . (count($noEstan) > 5 ? ' y ' . (count($noEstan) - 5) . ' mas' : '') . '.' : '');

        $avisos = array_filter([
            !$bl['nro'] ? 'No se leyo el numero de BL.' : null,
            $conIa ? 'La lista de VIN la leyo la IA: compruebala antes de aplicar.' : null,
            $this->avisoTipo, $this->avisoFichas, $this->yaSeSolto($md5, $driveId),
        ]);
        if ($avisos) {
            $propuesta['estado'] = 'revisar';
            $propuesta['aviso'] = implode(' ', $avisos) . ' ' . $propuesta['aviso'];
        }
        return $propuesta;
    }

    /** Los datos del BL que se anotaron al analizar su PDF (propuesta['embarque']), o null. */
    private function datosDeEmbarque(string $link): ?array
    {
        $p = $this->propuestaGuardada($link);
        if (($p['tipo'] ?? null) !== self::EMBARQUE || empty($p['embarque'])) return null;

        return $p['embarque'] + ['archivo' => $p['archivo'] ?? null,
            'vins' => array_column($p['equipos'] ?? [], 'vin_bl', 'id')];
    }

    /**
     * Enlaza UN equipo a su embarque. Un BL es de todos sus equipos, asi que las puertas son
     * las del documento compartido (como el RACDA), en este orden:
     *
     *   · El embarque es el de ese numero de BL (o, sin numero, el de ese mismo PDF). Si ya
     *     existe con OTRO PDF, cambiarlo se lo cambia a todos sus equipos: hace falta $pisar.
     *   · Un equipo llega en UN embarque: si ya esta en otro, moverlo tambien pide $pisar.
     *   · Ya enlazado a este mismo BL y PDF: no hay nada que hacer.
     *
     * Como aplicarEnEquipo: $ensayo dice que haria sin escribir, y $cerrar pasa la fila de la
     * tabla a "Aplicado" (con la ultima ficha del BL).
     */
    private function aplicarEmbarque(int $idEquipo, string $link, bool $pisar, bool $ensayo, bool $cerrar): array
    {
        $bl = $this->datosDeEmbarque($link);
        if (!$bl) return ['ok' => false, 'mensaje' => 'No estan los datos de ese BL. Vuelve a soltar el PDF.'];

        $equipo = Equipo::find($idEquipo);
        if (!$equipo) return ['ok' => false, 'mensaje' => 'El equipo ya no existe.'];

        $driveId = DocumentoAnexo::driveIdDeLink($link);
        // Por su numero o, si no, por su PDF: el numero se puede corregir a mano en el visor
        // (EquipoController::guardarDatosEmbarque) y la propuesta guarda el que se leyo.
        $embarque = ($bl['nro'] ? Embarque::where('NRO_BL', $bl['nro'])->first() : null)
            ?? Embarque::where('LINK', 'like', '/storage/google/' . $driveId . '%')->first();
        $nombreBl = 'BL ' . ($bl['nro'] ?? 'sin numero');

        $pdfDistinto = $embarque && !$this->mismoArchivo($embarque->LINK, $link);
        if ($pdfDistinto && !$pisar) {
            return ['ok' => false, 'requiere_pisar' => true,
                    'mensaje' => "El $nombreBl ya tiene otro PDF cargado: reemplazarlo se lo cambia a todos sus equipos."];
        }

        $actual = DB::table('embarque_equipo')->where('ID_EQUIPO', $idEquipo)->first();
        $enEste = $actual && $embarque && (int) $actual->ID_EMBARQUE === (int) $embarque->ID_EMBARQUE;
        if ($enEste && !$pdfDistinto) {
            if (!$ensayo && $cerrar) $this->cerrarPropuesta($link);
            return ['ok' => true, 'mensaje' => 'Ya estaba enlazado.'];
        }
        if ($actual && !$enEste && !$pisar) {
            $otro = Embarque::find($actual->ID_EMBARQUE);
            return ['ok' => false, 'requiere_pisar' => true,
                    'mensaje' => 'Este equipo ya esta en el embarque BL ' . ($otro?->NRO_BL ?? 'sin numero') . '.'];
        }

        if ($ensayo) {
            return ['ok' => true, 'ensayo' => true, 'mensaje' => 'ENSAYO — '
                . ($actual && !$enEste ? 'lo sacaria de su embarque y ' : '')
                . ($enEste ? 'ya esta en el ' . $nombreBl : 'lo enlazaria al ' . $nombreBl)
                . ($pdfDistinto ? ', cambiandole el PDF al BL' : '') . '. No se escribio nada.'];
        }

        $anterior = $pdfDistinto ? $embarque->LINK : null;
        DB::transaction(function () use (&$embarque, $bl, $link, $idEquipo, $actual, $enEste, $pdfDistinto) {
            // El BL se escribe al crearlo o al cambiarle el PDF; las demas unidades solo se enlazan.
            if (!$embarque || $pdfDistinto) {
                $datos = [
                    'NRO_BL' => $bl['nro'], 'BUQUE' => $bl['buque'],
                    'PUERTO_CARGA' => $bl['puerto_carga'], 'PUERTO_DESCARGA' => $bl['puerto_descarga'],
                    'FECHA_EMBARQUE' => $bl['fecha'], 'LINK' => $link, 'ARCHIVO' => $bl['archivo'],
                    'UNIDADES' => $bl['unidades'], 'SUBIDO_POR' => auth()->user()->ID_USUARIO,
                ];
                $embarque ? $embarque->update($datos) : ($embarque = Embarque::create($datos));
            }

            if (!$enEste) {
                if ($actual) DB::table('embarque_equipo')->where('ID_EQUIPO', $idEquipo)->delete();
                DB::table('embarque_equipo')->insert([
                    'ID_EMBARQUE' => $embarque->ID_EMBARQUE, 'ID_EQUIPO' => $idEquipo,
                    'VIN' => $bl['vins'][$idEquipo] ?? null, 'ASOCIADO_POR' => auth()->user()->ID_USUARIO,
                    'created_at' => now(),
                ]);
            }
        });

        // El PDF viejo del BL, si se le cambio, ya no lo usa nadie (el job lo vuelve a comprobar).
        if ($anterior) $this->retirarReemplazado($anterior, $link, ['embarque' => $embarque->ID_EMBARQUE]);

        EquipoAuditLog::registrar($idEquipo, 'upload_' . self::EMBARQUE, [
            'archivo' => basename($link), 'bl' => $bl['nro'], 'origen' => 'carga masiva',
        ]);

        if ($cerrar) $this->cerrarPropuesta($link);
        return ['ok' => true, 'mensaje' => 'Aplicado.'];
    }

    // ── ¿Es el documento que se eligio? ───────────────────────────────────────────

    /** Estado de la propuesta cuyo PDF no es el documento que se eligio. */
    public const OTRO_DOCUMENTO = 'otro_documento';

    /**
     * Si el PDF NO es el documento que se eligio, que es: ['es' => tipo o null (ninguno de los
     * cuatro), 'por_ia' => bool, 'nota' => ?string]. Null si lo es o si no hay forma de saberlo.
     *
     * Primero el rotulo del propio texto (detectarTipo, gratis). Si no cuadra con lo elegido, o
     * no hay rotulo (escaneado, formato raro), decide la IA, que mira el PDF entero: la regla
     * del rotulo se puede equivocar con un encabezado mal escaneado y no se rechaza un titulo
     * bueno solo por eso (pero esa propuesta sale para revisar). Sin IA, manda el rotulo; y sin
     * rotulo tampoco, se da por bueno lo que dijo el usuario, tambien para revisar ($avisoTipo).
     *
     * El certificado y la compraventa no tienen rotulo que buscar: se toman como se eligieron.
     */
    private function esOtroDocumento(UploadedFile $archivo, string $pedido, string $texto): ?array
    {
        if (!isset(self::ROTULOS[$pedido])) return null;

        $porTexto = $texto !== '' ? $this->detectarTipo($texto) : null;
        if ($porTexto === $pedido) return null;

        $visto = $this->vistoPorIa($archivo, $pedido);
        if ($visto && $visto['tipo'] === $pedido) {
            // El rotulo decia otra cosa y la IA dice que si: vale lo elegido, pero con la
            // contradiccion a la vista para que una persona lo mire antes de aplicar.
            if ($porTexto) {
                $this->avisoTipo = 'El encabezado parece ' . mb_strtolower(self::NOMBRES[$porTexto]) . ', pero la IA confirma que es '
                    . mb_strtolower(self::NOMBRES[$pedido]) . ': compruebalo antes de aplicar.';
            }
            return null;
        }
        // La IA dijo que es otro de los cuatro, o que no es ninguno ("otro").
        if ($visto && ($visto['tipo'] || !empty($visto['otro']))) {
            return ['es' => $visto['tipo'], 'por_ia' => true, 'nota' => $visto['nota'] ?? null];
        }
        if ($porTexto) {
            return ['es' => $porTexto, 'por_ia' => false, 'nota' => null];
        }

        // Ni rotulo ni IA que lo confirme: se sigue con lo que dijo el usuario, pero avisando.
        // Si ademas no hay texto, la propuesta ya sale "no se pudo leer" por su cuenta.
        if ($texto !== '') {
            $this->avisoTipo = 'No se pudo confirmar que el PDF sea ' . mb_strtolower(self::NOMBRES[$pedido]) . ': compruebalo antes de aplicar.';
        }
        return null;
    }

    /**
     * La propuesta de un PDF que no es lo que se eligio: sin equipos (no se puede aplicar) y con
     * el porque, que es lo que se lee en la tabla. El archivo se queda en Drive hasta que alguien
     * lo descarte, igual que un "Sin ficha reconocida".
     */
    private function propuestaDeOtroDocumento(string $nombre, string $link, ?string $md5, string $pedido, array $otro): array
    {
        $es = $otro['es']
            ? 'es ' . mb_strtolower(self::NOMBRES[$otro['es']])
            : 'no es titulo, poliza, ROTC, RACDA ni BL';
        $aviso = 'No se asocio: se cargo como ' . mb_strtolower(self::NOMBRES[$pedido]) . ' pero el PDF ' . $es
            . ($otro['por_ia'] ? ' (segun la IA)' : '') . '. Descartalo o subelo con el tipo correcto.'
            . (!empty($otro['nota']) ? ' ' . $otro['nota'] : '');

        return [
            'archivo' => $nombre, 'link' => $link,
            // El tipo que se ELIGIO: la fila sale al filtrar por el documento que se estaba cargando.
            'tipo' => $pedido, 'tipo_nombre' => self::NOMBRES[$pedido],
            'es_realmente' => $otro['es'],
            'vence' => null, 'emision' => null, 'titular' => null, 'nro' => null, 'aseguradora' => null,
            'equipos' => [], 'estado' => self::OTRO_DOCUMENTO, 'aviso' => $aviso,
            'ia' => $otro['por_ia'], 'md5' => $md5,
        ];
    }

    // ── Reconocer que documento es ────────────────────────────────────────────────

    /**
     * El rotulo con el que cada documento se presenta. El ORDEN importa solo para desempatar:
     * los rotulos se solapan (una providencia RACDA nombra polizas y vehiculos, y un ROTC trae
     * "Fecha de Vencimiento" igual que una poliza), y en un empate manda el mas especifico.
     */
    private const ROTULOS = [
        LectorDocumentoPdf::RACDA     => '/PROVIDENCIA ADMINISTRATIVA|RACDA|REGISTRO NACIONAL DE TRANSPORTE TERRESTRE/u',
        // "Certificado de circulacion" SOLO no basta: el titulo de propiedad del INTT lo lleva en
        // la colilla de abajo, y un titulo escaneado cuyo encabezado no se leyo bien salia como
        // ROTC (visto con un titulo real, 25-09-2026). El del ROTC dice "... DE VEHICULO DE CARGA".
        LectorDocumentoPdf::ROTC      => '/\bROTC\b|CERTIFICADO DE CIRCULACI[OÓ]N DE VEH[IÍ]CULO DE CARGA/u',
        LectorDocumentoPdf::POLIZA    => '/P[OÓ]LIZA|POLIZA|CUADRO RECIBO|ASEGURAD/u',
        // "CERTIFICADO DE REGISTRO" a secas: es lo que dice la colilla del titulo ("CERTIFICADO
        // DE REGISTRO DE VEHICULO" partido en dos lineas), y a veces lo unico legible de un
        // titulo escaneado.
        LectorDocumentoPdf::PROPIEDAD => '/CERTIFICADO DE REGISTRO|T[IÍ]TULO DE PROPIEDAD/u',
        // El formulario CONGENBILL se anuncia "BILL OF LADING B/L NO. HCLKGT03".
        self::EMBARQUE                => '/BILL OF LADING|\bB\/L NO\b|CONOCIMIENTO DE EMBARQUE/u',
        // El certificado asociado y la compraventa no tienen rotulo: todavia no hay ejemplos
        // reales de sus formatos. Se toman como el tipo que se elige en el modal.
    ];

    /**
     * Pistas FLOJAS: dicen quien emitio el papel, no que papel es. Solo valen cuando ningun
     * rotulo de arriba aparecio, porque no pueden competir por posicion: el ROTC y la
     * providencia RACDA los emite tambien el INTT y lo ponen en su membrete, o sea ANTES que
     * su propio rotulo. Si compitieran, un ROTC con "INTT" en la cabecera se repartiria como
     * titulo de propiedad — el mismo fallo que se arreglo, pero al reves.
     */
    private const PISTAS = [
        LectorDocumentoPdf::PROPIEDAD => '/\bINTT\b/u',
    ];

    /**
     * De que tipo es el PDF, por lo que dice de si mismo (con esto se comprueba que sea el que se
     * eligio, ver esOtroDocumento). Gana el rotulo que aparece ANTES en el texto, porque un
     * documento se anuncia en su encabezado y lo de despues son menciones.
     *
     * Por que no vale mirarlos en un orden fijo (visto en la prueba real del 23-09-2026): el
     * titulo de propiedad del INTT se presenta en el caracter 3 ("Certificado de Registro de
     * Vehiculo") pero en su letra pequeña, por el caracter 2.879, nombra el "certificado de
     * circulacion". Con el orden fijo ganaba esa mencion y DOS titulos de propiedad se
     * repartian como si fueran ROTC — y aplicarlos habria metido el titulo en la casilla del
     * ROTC y tapado el ROTC bueno.
     */
    public function detectarTipo(string $texto): ?string
    {
        $t = preg_replace('/\s+/u', ' ', mb_strtoupper($texto, 'UTF-8'));

        $mejor = null;
        $donde = PHP_INT_MAX;
        foreach (self::ROTULOS as $tipo => $re) {
            if (!preg_match($re, $t, $m, PREG_OFFSET_CAPTURE)) continue;
            // Estricto: en un empate se queda el primero de ROTULOS, que es el mas especifico.
            if ($m[0][1] < $donde) {
                $donde = $m[0][1];
                $mejor = $tipo;
            }
        }
        if ($mejor) return $mejor;

        // Ningun documento se anuncio: se mira quien lo emite (ver PISTAS).
        foreach (self::PISTAS as $tipo => $re) {
            if (preg_match($re, $t)) return $tipo;
        }

        return null;
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

        // PRIMERO lo que el documento dice que es SUYO: el serial y la placa que van detras de
        // su rotulo (el certificado de un ROTC de flota, "Placa: ..." en un titulo). Solo si
        // no dan con nadie se mira todo lo que aparece en la hoja. Con un ROTC real (25-09-2026)
        // la hoja nombra 17 unidades de la flota y el certificado es de UNA: mirando todo a la
        // vez, se proponia la que saliera primero y se avisaba de "varias unidades".
        $filas = collect();
        $porQue = null;
        foreach ([[$leido['serial'] ?? null], [$leido['placa'] ?? null], $seriales, $placas] as $i => $lista) {
            $lista = array_values(array_filter($lista));
            if (!$lista) continue;
            $filas = $i % 2 === 0 ? $this->buscarPorSerial($lista) : $this->filasPorPlaca($lista, 5);
            if ($filas->isNotEmpty()) {
                $porQue = $i % 2 === 0 ? 'el serial ' . $filas->first()->SERIAL_CHASIS : 'la placa ' . $filas->first()->PLACA;
                $propio = $i < 2;
                break;
            }
        }
        $fila = $filas->first();
        if (!$fila) return [];

        // Una poliza o un ROTC de FLOTA nombran varias unidades: se propone una (la de ID mas
        // bajo, siempre la misma) y se avisa, en vez de elegir una al azar sin decirlo. En un
        // ROTC el vencimiento es el de SU fila de la tabla (filaRotc, en proponer); el ROTC
        // entero de la flota ni llega aqui (propuestaDeFlota).
        if (!$propio && ($filas->count() > 1 || !empty($leido['flota']))) {
            $this->avisoFichas = 'El documento nombra ' . ($filas->count() > 1 ? 'varias unidades registradas' : 'varias unidades')
                . ': se propone ' . trim(($fila->MARCA ?? '') . ' ' . ($fila->MODELO ?? '')) . ($fila->PLACA ? ' (' . $fila->PLACA . ')' : '')
                . '. Comprueba que sea la correcta.';
        }

        // POR QUE se eligio esa ficha. Es lo primero que necesita saber quien mira la tabla
        // ("¿y como se que es de ESE equipo?"): el dato impreso en el PDF que cuadro EXACTO
        // con la ficha. Sin el, la propuesta es un acto de fe.
        return [['coincide_por' => $porQue] + $this->ficha($fila)];
    }

    /**
     * El AUXILIAR al que pertenece el documento. Solo por SERIAL: un auxiliar no tiene placa,
     * asi que es lo unico con lo que se le puede reconocer. Devuelve una lista de 0 o 1 para
     * que quien lo use no tenga que distinguir este caso del de los equipos.
     */
    private function auxiliarDelDocumento(array $leido): array
    {
        // Ademas de los seriales "de chasis", CUALQUIER codigo de la hoja (codigosEnTexto): el
        // serial de una soldadora o una planta suele ser corto ("S/N U1180512345") y no va
        // detras de un rotulo de carroceria, asi que el lector de siempre no lo veia. Se exige
        // que el codigo sea EXACTAMENTE el serial de un auxiliar, no un parecido.
        $seriales = array_values(array_unique(array_map('strtoupper', array_filter(array_merge(
            [$leido['serial'] ?? null],
            $leido['seriales'] ?? [],
            $leido['codigos'] ?? []
        )))));
        if (!$seriales) return [];

        $filas = EquipoAuxiliar::whereNull('deleted_at')
            ->whereIn(DB::raw(self::sqlCodigo('SERIAL')), $this->codigos($seriales))
            ->orderBy('ID_AUXILIAR')->limit(5)
            ->get(['ID_AUXILIAR', 'SERIAL', 'MARCA', 'MODELO',
                   'LINK_DOC_PROPIEDAD', 'LINK_CERTIFICADO', 'FECHA_VENCIMIENTO_CERT']);
        $fila = $filas->first();
        if (!$fila) return [];
        if ($filas->count() > 1) {
            $this->avisoFichas = 'El documento nombra varios auxiliares registrados: se propone '
                . trim(($fila->MARCA ?? '') . ' ' . ($fila->MODELO ?? '')) . ' (' . $fila->SERIAL . '). Comprueba que sea el correcto.';
        }
        return [['coincide_por' => 'el serial ' . $fila->SERIAL] + $this->fichaAuxiliar($fila)];
    }

    /** Los equipos que nombra la lista de placas de una providencia RACDA. */
    private function equiposDelRacda(array $leido): array
    {
        $placas = $leido['placas'] ?? [];
        if (!$placas) return [];

        // Uno mas que el tope, para saber si se paso y DECIRLO (antes se cortaba en silencio).
        $filas = $this->filasPorPlaca($placas, self::TOPE_RACDA + 1);
        if ($filas->count() > self::TOPE_RACDA) {
            $filas = $filas->take(self::TOPE_RACDA);
            $this->avisoFichas = 'La providencia nombra mas de ' . self::TOPE_RACDA . ' unidades registradas: aqui se proponen '
                . self::TOPE_RACDA . '. Las demas las enlaza la revision de la noche.';
        }

        // Cada ficha lleva POR QUE se la eligio: su propia placa, la que la providencia nombra.
        return array_map(
            fn ($f) => ['coincide_por' => 'la placa ' . $f['placa']] + $f,
            $this->fichas($filas)
        );
    }

    /** Hasta 5 equipos con alguno de esos seriales, siempre en el mismo orden. */
    private function buscarPorSerial(array $seriales)
    {
        return $this->consulta()
            ->whereIn(DB::raw(self::sqlCodigo('e.SERIAL_CHASIS')), $this->codigos($seriales))
            ->orderBy('e.ID_EQUIPO')->limit(5)
            ->get();
    }

    private function filasPorPlaca(array $placas, int $tope)
    {
        // La placa se compara sin guiones ni espacios ("A50AB1D" en la ficha, "A50-AB1D" en el
        // PDF) y con O/I/S como 0/1/5, igual que el lector (codigo()).
        $limpias = $this->codigos($placas);
        if (!$limpias) return collect();

        return $this->consulta()
            ->whereIn(DB::raw(self::sqlCodigo('d.PLACA')), $limpias)
            // Siempre el mismo orden: sin el, el tope (y "la primera") salian al azar.
            ->orderBy('e.ID_EQUIPO')
            ->limit($tope)
            ->get();
    }

    /**
     * Placas o seriales como los compara el lector (LectorDocumentoPdf::codigo): sin guiones
     * ni espacios y con O, I, S como 0, 1, 5. El escaneo confunde esas letras con las cifras
     * (una poliza real escaneada, 25-09-2026, traia "8XVC508SODDLD2694" por "...S0DDLD2694").
     */
    private function codigos(array $valores): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($v) => $this->lector->codigo((string) $v), $valores))));
    }

    /**
     * Lo mismo que LectorDocumentoPdf::codigo(), en SQL, sobre la columna de la ficha.
     *
     * Tiene que hacer EXACTAMENTE lo mismo o la carga masiva no encuentra equipos que el
     * verificador nocturno sí reconoce. Pasaba: no traducía las letras de otro alfabeto que se ven
     * iguales que las nuestras (HOMOGLIFOS) y solo quitaba guion, espacio y punto, mientras
     * codigo() borra todo lo que no sea letra o número. Una ficha con una «Н» cirílica en la placa
     * —las hay— se leía bien de noche y en la carga masiva salía "no dice de qué equipo es"; igual
     * con cualquier barra o paréntesis en la placa.
     *
     * Los homóglifos salen de la MISMA constante que usa el PHP, no de una lista copiada aquí.
     */
    private static function sqlCodigo(string $columna): string
    {
        $sql = "UPPER($columna)";
        // Primero las letras de otro alfabeto a las nuestras, igual que hace codigo() antes de nada.
        foreach (LectorDocumentoPdf::HOMOGLIFOS as $de => $a) {
            $sql = "REPLACE($sql, " . self::comillas($de) . ", " . self::comillas($a) . ")";
        }
        // Fuera todo lo que no sea letra o número. Se hace con REPLACE y no con REGEXP_REPLACE
        // para no atarse a la versión del motor (no existe antes de MySQL 8.0.4), así que la lista
        // se GENERA: todo el ASCII imprimible que no es letra ni número, más los invisibles
        // (tabulador, saltos de línea y espacio duro), que son justo lo que arrastra un dato
        // pegado desde Excel o desde una web —la ficha se veía igual pero no casaba con nada—.
        // Generada y no escrita a mano porque cualquier signo que faltara en la lista era un
        // equipo que la carga masiva no encontraba y el verificador nocturno sí.
        // Lo único que sigue sin coincidir con codigo(): una LETRA de otro alfabeto que no esté en
        // HOMOGLIFOS (una Ñ, una vocal con tilde). No las hay en placas ni en seriales.
        // Los invisibles y la puntuación de fuera del ASCII van listados aparte porque el bucle de
        // abajo solo barre ASCII: son los que mete Word o una web al copiar —guion largo, comillas
        // curvas, espacios raros, marcas de ancho cero—, y un serial con uno de ellos se veía
        // idéntico en pantalla y no casaba con nada.
        $fuera = [
            "\t", "\n", "\r",
            "\u{00A0}", "\u{2007}", "\u{2009}", "\u{202F}",             // espacios que no se parten
            "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}",             // ancho cero y marca de orden
            "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}",  // guiones
            "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}",             // comillas curvas
        ];
        for ($i = 32; $i < 127; $i++) {
            $ch = chr($i);
            if (! ctype_alnum($ch)) {
                $fuera[] = $ch;
            }
        }
        foreach ($fuera as $ch) {
            $sql = "REPLACE($sql, " . self::comillas($ch) . ", '')";
        }
        // Y las tres parejas que el escaneo confunde siempre.
        foreach (['O' => '0', 'I' => '1', 'S' => '5'] as $de => $a) {
            $sql = "REPLACE($sql, " . self::comillas($de) . ", " . self::comillas($a) . ")";
        }
        return $sql;
    }

    /**
     * Un literal de texto para SQL. Escapa la comilla simple y, sobre todo, la BARRA INVERTIDA:
     * en MySQL es carácter de escape, así que '\' deja la cadena abierta y rompe la consulta
     * entera (pasó al añadir la barra a los separadores que se quitan).
     *
     * SOLO para las constantes de sqlCodigo(), nunca para nada que venga del usuario: escapar a
     * mano depende de que el servidor NO tenga el modo NO_BACKSLASH_ESCAPES (con él, '\\' serían
     * dos barras de verdad). Para un dato de fuera van los parámetros ligados de siempre.
     */
    private static function comillas(string $v): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "''"], $v) . "'";
    }

    /**
     * Base comun: el equipo vivo con su documentacion y su embarque. Parte de EQUIPOS y no de
     * documentacion: 288 equipos no tienen fila de documentacion (30-09-2026) y, partiendo de
     * ella, ni su serial los encontraba. aplicarEnEquipo ya crea la fila si falta.
     */
    private function consulta()
    {
        return DB::table('equipos as e')
            ->leftJoin('documentacion as d', 'd.ID_EQUIPO', '=', 'e.ID_EQUIPO')
            ->whereNull('e.deleted_at')
            ->select([
                'e.ID_EQUIPO', 'd.PLACA', 'e.SERIAL_CHASIS', 'e.MODELO', 'e.MARCA',
                'd.LINK_DOC_PROPIEDAD', 'd.LINK_POLIZA_SEGURO', 'd.LINK_ROTC', 'd.LINK_RACDA',
                'd.LINK_DOC_ADICIONAL', 'd.LINK_DOC_ADICIONAL_2',
                'd.FECHA_VENC_POLIZA', 'd.FECHA_ROTC', 'd.FECHA_RACDA', 'd.FECHA_ADICIONAL',
                DB::raw('(SELECT ee.ID_EMBARQUE FROM embarque_equipo ee WHERE ee.ID_EQUIPO = e.ID_EQUIPO) as ID_EMBARQUE'),
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
            'id'       => (int) $f->ID_EQUIPO,
            'auxiliar' => false,
            'placa'    => $f->PLACA,
            'serial'   => $f->SERIAL_CHASIS,
            'nombre'   => trim(($f->MARCA ?? '') . ' ' . ($f->MODELO ?? '')) ?: ('Equipo #' . $f->ID_EQUIPO),
            'links'  => [
                LectorDocumentoPdf::PROPIEDAD => (bool) $f->LINK_DOC_PROPIEDAD,
                LectorDocumentoPdf::POLIZA    => (bool) $f->LINK_POLIZA_SEGURO,
                LectorDocumentoPdf::ROTC      => (bool) $f->LINK_ROTC,
                LectorDocumentoPdf::RACDA     => (bool) $f->LINK_RACDA,
                self::CERTIFICADO             => (bool) $f->LINK_DOC_ADICIONAL,
                self::COMPRAVENTA             => (bool) $f->LINK_DOC_ADICIONAL_2,
                self::EMBARQUE                => (bool) $f->ID_EMBARQUE,
            ],
            'vence_ficha' => [
                LectorDocumentoPdf::POLIZA => $this->soloFecha($f->FECHA_VENC_POLIZA),
                LectorDocumentoPdf::ROTC   => $this->soloFecha($f->FECHA_ROTC),
                LectorDocumentoPdf::RACDA  => $this->soloFecha($f->FECHA_RACDA),
                self::CERTIFICADO          => $this->soloFecha($f->FECHA_ADICIONAL),
            ],
        ];
    }

    /**
     * Lo mismo para un AUXILIAR. Solo admite dos documentos (ver EquipoAuxiliar::DOCS) y no
     * tiene placa: se reconoce por su serial, que es lo unico que trae su ficha.
     */
    private function fichaAuxiliar(object $a): array
    {
        return [
            'id'       => (int) $a->ID_AUXILIAR,
            'auxiliar' => true,
            'placa'    => null,
            'serial'   => $a->SERIAL,
            'nombre'   => trim(($a->MARCA ?? '') . ' ' . ($a->MODELO ?? '')) ?: ('Auxiliar #' . $a->ID_AUXILIAR),
            'links' => [
                LectorDocumentoPdf::PROPIEDAD => (bool) $a->LINK_DOC_PROPIEDAD,
                self::CERTIFICADO             => (bool) $a->LINK_CERTIFICADO,
            ],
            'vence_ficha' => [
                self::CERTIFICADO => $this->soloFecha($a->FECHA_VENCIMIENTO_CERT),
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
    public function aplicar(int $idEquipo, string $tipo, string $link, ?string $vence, ?string $emision, bool $pisar = false, bool $ensayo = false, bool $auxiliar = false, bool $cerrar = true): array
    {
        if (!in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'mensaje' => 'Tipo de documento no valido.'];
        }
        if ($tipo === self::EMBARQUE) {
            return $auxiliar
                ? ['ok' => false, 'mensaje' => 'Un equipo auxiliar no lleva documento de embarque.']
                : $this->aplicarEmbarque($idEquipo, $link, $pisar, $ensayo, $cerrar);
        }
        if ($auxiliar) {
            return $this->aplicarEnAuxiliar($idEquipo, $tipo, $link, $vence, $pisar, $ensayo, $cerrar);
        }
        // Un ROTC de flota: a este equipo le va SU parte, con SUS fechas (no las que mande la
        // pantalla, que son las de la hoja entera).
        if ($tipo === LectorDocumentoPdf::ROTC && ($parte = $this->parteDeFlota($link, $idEquipo))) {
            return $this->aplicarParteDeFlota($idEquipo, $link, $parte, $pisar, $ensayo, $cerrar);
        }
        return $this->aplicarEnEquipo($idEquipo, $tipo, $link, $vence, $emision, $pisar, $ensayo, $cerrar);
    }

    /** aplicar() en la ficha de un EQUIPO. */
    private function aplicarEnEquipo(int $idEquipo, string $tipo, string $link, ?string $vence, ?string $emision, bool $pisar, bool $ensayo, bool $cerrar): array
    {
        $equipo = Equipo::with('documentacion')->find($idEquipo);
        if (!$equipo) return ['ok' => false, 'mensaje' => 'El equipo ya no existe.'];

        $doc = $equipo->documentacion;
        $colLink = DocumentacionDeEquipo::COLUMNAS[$tipo]['link'];
        $colVence = DocumentacionDeEquipo::VENCIMIENTO[$tipo] ?? null;

        // 0) Ya tiene ESTE MISMO archivo (otra pestaña, o volver a pulsar Aplicar tras cortarse
        //    un RACDA a medias): no hay nada que hacer. Sin esto preguntaba "¿reemplazarlo?"
        //    y, confirmando, reescribia lo mismo y duplicaba el historial.
        $anterior = $doc?->$colLink;
        if ($this->mismoArchivo($anterior, $link)) {
            if (!$ensayo && $cerrar) $this->cerrarPropuesta($link);
            return ['ok' => true, 'mensaje' => 'Ya estaba enlazado.'];
        }

        // 1) Ya tiene uno: no se pisa sin permiso explicito.
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
        // La emision va SIEMPRE con el documento nuevo: si este no la trae, se vacia. Quedarse
        // con la del PDF reemplazado seria la fecha de otro papel (mismo criterio que deleteDoc).
        if (isset(DocumentacionDeEquipo::EMISION[$tipo])) {
            $datos[DocumentacionDeEquipo::EMISION[$tipo]] = $emision ?: null;
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
        $this->retirarReemplazado($anterior, $link, ['equipo' => $equipo->ID_EQUIPO, 'tipo' => $tipo]);

        EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'upload_' . $tipo, [
            'archivo' => basename($link),
            'origen'  => 'carga masiva',
        ]);
        if ($diff) EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'metadata_' . $tipo, $diff);

        if ($cerrar) $this->cerrarPropuesta($link);
        return ['ok' => true, 'mensaje' => 'Aplicado.'];
    }

    /**
     * El PDF que acaba de ser reemplazado sale de Drive (a la papelera, por el job, que ademas
     * comprueba que ninguna otra fila lo use). $borrar cambia COMO se retira; por defecto,
     * GoogleDriveService::borrarTrasResponder.
     *
     * EN LOCAL NO SE BORRA. El .env de desarrollo apunta al Drive REAL (las mismas credenciales
     * y carpetas que el servidor), mientras que la base de datos si es una copia. O sea:
     * reemplazar un documento desde el local no toca la ficha del servidor, pero SI borraria de
     * Drive el archivo al que apunta su enlace, y ese documento se perderia para todos. Se deja
     * el archivo huerfano, que no le hace daño a nadie, y queda en el log para poder limpiarlo a
     * mano si hiciera falta. (El job tambien lo comprueba —EnlacesDocumentos::esBaseDelServidor—;
     * aqui ni se llega a pedir.)
     */
    private function retirarReemplazado(?string $anterior, string $link, array $contexto, ?callable $borrar = null): void
    {
        if (!$anterior || $anterior === $link) return;

        if (app()->environment('local')) {
            Log::info('Carga masiva en local: NO se borra de Drive el documento reemplazado', $contexto + ['enlace' => $anterior]);
            return;
        }
        if ($borrar) {
            $borrar($anterior);
        } elseif ($viejoId = DocumentoAnexo::driveIdDeLink($anterior)) {
            GoogleDriveService::borrarTrasResponder($viejoId);
        }
    }

    /**
     * Lo mismo, pero en la ficha de un AUXILIAR. Va aparte porque la ficha es otra tabla y
     * tiene mucho menos: solo dos documentos (EquipoAuxiliar::DOCS), el enlace y —en el
     * certificado— su vencimiento. No guarda quien lo subio ni cuando, ni fecha de emision.
     *
     * Las PUERTAS son exactamente las mismas que en un equipo, en el mismo orden: no se pisa
     * un documento que ya esta sin decirlo, no se retrocede un vencimiento ni con permiso, y
     * lo que vence no entra sin su fecha.
     */
    private function aplicarEnAuxiliar(int $idAuxiliar, string $tipo, string $link, ?string $vence, bool $pisar, bool $ensayo, bool $cerrar = true): array
    {
        $tipoAux = self::TIPOS_AUXILIAR[$tipo] ?? null;
        if (!$tipoAux) {
            return ['ok' => false, 'mensaje' => 'Un equipo auxiliar solo puede tener título de propiedad y certificado.'];
        }

        $aux = EquipoAuxiliar::whereNull('deleted_at')->find($idAuxiliar);
        if (!$aux) return ['ok' => false, 'mensaje' => 'El equipo auxiliar ya no existe.'];

        $colLink  = EquipoAuxiliar::DOCS[$tipoAux];
        $colVence = EquipoAuxiliar::DOCS_VENCE[$tipoAux] ?? null;

        $anterior = $aux->$colLink;
        if ($this->mismoArchivo($anterior, $link)) {
            if (!$ensayo && $cerrar) $this->cerrarPropuesta($link);
            return ['ok' => true, 'mensaje' => 'Ya estaba enlazado.'];
        }
        if ($anterior && !$pisar) {
            return ['ok' => false, 'requiere_pisar' => true,
                    'mensaje' => 'Este auxiliar ya tiene ese documento. Marca "reemplazar" si quieres cambiarlo.'];
        }
        if ($colVence && $vence) {
            $motivo = VerificacionDocumento::documentoAnterior($this->soloFecha($aux->$colVence), $vence);
            if ($motivo) return ['ok' => false, 'mensaje' => $motivo];
        }
        if ($colVence && !$vence) {
            return ['ok' => false, 'mensaje' => 'Falta la fecha de vencimiento.'];
        }

        $datos = [$colLink => $link];
        if ($colVence && $vence) $datos[$colVence] = $vence;

        $diff = [];
        foreach ($datos as $campo => $valor) {
            $antes = $campo === $colVence ? $this->soloFecha($aux->$campo) : $aux->$campo;
            if ((string) $antes !== (string) $valor) $diff[$campo] = ['antes' => $antes, 'despues' => $valor];
        }

        if ($ensayo) {
            return ['ok' => true, 'ensayo' => true, 'cambios' => $diff,
                    'mensaje' => 'ENSAYO — ' . ($anterior ? 'reemplazaría el PDF que ya tiene' : 'enlazaría el PDF')
                        . ($colVence && $vence ? ' y pondría el vencimiento ' . $vence : '') . '. No se escribió nada.'];
        }

        $aux->update($datos);

        // Como el formulario (olvidarDoc): tambien los antiguos del disco public, servidos sin sesion.
        $this->retirarReemplazado($anterior, $link, ['auxiliar' => $aux->ID_AUXILIAR, 'tipo' => $tipo],
            fn (string $viejo) => EquipoAuxiliar::olvidarDoc($viejo));

        if ($cerrar) $this->cerrarPropuesta($link);

        // AQUI NO SE AUDITA. El update() de arriba dispara EquipoAuxiliarObserver::updated, que
        // ya escribe la subida (aux_upload_propiedad / aux_upload_certificado) y el cambio de
        // fecha, con los nombres que el Control de Auditoria sabe rotular y filtrar. Escribir
        // aqui otra fila dejaria TRES apuntes del mismo acto, uno de ellos con un nombre de
        // accion que esa pantalla no conoce. En los equipos si se audita a mano porque su
        // observer (DocumentacionObserver) se abstiene a proposito de estos campos.
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
     *
     * Devuelve false —y no borra NADA— si ese PDF no es una propuesta sin aplicar de esta
     * pantalla, o si ya lo usa alguna ficha. Antes se fiaba del enlace que llegaba: con el de
     * un documento MONTADO (una pestaña vieja donde la fila aun salia sin aplicar, o la
     * peticion escrita a mano) mandaba a la papelera el documento bueno de un equipo.
     */
    public function descartar(?string $link): bool
    {
        if (!$id = DocumentoAnexo::driveIdDeLink($link)) return false;
        if (!$this->esPropuestaSinAplicar($link) || EnlacesDocumentos::sigueEnUso($id)) return false;

        // Su fila sale de la tabla de Revision de documentos: la propuesta ya no existe.
        VerificacionDocumento::where('DRIVE_ID', $id)
            ->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)->delete();

        GoogleDriveService::borrarTrasResponder($id);
        return true;
    }

    /**
     * El PDF de $link es una propuesta de esta pantalla que todavia no se enlazo a ninguna
     * ficha: es la puerta de descartar(). No se borra cualquier archivo de Drive cuyo enlace
     * alguien escriba (el documento montado de un equipo, por ejemplo).
     */
    private function esPropuestaSinAplicar(string $link): bool
    {
        if (!$id = DocumentoAnexo::driveIdDeLink($link)) return false;

        return VerificacionDocumento::where('DRIVE_ID', $id)
            ->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)
            ->whereIn('ESTADO', VerificacionDocumento::DE_LA_CARGA)
            ->exists();
    }

    /**
     * El PDF de $link es una propuesta de esta pantalla PARA ESA FICHA y ESE tipo: es la puerta
     * de aplicar(). Una propuesta sin aplicar, o ya aplicada pero que nombraba varias fichas
     * (un RACDA se enlaza a cada una por turno; tras la primera la fila ya dice "Aplicado").
     * Sin esto, con una peticion escrita a mano se podia enlazar un PDF de la carga a
     * cualquier equipo o como cualquier tipo de documento.
     */
    public function propuestaAdmite(string $link, int $id, bool $auxiliar, string $tipo): bool
    {
        if (!$driveId = DocumentoAnexo::driveIdDeLink($link)) return false;

        $fila = VerificacionDocumento::where('DRIVE_ID', $driveId)
            ->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)
            ->whereIn('ESTADO', [VerificacionDocumento::POR_ENGANCHAR, VerificacionDocumento::APLICADO])
            ->first(['PROPUESTA']);
        $p = $fila?->PROPUESTA;
        if (!$p || ($p['tipo'] ?? null) !== $tipo) return false;

        foreach ($p['equipos'] ?? [] as $f) {
            if ((int) ($f['id'] ?? 0) === $id && (bool) ($f['auxiliar'] ?? false) === $auxiliar) return true;
        }
        return false;
    }

    /** La placa o el serial del equipo aparecen en la hoja (ver LectorDocumentoPdf::codigo). */
    private function enLaHoja(array $ficha, array $leido): bool
    {
        $enHoja = $this->codigos(array_merge($leido['placas'] ?? [], $leido['seriales'] ?? []));
        return (bool) array_intersect($this->codigos(array_filter([$ficha['placa'] ?? null, $ficha['serial'] ?? null])), $enHoja);
    }

    /** Los dos enlaces son el mismo archivo de Drive (el enlace lleva a veces "?v=..."). */
    private function mismoArchivo(?string $a, ?string $b): bool
    {
        $idA = DocumentoAnexo::driveIdDeLink($a);
        return $idA !== null && $idA === DocumentoAnexo::driveIdDeLink($b);
    }

    /**
     * Si este mismo archivo (misma huella) ya se habia soltado antes y sigue en la tabla, lo
     * dice: dos filas del mismo PDF acaban en un "¿reemplazarlo?" que confunde. No lo impide:
     * puede ser a proposito (se descarto la otra, o era para otro equipo).
     */
    private function yaSeSolto(?string $md5, ?string $driveId): ?string
    {
        if (!$md5) return null;

        $otra = VerificacionDocumento::where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)
            ->where('PROPUESTA', 'like', '%"md5":"' . $md5 . '"%')
            ->when($driveId, fn ($q) => $q->where('DRIVE_ID', '<>', $driveId))
            ->orderByDesc('ID_REGISTRO')->first(['ARCHIVO', 'ESTADO', 'created_at']);
        if (!$otra) return null;

        return 'Este mismo archivo ya se habia soltado' . ($otra->created_at ? ' el ' . $otra->created_at->format('d/m/Y') : '')
            . ($otra->ESTADO === VerificacionDocumento::APLICADO ? ' y ya esta aplicado.' : ' y sigue en la tabla.');
    }

    /**
     * Los codigos que aparecen en la hoja: letras y numeros (con guiones), de 6 a 25
     * caracteres y con algun digito. Son los candidatos a serial de un auxiliar; solo cuentan
     * si coinciden EXACTO con uno registrado (ver auxiliarDelDocumento).
     */
    private function codigosEnTexto(string $texto): array
    {
        preg_match_all('/(?<![A-Z0-9])[A-Z0-9][A-Z0-9\-]{4,23}[A-Z0-9](?![A-Z0-9])/u', mb_strtoupper($texto, 'UTF-8'), $m);
        $codigos = array_filter(array_unique($m[0]), fn ($c) => preg_match('/\d/', $c));
        return array_slice(array_values($codigos), 0, 300);
    }
}
