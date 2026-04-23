<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Agrega 'ESPECIAL' al enum TIPO_FRENTE. Idempotente y defensivo para producción:
     * - Valida que no haya valores fuera del dominio esperado antes del ALTER.
     * - Normaliza posibles typos comunes ('OPERACIONES' -> 'OPERACION') antes del ALTER.
     * - Si el enum ya incluye ESPECIAL (p.ej. por intervención manual), el ALTER es no-op.
     */
    public function up(): void
    {
        if (!Schema::hasTable('frentes_trabajo') || !Schema::hasColumn('frentes_trabajo', 'TIPO_FRENTE')) {
            return;
        }

        // Normaliza typos comunes antes de tocar el enum (defensivo).
        // MySQL ENUM es case-insensitive pero fallaría ante un valor literal fuera del dominio.
        DB::table('frentes_trabajo')->where('TIPO_FRENTE', 'OPERACIONES')->update(['TIPO_FRENTE' => 'OPERACION']);

        // Valida que no queden valores fuera del nuevo dominio antes de aplicar el ALTER.
        $invalidos = DB::table('frentes_trabajo')
            ->whereNotIn('TIPO_FRENTE', ['RESGUARDO', 'OPERACION', 'ESPECIAL'])
            ->count();
        if ($invalidos > 0) {
            throw new \RuntimeException(
                "Migracion abortada: existen {$invalidos} filas en frentes_trabajo con TIPO_FRENTE fuera de [RESGUARDO, OPERACION, ESPECIAL]. Normalice manualmente antes de migrar."
            );
        }

        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL') NOT NULL DEFAULT 'OPERACION'");
        Cache::forget('frentes_especial_ids');
    }

    public function down(): void
    {
        if (!Schema::hasTable('frentes_trabajo') || !Schema::hasColumn('frentes_trabajo', 'TIPO_FRENTE')) {
            return;
        }

        // Aviso de perdida de datos si existen filas ESPECIAL.
        $especiales = DB::table('frentes_trabajo')->where('TIPO_FRENTE', 'ESPECIAL')->count();
        if ($especiales > 0) {
            // Degradamos ESPECIAL -> OPERACION; esto es intencional para poder bajar el enum.
            DB::table('frentes_trabajo')->where('TIPO_FRENTE', 'ESPECIAL')->update(['TIPO_FRENTE' => 'OPERACION']);
        }

        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION') NOT NULL DEFAULT 'OPERACION'");
        Cache::forget('frentes_especial_ids');
    }
};
