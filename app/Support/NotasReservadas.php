<?php

namespace App\Support;

use App\Models\MovimientoInventario;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Números de Nota de Entrega apartados por dispositivo (tabla notas_reservadas).
 *
 * Una salida hecha sin internet tiene que salir ya con su número: el papel se imprime y se
 * firma en el momento. Con conexión, cada dispositivo pide un bloque (paraDispositivo) y lo
 * guarda; sin conexión, la salida usa el siguiente libre y lo manda en
 * `numero_nota_reservado`. Al subir, tomar() comprueba que ese número es del usuario y que no
 * se usó; si no viene ninguno, saca uno nuevo del contador como siempre.
 *
 * Los números salen del MISMO contador que las demás notas (apartarNumerosNota), así que
 * nunca chocan con una nota hecha con internet.
 */
class NotasReservadas
{
    public const TABLA = 'notas_reservadas';
    /** Cuántos se apartan de una vez, y con cuántos libres se pide otro bloque. */
    public const BLOQUE = 20;
    public const MINIMO = 5;

    /** @return list<string> los números libres del dispositivo (aparta otro bloque si quedan pocos). */
    public static function paraDispositivo(int $idUsuario, string $dispositivo): array
    {
        return DB::transaction(function () use ($idUsuario, $dispositivo) {
            $libres = self::libres($idUsuario, $dispositivo);
            if (count($libres) < self::MINIMO) {
                $nuevos = MovimientoInventario::apartarNumerosNota(self::BLOQUE);
                DB::table(self::TABLA)->insert(array_map(fn ($n) => [
                    'NUMERO_NOTA' => $n, 'ID_USUARIO' => $idUsuario, 'DISPOSITIVO' => $dispositivo, 'created_at' => now(),
                ], $nuevos));
                $libres = array_merge($libres, $nuevos);
            }

            return $libres;
        });
    }

    /**
     * El número de la nota que se está registrando. DENTRO de la transacción de la salida: si
     * algo falla después, el número vuelve a quedar libre con el rollback.
     */
    public static function tomar(?string $reservado, ?int $idUsuario): string
    {
        $reservado = trim((string) $reservado);
        if ($reservado === '') {
            return MovimientoInventario::generarNumeroNota();
        }

        $fila = DB::table(self::TABLA)->where('NUMERO_NOTA', $reservado)->lockForUpdate()->first();
        if (! $fila || (int) $fila->ID_USUARIO !== (int) $idUsuario) {
            throw new RuntimeException("La Nota {$reservado} no está reservada para tu usuario.");
        }
        if ($fila->USADA_AT !== null) {
            throw new RuntimeException("La Nota {$reservado} ya se usó en otra salida.");
        }
        DB::table(self::TABLA)->where('NUMERO_NOTA', $reservado)->update(['USADA_AT' => now()]);

        return $reservado;
    }

    /**
     * Los números de una salida que se emite en $cuantas notas (TandasNota), uno por nota y en
     * su orden. Sin números apartados, nuevos. Con apartados (salida sin internet) tienen que ser
     * justo $cuantas, el primero en numero_nota_reservado y el resto en numeros_nota_extra: son
     * los que ya llevan los papeles impresos, y si el servidor la partiera distinto, alguno
     * quedaría con un número que no es el suyo.
     *
     * @return list<string>
     */
    public static function tomarVarias(int $cuantas, array $data, ?int $idUsuario): array
    {
        $apartados = array_values(array_filter(array_map(
            fn ($n) => trim((string) $n),
            array_merge([$data['numero_nota_reservado'] ?? null], (array) ($data['numeros_nota_extra'] ?? []))
        ), fn ($n) => $n !== ''));
        if ($apartados && count($apartados) !== $cuantas) {
            throw new RuntimeException('La salida trae ' . count($apartados) . ' Nota(s) apartada(s) y se emite en ' . $cuantas
                . '. Vuelve a hacer la vista previa y regístrala de nuevo.');
        }

        return array_map(fn ($i) => self::tomar($apartados[$i] ?? null, $idUsuario), range(0, $cuantas - 1));
    }

    /**
     * Una salida sin internet que el servidor rechazó y el usuario DESCARTÓ: su papel ya se
     * imprimió, así que el número no vuelve a darse nunca (quedaría otro papel con el mismo
     * número). Se marca como usado sin movimiento: queda como hueco en la numeración.
     * Devuelve false si el número no es de este usuario o ya se usó.
     */
    public static function anular(string $numero, int $idUsuario): bool
    {
        return DB::table(self::TABLA)
            ->where('NUMERO_NOTA', trim($numero))->where('ID_USUARIO', $idUsuario)->whereNull('USADA_AT')
            ->update(['USADA_AT' => now()]) > 0;
    }

    /** @return list<string> los libres del año en curso (los de un año anterior ya no se dan). */
    private static function libres(int $idUsuario, string $dispositivo): array
    {
        return DB::table(self::TABLA)
            ->where('ID_USUARIO', $idUsuario)->where('DISPOSITIVO', $dispositivo)->whereNull('USADA_AT')
            ->where('NUMERO_NOTA', 'like', 'NE-' . date('Y') . '-%')
            ->orderBy('NUMERO_NOTA')->pluck('NUMERO_NOTA')->all();
    }
}
