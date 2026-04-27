@extends('layouts.estructura_base')
@section('title', 'Reportes de Fallas')

@section('content')
<style>
    .fallas-grid {
        display: grid;
        grid-template-columns: 1fr 350px;
        gap: 20px;
        width: 98%;
        max-width: 1600px;
        margin: 0 auto;
    }
    @media (max-width: 768px) {
        .fallas-grid { grid-template-columns: 1fr !important; }
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
        grid-template-columns: 170px minmax(0,1fr) auto;
        gap:14px; align-items:center;
        transition: box-shadow 0.15s;
    }
    .falla-row-card:hover { box-shadow:0 4px 12px rgba(0,0,0,0.08); }
    .falla-foto {
        width:170px; height:105px; border-radius:8px; background:#f1f5f9;
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
        width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:10px;
        font-size:13.5px; box-sizing:border-box; background:white; color:#1e293b; outline:none; transition: all 0.2s;
    }
    .fl-select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%2394a3b8'%3E%3Cpath d='M16.59 8.59L12 13.17 7.41 8.59 6 10l6 6 6-6z'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 10px center;
        background-size: 20px;
        padding-right: 36px;
        cursor: pointer;
    }
    .fl-input:focus, .fl-select:focus, .fl-textarea:focus { border-color:#0067b1; box-shadow:0 0 0 3px rgba(0,103,177,0.1); }
    .fl-textarea { resize:vertical; min-height:80px; font-family:inherit; }

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
        || request()->filled('fecha_hasta') || request()->filled('estatus');

    $estatusSel    = request('estatus');
    $estatusLabels = ['abierto' => 'Reportes Abiertos', 'cerrado' => 'Reportes Cerrados'];
    $estatusLabel  = $estatusLabels[$estatusSel] ?? 'Todos los reportes';

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

    $frenteSel    = request('id_frente');
    $frenteObj    = $frenteSel ? $frentes->firstWhere('ID_FRENTE', (int) $frenteSel) : null;
    $frenteLabel  = $frenteObj ? $frenteObj->NOMBRE_FRENTE : 'Todos los frentes';

    $respSel   = request('responsable');
    $respObj   = $respSel ? $responsables->firstWhere('ID_USUARIO', (int) $respSel) : null;
    $respLabel = $respObj ? $respObj->NOMBRE_COMPLETO : 'Todos los responsables';
@endphp

{{-- Toolbar (estilo /admin/equipos: custom-dropdown) --}}
<div class="filter-toolbar-container" style="display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin-bottom:12px;">

    {{-- Frente (en barra principal) --}}
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

    {{-- Tipo de Activo (en barra principal) --}}
    <div class="filter-item aligned-filter" style="flex:2; min-width:220px; max-width:340px;">
        <div class="custom-dropdown" id="fallasTipoActivoDD" data-filter-type="tipo_activo" data-default-label="Todos los activos">
            <input type="hidden" id="fallasTipoActivo" data-filter-value value="{{ $tipoActivoSel }}">
            <div class="dropdown-trigger {{ $tipoActivoSel ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:{{ $tipoActivoSel ? '#e1effa' : '#fbfcfd' }}; overflow:hidden; border:1px solid {{ $tipoActivoSel ? '#0067b1' : '#cbd5e0' }}; border-radius:12px; height:45px;">
                <div style="padding:0 10px; color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                <input type="text" name="filter_search_dropdown" data-filter-search
                       placeholder="{{ $tipoActivoLabel }}" aria-label="Filtrar Tipo de Activo"
                       style="width:100%; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none;"
                       oninput="window.filterDropdownOptions(this)" autocomplete="off">
                <i class="material-icons" data-clear-btn
                   style="padding:0 5px; color:#64748b; font-size:18px; display:{{ $tipoActivoSel ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); window.clearDropdownFilter('fallasTipoActivoDD'); window.cargarFallas();">close</i>
            </div>
            <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                    <div class="dropdown-item {{ !$tipoActivoSel ? 'selected' : '' }}" data-value=""
                         onclick="window.selectOption('fallasTipoActivoDD','','Todos los activos'); window.cargarFallas();">Todos los activos</div>
                    {{-- Grupo Vehículos --}}
                    @if($tiposEquipo->count())
                        <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">VEHÍCULOS</div>
                        @foreach($tiposEquipo as $te)
                            <div class="dropdown-item {{ $tipoActivoSel=='tipo_eq:'.$te->id ? 'selected' : '' }}" data-value="tipo_eq:{{ $te->id }}"
                                 onclick="window.selectOption('fallasTipoActivoDD','tipo_eq:{{ $te->id }}','{{ addslashes($te->nombre) }}'); window.cargarFallas();">{{ $te->nombre }}</div>
                        @endforeach
                    @endif
                    {{-- Grupo Auxiliares --}}
                    @if($tiposAux->count())
                        <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">AUXILIARES</div>
                        @foreach($tiposAux as $ta)
                            <div class="dropdown-item {{ $tipoActivoSel=='tipo_aux:'.$ta ? 'selected' : '' }}" data-value="tipo_aux:{{ $ta }}"
                                 onclick="window.selectOption('fallasTipoActivoDD','tipo_aux:{{ $ta }}','{{ addslashes($ta) }}'); window.cargarFallas();">{{ $ta }}</div>
                        @endforeach
                    @endif
                </div>
            </div>
        </div>
    </div>

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

                {{-- Estado del Reporte --}}
                <div>
                    <span style="display:block; font-size:12px; font-weight:600; color:#64748b; margin-bottom:5px;">Estado del Reporte</span>
                    <div class="custom-dropdown" id="fallasEstatusDD" data-filter-type="estatus" data-default-label="Todos los reportes" style="font-size:12px;">
                        <input type="hidden" id="fallasEstatus" data-filter-value value="{{ $estatusSel }}">
                        <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:{{ $estatusSel ? '#e1effa' : 'white' }}; border:1px solid #e2e8f0; border-radius:6px; height:32px;">
                            <div style="padding:0 6px; color:#94a3b8;"><i class="material-icons" style="font-size:16px;">flag</i></div>
                            <input type="text" name="filter_search_dropdown" data-filter-search
                                   placeholder="{{ $estatusLabel }}" style="width:100%; border:none; background:transparent; padding:6px 2px; font-size:12px; outline:none;"
                                   oninput="window.filterDropdownOptions(this)" autocomplete="off">
                            <i class="material-icons" data-clear-btn style="padding:0 4px; color:#94a3b8; font-size:16px; display:{{ $estatusSel ? 'block' : 'none' }};"
                               onclick="event.stopPropagation(); window.clearDropdownFilter('fallasEstatusDD'); window.cargarFallas();">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                            <div class="dropdown-item-list">
                                <div class="dropdown-item {{ !$estatusSel ? 'selected' : '' }}" data-value=""
                                     onclick="window.selectOption('fallasEstatusDD','','Todos los reportes'); window.cargarFallas();">Todos los reportes</div>
                                <div class="dropdown-item {{ $estatusSel=='abierto' ? 'selected' : '' }}" data-value="abierto"
                                     onclick="window.selectOption('fallasEstatusDD','abierto','Reportes Abiertos'); window.cargarFallas();">Reportes Abiertos</div>
                                <div class="dropdown-item {{ $estatusSel=='cerrado' ? 'selected' : '' }}" data-value="cerrado"
                                     onclick="window.selectOption('fallasEstatusDD','cerrado','Reportes Cerrados'); window.cargarFallas();">Reportes Cerrados</div>
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

            {{-- Cards de fallas --}}
            <div id="fallasTableBody" style="display:flex; flex-direction:column; gap:10px;">
                @include('admin.fallas.partials.table_rows', compact('fallas'))
            </div>

            <div id="fallasPagination" style="margin-top:12px;">{!! $fallas->links('vendor.pagination.custom-sliding') !!}</div>
        </div>
    </div>

    {{-- Columna derecha: Stats Sidebar --}}
    <div class="counter-sidebar" id="statsSidebarContainer" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 15px;">
        
        <div style="background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
            <!-- Decorative Icon -->
            <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">report_problem</i>
            
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.8; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <i class="material-icons" style="font-size: 14px;">pie_chart</i>
                    Consolidado de Fallas
                </div>
                
                <div style="display: flex; align-items: center; gap: 8px;">
                    <!-- Main Total: Reportes Abiertos -->
                    <div title="Reportes Abiertos" style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.15); padding: 8px 6px; border-radius: 10px; min-width: 65px; border: 1px solid rgba(255,255,255,0.2);">
                        <span id="statAbiertos" style="font-size: 36px; font-weight: 800; line-height: 1;">
                            {{ $stats['reportes_abiertos'] }}
                        </span>
                        <span style="font-size: 11px; opacity: 0.8; font-weight: 700; margin-top: 2px; letter-spacing: 0.5px; text-align: center; max-width: 75px; line-height: 1.1;">REPORTES ABIERTOS</span>
                    </div>

                    <!-- Detailed Stats Row: Inoperativo / Mantenimiento -->
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; flex: 1;">
                        <div title="Inoperativos" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); transition: background 0.2s;">
                            <i class="material-icons" style="font-size: 18px; color: #ef4444; margin-bottom: 2px;">cancel</i>
                            <strong id="statInoperativo" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['inoperativo'] }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Inoperativo</span>
                        </div>
                        <div title="En Mantenimiento" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(245, 158, 11, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(245, 158, 11, 0.25); transition: background 0.2s;">
                            <i class="material-icons" style="font-size: 18px; color: #f59e0b; margin-bottom: 2px;">engineering</i>
                            <strong id="statMantenimiento" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['mantenimiento'] }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Mantenimiento</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ─── Modal: Nuevo Reporte de Falla ─── --}}
<div id="nuevoReporteOverlay" class="fl-modal-overlay" onclick="if(event.target===this) window.closeNuevoReporteModal()">
    <div class="fl-modal">
        <div class="fl-modal-header" style="justify-content: center; position: relative;">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons" style="font-size: 20px;">report_problem</i>
                <h3 style="margin:0; font-size:16px; font-weight:800;">Nuevo Reporte de Falla</h3>
            </div>
            <button type="button" onclick="window.closeNuevoReporteModal()" style="background:transparent; border:none; color:white; cursor:pointer; opacity:0.7; position: absolute; right: 18px;"><i class="material-icons">close</i></button>
        </div>
        <form id="nuevoReporteForm" class="fl-modal-body" onsubmit="event.preventDefault(); window.submitNuevoReporte();">

            {{-- Tipo de reporte --}}
            <div>
                <span class="fl-field-label">Tipo de Reporte</span>
                <div class="fl-toggle-row">
                    <div class="fl-toggle-btn active" data-tipo="corto" onclick="window.flSetTipo('corto')">⚡ Reporte Rápido</div>
                    <div class="fl-toggle-btn" data-tipo="extenso" onclick="window.flSetTipo('extenso')">📄 Acta Detallada (PDF)</div>
                </div>
                <input type="hidden" id="fl_tipo_reporte" name="tipo_reporte" value="corto">
            </div>

            {{-- Buscador de activo --}}
            <div>
                <label class="fl-field-label" for="fl_search_activo">Buscar Equipo (placa / serial / cod. motor)</label>
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
                <div class="custom-dropdown" id="flEstadoCrearDD" style="width:100%;">
                    <input type="hidden" id="fl_estado_al_crear" name="estado_al_crear" value="INOPERATIVO">
                    <div class="dropdown-trigger" style="padding:0 12px; display:flex; align-items:center; background:white; border:1px solid #cbd5e1; border-radius:10px; height:45px; justify-content:space-between;" onclick="event.stopPropagation(); const c=this.nextElementSibling; document.querySelectorAll('.dropdown-content').forEach(el=>el!==c?el.style.display='none':null); c.style.display=(c.style.display==='none'||!c.style.display)?'block':'none';">
                        <span id="fl_estado_al_crear_label" style="font-size:13.5px; color:#1e293b;">Inoperativo</span>
                        <i class="material-icons" style="font-size:18px; color:#94a3b8;">expand_more</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px; margin-top:5px; border-radius:10px; z-index:1001; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);">
                        <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                            <div class="dropdown-item selected" onclick="document.getElementById('fl_estado_al_crear').value='INOPERATIVO'; document.getElementById('fl_estado_al_crear_label').innerText='Inoperativo'; document.querySelectorAll('#flEstadoCrearDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">Inoperativo</div>
                            <div class="dropdown-item" onclick="document.getElementById('fl_estado_al_crear').value='EN MANTENIMIENTO'; document.getElementById('fl_estado_al_crear_label').innerText='En Mantenimiento'; document.querySelectorAll('#flEstadoCrearDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">En Mantenimiento</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Campos extensos (visibles solo si tipo=extenso) --}}
            <div id="fl_fields_extenso" style="display:none; flex-direction:column; gap:10px;">
                <div>
                    <label class="fl-field-label" for="fl_horometro">Horometro / Kilometraje</label>
                    <input type="text" id="fl_horometro" name="horometro" class="fl-input" placeholder="Ej: 12500 km / 3200 hrs">
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label class="fl-field-label">Sistema Afectado</label>
                        <div class="custom-dropdown" id="flSistemaDD" style="width:100%;">
                            <input type="hidden" id="fl_sistema" name="sistema" value="">
                            <div class="dropdown-trigger" style="padding:0 12px; display:flex; align-items:center; background:white; border:1px solid #cbd5e1; border-radius:10px; height:45px; justify-content:space-between;" onclick="event.stopPropagation(); const c=this.nextElementSibling; document.querySelectorAll('.dropdown-content').forEach(el=>el!==c?el.style.display='none':null); c.style.display=(c.style.display==='none'||!c.style.display)?'block':'none';">
                                <span id="fl_sistema_label" style="font-size:13.5px; color:#94a3b8;">Seleccionar...</span>
                                <i class="material-icons" style="font-size:18px; color:#94a3b8;">expand_more</i>
                            </div>
                            <div class="dropdown-content" style="padding:5px; margin-top:5px; border-radius:10px; z-index:1001; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);">
                                <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                                    <div class="dropdown-item selected" onclick="document.getElementById('fl_sistema').value=''; document.getElementById('fl_sistema_label').innerText='Seleccionar...'; document.getElementById('fl_sistema_label').style.color='#94a3b8'; document.querySelectorAll('#flSistemaDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">Seleccionar...</div>
                                    @foreach(\App\Models\Falla::sistemasAfectados() as $k => $v)
                                        <div class="dropdown-item" onclick="document.getElementById('fl_sistema').value='{{ $k }}'; document.getElementById('fl_sistema_label').innerText='{{ $v }}'; document.getElementById('fl_sistema_label').style.color='#1e293b'; document.querySelectorAll('#flSistemaDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">{{ $v }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="fl-field-label">Prioridad</label>
                        <div class="custom-dropdown" id="flPrioridadDD" style="width:100%;">
                            <input type="hidden" id="fl_prioridad" name="prioridad" value="">
                            <div class="dropdown-trigger" style="padding:0 12px; display:flex; align-items:center; background:white; border:1px solid #cbd5e1; border-radius:10px; height:45px; justify-content:space-between;" onclick="event.stopPropagation(); const c=this.nextElementSibling; document.querySelectorAll('.dropdown-content').forEach(el=>el!==c?el.style.display='none':null); c.style.display=(c.style.display==='none'||!c.style.display)?'block':'none';">
                                <span id="fl_prioridad_label" style="font-size:13.5px; color:#94a3b8;">Seleccionar...</span>
                                <i class="material-icons" style="font-size:18px; color:#94a3b8;">expand_more</i>
                            </div>
                            <div class="dropdown-content" style="padding:5px; margin-top:5px; border-radius:10px; z-index:1001; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);">
                                <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                                    <div class="dropdown-item selected" onclick="document.getElementById('fl_prioridad').value=''; document.getElementById('fl_prioridad_label').innerText='Seleccionar...'; document.getElementById('fl_prioridad_label').style.color='#94a3b8'; document.querySelectorAll('#flPrioridadDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">Seleccionar...</div>
                                    @foreach(\App\Models\Falla::prioridades() as $k => $v)
                                        <div class="dropdown-item" onclick="document.getElementById('fl_prioridad').value='{{ $k }}'; document.getElementById('fl_prioridad_label').innerText='{{ $v }}'; document.getElementById('fl_prioridad_label').style.color='#1e293b'; document.querySelectorAll('#flPrioridadDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">{{ $v }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="fl-field-label">Tipo de Intervencion</label>
                    <div class="custom-dropdown" id="flIntervencionDD" style="width:100%;">
                        <input type="hidden" id="fl_tipo_intervencion" name="tipo_intervencion" value="">
                        <div class="dropdown-trigger" style="padding:0 12px; display:flex; align-items:center; background:white; border:1px solid #cbd5e1; border-radius:10px; height:45px; justify-content:space-between;" onclick="event.stopPropagation(); const c=this.nextElementSibling; document.querySelectorAll('.dropdown-content').forEach(el=>el!==c?el.style.display='none':null); c.style.display=(c.style.display==='none'||!c.style.display)?'block':'none';">
                            <span id="fl_tipo_intervencion_label" style="font-size:13.5px; color:#94a3b8;">Seleccionar...</span>
                            <i class="material-icons" style="font-size:18px; color:#94a3b8;">expand_more</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px; margin-top:5px; border-radius:10px; z-index:1001; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);">
                            <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                                <div class="dropdown-item selected" onclick="document.getElementById('fl_tipo_intervencion').value=''; document.getElementById('fl_tipo_intervencion_label').innerText='Seleccionar...'; document.getElementById('fl_tipo_intervencion_label').style.color='#94a3b8'; document.querySelectorAll('#flIntervencionDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">Seleccionar...</div>
                                @foreach(\App\Models\Falla::tiposIntervencion() as $k => $v)
                                    <div class="dropdown-item" onclick="document.getElementById('fl_tipo_intervencion').value='{{ $k }}'; document.getElementById('fl_tipo_intervencion_label').innerText='{{ $v }}'; document.getElementById('fl_tipo_intervencion_label').style.color='#1e293b'; document.querySelectorAll('#flIntervencionDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">{{ $v }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="fl-field-label" for="fl_repuestos">Repuestos Estimados</label>
                    <textarea id="fl_repuestos" name="repuestos" class="fl-textarea"></textarea>
                </div>
                <div>
                    <label class="fl-field-label" for="fl_observaciones">Observaciones del Mecánico</label>
                    <textarea id="fl_observaciones" name="observaciones" class="fl-textarea"></textarea>
                </div>
            </div>

            <div>
                <label class="fl-field-label" for="fl_descripcion">Descripción de la Avería</label>
                <textarea id="fl_descripcion" name="descripcion" class="fl-textarea" placeholder="Describe brevemente la falla detectada..."></textarea>
            </div>

            <button type="submit" id="fl_submit_btn" class="falla-btn falla-btn-primary" style="height:44px; width:100%; justify-content:center;">
                <i class="material-icons">send</i> Crear Reporte
            </button>
        </form>
    </div>
</div>


{{-- ─── Modal: Cerrar Reporte de Falla ─── --}}
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
                <label class="fl-field-label" for="cierreObservaciones">Observaciones de cierre <span style="font-weight:400; color:#94a3b8;">(opcional)</span></label>
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
