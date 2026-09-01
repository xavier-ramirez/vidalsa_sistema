<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firmantes FIJOS de la Nota de Entrega HORIZONTAL (el "CONTROL DE SALIDA" del almacen).
 *
 * Esa hoja lleva cinco bloques de firma: ENTREGADO · SOPORTADO · SOPORTADO · RECIBIDO ·
 * SEGURIDAD. Los tres primeros son SIEMPRE la misma gente del almacen que despacha, asi que
 * se configuran una vez por almacen y salen pre-impresos. Los otros dos NO se guardan:
 *   RECIBIDO  → lo firma quien recibe en el destino, cambia en cada nota.
 *   SEGURIDAD → lo firma el vigilante de turno.
 *
 * ENTREGADO reusa las columnas que YA existian (ALMACENISTA / CARGO_ALMACENISTA, las mismas
 * que el formato vertical imprime como "ENTREGADO POR"). Aqui solo se le agrega la cedula,
 * que el vertical no pide pero el horizontal si. No se duplican nombre y cargo: un almacen
 * tiene UN almacenista y es el mismo en los dos formatos.
 *
 * Los SOPORTE_n siguen la convencion que ya usa `frentes_trabajo` para sus responsables
 * (RESP_n_NOM / RESP_n_CAR / RESP_n_CED), para no inventar un tercer estilo de nombres.
 *
 * Van como columnas de la propia fila del almacen y no en una tabla aparte por lo mismo que
 * FORMATO_NOTA: el PDF ya carga el almacen del movimiento, asi que los firmantes viajan en
 * esa misma fila — cero consultas extra, cero joins, cero cache que invalidar.
 *
 * Todas nullable: un almacen sin configurar imprime esos bloques en blanco, que es un
 * formulario perfectamente valido (se llenan a mano).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->string('CEDULA_ALMACENISTA', 20)->nullable()->after('CARGO_ALMACENISTA');

            $t->string('SOPORTE_1_NOM', 120)->nullable()->after('CEDULA_ALMACENISTA');
            $t->string('SOPORTE_1_CAR', 120)->nullable()->after('SOPORTE_1_NOM');
            $t->string('SOPORTE_1_CED', 20)->nullable()->after('SOPORTE_1_CAR');

            $t->string('SOPORTE_2_NOM', 120)->nullable()->after('SOPORTE_1_CED');
            $t->string('SOPORTE_2_CAR', 120)->nullable()->after('SOPORTE_2_NOM');
            $t->string('SOPORTE_2_CED', 20)->nullable()->after('SOPORTE_2_CAR');
        });
    }

    public function down(): void
    {
        Schema::table('almacenes', function (Blueprint $t) {
            $t->dropColumn([
                'CEDULA_ALMACENISTA',
                'SOPORTE_1_NOM', 'SOPORTE_1_CAR', 'SOPORTE_1_CED',
                'SOPORTE_2_NOM', 'SOPORTE_2_CAR', 'SOPORTE_2_CED',
            ]);
        });
    }
};
