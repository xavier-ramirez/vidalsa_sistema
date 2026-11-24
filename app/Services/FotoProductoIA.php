<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Foto del inventario rehecha por IA como foto de catálogo (fondo blanco, producto completo y
 * bien presentado) a partir de la foto que sube el usuario.
 *
 * Dos proveedores, en este orden:
 *   1. Cloudflare Workers AI (`services.cloudflare_ai`), modelo FLUX.2 [klein] con la foto como
 *      referencia. Tiene cupo GRATIS diario (10.000 neuronas ≈ 75 fotos), por eso va primero.
 *   2. Gemini (`services.gemini.modelo_imagen`). Con la clave actual (plan gratis) responde 429
 *      (cupo 0); queda de respaldo por si se activa la facturación.
 * Sin ninguno configurado no se intenta nada.
 *
 * Nunca frena la subida: sin cupo, sin red o con una respuesta sin imagen devuelve null y quien
 * llama guarda la foto original. El motivo queda en el log. Tras un 429 ese proveedor se deja de
 * preguntar durante SIN_CUPO_MINUTOS (que cada foto no espere solo para oír que no); pasado ese
 * rato se vuelve a probar solo. Un 503 (ocupado) se reintenta una vez.
 */
class FotoProductoIA
{
    private const GEMINI_API = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const CF_API     = 'https://api.cloudflare.com/client/v4/accounts';
    private const SIN_CUPO_GEMINI     = 'gemini:imagen:sin_cupo';
    private const SIN_CUPO_CLOUDFLARE = 'cloudflare:imagen:sin_cupo';
    private const SIN_CUPO_MINUTOS    = 60;
    /** FLUX.2 solo acepta imágenes de referencia de hasta 512x512. */
    private const CF_LADO_ENTRADA = 512;
    /** Lado de la foto que pide a Cloudflare (cuadrada); luego se achica a 600 al guardar. */
    private const CF_LADO_SALIDA = 1024;

    private const INSTRUCCION = 'Convierte esta foto en una foto de producto para un catálogo de inventario: '
        . 'el mismo objeto, centrado, completo y nítido, sobre un fondo blanco puro (#FFFFFF), con luz '
        . 'uniforme y una sombra suave debajo. No cambies la forma, el color, las marcas, las etiquetas '
        . 'ni el texto del producto, no agregues objetos y no pongas textos ni marcas de agua. '
        . 'Si es ropa o tela (bragas, camisas, pantalones, chalecos, guantes), preséntala '
        . 'planchada, sin arrugas y bien acomodada, como en una tienda, conservando el mismo diseño, '
        . 'costuras, cintas reflectivas, bolsillos y logos. Encuadre cuadrado.';

    /** FLUX entiende mejor el inglés: la misma instrucción para Cloudflare. */
    private const INSTRUCCION_FLUX = 'Professional e-commerce catalog product photo of the item in image 0: '
        . 'the exact same item, centered, complete and sharp, on a pure white background (#FFFFFF), soft even '
        . 'studio lighting and a subtle shadow below. Keep the same shape, colors, brand marks, labels, logos and '
        . 'printed text. Do not add objects, text or watermarks. If it is clothing or fabric (coveralls, shirts, '
        . 'pants, vests, gloves), show it neatly ironed, wrinkle-free and nicely laid out like in a store, keeping '
        . 'the same design, seams, reflective tapes, pockets and logos. Square framing.';

    /** La instrucción, con el producto que debe quedar en la foto si se conoce. */
    private static function instruccion(string $base, string $descripcion, string $soloEse): string
    {
        // preg_replace da null con texto que no es UTF-8 válido: entonces va sin descripción.
        $descripcion = trim(preg_replace('/\s+/u', ' ', $descripcion) ?? '');
        if ($descripcion === '') return $base;
        return $base . ' ' . sprintf($soloEse, mb_substr($descripcion, 0, 300));
    }

    private function cloudflareActivo(): bool
    {
        return trim((string) config('services.cloudflare_ai.account_id')) !== ''
            && trim((string) config('services.cloudflare_ai.token')) !== ''
            && trim((string) config('services.cloudflare_ai.modelo_imagen')) !== '';
    }

    private function geminiActivo(): bool
    {
        return trim((string) config('services.gemini.key')) !== ''
            && trim((string) config('services.gemini.modelo_imagen')) !== '';
    }

    /** true si hay algún proveedor configurado (si no, ni se intenta). */
    public function activo(): bool
    {
        return $this->cloudflareActivo() || $this->geminiActivo();
    }

    /**
     * Foto tipo catálogo a partir del archivo $ruta, o null si ningún proveedor pudo.
     *
     * $descripcion = nombre del producto en el inventario (y su categoría): le dice a la IA CUÁL
     * de los objetos de la foto es el producto, cuando hay más cosas alrededor (una mesa, otras
     * piezas, la mano). Vacía, la IA decide sola.
     *
     * @return array{binario: string, mime: string}|null
     */
    public function fondoBlanco(string $ruta, string $mime, string $descripcion = ''): ?array
    {
        if (!is_readable($ruta)) return null;
        if ($this->cloudflareActivo() && !Cache::has(self::SIN_CUPO_CLOUDFLARE)) {
            $img = $this->conCloudflare($ruta, $descripcion);
            if ($img) return $img;
        }
        if ($this->geminiActivo() && !Cache::has(self::SIN_CUPO_GEMINI)) {
            return $this->conGemini($ruta, $mime, $descripcion);
        }
        return null;
    }

    /** POST con un reintento si el proveedor está ocupado (503). null si no hubo respuesta. */
    private function enviar(string $proveedor, callable $peticion): ?Response
    {
        try {
            $r = null;
            for ($intento = 1; $intento <= 2; $intento++) {
                $r = $peticion();
                if ($r->status() !== 503) break;
                if ($intento === 1) usleep(2_000_000);
            }
            return $r;
        } catch (\Throwable $e) {
            Log::warning("Foto de producto: {$proveedor} no respondió", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** Respuesta fallida: anota y, si es falta de cupo, deja de preguntar un rato. */
    private function rechazo(string $proveedor, Response $r, string $claveSinCupo, $error): void
    {
        if ($r->status() === 429) {
            Cache::put($claveSinCupo, true, now()->addMinutes(self::SIN_CUPO_MINUTOS));
        }
        Log::warning("Foto de producto: {$proveedor} rechazó la imagen", ['status' => $r->status(), 'error' => $error]);
    }

    private function conCloudflare(string $ruta, string $descripcion): ?array
    {
        $entrada = $this->achicarParaReferencia($ruta);
        if ($entrada === null) return null;

        $url = self::CF_API . '/' . trim((string) config('services.cloudflare_ai.account_id'))
             . '/ai/run/' . trim((string) config('services.cloudflare_ai.modelo_imagen'));
        $prompt = self::instruccion(self::INSTRUCCION_FLUX, $descripcion, 'The item is: "%s". Keep ONLY that item and remove everything else.');

        $r = $this->enviar('Cloudflare', fn () => Http::timeout((int) config('services.cloudflare_ai.timeout', 60))
            ->withToken(trim((string) config('services.cloudflare_ai.token')))
            ->attach('input_image_0', $entrada, 'foto.png', ['Content-Type' => 'image/png'])
            ->post($url, [
                'prompt' => $prompt,
                'width'  => (string) self::CF_LADO_SALIDA,
                'height' => (string) self::CF_LADO_SALIDA,
            ]));
        if (!$r) return null;

        if (!$r->successful()) {
            $this->rechazo('Cloudflare', $r, self::SIN_CUPO_CLOUDFLARE, $r->json('errors.0.message') ?? $r->body());
            return null;
        }

        // La API REST envuelve la salida en `result`; se acepta también sin envoltorio.
        $b64 = $r->json('result.image') ?? $r->json('image');
        $binario = is_string($b64) ? base64_decode($b64, true) : false;
        if ($binario === false || $binario === '') {
            Log::warning('Foto de producto: Cloudflare respondió sin imagen');
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
            Log::warning('Foto de producto: no se pudo leer la foto para Cloudflare');
            return null;
        }
        [$w, $h] = [imagesx($img), imagesy($img)];
        $escala = min(1, self::CF_LADO_ENTRADA / max($w, $h));
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

    private function conGemini(string $ruta, string $mime, string $descripcion): ?array
    {
        $url  = self::GEMINI_API . '/' . trim((string) config('services.gemini.modelo_imagen')) . ':generateContent';
        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => self::instruccion(self::INSTRUCCION, $descripcion, 'El producto es: «%s». Deja SOLO ese producto y quita todo lo demás.')],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($ruta))]],
                ],
            ]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];

        $r = $this->enviar('Gemini', fn () => Http::timeout((int) config('services.gemini.timeout_imagen', 60))
            ->withHeaders(['x-goog-api-key' => trim((string) config('services.gemini.key'))])
            ->post($url, $body));
        if (!$r) return null;

        if (!$r->successful()) {
            $this->rechazo('Gemini', $r, self::SIN_CUPO_GEMINI, $r->json('error.message'));
            return null;
        }

        foreach ((array) $r->json('candidates.0.content.parts', []) as $parte) {
            $img = $parte['inlineData'] ?? $parte['inline_data'] ?? null;
            if (!empty($img['data'])) {
                $binario = base64_decode($img['data'], true);
                if ($binario !== false && $binario !== '') {
                    return ['binario' => $binario, 'mime' => $img['mimeType'] ?? $img['mime_type'] ?? 'image/png'];
                }
            }
        }

        Log::warning('Foto de producto: Gemini respondió sin imagen', ['motivo' => $r->json('candidates.0.finishReason')]);
        return null;
    }
}
