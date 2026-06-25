@extends('layouts.estructura_base')

@section('title', 'Registrar Equipo')

@section('content')
<style>
    @media (max-width: 768px) {
        body:has(#formUnificadoCard) .page-title-card {
            margin-bottom: 6px !important;
            padding: 4px 0 !important;
        }
        body:has(#formUnificadoCard) .main-viewport {
            width: 100% !important;
            max-width: 100% !important;
            padding-left: 8px !important;
            padding-right: 8px !important;
            box-sizing: border-box !important;
        }
        body:has(#formUnificadoCard) .admin-card {
            width: 100% !important;
            max-width: 100% !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
    }
    .cat-card {
        flex: 1;
        min-width: 100px;
        padding: 14px 10px;
        border-radius: 12px;
        border: 2px solid #e2e8f0;
        background: #f8fafc;
        cursor: pointer;
        text-align: center;
        transition: all 0.25s;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }
    .cat-card:hover { border-color: #94a3b8; background: #f1f5f9; }
    .cat-card.active { border-color: #0067b1; background: #eff6ff; box-shadow: 0 0 0 3px rgba(0,103,177,0.15); }
    .cat-card .cat-icon { font-size: 32px; transition: color 0.25s; color: #94a3b8; }
    .cat-card.active .cat-icon { color: #0067b1; }
    .cat-card .cat-label { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px; color: #64748b; }
    .cat-card.active .cat-label { color: #0067b1; }
    .custom-form-autocomplete { position: relative; width: 100%; }
    .custom-form-autocomplete .dropdown-list {
        position: absolute; top: 100%; left: 0; right: 0; background: white;
        border: 1px solid #cbd5e0; border-radius: 8px; margin-top: 4px;
        max-height: 250px; overflow-y: auto; z-index: 50; display: none;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    }
    .custom-form-autocomplete .dropdown-item {
        padding: 10px 16px; border-bottom: 1px solid #f1f5f9;
        color: #4a5568; font-size: 14px; transition: all 0.2s; cursor: pointer;
    }
    .custom-form-autocomplete .dropdown-item:last-child { border-bottom: none; }
    .custom-form-autocomplete .dropdown-item:hover { background: #f7fafc; color: #2b6cb0; padding-left: 20px; }
</style>

<section class="page-title-card" style="margin: 0 auto 6px auto; padding: 18px 0 4px 0; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;" id="pageTitleText">Registro de Equipos y Maquinarias</span>
    </h1>
</section>

{{-- ══ Bulk Upload: Equipos ══ --}}
@can('equipos.create')
<div id="bulkEquipoWrapper" style="display: none;">
    @include('admin.partials.bulk_upload_card', [
        'suffix'        => '',
        'templateRoute' => 'equipos.bulkTemplate',
        'subtitulo'     => 'Descarga la plantilla, completa los equipos y súbelo para registrar varios a la vez.',
    ])
</div>
<div id="bulkAuxWrapper" style="display: none;">
    @include('admin.partials.bulk_upload_card', [
        'suffix'        => 'Aux',
        'templateRoute' => 'equipos-auxiliares.bulkTemplate',
        'subtitulo'     => 'Descarga la plantilla, completa los equipos auxiliares y súbelo para registrar varios a la vez.',
    ])
</div>
@endcan

<div id="formUnificadoCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="createUnifiedForm" action="{{ route('equipos.storeUnified') }}" method="POST" enctype="multipart/form-data" novalidate>
        @csrf
        <input type="hidden" name="__modo" id="__modo" value="equipo">
        <input type="hidden" name="CATEGORIA_FLOTA" id="__categoriaFlota" value="">

        {{-- ═══ SELECTOR DE CATEGORÍA ═══ --}}
        <div style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 700; margin-bottom: 10px; color: var(--maquinaria-dark-blue); font-size: 14px;">
                ¿Qué vas a registrar?
            </label>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <div class="cat-card" onclick="window.switchUnifiedMode('liviana')" id="catLiviana">
                    <i class="material-icons cat-icon">directions_car</i>
                    <span class="cat-label">Flota Liviana</span>
                </div>
                <div class="cat-card" onclick="window.switchUnifiedMode('pesada')" id="catPesada">
                    <i class="material-icons cat-icon">local_shipping</i>
                    <span class="cat-label">Flota Pesada</span>
                </div>
                <div class="cat-card" onclick="window.switchUnifiedMode('auxiliar')" id="catAuxiliar">
                    <i class="material-icons cat-icon">construction</i>
                    <span class="cat-label">Auxiliar</span>
                </div>
            </div>
        </div>

        {{-- ═══ FORMULARIO (oculto hasta seleccionar categoría) ═══ --}}
        <div id="unifiedFormBody" style="display: none;">

            {{-- ── CAMPOS COMUNES ── --}}
            <h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin-bottom: 20px;">Información General</h3>

            <div class="grid-responsive-5">

                {{-- TIPO: slot compartido, contenido cambia por modo --}}
                <div id="tipoSlot">
                    {{-- Tipo Equipo (modo equipo) --}}
                    <div id="tipoEquipoWrap">
                        <label for="input_tipo_equipo" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Tipo de Equipo</label>
                        <div class="custom-form-autocomplete">
                            <input type="text" id="input_tipo_equipo" name="TIPO_EQUIPO"
                                   class="form-input-custom" value="{{ old('TIPO_EQUIPO') }}"
                                   placeholder="Seleccione o escriba..." maxlength="35" autocomplete="off"
                                   onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)" oninput="filterFormDropdown(this)">
                            <div class="dropdown-list">
                                @foreach($tipos_equipo as $tipo)
                                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $tipo }}')">{{ $tipo }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    {{-- Tipo Auxiliar (modo auxiliar) --}}
                    <div id="tipoAuxWrap" style="display: none;">
                        <label for="input_tipo_aux" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                            Tipo <span style="color: var(--maquinaria-red);">*</span>
                        </label>
                        <div class="custom-dropdown" id="auxTipoCombo" style="position: relative;">
                            <input type="text" id="input_tipo_aux" name="TIPO" autocomplete="off" maxlength="80"
                                   value="{{ old('TIPO') }}" class="form-input-custom"
                                   placeholder="Selecciona o escribe..."
                                   onfocus="window.auxTipoOpen && window.auxTipoOpen(this)"
                                   oninput="window.auxTipoFilter && window.auxTipoFilter(this)"
                                   onblur="setTimeout(()=>window.auxTipoClose && window.auxTipoClose(),150)"
                                   style="text-transform: uppercase;">
                            <div class="dropdown-content" id="auxTipoContent">
                                @foreach($tiposAux as $k => $label)
                                    <div class="dropdown-item" data-label="{{ mb_strtolower($label) }}" onmousedown="event.preventDefault(); window.auxTipoPick('{{ addslashes($label) }}')">{{ $label }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                {{-- MARCA --}}
                <div>
                    <label for="marca" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        Marca <span style="color: var(--maquinaria-red);">*</span>
                    </label>
                    <div class="custom-form-autocomplete">
                        <input type="text" id="marca" name="MARCA" class="form-input-custom" value="{{ old('MARCA') }}"
                               placeholder="Escribe marca..." autocomplete="off" required
                               onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)" oninput="filterFormDropdown(this)"
                               style="text-transform: uppercase;">
                        <div class="dropdown-list">
                            @foreach($marcas as $m)
                                <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $m }}')">{{ $m }}</div>
                            @endforeach
                        </div>
                    </div>
                    @error('MARCA') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- MODELO --}}
                <div>
                    <label for="modelo" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        Modelo <span style="color: var(--maquinaria-red);">*</span>
                    </label>
                    <div class="custom-form-autocomplete">
                        <input type="text" id="modelo" name="MODELO" class="form-input-custom" value="{{ old('MODELO') }}"
                               placeholder="Escribe modelo..." autocomplete="off" required
                               onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)" oninput="filterFormDropdown(this)"
                               style="text-transform: uppercase;">
                        <div class="dropdown-list">
                            @foreach($modelos as $mod)
                                <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $mod }}')">{{ $mod }}</div>
                            @endforeach
                        </div>
                    </div>
                    @error('MODELO') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- AÑO --}}
                <div>
                    <label for="anio" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Año</label>
                    <div class="custom-form-autocomplete">
                        <input type="text" id="anio" name="ANIO" class="form-input-custom" value="{{ old('ANIO') }}"
                               placeholder="Ej: 2020" maxlength="4" autocomplete="off"
                               oninput="this.value = this.value.replace(/[^0-9]/g, ''); filterFormDropdown(this)"
                               onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)">
                        <div class="dropdown-list">
                            @foreach($aniosList ?? [] as $a)
                                <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $a }}')">{{ $a }}</div>
                            @endforeach
                        </div>
                    </div>
                    @error('ANIO') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- SERIAL (común, name cambia por JS) --}}
                <div>
                    <label for="serial_principal" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        <span id="serialLabel">Serial de Chasis</span> <span style="color: var(--maquinaria-red);">*</span>
                    </label>
                    <input type="text" id="serial_principal" name="SERIAL_CHASIS" class="form-input-custom" value="{{ old('SERIAL_CHASIS', old('SERIAL')) }}"
                           placeholder="Serial único" required autocomplete="off" style="text-transform: uppercase;">
                    @error('SERIAL_CHASIS') <span class="error-message-inline">{{ $message }}</span> @enderror
                    @error('SERIAL') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- CAPACIDAD --}}
                <div>
                    <label for="CAPACIDAD" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Capacidad</label>
                    <input type="text" id="CAPACIDAD" name="CAPACIDAD" class="form-input-custom" value="{{ old('CAPACIDAD') }}"
                           placeholder="Ej: 20 TON, 300A, 50kVA" maxlength="80" autocomplete="off" style="text-transform: uppercase;">
                </div>

                {{-- ESTADO (opciones cambian por modo) --}}
                <div>
                    <span style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Estatus</span>
                    <div class="custom-dropdown" id="estadoSelect">
                        <input type="hidden" name="ESTADO_OPERATIVO" id="input_estatus" data-filter-value value="{{ old('ESTADO_OPERATIVO', 'OPERATIVO') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('estadoSelect', event)" tabindex="0" role="button" style="cursor: default;">
                            <span id="label_estatus" data-filter-label>{{ old('ESTADO_OPERATIVO', 'OPERATIVO') }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content" id="estadoOptions">
                            {{-- Opciones equipo --}}
                            @foreach(['OPERATIVO', 'INOPERATIVO', 'EN MANTENIMIENTO' => 'MANTENIMIENTO', 'DESINCORPORADO'] as $key => $val)
                                @php $vd = is_numeric($key) ? $val : $val; $vv = is_numeric($key) ? $val : $key; @endphp
                                <div class="dropdown-item estado-opt-eq" onclick="selectOption('estadoSelect', '{{ $vv }}', '{{ $vd }}', 'estatus')">{{ $vd }}</div>
                            @endforeach
                            {{-- Opciones auxiliar (ocultas inicialmente) --}}
                            @foreach($estadosAux as $k => $label)
                                <div class="dropdown-item estado-opt-aux" style="display: none;" onclick="selectOption('estadoSelect', '{{ $k }}', '{{ addslashes($label) }}', 'estatus')">{{ $label }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- FRENTE DE TRABAJO --}}
                <div>
                    <span style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        Frente de Trabajo <span id="frenteReqMark" style="color: var(--maquinaria-red); display: none;">*</span>
                    </span>
                    <div class="custom-dropdown" id="frenteSelect">
                        <input type="hidden" name="ID_FRENTE_ACTUAL" id="input_frente_trabajo" data-filter-value value="{{ old('ID_FRENTE_ACTUAL') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('frenteSelect', event)" tabindex="0" role="button" style="cursor: default;">
                            <span id="label_frente_trabajo" data-filter-label>SELECCIONE</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            <div class="dropdown-item" onclick="selectOption('frenteSelect', '', 'Sin Asignar', 'frente_trabajo')">Sin Asignar</div>
                            @foreach($frentes as $id => $nombre)
                                <div class="dropdown-item" onclick="selectOption('frenteSelect', '{{ $id }}', '{{ $nombre }}', 'frente_trabajo')">{{ $nombre }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- DETALLE UBICACIÓN: no se pide al registrar; se asigna después via edición o movilización. --}}
            </div>

            {{-- ═══ CAMPOS EXCLUSIVOS EQUIPO ═══ --}}
            <div id="equipoFieldsSection">
                <div class="grid-responsive-5" style="margin-top: 12px;">

                    {{-- Nro Etiqueta --}}
                    <div>
                        <label for="numero_etiqueta" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">N Etiqueta</label>
                        @if(auth()->user()?->can('equipos.edit'))
                            <input type="text" id="numero_etiqueta" name="NUMERO_ETIQUETA" class="form-input-custom" value="{{ old('NUMERO_ETIQUETA') }}" placeholder="Ej: 001" maxlength="10" autocomplete="off">
                        @else
                            <input type="text" class="form-input-custom" readonly style="background: #f8fafc; color: #94a3b8; cursor: not-allowed;" title="No tienes permiso">
                        @endif
                    </div>

                    {{-- Serial Motor --}}
                    <div>
                        <label for="serial_motor" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Serial de Motor</label>
                        <input type="text" id="serial_motor" name="SERIAL_DE_MOTOR" class="form-input-custom" value="{{ old('SERIAL_DE_MOTOR') }}" placeholder="Opcional" autocomplete="off" style="text-transform: uppercase;">
                    </div>

                    {{-- Color --}}
                    <div>
                        <label for="color" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Color</label>
                        <input type="text" id="color" name="COLOR" class="form-input-custom" value="{{ old('COLOR') }}" placeholder="Ej: BLANCO" maxlength="50" autocomplete="off" oninput="this.value = this.value.toUpperCase()">
                    </div>

                    {{-- Código de Patio --}}
                    <div>
                        <label for="codigo_patio" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Código de Patio</label>
                        <input type="text" id="codigo_patio" name="CODIGO_PATIO" class="form-input-custom" value="{{ old('CODIGO_PATIO') }}" placeholder="Ej: V-01" autocomplete="off">
                    </div>

                    {{-- Link GPS --}}
                    <div>
                        <label for="link_gps" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Link GPS</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="url" id="link_gps" name="LINK_GPS" class="form-input-custom" value="{{ old('LINK_GPS') }}" placeholder="https://..." style="flex: 1;">
                            <span style="color: #10b981; display: flex;"><i class="material-icons" style="font-size: 20px;">gps_fixed</i></span>
                        </div>
                    </div>
                </div>

                {{-- Catalog Linking Widget --}}
                <div id="catalog_link_widget" style="display: none; margin: 16px 0; padding: 12px 14px; background: linear-gradient(135deg, #ebf8ff 0%, #f0f9ff 100%); border: 1px solid #0284c7; border-radius: 12px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <div style="background: #0284c7; padding: 6px; border-radius: 50%; display: flex;">
                            <i class="material-icons" style="color: white; font-size: 18px;">inventory_2</i>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="margin: 0; color: #0c4a6e; font-size: 13.5px; font-weight: 800;">¡Encontramos este modelo en el Catálogo!</h4>
                            <p style="margin: 2px 0 0 0; color: #075985; font-size: 12px;">Vincula las especificaciones técnicas si coinciden.</p>
                        </div>
                    </div>
                    <div id="catalog_preview" style="background: white; padding: 12px; border-radius: 10px; margin-bottom: 10px; border: 1px solid #bae6fd;"></div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" onclick="ignoreCatalogSuggestion()" style="background: white; color: #64748b; border: 1px solid #cbd5e0; padding: 7px 14px; border-radius: 8px; font-weight: 600; font-size: 13px;">
                            <i class="material-icons" style="font-size: 16px; vertical-align: middle; margin-right: 4px;">close</i> Ignorar
                        </button>
                        <button type="button" onclick="linkToCatalog()" style="background: #0284c7; color: white; border: none; padding: 7px 16px; border-radius: 8px; font-weight: 700; font-size: 13px;">
                            <i class="material-icons" style="font-size: 16px; vertical-align: middle; margin-right: 4px;">link</i> Vincular
                        </button>
                    </div>
                </div>
                <input type="hidden" id="linked_id_espec" name="ID_ESPEC" value="{{ old('ID_ESPEC') }}">

                {{-- Documentación Legal (Equipos) --}}
                <h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px;">Documentación Legal</h3>
                <div class="grid-responsive-5">
                    <div>
                        <label for="placa" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Placa</label>
                        <input type="text" id="placa" name="documentacion[PLACA]" class="form-input-custom" value="{{ old('documentacion.PLACA') }}" placeholder="Ej: A00BC12">
                    </div>
                    <div>
                        <label for="titular" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Titular del Registro</label>
                        <input type="text" id="titular" name="documentacion[NOMBRE_DEL_TITULAR]" class="form-input-custom" value="{{ old('documentacion.NOMBRE_DEL_TITULAR') }}" placeholder="Nombre propietario" autocomplete="off">
                    </div>
                    <div style="position: relative;">
                        <label for="nro_doc_propiedad" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Nro Título</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="text" id="nro_doc_propiedad" name="documentacion[NRO_DE_DOCUMENTO]" class="form-input-custom doc-meta" data-file-target="doc_propiedad" data-has-existing="false" value="{{ old('documentacion.NRO_DE_DOCUMENTO') }}" style="flex: 1;" autocomplete="off">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="doc_propiedad" title="Cargar PDF de Propiedad" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="doc_propiedad" name="doc_propiedad" class="doc-file" data-meta-target="nro_doc_propiedad" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                    <div>
                        <label for="seguro" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Póliza</label>
                        <div class="custom-form-autocomplete">
                            <input type="text" id="seguro" name="documentacion[NOMBRE_SEGURO]" class="form-input-custom" value="{{ old('documentacion.NOMBRE_SEGURO') }}" placeholder="Escriba aseguradora." autocomplete="off"
                                   onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)" oninput="filterFormDropdown(this)">
                            <div class="dropdown-list">
                                @foreach($seguros as $s)
                                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $s }}')">{{ $s }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="venc_poliza" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Vencimiento Póliza</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="venc_poliza" name="documentacion[FECHA_VENC_POLIZA]" class="form-input-custom doc-meta" data-file-target="poliza_seguro" data-has-existing="false" value="{{ old('documentacion.FECHA_VENC_POLIZA') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="poliza_seguro" title="Cargar PDF de Póliza" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="poliza_seguro" name="poliza_seguro" class="doc-file" data-meta-target="venc_poliza" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="fecha_rotc" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Fecha ROTC</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="fecha_rotc" name="documentacion[FECHA_ROTC]" class="form-input-custom doc-meta" data-file-target="doc_rotc" data-has-existing="false" value="{{ old('documentacion.FECHA_ROTC') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="doc_rotc" title="Cargar PDF ROTC" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="doc_rotc" name="doc_rotc" class="doc-file" data-meta-target="fecha_rotc" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="fecha_racda" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Fecha RACDA</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="fecha_racda" name="documentacion[FECHA_RACDA]" class="form-input-custom doc-meta" data-file-target="doc_racda" data-has-existing="false" value="{{ old('documentacion.FECHA_RACDA') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="doc_racda" title="Cargar PDF RACDA" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="doc_racda" name="doc_racda" class="doc-file" data-meta-target="fecha_racda" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                </div>
            </div>{{-- /equipoFieldsSection --}}

            {{-- ═══ CAMPOS EXCLUSIVOS AUXILIAR ═══ --}}
            <div id="auxiliarFieldsSection" style="display: none;">
                <div class="grid-responsive-5" style="margin-top: 12px;">

                    {{-- Código Interno --}}
                    <div>
                        <label for="CODIGO_INTERNO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Código Interno</label>
                        <input type="text" id="CODIGO_INTERNO" name="CODIGO_INTERNO" class="form-input-custom" value="{{ old('CODIGO_INTERNO') }}" placeholder="Ej: AUX-001" maxlength="50" autocomplete="off" style="text-transform: uppercase;">
                    </div>

                    {{-- Equipo Vinculado --}}
                    <div style="grid-column: span 2;">
                        <label for="hostSearchInput" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Equipo Vinculado</label>
                        <div id="auxHostPicker" style="position: relative;">
                            <input type="hidden" name="ID_EQUIPO_HOST" id="ID_EQUIPO_HOST" value="{{ old('ID_EQUIPO_HOST') }}">
                            <div id="hostSearchWrapper" style="position:relative;">
                                <input type="text" id="hostSearchInput" autocomplete="off" class="form-input-custom"
                                       placeholder="Buscar por serial, placa o código..."
                                       oninput="window.auxHostSearch && window.auxHostSearch(this)"
                                       onfocus="window.auxHostSearch && window.auxHostSearch(this)"
                                       onblur="setTimeout(()=>window.auxHostClose && window.auxHostClose(),200)">
                                <div id="hostResultsBox" style="display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:white; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 20px -5px rgba(15,23,42,0.18); max-height:360px; overflow-y:auto; z-index:50;"></div>
                            </div>
                            <div id="hostSelectedCard" style="display:none; background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%); border:1px solid #93c5fd; border-radius:10px; padding:10px 12px; align-items:center; gap:12px;">
                                <div style="background:#1e40af; color:white; padding:8px; border-radius:8px; display:flex; flex-shrink:0;">
                                    <i class="material-icons" style="font-size:20px;">directions_car</i>
                                </div>
                                <div style="flex:1; min-width:0;">
                                    <div id="hostSelectedPrimary" style="font-weight:800; color:#1e293b; font-size:14px;"></div>
                                    <div id="hostSelectedSecondary" style="color:#475569; font-size:12px; margin-top:2px;"></div>
                                    <div id="hostSelectedTertiary" style="color:#64748b; font-size:11px; margin-top:2px;"></div>
                                </div>
                                <button type="button" onclick="window.auxHostClear && window.auxHostClear()" title="Cambiar" style="background:white; border:1px solid #cbd5e1; color:#475569; cursor:pointer; border-radius:6px; padding:6px 10px; display:flex; align-items:center; gap:4px; font-size:12px; font-weight:600;">
                                    <i class="material-icons" style="font-size:16px;">swap_horiz</i> Cambiar
                                </button>
                            </div>
                        </div>
                        <small style="display:block;margin-top:4px;font-size:11px;color:#94a3b8;">Opcional. Máx. {{ \App\Models\EquipoAuxiliar::ANCHOR_MAX_PER_HOST }} por equipo.</small>
                    </div>
                </div>

                {{-- Documentación Auxiliar --}}
                <h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 8px; margin: 18px 0 12px 0;">Documentación Legal</h3>
                <div class="grid-responsive-5">
                    <div style="position: relative;">
                        <label style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Documento de Propiedad</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="text" id="aux_doc_propiedad_meta" class="form-input-custom" placeholder="Sin documento" readonly style="flex: 1; background:#f8fafc; cursor:default;">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="aux_doc_propiedad" title="Cargar PDF de Propiedad" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="aux_doc_propiedad" name="doc_propiedad" accept=".pdf" style="display:none;"
                                   onchange="document.getElementById('aux_doc_propiedad_meta').value = this.files[0] ? this.files[0].name : '';">
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="fecha_vencimiento_cert" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Vencimiento Certif.</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="fecha_vencimiento_cert" name="fecha_vencimiento_cert" class="form-input-custom" value="{{ old('fecha_vencimiento_cert') }}" style="flex: 1; cursor:pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="aux_certificado" title="Cargar PDF de certificado" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="aux_certificado" name="certificado" accept=".pdf" style="display:none;">
                        </div>
                    </div>
                </div>
            </div>{{-- /auxiliarFieldsSection --}}

        </div>{{-- /unifiedFormBody --}}

        <div id="submitRow" style="margin-top: 40px; display: none; gap: 12px; justify-content: center;">
            <a href="{{ route('equipos.index') }}" class="btn-primary-maquinaria btn-secondary">Cancelar</a>
            <button type="submit" class="btn-primary-maquinaria" id="btnSubmitUnified">
                <i class="material-icons">save</i>
                <span id="btnSubmitLabel">Registrar</span>
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    // ── Tipo Aux combobox ──
    window.auxTipoOpen = function (input) {
        var cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'block';
        window.auxTipoFilter(input);
    };
    window.auxTipoClose = function () {
        var cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'none';
    };
    window.auxTipoFilter = function (input) {
        var q = (input.value || '').toLowerCase().trim();
        document.querySelectorAll('#auxTipoContent .dropdown-item').forEach(function (it) {
            it.style.display = (!q || it.dataset.label.includes(q)) ? '' : 'none';
        });
    };
    window.auxTipoPick = function (label) {
        var input = document.getElementById('input_tipo_aux');
        if (input) input.value = label;
        window.auxTipoClose();
    };

    // ── Host picker (auxiliar) ──
    var _hostDebounce = null, _hostLastQuery = '';
    window.auxHostSearch = function (input) {
        var q = (input.value || '').trim();
        if (q.length < 2) { document.getElementById('hostResultsBox').style.display = 'none'; return; }
        if (q === _hostLastQuery) return;
        _hostLastQuery = q;
        clearTimeout(_hostDebounce);
        _hostDebounce = setTimeout(function () {
            fetch('{{ route("equipos-auxiliares.searchHosts") }}?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            }).then(function (r) { return r.json(); }).then(function (data) { window.auxHostRender(data); })
            .catch(function (e) { console.error('searchHosts:', e); });
        }, 280);
    };
    window.auxHostRender = function (rows) {
        var box = document.getElementById('hostResultsBox');
        if (!box) return;
        if (!rows || !rows.length) { box.innerHTML = '<div style="padding:14px; text-align:center; color:#94a3b8; font-size:12px;">Sin resultados.</div>'; box.style.display = 'block'; return; }
        var esc = function (s) { return (s || '').toString().replace(/"/g, '&quot;'); };
        box.innerHTML = rows.map(function (r) {
            var dis = r.disponible ? '' : 'opacity:0.55; pointer-events:none;';
            var badge = r.disponible
                ? '<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Disponible</span>'
                : '<span style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Lleno (' + r.auxiliares_anclados + '/2)</span>';
            var idP = r.placa || r.serial_chasis || ('#' + r.id);
            var primary = idP;
            var secondary = [r.tipo, r.marca].filter(function (x) { return x; }).join(' · ');
            var tertiary = r.codigo ? ('Código: ' + r.codigo) : '';
            return '<div style="padding:12px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; display:flex; align-items:center; gap:12px; ' + dis + '" onmousedown="event.preventDefault(); window.auxHostPick(' + r.id + ', this)" data-primary="' + esc(primary) + '" data-secondary="' + esc(secondary) + '" data-tertiary="' + esc(tertiary) + '" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'white\'">' +
                '<div style="width:40px;height:40px;border-radius:8px;background:#eff6ff;color:#1e40af;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="material-icons" style="font-size:20px;">directions_car</i></div>' +
                '<div style="flex:1; min-width:0;"><div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:2px;"><strong style="color:#1e293b; font-size:13px;">' + idP + '</strong>' + badge + '</div><div style="font-size:12px; color:#475569;">' + (secondary || '') + '</div></div></div>';
        }).join('');
        box.style.display = 'block';
    };
    window.auxHostPick = function (id, el) {
        document.getElementById('ID_EQUIPO_HOST').value = id;
        document.getElementById('hostSelectedPrimary').textContent = el.dataset.primary || ('#' + id);
        document.getElementById('hostSelectedSecondary').textContent = el.dataset.secondary || '';
        document.getElementById('hostSelectedTertiary').textContent = el.dataset.tertiary || '';
        document.getElementById('hostSearchWrapper').style.display = 'none';
        document.getElementById('hostSelectedCard').style.display = 'flex';
        document.getElementById('hostSearchInput').value = '';
        window.auxHostClose();
    };
    window.auxHostClose = function () { var b = document.getElementById('hostResultsBox'); if (b) b.style.display = 'none'; };
    window.auxHostClear = function () {
        document.getElementById('ID_EQUIPO_HOST').value = '';
        document.getElementById('hostSearchInput').value = '';
        document.getElementById('hostSearchWrapper').style.display = 'block';
        document.getElementById('hostSelectedCard').style.display = 'none';
        _hostLastQuery = '';
        window.auxHostClose();
        document.getElementById('hostSearchInput').focus();
    };

    // ── Mode switching ──
    window.switchUnifiedMode = function (mode) {
        var isAux = (mode === 'auxiliar');
        var modoVal = isAux ? 'auxiliar' : 'equipo';
        document.getElementById('__modo').value = modoVal;
        document.getElementById('__categoriaFlota').value = mode === 'liviana' ? 'FLOTA LIVIANA' : (mode === 'pesada' ? 'FLOTA PESADA' : '');

        // Cards
        document.querySelectorAll('.cat-card').forEach(function (c) { c.classList.remove('active'); });
        var active = mode === 'liviana' ? 'catLiviana' : (mode === 'pesada' ? 'catPesada' : 'catAuxiliar');
        document.getElementById(active).classList.add('active');

        // Show form body + submit
        document.getElementById('unifiedFormBody').style.display = '';
        document.getElementById('submitRow').style.display = 'flex';

        // Toggle sections
        document.getElementById('equipoFieldsSection').style.display = isAux ? 'none' : '';
        document.getElementById('auxiliarFieldsSection').style.display = isAux ? '' : 'none';

        // Tipo slot
        document.getElementById('tipoEquipoWrap').style.display = isAux ? 'none' : '';
        document.getElementById('tipoAuxWrap').style.display = isAux ? '' : 'none';

        // Serial name
        var serialInput = document.getElementById('serial_principal');
        serialInput.name = isAux ? 'SERIAL' : 'SERIAL_CHASIS';
        document.getElementById('serialLabel').textContent = isAux ? 'Serial' : 'Serial de Chasis';

        // Año required
        document.getElementById('anio').required = !isAux;

        // Frente required
        var frenteInput = document.getElementById('input_frente_trabajo');
        frenteInput.required = isAux;
        document.getElementById('frenteReqMark').style.display = isAux ? '' : 'none';

        // Estado opciones
        document.querySelectorAll('.estado-opt-eq').forEach(function (el) { el.style.display = isAux ? 'none' : ''; });
        document.querySelectorAll('.estado-opt-aux').forEach(function (el) { el.style.display = isAux ? '' : 'none'; });
        // Reset estado a OPERATIVO al cambiar modo
        document.getElementById('input_estatus').value = 'OPERATIVO';
        document.getElementById('label_estatus').textContent = 'OPERATIVO';

        // Tipo required
        var tipoEq = document.getElementById('input_tipo_equipo');
        var tipoAux = document.getElementById('input_tipo_aux');
        if (tipoEq) tipoEq.required = !isAux;
        if (tipoAux) tipoAux.required = isAux;

        // Bulk upload toggle
        var bulkEq = document.getElementById('bulkEquipoWrapper');
        var bulkAux = document.getElementById('bulkAuxWrapper');
        if (bulkEq) bulkEq.style.display = isAux ? 'none' : '';
        if (bulkAux) bulkAux.style.display = isAux ? '' : 'none';

        // Title
        var titleMap = { liviana: 'Registro de Equipos y Maquinarias — Flota Liviana', pesada: 'Registro de Equipos y Maquinarias — Flota Pesada', auxiliar: 'Registro de Equipo Auxiliar' };
        document.getElementById('pageTitleText').textContent = titleMap[mode] || 'Registro de Equipos y Maquinarias';

        // Submit label
        document.getElementById('btnSubmitLabel').textContent = isAux ? 'Registrar Auxiliar' : 'Registrar Equipo';

        // Disable hidden inputs in inactive section to prevent them from being submitted
        document.querySelectorAll('#equipoFieldsSection input, #equipoFieldsSection select').forEach(function (el) { el.disabled = isAux; });
        document.querySelectorAll('#auxiliarFieldsSection input, #auxiliarFieldsSection select').forEach(function (el) { el.disabled = !isAux; });
    };

    // ── AJAX Submit ──
    var form = document.getElementById('createUnifiedForm');
    if (form && form.dataset.ajaxBound !== '1') {
        form.dataset.ajaxBound = '1';
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (form.dataset.submitting === '1') return;
            form.dataset.submitting = '1';

            if (typeof window.showPreloader === 'function') window.showPreloader();

            // Limpiar errores anteriores
            form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
            form.querySelectorAll('.error-message-inline').forEach(function (el) { el.remove(); });
            var oldSummary = document.getElementById('errorSummary');
            if (oldSummary) oldSummary.remove();

            var formData = new FormData(form);
            fetch(form.action, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                body: formData,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json().then(function (body) { return { status: r.status, body: body }; }); })
            .then(function (res) {
                if (res.status === 200 || res.status === 201) {
                    var msg = res.body.message || 'Registrado correctamente.';
                    try { sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({ message: msg, type: 'success' })); } catch (_) {}
                    var redirect = res.body.redirect || '{{ route("equipos.index") }}';
                    if (typeof window.navigateTo === 'function') window.navigateTo(redirect);
                    else window.location.href = redirect;
                    return;
                }
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
                form.dataset.submitting = '0';

                if (res.status === 422 && res.body.errors) {
                    // Banner global
                    form.insertAdjacentHTML('afterbegin', '<div id="errorSummary" style="background:#fff5f5; border:1px solid #fed7d7; color:#c53030; padding:12px 15px; border-radius:12px; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:14px; font-weight:600;"><i class="material-icons" style="color:var(--maquinaria-red);">error_outline</i><span>Verifica los campos marcados en rojo.</span></div>');
                    Object.entries(res.body.errors).forEach(function (pair) {
                        var field = pair[0], msgs = pair[1];
                        var msg = Array.isArray(msgs) ? msgs[0] : String(msgs);
                        var input = document.getElementById(field) || document.querySelector('[name="' + field + '"]');
                        if (!input) return;
                        input.classList.add('is-invalid');
                        var parent = input.closest('.custom-dropdown') ? input.closest('.custom-dropdown').parentNode : input.parentNode;
                        if (parent) {
                            var fb = document.createElement('span');
                            fb.className = 'error-message-inline';
                            fb.innerText = msg;
                            parent.appendChild(fb);
                        }
                    });
                    var first = form.querySelector('.is-invalid');
                    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                var errMsg = res.body.message || 'No se pudo registrar.';
                if (window.showModal) window.showModal({ type: 'error', title: 'Error', message: errMsg, confirmText: 'Entendido', hideCancel: true });
            })
            .catch(function (err) {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
                form.dataset.submitting = '0';
                if (window.showModal) window.showModal({ type: 'error', title: 'Error de red', message: 'No se pudo contactar el servidor.', confirmText: 'Entendido', hideCancel: true });
            });
        });
    }
})();
</script>
@endsection
