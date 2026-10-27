<?php

namespace App\Support;

/**
 * Lista de ids que manda la pantalla en UN solo campo separado por comas ("12,7,305").
 *
 * No va como ids[]: PHP corta la petición en max_input_vars (1000 campos) y en
 * max_multipart_body_parts, SIN dar error — el Excel de una lista de 1300 equipos salía con
 * ~1000 filas. Un solo campo no tiene ese tope. Se acepta también un array (ids[]) para
 * quien todavía lo mande así (una pestaña abierta con el JS anterior al despliegue).
 *
 * Enteros > 0, sin repetidos, en el orden en que llegaron (el de la tabla). [] si no vino
 * nada usable.
 */
final class IdsEnviados
{
    public static function leer(mixed $valor): array
    {
        $partes = is_array($valor) ? $valor : explode(',', (string) $valor);

        return collect($partes)
            ->filter(fn ($s) => is_scalar($s))
            ->map(fn ($s) => (int) trim((string) $s))
            ->filter(fn ($n) => $n > 0)
            ->unique()
            ->values()
            ->all();
    }
}
