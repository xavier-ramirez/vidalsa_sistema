<?php

use App\Models\CatalogoColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deja el COLOR de las unidades escrito como se guarda desde ahora (Equipo::setCOLORAttribute
 * → CatalogoColor::normalizar: mayúsculas, sin espacios de más, en masculino). Sin esto un
 * "Roja" viejo quedaría fuera del filtro Color "ROJO" y aparecería dos veces en su lista.
 *
 * Fila por fila y no por valor distinto: la colación no distingue mayúsculas, así que un
 * DISTINCT juntaría "rojo" con "ROJO" y dejaría el primero sin tocar.
 * Idempotente. down() no hace nada: la escritura original no se puede reconstruir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('equipos')->whereNotNull('COLOR')->where('COLOR', '!=', '')
            ->select('ID_EQUIPO', 'COLOR')->orderBy('ID_EQUIPO')
            ->chunk(500, function ($filas) {
                foreach ($filas as $f) {
                    $normal = CatalogoColor::normalizar($f->COLOR);
                    if ($normal !== $f->COLOR) {
                        DB::table('equipos')->where('ID_EQUIPO', $f->ID_EQUIPO)->update(['COLOR' => $normal]);
                    }
                }
            });
    }

    public function down(): void
    {
    }
};
