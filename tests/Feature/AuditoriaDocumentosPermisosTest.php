<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Quién entra a Auditoría de Documentos y qué hace (pedido del cliente, 01-10-2026 y 19-10-2026):
 *  - Con 'docs.carga.masiva' (sin super.admin) se entra a las tres pestañas. En la revisión de
 *    documentos ve TODAS las lecturas (las de la noche y las de la carga) y revisa: cargar en
 *    lote, dar por revisado (visor y en lote) y "Revisar ahora". El historial y la compresión,
 *    solo de mirar.
 *  - Eliminar (descartar un PDF, borrar del historial, la Papelera) es SOLO de super.admin.
 * Ver Usuario::veAuditoriaDocumentos. Los usuarios se crean aquí y la transacción los deshace.
 */
class AuditoriaDocumentosPermisosTest extends MySqlTestCase
{
    private function usuario(string $permisos): Usuario
    {
        $u = new Usuario();
        $u->NOMBRE_COMPLETO = 'PRUEBA AUDITORIA ' . strtoupper($permisos ?: 'SIN PERMISOS');
        $u->CORREO_ELECTRONICO = 'prueba.auditoria.' . uniqid() . '@local.test';
        $u->PASSWORD_HASH = bcrypt(Str::random(16));
        $u->PERMISOS = $permisos;
        $u->NIVEL_ACCESO_EQUIPOS = 1;
        $u->NIVEL_ACCESO_ALMACEN = 1;
        $u->ESTATUS = 'ACTIVO';
        $u->REQUIERE_CAMBIO_CLAVE = 0;
        $u->save();
        return $u;
    }

    /** Una fila de cada procedencia, con una marca para encontrarla en la pantalla. */
    private function filas(): array
    {
        $noche = 'NOCHE-' . strtoupper(Str::random(8));
        $carga = 'CARGA-' . strtoupper(Str::random(8));
        DB::table('verificacion_documento_registro')->insert([
            ['TIPO' => 'poliza', 'ESTADO' => VerificacionDocumento::ILEGIBLE, 'ORIGEN' => VerificacionDocumento::DE_LA_NOCHE,
             'MOTIVO' => $noche, 'created_at' => now(), 'updated_at' => now()],
            ['TIPO' => 'poliza', 'ESTADO' => VerificacionDocumento::SIN_FICHA, 'ORIGEN' => VerificacionDocumento::DE_CARGA_MASIVA,
             'MOTIVO' => $carga, 'created_at' => now(), 'updated_at' => now()],
        ]);
        return [$noche, $carga];
    }

    public function test_con_solo_la_carga_masiva_ve_todas_las_lecturas_y_revisa(): void
    {
        [$noche, $carga] = $this->filas();
        $u = $this->usuario('docs.carga.masiva');

        $html = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'documentos']))->assertOk()->getContent();

        $this->assertStringContainsString('Revisión de documentos', $html);
        $this->assertStringContainsString('window.abrirCargaMasiva', $html, 'El modal de la carga masiva tiene que estar.');
        $this->assertStringContainsString('Carga masiva', $html, 'Y su botón en Acciones.');
        $this->assertStringContainsString($carga, $html, 'Ve lo que se cargó en lote.');
        $this->assertStringContainsString($noche, $html, 'Y las lecturas de la noche.');
        // La lectura automática y su avance, con "Revisar ahora" (los botones salen solo si
        // está activa en esta base; su función, siempre).
        $this->assertMatchesRegularExpression('/Lectura automática (activa|apagada)/', $html);
        $this->assertStringContainsString('Por dónde va la revisión', $html);
        $this->assertStringContainsString('window.cpdfLeerAhora', $html);
        // Elige filas y las da por revisadas.
        $this->assertStringContainsString('cpdfSelBarra', $html);
        $this->assertStringContainsString('cpdfSelFila(', $html);
        $this->assertStringContainsString('window.cpdfMarcarRevisadas', $html);
        // Nada de eliminar; las otras pestañas, a la vista.
        $this->assertStringNotContainsString('cpdfDescartarCarga(this', $html, 'Descartar borra de Drive: super.admin.');
        $this->assertStringContainsString('Historial de cambios', $html);
        $this->assertStringContainsString('Compresión de PDF', $html);
        $this->assertStringNotContainsString('abrirPapelera()', $html);
        $this->assertStringNotContainsString('hdDeleteRegistro(', $html);
        // Y el enlace del menú para llegar.
        $this->assertStringContainsString(route('historial-documentos.index'), $html);
    }

    public function test_con_solo_la_carga_masiva_ve_el_historial_y_la_compresion_sin_tocar_nada(): void
    {
        $u = $this->usuario('docs.carga.masiva');

        $hist = $this->actingAs($u)->get(route('historial-documentos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('historialDocumentosTable', $hist, 'Ve el historial.');
        $this->assertStringNotContainsString('btn-hd-del', $hist, 'Sin borrar registros.');
        $this->assertStringNotContainsString('abrirPapelera()', $hist, 'Ni la Papelera.');

        $comp = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'compresion']))->assertOk()->getContent();
        $this->assertStringContainsString('PDF comprimidos', $comp, 'Ve la compresión.');
        $this->assertStringNotContainsString('btn-hd-del', $comp);
        $this->assertStringNotContainsString('abrirPapelera()', $comp);
    }

    public function test_con_solo_la_carga_masiva_revisa_pero_no_elimina(): void
    {
        [$noche] = $this->filas();
        $u = $this->usuario('docs.carga.masiva');
        $id = DB::table('verificacion_documento_registro')->where('MOTIVO', $noche)->value('ID_REGISTRO');
        $json = ['Accept' => 'application/json'];

        // Las de la carga: llegan al controlador (falta el archivo / el enlace → 422, no 403).
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.analizar'), [], $json)->assertStatus(422);
        $this->actingAs($u)->get(route('historial-documentos.carga-masiva.buscar-equipo'), $json)->assertStatus(422);
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.atar-vin'), [], $json)->assertStatus(422);

        // Revisar: dar por revisado (visor y en lote) y "Revisar ahora" (con un tipo que no
        // existe, para que llegue al controlador sin pedir de verdad la lectura → 422).
        $this->actingAs($u)->post(route('compresion-pdf.documento.revisado', $id), [], $json)->assertOk()->assertJson(['success' => true]);
        $this->actingAs($u)->post(route('compresion-pdf.documentos.revisados'), ['ids' => [$id]], $json)->assertOk()->assertJson(['success' => true]);
        $this->actingAs($u)->post(route('compresion-pdf.documentos.leer-ahora'), ['tipo' => 'no-existe'], $json)->assertStatus(422);

        // Eliminar: solo super.admin.
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.descartar'), [], $json)->assertForbidden();
        $this->actingAs($u)->delete(route('historial-documentos.deleteRegistro'), [], $json)->assertForbidden();
    }

    public function test_super_admin_sin_la_clave_de_carga_masiva_ve_las_lecturas_pero_no_revisa(): void
    {
        [$noche] = $this->filas();
        $u = $this->usuario('super.admin');
        $json = ['Accept' => 'application/json'];

        $html = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'documentos']))->assertOk()->getContent();
        $this->assertStringContainsString($noche, $html, 'Ve las filas de la lectura automática.');
        $this->assertStringContainsString('Historial de cambios', $html);
        $this->assertStringContainsString('Compresión de PDF', $html);
        $this->assertStringNotContainsString('window.abrirCargaMasiva', $html, 'Sin la clave no se carga el modal.');
        $this->assertStringNotContainsString('cpdfSelBarra', $html, 'Ni revisa: eso va con la carga masiva.');
        $this->assertStringNotContainsString('window.cpdfLeerAhora', $html);
        // En el historial sí tiene el botón de borrar registros.
        $hist = $this->actingAs($u)->get(route('historial-documentos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('window.hdDeleteRegistro', $hist);

        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.analizar'), [], $json)->assertForbidden();
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.descartar'), [], $json)->assertForbidden();
        $this->actingAs($u)->get(route('historial-documentos.carga-masiva.buscar-equipo', ['q' => 'ABC']), $json)->assertForbidden();
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.atar-vin'), [], $json)->assertForbidden();
        $this->actingAs($u)->post(route('compresion-pdf.documento.revisado', 1), [], $json)->assertForbidden();
        $this->actingAs($u)->post(route('compresion-pdf.documentos.revisados'), ['ids' => [1]], $json)->assertForbidden();
        $this->actingAs($u)->post(route('compresion-pdf.documentos.leer-ahora'), [], $json)->assertForbidden();
    }

    public function test_super_admin_con_la_carga_masiva_tiene_todo(): void
    {
        $u = $this->usuario('super.admin,docs.carga.masiva');

        $html = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'documentos']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Lectura automática (activa|apagada)/', $html);
        $this->assertStringContainsString('Por dónde va la revisión', $html);
        $this->assertStringContainsString('window.abrirCargaMasiva', $html);
        $this->assertStringContainsString('cpdfSelBarra', $html);
        $this->assertStringContainsString('window.cpdfDescartarCarga', $html, 'Y descartar.');
    }

    public function test_sin_ninguno_de_los_dos_no_entra_ni_ve_el_enlace(): void
    {
        $u = $this->usuario('equipos.create');

        $this->actingAs($u)->get(route('historial-documentos.index'))->assertForbidden();
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.analizar'), [], ['Accept' => 'application/json'])->assertForbidden();

        $menu = $this->actingAs($u)->get(route('menu'))->getContent();
        $this->assertStringNotContainsString('Control de Auditoría', $menu);
    }
}
