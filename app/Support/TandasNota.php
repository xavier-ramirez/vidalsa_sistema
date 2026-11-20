<?php

namespace App\Support;

use App\Casts\MojibakeFix;

/**
 * Una Nota de Entrega lleva como máximo RENGLONES materiales: una salida con más se emite en
 * varias notas, cada una con su propio número (NE-AAAA-NNNN), en el orden en que vienen.
 *
 * Renglón = producto + nº de parte + destino: la misma clave con que la hoja junta las líneas
 * (AlmacenController::renderNotaEntregaPdfBinary y nota_entrega_local.js · agrupar), así un
 * renglón nunca queda partido entre dos notas. La Nota sin internet reparte igual
 * (nota_entrega_local.js · tandas, con su propio RENGLONES_POR_NOTA = este; lo vigila
 * TandasNotaTest).
 */
final class TandasNota
{
    /** Medido: con nombres largos, 20 caben en una hoja de pie y en una acostada (25 ya no). */
    public const RENGLONES = 20;

    /**
     * Las líneas repartidas en notas, en su orden. Las líneas de un mismo renglón van a la nota
     * donde apareció ese renglón por primera vez.
     *
     * @return list<list<array>>
     */
    public static function partir(array $lineas): array
    {
        $tandas = [];
        $dondeVa = [];   // renglón => índice de su nota
        $renglonesDeLaUltima = 0;
        foreach ($lineas as $linea) {
            $renglon = self::renglon($linea);
            if (!isset($dondeVa[$renglon])) {
                if (!$tandas || $renglonesDeLaUltima === self::RENGLONES) {
                    $tandas[] = [];
                    $renglonesDeLaUltima = 0;
                }
                $dondeVa[$renglon] = count($tandas) - 1;
                $renglonesDeLaUltima++;
            }
            $tandas[$dondeVa[$renglon]][] = $linea;
        }

        return $tandas;
    }

    /** La clave del renglón de la hoja donde se imprime esta línea. */
    public static function renglon(array $linea): string
    {
        return (int) ($linea['id_producto'] ?? 0)
            . '|' . trim((string) ($linea['numero_parte'] ?? ''))
            . '|' . (self::destino($linea['destino'] ?? null) ?? '');
    }

    /**
     * Destino de un renglón listo para guardar: texto reparado (MojibakeFix, como el resto de
     * la nota), espacios colapsados y en mayúsculas. Se corta a 150 DESPUÉS de pasarlo a
     * mayúsculas porque eso puede alargarlo (ß → SS) y la columna DESTINO_ITEM es de 150.
     */
    public static function destino(?string $texto): ?string
    {
        $t = (string) MojibakeFix::fix($texto);
        $t = mb_substr(mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $t))), 0, 150);

        return $t === '' ? null : $t;
    }
}
