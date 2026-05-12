{{-- Filas del kardex (modal "Movimientos"). $movimientos = paginator de MovimientoInventario --}}
@php
    $rows = $movimientos ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    $tipoMeta = [
        'ENTRADA'          => ['Entrada',  '#16a34a', '#dcfce7', 'add'],
        'TRASPASO_ENTRADA' => ['Traspaso (entra)', '#0891b2', '#cffafe', 'south_west'],
        'SALIDA'           => ['Salida',   '#dc2626', '#fee2e2', 'remove'],
        'TRASPASO_SALIDA'  => ['Traspaso (sale)',  '#ea580c', '#ffedd5', 'north_east'],
        'AJUSTE'           => ['Ajuste',   '#7c3aed', '#ede9fe', 'tune'],
    ];
@endphp

@if($rows->count() === 0)
    <tr><td colspan="8" style="text-align:center;padding:36px 16px;color:#94a3b8;font-size:14px;">
        <i class="material-icons" style="font-size:40px;color:#cbd5e0;display:block;margin:0 auto 8px;">receipt_long</i>
        No hay movimientos que coincidan con los filtros.
    </td></tr>
@else
    @foreach($rows as $m)
        @php
            $meta = $tipoMeta[$m->TIPO] ?? [$m->TIPO, '#475569', '#f1f5f9', 'swap_vert'];
            $entra = in_array($m->TIPO, ['ENTRADA', 'TRASPASO_ENTRADA'], true);
            $signo = $m->TIPO === 'AJUSTE'
                ? (((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) >= 0 ? '+' : '−')
                : ($entra ? '+' : '−');
            $mag = $m->TIPO === 'AJUSTE' ? abs((float) $m->CANTIDAD_RESULTANTE - (float) $m->CANTIDAD_ANTERIOR) : (float) $m->CANTIDAD;
        @endphp
        <tr style="border-bottom:1px solid #f1f5f9;">
            <td style="white-space:nowrap;color:#475569;font-size:12.5px;">{{ optional($m->FECHA)->format('d/m/Y') }}</td>
            <td style="white-space:nowrap;">
                <span style="display:inline-flex;align-items:center;gap:4px;background:{{ $meta[2] }};color:{{ $meta[1] }};font-weight:700;font-size:11px;padding:2px 8px;border-radius:999px;">
                    <i class="material-icons" style="font-size:13px;">{{ $meta[3] }}</i>{{ $meta[0] }}
                </span>
            </td>
            <td><span style="font-family:monospace;font-weight:700;color:#0f172a;">{{ $m->producto?->CODIGO }}</span> <span style="color:#475569;">{{ $m->producto?->NOMBRE }}</span></td>
            <td style="text-align:right;font-weight:800;color:{{ $entra || ($m->TIPO==='AJUSTE' && $signo==='+') ? '#16a34a' : '#dc2626' }};white-space:nowrap;">{{ $signo }}{{ $fmt($mag) }} <span style="color:#94a3b8;font-weight:600;">{{ $m->producto?->UM }}</span></td>
            <td style="text-align:right;color:#64748b;font-size:12.5px;white-space:nowrap;">{{ $fmt($m->CANTIDAD_ANTERIOR) }} → <strong style="color:#1e293b;">{{ $fmt($m->CANTIDAD_RESULTANTE) }}</strong></td>
            <td style="color:#475569;font-size:12.5px;">
                @if($m->ID_ALMACEN_CONTRAPARTE){{ $m->almacenContraparte?->NOMBRE ?? '—' }}@elseif($m->frente){{ $m->frente->NOMBRE_FRENTE }}@else—@endif
            </td>
            <td style="color:#64748b;font-size:12px;">{{ trim(($m->REFERENCIA ? '#'.$m->REFERENCIA.' ' : '').($m->MOTIVO ?? '')) ?: '—' }}</td>
            <td style="color:#94a3b8;font-size:12px;white-space:nowrap;">{{ $m->usuario?->NOMBRE_COMPLETO ?? '—' }}</td>
        </tr>
    @endforeach
@endif
