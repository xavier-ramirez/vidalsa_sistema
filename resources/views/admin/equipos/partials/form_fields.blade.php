<!-- General Info -->
<h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin-bottom: 20px;">Información General</h3>

<div class="grid-responsive-5">
    <!-- Tipo de Equipo -->
    <div>
        <label for="input_tipo_equipo" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Tipo de Equipo</label>
        <div class="custom-form-autocomplete">
            <input type="text" id="input_tipo_equipo" name="TIPO_EQUIPO"
                   class="form-input-custom @error('TIPO_EQUIPO') is-invalid @enderror"
                   value="{{ old('TIPO_EQUIPO', optional($equipo->tipo ?? null)->nombre ?? ($equipo->TIPO_EQUIPO ?? '')) }}"
                   placeholder="Seleccione o escriba..." 
                   required maxlength="35" autocomplete="off"
                   onfocus="showFormDropdown(this)" 
                   onblur="hideFormDropdownDelayed(this)" 
                   oninput="filterFormDropdown(this)">
            <div class="dropdown-list">
                @foreach($tipos_equipo as $tipo)
                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $tipo }}')">{{ $tipo }}</div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Marca (AJAX Autocomplete) -->
    <div>
        <label for="marca" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Marca <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <div class="custom-form-autocomplete">
            <input type="text" id="marca" name="MARCA" 
                   class="form-input-custom @error('MARCA') is-invalid @enderror" 
                   value="{{ old('MARCA', $equipo->MARCA ?? '') }}" 
                   placeholder="Escribe marca..." 
                   autocomplete="off"
                   onfocus="showFormDropdown(this)" 
                   onblur="hideFormDropdownDelayed(this)" 
                   oninput="filterFormDropdown(this)" 
                   required>
            <div class="dropdown-list">
                @foreach($marcas as $marca)
                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $marca }}')">{{ $marca }}</div>
                @endforeach
            </div>
        </div>
        @error('MARCA') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <style>
        .custom-form-autocomplete {
            position: relative;
            width: 100%;
        }
        .dropdown-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #cbd5e0;
            border-radius: 8px;
            margin-top: 4px;
            max-height: 250px;
            overflow-y: auto;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            z-index: 50;
            display: none;
        }
        .dropdown-item {
            padding: 10px 16px;
            border-bottom: 1px solid #f1f5f9;
            color: #4a5568;
            font-size: 14px;
            transition: all 0.2s;
        }
        .dropdown-item:last-child {
            border-bottom: none;
        }
        .dropdown-item:hover {
            background-color: #f7fafc;
            color: #2b6cb0;
            padding-left: 20px;
        }
    </style>

    <!-- Modelo (AJAX Autocomplete) -->
    <div>
        <label for="modelo" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
            Modelo <span style="color: var(--maquinaria-red);">*</span>
        </label>
        <div class="custom-form-autocomplete">
            <input type="text" id="modelo" name="MODELO" class="form-input-custom @error('MODELO') is-invalid @enderror" 
                   value="{{ old('MODELO', $equipo->MODELO ?? '') }}" 
                   placeholder="Escribe para buscar modelo..." 
                   autocomplete="off"
                   onfocus="showFormDropdown(this)" 
                   onblur="hideFormDropdownDelayed(this)" 
                   oninput="filterFormDropdown(this)" 
                   required>
            <div class="dropdown-list">
                @foreach($modelos as $modelo)
                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $modelo }}')">{{ $modelo }}</div>
                @endforeach
            </div>
        </div>

        @error('MODELO') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Año + Número de Etiqueta (Grid 2 columnas en 1 espacio de la grilla principal) -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
        <!-- Año -->
        <div>
            <label for="anio" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Año</label>
            <div class="custom-form-autocomplete">
                <input type="text" id="anio" name="ANIO"
                       class="form-input-custom @error('ANIO') is-invalid @enderror" 
                       value="{{ old('ANIO', $equipo->ANIO ?? '') }}" 
                       placeholder="Ej: 2020"
                       required 
                       maxlength="4" 
                       oninput="this.value = this.value.replace(/[^0-9]/g, ''); filterFormDropdown(this)"
                       onfocus="showFormDropdown(this)"
                       onblur="hideFormDropdownDelayed(this)"
                       autocomplete="off">
                <div class="dropdown-list">
                    @foreach($aniosList ?? [] as $anio)
                        <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $anio }}')">{{ $anio }}</div>
                    @endforeach
                </div>
            </div>
            @error('ANIO') <span class="error-message-inline">{{ $message }}</span> @enderror
        </div>

        <!-- Número de Etiqueta -->
        <div>
            <label for="numero_etiqueta" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                N Etiqueta
                @if(!auth()->user()?->can('equipos.edit'))
                    <i class="material-icons" style="font-size: 13px; color: #94a3b8; vertical-align: middle;" title="Solo editores pueden modificar este campo">lock</i>
                @endif
            </label>
            @if(auth()->user()?->can('equipos.edit'))
                <input type="text" id="numero_etiqueta" name="NUMERO_ETIQUETA"
                       class="form-input-custom @error('NUMERO_ETIQUETA') is-invalid @enderror"
                       value="{{ old('NUMERO_ETIQUETA', $equipo->NUMERO_ETIQUETA ?? '') }}"
                       placeholder="Ej: 001"
                       maxlength="10"
                       autocomplete="off">
            @else
                {{-- Campo solo lectura para usuarios sin permiso --}}
                <input type="text" id="numero_etiqueta"
                       class="form-input-custom"
                       value="{{ old('NUMERO_ETIQUETA', $equipo->NUMERO_ETIQUETA ?? '') }}"
                       readonly
                       style="background: #f8fafc; color: #94a3b8; cursor: not-allowed;"
                       title="No tienes permiso para editar este campo">
                {{-- No se envía name= para que no sea procesado en el POST --}}
            @endif
            @error('NUMERO_ETIQUETA') <span class="error-message-inline">{{ $message }}</span> @enderror
        </div>
    </div>
    
    <!-- Serial Chasis -->
    <div>
        <label for="serial_chasis" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Serial de Chasis</label>
        <input type="text" id="serial_chasis" name="SERIAL_CHASIS" class="form-input-custom @error('SERIAL_CHASIS') is-invalid @enderror" value="{{ old('SERIAL_CHASIS', $equipo->SERIAL_CHASIS ?? '') }}" placeholder="Serial único" required autocomplete="off">
        @error('SERIAL_CHASIS') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Serial Motor -->
    <div>
        <label for="serial_motor" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Serial de Motor</label>
        <input type="text" id="serial_motor" name="SERIAL_DE_MOTOR" class="form-input-custom @error('SERIAL_DE_MOTOR') is-invalid @enderror" value="{{ old('SERIAL_DE_MOTOR', $equipo->SERIAL_DE_MOTOR ?? '') }}" placeholder="Opcional" autocomplete="off">
        @error('SERIAL_DE_MOTOR') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Color (después de los seriales) -->
    <div>
        <label for="color" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Color</label>
        <input type="text" id="color" name="COLOR" class="form-input-custom @error('COLOR') is-invalid @enderror" value="{{ old('COLOR', $equipo->COLOR ?? '') }}" placeholder="Ej: BLANCO" maxlength="50" autocomplete="off" oninput="this.value = this.value.toUpperCase()">
        @error('COLOR') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Capacidad (string libre, igual que equipos auxiliares) -->
    <div>
        <label for="CAPACIDAD" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Capacidad</label>
        <input type="text" id="CAPACIDAD" name="CAPACIDAD" class="form-input-custom @error('CAPACIDAD') is-invalid @enderror" value="{{ old('CAPACIDAD', $equipo->CAPACIDAD ?? '') }}" placeholder="Ej: 20 TON, 300A, 50kVA" maxlength="80" autocomplete="off" oninput="this.value = this.value.toUpperCase()">
        @error('CAPACIDAD') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Categoría de Flota -->
    <div>
        <span id="lbl_categoria_title" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Categoría de Flota</span>
        <div class="custom-dropdown @error('CATEGORIA_FLOTA') is-invalid @enderror" id="categoriaSelect">
            <input type="hidden" name="CATEGORIA_FLOTA" id="input_categoria_flota" data-filter-value value="{{ old('CATEGORIA_FLOTA', $equipo->CATEGORIA_FLOTA ?? '') }}" aria-label="Categoría de Flota">
            <div class="dropdown-trigger" id="trigger_categoria" onclick="toggleDropdown('categoriaSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_categoria_title label_categoria_flota" style="cursor: default;">
                <span id="label_categoria_flota" data-filter-label>{{ old('CATEGORIA_FLOTA', $equipo->CATEGORIA_FLOTA ?? '') ?: 'SELECCIONE' }}</span>
                <i class="material-icons">expand_more</i>
            </div>
            <div class="dropdown-content">
                @foreach($categorias as $cat)
                    <div class="dropdown-item" onclick="selectOption('categoriaSelect', '{{ $cat }}', '{{ $cat }}', 'categoria_flota')">{{ $cat }}</div>
                @endforeach
            </div>
        </div>
        @error('CATEGORIA_FLOTA') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Frente de Trabajo -->
    <div>
        @php
            // En EDICIÓN el frente queda bloqueado: reasignar de frente es trabajo de
            // Movilización (que deja CONFIRMADO_EN_SITIO=0). El selector solo aplica al CREAR.
            $esEdicionEq = $equipo->exists ?? false;
            $frenteValEq = old('ID_FRENTE_ACTUAL', $equipo->ID_FRENTE_ACTUAL ?? '');
        @endphp
        <span id="lbl_frente_title" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Frente de Trabajo</span>
        <div class="custom-dropdown @error('ID_FRENTE_ACTUAL') is-invalid @enderror" id="frenteSelect">
            {{-- El hidden SIEMPRE envía el frente actual; update() lo descarta para conservarlo. --}}
            <input type="hidden" name="ID_FRENTE_ACTUAL" id="input_frente_trabajo" data-filter-value value="{{ $frenteValEq }}" aria-label="Frente de Trabajo">
            @if($esEdicionEq)
                {{-- EDICIÓN: trigger BLOQUEADO, sin dropdown (el frente se cambia por Movilización). --}}
                <div class="dropdown-trigger" id="trigger_frente" tabindex="-1" aria-disabled="true"
                     title="Para cambiar el frente usa Movilización" style="cursor: not-allowed; opacity: 0.75; background:#f1f5f9;">
                    <span id="label_frente_trabajo" data-filter-label>{{ $frentes[$frenteValEq] ?? 'SIN ASIGNAR' }}</span>
                    <i class="material-icons" style="color:#94a3b8;">lock</i>
                </div>
            @else
                <div class="dropdown-trigger" id="trigger_frente" onclick="toggleDropdown('frenteSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_frente_title label_frente_trabajo" style="cursor: default;">
                    <span id="label_frente_trabajo" data-filter-label>{{ $frentes[$frenteValEq] ?? 'SELECCIONE' }}</span>
                    <i class="material-icons">expand_more</i>
                </div>
                <div class="dropdown-content">
                    <div class="dropdown-item" onclick="selectOption('frenteSelect', '', 'Sin Asignar', 'frente_trabajo')">Sin Asignar</div>
                    @foreach($frentes as $id => $nombre)
                        <div class="dropdown-item" onclick="selectOption('frenteSelect', '{{ $id }}', '{{ $nombre }}', 'frente_trabajo')">{{ $nombre }}</div>
                    @endforeach
                </div>
            @endif
        </div>
        @if($esEdicionEq)
            <span style="margin-top:6px; font-size:11.5px; color:#64748b; display:flex; align-items:center; gap:4px;">
                <i class="material-icons" style="font-size:14px; color:#94a3b8;">info</i>
                El frente se cambia desde <strong>&nbsp;Movilización</strong>, no por edición.
            </span>
        @endif
        @error('ID_FRENTE_ACTUAL') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Detalle Ubicación -->
    <div>
        <label for="detalle_ubicacion_actual" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Sección / Detalle Específico</label>
        <input type="text" id="detalle_ubicacion_actual" name="DETALLE_UBICACION_ACTUAL" class="form-input-custom @error('DETALLE_UBICACION_ACTUAL') is-invalid @enderror" value="{{ old('DETALLE_UBICACION_ACTUAL', $equipo->DETALLE_UBICACION_ACTUAL ?? '') }}" placeholder="Ej: Fase 2, Estacionamiento..." autocomplete="off">
        @error('DETALLE_UBICACION_ACTUAL') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Estatus -->
    <div>
        <span id="lbl_estado_title" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Estatus</span>
        <div class="custom-dropdown" id="estadoSelect">
            <input type="hidden" name="ESTADO_OPERATIVO" id="input_estatus" data-filter-value value="{{ old('ESTADO_OPERATIVO', $equipo->ESTADO_OPERATIVO ?? 'OPERATIVO') }}" aria-label="Estatus Operativo">
            <div class="dropdown-trigger" id="trigger_estado" onclick="toggleDropdown('estadoSelect', event)" tabindex="0" role="button" aria-haspopup="listbox" aria-labelledby="lbl_estado_title label_estatus" style="cursor: default;">
                <span id="label_estatus" data-filter-label>{{ old('ESTADO_OPERATIVO', $equipo->ESTADO_OPERATIVO ?? 'OPERATIVO') == 'EN MANTENIMIENTO' ? 'MANTENIMIENTO' : old('ESTADO_OPERATIVO', $equipo->ESTADO_OPERATIVO ?? 'OPERATIVO') }}</span>
                <i class="material-icons">expand_more</i>
            </div>
            <div class="dropdown-content">
                @foreach(['OPERATIVO', 'INOPERATIVO', 'EN MANTENIMIENTO' => 'MANTENIMIENTO', 'DESINCORPORADO'] as $key => $val)
                    @php 
                        $val_display = is_numeric($key) ? $val : $val;
                        $val_value = is_numeric($key) ? $val : $key;
                    @endphp
                    <div class="dropdown-item" onclick="selectOption('estadoSelect', '{{ $val_value }}', '{{ $val_display }}', 'estatus')">{{ $val_display }}</div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Código de Patio -->
    <div>
        <label for="codigo_patio" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Código de Patio</label>
        <input type="text" id="codigo_patio" name="CODIGO_PATIO" class="form-input-custom @error('CODIGO_PATIO') is-invalid @enderror" value="{{ old('CODIGO_PATIO', $equipo->CODIGO_PATIO ?? '') }}" placeholder="Ej: V-01" autocomplete="off">
        @error('CODIGO_PATIO') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>
</div>

<!-- Catalog Linking Widget (Appears when model + year match catalog) -->
<div id="catalog_link_widget" style="display: none; margin: 16px 0; padding: 12px 14px; background: linear-gradient(135deg, #ebf8ff 0%, #f0f9ff 100%); border: 1px solid #0284c7; border-radius: 12px; box-shadow: 0 2px 4px -1px rgba(0,0,0,0.08);">
    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
        <div style="background: #0284c7; padding: 6px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="color: white; font-size: 18px;">inventory_2</i>
        </div>
        <div style="flex: 1;">
            <h4 style="margin: 0; color: #0c4a6e; font-size: 13.5px; font-weight: 800;">¡Encontramos este modelo en el Catálogo!</h4>
            <p style="margin: 2px 0 0 0; color: #075985; font-size: 12px;">Vincula las especificaciones técnicas si coinciden con el equipo.</p>
        </div>
    </div>

    <div id="catalog_preview" style="background: white; padding: 12px; border-radius: 10px; margin-bottom: 10px; border: 1px solid #bae6fd;">
        <!-- Catalog data will be inserted here by JavaScript -->
    </div>

    <div style="display: flex; gap: 8px; justify-content: flex-end;">
        <button type="button" onclick="ignoreCatalogSuggestion()" style="background: white; color: #64748b; border: 1px solid #cbd5e0; padding: 7px 14px; border-radius: 8px; font-weight: 600; font-size: 13px; transition: 0.2s;">
            <i class="material-icons" style="font-size: 16px; vertical-align: middle; margin-right: 4px;">close</i>
            Ignorar
        </button>
        <button type="button" onclick="linkToCatalog()" style="background: #0284c7; color: white; border: none; padding: 7px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; transition: 0.2s; box-shadow: 0 2px 4px rgba(2,132,199,0.2);">
            <i class="material-icons" style="font-size: 16px; vertical-align: middle; margin-right: 4px;">link</i>
            Vincular
        </button>
    </div>
</div>

<input type="hidden" id="linked_id_espec" name="ID_ESPEC" value="{{ old('ID_ESPEC', $equipo->ID_ESPEC ?? '') }}">

<!-- Documentation -->
<h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px;">Documentación Legal</h3>
<div class="grid-responsive-5">
    @php
        $hasProp = isset($equipo->documentacion) && $equipo->documentacion->LINK_DOC_PROPIEDAD;
        $hasPoliza = isset($equipo->documentacion) && $equipo->documentacion->LINK_POLIZA_SEGURO;
        $hasRotc = isset($equipo->documentacion) && $equipo->documentacion->LINK_ROTC;
        $hasRacda = isset($equipo->documentacion) && $equipo->documentacion->LINK_RACDA;
    @endphp
    <!-- Placa -->
    <div>
        <label for="placa" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Placa</label>
        <input type="text" id="placa" name="documentacion[PLACA]" class="form-input-custom @error('documentacion.PLACA') is-invalid @enderror" value="{{ old('documentacion.PLACA', $equipo->documentacion->PLACA ?? '') }}" placeholder="Ej: A00BC12">
        @error('documentacion.PLACA') <span class="error-message-inline">{{ $message }}</span> @enderror
    </div>

    <!-- Titular -->
    <div>
        <label for="titular" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Titular del Registro</label>
        <input type="text" id="titular" name="documentacion[NOMBRE_DEL_TITULAR]" class="form-input-custom" value="{{ old('documentacion.NOMBRE_DEL_TITULAR', $equipo->documentacion->NOMBRE_DEL_TITULAR ?? '') }}" placeholder="Nombre propietario" autocomplete="off">
    </div>

    <div style="position: relative;">
        <label for="nro_doc_propiedad" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Nro Título</label>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="text" id="nro_doc_propiedad" name="documentacion[NRO_DE_DOCUMENTO]" class="form-input-custom doc-meta" data-file-target="doc_propiedad" data-has-existing="{{ $hasProp ? 'true' : 'false' }}" value="{{ old('documentacion.NRO_DE_DOCUMENTO', $equipo->documentacion->NRO_DE_DOCUMENTO ?? '') }}" style="flex: 1;" autocomplete="off">

            <!-- Button Wrapper -->
            <div id="wrapper_propiedad" class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                @if($hasProp)
                    <a href="{{ $equipo->documentacion->LINK_DOC_PROPIEDAD }}" target="_blank" title="Ver documento: Propiedad"
                       style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:linear-gradient(135deg,#1e3a5f,#2563eb); box-shadow:0 2px 6px rgba(37,99,235,0.35);">
                        <i class="material-icons" style="font-size:17px; color:white;">description</i>
                    </a>
                    <label for="doc_propiedad" title="Reemplazar PDF"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; color:#64748b; cursor:pointer; background:#f1f5f9;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="material-icons" style="font-size:16px;">edit</i>
                    </label>
                @else
                    <label for="doc_propiedad" title="Cargar PDF de Propiedad"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                        <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                    </label>
                @endif
            </div>
            <input type="file" id="doc_propiedad" name="doc_propiedad" class="doc-file" data-meta-target="nro_doc_propiedad" accept=".pdf" style="display: none;">

        </div>
        <small id="error_meta_nro_doc_propiedad" style="display:none; color: #e53e3e; font-size: 11px; margin-top: 2px;">Escriba el Nro. de Documento</small>
        @error('doc_propiedad') <div style="color: var(--maquinaria-red); font-size: 12px; margin-top: 4px;">{{ $message }}</div> @enderror

    </div>

    <!-- Póliza -->
    <div>
        <label for="seguro" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Póliza</label>
        <div class="custom-form-autocomplete">
            <input type="text" id="seguro" name="documentacion[NOMBRE_SEGURO]" 
                   class="form-input-custom" 
                   value="{{ old('documentacion.NOMBRE_SEGURO', $equipo->documentacion->seguro->NOMBRE_ASEGURADORA ?? '') }}" 
                   placeholder="Escriba aseguradora." 
                   autocomplete="off"
                   onfocus="showFormDropdown(this)" 
                   onblur="hideFormDropdownDelayed(this)" 
                   oninput="filterFormDropdown(this)">
            <div class="dropdown-list">
                @foreach($seguros as $nombre)
                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $nombre }}')">{{ $nombre }}</div>
                @endforeach
            </div>
        </div>
    </div>

    <div style="position: relative;">
        <label for="venc_poliza" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Vencimiento Póliza</label>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="date" id="venc_poliza" name="documentacion[FECHA_VENC_POLIZA]" class="form-input-custom doc-meta" data-file-target="poliza_seguro" data-has-existing="{{ $hasPoliza ? 'true' : 'false' }}" value="{{ old('documentacion.FECHA_VENC_POLIZA', $equipo->documentacion->FECHA_VENC_POLIZA ?? '') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">

            <div id="wrapper_poliza" class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                @if($hasPoliza)
                    <a href="{{ $equipo->documentacion->LINK_POLIZA_SEGURO }}" target="_blank" title="Ver documento: Póliza"
                       style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:linear-gradient(135deg,#1e3a5f,#2563eb); box-shadow:0 2px 6px rgba(37,99,235,0.35);">
                        <i class="material-icons" style="font-size:17px; color:white;">description</i>
                    </a>
                    <label for="poliza_seguro" title="Reemplazar PDF"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; color:#64748b; cursor:pointer; background:#f1f5f9;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="material-icons" style="font-size:16px;">edit</i>
                    </label>
                @else
                    <label for="poliza_seguro" title="Cargar PDF de Póliza"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                        <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                    </label>
                @endif
            </div>
            <input type="file" id="poliza_seguro" name="poliza_seguro" class="doc-file" data-meta-target="venc_poliza" accept=".pdf" style="display: none;">

        </div>
        <small id="error_meta_venc_poliza" style="display:none; color: #e53e3e; font-size: 11px; margin-top: 2px;">Seleccione la Fecha de Vencimiento de Póliza</small>
        @error('poliza_seguro') <div style="color: var(--maquinaria-red); font-size: 12px; margin-top: 4px;">{{ $message }}</div> @enderror
    </div>

    <div style="position: relative;">
        <label for="fecha_rotc" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Fecha ROTC</label>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="date" id="fecha_rotc" name="documentacion[FECHA_ROTC]" class="form-input-custom doc-meta" data-file-target="doc_rotc" data-has-existing="{{ $hasRotc ? 'true' : 'false' }}" value="{{ old('documentacion.FECHA_ROTC', $equipo->documentacion->FECHA_ROTC ?? '') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">

            <div id="wrapper_rotc" class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                @if($hasRotc)
                    <a href="{{ $equipo->documentacion->LINK_ROTC }}" target="_blank" title="Ver documento: ROTC"
                       style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:linear-gradient(135deg,#1e3a5f,#2563eb); box-shadow:0 2px 6px rgba(37,99,235,0.35);">
                        <i class="material-icons" style="font-size:17px; color:white;">description</i>
                    </a>
                    <label for="doc_rotc" title="Reemplazar PDF"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; color:#64748b; cursor:pointer; background:#f1f5f9;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="material-icons" style="font-size:16px;">edit</i>
                    </label>
                @else
                    <label for="doc_rotc" title="Cargar PDF ROTC"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                        <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                    </label>
                @endif
            </div>
            <input type="file" id="doc_rotc" name="doc_rotc" class="doc-file" data-meta-target="fecha_rotc" accept=".pdf" style="display: none;">

        </div>
        <small id="error_meta_fecha_rotc" style="display:none; color: #e53e3e; font-size: 11px; margin-top: 2px;">Seleccione la Fecha ROTC</small>
        @error('doc_rotc') <div style="color: var(--maquinaria-red); font-size: 12px; margin-top: 4px;">{{ $message }}</div> @enderror

    </div>

    <div style="position: relative;">
        <label for="fecha_racda" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Fecha RACDA</label>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="date" id="fecha_racda" name="documentacion[FECHA_RACDA]" class="form-input-custom doc-meta" data-file-target="doc_racda" data-has-existing="{{ $hasRacda ? 'true' : 'false' }}" value="{{ old('documentacion.FECHA_RACDA', $equipo->documentacion->FECHA_RACDA ?? '') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">

           <div id="wrapper_racda" class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                @if($hasRacda)
                    <a href="{{ $equipo->documentacion->LINK_RACDA }}" target="_blank" title="Ver documento: RACDA"
                       style="text-decoration:none; display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:linear-gradient(135deg,#1e3a5f,#2563eb); box-shadow:0 2px 6px rgba(37,99,235,0.35);">
                        <i class="material-icons" style="font-size:17px; color:white;">description</i>
                    </a>
                    <label for="doc_racda" title="Reemplazar PDF"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; color:#64748b; cursor:pointer; background:#f1f5f9;"
                           onmouseover="this.style.background='#e2e8f0'" onmouseout="this.style.background='#f1f5f9'">
                        <i class="material-icons" style="font-size:16px;">edit</i>
                    </label>
                @else
                    <label for="doc_racda" title="Cargar PDF RACDA"
                           style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                        <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                    </label>
                @endif
            </div>
            <input type="file" id="doc_racda" name="doc_racda" class="doc-file" data-meta-target="fecha_racda" accept=".pdf" style="display: none;">

        </div>
        <small id="error_meta_fecha_racda" style="display:none; color: #e53e3e; font-size: 11px; margin-top: 2px;">Seleccione la Fecha RACDA</small>
    </div>

    
    <!-- Link GPS -->
    <div>
        <label for="link_gps" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Link GPS</label>
        <div style="display: flex; align-items: center; gap: 8px;">
            <input type="url" id="link_gps" name="LINK_GPS" class="form-input-custom" value="{{ old('LINK_GPS', $equipo->LINK_GPS ?? '') }}" placeholder="https://..." style="flex: 1;">
            <span style="color: #10b981; display: flex; align-items: center;"><i class="material-icons" style="font-size: 20px;">gps_fixed</i></span>
        </div>
    </div>

    {{-- Foto del equipo: SOLO al editar una unidad existente. En el alta (create)
         no se carga foto por unidad — la foto principal viene del catálogo del modelo
         (FOTO_REFERENCIAL) y se gestiona en /admin/catalogo. --}}
    @if($equipo->exists)
    <!-- Foto -->
    <div>
        <label for="foto_equipo" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Foto del Equipo</label>
        <div style="display: flex; gap: 10px; align-items: center; height: 38px;">
            <label for="foto_equipo" id="preview_equipo" style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; overflow: hidden; background: white; border-radius: 8px; border: 1px solid #cbd5e0; flex-shrink: 0; transition: all 0.2s;" title="Foto del Equipo" onmouseover="this.style.borderColor='var(--maquinaria-blue)';" onmouseout="this.style.borderColor='#cbd5e0';">
                @if($equipo->FOTO_EQUIPO)
                    <img src="{{ asset($equipo->FOTO_EQUIPO) }}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;">
                @else
                    <i class="material-icons" style="font-size: 16px; color: #cbd5e0;">photo_camera</i>
                @endif
            </label>
            <input type="file" id="foto_equipo" name="foto_equipo" accept="image/*" style="display: none;">
            <div style="font-size: 10px; color: #718096; line-height: 1.2;">Click para cambiar</div>
        </div>
    </div>
    @endif
</div>

<!-- Logic moved to public/js/maquinaria/form_logic.js for CSP compliance -->
