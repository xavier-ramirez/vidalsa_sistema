<?php

namespace App\Services\Consumibles;

use Illuminate\Support\Str;

/**
 * Normalización de los textos que llegan en los Excel de combustible. Una sola definición
 * para el lector, la identificación y las huellas: si cada uno normalizara a su manera, el
 * mismo texto daría claves distintas y un despacho ya cargado parecería nuevo.
 */
final class TextoConsumo
{
    /** Mayúsculas, sin acentos, espacios simples. «Tubería  12''» → «TUBERIA 12''». */
    public static function norm(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = Str::ascii((string) $v);

        return trim(preg_replace('/\s+/', ' ', mb_strtoupper($s)));
    }

    /** Solo letras y dígitos: «A45-AF4Y» → «A45AF4Y». */
    public static function clave(mixed $v): string
    {
        return preg_replace('/[^A-Z0-9]/', '', self::norm($v));
    }

    /**
     * Clave de un identificador (placa, serial, código). La letra O y el cero se confunden
     * al transcribir («A95BGOR» / «A95BG0R» son la misma placa en dos versiones del mismo
     * Excel), así que se comparan como iguales. Se aplica igual a los dos lados.
     */
    public static function claveId(mixed $v): string
    {
        return str_replace('O', '0', self::clave($v));
    }

    /** Solo dígitos (cédulas: «V-12.151.320» → «12151320»). */
    public static function digitos(mixed $v): string
    {
        if (is_float($v) && floor($v) === $v) {
            $v = (string) (int) $v;
        }

        return preg_replace('/\D/', '', (string) $v);
    }

    /** Texto sin lo que va entre paréntesis: «CORTA FUEGO ( BARE )» → «CORTA FUEGO». */
    public static function base(mixed $v): string
    {
        $s = preg_replace('/\(.*?\)|\(.*$/', ' ', self::norm($v));

        return trim(preg_replace('/\s+/', ' ', $s), " ,.-;:");
    }

    /** Lo que va entre paréntesis, cada trozo por separado. */
    public static function parentesis(mixed $v): array
    {
        preg_match_all('/\(([^)]*)\)?/', self::norm($v), $m);

        return array_values(array_filter(array_map(fn ($t) => trim($t, " ,.-;:"), $m[1] ?? [])));
    }
}
