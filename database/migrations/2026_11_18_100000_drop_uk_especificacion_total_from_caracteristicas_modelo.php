<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quita el índice ÚNICO uk_especificacion_total (MODELO, ANIO_ESPEC, ACEITE_MOTOR).
 *
 * La ficha es una por MODELO + año + TIPO (CaracteristicaModeloController::deSuTipo y
 * rechazarFichaRepetida): el mismo chasis puede ser camión de soldadura y cuadrillera, con
 * el mismo aceite. Ese índice no dejaba guardar la segunda (error de la base de datos al
 * crearla o editarla). Las repetidas de verdad las sigue rechazando rechazarFichaRepetida
 * (misma clave de modelo, año y tipo). Quedan los índices normales de MODELO y año.
 */
return new class extends Migration
{
    private const INDICE = 'uk_especificacion_total';

    private function existe(): bool
    {
        return collect(DB::select('SHOW INDEX FROM caracteristicas_modelo WHERE Key_name = ?', [self::INDICE]))->isNotEmpty();
    }

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql' && $this->existe()) {
            DB::statement('ALTER TABLE caracteristicas_modelo DROP INDEX ' . self::INDICE);
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql' && !$this->existe()) {
            DB::statement('ALTER TABLE caracteristicas_modelo ADD UNIQUE ' . self::INDICE . ' (MODELO, ANIO_ESPEC, ACEITE_MOTOR)');
        }
    }
};
