<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\Documentacion;
use App\Observers\EquipoObserver;
use App\Observers\EquipoAuxiliarObserver;
use App\Observers\DocumentacionObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        Schema::defaultStringLength(191);
        
        // GLOBAL PERMISSION GATE - Basado UNICAMENTE en claves (columna PERMISOS).
        // El ROL no otorga acceso automatico. Solo la clave 'super.admin' en PERMISOS
        // da acceso total — EXCEPTO las claves en Usuario::PERMISOS_EXPLICITOS, que
        // requieren la clave literal. Las exclusiones viven en el modelo para que
        // este gate y Usuario::can() compartan la misma fuente de verdad (sin esto,
        // el middleware `can:almacen.productos` pasaba para super.admin aunque
        // Usuario::can() lo bloqueara, dejando un agujero por el flow del middleware).
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            $permisosRaw = $user->PERMISOS;

            if (is_string($permisosRaw)) {
                $permisos = explode(',', $permisosRaw);
            } elseif (is_array($permisosRaw)) {
                $permisos = $permisosRaw;
            } else {
                $permisos = [];
            }

            $permisos = array_map('strtolower', array_map('trim', array_filter($permisos, 'is_string')));
            $ability  = strtolower($ability);

            // manage.users: solo requiere la clave 'super.admin' en PERMISOS.
            if ($ability === 'manage.users') {
                return in_array('super.admin', $permisos);
            }

            // super.admin = acceso total, EXCEPTO las claves explicitas que exigen
            // la clave literal en PERMISOS (ver \App\Models\Usuario::PERMISOS_EXPLICITOS).
            if (! isset(\App\Models\Usuario::PERMISOS_EXPLICITOS[$ability])
                && in_array('super.admin', $permisos)
            ) {
                return true;
            }

            // Verificar permiso especifico (literal en PERMISOS).
            if (in_array($ability, $permisos)) {
                return true;
            }

            // Ninguna clave coincide → null = continua la evaluacion normal del Gate.
            return null;
        });

        Equipo::observe(EquipoObserver::class);
        EquipoAuxiliar::observe(EquipoAuxiliarObserver::class);
        Documentacion::observe(DocumentacionObserver::class);

        // View Composer: inyecta $traspasosPorRecibir en el layout base para que el badge
        // del menú "Almacén → Recepción" se vea desde CUALQUIER página
        // (no solo desde /admin/almacen donde el controller lo calculaba).
        // Defensa: si la tabla `traspasos` aún no existe (migrate pendiente) o el usuario
        // no está autenticado, $traspasosPorRecibir queda en 0 y el badge no aparece.
        View::composer('layouts.estructura_base', function ($view) {
            $count = 0;
            try {
                $user = auth()->user();
                if ($user && Schema::hasTable('traspasos')) {
                    // Cacheado por usuario: estas 2 consultas (whereHas + count) corrían
                    // en CADA página. La clave lleva la versión que Traspaso::booted()
                    // incrementa en cada escritura → el badge se refresca al instante
                    // para TODOS. Nota: cambios en la asignación usuario↔frente↔almacén
                    // no bumpean la versión; los acota el TTL de 120s.
                    $ver = \App\Support\CacheVersion::current('traspasos_badge_ver');
                    $count = \Illuminate\Support\Facades\Cache::remember(
                        "traspasos_badge_u{$user->ID_USUARIO}_v{$ver}",
                        120,
                        function () use ($user) {
                            // La recepción es PERSONAL: el badge cuenta SOLO las notas
                            // destinadas al almacén del usuario (Almacen::asociadosIdsDe =
                            // ligados a sus frentes), NO visiblesPara() — que para un
                            // GLOBAL/admin devuelve TODOS y hacía que una cuenta que solo
                            // EMITE (origen) viera notas de OTROS almacenes que ella no
                            // recibe. Sin almacén propio → 0.
                            $almacenesAsociados = \App\Models\Almacen::asociadosIdsDe($user);
                            if ($almacenesAsociados->isEmpty()) {
                                return 0;
                            }
                            return \App\Models\Traspaso::query()
                                ->where('ESTADO', \App\Models\Traspaso::ESTADO_ENVIADO)
                                ->whereIn('ID_ALMACEN_DESTINO', $almacenesAsociados)
                                ->count();
                        }
                    );
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('View composer traspasosPorRecibir: ' . $e->getMessage());
            }
            $view->with('traspasosPorRecibir', $count);
        });
    }
}

