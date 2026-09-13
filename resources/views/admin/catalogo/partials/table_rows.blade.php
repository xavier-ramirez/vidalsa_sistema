{{-- Catálogo UNIFICADO: cada $item (array normalizado en CaracteristicaModeloController)
     es un VEHÍCULO (caracteristicas_modelo), un VEHÍCULO SIN FICHA (equipos registrados de un
     modelo que todavía no tiene ficha — sale solo, como los auxiliares) o un AUXILIAR
     (equipos_auxiliares agrupado). Mismo estilo de tarjeta; los badges los distinguen.

     COLORES (solo vehículos): la ficha es una por modelo+año y cada color de sus unidades
     tiene su foto (catalogo_colores). Los chips de color cambian la foto de la tarjeta y
     dicen a qué se aplica "Cambiar foto" / borrar: al modelo (chip "Modelo") o a ese color.
     La lógica vive en catElegirColor / catUploadPhoto / catDeletePhoto (index.blade.php). --}}
@forelse($catalogos as $item)
    @php
        $esVeh     = $item['clase'] === 'VEHICULO';
        $sinFicha  = $esVeh && !empty($item['sin_ficha']);
        $colores   = $item['colores'] ?? [];
        // Unidades sueltas de un modelo+año que YA tiene ficha: primero se enlazan (botón
        // "Enlazar a su ficha"). Subir aquí pisaría la foto del modelo de esa ficha, que
        // esta tarjeta ni muestra.
        $subeFoto  = !($sinFicha && !empty($item['ficha_existente']));
    @endphp
    <div class="cat-card{{ $sinFicha ? ' cat-sin-ficha' : '' }}">
        {{-- Foto + badges. Con permiso, toda la foto es clicable para cambiarla
             (VEHÍCULO → catUploadPhoto; AUXILIAR → auxCatUploadPhoto por grupo). En un
             vehículo sin ficha, subir la foto crea antes la ficha (asegurarFicha). --}}
        <div class="cat-photo"
             @if($esVeh)
                data-id="{{ $item['id'] ?? '' }}"
                data-foto-modelo="{{ $item['foto_url'] ?? '' }}"
                data-color=""
                @if($sinFicha)
                    data-sin-ficha="1"
                    data-tipo="{{ $item['tipo'] ?? '' }}"
                    data-modelo="{{ $item['modelo'] }}"
                    data-anio="{{ $item['anio'] ?? '' }}"
                @endif
             @endif
             @can('equipos.create')
                @if($subeFoto)
                style="cursor:pointer;"
                title="Click para cambiar la foto"
                @if($esVeh)
                    onclick="catUploadPhoto(this)"
                @else
                    data-tipo="{{ $item['tipo_raw'] ?? '' }}"
                    data-marca="{{ $item['marca'] ?? '' }}"
                    data-modelo="{{ $item['modelo'] }}"
                    data-anio="{{ $item['anio'] ?? '' }}"
                    onclick="auxCatUploadPhoto(this)"
                @endif
                @else
                title="Enlaza estas unidades a su ficha para cambiar la foto"
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

            {{-- Esquina sup. izquierda: distintivo VEHÍCULO / AUXILIAR (+ SIN FICHA). --}}
            <div class="cat-tipo-badges">
                <span class="cat-tipo-badge"
                      style="background:{{ $esVeh ? 'rgba(0,103,177,0.92)' : 'rgba(194,65,12,0.92)' }};"
                      title="{{ $esVeh ? 'Vehículo' : 'Auxiliar' }}">{{ $esVeh ? 'VEHÍCULO' : 'AUXILIAR' }}</span>
                @if($sinFicha)
                    <span class="cat-tipo-badge" style="background:rgba(180,83,9,0.92);"
                          title="Hay equipos de este modelo pero todavía no tiene ficha técnica">SIN FICHA</span>
                @endif
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

            @if($subeFoto)
            @can('equipos.create')
                <div class="cat-photo-overlay">
                    <i class="material-icons">photo_camera</i>
                    <span class="cat-photo-overlay-txt">Cambiar foto</span>
                </div>
            @endcan
            @endif

            @can('super.admin')
                {{-- En un vehículo con ficha el botón existe siempre y se esconde cuando la foto
                     elegida (modelo o color) no hay: el chip de color puede cambiar eso. --}}
                @if($esVeh && !$sinFicha)
                    <button type="button" class="cat-action-btn cat-del-photo" @unless($item['foto_url']) hidden @endunless
                            onclick="event.stopPropagation(); catDeletePhoto(this.closest('.cat-photo'));"
                            title="Eliminar foto">
                        <i class="material-icons">no_photography</i>
                    </button>
                @elseif(!$esVeh && $item['foto_url'])
                    <button type="button" class="cat-action-btn cat-del-photo"
                            onclick="event.stopPropagation(); auxCatDeletePhoto(this.closest('.cat-photo'));"
                            title="Eliminar foto">
                        <i class="material-icons">no_photography</i>
                    </button>
                @endif
            @endcan

            {{-- Acciones (solo VEHÍCULO con ficha: editar/eliminar el modelo del catálogo).
                 El <a> navega vía SPA (navigateTo) en vez de href directo para que
                 muestre spinner + transición sin recargar la página. --}}
            @if($esVeh && !$sinFicha)
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

            {{-- Colores: el chip "Modelo" es la foto general; cada color, la suya. La cifra es
                 cuántas unidades hay de ese color. Sin foto propia se ve el ícono tachado. --}}
            @if(!empty($colores))
                <div class="cat-colores">
                    @if(!$sinFicha)
                        <button type="button" class="cat-color activo" data-color="" onclick="catElegirColor(this)" title="Foto del modelo">
                            <i class="material-icons">photo</i>Modelo
                        </button>
                    @endif
                    @foreach($colores as $c)
                        <button type="button" class="cat-color" data-color="{{ $c['color'] }}" data-foto="{{ $c['foto_url'] ?? '' }}"
                                @if($sinFicha) disabled @else onclick="catElegirColor(this)" @endif
                                title="{{ $c['color'] }}: {{ $c['total'] }} {{ $c['total'] === 1 ? 'unidad' : 'unidades' }}{{ $c['foto_url'] ? '' : ' · sin foto propia' }}">
                            <span class="cat-color-muestra" style="background:{{ $c['muestra'] }};"></span>{{ $c['color'] }}
                            @if($c['total'])<b>{{ $c['total'] }}</b>@endif
                            @unless($c['foto_url'])<i class="material-icons cat-color-sinfoto">no_photography</i>@endunless
                        </button>
                    @endforeach
                </div>
            @endif

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

            @if($sinFicha)
                @can('equipos.create')
                    {{-- Ya hay una ficha de este modelo+año (unidades registradas después, sin
                         enlazar): se enlazan a ella. Si no, se crea y se abre para completarla. --}}
                    <button type="button" class="cat-crear-ficha" onclick="catCrearFicha(this)">
                        <i class="material-icons">{{ $item['ficha_existente'] ? 'link' : 'note_add' }}</i>
                        {{ $item['ficha_existente'] ? 'Enlazar a su ficha' : 'Crear ficha' }}
                    </button>
                @endcan
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
