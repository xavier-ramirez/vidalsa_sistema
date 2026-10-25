<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * bloqueo_ip guarda también los EQUIPOS (navegadores) bloqueados, no solo IPs: la oficina
 * sale a internet por una sola IP, y bloquearla dejaba fuera a todos por lo que hizo uno.
 *   TIPO        'IP' (DIRECCION_IP es la IP) o 'EQUIPO' (DIRECCION_IP es el id de la cookie
 *               del navegador, ver BloqueoIp::equipo).
 *   ULTIMA_IP   desde dónde se vio el equipo por última vez, para reconocerlo en el panel.
 * Lo que ya había son IPs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bloqueo_ip', function (Blueprint $table) {
            if (!Schema::hasColumn('bloqueo_ip', 'TIPO')) {
                $table->string('TIPO', 10)->default('IP')->after('ID_BLOQUEO');
            }
            if (!Schema::hasColumn('bloqueo_ip', 'ULTIMA_IP')) {
                $table->string('ULTIMA_IP', 45)->nullable()->after('DIRECCION_IP');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bloqueo_ip', function (Blueprint $table) {
            $table->dropColumn(['TIPO', 'ULTIMA_IP']);
        });
    }
};
