@forelse($fallas as $f)
    @php
        $a            = $f->_activo ?? null;
        $isAux        = $f->ACTIVO_TIPO === 'equipo_auxiliar';
        $foto         = $a ? ($a->FOTO_EQUIPO ?? $a->FOTO ?? null) : null;
        $marcaModelo  = $a ? trim(($a->MARCA ?? '') . ' ' . ($a->MODELO ?? '')) : '—';
        $serial       = $a ? ($a->SERIAL_CHASIS ?? $a->SERIAL ?? '') : '';
        $placa        = (!$isAux && $a) ? ($a->documentacion?->PLACA ?? '') : '';
        $codigo       = $a ? ($a->CODIGO_PATIO ?? $a->CODIGO_INTERNO ?? '') : '';
        $frente       = $f->_frente_nombre ?? '—';
        $tipoLabel    = $isAux ? ($a->TIPO ?? '') : ($a->tipo?->nombre ?? '');
    @endphp
    <div class="falla-row-card">

        {{-- Foto miniatura --}}
        <div class="falla-foto">
            @if($foto)
                <img src="{{ url($foto) }}" alt=""
                     onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'material-icons\'>{{ $isAux ? 'construction' : 'agriculture' }}</i>';">
            @else
                <i class="material-icons">{{ $isAux ? 'construction' : 'agriculture' }}</i>
            @endif
        </div>

        {{-- Meta --}}
        <div class="falla-meta">

            {{-- Línea 1: estado · código · fecha · prioridad --}}
            <div class="falla-codigo">
                <span style="font-weight: 700; color: {{ $f->ESTADO_REPORTE === 'abierto' ? '#ef4444' : '#10b981' }}; text-transform: capitalize;">
                    Reporte {{ $f->ESTADO_REPORTE }}
                </span>
                @if($f->TIPO_REPORTE === 'extenso')
                    · {{ $f->CODIGO_REPORTE }}
                @endif
                · {{ $f->FECHA_EMISION->format('d/m/Y H:i') }}
                @if($f->PRIORIDAD)
                    <span class="falla-chip falla-chip-prioridad">{{ $f->PRIORIDAD }}</span>
                @endif
            </div>

            {{-- Línea 2: tipo · marca modelo · código --}}
            <div class="falla-equipo">
                @if($tipoLabel)
                    <span style="font-size:11px; font-weight:500; color:#94a3b8; text-transform:uppercase; letter-spacing:0.4px; margin-right:5px;">{{ strtoupper($tipoLabel) }}</span>·
                @endif
                {{ $marcaModelo ?: '(sin marca/modelo)' }}
                @if($codigo)
                    <span style="color:#64748b; font-weight:400; font-size:12px;"> · {{ $codigo }}</span>
                @endif
            </div>

            {{-- Línea 3: serial · placa · frente · reportó --}}
            <div class="falla-info" style="display:flex; flex-wrap:wrap; align-items:center; gap:3px 14px;">
                @if($serial)
                    <span style="display:inline-flex; align-items:center; gap:3px;">
                        <i class="material-icons" style="font-size:12px;">fingerprint</i>
                        <strong>{{ $serial }}</strong>
                    </span>
                @endif
                @if($placa)
                    <span style="display:inline-flex; align-items:center; gap:3px;">
                        <i class="material-icons" style="font-size:12px;">featured_play_list</i>
                        <strong>{{ $placa }}</strong>
                    </span>
                @endif
                @if($frente !== '—')
                    <span style="display:inline-flex; align-items:center; gap:3px; color:#3b82f6; font-weight:600;">
                        <i class="material-icons" style="font-size:12px; color:#3b82f6;">location_on</i>
                        {{ $frente }}
                    </span>
                @endif
                @if($f->SISTEMA_AFECTADO)
                    <span>· Sistema: {{ $f->SISTEMA_AFECTADO }}</span>
                @endif
                <span style="color:#94a3b8;">· Reportó: {{ $f->NOMBRE_REPORTA ?: '—' }}</span>
            </div>

            {{-- Descripción de la avería --}}
            @if($f->DESCRIPCION_AVERIA)
                <div class="falla-info" style="margin-top:3px; color:#475569; font-style:italic;">
                    "{{ \Illuminate\Support\Str::limit($f->DESCRIPCION_AVERIA, 160) }}"
                </div>
            @endif
        </div>

        {{-- Acciones --}}
        <div class="falla-actions">
            @if($f->TIPO_REPORTE === 'extenso')
                <a href="{{ route('fallas.pdf', $f->ID_FALLA) }}" target="_blank" class="falla-btn" title="Descargar PDF">
                    <i class="material-icons" style="font-size:16px;">picture_as_pdf</i> PDF
                </a>
            @endif
            @if($f->ESTADO_REPORTE === 'abierto')
                <button type="button" class="falla-btn" title="Cerrar reporte"
                    onclick="window.cerrarFalla({{ $f->ID_FALLA }}, '{{ $f->CODIGO_REPORTE }}', '{{ addslashes($marcaModelo) }}')">
                    <i class="material-icons" style="font-size:16px;">check_circle</i> Cerrar
                </button>
            @endif
        </div>
    </div>
@empty
    <div style="background:white; border:1px dashed #cbd5e1; border-radius:12px; padding:40px 20px; text-align:center; color:#94a3b8;">
        <i class="material-icons" style="font-size:42px; display:block; margin:0 auto 8px; color:#cbd5e1;">inbox</i>
        <span style="font-size:13px;">No hay reportes que coincidan con los filtros.</span>
    </div>
@endforelse
