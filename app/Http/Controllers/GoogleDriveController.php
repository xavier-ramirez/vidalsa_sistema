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
            // ?sz=w300 (o cualquier valor) => devolver la MINIATURA de Drive cacheada
            // localmente. Con sz=w300 el archivo es chico (< 50KB) y el browser cachea via
            // max-age 1814400 = 21 dias. Sin sz, sirve el archivo original.
            //
            // Sirve para fotos y tambien para PDF: de un PDF, Drive da la imagen de su
            // PRIMERA PAGINA. Es lo que usa el visor para enseñar el documento mientras el
            // PDF de verdad todavia viaja (ver _pdfPreviaMostrar en layout_ui.js).
            $sz = request()->query('sz');
            $isThumb = $sz && preg_match('/^w?\d{2,4}(-h\d{2,4})?$/', $sz);
            $cachePath = \App\Services\GoogleDriveService::rutaCopiaLocal($fileId);

            // Cache-Control 1814400s = 21 dias (3 semanas) — pediste 1-3
            // semanas, escogemos el limite alto. must-revalidate permite a
            // un Ctrl+F5 forzar refetch contra el ETag.
            $maxAge = 1814400;

            // 1. MINIATURA: de su copia local o, la primera vez, de Drive.
            if ($isThumb) {
                [$bytes, $mimeOriginal] = \App\Services\GoogleDriveService::miniatura($fileId, $sz);
                if ($bytes !== null) {
                    $version = request()->query('v', '0');
                    $etag = md5($fileId . '-' . $sz . '-' . $version);
                    return response($bytes, 200, [
                        'Content-Type'  => getimagesizefromstring($bytes)['mime'],
                        'Cache-Control' => 'public, max-age=' . $maxAge . ', must-revalidate',
                        'ETag'          => '"' . $etag . '"',
                        'Pragma'        => 'public',
                        'Expires'       => gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge),
                    ]);
                }
                // Sin miniatura (Drive aun no la genero, p. ej. recien subido). Una FOTO cae
                // al archivo completo: mejor la foto grande que un icono roto. Un PDF NO: quien
                // pide su miniatura es el visor, que para ese momento YA esta bajando el PDF
                // en su <iframe>; mandarselo entero otra vez a un <img> duplicaria la descarga
                // mas pesada del sistema para no poder mostrarla. Sin cuerpo y sin cache: la
                // proxima apertura vuelve a intentarlo.
                if ($mimeOriginal === 'application/pdf') {
                    return response('', 404, ['Cache-Control' => 'no-store']);
                }
                $isThumb = false;
            }

            // 2. ARCHIVO COMPLETO DESDE LA COPIA LOCAL. Va DESPUES de la miniatura para que
            // una foto sin miniatura, que cae aqui, se sirva del disco si ya se bajo y no
            // vuelva a pedirse entera a Drive en cada carga.
            if (!$isThumb && Storage::disk('local')->exists($cachePath)) {
                $fullPath = Storage::disk('local')->path($cachePath);
                $mime = mime_content_type($fullPath);
                $version = request()->query('v', '0');
                // Mismo ETag que la primera vez (la transmision desde Drive, mas abajo): es el mismo archivo.
                $etag = md5($fileId . '-' . $version);
                return response()->file($fullPath, [
                    'Content-Type'  => $mime,
                    'Cache-Control' => 'public, max-age=' . $maxAge . ', must-revalidate',
                    'ETag'          => '"' . $etag . '"',
                    'Pragma'        => 'public',
                    'Expires'       => gmdate('D, d M Y H:i:s \G\M\T', time() + $maxAge),
                ]);
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
            // Esto solo es verdad desde que getStreamById entrega la conexion con Drive y no
            // una copia ya descargada (ver el comentario de ese metodo): hasta entonces el
            // bucle de abajo leia de un temporal completo y el solape no ocurria.
            //
            // La copia local se sigue guardando —es lo que hace instantanea la SEGUNDA
            // apertura, tambien para otro usuario— pero se escribe A LA VEZ que se envia,
            // asi que ya no retrasa nada.
            //
            // Se escribe a un archivo TEMPORAL y solo se asciende al nombre bueno cuando el
            // archivo llego completo. Si el usuario cierra el visor a mitad, o Drive corta,
            // el temporal se borra y no queda una copia truncada que luego se sirviera como
            // buena durante 21 dias.
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

            $esperado = (int) ($metadata['size'] ?? 0);

            return response()->stream(function () use ($stream, $rutaTmp, $rutaFinal, $esperado, $fileId) {
                // Que un cierre del visor NO mate el script. Por defecto PHP lo corta en seco
                // en cuanto escribe a un cliente que ya se fue, sin pasar por el finally: el
                // .parcial se quedaba en el disco para siempre (comprobado: uno de 950 KB tras
                // cerrar a mitad) y el connection_aborted() del bucle nunca llegaba a verse.
                // Asi el bucle lo detecta, deja de bajar y el finally limpia.
                ignore_user_abort(true);
                $salida = fopen('php://output', 'wb');
                $copia  = @fopen($rutaTmp, 'wb');   // si el disco falla, se sirve igual
                $bytes  = 0;

                try {
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
                } catch (\Throwable $e) {
                    // Drive corto a mitad (la conexion es la de Drive, no un temporal ya
                    // completo). Las cabeceras ya salieron: solo queda no guardar la copia.
                    Log::warning('Drive corto la transmision de ' . $fileId . ' en ' . $bytes . ' bytes: ' . $e->getMessage());
                } finally {
                    if ($copia) {
                        fclose($copia);
                        // COMPLETO = el cliente sigue ahi y llegaron TODOS los bytes que Drive
                        // dijo que tenia. Antes bastaba con "llego algo": un corte de Drive a
                        // mitad dejaba un PDF truncado guardado como bueno, roto para todos.
                        // Sin tamaño conocido se acepta lo recibido, como antes.
                        $completo = $bytes > 0 && !connection_aborted()
                            && ($esperado === 0 || $bytes === $esperado);
                        if ($completo) {
                            if (is_file($rutaFinal)) @unlink($rutaFinal);   // rename no pisa en Windows
                            @rename($rutaTmp, $rutaFinal);
                        } else {
                            @unlink($rutaTmp);
                        }
                    }
                    $stream->close();
                }
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
