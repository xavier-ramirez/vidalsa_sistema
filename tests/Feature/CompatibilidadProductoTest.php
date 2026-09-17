<?php

namespace Tests\Feature;

use App\Models\AuxiliarFiltro;
use App\Models\ModeloFiltro;
use App\Models\ProductoEquivalencia;
use App\Models\ProductoInventario;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Compatibilidad de un producto desde "Detalles del producto" (CompatibilidadProductoService):
 * los + / × de números de parte y de equipos que lo usan. Todo corre en la transacción de la
 * prueba y se revierte al terminar.
 */
class CompatibilidadProductoTest extends MySqlTestCase
{
    private function editor(): Usuario
    {
        $u = Usuario::all()->first(fn ($u) => $u->can('almacen.productos'));
        $this->assertNotNull($u, 'No hay un usuario con almacen.productos para probar.');
        return $u;
    }

    private function producto(?string $categoria = 'REPUESTOS'): ProductoInventario
    {
        return ProductoInventario::create([
            'CODIGO'    => 'C' . strtoupper(Str::random(7)),
            'NOMBRE'    => 'PRODUCTO COMPAT ' . strtoupper(Str::random(6)),
            'UM'        => 'UND',
            'CATEGORIA' => $categoria,
        ]);
    }

    private function pedir(string $metodo, string $ruta, ProductoInventario $p, array $datos = [])
    {
        return $this->actingAs($this->editor())->json($metodo, route($ruta, ['id' => $p->ID_PRODUCTO]), $datos);
    }

    public function test_agregar_y_quitar_numeros_de_parte(): void
    {
        $p = $this->producto();

        $this->pedir('POST', 'almacen.productos.equivalencias.store', $p, ['numero_parte' => ' B00714 '])
            ->assertOk()->assertJsonPath('equivalencias', ['B00714']);
        $this->pedir('POST', 'almacen.productos.equivalencias.store', $p, ['numero_parte' => '9F569-31A'])
            ->assertOk()->assertJsonPath('equivalencias', ['B00714', '9F569-31A']);
        $this->assertTrue((bool) ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('NUMERO_PARTE', 'B00714')->value('ES_PRINCIPAL'),
            'El primero que se agrega queda como principal.');

        // Repetido (también en minúsculas o con espacios, como en "Editar producto"): se dice, sin error 500.
        foreach (['B00714', 'b00714', 'B 00714'] as $repetido) {
            $this->pedir('POST', 'almacen.productos.equivalencias.store', $p, ['numero_parte' => $repetido])
                ->assertStatus(422)->assertJsonPath('message', 'El número de parte B00714 ya está en este producto.');
        }

        // "Editar producto": el mismo número dos veces (otra mayúscula) queda en uno, y si se
        // manda escrito distinto se corrige la escritura conservando el principal.
        $editar = fn (array $lista) => $this->actingAs($this->editor())->patchJson(route('almacen.productos.update', ['id' => $p->ID_PRODUCTO]), [
            'NOMBRE' => $p->NOMBRE, 'UM' => $p->UM, 'CATEGORIA' => $p->CATEGORIA, 'equivalencias' => $lista,
        ])->assertOk();
        $lista = fn () => ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->orderBy('ID_EQUIVALENCIA')->pluck('NUMERO_PARTE')->all();
        $editar(['B00714', 'b00714', '9F569-31A']);
        $this->assertSame(['B00714', '9F569-31A'], $lista());
        $editar(['b 00714', '9F569-31A']);
        $this->assertSame(['b 00714', '9F569-31A'], $lista());
        $this->assertTrue((bool) ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('NUMERO_PARTE', 'b 00714')->value('ES_PRINCIPAL'));
        $editar(['B00714', '9F569-31A']);

        // Quitar el principal asciende al que queda.
        $this->pedir('DELETE', 'almacen.productos.equivalencias.destroy', $p, ['numero_parte' => 'B00714'])
            ->assertOk()->assertJsonPath('equivalencias', ['9F569-31A']);
        $this->assertTrue((bool) ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->where('NUMERO_PARTE', '9F569-31A')->value('ES_PRINCIPAL'));
    }

    public function test_guardar_la_ubicacion_no_borra_las_equivalencias(): void
    {
        // Lo que manda "Detalles del producto" al guardar la ubicación: sin lista de
        // equivalencias. Antes, en un producto que no es de categoría FILTRO, las borraba.
        $p = $this->producto('LUBRICANTES');
        ProductoEquivalencia::create(['ID_PRODUCTO' => $p->ID_PRODUCTO, 'NUMERO_PARTE' => '1447', 'ES_PRINCIPAL' => true]);

        $this->actingAs($this->editor())->patchJson(route('almacen.productos.update', ['id' => $p->ID_PRODUCTO]), [
            'NOMBRE' => $p->NOMBRE, 'UM' => $p->UM, 'CATEGORIA' => $p->CATEGORIA, 'UBICACION' => 'ESTANTE 3',
        ])->assertOk();

        $this->assertSame(['1447'], ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->pluck('NUMERO_PARTE')->all());
    }

    public function test_vincular_y_desvincular_equipos_del_catalogo_y_auxiliares(): void
    {
        $p = $this->producto();
        $espec = (int) DB::table('caracteristicas_modelo')->value('ID_ESPEC');
        $aux = DB::table('equipos_auxiliares')->whereNull('deleted_at')->select('TIPO', 'MARCA', 'MODELO')->first();
        $this->assertNotEmpty($espec, 'Hace falta un modelo en el catálogo.');
        $this->assertNotNull($aux, 'Hace falta un equipo auxiliar.');
        $refAux = $aux->TIPO . '|' . $aux->MARCA . '|' . $aux->MODELO;

        // Las opciones traen el modelo y el auxiliar mientras no estén vinculados.
        $refs = fn () => collect($this->pedir('GET', 'almacen.productos.equipos.opciones', $p)->assertOk()->json('opciones'))->pluck('refs')->flatten()->all();
        $this->assertContains((string) $espec, $refs());

        $this->pedir('POST', 'almacen.productos.equipos.store', $p, ['origen' => 'modelo', 'refs' => [(string) $espec]])->assertOk();
        $r = $this->pedir('POST', 'almacen.productos.equipos.store', $p, ['origen' => 'aux', 'refs' => [$refAux]])->assertOk();
        $this->assertSame(['modelo', 'aux'], collect($r->json('equipos'))->pluck('origen')->all());
        $this->assertNotContains((string) $espec, $refs(), 'Lo ya vinculado no se vuelve a ofrecer.');
        $this->assertNotContains($refAux, $refs());

        // Vincular dos veces no duplica.
        $this->pedir('POST', 'almacen.productos.equipos.store', $p, ['origen' => 'modelo', 'refs' => [(string) $espec]])->assertOk();
        $this->assertSame(1, ModeloFiltro::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count());

        // Desvincular quita solo ese vínculo, y solo de este producto.
        $vinculo = ModeloFiltro::where('ID_PRODUCTO', $p->ID_PRODUCTO)->value('ID_MODELO_FILTRO');
        $otro = $this->producto();
        $this->pedir('DELETE', 'almacen.productos.equipos.destroy', $otro, ['origen' => 'modelo', 'ids' => [$vinculo]])->assertOk();
        $this->assertSame(1, ModeloFiltro::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count(), 'Con el id de otro producto no se toca nada.');
        $r = $this->pedir('DELETE', 'almacen.productos.equipos.destroy', $p, ['origen' => 'modelo', 'ids' => [$vinculo]])->assertOk();
        $this->assertSame(['aux'], collect($r->json('equipos'))->pluck('origen')->all());
        $this->assertSame(1, AuxiliarFiltro::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count());

        // Un equipo inexistente se dice.
        $this->pedir('POST', 'almacen.productos.equipos.store', $p, ['origen' => 'modelo', 'refs' => ['999999999']])
            ->assertStatus(422)->assertJsonPath('message', 'Ese modelo de equipo ya no existe.');
    }

    public function test_un_modelo_con_varias_fichas_es_una_sola_fila(): void
    {
        // Mismo tipo y modelo en dos fichas del catálogo (una por año): una opción, una fila,
        // y la × las quita las dos (antes quedaba la otra y parecía que no había quitado nada).
        $p = $this->producto();
        $tipo = 'PRUEBA TIPO ' . strtoupper(Str::random(5));
        $a = DB::table('caracteristicas_modelo')->insertGetId(['TIPO' => $tipo, 'MODELO' => 'MX-1', 'ANIO_ESPEC' => 2017]);
        $b = DB::table('caracteristicas_modelo')->insertGetId(['TIPO' => $tipo, 'MODELO' => 'MX-1', 'ANIO_ESPEC' => 2019]);

        $op = collect($this->pedir('GET', 'almacen.productos.equipos.opciones', $p, [])->json('opciones'))->where('tipo', $tipo)->values();
        $this->assertCount(1, $op);
        $this->assertEqualsCanonicalizing([(string) $a, (string) $b], $op[0]['refs']);

        $r = $this->pedir('POST', 'almacen.productos.equipos.store', $p, ['origen' => 'modelo', 'refs' => $op[0]['refs']])->assertOk();
        $this->assertSame(2, ModeloFiltro::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count());
        $fila = collect($r->json('equipos'))->where('tipo', $tipo)->values();
        $this->assertCount(1, $fila);
        $this->assertCount(2, $fila[0]['ids']);

        $r = $this->pedir('DELETE', 'almacen.productos.equipos.destroy', $p, ['origen' => 'modelo', 'ids' => $fila[0]['ids']])->assertOk();
        $this->assertSame([], $r->json('equipos'));
        $this->assertSame(0, ModeloFiltro::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count());
    }

    public function test_escribir_la_placa_sugiere_el_modelo_del_equipo(): void
    {
        // Un equipo con ficha del catálogo y otro sin ficha (se reconoce por tipo y modelo):
        // la placa, con o sin guion, trae el modelo de cada uno, marcado con su placa.
        $p = $this->producto();
        $tipo = 'PRUEBA TIPO ' . strtoupper(Str::random(5));
        $otroTipo = $tipo . ' B';
        $modelo = 'MP-' . strtoupper(Str::random(4));
        $idTipo = DB::table('tipo_equipos')->insertGetId(['nombre' => $tipo]);
        $espec = DB::table('caracteristicas_modelo')->insertGetId(['TIPO' => $tipo, 'MODELO' => $modelo, 'ANIO_ESPEC' => 2020]);
        // Mismo modelo en OTRO tipo: la placa del equipo sin ficha no debe marcarlo.
        DB::table('caracteristicas_modelo')->insert(['TIPO' => $otroTipo, 'MODELO' => $modelo, 'ANIO_ESPEC' => 2020]);
        $equipo = fn (array $extra) => DB::table('equipos')->insertGetId($extra + [
            'MARCA' => 'MARCAPRUEBA', 'MODELO' => $modelo, 'ANIO' => 2020, 'SERIAL_CHASIS' => 'SC' . strtoupper(Str::random(10)),
        ]);
        $conFicha = $equipo(['ID_ESPEC' => $espec]);
        $sinFicha = $equipo(['id_tipo_equipo' => $idTipo, 'MARCA' => 'OTRAMARCA']);
        DB::table('documentacion')->insert([
            ['ID_EQUIPO' => $conFicha, 'PLACA' => 'ZQ9-X1K'],
            ['ID_EQUIPO' => $sinFicha, 'PLACA' => 'ZQ9X2K'],
        ]);

        $opciones = fn (string $q) => collect($this->actingAs($this->editor())
            ->getJson(route('almacen.productos.equipos.opciones', ['id' => $p->ID_PRODUCTO, 'q' => $q]))->assertOk()->json('opciones'));

        $op = $opciones('ZQ9X1K')->where('tipo', $tipo)->values();
        $this->assertCount(1, $op);
        $this->assertSame(['ZQ9-X1K'], $op[0]['placas']);

        $op = $opciones('zq9-x2k');
        $this->assertSame(['ZQ9X2K'], $op->where('tipo', $tipo)->values()[0]['placas'] ?? null,
            'Sin ficha, el equipo se reconoce por tipo y modelo (aunque su marca sea otra).');
        $this->assertCount(0, $op->where('tipo', $otroTipo), 'El mismo modelo en otro tipo no se marca con esa placa.');

        $this->assertSame([], $opciones('ZQ9X')->where('tipo', $tipo)->values()->all(), 'Menos de 5 caracteres no busca por placa.');
    }

    public function test_sin_permiso_de_productos_no_se_cambia(): void
    {
        $p = $this->producto();
        $sinPermiso = Usuario::all()->first(fn ($u) => ! $u->can('almacen.productos'));
        $this->assertNotNull($sinPermiso, 'Hace falta un usuario sin almacen.productos.');

        $this->actingAs($sinPermiso)->postJson(route('almacen.productos.equivalencias.store', ['id' => $p->ID_PRODUCTO]), ['numero_parte' => 'X1'])
            ->assertForbidden();
        $this->assertSame(0, ProductoEquivalencia::where('ID_PRODUCTO', $p->ID_PRODUCTO)->count());
    }
}
