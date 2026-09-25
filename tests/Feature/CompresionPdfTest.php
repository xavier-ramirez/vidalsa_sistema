<?php

namespace Tests\Feature;

use App\Jobs\DeleteGoogleDriveFile;
use App\Models\Usuario;
use App\Services\CompresorPdf;
use App\Support\EnlacesDocumentos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\MySqlTestCase;

/**
 * Compresion nocturna de PDF (docs:comprimir, CompresorPdf, la pantalla del registro) y las
 * reglas que protegen los archivos de Drive (EnlacesDocumentos, DeleteGoogleDriveFile).
 *
 * Lo que fijan: que no se toquen archivos de Drive donde la base es local (el PC de
 * desarrollo comparte Google Drive con el servidor pero no la base), que un archivo
 * enlazado en varias filas cambie en todas y no se borre mientras alguna lo use, que el
 * cambio arrastre las correcciones anexas y no pise un documento reemplazado, que la
 * pantalla sea solo de super.admin, y que las comprobaciones de CompresorPdf digan lo que
 * tienen que decir. Nada de esto toca Google Drive; lo de la base se revierte al terminar.
 */
class CompresionPdfTest extends MySqlTestCase
{
    public function test_solo_es_servidor_donde_la_base_es_la_del_servidor(): void
    {
        config(['services.drive.es_servidor' => null]);

        config(['database.connections.mysql.host' => '127.0.0.1']);
        $this->assertFalse(EnlacesDocumentos::esBaseDelServidor()[0], 'Con la base local no es el servidor.');
        config(['database.connections.mysql.host' => 'localhost']);
        $this->assertFalse(EnlacesDocumentos::esBaseDelServidor()[0]);

        config(['database.connections.mysql.host' => 'sistemavidalsa_vidalsa2302']);
        $this->assertTrue(EnlacesDocumentos::esBaseDelServidor()[0], 'Con la base del servidor lo es.');

        // DRIVE_ES_SERVIDOR fuerza la respuesta en los dos sentidos.
        config(['services.drive.es_servidor' => false]);
        $this->assertFalse(EnlacesDocumentos::esBaseDelServidor()[0]);
        config(['services.drive.es_servidor' => true, 'database.connections.mysql.host' => '127.0.0.1']);
        $this->assertTrue(EnlacesDocumentos::esBaseDelServidor()[0]);
    }

    public function test_el_comando_no_cambia_documentos_con_la_base_local(): void
    {
        config(['services.drive.es_servidor' => null, 'database.connections.mysql.host' => '127.0.0.1']);

        $this->artisan('docs:comprimir')
            ->expectsOutputToContain('No se cambian documentos en este equipo')
            ->assertExitCode(1);
    }

    public function test_el_principal_se_lleva_sus_correcciones_y_no_pisa_un_reemplazo(): void
    {
        $equipo = DB::table('documentacion')->value('ID_EQUIPO');
        $this->assertNotNull($equipo, 'No hay ninguna fila de documentacion para probar.');

        DB::table('documentacion')->where('ID_EQUIPO', $equipo)
            ->update(['LINK_POLIZA_SEGURO' => '/storage/google/VIEJO_PRUEBA_CPDF?v=1']);
        $anexo = DB::table('documento_anexos')->insertGetId([
            'ID_EQUIPO' => $equipo, 'TIPO_DOC' => 'poliza', 'LINK' => '/storage/google/ANEXO_PRUEBA?v=1',
            'DRIVE_FILE_ID' => 'ANEXO_PRUEBA', 'ETIQUETA' => 'Correccion 1',
            'PRINCIPAL_DRIVE_ID' => 'VIEJO_PRUEBA_CPDF', 'created_at' => now(),
        ]);

        $this->assertSame(1, EnlacesDocumentos::cambiar('VIEJO_PRUEBA_CPDF', 'NUEVO_PRUEBA_CPDF'));
        $this->assertStringStartsWith('/storage/google/NUEVO_PRUEBA_CPDF?v=',
            DB::table('documentacion')->where('ID_EQUIPO', $equipo)->value('LINK_POLIZA_SEGURO'));
        $this->assertSame('NUEVO_PRUEBA_CPDF',
            DB::table('documento_anexos')->where('ID_ANEXO', $anexo)->value('PRINCIPAL_DRIVE_ID'),
            'La correccion debe seguir apuntando al principal ya comprimido.');

        // Ya nadie apunta al viejo: un segundo intento (o un reemplazo hecho entretanto) no
        // toca nada.
        $this->assertSame(0, EnlacesDocumentos::cambiar('VIEJO_PRUEBA_CPDF', 'OTRO_PRUEBA_CPDF'));
        $this->assertStringStartsWith('/storage/google/NUEVO_PRUEBA_CPDF?v=',
            DB::table('documentacion')->where('ID_EQUIPO', $equipo)->value('LINK_POLIZA_SEGURO'));
    }

    public function test_un_archivo_compartido_cambia_en_todas_las_filas_y_no_queda_en_uso(): void
    {
        $aux = DB::table('equipos_auxiliares')->orderBy('ID_AUXILIAR')->limit(2)->pluck('ID_AUXILIAR');
        $this->assertCount(2, $aux, 'Hacen falta dos auxiliares para probar.');
        DB::table('equipos_auxiliares')->whereIn('ID_AUXILIAR', $aux)
            ->update(['LINK_DOC_PROPIEDAD' => '/storage/google/COMPARTIDO_PRUEBA_CPDF?v=1']);

        $this->assertTrue(EnlacesDocumentos::sigueEnUso('COMPARTIDO_PRUEBA_CPDF'));
        $this->assertSame(2, EnlacesDocumentos::cambiar('COMPARTIDO_PRUEBA_CPDF', 'COMPARTIDO_NUEVO_CPDF'));
        $this->assertSame(2, DB::table('equipos_auxiliares')
            ->where('LINK_DOC_PROPIEDAD', 'like', '/storage/google/COMPARTIDO_NUEVO_CPDF?v=%')->count());
        $this->assertFalse(EnlacesDocumentos::sigueEnUso('COMPARTIDO_PRUEBA_CPDF'),
            'Tras cambiarlas todas, el viejo ya no lo usa nadie y se puede retirar.');
    }

    public function test_la_correccion_anexa_cambia_su_enlace_y_su_id(): void
    {
        $equipo = DB::table('documentacion')->value('ID_EQUIPO');
        $anexo = DB::table('documento_anexos')->insertGetId([
            'ID_EQUIPO' => $equipo, 'TIPO_DOC' => 'rotc', 'LINK' => '/storage/google/ANEXO_VIEJO_CPDF?v=1',
            'DRIVE_FILE_ID' => 'ANEXO_VIEJO_CPDF', 'ETIQUETA' => 'Correccion 1', 'created_at' => now(),
        ]);

        $this->assertSame(1, EnlacesDocumentos::cambiar('ANEXO_VIEJO_CPDF', 'ANEXO_NUEVO_CPDF'));
        $fila = DB::table('documento_anexos')->where('ID_ANEXO', $anexo)->first();
        $this->assertStringStartsWith('/storage/google/ANEXO_NUEVO_CPDF?v=', $fila->LINK);
        $this->assertSame('ANEXO_NUEVO_CPDF', $fila->DRIVE_FILE_ID);
    }

    public function test_la_app_no_borra_de_drive_en_el_pc_ni_un_archivo_que_otra_fila_usa(): void
    {
        $avisos = [];
        Log::listen(function ($e) use (&$avisos) { $avisos[] = $e->message; });

        // En el PC (base local): no se borra nada, ni se llega a preguntar a Drive.
        config(['services.drive.es_servidor' => null, 'database.connections.mysql.host' => '127.0.0.1']);
        (new DeleteGoogleDriveFile('CUALQUIERA_PRUEBA_CPDF'))->handle();

        // En el servidor, con el archivo todavia enlazado en otra fila: tampoco.
        config(['services.drive.es_servidor' => true]);
        $aux = DB::table('equipos_auxiliares')->value('ID_AUXILIAR');
        DB::table('equipos_auxiliares')->where('ID_AUXILIAR', $aux)
            ->update(['LINK_CERTIFICADO' => '/storage/google/EN_USO_PRUEBA_CPDF?v=1']);
        (new DeleteGoogleDriveFile('EN_USO_PRUEBA_CPDF'))->handle();

        $this->assertTrue(collect($avisos)->contains(fn ($m) => str_contains($m, 'CUALQUIERA_PRUEBA_CPDF') && str_contains($m, 'PC de desarrollo')));
        $this->assertTrue(collect($avisos)->contains(fn ($m) => str_contains($m, 'EN_USO_PRUEBA_CPDF') && str_contains($m, 'otra fila lo sigue usando')));
    }

    public function test_la_pantalla_del_registro_es_solo_para_super_admin(): void
    {
        $admin = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $otro  = Usuario::all()->first(fn ($u) => !$u->can('super.admin'));
        $this->assertNotNull($admin, 'No hay ningun super.admin para probar.');
        $this->assertNotNull($otro, 'No hay ningun usuario sin super.admin para probar.');

        // El registro vive en una pestaña de Control de Auditoría; la direccion vieja lleva alli.
        $this->actingAs($otro)->get(route('historial-documentos.index', ['pestana' => 'compresion']))->assertForbidden();
        $this->actingAs($admin)->get(route('compresion-pdf.index'))->assertRedirectContains('pestana=compresion');
        $this->actingAs($admin)->get(route('historial-documentos.index', ['pestana' => 'compresion']))
            ->assertOk()
            ->assertSee('Compresión de PDF')
            ->assertSee('Tarea nocturna apagada')
            ->assertSee('Es el PC de desarrollo.');
    }

    public function test_detecta_la_firma_digital(): void
    {
        $firmado = tempnam(sys_get_temp_dir(), 'pdf');
        $normal  = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($firmado, "%PDF-1.7\n1 0 obj << /Type /Sig /ByteRange [0 100 200 300] >> endobj\n");
        file_put_contents($normal, "%PDF-1.7\n1 0 obj << /Type /Page >> endobj\n");

        $gs = new CompresorPdf();
        $this->assertTrue($gs->tieneFirmaDigital($firmado));
        $this->assertFalse($gs->tieneFirmaDigital($normal));

        @unlink($firmado);
        @unlink($normal);
    }

    public function test_comprime_sin_cambiar_paginas_texto_ni_lo_que_se_ve(): void
    {
        $gs = new CompresorPdf();
        if (!$gs->disponible()) {
            $this->markTestSkipped('Ghostscript no esta instalado aqui.');
        }

        // Un PDF de dos paginas con texto real, generado por el propio Ghostscript.
        $dir = sys_get_temp_dir() . '/cpdf_' . uniqid();
        mkdir($dir);
        file_put_contents("$dir/a.ps", "%!PS\n/Helvetica findfont 20 scalefont setfont\n"
            . "72 700 moveto (Reporte de inspeccion Certificada) show showpage\n"
            . "72 700 moveto (Segunda pagina del certificado) show showpage\n");
        (new \Symfony\Component\Process\Process([config('services.compresion_pdf.ghostscript'),
            '-q', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sDEVICE=pdfwrite', "-sOutputFile=$dir/a.pdf", "$dir/a.ps"]))->mustRun();

        $gs->comprimir("$dir/a.pdf", "$dir/b.pdf");

        $this->assertSame(2, $gs->paginas("$dir/a.pdf"));
        $this->assertSame(2, $gs->paginas("$dir/b.pdf"));
        $this->assertSame(0, $gs->palabrasNuevas("$dir/a.pdf", "$dir/b.pdf"));
        $this->assertLessThan(5.0, $gs->diferenciaVisual("$dir/a.pdf", "$dir/b.pdf"));

        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    /**
     * Los temporales que deja una pasada cortada de golpe se barren solos.
     *
     * Por que existe: la pasada normal borra su carpeta en el finally, pero un finally no
     * corre si matan el PHP. Quedo un temporal de 1,4 MB del 10-09-2026 en disco quince
     * dias, y encima aparecia como "documento incompleto" al revisar los PDF del equipo.
     *
     * NO TOCA DRIVE: se llama al barrido directamente sobre carpetas de laboratorio, y se
     * limpia lo que crea la propia prueba. Lo importante es el segundo caso: una pasada que
     * esta trabajando AHORA no puede quedarse sin su carpeta.
     */
    public function test_barre_los_temporales_abandonados_y_respeta_los_vivos(): void
    {
        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        $viejo  = 'compresion_tmp/prueba_viejo_' . uniqid();
        $activo = 'compresion_tmp/prueba_activo_' . uniqid();
        $lento  = 'compresion_tmp/prueba_lento_' . uniqid();

        // El try abarca DESDE la creacion: si algo revienta a mitad, la prueba no puede
        // dejar sus propias carpetas tiradas — que es justo el fallo que arregla el codigo
        // que se esta probando.
        try {
            // Abandonado: carpeta y archivo con fecha de hace dos dias.
            $disco->put("$viejo/doc.pdf", '%PDF-1.4 resto');
            $haceDosDias = time() - 2 * 86400;
            touch($disco->path("$viejo/doc.pdf"), $haceDosDias);
            touch($disco->path($viejo), $haceDosDias);

            // Vivo: recien creado, es la pasada de ahora mismo.
            $disco->put("$activo/doc.pdf", '%PDF-1.4 trabajando');

            // El caso delicado: carpeta creada hace dos dias pero con un archivo escrito
            // hace un momento. Es una pasada larga, NO esta abandonada.
            $disco->put("$lento/doc.pdf", '%PDF-1.4 trabajando despacio');
            touch($disco->path($lento), $haceDosDias);

            // El comando se llama fuera de la consola, asi que hay que darle una salida:
            // sin ella $this->warn() revienta. De paso se lee lo que avisa.
            $comando = $this->app->make(\App\Console\Commands\ComprimirDocumentos::class);
            $pantalla = new \Symfony\Component\Console\Output\BufferedOutput();
            $comando->setOutput(new \Illuminate\Console\OutputStyle(
                new \Symfony\Component\Console\Input\ArrayInput([]), $pantalla
            ));

            $metodo = new \ReflectionMethod($comando, 'barrerTemporalesAbandonados');
            $metodo->setAccessible(true);
            $metodo->invoke($comando);

            $this->assertFalse($disco->exists("$viejo/doc.pdf"), 'El temporal abandonado tiene que barrerse.');
            $this->assertTrue($disco->exists("$activo/doc.pdf"), 'El temporal de la pasada de ahora NO se toca.');
            $this->assertTrue($disco->exists("$lento/doc.pdf"), 'Una pasada lenta que sigue escribiendo NO se toca.');
            $this->assertStringContainsString('1 temporal', $pantalla->fetch(), 'Avisa de cuantos restos barrio.');
        } finally {
            foreach ([$viejo, $activo, $lento] as $c) $disco->deleteDirectory($c);
        }
    }
}
