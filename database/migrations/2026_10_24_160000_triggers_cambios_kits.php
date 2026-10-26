<?php

use App\Support\RegistroCambios;
use Illuminate\Database\Migrations\Migration;

/**
 * Triggers del registro de cambios para los kits del almacén: viajan en los catálogos de la
 * copia local (App\Support\Sincronizacion\DominioAlmacen). crearTriggers() recrea todos: los
 * de antes quedan iguales y se suman los nuevos.
 *
 * Igual que las anteriores: si el usuario de la base no puede crear triggers no falla; la
 * sincronización trabaja en su modo de respaldo hasta pegar `php artisan cambios:sql` en
 * phpMyAdmin.
 */
return new class extends Migration
{
    private const TABLAS = ['almacen_kits', 'almacen_kit_items', 'almacen_kit_modelos'];

    public function up(): void
    {
        RegistroCambios::crearTriggers();
    }

    public function down(): void
    {
        RegistroCambios::borrarTriggers(self::TABLAS);
    }
};
