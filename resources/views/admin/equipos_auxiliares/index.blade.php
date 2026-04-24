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

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Equipos Auxiliares</span>
    </h1>
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
                $frenteLabel  = $frenteActual ? $frenteActual->NOMBRE_FRENTE : 'Filtrar Frente...';
            @endphp
            <div class="custom-dropdown" id="auxFrenteFilterSelect" data-filter-type="id_frente"
                 data-default-label="Filtrar Frente..." style="flex:1;min-width:180px;max-width:260px;">
                <input type="hidden" name="id_frente" value="{{ $reqFrente ?: '' }}" data-filter-value>
                <div class="dropdown-trigger {{ $reqFrente && $reqFrente !== 'all' ? 'filter-active' : '' }}"
                     style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">place</i></div>
                    <input type="text" data-filter-search placeholder="Filtrar Frente..."
                           style="flex:1;border:none;background:transparent;padding:12px 5px;font-size:13px;outline:none;min-width:0;"
                           autocomplete="off" value="{{ $frenteActual ? $frenteActual->NOMBRE_FRENTE : '' }}">
                    <span data-filter-label style="display:none;">{{ $frenteLabel }}</span>
                    <i class="material-icons" data-clear-btn
                       style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ $reqFrente && $reqFrente !== 'all' ? 'block' : 'none' }};"
                       onclick="event.stopPropagation(); clearDropdownFilter('auxFrenteFilterSelect'); cargarAuxiliares();">close</i>
                </div>
                <div class="dropdown-content">
                    <div class="dropdown-item {{ !$reqFrente || $reqFrente === 'all' ? 'selected' : '' }}" data-value="all"
                         onclick="selectOption('auxFrenteFilterSelect','all','TODOS LOS FRENTES'); cargarAuxiliares();">TODOS LOS FRENTES</div>
                    @foreach($frentes as $frente)
                        <div class="dropdown-item {{ (string)$reqFrente === (string)$frente->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $frente->ID_FRENTE }}"
                             onclick="selectOption('auxFrenteFilterSelect','{{ $frente->ID_FRENTE }}','{{ addslashes(trim($frente->NOMBRE_FRENTE)) }}'); cargarAuxiliares();">
                            {{ $frente->NOMBRE_FRENTE }}
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Tipo --}}
            @php
                $reqTipo = request('tipo');
                $tipoLabel = ($reqTipo && $reqTipo !== 'all') ? ($tipos[$reqTipo] ?? 'Filtrar Tipo...') : 'Filtrar Tipo...';
            @endphp
            <div class="custom-dropdown" id="auxTipoFilterSelect" data-filter-type="tipo"
                 data-default-label="Filtrar Tipo..." style="flex:1;min-width:180px;max-width:260px;">
                <input type="hidden" name="tipo" value="{{ $reqTipo ?: '' }}" data-filter-value>
                <div class="dropdown-trigger {{ $reqTipo && $reqTipo !== 'all' ? 'filter-active' : '' }}"
                     style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">category</i></div>
                    <input type="text" data-filter-search placeholder="Filtrar Tipo..."
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
                        <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}"
                             onclick="selectOption('auxTipoFilterSelect','{{ $k }}','{{ addslashes($label) }}'); cargarAuxiliares();">
                            {{ $label }}
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
                            <label style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Marca</label>
                            <input type="text" id="adv_marca" name="marca" value="{{ request('marca') }}" placeholder="Ej: Miller" autocomplete="off"
                                   style="width:100%;height:38px;padding:0 10px;border:1px solid #cbd5e0;border-radius:8px;background:white;font-size:13px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Modelo</label>
                            <input type="text" id="adv_modelo" name="modelo" value="{{ request('modelo') }}" placeholder="Ej: Bobcat 225" autocomplete="off"
                                   style="width:100%;height:38px;padding:0 10px;border:1px solid #cbd5e0;border-radius:8px;background:white;font-size:13px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Estado</label>
                            <select id="adv_estado" name="estado"
                                    style="width:100%;height:38px;padding:0 10px;border:1px solid #cbd5e0;border-radius:8px;background:white;font-size:13px;">
                                <option value="">Todos</option>
                                @foreach($estados as $k => $label)
                                    <option value="{{ $k }}" {{ request('estado') === $k ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label style="display:block;font-size:11px;font-weight:700;color:#334155;margin-bottom:4px;">Capacidad</label>
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
                        <th class="table-header-custom table-cell-center" style="width: 15%;">Frente / Foto</th>
                        <th class="table-header-custom">Tipo</th>
                        <th class="table-header-custom">Marca / Modelo</th>
                        <th class="table-header-custom">Serial</th>
                        <th class="table-header-custom">Capacidad</th>
                        <th class="table-header-custom" style="width: 130px;">Estado</th>
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
                    <div title="Total de equipos auxiliares" style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.15); padding: 8px 6px; border-radius: 10px; min-width: 65px;">
                        <span id="auxStatsTotal" style="font-size: 36px; font-weight: 800; line-height: 1;">{{ $stats['total'] }}</span>
                        <span style="font-size: 13px; opacity: 0.8; font-weight: 700; margin-top: 2px;">TOTAL</span>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; flex: 1;">
                        <div title="Operativos" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25);">
                            <i class="material-icons" style="font-size: 18px; color: #22c55e; margin-bottom: 2px;">check_circle</i>
                            <strong id="auxStatsOperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['operativos'] }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Operativos</span>
                        </div>
                        <div title="Inoperativos" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25);">
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
            html += '<li style="padding-bottom:4px;border-bottom:1px dashed #f1f5f9;"><div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;"><span style="color:#334155;font-size:12.5px;font-weight:600;line-height:1.25;flex:1;">'+label+'</span><span style="font-weight:700;color:#1e293b;font-size:12.5px;background:#f1f5f9;padding:2px 8px;border-radius:4px;">'+r.total+'</span></div><div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;"><div style="width:'+pct+'%;height:100%;background:linear-gradient(90deg,#3b82f6 0%,#2563eb 100%);"></div></div></li>';
        });
        html += '</ul>';
        cont.innerHTML = html;
    }

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

    // ── Menu de cambio de estado (inline en la tabla) ──
    const AUX_STATUS = @json($estados);
    const AUX_STATUS_COLOR = {
        OPERATIVO:      { bg: '#dcfce7', fg: '#166534', icon: 'check_circle' },
        INOPERATIVO:    { bg: '#fee2e2', fg: '#991b1b', icon: 'cancel' },
        EN_ALMACEN:     { bg: '#dbeafe', fg: '#1e40af', icon: 'inventory_2' },
        DESINCORPORADO: { bg: '#e2e8f0', fg: '#475569', icon: 'block' },
    };

    function getOrCreateAuxStatusMenu() {
        let menu = document.getElementById('auxStatusMenu');
        if (menu) return menu;
        menu = document.createElement('div');
        menu.id = 'auxStatusMenu';
        menu.style.cssText = 'position:absolute;display:none;min-width:180px;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 20px -5px rgba(15,23,42,0.18);overflow:hidden;z-index:9999;';
        document.body.appendChild(menu);
        return menu;
    }

    let _auxStatusTrigger = null;

    window.openAuxStatusMenu = function (trigger) {
        const menu = getOrCreateAuxStatusMenu();
        // Si ya estaba abierto para este trigger, cerrar
        if (_auxStatusTrigger === trigger && menu.style.display !== 'none') {
            menu.style.display = 'none'; _auxStatusTrigger = null; return;
        }
        _auxStatusTrigger = trigger;
        const currentStatus = trigger.dataset.status;
        menu.innerHTML = '';
        Object.entries(AUX_STATUS).forEach(([key, label]) => {
            const cfg = AUX_STATUS_COLOR[key] || { bg:'#f1f5f9', fg:'#475569', icon:'help_outline' };
            const item = document.createElement('div');
            item.style.cssText = 'display:flex;align-items:center;gap:8px;padding:10px 12px;cursor:pointer;border-bottom:1px solid #f8fafc;font-size:12px;font-weight:600;color:#334155;';
            item.innerHTML = `
                <div style="background:${cfg.bg};padding:4px;border-radius:4px;display:flex;">
                    <i class="material-icons" style="font-size:16px;color:${cfg.fg};">${cfg.icon}</i>
                </div>
                <span>${label}</span>
                ${key === currentStatus ? '<i class="material-icons" style="font-size:14px;color:'+cfg.fg+';margin-left:auto;">check</i>' : ''}
            `;
            item.addEventListener('mouseover', () => item.style.background = '#f8fafc');
            item.addEventListener('mouseout', () => item.style.background = 'white');
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.style.display = 'none';
                window.auxChangeStatus(trigger, key);
                _auxStatusTrigger = null;
            });
            menu.appendChild(item);
        });
        // Posicionar bajo el trigger
        const r = trigger.getBoundingClientRect();
        menu.style.top  = (window.scrollY + r.bottom + 4) + 'px';
        menu.style.left = (window.scrollX + r.left) + 'px';
        menu.style.display = 'block';
    };

    window.auxChangeStatus = function (trigger, newStatus) {
        const oldStatus = trigger.dataset.status;
        if (oldStatus === newStatus) return;
        const url = trigger.dataset.statusUrl;
        const cfg = AUX_STATUS_COLOR[newStatus] || { bg:'#f1f5f9', fg:'#475569' };
        const lbl = AUX_STATUS[newStatus] || newStatus;
        // Optimistic UI
        trigger.style.background = cfg.bg;
        trigger.style.color = cfg.fg;
        trigger.style.borderColor = cfg.fg + '33';
        const lblEl = trigger.querySelector('.aux-status-label');
        if (lblEl) lblEl.textContent = lbl;
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
                cargarAuxiliares(); // refrescar stats + distribucion
            } else {
                throw new Error(body.message || 'Error');
            }
        })
        .catch(err => {
            // Revertir UI
            const oldCfg = AUX_STATUS_COLOR[oldStatus] || { bg:'#f1f5f9', fg:'#475569' };
            trigger.style.background = oldCfg.bg;
            trigger.style.color = oldCfg.fg;
            trigger.style.borderColor = oldCfg.fg + '33';
            if (lblEl) lblEl.textContent = AUX_STATUS[oldStatus] || oldStatus;
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
