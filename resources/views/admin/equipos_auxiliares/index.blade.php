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

    <!-- Filtros -->
    <form id="auxFiltersForm" onsubmit="event.preventDefault(); cargarAuxiliares();" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:center;">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Buscar por serial, marca, modelo..."
               oninput="_auxDebounce && clearTimeout(_auxDebounce); _auxDebounce = setTimeout(cargarAuxiliares, 300);"
               style="flex:1;min-width:220px;padding:10px 14px;border:1px solid #cbd5e0;border-radius:10px;font-size:13px;background:#fbfcfd;outline:none;">

        <select name="tipo" onchange="cargarAuxiliares()" style="padding:10px 14px;border:1px solid #cbd5e0;border-radius:10px;font-size:13px;background:#fbfcfd;min-width:160px;">
            <option value="all">Todos los tipos</option>
            @foreach($tipos as $k => $label)
                <option value="{{ $k }}" {{ request('tipo') === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>

        <select name="id_frente" onchange="cargarAuxiliares()" style="padding:10px 14px;border:1px solid #cbd5e0;border-radius:10px;font-size:13px;background:#fbfcfd;min-width:180px;">
            <option value="all">Todos los frentes</option>
            @foreach($frentes as $f)
                <option value="{{ $f->ID_FRENTE }}" {{ (string)request('id_frente') === (string)$f->ID_FRENTE ? 'selected' : '' }}>{{ $f->NOMBRE_FRENTE }}</option>
            @endforeach
        </select>

        <select name="estado" onchange="cargarAuxiliares()" style="padding:10px 14px;border:1px solid #cbd5e0;border-radius:10px;font-size:13px;background:#fbfcfd;min-width:150px;">
            <option value="all">Todos los estados</option>
            @foreach($estados as $k => $label)
                <option value="{{ $k }}" {{ request('estado') === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </form>

    <!-- Tabla -->
    <div class="custom-scrollbar-container" style="background:white;border-radius:12px;box-shadow:0 1px 3px rgba(15,23,42,0.08);overflow:hidden;">
        <table class="admin-table" id="auxTable" style="width:100%;">
            <thead>
                <tr class="table-row-header">
                    <th class="table-header-custom">Tipo</th>
                    <th class="table-header-custom">Marca / Modelo</th>
                    <th class="table-header-custom">Serial</th>
                    <th class="table-header-custom">Capacidad</th>
                    <th class="table-header-custom">Frente</th>
                    <th class="table-header-custom">Equipo Host</th>
                    <th class="table-header-custom">Estado</th>
                    <th class="table-header-custom" style="text-align:right;">Acciones</th>
                </tr>
            </thead>
            <tbody id="auxTableBody">
                @include('admin.equipos_auxiliares.partials.table_rows')
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <div id="auxPagination" style="margin-top:14px;">
        {{ $auxiliares->links('vendor.pagination.custom-sliding') }}
    </div>

</div>

<script>
(function () {
    let _auxDebounce = null;
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
