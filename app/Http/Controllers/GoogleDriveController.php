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
            // Protección contra Path Traversal limpiando el ID de Google
            $fileId = basename($path);
            $cachePath = 'google_cache/' . $fileId;

            // 1. CHECK LOCAL CACHE (Fastest, Offline-capable)
            if (Storage::disk('local')->exists($cachePath)) {
                $fullPath = Storage::disk('local')->path($cachePath);
                $mime = mime_content_type($fullPath);
                
                $version = request()->query('v', '0');
                $etag = md5($fileId . '-' . $version);
                
                return response()->file($fullPath, [
                    'Content-Type' => $mime,
                    'Cache-Control' => 'public, max-age=2592000, must-revalidate',
                    'ETag' => '"' . $etag . '"',
                    'Pragma' => 'public',
                    'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 2592000),
                ]);
            }

            // 2. FETCH FROM GOOGLE (If not in cache)
            $driveService = \App\Services\GoogleDriveService::getInstance();
            
            // Metadata (Cached for 1 day)
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

            // 3. DOWNLOAD & SAVE TO LOCAL CACHE
            $stream = $driveService->getStreamById($fileId);
            Storage::disk('local')->put($cachePath, $stream);
            
            // 4. SERVE FROM LOCAL CACHE
            $localPath = Storage::disk('local')->path($cachePath);
            
            $version = request()->query('v', '0');
            $etag = md5($fileId . '-' . $version);
            
            return response()->file($localPath, [
                'Content-Type' => $metadata['mime'],
                'Cache-Control' => 'public, max-age=2592000, must-revalidate',
                'ETag' => '"' . $etag . '"',
                'Pragma' => 'public',
                'Expires' => gmdate('D, d M Y H:i:s \G\M\T', time() + 2592000),
            ]);

        } catch (\Exception $e) {
            Log::error('Google Drive fetch error: ' . $e->getMessage());
            abort(404, 'Imagen no disponible');
        }
    }
}
