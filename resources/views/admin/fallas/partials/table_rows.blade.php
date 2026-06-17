@forelse($fallas as $f)
    @php
        $a            = $f->_activo ?? null;
        $isAux        = $f->ACTIVO_TIPO === 'equipo_auxiliar';
        $foto         = $a ? ($a->FOTO_EQUIPO ?? $a->FOTO ?? ($a->especificaciones?->FOTO_REFERENCIAL ?? null)) : null;
        $marcaModelo  = $a ? trim(($a->MARCA ?? '') . ' ' . ($a->MODELO ?? '')) : '—';
        $marca        = $a ? trim($a->MARCA ?? '') : '';
        $serial       = $a ? ($a->SERIAL_CHASIS ?? $a->SERIAL ?? '') : '';
        $placa        = (!$isAux && $a) ? ($a->documentacion?->PLACA ?? '') : '';
        $codigo       = $a ? ($a->CODIGO_PATIO ?? $a->CODIGO_INTERNO ?? '') : '';
        $frente       = $f->_frente_nombre ?? '—';
        $tipoLabel    = $isAux ? ($a->TIPO ?? '') : ($a->tipo?->nombre ?? '');
        // Identificador para el modal de cierre: placa; si no tiene, el serial
        // (luego código y marca/modelo como último recurso para no quedar vacío).
        $idCierre     = $placa ?: ($serial ?: ($codigo ?: $marcaModelo));
    @endphp
    {{-- Tap en la tarjeta (móvil): expande el detalle truncado. No dispara si el tap
         fue en un botón/enlace (PDF/Cerrar). --}}
    <div class="falla-row-card" onclick="if(!event.target.closest('button,a')) this.classList.toggle('falla-expanded');">

        {{-- Columna de la foto: el frente va FUERA de la foto (arriba), como en /admin/equipos --}}
        <div class="falla-foto-col">
            @if($frente !== '—')
                <div class="falla-foto-frente" title="{{ $frente }}">{{ $frente }}</div>
            @endif
            <div class="falla-foto">
                @if($foto)
                    @php
                        if (!str_starts_with($foto, 'http') && !str_starts_with($foto, '/')) {
                            $foto = '/storage/' . ltrim($foto, '/');
                        }
                    @endphp
                    <img src="{{ url($foto) }}" alt=""
                         onerror="this.outerHTML='<i class=\'material-icons\'>{{ $isAux ? 'construction' : 'agriculture' }}</i>';">
                @else
                    <i class="material-icons">{{ $isAux ? 'construction' : 'agriculture' }}</i>
                @endif
            </div>
        </div>

        {{-- Meta --}}
        <div class="falla-meta">

            {{-- Línea 1: estado · código · tipo de mantenimiento · fecha --}}
            <div class="falla-codigo">
                <span style="font-weight: 700; color: {{ $f->ESTADO_REPORTE === 'abierto' ? '#ef4444' : '#10b981' }}; text-transform: capitalize;">
                    Reporte {{ $f->ESTADO_REPORTE }}
                </span>
                @if($f->TIPO_REPORTE === 'extenso')
                    · {{ $f->CODIGO_REPORTE }}
                @endif
                @if($f->TIPO_MANTENIMIENTO)
                    · {{ \App\Models\Falla::tiposMantenimiento()[$f->TIPO_MANTENIMIENTO] ?? $f->TIPO_MANTENIMIENTO }}
                @endif
                · {{ $f->FECHA_EMISION->format('d/m/Y H:i') }}
            </div>

            {{-- Línea 2: tipo · marca · código (sin el modelo) --}}
            <div class="falla-equipo">
                @if($tipoLabel)
                    <span style="font-size:12px; font-weight:700; color:#000; text-transform:uppercase; letter-spacing:0.4px; margin-right:5px;">{{ strtoupper($tipoLabel) }}</span>·
                @endif
                {{ $marca ?: '(sin marca)' }}
                @if($codigo)
                    <span style="color:#64748b; font-weight:400; font-size:12px;"> · {{ $codigo }}</span>
                @endif
            </div>

            {{-- Línea 3: serial · placa · mecánico (el frente va sobre la foto) --}}
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
                @if($f->MECANICO_ASIGNADO)
                    <span style="display:inline-flex; align-items:center; gap:3px;">
                        <i class="material-icons" style="font-size:12px;">build</i>
                        {{ $f->MECANICO_ASIGNADO }}
                    </span>
                @endif
            </div>

            {{-- "Reportó" debajo de placa/serial — SOLO móvil (.falla-reporto-mobile). --}}
            @if($f->NOMBRE_REPORTA)
                {{-- Pista de toque (solo móvil, colapsado): invita a tocar la tarjeta. --}}
                <div class="falla-tap-hint">
                    <i class="material-icons" style="font-size:14px;">expand_more</i> Ver quién reportó
                </div>
                {{-- Dato revelado al tocar la tarjeta (.falla-expanded). --}}
                <div class="falla-reporto-mobile">
                    <i class="material-icons" style="font-size:12px;">person</i>
                    Reportó: <span style="color:#1e293b; font-weight:700; margin-left:3px;">{{ $f->NOMBRE_REPORTA }}</span>
                </div>
            @endif

        </div>

        {{-- Descripción de la avería: columna propia, entre la info del equipo y las acciones --}}
        <div class="falla-descripcion">
            @if($f->DESCRIPCION_AVERIA)
                "{{ \Illuminate\Support\Str::limit($f->DESCRIPCION_AVERIA, 220) }}"
            @else
                <span style="color:#cbd5e1; font-style:normal;">Sin descripción</span>
            @endif
        </div>

        {{-- Acciones --}}
        <div class="falla-actions" style="flex-direction: column; align-items: flex-end; gap:8px;">
            <div class="falla-reporto-desktop" style="font-size:11.5px; color:#64748b; text-align:right; line-height:1.2;">
                Reportó:<br>
                <span style="color:#1e293b; font-weight:700;">{{ $f->NOMBRE_REPORTA ?: '—' }}</span>
            </div>
            <div class="falla-btn-stack" style="display:flex; flex-direction:column; gap:6px; align-items:stretch;">
                @if($f->TIPO_REPORTE === 'extenso')
                    <button type="button" class="falla-btn" title="Ver PDF" style="justify-content:center;"
                        onclick="window.openPdfPreview('{{ route('fallas.pdf', $f->ID_FALLA) }}', 'falla', 'Reporte {{ $f->CODIGO_REPORTE }}', 0, '', true, 'falla')">
                        <i class="material-icons" style="font-size:16px;">picture_as_pdf</i> PDF
                    </button>
                @endif
                @if($f->ESTADO_REPORTE === 'abierto')
                    @can('equipos.edit')
                        <button type="button" class="falla-btn" title="Cerrar reporte" style="justify-content:center;"
                            data-id="{{ $f->ID_FALLA }}"
                            data-codigo="{{ $f->CODIGO_REPORTE }}"
                            data-equipo="{{ $idCierre }}"
                            data-tipo="{{ $f->TIPO_REPORTE }}"
                            data-mecanico="{{ $f->MECANICO_ASIGNADO }}"
                            data-fecha-recepcion="{{ optional($f->FECHA_RECEPCION)->format('Y-m-d') }}"
                            data-diagnostico="{{ $f->DIAGNOSTICO }}"
                            data-acciones="{{ $f->ACCIONES_REALIZADAS }}"
                            onclick="window.cerrarFalla(this)">
                            <i class="material-icons" style="font-size:16px;">check_circle</i> Cerrar
                        </button>
                    @endcan
                @endif
                {{-- Borrado DURO del reporte: discreto, SOLO super.admin (irreversible). --}}
                @can('super.admin')
                    <button type="button" class="falla-btn falla-btn-danger" title="Eliminar reporte (definitivo)" style="justify-content:center;"
                        data-id="{{ $f->ID_FALLA }}"
                        data-ref="{{ ($f->TIPO_REPORTE === 'extenso' && $f->CODIGO_REPORTE) ? $f->CODIGO_REPORTE : ($idCierre ?: ('#'.$f->ID_FALLA)) }}"
                        onclick="window.eliminarFalla(this)">
                        <i class="material-icons" style="font-size:16px;">delete_forever</i>
                    </button>
                @endcan
            </div>
        </div>
    </div>
@empty
    <div style="background:white; border:1px dashed #cbd5e1; border-radius:12px; padding:40px 20px; text-align:center; color:#94a3b8;">
        <i class="material-icons" style="font-size:42px; display:block; margin:0 auto 8px; color:#cbd5e1;">inbox</i>
        <span style="font-size:13px;">No hay reportes que coincidan con los filtros.</span>
    </div>
@endforelse
