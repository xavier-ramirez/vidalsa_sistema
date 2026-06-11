<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\AlmacenStock;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use InvalidArgumentException;

/**
 * Lógica central del inventario de almacenes.
 *
 * Reglas duras:
 *  - `almacen_stock.CANTIDAD` SOLO se modifica desde aquí.
 *  - Cada cambio de stock genera una fila en `movimientos_inventario` (kardex).
 *  - Toda operación corre dentro de una transacción con `lockForUpdate()` sobre
 *    la fila de stock para evitar carreras (dos salidas simultáneas, etc.).
 *
 * `$opts` admitidas en los métodos públicos (todas opcionales):
 *   - fecha            : string|Carbon (default: hoy)
 *   - id_frente        : int|null   — frente destino/consumo de una salida
 *   - id_usuario       : int|null   — quién registra (default: auth()->id())
 *   - referencia       : string|null— nº guía/factura/orden
 *   - motivo           : string|null
 *   - notas            : string|null
 *   - permitir_negativo: bool       — solo para AJUSTE/SALIDA forzados (default false)
 */
class InventarioService
{
    /** Magnitud mínima representable (3 decimales). */
    private const EPS = 0.0005;

    // ─────────────────────────────────────────────────────────────
    //  API pública
    // ─────────────────────────────────────────────────────────────

    /** Registra un ingreso de mercancía. */
    public function registrarEntrada(int $idAlmacen, int $idProducto, float $cantidad, array $opts = []): MovimientoInventario
    {
        $this->assertCantidadPositiva($cantidad);

        return DB::transaction(function () use ($idAlmacen, $idProducto, $cantidad, $opts) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, MovimientoInventario::TIPO_ENTRADA, $cantidad, $opts);
        });
    }

    /**
     * Registra una salida de mercancía. Falla si no hay stock suficiente
     * (a menos que $opts['permitir_negativo'] === true).
     */
    public function registrarSalida(int $idAlmacen, int $idProducto, float $cantidad, array $opts = []): MovimientoInventario
    {
        $this->assertCantidadPositiva($cantidad);

        return DB::transaction(function () use ($idAlmacen, $idProducto, $cantidad, $opts) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, MovimientoInventario::TIPO_SALIDA, $cantidad, $opts);
        });
    }

    /**
     * Ajuste de inventario: deja el saldo del producto en $cantidadObjetivo
     * (conteo físico, corrección, etc.). Registra el delta en el kardex.
     */
    public function registrarAjuste(int $idAlmacen, int $idProducto, float $cantidadObjetivo, array $opts = []): MovimientoInventario
    {
        if ($cantidadObjetivo < 0 && empty($opts['permitir_negativo'])) {
            throw new InvalidArgumentException('La cantidad objetivo del ajuste no puede ser negativa.');
        }

        return DB::transaction(function () use ($idAlmacen, $idProducto, $cantidadObjetivo, $opts) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, MovimientoInventario::TIPO_AJUSTE, $cantidadObjetivo, $opts);
        });
    }

    /**
     * Registra SOLO la salida de un traspaso (origen) — usado por TraspasoService
     * cuando un pedido pasa a ENVIADO. La entrada al destino llegará más tarde
     * con `registrarTraspasoEntrada()` cuando el receptor confirme.
     *
     * Difiere de un SALIDA normal en que el movimiento queda con TIPO=TRASPASO_SALIDA,
     * apunta al almacén contraparte y al pedido padre.
     */
    public function registrarTraspasoSalida(
        int $idAlmacen,
        int $idProducto,
        float $cantidad,
        int $idTraspaso,
        int $idAlmacenDestino,
        array $opts = [],
    ): MovimientoInventario {
        $this->assertCantidadPositiva($cantidad);
        if ($idAlmacen === $idAlmacenDestino) {
            throw new InvalidArgumentException('El almacén de origen y destino no pueden ser el mismo.');
        }
        $optsCompletas = $opts + [
            'id_almacen_contraparte' => $idAlmacenDestino,
            'id_traspaso'            => $idTraspaso,
        ];
        return DB::transaction(function () use ($idAlmacen, $idProducto, $cantidad, $optsCompletas) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, MovimientoInventario::TIPO_TRASPASO_SALIDA, $cantidad, $optsCompletas);
        });
    }

    /**
     * Registra SOLO la entrada de un traspaso (destino) — usado por TraspasoService
     * cuando el receptor confirma la recepción. Si se pasa $idMovimientoSalida,
     * el movimiento queda enlazado bidireccionalmente con la salida original.
     */
    public function registrarTraspasoEntrada(
        int $idAlmacen,
        int $idProducto,
        float $cantidad,
        int $idTraspaso,
        int $idAlmacenOrigen,
        ?int $idMovimientoSalida = null,
        array $opts = [],
    ): MovimientoInventario {
        $this->assertCantidadPositiva($cantidad);
        $optsCompletas = $opts + [
            'id_almacen_contraparte'    => $idAlmacenOrigen,
            'id_traspaso'               => $idTraspaso,
            'id_movimiento_relacionado' => $idMovimientoSalida,
        ];
        return DB::transaction(function () use ($idAlmacen, $idProducto, $cantidad, $optsCompletas) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, MovimientoInventario::TIPO_TRASPASO_ENTRADA, $cantidad, $optsCompletas);
        });
    }

    /**
     * Asegura que exista la fila de stock para (almacén, producto). Útil para
     * que un almacén pueda "dar de alta" un producto con saldo 0.
     *
     * Por defecto $cantidadMinima=null no toca el campo (compat con llamadas que
     * solo quieren la fila creada). Si pasas $forzarMinimo=true, se aplica el
     * valor tal cual — incluyendo `null` para BORRAR el mínimo. Lo usa
     * AlmacenController::actualizarMinimo para evitar el patrón viejo
     * "asegurarStock + save() manual".
     */
    public function asegurarStock(int $idAlmacen, int $idProducto, ?float $cantidadMinima = null, bool $forzarMinimo = false): AlmacenStock
    {
        return DB::transaction(function () use ($idAlmacen, $idProducto, $cantidadMinima, $forzarMinimo) {
            $this->cargarAlmacen($idAlmacen);
            $this->cargarProducto($idProducto);

            $stockTable = (new AlmacenStock())->getTable();
            DB::table($stockTable)->insertOrIgnore([
                'ID_ALMACEN'  => $idAlmacen,
                'ID_PRODUCTO' => $idProducto,
                'CANTIDAD'    => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $stock = AlmacenStock::where('ID_ALMACEN', $idAlmacen)
                ->where('ID_PRODUCTO', $idProducto)
                ->firstOrFail();

            if ($forzarMinimo) {
                $stock->CANTIDAD_MINIMA = $cantidadMinima;
                $stock->save();
            } elseif ($cantidadMinima !== null) {
                $stock->CANTIDAD_MINIMA = $cantidadMinima;
                $stock->save();
            }
            return $stock;
        });
    }

    /**
     * Deshace un movimiento del kardex con BORRADO DURO (sin rastro) y deja el stock
     * coherente — operación EXCLUSIVA de super.admin (el gate vive en la ruta).
     *
     * A diferencia de una reversión por contrapartida (que añade una fila inversa y
     * CONSERVA el rastro, como eliminarNota), aquí la fila se ELIMINA físicamente: el
     * movimiento desaparece "como si nunca hubiera ocurrido". Para que el saldo no quede
     * con un salto, se RECALCULA CANTIDAD_ANTERIOR/RESULTANTE de todos los movimientos
     * posteriores del mismo (almacén, producto) y se reescribe almacen_stock.CANTIDAD.
     *
     * Traspasos: un traspaso son DOS filas (salida en origen + entrada en destino)
     * enlazadas por ID_MOVIMIENTO_RELACIONADO. Deshacer una sola dejaría medio traspaso
     * colgando, así que se borran AMBAS patas y se recalcula cada almacén afectado. El
     * pedido de Traspaso (tabla `traspasos`) NO se toca: solo el kardex y el stock.
     *
     * Irreversible: no deja registro en ninguna parte.
     *
     * @return array{eliminados:int, afectados:array<int,array{id_almacen:int,id_producto:int,saldo:float}>}
     */
    public function eliminarMovimientoConReverso(int $idMovimiento): array
    {
        return DB::transaction(function () use ($idMovimiento) {
            $mov = MovimientoInventario::lockForUpdate()->find($idMovimiento);
            if (! $mov) {
                throw new InvalidArgumentException("El movimiento #{$idMovimiento} no existe o ya fue eliminado.");
            }

            // Reunir las filas a borrar: la propia + su contraparte de traspaso (helper
            // compartido con el borrado SIN reverso, para no duplicar la lógica del enlace).
            $ids = $this->idsMovimientoYContraparte($mov);

            $movs = MovimientoInventario::whereIn('ID_MOVIMIENTO', $ids)->get();

            // (almacén, producto) únicos afectados — hay que recalcular el saldo de cada uno.
            $pares = $movs->map(fn ($m) => ['a' => (int) $m->ID_ALMACEN, 'p' => (int) $m->ID_PRODUCTO])
                ->unique(fn ($x) => $x['a'] . '-' . $x['p'])
                ->values();

            // Capturar el SALDO DE APERTURA de cada chain ANTES de borrar: es el
            // CANTIDAD_ANTERIOR del movimiento más antiguo (menor ID). El kardex NO siempre
            // arranca en 0 — puede haber un saldo inicial de migración o movimientos previos
            // ya archivados — así que recalcular desde 0 destrozaría el stock. Esa apertura
            // es el saldo previo al primer movimiento y se preserva tal cual.
            $aperturas = [];
            foreach ($pares as $par) {
                $clave = $par['a'] . '-' . $par['p'];
                $aperturas[$clave] = (float) (
                    MovimientoInventario::where('ID_ALMACEN', $par['a'])
                        ->where('ID_PRODUCTO', $par['p'])
                        ->orderBy('ID_MOVIMIENTO')
                        ->value('CANTIDAD_ANTERIOR') ?? 0
                );
            }

            // Borrado duro de las filas del kardex.
            MovimientoInventario::whereIn('ID_MOVIMIENTO', $ids)->delete();

            // Recalcular el saldo de cada (almacén, producto) afectado desde el kardex restante,
            // partiendo del saldo de apertura capturado arriba.
            $afectados = [];
            foreach ($pares as $par) {
                $saldo = $this->recalcularSaldoProducto($par['a'], $par['p'], $aperturas[$par['a'] . '-' . $par['p']]);
                $afectados[] = ['id_almacen' => $par['a'], 'id_producto' => $par['p'], 'saldo' => $saldo];
            }

            return ['eliminados' => $movs->count(), 'afectados' => $afectados];
        });
    }

    /**
     * Borra un movimiento SOLO del historial (kardex) SIN tocar el stock — el reverso de
     * eliminarMovimientoConReverso() para el caso "depurar el registro pero NO mover el
     * saldo". El stock de almacen_stock queda EXACTAMENTE como está; solo desaparece la
     * fila (o el par de traspaso) del kardex. Se usa cuando el saldo físico ya es correcto
     * y la entrada/salida NO debe revertirse, solo borrarse del historial.
     *
     * A diferencia del reverso: NO se recalcula CANTIDAD_ANTERIOR/RESULTANTE de los
     * movimientos posteriores. Eso deja un "salto" en los saldos corridos del kardex —
     * es el comportamiento PEDIDO a propósito (borrar el rastro sin alterar el stock).
     *
     * Irreversible: no deja registro en ninguna parte.
     *
     * @return array{eliminados:int}
     */
    public function eliminarMovimientoSinReverso(int $idMovimiento): array
    {
        return DB::transaction(function () use ($idMovimiento) {
            $mov = MovimientoInventario::lockForUpdate()->find($idMovimiento);
            if (! $mov) {
                throw new InvalidArgumentException("El movimiento #{$idMovimiento} no existe o ya fue eliminado.");
            }

            // Mismo conjunto de filas que el reverso (la propia + su contraparte de
            // traspaso) para no dejar media pata colgando — pero aquí NO se toca el stock.
            $ids = $this->idsMovimientoYContraparte($mov);

            return ['eliminados' => MovimientoInventario::whereIn('ID_MOVIMIENTO', $ids)->delete()];
        });
    }

    /**
     * IDs de las filas del kardex que forman una unidad atómica con $mov: la propia más su
     * contraparte de traspaso (enlace ID_MOVIMIENTO_RELACIONADO en AMBOS sentidos). La
     * comparten el borrado CON reverso y el borrado SIN reverso.
     */
    private function idsMovimientoYContraparte(MovimientoInventario $mov): \Illuminate\Support\Collection
    {
        $ids = collect([(int) $mov->ID_MOVIMIENTO]);
        if ($mov->ID_MOVIMIENTO_RELACIONADO) {
            $ids->push((int) $mov->ID_MOVIMIENTO_RELACIONADO);
        }
        return $ids->merge(
            MovimientoInventario::where('ID_MOVIMIENTO_RELACIONADO', $mov->ID_MOVIMIENTO)->pluck('ID_MOVIMIENTO')
        )->map(fn ($v) => (int) $v)->unique()->values();
    }

    /**
     * Reconstruye el saldo de un (almacén, producto) recorriendo su kardex en orden de
     * inserción (ID_MOVIMIENTO ASC) y reescribiendo CANTIDAD_ANTERIOR / CANTIDAD_RESULTANTE
     * (y la magnitud, para AJUSTE) de cada fila. Persiste el saldo final en
     * almacen_stock.CANTIDAD y lo devuelve. DEBE llamarse dentro de una transacción.
     *
     * $apertura = saldo previo al primer movimiento (el kardex puede no arrancar en 0). Si
     * ya no quedan movimientos, el stock vuelve a esa apertura.
     *
     * Reglas por tipo (las mismas que aplicarMovimiento, recorridas a posteriori):
     *  - ENTRADA / TRASPASO_ENTRADA : resultante = anterior + CANTIDAD
     *  - SALIDA  / TRASPASO_SALIDA  : resultante = anterior − CANTIDAD
     *  - AJUSTE  : CANTIDAD_RESULTANTE es un saldo OBJETIVO absoluto (conteo físico) y se
     *              conserva tal cual; se recalcula anterior y la magnitud = |resultante − anterior|.
     */
    private function recalcularSaldoProducto(int $idAlmacen, int $idProducto, float $apertura = 0.0): float
    {
        // Bloquear la fila de stock PRIMERO: es el mismo cerrojo que toma aplicarMovimiento,
        // así el recálculo se serializa contra una entrada/salida simultánea del producto.
        $stock = AlmacenStock::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->lockForUpdate()
            ->first();

        $movs = MovimientoInventario::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->orderBy('ID_MOVIMIENTO')
            ->get();

        $saldo = round($apertura, 3);
        foreach ($movs as $m) {
            $anterior = $saldo;
            if ($m->TIPO === MovimientoInventario::TIPO_AJUSTE) {
                $resultante = round((float) $m->CANTIDAD_RESULTANTE, 3); // objetivo absoluto: se respeta
                $magnitud   = round(abs($resultante - $anterior), 3);
            } elseif (in_array($m->TIPO, MovimientoInventario::TIPOS_ENTRADA, true)) {
                $magnitud   = round((float) $m->CANTIDAD, 3);
                $resultante = round($anterior + $magnitud, 3);
            } else { // TIPOS_SALIDA
                $magnitud   = round((float) $m->CANTIDAD, 3);
                $resultante = round($anterior - $magnitud, 3);
            }

            $m->CANTIDAD_ANTERIOR   = $anterior;
            $m->CANTIDAD_RESULTANTE = $resultante;
            $m->CANTIDAD            = $magnitud;
            $m->save();

            $saldo = $resultante;
        }

        // Persistir el acumulador (la fila ya quedó bloqueada arriba). Si ya no quedan
        // movimientos, el saldo cae a 0.
        if ($stock) {
            $stock->CANTIDAD             = $saldo;
            $stock->FECHA_ULT_MOVIMIENTO = now();
            $stock->save();
        }

        return $saldo;
    }

    // ─────────────────────────────────────────────────────────────
    //  Núcleo
    // ─────────────────────────────────────────────────────────────

    /**
     * Aplica un movimiento dentro de una transacción ya abierta:
     *   1. Valida almacén y producto.
     *   2. Bloquea (FOR UPDATE) la fila de stock — la crea con 0 si no existe.
     *   3. Calcula saldo anterior/resultante; valida que no quede negativo
     *      (salvo $opts['permitir_negativo']).
     *   4. Persiste el nuevo saldo + crea la fila del kardex.
     *
     * Para AJUSTE, $cantidad es el SALDO OBJETIVO; en los demás tipos es la
     * MAGNITUD del movimiento (siempre > 0).
     */
    protected function aplicarMovimiento(int $idAlmacen, int $idProducto, string $tipo, float $cantidad, array $opts): MovimientoInventario
    {
        $almacen  = $this->cargarAlmacen($idAlmacen);
        $producto = $this->cargarProducto($idProducto);

        // Garantizar que exista la fila de stock SIN romper en carreras: insertOrIgnore
        // no lanza excepción si otra transacción ya la creó (choca con uq_stock_alm_prod).
        $stockTable = (new AlmacenStock())->getTable();
        DB::table($stockTable)->insertOrIgnore([
            'ID_ALMACEN'  => $idAlmacen,
            'ID_PRODUCTO' => $idProducto,
            'CANTIDAD'    => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Ahora sí: bloquear la fila (FOR UPDATE) para serializar los movimientos.
        $stock = AlmacenStock::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->lockForUpdate()
            ->firstOrFail();

        $anterior = (float) $stock->CANTIDAD;

        // Determinar saldo resultante y la magnitud que se guarda en el kardex.
        if ($tipo === MovimientoInventario::TIPO_AJUSTE) {
            $resultante = round($cantidad, 3);
            $magnitud   = round(abs($resultante - $anterior), 3);
        } elseif (in_array($tipo, MovimientoInventario::TIPOS_ENTRADA, true)) {
            $magnitud   = round($cantidad, 3);
            $resultante = round($anterior + $magnitud, 3);
        } elseif (in_array($tipo, MovimientoInventario::TIPOS_SALIDA, true)) {
            $magnitud   = round($cantidad, 3);
            $resultante = round($anterior - $magnitud, 3);
        } else {
            throw new InvalidArgumentException("Tipo de movimiento no soportado: {$tipo}");
        }

        $permitirNegativo = (bool) ($opts['permitir_negativo'] ?? false);
        if ($resultante < -self::EPS && !$permitirNegativo) {
            throw new RuntimeException(sprintf(
                'Stock insuficiente de "%s" en "%s": saldo %.3f, se intentó dejar en %.3f.',
                $producto->NOMBRE, $almacen->NOMBRE, $anterior, $resultante
            ));
        }
        if ($resultante < 0 && $resultante > -self::EPS) {
            $resultante = 0.0; // absorber ruido de redondeo
        }

        // Movimiento AJUSTE sin cambio neto: no genera kardex (sería ruido).
        if ($tipo === MovimientoInventario::TIPO_AJUSTE && $magnitud < self::EPS) {
            throw new InvalidArgumentException('El ajuste no cambia el saldo actual; no se registró ningún movimiento.');
        }

        // Persistir saldo.
        $stock->CANTIDAD             = $resultante;
        $stock->FECHA_ULT_MOVIMIENTO = now();
        if (in_array($tipo, MovimientoInventario::TIPOS_ENTRADA, true)) {
            $stock->ULTIMA_ENTRADA = $magnitud;
        } elseif (in_array($tipo, MovimientoInventario::TIPOS_SALIDA, true)) {
            $stock->ULTIMA_SALIDA = $magnitud;
        }
        $stock->save();

        // Crear kardex.
        return MovimientoInventario::create([
            'ID_ALMACEN'                => $idAlmacen,
            'ID_PRODUCTO'               => $idProducto,
            'TIPO'                      => $tipo,
            'CANTIDAD'                  => $magnitud,
            'CANTIDAD_ANTERIOR'         => $anterior,
            'CANTIDAD_RESULTANTE'       => $resultante,
            'FECHA'                     => $this->resolverFecha($opts['fecha'] ?? null),
            'ID_ALMACEN_CONTRAPARTE'    => $opts['id_almacen_contraparte'] ?? null,
            'ID_MOVIMIENTO_RELACIONADO' => $opts['id_movimiento_relacionado'] ?? null,
            'ID_TRASPASO'               => $opts['id_traspaso'] ?? null,
            'ID_FRENTE'                 => $opts['id_frente'] ?? null,
            'ID_USUARIO'                => $opts['id_usuario'] ?? optional(auth())->id(),
            'REFERENCIA'                => $opts['referencia'] ?? null,
            // Nota de Entrega (solo se llenan en SALIDA — para los demás tipos quedan NULL).
            'NUMERO_CONTRATO'           => $opts['numero_contrato'] ?? null,
            'NUMERO_RQ'                 => $opts['numero_rq'] ?? null,
            'SOLICITANTE'               => $opts['solicitante'] ?? null,
            'DEPARTAMENTO'              => $opts['departamento'] ?? null,
            'NUMERO_NOTA'               => $opts['numero_nota'] ?? null,
            'MOTIVO'                    => $opts['motivo'] ?? null,
            'NOTAS'                     => $opts['notas'] ?? null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    //  Helpers privados
    // ─────────────────────────────────────────────────────────────

    private function assertCantidadPositiva(float $cantidad): void
    {
        if ($cantidad <= 0) {
            throw new InvalidArgumentException('La cantidad debe ser mayor que cero.');
        }
    }

    private function cargarAlmacen(int $id): Almacen
    {
        $almacen = Almacen::find($id);
        if (!$almacen) {
            throw new InvalidArgumentException("El almacén #{$id} no existe.");
        }
        if ($almacen->ESTATUS !== 'ACTIVO') {
            throw new RuntimeException("El almacén \"{$almacen->NOMBRE}\" está inactivo.");
        }
        return $almacen;
    }

    private function cargarProducto(int $id): ProductoInventario
    {
        $producto = ProductoInventario::find($id);
        if (!$producto) {
            throw new InvalidArgumentException("El producto #{$id} no existe.");
        }
        if ($producto->ESTATUS !== 'ACTIVO') {
            throw new RuntimeException("El producto \"{$producto->NOMBRE}\" está inactivo.");
        }
        return $producto;
    }

    private function resolverFecha($fecha): Carbon
    {
        if ($fecha instanceof Carbon) {
            return $fecha->copy()->startOfDay();
        }
        if (is_string($fecha) && trim($fecha) !== '') {
            return Carbon::parse($fecha)->startOfDay();
        }
        return Carbon::now()->startOfDay();
    }
}
