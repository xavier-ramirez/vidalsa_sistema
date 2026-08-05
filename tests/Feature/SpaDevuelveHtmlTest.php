<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Guarda el contrato de la navegacion SPA: pedir un modulo tiene que devolver la
 * PAGINA, no datos.
 *
 * Nacio de una regresion real: al centralizar las llamadas en window.apiFetch se le
 * puso Accept: application/json por defecto a todas. Varios controladores (Almacen,
 * Equipo, EquipoAuxiliar, Traspaso...) hacen `if ($request->wantsJson())` para
 * devolver solo las filas de la tabla, asi que la navegacion empezo a recibir JSON,
 * loadPage no podia inyectarlo y caia a RECARGA COMPLETA: spinner, recarga, y recien
 * ahi el modulo. El mapa no se veia afectado porque su controlador no tiene esa rama.
 *
 * Las cabeceras de abajo son las mismas que manda navegacion.js. Si alguien vuelve a
 * meter un Accept: application/json por defecto en apiFetch, esta prueba lo caza.
 * Solo GET: no escribe nada.
 */
class SpaDevuelveHtmlTest extends MySqlTestCase
{
    /** Las mismas cabeceras que manda navegacion.js (CABECERAS_SPA). */
    private const CABECERAS_SPA = [
        'X-Requested-With' => 'XMLHttpRequest',
        'X-SPA-Navigate'   => '1',
        'Accept'           => 'text/html, application/xhtml+xml',
    ];

    private const RUTAS = [
        '/admin/almacen',
        '/admin/almacen/movimientos',
        '/admin/almacen/notas',
        '/admin/equipos',
        // /admin/equipos-auxiliares NO va: dejo de ser pantalla a proposito y su index
        // redirige a /admin/equipos salvo que se pida con wantsJson (es endpoint de datos
        // del partial _machinery). Ver EquipoAuxiliarController::index.
        '/admin/catalogo',
        '/admin/movilizaciones',
        '/admin/usuarios',
        '/admin/historial-documentos',
        '/mapa',
    ];

    private function admin(): Usuario
    {
        return Usuario::whereRaw("LOWER(PERMISOS) LIKE '%super.admin%'")->firstOrFail();
    }

    public function test_la_navegacion_spa_recibe_html_no_json(): void
    {
        $user = $this->admin();

        foreach (self::RUTAS as $ruta) {
            $res = $this->actingAs($user)->get($ruta, self::CABECERAS_SPA);

            $this->assertSame(200, $res->getStatusCode(), "{$ruta} no devolvio 200");

            $tipo = strtolower((string) $res->headers->get('content-type'));
            $this->assertStringNotContainsString(
                'application/json',
                $tipo,
                "{$ruta} devolvio JSON a la navegacion SPA: la pagina se recargaria entera"
            );
            $this->assertStringContainsString('text/html', $tipo, "{$ruta} no devolvio HTML");

            // Y es la PAGINA completa, no un fragmento de tabla ni un JSON disfrazado.
            $html = $res->getContent();
            $this->assertStringContainsString('</html>', $html, "{$ruta} no devolvio un documento completo");
            $this->assertStringContainsString('main-viewport', $html, "{$ruta} no trae el armazon de la app");
        }
    }

    /**
     * La contraparte: con Accept: application/json esos MISMOS endpoints siguen
     * devolviendo JSON (la paginacion y los filtros dependen de eso).
     */
    public function test_con_accept_json_siguen_devolviendo_json(): void
    {
        $user = $this->admin();

        foreach (['/admin/almacen', '/admin/equipos'] as $ruta) {
            $res = $this->actingAs($user)->get($ruta, [
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept'           => 'application/json',
            ]);
            $this->assertStringContainsString(
                'application/json',
                strtolower((string) $res->headers->get('content-type')),
                "{$ruta} dejo de devolver JSON: se romperia la paginacion y los filtros"
            );
        }
    }
}
