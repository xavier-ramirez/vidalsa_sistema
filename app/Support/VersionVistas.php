<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Huella de las VISTAS del servidor: la fecha de la plantilla más nueva.
 *
 * Con ella la pestaña abierta sabe si el servidor ya sirve otras vistas:
 *   · el <meta name="version-vistas"> del layout la estampa en cada carga completa;
 *   · navegacion.js la compara al navegar por SPA (recarga completa si cambió) y le
 *     pregunta cada tanto a /version-vistas para avisar "hay cambios nuevos" sin que
 *     el usuario tenga que navegar ni recargar a ciegas.
 *
 * Recorre resource_path('views') UNA vez cada 30 s (caché): el resto de las llamadas
 * es una lectura de caché. Si algo falla devuelve '' y nadie compara nada — ninguna
 * pantalla depende de esto para funcionar.
 */
class VersionVistas
{
    /** Segundos que se reutiliza la huella antes de volver a recorrer las vistas. */
    private const TTL = 30;

    public static function actual(): string
    {
        try {
            return (string) Cache::remember('version_vistas', self::TTL, function () {
                $max = 0;
                $archivos = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($archivos as $archivo) {
                    $max = max($max, $archivo->getMTime());
                }
                return (string) $max;
            });
        } catch (\Throwable $e) {
            return '';
        }
    }
}
