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
     * Traspaso ATÓMICO de un producto entre dos almacenes (origen y destino en la
     * misma transacción). Crea 2 movimientos enlazados (TRASPASO_SALIDA en origen,
     * TRASPASO_ENTRADA en destino). Se mantiene para correcciones internas y
     * compatibilidad; el flujo PROFESIONAL es {@see TraspasoService} (envío con
     * recepción confirmada), que usa los métodos `registrarTraspasoSalida` /
     * `registrarTraspasoEntrada` de abajo.
     *
     * @return array{salida: MovimientoInventario, entrada: MovimientoInventario}
     */
    public function registrarTraspaso(int $idAlmacenOrigen, int $idAlmacenDestino, int $idProducto, float $cantidad, array $opts = []): array
    {
        $this->assertCantidadPositiva($cantidad);
        if ($idAlmacenOrigen === $idAlmacenDestino) {
            throw new InvalidArgumentException('El almacén de origen y destino no pueden ser el mismo.');
        }

        return DB::transaction(function () use ($idAlmacenOrigen, $idAlmacenDestino, $idProducto, $cantidad, $opts) {
            $optsSalida  = $opts + ['id_almacen_contraparte' => $idAlmacenDestino];
            $optsEntrada = $opts + ['id_almacen_contraparte' => $idAlmacenOrigen];

            $salida  = $this->aplicarMovimiento($idAlmacenOrigen, $idProducto, MovimientoInventario::TIPO_TRASPASO_SALIDA, $cantidad, $optsSalida);
            $entrada = $this->aplicarMovimiento($idAlmacenDestino, $idProducto, MovimientoInventario::TIPO_TRASPASO_ENTRADA, $cantidad, $optsEntrada);

            // Enlazar los dos movimientos espejo.
            $salida->ID_MOVIMIENTO_RELACIONADO  = $entrada->ID_MOVIMIENTO;
            $entrada->ID_MOVIMIENTO_RELACIONADO = $salida->ID_MOVIMIENTO;
            $salida->save();
            $entrada->save();

            return ['salida' => $salida, 'entrada' => $entrada];
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
     */
    public function asegurarStock(int $idAlmacen, int $idProducto, ?float $cantidadMinima = null): AlmacenStock
    {
        return DB::transaction(function () use ($idAlmacen, $idProducto, $cantidadMinima) {
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

            if ($cantidadMinima !== null) {
                $stock->CANTIDAD_MINIMA = $cantidadMinima;
                $stock->save();
            }
            return $stock;
        });
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
