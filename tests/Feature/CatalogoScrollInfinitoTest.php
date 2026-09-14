<?php

namespace Tests\Feature;

use Tests\MySqlTestCase;

/**
 * El scroll infinito de /admin/catalogo (catalogo_index.js) pide los lotes de 24 uno a uno:
 * juntos tienen que dar todas las tarjetas, en el mismo orden cada vez, y la primera página
 * (la de un filtro nuevo) trae el contador lateral con esos filtros.
 */
class CatalogoScrollInfinitoTest extends MySqlTestCase
{
    private function pagina(int $page, array $filtros = []): array
    {
        return $this->actingAs($this->superAdminGlobal())
            ->getJson(route('catalogo.index', $filtros + ['ajax_load' => 1, 'page' => $page]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->json();
    }

    private static function tarjetas(string $html): int
    {
        return preg_match_all('/class="cat-card[" ]/', $html);
    }

    public function test_los_lotes_juntos_dan_todas_las_tarjetas_en_un_orden_fijo(): void
    {
        $primera = $this->pagina(1);
        $this->assertNotNull($primera['stats'], 'La primera página trae el contador lateral.');
        $this->assertTrue((bool) preg_match('/(\d+)\s*<\/span>\s*<span[^>]*>\s*Modelos/', $primera['stats'], $m));
        $total = (int) $m[1];

        $vistas = self::tarjetas($primera['html']);
        for ($p = 2, $data = $primera; $data['hasMore']; $p++) {
            $data = $this->pagina($p);
            $this->assertNull($data['stats'], 'Los lotes del scroll no cambian el contador.');
            $this->assertSame($p, $data['page']);
            $vistas += self::tarjetas($data['html']);
        }
        $this->assertSame($total, $vistas, 'Entre todos los lotes salen todas las tarjetas.');

        // Mismo orden en cada petición: si no, un lote podría repetir o saltarse tarjetas.
        $this->assertSame($this->pagina(2)['html'], $this->pagina(2)['html']);
    }

    public function test_la_tarjeta_sin_ficha_muestra_la_foto_de_su_unidad(): void
    {
        // Sin ficha, la foto que toca es la de la unidad (color → modelo → FOTO_EQUIPO). Leída
        // como modelo Equipo, la columna FOTO caía en getFotoAttribute y la tarjeta salía sin foto.
        $modelo = 'PRUEBA-FOTO-' . strtoupper(uniqid());
        \App\Models\Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => $modelo, 'ANIO' => 2026, 'ESTADO_OPERATIVO' => 'OPERATIVO',
            'SERIAL_CHASIS' => 'TEST-FOTO-' . uniqid(), 'FOTO_EQUIPO' => '/storage/google/FOTOUNIDADPRUEBA?v=1',
        ]);
        $html = $this->pagina(1, ['modelo' => 'modelo_eq:' . $modelo])['html'];
        $this->assertSame(1, self::tarjetas($html));
        $this->assertStringContainsString('cat-sin-ficha', $html);
        $this->assertStringContainsString('/storage/google/FOTOUNIDADPRUEBA?sz=w300', $html);
    }

    public function test_con_un_filtro_el_contador_es_el_del_filtro(): void
    {
        $data = $this->pagina(1, ['anio' => '1900']);
        $this->assertSame(0, self::tarjetas($data['html']));
        $this->assertMatchesRegularExpression('/>\s*0\s*<\/span>\s*<span[^>]*>\s*Modelos/', $data['stats']);
    }
}
