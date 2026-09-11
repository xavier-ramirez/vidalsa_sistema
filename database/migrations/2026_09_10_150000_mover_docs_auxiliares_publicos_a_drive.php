<?php

use App\Http\Controllers\DashboardController;
use App\Models\EquipoAuxiliar;
use App\Services\GoogleDriveService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Saca de la carpeta pública los PDF de auxiliares que el formulario viejo guardaba en el
 * disco 'public' (/storage/equipos_auxiliares/…): nginx los entregaba SIN pedir sesión.
 *
 *   1. Los que la BD todavía usa se suben a Drive y su link pasa al proxy
 *      /storage/google/{id}, que sí exige login (EquipoAuxiliar::subirDocADrive).
 *   2. TODO lo que queda en esa carpeta —lo recién subido y lo que la BD ya no usaba— se
 *      MUEVE al disco privado 'local' (equipos_auxiliares_retirados/). No se borra nada.
 *
 * Corre sola en el deploy (start.sh ejecuta migrate). Si Drive falla, lanza ANTES de mover
 * ningún archivo: la migración queda sin registrar y el siguiente deploy la reintenta.
 *
 * Usa la app (EquipoAuxiliar::subirDocADrive, GoogleDriveService) a propósito: subir a Drive
 * solo existe ahí, y el PDF tiene que quedar guardado igual que los que sube la app. Donde
 * no quedan links al disco 'public' (una instalación nueva) no llega a llamarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        $publico = Storage::disk('public');
        $drive   = null;
        $cambios = false;

        foreach (EquipoAuxiliar::withTrashed()->get() as $aux) {
            foreach (EquipoAuxiliar::DOCS as $tipo => $col) {
                $ruta = (string) parse_url((string) $aux->$col, PHP_URL_PATH);
                if (!str_starts_with($ruta, '/storage/') || str_starts_with($ruta, '/storage/google/')) {
                    continue;
                }
                $rel = ltrim(substr($ruta, strlen('/storage/')), '/');
                if (!$publico->exists($rel)) {
                    Log::warning("Auxiliar {$aux->ID_AUXILIAR}: su {$tipo} apunta a {$ruta}, que no existe en el disco; se deja como está.");
                    continue;
                }

                $drive ??= GoogleDriveService::getInstance();
                $aux->$col = EquipoAuxiliar::subirDocADrive($drive, $tipo, new File($publico->path($rel)));
                // Sin observer: es un traslado, no una subida de un usuario; no va al historial.
                $aux->saveQuietly();
                $cambios = true;
            }
        }

        $privado = Storage::disk('local');
        foreach ($publico->allFiles('equipos_auxiliares') as $rel) {
            $destino = 'equipos_auxiliares_retirados/' . substr($rel, strlen('equipos_auxiliares/'));
            $origen  = $publico->readStream($rel);
            $copiado = $privado->writeStream($destino, $origen);
            fclose($origen); // antes de borrar: en Windows un archivo abierto no se puede borrar
            if (!$copiado) {
                throw new \RuntimeException("No se pudo copiar {$rel} al disco privado.");
            }
            $publico->delete($rel);
        }
        $publico->deleteDirectory('equipos_auxiliares');

        if ($cambios) {
            DashboardController::bumpDataVersion(); // saveQuietly no avisa a las cachés
        }
    }

    // Sin vuelta atrás a propósito: devolverlos al disco 'public' reabriría el acceso sin login.
    public function down(): void
    {
    }
};
