<?php

namespace App\Services;

use App\Models\MovimientoInventario;
use App\Models\Traspaso;
use App\Models\TraspasoLinea;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Devolución de material entregado con una Nota de Entrega.
 *
 * El caso: salen 5 BRAGA TALLA 45 con la nota NE-2026-0123 y a los días las regresan. Lo
 * correcto NO es corregir la nota —esa entrega pasó y se firmó; corregir es para una nota
 * cargada MAL, ver CorreccionNotaService— ni meter una entrada suelta
 * —el consumo seguiría contando las 45 y nadie sabría de dónde volvieron—, sino dejar escrito
 * lo que pasó, el día que pasó: una DEVOLUCION enlazada a la salida de la nota, que devuelve
 * el stock a la MISMA bolsa de la que salió y baja el consumo de esa salida
 * (MovimientoInventario::scopeConDevuelto).
 *
 * Si además hay que entregar otra cosa (la talla 42), eso es una salida normal con su propia
 * Nota: se registra desde el inventario, no desde aquí (decisión del cliente, 14-09-2026).
 *
 * Una nota de ENVÍO a otro almacén (TRASPASO_SALIDA) también se devuelve, se haya recibido
 * o no (el destino puede no usar la app y no recibirlo nunca):
 *  - ya recibido: el material sale del almacén que lo recibió (TRASPASO_SALIDA de retorno,
 *    enlazada a la DEVOLUCION) y vuelve al de origen, a la bolsa de la que salió. Tope: lo
 *    que entró allá y no ha regresado.
 *  - sin recibir: vuelve al origen y la línea del envío (CANTIDAD_ENVIADA, lo que Recepción
 *    espera) baja en lo devuelto, para que el destino no lo reciba después ni una
 *    cancelación lo devuelva dos veces. Deshacerla lo repone (TraspasoService::reponerLineas).
 *
 * Todo en una transacción: o vuelve todo lo indicado, o no vuelve nada.
 */
class DevolucionService
{
    private const EPS = InventarioService::EPS;

    /** Motivo común de la devolución y la corrección cuando el envío repite el producto. */
    public const REPETIDO = 'Este envío lleva este producto en dos líneas: no se puede saber a cuál corresponde el cambio.';

    public function __construct(private InventarioService $inventario)
    {
    }

    /** Filas de la nota, en orden de registro. $bloquear las toma FOR UPDATE. */
    public function salidasDeNota(string $numero, bool $bloquear = false): Collection
    {
        return MovimientoInventario::with('producto:ID_PRODUCTO,CODIGO,NOMBRE,UM,FOTO')
            ->where('NUMERO_NOTA', $numero)
            ->orderBy('ID_MOVIMIENTO')
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    /**
     * Si la nota es un envío, bloquea el envío y sus líneas (las del producto, si se da) en el
     * orden de Recepción (envío → líneas), ANTES que las filas de la nota: así una recepción
     * o cancelación a la vez queda en fila y lo que se lee después ya es lo que dejó. Lo usan
     * la devolución y la corrección, que cambian lo que Recepción espera recibir.
     */
    public function bloquearEnvio(string $numero, ?int $idProducto = null): void
    {
        $idTraspaso = MovimientoInventario::where('NUMERO_NOTA', $numero)
            ->when($idProducto, fn ($q) => $q->where('ID_PRODUCTO', $idProducto))
            ->where('TIPO', MovimientoInventario::TIPO_TRASPASO_SALIDA)->whereNotNull('ID_TRASPASO')
            ->value('ID_TRASPASO');
        if ($idTraspaso) {
            Traspaso::whereKey($idTraspaso)->lockForUpdate()->first();
            TraspasoLinea::where('ID_TRASPASO', $idTraspaso)
                ->when($idProducto, fn ($q) => $q->where('ID_PRODUCTO', $idProducto))
                ->orderBy('ID_LINEA')->lockForUpdate()->get();
        }
    }

    /**
     * Por qué no se puede devolver este producto de la nota, o null si se puede. Solo los
     * envíos a otro almacén tienen motivos propios (cancelado, línea repetida o perdida, o
     * recibido en 0). Sin recibir SÍ se devuelve: ver el docblock de la clase.
     */
    public function motivoNoDevolvible(Collection $filas): ?string
    {
        return $this->motivoEnvio($this->envio($filas));
    }

    /** motivoNoDevolvible con el envío ya leído (null = salida directa). */
    private function motivoEnvio(?array $envio): ?string
    {
        if ($envio === null) {
            return null;
        }
        if ($envio['traspaso']?->esCancelado()) {
            return 'Este envío se canceló: el material ya volvió al almacén.';
        }
        if ($envio['repetido']) {
            return self::REPETIDO;
        }
        if (!$envio['linea']) {
            return 'No se encontró el envío de este producto.';
        }
        if ($envio['linea']->estaConfirmada() && $envio['recibido'] <= self::EPS) {
            return 'El destino no recibió este producto (llegó en 0 o dañado): no hay nada que devolver.';
        }
        return null;
    }

    /**
     * Datos del envío de estas filas (todas del mismo producto), o null si son salidas
     * directas: la línea del pedido, el pedido, el nombre del destino y lo que entró al
     * destino (la TRASPASO_ENTRADA; lo dañado no entra) y lo que ya regresó de allá (los
     * retornos de sus devoluciones; las de antes de recibir no salieron del destino).
     */
    public function envio(Collection $filas): ?array
    {
        $envios = $filas->where('TIPO', MovimientoInventario::TIPO_TRASPASO_SALIDA);
        if ($envios->isEmpty()) {
            return null;
        }
        // La línea apunta al primer tramo; si ese enlace se perdió, se busca por envío + producto.
        $primera = $envios->first();
        $q = fn () => TraspasoLinea::with(['traspaso.almacenDestino:ID_ALMACEN,NOMBRE', 'movimientoEntrada']);
        $linea = $q()->whereIn('ID_MOVIMIENTO_SALIDA', $envios->pluck('ID_MOVIMIENTO'))->first()
            ?? $q()->where('ID_TRASPASO', $primera->ID_TRASPASO)->where('ID_PRODUCTO', $primera->ID_PRODUCTO)->first();
        $traspaso = $linea?->traspaso;
        $devoluciones = MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->whereIn('ID_MOVIMIENTO_RELACIONADO', $envios->pluck('ID_MOVIMIENTO'))->pluck('ID_MOVIMIENTO');
        $devueltoDestino = $devoluciones->isEmpty() ? 0.0 : (float) MovimientoInventario::where('TIPO', MovimientoInventario::TIPO_TRASPASO_SALIDA)
            ->whereIn('ID_MOVIMIENTO_RELACIONADO', $devoluciones)->sum('CANTIDAD');

        return [
            // Dos líneas del mismo producto en el envío: no se sabe qué filas son de cuál
            // (misma regla que el deshacer, TraspasoService::movimientosDelItem).
            'repetido' => $linea && TraspasoLinea::where('ID_TRASPASO', $linea->ID_TRASPASO)
                ->where('ID_PRODUCTO', $linea->ID_PRODUCTO)->count() > 1,
            'linea'    => $linea,
            'traspaso' => $traspaso,
            'destino'  => $traspaso?->almacenDestino?->NOMBRE ?? 'el almacén destino',
            'entrada'  => $linea?->movimientoEntrada,
            'recibido' => round((float) ($linea?->movimientoEntrada?->CANTIDAD ?? 0), 3),
            'devuelto_destino' => round($devueltoDestino, 3),
        ];
    }

    /**
     * Lo entregado, lo ya devuelto y lo que falta por devolver de cada producto de la nota.
     * Un producto puede tener varias filas en la nota (una por bolsa cuando el material se
     * tomó de más de un proyecto); aquí salen sumadas, que es como las ve el almacenista.
     */
    public function lineas(Collection $salidas): array
    {
        $porDevolver = MovimientoInventario::porDevolver($salidas);

        return $salidas->groupBy('ID_PRODUCTO')->map(function (Collection $filas) use ($porDevolver) {
            $producto = $filas->first()->producto;
            [$entregado, $devuelto, $pendiente] = $this->cuentas($filas, $porDevolver, $this->envio($filas));

            return [
                'id_producto' => (int) $filas->first()->ID_PRODUCTO,
                'codigo'      => $producto?->CODIGO,
                'nombre'      => $producto?->NOMBRE ?? '—',
                'foto'        => $producto?->FOTO,
                'um'          => $producto?->UM,
                'entregado'   => $entregado,
                'devuelto'    => $devuelto,
                'pendiente'   => $pendiente,
            ];
        })->values()->all();
    }

    /**
     * [entregado, devuelto, pendiente] de estas filas (un producto). En un envío lo pendiente
     * no pasa de lo que el destino tiene de él: si ya lo recibió, lo que entró allá menos lo
     * que ya regresó de allá; si no, lo que Recepción todavía espera (CANTIDAD_ENVIADA).
     */
    private function cuentas(Collection $filas, array $porDevolver, ?array $envio): array
    {
        $entregado   = round($filas->sum(fn ($s) => (float) $s->CANTIDAD), 3);
        $sinDevolver = round($filas->sum(fn ($s) => $porDevolver[$s->ID_MOVIMIENTO]), 3);
        $devuelto    = round($entregado - $sinDevolver, 3);
        $pendiente   = $sinDevolver;
        if ($envio !== null) {
            $tope = $envio['linea']?->estaConfirmada()
                ? $envio['recibido'] - $envio['devuelto_destino']
                : (float) ($envio['linea']?->CANTIDAD_ENVIADA ?? 0);
            $pendiente = max(0.0, round(min($sinDevolver, $tope), 3));
        }
        return [$entregado, $devuelto, $pendiente];
    }

    /** Devoluciones anteriores de estas salidas, para el modal. */
    public function historial(Collection $salidas): array
    {
        return MovimientoInventario::with('usuario:ID_USUARIO,NOMBRE_COMPLETO')
            ->where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->whereIn('ID_MOVIMIENTO_RELACIONADO', $salidas->pluck('ID_MOVIMIENTO'))
            ->orderBy('ID_MOVIMIENTO')
            ->get()
            ->map(fn ($d) => [
                'fecha'    => optional($d->FECHA)->format('d/m/Y'),
                'cantidad' => round((float) $d->CANTIDAD, 3),
                'motivo'   => $d->MOTIVO,
                'usuario'  => $d->usuario?->NOMBRE_COMPLETO,
            ])->all();
    }

    /**
     * Registra la devolución.
     *
     * $lineas: [['id_producto', 'cantidad'], …] — un producto por línea.
     * $datos : 'motivo', 'id_usuario'. La fecha es la de hoy.
     */
    public function registrar(string $numero, array $lineas, array $datos): void
    {
        // transaccionAlDia: si choca con otra operación simultánea del producto, se repite sola.
        $this->inventario->transaccionAlDia(function () use ($numero, $lineas, $datos) {
            // Si es un envío, primero el envío y sus líneas (como la corrección): la devolución
            // sin recibir baja la línea, y no se cruza con una recepción o cancelación a la vez.
            $this->bloquearEnvio($numero);
            // La nota entera bloqueada ANTES de leer lo ya devuelto: dos devoluciones a la vez
            // (doble clic, dos pestañas) quedan en fila y la segunda ve lo que registró la
            // primera, así que nunca se devuelve dos veces lo mismo. eliminarNota toma el
            // mismo candado, de modo que tampoco se cruza con el borrado de la nota.
            $salidas = $this->salidasDeNota($numero, true);
            if ($salidas->isEmpty()) {
                throw new RuntimeException("La Nota {$numero} no existe o ya fue eliminada.");
            }
            // Todas las bolsas de los productos de la nota, en el orden de cualquier salida
            // (bloquearSaldos): devolver tramo a tramo las tomaba al revés y se trababa con una
            // salida en cascada simultánea del mismo producto (InnoDB 1213).
            $this->inventario->bloquearSaldos((int) $salidas->first()->ID_ALMACEN, $salidas->pluck('ID_PRODUCTO')->all());

            $fecha       = $this->fechaDevolucion($salidas);
            $porDevolver = MovimientoInventario::porDevolver($salidas);
            $porProducto = $salidas->groupBy('ID_PRODUCTO');

            $motivoDevolucion = $this->texto($datos['motivo'] ?? null);
            $idUsuario        = $datos['id_usuario'] ?? null;

            // Primero se PLANIFICA todo y después se ejecuta ordenado por producto. Cada paso
            // bloquea la fila de stock de su producto; recorrerlos siempre en el mismo orden
            // (el que usa registrarMovimientoLote) evita el deadlock con una salida que esté
            // tocando los mismos productos al revés.
            $pasos = [];
            foreach ($lineas as $linea) {
                $idProducto = (int) $linea['id_producto'];
                $cantidad   = round((float) $linea['cantidad'], 3);
                $filas      = $porProducto->get($idProducto);

                if (!$filas) {
                    throw new InvalidArgumentException("Uno de los productos no está en la Nota {$numero}.");
                }
                $producto = $filas->first()->producto;
                $nombre   = $producto?->NOMBRE ?? ('producto #' . $idProducto);
                if ($cantidad <= self::EPS) {
                    throw new InvalidArgumentException("La cantidad a devolver de «{$nombre}» debe ser mayor que cero.");
                }

                $envio = $this->envio($filas);
                if ($motivo = $this->motivoEnvio($envio)) {
                    throw new RuntimeException("«{$nombre}»: {$motivo}");
                }
                [, , $pendiente] = $this->cuentas($filas, $porDevolver, $envio);
                if ($cantidad > $pendiente + self::EPS) {
                    if ($envio && !$envio['linea']->estaConfirmada()) {
                        throw new RuntimeException(sprintf(
                            'De «%s» solo se pueden devolver %s %s: es lo que sigue en camino a %s.',
                            $nombre, InventarioService::num($pendiente), $producto?->UM ?? '', $envio['destino']
                        ));
                    }
                    if ($envio) {
                        throw new RuntimeException(sprintf(
                            'De «%s» solo se pueden devolver %s %s: es lo que recibió %s y no se ha devuelto.',
                            $nombre, InventarioService::num($pendiente), $producto?->UM ?? '', $envio['destino']
                        ));
                    }
                    throw new RuntimeException(sprintf(
                        'No puedes devolver más de lo entregado. De «%s» quedan %s %s por devolver en la Nota %s y se intentó devolver %s.',
                        $nombre, InventarioService::num($pendiente), $producto?->UM ?? '', $numero, InventarioService::num($cantidad)
                    ));
                }

                // Lo devuelto vuelve a las filas de la nota empezando por la ÚLTIMA: la salida
                // en cascada consume primero la bolsa del proyecto y después las prestadas, así
                // que devolver al revés salda primero lo que se le había tomado a otro.
                $resto = $cantidad;
                foreach ($filas->sortByDesc('ID_MOVIMIENTO') as $salida) {
                    $libre = $porDevolver[$salida->ID_MOVIMIENTO];
                    if ($libre <= self::EPS) {
                        continue;
                    }
                    $tramo = round(min($resto, $libre), 3);
                    $pasos[] = [
                        'producto' => $idProducto, 'salida' => $salida, 'cantidad' => $tramo,
                        // Recibido: sale del destino (su entrada). Sin recibir: baja la línea.
                        'entrada'  => $envio['entrada'] ?? null,
                        'linea'    => ($envio && !$envio['linea']->estaConfirmada()) ? $envio['linea'] : null,
                    ];
                    $porDevolver[$salida->ID_MOVIMIENTO] = round($libre - $tramo, 3);
                    $resto = round($resto - $tramo, 3);
                    if ($resto <= self::EPS) {
                        break;
                    }
                }
            }

            usort($pasos, fn ($a, $b) => $a['producto'] <=> $b['producto']);

            // Los saldos del destino de los envíos, también antes de mover nada y en orden.
            foreach (collect($pasos)->pluck('entrada')->filter()->groupBy('ID_ALMACEN')->sortKeys() as $idAlmacen => $entradas) {
                $this->inventario->bloquearSaldos((int) $idAlmacen, $entradas->pluck('ID_PRODUCTO')->all());
            }

            foreach ($pasos as $paso) {
                $devolucion = $this->inventario->registrarDevolucion($paso['salida'], $paso['cantidad'], [
                    'fecha'      => $fecha,
                    'id_usuario' => $idUsuario,
                    'motivo'     => $motivoDevolucion,
                ]);
                if ($paso['linea']) {
                    $paso['linea']->CANTIDAD_ENVIADA = round((float) $paso['linea']->CANTIDAD_ENVIADA - $paso['cantidad'], 3);
                    $paso['linea']->save();
                } elseif ($paso['entrada']) {
                    $this->inventario->registrarRetornoEnvio($paso['entrada'], $devolucion, [
                        'fecha'      => $fecha,
                        'id_usuario' => $idUsuario,
                        'motivo'     => $motivoDevolucion,
                    ]);
                }
            }
        });
    }

    /**
     * Fecha de la devolución: hoy. No puede quedar antes de la nota —no se devuelve lo que
     * todavía no había salido—, cosa que solo pasa si la salida se registró con fecha futura.
     */
    private function fechaDevolucion(Collection $salidas): Carbon
    {
        $hoy   = Carbon::today();
        $desde = $salidas->min(fn ($s) => $s->FECHA)?->copy()->startOfDay();

        if ($desde && $hoy->lt($desde)) {
            throw new InvalidArgumentException('La nota tiene fecha ' . $desde->format('d/m/Y') . ': su devolución se podrá registrar desde ese día.');
        }
        return $hoy;
    }

    private function texto($valor): ?string
    {
        $t = trim((string) $valor);
        return $t === '' ? null : $t;
    }
}
