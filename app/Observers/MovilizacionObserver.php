<?php

namespace App\Observers;

use App\Models\Movilizacion;
use Illuminate\Support\Facades\Cache;

class MovilizacionObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    /**
     * Handle the Movilizacion "created" event.
     */
    public function created(Movilizacion $movilizacion): void
    {
        $this->refreshCache();
    }

    /**
     * Handle the Movilizacion "updated" event.
     */
    public function updated(Movilizacion $movilizacion): void
    {
        $this->refreshCache();
    }

    /**
     * Handle the Movilizacion "deleted" event.
     */
    public function deleted(Movilizacion $movilizacion): void
    {
        $this->refreshCache();
    }

    /**
     * Force refresh the dashboard cache.
     */
    private function refreshCache(): void
    {
        // Pendientes ya no existen, son instantaneos.
        Cache::forever('dashboard_pendientes', 0);

        // Movilizaciones de hoy: TTL hasta fin del dia (a las 00:00 expira y se
        // recalcula al dia siguiente). Antes era forever, lo que causaba que
        // si nadie creaba una movilizacion el cache no rotaba al cambiar la fecha.
        Cache::put(
            'dashboard_movilizaciones_hoy',
            Movilizacion::whereDate('FECHA_DESPACHO', now()->today())->count(),
            now()->endOfDay()
        );

        // Recent activity: TTL de 1 hora — defensivo aunque tambien se invalida
        // en cada created/updated/deleted via observer.
        $recentActivity = Movilizacion::with(['equipo', 'frenteDestino'])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        Cache::put('dashboard_recent_activity', $recentActivity, now()->addHour());
    }
}
