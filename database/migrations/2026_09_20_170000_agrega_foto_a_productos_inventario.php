<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto del producto del almacen.
 *
 * Guarda la MISMA forma de enlace que el resto de archivos de la app:
 * "/storage/google/<id de Drive>". El archivo vive en Drive y la app lo sirve por esa
 * ruta, asi que la carpeta NO tiene que ser publica — es el mismo camino que ya usan las
 * fotos de equipos (FOTO_EQUIPO) y las del catalogo.
 *
 * Con guarda de hasColumn: la base local va por detras de la del servidor y esta migracion
 * tiene que poder correr en las dos sin romperse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('productos_inventario', 'FOTO')) {
            return;
        }

        Schema::table('productos_inventario', function (Blueprint $table) {
            $table->string('FOTO', 255)->nullable()->after('UBICACION');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('productos_inventario', 'FOTO')) {
            return;
        }

        Schema::table('productos_inventario', function (Blueprint $table) {
            $table->dropColumn('FOTO');
        });
    }
};
