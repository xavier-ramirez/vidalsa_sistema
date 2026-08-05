<?php

namespace App\Models;

use App\Casts\MojibakeFix;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Almacén / depósito de inventario.
 *
 *  - TIPO = GENERAL  → almacén PRINCIPAL (uno o dos): surte a los secundarios vía traspasos.
 *  - TIPO = PROYECTO → almacén SECUNDARIO de obra/tienda.
 *  AMBOS se ligan a frentes de trabajo vía el pivote `almacen_frentes`.
 *
 * VISIBILIDAD: el TIPO ya NO restringe (ver visiblePara/visiblesPara). Depende SOLO de
 * Usuario::NIVEL_ACCESO_ALMACEN + los frentes compartidos:
 *   1 = GLOBAL → ve TODOS los almacenes (salvo los ligados en exclusiva a frentes bloqueados).
 *   2 = LOCAL  → ve cualquier almacén —GENERAL o PROYECTO— que comparta al menos un frente
 *                con él. Es decir, un LOCAL SÍ puede ver un almacén GENERAL si comparten frente.
 */
class Almacen extends Model
{
    /**
     * Snapshot offline: este modelo forma parte del dominio "catalogos", asi que al
     * escribirlo hay que marcar esa version como obsoleta para que los clientes
     * que SI cachean ese dominio se traigan el cambio. Los que no lo cachean ni
     * se enteran, que es justo el objetivo de separar las versiones.
     */
    protected static function booted(): void
    {
        $marcar = static fn () => \App\Support\OfflineVersion::invalidar('catalogos');
        static::saved($marcar);

        // Al BORRAR un almacén no basta con marcar el catálogo: su stock y sus
        // movimientos salen del alcance del cliente sin que ninguna de esas dos tablas
        // se haya tocado, así que un delta no vería nada que enviar y el teléfono se
        // quedaría con las existencias de un almacén que ya no existe. Reseteo → copia
        // completa del dominio. Es un evento raro, el coste no importa.
        static::deleted(static function () use ($marcar) {
            $marcar();
            \App\Support\OfflineVersion::resetear('almacen');
        });
    }

    use SoftDeletes;

    protected $table      = 'almacenes';
    protected $primaryKey = 'ID_ALMACEN';

    public const TIPO_GENERAL  = 'GENERAL';
    public const TIPO_PROYECTO = 'PROYECTO';

    protected $fillable = [
        'CODIGO',
        'NOMBRE',
        'TIPO',
        'UBICACION',
        'ALMACENISTA',
        'CARGO_ALMACENISTA',
        'ESTATUS',
        'NOTAS',
        'CREADO_POR',
    ];

    /**
     * Auto-decode mojibake (UTF-8 doble-encoded) en los campos de texto que pueden
     * traer datos legacy mal encodeados. Aplica solo si detecta el patron; strings
     * limpios se devuelven intactos. Asi cualquier vista, PDF o JSON ve el texto
     * correcto sin tener que llamar a un helper manualmente.
     */
    protected $casts = [
        'NOMBRE'            => MojibakeFix::class,
        'UBICACION'         => MojibakeFix::class,
        'ALMACENISTA'       => MojibakeFix::class,
        'CARGO_ALMACENISTA' => MojibakeFix::class,
        'NOTAS'             => MojibakeFix::class,
    ];

    // ── Relaciones ───────────────────────────────────────────────

    /** Frentes de trabajo (proyectos) servidos por este almacén. */
    public function frentes()
    {
        return $this->belongsToMany(
            FrenteTrabajo::class,
            'almacen_frentes',
            'ID_ALMACEN',
            'ID_FRENTE'
        )->withTimestamps();
    }

    /** Existencias (una fila por producto y proyecto) en este almacén. */
    public function stock()
    {
        return $this->hasMany(AlmacenStock::class, 'ID_ALMACEN', 'ID_ALMACEN');
    }

    /**
     * ¿Este almacén lleva el saldo SEPARADO POR PROYECTO?
     *
     * Solo lo hacen los almacenes de PROYECTO que sirven a más de un frente: es el caso
     * de PATIO EL TIGRE, donde el material de 6 proyectos se sumaba en un único número.
     * Un almacén con un solo frente (o GENERAL, como BARCELONA) no tiene nada que
     * separar y opera SIEMPRE contra la bolsa común (ID_FRENTE = 0), así que su
     * comportamiento es idéntico al de antes de existir esta columna.
     *
     * Es el ÚNICO interruptor de la separación: lo consultan InventarioService (para
     * decidir a qué saldo aplica cada movimiento) y AlmacenController (para saber si
     * debe ofrecer el selector de proyecto). Si se decidiera separar también los
     * mono-frente, se cambia aquí y no en cada sitio.
     *
     * Ojo: usa la relación ya cargada si existe (`with('frentes')`), y solo cuenta
     * contra la BD cuando no lo está — este método se llama por cada movimiento de un
     * lote y una consulta por línea se nota.
     */
    public function separaPorProyecto(): bool
    {
        if ($this->TIPO !== self::TIPO_PROYECTO) {
            return false;
        }

        return $this->relationLoaded('frentes')
            ? $this->frentes->count() > 1
            : $this->frentes()->count() > 1;
    }

    /** Movimientos (kardex) de este almacén. */
    public function movimientos()
    {
        return $this->hasMany(MovimientoInventario::class, 'ID_ALMACEN', 'ID_ALMACEN');
    }

    public function creadoPor()
    {
        return $this->belongsTo(Usuario::class, 'CREADO_POR', 'ID_USUARIO');
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeActivos(Builder $q): Builder
    {
        return $q->where('ESTATUS', 'ACTIVO');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * True si el usuario tiene acceso "global" (ve todos los almacenes).
     *
     * Decisión de producto: la visibilidad de almacenes depende ÚNICAMENTE de
     * `usuarios.NIVEL_ACCESO_ALMACEN` (1 = GLOBAL, 2 = LOCAL). NO depende del rol ni
     * de permisos como `super.admin` o `almacen.view.all` — un super.admin con
     * NIVEL_ACCESO_ALMACEN=2 debe ver solo los almacenes de sus frentes asignados,
     * igual que cualquier usuario LOCAL.
     *
     * Es un nivel INDEPENDIENTE del de equipos (NIVEL_ACCESO_EQUIPOS): un usuario
     * puede ver todos los equipos y a la vez solo su almacén.
     */
    public static function usuarioEsGlobal($user): bool
    {
        if (!$user) {
            return false;
        }
        // Criterio ÚNICO centralizado en Usuario::veTodosLosAlmacenes.
        return method_exists($user, 'veTodosLosAlmacenes')
            ? $user->veTodosLosAlmacenes()
            : (int) ($user->NIVEL_ACCESO_ALMACEN ?? 0) === 1;
    }

    /**
     * IDs (int) de los frentes asignados a un usuario.
     * El PARSEO del CSV vive en UN solo lugar (Usuario::getFrentesIds); aquí solo
     * delegamos y normalizamos a int para las queries por el pivote almacen_frentes
     * (ID_FRENTE es entero). Antes este método re-parseaba el CSV → duplicaba la lógica.
     */
    public static function frenteIdsDe($user): array
    {
        if (!$user || !method_exists($user, 'getFrentesIds')) {
            return [];
        }
        return self::normalizarIdsFrente($user->getFrentesIds());
    }

    /** IDs (int) de los frentes BLOQUEADOS de un usuario (lista negra); espejo de frenteIdsDe. */
    public static function frentesBloqueadosDe($user): array
    {
        if (!$user || !method_exists($user, 'getFrentesBloqueadosIds')) {
            return [];
        }
        return self::normalizarIdsFrente($user->getFrentesBloqueadosIds());
    }

    /** Normaliza una lista de IDs de frente a enteros únicos (sin vacíos/null). Punto ÚNICO. */
    private static function normalizarIdsFrente(array $ids): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_filter($ids, fn ($v) => $v !== '' && $v !== null)
        )));
    }

    /**
     * Almacenes que un usuario puede CONSULTAR.
     *
     *  - GLOBAL (NIVEL_ACCESO_ALMACEN=1):
     *      ve TODOS los almacenes (principales GENERAL + secundarios PROYECTO).
     *      Aún así, el módulo abre preseleccionado en el almacén ligado a su
     *      frente (ver Usuario::almacenPorDefecto + AlmacenController) — pero
     *      puede cambiar el filtro y ver los demás.
     *  - LOCAL (NIVEL_ACCESO_ALMACEN=2):
     *      ve los almacenes (GENERAL o PROYECTO) ligados a alguno de sus frentes
     *      asignados — los que comparten frente con el usuario.
     *  - Sin usuario o sin frentes asignados (siendo LOCAL) → no ve ningún almacén.
     *
     * NOTA: la visibilidad NO depende de roles ni permisos (super.admin /
     * almacen.view.all). Depende de `usuarios.NIVEL_ACCESO_ALMACEN` + los frentes asignados.
     *
     * @param  \App\Models\Usuario|null  $user
     */
    public static function visiblesPara($user): Builder
    {
        $q = static::query()->activos();
        $bloqueados = self::frentesBloqueadosDe($user);

        if (self::usuarioEsGlobal($user)) {
            // GLOBAL: ve todos MENOS los almacenes ligados EXCLUSIVAMENTE a frentes
            // bloqueados. Un almacén que además sirve a un frente NO bloqueado (o que no
            // tiene frentes, p.ej. GENERAL) sigue visible. El where(closure) agrupa el
            // OR para que no rompa el AND con activos().
            if (!empty($bloqueados)) {
                $q->where(fn (Builder $w) => $w
                    ->whereDoesntHave('frentes', fn (Builder $f) => $f->whereIn('frentes_trabajo.ID_FRENTE', $bloqueados))
                    ->orWhereHas('frentes', fn (Builder $f) => $f->whereNotIn('frentes_trabajo.ID_FRENTE', $bloqueados)));
            }
            return $q;
        }

        // LOCAL: solo sus frentes asignados, restando los bloqueados.
        $frenteIds = array_values(array_diff(self::frenteIdsDe($user), $bloqueados));
        if (empty($frenteIds)) {
            return $q->whereRaw('1 = 0'); // sin acceso: builder vacío
        }

        // Cualquier almacén (GENERAL o PROYECTO) asociado a alguno de los frentes del
        // usuario. Antes se exigía TIPO=PROYECTO — incoherente con almacenPorDefecto,
        // que ya consideraba un frente con almacén GENERAL asociado.
        return $q->whereHas('frentes', fn (Builder $f) => $f->whereIn('frentes_trabajo.ID_FRENTE', $frenteIds));
    }

    /**
     * IDs de los almacenes ASOCIADOS a un usuario = los ligados a SUS frentes asignados
     * (menos los bloqueados). Es la "propiedad" REAL del usuario sobre almacenes — a
     * diferencia de visiblesPara(), que para un GLOBAL/admin devuelve TODOS.
     *
     * Lo usan los avisos de "por recibir" de Recepción (badge del nav en
     * AppServiceProvider y widget de notas pendientes en AlmacenController::index):
     * la recepción es PERSONAL — solo cuentas las notas destinadas a TU almacén, no a
     * todos. Así un usuario que solo EMITE (origen) y no recibe ve 0; uno que recibe ve
     * lo suyo. Mismo criterio frente-linked que la rama LOCAL de visiblesPara() y
     * Usuario::almacenPorDefecto(). Devuelve Collection vacía si el usuario no tiene frentes.
     */
    public static function asociadosIdsDe($user): \Illuminate\Support\Collection
    {
        $frenteIds = array_values(array_diff(self::frenteIdsDe($user), self::frentesBloqueadosDe($user)));
        if (empty($frenteIds)) {
            return collect();
        }
        return static::query()
            ->where('ESTATUS', 'ACTIVO')
            ->whereHas('frentes', fn (Builder $f) => $f->whereIn('frentes_trabajo.ID_FRENTE', $frenteIds))
            ->pluck('ID_ALMACEN');
    }

    /** True si $user puede ver/operar sobre este almacén concreto. */
    public function visiblePara($user): bool
    {
        // El TIPO ya no restringe: GENERAL o PROYECTO, basta compartir un frente.
        if ($this->ESTATUS !== 'ACTIVO') {
            return false;
        }
        $bloqueados = self::frentesBloqueadosDe($user);

        if (self::usuarioEsGlobal($user)) {
            // GLOBAL ve todos salvo los ligados EXCLUSIVAMENTE a frentes bloqueados
            // (coherente con visiblesPara): visible si tiene un frente no bloqueado o
            // ningún frente bloqueado.
            if (empty($bloqueados)) {
                return true;
            }
            $tieneNoBloqueado = $this->frentes()->whereNotIn('frentes_trabajo.ID_FRENTE', $bloqueados)->exists();
            $tieneBloqueado   = $this->frentes()->whereIn('frentes_trabajo.ID_FRENTE', $bloqueados)->exists();
            return $tieneNoBloqueado || !$tieneBloqueado;
        }

        // LOCAL: comparte alguno de sus frentes asignados NO bloqueados.
        $frenteIds = array_values(array_diff(self::frenteIdsDe($user), $bloqueados));
        if (empty($frenteIds)) {
            return false;
        }
        return $this->frentes()->whereIn('frentes_trabajo.ID_FRENTE', $frenteIds)->exists();
    }

    /**
     * Helper para los controllers: carga el almacén por ID y aborta con 404 si
     * no existe o 403 si el usuario no puede verlo. $rolHumano se inyecta en el
     * mensaje del 403/404 ("origen" / "destino" / null = sin sufijo) para que
     * los traspasos puedan distinguir cuál de los dos almacenes falló.
     *
     * Centraliza el guard que antes vivía duplicado en AlmacenController y en
     * TraspasoController::assertPuedeOperar(Origen|Destino|...). Single source
     * of truth para "¿este usuario tiene derecho a tocar este almacén?".
     */
    public static function assertVisibleOrFail($user, int $idAlmacen, ?string $rolHumano = null): self
    {
        $sufijo = $rolHumano ? " {$rolHumano}" : '';
        $almacen = self::find($idAlmacen);
        abort_unless($almacen !== null, 404, "Almacén{$sufijo} no encontrado.");
        abort_unless($almacen->visiblePara($user), 403, "No tienes acceso a este almacén{$sufijo}.");
        return $almacen;
    }
}
