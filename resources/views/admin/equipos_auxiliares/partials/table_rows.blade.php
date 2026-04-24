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
        $fotoDriveId = $aux->FOTO ? basename(str_replace('/storage/google/', '', $aux->FOTO)) : null;
    @endphp
    <tr>
        {{-- 1. Frente + Foto (centrado, patron igual al de /admin/equipos) --}}
        <td class="table-cell-custom table-cell-center" style="padding: 4px 2px;">
            <div style="font-size: 13px; color: #000; margin-bottom: 5px; line-height: 1.3; font-weight: 600; text-align: center;">
                {{ optional($aux->frente)->NOMBRE_FRENTE ?? 'Sin Asignar' }}
            </div>
            @if($fotoDriveId)
                <div class="table-image-wrapper" style="cursor: default;">
                    <img data-src="https://drive.google.com/thumbnail?id={{ $fotoDriveId }}&sz=w300"
                         src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 60 60'></svg>"
                         alt="Foto" loading="lazy"
                         style="width: 60px; height: 60px; object-fit: cover; border-radius: 8px; border: 1px solid #e2e8f0;">
                </div>
            @else
                <div style="width:60px;height:60px;border-radius:8px;background:#fff7ed;border:1px solid #fed7aa;display:inline-flex;align-items:center;justify-content:center;">
                    <i class="material-icons" style="font-size:28px;color:#f59e0b;">{{ $tipoIcono }}</i>
                </div>
            @endif
        </td>

        {{-- 2. Tipo --}}
        <td>
            <span style="font-weight: 700; color: #1e293b;">{{ $tipoLabel }}</span>
        </td>

        {{-- 3. Marca / Modelo --}}
        <td>{{ $aux->MARCA }} {{ $aux->MODELO }}</td>

        {{-- 4. Serial --}}
        <td><code style="font-size: 12px;">{{ $aux->SERIAL ?: '—' }}</code></td>

        {{-- 5. Capacidad --}}
        <td>{{ $aux->CAPACIDAD ?: '—' }}</td>

        {{-- 6. Estado --}}
        <td>
            <span style="background:{{ $estadoColor['bg'] }};color:{{ $estadoColor['fg'] }};padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;">
                {{ $estadoLabel }}
            </span>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" style="text-align: center; padding: 40px; color: #94a3b8;">
            <i class="material-icons" style="font-size: 36px; display: block; margin: 0 auto 8px auto;">construction</i>
            No hay equipos auxiliares registrados.
        </td>
    </tr>
@endforelse
