{{-- Filas de alertas de stock bajo. $alertas = colección de AlmacenStock (con producto+almacen) --}}
@php
    $rows = $alertas ?? collect();
    $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
@endphp

@if($rows->count() === 0)
    <tr><td colspan="7" style="text-align:center;padding:36px 16px;color:#16a34a;font-size:14px;">
        <i class="material-icons" style="font-size:40px;color:#86efac;display:block;margin:0 auto 8px;">check_circle</i>
        No hay productos por debajo de su stock mínimo. Todo en orden.
    </td></tr>
@else
    @foreach($rows as $s)
        @php
            $falta = max(0, (float) $s->CANTIDAD_MINIMA - (float) $s->CANTIDAD);
            $agotado = (float) $s->CANTIDAD <= 0;
        @endphp
        <tr style="border-bottom:1px solid #f1f5f9;{{ $agotado ? 'background:#fef2f2;' : 'background:#fff7ed;' }}">
            <td style="font-weight:600;color:#1e293b;font-size:12.5px;">
                {{ $s->almacen?->NOMBRE ?? '—' }}
                <span style="font-size:10.5px;color:#94a3b8;">{{ $s->almacen?->TIPO === 'GENERAL' ? '(Principal)' : '(Proyecto)' }}</span>
            </td>
            <td><span style="font-family:monospace;font-weight:700;color:#0f172a;">{{ $s->producto?->CODIGO }}</span></td>
            <td style="color:#475569;">{{ $s->producto?->NOMBRE }}</td>
            <td style="color:#475569;font-size:12px;">{{ $s->producto?->CATEGORIA ?: '—' }}</td>
            <td style="text-align:right;font-weight:800;color:{{ $agotado ? '#dc2626' : '#ea580c' }};white-space:nowrap;">
                {{ $fmt($s->CANTIDAD) }} <span style="color:#94a3b8;font-weight:600;">{{ $s->producto?->UM }}</span>
            </td>
            <td style="text-align:right;color:#64748b;font-size:12.5px;white-space:nowrap;">mín. {{ $fmt($s->CANTIDAD_MINIMA) }}</td>
            <td style="text-align:right;font-weight:700;color:#dc2626;white-space:nowrap;">
                @if($agotado)<span style="background:#fee2e2;color:#b91c1c;font-size:11px;padding:2px 8px;border-radius:999px;">AGOTADO</span>
                @else falta {{ $fmt($falta) }} @endif
            </td>
        </tr>
    @endforeach
@endif
