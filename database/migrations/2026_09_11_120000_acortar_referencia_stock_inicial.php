<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acorta el texto de los movimientos de STOCK INICIAL (producto creado con cantidad inicial).
 *
 * Se guardaban con REFERENCIA "STOCK INICIAL registro de nuevo material" y MOTIVO "Stock
 * inicial al crear el producto". En /admin/almacen/movimientos la celda Referencia salía
 * enorme, y además el MOTIVO de una ENTRADA se pinta como PROVEEDOR (ícono de camión): la
 * frase aparecía repetida en el sitio del proveedor, que un stock inicial no tiene.
 * AlmacenController::storeProducto ya los crea con REFERENCIA "STOCK INICIAL" y sin MOTIVO;
 * esto deja igual los que ya existían.
 *
 * Se filtra por los DOS textos exactos que ponía el sistema, así no se toca ninguna entrada
 * escrita a mano que casualmente empiece por "STOCK INICIAL".
 *
 * REF_NUEVA se escribe aquí a mano y no se toma de MovimientoInventario::REF_STOCK_INICIAL:
 * una migración es una foto de este momento y tiene que dar el mismo resultado aunque la
 * constante cambie mañana (mismo criterio que las demás migraciones de datos).
 */
return new class extends Migration
{
    private const REF_VIEJA    = 'STOCK INICIAL registro de nuevo material';
    private const MOTIVO_VIEJO = 'Stock inicial al crear el producto';
    private const REF_NUEVA    = 'STOCK INICIAL';

    public function up(): void
    {
        DB::table('movimientos_inventario')
            ->where('REFERENCIA', self::REF_VIEJA)
            ->where('MOTIVO', self::MOTIVO_VIEJO)
            ->update(['REFERENCIA' => self::REF_NUEVA, 'MOTIVO' => null]);
    }

    public function down(): void
    {
        // Intencionalmente vacío: después de up() no queda forma de distinguir las filas que
        // se acortaron de una entrada escrita a mano como "STOCK INICIAL" sin proveedor, y
        // devolverle el texto largo a esa sería inventarle un dato. Además el texto corto es
        // válido con cualquier versión del código.
    }
};
