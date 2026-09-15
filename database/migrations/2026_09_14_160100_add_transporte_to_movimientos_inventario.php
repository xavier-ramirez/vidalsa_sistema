<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transporte de la Nota de Entrega: vehículo, placa, chofer y cédula. Van en cada movimiento
 * de la nota, igual que RQ, solicitante y departamento (la cabecera se lee del primero), y los
 * imprime el bloque "Datos del vehículo / Datos del chofer". Vacíos = la hoja sale en blanco
 * para llenarla a mano, como hasta ahora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            if (!Schema::hasColumn('movimientos_inventario', 'TRANSPORTE_VEHICULO')) {
                $table->string('TRANSPORTE_VEHICULO', 150)->nullable()->after('DEPARTAMENTO');
                $table->string('TRANSPORTE_PLACA', 30)->nullable()->after('TRANSPORTE_VEHICULO');
                $table->string('TRANSPORTE_CHOFER', 150)->nullable()->after('TRANSPORTE_PLACA');
                $table->string('TRANSPORTE_CEDULA', 30)->nullable()->after('TRANSPORTE_CHOFER');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movimientos_inventario', function (Blueprint $table) {
            $table->dropColumn(['TRANSPORTE_VEHICULO', 'TRANSPORTE_PLACA', 'TRANSPORTE_CHOFER', 'TRANSPORTE_CEDULA']);
        });
    }
};
