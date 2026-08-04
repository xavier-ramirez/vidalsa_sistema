<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Línea de detalle de un {@see Traspaso}: producto + cantidades enviada/recibida.
 *
 *   - CANTIDAD_ENVIADA:  se fija cuando el traspaso pasa a ENVIADO.
 *   - CANTIDAD_RECIBIDA: se llena cuando el destino confirma la recepción.
 *   - ESTADO_LINEA refleja la comparación final:
 *       OK        → enviado == recibido (con tolerancia EPS).
 *       FALTANTE  → recibido < enviado.
 *       SOBRANTE  → recibido > enviado.
 *       DANADO    → marcado manualmente por el receptor (con o sin diferencia).
 */
class TraspasoLinea extends Model
{
    protected $table      = 'traspaso_lineas';
    protected $primaryKey = 'ID_LINEA';

    public const ESTADO_PENDIENTE = 'PENDIENTE';
    public const ESTADO_OK        = 'OK';
    public const ESTADO_FALTANTE  = 'FALTANTE';
    public const ESTADO_SOBRANTE  = 'SOBRANTE';
    public const ESTADO_DANADO    = 'DANADO';

    /**
     * Metadata visual de cada ESTADO_LINEA para los partials de detalle (label / fondo / texto).
     * Single source of truth — antes vivía duplicada en `recepcion/detalle.blade.php` y
     * `recepcion/partials/detalle_modal.blade.php`. Mismo patrón que Traspaso::ESTADOS_META.
     *
     * Formato: [LABEL_HUMANO, COLOR_FONDO_HEX, COLOR_TEXTO_HEX]
     */
    public const ESTADOS_META = [
        self::ESTADO_PENDIENTE => ['Pendiente', '#f1f5f9', '#64748b'],
        self::ESTADO_OK        => ['OK',        '#dcfce7', '#15803d'],
        self::ESTADO_FALTANTE  => ['Faltante',  '#fee2e2', '#b91c1c'],
        self::ESTADO_SOBRANTE  => ['Sobrante',  '#dbeafe', '#1d4ed8'],
        self::ESTADO_DANADO    => ['Dañado',    '#fef3c7', '#b45309'],
    ];

    /**
     * Estados de línea que son una DISCREPANCIA con lo despachado: lo que alimenta el filtro
     * "Con discrepancias" de la bandeja de Recepción. PENDIENTE no entra (no es una diferencia,
     * es que aún no se revisó) y OK tampoco (cuadró).
     */
    public const ESTADOS_DISCREPANCIA = [
        self::ESTADO_FALTANTE,
        self::ESTADO_SOBRANTE,
        self::ESTADO_DANADO,
    ];

    /** Fallback cuando ESTADO_LINEA no figura en ESTADOS_META (defensivo). */
    public const ESTADO_META_DEFAULT = ['—', '#f1f5f9', '#64748b'];

    protected $fillable = [
        'ID_TRASPASO',
        'ID_PRODUCTO',
        'CANTIDAD_ENVIADA',
        'CANTIDAD_RECIBIDA',
        'ESTADO_LINEA',
        'NOTAS_LINEA',
        'ID_MOVIMIENTO_SALIDA',
        'ID_MOVIMIENTO_ENTRADA',
    ];

    protected $casts = [
        'CANTIDAD_ENVIADA'  => 'decimal:3',
        'CANTIDAD_RECIBIDA' => 'decimal:3',
    ];

    // ── Relaciones ───────────────────────────────────────────────

    public function traspaso()
    {
        return $this->belongsTo(Traspaso::class, 'ID_TRASPASO', 'ID_TRASPASO');
    }

    public function producto()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }

    public function movimientoSalida()
    {
        return $this->belongsTo(MovimientoInventario::class, 'ID_MOVIMIENTO_SALIDA', 'ID_MOVIMIENTO');
    }

    public function movimientoEntrada()
    {
        return $this->belongsTo(MovimientoInventario::class, 'ID_MOVIMIENTO_ENTRADA', 'ID_MOVIMIENTO');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /** Diferencia (recibida − enviada). Negativa = faltó, positiva = sobró. */
    public function getDiferenciaAttribute(): float
    {
        if ($this->CANTIDAD_RECIBIDA === null) {
            return 0.0;
        }
        return round((float) $this->CANTIDAD_RECIBIDA - (float) $this->CANTIDAD_ENVIADA, 3);
    }

    // Sin helpers esFaltante()/esSobrante()/esDanado()/esOk(): nadie los llamaba. Las vistas
    // resuelven el estado de la línea con ESTADOS_META[$linea->ESTADO_LINEA], que es la única
    // fuente de su etiqueta y sus colores.

    /**
     * ¿Ya se confirmó esta línea? Es EL concepto sobre el que gira la recepción parcial:
     * decide si la nota queda RECIBIDO o RECIBIDO_PARCIAL, si la fila del modal se puede
     * volver a tildar y si el servidor debe ignorarla para no duplicar stock.
     *
     * La marca es CANTIDAD_RECIBIDA (NULL = sin confirmar) y no ESTADO_LINEA: los dos campos
     * se escriben siempre juntos —TraspasoService los pone en el alta y en la recepción— pero
     * la cantidad es el dato, y el estado su etiqueta. Estaba deletreado a mano en el servicio,
     * en las dos vistas de recepción y en la migración; aquí queda en un solo sitio.
     */
    public function estaConfirmada(): bool
    {
        return $this->CANTIDAD_RECIBIDA !== null;
    }

    /** Líneas todavía sin confirmar. Contraparte en consulta de estaConfirmada(). */
    public function scopePendiente($query)
    {
        return $query->whereNull('CANTIDAD_RECIBIDA');
    }
}
