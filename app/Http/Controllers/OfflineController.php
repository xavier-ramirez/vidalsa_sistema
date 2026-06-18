<?php

namespace App\Http\Controllers;

use App\Casts\MojibakeFix;
use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use App\Models\Movilizacion;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use Illuminate\Http\Request;

/**
 * Snapshot OFFLINE — copia de solo lectura de los datos que el teléfono necesita
 * para CONSULTAR los módulos sin internet (Fase 1 del modo offline).
 *
 * NO toca nada del flujo online: solo hace SELECT (jamás INSERT/UPDATE/DELETE), y
 * el alcance se acota a los almacenes VISIBLES del usuario (Almacen::visiblesPara),
 * igual que el resto del módulo de almacén — así no se filtra data de almacenes
 * ajenos al dispositivo. El texto se pasa por MojibakeFix::fix (la MISMA lógica
 * central del proyecto) para que los acentos lleguen correctos al snapshot.
 *
 * Dos endpoints:
 *   - version():  barato. Devuelve una huella; el cliente lo consulta seguido para
 *                 saber SI hay datos nuevos sin bajar los ~MB del snapshot completo.
 *   - snapshot(): el JSON completo. El cliente solo lo baja cuando la version cambió.
 */
class OfflineController extends Controller
{
    /** Tope de movimientos históricos que viajan al teléfono (los más recientes). */
    private const MAX_MOVIMIENTOS    = 1500;
    /** Tope de movilizaciones históricas que viajan al teléfono (las más recientes). */
    private const MAX_MOVILIZACIONES = 1000;

    /**
     * Huella de versión: cambia cuando cambia cualquier dato relevante. Incluye el id
     * del usuario para que, al cambiar de sesión, el teléfono vuelva a bajar su propio
     * alcance. Barato: solo MAX() indexados, sin traer filas.
     *
     * Es GLOBAL a propósito (cubre equipos Y almacén sin mirar permisos): a lo sumo un
     * usuario re-baja su snapshot tras un cambio de un módulo que él no cachea (el
     * snapshot ya viene gateado y vacío para ese módulo). Preferimos eso a duplicar aquí
     * la lógica de accesos de snapshot() en un endpoint que el teléfono consulta seguido.
     */
    private function calcularVersion(Request $request): string
    {
        $parts = [
            $request->user()?->getAuthIdentifier(),
            MovimientoInventario::max('ID_MOVIMIENTO'),
            AlmacenStock::max('FECHA_ULT_MOVIMIENTO'),
            ProductoInventario::max('updated_at'),
            Equipo::max('updated_at'),
            Movilizacion::max('ID_MOVILIZACION'),
        ];

        return substr(md5(implode('|', array_map(static fn ($v) => (string) $v, $parts))), 0, 12);
    }

    public function version(Request $request)
    {
        return response()->json(['version' => $this->calcularVersion($request)]);
    }

    public function snapshot(Request $request)
    {
        $user    = $request->user();
        $version = $this->calcularVersion($request);

        // ── Alcance por permisos — snapshot ADITIVO: cada módulo viaja al teléfono solo
        //    si el usuario realmente lo usa, para no inflar el cache con datos ajenos.
        //   · Equipos (flota): requiere alguna clave equipos.* (super.admin la hereda).
        //   · Almacén: requiere alguna clave almacen.* o super.admin. NO basta con que el
        //     almacén sea "visible": un usuario GLOBAL (NIVEL_ACCESO=1) ve todos los
        //     almacenes, así que la visibilidad solo ACOTA cuáles viajan, no DA acceso al
        //     módulo offline (si no, todo GLOBAL bajaría almacén aunque no lo use).
        $puedeEquipos = $user->can('equipos.create')
            || $user->can('equipos.edit')
            || $user->can('equipos.assign');
        $puedeAlmacen = $user->can('almacen.productos')
            || $user->can('almacen.movimiento')
            || $user->can('almacen.nota.eliminar')
            || $user->can('super.admin');

        // Almacenes visibles (acotan TODO el inventario). Sin acceso a almacén ni se
        // consultan: la lista queda vacía y, como stock/movimientos se acotan con $almIds,
        // quedan vacíos solos (productos se gatea aparte porque su query no va por almacén).
        $almacenes = $puedeAlmacen
            ? Almacen::visiblesPara($user)->orderBy('NOMBRE')->get(['ID_ALMACEN', 'NOMBRE', 'TIPO'])
            : collect();
        $almIds = $almacenes->pluck('ID_ALMACEN');

        // ── STOCK (autocontenido: trae nombre/código/UM del producto para mostrar sin joins en el front) ──
        $stock = AlmacenStock::query()
            ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 'almacen_stock.ID_PRODUCTO')
            ->whereIn('almacen_stock.ID_ALMACEN', $almIds)
            ->get([
                'almacen_stock.ID_ALMACEN', 'almacen_stock.ID_PRODUCTO',
                'almacen_stock.CANTIDAD', 'almacen_stock.CANTIDAD_MINIMA',
                'p.CODIGO', 'p.NOMBRE', 'p.UM', 'p.CATEGORIA',
            ])
            ->map(static fn ($r) => [
                'id_almacen' => (int) $r->ID_ALMACEN,
                'id_producto' => (int) $r->ID_PRODUCTO,
                'cantidad' => (float) $r->CANTIDAD,
                'minima' => (float) $r->CANTIDAD_MINIMA,
                'codigo' => MojibakeFix::fix($r->CODIGO),
                'nombre' => MojibakeFix::fix($r->NOMBRE),
                'um' => MojibakeFix::fix($r->UM),
                'categoria' => MojibakeFix::fix($r->CATEGORIA),
            ]);

        // ── PRODUCTOS activos (para filtros/autocomplete offline) ──
        $productos = ! $puedeAlmacen ? collect() : ProductoInventario::activos()
            ->orderBy('NOMBRE')
            ->get(['ID_PRODUCTO', 'CODIGO', 'NOMBRE', 'UM', 'CATEGORIA'])
            ->map(static fn ($p) => [
                'id' => (int) $p->ID_PRODUCTO,
                'codigo' => MojibakeFix::fix($p->CODIGO),
                'nombre' => MojibakeFix::fix($p->NOMBRE),
                'um' => MojibakeFix::fix($p->UM),
                'categoria' => MojibakeFix::fix($p->CATEGORIA),
            ]);

        // ── MOVIMIENTOS recientes (bitácora) — los MAX_MOVIMIENTOS más nuevos por registro ──
        $movimientos = MovimientoInventario::query()
            ->leftJoin('productos_inventario as p', 'p.ID_PRODUCTO', '=', 'movimientos_inventario.ID_PRODUCTO')
            ->leftJoin('frentes_trabajo as f', 'f.ID_FRENTE', '=', 'movimientos_inventario.ID_FRENTE')
            ->whereIn('movimientos_inventario.ID_ALMACEN', $almIds)
            ->orderByDesc('movimientos_inventario.ID_MOVIMIENTO')
            ->limit(self::MAX_MOVIMIENTOS)
            ->get([
                'movimientos_inventario.ID_MOVIMIENTO', 'movimientos_inventario.ID_ALMACEN',
                'movimientos_inventario.ID_PRODUCTO', 'movimientos_inventario.TIPO',
                'movimientos_inventario.CANTIDAD', 'movimientos_inventario.CANTIDAD_RESULTANTE',
                'movimientos_inventario.FECHA', 'movimientos_inventario.NUMERO_NOTA',
                'p.NOMBRE as PROD_NOMBRE', 'p.UM as PROD_UM', 'f.NOMBRE_FRENTE',
            ])
            ->map(static fn ($m) => [
                'id' => (int) $m->ID_MOVIMIENTO,
                'id_almacen' => (int) $m->ID_ALMACEN,
                'id_producto' => (int) $m->ID_PRODUCTO,
                'tipo' => $m->TIPO,
                'cantidad' => (float) $m->CANTIDAD,
                'resultante' => (float) $m->CANTIDAD_RESULTANTE,
                'fecha' => optional($m->FECHA)->format('Y-m-d') ?? (string) $m->FECHA,
                'nota' => $m->NUMERO_NOTA,
                'producto' => MojibakeFix::fix($m->PROD_NOMBRE),
                'um' => MojibakeFix::fix($m->PROD_UM),
                'frente' => MojibakeFix::fix($m->NOMBRE_FRENTE),
            ]);

        // ── EQUIPOS (no se acotan por almacén; consulta de flota) ──
        // Trae lo que muestra la tabla de /admin/equipos: tipo, frente actual (+ si está
        // FINALIZADO), seriales, placa (de documentacion) y código de patio. La FOTO no
        // viaja: vive en Drive (/storage/google) y no sirve offline → se muestra ícono.
        // Misma barrera por frente que online (whitelist LOCAL + blacklist de bloqueados):
        // el snapshot offline no debe exponer frentes que el usuario no ve en pantalla.
        $equipos = ! $puedeEquipos ? collect() : Equipo::query()
            ->with([
                'tipo:id,nombre',
                'frenteActual:ID_FRENTE,NOMBRE_FRENTE,ESTATUS_FRENTE',
                'documentacion:ID_EQUIPO,PLACA',
            ])
            ->when($user, fn ($q) => $user->aplicarScopeFrentes($q, 'ID_FRENTE_ACTUAL'))
            ->orderBy('NUMERO_ETIQUETA')
            ->get([
                'ID_EQUIPO', 'NUMERO_ETIQUETA', 'CATEGORIA_FLOTA', 'MARCA', 'MODELO', 'ANIO',
                'ESTADO_OPERATIVO', 'DETALLE_UBICACION_ACTUAL', 'SERIAL_CHASIS', 'SERIAL_DE_MOTOR',
                'CODIGO_PATIO', 'ID_FRENTE_ACTUAL', 'id_tipo_equipo', 'ID_ESPEC',
                'CONFIRMADO_EN_SITIO',
            ])
            ->map(static fn ($e) => [
                'id' => (int) $e->ID_EQUIPO,
                'etiqueta' => MojibakeFix::fix($e->NUMERO_ETIQUETA),
                'tipo' => MojibakeFix::fix($e->tipo?->nombre),
                'categoria' => MojibakeFix::fix($e->CATEGORIA_FLOTA),
                'marca' => MojibakeFix::fix($e->MARCA),
                'modelo' => MojibakeFix::fix($e->MODELO),
                'anio' => $e->ANIO,
                'serial_chasis' => MojibakeFix::fix($e->SERIAL_CHASIS),
                'serial_motor' => MojibakeFix::fix($e->SERIAL_DE_MOTOR),
                'placa' => MojibakeFix::fix($e->documentacion?->PLACA),
                'codigo_patio' => $e->CODIGO_PATIO,
                'estado' => MojibakeFix::fix($e->ESTADO_OPERATIVO),
                'ubicacion' => MojibakeFix::fix($e->DETALLE_UBICACION_ACTUAL),
                'frente' => MojibakeFix::fix($e->frenteActual?->NOMBRE_FRENTE),
                'frente_finalizado' => $e->frenteActual && $e->frenteActual->ESTATUS_FRENTE === 'FINALIZADO',
                'id_frente' => $e->ID_FRENTE_ACTUAL ? (int) $e->ID_FRENTE_ACTUAL : null,
                'id_tipo' => $e->id_tipo_equipo ? (int) $e->id_tipo_equipo : null,
                'confirmado' => (int) ($e->CONFIRMADO_EN_SITIO ?? 0),
            ]);

        // ── MOVILIZACIONES recientes (historial de equipos) ──
        // id_equipo permite filtrar el historial de UN equipo en su detalle offline.
        // Lista negra: no exponer movimientos que TOQUEN un frente bloqueado (origen o
        // destino). Los NULL (recepción inicial) se conservan. Coherente con el resto.
        $bloqueadosOffline = $user ? $user->getFrentesBloqueadosIds() : [];
        $movilizaciones = ! $puedeEquipos ? collect() : Movilizacion::query()
            ->with([
                'equipo:ID_EQUIPO,NUMERO_ETIQUETA,SERIAL_CHASIS,CODIGO_PATIO,id_tipo_equipo',
                'equipo.tipo:id,nombre',
                'equipo.documentacion:ID_EQUIPO,PLACA',
                'auxiliar:ID_AUXILIAR,SERIAL,MARCA,MODELO,TIPO',
                'frenteOrigen:ID_FRENTE,NOMBRE_FRENTE',
                'frenteDestino:ID_FRENTE,NOMBRE_FRENTE',
                'usuario:ID_USUARIO,NOMBRE_COMPLETO,CORREO_ELECTRONICO',
            ])
            ->when(!empty($bloqueadosOffline), fn ($q) => $q
                ->where(fn ($w) => $w->whereNotIn('ID_FRENTE_ORIGEN', $bloqueadosOffline)->orWhereNull('ID_FRENTE_ORIGEN'))
                ->where(fn ($w) => $w->whereNotIn('ID_FRENTE_DESTINO', $bloqueadosOffline)->orWhereNull('ID_FRENTE_DESTINO')))
            ->orderByDesc('ID_MOVILIZACION')
            ->limit(self::MAX_MOVILIZACIONES)
            ->get()
            ->map(static fn ($mv) => [
                'id' => (int) $mv->ID_MOVILIZACION,
                'codigo' => $mv->formatted_codigo_control,
                'tipo' => $mv->TIPO_MOVIMIENTO,
                'fecha' => optional($mv->FECHA_DESPACHO)->format('Y-m-d H:i')
                    ?? optional($mv->created_at)->format('Y-m-d H:i')
                    ?? (string) $mv->FECHA_DESPACHO,
                'id_equipo' => $mv->ID_EQUIPO ? (int) $mv->ID_EQUIPO : null,
                'equipo_tipo' => MojibakeFix::fix($mv->equipo?->tipo?->nombre),
                'equipo_serial' => MojibakeFix::fix($mv->equipo?->SERIAL_CHASIS),
                'equipo_placa' => MojibakeFix::fix($mv->equipo?->documentacion?->PLACA),
                'equipo_codigo' => $mv->equipo?->CODIGO_PATIO,
                'auxiliar' => $mv->auxiliar ? MojibakeFix::fix(trim($mv->auxiliar->MARCA . ' ' . $mv->auxiliar->MODELO)) : null,
                'origen' => MojibakeFix::fix($mv->frenteOrigen?->NOMBRE_FRENTE),
                'destino' => MojibakeFix::fix($mv->frenteDestino?->NOMBRE_FRENTE),
                'usuario' => MojibakeFix::fix($mv->usuario?->NOMBRE_COMPLETO ?? $mv->USUARIO_REGISTRO),
                'ubicacion' => MojibakeFix::fix($mv->DETALLE_UBICACION),
            ]);

        // ── FRENTES activos (para etiquetas/filtros) ── oculta los no visibles
        //    (whitelist LOCAL + blacklist de bloqueados), igual que los dropdowns online.
        $frentes = ! ($puedeEquipos || $puedeAlmacen) ? collect() : FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->when($user, fn ($q) => $user->aplicarScopeFrentes($q, 'ID_FRENTE'))
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE'])
            ->map(static fn ($f) => [
                'id' => (int) $f->ID_FRENTE,
                'nombre' => MojibakeFix::fix($f->NOMBRE_FRENTE),
            ]);

        return response()->json([
            'version'        => $version,
            'generado'       => now()->toIso8601String(),
            'almacenes'      => $almacenes->map(static fn ($a) => [
                'id' => (int) $a->ID_ALMACEN, 'nombre' => MojibakeFix::fix($a->NOMBRE), 'tipo' => $a->TIPO,
            ]),
            'stock'          => $stock,
            'productos'      => $productos,
            'movimientos'    => $movimientos,
            'equipos'        => $equipos,
            'movilizaciones' => $movilizaciones,
            'frentes'        => $frentes,
        ]);
    }
}
