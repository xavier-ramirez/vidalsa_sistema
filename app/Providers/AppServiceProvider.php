<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use App\Models\Equipo;
use App\Observers\EquipoObserver;

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
        
        // GLOBAL PERMISSION GATE - Basado ÚNICAMENTE en claves (columna PERMISOS)
        // El ROL no otorga acceso automático. Solo la clave 'super.admin' en PERMISOS da acceso total.
        \Illuminate\Support\Facades\Gate::before(function ($user, $ability) {
            // Leer PERMISOS como array
            $permisosRaw = $user->PERMISOS;

            if (is_string($permisosRaw)) {
                $permisos = explode(',', $permisosRaw);
            } elseif (is_array($permisosRaw)) {
                $permisos = $permisosRaw;
            } else {
                $permisos = [];
            }

            // Normalizar (eliminar espacios, lowercase)
            $permisos = array_map('strtolower', array_map('trim', array_filter($permisos, 'is_string')));

            // ── manage.users: solo requiere la clave 'super.admin' en PERMISOS ──
            if ($ability === 'manage.users') {
                return in_array('super.admin', $permisos); // true o false definitivo
            }

            // Clave maestra: super.admin en PERMISOS = acceso total (para todo lo demás)
            if (in_array('super.admin', $permisos)) {
                return true;
            }

            // Verificar permiso específico
            if (in_array(strtolower($ability), $permisos)) {
                return true;
            }

            // Ninguna clave coincide → acceso denegado (null = continúa evaluación normal)
            return null;
        });

        Equipo::observe(EquipoObserver::class);

        // View Composer: inyecta $traspasosPorRecibir en el layout base para que el badge
        // del menú "Almacén → Recepción de Materiales" se vea desde CUALQUIER página
        // (no solo desde /admin/almacen donde el controller lo calculaba).
        // Defensa: si la tabla `traspasos` aún no existe (migrate pendiente) o el usuario
        // no está autenticado, $traspasosPorRecibir queda en 0 y el badge no aparece.
        View::composer('layouts.estructura_base', function ($view) {
            $count = 0;
            try {
                $user = auth()->user();
                if ($user && Schema::hasTable('traspasos')) {
                    $almacenesVisibles = \App\Models\Almacen::visiblesPara($user)->pluck('ID_ALMACEN');
                    $count = \App\Models\Traspaso::query()
                        ->where('ESTADO', \App\Models\Traspaso::ESTADO_ENVIADO)
                        ->whereIn('ID_ALMACEN_DESTINO', $almacenesVisibles)
                        ->count();
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('View composer traspasosPorRecibir: ' . $e->getMessage());
            }
            $view->with('traspasosPorRecibir', $count);
        });
    }
}

