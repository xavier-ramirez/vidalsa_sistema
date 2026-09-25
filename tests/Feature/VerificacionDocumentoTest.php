<?php

namespace Tests\Feature;

use App\Console\Commands\VerificarDocumentos;
use App\Models\CatalogoSeguro;
use App\Models\EquipoAuditLog;
use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use App\Services\CorrectorFichaDocumento;
use App\Services\LectorDocumentoPdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Verificacion de los documentos de un equipo contra su ficha: titulo de propiedad y poliza
 * (docs:verificar-documentos + la pestaña "Documentos" de Control de Auditoría).
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

    /** Texto de un ROTC (Certificado de Circulacion de Vehiculo de Carga del INTT). */
    private function textoRotc(string $titular, string $placa, string $serial, string $emision, string $vence): string
    {
        // Sale como bloque de rótulos y debajo el de valores, igual que el PDF de verdad.
        return "CERTIFICADO DE CIRCULACIÓN DE VEHICULO DE CARGA\n"
            . "Razón Social\nRIF\nNro de ROTC\n$titular\nJ-29387719-9\n49199\n"
            . "Placa\nSerial de Carrocería\nMarca - Modelo\nAño\n$placa\n$serial\nJAC - HFC3252KR1K3\n2018\n"
            . "Fecha de Emisión\nFecha de Vencimiento\n$emision\n$vence\n";
    }

    /** Texto de una providencia RACDA del MINEC, con su lista de placas autorizadas. */
    private function textoRacda(array $placas, string $dia = '14', string $mes = 'JULIO', string $anio = '2025'): string
    {
        return "MINISTERIO DEL PODER POPULAR PARA EL ECOSOCIALISMO.\n"
            . "CARACAS, $dia DE $mes DE $anio. PROVIDENCIA ADMINISTRATIVA N° 1120\n"
            . "PRIMERO: Otorgar a la empresa CONSTRUCTORA VIDALSA 27, C.A. ...\n"
            . "SEGUNDO: Las unidades de transporte terrestre autorizadas poseen las siguientes placas:\n"
            . implode(' ', $placas) . "\n"
            . "TERCERO: La AUTORIZACIÓN es de carácter INTRANSFERIBLE, y tendrá validez por DOS (02) años, "
            . "contados a partir de la emisión de la presente providencia administrativa.\n";
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
        // documentacion.PLACA es unica: cada ficha de prueba estrena la suya, con el FORMATO
        // de verdad (letra, dos dígitos, dos letras, dígito, letra). Str::random no sirve aquí:
        // mezcla letras y números, y entonces ni el lector la reconoce como placa ni la
        // encuentra en la lista del RACDA.
        $letra = fn () => chr(random_int(65, 90));
        $placa = $letra() . random_int(10, 99) . $letra() . $letra() . random_int(0, 9) . $letra();
        $id = DB::table('equipos')->insertGetId([
            'MARCA' => 'MARCAPRUEBA', 'MODELO' => 'MP-' . strtoupper(Str::random(4)),
            // El serial, con el largo y la pinta de un N.I.V. de verdad (letras Y digitos):
            // el lector descarta como serial lo que no lleve ningun digito.
            'ANIO' => 2020, 'SERIAL_CHASIS' => '8XA' . strtoupper(Str::random(8)) . random_int(100000, 999999),   // 17, como un N.I.V.
        ]);
        DB::table('documentacion')->insert($ficha + [
            'ID_EQUIPO' => $id, 'PLACA' => $placa,
            'LINK_DOC_PROPIEDAD' => '/storage/google/drive' . strtoupper(Str::random(10)) . '?v=1',
            'LINK_POLIZA_SEGURO' => '/storage/google/drive' . strtoupper(Str::random(10)) . '?v=1',
            'LINK_ROTC'          => '/storage/google/drive' . strtoupper(Str::random(10)) . '?v=1',
            'LINK_RACDA'         => '/storage/google/drive' . strtoupper(Str::random(10)) . '?v=1',
        ]);
        return [$id, $placa];
    }

    /** Corre el comando para una ficha y devuelve lo registrado de ese tipo de documento. */
    private function verificar(int $equipo, string $tipo): VerificacionDocumento
    {
        $this->artisan('docs:verificar-documentos', ['--equipo' => $equipo, '--tipo' => $tipo])->assertSuccessful();
        return VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', $tipo)->firstOrFail();
    }

    /**
     * Una pasada que solo ANOTA, sin escribir en la ficha (--no-rellenar). La usan las pruebas
     * del boton y de los avisos: necesitan la fila tal como queda antes de aplicarse.
     */
    private function verificarSinAplicar(int $equipo, string $tipo): VerificacionDocumento
    {
        $this->artisan('docs:verificar-documentos', ['--equipo' => $equipo, '--tipo' => $tipo, '--no-rellenar' => true])
            ->assertSuccessful();
        return VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', $tipo)->firstOrFail();
    }

    /** Lo que hace la tarea de la noche con una fila ya leida: poner en la ficha lo del documento. */
    private function aplicar(VerificacionDocumento $reg): array
    {
        return app(CorrectorFichaDocumento::class)->aplicar($reg->refresh());
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
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        // Manda el documento: el nombre mal escrito de la ficha y la fecha que le faltaba se
        // ponen con lo que dice el titulo, sin que nadie pulse nada.
        $ficha = $this->ficha($equipo);
        $this->assertSame('TRANSPORTE MILENIUM 0210 C.A', $ficha->NOMBRE_DEL_TITULAR);
        $this->assertSame('2018-10-03', substr((string) $ficha->FECHA_EMISION_PROPIEDAD, 0, 10),
            'La fecha en que se emitio el titulo sale del propio documento.');
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertNull($reg->DIFERENCIAS);
    }

    public function test_la_poliza_trae_aseguradora_vencimiento_y_emision(): void
    {
        $mampreca = $this->aseguradora('MAMPRECA');
        [$equipo, $placa] = $this->equipoConDocumentos([
            'ID_SEGURO' => $mampreca, 'FECHA_VENC_POLIZA' => '2026-01-01',
        ]);
        $this->lectorFalso($this->textoPoliza('Pirámide Seguros', $placa, '19/02/2027'));

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        // Manda el documento: aseguradora y las dos fechas quedan como dice la poliza.
        $ficha = $this->ficha($equipo);
        $this->assertSame($this->aseguradora('PIRÁMIDE SEGUROS'), (int) $ficha->ID_SEGURO);
        $this->assertSame('2027-02-19', substr((string) $ficha->FECHA_VENC_POLIZA, 0, 10));
        $this->assertSame('2026-02-19', substr((string) $ficha->FECHA_EMISION_POLIZA, 0, 10));
        $this->assertNotSame($mampreca, (int) $ficha->ID_SEGURO);
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertSame('PIRÁMIDE SEGUROS', $reg->LEIDO['aseguradora']);
    }

    public function test_la_correccion_pone_la_poliza_completa_y_queda_en_el_historial(): void
    {
        $mampreca = $this->aseguradora('MAMPRECA');
        $piramide = $this->aseguradora('PIRÁMIDE SEGUROS');
        [$equipo, $placa] = $this->equipoConDocumentos(['ID_SEGURO' => $mampreca, 'FECHA_VENC_POLIZA' => '2026-01-01']);
        $this->lectorFalso($this->textoPoliza('Pirámide Seguros', $placa, '19/02/2027'));
        // Sin aplicar primero: lo que se prueba aqui es la CORRECCION. Con la pasada normal la
        // fila ya llegaria corregida y el test pasaria aunque la correccion no hiciera nada.
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::POLIZA);
        $this->assertTrue($reg->aplicable());

        $this->assertNotEmpty($this->aplicar($reg)['puestos'] ?? []);

        $ficha = $this->ficha($equipo);
        $this->assertSame($piramide, (int) $ficha->ID_SEGURO);
        $this->assertSame('2027-02-19', substr($ficha->FECHA_VENC_POLIZA, 0, 10));
        $this->assertSame('2026-02-19', substr($ficha->FECHA_EMISION_POLIZA, 0, 10));
        $reg->refresh();
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertNotNull($reg->APLICADO_EN);
        $this->assertNull($reg->APLICADO_POR, 'Lo hizo la tarea, no una persona.');
        $this->assertNull($reg->DIFERENCIAS);
        // En el historial del equipo: la aseguradora y las fechas no las audita
        // DocumentacionObserver (solo PLACA, NRO_DE_DOCUMENTO y NOMBRE_DEL_TITULAR), asi que
        // las registra CorrectorFichaDocumento con su origen.
        $log = EquipoAuditLog::where('ID_EQUIPO', $equipo)->latest('created_at')->first();
        $this->assertNotNull($log, 'El cambio tiene que quedar en el historial del equipo.');
        $this->assertSame('Verificación de documentos (automática)', $log->CAMBIOS['_origen'] ?? null);
        $this->assertArrayHasKey('ID_SEGURO', $log->CAMBIOS);

        // Ya coincide: no hay nada que aplicar y la ficha no se vuelve a tocar.
        $this->assertArrayHasKey('error', $this->aplicar($reg));
    }

    public function test_diferencias_de_escritura_del_nombre_se_distinguen(): void
    {
        // Una letra de menos: puede estar mal el documento o la ficha, se avisa.
        [$e1, $p1] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $p1));
        $this->assertStringContainsString('una letra', (string) $this->verificarSinAplicar($e1, VerificacionDocumento::PROPIEDAD)->MOTIVO);

        // Letras de otro alfabeto que se ven iguales: la ficha queda inencontrable al buscarla.
        [$e2, $p2] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => "CONSTRUCTORA VIDALSA 27, \u{0421}.\u{0410}"]);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $p2));
        $this->assertStringContainsString('otro alfabeto', (string) $this->verificarSinAplicar($e2, VerificacionDocumento::PROPIEDAD)->MOTIVO);

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

    /**
     * Un PDF aplicado desde la carga masiva deja su fila con APLICADO_POR (quien pulso
     * Aplicar). La noche lo tomaba por una REVISION A MANO y le devolvia su "Aplicado" encima
     * de lo que acababa de leer: un documento de otro vehiculo quedaba escondido para siempre.
     */
    public function test_lo_aplicado_desde_la_carga_masiva_no_se_toma_por_una_revision_a_mano(): void
    {
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $driveId = \App\Models\DocumentoAnexo::driveIdDeLink($this->ficha($equipo)->LINK_DOC_PROPIEDAD);
        VerificacionDocumento::create([
            'ID_EQUIPO' => $equipo, 'TIPO' => VerificacionDocumento::PROPIEDAD, 'DRIVE_ID' => $driveId,
            'ORIGEN' => VerificacionDocumento::DE_CARGA_MASIVA, 'ESTADO' => VerificacionDocumento::APLICADO,
            'A_MANO' => false, 'INTENTOS' => 0, 'ARCHIVO' => 'titulo.pdf',
            'APLICADO_POR' => Usuario::query()->value('ID_USUARIO'), 'APLICADO_EN' => now(),
        ]);
        $this->lectorFalso($this->textoTitulo('OTRA EMPRESA, C.A', 'CC999DD'));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::DE_LA_NOCHE, $reg->ORIGEN);
        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO, 'lo que leyo la noche no se tapa');
        $this->assertTrue($reg->esDeOtroVehiculo());
        $this->assertNull($reg->APLICADO_POR);
    }

    public function test_un_documento_de_otro_vehiculo_se_avisa_y_no_se_puede_aplicar(): void
    {
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso($this->textoTitulo('OTRA EMPRESA, C.A', 'CC999DD'));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->esDeOtroVehiculo());
        $this->assertFalse($reg->aplicable(), 'Lo que hay que corregir es el PDF enlazado, no la ficha.');

        $this->assertArrayHasKey('error', $this->aplicar($reg));
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
        // Sin aplicar: lo que se prueba es el botón, y para eso la fila tiene que llegar viva.
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::POLIZA);

        // Entre la lectura y la correccion, alguien corrige el vencimiento a mano.
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update(['FECHA_VENC_POLIZA' => '2027-03-05']);

        $this->assertNotEmpty($this->aplicar($reg)['puestos'] ?? []);

        $ficha = $this->ficha($equipo);
        $this->assertSame('2027-03-05', substr($ficha->FECHA_VENC_POLIZA, 0, 10), 'La corrección a mano no se pisa.');
        $this->assertSame($this->aseguradora('PIRÁMIDE SEGUROS'), (int) $ficha->ID_SEGURO, 'Lo demás sí se aplica.');

        // Y la diferencia que quedó sigue a la vista, PARA REVISAR: la fila no se da por
        // resuelta y el botón ya no la puede pisar (si no, el segundo clic borraba la
        // corrección de la persona).
        $reg->refresh();
        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->A_MANO);
        $this->assertFalse($reg->aplicable());
        $this->assertArrayHasKey('FECHA_VENC_POLIZA', $reg->DIFERENCIAS);
        $this->assertSame('2027-03-05', $reg->DIFERENCIAS['FECHA_VENC_POLIZA']['ficha']);
        $this->assertArrayNotHasKey('ID_SEGURO', $reg->DIFERENCIAS, 'Lo ya corregido sale de la lista.');

        // Una segunda pasada no toca nada.
        $this->assertArrayHasKey('error', $this->aplicar($reg));
        $this->assertSame('2027-03-05', substr($this->ficha($equipo)->FECHA_VENC_POLIZA, 0, 10));
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

        $this->assertArrayHasKey('error', $this->aplicar($reg));
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

    /** ¿El comando volvería a leer ese documento? Se pregunta con SU MISMA cola. */
    private function enLaCola(int $equipo, string $tipo): bool
    {
        $columna = $tipo === VerificacionDocumento::POLIZA ? 'LINK_POLIZA_SEGURO' : 'LINK_DOC_PROPIEDAD';
        return VerificacionDocumento::pendientes($tipo, $columna)->where('d.ID_EQUIPO', $equipo)->exists();
    }

    public function test_si_no_se_lee_placa_ni_serial_no_se_ofrece_corregir(): void
    {
        // Escaneo en el que no se entiende ni la placa ni el serial: no hay forma de saber de
        // que vehículo es el PDF, así que la fila va al montón de "revisar a mano".
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nTRANSPORTE MILENIUM 0210 C.A \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->sinConfirmar());
        $this->assertTrue($reg->A_MANO);
        $this->assertFalse($reg->aplicable());
        $this->assertSame(1, VerificacionDocumento::paraRevisar()->where('ID_EQUIPO', $equipo)->count(),
            'Sale en el filtro "para revisar", no en lo que se corrige con un botón.');
        $this->assertSame(0, VerificacionDocumento::corregibles()->where('ID_EQUIPO', $equipo)->count());

        $this->assertArrayHasKey('error', $this->aplicar($reg));
        $this->assertSame('TRANSPORTE MILENUIM 0210, CA', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);
    }

    public function test_el_serial_del_chasis_vale_cuando_la_placa_no_se_lee(): void
    {
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');

        // El documento no trae placa legible, pero sí el serial de la ficha: se confirma igual.
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nTRANSPORTE MILENIUM 0210 C.A \n"
            . "Serial N.I.V.: $serial \nDado a los: 3 días del mes de: OCTUBRE de: 2018 \n");

        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertFalse($reg->sinConfirmar());
        $this->assertFalse($reg->A_MANO);
        $this->assertTrue($reg->aplicable());
    }

    public function test_una_placa_mal_leida_no_gana_al_serial_que_si_coincide(): void
    {
        // Escaneo sucio: el reconocimiento se come la placa, pero el serial sale perfecto.
        // Basta uno de los dos para confirmar que el PDF es de esta ficha.
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nTRANSPORTE MILENIUM 0210 C.A \n"
            . "Placa: XX0000X \nSerial N.I.V.: $serial \nDado a los: 3 días del mes de: OCTUBRE de: 2018 \n");

        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertFalse($reg->esDeOtroVehiculo(), 'El serial confirma que es el mismo vehículo.');
        $this->assertTrue($reg->aplicable());
    }

    public function test_un_documento_de_otro_serial_tambien_se_avisa(): void
    {
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nOTRA EMPRESA, C.A \n"
            . "Serial N.I.V.: XYZ999999999 \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertTrue($reg->esDeOtroVehiculo());
        $this->assertTrue($reg->A_MANO);
        $this->assertFalse($reg->aplicable());
        $this->assertStringContainsString('XYZ999999999', (string) $reg->MOTIVO);
    }

    public function test_el_rotc_trae_propietario_y_sus_dos_fechas(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos([
            'NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_ROTC' => '2026-01-01',
        ]);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        $this->lectorFalso($this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', $placa, $serial, '30/05/2025', '30/05/2026'));

        $reg = $this->verificar($equipo, VerificacionDocumento::ROTC);

        // Manda el documento: las dos fechas de la ficha quedan como dice el ROTC.
        $ficha = $this->ficha($equipo);
        $this->assertSame('2026-05-30', substr((string) $ficha->FECHA_ROTC, 0, 10), 'La ficha guarda el vencimiento.');
        $this->assertSame('2025-05-30', substr((string) $ficha->FECHA_EMISION_ROTC, 0, 10));
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertSame('49199', $reg->LEIDO['nro']);
    }

    public function test_un_pdf_anterior_no_pisa_el_vencimiento_nuevo_de_la_ficha(): void
    {
        // Caso real (18-09-2026): la ficha ya tiene el ROTC renovado (03/07/2027) pero el PDF
        // enlazado es el viejo (vence 30/05/2026). "Manda el documento" vale para el VIGENTE:
        // del anterior no se propone ni se pone nada, y queda para que alguien enlace el nuevo.
        [$equipo, $placa] = $this->equipoConDocumentos([
            'NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_ROTC' => '2027-07-03', 'FECHA_EMISION_ROTC' => '2026-07-03',
        ]);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        $this->lectorFalso($this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', $placa, $serial, '30/05/2025', '30/05/2026'));

        $reg = $this->verificar($equipo, VerificacionDocumento::ROTC);
        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->A_MANO, 'Va a "para revisar": hay que enlazar el vigente.');
        $this->assertNull($reg->DIFERENCIAS, 'No se propone ninguna fecha del PDF viejo.');
        $this->assertTrue($reg->esDocumentoAnterior());
        $this->assertStringContainsString('ANTERIOR: vence el 30/05/2026 y la ficha ya dice 03/07/2027', (string) $reg->MOTIVO);
        $ficha = $this->ficha($equipo);
        $this->assertSame('2027-07-03', substr((string) $ficha->FECHA_ROTC, 0, 10));
        $this->assertSame('2026-07-03', substr((string) $ficha->FECHA_EMISION_ROTC, 0, 10));

        // Lo mismo con la poliza: ni la aseguradora ni las fechas del cuadro viejo.
        [$otro, $placa2] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2027-02-19']);
        $serial2 = DB::table('equipos')->where('ID_EQUIPO', $otro)->value('SERIAL_CHASIS');
        $this->lectorFalso("PIRAMIDE SEGUROS\nPlaca: $placa2\nSerial Carroceria: $serial2\nVigencia del Seguro: 19/02/2025 al 19/02/2026\n");
        $pol = $this->verificar($otro, VerificacionDocumento::POLIZA);
        $this->assertTrue($pol->A_MANO);
        $this->assertNull($pol->DIFERENCIAS);
        $this->assertSame('2027-02-19', substr((string) $this->ficha($otro)->FECHA_VENC_POLIZA, 0, 10));

        // El vigente (vence DESPUES) si manda: la ficha se pone al dia.
        [$tercero, $placa3] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A', 'FECHA_ROTC' => '2026-05-30']);
        $serial3 = DB::table('equipos')->where('ID_EQUIPO', $tercero)->value('SERIAL_CHASIS');
        $this->lectorFalso($this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', $placa3, $serial3, '03/07/2026', '03/07/2027'));
        $this->verificar($tercero, VerificacionDocumento::ROTC);
        $this->assertSame('2027-07-03', substr((string) $this->ficha($tercero)->FECHA_ROTC, 0, 10));
    }

    public function test_el_rotc_de_flota_usa_la_fila_del_equipo(): void
    {
        // La hoja del ROTC de flota (visto el 18-09-2026): la tabla con una fila por vehiculo
        // —placa, serial y, al lado del serial, su vencimiento— y debajo el certificado de
        // UNO de ellos. Un equipo de la tabla esta amparado aunque el certificado sea de otro.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A', 'FECHA_ROTC' => '2026-05-30']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        [$fuera, $placaFuera] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A', 'FECHA_ROTC' => '2026-05-30']);
        $tabla = "Fecha y Hora de Emisión: 03/07/2026 12:20:15 PM Pág. 6/34 FLOTA VEHICULAR DE TRANSPORTE DE CARGA\n"
            . "Operadora: CONSTRUCTORA VIDALSA 27, C.A (J-29387719-9) Número de ROTC: 49199 Fecha de vencimiento: 03/07/2027\n"
            . "\t69 A45AF5Y JAC HFC3252KR1K3 2017 VOLTEO 3 16200 Ton. LJ13R8DK3H3400167 03/07/2027\n"
            . "\t70 $placa JAC HFC3252KR1K3 2017 VOLTEO 3 16200 Ton. $serial 03/07/2027\n"
            . "\t71 A45BN3R SINOTRUK ZZ4257V344JB1 2025 CAMION TRACTOR 3 17100 Ton. LZZPCMSCXSJ389196 03/07/2027\n";
        $texto = $tabla . $this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', 'A45BN3R', 'LZZPCMSCXSJ389196', '03/07/2026', '03/07/2027');

        $this->lectorFalso($texto);
        $reg = $this->verificar($equipo, VerificacionDocumento::ROTC);
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO, (string) $reg->MOTIVO);
        $this->assertTrue($reg->LEIDO['en_tabla']);
        $this->assertSame('2027-07-03', substr((string) $this->ficha($equipo)->FECHA_ROTC, 0, 10), 'El vencimiento de SU fila.');
        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_ROTC, 'La emision del certificado de otro no se copia.');

        // Un equipo que no esta en la tabla: el documento sigue siendo de otro vehiculo.
        $this->lectorFalso($texto);
        $otro = $this->verificar($fuera, VerificacionDocumento::ROTC);
        $this->assertTrue($otro->esDeOtroVehiculo());
        $this->assertSame('2026-05-30', substr((string) $this->ficha($fuera)->FECHA_ROTC, 0, 10));
    }

    /** Una fila de la tabla del ROTC de flota, con el vencimiento al lado del serial. */
    private function filaTablaRotc(string $placa, string $serial, string $vence = '03/07/2027'): string
    {
        return "\t70 $placa JAC HFC3252KR1K3 2017 VOLTEO 3 16200 Ton. $serial $vence\n";
    }

    public function test_el_rotc_de_flota_con_el_final_del_serial_y_el_certificado_anterior(): void
    {
        // Ficha 1086 (21-09-2026): la ficha solo tiene el FINAL del serial y el certificado de
        // debajo es SUYO pero del periodo anterior (vence 30/05/2026). Manda su fila (03/07/2027):
        // no es "el PDF anterior", y la emision de ese otro periodo no se le pone.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A', 'FECHA_ROTC' => '2027-07-03']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        DB::table('equipos')->where('ID_EQUIPO', $equipo)->update(['SERIAL_CHASIS' => substr($serial, -8)]);

        $this->lectorFalso($this->filaTablaRotc($placa, $serial)
            . $this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', $placa, $serial, '30/05/2025', '30/05/2026'));
        $reg = $this->verificar($equipo, VerificacionDocumento::ROTC);

        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO, (string) $reg->MOTIVO);
        $this->assertFalse($reg->esDocumentoAnterior());
        $this->assertTrue($reg->LEIDO['en_tabla']);
        $this->assertSame('2027-07-03', substr((string) $this->ficha($equipo)->FECHA_ROTC, 0, 10));
        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_ROTC, 'La emision del certificado anterior no es la de esta fila.');
    }

    public function test_el_rotc_de_flota_con_otra_placa_para_el_mismo_serial_se_revisa_a_mano(): void
    {
        // Ficha 1105 (21-09-2026): el serial COMPLETO esta en la tabla, pero con otra placa. El
        // documento es de este vehiculo (el N.I.V. no se repite); cual placa es la buena lo
        // decide una persona, y nada se escribe solo.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A', 'FECHA_ROTC' => '2026-05-30']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');

        $this->lectorFalso($this->filaTablaRotc('A06EA3G', $serial)
            . $this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', 'A06EA3G', $serial, '30/05/2025', '30/05/2026'));
        $reg = $this->verificar($equipo, VerificacionDocumento::ROTC);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->A_MANO);
        $this->assertSame('A06EA3G', $reg->LEIDO['placa_en_tabla']);
        $this->assertStringContainsString("A06EA3G y la ficha dice $placa", (string) $reg->MOTIVO);
        $this->assertSame('2026-05-30', substr((string) $this->ficha($equipo)->FECHA_ROTC, 0, 10), 'No se aplica nada.');
    }

    public function test_una_placa_con_una_letra_cirilica_se_encuentra_en_el_racda(): void
    {
        // Ficha 573 (21-09-2026): "A10AE0Н" con la Н CIRILICA. Es la misma letra que la H de la
        // providencia: la unidad esta autorizada y la emision que le faltaba se pone sola.
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_RACDA' => '2027-07-14']);
        $latina = substr($placa, 0, -1) . 'H';
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update(['PLACA' => substr($placa, 0, -1) . 'Н']);
        $this->lectorFalso($this->textoRacda(['A00CT9K', $latina]));

        $reg = $this->verificar($equipo, VerificacionDocumento::RACDA);

        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO, (string) $reg->MOTIVO);
        $this->assertSame('2025-07-14', substr((string) $this->ficha($equipo)->FECHA_EMISION_RACDA, 0, 10));
    }

    public function test_la_migracion_pasa_a_latinas_las_placas_y_manda_a_releer(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos();
        $cirilica = substr($placa, 0, -1) . 'Н';
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update(['PLACA' => $cirilica]);
        $this->lectorFalso($this->textoRacda(['A00CT9K']));
        $this->verificar($equipo, VerificacionDocumento::RACDA);

        (require database_path('migrations/2026_09_21_190000_arreglar_placas_de_otro_alfabeto_y_releer_rotc.php'))->up();

        $this->assertSame(substr($placa, 0, -1) . 'H', $this->ficha($equipo)->PLACA);
        $this->assertFalse(VerificacionDocumento::where('ID_EQUIPO', $equipo)->exists(), 'Sus lecturas se rehacen.');
        $apunte = EquipoAuditLog::where('ID_EQUIPO', $equipo)->latest('ID_LOG')->first();
        $this->assertSame(['antes' => $cirilica, 'despues' => substr($placa, 0, -1) . 'H'], $apunte->CAMBIOS['PLACA']);
        $this->assertNotNull(VerificarDocumentos::pedidaAhora());
    }

    public function test_una_lectura_guardada_de_un_pdf_anterior_no_se_aplica(): void
    {
        // Filas leidas ANTES de esta regla, con la fecha vieja como "diferencia" pendiente: la
        // tarea las rellena sola al arrancar. No pueden poner la fecha vieja encima de la buena.
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_ROTC' => '2027-07-03']);
        $reg = VerificacionDocumento::create([
            'ID_EQUIPO' => $equipo, 'TIPO' => VerificacionDocumento::ROTC, 'PLACA' => $placa, 'DRIVE_ID' => 'driveVIEJO',
            'LEIDO' => ['vence' => '2026-05-30', 'placa' => $placa], 'ESTADO' => VerificacionDocumento::DIFIERE, 'A_MANO' => false,
            'DIFERENCIAS' => ['FECHA_ROTC' => ['etiqueta' => 'Vencimiento', 'ficha' => '2027-07-03', 'documento' => '2026-05-30'],
                              'FECHA_EMISION_ROTC' => ['etiqueta' => 'Fecha de emisión', 'ficha' => null, 'documento' => '2025-05-30']],
        ]);

        $this->assertSame([], $this->aplicar($reg)['puestos']);
        $reg->refresh();
        $this->assertTrue($reg->A_MANO);
        $this->assertNull($reg->DIFERENCIAS);
        $this->assertTrue($reg->esDocumentoAnterior());
        $this->assertStringContainsString('ANTERIOR', (string) $reg->MOTIVO);
        $ficha = $this->ficha($equipo);
        $this->assertSame('2027-07-03', substr((string) $ficha->FECHA_ROTC, 0, 10));
        $this->assertNull($ficha->FECHA_EMISION_ROTC);
    }

    public function test_la_migracion_del_18_09_deshace_los_vencimientos_que_retrocedieron(): void
    {
        $origen = ['_origen' => 'Verificación de documentos (automática)'];
        // 1) La tarea le puso a la ficha la fecha de un ROTC viejo (antes 2027, despues 2026).
        [$mal] = $this->equipoConDocumentos(['FECHA_ROTC' => '2026-05-30', 'FECHA_EMISION_ROTC' => '2025-05-30']);
        EquipoAuditLog::registrar($mal, 'edit', ['FECHA_ROTC' => ['antes' => '2027-07-03', 'despues' => '2026-05-30'],
            'FECHA_EMISION_ROTC' => ['antes' => '2026-07-03', 'despues' => '2025-05-30']] + $origen);
        // 2) Igual, pero despues alguien corrigio la ficha a mano: manda lo suyo.
        [$tocada] = $this->equipoConDocumentos(['FECHA_ROTC' => '2028-01-01']);
        EquipoAuditLog::registrar($tocada, 'edit', ['FECHA_ROTC' => ['antes' => '2027-07-03', 'despues' => '2026-05-30']] + $origen);
        // 3) Una puesta al dia normal (la fecha AVANZO): se queda.
        [$bien] = $this->equipoConDocumentos(['FECHA_ROTC' => '2027-07-03']);
        EquipoAuditLog::registrar($bien, 'edit', ['FECHA_ROTC' => ['antes' => '2026-05-30', 'despues' => '2027-07-03']] + $origen);
        // Y una lectura de ROTC y otra de poliza: se relee el ROTC, la poliza no.
        foreach ([VerificacionDocumento::ROTC, VerificacionDocumento::POLIZA] as $tipo) {
            VerificacionDocumento::create(['ID_EQUIPO' => $bien, 'TIPO' => $tipo, 'DRIVE_ID' => 'drive' . $tipo, 'ESTADO' => VerificacionDocumento::COINCIDE]);
        }

        (require database_path('migrations/2026_09_18_111000_releer_rotc_racda_y_deshacer_vencimientos_viejos.php'))->up();

        $this->assertSame('2027-07-03', substr((string) $this->ficha($mal)->FECHA_ROTC, 0, 10), 'Vuelve la fecha buena.');
        $this->assertSame('2026-07-03', substr((string) $this->ficha($mal)->FECHA_EMISION_ROTC, 0, 10), 'Y su emision.');
        $this->assertStringContainsString('PDF anterior', (string) json_encode(
            EquipoAuditLog::where('ID_EQUIPO', $mal)->orderByDesc('ID_LOG')->value('CAMBIOS'), JSON_UNESCAPED_UNICODE));
        $this->assertSame('2028-01-01', substr((string) $this->ficha($tocada)->FECHA_ROTC, 0, 10));
        $this->assertSame('2027-07-03', substr((string) $this->ficha($bien)->FECHA_ROTC, 0, 10));
        $this->assertSame([VerificacionDocumento::POLIZA],
            VerificacionDocumento::where('ID_EQUIPO', $bien)->pluck('TIPO')->all(), 'El ROTC queda para releer.');
    }

    public function test_el_programador_respeta_las_franjas_y_no_arranca_sin_trabajo(): void
    {
        // Lectura 8 p.m. - 12 de la noche y compresion 2 - 5 a.m., sin tocarse.
        // Se mira cada tarea a varias horas SIN el filtro de "es el servidor" (en el PC de
        // desarrollo no corre nunca): franja + "hay trabajo".
        $pasan = function (string $hora, string $comando, bool $nadaEstaNoche = false) {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse("2026-09-18 $hora", config('app.timezone')));
            \Illuminate\Support\Facades\Cache::put('docs_comprimir_nada', $nadaEstaNoche, 60);
            $schedule = new \Illuminate\Console\Scheduling\Schedule(config('app.timezone'));
            \Illuminate\Support\Facades\Schedule::swap($schedule);
            // routes/console.php se carga en cada schedule:run: aqui igual, con la hora puesta.
            require base_path('routes/console.php');
            $evento = collect($schedule->events())->first(fn ($e) => str_contains($e->command, $comando));
            $filtros = (new \ReflectionProperty($evento, 'filters'))->getValue($evento);
            // [0] franja · [1] es el servidor (se salta) · [2] hay trabajo.
            return $this->app->call($filtros[0]) && $this->app->call($filtros[2]);
        };

        foreach (['20:00' => true, '23:59' => true, '00:00' => false, '00:30' => false, '10:00' => false, '19:59' => false] as $h => $sale) {
            $this->assertSame($sale, $pasan($h, 'docs:verificar-documentos --lote=25 --parte=0'), "Lectura a las $h");
        }
        foreach (['02:00' => true, '04:30' => true, '05:01' => false, '21:00' => false] as $h => $sale) {
            $this->assertSame($sale, $pasan($h, 'docs:comprimir'), "Compresion a las $h");
        }
        $this->assertFalse($pasan('03:00', 'docs:comprimir', true), 'Sin nada por comprimir esta noche, no se lanza.');

        // "Revisar ahora" del panel: a cualquier hora, la lectura arranca igual.
        \Illuminate\Support\Facades\Cache::forget('docs_verificar_ahora');
        $this->assertFalse($pasan('12:00', 'docs:verificar-documentos --lote=25 --parte=0'));
        $this->actingAs($this->superAdmin())->postJson(route('compresion-pdf.documentos.leer-ahora'))
            ->assertOk()->assertJson(['success' => true]);
        $this->assertTrue($pasan('12:01', 'docs:verificar-documentos --lote=25 --parte=0'), 'Pedida: corre ya.');
        \Illuminate\Support\Facades\Cache::forget('docs_verificar_ahora');
        \Carbon\Carbon::setTestNow();
        \Illuminate\Support\Facades\Cache::forget('docs_comprimir_nada');

        // "¿Hay trabajo?" de la lectura (arriba ya dio que si: hay fichas sin leer). Sin nada
        // pendiente ni por poner, no: se prueba con la cola vacia dentro de la transaccion.
        DB::table('documentacion')->update(['LINK_DOC_PROPIEDAD' => null, 'LINK_POLIZA_SEGURO' => null, 'LINK_ROTC' => null, 'LINK_RACDA' => null]);
        VerificacionDocumento::query()->update(['A_MANO' => true]);
        $this->assertFalse(VerificacionDocumento::hayTrabajo());
        [$equipo] = $this->equipoConDocumentos();
        $this->assertTrue(VerificacionDocumento::hayTrabajo(), 'Un documento nuevo sin leer vuelve a dar trabajo.');
    }

    public function test_lo_que_no_se_pudo_leer_se_vuelve_a_leer_cada_noche(): void
    {
        // Agotados sus intentos, un "No se pudo leer" no se relee esa noche (la tarea termina
        // y se apaga), pero la siguiente SI, una vez. Y "Revisar ahora" lo relee al momento.
        \Illuminate\Support\Facades\Cache::forget('docs_verificar_ahora');
        [$equipo] = $this->equipoConDocumentos();
        $this->lectorFalso('hoja en blanco sin nada util');
        for ($i = 1; $i <= VerificacionDocumento::MAX_INTENTOS; $i++) $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);
        $this->assertFalse($this->enLaCola($equipo, VerificacionDocumento::PROPIEDAD), 'Agotado: esta noche ya no.');

        // Se leyo antes de que empezara la noche en curso: vuelve a la cola.
        VerificacionDocumento::where('ID_EQUIPO', $equipo)->update(['updated_at' => now()->subDays(2)]);
        $this->assertTrue($this->enLaCola($equipo, VerificacionDocumento::PROPIEDAD), 'Noche nueva: se relee.');
        $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);
        $this->assertFalse($this->enLaCola($equipo, VerificacionDocumento::PROPIEDAD), 'Una vez por noche, no en bucle.');

        // Releida dos veces seguidas la misma noche: la segunda ya no la encola otra vez.
        $reg = VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', VerificacionDocumento::PROPIEDAD)->first();
        VerificacionDocumento::where('ID_REGISTRO', $reg->ID_REGISTRO)->update(['updated_at' => now()->subDays(2)]);
        $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);
        $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);
        $this->assertFalse($this->enLaCola($equipo, VerificacionDocumento::PROPIEDAD));

        // "Revisar ahora": lo leido hasta ese momento vuelve a la cola.
        \Carbon\Carbon::setTestNow(now()->addMinute());
        \App\Console\Commands\VerificarDocumentos::pedirAhora();
        $this->assertTrue($this->enLaCola($equipo, VerificacionDocumento::PROPIEDAD), 'Pedida la revision: se relee ya.');
        \Carbon\Carbon::setTestNow();
        \Illuminate\Support\Facades\Cache::forget('docs_verificar_ahora');
    }

    public function test_el_anexo_de_flota_toma_la_fecha_de_su_firma(): void
    {
        // Anexo de poliza de flota (visto el 19-09-2026, RCGE-001001-20572): no trae "Desde /
        // Hasta"; la emision es la fecha de la firma de la ultima pagina y vence un año despues.
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2026-06-01']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        $this->lectorFalso("ANEXO Nro.:\n1\nRESPONSABILIDAD CIVIL GENERAL\nPOLIZA Nro.: RCGE-001001-20572\n"
            . "SE AMAPARA LOS SIGUIENTES VEHÍCULOS Y/O EQUIPOS\n"
            . "-JAC HFC4250KR1K3 - 2017 $placa 39000 KGS 3 $serial 1417E060663 - CHUTO CARGA ROJO\n"
            . "-IVECO 230E22 EUROCARGO 2008 A26AA8V 16370 KGS 3 8ATE2KF008X063632 9927502 - PLATF\n"
            . "CLIENTE Pagina 1 de\nanexo 2\n"
            . "En consecuencia de lo cual se firma en la ciudad de CARACAS a los 25 días del mes de Marzo del año 2026.\n");

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);
        $this->assertSame('2026-03-25', $reg->LEIDO['emision']);
        $this->assertSame('2027-03-25', $reg->LEIDO['vence']);
        $this->assertTrue($reg->LEIDO['vence_por_firma']);
        $ficha = $this->ficha($equipo);
        $this->assertSame('2027-03-25', substr((string) $ficha->FECHA_VENC_POLIZA, 0, 10), 'Vence un año despues de la firma.');
        $this->assertSame('2026-03-25', substr((string) $ficha->FECHA_EMISION_POLIZA, 0, 10));

        // Una poliza normal (con su vigencia) no mira la firma aunque la traiga.
        $l = app(\App\Services\LectorDocumentoPdf::class);
        $d = $l->extraer('poliza', "ANEXO\nVigencia del Seguro: 19/02/2026 al 19/02/2027\nse firma en la ciudad de CARACAS a los 25 días del mes de Marzo del año 2026");
        $this->assertSame('2027-02-19', $d['vence']);
        $this->assertArrayNotHasKey('vence_por_firma', $d);
    }

    public function test_el_racda_comprueba_que_la_placa_este_autorizada(): void
    {
        // La providencia es de la EMPRESA: lo que dice de este equipo es si su placa está en
        // la lista. Si está, se comparan las fechas (vale dos años desde que se emitió).
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_RACDA' => '2027-07-14']);
        $this->lectorFalso($this->textoRacda(['A00CT9K', $placa, 'A09CW3M']));

        $reg = $this->verificar($equipo, VerificacionDocumento::RACDA);

        // La ficha no tenia fecha de emision: se pone sola y no queda nada que decidir.
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertFalse($reg->A_MANO);
        $this->assertSame('2025-07-14', substr((string) $this->ficha($equipo)->FECHA_EMISION_RACDA, 0, 10));
        $this->assertSame('2027-07-14', substr((string) $this->ficha($equipo)->FECHA_RACDA, 0, 10),
            'El vencimiento de la ficha ya cuadra (emisión + 2 años) y no se toca.');
        $this->assertSame(3, count($reg->LEIDO['placas']));
    }

    public function test_un_equipo_fuera_de_la_lista_del_racda_se_avisa(): void
    {
        [$equipo] = $this->equipoConDocumentos(['FECHA_RACDA' => '2027-07-14']);
        $this->lectorFalso($this->textoRacda(['A00CT9K', 'A09CW3M', 'A11AT9F']));

        $reg = $this->verificar($equipo, VerificacionDocumento::RACDA);

        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertTrue($reg->A_MANO, 'No lo arregla un botón: o falta el trámite o la providencia no lo cubre.');
        $this->assertFalse($reg->aplicable());
        $this->assertStringContainsString('NO esta entre las 3 unidades', (string) $reg->MOTIVO);
    }

    public function test_una_poliza_con_los_rotulos_aparte_de_los_valores_se_reconoce(): void
    {
        // Asi salen las polizas de Pirámide: primero el bloque de rótulos ("Marca: Modelo:
        // Placa: Uso:") y varias líneas más abajo el de los valores. Buscar "Placa: X" no
        // encuentra nada, pero la placa está escrita en la hoja y con eso basta.
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2027-02-19']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        $this->lectorFalso("PIRÁMIDE SEGUROS \nCUADRO Y RECIBO DE PÓLIZAS \n"
            . "Vigencia del Recibo 19/02/2026 al 19/02/2027 Fecha de Emisión: 19/02/2026 \n"
            . "DATOS DEL VEHÍCULO \nMarca: \nModelo: \nPlaca: \nUso: \nSerial Carroceria: \n"
            . "SINOTRUK \nZZ1037G322PB5BM2 \n$placa \nCARGA \n$serial \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertFalse($reg->sinConfirmar(), 'La placa suelta en la hoja confirma el vehículo.');
        $this->assertFalse($reg->esDeOtroVehiculo());
        // La vigencia, entera: el rótulo pegado delante no puede comerse el primer dígito
        // ("Vigencia del Recibo 19/02/2026" se leía 9/02/2026).
        $this->assertSame('2026-02-19', $reg->LEIDO['desde']);
        $this->assertSame('2027-02-19', $reg->LEIDO['vence']);
        $this->assertSame('2026-02-19', $reg->LEIDO['emision']);
        $this->assertArrayNotHasKey('FECHA_VENC_POLIZA', $reg->DIFERENCIAS ?? [], 'El vencimiento de la ficha ya cuadra.');
    }

    public function test_una_placa_del_formato_viejo_tambien_se_encuentra_en_el_racda(): void
    {
        // En la flota hay 82 unidades con placas de los formatos viejos (AB037BY, AO577ZB...).
        // Si el lector solo reconociera el formato actual, todas saldrían como "no autorizada".
        $letra = fn () => chr(random_int(65, 90));
        $vieja = $letra() . $letra() . random_int(100, 999) . $letra() . $letra();
        [$equipo] = $this->equipoConDocumentos(['FECHA_RACDA' => '2027-07-14']);
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)->update(['PLACA' => $vieja]);
        $this->lectorFalso($this->textoRacda(['A00CT9K', $vieja, 'A09CW3M']));

        $reg = $this->verificar($equipo, VerificacionDocumento::RACDA);

        $this->assertNotSame(VerificacionDocumento::ILEGIBLE, $reg->ESTADO);
        $this->assertStringNotContainsString('NO esta entre', (string) $reg->MOTIVO, "La placa $vieja sí está en la lista.");
        $this->assertArrayNotHasKey('fuera_de_lista', $reg->LEIDO);
    }

    public function test_un_racda_sin_lista_de_placas_no_se_da_por_bueno(): void
    {
        // Si de la providencia solo se leyó la fecha, no se comprobó lo único que dice de este
        // equipo: que esté autorizado. Eso es ilegible, no "verificado".
        [$equipo] = $this->equipoConDocumentos(['FECHA_RACDA' => '2027-07-14']);
        $this->lectorFalso("PROVIDENCIA ADMINISTRATIVA N° 1120 \nCARACAS, 14 DE JULIO DE 2025 \n"
            . "tendrá validez por DOS (02) años \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::RACDA);

        $this->assertSame(VerificacionDocumento::ILEGIBLE, $reg->ESTADO);
        $this->assertFalse($reg->aplicable());
    }

    public function test_el_rotulo_rif_pegado_al_nombre_no_llega_a_la_ficha(): void
    {
        // Visto en un título real: el reconocimiento pega el rótulo al nombre sin nada que los
        // separe ("Cédula o RIFCORPO NAC DE LOGISTICA...") y así se habría escrito en la ficha.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CORPO NAC DE LOGISTICA Y TRANSPORTE DECARGA S.A']);
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \n"
            . "Cédula o RIFCORPO NAC DE LOGISTICA Y TRANSPORTE DECARGA S.A \nPlaca: $placa \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame('CORPO NAC DE LOGISTICA Y TRANSPORTE DECARGA S.A', $reg->LEIDO['titular']);
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
    }

    public function test_lo_que_dice_el_pdf_se_pone_solo_en_la_ficha(): void
    {
        // Ficha con el propietario correcto y SIN fecha de emisión: el dato que falta se pone
        // solo en cuanto se lee el documento, sin que nadie pulse nada.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame('2018-10-03', substr((string) $this->ficha($equipo)->FECHA_EMISION_PROPIEDAD, 0, 10));
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO, 'Ya no queda nada distinto.');
        $this->assertNull($reg->DIFERENCIAS);
        $this->assertNull($reg->APLICADO_POR, 'No lo aplicó ninguna persona.');
        $this->assertNotNull($reg->APLICADO_EN);
        $this->assertStringContainsString('solo', (string) $reg->MOTIVO);
    }

    public function test_el_documento_manda_sobre_lo_que_ya_estaba_escrito(): void
    {
        // Fechas viejas en la ficha y otras en la póliza: manda el papel, sin preguntar.
        $mampreca = $this->aseguradora('MAMPRECA');
        [$equipo, $placa] = $this->equipoConDocumentos([
            'ID_SEGURO' => $mampreca, 'FECHA_VENC_POLIZA' => '2026-01-01', 'FECHA_EMISION_POLIZA' => '2025-01-01',
        ]);
        $this->lectorFalso($this->textoPoliza('MAMPRECA', $placa, '19/02/2027'));

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $ficha = $this->ficha($equipo);
        $this->assertSame('2027-02-19', substr((string) $ficha->FECHA_VENC_POLIZA, 0, 10));
        $this->assertSame('2026-02-19', substr((string) $ficha->FECHA_EMISION_POLIZA, 0, 10));
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertNotNull($reg->APLICADO_EN);
        $this->assertNull($reg->APLICADO_POR, 'Lo hizo el comando, no una persona.');
    }

    public function test_nunca_escribe_la_placa_ni_el_serial(): void
    {
        // La lista de lo que se puede escribir es cerrada: la identidad del vehiculo NO esta,
        // porque es justo lo que sirve para saber si el PDF es de esta ficha.
        $this->assertNotContains('PLACA', CorrectorFichaDocumento::CAMPOS);
        $this->assertNotContains('SERIAL_CARROCERIA', CorrectorFichaDocumento::CAMPOS);
        $this->assertNotContains('SERIAL_CHASIS', CorrectorFichaDocumento::CAMPOS);

        // Y aunque llegara una diferencia de placa, no se escribe: se queda a la vista.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placa));
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);
        $reg->update(['DIFERENCIAS' => ['PLACA' => ['etiqueta' => 'Placa', 'ficha' => $placa, 'documento' => 'A00XX9X']]
            + (array) $reg->DIFERENCIAS]);

        $this->aplicar($reg);

        $this->assertSame($placa, $this->ficha($equipo)->PLACA, 'La placa de la ficha no se toca nunca.');
    }

    public function test_con_no_rellenar_solo_anota_y_no_escribe_nada(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));

        $this->artisan('docs:verificar-documentos', ['--equipo' => $equipo, '--tipo' => VerificacionDocumento::PROPIEDAD,
            '--no-rellenar' => true])->assertSuccessful();

        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_PROPIEDAD);
        $reg = VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', VerificacionDocumento::PROPIEDAD)->firstOrFail();
        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);
        $this->assertSame('2018-10-03', $reg->DIFERENCIAS['FECHA_EMISION_PROPIEDAD']['documento']);
    }

    public function test_lo_leido_otras_noches_tambien_se_rellena_sin_volver_a_drive(): void
    {
        // Filas de antes de que existiera el rellenado automatico: la pasada siguiente las
        // completa con la lectura que ya tiene guardada, sin pedirle el PDF a Drive otra vez.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));
        $this->artisan('docs:verificar-documentos', ['--equipo' => $equipo, '--tipo' => VerificacionDocumento::PROPIEDAD,
            '--no-rellenar' => true])->assertSuccessful();
        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_PROPIEDAD);

        // Sin --equipo: la ficha ya está leída, así que de Drive no se pide nada; lo único que
        // hace la pasada es rellenar el hueco.
        $this->artisan('docs:verificar-documentos', ['--tipo' => VerificacionDocumento::PROPIEDAD, '--lote' => 1])
            ->assertSuccessful();

        $this->assertSame('2018-10-03', substr((string) $this->ficha($equipo)->FECHA_EMISION_PROPIEDAD, 0, 10));
        $reg = VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', VerificacionDocumento::PROPIEDAD)->firstOrFail();
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
    }

    public function test_si_la_ficha_cambio_entera_a_mano_la_fila_queda_para_mirar(): void
    {
        // Caso límite del anterior: la corrección a mano tocó el ÚNICO dato que había que
        // aplicar. No queda nada que escribir, y la fila tiene que quedar marcada igual; si
        // no, ningún botón la arregla y la pasada de cada día la reintenta para siempre.
        $mampreca = $this->aseguradora('MAMPRECA');
        [$equipo, $placa] = $this->equipoConDocumentos([
            'ID_SEGURO' => $mampreca, 'FECHA_VENC_POLIZA' => '2026-01-01', 'FECHA_EMISION_POLIZA' => '2025-01-01',
        ]);
        $this->lectorFalso($this->textoPoliza('MAMPRECA', $placa, '19/02/2027'));
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::POLIZA);
        $this->assertSame(['FECHA_VENC_POLIZA', 'FECHA_EMISION_POLIZA'], array_keys($reg->DIFERENCIAS));

        // Alguien corrige a mano las dos fechas entre la lectura y la aplicación.
        DB::table('documentacion')->where('ID_EQUIPO', $equipo)
            ->update(['FECHA_VENC_POLIZA' => '2027-03-05', 'FECHA_EMISION_POLIZA' => '2026-03-05']);

        $this->aplicar($reg);

        $ficha = $this->ficha($equipo);
        $this->assertSame('2027-03-05', substr((string) $ficha->FECHA_VENC_POLIZA, 0, 10), 'Lo de la persona manda.');
        $reg->refresh();
        $this->assertTrue($reg->A_MANO, 'Queda para mirarla con el PDF delante.');
        $this->assertFalse($reg->aplicable(), 'Ningún botón la puede pisar.');
        $this->assertSame(0, VerificacionDocumento::corregibles()->where('ID_EQUIPO', $equipo)->count(),
            'Y sale de la cola: si no, se reintentaría todas las noches.');
        $this->assertStringContainsString('Alguien cambió la ficha', (string) $reg->MOTIVO);
    }

    public function test_un_escaneo_sin_placa_legible_no_acusa_al_archivo_de_ser_de_otro(): void
    {
        // En la hoja hay códigos con pinta de placa ("3500KG") pero NO la del vehículo. Eso es
        // "no se pudo confirmar", no "es de otro vehículo": lo segundo manda a una persona a
        // corregir un enlace que está bien.
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nCONSTRUCTORA VIDALSA 27, C.A \n"
            . "Capacidad: 3500KG Carga: 1050KG \nDado a los: 3 días del mes de: OCTUBRE de: 2018 \n");

        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertFalse($reg->esDeOtroVehiculo(), 'No se puede afirmar que sea de otro.');
        $this->assertTrue($reg->sinConfirmar());
        $this->assertStringContainsString('no se leyó placa ni serial', (string) $reg->MOTIVO);
    }

    public function test_la_placa_del_rotulo_manda_sobre_la_que_aparezca_suelta(): void
    {
        // Póliza de flota: el rótulo "Placa:" nombra a OTRA unidad, pero la placa de esta
        // ficha aparece suelta más abajo (la hoja lista varios vehículos). Eso NO la convierte
        // en el documento de esta ficha: manda lo que va tras el rótulo.
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2026-01-01']);
        $this->lectorFalso("MAMPRECA \nCUADRO Y RECIBO DE PÓLIZAS \n"
            . "Vigencia del Seguro: 19/02/2026 al 19/02/2027 Fecha de Emisión: 19/02/2026 \n"
            . "DATOS DEL VEHÍCULO \nPlaca: A00XX9X \nMarca: TOYOTA \n"
            . "Otras unidades de la flota: $placa, A09CW3M \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertTrue($reg->esDeOtroVehiculo(), 'El rótulo dice que es de otra unidad.');
        $this->assertTrue($reg->A_MANO);
        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_POLIZA, 'No se le escribe nada a la ficha.');
        $this->assertSame('2026-01-01', substr((string) $this->ficha($equipo)->FECHA_VENC_POLIZA, 0, 10));
    }

    public function test_el_filtro_sin_aplicar_abre_la_pantalla(): void
    {
        // La tarjeta "Sin aplicar" enlaza a estado_doc=corregibles: ese filtro tiene que
        // existir en la lista del desplegable o la pantalla revienta al pulsarla.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placa));
        $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->actingAs($this->superAdmin())
            ->get(route('historial-documentos.index', ['pestana' => 'documentos', 'estado_doc' => 'corregibles']))
            ->assertOk()
            ->assertSee($placa, false)
            ->assertSee('cpdfRevisar', false)->assertDontSee('Corregir ficha', false);
    }

    public function test_un_racda_que_no_es_una_providencia_no_se_da_por_bueno(): void
    {
        // Si en el enlace del RACDA se subió por error el título del mismo equipo, su placa
        // aparece en el texto y no hay fechas que comparar: sin más comprobación, la fila
        // quedaría "coincide" y el documento mal enlazado pasaría por verificado.
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_RACDA' => '2027-07-14']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::RACDA);

        $this->assertSame(VerificacionDocumento::ILEGIBLE, $reg->ESTADO);
        $this->assertStringContainsString('no parece una providencia', (string) $reg->MOTIVO);
    }

    public function test_con_equipo_solo_se_aplica_esa_ficha(): void
    {
        // --equipo es para probar UNA ficha: no puede aplicar de golpe lo pendiente de toda
        // la flota.
        [$uno, $placaUno] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        [$otro, $placaOtro] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placaUno));
        $this->verificarSinAplicar($uno, VerificacionDocumento::PROPIEDAD);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placaOtro));
        $this->verificarSinAplicar($otro, VerificacionDocumento::PROPIEDAD);

        $this->artisan('docs:verificar-documentos', ['--equipo' => $uno, '--tipo' => VerificacionDocumento::PROPIEDAD])
            ->assertSuccessful();

        $this->assertSame('TRANSPORTE MILENIUM 0210 C.A', $this->ficha($uno)->NOMBRE_DEL_TITULAR);
        $this->assertSame('TRANSPORTE MILENUIM 0210, CA', $this->ficha($otro)->NOMBRE_DEL_TITULAR,
            'La otra ficha no se toca.');
    }

    public function test_un_pdf_de_otro_vehiculo_no_rellena_nada(): void
    {
        // La red de seguridad: si no se puede afirmar que el PDF sea de esta ficha, el
        // rellenado automático no escribe NADA, ni siquiera donde falte el dato.
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nCONSTRUCTORA VIDALSA 27, C.A \n"
            . "Placa: A00XX9X \nSerial N.I.V.: 8XA00000000000999 \nDado a los: 3 días del mes de: OCTUBRE de: 2018 \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertTrue($reg->esDeOtroVehiculo());
        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_PROPIEDAD, 'No se escribe nada de un PDF de otro vehículo.');
        $this->assertNull($reg->APLICADO_EN);
    }

    public function test_la_puntuacion_del_nombre_no_es_una_diferencia(): void
    {
        // "27, C.A" y "27,CA" son el mismo nombre: el punto lo pone o lo quita el escaneo. Antes
        // contaba como "una letra" y la noche reescribia la ficha (y el historial) por un punto.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27,CA',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertSame('CONSTRUCTORA VIDALSA 27,CA', $this->ficha($equipo)->NOMBRE_DEL_TITULAR, 'No se toca por un punto.');
    }

    public function test_la_primera_letra_que_se_come_el_escaneo_no_llega_a_la_ficha(): void
    {
        // La primera letra va pegada al borde de la hoja y el escaneo la pierde o la cambia:
        // "ORPO NAC" donde el papel dice "CORPO NAC". Eso no lo dice el documento: no se escribe.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CORPO NAC DE LOGISTICA Y TRANSPORTE DE CARGA S.A']);
        $this->lectorFalso($this->textoTitulo('ORPO NAC DE LOGISTICA Y TRANSPORTE DE CARGA S.A', $placa));

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame('CORPO NAC DE LOGISTICA Y TRANSPORTE DE CARGA S.A', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);
        $this->assertTrue($reg->A_MANO, 'Queda para que lo mire una persona en el visor.');
        $this->assertStringContainsString('primera letra', (string) $reg->MOTIVO);
    }

    public function test_una_errata_real_de_la_ficha_si_se_corrige_sola(): void
    {
        // La otra cara: si la letra que falta esta DENTRO del nombre, es una errata de verdad
        // ("LOGSTCA", visto en la ficha 757) y manda el documento.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CORPO NAC DE LOGSTCA Y TRANSPORTE DE CARGA S.A']);
        $this->lectorFalso($this->textoTitulo('CORPO NAC DE LOGISTICA Y TRANSPORTE DE CARGA S.A', $placa));

        $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame('CORPO NAC DE LOGISTICA Y TRANSPORTE DE CARGA S.A', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);
    }

    public function test_la_mancha_pegada_al_final_del_nombre_no_llega_a_la_ficha(): void
    {
        // Volteos IVECO: el escaneo dice "CONTRUCTORA VIDALSA 27, C.Aaca:" — el "aca" en
        // minusculas es una mancha o un sello, no parte del nombre.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'GRUPO ROYSO C.A.']);
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nGRUPO ROYSO C.Aaca: \nPlaca: $placa \n");

        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);

        $this->assertSame('GRUPO ROYSO C.A', $reg->LEIDO['titular']);
    }

    /** Anexo de poliza de FLOTA, con el formato real (Piramide, "SE AMAPARA" incluido). */
    private function textoPolizaFlota(array $seriales): string
    {
        $filas = implode(' ', array_map(fn ($s) => "-LOVOL FR220D 2025 N/A 22200 KGS 1 $s 448549 EXCAVADORA MAQUINARIA PESADA AMARILLO", $seriales));
        return "TOMADOR: CONSTRUCTORA VIDALSA 27 CA \nRESPONSABILIDAD CIVIL GENERAL \nPOLIZA Nro.: RCGE-001001-20669 \n"
            . "ASEGURADO: CONSTRUCTORA VIDALSA 27, C.A. \nSE AMAPARA LOS SIGUIENTES VEHÍCULOS Y/O EQUIPOS \n"
            . "MARCA MODELO AÑO PLACA CAP DE CARGA PUESTOS S/CARROCERIA S/MOTOR TIPO USO VEH. COLOR $filas \n";
    }

    public function test_una_poliza_de_flota_que_no_ampara_al_equipo_se_avisa_como_de_otros(): void
    {
        // Visto el 18-09-2026: la ficha de un payloader enlazaba el anexo que ampara 4
        // excavadoras. Es de OTROS equipos, no "ilegible" ni "sin confirmar".
        [$equipo] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2027-04-08']);
        $this->lectorFalso($this->textoPolizaFlota(['FTC003RHLSS556872', 'FTC003RHASS556868']));

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertTrue($reg->esDeOtroVehiculo());
        $this->assertTrue($reg->A_MANO);
        $this->assertStringContainsString('NO ampara este equipo', (string) $reg->MOTIVO);
        $this->assertStringContainsString('FTC003RHLSS556872', (string) $reg->MOTIVO);
        $this->assertSame('2027-04-08', substr((string) $this->ficha($equipo)->FECHA_VENC_POLIZA, 0, 10), 'No se toca la ficha.');
    }

    public function test_una_poliza_de_flota_que_si_lo_ampara_dice_que_le_faltan_las_fechas(): void
    {
        [$equipo] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2027-04-08']);
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        $this->lectorFalso($this->textoPolizaFlota(['FTC003RHLSS556872', $serial]));

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertFalse($reg->esDeOtroVehiculo());
        $this->assertSame(VerificacionDocumento::ILEGIBLE, $reg->ESTADO);
        $this->assertStringContainsString('flota', (string) $reg->MOTIVO);
    }

    public function test_un_anexo_de_flota_sin_fechas_se_reintenta(): void
    {
        // Sus fechas salen de la firma de la ultima pagina (ver test_el_anexo_de_flota_toma_la_
        // fecha_de_su_firma). Si Drive devolvio el texto cortado, la siguiente lectura la trae:
        // no se da por definitivo, vuelve a la cola como cualquier ilegible.
        [$equipo] = $this->equipoConDocumentos();
        $serial = DB::table('equipos')->where('ID_EQUIPO', $equipo)->value('SERIAL_CHASIS');
        $this->lectorFalso($this->textoPolizaFlota([$serial]));

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertSame(VerificacionDocumento::ILEGIBLE, $reg->ESTADO);
        $this->assertSame(1, (int) $reg->INTENTOS);
        $this->assertSame(1, VerificacionDocumento::pendientes(VerificacionDocumento::POLIZA, 'LINK_POLIZA_SEGURO')
            ->where('d.ID_EQUIPO', $equipo)->count(), 'Vuelve a la cola.');

        // La relectura trae la ultima pagina: ahora si hay fechas.
        $this->lectorFalso("TEXTO ANEXO\n" . $this->textoPolizaFlota([$serial])
            . "En consecuencia de lo cual se firma en la ciudad de CARACAS a los 08 días del mes de Abril del año 2026.\n");
        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertNotSame(VerificacionDocumento::ILEGIBLE, $reg->ESTADO);
        $this->assertSame('2027-04-08', $reg->LEIDO['vence']);
    }

    public function test_reintentar_relee_los_ilegibles_aunque_hayan_agotado_sus_intentos(): void
    {
        // Tras mejorar el lector, lo que agoto sus intentos con el lector viejo se puede releer.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        $this->lectorFalso("hoja en blanco \n");
        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);
        $reg->update(['INTENTOS' => VerificacionDocumento::MAX_INTENTOS]);
        $this->assertSame(0, VerificacionDocumento::pendientes(VerificacionDocumento::PROPIEDAD, 'LINK_DOC_PROPIEDAD')
            ->where('d.ID_EQUIPO', $equipo)->count(), 'Agotado: la pasada normal ya no lo lee.');

        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));
        $this->artisan('docs:verificar-documentos', ['--tipo' => VerificacionDocumento::PROPIEDAD, '--reintentar' => true, '--lote' => 500])
            ->assertSuccessful();

        $this->assertNotSame(VerificacionDocumento::ILEGIBLE, $reg->refresh()->ESTADO, 'Con --reintentar se vuelve a leer.');
    }

    public function test_repartida_en_partes_cada_proceso_lee_solo_las_suyas(): void
    {
        // Con --parte/--de varios procesos leen a la vez sin coger los mismos documentos.
        $ids = [];
        for ($i = 0; $i < 4; $i++) { [$ids[]] = $this->equipoConDocumentos(); }
        $this->lectorFalso("hoja en blanco \n");
        $pares = array_values(array_filter($ids, fn ($id) => $id % 2 === 0));

        foreach ($ids as $id) {
            // cada ficha se lee con su parte; la de la otra parte no se toca
            $parte = $id % 2;
            $this->artisan('docs:verificar-documentos', ['--equipo' => $id, '--tipo' => VerificacionDocumento::PROPIEDAD,
                '--parte' => 1 - $parte, '--de' => 2])->assertSuccessful();
            $this->assertSame(0, VerificacionDocumento::where('ID_EQUIPO', $id)->count(), "La parte ajena no lee la ficha $id.");
        }
        foreach ($pares as $id) {
            $this->artisan('docs:verificar-documentos', ['--equipo' => $id, '--tipo' => VerificacionDocumento::PROPIEDAD,
                '--parte' => 0, '--de' => 2])->assertSuccessful();
            $this->assertSame(1, VerificacionDocumento::where('ID_EQUIPO', $id)->count(), "Su parte si la lee ($id).");
        }
        $this->artisan('docs:verificar-documentos', ['--parte' => 2, '--de' => 2])->assertFailed();
    }

    public function test_el_nombre_de_la_empresa_se_escribe_siempre_igual(): void
    {
        // Decision del cliente (18-09-2026): CONSTRUCTORA VIDALSA 27, C.A, y la errata de los
        // titulos del INTT ("CONTRUCTORA", sin S) cuenta como el mismo nombre.
        $l = app(\App\Services\LectorDocumentoPdf::class);
        foreach (['CONSTRUCTORA VIDALSA 27 C.A.', 'CONSTRUCTORA VIDALSA 27,CA', 'CONTRUCTORA VIDALSA 27, C.A',
                  'CONTRUCTORA VIDALSA 27, C.AACA', 'NCONSTRUCTORA VIDALSA 27, С.А', 'CONSTRUCTURA VIDALSA 27, C.A'] as $forma) {
            $this->assertSame('CONSTRUCTORA VIDALSA 27, C.A', $l->canonico($forma), $forma);
        }
        $this->assertSame('CONSTRUCTORA SERVISAGA, C.A', $l->canonico('CONSTRUCTORA SERVISAGA, C.A'), 'Otra empresa no se toca.');

        // Ficha ya unificada y titulo con la errata del INTT: coincide, la ficha no se reescribe.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONTRUCTORA VIDALSA 27, C.A', $placa));
        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertSame('CONSTRUCTORA VIDALSA 27, C.A', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);

        // Ficha sin propietario (N/A): la tarea lo pone, y en la forma elegida, no con la errata.
        [$otro, $placa2] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'N/A']);
        $this->lectorFalso($this->textoTitulo('CONTRUCTORA VIDALSA 27, C.A', $placa2));
        $this->verificar($otro, VerificacionDocumento::PROPIEDAD);
        $this->assertSame('CONSTRUCTORA VIDALSA 27, C.A', $this->ficha($otro)->NOMBRE_DEL_TITULAR);
    }

    public function test_los_formatos_de_poliza_vistos_el_18_09_se_leen(): void
    {
        // Tres aseguradoras que salian "no se pudo leer" teniendo la vigencia escrita.
        $l = app(\App\Services\LectorDocumentoPdf::class);
        $casos = [
            // Rotulos en una linea, fechas en otra; el vencimiento pegado a "Hasta:"; "Incio" (sic).
            ["Fecha de Emisión: \nCUADRO RECIBO \nDesde : Desde: \n12/05/2025 12/05/2025 \nHasta: 12/05/2026 Hasta: 12/05/2026 \nFecha de Incio Póliza: 12/05/2025 \n",
             '2025-05-12', '2026-05-12'],
            // "Hasta" y su fecha separados por otros rotulos.
            ["Vigencia \n1/12/2025 \nDesde 01/12/2025 Hasta \nFrecuencia de Pago: Sucursal: 1/12/2026 Anual \n", '2025-12-01', '2026-12-01'],
            // Las dos fechas de la vigencia separadas por un guion.
            ["VIGENCIA DEL SEGURO:16/01/2026 - 16/01/2027 \n", '2026-01-16', '2027-01-16'],
        ];
        foreach ($casos as [$texto, $desde, $vence]) {
            $d = $l->extraer(VerificacionDocumento::POLIZA, $texto);
            $this->assertSame($desde, $d['desde'], $texto);
            $this->assertSame($vence, $d['vence'], $texto);
        }
    }

    public function test_los_cuatro_documentos_se_revisan_en_orden(): void
    {
        // La pasada se gasta en el primer documento que tenga cola; cuando ese se acaba, sigue
        // con el siguiente. Nunca vuelve a uno anterior dentro de la misma pasada.
        $this->lectorFalso("INTT \nhoja sin datos que sirvan \n");
        // Sobre UNA ficha propia: la cola de la base de desarrollo tambien trae relecturas de
        // filas viejas (las ilegibles se reintentan), y esas no estrenan ID_REGISTRO.
        [$equipo] = $this->equipoConDocumentos();

        $this->artisan('docs:verificar-documentos', ['--equipo' => $equipo, '--lote' => 6])->assertSuccessful();

        $orden = array_flip(array_keys(VerificacionDocumento::ENLACES));
        $tipos = VerificacionDocumento::where('ID_EQUIPO', $equipo)->orderBy('ID_REGISTRO')->pluck('TIPO')->all();
        $this->assertNotEmpty($tipos, 'La pasada tiene que haber leído algo.');

        $posiciones = array_map(fn ($tipo) => $orden[$tipo], $tipos);
        $ordenadas = $posiciones;
        sort($ordenadas);
        $this->assertSame($ordenadas, $posiciones, 'Los tipos salen en su orden: ' . implode(', ', $tipos));
    }

    public function test_revisado_a_mano_desde_el_visor_deja_la_fila_como_coincide(): void
    {
        // La persona corrige la ficha en el panel del visor y la fila queda revisada por
        // ella: sale de pendientes, sin diferencias, con su nombre en el motivo.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placa));
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);
        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->ESTADO);

        $yo = $this->superAdmin();
        $this->actingAs($yo)->postJson(route('compresion-pdf.documento.revisado', ['id' => $reg->ID_REGISTRO]))
            ->assertOk()->assertJson(['success' => true]);

        $reg->refresh();
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
        $this->assertNull($reg->DIFERENCIAS);
        $this->assertFalse($reg->A_MANO);
        $this->assertSame($yo->getKey(), $reg->APLICADO_POR);
        $this->assertStringContainsString('Revisado a mano por', (string) $reg->MOTIVO);
        $this->assertSame(0, VerificacionDocumento::corregibles()->where('ID_EQUIPO', $equipo)->count(), 'Sale de pendientes.');
        // Marcar NO toca la ficha: eso lo hace el panel del visor con su propia ruta.
        $this->assertSame('TRANSPORTE MILENUIM 0210, CA', $this->ficha($equipo)->NOMBRE_DEL_TITULAR);
    }

    public function test_revisado_a_mano_pone_lo_que_el_panel_del_visor_no_tiene(): void
    {
        // La fecha de emision no tiene campo en el panel del visor: sale aparte, con el valor
        // del documento, y la persona la pone (o no). Sin esto se perdia al dar la fila por buena.
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placa));
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);
        $this->assertArrayHasKey('FECHA_EMISION_PROPIEDAD', $reg->DIFERENCIAS);

        $this->actingAs($this->superAdmin())
            ->postJson(route('compresion-pdf.documento.revisado', ['id' => $reg->ID_REGISTRO]),
                ['campos' => ['FECHA_EMISION_PROPIEDAD' => '2018-10-03']])
            ->assertOk()->assertJson(['success' => true, 'puestos' => ['FECHA_EMISION_PROPIEDAD']]);

        $this->assertSame('2018-10-03', substr((string) $this->ficha($equipo)->FECHA_EMISION_PROPIEDAD, 0, 10));
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->refresh()->ESTADO);
    }

    public function test_revisado_a_mano_tampoco_escribe_la_placa_ni_datos_que_no_estaban(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placa));
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);
        $url = route('compresion-pdf.documento.revisado', ['id' => $reg->ID_REGISTRO]);
        $yo = $this->superAdmin();

        // La placa: nunca, ni a mano por este camino (para eso esta la ficha del equipo).
        $this->actingAs($yo)->postJson($url, ['campos' => ['PLACA' => 'A00XX9X']])->assertStatus(422);
        // Un dato que el verificador no marco como distinto en esta fila.
        $this->actingAs($yo)->postJson($url, ['campos' => ['FECHA_VENC_POLIZA' => '2030-01-01']])->assertStatus(422);
        // Una fecha que no es fecha.
        $this->actingAs($yo)->postJson($url, ['campos' => ['FECHA_EMISION_PROPIEDAD' => '2018-02-31']])->assertStatus(422);

        $this->assertSame($placa, $this->ficha($equipo)->PLACA);
        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_PROPIEDAD);
        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->refresh()->ESTADO, 'Con un error no se da por revisada.');
    }

    public function test_una_tabla_de_flota_mal_escaneada_no_descarta_al_equipo(): void
    {
        // Si la tabla no trae ningun serial de carroceria legible (17 caracteres), no se puede
        // afirmar que el equipo no este amparado: queda para mirarlo, sin acusar al archivo.
        [$equipo] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2027-04-08']);
        $this->lectorFalso("RESPONSABILIDAD CIVIL GENERAL \nPOLIZA Nro.: RCGE-001001-20669 \n"
            . "SE AMAPARA LOS SIGUIENTES VEHÍCULOS Y/O EQUIPOS \n-LOVOL FR220D 2025 N/A FTC0O3RH 448549 EXCAVADORA \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::POLIZA);

        $this->assertFalse($reg->esDeOtroVehiculo(), 'Sin seriales legibles en la tabla no se afirma que no lo ampare.');
        $this->assertStringContainsString('no se pudo confirmar si ampara', (string) $reg->MOTIVO);
    }

    public function test_sin_super_admin_no_se_puede_marcar_revisado(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $placa));
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);

        $sinPermiso = Usuario::all()->first(fn ($u) => ! $u->can('super.admin'));
        $this->actingAs($sinPermiso)->postJson(route('compresion-pdf.documento.revisado', ['id' => $reg->ID_REGISTRO]))
            ->assertForbidden();
        $this->assertSame(VerificacionDocumento::DIFIERE, $reg->refresh()->ESTADO);
    }

    public function test_varias_filas_se_dan_por_revisadas_de_una_vez(): void
    {
        // Filas elegidas en la tabla y el boton "Revisado", sin abrir el visor. Las filas
        // quedan revisadas por la persona; de las fichas solo se llenan las fechas que tienen
        // VACIAS (21-09-2026), el resto queda como estaba.
        [$e1, $p1] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso($this->textoTitulo('TRANSPORTE MILENIUM 0210 C.A', $p1));
        $r1 = $this->verificarSinAplicar($e1, VerificacionDocumento::PROPIEDAD);
        [$e2, $p2] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'GRUPO ROYSO C.A.']);
        $this->lectorFalso($this->textoTitulo('GRUPO ROYSO CORP C.A.', $p2));
        $r2 = $this->verificarSinAplicar($e2, VerificacionDocumento::PROPIEDAD);
        $this->assertSame(VerificacionDocumento::DIFIERE, $r2->ESTADO);
        // En la ficha 2 alguien puso la emision DESPUES de leer el PDF: esa no se pisa.
        DB::table('documentacion')->where('ID_EQUIPO', $e2)->update(['FECHA_EMISION_PROPIEDAD' => '2019-01-01']);

        $url = route('compresion-pdf.documentos.revisados');
        $sinPermiso = Usuario::all()->first(fn ($u) => ! $u->can('super.admin'));
        $this->actingAs($sinPermiso)->postJson($url, ['ids' => [$r1->ID_REGISTRO]])->assertForbidden();
        $this->assertSame(VerificacionDocumento::DIFIERE, $r1->refresh()->ESTADO);

        $yo = $this->superAdmin();
        $this->actingAs($yo)->postJson($url, ['ids' => []])->assertStatus(422);
        $this->actingAs($yo)->postJson($url, ['ids' => ['x']])->assertStatus(422);
        $this->actingAs($yo)->postJson($url, ['ids' => [$r1->ID_REGISTRO, $r2->ID_REGISTRO, $r1->ID_REGISTRO]])
            ->assertOk()->assertJson(['success' => true, 'revisadas' => 2, 'fechas' => 1]);
        $this->assertSame('2018-10-03', substr((string) $this->ficha($e1)->FECHA_EMISION_PROPIEDAD, 0, 10), 'La vacia se llena.');
        $this->assertSame('2019-01-01', substr((string) $this->ficha($e2)->FECHA_EMISION_PROPIEDAD, 0, 10), 'La puesta despues se respeta.');

        foreach ([$r1, $r2] as $reg) {
            $reg->refresh();
            $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
            $this->assertNull($reg->DIFERENCIAS);
            $this->assertSame($yo->getKey(), $reg->APLICADO_POR);
            $this->assertStringContainsString('Revisado a mano por', (string) $reg->MOTIVO);
        }
        $this->assertSame('TRANSPORTE MILENUIM 0210, CA', $this->ficha($e1)->NOMBRE_DEL_TITULAR);
        $this->assertSame('GRUPO ROYSO C.A.', $this->ficha($e2)->NOMBRE_DEL_TITULAR);

        // El PDF de OTRO vehiculo no da ninguna fecha: solo se marca revisada.
        [$e4] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'GRUPO ROYSO C.A.']);
        $this->lectorFalso($this->textoTitulo('GRUPO ROYSO C.A.', 'Z99ZZ9Z'));
        $r4 = $this->verificarSinAplicar($e4, VerificacionDocumento::PROPIEDAD);
        $this->assertTrue($r4->esDeOtroVehiculo());
        $this->actingAs($yo)->postJson($url, ['ids' => [$r4->ID_REGISTRO]])->assertOk()->assertJson(['revisadas' => 1, 'fechas' => 0]);
        $this->assertNull($this->ficha($e4)->FECHA_EMISION_PROPIEDAD);

        // Una que ya coincide no se toca (no se puede elegir): conserva su motivo.
        [$e3, $p3] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'GRUPO ROYSO C.A.', 'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('GRUPO ROYSO C.A.', $p3));
        $r3 = $this->verificarSinAplicar($e3, VerificacionDocumento::PROPIEDAD);
        $this->assertSame(VerificacionDocumento::COINCIDE, $r3->ESTADO);
        $this->actingAs($yo)->postJson($url, ['ids' => [$r3->ID_REGISTRO]])->assertOk()->assertJson(['revisadas' => 0]);
        $this->assertNull($r3->refresh()->APLICADO_POR);
    }

    public function test_una_letra_mal_leida_no_convierte_el_documento_en_otro_vehiculo(): void
    {
        // Visto el 21-09-2026: titulos dados por "de otro vehiculo" porque el escaneo leyo mal
        // una o dos letras. Eso queda SIN CONFIRMAR (se ponen las fechas vacias, el resto lo
        // mira una persona). Pero los HERMANOS de una flota —misma serie, cambia una cifra—
        // siguen siendo otro vehiculo.
        $l = app(LectorDocumentoPdf::class);
        $mal = ['LSFAM11H5PA084848' => 'LSFAM1115PA084848', '8ATS2SSH07X057494' => 'SATS2SSH07X057494',
                'LJ11KFBD9H1801772' => 'ELJ11KFBD9H1801772', 'MP0DX9CD9S2650003' => 'MRODX9CD9S2650003'];
        foreach ($mal as $ficha => $leido) {
            $this->assertSame('no_se_sabe', $l->mismoVehiculo(null, $ficha, ['serial' => $leido]), $leido);
        }
        $this->assertSame('no_se_sabe', $l->mismoVehiculo('A90AR5G', null, ['placa' => 'A90ARSO']));
        $this->assertSame('no_se_sabe', $l->mismoVehiculo('A09CV0M', null, ['placa' => 'A09CVO']));

        $this->assertSame('no', $l->mismoVehiculo('A90BE2R', null, ['placa' => 'A90BE0R']), 'Hermano de flota.');
        $this->assertSame('no', $l->mismoVehiculo(null, 'L1C29HRG0S0000017', ['serial' => 'L1C29HRG5S0000014']), 'Hermano de flota.');
        $this->assertSame('no', $l->mismoVehiculo(null, 'CAT0966HJA6D00842', ['serial' => 'CAT0996HJA6D00842']), 'Cifra por cifra.');
        // Una O (que es un 0) frente a otra cifra sigue siendo cifra por cifra: hermano de flota.
        $this->assertSame('no', $l->mismoVehiculo('A90BE2R', null, ['placa' => 'A90BEOR']), 'O = 0 frente a un 2.');
        $this->assertSame('no', $l->mismoVehiculo('A90BE7R', null, ['placa' => 'A90BEIR']), 'I = 1 frente a un 7.');
        // A un serial no se le admite el FINAL cortado: casaria con dos hermanos.
        $this->assertSame('no', $l->mismoVehiculo(null, 'LJ11KFBD9H3400085', ['serial' => 'LJ11KFBD9H340008']));
    }

    public function test_un_rotc_se_reconoce_aunque_no_se_lea_su_numero(): void
    {
        // Desde el 18-09-2026 la regla buscaba 'ROTC' con dos caracteres invisibles pegados y no
        // reconocia ninguno: todo ROTC sin numero leido salia "no parece un ROTC". Se nombra
        // como "ROTC", "R.O.T.C." o "Registro de Operadoras de Transporte de Carga".
        foreach (['REGISTRO DE OPERADORAS DE TRANSPORTE DE CARGA (ROTC)', 'Registro de Operadoras de Transporte de Carga (R.O.T.C.)'] as $nombre) {
            [$equipo, $placa] = $this->equipoConDocumentos();
            $this->lectorFalso("$nombre \nPlaca: $placa \nFecha de Emisión: 11/02/2026 \nFecha de Vencimiento: 11/02/2027 \n");
            $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::ROTC);
            $this->assertStringNotContainsString('no parece un ROTC', (string) $reg->MOTIVO, $nombre);
        }
        // Un papel que no es un ROTC sigue sin pasar.
        [$equipo, $placa] = $this->equipoConDocumentos();
        $this->lectorFalso("CUADRO Y RECIBO DE PÓLIZAS \nPlaca: $placa \nFecha de Emisión: 11/02/2026 \nFecha de Vencimiento: 11/02/2027 \n");
        $reg = $this->verificarSinAplicar($equipo, VerificacionDocumento::ROTC);
        $this->assertStringContainsString('no parece un ROTC', (string) $reg->MOTIVO);
    }

    public function test_la_tabla_de_flota_del_rotc_da_numero_y_fechas(): void
    {
        // Drive a veces devuelve solo la tabla de la flota, sin el certificado (una imagen): su
        // cabecera trae el numero y la emision con "Fecha y Hora de Emisión".
        $d = app(LectorDocumentoPdf::class)->extraer(LectorDocumentoPdf::ROTC,
            "Fecha y Hora de Emisión: 11/02/2026 06:45:24 PM Pág. 11/60 FLOTA VEHICULAR DE TRANSPORTE DE CARGA \n"
            . "REGISTRO DE OPERADORAS DE TRANSPORTE DE CARGA (ROTC) \n"
            . "Operadora: CONTRUCTORA VIDALSA 27, C.A (J-29387719-9) Número de ROTC: 49199 Fecha de vencimiento: 11/02/2027 \n");
        $this->assertSame('49199', $d['nro']);
        $this->assertSame('2026-02-11', $d['emision']);
        $this->assertSame('2027-02-11', $d['vence']);
    }

    public function test_la_migracion_vuelve_a_poner_en_cola_todos_los_rotc(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['FECHA_ROTC' => '2027-02-11', 'FECHA_EMISION_ROTC' => '2026-02-11']);
        $this->lectorFalso($this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', $placa, 'ABC123', '11/02/2026', '11/02/2027'));
        $this->verificar($equipo, VerificacionDocumento::ROTC);
        $this->assertFalse(VerificacionDocumento::pendientes(VerificacionDocumento::ROTC, 'LINK_ROTC')->where('d.ID_EQUIPO', $equipo)->exists());
        // Uno revisado por una persona y un titulo: esos no se tocan.
        [$revisado, $placa2] = $this->equipoConDocumentos();
        $this->lectorFalso($this->textoRotc('CONSTRUCTORA VIDALSA 27, C.A', $placa2, 'ABC123', '11/02/2026', '11/02/2027'));
        $this->verificarSinAplicar($revisado, VerificacionDocumento::ROTC)->marcarRevisadoPor($this->superAdmin());
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa));
        $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        (require database_path('migrations/2026_09_21_210000_releer_todos_los_rotc.php'))->up();

        $this->assertTrue(VerificacionDocumento::pendientes(VerificacionDocumento::ROTC, 'LINK_ROTC')->where('d.ID_EQUIPO', $equipo)->exists(),
            'Aunque estaba bien, el ROTC vuelve a la cola.');
        $this->assertTrue(VerificacionDocumento::where('ID_EQUIPO', $revisado)->where('TIPO', VerificacionDocumento::ROTC)->whereNotNull('APLICADO_POR')->exists(),
            'Lo revisado por una persona se respeta.');
        $this->assertTrue(VerificacionDocumento::where('ID_EQUIPO', $equipo)->where('TIPO', VerificacionDocumento::PROPIEDAD)->exists(),
            'Los otros documentos no se tocan.');
    }

    public function test_la_migracion_pone_las_fechas_vacias_de_lo_ya_leido(): void
    {
        // Como en el servidor el 21-09-2026: poliza leida ANTES de la regla, sin confirmar el
        // vehiculo, "Fecha de emisión: (vacío) → 2026-08-06" guardada y sin poner.
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $diferencias = [
            'FECHA_EMISION_POLIZA' => ['etiqueta' => 'Fecha de emisión', 'ficha' => null, 'documento' => '2026-08-06'],
            'NOMBRE_DEL_TITULAR'   => ['etiqueta' => 'Propietario', 'ficha' => 'TRANSPORTE MILENUIM 0210, CA', 'documento' => 'OTRO NOMBRE'],
        ];
        $reg = VerificacionDocumento::create([
            'ID_EQUIPO' => $equipo, 'TIPO' => VerificacionDocumento::POLIZA, 'DRIVE_ID' => 'drive-leido',
            'ESTADO' => VerificacionDocumento::DIFIERE, 'A_MANO' => true, 'LEIDO' => ['sin_confirmar' => true],
            'DIFERENCIAS' => $diferencias,
        ]);
        // Otra del mismo estado pero de OTRO vehiculo: no se toca.
        [$ajeno] = $this->equipoConDocumentos();
        VerificacionDocumento::create([
            'ID_EQUIPO' => $ajeno, 'TIPO' => VerificacionDocumento::POLIZA, 'DRIVE_ID' => 'drive-ajeno',
            'ESTADO' => VerificacionDocumento::DIFIERE, 'A_MANO' => true, 'LEIDO' => ['otra_placa' => true],
            'DIFERENCIAS' => ['FECHA_EMISION_POLIZA' => $diferencias['FECHA_EMISION_POLIZA']],
        ]);

        // Un ROTC igual NO: lo relee entero la migracion 210000 (su lectura era la defectuosa).
        [$rotc] = $this->equipoConDocumentos();
        VerificacionDocumento::create([
            'ID_EQUIPO' => $rotc, 'TIPO' => VerificacionDocumento::ROTC, 'DRIVE_ID' => 'drive-rotc',
            'ESTADO' => VerificacionDocumento::DIFIERE, 'A_MANO' => true, 'LEIDO' => ['sin_confirmar' => true],
            'DIFERENCIAS' => ['FECHA_EMISION_ROTC' => ['etiqueta' => 'Emisión del ROTC', 'ficha' => null, 'documento' => '2026-02-11']],
        ]);

        (require database_path('migrations/2026_09_21_200000_poner_fechas_vacias_ya_leidas.php'))->up();

        $this->assertNull($this->ficha($rotc)->FECHA_EMISION_ROTC, 'Los ROTC no se tocan: se releen todos.');

        $this->assertSame('2026-08-06', substr((string) $this->ficha($equipo)->FECHA_EMISION_POLIZA, 0, 10));
        $this->assertSame('TRANSPORTE MILENUIM 0210, CA', $this->ficha($equipo)->NOMBRE_DEL_TITULAR, 'Lo demás no se toca.');
        $reg->refresh();
        $this->assertTrue($reg->A_MANO, 'El titular sigue para revisar.');
        $this->assertArrayNotHasKey('FECHA_EMISION_POLIZA', $reg->DIFERENCIAS);
        $this->assertNull($this->ficha($ajeno)->FECHA_EMISION_POLIZA, 'El PDF de otro vehículo no pone nada.');
    }

    public function test_el_boton_revisado_no_pone_fechas_de_un_pdf_anterior_leido_antes_de_la_regla(): void
    {
        // Lectura guardada ANTES de la regla del PDF anterior: no lleva la marca doc_anterior,
        // pero su vencimiento es 17 meses mas viejo que el de la ficha. Su emision es la del
        // documento viejo: no se pone (lo mismo que hace aplicar()).
        [$equipo] = $this->equipoConDocumentos(['FECHA_VENC_POLIZA' => '2027-06-01']);
        $reg = VerificacionDocumento::create([
            'ID_EQUIPO' => $equipo, 'TIPO' => VerificacionDocumento::POLIZA, 'DRIVE_ID' => 'drive-viejo',
            'ESTADO' => VerificacionDocumento::DIFIERE, 'A_MANO' => false, 'LEIDO' => ['vence' => '2026-01-01'],
            'DIFERENCIAS' => [
                'FECHA_VENC_POLIZA'    => ['etiqueta' => 'Vencimiento', 'ficha' => '2027-06-01', 'documento' => '2026-01-01'],
                'FECHA_EMISION_POLIZA' => ['etiqueta' => 'Fecha de emisión', 'ficha' => null, 'documento' => '2025-01-01'],
            ],
        ]);

        $this->actingAs($this->superAdmin())->postJson(route('compresion-pdf.documentos.revisados'), ['ids' => [$reg->ID_REGISTRO]])
            ->assertOk()->assertJson(['revisadas' => 1, 'fechas' => 0]);
        $this->assertNull($this->ficha($equipo)->FECHA_EMISION_POLIZA);
    }

    public function test_la_pantalla_de_auditoria_tiene_las_tres_pestanas(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'MODAVENCA HOME, C.A.']);
        $this->lectorFalso($this->textoTitulo('JESUS VIDAL SALAZAR ACEVEDO', $placa));
        // Sin aplicar, para que la fila llegue a la pantalla con su botón.
        $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD);

        // La pantalla vive en Control de Auditoría, con sus tres pestañas.
        $this->actingAs($this->superAdmin())->get(route('historial-documentos.index'))
            ->assertOk()->assertSee('Documentos', false)->assertSee('Compresión de PDF', false);

        $this->actingAs($this->superAdmin())->get(route('historial-documentos.index', ['pestana' => 'documentos']))
            ->assertOk()
            ->assertSee('JESUS VIDAL SALAZAR ACEVEDO', false)
            ->assertSee('cpdfRevisar', false)->assertDontSee('Corregir ficha', false);

        // La direccion vieja sigue sirviendo: lleva a la pestaña.
        $this->actingAs($this->superAdmin())->get(route('compresion-pdf.index'))
            ->assertRedirectContains('pestana=compresion');
    }

    // ── Fechas de emision (21-09-2026) ───────────────────────────────────────────────────

    public function test_el_titulo_del_formato_nuevo_del_intt_da_su_fecha_de_emision(): void
    {
        // Sin "Dado a los": la fecha va en la fila de datos, en MAYUSCULAS y sin "de". La de la
        // Gaceta del reverso ("de fecha 29 de agosto de 2018") no es la emision.
        $lector = app(LectorDocumentoPdf::class);
        $texto = "según lo establecido en Gaceta Oficial N° 41.470 de fecha 29 de agosto de 2018. \n"
            . "CAMIONETA PICK-UP D/CABINA CARGA *2026* \n5 2 2105 1050 KGS PRIVADO  12 FEBRERO 2026 \n";
        $this->assertSame('2026-02-12', $lector->extraer(LectorDocumentoPdf::PROPIEDAD, $texto)['emision']);

        // Otra fecha en mayusculas fuera de la fila de datos no es la emision.
        $this->assertNull($lector->extraer(LectorDocumentoPdf::PROPIEDAD, "CARACAS, 29 AGOSTO 2018 \nPlaca: A00AA0A \n")['emision']);

        // Respaldo: la linea de control del pie empieza por la fecha.
        $pie = "20260213/EL/PRS/1/1/260110643476/J6E5C6O/20260213/092614 \n";
        $this->assertSame('2026-02-13', $lector->extraer(LectorDocumentoPdf::PROPIEDAD, $pie)['emision']);
    }

    public function test_la_emision_de_la_poliza_se_lee_aunque_el_rotulo_quede_lejos_de_su_valor(): void
    {
        // El escaneo junta los rotulos en una linea y deja los valores en la de abajo.
        $d = app(LectorDocumentoPdf::class)->extraer(LectorDocumentoPdf::POLIZA,
            "DATOS DE LA PÓLIZA \nFecha Emisión: Hora Emisión: Vigencia \n17/6/2025 \n9:22:16a. m. Desde 17/06/2025 Hasta 17/6/2026 \n");
        $this->assertSame('2025-06-17', $d['emision']);
        $this->assertSame('2026-06-17', $d['vence']);
    }

    public function test_sin_rotulo_de_emision_la_poliza_toma_el_inicio_de_su_vigencia(): void
    {
        $lector = app(LectorDocumentoPdf::class);
        // Piramide: solo "VIGENCIA DEL SEGURO".
        $d = $lector->extraer(LectorDocumentoPdf::POLIZA, "VIGENCIA DEL SEGURO: \n12/08/2026 al 09/06/2027 \nSUCURSAL: CARACAS \n");
        $this->assertSame('2026-08-12', $d['emision']);
        // La del RECIBO es un periodo de pago: no es la fecha de origen de la poliza.
        $d = $lector->extraer(LectorDocumentoPdf::POLIZA, "VIGENCIA DEL RECIBO: 12/08/2026 al 09/12/2026 \n");
        $this->assertNull($d['emision']);
        $d = $lector->extraer(LectorDocumentoPdf::POLIZA, "Vigencia del Recibo: \nDesde 12/08/2026 Hasta 09/12/2026 \n");
        $this->assertNull($d['emision'], 'Tampoco con "Desde ... Hasta" bajo el rótulo del recibo.');
    }

    public function test_sin_confirmar_el_vehiculo_se_ponen_las_fechas_vacias_y_nada_mas(): void
    {
        // No se lee ni placa ni serial: el titular distinto NO se pone (lo decide una persona),
        // pero la fecha de emision que la ficha tiene vacia SI: no pisa nada.
        [$equipo] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'TRANSPORTE MILENUIM 0210, CA']);
        $this->lectorFalso("INTT \nCertificado de Registro de Vehículo a: \nTRANSPORTE MILENIUM 0210 C.A \n"
            . "Dado a los: 3 días del mes de: OCTUBRE de: 2018 \n");

        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $ficha = $this->ficha($equipo);
        $this->assertSame('2018-10-03', substr((string) $ficha->FECHA_EMISION_PROPIEDAD, 0, 10));
        $this->assertSame('TRANSPORTE MILENUIM 0210, CA', $ficha->NOMBRE_DEL_TITULAR, 'Lo demás no se toca.');
        $this->assertTrue($reg->A_MANO, 'El titular sigue para revisar.');
        $this->assertArrayHasKey('NOMBRE_DEL_TITULAR', $reg->DIFERENCIAS);
        $this->assertArrayNotHasKey('FECHA_EMISION_PROPIEDAD', $reg->DIFERENCIAS, 'La fecha ya está puesta.');
    }

    public function test_revisar_ahora_relee_solo_lo_que_tiene_un_problema(): void
    {
        // Solo los "Datos distintos" (y los "No se pudo leer", por la regla de siempre). Lo que
        // coincide se deja tranquilo aunque le falte la fecha (lo pidio el cliente).
        [$sinFecha, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A']);
        [$conFecha, $placa2] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa, 'sin fecha legible'));
        $this->verificar($sinFecha, VerificacionDocumento::PROPIEDAD);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', $placa2));
        $this->verificar($conFecha, VerificacionDocumento::PROPIEDAD);
        [$ajeno] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27, C.A',
            'FECHA_EMISION_PROPIEDAD' => '2018-10-03']);
        $this->lectorFalso($this->textoTitulo('CONSTRUCTORA VIDALSA 27, C.A', 'Z99ZZ9Z'));
        $this->assertSame(VerificacionDocumento::DIFIERE, $this->verificar($ajeno, VerificacionDocumento::PROPIEDAD)->ESTADO);

        // POLIZA que coincide aunque la ficha siga sin su fecha de emision: tampoco se relee.
        [$poliza] = $this->equipoConDocumentos();
        $enlace = DB::table('documentacion')->where('ID_EQUIPO', $poliza)->value('LINK_POLIZA_SEGURO');
        VerificacionDocumento::create([
            'ID_EQUIPO' => $poliza, 'TIPO' => VerificacionDocumento::POLIZA, 'ESTADO' => VerificacionDocumento::COINCIDE,
            'DRIVE_ID' => explode('?', substr($enlace, strlen('/storage/google/')))[0],
        ]);

        $this->assertFalse($this->enLaCola($ajeno, VerificacionDocumento::PROPIEDAD), 'Ya se leyó.');
        // Otra noche SIN pulsar el boton: no se relee nada de esto.
        \Carbon\Carbon::setTestNow(now()->addDay());
        $this->assertFalse($this->enLaCola($ajeno, VerificacionDocumento::PROPIEDAD), 'Sin el botón no se relee.');
        $this->assertFalse($this->enLaCola($poliza, VerificacionDocumento::POLIZA), 'Sin el botón no se relee.');

        // "Revisar ahora".
        \Carbon\Carbon::setTestNow(now()->addMinute());
        \App\Console\Commands\VerificarDocumentos::pedirAhora();
        $this->assertTrue($this->enLaCola($ajeno, VerificacionDocumento::PROPIEDAD), 'El título en "Datos distintos" se relee.');
        $this->assertFalse($this->enLaCola($sinFecha, VerificacionDocumento::PROPIEDAD), 'El título que coincide se deja tranquilo.');
        $this->assertFalse($this->enLaCola($conFecha, VerificacionDocumento::PROPIEDAD), 'Lo que está bien no se relee.');
        $this->assertFalse($this->enLaCola($poliza, VerificacionDocumento::POLIZA), 'La póliza que coincide se deja tranquila.');
        \Carbon\Carbon::setTestNow();
    }

    public function test_lo_revisado_por_una_persona_solo_recibe_las_fechas_vacias(): void
    {
        [$equipo, $placa] = $this->equipoConDocumentos(['NOMBRE_DEL_TITULAR' => 'MODAVENCA HOME, C.A.']);
        // Primera lectura, sin fecha legible; una persona la revisa y deja el titular como está.
        $this->lectorFalso($this->textoTitulo('JESUS VIDAL SALAZAR ACEVEDO', $placa, 'sin fecha legible'));
        $admin = $this->superAdmin();
        $this->verificarSinAplicar($equipo, VerificacionDocumento::PROPIEDAD)->marcarRevisadoPor($admin);

        // Al releerla (le falta la fecha), el lector ya la encuentra.
        $this->lectorFalso($this->textoTitulo('JESUS VIDAL SALAZAR ACEVEDO', $placa));
        $reg = $this->verificar($equipo, VerificacionDocumento::PROPIEDAD);

        $ficha = $this->ficha($equipo);
        $this->assertSame('2018-10-03', substr((string) $ficha->FECHA_EMISION_PROPIEDAD, 0, 10));
        $this->assertSame('MODAVENCA HOME, C.A.', $ficha->NOMBRE_DEL_TITULAR, 'Su decisión se respeta.');
        $this->assertSame($admin->getKey(), (int) $reg->APLICADO_POR, 'Sigue como revisada por ella.');
        $this->assertSame(VerificacionDocumento::COINCIDE, $reg->ESTADO);
    }
}
