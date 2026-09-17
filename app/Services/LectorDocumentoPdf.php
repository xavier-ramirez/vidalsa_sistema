<?php

namespace App\Services;

use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

/**
 * Lee lo que dicen los PDF de la documentacion de un equipo y lo compara con su ficha
 * (tabla documentacion). Lo usa docs:verificar-documentos para dos documentos:
 *
 *   · TITULO DE PROPIEDAD (LINK_DOC_PROPIEDAD) — nombre del propietario y fecha de emision.
 *   · POLIZA DE SEGURO   (LINK_POLIZA_SEGURO)  — aseguradora, fecha de vencimiento y de emision.
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
    /** Los dos documentos que se verifican. */
    public const PROPIEDAD = 'propiedad';
    public const POLIZA    = 'poliza';

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

    /** documentacion.NOMBRE_DEL_TITULAR es varchar(150): lo leido se recorta a esa medida. */
    private const LARGO_TITULAR = 150;

    /**
     * Letras de otros alfabetos que se ven IGUAL que las nuestras. Aparecen al pegar el
     * nombre desde otro programa y dejan la ficha inencontrable al buscarla: "С.А" con C y A
     * cirilicas no es "C.A". Se traducen para poder comparar, y si solo diferian en eso se
     * avisa igual (ver comparar()).
     */
    private const HOMOGLIFOS = [
        'А'=>'A','В'=>'B','С'=>'C','Е'=>'E','Н'=>'H','І'=>'I','Ј'=>'J','К'=>'K','М'=>'M','О'=>'O',
        'Р'=>'P','Ѕ'=>'S','Т'=>'T','Х'=>'X','У'=>'Y','Ζ'=>'Z','Α'=>'A','Β'=>'B','Ε'=>'E','Η'=>'H',
        'Ι'=>'I','Κ'=>'K','Μ'=>'M','Ν'=>'N','Ο'=>'O','Ρ'=>'P','Τ'=>'T','Υ'=>'Y','Χ'=>'X',
    ];

    private const MESES = [
        'ENERO'=>1,'FEBRERO'=>2,'MARZO'=>3,'ABRIL'=>4,'MAYO'=>5,'JUNIO'=>6,
        'JULIO'=>7,'AGOSTO'=>8,'SEPTIEMBRE'=>9,'SETIEMBRE'=>9,'OCTUBRE'=>10,'NOVIEMBRE'=>11,'DICIEMBRE'=>12,
    ];

    /**
     * Texto del PDF de Drive. Devuelve '' si Drive no lo reconocio (la copia sale vacia).
     * La copia temporal se borra SIEMPRE, incluso si la exportacion falla: si no, quedarian
     * documentos sueltos ocupando el Drive de la empresa.
     */
    public function texto(string $driveId): string
    {
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

    /** Lo que dice el documento. Las claves vacias son "no se encontro". */
    public function extraer(string $tipo, string $texto): array
    {
        $plano = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', $texto)));
        return $tipo === self::POLIZA ? $this->extraerPoliza($plano) : $this->extraerPropiedad($plano);
    }

    /**
     * Titulo de propiedad (Certificado de Registro de Vehiculo del INTT): propietario, placa,
     * numero de documento y la fecha en que se emitio ("Dado a los 3 dias del mes de OCTUBRE
     * de 2018").
     */
    private function extraerPropiedad(string $plano): array
    {
        $datos = ['titular' => null, 'placa' => null, 'nro' => null, 'emision' => null];

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
        if (preg_match('/Placa:?\s*([A-Z0-9]{5,8})\b/ui', $plano, $m)) {
            $datos['placa'] = mb_strtoupper($m[1]);
        }
        if (preg_match('/\b(\d{12})\b/', $plano, $m)) {
            $datos['nro'] = $m[1];
        }
        if (preg_match('/Dado\s+a\s+los:?\s*(\d{1,2})\D{1,40}?de:?\s*([A-ZÁÉÍÓÚa-záéíóú]{4,12})\D{0,12}(\d{4})/ui', $plano, $m)) {
            $datos['emision'] = $this->fechaDeMes($m[1], $m[2], $m[3]);
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
        $datos = ['aseguradora' => null, 'nro' => null, 'desde' => null, 'vence' => null, 'emision' => null, 'placa' => null];

        // La vigencia viene de dos maneras segun la aseguradora: "Desde X Hasta Y" (Mampreca)
        // o "Vigencia del Seguro: X al Y" (Piramide). El reconocimiento parte la tabla, asi que
        // el rotulo y sus fechas pueden quedar en lineas distintas: se busca la PAREJA de fechas.
        $f = '(\d{1,2}[\/\-\.]\d{1,2}[\/\-\.]\d{2,4})';
        if (preg_match('/Desde\s*' . $f . '\s*Hasta\s*' . $f . '/ui', $plano, $m)
            || preg_match('/Vigencia[^\r\n]{0,45}\R?[^\d\r\n]{0,15}' . $f . '\s*(?:al|a|hasta)\s*' . $f . '/ui', $plano, $m)) {
            $datos['desde'] = $this->fecha($m[1]);
            $datos['vence'] = $this->fecha($m[2]);
        }
        // La emision solo se toma si esta pegada a su rotulo: en las hojas donde el
        // reconocimiento la separa, cualquier otra fecha de la pagina ocuparia su lugar.
        if (preg_match('/Fecha\s*(?:de\s*)?Emisi[oó]n:?\s*' . $f . '/ui', $plano, $m)) {
            $datos['emision'] = $this->fecha($m[1]);
        }
        // El numero de poliza lleva digitos; sin exigirlos, "Cuadro Poliza Recibo" daba "RECIBO".
        if (preg_match('/P[oó]liza\s*(?:Nro|N[°ºo]|Numero|N[uú]mero)?\.?:?\s*([A-Z0-9][A-Z0-9\-\.\/]{5,38})/ui', $plano, $m)
            && preg_match('/\d{3}/', $m[1])) {
            $datos['nro'] = mb_strtoupper(trim($m[1], " .-/"));
        }
        if (preg_match('/Placa:?\s*([A-Z0-9]{5,8})\b/ui', $plano, $m)) {
            $datos['placa'] = mb_strtoupper($m[1]);
        }
        return $datos;
    }

    /**
     * La aseguradora del catalogo cuyo nombre aparece en el texto. $catalogo es
     * [ID_SEGURO => NOMBRE_ASEGURADORA]. Se busca por la palabra mas larga del nombre
     * ("PIRAMIDE" de "PIRÁMIDE SEGUROS"), que es la que no comparten entre si.
     */
    public function aseguradoraEnTexto(string $texto, array $catalogo): ?int
    {
        $plano = $this->normalizar($texto);
        foreach ($catalogo as $id => $nombre) {
            $palabras = array_filter(explode(' ', $this->normalizar($nombre)),
                fn ($p) => mb_strlen($p) >= 5 && !in_array($p, ['SEGUROS', 'SEGURO', 'POSEE', 'DOCUMENTO'], true));
            if (!$palabras) continue;
            usort($palabras, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
            if (str_contains($plano, $palabras[0])) return (int) $id;
        }
        return null;
    }

    /**
     * Compara un nombre de la ficha con el del documento. Devuelve [iguales, motivo, sirve]:
     * el motivo explica en que se diferencian, para decidir sin abrir el PDF, y `sirve` dice
     * si lo leido se le puede poner a la ficha (false cuando el documento se leyo a medias).
     */
    public function compararNombre(?string $enFicha, ?string $enDocumento): array
    {
        $doc = $this->normalizar($enDocumento);
        $ficha = $this->normalizar($enFicha);
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
     * Placas iguales aunque una lleve guion o espacios ("A85-DR1K" = "A85DR1K"). Las parejas
     * que el reconocimiento confunde de verdad en una foto (O con 0, I con 1, S con 5) cuentan
     * como iguales: si no, media flota saldria avisada de "el documento es de otra placa" por
     * un cero. No mas que esas: esta comparacion es lo UNICO que impide copiarle a una ficha
     * los datos del documento de otro vehiculo, y cada letra que se confunde a proposito le
     * quita puntería. Placas de distinto largo son siempre distintas.
     */
    public function mismaPlaca(?string $a, ?string $b): bool
    {
        if (!$a || !$b) return true;   // sin placa que comparar no se afirma nada
        $limpia = fn ($p) => strtr(preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($p)),
            ['O' => '0', 'I' => '1', 'S' => '5']);
        [$x, $y] = [$limpia($a), $limpia($b)];
        return mb_strlen($x) === mb_strlen($y) && $x === $y;
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
        $v = preg_replace('/^\s*(C[eé]dula\s*o\s*RIF|C[eé]dula|RIF|Placa|Serial)\b\s*:?\s*/ui', '', $v);
        $v = preg_replace('/\s+(C[eé]dula|RIF|Placa|Serial|N\.?I\.?V)\b.*/ui', '', $v);
        $palabras = explode(' ', trim($v, " \t.:,-"));
        while (count($palabras) > 1 && !preg_match('/\p{Lu}/u', $palabras[0])) {
            array_shift($palabras);
        }
        return mb_substr(mb_strtoupper(trim(implode(' ', $palabras), " \t.:,-")), 0, self::LARGO_TITULAR);
    }

    private function tieneHomoglifos(string $v): bool
    {
        foreach (array_keys(self::HOMOGLIFOS) as $letra) {
            if (str_contains($v, $letra)) return true;
        }
        return false;
    }
}
