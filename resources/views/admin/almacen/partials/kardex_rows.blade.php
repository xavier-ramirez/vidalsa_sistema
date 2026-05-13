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
        <tr>
            <td style="white-space:nowrap;">{{ optional($m->FECHA)->format('d/m/Y') }}</td>
            <td style="white-space:nowrap;">
                {{-- La pill mantiene su color de fondo y texto propios (visualmente distingue ENTRADA / SALIDA / etc.). --}}
                <span style="display:inline-flex;align-items:center;gap:4px;background:{{ $meta[2] }};color:{{ $meta[1] }};font-weight:700;font-size:11px;padding:2px 8px;border-radius:999px;">
                    <i class="material-icons" style="font-size:13px;">{{ $meta[3] }}</i>{{ $meta[0] }}
                </span>
            </td>
            {{-- Producto: solo el NOMBRE (sin CODIGO al inicio que se veía repetido cuando los nombres
                 importados ya incluían el código como prefijo). El código queda como tooltip por si lo necesitan. --}}
            <td title="{{ $m->producto?->CODIGO ?? '' }}" style="font-weight:600;">{{ $m->producto?->NOMBRE ?? '—' }}</td>
            <td style="text-align:right;font-weight:800;color:{{ $entra || ($m->TIPO==='AJUSTE' && $signo==='+') ? '#16a34a' : '#dc2626' }};white-space:nowrap;">{{ $signo }}{{ $fmt($mag) }} <span style="color:#64748b;font-weight:600;">{{ $m->producto?->UM }}</span></td>
            <td style="text-align:right;white-space:nowrap;">{{ $fmt($m->CANTIDAD_ANTERIOR) }} → <strong>{{ $fmt($m->CANTIDAD_RESULTANTE) }}</strong></td>
            <td>
                {{-- Mostrar el FRENTE primero (es lo que el operario eligió como destino real);
                     si no hay frente (traspasos legacy o movimientos sin frente), caer al almacén contraparte. --}}
                @if($m->frente)
                    {{ $m->frente->NOMBRE_FRENTE }}
                @elseif($m->ID_ALMACEN_CONTRAPARTE)
                    {{ $m->almacenContraparte?->NOMBRE ?? '—' }}
                @else
                    —
                @endif
            </td>
            {{-- Ref: solo el número de referencia/documento (antes mezclaba REFERENCIA + MOTIVO). El MOTIVO
                 sigue grabado en BD; si se necesita verlo, se agrega columna o tooltip aparte. --}}
            <td title="{{ $m->MOTIVO ?? '' }}">{{ $m->REFERENCIA ?: '—' }}</td>
            <td style="white-space:nowrap;">{{ $m->usuario?->NOMBRE_COMPLETO ?? '—' }}</td>
        </tr>
    @endforeach
@endif
