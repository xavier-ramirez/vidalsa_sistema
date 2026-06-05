@extends('layouts.estructura_base')



@section('title', 'Gestión de Frentes')

@section('content')
    {{-- Espaciado compacto SOLO para este formulario: reduce el desperdicio vertical
         (gap del grid, márgenes de los separadores de Responsables, etc.) sin tocar
         el resto del sitio. Mantiene los inputs y dropdowns con su altura habitual. --}}
    <style>
        #frenteForm .form-grid { gap: 10px; }
        #frenteForm .resp-grid { gap: 10px; }
        /* Separadores de cada bloque "Responsable N": menos espacio arriba/abajo. */
        #frenteForm > .form-grid > div[style*="border-bottom"][style*="margin-top: 20px"] {
            margin-top: 12px !important;
            padding: 6px 0 4px 0 !important;
        }
        /* Inputs y labels más compactos. */
        #frenteForm .form-input-custom { padding: 8px 12px; font-size: 13.5px; }
        #frenteForm .form-label        { margin-bottom: 3px; font-size: 12.5px; }
        /* Dropdowns (Tipo/Estatus/buscar) IGUALADOS a los inputs: misma fuente
           13.5px y mismo alto (~36px). El icono expand_more por defecto mide
           24px y agrandaba el cuadro — se reduce a 18px para que coincida. */
        #frenteForm .dropdown-trigger  { padding: 8px 12px; font-size: 13.5px; min-height: 36px; box-sizing: border-box; }
        #frenteForm .dropdown-trigger .material-icons { font-size: 18px; }
        /* Barra de búsqueda superior: 30px era exagerado. */
        .admin-card > div[style*="margin-bottom: 30px"] { margin-bottom: 14px !important; }
        /* Footer de botones (Cancelar / Guardar / Eliminar): 40px → 18px. */
        .frentes-btn-row { margin-top: 18px !important; }

        /* Acordeón de responsables: cada bloque se "recoge". Click en el encabezado
           expande/colapsa sus campos. Arrancan abiertos solo los que traen datos.
           Pensado para móvil (5 bloques juntos eran demasiado). */
        #frenteForm .resp-head { cursor: pointer; user-select: none; }
        #frenteForm .resp-head:hover { background: #f8fafc; }
        #frenteForm .resp-chevron { margin-left: auto; color: var(--maquinaria-blue); transition: transform .2s; font-size: 20px; }
        #frenteForm .resp-collapsed { display: none !important; }

        /* Teléfono: el botón "Sin equipos" queda al lado del buscador pero SOLO con
           el icono (sin texto), cuadrado, para no robar ancho a la barra de búsqueda. */
        @media (max-width: 600px) {
            #btnSinEquipos span { display: none; }
            #btnSinEquipos { padding: 0; width: 38px; gap: 0; justify-content: center; }
        }
    </style>

    <section class="page-title-card" style="text-align: center; margin: 0 auto 8px auto;">
        <h1 class="page-title">
            <span class="page-title-line2" style="color: #000; font-size: 26px;"
                id="formTitle">{{ (isset($frente) && $frente->exists) ? 'Edición de Frente de Trabajo' : 'Registro de Frente de Trabajo' }}</span>
        </h1>
    </section>



    <!-- Form -->
    <div class="admin-card" style="max-width: 800px; margin: 0 auto;">

        <!-- Top Search Bar (Standardized Smart Dropdown) -->
        <div style="margin-bottom: 30px; display: flex; justify-content: center; gap: 10px; align-items: center; flex-wrap: wrap;">
            <div style="flex: 1 1 auto; min-width: 0; max-width: 500px; position: relative;">

                <div class="custom-dropdown" id="frenteSearchDropdown" style="width: 100%;">
                    <div class="dropdown-trigger"
                        style="background: #fff; border: 1px solid #cbd5e0; border-radius: 10px; height: 38px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">

                        <div
                            style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 18px;">search</i>
                        </div>

                        <input type="text" id="filterSearchInput" placeholder="Buscar frente para editar..."
                            style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 13.5px; outline: none; color: #4a5568;"
                            autocomplete="off" oninput="window.filterFrentesDropdown(this)">

                        <div style="display: flex; align-items: center; padding-right: 10px;">
                            <i id="btn_clear_search_frente" class="material-icons"
                                style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: none;"
                                onclick="event.stopPropagation(); window.clearFrentesSearchSPA();">close</i>
                        </div>
                    </div>

                    <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                        <div class="dropdown-item-list" id="frenteItemsList" style="max-height: 250px; overflow-y: auto;">
                            @if(isset($allFrentes))
                                @foreach($allFrentes as $f)
                                    <div class="dropdown-item search-result-item" data-name="{{ $f->NOMBRE_FRENTE }}"
                                        onclick="selectFrenteSPA('{{ $f->ID_FRENTE }}')">
                                        {{ $f->NOMBRE_FRENTE }}
                                    </div>
                                @endforeach
                            @endif
                            <div id="no-results-msg"
                                style="display: none; padding: 10px 15px; color: #94a3b8; font-size: 14px;">No se
                                encontraron resultados</div>
                        </div>
                    </div>
                </div>

            </div>
            {{-- Botón "Sin equipos": abre modal con frentes ACTIVOS sin equipos ni
                 auxiliares asignados, para borrarlos o desactivarlos. --}}
            <button type="button" id="btnSinEquipos" onclick="window.abrirModalSinEquipos()" title="Frentes sin equipos asignados"
                class="btn-primary-maquinaria btn-secondary"
                style="padding: 0 14px; height: 38px; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0;">
                <i class="material-icons" style="font-size: 18px;">domain_disabled</i>
                <span>Sin equipos</span>
            </button>
        </div>
        <form
            action="{{ (isset($frente) && $frente->exists) ? route('frentes.update', $frente->ID_FRENTE) : route('frentes.store') }}"
            method="POST" id="frenteForm" onsubmit="if(window.showPreloader) window.showPreloader();">
            @csrf
            @if(isset($frente) && $frente->exists) @method('PUT') @endif
            <input type="hidden" id="ID_FRENTE" name="ID_FRENTE" value="{{ $frente->ID_FRENTE ?? '' }}">

            <div class="form-grid">
                <!-- Row 1 -->
                <div>
                    <label for="NOMBRE_FRENTE" class="form-label">Nombre del Frente <span
                            style="color: red;">*</span></label>
                    <input type="text" id="NOMBRE_FRENTE" name="NOMBRE_FRENTE" class="form-input-custom"
                        style="background: white;" placeholder="Ej: MINA 1"
                        value="{{ old('NOMBRE_FRENTE', $frente->NOMBRE_FRENTE ?? '') }}" required autocomplete="off">
                </div>

                <div>
                    <div class="form-label">Tipo de Frente <span style="color: red;">*</span></div>
                    <div class="custom-dropdown" id="tipoSelect">
                        <input type="hidden" name="TIPO_FRENTE" id="input_tipo"
                            value="{{ old('TIPO_FRENTE', $frente->TIPO_FRENTE ?? '') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('tipoSelect', event)"
                            style="background: white; cursor: default;">
                            <span
                                id="label_tipo">{{ old('TIPO_FRENTE', $frente->TIPO_FRENTE ?? 'Seleccione Tipo...') }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            <div class="dropdown-item"
                                onclick="selectOption('tipoSelect', 'OPERACION', 'OPERACION', 'tipo')">OPERACION</div>
                            <div class="dropdown-item"
                                onclick="selectOption('tipoSelect', 'RESGUARDO', 'RESGUARDO', 'tipo')">RESGUARDO</div>
                            <div class="dropdown-item"
                                onclick="selectOption('tipoSelect', 'ESPECIAL', 'ESPECIAL', 'tipo')">ESPECIAL</div>
                        </div>
                    </div>
                </div>

                <!-- Row 2 -->
                <div>
                    <label for="UBICACION" class="form-label">Ubicación / Geografía <span
                            style="color: red;">*</span></label>
                    <input type="text" id="UBICACION" name="UBICACION" class="form-input-custom" style="background: white;"
                        placeholder="Ej: Sector 4" value="{{ old('UBICACION', $frente->UBICACION ?? '') }}" required
                        autocomplete="off">
                </div>

                {{-- ZONA: nombre corto (zona/ciudad) que se imprime en el renglón
                     "Lugar, fecha" del Acta de Traslado (ej: "MATURÍN 02/06/2026").
                     Opcional: si se deja vacío, el acta usa el Nombre del Frente. --}}
                <div>
                    <label for="ZONA" class="form-label">Zona / Ciudad <span style="font-weight:400; color:#94a3b8; font-size:12px;">(sale en el Acta de Traslado)</span></label>
                    <input type="text" id="ZONA" name="ZONA" class="form-input-custom" style="background: white;"
                        placeholder="Ej: MATURÍN" value="{{ old('ZONA', $frente->ZONA ?? '') }}" maxlength="100"
                        autocomplete="off">
                </div>

                <div>
                    <div class="form-label">Estatus del Proyecto <span style="color: red;">*</span></div>
                    <div class="custom-dropdown" id="statusSelect">
                        <input type="hidden" name="ESTATUS_FRENTE" id="input_estatus"
                            value="{{ old('ESTATUS_FRENTE', $frente->ESTATUS_FRENTE ?? '') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('statusSelect', event)"
                            style="background: white; cursor: default;">
                            <span
                                id="label_estatus">{{ old('ESTATUS_FRENTE', $frente->ESTATUS_FRENTE ?? 'Seleccione Estatus...') }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            <div class="dropdown-item"
                                onclick="selectOption('statusSelect', 'ACTIVO', 'ACTIVO', 'estatus')">ACTIVO</div>
                            <div class="dropdown-item"
                                onclick="selectOption('statusSelect', 'FINALIZADO', 'FINALIZADO', 'estatus')">FINALIZADO
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contratos asociados al proyecto (chips multi-valor; se sugieren en el modal
                     "Registrar salida" del inventario cuando se elige este frente como proyecto).
                     Ocupa 1 columna y el contenedor imita exactamente el estilo de los demas
                     `.form-input-custom` del formulario: border-radius 12px, border 1px #cbd5e0,
                     background blanco, padding 8px 12px. Asi el chip-box se ve como un input
                     normal (misma altura y curvatura que UBICACION, NOMBRE_FRENTE, etc.). -->
                <div>
                    <label class="form-label" for="contratos_input">N° de Contrato(s) asociados al proyecto</label>
                    {{-- min-height 36px + padding/borde/radio iguales a .form-input-custom
                         (8px 12px de alto efectivo) para que el chip-box se vea EXACTAMENTE
                         como un input normal (mismo alto que Zona/Ubicación) cuando está
                         vacío, y crezca solo si se agregan chips. --}}
                    <div id="contratos_chip_box"
                         style="background:white;border:1px solid #cbd5e0;border-radius:12px;padding:4px 12px;min-height:36px;display:flex;flex-wrap:wrap;align-items:center;gap:5px;box-sizing:border-box;transition:border-color .2s;">
                        <input type="text" id="contratos_input" autocomplete="off"
                               placeholder="Escribe y pulsa Enter o coma"
                               style="flex:1;min-width:110px;border:none;outline:none;background:transparent;font-size:13.5px;padding:0 2px;text-transform:uppercase;line-height:1.4;">
                    </div>
                    {{-- El hidden guarda los contratos como CSV; el backend lo splittea + normaliza. --}}
                    <input type="hidden" id="CONTRATOS_HIDDEN" name="CONTRATOS"
                           value="{{ old('CONTRATOS', is_array($frente->CONTRATOS ?? null) ? implode(',', $frente->CONTRATOS) : '') }}">
                </div>

                @php
                    // Un responsable arranca ABIERTO si trae nombre/cargo/cédula
                    // (datos guardados o reenvíos con error). El resto, recogido.
                    $respOpen = [];
                    for ($i = 1; $i <= 5; $i++) {
                        $respOpen[$i] = !empty(old("RESP_{$i}_NOM", $frente->{"RESP_{$i}_NOM"} ?? ''))
                                     || !empty(old("RESP_{$i}_CAR", $frente->{"RESP_{$i}_CAR"} ?? ''))
                                     || !empty(old("RESP_{$i}_CED", $frente->{"RESP_{$i}_CED"} ?? ''));
                    }
                @endphp

                <!-- Responsable 1 -->
                <div id="respHead1" class="resp-head" onclick="window.toggleResp(1)" style="grid-column: span 2; border-bottom: 2px solid #dbeafe; padding: 12px 0 8px 0; margin-top: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">SOLICITADO</span>
                    <span style="color:#64748b; font-size:11px; font-style:italic;">Coord. Mec&aacute;nica Liviana</span>
                    <i class="material-icons resp-chevron" style="{{ $respOpen[1] ? '' : 'transform:rotate(-90deg);' }}">expand_more</i>
                </div>

                <!-- Responsable 1 Inputs -->
                <div class="resp-grid {{ $respOpen[1] ? '' : 'resp-collapsed' }}" id="respBody1" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_1_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_1_NOM" name="RESP_1_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_1_NOM', $frente->RESP_1_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_1_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_1_CAR" name="RESP_1_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Gerente"
                            value="{{ old('RESP_1_CAR', $frente->RESP_1_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_1_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_1_CED" name="RESP_1_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_1_CED', $frente->RESP_1_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma <span style="font-weight: normal; font-size: 11px; color: #64748b;">(Opcional)</span></span>
                        <div class="custom-dropdown" id="resp1EquSelect">
                            <input type="hidden" name="RESP_1_EQU" id="input_resp1_equ" value="{{ old('RESP_1_EQU', $frente->RESP_1_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp1EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp1_equ">{{ old('RESP_1_EQU', $frente->RESP_1_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp1EquSelect', '', 'SIN FILTRO', 'resp1_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp1EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp1_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Responsable 2 -->
                <div id="respHead2" class="resp-head" onclick="window.toggleResp(2)" style="grid-column: span 2; border-bottom: 2px solid #dbeafe; padding: 12px 0 8px 0; margin-top: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">SOLICITADO Alt.</span>
                    <span style="color:#64748b; font-size:11px; font-style:italic;">Coord. Mec&aacute;nica Pesada</span>
                    <i class="material-icons resp-chevron" style="{{ $respOpen[2] ? '' : 'transform:rotate(-90deg);' }}">expand_more</i>
                </div>

                <!-- Responsable 2 Inputs -->
                <div class="resp-grid {{ $respOpen[2] ? '' : 'resp-collapsed' }}" id="respBody2" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_2_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_2_NOM" name="RESP_2_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_2_NOM', $frente->RESP_2_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_2_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_2_CAR" name="RESP_2_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Supervisor"
                            value="{{ old('RESP_2_CAR', $frente->RESP_2_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_2_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_2_CED" name="RESP_2_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_2_CED', $frente->RESP_2_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma</span>
                        <div class="custom-dropdown" id="resp2EquSelect">
                            <input type="hidden" name="RESP_2_EQU" id="input_resp2_equ" value="{{ old('RESP_2_EQU', $frente->RESP_2_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp2EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp2_equ">{{ old('RESP_2_EQU', $frente->RESP_2_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp2EquSelect', '', 'SIN FILTRO', 'resp2_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp2EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp2_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Responsable 3 -->
                <div id="respHead3" class="resp-head" onclick="window.toggleResp(3)" style="grid-column: span 2; border-bottom: 2px solid #dbeafe; padding: 12px 0 8px 0; margin-top: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">ELABORADO</span>
                    <span style="color:#64748b; font-size:11px; font-style:italic;">Transporte y Log&iacute;stica</span>
                    <i class="material-icons resp-chevron" style="{{ $respOpen[3] ? '' : 'transform:rotate(-90deg);' }}">expand_more</i>
                </div>

                <!-- Responsable 3 Inputs -->
                <div class="resp-grid {{ $respOpen[3] ? '' : 'resp-collapsed' }}" id="respBody3" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_3_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_3_NOM" name="RESP_3_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_3_NOM', $frente->RESP_3_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_3_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_3_CAR" name="RESP_3_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Jefe de Taller"
                            value="{{ old('RESP_3_CAR', $frente->RESP_3_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_3_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_3_CED" name="RESP_3_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_3_CED', $frente->RESP_3_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma</span>
                        <div class="custom-dropdown" id="resp3EquSelect">
                            <input type="hidden" name="RESP_3_EQU" id="input_resp3_equ" value="{{ old('RESP_3_EQU', $frente->RESP_3_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp3EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp3_equ">{{ old('RESP_3_EQU', $frente->RESP_3_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp3EquSelect', '', 'SIN FILTRO', 'resp3_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp3EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp3_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Responsable 4 -->
                <div id="respHead4" class="resp-head" onclick="window.toggleResp(4)" style="grid-column: span 2; border-bottom: 2px solid #dbeafe; padding: 12px 0 8px 0; margin-top: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">REVISADO</span>
                    <span style="color:#64748b; font-size:11px; font-style:italic;">Sub-gerente</span>
                    <i class="material-icons resp-chevron" style="{{ $respOpen[4] ? '' : 'transform:rotate(-90deg);' }}">expand_more</i>
                </div>

                <!-- Responsable 4 Inputs -->
                <div class="resp-grid {{ $respOpen[4] ? '' : 'resp-collapsed' }}" id="respBody4" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_4_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_4_NOM" name="RESP_4_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_4_NOM', $frente->RESP_4_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_4_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_4_CAR" name="RESP_4_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Residente"
                            value="{{ old('RESP_4_CAR', $frente->RESP_4_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_4_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_4_CED" name="RESP_4_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_4_CED', $frente->RESP_4_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma</span>
                        <div class="custom-dropdown" id="resp4EquSelect">
                            <input type="hidden" name="RESP_4_EQU" id="input_resp4_equ" value="{{ old('RESP_4_EQU', $frente->RESP_4_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp4EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp4_equ">{{ old('RESP_4_EQU', $frente->RESP_4_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp4EquSelect', '', 'SIN FILTRO', 'resp4_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp4EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp4_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <div id="respHead5" class="resp-head" onclick="window.toggleResp(5)" style="grid-column: span 2; border-bottom: 2px solid #dbeafe; padding: 12px 0 8px 0; margin-top: 20px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                    <span style="color: var(--maquinaria-blue); font-weight: 700; font-size: 14px; text-transform: uppercase;">APROBADO</span>
                    <span style="color:#64748b; font-size:11px; font-style:italic;">Gerente</span>
                    <i class="material-icons resp-chevron" style="{{ $respOpen[5] ? '' : 'transform:rotate(-90deg);' }}">expand_more</i>
                </div>

                <!-- Responsable 5 Inputs -->
                <div class="resp-grid {{ $respOpen[5] ? '' : 'resp-collapsed' }}" id="respBody5" style="grid-column: span 2;">
                    <div>
                        <label for="RESP_5_NOM" class="form-label">Nombre Completo</label>
                        <input type="text" id="RESP_5_NOM" name="RESP_5_NOM" class="form-input-custom"
                            style="background: white;" placeholder="Nombre"
                            value="{{ old('RESP_5_NOM', $frente->RESP_5_NOM ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_5_CAR" class="form-label">Cargo</label>
                        <input type="text" id="RESP_5_CAR" name="RESP_5_CAR" class="form-input-custom"
                            style="background: white;" placeholder="Ej: Gerente General"
                            value="{{ old('RESP_5_CAR', $frente->RESP_5_CAR ?? '') }}" autocomplete="off">
                    </div>

                    <div>
                        <label for="RESP_5_CED" class="form-label">Cédula</label>
                        <input type="text" id="RESP_5_CED" name="RESP_5_CED" class="form-input-custom"
                            style="background: white; text-transform: uppercase;" placeholder="Ej: V-12345678"
                            value="{{ old('RESP_5_CED', $frente->RESP_5_CED ?? '') }}" maxlength="20" autocomplete="off">
                    </div>

                    <div>
                        <span class="form-label">Filtro Firma</span>
                        <div class="custom-dropdown" id="resp5EquSelect">
                            <input type="hidden" name="RESP_5_EQU" id="input_resp5_equ" value="{{ old('RESP_5_EQU', $frente->RESP_5_EQU ?? '') }}">
                            <div class="dropdown-trigger" onclick="toggleDropdown('resp5EquSelect', event)" style="background: white; cursor: default;">
                                <span id="label_resp5_equ">{{ old('RESP_5_EQU', $frente->RESP_5_EQU ?? 'SIN FILTRO') }}</span>
                                <i class="material-icons">expand_more</i>
                            </div>
                            <div class="dropdown-content">
                                <div class="dropdown-item" onclick="selectOption('resp5EquSelect', '', 'SIN FILTRO', 'resp5_equ')">SIN FILTRO</div>
                                @foreach($categorias as $cat)
                                    <div class="dropdown-item" onclick="selectOption('resp5EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp5_equ')">{{ $cat }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="frentes-btn-row"
                style="margin-top: 40px; display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; align-items: center;">
                <a href="{{ route('menu') }}" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </a>

                {{-- Boton Acciones: abre modal con frentes FINALIZADOS para
                     recuperarlos (mark ESTATUS_FRENTE='ACTIVO' de nuevo). --}}
                <button type="button" id="btnAccionesFrentes"
                    onclick="window.abrirModalFinalizados()"
                    class="btn-primary-maquinaria btn-secondary"
                    style="padding: 12px 18px; font-size: 14px; height: auto; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="material-icons" style="font-size: 18px;">history</i>
                    <span>Finalizados</span>
                </button>

                <button type="submit" class="btn-primary-maquinaria"
                    style="padding: 12px 30px; font-size: 14px; height: auto;">
                    <i class="material-icons" style="font-size: 20px;"
                        id="submitBtnIcon">{{ (isset($frente) && $frente->exists) ? 'save' : 'add_circle' }}</i>
                    <span
                        id="submitBtnText">{{ (isset($frente) && $frente->exists) ? 'Guardar Cambios' : 'Registrar' }}</span>
                </button>

                {{-- Boton Eliminar: borra fisicamente el frente de la BD.
                     Bloquea server-side si tiene equipos/auxiliares asignados.
                     Para marcar el frente como FINALIZADO sin borrarlo, el
                     usuario debe usar el campo "Estatus del Proyecto" del form. --}}
                <button type="button" id="btnEliminarFrente"
                    data-frente-id="{{ $frente->ID_FRENTE ?? '' }}"
                    data-frente-nombre="{{ $frente->NOMBRE_FRENTE ?? '' }}"
                    onclick="window.eliminarFrenteSPA(this.dataset.frenteId, this.dataset.frenteNombre)"
                    class="btn-primary-maquinaria"
                    style="padding: 12px 30px; font-size: 14px; height: auto; background: #ef4444; border-color: #ef4444; display: {{ (isset($frente) && $frente->exists) ? 'inline-flex' : 'none' }};">
                    <i class="material-icons" style="font-size: 20px;">delete_outline</i>
                    <span>Eliminar</span>
                </button>
            </div>
        </form>

        {{-- Script siempre disponible: el boton se muestra/oculta segun haya
             un frente cargado (modo edicion o seleccion SPA). --}}
        <script>
            window.eliminarFrenteSPA = function (id, nombre) {
                if (!id) return;

                // Modal corporativo (mismo que usa el resto de la app para
                // confirmaciones destructivas — coherente con showModal API).
                const proceed = function () {
                    if (window.showPreloader) window.showPreloader();
                    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                                 document.querySelector('input[name="_token"]')?.value || '';
                    fetch('{{ url("admin/frentes") }}/' + id, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }).then(handleDeleteResponse).catch(handleDeleteError);
                };

                if (typeof window.showModal === 'function') {
                    window.showModal({
                        type: 'warning',
                        title: 'Eliminar Frente',
                        message: '¿Eliminar el frente "' + nombre + '"?\n\nEsta acción no se puede deshacer. Si tiene equipos o auxiliares asignados, será rechazada.',
                        confirmText: 'Sí, eliminar',
                        cancelText: 'Cancelar',
                        onConfirm: proceed
                    });
                } else if (confirm('¿Eliminar el frente "' + nombre + '"?')) {
                    proceed();
                }
            };

            function handleDeleteResponse(r) {
                return r.json().catch(function () { return {}; }).then(function (data) {
                    if (r.ok && data.success) {
                        // Mantener preloader activo durante la transicion al
                        // create (un solo spinner). Toast en destino via flash.
                        try {
                            sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                                message: data.message || 'Frente eliminado.',
                                type: 'success'
                            }));
                        } catch (_) {}
                        var dest = data.redirect || '{{ route("frentes.create") }}';
                        if (window.navigateTo) window.navigateTo(dest);
                        else window.location.href = dest;
                    } else {
                        if (window.hidePreloader) window.hidePreloader();
                        var msg = data.message || 'No se pudo eliminar el frente.';
                        // Toast efimero (auto-cierra) en vez de modal: el rechazo
                        // por dependencias (equipos/auxiliares asignados) es un
                        // resultado puntual, no requiere bloqueo modal.
                        if (window.showToast) {
                            window.showToast(msg, 'error');
                        } else {
                            alert(msg);
                        }
                    }
                });
            }

            function handleDeleteError(err) {
                if (window.hidePreloader) window.hidePreloader();
                var msg = 'Error de red al eliminar el frente.';
                if (typeof window.showModal === 'function') {
                    window.showModal({ type: 'error', title: 'Error de red', message: msg, confirmText: 'Cerrar', hideCancel: true });
                } else if (window.showToast) {
                    window.showToast(msg, 'error');
                } else {
                    alert(msg);
                }
            }

            // ═══════════════════════════════════════════════════════════
            // MODAL FRENTES FINALIZADOS (papelera lógica)
            // ═══════════════════════════════════════════════════════════
            window.abrirModalFinalizados = function () {
                var existing = document.getElementById('finalizadosOverlay');
                if (existing) existing.remove();

                var overlay = document.createElement('div');
                overlay.id = 'finalizadosOverlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2500;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(2px);';
                overlay.innerHTML = '<div style="background:white;border-radius:16px;width:90%;max-width:520px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">' +
                    '<div style="background:#1e293b;padding:18px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">' +
                        '<div style="display:flex;align-items:center;gap:10px;">' +
                            '<i class="material-icons" style="color:#f59e0b;font-size:20px;">history</i>' +
                            '<h2 style="margin:0;font-size:16px;font-weight:700;">Frentes Finalizados</h2>' +
                        '</div>' +
                        '<button type="button" id="btnCloseFinalizados" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.7">' +
                            '<i class="material-icons">close</i>' +
                        '</button>' +
                    '</div>' +
                    '<div style="padding:18px;">' +
                        '<p style="margin:0 0 12px 0;font-size:12px;color:#64748b;text-align:center;">Frentes en estado FINALIZADO. Click en "Recuperar" para reactivarlos.</p>' +
                        '<div id="finalizadosList" style="max-height:380px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:8px;">' +
                            '<div style="padding:30px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:24px;">sync</i></div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
                document.getElementById('btnCloseFinalizados').onclick = function () { overlay.remove(); };

                window.cargarFrentesFinalizados();
            };

            window.cargarFrentesFinalizados = function () {
                var list = document.getElementById('finalizadosList');
                if (!list) return;
                fetch('{{ route("frentes.finalizados") }}', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.frentes || data.frentes.length === 0) {
                        list.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="font-size:32px;display:block;margin:0 auto 10px;">inbox</i>No hay frentes finalizados</div>';
                        return;
                    }
                    list.innerHTML = data.frentes.map(function (f) {
                        var ubic = f.ubicacion ? '<div style="font-size:11px;color:#64748b;display:flex;align-items:center;gap:3px;"><i class="material-icons" style="font-size:11px;">place</i>' + escapeHtml(f.ubicacion) + '</div>' : '';
                        var fecha = f.finalizado_en ? '<div style="font-size:10px;color:#94a3b8;">Finalizado: ' + escapeHtml(f.finalizado_en) + '</div>' : '';
                        return '<div style="padding:10px;border-radius:8px;background:white;border:1px solid #e2e8f0;margin-bottom:6px;display:flex;align-items:center;gap:12px;">' +
                            '<div style="width:36px;height:36px;background:#fef3c7;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="material-icons" style="color:#d97706;font-size:18px;">domain_disabled</i></div>' +
                            '<div style="flex:1;min-width:0;">' +
                                '<div style="font-weight:700;color:#1e293b;font-size:13px;">' + escapeHtml(f.nombre) + '</div>' +
                                ubic + fecha +
                            '</div>' +
                            '<button type="button" onclick="window.recuperarFrente(' + f.id + ', \'' + escapeAttr(f.nombre) + '\')" class="btn-primary-maquinaria" style="padding:6px 12px;font-size:12px;height:auto;background:#10b981;border-color:#10b981;display:inline-flex;align-items:center;gap:4px;">' +
                                '<i class="material-icons" style="font-size:14px;">restore</i>Recuperar' +
                            '</button>' +
                        '</div>';
                    }).join('');
                })
                .catch(function () {
                    list.innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;font-size:13px;">Error al cargar los frentes finalizados.</div>';
                });
            };

            window.recuperarFrente = function (id, nombre) {
                if (typeof window.showModal !== 'function') {
                    if (!confirm('¿Recuperar el frente "' + nombre + '"?')) return;
                    _doRestore(id);
                    return;
                }
                window.showModal({
                    type: 'info',
                    title: 'Recuperar Frente',
                    message: '¿Reactivar el frente "' + nombre + '"?\n\nVolverá a aparecer en los filtros y dropdowns.',
                    confirmText: 'Sí, recuperar',
                    cancelText: 'Cancelar',
                    onConfirm: function () { _doRestore(id); }
                });
            };

            function _doRestore(id) {
                if (window.showPreloader) window.showPreloader();
                var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                           document.querySelector('input[name="_token"]')?.value || '';
                fetch('{{ url("admin/frentes") }}/' + id + '/restore', {
                    method: 'PATCH',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, body: d }; }); })
                .then(function (res) {
                    if (window.hidePreloader) window.hidePreloader();
                    if (res.ok && res.body.success) {
                        if (window.showToast) window.showToast(res.body.message || 'Frente recuperado.', 'success');
                        // Refrescar lista del modal y el dropdown principal de busqueda.
                        window.cargarFrentesFinalizados();
                        if (typeof window.addToSearchList === 'function' && res.body.frente) {
                            // Reagregar al dropdown de busqueda principal sin recargar.
                            window.addToSearchList({
                                ID_FRENTE: res.body.frente.id,
                                NOMBRE_FRENTE: res.body.frente.nombre
                            });
                        }
                    } else {
                        var msg = (res.body && res.body.message) || 'No se pudo recuperar el frente.';
                        if (window.showToast) window.showToast(msg, 'error');
                        else alert(msg);
                    }
                })
                .catch(function () {
                    if (window.hidePreloader) window.hidePreloader();
                    if (window.showToast) window.showToast('Error de red al recuperar el frente.', 'error');
                });
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function escapeAttr(s) {
                return String(s == null ? '' : s).replace(/'/g, "\\'").replace(/"/g, '&quot;');
            }

            // Acordeón de responsables: expande/colapsa el bloque de campos al
            // hacer clic en su encabezado y gira el chevron.
            window.toggleResp = function (n) {
                var body = document.getElementById('respBody' + n);
                var head = document.getElementById('respHead' + n);
                if (!body) return;
                var collapsed = body.classList.toggle('resp-collapsed');
                var chev = head ? head.querySelector('.resp-chevron') : null;
                if (chev) chev.style.transform = collapsed ? 'rotate(-90deg)' : '';
            };

            // ═══════════════════════════════════════════════════════════
            // MODAL FRENTES SIN EQUIPOS (candidatos a desactivar / eliminar)
            // ═══════════════════════════════════════════════════════════
            window.abrirModalSinEquipos = function () {
                var existing = document.getElementById('sinEquiposOverlay');
                if (existing) existing.remove();

                var overlay = document.createElement('div');
                overlay.id = 'sinEquiposOverlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2500;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(2px);';
                overlay.innerHTML = '<div style="background:white;border-radius:14px;width:90%;max-width:460px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">' +
                    '<div style="background:#1e293b;padding:12px 16px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">' +
                        '<div style="display:flex;align-items:center;gap:10px;">' +
                            '<i class="material-icons" style="color:#0284c7;font-size:20px;">domain_disabled</i>' +
                            '<h2 style="margin:0;font-size:16px;font-weight:700;">Frentes sin equipos</h2>' +
                        '</div>' +
                        '<button type="button" id="btnCloseSinEquipos" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.7">' +
                            '<i class="material-icons">close</i>' +
                        '</button>' +
                    '</div>' +
                    '<div style="padding:14px 16px;">' +
                        '<p style="margin:0 0 8px 0;font-size:11px;color:#64748b;text-align:center;line-height:1.35;">Frentes activos sin equipos ni auxiliares. Puedes desactivarlos o eliminarlos.</p>' +
                        '<div id="sinEquiposList" style="max-height:380px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:8px;">' +
                            '<div style="padding:30px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:24px;">sync</i></div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
                document.body.appendChild(overlay);
                overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
                document.getElementById('btnCloseSinEquipos').onclick = function () { overlay.remove(); };

                window.cargarFrentesSinEquipos();
            };

            window.cargarFrentesSinEquipos = function () {
                var list = document.getElementById('sinEquiposList');
                if (!list) return;
                fetch('{{ route("frentes.sinEquipos") }}', {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.frentes || data.frentes.length === 0) {
                        list.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="font-size:32px;display:block;margin:0 auto 10px;">check_circle</i>Todos los frentes activos tienen equipos</div>';
                        return;
                    }
                    list.innerHTML = data.frentes.map(function (f) {
                        var ubic = f.ubicacion ? '<div style="font-size:11px;color:#64748b;display:flex;align-items:center;gap:3px;"><i class="material-icons" style="font-size:11px;">place</i>' + escapeHtml(f.ubicacion) + '</div>' : '';
                        return '<div style="padding:10px;border-radius:8px;background:white;border:1px solid #e2e8f0;margin-bottom:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
                            '<div style="width:36px;height:36px;background:#e0f2fe;border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="material-icons" style="color:#0284c7;font-size:18px;">domain_disabled</i></div>' +
                            '<div style="flex:1;min-width:120px;">' +
                                '<div style="font-weight:700;color:#1e293b;font-size:13px;">' + escapeHtml(f.nombre) + '</div>' +
                                ubic +
                            '</div>' +
                            '<button type="button" onclick="window._sinEquiposDesactivar(' + f.id + ', \'' + escapeAttr(f.nombre) + '\')" class="btn-primary-maquinaria" style="padding:6px 12px;font-size:12px;height:auto;background:#f59e0b;border-color:#f59e0b;display:inline-flex;align-items:center;gap:4px;">' +
                                '<i class="material-icons" style="font-size:14px;">block</i>Desactivar' +
                            '</button>' +
                            '<button type="button" onclick="window._sinEquiposEliminar(' + f.id + ', \'' + escapeAttr(f.nombre) + '\')" class="btn-primary-maquinaria" style="padding:6px 12px;font-size:12px;height:auto;background:#ef4444;border-color:#ef4444;display:inline-flex;align-items:center;gap:4px;">' +
                                '<i class="material-icons" style="font-size:14px;">delete_outline</i>Eliminar' +
                            '</button>' +
                        '</div>';
                    }).join('');
                })
                .catch(function () {
                    list.innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;font-size:13px;">Error al cargar los frentes.</div>';
                });
            };

            function _sinEquiposAction(method, url, okMsg, errFallback, nombre) {
                if (window.showPreloader) window.showPreloader();
                var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
                           document.querySelector('input[name="_token"]')?.value || '';
                fetch(url, { method: method, headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, body: d }; }); })
                .then(function (res) {
                    if (window.hidePreloader) window.hidePreloader();
                    if (res.ok && res.body.success) {
                        if (window.showToast) window.showToast(res.body.message || okMsg, 'success');
                        window.cargarFrentesSinEquipos();
                        if (typeof window.removeFromSearchList === 'function') window.removeFromSearchList(nombre);
                    } else {
                        if (window.showToast) window.showToast((res.body && res.body.message) || errFallback, 'error');
                        else alert((res.body && res.body.message) || errFallback);
                    }
                })
                .catch(function () {
                    if (window.hidePreloader) window.hidePreloader();
                    if (window.showToast) window.showToast('Error de red.', 'error');
                });
            }

            window._sinEquiposDesactivar = function (id, nombre) {
                var run = function () { _sinEquiposAction('PATCH', '{{ url("admin/frentes") }}/' + id + '/finalizar', 'Frente desactivado.', 'No se pudo desactivar.', nombre); };
                if (typeof window.showModal === 'function') {
                    window.showModal({ type: 'warning', title: 'Desactivar Frente', message: 'El frente "' + nombre + '" dejará de aparecer en los dropdowns; puedes recuperarlo desde "Finalizados".', confirmText: 'Sí, desactivar', cancelText: 'Cancelar', onConfirm: run });
                } else if (confirm('¿Desactivar el frente "' + nombre + '"?')) { run(); }
            };

            window._sinEquiposEliminar = function (id, nombre) {
                var run = function () { _sinEquiposAction('DELETE', '{{ url("admin/frentes") }}/' + id, 'Frente eliminado.', 'No se pudo eliminar.', nombre); };
                if (typeof window.showModal === 'function') {
                    window.showModal({ type: 'warning', title: 'Eliminar Frente', message: '¿Eliminar el frente "' + nombre + '"?\n\nEsta acción no se puede deshacer.', confirmText: 'Sí, eliminar', cancelText: 'Cancelar', onConfirm: run });
                } else if (confirm('¿Eliminar el frente "' + nombre + '"?')) { run(); }
            };

            // ── Chips de "Contratos asociados" ──────────────────────────────────
            // El hidden #CONTRATOS_HIDDEN guarda los valores como CSV
            // ("CTR-2026-0042,CTR-2026-0099"). El backend (FrenteRequest::prepareForValidation)
            // splittea por coma/punto-y-coma/salto de línea y normaliza a array UPPER.
            (function () {
                var hidden = document.getElementById('CONTRATOS_HIDDEN');
                var box    = document.getElementById('contratos_chip_box');
                var input  = document.getElementById('contratos_input');
                if (!hidden || !box || !input) return;

                function parseList() {
                    return String(hidden.value || '')
                        .split(',')
                        .map(function (s) { return s.trim(); })
                        .filter(function (s) { return s.length > 0; });
                }
                function writeList(list) {
                    var dedup = [];
                    list.forEach(function (v) { if (v && dedup.indexOf(v) === -1) dedup.push(v); });
                    hidden.value = dedup.join(',');
                    render();
                }
                function render() {
                    // Limpia los chips anteriores (todo lo que no sea el input).
                    Array.prototype.slice.call(box.children).forEach(function (c) {
                        if (c !== input) c.remove();
                    });
                    parseList().forEach(function (val) {
                        var chip = document.createElement('span');
                        chip.style.cssText = 'background:#e0f2fe;color:#0369a1;padding:3px 4px 3px 10px;border-radius:99px;font-size:12.5px;font-weight:700;display:inline-flex;align-items:center;gap:3px;font-family:monospace;';
                        chip.innerHTML = escapeHtml(val) +
                            ' <i class="material-icons" style="font-size:15px;cursor:pointer;color:#0284c7;" title="Quitar">close</i>';
                        chip.querySelector('i').addEventListener('click', function () {
                            var current = parseList();
                            var idx = current.indexOf(val);
                            if (idx !== -1) { current.splice(idx, 1); writeList(current); }
                        });
                        box.insertBefore(chip, input);
                    });
                }
                function addFromInput() {
                    var raw = String(input.value || '').toUpperCase().trim();
                    if (!raw) return;
                    var partes = raw.split(/[,;\n\r]+/).map(function (s) { return s.trim(); }).filter(Boolean);
                    if (partes.length === 0) return;
                    writeList(parseList().concat(partes));
                    input.value = '';
                }
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
                        e.preventDefault();
                        addFromInput();
                    } else if (e.key === 'Backspace' && input.value === '') {
                        var current = parseList();
                        if (current.length) { current.pop(); writeList(current); }
                    }
                });
                input.addEventListener('blur', addFromInput);
                // Clic en cualquier parte del wrapper enfoca el input.
                box.addEventListener('click', function (e) { if (e.target === box) input.focus(); });

                // Render inicial — usa el value renderizado por blade (old() o frente existente).
                render();

                // Exponer para que el SPA reset (al cancelar/crear nuevo) lo reaproveche.
                window.contratosReset = function (newCsv) {
                    hidden.value = String(newCsv || '');
                    input.value = '';
                    render();
                };
            })();
        </script>
    </div>
@endsection