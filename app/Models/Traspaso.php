<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Pedido de Traspaso entre dos almacenes — cabecera.
 * Las líneas (productos + cantidades) viven en {@see TraspasoLinea}.
 *
 * Flujo de estados (state machine):
 *   BORRADOR → ENVIADO → RECIBIDO | RECIBIDO_PARCIAL
 *   BORRADOR → CANCELADO   (sin tocar stock)
 *   ENVIADO  → CANCELADO   (reversa el stock al origen)
 */
class Traspaso extends Model
{
    use SoftDeletes;

    protected $table      = 'traspasos';
    protected $primaryKey = 'ID_TRASPASO';

    public const ESTADO_BORRADOR         = 'BORRADOR';
    public const ESTADO_ENVIADO          = 'ENVIADO';
    public const ESTADO_RECIBIDO         = 'RECIBIDO';
    public const ESTADO_RECIBIDO_PARCIAL = 'RECIBIDO_PARCIAL';
    public const ESTADO_CANCELADO        = 'CANCELADO';

    /** Estados terminales — no admiten más transiciones. */
    public const ESTADOS_FINALES = [
        self::ESTADO_RECIBIDO,
        self::ESTADO_RECIBIDO_PARCIAL,
        self::ESTADO_CANCELADO,
    ];

    /** Estados en los que el destino ya tiene mercancía visible en su stock. */
    public const ESTADOS_RECIBIDOS = [
        self::ESTADO_RECIBIDO,
        self::ESTADO_RECIBIDO_PARCIAL,
    ];

    /**
     * Metadata visual de cada ESTADO para los partials/vistas (label / fondo hex / texto hex).
     * Single source of truth — antes vivía duplicada en `recepcion/index.blade.php`,
     * `recepcion/detalle.blade.php` y `recepcion/partials/rows.blade.php` (3 sitios).
     * Mismo patrón que MovimientoInventario::TIPO_META.
     *
     * Formato: [LABEL_HUMANO, COLOR_FONDO_HEX, COLOR_TEXTO_HEX]
     */
    public const ESTADOS_META = [
        self::ESTADO_BORRADOR         => ['Borrador',           '#f1f5f9', '#64748b'],
        self::ESTADO_ENVIADO          => ['En tránsito',        '#fef3c7', '#b45309'],
        self::ESTADO_RECIBIDO         => ['Confirmada',         '#dcfce7', '#15803d'],
        self::ESTADO_RECIBIDO_PARCIAL => ['Confirmada parcial', '#fee2e2', '#b91c1c'],
        self::ESTADO_CANCELADO        => ['Cancelada',          '#e2e8f0', '#475569'],
    ];

    /** Fallback cuando el ESTADO no figura en ESTADOS_META (defensivo, igual que TIPO_META_DEFAULT). */
    public const ESTADO_META_DEFAULT = ['—', '#f1f5f9', '#64748b'];

    protected $fillable = [
        'NUMERO',
        'ID_ALMACEN_ORIGEN',
        'ID_ALMACEN_DESTINO',
        'ID_FRENTE_DESTINO',
        'ESTADO',
        'FECHA_ENVIO',
        'FECHA_RECEPCION',
        'ID_USUARIO_CREO',
        'ID_USUARIO_ENVIO',
        'ID_USUARIO_RECEPCION',
        'REFERENCIA',
        'MOTIVO',
        'NOTAS',
    ];

    protected $casts = [
        'FECHA_ENVIO'     => 'datetime',
        'FECHA_RECEPCION' => 'datetime',
    ];

    // ── Relaciones ───────────────────────────────────────────────

    public function lineas()
    {
        return $this->hasMany(TraspasoLinea::class, 'ID_TRASPASO', 'ID_TRASPASO');
    }

    public function almacenOrigen()
    {
        return $this->belongsTo(Almacen::class, 'ID_ALMACEN_ORIGEN', 'ID_ALMACEN');
    }

    public function almacenDestino()
    {
        return $this->belongsTo(Almacen::class, 'ID_ALMACEN_DESTINO', 'ID_ALMACEN');
    }

    public function frenteDestino()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_DESTINO', 'ID_FRENTE');
    }

    public function usuarioCreo()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_CREO', 'ID_USUARIO');
    }

    public function usuarioEnvio()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_ENVIO', 'ID_USUARIO');
    }

    public function usuarioRecepcion()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO_RECEPCION', 'ID_USUARIO');
    }

    /** Kardex físico ligado a este traspaso (TRASPASO_SALIDA + TRASPASO_ENTRADA). */
    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class, 'ID_TRASPASO', 'ID_TRASPASO');
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeEstado(Builder $q, string|array $estados): Builder
    {
        return $q->whereIn('ESTADO', (array) $estados);
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function esBorrador(): bool   { return $this->ESTADO === self::ESTADO_BORRADOR; }
    public function esEnviado(): bool    { return $this->ESTADO === self::ESTADO_ENVIADO; }
    public function esRecibido(): bool   { return in_array($this->ESTADO, self::ESTADOS_RECIBIDOS, true); }
    public function esCancelado(): bool  { return $this->ESTADO === self::ESTADO_CANCELADO; }
    public function esFinal(): bool      { return in_array($this->ESTADO, self::ESTADOS_FINALES, true); }

    /**
     * Genera el siguiente folio (TR-YYYY-NNNN). Debe llamarse dentro de la misma
     * transacción que crea el registro para evitar choques en el índice único.
     * El año va embebido en el folio porque el contador NO se reinicia anualmente
     * (es global) — el año solo indica la cohorte, así nunca chocan.
     */
    public static function generarNumero(): string
    {
        $anio = now()->year;
        // Busca el mayor consecutivo histórico (de cualquier año) con el formato esperado.
        $max = 0;
        static::withTrashed()
            ->where('NUMERO', 'like', 'TR-%')
            ->pluck('NUMERO')
            ->each(function ($num) use (&$max) {
                if (preg_match('/^TR-\d{4}-(\d{4,})$/', (string) $num, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            });
        $siguiente = $max + 1;

        // Defensa contra colisión (raro: dos procesos asignan el mismo nº). El UNIQUE de
        // la BD igual nos protege, pero así devolvemos el siguiente libre al primer intento.
        while (static::withTrashed()->where('NUMERO', sprintf('TR-%d-%04d', $anio, $siguiente))->exists()) {
            $siguiente++;
        }
        return sprintf('TR-%d-%04d', $anio, $siguiente);
    }
}
