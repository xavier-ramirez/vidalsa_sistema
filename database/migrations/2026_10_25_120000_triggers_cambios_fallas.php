<?php

use App\Support\RegistroCambios;
use Illuminate\Database\Migrations\Migration;

/**
 * Triggers del registro de cambios: los de `fallas` anotan ahora también el reporte mismo
 * ('fallas', ID_FALLA), que viaja en su propia copia local (App\Support\Sincronizacion\
 * DominioFallas). Antes solo anotaban su equipo o auxiliar, y un reporte borrado no llegaba
 * como borrado. crearTriggers() recrea todos: los demás quedan iguales.
 *
 * Igual que las anteriores: si el usuario de la base no puede crear triggers no falla; la
 * sincronización trabaja en su modo de respaldo hasta pegar `php artisan cambios:sql` en
 * phpMyAdmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        RegistroCambios::crearTriggers();
    }

    public function down(): void
    {
        // Los triggers de antes son los mismos, menos la línea de 'fallas': recrearlos con el
        // código de antes es volver a correr la migración anterior.
    }
};
