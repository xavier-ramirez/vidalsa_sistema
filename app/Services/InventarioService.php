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
                $frentes = AlmacenStock::where('ID_ALMACEN', $par['a'])
                    ->where('ID_PRODUCTO', $par['p'])
                    ->pluck('ID_FRENTE')
                    ->map(fn ($v) => (int) $v)
                    ->all();
                if ($frentes === []) {
                    $frentes = [self::FRENTE_BOLSA_COMUN];
                }

                $total = 0.0;
                foreach ($frentes as $idFrente) {
                    // La apertura solo aplica a la bolsa común: es el saldo previo al primer
                    // movimiento del kardex, anterior a que existiera la separación por
                    // proyecto. Sumarla a cada frente multiplicaría stock que nunca existió.
                    $ap = $idFrente === self::FRENTE_BOLSA_COMUN ? $aperturas[$par['a'] . '-' . $par['p']] : 0.0;
                    $total += $this->recalcularSaldoProducto($par['a'], $par['p'], $ap, $idFrente);
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

        // Solo los movimientos DE ESE MISMO saldo: cada fila se reconstruye con su propio
        // kardex. Los movimientos de un almacén que no separa por proyecto llevan frente
        // NULL o el del destino, y su saldo es siempre el 0 — por eso el criterio mira el
        // frente del SALDO, que es el que aplicarMovimiento usó al descontarlo.
        $movs = MovimientoInventario::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->when($this->almacenSepara($idAlmacen), fn ($q) => $q->where(
                fn ($w) => $idFrente === self::FRENTE_BOLSA_COMUN
                    ? $w->whereNull('ID_FRENTE')->orWhere('ID_FRENTE', self::FRENTE_BOLSA_COMUN)
                    : $w->where('ID_FRENTE', $idFrente)
            ))
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
        if (array_key_exists('_frente_saldo', $opts)) {
            return (int) $opts['_frente_saldo'];
        }

        return (int) ($opts['id_frente'] ?? self::FRENTE_BOLSA_COMUN);
    }

    /**
     * Salida que consume PRIMERO del saldo del proyecto y, si no alcanza, el resto de la
     * bolsa común del almacén (el material que aún no está atribuido a nadie).
     *
     * Cuando hace falta tirar de las dos bolsas se registran DOS movimientos, uno por
     * bolsa: así cada fila del kardex sigue explicando el saldo del que salió y las
     * cantidades anterior/resultante cuadran. Devuelve el movimiento del tramo del
     * proyecto (el principal); si todo salió de la común, ese único movimiento.
     *
     * Si entre las dos no alcanza, el segundo tramo falla con el "Stock insuficiente" de
     * siempre y la transacción del llamador revierte ambos.
     */
    protected function aplicarSalidaConCascada(int $idAlmacen, int $idProducto, string $tipo, float $cantidad, array $opts): MovimientoInventario
    {
        $almacen = $this->cargarAlmacen($idAlmacen);
        $frente  = $this->frenteDelSaldo($almacen, $opts);

        // Sin separación por proyecto, o la salida ya es de la propia bolsa común:
        // no hay nada que repartir.
        if ($frente === self::FRENTE_BOLSA_COMUN) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, $tipo, $cantidad, $opts);
        }

        $saldoProyecto = (float) (AlmacenStock::where('ID_ALMACEN', $idAlmacen)
            ->where('ID_PRODUCTO', $idProducto)
            ->where('ID_FRENTE', $frente)
            ->value('CANTIDAD') ?? 0);

        // Alcanza con lo del proyecto → camino normal, un solo movimiento.
        if ($saldoProyecto >= $cantidad - self::EPS) {
            return $this->aplicarMovimiento($idAlmacen, $idProducto, $tipo, $cantidad, $opts);
        }

        $delProyecto = max(0.0, round($saldoProyecto, 3));
        $delComun    = round($cantidad - $delProyecto, 3);

        $movProyecto = null;
        if ($delProyecto > self::EPS) {
            $movProyecto = $this->aplicarMovimiento($idAlmacen, $idProducto, $tipo, $delProyecto, $opts);
        }

        // El tramo de la bolsa común se registra en el kardex CON FRENTE 0, igual que el
        // saldo del que sale. Es imprescindible que kardex y saldo coincidan: el recálculo
        // que corre al deshacer un movimiento (recalcularSaldoProducto) reconstruye cada
        // saldo sumando los movimientos DE SU MISMO frente, y un movimiento que descuenta
        // de la bolsa común pero se registra a nombre del proyecto descuadraría las dos
        // filas. El proyecto que recibió no se pierde: queda escrito en las notas.
        // OJO: en el KARDEX el frente va NULL, no 0. `movimientos_inventario.ID_FRENTE`
        // tiene FK contra `frentes_trabajo` y no existe ningún frente con id 0, así que
        // guardar el centinela ahí revienta con "foreign key constraint fails". El 0 solo
        // vive en `almacen_stock` (que no lleva FK justamente por eso) y viaja aparte en
        // `_frente_saldo`. El recálculo ya trata NULL y 0 como la misma bolsa común.
        $notaComun = 'Tomado del material sin asignar del almacén.';
        $optsComun = ['_frente_saldo' => self::FRENTE_BOLSA_COMUN, 'id_frente' => null] + $opts;
        $optsComun['notas'] = trim(($opts['notas'] ?? '') . ' ' . $notaComun);

        $movComun = $this->aplicarMovimiento($idAlmacen, $idProducto, $tipo, $delComun, $optsComun);

        return $movProyecto ?? $movComun;
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
