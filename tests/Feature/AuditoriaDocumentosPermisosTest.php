<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Quién entra a Auditoría de Documentos y qué ve (pedido del cliente, 01-10-2026):
 *  - La carga masiva entera (soltar, aplicar, descartar y su revisión) funciona con SU permiso,
 *    'docs.carga.masiva', sin necesitar super.admin.
 *  - La lectura automática ("Lectura automática activa… Revisar ahora", el avance y el
 *    "Revisado") es SOLO de super.admin, igual que el historial y la compresión.
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

    public function test_con_solo_la_carga_masiva_entra_a_la_revision_y_ve_solo_lo_cargado(): void
    {
        [$noche, $carga] = $this->filas();
        $u = $this->usuario('docs.carga.masiva');

        $html = $this->actingAs($u)->get(route('historial-documentos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Revisión de documentos', $html);
        $this->assertStringContainsString('window.abrirCargaMasiva', $html, 'El modal de la carga masiva tiene que estar.');
        $this->assertStringContainsString('Carga masiva', $html, 'Y su botón en Acciones.');
        $this->assertStringContainsString($carga, $html, 'Ve lo que se cargó en lote.');
        $this->assertStringNotContainsString($noche, $html, 'No ve las filas de la lectura automática.');
        // Nada de la lectura automática, ni las otras pestañas, ni la papelera.
        $this->assertStringNotContainsString('Lectura automática', $html);
        $this->assertStringNotContainsString('Revisar ahora', $html);
        $this->assertStringNotContainsString('Por dónde va la revisión', $html);
        $this->assertStringNotContainsString('cpdfSelBarra', $html);
        $this->assertStringNotContainsString('Historial de cambios', $html);
        $this->assertStringNotContainsString('Compresión de PDF', $html);
        $this->assertStringNotContainsString('abrirPapelera()', $html);
        // Ni el botón de borrar registros del historial (solo super.admin, igual que su ruta).
        $this->assertStringNotContainsString('hdDeleteRegistro(', $html);
        // Y el enlace del menú para llegar.
        $this->assertStringContainsString(route('historial-documentos.index'), $html);
    }

    public function test_con_solo_la_carga_masiva_no_llega_a_otra_pestana_por_la_url(): void
    {
        $u = $this->usuario('docs.carga.masiva');

        foreach (['historial', 'compresion'] as $pestana) {
            $html = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => $pestana]))->assertOk()->getContent();
            $this->assertStringNotContainsString('PDF comprimidos', $html, "?pestana=$pestana no debe dar la compresión.");
            $this->assertStringNotContainsString('historialDocumentosTable', $html, "?pestana=$pestana no debe dar el historial.");
            $this->assertStringContainsString('Revisión de documentos', $html);
        }
    }

    public function test_con_solo_la_carga_masiva_usa_sus_rutas_pero_no_las_de_la_lectura_automatica(): void
    {
        $u = $this->usuario('docs.carga.masiva');

        // Las suyas: llegan al controlador (falta el archivo / el enlace → 422, no 403).
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.analizar'), [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.aplicar'), [], ['Accept' => 'application/json'])->assertStatus(422);
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.descartar'), [], ['Accept' => 'application/json'])->assertStatus(422);

        // Las de la lectura automática y el resto de Auditoría: solo super.admin.
        $this->actingAs($u)->post(route('compresion-pdf.documentos.leer-ahora'), [], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u)->post(route('compresion-pdf.documentos.revisados'), ['ids' => [1]], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u)->post(route('compresion-pdf.documento.revisado', 1), [], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u)->delete(route('historial-documentos.deleteRegistro'), [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_super_admin_sin_la_clave_de_carga_masiva_ve_la_lectura_automatica_pero_no_la_carga(): void
    {
        [$noche] = $this->filas();
        $u = $this->usuario('super.admin');

        $html = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'documentos']))->assertOk()->getContent();
        $this->assertStringContainsString($noche, $html, 'Ve las filas de la lectura automática.');
        $this->assertStringContainsString('Historial de cambios', $html);
        $this->assertStringContainsString('Compresión de PDF', $html);
        $this->assertStringNotContainsString('window.abrirCargaMasiva', $html, 'Sin la clave no se carga el modal.');
        // En el historial sí tiene el botón de borrar registros.
        $hist = $this->actingAs($u)->get(route('historial-documentos.index'))->assertOk()->getContent();
        $this->assertStringContainsString('window.hdDeleteRegistro', $hist);

        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.analizar'), [], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.aplicar'), [], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.descartar'), [], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_super_admin_ve_la_lectura_automatica_y_su_boton(): void
    {
        $u = $this->usuario('super.admin,docs.carga.masiva');

        $html = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'documentos']))->assertOk()->getContent();
        // Encendida o apagada según la base: el bloque está, y con él lo demás de la lectura.
        $this->assertMatchesRegularExpression('/Lectura automática (activa|apagada)/', $html);
        $this->assertStringContainsString('Por dónde va la revisión', $html);
        $this->assertStringContainsString('window.abrirCargaMasiva', $html);
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
