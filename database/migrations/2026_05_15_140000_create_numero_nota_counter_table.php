<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla contador para emitir folios de Nota de Entrega de forma serializada.
 *
 * El método anterior `MovimientoInventario::generarNumeroNota` (max+1 sobre
 * `movimientos_inventario.NUMERO_NOTA`) NO era serializable: dos transacciones
 * concurrentes podían leer el mismo MAX y emitir el mismo folio porque NUMERO_NOTA
 * no puede ser UNIQUE (varias líneas del mismo lote comparten el folio).
 *
 * Esta tabla guarda un único registro por año con el último folio emitido. La
 * generación toma el row con `lockForUpdate` dentro de la misma transacción del
 * lote → la segunda petición espera al COMMIT de la primera y obtiene el folio
 * siguiente. Cero duplicados, sin cambiar la unicidad de NUMERO_NOTA.
 *
 * Convención: ANIO es PK; SIGUIENTE es el último folio EMITIDO (próximo será +1).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('numero_nota_counter', function (Blueprint $t) {
            $t->smallInteger('ANIO')->unsigned()->primary();
            $t->integer('SIGUIENTE')->unsigned()->default(0);
            $t->timestamps();
        });

        // Bootstrap: sembramos el año actual con el MAX existente para que el primer
        // folio post-deploy sea consecutivo con los emitidos por el método viejo.
        $year = (int) date('Y');
        $max  = (int) DB::table('movimientos_inventario')
            ->whereNotNull('NUMERO_NOTA')
            ->where('NUMERO_NOTA', 'like', "NE-{$year}-%")
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(NUMERO_NOTA, '-', -1) AS UNSIGNED)) as max_n")
            ->value('max_n');

        DB::table('numero_nota_counter')->insert([
            'ANIO'       => $year,
            'SIGUIENTE'  => $max,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('numero_nota_counter');
    }
};
