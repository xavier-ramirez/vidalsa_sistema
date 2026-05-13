<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Models\TraspasoLinea;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Lógica del Pedido de Traspaso (envío entre almacenes con recepción confirmada).
 *
 * Reusa {@see InventarioService} para los movimientos físicos — esta clase NUNCA
 * toca `almacen_stock` directamente; siempre delega para que el kardex, locking,
 * y validación de stock se hagan en un único lugar.
 *
 * Reglas duras:
 *  - Crear / agregar líneas: no toca stock (estado BORRADOR).
 *  - Enviar: bloquea el traspaso por estado, registra TRASPASO_SALIDA por cada línea
 *    (resta del origen), pasa a ENVIADO.
 *  - Recibir: por cada línea registra TRASPASO_ENTRADA en el destino con la cantidad
 *    REAL recibida (puede ser != enviada). Enlaza salida↔entrada con
 *    ID_MOVIMIENTO_RELACIONADO. Decide RECIBIDO vs RECIBIDO_PARCIAL según diferencias.
 *  - Cancelar (ENVIADO): registra ENTRADA de retorno al origen por cada línea no
 *    recibida; el stock vuelve y el pedido queda CANCELADO con trazo completo.
 */
class TraspasoService
{
    /** Tolerancia para comparar cantidades (3 decimales). */
    private const EPS = 0.0005;

    public function __construct(private InventarioService $inventario) {}

    // ─────────────────────────────────────────────────────────────
    //  Crear / editar borrador
    // ─────────────────────────────────────────────────────────────

    /**
     * Crea un traspaso en estado BORRADOR. NO toca stock.
     *
     * @param  array  $datos  ['id_almacen_origen', 'id_almacen_destino', 'id_frente_destino'?,
     *                         'referencia'?, 'motivo'?, 'notas'?, 'id_usuario']
     * @param  array  $lineas [['id_producto' => int, 'cantidad' => float], ...]  (opcional)
     */
    public function crearBorrador(array $datos, array $lineas = []): Traspaso
    {
        $this->validarOrigenDestino((int) $datos['id_almacen_origen'], (int) $datos['id_almacen_destino']);

        return DB::transaction(function () use ($datos, $lineas) {
            $traspaso = new Traspaso();
            $traspaso->NUMERO              = Traspaso::generarNumero();
            $traspaso->ID_ALMACEN_ORIGEN   = (int) $datos['id_almacen_origen'];
            $traspaso->ID_ALMACEN_DESTINO  = (int) $datos['id_almacen_destino'];
            $traspaso->ID_FRENTE_DESTINO   = $datos['id_frente_destino'] ?? null;
            $traspaso->ESTADO              = Traspaso::ESTADO_BORRADOR;
            $traspaso->ID_USUARIO_CREO     = (int) $datos['id_usuario'];
            $traspaso->REFERENCIA          = $datos['referencia'] ?? null;
            $traspaso->MOTIVO              = $datos['motivo'] ?? null;
            $traspaso->NOTAS               = $datos['notas'] ?? null;
            $traspaso->save();

            $this->reemplazarLineas($traspaso, $lineas);

            return $traspaso->fresh('lineas');
        });
    }

    /**
     * Sustituye TODAS las líneas del borrador por la nueva lista (modo "guardar
     * formulario"). Solo permitido en BORRADOR — una vez enviado las cantidades
     * son inmutables (lo que se firmó es lo que se firmó).
     */
    public function reemplazarLineas(Traspaso $traspaso, array $lineas): Traspaso
    {
        if (!$traspaso->esBorrador()) {
            throw new RuntimeException("Solo se pueden editar líneas en estado BORRADOR (actual: {$traspaso->ESTADO}).");
        }

        return DB::transaction(function () use ($traspaso, $lineas) {
            $traspaso->lineas()->delete();

            foreach ($lineas as $linea) {
                $idProducto = (int) ($linea['id_producto'] ?? 0);
                $cantidad   = (float) ($linea['cantidad'] ?? 0);
                if ($idProducto <= 0 || $cantidad <= 0) {
                    throw new InvalidArgumentException('Cada línea necesita id_producto y cantidad > 0.');
                }
                // Verificamos que el producto exista y esté activo — falla fuerte si no.
                $producto = ProductoInventario::find($idProducto);
                if (!$producto || $producto->ESTATUS !== 'ACTIVO') {
                    throw new InvalidArgumentException("Producto #{$idProducto} no existe o está inactivo.");
                }

                TraspasoLinea::create([
                    'ID_TRASPASO'      => $traspaso->ID_TRASPASO,
                    'ID_PRODUCTO'      => $idProducto,
                    'CANTIDAD_ENVIADA' => round($cantidad, 3),
                    'ESTADO_LINEA'     => TraspasoLinea::ESTADO_PENDIENTE,
                ]);
            }

            return $traspaso->fresh('lineas');
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  Enviar (BORRADOR → ENVIADO): descontar stock del origen
    // ─────────────────────────────────────────────────────────────

    /**
     * Marca el traspaso como ENVIADO y descuenta el stock del origen.
     * Si CUALQUIER línea falla (stock insuficiente, producto inactivo, etc.),
     * la transacción se aborta y nada queda parcialmente aplicado.
     *
     * @param  array  $opts  ['id_usuario_envio', 'fecha_envio'?, 'permitir_negativo'?]
     */
    public function enviar(Traspaso $traspaso, array $opts = []): Traspaso
    {
        if (!$traspaso->esBorrador()) {
            throw new RuntimeException("Solo un BORRADOR puede pasar a ENVIADO (actual: {$traspaso->ESTADO}).");
        }
        if ($traspaso->lineas()->count() === 0) {
            throw new RuntimeException('No se puede enviar un traspaso sin líneas.');
        }

        return DB::transaction(function () use ($traspaso, $opts) {
            // Bloquear el traspaso para evitar doble envío concurrente.
            $traspasoLock = Traspaso::where('ID_TRASPASO', $traspaso->ID_TRASPASO)->lockForUpdate()->first();
            if (!$traspasoLock || !$traspasoLock->esBorrador()) {
                throw new RuntimeException('El traspaso cambió de estado mientras se intentaba enviar.');
            }

            $opUser = (int) ($opts['id_usuario_envio'] ?? $traspasoLock->ID_USUARIO_CREO);
            $fecha  = $opts['fecha_envio'] ?? null;
            $permitirNegativo = !empty($opts['permitir_negativo']);

            // Por cada línea: TRASPASO_SALIDA en el almacén origen + guardar el ID del movimiento
            // en la línea (para enlazar con la entrada cuando se reciba).
            foreach ($traspasoLock->lineas()->lockForUpdate()->get() as $linea) {
                $salida = $this->inventario->registrarTraspasoSalida(
                    idAlmacen:        (int) $traspasoLock->ID_ALMACEN_ORIGEN,
                    idProducto:       (int) $linea->ID_PRODUCTO,
                    cantidad:         (float) $linea->CANTIDAD_ENVIADA,
                    idTraspaso:       (int) $traspasoLock->ID_TRASPASO,
                    idAlmacenDestino: (int) $traspasoLock->ID_ALMACEN_DESTINO,
                    opts: [
                        'fecha'             => $fecha,
                        'id_frente'         => $traspasoLock->ID_FRENTE_DESTINO,
                        'id_usuario'        => $opUser ?: null,
                        'referencia'        => $traspasoLock->REFERENCIA ?: $traspasoLock->NUMERO,
                        'motivo'            => $traspasoLock->MOTIVO ?: ('Envío ' . $traspasoLock->NUMERO),
                        'permitir_negativo' => $permitirNegativo,
                    ],
                );
                $linea->ID_MOVIMIENTO_SALIDA = $salida->ID_MOVIMIENTO;
                $linea->save();
            }

            $traspasoLock->ESTADO            = Traspaso::ESTADO_ENVIADO;
            $traspasoLock->FECHA_ENVIO       = $fecha ? \Illuminate\Support\Carbon::parse($fecha) : now();
            $traspasoLock->ID_USUARIO_ENVIO  = $opUser ?: null;
            $traspasoLock->save();

            return $traspasoLock->fresh('lineas');
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  Recibir (ENVIADO → RECIBIDO / RECIBIDO_PARCIAL)
    // ─────────────────────────────────────────────────────────────

    /**
     * Confirma la recepción. El destino dice cuánto recibió de cada línea
     * (puede ser 0 si no llegó nada de ese producto) y opcionalmente marca
     * estados especiales (DANADO, etc.).
     *
     * @param  array  $lineasRecibidas  [
     *     [ 'id_linea' => int, 'cantidad_recibida' => float, 'estado'? => string, 'notas'? => string ],
     *     ...
     * ]
     * @param  array  $opts  ['id_usuario_recepcion', 'fecha_recepcion'?]
     */
    public function recibir(Traspaso $traspaso, array $lineasRecibidas, array $opts = []): Traspaso
    {
        if (!$traspaso->esEnviado()) {
            throw new RuntimeException("Solo un traspaso ENVIADO puede recibirse (actual: {$traspaso->ESTADO}).");
        }

        return DB::transaction(function () use ($traspaso, $lineasRecibidas, $opts) {
            $lock = Traspaso::where('ID_TRASPASO', $traspaso->ID_TRASPASO)->lockForUpdate()->first();
            if (!$lock || !$lock->esEnviado()) {
                throw new RuntimeException('El traspaso cambió de estado mientras se intentaba recibir.');
            }

            $opUser = (int) ($opts['id_usuario_recepcion'] ?? 0);
            $fecha  = $opts['fecha_recepcion'] ?? null;

            // Indexar líneas por ID para emparejarlas con lo que llega del request.
            $lineas = $traspaso->lineas()->lockForUpdate()->get()->keyBy('ID_LINEA');

            $huboDiferencia = false;

            foreach ($lineasRecibidas as $rec) {
                $idLinea = (int) ($rec['id_linea'] ?? 0);
                $linea = $lineas->get($idLinea);
                if (!$linea) {
                    throw new InvalidArgumentException("La línea #{$idLinea} no pertenece a este traspaso.");
                }
                $recibida = max(0.0, (float) ($rec['cantidad_recibida'] ?? 0));
                $estado   = $rec['estado'] ?? null; // opcional: DANADO sobrescribe el cálculo automático
                $notas    = $rec['notas'] ?? null;

                // Comparación enviada vs recibida (tolerancia EPS).
                $diff = round($recibida - (float) $linea->CANTIDAD_ENVIADA, 3);
                if ($estado === TraspasoLinea::ESTADO_DANADO) {
                    $estadoFinal = TraspasoLinea::ESTADO_DANADO;
                    $huboDiferencia = true;
                } elseif (abs($diff) < self::EPS) {
                    $estadoFinal = TraspasoLinea::ESTADO_OK;
                } elseif ($diff < 0) {
                    $estadoFinal = TraspasoLinea::ESTADO_FALTANTE;
                    $huboDiferencia = true;
                } else {
                    $estadoFinal = TraspasoLinea::ESTADO_SOBRANTE;
                    $huboDiferencia = true;
                }

                // Aplicamos la entrada al destino — SOLO si la cantidad recibida > 0
                // (si llegó 0, ninguna entrada al stock; el faltante queda registrado en la línea).
                $entrada = null;
                if ($recibida > self::EPS) {
                    $entrada = $this->inventario->registrarTraspasoEntrada(
                        idAlmacen:          (int) $traspaso->ID_ALMACEN_DESTINO,
                        idProducto:         (int) $linea->ID_PRODUCTO,
                        cantidad:           $recibida,
                        idTraspaso:         (int) $traspaso->ID_TRASPASO,
                        idAlmacenOrigen:    (int) $traspaso->ID_ALMACEN_ORIGEN,
                        idMovimientoSalida: $linea->ID_MOVIMIENTO_SALIDA ? (int) $linea->ID_MOVIMIENTO_SALIDA : null,
                        opts: [
                            'fecha'      => $fecha,
                            'id_frente'  => $traspaso->ID_FRENTE_DESTINO,
                            'id_usuario' => $opUser ?: null,
                            'referencia' => $traspaso->REFERENCIA,
                            'motivo'     => $traspaso->MOTIVO ?: ('Recepción ' . $traspaso->NUMERO),
                            'notas'      => $notas,
                        ],
                    );

                    // Enlace bidireccional con la salida si existe.
                    if ($linea->ID_MOVIMIENTO_SALIDA) {
                        MovimientoInventario::where('ID_MOVIMIENTO', $linea->ID_MOVIMIENTO_SALIDA)
                            ->update(['ID_MOVIMIENTO_RELACIONADO' => $entrada->ID_MOVIMIENTO]);
                        $entrada->ID_MOVIMIENTO_RELACIONADO = $linea->ID_MOVIMIENTO_SALIDA;
                        $entrada->save();
                    }
                }

                $linea->CANTIDAD_RECIBIDA    = $recibida;
                $linea->ESTADO_LINEA         = $estadoFinal;
                $linea->NOTAS_LINEA          = $notas;
                $linea->ID_MOVIMIENTO_ENTRADA = $entrada?->ID_MOVIMIENTO;
                $linea->save();
            }

            // Líneas que NO se reportaron explícitamente: quedan en PENDIENTE y se tratan como faltantes.
            $idsReportados = collect($lineasRecibidas)->pluck('id_linea')->filter()->map('intval')->all();
            foreach ($lineas as $linea) {
                if (!in_array((int) $linea->ID_LINEA, $idsReportados, true) && $linea->CANTIDAD_RECIBIDA === null) {
                    $linea->CANTIDAD_RECIBIDA = 0;
                    $linea->ESTADO_LINEA      = TraspasoLinea::ESTADO_FALTANTE;
                    $linea->save();
                    $huboDiferencia = true;
                }
            }

            $traspaso->ESTADO              = $huboDiferencia ? Traspaso::ESTADO_RECIBIDO_PARCIAL : Traspaso::ESTADO_RECIBIDO;
            $traspaso->FECHA_RECEPCION     = $fecha ? \Illuminate\Support\Carbon::parse($fecha) : now();
            $traspaso->ID_USUARIO_RECEPCION = $opUser ?: null;
            $traspaso->save();

            return $traspaso->fresh('lineas');
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  Cancelar
    // ─────────────────────────────────────────────────────────────

    /**
     * Cancela el traspaso.
     *  - Si está BORRADOR: simple cambio de estado.
     *  - Si está ENVIADO:  reversa el stock al origen (ENTRADA por la cantidad que
     *                      no se haya recibido aún) y marca CANCELADO.
     *  - Si está RECIBIDO / RECIBIDO_PARCIAL / CANCELADO: error — no se cancela algo terminal.
     */
    public function cancelar(Traspaso $traspaso, array $opts = []): Traspaso
    {
        if ($traspaso->esFinal()) {
            throw new RuntimeException("No se puede cancelar un traspaso en estado {$traspaso->ESTADO}.");
        }

        return DB::transaction(function () use ($traspaso, $opts) {
            $lock = Traspaso::where('ID_TRASPASO', $traspaso->ID_TRASPASO)->lockForUpdate()->first();
            if (!$lock || $lock->esFinal()) {
                throw new RuntimeException('El traspaso cambió de estado mientras se intentaba cancelar.');
            }

            $opUser = (int) ($opts['id_usuario'] ?? 0);

            if ($lock->esEnviado()) {
                // Reversa: por cada línea no recibida, ENTRADA de retorno al origen.
                foreach ($lock->lineas()->lockForUpdate()->get() as $linea) {
                    $cant = (float) $linea->CANTIDAD_ENVIADA;
                    if ($cant <= self::EPS) continue;

                    $this->inventario->registrarEntrada(
                        (int) $lock->ID_ALMACEN_ORIGEN,
                        (int) $linea->ID_PRODUCTO,
                        $cant,
                        [
                            'id_usuario' => $opUser ?: null,
                            'referencia' => $lock->NUMERO,
                            'motivo'     => 'Retorno por cancelación de ' . $lock->NUMERO,
                            'notas'      => $opts['notas'] ?? null,
                        ],
                    );
                }
            }

            $lock->ESTADO = Traspaso::ESTADO_CANCELADO;
            $lock->save();
            return $lock->fresh('lineas');
        });
    }

    // ─────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────

    private function validarOrigenDestino(int $origen, int $destino): void
    {
        if ($origen === $destino) {
            throw new InvalidArgumentException('El almacén de origen y destino no pueden ser el mismo.');
        }
        $almOrigen  = Almacen::find($origen);
        $almDestino = Almacen::find($destino);
        if (!$almOrigen)  throw new InvalidArgumentException("Almacén origen #{$origen} no existe.");
        if (!$almDestino) throw new InvalidArgumentException("Almacén destino #{$destino} no existe.");
        if ($almOrigen->ESTATUS !== 'ACTIVO' || $almDestino->ESTATUS !== 'ACTIVO') {
            throw new RuntimeException('Ambos almacenes deben estar ACTIVOS para registrar un traspaso.');
        }
    }
}
