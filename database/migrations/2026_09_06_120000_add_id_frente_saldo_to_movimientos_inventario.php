<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `ID_FRENTE_SALDO` a `movimientos_inventario`: DE QUÉ BOLSA salió (o entró) el saldo,
 * que no siempre es el proyecto al que se le entregó el material.
 *
 * Contexto: en los almacenes que sirven a varios proyectos el saldo se lleva por
 * (almacén, producto, proyecto) — ver InventarioService::frenteDelSaldo. Hasta ahora esos
 * dos datos viajaban en la MISMA columna (ID_FRENTE), así que cuando una salida tenía que
 * tomar material de otra bolsa no había forma de escribir las dos cosas: el tramo que salía
 * de la bolsa común se guardaba con ID_FRENTE NULL (para que el recálculo lo sumara a la
 * bolsa correcta) y el proyecto que RECIBIÓ quedaba solo en el texto de las notas.
 *
 * Con esta columna cada fila del kardex dice las dos cosas sin ambigüedad:
 *   ID_FRENTE       → a quién se le entregó (o de quién es la entrada). Es lo que imprime
 *                     la Nota de Entrega y lo que se filtra en la bitácora.
 *   ID_FRENTE_SALDO → de qué bolsa se descontó. 0 = bolsa común (material sin asignar).
 *
 * SIN clave foránea a propósito: 0 no es un frente real, es el centinela de la bolsa común
 * (mismo criterio que `almacen_stock.ID_FRENTE`, que tampoco lleva FK).
 *
 * NULL = filas anteriores a esta columna. El recálculo de saldos las sigue leyendo por
 * ID_FRENTE (COALESCE), que es exactamente como se comportaban hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $t) {
            $t->unsignedBigInteger('ID_FRENTE_SALDO')->nullable()->after('ID_FRENTE');
            // El recálculo de un saldo recorre el kardex de UNA bolsa concreta
            // (almacén + producto + bolsa): sin índice eso es un scan por producto.
            $t->index(['ID_ALMACEN', 'ID_PRODUCTO', 'ID_FRENTE_SALDO'], 'mov_inv_alm_prod_frsaldo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $t) {
            $t->dropIndex('mov_inv_alm_prod_frsaldo_idx');
            $t->dropColumn('ID_FRENTE_SALDO');
        });
    }
};
