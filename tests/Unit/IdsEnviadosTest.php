<?php

namespace Tests\Unit;

use App\Support\IdsEnviados;
use PHPUnit\Framework\TestCase;

/**
 * IdsEnviados: los ids de las exportaciones llegan en un solo campo separado por comas (ids[]
 * se cortaba en max_input_vars, 1000 campos). Se acepta también el array del JS anterior.
 */
class IdsEnviadosTest extends TestCase
{
    public function test_lee_la_lista_separada_por_comas_en_su_orden(): void
    {
        $this->assertSame([305, 12, 7], IdsEnviados::leer('305, 12,7'));
    }

    public function test_mas_de_mil_ids_llegan_todos(): void
    {
        $ids = range(1, 2500);
        $this->assertSame($ids, IdsEnviados::leer(implode(',', $ids)));
    }

    public function test_acepta_el_array_de_antes(): void
    {
        $this->assertSame([4, 9], IdsEnviados::leer(['4', 9]));
    }

    public function test_descarta_lo_que_no_es_un_id_y_los_repetidos(): void
    {
        $this->assertSame([3, 8], IdsEnviados::leer('3,,abc,-2,0,3,8'));
        $this->assertSame([], IdsEnviados::leer(null));
        $this->assertSame([], IdsEnviados::leer(''));
        $this->assertSame([5], IdsEnviados::leer([5, ['x'], null]));
    }
}
