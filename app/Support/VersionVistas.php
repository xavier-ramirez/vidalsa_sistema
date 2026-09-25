<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Huella de lo que sirve el servidor: la fecha del archivo más nuevo entre las VISTAS y el
 * JS/CSS propio (public/js, public/css).
 *
 * El JS y el CSS entran porque la navegación SPA recibe solo el contenido del módulo (ver
 * layouts/estructura_base): ya no trae los <script src> del layout para comparar sus ?v=,
 * así que esta huella es la que avisa de que hubo un despliegue de cualquiera de las tres
 * cosas.
 *
 * Con ella la pestaña abierta sabe si el servidor ya sirve otras vistas:
 *   · el <meta name="version-vistas"> del layout la estampa en cada carga completa;
 *   · navegacion.js la compara al navegar por SPA (recarga completa si cambió) y le
 *     pregunta cada tanto a /version-vistas para avisar "hay cambios nuevos" sin que
 *     el usuario tenga que navegar ni recargar a ciegas.
 *
 * Recorre esas carpetas UNA vez cada 30 s (caché): el resto de las llamadas
 * es una lectura de caché. Si algo falla devuelve '' y nadie compara nada — ninguna
 * pantalla depende de esto para funcionar.
 */
class VersionVistas
{
    /** Segundos que se reutiliza la huella antes de volver a recorrer las carpetas. */
    private const TTL = 30;

    public static function actual(): string
    {
        try {
            return (string) Cache::remember('version_vistas', self::TTL, function () {
                $max = 0;
                foreach ([resource_path('views'), public_path('js'), public_path('css')] as $dir) {
                    if (!is_dir($dir)) continue;
                    $archivos = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                    );
                    foreach ($archivos as $archivo) {
                        $max = max($max, $archivo->getMTime());
                    }
                }
                return (string) $max;
            });
        } catch (\Throwable $e) {
            return '';
        }
    }
}
