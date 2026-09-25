<?php

namespace App\Services;

use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

/**
 * Lee lo que dicen los PDF de la documentacion de un equipo y lo compara con su ficha
 * (tabla documentacion). Lo usa docs:verificar-documentos para los cuatro documentos:
 *
 *   · TITULO DE PROPIEDAD (LINK_DOC_PROPIEDAD) — nombre del propietario y fecha de emision.
 *   · POLIZA DE SEGURO   (LINK_POLIZA_SEGURO)  — aseguradora, fecha de vencimiento y de emision.
 *   · ROTC               (LINK_ROTC)           — propietario, numero y sus dos fechas.
 *   · RACDA              (LINK_RACDA)          — providencia de la EMPRESA: fecha, años de
 *                                                validez y la lista de placas autorizadas.
 *
 * El texto lo saca GOOGLE DRIVE, no el servidor: casi todos esos PDF son fotos escaneadas
 * (no traen texto que copiar), y montar un reconocedor propio pediria instalar y correr
 * Tesseract en cada documento. Drive ya lo hace gratis con la misma cuenta que guarda los
 * archivos: se copia el PDF como Documento de Google —esa copia SI lleva texto—, se exporta
 * en texto plano y se borra la copia. El servidor solo compara cadenas. Medido el
 * 17-09-2026: 6,3 s de Drive + 1,6 s de ida y vuelta por documento, sin descargar el PDF.
 */
class LectorDocumentoPdf
{
    /** Los cuatro documentos que se verifican. */
    public const PROPIEDAD = 'propiedad';
    public const POLIZA    = 'poliza';
    public const ROTC      = 'rotc';
    public const RACDA     = 'racda';

    /** Estados de la verificacion (los mismos que guarda verificacion_documento_registro). */
    public const COINCIDE    = 'coincide';
    public const DIFIERE     = 'difiere';
    public const ILEGIBLE    = 'ilegible';
    public const SIN_ARCHIVO = 'sin_archivo';
    public const ERROR       = 'error';

    /** Reintentos y espera para dejarle a Drive terminar el reconocimiento (ver texto()). */
    private const INTENTOS_OCR = 3;
    private const ESPERA_OCR_MS = 2500;
    /** Menos texto que esto es una hoja que no se reconocio (un documento da miles de caracteres). */
    private const TEXTO_MINIMO = 40;
    /** Cuantos PDF recien leidos se recuerdan dentro de una pasada (ver texto()). */
    private const RECUERDA_PDF = 8;

    /** documentacion.NOMBRE_DEL_TITULAR es varchar(150): lo leido se recorta a esa medida. */
    public const LARGO_TITULAR = 150;

    /**
     * El nombre de la empresa, tal como decidio el cliente que se escriba en TODAS las fichas
     * (18-09-2026). Estaba de seis formas en la flota ("27 C.A.", "27,CA", "CONTRUCTORA"...).
     */
    public const VIDALSA = 'CONSTRUCTORA VIDALSA 27, C.A';

    /**
     * Cualquier forma de ese nombre, comparada sin espacios ni puntuacion y con las letras de
     * otro alfabeto ya traducidas: la errata de los titulos del INTT ("CONTRUCTORA", sin S),
     * las del escaneo ("ONSTRUCTORA", "NCONSTRUCTORA", "CONATRUCTORA", "CONSTRUCTURA") y la
     * mancha pegada al final ("C.AACA"). El mismo patron lo usa el SQL de unificacion.
     */
    private const RE_VIDALSA = '/^[A-Z]{0,2}ONS?A?TRUCT[OU]RAVIDALSA27CA(ACA)?$/';

    /**
     * Una fecha 19/02/2026 dentro del texto. Los dos "no mires" de los extremos son la parte
     * importante: sin ellos, al buscarla detras de un rotulo ("Vigencia del Recibo 19/02/2026")
     * el comodin del rotulo se comia el primer digito y la fecha salia como 9/02/2026.
     */
    private const RE_FECHA = '(?<![\d\/\-\.])(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})(?![\d\/])';

    /**
     * Algo con pinta de placa: de 5 a 8 caracteres con letras Y digitos. No se pide el formato
     * actual (A85DR1K) porque en la flota hay 82 unidades con los formatos viejos (AB037BY,
     * AO577ZB, AP052BB, A03B01F...) y con el patron estricto ninguna se encontraba en la lista
     * del RACDA: salian todas avisadas como "no autorizada". Que se cuele algun codigo de la
     * hoja no hace daño: la lista solo se usa para ver si esta LA PLACA DE LA FICHA.
     */
    private const RE_PLACA = '/\b(?=[A-Z0-9]{5,8}\b)(?=[A-Z0-9]*[A-Z])(?=[A-Z0-9]*\d)[A-Z0-9]+\b/u';

    /** Serial de carroceria (N.I.V.): 14 a 20 caracteres con letras Y digitos, sin vocales sueltas. */
    private const RE_SERIAL = '/\b(?=[A-Z0-9]*\d)(?=[A-Z0-9]*[A-Z])[A-Z0-9]{14,20}\b/u';

    /**
     * Letras de otros alfabetos que se ven IGUAL que las nuestras. Aparecen al pegar el
     * nombre desde otro programa y dejan la ficha inencontrable al buscarla: "С.А" con C y A
     * cirilicas no es "C.A". Se traducen para poder comparar, y si solo diferian en eso se
     * avisa igual (ver comparar()). En placas y seriales son la MISMA letra (ver codigo()):
     * la ficha 573 tenia "A10AE0Н" con la Н cirilica y el RACDA la daba por no autorizada.
     * Publica: la usa tambien la migracion que arreglo esas placas.
     */
    public const HOMOGLIFOS = [
        'А'=>'A','В'=>'B','С'=>'C','Е'=>'E','Н'=>'H','І'=>'I','Ј'=>'J','К'=>'K','М'=>'M','О'=>'O',
        'Р'=>'P','Ѕ'=>'S','Т'=>'T','Х'=>'X','У'=>'Y','Ү'=>'Y','Ζ'=>'Z','Α'=>'A','Β'=>'B','Ε'=>'E','Η'=>'H',
        'Ι'=>'I','Κ'=>'K','Μ'=>'M','Ν'=>'N','Ο'=>'O','Ρ'=>'P','Τ'=>'T','Υ'=>'Y','Χ'=>'X',
    ];

    /**
     * Parejas que el escaneo confunde por su FORMA (ver malLeido): letra con letra y letra con
     * la cifra a la que se parece. O/0, I/1 y S/5 ya son la misma en codigo(). Una lista cerrada,
     * y no "cualquier letra por cualquier cifra": una O leida por un 0 frente a un 2 es, en el
     * fondo, un 0 por un 2 —cifra por cifra— y eso separa a dos hermanos de flota.
     */
    private const LETRAS_PARECIDAS = ['RP', 'NH', 'NM', 'UV', 'CG', 'EF', 'KX', 'ZL',
                                      'S8', 'B8', 'G6', 'G0', 'Z2', 'D0', 'Q0', 'T7', 'A4', 'L1', 'H1'];

    private const MESES = [
        'ENERO'=>1,'FEBRERO'=>2,'MARZO'=>3,'ABRIL'=>4,'MAYO'=>5,'JUNIO'=>6,
        'JULIO'=>7,'AGOSTO'=>8,'SEPTIEMBRE'=>9,'SETIEMBRE'=>9,'OCTUBRE'=>10,'NOVIEMBRE'=>11,'DICIEMBRE'=>12,
    ];

    /**
     * Texto del PDF de Drive. Devuelve '' si Drive no lo reconocio (la copia sale vacia).
     * La copia temporal se borra SIEMPRE, incluso si la exportacion falla: si no, quedarian
     * documentos sueltos ocupando el Drive de la empresa.
     *
     * Un mismo ARCHIVO de Drive se lee una sola vez por pasada (se recuerdan los ultimos
     * RECUERDA_PDF). Pasa cuando varias fichas apuntan al mismo enlace. Con el RACDA ahorra
     * menos de lo que parece: la providencia es la misma para muchos equipos, pero en Drive
     * hay una copia subida POR FICHA (223 fichas, 224 archivos distintos). El recuerdo vive
     * lo que vive el objeto —una pasada del comando—, asi que la pasada siguiente vuelve a
     * leer el archivo de verdad.
     */
    public function texto(string $driveId): string
    {
        if (isset($this->leidos[$driveId])) return $this->leidos[$driveId];

        $drive = GoogleDriveService::getInstance()->getDrive();
        $copia = $drive->files->copy($driveId, new DriveFile([
            'name'     => 'ocr_tmp_' . uniqid(),
            'mimeType' => 'application/vnd.google-apps.document',
        ]), ['ocrLanguage' => 'es', 'supportsAllDrives' => true, 'fields' => 'id']);

        try {
            // Drive termina de reconocer la imagen DESPUES de responder a la copia: pedir el
            // texto enseguida devuelve el documento vacio (visto con titulos que en otra
            // pasada dieron 2.600 caracteres). Se reintenta antes de darlo por ilegible.
            $texto = '';
            for ($intento = 1; $intento <= self::INTENTOS_OCR; $intento++) {
                if ($intento > 1) usleep(self::ESPERA_OCR_MS * 1000);
                $texto = (string) $drive->files->export($copia->id, 'text/plain', ['alt' => 'media'])->getBody();
                if (mb_strlen(trim($texto, " \t\n\r\0\x0B\u{FEFF}")) >= self::TEXTO_MINIMO) break;
            }
            // Lo ilegible tambien se recuerda: si Drive no lo reconocio tras los reintentos,
            // repetirlo 40 veces en la misma pasada no lo va a reconocer.
            $this->leidos[$driveId] = $texto;
            // Con tope: lo que hay que evitar es releer el MISMO archivo una y otra vez
            // cuando varias fichas comparten enlace, no quedarse con el texto de toda la
            // pasada en memoria — con --rehacer serian miles.
            if (count($this->leidos) > self::RECUERDA_PDF) array_shift($this->leidos);
            return $texto;
        } finally {
            try {
                $drive->files->delete($copia->id, ['supportsAllDrives' => true]);
            } catch (\Throwable $e) {
                // Que no se pierda la lectura por no poder borrar la copia; queda en el log.
                Log::warning('verificar-documentos: no se borro la copia OCR ' . $copia->id . ': ' . $e->getMessage());
            }
        }
    }

    /** Lo ya leido en esta pasada, por id de Drive (ver texto()). */
    private array $leidos = [];

    /** Lo que dice el documento. Las claves vacias son "no se encontro". */
    public function extraer(string $tipo, string $texto): array
    {
        $plano = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $texto)));

        $datos = match ($tipo) {
            self::POLIZA => $this->extraerPoliza($plano),
            self::ROTC   => $this->extraerRotc($plano),
            self::RACDA  => $this->extraerRacda($plano),
            default      => $this->extraerPropiedad($plano),
        };

        // Ademas de la placa y el serial que van detras de su rotulo, TODOS los que aparezcan
        // en la hoja: con ellos se reconoce el vehiculo aunque el reconocimiento haya separado
        // los rotulos de sus valores (ver placasEnTexto y mismoVehiculo). El RACDA no los
        // necesita: su lista de placas ya es eso mismo.
        if ($tipo !== self::RACDA) {
            $datos['placas']   = $this->placasEnTexto($plano);
            $datos['seriales'] = $this->serialesEnTexto($plano);
            // Polizas de FLOTA (anexos de Responsabilidad Civil y similares): no nombran UN
            // vehiculo, traen una tabla "SE AMPARA(N) LOS SIGUIENTES VEHICULOS Y/O EQUIPOS"
            // con el serial de carroceria de cada uno. Esa tabla SI dice de quien es el
            // documento, y un vehiculo que no esta en ella no esta amparado (ver mismoVehiculo).
            // Se busca "LOS SIGUIENTES VEHICULOS" y no el verbo: la de Piramide dice "SE AMAPARA".
            $datos['flota'] = (bool) preg_match('/LOS\s+SIGUIENTES\s+VEH[IÍ]CULOS/ui', $plano, $m, PREG_OFFSET_CAPTURE);
            // Los seriales de la TABLA: solo lo que va detras de su rotulo (lo de arriba es
            // el membrete) y solo los de 17 caracteres, que es lo que mide un serial de
            // carroceria (N.I.V.). Un serial de motor o un codigo cualquiera no cuenta. Es la
            // lista que puede decir "este equipo no esta amparado" (ver mismoVehiculo).
            $datos['seriales_flota'] = $datos['flota']
                ? array_values(array_filter($this->serialesEnTexto(substr($plano, $m[0][1])), fn ($s) => strlen($s) === 17))
                : [];
        }
        return $datos;
    }

    /**
     * ROTC (Certificado de Circulacion de Vehiculo de Carga del INTT): una tabla con la razon
     * social, la placa, el serial de carroceria, el numero de ROTC y sus dos fechas. Es un PDF
     * con texto de verdad (no una foto), asi que sale entero y en orden.
     */
    private function extraerRotc(string $plano): array
    {
        $datos = ['titular' => null, 'placa' => null, 'serial' => null, 'nro' => null, 'emision' => null, 'vence' => null];
        $f = self::RE_FECHA;

        // Las dos fechas van juntas bajo sus rotulos: primero la de emision, despues la de
        // vencimiento. El reconocimiento puede meter el rotulo y el valor en lineas distintas.
        // La tabla de FLOTA las pone en su cabecera: "Fecha y Hora de Emisión: 11/02/2026
        // 06:45:24 PM" y "Fecha de vencimiento: 11/02/2027" (Drive a veces devuelve solo esa
        // tabla y no el certificado, que es una imagen: visto el 21-09-2026).
        if (preg_match('/Fecha\s*(?:y\s*Hora\s*)?de\s*Emisi[oó]n[^\d]{0,80}' . $f . '[^\d]{0,80}' . $f . '/ui', $plano, $m)) {
            $datos['emision'] = $this->fecha($m[1]);
            $datos['vence']   = $this->fecha($m[2]);
        } else {
            if (preg_match('/Fecha\s*(?:y\s*Hora\s*)?de\s*Emisi[oó]n:?\s*' . $f . '/ui', $plano, $m)) $datos['emision'] = $this->fecha($m[1]);
            if (preg_match('/Fecha\s*de\s*Vencimiento:?\s*' . $f . '/ui', $plano, $m)) $datos['vence'] = $this->fecha($m[1]);
        }
        // La tabla sale como BLOQUE DE ROTULOS y debajo el bloque de valores, en el mismo
        // orden ("Razon Social / RIF / Nro de ROTC" y luego el nombre, el RIF y el numero).
        // Por eso no vale buscar "rotulo: valor": hay que leer la fila de abajo.
        if (preg_match('/Raz[oó]n\s*Social\s*\R\s*RIF\s*\R\s*Nro\s*de\s*ROTC\s*\R\s*(.+)\R\s*[VEJGP]-?[\d\-]{6,}\s*\R\s*(\d{3,10})/ui', $plano, $m)
            // La misma tabla con cada fila en UNA linea ("Razón Social RIF Nro de ROTC" y debajo
            // "CONSTRUCTORA VIDALSA 27, C.A J-29387719-9 49199"). Sin esto el respaldo de abajo
            // tomaba "RIF Nro de ROTC" por el nombre del titular (visto con el ROTC real, 25-09-2026).
            || preg_match('/Raz[oó]n\s*Social[^\S\r\n]+RIF[^\S\r\n]+Nro\s*de\s*ROTC[^\S\r\n]*\R[^\S\r\n]*(.+?)[^\S\r\n]+[VEJGP]-?[\d\-]{6,}[^\S\r\n]+(\d{3,10})\b/ui', $plano, $m)) {
            $datos['titular'] = $this->limpiarNombre($m[1]);
            $datos['nro'] = $m[2];
        } else {
            // Respaldo para cuando el reconocimiento junta rotulo y valor en una linea. El
            // nombre tiene que ir DETRAS del rotulo, no en la linea de mas abajo: con \s* (que
            // cruza saltos de linea) el respaldo cogia el siguiente rotulo de la tabla ("RIF")
            // y eso terminaba escrito en la ficha al pulsar "Corregir".
            // Y nunca un rotulo por nombre: "RIF Nro de ROTC" es la cabecera, no el titular.
            if (preg_match('/Raz[oó]n\s*Social:?[^\S\r\n]*([^\r\n]+)/ui', $plano, $m) && !preg_match('/^\s*RIF\b/ui', $m[1])) {
                $datos['titular'] = $this->limpiarNombre($m[1]);
            }
            // "Nro de ROTC" en el certificado; "Número de ROTC: 49199" en la tabla de flota.
            if (preg_match('/(?:Nro|N[uú]mero)\s*de\s*ROTC:?[^\d]{0,30}(\d{3,10})/ui', $plano, $m)) $datos['nro'] = $m[1];
        }
        // "Placa / Serial de Carroceria / Marca - Modelo / Año" y debajo sus cuatro valores.
        if (preg_match('/Placa\s*\R\s*Serial\s*de\s*Carrocer[ií]a\s*\R[^\n]*\R[^\n]*\R\s*([A-Z0-9]{5,8})\s*\R\s*([A-Z0-9]{10,25})\b/ui', $plano, $m)
            // La misma tabla con cada fila en UNA linea: "Placa Serial de Carrocería Marca -
            // Modelo Año" y debajo "A88EZ7A LA9B23GE5H1GHY696 JAC - HFC9380TJP 2017" (ROTC real,
            // 25-09-2026). Sin esto la placa y el serial del certificado quedaban vacios.
            || preg_match('/Placa[^\S\r\n]+Serial[^\S\r\n]*de[^\S\r\n]*Carrocer[ií]a[^\r\n]*\R[^\S\r\n]*([A-Z0-9]{5,8})[^\S\r\n]+([A-Z0-9]{10,25})\b/ui', $plano, $m)) {
            $datos['placa']  = mb_strtoupper($m[1]);
            $datos['serial'] = mb_strtoupper($m[2]);
        } else {
            $datos['placa']  = $this->placaEnTexto($plano);
            $datos['serial'] = $this->serialEnTexto($plano);
        }
        // El vencimiento de la HOJA de flota ("Fecha de vencimiento: 03/07/2027", en su
        // cabecera). Vale para las unidades de su tabla cuando la tabla no se pudo leer fila por
        // fila; el del certificado de debajo puede ser viejo (ver CargaMasivaDocumentos).
        $datos['vence_flota'] = preg_match('/FLOTA\s+VEHICULAR[\s\S]{0,600}?Fecha\s*de\s*vencimiento:?\s*' . $f . '/ui', $plano, $m)
            ? $this->fecha($m[1]) : null;
        // Y cuando se emitio esa hoja ("Fecha y Hora de Emisión: 03/07/2026 12:20:15 PM"): es la
        // emision que va con ese vencimiento, no la del certificado.
        $datos['emision_flota'] = $datos['vence_flota'] && preg_match('/Fecha\s*y\s*Hora\s*de\s*Emisi[oó]n:?\s*' . $f . '/ui', $plano, $m)
            ? $this->fecha($m[1]) : null;

        // El ROTC de FLOTA trae, antes del certificado, la tabla de la flota: una fila por
        // vehiculo con su placa, su serial de carroceria y, AL LADO DEL SERIAL, su vencimiento
        // ("69 A45AF5Y JAC HFC3252KR1K3 2017 VOLTEO 3 16200 Ton. LJ13R8DK3H3400167 03/07/2027").
        // El certificado de debajo puede ser de otro vehiculo de la misma hoja: la fila de ESTE
        // equipo la busca filaRotc().
        $datos['filas'] = [];
        if (preg_match_all('/^[^\S\r\n]*\d{1,4}[^\S\r\n]+([A-Z0-9]{5,8})[^\S\r\n]+[^\r\n]*?[^\S\r\n]([A-Z0-9]{17})[^\S\r\n]+(\d{1,2}\/\d{1,2}\/\d{4})[^\S\r\n]*$/um',
            $plano, $filas, PREG_SET_ORDER)) {
            foreach ($filas as $fila) {
                if ($vence = $this->fecha($fila[3])) {
                    $datos['filas'][] = ['placa' => $fila[1], 'serial' => $fila[2], 'vence' => $vence];
                }
            }
        }

        return $datos;
    }

    /**
     * La fila de ESTE equipo en la tabla de un ROTC de flota (ver extraerRotc), o null. Cuenta
     * si coincide la placa o el serial —con la tolerancia de siempre, ver codigo()— y el otro
     * dato, si la ficha lo tiene, no la contradice: una fila con la placa de este equipo y el
     * serial de otro no es de nadie seguro.
     *
     * Dos matices medidos en el ROTC 49199 (21-09-2026):
     *   · Hay fichas con solo el FINAL del serial ("H3400085" de "LJ13R8DK1H3400085"): si la
     *     fila termina en el, no la contradice.
     *   · Con el serial COMPLETO (17) igual, la fila es de este equipo aunque traiga otra placa
     *     (el ROTC tenia "A06EA3G" para el serial de la ficha A46AF0Y): el N.I.V. no se repite.
     *     Se devuelve marcada ('placa_distinta') para que una persona decida cual placa es la buena.
     */
    public function filaRotc(?string $placa, ?string $serial, array $leido): ?array
    {
        foreach ($leido['filas'] ?? [] as $fila) {
            $porPlaca  = $placa ? $this->mismoCodigo($placa, $fila['placa']) === 'si' : null;
            $porSerial = $serial ? $this->serialDeFila($serial, $fila['serial']) : null;
            if ($porSerial && $porPlaca === false && strlen($this->codigo($serial)) === 17) {
                return $fila + ['placa_distinta' => true];
            }
            if (($porPlaca || $porSerial) && $porPlaca !== false && $porSerial !== false) return $fila;
        }
        return null;
    }

    /** ¿El serial de la ficha es el de la fila, entero o su final (8 o mas caracteres)? */
    private function serialDeFila(string $serial, string $deFila): bool
    {
        [$s, $f] = [$this->codigo($serial), $this->codigo($deFila)];
        return $s === $f || (strlen($s) >= 8 && strlen($s) < strlen($f) && str_ends_with($f, $s));
    }

    /**
     * RACDA (Providencia Administrativa del MINEC). OJO: es un documento de la EMPRESA, no de
     * un vehiculo: la misma providencia vale para muchos, y lo que dice de cada equipo es si
     * su placa esta en la lista de unidades autorizadas. De ahi salen:
     *   · la fecha en que se emitio ("CARACAS, 14 DE JULIO DE 2025");
     *   · hasta cuando vale ("tendra validez por DOS (02) años, contados a partir de la emision");
     *   · las placas autorizadas.
     */
    private function extraerRacda(string $plano): array
    {
        $datos = ['emision' => null, 'vence' => null, 'nro' => null, 'anios' => null, 'placas' => []];

        if (preg_match('/CARACAS,?\s*(\d{1,2})\s*DE\s*([A-ZÁÉÍÓÚa-záéíóú]{4,12})\s*DE\s*(\d{4})/ui', $plano, $m)) {
            $datos['emision'] = $this->fechaDeMes($m[1], $m[2], $m[3]);
        }
        if (preg_match('/validez\s*por\s*[A-ZÁÉÍÓÚa-záéíóú]+\s*\((\d{1,2})\)\s*a[ñn]os/ui', $plano, $m)) {
            $datos['anios'] = (int) $m[1];
        }
        if ($datos['emision'] && $datos['anios']) {
            $datos['vence'] = date('Y-m-d', strtotime($datos['emision'] . ' +' . $datos['anios'] . ' years'));
        }
        if (preg_match('/PROVIDENCIA\s*ADMINISTRATIVA\s*N[°ºo.]*\s*(\d{2,8})/ui', $plano, $m)) {
            $datos['nro'] = $m[1];
        }
        $datos['placas'] = $this->placasEnTexto($plano);

        return $datos;
    }

    /** ¿La placa de la ficha esta entre las autorizadas por el RACDA? */
    public function placaEnLista(?string $placa, array $placas): bool
    {
        return $this->codigoEnLista($placa, $placas) === 'si';
    }

    /**
     * ¿Esta la placa (o el serial) de la ficha en una lista leida del documento? Responde
     * 'si', 'no' o 'no_se_sabe' cuando no hay con que comparar. Compara con la MISMA tolerancia
     * que mismoCodigo (ver codigo()): un cero mal leido en un documento de decenas de placas
     * no puede hacer que una unidad autorizada salga como "no autorizada".
     */
    private function codigoEnLista(?string $codigo, array $lista): string
    {
        if (!$codigo || !$lista) return 'no_se_sabe';
        foreach ($lista as $x) {
            if ($this->mismoCodigo($codigo, is_scalar($x) ? (string) $x : null) === 'si') return 'si';
        }
        return 'no';
    }

    /**
     * Titulo de propiedad (Certificado de Registro de Vehiculo del INTT): propietario, placa,
     * numero de documento y la fecha en que se emitio ("Dado a los 3 dias del mes de OCTUBRE
     * de 2018" en el formato viejo; "12 FEBRERO 2026" en la fila de datos del nuevo).
     */
    private function extraerPropiedad(string $plano): array
    {
        $datos = ['titular' => null, 'placa' => null, 'serial' => null, 'nro' => null, 'emision' => null];

        // El nombre va en la linea siguiente a "a:", salvo cuando el reconocimiento pega ahi el
        // rotulo que sigue en la hoja ("Cédula o RIFCORPO NAC DE LOGISTICA..."): eso lo quita
        // limpiarNombre. `\R` = el salto de linea, que no puede caer dentro de un \s* suelto.
        if (preg_match('/Veh[ií]culo\s+a:[^\S\r\n]*\R?[^\S\r\n]*(.+)/ui', $plano, $m)) {
            $datos['titular'] = $this->limpiarNombre($m[1]);
        }
        // Respaldo: la linea-resumen del pie, que repite todo seguido
        // "8XAFU29G4JR001045-1-1 CONSTRUCTORA VIDALSA 27, C.A J293877199 A85DR1K".
        if (!$datos['titular'] && preg_match('/^[A-Z0-9]{8,}-\d+-\d+\s+(.+?)\s+([VEJGP]-?\d{6,})\b/umi', $plano, $m)) {
            $datos['titular'] = $this->limpiarNombre($m[1]);
        }
        $datos['placa'] = $this->placaEnTexto($plano);
        if (preg_match('/\b(\d{12})\b/', $plano, $m)) {
            $datos['nro'] = $m[1];
        }
        $datos['serial'] = $this->serialEnTexto($plano);
        if (preg_match('/Dado\s+a\s+los:?\s*(\d{1,2})\D{1,40}?de:?\s*([A-ZÁÉÍÓÚa-záéíóú]{4,12})\D{0,12}(\d{4})/ui', $plano, $m)) {
            $datos['emision'] = $this->fechaDeMes($m[1], $m[2], $m[3]);
        }
        // Formato NUEVO del INTT (visto en los de 2025-2026): no trae "Dado a los". La fecha va
        // en la fila de datos del vehiculo, tras la capacidad y el uso ("1050 KGS PRIVADO  12
        // FEBRERO 2026"), en MAYUSCULAS y sin "de"; por eso sin /i y atada a esa fila: otra
        // fecha de la hoja ("Gaceta Oficial ... de fecha 29 de agosto de 2018") no es la emision.
        if (!$datos['emision'] && preg_match('/\bKGS\b[^\r\n]{0,40}?(?<!\d)(\d{1,2})\s+(' . implode('|', array_keys(self::MESES)) . ')\s+(\d{4})\b/u', $plano, $m)) {
            $datos['emision'] = $this->fechaDeMes($m[1], $m[2], $m[3]);
        }
        // Respaldo del mismo formato: la linea de control del pie empieza por esa fecha
        // ("20260212/EL/PRS/1/1/<numero>/...").
        if (!$datos['emision'] && preg_match('/(?<!\d)(20\d{2})(\d{2})(\d{2})\/[A-Z]{2}\/[A-Z]{3}\//', $plano, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $datos['emision'] = sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }
        return $datos;
    }

    /**
     * Poliza de seguro: la aseguradora se reconoce por su nombre en el texto (lo hace
     * aseguradoraEnTexto con el catalogo), y las fechas por sus rotulos. "Vigencia ... 19/02/2026
     * al 19/02/2027" da la de vencimiento (la segunda); "Fecha de Emision" da la de emision.
     */
    private function extraerPoliza(string $plano): array
    {
        $datos = ['aseguradora' => null, 'nro' => null, 'desde' => null, 'vence' => null, 'emision' => null, 'placa' => null, 'serial' => null];

        // La vigencia viene de dos maneras segun la aseguradora: "Desde X Hasta Y" (Mampreca)
        // o "Vigencia del Seguro: X al Y" (Piramide). El reconocimiento parte la tabla, asi que
        // el rotulo y sus fechas pueden quedar en lineas distintas: se busca la PAREJA de fechas.
        $f = self::RE_FECHA;
        // ¿La vigencia encontrada es la de la POLIZA? La del recibo ("VIGENCIA DEL RECIBO") es un
        // periodo de pago: no sirve de fecha de origen (ver el respaldo de la emision, abajo).
        $vigenciaDePoliza = false;
        // Tras "Hasta" pueden venir otros rotulos antes de su fecha ("Hasta \nFrecuencia de
        // Pago: Sucursal: 1/12/2026"), pero ninguna otra cifra: [^\d] no se salta una fecha.
        // Entre las dos de la vigencia puede ir "al", "hasta" o un guion ("16/01/2026 - 16/01/2027").
        if (preg_match('/Desde\s*' . $f . '\s*Hasta[^\d]{0,60}' . $f . '/ui', $plano, $m, PREG_OFFSET_CAPTURE)
            || preg_match('/Vigencia[^\r\n]{0,45}\R?[^\d\r\n]{0,15}' . $f . '\s*(?:al|a|hasta|-|–)\s*' . $f . '/ui', $plano, $m, PREG_OFFSET_CAPTURE)) {
            $datos['desde'] = $this->fecha($m[1][0]);
            $datos['vence'] = $this->fecha($m[2][0]);
            // "RECIBO" en la vigencia o en el rotulo de justo antes ("Vigencia del Recibo:
            // Desde ... Hasta ..."): es el periodo de pago, no la poliza.
            $vigenciaDePoliza = !preg_match('/RECIBO/ui', substr($plano, max(0, $m[0][1] - 40), 40) . $m[0][0]);
        } else {
            // Respaldo para las que separan los rotulos de sus valores ("Desde : Desde:" en una
            // linea y las fechas mas abajo) pero dejan el vencimiento pegado a su rotulo:
            // "Hasta: 12/05/2026" y "Fecha de Inicio Poliza: 12/05/2025" (visto el 18-09-2026,
            // que ademas escribe "Incio"). Solo fechas PEGADAS a su rotulo: una suelta de la
            // pagina podria ser cualquier otra.
            if (preg_match('/Hasta\s*:?[^\S\r\n]*' . $f . '/ui', $plano, $m)) {
                $datos['vence'] = $this->fecha($m[1]);
            }
            if (preg_match('/Fecha\s*de\s*In[i]?cio\s*(?:de\s*)?P[oó]liza\s*:?[^\S\r\n]*' . $f . '/ui', $plano, $m)) {
                $datos['desde'] = $this->fecha($m[1]);
            }
        }
        // ANEXO de poliza (el de flota, "SE AMPARA LOS SIGUIENTES VEHICULOS"): no trae "Desde /
        // Hasta"; su unica fecha es la de la firma, en la ultima pagina ("se firma en la ciudad
        // de CARACAS a los 25 dias del mes de Marzo del año 2026"). Esa es su emision y vence
        // UN AÑO despues (regla del cliente, 19-09-2026). Solo si no se encontro la vigencia.
        if (!$datos['vence'] && preg_match('/\bANEXO\b/u', $plano)
            && preg_match('/se\s+firma\s+en\s+la\s+ciudad\s+de\s+[^\r\n]{2,40}?\s+a\s+los\s+(\d{1,2})\s+d[ií]as?\s+del\s+mes\s+de\s+(\p{L}{4,12})\s+del\s+a[ñn]o\s+(\d{4})/ui', $plano, $m)
            && ($firma = $this->fechaDeMes($m[1], $m[2], $m[3]))) {
            $datos['emision'] = $datos['desde'] = $firma;
            $datos['vence'] = date('Y-m-d', strtotime($firma . ' +1 year'));
            $datos['vence_por_firma'] = true;
        }
        // La emision es la PRIMERA fecha despues de su rotulo. Entre los dos solo puede haber
        // texto SIN cifras: el reconocimiento a veces pone los rotulos juntos y los valores en
        // la linea de abajo ("Fecha Emisión: Hora Emisión: Vigencia\n17/6/2025", medido el
        // 21-09-2026), y sin cifras en medio nunca se salta a otra fecha ni a la hora.
        if (preg_match('/Fecha\s*(?:de\s*)?Emisi[oó]n:?[^\d]{0,30}?' . $f . '/ui', $plano, $m)) {
            $datos['emision'] = $this->fecha($m[1]);
        }
        // Sin rotulo de emision (los cuadros de Piramide solo dicen "VIGENCIA DEL SEGURO:
        // 12/08/2026 al 09/06/2027"), la fecha de origen es el INICIO de la vigencia de la poliza
        // (regla del cliente, 21-09-2026; donde vienen las dos, coinciden).
        if (!$datos['emision'] && $vigenciaDePoliza) {
            $datos['emision'] = $datos['desde'];
        }
        // El numero de poliza lleva digitos; sin exigirlos, "Cuadro Poliza Recibo" daba "RECIBO".
        if (preg_match('/P[oó]liza\s*(?:Nro|N[°ºo]|Numero|N[uú]mero)?\.?:?\s*([A-Z0-9][A-Z0-9\-\.\/]{5,38})/ui', $plano, $m)
            && preg_match('/\d{3}/', $m[1])) {
            $datos['nro'] = mb_strtoupper(trim($m[1], " .-/"));
        }
        $datos['placa'] = $this->placaEnTexto($plano);
        $datos['serial'] = $this->serialEnTexto($plano);
        return $datos;
    }

    /**
     * TODAS las placas con formato venezolano que aparecen en el texto, esten donde esten.
     * Hace falta porque hay hojas donde el rotulo y su valor quedan en bloques separados (la
     * poliza de Piramide pone "Marca: Modelo: Placa: Uso:" y varias lineas mas abajo los
     * cuatro valores): buscar "Placa: X" no encuentra nada y el documento quedaba "sin
     * confirmar" aunque la placa este escrita. Tambien vale para el RACDA, que es una lista.
     */
    private function placasEnTexto(string $plano): array
    {
        preg_match_all(self::RE_PLACA, mb_strtoupper($plano), $m);
        return array_values(array_unique($m[0]));
    }

    /** Lo mismo para los seriales de carroceria (ver placasEnTexto). */
    private function serialesEnTexto(string $plano): array
    {
        preg_match_all(self::RE_SERIAL, mb_strtoupper($plano), $m);
        return array_values(array_unique($m[0]));
    }

    /**
     * La placa que dice el documento. Va detras de su rotulo, en la misma linea o en la
     * siguiente, y SIEMPRE lleva algun digito: sin exigirlo, en la tabla del ROTC ("Placa" y
     * debajo "Serial de Carroceria") se leia "SERIAL" como si fuera la placa.
     */
    private function placaEnTexto(string $plano): ?string
    {
        if (!preg_match('/Placa:?[^\S\r\n]*(?:\R[^\S\r\n]*)?([A-Z0-9]{5,8})\b/ui', $plano, $m)) return null;
        $placa = mb_strtoupper($m[1]);
        return preg_match('/\d/', $placa) ? $placa : null;
    }

    /**
     * El serial del chasis (N.I.V. / Serial de Carroceria), que identifica al vehiculo igual
     * que la placa y sale en los dos documentos. Hace falta porque la placa es justo lo que no
     * se lee en los escaneos sucios, y sin ninguno de los dos no se puede afirmar que el PDF
     * sea de esta ficha (ver mismoVehiculo).
     */
    private function serialEnTexto(string $plano): ?string
    {
        foreach (['/N\.?\s?I\.?\s?V\.?:?\s*([A-Z0-9]{10,25})\b/ui',
                  '/Serial\s*(?:de\s*)?(?:N\.?I\.?V|Carroceri?a|Chasis)\.?:?\s*([A-Z0-9]{10,25})\b/ui'] as $re) {
            // Con digitos: un serial siempre los lleva. Sin exigirlos, en las hojas donde el
            // valor no va detras del rotulo se cogia la palabra de al lado ("PLATAFORMA") y el
            // documento salia avisado como "es de otro vehiculo".
            if (preg_match($re, $plano, $m) && strtoupper($m[1]) !== 'NA' && preg_match('/\d/', $m[1])) {
                return mb_strtoupper($m[1]);
            }
        }
        return null;
    }

    /**
     * La aseguradora del catalogo cuyo nombre aparece en el texto. $catalogo es
     * [ID_SEGURO => NOMBRE_ASEGURADORA].
     *
     * Primero el nombre ENTERO ("SEGUROS CONSTITUCION"); si ninguno sale entero, la palabra mas
     * larga del nombre ("PIRAMIDE" de "PIRÁMIDE SEGUROS", tambien dentro de
     * "segurospiramide.com"). Entre varias, gana la que aparece ANTES en el texto, y no la
     * primera del catalogo.
     *
     * Una palabra que es un LUGAR no vale sola (LUGARES): con dos polizas reales (Pirámide y
     * Seguros Constitución, 25-09-2026) las dos salian "SEGUROS CARACAS" porque en ambas
     * aparece "CARACAS" en la direccion o la sucursal. Esa aseguradora se reconoce por su
     * nombre entero.
     */
    public function aseguradoraEnTexto(string $texto, array $catalogo): ?int
    {
        $plano = $this->normalizar($texto);
        $mejor = null;
        $donde = PHP_INT_MAX;
        foreach ([true, false] as $entero) {
            foreach ($catalogo as $id => $nombre) {
                $norm = $this->normalizar($nombre);
                if ($entero) {
                    $clave = mb_strlen($norm) >= 5 ? $norm : null;
                } else {
                    $palabras = array_filter(explode(' ', $norm),
                        fn ($p) => mb_strlen($p) >= 5 && !in_array($p, ['SEGUROS', 'SEGURO', 'POSEE', 'DOCUMENTO'], true)
                            && !in_array($p, self::LUGARES, true));
                    usort($palabras, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
                    $clave = $palabras[0] ?? null;
                }
                if (!$clave) continue;
                $pos = mb_strpos($plano, $clave);
                if ($pos !== false && $pos < $donde) {
                    $donde = $pos;
                    $mejor = (int) $id;
                }
            }
            if ($mejor !== null) return $mejor;
        }
        return null;
    }

    /** Lugares que salen en las direcciones de las polizas (ver aseguradoraEnTexto). */
    private const LUGARES = ['CARACAS', 'VENEZUELA', 'MIRANDA', 'MATURIN', 'MONAGAS', 'ANZOATEGUI',
                             'BARCELONA', 'VALENCIA', 'MARACAIBO', 'ORIENTE', 'OCCIDENTE', 'CAPITAL'];

    /**
     * Compara un nombre de la ficha con el del documento. Devuelve [iguales, motivo, sirve]:
     * el motivo explica en que se diferencian, para decidir sin abrir el PDF, y `sirve` dice
     * si lo leido se le puede poner a la ficha (false cuando el documento se leyo a medias).
     */
    public function compararNombre(?string $enFicha, ?string $enDocumento): array
    {
        // Sin espacios, ademas de sin puntuacion: "27, C.A", "27 C. A." y "27,CA" son el MISMO
        // nombre, y el punto de "C.A" lo pone o lo quita el escaneo. Con los espacios dentro,
        // "C.A" quedaba "C A" frente a "CA" y la noche reescribia cientos de fichas (y llenaba
        // el historial) por un punto, avisando encima "se diferencian en una letra". Solo aqui:
        // normalizar() la usan tambien otros que SI necesitan separar las palabras.
        $doc = str_replace(' ', '', $this->normalizar($this->canonico($enDocumento)));
        $ficha = str_replace(' ', '', $this->normalizar($this->canonico($enFicha)));
        if ($ficha === '') {
            return [false, 'La ficha no tiene nombre'];
        }
        if ($ficha === $doc) {
            // Iguales tras traducir las letras de otro alfabeto: hay que corregirlo igual. Los
            // acentos NO cuentan: el reconocimiento se los come y la ficha suele ser la correcta.
            return $this->tieneHomoglifos((string) $enFicha)
                ? [false, 'El mismo nombre, pero escrito con letras de otro alfabeto']
                : [true, null];
        }
        // La PRIMERA letra es la que va pegada al borde de la hoja, y en los escaneos es la que
        // se pierde o sale cambiada: "ORPO NAC" o "AORPO NAC" donde el papel dice "CORPO NAC"
        // (visto en los titulos del 18-09-2026). Eso no es lo que dice el documento, es un
        // fallo de lectura: si solo difieren en esa letra, la ficha NO se toca (sirve = false)
        // y queda para que una persona lo mire en el visor. Cualquier otra diferencia sigue la
        // regla de siempre: manda el documento.
        if (mb_strlen($ficha) >= 6 && $doc !== '' && $doc[0] !== $ficha[0]) {
            $restoDoc = substr($doc, 1);
            $restoFicha = substr($ficha, 1);
            $cambiada = str_starts_with($restoDoc, $restoFicha) || str_starts_with($restoFicha, $restoDoc);
            $perdida  = str_starts_with($doc, $restoFicha) || str_starts_with($restoFicha, $doc);
            if ($cambiada || $perdida) {
                return [false, 'El escaneo se comió o cambió la primera letra del nombre: la ficha no se toca', false];
            }
        }
        // Uno contiene al otro. Importa CUAL es el corto: si el corto es el del documento, lo
        // que se leyo a medias es el PDF (el reconocimiento corta el nombre al topar con el
        // siguiente rotulo), y aplicarlo dejaria la ficha PEOR. Eso no se ofrece corregir.
        if (str_contains($doc, $ficha)) {
            return [false, 'El nombre de la ficha esta recortado o abreviado'];
        }
        if (str_contains($ficha, $doc)) {
            return [false, 'El documento se leyo a medias: dice menos que la ficha', false];
        }
        // Una o dos letras de diferencia sobre un nombre largo no es otro propietario: es una
        // errata, y puede estar en la ficha o en el propio documento (el INTT emitio titulos
        // que dicen "CONTRUCTORA"). Se avisa para que la persona mire antes de aplicarlo.
        if (mb_strlen($doc) >= 12 && levenshtein($ficha, $doc) <= 2) {
            return [false, 'Se diferencian en una letra: revisa cual de los dos esta mal escrito'];
        }
        return [false, 'La ficha dice otro nombre'];
    }

    /**
     * ¿El documento es de ESTE vehiculo? Devuelve 'si' | 'no' | 'no_se_sabe'. En este orden:
     *   1. La placa y el serial que van TRAS SU ROTULO: si uno coincide, 'si'; si nombran a
     *      otro, 'no'.
     *   2. Sin rotulo legible, las placas y seriales sueltos de la hoja solo CONFIRMAN ('si'):
     *      recogen cualquier codigo parecido y su "no lo encuentro" seria ruido.
     *   3. Excepcion: la tabla de equipos de una poliza de FLOTA si descarta ('no').
     * Es lo UNICO que impide copiarle a una ficha los datos del documento de otro vehiculo,
     * por eso "no se sabe" NO cuenta como que si: se marca para que lo mire una persona en el
     * visor y la tarea no lo aplica.
     */
    public function mismoVehiculo(?string $placaFicha, ?string $serialFicha, array $leido): string
    {
        // BASTA QUE UNO COINCIDA: en un escaneo sucio la placa puede salir mal leida ("A85DRIX"
        // por "A85DR1K") mientras el serial sale perfecto, y al reves. Solo se dice que no es
        // de este vehiculo cuando ninguno coincide y al menos uno se pudo leer.
        //
        // Se mira lo que va detras del rotulo y TAMBIEN todo lo que parezca placa o serial en
        // la hoja: hay polizas que ponen los rotulos en un bloque y los valores en otro, y ahi
        // la unica manera de reconocer el vehiculo es encontrar su placa suelta en el texto.
        // MANDA LO QUE VA TRAS EL ROTULO ("Placa: ...", "Serial de Carroceria: ..."), que es
        // donde el documento dice de quien es. Si eso nombra a otro vehiculo, es de otro: y
        // punto, aunque la placa de esta ficha aparezca suelta en alguna linea (pasa en las
        // polizas de flota, que listan varias unidades).
        $rotulo = [
            $this->mismoCodigo($placaFicha, $leido['placa'] ?? null),
            $this->mismoCodigo($serialFicha, $leido['serial'] ?? null),
        ];
        if (in_array('si', $rotulo, true)) return 'si';
        if (in_array('no', $rotulo, true)) return 'no';

        // Solo cuando el rotulo no se pudo leer se miran las listas de todo lo que parece
        // placa o serial en la hoja, y SOLO para confirmar: recogen cualquier codigo ("3500KG")
        // y casi siempre traen algo, asi que su "no lo encuentro" no puede acusar al archivo de
        // ser de otro vehiculo — eso mandaria a una persona a arreglar un enlace que esta bien.
        if ($this->codigoEnLista($placaFicha, $leido['placas'] ?? []) === 'si'
            || $this->codigoEnLista($serialFicha, $leido['seriales'] ?? []) === 'si') {
            return 'si';
        }
        // Excepcion: la tabla de una poliza de FLOTA no es ruido suelto, es la lista de los
        // equipos que ampara. Si el serial de la ficha (de 17, como los de la tabla) no esta
        // en ella —con la misma tolerancia de siempre, ver codigo()—, el documento es de
        // OTROS equipos. Sin seriales de carroceria en la tabla (escaneo malo, o una tabla
        // con seriales de motor) no se afirma nada: queda "no se sabe" para mirarlo a mano.
        if (($leido['flota'] ?? false) && strlen((string) $serialFicha) === 17 && !empty($leido['seriales_flota'])) {
            return $this->codigoEnLista($serialFicha, $leido['seriales_flota']) === 'si' ? 'si' : 'no';
        }
        return 'no_se_sabe';
    }

    /**
     * Placas o seriales iguales aunque uno lleve guion o espacios ("A85-DR1K" = "A85DR1K").
     * Las parejas que el reconocimiento confunde de verdad en una foto (O con 0, I con 1, S
     * con 5) cuentan como iguales: si no, media flota saldria avisada de "es de otro vehiculo"
     * por un cero. No mas que esas (y las letras de otro alfabeto identicas a las nuestras, ver
     * codigo()), y con el mismo largo: cada letra que se confunde a proposito le quita puntería
     * a la unica comprobacion que protege la ficha.
     */
    private function mismoCodigo(?string $enFicha, ?string $enDocumento): string
    {
        if (!$enFicha || !$enDocumento) return 'no_se_sabe';
        if ($this->codigo($enFicha) === $this->codigo($enDocumento)) return 'si';
        // Casi igual por un fallo del escaneo: no se afirma que sea de OTRO vehiculo (eso bloquea
        // la ficha entera); se queda sin confirmar, que solo deja poner las fechas vacias.
        return $this->malLeido($enFicha, $enDocumento) ? 'no_se_sabe' : 'no';
    }

    /**
     * ¿El codigo del documento es el de la ficha con UNA o DOS letras mal leidas por el escaneo?
     * (visto el 21-09-2026: "LSFAM1115PA..." por "LSFAM11H5PA...", "A90ARSO" por "A90AR5G",
     * "A09CVO" por "A09CV0M", "ELJ11KFBD9H..." por "LJ11KFBD9H...").
     *
     * Solo cuentan las confusiones de FORMA de LETRAS_PARECIDAS (G/0, H/1, B/8, R/P...) y un
     * caracter de mas o de menos en una punta: DELANTE en un serial (la mancha pegada), en
     * cualquiera de las dos en una placa (la ultima letra que no se leyo). NUNCA una cifra por
     * otra cifra: es justo lo que separa a los vehiculos hermanos de una flota ("A90BE0R" y
     * "A90BE2R", "...HRG5S0000014" y "...HRG0S0000017"), y por lo mismo tampoco se admite que al
     * serial le falte el FINAL (casaria con dos hermanos a la vez). Confundirlos pondria en una
     * ficha las fechas del documento de otro. Hasta 2 en un serial (14+ caracteres), 1 en una
     * placa.
     */
    private function malLeido(string $enFicha, string $enDocumento): bool
    {
        $limpio = fn (string $v) => preg_replace('/[^A-Z0-9]/', '', strtr(mb_strtoupper($v), self::HOMOGLIFOS));
        $a = $limpio($enFicha);
        $b = $limpio($enDocumento);
        $tope = max(strlen($a), strlen($b)) >= 14 ? 2 : 1;

        // Un caracter de mas o de menos en una punta: quitado, tiene que quedar el mismo codigo.
        // En un serial solo DELANTE (ver arriba).
        if (abs(strlen($a) - strlen($b)) === 1) {
            [$largo, $corto] = strlen($a) > strlen($b) ? [$a, $b] : [$b, $a];
            return $this->codigo(substr($largo, 1)) === $this->codigo($corto)
                || ($tope === 1 && $this->codigo(substr($largo, 0, -1)) === $this->codigo($corto));
        }
        if (strlen($a) !== strlen($b)) return false;

        $fallos = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $x = $a[$i];
            $y = $b[$i];
            if ($this->codigo($x) === $this->codigo($y)) continue;
            // La pareja tal como viene ("S" por "8") o ya con O, I, S como 0, 1, 5 (la O de
            // "A90ARSO" hace de cero frente a una G). Lo que no esta en la lista —cifra por
            // cifra, u otra letra cualquiera— es otro codigo.
            $parecidas = function (string $p, string $q) {
                return in_array($p . $q, self::LETRAS_PARECIDAS, true) || in_array($q . $p, self::LETRAS_PARECIDAS, true);
            };
            if (!$parecidas($x, $y) && !$parecidas($this->codigo($x), $this->codigo($y))) return false;
            if (++$fallos > $tope) return false;
        }
        return true;
    }

    /**
     * Una placa o serial listo para comparar: en MAYUSCULAS, sin guiones ni espacios, las
     * letras de otro alfabeto que son IGUALES a las nuestras (HOMOGLIFOS) pasadas a latinas, y
     * O, I, S como 0, 1, 5 (lo que confunde el reconocimiento). Una Н cirilica tecleada por
     * error no es otra letra: sin esto el vehiculo no se encontraba en sus propios documentos.
     * Publica: la carga masiva busca el equipo con la misma tolerancia (ver
     * CargaMasivaDocumentos::sqlCodigo, que la repite en SQL).
     */
    public function codigo(string $p): string
    {
        return strtr(preg_replace('/[^A-Z0-9]/', '', strtr(mb_strtoupper($p), self::HOMOGLIFOS)),
            ['O' => '0', 'I' => '1', 'S' => '5']);
    }

    /** Para comparar: sin acentos, sin puntuacion, sin letras de otro alfabeto y en MAYUSCULAS. */
    public function normalizar(?string $v): string
    {
        $v = strtr(mb_strtoupper(trim((string) $v)), self::HOMOGLIFOS);
        $v = strtr($v, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', $v)));
    }

    /** dd/mm/aaaa (o con - o .) a 'aaaa-mm-dd'. Un año de dos cifras se entiende como 20xx. */
    public function fecha(?string $v): ?string
    {
        if (!$v || !preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', trim($v), $m)) return null;
        [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        if ($a < 100) $a += 2000;
        return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : null;
    }

    /** "3", "OCTUBRE", "2018" -> '2018-10-03'. */
    private function fechaDeMes(string $dia, string $mes, string $anio): ?string
    {
        $m = self::MESES[$this->normalizar($mes)] ?? null;
        if (!$m) return null;
        return checkdate($m, (int) $dia, (int) $anio) ? sprintf('%04d-%02d-%02d', (int) $anio, $m, (int) $dia) : null;
    }

    /**
     * El nombre tal cual lo trae el documento, sin lo que el reconocimiento pega alrededor: el
     * siguiente rotulo de la hoja al final, y al principio las manchas y sellos del escaneo,
     * que salen en minusculas ("nab CONSTRUCTORA VIDALSA 27, C.A") mientras el nombre va
     * siempre en mayusculas. Recortado a lo que cabe en la ficha.
     */
    private function limpiarNombre(string $v): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v));
        // Rotulo pegado DELANTE del nombre: se quita el rotulo, no el nombre. El \b evita
        // que "GRIFERIA" pierda todo por empezar como "RIF".
        // Primero el rotulo pegado SIN nada que lo separe, que es como sale del reconocimiento:
        // "Cédula o RIFCORPO NAC DE LOGISTICA Y TRANSPORTE DECARGA S.A" es el rotulo mas el
        // nombre, y asi tal cual se habria escrito en la ficha. Va antes que la regla de abajo
        // porque esa, al no encontrar el limite de palabra tras "RIF", se queda en "Cédula" y
        // deja el "RIF" pegado. Se exige que lo que queda siga siendo un nombre (dos palabras
        // o mas) para no desarmar uno que de verdad empiece por esas tres letras.
        $v = preg_replace('/^\s*(?:C[eé]dula\s*o\s*)?RIF(?=[A-ZÁÉÍÓÚÑ]{3,}\s+\S)/ui', '', $v);
        $v = preg_replace('/^\s*(C[eé]dula\s*o\s*RIF|C[eé]dula|RIF|Placa|Serial)\b\s*:?\s*/ui', '', $v);
        $v = preg_replace('/\s+(C[eé]dula|RIF|Placa|Serial|N\.?I\.?V)\b.*/ui', '', $v);
        $palabras = explode(' ', trim($v, " \t.:,-"));
        while (count($palabras) > 1 && !preg_match('/\p{Lu}/u', $palabras[0])) {
            array_shift($palabras);
        }
        $nombre = trim(implode(' ', $palabras), " \t.:,-");
        // Y la mancha pegada DETRAS, tambien en minusculas y sin espacio que la separe: en los
        // volteos IVECO el escaneo dice "CONTRUCTORA VIDALSA 27, C.Aaca:" (medido el 18-09-2026)
        // y, pasado a mayusculas, habria llegado a la ficha como "C.AACA". Solo cuando todo lo
        // demas va en MAYUSCULAS, como en los titulos: un nombre escrito normal ("Vidalsa") no
        // pierde su final.
        if (preg_match('/^(.*\p{Lu}[.)]?)(\p{Ll}+)$/u', $nombre, $m) && $m[1] === mb_strtoupper($m[1])) {
            $nombre = $m[1];
        }
        return mb_substr($this->canonico(mb_strtoupper(trim($nombre, " \t.:,-"))), 0, self::LARGO_TITULAR);
    }

    /**
     * El nombre en su forma elegida si es una de las formas de CONSTRUCTORA VIDALSA 27 (ver
     * RE_VIDALSA); si no, tal cual. Lo usan el lector —asi la tarea escribe siempre la misma
     * forma— y el comparador —asi "CONTRUCTORA" y "CONSTRUCTORA" son el mismo nombre y la
     * tarea no reescribe la ficha por una errata del INTT (decision del cliente, 18-09-2026)—.
     */
    public function canonico(?string $nombre): ?string
    {
        if ($nombre === null) return null;
        return preg_match(self::RE_VIDALSA, str_replace(' ', '', $this->normalizar($nombre))) ? self::VIDALSA : $nombre;
    }

    private function tieneHomoglifos(string $v): bool
    {
        foreach (array_keys(self::HOMOGLIFOS) as $letra) {
            if (str_contains($v, $letra)) return true;
        }
        return false;
    }
}
