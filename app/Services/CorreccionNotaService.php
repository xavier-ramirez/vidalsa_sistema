<?php

namespace App\Services;

use App\Models\AlmacenStock;
use App\Models\CorreccionNota;
use App\Models\MovimientoInventario;
use App\Models\TraspasoLinea;
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
    /** Código de la excepción cuando la nota cambió desde que se abrió el modal (→ HTTP 409). */
    public const CONFLICTO = 409;

    public function __construct(
        private InventarioService $inventario,
        private DevolucionService $devoluciones,
    ) {
    }

    /**
     * Por qué no se puede corregir este producto de la nota, o null si se puede.
     * - Un envío a otro almacén (TRASPASO_SALIDA) se corrige esté en camino o ya recibido
     *   (el destino puede haberlo recibido sin contar). Cancelado no: el material ya volvió.
     * - Si el producto va en VARIAS líneas impresas (distinto Nº de parte, proyecto o destino), la
     *   cantidad de "el producto" no dice cuál de ellas cambiar.
     */
    public function motivoNoCorregible(Collection $filasProducto): ?string
    {
        if ($envio = $this->devoluciones->envio($filasProducto)) {
            if ($envio['traspaso']?->esCancelado()) {
                return 'Este envío se canceló: el material ya volvió al almacén.';
            }
            if (!$envio['linea']) {
                return 'No se encontró el envío de este producto.';
            }
            if ($envio['repetido']) {
                return DevolucionService::REPETIDO;
            }
        }
        // Misma clave de línea que la nota impresa (AlmacenController::renderNotaEntregaPdfBinary):
        // el destino de la nota horizontal también parte el producto en varias líneas.
        $lineas = $filasProducto->map(fn ($m) => $m->ID_FRENTE . '-' . ($m->NUMERO_PARTE ?? '') . '-' . ($m->DESTINO_ITEM ?? ''))->unique();
        if ($lineas->count() > 1) {
            return 'Este producto va en varias líneas de la nota (distinto Nº de parte, proyecto o destino): corrígelo con «Deshacer» y vuelve a registrar la salida.';
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
            // Si es un envío, su pedido va PRIMERO y en el orden de Recepción (envío → líneas):
            // una recepción o cancelación a la vez queda en fila, y lo que se mira abajo
            // (motivoNoCorregible) ya es lo que dejó. Sin esto, una cancelación devolvía al
            // origen lo enviado original y la corrección, además, lo que bajó.
            $this->devoluciones->bloquearEnvio($numero, $idProducto);
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
            if ($motivo = $this->motivoNoCorregible($filas)) {
                throw new RuntimeException($motivo);
            }

            // Lo entregado y lo ya devuelto, con la MISMA cuenta que usa la devolución.
            $linea  = $this->devoluciones->lineas($filas)[0];
            $actual = $linea['entregado'];
            $um     = $linea['um'] ?? '';
            // Lo que la nota decía cuando el usuario abrió el modal: si otro la corrigió
            // mientras, no se pisa esa corrección (código 409: el modal se vuelve a cargar).
            if (isset($datos['entregado_visto']) && abs((float) $datos['entregado_visto'] - $actual) > self::EPS) {
                throw new RuntimeException(sprintf(
                    'Otra persona modificó esta nota mientras la tenías abierta: ahora dice %s %s. Revisa y vuelve a intentarlo.',
                    InventarioService::num($actual), $um
                ), self::CONFLICTO);
            }
            if (abs($nueva - $actual) <= self::EPS) {
                throw new InvalidArgumentException('Es la misma cantidad que ya tiene la nota.');
            }
            if ($nueva < $linea['devuelto'] - self::EPS) {
                throw new RuntimeException(sprintf(
                    'No puede quedar en menos de lo que ya se devolvió de esta nota (%s %s). Si hay que bajarla más, deshaz primero esa devolución.',
                    InventarioService::num($linea['devuelto']), $um
                ));
            }

            // Los saldos del producto, tomados ya (en el orden de todos): el reparto lee lo que
            // tiene cada bolsa y nadie lo cambia hasta terminar.
            $this->inventario->bloquearSaldos((int) $filas->first()->ID_ALMACEN, [$idProducto]);
            $nuevas = $this->repartir($filas, $nueva - $actual);
            if ($envio = $this->devoluciones->envio($filas)) {
                $nuevas += $this->ajustarEnvio($envio, $nueva, $linea['devuelto']);
            }
            $this->inventario->corregirCantidadesSalida($nuevas);

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
     * Pone la línea del envío (ya bloqueada por bloquearEnvio) de acuerdo con la nota
     * corregida, y devuelve [ID_MOVIMIENTO => cantidad] de la entrada del destino si también
     * cambia.
     *
     *  - CANTIDAD_ENVIADA (lo que Recepción espera) = lo que salió menos lo devuelto antes de
     *    recibir (eso ya no estaba en camino).
     *  - Ya recibido: lo recibido no puede pasar de lo que salió. Si la nota BAJA por debajo de
     *    lo que el destino dio por recibido, eso baja también —y su entrada— con el saldo del
     *    destino comprobado (corregirCantidadesSalida). Si SUBE, lo recibido no se inventa:
     *    queda como se contó y la línea marca el faltante.
     */
    private function ajustarEnvio(array $envio, float $nueva, float $devuelto): array
    {
        $linea = $envio['linea'];
        $devueltoAntes = round($devuelto - $envio['devuelto_destino'], 3);
        $enviada = round($nueva - $devueltoAntes, 3);
        $linea->CANTIDAD_ENVIADA = $enviada;

        $cambios = [];
        if ($linea->estaConfirmada()) {
            $recibida = (float) $linea->CANTIDAD_RECIBIDA;
            // $enviada nunca queda por debajo de lo que el destino ya devolvió: corregir() exige
            // nueva >= lo devuelto, y enviada = nueva − lo devuelto antes de recibir.
            if ($recibida > $enviada + self::EPS) {
                $linea->CANTIDAD_RECIBIDA = $enviada;
                if ($envio['entrada'] && (float) $envio['entrada']->CANTIDAD > $enviada + self::EPS) {
                    if ($enviada <= self::EPS) {
                        throw new RuntimeException('Así no quedaría nada recibido en ' . $envio['destino'] . ': usa «Deshacer».');
                    }
                    $cambios[(int) $envio['entrada']->ID_MOVIMIENTO] = $enviada;
                }
            }
            if ($linea->ESTADO_LINEA !== TraspasoLinea::ESTADO_DANADO) {
                $diff = round((float) $linea->CANTIDAD_RECIBIDA - $enviada, 3);
                $linea->ESTADO_LINEA = abs($diff) < self::EPS ? TraspasoLinea::ESTADO_OK
                    : ($diff < 0 ? TraspasoLinea::ESTADO_FALTANTE : TraspasoLinea::ESTADO_SOBRANTE);
            }
        }
        $linea->save();
        return $cambios;
    }

    /**
     * Cantidad nueva de cada fila del producto. [ID_MOVIMIENTO => cantidad].
     *
     * SUBIR empieza por la PRIMERA fila —la bolsa por la que empezó el despacho (la del
     * proyecto, o la que se eligió en «¿De qué proyecto sale?»)— y sigue por las demás filas de
     * la nota hasta lo que tiene cada bolsa, como la salida en cascada que las creó. Antes iba
     * entero a la primera: si esa bolsa estaba en 0 se rechazaba aunque la bolsa prestada
     * tuviera de sobra. Lo que ninguna cubre queda en la primera, y el recálculo dice cuánto falta.
     *
     * BAJAR empieza por la ÚLTIMA, igual que la devolución: la salida en cascada consume
     * primero la bolsa del proyecto y después las prestadas, así que lo que sobra es primero
     * lo que se le tomó a otro. Ninguna fila baja de lo que ya se devolvió de ella.
     */
    private function repartir(Collection $filas, float $delta): array
    {
        $nuevas = $filas->mapWithKeys(fn ($m) => [(int) $m->ID_MOVIMIENTO => (float) $m->CANTIDAD])->all();

        if ($delta > 0) {
            $resto = round($delta, 3);
            $libre = [];   // [bolsa => lo que le queda], para no contar dos veces la misma
            foreach ($filas as $m) {
                $bolsa = $this->inventario->bolsaDeFila($m)[1];
                $libre[$bolsa] ??= max(0.0, (float) AlmacenStock::where('ID_ALMACEN', $m->ID_ALMACEN)
                    ->where('ID_PRODUCTO', $m->ID_PRODUCTO)->where('ID_FRENTE', $bolsa)->value('CANTIDAD'));
                $toma = round(min($resto, $libre[$bolsa]), 3);
                $id   = (int) $m->ID_MOVIMIENTO;
                $nuevas[$id]   = round($nuevas[$id] + $toma, 3);
                $libre[$bolsa] = round($libre[$bolsa] - $toma, 3);
                $resto         = round($resto - $toma, 3);
                if ($resto <= self::EPS) {
                    return $nuevas;
                }
            }
            $primera = (int) $filas->first()->ID_MOVIMIENTO;
            $nuevas[$primera] = round($nuevas[$primera] + $resto, 3);
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
