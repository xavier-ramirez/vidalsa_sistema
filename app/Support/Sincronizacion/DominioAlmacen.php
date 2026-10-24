<?php

namespace App\Support\Sincronizacion;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\CorreccionNota;
use App\Models\FrenteTrabajo;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;

/**
 * Dominio ALMACÉN de la copia local: el catálogo de productos y, de los almacenes que el
 * usuario ve (Almacen::visiblesPara), los saldos y el kardex entero. El inventario
 * (/admin/almacen), la bitácora de movimientos, el historial de un producto y las Notas de
 * Entrega se filtran, cuentan y pintan SOLO con estas filas (almacen_local.js), con o sin
 * internet.
 *
 *   · productos:    todo el catálogo sin borrar (los inactivos también: el kardex los
 *                   nombra), con sus nºs de parte y los modelos de equipo que lo usan.
 *   · stock:        una fila por almacén, producto y proyecto (bolsa), como almacen_stock.
 *   · movimientos:  el kardex de los almacenes visibles. Lo que se devolvió de cada salida,
 *                   las notas vigentes y los préstamos entre bolsas los calcula el navegador
 *                   con estas mismas filas.
 *   · correcciones: qué producto de qué nota se corrigió (el botón «Corregida»).
 *
 * El nombre y el correo del usuario que registró van copiados en el movimiento y la tabla de
 * usuarios no se anota en el registro de cambios: un cambio de nombre llega con la próxima
 * carga completa (igual que en Movilizaciones).
 */
class DominioAlmacen
{
    /** Súbelo cuando cambie la forma de las filas: los clientes recargan su copia. */
    public const ESQUEMA = 1;

    public function __construct(private Usuario $usuario)
    {
    }

    /** Consultar el almacén no pide permiso: el alcance lo acota Almacen::visiblesPara. */
    public static function puede(Usuario $usuario): bool
    {
        return true;
    }

    /** Huella de lo que decide qué almacenes ve (ver Almacen::visiblesPara). */
    public function firma(): string
    {
        $a = $this->usuario->getAttributes();

        return substr(md5(implode('|', [
            (int) ($a['NIVEL_ACCESO_ALMACEN'] ?? 0),
            (string) ($a['ID_FRENTE_ASIGNADO'] ?? ''),
            (string) ($a['ID_FRENTE_BLOQUEADO'] ?? ''),
        ])), 0, 10);
    }

    /** @return array{datos: array, catalogos: array} */
    public function completo(): array
    {
        $visibles = $this->visibles();

        return [
            'datos' => [
                'productos'    => $this->filasProductos(ProductoInventario::query()),
                'stock'        => $this->filasStock(AlmacenStock::query()->whereIn('ID_ALMACEN', $visibles)),
                'movimientos'  => $this->filasMovimientos(MovimientoInventario::query()->whereIn('ID_ALMACEN', $visibles)),
                'correcciones' => $this->filasCorrecciones(CorreccionNota::query()),
            ],
            'catalogos' => $this->catalogos($visibles),
        ];
    }

    /**
     * Lo que cambió según el registro. null = mandar la copia entera: un almacén nuevo, borrado
     * o con otros frentes cambia QUÉ saldos y movimientos ve el usuario, y esas filas no
     * quedaron anotadas.
     *
     * @param  array<string,array<int>>  $agregados  agregado → ids (RegistroCambios::desde)
     * @return array{datos: array, borrados: array, catalogos: array}|null
     */
    public function cambios(array $agregados): ?array
    {
        if (! empty($agregados['almacenes'])) {
            return null;
        }

        $visibles = $this->visibles();
        $datos = [];
        $borrados = [];

        $tablas = [
            'productos'    => [$this->productosAfectados($agregados), fn ($ids) => $this->filasProductos(ProductoInventario::query()->whereIn('ID_PRODUCTO', $ids))],
            'stock'        => [$agregados['stock'] ?? [], fn ($ids) => $this->filasStock(AlmacenStock::query()->whereIn('ID_ALMACEN', $visibles)->whereIn('ID_STOCK', $ids))],
            'movimientos'  => [$agregados['movimientos'] ?? [], fn ($ids) => $this->filasMovimientos(MovimientoInventario::query()->whereIn('ID_ALMACEN', $visibles)->whereIn('ID_MOVIMIENTO', $ids))],
            'correcciones' => [$agregados['correcciones'] ?? [], fn ($ids) => $this->filasCorrecciones(CorreccionNota::query()->whereIn('ID_CORRECCION', $ids))],
        ];
        foreach ($tablas as $tabla => [$ids, $filas]) {
            if (! $ids) continue;
            $ver = $filas($ids);
            $fuera = array_values(array_diff(array_map('intval', $ids), array_column($ver, 'id')));
            if ($ver) $datos[$tabla] = $ver;
            if ($fuera) $borrados[$tabla] = $fuera;
        }

        return [
            'datos'     => $datos,
            'borrados'  => $borrados,
            'catalogos' => ! empty($agregados['frentes']) ? ['frentes' => $this->catalogoFrentes()] : [],
        ];
    }

    /**
     * Productos cuya FILA cambia: los anotados (catálogo, nºs de parte, modelos que lo usan) y
     * los que nombran un modelo de equipo o una marca que cambió (la ficha del modelo, o un
     * equipo de ese modelo).
     *
     * @return array<int>
     */
    private function productosAfectados(array $agregados): array
    {
        $especs = $agregados['fichas'] ?? [];
        if (! empty($agregados['equipos'])) {
            $especs = array_merge($especs, DB::table('equipos')->whereIn('ID_EQUIPO', $agregados['equipos'])
                ->whereNotNull('ID_ESPEC')->distinct()->pluck('ID_ESPEC')->all());
        }
        $ids = $agregados['productos'] ?? [];
        if ($especs) {
            $ids = array_merge($ids, DB::table('modelo_filtro')->whereIn('ID_ESPEC', $especs)->pluck('ID_PRODUCTO')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** IDs de los almacenes que el usuario ve. */
    private function visibles(): array
    {
        return Almacen::visiblesPara($this->usuario)->pluck('ID_ALMACEN')->map('intval')->all();
    }

    private function filasProductos($query): array
    {
        $productos = $query
            ->with([
                'equivalencias' => fn ($q) => $q->orderByDesc('ES_PRINCIPAL')->orderBy('ID_EQUIVALENCIA'),
                'modelosCompatibles:ID_ESPEC,TIPO,MODELO',
            ])
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA', 'UBICACION', 'FOTO', 'ESTATUS']);

        // La MARCA de cada modelo vive en `equipos`, no en la ficha: la del primer equipo de ese
        // modelo que la tenga (como el tooltip de la tabla de siempre).
        $especs = $productos->flatMap->modelosCompatibles->pluck('ID_ESPEC')->unique()->filter()->all();
        $marcaPorEspec = $especs
            ? \App\Models\Equipo::whereIn('ID_ESPEC', $especs)->orderBy('ID_EQUIPO')->get(['ID_ESPEC', 'MARCA'])
                ->groupBy('ID_ESPEC')->map(fn ($g) => $g->pluck('MARCA')->filter()->first())
            : collect();

        return $productos->map(fn (ProductoInventario $p) => [
            'id'      => (int) $p->ID_PRODUCTO,
            'codigo'  => $p->CODIGO,
            'nombre'  => $p->NOMBRE,
            'um'      => $p->UM,
            'cat'     => $p->CATEGORIA,
            'ubi'     => $p->UBICACION,
            'foto'    => $p->FOTO,
            'activo'  => $p->ESTATUS === 'ACTIVO',
            'partes'  => $p->equivalencias->pluck('NUMERO_PARTE')->values()->all(),
            'equipos' => $p->modelosCompatibles->map(fn ($m) => [
                't'  => $m->TIPO,
                'm'  => $marcaPorEspec[$m->ID_ESPEC] ?? null,
                'mo' => $m->MODELO,
            ])->values()->all(),
        ])->all();
    }

    private function filasStock($query): array
    {
        return $query
            ->get(['ID_STOCK', 'ID_ALMACEN', 'ID_PRODUCTO', 'ID_FRENTE', 'CANTIDAD', 'CANTIDAD_MINIMA', 'FECHA_ULT_MOVIMIENTO'])
            ->map(fn (AlmacenStock $s) => [
                'id'     => (int) $s->ID_STOCK,
                'alm'    => (int) $s->ID_ALMACEN,
                'prod'   => (int) $s->ID_PRODUCTO,
                'frente' => (int) $s->ID_FRENTE,
                'cant'   => (float) $s->CANTIDAD,
                'min'    => $s->CANTIDAD_MINIMA !== null ? (float) $s->CANTIDAD_MINIMA : null,
                'fecha'  => $s->FECHA_ULT_MOVIMIENTO?->format('Y-m-d H:i:s'),
            ])
            ->all();
    }

    private function filasMovimientos($query): array
    {
        return $query
            ->with('usuario:ID_USUARIO,NOMBRE_COMPLETO,CORREO_ELECTRONICO')
            ->get()
            ->map(fn (MovimientoInventario $m) => [
                'id'        => (int) $m->ID_MOVIMIENTO,
                'alm'       => (int) $m->ID_ALMACEN,
                'prod'      => (int) $m->ID_PRODUCTO,
                'tipo'      => $m->TIPO,
                'cant'      => (float) $m->CANTIDAD,
                'ant'       => (float) $m->CANTIDAD_ANTERIOR,
                'res'       => (float) $m->CANTIDAD_RESULTANTE,
                'fecha'     => $m->FECHA?->format('Y-m-d'),
                'creado'    => $m->created_at?->format('Y-m-d H:i:s'),
                'contra'    => $m->ID_ALMACEN_CONTRAPARTE ? (int) $m->ID_ALMACEN_CONTRAPARTE : null,
                'rel'       => $m->ID_MOVIMIENTO_RELACIONADO ? (int) $m->ID_MOVIMIENTO_RELACIONADO : null,
                'frente'    => $m->ID_FRENTE ? (int) $m->ID_FRENTE : null,
                'fsaldo'    => $m->ID_FRENTE_SALDO !== null ? (int) $m->ID_FRENTE_SALDO : null,
                'ref'       => $m->REFERENCIA,
                'parte'     => $m->NUMERO_PARTE,
                'nota'      => $m->NUMERO_NOTA,
                'rq'        => $m->NUMERO_RQ,
                'contrato'  => $m->NUMERO_CONTRATO,
                'solicita'  => $m->SOLICITANTE,
                'motivo'    => $m->MOTIVO,
                'notas'     => $m->NOTAS,
                'usuario'   => $m->usuario?->NOMBRE_COMPLETO,
                'correo'    => $m->usuario?->CORREO_ELECTRONICO,
            ])
            ->all();
    }

    private function filasCorrecciones($query): array
    {
        return $query->get(['ID_CORRECCION', 'NUMERO_NOTA', 'ID_PRODUCTO'])
            ->map(fn (CorreccionNota $c) => [
                'id'   => (int) $c->ID_CORRECCION,
                'nota' => $c->NUMERO_NOTA,
                'prod' => (int) $c->ID_PRODUCTO,
            ])
            ->all();
    }

    /**
     * almacenes: todos los que no están borrados (el kardex nombra la contraparte aunque el
     * usuario no la vea); `visible` = los suyos, y `separa` = lleva el saldo por proyecto.
     * frentes: con los borrados, porque el historial los sigue nombrando.
     */
    private function catalogos(array $visibles): array
    {
        $ver = array_flip($visibles);

        return [
            'almacenes' => Almacen::with('frentes:ID_FRENTE')->orderBy('TIPO')->orderBy('NOMBRE')
                ->get(['ID_ALMACEN', 'NOMBRE', 'TIPO'])
                ->map(fn (Almacen $a) => [
                    'id'      => (int) $a->ID_ALMACEN,
                    'nombre'  => $a->NOMBRE,
                    'tipo'    => $a->TIPO,
                    'visible' => isset($ver[$a->ID_ALMACEN]),
                    'separa'  => $a->separaPorProyecto(),
                    'frentes' => $a->frentes->pluck('ID_FRENTE')->map('intval')->values()->all(),
                ])->all(),
            'frentes' => $this->catalogoFrentes(),
        ];
    }

    private function catalogoFrentes(): array
    {
        return FrenteTrabajo::withTrashed()->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'ESTATUS_FRENTE', 'deleted_at'])
            ->map(fn (FrenteTrabajo $f) => [
                'id'     => (int) $f->ID_FRENTE,
                'nombre' => $f->NOMBRE_FRENTE,
                'activo' => $f->ESTATUS_FRENTE === 'ACTIVO' && $f->deleted_at === null,
            ])->all();
    }
}
