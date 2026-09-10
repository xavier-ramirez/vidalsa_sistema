<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GoogleDriveService
{
    private static $instance = null;
    private $client;
    private $drive;
    private $accessToken;

    private function __construct()
    {
        // Private constructor for singleton
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            // Se cachea SOLO si initialize() termino bien. Antes se asignaba self::$instance
            // antes de inicializar, asi que si el token fallaba (red caida) la excepcion salia
            // pero el singleton quedaba a medias: la siguiente llamada del mismo request
            // devolvia ese objeto sin token, fallando despues con un error sin relacion.
            $instance = new self();
            $instance->initialize();
            self::$instance = $instance;
        }
        return self::$instance;
    }

    private function initialize()
    {
        $this->client = new Client();
        $this->client->setClientId(config('filesystems.disks.google.clientId'));
        $this->client->setClientSecret(config('filesystems.disks.google.clientSecret'));

        // GLOBAL FIX: Apply SSL bypass and timeout to ALL Google Client requests, not just token generation
        // Timeout increased to 120s for very slow internet connections
        $httpClient = new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 15, 'verify' => false]);
        $this->client->setHttpClient($httpClient);

        // Cache the access token for 55 minutes
        $this->accessToken = Cache::remember('google_drive_access_token', 55 * 60, function () {
            return $this->generateAccessToken();
        });

        $this->client->setAccessToken($this->accessToken);
        $this->drive = new Drive($this->client);
    }

    private function generateAccessToken()
    {
        // Circuit Breaker: If we failed recently, don't try again immediately to prevent page hangs
        if (Cache::has('google_drive_connection_error')) {
            throw new \Exception('Google Drive Service is temporarily unavailable (Circuit Breaker executed).');
        }

        try {
            // Client is already configured in initialize() with the correct HTTP client settings
            $this->client->refreshToken(config('filesystems.disks.google.refreshToken'));
            $token = $this->client->getAccessToken();
            Log::info('Google Drive token generated successfully');
            return $token;
        } catch (\Exception $e) {
            // Activate Circuit Breaker for 5 minutes
            Cache::put('google_drive_connection_error', true, 5 * 60);
            Log::error('Error generating Google Drive token: ' . $e->getMessage());
            throw $e;
        }
    }

    public function getClient()
    {
        // Check if token needs refresh
        if ($this->client->isAccessTokenExpired()) {
            Log::info('Google Drive token expired, refreshing...');
            Cache::forget('google_drive_access_token');
            $this->initialize();
        }
        
        return $this->client;
    }

    /**
     * Contenido de un archivo de Drive como flujo PSR-7 que se va leyendo MIENTRAS llega.
     *
     * Antes se pedia con files->get(alt=media) sin mas, y la libreria de Google baja la
     * respuesta ENTERA a un temporal (php://temp) antes de devolverla: quien leia "por
     * trozos" en realidad leia de un archivo que ya estaba completo. El proxy del visor,
     * que presume de ir mandando cada trozo al navegador segun llega de Drive, no mandaba
     * el primer byte hasta tener el PDF completo — medido: un ROTC de 3,3 MB tardaba 11,6 s
     * en empezar a salir. Con 'stream' => true el cuerpo es la conexion misma y el primer
     * trozo sale en cuanto Drive lo manda.
     *
     * Por eso se arma la peticion en modo diferido (setDefer: la libreria la devuelve sin
     * enviarla) y se envia aparte con el cliente autorizado. Los errores siguen saliendo
     * como excepcion igual que antes: el cliente HTTP de este servicio (initialize) lanza
     * en 4xx/5xx, asi que un archivo borrado da un 404 que el proxy convierte en su
     * pagina de "Documento no disponible".
     */
    public function getStreamById($fileId)
    {
        $drive = $this->getDrive();
        $this->client->setDefer(true);
        try {
            $peticion = $drive->files->get($fileId, [
                'alt' => 'media',
                'supportsAllDrives' => true
            ]);
        } finally {
            // Siempre de vuelta: el cliente es un singleton y en diferido NINGUNA llamada
            // posterior de la peticion llegaria a ejecutarse.
            $this->client->setDefer(false);
        }

        return $this->client->authorize()->send($peticion, ['stream' => true])->getBody();
    }

    /**
     * Get the drive instance
     */
    public function getDrive()
    {
        if ($this->client->isAccessTokenExpired()) {
            $this->getClient(); 
        }
        return $this->drive;
    }

    /**
     * Get the root folder ID from configuration
     */
    public function getRootFolderId()
    {
        return config('filesystems.disks.google.folder') ?: 'root';
    }

    /**
     * Uploads a file using multipart upload optimized for speed.
     */
    public function uploadFile($folderId, $file, $filename, $mimeType)
    {
        try {
            $drive = $this->getDrive();
            $fileMetadata = new \Google\Service\Drive\DriveFile([
                'name' => $filename,
                'parents' => [$folderId]
            ]);

            // Using multipart for speed and compatibility. 
            // We request ONLY the id back to minimize overhead.
            $content = file_get_contents($file->getRealPath());
            
            $driveFile = $drive->files->create($fileMetadata, [
                'data' => $content,
                'mimeType' => $mimeType,
                'uploadType' => 'multipart',
                'fields' => 'id',
                'supportsAllDrives' => true
            ]);

            if (!$driveFile || !isset($driveFile->id)) {
                Log::error("Failed to upload to Google Drive: " . $filename);
                return null;
            }

            return $driveFile;
        } catch (\Exception $e) {
            Log::error("Google Drive Upload Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function deleteFile($fileId)
    {
        try {
            $drive = $this->getDrive();
            $drive->files->delete($fileId, ['supportsAllDrives' => true]);
            Log::info("Deleted file from Google Drive: " . $fileId);
            return true;
        } catch (\Exception $e) {
            Log::error("Google Drive Delete Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Make a Drive file readable by anyone with the link. Required when the
     * thumbnail (https://drive.google.com/thumbnail?id=...) needs to render
     * in <img> tags without auth — we use this for catalog photos that the
     * public listing renders directly. Idempotent: if a public permission
     * already exists Drive returns 400 which we swallow.
     */
    public function makePublic($fileId)
    {
        try {
            $drive = $this->getDrive();
            $perm = new \Google\Service\Drive\Permission([
                'role' => 'reader',
                'type' => 'anyone',
            ]);
            $drive->permissions->create($fileId, $perm, ['supportsAllDrives' => true]);
            return true;
        } catch (\Exception $e) {
            Log::warning("Google Drive makePublic skipped/failed for {$fileId}: " . $e->getMessage());
            return false;
        }
    }

    /* ── COPIA LOCAL de los archivos de Drive ──────────────────────────────────────────
       El proxy (GoogleDriveController) guarda en el disco local cada archivo que sirve y
       cada miniatura que pide, para no volver a bajarlos. Aqui vive TODO lo que sabe de
       esas copias —donde estan, como se piden las miniaturas y como se olvidan— porque lo
       usan varios sitios: el proxy, el dashboard y cada pantalla que borra o reemplaza un
       documento. Antes estaba copiado a mano en cada uno. */

    /** Carpeta de las copias, dentro del disco 'local'. */
    private const CARPETA_COPIAS = 'google_cache/';

    /** Ruta (en el disco 'local') de la copia de un archivo, o de su miniatura si se da $sz. */
    public static function rutaCopiaLocal(string $fileId, ?string $sz = null): string
    {
        return self::CARPETA_COPIAS
            . ($sz === null ? '' : 'thumb_' . preg_replace('/[^A-Za-z0-9_-]/', '', $sz) . '_')
            . $fileId;
    }

    /**
     * Olvida TODO lo guardado de un archivo de Drive: su copia, sus miniaturas y los datos
     * recordados. Se llama al borrar o reemplazar un documento.
     *
     * Las miniaturas cuentan: desde que el visor enseña la primera pagina de cada PDF, un
     * documento borrado dejaba su pagina 1 servible desde el disco a quien tuviera el
     * enlace. Antes solo se borraba la copia del PDF.
     */
    public static function olvidarCopiaLocal(string $fileId): void
    {
        $disco = Storage::disk('local');
        $disco->delete(self::rutaCopiaLocal($fileId));
        foreach (glob($disco->path(self::CARPETA_COPIAS . 'thumb_*_' . $fileId)) ?: [] as $miniatura) {
            @unlink($miniatura);
        }
        Cache::forget('gdrive_meta_' . $fileId);
        Cache::forget('gdrive_miniatura_' . $fileId);
    }

    /**
     * Miniatura de un archivo de Drive, del tamaño pedido ("w300", "w1024"...). De un PDF
     * es la imagen de su PRIMERA PAGINA. Guardada en el disco local: la segunda vez sale
     * de ahi.
     *
     * Devuelve [bytes o null, mimeType del archivo original o null]. El mime solo se sabe
     * cuando hubo que preguntar a Drive; lo usa el proxy para no mandar un PDF entero a
     * un <img> cuando no hay miniatura.
     *
     * POR LA API y no por la URL publica drive.google.com/thumbnail, que era la unica via:
     * esa solo funciona con archivos compartidos por enlace, y los PDF del sistema son
     * PRIVADOS. Con uno privado Google no da error, da su pagina de inicio de sesion, y esa
     * pagina (HTML de ~900 KB) acababa guardada y servida como si fuera la imagen durante
     * 21 dias: un icono roto. La API da un enlace a la miniatura que sirve con cualquier
     * permiso.
     *
     * Al enlace se le pide el tamaño y ademas `-rj-l75`: JPEG al 75 %. Sin eso Drive la da
     * en PNG, y la primera pagina de un escaneo a 1024 px pesaba 1,9 MB en PNG contra
     * 124-235 KB en JPEG (medido). La miniatura esta para ir RAPIDO.
     *
     * La URL publica queda de respaldo SOLO para cuando la API no contesta (sin token, sin
     * red hacia Google): con una foto compartida sigue sirviendo. Si la API contesto —aunque
     * sea "no existe" o "no tiene miniatura"— la URL publica no va a saber mas, y con un
     * archivo privado o borrado se pasaba hasta 27 s bajando la pagina de login (medido)
     * con el proceso de PHP ocupado. Nada se da por bueno sin comprobar que es una imagen.
     */
    public static function miniatura(string $fileId, string $sz): array
    {
        $disco = Storage::disk('local');
        $ruta  = self::rutaCopiaLocal($fileId, $sz);
        if ($disco->exists($ruta)) {
            $guardada = $disco->get($ruta);
            if (self::esImagen($guardada)) {
                return [$guardada, null];
            }
            // Envenenada por la version anterior (ver arriba): se tira y se pide de nuevo.
            $disco->delete($ruta);
        }

        // "w300" o "w300-h200" van tal cual; un numero pelado ("300") es un lado maximo.
        $tamano = ctype_digit($sz[0]) ? 's' . $sz : $sz;
        $bytes = null;
        $mime  = null;
        $apiContesto = false;

        try {
            $servicio = self::getInstance();
            // El enlace caduca a las pocas horas: se recuerda media hora, y SOLO si existe.
            // Justo despues de subir un archivo Drive todavia no lo tiene, y recordar ese
            // "no hay" dejaria el documento sin miniatura media hora.
            $meta = Cache::get('gdrive_miniatura_' . $fileId);
            if (!$meta) {
                $archivo = $servicio->getDrive()->files->get($fileId, [
                    'fields' => 'mimeType,thumbnailLink',
                    'supportsAllDrives' => true,
                ]);
                $meta = ['mime' => $archivo->getMimeType(), 'link' => $archivo->getThumbnailLink()];
                if (!empty($meta['link'])) {
                    Cache::put('gdrive_miniatura_' . $fileId, $meta, 1800);
                }
            }
            $apiContesto = true;
            $mime = $meta['mime'];
            if (!empty($meta['link'])) {
                // El enlace trae su propio tamaño al final ("=s220"): se cambia por el pedido.
                $url = preg_replace('/=[^=\/]*$/', '', $meta['link']) . '=' . $tamano . '-rj-l75';
                $resp = $servicio->getClient()->authorize()->request('GET', $url, [
                    'http_errors' => false,
                    'timeout'     => 8,
                ]);
                if ($resp->getStatusCode() === 200) {
                    $bytes = (string) $resp->getBody();
                }
            }
        } catch (\Throwable $e) {
            // Un 404 tambien es una respuesta: el archivo no esta en Drive.
            $apiContesto = $e->getCode() === 404;
            Log::warning('Miniatura de Drive por API fallo para ' . $fileId . ': ' . $e->getMessage());
        }

        if (!$apiContesto && !self::esImagen($bytes)) {
            $ctx = stream_context_create([
                'http' => ['timeout' => 8, 'follow_location' => 1],
                'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
            ]);
            $bytes = @file_get_contents('https://drive.google.com/thumbnail?id=' . urlencode($fileId) . '&sz=' . urlencode($sz), false, $ctx);
        }

        if (!self::esImagen($bytes)) {
            return [null, $mime];
        }
        $disco->put($ruta, $bytes);
        return [$bytes, $mime];
    }

    /** ¿Son estos bytes una imagen de verdad? (y no, por ejemplo, una pagina de login) */
    private static function esImagen($bytes): bool
    {
        return is_string($bytes) && strlen($bytes) > 100 && @getimagesizefromstring($bytes) !== false;
    }
}
