<?php

namespace App\Services;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Models\ProductoInventario;
use App\Models\Traspaso;
use App\Models\TraspasoLinea;
use Illuminate\Support\Carbon;
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
 *    ID_MOVIMIENTO_RELACIONADO. Queda RECIBIDO_PARCIAL si sobraron líneas SIN confirmar
 *    (no por diferencias de cantidad: una nota aceptada entera pasa a RECIBIDO aunque las
 *    cantidades no cuadren). Una nota RECIBIDO_PARCIAL puede volver a recibirse para
 *    completar lo que falta; las líneas ya confirmadas se ignoran (no duplican stock).
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

            // Campos de la Nota de Entrega (VID-FO-GEN-019). Si vienen en $opts, se
            // estampan en CADA TRASPASO_SALIDA — el flujo unificado de "Registrar salida"
            // (botón único en /admin/almacen) los pasa para que el envío genere PDF NE-YYYY-NNNN
            // igual que una SALIDA normal. Si no vienen, los movimientos quedan sin Nota
            // (caso del flujo legacy de /admin/almacen/recepcion).
            $notaOpts = [
                'numero_nota'     => $opts['numero_nota']     ?? null,
                'numero_contrato' => $opts['numero_contrato'] ?? null,
                'numero_rq'       => $opts['numero_rq']       ?? null,
                'solicitante'     => $opts['solicitante']     ?? null,
                'departamento'    => $opts['departamento']    ?? null,
            ];

            // Por cada línea: TRASPASO_SALIDA en el almacén origen + guardar el ID del movimiento
            // en la línea (para enlazar con la entrada cuando se reciba).
            foreach ($traspasoLock->lineas()->lockForUpdate()->get() as $linea) {
                $salida = $this->inventario->registrarTraspasoSalida(
                    idAlmacen:        (int) $traspasoLock->ID_ALMACEN_ORIGEN,
                    idProducto:       (int) $linea->ID_PRODUCTO,
                    cantidad:         (float) $linea->CANTIDAD_ENVIADA,
                    idTraspaso:       (int) $traspasoLock->ID_TRASPASO,
                    idAlmacenDestino: (int) $traspasoLock->ID_ALMACEN_DESTINO,
                    opts: array_merge([
                        'fecha'             => $fecha,
                        'id_frente'         => $traspasoLock->ID_FRENTE_DESTINO,
                        'id_usuario'        => $opUser ?: null,
                        // La REFERENCIA del movimiento NO debe duplicar la Nota de Entrega: si el
                        // envío trae numero_nota (NE-YYYY-NNNN), ese número ya viaja en NUMERO_NOTA.
                        // Antes se copiaba traspaso.REFERENCIA (que en el flujo de salida ES el mismo
                        // NE) → el kardex mostraba el NE dos veces. Solo usamos una referencia propia
                        // si DIFIERE del NE; sin NE (flujo legacy) caemos al NUMERO del traspaso.
                        'referencia'        => ($traspasoLock->REFERENCIA && $traspasoLock->REFERENCIA !== ($notaOpts['numero_nota'] ?? null))
                            ? $traspasoLock->REFERENCIA
                            : (($notaOpts['numero_nota'] ?? null) ? null : $traspasoLock->NUMERO),
                        'motivo'            => $traspasoLock->MOTIVO ?: ('Envío ' . $traspasoLock->NUMERO),
                        'permitir_negativo' => $permitirNegativo,
                    ], $notaOpts),
                );
                $linea->ID_MOVIMIENTO_SALIDA = $salida->ID_MOVIMIENTO;
                $linea->save();
            }

            $traspasoLock->ESTADO            = Traspaso::ESTADO_ENVIADO;
            $traspasoLock->FECHA_ENVIO       = $this->resolverFechaHora($fecha);
            $traspasoLock->ID_USUARIO_ENVIO  = $opUser ?: null;
            $traspasoLock->save();

            return $traspasoLock->fresh('lineas');
        });
    }

    /**
     * FECHA_ENVIO y FECHA_RECEPCION son DATETIME, pero los formularios mandan la fecha SIN
     * hora: usan <input type="date"> y llega "2026-07-10". Carbon::parse la fija a las
     * 00:00:00, así que una nota enviada a las 08:54 quedaba grabada como enviada a
     * medianoche — nueve horas más vieja de lo real.
     *
     * No es cosmético: de FECHA_ENVIO dependen el orden de la bandeja, el "hace X" de cada
     * fila y los KPIs "Recientes 24h" / "Urgentes +3d". Con todo a medianoche, las notas del
     * mismo día empataban y los KPIs se disparaban hasta un día antes de tiempo.
     *
     * Si la fecha recibida trae hora, se respeta tal cual (un envío retroactivo con hora
     * exacta debe guardarse como se pidió). Si viene sólo el día, se le pega la hora actual:
     * el día es el que eligió el usuario, la hora es cuándo pulsó el botón.
     *
     * OJO: NO usar esto para movimientos_inventario.FECHA — esa columna es DATE y su
     * InventarioService::resolverFecha() aplica startOfDay() a propósito.
     */
    private function resolverFechaHora(mixed $fecha): Carbon
    {
        if (!$fecha) {
            return now();
        }

        $c = Carbon::parse($fecha);

        // "2026-07-10" no lleva hora; "2026-07-10 08:54" o un Carbon/DateTime sí. Sólo en el
        // primer caso completamos con la hora actual (parse ya la dejó en 00:00:00).
        $traeHora = !is_string($fecha) || preg_match('/\d{1,2}:\d{2}/', $fecha);
        if (!$traeHora) {
            $ahora = now();
            $c->setTime($ahora->hour, $ahora->minute, $ahora->second);
        }

        return $c;
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
        if (!$traspaso->puedeRecibirse()) {
            throw new RuntimeException("Solo un traspaso ENVIADO o con líneas pendientes puede recibirse (actual: {$traspaso->ESTADO}).");
        }

        return DB::transaction(function () use ($traspaso, $lineasRecibidas, $opts) {
            $lock = Traspaso::where('ID_TRASPASO', $traspaso->ID_TRASPASO)->lockForUpdate()->first();
            // MISMA regla que la guarda de arriba (puedeRecibirse), no esEnviado(): esta es la
            // comprobación real bajo lock —contra dos recepciones simultáneas— y si aquí se
            // exigiera ENVIADO, completar una nota RECIBIDO_PARCIAL fallaría igual dentro de la
            // transacción por más que la guarda previa la dejara pasar.
            if (!$lock || !$lock->puedeRecibirse()) {
                throw new RuntimeException('El traspaso cambió de estado mientras se intentaba recibir.');
            }

            $opUser = (int) ($opts['id_usuario_recepcion'] ?? 0);
            $fecha  = $opts['fecha_recepcion'] ?? null;

            // Indexar líneas por ID para emparejarlas con lo que llega del request.
            $lineas = $traspaso->lineas()->lockForUpdate()->get()->keyBy('ID_LINEA');

            $vistos = []; // id_linea ya procesados → rechazar duplicados en el payload

            // ── ORDEN DE BLOQUEO: siempre por ID_PRODUCTO ─────────────────────────────────
            // Cada línea recibida acaba bloqueando la fila de `almacen_stock` de su producto
            // en el almacén destino. El lock del traspaso de arriba serializa a dos personas
            // recibiendo LA MISMA nota, pero no a dos traspasos DISTINTOS que traen los
            // mismos productos: si sus payloads vienen en orden contrario, una toma A y
            // espera B mientras la otra toma B y espera A → "1213 Deadlock found" y una
            // recepción se cae a mitad. Recorriendo siempre en el mismo orden, la segunda
            // solo espera. Mismo criterio que el lote de salidas en AlmacenController.
            // Las líneas que no pertenecen al traspaso quedan al final y siguen lanzando su
            // error dentro del bucle, igual que antes.
            usort($lineasRecibidas, function ($a, $b) use ($lineas) {
                $pa = $lineas->get((int) ($a['id_linea'] ?? 0))->ID_PRODUCTO ?? PHP_INT_MAX;
                $pb = $lineas->get((int) ($b['id_linea'] ?? 0))->ID_PRODUCTO ?? PHP_INT_MAX;
                return (int) $pa <=> (int) $pb;
            });

            foreach ($lineasRecibidas as $rec) {
                $idLinea = (int) ($rec['id_linea'] ?? 0);
                $linea = $lineas->get($idLinea);
                if (!$linea) {
                    throw new InvalidArgumentException("La línea #{$idLinea} no pertenece a este traspaso.");
                }
                // Sin esto, dos entradas con el mismo id_linea aplicaban la entrada al destino
                // DOS veces (stock duplicado) y dejaban el primer movimiento huérfano.
                if (isset($vistos[$idLinea])) {
                    throw new InvalidArgumentException("La línea #{$idLinea} viene duplicada en la recepción.");
                }
                $vistos[$idLinea] = true;

                // Línea YA confirmada en una recepción anterior (nota en RECIBIDO_PARCIAL que
                // vuelve a la bandeja para completar lo pendiente): se SALTA. Sin esto, volver a
                // tildarla aplicaría la entrada al destino por segunda vez → stock duplicado y el
                // movimiento anterior huérfano. Es el mismo riesgo que cubre $vistos, pero ENTRE
                // recepciones distintas en vez de dentro del mismo payload. Se ignora en silencio
                // (no es un error del usuario: la vista ya las muestra confirmadas y solo se
                // envían las pendientes; esto es la red de seguridad del servidor).
                if ($linea->estaConfirmada()) {
                    continue;
                }
                $recibida = max(0.0, (float) ($rec['cantidad_recibida'] ?? 0));
                $estado   = $rec['estado'] ?? null; // opcional: DANADO sobrescribe el cálculo automático
                $notas    = $rec['notas'] ?? null;

                // Comparación enviada vs recibida (tolerancia EPS).
                // El estado de la LÍNEA sí registra la diferencia (alimenta el filtro
                // "Con discrepancias"); lo que ya NO hace es decidir el estado de la NOTA.
                $diff = round($recibida - (float) $linea->CANTIDAD_ENVIADA, 3);
                if ($estado === TraspasoLinea::ESTADO_DANADO) {
                    $estadoFinal = TraspasoLinea::ESTADO_DANADO;
                } elseif (abs($diff) < self::EPS) {
                    $estadoFinal = TraspasoLinea::ESTADO_OK;
                } elseif ($diff < 0) {
                    $estadoFinal = TraspasoLinea::ESTADO_FALTANTE;
                } else {
                    $estadoFinal = TraspasoLinea::ESTADO_SOBRANTE;
                }

                // Aplicamos la entrada al destino — SOLO si la cantidad recibida > 0 y la línea
                // NO está DAÑADA. Lo dañado NO suma al inventario disponible: la mercancía llegó
                // rota/inservible, así que se registra la línea como DAÑADO (con su cantidad, para
                // auditoría) pero sin entrada de stock. Si llegó 0, tampoco hay entrada.
                $entrada = null;
                if ($recibida > self::EPS && $estadoFinal !== TraspasoLinea::ESTADO_DANADO) {
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

            // Líneas que NO se reportaron: el usuario no las tildó, así que se quedan en
            // PENDIENTE con CANTIDAD_RECIBIDA en NULL. Antes se les forzaba 0 + FALTANTE, y eso
            // borraba la diferencia entre "no la confirmé" y "confirmé que llegaron 0": las dos
            // quedaban idénticas en la base. Las vistas ya pintan "—" cuando la cantidad es NULL.
            // No hace falta cruzar contra los ids del payload: toda línea reportada sale del
            // bucle de arriba con su CANTIDAD_RECIBIDA puesta, así que "sin confirmar" ya las
            // excluye. $lineas son los MISMOS objetos que se acaban de guardar.
            $quedaronPendientes = $lineas->contains(fn ($l) => !$l->estaConfirmada());

            // "Confirmada parcial" = QUEDARON PRODUCTOS SIN CONFIRMAR, no "hubo diferencias de
            // cantidad". Antes bastaba un faltante para marcar la nota como parcial y dejarla
            // atascada en la bandeja aunque el usuario hubiera revisado y aceptado la lista
            // entera. Las diferencias de cantidad se siguen viendo en el estado de cada línea
            // y por el filtro "Con discrepancias" — no retienen la nota en la bandeja.
            $traspaso->ESTADO              = $quedaronPendientes ? Traspaso::ESTADO_RECIBIDO_PARCIAL : Traspaso::ESTADO_RECIBIDO;
            $traspaso->FECHA_RECEPCION     = $this->resolverFechaHora($fecha);
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
                            // Ligar la entrada de retorno al pedido y al destino frustrado, para
                            // que Traspaso::movimientos() la incluya y el pedido cancelado muestre
                            // el trazo COMPLETO (salida + retorno), como promete el docblock.
                            'id_traspaso'            => (int) $lock->ID_TRASPASO,
                            'id_almacen_contraparte' => (int) $lock->ID_ALMACEN_DESTINO,
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

    /**
     * Público porque TraspasoController::update() también lo necesita: en modo parcial no
     * puede apoyarse en `different:id_almacen_origen` (el origen no viaja en el PATCH), y sin
     * este chequeo se podía guardar un borrador con origen == destino que solo reventaba al
     * enviarlo. Fuente única de la regla, en vez de repetirla en el controller.
     */
    public function validarOrigenDestino(int $origen, int $destino): void
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
