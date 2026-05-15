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
        // El ROL no otorga acceso automático. Solo la clave 'super.admin' da acceso total.
        if (is_string($abilities)) {
            $permisosRaw = $this->PERMISOS ?? [];
            $permisos = array_map('strtolower', $permisosRaw);
            $ability = strtolower($abilities);

            // REGLA MAESTRA: clave super.admin explícita = acceso total
            if (in_array('super.admin', $permisos)) {
                return true;
            }

            // Verificación del permiso específico solicitado
            if (in_array($ability, $permisos)) {
                return true;
            }
        }

        // Delegar el resto al framework (para Gates/Policies estándar si se usan)
        return parent::can($abilities, $arguments);
    }
}
