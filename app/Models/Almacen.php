<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Almacén / depósito de inventario.
 *
 *  - TIPO = GENERAL  → almacén PRINCIPAL (uno o dos): surte a los secundarios vía
 *                      traspasos. Solo visible para usuarios GLOBAL (NIVEL_ACCESO=1).
 *  - TIPO = PROYECTO → almacén SECUNDARIO de obra/tienda: ligado a uno o varios
 *                      frentes de trabajo vía el pivote `almacen_frentes`. Visible
 *                      para los usuarios LOCAL (NIVEL_ACCESO=2) de esos frentes —
 *                      que NO ven los almacenes GENERAL.
 *
 * Reusa el modelo "global vs local" del sistema (Usuario::NIVEL_ACCESO):
 *   1 = GLOBAL → ve todos los almacenes;  2 = LOCAL → solo los de sus frentes.
 */
class Almacen extends Model
{
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
        'ESTATUS',
        'NOTAS',
        'CREADO_POR',
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

    /** Existencias (una fila por producto) en este almacén. */
    public function stock()
    {
        return $this->hasMany(AlmacenStock::class, 'ID_ALMACEN', 'ID_ALMACEN');
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

    /** True si el usuario tiene acceso "global" (ve todos los almacenes). */
    public static function usuarioEsGlobal($user): bool
    {
        if (!$user) {
            return false;
        }
        if ((int) ($user->NIVEL_ACCESO ?? 0) === 1) {
            return true;
        }
        // super.admin y el permiso explícito 'almacen.view.all' también dan acceso total.
        return \Illuminate\Support\Facades\Gate::forUser($user)->allows('super.admin')
            || \Illuminate\Support\Facades\Gate::forUser($user)->allows('almacen.view.all');
    }

    /** IDs (int) de los frentes asignados a un usuario; robusto al formato. */
    public static function frenteIdsDe($user): array
    {
        if (!$user) {
            return [];
        }
        $ids = method_exists($user, 'getFrentesIds') ? $user->getFrentesIds() : [];
        if (empty($ids)) {
            $raw = $user->ID_FRENTE_ASIGNADO ?? null;
            if (is_array($raw)) {
                $ids = $raw;
            } elseif (is_string($raw) && $raw !== '') {
                $ids = array_filter(array_map('trim', explode(',', $raw)));
            }
        }
        return array_values(array_unique(array_map(
            'intval',
            array_filter((array) $ids, fn ($v) => $v !== '' && $v !== null)
        )));
    }

    /**
     * Almacenes que un usuario puede CONSULTAR.
     *
     *  - GLOBAL (NIVEL_ACCESO=1) / super.admin / 'almacen.view.all':
     *      ve TODOS los almacenes (principales GENERAL + secundarios PROYECTO).
     *  - LOCAL (NIVEL_ACCESO=2):
     *      ve ÚNICAMENTE los almacenes PROYECTO ligados a sus frentes asignados.
     *      NUNCA ve los almacenes GENERAL (los frentes no deben ver el stock del
     *      almacén global).
     *  - Sin usuario o sin frentes asignados → no ve ningún almacén.
     *
     * @param  \App\Models\Usuario|null  $user
     */
    public static function visiblesPara($user): Builder
    {
        $q = static::query()->activos();

        if (self::usuarioEsGlobal($user)) {
            return $q;
        }

        $frenteIds = self::frenteIdsDe($user);
        if (empty($frenteIds)) {
            return $q->whereRaw('1 = 0'); // sin acceso: builder vacío
        }

        return $q->where('TIPO', self::TIPO_PROYECTO)
            ->whereHas('frentes', fn (Builder $f) => $f->whereIn('frentes_trabajo.ID_FRENTE', $frenteIds));
    }

    /** True si $user puede ver/operar sobre este almacén concreto. */
    public function visiblePara($user): bool
    {
        if (self::usuarioEsGlobal($user)) {
            return $this->ESTATUS === 'ACTIVO';
        }
        if ($this->TIPO !== self::TIPO_PROYECTO || $this->ESTATUS !== 'ACTIVO') {
            return false;
        }
        $frenteIds = self::frenteIdsDe($user);
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
