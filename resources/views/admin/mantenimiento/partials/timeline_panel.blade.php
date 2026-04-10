{{-- Timeline Panel - Equipment state history --}}
<div>
    <!-- Equipment Header -->
    <div class="mant-card" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
        <div style="width:50px; height:50px; border-radius:14px; display:flex; align-items:center; justify-content:center;
            background:{{ $equipo->ESTADO_OPERATIVO === 'INOPERATIVO' ? 'linear-gradient(135deg,#ef4444,#dc2626)' : 'linear-gradient(135deg,#22c55e,#16a34a)' }};">
            <i class="material-icons" style="color:white; font-size:24px;">{{ $equipo->ESTADO_OPERATIVO === 'INOPERATIVO' ? 'warning' : 'check_circle' }}</i>
        </div>
        <div style="flex:1;">
            <div style="font-size:18px; font-weight:800; color:#1e293b;">{{ $equipo->MARCA }} {{ $equipo->MODELO }} ({{ $equipo->ANIO }})</div>
            <div style="font-size:13px; color:#64748b;">
                {{ $equipo->tipo->nombre ?? '' }} |
                {{ $equipo->SERIAL_CHASIS ?? $equipo->CODIGO_PATIO ?? '' }} |
                {{ $equipo->frenteActual->NOMBRE_FRENTE ?? 'Sin Frente' }}
            </div>
        </div>
        <div style="text-align:right;">
            <span class="badge-estado {{ $equipo->ESTADO_OPERATIVO === 'INOPERATIVO' ? 'abierta' : 'resuelta' }}" style="font-size:13px; padding:6px 14px;">
                {{ $equipo->ESTADO_OPERATIVO }}
            </span>
            @if($diasInoperativo > 0)
                <div style="font-size:12px; font-weight:700; color:#dc2626; margin-top:4px;">
                    {{ $diasInoperativo }} día{{ $diasInoperativo > 1 ? 's' : '' }} inoperativo
                </div>
            @endif
        </div>
    </div>

    @if($historial->count() > 0)
    <!-- Timeline -->
    <div class="mant-card" style="margin-top:16px;">
        <div class="mant-card-header">
            <span class="mant-card-title"><i class="material-icons">timeline</i> Historial de Estados</span>
        </div>
        <div style="position:relative; padding-left:28px;">
            <!-- Vertical line -->
            <div style="position:absolute; left:11px; top:0; bottom:0; width:2px; background:#e2e8f0;"></div>

            @foreach($historial as $h)
            @php
                $isInop = $h->ESTADO_NUEVO === 'INOPERATIVO';
                $dotColor = $isInop ? '#ef4444' : '#22c55e';
            @endphp
            <div style="position:relative; padding-bottom:20px;">
                <!-- Dot -->
                <div style="position:absolute; left:-22px; top:2px; width:12px; height:12px; border-radius:50%; background:{{ $dotColor }}; border:2px solid white; box-shadow:0 0 0 2px {{ $dotColor }}33;"></div>

                <div style="padding:10px 14px; background:#fafbfc; border-radius:10px; border:1px solid #f1f5f9;">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="badge-estado {{ $isInop ? 'abierta' : 'resuelta' }}" style="font-size:10px;">
                                {{ $h->ESTADO_ANTERIOR }} → {{ $h->ESTADO_NUEVO }}
                            </span>
                        </div>
                        <span style="font-size:11px; color:#94a3b8; font-weight:600;">
                            {{ $h->created_at->format('d/m/Y H:i') }}
                        </span>
                    </div>
                    @if($h->MOTIVO)
                        <div style="font-size:12px; color:#475569; margin-top:4px;">{{ $h->MOTIVO }}</div>
                    @endif
                    @if($h->usuario)
                        <div style="font-size:11px; color:#94a3b8; margin-top:2px;">
                            <i class="material-icons" style="font-size:12px; vertical-align:middle;">person</i>
                            {{ $h->usuario->NOMBRE_COMPLETO ?? '' }}
                        </div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @else
    <div class="mant-card" style="margin-top:16px;">
        <div class="mant-empty">
            <i class="material-icons">history</i>
            <p>No hay historial de cambios de estado registrado</p>
        </div>
    </div>
    @endif

    @if($fallas->count() > 0)
    <!-- Recent Faults -->
    <div class="mant-card" style="margin-top:16px;">
        <div class="mant-card-header">
            <span class="mant-card-title"><i class="material-icons">error_outline</i> Fallas Recientes (30 días)</span>
        </div>
        <table class="mant-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Frente</th>
                    <th style="text-align:center;">Foto</th>
                    <th>Tipo</th>
                    <th>Descripción</th>
                    <th>Prioridad</th>
                    <th>Estado</th>
                </tr>
            </thead>
            <tbody>
                @foreach($fallas as $f)
                <tr>
                    <td style="font-weight:700; font-size:12px; white-space:nowrap;">{{ $f->HORA_REGISTRO->format('d/m H:i') }}</td>
                    <td style="font-size:12px;">{{ $f->reporte->frente->NOMBRE_FRENTE ?? '' }}</td>
                    <td style="text-align:center; width:80px;">
                        @php
                            $fotoTl = ($f->equipo->especificaciones && $f->equipo->especificaciones->FOTO_REFERENCIAL)
                                ? $f->equipo->especificaciones->FOTO_REFERENCIAL
                                : $f->equipo->FOTO_EQUIPO;
                        @endphp
                        @if($fotoTl)
                            <div class="table-image-wrapper" style="width:70px; height:45px; margin:0 auto;">
                                <img src="{{ route('drive.file', ['path' => str_replace('/storage/google/', '', $fotoTl)]) }}"
                                    alt="Equipo" loading="lazy" onload="this.style.opacity='1'" style="opacity:0;">
                            </div>
                        @else
                            <div class="table-image-wrapper placeholder" style="width:70px; height:45px; margin:0 auto;">
                                <span class="material-icons" style="font-size:18px;">image_not_supported</span>
                            </div>
                        @endif
                    </td>
                    <td style="font-size:12px;">{{ $f->TIPO_FALLA }}</td>
                    <td style="font-size:12px; max-width:200px;">
                        <div style="overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical;">{{ $f->DESCRIPCION_FALLA }}</div>
                    </td>
                    <td><span class="badge-prioridad {{ strtolower($f->PRIORIDAD) }}">{{ $f->PRIORIDAD }}</span></td>
                    <td><span class="badge-estado {{ strtolower(str_replace(' ', '_', $f->ESTADO_FALLA)) }}">{{ str_replace('_', ' ', $f->ESTADO_FALLA) }}</span></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
