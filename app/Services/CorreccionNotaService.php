<?php

namespace App\Services;

use App\Models\CorreccionNota;
use App\Models\MovimientoInventario;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Corrección de la cantidad de un producto en una Nota de Entrega ya emitida.
 *
 * No es una devolución. La DEVOLUCION (DevolucionService) es material que salió de verdad y
 * vuelve otro día: la nota no se toca. La CORRECCIÓN es un error al cargarla —salieron 100
 * electrodos y se tecleó 180—: la salida pasa a decir lo que de verdad salió, el kardex se
 * recalcula como si siempre hubiera sido así y la nota se reimprime con 100. Los demás
 * productos de la nota no se tocan.
 *
 * El rastro queda en correcciones_nota (antes, después, quién, cuándo y por qué), y de ahí
 * sale la nota ORIGINAL con la corrección en rojo para compararla con la corregida.
 */
class CorreccionNotaService
{
    private const EPS = InventarioService::EPS;

    public function __construct(
        private InventarioService $inventario,
        private DevolucionService $devoluciones,
    ) {
    }

    /**
     * Por qué no se puede corregir este producto de la nota, o null si se puede.
     * - Un envío a otro almacén (TRASPASO_SALIDA) tiene su recepción en el destino: su
     *   cantidad se corrige desde Recepción, no aquí.
     * - Si el producto va en VARIAS líneas impresas (distinto Nº de parte o proyecto), la
     *   cantidad de "el producto" no dice cuál de ellas cambiar.
     */
    public function motivoNoCorregible(Collection $salidas, Collection $filasProducto): ?string
    {
        if ($salidas->contains(fn ($m) => $m->TIPO !== MovimientoInventario::TIPO_SALIDA)) {
            return 'Esta nota es un envío a otro almacén: su cantidad se corrige desde Recepción, no aquí.';
        }
        $lineas = $filasProducto->map(fn ($m) => $m->ID_FRENTE . '-' . ($m->NUMERO_PARTE ?? ''))->unique();
        if ($lineas->count() > 1) {
            return 'Este producto va en varias líneas de la nota (distinto Nº de parte o proyecto): corrígelo con «Deshacer» y vuelve a registrar la salida.';
        }
        return null;
    }

    /** Correcciones anteriores de ese producto en la nota, para el modal. */
    public function historial(string $numero, int $idProducto): array
    {
        return CorreccionNota::with('usuario:ID_USUARIO,NOMBRE_COMPLETO')
            ->where('NUMERO_NOTA', $numero)
            ->where('ID_PRODUCTO', $idProducto)
            ->orderBy('ID_CORRECCION')
            ->get()
            ->map(fn ($c) => [
                'fecha'   => optional($c->created_at)->format('d/m/Y h:i A'),
                'antes'   => $c->CANTIDAD_ANTES,
                'despues' => $c->CANTIDAD_DESPUES,
                'motivo'  => $c->MOTIVO,
                'usuario' => $c->usuario?->NOMBRE_COMPLETO,
            ])->all();
    }

    /**
     * Deja el producto $idProducto de la nota $numero en $nueva.
     * $datos: 'motivo', 'id_usuario'.
     */
    public function corregir(string $numero, int $idProducto, float $nueva, array $datos): void
    {
        $nueva = round($nueva, 3);
        if ($nueva <= self::EPS) {
            throw new InvalidArgumentException('La cantidad corregida debe ser mayor que cero. Si no salió nada de este producto, usa «Deshacer».');
        }

        // transaccionAlDia: el recálculo del kardex tiene que ver lo último confirmado (ver ahí).
        $this->inventario->transaccionAlDia(function () use ($numero, $idProducto, $nueva, $datos) {
            // La nota entera bloqueada, con el MISMO candado que la devolución y el borrado de
            // la nota: una corrección y una devolución a la vez quedan en fila, y la segunda
            // ve lo que dejó la primera.
            $salidas = $this->devoluciones->salidasDeNota($numero, true);
            if ($salidas->isEmpty()) {
                throw new RuntimeException("La Nota {$numero} no existe o ya fue eliminada.");
            }
            $filas = $salidas->where('ID_PRODUCTO', $idProducto)->sortBy('ID_MOVIMIENTO')->values();
            if ($filas->isEmpty()) {
                throw new InvalidArgumentException("Ese producto no está en la Nota {$numero}.");
            }
            if ($motivo = $this->motivoNoCorregible($salidas, $filas)) {
                throw new RuntimeException($motivo);
            }

            // Lo entregado y lo ya devuelto, con la MISMA cuenta que usa la devolución.
            $linea  = $this->devoluciones->lineas($filas)[0];
            $actual = $linea['entregado'];
            $um     = $linea['um'] ?? '';
            if (abs($nueva - $actual) <= self::EPS) {
                throw new InvalidArgumentException('Es la misma cantidad que ya tiene la nota.');
            }
            if ($nueva < $linea['devuelto'] - self::EPS) {
                throw new RuntimeException(sprintf(
                    'No puede quedar en menos de lo que ya se devolvió de esta nota (%s %s). Si hay que bajarla más, deshaz primero esa devolución.',
                    InventarioService::num($linea['devuelto']), $um
                ));
            }

            $this->inventario->corregirCantidadesSalida($this->repartir($filas, $nueva - $actual));

            CorreccionNota::create([
                'NUMERO_NOTA'      => $numero,
                'ID_PRODUCTO'      => $idProducto,
                'CANTIDAD_ANTES'   => $actual,
                'CANTIDAD_DESPUES' => $nueva,
                'MOTIVO'           => trim((string) ($datos['motivo'] ?? '')) ?: null,
                'ID_USUARIO'       => $datos['id_usuario'] ?? null,
            ]);
        });
    }

    /**
     * Cantidad nueva de cada fila del producto. [ID_MOVIMIENTO => cantidad].
     *
     * SUBIR va entero a la PRIMERA fila: es la bolsa por la que empezó el despacho (la del
     * proyecto, o la que se eligió en «¿De qué proyecto sale?»).
     *
     * BAJAR empieza por la ÚLTIMA, igual que la devolución: la salida en cascada consume
     * primero la bolsa del proyecto y después las prestadas, así que lo que sobra es primero
     * lo que se le tomó a otro. Ninguna fila baja de lo que ya se devolvió de ella.
     */
    private function repartir(Collection $filas, float $delta): array
    {
        $nuevas = $filas->mapWithKeys(fn ($m) => [(int) $m->ID_MOVIMIENTO => (float) $m->CANTIDAD])->all();

        if ($delta > 0) {
            $primera = (int) $filas->first()->ID_MOVIMIENTO;
            $nuevas[$primera] = round($nuevas[$primera] + $delta, 3);
            return $nuevas;
        }

        $porDevolver = MovimientoInventario::porDevolver($filas);
        $resto = round(-$delta, 3);
        foreach ($filas->sortByDesc('ID_MOVIMIENTO') as $m) {
            $id     = (int) $m->ID_MOVIMIENTO;
            $libre  = (float) $porDevolver[$id];          // lo que se puede quitar de esta fila
            $quitar = round(min($resto, $libre), 3);
            $nuevas[$id] = round($nuevas[$id] - $quitar, 3);
            $resto = round($resto - $quitar, 3);
            if ($resto <= self::EPS) {
                break;
            }
        }
        return $nuevas;
    }
}
