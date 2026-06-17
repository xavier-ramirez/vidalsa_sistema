<?php

namespace App\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Convierte imágenes subidas (JPG/PNG/WebP) a WebP y las reescala a 8 cm de
 * ancho usando PHP GD. Compartido entre CaracteristicaModeloController y
 * EquipoAuxiliarController para evitar duplicación de la misma lógica.
 *
 * El reescalado a 8 cm reemplaza el paso manual que se hacía en PowerPoint:
 * las fotos quedan ligeras (pocos KB) sin perder nitidez en las tarjetas.
 */
trait ConvertsImageToWebp
{
    /**
     * Ancho objetivo de las fotos de catálogo.
     * 8 cm a 300 DPI = 8 / 2.54 * 300 ≈ 945 px (calidad de impresión, peso bajo).
     * Solo se REDUCE: imágenes más angostas se dejan tal cual (nunca se amplían).
     */
    private int $catalogoTargetWidth = 945;

    /**
     * Convierte la imagen a WebP (reescalada a 8 cm de ancho) en disco temporal.
     * @param  \Illuminate\Http\UploadedFile $file
     * @return array  ['file' => UploadedFile (original o convertido),
     *                 'tempPath' => string|null (path temp para cleanup)]
     */
    private function convertToWebp($file): array
    {
        $mime      = $file->getMimeType();
        $imagePath = $file->getRealPath();

        try {
            $image = match (true) {
                in_array($mime, ['image/jpeg', 'image/jpg'], true) => @imagecreatefromjpeg($imagePath),
                $mime === 'image/png'  => @imagecreatefrompng($imagePath),
                $mime === 'image/webp' && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($imagePath),
                default => null,
            };

            if ($image) {
                // Normaliza a truecolor y preserva transparencia (PNG con alpha).
                imagepalettetotruecolor($image);
                imagealphablending($image, false);
                imagesavealpha($image, true);

                $width      = imagesx($image);
                $needsScale = $width > $this->catalogoTargetWidth;

                // Fast-path: ya es WebP y no hay que reescalar → se sube el original
                // sin re-encodear (evita una pérdida de calidad innecesaria).
                if ($mime === 'image/webp' && !$needsScale) {
                    imagedestroy($image);
                    return ['file' => $file, 'tempPath' => null];
                }

                // Reescalado a 8 cm de ancho, conservando proporción y alpha.
                if ($needsScale) {
                    $height  = imagesy($image);
                    $newW    = $this->catalogoTargetWidth;
                    $newH    = (int) round($height * ($newW / $width));
                    $resized = imagecreatetruecolor($newW, $newH);
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);
                    imagedestroy($image);
                    $image = $resized;
                }

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

        // Fallback: si GD falla o el mime no es soportado, subir el original.
        return ['file' => $file, 'tempPath' => null];
    }
}
