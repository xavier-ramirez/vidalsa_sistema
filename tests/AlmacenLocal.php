<?php

namespace Tests;

use App\Models\Usuario;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * El inventario (/admin/almacen), la bitácora, el historial de un producto y las Notas de
 * Entrega se filtran, cuentan y pintan en el navegador (public/js/maquinaria/almacen_local.js)
 * con la copia de GET /sync/almacen. Estas pruebas hacen lo mismo: bajan la copia como el
 * usuario y corren ese JS con Node (tests/almacen_local.cjs), así prueban el código que de
 * verdad usa la pantalla. Los filtros llevan los nombres de la URL (search, id_almacen…).
 */
trait AlmacenLocal
{
    /** Tabla del inventario: {html, hasMore, nextOffset, aproximada, stats, distribucionHtml}. */
    protected function inventarioLocal(Usuario $usuario, array $filtros = [], int $offset = 0, int $cuantos = 0): array
    {
        return $this->correrAlmacenLocal($usuario, [
            'accion' => 'inventario', 'filtros' => (object) $filtros, 'offset' => $offset, 'cuantos' => $cuantos,
        ]);
    }

    /** Ids de producto que manda el botón «Copia de inventario» (Excel). */
    protected function idsInventarioLocal(Usuario $usuario, array $filtros = []): array
    {
        return $this->correrAlmacenLocal($usuario, ['accion' => 'ids', 'filtros' => (object) $filtros]);
    }

    /** Panel lateral «En otros almacenes» de un producto (html). */
    protected function panelOtrosLocal(Usuario $usuario, int $idProducto, ?int $idAlmacen): string
    {
        return $this->correrAlmacenLocal($usuario, ['accion' => 'panelOtros', 'idProducto' => $idProducto, 'idAlmacen' => $idAlmacen]);
    }

    /** Bitácora: {html, pagination, total, ids}. `mini` = el historial de un producto. */
    protected function movimientosLocal(Usuario $usuario, array $filtros = [], int $pagina = 1, bool $mini = false): array
    {
        return $this->correrAlmacenLocal($usuario, [
            'accion' => 'movimientos', 'filtros' => (object) $filtros, 'pagina' => $pagina, 'mini' => $mini,
            'puede' => [
                'superAdmin' => $usuario->can('super.admin'),
                'devolver' => $usuario->can('almacen.movimiento'),
                'corregir' => $usuario->can('almacen.nota.corregir'),
            ],
        ]);
    }

    /** Ranking «Consumo de inventario» (html). En Notas, `search` no es un producto. */
    protected function consumoLocal(Usuario $usuario, array $filtros = [], bool $conBusqueda = true): string
    {
        return $this->correrAlmacenLocal($usuario, ['accion' => 'consumo', 'filtros' => (object) $filtros, 'conBusqueda' => $conBusqueda]);
    }

    /** Historial de Notas de Entrega: {html, pagination, total}. */
    protected function notasLocal(Usuario $usuario, array $filtros = [], int $pagina = 1): array
    {
        return $this->correrAlmacenLocal($usuario, ['accion' => 'notas', 'filtros' => (object) $filtros, 'pagina' => $pagina]);
    }

    /** Catálogo de los buscadores (forma de ProductoInventario::listaAutocomplete). */
    protected function listaProductosLocal(Usuario $usuario): array
    {
        return $this->correrAlmacenLocal($usuario, ['accion' => 'listaProductos']);
    }

    private function correrAlmacenLocal(Usuario $usuario, array $entrada): mixed
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            $this->markTestSkipped('Hace falta Node para correr almacen_local.js.');
        }

        $entrada['copia'] = $this->actingAs($usuario)->getJson('/sync/almacen')->assertOk()->json();
        $entrada['porDefecto'] = $usuario->almacenPorDefecto();
        $p = new Process([$node, base_path('tests/almacen_local.cjs')]);
        $p->setInput(json_encode($entrada));
        $p->setTimeout(120);
        $p->mustRun();

        return json_decode($p->getOutput(), true);
    }
}
