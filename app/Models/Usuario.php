<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;


    protected $table = 'usuarios';
    protected $primaryKey = 'ID_USUARIO';
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    /**
     * Campos seguros para mass-assignment (perfil / autoservicio).
     * Los campos sensibles (ID_ROL, NIVEL_ACCESO, PERMISOS, ESTATUS, SESSION_TOKEN,
     * ID_FRENTE_ASIGNADO, REQUIERE_CAMBIO_CLAVE) NO van aqui — se asignan
     * explicitamente desde UserController bajo `can:manage.users` para evitar
     * escalacion de privilegios via $user->fill($request->all()).
     */
    protected $fillable = [
        'NOMBRE_COMPLETO',
        'CORREO_ELECTRONICO',
        'PASSWORD_HASH',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'PASSWORD_HASH',
        'SESSION_TOKEN',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        // 'PERMISOS' => 'array', // Comentado para usar accessor/mutator para columna SET
    ];

    /**
     * Get the permissions as an array.
     */
    public function getPermisosAttribute($value)
    {
        return $value ? explode(',', $value) : [];
    }

    /**
     * Set the permissions from an array to a comma-separated string.
     */
    public function setPermisosAttribute($value)
    {
        $this->attributes['PERMISOS'] = is_array($value) ? implode(',', $value) : $value;
    }

    /**
     * Get the frentes IDs as an array.
     */
    public function getIdFrenteAsignadoAttribute($value)
    {
        if (!$value) return null;
        // Devolver el raw string para que ->ID_FRENTE_ASIGNADO siga funcionando en código legado
        return $value;
    }

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword()
    {
        return $this->PASSWORD_HASH;
    }

    public function rol()
    {
        return $this->belongsTo(Role::class, 'ID_ROL', 'ID_ROL');
    }

    public function frenteAsignado()
    {
        // Para compatibilidad hacia atrás: devuelve el primer frente asignado
        $ids = $this->getFrentesIds();
        $firstId = $ids[0] ?? null;
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_ASIGNADO', 'ID_FRENTE')
                    ->whereKey($firstId);
    }

    /**
     * Devuelve todos los frentes asignados al usuario como colección.
     */
    public function frentesAsignados()
    {
        $ids = $this->getFrentesIds();
        return FrenteTrabajo::whereIn('ID_FRENTE', $ids)->get();
    }

    /**
     * Devuelve el array de IDs de frentes asignados.
     */
    public function getFrentesIds(): array
    {
        $raw = $this->attributes['ID_FRENTE_ASIGNADO'] ?? null;
        if (!$raw) return [];
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Resuelve el almacén "natural" del usuario para los módulos de Almacén.
     *
     * Convención: el primer almacén PROYECTO ligado a CUALQUIERA de los frentes
     * asignados al usuario (orden por NOMBRE asc — determinístico). Si el usuario
     * no tiene frentes asignados o ninguno de ellos tiene almacén PROYECTO, retorna
     * `null` y los controllers caen al comportamiento por defecto (ver todos).
     *
     * Lo usan AlmacenController::index/movimientos y TraspasoController::index
     * para preseleccionar el filtro de almacén al abrir cada módulo.
     */
    public function almacenPorDefecto(): ?int
    {
        $frentes = $this->getFrentesIds();
        if (empty($frentes)) return null;

        return \App\Models\Almacen::query()
            ->where('TIPO', 'PROYECTO')
            ->where('ESTATUS', 'ACTIVO')
            ->whereHas('frentes', fn ($q) => $q->whereIn('frentes_trabajo.ID_FRENTE', $frentes))
            ->orderBy('NOMBRE')
            ->value('ID_ALMACEN');
    }

    /**
     * Get the access level as descriptive text.
     *
     * @return string
     */
    public function getNivelAccesoTextoAttribute()
    {
        $niveles = [
            1 => 'GLOBAL',
            2 => 'LOCAL'
        ];
        
        return $niveles[$this->NIVEL_ACCESO] ?? 'Desconocido';
    }
    /**
     * Determine if the entity has the given abilities.
     *
     * @param  iterable|string  $abilities
     * @param  array|mixed  $arguments
     * @return bool
     */
    public function can($abilities, $arguments = []): bool
    {
        // manage.users SIEMPRE delega al Gate (requiere clave + rol en Gate::before)
        // Si se resuelve aquí con el shortcut de super.admin, el check de rol se pasa por alto.
        if (is_string($abilities) && $abilities === 'manage.users') {
            return parent::can($abilities, $arguments);
        }

        // Sistema basado ÚNICAMENTE en claves (columna PERMISOS).
        // El ROL no otorga acceso automático. Solo la clave 'super.admin' da acceso total,
        // CON EXCEPCIONES (ver $OPERACIONES_EXPLICITAS abajo).
        if (is_string($abilities)) {
            $permisosRaw = $this->PERMISOS ?? [];
            $permisos = array_map('strtolower', $permisosRaw);
            $ability = strtolower($abilities);

            // Operaciones que NO se conceden por la regla maestra de super.admin: el
            // usuario debe tener la clave explicita correspondiente. Decision del cliente
            // — separar la administracion del sistema (super.admin) de la operacion
            // diaria de almacen (movimientos, productos). Un super.admin sin
            // `almacen.movimiento` no puede registrar entradas/salidas; sin
            // `almacen.productos` no puede tocar el catalogo. Asi se evita que un super
            // tecnico (que cubre TI / usuarios / equipos / almacenes) pueda accidentalmente
            // mover stock o editar el catalogo sin que se lo asignen formalmente.
            static $OPERACIONES_EXPLICITAS = [
                'almacen.movimiento' => true,
                'almacen.productos'  => true,
            ];

            // REGLA MAESTRA: clave super.admin explícita = acceso total, EXCEPTO las
            // operaciones listadas arriba (que requieren clave especifica).
            if (! isset($OPERACIONES_EXPLICITAS[$ability]) && in_array('super.admin', $permisos)) {
                return true;
            }

            // Verificación del permiso específico solicitado
            if (in_array($ability, $permisos)) {
                return true;
            }

            // ── Alias de back-compat ──
            // Modelo final del picker:
            //   super.admin · almacen.productos · almacen.movimiento
            //
            // Claves viejas que ya no aparecen en el picker pero pueden estar guardadas
            // en la columna PERMISOS de usuarios creados antes de la consolidacion. Las
            // mapeamos al nuevo modelo para no perder accesos hasta que un admin los
            // re-asigne explicitamente.
            //
            // Forward (clave nueva pedida → clave vieja en PERMISOS): cuando el codigo
            // ahora chequea la clave nueva (ej. `can('almacen.productos')`), tambien
            // pasamos si el usuario tenia la clave amplia vieja correspondiente
            // (ej. `almacen.manage`).
            static $ALIASES_FORWARD = [
                // CRUD productos: usuarios con la vieja `almacen.manage` siguen pudiendo
                // operar el catalogo de productos sin re-asignacion.
                'almacen.productos'  => 'almacen.manage',
            ];
            if (isset($ALIASES_FORWARD[$ability]) && in_array($ALIASES_FORWARD[$ability], $permisos)) {
                return true;
            }

            // Reverse (clave vieja pedida → clave nueva en PERMISOS): cubre codigo
            // legacy / vistas / extensiones que aun llamen a `can('traspaso.recibir')`
            // o `can('almacen.salidas_recepciones')` por nombre. Si el usuario tiene
            // la clave consolidada `almacen.movimiento`, los pasamos.
            if (in_array($ability, ['traspaso.recibir', 'almacen.salidas_recepciones'], true)
                && in_array('almacen.movimiento', $permisos)
            ) {
                return true;
            }
            // Reverse para usuarios con CLAVES VIEJAS en PERMISOS que ahora se chequea
            // por la clave consolidada `almacen.movimiento`. Si el usuario aun tiene
            // `traspaso.recibir` o `almacen.salidas_recepciones` guardadas, los
            // consideramos equivalentes a `almacen.movimiento`.
            if ($ability === 'almacen.movimiento'
                && (in_array('traspaso.recibir', $permisos) || in_array('almacen.salidas_recepciones', $permisos))
            ) {
                return true;
            }
        }

        // Delegar el resto al framework (para Gates/Policies estándar si se usan)
        return parent::can($abilities, $arguments);
    }
}
