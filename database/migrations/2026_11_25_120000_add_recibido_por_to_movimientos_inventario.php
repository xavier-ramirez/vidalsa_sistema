<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RECIBIDO POR de la Nota de Entrega: nombre y cargo de quien recibe el material, que antes
 * se escribían a mano. Van en cada movimiento de la nota, como el resto de la cabecera.
 *
 * CONSUMO_INTERNO: la salida se quedó en un proyecto del mismo almacén (sin traspaso). La
 * nota vertical de esas salidas no lleva el bloque "Datos del vehículo / Datos del chofer"
 * (no hay transporte). Se guarda al registrar para que una nota se reimprima siempre como se
 * firmó, aunque luego cambien los proyectos del almacén. Las notas viejas quedan en 0: salen
 * igual que siempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            if (!Schema::hasColumn('movimientos_inventario', 'RECIBIDO_POR')) {
                $table->string('RECIBIDO_POR', 150)->nullable()->after('TRANSPORTE_CEDULA');
                $table->string('CARGO_RECIBE', 100)->nullable()->after('RECIBIDO_POR');
                $table->boolean('CONSUMO_INTERNO')->default(false)->after('CARGO_RECIBE');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropColumn(['RECIBIDO_POR', 'CARGO_RECIBE', 'CONSUMO_INTERNO']);
        });
    }
};
