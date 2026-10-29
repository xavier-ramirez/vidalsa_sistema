<?php

namespace Tests\Feature;

use App\Http\Controllers\HistorialDocumentosController;
use App\Models\CatalogoAuditLog;
use App\Models\EquipoAuditLog;
use App\Models\Usuario;
use App\Support\CacheVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Control de Auditoría → pestaña Historial: que cada cosa salga UNA vez.
 *
 * Al subir un PDF la fecha de vencimiento es obligatoria, asi que la subida escribe dos
 * apuntes (el archivo y sus datos). En la pantalla van en una sola fila; una edicion
 * posterior de esos datos sigue siendo su propia fila.
 */
class HistorialDocumentosTest extends MySqlTestCase
{
    /** Ficha de juguete con el RACDA recien subido por $autor. */
    private function equipoConRacda(Usuario $autor, \Carbon\Carbon $cuando): array
    {
        $letra = fn () => chr(random_int(65, 90));
        $placa = $letra() . random_int(10, 99) . $letra() . $letra() . random_int(0, 9) . $letra();
        $id = DB::table('equipos')->insertGetId([
            'MARCA' => 'MARCAPRUEBA', 'MODELO' => 'MP-' . strtoupper(Str::random(4)),
            'ANIO' => 2020, 'SERIAL_CHASIS' => '8XA' . strtoupper(Str::random(8)) . random_int(100000, 999999),
        ]);
        DB::table('documentacion')->insert([
            'ID_EQUIPO' => $id, 'PLACA' => $placa,
            'LINK_RACDA' => '/storage/google/drive' . strtoupper(Str::random(10)) . '?v=1',
            'RACDA_FECHA_SUBIDA' => $cuando, 'RACDA_SUBIDO_POR' => $autor->getKey(),
            'FECHA_RACDA' => '2027-08-15',
        ]);
        return [$id, $placa];
    }

    /**
     * La burbuja de cambios se entiende: el PDF se dice con palabras (no su enlace de Drive), y
     * quién/cuándo se subió no se repite (ya lo dicen las columnas de la fila). Caso real: la
     * póliza renovada por la carga masiva (20-10-2026).
     */
    public function test_los_cambios_de_un_pdf_se_leen_sin_enlaces_ni_datos_repetidos(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $cuando = now()->subMinutes(5);
        [$equipo, $placa] = $this->equipoConRacda($yo, $cuando->copy()->subDay());
        EquipoAuditLog::create([
            'ID_EQUIPO' => $equipo, 'ID_USUARIO' => $yo->getKey(), 'ACCION' => 'metadata_poliza',
            'CAMBIOS' => [
                'LINK_POLIZA_SEGURO'   => ['antes' => '/storage/google/viejoXYZ?v=1', 'despues' => '/storage/google/nuevoXYZ?v=2'],
                'FECHA_VENC_POLIZA'    => ['antes' => '2026-10-01', 'despues' => '2027-06-09'],
                'POLIZA_SUBIDO_POR'    => ['antes' => null, 'despues' => $yo->getKey()],
                'POLIZA_FECHA_SUBIDA'  => ['antes' => null, 'despues' => '2026-10-20T19:19:34.806235Z'],
            ],
            'created_at' => $cuando,
        ]);

        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'historial', 'search_equipo' => $placa]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('PDF de la póliza', $html);
        $this->assertStringContainsString('Se reemplazó por uno nuevo', $html);
        $this->assertStringNotContainsString('nuevoXYZ', $html, 'Sin el enlace de Drive en la burbuja.');
        $this->assertStringContainsString('Vencimiento de la póliza', $html);
        $this->assertStringContainsString('09/06/2027', $html);
        $this->assertStringNotContainsString('Poliza Subido Por', $html);
        $this->assertStringNotContainsString('19:19:34.806235Z', $html);
    }

    public function test_la_subida_y_los_datos_guardados_con_ella_van_en_una_sola_fila(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($yo, 'Hace falta un usuario super.admin para probar.');
        $cuando = now()->subMinutes(5);
        [$equipo, $placa] = $this->equipoConRacda($yo, $cuando);
        EquipoAuditLog::create([
            'ID_EQUIPO' => $equipo, 'ID_USUARIO' => $yo->getKey(), 'ACCION' => 'metadata_racda',
            'CAMBIOS' => ['FECHA_RACDA' => ['antes' => null, 'despues' => '2027-08-15']],
            'created_at' => $cuando,
        ]);

        $r = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'historial', 'search_equipo' => $placa]));
        $r->assertOk();
        $html = $r->getContent();

        $this->assertStringContainsString($placa, $html);
        $this->assertSame(1, substr_count($html, '<tr class="hd-selectable-row'), 'Una sola fila para la subida.');
        $this->assertStringNotContainsString('Edición Metadata RACDA', $html);
        // Y esa fila lleva la burbuja con lo que se guardó (vacío → 15/08/2027).
        $this->assertStringContainsString('hd-cambios-caja', $html);
        $this->assertStringContainsString('15/08/2027', $html);

        // Una edición POSTERIOR de las fechas sí es una fila aparte.
        EquipoAuditLog::create([
            'ID_EQUIPO' => $equipo, 'ID_USUARIO' => $yo->getKey(), 'ACCION' => 'metadata_racda',
            'CAMBIOS' => ['FECHA_RACDA' => ['antes' => '2027-08-15', 'despues' => '2028-08-15']],
            'created_at' => $cuando->copy()->addMinutes(20),
        ]);
        CacheVersion::olvidarBumpsDelRequest();
        CacheVersion::bump(HistorialDocumentosController::DATA_VER_KEY);
        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'historial', 'search_equipo' => $placa]))->getContent();
        $this->assertSame(2, substr_count($html, '<tr class="hd-selectable-row'));
        $this->assertStringContainsString('Edición Metadata RACDA', $html);
    }

    /** La barra lateral resume la MISMA lista de la tabla y se repinta al filtrar (08-10-2026). */
    public function test_el_resumen_lateral_cuenta_la_lista_de_la_tabla(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        // Hace un rato, pero HOY: pasada la medianoche "hace 5 minutos" era ayer y el "Hoy" daba 0.
        [, $placa] = $this->equipoConRacda($yo, now()->subMinutes(5)->max(now()->startOfDay()));

        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'historial', 'search_equipo' => $placa]))->getContent();
        $this->assertStringContainsString('id="hdResumen"', $html);
        $this->assertMatchesRegularExpression('~<small>Hoy</small>\s*<strong>1</strong>~', $html);
        $this->assertStringNotContainsString('Por tipo de acción', $html, 'Esa caja se quito (21-10-2026).');

        // El filtrado por AJAX trae el resumen repintado; filtrar por la accion sigue igual.
        $json = $this->actingAs($yo)->getJson(route('historial-documentos.index', ['pestana' => 'historial', 'search_equipo' => $placa, 'search_tipo' => 'cat_uploads']))->json();
        $this->assertSame(1, $json['total']);
        $this->assertMatchesRegularExpression('~<small>Hoy</small>\s*<strong>1</strong>~', $json['resumen_html']);
        $json = $this->actingAs($yo)->getJson(route('historial-documentos.index', ['pestana' => 'historial', 'search_equipo' => $placa, 'search_tipo' => 'cat_borrados']))->json();
        $this->assertSame(0, $json['total']);
        // Cambiar de pagina no cambia el resumen: no se vuelve a mandar.
        $json = $this->actingAs($yo)->getJson(route('historial-documentos.index', ['pestana' => 'historial', 'search_equipo' => $placa, 'page' => 2]))->json();
        $this->assertNull($json['resumen_html']);
    }

    /** Una lista nueva para cada caso: ver el comentario de versionUnica(). */
    private function versionUnica(): void
    {
        // En las pruebas la versión vive en la caché de memoria (vuelve a cero en cada corrida) y
        // la lista armada en la de archivo (sobrevive). Con filtros que se repiten entre corridas,
        // la versión repetida leería la lista de otra corrida, sin los apuntes de esta.
        \Illuminate\Support\Facades\Cache::forever(HistorialDocumentosController::DATA_VER_KEY, random_int(1_000_000, 2_000_000_000));
    }

    private function filas(Usuario $yo, array $filtros): array
    {
        return $this->actingAs($yo)->getJson(route('historial-documentos.index', ['pestana' => 'historial'] + $filtros))->json();
    }

    /**
     * Equipos y auxiliares van en las MISMAS acciones (pedido del cliente, 19-10-2026): el alta
     * de un auxiliar es "Registro de Vehículo", su baja "Eliminación de Equipo" y su foto del
     * catálogo "Foto de Modelo". El filtro "Tipo de Acción" ya no ofrece nada "de Auxiliar".
     */
    public function test_los_auxiliares_van_en_las_mismas_acciones_que_los_equipos(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $serial = 'AUXH' . strtoupper(Str::random(8));
        $aux = \App\Models\EquipoAuxiliar::create(['TIPO' => 'MAQUINA_DE_SOLDAR', 'MARCA' => 'PRUEBA', 'MODELO' => 'HISTORIAL',
            'SERIAL' => $serial, 'CREADO_POR' => $yo->ID_USUARIO]);
        $marca = 'AUXPRUEBA' . strtoupper(Str::random(6));
        CatalogoAuditLog::create(['ID_USUARIO' => $yo->ID_USUARIO, 'ACCION' => 'upload_foto_aux', 'MODELO' => $marca,
            'CAMBIOS' => ['tipo' => 'SOLDADORA'], 'created_at' => now()]);
        $this->versionUnica();

        // El alta, desde su ficha (y se encuentra por su serial, como la de un equipo).
        $alta = $this->filas($yo, ['search_tipo' => 'Registro de Vehículo', 'search_equipo' => $serial]);
        $this->assertSame(1, $alta['total'], 'El alta del auxiliar es un "Registro de Vehículo".');
        $this->assertStringContainsString('Serial: ' . $serial, $alta['html']);

        // La baja, con su ruta: queda en la auditoría como la de un equipo.
        $this->actingAs($yo)->deleteJson(route('equipos-auxiliares.destroy', $aux->ID_AUXILIAR))->assertOk();
        $this->versionUnica();
        $baja = $this->filas($yo, ['search_tipo' => 'Eliminación de Equipo', 'search_equipo' => $serial]);
        $this->assertSame(1, $baja['total'], 'La baja del auxiliar es una "Eliminación de Equipo".');

        // La foto del catálogo de un auxiliar, en "Foto de Modelo".
        $hoy = now()->toDateString();
        $foto = $this->filas($yo, ['search_tipo' => 'Foto de Modelo', 'fecha_desde' => $hoy, 'fecha_hasta' => $hoy]);
        $this->assertStringContainsString($marca, $foto['html']);

        $grupos = HistorialDocumentosController::TIPOS_ACCION;
        $this->assertArrayNotHasKey('Registro de Auxiliar', $grupos['Catálogo de modelos']);
        $this->assertArrayNotHasKey('Foto de Auxiliar', $grupos['Catálogo de modelos']);
        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'historial']))->getContent();
        $this->assertStringNotContainsString('de Auxiliar', $html, 'Ni en el desplegable ni en las filas.');
    }

    /** Las correcciones anexas van en "Subida de documento"; ya no hay "Corrección anexa" aparte. */
    public function test_las_correcciones_anexas_van_en_subida_de_documento(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        [$id, $placa] = $this->equipoConRacda($yo, now()->subMinutes(5));
        EquipoAuditLog::registrar($id, 'anexo_racda', []);
        $this->versionUnica();

        $subidas = $this->filas($yo, ['search_tipo' => 'cat_uploads', 'search_equipo' => $placa]);
        $this->assertSame(2, $subidas['total'], 'La subida del RACDA y su corrección anexa.');
        $this->assertArrayNotHasKey('cat_anexos', HistorialDocumentosController::TIPOS_ACCION['Documentos']);
    }

    /** "Cambio de Estado" es UNA opción del filtro y trae los tres; cada fila dice cuál fue. */
    public function test_cambio_de_estado_junta_desincorporacion_y_reincorporacion(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        [$id, $placa] = $this->equipoConRacda($yo, now()->subMinutes(5));
        foreach ([['OPERATIVO', 'INOPERATIVO'], ['INOPERATIVO', 'DESINCORPORADO'], ['DESINCORPORADO', 'OPERATIVO']] as [$antes, $despues]) {
            EquipoAuditLog::registrar($id, 'edit', ['ESTADO_OPERATIVO' => ['antes' => $antes, 'despues' => $despues]]);
        }
        $this->versionUnica();

        $estado = $this->filas($yo, ['search_tipo' => 'cat_estado', 'search_equipo' => $placa]);
        $this->assertSame(3, $estado['total']);
        foreach (['Cambio de Estado', 'Desincorporación', 'Reincorporación'] as $rotulo) {
            $this->assertStringContainsString($rotulo, $estado['html']);
        }
        $this->assertSame(['Registro de Vehículo', 'Edición de Datos', 'Cambio de Estado', 'Detalle Masivo', 'Eliminación de Equipo'],
            array_values(HistorialDocumentosController::TIPOS_ACCION['Sobre el equipo']));
    }

    /**
     * Las tres pestañas abren y cada una trae el botón Acciones UNA vez: vive en la fila de
     * filtros, que el Historial pinta en su vista y las otras dos en compresion_pdf/panel.
     */
    public function test_cada_pestana_trae_un_solo_boton_de_acciones(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $this->assertNotNull($yo, 'Hace falta un usuario super.admin para probar.');

        foreach (['historial', 'documentos', 'compresion'] as $pestana) {
            $html = $this->actingAs($yo)
                ->get(route('historial-documentos.index', ['pestana' => $pestana]))
                ->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'id="hdBtnAcciones"'), 'Pestaña ' . $pestana);
        }
    }

    /** Sin ?pestana= abre la revisión de documentos, que además es la primera pestaña. */
    public function test_abre_en_la_revision_de_documentos_que_es_la_primera(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $html = $this->actingAs($yo)->get(route('historial-documentos.index'))->assertOk()->getContent();

        $this->assertStringContainsString('cpdf-docs', $html, 'Abre la revisión.');
        $this->assertStringNotContainsString('historialDocumentosTable', $html);
        preg_match_all("/window\.hdPestana\('(\w+)'\)/", $html, $m);
        $this->assertSame(['documentos', 'historial', 'compresion'], $m[1]);
        $this->assertStringContainsString("class=\"on\" onclick=\"window.hdPestana('documentos')\"", $html, 'Y sale marcada.');
    }

    /**
     * Revisión automática: tipo, estado y el buscador al final; Compresión también con el
     * documento primero y el buscador después de sus desplegables (29-10-2026) y UN "Certificado" para equipos y auxiliares.
     */
    public function test_orden_de_los_filtros_y_un_solo_certificado(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $pos = fn (string $html, string $id) => strpos($html, 'id="' . $id . '"');

        $docs = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'documentos']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($docs, 'id="cpdfBuscar"'));
        $this->assertTrue($pos($docs, 'cpdfDocTipoSelect') < $pos($docs, 'cpdfDocEstadoSelect')
            && $pos($docs, 'cpdfDocEstadoSelect') < $pos($docs, 'cpdfBuscar')
            && $pos($docs, 'cpdfBuscar') < $pos($docs, 'hdBtnAcciones'), 'Tipo, estado, buscador.');

        $comp = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'compresion']))->assertOk()->getContent();
        $this->assertSame(1, substr_count($comp, 'id="cpdfBuscar"'));
        $this->assertTrue($pos($comp, 'cpdfDocumentoSelect') < $pos($comp, 'cpdfEstadoSelect')
            && $pos($comp, 'cpdfEstadoSelect') < $pos($comp, 'cpdfBuscar')
            && $pos($comp, 'cpdfBuscar') < $pos($comp, 'hdBtnAcciones'), 'Documento, estado, buscador.');
        $this->assertStringNotContainsString('Certificado (auxiliar)', $comp);
        $this->assertSame('Certificado', collect(\App\Support\EnlacesDocumentos::DOCUMENTOS)->firstWhere(1, 'LINK_CERTIFICADO')[3]);
    }

    /**
     * El buscador de Compresión encuentra también por la placa del equipo (la fila no la guarda:
     * en las de documentacion, FILA_ID es su ID_EQUIPO).
     */
    public function test_compresion_busca_por_placa(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        $idEquipo = DB::table('documentacion')->value('ID_EQUIPO');
        $placa = 'PLC' . mt_rand(10000, 99999);
        DB::table('documentacion')->where('ID_EQUIPO', $idEquipo)->update(['PLACA' => $placa]);
        $serial = 'SERPLACA' . mt_rand(10000, 99999);
        \App\Models\CompresionPdf::create([
            'TABLA' => 'documentacion', 'COLUMNA' => 'LINK_ROTC', 'FILA_ID' => $idEquipo, 'DOCUMENTO' => 'ROTC',
            'SERIAL' => $serial, 'DRIVE_ID_VIEJO' => 'x' . $serial, 'ESTADO' => \App\Models\CompresionPdf::COMPRIMIDO,
        ]);

        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'compresion', 'buscar' => $placa]))
            ->assertOk()->getContent();
        $this->assertStringContainsString($serial, $html);
    }

    /**
     * Filtros Avanzados (fecha desde / hasta) en las TRES pestañas; en Revisión filtran por la
     * fecha de cada fila y el botón sale en rojo con una fecha puesta.
     */
    public function test_las_tres_pestanas_tienen_filtros_avanzados_por_fecha(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        foreach (['documentos', 'historial', 'compresion'] as $pestana) {
            $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => $pestana]))->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'id="btnHdAdvancedFilter"'), 'Pestaña ' . $pestana);
            $this->assertSame(1, substr_count($html, 'id="hdFechaDesde"'), 'Pestaña ' . $pestana);
        }

        $marca = 'FECHAS' . mt_rand(10000, 99999);
        foreach (['VIEJA' => now()->subDays(20), 'NUEVA' => now()->subDay()] as $nombre => $cuando) {
            $v = \App\Models\VerificacionDocumento::create([
                'DRIVE_ID' => $marca . $nombre, 'ORIGEN' => \App\Models\VerificacionDocumento::DE_LA_NOCHE, 'TIPO' => 'poliza',
                'ARCHIVO' => $marca . '.pdf', 'ESTADO' => \App\Models\VerificacionDocumento::COINCIDE, 'MOTIVO' => $marca . ' ' . $nombre, 'INTENTOS' => 0,
            ]);
            \Illuminate\Support\Facades\DB::table('verificacion_documento_registro')->where('ID_REGISTRO', $v->ID_REGISTRO)->update(['updated_at' => $cuando]);
        }
        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'documentos', 'buscar' => $marca,
            'fecha_desde' => now()->subDays(3)->toDateString()]))->assertOk()->getContent();
        $this->assertStringContainsString($marca . ' NUEVA', $html);
        $this->assertStringNotContainsString($marca . ' VIEJA', $html);
        $this->assertMatchesRegularExpression('/id="btnHdAdvancedFilter"[^>]*?>/s', $html);
        $this->assertStringContainsString('background: #fee2e2', $html, 'Con una fecha puesta el botón sale en rojo.');
    }

    /**
     * El contador de selección es el MISMO en las tres pestañas, y en Revisión y Compresión
     * "ver solo las elegidas" (?ids=) deja solo esas filas.
     */
    public function test_el_contador_de_seleccion_esta_en_las_tres_y_filtra_las_elegidas(): void
    {
        $yo = Usuario::all()->first(fn ($u) => $u->can('super.admin'));
        foreach (['documentos' => 'cpdf-selection-chip', 'historial' => 'hd-selection-chip', 'compresion' => 'cpdf-selection-chip'] as $pestana => $id) {
            $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => $pestana]))->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'id="' . $id . '"'), 'Pestaña ' . $pestana);
            $this->assertStringContainsString('id="' . $id . '-cuenta"', $html);
        }

        $marca = 'ELEGIDAS' . mt_rand(10000, 99999);
        $ids = [];
        foreach (['UNA', 'OTRA'] as $nombre) {
            $ids[$nombre] = \App\Models\VerificacionDocumento::create([
                'DRIVE_ID' => $marca . $nombre, 'ORIGEN' => \App\Models\VerificacionDocumento::DE_LA_NOCHE, 'TIPO' => 'poliza',
                'ARCHIVO' => $marca . '.pdf', 'ESTADO' => \App\Models\VerificacionDocumento::COINCIDE, 'MOTIVO' => $marca . ' ' . $nombre, 'INTENTOS' => 0,
            ])->ID_REGISTRO;
        }
        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['pestana' => 'documentos', 'ids' => $ids['UNA'] . ',x']))
            ->assertOk()->getContent();
        $this->assertStringContainsString($marca . ' UNA', $html);
        $this->assertStringNotContainsString($marca . ' OTRA', $html);
        $this->assertStringContainsString('data-id="' . $ids['UNA'] . '" onclick="window.cpdfSelFila(event, this)"', $html, 'Toda fila se puede elegir.');
    }
}
