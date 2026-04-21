@extends('layouts.estructura_base')

@section('title', 'Catálogo de Modelos')

@section('content')
<section class="page-title-card" style="text-align: left; margin: 0 0 10px 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Catálogo por Modelo</span>
    </h1>
</section>

<div class="page-layout-grid">
    <div class="admin-card" style="margin: 0; min-height: 80vh; min-width: 0; width: 100%; padding: 6px;">

    <div class="filter-toolbar-container" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center; margin-bottom: 5px;">
    <form id="catalogoFiltersForm" onsubmit="event.preventDefault(); loadCatalogo();" style="display: contents;">
            
            <!-- Modelo Filter -->
            <div class="filter-item" style="flex: 0 1 300px; min-width: 100px;">
                <div class="custom-dropdown" id="modeloFilterSelect" data-filter-type="modelo" data-default-label="Buscar Modelo...">
                    <input type="hidden" name="modelo" data-filter-value value="{{ request('modelo') }}">
                    <div class="dropdown-trigger {{ request('modelo') ? 'filter-active' : '' }}" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px; position: relative; overflow: hidden;">
                        <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 18px;">search</i>
                        </div>
                        <input type="text" placeholder="{{ request('modelo') ?: 'Buscar Modelo...' }}" 
                            name="filter_search_dropdown"
                            data-filter-search
                            style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none;"
                            onkeyup="filterDropdownOptions(this);"
                            onfocus="this.closest('.custom-dropdown').classList.add('active')"
                            autocomplete="off">
                        <i class="material-icons" data-clear-btn style="padding: 0 5px; color: var(--maquinaria-gray-text); font-size: 18px; display: {{ request('modelo') ? 'block' : 'none' }};" onclick="event.stopPropagation(); clearDropdownFilter('modeloFilterSelect'); loadCatalogo();">close</i>
                    </div>
                    <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                        <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                            <div class="dropdown-item {{ !request('modelo') || request('modelo') == 'all' ? 'selected' : '' }}" data-value="all" onclick="selectOption('modeloFilterSelect', 'all', 'TODOS LOS MODELOS'); loadCatalogo();">
                                TODOS LOS MODELOS
                            </div>
                            @foreach($availableModelos as $mod)
                                <div class="dropdown-item {{ request('modelo') == $mod ? 'selected' : '' }}" data-value="{{ $mod }}" onclick="selectOption('modeloFilterSelect', '{{ $mod }}', '{{ $mod }}'); loadCatalogo();">
                                    {{ $mod }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <!-- Año Filter -->
            <div class="filter-item" style="flex: 0 0 190px;">
                <div class="custom-dropdown" id="anioFilterSelect" data-filter-type="anio" data-default-label="Buscar Año...">
                    <input type="hidden" name="anio" data-filter-value value="{{ request('anio') }}">
                    <div class="dropdown-trigger {{ request('anio') ? 'filter-active' : '' }}" style="padding: 0; display: flex; align-items: center; background: #fbfcfd; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px; position: relative; overflow: hidden;">
                        <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 18px;">calendar_today</i>
                        </div>
                        <input type="text" placeholder="{{ request('anio') ?: 'Buscar Año...' }}" 
                            name="filter_search_dropdown"
                            data-filter-search
                            style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none;"
                            onkeyup="filterDropdownOptions(this);"
                            onfocus="this.closest('.custom-dropdown').classList.add('active')"
                            autocomplete="off">
                        <i class="material-icons" data-clear-btn style="padding: 0 5px; color: var(--maquinaria-gray-text); font-size: 18px; display: {{ request('anio') ? 'block' : 'none' }};" onclick="event.stopPropagation(); clearDropdownFilter('anioFilterSelect'); loadCatalogo();">close</i>
                    </div>
                    <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                        <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                            <div class="dropdown-item {{ !request('anio') || request('anio') == 'all' ? 'selected' : '' }}" data-value="all" onclick="selectOption('anioFilterSelect', 'all', 'TODOS LOS AÑOS'); loadCatalogo();">
                                TODOS LOS AÑOS
                            </div>
                            @foreach($availableAnios as $a)
                                <div class="dropdown-item {{ request('anio') == $a ? 'selected' : '' }}" data-value="{{ $a }}" onclick="selectOption('anioFilterSelect', '{{ $a }}', '{{ $a }}'); loadCatalogo();">
                                    {{ $a }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nuevo Button -->
            <div class="filter-item">
                <a href="{{ route('catalogo.create') }}" class="btn-primary-maquinaria" style="height: 45px; display: flex; align-items: center; padding: 0 15px; text-decoration: none; gap: 8px;">
                    <i class="material-icons" style="font-size: 18px;">add_circle</i>
                    Nuevo
                </a>
            </div>

    </form>
    </div>

    <div class="custom-scrollbar-container" style="width: 100%; overflow-x: auto; margin-top: 5px;">
        <!-- Added catalog-specific-table class for CSS isolation -->
        <table class="admin-table catalog-specific-table" style="width: 100%; margin: 0; border-collapse: separate; border-spacing: 0 5px;">
            <thead>
                <tr class="table-row-header">
                    <th class="table-header-custom table-cell-bordered" style="width: 160px;"></th> 
                    <th class="table-header-custom table-cell-bordered" style="width: 160px; text-align: center;">Modelo / Año</th>
                    <th class="table-header-custom table-cell-bordered" style="width: 250px; text-align: center;">Motor / Energía / Consumo</th>
                    <th class="table-header-custom table-cell-bordered" style="width: 290px; text-align: center;">Lubricantes y Fluidos</th>
                    <th class="table-header-custom" style="width: 25px; text-align: center !important; padding-left: 0; padding-right: 0;">Acciones</th>
                </tr>
            </thead>
            <tbody id="catalogoTableBody" style="font-size: 15px;">
                @include('admin.catalogo.partials.table_rows')
            </tbody>
        </table>
    </div>

    <div style="margin-top: 25px;" id="catalogoPagination">
        {{ $catalogos->links() }}
    </div>
</div> <!-- End admin-card -->

<!-- Right Sidebar: Stats -->
<div class="counter-sidebar" id="statsSidebarContainer" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 15px;">
    @include('admin.catalogo.partials.stats_sidebar')
</div>

</div> <!-- End page-layout-grid -->

@endsection

