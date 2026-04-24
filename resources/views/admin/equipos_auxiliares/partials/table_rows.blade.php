@forelse($auxiliares as $aux)
    @php
        $tipoLabel = \App\Models\EquipoAuxiliar::tiposLabel()[$aux->TIPO] ?? $aux->TIPO;
        $tipoIcono = \App\Models\EquipoAuxiliar::tiposIcono()[$aux->TIPO] ?? 'build';
        $estadoLabel = \App\Models\EquipoAuxiliar::estadosLabel()[$aux->ESTADO_OPERATIVO] ?? $aux->ESTADO_OPERATIVO;
        $estadoColor = match($aux->ESTADO_OPERATIVO) {
            'OPERATIVO' => ['bg' => '#dcfce7', 'fg' => '#166534'],
            'INOPERATIVO' => ['bg' => '#fee2e2', 'fg' => '#991b1b'],
            'EN_ALMACEN' => ['bg' => '#dbeafe', 'fg' => '#1e40af'],
            'DESINCORPORADO' => ['bg' => '#e2e8f0', 'fg' => '#475569'],
            default => ['bg' => '#f1f5f9', 'fg' => '#475569'],
        };
    @endphp
    <tr>
        <td>
            <div style="display:flex;align-items:center;gap:8px;">
                <div style="background:#fff7ed;padding:6px;border-radius:6px;display:flex;">
                    <i class="material-icons" style="font-size:18px;color:#f59e0b;">{{ $tipoIcono }}</i>
                </div>
                <span style="font-weight:700;color:#1e293b;">{{ $tipoLabel }}</span>
            </div>
        </td>
        <td>{{ $aux->MARCA }} {{ $aux->MODELO }}</td>
        <td><code style="font-size:12px;">{{ $aux->SERIAL ?: '—' }}</code></td>
        <td>{{ $aux->CAPACIDAD ?: '—' }}</td>
        <td>{{ optional($aux->frente)->NOMBRE_FRENTE ?: '—' }}</td>
        <td>
            <span style="background:{{ $estadoColor['bg'] }};color:{{ $estadoColor['fg'] }};padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;">
                {{ $estadoLabel }}
            </span>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" style="text-align:center;padding:40px;color:#94a3b8;">
            <i class="material-icons" style="font-size:36px;display:block;margin-bottom:8px;">construction</i>
            No hay equipos auxiliares registrados.
        </td>
    </tr>
@endforelse
