@extends('layouts.estructura_base')
@section('title', 'Reportes de Fallas')

@section('content')
    <link rel="stylesheet" href="{{ asset('css/vistas/admin_fallas_index.css') }}?v={{ @filemtime(public_path('css/vistas/admin_fallas_index.css')) }}">

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
                        onclick="{{ auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('super.admin')) ? 'window.openNuevoReporteModal()' : 'window.flAccesoDenegado()' }}"
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
                    <div>
                        <div style="display:flex; align-items:center; justify-content:center; gap:8px;">
                            <i class="material-icons">check_circle</i>
                            <h3 style="margin:0; font-size:15px; font-weight:700;">Cerrar Reporte de Falla</h3>
                        </div>
                        {{-- Qué equipo: lo pinta flEncabezadoCierre (el mismo que el modal compartido). --}}
                        <div id="cierreEquipo" class="fl-modal-subtitulo"></div>
                    </div>
                    <button type="button" onclick="window.closeCierreModal()"
                        style="position: absolute; right: 15px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;"><i
                            class="material-icons">close</i></button>
                </div>
                <div class="fl-modal-body">
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

        {{-- Rutas para fallas_index.js (se carga al final de esta vista, despues de esto) --}}
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
                onCreated: function () { if (window.cargarFallas) window.cargarFallas(); }
            };
        </script>
        {{-- falla_create_modal.js se carga GLOBAL en el layout (SPA-safe). --}}

{{-- JS de esta pantalla (solo lo usa ella; antes iba en el layout y lo bajaban todas). La SPA lo ejecuta una vez por pestaña. --}}
<script src="{{ asset('js/maquinaria/fallas_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/fallas_index.js')) }}"></script>
@endsection