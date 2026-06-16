@forelse($catalogos as $catalogo)
    @php
        $driveFileId = $catalogo->FOTO_REFERENCIAL
            ? basename(str_replace('/storage/google/', '', explode('?', $catalogo->FOTO_REFERENCIAL)[0]))
            : null;

        // Lista compacta de specs: solo se renderizan las que tienen valor.
        // Asi cada tarjeta muestra exactamente lo que el modelo tiene
        // registrado, sin filas vacias.
        $specs = array_filter([
            'Motor'        => $catalogo->MOTOR,
            'Combustible'  => $catalogo->COMBUSTIBLE,
            'Consumo'      => $catalogo->CONSUMO_PROMEDIO ? $catalogo->CONSUMO_PROMEDIO . ' L/día' : null,
            'Batería'      => $catalogo->TIPO_BATERIA,
            'Aceite Motor' => $catalogo->ACEITE_MOTOR,
            'Aceite Caja'  => $catalogo->ACEITE_CAJA,
            'Liga Freno'   => $catalogo->LIGA_FRENO,
            'Refrigerante' => $catalogo->REFRIGERANTE,
        ], fn ($v) => $v !== null && $v !== '');
    @endphp
    <div class="cat-card">
        {{-- Foto representativa con badges flotantes (Tipo arriba-izquierda,
             Año arriba-derecha) + acciones de editar/eliminar abajo-derecha.
             Con permiso, TODA la foto es clicable para cambiarla (mismo flujo que
             el catálogo de auxiliares); el overlay "Cambiar foto" aparece al hover.
             Carga directa (sin lazy) — Drive thumbnail w300 es liviano y
             evita el delay del IntersectionObserver. --}}
        <div class="cat-photo"
             @can('equipos.create')
                style="cursor:pointer;"
                onclick="catUploadPhoto('{{ $catalogo->ID_ESPEC }}')"
                title="Click para cambiar la foto del modelo"
             @endcan
        >
            @if($driveFileId)
                {{-- Sin loading=lazy a proposito: el scroll infinito ya difiere
                     las tarjetas fuera de pantalla; lazy encima retrasaba la foto
                     al scrollear. decoding=async no bloquea el render; opacity:0
                     + fade-in onload evita el flash de img rota mientras carga. --}}
                <img src="{{ url('/storage/google/' . $driveFileId . '?sz=w300') }}"
                     alt="{{ $catalogo->MODELO }}"
                     decoding="async"
                     style="opacity:0; transition:opacity 0.25s ease;"
                     onload="this.style.opacity=1"
                     onerror="this.outerHTML='<i class=&quot;material-icons placeholder&quot;>image_not_supported</i>'">
            @else
                <i class="material-icons placeholder">precision_manufacturing</i>
            @endif

            {{-- Tipo de equipo del catálogo (columna TIPO propia) ─ badge en la esquina
                 superior izquierda de la foto, mismo idioma visual que cat-anio-badge. --}}
            @if($catalogo->TIPO)
                <div class="cat-tipo-badges">
                    <span class="cat-tipo-badge" title="Tipo de equipo">{{ $catalogo->TIPO }}</span>
                </div>
            @endif

            <span class="cat-anio-badge">
                <i class="material-icons" style="font-size:12px;">event</i>
                {{ $catalogo->ANIO_ESPEC }}
            </span>

            {{-- Overlay "Cambiar foto" (solo hover) — afford visual del click sobre la
                 foto. pointer-events:none en CSS: el click lo recibe .cat-photo. --}}
            @can('equipos.create')
                <div class="cat-photo-overlay">
                    <i class="material-icons">photo_camera</i>
                    <span>Cambiar foto</span>
                </div>
            @endcan

            {{-- Acciones flotantes en la esquina inferior derecha de la foto.
                 stopPropagation evita que el click dispare también la subida de foto
                 del contenedor .cat-photo. --}}
            <a href="{{ route('catalogo.edit', $catalogo->ID_ESPEC) }}"
               class="cat-action-btn edit"
               title="Editar Modelo"
               onclick="event.stopPropagation();">
                <i class="material-icons">edit</i>
            </a>
            @can('equipos.assign')
                <button type="button"
                        class="cat-action-btn del"
                        onclick="event.stopPropagation(); confirmDeleteCatalogo('{{ $catalogo->ID_ESPEC }}', '{{ addslashes($catalogo->MODELO) }}')"
                        title="Eliminar Modelo">
                    <i class="material-icons">delete</i>
                </button>
            @endcan
        </div>

        {{-- Cuerpo: modelo + tabla compacta de todas las specs.
             (El tipo de equipo se renderiza arriba, como banda superior de la tarjeta.) --}}
        <div class="cat-body">
            <span class="cat-modelo">{{ $catalogo->MODELO }}</span>

            @if(!empty($specs))
                <div class="cat-specs">
                    @foreach($specs as $label => $value)
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
