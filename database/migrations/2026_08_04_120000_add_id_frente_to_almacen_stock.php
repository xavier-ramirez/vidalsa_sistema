<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saldo separado POR PROYECTO dentro de un mismo almacén.
     *
     * Por qué: `almacen_stock` era UNIQUE (ID_ALMACEN, ID_PRODUCTO) — un saldo por
     * producto y almacén. PATIO EL TIGRE sirve a 6 frentes a la vez, así que el material
     * de los 6 proyectos se sumaba en un único número y dos notas de entrega para
     * proyectos distintos quedaban indistinguibles.
     *
     * ID_FRENTE = 0 es la BOLSA COMÚN del almacén: material sin proyecto asignado
     * (ajustes de conteo, stock inicial, cargas antiguas). Es utilizable por cualquier
     * proyecto — una salida consume primero del saldo de su proyecto y luego de aquí.
     *
     * Se usa 0 y NO NULL a propósito: MySQL admite varios NULL dentro de un índice
     * único, así que con NULL se podrían crear filas duplicadas justo en el caso de la
     * bolsa común, que es el más habitual. Por ese mismo 0 la columna no lleva FK a
     * `frentes_trabajo` (no existe un frente 0); la integridad la garantiza
     * InventarioService, que es el único punto que escribe el saldo.
     *
     * Los almacenes de un solo frente (BARCELONA y cualquier mono-proyecto) operan
     * SIEMPRE con ID_FRENTE = 0 — ver Almacen::separaPorProyecto(). Para ellos esta
     * migración no cambia nada: su fila única pasa a llevar frente 0.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('almacen_stock', 'ID_FRENTE')) {
            Schema::table('almacen_stock', function (Blueprint $table) {
                $table->unsignedBigInteger('ID_FRENTE')->default(0)->after('ID_PRODUCTO')
                      ->comment('Proyecto dueño del saldo. 0 = bolsa común del almacén (sin asignar)');
            });
        }

        // ORDEN IMPORTANTE: primero se CREA el índice nuevo y solo después se borra el
        // viejo. Al revés, MySQL aborta con "Cannot drop index 'uq_stock_alm_prod':
        // needed in a foreign key constraint" — la FK de ID_ALMACEN se apoya en él y no
        // puede quedarse sin ningún índice que la cubra ni por un instante. El nuevo
        // empieza por las mismas dos columnas, así que sirve de relevo; la FK de
        // ID_PRODUCTO sigue cubierta por idx_stock_prod.
        if (!$this->indiceExiste('uq_stock_alm_prod_frente')) {
            Schema::table('almacen_stock', function (Blueprint $table) {
                $table->unique(['ID_ALMACEN', 'ID_PRODUCTO', 'ID_FRENTE'], 'uq_stock_alm_prod_frente');
            });
        }
        if ($this->indiceExiste('uq_stock_alm_prod')) {
            Schema::table('almacen_stock', function (Blueprint $table) {
                $table->dropUnique('uq_stock_alm_prod');
            });
        }
    }

    private function indiceExiste(string $nombre): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            ['almacen_stock', $nombre]
        ) !== [];
    }

    /**
     * Al revertir hay que CONSOLIDAR antes de restaurar el índice de dos columnas: si un
     * producto quedó repartido entre varios proyectos, esas filas violarían el UNIQUE
     * viejo y el rollback moriría a mitad. Se suman los saldos en la fila más antigua
     * (menor ID_STOCK) y se borran las demás, que es exactamente el estado previo:
     * un único saldo por almacén y producto.
     */
    public function down(): void
    {
        DB::statement('
            UPDATE almacen_stock s
            JOIN (
                SELECT MIN(ID_STOCK) AS keep_id, ID_ALMACEN, ID_PRODUCTO, SUM(CANTIDAD) AS total
                FROM almacen_stock GROUP BY ID_ALMACEN, ID_PRODUCTO
            ) g ON g.keep_id = s.ID_STOCK
            SET s.CANTIDAD = g.total
        ');

        DB::statement('
            DELETE s FROM almacen_stock s
            JOIN (
                SELECT MIN(ID_STOCK) AS keep_id, ID_ALMACEN, ID_PRODUCTO
                FROM almacen_stock GROUP BY ID_ALMACEN, ID_PRODUCTO
            ) g ON g.ID_ALMACEN = s.ID_ALMACEN AND g.ID_PRODUCTO = s.ID_PRODUCTO
            WHERE s.ID_STOCK <> g.keep_id
        ');

        Schema::table('almacen_stock', function (Blueprint $table) {
            $table->dropUnique('uq_stock_alm_prod_frente');
            $table->unique(['ID_ALMACEN', 'ID_PRODUCTO'], 'uq_stock_alm_prod');
            $table->dropColumn('ID_FRENTE');
        });
    }
};
