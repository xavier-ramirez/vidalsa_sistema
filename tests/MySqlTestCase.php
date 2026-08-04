<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;

/**
 * Base para las pruebas que necesitan la base MySQL REAL en vez del sqlite :memory: que
 * fuerza phpunit.xml.
 *
 * Por qué contra la base real: estos casos verifican comportamiento que depende de los
 * DATOS y de la configuración de verdad —permisos del usuario, almacenes visibles, notas en
 * tránsito, seriales del catálogo—. Con una base vacía pasarían sin demostrar nada.
 *
 * DatabaseTransactions (NUNCA RefreshDatabase): envuelve cada test en una transacción y la
 * revierte al terminar. RefreshDatabase BORRARÍA la base de trabajo.
 */
abstract class MySqlTestCase extends TestCase
{
    use DatabaseTransactions;

    /** La transacción se abre sobre esta conexión, la misma que usan los tests. */
    protected $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        // phpunit.xml fuerza DB_CONNECTION=sqlite y DB_DATABASE=:memory:. Hay que revertir
        // AMBAS antes de arrancar la app: si solo se cambia la conexión, la de mysql hereda
        // el ":memory:" como nombre de base y falla al conectar.
        putenv('DB_CONNECTION=mysql');
        putenv('DB_DATABASE');
        unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';

        parent::setUp();

        config(['database.default' => 'mysql']);
    }
}
