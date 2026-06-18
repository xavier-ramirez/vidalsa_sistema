<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modo OFFLINE Fase 2 (escritura encolada): idempotencia de movilizaciones
 * creadas sin internet. El cliente (PWA) genera un UUID por LOTE y lo manda al
 * sincronizar; el servidor lo usa para no duplicar si el outbox se reenvía.
 *
 * UNIQUE compuesto (client_uuid, ID_EQUIPO): movilizar es bulk → N filas con el
 * MISMO client_uuid (uuid del lote) pero distinto ID_EQUIPO. Una UNIQUE simple
 * sobre client_uuid rompería el bulk. NULL no colisiona en MySQL, así que las
 * inserciones online (client_uuid null) quedan intactas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            if (!Schema::hasColumn('movilizacion_historial', 'client_uuid')) {
                $table->char('client_uuid', 36)->nullable()->after('USUARIO_REGISTRO');
                $table->unique(['client_uuid', 'ID_EQUIPO'], 'mov_client_uuid_equipo_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movilizacion_historial', function (Blueprint $table) {
            if (Schema::hasColumn('movilizacion_historial', 'client_uuid')) {
                $table->dropUnique('mov_client_uuid_equipo_unique');
                $table->dropColumn('client_uuid');
            }
        });
    }
};
