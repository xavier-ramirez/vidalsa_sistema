<?php

namespace App\Services;

use Google\Client;
use Google\Service\Drive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
            self::$instance = new self();
            self::$instance->initialize();
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

    public function getStreamById($fileId)
    {
        $drive = $this->getDrive();
        $response = $drive->files->get($fileId, [
            'alt' => 'media',
            'supportsAllDrives' => true
        ]);
        return $response->getBody(); // This is a PSR-7 stream
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
}
