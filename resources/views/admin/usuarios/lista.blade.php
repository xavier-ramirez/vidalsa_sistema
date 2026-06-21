@extends('layouts.estructura_base')

@section('title', 'Gestión de Usuarios')

@section('content')
<div>
<section class="page-title-card" style="text-align: left; width: 100%; max-width: none; margin: 0 0 16px 0; padding: 0;">
    <h1 class="page-title" style="display: flex; align-items: center; gap: 12px; font-size: 24px;">
        <span class="page-title-line2" style="color: #000; margin: 0;">Gestión de Usuarios</span>
        <span id="user-count-badge" style="background: rgba(0, 103, 177, 0.08); color: #0067b1; padding: 4px 12px; border-radius: 20px; font-size: 14px; font-weight: 700; border: 1px solid rgba(0, 103, 177, 0.15); display: inline-flex; align-items: center; justify-content: center; min-width: 30px; height: 26px; gap: 6px;">
            <i class="material-icons" style="font-size: 16px;">people</i>
            <span id="user-count-text">{{ $users->total() }}</span>
        </span>
    </h1>
</section>

<div class="admin-card" style="width: 100%; max-width: none; margin: 0; background: transparent; border: none; box-shadow: none; padding: 0;">
    {{-- Layout: tarjeta principal (filtros + tabla, como en /admin/equipos) a la izquierda
         y el panel de Usuarios Activos en su propia tarjeta a la derecha. --}}
    <div class="usuarios-layout">
    <div class="usuarios-main">
    <div class="filter-toolbar-container usr-filter-row" style="margin-bottom: 6px;">
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

        <!-- Action Buttons -->
        <div class="filter-item aligned-filter responsive-btn-item usuarios-action-btns" style="display: flex; gap: 10px; flex: 0 0 auto;">
            <!-- Limpiar Roles Button -->
            <button type="button" onclick="window.checkUnusedRoles()" title="Limpiar roles inactivos" style="height: 45px; width: 45px; padding: 0; border-radius: 12px; background: white; border: 1px solid #fed7aa; color: #c2410c; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: background 0.2s;" onmouseover="this.style.background='#fff7ed'" onmouseout="this.style.background='white'">
                <i class="material-icons" style="font-size:20px;">delete_sweep</i>
            </button>

            <!-- New User Button -->
            <a href="{{ route('usuarios.create') }}" class="btn-primary-maquinaria btn-nuevo-usuario" style="height: 45px; display: flex; align-items: center; flex: 0 0 auto;">
                <i class="material-icons">person_add</i>
                Nuevo
            </a>
        </div>
    </div>

    <style>
        /* 2 columnas: izquierda = tarjeta blanca con FILTROS + TABLA (como /admin/equipos),
           derecha = panel "Usuarios Activos" en su propia tarjeta. El .admin-card exterior
           es solo el contenedor transparente que centra y limita el ancho. */
        .usuarios-layout { display: flex; gap: 18px; align-items: flex-start; }
        .usuarios-layout .usuarios-main {
            flex: 1 1 auto; min-width: 0;
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            padding: 16px; box-shadow: 0 4px 6px -1px rgba(15,23,42,0.06);
        }
        .usuarios-side { width: 300px; flex: 0 0 300px; }
        .usr-filter-row {
            display: flex;
            flex-wrap: nowrap;
            gap: 10px;
            align-items: stretch;
        }
        .usr-filter-row > .filter-item.responsive-filter-item {
            flex: 1 1 0 !important;
            max-width: none !important;
            min-width: 0;
        }
        @media (min-width: 769px) {
            .usr-filter-row > .filter-item.responsive-filter-item:nth-child(1) {
                flex: 2.5 1 0 !important;
            }
            .usr-filter-row > .filter-item.responsive-filter-item:nth-child(2) {
                flex: 1.5 1 0 !important;
            }
            .usr-filter-row > .filter-item.responsive-filter-item:nth-child(3) {
                flex: 1.5 1 0 !important;
            }
            .table-usuarios-mobile { border-spacing: 0 5px !important; }
            .table-usuarios-mobile td { padding-top: 7px !important; padding-bottom: 7px !important; }
        }
        @media (max-width: 1024px) {
            .usuarios-layout { flex-direction: column; }
            .usuarios-side { width: 100%; flex-basis: auto; }
            .usr-filter-row { flex-wrap: wrap; }
        }
        @media (max-width: 768px) {
            .usr-filter-row {
                flex-direction: row !important;
                flex-wrap: wrap !important;
            }
            .usr-filter-row > .filter-item.responsive-filter-item {
                flex: 1 1 100% !important;
                min-width: 0 !important;
            }
        }
    </style>
    <!-- Unified Responsive Table -->
    <div class="custom-scrollbar-container">
        <table class="admin-table table-usuarios-mobile" style="width: 100% !important;">
            <thead>
                <tr style="background: #1e293b; text-align: left; color: #ffffff; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; border-bottom: 2px solid #0f172a;">
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 150px;">Nombre y Apellido</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 140px;">Correo</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 160px;">Rol</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 90px;">Nivel</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 85px;">Frente</th>
                    <th class="table-cell-bordered" style="padding: 10px 15px; text-align: left; min-width: 80px;">Estado</th>
                    <th style="padding: 10px 8px; text-align: center; width: 60px;"></th>
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
    </div>{{-- /usuarios-main --}}

    {{-- ─── Panel lateral: Usuarios Activos (sesiones últimos 30 min) ───
         Mismo panel y misma fuente de datos que el módulo de Auditoría
         (Usuario::sesionesActivas) — para no duplicar la lógica. --}}
    @if(isset($activeUsers) && auth()->check() && auth()->user()->can('super.admin'))
    <aside class="usuarios-side">
        <div style="background: white; border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.06);">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #10b981; font-size: 18px;">radio_button_checked</i>
                    <h3 style="margin: 0; font-size: 12px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 0.5px;">Usuarios Activos</h3>
                </div>
                <span style="background: #dcfce7; color: #15803d; font-size: 12px; padding: 3px 10px; border-radius: 10px; font-weight: 700;">{{ $activeUsers->count() }}</span>
            </div>
            @if($activeUsers->count() === 0)
                <p style="margin: 4px 0 0; font-size: 12px; color: #94a3b8; text-align: center; padding: 20px 0;">Nadie conectado en los últimos 30 min.</p>
            @else
                <div style="display: flex; flex-direction: column; gap: 8px; max-height: 460px; overflow-y: auto; padding-right: 4px;" class="custom-scrollbar-container">
                    @foreach($activeUsers as $u)
                        @php
                            $minsAgo = max(0, (int) floor((now()->timestamp - $u->last_activity) / 60));
                            $ago = $minsAgo === 0 ? 'ahora' : ($minsAgo === 1 ? 'hace 1 min' : 'hace ' . $minsAgo . ' min');
                            $nombreCorto = $u->NOMBRE_COMPLETO ?: strtok($u->CORREO_ELECTRONICO, '@');
                        @endphp
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #f0fdf4; padding: 9px 12px; border-radius: 8px; border: 1px solid #dcfce7;" title="{{ $u->CORREO_ELECTRONICO }} | IP: {{ $u->ip_address ?? 'N/A' }}">
                            <div style="display: flex; align-items: center; gap: 10px; min-width: 0; flex: 1;">
                                <span style="width: 9px; height: 9px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.25); flex-shrink: 0;"></span>
                                <span style="font-size: 11px; font-weight: 600; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">{{ $nombreCorto }}</span>
                            </div>
                            <span style="font-size: 11px; color: #64748b; white-space: nowrap; margin-left: 8px; background: #e2e8f0; padding: 2px 7px; border-radius: 6px; font-weight: 600;">{{ $ago }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </aside>
    @endif
    </div>{{-- /usuarios-layout --}}

    <!-- Modal Limpiar Roles (Estilo Papelera) -->
    <div id="modalLimpiarRoles" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0,0,0,0.5); z-index: 99999; align-items: center; justify-content: center; backdrop-filter: blur(4px);">
        <div style="background: white; border-radius: 14px; width: 90%; max-width: 440px; max-height: 80vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25);">
            <!-- Encabezado oscuro -->
            <div style="background: #1e293b; padding: 12px 16px; color: white; display: flex; justify-content: center; align-items: center; position: relative;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <i class="material-icons" style="color: #f59e0b; font-size: 18px;">cleaning_services</i>
                    <h2 style="margin: 0; font-size: 14px; font-weight: 700;">Limpieza de Roles</h2>
                </div>
                <button type="button" onclick="document.getElementById('modalLimpiarRoles').style.display='none'" style="position: absolute; right: 12px; background: transparent; border: none; color: white; cursor: pointer; opacity: 0.7; transition: opacity 0.2s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                    <i class="material-icons" style="font-size: 18px;">close</i>
                </button>
            </div>
            <!-- Cuerpo Dinámico -->
            <div id="modalLimpiarRolesBody" style="display: flex; flex-direction: column; flex: 1; overflow: hidden; background: #f8fafc;">
                <div style="padding: 24px; text-align: center; color: #94a3b8;">
                    <i class="material-icons" style="animation: spin 1s linear infinite; font-size: 22px;">sync</i>
                </div>
            </div>
        </div>
    </div>

<style>
    @media (max-width: 768px) {
        body:has(.table-usuarios-mobile) .page-title-card {
            margin-bottom: 10px !important;
            padding: 0 !important;
        }
        .usuarios-action-btns {
            flex: 1 1 100% !important;
            width: 100% !important;
        }
        .usuarios-action-btns > button, .usuarios-action-btns > a {
            flex: 1 1 0 !important;
            width: 100% !important;
            justify-content: center !important;
        }
    }
</style>

<script>
    // Inyecta el keyframes de rotación en el head si no existe
    if (!document.getElementById('spin-keyframes')) {
        const style = document.createElement('style');
        style.id = 'spin-keyframes';
        style.innerHTML = `@keyframes spin { 100% { transform: rotate(360deg); } }`;
        document.head.appendChild(style);
    }

    // Asignar al objeto window para que esté disponible globalmente sin importar cómo el SPA inyecte el HTML
    window.checkUnusedRoles = function() {
        document.getElementById('modalLimpiarRoles').style.display = 'flex';
        
        const body = document.getElementById('modalLimpiarRolesBody');
        body.innerHTML = '<div style="padding: 24px; text-align: center; color: #94a3b8;"><i class="material-icons" style="animation: spin 1s linear infinite; font-size: 22px;">sync</i></div>';

        fetch("{{ route('usuarios.unused-roles') }}")
            .then(response => response.json())
            .then(roles => {
                if (roles.length > 0) {
                    // Escapa el nombre del rol antes de inyectarlo como HTML (defensa básica XSS).
                    const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
                    const plural = roles.length === 1 ? 'rol' : 'roles';
                    let html = `<div style="padding: 10px 16px; font-size: 12px; color: #64748b; background: white; border-bottom: 1px solid #e2e8f0; text-align: center;">Se ${roles.length === 1 ? 'encontró' : 'encontraron'} <strong style="color:#c2410c;">${roles.length}</strong> ${plural} sin usuarios que se pueden eliminar:</div>`;
                    html += '<div style="overflow-y: auto; background: #f8fafc; padding: 10px; flex: 1; min-height: 160px; max-height: 400px;">';

                    roles.forEach(rol => {
                        html += `
                        <div style="display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: white; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 5px;">
                            <div style="width: 42px; height: 42px; border-radius: 6px; background: #fff7ed; color: #c2410c; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border: 1px solid #e2e8f0;">
                                <i class="material-icons" style="font-size: 20px;">badge</i>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 700; color: #1e293b; font-size: 12px; text-transform: uppercase; line-height: 1.2;">${esc(rol.NOMBRE_ROL)}</div>
                                <div style="font-size: 10px; color: #94a3b8; margin-top: 2px;">SIN USUARIOS ASIGNADOS</div>
                            </div>
                        </div>`;
                    });
                    
                    html += '</div>';
                    
                    // Footer con el botón de eliminar
                    html += `
                    <div style="padding: 12px 16px; background: white; border-top: 1px solid #e2e8f0; display: flex; justify-content: center;">
                        <button type="button" onclick="window.deleteUnusedRoles()" class="btn-primary-maquinaria" style="background:#ef4444;padding:8px 16px;border-radius:8px;font-size:13px;height:auto;gap:6px;cursor:pointer;">
                            <i class="material-icons" style="font-size: 18px;">delete_sweep</i>
                            Confirmar Eliminación
                        </button>
                    </div>`;
                    body.innerHTML = html;
                } else {
                    body.innerHTML = `
                    <div style="padding: 40px 20px; text-align: center;">
                        <i class="material-icons" style="font-size: 48px; color: #10b981; margin-bottom: 10px;">check_circle_outline</i>
                        <p style="color: #059669; font-size: 16px; font-weight: bold; margin: 0;">¡Sistema Limpio!</p>
                        <p style="color: #475569; font-size: 13px; margin-top: 5px;">Todos los roles tienen usuarios asignados.</p>
                    </div>`;
                }
            })
            .catch(error => {
                body.innerHTML = '<div style="padding: 24px; text-align: center; color: #ef4444;">Error al consultar los roles.</div>';
                console.error(error);
            });
    };

    window.deleteUnusedRoles = function() {
        if (!confirm('¿Confirma que desea eliminar estos roles permanentemente?')) return;
        
        fetch("{{ route('usuarios.delete-unused-roles') }}", {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('modalLimpiarRoles').style.display = 'none';
                if (window.showToast) {
                    window.showToast(data.message, 'success');
                } else {
                    alert(data.message);
                }
                setTimeout(() => window.location.reload(), 1500);
            }
        })
        .catch(error => {
            alert('Ocurrió un error al intentar eliminar los roles.');
            console.error(error);
        });
    };
</script>

@endsection
