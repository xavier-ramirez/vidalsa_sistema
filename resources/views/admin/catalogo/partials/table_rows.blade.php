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
        {{-- Foto representativa con badge de año + acciones flotantes.
             Carga directa (sin lazy) — Drive thumbnail w300 es liviano y
             evita el delay del IntersectionObserver. --}}
        <div class="cat-photo">
            @if($driveFileId)
                <img src="https://drive.google.com/thumbnail?id={{ $driveFileId }}&sz=w300"
                     alt="{{ $catalogo->MODELO }}"
                     loading="eager"
                     decoding="async"
                     onerror="this.outerHTML='<i class=&quot;material-icons placeholder&quot;>image_not_supported</i>'">
            @else
                <i class="material-icons placeholder">precision_manufacturing</i>
            @endif

            <span class="cat-anio-badge">
                <i class="material-icons" style="font-size:12px;">event</i>
                {{ $catalogo->ANIO_ESPEC }}
            </span>

            {{-- Acciones flotantes en la esquina inferior derecha de la foto --}}
            <a href="{{ route('catalogo.edit', $catalogo->ID_ESPEC) }}"
               class="cat-action-btn edit"
               title="Editar Modelo">
                <i class="material-icons">edit</i>
            </a>
            @can('equipos.assign')
                <button type="button"
                        class="cat-action-btn del"
                        onclick="confirmDeleteCatalogo('{{ $catalogo->ID_ESPEC }}', '{{ addslashes($catalogo->MODELO) }}')"
                        title="Eliminar Modelo">
                    <i class="material-icons">delete</i>
                </button>
            @endcan
        </div>

        {{-- Cuerpo: modelo + tabla compacta de todas las specs --}}
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
