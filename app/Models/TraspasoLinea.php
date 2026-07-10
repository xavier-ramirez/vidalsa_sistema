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
}
