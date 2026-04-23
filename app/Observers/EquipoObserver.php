<?php

namespace App\Observers;

use App\Models\Equipo;
use Illuminate\Support\Facades\Cache;

class EquipoObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    /**
     * Handle the Equipo "created" event.
     */
    public function created(Equipo $equipo): void
    {
        $this->refreshCache();
    }

    /**
     * Handle the Equipo "updated" event.
     */
    public function updated(Equipo $equipo): void
    {
        // Auditoria de ediciones: guarda los campos que cambiaron (solo los dirty).
        // Excluye timestamps y campos tecnicos. Silencioso ante errores.
        try {
            $changes = $equipo->getChanges();
            unset($changes['updated_at'], $changes['created_at']);
            if (!empty($changes)) {
                $original = $equipo->getOriginal();
                $diff = [];
                foreach ($changes as $field => $newValue) {
                    $diff[$field] = [
                        'antes'   => $original[$field] ?? null,
                        'despues' => $newValue,
                    ];
                }
                \App\Models\EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'edit', $diff);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('EquipoObserver updated audit log fallo: ' . $e->getMessage());
        }

        $this->refreshCache();
    }

    /**
     * Handle the Equipo "deleted" event.
     */
    public function deleted(Equipo $equipo): void
    {
        $this->refreshCache();
    }

    /**
     * Handle the Equipo "restored" event.
     */
    public function restored(Equipo $equipo): void
    {
        $this->refreshCache();
    }

    /**
     * Force refresh the dashboard cache.
     */
    private function refreshCache(): void
    {
        // When an equipment changes, invalidate expired documents caches
        // They will be regenerated on next dashboard load
        Cache::forget('dashboard_total_alerts');
        Cache::forget('dashboard_expired_list_v3');
    }
}
