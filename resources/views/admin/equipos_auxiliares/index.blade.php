@extends('layouts.estructura_base')
@section('title', 'Equipos Auxiliares')

@section('content')
<style>
    /* Mobile compaction igual a /admin/equipos para el sidebar */
    @media (max-width: 900px) {
        .counter-sidebar [style*="font-size: 13px"] { font-size: 11px !important; }
        .counter-sidebar [style*="font-size: 36px"] { font-size: 26px !important; }
        .counter-sidebar [style*="font-size: 18px"] { font-size: 15px !important; }
        .counter-sidebar [style*="font-size: 16px"] { font-size: 14px !important; }
        .counter-sidebar [style*="font-size: 8px"] { font-size: 7.5px !important; letter-spacing: -0.3px !important; }
        .counter-sidebar h4 { font-size: 11px !important; margin-bottom: 8px !important; }
        .counter-sidebar h4 .material-icons { font-size: 15px !important; }
        .counter-sidebar li span { font-size: 9.5px !important; }
        .counter-sidebar { gap: 10px !important; }
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
    <div>
        <h1 class="page-title" style="margin-bottom: 2px;">
            <span class="page-title-line2" style="color: #000;">Equipos Auxiliares</span>
        </h1>
        <p style="margin: 0; font-size: 12px; color: #64748b; font-weight: 500; line-height: 1.3;">
            Máquinas de soldar, compresores, luminarias, plantas eléctricas, contenedores y otros.
        </p>
    </div>
</div>

<div class="page-layout-grid">

    {{-- Columna izq: Filtros + Tabla --}}
    <div class="admin-card" style="margin: 0; min-height: 70vh; min-width: 0; width: 100%;">

        <form id="auxFiltersForm" onsubmit="event.preventDefault(); cargarAuxiliares();"
              style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:center;">

            {{-- Frente --}}
            @php
                $reqFrente = request('id_frente');
                $frenteActual = ($reqFrente && $reqFrente !== 'all') ? $frentes->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
                $frenteLabel  = $frenteActual ? mb_strtoupper($frenteActual->NOMBRE_FRENTE) : 'Filtrar Frente...';
            @endphp
            <div class="custom-dropdown" id="auxFrenteFilterSelect" data-filter-type="id_frente"
                 data-default-label="Filtrar Frente..." style="flex:1;min-width:180px;max-width:260px;">
                <input type="hidden" name="id_frente" value="{{ $reqFrente ?: '' }}" data-filter-value>
                <div class="dropdown-trigger {{ $reqFrente && $reqFrente !== 'all' ? 'filter-active' : '' }}"
                     style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">place</i></div>
                    <input type="text" id="auxFrenteFilterSearch" name="frente_filter_search" data-filter-search
                           placeholder="Filtrar Frente..." aria-label="Filtrar Frente"
                           style="flex:1;border:none;background:transparent;padding:12px 5px;font-size:13px;outline:none;min-width:0;text-transform:uppercase;"
                           autocomplete="off" value="{{ $frenteActual ? mb_strtoupper($frenteActual->NOMBRE_FRENTE) : '' }}">
                    <span data-filter-label style="display:none;">{{ $frenteLabel }}</span>
                    <i class="material-icons" data-clear-btn
                       style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ $reqFrente && $reqFrente !== 'all' ? 'block' : 'none' }};"
                       onclick="event.stopPropagation(); clearDropdownFilter('auxFrenteFilterSelect'); cargarAuxiliares();">close</i>
                </div>
                <div class="dropdown-content">
                    <div class="dropdown-item {{ !$reqFrente || $reqFrente === 'all' ? 'selected' : '' }}" data-value="all"
                         onclick="selectOption('auxFrenteFilterSelect','all','TODOS LOS FRENTES'); cargarAuxiliares();">TODOS LOS FRENTES</div>
                    @foreach($frentes as $frente)
                        @php $frenteNombreUpper = mb_strtoupper(trim($frente->NOMBRE_FRENTE)); @endphp
                        <div class="dropdown-item {{ (string)$reqFrente === (string)$frente->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $frente->ID_FRENTE }}"
                             onclick="selectOption('auxFrenteFilterSelect','{{ $frente->ID_FRENTE }}','{{ addslashes($frenteNombreUpper) }}'); cargarAuxiliares();">
                            {{ $frenteNombreUpper }}
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Tipo --}}
            @php
                $reqTipo = request('tipo');
                $tipoLabel = ($reqTipo && $reqTipo !== 'all') ? mb_strtoupper($tipos[$reqTipo] ?? 'Filtrar Tipo...') : 'Filtrar Tipo...';
            @endphp
            <div class="custom-dropdown" id="auxTipoFilterSelect" data-filter-type="tipo"
                 data-default-label="Filtrar Tipo..." style="flex:1;min-width:180px;max-width:260px;">
                <input type="hidden" name="tipo" value="{{ $reqTipo ?: '' }}" data-filter-value>
                <div class="dropdown-trigger {{ $reqTipo && $reqTipo !== 'all' ? 'filter-active' : '' }}"
                     style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">category</i></div>
                    <input type="text" id="auxTipoFilterSearch" name="tipo_filter_search" data-filter-search
                           placeholder="Filtrar Tipo..." aria-label="Filtrar Tipo"
                           style="flex:1;border:none;background:transparent;padding:12px 5px;font-size:13px;outline:none;min-width:0;"
                           autocomplete="off" value="{{ ($reqTipo && $reqTipo !== 'all') ? $tipoLabel : '' }}">
                    <span data-filter-label style="display:none;">{{ $tipoLabel }}</span>
                    <i class="material-icons" data-clear-btn
                       style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ $reqTipo && $reqTipo !== 'all' ? 'block' : 'none' }};"
                       onclick="event.stopPropagation(); clearDropdownFilter('auxTipoFilterSelect'); cargarAuxiliares();">close</i>
                </div>
                <div class="dropdown-content">
                    <div class="dropdown-item {{ !$reqTipo || $reqTipo === 'all' ? 'selected' : '' }}" data-value="all"
                         onclick="selectOption('auxTipoFilterSelect','all','TODOS LOS TIPOS'); cargarAuxiliares();">TODOS LOS TIPOS</div>
                    @foreach($tipos as $k => $label)
                        @php $labelUpper = mb_strtoupper($label); @endphp
                        <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}"
                             onclick="selectOption('auxTipoFilterSelect','{{ $k }}','{{ addslashes($labelUpper) }}'); cargarAuxiliares();">
                            {{ $labelUpper }}
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Serial --}}
            <div class="search-wrapper" style="flex:1;min-width:200px;max-width:260px;border:1px solid {{ request('search') ? '#0067b1' : '#cbd5e0' }};border-radius:12px;background:{{ request('search') ? '#e1effa' : '#fbfcfd' }};display:flex;align-items:center;height:45px;overflow:hidden;">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                <input type="text" id="auxSearchInput" name="search" value="{{ request('search') }}" placeholder="Filtrar Serial..."
                       oninput="window._auxDebounce && clearTimeout(window._auxDebounce); window._auxDebounce = setTimeout(cargarAuxiliares, 300);"
                       style="flex:1;border:none;background:transparent;padding:12px 5px;font-size:13px;outline:none;min-width:0;" autocomplete="off">
                <i class="material-icons"
                   style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('search') ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); document.getElementById('auxSearchInput').value=''; cargarAuxiliares();">close</i>
            </div>

            @php
                $advActive = request()->filled('marca') || request()->filled('modelo') || request()->filled('estado') || request()->filled('capacidad');
            @endphp
            <div style="position:relative;flex-shrink:0;">
                <button type="button" id="auxAdvBtn" title="Filtros Avanzados"
                        onclick="const p=document.getElementById('auxAdvPanel'); p.style.display = (p.style.display==='none'||!p.style.display) ? 'block' : 'none'; event.stopPropagation();"
                        class="btn-primary-maquinaria"
                        style="height:45px;width:45px;min-width:45px;padding:0;display:flex;align-items:center;justify-content:center;background:{{ $advActive ? '#fee2e2' : 'white' }};border:1px solid {{ $advActive ? '#ef4444' : '#cbd5e0' }};color:{{ $advActive ? '#ef4444' : '#64748b' }};box-shadow:none;">
                    <i class="material-icons">filter_list</i>
                </button>
                <div id="auxAdvPanel" style="display:none;position:absolute;top:calc(100% + 6px);right:0;width:320px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);padding:14px;z-index:100;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h4 style="margin:0;font-size:14px;font-weight:700;color:#334155;">Filtros Avanzados</h4>
                        <span style="font-size:11px;color:#64748b;text-decoration:underline;cursor:pointer;"
                              onclick="document.getElementById('adv_marca').value=''; document.getElementById('adv_modelo').value=''; document.getElementById('adv_capacidad').value=''; document.getElementById('adv_estado').value=''; cargarAuxiliares();">Limpiar Todo</span>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div>
                            <label for="adv_marca" style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Marca</label>
                            <input type="text" id="adv_marca" name="marca" value="{{ request('marca') }}" placeholder="Ej: Miller" autocomplete="off"
                                   style="width:100%;height:38px;padding:0 10px;border:1px solid #cbd5e0;border-radius:8px;background:white;font-size:13px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label for="adv_modelo" style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Modelo</label>
                            <input type="text" id="adv_modelo" name="modelo" value="{{ request('modelo') }}" placeholder="Ej: Bobcat 225" autocomplete="off"
                                   style="width:100%;height:38px;padding:0 10px;border:1px solid #cbd5e0;border-radius:8px;background:white;font-size:13px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label for="adv_estado" style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Estado</label>
                            <select id="adv_estado" name="estado"
                                    style="width:100%;height:38px;padding:0 10px;border:1px solid #cbd5e0;border-radius:8px;background:white;font-size:13px;">
                                <option value="">Todos</option>
                                @foreach($estados as $k => $label)
                                    <option value="{{ $k }}" {{ request('estado') === $k ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="adv_capacidad" style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Capacidad</label>
                            <input type="text" id="adv_capacidad" name="capacidad" value="{{ request('capacidad') }}" placeholder="Ej: 300A, 20 pies" autocomplete="off"
                                   style="width:100%;height:38px;padding:0 10px;border:1px solid #cbd5e0;border-radius:8px;background:white;font-size:13px;box-sizing:border-box;">
                        </div>
                        <button type="button" onclick="cargarAuxiliares(); document.getElementById('auxAdvPanel').style.display='none';"
                                class="btn-primary-maquinaria" style="width:100%;height:38px;justify-content:center;margin-top:4px;">
                            <i class="material-icons" style="font-size:16px;">search</i> Aplicar
                        </button>
                    </div>
                </div>
            </div>

            {{-- Acciones --}}
            <div style="position:relative;flex-shrink:0;">
                <button type="button" id="auxAccionesBtn" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;border-radius:12px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;cursor:pointer;"
                        onclick="const d=document.getElementById('auxAccionesDropdown'); d.style.display = d.style.display==='none'||!d.style.display ? 'block' : 'none'; event.stopPropagation();">
                    <i class="material-icons" style="font-size:18px;">settings</i>
                    <span>Acciones</span>
                    <i class="material-icons" style="font-size:16px;">expand_more</i>
                </button>
                <div id="auxAccionesDropdown" style="display:none;position:absolute;top:calc(100% + 5px);right:0;min-width:240px;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 20px -5px rgba(15,23,42,0.18);overflow:hidden;z-index:50;">
                    @can('equipos.create')
                    <a href="{{ route('equipos-auxiliares.create') }}"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;"
                       onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                        <div style="background:#fff7ed;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#f59e0b;">add_circle</i></div>
                        <span>Nuevo Equipo Auxiliar</span>
                    </a>
                    @endcan
                    <a href="#" onclick="event.preventDefault(); window.exportAuxiliaresXlsx(); document.getElementById('auxAccionesDropdown').style.display='none';"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;"
                       onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#64748b;">download</i></div>
                        <span>Exportación de Data</span>
                    </a>
                    <a href="#" onclick="event.preventDefault(); if(window.showToast){window.showToast('Catálogo por Modelo en desarrollo.', 'info');} document.getElementById('auxAccionesDropdown').style.display='none';"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;"
                       onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                        <div style="background:#eff6ff;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">menu_book</i></div>
                        <span>Catálogo por Modelo</span>
                    </a>
                </div>
            </div>
        </form>

        <div class="custom-scrollbar-container" style="overflow-x:auto;">
            <table class="admin-table" id="auxTable" style="width:100%;">
                <thead>
                    <tr class="table-row-header">
                        <th class="table-header-custom" style="width: 150px;"></th>
                        <th class="table-header-custom" style="width: 22%;">TIPO</th>
                        <th class="table-header-custom" style="width: 15%;">MARCA / MODELO</th>
                        <th class="table-header-custom" style="width: 25%;">SERIAL</th>
                        <th class="table-header-custom" style="width: 110px;">ESTADO</th>
                        <th class="table-cell-center" style="width: 90px;"></th>
                    </tr>
                </thead>
                <tbody id="auxTableBody">
                    @include('admin.equipos_auxiliares.partials.table_rows')
                </tbody>
            </table>
        </div>

        <div id="auxPagination" style="margin-top:14px;">
            {{ $auxiliares->links('vendor.pagination.custom-sliding') }}
        </div>

    </div>{{-- /admin-card (columna izq) --}}

    {{-- Columna der: Consolidado + Distribucion (sidebar sticky) --}}
    <div class="counter-sidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 8px;">

        {{-- Consolidado de Equipos Auxiliares --}}
        <div style="background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
            <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">construction</i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.8; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <i class="material-icons" style="font-size: 14px;">pie_chart</i>
                    Consolidado
                </div>

                <div style="display: flex; align-items: center; gap: 8px;">
                    <div title="Cargar todos (limpia filtro de estado)" onclick="window.auxFilterByEstado('all')"
                         style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.15); padding: 8px 6px; border-radius: 10px; min-width: 65px; cursor: pointer; transition: transform 0.15s;"
                         onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'">
                        <span id="auxStatsTotal" style="font-size: 36px; font-weight: 800; line-height: 1;">{{ $stats['total'] }}</span>
                        <span style="font-size: 13px; opacity: 0.8; font-weight: 700; margin-top: 2px;">TOTAL</span>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; flex: 1;">
                        <div title="Filtrar solo Operativos" onclick="window.auxFilterByEstado('OPERATIVO')"
                             style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25); cursor: pointer; transition: transform 0.15s;"
                             onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                            <i class="material-icons" style="font-size: 18px; color: #22c55e; margin-bottom: 2px;">check_circle</i>
                            <strong id="auxStatsOperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['operativos'] }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Operativos</span>
                        </div>
                        <div title="Filtrar solo Inoperativos" onclick="window.auxFilterByEstado('INOPERATIVO')"
                             style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); cursor: pointer; transition: transform 0.15s;"
                             onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                            <i class="material-icons" style="font-size: 18px; color: #ef4444; margin-bottom: 2px;">cancel</i>
                            <strong id="auxStatsInoperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['inoperativos'] }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Inoperativos</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Distribucion por tipo --}}
        <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden;">
            <div id="auxDistribucionContainer">
                @include('admin.equipos_auxiliares.partials.distribucion_stats')
            </div>
        </div>
    </div>

</div>{{-- /page-layout-grid --}}

@can('equipos.edit')
{{-- ═══════════════════════════════════════════════════════════
     BARRA FLOTANTE DE SELECCION MASIVA (estilo /admin/equipos)
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxBulkBar" class="selection-floating-bar" style="display:none;">
    <div class="selection-counter">
        <div style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: white;">functions</i>
        </div>
        <span id="auxBulkCount">0</span>
    </div>
    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.2);"></div>
    <div style="display: flex; gap: 10px;">
        <button type="button" onclick="window.auxClearSelection()" class="btn-bulk-clear"
                onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            <span class="desktop-text">Limpiar</span>
        </button>
        <button type="button" onclick="window.openAuxMovilizarModal()" class="btn-bulk-action">
            <i class="material-icons" style="font-size: 18px;">local_shipping</i>
            <span class="desktop-text">Movilizar</span>
        </button>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     MODAL MOVILIZACION MASIVA (pick frente destino)
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxMovilizarModal" class="modal-overlay" style="display:none;"
     onclick="if(event.target===this) window.closeAuxMovilizarModal()">
    <div class="modal-content"
         style="width: 90%; max-width: 480px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: white; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">
        <div style="background: var(--maquinaria-dark-blue); color: white; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin:0; font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px;">
                <i class="material-icons">local_shipping</i>
                Movilización de Auxiliares
            </h2>
            <button type="button" onclick="window.closeAuxMovilizarModal()"
                    style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>
        <div style="padding: 20px;">
            <div id="auxMovilizarSummary" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12px; color:#475569;">
                {{-- poblado via JS --}}
            </div>

            <label for="auxMovilizarFrente" style="display:block; font-weight:700; margin-bottom:6px; color:var(--maquinaria-dark-blue); font-size:13px;">
                <i class="material-icons" style="font-size:14px; vertical-align:middle;">place</i>
                Frente de Destino <span style="color:var(--maquinaria-red);">*</span>
            </label>
            <select id="auxMovilizarFrente" class="form-input-custom" style="width:100%;">
                <option value="">— Seleccione un frente —</option>
                @foreach($frentes as $f)
                    <option value="{{ $f->ID_FRENTE }}">{{ $f->NOMBRE_FRENTE }}</option>
                @endforeach
            </select>

            <div style="display:flex; gap:10px; margin-top:18px; justify-content:flex-end;">
                <button type="button" onclick="window.closeAuxMovilizarModal()" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </button>
                <button type="button" onclick="window.auxSubmitMovilizar()" class="btn-primary-maquinaria">
                    <i class="material-icons" style="font-size:16px;">send</i>
                    Confirmar
                </button>
            </div>
        </div>
    </div>
</div>
@endcan

{{-- ═══════════════════════════════════════════════════════════
     MODAL DETALLES DE EQUIPO AUXILIAR
     Mismo estilo que /admin/equipos (modal-overlay + modal-content +
     header azul oscuro + accordion details). Solo muestra campos
     que NO estan en la tabla.
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxDetailsModal" class="modal-overlay"
     onclick="if(event.target===this) window.closeAuxDetailsModal()">
    <div class="modal-content"
        style="width: 90%; max-width: 420px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #f8fafc; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">

        {{-- HEADER --}}
        <div style="background: var(--maquinaria-dark-blue); color: white;">
            <div style="padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0;">
                    <h2 id="auxDetailsTitle" style="margin: 0; font-size: 17px; font-weight: 700; word-break: break-word; line-height: 1.2;">—</h2>
                    <p id="auxDetailsSubtitle" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px; word-break: break-word;">—</p>
                </div>
                <div style="display: flex; gap: 6px; flex-shrink: 0;">
                    @can('equipos.edit')
                        <button type="button" id="auxDetailsEditBtn" title="Editar datos"
                            style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                            onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                            onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                            <i class="material-icons" style="font-size: 17px;">edit</i>
                        </button>
                    @endcan
                    @can('equipos.assign')
                        <button type="button" id="auxDetailsVincularBtn" title="Vincular a Equipo Host"
                            style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                            onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                            onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                            <i class="material-icons" style="font-size: 17px;">link</i>
                        </button>
                    @endcan
                    <button type="button" onclick="window.closeAuxDetailsModal()"
                        style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        <i class="material-icons" style="font-size: 18px;">close</i>
                    </button>
                </div>
            </div>
        </div>

        {{-- BODY --}}
        <div class="modal-body-scroll" style="padding: 25px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div id="auxDetailsBody" style="display: flex; flex-direction: column; gap: 15px;">
                {{-- Contenido inyectado via JS (renderAuxDetailsModal) --}}
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    // ── Modal de detalles ──
    window.openAuxDetailsModal = function (btn, e) {
        if (e) e.stopPropagation();
        const id = btn.dataset.auxId;
        if (!id) {
            console.warn('openAuxDetailsModal: boton sin data-aux-id');
            return;
        }
        const modal = document.getElementById('auxDetailsModal');
        const body  = document.getElementById('auxDetailsBody');
        if (!modal || !body) {
            console.warn('openAuxDetailsModal: modal/body no encontrado en DOM');
            return;
        }
        body.innerHTML = '<div style="text-align:center; padding:40px; color:#94a3b8;"><i class="material-icons" style="font-size:36px;">hourglass_empty</i><div style="margin-top:8px;">Cargando detalles…</div></div>';
        // .modal-overlay tiene opacity:0 + display:none por default; la clase .active
        // la vuelve visible (display:flex + opacity:1). Setear solo display inline
        // dejaba el modal invisible por opacity:0.
        modal.style.display = '';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        fetch('/admin/equipos-auxiliares/' + id + '/details', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(d => window.renderAuxDetailsModal(d))
        .catch(err => {
            body.innerHTML = '<div style="text-align:center; padding:40px; color:#dc2626;">Error al cargar detalles. ' + (err.message || '') + '</div>';
            console.error('openAuxDetailsModal:', err);
        });
    };

    window.renderAuxDetailsModal = function (d) {
        // Title + subtitle en el header
        const title = document.getElementById('auxDetailsTitle');
        const sub   = document.getElementById('auxDetailsSubtitle');
        if (title) title.textContent = (d.tipo_label || d.tipo || 'Auxiliar');
        if (sub)   sub.textContent   = ((d.marca || '') + ' ' + (d.modelo || '')).trim() || '—';

        // Enlazar edit + vincular en los botones del header
        const editBtn = document.getElementById('auxDetailsEditBtn');
        if (editBtn) editBtn.onclick = () => { window.location.href = d.edit_url; };
        const vincularBtn = document.getElementById('auxDetailsVincularBtn');
        if (vincularBtn) vincularBtn.onclick = () => window.openAuxVincularModal(d);

        // Helper: fila de detalle con label + valor alineados
        const row = (label, value) => `
            <div class="detail-row-basic" style="display:flex; align-items:flex-start; justify-content:space-between; gap:8px; padding:6px 0; border-bottom:1px dashed #f1f5f9;">
                <span style="color:#64748b; font-size:12px; white-space:nowrap;">${label}</span>
                <span style="color:#333; font-size:13px; text-align:right; word-wrap:break-word; line-height:1.3; flex:1; max-width:65%;">${value || '—'}</span>
            </div>`;

        // Helper: seccion accordion (mismo estilo que /admin/equipos)
        const section = (title, icon, content, open = false) => `
            <details ${open ? 'open' : ''} name="aux_details_accordion" style="background:white; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden;">
                <summary style="padding:15px 20px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:10px; background:#f8fafc; list-style:none; cursor:pointer;">
                    <i class="material-icons" style="font-size:20px; color:#64748b;">${icon}</i>
                    <span>${title}</span>
                </summary>
                <div style="padding:12px 18px; border-top:1px solid #e2e8f0; display:flex; flex-direction:column; gap:2px;">
                    ${content}
                </div>
            </details>`;

        // Badge de fecha de vencimiento del certificado
        let vencHtml = '<span style="color:#94a3b8;">Sin fecha</span>';
        if (d.fecha_vencimiento_cert) {
            const venc = new Date(d.fecha_vencimiento_cert);
            const hoy = new Date(); hoy.setHours(0,0,0,0);
            const diff = Math.floor((venc - hoy) / (1000*60*60*24));
            let color = '#16a34a', bg = '#f0fdf4', txt = d.fecha_vencimiento_cert;
            if (diff < 0)       { color = '#dc2626'; bg = '#fef2f2'; txt += ' (VENCIDO)'; }
            else if (diff < 30) { color = '#d97706'; bg = '#fffbeb'; txt += ' (' + diff + ' días)'; }
            vencHtml = `<span style="background:${bg}; color:${color}; padding:3px 10px; border-radius:6px; font-weight:700; font-size:12px;">${txt}</span>`;
        }

        // Links a PDFs
        const pdfLink = (url, label, color) => url
            ? `<a href="${url}" target="_blank" rel="noopener" style="display:inline-flex; align-items:center; gap:4px; color:${color}; font-weight:700; text-decoration:none; font-size:12px;"><i class="material-icons" style="font-size:16px;">picture_as_pdf</i>${label}</a>`
            : '<span style="color:#94a3b8; font-size:12px;">No cargado</span>';

        // Equipo Vinculado
        const host = d.host_id
            ? ((d.host_codigo || '#' + d.host_id)
                + (d.host_placa ? ' · <strong>' + d.host_placa + '</strong>' : '')
                + (d.host_tipo  ? ' <em style="color:#64748b;">('+ d.host_tipo +')</em>' : ''))
            : '<em style="color:#94a3b8;">Sin vincular</em>';

        // IMPORTANTE: solo campos NO presentes en la tabla del index.
        // En la tabla ya se ven: frente, foto, tipo, marca/modelo, serial, capacidad, estado.
        // Aqui mostramos: codigo interno, año, observaciones, equipo vinculado,
        // documentacion (propiedad + certificado + vencimiento) y auditoria.
        const body = document.getElementById('auxDetailsBody');
        body.innerHTML = `
            ${section('Documentación Legal y Soportes', 'description',
                row('Doc. Propiedad',       pdfLink(d.link_doc_propiedad, 'Ver PDF', '#16a34a')) +
                row('Certificado',          pdfLink(d.link_certificado, 'Ver PDF', '#1e40af')) +
                row('Vencimiento Certif.',  vencHtml),
                true
            )}

            ${section('Información Adicional', 'info',
                row('Código Interno',       d.codigo_interno ? '#' + d.codigo_interno : '—') +
                row('Año',                  d.anio) +
                row('Observaciones',        d.observaciones)
            )}

            ${section('Vinculación', 'link',
                row('Equipo Vinculado',     host)
            )}
        `;
    };

    window.closeAuxDetailsModal = function () {
        const modal = document.getElementById('auxDetailsModal');
        if (modal) {
            modal.classList.remove('active');
            modal.style.display = '';
        }
        document.body.style.overflow = '';
    };

    // Cerrar con Escape
    if (!window._auxDetailsEscBound) {
        window._auxDetailsEscBound = true;
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') window.closeAuxDetailsModal();
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  MODAL "VINCULAR A EQUIPO HOST" (patron igual al Anclaje de
    //  /admin/equipos: overlay oscuro, header #1e293b, input search
    //  server-side con debounce, lista de candidatos, submit POST).
    // ═══════════════════════════════════════════════════════════════
    window.openAuxVincularModal = function (aux) {
        // Cerrar el modal de detalles primero
        window.closeAuxDetailsModal();

        const overlay = document.createElement('div');
        overlay.className = 'aux-vincular-overlay';
        overlay.style.cssText = 'position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:2600; display:flex; justify-content:center; align-items:center; backdrop-filter:blur(2px);';

        const content = document.createElement('div');
        content.style.cssText = 'background:white; border-radius:16px; width:90%; max-width:480px; max-height:92vh; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column;';

        content.innerHTML = `
            <div style="background:#1e293b; padding:18px; color:white; display:flex; justify-content:center; align-items:center; position:relative;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="material-icons" style="color:#10b981; font-size:20px;">link</i>
                    <h2 style="margin:0; font-size:16px; font-weight:700;">Vincular a Equipo Host</h2>
                </div>
                <button type="button" id="btnCloseAuxVincular" style="position:absolute; right:15px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                    <i class="material-icons">close</i>
                </button>
            </div>
            <div style="padding:18px 20px; display:flex; flex-direction:column; gap:12px; overflow:hidden;">
                <div style="background:#f1f5f9; padding:10px 12px; border-radius:10px; font-size:12.5px; color:#334155;">
                    <strong>Auxiliar:</strong> ${(aux.tipo_label || aux.tipo || 'Auxiliar')} ${(aux.marca || '')} ${(aux.modelo || '')}
                    ${aux.serial ? `<br><span style="color:#64748b;">Serial: ${aux.serial}</span>` : ''}
                    ${aux.host_id ? `<br><span style="color:#64748b;">Actualmente vinculado a: <strong>${aux.host_codigo || '#' + aux.host_id}</strong></span>` : ''}
                </div>

                <div id="auxVincularInputBox" style="display:flex; align-items:center; border:2px solid #e2e8f0; border-radius:10px; background:white; overflow:hidden; transition:border-color 0.2s;">
                    <i class="material-icons" style="padding:0 10px; color:#94a3b8; font-size:20px; flex-shrink:0;">search</i>
                    <input type="text" id="auxVincularSearch" placeholder="Buscar por serial motor, serial chasis, placa..." autocomplete="off"
                        style="flex:1; border:none; outline:none; padding:11px 6px; font-size:14px; background:transparent;">
                    <i class="material-icons" id="auxVincularClear" style="padding:0 10px; color:#94a3b8; font-size:18px; cursor:pointer; display:none;">close</i>
                </div>

                <div id="auxVincularList" style="overflow-y:auto; max-height:320px; border:1px solid #f1f5f9; border-radius:10px;">
                    <div style="padding:24px; text-align:center; color:#94a3b8; font-size:13px;">
                        <i class="material-icons" style="font-size:28px; display:block; margin: 0 auto 8px; color:#cbd5e0;">search</i>
                        Escribe al menos 2 caracteres para buscar equipos host disponibles.
                    </div>
                </div>

                ${aux.host_id ? `
                <button type="button" id="auxVincularDesanclarBtn" style="width:100%; padding:10px; background:white; color:#dc2626; border:1px solid #fecaca; border-radius:10px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;">
                    <i class="material-icons" style="font-size:18px;">link_off</i> Desvincular del host actual
                </button>
                ` : ''}
            </div>
        `;

        overlay.appendChild(content);
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        const _close = () => { overlay.remove(); document.body.style.overflow = ''; };
        overlay.addEventListener('click', (e) => { if (e.target === overlay) _close(); });
        content.querySelector('#btnCloseAuxVincular').onclick = _close;

        const searchInput = content.querySelector('#auxVincularSearch');
        const clearBtn    = content.querySelector('#auxVincularClear');
        const listBox     = content.querySelector('#auxVincularList');
        const inputBox    = content.querySelector('#auxVincularInputBox');

        let debounceTimer = null;
        searchInput.addEventListener('input', () => {
            const q = searchInput.value.trim();
            clearBtn.style.display = q ? 'block' : 'none';
            inputBox.style.borderColor = q ? '#10b981' : '#e2e8f0';

            if (q.length < 2) {
                listBox.innerHTML = '<div style="padding:24px; text-align:center; color:#94a3b8; font-size:13px;"><i class="material-icons" style="font-size:28px; display:block; margin: 0 auto 8px; color:#cbd5e0;">search</i>Escribe al menos 2 caracteres para buscar equipos host disponibles.</div>';
                return;
            }

            clearTimeout(debounceTimer);
            listBox.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite; font-size:22px;">sync</i></div>';
            debounceTimer = setTimeout(async () => {
                try {
                    const r = await fetch('/admin/equipos-auxiliares/hosts/search?q=' + encodeURIComponent(q), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
                    });
                    const rows = await r.json();
                    if (!rows || !rows.length) {
                        listBox.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8; font-size:13px;">Sin resultados.</div>';
                        return;
                    }
                    listBox.innerHTML = rows.map(h => {
                        const dis = h.disponible ? '' : 'opacity:0.55; pointer-events:none;';
                        const badge = h.disponible
                            ? `<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;">Disponible</span>`
                            : `<span style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;">Lleno (${h.auxiliares_anclados}/2)</span>`;
                        return `
                            <div class="aux-vincular-card" data-host-id="${h.id}" data-host-codigo="${(h.codigo || '').replace(/"/g,'&quot;')}"
                                 style="padding:11px 13px; border-bottom:1px solid #f1f5f9; cursor:pointer; transition:background 0.15s; ${dis}"
                                 onmouseover="if(!${!h.disponible}) this.style.background='#f0fdf4'"
                                 onmouseout="this.style.background='white'">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:4px;">
                                    <strong style="color:#1e293b; font-size:13.5px;">${h.codigo || ('#' + h.id)}</strong>
                                    ${badge}
                                </div>
                                <div style="font-size:12px; color:#475569; line-height:1.3;">
                                    ${h.marca_modelo || '<em style="color:#94a3b8;">Sin marca/modelo</em>'}
                                    ${h.tipo ? ` · <span style="color:#64748b;">${h.tipo}</span>` : ''}
                                </div>
                                <div style="font-size:11px; color:#64748b; margin-top:3px; display:flex; gap:10px; flex-wrap:wrap;">
                                    ${h.placa ? `<span><b>Placa:</b> ${h.placa}</span>` : ''}
                                    ${h.serial_chasis ? `<span><b>Chasis:</b> ${h.serial_chasis}</span>` : ''}
                                </div>
                            </div>
                        `;
                    }).join('');
                    // Bind click en cada candidato
                    listBox.querySelectorAll('.aux-vincular-card').forEach(card => {
                        card.onclick = () => window.auxConfirmarVinculacion(aux.id, card.dataset.hostId, card.dataset.hostCodigo, _close);
                    });
                } catch (e) {
                    listBox.innerHTML = '<div style="padding:20px; text-align:center; color:#ef4444; font-size:12.5px;">Error al buscar equipos.</div>';
                }
            }, 280);
        });

        clearBtn.onclick = () => { searchInput.value = ''; searchInput.dispatchEvent(new Event('input')); searchInput.focus(); };

        // Boton desvincular (si aplica)
        const desBtn = content.querySelector('#auxVincularDesanclarBtn');
        if (desBtn) {
            desBtn.onclick = () => window.auxDesvincular(aux.id, _close);
        }

        searchInput.focus();
    };

    // Confirmar vinculacion: POST al endpoint anchor con ID del host.
    window.auxConfirmarVinculacion = function (auxId, hostId, hostCodigo, closeCb) {
        if (window.showPreloader) window.showPreloader();
        fetch('/admin/equipos-auxiliares/' + auxId + '/anchor', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ id_equipo_host: hostId })
        })
        .then(async r => {
            if (window.hidePreloader) window.hidePreloader();
            if (r.status === 403) {
                const b = await r.json().catch(()=>({}));
                if (window.showToast) window.showToast(b.message || 'No tienes permiso para vincular.', 'error');
                return;
            }
            const body = await r.json();
            if (body.success) {
                if (window.showToast) window.showToast(body.message || `Vinculado a ${hostCodigo}.`, 'success');
                if (typeof closeCb === 'function') closeCb();
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
            } else {
                if (window.showModal) window.showModal({ type: 'error', title: 'Error', message: body.message || 'No se pudo vincular.', confirmText: 'Cerrar', hideCancel: true });
            }
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            console.error('[auxConfirmarVinculacion]', err);
            if (window.showToast) window.showToast('Error de red al vincular.', 'error');
        });
    };

    window.auxDesvincular = function (auxId, closeCb) {
        if (window.showPreloader) window.showPreloader();
        fetch('/admin/equipos-auxiliares/' + auxId + '/unanchor', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(async r => {
            if (window.hidePreloader) window.hidePreloader();
            if (r.status === 403) {
                const b = await r.json().catch(()=>({}));
                if (window.showToast) window.showToast(b.message || 'No tienes permiso para desvincular.', 'error');
                return;
            }
            const body = await r.json();
            if (body.success) {
                if (window.showToast) window.showToast(body.message || 'Desvinculado correctamente.', 'success');
                if (typeof closeCb === 'function') closeCb();
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
            } else {
                if (window.showModal) window.showModal({ type: 'error', title: 'Error', message: body.message || 'No se pudo desvincular.', confirmText: 'Cerrar', hideCancel: true });
            }
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            console.error('[auxDesvincular]', err);
            if (window.showToast) window.showToast('Error de red al desvincular.', 'error');
        });
    };
})();
</script>

<script>
(function () {
    // Carga AJAX — reemplaza tabla + paginacion + stats + distribucion
    window.cargarAuxiliares = function () {
        const form   = document.getElementById('auxFiltersForm');
        const params = new URLSearchParams(new FormData(form));
        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch('{{ route("equipos-auxiliares.index") }}?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('auxTableBody').innerHTML = data.html;
            document.getElementById('auxPagination').innerHTML = data.pagination;
            if (data.stats) {
                const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? 0; };
                set('auxStatsTotal',       data.stats.total);
                set('auxStatsOperativos',  data.stats.operativos);
                set('auxStatsInoperativos',data.stats.inoperativos);
            }
            if (data.distribucion) renderDistribucion(data.distribucion);
            // Restaurar checks seleccionados tras rerender del body
            if (typeof window.auxRestoreSelection === 'function') window.auxRestoreSelection();
        })
        .catch(e => console.error('auxiliares load:', e))
        .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
    };

    function renderDistribucion(rows) {
        const cont = document.getElementById('auxDistribucionContainer');
        if (!cont) return;
        if (!rows || !rows.length) {
            cont.innerHTML = '<h4 style="margin:0 0 12px 0;font-size:12px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;">Distribución</h4><p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">Sin datos para mostrar.</p>';
            return;
        }
        const total = rows.reduce((a,r) => a + parseInt(r.total,10), 0);
        const TIPOS = @json($tipos);
        let html = '<h4 style="margin:0 0 12px 0;font-size:12px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="material-icons" style="font-size:18px;color:#3b82f6;">pie_chart</i>Distribución</h4>';
        html += '<ul style="list-style:none;padding:0;margin:0;max-height:50vh;overflow-y:auto;display:flex;flex-direction:column;gap:4px;">';
        rows.forEach(r => {
            const pct = total > 0 ? (parseInt(r.total,10) / total) * 100 : 0;
            const label = TIPOS[r.TIPO] || r.TIPO;
            html += '<li onclick="window.auxFilterByTipo(\''+r.TIPO+'\')" style="padding:4px 6px;border-bottom:1px dashed #f1f5f9;cursor:pointer;border-radius:6px;transition:background 0.15s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'transparent\'"><div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;"><span style="color:#334155;font-size:12.5px;font-weight:600;line-height:1.25;flex:1;">'+label+'</span><span style="font-weight:700;color:#1e293b;font-size:12.5px;background:#f1f5f9;padding:2px 8px;border-radius:4px;">'+r.total+'</span></div><div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;"><div style="width:'+pct+'%;height:100%;background:linear-gradient(90deg,#3b82f6 0%,#2563eb 100%);"></div></div></li>';
        });
        html += '</ul>';
        cont.innerHTML = html;
    }

    // Helpers para filtrar desde Consolidado + Distribucion (clicks).
    window.auxFilterByTipo = function (tipo) {
        selectOption('auxTipoFilterSelect', tipo, (@json($tipos))[tipo] || tipo);
        cargarAuxiliares();
    };
    window.auxFilterByEstado = function (estado) {
        const input = document.getElementById('adv_estado');
        if (input) input.value = (estado === 'all') ? '' : estado;
        cargarAuxiliares();
    };

    // ═══════════════════════════════════════════════════════════
    // SELECCION MASIVA + MOVILIZACION (patron /admin/equipos — row click)
    // Click en cualquier parte de la fila alterna selecto/deselecto.
    // Guarda { id -> { id, codigo, frente } } en window._auxSelectedMap.
    // ═══════════════════════════════════════════════════════════
    window._auxSelectedMap = window._auxSelectedMap || {};

    window.auxToggleRow = function (tr) {
        if (!tr || !tr.dataset.auxId) return;
        const id = tr.dataset.auxId;
        if (id in window._auxSelectedMap) {
            delete window._auxSelectedMap[id];
            tr.classList.remove('selected-row-maquinaria');
        } else {
            window._auxSelectedMap[id] = {
                id: id,
                codigo: tr.dataset.codigo || ('#' + id),
                frente: tr.dataset.frente || ''
            };
            tr.classList.add('selected-row-maquinaria');
        }
        window.auxRefreshBulkBar();
    };

    window.auxRefreshBulkBar = function () {
        const bar = document.getElementById('auxBulkBar');
        const count = document.getElementById('auxBulkCount');
        if (!bar) return;
        const n = Object.keys(window._auxSelectedMap).length;
        if (count) count.textContent = String(n);
        bar.style.display = n > 0 ? 'flex' : 'none';
    };

    window.auxClearSelection = function () {
        window._auxSelectedMap = {};
        document.querySelectorAll('#auxTableBody tr.selected-row-maquinaria').forEach(tr => {
            tr.classList.remove('selected-row-maquinaria');
        });
        window.auxRefreshBulkBar();
    };

    // Restaurar highlight tras AJAX refresh de la tabla
    window.auxRestoreSelection = function () {
        document.querySelectorAll('#auxTableBody tr[data-aux-id]').forEach(tr => {
            if (tr.dataset.auxId in window._auxSelectedMap) {
                tr.classList.add('selected-row-maquinaria');
            }
        });
        window.auxRefreshBulkBar();
    };

    // Delegacion de click en filas (solo con can:equipos.edit via clase)
    if (!window._auxRowClickBound) {
        window._auxRowClickBound = true;
        document.addEventListener('click', function (e) {
            const tr = e.target.closest('#auxTableBody tr.aux-row-clickable');
            if (!tr) return;
            // Ignorar clicks en elementos interactivos dentro de la fila
            if (e.target.closest('button, a, input, .aux-status-trigger, .material-icons, .btn-details-mini')) return;
            window.auxToggleRow(tr);
        });
    }

    window.openAuxMovilizarModal = function () {
        const ids = Object.keys(window._auxSelectedMap);
        if (ids.length === 0) return;
        const modal = document.getElementById('auxMovilizarModal');
        const sum   = document.getElementById('auxMovilizarSummary');
        if (!modal) return;
        const items = ids.map(k => window._auxSelectedMap[k].codigo);
        if (sum) sum.innerHTML = '<strong>' + ids.length + '</strong> equipo(s) a movilizar: <span style="color:#334155;">' + items.slice(0,6).join(', ') + (items.length > 6 ? ', +' + (items.length - 6) + ' más' : '') + '</span>';
        document.getElementById('auxMovilizarFrente').value = '';
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.closeAuxMovilizarModal = function () {
        const modal = document.getElementById('auxMovilizarModal');
        if (modal) modal.style.display = 'none';
        document.body.style.overflow = '';
    };

    window.auxSubmitMovilizar = function () {
        const frenteId = document.getElementById('auxMovilizarFrente').value;
        if (!frenteId) {
            if (window.showToast) window.showToast('Selecciona un frente destino.', 'warning');
            return;
        }
        const ids = Object.keys(window._auxSelectedMap).map(x => parseInt(x, 10));
        if (!ids.length) { window.closeAuxMovilizarModal(); return; }

        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch('{{ route("equipos-auxiliares.bulkMove") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? ''
            },
            body: JSON.stringify({ ids: ids, id_frente: parseInt(frenteId, 10) })
        })
        .then(r => r.json().then(body => ({ status: r.status, body })))
        .then(({ status, body }) => {
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message || 'Movilización exitosa.', 'success');
                window.auxClearSelection();
                window.closeAuxMovilizarModal();
                cargarAuxiliares();
            } else {
                if (window.showModal) {
                    window.showModal({ type:'error', title:'Error', message: body.message || 'No se pudo movilizar.', confirmText:'Entendido', hideCancel:true });
                } else if (window.showToast) {
                    window.showToast(body.message || 'Error al movilizar.', 'error');
                }
            }
        })
        .catch(err => {
            console.error('auxSubmitMovilizar:', err);
            if (window.showToast) window.showToast('Error de red.', 'error');
        })
        .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
    };

    if (!window.auxPaginationAttached) {
        window.auxPaginationAttached = true;
        document.addEventListener('click', (e) => {
            const link = e.target.closest('#auxPagination a');
            if (!link) return;
            e.preventDefault();
            const u = new URL(link.href);
            const form = document.getElementById('auxFiltersForm');
            if (form) {
                const p = new URLSearchParams(new FormData(form));
                p.forEach((v, k) => u.searchParams.set(k, v));
            }
            if (typeof window.showPreloader === 'function') window.showPreloader();
            fetch(u.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }})
                .then(r => r.json())
                .then(data => {
                    document.getElementById('auxTableBody').innerHTML = data.html;
                    document.getElementById('auxPagination').innerHTML = data.pagination;
                })
                .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
        });
    }

    if (!window.auxAccionesOutsideBound) {
        window.auxAccionesOutsideBound = true;
        document.addEventListener('click', (e) => {
            // Cerrar dropdown Acciones
            const d = document.getElementById('auxAccionesDropdown');
            const btn = document.getElementById('auxAccionesBtn');
            if (d && btn && !d.contains(e.target) && !btn.contains(e.target)) d.style.display = 'none';
            // Cerrar panel Filtros Avanzados
            const adv = document.getElementById('auxAdvPanel');
            const advBtn = document.getElementById('auxAdvBtn');
            if (adv && advBtn && !adv.contains(e.target) && !advBtn.contains(e.target)) adv.style.display = 'none';
            // Cerrar menu de estado de fila
            const sm = document.getElementById('auxStatusMenu');
            if (sm && !sm.contains(e.target) && !e.target.closest('.aux-status-trigger')) sm.style.display = 'none';
        });
    }

    // ── Menu de cambio de estado (estilo /admin/equipos openSharedStatusMenu) ──
    const AUX_STATUS_CFG = {
        OPERATIVO:      { color: '#16a34a', bg: '#f0fdf4', icon: 'check_circle', label: 'Operativo' },
        INOPERATIVO:    { color: '#dc2626', bg: '#fef2f2', icon: 'cancel',       label: 'Inoperativo' },
        EN_ALMACEN:     { color: '#1e40af', bg: '#eff6ff', icon: 'inventory_2',  label: 'En Almacén' },
        DESINCORPORADO: { color: '#475569', bg: '#f1f5f9', icon: 'block',        label: 'Desincorp.' },
    };

    function getOrCreateAuxStatusMenu() {
        let menu = document.getElementById('auxStatusMenu');
        if (menu) return menu;
        menu = document.createElement('div');
        menu.id = 'auxStatusMenu';
        menu.style.cssText = 'position:absolute; display:none; min-width:200px; background:white; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 25px -5px rgba(15,23,42,0.18); overflow:hidden; z-index:9999;';
        document.body.appendChild(menu);
        return menu;
    }

    let _auxStatusTrigger = null;

    window.openAuxStatusMenu = function (trigger) {
        const menu = getOrCreateAuxStatusMenu();
        if (_auxStatusTrigger === trigger && menu.style.display !== 'none') {
            menu.style.display = 'none'; _auxStatusTrigger = null; return;
        }
        _auxStatusTrigger = trigger;
        const currentStatus = trigger.dataset.status;
        menu.innerHTML = '';
        Object.entries(AUX_STATUS_CFG).forEach(([key, cfg]) => {
            const item = document.createElement('div');
            item.style.cssText = 'display:flex; align-items:center; gap:8px; padding:10px 12px; cursor:pointer; border-bottom:1px solid #f8fafc;';
            item.innerHTML = `
                <div style="background:${cfg.bg}; padding:4px; border-radius:4px; display:flex;">
                    <i class="material-icons" style="font-size:16px; color:${cfg.color};">${cfg.icon}</i>
                </div>
                <span style="font-size:12px; font-weight:600; color:#334155;">${cfg.label}</span>
                ${key === currentStatus
                    ? '<i class="material-icons" style="font-size:14px; color:'+cfg.color+'; margin-left:auto;">check</i>'
                    : ''}
            `;
            item.addEventListener('mouseover', () => item.style.background = '#f8fafc');
            item.addEventListener('mouseout',  () => item.style.background = 'white');
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.style.display = 'none';
                const t = _auxStatusTrigger;
                _auxStatusTrigger = null;
                window.auxChangeStatus(t, key);
            });
            menu.appendChild(item);
        });
        const r = trigger.getBoundingClientRect();
        menu.style.top  = (window.scrollY + r.bottom + 4) + 'px';
        menu.style.left = (window.scrollX + r.left) + 'px';
        menu.style.display = 'block';
    };

    window.auxChangeStatus = function (trigger, newStatus) {
        if (!trigger) return;
        const oldStatus = trigger.dataset.status;
        if (oldStatus === newStatus) return;
        const url = trigger.dataset.statusUrl;
        const cfg = AUX_STATUS_CFG[newStatus] || AUX_STATUS_CFG['DESINCORPORADO'];

        const iconEl  = trigger.querySelector('.material-icons:first-child') || trigger.querySelector('div > .material-icons');
        const labelEl = trigger.querySelector('.aux-status-label');

        // Optimistic UI (mismo estilo que equipos)
        if (iconEl)  { iconEl.textContent = cfg.icon; iconEl.style.color = cfg.color; }
        if (labelEl)   labelEl.textContent = cfg.label;
        const innerBlock = trigger.querySelector('div');
        if (innerBlock) innerBlock.style.color = cfg.color;
        trigger.dataset.status = newStatus;

        fetch(url, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? ''
            },
            body: JSON.stringify({ ESTADO_OPERATIVO: newStatus })
        })
        .then(r => r.json().then(body => ({ status: r.status, body })))
        .then(({ status, body }) => {
            if (status === 200) {
                if (window.showToast) window.showToast('Estado actualizado.', 'success');
                cargarAuxiliares();
            } else {
                throw new Error(body.message || 'Error');
            }
        })
        .catch(err => {
            const oldCfg = AUX_STATUS_CFG[oldStatus] || AUX_STATUS_CFG['DESINCORPORADO'];
            if (iconEl)  { iconEl.textContent = oldCfg.icon; iconEl.style.color = oldCfg.color; }
            if (labelEl)   labelEl.textContent = oldCfg.label;
            if (innerBlock) innerBlock.style.color = oldCfg.color;
            trigger.dataset.status = oldStatus;
            if (window.showToast) window.showToast('No se pudo actualizar el estado.', 'error');
            console.error('auxChangeStatus:', err);
        });
    };

    // Exportar XLSX respetando filtros activos.
    // Usamos fetch() + Blob en vez de <a> click para que el navegador NO muestre
    // el spinner de pestana: solo el preloader propio de la app.
    window.exportAuxiliaresXlsx = function () {
        const form = document.getElementById('auxFiltersForm');
        const params = form ? new URLSearchParams(new FormData(form)) : new URLSearchParams();
        const url = '{{ route("equipos-auxiliares.export") }}' + (params.toString() ? '?' + params.toString() : '');

        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const cd = r.headers.get('Content-Disposition') || '';
                const m = cd.match(/filename="?([^";]+)"?/i);
                const filename = m ? m[1] : 'Listado_Equipos_Auxiliares.xlsx';
                return r.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                const objUrl = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = objUrl;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                setTimeout(() => URL.revokeObjectURL(objUrl), 1000);
            })
            .catch(err => {
                console.error('export auxiliares:', err);
                if (window.showToast) window.showToast('No se pudo exportar. Intenta nuevamente.', 'error');
            })
            .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
    };
})();
</script>
@endsection
