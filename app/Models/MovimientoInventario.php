<?php

namespace App\Models;

use App\Casts\MojibakeFix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

/**
 * Kardex de inventario: una fila por cada entrada / salida / ajuste / traspaso / devolución.
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
    /**
     * Material que VUELVE de un proyecto al almacén (sacaron BRAGA 45 y la regresan porque
     * era la 42). Suma al stock como una entrada, pero va enlazada a la SALIDA que devuelve
     * por ID_MOVIMIENTO_RELACIONADO y le resta al consumo de esa salida. La registra
     * App\Services\DevolucionService.
     */
    public const TIPO_DEVOLUCION       = 'DEVOLUCION';

    /** Tipos que SUMAN al stock. */
    public const TIPOS_ENTRADA = [self::TIPO_ENTRADA, self::TIPO_TRASPASO_ENTRADA, self::TIPO_DEVOLUCION];
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
        // Color propio (verde azulado) y no el verde de Entrada: suma al stock igual, pero
        // no es material nuevo y se tiene que distinguir de una compra de un vistazo.
        self::TIPO_DEVOLUCION       => ['Devolución', '#0d9488', '#ccfbf1', 'assignment_return'],
    ];

    /** Fallback usado cuando el TIPO no figura en la tabla (defensivo). */
    public const TIPO_META_DEFAULT = ['?', '#475569', '#f1f5f9', 'swap_vert'];

    /**
     * REFERENCIA de la ENTRADA que se registra al crear un producto con cantidad inicial
     * (AlmacenController::storeProducto). Los kardex la reconocen con esStockInicial() y
     * la pintan en dos líneas: "STOCK INICIAL" + "Nuevo material".
     */
    public const REF_STOCK_INICIAL = 'STOCK INICIAL';

    public function esStockInicial(): bool
    {
        return $this->TIPO === self::TIPO_ENTRADA && $this->REFERENCIA === self::REF_STOCK_INICIAL;
    }

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
        // Bolsa de saldo que movió la fila (0 = común). Ver InventarioService.
        'ID_FRENTE_SALDO',
        'ID_USUARIO',
        'REFERENCIA',
        'NUMERO_PARTE',
        'NUMERO_CONTRATO',
        'NUMERO_RQ',
        'SOLICITANTE',
        'DEPARTAMENTO',
        'NUMERO_NOTA',
        // Plantilla con la que se emitió la Nota (VERTICAL/HORIZONTAL) — se congela al
        // registrar para que el historial reimprima la hoja que se firmó, no la que el
        // almacén emita hoy. Ver InventarioService::aplicarMovimiento.
        'FORMATO_NOTA',
        'MOTIVO',
        'NOTAS',
    ];

    /**
     * Genera el siguiente NUMERO_NOTA (NE-YYYY-NNNN) para una Nota de Entrega
     * de Materiales. Consecutivo por año.
     *
     * Serializa el incremento con UN SOLO UPDATE sobre la fila del año en
     * `numero_nota_counter`: ese UPDATE toma el candado EXCLUSIVO de entrada, así la
     * segunda salida simultánea simplemente espera al COMMIT de la primera y luego lee
     * su propio folio → cero duplicados aun con varios almacenes despachando a la vez.
     *
     * POR QUÉ NO `insertOrIgnore` + `lockForUpdate` (como estaba antes): sobre una fila que
     * YA existe, el INSERT IGNORE deja un candado COMPARTIDO, y el SELECT ... FOR UPDATE que
     * venía detrás pide el EXCLUSIVO de esa misma fila. Con varias peticiones a la vez todas
     * sostienen el compartido y todas esperan el exclusivo: InnoDB lo corta con
     * "1213 Deadlock found". Medido: con 10 salidas simultáneas fallaban 8. Un único UPDATE
     * no sube de candado compartido a exclusivo, así que no hay deadlock — solo cola.
     *
     * LAST_INSERT_ID(expr) es el modismo de MySQL para leer el valor que el propio UPDATE
     * acaba de calcular; es POR CONEXIÓN, así que dos peticiones nunca se pisan el valor.
     *
     * DEBE llamarse DENTRO de una transacción (la propia que crea los movimientos del lote)
     * — de otro modo el candado se libera de inmediato y la garantía cae.
     */
    public static function generarNumeroNota(): string
    {
        $year = (int) date('Y');

        // RECONCILIAR con el máximo número REAL ya usado en movimientos del año. Si el
        // contador quedó atrás (p.ej. notas importadas/migradas sin pasar por aquí), generar
        // contador+1 produciría un número YA EXISTENTE → la Nota mostraría movimientos de
        // OTRAS salidas (bug de "el PDF trae más productos"). Se toma el mayor de los dos.
        // SUBSTRING_INDEX(...,'-',-1) extrae el folio tras el último guión (robusto a 4+ dígitos).
        // Va ANTES del UPDATE y sin candado a propósito: si dos peticiones leen el mismo
        // máximo, el GREATEST del UPDATE —que sí está serializado— las separa igual.
        $maxReal = (int) static::where('NUMERO_NOTA', 'like', 'NE-' . $year . '-%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(NUMERO_NOTA, '-', -1) AS UNSIGNED)) AS m")
            ->value('m');

        $sql = 'UPDATE numero_nota_counter
                   SET SIGUIENTE = LAST_INSERT_ID(GREATEST(SIGUIENTE, ?) + 1),
                       updated_at = ?
                 WHERE ANIO = ?';

        if (\DB::update($sql, [$maxReal, now(), $year]) === 0) {
            // Primera nota del año: la fila todavía no existe. insertOrIgnore por si dos
            // peticiones la crean a la vez (una gana, la otra la ignora) y se repite el
            // UPDATE, que es quien asigna el folio. Este camino corre UNA vez al año.
            \DB::table('numero_nota_counter')->insertOrIgnore([
                'ANIO'       => $year,
                'SIGUIENTE'  => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \DB::update($sql, [$maxReal, now(), $year]);
        }

        $siguiente = (int) \DB::selectOne('SELECT LAST_INSERT_ID() AS n')->n;

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

    // ── Devoluciones ─────────────────────────────────────────────

    /**
     * Cantidad NETA de una salida en una consulta de consumo: lo entregado menos lo que se
     * devolvió de ella. Exige el JOIN de scopeConDevuelto (alias `dv`).
     */
    public const SQL_CANTIDAD_NETA = '(movimientos_inventario.CANTIDAD - COALESCE(dv.DEVUELTO, 0))';

    /**
     * Une a cada fila lo que ya se devolvió de ella (dv.DEVUELTO; NULL si nada). Lo usan
     * las consultas de CONSUMO para contar SALIDA − DEVOLUCION con SQL_CANTIDAD_NETA.
     *
     * La devolución resta en la fecha de la SALIDA, no en la suya: la braga 45 que se
     * regresó no se consumió nunca, así que desaparece del mes en que salió y el gráfico
     * no pinta un mes con consumo negativo. La 42 que se entregó a cambio es una salida
     * nueva y cuenta en el mes en que se entregó, que es cuando se consumió de verdad.
     *
     * Las columnas del derivado (ID_SALIDA, DEVUELTO) no existen en ninguna otra tabla, así
     * que el JOIN no vuelve ambiguas las FECHA/CANTIDAD sin prefijo de quien lo usa.
     */
    public function scopeConDevuelto(Builder $q): Builder
    {
        $devuelto = static::query()->toBase()
            ->where('TIPO', self::TIPO_DEVOLUCION)
            ->whereNotNull('ID_MOVIMIENTO_RELACIONADO')
            ->groupBy('ID_MOVIMIENTO_RELACIONADO')
            ->selectRaw('ID_MOVIMIENTO_RELACIONADO AS ID_SALIDA, SUM(CANTIDAD) AS DEVUELTO');

        return $q->leftJoinSub($devuelto, 'dv', 'dv.ID_SALIDA', '=', 'movimientos_inventario.ID_MOVIMIENTO');
    }

    /**
     * Lo que falta por devolver de cada salida, [ID_MOVIMIENTO => cantidad] (0 si ya volvió
     * entera). Lo usan la devolución (DevolucionService) y eliminarNota, que revierte solo
     * lo que no se había devuelto. Es la MISMA cuenta que el consumo (scopeConDevuelto +
     * SQL_CANTIDAD_NETA), en una consulta.
     */
    public static function porDevolver($salidas): array
    {
        $ids = collect($salidas)->pluck('ID_MOVIMIENTO');
        if ($ids->isEmpty()) {
            return [];
        }

        return static::query()->conDevuelto()
            ->whereIn('movimientos_inventario.ID_MOVIMIENTO', $ids)
            ->selectRaw('movimientos_inventario.ID_MOVIMIENTO AS id, ' . self::SQL_CANTIDAD_NETA . ' AS neto')
            ->pluck('neto', 'id')
            ->mapWithKeys(fn ($neto, $id) => [(int) $id => max(0.0, round((float) $neto, 3))])
            ->all();
    }

    /**
     * Cuáles de los N° de Nota que traen en REFERENCIA las devoluciones de esta página
     * siguen existiendo, como [NUMERO_NOTA => true]. El kardex solo enlaza al PDF los que
     * están aquí: una nota eliminada ya no tiene PDF y el enlace daría 404. Una consulta
     * por página y ninguna si en ella no hay devoluciones.
     */
    public static function notasVigentesDeDevoluciones($movimientos): array
    {
        $filas   = is_array($movimientos) ? collect($movimientos) : $movimientos;
        $numeros = $filas->where('TIPO', self::TIPO_DEVOLUCION)->pluck('REFERENCIA')->filter()->unique()->values();

        return $numeros->isEmpty()
            ? []
            : static::whereIn('NUMERO_NOTA', $numeros)->distinct()->pluck('NUMERO_NOTA')->flip()->map(fn () => true)->all();
    }

    /**
     * Bolsa de la que se descontó el saldo cuando NO es la del proyecto al que se entregó.
     * NULL cuando coinciden —el caso normal— o en filas anteriores a ID_FRENTE_SALDO.
     *
     * OJO: esto SOLO mira la fila, y con la fila sola no alcanza. En un almacén que no
     * separa por proyecto todo el saldo vive en la bolsa común (0) mientras ID_FRENTE lleva
     * el frente destino, así que 0 != destino en TODAS sus salidas y esto devolvería una
     * bolsa en cada una sin que nadie haya prestado nada. Para pintar el aviso hay que usar
     * prestamosPorMovimiento(), que además comprueba que el almacén separe.
     */
    public function bolsaPrestada(): ?int
    {
        if ($this->ID_FRENTE_SALDO === null || (int) $this->ID_FRENTE_SALDO === (int) $this->ID_FRENTE) {
            return null;
        }
        return (int) $this->ID_FRENTE_SALDO;
    }

    /**
     * Nombres de las bolsas prestadas que aparecen en un conjunto de movimientos, en UNA
     * consulta. Devuelve [ID_FRENTE => NOMBRE_FRENTE]; la bolsa común (0) no entra porque
     * no es un frente y su rótulo lo pone InventarioService::ROTULO_BOLSA_COMUN*.
     *
     * Acepta lo que le den: Collection, PAGINADOR (así llega desde el kardex) o array.
     * NO envolver en collect() — sobre un paginador eso devuelve su metadata
     * (current_page, data, links…) y no sus filas, así que el pluck salía vacío y el
     * kardex rotulaba "proyecto #5" en vez del nombre del frente. pluck() directo sí
     * funciona en los tres casos: el paginador lo reenvía a su colección de items.
     */
    public static function nombresDeBolsa($movimientos)
    {
        $filas = is_array($movimientos) ? collect($movimientos) : $movimientos;
        $ids   = $filas->pluck('ID_FRENTE_SALDO')->filter(fn ($v) => (int) $v > 0)->unique();

        return $ids->isEmpty()
            ? collect()
            : FrenteTrabajo::whereIn('ID_FRENTE', $ids)->pluck('NOMBRE_FRENTE', 'ID_FRENTE');
    }

    /**
     * Cuáles de estos movimientos son REALMENTE un préstamo entre bolsas, como
     * [ID_MOVIMIENTO => bolsa de la que salió].
     *
     * PUNTO ÚNICO de esa decisión: la usan los dos kardex y el export de la bitácora, que
     * antes preguntaban cada uno por su cuenta con bolsaPrestada() y marcaban de más.
     *
     * Solo hay préstamo donde el almacén SEPARA por proyecto: en el resto no hay bolsas que
     * prestar, todo el saldo es de la común. Eso no se puede saber por la fila, así que se
     * resuelve por PÁGINA —una consulta con los almacenes que aparecen— y no por fila, que
     * seria un N+1 que crece con el kardex.
     */
    public static function prestamosPorMovimiento($movimientos): array
    {
        $filas = is_array($movimientos) ? collect($movimientos) : $movimientos;

        // Candidatos: los que la fila ya descarta no hace falta ni consultarlos.
        $candidatos = $filas->filter(fn ($m) => $m->bolsaPrestada() !== null);
        if ($candidatos->isEmpty()) {
            return [];
        }

        // separaPorProyecto() del modelo, no una copia de su regla aquí.
        $separan = Almacen::with('frentes:ID_FRENTE')
            ->whereIn('ID_ALMACEN', $candidatos->pluck('ID_ALMACEN')->filter()->unique())
            ->get()
            ->filter(fn ($a) => $a->separaPorProyecto())
            ->pluck('ID_ALMACEN')
            ->flip();

        return $candidatos
            ->filter(fn ($m) => isset($separan[$m->ID_ALMACEN]))
            ->mapWithKeys(fn ($m) => [$m->ID_MOVIMIENTO => $m->bolsaPrestada()])
            ->all();
    }

}
