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
     * `tipo_categoria_map_form` (EquipoController::create, cache 1h) se DERIVA de los
     * pares (tipo, CATEGORIA_FLOTA) de los equipos ya registrados. Solo cambia cuando
     * nace un equipo o cuando uno cambia de tipo/categoría — no en cada edición.
     */
    private function bustTipoCategoriaMap(): void
    {
        Cache::forget('tipo_categoria_map_form');
    }

    /**
     * El historial de documentos lista una fila "Registro de Vehículo" por equipo con
     * CREADO_POR, así que nacer o morir lo cambia. NO basta con el bump que hace
     * EquipoAuditLog al registrar 'create'/'delete': la importación masiva crea equipos
     * SIN registrar auditoría, y forceDeleteEquipo borra los logs por query builder
     * (sin eventos de modelo) y cascadea `documentacion`. Aquí se cubren TODOS los
     * caminos. La edición no necesita bump: siempre pasa por EquipoAuditLog 'edit'.
     */
    private function bustHistorialDocs(): void
    {
        \App\Http\Controllers\HistorialDocumentosController::bumpDataVersion();
    }

    /**
     * Snapshot offline (dominio "equipos"). SIN gate por columna, a diferencia del
     * bump del dashboard: el offline pinta casi todos los campos del equipo, asi que
     * cualquier edicion es relevante para el telefono.
     */
    private function bustOffline(): void
    {
        \App\Support\OfflineVersion::invalidar('equipos');
    }

    public function created(Equipo $equipo): void
    {
        $this->bustTipoCategoriaMap();
        $this->bustHistorialDocs();
        $this->bustOffline();
        \App\Http\Controllers\DashboardController::bumpDataVersion();
    }

    public function deleted(Equipo $equipo): void
    {
        $this->bustTipoCategoriaMap();
        $this->bustHistorialDocs();
        $this->bustOffline();
        \App\Http\Controllers\DashboardController::bumpDataVersion();
    }

    /**
     * Handle the Equipo "updated" event.
     *
     * Auditoría de ediciones: guarda los campos que cambiaron (solo los dirty).
     * Excluye timestamps y campos técnicos. Silencioso ante errores.
     */
    public function updated(Equipo $equipo): void
    {
        $this->bustOffline();

        if ($equipo->wasChanged(['id_tipo_equipo', 'CATEGORIA_FLOTA'])) {
            $this->bustTipoCategoriaMap();
        }

        // El dashboard /menu (alertas, salud de flota, catálogos) se cachea por
        // usuario con la versión en la clave: este bump lo refresca para TODOS.
        // Solo si cambió una columna que el dashboard realmente pinta — sin este
        // gate, cualquier edición menor (horómetro, observaciones) mataría la
        // caché de todos los usuarios y el TTL de 10 min nunca sobreviviría.
        if ($equipo->wasChanged(['ESTADO_OPERATIVO', 'ID_FRENTE_ACTUAL', 'MODELO', 'MARCA', 'ID_ANCLAJE'])) {
            \App\Http\Controllers\DashboardController::bumpDataVersion();
        }

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
