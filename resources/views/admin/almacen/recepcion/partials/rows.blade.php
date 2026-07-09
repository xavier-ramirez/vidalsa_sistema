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
        {{-- Trayecto Origen → Destino lado a lado, mismo diseño que el historial de
             movilizaciones de equipos (admin/movilizaciones/partials/table_rows): dos
             columnas (label arriba + nombre abajo) con una flecha `east` en medio.
             Origen en gris, Destino en azul. --}}
        <td class="tr-ruta-dest">
            <div style="display:flex;align-items:center;justify-content:center;gap:12px;word-break:break-word;overflow-wrap:break-word;">
                <div style="display:flex;flex-direction:column;align-items:center;max-width:160px;text-align:center;">
                    <span style="font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;letter-spacing:.3px;">Origen</span>
                    <span style="font-weight:600;color:#4a5568;font-size:13px;line-height:1.2;">{{ optional($t->almacenOrigen)->NOMBRE ?: '—' }}</span>
                </div>
                <i class="material-icons" style="font-size:18px;color:#cbd5e0;flex-shrink:0;">east</i>
                <div style="display:flex;flex-direction:column;align-items:center;max-width:160px;text-align:center;">
                    <span style="font-size:11px;color:#0067b1;font-weight:800;text-transform:uppercase;letter-spacing:.3px;">Destino</span>
                    <span style="font-weight:700;color:var(--maquinaria-dark-blue,#1e3a5f);font-size:13px;line-height:1.2;">{{ optional($t->almacenDestino)->NOMBRE ?: '—' }}</span>
                </div>
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
