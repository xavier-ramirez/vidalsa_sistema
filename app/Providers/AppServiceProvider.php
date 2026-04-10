<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Schema;
use App\Models\Equipo;
use App\Models\Movilizacion;
use App\Models\Documentacion;
use App\Models\RegistroFalla;
use App\Observers\EquipoObserver;
use App\Observers\MovilizacionObserver;
use App\Observers\DocumentacionObserver;
use App\Observers\RegistroFallaObserver;

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

            // ── manage.users: requiere AMBAS condiciones SIEMPRE (no es bypaseable) ──
            // Se evalúa ANTES del shortcut de super.admin para garantizar ambas condiciones.
            if ($ability === 'manage.users') {
                $tieneClaveAdmin = in_array('super.admin', $permisos);
                $tieneRolAdmin   = optional($user->rol)->NOMBRE_ROL === 'SUPER ADMIN';
                return $tieneClaveAdmin && $tieneRolAdmin; // true o false definitivo
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
        Movilizacion::observe(MovilizacionObserver::class);
        Documentacion::observe(DocumentacionObserver::class);
        RegistroFalla::observe(RegistroFallaObserver::class);
    }
}

