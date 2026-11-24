<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Foto del inventario rehecha por IA como foto de catálogo (fondo blanco, producto completo y
 * bien presentado) a partir de la foto que sube el usuario.
 *
 * Proveedor: Cloudflare Workers AI (`services.cloudflare_ai`), modelo FLUX.2 [klein] con la foto
 * como referencia. Tiene cupo GRATIS diario (10.000 neuronas ≈ 75 fotos). Sin cuenta ni token
 * configurados no se intenta nada. (Gemini se quitó el 24-11-2026: la clave es de plan gratis y
 * sus modelos de imagen tienen cupo 0 — nunca hizo una foto.)
 *
 * Nunca frena la subida: sin cupo, sin red o con una respuesta sin imagen devuelve null y quien
 * llama guarda la foto original. El motivo queda en el log. Tras un 429 (cupo del día gastado)
 * se deja de preguntar durante SIN_CUPO_MINUTOS, para que cada foto no espere solo para oír que
 * no; pasado ese rato se vuelve a probar solo. Un 503 (ocupado) se reintenta una vez.
 */
class FotoProductoIA
{
    private const API              = 'https://api.cloudflare.com/client/v4/accounts';
    private const CLAVE_SIN_CUPO   = 'cloudflare:imagen:sin_cupo';
    private const SIN_CUPO_MINUTOS = 60;
    /** FLUX.2 solo acepta imágenes de referencia de hasta 512x512. */
    private const LADO_ENTRADA = 512;
    /** Lado de la foto que se pide (cuadrada); al guardarla se achica a 600. */
    private const LADO_SALIDA = 1024;

    /** En inglés: FLUX lo entiende mejor. */
    private const INSTRUCCION = 'Professional e-commerce catalog product photo of the item in image 0: '
        . 'the exact same item, centered, complete and sharp, on a pure white background (#FFFFFF), soft even '
        . 'studio lighting and a subtle shadow below. Keep the same shape, colors, brand marks, labels, logos and '
        . 'printed text. Do not add objects, text or watermarks. If it is clothing or fabric (coveralls, shirts, '
        . 'pants, vests, gloves), show it neatly ironed, wrinkle-free and nicely laid out like in a store, keeping '
        . 'the same design, seams, reflective tapes, pockets and logos. Square framing.';

    /** La instrucción, con el producto que debe quedar en la foto si se conoce. */
    private static function instruccion(string $descripcion): string
    {
        // preg_replace da null con texto que no es UTF-8 válido: entonces va sin descripción.
        $descripcion = trim(preg_replace('/\s+/u', ' ', $descripcion) ?? '');
        if ($descripcion === '') return self::INSTRUCCION;
        return self::INSTRUCCION . ' The item is: "' . mb_substr($descripcion, 0, 300) . '". '
            . 'Keep ONLY that item and remove everything else.';
    }

    /** true si hay cuenta, token y modelo configurados (si no, ni se intenta). */
    public function activo(): bool
    {
        return trim((string) config('services.cloudflare_ai.account_id')) !== ''
            && trim((string) config('services.cloudflare_ai.token')) !== ''
            && trim((string) config('services.cloudflare_ai.modelo_imagen')) !== '';
    }

    /**
     * Foto tipo catálogo a partir del archivo $ruta, o null si no se pudo.
     *
     * $descripcion = nombre del producto en el inventario (y su categoría): le dice a la IA CUÁL
     * de los objetos de la foto es el producto, cuando hay más cosas alrededor (una mesa, otras
     * piezas, la mano). Vacía, la IA decide sola.
     *
     * @return array{binario: string, mime: string}|null
     */
    public function fondoBlanco(string $ruta, string $descripcion = ''): ?array
    {
        if (!$this->activo() || !is_readable($ruta) || Cache::has(self::CLAVE_SIN_CUPO)) {
            return null;
        }
        $entrada = $this->achicarParaReferencia($ruta);
        if ($entrada === null) return null;

        $url = self::API . '/' . trim((string) config('services.cloudflare_ai.account_id'))
             . '/ai/run/' . trim((string) config('services.cloudflare_ai.modelo_imagen'));

        try {
            $r = null;
            for ($intento = 1; $intento <= 2; $intento++) {
                $r = Http::timeout((int) config('services.cloudflare_ai.timeout', 60))
                    ->withToken(trim((string) config('services.cloudflare_ai.token')))
                    ->attach('input_image_0', $entrada, 'foto.png', ['Content-Type' => 'image/png'])
                    ->post($url, [
                        'prompt' => self::instruccion($descripcion),
                        'width'  => (string) self::LADO_SALIDA,
                        'height' => (string) self::LADO_SALIDA,
                    ]);
                if ($r->status() !== 503) break;
                if ($intento === 1) usleep(2_000_000);
            }
        } catch (\Throwable $e) {
            Log::warning('Foto de producto: la IA no respondió', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$r->successful()) {
            if ($r->status() === 429) {
                Cache::put(self::CLAVE_SIN_CUPO, true, now()->addMinutes(self::SIN_CUPO_MINUTOS));
            }
            Log::warning('Foto de producto: la IA rechazó la imagen', [
                'status' => $r->status(), 'error' => $r->json('errors.0.message') ?? mb_substr($r->body(), 0, 300),
            ]);
            return null;
        }

        // La API REST envuelve la salida en `result`; se acepta también sin envoltorio.
        $b64 = $r->json('result.image') ?? $r->json('image');
        $binario = is_string($b64) ? base64_decode($b64, true) : false;
        if ($binario === false || $binario === '') {
            Log::warning('Foto de producto: la IA respondió sin imagen');
            return null;
        }
        $info = @getimagesizefromstring($binario);
        return ['binario' => $binario, 'mime' => $info['mime'] ?? 'image/jpeg'];
    }

    /**
     * La foto achicada a 512 px de lado mayor (tope de FLUX.2 para las referencias), en PNG.
     * null si GD no la puede leer.
     */
    private function achicarParaReferencia(string $ruta): ?string
    {
        $img = @imagecreatefromstring((string) file_get_contents($ruta));
        if (!$img) {
            Log::warning('Foto de producto: no se pudo leer la foto para la IA');
            return null;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $escala = min(1, self::LADO_ENTRADA / max($w, $h));
        if ($escala < 1) {
            $nw = max(1, (int) round($w * $escala));
            $nh = max(1, (int) round($h * $escala));
            $chica = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($chica, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $chica;
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return ob_get_clean();
    }
}
