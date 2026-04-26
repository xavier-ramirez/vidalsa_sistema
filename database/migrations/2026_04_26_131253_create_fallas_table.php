<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabla unificada de reportes de fallas. Soporta dos modalidades:
     *  - corto:   solo fecha + descripcion (registro rapido).
     *  - extenso: campos completos del formato corporativo "REPORTE DE FALLAS.xlsx"
     *             (sistema afectado, prioridad, tipo intervencion, etc.) y genera PDF.
     *
     * Activo polimorfico: una falla puede pertenecer a un equipo (vehiculo)
     * o a un equipo_auxiliar. Se modela con (ACTIVO_TIPO, ACTIVO_ID) — no
     * usamos morphTo de Laravel porque ya tenemos PKs custom (ID_EQUIPO,
     * ID_AUXILIAR) y queremos control explicito sobre las queries.
     *
     * Permiso global del modulo: equipos.edit (gateado en routes/web.php).
     */
    public function up(): void
    {
        Schema::create('fallas', function (Blueprint $table) {
            $table->bigIncrements('ID_FALLA');

            // Codigo de control unico para auditoria (RF-NNNNN). Generado en
            // FallaController::generateCodigoReporte() reusando el numerador
            // sequencial via DB::transaction + lockForUpdate para evitar
            // colisiones en concurrencia.
            $table->string('CODIGO_REPORTE', 20)->unique();
            $table->timestamp('FECHA_EMISION');

            // corto = registro rapido sin acta. extenso = campos completos + PDF.
            $table->enum('TIPO_REPORTE', ['corto', 'extenso'])->default('corto');
            $table->enum('ESTADO_REPORTE', ['abierto', 'cerrado'])->default('abierto');

            // Activo polimorfico (vehiculo o auxiliar)
            $table->enum('ACTIVO_TIPO', ['equipo', 'equipo_auxiliar']);
            $table->unsignedBigInteger('ACTIVO_ID');
            $table->index(['ACTIVO_TIPO', 'ACTIVO_ID']);

            // Estado del equipo en el momento del reporte. ESTADO_AL_CREAR
            // es el que el usuario aplica al equipo (puede ser INOPERATIVO o
            // EN MANTENIMIENTO segun el escenario). ESTADO_PREVIO se guarda
            // para poder restaurarlo al cerrar el reporte.
            $table->string('ESTADO_PREVIO', 30)->nullable();
            $table->string('ESTADO_AL_CREAR', 30)->nullable();
            $table->string('HOROMETRO_ACTUAL', 50)->nullable();

            // Detalle tecnico (opcional en reporte corto)
            $table->text('DESCRIPCION_AVERIA')->nullable();
            $table->enum('SISTEMA_AFECTADO', [
                'MOTOR', 'HIDRAULICO', 'ELECTRICO', 'NEUMATICO',
                'TRANSMISION', 'ESTRUCTURAL', 'FRENOS', 'OTROS'
            ])->nullable();
            $table->enum('PRIORIDAD', ['CRITICA', 'ALTA', 'MEDIA', 'BAJA'])->nullable();

            // Gestion de mantenimiento
            $table->enum('TIPO_INTERVENCION', ['CORRECTIVO_INMEDIATO', 'PROGRAMADO'])->nullable();
            $table->text('REPUESTOS_ESTIMADOS')->nullable();
            $table->text('OBSERVACIONES_MECANICO')->nullable();

            // Validacion de personal: tomamos el ID_USUARIO autenticado y
            // congelamos NOMBRE/CARGO/EMAIL en el momento del reporte
            // (snapshot). Asi el PDF queda inmutable aunque el usuario edite
            // su perfil despues.
            $table->unsignedBigInteger('ID_USUARIO_REPORTA');
            $table->string('NOMBRE_REPORTA', 120)->nullable();
            $table->string('CARGO_REPORTA', 80)->nullable();
            $table->string('EMAIL_REPORTA', 120)->nullable();

            // Cierre del reporte
            $table->unsignedBigInteger('ID_USUARIO_CIERRA')->nullable();
            $table->string('NOMBRE_CIERRA', 120)->nullable();
            $table->string('CARGO_CIERRA', 80)->nullable();
            $table->timestamp('FECHA_CIERRE')->nullable();
            $table->text('OBSERVACIONES_CIERRE')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Solo indices (sin FK enforced) para mantener historial intacto
            // si un usuario se borra. La integridad la valida el controller.
            $table->index('ID_USUARIO_REPORTA');
            $table->index('ID_USUARIO_CIERRA');
        });

        // Tabla de log inalterable para auditoria (creacion / cierre /
        // cambio de estado sin reporte). NO tiene updated_at: cada accion
        // es un registro nuevo.
        Schema::create('fallas_audit_log', function (Blueprint $table) {
            $table->bigIncrements('ID_LOG');
            $table->unsignedBigInteger('ID_FALLA')->nullable();
            $table->enum('ACTIVO_TIPO', ['equipo', 'equipo_auxiliar']);
            $table->unsignedBigInteger('ACTIVO_ID');
            // accion: create_falla, close_falla, change_estado, reopen_falla
            $table->string('ACCION', 40);
            $table->json('METADATA')->nullable();
            $table->unsignedBigInteger('ID_USUARIO');
            $table->string('NOMBRE_USUARIO', 120)->nullable();
            $table->string('EMAIL_USUARIO', 120)->nullable();
            $table->timestamp('CREADO_EN')->useCurrent();

            $table->index(['ACTIVO_TIPO', 'ACTIVO_ID']);
            $table->index('ID_FALLA');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fallas_audit_log');
        Schema::dropIfExists('fallas');
    }
};
