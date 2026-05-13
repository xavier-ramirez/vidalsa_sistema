{{-- Filas de la tabla de traspasos. $traspasos = paginator de Traspaso con almacenes y usuarioCreo cargados, withCount('lineas'). --}}
@php
    $estilos = [
        'BORRADOR'         => ['Borrador', '#f1f5f9', '#64748b'],
        'ENVIADO'          => ['Enviado',  '#fef3c7', '#b45309'],
        'RECIBIDO'         => ['Recibido', '#dcfce7', '#15803d'],
        'RECIBIDO_PARCIAL' => ['Parcial',  '#fee2e2', '#b91c1c'],
        'CANCELADO'        => ['Cancelado','#e2e8f0', '#475569'],
    ];
@endphp

@forelse($traspasos as $t)
    @php
        $e = $estilos[$t->ESTADO] ?? ['Desconocido', '#f1f5f9', '#64748b'];
    @endphp
    <tr data-id="{{ $t->ID_TRASPASO }}">
        <td style="font-family:monospace;font-weight:700;color:#0f172a;white-space:nowrap;">{{ $t->NUMERO }}</td>
        <td style="font-size:12.5px;">
            <div style="font-weight:700;color:#1e293b;">{{ optional($t->almacenOrigen)->NOMBRE ?: '—' }}</div>
            <div style="color:#64748b;display:flex;align-items:center;gap:4px;margin-top:2px;">
                <i class="material-icons" style="font-size:13px;">arrow_downward</i>
                {{ optional($t->almacenDestino)->NOMBRE ?: '—' }}
            </div>
        </td>
        <td style="text-align:center;">
            <span class="estado-pill" style="background:{{ $e[1] }};color:{{ $e[2] }};">{{ $e[0] }}</span>
        </td>
        <td style="text-align:right;font-weight:700;color:#334155;">{{ $t->lineas_count ?? '—' }}</td>
        <td style="font-size:12.5px;color:#475569;white-space:nowrap;">
            {{ $t->FECHA_ENVIO ? $t->FECHA_ENVIO->format('d-M-Y H:i') : '—' }}
        </td>
        <td style="font-size:12.5px;color:#475569;white-space:nowrap;">
            {{ $t->FECHA_RECEPCION ? $t->FECHA_RECEPCION->format('d-M-Y H:i') : '—' }}
        </td>
        <td style="font-size:12.5px;color:#64748b;">{{ optional($t->usuarioCreo)->NOMBRE_COMPLETO ?: '—' }}</td>
    </tr>
@empty
    <tr>
        <td colspan="7" style="text-align:center;padding:48px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">inbox</i>
            No hay traspasos que coincidan con tu vista actual.
        </td>
    </tr>
@endforelse
