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
    // Timestamps ACTIVOS: al crear un usuario se guarda created_at (fecha de
    // registro) y updated_at automáticamente. La tabla ya tiene ambas columnas
    // (migración con timestamps()). Eloquent además los castea a Carbon, por lo
    // que created_at->format(...) funciona en la lista/tooltip.
    public $timestamps = true;

    /**
     * Claves que NO se conceden por la regla maestra super.admin — el usuario
     * debe tener la clave LITERAL en PERMISOS. Es la UNICA fuente de verdad
     * de las exclusiones; Usuario::can() y AppServiceProvider::Gate::before
     * la consultan para mantenerse coherentes.
     *
     * Claves del modulo Almacen — EXCLUSIVAS (decision del cliente):
     * el acceso al almacen NO se hereda por ser super.admin ni depende del ROL
     * — se concede SOLO con la clave literal en PERMISOS.
     *  - almacen.productos : editar el catalogo (CODIGO/NOMBRE/UM/categoria/
     *    ubicacion) impacta historial, kardex y reportes.
     *  - almacen.movimiento: registrar entradas/salidas/ajustes/traspasos y
     *    confirmar recepciones mueve el stock real.
     *  - almacen.nota.eliminar: eliminar Notas de Entrega (reversa el stock) y
     *    eliminar productos del catalogo — borrados destructivos.
     *
     * user.delete (agregada 2026-05-26): elimina equipos (vehiculos + auxiliares)
     * y da acceso a las papeleras del historial. EXCLUSIVA por decision del
     * cliente: ni super.admin ni el ROL la heredan — exige la clave LITERAL
     * en PERMISOS. La accion es destructiva (soft-delete masivo) y se queja
     * registrada en equipo_audit_log con deleted_by.
     *
     * Consecuencia: un super.admin que deba eliminar equipos o tocar el almacen
     * necesita la clave literal correspondiente agregada en su columna PERMISOS.
     */
    public const PERMISOS_EXPLICITOS = [
        'almacen.productos'     => true,
        'almacen.movimiento'    => true,
        'almacen.nota.eliminar' => true,
        'user.delete'           => true,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    /**
     * Campos seguros para mass-assignment (perfil / autoservicio).
     * Los campos sensibles (ID_ROL, NIVEL_ACCESO_EQUIPOS, NIVEL_ACCESO_ALMACEN,
     * PERMISOS, ESTATUS, SESSION_TOKEN,
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
     * Devuelve el array de IDs de frentes asignados.
     */
    public function getFrentesIds(): array
    {
        $raw = $this->attributes['ID_FRENTE_ASIGNADO'] ?? null;
        if (!$raw) return [];
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Nombres de TODOS los frentes asignados. `frenteAsignado` solo devuelve el
     * primero del CSV; esto lista los demás (p. ej. para el tooltip de la lista).
     * [] = sin frente asignado (GLOBAL). El mapa id→nombre se cachea una sola vez
     * por request para no disparar N+1 en la lista paginada.
     */
    public function getNombresFrentesAsignados(): array
    {
        $ids = $this->getFrentesIds();
        if (empty($ids)) return [];

        static $mapa = null;
        if ($mapa === null) {
            $mapa = FrenteTrabajo::pluck('NOMBRE_FRENTE', 'ID_FRENTE')->all();
        }
        return array_values(array_filter(array_map(fn ($id) => $mapa[$id] ?? null, $ids)));
    }

    /**
     * IDs de frentes BLOQUEADOS para el usuario (lista negra, CSV en ID_FRENTE_BLOQUEADO).
     * Se RESTAN de la visibilidad SIN importar GLOBAL/LOCAL: un GLOBAL ve todos los frentes
     * MENOS estos. Es el camino cómodo para "ve casi todo salvo unos pocos" (tildas lo
     * prohibido en vez de tildar muchísimos asignados como LOCAL). [] = no bloquea nada.
     */
    public function getFrentesBloqueadosIds(): array
    {
        $raw = $this->attributes['ID_FRENTE_BLOQUEADO'] ?? null;
        if (!$raw) return [];
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * ¿El usuario ve los EQUIPOS de todos los frentes (sin barrera)?
     * GLOBAL = NIVEL_ACCESO_EQUIPOS 1; cualquier otro valor (2/LOCAL, null o inválido)
     * → restringido. Gobierna Equipos, Auxiliares, Movilizaciones, Historial y Dashboard.
     *
     * Decisión de producto (ver también App\Models\Almacen::usuarioEsGlobal): la
     * visibilidad depende SOLO del nivel, NO del rol ni de super.admin — un
     * super.admin LOCAL se restringe igual que cualquier LOCAL. Por eso acá NO hay
     * override de super.admin.
     *
     * Este método y veTodosLosAlmacenes() son los DOS criterios canónicos. Antes había
     * uno solo (veTodosLosFrentes, sobre la columna NIVEL_ACCESO), que forzaba a mover
     * ambos módulos a la vez; se separó en dos columnas. No compares el entero a mano
     * en un controller o una vista: usa estos métodos, o vuelve la incoherencia que la
     * unificación original vino a arreglar (Almacén comparaba "==1" y Equipos "==2").
     */
    public function veTodosLosFrentesEquipos(): bool
    {
        return (int) ($this->NIVEL_ACCESO_EQUIPOS ?? 0) === 1;
    }

    /**
     * ¿El usuario ve TODOS los almacenes? Espejo exacto de veTodosLosFrentesEquipos()
     * sobre NIVEL_ACCESO_ALMACEN. Gobierna Almacén, Traspasos/Recepción y el snapshot
     * offline de almacén. LOCAL → solo los almacenes ligados a sus frentes asignados
     * (ver Almacen::visiblesPara).
     */
    public function veTodosLosAlmacenes(): bool
    {
        return (int) ($this->NIVEL_ACCESO_ALMACEN ?? 0) === 1;
    }

    /**
     * IDs de frentes cuyos EQUIPOS puede ver el usuario. Convención:
     *   - null  → SIN restricción (ve todos): usuario GLOBAL en equipos.
     *   - []    → no ve ninguno (LOCAL sin frentes asignados).
     *   - [ids] → restringido a esos frentes (LOCAL con asignados).
     *
     * OJO: esto es SOLO la lista blanca (nivel GLOBAL/LOCAL). La lista negra de
     * bloqueados es independiente y se aplica aparte (getFrentesBloqueadosIds /
     * aplicarBloqueoIds). El punto que combina ambas es aplicarScopeFrentesEquipos().
     */
    public function frentesVisiblesEquiposIds(): ?array
    {
        if ($this->veTodosLosFrentesEquipos()) {
            return null;
        }
        return $this->getFrentesIds();
    }

    /**
     * Barrera COMPLETA de visibilidad por frente para EQUIPOS sobre $query/$columna:
     *   1) lista blanca por nivel (whereIn asignados si LOCAL; nada si GLOBAL), y
     *   2) lista negra de bloqueados (whereNotIn), que aplica TAMBIÉN a GLOBAL.
     * Es el punto ÚNICO recomendado — centraliza el patrón que estaba duplicado en
     * los controladores. Devuelve el $query para encadenar.
     */
    public function aplicarScopeFrentesEquipos($query, string $columna = 'ID_FRENTE_ACTUAL')
    {
        static::aplicarScopeIds($query, $this->frentesVisiblesEquiposIds(), $columna);
        return static::aplicarBloqueoIds($query, $this->getFrentesBloqueadosIds(), $columna);
    }

    /**
     * Barrera por frente para superficies COMPARTIDAS por los dos módulos, donde una
     * sola lista de frentes alimenta a equipos Y a almacén (hoy: el dropdown de frentes
     * del snapshot offline).
     *
     * Usa la UNIÓN de ambos scopes: basta con ser GLOBAL en UNO de los dos módulos para
     * ver todos los frentes en esa lista. Acotarla con un solo nivel escondería frentes
     * que el otro módulo sí necesita (un GLOBAL-equipos + LOCAL-almacén perdería frentes
     * de equipos si se filtrara por el nivel de almacén).
     *
     * La lista negra se resta igual, sin importar el nivel — un frente bloqueado no
     * aparece por ninguna vía.
     */
    public function aplicarScopeFrentesUnion($query, string $columna = 'ID_FRENTE_ACTUAL')
    {
        $ids = ($this->veTodosLosFrentesEquipos() || $this->veTodosLosAlmacenes())
            ? null                    // GLOBAL en algún módulo → sin lista blanca
            : $this->getFrentesIds(); // LOCAL en ambos → solo sus frentes

        static::aplicarScopeIds($query, $ids, $columna);
        return static::aplicarBloqueoIds($query, $this->getFrentesBloqueadosIds(), $columna);
    }

    /**
     * Aplica la convención de visibilidad (null = ve todo, [] = nada, [ids] = whereIn)
     * a $query sobre $columna, dado el set YA resuelto de frentes visibles. Útil cuando
     * el set se calculó una vez (con frentesVisiblesEquiposIds()) y se reusa. Punto ÚNICO del
     * idiom whereIn/whereRaw('1=0') que estaba duplicado por todos lados.
     */
    public static function aplicarScopeIds($query, ?array $ids, string $columna = 'ID_FRENTE_ACTUAL')
    {
        if ($ids === null) {
            return $query;                    // ve todo
        }
        if (count($ids) === 0) {
            return $query->whereRaw('1 = 0'); // local sin frentes → nada
        }
        return $query->whereIn($columna, $ids);
    }

    /**
     * RESTA la lista negra de frentes bloqueados a $query/$columna (whereNotIn). Es
     * el simétrico de aplicarScopeIds (lista blanca) y aplica SIN importar GLOBAL/LOCAL:
     * por eso se llama aparte y siempre. [] → no toca el query. Punto ÚNICO del idiom.
     */
    public static function aplicarBloqueoIds($query, array $bloqueados, string $columna = 'ID_FRENTE_ACTUAL')
    {
        if (count($bloqueados) > 0) {
            $query->whereNotIn($columna, $bloqueados);
        }
        return $query;
    }

    /**
     * Resuelve el almacén "natural" del usuario para los módulos de Almacén.
     *
     * Convención (en orden de preferencia, primer match gana):
     *   1) Almacén PROYECTO ligado a alguno de los frentes asignados al usuario.
     *      Es el caso típico: cada frente tiene SU almacén PROYECTO donde llega
     *      la mercadería de los traspasos.
     *   2) Fallback: cualquier almacén ACTIVO (PROYECTO o GENERAL) ligado a alguno
     *      de los frentes del usuario. Cubre el caso donde el frente solo tiene
     *      asignado un almacén GENERAL — antes retornaba null y la bandeja de
     *      recepción se abría con "Todos los almacenes destino" (pedido del cliente
     *      corregido: 2026-05-19).
     *
     * Si el usuario no tiene frentes O sus frentes no tienen NINGÚN almacén,
     * retorna `null` y los controllers caen al comportamiento por defecto.
     *
     * Lo usan AlmacenController::index/movimientos y TraspasoController::index/nuevaEntrada
     * para preseleccionar el filtro de almacén al abrir cada módulo.
     */
    public function almacenPorDefecto(): ?int
    {
        // Restamos los frentes BLOQUEADOS: el almacén por defecto NO puede caer en un
        // frente que el usuario no ve (si no, el módulo abriría preseleccionado en un
        // almacén que visiblesPara() oculta → incoherencia). Si todos sus frentes están
        // bloqueados, no hay default (coherente: tampoco ve almacenes).
        $frentes = array_values(array_diff($this->getFrentesIds(), $this->getFrentesBloqueadosIds()));
        if (empty($frentes)) return null;

        $almacenModel = \App\Models\Almacen::class;

        // 1) Preferir PROYECTO (es el destino natural de los traspasos).
        $proyecto = $almacenModel::query()
            ->where('TIPO', 'PROYECTO')
            ->where('ESTATUS', 'ACTIVO')
            ->whereHas('frentes', fn ($q) => $q->whereIn('frentes_trabajo.ID_FRENTE', $frentes))
            ->orderBy('NOMBRE')
            ->value('ID_ALMACEN');

        if ($proyecto !== null) return (int) $proyecto;

        // 2) Fallback: cualquier almacén ACTIVO ligado al frente (cubre frentes
        //    que solo tienen GENERAL asociado).
        $cualquiera = $almacenModel::query()
            ->where('ESTATUS', 'ACTIVO')
            ->whereHas('frentes', fn ($q) => $q->whereIn('frentes_trabajo.ID_FRENTE', $frentes))
            ->orderBy('NOMBRE')
            ->value('ID_ALMACEN');

        return $cualquiera !== null ? (int) $cualquiera : null;
    }

    /** Texto del nivel (1 GLOBAL / 2 LOCAL). Punto único de la etiqueta. */
    private function nivelTexto($valor): string
    {
        return [1 => 'GLOBAL', 2 => 'LOCAL'][(int) $valor] ?? 'Desconocido';
    }

    /** Nivel de acceso a EQUIPOS como texto (para vistas/listados). */
    public function getNivelAccesoEquiposTextoAttribute(): string
    {
        return $this->nivelTexto($this->NIVEL_ACCESO_EQUIPOS);
    }

    /** Nivel de acceso a ALMACÉN como texto (para vistas/listados). */
    public function getNivelAccesoAlmacenTextoAttribute(): string
    {
        return $this->nivelTexto($this->NIVEL_ACCESO_ALMACEN);
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
        // manage.users se delega SIEMPRE al Gate; Gate::before lo resuelve
        // ÚNICAMENTE con la clave 'super.admin' en PERMISOS — sin rol.
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

            // REGLA MAESTRA: clave super.admin explicita = acceso total, EXCEPTO las
            // claves en self::PERMISOS_EXPLICITOS (que requieren la clave literal).
            if (! isset(self::PERMISOS_EXPLICITOS[$ability]) && in_array('super.admin', $permisos)) {
                return true;
            }

            // Verificación del permiso específico solicitado.
            // No hay alias ni atajos: la clave debe estar LITERAL en PERMISOS.
            // El picker (UserController::availablePermissions) es la unica
            // fuente de verdad.
            if (in_array($ability, $permisos)) {
                return true;
            }
        }

        // Delegar el resto al framework (para Gates/Policies estándar si se usan)
        return parent::can($abilities, $arguments);
    }

    /**
     * Usuarios con sesión activa en los últimos $mins minutos (driver de sesión = database).
     * Lee la tabla `sessions` y toma la sesión MÁS RECIENTE por usuario (evita duplicados por
     * varios navegadores/dispositivos). FUENTE ÚNICA reutilizada por el módulo de Auditoría
     * (HistorialDocumentos) y por /admin/usuarios — para no duplicar la consulta.
     *
     * Devuelve una colección de stdClass con: ID_USUARIO, NOMBRE_COMPLETO, CORREO_ELECTRONICO,
     * ip_address, last_activity. Colección vacía si la tabla/driver no aplica (no rompe la vista).
     */
    public static function sesionesActivas(int $mins = 30)
    {
        try {
            $cutoff = now()->subMinutes($mins)->timestamp;

            $latestPerUser = \Illuminate\Support\Facades\DB::table('sessions')
                ->selectRaw('user_id, MAX(last_activity) as last_activity')
                ->whereNotNull('user_id')
                ->where('last_activity', '>=', $cutoff)
                ->groupBy('user_id');

            return \Illuminate\Support\Facades\DB::table('sessions')
                ->joinSub($latestPerUser, 'latest', function ($j) {
                    $j->on('sessions.user_id', '=', 'latest.user_id')
                      ->on('sessions.last_activity', '=', 'latest.last_activity');
                })
                ->join('usuarios', 'usuarios.ID_USUARIO', '=', 'sessions.user_id')
                ->select(
                    'usuarios.ID_USUARIO',
                    'usuarios.NOMBRE_COMPLETO',
                    'usuarios.CORREO_ELECTRONICO',
                    'sessions.ip_address',
                    'sessions.last_activity'
                )
                ->orderByDesc('sessions.last_activity')
                ->get()
                ->unique('ID_USUARIO')
                ->values();
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::error('active users read failed', [
                'error'    => $e->getMessage(),
                'sql'      => method_exists($e, 'getRawSql') ? $e->getRawSql() : null,
                'bindings' => $e->getBindings(),
                'code'     => $e->getCode(),
            ]);
            return collect();
        }
    }
}
