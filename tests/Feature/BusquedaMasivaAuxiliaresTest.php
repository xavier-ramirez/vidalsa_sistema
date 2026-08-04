<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Búsqueda Masiva (/admin/equipos): comprueba POR HTTP —ruta, sesión, permisos y
 * controlador— que un serial de equipo AUXILIAR se encuentra.
 * Corre contra MySQL con los datos reales y se revierte; ver MySqlTestCase.
 */
class BusquedaMasivaAuxiliaresTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::whereNotNull('ID_USUARIO')->get()->first(fn ($x) => $x->can('super.admin'))
            ?? Usuario::first();
        $this->assertNotNull($u, 'No hay usuarios en la base.');
        return $u;
    }

    private function buscar(array $terms): array
    {
        $res = $this->actingAs($this->usuario())
            ->postJson('/admin/equipos/bulk-lookup', ['terms' => $terms]);
        $res->assertOk();
        return collect($res->json('results'))->keyBy('term')->all();
    }

    public function test_encuentra_el_serial_de_un_equipo_auxiliar(): void
    {
        $serial = DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->whereNotNull('SERIAL')->where('SERIAL', '!=', '')->value('SERIAL');
        $this->assertNotNull($serial, 'No hay auxiliares con serial para probar.');

        $r = $this->buscar([$serial])[mb_strtoupper($serial)] ?? null;

        $this->assertNotNull($r, "El término {$serial} no volvió en la respuesta.");
        $this->assertTrue($r['found'], "El serial de auxiliar {$serial} salió como NO encontrado.");
        $this->assertTrue($r['es_auxiliar'], 'Debe venir marcado como auxiliar.');
        $this->assertNull($r['id'], 'Un auxiliar no lleva id de equipo: no se moviliza por esa vía.');
    }

    public function test_encuentra_auxiliares_y_equipos_en_la_misma_busqueda(): void
    {
        $aux = DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->whereNotNull('SERIAL')->where('SERIAL', '!=', '')->value('SERIAL');
        $eq  = DB::table('equipos')->whereNull('deleted_at')
            ->whereNotNull('SERIAL_CHASIS')->where('SERIAL_CHASIS', '!=', '')->value('SERIAL_CHASIS');

        $res = $this->buscar([$aux, $eq, 'NO-EXISTE-XYZ']);

        $this->assertTrue($res[mb_strtoupper($aux)]['es_auxiliar'] ?? false);
        $this->assertTrue($res[mb_strtoupper($eq)]['found']);
        $this->assertEmpty($res[mb_strtoupper($eq)]['es_auxiliar'] ?? null, 'Un equipo no es auxiliar.');
        $this->assertFalse($res['NO-EXISTE-XYZ']['found'], 'Un valor inexistente no debe encontrarse.');
    }

    public function test_tolera_la_confusion_entre_cero_y_letra_o(): void
    {
        $serial = DB::table('equipos_auxiliares')->whereNull('deleted_at')
            ->whereRaw("SERIAL LIKE '%0%'")->value('SERIAL');
        $this->assertNotNull($serial, 'No hay auxiliares con cero en el serial.');

        $conLetraO = str_replace('0', 'O', $serial);   // como si lo transcribieran mal
        $r = $this->buscar([$conLetraO])[mb_strtoupper($conLetraO)] ?? null;

        $this->assertNotNull($r);
        $this->assertTrue($r['found'], "Tecleando {$conLetraO} debería encontrar {$serial}.");
        $this->assertSame($serial, $r['chasis'], 'Debe devolver el registro real, no otro.');
    }
}
