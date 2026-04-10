<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Sistema de Gestión')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <!-- CSS -->
    <link rel="stylesheet" href="{{ asset('css/maquinaria/estilos_globales.css') }}?v=20.4">
    <link rel="stylesheet" href="{{ asset('css/maquinaria/menu.css') }}?v=10.3">
    <link rel="stylesheet" href="{{ asset('css/maquinaria/catalogo.css') }}?v=4.1">
    <!-- Local Fonts Optimization -->
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}?v=1.0">

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        /* Standard Material Icons definition */
        .material-icons {
            font-family: 'Material Icons';
            font-weight: normal;
            font-style: normal;
            font-size: 24px;
            line-height: 1;
            letter-spacing: normal;
            text-transform: none;
            display: inline-block;
            white-space: nowrap;
            word-wrap: normal;
            direction: ltr;
            -webkit-font-feature-settings: 'liga';
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
            -moz-osx-font-smoothing: grayscale;
            font-feature-settings: 'liga';
        }
        
        /* Spin animation for download button */
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* CRITICAL CSS: Prevent Layout Shift / FOUC */
        body {
            /* Matches menu.css padding-top */
            padding-top: 70px; 
            margin: 0;
            opacity: 1 !important; /* Force visible immediately */
        }
        .dashboard-header {
            /* Reserve space even before CSS loads */
            height: 70px;
            position: fixed;
            top: 5px;
            width: 98%;
            max-width: 1600px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
        }
    </style>
    <!-- Custom UI Components (SPA Friendly) -->
    <!-- Scripts moved to footer for performance -->
    <meta name="spa-extra-css-start" />
    @yield('extra_css')
    <meta name="spa-extra-css-end" />
</head>
<body class="modern-app">
    <!-- Global Preloader (Bars animation) - Solo para navegación interna -->
    <div id="preloader" class="preloader" style="display: none;">
        <div class="preloader-content">

            <div class="spinner-circle"></div>
        </div>
    </div>


    
    <!-- Permanent Header (Never reloads) -->
    <header class="dashboard-header">
        <div class="header-logo">
            <a href="{{ route('menu') }}">
                <img src="{{ asset('images/maquinaria/logo.webp') }}" alt="Logo">
            </a>
        </div>

        <nav class="header-nav">
            <a id="nav-inicio-btn" href="{{ route('menu') }}" class="nav-link" style="display: {{ request()->is('menu') ? 'none' : 'flex' }} !important; align-items: center;">
                <i class="material-icons" style="font-size: 18px; margin-right: 5px;">home</i>Inicio
            </a>

            <a href="{{ route('equipos.index') }}" class="nav-link {{ request()->is('admin/equipos*') ? 'active' : '' }}" style="display: flex; align-items: center;">
                <i class="material-icons" style="font-size: 18px; margin-right: 5px;">agriculture</i>Vehículo
            </a>
            <a href="{{ route('movilizaciones.index') }}" class="nav-link {{ request()->is('admin/movilizaciones*') ? 'active' : '' }}" style="display: flex; align-items: center;">
                <i class="material-icons" style="font-size: 18px; margin-right: 5px;">local_shipping</i>Historial Mov.
            </a>

            <!-- Configuraciones Dropdown -->
            <div class="nav-dropdown">
                <a href="#" class="nav-link {{ (request()->is('admin/usuarios*') || request()->is('admin/frentes*')) ? 'active' : '' }}" style="display: flex; align-items: center; gap: 4px;">
                    <i class="material-icons" style="font-size: 18px;">settings</i>Configuraciones
                    <i class="material-icons" style="font-size: 16px;">expand_more</i>
                </a>
                <div class="nav-dropdown-content">
                    @can('manage.users')
                    <a href="{{ route('usuarios.index') }}" class="nav-dropdown-link {{ request()->is('admin/usuarios*') ? 'active' : '' }}">
                        <i class="material-icons">people</i> Usuarios
                    </a>
                    @else
                    <a href="{{ route('usuarios.miPerfil') }}" class="nav-dropdown-link {{ request()->is('admin/usuarios/mi-perfil') ? 'active' : '' }}">
                        <i class="material-icons">manage_accounts</i> Mi Usuario
                    </a>
                    @endcan
                    <a href="{{ route('frentes.index') }}" class="nav-dropdown-link {{ request()->is('admin/frentes*') ? 'active' : '' }}">
                        <i class="material-icons">business</i> Frentes de trabajo
                    </a>
                    <a href="{{ route('catalogo.index') }}" class="nav-dropdown-link {{ request()->is('admin/catalogo*') ? 'active' : '' }}">
                        <i class="material-icons">menu_book</i> Catálogo de Modelos
                    </a>

                </div>
            </div>

            <a href="{{ route('consumibles.graficos') }}" class="nav-link {{ request()->is('admin/consumibles*') ? 'active' : '' }}" style="display:flex; align-items:center;">
                <i class="material-icons" style="font-size:18px; margin-right:5px;">local_gas_station</i>Consumibles
            </a>
            <a href="{{ route('mantenimiento.index') }}" class="nav-link {{ request()->is('admin/mantenimiento*') ? 'active' : '' }}" style="display:flex; align-items:center;">
                <i class="material-icons" style="font-size:18px; margin-right:5px;">build</i>Mantenimiento
            </a>
        </nav>

        <div class="header-actions desktop-only">
            <form action="{{ route('logout') }}" method="POST" style="margin: 0; display: inline;">
                @csrf
                <button type="submit" class="btn-logout-header" data-no-spa title="Salir del sistema">
                    <i class="material-icons">logout</i>
                </button>
            </form>
        </div>

        <button class="menu-toggle mobile-only" onclick="toggleMobileMenu()">
            <i class="material-icons">menu</i>
        </button>
    </header>

    <!-- Floating User Panel (Bottom Right) -->


    <!-- Mobile Menu -->
    <div class="mobile-menu" id="mobileMenu">
        <a href="{{ route('menu') }}" class="mobile-nav-link {{ request()->is('menu') ? 'active' : '' }}">
            <i class="material-icons">home</i> Inicio
        </a>
        
        <a href="{{ route('equipos.index') }}" class="mobile-nav-link {{ request()->is('admin/equipos*') ? 'active' : '' }}">
            <i class="material-icons">agriculture</i> Vehículo
        </a>
        <a href="{{ route('movilizaciones.index') }}" class="mobile-nav-link {{ request()->is('admin/movilizaciones*') ? 'active' : '' }}">
            <i class="material-icons">local_shipping</i> Historial Mov.
        </a>

        <!-- Mobile Group -->
        <div class="mobile-nav-group" id="mobileConfigGroup">
            <div class="mobile-nav-group-title">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="material-icons">settings</i>
                    Configuraciones
                </div>
                <i class="material-icons chevron">expand_more</i>
            </div>
            <div class="mobile-nav-group-content">
                @can('manage.users')
                <a href="{{ route('usuarios.index') }}" class="mobile-nav-link {{ request()->is('admin/usuarios*') ? 'active' : '' }}">
                    <i class="material-icons">people</i> Usuarios
                </a>
                @else
                <a href="{{ route('usuarios.miPerfil') }}" class="mobile-nav-link {{ request()->is('admin/usuarios/mi-perfil') ? 'active' : '' }}">
                    <i class="material-icons">manage_accounts</i> Mi Usuario
                </a>
                @endcan
                <a href="{{ route('frentes.index') }}" class="mobile-nav-link {{ request()->is('admin/frentes*') ? 'active' : '' }}">
                    <i class="material-icons">business</i> Frentes de trabajo
                </a>
                <a href="{{ route('catalogo.index') }}" class="mobile-nav-link {{ request()->is('admin/catalogo*') ? 'active' : '' }}">
                    <i class="material-icons">menu_book</i> Catálogo de Modelos
                </a>
            </div>
        </div>

        <a href="#" class="mobile-nav-link">
            <i class="material-icons">dashboard</i> Sección 5
        </a>
        <a href="{{ route('mantenimiento.index') }}" class="mobile-nav-link {{ request()->is('admin/mantenimiento*') ? 'active' : '' }}">
            <i class="material-icons">build</i> Mantenimiento
        </a>
        <a href="#" class="mobile-nav-link">
            <i class="material-icons">inventory</i> Sección 7
        </a>
        <div class="mobile-nav-separator"></div>
        <form action="{{ route('logout') }}" method="POST" style="margin: 0;">
            @csrf
            <button type="submit" class="mobile-nav-link mobile-logout" data-no-spa>
                <i class="material-icons">logout</i> Cerrar Sesión
            </button>
        </form>
    </div>

    <!-- Main Content Area -->
    <main class="main-viewport transition-fade">
        @if(session('success'))
            <script>
                window.addEventListener('load', () => {
                    showModal({
                        type: 'success',
                        title: '¡Operación Exitosa!',
                        message: @json(session('success')),
                        confirmText: 'Aceptar',
                        hideCancel: true
                    });
                });
            </script>
        @endif

        @yield('content')
    </main>



    <!-- PDF Preview Modal -->
    <div id="pdfPreviewModal" class="modal-overlay modal-overlay-front">
        <div class="modal-content" style="width: 95%; height: 95vh; max-width: none; padding: 0; display: flex; flex-direction: column; background: #2d3748;">
            <!-- Header (Optimized - Lightweight) -->
            <div style="background: #2d3748; padding: 10px 15px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #4a5568;">
                <h3 id="pdfPreviewTitle" style="margin: 0; color: white; font-size: 14px; font-weight: 600;">Documento</h3>
                
                <div style="display: flex; align-items: center; gap: 8px;">


                    <button id="pdfDownloadBtn" onclick="downloadPdfDirect(this.dataset.url, this.dataset.label)" style="background: #3182ce; border: none; padding: 6px 12px; font-size: 12px; display: flex; align-items: center; gap: 5px; color: white; border-radius: 4px;">
                        <i class="material-icons" style="font-size: 16px;">download</i> Descargar
                    </button>
                    
                    @if(auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('user.edit') || auth()->user()->can('super.admin')))
                    <label id="pdfUpdateLabel" for="pdfUpdateInput" style="background: #059669; border: none; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; color: white; border-radius: 50%; transition: transform 0.2s; cursor: pointer;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'" title="Actualizar Documento">
                        <i class="material-icons" style="font-size: 18px;">add</i>
                        <input type="file" id="pdfUpdateInput" accept="application/pdf" style="display: none;">
                    </label>
                    @endif

                    <button onclick="closePdfPreview()" style="background: none; border: none; color: #cbd5e0; padding: 4px; display: flex; align-items: center; cursor: pointer;">
                        <i class="material-icons" style="font-size: 20px;">close</i>
                    </button>
                </div>
            </div>
            
            <!-- Viewer Container -->
            <div style="flex: 1; background: #4a5568; position: relative; display: flex; overflow: hidden;">
                
                <div style="flex: 1; position: relative; display: flex; align-items: center; justify-content: center;">
                    <!-- Loading Indicator (Same as global preloader) -->
                    <div id="pdfViewerLoader" style="position: absolute; display: flex; flex-direction: column; align-items: center; gap: 15px; z-index: 50;">
                         <div class="spinner-circle"></div>
                         <span style="color: white; font-weight: 500; font-size: 14px;">Cargando documento...</span>
                    </div>
                    
                    <div id="pdfUploadProgressOverlay" style="position: absolute; display: none; flex-direction: column; align-items: center; justify-content: center; gap: 15px; z-index: 60; background: rgba(0,0,0,0.85); inset: 0; backdrop-filter: blur(4px); border-radius: 12px;">
                        <div class="spinner-circle"></div>
                        <div style="text-align: center;">
                            <div id="pdfUploadStatusText" style="color: white; font-weight: 600; font-size: 16px; margin-bottom: 8px;">Subiendo documento</div>
                            <div id="pdfUploadPercentage" style="color: #63b3ed; font-size: 24px; font-weight: 700;">0%</div>
                        </div>
                        <div style="width: 200px; height: 6px; background: rgba(255,255,255,0.2); border-radius: 3px; overflow: hidden;">
                            <div id="pdfUploadProgressBar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #3182ce 0%, #63b3ed 100%); transition: width 0.2s; border-radius: 3px;"></div>
                        </div>
                    </div>
                    
                    <iframe id="pdfPreviewFrame" src="" style="width: 100%; height: 100%; border: none; opacity: 0; transition: opacity 0.3s; position: relative; z-index: 20;" allowfullscreen></iframe>
                    
                    <!-- Vista móvil para descarga directa -->
                    <div id="pdfMobileFallback" style="display: none; flex-direction: column; align-items: center; justify-content: center; z-index: 25; width: 100%; height: 100%; background: #4a5568; padding: 20px; box-sizing: border-box; text-align: center; position: absolute; top:0; left:0;">
                        <i class="material-icons" style="font-size: 64px; color: #a0aec0; margin-bottom: 15px;">description</i>
                        <h4 style="color: white; margin: 0 0 10px 0; font-size: 18px; font-weight: 600;">Vista Previa No Disponible</h4>
                        <p style="color: #cbd5e0; margin: 0 0 25px 0; font-size: 14px; max-width: 280px; line-height: 1.4;">Los teléfonos móviles no soportan la visualización incrustada del documento.</p>
                        <button onclick="downloadPdfDirect(document.getElementById('pdfDownloadBtn').dataset.url, document.getElementById('pdfDownloadBtn').dataset.label)" style="background: #3182ce; color: white; border: none; padding: 12px 24px; font-size: 15px; font-weight: 600; border-radius: 8px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2); cursor: pointer;">
                            <i class="material-icons">download</i> Descargar Documento
                        </button>
                    </div>
                </div>

                <!-- Metadata Side Panel -->
                <div id="pdfMetadataPanel" style="width: 0; background: #2d3748; border-left: 1px solid #4a5568; transition: width 0.3s ease; overflow: hidden; display: flex; flex-direction: column;" class="pdf-metadata-panel-responsive">
                    <div style="padding: 12px; width: 300px; color: white; box-sizing: border-box;">
                        <h4 style="margin: 0 0 15px 0; font-size: 15px; border-bottom: 1px solid #4a5568; padding-bottom: 8px;">Editar Datos del Documento</h4>
                        
                        <div id="metaPanelLoader" style="display: none; justify-content: center; padding: 20px;">
                            <div class="spinner-circle" style="width: 24px; height: 24px; border-width: 2px;"></div>
                        </div>

                        <form id="pdfMetadataForm" onsubmit="saveMetadata(event)" style="display: flex; flex-direction: column; gap: 12px;">
                            <div id="metaFieldsContainer"></div>

                            @if(auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('user.edit') || auth()->user()->can('super.admin')))
                            <button type="submit" id="btnSaveMeta" style="margin-top: 8px; background: #3182ce; color: white; border: none; padding: 8px 12px; border-radius: 6px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; width: 100%; box-sizing: border-box;">
                                <i class="material-icons" style="font-size: 16px;">save</i> Guardar Cambios
                            </button>
                            @endif
                        </form>
                    </div>
                </div>
        </div>
    </div>

    <!-- Standardized Reusable Modal (Moved to End for stacking context) -->
    <div id="standardModal" class="modal-overlay" style="z-index: 1000001 !important;">
        <div class="modal-card">
            <i id="modalIcon" class="material-icons modal-icon" style="color: var(--maquinaria-blue);">help_outline</i>
            <h3 id="modalTitle" class="modal-title">¿Confirmar Acción?</h3>
            <p id="modalMessage" class="modal-message">¿Estás seguro de que deseas realizar esta acción?</p>
            <div class="modal-footer">
                <button id="modalCancelBtn" onclick="closeModal()" class="modal-btn modal-btn-cancel">Cancelar</button>
                <button id="modalConfirmBtn" class="modal-btn modal-btn-confirm">Confirmar</button>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Global Preloader Controls
        window.showPreloader = function() {
            const preloader = document.getElementById('preloader');
            if (preloader) {
                preloader.classList.remove('fade-out');
                preloader.style.display = 'flex';
                // Force visibility properties to ensure it appears on top of everything
                preloader.style.opacity = '1';
                preloader.style.visibility = 'visible';
                preloader.style.zIndex = '1000000';
            }
        };

        window.hidePreloader = function() {
            const preloader = document.getElementById('preloader');
            if (preloader) {
                preloader.classList.add('fade-out');
                setTimeout(() => {
                    if (preloader.classList.contains('fade-out')) {
                        preloader.style.display = 'none';
                    }
                }, 100);
            }
        };

        // Handle logout - show preloader during redirect to login -> DISABLED to avoid double load
        document.addEventListener('DOMContentLoaded', () => {
            // Handle logout - prevent double click 419 error
            const logoutForms = document.querySelectorAll('form[action$="logout"]');
            logoutForms.forEach(form => {
                form.addEventListener('submit', function() {
                    const btn = this.querySelector('button[type="submit"]');
                    if(btn) {
                        btn.style.pointerEvents = 'none';
                        btn.style.opacity = '0.5';
                        // Don't disable completely or it might not submit in some browsers, 
                        // just block pointer events and multiple submits
                    }
                });
            });

            // Prevent FOUC for Material Icons
            document.fonts.ready.then(function() {
                document.body.classList.add('fonts-loaded');
            });

            // GLOBAL EVENT DELEGATION FOR EQUIPOS MODULE (SPA COMPATIBLE)
            // This ensures that "Acciones" and "Filter" buttons work even after AJAX content replacement
            window.equiposGlobalClickHandler = function(event) {
                // GUARD: Este handler solo actúa en la página de Equipos
                // (donde existe #splitDropdownMenu). En otras páginas (movilizaciones, etc.)
                // salimos inmediatamente para no interferir con sus propios handlers.
                const isEquiposPage = !!document.getElementById('splitDropdownMenu');
                if (!isEquiposPage) return;

                // Toggle Acciones Dropdown
                if (event.target.closest('#btnAcciones')) {
                    event.preventDefault();
                    event.stopPropagation();
                    const menu = document.getElementById('splitDropdownMenu');
                    const panel = document.getElementById('advancedFilterPanel');
                    
                    if (panel) panel.style.display = 'none'; 
                    
                    if (menu) {
                        const isHidden = menu.style.display === 'none' || menu.style.display === '';
                        menu.style.display = isHidden ? 'block' : 'none';
                    }
                    return;
                }

                // Toggle Advanced Filter Panel
                if (event.target.closest('#btnAdvancedFilter')) {
                    event.preventDefault();
                    event.stopPropagation();
                    const panel = document.getElementById('advancedFilterPanel');
                    const menu = document.getElementById('splitDropdownMenu');
                    
                    if (menu) menu.style.display = 'none'; 
                    
                    if (panel) {
                        const isHidden = panel.style.display === 'none' || panel.style.display === '';
                        panel.style.display = isHidden ? 'block' : 'none';
                    }
                    return;
                }

                // Close when clicking outside (solo en página de equipos)
                if (!event.target.closest('#advancedFilterPanel') && 
                    !event.target.closest('#splitDropdownMenu') &&
                    !event.target.closest('#btnAcciones') && 
                    !event.target.closest('#btnAdvancedFilter')) {
                    
                    const menu = document.getElementById('splitDropdownMenu');
                    const panel = document.getElementById('advancedFilterPanel');
                    if (menu) menu.style.display = 'none';
                    if (panel) panel.style.display = 'none';
                }
            };

            // Global Keyup for Filters
            window.equiposGlobalKeyupHandler = function(event) {
                if (event.target && event.target.id === 'searchModelInput') {
                    const filter = event.target.value.toLowerCase();
                    const list = document.getElementById('modelList');
                    if (!list) return;
                    const items = list.getElementsByClassName('filter-option-item');

                    for (let i = 0; i < items.length; i++) {
                        const txtValue = items[i].textContent || items[i].innerText;
                        items[i].style.display = txtValue.toLowerCase().indexOf(filter) > -1 ? "" : "none";
                    }
                }
            };

            // Clean & Attach Global Listeners
            document.removeEventListener('click', window.equiposGlobalClickHandler);
            document.addEventListener('click', window.equiposGlobalClickHandler);
            
            document.removeEventListener('keyup', window.equiposGlobalKeyupHandler);
            document.addEventListener('keyup', window.equiposGlobalKeyupHandler);
            
        });

    </script>
    
    {{-- Core Scripts (Always Loaded) --}}

    <script src="{{ asset('js/maquinaria/module_manager.js') }}?v=2.2"></script>
    <script src="{{ asset('js/maquinaria/uicomponents.js') }}?v=18.3"></script>
    <script src="{{ asset('js/maquinaria/navegacion.js') }}?v=11.0"></script>
    <script src="{{ asset('js/maquinaria/form_logic.js') }}?v=4.2"></script>
    <script src="{{ asset('js/maquinaria/equipo_catalog_linking.js') }}?v=3.2"></script>
    
    {{-- Module Scripts (Global Load for SPA Navigation) --}}
    {{-- NOTE: These MUST be loaded globally because the SPA navigation --}}
    {{-- calls functions like window.loadEquipos(), window.loadCatalogo(), etc. --}}
    {{-- from navegacion.js when switching between pages without reload --}}
    <script src="{{ asset('js/maquinaria/menu.js') }}?v=5.2"></script>
    <script src="{{ asset('js/maquinaria/catalogo_create.js') }}?v=12.2"></script>
    <script src="{{ asset('js/maquinaria/equipos_index.js') }}?v=21.2"></script>
    <script src="{{ asset('js/maquinaria/catalogo_index.js') }}?v=4.0"></script>
    <script src="{{ asset('js/maquinaria/movilizaciones_index.js') }}?v=9.1"></script>
    <script src="{{ asset('js/maquinaria/usuarios_index.js') }}?v=10.2"></script>
    <script src="{{ asset('js/maquinaria/fleet_dashboard.js') }}?v=106.4"></script>

    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
    <script src="{{ asset('js/maquinaria/frentes_spa.js') }}?v=4.3"></script>
    <script src="{{ asset('js/maquinaria/consumibles_index.js') }}?v=5.1"></script>
    <script>
        function toggleMobileMenu() {
            const menu = document.getElementById('mobileMenu');
            if (menu) menu.classList.toggle('active');
        }

        // Toggle Mobile Group
        document.addEventListener('DOMContentLoaded', () => {
            const configGroup = document.getElementById('mobileConfigGroup');
            if (configGroup) {
                const title = configGroup.querySelector('.mobile-nav-group-title');
                title.onclick = (e) => {
                    e.stopPropagation();
                    configGroup.classList.toggle('active');
                };
            }
        });

        // Dropdown Click Interaction
        document.addEventListener('DOMContentLoaded', () => {
            const dropdowns = document.querySelectorAll('.nav-dropdown');
            
            dropdowns.forEach(dropdown => {
                const trigger = dropdown.querySelector('.nav-link');
                
                trigger.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // Close other dropdowns
                    dropdowns.forEach(d => {
                        if (d !== dropdown) d.classList.remove('active');
                    });
                    
                    dropdown.classList.toggle('active');
                });
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.nav-dropdown')) {
                    dropdowns.forEach(d => d.classList.remove('active'));
                }
            });

            // Close dropdown when a link inside it is clicked
            const dropdownLinks = document.querySelectorAll('.nav-dropdown-link');
            dropdownLinks.forEach(link => {
                link.addEventListener('click', () => {
                    dropdowns.forEach(d => d.classList.remove('active'));
                });
            });
        });

        // Modal Logic
        let modalCallback = null;

        /**
         * Generic Modal System
         * @param {Object} options { type, title, message, onConfirm, onCancel, confirmText, cancelText, hideCancel }
         */
        window.showModal = function(options) {
            const config = {
                type: 'info', // success, error, warning, info
                title: 'Aviso',
                message: '',
                confirmText: 'Aceptar',
                cancelText: 'Cancelar',
                hideCancel: false,
                onConfirm: null,
                onCancel: null,
                ...options
            };

            const modalEl = document.getElementById('standardModal');
            const iconEl = document.getElementById('modalIcon');
            const titleEl = document.getElementById('modalTitle');
            const messageEl = document.getElementById('modalMessage');
            const confirmBtn = document.getElementById('modalConfirmBtn');
            const cancelBtn = document.getElementById('modalCancelBtn');

            // Set content
            titleEl.innerText = config.title;
            messageEl.innerHTML = config.message;
            confirmBtn.innerText = config.confirmText;
            cancelBtn.innerText = config.cancelText;
            cancelBtn.style.display = config.hideCancel ? 'none' : 'block';

            // Set Icon and colors
            iconEl.className = 'material-icons modal-icon';
            confirmBtn.className = 'modal-btn modal-btn-confirm';
            
            switch(config.type) {
                case 'success':
                    iconEl.innerText = 'check_circle';
                    iconEl.classList.add('modal-icon-success');
                    confirmBtn.classList.add('btn-success');
                    break;
                case 'error':
                    iconEl.innerText = 'error';
                    iconEl.classList.add('modal-icon-error');
                    confirmBtn.classList.add('btn-danger');
                    break;
                case 'warning':
                    iconEl.innerText = 'warning';
                    iconEl.classList.add('modal-icon-warning');
                    confirmBtn.classList.add('btn-warning');
                    break;
                default:
                    iconEl.innerText = 'help_outline';
                    iconEl.classList.add('modal-icon-info');
            }

            modalCallback = config.onConfirm;
            
            // Show modal
            modalEl.classList.add('active');

            // Auto-close success modal after 3s (unless disabled)
            if (config.type === 'success' && !config.disableAutoClose) {
                setTimeout(() => {
                    const modalEl = document.getElementById('standardModal');
                    if (modalEl && modalEl.classList.contains('active')) {
                        const confirmBtn = document.getElementById('modalConfirmBtn');
                        if (confirmBtn) confirmBtn.click();
                    }
                }, 3000);
            }

            // Handle confirm
            confirmBtn.onclick = () => {
                if (modalCallback) modalCallback();
                closeModal();
            };
        }

        window.closeModal = function() {
            const modalEl = document.getElementById('standardModal');
            if (modalEl) modalEl.classList.remove('active');
            modalCallback = null;
        }

        // Legacy compatibility helper
        window.showConfirmModal = function(title, message, callback, btnText = 'Eliminar') {
            window.showModal({
                type: 'error',
                title: title,
                message: message,
                confirmText: btnText,
                onConfirm: callback
            });
        }

        // --- Custom UI Components (SPA Friendly) ---
        // Moved to js/maquinaria/uicomponents.js to ensure availability before other scripts


        // --- Equipos / Vehículos Specific Logic (Globalized for SPA) ---
        // Tab Logic (Updated for 3 Tabs)
        window.switchModalTab = function(tabName) {
            // Hide all content
            const contentGeneral = document.getElementById('tab_content_general');
            const contentSpecs = document.getElementById('tab_content_specs');
            const contentLegal = document.getElementById('tab_content_legal');

            if(contentGeneral) contentGeneral.style.display = 'none';
            if(contentSpecs) contentSpecs.style.display = 'none';
            if(contentLegal) contentLegal.style.display = 'none';
            
            // Reset Buttons
            const btnGeneral = document.getElementById('tab_btn_general');
            const btnSpecs = document.getElementById('tab_btn_specs');
            const btnLegal = document.getElementById('tab_btn_legal');
            
            const inactiveStyle = "flex: 1; padding: 12px; background: none; border: none; border-bottom: 3px solid transparent; font-weight: 600; color: #64748b; cursor: default; transition: all 0.2s; outline: none;";
            const activeStyle = "flex: 1; padding: 12px; background: none; border: none; border-bottom: 3px solid var(--maquinaria-blue); font-weight: 700; color: var(--maquinaria-blue); cursor: default; transition: all 0.2s; outline: none;";

            if(btnGeneral) btnGeneral.style.cssText = inactiveStyle;
            if(btnSpecs) btnSpecs.style.cssText = inactiveStyle;
            if(btnLegal) btnLegal.style.cssText = inactiveStyle;

            // Activate Target
            if(tabName === 'general') {
                if(contentGeneral) contentGeneral.style.display = 'block';
                if(btnGeneral) btnGeneral.style.cssText = activeStyle;
            } else if(tabName === 'specs') {
                if(contentSpecs) contentSpecs.style.display = 'block';
                if(btnSpecs) btnSpecs.style.cssText = activeStyle;
            } else {
                if(contentLegal) contentLegal.style.display = 'block';
                if(btnLegal) btnLegal.style.cssText = activeStyle;
            }
        };

        // showDetailsImproved and closeDetailsModal are defined in uicomponents.js (loaded after)

        // --- PDF Preview System (Internal View) - OPTIMIZED ---
        
        // Optimized Direct PDF Download with visual feedback
        window.downloadPdfDirect = function(url, documentLabel) {
            if (!url) {
                alert('No hay URL para descargar');
                return;
            }
            
            const downloadBtn = document.getElementById('pdfDownloadBtn');
            
            // Show loading state
            if (downloadBtn) {
                downloadBtn.disabled = true;
                downloadBtn.innerHTML = '<span class="material-icons" style="font-size: 16px; animation: spin 1s linear infinite;">sync</span> Descargando...';
            }
            
            // Generate filename
            let filename = 'documento.pdf';
            if (documentLabel) {
                const cleanLabel = documentLabel.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
                filename = cleanLabel + '.pdf';
            }
            
            // Direct download link
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            a.setAttribute('data-no-spa', 'true');
            a.style.display = 'none';
            
            document.body.appendChild(a);
            a.click();
            
            // Restore button after short delay
            setTimeout(() => {
                document.body.removeChild(a);
                if (downloadBtn) {
                    downloadBtn.disabled = false;
                    downloadBtn.innerHTML = '<span class="material-icons" style="font-size: 16px;">download</span> Descargar';
                }
            }, 800);
        };
        
        window.openPdfPreview = function(url, docType, label, equipoId) {
            const modal = document.getElementById('pdfPreviewModal');
            const iframe = document.getElementById('pdfPreviewFrame');
            const title = document.getElementById('pdfPreviewTitle');
            const downloadBtn = document.getElementById('pdfDownloadBtn');
            const updateInput = document.getElementById('pdfUpdateInput');
            const loader = document.getElementById('pdfViewerLoader');

            // Mostrar spinner global — se oculta cuando el PDF cargue en el iframe
            if (typeof window.showPreloader === 'function') window.showPreloader();
            
            // Abrir modal de PDF
            if(modal) modal.classList.add('active');
            // NO ocultamos el preloader aquí — esperamos al onload del iframe
            
            // Show Loader
            if(loader) {
                loader.style.display = 'flex';
                loader.style.opacity = '1';
            }
            
            if(iframe) {
                iframe.style.opacity = '0';
                iframe.src = '';
            }
            
            const fallbackNode = document.getElementById('pdfMobileFallback');
            if (fallbackNode) fallbackNode.style.display = 'none';

            // Set Content
            if(title) title.innerText = label || 'Documento';
            if(downloadBtn) {
                downloadBtn.dataset.url = url;
                downloadBtn.dataset.label = label || 'documento';
                if(!url || url.length < 5) {
                    downloadBtn.style.display = 'none';
                } else {
                    downloadBtn.style.display = 'flex';
                }
            }
            
            // Track timing to ensure loader shows for minimum duration
            const loaderStartTime = Date.now();
            const minimumLoaderDuration = 800; // Minimum time (ms) to show loader
            
            // Fallback: ocultar spinner y loader tras 5s máximo
            const loaderTimeout = setTimeout(() => {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
                if(loader) loader.style.display = 'none';
                if(iframe) iframe.style.opacity = '1';
            }, 5000);
            
            // Hide loader only when BOTH conditions are met:
            // 1. iframe.onload has fired
            // 2. Minimum duration has elapsed
            const hideLoaderWhenReady = () => {
                const elapsed = Date.now() - loaderStartTime;
                const remainingTime = Math.max(0, minimumLoaderDuration - elapsed);
                
                setTimeout(() => {
                    clearTimeout(loaderTimeout);
                    // Ocultar spinner global al terminar de cargar el PDF
                    if (typeof window.hidePreloader === 'function') window.hidePreloader();
                    if(loader) {
                        loader.style.opacity = '0';
                        setTimeout(() => { 
                            if(loader) loader.style.display = 'none'; 
                        }, 200);
                    }
                    if(iframe) iframe.style.opacity = '1';
                }, remainingTime);
            };
            
            // Set source and setup load listener
            if(iframe) {
                iframe.onload = function() {
                    hideLoaderWhenReady();
                };
                
                iframe.onerror = function() {
                    clearTimeout(loaderTimeout);
                    if(loader) loader.style.display = 'none';
                    showModal({ 
                        type: 'error', 
                        title: 'Error', 
                        message: 'No se pudo cargar la vista previa del documento.', 
                        confirmText: 'Cerrar', 
                        hideCancel: true 
                    });
                };
                
                if (url && url.length > 5) {
                    const isMobileDevice = window.innerWidth <= 768 || /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
                    const fallback = document.getElementById('pdfMobileFallback');
                    
                    if (isMobileDevice) {
                        // Mostrar pantalla nativa de descarga en vez de GDocs
                        iframe.style.display = 'none';
                        if (fallback) fallback.style.display = 'flex';
                        
                        // Quitar el spinner porque no cargaremos iframe
                        clearTimeout(loaderTimeout);
                        if(loader) loader.style.display = 'none';
                        if (typeof window.hidePreloader === 'function') window.hidePreloader();
                    } else {
                        if (fallback) fallback.style.display = 'none';
                        iframe.style.display = 'block';
                        iframe.src = url + '#toolbar=0&navpanes=0&scrollbar=0&zoom=100';
                    }
                } else {
                    const fallback = document.getElementById('pdfMobileFallback');
                    if (fallback) fallback.style.display = 'none';
                    
                    iframe.style.display = 'block';
                    iframe.src = 'about:blank';
                    if(loader) loader.style.display = 'none';
                }
            }
            
            // Setup Update Input
            if(updateInput) {
                updateInput.onchange = function() {
                    uploadDocumentFromPreview(this, docType, equipoId, label);
                };
            }
            
            // Store current context for metadata panel
            window.currentPdfContext = { equipoId, docType, label };

            // Auto-open metadata panel on desktop only (no ocultar el PDF en móviles)
            const panel = document.getElementById('pdfMetadataPanel');
            if (panel) {
                panel.style.width = '0';
                setTimeout(() => {
                    const isMobile = window.innerWidth <= 768;
                    if (!isMobile) {
                        panel.style.width = '300px';
                        loadMetadata();
                    }
                }, 400);
            }
        };

        // Permission Flag (Global & Exposed to External Scripts)
        window.CAN_UPDATE_INFO = {{ auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('user.edit') || auth()->user()->can('super.admin')) ? 'true' : 'false' }};
        window.CAN_CREATE_EQUIPOS = {{ auth()->user() && auth()->user()->can('equipos.create') ? 'true' : 'false' }};
        window.CAN_ASSIGN_EQUIPOS = {{ auth()->user() && auth()->user()->can('equipos.assign') ? 'true' : 'false' }};
        window.CAN_CHANGE_STATUS = {{ auth()->user() && auth()->user()->can('equipos.edit') ? 'true' : 'false' }};

        // --- Metadata Side Panel Logic ---
        window.loadMetadata = async function() {
            const ctx = window.currentPdfContext;
            if (!ctx) return;
            const container = document.getElementById('metaFieldsContainer');
            const loader = document.getElementById('metaPanelLoader');
            const form = document.getElementById('pdfMetadataForm');
            if (loader) loader.style.display = 'flex';
            if (form) form.style.opacity = '0.5';
            try {
                const res = await fetch(`/admin/equipos/${ctx.equipoId}/metadata?type=${ctx.docType}`);
                const data = await res.json();
                if (data.success) {
                    const info = data.data;
                    let html = '';
                    const commonInputStyle = "background: #4a5568; border: 1px solid #718096; color: white; padding: 6px 8px; border-radius: 4px; width: 100%; box-sizing: border-box; font-size: 13px; height: 32px;";
                    const labelStyle = "display: block; font-size: 12px; color: #cbd5e0; margin-bottom: 4px; font-weight: 600;";
                    const containerStyle = "margin-bottom: 12px;";
                    const disabledAttr = !window.CAN_UPDATE_INFO ? `disabled style="${commonInputStyle} opacity: 0.7; cursor: not-allowed;"` : `style="${commonInputStyle}"`;
                    if (ctx.docType === 'propiedad') {
                        html += `
                            <div style="${containerStyle}"><label style="${labelStyle}">Nro. Documento</label><input type="text" name="nro_documento" value="${info.nro_documento || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label style="${labelStyle}">Titular</label><input type="text" name="titular" value="${info.titular || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label style="${labelStyle}">Placa</label><input type="text" name="placa" value="${info.placa || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label style="${labelStyle}">Marca</label><input type="text" name="marca" value="${info.marca || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label style="${labelStyle}">Modelo</label><input type="text" name="modelo" value="${info.modelo || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label style="${labelStyle}">Serial Chasis</label><input type="text" name="serial_chasis" value="${info.serial_chasis || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label style="${labelStyle}">Serial Motor</label><input type="text" name="serial_motor" value="${info.serial_motor || ''}" ${disabledAttr} autocomplete="off"></div>
                        `;
                    } else if (ctx.docType === 'poliza') {
                        let datalistOptions = '';
                        let currentInsurerName = '';
                        if (info.insurers) {
                            info.insurers.forEach(ins => {
                                datalistOptions += `<option value="${ins.NOMBRE_ASEGURADORA}">`;
                                if (ins.ID_SEGURO == info.id_seguro) currentInsurerName = ins.NOMBRE_ASEGURADORA;
                            });
                        }
                        html += `
                            <div style="${containerStyle}"><label style="${labelStyle}">Fecha Vencimiento</label><input type="date" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}">
                                <label style="${labelStyle}">Aseguradora <small style="color:#94a3b8;font-weight:400;">(Seleccionar o escribir nueva)</small></label>
                                <input type="text" name="nombre_aseguradora" list="insurersList_${ctx.equipoId}" value="${currentInsurerName || ''}" placeholder="Escriba o seleccione..." ${disabledAttr} autocomplete="off">
                                <datalist id="insurersList_${ctx.equipoId}">${datalistOptions}</datalist>
                            </div>
                        `;
                    } else if (ctx.docType === 'rotc' || ctx.docType === 'racda') {
                        html += `<div style="${containerStyle}"><label style="${labelStyle}">Fecha Vencimiento</label><input type="date" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>`;
                    }
                    container.innerHTML = html;
                }
            } catch (e) {
                console.error(e);
                container.innerHTML = '<span style="color:#fc8181;">Error al cargar datos.</span>';
            } finally {
                if (loader) loader.style.display = 'none';
                if (form) form.style.opacity = '1';
            }
        };

        window.saveMetadata = async function(e) {
            e.preventDefault();
            if (!window.CAN_UPDATE_INFO) {
                if (window.showToast) window.showToast('No tienes permisos para actualizar', 'error');
                return;
            }
            const ctx = window.currentPdfContext;
            const btn = document.getElementById('btnSaveMeta');
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="material-icons" style="font-size:16px;">hourglass_empty</i> Guardando...';
            btn.disabled = true;
            try {
                const formData = new FormData(e.target);
                formData.append('doc_type', ctx.docType);
                const res = await fetch(`/admin/equipos/${ctx.equipoId}/update-metadata`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'), 'Accept': 'application/json' },
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    if (window.showToast) window.showToast('Datos actualizados correctamente', 'success');
                    if (window.activeEquipoButton) {
                        const d = window.activeEquipoButton.dataset;
                        if (ctx.docType === 'propiedad') {
                            d.nroDoc = formData.get('nro_documento'); d.titular = formData.get('titular');
                            d.placa = formData.get('placa'); d.marca = formData.get('marca');
                            d.modelo = formData.get('modelo'); d.chasis = formData.get('serial_chasis');
                            d.motorSerial = formData.get('serial_motor');
                        } else if (ctx.docType === 'poliza') {
                            d.vencSeguro = formData.get('fecha_vencimiento'); d.seguro = formData.get('nombre_aseguradora');
                        } else if (ctx.docType === 'rotc') { d.fechaRotc = formData.get('fecha_vencimiento'); }
                          else if (ctx.docType === 'racda') { d.fechaRacda = formData.get('fecha_vencimiento'); }
                        showDetailsImproved(window.activeEquipoButton);
                    }
                    if (typeof window.refreshDashboardAlerts === 'function') window.refreshDashboardAlerts();
                } else { throw new Error(data.message); }
            } catch (error) {
                console.error(error);
                if (window.showToast) window.showToast('Error: No se pudieron guardar los cambios', 'error');
            } finally {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        };

        window.closePdfPreview = function() {
            const modal = document.getElementById('pdfPreviewModal');
            const iframe = document.getElementById('pdfPreviewFrame');
            if(modal) modal.classList.remove('active');
            if(iframe) {
                iframe.src = ''; // Clear source to free memory
            }
        };

        // Special Upload Handler for Preview Modal (XMLHttpRequest for Progress)
        window.uploadDocumentFromPreview = function(input, type, equipoId, label) {
            // PERMISSION CHECK
            if (!window.CAN_UPDATE_INFO) {
                input.value = ''; // Clear input
                if (window.showToast) window.showToast('No tienes permisos para actualizar documentos', 'error');
                return;
            }

            if (!input.files || !input.files[0]) return;
            const file = input.files[0];
            
            // Show upload progress overlay
            const progressOverlay = document.getElementById('pdfUploadProgressOverlay');
            const progressBar = document.getElementById('pdfUploadProgressBar');
            const progressPercentage = document.getElementById('pdfUploadPercentage');
            
            if(progressOverlay) progressOverlay.style.display = 'flex';
            if(progressBar) progressBar.style.width = '0%';
            if(progressPercentage) progressPercentage.innerText = '0%';
            
            const formData = new FormData();
            formData.append('file', file);
            formData.append('doc_type', type);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', `/admin/equipos/${equipoId}/upload-doc`, true);
            xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
            xhr.setRequestHeader('Accept', 'application/json');

            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percentComplete = Math.round((e.loaded / e.total) * 100);
                    if(progressBar) progressBar.style.width = percentComplete + '%';
                    
                    const statusText = document.getElementById('pdfUploadStatusText');
                    if (percentComplete === 100) {
                        if(statusText) statusText.innerText = 'Guardando...';
                        if(progressPercentage) progressPercentage.innerText = 'Procesando...';
                    } else {
                        if(statusText) statusText.innerText = 'Subiendo documento';
                        if(progressPercentage) progressPercentage.innerText = percentComplete + '%';
                    }
                }
            };

            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        const data = JSON.parse(xhr.responseText);
                        
                        if (data.success) {
                            // Update status text while iframe loads
                            const statusText = document.getElementById('pdfUploadStatusText');
                            if(statusText) statusText.innerText = 'Abriendo vista previa...';
                            if(progressPercentage) progressPercentage.innerText = 'Listo';

                            // Get iframe reference
                            const iframe = document.getElementById('pdfPreviewFrame');
                            
                            // Update iframe to show new PDF
                            if(iframe) {
                                iframe.style.opacity = '0';
                                
                                // Setup load handler for new PDF to hide overlay ONLY when ready
                                iframe.onload = function() {
                                    if(progressOverlay) {
                                        progressOverlay.style.opacity = '0';
                                        setTimeout(() => {
                                            progressOverlay.style.display = 'none';
                                            progressOverlay.style.opacity = '1';
                                        }, 300);
                                    }
                                    iframe.style.opacity = '1';
                                    
                                    // Reset status text for next time
                                    if(statusText) statusText.innerText = 'Subiendo documento';
                                };
                                
                                // Load new PDF with force-refresh since file changed
                                iframe.src = data.link + '?upd=' + new Date().getTime() + '#toolbar=0&navpanes=0&scrollbar=0&zoom=100';
                            }
                            
                            // Update Download Button
                            const downloadBtn = document.getElementById('pdfDownloadBtn');
                            if(downloadBtn) downloadBtn.dataset.url = data.link;

                            // Update Parent Button Data (Table Row)
                            if (window.activeEquipoButton) {
                                const d = window.activeEquipoButton.dataset;
                                if (type === 'propiedad') d.linkPropiedad = data.link;
                                if (type === 'poliza') d.linkSeguro = data.link;
                                if (type === 'rotc') d.linkRotc = data.link;
                                if (type === 'racda') d.linkRacda = data.link;
                                if (type === 'adicional') d.linkAdicional = data.link;
                            }

                            // FIX: Also update the button in the currently open Details Modal
                            // to prevent opening the old file if the user closes and re-opens preview.
                            let containerId = '';
                            if (type === 'propiedad') containerId = 'd_btn_propiedad';
                            else if (type === 'poliza') containerId = 'd_btn_poliza';
                            else if (type === 'rotc') containerId = 'd_btn_rotc';
                            else if (type === 'racda') containerId = 'd_btn_racda';
                            else if (type === 'adicional') containerId = 'd_btn_adicional';

                            const btnContainer = document.getElementById(containerId);
                            if (btnContainer) {
                                btnContainer.innerHTML = `
                                    <div class="pdf-btn-container">
                                        <button type="button" 
                                            onclick="openPdfPreview('${data.link}', '${type}', '${label}', '${equipoId}')" 
                                            style="width: 36px; height: 36px; border-radius: 8px; background: #f8f9fa; border: 1px solid #dee2e6; display: flex; align-items: center; justify-content: center; transition: all 0.2s;"
                                            onmouseover="this.style.background='#e9ecef'" 
                                            onmouseout="this.style.background='#f8f9fa'"
                                            title="Ver PDF: ${label}">
                                            <i class="material-icons" style="font-size: 20px; color: #6c757d;">picture_as_pdf</i>
                                        </button>
                                    </div>
                                `;
                            }


                            if (window.showToast) window.showToast('Documento actualizado exitosamente', 'success');
                            
                            // Refresh Dashboard Alerts if function exists
                            if (typeof window.refreshDashboardAlerts === 'function') {
                                window.refreshDashboardAlerts();
                            }
                        } else {
                            throw new Error(data.message);
                        }
                    } catch (error) {
                         console.error(error);
                         if(progressOverlay) progressOverlay.style.display = 'none';
                         if (window.showToast) window.showToast('Error: Respuesta inválida del servidor', 'error');
                    }
                } else {
                    if(progressOverlay) progressOverlay.style.display = 'none';
                    if (window.showToast) window.showToast('Error al cargar documento', 'error');
                }
            };
            
            xhr.onerror = function() {
                const progressOverlay = document.getElementById('pdfUploadProgressOverlay');
                if(progressOverlay) progressOverlay.style.display = 'none';
                if (window.showToast) window.showToast('Error de red', 'error');
            };

            xhr.send(formData);
        };

        window.filterDropdownOptions = function(input) {
            const filter = input.value.toUpperCase();
            // Generic lookup relative to input
            const wrapper = input.closest('.custom-dropdown');
            if(!wrapper) return;
            const container = wrapper.querySelector('.dropdown-item-list');
            if(!container) return;
            
            const items = container.getElementsByClassName('dropdown-item');
            
            for (let i = 0; i < items.length; i++) {
                const txtValue = items[i].textContent || items[i].innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    items[i].style.display = "";
                } else {
                    items[i].style.display = "none";
                }
            }
        };
        
        // Delete Document Logic
        window.confirmDeleteDocument = function(equipoId, docType, label) {
             showModal({
                type: 'error',
                title: '¿Eliminar Documento?',
                message: `¿Estás seguro de que deseas eliminar "${label}"? Esta acción no se puede deshacer.`,
                confirmText: 'Eliminar',
                onConfirm: async () => {
                     // PERMISSION CHECK
                     if (!window.CAN_UPDATE_INFO) {
                        if (window.showToast) window.showToast("No tienes permisos para eliminar documentos.", "error");
                        return;
                    }

                     try {
                        const response = await fetch(`/admin/equipos/${equipoId}/delete-doc`, {
                            method: 'DELETE',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                                'Content-Type': 'application/json'
                            },
                             body: JSON.stringify({ doc_type: docType })
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                             // Close Preview
                             closePdfPreview();
                             
                             // Update UI to show upload button again
                              if (window.activeEquipoButton) {
                                const d = window.activeEquipoButton.dataset;
                                const containerId = `d_btn_${docType}`; // Assuming this naming convention from showDetailsImproved
                                const container = document.getElementById(containerId);
                                
                                // Reset dataset
                                if (docType === 'propiedad') d.linkPropiedad = '';
                                if (docType === 'poliza') d.linkSeguro = '';
                                if (docType === 'rotc') d.linkRotc = '';
                                if (docType === 'racda') d.linkRacda = '';

                                // Render Upload Button
                                if(container) {
                                     const inputId = `input_upload_${docType}_${equipoId}`;
                                     const inputHtml = `<input type="file" id="${inputId}" accept="application/pdf" style="display: none;" onchange="uploadDocument(this, '${docType}', '${equipoId}', '${containerId}', '${label}')">`;
                                     
                                     container.innerHTML = `
                                        <div style="position: relative; width: 30px; height: 30px;">
                                            ${inputHtml}
                                            <label for="${inputId}" style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; background: #fbfcfd; color: #3b82f6; border: 1px dashed #3b82f6; border-radius: 6px; transition: 0.2s;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='#fbfcfd'" title="Cargar ${label}">
                                                <i class="material-icons" style="font-size: 18px;">cloud_upload</i>
                                            </label>
                                        </div>
                                    `;
                                }
                             }
                             
                             if (window.showToast) window.showToast("Documento eliminado correctamente.", "success");
                        } else {
                             throw new Error(data.message);
                        }
                     } catch (error) {
                          console.error(error);
                          if (window.showToast) window.showToast("No se pudo eliminar el documento.", "error");
                     }
                }
            });
        };

        window.confirmDeleteEquipo = function(id) {
            showModal({
                type: 'error',
                title: '¿Eliminar equipo?',
                message: '¿Estás seguro de eliminar este equipo? Esta acción no se puede deshacer.',
                confirmText: 'Eliminar',
                onConfirm: () => {
                    var form = document.getElementById('delete-form-global');
                    if(form) {
                        form.action = '/admin/equipos/' + id;
                        form.submit();
                    }
                }
            });
        };

        // showPreloader / hidePreloader are defined earlier in this file (with fade-out animation)

        // Re-initialize dynamic elements after SPA load
        window.addEventListener('spa:contentLoaded', () => {
            window.updateSelectedCount();
        });

        // Auto-submit search when selecting from datalist
        window.checkAutoSubmit = function(input) {
            const val = input.value.trim().toUpperCase();
            if (!val) return;

            const listId = input.getAttribute('list');
            if (!listId) return;

            const datalist = document.getElementById(listId);
            if (!datalist) return;

            const options = Array.from(datalist.options).map(opt => opt.value.trim().toUpperCase());
            
            if (options.includes(val)) {
                const form = input.closest('form');
                if (form) {
                    if(window.showPreloader) window.showPreloader();
                    form.submit();
                }
            }
        };

        // Clear filter without reload or query - just clear UI
        window.clearFilter = function(filterName) {
            // Cancel any pending search timeout
            if (window.searchTimeout) {
                clearTimeout(window.searchTimeout);
            }
            
            // Clear input fields and reset UI
            if (filterName === 'id_frente') {
                const input = document.getElementById('input_frente_filter');
                if (input) input.value = '';
                const searchInput = document.getElementById('filterSearchInput');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.placeholder = 'Filtrar Frente...';
                }
                const trigger = searchInput?.closest('.dropdown-trigger');
                if (trigger) {
                    trigger.style.background = '#fbfcfd';
                    trigger.style.borderColor = '#cbd5e0';
                }
                const clearBtn = document.getElementById('btn_clear_frente');
                if (clearBtn) clearBtn.style.display = 'none';

            } else if (filterName === 'id_tipo') {
                const input = document.getElementById('input_tipo_filter');
                if (input) input.value = '';
                const searchInput = document.getElementById('filterTipoSearchInput');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.placeholder = 'Filtrar Tipo...';
                }
                const trigger = searchInput?.closest('.dropdown-trigger');
                if (trigger) {
                    trigger.style.background = '#fbfcfd';
                    trigger.style.borderColor = '#cbd5e0';
                }
                const clearBtn = document.getElementById('btn_clear_tipo');
                if (clearBtn) clearBtn.style.display = 'none';

            } else if (filterName === 'modelo') {
                // Catalog - Modelo filter
                const input = document.getElementById('input_modelo_filter');
                if (input) input.value = '';
                const searchInput = document.getElementById('searchModeloInput');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.placeholder = 'Buscar Modelo...';
                }
                const trigger = searchInput?.closest('.dropdown-trigger');
                if (trigger) {
                    trigger.style.background = '#fbfcfd';
                    trigger.style.borderColor = '#cbd5e0';
                }
                const clearBtn = document.getElementById('btn_clear_modelo');
                if (clearBtn) clearBtn.style.display = 'none';
                
                // Clear selection in dropdown
                const dropdown = document.getElementById('modeloFilterSelect');
                if (dropdown) {
                    dropdown.querySelectorAll('.filter-option-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                }
                
                // Trigger catalog reload
                if (typeof window.loadCatalogo === 'function') {
                    window.loadCatalogo();
                }
                return;

            } else if (filterName === 'anio') {
                // Catalog - Año filter
                const input = document.getElementById('input_anio_filter');
                if (input) input.value = '';
                const searchInput = document.getElementById('searchAnioInput');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.placeholder = 'Buscar Año...';
                }
                const trigger = searchInput?.closest('.dropdown-trigger');
                if (trigger) {
                    trigger.style.background = '#fbfcfd';
                    trigger.style.borderColor = '#cbd5e0';
                }
                const clearBtn = document.getElementById('btn_clear_anio');
                if (clearBtn) clearBtn.style.display = 'none';
                
                // Clear selection in dropdown
                const dropdown = document.getElementById('anioFilterSelect');
                if (dropdown) {
                    dropdown.querySelectorAll('.filter-option-item').forEach(item => {
                        item.classList.remove('selected');
                    });
                }
                
                // Trigger catalog reload
                if (typeof window.loadCatalogo === 'function') {
                    window.loadCatalogo();
                }
                return;

            } else if (filterName === 'search_query' || filterName === 'search') {
                const input = document.getElementById('searchInput');
                if (input) {
                    // Temporarily disable onkeyup to prevent auto-submit
                    const originalOnkeyup = input.onkeyup;
                    input.onkeyup = null;
                    input.value = '';
                    // Restore onkeyup after a short delay
                    setTimeout(() => {
                        input.onkeyup = originalOnkeyup;
                    }, 100);
                }
                const wrapper = input?.closest('.search-wrapper');
                if (wrapper) {
                    wrapper.style.borderColor = '#cbd5e0';
                    wrapper.style.background = '#fff';
                }
                const clearBtn = document.getElementById('btn_clear_search');
                if (clearBtn) clearBtn.style.display = 'none';
            }
            
            // Reusable Table Body Clear (Handles Equipos, Movilizaciones and Usuarios)
            const equiposBody = document.getElementById('equiposTableBody');
            const movilizacionesBody = document.getElementById('movilizacionesTableBody');
            const usuariosBody = document.getElementById('usuariosTableBody');
            const tbody = equiposBody || movilizacionesBody || usuariosBody;

            if (tbody) {
                const isUsuarios = !!usuariosBody;
                const isMov = !!movilizacionesBody;
                let icon = 'search_off';
                let message = 'No se han aplicado filtros. Seleccione uno para ver datos.';
                
                if (isUsuarios) {
                    icon = 'person_search';
                    message = 'Filtro limpiado. Todos los usuarios serán mostrados.';
                } else if (isMov) {
                    icon = 'local_shipping';
                    message = 'No se han aplicado filtros. Seleccione uno para ver movilizaciones.';
                }
                
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="table-cell-custom" style="text-align: center; padding: 40px; color: #a0aec0;">
                            <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                                <i class="material-icons" style="font-size: 48px; opacity: 0.3;">${icon}</i>
                                <span>${message}</span>
                            </div>
                        </td>
                    </tr>
                `;

                // Reset Dashboard Stats to 0/Empty
                const stTotal = document.getElementById('stats_total');
                const stInact = document.getElementById('stats_inactivos');
                const stMant = document.getElementById('stats_mantenimiento');
                const stDist = document.getElementById('distributionStatsContainer');
                
                if (stTotal) stTotal.textContent = '0';
                if (stInact) stInact.textContent = '0';
                if (stMant) stMant.textContent = '0';
                if (stDist) stDist.innerHTML = '';
            }
            
            // Update URL without navigation
            const url = new URL(window.location.href);
            const params = new URLSearchParams(url.search);
            params.delete(filterName);
            const newUrl = url.pathname + (params.toString() ? '?' + params.toString() : '');
            
            if (window.history && window.history.replaceState) {
                window.history.replaceState({}, '', newUrl);
            }
        };
    </script>
    {{-- Scripts de Formularios (Globales para soporte SPA) --}}
    {{-- NOTE: form_selects.js removed (deprecated, merged into form_logic.js) --}}
    <script src="{{ asset('js/maquinaria/equipos_form.js') }}?v=5.0"></script>
    @yield('extra_js')
    @include('partials.session_timeout')


</body>
</html>
