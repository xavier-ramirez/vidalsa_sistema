<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Qué papel es cada "embarque": el BL de la naviera o el CERTIFICADO DE ORIGEN del INTT.
 *
 * Pedido 05-10-2026: dos camionetas Maxus tenían su certificado de origen cargado como título de
 * propiedad; se pasó al lugar del documento de embarque (es también un papel de ANTES del título),
 * pero el detalle del equipo tiene que decir "Certificado de origen" y no "Embarque BL".
 * Guarda hasColumn: el servidor puede recibir la columna antes por SQL. Marca además los dos
 * certificados de origen que ya están cargados (Maxus A91AY8C y A91AY4C): así el despliegue lo deja
 * todo hecho sin correr ningún SQL aparte.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('embarques', 'TIPO_DOCUMENTO')) {
            Schema::table('embarques', function (Blueprint $t) {
                $t->string('TIPO_DOCUMENTO', 30)->default('BL')->after('NRO_BL');
            });
        }
        DB::table('embarques')->whereIn('NRO_BL', ['AA-0709056', 'AA-0709052'])
            ->update(['TIPO_DOCUMENTO' => 'CERTIFICADO_ORIGEN']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('embarques', 'TIPO_DOCUMENTO')) {
            Schema::table('embarques', function (Blueprint $t) {
                $t->dropColumn('TIPO_DOCUMENTO');
            });
        }
    }
};
