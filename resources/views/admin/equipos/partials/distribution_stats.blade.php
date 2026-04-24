@php
    // Lógica de qué card mostrar:
    //   - Si filtra por id_frente (con o sin id_tipo) → mostrar "Distribución" por TIPO
    //   - Si filtra por id_tipo sin id_frente → mostrar "Ubicación por Frente"
    //   - Sin filtros → "Distribución" por TIPO (default)
    // Se acepta $showFrentes inyectado desde el controller; si no viene, se calcula aquí.
    if (!isset($showFrentes)) {
        $reqFrente = request('id_frente');
        $reqTipo   = request('id_tipo');
        $hasFrenteFilter = $reqFrente && $reqFrente !== 'all';
        $hasTipoFilter   = $reqTipo && $reqTipo !== 'all';
        $showFrentes     = $hasTipoFilter && !$hasFrenteFilter;
    }
@endphp

@if($showFrentes)
    {{-- Distribución por FRENTE (cuando filtra por tipo sin frente) --}}
    <h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="material-icons" style="font-size: 18px; color: #10b981;">map</i>
        Ubicación por Frente
    </h4>
    <ul style="list-style: none; padding: 0; margin: 0; max-height: 75vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar">
        @if($hasFilter ?? request('search_query') || request('id_frente') || request('id_tipo'))
            @php $totalFrentes = $frentesStats->sum('total'); @endphp
            @foreach($frentesStats as $stat)
                @php $percentage = $totalFrentes > 0 ? ($stat->total / $totalFrentes) * 100 : 0; @endphp
                <li onclick="selectOption('frenteFilterSelect', '{{ $stat->ID_FRENTE_ACTUAL }}', '{{ $stat->NOMBRE_FRENTE }}'); loadEquipos();"
                    style="padding-bottom: 4px; border-bottom: 1px dashed #f1f5f9; transition: opacity 0.2s; cursor: pointer;"
                    onmouseover="this.style.opacity='0.7'" onmouseout="this.style.opacity='1'">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; gap: 4px;">
                        <span style="color: #334155; font-size: 12.5px; font-weight: 600; word-break: break-word; line-height: 1.25; flex: 1;">
                            {{ $stat->NOMBRE_FRENTE ?? 'Sin Asignar' }}
                        </span>
                        <span style="font-weight: 700; color: #1e293b; font-size: 12.5px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; flex-shrink: 0; white-space: nowrap;">
                            {{ $stat->total }}
                        </span>
                    </div>
                    <div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                        <div style="width: {{ $percentage }}%; height: 100%; background: linear-gradient(90deg, #10b981 0%, #059669 100%); border-radius: 2px;"></div>
                    </div>
                </li>
            @endforeach
        @endif
    </ul>

@else
    {{-- Distribución por TIPO (vista por defecto) --}}
    <h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="material-icons" style="font-size: 18px; color: #3b82f6;">pie_chart</i>
        Distribución
    </h4>
    <ul style="list-style: none; padding: 0; margin: 0; max-height: 75vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar">
        @if($hasFilter ?? request('search_query') || request('id_frente') || request('id_tipo'))
            @php $totalStats = $tiposStats->sum('total'); @endphp
            @foreach($tiposStats as $stat)
                @php $percentage = $totalStats > 0 ? ($stat->total / $totalStats) * 100 : 0; @endphp
                <li onclick="selectOption('tipoFilterSelect', '{{ $stat->id_tipo_equipo }}', '{{ $stat->nombre }}'); loadEquipos();"
                    style="padding-bottom: 4px; border-bottom: 1px dashed #f1f5f9; transition: opacity 0.2s; cursor: pointer;"
                    onmouseover="this.style.opacity='0.7'" onmouseout="this.style.opacity='1'">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; gap: 4px;">
                        <span style="color: #334155; font-size: 12.5px; font-weight: 600; word-break: break-word; line-height: 1.25; flex: 1;">
                            {{ $stat->nombre ?? 'Desconocido' }}
                        </span>
                        <span style="font-weight: 700; color: #1e293b; font-size: 12.5px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; flex-shrink: 0; white-space: nowrap;">
                            {{ $stat->total }}
                        </span>
                    </div>
                    <div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                        <div style="width: {{ $percentage }}%; height: 100%; background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%); border-radius: 2px;"></div>
                    </div>
                </li>
            @endforeach
        @endif
    </ul>
@endif
