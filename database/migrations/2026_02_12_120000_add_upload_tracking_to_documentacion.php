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
     */
    public function down(): void
    {
        Schema::table('documentacion', function (Blueprint $table) {
            // NOTA: up() crea estas columnas como unsignedBigInteger nullable
            // SIN foreign key. Antes este down() llamaba dropForeign() sobre
            // ellas y el rollback fallaba con "foreign key constraint does not
            // exist" (no habia FK que dropear). Se eliminaron esos drops; solo
            // se dropean las columnas.
            $table->dropColumn([
                'POLIZA_SUBIDO_POR',
                'POLIZA_FECHA_SUBIDA',
                'ROTC_SUBIDO_POR',
                'ROTC_FECHA_SUBIDA',
                'RACDA_SUBIDO_POR',
                'RACDA_FECHA_SUBIDA',
                'PROPIEDAD_SUBIDO_POR',
                'PROPIEDAD_FECHA_SUBIDA',
                'ADICIONAL_SUBIDO_POR',
                'ADICIONAL_FECHA_SUBIDA'
            ]);
        });
    }
};
