{{-- Distribución de AUXILIARES en /admin/equipos (modo aux). Espeja
     admin.equipos.partials.distribution_stats:
     - Con un TIPO de aux seleccionado y SIN frente ($showFrentes): "Ubicación por
       Frente" → cuántos de ese tipo hay en CADA frente. Click filtra ese frente.
     - Si no: distribución por TIPO (navegar entre tipos). Click filtra 'tipo_aux:CODIGO'.
     $auxDistribucion = [['tipo','label','total'], ...]; $auxDistribucionFrentes = Collection. --}}
@if(($showFrentes ?? false) && !empty($auxDistribucionFrentes) && count($auxDistribucionFrentes))
    {{-- Distribución por FRENTE (cuando filtra por tipo aux sin frente) --}}
    <h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="material-icons" style="font-size: 18px; color: #10b981;">map</i>
        Ubicación por Frente
    </h4>
    <ul style="list-style: none; padding: 0; margin: 0; max-height: 64vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar">
        @php $totalFrentesAux = collect($auxDistribucionFrentes)->sum('total'); @endphp
        @foreach($auxDistribucionFrentes as $stat)
            @php $percentage = $totalFrentesAux > 0 ? ($stat->total / $totalFrentesAux) * 100 : 0; @endphp
            <li onclick="selectOption('frenteFilterSelect', '{{ $stat->ID_FRENTE_ACTUAL }}', '{{ addslashes($stat->NOMBRE_FRENTE ?? 'Sin Asignar') }}'); loadEquipos();"
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
    </ul>
@else
    {{-- Distribución por TIPO (vista por defecto) --}}
    <h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="material-icons" style="font-size: 18px; color: #3b82f6;">pie_chart</i>
        Distribución
    </h4>
    <ul style="list-style: none; padding: 0; margin: 0; max-height: 64vh; overflow-y: auto; overflow-x: visible; display: flex; flex-direction: column; gap: 4px;" class="custom-scrollbar">
        @php $totalAux = collect($auxDistribucion)->sum('total'); @endphp
        @foreach($auxDistribucion as $d)
            @php $percentage = $totalAux > 0 ? ($d['total'] / $totalAux) * 100 : 0; @endphp
            <li onclick="selectOption('tipoFilterSelect', 'tipo_aux:{{ $d['tipo'] }}', '{{ addslashes($d['label']) }}'); loadEquipos();"
                style="padding-bottom: 4px; border-bottom: 1px dashed #f1f5f9; transition: opacity 0.2s; cursor: pointer;"
                onmouseover="this.style.opacity='0.7'" onmouseout="this.style.opacity='1'">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; gap: 4px;">
                    <span style="color: #334155; font-size: 12.5px; font-weight: 600; word-break: break-word; line-height: 1.25; flex: 1;">
                        {{ $d['label'] }}
                    </span>
                    <span style="font-weight: 700; color: #1e293b; font-size: 12.5px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; flex-shrink: 0; white-space: nowrap;">
                        {{ $d['total'] }}
                    </span>
                </div>
                <div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                    <div style="width: {{ $percentage }}%; height: 100%; background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%); border-radius: 2px;"></div>
                </div>
            </li>
        @endforeach
    </ul>
@endif
