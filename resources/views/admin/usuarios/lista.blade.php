@extends('layouts.estructura_base')

@section('title', 'Gestión de Usuarios')

@section('content')
<div>
<section class="page-title-card" style="text-align: left; width: 95%; max-width: 1600px; margin: 0 auto 10px auto;">
    <h1 class="page-title" style="display: flex; align-items: center; gap: 12px; font-size: 24px;">
        <span class="page-title-line2" style="color: #000; margin: 0;">Gestión de Usuarios</span>
        <span id="user-count-badge" style="background: rgba(0, 103, 177, 0.08); color: #0067b1; padding: 4px 12px; border-radius: 20px; font-size: 14px; font-weight: 700; border: 1px solid rgba(0, 103, 177, 0.15); display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 26px; gap: 6px;">
            <i class="material-icons" style="font-size: 16px;">people</i>
            <span id="user-count-text">{{ $users->total() }}</span>
        </span>
    </h1>
</section>



<div class="admin-card" style="width: 95%; max-width: 1600px; margin: 0 auto;">
    <div class="filter-toolbar-container" style="margin-bottom: 5px;">
        <!-- Search Filter -->
        <div class="filter-item aligned-filter responsive-filter-item" style="position: relative;">
            <form id="search-form" style="width: 100%;" onsubmit="event.preventDefault();">
                <div class="search-wrapper" style="width: 100%; border-color: {{ request('search') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('search') ? '#e1effa' : '#fbfcfd' }}; height: 45px;">
                    <i class="material-icons search-icon">search</i>
                    <input type="text" id="searchInput" name="search" value="{{ request('search') }}"
                        placeholder="Buscar por nombre o correo..."
                        class="search-input-field"
                        style="height: 100%;"
                        autocomplete="off">
                    <i id="btn_clear_search" class="material-icons clear-icon" style="display: {{ request('search') ? 'block' : 'none' }};" onclick="clearUsuariosFilter('search');">close</i>
                </div>
            </form>
            {{-- Lista de sugerencias del buscador (nombre / correo): se rellena por JS al escribir. --}}
            <div id="searchSuggest" class="usuarios-suggest" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; margin-top:6px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 6px 16px rgba(0,0,0,0.08); max-height:280px; overflow-y:auto; padding:5px;"></div>
            {{-- Datos para el autocompletado. Va en un data-attribute (no en un <script>) para
                 ser SPA-safe: el loader (navegacion.js) re-ejecuta los <script> inyectados y
                 duplicaría un <script> en <head>. @json escapa ' < > & dentro de los valores,
                 por eso es seguro entre comillas simples. --}}
            <div id="usuariosSugerenciasData" data-list='@json($usuariosSugerencias ?? [])' hidden></div>
        </div>

        <!-- Frente Filter -->
        <div class="filter-item aligned-filter responsive-filter-item">
            <div class="custom-dropdown" id="frenteFilterSelect" data-filter-type="frente_filter" data-default-label="Filtrar Frente..." style="width: 100%;">
                <input type="hidden" name="id_frente" data-filter-value value="{{ request('id_frente') }}">
                
                @php 
                    $currentFrente = $frentes->firstWhere('ID_FRENTE', request('id_frente'));
                @endphp

                <div class="dropdown-trigger {{ request('id_frente') ? 'filter-active' : '' }}" style="background: #fbfcfd; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">
                    
                    <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                        <i class="material-icons" style="font-size: 18px;">search</i>
                    </div>

                    <input type="text" name="filter_search_dropdown" data-filter-search
                        placeholder="{{ $currentFrente ? $currentFrente->NOMBRE_FRENTE : 'Filtrar Frente...' }}" 
                        style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; color: #4a5568;"
                        onkeyup="window.filterDropdownOptions(this)"
                        onfocus="this.closest('.custom-dropdown').classList.add('active')"
                        autocomplete="off">

                    <div style="display: flex; align-items: center; padding-right: 10px;">
                        <i class="material-icons" data-clear-btn
                           style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: {{ request('id_frente') ? 'block' : 'none' }};" 
                           onclick="event.stopPropagation(); clearDropdownFilter('frenteFilterSelect'); loadUsuarios();"
                           title="Limpiar filtro">close</i>
                    </div>
                </div>

                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                    <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                        <div class="dropdown-item {{ !request('id_frente') || request('id_frente') == 'all' ? 'selected' : '' }}" data-value="all" onclick="selectOption('frenteFilterSelect', 'all', 'TODOS LOS FRENTES'); loadUsuarios();">
                            TODOS LOS FRENTES
                        </div>
                        @foreach($frentes as $frente)
                            <div class="dropdown-item {{ request('id_frente') == $frente->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $frente->ID_FRENTE }}" onclick="selectOption('frenteFilterSelect', '{{ $frente->ID_FRENTE }}', '{{ $frente->NOMBRE_FRENTE }}'); loadUsuarios();">
                                {{ $frente->NOMBRE_FRENTE }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Rol Filter -->
        <div class="filter-item aligned-filter responsive-filter-item">
            <div class="custom-dropdown" id="rolFilterSelect" data-filter-type="rol_filter" data-default-label="Filtrar Rol..." style="width: 100%;">
                <input type="hidden" name="id_rol" data-filter-value value="{{ request('id_rol') }}">

                @php
                    $currentRol = $roles->firstWhere('ID_ROL', request('id_rol'));
                @endphp

                <div class="dropdown-trigger {{ request('id_rol') ? 'filter-active' : '' }}" style="background: #fbfcfd; border: 1px solid #cbd5e0; border-radius: 12px; height: 45px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">

                    <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                        <i class="material-icons" style="font-size: 18px;">search</i>
                    </div>

                    <input type="text" name="filter_search_dropdown" data-filter-search
                        placeholder="{{ $currentRol ? $currentRol->NOMBRE_ROL : 'Filtrar Rol...' }}"
                        style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; color: #4a5568;"
                        onkeyup="window.filterDropdownOptions(this)"
                        onfocus="this.closest('.custom-dropdown').classList.add('active')"
                        autocomplete="off">

                    <div style="display: flex; align-items: center; padding-right: 10px;">
                        <i class="material-icons" data-clear-btn
                           style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: {{ request('id_rol') ? 'block' : 'none' }};"
                           onclick="event.stopPropagation(); clearDropdownFilter('rolFilterSelect'); loadUsuarios();"
                           title="Limpiar filtro">close</i>
                    </div>
                </div>

                <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                    <div class="dropdown-item-list" style="max-height: 250px; overflow-y: auto;">
                        <div class="dropdown-item {{ !request('id_rol') || request('id_rol') == 'all' ? 'selected' : '' }}" data-value="all" onclick="selectOption('rolFilterSelect', 'all', 'TODOS LOS ROLES'); loadUsuarios();">
                            TODOS LOS ROLES
                        </div>
                        @foreach($roles as $rol)
                            <div class="dropdown-item {{ request('id_rol') == $rol->ID_ROL ? 'selected' : '' }}" data-value="{{ $rol->ID_ROL }}" onclick="selectOption('rolFilterSelect', '{{ $rol->ID_ROL }}', '{{ $rol->NOMBRE_ROL }}'); loadUsuarios();">
                                {{ $rol->NOMBRE_ROL }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtro Fecha de creación: calendario nativo (igual que los otros módulos);
             se abre al hacer clic (showPicker) y filtra por created_at del usuario.
             Contenedor más angosto: el date no necesita los 350px de los otros filtros. -->
        <div class="filter-item aligned-filter responsive-filter-item" style="flex: 0 1 180px;">
            <input type="date" name="fecha_creacion" class="native-date"
                value="{{ request('fecha_creacion') }}"
                onchange="loadUsuarios()"
                onclick="try{this.showPicker()}catch(e){}"
                title="Filtrar por fecha de creación del usuario"
                style="width: 100%; height: 45px; border-radius: 12px; border: 1px solid {{ request('fecha_creacion') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('fecha_creacion') ? '#e1effa' : '#fbfcfd' }}; outline: none; padding: 0 12px; font-size: 14px; color: #4a5568; cursor: pointer;">
        </div>

        <!-- New User Button -->
        <div class="filter-item aligned-filter responsive-btn-item">
            <a href="{{ route('usuarios.create') }}" class="btn-primary-maquinaria btn-nuevo-usuario">
                <i class="material-icons">person_add</i>
                Nuevo
            </a>
        </div>
    </div>

    <!-- Unified Responsive Table -->
    <div class="custom-scrollbar-container">
        <table class="admin-table table-usuarios-mobile" style="width: 100% !important;">
            <thead>
                <tr style="background: #334155; text-align: left; color: #ffffff; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; border-bottom: 2px solid #1e293b;">
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 200px;">Nombre y Apellido</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 180px;">Correo</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 150px;">Rol</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 120px;">Acceso</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 180px;">Frente de Trabajo</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 100px;">Estado</th>
                    <th style="padding: 10px 15px; text-align: left; width: 100px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="usuariosTableBody" style="font-size: 14px;">
                @include('admin.usuarios.partials.table_rows', ['users' => $users])
            </tbody>
        </table>
        {{-- Single Delete Form for Optimization --}}
        <form id="delete-form-global" action="" method="POST" style="display: none;">
            @csrf
            @method('DELETE')
        </form>
    </div>

    <!-- Pagination -->

    <div id="usuariosPagination" style="margin-top: 25px;">
        {{ $users->links() }}
    </div>





@endsection
