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
    /**
     * Magnitud mínima representable (3 decimales). Pública para que quien compare
     * cantidades del kardex fuera de aquí (DevolucionService, eliminarNota) use la misma.
     */
    public const EPS = 0.0005;

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
            return $this->aplicarSalidaConCascada($idAlmacen, $idProducto, MovimientoInventario::TIPO_SALIDA, $cantidad, $opts);
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
            return $this->aplicarSalidaConCascada($idAlmacen, $idProducto, MovimientoInventario::TIPO_TRASPASO_SALIDA, $cantidad, $optsCompletas);
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
     * Devolución de material: vuelve al almacén parte (o todo) lo que salió con $salida.
     *
     * Deja una fila DEVOLUCION que suma al stock en el MISMO almacén, producto y bolsa de
     * la salida —si la salida tomó material prestado de otro proyecto, vuelve a ese
     * proyecto— y que apunta a la salida por ID_MOVIMIENTO_RELACIONADO. Con ese enlace el
     * consumo resta lo devuelto (MovimientoInventario::scopeConDevuelto) y deshacer la
     * salida se lleva también sus devoluciones (idsMovimientoYContraparte).
     *
     * Que no se devuelva más de lo que salió lo controla quien llama
     * (DevolucionService), que es quien tiene la nota entera bloqueada.
     */
    public function registrarDevolucion(MovimientoInventario $salida, float $cantidad, array $opts = []): MovimientoInventario
    {
        $this->assertCantidadPositiva($cantidad);
        if ($salida->TIPO !== MovimientoInventario::TIPO_SALIDA) {
            throw new InvalidArgumentException('Solo se puede devolver material de una salida.');
        }

        // La bolsa de la que se descontó; las filas anteriores a ID_FRENTE_SALDO que no la
        // tuvieran caen a la del proyecto, que es de donde salían entonces.
        $bolsa = $salida->ID_FRENTE_SALDO ?? $salida->ID_FRENTE ?? self::FRENTE_BOLSA_COMUN;

        $optsDevolucion = [
            'id_frente'                 => $salida->ID_FRENTE,
            '_frente_saldo'             => (int) $bolsa,
            'id_movimiento_relacionado' => (int) $salida->ID_MOVIMIENTO,
            'referencia'                => $salida->NUMERO_NOTA,
        ] + $opts;

        return DB::transaction(function () use ($salida, $cantidad, $optsDevolucion) {
            return $this->aplicarMovimiento(
                (int) $salida->ID_ALMACEN,
                (int) $salida->ID_PRODUCTO,
                MovimientoInventario::TIPO_DEVOLUCION,
                $cantidad,
                $optsDevolucion
            );
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

            // El MÍNIMO de reposición es del producto EN EL ALMACÉN, no de cada proyecto:
            // se guarda siempre en la fila de la bolsa común (frente 0), que hace de fila
            // base del producto. Si se buscara sin filtrar por frente, en un almacén que
            // separa por proyecto `firstOrFail()` devolvería una fila cualquiera de las
            // que haya y el mínimo acabaría en el proyecto que tocara primero.
            $stockTable = (new AlmacenStock())->getTable();
            DB::table($stockTable)->insertOrIgnore([
                'ID_ALMACEN'  => $idAlmacen,
                'ID_PRODUCTO' => $idProducto,
                'ID_FRENTE'   => self::FRENTE_BOLSA_COMUN,
                'CANTIDAD'    => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            $stock = AlmacenStock::where('ID_ALMACEN', $idAlmacen)
                ->where('ID_PRODUCTO', $idProducto)
                ->where('ID_FRENTE', self::FRENTE_BOLSA_COMUN)
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
     * pedido de Traspaso (tabla `traspasos`) NO se toca aquí: lo ajusta quien llama, en la
     * misma transacción (TraspasoService::prepararDeshacer / quitarLineasDeshechas).
     *
     * $idsArrastrados: filas que forman unidad con $mov sin estar enlazadas a él en el kardex
     * (los demás tramos, la entrada y el retorno del mismo ítem de envío, ver
     * TraspasoService::prepararDeshacer).
     *
     * Irreversible: no deja registro en ninguna parte.
     *
     * @param  int[]  $idsArrastrados
     * @return array{eliminados:int, afectados:array<int,array{id_almacen:int,id_producto:int,saldo:float}>}
     */
    public function eliminarMovimientoConReverso(int $idMovimiento, array $idsArrastrados = []): array
    {
        return DB::transaction(function () use ($idMovimiento, $idsArrastrados) {
            $mov = MovimientoInventario::lockForUpdate()->find($idMovimiento);
            if (! $mov) {
                throw new InvalidArgumentException("El movimiento #{$idMovimiento} no existe o ya fue eliminado.");
            }

            // Reunir las filas a borrar: la propia + su contraparte de traspaso (helper
            // compartido con el borrado SIN reverso, para no duplicar la lógica del enlace)
            // + las que se arrastran sin enlace.
            $ids = $this->idsMovimientoYContraparte($mov)
                ->merge($idsArrastrados)
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->values();

            $movs = MovimientoInventario::whereIn('ID_MOVIMIENTO', $ids)->get();

            // (almacén, producto) únicos afectados — hay que recalcular el saldo de cada uno.
            $pares = $movs->map(fn ($m) => ['a' => (int) $m->ID_ALMACEN, 'p' => (int) $m->ID_PRODUCTO])
                ->unique(fn ($x) => $x['a'] . '-' . $x['p'])
                ->values();

            // Capturar el SALDO DE APERTURA de cada BOLSA ANTES de borrar: es el
            // CANTIDAD_ANTERIOR de su movimiento más antiguo (menor ID). El kardex NO siempre
            // arranca en 0 — puede haber un saldo inicial de migración o movimientos previos
            // ya archivados — así que recalcular desde 0 destrozaría el stock. Esa apertura
            // es el saldo previo al primer movimiento de la bolsa y se preserva tal cual.
            // Sirve igual si el movimiento borrado ERA el más antiguo: la apertura describe
            // el saldo de ANTES de él, así que el replay lo deja fuera y da el número justo.
            //
            // Y si una bolsa no tiene NI UN movimiento (saldo cargado por importación, típico
            // en los almacenes que ya venían con inventario), su apertura es su saldo ACTUAL:
            // el kardex no lo explica, pero existe, y recalcularlo desde 0 lo borraría.
            //
            // Las bolsas se listan aquí una sola vez y se reutilizan abajo en el recálculo:
            // dos listados separados podían recalcular una bolsa con la apertura de otra.
            $bolsasPorPar = [];
            $aperturas    = [];
            foreach ($pares as $par) {
                $clave  = $par['a'] . '-' . $par['p'];
                $separa = $this->almacenSepara($par['a']);

                $bolsas = AlmacenStock::where('ID_ALMACEN', $par['a'])
                    ->where('ID_PRODUCTO', $par['p'])
                    ->pluck('ID_FRENTE')
                    ->map(fn ($v) => (int) $v)
                    ->all();
                if ($bolsas === []) {
                    $bolsas = [self::FRENTE_BOLSA_COMUN];
                }
                $bolsasPorPar[$clave] = $bolsas;

                foreach ($bolsas as $bolsa) {
                    // MISMO criterio de pertenencia que usa el recálculo (ver
                    // recalcularSaldoProducto): sin separación por proyecto todo el kardex es
                    // de la única bolsa, y con ella la bolsa la dice ID_FRENTE_SALDO sola.
                    $anterior = MovimientoInventario::where('ID_ALMACEN', $par['a'])
                        ->where('ID_PRODUCTO', $par['p'])
                        ->when(
                            $separa,
                            fn ($q) => $q->where('ID_FRENTE_SALDO', $bolsa)
                        )
                        ->orderBy('ID_MOVIMIENTO')
                        ->value('CANTIDAD_ANTERIOR');

                    $aperturas[$clave][$bolsa] = $anterior !== null
                        ? (float) $anterior
                        : (float) (AlmacenStock::where('ID_ALMACEN', $par['a'])
                            ->where('ID_PRODUCTO', $par['p'])
                            ->where('ID_FRENTE', $bolsa)
                            ->value('CANTIDAD') ?? 0);
                }
            }

            // Borrado duro de las filas del kardex.
            MovimientoInventario::whereIn('ID_MOVIMIENTO', $ids)->delete();

            // Snapshot offline: un borrado DURO no lo detecta la sincronizacion
            // incremental (compara por updated_at / ID maximo, y una fila que se va
            // no deja rastro). Ademas el recalculo de abajo REESCRIBE filas viejas
            // sin cambiar su ID. Se pide a los clientes la copia completa de almacen.
            \App\Support\OfflineVersion::resetear('almacen');

            // Recalcular el saldo de cada (almacén, producto) afectado desde el kardex restante,
            // partiendo del saldo de apertura capturado arriba.
            // Un producto puede tener VARIOS saldos en el mismo almacén (uno por proyecto),
            // y cada uno se reconstruye con los movimientos de su propio frente. Recalcular
            // solo uno dejaría los demás con el valor viejo. Se recorren todas las filas que
            // existan y, si no hay ninguna, al menos la bolsa común para no perder la
            // reposición del saldo de apertura.
            $afectados = [];
            foreach ($pares as $par) {
                $clave = $par['a'] . '-' . $par['p'];
                $total = 0.0;
                foreach ($bolsasPorPar[$clave] as $idFrente) {
                    // Cada bolsa se reconstruye desde SU propia apertura (capturada arriba):
                    // el saldo que ya tenía antes del primer movimiento que la explica.
                    $total += $this->recalcularSaldoProducto(
                        $par['a'],
                        $par['p'],
                        $aperturas[$clave][$idFrente] ?? 0.0,
                        $idFrente
                    );
                }
                $afectados[] = ['id_almacen' => $par['a'], 'id_producto' => $par['p'], 'saldo' => $total];
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

            // Borrado duro: ver la nota de resetear('almacen') mas arriba.
            \App\Support\OfflineVersion::resetear('almacen');

            return ['eliminados' => MovimientoInventario::whereIn('ID_MOVIMIENTO', $ids)->delete()];
        });
    }

    /**
     * IDs de las filas del kardex que forman una unidad atómica con $mov: la propia más su
     * contraparte de traspaso (enlace ID_MOVIMIENTO_RELACIONADO en AMBOS sentidos). La
     * comparten el borrado CON reverso y el borrado SIN reverso, y es pública porque
     * TraspasoService::prepararDeshacer la necesita para saber qué líneas de envío se van:
     * una sola regla de qué borra el deshacer.
     *
     * Devoluciones: el enlace va en UN solo sentido. Borrar una SALIDA se lleva sus
     * devoluciones (sin la salida, devolver material que nunca salió inflaría el stock),
     * pero borrar una DEVOLUCION no toca la salida: esa entrega sí ocurrió.
     */
    public function idsMovimientoYContraparte(MovimientoInventario $mov): \Illuminate\Support\Collection
    {
        $ids = collect([(int) $mov->ID_MOVIMIENTO]);
        if ($mov->ID_MOVIMIENTO_RELACIONADO && $mov->TIPO !== MovimientoInventario::TIPO_DEVOLUCION) {
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
     *  - TIPOS_ENTRADA (ENTRADA / TRASPASO_ENTRADA / DEVOLUCION) : resultante = anterior + CANTIDAD
     *  - SALIDA  / TRASPASO_SALIDA  : resultante = anterior − CANTIDAD
     *  - AJUSTE  : CANTIDAD_RESULTANTE es un saldo OBJETIVO absoluto (conteo físico) y se
     *              conserva tal cual; se recalcula anterior y la magnitud = |resultante − anterior|.
     */
    private function recalcularSaldoProducto(int $idAlmacen, int $idProducto, float $apertura = 0.0, int $idFrente = self::FRENTE_BOLSA_COMUN): float
    {
        // Bloquear la fila de stock PRIMERO: es el mismo cerrojo que toma aplicarMovimiento,
        // así el recálculo se serializa contra una entrada/salida simultánea del producto.
        //
        // El filtro por ID_FRENTE es imprescindible desde que el saldo se lleva por
        // proyecto: sin él, `first()` devolvía una fila cualquiera de las que tuviera el
        // producto y le escribía la suma de TODOS los proyectos, dejando el stock del
        // almacén inflado y las demás filas congeladas en su valor viejo.
        $stock = AlmacenStock::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->where('ID_FRENTE', $idFrente)
            ->lockForUpdate()
            ->first();

        // Solo los movimientos DE ESE MISMO saldo: cada bolsa se reconstruye con su propio
        // kardex, y la bolsa la dice ID_FRENTE_SALDO y nada más. Las filas anteriores a esa
        // columna ya la tienen rellenada (migración backfill_id_frente_saldo_movimientos),
        // así que aquí NO va un COALESCE: envolver la columna en una función anulaba el
        // índice mov_inv_alm_prod_frsaldo_idx y MySQL se iba a un index_merge.
        //
        // El filtro solo aplica si el almacén separa por proyecto: en el resto TODO su
        // kardex pertenece a la única bolsa (la común) aunque las filas lleven el frente
        // del destino, que ahí es solo el dato de a quién se le entregó.
        $movs = MovimientoInventario::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->when(
                $this->almacenSepara($idAlmacen),
                fn ($q) => $q->where('ID_FRENTE_SALDO', $idFrente)
            )
            ->orderBy('ID_MOVIMIENTO')
            ->get();

        $saldo = round($apertura, 3);
        // Magnitud de la ÚLTIMA entrada / salida que sobrevive — se recomputa aquí para que
        // no queden apuntando a un movimiento ya borrado (quedaban stale tras un borrado duro).
        // Solo ENTRADA/SALIDA las tocan (igual que aplicarMovimiento); el AJUSTE no. Si ya no
        // queda ninguna del tipo, la fija en null (limpia el valor viejo).
        $ultEntrada = null;
        $ultSalida  = null;
        foreach ($movs as $m) {
            $anterior = $saldo;
            if ($m->TIPO === MovimientoInventario::TIPO_AJUSTE) {
                $resultante = round((float) $m->CANTIDAD_RESULTANTE, 3); // objetivo absoluto: se respeta
                $magnitud   = round(abs($resultante - $anterior), 3);
            } elseif (in_array($m->TIPO, MovimientoInventario::TIPOS_ENTRADA, true)) {
                $magnitud   = round((float) $m->CANTIDAD, 3);
                $resultante = round($anterior + $magnitud, 3);
                $ultEntrada = $magnitud;
            } else { // TIPOS_SALIDA
                $magnitud   = round((float) $m->CANTIDAD, 3);
                $resultante = round($anterior - $magnitud, 3);
                $ultSalida  = $magnitud;
            }

            $m->CANTIDAD_ANTERIOR   = $anterior;
            $m->CANTIDAD_RESULTANTE = $resultante;
            $m->CANTIDAD            = $magnitud;
            $m->save();

            $saldo = $resultante;
        }

        // Persistir el acumulador (la fila ya quedó bloqueada arriba). Si ya no quedan
        // movimientos, el saldo vuelve a la APERTURA ($apertura), no a 0 (ver docblock arriba).
        if ($stock) {
            $stock->CANTIDAD             = $saldo;
            $stock->FECHA_ULT_MOVIMIENTO = now();
            $stock->ULTIMA_ENTRADA       = $ultEntrada;
            $stock->ULTIMA_SALIDA        = $ultSalida;
            $stock->save();
        }

        return $saldo;
    }

    // ─────────────────────────────────────────────────────────────
    //  Núcleo
    // ─────────────────────────────────────────────────────────────

    /**
     * ¿A qué saldo (proyecto) va este movimiento?
     *
     * Solo los almacenes que sirven a VARIOS proyectos separan el saldo — ver
     * Almacen::separaPorProyecto(). En el resto todo va a la bolsa común (0), que es lo
     * que hace que BARCELONA y cualquier almacén mono-frente se comporten exactamente
     * igual que antes de existir esta columna.
     *
     * En un almacén que sí separa, un movimiento SIN frente (un AJUSTE de conteo, que
     * nunca lo lleva) también cae en la bolsa común: es material del almacén que todavía
     * no está atribuido a ningún proyecto, y desde ahí cualquiera puede consumirlo.
     */
    public const FRENTE_BOLSA_COMUN = 0;

    /**
     * Bolsas PROPIAS de una salida: la de $bolsaPreferente y la común, en el orden en que
     * se consumen. De ahí sale material sin tocar el de nadie más.
     *
     * $bolsaPreferente es la bolsa por la que EMPIEZA el despacho: normalmente la del
     * proyecto destino, o la que el usuario eligió a mano en el desglose por proyecto de esa
     * fila cuando un proyecto le presta material a otro (ver frenteDelSaldo). Quien llama ya
     * resolvió cuál de las dos es — aquí solo se ordena.
     *
     * FUENTE ÚNICA de ese criterio: lo usan la cascada al despachar
     * (aplicarSalidaConCascada, que después sigue con las bolsas ajenas) y la vista previa
     * de la Nota, que compara contra ellas para avisar cuánto se va a tomar prestado. Si
     * los dos no coinciden, el aviso miente sobre lo que va a pasar al registrar.
     */
    public static function bolsasPropias(?int $bolsaPreferente): array
    {
        return array_values(array_unique([
            (int) ($bolsaPreferente ?? self::FRENTE_BOLSA_COMUN),
            self::FRENTE_BOLSA_COMUN,
        ]));
    }

    /**
     * Cómo se NOMBRA la bolsa común en pantalla. Dos redacciones porque se lee en dos
     * sitios distintos —una lista de proyectos y una frase—, pero viven aquí juntas para
     * que no se puedan desincronizar: hasta ahora estaban escritas a mano en la vista del
     * panel, en el kardex, en el export de la bitácora y en el detalle del producto.
     */
    public const ROTULO_BOLSA_COMUN          = 'Sin proyecto (común)';   // columna o lista
    public const ROTULO_BOLSA_COMUN_EN_FRASE = 'material sin asignar';   // "del saldo de …"

    /**
     * ¿Esta fila de saldo es la bolsa común? Frente 0 es el centinela; NULL en el nombre
     * es la misma cosa vista desde un LEFT JOIN que no encontró frente (o un frente
     * borrado). Los dos casos son saldo del almacén sin proyecto asignado.
     *
     * El nombre es OBLIGATORIO, sin valor por defecto: con `= null` una llamada de un solo
     * argumento —esBolsaComun(5)— devolvía true y rotulaba un proyecto real como bolsa
     * común, sin error. Quien solo tenga el id y no el nombre debe pasar explícitamente el
     * que haya (o cadena vacía), no omitirlo.
     */
    public static function esBolsaComun(?int $idFrente, ?string $nombreFrente): bool
    {
        return (int) $idFrente === self::FRENTE_BOLSA_COMUN || $nombreFrente === null;
    }

    /**
     * Nombre a mostrar de la bolsa PRESTADA de un movimiento (la que devuelve
     * MovimientoInventario::bolsaPrestada), resolviendo el id contra el mapa de nombres de
     * MovimientoInventario::nombresDeBolsa. Lo usan el kardex en pantalla y el export.
     *
     * $rotuloComun deja elegir la redacción de la bolsa 0 según dónde se lea: la de lista
     * por defecto, o ROTULO_BOLSA_COMUN_EN_FRASE cuando va dentro de "del saldo de …".
     * El resto —incluido el respaldo por si el frente ya no existe— es igual en los dos.
     */
    public static function rotuloBolsaPrestada(int $bolsa, $nombres, ?string $rotuloComun = null): string
    {
        return $bolsa === self::FRENTE_BOLSA_COMUN
            ? ($rotuloComun ?? self::ROTULO_BOLSA_COMUN)
            : ($nombres[$bolsa] ?? ('proyecto #' . $bolsa));
    }

    /** Nombre a mostrar de una bolsa: el del proyecto, o el rótulo de la común. */
    public static function rotuloBolsa(?int $idFrente, ?string $nombreFrente): string
    {
        return self::esBolsaComun($idFrente, $nombreFrente)
            ? self::ROTULO_BOLSA_COMUN
            : $nombreFrente;
    }

    /** ¿El almacén lleva saldo por proyecto? Cacheado: el recálculo pregunta por cada fila. */
    private array $separaCache = [];

    private function almacenSepara(int $idAlmacen): bool
    {
        return $this->separaCache[$idAlmacen] ??= Almacen::with('frentes:ID_FRENTE')
            ->find($idAlmacen)?->separaPorProyecto() ?? false;
    }

    protected function frenteDelSaldo(Almacen $almacen, array $opts): int
    {
        if (!$almacen->separaPorProyecto()) {
            return self::FRENTE_BOLSA_COMUN;
        }

        // `_frente_saldo` separa DE QUÉ SALDO sale el material de A QUIÉN se le entrega.
        // Lo usa la salida en cascada: cuando el proyecto no tiene suficiente y el resto
        // se toma de la bolsa común, ese tramo descuenta del saldo 0 pero el movimiento
        // sigue registrando en el kardex el proyecto que recibió (id_frente). Sin esta
        // distinción, el material entregado a un proyecto aparecería en la bitácora como
        // si no fuera de nadie.
        //
        // La misma clave llega desde FUERA cuando el usuario elige a mano "Sale del saldo
        // de" en el formulario de salida: es el mismo dato —de qué bolsa se descuenta—, y
        // así una entrega de apoyo (sale del saldo de un proyecto y se entrega a otro)
        // empieza por la bolsa elegida en vez de por la del destino. Desde ahí la cascada
        // sigue igual: común y después el resto, si no alcanza.
        if (array_key_exists('_frente_saldo', $opts)) {
            return (int) $opts['_frente_saldo'];
        }

        return (int) ($opts['id_frente'] ?? self::FRENTE_BOLSA_COMUN);
    }

    /**
     * Salida que consume las bolsas del almacén EN ORDEN hasta completar la cantidad:
     *
     *   1. La bolsa de SALIDA       — la que eligió la línea en el desglose por proyecto de su
     *                                 fila y, si no eligió, la del proyecto destino de la nota
     *                                 (ver frenteDelSaldo).
     *   2. La COMÚN (frente 0)      — material del almacén que aún no es de nadie.
     *   3. El RESTO de los proyectos, de mayor a menor saldo — material que está
     *      físicamente en el almacén pero asignado a otro proyecto.
     *
     * El paso 3 es lo que permite resolver en obra: el material está en la bodega, y
     * prestárselo a otro frente es un ajuste normal de almacén. NO se pierde de vista de
     * quién era: cada tramo es su propia fila del kardex, con la bolsa que se descontó en
     * ID_FRENTE_SALDO y una nota que lo dice en palabras. Devolverlo es una entrada a la
     * bolsa original.
     *
     * Un movimiento por tramo (y no uno solo por la cantidad total) para que las
     * cantidades anterior/resultante de CADA bolsa cuadren y el recálculo
     * (recalcularSaldoProducto) pueda reconstruirlas fila por fila.
     *
     * Devuelve el movimiento del primer tramo. Si entre TODAS las bolsas no alcanza, el
     * último falla con el "Stock insuficiente" de siempre y la transacción del llamador
     * revierte los anteriores.
     */
    protected function aplicarSalidaConCascada(int $idAlmacen, int $idProducto, string $tipo, float $cantidad, array $opts): MovimientoInventario
    {
        $almacen = $this->cargarAlmacen($idAlmacen);
        $frente  = $this->frenteDelSaldo($almacen, $opts);

        // Sin separación por proyecto el almacén es UNA sola bolsa (la común): no hay nada
        // que repartir y el camino es el de siempre, un movimiento y listo.
        if (!$almacen->separaPorProyecto()) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, $tipo, $cantidad, $opts);
        }

        // Saldos por bolsa de ESTE producto. Son pocas filas (una por proyecto del almacén),
        // así que una consulta basta para planificar todos los tramos.
        //
        // SE BLOQUEAN TODAS, y en orden ASCENDENTE de ID_FRENTE, ANTES de tocar ninguna:
        //
        //  1) Sin esto hay DEADLOCK. Cada salida pedía primero la bolsa de SU proyecto, así
        //     que dos salidas simultáneas del mismo producto a proyectos distintos pedían
        //     las mismas filas en orden opuesto (A: 5→11, B: 11→5) y MySQL mataba una con
        //     "1213 Deadlock found" (reproducido). Pidiendo siempre en el mismo orden, la
        //     segunda espera a la primera en vez de morir.
        //  2) Los saldos que se leen aquí ya están bloqueados, así que el reparto en tramos
        //     se calcula sobre números que nadie puede mover por debajo. Antes se leían
        //     sueltos y otra salida podía vaciar una bolsa entre el plan y su ejecución.
        //
        // El filtro de "tiene saldo" va en PHP y NO en el WHERE a propósito: el conjunto de
        // filas bloqueadas tiene que ser el MISMO para todas las salidas de este producto
        // (todas sus bolsas), o el orden canónico deja de serlo. El índice único
        // (ID_ALMACEN, ID_PRODUCTO, ID_FRENTE) hace que el recorrido ya venga en ese orden.
        $saldos = AlmacenStock::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->orderBy('ID_FRENTE')
            ->lockForUpdate()
            ->get(['ID_FRENTE', 'CANTIDAD'])
            ->mapWithKeys(fn ($r) => [(int) $r->ID_FRENTE => (float) $r->CANTIDAD])
            ->filter(fn ($cant) => $cant > 0);

        // Orden de consumo: destino, común y después las demás de MAYOR a menor (vaciar la
        // bolsa más grande parte menos saldos ajenos). unique() quita los repetidos cuando
        // el destino o la común ya venían en la lista.
        $propias = self::bolsasPropias($frente);
        $otras   = $saldos->except($propias)->sortDesc()->keys();
        $orden   = collect($propias)->merge($otras)->unique()->values();

        $pendiente = round($cantidad, 3);
        $primero   = null;

        foreach ($orden as $i => $bolsa) {
            if ($pendiente <= self::EPS) {
                break;
            }
            // La ÚLTIMA bolsa se lleva todo lo que falte aunque no alcance: así el "Stock
            // insuficiente" lo lanza aplicarMovimiento con los números reales de esa bolsa,
            // en vez de duplicar aquí el mensaje y la tolerancia.
            $tramo = $i === $orden->count() - 1
                ? $pendiente
                : round(min($pendiente, max(0.0, $saldos[$bolsa] ?? 0.0)), 3);
            if ($tramo <= self::EPS) {
                continue;
            }

            // ID_FRENTE (a quién se le entrega) es el MISMO en todos los tramos: el proyecto
            // destino. Lo que cambia es la BOLSA de la que se descuenta, que viaja en
            // _frente_saldo y queda escrita en ID_FRENTE_SALDO. Antes, el tramo de la bolsa
            // común tenía que falsear ID_FRENTE a NULL para que el recálculo lo sumara donde
            // iba; con la columna propia ya no hace falta y la Nota de Entrega imprime
            // siempre el proyecto real, salga de la bolsa que salga.
            //
            // La bolsa NO se repite en las NOTAS: el dato vive en la columna y la bitácora
            // la pinta bajo el destino ("del saldo de …"). Un texto derivado además se
            // desactualiza solo — al renombrar un proyecto quedaría diciendo el nombre viejo.
            $optsTramo = ['_frente_saldo' => (int) $bolsa] + $opts;

            $mov = $this->aplicarMovimiento($idAlmacen, $idProducto, $tipo, $tramo, $optsTramo);
            $primero ??= $mov;
            $pendiente = round($pendiente - $tramo, 3);
        }

        // Solo quedaría null con cantidad 0, y eso lo rechaza antes assertCantidadPositiva.
        return $primero ?? $this->aplicarMovimiento($idAlmacen, $idProducto, $tipo, $cantidad, $opts);
    }

    /**
     * Aplica un movimiento dentro de una transacción ya abierta:
     *   1. Valida almacén y producto.
     *   2. Bloquea (FOR UPDATE) la fila de stock DEL PROYECTO — la crea con 0 si no existe.
     *   3. Calcula saldo anterior/resultante; valida que no quede negativo
     *      (salvo $opts['permitir_negativo']).
     *   4. Persiste el nuevo saldo + crea la fila del kardex.
     *
     * Para AJUSTE, $cantidad es el SALDO OBJETIVO; en los demás tipos es la
     * MAGNITUD del movimiento (siempre > 0).
     *
     * El saldo se lleva por (almacén, producto, PROYECTO) — ver frenteDelSaldo(). Este es
     * el único sitio del sistema que escribe `almacen_stock`, así que basta con resolver
     * aquí el proyecto para que entradas, salidas, ajustes y los dos traspasos queden
     * separados sin tocar ninguno de sus métodos.
     */
    protected function aplicarMovimiento(int $idAlmacen, int $idProducto, string $tipo, float $cantidad, array $opts): MovimientoInventario
    {
        $almacen  = $this->cargarAlmacen($idAlmacen);
        $producto = $this->cargarProducto($idProducto);
        $idFrente = $this->frenteDelSaldo($almacen, $opts);

        // Garantizar que exista la fila de stock SIN romper en carreras: insertOrIgnore
        // no lanza excepción si otra transacción ya la creó (choca con el índice único).
        $stockTable = (new AlmacenStock())->getTable();
        DB::table($stockTable)->insertOrIgnore([
            'ID_ALMACEN'  => $idAlmacen,
            'ID_PRODUCTO' => $idProducto,
            'ID_FRENTE'   => $idFrente,
            'CANTIDAD'    => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Ahora sí: bloquear la fila (FOR UPDATE) para serializar los movimientos.
        $stock = AlmacenStock::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->where('ID_FRENTE', $idFrente)
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
            // BOLSA de la que se descontó (0 = común). Es lo que separa "a quién se le
            // entrega" (ID_FRENTE) de "de qué saldo salió": una salida a un proyecto puede
            // tomar material de otro (ver aplicarSalidaConCascada) y las dos cosas tienen
            // que quedar escritas. Se guarda SIEMPRE, también cuando coinciden: así el
            // recálculo lee una sola columna y no tiene que adivinar.
            //
            // Se guarda la BOLSA REAL, siempre — la misma que frenteDelSaldo() acaba de usar
            // para descontar. En un almacén que no separa eso es 0 (la común), aunque
            // ID_FRENTE lleve el frente destino: es la verdad de dónde salió el saldo, y el
            // recálculo depende de que lo sea. Guardar ahí el frente destino "para que no se
            // vea un préstamo falso" era torcer el dato: el día que a ese almacén le agreguen
            // un segundo frente y empiece a separar, el recálculo buscaría esas filas en una
            // bolsa que nunca las tuvo y el saldo quedaría inflado.
            //
            // Que la fila SOLA no baste para decidir si hubo préstamo se resuelve donde toca,
            // al pintarlo: ver MovimientoInventario::prestamosPorMovimiento().
            'ID_FRENTE_SALDO'           => $idFrente,
            'ID_USUARIO'                => $opts['id_usuario'] ?? optional(auth())->id(),
            'REFERENCIA'                => $opts['referencia'] ?? null,
            // Nº de parte específico entregado (filtros): lo elige el usuario en la salida.
            'NUMERO_PARTE'              => $opts['numero_parte'] ?? null,
            // Nota de Entrega (solo se llenan en SALIDA — para los demás tipos quedan NULL).
            'NUMERO_CONTRATO'           => $opts['numero_contrato'] ?? null,
            'NUMERO_RQ'                 => $opts['numero_rq'] ?? null,
            'SOLICITANTE'               => $opts['solicitante'] ?? null,
            'DEPARTAMENTO'              => $opts['departamento'] ?? null,
            'NUMERO_NOTA'               => $opts['numero_nota'] ?? null,
            // Formato de la Nota CONGELADO al momento de la operación. Se toma del almacén
            // que despacha —el mismo que ya está cargado aquí— y solo cuando hay NUMERO_NOTA:
            // sin nota no hay hoja que congelar. Se resuelve en este único sitio (y no en
            // cada caller) para que salida directa y envío a otro almacén no puedan guardar
            // formatos distintos, y para que agregar mañana otro flujo con nota no obligue a
            // acordarse de estampar nada.
            'FORMATO_NOTA'              => ($opts['numero_nota'] ?? null) ? $almacen->formatoNota() : null,
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
