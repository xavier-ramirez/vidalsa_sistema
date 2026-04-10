@extends('layouts.estructura_base')

@section('title', 'Mantenimiento')

@section('extra_css')
<style>
    /* ── Mantenimiento Module Styles ── */
    .mant-container { padding: 10px 20px; max-width: 1400px; margin: 0 auto; }

    .mant-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 20px; flex-wrap: wrap; gap: 10px;
    }
    .mant-header h1 { font-size: 22px; font-weight: 800; color: #1e293b; margin: 0; display: flex; align-items: center; gap: 10px; }
    .mant-header h1 i { font-size: 26px; color: #0067b1; }

    /* ── Tabs ── */
    .mant-tabs {
        display: flex; gap: 0; background: #f1f5f9; border-radius: 12px; padding: 4px;
        margin-bottom: 20px; overflow-x: auto;
    }
    .mant-tab {
        padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700;
        color: #64748b; cursor: pointer; transition: all 0.2s; white-space: nowrap;
        display: flex; align-items: center; gap: 6px; border: none; background: transparent;
    }
    .mant-tab:hover { color: #1e293b; background: rgba(255,255,255,0.5); }
    .mant-tab.active { background: white; color: #0067b1; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .mant-tab .badge {
        background: #ef4444; color: white; font-size: 10px; font-weight: 800;
        padding: 2px 7px; border-radius: 50px; min-width: 18px; text-align: center;
    }

    /* ── Tab Panels ── */
    .mant-panel { display: none; }
    .mant-panel.active { display: block; }

    /* ── Stats Row ── */
    .mant-stats {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px; margin-bottom: 20px;
    }
    .mant-stat-card {
        background: white; border-radius: 14px; padding: 16px 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06); display: flex; align-items: center; gap: 14px;
        transition: transform 0.15s;
    }
    .mant-stat-card:hover { transform: translateY(-2px); }
    .mant-stat-icon {
        width: 44px; height: 44px; border-radius: 12px; display: flex;
        align-items: center; justify-content: center;
    }
    .mant-stat-icon i { font-size: 22px; color: white; }
    .mant-stat-icon.red { background: linear-gradient(135deg, #ef4444, #dc2626); }
    .mant-stat-icon.amber { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .mant-stat-icon.green { background: linear-gradient(135deg, #22c55e, #16a34a); }
    .mant-stat-icon.blue { background: linear-gradient(135deg, #3b82f6, #2563eb); }
    .mant-stat-label { font-size: 11px; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
    .mant-stat-value { font-size: 24px; font-weight: 800; color: #1e293b; line-height: 1; }

    /* ── Filter Bar ── */
    .mant-filter-bar {
        display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: nowrap; align-items: center;
        width: 100%; box-sizing: border-box;
    }
    .mant-filter-bar .custom-dropdown {
        flex: 1 1 0; min-width: 0; position: relative;
    }
    .mant-filter-bar .search-wrapper {
        flex: 1 1 0; min-width: 0; display: flex; align-items: center;
        border: 1px solid #cbd5e0; border-radius: 12px; padding: 0 12px;
        background: #fbfcfd; height: 45px; box-sizing: border-box;
    }
    .mant-filter-bar .search-wrapper input {
        border: none; outline: none; background: transparent; width: 100%;
        font-size: 13px; color: #1e293b; padding: 0 8px; min-width: 0;
    }
    .mant-filter-bar .search-wrapper i { color: #94a3b8; font-size: 20px; flex-shrink: 0; }
    .mant-filter-bar input[type="date"] {
        height: 45px; border: 1px solid #cbd5e0; border-radius: 12px; padding: 0 14px;
        font-size: 14px; color: #1e293b; background: #fbfcfd; outline: none;
        flex: 0 0 auto; box-sizing: border-box;
    }
    .mant-filter-bar .custom-dropdown .dropdown-trigger input[data-filter-search] {
        min-width: 0; width: 100%;
    }

    @media (max-width: 768px) {
        .mant-filter-bar { flex-wrap: wrap; }
        .mant-filter-bar .custom-dropdown,
        .mant-filter-bar .search-wrapper,
        .mant-filter-bar input[type="date"] { flex: 1 1 100%; }
    }

    /* ── Cards / Content ── */
    .mant-card {
        background: white; border-radius: 16px; padding: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.03);
        margin-bottom: 16px;
    }
    .mant-card-header {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;
    }
    .mant-card-title { font-size: 15px; font-weight: 800; color: #1e293b; display: flex; align-items: center; gap: 8px; }
    .mant-card-title i { font-size: 20px; color: #0067b1; }

    /* ── Table ── */
    .mant-table { width: 100%; border-collapse: collapse; }
    .mant-table thead th {
        padding: 10px 12px; font-size: 11px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e2e8f0;
        text-align: left; white-space: nowrap;
    }
    .mant-table tbody td {
        padding: 12px; font-size: 13px; color: #334155; border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .mant-table tbody tr:hover { background: #f8fafc; }

    /* ── Badges / Pills ── */
    .badge-prioridad {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 50px; font-size: 11px; font-weight: 700;
    }
    .badge-prioridad.critica { background: #fef2f2; color: #dc2626; }
    .badge-prioridad.alta { background: #fff7ed; color: #ea580c; }
    .badge-prioridad.media { background: #fefce8; color: #ca8a04; }
    .badge-prioridad.baja { background: #f0fdf4; color: #16a34a; }

    .badge-estado {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 50px; font-size: 11px; font-weight: 700;
    }
    .badge-estado.abierta { background: #fef2f2; color: #dc2626; }
    .badge-estado.en_proceso { background: #fff7ed; color: #ea580c; }
    .badge-estado.resuelta { background: #f0fdf4; color: #16a34a; }

    .badge-reporte {
        display: inline-flex; padding: 3px 10px; border-radius: 50px; font-size: 11px; font-weight: 700;
    }
    .badge-reporte.abierto { background: #dbeafe; color: #2563eb; }
    .badge-reporte.cerrado { background: #f1f5f9; color: #64748b; }

    /* ── Buttons ── */
    .btn-mant-primary {
        background: #0067b1; color: white; border: none; padding: 10px 20px;
        border-radius: 12px; font-weight: 700; font-size: 13px; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
    }
    .btn-mant-primary:hover { background: #005a9e; transform: translateY(-1px); box-shadow: 0 4px 6px rgba(0,103,177,0.3); }

    .btn-mant-secondary {
        background: white; color: #475569; border: 1px solid #e2e8f0; padding: 10px 20px;
        border-radius: 12px; font-weight: 700; font-size: 13px; cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;
    }
    .btn-mant-secondary:hover { background: #f8fafc; border-color: #cbd5e0; }

    .btn-mant-sm {
        padding: 6px 12px; font-size: 12px; border-radius: 8px; border: none;
        font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
        transition: all 0.15s;
    }

    .btn-mant-danger { background: #fef2f2; color: #dc2626; }
    .btn-mant-danger:hover { background: #fee2e2; }
    .btn-mant-success { background: #f0fdf4; color: #16a34a; }
    .btn-mant-success:hover { background: #dcfce7; }
    .btn-mant-info { background: #eff6ff; color: #2563eb; }
    .btn-mant-info:hover { background: #dbeafe; }

    /* ── Empty State ── */
    .mant-empty {
        text-align: center; padding: 40px 20px; color: #94a3b8;
    }
    .mant-empty i { font-size: 48px; margin-bottom: 12px; display: block; opacity: 0.4; }
    .mant-empty p { font-size: 14px; font-weight: 600; }

    /* ── Responsive ── */
    /* ── Animation ── */
    @keyframes slideDown { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

    /* ── Responsive ── */
    @media (max-width: 768px) {
        .mant-container { padding: 10px 12px; }
        .mant-stats { grid-template-columns: repeat(2, 1fr); }
        .mant-header h1 { font-size: 18px; }
        .mant-table { font-size: 12px; }
        .mant-filter-bar { flex-direction: column; }
        .mant-filter-bar .search-wrapper { min-width: 100%; }
    }
</style>
@endsection

@section('content')
<div class="mant-container">

    <!-- Header -->
    <div class="mant-header">
        <h1><i class="material-icons">build</i> Mantenimiento Integral</h1>
        <div style="display: flex; gap: 8px;">
            <button class="btn-mant-primary" onclick="abrirFormularioFalla()">
                <i class="material-icons" style="font-size:16px;">add_circle</i> Registrar Falla
            </button>
            <button class="btn-mant-secondary" onclick="exportarPdfConsolidado()">
                <i class="material-icons" style="font-size:16px;">picture_as_pdf</i> PDF
            </button>
        </div>
    </div>

    <!-- Stats -->
    <div class="mant-stats" id="mantStats">
        <div class="mant-stat-card">
            <div class="mant-stat-icon red"><i class="material-icons">warning</i></div>
            <div>
                <div class="mant-stat-label">Fallas Abiertas Hoy</div>
                <div class="mant-stat-value" id="statFallasAbiertas">-</div>
            </div>
        </div>
        <div class="mant-stat-card">
            <div class="mant-stat-icon amber"><i class="material-icons">build</i></div>
            <div>
                <div class="mant-stat-label">Equipos Inoperativos</div>
                <div class="mant-stat-value" id="statInoperativos">-</div>
            </div>
        </div>
        <div class="mant-stat-card">
            <div class="mant-stat-icon green"><i class="material-icons">check_circle</i></div>
            <div>
                <div class="mant-stat-label">Resueltas Hoy</div>
                <div class="mant-stat-value" id="statResueltas">-</div>
            </div>
        </div>
        <div class="mant-stat-card">
            <div class="mant-stat-icon blue"><i class="material-icons">assignment</i></div>
            <div>
                <div class="mant-stat-label">Reportes del Día</div>
                <div class="mant-stat-value" id="statReportes">-</div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="mant-tabs">
        <button class="mant-tab active" data-tab="mi-frente" onclick="switchTab('mi-frente')">
            <i class="material-icons" style="font-size:18px;">assignment</i> Mi Frente
        </button>
        <button class="mant-tab" data-tab="consolidado" onclick="switchTab('consolidado')">
            <i class="material-icons" style="font-size:18px;">dashboard</i> Consolidado Nacional
        </button>
        <button class="mant-tab" data-tab="timeline" onclick="switchTab('timeline')">
            <i class="material-icons" style="font-size:18px;">timeline</i> Timeline Equipo
        </button>
    </div>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- TAB 1: Mi Frente (Reporte Diario) -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="mant-panel active" id="panel-mi-frente">

        <!-- Filters -->
        <div class="mant-filter-bar">
            {{-- Dropdown Frente --}}
            <div class="custom-dropdown" id="frenteFilterSelect" data-filter-type="frente" data-default-label="Todos los Frentes" style="min-width:200px;">
                <input type="hidden" id="filterFrente" name="frente" data-filter-value value="">
                <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                    <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text,#94a3b8);">
                        <i class="material-icons" style="font-size:18px;">search</i>
                    </div>
                    <input type="text" data-filter-search placeholder="Todos los Frentes" autocomplete="off"
                        style="flex:1; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none; min-width:0;"
                        oninput="window.filterDropdownOptions && window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 8px; color:var(--maquinaria-gray-text,#94a3b8); font-size:18px; display:none; cursor:pointer;"
                        onclick="event.stopPropagation(); clearDropdownFilter('frenteFilterSelect'); cargarReportes();">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                    <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                        <div class="dropdown-item selected" data-value="" onclick="selectOption('frenteFilterSelect','','Todos los Frentes'); cargarReportes();">TODOS LOS FRENTES</div>
                        @foreach($frentes as $f)
                            <div class="dropdown-item" data-value="{{ $f->ID_FRENTE }}" onclick="selectOption('frenteFilterSelect','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}'); cargarReportes();">{{ $f->NOMBRE_FRENTE }}</div>
                        @endforeach
                    </div>
                </div>
            </div>

            <input type="date" id="filterFecha" value="{{ date('Y-m-d') }}" onchange="cargarReportes()" style="height:45px; border:1px solid #cbd5e0; border-radius:12px; padding:0 14px; font-size:14px; color:#1e293b; background:#fbfcfd; outline:none;">

            {{-- Dropdown Estado --}}
            <div class="custom-dropdown" id="estadoFilterSelect" data-filter-type="estado" data-default-label="Todos" style="min-width:140px;">
                <input type="hidden" id="filterEstado" name="estado" data-filter-value value="">
                <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                    <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text,#94a3b8);">
                        <i class="material-icons" style="font-size:18px;">filter_list</i>
                    </div>
                    <input type="text" data-filter-search placeholder="Todos" autocomplete="off"
                        style="flex:1; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none; min-width:0;"
                        oninput="window.filterDropdownOptions && window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 8px; color:var(--maquinaria-gray-text,#94a3b8); font-size:18px; display:none; cursor:pointer;"
                        onclick="event.stopPropagation(); clearDropdownFilter('estadoFilterSelect'); cargarReportes();">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                    <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                        <div class="dropdown-item selected" data-value="" onclick="selectOption('estadoFilterSelect','','Todos'); cargarReportes();">TODOS</div>
                        <div class="dropdown-item" data-value="ABIERTO" onclick="selectOption('estadoFilterSelect','ABIERTO','Abiertos'); cargarReportes();">ABIERTOS</div>
                        <div class="dropdown-item" data-value="CERRADO" onclick="selectOption('estadoFilterSelect','CERRADO','Cerrados'); cargarReportes();">CERRADOS</div>
                    </div>
                </div>
            </div>

            <div class="search-wrapper">
                <i class="material-icons">search</i>
                <input type="text" id="searchReportes" placeholder="Buscar equipo, descripción..." onkeyup="filtrarTablaLocal(this.value)">
            </div>
        </div>

        <!-- Reports List -->
        <div class="mant-card">
            <div class="mant-card-header">
                <span class="mant-card-title"><i class="material-icons">description</i> Reportes Diarios</span>
                <button class="btn-mant-sm btn-mant-info" onclick="crearReporteHoy()">
                    <i class="material-icons" style="font-size:14px;">today</i> Reporte de Hoy
                </button>
            </div>
            <div id="reportesTableContainer">
                <div class="mant-empty">
                    <i class="material-icons">assignment</i>
                    <p>Selecciona un frente o haz clic en "Reporte de Hoy"</p>
                </div>
            </div>
            <div id="reportesPagination"></div>
        </div>

        <!-- Fault Detail (loaded when clicking a report) -->
        <div class="mant-card" id="fallaDetailCard" style="display: none;">
            <div class="mant-card-header">
                <span class="mant-card-title" id="fallaDetailTitle"><i class="material-icons">error_outline</i> Fallas del Reporte</span>
                <div style="display: flex; gap: 6px;">
                    <button class="btn-mant-sm btn-mant-info" onclick="exportarPdfReporte()">
                        <i class="material-icons" style="font-size:14px;">picture_as_pdf</i> PDF
                    </button>
                    <button class="btn-mant-sm btn-mant-danger" id="btnCerrarReporte" onclick="cerrarReporteActual()" style="display:none;">
                        <i class="material-icons" style="font-size:14px;">lock</i> Cerrar Reporte
                    </button>
                </div>
            </div>
            <div id="fallasTableContainer"></div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- TAB 2: Consolidado Nacional -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="mant-panel" id="panel-consolidado">
        <div class="mant-filter-bar">
            <input type="date" id="consolidadoFecha" value="{{ date('Y-m-d') }}" onchange="cargarConsolidado()" style="height:45px; border:1px solid #cbd5e0; border-radius:12px; padding:0 14px; font-size:14px; color:#1e293b; background:#fbfcfd; outline:none;">
            <button class="btn-mant-secondary" onclick="cargarConsolidado()">
                <i class="material-icons" style="font-size:16px;">refresh</i> Actualizar
            </button>
        </div>
        <div id="consolidadoContainer">
            <div class="mant-empty">
                <i class="material-icons">dashboard</i>
                <p>Cargando consolidado nacional...</p>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════ -->
    <!-- TAB 3: Timeline de Equipo -->
    <!-- ══════════════════════════════════════════════════════ -->
    <div class="mant-panel" id="panel-timeline">
        <div class="mant-filter-bar">
            <div class="search-wrapper" style="max-width: 400px;">
                <i class="material-icons">search</i>
                <input type="text" id="timelineEquipoSearch" placeholder="Buscar equipo por serial, placa, etiqueta..." autocomplete="off">
            </div>
        </div>
        <div id="timelineSearchResults" style="display:none; margin-bottom: 16px;"></div>
        <div id="timelineContainer">
            <div class="mant-empty">
                <i class="material-icons">timeline</i>
                <p>Busca un equipo para ver su historial de estados</p>
            </div>
        </div>
    </div>

</div>

<!-- ══════════════════════════════════════════════════════ -->
<!-- MODAL: Registrar Nueva Falla -->
<!-- ══════════════════════════════════════════════════════ -->
<div id="modalRegistrarFalla" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; justify-content:center; align-items:center;">
    <div style="background:white; width:95%; max-width:550px; max-height:90vh; border-radius:16px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden; animation: slideDown 0.3s ease-out;">

        <!-- Header -->
        <div style="background:linear-gradient(135deg, #0067b1, #005a9e); padding:14px 18px; color:white; flex-shrink:0;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="material-icons" style="font-size:22px;">report_problem</i>
                    <div>
                        <h3 style="margin:0; font-size:15px; font-weight:800;">Registrar Falla</h3>
                        <p style="margin:0; font-size:11px; opacity:0.85;">Reporte de Inoperatividad</p>
                    </div>
                </div>
                <button type="button" onclick="cerrarModalFalla()" style="background:rgba(255,255,255,0.2); border:none; color:white; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <i class="material-icons" style="font-size:18px;">close</i>
                </button>
            </div>
        </div>

        <!-- Body -->
        <div style="padding:20px 25px; overflow-y:auto; flex:1;">

            <!-- Frente de Trabajo -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:6px;">
                    <span style="background:#0067b1; color:white; padding:2px 8px; border-radius:50%; font-size:11px; font-weight:800; margin-right:6px;">1</span>
                    Frente de Trabajo
                </label>
                <div class="custom-dropdown" id="fallaFrenteDropdown" data-default-label="Seleccionar frente..." style="width:100%;">
                    <input type="hidden" id="fallaFrente" value="">
                    <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                        <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text,#94a3b8);">
                            <i class="material-icons" style="font-size:18px;">business</i>
                        </div>
                        <input type="text" data-filter-search placeholder="Seleccionar frente..." autocomplete="off"
                            style="flex:1; border:none; background:transparent; padding:10px 5px; font-size:13px; outline:none; min-width:0;"
                            oninput="window.filterDropdownOptions && window.filterDropdownOptions(this)">
                        <i class="material-icons" data-clear-btn style="padding:0 8px; color:var(--maquinaria-gray-text,#94a3b8); font-size:18px; display:none; cursor:pointer;"
                            onclick="event.stopPropagation(); clearDropdownFilter('fallaFrenteDropdown');">close</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:10002;">
                        <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                            @foreach($frentes as $f)
                                <div class="dropdown-item" data-value="{{ $f->ID_FRENTE }}"
                                    onclick="selectOption('fallaFrenteDropdown','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}');">
                                    {{ $f->NOMBRE_FRENTE }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <!-- Equipo -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:6px;">
                    <span style="background:#0067b1; color:white; padding:2px 8px; border-radius:50%; font-size:11px; font-weight:800; margin-right:6px;">2</span>
                    Equipo
                </label>
                <div class="custom-dropdown" id="fallaEquipoDropdown" data-default-label="Seleccionar equipo..." style="width:100%;">
                    <input type="hidden" id="fallaEquipo" value="">
                    <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                        <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text,#94a3b8);">
                            <i class="material-icons" style="font-size:18px;">search</i>
                        </div>
                        <input type="text" data-filter-search placeholder="Seleccionar equipo..." autocomplete="off"
                            style="flex:1; border:none; background:transparent; padding:10px 5px; font-size:13px; outline:none; min-width:0;"
                            oninput="window.filterDropdownOptions && window.filterDropdownOptions(this)">
                        <i class="material-icons" data-clear-btn style="padding:0 8px; color:var(--maquinaria-gray-text,#94a3b8); font-size:18px; display:none; cursor:pointer;"
                            onclick="event.stopPropagation(); clearDropdownFilter('fallaEquipoDropdown'); onEquipoSelected();">close</i>
                    </div>
                    <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:10002;">
                        <div class="dropdown-item-list" style="max-height:280px; overflow-y:auto;">
                            <div class="dropdown-item" data-value="" onclick="selectOption('fallaEquipoDropdown','','Seleccionar equipo...'); onEquipoSelected();" style="padding:8px 10px; font-size:13px; color:#94a3b8;">— Ninguno —</div>
                            @foreach($equipos as $eq)
                                @php $eqFoto = $eq->foto; @endphp
                                <div class="dropdown-item" data-value="{{ $eq->ID_EQUIPO }}"
                                    onclick="selectOption('fallaEquipoDropdown','{{ $eq->ID_EQUIPO }}','{{ addslashes(($eq->tipo->nombre ?? 'S/T') . ' - ' . $eq->MARCA . ' ' . $eq->MODELO) }}'); onEquipoSelected();"
                                    style="display:flex; align-items:center; gap:10px; padding:6px 10px;">
                                    @if($eqFoto)
                                        <img src="{{ $eqFoto }}" alt="" style="width:38px; height:38px; border-radius:8px; object-fit:cover; flex-shrink:0; background:#f1f5f9;">
                                    @else
                                        <div style="width:38px; height:38px; border-radius:8px; background:#f1f5f9; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                                            <i class="material-icons" style="font-size:20px; color:#cbd5e0;">agriculture</i>
                                        </div>
                                    @endif
                                    <div style="min-width:0; flex:1;">
                                        <div style="font-size:12px; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $eq->tipo->nombre ?? 'S/T' }} — {{ $eq->MARCA }} {{ $eq->MODELO }}</div>
                                        <div style="font-size:11px; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">{{ $eq->SERIAL_CHASIS ?? $eq->CODIGO_PATIO ?? '#'.$eq->NUMERO_ETIQUETA }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <!-- Recomendaciones auto -->
                <div id="recomendacionesContainer" style="display:none; margin-top:8px;"></div>
            </div>

            <!-- Tipo de Falla -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:6px;">
                    <span style="background:#0067b1; color:white; padding:2px 8px; border-radius:50%; font-size:11px; font-weight:800; margin-right:6px;">3</span>
                    Tipo de Falla
                </label>
                <div class="custom-dropdown" id="fallaTipoDropdown" data-default-label="Mecánica" style="width:100%;">
                    <input type="hidden" id="fallaTipo" value="MECANICA">
                    <div class="dropdown-trigger" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                        <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text,#94a3b8);">
                            <i class="material-icons" style="font-size:18px;">category</i>
                        </div>
                        <input type="text" data-filter-search placeholder="Mecánica" autocomplete="off"
                            style="flex:1; border:none; background:transparent; padding:10px 5px; font-size:13px; outline:none; min-width:0;"
                            oninput="window.filterDropdownOptions && window.filterDropdownOptions(this)">
                    </div>
                    <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:10002;">
                        <div class="dropdown-item-list" style="max-height:220px; overflow-y:auto;">
                            <div class="dropdown-item selected" data-value="MECANICA" onclick="selectOption('fallaTipoDropdown','MECANICA','Mecánica');">Mecánica</div>
                            <div class="dropdown-item" data-value="ELECTRICA" onclick="selectOption('fallaTipoDropdown','ELECTRICA','Eléctrica');">Eléctrica</div>
                            <div class="dropdown-item" data-value="HIDRAULICA" onclick="selectOption('fallaTipoDropdown','HIDRAULICA','Hidráulica');">Hidráulica</div>
                            <div class="dropdown-item" data-value="NEUMATICA" onclick="selectOption('fallaTipoDropdown','NEUMATICA','Neumática');">Neumática</div>
                            <div class="dropdown-item" data-value="ESTRUCTURAL" onclick="selectOption('fallaTipoDropdown','ESTRUCTURAL','Estructural');">Estructural</div>
                            <div class="dropdown-item" data-value="OTRA" onclick="selectOption('fallaTipoDropdown','OTRA','Otra');">Otra</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sistema Afectado -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:6px;">
                    <span style="background:#0067b1; color:white; padding:2px 8px; border-radius:50%; font-size:11px; font-weight:800; margin-right:6px;">4</span>
                    Sistema Afectado <span style="font-weight:400; color:#94a3b8;">(opcional)</span>
                </label>
                <input type="text" id="fallaSistema" placeholder="Ej: Motor, Transmisión, Sistema Hidráulico..."
                    style="width:100%; padding:10px 14px; border:1px solid #cbd5e0; border-radius:10px; font-size:13px; background:#f8fafc; outline:none; box-sizing:border-box;">
            </div>

            <!-- Prioridad -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:6px;">
                    <span style="background:#0067b1; color:white; padding:2px 8px; border-radius:50%; font-size:11px; font-weight:800; margin-right:6px;">5</span>
                    Prioridad
                </label>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <label style="display:flex; align-items:center; gap:6px; padding:8px 14px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; transition:all 0.15s;" class="prio-option">
                        <input type="radio" name="fallaPrioridad" value="BAJA" style="accent-color:#16a34a;"> Baja
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; padding:8px 14px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; transition:all 0.15s;" class="prio-option">
                        <input type="radio" name="fallaPrioridad" value="MEDIA" checked style="accent-color:#ca8a04;"> Media
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; padding:8px 14px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; transition:all 0.15s;" class="prio-option">
                        <input type="radio" name="fallaPrioridad" value="ALTA" style="accent-color:#ea580c;"> Alta
                    </label>
                    <label style="display:flex; align-items:center; gap:6px; padding:8px 14px; border:1px solid #e2e8f0; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; transition:all 0.15s;" class="prio-option">
                        <input type="radio" name="fallaPrioridad" value="CRITICA" style="accent-color:#dc2626;"> Crítica
                    </label>
                </div>
            </div>

            <!-- Descripción -->
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:6px;">
                    <span style="background:#0067b1; color:white; padding:2px 8px; border-radius:50%; font-size:11px; font-weight:800; margin-right:6px;">6</span>
                    Descripción de la Falla
                </label>
                <textarea id="fallaDescripcion" rows="3" placeholder="Describe la falla con el mayor detalle posible..."
                    style="width:100%; padding:10px 14px; border:1px solid #cbd5e0; border-radius:10px; font-size:13px; background:#f8fafc; outline:none; resize:vertical; box-sizing:border-box; font-family:inherit;"></textarea>
            </div>
        </div>

        <!-- Footer -->
        <div style="padding:15px 25px; border-top:1px solid #e2e8f0; display:flex; gap:10px; flex-shrink:0; background:#fafbfc;">
            <button type="button" onclick="cerrarModalFalla()" style="flex:1; padding:12px; background:white; border:1px solid #e2e8f0; border-radius:10px; font-weight:600; color:#64748b; cursor:pointer;">
                Cancelar
            </button>
            <button type="button" onclick="guardarFalla()" style="flex:1; padding:12px; background:#0067b1; border:none; border-radius:10px; font-weight:700; color:white; display:flex; align-items:center; justify-content:center; gap:6px; cursor:pointer; transition:all 0.2s;"
                onmouseover="this.style.background='#005a9e'" onmouseout="this.style.background='#0067b1'">
                <i class="material-icons" style="font-size:16px;">save</i> Guardar Falla
            </button>
        </div>
    </div>
</div>

{{-- Modal Detalle Falla --}}
@include('admin.mantenimiento.partials.modal_detalle_falla')

@endsection

@section('extra_js')
<script src="{{ asset('js/maquinaria/mantenimiento_index.js') }}?v=1.2"></script>
<script src="{{ asset('js/maquinaria/mantenimiento_fallas.js') }}?v=1.2"></script>
@endsection
