{{-- Filas compactas del kardex para el modal "Movimientos del producto"
     (almKardexProductoModal). Igual que kardex_rows.blade.php pero SIN la
     columna Producto — ya estamos viendo movimientos de UN producto — y SIN
     la de Fecha (el cliente la pidió fuera; el rango se sigue filtrando arriba).
     5 columnas: Tipo · Cantidad · Stock · Destino · Documento. Destino y Documento iban en
     una sola celda, apilados en dos o tres líneas; separados, el proyecto y su nota se leen
     en el mismo renglón. Estilos en index.blade.php (.alm-kp-*). --}}
@php
    $rows = $movimientos ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',') ?: '0';
    // Metadata visual única (TIPO_META) definida en el modelo — coherencia con el partial grande.
    $tipoMeta = \App\Models\MovimientoInventario::TIPO_META;
    // NOTA: la etiqueta "(consumo interno)" ya no existe en NINGUNO de los dos kardex —se
    // quitó porque marcaba toda salida a un frente que el almacén sirve, y en uno
    // multi-proyecto eso son todas—, así que aquí tampoco se consulta almacen_frentes.
    //
    // El "tomado de" SÍ va aquí: este modal es el historial de UN producto, y es justo
    // donde se ve que a un proyecto le bajó el saldo sin haber pedido nada. Mismo helper
    // que el kardex grande y el export — una sola consulta por página.
    $nombreBolsa = \App\Models\MovimientoInventario::nombresDeBolsa($rows);
    $prestamos   = \App\Models\MovimientoInventario::prestamosPorMovimiento($rows);
    // Notas vigentes que traen las devoluciones de la página (mismo criterio que kardex_rows).
    $notasVigentes = \App\Models\MovimientoInventario::notasVigentesDeDevoluciones($rows);
@endphp

@if($rows->count() === 0)
    <tr><td colspan="5" style="padding:0;">
        <div class="alm-kp-sinmov">
            <div class="alm-kp-sinmov-ic"><i class="material-icons">receipt_long</i></div>
            <div class="alm-kp-sinmov-tit">Sin movimientos</div>
            <div class="alm-kp-sinmov-sub">Este producto no registra movimientos con los filtros aplicados.</div>
        </div>
    </td></tr>
@else
    @foreach($rows as $m)
        @php
            $meta = $tipoMeta[$m->TIPO] ?? \App\Models\MovimientoInventario::TIPO_META_DEFAULT;
            // Entradas, traspasos recibidos y devoluciones suman (TIPOS_ENTRADA del modelo).
            $entra = $m->esEntrada();
            $signo = $m->TIPO === 'AJUSTE'
                ? (((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) >= 0 ? '+' : '−')
                : ($entra ? '+' : '−');
            $mag = $m->TIPO === 'AJUSTE' ? abs((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) : (float) $m->CANTIDAD;
            // N° de Nota que la fila enlaza al PDF: el suyo, o —en una devolución— el de la nota
            // de la que vuelve el material (en REFERENCIA), mientras esa nota exista.
            $numNota = $m->NUMERO_NOTA
                ?: ($m->TIPO === \App\Models\MovimientoInventario::TIPO_DEVOLUCION && isset($notasVigentes[$m->REFERENCIA]) ? $m->REFERENCIA : null);
        @endphp
        <tr class="alm-kp-fila">
            {{-- Sin la píldora de fondo que llevaba el partial grande ($meta[2]): el cliente
                 la pidió fuera de este modal. El color del tipo ($meta[1]) va en icono y texto. --}}
            <td class="alm-kp-tipo" style="color:{{ $meta[1] }};">
                <i class="material-icons">{{ $meta[3] }}</i>{{ $meta[0] }}
            </td>
            <td class="alm-kp-cant" style="color:{{ $entra || ($m->TIPO==='AJUSTE' && $signo==='+') ? '#16a34a' : '#dc2626' }};">
                {{ $signo }}{{ $fmt($mag) }} <span class="alm-kp-um">{{ $m->producto?->UM }}</span>
            </td>
            <td class="alm-kp-stock" title="Antes: {{ $fmt($m->CANTIDAD_ANTERIOR) }} → Después: {{ $fmt($m->CANTIDAD_RESULTANTE) }}">
                {{ $fmt($m->CANTIDAD_RESULTANTE) }}
            </td>
            {{-- DESTINO: a quién se le entregó (el frente SIEMPRE se muestra: el cliente necesita
                 verlo) o de qué almacén vino, y debajo lo que lo explica: la bolsa de la que se
                 tomó, el proveedor de una entrada, las notas. A diferencia de kardex_rows, aquí NO
                 va la etiqueta "(consumo interno)" — el cliente la pidió fuera de este modal. --}}
            <td class="alm-kp-destino">
                @if($m->frente)
                    <div class="alm-kp-nombre">{{ $m->frente->NOMBRE_FRENTE }}</div>
                    @php $bolsa = $prestamos[$m->ID_MOVIMIENTO] ?? null; @endphp
                    @if($bolsa !== null)
                        @include('admin.almacen.partials.kardex_bolsa')
                    @endif
                @elseif($m->ID_ALMACEN_CONTRAPARTE)
                    <div class="alm-kp-nombre">{{ $m->almacenContraparte?->NOMBRE ?? '—' }}</div>
                @elseif($m->esStockInicial())
                    <div class="alm-kp-sub">Nuevo material</div>
                @endif
                @if($m->TIPO === 'ENTRADA' && $m->MOTIVO)
                    {{-- Proveedor visible — dato clave para una devolución. --}}
                    <div class="alm-kp-sub">Proveedor: {{ $m->MOTIVO }}</div>
                @endif
                @if($m->NOTAS)
                    <div class="alm-kp-sub alm-kp-notas" title="{{ $m->NOTAS }}">
                        <i class="material-icons">sticky_note_2</i><span>{{ $m->NOTAS }}</span>
                    </div>
                @endif
                @if(!$m->frente && !$m->ID_ALMACEN_CONTRAPARTE && !$m->esStockInicial() && !($m->TIPO === 'ENTRADA' && $m->MOTIVO) && !$m->NOTAS)
                    <span class="alm-kp-vacio">—</span>
                @endif
            </td>
            {{-- DOCUMENTO: la Nota de Entrega (abre el PDF) y/o la referencia del movimiento. En
                 una ENTRADA directa REFERENCIA es la nota del proveedor; en el stock inicial, su
                 rótulo. La referencia se OMITE si coincide con la nota (los traspasos traían el
                 mismo NE). --}}
            <td class="alm-kp-doc">
                @if($numNota)
                    {{-- Visor in-page (#pdfPreviewModal) — fallback a pestaña nueva. --}}
                    <a class="alm-kp-nota" href="{{ route('almacen.nota-entrega', ['numero' => $numNota]) }}"
                       onclick="if (typeof window.openPdfPreview === 'function') { event.preventDefault(); window.openPdfPreview(this.href, 'nota_entrega', 'Nota ' + this.textContent.trim(), 0, '', true, 'almacen'); }"
                       target="_blank" rel="noopener" title="Ver Nota de Entrega (PDF)">{{ $numNota }}</a>
                @endif
                @if($m->esStockInicial())
                    <span class="alm-kp-ref">{{ $m->REFERENCIA }}</span>
                @elseif($m->REFERENCIA && $m->REFERENCIA !== $numNota)
                    <span class="alm-kp-ref" title="Nota de entrega / referencia">Ref {{ $m->REFERENCIA }}</span>
                @endif
                @if(!$numNota && !$m->REFERENCIA)
                    <span class="alm-kp-vacio">—</span>
                @endif
            </td>
        </tr>
    @endforeach
@endif
