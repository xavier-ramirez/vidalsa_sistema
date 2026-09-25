<?php

namespace App\Services;

use App\Models\Documentacion;
use App\Models\DocumentoAnexo;
use App\Models\Equipo;
use App\Models\EquipoAuditLog;
use App\Models\EquipoAuxiliar;
use App\Models\VerificacionDocumento;
use App\Support\DocumentacionDeEquipo;
use App\Support\EnlacesDocumentos;
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
    /** El "Certificado asociado" y la "Compraventa" de la ficha (LINK_DOC_ADICIONAL y _2). */
    public const CERTIFICADO = 'adicional';
    public const COMPRAVENTA = 'adicional_2';

    /**
     * Los 6 documentos que esta pantalla sabe repartir: los mismos que la ficha del equipo.
     * El certificado y la compraventa NO se reconocen tan bien solos como los otros cuatro
     * (no traen un rotulo tan claro): para esos conviene elegir el tipo arriba.
     */
    public const TIPOS = [
        LectorDocumentoPdf::PROPIEDAD,
        LectorDocumentoPdf::POLIZA,
        LectorDocumentoPdf::ROTC,
        LectorDocumentoPdf::RACDA,
        self::CERTIFICADO,
        self::COMPRAVENTA,
    ];

    /** Como se llama cada tipo en pantalla (los mismos rotulos que la ficha). */
    public const NOMBRES = [
        LectorDocumentoPdf::PROPIEDAD => 'Titulo de propiedad',
        LectorDocumentoPdf::POLIZA    => 'Poliza de seguro',
        LectorDocumentoPdf::ROTC      => 'ROTC',
        LectorDocumentoPdf::RACDA     => 'RACDA',
        self::CERTIFICADO             => 'Certificado asociado',
        self::COMPRAVENTA             => 'Compraventa',
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
    ];

    /**
     * Un RACDA es de la EMPRESA y nombra muchas unidades; enlazarlo a cientos de fichas de
     * un golpe desde aqui seria una operacion enorme detras de un solo clic. Se propone
     * hasta este tope y, si hay mas, se avisa: para la flota entera esta la revision
     * nocturna, que ya lo reparte.
     */
    private const TOPE_RACDA = 40;

    /** Lo que cabe en verificacion_documento_registro.MOTIVO (varchar 255). */
    private const MOTIVO_MAX = 255;

    /**
     * Lo que la busqueda de fichas tiene que contar en la propuesta: que el documento nombra
     * varias unidades y se propuso solo una, que el RACDA paso del tope... Lo llenan
     * equipoDelDocumento / auxiliarDelDocumento / equiposDelRacda y lo lee analizar(), que lo
     * vacia al empezar cada archivo.
     */
    private ?string $avisoFichas = null;

    public function __construct(private LectorDocumentoPdf $lector, private LectorGemini $ia) {}

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
        $this->avisoFichas = null;
        // La huella del archivo: con ella se sabe si ESTE MISMO PDF ya se habia soltado antes
        // (ver yaSeSolto). Se saca antes de subirlo: subirPdf puede mover el temporal.
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

        // El motivo por el que la lectura de siempre no llego a nada: es el que se le enseña al
        // usuario si la IA tampoco lo resuelve (o no esta puesta). Los mismos mensajes de
        // siempre, en el mismo orden: primero "no se pudo leer", luego "esta en blanco" y por
        // ultimo "no se reconoce que documento es".
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

        $tipo = $texto !== '' ? ($tipoPedido ?: $this->detectarTipo($texto)) : null;
        if ($texto !== '' && !$tipo) {
            $motivo = 'No se reconoce que documento es. Elige el tipo arriba y vuelve a subirlo.';
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

        // Sin tipo no hay nada que proponer: se devuelve el motivo de la lectura de siempre,
        // porque es el que explica que paso con el archivo.
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
        $avisos = array_filter([$this->avisoFichas, $this->yaSeSolto($md5, $driveId)]);
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
     * Si falla al anotarla no se rompe la subida —el PDF ya esta en Drive y la pantalla ya
     * tiene su respuesta—, pero queda en el log.
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
                    'ESTADO'      => $ficha ? VerificacionDocumento::POR_ENGANCHAR : VerificacionDocumento::SIN_FICHA,
                    'MOTIVO'      => mb_substr($this->motivoDeLaPropuesta($propuesta), 0, self::MOTIVO_MAX),
                    'A_MANO'      => true,
                    'INTENTOS'    => 0,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Carga masiva: no se pudo anotar la propuesta', [
                'archivo' => $propuesta['archivo'], 'error' => $e->getMessage(),
            ]);
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
    private function apoyarConIa(UploadedFile $archivo, ?string $tipoPedido, ?string $tipo, array $leido, array $equipos): array
    {
        $comoEstaba = [$tipo, $leido, $equipos, false, null];
        if (!$this->ia->disponible()) return $comoEstaba;

        $pdf = (string) @file_get_contents($archivo->getRealPath());
        // Un solo intento: esto corre dentro de una peticion del navegador, que tiene su
        // propio tope de tiempo (ver CargaMasivaDocumentosController). Reintentar aqui
        // acabaria en un "error de red" con el archivo ya subido.
        $visto = $pdf === '' ? null : $this->ia->leer($pdf, false, 1);
        if (!$visto) return $comoEstaba;

        $tipoIa = $tipoPedido ?: ($tipo ?: $visto['tipo']);
        if (!$tipoIa) return $comoEstaba;

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
        // El certificado asociado y la compraventa no se reconocen solos: todavia no hay
        // ejemplos reales de sus formatos. Se sueltan eligiendo el tipo en el modal.
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
     * De que tipo es el PDF, por lo que dice de si mismo. Gana el rotulo que aparece ANTES en
     * el texto, porque un documento se anuncia en su encabezado y lo de despues son menciones.
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
        // bajo, siempre la misma) y se avisa, en vez de elegir una al azar sin decirlo. Su
        // vencimiento puede ser el de otra fila de la tabla: eso lo resuelve la revision de la
        // noche, que lee fila por fila.
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
            ->orderBy('d.ID_EQUIPO')->limit(5)
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
            ->orderBy('d.ID_EQUIPO')
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

    /** Lo mismo que codigo(), en SQL, sobre la columna de la ficha. */
    private static function sqlCodigo(string $columna): string
    {
        $sql = "UPPER($columna)";
        foreach (['-' => '', ' ' => '', '.' => '', 'O' => '0', 'I' => '1', 'S' => '5'] as $de => $a) {
            $sql = "REPLACE($sql, '$de', '$a')";
        }
        return $sql;
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
                'd.LINK_DOC_ADICIONAL', 'd.LINK_DOC_ADICIONAL_2',
                'd.FECHA_VENC_POLIZA', 'd.FECHA_ROTC', 'd.FECHA_RACDA', 'd.FECHA_ADICIONAL',
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
        if ($auxiliar) {
            return $this->aplicarEnAuxiliar($idEquipo, $tipo, $link, $vence, $pisar, $ensayo, $cerrar);
        }

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
        //
        // EN LOCAL NO SE BORRA. El .env de desarrollo apunta al Drive REAL (las mismas
        // credenciales y carpetas que el servidor), mientras que la base de datos si es una
        // copia. O sea: reemplazar un documento desde el local no toca la ficha del servidor,
        // pero SI borraria de Drive el archivo al que apunta su enlace, y ese documento se
        // perderia para todos. Se deja el archivo huerfano, que no le hace daño a nadie, y
        // queda en el log para poder limpiarlo a mano si hiciera falta.
        if ($anterior && $anterior !== $link && ($viejoId = DocumentoAnexo::driveIdDeLink($anterior))) {
            if (app()->environment('local')) {
                Log::info('Carga masiva en local: NO se borra de Drive el documento reemplazado', [
                    'equipo' => $equipo->ID_EQUIPO, 'tipo' => $tipo, 'drive_id' => $viejoId,
                ]);
            } else {
                GoogleDriveService::borrarTrasResponder($viejoId);
            }
        }

        EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'upload_' . $tipo, [
            'archivo' => basename($link),
            'origen'  => 'carga masiva',
        ]);
        if ($diff) EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'metadata_' . $tipo, $diff);

        if ($cerrar) $this->cerrarPropuesta($link);
        return ['ok' => true, 'mensaje' => 'Aplicado.'];
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

        // Igual que en los equipos: en LOCAL no se borra de Drive el reemplazado (el .env de
        // desarrollo apunta al Drive REAL y se perderia para todos).
        if ($anterior && $anterior !== $link && ($viejoId = DocumentoAnexo::driveIdDeLink($anterior))) {
            if (app()->environment('local')) {
                Log::info('Carga masiva en local: NO se borra de Drive el documento reemplazado', [
                    'auxiliar' => $aux->ID_AUXILIAR, 'tipo' => $tipo, 'drive_id' => $viejoId,
                ]);
            } else {
                GoogleDriveService::borrarTrasResponder($viejoId);
            }
        }

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
