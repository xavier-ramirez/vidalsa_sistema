{{-- Panel lateral del inventario (AlmacenController::panelLateral). DOS MODOS; el controlador
     decide cuál pasando o no $productoOtros:
      A) "En otros almacenes" — de UN producto ($idProducto). Responde "¿dónde está este
         producto?" en dos niveles, de adentro hacia afuera:
           1. En ESTE almacén, repartido por proyecto ($productoProyectos). Solo sale en
              almacenes que separan por proyecto — en el resto todo el saldo vive en la bolsa
              común y el desglose repetiría el total en una sola línea.
           2. En CADA uno de los otros almacenes visibles ($productoOtros), también los que
              tienen 0 (pedido del cliente, 16-09-2026: el cero es una respuesta), para pedir
              un traspaso si el actual quedó corto. Cada uno cuelga TAMBIÉN su reparto por
              proyecto ($row->proyectos, que arma el controlador): saber que de los 325 hay 150
              en un frente y 100 en otro es lo que decide a quién pedírselo.
         Sale cuando el filtro apunta a un producto (sugerencia o búsqueda con una sola fila)
         o al tocar una fila de la tabla. No repite la descripción del producto: la fila
         marcada ya la dice (decisión del cliente, 15-09-2026).
      B) "Distribución de Inventario" — sin producto: los productos que la tabla está
         filtrando, agrupados por categoría ($distribucion); clic en una para filtrarla
         (almCatPick). Es lo que se ve al abrir el módulo y mientras no se toque una fila. --}}
@php
    $modoCruzado = isset($productoOtros) && $productoOtros !== null;
    $porProyecto = $productoProyectos ?? collect();
    $dist        = $distribucion ?? collect();
    $totalDist   = $dist->sum('total');
    // Saldo latino "12" / "12,5" / "1.800" — sin ceros sobrantes ni separador roto.
    // Se declara UNA vez y lo usan las listas de los dos modos. OJO: sin escribir
    // directivas Blade en este comentario — dentro de un bloque PHP quedarían a merced
    // del compilador.
    $fmtQty = function ($n) {
        $q = rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
        return ($q === '' || $q === '-') ? '0' : $q;
    };
    // Rotulo de una fila de reparto por proyecto. El frente 0 es la BOLSA COMUN del
    // almacen -material que todavia no es de ningun proyecto-: es saldo real, asi que se
    // lista como una fila mas, solo que con nombre propio y en cursiva.
    //
    // La REGLA y el TEXTO salen de InventarioService (esBolsaComun/rotuloBolsa), que es de
    // donde los leen tambien el detalle del producto y el export de la bitacora. Aqui solo
    // queda el envoltorio que las DOS listas del modo A necesitan: [esComun, texto].
    $rotuloFrente = function ($fila) {
        return [
            \App\Services\InventarioService::esBolsaComun($fila->ID_FRENTE, $fila->NOMBRE_FRENTE),
            \App\Services\InventarioService::rotuloBolsa($fila->ID_FRENTE, $fila->NOMBRE_FRENTE),
        ];
    };
@endphp

@if($modoCruzado)
{{-- Listas minimales "nombre ····· cantidad": la .guia punteada une cada nombre con su cifra.
     El wrapper conserva la clase .alm-otros-almacenes: de ella cuelgan las reglas de mobile del
     index (tipografias compactas) y el :has() que decide si el panel se ve en el teléfono, asi
     que renombrarla apagaria el panel. --}}
<div class="alm-otros-almacenes">
    {{-- ── 1. Puertas adentro: reparto por proyecto ────────────────────────────────
         Solo en almacenes que separan por proyecto. Es la ÚNICA forma de ver el reparto:
         el módulo ya no tiene filtro por proyecto en la barra de arriba, porque obligaba a
         ir probando de a un proyecto por vez para responder lo que esta lista contesta de
         un golpe. --}}
    @if($porProyecto->count() > 0)
        @php $totalAqui = $porProyecto->sum('CANTIDAD'); @endphp
        <h4 class="alm-panel-h4">
            <i class="material-icons" style="color:#0067b1;">warehouse</i>
            Almacén {{ $almacenActualNombre ?? 'actual' }}
        </h4>
        <ul class="alm-panel-list">
            @foreach($porProyecto as $fila)
                @php [$esComun, $rotulo] = $rotuloFrente($fila); @endphp
                <li class="alm-panel-row">
                    <span class="nom {{ $esComun ? 'comun' : '' }}">{{ $rotulo }}</span>
                    <span class="guia" aria-hidden="true"></span>
                    <span class="qty proy">{{ $fmtQty($fila->CANTIDAD) }}</span>
                </li>
            @endforeach
        </ul>
        <div class="alm-panel-total">
            <span>Total en el almacén</span>
            <strong>{{ $fmtQty($totalAqui) }}</strong>
        </div>
    @endif

    {{-- ── 2. Puertas afuera: el resto de la red ── --}}
    <h4 class="alm-panel-h4 {{ $porProyecto->count() > 0 ? 'sep' : '' }}">
        <i class="material-icons" style="color:#10b981;">place</i>
        En otros almacenes
    </h4>

    @if($productoOtros->count() > 0)
        <ul class="alm-panel-list scroll custom-scrollbar">
            @foreach($productoOtros as $row)
                @php
                    $bajo   = $row->CANTIDAD_MINIMA !== null && (float) $row->CANTIDAD <= (float) $row->CANTIDAD_MINIMA;
                    // Con UN solo proyecto no se despliega: repetiría la cifra de la fila.
                    $conSub = $row->proyectos->count() > 1;
                @endphp
                {{-- El desglose va DENTRO del <li> del almacén, no como hermano suyo:
                     - Un <ul> colgando directo de otro <ul> no es HTML válido.
                     - Y como .alm-panel-list es flex con gap, de hermano quedaba separado
                       del almacén al que pertenece EXACTAMENTE igual que del siguiente:
                       con dos almacenes no se sabía dónde acababa uno.
                     Dentro, el hover y el clic de la fila lo abarcan, que es justo lo que
                     dice "esto es de este almacén".
                     Los <span> .nom y .qty siguen siendo hijos DIRECTOS del <li>: de ellos cuelgan
                     las reglas de teléfono (.alm-otros-almacenes li > .nom y li > .qty), así que meterlos
                     en un envoltorio las habría apagado sin avisar. --}}
                <li class="alm-panel-row clicable {{ $conSub ? 'con-sub' : '' }}"
                    onclick="window.almVerProductoEnAlmacen('{{ $row->ID_ALMACEN }}', '{{ addslashes($row->NOMBRE) }}', '{{ $idProducto }}')"
                    title="Ver este producto en {{ $row->NOMBRE }}">
                    <span class="nom">{{ $row->NOMBRE }}</span>
                    <span class="guia" aria-hidden="true"></span>
                    <span class="qty {{ $bajo ? 'bajo' : '' }}">{{ $fmtQty($row->CANTIDAD) }}</span>
                    @if($conSub)
                        <ul class="alm-panel-sub">
                            @foreach($row->proyectos as $p)
                                @php [$esComun, $rotulo] = $rotuloFrente($p); @endphp
                                <li>
                                    <span class="nom {{ $esComun ? 'comun' : '' }}">{{ $rotulo }}</span>
                                    <span class="guia" aria-hidden="true"></span>
                                    <span class="qty">{{ $fmtQty($p->CANTIDAD) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        {{-- El usuario solo ve este almacén (visibilidad por frentes): no hay otro al que preguntar. --}}
        <p class="alm-panel-nota">No tienes otros almacenes a la vista.</p>
    @endif
</div>
@else
{{-- Modo B: distribución por categoría de lo que la tabla está filtrando. El wrapper
     .alm-distribucion-cats es lo que el index oculta en el teléfono (cada tarjeta ya muestra su
     categoría y este gráfico comía pantalla; pedido del cliente). --}}
<div class="alm-distribucion-cats">
    <h4 class="alm-panel-h4">
        <i class="material-icons" style="color:#3b82f6;">pie_chart</i>
        Distribución de Inventario
        {{-- Camara: baja el panel como imagen. Reusa window.descargarPanelHtmlFDM
             (fleet_dashboard.js, que estructura_base carga en todas las páginas): ya
             resuelve la carga diferida de html2canvas, abre las listas con scroll para
             que la foto salga completa y esconde los botones en la captura. NO se
             escribe un captador nuevo aquí. Fotografía la COLUMNA entera (#almLateral):
             así la imagen sale con el Consolidado de Inventario arriba y la distribución
             debajo, que es como se lee en pantalla. En el teléfono el JS saca el panel de
             esa columna, y entonces se cae a #almDistWrapper, que es la tarjeta blanca con
             su borde (nunca el div de dentro: saldría a filo, sin bordes). --}}
        <button type="button" class="alm-cat-cam"
                onclick="window.descargarPanelHtmlFDM(document.querySelector('#almLateral #almDistribucionContainer') ? 'almLateral' : 'almDistWrapper','distribucion_de_inventario')"
                title="Descargar imagen" aria-label="Descargar imagen">
            <i class="material-icons">photo_camera</i>
        </button>
    </h4>

    @if($dist->count() > 0)
        <ul class="alm-panel-list scroll custom-scrollbar alm-cat-list">
            @foreach($dist as $row)
                @php
                    $pct       = $totalDist > 0 ? ($row->total / $totalDist) * 100 : 0;
                    // "SIN CATEGORÍA" no es una categoría registrada: no hay por qué filtrar.
                    $filtrable = $row->categoria !== 'SIN CATEGORÍA';
                @endphp
                <li class="alm-cat-row {{ $filtrable ? 'clicable' : '' }}"
                    @if($filtrable) onclick="window.almCatPick('{{ addslashes($row->categoria) }}')" title="Filtrar por {{ $row->categoria }}" @endif>
                    <div class="alm-panel-row">
                        {{-- Sin .guia: aqui el nombre ocupa el ancho (flex:1) y el badge ya
                             ancla el numero a la derecha. La llevan las OTRAS dos listas
                             de este partial, donde el nombre no se estira. --}}
                        <span class="nom">{{ $row->categoria }}</span>
                        <span class="qty" title="{{ $fmtQty($row->unidades) }} unidades en total">{{ $row->total }}</span>
                    </div>
                    <div class="alm-cat-bar"><div style="width:{{ round($pct, 1) }}%;"></div></div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="alm-panel-nota">Sin datos para mostrar.</p>
    @endif
</div>
@endif
