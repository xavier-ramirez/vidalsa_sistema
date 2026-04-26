@forelse($auxiliares as $aux)
    @php
        // Usa $tipos (que el controller pasa con tipos custom + enum) como fuente
        // de labels. Asi reflejamos los tipos nuevos creados por el usuario.
        $tiposMap  = $tipos ?? \App\Models\EquipoAuxiliar::tiposLabel();
        $tipoLabel = $tiposMap[$aux->TIPO] ?? $aux->TIPO;

        $statusConfig = [
            'OPERATIVO'       => ['color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'check_circle',  'label' => 'OPERATIVO'],
            'INOPERATIVO'     => ['color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'cancel',        'label' => 'INOPERATIVO'],
            'EN_ALMACEN'      => ['color' => '#1e40af', 'bg' => '#eff6ff', 'icon' => 'inventory_2',   'label' => 'EN ALMACÉN'],
            'DESINCORPORADO'  => ['color' => '#475569', 'bg' => '#f1f5f9', 'icon' => 'block',         'label' => 'DESINCORP.'],
        ];
        $currentConfig = $statusConfig[$aux->ESTADO_OPERATIVO] ?? $statusConfig['DESINCORPORADO'];

        // Foto del equipo: propia, fallback a la de otro aux con misma
        // MARCA/MODELO (catalogo implicito). $photoByModel viene opcional.
        $photoByModel = $photoByModel ?? collect();
        $modelKey = mb_strtoupper(trim(($aux->MARCA ?? '') . '|' . ($aux->MODELO ?? '')));
        $fotoUrl  = $aux->FOTO ?: ($photoByModel[$modelKey] ?? null);
        $fotoDriveId = $fotoUrl ? basename(str_replace('/storage/google/', '', $fotoUrl)) : null;
    @endphp
    <tr data-aux-id="{{ $aux->ID_AUXILIAR }}"
        data-codigo="{{ $aux->CODIGO_INTERNO ?: $aux->SERIAL }}"
        data-frente="{{ optional($aux->frente)->NOMBRE_FRENTE ?? 'Sin Asignar' }}"
        class="aux-row-selectable @if(auth()->user() && auth()->user()->can('equipos.edit')) aux-row-clickable @endif"
        style="{{ auth()->user() && auth()->user()->can('equipos.edit') ? 'cursor:pointer;' : '' }}">
        {{-- 1. FRENTE + FOTO (patron vehiculos: /admin/equipos) --}}
        <td class="table-cell-custom table-cell-center" style="padding: 6px 4px; width: 150px;">
            <div style="font-size: 11px; color: #000; margin-bottom: 5px; line-height: 1.2; font-weight: 700; text-align: center; text-transform: uppercase; word-wrap: break-word;">
                {{ optional($aux->frente)->NOMBRE_FRENTE ?? 'SIN ASIGNAR' }}
            </div>
            @if($fotoDriveId)
                <div class="table-image-wrapper" style="cursor: default;">
                    <img data-src="{{ url('/storage/google/' . $fotoDriveId . '?sz=w300') }}"
                         alt="Foto"
                         style="width:100%; height:100%; object-fit:contain; opacity:0; transition:opacity 0.4s;">
                </div>
            @else
                <div class="table-image-wrapper placeholder">
                    <span class="material-icons">image_not_supported</span>
                </div>
            @endif
        </td>

        {{-- 2. Tipo (columna independiente, patron /admin/equipos) --}}
        <td class="table-cell-custom" style="font-size: 13px; color: #000; word-wrap: break-word;">
            <div style="font-weight: 700; text-transform: uppercase; line-height: 1.25;">{{ $tipoLabel }}</div>
            @if($aux->CAPACIDAD)
                <div style="font-size: 11px; color: #64748b; font-weight: 500; text-transform: uppercase; margin-top: 3px;">{{ $aux->CAPACIDAD }}</div>
            @endif
        </td>

        {{-- 3. Marca / Modelo --}}
        <td class="table-cell-custom" style="font-size: 13px; color: #000; word-wrap: break-word;">
            <div style="font-weight: 700; text-transform: uppercase; line-height: 1.25;">{{ $aux->MARCA ?: '—' }}</div>
            @if($aux->MODELO)
                <div style="font-size: 13px; color: #64748b; font-weight: 500; text-transform: uppercase; margin-top: 3px;">{{ $aux->MODELO }}</div>
            @endif
        </td>

        {{-- 4. Serial / Codigo interno --}}
        <td class="table-cell-custom" style="font-size: 14px; color: #4a5568;">
            <div style="text-transform: uppercase; line-height: 1.3;">
                <strong style="color:#64748b;">S:</strong> {{ $aux->SERIAL ?: '—' }}
            </div>
            @if($aux->CODIGO_INTERNO)
                <div style="font-size: 12.5px; color: #718096; margin-top: 3px; text-transform: uppercase;">
                    <strong style="color:#64748b;">Cod:</strong> #{{ strtoupper($aux->CODIGO_INTERNO) }}
                </div>
            @endif
            @if($aux->ANIO)
                <div style="font-size: 12px; color: #94a3b8; margin-top: 2px;">Año: {{ $aux->ANIO }}</div>
            @endif
        </td>

        {{-- 5. Estado (compreso) --}}
        <td class="table-cell-custom" style="padding: 8px 2px; width: 120px;">
            @can('equipos.edit')
                <div class="aux-status-trigger"
                    data-aux-id="{{ $aux->ID_AUXILIAR }}"
                    data-status="{{ $aux->ESTADO_OPERATIVO }}"
                    data-status-url="{{ route('equipos-auxiliares.estado', $aux->ID_AUXILIAR) }}"
                    onclick="event.stopPropagation(); window.openAuxStatusMenu(this)"
                    style="padding: 5px 8px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 4px; font-size: 11px; font-weight: 700; background: white; border: 1px solid #e2e8f0; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <div style="display: flex; align-items: center; gap: 5px; color: {{ $currentConfig['color'] }};">
                        <i class="material-icons" style="font-size: 14px;">{{ $currentConfig['icon'] }}</i>
                        <span class="aux-status-label" style="color: #334155; text-transform: uppercase;">{{ $currentConfig['label'] }}</span>
                    </div>
                    <i class="material-icons" style="font-size: 14px; color: #94a3b8;">expand_more</i>
                </div>
            @else
                <div style="padding: 5px 8px; border-radius: 8px; display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; background: {{ $currentConfig['bg'] }}; border: 1px solid {{ $currentConfig['bg'] }}; color: {{ $currentConfig['color'] }}; text-transform: uppercase;">
                    <i class="material-icons" style="font-size: 14px;">{{ $currentConfig['icon'] }}</i>
                    <span>{{ $currentConfig['label'] }}</span>
                </div>
            @endcan
        </td>

        {{-- 6. Acciones: ojo (detalles). Click anywhere en este TD NO selecciona la fila. --}}
        <td class="table-cell-center aux-action-cell" style="padding: 8px 5px; width: 72px; text-align: center; vertical-align: middle;">
            <div style="display:flex; justify-content:center; align-items:center; gap:4px;">
                <button type="button"
                    data-aux-id="{{ $aux->ID_AUXILIAR }}"
                    data-tipo-label="{{ $tipoLabel }}"
                    data-marca="{{ $aux->MARCA }}"
                    data-modelo="{{ $aux->MODELO }}"
                    onclick="event.stopPropagation(); window.openAuxDetailsModal(this, event)"
                    class="btn-details-mini" title="Ver Detalles">
                    <i class="material-icons">visibility</i>
                </button>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="table-empty-state" style="text-align: center; padding: 40px; color: #94a3b8;">
            @if(request('tipo') || request('id_frente') || request('search') || request('marca') || request('modelo') || request('capacidad') || request('estado'))
                <i class="material-icons" style="font-size: 48px; display: block; margin: 0 auto 10px auto; color: #cbd5e0;">search_off</i>
                NO SE ENCONTRARON EQUIPOS AUXILIARES CON LOS FILTROS APLICADOS.
            @else
                <i class="material-icons" style="font-size: 48px; display: block; margin: 0 auto 10px auto; color: #cbd5e0;">filter_alt</i>
                SELECCIONA UN FILTRO PARA VER LOS EQUIPOS AUXILIARES.
            @endif
        </td>
    </tr>
@endforelse
