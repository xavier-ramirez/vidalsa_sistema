<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast Eloquent: decodea UTF-8 doble-encoded (mojibake) al LEER un atributo.
 *
 * Caso de uso: muchos campos de texto en la BD (NOMBRE_FRENTE, PRODUCTO.NOMBRE,
 * MOTIVO, etc.) traen caracteres tipo "ASIGNACIÃ"N UPATA" en lugar de
 * "ASIGNACIÓN UPATA" porque fueron insertados originalmente como UTF-8
 * interpretado como Latin-1 y re-encodeados a UTF-8 (clasico bug de migracion
 * de datos legacy o import desde Excel mal configurado).
 *
 * Aplicar este cast en el modelo hace que la decodificacion ocurra in-memory
 * cada vez que se LEE el atributo — vistas, JSON, PDFs, dumps de tinker, todo
 * ve el texto correcto sin tener que llamar a un helper manualmente en cada
 * punto de salida.
 *
 * Comportamiento:
 *  - get(): aplica heuristica de mojibake (regex Ã[\x80-\xBF] = firma UTF-8
 *    doble-encoded en caracteres latinos). Si matchea Y la conversion produce
 *    UTF-8 valido, devuelve el decodificado. Si no matchea o la conversion
 *    falla, devuelve el original — los strings ya correctos NO se tocan jamas.
 *  - set(): passthrough. Confiamos que los inputs del navegador moderno son
 *    UTF-8 validos. Si una importacion batch mete mojibake nuevo, el get() lo
 *    decodea igual al leerlo.
 *
 * Cero impacto en strings limpios: la regex no matchea, retorna inmediato.
 * Centraliza toda la logica de detecccion/correccion de mojibake del proyecto —
 * los modelos usan el cast en sus $casts, el codigo no-Eloquent usa el helper
 * estatico fix(). Una sola implementacion, sin duplicar.
 */
class MojibakeFix implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return self::fix($value === null ? null : (string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value;
    }

    /**
     * Helper publico estatico: decodea mojibake en cualquier string (no requiere
     * estar atado a un modelo Eloquent). Util para fixear strings que vienen del
     * request o de arrays construidos en memoria, donde el cast del modelo no
     * aplica. Misma logica que get(): unico punto donde vive el algoritmo.
     *
     * Detalle del bug que arregla — cuando bytes UTF-8 originales se interpretaron
     * como Windows-1252 y se re-encodearon como UTF-8, "Ó" (c3 93) se convierte en
     * "Ã"" (c3 83 e2 80 9c) porque 0x93 en Windows-1252 es la LEFT DOUBLE QUOTE.
     * La regex captura los DOS patrones posibles:
     *   1. "Ã" + byte latin extended  → caso simple (acentos a/e/i/o/u con tilde).
     *   2. "Ã" + comilla tipografica  → caso Windows-1252 punctuation (smart quotes,
     *      em/en dashes, etc. que viven en 0x80-0x9F en CP1252).
     * Si matchea, convertimos UTF-8 → Windows-1252 (NO ISO-8859-1: Windows-1252
     * SI mapea los smart quotes) y reinterpretamos esos bytes como UTF-8.
     */
    public static function fix(?string $s): ?string
    {
        if ($s === null || $s === '') return $s;
        // "Ã" en UTF-8 son los bytes c3 83. Si aparece, casi siempre es mojibake
        // (el caracter "Ã" solo legitimamente aparece en palabras portuguesas tipo
        // "São Paulo" — poco frecuente en nuestro contexto). Despues de "Ã" puede
        // venir cualquier patron: byte simple latin-extended (acentos comunes
        // tipo Ó/Ñ/Á), smart quote multi-byte (e2 80 9X de Windows-1252 0x91-0x94),
        // o letra con caron/cedilla multi-byte (c4/c5 + xx de Windows-1252 0x8A/0x9A).
        // Por eso solo chequeamos "c3 83" como signature y dejamos que la conversion
        // + el mb_check_encoding hagan de filtro final: si la conversion no produce
        // UTF-8 valido el cast devuelve el original (defensivo).
        if (!preg_match('/\xc3\x83/', $s)) {
            return $s;
        }
        $decoded = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return $s;
        }
        return $decoded;
    }
}
