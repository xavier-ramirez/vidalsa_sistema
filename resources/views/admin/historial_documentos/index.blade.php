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
            <div class="filter-toolbar-container" style="margin-bottom: 5px;">
                <!-- Search Correo -->
                <div class="filter-item aligned-filter responsive-filter-item">
                    <form style="width: 100%;" onsubmit="event.preventDefault(); window.loadHistorialDocumentos();">
                        <div class="search-wrapper" style="width: 100%; border-color: #cbd5e0; background: #fbfcfd; height: 45px;">
                            <i class="material-icons search-icon">person</i>
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
                            <i class="material-icons search-icon">agriculture</i>
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
                                <i class="material-icons" style="font-size: 18px;">description</i>
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
                                <div class="dropdown-item" data-value="doc. adicional" onclick="selectOption('tipoDocFilterSelect', 'doc. adicional', 'Doc. Adicional'); window.loadHistorialDocumentos();">
                                    Doc. Adicional
                                </div>
                                <div class="dropdown-item" data-value="registro de vehículo" onclick="selectOption('tipoDocFilterSelect', 'registro de vehículo', 'Registro de Vehículo'); window.loadHistorialDocumentos();">
                                    Registro de Vehículo
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

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
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #ef4444; font-size: 20px;">gpp_bad</i>
                    <h3 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b; text-transform: uppercase;">IPs Bloqueadas</h3>
                </div>
                <span class="badge" style="background: #fee2e2; color: #ef4444; font-size: 11px; padding: 2px 6px; border-radius: 10px; font-weight: 700;" id="blocked-ip-count">{{ $blockedIps->count() }}</span>
            </div>
            
            <div style="display: flex; flex-direction: column; gap: 8px; max-height: 300px; overflow-y: auto; padding-right: 4px;" class="custom-scrollbar-container">
                @foreach($blockedIps as $ip)
                <div id="blocked-ip-{{ $ip->ID_BLOQUEO }}" style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; padding: 8px 10px; border-radius: 6px; border: 1px solid #f1f5f9; transition: all 0.2s;">
                    <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 13px; font-weight: 600; color: #334155; font-family: monospace;">{{ $ip->DIRECCION_IP }}</span>
                        <span style="font-size: 11px; color: #64748b;" title="Último intento: {{ $ip->ULTIMO_INTENTO->format('d/m/Y H:i') }}">Fallos: {{ $ip->CANTIDAD_INTENTOS }}</span>
                    </div>
                    @can('super.admin')
                    <button 
                            class="btn-unlock-ip"
                            data-ip-id="{{ $ip->ID_BLOQUEO }}"
                            data-ip-address="{{ $ip->DIRECCION_IP }}"
                            style="background: transparent; border: none; padding: 4px; color: #ef4444; cursor: pointer; border-radius: 4px; transition: background 0.2s; display: flex; align-items: center; justify-content: center; pointer-events: all; position: relative; z-index: 30;" 
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



@endsection
