{{-- Panel lateral "En otros almacenes" de UN producto (AlmacenController::panelOtrosAlmacenes).
     Responde "¿dónde está este producto?" en dos niveles, de adentro hacia afuera:
       1. En ESTE almacén, repartido por proyecto ($productoProyectos). Solo sale en almacenes
          que separan por proyecto — en el resto todo el saldo vive en la bolsa común y el
          desglose repetiría el total en una sola línea.
       2. En los OTROS almacenes visibles ($productoOtros), para pedir un traspaso si el actual
          quedó corto. Cada uno cuelga TAMBIÉN su reparto por proyecto ($row->proyectos, que arma
          el controlador): saber que de los 325 hay 150 en un frente y 100 en otro es lo que
          decide a quién pedírselo.
     Sale cuando el filtro apunta a un producto (sugerencia o búsqueda con una sola fila) o al
     tocar una fila de la tabla ($producto: entonces dice de cuál habla), y SOLO si otro almacén
     tiene existencias: sin ninguna el controlador no renderiza este partial. Ya no hay lista por
     categoría: sumaba productos distintos y no respondía nada útil (decisión del cliente,
     15-09-2026). --}}
@php
    $porProyecto = $productoProyectos ?? collect();
    // Saldo latino "12" / "12,5" / "1.800" — sin ceros sobrantes ni separador roto.
    // Se declara UNA vez y lo usan las dos listas del panel (antes el bloque de
    // otros almacenes repetía este number_format inline dentro de su bucle). OJO: sin
    // escribir directivas Blade en este comentario — dentro de un bloque PHP quedarían a
    // merced del compilador.
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
    // queda el envoltorio que las DOS listas de esta vista necesitan: [esComun, texto].
    $rotuloFrente = function ($fila) {
        return [
            \App\Services\InventarioService::esBolsaComun($fila->ID_FRENTE, $fila->NOMBRE_FRENTE),
            \App\Services\InventarioService::rotuloBolsa($fila->ID_FRENTE, $fila->NOMBRE_FRENTE),
        ];
    };
@endphp

{{-- Listas minimales "nombre — cantidad". El wrapper conserva la clase .alm-otros-almacenes:
     de ella cuelgan las reglas de mobile del index (tipografias compactas) y el :has() que
     decide si el panel se muestra, asi que renombrarla apagaria el panel. --}}
<div class="alm-otros-almacenes">
    {{-- Al tocar una fila (varios productos en la tabla) el panel dice de cuál habla. --}}
    @if(!empty($producto))
        <p class="alm-otros-prod">{{ $producto->CODIGO }} · {{ $producto->NOMBRE }}</p>
    @endif

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
                     Los dos <span> siguen siendo hijos DIRECTOS del <li>: de ellos cuelgan
                     las reglas de teléfono (.alm-otros-almacenes li > span), así que meterlos
                     en un envoltorio las habría apagado sin avisar. --}}
                <li class="alm-panel-row clicable {{ $conSub ? 'con-sub' : '' }}"
                    onclick="window.almVerProductoEnAlmacen('{{ $row->ID_ALMACEN }}', '{{ addslashes($row->NOMBRE) }}', '{{ $idProducto }}')"
                    title="Ver este producto en {{ $row->NOMBRE }}">
                    <span class="nom">{{ $row->NOMBRE }}</span>
                    <span class="qty {{ $bajo ? 'bajo' : '' }}">{{ $fmtQty($row->CANTIDAD) }}</span>
                    @if($conSub)
                        <ul class="alm-panel-sub">
                            @foreach($row->proyectos as $p)
                                @php [$esComun, $rotulo] = $rotuloFrente($p); @endphp
                                <li>
                                    <span class="nom {{ $esComun ? 'comun' : '' }}">{{ $rotulo }}</span>
                                    <span class="qty">{{ $fmtQty($p->CANTIDAD) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
    </ul>
</div>
