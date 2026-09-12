<?php

namespace App\Services;

use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Devolución de material entregado con una Nota de Entrega, con cambio opcional.
 *
 * El caso: salen 5 BRAGA TALLA 45 con la nota NE-2026-0123 y a los días las regresan
 * porque era la 42. Lo correcto NO es corregir la nota —esa entrega pasó y se firmó— ni
 * meter una entrada suelta —el consumo seguiría contando las 45 y nadie sabría de dónde
 * volvieron—, sino dejar escrito lo que pasó, el día que pasó:
 *
 *   1. Una DEVOLUCION de las 45 enlazada a la salida de la nota: el stock de la 45 vuelve,
 *      a la misma bolsa de la que salió, y el consumo de esa salida baja
 *      (MovimientoInventario::scopeConDevuelto).
 *   2. Si se entrega otro producto a cambio, una SALIDA nueva de la 42 con su propia Nota de
 *      Entrega —la que se firma al entregar—, con el N° de la nota original en REFERENCIA y
 *      en Observaciones.
 *
 * Todo en una transacción: si la 42 no tiene stock no queda una devolución a medias.
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
     * $lineas: [['id_producto', 'cantidad', 'id_producto_cambio'?, 'cantidad_cambio'?], …]
     *          —un producto por línea; sin cambio, solo vuelve el material.
     * $datos : 'fecha' (default hoy), 'motivo', 'id_usuario'.
     *
     * @return string|null  N° de la Nota de Entrega de lo entregado a cambio, si hubo cambio.
     */
    public function registrar(string $numero, array $lineas, array $datos): ?string
    {
        return DB::transaction(function () use ($numero, $lineas, $datos) {
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

            $fecha       = $this->fechaDevolucion($datos['fecha'] ?? null, $salidas);
            $porDevolver = MovimientoInventario::porDevolver($salidas);
            $porProducto = $salidas->groupBy('ID_PRODUCTO');
            $cabecera    = $salidas->first();

            $motivoDevolucion = $this->texto($datos['motivo'] ?? null);
            $idUsuario        = $datos['id_usuario'] ?? null;

            // Nota de la entrega a cambio: UNA para todos los productos que se cambian, como
            // cualquier salida de varias líneas. Solo se genera si hay algún cambio.
            $hayCambio    = collect($lineas)->contains(fn ($l) => !empty($l['id_producto_cambio']));
            $numeroCambio = $hayCambio ? MovimientoInventario::generarNumeroNota() : null;

            // Primero se PLANIFICA todo y después se ejecuta ordenado por producto. Cada paso
            // bloquea la fila de stock de su producto; recorrerlos siempre en el mismo orden
            // (el que usa registrarMovimientoLote) evita el deadlock con una salida que esté
            // tocando los mismos productos al revés. En un mismo producto la devolución va
            // antes que la salida: si alguien cambia 42 por 45 y otro 45 por 42 en la misma
            // nota, el stock que vuelve es el que se entrega.
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

                // Cambio por otro producto (opcional).
                $idCambio = !empty($linea['id_producto_cambio']) ? (int) $linea['id_producto_cambio'] : null;
                $cambio   = null;
                if ($idCambio !== null) {
                    if ($idCambio === $idProducto) {
                        throw new InvalidArgumentException("El producto a cambio de «{$nombre}» es el mismo que se devuelve.");
                    }
                    $cantCambio = round((float) ($linea['cantidad_cambio'] ?? 0) ?: $cantidad, 3);
                    if ($cantCambio <= self::EPS) {
                        throw new InvalidArgumentException("La cantidad a entregar a cambio de «{$nombre}» debe ser mayor que cero.");
                    }
                    $cambio = ['id' => $idCambio, 'cantidad' => $cantCambio, 'nombre' => ProductoInventario::whereKey($idCambio)->value('NOMBRE')];
                    $pasos[] = ['producto' => $idCambio, 'orden' => 1, 'cambio' => $cambio, 'de' => $nombre];
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
                        'producto' => $idProducto, 'orden' => 0, 'salida' => $salida, 'cantidad' => $tramo,
                        'notas'    => $cambio ? "A cambio se entregó {$cambio['nombre']} con la Nota {$numeroCambio}." : null,
                    ];
                    $porDevolver[$salida->ID_MOVIMIENTO] = round($libre - $tramo, 3);
                    $resto = round($resto - $tramo, 3);
                    if ($resto <= self::EPS) {
                        break;
                    }
                }
            }

            usort($pasos, fn ($a, $b) => [$a['producto'], $a['orden']] <=> [$b['producto'], $b['orden']]);

            // Observaciones de la nota del cambio: es lo que se imprime en la hoja que se
            // firma, así que dice en palabras de qué nota viene.
            $motivoCambio = Str::limit("Cambio por devolución de la Nota {$numero}" . ($motivoDevolucion ? ": {$motivoDevolucion}" : ''), 200, '');

            foreach ($pasos as $paso) {
                if ($paso['orden'] === 0) {
                    $this->inventario->registrarDevolucion($paso['salida'], $paso['cantidad'], [
                        'fecha'      => $fecha,
                        'id_usuario' => $idUsuario,
                        'motivo'     => $motivoDevolucion,
                        'notas'      => $paso['notas'],
                    ]);
                    continue;
                }

                // Entrega a cambio: una salida normal al mismo proyecto, con los datos de la
                // nota original (contrato, RQ, quién recibe) para que la hoja nueva salga igual.
                $this->inventario->registrarSalida(
                    (int) $cabecera->ID_ALMACEN,
                    $paso['cambio']['id'],
                    $paso['cambio']['cantidad'],
                    [
                        'fecha'           => $fecha,
                        'id_frente'       => $cabecera->ID_FRENTE,
                        'id_usuario'      => $idUsuario,
                        'numero_nota'     => $numeroCambio,
                        'numero_contrato' => $cabecera->NUMERO_CONTRATO,
                        'numero_rq'       => $cabecera->NUMERO_RQ,
                        'solicitante'     => $cabecera->SOLICITANTE,
                        'departamento'    => $cabecera->DEPARTAMENTO,
                        'referencia'      => $numero,
                        'motivo'          => $motivoCambio,
                        'notas'           => "Se entrega a cambio de {$paso['de']}.",
                    ]
                );
            }

            return $numeroCambio;
        });
    }

    /**
     * Fecha de la devolución: la que se indique, o hoy. No puede ser anterior a la nota —no
     * se devuelve lo que todavía no había salido— ni futura.
     */
    private function fechaDevolucion($fecha, Collection $salidas): Carbon
    {
        $dia   = $fecha ? Carbon::parse($fecha)->startOfDay() : Carbon::today();
        $desde = $salidas->min(fn ($s) => $s->FECHA)?->copy()->startOfDay();

        if ($desde && $dia->lt($desde)) {
            throw new InvalidArgumentException('La devolución no puede tener una fecha anterior a la nota (' . $desde->format('d/m/Y') . ').');
        }
        if ($dia->gt(Carbon::today())) {
            throw new InvalidArgumentException('La fecha de la devolución no puede ser futura.');
        }
        return $dia;
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
