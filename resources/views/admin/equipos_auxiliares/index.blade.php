@extends('layouts.estructura_base')
@section('title', 'Equipos Auxiliares')

@section('content')
<div class="dashboard-container" style="padding: 15px 20px; position: relative; z-index: 1;">

    {{-- Titulo con la misma tipografia que /admin/equipos (class page-title + page-title-line2) --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h1 class="page-title">
            <span class="page-title-line2" style="color: #000;">Equipos Auxiliares</span>
        </h1>
    </div>

    {{-- Contenedor blanco unico que envuelve filtros + tabla + paginacion (patron admin-card como en /admin/equipos) --}}
    <div class="admin-card" style="margin: 0; min-height: 60vh; min-width: 0; width: 100%;">

    {{-- Barra de filtros + acciones: 3 filtros en fila + boton Filtros Avanzados + boton Acciones --}}
    <form id="auxFiltersForm" onsubmit="event.preventDefault(); cargarAuxiliares();"
          style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:center;">

        {{-- 1) Frente --}}
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

        {{-- 2) Tipo --}}
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

        {{-- 3) Serial (busqueda libre) --}}
        <div class="search-wrapper" style="flex:1;min-width:200px;max-width:260px;border:1px solid {{ request('search') ? '#0067b1' : '#cbd5e0' }};border-radius:12px;background:{{ request('search') ? '#e1effa' : '#fbfcfd' }};display:flex;align-items:center;height:45px;overflow:hidden;">
            <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
            <input type="text" id="auxSearchInput" name="search" value="{{ request('search') }}" placeholder="Filtrar Serial..."
                   oninput="window._auxDebounce && clearTimeout(window._auxDebounce); window._auxDebounce = setTimeout(cargarAuxiliares, 300);"
                   style="flex:1;border:none;background:transparent;padding:12px 5px;font-size:13px;outline:none;min-width:0;" autocomplete="off">
            <i class="material-icons"
               style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('search') ? 'block' : 'none' }};"
               onclick="event.stopPropagation(); document.getElementById('auxSearchInput').value=''; cargarAuxiliares();">close</i>
        </div>

        {{-- Boton Filtros Avanzados (placeholder: no hay filtros avanzados aun) --}}
        <button type="button" onclick="window.showToast && window.showToast('Filtros avanzados proximamente.', 'info')"
                style="height:45px;padding:0 16px;background:white;border:1px solid #cbd5e0;border-radius:12px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#475569;cursor:pointer;flex-shrink:0;"
                onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
            <i class="material-icons" style="font-size:18px;color:#0067b1;">tune</i>
            <span>Filtros Avanzados</span>
        </button>

        {{-- Boton Acciones: dropdown con Nuevo / (futuro: exportar, etc) --}}
        <div style="position:relative;flex-shrink:0;">
            <button type="button" id="auxAccionesBtn" class="btn-primary-maquinaria"
                    style="height:45px;padding:0 16px;border-radius:12px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;cursor:pointer;"
                    onclick="const d=document.getElementById('auxAccionesDropdown'); d.style.display = d.style.display==='none'||!d.style.display ? 'block' : 'none'; event.stopPropagation();">
                <i class="material-icons" style="font-size:18px;">settings</i>
                <span>Acciones</span>
                <i class="material-icons" style="font-size:16px;">expand_more</i>
            </button>
            <div id="auxAccionesDropdown" style="display:none;position:absolute;top:calc(100% + 5px);right:0;min-width:220px;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 20px -5px rgba(15,23,42,0.18);overflow:hidden;z-index:50;">
                @can('equipos.create')
                <a href="{{ route('equipos-auxiliares.create') }}"
                   style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;"
                   onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                    <div style="background:#fff7ed;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#f59e0b;">add_circle</i></div>
                    <span>Nuevo Equipo Auxiliar</span>
                </a>
                @endcan
            </div>
        </div>
    </form>

    {{-- Tabla ya no tiene su propio fondo blanco: vive dentro del admin-card padre --}}
    <div class="custom-scrollbar-container" style="overflow-x:auto;">
        <table class="admin-table" id="auxTable" style="width:100%;">
            <thead>
                <tr class="table-row-header">
                    <th class="table-header-custom">Tipo</th>
                    <th class="table-header-custom">Marca / Modelo</th>
                    <th class="table-header-custom">Serial</th>
                    <th class="table-header-custom">Capacidad</th>
                    <th class="table-header-custom">Frente</th>
                    <th class="table-header-custom">Estado</th>
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

    </div>{{-- /admin-card --}}
</div>

<script>
(function () {
    window.cargarAuxiliares = function () {
        const form   = document.getElementById('auxFiltersForm');
        const params = new URLSearchParams(new FormData(form));
        fetch('{{ route("equipos-auxiliares.index") }}?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('auxTableBody').innerHTML = data.html;
            document.getElementById('auxPagination').innerHTML = data.pagination;
        })
        .catch(e => console.error('auxiliares load:', e));
    };

    // Paginacion AJAX
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
            fetch(u.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }})
                .then(r => r.json())
                .then(data => {
                    document.getElementById('auxTableBody').innerHTML = data.html;
                    document.getElementById('auxPagination').innerHTML = data.pagination;
                });
        });
    }

    // Cerrar el dropdown de Acciones al hacer click fuera (SPA-safe)
    if (!window.auxAccionesOutsideBound) {
        window.auxAccionesOutsideBound = true;
        document.addEventListener('click', (e) => {
            const d = document.getElementById('auxAccionesDropdown');
            const btn = document.getElementById('auxAccionesBtn');
            if (!d || !btn) return;
            if (!d.contains(e.target) && !btn.contains(e.target)) {
                d.style.display = 'none';
            }
        });
    }
})();
</script>
@endsection
