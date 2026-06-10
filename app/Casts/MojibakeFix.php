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
     * Arregla DOS clases de corrupcion distintas (ver detalle en el cuerpo):
     *   CASO 1 — doble-UTF-8 ("Ã…"): bytes UTF-8 leidos como Windows-1252 y
     *            re-encodeados (ej "Ó" c3 93 → "Ã"" c3 83 e2 80 9c). Firma c3 83.
     *            Se revierte UTF-8 → Windows-1252.
     *   CASO 2 — import CP850 ("MATUR═N"): bytes Windows-1252 leidos como CP850
     *            (OEM) al correr un .sql, convirtiendo acentos en caracteres de
     *            dibujo de caja (Í→═, Á→┴, Ú→┌, Ñ→╤, É→╔, Ó→Ë). Firma: presencia
     *            de un char U+2500–U+257F. Se revierte UTF-8 → CP850 → Windows-1252.
     * En ambos casos, si la conversion no produce UTF-8 valido se devuelve el
     * original (defensivo), y los strings limpios NUNCA se tocan.
     */
    public static function fix(?string $s): ?string
    {
        if ($s === null || $s === '') return $s;

        // ── Caso 1: doble-UTF-8 ("Ã…") — firma c3 83 ─────────────────────────
        // "Ã" en UTF-8 son los bytes c3 83. Si aparece, casi siempre es mojibake
        // (bytes UTF-8 leidos como Windows-1252 y re-encodeados). Convertimos de
        // vuelta UTF-8→Windows-1252; el mb_check_encoding es el filtro final: si
        // no produce UTF-8 valido devolvemos el original (defensivo).
        if (preg_match('/\xc3\x83/', $s)) {
            $decoded = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
            if ($decoded !== false && mb_check_encoding($decoded, 'UTF-8')) {
                return $decoded;
            }
            return $s;
        }

        // ── Caso 2: import CP850 — acentos convertidos a otros caracteres ────
        // Pasa cuando bytes Windows-1252 se leen como CP850 (OEM) al correr un
        // .sql de importacion. La MAYORIA de acentos mayusculos caen en chars de
        // dibujo de caja (Í→═, Á→┴, Ú→┌, É→╔ …; U+2500–U+257F), que NUNCA aparecen
        // en datos legitimos. PERO dos NO son de caja y se escapaban a la deteccion
        // vieja: Ó→Ë (U+00CB) y Ñ→Ð (U+00D0) — tampoco existen en el español de la
        // app, asi que se incluyen explicitamente. Un solo pase UTF-8→CP850→Win-1252
        // corrige TODA la cadena (mezcle o no chars de caja con Ë/Ð). Si la conversion
        // no da UTF-8 valido se devuelve el original (defensivo); los strings limpios
        // NO entran aqui — quedan intactos.
        if (preg_match('/[\x{2500}-\x{257F}\x{00CB}\x{00D0}]/u', $s)) {
            $bytes = @iconv('UTF-8', 'CP850', $s);
            if ($bytes !== false) {
                $rev = @iconv('Windows-1252', 'UTF-8', $bytes);
                if ($rev !== false && mb_check_encoding($rev, 'UTF-8')) {
                    return $rev;
                }
            }
            return $s;
        }

        return $s;
    }
}
