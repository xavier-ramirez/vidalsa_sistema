<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Compresion de PDF con Ghostscript, y las comprobaciones para saber si el resultado se
 * puede usar en lugar del original. Lo usa el comando docs:comprimir.
 *
 * Solo Ghostscript, a proposito: comprime, cuenta paginas, extrae el texto y dibuja las
 * paginas para compararlas. En el servidor es un unico paquete del sistema (Dockerfile) y
 * no hace falta Python ni nada mas. Los ajustes son los probados a mano el 10-09-2026 sobre
 * polizas, compraventas y certificados: imagenes a 150 dpi en JPEG al 75 %, lo que hace
 * PDF24 de forma parecida.
 */
class CompresorPdf
{
    /** Tope por llamada a Ghostscript: un PDF de 11 MB tarda ~1 s; esto es un cuelgue. */
    private const TIEMPO_MAX_S = 300;

    private string $bin;

    public function __construct(?string $bin = null)
    {
        $this->bin = $bin ?? (string) config('services.compresion_pdf.ghostscript', 'gs');
    }

    /** ¿Esta Ghostscript instalado y responde? */
    public function disponible(): bool
    {
        try {
            $p = new Process([$this->bin, '--version']);
            $p->setTimeout(20)->run();
            return $p->isSuccessful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Comprime $origen en $destino. Lanza si Ghostscript falla. */
    public function comprimir(string $origen, string $destino): void
    {
        $this->correr([
            '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.5', '-dDetectDuplicateImages=true',
            '-dDownsampleColorImages=true', '-dDownsampleGrayImages=true', '-dDownsampleMonoImages=true',
            '-dColorImageResolution=150', '-dGrayImageResolution=150', '-dMonoImageResolution=300',
            // 1.1: solo se reduce lo que pasa de ~165 dpi; lo que ya esta cerca se deja.
            '-dColorImageDownsampleThreshold=1.1', '-dGrayImageDownsampleThreshold=1.1',
            '-dAutoFilterColorImages=false', '-sColorImageFilter=DCTEncode',
            '-dAutoFilterGrayImages=false', '-sGrayImageFilter=DCTEncode', '-dJPEGQ=75',
            '-sOutputFile=' . $destino, $origen,
        ]);
    }

    /** Numero de paginas. */
    public function paginas(string $pdf): int
    {
        // runpdfbegin necesita leer el archivo fuera de -dSAFER: se permite SOLO ese.
        $salida = $this->correr([
            '-q', '-dNODISPLAY', '-dNOSAFER', '--permit-file-read=' . $pdf,
            '-c', '(' . $this->rutaPostScript($pdf) . ') (r) file runpdfbegin pdfpagecount = quit',
        ]);
        return (int) trim($salida);
    }

    /**
     * Cuantas palabras tiene el texto del comprimido que NO estaban en el original. Tiene
     * que ser 0. Es el sintoma del daño conocido: con algunas fuentes Ghostscript pierde el
     * mapa de las ligaduras (rt, fi) y "Reporte" pasa a "Repo8e" — se ve igual, pero buscar
     * y copiar dejan de funcionar.
     *
     * Palabras NUEVAS y no "texto distinto": al reescribir el PDF, Ghostscript tira el texto
     * escondido que no se ve en la pagina (los ROTC del INTT arrastran en su plantilla los
     * datos de OTRO vehiculo). Eso es texto que desaparece, no que se estropea, y con una
     * comparacion estricta se saltaban sin motivo. Un escaneo no tiene texto: da 0.
     */
    public function palabrasNuevas(string $original, string $comprimido): int
    {
        $antes = array_flip($this->palabras($original));
        return count(array_filter(array_unique($this->palabras($comprimido)), fn ($w) => !isset($antes[$w])));
    }

    /**
     * Cuanto cambia lo que SE VE: se dibujan las paginas de los dos en gris a 30 dpi y se
     * compara pixel a pixel. Devuelve la peor pagina, en % (0 = identicas). Es la red por
     * si la compresion perdiera algo visible que la comparacion de palabras no ve (una
     * tabla, un sello). La perdida normal de un JPEG al 75 % da 1-2 %.
     */
    public function diferenciaVisual(string $original, string $comprimido): float
    {
        $a = $this->paginasEnGris($original);
        $b = $this->paginasEnGris($comprimido);
        if (count($a) !== count($b)) return 100.0;
        $peor = 0.0;
        foreach ($a as $i => [$w, $h, $pixA]) {
            [$w2, $h2, $pixB] = $b[$i];
            if ($w !== $w2 || $h !== $h2) return 100.0;
            $suma = 0;
            $n = strlen($pixA);
            for ($k = 0; $k < $n; $k++) $suma += abs(ord($pixA[$k]) - ord($pixB[$k]));
            $peor = max($peor, $n ? $suma / $n / 255 * 100 : 0);
        }
        return $peor;
    }

    /** Palabras del texto del PDF (lo que extrae Ghostscript). */
    private function palabras(string $pdf): array
    {
        $texto = $this->correr(['-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=txtwrite', '-sOutputFile=-', $pdf]);
        return preg_split('/\s+/u', trim($texto), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /** Cada pagina dibujada en gris a 30 dpi: [ancho, alto, pixeles] (formato PGM binario). */
    private function paginasEnGris(string $pdf): array
    {
        $raw = $this->correr(['-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pgmraw', '-r30', '-sOutputFile=-', $pdf]);
        $paginas = [];
        $i = 0;
        $largo = strlen($raw);
        while ($i < $largo) {
            // Cabecera "P5 <ancho> <alto> 255" + un espacio; puede traer comentarios "# ...".
            $campos = [];
            while (count($campos) < 4) {
                while ($i < $largo && ctype_space($raw[$i])) $i++;
                if ($i < $largo && $raw[$i] === '#') { $i = strpos($raw, "\n", $i) + 1; continue; }
                $j = $i;
                while ($j < $largo && !ctype_space($raw[$j])) $j++;
                $campos[] = substr($raw, $i, $j - $i);
                $i = $j;
            }
            $i++;
            [$w, $h] = [(int) $campos[1], (int) $campos[2]];
            $paginas[] = [$w, $h, substr($raw, $i, $w * $h)];
            $i += $w * $h;
        }
        return $paginas;
    }

    /**
     * ¿Lleva firma digital? Comprimirlo la invalidaria. Se mira el archivo tal cual: una
     * firma siempre deja un /ByteRange (lo firmado) o un campo /Sig.
     */
    public function tieneFirmaDigital(string $pdf): bool
    {
        $f = fopen($pdf, 'rb');
        if (!$f) return false;
        $cola = '';
        try {
            while (!feof($f)) {
                $trozo = $cola . fread($f, 1048576);
                if (preg_match('#/ByteRange\s*\[|/FT\s*/Sig\b|/Type\s*/Sig\b#', $trozo)) return true;
                $cola = substr($trozo, -32);   // por si la marca cae entre dos trozos
            }
            return false;
        } finally {
            fclose($f);
        }
    }

    private function correr(array $args): string
    {
        $p = new Process(array_merge([$this->bin], $args));
        $p->setTimeout(self::TIEMPO_MAX_S)->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException('Ghostscript fallo: ' . trim(mb_substr($p->getErrorOutput() ?: $p->getOutput(), 0, 200)));
        }
        return $p->getOutput();
    }

    /** Ruta como cadena PostScript: barras normales y parentesis/backslash escapados. */
    private function rutaPostScript(string $ruta): string
    {
        return strtr(str_replace('\\', '/', $ruta), ['(' => '\\(', ')' => '\\)']);
    }
}
