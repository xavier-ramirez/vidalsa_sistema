{{-- Filas compactas del kardex para el modal "Movimientos del producto"
     (almKardexProductoModal). Igual que kardex_rows.blade.php pero SIN la
     columna Producto — ya estamos viendo movimientos de UN producto.
     5 columnas: Fecha · Tipo · Cantidad · Stock · Destino/Ref. --}}
@php
    $rows = $movimientos ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, ',', '.'), '0'), ',') ?: '0';
    // Metadata visual única (TIPO_META) definida en el modelo — coherencia con el partial grande.
    $tipoMeta = \App\Models\MovimientoInventario::TIPO_META;
@endphp

@if($rows->count() === 0)
    <tr><td colspan="5" style="text-align:center;padding:30px 14px;color:#94a3b8;font-size:13px;">
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
            <td style="padding:7px 8px;text-align:center;white-space:nowrap;font-size:12px;">{{ optional($m->FECHA)->format('d/m/Y') }} <span style="color:#94a3b8;font-size:11px;">{{ optional($m->created_at)->format('h:i A') }}</span></td>
            <td style="padding:7px 8px;text-align:center;white-space:nowrap;">
                <span style="display:inline-flex;align-items:center;gap:3px;background:{{ $meta[2] }};color:{{ $meta[1] }};font-weight:700;font-size:10.5px;padding:2px 7px;border-radius:999px;">
                    <i class="material-icons" style="font-size:12px;">{{ $meta[3] }}</i>{{ $meta[0] }}
                </span>
            </td>
            <td style="padding:7px 8px;text-align:center;font-weight:800;color:{{ $entra || ($m->TIPO==='AJUSTE' && $signo==='+') ? '#16a34a' : '#dc2626' }};white-space:nowrap;font-size:12.5px;">
                {{ $signo }}{{ $fmt($mag) }} <span style="color:#64748b;font-weight:600;font-size:11px;">{{ $m->producto?->UM }}</span>
            </td>
            <td title="Antes: {{ $fmt($m->CANTIDAD_ANTERIOR) }} → Después: {{ $fmt($m->CANTIDAD_RESULTANTE) }}"
                style="padding:7px 8px;text-align:center;font-weight:700;white-space:nowrap;font-size:12.5px;">
                {{ $fmt($m->CANTIDAD_RESULTANTE) }}
            </td>
            <td style="padding:7px 8px;font-size:12px;color:#475569;">
                {{-- Mismo criterio que kardex_rows.blade.php (Destino): el nombre del frente
                     SIEMPRE se muestra (el cliente necesita ver a quién se le entregó cada
                     cosa). Si es SALIDA pura (sin contraparte) se agrega la etiqueta chica
                     "(consumo interno)" debajo, sin ocultar el frente. --}}
                @if($m->frente)
                    <div style="font-weight:600;color:#0f172a;">{{ $m->frente->NOMBRE_FRENTE }}</div>
                    @if($m->TIPO === 'SALIDA' && !$m->ID_ALMACEN_CONTRAPARTE)
                        <div style="font-size:10px;color:#94a3b8;font-style:italic;" title="El material no salió de este almacén — fue consumido por el frente, no hubo traspaso">(consumo interno)</div>
                    @endif
                @elseif($m->ID_ALMACEN_CONTRAPARTE)
                    <div style="font-weight:600;color:#0f172a;">{{ $m->almacenContraparte?->NOMBRE ?? '—' }}</div>
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
