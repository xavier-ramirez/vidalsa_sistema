<?php

namespace App\Models;

use App\Casts\MojibakeFix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kardex de inventario: una fila por cada entrada / salida / ajuste / traspaso.
 * Fuente de verdad del stock (almacen_stock.CANTIDAD es solo el acumulado).
 */
class MovimientoInventario extends Model
{
    /**
     * Snapshot offline: este modelo forma parte del dominio "almacen", asi que al
     * escribirlo hay que marcar esa version como obsoleta para que los clientes
     * que SI cachean ese dominio se traigan el cambio. Los que no lo cachean ni
     * se enteran, que es justo el objetivo de separar las versiones.
     */
    protected static function booted(): void
    {
        $marcar = static fn () => \App\Support\OfflineVersion::invalidar('almacen');
        static::saved($marcar);
        static::deleted($marcar);
    }

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
        self::TIPO_ENTRADA          => ['Entrada',  '#16a34a', '#dcfce7', 'add'],
        // Traspaso entre almacenes: se muestra como Entrada/Salida normal (mismo label y
        // color que su contraparte pura) — para el usuario una salida a otro almacén ES
        // una salida. El ícono direccional (south_west/north_east) y la columna de
        // contraparte/frente indican que fue un movimiento entre almacenes.
        self::TIPO_TRASPASO_ENTRADA => ['Entrada',  '#16a34a', '#dcfce7', 'south_west'],
        self::TIPO_SALIDA           => ['Salida',   '#dc2626', '#fee2e2', 'remove'],
        self::TIPO_TRASPASO_SALIDA  => ['Salida',   '#dc2626', '#fee2e2', 'north_east'],
        // AJUSTE en BD = "Auditoría de Inventario" en UI (cuadre por conteo físico).
        self::TIPO_AJUSTE           => ['Auditoría','#0067b1', '#e1effa', 'fact_check'],
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
        'NUMERO_PARTE',
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

        // RECONCILIAR con el máximo número REAL ya usado en movimientos del año. Si el
        // counter quedó atrás (p.ej. notas importadas/migradas sin pasar por aquí), generar
        // counter+1 produciría un número YA EXISTENTE → la Nota mostraría movimientos de
        // OTRAS salidas (bug de "el PDF trae más productos"). Tomamos el mayor de ambos.
        // SUBSTRING_INDEX(...,'-',-1) extrae el folio tras el último guión (robusto a 4+ dígitos).
        $maxReal = (int) static::where('NUMERO_NOTA', 'like', 'NE-' . $year . '-%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(NUMERO_NOTA, '-', -1) AS UNSIGNED)) AS m")
            ->value('m');

        $siguiente = max((int) $row->SIGUIENTE, $maxReal) + 1;
        \DB::table('numero_nota_counter')
            ->where('ANIO', $year)
            ->update(['SIGUIENTE' => $siguiente, 'updated_at' => now()]);
        return sprintf('NE-%d-%04d', $year, $siguiente);
    }

    /**
     * Casts:
     *  - CANTIDAD*: decimales con 3 posiciones (matching las columnas DECIMAL(15,3)).
     *  - FECHA: Carbon date.
     *  - MOTIVO / SOLICITANTE / DEPARTAMENTO / NUMERO_CONTRATO / NUMERO_RQ / NOTAS:
     *    auto-decode mojibake (UTF-8 doble-encoded) al leer — strings limpios pasan
     *    sin tocar. Asi el kardex y los PDFs muestran tildes correctas sin tener
     *    que llamar a un helper manualmente en cada vista.
     */
    protected $casts = [
        'CANTIDAD'            => 'decimal:3',
        'CANTIDAD_ANTERIOR'   => 'decimal:3',
        'CANTIDAD_RESULTANTE' => 'decimal:3',
        'FECHA'               => 'date',
        'MOTIVO'              => MojibakeFix::class,
        'SOLICITANTE'         => MojibakeFix::class,
        'DEPARTAMENTO'        => MojibakeFix::class,
        'NUMERO_CONTRATO'     => MojibakeFix::class,
        'NUMERO_RQ'           => MojibakeFix::class,
        'NOTAS'               => MojibakeFix::class,
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

    /**
     * Normaliza un rango Desde/Hasta. Acepta 'YYYY-MM-DD' (día) o 'YYYY-MM' (filtro por
     * MES): si llega solo el mes lo expande (Desde → primer día; Hasta → último día).
     * Sin esto, '<= YYYY-MM' se interpreta como 'YYYY-MM-00' y EXCLUYE todo el mes.
     * Es idempotente (una fecha completa de 10 chars pasa tal cual) y FUENTE ÚNICA del
     * idiom — la usan scopePeriodo y AlmacenController::consumoDashboard.
     * @return array{0:?string,1:?string} [desde, hasta] ya expandidos (o null).
     */
    public static function expandirRangoMes(?string $desde, ?string $hasta): array
    {
        $desde = $desde ? trim($desde) : null;
        $hasta = $hasta ? trim($hasta) : null;
        if ($desde && strlen($desde) === 7) {
            $desde .= '-01';
        }
        if ($hasta && strlen($hasta) === 7) {
            // '-01' antes de endOfMonth evita el desbordamiento de día (p.ej. feb 31 → marzo).
            $hasta = \Carbon\Carbon::parse($hasta . '-01')->endOfMonth()->format('Y-m-d');
        }
        return [$desde ?: null, $hasta ?: null];
    }

    /** Filtra por rango de fechas. Soporta día ('YYYY-MM-DD') y mes ('YYYY-MM') vía expandirRangoMes. */
    public function scopePeriodo(Builder $q, ?string $desde, ?string $hasta): Builder
    {
        [$desde, $hasta] = static::expandirRangoMes($desde, $hasta);
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

}
