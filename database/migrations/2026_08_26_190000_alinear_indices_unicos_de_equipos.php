<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quita los dos indices UNICOS de `equipos` que la base real ya no tiene.
 *
 * La migracion que crea la tabla los declara (`CODIGO_PATIO`->unique() y
 * `SERIAL_CHASIS`->unique()), pero en la base de produccion no existen: se quitaron a
 * mano en algun momento. Levantar el sistema desde cero daba, por tanto, un esquema
 * DISTINTO del que esta corriendo, y no era un detalle cosmetico: hoy hay 3 equipos con
 * SERIAL_CHASIS = '' (cadena vacia, no NULL), y un indice unico NO admite la cadena
 * vacia repetida. O sea que una instalacion nueva no podia siquiera cargar los datos de
 * la que esta en marcha.
 *
 * Se alinea hacia lo que hay corriendo —quitarlos— y no al reves, porque el sistema
 * lleva tiempo funcionando sin ellos y volver a ponerlos cambiaria el comportamiento:
 * empezarian a fallar altas que hoy pasan. Si algun dia se quiere la unicidad de
 * verdad, CODIGO_PATIO la aguantaria ya mismo (1.113 estan en NULL, que el indice si
 * admite repetido, y no hay ni un valor duplicado), pero SERIAL_CHASIS necesitaria
 * antes pasar esas 3 cadenas vacias a NULL. Es una decision de negocio, no de esquema.
 */
return new class extends Migration
{
    /** Los nombres que les pone Laravel al declararlos con ->unique(). */
    private const INDICES = ['equipos_codigo_patio_unique', 'equipos_serial_chasis_unique'];

    public function up(): void
    {
        foreach (self::INDICES as $indice) {
            // En produccion ya no estan, asi que aqui no hace nada; en una base recien
            // migrada si. Se comprueba antes para que la migracion valga en las dos.
            if ($this->existe($indice)) {
                Schema::table('equipos', function (Blueprint $tabla) use ($indice) {
                    $tabla->dropIndex($indice);
                });
            }
        }
    }

    public function down(): void
    {
        // A proposito NO se vuelven a crear: con SERIAL_CHASIS = '' repetido, crearlos
        // falla y dejaria el rollback a medias. Si se quisieran de vuelta habria que
        // limpiar antes esos datos, y eso pide su propia migracion.
    }

    private function existe(string $indice): bool
    {
        return count(DB::select('SHOW INDEX FROM equipos WHERE Key_name = ?', [$indice])) > 0;
    }
};
