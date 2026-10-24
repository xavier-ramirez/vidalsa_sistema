<?php

use App\Support\RegistroCambios;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Triggers del registro de cambios para las tablas del almacén (copia local del dominio
 * 'almacen', ver App\Support\Sincronizacion\DominioAlmacen). crearTriggers() recrea todos:
 * los de antes quedan iguales y se suman los nuevos.
 *
 * Igual que la primera migración: si el usuario de la base no puede crear triggers no
 * falla; la sincronización trabaja en su modo de respaldo hasta pegar `php artisan
 * cambios:sql` en phpMyAdmin.
 */
return new class extends Migration
{
    private const TABLAS = [
        'productos_inventario', 'producto_equivalencias', 'modelo_filtro', 'almacen_stock',
        'movimientos_inventario', 'correcciones_nota', 'almacenes', 'almacen_frentes',
    ];

    public function up(): void
    {
        RegistroCambios::crearTriggers();
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            foreach (['ai', 'au', 'ad'] as $sufijo) {
                DB::unprepared("DROP TRIGGER IF EXISTS cambios_{$tabla}_{$sufijo}");
            }
        }
    }
};
