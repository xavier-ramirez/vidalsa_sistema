<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pases de reingreso (App\Support\PaseReingreso): quien entró SIN conexión con su clave vuelve
 * a tener sesión en cuanto vuelve el servidor, sin pasar otra vez por el inicio de sesión.
 * Solo se guarda el hash del pase; el pase va cifrado en el equipo (llave local).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pases_reingreso')) {
            return;
        }

        Schema::create('pases_reingreso', function (Blueprint $table) {
            $table->id('ID_PASE');
            $table->unsignedBigInteger('ID_USUARIO');
            $table->char('HASH', 64)->unique();
            // Usuario::claveVersion() al emitirlo: con otra clave el pase ya no sirve.
            $table->string('CLAVE_V', 16);
            $table->dateTime('VENCE')->index();
            $table->dateTime('CREADO');

            $table->foreign('ID_USUARIO')->references('ID_USUARIO')->on('usuarios')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pases_reingreso');
    }
};
