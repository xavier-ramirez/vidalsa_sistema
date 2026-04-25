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
            <div class="filter-toolbar-container" style="margin-bottom: 5px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                <!-- Search Correo: flex 1 1 250px → crece para ocupar espacio disponible. -->
                <div class="filter-item aligned-filter" style="flex: 1 1 250px; min-width: 220px;">
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
                <div class="filter-item aligned-filter" style="flex: 1 1 250px; min-width: 220px;">
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
                <div class="filter-item aligned-filter" style="flex: 1 1 250px; min-width: 220px;">
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

                @php
                    $hasAdvHd = request()->filled('fecha_desde') || request()->filled('fecha_hasta');
                @endphp

                <!-- Boton Filtros Avanzados (panel con rango de fechas) -->
                <div class="filter-item aligned-filter" style="flex: 0 0 auto; position: relative;">
                    <button type="button" id="btnHdAdvancedFilter"
                        onclick="const p=document.getElementById('hdAdvancedFilterPanel'); p.style.display = (p.style.display==='none'||!p.style.display) ? 'block' : 'none'; event.stopPropagation();"
                        title="Filtros Avanzados"
                        style="height: 45px; width: 45px; padding: 0; border-radius: 12px; background: {{ $hasAdvHd ? '#fee2e2' : 'white' }}; border: 1px solid {{ $hasAdvHd ? '#ef4444' : '#cbd5e0' }}; color: {{ $hasAdvHd ? '#ef4444' : '#64748b' }}; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;">
                        <i class="material-icons">filter_list</i>
                    </button>
                    <div id="hdAdvancedFilterPanel" style="display: none; position: absolute; top: 100%; right: 0; width: 280px; max-width: calc(100vw - 24px); background: #e2e8f0; border: 1px solid #cbd5e1; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15); margin-top: 8px; padding: 14px; z-index: 100;">
                        <h4 style="margin: 0 0 12px 0; font-size: 13px; font-weight: 700; color: #334155; display: flex; justify-content: space-between; align-items: center;">
                            Filtros Avanzados
                            <span style="font-size: 11px; color: #64748b; font-weight: 400; text-decoration: underline; cursor: pointer;"
                                  onclick="document.getElementById('hdFechaDesde').value=''; document.getElementById('hdFechaHasta').value=''; window.loadHistorialDocumentos();">Limpiar</span>
                        </h4>

                        <div style="margin-bottom: 10px;">
                            <span style="display: block; font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Fecha desde</span>
                            <input type="date" id="hdFechaDesde" name="fecha_desde" value="{{ request('fecha_desde') }}"
                                onchange="window.loadHistorialDocumentos()"
                                style="width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid {{ request('fecha_desde') ? '#0067b1' : '#cbd5e0' }}; border-radius: 8px; font-size: 13px; color: #334155; background: {{ request('fecha_desde') ? '#e1effa' : 'white' }};">
                        </div>

                        <div>
                            <span style="display: block; font-size: 11px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Fecha hasta</span>
                            <input type="date" id="hdFechaHasta" name="fecha_hasta" value="{{ request('fecha_hasta') }}"
                                onchange="window.loadHistorialDocumentos()"
                                style="width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid {{ request('fecha_hasta') ? '#0067b1' : '#cbd5e0' }}; border-radius: 8px; font-size: 13px; color: #334155; background: {{ request('fecha_hasta') ? '#e1effa' : 'white' }};">
                        </div>
                    </div>
                </div>

                {{-- Boton IPs Bloqueadas removido a pedido del usuario:
                     causaba TypeError cuando $blockedIps estaba vacio (la
                     funcion JS solo se renderizaba con count > 0, pero el
                     boton aparecia siempre que hubiera permiso super.admin).
                     La lista de IPs sigue visible en el sidebar derecho. --}}

                @can('super.admin')
                <!-- Boton Papelera de Equipos -->
                <div class="filter-item aligned-filter" style="flex: 0 0 auto;">
                    <button type="button" id="btnVerPapeleraEquipos"
                        onclick="window.abrirPapeleraEquipos()"
                        title="Ver Papelera de Equipos"
                        style="height: 45px; padding: 0 14px; border-radius: 12px; background: white; border: 1px solid #fcd34d; color: #d97706; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 700;"
                        onmouseover="this.style.background='#fef3c7'"
                        onmouseout="this.style.background='white'">
                        <i class="material-icons" style="font-size: 18px;">history</i>
                        <span>Papelera</span>
                    </button>
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

        {{-- ─── Usuarios Activos (sesiones últimos 30 min) ─── --}}
        @if(isset($activeUsers) && auth()->check() && auth()->user()->can('super.admin'))
        <div style="background: white; border-radius: 12px; padding: 13px 15px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
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

        {{-- IPs Bloqueadas Card del sidebar: SIEMPRE visible para super.admin
             (incluso con 0 IPs muestra empty state). Las IPs solo se cuentan
             como bloqueadas con >= 10 intentos fallidos (filtro en controller). --}}
        @can('super.admin')
        <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: relative; z-index: 20; margin-top: 10px;" id="blocked-ips-container">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #ef4444; font-size: 20px;">gpp_bad</i>
                    <h3 style="margin: 0; font-size: 13px; font-weight: 700; color: #1e293b; text-transform: uppercase;">IPs Bloqueadas</h3>
                </div>
                <span class="badge" style="background: {{ ($blockedIps ?? collect())->count() > 0 ? '#fee2e2' : '#f1f5f9' }}; color: {{ ($blockedIps ?? collect())->count() > 0 ? '#ef4444' : '#64748b' }}; font-size: 11px; padding: 2px 6px; border-radius: 10px; font-weight: 700;" id="blocked-ip-count">{{ ($blockedIps ?? collect())->count() }}</span>
            </div>

            @if(isset($blockedIps) && $blockedIps->count() > 0)
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
                    </div>
                    @endforeach
                </div>
            @else
                <p style="margin: 4px 0 0; font-size: 11px; color: #94a3b8; text-align: center; padding: 4px 0;">Sin IPs bloqueadas (umbral: 10 intentos fallidos).</p>
            @endif
        </div>
        @endcan
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

{{-- Modal "IPs Bloqueadas" eliminado: se reemplazo por la card del sidebar
     (siempre visible para super.admin) ya que el boton causaba TypeError
     cuando $blockedIps->count() era 0 — la funcion JS se renderizaba bajo
     la condicion count > 0 pero el boton aparecia siempre. --}}

@can('super.admin')
{{-- ═══════════════════════════════════════════════════════════
     PAPELERA DE EQUIPOS — soft-deleted con auditoria de quien borro.
     Movida a /admin/historial-documentos donde tiene sentido junto al
     resto del audit trail. Endpoints: equipos.papelera + equipos.restore.
     ═══════════════════════════════════════════════════════════ --}}
<script>
(function () {
    var csrfTok = function () {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };
    var esc = function (s) {
        return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    };

    window.abrirPapeleraEquipos = function () {
        var old = document.getElementById('papeleraEquiposOverlay');
        if (old) old.remove();

        var overlay = document.createElement('div');
        overlay.id = 'papeleraEquiposOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2500;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(2px);';
        overlay.innerHTML = '<div style="background:white;border-radius:16px;width:90%;max-width:680px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">' +
            '<div style="background:#1e293b;padding:18px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">' +
                '<div style="display:flex;align-items:center;gap:10px;">' +
                    '<i class="material-icons" style="color:#f59e0b;font-size:20px;">history</i>' +
                    '<h2 style="margin:0;font-size:16px;font-weight:700;">Papelera de Equipos</h2>' +
                '</div>' +
                '<button type="button" id="btnClosePapelera" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity=1" onmouseout="this.style.opacity=0.7"><i class="material-icons">close</i></button>' +
            '</div>' +
            '<div style="padding:18px;overflow:hidden;flex:1;display:flex;flex-direction:column;min-height:0;">' +
                '<p style="margin:0 0 12px;font-size:12px;color:#64748b;text-align:center;">Equipos eliminados. Click en "Recuperar" para reactivarlos.</p>' +
                '<div id="papeleraList" style="overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:8px;flex:1;min-height:200px;">' +
                    '<div style="padding:30px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:24px;">sync</i></div>' +
                '</div>' +
            '</div>' +
        '</div>';
        document.body.appendChild(overlay);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) overlay.remove(); });
        document.getElementById('btnClosePapelera').onclick = function () { overlay.remove(); };

        cargarPapelera();
    };

    function cargarPapelera() {
        var list = document.getElementById('papeleraList');
        if (!list) return;
        fetch('{{ route("equipos.papelera") }}', {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.items || data.items.length === 0) {
                list.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="font-size:32px;display:block;margin:0 auto 10px;">inbox</i>La papelera está vacía</div>';
                return;
            }
            list.innerHTML = data.items.map(function (it) {
                var idStr = it.placa || it.serial_chasis || it.codigo || ('#' + it.id);
                var idLabel = it.placa ? 'Placa' : (it.serial_chasis ? 'Chasis' : (it.codigo ? 'Código' : 'ID'));
                var fotoHtml = it.foto
                    ? '<img src="' + esc(it.foto) + '" alt="" style="width:48px;height:48px;border-radius:8px;object-fit:contain;background:white;border:1px solid #e2e8f0;flex-shrink:0;" onerror="this.outerHTML=\'<div style=&quot;width:48px;height:48px;border-radius:8px;background:#eff6ff;color:#1e40af;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;&quot;><i class=&quot;material-icons&quot; style=&quot;font-size:22px;&quot;>directions_car</i></div>\'">'
                    : '<div style="width:48px;height:48px;border-radius:8px;background:#eff6ff;color:#1e40af;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid #e2e8f0;"><i class="material-icons" style="font-size:22px;">directions_car</i></div>';
                return '<div style="display:flex;align-items:center;gap:12px;padding:12px;background:white;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:6px;">' +
                    fotoHtml +
                    '<div style="flex:1;min-width:0;">' +
                        '<div style="font-weight:700;color:#1e293b;font-size:13px;text-transform:uppercase;line-height:1.2;">' + esc(it.tipo || 'EQUIPO') + '</div>' +
                        '<div style="font-size:12px;color:#475569;margin-top:2px;">' + esc(it.marca || '') + (it.marca && it.modelo ? ' · ' : '') + esc(it.modelo || '') + '</div>' +
                        '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;font-size:11px;color:#64748b;">' +
                            '<span><strong style="color:#334155;">' + idLabel + ':</strong> ' + esc(idStr) + '</span>' +
                            (it.frente ? '<span style="color:#f97316;"><i class="material-icons" style="font-size:11px;vertical-align:middle;">place</i> ' + esc(it.frente) + '</span>' : '') +
                        '</div>' +
                        '<div style="font-size:10.5px;color:#94a3b8;margin-top:4px;">' +
                            '<i class="material-icons" style="font-size:11px;vertical-align:middle;">person</i> ' + esc(it.eliminado_por) +
                            ' · <i class="material-icons" style="font-size:11px;vertical-align:middle;">schedule</i> ' + esc(it.eliminado_en) +
                        '</div>' +
                    '</div>' +
                    '<button type="button" onclick="window.recuperarEquipo(' + it.id + ', \'' + esc(idStr).replace(/'/g, "\\'") + '\')" class="btn-primary-maquinaria" style="padding:6px 12px;font-size:12px;height:auto;background:#10b981;border-color:#10b981;display:inline-flex;align-items:center;gap:4px;flex-shrink:0;">' +
                        '<i class="material-icons" style="font-size:14px;">restore</i>Recuperar' +
                    '</button>' +
                '</div>';
            }).join('');
        })
        .catch(function () {
            list.innerHTML = '<div style="padding:30px;text-align:center;color:#ef4444;font-size:13px;">Error al cargar la papelera.</div>';
        });
    }

    window.recuperarEquipo = function (id, label) {
        var proceed = function () {
            if (window.showPreloader) window.showPreloader();
            fetch('{{ url("admin/equipos") }}/' + id + '/restore', {
                method: 'PATCH',
                headers: { 'X-CSRF-TOKEN': csrfTok(), 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, body: d }; }); })
            .then(function (res) {
                if (window.hidePreloader) window.hidePreloader();
                if (res.ok && res.body.success) {
                    if (window.showToast) window.showToast(res.body.message || 'Equipo recuperado.', 'success');
                    cargarPapelera();
                } else {
                    if (window.showToast) window.showToast((res.body && res.body.message) || 'No se pudo recuperar.', 'error');
                }
            })
            .catch(function () {
                if (window.hidePreloader) window.hidePreloader();
                if (window.showToast) window.showToast('Error de red al recuperar.', 'error');
            });
        };
        if (typeof window.showModal === 'function') {
            window.showModal({
                type: 'info',
                title: 'Recuperar Equipo',
                message: '¿Restaurar el equipo "' + label + '"?\n\nVolverá a aparecer en el listado activo.',
                confirmText: 'Sí, recuperar',
                cancelText: 'Cancelar',
                onConfirm: proceed
            });
        } else if (confirm('¿Recuperar el equipo "' + label + '"?')) {
            proceed();
        }
    };
})();
</script>
@endcan

@endsection
