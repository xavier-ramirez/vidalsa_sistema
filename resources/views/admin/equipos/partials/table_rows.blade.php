@forelse($equipos as $equipo)
    <tr>
        <!-- 1. Foto -->
        <td class="table-cell-custom table-cell-center" style="padding: 4px 2px;">
            <!-- Frente Info (Con Burbuja Personalizada) -->
            <div class="tooltip-wrapper"
                style="font-size: 13px; color: #000000; margin-bottom: 5px; line-height: 1.3; font-weight: 600; text-align: center; width: 100%; word-wrap: break-word; position: relative; cursor: default;">

                {{ $equipo->frenteActual->NOMBRE_FRENTE ?? 'Sin Asignar' }}

                {{-- Alerta: Equipo en frente FINALIZADO --}}
                @if($equipo->frenteActual && $equipo->frenteActual->ESTATUS_FRENTE === 'FINALIZADO')
                    <div style="display: flex; align-items: center; justify-content: center; gap: 3px; margin-top: 2px;">
                        <span
                            style="background: #fef2f2; color: #dc2626; padding: 1px 6px; border-radius: 8px; font-size: 9px; font-weight: 700; display: inline-flex; align-items: center; gap: 2px; border: 1px solid #fecaca;">
                            <i class="material-icons" style="font-size: 10px;">warning</i>
                            PROYECTO FINALIZADO
                        </span>
                    </div>
                @endif

                @if($equipo->DETALLE_UBICACION_ACTUAL)
                    {{-- Burbuja Tooltip --}}
                    <div class="tooltip-bubble" style="
                                                                                pointer-events: none;
                                                                                opacity: 0;
                                                                                visibility: hidden;
                                                                                position: absolute;
                                                                                bottom: 100%;
                                                                                left: 50%;
                                                                                transform: translateX(-50%) translateY(5px);
                                                                                background-color: #1e293b;
                                                                                color: #fff;
                                                                                padding: 6px 10px;
                                                                                border-radius: 6px;
                                                                                font-size: 11px;
                                                                                font-weight: 500;
                                                                                white-space: nowrap;
                                                                                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
                                                                                transition: all 0.2s ease-in-out;
                                                                                z-index: 50;
                                                                                margin-bottom: 5px;
                                                                            ">
                        📍 {{ $equipo->DETALLE_UBICACION_ACTUAL }}
                        {{-- Flechita --}}
                        <div style="
                                                                                    position: absolute;
                                                                                    top: 100%;
                                                                                    left: 50%;
                                                                                    margin-left: -4px;
                                                                                    border-width: 4px;
                                                                                    border-style: solid;
                                                                                    border-color: #1e293b transparent transparent transparent;
                                                                                "></div>
                    </div>
                @endif
            </div>

            @php
                $fotoToShow = ($equipo->especificaciones && $equipo->especificaciones->FOTO_REFERENCIAL) 
                              ? $equipo->especificaciones->FOTO_REFERENCIAL 
                              : $equipo->FOTO_EQUIPO;
            @endphp
            @if($fotoToShow)
                <div class="table-image-wrapper" style="cursor: default;">
                    {{-- data-src: cargada por _registerLazyImages (IntersectionObserver + semáforo máx 3 concurrentes) --}}
                    <img data-src="{{ route('drive.file', ['path' => str_replace('/storage/google/', '', $fotoToShow)]) }}"
                        alt="Foto Modelo"
                        style="width:100%;height:100%;object-fit:contain;opacity:0;transition:opacity 0.4s;">
                </div>
            @else
                <div class="table-image-wrapper placeholder">
                    <span class="material-icons">image_not_supported</span>
                </div>
            @endif
        </td>
        <!-- 2. Tipo -->
        <td class="table-cell-custom" style="font-weight: 600; max-width: 170px; font-size: 14px; color: #000;">
            {{ $equipo->tipo->nombre ?? 'N/A' }}
            @if($equipo->NUMERO_ETIQUETA)
                <span
                    style="margin-left: 8px; font-weight: 700; color: var(--maquinaria-blue);">#{{ $equipo->NUMERO_ETIQUETA }}</span>
            @endif
        </td>
        <!-- 3. Marca / Modelo -->
        <td class="table-cell-custom" style="max-width: 110px; word-wrap: break-word; overflow-wrap: break-word;">
            <div style="font-weight: 700; font-size: 14px; color: #000;">{{ $equipo->MARCA }}</div>
            <div style="font-size: 14px; color: #718096;">{{ $equipo->MODELO }}</div>
        </td>
        <!-- 4. Seriales / Placa / ID -->
        <td class="table-cell-custom"
            style="max-width: 160px; font-size: 14px;">
            <div style="color: #4a5568; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><strong>S:</strong> {{ $equipo->SERIAL_CHASIS }}</div>
            @if($equipo->SERIAL_DE_MOTOR)
                <div style="color: #64748b; margin-top: 1px; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><strong>M:</strong> {{ $equipo->SERIAL_DE_MOTOR }}</div>
            @endif
            @if($equipo->documentacion && $equipo->documentacion->PLACA)
                <div style="color: var(--maquinaria-blue); margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><strong>P:</strong>
                    {{ $equipo->documentacion->PLACA }}</div>
            @else
                <div style="color: #a0aec0; margin-top: 1px; font-style: italic;">Sin Placa</div>
            @endif
            <div style="color: #2d3748; margin-top: 1px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><strong>ID:</strong> {{ $equipo->CODIGO_PATIO }}
            </div>
        </td>
        <!-- 6. Estatus -->
        <td class="table-cell-custom" style="padding: 12px 2px; width: 140px;">
            @php
                $statusConfig = [
                    'OPERATIVO'       => ['color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'check_circle',  'label' => 'Operativo'],
                    'INOPERATIVO'     => ['color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'cancel',        'label' => 'Inoperativo'],
                    'EN MANTENIMIENTO'=> ['color' => '#d97706', 'bg' => '#fffbeb', 'icon' => 'engineering',   'label' => 'Mantenimiento'],
                    'DESINCORPORADO'  => ['color' => '#475569', 'bg' => '#f1f5f9', 'icon' => 'archive',       'label' => 'Desincorp.']
                ];
                $currentConfig = $statusConfig[$equipo->ESTADO_OPERATIVO] ?? $statusConfig['DESINCORPORADO'];
            @endphp

            @can('equipos.edit')
                {{-- Trigger ligero: solo almacena la data necesaria, sin menú embebido --}}
                <div class="status-trigger-lite"
                    data-equipo-id="{{ $equipo->ID_EQUIPO }}"
                    data-status="{{ $equipo->ESTADO_OPERATIVO }}"
                    data-status-url="{{ route('equipos.changeStatus', $equipo->ID_EQUIPO) }}"
                    onclick="event.stopPropagation(); openSharedStatusMenu(this)"
                    style="padding: 6px 10px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 5px; font-size: 13px; font-weight: 600; background: white; border: 1px solid #e2e8f0; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <div style="display: flex; align-items: center; gap: 6px; color: {{ $currentConfig['color'] }};">
                        <i class="material-icons" style="font-size: 16px;">{{ $currentConfig['icon'] }}</i>
                        <span style="color: #334155;">{{ $currentConfig['label'] }}</span>
                    </div>
                    <i class="material-icons" style="font-size: 16px; color: #94a3b8;">expand_more</i>
                </div>
            @else
                <div style="padding: 6px 10px; border-radius: 8px; display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; background: {{ $currentConfig['bg'] }}; border: 1px solid {{ $currentConfig['bg'] }}; color: {{ $currentConfig['color'] }};">
                    <i class="material-icons" style="font-size: 16px;">{{ $currentConfig['icon'] }}</i>
                    <span>{{ $currentConfig['label'] }}</span>
                </div>
            @endcan
        </td>
        <td class="table-cell-center" style="padding: 12px 5px; width: 20px;">
            <div style="display: flex; gap: 8px; justify-content: center;">
                <button type="button" 
                    data-equipo-id="{{ $equipo->ID_EQUIPO }}" 
                    data-codigo="{{ $equipo->CODIGO_PATIO }}"
                    data-chasis="{{ $equipo->SERIAL_CHASIS }}"
                    data-placa="{{ optional($equipo->documentacion)->PLACA ?? 'N/A' }}"
                    data-anchor-id="{{ $equipo->ID_ANCLAJE ?? '' }}"
                    data-frente-id="{{ $equipo->ID_FRENTE_ACTUAL }}"
                    onclick="showDetailsImproved(this, event)"
                    class="btn-details-mini" title="Ver Detalles">
                    <i class="material-icons">visibility</i>
                </button>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="table-empty-state">
            @if(request('search_query') || request('id_frente') || request('id_tipo'))
                <i class="material-icons"
                    style="font-size: 48px; display: block; margin: 0 auto 10px auto; color: #cbd5e0;">search_off</i>
                No se encontraron equipos con los filtros aplicados.
            @else
                <i class="material-icons"
                    style="font-size: 48px; display: block; margin: 0 auto 10px auto; color: #cbd5e0;">filter_alt</i>
                Seleccione un filtro para ver los equipos.
            @endif
        </td>
    </tr>
@endforelse