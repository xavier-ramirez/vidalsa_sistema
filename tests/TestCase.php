<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seguro contra la configuracion CACHEADA.
     *
     * phpunit.xml fuerza DB_CONNECTION=sqlite y DB_DATABASE=:memory:, pero esas variables
     * se leen al construir la configuracion. Si existe bootstrap/cache/config.php (lo deja
     * un `php artisan config:cache`), la configuracion ya viene resuelta desde el .env y
     * phpunit.xml NO la puede cambiar: las pruebas apuntarian a la base MySQL de trabajo.
     *
     * Eso no es un detalle: hay casos con RefreshDatabase, que hace `migrate:fresh`. Con la
     * config cacheada, correr las pruebas BORRARIA la base real. Por eso esto para la
     * ejecucion entera en vez de dejarla seguir.
     *
     * Tests\MySqlTestCase es la excepcion legitima —usa la base real a proposito, dentro de
     * una transaccion que revierte— y por eso redefine este control.
     */
    /**
     * El control va AQUI y no en setUp() a proposito: createApplication() corre ANTES de
     * setUpTraits(), que es donde RefreshDatabase lanza su migrate:fresh. Comprobarlo en
     * setUp() llegaba tarde — la base ya estaria borrada.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $this->prohibirBaseReal();

        return $app;
    }

    protected function prohibirBaseReal(): void
    {
        $conexion = config('database.default');
        if ($conexion === 'sqlite') return;

        $this->fail(
            "Las pruebas estan apuntando a la conexion '{$conexion}', no a sqlite en memoria. "
            . "Casi seguro hay una configuracion cacheada: ejecuta `php artisan config:clear` y repite. "
            . "Seguir seria arriesgar la base de trabajo (hay casos con RefreshDatabase)."
        );
    }
}
