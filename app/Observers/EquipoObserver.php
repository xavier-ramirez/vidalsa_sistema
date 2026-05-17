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
    }
}
