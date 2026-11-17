<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Foto del inventario con fondo blanco, tipo catálogo de producto, hecha por Gemini (modelo de
 * imagen, `services.gemini.modelo_imagen`) a partir de la foto que sube el usuario.
 *
 * Nunca frena la subida: sin cupo, sin red o con una respuesta sin imagen devuelve null y quien
 * llama guarda la foto original. El motivo queda en el log.
 *
 * Cupo: la clave en plan gratis responde 429 a los modelos de imagen (cupo 0). Tras un 429 se
 * deja de preguntar durante SIN_CUPO_MINUTOS, para que cada foto no espere a Google solo para
 * oír que no; pasado ese rato se vuelve a probar sola (por si activaron la facturación). Un 503
 * (modelo ocupado) se reintenta una vez. Es independiente del cupo de LectorGemini: son
 * modelos distintos con contadores distintos en Google.
 */
class FotoProductoGemini
{
    private const API = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const CLAVE_SIN_CUPO   = 'gemini:imagen:sin_cupo';
    private const SIN_CUPO_MINUTOS = 60;

    private const INSTRUCCION = 'Convierte esta foto en una foto de producto para un catálogo de inventario: '
        . 'el mismo objeto, centrado, completo y nítido, sobre un fondo blanco puro (#FFFFFF), con luz '
        . 'uniforme y una sombra suave debajo. No cambies la forma, el color, las marcas, las etiquetas '
        . 'ni el texto del producto, no agregues objetos y no pongas textos ni marcas de agua. '
        . 'Encuadre cuadrado.';

    /** true si hay clave y modelo de imagen configurados (si no, ni se intenta). */
    public function activo(): bool
    {
        return trim((string) config('services.gemini.key')) !== ''
            && trim((string) config('services.gemini.modelo_imagen')) !== '';
    }

    /**
     * Imagen con fondo blanco a partir del archivo $ruta, o null si no se pudo.
     *
     * @return array{binario: string, mime: string}|null
     */
    public function fondoBlanco(string $ruta, string $mime): ?array
    {
        if (!$this->activo() || !is_readable($ruta) || Cache::has(self::CLAVE_SIN_CUPO)) {
            return null;
        }

        $url  = self::API . '/' . trim((string) config('services.gemini.modelo_imagen')) . ':generateContent';
        $body = [
            'contents' => [[
                'parts' => [
                    ['text' => self::INSTRUCCION],
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($ruta))]],
                ],
            ]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];

        try {
            $r = null;
            for ($intento = 1; $intento <= 2; $intento++) {
                $r = Http::timeout((int) config('services.gemini.timeout_imagen', 60))
                    ->withHeaders(['x-goog-api-key' => trim((string) config('services.gemini.key'))])
                    ->post($url, $body);
                if ($r->status() !== 503) break;
                if ($intento === 1) usleep(2_000_000);
            }
        } catch (\Throwable $e) {
            Log::warning('Foto de producto: Gemini no respondió', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$r->successful()) {
            if ($r->status() === 429) {
                Cache::put(self::CLAVE_SIN_CUPO, true, now()->addMinutes(self::SIN_CUPO_MINUTOS));
            }
            Log::warning('Foto de producto: Gemini rechazó la imagen', [
                'status' => $r->status(), 'error' => $r->json('error.message'),
            ]);
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
