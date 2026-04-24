@extends('layouts.estructura_base')
@section('title', 'Equipos Auxiliares')

@section('content')
<div class="dashboard-container" style="padding: 15px 20px; position: relative; z-index: 1;">

    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
        <div>
            <h1 style="margin:0;font-size:22px;font-weight:800;color:#1e293b;display:flex;align-items:center;gap:10px;">
                <i class="material-icons" style="color:#f59e0b;">construction</i>
                Equipos Auxiliares
            </h1>
            <p style="margin:4px 0 0 0;color:#64748b;font-size:13px;">
                Máquinas de soldar, luminarias, compresores, contenedores y plantas eléctricas.
            </p>
        </div>
        @can('equipos.create')
        <a href="{{ route('equipos-auxiliares.create') }}" class="btn-primary-maquinaria"
           style="padding:0 18px;height:42px;display:inline-flex;align-items:center;gap:8px;border-radius:10px;text-decoration:none;font-weight:700;">
            <i class="material-icons" style="font-size:18px;">add</i>
            <span>Nuevo Equipo Auxiliar</span>
        </a>
        @endcan
    </div>

    {{-- Filtros: mismo diseño visual que /admin/equipos (custom-dropdown + trigger) --}}
    <form id="auxFiltersForm" onsubmit="event.preventDefault(); cargarAuxiliares();" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:center;">

        {{-- 1. Filtro FRENTE --}}
        @php
            $reqFrente = request('id_frente');
            $frenteActual = null;
            if ($reqFrente && $reqFrente !== 'all') {
                $frenteActual = $frentes->firstWhere('ID_FRENTE', (int) $reqFrente);
            }
            $frenteLabel = $frenteActual ? $frenteActual->NOMBRE_FRENTE : 'Filtrar Frente...';
        @endphp
        <input type="hidden" name="id_frente" value="{{ $reqFrente ?: '' }}" data-filter-value>
        <div class="custom-dropdown" id="auxFrenteFilterSelect" data-filter-type="id_frente" data-default-label="Filtrar Frente..." style="flex:1;min-width:180px;">
            <div class="dropdown-trigger {{ $reqFrente && $reqFrente !== 'all' ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                <div style="padding: 0 12px; display: flex; align-items: center; color: #64748b;">
                    <i class="material-icons" style="font-size: 18px;">place</i>
                </div>
                <input type="text" data-filter-search placeholder="Buscar frente..." style="flex: 1; border: none; background: transparent; padding: 12px 5px; font-size: 13px; outline: none; min-width: 0;" autocomplete="off" value="{{ $frenteActual ? $frenteActual->NOMBRE_FRENTE : '' }}">
                <span data-filter-label style="display:none;">{{ $frenteLabel }}</span>
                <i class="material-icons" data-clear-btn
                   style="padding:0 8px; color:#64748b; font-size:18px; cursor:pointer; display:{{ $reqFrente && $reqFrente !== 'all' ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); clearDropdownFilter('auxFrenteFilterSelect'); cargarAuxiliares();">close</i>
            </div>
            <div class="dropdown-list">
                <div class="dropdown-item {{ !$reqFrente || $reqFrente === 'all' ? 'selected' : '' }}" data-value="all"
                     onclick="selectOption('auxFrenteFilterSelect','all','TODOS LOS FRENTES'); cargarAuxiliares();">
                    TODOS LOS FRENTES
                </div>
                @foreach($frentes as $frente)
                    <div class="dropdown-item {{ (string)$reqFrente === (string)$frente->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $frente->ID_FRENTE }}"
                         onclick="selectOption('auxFrenteFilterSelect','{{ $frente->ID_FRENTE }}','{{ addslashes(trim($frente->NOMBRE_FRENTE)) }}'); cargarAuxiliares();">
                        {{ $frente->NOMBRE_FRENTE }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 2. Filtro TIPO --}}
        @php
            $reqTipo = request('tipo');
            $tipoLabel = ($reqTipo && $reqTipo !== 'all') ? ($tipos[$reqTipo] ?? 'Filtrar Tipo...') : 'Filtrar Tipo...';
        @endphp
        <input type="hidden" name="tipo" value="{{ $reqTipo ?: '' }}" data-filter-value>
        <div class="custom-dropdown" id="auxTipoFilterSelect" data-filter-type="tipo" data-default-label="Filtrar Tipo..." style="flex:1;min-width:180px;">
            <div class="dropdown-trigger {{ $reqTipo && $reqTipo !== 'all' ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                <div style="padding: 0 12px; display: flex; align-items: center; color: #64748b;">
                    <i class="material-icons" style="font-size: 18px;">category</i>
                </div>
                <input type="text" data-filter-search placeholder="Buscar tipo..." style="flex: 1; border: none; background: transparent; padding: 12px 5px; font-size: 13px; outline: none; min-width: 0;" autocomplete="off" value="{{ ($reqTipo && $reqTipo !== 'all') ? $tipoLabel : '' }}">
                <span data-filter-label style="display:none;">{{ $tipoLabel }}</span>
                <i class="material-icons" data-clear-btn
                   style="padding:0 8px; color:#64748b; font-size:18px; cursor:pointer; display:{{ $reqTipo && $reqTipo !== 'all' ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); clearDropdownFilter('auxTipoFilterSelect'); cargarAuxiliares();">close</i>
            </div>
            <div class="dropdown-list">
                <div class="dropdown-item {{ !$reqTipo || $reqTipo === 'all' ? 'selected' : '' }}" data-value="all"
                     onclick="selectOption('auxTipoFilterSelect','all','TODOS LOS TIPOS'); cargarAuxiliares();">
                    TODOS LOS TIPOS
                </div>
                @foreach($tipos as $k => $label)
                    <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}"
                         onclick="selectOption('auxTipoFilterSelect','{{ $k }}','{{ addslashes($label) }}'); cargarAuxiliares();">
                        {{ $label }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 3. Filtro SERIAL (búsqueda libre) --}}
        <div class="search-wrapper" style="flex:1;min-width:220px;border:1px solid {{ request('search') ? '#0067b1' : '#cbd5e0' }};border-radius:12px;background:{{ request('search') ? '#e1effa' : '#fbfcfd' }};display:flex;align-items:center;height:45px;overflow:hidden;">
            <div style="padding: 0 12px; display: flex; align-items: center; color: #64748b;">
                <i class="material-icons" style="font-size: 18px;">search</i>
            </div>
            <input type="text" id="auxSearchInput" name="search" value="{{ request('search') }}" placeholder="Buscar por serial..."
                   oninput="window._auxDebounce && clearTimeout(window._auxDebounce); window._auxDebounce = setTimeout(cargarAuxiliares, 300);"
                   style="flex:1; border:none; background:transparent; padding:12px 5px; font-size:13px; outline:none;" autocomplete="off">
            <i class="material-icons"
               style="padding:0 8px; color:#64748b; font-size:18px; cursor:pointer; display:{{ request('search') ? 'block' : 'none' }};"
               onclick="event.stopPropagation(); document.getElementById('auxSearchInput').value=''; cargarAuxiliares();">close</i>
        </div>
    </form>

    {{-- Tabla: columnas 'Equipo Host' y 'Acciones' removidas a pedido del dueño.
         Edicion vive en el formulario de edit (se puede llegar a el via otra ruta). --}}
    <div class="custom-scrollbar-container" style="background:white;border-radius:12px;box-shadow:0 1px 3px rgba(15,23,42,0.08);overflow:hidden;">
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
})();
</script>
@endsection
