<?php

namespace App\Observers;

use App\Models\EquipoAuxiliar;

class EquipoAuxiliarObserver
{
    public $afterCommit = true;

    // Columnas de enlace PDF: al cambiar indican subida/borrado de documento, no
    // una edición de datos. Cada una mapea a su ACCION de subida y de borrado.
    private const DOC_FIELDS = [
        'LINK_DOC_PROPIEDAD' => ['upload' => 'aux_upload_propiedad',   'delete' => 'aux_delete_propiedad'],
        'LINK_CERTIFICADO'   => ['upload' => 'aux_upload_certificado', 'delete' => 'aux_delete_certificado'],
    ];

    // Columnas del auxiliar que alimentan el panel "Alertas de Documentos" del
    // dashboard (DashboardController::generateAlertsList lee el certificado del
    // auxiliar y lo filtra por estado y frente). Mismo gate por columna que
    // EquipoObserver::updated: sin él, cualquier edición menor mataría la caché
    // de TODOS los usuarios y el TTL de 10 minutos no serviría de nada.
    private const DASHBOARD_FIELDS = [
        'FECHA_VENCIMIENTO_CERT', 'LINK_CERTIFICADO', 'ESTADO_OPERATIVO', 'ID_FRENTE_ACTUAL',
    ];

    public function created(EquipoAuxiliar $aux): void
    {
        \App\Http\Controllers\DashboardController::bumpDataVersion();
    }

    public function deleted(EquipoAuxiliar $aux): void
    {
        \App\Http\Controllers\DashboardController::bumpDataVersion();
    }

    public function updated(EquipoAuxiliar $aux): void
    {
        // El dashboard va cacheado con la versión EN la clave: este bump lo refresca
        // para todos. Antes no existía, así que cargar o vencer el certificado de un
        // auxiliar no aparecía en el panel hasta 10 minutos después.
        if ($aux->wasChanged(self::DASHBOARD_FIELDS)) {
            \App\Http\Controllers\DashboardController::bumpDataVersion();
        }

        try {
            $changes = $aux->getChanges();
            unset($changes['updated_at'], $changes['created_at']);
            if (empty($changes)) return;

            $original   = $aux->getOriginal();
            $diff       = [];
            $docActions = [];

            foreach ($changes as $field => $newValue) {
                if (isset(self::DOC_FIELDS[$field])) {
                    // Subida = el link pasa a tener valor; borrado = pasa de tener a vacío.
                    $oldValue = $original[$field] ?? null;
                    if ($newValue)       $docActions[] = self::DOC_FIELDS[$field]['upload'];
                    elseif ($oldValue)   $docActions[] = self::DOC_FIELDS[$field]['delete'];
                    continue;
                }
                $oldValue = $original[$field] ?? null;
                if ((string) $oldValue === (string) $newValue) continue;
                $diff[$field] = ['antes' => $oldValue, 'despues' => $newValue];
            }

            $label = trim(implode(' ', array_filter([
                $aux->TIPO   ?? null,
                $aux->MARCA  ?? null,
                $aux->MODELO ?? null,
            ])));

            if (!empty($diff)) {
                \App\Models\EquipoAuditLog::registrarAux($aux->ID_AUXILIAR, 'aux_edit', $diff, $label);
            }
            foreach ($docActions as $action) {
                \App\Models\EquipoAuditLog::registrarAux($aux->ID_AUXILIAR, $action, [], $label);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('EquipoAuxiliarObserver updated audit log fallo: ' . $e->getMessage());
        }
    }
}
