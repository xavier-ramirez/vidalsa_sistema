<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Convierte imágenes subidas (JPG/PNG) a WebP usando PHP GD.
 * Compartido entre CaracteristicaModeloController y EquipoAuxiliarController
 * para evitar duplicación de la misma lógica de conversión.
 */
trait ConvertsImageToWebp
{
    /**
     * Convierte la imagen a WebP en disco temporal.
     * @param  \Illuminate\Http\UploadedFile $file
     * @return array  ['file' => UploadedFile (original o convertido),
     *                 'tempPath' => string|null (path temp para cleanup)]
     */
    private function convertToWebp($file): array
    {
        $mime      = $file->getMimeType();
        $imagePath = $file->getRealPath();

        // Ya es WebP: nada que convertir
        if ($mime === 'image/webp') {
            return ['file' => $file, 'tempPath' => null];
        }

        try {
            $image = null;
            if (in_array($mime, ['image/jpeg', 'image/jpg'])) {
                $image = @imagecreatefromjpeg($imagePath);
            } elseif ($mime === 'image/png') {
                $image = @imagecreatefrompng($imagePath);
                if ($image) {
                    imagepalettetotruecolor($image);
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                }
            }

            if ($image) {
                $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('webp_') . '.webp';
                imagewebp($image, $tempPath, 85);
                imagedestroy($image);

                $uploadedFile = new \Illuminate\Http\UploadedFile(
                    $tempPath, 'converted.webp', 'image/webp', null, true
                );
                return ['file' => $uploadedFile, 'tempPath' => $tempPath];
            }
        } catch (\Throwable $e) {
            Log::warning('convertToWebp falló, se usará el archivo original: ' . $e->getMessage());
        }

        // Fallback: si GD falla o el mime no es soportado, subir el original
        return ['file' => $file, 'tempPath' => null];
    }
}
