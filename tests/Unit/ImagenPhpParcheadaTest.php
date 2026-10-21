<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * El contenedor del servidor queda en un PHP con el parche de PHP2Shell (CVE-2026-109618 y
 * CVE-2026-109619): versión exacta en el Dockerfile (la etiqueta móvil dejaba reusar la imagen
 * vieja) y el progreso de subida apagado en docker/php.ini.
 */
class ImagenPhpParcheadaTest extends TestCase
{
    public function test_el_dockerfile_fija_un_php_83_con_el_parche(): void
    {
        $dockerfile = file_get_contents(dirname(__DIR__, 2) . '/Dockerfile');
        $this->assertMatchesRegularExpression('/^FROM php:(8\.3\.\d+)-fpm\s*$/m', $dockerfile, 'Versión exacta, no "8.3-fpm".');
        preg_match('/^FROM php:(8\.3\.\d+)-fpm/m', $dockerfile, $m);
        $this->assertTrue(version_compare($m[1], '8.3.36', '>='), "PHP {$m[1]} no trae el parche de PHP2Shell.");
    }

    public function test_el_php_ini_del_servidor_apaga_el_progreso_de_subida(): void
    {
        $ini = parse_ini_file(dirname(__DIR__, 2) . '/docker/php.ini');
        $this->assertSame('', $ini['session.upload_progress.enabled'] ?? null, 'session.upload_progress.enabled = Off');
    }
}
