<?php

namespace App\Support;

/**
 * Límite de memoria para las operaciones pesadas (subir PDFs grandes, cargas masivas, actas en
 * PDF). Antes cada una hacía ini_set('memory_limit', '512M') a secas: eso SUBE el límite del
 * servidor (128M) pero BAJA uno mayor —el de phpunit.xml (1536M), o un servidor con más— y la
 * suite de pruebas se quedaba sin memoria al final. Aquí solo se sube, nunca se baja.
 */
final class Memoria
{
    /** Pone memory_limit en $minimo si el actual es menor; -1 (sin límite) se respeta. */
    public static function alMenos(string $minimo): void
    {
        $actual = (string) ini_get('memory_limit');
        if ($actual === '-1' || self::bytes($actual) >= self::bytes($minimo)) {
            return;
        }
        @ini_set('memory_limit', $minimo);
    }

    /** "512M" → bytes (K, M y G como los entiende PHP). */
    private static function bytes(string $valor): int
    {
        $valor = trim($valor);
        $n = (int) $valor;

        return match (strtoupper(substr($valor, -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        };
    }
}
