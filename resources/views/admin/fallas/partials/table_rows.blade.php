@forelse($fallas as $f)
    @php
        $a = $f->_activo ?? null;
        $foto = $a ? ($a->FOTO_EQUIPO ?? $a->FOTO ?? null) : null;
        $marcaModelo = $a ? trim(($a->MARCA ?? '') . ' ' . ($a->MODELO ?? '')) : '—';
        $serial = $a ? ($a->SERIAL_CHASIS ?? $a->SERIAL ?? '') : '';
        $codigo = $a ? ($a->CODIGO_PATIO ?? $a->CODIGO_INTERNO ?? '') : '';
        $estadoActual = $a?->ESTADO_OPERATIVO ?? '';
        $frente = $f->_frente_nombre ?? '—';
        $isAux = $f->ACTIVO_TIPO === 'equipo_auxiliar';
    @endphp
    <div class="falla-row-card">
        {{-- Foto miniatura --}}
        <div class="falla-foto">
            @if($foto)
                <img src="{{ url($foto) }}" alt="" onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'material-icons\'>{{ $isAux ? 'construction' : 'agriculture' }}</i>';">
            @else
                <i class="material-icons">{{ $isAux ? 'construction' : 'agriculture' }}</i>
            @endif
        </div>

        {{-- Meta --}}
        <div class="falla-meta">
            <div class="falla-codigo">
                <span class="falla-chip {{ $f->ESTADO_REPORTE === 'abierto' ? 'falla-chip-abierto' : 'falla-chip-cerrado' }}">
                    {{ $f->ESTADO_REPORTE }}
                </span>
                · {{ $f->CODIGO_REPORTE }}
                · {{ $f->FECHA_EMISION->format('d/m/Y H:i') }}
                @if($f->PRIORIDAD)
                    <span class="falla-chip falla-chip-prioridad">{{ $f->PRIORIDAD }}</span>
                @endif
            </div>
            <div class="falla-equipo">
                {{ $isAux ? '🔧' : '🚛' }} {{ $marcaModelo ?: '(sin marca/modelo)' }}
                @if($codigo) <span style="color:#64748b; font-weight:400;">· {{ $codigo }}</span> @endif
            </div>
            <div class="falla-info">
                @if($serial) S/N: <strong>{{ $serial }}</strong> · @endif
                Estado actual: <strong>{{ $estadoActual ?: '—' }}</strong>
                @if($frente !== '—') · <i class="material-icons" style="font-size:12px; vertical-align:middle; color:#0067b1;">place</i> {{ $frente }} @endif
                @if($f->SISTEMA_AFECTADO) · Sistema: {{ $f->SISTEMA_AFECTADO }} @endif
                · Reportó: {{ $f->NOMBRE_REPORTA ?: '—' }}
            </div>
            @if($f->DESCRIPCION_AVERIA)
                <div class="falla-info" style="margin-top:2px; color:#475569;">
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
