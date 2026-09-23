<?php

namespace App\Services;

use App\Models\AlmacenKit;
use App\Models\AlmacenKitItem;
use App\Models\AlmacenKitModelo;
use App\Models\AlmacenStock;
use App\Models\ProductoInventario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Kits del almacén: recetas de materiales por modelo de equipo, para cargar una salida de un
 * golpe ("¿cuántos kits?" × lo que lleva cada uno).
 *
 * El kit NO descuenta nada: solo marca los productos en la salida del inventario, que sigue
 * siendo la de siempre (AlmacenController::registrarMovimientoLote) con su control de stock,
 * sus proyectos, el nº de parte y la Nota de Entrega. Por eso aquí no hay movimientos.
 *
 * Los modelos se guardan con las mismas referencias que la compatibilidad de un producto
 * (ID_ESPEC de las fichas o "TIPO|MARCA|MODELO" del auxiliar) y se muestran agrupados igual
 * (CompatibilidadProductoService::modelosAgrupados): un kit del "HOWO ZZ4257" sirve a todas
 * las fichas de ese modelo, y los productos que se le sugieren son los ligados a él.
 */
class KitAlmacenService
{
    /** Tope de materiales por kit y de modelos: un kit es una receta, no un catálogo. */
    public const MAX_ITEMS   = 60;
    public const MAX_MODELOS = 30;

    public function __construct(private CompatibilidadProductoService $compat)
    {
    }

    /**
     * Todos los kits como los pinta la pantalla —online y en la copia offline, que recibe
     * EXACTAMENTE esto (OfflineController)—:
     *   id, nombre, descripcion
     *   items   [{id_producto, codigo, nombre, um, cantidad}]  (cantidad POR kit)
     *   modelos [{origen, clave, refs, tipo, modelo}]           (agrupados como en la ficha)
     *   placas  [placas de los equipos de esos modelos]         (buscar el kit por placa)
     * Los materiales de productos borrados o inactivos no salen: no se pueden despachar.
     */
    public function catalogo(): Collection
    {
        $kits = AlmacenKit::with([
            'items.producto' => fn ($q) => $q->select('ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'ESTATUS'),
            'modelos',
        ])->orderBy('NOMBRE')->get();
        if ($kits->isEmpty()) {
            return collect();
        }

        $grupos = $this->compat->modelosAgrupados();
        $porRef = $this->indicePorRef($grupos);
        $modelosDe = $kits->mapWithKeys(fn ($k) => [$k->ID_KIT => $k->modelos
            ->map(fn ($m) => $porRef[$this->refDe($m)] ?? null)->filter()->unique('clave')->values()]);
        $placas = $this->compat->placasPorModelo($modelosDe->flatten(1)->unique('clave')->values());

        return $kits->map(fn ($k) => [
            'id'          => (int) $k->ID_KIT,
            'nombre'      => (string) $k->NOMBRE,
            'descripcion' => (string) ($k->DESCRIPCION ?? ''),
            'items'       => $k->items
                ->filter(fn ($i) => $i->producto && $i->producto->ESTATUS === 'ACTIVO')
                ->map(fn ($i) => [
                    'id_producto' => (int) $i->ID_PRODUCTO,
                    'codigo'      => (string) $i->producto->CODIGO,
                    'nombre'      => (string) $i->producto->NOMBRE,
                    'um'          => (string) $i->producto->UM,
                    'cantidad'    => (float) $i->CANTIDAD,
                ])->values(),
            'modelos'     => $modelosDe[$k->ID_KIT]->map(fn ($g) => $this->vistaModelo($g))->values(),
            'placas'      => $modelosDe[$k->ID_KIT]
                ->flatMap(fn ($g) => $placas[$g['clave']] ?? [])->unique()->values(),
        ])->values();
    }

    /** Modelos para elegir en el editor del kit (todos, agrupados como en la ficha del producto). */
    public function modelosParaElegir(): Collection
    {
        return $this->compat->modelosAgrupados()->map(fn ($g) => $this->vistaModelo($g));
    }

    /** Lo que la pantalla necesita de un modelo agrupado (sin `bases`, que es interno). */
    private function vistaModelo(array $g): array
    {
        return ['origen' => $g['origen'], 'clave' => $g['clave'], 'refs' => $g['refs'], 'tipo' => $g['tipo'], 'modelo' => $g['modelo']];
    }

    /**
     * Productos que "lleva" un modelo (su compatibilidad): el editor los propone primero, con
     * la cantidad por servicio de su vínculo. Solo activos.
     */
    public function sugeridos(string $origen, array $refs): Collection
    {
        $ligados = $this->compat->productosDeModelo($origen, $refs)->keyBy('id_producto');
        if ($ligados->isEmpty()) {
            return collect();
        }
        return ProductoInventario::activos()->whereIn('ID_PRODUCTO', $ligados->keys())
            ->orderBy('NOMBRE')->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM'])
            ->map(fn ($p) => [
                'id_producto' => (int) $p->ID_PRODUCTO,
                'codigo'      => (string) $p->CODIGO,
                'nombre'      => (string) $p->NOMBRE,
                'um'          => (string) $p->UM,
                'cantidad'    => (float) $ligados[$p->ID_PRODUCTO]['cantidad'],
            ])->values();
    }

    /**
     * Existencia de cada producto en un almacén, sumando todos sus proyectos: es lo que la
     * salida puede tomar (en cascada, ver InventarioService::aplicarSalidaConCascada). La
     * copia offline suma igual (OfflineController::consultaStock).
     *
     * @return array<int, float>  [id_producto => cantidad]
     */
    public function existencias(int $idAlmacen, array $idsProducto): array
    {
        if (! $idsProducto) {
            return [];
        }
        return AlmacenStock::where('ID_ALMACEN', $idAlmacen)->whereIn('ID_PRODUCTO', $idsProducto)
            ->groupBy('ID_PRODUCTO')->selectRaw('ID_PRODUCTO, SUM(CANTIDAD) as CANTIDAD')
            ->pluck('CANTIDAD', 'ID_PRODUCTO')
            ->map(fn ($q) => (float) $q)->all();
    }

    /**
     * Crea ($kit null) o reemplaza un kit: nombre, descripción, materiales y modelos. La
     * forma de los datos ya viene validada por el controlador; aquí se comprueba contra la
     * base (productos activos, modelos que existen) y se guarda todo en una transacción.
     *
     * @param  array{nombre:string, descripcion:?string, items:array, modelos:array}  $datos
     */
    public function guardar(?AlmacenKit $kit, array $datos, ?int $idUsuario): AlmacenKit
    {
        $items = collect($datos['items'] ?? [])->map(fn ($i) => [
            'id_producto' => (int) $i['id_producto'],
            'cantidad'    => round((float) $i['cantidad'], 3),
        ]);
        $activos = ProductoInventario::activos()->whereIn('ID_PRODUCTO', $items->pluck('id_producto'))->pluck('ID_PRODUCTO');
        if ($activos->count() !== $items->count()) {
            throw new InvalidArgumentException('Uno de los materiales ya no está activo en el catálogo.');
        }

        $modelos = $this->filasDeModelos($datos['modelos'] ?? []);

        return DB::transaction(function () use ($kit, $datos, $idUsuario, $items, $modelos) {
            $kit ??= new AlmacenKit(['CREADO_POR' => $idUsuario]);
            $kit->fill([
                'NOMBRE'      => mb_strtoupper(trim($datos['nombre'])),
                'DESCRIPCION' => trim((string) ($datos['descripcion'] ?? '')) ?: null,
            ]);
            // Si solo cambian materiales o modelos, touch(): el kit también tiene que quedar
            // "cambiado", que es lo que avisa a la copia offline (AlmacenKit::booted).
            if ($kit->exists && ! $kit->isDirty()) {
                $kit->touch();
            } else {
                $kit->save();
            }

            AlmacenKitItem::where('ID_KIT', $kit->ID_KIT)->delete();
            foreach ($items->values() as $orden => $i) {
                AlmacenKitItem::create(['ID_KIT' => $kit->ID_KIT, 'ID_PRODUCTO' => $i['id_producto'],
                    'CANTIDAD' => $i['cantidad'], 'ORDEN' => $orden]);
            }
            AlmacenKitModelo::where('ID_KIT', $kit->ID_KIT)->delete();
            foreach ($modelos as $m) {
                AlmacenKitModelo::create(['ID_KIT' => $kit->ID_KIT] + $m);
            }
            return $kit;
        });
    }

    /**
     * Filas de almacen_kit_modelos a partir de lo que manda el editor ([{origen, refs}]):
     * cada ficha del modelo, o el auxiliar. Solo se aceptan refs que existen HOY en los
     * modelos agrupados (así no se guarda una ficha borrada ni un auxiliar inventado).
     */
    private function filasDeModelos(array $modelos): array
    {
        $validas = $this->indicePorRef($this->compat->modelosAgrupados());
        $filas = [];
        foreach ($modelos as $m) {
            foreach ((array) ($m['refs'] ?? []) as $ref) {
                $ref = ($m['origen'] ?? '') . ':' . $ref;
                if (! isset($validas[$ref])) {
                    throw new InvalidArgumentException('Uno de los modelos de equipo ya no existe.');
                }
                if (str_starts_with($ref, 'modelo:')) {
                    $filas[$ref] = ['ID_ESPEC' => (int) substr($ref, 7)];
                } else {
                    [$tipo, $marca, $modelo] = array_pad(explode('|', substr($ref, 4), 3), 3, '');
                    $filas[$ref] = ['AUX_TIPO' => $tipo, 'AUX_MARCA' => $marca, 'AUX_MODELO' => $modelo];
                }
            }
        }
        return array_values($filas);
    }

    /** [origen:ref => grupo] de los modelos agrupados, para ubicar una fila guardada en su grupo. */
    private function indicePorRef(Collection $grupos): array
    {
        $indice = [];
        foreach ($grupos as $g) {
            foreach ($g['refs'] as $ref) {
                $indice[$g['origen'] . ':' . $ref] = $g;
            }
        }
        return $indice;
    }

    /** La referencia de una fila guardada, con la misma forma que indicePorRef. */
    private function refDe(AlmacenKitModelo $m): string
    {
        return $m->ID_ESPEC !== null
            ? 'modelo:' . $m->ID_ESPEC
            : 'aux:' . $m->AUX_TIPO . '|' . $m->AUX_MARCA . '|' . $m->AUX_MODELO;
    }
}
