<?php

namespace Tests\Feature;

use App\Models\Almacen;
use App\Models\Traspaso;
use App\Models\TraspasoLinea;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Recepción parcial de una nota de entrega, de punta a punta por HTTP.
 *
 * DatabaseTransactions (NO RefreshDatabase): envuelve cada test en una transacción y la
 * revierte al terminar, así corre contra la base real sin dejar rastro ni borrar datos.
 */
class RecepcionParcialTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Contra MySQL, no contra el sqlite :memory: de phpunit.xml: estos casos verifican el
     * comportamiento con los datos REALES (notas en tránsito, permisos, almacenes). La
     * transacción de DatabaseTransactions se abre sobre esta misma conexión y se revierte.
     */
    protected $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        // phpunit.xml fuerza DB_CONNECTION=sqlite y DB_DATABASE=:memory:. Se revierte a los
        // valores del .env ANTES de arrancar la app, si no la conexión mysql hereda el
        // ":memory:" como nombre de base y falla.
        putenv('DB_CONNECTION=mysql');
        putenv('DB_DATABASE');
        unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);
        $_ENV['DB_CONNECTION'] = $_SERVER['DB_CONNECTION'] = 'mysql';
        parent::setUp();
        config(['database.default' => 'mysql']);
    }

    /** Usuario + nota ENVIADO cuyo almacén destino ese usuario puede operar. */
    private function notaOperable(): array
    {
        $user = Usuario::whereNotNull('ID_USUARIO')->get()
            ->first(fn ($u) => Almacen::visiblesPara($u)->exists() && $u->can('almacen.movimiento'));
        $this->assertNotNull($user, 'No hay usuario con permiso de movimiento y almacenes visibles.');

        $visibles = Almacen::visiblesPara($user)->pluck('ID_ALMACEN');
        $traspaso = Traspaso::where('ESTADO', Traspaso::ESTADO_ENVIADO)
            ->whereIn('ID_ALMACEN_DESTINO', $visibles)
            ->has('lineas', '>=', 3)
            ->with('lineas')->first();
        $this->assertNotNull($traspaso, 'No hay nota ENVIADO con 3+ líneas en un almacén visible.');

        return [$user, $traspaso];
    }

    public function test_confirmar_solo_algunas_lineas_deja_la_nota_parcial_y_en_la_bandeja(): void
    {
        [$user, $traspaso] = $this->notaOperable();
        $ids   = $traspaso->lineas->pluck('ID_LINEA')->values();
        $total = $ids->count();

        $res = $this->actingAs($user)->postJson("/admin/almacen/recepcion/{$traspaso->ID_TRASPASO}/recibir", [
            'lineas' => [
                ['id_linea' => $ids[0], 'cantidad_recibida' => 1],
                ['id_linea' => $ids[1], 'cantidad_recibida' => 2],
            ],
        ]);

        $res->assertOk();
        $fresco = Traspaso::with('lineas')->find($traspaso->ID_TRASPASO);

        $this->assertSame(Traspaso::ESTADO_RECIBIDO_PARCIAL, $fresco->ESTADO);
        $this->assertSame($total - 2, $fresco->lineas->whereNull('CANTIDAD_RECIBIDA')->count(),
            'Las líneas no tildadas deben quedar PENDIENTES, no forzadas a 0.');
        $this->assertTrue($fresco->puedeRecibirse(), 'Una parcial debe poder completarse después.');

        // Sigue en la bandeja por defecto y la cuentan los contadores.
        $this->assertContains($fresco->ESTADO, Traspaso::ESTADOS_BANDEJA_DEFAULT);
        $this->assertContains($fresco->ESTADO, Traspaso::ESTADOS_RECIBIBLES);
    }

    public function test_confirmar_todas_cierra_la_nota_aunque_falten_cantidades(): void
    {
        [$user, $traspaso] = $this->notaOperable();

        // TODAS las líneas tildadas pero con 0 recibido: puros faltantes.
        $lineas = $traspaso->lineas
            ->map(fn ($l) => ['id_linea' => $l->ID_LINEA, 'cantidad_recibida' => 0])->all();

        $this->actingAs($user)
            ->postJson("/admin/almacen/recepcion/{$traspaso->ID_TRASPASO}/recibir", ['lineas' => $lineas])
            ->assertOk();

        $fresco = Traspaso::with('lineas')->find($traspaso->ID_TRASPASO);

        $this->assertSame(Traspaso::ESTADO_RECIBIDO, $fresco->ESTADO,
            'Revisada entera = cerrada, aunque las cantidades no cuadren.');
        $this->assertNotContains($fresco->ESTADO, Traspaso::ESTADOS_BANDEJA_DEFAULT,
            'Ya confirmada: NO debe seguir en la bandeja.');
        $this->assertGreaterThan(0, $fresco->lineas->where('ESTADO_LINEA', TraspasoLinea::ESTADO_FALTANTE)->count(),
            'Los faltantes se registran en la línea; es lo que alimenta "Con discrepancias".');
    }

    public function test_una_parcial_se_reabre_y_se_completa_sin_duplicar_stock(): void
    {
        [$user, $traspaso] = $this->notaOperable();
        $ids = $traspaso->lineas->pluck('ID_LINEA')->values();
        $url = "/admin/almacen/recepcion/{$traspaso->ID_TRASPASO}/recibir";

        $this->actingAs($user)->postJson($url, [
            'lineas' => [['id_linea' => $ids[0], 'cantidad_recibida' => 5]],
        ])->assertOk();

        $movsTrasPrimera = \DB::table('movimientos_inventario')
            ->where('ID_TRASPASO', $traspaso->ID_TRASPASO)->count();

        // Segunda tanda: el resto + REENVÍO de la ya confirmada (el servidor debe ignorarla).
        $pendientes = Traspaso::with('lineas')->find($traspaso->ID_TRASPASO)
            ->lineas->whereNull('CANTIDAD_RECIBIDA')
            ->map(fn ($l) => ['id_linea' => $l->ID_LINEA, 'cantidad_recibida' => (float) $l->CANTIDAD_ENVIADA])
            ->values()->all();
        $payload = array_merge([['id_linea' => $ids[0], 'cantidad_recibida' => 999]], $pendientes);

        $this->actingAs($user)->postJson($url, ['lineas' => $payload])->assertOk();

        $fresco = Traspaso::with('lineas')->find($traspaso->ID_TRASPASO);
        $movsFinal = \DB::table('movimientos_inventario')
            ->where('ID_TRASPASO', $traspaso->ID_TRASPASO)->count();

        $this->assertSame(Traspaso::ESTADO_RECIBIDO, $fresco->ESTADO);
        $this->assertSame(0, $fresco->lineas->whereNull('CANTIDAD_RECIBIDA')->count());
        $this->assertSame(5.0, (float) $fresco->lineas->firstWhere('ID_LINEA', $ids[0])->CANTIDAD_RECIBIDA,
            'Reenviar una línea ya confirmada NO debe pisar su cantidad.');
        $this->assertSame($movsTrasPrimera + count($pendientes), $movsFinal,
            'Solo las pendientes generan movimiento; la reenviada no duplica stock.');
    }

    public function test_una_nota_ya_cerrada_rechaza_otra_recepcion(): void
    {
        [$user, $traspaso] = $this->notaOperable();
        $url = "/admin/almacen/recepcion/{$traspaso->ID_TRASPASO}/recibir";

        $todas = $traspaso->lineas
            ->map(fn ($l) => ['id_linea' => $l->ID_LINEA, 'cantidad_recibida' => (float) $l->CANTIDAD_ENVIADA])->all();
        $this->actingAs($user)->postJson($url, ['lineas' => $todas])->assertOk();

        $this->actingAs($user)->postJson($url, ['lineas' => $todas])->assertStatus(422);
    }

    public function test_el_payload_vacio_lo_rechaza_la_validacion(): void
    {
        [$user, $traspaso] = $this->notaOperable();

        $this->actingAs($user)
            ->postJson("/admin/almacen/recepcion/{$traspaso->ID_TRASPASO}/recibir", ['lineas' => []])
            ->assertStatus(422);
    }
}
