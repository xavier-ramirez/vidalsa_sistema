<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GoogleDriveController extends Controller
{
    public function proxy($path)
    {
        try {
            $fileId = basename($path);
            // ?sz=w300 (o cualquier valor) => devolver el thumbnail publico
            // de Drive cacheado localmente. Con sz=w300 el archivo es chico
            // (< 50KB) y el browser cachea via max-age 1814400 = 21 dias.
            // Sin sz, sirve el archivo original (FOTO completa).
            $sz = request()->query('sz');
            $isThumb = $sz && preg_match('/^w?\d{2,4}(-h\d{2,4})?$/', $sz);
            $cachePath = $isThumb
                ? 'google_cache/thumb_' . preg_replace('/[^A-Za-z0-9_-]/', '', $sz) . '_' . $fileId
                : 'google_cache/' . $fileId;

            // Cache-Control 1814400s = 21 dias (3 semanas) — pediste 1-3
            // semanas, escogemos el limite alto. must-revalidate permite a
            // un Ctrl+F5 forzar refetch contra el ETag.
            $maxAge = 1814400;

            // 1. SERVIR DESDE LOCAL CACHE
            if (Storage::disk('local')->exists($cachePath)) {
                $fullPath = Storage::disk('local')->path($cachePath);
                $mime = $isThumb ? 'image/jpeg' : mime_content_type($fullPath);
                $version = request()->query('v', '0');
                $etag = md5($fileId . '-' . $sz . '-' . $version);
                return response()->file($fullPath, [
                    'Content-Type'  => $mime,
                    'Cache-Control' => 'public, max-age=' . $maxAge . ', must-revalidate',
                    'ETag'          => '"' . $etag . '"',
                    'Pragma'        => 'public',
                    'Expires'       => gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge),
                ]);
            }

            // 2. THUMB MODE: bajar el thumbnail de Drive y guardarlo local.
            //    Drive expone https://drive.google.com/thumbnail?id=...&sz=w300
            //    para archivos publicos — es la URL que usaban los listados
            //    directamente (drive.google.com/thumbnail?id=...). La pasamos
            //    por aca para cachearla 3 semanas en disco local + browser.
            if ($isThumb) {
                $thumbUrl = 'https://drive.google.com/thumbnail?id=' . urlencode($fileId) . '&sz=' . urlencode($sz);
                $ctx = stream_context_create([
                    'http' => ['timeout' => 8, 'follow_location' => 1],
                    'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
                ]);
                $bytes = @file_get_contents($thumbUrl, false, $ctx);
                if ($bytes === false || strlen($bytes) < 100) {
                    // Drive devolvio HTML de error — cae al stream completo
                    // como fallback (mejor algo que un broken image icon).
                    $isThumb = false;
                } else {
                    Storage::disk('local')->put($cachePath, $bytes);
                    $version = request()->query('v', '0');
                    $etag = md5($fileId . '-' . $sz . '-' . $version);
                    return response($bytes, 200, [
                        'Content-Type'  => 'image/jpeg',
                        'Cache-Control' => 'public, max-age=' . $maxAge . ', must-revalidate',
                        'ETag'          => '"' . $etag . '"',
                        'Pragma'        => 'public',
                        'Expires'       => gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge),
                    ]);
                }
            }

            // 3. FULL FILE: descargar via API (mantiene comportamiento legacy)
            $driveService = \App\Services\GoogleDriveService::getInstance();
            $metadata = Cache::remember('gdrive_meta_' . $fileId, 86400, function() use ($driveService, $fileId) {
                $drive = $driveService->getDrive();
                $file = $drive->files->get($fileId, [
                    'fields' => 'mimeType,size,modifiedTime',
                    'supportsAllDrives' => true
                ]);
                return [
                    'mime' => $file->getMimeType(),
                    'size' => $file->getSize() ?: 0,
                ];
            });
            $version = request()->query('v', '0');
            $etag = md5($fileId . '-' . $version);

            // TRANSMITIR, no acumular. Antes esto bajaba el archivo ENTERO de Drive, lo
            // escribia en disco, lo volvia a leer y recien ahi empezaba a enviarlo: cuatro
            // pasos uno detras de otro, y el navegador sin ver un byte hasta el final.
            // Ahora se lee de Drive por trozos y cada trozo sale hacia el navegador en el
            // acto, asi que las dos patas del viaje se solapan en vez de sumarse. El
            // servidor tampoco tiene que sostener el archivo completo en memoria ni esperar
            // al disco.
            //
            // La copia local se sigue guardando —es lo que hace instantanea la SEGUNDA
            // apertura, tambien para otro usuario— pero se escribe A LA VEZ que se envia,
            // asi que ya no retrasa nada.
            //
            // Se escribe a un archivo TEMPORAL y solo se asciende al nombre bueno cuando el
            // archivo llego completo. Si el usuario cierra el visor a mitad, el corte deja
            // el .parcial y no una copia truncada que luego se sirviera como buena.
            $stream = $driveService->getStreamById($fileId);
            $rutaFinal = Storage::disk('local')->path($cachePath);
            $rutaTmp   = $rutaFinal . '.parcial';
            @mkdir(dirname($rutaFinal), 0775, true);

            $cabeceras = [
                'Content-Type'  => $metadata['mime'],
                'Cache-Control' => 'public, max-age=' . $maxAge . ', must-revalidate',
                'ETag'          => '"' . $etag . '"',
                'Pragma'        => 'public',
                'Expires'       => gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge),
            ];
            // Content-Length solo si Drive lo dio: con el, el navegador sabe cuanto falta y
            // detecta un corte a mitad; sin el, mentir seria peor que callar.
            if (!empty($metadata['size'])) {
                $cabeceras['Content-Length'] = (string) $metadata['size'];
            }

            return response()->stream(function () use ($stream, $rutaTmp, $rutaFinal) {
                $salida = fopen('php://output', 'wb');
                $copia  = @fopen($rutaTmp, 'wb');   // si el disco falla, se sirve igual
                $bytes  = 0;

                while (!$stream->eof()) {
                    $trozo = $stream->read(262144);   // 256 KB
                    if ($trozo === '' || $trozo === false) break;
                    fwrite($salida, $trozo);
                    if ($copia) fwrite($copia, $trozo);
                    $bytes += strlen($trozo);
                    // Vaciar el bufer de PHP ANTES del de la conexion. Con solo flush(), si
                    // Laravel dejo un bufer de salida abierto el trozo se queda acumulado
                    // ahi y no sale: la transmision seria de mentira y todo esto no serviria
                    // de nada. El guard evita el aviso de ob_flush() sin bufer que vaciar.
                    if (ob_get_level() > 0) { @ob_flush(); }
                    flush();
                    if (connection_aborted()) break;  // cerro el visor: no seguir bajando
                }

                if ($copia) {
                    fclose($copia);
                    // Solo se asciende si de verdad llego completo.
                    if ($bytes > 0 && !connection_aborted()) {
                        if (is_file($rutaFinal)) @unlink($rutaFinal);   // rename no pisa en Windows
                        @rename($rutaTmp, $rutaFinal);
                    } else {
                        @unlink($rutaTmp);
                    }
                }
                $stream->close();
            }, 200, $cabeceras);

        } catch (\Exception $e) {
            Log::error('Google Drive fetch error: ' . $e->getMessage());
            // Este endpoint sirve archivos EMBEBIDOS (PDF en visor/iframe, imágenes).
            // NO usar abort(404) porque renderiza la página de NAVEGACIÓN con botón
            // "Ir al inicio de sesión" — absurdo dentro de un visor de PDF (el usuario
            // ya está logueado). Devolvemos una página propia "Documento no disponible".
            return response()->view('errors.asset_unavailable', [], 404);
        }
    }
}
