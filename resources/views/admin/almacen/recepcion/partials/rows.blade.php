{{-- Filas de la tabla de traspasos. $traspasos = paginator de Traspaso con
     almacenes y lineas.producto (CODIGO/NOMBRE) cargados via eager loading. --}}

@forelse($traspasos as $t)
    @php
        // Metadata visual del estado — single source of truth en Traspaso::ESTADOS_META.
        $e = \App\Models\Traspaso::ESTADOS_META[$t->ESTADO] ?? \App\Models\Traspaso::ESTADO_META_DEFAULT;
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
        {{-- Líneas: lista CODIGO + descripción de cada producto del traspaso. --}}
        <td class="tr-lineas-cell">
            <div class="tr-lineas-box">
                @forelse($t->lineas as $linea)
                    <div class="tr-linea-item">
                        <span class="tr-linea-cod">{{ optional($linea->producto)->CODIGO ?: '—' }}</span>
                        <span class="tr-linea-desc">{{ optional($linea->producto)->NOMBRE ?: 'Producto no disponible' }}</span>
                    </div>
                @empty
                    <span style="color:#94a3b8;">—</span>
                @endforelse
            </div>
        </td>
        <td style="font-size:12.5px;color:#475569;white-space:nowrap;">
            {{ $t->FECHA_ENVIO ? $t->FECHA_ENVIO->format('d-M-Y H:i') : '—' }}
        </td>
        <td style="font-size:12.5px;color:#475569;white-space:nowrap;">
            {{ $t->FECHA_RECEPCION ? $t->FECHA_RECEPCION->format('d-M-Y H:i') : '—' }}
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" style="text-align:center;padding:48px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">inbox</i>
            No hay traspasos que coincidan con tu vista actual.
        </td>
    </tr>
@endforelse
