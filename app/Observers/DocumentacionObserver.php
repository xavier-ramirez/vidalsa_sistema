<?php

namespace App\Observers;

use App\Models\Documentacion;

class DocumentacionObserver
{
    public $afterCommit = true;

    // Solo se auditan estos campos de IDENTIDAD del documento. El resto
    // (LINK_*, *_FECHA_SUBIDA, *_SUBIDO_POR, fechas de vencimiento y campos de
    // gestión) ya tienen su propia auditoría: upload_X / delete_X / metadata_X,
    // registrados explícitamente en EquipoController (uploadDoc/deleteDoc/
    // updateMetadata). Auditar aquí esos campos generaría eventos DUPLICADOS.
    // Estos 3, en cambio, solo se registran al editarlos por el panel del visor
    // PDF (metadata_propiedad); por el FORMULARIO PRINCIPAL de edición no había
    // ningún registro — este observer cubre ese hueco.
    private const AUDITED = ['PLACA', 'NRO_DE_DOCUMENTO', 'NOMBRE_DEL_TITULAR'];

    public function updated(Documentacion $doc): void
    {
        try {
            $changes = $doc->getChanges();
            $original = $doc->getOriginal();
            $diff = [];
            foreach (self::AUDITED as $field) {
                if (!array_key_exists($field, $changes)) continue;
                $old = $original[$field] ?? null;
                $new = $changes[$field];
                if ((string) $old === (string) $new) continue;
                $diff[$field] = ['antes' => $old, 'despues' => $new];
            }
            if (!empty($diff)) {
                \App\Models\EquipoAuditLog::registrar((int) $doc->ID_EQUIPO, 'edit', $diff);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DocumentacionObserver updated audit log fallo: ' . $e->getMessage());
        }
    }
}
