@extends('layouts.estructura_base')
@section('title', 'Reportes de Fallas')

@section('content')
<style>
    .fallas-grid {
        display: grid;
        grid-template-columns: 1fr 280px;
        gap: 20px;
        width: 98%;
        max-width: 1600px;
        margin: 0 auto;
    }
    @media (max-width: 768px) {
        .fallas-grid { grid-template-columns: 1fr !important; }
        .fallas-sidebar { position: static !important; }
    }
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 14px 16px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    }
    .stat-card-row { display:flex; align-items:center; gap:10px; }
    .stat-card-icon {
        width:38px; height:38px; border-radius:10px;
        display:flex; align-items:center; justify-content:center;
    }
    .stat-card-num { font-size:22px; font-weight:800; color:#0f172a; line-height:1; }
    .stat-card-label { font-size:11px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; }

    .falla-row-card {
        background:white; border:1px solid #e2e8f0; border-radius:12px;
        padding:14px 16px; display:grid;
        grid-template-columns: 64px minmax(0,1fr) auto;
        gap:14px; align-items:center;
        transition: box-shadow 0.15s;
    }
    .falla-row-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); }
    .falla-foto {
        width:64px; height:64px; border-radius:8px; background:#f1f5f9;
        display:flex; align-items:center; justify-content:center; overflow:hidden;
    }
    .falla-foto img { width:100%; height:100%; object-fit:cover; }
    .falla-foto .material-icons { color:#cbd5e0; font-size:32px; }
    .falla-meta { display:flex; flex-direction:column; gap:4px; min-width:0; }
    .falla-codigo { font-size:11px; font-weight:800; color:#0067b1; letter-spacing:0.5px; }
    .falla-equipo { font-size:14px; font-weight:700; color:#1e293b; line-height:1.3; }
    .falla-info  { font-size:12px; color:#64748b; }
    .falla-chip {
        display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700;
        padding:2px 8px; border-radius:6px; text-transform:uppercase; letter-spacing:0.3px;
    }
    .falla-chip-abierto   { background:#fee2e2; color:#b91c1c; }
    .falla-chip-cerrado   { background:#dcfce7; color:#15803d; }
    .falla-chip-prioridad { background:#fef3c7; color:#a16207; }
    .falla-actions { display:flex; gap:6px; }
    .falla-btn {
        height:32px; padding:0 10px; border-radius:8px; border:1px solid #e2e8f0;
        background:white; cursor:pointer; display:inline-flex; align-items:center; gap:5px;
        font-size:12px; font-weight:700; color:#475569; text-decoration:none;
    }
    .falla-btn:hover { background:#f8fafc; }
    .falla-btn-primary { background:#0067b1; color:white; border-color:#0067b1; }
    .falla-btn-primary:hover { background:#0a5599; }

    /* Modal */
    .fl-modal-overlay { position:fixed; inset:0; background:rgba(15,23,42,0.55); backdrop-filter:blur(3px); z-index:10001; display:none; align-items:center; justify-content:center; padding:16px; }
    .fl-modal-overlay.active { display:flex; }
    .fl-modal {
        background:white; width:100%; max-width:520px; max-height:92vh; overflow-y:auto;
        border-radius:14px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);
    }
    .fl-modal-header {
        background:#1e293b; color:white; padding:14px 18px;
        display:flex; justify-content:space-between; align-items:center;
        border-radius:14px 14px 0 0;
    }
    .fl-modal-body { padding:18px; display:flex; flex-direction:column; gap:14px; }
    .fl-field-label { display:block; font-size:12px; font-weight:700; color:#475569; margin-bottom:5px; }
    .fl-input, .fl-select, .fl-textarea {
        width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px;
        font-size:13px; box-sizing:border-box; background:white; color:#1e293b; outline:none;
    }
    .fl-input:focus, .fl-select:focus, .fl-textarea:focus { border-color:#0067b1; box-shadow:0 0 0 3px rgba(0,103,177,0.1); }
    .fl-textarea { resize:vertical; min-height:70px; font-family:inherit; }

    .fl-toggle-row { display:flex; gap:8px; }
    .fl-toggle-btn {
        flex:1; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:8px;
        background:white; cursor:pointer; font-size:12.5px; font-weight:700; color:#64748b;
        text-align:center; transition: all 0.15s;
    }
    .fl-toggle-btn.active { border-color:#0067b1; background:#e1effa; color:#0067b1; }

    .fl-search-result {
        padding:10px 12px; cursor:pointer; border-bottom:1px solid #f1f5f9;
        display:flex; gap:0; align-items:flex-start; border-radius:8px;
        transition:background 0.12s;
    }
    .fl-search-result:hover { background:#f0f4f8; }
    .fl-search-result:last-child { border-bottom:none; }
    .fl-search-result img { width:50px; height:42px; border-radius:6px; object-fit:contain; background:#f8fafc; flex-shrink:0; }
</style>

<section class="page-title-card" style="text-align:left; margin:0 auto 10px auto; width:98%; max-width:1600px;">
    <h1 class="page-title" style="display:flex; align-items:center; gap:12px; font-size:24px;">
        <i class="material-icons" style="font-size:28px; color:#d97706;">report_problem</i>
        <span style="color:#000;">Reportes de Fallas</span>
    </h1>
</section>

<div class="fallas-grid">

    {{-- Columna principal: filtros + tabla --}}
    <div>
        <div class="admin-card" style="margin:0; padding:14px;">

@php
    $advActive = request()->filled('tipo_activo') || request()->filled('id_frente')
        || request()->filled('responsable') || request()->filled('marca')
        || request()->filled('modelo') || request()->filled('fecha_desde')
        || request()->filled('fecha_hasta');

    $estatusSel    = request('estatus');
    $estatusLabels = ['abierto' => 'Abiertos', 'cerrado' => 'Cerrados'];
    $estatusLabel  = $estatusLabels[$estatusSel] ?? 'Todos los reportes';

    $tipoActivoSel    = request('tipo_activo');
    $tipoActivoLabels = ['equipo' => 'ðŸš› VehÃ­culos', 'equipo_auxiliar' => 'ðŸ”§ Auxiliares'];
    $tipoActivoLabel  = $tipoActivoLabels[$tipoActivoSel] ?? 'Todos los activos';

    $frenteSel    = request('id_frente');
    $frenteObj    = $frenteSel ? $frentes->firstWhere('ID_FRENTE', (int) $frenteSel) : null;
    $frenteLabel  = $frenteObj ? $frenteObj->NOMBRE_FRENTE : 'Todos los frentes';

    $respSel   = request('responsable');
    $respObj   = $respSel ? $responsables->firstWhere('ID_USUARIO', (int) $respSel) : null;
    $respLabel = $respObj ? $respObj->NOMBRE_COMPLETO : 'Todos los responsables';
@endphp

{{-- Toolbar (estilo /admin/equipos: custom-dropdown) --}}
<div class="filter-toolbar-container" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:12px;">

    {{-- Buscar por serial / placa --}}
    <div class="filter-item aligned-filter" style="flex:1.5; min-width:200px;">
        <div class="search-wrapper" style="width:100%; border-color:{{ request('search') ? '#0067b1' : '#cbd5e0' }}; background:{{ request('search') ? '#e1effa' : '#fff' }};">
            <i class="material-icons search-icon">search</i>
            <input type="text" id="fallasSearch" name="search" value="{{ request('search') }}"
                   placeholder="Buscar Seriales / Placa..." class="search-input-field" autocomplete="off"
                   oninput="window._flDebounce && clearTimeout(window._flDebounce); window._flDebounce = setTimeout(window.cargarFallas, 350);">
            <i id="fallasSearchClear" class="material-icons clear-icon"
               style="display:{{ request('search') ? 'block' : 'none' }};"
               onclick="event.preventDefault(); event.stopPropagation(); document.getElementById('fallasSearch').value=''; this.style.display='none'; window.cargarFallas();">close</i>
        </div>
    </div>

    {{-- Frente (en barra principal, junto al buscador) --}}
    <div class="filter-item aligned-filter" style="flex:2; min-width:220px; max-width:340px;">
        <div class="custom-dropdown" id="fallasFrenteDD" data-filter-type="id_frente" data-default-label="Todos los frentes">
            <input type="hidden" id="fallasFrente" data-filter-value value="{{ $frenteSel }}">
            <div class="dropdown-trigger {{ $frenteSel ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:{{ $frenteSel ? '#e1effa' : '#fbfcfd' }}; overflow:hidden; border:1px solid {{ $frenteSel ? '#0067b1' : '#cbd5e0' }}; border-radius:12px; height:45px;">
                <div style="padding:0 10px; color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                <input type="text" name="filter_search_dropdown" data-filter-search
                       placeholder="{{ $frenteLabel }}" aria-label="Filtrar Frente"
                       style="width:100%; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none;"
                       oninput="window.filterDropdownOptions(this)" autocomplete="off">
                <i class="material-icons" data-clear-btn
                   style="padding:0 5px; color:#64748b; font-size:18px; display:{{ $frenteSel ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); window.clearDropdownFilter('fallasFrenteDD'); window.cargarFallas();">close</i>
            </div>
            <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                    <div class="dropdown-item {{ !$frenteSel ? 'selected' : '' }}" data-value=""
                         onclick="window.selectOption('fallasFrenteDD','','Todos los frentes'); window.cargarFallas();">Todos los frentes</div>
                    @foreach($frentes as $fr)
                        <div class="dropdown-item {{ $frenteSel == $fr->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $fr->ID_FRENTE }}"
                             onclick="window.selectOption('fallasFrenteDD','{{ $fr->ID_FRENTE }}','{{ addslashes(trim($fr->NOMBRE_FRENTE)) }}'); window.cargarFallas();">{{ $fr->NOMBRE_FRENTE }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Estatus (custom-dropdown) --}}
    <div class="filter-item aligned-filter" style="flex:2; min-width:220px; max-width:340px;">
        <div class="custom-dropdown" id="fallasEstatusDD" data-filter-type="estatus" data-default-label="Todos los reportes">
            <input type="hidden" id="fallasEstatus" data-filter-value value="{{ $estatusSel }}">
            <div class="dropdown-trigger {{ $estatusSel ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:{{ $estatusSel ? '#e1effa' : '#fbfcfd' }}; overflow:hidden; border:1px solid {{ $estatusSel ? '#0067b1' : '#cbd5e0' }}; border-radius:12px; height:45px;">
                <div style="padding:0 10px; color:#64748b;"><i class="material-icons" style="font-size:18px;">flag</i></div>
                <input type="text" name="filter_search_dropdown" data-filter-search
                       placeholder="{{ $estatusLabel }}" aria-label="Filtrar Estatus"
                       style="width:100%; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none;"
                       oninput="window.filterDropdownOptions(this)" autocomplete="off">
                <i class="material-icons" data-clear-btn
                   style="padding:0 5px; color:#64748b; font-size:18px; display:{{ $estatusSel ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); window.clearDropdownFilter('fallasEstatusDD'); window.cargarFallas();">close</i>
            </div>
            <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                <div class="dropdown-item-list">
                    <div class="dropdown-item {{ !$estatusSel ? 'selected' : '' }}" data-value=""
                         onclick="window.selectOption('fallasEstatusDD','','Todos los reportes'); window.cargarFallas();">Todos los reportes</div>
                    <div class="dropdown-item {{ $estatusSel=='abierto' ? 'selected' : '' }}" data-value="abierto"
                         onclick="window.selectOption('fallasEstatusDD','abierto','Abiertos'); window.cargarFallas();">Abiertos</div>
                    <div class="dropdown-item {{ $estatusSel=='cerrado' ? 'selected' : '' }}" data-value="cerrado"
                         onclick="window.selectOption('fallasEstatusDD','cerrado','Cerrados'); window.cargarFallas();">Cerrados</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Boton Filtros Avanzados --}}
    <div style="position:relative; flex-shrink:0;">
        <button type="button" id="fallasAdvBtn" class="btn-primary-maquinaria"
                onclick="const p=document.getElementById('fallasAdvPanel'); p.style.display=(p.style.display==='none'||!p.style.display)?'block':'none'; event.stopPropagation();"
                title="Filtros Avanzados"
                style="height:45px; width:45px; min-width:45px; padding:0; display:flex; align-items:center; justify-content:center;
                       background:{{ $advActive ? '#fee2e2' : 'white' }}; border:1px solid {{ $advActive ? '#ef4444' : '#cbd5e0' }};
                       color:{{ $advActive ? '#ef4444' : '#64748b' }}; box-shadow:none;">
            <i class="material-icons">filter_list</i>
        </button>

        <div id="fallasAdvPanel" style="display:none; position:absolute; top:100%; right:0; width:300px; max-width:calc(100vw - 20px);
             background:#e2e8f0; border:1px solid #cbd5e1; border-radius:12px;
             box-shadow:0 10px 25px -5px rgba(0,0,0,0.15); margin-top:10px; padding:15px; z-index:500;">

            <h4 style="margin:0 0 14px 0; font-size:14px; font-weight:700; color:#334155; display:flex; justify-content:space-between; align-items:center;">
                Filtros Avanzados
                <span style="font-size:11px; color:#64748b; font-weight:400; text-decoration:underline; cursor:pointer;"
                      onclick="window.flClearAdv()">Limpiar Todo</span>
            </h4>

            <div style="display:flex; flex-direction:column; gap:10px;">

                {{-- Tipo de activo (custom-dropdown) --}}
                <div>
                    <span style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Tipo de Activo</span>
                    <div class="custom-dropdown" id="fallasTipoActivoDD" data-filter-type="tipo_activo" data-default-label="Todos los activos" style="font-size:12px;">
                        <input type="hidden" id="fallasTipoActivo" data-filter-value value="{{ $tipoActivoSel }}">
                        <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:{{ $tipoActivoSel ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                            <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons" style="font-size:16px;">search</i></div>
                            <input type="text" name="filter_search_dropdown" data-filter-search
                                   placeholder="{{ $tipoActivoLabel }}" style="width:100%; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                   oninput="window.filterDropdownOptions(this)" autocomplete="off">
                            <i class="material-icons" data-clear-btn style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ $tipoActivoSel ? 'block' : 'none' }};"
                               onclick="event.stopPropagation(); window.clearDropdownFilter('fallasTipoActivoDD'); window.cargarFallas();">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                            <div class="dropdown-item-list">
                                <div class="dropdown-item {{ !$tipoActivoSel ? 'selected' : '' }}" data-value=""
                                     onclick="window.selectOption('fallasTipoActivoDD','','Todos los activos'); window.cargarFallas();">Todos los activos</div>
                                <div class="dropdown-item {{ $tipoActivoSel=='equipo' ? 'selected' : '' }}" data-value="equipo"
                                     onclick="window.selectOption('fallasTipoActivoDD','equipo','ðŸš› VehÃ­culos'); window.cargarFallas();">ðŸš› VehÃ­culos</div>
                                <div class="dropdown-item {{ $tipoActivoSel=='equipo_auxiliar' ? 'selected' : '' }}" data-value="equipo_auxiliar"
                                     onclick="window.selectOption('fallasTipoActivoDD','equipo_auxiliar','ðŸ”§ Auxiliares'); window.cargarFallas();">ðŸ”§ Auxiliares</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Frente movido a barra principal --}}

                {{-- Responsable (custom-dropdown buscable) --}}
                @if($responsables->count() > 0)
                <div>
                    <span style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Responsable</span>
                    <div class="custom-dropdown" id="fallasResponsableDD" data-filter-type="responsable" data-default-label="Todos los responsables" style="font-size:12px;">
                        <input type="hidden" id="fallasResponsable" data-filter-value value="{{ $respSel }}">
                        <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:{{ $respSel ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                            <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons" style="font-size:16px;">search</i></div>
                            <input type="text" name="filter_search_dropdown" data-filter-search
                                   placeholder="{{ $respLabel }}" style="width:100%; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                   oninput="window.filterDropdownOptions(this)" autocomplete="off">
                            <i class="material-icons" data-clear-btn style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ $respSel ? 'block' : 'none' }};"
                               onclick="event.stopPropagation(); window.clearDropdownFilter('fallasResponsableDD'); window.cargarFallas();">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                            <div class="dropdown-item-list" style="max-height:160px; overflow-y:auto;">
                                <div class="dropdown-item {{ !$respSel ? 'selected' : '' }}" data-value=""
                                     onclick="window.selectOption('fallasResponsableDD','','Todos los responsables'); window.cargarFallas();">Todos los responsables</div>
                                @foreach($responsables as $r)
                                    <div class="dropdown-item {{ $respSel == $r->ID_USUARIO ? 'selected' : '' }}" data-value="{{ $r->ID_USUARIO }}"
                                         onclick="window.selectOption('fallasResponsableDD','{{ $r->ID_USUARIO }}','{{ addslashes(trim($r->NOMBRE_COMPLETO)) }}'); window.cargarFallas();">{{ $r->NOMBRE_COMPLETO }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- Marca + Modelo: dropdowns con autocomplete sobre datos reales --}}
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                    <div>
                        <span style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Marca</span>
                        <div class="custom-dropdown" id="fallasMarcaDD" data-filter-type="marca" data-default-label="Marca..." style="font-size:12px;">
                            <input type="hidden" id="fallasMarca" data-filter-value value="{{ request('marca') }}">
                            <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:{{ request('marca') ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                                <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons" style="font-size:16px;">search</i></div>
                                <input type="text" name="filter_search_dropdown" data-filter-search
                                       placeholder="{{ request('marca') ?: 'Marca...' }}" style="width:100%; min-width:0; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                       oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                <i class="material-icons" data-clear-btn style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ request('marca') ? 'block' : 'none' }};"
                                   onclick="event.stopPropagation(); window.clearDropdownFilter('fallasMarcaDD'); window.cargarFallas();">close</i>
                            </div>
                            <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:150px; overflow-y:auto;">
                                    @foreach($availableMarcas as $m)
                                        @if(trim($m) !== '')
                                            <div class="dropdown-item {{ request('marca') == $m ? 'selected' : '' }}" data-value="{{ $m }}"
                                                 onclick="window.selectOption('fallasMarcaDD','{{ addslashes(trim($m)) }}','{{ addslashes(trim($m)) }}'); window.cargarFallas();">{{ $m }}</div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <span style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Modelo</span>
                        <div class="custom-dropdown" id="fallasModeloDD" data-filter-type="modelo" data-default-label="Modelo..." style="font-size:12px;">
                            <input type="hidden" id="fallasModelo" data-filter-value value="{{ request('modelo') }}">
                            <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:{{ request('modelo') ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                                <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons" style="font-size:16px;">search</i></div>
                                <input type="text" name="filter_search_dropdown" data-filter-search
                                       placeholder="{{ request('modelo') ?: 'Modelo...' }}" style="width:100%; min-width:0; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                       oninput="window.filterDropdownOptions(this)" autocomplete="off">
                                <i class="material-icons" data-clear-btn style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ request('modelo') ? 'block' : 'none' }};"
                                   onclick="event.stopPropagation(); window.clearDropdownFilter('fallasModeloDD'); window.cargarFallas();">close</i>
                            </div>
                            <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:150px; overflow-y:auto;">
                                    @foreach($availableModelos as $mo)
                                        @if(trim($mo) !== '')
                                            <div class="dropdown-item {{ request('modelo') == $mo ? 'selected' : '' }}" data-value="{{ $mo }}"
                                                 onclick="window.selectOption('fallasModeloDD','{{ addslashes(trim($mo)) }}','{{ addslashes(trim($mo)) }}'); window.cargarFallas();">{{ $mo }}</div>
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
                        <span style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Desde</span>
                        <input type="date" id="fallasFechaDesde" class="fl-input" style="height:32px; font-size:12px; width:100%;" value="{{ request('fecha_desde') }}" onchange="window.cargarFallas()">
                    </div>
                    <div>
                        <span style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Hasta</span>
                        <input type="date" id="fallasFechaHasta" class="fl-input" style="height:32px; font-size:12px; width:100%;" value="{{ request('fecha_hasta') }}" onchange="window.cargarFallas()">
                    </div>
                </div>

            </div>
        </div>
    </div>

        <button type="button" onclick="window.openNuevoReporteModal()" class="falla-btn falla-btn-primary" style="height:45px;">
            <i class="material-icons" style="font-size:18px;">add_circle</i> Nuevo Reporte
        </button>
    </div>
</div>

            {{-- Cards de fallas --}}
            <div id="fallasTableBody" style="display:flex; flex-direction:column; gap:10px;">
                @include('admin.fallas.partials.table_rows', compact('fallas'))
            </div>

            <div id="fallasPagination" style="margin-top:12px;">{!! $fallas->links('vendor.pagination.custom-sliding') !!}</div>
        </div>
    </div>

    {{-- Sidebar de stats --}}
    <div class="fallas-sidebar" style="position:sticky; top:20px; display:flex; flex-direction:column; gap:14px;">
        <div class="stat-card">
            <div class="stat-card-row">
                <div class="stat-card-icon" style="background:#fee2e2;">
                    <i class="material-icons" style="color:#dc2626;">cancel</i>
                </div>
                <div>
                    <div class="stat-card-num" id="statInoperativo">{{ $stats['inoperativo'] }}</div>
                    <div class="stat-card-label">Inoperativo</div>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-row">
                <div class="stat-card-icon" style="background:#fef3c7;">
                    <i class="material-icons" style="color:#d97706;">engineering</i>
                </div>
                <div>
                    <div class="stat-card-num" id="statMantenimiento">{{ $stats['mantenimiento'] }}</div>
                    <div class="stat-card-label">Mantenimiento</div>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-card-row">
                <div class="stat-card-icon" style="background:#fff7ed;">
                    <i class="material-icons" style="color:#c2410c;">report_problem</i>
                </div>
                <div>
                    <div class="stat-card-num" id="statAbiertos">{{ $stats['reportes_abiertos'] }}</div>
                    <div class="stat-card-label">Reportes Abiertos</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- â”€â”€â”€ Modal: Nuevo Reporte de Falla â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ --}}
<div id="nuevoReporteOverlay" class="fl-modal-overlay" onclick="if(event.target===this) window.closeNuevoReporteModal()">
    <div class="fl-modal">
        <div class="fl-modal-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons">report_problem</i>
                <h3 style="margin:0; font-size:15px; font-weight:700;">Nuevo Reporte de Falla</h3>
            </div>
            <button type="button" onclick="window.closeNuevoReporteModal()" style="background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;"><i class="material-icons">close</i></button>
        </div>
        <form id="nuevoReporteForm" class="fl-modal-body" onsubmit="event.preventDefault(); window.submitNuevoReporte();">

            {{-- Tipo de reporte --}}
            <div>
                <label class="fl-field-label">Tipo de Reporte</label>
                <div class="fl-toggle-row">
                    <div class="fl-toggle-btn active" data-tipo="corto" onclick="window.flSetTipo('corto')">ðŸ“ Corto (sin acta)</div>
                    <div class="fl-toggle-btn" data-tipo="extenso" onclick="window.flSetTipo('extenso')">ðŸ“„ Extenso (con PDF)</div>
                </div>
                <input type="hidden" id="fl_tipo_reporte" name="tipo_reporte" value="corto">
            </div>

            {{-- Buscador de activo --}}
            <div>
                <label class="fl-field-label">Buscar Equipo (placa / serial / cÃ³d. motor)</label>
                <input type="text" id="fl_search_activo" class="fl-input" placeholder="Ej: ABC123 / 1HGCM82..."
                       autocomplete="off" oninput="window.flSearchActivos(this.value)">
                <div id="fl_search_results" style="border:1px solid #e2e8f0; border-radius:8px; max-height:220px; overflow-y:auto; margin-top:6px; display:none; background:white;"></div>
                <div id="fl_activo_seleccionado" style="display:none; margin-top:8px; padding:10px; background:#e1effa; border:1px solid #0067b1; border-radius:8px;"></div>
                <input type="hidden" id="fl_activo_tipo" name="activo_tipo" value="">
                <input type="hidden" id="fl_activo_id" name="activo_id" value="">
            </div>

            {{-- Estado a aplicar --}}
            <div>
                <label class="fl-field-label">Estado a aplicar al equipo</label>
                <select id="fl_estado_al_crear" name="estado_al_crear" class="fl-select">
                    <option value="INOPERATIVO">Inoperativo (falla crÃ­tica)</option>
                    <option value="EN MANTENIMIENTO">En Mantenimiento</option>
                </select>
            </div>

            {{-- Campos extensos (visibles solo si tipo=extenso) --}}
            <div id="fl_fields_extenso" style="display:none; flex-direction:column; gap:10px;">
                <div>
                    <label class="fl-field-label">HorÃ³metro / Kilometraje</label>
                    <input type="text" id="fl_horometro" name="horometro" class="fl-input" placeholder="Ej: 12500 km / 3200 hrs">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label class="fl-field-label">Sistema Afectado</label>
                        <select id="fl_sistema" name="sistema" class="fl-select">
                            <option value="">Seleccionar...</option>
                            @foreach(\App\Models\Falla::sistemasAfectados() as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="fl-field-label">Prioridad</label>
                        <select id="fl_prioridad" name="prioridad" class="fl-select">
                            <option value="">Seleccionar...</option>
                            @foreach(\App\Models\Falla::prioridades() as $k => $v)
                                <option value="{{ $k }}">{{ $v }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="fl-field-label">Tipo de IntervenciÃ³n</label>
                    <select id="fl_tipo_intervencion" name="tipo_intervencion" class="fl-select">
                        <option value="">Seleccionar...</option>
                        @foreach(\App\Models\Falla::tiposIntervencion() as $k => $v)
                            <option value="{{ $k }}">{{ $v }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="fl-field-label">Repuestos Estimados</label>
                    <textarea id="fl_repuestos" name="repuestos" class="fl-textarea"></textarea>
                </div>
                <div>
                    <label class="fl-field-label">Observaciones del MecÃ¡nico</label>
                    <textarea id="fl_observaciones" name="observaciones" class="fl-textarea"></textarea>
                </div>
            </div>

            <div>
                <label class="fl-field-label">DescripciÃ³n de la AverÃ­a</label>
                <textarea id="fl_descripcion" name="descripcion" class="fl-textarea" placeholder="Describe brevemente la falla detectada..."></textarea>
            </div>

            <button type="submit" id="fl_submit_btn" class="falla-btn falla-btn-primary" style="height:44px; width:100%; justify-content:center;">
                <i class="material-icons">send</i> Crear Reporte
            </button>
        </form>
    </div>
</div>


{{-- â”€â”€â”€ Modal: Cerrar Reporte de Falla â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ --}}
<div id="cierreReporteOverlay" class="fl-modal-overlay" onclick="if(event.target===this) window.closeCierreModal()">
    <div class="fl-modal" style="max-width:520px;">
        <div class="fl-modal-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons">check_circle</i>
                <h3 style="margin:0; font-size:15px; font-weight:700;">Cerrar Reporte de Falla</h3>
            </div>
            <button type="button" onclick="window.closeCierreModal()" style="background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;"><i class="material-icons">close</i></button>
        </div>
        <div class="fl-modal-body">
            <div id="cierreInfoMsg" style="padding:10px 12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; color:#475569;"></div>
            <div>
                <label class="fl-field-label">Observaciones de cierre <span style="font-weight:400; color:#94a3b8;">(opcional)</span></label>
                <textarea id="cierreObservaciones" class="fl-textarea" placeholder="Describe las acciones correctivas realizadas..."></textarea>
            </div>
            <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding:10px 12px; background:#e1effa; border-radius:8px; border:1px solid #93c5fd;">
                <input type="checkbox" id="cierreRestaurar" checked style="width:16px; height:16px; cursor:pointer; accent-color:#0067b1;">
                <span style="font-size:13px; font-weight:600; color:#0067b1;">Restaurar equipo a estado <strong>OPERATIVO</strong></span>
            </label>
            <div style="display:flex; gap:10px;">
                <button type="button" onclick="window.closeCierreModal()" class="falla-btn" style="height:44px; flex:1; justify-content:center;">
                    <i class="material-icons" style="font-size:16px;">close</i> Cancelar
                </button>
                <button type="button" id="btnConfirmarCierre" onclick="window.submitCierreReporte()" class="falla-btn falla-btn-primary" style="height:44px; flex:2; justify-content:center;">
                    <i class="material-icons" style="font-size:16px;">check_circle</i> Confirmar Cierre
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Rutas para fallas_index.js (cargado globalmente en el layout) --}}
<script>
window.FALLAS_CFG = {
    urlIndex:       '{{ route("fallas.index") }}',
    urlSearch:      '{{ route("fallas.searchActivos") }}',
    urlStore:       '{{ route("fallas.store") }}',
    urlChangeEstado:'{{ route("fallas.changeEstado") }}',
    urlBase:        '{{ url("admin/fallas") }}'
};
</script>

@endsection
