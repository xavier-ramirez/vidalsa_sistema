<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION','ESPECIAL') NOT NULL DEFAULT 'OPERACION'");
    }

    public function down(): void
    {
        DB::statement("UPDATE frentes_trabajo SET TIPO_FRENTE = 'OPERACION' WHERE TIPO_FRENTE = 'ESPECIAL'");
        DB::statement("ALTER TABLE frentes_trabajo MODIFY TIPO_FRENTE ENUM('RESGUARDO','OPERACION') NOT NULL DEFAULT 'OPERACION'");
    }
};
