<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documentacion', function (Blueprint $table) {
            // Poliza tracking
            if (!Schema::hasColumn('documentacion', 'POLIZA_SUBIDO_POR')) {
                $table->unsignedBigInteger('POLIZA_SUBIDO_POR')->nullable();
            }
            if (!Schema::hasColumn('documentacion', 'POLIZA_FECHA_SUBIDA')) {
                $table->timestamp('POLIZA_FECHA_SUBIDA')->nullable();
            }
            
            // ROTC tracking
            if (!Schema::hasColumn('documentacion', 'ROTC_SUBIDO_POR')) {
                $table->unsignedBigInteger('ROTC_SUBIDO_POR')->nullable();
            }
            if (!Schema::hasColumn('documentacion', 'ROTC_FECHA_SUBIDA')) {
                $table->timestamp('ROTC_FECHA_SUBIDA')->nullable();
            }
            
            // RACDA tracking
            if (!Schema::hasColumn('documentacion', 'RACDA_SUBIDO_POR')) {
                $table->unsignedBigInteger('RACDA_SUBIDO_POR')->nullable();
            }
            if (!Schema::hasColumn('documentacion', 'RACDA_FECHA_SUBIDA')) {
                $table->timestamp('RACDA_FECHA_SUBIDA')->nullable();
            }
            
            // Propiedad tracking
            if (!Schema::hasColumn('documentacion', 'PROPIEDAD_SUBIDO_POR')) {
                $table->unsignedBigInteger('PROPIEDAD_SUBIDO_POR')->nullable();
            }
            if (!Schema::hasColumn('documentacion', 'PROPIEDAD_FECHA_SUBIDA')) {
                $table->timestamp('PROPIEDAD_FECHA_SUBIDA')->nullable();
            }
            
            // Adicional tracking  
            if (!Schema::hasColumn('documentacion', 'ADICIONAL_SUBIDO_POR')) {
                $table->unsignedBigInteger('ADICIONAL_SUBIDO_POR')->nullable();
            }
            if (!Schema::hasColumn('documentacion', 'ADICIONAL_FECHA_SUBIDA')) {
                $table->timestamp('ADICIONAL_FECHA_SUBIDA')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * NO-OP a propósito (down() blindado): NO borra las columnas de tracking.
     *
     * Esta migración fue renombrada (antes tenía el timestamp malformado
     * `2026_02_12_add_upload_tracking_to_documentacion`). En bases de datos ya
     * migradas con el nombre viejo, el archivo renombrado se ejecuta una vez
     * como no-op (up() está protegido con hasColumn) y queda en su propio batch.
     * Si ese batch se revirtiera con `migrate:rollback`, un down() que dropeara
     * las columnas destruiría datos de producción que ya existían desde antes.
     *
     * Como estas 10 columnas (POLIZA/ROTC/RACDA/PROPIEDAD/ADICIONAL _SUBIDO_POR
     * y _FECHA_SUBIDA) son parte estable del esquema, este down() se deja vacío:
     * ningún rollback las borra. En una BD nueva simplemente permanecen (son
     * nullable, sin efecto). Seguridad de datos > reversibilidad exacta.
     */
    public function down(): void
    {
        // intencionalmente vacío — ver cabecera (no se borran columnas en rollback)
    }
};
