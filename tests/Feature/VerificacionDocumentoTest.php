<?php

namespace Tests\Feature;

use App\Models\CatalogoSeguro;
use App\Models\EquipoAuditLog;
use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use App\Services\LectorDocumentoPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Verificacion de los documentos de un equipo contra su ficha: titulo de propiedad y poliza
 * (docs:verificar-documentos + la pestaña "Títulos y pólizas" de /admin/compresion-pdf).
 *
 * El lector se sustituye por uno de mentira: las pruebas no llaman a Google Drive, solo
 * comprueban lo que hace el sistema con el texto que ese lector devuelve. Todo corre en la
 * transaccion de la prueba y se revierte al terminar.
 */
class VerificacionDocumentoTest extends MySqlTestCase
{
    /** Texto de un Certificado de Registro de Vehiculo del INTT. */
    private function textoTitulo(string $titular, string $placa, string $emitido = '3 días del mes de: OCTUBRE de: 2018'): string
    {
        return "INTT \n240109177454 \nCertificado de Registro de Vehículo \n"
            . "El Instituto Nacional de Transporte Terrestre certifica ... Certificado de Registro de Vehículo a: \n"
            . "$titular \nCédula o RIF: \nJ293877199 \nPlaca: $placa \nSerial N.I.V.: ABC123 \n"
            . "Dado a los: $emitido \n";
    }

    /** Texto de un cuadro de poliza (el de Pirámide: "Vigencia del Seguro: X al Y"). */
    private function textoPoliza(string $aseguradora, string $placa, string $vence, string $emision = '19/02/2026'): string
    {
        return "$aseguradora \nCUADRO Y RECIBO DE PÓLIZAS \nContratante: CONSTRUCTORA VIDALSA 27 CA \n"
            . "Vigencia del Seguro: 19/02/2026 al $vence Fecha de Emisión: $emision \n"
            . "DATOS DEL VEHÍCULO \nPlaca: $placa \nMarca: TOYOTA \n";
    }

    /** Hace que el lector devuelva ese texto (o lance ese error) sin tocar Drive. */
    private function lectorFalso(string $texto, ?\Throwable $falla = null): void
    {
        $this->app->bind(LectorDocumentoPdf::class, function () use ($texto, $falla) {
            return new class ($texto, $falla) extends LectorDocumentoPdf {
                public function __construct(private string $textoFalso, private ?\Throwable $falla) {}

                public function texto(string $driveId): string
                {
                    if ($this->falla) throw $this->falla;
                    return $this->textoFalso;
                }
            };
        });
    }

    /**
     * Equipo + ficha con los dos documentos enlazados. Devuelve [ID_EQUIPO, placa].
     * `$ficha` sobreescribe columnas de documentacion (titular, ID_SEGURO, fechas...).
     */
    private function equipoConDocumentos(array $ficha = []): array
    {
        // documentacion.PLACA es unica: cada ficha de prueba estrena la suya.
        $placa = 'ZZ' . strtoupper(Str::random(5));
        $id = DB::table('equipos')->insertGetId([
            'MARCA' => 'MARCAPRUEBA', 'MODELO' => 'MP-' . strtoupper(Str::random(4)),
            'ANIO' => 2020, 'SERIAL_CHASIS' => 'SC' . strtoupper(Str::random(10)),
        ]);
        DB::table('documentacion')->insert($ficha + [
            'ID_EQUIPO' => $id, 'PLACA' => $placa,
            'LINK_DOC_PROPIEDAD' => '/storage/google/drive' . strtoupper(Str::random(10)) . '?v=1',
            'LINK_POLIZA_SEGURO' => '/storage/google/drive' . strtoupper(Str::random(10)) . '?v=1',
        ]);
        return [$id, $placa];
    }

    /** Corre el comando para una ficha y devuelve lo registrado de ese tipo de documento. */
    private function verificar(int $equipo, string $tipo): VerificacionDocumento
    {
        $this->artisan('docs:verificar-documentos', ['--equipo' => $equipo, '--tipo' => $tipo])->assertSuccessful();
        return VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', $tipo)->firstOrFail();
    }

    private function ficha(int $equipo): object
    {
        return DB::table('documentacion')->where('ID_EQUIPO', $equipo)->first();
    }

    private function superAdmin(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($u, 'Hace falta un usuario super.admin para probar.');
        return $u;
    }

    private function aseguradora(string $nombre): int
    {
        return (int) CatalogoSeguro::where('NOMBRE_ASEGURADORA', $nombre)->value('ID_SEGURO')
            ?: (int) CatalogoSeguro::query()->value('ID_SEGURO');
    }

    public function test_si_los_datos_ya_estan_bien_no_se_cambia_nada(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertNull($reg->DIFERENCIAS);
        $this->assertSame('CONSTRUCTORA VIDALSA 27, C.A', $this->ficha($equipo)->NOMBRE_DEL_TITULAR,
            'El comando no debe tocar la ficha.');
    }

    public function test_el_titulo_trae_propietario_y_fecha_de_emision(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'ONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertSame('CONSTRUCTORA VIDALSA 27, C.A', $reg->DIFERENCIAS['NOMBRE_DEL_TITULAR']['documento']);
        $this->assertSame('2018-10-03', $reg->DIFERENCIAS['FECHA_EMISION_PROPIEDAD']['documento'],
            'La fecha en que se emitio el titulo sale del propio documento.');
    }

    public function test_la_poliza_trae_aseguradora_vencimiento_y_emision(): void
    {
        $mampreca = $this->aseguradora('MAMPRECA');
        [$equipo, $placa] = $this->equipoConDocumentos([
            'ID_SEGURO' => $mampreca, 'FECHA_VENC_POLIZA' => '2026-01-01',
        ]);
        $this->lectorFalso($this->textoPoliza('Pirámide Seguros', $placa, '19/02/2027'));

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertSame('PIRÁMIDE SEGUROS', $reg->DIFERENCIAS['ID_SEGURO']['documento']);
        $this->assertSame('2027-02-19', $reg->DIFERENCIAS['FECHA_VENC_POLIZA']['documento']);
        $this->assertSame('2026-02-19', $reg->DIFERENCIAS['FECHA_EMISION_POLIZA']['documento']);
        $this->assertSame('PIRÁMIDE SEGUROS', $reg->LEIDO['aseguradora']);
    }

    public function test_el_boton_corrige_la_poliza_completa_y_queda_en_el_historial(): void
    {
        $mampreca = $this->aseguradora('MAMPRECA');
        $piramide = $this->aseguradora('PIRÁMIDE SEGUROS');
        [$equipo, $placa] = $this->equipoConDocumentos(['ID_SEGURO' => $mampreca, 'FECHA_VENC_POLIZA' => '2026-01-01']);
        $this->lectorFalso($this->textoPoliza('Pirámide Seguros', $placa, '19/02/2027'));
        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $antesAudit = EquipoAuditLog::where('ID_EQUIPO', $equipo)->count();
        $this->actingAs($this->superAdmin())
            ->post(route('compresion-pdf.documento.aplicar', ['id' => $reg->ID_REGISTRO]))
            ->assertRedirect();

        $ficha = $this->ficha($equipo);
        $this->assertSame($piramide, (int) $ficha->ID_SEGURO);
        $this->assertSame('2027-02-19', substr($ficha->FECHA_VENC_POLIZA, 0, 10));
        $this->assertSame('2026-02-19', substr($ficha->FECHA_EMISION_POLIZA, 0, 10));
        $reg->refresh();
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertNotNull($reg->APLICADO_EN);
        $this->assertNull($reg->DIFERENCIAS);
        // Lo audita DocumentacionObserver (solo los campos de identidad del documento).
        $this->assertGreaterThanOrEqual($antesAudit, EquipoAuditLog::where('ID_EQUIPO', $equipo)->count());

        // Ya coincide: no hay nada que aplicar y la ficha no se vuelve a tocar.
        $this->actingAs($this->superAdmin())
            ->post(route('compresion-pdf.documento.aplicar', ['id' => $reg->ID_REGISTRO]))
            ->assertRedirect()->assertSessionHas('error');
    }

    public function test_diferencias_de_escritura_del_nombre_se_distinguen(): void
    {
        // Una letra de menos: puede estar mal el documento o la ficha, se avisa.
        [$e1, $p1] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27 C.A.']);
        $this->lectorFalso($this->textoTitulo('CONTRUCTORA VIDALSA 27, C.A', $p1));
        $this->assertStringContainsString('una letra', (string) $this->verificar($e1, VerificacionDocumento::PROPIEDAD)->MOTIVO);

        // Letras de otro alfabeto que se ven iguales: la ficha queda inencontrable al buscarla.
        [$e2, $p2] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => "CONSTRUCTORA VIDALSA 27, \u{0421}.\u{0410}"]);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $p2));
        $this->assertStringContainsString('otro alfabeto', (string) $this->verificar($e2, VerificacionDocumento::PROPIEDAD)->MOTIVO);

        // Los acentos NO son una diferencia: el reconocimiento se los come y la ficha es la buena.
        [$e3, $p3] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCCIÓN PEÑA, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCCION PENA, C.A', $p3));
        $r3 = $this->verificar($e3, VerificacionDocumento::PROPIEDAD);
        $this->assertSame(VerificacionDocumento::COINCIDE, $r3->ESTADO);
        $this->assertSame('CONSTRUCCIÓN PEÑA, C.A', $this->ficha($e3)->NOMBRE_DEL_TITULAR,
            'Aplicar no debe cambiar un nombre bien acentuado por el del reconocimiento.');
    }

    public function test_documento_ilegible_o_perdido_queda_para_revisar_a_mano(): void
    {
        [$e1] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso("INTT \nhoja escaneada sin nada que se entienda \n");
        $r1 = $this->verificar($e1, VerificacionDocumento::PROPIEDAD);
        $this->assertSame(VerificacionDocumento::ILEGIBLE, $r1->ESTADO);
        $this->assertContains($r1->ESTADO, VerificacionDocumento::A_REVISAR);
        $this->assertFalse($r1->aplicable());

        // Una poliza de la que no sale la vigencia tampoco se da por revisada.
        [$e2] = $this->equipoConDocumentos(['ID_SEGURO' => $this->aseguradora('MAMPRECA')]);
        $this->lectorFalso("MAMPRECA \nCuadro Póliza Recibo \nsin fechas legibles \n");
        $r2 = $this->verificar($e2, VerificacionDocumento::POLIZA);
        $this->assertSame(VerificacionDocumento::ILEGIBLE, $r2->ESTADO);
        $this->assertStringContainsString('vigencia', (string) $r2->MOTIVO);

        [$e3] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso('', new \Exception('Client error: 404 File not found'));
        $this->assertSame(VerificacionDocumento::SIN_ARCHIVO, $this->verificar($e3, VerificacionDocumento::PROPIEDAD)->ESTADO);
    }

    public function test_un_documento_de_otro_vehiculo_se_avisa_y_no_se_puede_aplicar(): void
    {
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso($this->textoTitulo('OTRA EMPRESA, C.A', 'CC999DD'));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->esDeOtroVehiculo());
        $this->assertFalse($reg->aplicable(), 'Lo que hay que corregir es el PDF enlazado, no la ficha.');

        $this->actingAs($this->superAdmin())
            ->post(route('compresion-pdf.documento.aplicar', ['id' => $reg->ID_REGISTRO]))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame('CONSTRUCTORA VIDALSA 27, C.A', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);
    }

    public function test_una_o_y_un_cero_en_la_placa_no_son_dos_vehiculos(): void
    {
        // El reconocimiento confunde O con 0 en las fotos: eso no es "otra placa".
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', str_replace('O', '0', $placa)));

        $this->assertSame(VerificacionDocumento::COINCIDE, $this->verificar($equipo, VerificacionDocumento::PROPIEDAD)->ESTADO);
    }

    public function test_no_relee_lo_ya_revisado_y_al_subir_otro_archivo_retira_la_lectura_vieja(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));
        $viejo = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        // Sin cambios, la siguiente pasada no lo vuelve a leer (no hay pendientes).
        $this->artisan('docs:verificar-documentos', ['--tipo' => VerificacionDocumento::PROPIEDAD])->assertSuccessful();

        DB::table('documentacion')->where('ID_EQUIPO', $equipo)
            ->update(['LINK_DOC_PROPIEDAD' => '/storage/google/driveOTRO' . strtoupper(Str::random(6)) . '?v=2']);
        $nuevo = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertNotSame($viejo->ID_REGISTRO, $nuevo->ID_REGISTRO);
        $this->assertSame(1, VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', VerificacionDocumento::PROPIEDAD)->count(),
            'La lectura del archivo reemplazado se retira: no quedan dos filas del mismo documento.');
    }

    public function test_lo_corregido_a_mano_despues_de_leer_el_pdf_se_respeta(): void
    {
        $mampreca = $this->aseguradora('MAMPRECA');
        [$equipo, $placa] = $this->equipoConDocumentos(['ID_SEGURO' => $mampreca, 'FECHA_VENC_POLIZA' => '2026-01-01']);
        $this->lectorFalso($this->textoPoliza('Pirámide Seguros', $placa, '19/02/2027'));
        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        // Entre la lectura y el botón, alguien corrige el vencimiento a mano.
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update(['FECHA_VENC_POLIZA' => '2027-03-05']);

        $this->actingAs($this->superAdmin())
            ->post(route('compresion-pdf.documento.aplicar', ['id' => $reg->ID_REGISTRO]))
            ->assertRedirect()->assertSessionHas('success');

        $ficha = $this->ficha($equipo);
        $this->assertSame('2027-03-05', substr($ficha->FECHA_VENC_POLIZA, 0, 10), 'La corrección a mano no se pisa.');
        $this->assertSame($this->aseguradora('PIRÁMIDE SEGUROS'), (int) $ficha->ID_SEGURO, 'Lo demás sí se aplica.');
    }

    public function test_un_documento_leido_a_medias_no_se_puede_aplicar(): void
    {
        // El reconocimiento corta el nombre al topar con el siguiente rótulo: lo que dice el
        // documento es MENOS que lo que tiene la ficha, y aplicarlo la dejaría peor.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->esLecturaParcial());
        $this->assertFalse($reg->aplicable());
        $this->assertStringContainsString('a medias', (string) $reg->MOTIVO);

        $this->actingAs($this->superAdmin())
            ->post(route('compresion-pdf.documento.aplicar', ['id' => $reg->ID_REGISTRO]))
            ->assertRedirect()->assertSessionHas('error');
        $this->assertSame('CONSTRUCTORA VIDALSA 27, C.A', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);
    }

    public function test_lo_ilegible_se_reintenta_otras_noches_hasta_el_tope(): void
    {
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso("INTT \nhoja escaneada sin nada que se entienda \n");

        // Cada pasada suma un intento; mientras le queden, sigue en la cola.
        for ($i = 1; $i <= VerificacionDocumento::MAX_INTENTOS; $i++) {
            $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);
            $this->assertSame($i, (int) $reg->INTENTOS);
            $this->assertSame($i < VerificacionDocumento::MAX_INTENTOS, $this->enLaCola($equipo, VerificacionDocumento::PROPIEDAD));
        }
    }

    /** ¿El comando volvería a leer ese documento en su próxima pasada? */
    private function enLaCola(int $equipo, string $tipo): bool
    {
        $idEnlace = "SUBSTRING_INDEX(SUBSTRING_INDEX(d.LINK_DOC_PROPIEDAD, '/storage/google/', -1), '?', 1)";
        return DB::table('documentacion as d')
            ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->where('d.ID_EQUIPO', $equipo)
            ->whereNotExists(fn ($s) => $s->from('verificacion_documento_registro as v')
                ->whereColumn('v.ID_EQUIPO', 'd.ID_EQUIPO')->where('v.TIPO', $tipo)->whereRaw("v.DRIVE_ID = $idEnlace")
                ->where(fn ($w) => $w->whereNotIn('v.ESTADO', VerificacionDocumento::A_REVISAR)
                    ->orWhere('v.INTENTOS', '>=', VerificacionDocumento::MAX_INTENTOS)))
            ->exists();
    }

    public function test_sin_super_admin_no_se_puede_corregir(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'ONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));
        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $sinPermiso = Usuario::all()->first(fn ($u) => ! $u->can('super.admin'));
        $this->assertNotNull($sinPermiso, 'Hace falta un usuario sin super.admin.');

        $this->actingAs($sinPermiso)
            ->post(route('compresion-pdf.documento.aplicar', ['id' => $reg->ID_REGISTRO]))
            ->assertForbidden();
        $this->assertSame('ONSTRUCTORA VIDALSA 27, C.A', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);
    }

    public function test_la_pantalla_de_auditoria_tiene_las_tres_pestanas(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'MODAVENCA HOME, C.A.']);
        $this->lectorFalso($this->textoTitulo('JESUS VIDAL SALAZAR ACEVEDO', $placa));
        $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        // La pantalla vive en Control de Auditoría, con sus tres pestañas.
        $this->actingAs($this->superAdmin())->get(route('historial-documentos.index'))
            ->assertOk()->assertSee('Títulos y pólizas', false)->assertSee('Compresión de PDF', false);

        $this->actingAs($this->superAdmin())->get(route('historial-documentos.index', ['pestana' => 'documentos']))
            ->assertOk()
            ->assertSee('JESUS VIDAL SALAZAR ACEVEDO', false)
            ->assertSee('Corregir ficha', false);

        // La direccion vieja sigue sirviendo: lleva a la pestaña.
        $this->actingAs($this->superAdmin())->get(route('compresion-pdf.index'))
            ->assertRedirectContains('pestana=compresion');
    }
}
