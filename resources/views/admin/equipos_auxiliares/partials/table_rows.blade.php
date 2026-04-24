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
    @endphp
    <tr data-aux-id="{{ $aux->ID_AUXILIAR }}"
        data-codigo="{{ $aux->CODIGO_INTERNO ?: $aux->SERIAL }}"
        data-frente="{{ optional($aux->frente)->NOMBRE_FRENTE ?? 'Sin Asignar' }}"
        class="aux-row-selectable @if(auth()->user() && auth()->user()->can('equipos.edit')) aux-row-clickable @endif"
        style="{{ auth()->user() && auth()->user()->can('equipos.edit') ? 'cursor:pointer;' : '' }}">
        {{-- 1. Tipo + Marca/Modelo + Frente (columna FRENTE/FOTO eliminada) --}}
        <td class="table-cell-custom" style="font-size: 12px; color: #000; max-width: 240px; word-wrap: break-word;">
            <div style="font-weight: 700; text-transform: uppercase; line-height: 1.25;">{{ $tipoLabel }}</div>
            <div style="font-size: 11px; color: #334155; font-weight: 600; text-transform: uppercase; margin-top: 2px;">
                {{ $aux->MARCA }} {{ $aux->MODELO }}
            </div>
            @if($aux->CODIGO_INTERNO)
                <div style="font-size: 10px; color: #718096; font-weight: 500; margin-top: 2px;">#{{ strtoupper($aux->CODIGO_INTERNO) }}</div>
            @endif
            <div style="display:inline-flex; align-items:center; gap:3px; margin-top: 4px; font-size: 10px; color: #64748b; font-weight: 600; text-transform: uppercase;">
                <i class="material-icons" style="font-size: 12px;">place</i>
                {{ optional($aux->frente)->NOMBRE_FRENTE ?? 'SIN ASIGNAR' }}
            </div>
        </td>

        {{-- 3. Serial --}}
        <td class="table-cell-custom" style="font-size: 12px; max-width: 140px; color: #4a5568;">
            <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-transform: uppercase;">
                <strong>S:</strong> {{ $aux->SERIAL ?: '—' }}
            </div>
        </td>

        {{-- 4. Capacidad (compresa) --}}
        <td class="table-cell-custom" style="font-size: 12px; color: #4a5568; text-transform: uppercase; width: 90px; max-width: 90px;">
            {{ $aux->CAPACIDAD ?: '—' }}
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

        {{-- 6. Acciones (ojo -> modal de detalles) --}}
        <td class="table-cell-center" style="padding: 8px 5px; width: 40px; text-align: center; vertical-align: middle;">
            <div style="display:flex; justify-content:center; align-items:center;">
                <button type="button"
                    data-aux-id="{{ $aux->ID_AUXILIAR }}"
                    onclick="window.openAuxDetailsModal(this, event)"
                    class="btn-details-mini" title="Ver Detalles">
                    <i class="material-icons">visibility</i>
                </button>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" class="table-empty-state" style="text-align: center; padding: 40px; color: #94a3b8;">
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
