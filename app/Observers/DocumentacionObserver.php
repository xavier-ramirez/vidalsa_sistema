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
    // Publica: CorrectorFichaDocumento mira esta lista para registrar SOLO lo que este
    // observer no audita (los datos de la poliza) y no duplicar el historial.
    public const AUDITED = ['PLACA', 'NRO_DE_DOCUMENTO', 'NOMBRE_DEL_TITULAR'];

    // Las fechas de vencimiento de la documentación alimentan las alertas del
    // dashboard /menu (cacheado por usuario con la versión en la clave): cualquier
    // alta/edición/borrado la refresca para TODOS los usuarios.
    public function created(Documentacion $doc): void
    {
        \App\Http\Controllers\DashboardController::bumpDataVersion();
    }

    public function deleted(Documentacion $doc): void
    {
        \App\Http\Controllers\DashboardController::bumpDataVersion();
    }

    public function updated(Documentacion $doc): void
    {
        \App\Http\Controllers\DashboardController::bumpDataVersion();

        try {
            $changes = $doc->getChanges();
            // getPrevious(), NO getOriginal(): este observer corre DESPUÉS del commit
            // ($afterCommit) y para entonces Laravel ya igualó el "original" al valor nuevo; dentro
            // de una transacción (formulario del equipo, lectura de documentos) todo parecía sin
            // cambios y no se anotaba nada. getPrevious() guarda lo de antes del último guardado.
            $original = $doc->getPrevious();
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

        self::fechasPuestas($doc);
    }

    /**
     * La fecha de vencimiento que le faltaba a un documento: su fila sale de "para revisar" (ver
     * VerificacionDocumento::fechaPuesta). Publica: el panel del visor guarda con updateQuietly
     * (para no duplicar el historial) y este observer no corre; EquipoController::updateMetadata
     * la llama a mano. Lee wasChanged(), que tambien vale tras un guardado silencioso.
     */
    public static function fechasPuestas(Documentacion $doc): void
    {
        foreach (\App\Support\DocumentacionDeEquipo::VENCIMIENTO as $tipo => $col) {
            if ($doc->wasChanged($col) && $doc->$col) {
                $link = \App\Support\DocumentacionDeEquipo::COLUMNAS[$tipo]['link'];
                \App\Models\VerificacionDocumento::fechaPuesta('documentacion', $link, $col, $doc->$link);
            }
        }
    }
}
