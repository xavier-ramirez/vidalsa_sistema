<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteGoogleDriveFile implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    protected $fileId;

    /**
     * Create a new job instance.
     *
     * @param string $fileId
     * @return void
     */
    public function __construct($fileId)
    {
        $this->fileId = $fileId;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (empty($this->fileId)) {
            return;
        }

        // Las dos reglas de EnlacesDocumentos, justo antes de borrar para siempre:
        //   · El PC de desarrollo comparte Google Drive con el servidor pero NO la base.
        //     Reemplazar o borrar un documento alli borraba un archivo que el servidor
        //     todavia usa: el documento quedaba roto en produccion. Alli no se borra nada
        //     (queda un archivo de mas en Drive, que no rompe nada).
        //   · Un mismo archivo puede estar enlazado en varias filas (13 auxiliares comparten
        //     el mismo documento de propiedad). Reemplazar el de uno borraba el de todos. Este
        //     job corre DESPUES de guardar la fila nueva (borrarTrasResponder), asi que si
        //     alguna fila lo sigue enlazando, es de otro.
        [$esServidor, $motivo] = \App\Support\EnlacesDocumentos::esBaseDelServidor();
        if (!$esServidor) {
            \Illuminate\Support\Facades\Log::info("Background Job: NO se borra el archivo de Drive {$this->fileId}: $motivo.");
            return;
        }
        if (\App\Support\EnlacesDocumentos::sigueEnUso($this->fileId)) {
            \Illuminate\Support\Facades\Log::info("Background Job: NO se borra el archivo de Drive {$this->fileId}: otra fila lo sigue usando.");
            return;
        }

        try {
            $driveService = \App\Services\GoogleDriveService::getInstance();
            $driveService->deleteFile($this->fileId);
            \Illuminate\Support\Facades\Log::info("Background Job: Deleted old Drive file {$this->fileId}");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Background Job Failed: Could not delete Drive file {$this->fileId}. Error: " . $e->getMessage());
        }
    }
}
