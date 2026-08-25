<?php

namespace Tests\Feature;

use App\Models\DocumentoAnexo;
use App\Models\Equipo;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Borrar UNA corrección anexa sin llevarse por delante el documento principal.
 *
 * El visor tenía un solo botón rojo y siempre apuntaba al principal (miraba
 * currentPdfContext, que no cambia al saltar de pestaña), así que se escondía al abrir
 * una corrección y no había forma de deshacerla. Ahora el botón borra lo que se está
 * viendo, y este endpoint es su otra mitad.
 *
 * El enlace de los anexos de prueba NO empieza por /storage/google/ a propósito: así el
 * método se salta la llamada a Drive y la prueba no depende de la red.
 */
class EliminarCorreccionAnexaTest extends MySqlTestCase
{
    private function equipoConDocumentacion(): Equipo
    {
        $equipo = Equipo::whereHas('documentacion')->first();
        $this->assertNotNull($equipo, 'hace falta al menos un equipo con documentación');

        return $equipo;
    }

    private function anexoDe(Equipo $equipo): DocumentoAnexo
    {
        return DocumentoAnexo::create([
            'ID_EQUIPO'  => $equipo->ID_EQUIPO,
            'TIPO_DOC'   => 'poliza',
            'LINK'       => '/pruebas/correccion-de-prueba.pdf',
            'ETIQUETA'   => 'Corrección de prueba',
            'created_at' => now(),
        ]);
    }

    private function superAdmin(): Usuario
    {
        $u = Usuario::all()->first(fn ($usr) => in_array(
            'super.admin',
            array_map('strtolower', is_array($usr->PERMISOS) ? $usr->PERMISOS : []),
            true
        ));
        $this->assertNotNull($u, 'hace falta un super.admin');

        return $u;
    }

    public function test_un_super_admin_borra_la_correccion_y_el_principal_sigue(): void
    {
        $equipo = $this->equipoConDocumentacion();
        $anexo  = $this->anexoDe($equipo);

        $linkPrincipalAntes = $equipo->documentacion->LINK_POLIZA_SEGURO;

        $this->actingAs($this->superAdmin())
            ->deleteJson("/admin/equipos/{$equipo->ID_EQUIPO}/anexos/{$anexo->ID_ANEXO}")
            ->assertOk()
            ->assertJson(['success' => true, 'doc_type' => 'poliza']);

        $this->assertNull(
            DocumentoAnexo::find($anexo->ID_ANEXO),
            'la corrección tenía que desaparecer'
        );
        $this->assertSame(
            $linkPrincipalAntes,
            $equipo->documentacion()->first()->LINK_POLIZA_SEGURO,
            'el documento principal NO se toca al borrar una corrección'
        );
    }

    public function test_no_se_puede_borrar_una_correccion_de_otro_equipo(): void
    {
        $equipo = $this->equipoConDocumentacion();
        $otro   = Equipo::where('ID_EQUIPO', '!=', $equipo->ID_EQUIPO)->first();
        $anexo  = $this->anexoDe($equipo);

        $this->actingAs($this->superAdmin())
            ->deleteJson("/admin/equipos/{$otro->ID_EQUIPO}/anexos/{$anexo->ID_ANEXO}")
            ->assertNotFound();

        $this->assertNotNull(
            DocumentoAnexo::find($anexo->ID_ANEXO),
            'pedirla por el equipo equivocado no puede borrarla igual'
        );
    }

    public function test_sin_super_admin_no_se_borra(): void
    {
        $equipo = $this->equipoConDocumentacion();
        $anexo  = $this->anexoDe($equipo);

        $raso = Usuario::all()->first(function ($usr) {
            $p = array_map('strtolower', is_array($usr->PERMISOS) ? $usr->PERMISOS : []);
            return !in_array('super.admin', $p, true);
        });
        $this->assertNotNull($raso, 'hace falta un usuario sin super.admin');

        $this->actingAs($raso)
            ->deleteJson("/admin/equipos/{$equipo->ID_EQUIPO}/anexos/{$anexo->ID_ANEXO}")
            ->assertForbidden();

        $this->assertNotNull(
            DocumentoAnexo::find($anexo->ID_ANEXO),
            'sin permiso la corrección se queda donde está'
        );
    }
}
