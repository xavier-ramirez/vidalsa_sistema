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

    public function esFaltante(): bool { return $this->ESTADO_LINEA === self::ESTADO_FALTANTE; }
    public function esSobrante(): bool { return $this->ESTADO_LINEA === self::ESTADO_SOBRANTE; }
    public function esDanado(): bool   { return $this->ESTADO_LINEA === self::ESTADO_DANADO; }
    public function esOk(): bool       { return $this->ESTADO_LINEA === self::ESTADO_OK; }
}
