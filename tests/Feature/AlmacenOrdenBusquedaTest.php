<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\MySqlTestCase;

/**
 * /admin/almacen: al buscar escribiendo (sin elegir una sugerencia), la tabla tiene que salir
 * ordenada de lo MÁS parecido a lo más lejano — no alfabética.
 *
 * Por qué existe: el filtro traía bien todas las similitudes, pero el orden era
 * `ORDER BY NOMBRE` a secas. Buscando "manguera" aparecían primero
 * «ABRAZADERA 5" PARA MANGUERA DE ADMISIÓN JAC» y «ABRAZADERA 6"…», y las mangueras de
 * verdad quedaban de la cuarta fila en adelante: el usuario veía lo que pidió, pero
 * sepultado bajo lo que solo lo mencionaba de pasada.
 *
 * No toca datos: solo consulta. El caso de prueba se BUSCA en el inventario real en vez de
 * fijar un producto concreto, para que la prueba siga valiendo cuando el catálogo cambie.
 */
class AlmacenOrdenBusquedaTest extends MySqlTestCase
{
    /**
     * Encuentra un caso donde el orden se note de verdad: un almacén y un término para el
     * que existan un producto que EMPIEZA por el término y otro que solo lo menciona, y
     * donde el que solo lo menciona vaya ANTES alfabéticamente. Sin esa última condición
     * el alfabético acertaría por casualidad y la prueba no demostraría nada.
     *
     * @return array{0:int,1:string,2:string,3:string} [idAlmacen, término, empieza, menciona]
     */
    private function casoDondeElOrdenImporta(): array
    {
        foreach (['MANGUERA', 'VALVULA', 'TUBO', 'CABLE', 'PINTURA', 'FILTRO', 'BOTA'] as $term) {
            $fila = DB::table('almacen_stock as s')
                ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 's.ID_PRODUCTO')
                ->where('s.CANTIDAD', '>', 0)
                ->whereNull('p.deleted_at')
                ->where('p.NOMBRE', 'like', "%{$term}%")
                ->groupBy('s.ID_ALMACEN')
                ->selectRaw('s.ID_ALMACEN')
                ->selectRaw('MIN(CASE WHEN p.NOMBRE LIKE ? THEN p.NOMBRE END) AS empieza', ["{$term}%"])
                ->selectRaw('MIN(CASE WHEN p.NOMBRE NOT LIKE ? THEN p.NOMBRE END) AS menciona', ["{$term}%"])
                ->havingRaw('empieza IS NOT NULL AND menciona IS NOT NULL AND menciona < empieza')
                ->first();

            if ($fila) {
                return [(int) $fila->ID_ALMACEN, $term, $fila->empieza, $fila->menciona];
            }
        }

        $this->markTestSkipped('No hay en el inventario un caso donde el orden se pueda demostrar.');
    }

    /**
     * Un caso de error de tipeo fabricado a partir de un producto REAL: devuelve el almacén,
     * el nombre completo, la primera palabra de su nombre bien escrita, y esa misma palabra
     * con una letra de menos ("MANGUERA" → "MANGERA").
     *
     * En un helper y no copiado en cada prueba: son cinco las que lo necesitan, y si cada
     * una elige su producto por su cuenta, dos pruebas pueden acabar probando cosas
     * distintas sin que se note.
     *
     * @return array{0:int,1:string,2:string,3:string} [idAlmacen, nombre, palabra, conError]
     */
    private function casoConErrorDeTipeo(): array
    {
        $fila = DB::table('almacen_stock as s')
            ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 's.ID_PRODUCTO')
            ->where('s.CANTIDAD', '>', 0)->whereNull('p.deleted_at')
            ->whereRaw('p.NOMBRE REGEXP ?', ['^[A-Za-z]{7,} '])
            ->orderBy('p.NOMBRE')
            ->first(['s.ID_ALMACEN', 'p.NOMBRE']);

        if (!$fila) {
            $this->markTestSkipped('No hay un producto con una primera palabra larga para fabricar el error.');
        }

        $palabra = mb_strtolower(explode(' ', $fila->NOMBRE)[0]);

        return [
            (int) $fila->ID_ALMACEN,
            $fila->NOMBRE,
            $palabra,
            mb_substr($palabra, 0, 4) . mb_substr($palabra, 5),   // le falta la 5ª letra
        ];
    }

    /** Posición del nombre dentro del HTML de la tabla, o -1 si no aparece. */
    private function posicion(string $html, string $nombre): int
    {
        $p = mb_strpos($html, e($nombre));
        return $p === false ? -1 : $p;
    }

    public function test_lo_que_empieza_por_lo_escrito_sale_antes_que_lo_que_solo_lo_menciona(): void
    {
        [$idAlmacen, $term, $empieza, $menciona] = $this->casoDondeElOrdenImporta();

        $html = (string) $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/almacen?' . http_build_query([
                'id_almacen' => $idAlmacen,
                'search'     => $term,
            ]))->assertOk()->json('html');

        $pEmpieza  = $this->posicion($html, $empieza);
        $pMenciona = $this->posicion($html, $menciona);

        $this->assertGreaterThan(-1, $pEmpieza, "«{$empieza}» tiene que salir al buscar «{$term}».");
        $this->assertGreaterThan(-1, $pMenciona, "«{$menciona}» también sale: se buscan todas las similitudes.");
        $this->assertLessThan(
            $pMenciona,
            $pEmpieza,
            "Buscando «{$term}», «{$empieza}» tiene que ir ANTES que «{$menciona}», "
            . 'aunque alfabéticamente sea al revés: lo más parecido va primero.'
        );
    }

    /**
     * Escribir con un error de tipeo encuentra igual, y la pantalla lo dice.
     *
     * Por qué existe: el autocomplete ya perdonaba el error (FuzzySearch tolera Levenshtein),
     * así que escribir "mangera" mostraba las mangueras EN LA LISTA; pero al buscar sin
     * elegir ninguna, el servidor hacía LIKE literal y la tabla salía VACÍA. La lista decía
     * una cosa y la tabla otra.
     *
     * El reintento solo salta cuando el resultado exacto es CERO, y la respuesta trae
     * `aproximada` para que la pantalla avise de que son parecidos y no coincidencias.
     */
    public function test_con_un_error_de_tipeo_encuentra_igual_y_avisa_de_que_son_parecidos(): void
    {
        [$idAlmacen, $nombre, $palabra, $conError] = $this->casoConErrorDeTipeo();

        $pedir = fn (string $term) => $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/almacen?' . http_build_query([
                'id_almacen' => $idAlmacen,
                'search'     => $term,
            ]))->assertOk();

        // Lo escrito con error igual encuentra el producto, y viene marcado como aproximado.
        $conTypo = $pedir($conError);
        $this->assertTrue(
            $conTypo->json('aproximada'),
            "Escribiendo «{$conError}» no hay coincidencia exacta: tiene que avisar de que son parecidos."
        );
        $this->assertStringContainsString(
            e($nombre),
            (string) $conTypo->json('html'),
            "«{$nombre}» tiene que aparecer aunque se escriba «{$conError}»."
        );

        // Y lo escrito BIEN no cambia: sigue siendo una búsqueda exacta, sin aviso.
        $bien = $pedir($palabra);
        $this->assertFalse(
            $bien->json('aproximada'),
            "Escribiendo «{$palabra}» hay coincidencias exactas: no se toca la búsqueda que ya funcionaba."
        );
        $this->assertStringContainsString(e($nombre), (string) $bien->json('html'));
    }

    /**
     * Un plural CON error de tipeo encuentra lo mismo que el singular bien escrito.
     *
     * Por qué existe: la regla del singular ("BOTAS" encuentra "BOTA…") estaba escrita dos
     * veces —una en el filtro y otra en el orden— y cada copia le daba una entrada distinta
     * al perdón de tipeo: el filtro perdonaba el plural y metía el singular literal. Medido
     * antes del arreglo: "mangeras" devolvía 1 fila donde "manguera" devolvía 12, y "botazs"
     * ninguna. Ahora las formas de una palabra salen de un solo sitio
     * (formasDeBuscarPalabra), así que filtro y orden miran exactamente lo mismo.
     */
    public function test_un_plural_mal_escrito_encuentra_lo_mismo_que_el_singular(): void
    {
        $ctrl      = new \ReflectionClass(\App\Http\Controllers\AlmacenController::class);
        $instancia = $ctrl->newInstanceWithoutConstructor();
        $buscar    = $ctrl->getMethod('aplicarBusquedaProducto');
        $buscar->setAccessible(true);

        $cuantos = function (string $term, bool $tolerante) use ($buscar, $instancia) {
            $q = DB::table('productos_inventario')->whereNull('deleted_at');
            $buscar->invoke($instancia, $q, $term, ['CODIGO', 'NOMBRE'], false, $tolerante);
            return $q->count();
        };

        // Una palabra del catálogo lo bastante larga para que el error no la deje irreconocible.
        $nombre = DB::table('productos_inventario')->whereNull('deleted_at')
            ->whereRaw('NOMBRE REGEXP ?', ['^[A-Za-z]{8,} '])
            ->orderBy('NOMBRE')->value('NOMBRE');

        if (!$nombre) {
            $this->markTestSkipped('No hay una palabra larga en el catálogo para probarlo.');
        }

        $palabra  = mb_strtolower(explode(' ', $nombre)[0]);
        $bien     = $cuantos($palabra, false);
        $conError = mb_substr($palabra, 0, 4) . mb_substr($palabra, 5);   // le falta una letra

        $this->assertGreaterThan(0, $bien, 'La palabra bien escrita tiene que encontrar algo.');
        $this->assertSame($bien, $cuantos($conError, true), 'Con el error encuentra lo mismo.');
        $this->assertSame(
            $bien,
            $cuantos($conError . 's', true),
            "«{$conError}s» (plural Y con error) tiene que encontrar lo mismo que «{$palabra}»: "
            . 'el perdón del tipeo se aplica también al singular.'
        );
    }

    /**
     * Un `%` escrito en el buscador es un PORCENTAJE, no un comodín de SQL.
     *
     * Por qué existe: el filtro metía lo escrito crudo dentro del LIKE, así que buscar "%"
     * hacía `LIKE '%%%'` y devolvía el catálogo entero; y buscar "100%" traía los 60
     * productos que llevan "100" en cualquier parte en vez de los que dicen "100%". Hay 14
     * productos con `%` en el nombre, así que no es un caso inventado.
     *
     * Importa además que el filtro y el orden lo entiendan IGUAL: si uno escapa y el otro no,
     * el orden puntúa contra un texto que no casa con nada y se pierde la relevancia. Por eso
     * los dos pasan por comoTextoEnLike().
     */
    public function test_un_porcentaje_escrito_no_actua_como_comodin(): void
    {
        $ctrl      = new \ReflectionClass(\App\Http\Controllers\AlmacenController::class);
        $instancia = $ctrl->newInstanceWithoutConstructor();
        $buscar    = $ctrl->getMethod('aplicarBusquedaProducto');
        $buscar->setAccessible(true);

        $cuantos = function (string $term) use ($buscar, $instancia) {
            $q = DB::table('productos_inventario')->whereNull('deleted_at');
            $buscar->invoke($instancia, $q, $term, ['CODIGO', 'NOMBRE'], false);
            return $q->count();
        };

        $total      = DB::table('productos_inventario')->whereNull('deleted_at')->count();
        $conPorCien = DB::table('productos_inventario')->whereNull('deleted_at')
            ->where('NOMBRE', 'like', '%\%%')->count();

        if ($conPorCien === 0) {
            $this->markTestSkipped('No hay productos con «%» en el nombre para probarlo.');
        }

        $this->assertSame(
            $conPorCien,
            $cuantos('%'),
            'Buscar «%» tiene que devolver los productos que lo llevan en el nombre, no el catálogo entero.'
        );
        $this->assertLessThan($total, $cuantos('%'), 'Un «%» no puede traerlo todo.');

        // El guion bajo, igual: es un carácter, no "cualquier carácter".
        $this->assertSame(
            DB::table('productos_inventario')->whereNull('deleted_at')->where('NOMBRE', 'like', '%\_%')->count(),
            $cuantos('_'),
            'Buscar «_» tiene que buscar ese carácter, no comodín.'
        );
    }

    /**
     * El perdón de tipeo tiene techo: una frase larga no dispara miles de comparaciones.
     *
     * Por qué existe: cada palabra genera unas tres variantes por letra, y cada variante son
     * varios LIKE en el WHERE y otros tantos en el ORDER BY. Medido antes del tope: "manguera
     * hidraulica reforzada industrial pesada" generaba 417 LIKE y tardaba 118 ms, y un texto
     * pegado de muchas palabras habría generado miles y un SQL de cientos de KB. Pasados los
     * topes se busca exacto, como siempre — que es justo lo que se quiere con una frase así.
     */
    public function test_una_frase_larga_no_dispara_el_perdon_de_tipeo(): void
    {
        $ctrl      = new \ReflectionClass(\App\Http\Controllers\AlmacenController::class);
        $instancia = $ctrl->newInstanceWithoutConstructor();
        $buscar    = $ctrl->getMethod('aplicarBusquedaProducto');
        $buscar->setAccessible(true);

        $sqlDe = function (string $term) use ($buscar, $instancia) {
            $q = DB::table('productos_inventario')->whereNull('deleted_at');
            $buscar->invoke($instancia, $q, $term, ['CODIGO', 'NOMBRE'], true, true);
            return $q->toSql();
        };

        // Dentro del tope (3 palabras): se perdona, así que hay muchas comparaciones.
        $corto = substr_count($sqlDe('manguera hidraulica reforzada'), 'like');
        $this->assertGreaterThan(50, $corto, 'Con pocas palabras sí se perdona el error.');

        // Pasado el tope: se busca exacto y el número de comparaciones cae en picado.
        $largo = substr_count($sqlDe('manguera hidraulica reforzada industrial pesada'), 'like');
        $this->assertLessThan(
            $corto,
            $largo,
            'Una frase de más palabras NO puede generar más comparaciones que una corta: '
            . 'pasado el tope se busca exacto.'
        );

        // Y un texto pegado tampoco desborda.
        $pegado = substr_count($sqlDe(str_repeat('palabralarga ', 30)), 'like');
        $this->assertLessThan(200, $pegado, 'Un texto pegado no puede disparar cientos de comparaciones.');

        // Una palabra larguísima tampoco genera variantes (MAX_LETRAS_CON_PERDON).
        $variantes = $ctrl->getMethod('variantesConUnError');
        $variantes->setAccessible(true);
        $this->assertSame([], $variantes->invoke($instancia, str_repeat('a', 40)));
        $this->assertNotSame([], $variantes->invoke($instancia, 'manguera'), 'Una palabra normal sí se perdona.');
    }

    /**
     * Las páginas siguientes del scroll de una búsqueda aproximada van directas al modo
     * tolerante (`aprox=1`), sin repetir en cada una la consulta exacta que ya se sabe vacía.
     *
     * Lo pone el front en los filtros congelados que reusan los append. Además evita el caso
     * raro de que una página vacía de una búsqueda EXACTA —porque los datos cambiaron a media
     * lectura— se rellene con parecidos que no vienen a cuento.
     */
    public function test_el_scroll_de_una_busqueda_aproximada_sigue_siendo_aproximado(): void
    {
        [$idAlmacen, $nombre, $palabra, $conError] = $this->casoConErrorDeTipeo();

        $pedir = fn (array $extra) => $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/almacen?' . http_build_query(array_merge([
                'id_almacen' => $idAlmacen,
                'search'     => $conError,
            ], $extra)))->assertOk();

        // En una página siguiente sí se honra: se va directo al modo tolerante.
        $this->assertTrue(
            $pedir(['aprox' => 1, 'offset' => 120])->json('aproximada'),
            'En el scroll de una búsqueda aproximada, las páginas siguientes siguen siendo aproximadas.'
        );

        // Pero en la PRIMERA página no se cree el parámetro: se mide. Si no, bastaría un
        // enlace con &aprox=1 para que una búsqueda con coincidencias exactas se anunciara
        // como "sin coincidencias exactas, mostrando parecidos".
        $exacta = $this->actingAs($this->superAdminGlobal())
            ->getJson('/admin/almacen?' . http_build_query([
                'id_almacen' => $idAlmacen,
                'search'     => $palabra,       // escrita BIEN
                'aprox'      => 1,              // y aun así
            ]))->assertOk();

        $this->assertFalse(
            $exacta->json('aproximada'),
            'Un aprox=1 puesto a mano no puede hacer pasar por aproximada una búsqueda exacta.'
        );
        $this->assertStringContainsString(e($nombre), (string) $exacta->json('html'));
    }

    /**
     * El panel lateral reparte por categoría LO QUE LA TABLA ESTÁ MOSTRANDO, también cuando
     * la búsqueda fue aproximada.
     *
     * Por qué existe: al añadir el perdón de tipeo solo al listado, escribir "acetminofen"
     * enseñaba 4 filas en la tabla y un panel VACÍO justo al lado — medido, no supuesto.
     * El panel iba por su cuenta buscando la palabra exacta.
     */
    public function test_el_panel_lateral_acompana_a_la_tabla_en_una_busqueda_aproximada(): void
    {
        [$idAlmacen, $nombre, $palabra, $conError] = $this->casoConErrorDeTipeo();

        $panelDe = function (string $term) use ($idAlmacen) {
            $r = $this->actingAs($this->superAdminGlobal())
                ->getJson('/admin/almacen?' . http_build_query([
                    'id_almacen' => $idAlmacen,
                    'search'     => $term,
                ]))->assertOk();

            return [
                substr_count((string) $r->json('html'), 'tr class="alm-row'),
                (string) $r->json('distribucionHtml'),
                (bool) $r->json('aproximada'),
            ];
        };

        [$filasBien, $panelBien, $aproxBien]    = $panelDe($palabra);
        [$filasError, $panelError, $aproxError] = $panelDe($conError);

        $this->assertFalse($aproxBien, 'Escrito bien tiene que ser una búsqueda exacta.');
        $this->assertTrue($aproxError, 'Escrito con error tiene que caer en la aproximada.');
        $this->assertGreaterThan(0, $filasError, 'Con el error tiene que encontrar filas igual.');
        // El tolerante puede encontrar ALGUNO MÁS que el exacto —el patrón «mang_era» casa
        // «mangXera» para cualquier X—, así que exigir el mismo número haría fallar la prueba
        // en cuanto un producto del catálogo tuviera una errata en el nombre. Lo que importa
        // es que no encuentre MENOS: todo lo que sale bien escrito tiene que salir también
        // con el error.
        $this->assertGreaterThanOrEqual($filasBien, $filasError,
            'La búsqueda con error no puede encontrar menos que la bien escrita.');

        // Comparar los dos paneles NO basta: si los dos salieran vacíos serían iguales y
        // esta prueba pasaría sin demostrar nada. Así que además se exige que el panel
        // tenga contenido de verdad — y para eso hace falta que al menos uno de los
        // productos encontrados tenga existencia, porque el panel solo reparte lo que TIENE
        // saldo (decisión anterior a este cambio: va pegado al KPI "CON STOCK").
        $conSaldo = DB::table('almacen_stock')
            ->where('ID_ALMACEN', $idAlmacen)
            ->where('CANTIDAD', '>', 0)
            ->whereIn('ID_PRODUCTO', function ($q) use ($palabra) {
                $q->select('ID_PRODUCTO')->from('productos_inventario')
                  ->whereNull('deleted_at')->where('NOMBRE', 'like', "%{$palabra}%");
            })->exists();

        if ($conSaldo) {
            $this->assertMatchesRegularExpression(
                '/alm-dist-row|alm-cat-row|<li/',
                $panelError,
                'Con productos que tienen existencia, el panel no puede venir vacío.'
            );
        }
    }

    /**
     * El Excel también entiende lo escrito con un error de tipeo.
     *
     * Por qué existe: al añadir el perdón de tipeo solo al listado, buscar con un error
     * enseñaba filas en pantalla y bajaba un Excel VACÍO. Por eso la regla vive en un único
     * sitio (buscarConPerdonDeTipeo) y la usan los dos.
     *
     * Lo que se comprueba es que el FILTRO se entiende igual, no que el archivo traiga las
     * mismas filas que la pantalla: el export salta los productos con saldo 0 "a pedido del
     * cliente" (decisión anterior a este cambio) mientras que la tabla sí los enseña cuando
     * hay una búsqueda. Por eso la prueba elige a propósito un producto CON existencia — si
     * exigiera todas las filas, estaría fijando un comportamiento que el módulo nunca tuvo.
     */
    public function test_el_excel_entiende_lo_escrito_con_un_error_de_tipeo(): void
    {
        [$idAlmacen, $nombre, $palabra, $conError] = $this->casoConErrorDeTipeo();
        $query    = ['id_almacen' => $idAlmacen, 'search' => $conError];

        // La tabla sí encuentra (aproximado).
        $this->assertTrue(
            $this->actingAs($this->superAdminGlobal())
                ->getJson('/admin/almacen?' . http_build_query($query))->assertOk()->json('aproximada')
        );

        // Y el Excel de esa misma búsqueda tiene que traer ese producto. Se compara el
        // CONTENIDO del .xlsx (es un ZIP: el texto va en la tabla de cadenas compartidas),
        // no su tamaño, que no demuestra nada.
        $resp = $this->actingAs($this->superAdminGlobal())
            ->get('/admin/almacen/export?' . http_build_query($query))->assertOk();

        ob_start();
        $resp->baseResponse->sendContent();          // streamDownload no expone el string
        $xlsx = (string) ob_get_clean();

        $this->assertSame('PK', substr($xlsx, 0, 2), 'Lo descargado tiene que ser un XLSX.');

        $ruta = tempnam(sys_get_temp_dir(), 'expalm') . '.xlsx';
        file_put_contents($ruta, $xlsx);
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($ruta) === true, 'El XLSX tiene que abrirse.');
            $texto = (string) $zip->getFromName('xl/sharedStrings.xml');
            $zip->close();

            $this->assertStringContainsString(
                htmlspecialchars($nombre, ENT_XML1),
                $texto,
                "«{$nombre}» sale en la tabla al escribir «{$conError}», así que el Excel tiene que traerlo también."
            );
        } finally {
            @unlink($ruta);
        }
    }

    /**
     * El orden tiene que ser TOTAL, no solo por relevancia: 28 productos se llaman
     * "FILTRO DE ACEITE DE MOTOR". Si dos filas empatan y no queda nada que las desempate,
     * MySQL no garantiza devolverlas siempre igual — y el scroll infinito, que pide las
     * páginas con skip/take en peticiones distintas, puede repetir una fila o saltársela.
     */
    public function test_las_paginas_del_scroll_no_repiten_ni_se_saltan_filas(): void
    {
        // Hace falta una búsqueda que pase de una página (el módulo las trae de 120 en 120).
        $caso = null;
        foreach (['FILTRO', 'TUBO', 'ACEITE', 'A'] as $term) {
            $fila = DB::table('almacen_stock as s')
                ->join('productos_inventario as p', 'p.ID_PRODUCTO', '=', 's.ID_PRODUCTO')
                ->where('s.CANTIDAD', '>', 0)->whereNull('p.deleted_at')
                ->where('p.NOMBRE', 'like', "%{$term}%")
                ->groupBy('s.ID_ALMACEN')
                // DISTINCT sobre el producto: almacen_stock tiene una fila por PROYECTO, y
                // el listado agrupa por producto. Contando filas, un almacén con varios
                // proyectos daría n > 120 con menos de 120 productos y la prueba fallaría
                // sin que hubiera nada roto.
                ->selectRaw('s.ID_ALMACEN, COUNT(DISTINCT p.ID_PRODUCTO) AS n')
                ->havingRaw('n > 120')
                ->orderByDesc('n')
                ->first();
            if ($fila) { $caso = [(int) $fila->ID_ALMACEN, $term]; break; }
        }
        if (!$caso) {
            $this->markTestSkipped('Ninguna búsqueda pasa de una página: no hay scroll que probar.');
        }
        [$idAlmacen, $term] = $caso;

        // Dos peticiones distintas, como las que hace el scroll al bajar.
        $codigos = [];
        foreach ([0, 120] as $offset) {
            $html = (string) $this->actingAs($this->superAdminGlobal())
                ->getJson('/admin/almacen?' . http_build_query([
                    'id_almacen' => $idAlmacen,
                    'search'     => $term,
                    'offset'     => $offset,
                ]))->assertOk()->json('html');

            preg_match_all('/data-codigo="([^"]*)"/', $html, $m);
            $codigos = array_merge($codigos, $m[1]);
        }

        $this->assertGreaterThan(120, count($codigos), 'La segunda página tiene que traer filas nuevas.');
        $this->assertSame(
            count($codigos),
            count(array_unique($codigos)),
            'Ninguna fila puede salir dos veces entre una página del scroll y la siguiente.'
        );
    }
}
