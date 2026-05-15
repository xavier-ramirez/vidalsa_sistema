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

    /**
     * Metadata visual de cada TIPO para los partials del kardex (label / color
     * texto / color fondo / ícono). Centraliza la definición que antes vivía
     * duplicada en kardex_rows.blade.php y kardex_rows_mini.blade.php — si
     * mañana renombramos "Auditoría" o cambiamos el ícono de "Traspaso", se
     * toca un solo sitio.
     *
     * Formato: [LABEL_HUMANO, COLOR_TEXTO_HEX, COLOR_FONDO_HEX, MATERIAL_ICON]
     */
    public const TIPO_META = [
        self::TIPO_ENTRADA          => ['Entrada',           '#16a34a', '#dcfce7', 'add'],
        self::TIPO_TRASPASO_ENTRADA => ['Traspaso (entra)',  '#0891b2', '#cffafe', 'south_west'],
        self::TIPO_SALIDA           => ['Salida',            '#dc2626', '#fee2e2', 'remove'],
        self::TIPO_TRASPASO_SALIDA  => ['Traspaso (sale)',   '#ea580c', '#ffedd5', 'north_east'],
        // AJUSTE en BD = "Auditoría de Inventario" en UI (cuadre por conteo físico).
        self::TIPO_AJUSTE           => ['Auditoría',         '#7c3aed', '#ede9fe', 'fact_check'],
    ];

    /** Fallback usado cuando el TIPO no figura en la tabla (defensivo). */
    public const TIPO_META_DEFAULT = ['?', '#475569', '#f1f5f9', 'swap_vert'];

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
     * de Materiales. Consecutivo por año.
     *
     * Serializa el incremento usando la tabla `numero_nota_counter` con
     * `lockForUpdate`: la segunda petición concurrente espera al COMMIT de la
     * primera antes de leer su propio folio → cero duplicados aun bajo carga.
     *
     * DEBE llamarse DENTRO de una transacción (la propia que crea los movimientos
     * del lote) — de otro modo el lock se libera de inmediato y la garantía cae.
     */
    public static function generarNumeroNota(): string
    {
        $year = (int) date('Y');
        // Asegurar el row del año (idempotente vía INSERT IGNORE-equivalente con `insertOrIgnore`).
        \DB::table('numero_nota_counter')->insertOrIgnore([
            'ANIO'       => $year,
            'SIGUIENTE'  => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Lock + incremento atómico del folio.
        $row = \DB::table('numero_nota_counter')
            ->where('ANIO', $year)
            ->lockForUpdate()
            ->first();
        $siguiente = ((int) $row->SIGUIENTE) + 1;
        \DB::table('numero_nota_counter')
            ->where('ANIO', $year)
            ->update(['SIGUIENTE' => $siguiente, 'updated_at' => now()]);
        return sprintf('NE-%d-%04d', $year, $siguiente);
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
