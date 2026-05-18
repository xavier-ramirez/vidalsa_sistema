<?php

use App\Casts\MojibakeFix;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Limpieza definitiva de mojibake (UTF-8 doble-encoded) en los datos legacy.
 *
 * Antes de esta migration teniamos un Eloquent cast (App\Casts\MojibakeFix) que
 * decodea AL LEER — pero el dato seguia roto en disco. Si alguna consulta usaba
 * raw SQL o un PDF/export tocaba bypass del cast, el mojibake reaparecia.
 *
 * Esta migration UPDATEa una vez todas las filas con la signature de mojibake
 * ("Ã" = bytes c3 83), aplicando MojibakeFix::fix() a cada valor y guardando
 * el resultado. Despues de correr esta migration:
 *  - El dato en disco queda limpio (acentos correctos en BD).
 *  - El cast del modelo sigue activo como defensa de profundidad para
 *    importaciones futuras que vuelvan a meter mojibake.
 *  - Cualquier export/raw SQL ve el texto correcto sin pasar por el cast.
 *
 * No-op si no encuentra mojibake — corre siempre, cuesta tiempo solo en datos
 * que realmente tengan el problema. Sin transaction global porque los UPDATEs
 * son idempotentes y un fallo a mitad de tabla no corrompe nada (la siguiente
 * corrida fixea lo que falte).
 *
 * Cobertura — todas las columnas TEXT/VARCHAR que el cast MojibakeFix toca en
 * los modelos correspondientes, mas las que aparecen en busquedas frecuentes.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private array $columnas = [
        'frentes_trabajo'        => ['NOMBRE_FRENTE', 'UBICACION'],
        'almacenes'              => ['NOMBRE', 'UBICACION', 'ALMACENISTA', 'CARGO_ALMACENISTA', 'NOTAS'],
        'productos_inventario'   => ['NOMBRE', 'CATEGORIA', 'UBICACION', 'NOTAS'],
        'movimientos_inventario' => ['MOTIVO', 'SOLICITANTE', 'DEPARTAMENTO', 'NUMERO_CONTRATO', 'NUMERO_RQ', 'NOTAS'],
    ];

    public function up(): void
    {
        foreach ($this->columnas as $tabla => $cols) {
            if (!\Schema::hasTable($tabla)) continue;
            $pk = $this->primaryKey($tabla);
            foreach ($cols as $col) {
                if (!\Schema::hasColumn($tabla, $col)) continue;
                $this->fixColumna($tabla, $pk, $col);
            }
        }
    }

    /**
     * Si esta migration se revierte, NO restauramos el mojibake — no tiene
     * sentido re-corromper el dato. Si necesitas el dato roto de vuelta,
     * restaura un backup.
     */
    public function down(): void
    {
        // intencionalmente vacio: no-op
    }

    private function primaryKey(string $tabla): string
    {
        // Convencion del proyecto: PK es siempre ID_<TABLA_SINGULAR_UPPER>.
        // frentes_trabajo -> ID_FRENTE, almacenes -> ID_ALMACEN,
        // productos_inventario -> ID_PRODUCTO, movimientos_inventario -> ID_MOVIMIENTO.
        return match ($tabla) {
            'frentes_trabajo'        => 'ID_FRENTE',
            'almacenes'              => 'ID_ALMACEN',
            'productos_inventario'   => 'ID_PRODUCTO',
            'movimientos_inventario' => 'ID_MOVIMIENTO',
        };
    }

    private function fixColumna(string $tabla, string $pk, string $col): void
    {
        // SELECT id + columna donde haya signature de mojibake ("Ã" = c3 83).
        // Usamos query builder en chunks para no cargar 50k filas en memoria
        // de una sola — relevante en productos_inventario (873 filas con
        // mojibake al momento de escribir esto, pero la tabla crece).
        $afectados  = 0;
        $totalFilas = 0;

        DB::table($tabla)
            ->select($pk, $col)
            ->whereRaw("HEX($col) LIKE ?", ['%C383%'])
            ->orderBy($pk)
            ->chunkById(500, function ($filas) use ($tabla, $pk, $col, &$afectados, &$totalFilas) {
                foreach ($filas as $fila) {
                    $totalFilas++;
                    $original = $fila->$col;
                    $fixed    = MojibakeFix::fix($original);
                    if ($fixed !== null && $fixed !== $original) {
                        DB::table($tabla)->where($pk, $fila->$pk)->update([$col => $fixed]);
                        $afectados++;
                    }
                }
            }, $pk);

        if ($totalFilas > 0) {
            Log::info("[mojibake fix] $tabla.$col: $afectados/$totalFilas filas actualizadas");
        }
    }
};
