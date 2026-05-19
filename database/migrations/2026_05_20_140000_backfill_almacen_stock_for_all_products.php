<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: garantiza que TODO producto del catalogo (productos_inventario) tenga
 * una fila en almacen_stock para CADA almacen ACTIVO, con CANTIDAD=0 si no existia.
 *
 * Motivo (pedido del cliente 2026-05-19): el buscador / autocomplete de cualquier
 * modulo de almacen filtra por filas de almacen_stock. Productos creados sin un
 * contexto de almacen (API directa, ediciones legacy, importaciones manuales)
 * quedaban en el catalogo pero INVISIBLES en cualquier almacen — el cliente no
 * podia encontrarlos para registrar entradas.
 *
 * Esta migracion deja el sistema en un estado coherente:
 *   - Todo producto activo aparece en CADA almacen activo (con CANTIDAD=0)
 *   - Las filas existentes con CANTIDAD > 0 NO se tocan (idempotente)
 *   - El controlador AlmacenController::storeProducto ahora mantiene esta
 *     invariante para todo producto nuevo (loopea por todos los almacenes
 *     activos y llama a asegurarStock)
 *
 * El UNIQUE constraint `uq_stock_alm_prod` sobre (ID_ALMACEN, ID_PRODUCTO) ya existe
 * en almacen_stock — el WHERE NOT EXISTS es defensa adicional para que el INSERT
 * nunca explote en violacion de unique aunque el unique se pierda en algun esquema.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("
            INSERT INTO almacen_stock (ID_ALMACEN, ID_PRODUCTO, CANTIDAD, created_at, updated_at)
            SELECT a.ID_ALMACEN, p.ID_PRODUCTO, 0, NOW(), NOW()
            FROM almacenes a
            CROSS JOIN productos_inventario p
            WHERE a.ESTATUS = 'ACTIVO'
              AND p.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM almacen_stock s
                  WHERE s.ID_ALMACEN = a.ID_ALMACEN
                    AND s.ID_PRODUCTO = p.ID_PRODUCTO
              )
        ");
    }

    public function down(): void
    {
        // No-op: revertir borraria filas que pueden tener CANTIDAD_MINIMA o
        // FECHA_ULT_MOVIMIENTO ajustados manualmente; ademas no hay forma fiable
        // de distinguir las filas "creadas por backfill" de las legitimas. La
        // migracion es de un solo sentido — el sistema gana coherencia y no
        // tiene sentido hacer rollback.
    }
};
