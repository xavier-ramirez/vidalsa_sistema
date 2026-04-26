@forelse($catalogos as $catalogo)
    @php
        $driveFileId = $catalogo->FOTO_REFERENCIAL
            ? basename(str_replace('/storage/google/', '', explode('?', $catalogo->FOTO_REFERENCIAL)[0]))
            : null;
    @endphp
    <div class="cat-card">
        {{-- Foto representativa con badge de año + acciones flotantes --}}
        <div class="cat-photo">
            @if($driveFileId)
                <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                     data-src="https://drive.google.com/thumbnail?id={{ $driveFileId }}&sz=w400"
                     alt="{{ $catalogo->MODELO }}"
                     class="lazy-catalog-img"
                     style="opacity:0; transition:opacity 0.3s;"
                     onerror="this.outerHTML='<i class=&quot;material-icons placeholder&quot;>image_not_supported</i>'">
            @else
                <i class="material-icons placeholder">precision_manufacturing</i>
            @endif

            <span class="cat-anio-badge">
                <i class="material-icons" style="font-size:13px;">event</i>
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

        {{-- Cuerpo: modelo, chips de motor/combustible/batería, lubricantes --}}
        <div class="cat-body">
            <span class="cat-modelo">{{ $catalogo->MODELO }}</span>

            <div class="cat-meta">
                @if($catalogo->MOTOR)
                    <span class="cat-chip" title="Motor">
                        <i class="material-icons">settings</i>{{ \Illuminate\Support\Str::limit($catalogo->MOTOR, 24) }}
                    </span>
                @endif
                @if($catalogo->COMBUSTIBLE)
                    <span class="cat-chip" title="Combustible">
                        <i class="material-icons">local_gas_station</i>{{ $catalogo->COMBUSTIBLE }}
                    </span>
                @endif
                @if($catalogo->TIPO_BATERIA)
                    <span class="cat-chip" title="Batería">
                        <i class="material-icons">battery_charging_full</i>{{ $catalogo->TIPO_BATERIA }}
                    </span>
                @endif
                @if($catalogo->CONSUMO_PROMEDIO)
                    <span class="cat-chip" title="Consumo">
                        <i class="material-icons">speed</i>{{ $catalogo->CONSUMO_PROMEDIO }} L/día
                    </span>
                @endif
            </div>

            {{-- Lubricantes y fluidos: solo se renderiza el bloque si hay
                 al menos un dato — evita seccion vacia en modelos sin info --}}
            @if($catalogo->ACEITE_MOTOR || $catalogo->ACEITE_CAJA || $catalogo->LIGA_FRENO || $catalogo->REFRIGERANTE)
                <div class="cat-section">
                    <div class="cat-section-title">
                        <i class="material-icons" style="font-size:12px;color:#3b82f6;">water_drop</i>
                        Lubricantes y Fluidos
                    </div>
                    <div class="cat-data-grid">
                        @if($catalogo->ACEITE_MOTOR)
                            <div class="cat-data-cell">
                                <div class="cat-data-label">Aceite Motor</div>
                                <div class="cat-data-value">{{ $catalogo->ACEITE_MOTOR }}</div>
                            </div>
                        @endif
                        @if($catalogo->ACEITE_CAJA)
                            <div class="cat-data-cell">
                                <div class="cat-data-label">Aceite Caja</div>
                                <div class="cat-data-value">{{ $catalogo->ACEITE_CAJA }}</div>
                            </div>
                        @endif
                        @if($catalogo->LIGA_FRENO)
                            <div class="cat-data-cell">
                                <div class="cat-data-label">Liga Freno</div>
                                <div class="cat-data-value">{{ $catalogo->LIGA_FRENO }}</div>
                            </div>
                        @endif
                        @if($catalogo->REFRIGERANTE)
                            <div class="cat-data-cell">
                                <div class="cat-data-label">Refrigerante</div>
                                <div class="cat-data-value">{{ $catalogo->REFRIGERANTE }}</div>
                            </div>
                        @endif
                    </div>
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
