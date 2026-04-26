@extends('layouts.estructura_base')

@section('title', 'Auditoría de Documentos')

@section('content')
<style>
    .badge-doc {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #ebf8ff;
        color: #2b6cb0;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
    }
    .badge-autor {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #f1f5f9;
        color: #475569;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 500;
    }
    .btn-view-pdf {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-view-pdf:hover {
        background: #e2e8f0;
        color: #0f172a;
    }

    /* Historial — layout custom de la barra de filtros.
       PC: filtros se reparten el ancho disponible (flex:1 1 0 + min 240px),
           botones papelera tight al final, juntos.
       Mobile: filtros uno bajo otro full-width, botones papelera abajo
           lado a lado al 50/50. */
    #historialDocumentosTable, .filter-toolbar-container { /* placeholder */ }
    .hd-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: stretch;
    }
    .hd-filter-row > .filter-item.responsive-filter-item {
        flex: 1 1 240px !important;
        max-width: none !important;
        min-width: 240px;
    }
    .hd-papelera-group {
        display: flex;
        gap: 8px;
        flex: 0 0 auto;
    }
    .hd-papelera-group .filter-item {
        flex: 0 0 auto;
    }
    @media (max-width: 768px) {
        .hd-filter-row > .filter-item.responsive-filter-item {
            flex: 1 1 100% !important;
            min-width: 0 !important;
        }
        .hd-papelera-group {
            flex: 1 1 100%;
            gap: 8px;
        }
        .hd-papelera-group .filter-item {
            flex: 1 1 0 !important;
        }
        .hd-papelera-group .filter-item button {
            width: 100% !important;
        }
    }

    /* Mobile: en la tarjeta de la tabla (table-usuarios-mobile transforma td
       en lineas), mover el correo del autor a la misma fila que la fecha
       en lugar de bajo. La data viene en el TD #2, por eso lo absolute para
       que aparezca al lado del TD #1 (fecha). */
    @media (max-width: 768px) {
        #historialDocumentosTable.table-usuarios-mobile tbody tr {
            position: relative;
            padding-top: 50px !important;
        }
        #historialDocumentosTable.table-usuarios-mobile tbody td:nth-child(1) {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 0 !important;
            background: transparent !important;
        }
        #historialDocumentosTable.table-usuarios-mobile tbody td:nth-child(2) {
            position: absolute;
            top: 12px;
            right: 12px;
            text-align: right;
            padding: 0 !important;
            background: transparent !important;
            border-bottom: none !important;
        }
        #historialDocumentosTable.table-usuarios-mobile tbody td:nth-child(2) .badge-autor {
            background: transparent;
            padding: 0;
            color: #475569;
            font-size: 11px;
            font-weight: 600;
        }
    }
</style>

<section class="page-title-card" style="text-align: left; margin: 0 auto 10px auto; width: 98%; max-width: 1600px;">
    <h1 class="page-title" style="display: flex; align-items: center; gap: 12px; font-size: 24px;">
        <span class="page-title-line2" style="color: #000; margin: 0;">Auditoría de Documentos</span>
    </h1>
</section>

<div class="maquinaria-layout-container" style="display: grid; grid-template-columns: 1fr 280px; gap: 20px; width: 98%; max-width: 1600px; margin: 0 auto;">
    
    <!-- Left Column (Main Content) -->
    <div>
        <div class="admin-card">
            <div class="filter-toolbar-container hd-filter-row" style="margin-bottom: 5px;">
                <!-- Search Correo -->
                <div class="filter-item aligned-filter responsive-filter-item">
                    <form style="width: 100%;" onsubmit="event.preventDefault(); window.loadHistorialDocumentos();">
                        <div class="search-wrapper" style="width: 100%; border-color: #cbd5e0; background: #fbfcfd; height: 45px;">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="searchCorreo" name="search_correo"
                                placeholder="Buscar por correo autor..." 
                                class="search-input-field"
                                style="height: 100%;"
                                autocomplete="off"
                                onkeyup="window.checkHistorialClearBtn('searchCorreo', 'btn_clear_searchCorreo')">
                            <i id="btn_clear_searchCorreo" class="material-icons clear-icon" style="display: none;" onclick="clearHistorialFilter('btn_clear_searchCorreo', 'searchCorreo');">close</i>
                        </div>
                    </form>
                </div>

                <!-- Search Equipo (Placa/Serial) -->
                <div class="filter-item aligned-filter responsive-filter-item">
                    <form style="width: 100%;" onsubmit="event.preventDefault(); window.loadHistorialDocumentos();">
                        <div class="search-wrapper" style="width: 100%; border-color: #cbd5e0; background: #fbfcfd; height: 45px;">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="searchEquipo" name="search_equipo" 
                                placeholder="Buscar por placa o serial..." 
                                class="search-input-field"
                                style="height: 100%;"
                                autocomplete="off"
                                onkeyup="window.checkHistorialClearBtn('searchEquipo', 'btn_clear_searchEquipo')">
                            <i id="btn_clear_searchEquipo" class="material-icons clear-icon" style="display: none;" onclick="clearHistorialFilter('btn_clear_searchEquipo', 'searchEquipo');">close</i>
                        </div>
                    </form>
                </div>

                <!-- Filter Tipo Documento -->
                <div class="filter-item aligned-filter responsive-filter-item">
                    <div class="custom-dropdown" id="tipoDocFilterSelect" data-filter-type="tipo_filter" data-default-label="Filtrar Tipo Doc..." style="width: 100%;">
                        <input type="hidden" name="search_tipo" data-filter-value value="">
                        
                        <div class="dropdown-trigger" style="background: #fbfcfd; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">
                            
                            <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                                <i class="material-icons" style="font-size: 18px;">search</i>
                            </div>

                            <input type="text" name="filter_search_dropdown" data-filter-search
                                placeholder="Filtrar Tipo Doc..." 
                                style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; color: #4a5568;"
                                onkeyup="window.filterDropdownOptions(this)"
                                onfocus="this.closest('.custom-dropdown').classList.add('active')"
                                autocomplete="off">

                            <div style="display: flex; align-items: center; padding-right: 10px;">
                                <i class="material-icons" data-clear-btn
                                   style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: none;" 
                                   onclick="event.stopPropagation(); clearDropdownFilter('tipoDocFilterSelect'); window.loadHistorialDocumentos();"
                                   title="Limpiar filtro">close</i>
                            </div>
                        </div>

                        <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                            <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                                <div class="dropdown-item selected" data-value="all" onclick="selectOption('tipoDocFilterSelect', 'all', 'TODOS LOS DOCUMENTOS'); window.loadHistorialDocumentos();">
                                    TODOS LOS DOCUMENTOS
                                </div>
                                <div class="dropdown-item" data-value="propiedad" onclick="selectOption('tipoDocFilterSelect', 'propiedad', 'Título de Propiedad'); window.loadHistorialDocumentos();">
                                    Título de Propiedad
                                </div>
                                <div class="dropdown-item" data-value="poliza" onclick="selectOption('tipoDocFilterSelect', 'poliza', 'Póliza de Seguro'); window.loadHistorialDocumentos();">
                                    Póliza de Seguro
                                </div>
                                <div class="dropdown-item" data-value="rotc" onclick="selectOption('tipoDocFilterSelect', 'rotc', 'ROTC'); window.loadHistorialDocumentos();">
                                    ROTC
                                </div>
                                <div class="dropdown-item" data-value="racda" onclick="selectOption('tipoDocFilterSelect', 'racda', 'RACDA'); window.loadHistorialDocumentos();">
                                    RACDA
                                </div>
                                <div class="dropdown-item" data-value="certificado" onclick="selectOption('tipoDocFilterSelect', 'certificado', 'Certificado Asociado'); window.loadHistorialDocumentos();">
                                    Certificado Asociado
                                </div>
                                <div class="dropdown-item" data-value="compraventa" onclick="selectOption('tipoDocFilterSelect', 'compraventa', 'Compraventa'); window.loadHistorialDocumentos();">
                                    Compraventa
                                </div>
                                <div class="dropdown-item" data-value="registro de vehículo" onclick="selectOption('tipoDocFilterSelect', 'registro de vehículo', 'Registro de Vehículo'); window.loadHistorialDocumentos();">
                                    Registro de Vehículo
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Papelera buttons agrupados con label — desktop: lado a lado
                     en el row de filtros, mobile: full width compartido 50/50. --}}
                @can('user.delete')
                <div class="hd-papelera-group">
                    <div class="filter-item aligned-filter">
                        <button type="button" id="btnVerPapeleraEquipos"
                            onclick="window.abrirPapeleraEquipos && window.abrirPapeleraEquipos()"
                            title="Papelera de Vehículos"
                            style="height: 45px; width: 100%; padding: 0 12px; border-radius: 12px; background: white; border: 1px solid #fcd34d; color: #d97706; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 12.5px; font-weight: 700; white-space: nowrap;"
                            onmouseover="this.style.background='#fef3c7'"
                            onmouseout="this.style.background='white'">
                            <i class="material-icons" style="font-size:18px;">directions_car</i>
                            <span>Vehículos</span>
                        </button>
                    </div>
                    <div class="filter-item aligned-filter">
                        <button type="button" id="btnVerPapeleraAux"
                            onclick="window.abrirPapeleraAuxiliares && window.abrirPapeleraAuxiliares()"
                            title="Papelera de Auxiliares"
                            style="height: 45px; width: 100%; padding: 0 12px; border-radius: 12px; background: white; border: 1px solid #fed7aa; color: #c2410c; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 6px; font-size: 12.5px; font-weight: 700; white-space: nowrap;"
                            onmouseover="this.style.background='#fff7ed'"
                            onmouseout="this.style.background='white'">
                            <i class="material-icons" style="font-size:18px;">construction</i>
                            <span>Auxiliares</span>
                        </button>
                    </div>
                </div>
                @endcan

            </div>

            <!-- Unified Responsive Table -->
            <div class="custom-scrollbar-container">
                <table class="admin-table table-usuarios-mobile" id="historialDocumentosTable" style="width: 100% !important;">
                    <thead>
                        <tr style="background: #334155; text-align: left; color: #ffffff; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; border-bottom: 2px solid #1e293b;">
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 150px;">Fecha y Hora</th>
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 200px;">Autor</th>
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 180px;">Tipo de Documento</th>
                            <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 200px;">Equipo Asociado</th>
                            <th style="padding: 10px 15px; text-align: center; width: 100px;">Ver PDF</th>
                        </tr>
                    </thead>
                    <tbody id="historialTableBody" style="font-size: 14px;">
                        @include('admin.historial_documentos.partials.table_rows', ['events' => $events])
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="historialPagination" style="margin-top: 25px;">
                {{ $events->links('vendor.pagination.custom-sliding') }}
            </div>

        </div>
    </div>
    
    <!-- Right Sidebar -->
    <div class="counter-sidebar historial-sidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 20px; z-index: 10;">
        <!-- Total Card -->
        <div style="background: linear-gradient(135deg, #4c1d95 0%, #6d28d9 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
            <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">history</i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.9; margin-bottom: 5px;">Total Auditoría</div>
                <div style="display: flex; align-items: baseline; gap: 5px;">
                    <span id="historial-count-text" style="font-size: 32px; font-weight: 800; line-height: 1; letter-spacing: -1px;">
                        {{ $total }}
                    </span>
                    <span style="font-size: 12px; opacity: 0.8; font-weight: 500;">registros</span>
                </div>
            </div>
        </div>

        <!-- IPs Bloqueadas Card -->
        @if(isset($blockedIps) && $blockedIps->count() > 0 && auth()->check() && auth()->user()->can('super.admin'))
        <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: relative; z-index: 20;" id="blocked-ips-container">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #ef4444; font-size: 20px;">gpp_bad</i>
                    <h3 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b; text-transform: uppercase;">IPs Bloqueadas</h3>
                </div>
                <span class="badge" style="background: #fee2e2; color: #ef4444; font-size: 11px; padding: 2px 6px; border-radius: 10px; font-weight: 700;" id="blocked-ip-count">{{ $blockedIps->count() }}</span>
            </div>

            {{-- ─── Filtro de búsqueda de IPs ────────────────────────────── --}}
            <div style="position: relative; margin-bottom: 10px;">
                <i class="material-icons" style="position: absolute; left: 8px; top: 50%; transform: translateY(-50%); font-size: 16px; color: #94a3b8; pointer-events: none;">search</i>
                <input
                    type="text"
                    id="ip-filter-input"
                    placeholder="Filtrar por IP..."
                    autocomplete="off"
                    oninput="window.filterBlockedIps(this.value)"
                    style="width: 100%; box-sizing: border-box; padding: 7px 10px 7px 30px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 12px; color: #334155; background: #f8fafc; outline: none; transition: border-color 0.2s;"
                    onfocus="this.style.borderColor='#ef4444'; this.style.background='#fff'"
                    onblur="this.style.borderColor='#e2e8f0'; this.style.background='#f8fafc'"
                >
            </div>
            {{-- Mensaje sin resultados --}}
            <div id="ip-filter-empty" style="display: none; text-align: center; font-size: 12px; color: #94a3b8; padding: 8px 0;">Sin coincidencias</div>
            
            <div id="blocked-ips-list" style="display: flex; flex-direction: column; gap: 8px; max-height: 280px; overflow-y: auto; padding-right: 4px;" class="custom-scrollbar-container">
                @foreach($blockedIps as $ip)
                <div id="blocked-ip-{{ $ip->ID_BLOQUEO }}" data-ip-text="{{ $ip->DIRECCION_IP }}" style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #f1f5f9; transition: all 0.2s;">
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <span style="font-size: 13px; font-weight: 600; color: #334155; font-family: monospace;">{{ $ip->DIRECCION_IP }}</span>
                        <span style="font-size: 11px; color: #64748b; background: #e2e8f0; padding: 2px 6px; border-radius: 4px; font-weight: 600;" title="Último intento: {{ $ip->ULTIMO_INTENTO->format('d/m/Y H:i') }}">Fallos: {{ $ip->CANTIDAD_INTENTOS }}</span>
                    </div>
                    @can('super.admin')
                    <button 
                            class="btn-unlock-ip"
                            data-ip-id="{{ $ip->ID_BLOQUEO }}"
                            data-ip-address="{{ $ip->DIRECCION_IP }}"
                            style="background: transparent; border: none; padding: 4px; color: #ef4444; cursor: pointer; border-radius: 4px; transition: background 0.2s; display: flex; align-items: center; justify-content: center; pointer-events: all; position: relative; z-index: 30; margin-left: 10px;" 
                            onmouseover="this.style.background='#fee2e2'" 
                            onmouseout="this.style.background='transparent'" 
                            title="Desbloquear IP">
                        <i class="material-icons" style="font-size: 18px; pointer-events: none;">delete_outline</i>
                    </button>
                    @endcan
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- ─── Usuarios Activos (sesiones últimos 30 min) ─── --}}
        @if(isset($activeUsers) && auth()->check() && auth()->user()->can('super.admin'))
        <div style="background: white; border-radius: 12px; padding: 13px 15px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-top: 10px;">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #10b981; font-size: 18px;">radio_button_checked</i>
                    <h3 style="margin: 0; font-size: 12px; font-weight: 700; color: #1e293b; text-transform: uppercase; letter-spacing: 0.3px;">Usuarios Activos</h3>
                </div>
                <span style="background: #dcfce7; color: #15803d; font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 700;">{{ $activeUsers->count() }}</span>
            </div>
            @if($activeUsers->count() === 0)
                <p style="margin: 4px 0 0; font-size: 11px; color: #94a3b8; text-align: center; padding: 4px 0;">Nadie conectado en los últimos 30 min.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: 6px; max-height: 180px; overflow-y: auto; padding-right: 4px;" class="custom-scrollbar-container">
                    @foreach($activeUsers as $u)
                        @php
                            $minsAgo = max(0, (int) floor((now()->timestamp - $u->last_activity) / 60));
                            $ago = $minsAgo === 0 ? 'ahora' : ($minsAgo === 1 ? 'hace 1 min' : 'hace ' . $minsAgo . ' min');
                            $nombreCorto = $u->NOMBRE_COMPLETO ?: strtok($u->CORREO_ELECTRONICO, '@');
                        @endphp
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; padding: 6px 10px; border-radius: 6px; border: 1px solid #dcfce7;" title="{{ $u->CORREO_ELECTRONICO }} | IP: {{ $u->ip_address ?? 'N/A' }}">
                            <div style="display: flex; align-items: center; gap: 8px; min-width: 0; flex: 1;">
                                <span style="width: 7px; height: 7px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2); flex-shrink: 0;"></span>
                                <span style="font-size: 12px; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $nombreCorto }}</span>
                            </div>
                            <span style="font-size: 10px; color: #64748b; white-space: nowrap; margin-left: 6px;">{{ $ago }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        @endif
    </div>
</div>

{{-- ─── CONTADOR FLOTANTE DE SELECCIÓN ───────────────────────────────────── --}}
<div id="hd-selection-chip" class="selection-floating-bar">
    <div class="selection-counter">
        <div style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: white;">functions</i>
        </div>
        <span id="hd-selection-count">0</span>
    </div>
    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.2);"></div>
    <div style="display: flex; gap: 10px;">
        <button type="button" onclick="window.hdClearSelection()" style="background: transparent; border: none; color: #94a3b8; font-size: 13px; font-weight: 600;" onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            Limpiar
        </button>
    </div>
</div>

<style>
    /* Hover en filas seleccionables */
    #historialDocumentosTable .hd-selectable-row:not(.selected-row-maquinaria):hover td {
        background: #f8fafc !important;
        transition: background 0.15s;
    }

    /* Corrección para que la selección mantenga el borde redondeado de los TDs */
    #historialDocumentosTable tr.selected-row-maquinaria {
        background-color: transparent !important;
        border-left: none !important;
    }
    #historialDocumentosTable tr.selected-row-maquinaria td {
        background-color: #e1effa !important;
        color: #0067b1 !important;
        border-top-color: #93c5fd !important;
        border-bottom-color: #93c5fd !important;
        transition: all 0.2s ease;
    }
    #historialDocumentosTable tr.selected-row-maquinaria td:first-child {
        border-left: 4px solid #0067b1 !important;
        border-top-color: #93c5fd !important;
        border-bottom-color: #93c5fd !important;
    }
    #historialDocumentosTable tr.selected-row-maquinaria td:last-child {
        border-right-color: #93c5fd !important;
    }
</style>

{{-- ═══════════════════════════════════════════════════════════
     PAPELERA — modales de vehículos + auxiliares soft-deleted.
     Cargados via AJAX, respetan el permiso user.delete via middleware.
     ═══════════════════════════════════════════════════════════ --}}
@can('user.delete')
<script>
(function () {
    var csrfTok = function () { return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''; };
    var esc = function (s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); };

    function buildModal(id, title) {
        var old = document.getElementById(id + 'Overlay');
        if (old) old.remove();
        var overlay = document.createElement('div');
        overlay.id = id + 'Overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2500;display:flex;justify-content:center;align-items:center;';
        overlay.innerHTML = '<div style="background:white;border-radius:14px;width:90%;max-width:540px;max-height:80vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">' +
            '<div style="background:#1e293b;padding:12px 16px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">' +
                '<div style="display:flex;align-items:center;gap:8px;">' +
                    '<i class="material-icons" style="color:#f59e0b;font-size:18px;">history</i>' +
                    '<h2 style="margin:0;font-size:14px;font-weight:700;">' + title + '</h2>' +
                '</div>' +
                '<button type="button" onclick="document.getElementById(\'' + id + 'Overlay\').remove();" style="position:absolute;right:12px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;"><i class="material-icons" style="font-size:18px;">close</i></button>' +
            '</div>' +
            '<div id="' + id + 'List" style="overflow-y:auto;background:#f8fafc;padding:10px;flex:1;min-height:160px;">' +
                '<div style="padding:24px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:22px;">sync</i></div>' +
            '</div>' +
        '</div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        return overlay;
    }

    function renderRow(it, kind) {
        var idStr = it.placa || it.serial_chasis || it.serial || it.codigo || ('#' + it.id);
        var iconCol = kind === 'aux' ? '#c2410c' : '#1e40af';
        var iconBg  = kind === 'aux' ? '#fff7ed' : '#eff6ff';
        var iconNm  = kind === 'aux' ? 'construction' : 'directions_car';
        var fotoHtml = it.foto_drive_id
            ? '<img src="https://drive.google.com/thumbnail?id=' + esc(it.foto_drive_id) + '&sz=w120" style="width:42px;height:42px;border-radius:6px;object-fit:contain;background:white;border:1px solid #e2e8f0;flex-shrink:0;" onerror="this.outerHTML=\'<div style=&quot;width:42px;height:42px;border-radius:6px;background:' + iconBg + ';color:' + iconCol + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;&quot;><i class=&quot;material-icons&quot; style=&quot;font-size:20px;&quot;>' + iconNm + '</i></div>\'">'
            : '<div style="width:42px;height:42px;border-radius:6px;background:' + iconBg + ';color:' + iconCol + ';display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;"><i class="material-icons" style="font-size:20px;">' + iconNm + '</i></div>';
        var fn = kind === 'aux' ? 'window.recuperarAuxiliar' : 'window.recuperarEquipo';
        var meta = [esc(it.marca || ''), esc(it.modelo || '')].filter(Boolean).join(' ');
        return '<div style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:white;border-radius:8px;border:1px solid #e2e8f0;margin-bottom:5px;">' +
            fotoHtml +
            '<div style="flex:1;min-width:0;">' +
                '<div style="font-weight:700;color:#1e293b;font-size:12px;text-transform:uppercase;line-height:1.2;">' + esc(it.tipo || (kind === 'aux' ? 'AUXILIAR' : 'EQUIPO')) + (meta ? ' · <span style="color:#64748b;font-weight:500;">' + meta + '</span>' : '') + '</div>' +
                '<div style="font-size:11px;color:#64748b;margin-top:2px;">' + esc(idStr) + (it.frente ? ' · <span style="color:#f97316;">' + esc(it.frente) + '</span>' : '') + '</div>' +
                '<div style="font-size:10px;color:#94a3b8;margin-top:2px;">' + esc(it.eliminado_por || it.deleted_by || '') + (it.eliminado_en || it.deleted_at ? ' · ' + esc(it.eliminado_en || it.deleted_at) : '') + '</div>' +
            '</div>' +
            '<button type="button" onclick="' + fn + '(' + it.id + ', \'' + esc(idStr).replace(/\'/g, "\\\'") + '\')" style="padding:5px 8px;font-size:11px;background:#10b981;color:white;border:none;border-radius:6px;display:inline-flex;align-items:center;gap:3px;cursor:pointer;font-weight:700;">' +
                '<i class="material-icons" style="font-size:13px;">restore</i>' +
            '</button>' +
        '</div>';
    }

    function loadList(url, kind, listElId) {
        var list = document.getElementById(listElId);
        fetch(url, { headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' }})
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var items = data.items || [];
                if (!items.length) {
                    list.innerHTML = '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:12px;"><i class="material-icons" style="font-size:24px;display:block;margin:0 auto 6px;">inbox</i>Papelera vacía</div>';
                    return;
                }
                list.innerHTML = items.map(function (it) { return renderRow(it, kind); }).join('');
            })
            .catch(function () {
                list.innerHTML = '<div style="padding:24px;text-align:center;color:#ef4444;font-size:12px;">Error al cargar la papelera.</div>';
            });
    }

    window.abrirPapeleraEquipos = function () {
        buildModal('papeleraEquipos', 'Papelera de Vehículos');
        loadList('{{ route("equipos.papelera") }}', 'eq', 'papeleraEquiposList');
    };
    window.abrirPapeleraAuxiliares = function () {
        buildModal('papeleraAux', 'Papelera de Auxiliares');
        loadList('{{ route("equipos-auxiliares.papelera") }}', 'aux', 'papeleraAuxList');
    };

    function restoreItem(url, label, refreshFn) {
        var doRestore = function () {
            if (window.showPreloader) window.showPreloader();
            fetch(url, { method: 'PATCH', headers: { 'X-CSRF-TOKEN': csrfTok(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }})
                .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
                .then(function (res) {
                    if (window.hidePreloader) window.hidePreloader();
                    if (res.ok && res.body.success) {
                        if (window.showToast) window.showToast(res.body.message || 'Restaurado.', 'success');
                        refreshFn();
                    } else {
                        if (window.showToast) window.showToast((res.body && res.body.message) || 'No se pudo restaurar.', 'error');
                    }
                })
                .catch(function () {
                    if (window.hidePreloader) window.hidePreloader();
                    if (window.showToast) window.showToast('Error de red.', 'error');
                });
        };
        // Modal moderno (showModal global) en lugar del confirm() nativo
        // del navegador — consistente con el resto del sistema.
        if (typeof window.showModal === 'function') {
            window.showModal({
                type: 'info',
                title: 'Restaurar',
                message: '¿Restaurar "' + label + '"?\n\nVolverá al listado activo.',
                confirmText: 'Restaurar',
                cancelText: 'Cancelar',
                onConfirm: doRestore
            });
        } else {
            doRestore();
        }
    }

    window.recuperarEquipo = function (id, label) {
        restoreItem('{{ url("admin/equipos") }}/' + id + '/restore', label, window.abrirPapeleraEquipos);
    };
    window.recuperarAuxiliar = function (id, label) {
        restoreItem('{{ url("admin/equipos-auxiliares") }}/' + id + '/restore', label, window.abrirPapeleraAuxiliares);
    };
})();
</script>
@endcan

@endsection
