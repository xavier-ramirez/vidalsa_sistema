{{-- Filas de la tabla de notas de entrega / traspasos. $traspasos = paginator de
     Traspaso con almacenes cargados via eager loading. El detalle de materiales NO
     se lista aquí: se revisa al abrir la nota (modal de detalle/recepción). --}}

@forelse($traspasos as $t)
    @php
        $e = \App\Models\Traspaso::ESTADOS_META[$t->ESTADO] ?? \App\Models\Traspaso::ESTADO_META_DEFAULT;
        $neNumero = $t->REFERENCIA ?: $t->NUMERO;
        // Antigüedad desde el envío para indicador visual
        $horasDesdeEnvio = $t->FECHA_ENVIO ? now()->diffInHours($t->FECHA_ENVIO) : null;
    @endphp
    <tr data-id="{{ $t->ID_TRASPASO }}">
        <td style="font-family:monospace;font-weight:800;font-size:13px;color:#0f172a;white-space:nowrap;letter-spacing:.3px;">
            {{ $neNumero }}
        </td>
        <td style="font-size:12px;">
            <div style="font-weight:700;color:#1e293b;">{{ optional($t->almacenOrigen)->NOMBRE ?: '—' }}@if(optional($t->almacenOrigen)->TIPO !== 'GENERAL') <span class="alm-tipo-p">P</span>@endif</div>
            <div class="tr-ruta-dest" style="color:#64748b;display:flex;align-items:center;justify-content:center;gap:3px;margin-top:1px;">
                <i class="material-icons" style="font-size:12px;color:#94a3b8;">south</i>
                <span style="font-weight:600;">{{ optional($t->almacenDestino)->NOMBRE ?: '—' }}@if(optional($t->almacenDestino)->TIPO !== 'GENERAL') <span class="alm-tipo-p">P</span>@endif</span>
            </div>
        </td>
        <td style="text-align:center;">
            <span class="estado-pill" style="background:{{ $e[1] }};color:{{ $e[2] }};">{{ $e[0] }}</span>
        </td>
        <td style="font-size:12px;color:#475569;white-space:nowrap;">
            @if($t->FECHA_ENVIO)
                {{ $t->FECHA_ENVIO->format('d/m/Y h:i A') }}
                @if($t->esEnviado() && $horasDesdeEnvio !== null)
                    <div style="display:flex;align-items:center;justify-content:center;gap:4px;margin-top:2px;">
                        @if($horasDesdeEnvio < 24)
                            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#22c55e;" title="Hace menos de 24h"></span>
                        @elseif($horasDesdeEnvio < 72)
                            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;" title="Hace {{ intdiv($horasDesdeEnvio, 24) }} día(s)"></span>
                        @else
                            <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#ef4444;" title="Hace {{ intdiv($horasDesdeEnvio, 24) }} días"></span>
                        @endif
                        <span style="font-size:10.5px;color:#94a3b8;">{{ $t->FECHA_ENVIO->locale('es')->diffForHumans() }}</span>
                    </div>
                @endif
            @else
                —
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="4" style="text-align:center;padding:48px 16px;color:#94a3b8;font-size:14px;">
            <i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">inbox</i>
            No hay notas de entrega que coincidan con tu vista actual.
        </td>
    </tr>
@endforelse
