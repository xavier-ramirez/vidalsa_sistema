<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Frentes VENDIDO y TRÁMITE DE VENTA: como ESPECIAL, quedan FUERA de la flota
 * (FrenteTrabajo::TIPOS_FUERA_DE_FLOTA) y, en Equipos, se resaltan: etiqueta junto al nombre
 * del frente (también al buscar un equipo por serial) y aviso al entrar al frente.
 * Corre en la transacción de la prueba.
 */
class FrentesVentaTest extends MySqlTestCase
{
    /** Frente del tipo dado, creado por el formulario, con un equipo real movido adentro. */
    private function frenteConEquipo(string $tipo): array
    {
        $nombre = $tipo . ' PRUEBA ' . strtoupper(Str::random(6));
        $this->actingAs($this->superAdminGlobal())->postJson('/admin/frentes?json=true', [
            'NOMBRE_FRENTE' => $nombre, 'UBICACION' => 'EL TIGRE', 'TIPO_FRENTE' => $tipo, 'ESTATUS_FRENTE' => 'ACTIVO',
        ])->assertOk()->assertJson(['success' => true]);

        $frente = FrenteTrabajo::where('NOMBRE_FRENTE', $nombre)->firstOrFail();
        $equipo = Equipo::whereNotNull('ID_FRENTE_ACTUAL')->whereNotNull('SERIAL_CHASIS')->where('SERIAL_CHASIS', '<>', '')
            ->whereNotIn('ID_FRENTE_ACTUAL', FrenteTrabajo::idsFueraDeFlota())->firstOrFail();
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['ID_FRENTE_ACTUAL' => $frente->ID_FRENTE]);

        return [$frente, $equipo];
    }

    public function test_vendido_y_tramite_quedan_fuera_de_la_flota(): void
    {
        foreach ([FrenteTrabajo::TIPO_VENDIDO, FrenteTrabajo::TIPO_TRAMITE_VENTA] as $tipo) {
            [$frente, $equipo] = $this->frenteConEquipo($tipo);
            $this->assertTrue(FrenteTrabajo::esFueraDeFlota($frente->ID_FRENTE), "$tipo cuenta como fuera de la flota.");
            $this->assertFalse(Equipo::excludeFueraDeFlota()->where('ID_EQUIPO', $equipo->ID_EQUIPO)->exists(),
                "$tipo: su equipo no entra en los listados y totales de la flota.");
        }
        $especial = FrenteTrabajo::where('TIPO_FRENTE', 'ESPECIAL')->value('ID_FRENTE');
        if ($especial) {
            $this->assertTrue(FrenteTrabajo::esFueraDeFlota($especial), 'ESPECIAL sigue igual que antes.');
        }
    }

    public function test_al_entrar_a_un_frente_vendido_se_ve_el_aviso_rojo_y_la_etiqueta(): void
    {
        [$frente, $equipo] = $this->frenteConEquipo(FrenteTrabajo::TIPO_VENDIDO);
        $admin = $this->superAdminGlobal();

        $json = $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk();
        $this->assertSame('vendido', $json->json('frenteVenta.clase'));
        $this->assertSame($frente->NOMBRE_FRENTE, $json->json('frenteVenta.nombre'));
        $this->assertSame('Frente de activos vendidos', $json->json('frenteVenta.titulo'));
        $this->assertStringContainsString('etiqueta-venta--vendido">VENDIDO<', (string) $json->json('html'));

        $html = (string) $this->actingAs($admin)->get('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="eqAvisoVenta" class="aviso-venta aviso-venta--vendido" role="status"\s*>/', $html, 'Aviso visible, en rojo.');
    }

    public function test_al_entrar_a_un_frente_en_tramite_se_ve_el_aviso_amarillo_y_la_etiqueta(): void
    {
        [$frente] = $this->frenteConEquipo(FrenteTrabajo::TIPO_TRAMITE_VENTA);
        $admin = $this->superAdminGlobal();

        $json = $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk();
        $this->assertSame('tramite', $json->json('frenteVenta.clase'));
        $this->assertSame('Frente en trámite de venta', $json->json('frenteVenta.titulo'));
        $this->assertStringContainsString('etiqueta-venta--tramite">EN TRÁMITE DE VENTA<', (string) $json->json('html'));
    }

    public function test_buscar_por_serial_muestra_la_etiqueta_del_frente(): void
    {
        [, $equipo] = $this->frenteConEquipo(FrenteTrabajo::TIPO_VENDIDO);

        $json = $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/equipos?search_query=' . urlencode($equipo->SERIAL_CHASIS))->assertOk();

        $this->assertStringContainsString('etiqueta-venta--vendido">VENDIDO<', (string) $json->json('html'),
            'Buscando por serial (todos los frentes), la fila dice que el equipo está VENDIDO.');
        $this->assertNull($json->json('frenteVenta'), 'Sin frente elegido no hay aviso de frente.');
    }

    public function test_en_otro_frente_el_aviso_queda_escondido(): void
    {
        $normal = FrenteTrabajo::whereNotIn('TIPO_FRENTE', FrenteTrabajo::TIPOS_FUERA_DE_FLOTA)->value('ID_FRENTE');
        $admin = $this->superAdminGlobal();

        $this->assertNull($this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $normal)->assertOk()->json('frenteVenta'));
        $html = (string) $this->actingAs($admin)->get('/admin/equipos?id_frente=' . $normal)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="eqAvisoVenta" class="aviso-venta aviso-venta--tramite" role="status"\s+hidden/', $html);
    }

    public function test_el_formulario_ofrece_las_dos_opciones_con_su_nombre(): void
    {
        $admin = $this->superAdminGlobal();
        $html = (string) $this->actingAs($admin)->get('/admin/frentes/create')->assertOk()->getContent();
        $this->assertStringContainsString("selectOption('tipoSelect', 'VENDIDO', 'ACTIVOS VENDIDOS', 'tipo')", $html);
        $this->assertStringContainsString("selectOption('tipoSelect', 'TRAMITE_VENTA', 'TRÁMITE DE VENTA', 'tipo')", $html);

        // Un frente en trámite muestra su tipo con el nombre en pantalla, no el código guardado.
        [$frente] = $this->frenteConEquipo(FrenteTrabajo::TIPO_TRAMITE_VENTA);
        $edit = (string) $this->actingAs($admin)->get('/admin/frentes/' . $frente->ID_FRENTE . '/edit')->assertOk()->getContent();
        $this->assertStringContainsString('<span id="label_tipo">TRÁMITE DE VENTA</span>', $edit);
    }

    public function test_cambiar_el_tipo_desde_equipos(): void
    {
        [$frente] = $this->frenteConEquipo('OPERACION');
        $admin = $this->superAdminGlobal();

        $this->actingAs($admin)->patchJson(route('frentes.cambiarTipo', $frente->ID_FRENTE), ['TIPO_FRENTE' => FrenteTrabajo::TIPO_VENDIDO])
            ->assertOk()->assertJson(['success' => true, 'tipo' => 'VENDIDO'])
            ->assertJsonPath('etiqueta', '<span class="etiqueta-venta etiqueta-venta--vendido">VENDIDO</span>');
        $this->assertSame('VENDIDO', $frente->fresh()->TIPO_FRENTE);
        $this->assertTrue(FrenteTrabajo::esFueraDeFlota($frente->ID_FRENTE), 'Sale de la flota al momento (caché invalidada).');

        $this->actingAs($admin)->patchJson(route('frentes.cambiarTipo', $frente->ID_FRENTE), ['TIPO_FRENTE' => 'OPERACION'])
            ->assertOk()->assertJsonPath('etiqueta', '');
        $this->assertFalse(FrenteTrabajo::esFueraDeFlota($frente->ID_FRENTE), 'Y vuelve a la flota.');

        $this->actingAs($admin)->patchJson(route('frentes.cambiarTipo', $frente->ID_FRENTE), ['TIPO_FRENTE' => 'NO_EXISTE'])
            ->assertStatus(422)->assertJsonValidationErrors('TIPO_FRENTE');
    }

    /** Usuario de prueba (no super.admin) con los permisos dados; LOCAL si trae frentes asignados. */
    private function usuarioCon(string $permisos, ?string $frentesAsignados = null): \App\Models\Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO' => 'PRUEBA TIPO FRENTE', 'CORREO_ELECTRONICO' => 'prueba.tipofrente.' . Str::random(6) . '@local.test',
            'PASSWORD_HASH' => bcrypt('x'), 'ID_ROL' => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS' => $frentesAsignados ? 2 : 1, 'ID_FRENTE_ASIGNADO' => $frentesAsignados,
            'NIVEL_ACCESO_ALMACEN' => 1, 'ESTATUS' => 'ACTIVO', 'REQUIERE_CAMBIO_CLAVE' => 0, 'PERMISOS' => $permisos,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return \App\Models\Usuario::find($id);
    }

    public function test_cambiar_el_tipo_pide_el_permiso_actualizar_informacion(): void
    {
        [$frente] = $this->frenteConEquipo('OPERACION');
        $url = route('frentes.cambiarTipo', $frente->ID_FRENTE);

        // Sin "Actualizar Información" (user.edit): ni la opción ni la ruta.
        $sin = $this->usuarioCon('equipos.edit');
        $this->actingAs($sin)->patchJson($url, ['TIPO_FRENTE' => 'VENDIDO'])->assertForbidden();
        $this->assertSame('OPERACION', $frente->fresh()->TIPO_FRENTE);
        $this->assertStringNotContainsString('id="btnTipoFrente"',
            (string) $this->actingAs($sin)->get('/admin/equipos')->assertOk()->getContent(), 'Sin el permiso ni siquiera ve la opción.');

        // Con el permiso basta: no hace falta super.admin. Y el modal trae el tipo de cada frente.
        $con = $this->usuarioCon('user.edit');
        $html = (string) $this->actingAs($con)->get('/admin/equipos')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="btnTipoFrente"[^>]*>/', $html);
        $this->assertStringContainsString('data-value="' . $frente->ID_FRENTE . '" data-tipo="OPERACION"', $html);
        $this->actingAs($con)->patchJson($url, ['TIPO_FRENTE' => 'VENDIDO'])->assertOk()->assertJson(['success' => true, 'tipo' => 'VENDIDO']);
        $this->assertSame('VENDIDO', $frente->fresh()->TIPO_FRENTE);

        // LOCAL con el permiso: solo sus frentes, uno ajeno no existe para él.
        $otro = FrenteTrabajo::where('ID_FRENTE', '<>', $frente->ID_FRENTE)->value('ID_FRENTE');
        $local = $this->usuarioCon('user.edit', (string) $otro);
        $this->actingAs($local)->patchJson($url, ['TIPO_FRENTE' => 'OPERACION'])->assertNotFound();
        $this->assertSame('VENDIDO', $frente->fresh()->TIPO_FRENTE);
    }

    public function test_la_opcion_del_menu_se_ve_siempre_con_su_modal(): void
    {
        $admin = $this->superAdminGlobal();
        $frente = FrenteTrabajo::value('ID_FRENTE');

        foreach (['/admin/equipos', '/admin/equipos?id_frente=' . $frente] as $url) {
            $html = (string) $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('/id="btnTipoFrente"[^>]*>/', $html, "Se ve en $url.");
            $this->assertDoesNotMatchRegularExpression('/id="btnTipoFrente"[^>]*\s+hidden\s*>/', $html);
            $this->assertStringContainsString('id="tipoFrenteFrente"', $html, 'El modal trae su buscador de frente.');
            $this->assertStringContainsString('data-value="TRAMITE_VENTA"', $html);
        }
    }

    /**
     * Los AUXILIARES de un frente vendido siguen la misma regla que los equipos: fuera de los
     * totales con "todos los frentes", a la vista al entrar al frente o al buscarlos, con la
     * etiqueta sobre la foto y con el aviso también cuando solo se ven auxiliares.
     */
    public function test_los_auxiliares_del_frente_vendido_siguen_la_misma_regla(): void
    {
        [$frente, $equipo] = $this->frenteConEquipo(FrenteTrabajo::TIPO_VENDIDO);
        $admin = $this->superAdminGlobal();
        $totalAux = fn () => (int) $this->actingAs($admin)->getJson('/admin/equipos?id_frente=all')->assertOk()->json('auxConsolidado.total');

        $antes = $totalAux();
        $serial = 'AUXVENTA' . strtoupper(Str::random(6));
        \App\Models\EquipoAuxiliar::create([
            'TIPO' => 'PRUEBAVENTA', 'MARCA' => 'PRUEBA', 'MODELO' => 'VENTA', 'SERIAL' => $serial,
            'ESTADO_OPERATIVO' => 'OPERATIVO', 'ID_FRENTE_ACTUAL' => $frente->ID_FRENTE, 'ID_EQUIPO_HOST' => $equipo->ID_EQUIPO,
        ]);
        $this->assertSame($antes, $totalAux(), 'Con "todos los frentes" el auxiliar del frente vendido no cuenta.');

        $html = (string) $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk()->json('html');
        $this->assertStringContainsString($serial, $html, 'Dentro del frente sí sale.');
        $this->assertSame(2, substr_count($html, 'etiqueta-venta--vendido">VENDIDO<'), 'Etiqueta en el equipo y en el auxiliar.');

        $this->assertStringContainsString($serial, (string) $this->actingAs($admin)
            ->getJson('/admin/equipos?search_query=' . $serial)->assertOk()->json('html'), 'Buscándolo también sale.');

        $soloAux = $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE . '&id_tipo=tipo_aux:PRUEBAVENTA')->assertOk();
        $this->assertSame('aux', $soloAux->json('mode'));
        $this->assertSame('vendido', $soloAux->json('frenteVenta.clase'), 'Solo auxiliares: el aviso también sale.');
        $this->assertStringContainsString('etiqueta-venta--vendido">VENDIDO<', (string) $soloAux->json('html'));

        // Anclajes (el modal de Equipos, que trae también los auxiliares): lo mismo (sin frente,
        // fuera; con su frente, dentro).
        $anclados = fn (string $qs) => collect($this->actingAs($admin)->getJson(route('equipos.getAnchors') . $qs)->assertOk()->json('aux'))
            ->flatMap(fn ($host) => collect($host['auxes'])->pluck('serial'));
        $this->assertNotContains($serial, $anclados(''));
        $this->assertContains($serial, $anclados('?frente_id=' . $frente->ID_FRENTE));
        // Con "todos los auxiliares" en el tipo: también (antes buscaba un TIPO llamado 'all').
        $this->assertNotContains($serial, $anclados('?id_tipo=tipo_aux:all'));
        $this->assertContains($serial, $anclados('?frente_id=' . $frente->ID_FRENTE . '&id_tipo=tipo_aux:all'));
    }

    /**
     * Filtrando por TIPO con "todos los frentes" el listado trae también lo de frentes en venta:
     * el TOTAL cuenta solo lo disponible y "Ubicación por Frente" muestra el frente vendido en
     * rojo (con su cantidad). Dentro del frente vendido, sus cifras son las suyas.
     */
    public function test_por_tipo_el_total_no_cuenta_lo_vendido_y_el_frente_sale_en_rojo(): void
    {
        [$frente, $equipo] = $this->frenteConEquipo(FrenteTrabajo::TIPO_VENDIDO);
        DB::table('equipos')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->update(['ESTADO_OPERATIVO' => 'OPERATIVO']);
        $admin = $this->superAdminGlobal();
        $tipo = $equipo->id_tipo_equipo;

        $esperado = $admin->aplicarScopeFrentesEquipos(Equipo::where('id_tipo_equipo', $tipo))
            ->where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')->excludeEnVenta()->count();
        $j = $this->actingAs($admin)->getJson('/admin/equipos?id_tipo=' . $tipo)->assertOk();
        $this->assertSame($esperado, $j->json('stats.total'), 'El TOTAL es lo disponible: sin el vendido.');

        $html = (string) $j->json('distribution');
        $fila = '/<li class="dist-fila"[^>]*>\s*<div class="dist-cab">\s*<span class="dist-nombre">\s*<small class="dist-rotulo">VENDIDO:<\/small> '
            . preg_quote(e($frente->NOMBRE_FRENTE), '/') . '.*?<span class="dist-cant">1<\/span>.*?<div class="dist-barra-roja"/s';
        $this->assertMatchesRegularExpression($fila, $html, 'El frente vendido: "VENDIDO: NOMBRE" en una línea, su cantidad y la barra en rojo.');

        // Dentro del frente vendido, a propósito: sus cifras normales, y sus filas por TIPO sin el
        // rótulo ni la barra roja (la página completa tiene su propio $venta, el del aviso: el
        // include de la fila lo heredaba y ponía "VENDIDO" en todos los tipos).
        $this->assertSame(1, $this->actingAs($admin)->getJson('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk()->json('stats.total'));
        $pagina = (string) $this->actingAs($admin)->get('/admin/equipos?id_frente=' . $frente->ID_FRENTE)->assertOk()->getContent();
        $card = substr($pagina, strpos($pagina, 'id="distributionStatsContainer"'), 4000);
        $this->assertStringContainsString('dist-fila', $card);
        $this->assertStringNotContainsString('dist-rotulo', $card);
        $this->assertStringNotContainsString('dist-barra-roja', $card);
    }

    public function test_auxiliares_por_tipo_el_total_no_cuenta_lo_vendido_pero_la_lista_si(): void
    {
        [$frente] = $this->frenteConEquipo(FrenteTrabajo::TIPO_VENDIDO);
        $otro = FrenteTrabajo::whereNotIn('TIPO_FRENTE', FrenteTrabajo::TIPOS_FUERA_DE_FLOTA)->value('ID_FRENTE');
        foreach ([$frente->ID_FRENTE, $otro, $otro] as $i => $idFrente) {
            \App\Models\EquipoAuxiliar::create([
                'TIPO' => 'PRUEBAVENTA2', 'MARCA' => 'PRUEBA', 'MODELO' => 'VENTA', 'SERIAL' => 'AUXV2' . $i . strtoupper(Str::random(6)),
                'ESTADO_OPERATIVO' => 'OPERATIVO', 'ID_FRENTE_ACTUAL' => $idFrente,
            ]);
        }
        $j = $this->actingAs($this->superAdminGlobal())->getJson('/admin/equipos?id_tipo=tipo_aux:PRUEBAVENTA2')->assertOk();
        $this->assertSame(2, $j->json('stats.total'), 'TOTAL: los 2 disponibles, sin el vendido.');
        $this->assertSame(2, $j->json('stats.activos'));
        $this->assertSame(3, $j->json('totalFound'), 'La lista (y su paginación) sí los trae a los 3.');
        $this->assertStringContainsString('<small class="dist-rotulo">VENDIDO:</small>', (string) $j->json('distribution'));
    }

    public function test_un_tipo_que_no_existe_no_se_acepta(): void
    {
        $this->actingAs($this->superAdminGlobal())->postJson('/admin/frentes?json=true', [
            'NOMBRE_FRENTE' => 'X ' . Str::random(6), 'UBICACION' => 'X', 'TIPO_FRENTE' => 'VENTA', 'ESTATUS_FRENTE' => 'ACTIVO',
        ])->assertStatus(422)->assertJsonValidationErrors('TIPO_FRENTE');
    }
}
