<?php

namespace App\Support;

use App\Services\CargaMasivaDocumentos;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Los PDF de la carga masiva que ya estan en Drive y faltan por LEER (el OCR de Drive, ~8 s cada
 * uno). La pantalla solo espera a la subida; la lectura sigue aqui, en segundo plano.
 *
 * UNA sola fila y UN solo lector a la vez (candado): treinta PDF leyendose cada uno por su lado
 * ocuparian a la vez casi todos los procesos de PHP del servidor (12) y el resto de la aplicacion
 * se quedaria esperando. El lector arranca despues de responder a la subida
 * (CargaMasivaDocumentosController::analizar) y, si algo se quedo sin leer —el servidor se
 * reinicio a medias—, lo recoge el programador (docs:carga-masiva-pendientes, cada minuto).
 *
 * Cada PDF es un par de archivos en storage/app/private/carga_masiva_cola: el PDF y su .json
 * (tipo pedido y lo que devolvio la subida). Al tomarlo, el .json pasa a .leyendo (rename es
 * atomico): dos lectores no leen nunca el mismo.
 */
class ColaCargaMasiva
{
    private const CARPETA = 'carga_masiva_cola';
    private const CANDADO = 'carga-masiva-lector';
    /** Un lector que murio a medias deja su .leyendo: pasado esto vuelve a la fila. */
    private const ABANDONADO_SEG = 900;

    /** Deja el PDF en la fila para leerlo despues. */
    public static function encolar(UploadedFile $archivo, string $tipo, array $subido): void
    {
        $disco = Storage::disk('local');
        $base = self::CARPETA . '/' . now()->format('YmdHis') . '_' . bin2hex(random_bytes(4));
        $disco->put($base . '.pdf', file_get_contents($archivo->getRealPath()));
        // El .json se escribe el ULTIMO: es el que dice que el par esta completo.
        // Quien lo subio: el BL se enlaza solo al leerlo, y el historial lo pone a su nombre.
        $disco->put($base . '.json', json_encode(['tipo' => $tipo, 'subido' => $subido, 'usuario' => auth()->id()]));
    }

    public static function hayPendientes(): bool
    {
        return (bool) glob(self::ruta('*.json')) || (bool) self::abandonados();
    }

    /**
     * Lee lo que haya en la fila, del mas viejo al mas nuevo, hasta vaciarla. Si ya hay otro
     * lector, no hace nada: ese recoge tambien lo nuevo, porque no para hasta que no queda nada.
     * Tras soltar el candado se vuelve a mirar: un PDF que entro justo entonces no se queda sin
     * lector.
     */
    public static function leer(CargaMasivaDocumentos $servicio): int
    {
        $leidos = 0;
        foreach (self::abandonados() as $leyendo) {
            @rename($leyendo, substr($leyendo, 0, -strlen('.leyendo')) . '.json');
        }
        // Tope de vueltas sin tomar nada: un .json que no se deja renombrar no deja al lector
        // dando vueltas para siempre (queda para el programador).
        $enBlanco = 0;
        while ($enBlanco < 3 && self::hayPendientes()) {
            $candado = Cache::lock(self::CANDADO, self::ABANDONADO_SEG);
            if (!$candado->get()) break;
            $tomados = 0;
            try {
                while ($leyendo = self::siguiente()) {
                    $tomados++;
                    $leidos += self::leerUno($servicio, $leyendo);
                }
            } finally {
                $candado->release();
            }
            $enBlanco = $tomados ? 0 : $enBlanco + 1;
        }
        return $leidos;
    }

    /** El .json mas viejo, ya tomado (renombrado a .leyendo), o null si no queda ninguno. */
    private static function siguiente(): ?string
    {
        $pendientes = glob(self::ruta('*.json')) ?: [];
        sort($pendientes);
        foreach ($pendientes as $json) {
            $leyendo = substr($json, 0, -strlen('.json')) . '.leyendo';
            if (@rename($json, $leyendo)) {
                @touch($leyendo);   // desde AHORA cuenta el tiempo para darlo por abandonado
                return $leyendo;
            }
        }
        return null;
    }

    private static function leerUno(CargaMasivaDocumentos $servicio, string $leyendo): int
    {
        $pdf = substr($leyendo, 0, -strlen('.leyendo')) . '.pdf';
        try {
            $datos = json_decode((string) @file_get_contents($leyendo), true);
            if (!is_array($datos) || !is_file($pdf)) {
                Log::error('Carga masiva: en la fila hay un PDF incompleto', ['archivo' => basename($leyendo)]);
                return 0;
            }
            set_time_limit(180);   // por PDF: leerlo con Drive tarda
            // El lector puede ser otro (el programador, o la subida de otra persona): lo que se
            // enlace al leerlo va a nombre de quien soltó el PDF.
            if (!empty($datos['usuario'])) Auth::onceUsingId($datos['usuario']);
            $servicio->leer(new UploadedFile($pdf, $datos['subido']['nombre'], 'application/pdf', null, true),
                $datos['tipo'], $datos['subido']);
            return 1;
        } catch (\Throwable $e) {
            // leer() ya recoge sus fallos; esto es lo que quede (el PDF de la fila ilegible...).
            Log::error('Carga masiva: no se pudo leer un PDF de la fila', ['archivo' => basename($pdf), 'error' => $e->getMessage()]);
            return 0;
        } finally {
            @unlink($leyendo);
            @unlink($pdf);
        }
    }

    /** Los .leyendo de un lector que murio a medias. */
    private static function abandonados(): array
    {
        return array_values(array_filter(glob(self::ruta('*.leyendo')) ?: [],
            fn ($f) => @filemtime($f) < time() - self::ABANDONADO_SEG));
    }

    private static function ruta(string $patron): string
    {
        return Storage::disk('local')->path(self::CARPETA . '/' . $patron);
    }
}
