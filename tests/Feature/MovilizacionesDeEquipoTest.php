<?php

namespace Tests\Feature;

use App\Models\Movilizacion;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * Modal "Movilizaciones" del detalle de equipo (/admin/equipos).
 *
 * Lo que se fija aqui es lo que se rompe sin avisar:
 *
 *  1. LA COLUMNA DE LA FECHA. `FECHA_DESPACHO` solo se rellena cuando el movimiento
 *     genera acta —la fila nace como `$generarPdf ? 'DESPACHO' : 'ACT.'`— asi que hoy
 *     esta vacia en el 62% de las filas. Ordenar por ahi ponia la movilizacion MAS
 *     RECIENTE la ULTIMA, porque MySQL manda los NULL al final en un DESC, y encima se
 *     leia "Sin fecha" en la mayoria. La buena es `created_at`, que es ademas la que ya
 *     usa el listado de /admin/movilizaciones.
 *  2. EL INDICE por el que entra la consulta. Sin el, MySQL escanea la tabla entera y
 *     ordena a mano; el modal seguiria funcionando —por eso nadie lo notaria— pero cada
 *     movilizacion nueva lo haria un poco mas lento, para siempre.
 *  3. QUE NO DEGENERE EN UN N+1. Los nombres de frente salen de accesores del modelo, y
 *     basta tocarlos para que cada fila pida su frente por separado.
 */
class MovilizacionesDeEquipoTest extends MySqlTestCase
{
    private const INDICE = 'idx_mov_hist_equipo_creado';

    /** Un usuario que pueda entrar al modulo de equipos. */
    private function usuario(): Usuario
    {
        $user = Usuario::all()->first(fn ($u) => $u->can('equipos.view') || $u->can('super.admin'));
        $this->assertNotNull($user, 'No hay usuario capaz de ver equipos.');

        return $user;
    }

    /** ID del equipo con mas movilizaciones registradas (o null si no hay ninguna). */
    private function equipoConMasMovilizaciones(): ?int
    {
        $fila = DB::table('movilizacion_historial')
            ->select('ID_EQUIPO', DB::raw('COUNT(*) as total'))
            ->whereNotNull('ID_EQUIPO')
            ->groupBy('ID_EQUIPO')
            ->orderByDesc('total')
            ->first();

        return $fila ? (int) $fila->ID_EQUIPO : null;
    }

    public function test_existe_el_indice_por_el_que_entra_la_consulta(): void
    {
        $columnas = DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'movilizacion_historial')
            ->where('index_name', self::INDICE)
            ->orderBy('SEQ_IN_INDEX')
            ->pluck('COLUMN_NAME')
            ->all();

        $this->assertSame(['ID_EQUIPO', 'created_at'], $columnas,
            'El indice ' . self::INDICE . ' debe ser (ID_EQUIPO, created_at). ID_EQUIPO primero '
            . 'porque es por donde filtra, y created_at dentro para que MySQL lea las filas ya '
            . 'ordenadas y se ahorre el filesort.');
    }

    public function test_el_plan_de_ejecucion_usa_el_indice_y_no_escanea_la_tabla(): void
    {
        $plan = DB::select(
            'EXPLAIN SELECT ID_MOVILIZACION FROM movilizacion_historial '
            . 'WHERE ID_EQUIPO = ? ORDER BY created_at DESC, ID_MOVILIZACION DESC',
            [$this->equipoConMasMovilizaciones() ?? 1]
        )[0];

        $this->assertSame(self::INDICE, $plan->key ?? null,
            'La consulta del modal deberia entrar por ' . self::INDICE . '.');
        $this->assertNotSame('ALL', $plan->type,
            'type=ALL significa escaneo completo de la tabla.');
        $this->assertStringNotContainsString('filesort', (string) ($plan->Extra ?? ''),
            'Si aparece filesort, el indice dejo de cubrir el ORDER BY.');
    }

    public function test_responde_las_movilizaciones_del_equipo_con_una_sola_consulta(): void
    {
        $idEquipo = $this->equipoConMasMovilizaciones();
        if ($idEquipo === null) {
            $this->markTestSkipped('No hay movilizaciones cargadas contra las que probar.');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $res = $this->actingAs($this->usuario())
            ->getJson("/admin/equipos/{$idEquipo}/movilizaciones");

        $consultas = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'movilizacion_historial')
                             || str_contains($q['query'], 'frentes_trabajo'))
            ->count();
        DB::disableQueryLog();

        $res->assertOk()->assertJson(['success' => true]);

        // 1 = la consulta principal. Se permite hasta 3 porque las filas ANTERIORES a las
        // columnas de snapshot necesitan el nombre del frente en vivo: eso son, como mucho,
        // dos load() para toda la coleccion. Lo que este numero impide es el N+1 (una
        // consulta por fila), que es el fallo que se cuela sin que nadie lo vea.
        $this->assertLessThanOrEqual(3, $consultas,
            "La respuesta uso {$consultas} consultas: hay un N+1 resolviendo los frentes.");

        $datos = $res->json('data');
        $this->assertNotEmpty($datos, 'El equipo elegido tiene movilizaciones; deberian venir.');
        $this->assertSame(
            ['id', 'codigo', 'fecha', 'origen', 'destino', 'detalle', 'usuario'],
            array_keys($datos[0]),
            'El payload cambio de forma; el modal lee estas claves. "tipo" se quito a proposito: '
            . 'decia "Despacho" en las filas ACT., que son la mayoria.'
        );
    }

    public function test_todas_las_filas_traen_fecha(): void
    {
        $idEquipo = $this->equipoConMasMovilizaciones();
        if ($idEquipo === null) {
            $this->markTestSkipped('No hay movilizaciones cargadas contra las que probar.');
        }

        $datos = $this->actingAs($this->usuario())
            ->getJson("/admin/equipos/{$idEquipo}/movilizaciones")
            ->json('data');

        foreach ($datos as $fila) {
            $this->assertNotNull($fila['fecha'],
                'Una fila salio sin fecha. Es la senal de que se volvio a leer FECHA_DESPACHO, '
                . 'que esta vacia en las movilizaciones registradas sin acta (tipo ACT.).');
        }
    }

    public function test_el_codigo_solo_sale_cuando_hay_acta(): void
    {
        $idEquipo = $this->equipoConMasMovilizaciones();
        if ($idEquipo === null) {
            $this->markTestSkipped('No hay movilizaciones cargadas contra las que probar.');
        }

        $datos = $this->actingAs($this->usuario())
            ->getJson("/admin/equipos/{$idEquipo}/movilizaciones")
            ->json('data');

        $conActa = Movilizacion::where('ID_EQUIPO', $idEquipo)
            ->whereNotNull('CODIGO_CONTROL')
            ->count();

        $devueltos = collect($datos)->filter(fn ($f) => $f['codigo'] !== null);

        $this->assertSame($conActa, $devueltos->count(),
            'Solo las filas con CODIGO_CONTROL deben traer codigo.');

        foreach ($devueltos as $f) {
            $this->assertMatchesRegularExpression('/^MV-\d{5}$/', $f['codigo'],
                'El codigo con acta debe venir formateado como MV-000NN.');
        }

        $this->assertEmpty(collect($datos)->where('codigo', 'R.D.')->all(),
            'Volvio el "R.D." del accesor formatted_codigo_control: lo devuelve para CUALQUIER '
            . 'fila sin CODIGO_CONTROL, y las de tipo ACT. —783 de 1.265— no son recepciones '
            . 'directas. El listado de /admin/movilizaciones no muestra nada en ese caso; el '
            . 'modal tampoco debe inventarse un rotulo.');
    }

    public function test_las_devuelve_de_la_mas_reciente_a_la_mas_antigua(): void
    {
        $idEquipo = $this->equipoConMasMovilizaciones();
        if ($idEquipo === null) {
            $this->markTestSkipped('No hay movilizaciones cargadas contra las que probar.');
        }

        $ids = collect($this->actingAs($this->usuario())
            ->getJson("/admin/equipos/{$idEquipo}/movilizaciones")
            ->json('data'))->pluck('id')->all();

        $esperado = Movilizacion::where('ID_EQUIPO', $idEquipo)
            ->orderByDesc('created_at')
            ->orderByDesc('ID_MOVILIZACION')
            ->pluck('ID_MOVILIZACION')
            ->all();

        $this->assertSame(array_slice($esperado, 0, count($ids)), $ids,
            'El orden debe ser created_at DESC con desempate por ID_MOVILIZACION DESC: un lote '
            . 'entero se guarda en el mismo segundo y sin desempate la lista se baraja entre '
            . 'una carga y otra.');
    }

    public function test_un_equipo_sin_movilizaciones_devuelve_lista_vacia_y_no_un_error(): void
    {
        // Un ID que con seguridad no tiene movimientos: por encima del maximo registrado.
        $inexistente = ((int) DB::table('movilizacion_historial')->max('ID_EQUIPO')) + 999999;

        $this->actingAs($this->usuario())
            ->getJson("/admin/equipos/{$inexistente}/movilizaciones")
            ->assertOk()
            ->assertJson(['success' => true, 'hay_mas' => false, 'data' => []]);
    }
}
