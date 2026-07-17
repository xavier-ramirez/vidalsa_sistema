{{-- Mobile-only: oculta CATEGORÍA/MODELO/AÑO en tarjetas pequeñas. Va UNA sola vez
     antes del bucle (antes se emitía por cada fila). El repintado offline inyecta su
     propia copia porque reemplaza el tbody y este style desaparece (equipos-offline.js). --}}
<style>@media(max-width:900px){.eq-hide-mobile{display:none!important;}}</style>
@forelse($equipos as $equipo)
    @php
        // Foto: prioriza FOTO_REFERENCIAL del catalogo (ID_ESPEC), cae a FOTO_EQUIPO
        $fotoToShow = ($equipo->especificaciones && $equipo->especificaciones->FOTO_REFERENCIAL)
                      ? $equipo->especificaciones->FOTO_REFERENCIAL
                      : $equipo->FOTO_EQUIPO;
        $driveFileId = $fotoToShow ? basename(str_replace('/storage/google/', '', explode('?', $fotoToShow)[0])) : null;

        // Estatus: paleta uniformada con /admin/equipos-auxiliares
        $statusConfig = [
            'OPERATIVO'        => ['color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'check_circle', 'label' => 'OPERATIVO'],
            'INOPERATIVO'      => ['color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'cancel',       'label' => 'INOPERATIVO'],
            'EN MANTENIMIENTO' => ['color' => '#d97706', 'bg' => '#fffbeb', 'icon' => 'engineering',  'label' => 'MANTENIMIENTO'],
            'DESINCORPORADO'   => ['color' => '#475569', 'bg' => '#f1f5f9', 'icon' => 'archive',      'label' => 'DESINCORP.'],
        ];
        $currentConfig = $statusConfig[$equipo->ESTADO_OPERATIVO] ?? $statusConfig['DESINCORPORADO'];
    @endphp
    <tr>
        {{-- 1. FRENTE + FOTO ─ tipografia uniformada con aux: frente UPPERCASE
             bold 11px arriba, foto 170x105 abajo. La burbuja de DETALLE_UBICACION
             se mantiene (tooltip-bubble + .admin-table tr:hover trigger). --}}
        <td class="table-cell-custom table-cell-center" style="padding: 6px 4px; width: 150px;">
            @php $cfd = (int) ($equipo->CONFIRMADO_EN_SITIO ?? 0); @endphp
            <div class="tooltip-wrapper" style="font-size: 13px; color: #000; margin-bottom: 5px; line-height: 1.25; font-weight: 700; text-align: center; text-transform: uppercase; word-wrap: break-word; position: relative; cursor: default;">
                <span style="display:inline-flex; align-items:center; gap:3px; justify-content:center;">
                    {{ $equipo->frenteActual->NOMBRE_FRENTE ?? 'SIN ASIGNAR' }}
                    <i class="material-icons confirm-sitio-chip"
                       data-equipo-id="{{ $equipo->ID_EQUIPO }}"
                       data-confirmado="{{ $cfd }}"
                       @can('equipos.edit')
                           onclick="event.stopPropagation(); window.toggleConfirmacionSitio(this)"
                           title="{{ $cfd ? 'Confirmado en sitio (click para quitar)' : 'Sin confirmar (click para confirmar)' }}"
                       @else
                           title="{{ $cfd ? 'Confirmado en sitio' : 'Sin confirmar' }}"
                       @endcan
                       style="font-size:14px; color:{{ $cfd ? '#16a34a' : '#cbd5e0' }};@can('equipos.edit') cursor:pointer;@endcan">{{ $cfd ? 'check_circle' : 'radio_button_unchecked' }}</i>
                </span>

                @if($equipo->frenteActual && $equipo->frenteActual->ESTATUS_FRENTE === 'FINALIZADO')
                    <div style="display:flex; align-items:center; justify-content:center; gap:3px; margin-top:3px;">
                        <span style="background:#fef2f2; color:#dc2626; padding:1px 6px; border-radius:8px; font-size:9px; font-weight:700; display:inline-flex; align-items:center; gap:2px; border:1px solid #fecaca;">
                            <i class="material-icons" style="font-size:10px;">warning</i>
                            FINALIZADO
                        </span>
                    </div>
                @endif

                @if($equipo->DETALLE_UBICACION_ACTUAL)
                    <div class="tooltip-bubble" style="pointer-events:none; opacity:0; visibility:hidden; position:absolute; bottom:100%; left:0; transform:translateY(5px); background:#1e293b; color:#fff; padding:6px 10px; border-radius:6px; font-size:11px; font-weight:500; white-space:normal; width:max-content; max-width:220px; word-wrap:break-word; text-align:center; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); transition:all 0.2s ease-in-out; z-index:50; margin-bottom:5px;">
                        📍 {{ $equipo->DETALLE_UBICACION_ACTUAL }}
                        <div style="position:absolute; top:100%; left:75px; margin-left:-4px; border-width:4px; border-style:solid; border-color:#1e293b transparent transparent transparent;"></div>
                    </div>
                @endif
            </div>

            @if($driveFileId)
                <div class="table-image-wrapper" style="cursor:default;">
                    <img data-src="{{ url('/storage/google/' . $driveFileId . '?sz=w300') }}"
                         alt="Foto"
                         style="width:100%; height:100%; object-fit:contain; opacity:0; transition:opacity 0.4s;">
                </div>
            @else
                <div class="table-image-wrapper placeholder">
                    <span class="material-icons">image_not_supported</span>
                </div>
            @endif
        </td>

        {{-- 2. TIPO ─ bold uppercase; el N° de etiqueta va AL LADO del tipo, como
             texto normal (sin contenedor de color), no debajo. --}}
        <td class="table-cell-custom" style="font-size: 14.5px; color: #000; word-wrap: break-word;">
            <div style="font-weight: 700; text-transform: uppercase; line-height: 1.3;">
                {{ $equipo->tipo->nombre ?? '—' }}@if($equipo->NUMERO_ETIQUETA)<span style="font-weight: 700; color: var(--maquinaria-blue); margin-left: 6px; white-space: nowrap;"><i class="material-icons" style="font-size: 13px; vertical-align: -2px;">tag</i>{{ $equipo->NUMERO_ETIQUETA }}</span>@endif
            </div>
            @if($equipo->CATEGORIA_FLOTA)
                <div class="eq-hide-mobile" style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-top: 5px; letter-spacing: 0.3px;">
                    {{ $equipo->CATEGORIA_FLOTA }}
                </div>
            @endif
            @if($equipo->CAPACIDAD)
                <div style="font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-top: 3px; letter-spacing: 0.3px;">
                    {{ $equipo->CAPACIDAD }}
                </div>
            @endif
        </td>

        {{-- 3. MARCA / MODELO ─ marca queda en 13px (feedback del usuario:
             "menos la marca ya se ve bien"). Modelo y año subidos para
             igualar la legibilidad del resto. --}}
        <td class="table-cell-custom" style="font-size: 13px; color: #000; word-wrap: break-word;">
            <div style="font-weight: 700; text-transform: uppercase; line-height: 1.3;">
                {{ $equipo->MARCA ?: '—' }}
            </div>
            @if($equipo->MODELO)
                <div class="eq-hide-mobile" style="font-size: 13.5px; color: #475569; font-weight: 500; text-transform: uppercase; margin-top: 4px; line-height: 1.3;">
                    {{ $equipo->MODELO }}
                </div>
            @endif
            @if($equipo->ANIO)
                <div class="eq-hide-mobile" style="font-size: 12.5px; color: #64748b; margin-top: 5px; font-weight: 500;">
                    Año: {{ $equipo->ANIO }}
                </div>
            @endif
        </td>

        {{-- 4. SERIALES / PLACA / ID ─ 4 lineas compactas con labels muteados.
             La placa se destaca en azul cuando existe. Tamaño 14px (subido
             de 12.5 por feedback de legibilidad en pantalla grande). --}}
        <td class="table-cell-custom" style="font-size: 14px; color: #4a5568;">
            <div style="line-height: 1.5; word-break: break-all;">
                <strong style="color:#64748b;">S:</strong>
                <span style="color:#1e293b; font-weight:600; text-transform:uppercase;">{{ $equipo->SERIAL_CHASIS ?: '—' }}</span>
            </div>
            @if($equipo->SERIAL_DE_MOTOR)
                <div style="line-height: 1.5; margin-top: 3px; word-break: break-all;">
                    <strong style="color:#64748b;">M:</strong>
                    <span style="color:#1e293b; font-weight:600; text-transform:uppercase;">{{ $equipo->SERIAL_DE_MOTOR }}</span>
                </div>
            @endif
            @if($equipo->documentacion && $equipo->documentacion->PLACA)
                <div style="line-height: 1.4; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                    <strong style="color:#64748b;">P:</strong>
                    <span style="color:var(--maquinaria-blue); font-weight:700; text-transform:uppercase;">{{ $equipo->documentacion->PLACA }}</span>
                </div>
            @else
                <div style="line-height: 1.4; margin-top: 3px;">
                    <strong style="color:#64748b;">P:</strong>
                    <span style="color:#a0aec0; font-style:italic;">Sin Placa</span>
                </div>
            @endif
            {{-- "ID: #<código de patio>" SOLO si el equipo tiene CODIGO_PATIO. Sin él
                 (p.ej. tipos que no usan código de patio) la línea no se muestra, para
                 no dejar un "ID: #" vacío. --}}
            @if($equipo->CODIGO_PATIO)
            <div class="eq-id-line" style="line-height: 1.4; margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                <strong style="color:#64748b;">ID:</strong>
                <span style="color:#1e293b; font-weight:600;">#{{ $equipo->CODIGO_PATIO }}</span>
            </div>
            @endif
        </td>

        {{-- 5. ESTATUS ─ trigger compacto estilo aux: 11px font, icono 14px,
             chip blanco con borde sutil + chevron. Click abre el menu compartido. --}}
        <td class="table-cell-custom" style="padding: 8px 2px; width: 145px;">
            @can('equipos.edit')
                <div class="status-trigger-lite"
                    data-equipo-id="{{ $equipo->ID_EQUIPO }}"
                    data-status="{{ $equipo->ESTADO_OPERATIVO }}"
                    data-status-url="{{ route('equipos.changeStatus', $equipo->ID_EQUIPO) }}"
                    data-label="{{ optional($equipo->documentacion)->PLACA ?: ($equipo->SERIAL_CHASIS ?: ($equipo->CODIGO_PATIO ?: ('Equipo #'.$equipo->ID_EQUIPO))) }}"
                    @if($equipo->fallaAbierta) data-falla-id="{{ $equipo->fallaAbierta->ID_FALLA }}" data-falla-codigo="{{ $equipo->fallaAbierta->CODIGO_REPORTE }}" data-falla-tipo="{{ $equipo->fallaAbierta->TIPO_REPORTE }}" @endif
                    onclick="event.stopPropagation(); openSharedStatusMenu(this)"
                    style="padding: 6px 10px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 5px; font-size: 12.5px; font-weight: 700; background: white; border: 1px solid #e2e8f0; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                    <div style="display: flex; align-items: center; gap: 5px; color: {{ $currentConfig['color'] }};">
                        <i class="material-icons" style="font-size: 16px;">{{ $currentConfig['icon'] }}</i>
                        <span style="color: #334155; text-transform: uppercase;">{{ $currentConfig['label'] }}</span>
                    </div>
                    <i class="material-icons" style="font-size: 16px; color: #94a3b8;">expand_more</i>
                </div>
            @else
                <div style="padding: 6px 10px; border-radius: 8px; display: flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; background: {{ $currentConfig['bg'] }}; border: 1px solid {{ $currentConfig['bg'] }}; color: {{ $currentConfig['color'] }}; text-transform: uppercase;">
                    <i class="material-icons" style="font-size: 16px;">{{ $currentConfig['icon'] }}</i>
                    <span>{{ $currentConfig['label'] }}</span>
                </div>
            @endcan
        </td>

        {{-- 6. ACCIONES ─ ojo de detalles, 72px ancho como aux. --}}
        <td class="table-cell-center" style="padding: 8px 2px; width: 44px; text-align: center; vertical-align: middle;">
            <div style="display:flex; justify-content:center; align-items:center; gap:4px;">
                <button type="button"
                    data-equipo-id="{{ $equipo->ID_EQUIPO }}"
                    data-codigo="{{ $equipo->CODIGO_PATIO }}"
                    data-chasis="{{ $equipo->SERIAL_CHASIS }}"
                    data-placa="{{ optional($equipo->documentacion)->PLACA ?? 'N/A' }}"
                    data-tipo="{{ optional($equipo->tipo)->nombre ?? 'SIN TIPO' }}"
                    data-anchor-id="{{ $equipo->ID_ANCLAJE ?? '' }}"
                    data-frente-id="{{ $equipo->ID_FRENTE_ACTUAL }}"
                    data-rol-anclaje="{{ optional($equipo->tipo)->ROL_ANCLAJE ?? 'NEUTRO' }}"
                    data-anchor-code="{{ optional($equipo->ancladoA)->CODIGO_PATIO ?? '' }}"
                    data-anchor-placa="{{ optional(optional($equipo->ancladoA)->documentacion)->PLACA ?? '' }}"
                    data-anchor-serial="{{ optional($equipo->ancladoA)->SERIAL_CHASIS ?? '' }}"
                    data-anchor-rol="{{ optional(optional($equipo->ancladoA)->tipo)->ROL_ANCLAJE ?? '' }}"
                    data-anchor-tipo-nombre="{{ optional(optional($equipo->ancladoA)->tipo)->nombre ?? 'Equipo' }}"
                    data-confirmado="{{ (int) ($equipo->CONFIRMADO_EN_SITIO ?? 0) }}"
                    onclick="showDetailsImproved(this, event)"
                    class="btn-details-mini" title="Ver Detalles">
                    <i class="material-icons">visibility</i>
                </button>
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="6" class="table-empty-state" style="text-align:center; padding:40px; color:#94a3b8;">
            @if(request('search_query') || request('id_frente') || request('id_tipo'))
                <i class="material-icons" style="font-size:48px; display:block; margin:0 auto 10px auto; color:#cbd5e0;">search_off</i>
                NO SE ENCONTRARON EQUIPOS CON LOS FILTROS APLICADOS.
            @else
                <i class="material-icons" style="font-size:48px; display:block; margin:0 auto 10px auto; color:#cbd5e0;">filter_alt</i>
                SELECCIONE UN FILTRO PARA VER LOS EQUIPOS.
            @endif
        </td>
    </tr>
@endforelse
