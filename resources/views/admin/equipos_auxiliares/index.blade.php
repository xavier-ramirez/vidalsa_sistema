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
            <input type="hidden" name="id_frente" value="{{ $reqFrente ?: '' }}" data-filter-value>
            <div class="custom-dropdown" id="auxFrenteFilterSelect" data-filter-type="id_frente"
                 data-default-label="Filtrar Frente..." style="flex:1;min-width:180px;max-width:260px;">
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
            <input type="hidden" name="tipo" value="{{ $reqTipo ?: '' }}" data-filter-value>
            <div class="custom-dropdown" id="auxTipoFilterSelect" data-filter-type="tipo"
                 data-default-label="Filtrar Tipo..." style="flex:1;min-width:180px;max-width:260px;">
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

            <button type="button" onclick="window.showToast && window.showToast('Filtros avanzados proximamente.', 'info')"
                    style="height:45px;padding:0 16px;background:white;border:1px solid #cbd5e0;border-radius:12px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#475569;cursor:pointer;flex-shrink:0;"
                    onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                <i class="material-icons" style="font-size:18px;color:#0067b1;">tune</i>
                <span>Filtros Avanzados</span>
            </button>

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
                    <a href="#" onclick="event.preventDefault(); window.exportAuxiliaresCsv(); document.getElementById('auxAccionesDropdown').style.display='none';"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;"
                       onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                        <div style="background:#dcfce7;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#16a34a;">file_download</i></div>
                        <span>Exportar Lista (CSV)</span>
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
                        <span id="auxStatsTotal" style="font-size: 36px; font-weight: 800; line-height: 1;">{{ $hasFilter ? $stats['total'] : '--' }}</span>
                        <span style="font-size: 13px; opacity: 0.8; font-weight: 700; margin-top: 2px;">TOTAL</span>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; flex: 1;">
                        <div title="Operativos" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25);">
                            <i class="material-icons" style="font-size: 18px; color: #22c55e; margin-bottom: 2px;">check_circle</i>
                            <strong id="auxStatsOperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $hasFilter ? $stats['operativos'] : '--' }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Operativos</span>
                        </div>
                        <div title="Inoperativos" style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25);">
                            <i class="material-icons" style="font-size: 18px; color: #ef4444; margin-bottom: 2px;">cancel</i>
                            <strong id="auxStatsInoperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $hasFilter ? $stats['inoperativos'] : '--' }}</strong>
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
                const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = data.hasFilter ? v : '--'; };
                set('auxStatsTotal',       data.stats.total);
                set('auxStatsOperativos',  data.stats.operativos);
                set('auxStatsInoperativos',data.stats.inoperativos);
            }
            if (data.distribucion) renderDistribucion(data.distribucion, data.hasFilter);
        })
        .catch(e => console.error('auxiliares load:', e))
        .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
    };

    function renderDistribucion(rows, hasFilter) {
        const cont = document.getElementById('auxDistribucionContainer');
        if (!cont) return;
        if (!hasFilter || !rows.length) {
            cont.innerHTML = '<h4 style="margin:0 0 12px 0;font-size:12px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="material-icons" style="font-size:18px;color:#3b82f6;">pie_chart</i>Distribución</h4><p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">Aplica un filtro para ver el detalle.</p>';
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
            const d = document.getElementById('auxAccionesDropdown');
            const btn = document.getElementById('auxAccionesBtn');
            if (!d || !btn) return;
            if (!d.contains(e.target) && !btn.contains(e.target)) d.style.display = 'none';
        });
    }

    // Exportar CSV respetando filtros activos
    window.exportAuxiliaresCsv = function () {
        const form = document.getElementById('auxFiltersForm');
        const params = form ? new URLSearchParams(new FormData(form)) : new URLSearchParams();
        const url = '{{ route("equipos-auxiliares.export") }}' + (params.toString() ? '?' + params.toString() : '');
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('data-no-spa', 'true');
        document.body.appendChild(link);
        link.click();
        setTimeout(() => document.body.removeChild(link), 500);
    };
})();
</script>
@endsection
