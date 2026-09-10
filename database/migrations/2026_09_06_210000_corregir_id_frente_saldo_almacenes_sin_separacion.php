<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Endereza `ID_FRENTE_SALDO` en los almacenes que NO separan por proyecto.
 *
 * La migración de relleno anterior (backfill_id_frente_saldo_movimientos) escribió
 * `COALESCE(ID_FRENTE, 0)` en todas las filas viejas. En un almacén que separa eso es
 * correcto —el frente ES la bolsa—, pero en uno que no separa NO hay bolsas: todo el saldo
 * vive en la fila común (ID_FRENTE = 0) y el frente que lleva el movimiento es solo el
 * destino de la entrega. Esas filas quedaron diciendo que salieron de una bolsa que ese
 * almacén nunca tuvo.
 *
 * Mientras el almacén siga sin separar da igual: el recálculo no filtra por bolsa ahí. El
 * problema aparece el día que a un almacén PROYECTO con un solo frente le agregan el
 * segundo y pasa a separar: desde ese momento recalcularSaldoProducto() busca las filas de
 * la bolsa 0 y ninguna de estas aparece, así que "deshacer movimiento" sobre ese producto
 * dejaría el saldo inflado por todo lo que se ignoró.
 *
 * OJO — la regla de "separa por proyecto" (TIPO = PROYECTO y MÁS DE UN frente asociado)
 * está escrita AQUÍ a mano en vez de llamar a Almacen::separaPorProyecto(). No es un
 * descuido ni una duplicación de la de verdad: una migración es una foto de un momento y
 * tiene que dar el MISMO resultado dentro de un año, aunque el modelo cambie de criterio o
 * ese método deje de existir. Ninguna de las otras 145 migraciones del proyecto toca
 * modelos de la app, por lo mismo. El punto único sigue siendo el modelo; esto es el
 * registro de lo que se corrigió hoy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $noSeparan = DB::table('almacenes')
            ->leftJoin('almacen_frentes', 'almacen_frentes.ID_ALMACEN', '=', 'almacenes.ID_ALMACEN')
            ->groupBy('almacenes.ID_ALMACEN', 'almacenes.TIPO')
            ->havingRaw("almacenes.TIPO <> 'PROYECTO' OR COUNT(almacen_frentes.ID_FRENTE) <= 1")
            ->pluck('almacenes.ID_ALMACEN');

        if ($noSeparan->isEmpty()) {
            return;
        }

        // Por lotes, como el relleno: la tabla crece con cada movimiento y un UPDATE único
        // sobre todo el histórico bloquea filas que el almacén podría estar usando.
        do {
            $tocadas = DB::table('movimientos_inventario')
                ->whereIn('ID_ALMACEN', $noSeparan)
                ->where('ID_FRENTE_SALDO', '<>', 0)   // 0 = bolsa común (el centinela)
                ->limit(2000)
                ->update(['ID_FRENTE_SALDO' => 0]);
        } while ($tocadas > 0);
    }

    public function down(): void
    {
        // Intencionalmente vacío: el valor anterior era el frente destino, que sigue
        // guardado en ID_FRENTE. Volver a escribirlo aquí solo reintroduciría el error.
    }
};
