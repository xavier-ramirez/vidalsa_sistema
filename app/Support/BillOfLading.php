<?php

namespace App\Support;

/**
 * Lo que dice el TEXTO de un documento de embarque (Bill of Lading, formato CONGENBILL).
 *
 * Solo lee; quien busca los equipos y enlaza es CargaMasivaDocumentos. Visto con los BL reales
 * del 2do embarque (HCLKGT01, 03, 08, 14 — 30-09-2026), todos PDF con texto:
 *
 *   B/L NO. HCLKGT08                       <- numero ("BL NO.:HCLKGT08" / "BL NO..HCLKGT14" en el anexo)
 *   Port of loading
 *   HONCHO   V2512                         <- buque (a veces partido: "HONCHO" / "V.2512")
 *   LONGKOU,CHINA                          <- puerto de carga (la ultima linea antes de...)
 *   Port of discharge
 *   GUANTA, VENEZUELA                      <- puerto de descarga
 *   Place and date of issue
 *   GUANTA, VENEZUELA       2025-07-20     <- fecha (en los escaneados, unas lineas mas abajo)
 *
 * Drive no siempre respeta ese orden: con el RAQDLA16 (RUI AN YANG V.2524, 01-10-2026) pone los
 * rotulos juntos y los valores despues. Por eso el buque se busca por su forma (RE_BUQUE) y la
 * fecha, si no esta junto a su rotulo, es la unica del documento.
 *   ATTACHMENT: ITEM / MODEL / VIN NO. / ENGINE NO.   <- una fila por unidad
 *
 * Todo es "lo mejor que se pudo leer": lo que no aparece queda en null y la unica pieza
 * imprescindible son los VIN (sin ellos no hay a quien enlazarlo).
 */
class BillOfLading
{
    /**
     * VIN / serial de carroceria de 17 caracteres. Sin I, O ni Q (la norma del VIN no las usa,
     * y asi no se cuelan palabras del texto) y con letras Y cifras.
     */
    private const RE_VIN = '/\b(?=[A-HJ-NPR-Z0-9]*[A-HJ-NPR-Z])(?=[A-HJ-NPR-Z0-9]*\d)[A-HJ-NPR-Z0-9]{17}\b/';

    /** Un VIN que el PDF parte en dos con un espacio ("LZZWADG49ST501 039", anexo HCLKGT14). */
    private const RE_VIN_PARTIDO = '/\b([A-HJ-NPR-Z0-9]{8,16}) ([A-HJ-NPR-Z0-9]{1,9})\b/';

    /**
     * Buque y viaje en una linea: "RUI AN YANG V.2524", "HONCHO V2512". Es la forma mas segura
     * de dar con el buque, porque Drive no siempre deja el valor debajo de su rotulo.
     */
    private const RE_BUQUE = '/^([A-Z][A-Z0-9 \-]*[A-Z])\s+(V\.?\s?\d{3,5}[A-Z]?)$/u';

    /** Rotulos del formulario: donde termina el valor de otro rotulo. */
    private const ROTULO = '/^(PORT OF|VESSEL|SHIPPING MARK|SHIPPER|CONSIGNEE|NOTIFY)/i';

    /**
     * 'vins' son los que se leen enteros (y cuentan como "nombrados por el BL"); 'vins_partidos'
     * son uniones de dos trozos que PODRIAN ser un VIN: solo sirven para buscar el equipo, no se
     * dan por nombrados si no aparece ninguno con ese serial.
     *
     * @return array{nro:?string,buque:?string,puerto_carga:?string,puerto_descarga:?string,fecha:?string,vins:string[],vins_partidos:string[]}
     */
    public static function leer(string $texto): array
    {
        $lineas = array_values(array_filter(array_map(
            fn ($l) => trim(preg_replace('/\s+/u', ' ', $l)),
            preg_split('/\R/u', $texto)
        ), fn ($l) => $l !== ''));
        $mayus = mb_strtoupper($texto);

        preg_match_all(self::RE_VIN, $mayus, $m);
        $vins = array_values(array_unique($m[0]));

        preg_match_all(self::RE_VIN_PARTIDO, $mayus, $p, PREG_SET_ORDER);
        $partidos = [];
        foreach ($p as [, $a, $b]) {
            $v = $a . $b;
            if (strlen($v) === 17 && preg_match(self::RE_VIN, $v) && !in_array($v, $vins, true)) $partidos[] = $v;
        }

        [$buque, $puertoCarga] = self::buqueYPuerto($lineas);

        return [
            'nro'             => self::nro($texto),
            'buque'           => self::soloNombre(self::buqueConViaje($lineas) ?? self::buqueJuntoAlRotulo($texto) ?? $buque ?? self::buqueDelAnexo($texto)),
            'puerto_carga'    => $puertoCarga,
            'puerto_descarga' => self::lineaTras($lineas, '/^PORT OF DISCHARGE$/i'),
            'fecha'           => self::fecha($lineas),
            'vins'            => $vins,
            'vins_partidos'   => array_values(array_unique($partidos)),
        ];
    }

    /**
     * "B/L NO. HCLKGT03", "BL NO.:HCLKGT08", "BL NO..HCLKGT14". Si trae varios seguidos
     * ("B/L NO.:LHYT06LG01/LHYT06LG02/...", factura de flete de todo un barco) no se sabe cual
     * es el de estos equipos: sin numero, y se pone en el visor.
     */
    private static function nro(string $texto): ?string
    {
        if (!preg_match('/\bB\/?L\s*NO[.:\s]*([A-Z0-9][A-Z0-9\-]{3,})(\s*\/\s*[A-Z0-9])?/i', $texto, $m)) return null;
        return empty($m[2]) ? strtoupper($m[1]) : null;
    }

    /** La primera linea que es un buque con su viaje (RE_BUQUE), donde sea que este. */
    private static function buqueConViaje(array $lineas): ?string
    {
        foreach ($lineas as $l) {
            if (preg_match(self::RE_BUQUE, mb_strtoupper($l))) return mb_substr($l, 0, 120);
        }
        return null;
    }

    /**
     * El buque pegado a su rotulo, como lo deja el OCR de Drive en los BL reales:
     *   "Vessel Port of loading RUI AN YANG V.2524 QINGDAO,CHINA"  (nombre + viaje)
     *   "Vessel" / "MV HORIZON HOPE" / "MAR26VC005"                  (MV + nombre)
     *   "VESSEL/ VOYAGE: MV HORIZON HOPE MAR26VC005"
     *   "ON BEHALF MASTER OF MV : RUI AN  YANG"                      (la firma del capitan)
     * El nombre son palabras en MAYUSCULAS (hasta 4) en el mismo renglon; "PORT OF LOADING"
     * no cuenta como nombre aunque venga en mayusculas.
     */
    private static function buqueJuntoAlRotulo(string $texto): ?string
    {
        $palabra = '(?!(?:PORT|OF|LOADING|DISCHARGE)\b)[A-Z][A-Z\-]*\b';
        $nombre = "($palabra(?:[ \t]+$palabra){0,3})";
        $patrones = [
            // Con su viaje detras: es el buque seguro.
            "/(?i:PORT OF LOADING|VESSEL)\s*(?:M\.?\/?V\.?\s*:?\s*)?$nombre\s+V\.?\s?\d{3,5}[A-Z]?\b/u",
            // Sin viaje, solo si lleva el "MV" (motor vessel) delante.
            "/(?i:VESSEL(?:\s*\/\s*VOYAGE)?\s*:?|MASTER OF)\s*M\.?\/?V\b\.?\s*:?[ \t]*$nombre/u",
        ];
        foreach ($patrones as $re) {
            if (preg_match($re, $texto, $m)) return mb_substr(preg_replace('/\s+/u', ' ', $m[1]), 0, 120);
        }
        return null;
    }

    /**
     * Del buque se guarda solo el NOMBRE del barco: "HONCHO V2512" -> "HONCHO",
     * "RUI AN YANG V.2524" -> "RUI AN YANG" (el viaje no le dice nada a quien ve la ficha).
     */
    private static function soloNombre(?string $buque): ?string
    {
        if ($buque === null) return null;
        $nombre = trim(preg_replace(['/\s+V\.?\s?\d{3,5}[A-Z]?$/iu', '/^M\.?\/?V\b\.?\s*:?\s*/u'], '', $buque));
        return $nombre !== '' ? $nombre : null;
    }

    /** "V/V:HONCHO V2512   BL NO.:..." del anexo, si la cabecera no lo dio: corta antes del nro. */
    private static function buqueDelAnexo(string $texto): ?string
    {
        return preg_match('/V\/V\s*:\s*(.+?)(?=\s{2,}|\s*B\/?L\s*NO|\R|$)/iu', $texto, $m) ? trim($m[1]) : null;
    }

    /**
     * Entre "Port of loading" y "Port of discharge" van el buque y el puerto de carga: el
     * puerto es la ULTIMA linea y el buque todo lo de antes (a veces "HONCHO" y "V.2512" salen
     * en dos lineas). Con una sola linea: si lleva coma ("QINGDAO,CHINA") es el puerto; si no,
     * se toma como buque.
     *
     * @return array{0:?string,1:?string}
     */
    private static function buqueYPuerto(array $lineas): array
    {
        foreach ($lineas as $i => $l) {
            if (!preg_match('/^PORT OF LOADING$/i', $l)) continue;
            $bloque = [];
            foreach (array_slice($lineas, $i + 1, 5) as $v) {
                if (preg_match(self::ROTULO, $v)) break;
                $bloque[] = $v;
            }
            if (!$bloque) return [null, null];
            if (count($bloque) === 1) {
                $sola = mb_substr($bloque[0], 0, 120);
                return str_contains($sola, ',') ? [null, $sola] : [$sola, null];
            }
            $puerto = array_pop($bloque);
            return [mb_substr(implode(' ', $bloque), 0, 120), mb_substr($puerto, 0, 120)];
        }
        return [null, null];
    }

    /** La linea que sigue al rotulo, si no es otro rotulo. */
    private static function lineaTras(array $lineas, string $rotulo): ?string
    {
        foreach ($lineas as $i => $l) {
            if (!preg_match($rotulo, $l)) continue;
            $v = $lineas[$i + 1] ?? null;
            return ($v === null || preg_match(self::ROTULO, $v)) ? null : mb_substr($v, 0, 120);
        }
        return null;
    }

    /**
     * La fecha de "Place and date of issue" (2025-07-20 o 20/07/2025), en AAAA-MM-DD. En los
     * escaneados el rotulo va partido ("Place and" / "date of issue") y la fecha llega varias
     * lineas despues, tras "Freight payable at" y el lugar. Si cerca del rotulo no hay ninguna
     * —Drive a veces pone todos los rotulos juntos y los valores mas abajo—, la del documento,
     * siempre que tenga UNA sola (con dos no se sabe cual es).
     */
    private static function fecha(array $lineas): ?string
    {
        foreach ($lineas as $i => $l) {
            if (!preg_match('/DATE OF ISSUE/i', $l)) continue;
            foreach (array_slice($lineas, $i, 8) as $cerca) {
                if ($f = self::fechaEn($cerca)) return $f;
            }
        }
        $todas = array_unique(array_filter(array_map([self::class, 'fechaEn'], $lineas)));
        return count($todas) === 1 ? reset($todas) : null;
    }

    /** La fecha de una linea (2025-07-20 o 20/07/2025) en AAAA-MM-DD, o null. */
    private static function fechaEn(string $linea): ?string
    {
        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $linea, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return "$m[1]-$m[2]-$m[3]";
        }
        if (preg_match('/\b(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})\b/', $linea, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }
}
