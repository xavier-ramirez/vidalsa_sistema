<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alinea la tabla `fallas` al formato oficial del acta "REPORTE DE FALLAS"
 * (formatos mecanica.xlsx). El acta NO usa Sistema/Prioridad/Repuestos, y
 * separa la informacion del taller (mecanico, diagnostico, acciones) que
 * antes vivia en un unico OBSERVACIONES_MECANICO.
 *
 * Los campos del taller son opcionales: se pueden llenar al crear el
 * reporte extenso O al cerrarlo (flujo decidido con el cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fallas', function (Blueprint $table) {
            // ── Columnas fuera del formato oficial → se eliminan ──
            $table->dropColumn([
                'SISTEMA_AFECTADO',
                'PRIORIDAD',
                'TIPO_INTERVENCION',
                'REPUESTOS_ESTIMADOS',
                'OBSERVACIONES_MECANICO',
                'HOROMETRO_ACTUAL',
            ]);
        });

        Schema::table('fallas', function (Blueprint $table) {
            // ── Seccion 1: Informacion general ──
            $table->string('FRENTE_TRABAJO', 120)->nullable()->after('ESTADO_AL_CREAR');

            // ── Seccion 2: Identificacion del equipo ──
            $table->enum('CLASE_ACTIVO', ['MAQUINARIA', 'VEHICULO', 'OTRO'])->nullable()->after('FRENTE_TRABAJO');
            $table->string('KILOMETRAJE', 50)->nullable()->after('CLASE_ACTIVO');
            $table->string('HORAS', 50)->nullable()->after('KILOMETRAJE');

            // ── Seccion 3: Tipo de mantenimiento requerido ──
            $table->enum('TIPO_MANTENIMIENTO', ['PREVENTIVO', 'CORRECTIVO'])->nullable()->after('DESCRIPCION_AVERIA');

            // ── Seccion 4: Exclusiva para taller de mantenimiento ──
            $table->string('MECANICO_ASIGNADO', 120)->nullable()->after('TIPO_MANTENIMIENTO');
            $table->date('FECHA_RECEPCION')->nullable()->after('MECANICO_ASIGNADO');
            $table->text('DIAGNOSTICO')->nullable()->after('FECHA_RECEPCION');
            $table->text('ACCIONES_REALIZADAS')->nullable()->after('DIAGNOSTICO');
        });
    }

    public function down(): void
    {
        Schema::table('fallas', function (Blueprint $table) {
            $table->dropColumn([
                'FRENTE_TRABAJO', 'CLASE_ACTIVO', 'KILOMETRAJE', 'HORAS',
                'TIPO_MANTENIMIENTO', 'MECANICO_ASIGNADO', 'FECHA_RECEPCION',
                'DIAGNOSTICO', 'ACCIONES_REALIZADAS',
            ]);
        });

        Schema::table('fallas', function (Blueprint $table) {
            $table->string('HOROMETRO_ACTUAL', 50)->nullable()->after('ESTADO_AL_CREAR');
            $table->enum('SISTEMA_AFECTADO', ['MOTOR', 'HIDRAULICO', 'ELECTRICO', 'NEUMATICO', 'TRANSMISION', 'ESTRUCTURAL', 'FRENOS', 'OTROS'])->nullable()->after('DESCRIPCION_AVERIA');
            $table->enum('PRIORIDAD', ['CRITICA', 'ALTA', 'MEDIA', 'BAJA'])->nullable()->after('SISTEMA_AFECTADO');
            $table->enum('TIPO_INTERVENCION', ['CORRECTIVO_INMEDIATO', 'PROGRAMADO'])->nullable()->after('PRIORIDAD');
            $table->text('REPUESTOS_ESTIMADOS')->nullable()->after('TIPO_INTERVENCION');
            $table->text('OBSERVACIONES_MECANICO')->nullable()->after('REPUESTOS_ESTIMADOS');
        });
    }
};
