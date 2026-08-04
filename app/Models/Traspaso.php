<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Estados que NO admiten anulación. Se llaman "finales" por historia: RECIBIDO_PARCIAL sí
     * admite una transición más —volver a recibirse para completar las líneas pendientes, ver
     * puedeRecibirse()—, pero igual que los otros dos ya movió stock al destino y no se puede
     * revertir. Su único uso es esFinal(), y esFinal() solo lo consulta quien decide si se
     * puede cancelar (TraspasoService::cancelar y $puedeCancelar del controller).
     */
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

    /**
     * Estados que trae la bandeja de Recepción CUANDO NO SE FILTRA NADA: los dos que aún
     * piden acción del almacén que recibe.
     *   · ENVIADO          → "En tránsito": llegó la nota, falta confirmarla.
     *   · RECIBIDO_PARCIAL → "Confirmada parcial": QUEDARON PRODUCTOS SIN CONFIRMAR (líneas
     *                        en PENDIENTE). NO significa "se confirmó con diferencias": una
     *                        nota aceptada entera pasa a RECIBIDO aunque las cantidades no
     *                        cuadren, y sale de la bandeja. Las diferencias se consultan por
     *                        el filtro "Con discrepancias".
     * Quedan fuera del default —solo se ven si se piden por el filtro— RECIBIDO (cerrada sin
     * novedad), BORRADOR (es del almacén que emite) y CANCELADO (ya revirtió el stock).
     */
    public const ESTADOS_BANDEJA_DEFAULT = self::ESTADOS_RECIBIBLES;

    /**
     * Notas que TODAVÍA admiten confirmación de mercancía — la definición de "pide acción del
     * almacén que recibe". De aquí salen TRES cosas que antes se escribían por separado y
     * podían desincronizarse: el default de la bandeja, puedeRecibirse() y los contadores
     * ("Por revisar", el badge del menú). Si mañana se suma un estado, se suma una sola vez.
     */
    public const ESTADOS_RECIBIBLES = [
        self::ESTADO_ENVIADO,
        self::ESTADO_RECIBIDO_PARCIAL,
    ];

    /**
     * PSEUDO-estados del filtro "Estado" de la bandeja de Recepción. NO son valores de la
     * columna ESTADO: son atajos del UI que TraspasoController@index traduce a su propio
     * WHERE. Van en minúscula justamente para que se distingan de los ESTADOS reales y
     * nunca colisionen con ellos.
     *   · all           → sin filtro de estado (historial completo de sus almacenes). NO se
     *                     ofrece en el dropdown (el cliente no quiere el histórico en la
     *                     bandeja); se acepta por URL y por eso conserva su rótulo.
     *   · con_faltantes → notas YA CONFIRMADAS (ESTADOS_RECIBIDOS) con al menos una línea en
     *                     discrepancia (TraspasoLinea::ESTADOS_DISCREPANCIA: faltante, sobrante
     *                     o dañado). Es la ÚNICA forma de encontrarlas: al confirmarse completas
     *                     ya no aparecen en la bandeja por defecto. (La constante conserva el
     *                     nombre `con_faltantes` porque viaja en la URL y hay enlaces guardados.)
     */
    public const FILTRO_TODAS         = 'all';
    public const FILTRO_CON_FALTANTES = 'con_faltantes';

    /** Rótulo humano de cada pseudo-estado (dropdown del filtro). */
    public const FILTROS_META = [
        self::FILTRO_TODAS         => 'Todas',
        self::FILTRO_CON_FALTANTES => 'Con discrepancias',
    ];

    /**
     * Invalida el badge "por recibir" del menú (View Composer en AppServiceProvider,
     * cacheado por usuario con la versión en la clave). Vive en booted() —mismo
     * patrón que FrenteTrabajo— para que NINGUNA transición de estado futura
     * olvide el bump: cualquier alta/cambio/borrado puede alterar el conteo.
     */
    protected static function booted(): void
    {
        $bump = static fn () => \App\Support\CacheVersion::bump('traspasos_badge_ver');
        static::saved($bump);
        static::deleted($bump);
    }

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

    /**
     * Nombre del DESTINO tal como debe leerlo el usuario: el FRENTE al que se envió, si lo
     * hay; si no, el almacén. Mismo criterio que la columna Destino del kardex de salidas
     * (ver partials/kardex_rows.blade.php): el frente es el dato que dice a quién se le
     * entregó, y un almacén de PROYECTO puede servir a varios frentes — mostrar solo su
     * nombre hacía que notas a frentes distintos se vieran todas iguales.
     */
    public function getNombreDestinoAttribute(): string
    {
        $frente  = trim((string) (optional($this->frenteDestino)->NOMBRE_FRENTE ?? ''));
        $almacen = trim((string) (optional($this->almacenDestino)->NOMBRE ?? ''));
        return $frente !== '' ? $frente : ($almacen !== '' ? $almacen : '—');
    }

    /**
     * True cuando el nombre del frente destino no aporta nada sobre el del almacén (en
     * almacenes de PROYECTO suelen coincidir). Comparación tolerante a tildes, mayúsculas
     * y espacios repetidos. Fuente ÚNICA: la usan la bandeja de recepción y el modal de
     * detalle para no repetir el mismo nombre dos veces.
     */
    public function frenteDestinoEsRedundante(): bool
    {
        $frente = optional($this->frenteDestino)->NOMBRE_FRENTE;
        if (!$frente) return false;
        $norm = static fn ($s) => mb_strtoupper(trim(preg_replace('/\s+/', ' ', \Illuminate\Support\Str::ascii((string) $s))));
        return $norm($frente) === $norm(optional($this->almacenDestino)->NOMBRE ?? '');
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

    // Sin scopeEstado(): nadie lo usaba. Los filtros por estado se escriben con
    // where('ESTADO', ...) / whereIn directo en TraspasoController y en el View Composer.

    // ── Helpers ──────────────────────────────────────────────────

    public function esBorrador(): bool   { return $this->ESTADO === self::ESTADO_BORRADOR; }
    public function esEnviado(): bool    { return $this->ESTADO === self::ESTADO_ENVIADO; }
    public function esRecibido(): bool   { return in_array($this->ESTADO, self::ESTADOS_RECIBIDOS, true); }
    public function esCancelado(): bool  { return $this->ESTADO === self::ESTADO_CANCELADO; }
    public function esFinal(): bool      { return in_array($this->ESTADO, self::ESTADOS_FINALES, true); }

    /**
     * ¿Se le puede (seguir) confirmando mercancía?
     *
     * ENVIADO es la primera confirmación. RECIBIDO_PARCIAL significa que quedaron líneas SIN
     * confirmar y la nota SIGUE EN LA BANDEJA esperando que se completen (ver ESTADOS_BANDEJA_DEFAULT
     * y el mensaje de TraspasoController::recibir). Sin incluirlo aquí, el mismo estado que la deja
     * en la bandeja era el que impedía volver a recibirla: el modal se abría en solo lectura
     * ($puedeRecibir) y el POST respondía 422 — la nota quedaba atascada para siempre y el material
     * pendiente no se podía registrar nunca.
     *
     * Punto ÚNICO de la regla: lo usan TraspasoService::recibir (guarda del POST) y
     * TraspasoController ($puedeRecibir, que decide si la vista pinta los inputs).
     */
    public function puedeRecibirse(): bool
    {
        return in_array($this->ESTADO, self::ESTADOS_RECIBIBLES, true);
    }

    /**
     * Genera el siguiente folio (TR-YYYY-NNNN). Debe llamarse dentro de la misma
     * transacción que crea el registro para evitar choques en el índice único.
     * El año va embebido en el folio porque el contador NO se reinicia anualmente
     * (es global) — el año solo indica la cohorte, así nunca chocan.
     *
     * El withTrashed() de abajo NO significa que el módulo tenga papelera: eliminar un
     * borrador borra la fila de verdad (TraspasoController@destroy usa forceDelete). Se
     * mantiene como red de seguridad — si alguna vez quedara una fila con deleted_at, su
     * NUMERO sigue ocupando el UNIQUE y reusarlo reventaría el INSERT.
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
