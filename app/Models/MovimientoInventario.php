<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kardex de inventario: una fila por cada entrada / salida / ajuste / traspaso.
 * Fuente de verdad del stock (almacen_stock.CANTIDAD es solo el acumulado).
 */
class MovimientoInventario extends Model
{
    protected $table      = 'movimientos_inventario';
    protected $primaryKey = 'ID_MOVIMIENTO';

    public const TIPO_ENTRADA          = 'ENTRADA';
    public const TIPO_SALIDA           = 'SALIDA';
    public const TIPO_AJUSTE           = 'AJUSTE';
    public const TIPO_TRASPASO_ENTRADA = 'TRASPASO_ENTRADA';
    public const TIPO_TRASPASO_SALIDA  = 'TRASPASO_SALIDA';

    /** Tipos que SUMAN al stock. */
    public const TIPOS_ENTRADA = [self::TIPO_ENTRADA, self::TIPO_TRASPASO_ENTRADA];
    /** Tipos que RESTAN del stock. */
    public const TIPOS_SALIDA  = [self::TIPO_SALIDA, self::TIPO_TRASPASO_SALIDA];

    protected $fillable = [
        'ID_ALMACEN',
        'ID_PRODUCTO',
        'TIPO',
        'CANTIDAD',
        'CANTIDAD_ANTERIOR',
        'CANTIDAD_RESULTANTE',
        'FECHA',
        'ID_ALMACEN_CONTRAPARTE',
        'ID_MOVIMIENTO_RELACIONADO',
        'ID_TRASPASO',
        'ID_FRENTE',
        'ID_USUARIO',
        'REFERENCIA',
        'NUMERO_CONTRATO',
        'NUMERO_RQ',
        'SOLICITANTE',
        'DEPARTAMENTO',
        'NUMERO_NOTA',
        'MOTIVO',
        'NOTAS',
    ];

    /**
     * Genera el siguiente NUMERO_NOTA (NE-YYYY-NNNN) para una Nota de Entrega
     * de Materiales. Consecutivo GLOBAL — no se reinicia por año (mismo patrón
     * que Traspaso::generarNumero()).
     *
     * Debe llamarse DENTRO de la transacción que crea los movimientos del lote
     * para que el conteo refleje las notas ya emitidas y no haya carrera entre
     * dos lotes simultáneos.
     */
    public static function generarNumeroNota(): string
    {
        $year = date('Y');
        $count = self::whereNotNull('NUMERO_NOTA')->count() + 1;
        return sprintf('NE-%s-%04d', $year, $count);
    }

    protected $casts = [
        'CANTIDAD'            => 'decimal:3',
        'CANTIDAD_ANTERIOR'   => 'decimal:3',
        'CANTIDAD_RESULTANTE' => 'decimal:3',
        'FECHA'               => 'date',
    ];

    // ── Relaciones ───────────────────────────────────────────────

    public function almacen()
    {
        return $this->belongsTo(Almacen::class, 'ID_ALMACEN', 'ID_ALMACEN');
    }

    public function almacenContraparte()
    {
        return $this->belongsTo(Almacen::class, 'ID_ALMACEN_CONTRAPARTE', 'ID_ALMACEN');
    }

    public function producto()
    {
        return $this->belongsTo(ProductoInventario::class, 'ID_PRODUCTO', 'ID_PRODUCTO');
    }

    public function frente()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE', 'ID_FRENTE');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO', 'ID_USUARIO');
    }

    /** Pedido de Traspaso padre (cuando este movimiento es TRASPASO_SALIDA / TRASPASO_ENTRADA). */
    public function traspaso()
    {
        return $this->belongsTo(Traspaso::class, 'ID_TRASPASO', 'ID_TRASPASO');
    }

    // ── Scopes ───────────────────────────────────────────────────

    /** Filtra por rango de fechas (usado por el endpoint de kardex). */
    public function scopePeriodo(Builder $q, ?string $desde, ?string $hasta): Builder
    {
        if ($desde) $q->whereDate('FECHA', '>=', $desde);
        if ($hasta) $q->whereDate('FECHA', '<=', $hasta);
        return $q;
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function esEntrada(): bool
    {
        return in_array($this->TIPO, self::TIPOS_ENTRADA, true);
    }

    public function esSalida(): bool
    {
        return in_array($this->TIPO, self::TIPOS_SALIDA, true);
    }

    /** Cantidad con signo según el tipo (+ entrada, − salida, ± ajuste). */
    public function getCantidadConSignoAttribute(): float
    {
        if ($this->esEntrada()) return (float) $this->CANTIDAD;
        if ($this->esSalida())  return -1 * (float) $this->CANTIDAD;
        // AJUSTE: el signo real lo da resultante - anterior
        return (float) $this->CANTIDAD_RESULTANTE - (float) $this->CANTIDAD_ANTERIOR;
    }
}
