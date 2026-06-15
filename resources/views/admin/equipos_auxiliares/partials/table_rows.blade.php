@forelse($auxiliares as $aux)
    @php
        // Usa $tipos (que el controller pasa con tipos custom + enum) como fuente
        // de labels. Asi reflejamos los tipos nuevos creados por el usuario.
        $tiposMap  = $tipos ?? \App\Models\EquipoAuxiliar::tiposLabel();
        $tipoLabel = $tiposMap[$aux->TIPO] ?? $aux->TIPO;

        $statusConfig = [
            'OPERATIVO'       => ['color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'check_circle',  'label' => 'OPERATIVO'],
            'INOPERATIVO'     => ['color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'cancel',        'label' => 'INOPERATIVO'],
            'EN MANTENIMIENTO'=> ['color' => '#eab308', 'bg' => '#fefce8', 'icon' => 'build',         'label' => 'MANTENIMIENTO'],
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
    {{-- aux-row-clickable se aplica SIEMPRE: la seleccion (toggle del check)
         no requiere permiso. La validacion de permiso (equipos.assign) la
         hacen los handlers de las acciones (Anclar / Asignar / Movilizar)
         en JS, mostrando toast "no tienes permiso" si falla. --}}
    <tr data-aux-id="{{ $aux->ID_AUXILIAR }}"
        data-codigo="{{ $aux->CODIGO_INTERNO ?: $aux->SERIAL }}"
        data-frente="{{ optional($aux->frente)->NOMBRE_FRENTE ?? 'Sin Asignar' }}"
        class="aux-row-selectable aux-row-clickable"
        style="cursor:pointer;">
        {{-- 1. FRENTE + FOTO (patron vehiculos: /admin/equipos)
             tooltip-bubble con DETALLE_UBICACION_ACTUAL en hover de fila (CSS
             global .admin-table tr:hover .tooltip-bubble lo hace visible). --}}
        <td class="table-cell-custom table-cell-center" style="padding: 6px 4px; width: 150px;">
            <div class="tooltip-wrapper" style="font-size: 13px; color: #000; margin-bottom: 5px; line-height: 1.25; font-weight: 700; text-align: center; text-transform: uppercase; word-wrap: break-word; position: relative; cursor: default;">
                {{ optional($aux->frente)->NOMBRE_FRENTE ?? 'SIN ASIGNAR' }}

                {{-- Badge FINALIZADO: misma lógica/estilo que /admin/equipos --}}
                @if($aux->frente && $aux->frente->ESTATUS_FRENTE === 'FINALIZADO')
                    <div style="display:flex; align-items:center; justify-content:center; gap:3px; margin-top:3px;">
                        <span style="background:#fef2f2; color:#dc2626; padding:1px 6px; border-radius:8px; font-size:9px; font-weight:700; display:inline-flex; align-items:center; gap:2px; border:1px solid #fecaca;">
                            <i class="material-icons" style="font-size:10px;">warning</i>
                            FINALIZADO
                        </span>
                    </div>
                @endif

                @if($aux->DETALLE_UBICACION_ACTUAL)
                    <div class="tooltip-bubble" style="pointer-events:none; opacity:0; visibility:hidden; position:absolute; bottom:100%; left:0; transform:translateY(5px); background:#1e293b; color:#fff; padding:6px 10px; border-radius:6px; font-size:11px; font-weight:500; white-space:normal; width:max-content; max-width:220px; word-wrap:break-word; text-align:center; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); transition:all 0.2s ease-in-out; z-index:50; margin-bottom:5px;">
                        📍 {{ $aux->DETALLE_UBICACION_ACTUAL }}
                        <div style="position:absolute; top:100%; left:75px; margin-left:-4px; border-width:4px; border-style:solid; border-color:#1e293b transparent transparent transparent;"></div>
                    </div>
                @endif
            </div>
            @if($fotoDriveId)
                <div class="table-image-wrapper" style="cursor: default;">
                    {{-- src directo (no data-src lazy): el aux module no tiene
                         IntersectionObserver para promover data-src=>src, asi
                         que las fotos quedaban transparentes. El proxy local
                         /storage/google/{id}?sz=w300 cachea 21 dias en disco
                         + browser, asi que el render directo es rapido. --}}
                    <img src="{{ url('/storage/google/' . $fotoDriveId . '?sz=w300') }}"
                         alt="Foto"
                         loading="lazy"
                         decoding="async"
                         style="width:100%; height:100%; object-fit:contain;"
                         onerror="this.style.opacity='0.3';">
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
