{{-- Filas compactas del kardex para el modal "Movimientos del producto"
     (almKardexProductoModal). Igual que kardex_rows.blade.php pero SIN la
     columna Producto — ya estamos viendo movimientos de UN producto — y SIN
     la de Fecha (el cliente la pidió fuera; el rango se sigue filtrando arriba).
     4 columnas: Tipo · Cantidad · Stock · Destino/Ref. --}}
@php
    $rows = $movimientos ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',') ?: '0';
    // Metadata visual única (TIPO_META) definida en el modelo — coherencia con el partial grande.
    $tipoMeta = \App\Models\MovimientoInventario::TIPO_META;
    // NOTA: aquí NO se consulta almacen_frentes. La etiqueta "(consumo interno)" que la
    // necesitaba se quitó de este modal a pedido del cliente; el partial grande
    // (kardex_rows.blade.php) sí la conserva y hace su propia consulta.
@endphp

@if($rows->count() === 0)
    <tr><td colspan="4" style="text-align:center;padding:30px 14px;color:#94a3b8;font-size:13px;">
        <i class="material-icons" style="font-size:34px;color:#cbd5e0;display:block;margin:0 auto 6px;">receipt_long</i>
        Este producto no tiene movimientos con esos filtros.
    </td></tr>
@else
    @foreach($rows as $m)
        @php
            $meta = $tipoMeta[$m->TIPO] ?? \App\Models\MovimientoInventario::TIPO_META_DEFAULT;
            $entra = in_array($m->TIPO, ['ENTRADA', 'TRASPASO_ENTRADA'], true);
            $signo = $m->TIPO === 'AJUSTE'
                ? (((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) >= 0 ? '+' : '−')
                : ($entra ? '+' : '−');
            $mag = $m->TIPO === 'AJUSTE' ? abs((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) : (float) $m->CANTIDAD;
        @endphp
        <tr>
            {{-- Sin la píldora de fondo que llevaba el partial grande ($meta[2]): el cliente
                 la pidió fuera de este modal, y sin ella la columna necesita menos ancho.
                 El color del tipo ($meta[1]) se mantiene en el icono y el texto. --}}
            <td style="padding:7px 8px;text-align:center;white-space:nowrap;">
                <span style="display:inline-flex;align-items:center;gap:3px;color:{{ $meta[1] }};font-weight:700;font-size:10.5px;">
                    <i class="material-icons" style="font-size:12px;">{{ $meta[3] }}</i>{{ $meta[0] }}
                </span>
            </td>
            <td style="padding:7px 8px;text-align:center;font-weight:800;color:{{ $entra || ($m->TIPO==='AJUSTE' && $signo==='+') ? '#16a34a' : '#dc2626' }};white-space:nowrap;font-size:12.5px;">
                {{ $signo }}{{ $fmt($mag) }} <span style="color:#64748b;font-weight:600;font-size:9.5px;">{{ $m->producto?->UM }}</span>
            </td>
            <td title="Antes: {{ $fmt($m->CANTIDAD_ANTERIOR) }} → Después: {{ $fmt($m->CANTIDAD_RESULTANTE) }}"
                style="padding:7px 8px;text-align:center;font-weight:700;white-space:nowrap;font-size:12.5px;">
                {{ $fmt($m->CANTIDAD_RESULTANTE) }}
            </td>
            {{-- overflow-wrap:anywhere — es la única columna con texto libre (frente,
                 proveedor, notas): garantiza que nada la ensanche más allá de su
                 porcentaje y se salga del modal. --}}
            <td style="padding:7px 8px;font-size:12px;color:#475569;overflow-wrap:anywhere;">
                {{-- El nombre del frente SIEMPRE se muestra: el cliente necesita ver a quién
                     se le entregó cada cosa. A diferencia de kardex_rows.blade.php, aquí NO
                     va la etiqueta "(consumo interno)" — el cliente la pidió fuera de este
                     modal. --}}
                @if($m->frente)
                    <div style="font-size:11px;font-weight:600;color:#0f172a;">{{ $m->frente->NOMBRE_FRENTE }}</div>
                @elseif($m->ID_ALMACEN_CONTRAPARTE)
                    <div style="font-size:11px;font-weight:600;color:#0f172a;">{{ $m->almacenContraparte?->NOMBRE ?? '—' }}</div>
                @endif
                @if($m->NUMERO_NOTA)
                    <div style="font-size:10.5px;margin-top:2px;">
                        {{-- Visor in-page (#pdfPreviewModal) — fallback a pestaña nueva. --}}
                        <a href="{{ route('almacen.nota-entrega', ['numero' => $m->NUMERO_NOTA]) }}"
                           onclick="if (typeof window.openPdfPreview === 'function') { event.preventDefault(); window.openPdfPreview(this.href, 'nota_entrega', 'Nota ' + this.textContent.trim(), 0, '', true, 'almacen'); }"
                           target="_blank" rel="noopener"
                           style="color:#0067b1;text-decoration:none;font-weight:700;font-family:monospace;"
                           title="Ver Nota de Entrega (PDF)">{{ $m->NUMERO_NOTA }}</a>
                    </div>
                @endif
                @if($m->REFERENCIA && $m->REFERENCIA !== $m->NUMERO_NOTA)
                    {{-- En ENTRADA directa REFERENCIA es la Nota de entrega del proveedor:
                         en negrita igual que la Nota de Entrega (NUMERO_NOTA) de las SALIDAS.
                         Se OMITE si coincide con NUMERO_NOTA (traspasos traían el mismo NE). --}}
                    <div style="font-size:10.5px;color:#334155;font-weight:700;" title="Nota de entrega / referencia">Ref: {{ $m->REFERENCIA }}</div>
                @endif
                @if($m->TIPO === 'ENTRADA' && $m->MOTIVO)
                    {{-- Proveedor visible — dato clave para una devolución. --}}
                    <div style="font-size:10.5px;color:#64748b;" title="Proveedor">
                        <span>{{ $m->MOTIVO }}</span>
                    </div>
                @endif
                @if($m->NOTAS)
                    <div style="font-size:10.5px;color:#94a3b8;display:flex;align-items:center;gap:3px;" title="{{ $m->NOTAS }}">
                        <i class="material-icons" style="font-size:12px;">sticky_note_2</i><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:120px;">{{ $m->NOTAS }}</span>
                    </div>
                @endif
                @if(!$m->frente && !$m->ID_ALMACEN_CONTRAPARTE && !$m->NUMERO_NOTA && !$m->REFERENCIA && !($m->TIPO === 'ENTRADA' && $m->MOTIVO) && !$m->NOTAS)
                    —
                @endif
            </td>
        </tr>
    @endforeach
@endif
