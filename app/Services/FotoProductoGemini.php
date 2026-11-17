<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Foto del inventario con fondo blanco, tipo catálogo de producto, hecha por Gemini (modelo de
 * imagen, `services.gemini.modelo_imagen`) a partir de la foto que sube el usuario.
 *
 * Nunca frena la subida: sin clave, sin cupo (la cuenta en plan gratis responde 429 a los
 * modelos de imagen), sin red o con una respuesta sin imagen devuelve null y quien llama
 * guarda la foto original. El motivo queda en el log.
 */
class FotoProductoGemini
{
    private const API = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const INSTRUCCION = 'Convierte esta foto en una foto de producto para un catálogo de inventario: '
        . 'el mismo objeto, centrado, completo y nítido, sobre un fondo blanco puro (#FFFFFF), con luz '
        . 'uniforme y una sombra suave debajo. No cambies la forma, el color, las marcas, las etiquetas '
        . 'ni el texto del producto, no agregues objetos y no pongas textos ni marcas de agua. '
        . 'Encuadre cuadrado.';

    /**
     * Imagen con fondo blanco a partir del archivo $ruta, o null si no se pudo.
     *
     * @return array{binario: string, mime: string}|null
     */
    public function fondoBlanco(string $ruta, string $mime): ?array
    {
        $clave  = trim((string) config('services.gemini.key'));
        $modelo = (string) config('services.gemini.modelo_imagen');
        if ($clave === '' || $modelo === '' || !is_readable($ruta)) {
            return null;
        }

        try {
            $r = Http::timeout((int) config('services.gemini.timeout_imagen', 60))
                ->withHeaders(['x-goog-api-key' => $clave])
                ->post(self::API . '/' . $modelo . ':generateContent', [
                    'contents' => [[
                        'parts' => [
                            ['text' => self::INSTRUCCION],
                            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode(file_get_contents($ruta))]],
                        ],
                    ]],
                    'generationConfig' => ['responseModalities' => ['IMAGE']],
                ]);
        } catch (\Throwable $e) {
            Log::warning('Foto de producto: Gemini no respondió', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$r->successful()) {
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
