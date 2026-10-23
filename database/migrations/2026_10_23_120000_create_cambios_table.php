<?php

use App\Support\RegistroCambios;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de cambios para la copia local de cada dispositivo: la tabla y sus triggers
 * (ver App\Support\RegistroCambios).
 *
 * Si el usuario de la base no puede crear triggers (MySQL 8 con registro binario y sin
 * SUPER), la migración NO falla: deja la tabla, avisa en el log y la sincronización usa su
 * modo de respaldo. Los triggers se crean después pegando `php artisan cambios:sql` en
 * phpMyAdmin con un usuario que sí pueda.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(RegistroCambios::TABLA)) {
            Schema::create(RegistroCambios::TABLA, function (Blueprint $table) {
                $table->bigIncrements('ID_CAMBIO');
                $table->string('TABLA', 30);
                $table->unsignedBigInteger('ID_FILA');
                $table->timestamp('created_at')->useCurrent();
                $table->index('created_at');   // la purga
                $table->index('TABLA');        // la marca de la purga (RegistroCambios::cubre)
            });
        }

        RegistroCambios::crearTriggers();
    }

    public function down(): void
    {
        RegistroCambios::borrarTriggers();
        Schema::dropIfExists(RegistroCambios::TABLA);
    }
};
