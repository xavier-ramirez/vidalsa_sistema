{{-- Catálogo UNIFICADO: cada $item (array normalizado en CaracteristicaModeloController)
     es un VEHÍCULO (caracteristicas_modelo) o un AUXILIAR (equipos_auxiliares agrupado).
     Mismo estilo de tarjeta para ambos; un badge VEHÍCULO/AUXILIAR los distingue. --}}
@forelse($catalogos as $item)
    @php
        $esVeh = $item['clase'] === 'VEHICULO';
    @endphp
    <div class="cat-card">
        {{-- Foto + badges. Con permiso, toda la foto es clicable para cambiarla
             (VEHÍCULO → catUploadPhoto por id; AUXILIAR → auxCatUploadPhoto por grupo). --}}
        <div class="cat-photo"
             @can('equipos.create')
                style="cursor:pointer;"
                title="Click para cambiar la foto del modelo"
                @if($esVeh)
                    onclick="catUploadPhoto('{{ $item['id'] }}', this)"
                @else
                    data-tipo="{{ $item['tipo_raw'] ?? '' }}"
                    data-marca="{{ $item['marca'] ?? '' }}"
                    data-modelo="{{ $item['modelo'] }}"
                    data-anio="{{ $item['anio'] ?? '' }}"
                    onclick="auxCatUploadPhoto(this)"
                @endif
             @endcan
        >
            @if($item['foto_url'])
                <img src="{{ $item['foto_url'] }}"
                     alt="{{ $item['modelo'] }}"
                     loading="lazy"
                     decoding="async"
                     style="opacity:0; transition:opacity 0.25s ease;"
                     onload="this.style.opacity=1"
                     onerror="this.outerHTML='<i class=&quot;material-icons placeholder&quot;>image_not_supported</i>'">
            @else
                <i class="material-icons placeholder">{{ $item['placeholder'] }}</i>
            @endif

            {{-- Esquina sup. izquierda: distintivo VEHÍCULO / AUXILIAR. --}}
            <div class="cat-tipo-badges">
                <span class="cat-tipo-badge"
                      style="background:{{ $esVeh ? 'rgba(0,103,177,0.92)' : 'rgba(194,65,12,0.92)' }};"
                      title="{{ $esVeh ? 'Vehículo' : 'Auxiliar' }}">{{ $esVeh ? 'VEHÍCULO' : 'AUXILIAR' }}</span>
            </div>

            {{-- Esquina sup. derecha: año y cantidad asociada (equipos si es VEHÍCULO,
                 unidades registradas si es AUXILIAR). Solo se muestra si hay > 0. --}}
            @if($item['anio'])
                <span class="cat-anio-badge">
                    <i class="material-icons" style="font-size:12px;">event</i>
                    {{ $item['anio'] }}
                </span>
            @endif
            @if(!empty($item['total']))
                <span class="cat-anio-badge" style="top:{{ $item['anio'] ? '40px' : '10px' }}; background:rgba(15,23,42,0.85);" title="{{ $esVeh ? 'Equipos registrados' : 'Unidades registradas' }}">
                    <i class="material-icons" style="font-size:12px;">{{ $esVeh ? 'local_shipping' : 'inventory_2' }}</i>
                    {{ $item['total'] }}
                </span>
            @endif

            @can('equipos.create')
                <div class="cat-photo-overlay">
                    <i class="material-icons">photo_camera</i>
                    <span>Cambiar foto</span>
                </div>
            @endcan

            @can('super.admin')
                @if($item['foto_url'])
                    <button type="button" class="cat-action-btn cat-del-photo"
                            onclick="event.stopPropagation();
                                @if($esVeh)
                                    catDeletePhoto('{{ $item['id'] }}', this.closest('.cat-photo'));
                                @else
                                    auxCatDeletePhoto(this.closest('.cat-photo'));
                                @endif"
                            title="Eliminar foto">
                        <i class="material-icons">no_photography</i>
                    </button>
                @endif
            @endcan

            {{-- Acciones (solo VEHÍCULO: editar/eliminar el modelo del catálogo).
                 El <a> navega vía SPA (navigateTo) en vez de href directo para que
                 muestre spinner + transición sin recargar la página. --}}
            @if($esVeh)
                <a href="{{ route('catalogo.edit', $item['id']) }}"
                   class="cat-action-btn edit" title="Editar Modelo"
                   onclick="event.stopPropagation(); event.preventDefault(); if(window.navigateTo) window.navigateTo(this.href); else window.location.href = this.href;">
                    <i class="material-icons">edit</i>
                </a>
                @can('super.admin')
                    <button type="button" class="cat-action-btn del"
                            onclick="event.stopPropagation(); confirmDeleteCatalogo('{{ $item['id'] }}', '{{ addslashes($item['modelo']) }}')"
                            title="Eliminar Modelo">
                        <i class="material-icons">delete</i>
                    </button>
                @endcan
            @endif
        </div>

        <div class="cat-body">
            @php
                $showTipoPrefix = $item['tipo'] && !str_starts_with(mb_strtoupper($item['modelo']), mb_strtoupper($item['tipo']));
            @endphp
            <span class="cat-modelo">@if($showTipoPrefix){{ $item['tipo'] }} · @endif{{ $item['modelo'] }}</span>

            @if(!empty($item['specs']))
                <div class="cat-specs">
                    @foreach($item['specs'] as $label => $value)
                        <div class="cat-spec-row">
                            <span class="cat-spec-label">{{ $label }}</span>
                            <span class="cat-spec-value" title="{{ $value }}">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@empty
    <div class="cat-empty">
        <i class="material-icons">inventory_2</i>
        <div style="font-size:14px; font-weight:600; color:#475569; margin-bottom:4px;">Sin modelos registrados</div>
        <div style="font-size:12px;">No hay modelos que coincidan con los filtros seleccionados.</div>
    </div>
@endforelse
