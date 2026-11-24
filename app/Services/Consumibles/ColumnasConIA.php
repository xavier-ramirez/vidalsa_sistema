<?php

namespace App\Services\Consumibles;

use App\Services\LectorGemini;
use Illuminate\Support\Facades\Cache;

/**
 * Respaldo con IA para reconocer las columnas de una tabla de despachos cuando los nombres de los
 * encabezados no dicen lo que las reglas esperan (otro idioma, abreviaturas, columnas de más,
 * títulos raros) o cuando la tabla pegada no trae encabezados.
 *
 * Se le enseñan a la IA (Gemini, con su cupo: LectorGemini::preguntarJson) las primeras filas tal
 * cual y contesta qué fila es el encabezado y en qué columna está cada dato. Su respuesta NO se
 * cree a ciegas: se comprueba con las filas de datos (la columna de fecha tiene fechas, la de
 * cantidad tiene números). Si no cuadra, o no hay clave o cupo, devuelve null y todo sigue como
 * sin IA. La respuesta se guarda por el contenido enseñado: el mismo formato no gasta cupo dos veces.
 */
class ColumnasConIA
{
    /** Filas y columnas que se le enseñan, y largo de cada celda. Basta para ver el formato. */
    private const FILAS = 20;
    private const COLUMNAS = 30;
    private const LARGO_CELDA = 40;
    private const DIAS_RECUERDO = 30;

    public function __construct(private readonly LectorGemini $ia) {}

    public function disponible(): bool
    {
        return $this->ia->disponible();
    }

    /**
     * @param array<int,array<int,mixed>>  $filas        primeras filas, cada una con sus celdas en orden (índice 0 = 1ª columna)
     * @param array<string,string>         $campos       campo => qué es (lo lee la IA)
     * @param string                       $fecha        campo que debe traer fechas
     * @param string                       $cantidad     campo que debe traer números
     * @return array{fila:?int,mapa:array<string,int>}|null  fila del encabezado (índice 0, null = no hay) y campo → índice de columna
     */
    public function reconocer(array $filas, array $campos, string $fecha, string $cantidad): ?array
    {
        $filas = array_values(array_slice($filas, 0, self::FILAS));
        $muestra = [];
        foreach ($filas as $i => $celdas) {
            $txt = [];
            foreach (array_slice(array_values((array) $celdas), 0, self::COLUMNAS) as $c => $v) {
                $txt[] = '[' . ($c + 1) . '] ' . mb_substr(trim($this->texto($v)), 0, self::LARGO_CELDA);
            }
            $muestra[] = 'Fila ' . ($i + 1) . ': ' . implode(' | ', $txt);
        }
        if (count($muestra) < 2) {
            return null;
        }

        $lista = [];
        foreach ($campos as $k => $desc) {
            $lista[] = '- "' . $k . '": ' . $desc;
        }
        $instruccion = "Te doy las primeras filas de una tabla de despachos de combustible o consumibles de una "
            . "constructora en Venezuela, copiada de Excel. Cada celda va con su número de columna entre corchetes. "
            . "Las fechas pueden venir como día/mes/año, con el mes escrito o como número de serie de Excel (p. ej. 45900).\n\n"
            . implode("\n", $muestra) . "\n\n"
            . "Di qué fila es el ENCABEZADO (la de los nombres de las columnas; null si la tabla no trae encabezado) "
            . "y en qué número de columna está cada uno de estos datos (null si no está):\n" . implode("\n", $lista) . "\n\n"
            . "Usa cada columna UNA sola vez. Si hay columna de entrada al tanque y de salida, la cantidad es la SALIDA. "
            . "No tomes por cantidad una hora, un kilometraje, un precio ni un número de nota. "
            . 'Devuelve SOLO un JSON: {"fila_encabezado": número o null, "columnas": {"campo": número o null}}';

        $clave = 'columnas_ia_' . md5($instruccion);
        $r = Cache::get($clave);
        if ($r === null) {
            $r = $this->ia->preguntarJson($instruccion);
            if ($r === null) {
                return null;   // sin clave, sin cupo o sin red: no se recuerda, se puede volver a intentar
            }
            Cache::put($clave, $r, now()->addDays(self::DIAS_RECUERDO));
        }

        return $this->validar($r, $filas, array_keys($campos), $fecha, $cantidad);
    }

    /** La respuesta de la IA, comprobada contra las filas de datos. */
    private function validar(array $r, array $filas, array $campos, string $fecha, string $cantidad): ?array
    {
        $enc = $r['fila_encabezado'] ?? null;
        $enc = is_numeric($enc) && (int) $enc >= 1 && (int) $enc <= count($filas) ? (int) $enc - 1 : null;

        $mapa = [];
        $usadas = [];
        foreach ((array) ($r['columnas'] ?? []) as $campo => $col) {
            if (!in_array($campo, $campos, true) || !is_numeric($col)) {
                continue;
            }
            $col = (int) $col - 1;
            if ($col < 0 || $col >= self::COLUMNAS || isset($usadas[$col])) {
                continue;
            }
            $mapa[$campo] = $col;
            $usadas[$col] = true;
        }
        if (!isset($mapa[$fecha], $mapa[$cantidad])) {
            return null;
        }

        // Las filas de datos: la mayoría con fecha y cantidad donde la IA dijo.
        $datos = array_slice($filas, $enc === null ? 0 : $enc + 1);
        $conAlgo = $buenas = 0;
        foreach ($datos as $celdas) {
            $celdas = array_values((array) $celdas);
            $f = $celdas[$mapa[$fecha]] ?? null;
            $q = $celdas[$mapa[$cantidad]] ?? null;
            if ($this->texto($f) === '' && $this->texto($q) === '') {
                continue;
            }
            $conAlgo++;
            if ($this->pareceFecha($f) && $this->pareceNumero($q)) {
                $buenas++;
            }
        }
        if ($buenas < 1 || $buenas * 2 < $conAlgo) {
            return null;
        }

        return ['fila' => $enc, 'mapa' => $mapa];
    }

    private function pareceFecha(mixed $v): bool
    {
        if ($v instanceof \DateTimeInterface) {
            return true;
        }
        if (is_numeric($v)) {
            return $v > 20000 && $v < 80000;   // número de serie de Excel
        }
        $s = TextoConsumo::norm($this->texto($v));
        // 01/08/2026, 2026-08-01, 1-ago-26, 1 de agosto de 2026 (con o sin hora detrás)
        return (bool) preg_match('/^\d{1,4}[\/\-. ]+(\d{1,2}|[A-Z]{3,})[\/\-. ]+(DE )?\d{2,4}\b/', preg_replace('/\bDE /', '', $s));
    }

    private function pareceNumero(mixed $v): bool
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0;
        }
        $s = str_replace([' ', '.', ','], '', $this->texto($v));
        return $s !== '' && ctype_digit($s) && (int) $s > 0;
    }

    private function texto(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('d/m/Y');
        }
        return is_scalar($v) ? (string) $v : '';
    }
}
