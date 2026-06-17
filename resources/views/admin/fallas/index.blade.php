@extends('layouts.estructura_base')
@section('title', 'Reportes de Fallas')

@section('content')
    <style>
        /* Mismas medidas que el .page-layout-grid de /admin/equipos para que el
           contenedor blanco quede del mismo ancho: 100%, sidebar 300px, gap 14px. */
        .fallas-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 300px;
            gap: 14px;
            width: 100%;
            max-width: 1600px;
            margin: 0 auto;
        }

        @media (max-width: 768px) {
            .fallas-grid {
                grid-template-columns: minmax(0, 1fr) !important;
                width: 100% !important;
            }

            .falla-row-card {
                grid-template-columns: 80px minmax(0, 1fr) !important;
                padding: 10px 8px !important;
                align-items: start !important;
            }

            .falla-foto {
                width: 80px !important;
                height: 80px !important;
            }

            .falla-foto .material-icons {
                font-size: 24px !important;
            }

            /* En móvil la descripción pasa a ancho completo (no al lado) y, si es
               larga, se trunca a 2 líneas. Al tocar la tarjeta se expande (.falla-expanded). */
            .falla-descripcion {
                grid-column: 1 / -1 !important;
                border-left: none !important;
                padding-left: 0 !important;
                border-top: 1px solid #f1f5f9;
                padding-top: 8px !important;
                margin-top: 6px !important;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                cursor: pointer;
            }

            .falla-row-card.falla-expanded .falla-descripcion {
                -webkit-line-clamp: unset;
                overflow: visible;
            }

            /* "Reportó" se REVELA al tocar la tarjeta (.falla-expanded), igual que el
               detalle en el módulo de equipos en móvil; oculto mientras está colapsada.
               El de la columna de acciones (desktop) se oculta siempre en móvil. */
            .falla-reporto-mobile { display: none !important; }
            .falla-row-card.falla-expanded .falla-reporto-mobile { display: flex !important; }
            .falla-actions > .falla-reporto-desktop { display: none !important; }

            /* Pista "Ver quién reportó": visible colapsada, se oculta al expandir
               (entonces aparece el dato). Afordancia tipo expand_more de equipos. */
            .falla-tap-hint {
                display: flex !important; align-items: center; gap: 3px;
                margin-top: 4px; font-size: 11px; color: #94a3b8;
            }
            .falla-row-card.falla-expanded .falla-tap-hint { display: none !important; }

            .falla-actions {
                grid-column: 1 / -1 !important;
                flex-direction: row !important;
                justify-content: flex-end !important;
                align-items: center !important;
                border-top: 1px solid #f1f5f9;
                padding-top: 12px !important;
                margin-top: 8px !important;
                width: 100%;
            }

            .falla-actions>div:first-child {
                text-align: left !important;
                margin-right: auto !important;
            }

            /* PDF y Cerrar uno al lado del otro (no apilados) en móvil. */
            .falla-btn-stack {
                flex-direction: row !important;
            }

            .fallas-header-container {
                justify-content: center !important;
                text-align: center !important;
            }

            .fallas-title-h1 {
                text-align: center !important;
                justify-content: center !important;
                width: 100%;
            }

            .fallas-btn-new {
                justify-content: center !important;
                width: 100% !important;
            }

            .counter-sidebar { display: none !important; }
            .fallas-mobile-stats { display: block !important; margin-bottom: 15px; }
        }

        .fallas-mobile-stats { display: none; }


        /* Paneles de "Consolidado de Fallas": cursor normal (flecha, no el de
           texto) sobre los números/labels de solo lectura. NO se bloquea la
           selección ni la interacción del contenido. */
        .counter-sidebar,
        .fallas-mobile-stats {
            cursor: default;
        }

        .falla-row-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            display: grid;
            grid-template-columns: 170px minmax(0, 1fr) minmax(0, 1.2fr) auto;
            gap: 14px;
            align-items: center;
            transition: box-shadow 0.15s;
        }

        /* Descripción de la avería: columna al lado de la info, separada por una línea */
        .falla-descripcion {
            font-size: 12px;
            color: #475569;
            font-style: italic;
            line-height: 1.4;
            min-width: 0;
            align-self: center;
            border-left: 1px solid #e2e8f0;
            padding-left: 14px;
            overflow-wrap: break-word;
            word-break: break-word;
        }

        .falla-row-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .falla-foto {
            position: relative;
            width: 170px;
            height: 105px;
            border-radius: 8px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
        }

        .falla-foto img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .falla-foto .material-icons {
            color: #cbd5e0;
            font-size: 32px;
        }

        /* Columna de la foto: frente (arriba, fuera de la foto) + foto, como /admin/equipos */
        .falla-foto-col {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        /* Frente FUERA de la foto, arriba (mismo idioma visual que la tabla de equipos):
           texto centrado en MAYÚSCULAS que se reparte en varias líneas si es largo. */
        .falla-foto-frente {
            font-size: 10px;
            font-weight: 700;
            color: #1e293b;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            line-height: 1.2;
            text-align: center;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .falla-meta {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .falla-codigo {
            font-size: 10px;
            font-weight: 800;
            color: #0067b1;
            letter-spacing: 0.4px;
        }

        .falla-equipo {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.3;
        }

        .falla-info {
            font-size: 12px;
            color: #64748b;
            word-break: break-word;
            overflow-wrap: break-word;
        }

        /* "Reportó" dentro del .falla-meta: solo visible en móvil (en escritorio va
           en la columna de acciones, arriba-derecha). */
        .falla-reporto-mobile {
            display: none;
            align-items: center;
            gap: 4px;
            margin-top: 3px;
            font-size: 12px;
            color: #64748b;
        }

        /* Pista de toque: solo en móvil (ver media query); oculta en escritorio. */
        .falla-tap-hint { display: none; }

        .falla-actions {
            display: flex;
            gap: 6px;
        }

        .falla-btn {
            height: 32px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            color: #475569;
            text-decoration: none;
        }

        .falla-btn:hover {
            background: #f8fafc;
        }

        .falla-btn-primary {
            background: #0067b1;
            color: white;
            border-color: #0067b1;
        }

        .falla-btn-primary:hover {
            background: #0a5599;
        }

        /* Borrado duro (super.admin): discreto, icono solo, rojo al hover. */
        .falla-btn-danger {
            padding: 0 8px;
            color: #94a3b8;
            border-color: #e2e8f0;
        }

        .falla-btn-danger:hover {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }

        /* Modal */
        /* Estilos del modal "Nuevo Reporte de Falla" (fl-*) movidos a
           partials/create_modal.blade.php (compartido). El modal de cierre
           de abajo reutiliza esas mismas clases. */

        /* Letra de las listas del filtro avanzado un poco más pequeña
           (scoped a #fallasAdvPanel para no afectar otros módulos). */
        #fallasAdvPanel .dropdown-item {
            font-size: 12.5px;
        }
    </style>

    <div class="fallas-header-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; margin-top: 5px; width: 100%; max-width: 1600px; margin-left: auto; margin-right: auto;">
        <h1 class="page-title fallas-title-h1" style="margin: 0;">
            <span class="page-title-line2" style="color: #000;">Reportes de Fallas</span>
        </h1>
    </div>

    <div class="fallas-grid">

        {{-- Columna principal: filtros + tabla --}}
        <div>
            <div class="admin-card" style="margin:0; padding:14px;">

                @php
                    $advActive = request()->filled('tipo_activo') || request()->filled('id_frente')
                        || request()->filled('responsable') || request()->filled('marca')
                        || request()->filled('modelo') || request()->filled('fecha_desde')
                        || request()->filled('fecha_hasta') || request()->filled('estatus');

                    $estatusSel = request('estatus');
                    $estatusLabels = ['abierto' => 'Reportes Abiertos', 'cerrado' => 'Reportes Cerrados'];
                    $estatusLabel = $estatusLabels[$estatusSel] ?? 'Todos los reportes';

                    $tipoActivoSel = request('tipo_activo');
                    if (!$tipoActivoSel) {
                        $tipoActivoLabel = 'Todos los activos';
                    } elseif ($tipoActivoSel === 'equipo') {
                        $tipoActivoLabel = 'Vehiculos (todos)';
                    } elseif ($tipoActivoSel === 'equipo_auxiliar') {
                        $tipoActivoLabel = 'Auxiliares (todos)';
                    } elseif (str_starts_with($tipoActivoSel, 'tipo_eq:')) {
                        $teId = (int) substr($tipoActivoSel, 8);
                        $teObj = $tiposEquipo->firstWhere('id', $teId);
                        $tipoActivoLabel = $teObj ? $teObj->nombre : $tipoActivoSel;
                    } elseif (str_starts_with($tipoActivoSel, 'tipo_aux:')) {
                        $tipoActivoLabel = substr($tipoActivoSel, 9);
                    } else {
                        $tipoActivoLabel = 'Todos los activos';
                    }

                    $frenteSel = request('id_frente');
                    $frenteObj = $frenteSel ? $frentes->firstWhere('ID_FRENTE', (int) $frenteSel) : null;
                    $frenteLabel = $frenteObj ? $frenteObj->NOMBRE_FRENTE : 'Todos los frentes';

                    $respSel = request('responsable');
                    $respObj = $respSel ? $responsables->firstWhere('ID_USUARIO', (int) $respSel) : null;
                    $respLabel = $respObj ? $respObj->NOMBRE_COMPLETO : 'Todos los responsables';
                @endphp

                {{-- Toolbar (estilo /admin/equipos: custom-dropdown) --}}
                <div class="filter-toolbar-container"
                    style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:12px;">

                    {{-- Frente (en barra principal) --}}
                    <div class="filter-item aligned-filter" style="flex:2; min-width:220px; max-width:340px;">
                        <div class="custom-dropdown" id="fallasFrenteDD" data-filter-type="id_frente"
                            data-default-label="Todos los frentes">
                            <input type="hidden" id="fallasFrente" data-filter-value value="{{ $frenteSel }}">
                            <div class="dropdown-trigger {{ $frenteSel ? 'filter-active' : '' }}"
                                style="padding:0; display:flex; align-items:center; background:{{ $frenteSel ? '#e1effa' : '#fbfcfd' }}; overflow:hidden; border:1px solid {{ $frenteSel ? '#0067b1' : '#cbd5e0' }}; border-radius:12px; height:45px;">
                                <div style="padding:0 10px; color:#64748b;"><i class="material-icons"
                                        style="font-size:18px;">search</i></div>
                                <input type="text" name="filter_search_dropdown" data-filter-search
                                    placeholder="{{ $frenteLabel }}" aria-label="Filtrar Frente"
                                    style="width:100%; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none;"
                                    oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                <i class="material-icons" data-clear-btn
                                    style="padding:0 5px; color:#64748b; font-size:18px; display:{{ $frenteSel ? 'block' : 'none' }};"
                                    onclick="event.stopPropagation(); window.clearDropdownFilter('fallasFrenteDD'); window.cargarFallas();">close</i>
                            </div>
                            <div class="dropdown-content"
                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                                    <div class="dropdown-item {{ !$frenteSel ? 'selected' : '' }}" data-value=""
                                        onclick="window.selectOption('fallasFrenteDD','','Todos los frentes'); window.cargarFallas();">
                                        Todos los frentes</div>
                                    @foreach($frentes as $fr)
                                        <div class="dropdown-item {{ $frenteSel == $fr->ID_FRENTE ? 'selected' : '' }}"
                                            data-value="{{ $fr->ID_FRENTE }}"
                                            onclick="window.selectOption('fallasFrenteDD','{{ $fr->ID_FRENTE }}','{{ addslashes(trim($fr->NOMBRE_FRENTE)) }}'); window.cargarFallas();">
                                            {{ $fr->NOMBRE_FRENTE }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Tipo de Activo (en barra principal) --}}
                    <div class="filter-item aligned-filter" style="flex:2; min-width:220px; max-width:340px;">
                        <div class="custom-dropdown" id="fallasTipoActivoDD" data-filter-type="tipo_activo"
                            data-default-label="Todos los activos">
                            <input type="hidden" id="fallasTipoActivo" data-filter-value value="{{ $tipoActivoSel }}">
                            <div class="dropdown-trigger {{ $tipoActivoSel ? 'filter-active' : '' }}"
                                style="padding:0; display:flex; align-items:center; background:{{ $tipoActivoSel ? '#e1effa' : '#fbfcfd' }}; overflow:hidden; border:1px solid {{ $tipoActivoSel ? '#0067b1' : '#cbd5e0' }}; border-radius:12px; height:45px;">
                                <div style="padding:0 10px; color:#64748b;"><i class="material-icons"
                                        style="font-size:18px;">search</i></div>
                                <input type="text" name="filter_search_dropdown" data-filter-search
                                    placeholder="{{ $tipoActivoLabel }}" aria-label="Filtrar Tipo de Activo"
                                    style="width:100%; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none;"
                                    oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                <i class="material-icons" data-clear-btn
                                    style="padding:0 5px; color:#64748b; font-size:18px; display:{{ $tipoActivoSel ? 'block' : 'none' }};"
                                    onclick="event.stopPropagation(); window.clearDropdownFilter('fallasTipoActivoDD'); window.cargarFallas();">close</i>
                            </div>
                            <div class="dropdown-content"
                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                                    <div class="dropdown-item {{ !$tipoActivoSel ? 'selected' : '' }}" data-value=""
                                        onclick="window.selectOption('fallasTipoActivoDD','','Todos los activos'); window.cargarFallas();">
                                        Todos los activos</div>
                                    {{-- Grupo Vehículos --}}
                                    @if($tiposEquipo->count())
                                        <div
                                            style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">
                                            VEHÍCULOS</div>
                                        @foreach($tiposEquipo as $te)
                                            <div class="dropdown-item {{ $tipoActivoSel == 'tipo_eq:' . $te->id ? 'selected' : '' }}"
                                                data-value="tipo_eq:{{ $te->id }}"
                                                onclick="window.selectOption('fallasTipoActivoDD','tipo_eq:{{ $te->id }}','{{ addslashes($te->nombre) }}'); window.cargarFallas();">
                                                {{ $te->nombre }}</div>
                                        @endforeach
                                    @endif
                                    {{-- Grupo Auxiliares --}}
                                    @if($tiposAux->count())
                                        <div
                                            style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">
                                            AUXILIARES</div>
                                        @foreach($tiposAux as $ta)
                                            <div class="dropdown-item {{ $tipoActivoSel == 'tipo_aux:' . $ta ? 'selected' : '' }}"
                                                data-value="tipo_aux:{{ $ta }}"
                                                onclick="window.selectOption('fallasTipoActivoDD','tipo_aux:{{ $ta }}','{{ addslashes($ta) }}'); window.cargarFallas();">
                                                {{ $ta }}</div>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Buscar por serial / placa + Boton Filtros Avanzados --}}
                    <div class="filter-item aligned-filter"
                        style="flex:1.5; min-width:260px; display:flex; gap:10px; align-items:center;">
                        <div class="search-wrapper"
                            style="flex:1; border-color:{{ request('search') ? '#0067b1' : '#cbd5e0' }}; background:{{ request('search') ? '#e1effa' : '#fff' }};">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="fallasSearch" name="search" value="{{ request('search') }}"
                                placeholder="Seriales y Placas" class="search-input-field" autocomplete="off"
                                oninput="
                                    window._flDebounce && clearTimeout(window._flDebounce);
                                    const _v = this.value;
                                    if (_v.length === 0) { window.cargarFallas(); return; }
                                    if (_v.length < 3) return;
                                    window._flDebounce = setTimeout(window.cargarFallas, 500);
                                ">
                            <i id="fallasSearchClear" class="material-icons clear-icon"
                                style="display:{{ request('search') ? 'block' : 'none' }};"
                                onclick="event.preventDefault(); event.stopPropagation(); document.getElementById('fallasSearch').value=''; this.style.display='none'; window.cargarFallas();">close</i>
                        </div>

                        {{-- Boton Filtros Avanzados --}}
                        <div style="position:relative; flex-shrink:0;">
                            <button type="button" id="fallasAdvBtn" class="btn-primary-maquinaria"
                                onclick="const p=document.getElementById('fallasAdvPanel'); p.style.display=(p.style.display==='none'||!p.style.display)?'block':'none'; event.stopPropagation();"
                                title="Filtros Avanzados" style="height:45px; width:45px; min-width:45px; padding:0; display:flex; align-items:center; justify-content:center;
                           background:{{ $advActive ? '#fee2e2' : 'white' }}; border:1px solid {{ $advActive ? '#ef4444' : '#cbd5e0' }};
                           color:{{ $advActive ? '#ef4444' : '#64748b' }}; box-shadow:none;">
                                <i class="material-icons">filter_list</i>
                            </button>

                            <div id="fallasAdvPanel" style="display:none; position:absolute; top:100%; right:0; width:300px; max-width:calc(100vw - 20px);
                 background:#e2e8f0; border:1px solid #cbd5e1; border-radius:12px;
                 box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); margin-top:10px; padding:15px; z-index:500;">

                                <h4
                                    style="margin:0 0 14px 0; font-size:14px; font-weight:700; color:#334155; display:flex; justify-content:space-between; align-items:center;">
                                    Filtros Avanzados
                                    <span
                                        style="font-size:11px; color:#64748b; font-weight:400; text-decoration:underline; cursor:pointer;"
                                        onclick="window.flClearAdv()">Limpiar Todo</span>
                                </h4>

                                <div style="display:flex; flex-direction:column; gap:10px;">

                                    {{-- Estado del Reporte + Responsable: lado a lado --}}
                                    <div style="display:grid; grid-template-columns:{{ $responsables->count() > 0 ? '1fr 1fr' : '1fr' }}; gap:8px; align-items:start;">

                                    {{-- Estado del Reporte --}}
                                    <div>
                                        <span
                                            style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Estado
                                            del Reporte</span>
                                        <div class="custom-dropdown" id="fallasEstatusDD" data-filter-type="estatus"
                                            data-default-label="Todos los reportes" style="font-size:12px;">
                                            <input type="hidden" id="fallasEstatus" data-filter-value
                                                value="{{ $estatusSel }}">
                                            <div class="dropdown-trigger"
                                                style="padding:0; display:flex; align-items:center; background:{{ $estatusSel ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                                                <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons"
                                                        style="font-size:16px;">flag</i></div>
                                                <input type="text" name="filter_search_dropdown" data-filter-search
                                                    placeholder="{{ $estatusLabel }}"
                                                    style="width:100%; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                                    oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                                <i class="material-icons" data-clear-btn
                                                    style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ $estatusSel ? 'block' : 'none' }};"
                                                    onclick="event.stopPropagation(); window.clearDropdownFilter('fallasEstatusDD'); window.cargarFallas();">close</i>
                                            </div>
                                            <div class="dropdown-content"
                                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                                <div class="dropdown-item-list">
                                                    <div class="dropdown-item {{ $estatusSel == 'abierto' ? 'selected' : '' }}"
                                                        data-value="abierto"
                                                        onclick="window.selectOption('fallasEstatusDD','abierto','Reportes Abiertos'); window.cargarFallas();">
                                                        Reportes Abiertos</div>
                                                    <div class="dropdown-item {{ $estatusSel == 'cerrado' ? 'selected' : '' }}"
                                                        data-value="cerrado"
                                                        onclick="window.selectOption('fallasEstatusDD','cerrado','Reportes Cerrados'); window.cargarFallas();">
                                                        Reportes Cerrados</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>



                                    {{-- Frente movido a barra principal --}}

                                    {{-- Responsable (custom-dropdown buscable) --}}
                                    @if($responsables->count() > 0)
                                        <div>
                                            <span
                                                style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Responsable</span>
                                            <div class="custom-dropdown" id="fallasResponsableDD" data-filter-type="responsable"
                                                data-default-label="Todos los responsables" style="font-size:12px;">
                                                <input type="hidden" id="fallasResponsable" data-filter-value
                                                    value="{{ $respSel }}">
                                                <div class="dropdown-trigger"
                                                    style="padding:0; display:flex; align-items:center; background:{{ $respSel ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                                                    <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons"
                                                            style="font-size:16px;">search</i></div>
                                                    <input type="text" name="filter_search_dropdown" data-filter-search
                                                        placeholder="{{ $respLabel }}"
                                                        style="width:100%; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                                        oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                                    <i class="material-icons" data-clear-btn
                                                        style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ $respSel ? 'block' : 'none' }};"
                                                        onclick="event.stopPropagation(); window.clearDropdownFilter('fallasResponsableDD'); window.cargarFallas();">close</i>
                                                </div>
                                                <div class="dropdown-content"
                                                    style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                                    <div class="dropdown-item-list" style="max-height:160px; overflow-y:auto;">
                                                        @foreach($responsables as $r)
                                                            <div class="dropdown-item {{ $respSel == $r->ID_USUARIO ? 'selected' : '' }}"
                                                                data-value="{{ $r->ID_USUARIO }}"
                                                                onclick="window.selectOption('fallasResponsableDD','{{ $r->ID_USUARIO }}','{{ addslashes(trim($r->NOMBRE_COMPLETO)) }}'); window.cargarFallas();">
                                                                {{ $r->NOMBRE_COMPLETO }}</div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                    </div>{{-- /Estado del Reporte + Responsable --}}

                                    {{-- Marca + Modelo: dropdowns con autocomplete sobre datos reales --}}
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                        <div>
                                            <span
                                                style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Marca</span>
                                            <div class="custom-dropdown" id="fallasMarcaDD" data-filter-type="marca"
                                                data-default-label="Marca..." style="font-size:12px;">
                                                <input type="hidden" id="fallasMarca" data-filter-value
                                                    value="{{ request('marca') }}">
                                                <div class="dropdown-trigger"
                                                    style="padding:0; display:flex; align-items:center; background:{{ request('marca') ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                                                    <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons"
                                                            style="font-size:16px;">search</i></div>
                                                    <input type="text" name="filter_search_dropdown" data-filter-search
                                                        placeholder="{{ request('marca') ?: 'Marca...' }}"
                                                        style="width:100%; min-width:0; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                                        oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                                    <i class="material-icons" data-clear-btn
                                                        style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ request('marca') ? 'block' : 'none' }};"
                                                        onclick="event.stopPropagation(); window.clearDropdownFilter('fallasMarcaDD'); window.cargarFallas();">close</i>
                                                </div>
                                                <div class="dropdown-content"
                                                    style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                                    <div class="dropdown-item-list"
                                                        style="max-height:150px; overflow-y:auto;">
                                                        @foreach($availableMarcas as $m)
                                                            @if(trim($m) !== '')
                                                                <div class="dropdown-item {{ request('marca') == $m ? 'selected' : '' }}"
                                                                    data-value="{{ $m }}"
                                                                    onclick="window.selectOption('fallasMarcaDD','{{ addslashes(trim($m)) }}','{{ addslashes(trim($m)) }}'); window.cargarFallas();">
                                                                    {{ $m }}</div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div>
                                            <span
                                                style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Modelo</span>
                                            <div class="custom-dropdown" id="fallasModeloDD" data-filter-type="modelo"
                                                data-default-label="Modelo..." style="font-size:12px;">
                                                <input type="hidden" id="fallasModelo" data-filter-value
                                                    value="{{ request('modelo') }}">
                                                <div class="dropdown-trigger"
                                                    style="padding:0; display:flex; align-items:center; background:{{ request('modelo') ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                                                    <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons"
                                                            style="font-size:16px;">search</i></div>
                                                    <input type="text" name="filter_search_dropdown" data-filter-search
                                                        placeholder="{{ request('modelo') ?: 'Modelo...' }}"
                                                        style="width:100%; min-width:0; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                                        oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                                    <i class="material-icons" data-clear-btn
                                                        style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ request('modelo') ? 'block' : 'none' }};"
                                                        onclick="event.stopPropagation(); window.clearDropdownFilter('fallasModeloDD'); window.cargarFallas();">close</i>
                                                </div>
                                                <div class="dropdown-content"
                                                    style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                                    <div class="dropdown-item-list"
                                                        style="max-height:150px; overflow-y:auto;">
                                                        @foreach($availableModelos as $mo)
                                                            @if(trim($mo) !== '')
                                                                <div class="dropdown-item {{ request('modelo') == $mo ? 'selected' : '' }}"
                                                                    data-value="{{ $mo }}"
                                                                    onclick="window.selectOption('fallasModeloDD','{{ addslashes(trim($mo)) }}','{{ addslashes(trim($mo)) }}'); window.cargarFallas();">
                                                                    {{ $mo }}</div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Fechas --}}
                                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                                        <div>
                                            <span
                                                style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Desde</span>
                                            <input type="date" id="fallasFechaDesde" class="fl-input"
                                                style="height:32px; font-size:12px; width:100%; cursor:pointer;"
                                                value="{{ request('fecha_desde') }}" onchange="window.cargarFallas()"
                                                onclick="if(this.showPicker)this.showPicker()">
                                        </div>
                                        <div>
                                            <span
                                                style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Hasta</span>
                                            <input type="date" id="fallasFechaHasta" class="fl-input"
                                                style="height:32px; font-size:12px; width:100%; cursor:pointer;"
                                                value="{{ request('fecha_hasta') }}" onchange="window.cargarFallas()"
                                                onclick="if(this.showPicker)this.showPicker()">
                                        </div>
                                    </div>

                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="button"
                        onclick="{{ auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('super.admin')) ? 'window.openNuevoReporteModal()' : 'if(window.showToast) window.showToast(\'No tienes los permisos requeridos para registrar fallas.\', \'error\'); else alert(\'Permiso denegado.\');' }}"
                        class="falla-btn falla-btn-primary fallas-btn-new" style="height:45px;">
                        <i class="material-icons" style="font-size:18px;">add_circle</i> Reporte
                    </button>
                </div>

                {{-- Stats compactas solo en móvil --}}
                <div class="fallas-mobile-stats" style="background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 10px 14px; margin-bottom: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.15);">
                    <div style="font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; opacity: 0.75; margin-bottom: 6px; display: flex; align-items: center; gap: 5px;">
                        <i class="material-icons" style="font-size: 13px;">pie_chart</i>
                        Consolidado de Fallas
                    </div>
                    <div style="display: flex; gap: 8px; justify-content: space-between;">
                        <div class="eq-mobile-stat-block eq-block-total" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(255,255,255,0.15); border:1px solid rgba(255,255,255,0.25);">
                            <span style="font-size:10px; font-weight:700; color:#ffffff; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">summarize</i> TOTAL</span>
                            <span style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $stats['total_reportes'] }}</span>
                        </div>
                        <div class="eq-mobile-stat-block eq-block-inop" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(239,68,68,0.15); border:1px solid rgba(239,68,68,0.3);">
                            <span style="font-size:10px; font-weight:700; color:#fca5a5; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">report_problem</i> ABIERTOS</span>
                            <span style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $stats['reportes_abiertos'] }}</span>
                        </div>
                        <div class="eq-mobile-stat-block eq-block-cerr" style="flex:1; display:flex; flex-direction:column; align-items:center; padding:8px 4px; border-radius:10px; background:rgba(34,197,94,0.15); border:1px solid rgba(34,197,94,0.3);">
                            <span style="font-size:10px; font-weight:700; color:#86efac; margin-bottom:2px;"><i class="material-icons" style="font-size:11px; vertical-align:middle;">check_circle</i> CERRADOS</span>
                            <span style="color:white; font-size:22px; font-weight:800; line-height:1;">{{ $stats['reportes_cerrados'] }}</span>
                        </div>
                    </div>
                    @include('admin.fallas.partials.mant_chart', ['stats' => $stats])
                </div>

                {{-- Cards de fallas --}}
                <div id="fallasTableBody" style="display:flex; flex-direction:column; gap:10px;">
                    @include('admin.fallas.partials.table_rows', compact('fallas'))
                </div>

                <div id="fallasPagination"
                    style="margin-top:12px; width: 100%; max-width: 100vw; overflow-x: auto; padding-bottom: 8px;">
                    {!! $fallas->links('vendor.pagination.custom-sliding') !!}</div>
            </div>
            </div>

            {{-- Columna derecha: Stats Sidebar --}}
            <div class="counter-sidebar" id="statsSidebarContainer"
                style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 15px;">

                <div
                    style="background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 12px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
                    <!-- Decorative Icon -->
                    <i class="material-icons"
                        style="position: absolute; right: -15px; bottom: -15px; font-size: 70px; opacity: 0.1; transform: rotate(-15deg);">report_problem</i>

                    <div style="position: relative; z-index: 2;">
                        <div
                            style="font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8; margin-bottom: 10px; display: flex; align-items: center; gap: 6px;">
                            <i class="material-icons" style="font-size: 13px;">pie_chart</i>
                            Consolidado de Fallas
                        </div>

                        {{-- Reportes de falla: Total / Abiertos / Cerrados (compacto, mismo idioma visual que /admin/equipos) --}}
                        <div style="display: flex; gap: 8px;">
                            <div title="Total de Reportes"
                                style="flex:1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.15); padding: 8px 4px; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.25);">
                                <i class="material-icons" style="font-size: 17px; color: #ffffff; margin-bottom: 2px;">summarize</i>
                                <strong id="statTotal" style="font-weight: 800; font-size: 22px; color: white; line-height: 1;">{{ $stats['total_reportes'] }}</strong>
                                <span style="font-size: 9px; letter-spacing: 0.3px; opacity: 0.9; font-weight: 700; text-transform: uppercase; margin-top: 2px; text-align: center; line-height: 1.15;">Total Reportes</span>
                            </div>
                            <div title="Reportes Abiertos"
                                style="flex:1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 8px 4px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25);">
                                <i class="material-icons" style="font-size: 17px; color: #ef4444; margin-bottom: 2px;">report_problem</i>
                                <strong id="statAbiertos" style="font-weight: 800; font-size: 22px; color: white; line-height: 1;">{{ $stats['reportes_abiertos'] }}</strong>
                                <span style="font-size: 9px; letter-spacing: 0.3px; opacity: 0.9; font-weight: 700; text-transform: uppercase; margin-top: 2px; text-align: center; line-height: 1.15;">Reportes Abiertos</span>
                            </div>
                            <div title="Reportes Cerrados"
                                style="flex:1; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); padding: 8px 4px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25);">
                                <i class="material-icons" style="font-size: 17px; color: #22c55e; margin-bottom: 2px;">check_circle</i>
                                <strong id="statCerrados" style="font-weight: 800; font-size: 22px; color: white; line-height: 1;">{{ $stats['reportes_cerrados'] }}</strong>
                                <span style="font-size: 9px; letter-spacing: 0.3px; opacity: 0.9; font-weight: 700; text-transform: uppercase; margin-top: 2px; text-align: center; line-height: 1.15;">Reportes Cerrados</span>
                            </div>
                        </div>

                        @include('admin.fallas.partials.mant_chart', ['stats' => $stats])
                    </div>
                </div>
            </div>
        </div>

        @include('admin.fallas.partials.create_modal')

        {{-- ─── Modal: Cerrar Reporte de Falla ─── --}}
        <div id="cierreReporteOverlay" class="fl-modal-overlay" onclick="if(event.target===this) window.closeCierreModal()">
            <div class="fl-modal" style="max-width:460px;">
                <div class="fl-modal-header" style="justify-content: center; position: relative;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <i class="material-icons">check_circle</i>
                        <h3 style="margin:0; font-size:15px; font-weight:700;">Cerrar Reporte de Falla</h3>
                    </div>
                    <button type="button" onclick="window.closeCierreModal()"
                        style="position: absolute; right: 15px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;"><i
                            class="material-icons">close</i></button>
                </div>
                <div class="fl-modal-body">
                    <div id="cierreInfoMsg"
                        style="padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; color:#475569;">
                    </div>
                    <div>
                        <label class="fl-field-label" for="cierreObservaciones">Observaciones de cierre <span
                                style="font-weight:400; color:#94a3b8;">(opcional)</span></label>
                        <textarea id="cierreObservaciones" class="fl-textarea"
                            placeholder="Describe las acciones correctivas realizadas..."></textarea>
                    </div>

                    {{-- Seccion taller: viene PRE-LLENADA con lo cargado al crear el
                         reporte; al cerrar se revisa/confirma y se completa lo que falte. --}}
                    <div id="cierreTallerFields" style="display:none; flex-direction:column; gap:10px;">
                        <div style="border-top:1px dashed #cbd5e1; padding-top:10px;">
                            <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Sección Taller (acta)</span>
                            <span style="display:block; font-size:11px; font-weight:400; color:#94a3b8; margin-top:2px; text-transform:none; letter-spacing:0;">Revisa lo cargado y completa lo que falte antes de cerrar.</span>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label class="fl-field-label" for="cierreMecanico">Mecánico Asignado</label>
                                <input type="text" id="cierreMecanico" class="fl-input" placeholder="Nombre del mecánico">
                            </div>
                            <div>
                                <label class="fl-field-label" for="cierreFechaRecepcion">Fecha de Recepción</label>
                                <input type="date" id="cierreFechaRecepcion" class="fl-input" style="cursor:pointer;" onclick="if(this.showPicker)this.showPicker()">
                            </div>
                        </div>
                        <div>
                            <label class="fl-field-label" for="cierreDiagnostico">Diagnóstico</label>
                            <textarea id="cierreDiagnostico" class="fl-textarea"></textarea>
                        </div>
                        <div>
                            <label class="fl-field-label" for="cierreAcciones">Acciones Realizadas</label>
                            <textarea id="cierreAcciones" class="fl-textarea"></textarea>
                        </div>
                    </div>

                    <div style="display:flex; gap:10px;">
                        <button type="button" onclick="window.closeCierreModal()" class="falla-btn"
                            style="height:44px; flex:1; justify-content:center;">
                            <i class="material-icons" style="font-size:16px;">close</i> Cancelar
                        </button>
                        <button type="button" id="btnConfirmarCierre" onclick="window.submitCierreReporte()"
                            class="falla-btn falla-btn-primary" style="height:44px; flex:1; justify-content:center;">
                            <i class="material-icons" style="font-size:16px;">check_circle</i> Confirmar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Rutas para fallas_index.js (cargado globalmente en el layout) --}}
        <script>
            window.FALLAS_CFG = {
                urlIndex: '{{ route("fallas.index") }}',
                urlBase: '{{ url("admin/fallas") }}'
            };
            // Modal de creación compartido: al crear un reporte se recarga el listado.
            window.FALLA_MODAL_CFG = {
                urlSearch: '{{ route("fallas.searchActivos") }}',
                urlStore: '{{ route("fallas.store") }}',
                urlBase: '{{ url("admin/fallas") }}',
                openPdf: true,
                onCreated: function () { if (window.cargarFallas) window.cargarFallas(); }
            };
        </script>
        <script src="{{ asset('js/maquinaria/falla_create_modal.js') }}"></script>

@endsection