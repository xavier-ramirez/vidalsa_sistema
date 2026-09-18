<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A_MANO: 1 = eso no lo arregla ningun boton, lo tiene que mirar una persona (el PDF es de
     * otro vehiculo, se leyo a medias, no se pudo confirmar de quien es, o alguien cambio la
     * ficha despues de leer el documento).
     *
     * VA EN SU PROPIO ARCHIVO a proposito. La columna se habia añadido dentro de
     * 2026_09_17_120000_create_verificacion_documento_registro_table.php, pero ese archivo YA
     * se habia subido y corrido antes sin ella: Laravel guarda el nombre del archivo en la
     * tabla `migrations` y no lo vuelve a ejecutar nunca, asi que en el servidor esa columna no
     * se habria creado jamas y la aplicacion fallaria al escribirla. La regla: una columna
     * nueva sobre algo ya desplegado siempre en una migracion nueva.
     *
     * El guardaespaldas hasColumn deja que convivan las dos: en una base recien creada la
     * columna la crea el archivo de arriba y aqui no se hace nada.
     */
    public function up(): void
    {
        if (!Schema::hasTable('verificacion_documento_registro')) return;

        if (!Schema::hasColumn('verificacion_documento_registro', 'A_MANO')) {
            Schema::table('verificacion_documento_registro', function (Blueprint $table) {
                $table->boolean('A_MANO')->default(false)->index()->after('INTENTOS');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('verificacion_documento_registro', 'A_MANO')) {
            Schema::table('verificacion_documento_registro', fn (Blueprint $t) => $t->dropColumn('A_MANO'));
        }
    }
};
