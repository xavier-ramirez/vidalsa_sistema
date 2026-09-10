{{-- Filas de la tabla de equipos.

     Los estilos de estas filas viven en el <style> de admin/equipos/index.blade.php
     (bloque "Presentacion de las filas de la tabla de equipos"), NO aqui: las mismas
     clases las usa equipos-offline.js, que rearma este <tbody> cuando no hay internet.
     Con una sola copia en CSS las dos vistas no se pueden desincronizar.

     Ahi vive tambien la regla movil de .eq-modelo, que antes era un <style> dentro de
     este partial: el repintado offline reemplaza el <tbody> y se lo llevaba por delante,
     por eso equipos-offline.js tenia que reinyectar una copia a mano. --}}
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
        <td class="table-cell-custom table-cell-center eq-td-frente">
            @php $cfd = (int) ($equipo->CONFIRMADO_EN_SITIO ?? 0); @endphp
            <div class="tooltip-wrapper eq-frente-nom">
                <span class="eq-frente-linea">
                    {{ $equipo->frenteActual->NOMBRE_FRENTE ?? 'SIN ASIGNAR' }}
                    <i class="material-icons confirm-sitio-chip{{ $cfd ? ' eq-cfd' : '' }}@can('equipos.edit') eq-click @endcan"
                       data-equipo-id="{{ $equipo->ID_EQUIPO }}"
                       data-confirmado="{{ $cfd }}"
                       @can('equipos.edit')
                           onclick="event.stopPropagation(); window.toggleConfirmacionSitio(this)"
                           title="{{ $cfd ? 'Confirmado en sitio (click para quitar)' : 'Sin confirmar (click para confirmar)' }}"
                       @else
                           title="{{ $cfd ? 'Confirmado en sitio' : 'Sin confirmar' }}"
                       @endcan
                       >{{ $cfd ? 'check_circle' : 'radio_button_unchecked' }}</i>
                </span>

                @if($equipo->frenteActual && $equipo->frenteActual->ESTATUS_FRENTE === 'FINALIZADO')
                    <div class="eq-finalizado-wrap">
                        <span class="eq-finalizado">
                            <i class="material-icons">warning</i>
                            FINALIZADO
                        </span>
                    </div>
                @endif

                @if($equipo->DETALLE_UBICACION_ACTUAL)
                    <div class="tooltip-bubble">
                        📍 {{ $equipo->DETALLE_UBICACION_ACTUAL }}
                        <div class="eq-tip-flecha"></div>
                    </div>
                @endif
            </div>

            @if($driveFileId)
                <div class="table-image-wrapper eq-foto-wrap">
                    <img data-src="{{ url('/storage/google/' . $driveFileId . '?sz=w300') }}"
                         alt="Foto"
                         class="eq-foto">
                </div>
            @else
                <div class="table-image-wrapper placeholder">
                    <span class="material-icons">image_not_supported</span>
                </div>
            @endif
        </td>

        {{-- 2. TIPO ─ bold uppercase; el N° de etiqueta va AL LADO del tipo, como
             texto normal (sin contenedor de color), no debajo. --}}
        <td class="table-cell-custom eq-td-tipo">
            <div class="eq-linea-fuerte">
                {{ $equipo->tipo->nombre ?? '—' }}@if($equipo->NUMERO_ETIQUETA)<span class="eq-etiqueta"><i class="material-icons">tag</i>{{ $equipo->NUMERO_ETIQUETA }}</span>@endif
            </div>
            @if($equipo->CATEGORIA_FLOTA)
                <div class="eq-hide-mobile eq-sub">
                    {{ $equipo->CATEGORIA_FLOTA }}
                </div>
            @endif
            @if($equipo->CAPACIDAD)
                <div class="eq-sub eq-sub-junto">
                    {{ $equipo->CAPACIDAD }}
                </div>
            @endif
        </td>

        {{-- 3. MARCA / MODELO ─ marca queda en 13px (feedback del usuario:
             "menos la marca ya se ve bien"). Modelo y año subidos para
             igualar la legibilidad del resto. En móvil el modelo pasa a la misma
             línea de la marca, con su misma letra (negrita/negro) y en 11.5px
             (regla .eq-modelo del @media de index.blade.php, que pisa a la regla
             de escritorio por especificidad). --}}
        <td class="table-cell-custom eq-td-marca">
            <div class="eq-linea-fuerte">
                {{ $equipo->MARCA ?: '—' }}@if($equipo->MODELO)<span class="eq-modelo">{{ $equipo->MODELO }}</span>@endif
            </div>
            @if($equipo->ANIO)
                <div class="eq-hide-mobile eq-anio">
                    Año: {{ $equipo->ANIO }}
                </div>
            @endif
        </td>

        {{-- 4. SERIALES / PLACA / ID ─ 4 lineas compactas con labels muteados.
             La placa se destaca en azul cuando existe. Tamaño 14px (subido
             de 12.5 por feedback de legibilidad en pantalla grande). --}}
        <td class="table-cell-custom eq-td-serial">
            <div class="eq-ser-linea">
                <strong class="eq-lbl">S:</strong>
                <span class="eq-val">{{ $equipo->SERIAL_CHASIS ?: '—' }}</span>
            </div>
            @if($equipo->SERIAL_DE_MOTOR)
                <div class="eq-ser-linea eq-ser-sep">
                    <strong class="eq-lbl">M:</strong>
                    <span class="eq-val">{{ $equipo->SERIAL_DE_MOTOR }}</span>
                </div>
            @endif
            @if($equipo->documentacion && $equipo->documentacion->PLACA)
                <div class="eq-ser-corta eq-ser-sep">
                    <strong class="eq-lbl">P:</strong>
                    <span class="eq-val-placa">{{ $equipo->documentacion->PLACA }}</span>
                </div>
            @else
                <div class="eq-ser-simple eq-ser-sep">
                    <strong class="eq-lbl">P:</strong>
                    <span class="eq-val-vacio">Sin Placa</span>
                </div>
            @endif
            {{-- "ID: #<código de patio>" SOLO si el equipo tiene CODIGO_PATIO. Sin él
                 (p.ej. tipos que no usan código de patio) la línea no se muestra, para
                 no dejar un "ID: #" vacío. --}}
            @if($equipo->CODIGO_PATIO)
            <div class="eq-id-line eq-ser-corta eq-ser-sep">
                <strong class="eq-lbl">ID:</strong>
                <span class="eq-val-id">#{{ $equipo->CODIGO_PATIO }}</span>
            </div>
            @endif
        </td>

        {{-- 5. ESTATUS ─ trigger compacto estilo aux: 11px font, icono 14px,
             chip blanco con borde sutil + chevron. Click abre el menu compartido. --}}
        <td class="table-cell-custom eq-td-estatus">
            @can('equipos.edit')
                <div class="status-trigger-lite"
                    data-equipo-id="{{ $equipo->ID_EQUIPO }}"
                    data-status="{{ $equipo->ESTADO_OPERATIVO }}"
                    data-status-url="{{ route('equipos.changeStatus', $equipo->ID_EQUIPO) }}"
                    data-label="{{ optional($equipo->documentacion)->PLACA ?: ($equipo->SERIAL_CHASIS ?: ($equipo->CODIGO_PATIO ?: ('Equipo #'.$equipo->ID_EQUIPO))) }}"
                    @if($equipo->fallaAbierta) data-falla-id="{{ $equipo->fallaAbierta->ID_FALLA }}" data-falla-codigo="{{ $equipo->fallaAbierta->CODIGO_REPORTE }}" data-falla-tipo="{{ $equipo->fallaAbierta->TIPO_REPORTE }}" @endif
                    onclick="event.stopPropagation(); openSharedStatusMenu(this)"
                    style="--eq-st-color: {{ $currentConfig['color'] }}">
                    <div class="eq-status-izq">
                        <i class="material-icons">{{ $currentConfig['icon'] }}</i>
                        <span class="eq-status-txt">{{ $currentConfig['label'] }}</span>
                    </div>
                    <i class="material-icons eq-status-chevron">expand_more</i>
                </div>
            @else
                <div class="eq-status-fijo" style="--eq-st-bg: {{ $currentConfig['bg'] }}; --eq-st-color: {{ $currentConfig['color'] }}">
                    <i class="material-icons">{{ $currentConfig['icon'] }}</i>
                    <span>{{ $currentConfig['label'] }}</span>
                </div>
            @endcan
        </td>

        {{-- 6. ACCIONES ─ ojo de detalles, 72px ancho como aux. --}}
        <td class="table-cell-center eq-td-acciones">
            <div class="eq-acciones-wrap">
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
        <td colspan="6" class="table-empty-state">
            @if(request('search_query') || request('id_frente') || request('id_tipo'))
                <i class="material-icons">search_off</i>
                NO SE ENCONTRARON EQUIPOS CON LOS FILTROS APLICADOS.
            @else
                <i class="material-icons">filter_alt</i>
                SELECCIONE UN FILTRO PARA VER LOS EQUIPOS.
            @endif
        </td>
    </tr>
@endforelse
