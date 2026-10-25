<?php

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Las páginas que guarda el service worker traen los datos de quien las abrió: si entra otro
 * usuario en el mismo equipo se tiran (sw.js · DUENO); al salir se quedan, para entrar sin red. Se
 * prueba el sw.js real con Node (tests/sw_paginas_por_usuario.cjs).
 */
class ServiceWorkerPaginasPorUsuarioTest extends TestCase
{
    public function test_las_paginas_guardadas_son_solo_del_usuario_que_esta_dentro(): void
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            $this->markTestSkipped('Hace falta Node para correr sw.js.');
        }

        $p = new Process([$node, base_path('tests/sw_paginas_por_usuario.cjs')]);
        $p->setTimeout(60);
        $p->run();

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput() . $p->getOutput());
        $this->assertSame('ok', trim($p->getOutput()));
    }
}
