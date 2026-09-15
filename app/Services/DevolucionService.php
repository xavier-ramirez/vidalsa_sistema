<?php

namespace App\Services;

use App\Models\MovimientoInventario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Devolución de material entregado con una Nota de Entrega.
 *
 * El caso: salen 5 BRAGA TALLA 45 con la nota NE-2026-0123 y a los días las regresan. Lo
 * correcto NO es corregir la nota —esa entrega pasó y se firmó— ni meter una entrada suelta
 * —el consumo seguiría contando las 45 y nadie sabría de dónde volvieron—, sino dejar escrito
 * lo que pasó, el día que pasó: una DEVOLUCION enlazada a la salida de la nota, que devuelve
 * el stock a la MISMA bolsa de la que salió y baja el consumo de esa salida
 * (MovimientoInventario::scopeConDevuelto).
 *
 * Si además hay que entregar otra cosa (la talla 42), eso es una salida normal con su propia
 * Nota: se registra desde el inventario, no desde aquí (decisión del cliente, 14-09-2026).
 *
 * Todo en una transacción: o vuelve todo lo indicado, o no vuelve nada.
 */
class DevolucionService
{
    private const EPS = InventarioService::EPS;

    public function __construct(private InventarioService $inventario)
    {
    }

    /** Filas de la nota, en orden de registro. $bloquear las toma FOR UPDATE. */
    public function salidasDeNota(string $numero, bool $bloquear = false): Collection
    {
        return MovimientoInventario::with('producto:ID_PRODUCTO,CODIGO,NOMBRE,UM')
            ->where('NUMERO_NOTA', $numero)
            ->orderBy('ID_MOVIMIENTO')
            ->when($bloquear, fn ($q) => $q->lockForUpdate())
            ->get();
    }

    /**
     * Por qué no se puede devolver sobre estas filas, o null si se puede. Un envío a otro
     * almacén (TRASPASO_SALIDA) no se consumió: entró al stock del otro almacén, y lo que
     * sobre se regresa con otro envío o se devuelve sobre la nota con la que ESE almacén
     * lo entregó.
     */
    public function motivoNoDevolvible(Collection $salidas): ?string
    {
        if ($salidas->contains(fn ($m) => $m->TIPO !== MovimientoInventario::TIPO_SALIDA)) {
            return 'Esta nota es un envío a otro almacén: el material entró a su inventario. La devolución se registra en ese almacén, sobre la nota con la que lo entregó.';
        }
        return null;
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
            $producto  = $filas->first()->producto;
            $entregado = round($filas->sum(fn ($s) => (float) $s->CANTIDAD), 3);
            $pendiente = round($filas->sum(fn ($s) => $porDevolver[$s->ID_MOVIMIENTO]), 3);

            return [
                'id_producto' => (int) $filas->first()->ID_PRODUCTO,
                'codigo'      => $producto?->CODIGO,
                'nombre'      => $producto?->NOMBRE ?? '—',
                'um'          => $producto?->UM,
                'entregado'   => $entregado,
                'devuelto'    => round($entregado - $pendiente, 3),
                'pendiente'   => $pendiente,
            ];
        })->values()->all();
    }

    /**
     * Registra la devolución.
     *
     * $lineas: [['id_producto', 'cantidad'], …] — un producto por línea.
     * $datos : 'motivo', 'id_usuario'. La fecha es la de hoy.
     */
    public function registrar(string $numero, array $lineas, array $datos): void
    {
        DB::transaction(function () use ($numero, $lineas, $datos) {
            // La nota entera bloqueada ANTES de leer lo ya devuelto: dos devoluciones a la vez
            // (doble clic, dos pestañas) quedan en fila y la segunda ve lo que registró la
            // primera, así que nunca se devuelve dos veces lo mismo. eliminarNota toma el
            // mismo candado, de modo que tampoco se cruza con el borrado de la nota.
            $salidas = $this->salidasDeNota($numero, true);
            if ($salidas->isEmpty()) {
                throw new RuntimeException("La Nota {$numero} no existe o ya fue eliminada.");
            }
            if ($motivo = $this->motivoNoDevolvible($salidas)) {
                throw new RuntimeException($motivo);
            }

            $fecha       = $this->fechaDevolucion($salidas);
            $porDevolver = MovimientoInventario::porDevolver($salidas);
            $porProducto = $salidas->groupBy('ID_PRODUCTO');
            $cabecera    = $salidas->first();

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

                $pendiente = round($filas->sum(fn ($s) => $porDevolver[$s->ID_MOVIMIENTO]), 3);
                if ($cantidad > $pendiente + self::EPS) {
                    throw new RuntimeException(sprintf(
                        'De «%s» quedan %s %s por devolver en la Nota %s; no se pueden devolver %s.',
                        $nombre, $this->num($pendiente), $producto?->UM ?? '', $numero, $this->num($cantidad)
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
                    $pasos[] = ['producto' => $idProducto, 'salida' => $salida, 'cantidad' => $tramo];
                    $porDevolver[$salida->ID_MOVIMIENTO] = round($libre - $tramo, 3);
                    $resto = round($resto - $tramo, 3);
                    if ($resto <= self::EPS) {
                        break;
                    }
                }
            }

            usort($pasos, fn ($a, $b) => $a['producto'] <=> $b['producto']);

            foreach ($pasos as $paso) {
                $this->inventario->registrarDevolucion($paso['salida'], $paso['cantidad'], [
                    'fecha'      => $fecha,
                    'id_usuario' => $idUsuario,
                    'motivo'     => $motivoDevolucion,
                ]);
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

    /** 5 → "5", 2.5 → "2,5": la misma presentación que el kardex. */
    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',') ?: '0';
    }
}
