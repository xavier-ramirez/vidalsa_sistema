<?php

namespace Tests;

use App\Models\Usuario;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * /admin/equipos se filtra, cuenta y pinta en el navegador (public/js/maquinaria/equipos_local.js)
 * con la copia de GET /sync/equipos. Estas pruebas hacen lo mismo: bajan la copia como el usuario
 * y corren ese JS con Node (tests/equipos_local.cjs), así prueban el código que de verdad usa la
 * pantalla. Los filtros llevan los nombres de la URL (id_frente, id_tipo, search_query…).
 */
trait EquiposLocal
{
    /** Lo que pinta la pantalla: html, stats, distribution, frenteVenta, auxConsolidado… */
    protected function consultaLocal(Usuario $usuario, array $filtros = [], int $offset = 0): array
    {
        return $this->correrEquiposLocal($usuario, [
            'accion' => 'consultar',
            'filtros' => (object) $filtros,
            'offset' => $offset,
            'puede' => [
                'editar' => $usuario->can('equipos.edit'),
                'superAdmin' => $usuario->can('super.admin'),
                'subirPdf' => $usuario->can('user.edit'),
            ],
        ]);
    }

    /** Ids que manda el botón Excel: {equipos, auxiliares, modoAux}. */
    protected function idsExportarLocal(Usuario $usuario, array $filtros): array
    {
        return $this->correrEquiposLocal($usuario, ['accion' => 'ids', 'filtros' => (object) $filtros]);
    }

    /** ¿Cuentan esos filtros como filtro? Sin él la tabla abre vacía y el Excel no se baja. */
    protected function hayFiltroLocal(Usuario $usuario, array $filtros): bool
    {
        return (bool) $this->correrEquiposLocal($usuario, ['accion' => 'hayFiltro', 'filtros' => (object) $filtros]);
    }

    /** Nombres de la ventana «Titular» o «Aseguradora» con esos filtros. */
    protected function opcionesLocal(Usuario $usuario, string $campo, array $filtros = []): array
    {
        return $this->correrEquiposLocal($usuario, ['accion' => 'opciones', 'campo' => $campo, 'filtros' => (object) $filtros]);
    }

    /**
     * Filas de una lista de la Distribución (html de consultaLocal: distribution / auxDistribution):
     * [{nombre, total, vendidos, en_tramite, rotulo}], en el orden en que se pintan. `rotulo` es el
     * texto rojo del frente en venta ('VENDIDO', 'EN TRÁMITE DE VENTA') o null.
     */
    protected function filasDistribucion(?string $html): array
    {
        preg_match_all('/<li class="dist-fila"[^>]*>(.*?)<\/li>/s', (string) $html, $m);

        return array_map(static function (string $li) {
            preg_match('/<span class="dist-nombre">(.*?)<\/span><span class="dist-cant">(\d+)<\/span>/s', $li, $c);
            preg_match('/<small class="dist-rotulo">(.*?):<\/small>/s', $c[1], $r);
            preg_match('/<small class="dist-nota">(.*?)<\/small>/s', $c[1], $n);
            $nota = html_entity_decode($n[1] ?? '');
            $nombre = trim(preg_replace('/<small.*?<\/small>/s', '', $c[1]));

            return [
                'nombre' => html_entity_decode($nombre, ENT_QUOTES),
                'total' => (int) $c[2],
                'vendidos' => preg_match('/(\d+) vendidos?/', $nota, $v) ? (int) $v[1] : 0,
                'en_tramite' => preg_match('/(\d+) en trámite/', $nota, $t) ? (int) $t[1] : 0,
                'rotulo' => isset($r[1]) ? html_entity_decode($r[1]) : null,
            ];
        }, $m[1]);
    }

    private function correrEquiposLocal(Usuario $usuario, array $entrada): mixed
    {
        $node = (new ExecutableFinder())->find('node');
        if ($node === null) {
            $this->markTestSkipped('Hace falta Node para correr equipos_local.js.');
        }

        $entrada['copia'] = $this->actingAs($usuario)->getJson('/sync/equipos')->assertOk()->json();
        $p = new Process([$node, base_path('tests/equipos_local.cjs')]);
        $p->setInput(json_encode($entrada));
        $p->mustRun();

        return json_decode($p->getOutput(), true);
    }
}
