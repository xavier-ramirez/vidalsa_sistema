<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Usuario;
use App\Services\Gps51Service;
use Illuminate\Support\Facades\Http;
use Tests\MySqlTestCase;

/**
 * Capa "Equipos" del mapa (MapaController): equipos con enlace compartido de GPS51, sus
 * posiciones por tandas y la dirección. GPS51 se simula con Http::fake — la prueba no sale a
 * internet — y la caché de las pruebas es la de memoria (phpunit.xml).
 */
class MapaEquiposGpsTest extends MySqlTestCase
{
    /** Respuesta real de sharetracklastposition (recortada), la que da un chuto en marcha. */
    private function respuestaGps51(float $lat = 8.68235, float $lng = -64.861661): array
    {
        return [
            'status' => 0, 'cause' => 'OK', 'isvalid' => 1, 'devicename' => 'GPS-CHUTO SJ389420', 'expire' => 1789827460851,
            'records' => [[
                'callat' => $lat, 'callon' => $lng, 'speed' => 74500.0, 'course' => 233,
                'totaldistance' => 5.5302799E7, 'masteroil' => 56188, 'auxoil' => 19591,
                'updatetime' => (int) round(microtime(true) * 1000) - 60000,
                'strstatusen' => 'ACC On 2H54M/Voltage 27.9V',
            ]],
        ];
    }

    /** Usuario activo que ve TODOS los frentes de equipos (así ve los equipos con GPS). */
    private function usuarioGlobal(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->where('ESTATUS', 'ACTIVO')->get()
            ->first(fn ($u) => $u->frentesVisiblesEquiposIds() === null && !$u->getFrentesBloqueadosIds());
        $this->assertNotNull($u, 'Hace falta un usuario activo que vea todos los frentes.');
        return $u;
    }

    public function test_la_lista_responde_sin_consultar_gps51_y_las_posiciones_llegan_por_tandas(): void
    {
        $this->assertTrue(Equipo::where('LINK_GPS', 'like', '%gps51%')->exists(), 'Hace falta algún equipo con enlace de GPS51.');
        Http::fake(['gps51.com/*' => Http::response($this->respuestaGps51())]);
        $u = $this->usuarioGlobal();

        // Paso 1: la lista sale de la base, sin tocar GPS51; lo que falta va en `pendientes`.
        $lista = $this->actingAs($u)->getJson(route('mapa.equiposGps'))->assertOk();
        Http::assertNothingSent();
        $pendientes = $lista->json('pendientes');
        $this->assertNotEmpty($pendientes);
        $this->assertSame(Gps51Service::LOTE, $lista->json('lote'));

        // Paso 2: una tanda de posiciones.
        $tanda = array_slice($pendientes, 0, Gps51Service::LOTE);
        $r = $this->actingAs($u)->getJson(route('mapa.equiposGps.posiciones', ['ids' => $tanda]))->assertOk();
        $gps = $r->json('posiciones.' . $tanda[0]);
        $this->assertTrue($gps['ok']);
        $this->assertFalse($gps['fuera_de_venezuela']);
        $this->assertSame(8.68235, $gps['lat']);
        $this->assertSame(74.5, $gps['velocidad']);
        $this->assertSame(55302.8, $gps['km_total']);
        $this->assertSame(['principal' => 561.9, 'auxiliar' => 195.9, 'total' => 757.8], $gps['combustible']);
        $this->assertTrue($gps['acc']);
        $this->assertSame('2h54m', $gps['acc_tiempo']);
        $this->assertSame(27.9, $gps['voltaje']);
        $this->assertTrue($gps['en_linea']);

        // Ya en caché: la lista la trae directo y ese equipo deja de estar pendiente.
        $otra = $this->actingAs($u)->getJson(route('mapa.equiposGps'))->assertOk();
        $this->assertNotContains($tanda[0], $otra->json('pendientes'));

        // Ni el código del enlace ni el enlace mismo viajan al navegador.
        foreach ([$lista, $r, $otra] as $resp) {
            $this->assertStringNotContainsString('authcode', $resp->getContent());
            $this->assertStringNotContainsString('gps51.com', $resp->getContent());
        }

        // Más ids que una tanda: se rechaza (GPS51 no aguanta ráfagas grandes).
        $this->actingAs($u)->getJson(route('mapa.equiposGps.posiciones', ['ids' => range(1, Gps51Service::LOTE + 1)]))->assertStatus(422);
    }

    public function test_los_casos_sin_posicion_y_el_authcode(): void
    {
        $this->assertSame(['ok' => false, 'motivo' => 'enlace_invalido'], Gps51Service::normalizar(['status' => 9906, 'cause' => 'global_error_not_find_token']));
        $this->assertSame(['ok' => false, 'motivo' => 'enlace_vencido'], Gps51Service::normalizar(['status' => 0, 'isvalid' => 0, 'records' => []]));
        $this->assertSame(['ok' => false, 'motivo' => 'sin_posicion'], Gps51Service::normalizar(['status' => 0, 'isvalid' => 1, 'records' => []]));
        // Posición de fábrica en China: se marca para no pintarla.
        $this->assertTrue(Gps51Service::normalizar($this->respuestaGps51(35.612066, 119.799127))['fuera_de_venezuela']);
        // GPS51 frenando una ráfaga (status de error, o un cuerpo que no es JSON): "sin respuesta",
        // sin guardarlo — la siguiente consulta vuelve a preguntar y ya trae la posición.
        $ac = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        Http::fakeSequence('gps51.com/*')
            ->push(['status' => 9903, 'cause' => 'too many requests'])
            ->push('<html>ocupado</html>')
            ->push($this->respuestaGps51());
        $this->assertSame([$ac => null], Gps51Service::posiciones([$ac]));
        $this->assertSame([$ac => null], Gps51Service::posiciones([$ac]));
        $this->assertTrue(Gps51Service::posiciones([$ac])[$ac]['ok']);

        $this->assertNull(Gps51Service::authcode('https://otra-plataforma.com/#/x?authcode=abcdef0123456789'));
        $this->assertSame('0a1b2c3d4e5f60718293a4b5c6d7e8f9', Gps51Service::authcode('https://gps51.com:443//#/tracking?isshare=1&deviceid=1&authcode=0a1b2c3d4e5f60718293a4b5c6d7e8f9&language=es'));
    }

    public function test_la_direccion_de_un_equipo_sale_de_gps51(): void
    {
        $equipo = Equipo::where('LINK_GPS', 'like', '%gps51%')->get(['ID_EQUIPO', 'LINK_GPS'])
            ->first(fn ($e) => Gps51Service::authcode($e->LINK_GPS) !== null);
        $this->assertNotNull($equipo, 'Hace falta un equipo con enlace de GPS51 válido.');
        Http::fake([
            'gps51.com/webapi?action=sharetracklastposition*' => Http::response($this->respuestaGps51()),
            'gps51.com/webapi?action=poibatchwithauthcode*'   => Http::response(['status' => 0, 'points' => [['address' => 'El Manguito, Anzoátegui, Venezuela']]]),
        ]);

        $this->actingAs($this->usuarioGlobal())->getJson(route('mapa.equiposGps.direccion', ['id' => $equipo->ID_EQUIPO]))
            ->assertOk()
            ->assertJson(['direccion' => 'El Manguito, Anzoátegui, Venezuela']);
    }

    public function test_las_direcciones_van_en_lote_y_se_emparejan_por_coordenada(): void
    {
        // GPS51 contesta en OTRO orden y se salta un punto; el que falta se pide una vez más.
        Http::fakeSequence('gps51.com/webapi?action=poibatchwithauthcode*')
            ->push(['status' => 0, 'points' => [
                ['lat' => 9.7, 'lon' => -63.2, 'address' => 'Maturín'],
                ['lat' => 8.9, 'lon' => -64.2, 'address' => 'El Tigre'],
            ]])
            ->push(['status' => 0, 'points' => [['lat' => 10.5, 'lon' => -66.9, 'address' => 'Caracas']]]);
        $puntos = [[8.9, -64.2], [10.5, -66.9], [9.7, -63.2]];

        $dirs = Gps51Service::direcciones('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $puntos);
        $this->assertSame('El Tigre', $dirs[Gps51Service::claveDireccion(8.9, -64.2)]);
        $this->assertSame('Caracas', $dirs[Gps51Service::claveDireccion(10.5, -66.9)]);
        $this->assertSame('Maturín', $dirs[Gps51Service::claveDireccion(9.7, -63.2)]);
        Http::assertSentCount(2);   // uno para todos + uno para el que faltó

        // Ya en caché: no se vuelve a preguntar.
        $this->assertCount(3, Gps51Service::direcciones('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $puntos));
        Http::assertSentCount(2);
    }

    public function test_el_excel_del_mapa_trae_frente_direccion_e_identificacion(): void
    {
        $u = $this->usuarioGlobal();
        $conGps = Equipo::where('LINK_GPS', 'like', '%gps51%')->with('documentacion:ID_EQUIPO,PLACA', 'frenteActual:ID_FRENTE,NOMBRE_FRENTE')
            ->get(['ID_EQUIPO', 'LINK_GPS', 'SERIAL_CHASIS', 'SERIAL_DE_MOTOR', 'ID_FRENTE_ACTUAL'])
            ->filter(fn ($e) => Gps51Service::authcode($e->LINK_GPS) !== null)->values();
        $this->assertGreaterThanOrEqual(2, $conGps->count(), 'Hacen falta dos equipos con enlace de GPS51.');
        $elegidos = $conGps->take(2);
        // Uno sin placa: debe salir identificado por su serial de chasis.
        optional($elegidos[1]->documentacion)->update(['PLACA' => null]);
        Equipo::whereKey($elegidos[1]->ID_EQUIPO)->update(['SERIAL_CHASIS' => 'CHASISPRUEBA001']);

        // GPS51 simulado: la posición y una dirección que repite la coordenada que le preguntan.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'poibatchwithauthcode')) {
                return Http::response(['status' => 0, 'points' => array_map(
                    fn ($p) => $p + ['address' => 'Calle de prueba ' . $p['lat']], $request['points']
                )]);
            }
            return Http::response($this->respuestaGps51(9.7, -63.2));
        });

        // El export vacía los buffers de salida antes de mandar el archivo (igual que el de
        // Equipos, por php-fpm): se reponen para que PHPUnit no lo marque como prueba riesgosa.
        $nivel = ob_get_level();
        $exportar = function (string $ids) use ($u, $nivel) {
            $r = $this->actingAs($u)->get(route('mapa.equiposGps.exportar', ['ids' => $ids]));
            while (ob_get_level() < $nivel) ob_start();
            return $r;
        };
        $r = $exportar($elegidos->pluck('ID_EQUIPO')->implode(','));
        $r->assertOk();
        $hoja = \PhpOffice\PhpSpreadsheet\IOFactory::load($r->baseResponse->getFile()->getPathname())->getActiveSheet();

        $this->assertSame(['N°', 'FRENTE', 'TIPO', 'MARCA', 'MODELO', 'PLACA / SERIAL', "IDENTIFICADO\nPOR", 'ESTADO DEL GPS', "ÚLTIMA\nSEÑAL", 'DIRECCIÓN (UBICACIÓN REAL)', 'COORDENADAS'],
            $hoja->rangeToArray('A5:K5')[0]);
        $this->assertStringStartsWith("EQUIPOS CON GPS\n", $hoja->getCell('C1')->getValue());
        $filas = collect($hoja->rangeToArray('A6:K7'))->keyBy(5);
        $sinPlaca = $filas['CHASISPRUEBA001'];
        $this->assertSame('SERIAL DE CHASIS', $sinPlaca[6]);
        $this->assertSame('EN LÍNEA', $sinPlaca[7]);
        $this->assertSame('Calle de prueba 9.7', $sinPlaca[9]);
        $this->assertSame(mb_strtoupper(trim($elegidos[1]->frenteActual->NOMBRE_FRENTE ?? 'SIN FRENTE')), $sinPlaca[1]);
        $this->assertStringContainsString('2 EQUIPOS LISTADOS', $hoja->getCell('B8')->getValue());

        // Pasados los 2 min de la posición fresca, el Excel usa la última conocida: no vuelve a GPS51.
        foreach ($elegidos as $e) {
            \Illuminate\Support\Facades\Cache::forget('gps51_pos_' . Gps51Service::authcode($e->LINK_GPS));
        }
        // (Http::fake SUMA respuestas a las anteriores: hay que empezar con un cliente limpio para
        // que lo que se registre sea solo lo de esta exportación.)
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake();
        $exportar($elegidos->pluck('ID_EQUIPO')->implode(','))->assertOk();
        Http::assertNothingSent();

        // Un id que el usuario no ve (o que no existe) no exporta nada; ids sin ningún número
        // válido tampoco (vacío no es "todo").
        $exportar('999999999')->assertNotFound();
        $exportar('abc')->assertNotFound();
    }
}
