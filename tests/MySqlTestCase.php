<?php

namespace Tests;

use App\Models\Almacen;
use App\Models\Usuario;
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

    /**
     * Devolver la conexion a sqlite al terminar.
     *
     * putenv()/$_ENV son GLOBALES del proceso: sin esto, la conexion mysql que abre setUp se
     * quedaba puesta y TODA prueba que corriera despues —aunque herede de Tests\TestCase y
     * phpunit.xml pida sqlite— arrancaba contra la base de trabajo. Hoy no hay ningun caso
     * con RefreshDatabase activo, pero el dia que lo hubiera, `migrate:fresh` habria borrado
     * la base real. El control de Tests\TestCase::prohibirBaseReal es la segunda red.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE']   = $_SERVER['DB_DATABASE']   = ':memory:';
    }

    /**
     * Esta base SI usa la conexión real, a propósito: es la excepción para la que Tests\TestCase
     * deja el control redefinible. Aquí no hay riesgo porque DatabaseTransactions revierte
     * todo al terminar y NUNCA se usa RefreshDatabase (ver la nota de la clase).
     */
    protected function prohibirBaseReal(): void
    {
        // Sin control: la conexión real es justamente lo que se quiere aquí.
    }

    /** Un super.admin real (con equipos.create), global y sin cambio de clave pendiente. */
    protected function superAdminGlobal(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($u) => $u->can('super.admin') && $u->can('equipos.create') && Almacen::usuarioEsGlobal($u));
        $this->assertNotNull($u, 'Hace falta un super.admin global para probar.');
        return $u;
    }
}
