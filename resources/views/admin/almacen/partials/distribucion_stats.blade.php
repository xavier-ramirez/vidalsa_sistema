{{-- Sidebar "Distribución de Inventario" — DOS MODOS:
      A) Cruzado: cuando $productoOtros está seteado (el usuario clickeó una sugerencia
         del filtro Buscar → la URL trae id_producto). Responde "¿dónde está este
         producto?" en dos niveles, de adentro hacia afuera:
           1. En ESTE almacén, repartido por proyecto ($productoProyectos). Solo sale en
              almacenes que separan por proyecto — en el resto todo el saldo vive en la
              bolsa común y el desglose repetiría el total en una sola línea.
           2. En los OTROS almacenes visibles ($productoOtros), para pedir un traspaso
              si el actual quedó corto.
      B) Por categoria: comportamiento default — agrupa los productos del almacen
         actual segun su categoria.

   El controlador decide qué pasar al partial (productoOtros queda null en modo B). --}}
@php
    $modoCruzado = isset($productoOtros) && $productoOtros !== null;
    $dist        = $distribucion ?? collect();
    $totalDist   = $dist->sum('total');
    $porProyecto = $productoProyectos ?? collect();
    // Saldo latino "12" / "12,5" / "1.800" — sin ceros sobrantes ni separador roto.
    // Se declara UNA vez y lo usan las dos listas del modo cruzado (antes el bloque de
    // otros almacenes repetía este number_format inline dentro de su bucle). OJO: sin
    // escribir directivas Blade en este comentario — dentro de un bloque PHP quedarían a
    // merced del compilador.
    $fmtQty = function ($n) {
        $q = rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',');
        return ($q === '' || $q === '-') ? '0' : $q;
    };
@endphp

@if($modoCruzado)
    {{-- Modo cruzado: listas minimales "nombre — cantidad" que responden "¿dónde está este
         producto?". El nombre del producto NO se repite aqui porque ya esta en la cabecera
         de la tabla principal (el chip "Filtros: ...") — duplicarlo confundia al cliente.

         El wrapper conserva la clase .alm-otros-almacenes: de ella cuelgan las reglas de
         mobile del index (tipografias compactas y el :has() que decide si el panel se
         muestra), asi que renombrarla apagaria el panel en telefono. --}}
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
                @php
                    // Frente 0 = bolsa común: material del almacén que todavía no es de ningún
                    // proyecto. Es saldo real y disponible, por eso se lista como una fila más
                    // — en cursiva para que se lea distinto de un proyecto con nombre propio.
                    $esComun = (int) $fila->ID_FRENTE === 0 || $fila->NOMBRE_FRENTE === null;
                @endphp
                <li class="alm-panel-row">
                    <span class="nom {{ $esComun ? 'comun' : '' }}">{{ $esComun ? 'Sin proyecto (común)' : $fila->NOMBRE_FRENTE }}</span>
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
                    $bajo = $row->CANTIDAD_MINIMA !== null && (float) $row->CANTIDAD <= (float) $row->CANTIDAD_MINIMA;
                @endphp
                <li class="alm-panel-row clicable"
                    onclick="window.almVerProductoEnAlmacen('{{ $row->ID_ALMACEN }}', '{{ addslashes($row->NOMBRE) }}', '{{ request('id_producto') }}')"
                    title="Ver este producto en {{ $row->NOMBRE }}">
                    <span class="nom">{{ $row->NOMBRE }}</span>
                    <span class="qty {{ $bajo ? 'bajo' : '' }}">{{ $fmtQty($row->CANTIDAD) }}</span>
                </li>
                {{-- Reparto por proyecto de ESE almacén, sin tener que cambiarse a él: saber
                     que de los 325 hay 150 en Patio I y 100 en Cortafuego es justo lo que
                     decide a quién pedirle el traspaso.
                     Solo con MÁS DE UN proyecto: con uno solo repetiría la cifra de arriba.
                     El controlador ya deja $row->proyectos vacío en los almacenes que no
                     separan (ver conDesgloseDeProyectos). --}}
                @if($row->proyectos->count() > 1)
                    <ul class="alm-panel-sub">
                        @foreach($row->proyectos as $p)
                            @php $esComun = (int) $p->ID_FRENTE === 0 || $p->NOMBRE_FRENTE === null; @endphp
                            <li>
                                <span class="nom {{ $esComun ? 'comun' : '' }}">{{ $esComun ? 'Sin proyecto (común)' : $p->NOMBRE_FRENTE }}</span>
                                <span class="qty">{{ $fmtQty($p->CANTIDAD) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endforeach
        </ul>
    @else
        <p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;font-style:italic;line-height:1.4;">
            Este producto solo existe en el almacén actual.
        </p>
    @endif
    </div>
@else
    {{-- Modo default: distribucion por categoria. El wrapper .alm-distribucion-cats
         permite ocultar todo el grafico en mobile (regla en index @media ≤768px) —
         el cliente lo pidio: el grafico de categorias es util en pantalla amplia
         pero come espacio vertical en telefono y se vio repetido con el listado
         de productos que ya muestra la categoria por fila. --}}
    <div class="alm-distribucion-cats">
        <h4 style="margin:0 0 12px 0;font-size:13px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;">
            <i class="material-icons" style="font-size:18px;color:#3b82f6;">pie_chart</i>
            Distribución de Inventario
        </h4>

        @if($dist->count() > 0)
            <ul style="list-style:none;padding:0;margin:0;max-height:64vh;overflow-y:auto;overflow-x:visible;display:flex;flex-direction:column;gap:4px;" class="custom-scrollbar">
                @foreach($dist as $row)
                    @php $pct = $totalDist > 0 ? ($row->total / $totalDist) * 100 : 0; @endphp
                    <li onclick="window.almFilterByCategoria('{{ $row->categoria === 'SIN CATEGORÍA' ? '' : addslashes($row->categoria) }}')"
                        style="padding:5px 6px;border-bottom:1px dashed #f1f5f9;cursor:pointer;border-radius:6px;transition:background 0.15s;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'"
                        title="{{ rtrim(rtrim(number_format((float)$row->unidades,3,',','.'),'0'),',') }} unidades en total">
                        <div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;">
                            <span style="color:#334155;font-size:12.5px;font-weight:600;line-height:1.25;flex:1;text-transform:uppercase;">
                                {{ $row->categoria }}
                            </span>
                            <span style="font-weight:700;color:#1e293b;font-size:12.5px;background:#f1f5f9;padding:2px 8px;border-radius:4px;white-space:nowrap;">
                                {{ $row->total }}
                            </span>
                        </div>
                        <div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">
                            <div style="width:{{ $pct }}%;height:100%;background:linear-gradient(90deg,#3b82f6 0%,#2563eb 100%);"></div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">Sin datos para mostrar.</p>
        @endif
    </div>
@endif
