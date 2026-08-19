<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * La rejilla de campos de /admin/equipos/create.
 *
 * Lo que fija esta prueba: que cada campo sea hijo DIRECTO de .grid-responsive-5. Un
 * envoltorio mal cerrado (le paso a #colorWrap) no da error de PHP ni de Blade: la pagina
 * carga, pero los campos que quedan dentro de el ocupan UNA sola celda y salen apilados en
 * vertical en vez de repartidos por el ancho. Solo se ve mirando el HTML resultante.
 */
class FormularioEquipoRejillaTest extends MySqlTestCase
{
    private function html(): string
    {
        $user = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($user, 'Hace falta un usuario con super.admin.');

        $resp = $this->actingAs($user)->get('/admin/equipos/create');
        $resp->assertOk();

        return $resp->getContent();
    }

    public function test_los_campos_son_hijos_directos_de_la_rejilla(): void
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $this->html());
        $xp = new \DOMXPath($doc);

        // Cada campo que se oculta por modo tiene su propio envoltorio con id. Todos han de
        // colgar directamente de la rejilla: si uno cuelga de otro, comparten celda.
        foreach (['colorWrap', 'combustibleWrap', 'consumoWrap', 'linkGpsWrap', 'serialMotorWrap'] as $id) {
            $nodo = $xp->query("//div[@id='$id']")->item(0);
            $this->assertNotNull($nodo, "No existe #$id en el formulario.");

            $clasePadre = $nodo->parentNode->getAttribute('class');
            $this->assertStringContainsString(
                'grid-responsive-5',
                $clasePadre,
                "#$id no cuelga de la rejilla sino de <{$nodo->parentNode->nodeName} class=\"$clasePadre\">: "
                . 'comparte celda con otros campos y saldran apilados.'
            );
        }
    }

    /**
     * #colorWrap se traga seis campos si no cierra donde debe. Se comprueba por su cuenta
     * porque es el fallo concreto que se corrigio y el que mas facil vuelve a colarse.
     */
    public function test_color_no_se_traga_los_campos_siguientes(): void
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $this->html());
        $xp = new \DOMXPath($doc);

        $color = $xp->query("//div[@id='colorWrap']")->item(0);
        $this->assertNotNull($color);

        foreach (['combustibleWrap', 'consumoWrap', 'linkGpsWrap'] as $id) {
            $this->assertSame(0, $xp->query(".//div[@id='$id']", $color)->length,
                "#$id quedo DENTRO de #colorWrap: los campos saldran uno debajo del otro.");
        }

        // Y no debe llevar dentro ni el estatus ni el codigo interno.
        $this->assertSame(0, $xp->query(".//*[@id='codigo_interno']", $color)->length);
        $this->assertSame(0, $xp->query(".//*[@id='estadoSelect']", $color)->length);
    }
}
