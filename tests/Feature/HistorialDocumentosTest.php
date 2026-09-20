<?php

namespace Tests\Feature;

use App\Http\Controllers\HistorialDocumentosController;
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

        $r = $this->actingAs($yo)->get(route('historial-documentos.index', ['search_equipo' => $placa]));
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
        $html = $this->actingAs($yo)->get(route('historial-documentos.index', ['search_equipo' => $placa]))->getContent();
        $this->assertSame(2, substr_count($html, '<tr class="hd-selectable-row'));
        $this->assertStringContainsString('Edición Metadata RACDA', $html);
    }
}
