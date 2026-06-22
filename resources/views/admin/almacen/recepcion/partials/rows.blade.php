{{-- Filas de la tabla de notas de entrega / traspasos. $traspasos = paginator de
     Traspaso con almacenes y lineas.producto (CODIGO/NOMBRE) cargados via eager loading. --}}

@forelse($traspasos as $t)
    @php
        $e = \App\Models\Traspaso::ESTADOS_META[$t->ESTADO] ?? \App\Models\Traspaso::ESTADO_META_DEFAULT;
        $neNumero = $t->REFERENCIA ?: $t->NUMERO;
        $esNE = (bool) $t->REFERENCIA;
        // Antigüedad desde el envío para indicador visual
        $horasDesdeEnvio = $t->FECHA_ENVIO ? now()->diffInHours($t->FECHA_ENVIO) : null;
    @endphp
    <tr data-id="{{ $t->ID_TRASPASO }}">
        {{-- Nº principal: NE si viene de nota de entrega, TR si es traspaso directo --}}
        <td style="font-family:monospace;font-weight:700;color:#0f172a;white-space:nowrap;">
            <div>{{ $neNumero }}</div>
            @if($esNE)
                <div style="font-size:10.5px;font-weight:600;color:#94a3b8;font-family:monospace;">{{ $t->NUMERO }}</div>
            @endif
        </td>
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
            @if($t->FECHA_ENVIO)
                {{ $t->FECHA_ENVIO->format('d-M-Y H:i') }}
                @if($t->esEnviado() && $horasDesdeEnvio !== null)
                    <div style="margin-top:2px;">
                        @if($horasDesdeEnvio < 24)
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#22c55e;vertical-align:middle;" title="Hace menos de 24h"></span>
                        @elseif($horasDesdeEnvio < 72)
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#f59e0b;vertical-align:middle;" title="Hace {{ intdiv($horasDesdeEnvio, 24) }} día(s)"></span>
                        @else
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444;vertical-align:middle;" title="Hace {{ intdiv($horasDesdeEnvio, 24) }} días"></span>
                        @endif
                        <span style="font-size:10.5px;color:#94a3b8;">{{ $t->FECHA_ENVIO->diffForHumans() }}</span>
                    </div>
                @endif
            @else
                —
            @endif
        </td>
        <td style="font-size:12.5px;color:#475569;white-space:nowrap;">
            {{ $t->FECHA_RECEPCION ? $t->FECHA_RECEPCION->format('d-M-Y H:i') : '—' }}
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" style="text-align:center;padding:48px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">inbox</i>
            No hay notas de entrega que coincidan con tu vista actual.
        </td>
    </tr>
@endforelse
