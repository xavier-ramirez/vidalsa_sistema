<?php

namespace App\Observers;

use App\Models\Equipo;

class EquipoObserver
{
    /**
     * Handle events after all transactions are committed.
     *
     * @var bool
     */
    public $afterCommit = true;

    /**
     * Handle the Equipo "updated" event.
     *
     * Auditoría de ediciones: guarda los campos que cambiaron (solo los dirty).
     * Excluye timestamps y campos técnicos. Silencioso ante errores.
     */
    public function updated(Equipo $equipo): void
    {
        try {
            $changes = $equipo->getChanges();
            unset($changes['updated_at'], $changes['created_at']);
            if (empty($changes)) return;

            $original = $equipo->getOriginal();
            $diff = [];
            foreach ($changes as $field => $newValue) {
                $oldValue = $original[$field] ?? null;
                if ((string) $oldValue === (string) $newValue) continue;
                $diff[$field] = [
                    'antes'   => $oldValue,
                    'despues' => $newValue,
                ];
            }
            if (!empty($diff)) {
                \App\Models\EquipoAuditLog::registrar($equipo->ID_EQUIPO, 'edit', $diff);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('EquipoObserver updated audit log fallo: ' . $e->getMessage());
        }
    }
}
