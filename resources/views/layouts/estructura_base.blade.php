<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Sistema de Gestión')</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- ===== PWA ===== --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}?v={{ @filemtime(public_path('manifest.json')) }}">
    {{-- Sin theme-color: respetamos el color nativo de la barra del navegador (gris claro por defecto). --}}
    <meta name="application-name" content="Vidalsa">
    <meta name="mobile-web-app-capable" content="yes">
    {{-- iOS: homescreen / standalone --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Vidalsa">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/icon-180.png') }}">
    <link rel="apple-touch-icon" sizes="152x152" href="{{ asset('icons/icon-152.png') }}">
    <link rel="apple-touch-icon" sizes="192x192" href="{{ asset('icons/icon-192.png') }}">

    <!-- Preload Fonts to prevent FOUT (text flashing before icons load) -->
    <link rel="preload" as="font" href="{{ asset('fonts/MaterialIcons-Regular.ttf') }}" type="font/ttf"
        crossorigin="anonymous">

    <!-- CSS -->
    <link rel="stylesheet"
        href="{{ asset('css/maquinaria/estilos_globales.css') }}?v={{ @filemtime(public_path('css/maquinaria/estilos_globales.css')) }}">
    <link rel="stylesheet"
        href="{{ asset('css/maquinaria/menu.css') }}?v={{ @filemtime(public_path('css/maquinaria/menu.css')) }}">
    <link rel="stylesheet"
        href="{{ asset('css/maquinaria/catalogo.css') }}?v={{ @filemtime(public_path('css/maquinaria/catalogo.css')) }}">
    <!-- Local Fonts Optimization -->
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}?v={{ @filemtime(public_path('css/fonts.css')) }}">

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="base-url" content="{{ url('/') }}">
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
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        /* CRITICAL CSS: Prevent Layout Shift / FOUC */
        body {
            /* Matches menu.css padding-top */
            padding-top: 70px;
            margin: 0;
            opacity: 1 !important;
            /* Force visible immediately */
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
    {{-- ── Estilos del centro de notificaciones ── --}}
    <style>
        .notif-center {
            position: relative;
            display: inline-block;
            margin-right: 8px;
        }
        /* Coincide visualmente con .btn-logout-header para mantener consistencia del navbar */
        .notif-bell-btn {
            position: relative;
            background-color: transparent;
            border: none;
            color: var(--maquinaria-gray-text);
            width: auto;
            height: 40px;
            padding: 0 10px;
            border-radius: 8px;
            cursor: default;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        .notif-bell-btn:hover {
            color: var(--maquinaria-blue);
            background-color: #f1f5f9;
        }
        .notif-bell-btn .material-icons {
            font-size: 20px;
            font-weight: bold;
        }
        /* Cuando hay notificaciones: mantiene color base pero aplica la animación del icono */
        .notif-bell-btn.has-notifs .material-icons {
            animation: notifShake 2s ease-in-out infinite;
        }
        @keyframes notifShake {
            0%, 90%, 100% { transform: rotate(0); }
            92% { transform: rotate(-15deg); }
            94% { transform: rotate(15deg); }
            96% { transform: rotate(-10deg); }
            98% { transform: rotate(10deg); }
        }
        .notif-badge {
            position: absolute;
            top: 2px;
            right: 2px;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: #fff;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 6px rgba(220, 38, 38, 0.45), 0 0 0 2px #fff;
            letter-spacing: -0.3px;
        }
        .notif-badge[hidden] { display: none; }
        .notif-dropdown {
            position: absolute;
            top: calc(100% + 10px);
            right: 0;
            width: 380px;
            max-width: calc(100vw - 24px);
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(226, 232, 240, 0.9);
            border-radius: 16px;
            box-shadow: 0 20px 50px -12px rgba(0, 0, 0, 0.25), 0 8px 16px -4px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            z-index: 2000;
            animation: notifSlideIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            transform-origin: top right;
        }
        .notif-dropdown[hidden] { display: none; }
        @keyframes notifSlideIn {
            from { opacity: 0; transform: translateY(-8px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .notif-dropdown-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            background: #1e293b;
            color: #fff;
            position: relative;
            z-index: 1;
        }
        .notif-dropdown-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.2px;
        }
        .notif-dropdown-title .material-icons { font-size: 20px; }
        .notif-refresh-btn {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .notif-refresh-btn:hover { background: rgba(255, 255, 255, 0.22); }
        .notif-refresh-btn.rotating .material-icons { animation: spin 0.8s linear infinite; }
        .notif-refresh-btn .material-icons { font-size: 16px; }
        @keyframes spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
        .notif-dropdown-body {
            max-height: 420px;
            overflow-y: auto;
            background: #fff;
        }
        .notif-loading {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            color: #94a3b8;
            gap: 8px;
            font-size: 13px;
        }
        .notif-loading .material-icons { font-size: 36px; animation: spin 1.5s linear infinite; }

        /* ── Encabezado compacto del usuario en el drawer mobile (una sola fila) ── */
        .mobile-user-header-compact {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            margin: 0 -8px 8px -8px;
            background: linear-gradient(135deg, #00004d 0%, #0067b1 100%);
            color: #fff;
            border-radius: 10px;
            line-height: 1.15;
        }
        .mobile-user-header-compact .muhc-avatar {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.18);
            border: 1.5px solid rgba(255,255,255,0.3);
            color: #fff;
            font-weight: 800;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .mobile-user-header-compact .muhc-text {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }
        .mobile-user-header-compact .muhc-name {
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .mobile-user-header-compact .muhc-role {
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.78);
        }

        /* (Legacy — panel grande removido) Encabezado original del drawer, ya no se usa */
        .mobile-user-header {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 18px 20px;
            margin: 0 -16px 12px -16px;
            background: linear-gradient(135deg, #00004d 0%, #0067b1 100%);
            color: #fff;
            border-radius: 14px;
            box-shadow: 0 6px 18px -8px rgba(0, 0, 77, 0.55);
        }
        .mobile-user-header .mobile-user-avatar {
            flex-shrink: 0;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.18);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: #fff;
            font-weight: 800;
            font-size: 18px;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(6px);
        }
        .mobile-user-header .mobile-user-info {
            display: flex;
            flex-direction: column;
            min-width: 0;
            flex: 1;
        }
        .mobile-user-header .mobile-user-name {
            font-size: 15px;
            font-weight: 800;
            color: #fff;
            line-height: 1.15;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .mobile-user-header .mobile-user-role {
            margin-top: 3px;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.8);
            background: rgba(255, 255, 255, 0.12);
            border-radius: 999px;
            padding: 2px 10px;
            align-self: flex-start;
        }

        /* ── Panel de usuario en el navbar (solo desktop) ── */
        .header-user-panel {
            display: inline-flex;
            flex-direction: row;
            align-items: center;
            flex-wrap: nowrap;
            gap: 10px;
            padding: 4px 14px 4px 4px;
            margin-right: 4px;
            background: rgba(15, 23, 42, 0.03);
            border: 1px solid rgba(15, 23, 42, 0.07);
            border-radius: 999px;
            transition: background 0.2s, border-color 0.2s;
        }
        .header-user-panel:hover {
            background: rgba(15, 23, 42, 0.06);
            border-color: rgba(15, 23, 42, 0.12);
        }
        .header-user-panel .hup-avatar {
            position: relative;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            /* Azul general del proyecto (matches #00004d usado en el fondo del menú) */
            background: #00004d;
            color: #fff;
            font-weight: 800;
            font-size: 14px;
            letter-spacing: 0.3px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 8px -2px rgba(0, 0, 77, 0.35);
            flex-shrink: 0;
        }
        .header-user-panel .hup-info {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            line-height: 1.1;
            min-width: 0;
        }
        .header-user-panel .hup-name {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #0f172a;
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .header-user-panel .hup-role {
            display: block;
            margin-top: 1px;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #00004d;
        }

        /* Responsive: breakpoint 900px (matches con el layout base donde se activa mobile) */
        @media (max-width: 900px) {
            .header-user-panel { display: none !important; }
            /* Empujar el grupo notif hacia la derecha, pegado al hamburger con 6px de separación */
            .dashboard-header { justify-content: flex-start !important; }
            .header-actions { margin-left: auto !important; gap: 0; }
            .notif-center { margin: 0; }
            .notif-bell-btn { height: 38px; padding: 0 4px; }
            .notif-bell-btn .material-icons { font-size: 19px; }
            .notif-badge {
                min-width: 16px;
                height: 16px;
                font-size: 9px;
                top: 2px;
                right: 2px;
            }
            .menu-toggle.mobile-only { margin-left: 6px !important; padding: 4px 6px; }

            /* Dropdown de notificaciones anclado al viewport para que nunca se escape */
            .notif-dropdown {
                position: fixed;
                top: 82px;
                left: 10px;
                right: 10px;
                width: auto;
                max-width: none;
                max-height: calc(100vh - 100px);
                display: flex;
                flex-direction: column;
            }
            .notif-dropdown-body { flex: 1; max-height: none; }
        }
    </style>
    <!-- Custom UI Components (SPA Friendly) -->
    <!-- Scripts moved to footer for performance -->

    <script>
        // Interceptor GLOBAL de Fetch para manejar expiración de sesión (419, 401)
        const originalFetch = window.fetch;
        window.fetch = async function (...args) {
            try {
                const response = await originalFetch.apply(this, args);
                // Si la sesión expiró o hubo un problema de token CSRF
                if (response.status === 401 || response.status === 419) {
                    // Prevenir que se ejecute la lógica inferior y redirigir silenciosamente
                    window.location.href = '/login';
                    return new Promise(() => { }); // Promesa pendiente eterna
                }
                return response;
            } catch (err) {
                // Si la conexión es rechazada por completo (ej: servidor local caído)
                if (err.message && (err.message.includes('fetch') || err.message.includes('NetworkError'))) {
                    // Opcionalmente podríamos redirigir al login si ocurre un error de red masivo
                    console.warn('Error de red detectado en fetch. Posible desconexión del servidor.');
                }
                throw err;
            }
        };
    </script>

    @yield('extra_css')
</head>

<body class="modern-app">
    <!-- Global Preloader (Bars animation) - Para carga inicial y navegación SPA -->
    <div id="preloader" class="preloader">
        <div class="preloader-content">
            <div class="spinner-circle"></div>
        </div>
    </div>



    <!-- Permanent Header (Never reloads) -->
    <header class="dashboard-header">
        <div class="header-logo">
            <a href="{{ route('menu') }}"
                onclick="if(window.location.pathname === '/menu' || window.location.pathname === '/admin/menu') { event.preventDefault(); event.stopPropagation(); return false; }">
                <img src="{{ asset('images/maquinaria/logo.webp') }}" alt="Logo">
            </a>
        </div>

        <nav class="header-nav">
            <a id="nav-inicio-btn" href="{{ route('menu') }}" class="nav-link"
                style="display: {{ request()->is('menu') ? 'none' : 'flex' }} !important; align-items: center;">
                <i class="material-icons" style="font-size: 18px; margin-right: 5px;">home</i>Inicio
            </a>

            <a href="{{ route('equipos.index') }}"
                class="nav-link {{ request()->is('admin/equipos') || (request()->is('admin/equipos/*') && !request()->is('admin/equipos-auxiliares*')) ? 'active' : '' }}"
                style="display: flex; align-items: center;">
                <i class="material-icons" style="font-size: 18px; margin-right: 5px;">agriculture</i>Vehículo
            </a>
            <a href="{{ route('equipos-auxiliares.index') }}"
                class="nav-link {{ request()->is('admin/equipos-auxiliares*') ? 'active' : '' }}"
                style="display: flex; align-items: center;">
                <i class="material-icons" style="font-size: 18px; margin-right: 5px;">construction</i>Aux.
            </a>
            <a href="{{ route('movilizaciones.index') }}"
                class="nav-link {{ request()->is('admin/movilizaciones*') ? 'active' : '' }}"
                style="display: flex; align-items: center;">
                <i class="material-icons" style="font-size: 18px; margin-right: 5px;">local_shipping</i>Historial Mov.
            </a>

            <!-- Configuraciones Dropdown -->
            <a href="{{ route('consumibles.graficos') }}"
                class="nav-link {{ request()->is('admin/consumibles*') ? 'active' : '' }}"
                style="display:flex; align-items:center;">
                <i class="material-icons" style="font-size:18px; margin-right:5px;">local_gas_station</i>Consumibles
            </a>

            <div class="nav-dropdown">
                <a href="#"
                    class="nav-link {{ (request()->is('admin/usuarios*') || request()->is('admin/frentes*')) ? 'active' : '' }}"
                    style="display: flex; align-items: center; gap: 4px;">
                    <i class="material-icons" style="font-size: 18px;">settings</i>Configuraciones
                    <i class="material-icons" style="font-size: 16px;">expand_more</i>
                </a>
                <div class="nav-dropdown-content">
                    @can('manage.users')
                        <a href="{{ route('usuarios.index') }}"
                            class="nav-dropdown-link {{ request()->is('admin/usuarios*') ? 'active' : '' }}">
                            <i class="material-icons">people</i> Usuarios
                        </a>
                    @else
                        <a href="{{ route('usuarios.miPerfil') }}"
                            class="nav-dropdown-link {{ request()->is('admin/usuarios/mi-perfil') ? 'active' : '' }}">
                            <i class="material-icons">manage_accounts</i> Mi Usuario
                        </a>
                    @endcan
                    <a href="{{ route('frentes.index') }}"
                        class="nav-dropdown-link {{ request()->is('admin/frentes*') ? 'active' : '' }}">
                        <i class="material-icons">business</i> Frentes de trabajo
                    </a>
                    @can('super.admin')
                    <a href="{{ route('historial-documentos.index') }}"
                        class="nav-dropdown-link {{ request()->routeIs('historial-documentos.*') ? 'active' : '' }}">
                        <i class="material-icons">fact_check</i> Control de Auditoría
                    </a>
                    @endcan
                </div>
            </div>
        </nav>

        <div class="header-actions">
            {{-- ── Panel de usuario (avatar + nombre + rol) — estilo moderno en el navbar ── --}}
            @auth
                <div class="header-user-panel" title="{{ auth()->user()->NOMBRE_COMPLETO ?? 'Usuario' }}">
                    <div class="hup-avatar">
                        {{ strtoupper(substr(auth()->user()->NOMBRE_COMPLETO ?? 'U', 0, 1)) }}
                    </div>
                    <div class="hup-info">
                        <span class="hup-name">{{ auth()->user()->NOMBRE_COMPLETO ?? 'Usuario' }}</span>
                        <span class="hup-role">{{ auth()->user()->rol->NOMBRE_ROL ?? 'Sin Rol' }}</span>
                    </div>
                </div>
            @endauth

            {{-- ── Centro de Notificaciones (entre nav y logout en desktop; al lado del hamburger en mobile) ── --}}
            <div class="notif-center" id="notifCenter">
                <button type="button" id="notifToggleBtn" class="notif-bell-btn" title="Notificaciones" aria-label="Notificaciones" aria-expanded="false">
                    <i class="material-icons">notifications</i>
                    <span id="notifBadge" class="notif-badge" hidden>0</span>
                </button>
                <div id="notifDropdown" class="notif-dropdown" role="dialog" aria-label="Equipos por confirmar recepción" hidden>
                    <div class="notif-dropdown-header">
                        <div class="notif-dropdown-title">
                            <i class="material-icons">local_shipping</i>
                            <span>Equipos Por Confirmar</span>
                        </div>
                        <button type="button" id="notifRefreshBtn" class="notif-refresh-btn" title="Actualizar">
                            <i class="material-icons">refresh</i>
                        </button>
                    </div>
                    <div class="notif-dropdown-body" id="notifDropdownBody">
                        <div class="notif-loading">
                            <i class="material-icons">hourglass_empty</i>
                            <span>Cargando...</span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Logout solo visible en desktop (en mobile se usa el menú hamburguesa) --}}
            <form action="{{ route('logout') }}" method="POST" class="desktop-only" style="margin: 0; display: inline;">
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
        {{-- Encabezado compacto del usuario (una sola fila: avatar + nombre + rol pequeño) --}}
        @auth
            <div class="mobile-user-header-compact">
                <div class="muhc-avatar">
                    {{ strtoupper(substr(auth()->user()->NOMBRE_COMPLETO ?? 'U', 0, 1)) }}
                </div>
                <div class="muhc-text">
                    <span class="muhc-name">{{ auth()->user()->NOMBRE_COMPLETO ?? 'Usuario' }}</span>
                    <span class="muhc-role">{{ auth()->user()->rol->NOMBRE_ROL ?? 'Sin Rol' }}</span>
                </div>
            </div>
        @endauth

        <a href="{{ route('menu') }}" class="mobile-nav-link {{ request()->is('menu') ? 'active' : '' }}">
            <i class="material-icons">home</i> Inicio
        </a>

        <a href="{{ route('equipos.index') }}"
            class="mobile-nav-link {{ request()->is('admin/equipos') || (request()->is('admin/equipos/*') && !request()->is('admin/equipos-auxiliares*')) ? 'active' : '' }}">
            <i class="material-icons">agriculture</i> Vehículo
        </a>
        <a href="{{ route('equipos-auxiliares.index') }}"
            class="mobile-nav-link {{ request()->is('admin/equipos-auxiliares*') ? 'active' : '' }}">
            <i class="material-icons">construction</i> Equipos Auxiliares
        </a>
        <a href="{{ route('movilizaciones.index') }}"
            class="mobile-nav-link {{ request()->is('admin/movilizaciones*') ? 'active' : '' }}">
            <i class="material-icons">local_shipping</i> Historial Mov.
        </a>
        <a href="{{ route('consumibles.graficos') }}"
            class="mobile-nav-link {{ request()->is('admin/consumibles*') ? 'active' : '' }}">
            <i class="material-icons">local_gas_station</i> Consumibles
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
                    <a href="{{ route('usuarios.index') }}"
                        class="mobile-nav-link {{ request()->is('admin/usuarios*') ? 'active' : '' }}">
                        <i class="material-icons">people</i> Usuarios
                    </a>
                @else
                    <a href="{{ route('usuarios.miPerfil') }}"
                        class="mobile-nav-link {{ request()->is('admin/usuarios/mi-perfil') ? 'active' : '' }}">
                        <i class="material-icons">manage_accounts</i> Mi Usuario
                    </a>
                @endcan
                <a href="{{ route('frentes.index') }}"
                    class="mobile-nav-link {{ request()->is('admin/frentes*') ? 'active' : '' }}">
                    <i class="material-icons">business</i> Frentes de trabajo
                </a>
                <a href="{{ route('catalogo.index') }}"
                    class="mobile-nav-link {{ request()->is('admin/catalogo*') ? 'active' : '' }}">
                    <i class="material-icons">menu_book</i> Catálogo de Modelos
                </a>
            </div>
        </div>

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
                    if (window.showToast) {
                        window.showToast(@json(session('success')), 'success');
                    }
                });
            </script>
        @endif

        {{-- Flash toast desde sessionStorage (post-redirect en flujos AJAX/SPA).
             Permite mostrar la notificacion en la pagina destino sin parpadeo
             cuando el form origen redirigio via JS (ej: equipos edit, catalogo). --}}
        <script>
            (function () {
                function _flushFlashToast() {
                    try {
                        var raw = sessionStorage.getItem('vidalsa_flash_toast');
                        if (!raw) return;
                        sessionStorage.removeItem('vidalsa_flash_toast');
                        var data = JSON.parse(raw);
                        if (!data || !data.message) return;
                        var tryShow = function () {
                            if (typeof window.showToast === 'function') {
                                window.showToast(data.message, data.type || 'success');
                            } else {
                                setTimeout(tryShow, 80);
                            }
                        };
                        tryShow();
                    } catch (_) { /* silencioso */ }
                }
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', _flushFlashToast);
                } else {
                    _flushFlashToast();
                }
                // En navegaciones SPA, leer el toast nuevo y liberar el flag
                // de "redirigiendo" para que el siguiente submit arranque limpio.
                window.addEventListener('spa:contentLoaded', function () {
                    _flushFlashToast();
                    window.__vidalsaRedirecting = false;
                });
            })();
        </script>

        @yield('content')
    </main>



    <!-- PDF Preview Modal -->
    <div id="pdfPreviewModal" class="modal-overlay modal-overlay-front">
        <div class="modal-content"
            style="width: 95%; height: 95vh; max-width: none; padding: 0; display: flex; flex-direction: column; background: #2d3748;">
            <!-- Header (Optimized - Lightweight) -->
            <div
                style="background: #2d3748; padding: 10px 15px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #4a5568;">
                <h3 id="pdfPreviewTitle" style="margin: 0; color: white; font-size: 14px; font-weight: 600;">Documento
                </h3>

                <div style="display: flex; align-items: center; gap: 8px;">


                    <button id="pdfDownloadBtn" onclick="downloadPdfDirect(this.dataset.url, this.dataset.label)"
                        style="background: #3182ce; border: none; padding: 6px 12px; font-size: 12px; display: flex; align-items: center; gap: 5px; color: white; border-radius: 4px;">
                        <i class="material-icons" style="font-size: 16px;">download</i> Descargar
                    </button>

                    @if(auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('user.edit') || auth()->user()->can('super.admin')))
                        <label id="pdfUpdateLabel" for="pdfUpdateInput"
                            style="background: #059669; border: none; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; color: white; border-radius: 50%; transition: transform 0.2s; cursor: pointer;"
                            onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"
                            title="Actualizar Documento">
                            <i class="material-icons" style="font-size: 18px;">add</i>
                            <input type="file" id="pdfUpdateInput" accept="application/pdf" style="display: none;">
                        </label>
                    @endif

                    <button onclick="closePdfPreview()"
                        style="background: none; border: none; color: #cbd5e0; padding: 4px; display: flex; align-items: center; cursor: pointer;">
                        <i class="material-icons" style="font-size: 20px;">close</i>
                    </button>
                </div>
            </div>

            <!-- Viewer Container -->
            <div style="flex: 1; background: #4a5568; position: relative; display: flex; overflow: hidden;">

                <div style="flex: 1; position: relative; display: flex; align-items: center; justify-content: center;">
                    <!-- Loading Indicator (Same as global preloader) -->
                    <div id="pdfViewerLoader"
                        style="position: absolute; display: flex; flex-direction: column; align-items: center; gap: 15px; z-index: 50;">
                        <div class="spinner-circle"></div>
                        <span style="color: white; font-weight: 500; font-size: 14px;">Cargando documento...</span>
                    </div>

                    <div id="pdfUploadProgressOverlay"
                        style="position: absolute; display: none; flex-direction: column; align-items: center; justify-content: center; gap: 15px; z-index: 60; background: rgba(0,0,0,0.85); inset: 0; backdrop-filter: blur(4px); border-radius: 12px;">
                        <div class="spinner-circle"></div>
                        <div style="text-align: center;">
                            <div id="pdfUploadStatusText"
                                style="color: white; font-weight: 600; font-size: 16px; margin-bottom: 8px;">Subiendo
                                documento</div>
                            <div id="pdfUploadPercentage" style="color: #63b3ed; font-size: 24px; font-weight: 700;">0%
                            </div>
                        </div>
                        <div
                            style="width: 200px; height: 6px; background: rgba(255,255,255,0.2); border-radius: 3px; overflow: hidden;">
                            <div id="pdfUploadProgressBar"
                                style="width: 0%; height: 100%; background: linear-gradient(90deg, #3182ce 0%, #63b3ed 100%); transition: width 0.2s; border-radius: 3px;">
                            </div>
                        </div>
                    </div>

                    <iframe id="pdfPreviewFrame" src=""
                        style="width: 100%; height: 100%; border: none; opacity: 0; transition: opacity 0.3s; position: relative; z-index: 20;"
                        allowfullscreen></iframe>

                    <!-- Vista móvil para descarga directa -->
                    <div id="pdfMobileFallback"
                        style="display: none; flex-direction: column; align-items: center; justify-content: center; z-index: 25; width: 100%; height: 100%; background: #4a5568; padding: 20px; box-sizing: border-box; text-align: center; position: absolute; top:0; left:0;">
                        <i class="material-icons"
                            style="font-size: 64px; color: #a0aec0; margin-bottom: 15px;">description</i>
                        <h4 style="color: white; margin: 0 0 10px 0; font-size: 18px; font-weight: 600;">Vista Previa No
                            Disponible</h4>
                        <p
                            style="color: #cbd5e0; margin: 0 0 25px 0; font-size: 14px; max-width: 280px; line-height: 1.4;">
                            Los teléfonos móviles no soportan la visualización incrustada del documento.</p>
                        <button
                            onclick="downloadPdfDirect(document.getElementById('pdfDownloadBtn').dataset.url, document.getElementById('pdfDownloadBtn').dataset.label)"
                            style="background: #3182ce; color: white; border: none; padding: 12px 24px; font-size: 15px; font-weight: 600; border-radius: 8px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2); cursor: pointer;">
                            <i class="material-icons">download</i> Descargar Documento
                        </button>
                    </div>
                </div>

                <!-- Metadata Side Panel -->
                <div id="pdfMetadataPanel"
                    style="width: 0; background: #2d3748; border-left: 1px solid #4a5568; transition: width 0.3s ease; overflow: hidden; display: flex; flex-direction: column;"
                    class="pdf-metadata-panel-responsive">
                    <div style="padding: 12px; width: 300px; color: white; box-sizing: border-box;">
                        <h4
                            style="margin: 0 0 15px 0; font-size: 15px; border-bottom: 1px solid #4a5568; padding-bottom: 8px;">
                            Editar Datos del Documento</h4>

                        <div id="metaPanelLoader" style="display: none; justify-content: center; padding: 20px;">
                            <div class="spinner-circle" style="width: 24px; height: 24px; border-width: 2px;"></div>
                        </div>

                        <form id="pdfMetadataForm" onsubmit="saveMetadata(event)"
                            style="display: flex; flex-direction: column; gap: 12px;">
                            <div id="metaFieldsContainer"></div>

                            @if(auth()->user() && (auth()->user()->can('equipos.edit') || auth()->user()->can('user.edit') || auth()->user()->can('super.admin')))
                                <button type="submit" id="btnSaveMeta"
                                    style="margin-top: 8px; background: #3182ce; color: white; border: none; padding: 8px 12px; border-radius: 6px; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 13px; width: 100%; box-sizing: border-box;">
                                    <i class="material-icons" style="font-size: 16px;">save</i> Guardar Cambios
                                </button>
                            @endif
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- Cierra pdfPreviewModal -->

    <!-- Standardized Reusable Modal (Moved to End for stacking context) -->
    <style>
        #standardModal .modal-card {
            padding: 20px !important;
            max-width: 320px !important;
        }
        #standardModal .modal-title {
            font-size: 1.1rem !important;
            margin-bottom: 5px !important;
        }
        #standardModal .modal-message {
            font-size: 0.85rem !important;
            margin-bottom: 15px !important;
        }
        #standardModal .modal-icon {
            font-size: 40px !important;
            margin-bottom: 10px !important;
        }
        #standardModal .modal-btn {
            padding: 8px 16px !important;
            font-size: 0.85rem !important;
        }
    </style>
    <div id="standardModal" class="modal-overlay" style="z-index: 1000001 !important;">
            <div class="modal-card">
                <i id="modalIcon" class="material-icons modal-icon"
                    style="color: var(--maquinaria-blue);">help_outline</i>
                <h3 id="modalTitle" class="modal-title">¿Confirmar Acción?</h3>
                <p id="modalMessage" class="modal-message">¿Estás seguro de que deseas realizar esta acción?</p>
                <div class="modal-footer">
                    <button id="modalCancelBtn" onclick="cancelModal()"
                        class="modal-btn modal-btn-cancel">Cancelar</button>
                    <button id="modalConfirmBtn" class="modal-btn modal-btn-confirm">Confirmar</button>
                </div>
            </div>
        </div>

        <!-- Scripts -->
        <script>
            // Global Preloader Controls
            window.showPreloader = function () {
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

            window.hidePreloader = function () {
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

            // Asegurar que el preloader inicial se oculte solo cuando todo (incluyendo imágenes/iconos) haya cargado.
            window.addEventListener('load', function() {
                if (typeof window.hidePreloader === 'function') {
                    window.hidePreloader();
                }
            });

            // Utilidad Global para Mostrar/Ocultar Contraseñas
            window.togglePw = function (inputId, icon) {
                const input = document.getElementById(inputId);
                if (!input) return;
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                icon.textContent = isHidden ? 'visibility' : 'visibility_off';
            };

            // Global handler para Cierre de Sesión (Previene doble click y muestra spinner Inmediato)
            document.addEventListener('submit', function (e) {
                if (e.target && e.target.action && e.target.action.includes('logout')) {
                    if (typeof window.showPreloader === 'function') window.showPreloader();
                    const btn = e.target.querySelector('button[type="submit"]');
                    if (btn) {
                        btn.style.pointerEvents = 'none';
                        btn.style.opacity = '0.5';
                    }
                }
            });

            document.addEventListener('DOMContentLoaded', () => {


                // GLOBAL EVENT DELEGATION FOR EQUIPOS MODULE (SPA COMPATIBLE)
                // This ensures that "Acciones" and "Filter" buttons work even after AJAX content replacement
                window.equiposGlobalClickHandler = function (event) {
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
                window.equiposGlobalKeyupHandler = function (event) {
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

        <script
            src="{{ asset('js/maquinaria/module_manager.js') }}?v={{ @filemtime(public_path('js/maquinaria/module_manager.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/uicomponents.js') }}?v={{ @filemtime(public_path('js/maquinaria/uicomponents.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/navegacion.js') }}?v={{ @filemtime(public_path('js/maquinaria/navegacion.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/form_logic.js') }}?v={{ @filemtime(public_path('js/maquinaria/form_logic.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/equipo_catalog_linking.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipo_catalog_linking.js')) }}"></script>

        {{-- Module Scripts (Global Load for SPA Navigation) --}}
        {{-- NOTE: These MUST be loaded globally because the SPA navigation --}}
        {{-- calls functions like window.loadEquipos(), window.loadCatalogo(), etc. --}}
        {{-- from navegacion.js when switching between pages without reload --}}
        <script
            src="{{ asset('js/maquinaria/menu.js') }}?v={{ @filemtime(public_path('js/maquinaria/menu.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/catalogo_create.js') }}?v={{ @filemtime(public_path('js/maquinaria/catalogo_create.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/equipos_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipos_index.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/catalogo_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/catalogo_index.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/movilizaciones_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/movilizaciones_index.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/usuarios_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/usuarios_index.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/historial_documentos_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/historial_documentos_index.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/fleet_dashboard.js') }}?v={{ @filemtime(public_path('js/maquinaria/fleet_dashboard.js')) }}"></script>

        <script src="https://cdn.jsdelivr.net/npm/tom-select@2.2.2/dist/js/tom-select.complete.min.js"></script>
        <script
            src="{{ asset('js/maquinaria/frentes_spa.js') }}?v={{ @filemtime(public_path('js/maquinaria/frentes_spa.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/consumibles_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_index.js')) }}"></script>
        <script>
            function toggleMobileMenu() {
                const menu = document.getElementById('mobileMenu');
                if (menu) menu.classList.toggle('active');
            }

            // Cerrar el menu movil al hacer click fuera (ni en el menu ni en el hamburger).
            // Guard _mobileMenuOutsideReady evita duplicar listener en SPA re-ejecuciones.
            if (!window._mobileMenuOutsideReady) {
                window._mobileMenuOutsideReady = true;
                document.addEventListener('click', function (e) {
                    const menu = document.getElementById('mobileMenu');
                    if (!menu || !menu.classList.contains('active')) return;
                    if (e.target.closest('.mobile-menu') || e.target.closest('.menu-toggle')) return;
                    menu.classList.remove('active');
                });
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
            let modalCancelCallback = null;

            /**
             * Generic Modal System
             * @param {Object} options { type, title, message, onConfirm, onCancel, confirmText, cancelText, hideCancel }
             */
            window.showModal = function (options) {
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

                // Guard: if any modal element is missing, fall back to alert
                if (!modalEl || !titleEl || !messageEl || !confirmBtn || !cancelBtn) {
                    console.warn('showModal: modal DOM elements not found, using alert fallback');
                    if (config.type === 'error' || config.type === 'warning') {
                        alert(`${config.title}\n\n${config.message}`);
                    }
                    if (config.onConfirm) config.onConfirm();
                    return;
                }

                // Set content
                titleEl.innerText = config.title;
                messageEl.innerHTML = config.message;
                confirmBtn.innerText = config.confirmText;
                cancelBtn.innerText = config.cancelText;
                cancelBtn.style.display = config.hideCancel ? 'none' : 'block';

                // Set Icon and colors
                iconEl.className = 'material-icons modal-icon';
                confirmBtn.className = 'modal-btn modal-btn-confirm';

                // Compress modal and force blue buttons
                confirmBtn.style.backgroundColor = 'var(--maquinaria-blue, #1e293b)';
                confirmBtn.style.color = 'white';
                confirmBtn.style.border = 'none';

                switch (config.type) {
                    case 'success':
                        iconEl.innerText = 'check_circle';
                        iconEl.classList.add('modal-icon-success');
                        break;
                    case 'error':
                    case 'danger':
                        iconEl.innerText = 'error';
                        iconEl.classList.add('modal-icon-error');
                        confirmBtn.style.backgroundColor = '#dc2626'; // Keep red for errors
                        break;
                    case 'warning':
                        iconEl.innerText = 'warning';
                        iconEl.classList.add('modal-icon-warning');
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

                // Handle cancel (wired here so onCancel callback fires)
                cancelBtn.onclick = () => {
                    cancelModal();
                };

                // Store cancel callback
                modalCancelCallback = config.onCancel || null;
            }

            window.closeModal = function () {
                const modalEl = document.getElementById('standardModal');
                if (modalEl) modalEl.classList.remove('active');
                modalCallback = null;
                modalCancelCallback = null;
            }

            window.cancelModal = function () {
                const cb = modalCancelCallback;
                closeModal();
                if (cb) cb();
            }

            // Legacy compatibility helper
            window.showConfirmModal = function (title, message, callback, btnText = 'Eliminar') {
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
            window.switchModalTab = function (tabName) {
                // Hide all content
                const contentGeneral = document.getElementById('tab_content_general');
                const contentSpecs = document.getElementById('tab_content_specs');
                const contentLegal = document.getElementById('tab_content_legal');

                if (contentGeneral) contentGeneral.style.display = 'none';
                if (contentSpecs) contentSpecs.style.display = 'none';
                if (contentLegal) contentLegal.style.display = 'none';

                // Reset Buttons
                const btnGeneral = document.getElementById('tab_btn_general');
                const btnSpecs = document.getElementById('tab_btn_specs');
                const btnLegal = document.getElementById('tab_btn_legal');

                const inactiveStyle = "flex: 1; padding: 12px; background: none; border: none; border-bottom: 3px solid transparent; font-weight: 600; color: #64748b; cursor: default; transition: all 0.2s; outline: none;";
                const activeStyle = "flex: 1; padding: 12px; background: none; border: none; border-bottom: 3px solid var(--maquinaria-blue); font-weight: 700; color: var(--maquinaria-blue); cursor: default; transition: all 0.2s; outline: none;";

                if (btnGeneral) btnGeneral.style.cssText = inactiveStyle;
                if (btnSpecs) btnSpecs.style.cssText = inactiveStyle;
                if (btnLegal) btnLegal.style.cssText = inactiveStyle;

                // Activate Target
                if (tabName === 'general') {
                    if (contentGeneral) contentGeneral.style.display = 'block';
                    if (btnGeneral) btnGeneral.style.cssText = activeStyle;
                } else if (tabName === 'specs') {
                    if (contentSpecs) contentSpecs.style.display = 'block';
                    if (btnSpecs) btnSpecs.style.cssText = activeStyle;
                } else {
                    if (contentLegal) contentLegal.style.display = 'block';
                    if (btnLegal) btnLegal.style.cssText = activeStyle;
                }
            };

            // showDetailsImproved and closeDetailsModal are defined in uicomponents.js (loaded after)

            // --- PDF Preview System (Internal View) - OPTIMIZED ---

            // Optimized Direct PDF Download with visual feedback
            window.downloadPdfDirect = function (url, documentLabel) {
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

            window.openPdfPreview = function (url, docType, label, equipoId) {
                const modal = document.getElementById('pdfPreviewModal');
                const iframe = document.getElementById('pdfPreviewFrame');
                const title = document.getElementById('pdfPreviewTitle');
                const downloadBtn = document.getElementById('pdfDownloadBtn');
                const updateInput = document.getElementById('pdfUpdateInput');
                const loader = document.getElementById('pdfViewerLoader');

                // Mostrar spinner global — se oculta cuando el PDF cargue en el iframe
                if (typeof window.showPreloader === 'function') window.showPreloader();

                // Abrir modal de PDF
                if (modal) modal.classList.add('active');
                // NO ocultamos el preloader aquí — esperamos al onload del iframe

                // Show Loader
                if (loader) {
                    loader.style.display = 'flex';
                    loader.style.opacity = '1';
                }

                if (iframe) {
                    iframe.style.opacity = '0';
                    iframe.src = '';
                }

                const fallbackNode = document.getElementById('pdfMobileFallback');
                if (fallbackNode) fallbackNode.style.display = 'none';

                // Set Content
                if (title) title.innerText = label || 'Documento';
                if (downloadBtn) {
                    downloadBtn.dataset.url = url;
                    downloadBtn.dataset.label = label || 'documento';
                    if (!url || url.length < 5) {
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
                    if (loader) loader.style.display = 'none';
                    if (iframe) iframe.style.opacity = '1';
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
                        if (loader) {
                            loader.style.opacity = '0';
                            setTimeout(() => {
                                if (loader) loader.style.display = 'none';
                            }, 200);
                        }
                        if (iframe) iframe.style.opacity = '1';
                    }, remainingTime);
                };

                // Set source and setup load listener
                if (iframe) {
                    iframe.onload = function () {
                        hideLoaderWhenReady();
                    };

                    iframe.onerror = function () {
                        clearTimeout(loaderTimeout);
                        if (loader) loader.style.display = 'none';
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
                            if (loader) loader.style.display = 'none';
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
                        if (loader) loader.style.display = 'none';
                    }
                }

                // Setup Update Input
                if (updateInput) {
                    updateInput.onchange = function () {
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
            window.loadMetadata = async function () {
                const ctx = window.currentPdfContext;
                if (!ctx) return;
                
                const container = document.getElementById('metaFieldsContainer');
                const loader = document.getElementById('metaPanelLoader');
                const form = document.getElementById('pdfMetadataForm');
                
                if (!ctx.equipoId) {
                    if (loader) loader.style.display = 'none';
                    if (container) {
                        container.innerHTML = '<div style="padding: 15px; background: rgba(255,255,255,0.05); border-radius: 8px; border: 1px dashed #4a5568;"><p style="color: #cbd5e0; font-size: 13px; text-align: center; margin: 0;">El vehículo asociado a este documento fue eliminado de la base de datos.</p></div>';
                    }
                    return;
                }
                
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
                            <div style="${containerStyle}"><label for="meta_nro_doc_${ctx.equipoId}" style="${labelStyle}">Nro. Documento</label><input type="text" id="meta_nro_doc_${ctx.equipoId}" name="nro_documento" value="${info.nro_documento || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label for="meta_titular_${ctx.equipoId}" style="${labelStyle}">Titular</label><input type="text" id="meta_titular_${ctx.equipoId}" name="titular" value="${info.titular || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label for="meta_placa_${ctx.equipoId}" style="${labelStyle}">Placa</label><input type="text" id="meta_placa_${ctx.equipoId}" name="placa" value="${info.placa || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label for="meta_marca_${ctx.equipoId}" style="${labelStyle}">Marca</label><input type="text" id="meta_marca_${ctx.equipoId}" name="marca" value="${info.marca || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label for="meta_modelo_${ctx.equipoId}" style="${labelStyle}">Modelo</label><input type="text" id="meta_modelo_${ctx.equipoId}" name="modelo" value="${info.modelo || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label for="meta_chasis_${ctx.equipoId}" style="${labelStyle}">Serial Chasis</label><input type="text" id="meta_chasis_${ctx.equipoId}" name="serial_chasis" value="${info.serial_chasis || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}"><label for="meta_motor_${ctx.equipoId}" style="${labelStyle}">Serial Motor</label><input type="text" id="meta_motor_${ctx.equipoId}" name="serial_motor" value="${info.serial_motor || ''}" ${disabledAttr} autocomplete="off"></div>
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
                            <div style="${containerStyle}"><label for="meta_fec_venc_${ctx.equipoId}" style="${labelStyle}">Fecha Vencimiento</label><input type="date" id="meta_fec_venc_${ctx.equipoId}" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>
                            <div style="${containerStyle}">
                                <label for="meta_aseguradora_${ctx.equipoId}" style="${labelStyle}">Aseguradora <small style="color:#94a3b8;font-weight:400;">(Seleccionar o escribir nueva)</small></label>
                                <input type="text" id="meta_aseguradora_${ctx.equipoId}" name="nombre_aseguradora" list="insurersList_${ctx.equipoId}" value="${currentInsurerName || ''}" placeholder="Escriba o seleccione..." ${disabledAttr} autocomplete="off">
                                <datalist id="insurersList_${ctx.equipoId}">${datalistOptions}</datalist>
                            </div>
                        `;
                        } else if (ctx.docType === 'rotc' || ctx.docType === 'racda' || (ctx.docType === 'adicional' && info.categoria === 'FLOTA LIVIANA')) {
                            // Compraventa (adicional_2) NO requiere fecha de vencimiento.
                            html += `<div style="${containerStyle}"><label for="meta_fec_venc_${ctx.equipoId}" style="${labelStyle}">Fecha Vencimiento</label><input type="date" id="meta_fec_venc_${ctx.equipoId}" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>`;
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

            window.saveMetadata = async function (e) {
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
                            } else {
                                // Consolidado: usa la misma fuente de verdad (DOC_FIELD_MAP.vencKey)
                                // que el resto de los flujos de subida/borrado.
                                const vk = (window.DOC_FIELD_MAP && window.DOC_FIELD_MAP[ctx.docType]) ? window.DOC_FIELD_MAP[ctx.docType].vencKey : null;
                                if (vk) d[vk] = formData.get('fecha_vencimiento');
                                if (ctx.docType === 'poliza') d.seguro = formData.get('nombre_aseguradora');
                            }
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

            window.closePdfPreview = function () {
                const modal = document.getElementById('pdfPreviewModal');
                const iframe = document.getElementById('pdfPreviewFrame');
                if (modal) modal.classList.remove('active');
                if (iframe) {
                    iframe.src = ''; // Clear source to free memory
                }
            };

            // Special Upload Handler for Preview Modal (XMLHttpRequest for Progress)
            window.uploadDocumentFromPreview = function (input, type, equipoId, label) {
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

                if (progressOverlay) progressOverlay.style.display = 'flex';
                if (progressBar) progressBar.style.width = '0%';
                if (progressPercentage) progressPercentage.innerText = '0%';

                const formData = new FormData();
                formData.append('file', file);
                formData.append('doc_type', type);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', `/admin/equipos/${equipoId}/upload-doc`, true);
                xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
                xhr.setRequestHeader('Accept', 'application/json');

                xhr.upload.onprogress = function (e) {
                    if (e.lengthComputable) {
                        const percentComplete = Math.round((e.loaded / e.total) * 100);
                        if (progressBar) progressBar.style.width = percentComplete + '%';

                        const statusText = document.getElementById('pdfUploadStatusText');
                        if (percentComplete === 100) {
                            if (statusText) statusText.innerText = 'Guardando...';
                            if (progressPercentage) progressPercentage.innerText = 'Procesando...';
                        } else {
                            if (statusText) statusText.innerText = 'Subiendo documento';
                            if (progressPercentage) progressPercentage.innerText = percentComplete + '%';
                        }
                    }
                };

                xhr.onload = function () {
                    if (xhr.status === 200) {
                        try {
                            const data = JSON.parse(xhr.responseText);

                            if (data.success) {
                                // Update status text while iframe loads
                                const statusText = document.getElementById('pdfUploadStatusText');
                                if (statusText) statusText.innerText = 'Abriendo vista previa...';
                                if (progressPercentage) progressPercentage.innerText = 'Listo';

                                // Get iframe reference
                                const iframe = document.getElementById('pdfPreviewFrame');

                                // Update iframe to show new PDF
                                if (iframe) {
                                    iframe.style.opacity = '0';

                                    // Setup load handler for new PDF to hide overlay ONLY when ready
                                    iframe.onload = function () {
                                        if (progressOverlay) {
                                            progressOverlay.style.opacity = '0';
                                            setTimeout(() => {
                                                progressOverlay.style.display = 'none';
                                                progressOverlay.style.opacity = '1';
                                            }, 300);
                                        }
                                        iframe.style.opacity = '1';

                                        // Reset status text for next time
                                        if (statusText) statusText.innerText = 'Subiendo documento';
                                    };

                                    // Load new PDF with force-refresh since file changed
                                    iframe.src = data.link + '?upd=' + new Date().getTime() + '#toolbar=0&navpanes=0&scrollbar=0&zoom=100';
                                }

                                // Update Download Button
                                const downloadBtn = document.getElementById('pdfDownloadBtn');
                                if (downloadBtn) downloadBtn.dataset.url = data.link;

                                // Sincroniza dataset + equiposData usando el helper unico (DOC_FIELD_MAP).
                                // Solo re-renderiza el modal detalles si sigue abierto debajo del preview;
                                // asi evitamos reabrirlo por encima del preview y manejamos race conditions
                                // (nodo muerto, SPA nav) gracias al guard de activeEquipoButton.
                                const btnFP = window.activeEquipoButton;
                                const btnFPAlive = btnFP && document.body.contains(btnFP);
                                if (btnFPAlive && typeof window.applyDocUpload === 'function') {
                                    window.applyDocUpload(btnFP.dataset, type, data);
                                    if (window.equiposData && btnFP.dataset.equipoId && window.equiposData[btnFP.dataset.equipoId]) {
                                        window.applyDocUpload(window.equiposData[btnFP.dataset.equipoId], type, data);
                                    }
                                    const detailsModal = document.getElementById('detailsModal');
                                    const detailsOpen  = detailsModal && detailsModal.classList.contains('active');
                                    if (detailsOpen && typeof window.showDetailsImproved === 'function') {
                                        try { window.showDetailsImproved(btnFP); } catch (_) { /* noop */ }
                                    }
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
                            if (progressOverlay) progressOverlay.style.display = 'none';
                            if (window.showToast) window.showToast('Error: Respuesta inválida del servidor', 'error');
                        }
                    } else {
                        if (progressOverlay) progressOverlay.style.display = 'none';
                        if (window.showToast) window.showToast('Error al cargar documento', 'error');
                    }
                };

                xhr.onerror = function () {
                    const progressOverlay = document.getElementById('pdfUploadProgressOverlay');
                    if (progressOverlay) progressOverlay.style.display = 'none';
                    if (window.showToast) window.showToast('Error de red', 'error');
                };

                xhr.send(formData);
            };

            window.filterDropdownOptions = function (input) {
                const filter = input.value.toUpperCase();
                // Generic lookup relative to input
                const wrapper = input.closest('.custom-dropdown');
                if (!wrapper) return;
                const container = wrapper.querySelector('.dropdown-item-list');
                if (!container) return;

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
            window.confirmDeleteDocument = function (equipoId, docType, label) {
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
                                // Cierra preview
                                closePdfPreview();

                                // Limpia dataset + equiposData y tambien la fecha de vencimiento
                                // asociada (vencKey) via DOC_FIELD_MAP. showDetailsImproved re-renderiza
                                // el boton en estado "cloud_upload" con el estilo correcto + verifica
                                // CAN_UPDATE_INFO. No inyectamos HTML manual.
                                const btnDel = window.activeEquipoButton;
                                const btnDelAlive = btnDel && document.body.contains(btnDel);
                                if (btnDelAlive) {
                                    if (typeof window.clearDocFields === 'function') {
                                        window.clearDocFields(btnDel.dataset, docType);
                                        if (window.equiposData && btnDel.dataset.equipoId && window.equiposData[btnDel.dataset.equipoId]) {
                                            window.clearDocFields(window.equiposData[btnDel.dataset.equipoId], docType);
                                        }
                                    }
                                    // Limpia la fecha de vencimiento extra (no cubierta por clearDocFields).
                                    const vk = window.DOC_FIELD_MAP && window.DOC_FIELD_MAP[docType] ? window.DOC_FIELD_MAP[docType].vencKey : null;
                                    if (vk) {
                                        btnDel.dataset[vk] = '';
                                        if (window.equiposData && btnDel.dataset.equipoId && window.equiposData[btnDel.dataset.equipoId]) {
                                            window.equiposData[btnDel.dataset.equipoId][vk] = '';
                                        }
                                    }
                                    const detailsModal = document.getElementById('detailsModal');
                                    const detailsOpen  = detailsModal && detailsModal.classList.contains('active');
                                    if (detailsOpen && typeof window.showDetailsImproved === 'function') {
                                        try { window.showDetailsImproved(btnDel); } catch (_) { /* noop */ }
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

            window.confirmDeleteEquipo = function (id) {
                showModal({
                    type: 'error',
                    title: '¿Eliminar equipo?',
                    message: '¿Estás seguro de eliminar este equipo? Esta acción no se puede deshacer.',
                    confirmText: 'Eliminar',
                    onConfirm: () => {
                        var form = document.getElementById('delete-form-global');
                        if (form) {
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
            window.checkAutoSubmit = function (input) {
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
                        if (window.showPreloader) window.showPreloader();
                        form.submit();
                    }
                }
            };

            // Clear filter without reload or query - just clear UI
            window.clearFilter = function (filterName) {
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
        <script
            src="{{ asset('js/maquinaria/equipos_form.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipos_form.js')) }}"></script>
        {{-- Bulk upload de equipos (Global: @yield('extra_js') queda fuera del .main-viewport → SPA no lo re-ejecutaría) --}}
        <script
            src="{{ asset('js/maquinaria/equipos_bulk.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipos_bulk.js')) }}"></script>

        {{-- ── Centro de notificaciones (Equipos por confirmar) ── --}}
        <script>
            (function () {
                if (window._notifCenterReady) return;
                window._notifCenterReady = true;

                const bellBtn    = document.getElementById('notifToggleBtn');
                const dropdown   = document.getElementById('notifDropdown');
                const body       = document.getElementById('notifDropdownBody');
                const badge      = document.getElementById('notifBadge');
                const refreshBtn = document.getElementById('notifRefreshBtn');
                if (!bellBtn || !dropdown || !body) return;

                let isOpen = false;
                let lastFetchedAt = 0;

                const ENDPOINT = @json(route('dashboard.pendingMovsHtml'));
                const POLL_INTERVAL_MS = 60000; // refrescar badge cada 60s
                const STALE_MS = 20000;         // si se abre el dropdown y los datos tienen <20s, no refetchea

                function updateBadge(count) {
                    const n = parseInt(count, 10) || 0;
                    if (n > 0) {
                        badge.textContent = n > 99 ? '99+' : String(n);
                        badge.hidden = false;
                        bellBtn.classList.add('has-notifs');
                    } else {
                        badge.hidden = true;
                        bellBtn.classList.remove('has-notifs');
                    }
                }

                function renderEmpty(message) {
                    body.innerHTML = '<div class="mov-empty-state" style="padding:30px 20px;"><i class="material-icons">inbox</i><p>' + message + '</p></div>';
                }

                async function fetchNotifs(forceSpin) {
                    if (forceSpin) refreshBtn.classList.add('rotating');
                    try {
                        const res = await fetch(ENDPOINT, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            credentials: 'same-origin'
                        });
                        if (!res.ok) throw new Error('HTTP ' + res.status);
                        const data = await res.json();
                        if (typeof data.pendientes !== 'undefined') updateBadge(data.pendientes);
                        if (typeof data.html === 'string') {
                            // El partial ya incluye su propio empty state cuando no hay items.
                            // Solo si el backend mandó HTML completamente vacío, renderizamos fallback.
                            const clean = data.html.trim();
                            if (clean) body.innerHTML = clean;
                            else renderEmpty('No hay equipos por confirmar recepción.');
                        }
                        lastFetchedAt = Date.now();
                    } catch (err) {
                        console.warn('[Notif] error cargando:', err);
                        renderEmpty('No se pudo cargar la información.');
                    } finally {
                        if (forceSpin) refreshBtn.classList.remove('rotating');
                    }
                }

                function openDropdown() {
                    dropdown.hidden = false;
                    isOpen = true;
                    bellBtn.setAttribute('aria-expanded', 'true');
                    if (Date.now() - lastFetchedAt > STALE_MS) fetchNotifs(false);
                }
                function closeDropdown() {
                    dropdown.hidden = true;
                    isOpen = false;
                    bellBtn.setAttribute('aria-expanded', 'false');
                }

                bellBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    isOpen ? closeDropdown() : openDropdown();
                });
                refreshBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    fetchNotifs(true);
                });
                document.addEventListener('click', (e) => {
                    if (!isOpen) return;
                    if (!dropdown.contains(e.target) && !bellBtn.contains(e.target)) closeDropdown();
                });
                document.addEventListener('keydown', (e) => {
                    if (isOpen && e.key === 'Escape') closeDropdown();
                });

                // Refresco del badge tras acciones que pueden cambiar los pendientes.
                window.addEventListener('notif:refresh', () => fetchNotifs(false));
                // Polling silencioso del badge.
                setInterval(() => fetchNotifs(false), POLL_INTERVAL_MS);
                // Primer fetch (no bloqueante).
                setTimeout(() => fetchNotifs(false), 300);

                // Handler del botón "Confirmar" del partial.
                // Abre un mini-modal inline con campo de ubicación (autocompleta las subdivisiones del
                // frente destino) y hace PATCH /admin/equipos/{id}/ubicacion para marcar el equipo en su
                // sección física. No toca el modelo de movilización (el tránsito ya se persistió al
                // crear la movilización — aquí solo ubicamos el equipo dentro del frente).
                window.iniciarRecepcionDesdeDashboard = function (movilizacionId, frenteNombre, subdivisiones, frenteId, equipoId) {
                    closeDropdown();
                    if (!equipoId) {
                        if (typeof window.showModal === 'function') {
                            window.showModal({ type:'error', title:'Error', message:'No se pudo identificar el equipo.', confirmText:'Cerrar', hideCancel:true });
                        }
                        return;
                    }
                    const subs = (subdivisiones && subdivisiones.trim() !== '')
                        ? subdivisiones.split(',').map(s => s.trim()).filter(Boolean)
                        : [];

                    // Limpieza de modal existente
                    const existing = document.getElementById('dashboardRecepcionModal');
                    if (existing) existing.remove();

                    const overlay = document.createElement('div');
                    overlay.id = 'dashboardRecepcionModal';
                    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:20000;display:flex;justify-content:center;align-items:center;';
                    overlay.innerHTML = `
                        <div style="background:#fff;width:95%;max-width:420px;border-radius:16px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3);">
                            <div style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:14px 18px;color:#fff;display:flex;justify-content:space-between;align-items:center;">
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <i class="material-icons" style="font-size:20px;">check_circle</i>
                                    <div>
                                        <h3 style="margin:0;font-size:14px;font-weight:800;">Confirmar Recepción</h3>
                                        <p style="margin:0;font-size:11px;opacity:0.85;">El equipo ha llegado a ${frenteNombre}</p>
                                    </div>
                                </div>
                                <button type="button" data-cancel style="background:rgba(255,255,255,0.2);border:none;color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;">
                                    <i class="material-icons" style="font-size:16px;">close</i>
                                </button>
                            </div>
                            <div style="padding:20px;">
                                <label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">
                                    <i class="material-icons" style="font-size:14px;vertical-align:middle;color:#64748b;">place</i>
                                    Ubicación Específica (Opcional)
                                </label>
                                <div style="position:relative;">
                                    <input type="text" id="dashRdUbicacion" autocomplete="off"
                                        placeholder="Patio, sección, subdivisión…"
                                        style="width:100%;padding:9px 12px;border:1px solid #cbd5e0;border-radius:10px;font-size:13px;background:#f8fafc;outline:none;box-sizing:border-box;"
                                        onfocus="this.style.borderColor='#1e293b'" onblur="this.style.borderColor='#cbd5e0'">
                                    <div id="dashRdSuggestions" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #cbd5e0;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:500;max-height:140px;overflow-y:auto;margin-top:4px;"></div>
                                </div>
                            </div>
                            <div style="padding:0 20px 20px;display:flex;gap:10px;">
                                <button type="button" data-cancel
                                    style="flex:1;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;font-weight:600;color:#64748b;cursor:pointer;">
                                    Cancelar
                                </button>
                                <button type="button" id="dashBtnConfirmarRecep"
                                    style="flex:1;padding:10px;background:#1e293b;border:none;border-radius:10px;font-weight:700;color:#fff;cursor:pointer;transition:background 0.2s;"
                                    onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">
                                    Confirmar
                                </button>
                            </div>
                        </div>`;
                    document.body.appendChild(overlay);

                    const removeModal = () => overlay.remove();
                    overlay.querySelectorAll('[data-cancel]').forEach(b => b.addEventListener('click', removeModal));
                    overlay.addEventListener('click', (e) => { if (e.target === overlay) removeModal(); });

                    // Sugerencias de subdivisiones
                    if (subs.length > 0) {
                        const input  = overlay.querySelector('#dashRdUbicacion');
                        const sugBox = overlay.querySelector('#dashRdSuggestions');
                        subs.forEach(s => {
                            const opt = document.createElement('div');
                            opt.textContent = s;
                            opt.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;';
                            opt.addEventListener('mouseover', () => opt.style.background = '#f1f5f9');
                            opt.addEventListener('mouseout',  () => opt.style.background = '');
                            opt.addEventListener('mousedown', () => { input.value = s; sugBox.style.display = 'none'; });
                            sugBox.appendChild(opt);
                        });
                        input.addEventListener('focus', () => { sugBox.style.display = 'block'; });
                        input.addEventListener('blur',  () => { setTimeout(() => sugBox.style.display = 'none', 150); });
                    }

                    overlay.querySelector('#dashBtnConfirmarRecep').addEventListener('click', async function () {
                        const ubicacion = overlay.querySelector('#dashRdUbicacion').value.trim();
                        this.disabled = true;
                        this.innerHTML = '<i class="material-icons" style="font-size:14px;vertical-align:middle;animation:spin 1s linear infinite;">sync</i> Procesando...';
                        try {
                            const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
                            const res  = await fetch('/admin/equipos/' + equipoId + '/ubicacion', {
                                method: 'PATCH',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-TOKEN': csrf,
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'Accept': 'application/json',
                                },
                                body: JSON.stringify({ DETALLE_UBICACION_ACTUAL: ubicacion || null }),
                            });
                            const data = await res.json();
                            if (!res.ok || !data.success) throw new Error(data.error || 'Error al confirmar.');
                            removeModal();
                            // Retirar visualmente el item de la lista abierta
                            const itemEl = document.getElementById('mov-item-' + movilizacionId);
                            if (itemEl) itemEl.remove();
                            // Refrescar lista + badge
                            window.dispatchEvent(new Event('notif:refresh'));
                            if (typeof window.showToast === 'function') {
                                window.showToast('Recepción confirmada' + (ubicacion ? ' en ' + ubicacion : ''), 'success');
                            }
                        } catch (err) {
                            removeModal();
                            console.error('[iniciarRecepcion]', err);
                            if (typeof window.showModal === 'function') {
                                window.showModal({ type:'error', title:'Error', message: err.message || 'No se pudo confirmar.', confirmText:'Cerrar', hideCancel:true });
                            }
                        }
                    });
                };
            })();
        </script>
        @yield('extra_js')
        @include('partials.session_timeout')
        <script>
            // iOS / Android WebKit Font memory drop workaround
            document.addEventListener("visibilitychange", function () {
                if (document.visibilityState === 'visible' && document.fonts) {
                    document.fonts.load('1em "Material Icons"').then(() => {
                        document.querySelectorAll('.material-icons').forEach(el => {
                            const temp = el.style.display;
                            el.style.display = 'none';
                            el.offsetHeight; // trigger reflow
                            el.style.display = temp;
                        });
                    });
                }
            });
        </script>

        {{-- ===== PWA: registro del Service Worker + banner "Instalar aplicacion" ===== --}}
        <script src="{{ asset('js/pwa-install.js') }}?v={{ @filemtime(public_path('js/pwa-install.js')) }}" defer></script>
</body>

</html>