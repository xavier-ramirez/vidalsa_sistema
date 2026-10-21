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
 *    lote y dar por revisado (visor y en lote). El historial y la compresión, solo de mirar.
 *  - La lectura automática y "Revisar ahora", SOLO super.admin (20-10-2026).
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

        $this->assertStringContainsString('Revisión automática', $html);
        $this->assertStringContainsString('window.abrirCargaMasiva', $html, 'El modal de la carga masiva tiene que estar.');
        $this->assertStringContainsString('Carga masiva', $html, 'Y su botón en Acciones.');
        $this->assertStringContainsString($carga, $html, 'Ve lo que se cargó en lote.');
        $this->assertStringContainsString($noche, $html, 'Y las lecturas de la noche.');
        // El avance sí; la lectura automática y "Revisar ahora", no (solo super.admin).
        $this->assertStringContainsString('Por dónde va la revisión', $html);
        $this->assertStringNotContainsString('Lectura automática', $html);
        $this->assertStringNotContainsString('window.cpdfLeerAhora', $html);
        // Elige filas (el contador común) y las da por revisadas.
        $this->assertStringContainsString('id="cpdf-selection-chip-revisado"', $html);
        $this->assertStringContainsString('cpdfSelFila(', $html);
        $this->assertStringContainsString('window.cpdfMarcarRevisadas', $html);
        // Nada de eliminar; las otras pestañas, a la vista.
        $this->assertStringNotContainsString('cpdfDescartarCarga(this', $html, 'Descartar borra de Drive: super.admin.');
        $this->assertStringContainsString('Historial<span class="hd-pest-mas"> de cambios</span>', $html);
        $this->assertStringContainsString('Compresión<span class="hd-pest-mas"> de PDF</span>', $html);
        $this->assertStringNotContainsString('abrirPapelera()', $html);
        $this->assertStringNotContainsString('hdDeleteRegistro(', $html);
        // Y el enlace del menú para llegar.
        $this->assertStringContainsString(route('historial-documentos.index'), $html);
    }

    public function test_con_solo_la_carga_masiva_ve_el_historial_y_la_compresion_sin_tocar_nada(): void
    {
        $u = $this->usuario('docs.carga.masiva');

        $hist = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'historial']))->assertOk()->getContent();
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

        // Revisar: dar por revisado (visor y en lote). "Revisar ahora", no: es de super.admin.
        $this->actingAs($u)->post(route('compresion-pdf.documento.revisado', $id), [], $json)->assertOk()->assertJson(['success' => true]);
        $this->actingAs($u)->post(route('compresion-pdf.documentos.revisados'), ['ids' => [$id]], $json)->assertOk()->assertJson(['success' => true]);
        $this->actingAs($u)->post(route('compresion-pdf.documentos.leer-ahora'), ['tipo' => 'no-existe'], $json)->assertForbidden();

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
        $this->assertStringContainsString('Historial<span class="hd-pest-mas"> de cambios</span>', $html);
        $this->assertStringContainsString('Compresión<span class="hd-pest-mas"> de PDF</span>', $html);
        $this->assertStringNotContainsString('window.abrirCargaMasiva', $html, 'Sin la clave no se carga el modal.');
        // Elige filas para verlas (el contador es de todos), pero no revisa: eso va con la carga masiva.
        $this->assertStringContainsString('id="cpdf-selection-chip"', $html);
        $this->assertStringNotContainsString('id="cpdf-selection-chip-revisado"', $html, 'Ni revisa: eso va con la carga masiva.');
        // La lectura automática y "Revisar ahora" sí: son de super.admin.
        $this->assertStringContainsString('Lectura automática', $html);
        $this->assertStringContainsString('window.cpdfLeerAhora', $html);
        // En el historial sí tiene el botón de borrar registros.
        $hist = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'historial']))->assertOk()->getContent();
        $this->assertStringContainsString('window.hdDeleteRegistro', $hist);

        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.analizar'), [], $json)->assertForbidden();
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.descartar'), [], $json)->assertForbidden();
        $this->actingAs($u)->get(route('historial-documentos.carga-masiva.buscar-equipo', ['q' => 'ABC']), $json)->assertForbidden();
        $this->actingAs($u)->post(route('historial-documentos.carga-masiva.atar-vin'), [], $json)->assertForbidden();
        $this->actingAs($u)->post(route('compresion-pdf.documento.revisado', 1), [], $json)->assertForbidden();
        $this->actingAs($u)->post(route('compresion-pdf.documentos.revisados'), ['ids' => [1]], $json)->assertForbidden();
        // Llega al controlador (un tipo que no existe → 422, sin pedir de verdad la lectura).
        $this->actingAs($u)->post(route('compresion-pdf.documentos.leer-ahora'), ['tipo' => 'no-existe'], $json)->assertStatus(422);
    }

    public function test_super_admin_con_la_carga_masiva_tiene_todo(): void
    {
        $u = $this->usuario('super.admin,docs.carga.masiva');

        $html = $this->actingAs($u)->get(route('historial-documentos.index', ['pestana' => 'documentos']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Lectura automática (activa|apagada)/', $html);
        $this->assertStringContainsString('Por dónde va la revisión', $html);
        $this->assertStringContainsString('window.abrirCargaMasiva', $html);
        $this->assertStringContainsString('id="cpdf-selection-chip-revisado"', $html);
        $this->assertStringContainsString('window.cpdfDescartarCarga', $html, 'Y descartar.');
    }

    /**
     * Lo que lee la IA se nombra "Revisión automática" (pedido del cliente, 21-10-2026), también
     * en las filas guardadas antes con "(según la IA)" / "La IA lee distinto: ".
     */
    public function test_lo_que_lee_la_ia_se_nombra_revision_automatica(): void
    {
        $marca = 'IAVIEJA-' . strtoupper(Str::random(6));
        DB::table('verificacion_documento_registro')->insert([
            'TIPO' => 'propiedad', 'ESTADO' => VerificacionDocumento::DIFIERE, 'ORIGEN' => VerificacionDocumento::DE_LA_NOCHE,
            'MOTIVO' => 'La IA lee distinto: Serial de chasis (según la IA)', 'PLACA' => $marca,
            'DIFERENCIAS' => json_encode(['IA_SERIAL_CHASIS' => ['etiqueta' => 'Serial de chasis (según la IA)', 'ficha' => 'AAA111', 'documento' => 'AAA11I']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($this->usuario('super.admin,docs.carga.masiva'))
            ->get(route('historial-documentos.index', ['pestana' => 'documentos', 'buscar' => $marca]))->assertOk()->getContent();

        $this->assertStringContainsString('Revisión automática', $html);
        $this->assertStringContainsString('La revisión automática lee distinto: Serial de chasis"', $html, 'El motivo viejo, traducido y sin repetir la marca.');
        $this->assertStringNotContainsString('según la IA', $html);
        $this->assertStringNotContainsString('La IA lee distinto', $html);
    }

    /** La columna del equipo: serial de chasis; sin él, el de motor (de la ficha); sin él, la placa; si no, vacía. */
    public function test_la_columna_del_equipo_es_chasis_motor_o_placa(): void
    {
        $marca = 'IDENT' . strtoupper(Str::random(5));
        $motor = 'MOT' . strtoupper(Str::random(8));
        $equipo = DB::table('equipos')->insertGetId(['MARCA' => 'PRUEBA', 'MODELO' => 'IDENT', 'ANIO' => 2020,
            'SERIAL_CHASIS' => 'CH' . strtoupper(Str::random(12)), 'SERIAL_DE_MOTOR' => $motor]);
        $fila = fn (array $c) => DB::table('verificacion_documento_registro')->insertGetId($c + [
            'TIPO' => 'poliza', 'ESTADO' => VerificacionDocumento::DIFIERE, 'ORIGEN' => VerificacionDocumento::DE_LA_NOCHE,
            'MOTIVO' => $marca, 'created_at' => now(), 'updated_at' => now()]);
        $ids = [
            'chasis' => $fila(['SERIAL' => 'CHASIS' . $marca, 'PLACA' => 'PL' . $marca, 'ID_EQUIPO' => $equipo]),
            'motor'  => $fila(['SERIAL' => null, 'PLACA' => 'PL' . $marca, 'ID_EQUIPO' => $equipo]),
            'placa'  => $fila(['SERIAL' => null, 'PLACA' => 'PL' . $marca]),
            'nada'   => $fila(['SERIAL' => null, 'PLACA' => null]),
        ];

        $html = $this->actingAs($this->usuario('super.admin,docs.carga.masiva'))
            ->get(route('historial-documentos.index', ['pestana' => 'documentos', 'ids' => implode(',', $ids)]))->assertOk()->getContent();
        $celda = function (int $id) use ($html) {
            preg_match('~<tr[^>]*data-id="' . $id . '".*?<span class="cpdf-ident">(.*?)</span>~s', $html, $m);
            return $m[1] ?? 'SIN FILA';
        };

        $this->assertSame('CHASIS' . $marca, $celda($ids['chasis']));
        $this->assertSame($motor, $celda($ids['motor']));
        $this->assertSame('PL' . $marca, $celda($ids['placa']));
        $this->assertSame('', $celda($ids['nada']), 'Sin ningún dato la celda queda vacía, pero la fila sale.');
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
