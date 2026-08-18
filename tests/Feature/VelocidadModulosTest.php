<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Cuánto tarda el SERVIDOR en abrir cada módulo, y con cuántas consultas.
 *
 *   php vendor/bin/phpunit tests/Feature/VelocidadModulosTest.php
 *
 * Mide lo que de verdad cuesta: el tiempo dentro de PHP y el número de consultas a la base.
 * Deja fuera la red y el navegador a propósito — eso ya se midió aparte y no es lo que se
 * está afinando aquí.
 *
 * El umbral NO busca clavar milisegundos (dependen de la máquina y de si hay OPcache): busca
 * cazar el desastre que se cuela sin que nadie lo note, que es el N+1 — una consulta por
 * fila. Un módulo que abre con 400 consultas tiene un N+1, no "una máquina lenta", y eso sí
 * se puede afirmar en cualquier equipo.
 */
class VelocidadModulosTest extends MySqlTestCase
{
    /** Ninguna pantalla debería necesitar más de esto para pintarse. */
    private const TOPE_CONSULTAS = 120;

    private function usuarioConTodo(): Usuario
    {
        $user = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($user, 'Hace falta un usuario con super.admin para medir.');

        return $user;
    }

    public function test_cada_modulo_abre_sin_consultas_de_mas(): void
    {
        $user = $this->usuarioConTodo();

        $modulos = [
            'Menú (tablero)'      => '/menu',
            'Equipos'             => '/admin/equipos',
            'Equipos auxiliares'  => '/admin/equipos-auxiliares',
            'Almacén'             => '/admin/almacen',
            'Almacén · movimien.' => '/admin/almacen/movimientos',
            'Almacén · notas'     => '/admin/almacen/notas',
            'Almacén · recepción' => '/admin/almacen/recepcion',
            'Movilizaciones'      => '/admin/movilizaciones',
            'Fallas'              => '/admin/fallas',
            'Consumibles'         => '/admin/consumibles',
            'Catálogo'            => '/admin/catalogo',
            'Usuarios'            => '/admin/usuarios',
            'Mapa'                => '/mapa',
        ];

        $filas = [];
        $culpables = [];

        foreach ($modulos as $nombre => $ruta) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $t0 = microtime(true);
            $resp = $this->actingAs($user)->get($ruta);
            $ms = (microtime(true) - $t0) * 1000;
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            // 404 = la ruta no existe en esta instalación: se informa y no se juzga.
            $estado = $resp->getStatusCode();
            $consultas = count($log);
            $sql = 0.0;
            $peor = ['t' => 0.0, 'q' => ''];
            foreach ($log as $q) {
                $sql += $q['time'];
                if ($q['time'] > $peor['t']) $peor = ['t' => $q['time'], 'q' => $q['query']];
            }

            $filas[] = [
                'modulo'    => $nombre,
                'estado'    => $estado,
                'ms'        => round($ms),
                'sql_ms'    => round($sql),
                'consultas' => $consultas,
                'peor'      => round($peor['t']) . ' ms · ' . mb_substr(preg_replace('/\s+/', ' ', $peor['q']), 0, 90),
            ];

            if ($estado === 200 && $consultas > self::TOPE_CONSULTAS) {
                $culpables[] = sprintf('%s: %d consultas', $nombre, $consultas);
            }
        }

        // Tabla legible en la salida de la prueba (phpunit no la imprime si todo pasa,
        // por eso va a STDOUT directamente).
        fwrite(STDOUT, "\n" . str_pad('MÓDULO', 22) . str_pad('HTTP', 6) . str_pad('TOTAL', 9)
            . str_pad('SQL', 8) . str_pad('CONSULTAS', 11) . "CONSULTA MÁS LENTA\n");
        fwrite(STDOUT, str_repeat('─', 130) . "\n");
        foreach ($filas as $f) {
            fwrite(STDOUT, str_pad($f['modulo'], 22)
                . str_pad((string) $f['estado'], 6)
                . str_pad($f['ms'] . ' ms', 9)
                . str_pad($f['sql_ms'] . ' ms', 8)
                . str_pad((string) $f['consultas'], 11)
                . $f['peor'] . "\n");
        }
        fwrite(STDOUT, str_repeat('─', 130) . "\n");

        $this->assertSame([], $culpables,
            'Módulos con demasiadas consultas para abrir (huele a N+1): ' . implode(' | ', $culpables));
    }
}
