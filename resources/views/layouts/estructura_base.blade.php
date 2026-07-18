<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Title: SOLO el nombre de la pagina (sin sufijo " · Sistema Vidalsa").
         En la PWA instalada de Windows el sistema agrega automaticamente
         " - <manifest.name>" al titulo de la ventana — si aqui repetimos
         "Sistema Vidalsa" en document.title aparece DOS veces en el borde
         superior ("Inventario de Almacen · Sistema Vidalsa - Sistema Vidalsa"). --}}
    <title>@hasSection('title')@yield('title')@else{{ 'Sistema Vidalsa' }}@endif</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">

    {{-- ===== PWA ===== --}}
    <link rel="manifest" href="{{ asset('manifest.json') }}?v={{ @filemtime(public_path('manifest.json')) }}">
    {{-- Barra de estado (status bar) BLANCA en la PWA, para que cuadre con el fondo de la app. --}}
    {{-- theme-color blanco: la barra de notificaciones del teléfono se muestra
         en blanco (pedido del cliente). --}}
    <meta name="theme-color" content="#ffffff">
    <meta name="application-name" content="Sistema Vidalsa">
    <meta name="mobile-web-app-capable" content="yes">
    {{-- iOS: homescreen / standalone. 'default' = barra de estado blanca con texto/iconos oscuros. --}}
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Sistema Vidalsa">
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

        /* Visor PDF: en movil ocultar el texto, queda solo el icono */
        @media (max-width: 640px) {
            #pdfPrintBtn .btn-label,
            #pdfDownloadBtn .btn-label {
                display: none;
            }
            #pdfPrintBtn,
            #pdfDownloadBtn {
                padding: 6px 9px;
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

        /* Banner de estado de red (#netStatusBanner): es position:fixed top:0 y, sin
           esto, quedaba ENCIMA del header flotante (top:5px) tapándolo. Cuando está
           visible (clase .net-banner-active, puesta por JS) empujamos header + contenido
           hacia abajo su ALTURA REAL (--net-banner-h, medida por JS). */
        body.net-banner-active {
            padding-top: calc(70px + var(--net-banner-h, 0px));
        }

        body.net-banner-active .dashboard-header {
            top: calc(5px + var(--net-banner-h, 0px));
        }

        @media (max-width: 480px) {
            #netStatusBanner {
                font-size: 11.5px !important;
                padding: 7px 10px !important;
                gap: 6px !important;
            }
            #netStatusBanner .material-icons { font-size: 16px !important; }
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
    <style>

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
            /* Empujar el grupo de acciones (hamburger) hacia la derecha. */
            .dashboard-header { justify-content: flex-start !important; }
            .header-actions { margin-left: auto !important; gap: 0; }
            .menu-toggle.mobile-only { margin-left: 6px !important; padding: 4px 6px; }
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
                // Conexión rechazada (servidor caído, sin red, etc.). El error se
                // relanza para que el caller (ej. fetchNotifs) decida qué hacer.
                // El console.warn anterior generaba ruido en cada poll cuando no
                // habia red — eliminado.
                throw err;
            }
        };
    </script>

    <script>
        // Marca <html> con .is-ios en dispositivos Apple táctiles (iPhone/iPad). El CSS
        // lo usa para MOSTRAR el botón "+" de "Nueva entrada (ODC)" SOLO en iOS, cuyo
        // teclado decimal no trae tecla Enter. En Android/PC la línea se agrega con Enter
        // y el botón va oculto. Corre una vez al cargar y persiste entre navegaciones SPA
        // (documentElement no se reemplaza).
        (function () {
            var ua = navigator.userAgent || '';
            var isIOS = /iPad|iPhone|iPod/.test(ua) ||
                        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            if (isIOS) document.documentElement.classList.add('is-ios');
        })();
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
    <script>
        // Primera carga tras el login: ocultamos el preloader general (el splash con
        // logo de la pantalla de login ya cubrió la transición; evita el "segundo
        // spinner"). Solo cambia 'display' — NO se toca el contenido, para que las
        // operaciones internas sigan mostrando su spinner-circle normal.
        try {
            if (sessionStorage.getItem('vidalsaJustLoggedIn')) {
                sessionStorage.removeItem('vidalsaJustLoggedIn');
                var __plLogin = document.getElementById('preloader');
                if (__plLogin) __plLogin.style.display = 'none';
            }
        } catch (e) {}
    </script>

    {{-- Banner global de estado de red. Se muestra cuando window.addEventListener('offline')
         dispara o cuando navigator.onLine === false al cargar la app. Persiste hasta que vuelva
         la conexion (no se auto-oculta). Cuando vuelve la red, muestra brevemente "Conexion
         restaurada" en verde y luego desaparece. La logica esta en el bloque <script> de abajo. --}}
    <div id="netStatusBanner" role="status" aria-live="polite"
         style="position:fixed;top:0;left:0;right:0;z-index:1000001;display:none;
                align-items:center;justify-content:center;gap:8px;
                padding:8px 16px;font-size:13px;font-weight:600;
                background:#dc2626;color:#fff;
                box-shadow:0 2px 6px rgba(0,0,0,0.15);
                transform:translateY(-100%);transition:transform 0.3s ease;
                font-family:'Inter','Segoe UI',sans-serif;">
        <i class="material-icons" id="netStatusIcon" style="font-size:18px;">wifi_off</i>
        <span id="netStatusText" style="white-space:nowrap;">Sin conexión a internet</span>
        {{-- Botón que OFRECE pasar a la versión offline (no se cambia solo). Solo
             aparece sin conexión y si el módulo actual tiene render offline. --}}
        <button id="netStatusAction" type="button"
                style="display:none;margin-left:10px;background:#fff;color:#dc2626;border:none;border-radius:6px;padding:4px 12px;font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap;">
            Trabajar sin conexión
        </button>
    </div>

    {{-- ── Bandeja de cambios sin conexión (Fase 2) ──────────────────────────────
         Badge flotante visible cuando hay acciones en el outbox (por subir o con
         error). Click → panel con la lista; permite Reintentar / Descartar /
         Subir ahora. Se refresca con el evento 'outbox-actualizado'. --}}
    <div id="outboxTray" style="position:fixed;left:16px;bottom:16px;z-index:1000002;display:none;font-family:'Inter','Segoe UI',sans-serif;">
        <button id="outboxTrayBtn" type="button" title="Cambios sin conexión pendientes de subir"
                style="display:flex;align-items:center;gap:7px;background:#0067b1;color:#fff;border:none;border-radius:999px;padding:9px 14px;font-size:13px;font-weight:800;cursor:pointer;box-shadow:0 6px 16px rgba(0,0,0,0.22);">
            <i class="material-icons" style="font-size:18px;">cloud_upload</i>
            <span id="outboxTrayCount">0</span>
        </button>
        <div id="outboxTrayPanel" style="display:none;position:absolute;bottom:52px;left:0;width:330px;max-width:calc(100vw - 32px);max-height:60vh;overflow:auto;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 14px 34px rgba(0,0,0,0.22);">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 14px;border-bottom:1px solid #f1f5f9;">
                <strong style="font-size:13px;color:#1e293b;">Cambios sin conexión</strong>
                <button id="outboxTraySubir" type="button" style="background:#0067b1;color:#fff;border:none;border-radius:7px;padding:5px 10px;font-size:11.5px;font-weight:700;cursor:pointer;">Subir ahora</button>
            </div>
            <div id="outboxTrayList"></div>
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

            {{-- Flota Dropdown: agrupa Equipos y Maquinarias + Reporte de Fallas + Consumibles + Historial --}}
            <div class="nav-dropdown">
                <a href="#"
                    class="nav-link {{ (request()->is('admin/equipos') || request()->is('admin/equipos/*') || request()->is('admin/equipos-auxiliares*') || request()->is('admin/fallas*') || request()->is('admin/consumibles*') || request()->is('admin/movilizaciones*')) ? 'active' : '' }}"
                    style="display: flex; align-items: center; gap: 4px;">
                    <i class="material-icons" style="font-size: 18px;">agriculture</i>Flota
                    <i class="material-icons" style="font-size: 16px;">expand_more</i>
                </a>
                <div class="nav-dropdown-content">
                    <a href="{{ route('equipos.index') }}"
                        class="nav-dropdown-link {{ request()->is('admin/equipos') || request()->is('admin/equipos/*') ? 'active' : '' }}">
                        <i class="material-icons">agriculture</i> Equipos y Maquinarias
                    </a>
                    <a href="{{ route('fallas.index') }}"
                        class="nav-dropdown-link {{ request()->is('admin/fallas*') ? 'active' : '' }}">
                        <i class="material-icons">report_problem</i> Reporte de Fallas
                    </a>
                    <a href="{{ route('consumibles.graficos') }}"
                        class="nav-dropdown-link {{ request()->is('admin/consumibles*') ? 'active' : '' }}">
                        <i class="material-icons">local_gas_station</i> Consumibles
                    </a>
                    <a href="{{ route('movilizaciones.index') }}"
                        class="nav-dropdown-link {{ request()->is('admin/movilizaciones*') ? 'active' : '' }}">
                        <i class="material-icons">local_shipping</i> Historial
                    </a>
                </div>
            </div>

            @php
                // URL de "Recepción" según el nivel de ALMACÉN del usuario (veTodosLosAlmacenes
                // == el MISMO criterio que Almacen::usuarioEsGlobal del controller, así no hay
                // desfase). Ojo: es el nivel de almacén, NO el de equipos:
                //   LOCAL  → BANDEJA explícita (?force=1) PRESELECCIONADA a SU almacén (el
                //     ligado a su frente, vía almacenPorDefecto). El id_almacen_destino en la
                //     URL garantiza la preselección sin depender solo del merge del controller.
                //   GLOBAL → /recepcion a secas → el controller lo redirige a "Entrada por
                //     ODC". NO se le pone id_almacen_destino: si lo lleva, el controller NO
                //     redirige a ODC (cuenta como filtro) y se quedaría en la bandeja.
                // Se calcula una sola vez y se reutiliza en el menú desktop y móvil.
                $__recUser     = auth()->user();
                $__recEsGlobal = $__recUser && $__recUser->veTodosLosAlmacenes();
                $__recAlmacen  = $__recUser ? $__recUser->almacenPorDefecto() : null; // almacén del frente
                $recepcionUrl  = $__recEsGlobal
                    ? route('almacen.recepcion.index')
                    : route('almacen.recepcion.index', array_filter(['force' => 1, 'id_almacen_destino' => $__recAlmacen]));
            @endphp
            {{-- Almacén Dropdown: Inventario + Recepción (con badge si hay envíos pendientes) + Kardex --}}
            <div class="nav-dropdown">
                <a href="#"
                    class="nav-link {{ request()->is('admin/almacen*') ? 'active' : '' }}"
                    style="display: flex; align-items: center; gap: 4px;">
                    <i class="material-icons" style="font-size: 18px;">warehouse</i>Almacén
                    <i class="material-icons" style="font-size: 16px;">expand_more</i>
                </a>
                <div class="nav-dropdown-content">
                    <a href="{{ route('almacen.index') }}"
                        class="nav-dropdown-link {{ request()->routeIs('almacen.index') ? 'active' : '' }}">
                        <i class="material-icons">inventory_2</i> Inventario
                    </a>
                    <a href="{{ $recepcionUrl }}"
                        class="nav-dropdown-link {{ request()->routeIs('almacen.recepcion.*') ? 'active' : '' }}"
                        style="display:flex;align-items:center;gap:8px;justify-content:space-between;">
                        <span style="display:flex;align-items:center;gap:8px;">
                            <i class="material-icons">move_to_inbox</i> Recepción
                        </span>
                        @php $__nav_traspasosPorRecibir = $traspasosPorRecibir ?? 0; @endphp
                        <span id="navBadgeRecepcion" data-count="{{ $__nav_traspasosPorRecibir }}"
                            style="background:#ef4444;color:#fff;border-radius:999px;padding:1px 7px;font-size:10.5px;font-weight:800;min-width:18px;text-align:center;line-height:1.5;{{ $__nav_traspasosPorRecibir > 0 ? '' : 'display:none;' }}">{{ $__nav_traspasosPorRecibir }}</span>
                    </a>
                    <a href="{{ route('almacen.movimientos') }}"
                        class="nav-dropdown-link {{ request()->routeIs('almacen.movimientos') ? 'active' : '' }}">
                        <i class="material-icons">receipt_long</i> Historial
                    </a>
                </div>
            </div>

            <!-- Configuraciones Dropdown -->
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
                    {{-- "Frentes de trabajo": módulo EXCLUSIVO super.admin (clave literal en
                         PERMISOS, sin relación con el rol). Visible para todos, pero solo navega
                         si tiene la clave; sin ella muestra la notificación moderna (showToast)
                         en vez de ir a un 403. El gate real vive en la ruta (can:super.admin). --}}
                    @can('super.admin')
                    <a href="{{ route('frentes.index') }}"
                        class="nav-dropdown-link {{ request()->is('admin/frentes*') ? 'active' : '' }}">
                        <i class="material-icons">business</i> Frentes de trabajo
                    </a>
                    @else
                    <a href="#" class="nav-dropdown-link"
                        onclick="event.preventDefault(); if (window.showToast) { window.showToast('No tienes permiso para acceder a Frentes de trabajo.', 'error'); }">
                        <i class="material-icons">business</i> Frentes de trabajo
                    </a>
                    @endcan
                    @can('super.admin')
                    <a href="{{ route('historial-documentos.index') }}"
                        class="nav-dropdown-link {{ request()->routeIs('historial-documentos.*') ? 'active' : '' }}">
                        <i class="material-icons">fact_check</i> Control de Auditoría
                    </a>
                    @endcan
                    {{-- Baja AHORA una copia de la base de datos a IndexedDB para poder
                         trabajar SIN internet (snapshot manual → OfflineDB.sync(true)).
                         La copia también se baja sola cada cierto tiempo; esto la fuerza. --}}
                    <a href="#" class="nav-dropdown-link" onclick="window.descargarSnapshotOffline(event)">
                        <i class="material-icons">cloud_download</i> Copia local
                    </a>
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

        {{-- Flota: grupo colapsable con Vehiculo + Activos Auxiliares + Reporte de Fallas + Consumibles + Historial --}}
        <div class="mobile-nav-group" id="mobileFlotaGroup">
            <div class="mobile-nav-group-title">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="material-icons">agriculture</i>
                    Flota
                </div>
                <i class="material-icons chevron">expand_more</i>
            </div>
            <div class="mobile-nav-group-content">
                <a href="{{ route('equipos.index') }}"
                    class="mobile-nav-link {{ request()->is('admin/equipos') || request()->is('admin/equipos/*') ? 'active' : '' }}">
                    <i class="material-icons">agriculture</i> Equipos y Maquinarias
                </a>
                <a href="{{ route('fallas.index') }}"
                    class="mobile-nav-link {{ request()->is('admin/fallas*') ? 'active' : '' }}">
                    <i class="material-icons">report_problem</i> Reporte de Fallas
                </a>
                <a href="{{ route('consumibles.graficos') }}"
                    class="mobile-nav-link {{ request()->is('admin/consumibles*') ? 'active' : '' }}">
                    <i class="material-icons">local_gas_station</i> Consumibles
                </a>
                <a href="{{ route('movilizaciones.index') }}"
                    class="mobile-nav-link {{ request()->is('admin/movilizaciones*') ? 'active' : '' }}">
                    <i class="material-icons">local_shipping</i> Historial
                </a>
            </div>
        </div>
        <div class="mobile-nav-group" id="mobileAlmacenGroup">
            <div class="mobile-nav-group-title">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="material-icons">warehouse</i>
                    Almacén
                </div>
                <i class="material-icons chevron">expand_more</i>
            </div>
            <div class="mobile-nav-group-content">
                <a href="{{ route('almacen.index') }}"
                    class="mobile-nav-link {{ request()->routeIs('almacen.index') ? 'active' : '' }}">
                    <i class="material-icons">inventory_2</i> Inventario
                </a>
                <a href="{{ $recepcionUrl }}"
                    class="mobile-nav-link {{ request()->routeIs('almacen.recepcion.*') ? 'active' : '' }}"
                    style="display:flex;align-items:center;gap:10px;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:10px;">
                        <i class="material-icons">move_to_inbox</i> Recepción
                    </span>
                    @php $__nav_traspasosPorRecibir_m = $traspasosPorRecibir ?? 0; @endphp
                    <span id="navBadgeRecepcionMobile" data-count="{{ $__nav_traspasosPorRecibir_m }}"
                        style="background:#ef4444;color:#fff;border-radius:999px;padding:1px 7px;font-size:10.5px;font-weight:800;min-width:18px;text-align:center;line-height:1.5;{{ $__nav_traspasosPorRecibir_m > 0 ? '' : 'display:none;' }}">{{ $__nav_traspasosPorRecibir_m }}</span>
                </a>
                <a href="{{ route('almacen.movimientos') }}"
                    class="mobile-nav-link {{ request()->routeIs('almacen.movimientos') ? 'active' : '' }}">
                    <i class="material-icons">receipt_long</i> Historial
                </a>
            </div>
        </div>

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
                @can('super.admin')
                <a href="{{ route('historial-documentos.index') }}"
                    class="mobile-nav-link {{ request()->routeIs('historial-documentos.*') ? 'active' : '' }}">
                    <i class="material-icons">fact_check</i> Control de Auditoría
                </a>
                @endcan
                {{-- Descargar copia de la base de datos para trabajar SIN internet. --}}
                <a href="#" class="mobile-nav-link" onclick="window.descargarSnapshotOffline(event)">
                    <i class="material-icons">cloud_download</i> Copia local
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

        {{-- Bridge Blade -> sessionStorage: si el backend redirigio via
             redirect()->back()->with('flash_toast', [...]) (ej. handler 403
             global en bootstrap/app.php), tomamos ese flash y lo movemos a
             sessionStorage para que el script siguiente lo renderice como
             toast en lugar del modal feo default. --}}
        @if(session('flash_toast'))
            @php $ft = session('flash_toast'); @endphp
            <script>
                (function () {
                    try {
                        sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                            message: @json($ft['message'] ?? ''),
                            type:    @json($ft['type'] ?? 'error'),
                        }));
                    } catch (_) {}
                })();
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
                // En navegaciones SPA, leer el toast nuevo del destino. El flag
                // window.__vidalsaRedirecting lo libera loadPage() en su finally
                // (punto único, cubre éxito y error); no se toca aquí para no duplicar.
                window.addEventListener('spa:contentLoaded', function () {
                    _flushFlashToast();
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
                        title="Descargar"
                        style="background: #3182ce; border: none; padding: 6px 12px; font-size: 12px; display: flex; align-items: center; gap: 5px; color: white; border-radius: 4px;">
                        <i class="material-icons" style="font-size: 16px;">download</i><span class="btn-label">Descargar</span>
                    </button>

                    <button id="pdfPrintBtn" type="button" onclick="printPdfFromPreview()"
                        title="Imprimir"
                        style="background: #6366f1; border: none; padding: 6px 12px; font-size: 12px; display: flex; align-items: center; gap: 5px; color: white; border-radius: 4px;">
                        <i class="material-icons" style="font-size: 16px;">print</i><span class="btn-label">Imprimir</span>
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

                    {{-- Boton borrar documento: destructivo. Visible y funcional SOLO para super.admin.
                         Borra el archivo del Google Drive Y limpia el registro en BD. --}}
                    @if(auth()->user() && auth()->user()->can('super.admin'))
                        <button id="pdfDeleteBtn" type="button"
                            onclick="deletePdfFromPreview()"
                            style="background: #dc2626; border: none; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center; color: white; border-radius: 50%; transition: transform 0.2s; cursor: pointer;"
                            onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'"
                            title="Eliminar Documento (Drive + BD)">
                            <i class="material-icons" style="font-size: 18px;">delete</i>
                        </button>
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
            max-width: 300px !important;
        }
        #standardModal .modal-title {
            font-size: 1.1rem !important;
            margin-bottom: 5px !important;
        }
        #standardModal .modal-message {
            font-size: 0.85rem !important;
            margin-bottom: 15px !important;
            max-height: 50vh;
            overflow-y: auto;
            word-break: break-word;
            white-space: pre-wrap;
            text-align: center;
            padding: 0 4px;
        }
        #standardModal .modal-title { text-align: center; }
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
            // Global Preloader Controls — CON CONTADOR DE REFERENCIAS.
            //
            // Motivo: un ÚNICO spinner global es compartido por varias operaciones
            // async. El caso más común: al entrar a un módulo (navegación SPA) se
            // muestra el spinner, se inyecta el HTML del módulo y su script de init
            // lanza un SEGUNDO fetch (los datos de la tabla/filtro). SIN contador, el
            // hidePreloader de la navegación ocultaba el spinner apenas llegaba el
            // cascarón HTML, aunque el fetch de datos siguiera en vuelo → con internet
            // lento el spinner desaparecía y los datos filtrados aparecían unos
            // segundos DESPUÉS. CON contador, cada show() suma y cada hide() resta: el
            // spinner solo se oculta cuando TODAS las operaciones que lo pidieron
            // terminaron (es decir, cuando los datos ya están pintados en pantalla).
            //
            // hidePreloader(true) FUERZA el reset del contador y oculta de inmediato:
            // lo usan los watchdogs anti-congelado (ver navegacion.js).
            let _preloaderRefs = 0;

            window.showPreloader = function () {
                _preloaderRefs++;
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

            window.hidePreloader = function (force) {
                if (force === true) {
                    _preloaderRefs = 0;
                } else {
                    _preloaderRefs = Math.max(0, _preloaderRefs - 1);
                    // Aún hay operaciones en vuelo → mantener el spinner visible.
                    if (_preloaderRefs > 0) return;
                }
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

            // Ocultar el preloader INICIAL cuando todo (imágenes/iconos) haya cargado,
            // PERO solo si ninguna operación lo está usando (refs===0). Si el script de
            // init de un módulo ya pidió el spinner para su primer fetch (refs>0), NO lo
            // tocamos aquí: se ocultará cuando ese fetch termine de pintar los datos.
            // Sin esta guarda, en una carga de página COMPLETA (no-SPA) el window.load
            // bajaba el contador del init y reaparecía el mismo bug (spinner antes que datos).
            window.addEventListener('load', function() {
                if (_preloaderRefs === 0 && typeof window.hidePreloader === 'function') {
                    window.hidePreloader(true);
                }
            });

            // ── BANNER DE ESTADO DE RED ──────────────────────────────────────
            // Se engancha a los eventos online/offline del browser para mostrar/ocultar
            // el #netStatusBanner. Tambien chequea estado inicial al cargar la pagina,
            // por si la app se abrio ya sin conexion. Expuesto en window.netStatus para
            // que navegacion.js pueda forzar el aviso cuando un fetch falla aunque
            // navigator.onLine diga lo contrario (red marca pero servidor caido).
            (function () {
                const banner = document.getElementById('netStatusBanner');
                if (!banner) return;
                const icon = document.getElementById('netStatusIcon');
                const text = document.getElementById('netStatusText');
                let hideTimer = null;

                function showBanner(message, iconName, bgColor, autoHideMs) {
                    if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
                    banner.style.background = bgColor;
                    icon.textContent = iconName;
                    text.textContent = message;
                    banner.style.display = 'flex';
                    // Empujar header + contenido hacia abajo la ALTURA REAL del banner para
                    // que no lo tape (se re-mide en cada showBanner: el texto cambia entre
                    // estados y en móvil puede ocupar 2 líneas). offsetHeight ya es válido
                    // con display:flex; el transform del slide no lo afecta.
                    document.documentElement.style.setProperty('--net-banner-h', banner.offsetHeight + 'px');
                    document.body.classList.add('net-banner-active');
                    requestAnimationFrame(() => {
                        banner.style.transform = 'translateY(0)';
                    });
                    if (autoHideMs && autoHideMs > 0) {
                        hideTimer = setTimeout(hideBanner, autoHideMs);
                    }
                }
                function hideBanner() {
                    banner.style.transform = 'translateY(-100%)';
                    // Restaurar el layout: el header vuelve a top:5px (con su transición) y
                    // el body a su padding normal — en sync con el slide-up del banner.
                    document.body.classList.remove('net-banner-active');
                    document.documentElement.style.removeProperty('--net-banner-h');
                    setTimeout(() => { banner.style.display = 'none'; }, 300);
                }

                // ── Modo OFFLINE: se OFRECE, no se cambia solo ──────────────────
                // Los módulos con vista offline registran su render aquí (OfflineMode).
                // Sin conexión, el banner rojo muestra el botón "Trabajar sin conexión";
                // al tocarlo se pinta la versión local y el banner pasa a ámbar.
                //
                // Registro POR CLAVE (no array): la navegación SPA re-ejecuta el script del
                // módulo en cada visita; con clave se SOBREESCRIBE en vez de acumular (sin
                // fuga). Cada render se guarda con su propio guard (pinta solo si su tabla
                // sigue en el DOM), así los módulos que ya no están en pantalla no hacen nada.
                const action = document.getElementById('netStatusAction');
                const renders = {};
                let offlineActivo = false;
                let sinConexion   = false; // true mientras el banner muestra estado offline
                                           // (NO usar navigator.onLine: miente con el server caído)

                function correrRenders() {
                    Object.keys(renders).forEach(function (k) { try { renders[k](); } catch (e) {} });
                }
                function mostrarOffline() {
                    sinConexion = true;
                    showBanner('Sin conexión a internet', 'wifi_off', '#dc2626', 0);
                    // AUTO-activar el modo offline si el módulo actual tiene vista offline
                    // registrada. Antes era MANUAL (botón "Trabajar sin conexión"); si el
                    // usuario no lo pulsaba, los filtros seguían llamando al servidor caído y
                    // "no filtraban nada". Ahora funciona solo. El botón queda de respaldo si
                    // aún no hay render registrado en este instante.
                    if (Object.keys(renders).length && !offlineActivo) {
                        activarOffline();
                    } else if (action) {
                        action.style.display = 'none';
                    }
                }
                function activarOffline() {
                    if (offlineActivo) return;
                    offlineActivo = true;
                    if (action) action.style.display = 'none';
                    correrRenders();
                    // Banner ámbar con la fecha de la copia local (si OfflineDB ya cargó).
                    var pintar = function (cuando) {
                        showBanner('Trabajando sin conexión' + (cuando || ''), 'cloud_off', '#b45309', 0);
                    };
                    if (window.OfflineDB) {
                        window.OfflineDB.meta().then(function (m) {
                            var c = '';
                            if (m && m.generado) { var d = new Date(m.generado); if (!isNaN(d)) c = ' · ' + d.toLocaleString('es-VE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
                            pintar(c);
                        }).catch(function () { pintar(''); });
                    } else { pintar(''); }
                }
                if (action) action.addEventListener('click', activarOffline);

                window.addEventListener('offline', mostrarOffline);
                window.addEventListener('online', function () {
                    sinConexion = false;
                    if (action) action.style.display = 'none';
                    // Si se estaba TRABAJANDO en modo offline, el módulo quedó pintado
                    // con la copia local y sus handlers en modo offline: sin esto la
                    // pantalla se queda "congelada" en esa vista hasta que el usuario
                    // adivine que debe recargar. Verificamos conexión REAL primero
                    // (navigator.onLine miente en parpadeos de red) y recargamos: la
                    // recarga restaura la vista de servidor — y si se entró con el
                    // login offline (sin sesión de servidor), el redirect natural
                    // lleva al login para reconectarse.
                    if (offlineActivo) {
                        showBanner('Conexión restaurada · actualizando…', 'wifi', '#16a34a', 0);
                        fetch('/offline/version', { method: 'GET', cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                            .then(function () { window.location.reload(); })
                            .catch(function () { mostrarOffline(); }); // seguía sin servidor: volver al modo offline
                        return;
                    }
                    offlineActivo = false;
                    showBanner('Conexión restaurada', 'wifi', '#16a34a', 2500);
                });
                // Si baja una copia nueva mientras se trabaja offline, repintar el módulo
                // visible. (La re-pintada al navegar por SPA la hace cada módulo en su
                // propio init sobre 'spa:contentLoaded', no aquí, para no duplicar.)
                window.addEventListener('offline-datos-actualizados', function () { if (offlineActivo) correrRenders(); });

                // ── Detección de conexión REAL ──────────────────────────────────
                // navigator.onLine NO es confiable: en el navegador suele decir "online"
                // aunque el SERVIDOR esté caído (solo refleja la interfaz de red). Por eso
                // sondeamos /offline/version (que el SW NUNCA cachea — ver sw.js): si el
                // fetch falla, no hay servidor → mostramos el aviso. Esto hace que el
                // banner salga también en /menu y en el navegador (no solo en la PWA ni
                // solo al desconectar el wifi). En /menu solo sale el aviso informativo
                // (sin botón) porque ese módulo no tiene vista offline registrada.
                function comprobarConexion() {
                    if (offlineActivo) return;                 // ya en modo offline manual: no repintar
                    if (!navigator.onLine) { mostrarOffline(); return; }
                    // Cualquier RESPUESTA (aunque sea 401/500) significa que el servidor
                    // responde → estamos online. Solo el fallo de red (catch) = sin conexión.
                    fetch('/offline/version', { method: 'GET', cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function () { if (window.OfflineOutbox) window.OfflineOutbox.drain(); }) // servidor OK → subir outbox
                        .catch(function () { mostrarOffline(); });
                }

                // Estado inicial al cargar: comprobar conexión real (no solo navigator.onLine).
                comprobarConexion();

                window.netStatus = { showOffline: mostrarOffline, hide: hideBanner, comprobar: comprobarConexion };

                // API para los módulos. registrar(clave, fn): clave única por módulo.
                // Helpers compartidos (esc, conOfflineDB) para no duplicarlos en cada módulo.
                window.OfflineMode = {
                    registrar: function (clave, fn) {
                        renders[clave] = fn;
                        // Si YA estamos sin conexión cuando el módulo se registra (p.ej. se
                        // navegó a equipos estando offline), auto-activar en vez de solo
                        // ofrecer el botón — así los filtros offline funcionan de inmediato.
                        if (sinConexion && !offlineActivo) activarOffline();
                    },
                    activar: activarOffline,
                    estaActivo: function () { return offlineActivo; },
                    esc: function (s) {
                        return String(s == null ? '' : s)
                            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    },
                    // Normalización para buscar (sin acentos + minúsculas, vía FuzzySearch si
                    // cargó) con separadores colapsados a espacio: el texto que deja una
                    // sugerencia clickeada es "PARTE · NOMBRE" y sin esto nunca coincidiría
                    // con el haystack "codigo nombre". La usan los motores de filtro offline.
                    norm: function (s) {
                        var base = (window.FuzzySearch && window.FuzzySearch.norm)
                            ? window.FuzzySearch.norm(s)
                            : String(s == null ? '' : s).toLowerCase();
                        return base.replace(/[^a-z0-9ñ]+/g, ' ').trim();
                    },
                    // Números en formato latino (1.234,5 — hasta 3 decimales sin ceros
                    // sobrantes), réplica EXACTA de number_format(n,3,',','.') de las tablas
                    // online. No usa toLocaleString('es-ES'): ese locale NO agrupa miles en
                    // números de 4 dígitos (1234,5) y desentonaría con las filas online.
                    fmt: function (n) {
                        var v = Number(n) || 0;
                        var neg = v < 0 ? '-' : '';
                        var s = Math.abs(v).toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
                        var p = s.split('.');
                        p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                        return neg + (p[1] ? p[0] + ',' + p[1] : p[0]);
                    },
                    // Espera a que OfflineDB (offline-sync.js, al final del body) esté listo.
                    conOfflineDB: function (cb) {
                        if (window.OfflineDB) return cb();
                        var n = 0;
                        var t = setInterval(function () {
                            if (window.OfflineDB) { clearInterval(t); cb(); }
                            else if (++n > 50) { clearInterval(t); }
                        }, 100);
                    }
                };
            })();

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

        {{-- Helpers DOM compartidos (window.getCsrf / window.escapeHtml): DEBEN cargar
             primero para estar disponibles cuando el resto de scripts los invoque. --}}
        <script
            src="{{ asset('js/maquinaria/dom_helpers.js') }}?v={{ @filemtime(public_path('js/maquinaria/dom_helpers.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/module_manager.js') }}?v={{ @filemtime(public_path('js/maquinaria/module_manager.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/uicomponents.js') }}?v={{ @filemtime(public_path('js/maquinaria/uicomponents.js')) }}"></script>
        {{-- Buscador "estilo Google" compartido (window.FuzzySearch): lo usan Inventario
             y Recepción. Global aquí → sobrevive a la navegación SPA. --}}
        <script
            src="{{ asset('js/maquinaria/fuzzy_search.js') }}?v={{ @filemtime(public_path('js/maquinaria/fuzzy_search.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/navegacion.js') }}?v={{ @filemtime(public_path('js/maquinaria/navegacion.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/form_logic.js') }}?v={{ @filemtime(public_path('js/maquinaria/form_logic.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/equipo_catalog_linking.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipo_catalog_linking.js')) }}"></script>

        {{-- Chart.js GLOBAL: lo usa el modal "Dashboard de Consumo" (Acciones de
             /admin/almacen y /admin/almacen/movimientos). Debe ir aquí (global) y no
             en la vista: la SPA omite los <script src> dentro de @section('content'),
             así que cargarlo por página no sobreviviría la navegación. --}}
        <script
            src="{{ asset('js/chart.umd.min.js') }}?v={{ @filemtime(public_path('js/chart.umd.min.js')) }}"></script>

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
            src="{{ asset('js/maquinaria/fallas_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/fallas_index.js')) }}"></script>
        {{-- Modal "Nuevo Reporte de Falla" COMPARTIDO (fallas/equipos/auxiliares). Global
             porque la SPA no re-ejecuta los <script> del contenido al navegar. --}}
        <script
            src="{{ asset('js/maquinaria/falla_create_modal.js') }}?v={{ @filemtime(public_path('js/maquinaria/falla_create_modal.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/usuarios_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/usuarios_index.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/historial_documentos_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/historial_documentos_index.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/fleet_dashboard.js') }}?v={{ @filemtime(public_path('js/maquinaria/fleet_dashboard.js')) }}"></script>
        {{-- Módulo "Mapa Satelital": global (la SPA no re-ejecuta los <script> del
             contenido). Carga Leaflet de forma diferida e inicializa en spa:contentLoaded. --}}
        <script
            src="{{ asset('js/maquinaria/mapa_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/mapa_index.js')) }}"></script>

        <script
            src="{{ asset('js/maquinaria/frentes_spa.js') }}?v={{ @filemtime(public_path('js/maquinaria/frentes_spa.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/consumibles_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_index.js')) }}"></script>
        <script>
            // Colapsa todos los grupos expandidos del menu mobile (Flota,
            // Configuraciones, etc). Reusable para cuando el menu se cierra:
            // antes el grupo abierto quedaba "recordando" su estado y al
            // reabrir el hamburguesa seguia desplegado.
            function _mobileNavCollapseAll() {
                document.querySelectorAll('.mobile-nav-group.active').forEach(g => {
                    g.classList.remove('active');
                });
            }

            function toggleMobileMenu() {
                const menu = document.getElementById('mobileMenu');
                if (!menu) return;
                const willOpen = !menu.classList.contains('active');
                menu.classList.toggle('active');
                // Si lo estamos cerrando, colapsa todos los grupos para que el
                // proximo open no muestre estado residual.
                if (!willOpen) _mobileNavCollapseAll();
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
                    _mobileNavCollapseAll();
                });
            }

            // Toggle Mobile Groups (Flota, Configuraciones, etc.) — event delegation
            // para que funcione con cualquier grupo sin nombrarlos uno por uno y
            // para sobrevivir re-renders SPA (delegacion en document, idempotente).
            if (!window._mobileNavGroupDelegated) {
                window._mobileNavGroupDelegated = true;
                document.addEventListener('click', (e) => {
                    const title = e.target.closest('.mobile-nav-group-title');
                    if (!title) return;
                    const group = title.closest('.mobile-nav-group');
                    if (!group) return;
                    e.stopPropagation();
                    // Acordeón: solo UN grupo desplegado a la vez. Cerramos todos y, si
                    // el tocado no estaba abierto, lo abrimos → al desplegar uno se
                    // recoge el otro. Clic en el que ya estaba abierto = se cierra.
                    const yaAbierto = group.classList.contains('active');
                    document.querySelectorAll('.mobile-nav-group.active').forEach(g => g.classList.remove('active'));
                    if (!yaAbierto) group.classList.add('active');
                });
            }

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
                    downloadBtn.innerHTML = '<span class="material-icons" style="font-size: 16px; animation: spin 1s linear infinite;">sync</span><span class="btn-label">Descargando...</span>';
                }

                // Generate filename
                let filename = 'documento.pdf';
                if (documentLabel) {
                    const cleanLabel = documentLabel.toLowerCase().replace(/\s+/g, '_').replace(/[^a-z0-9_]/g, '');
                    filename = cleanLabel + '.pdf';
                }

                const restoreBtn = function () {
                    if (downloadBtn) {
                        downloadBtn.disabled = false;
                        downloadBtn.innerHTML = '<span class="material-icons" style="font-size: 16px;">download</span><span class="btn-label">Descargar</span>';
                    }
                };

                // Descarga con un <a download> apuntando a una URL (blob o directa).
                const downloadViaAnchor = function (href, revoke) {
                    const a = document.createElement('a');
                    a.href = href;
                    a.download = filename;
                    a.setAttribute('data-no-spa', 'true');
                    a.style.display = 'none';
                    document.body.appendChild(a);
                    a.click();
                    // Quitar el <a> y restaurar el botón rápido; pero si era un blob, NO lo
                    // revocamos aún: si el navegador muestra "Guardar como", la descarga no
                    // inicia hasta que el usuario confirme — revocar antes la cancelaría.
                    setTimeout(function () {
                        if (a.parentNode) a.parentNode.removeChild(a);
                        restoreBtn();
                    }, 800);
                    if (revoke) setTimeout(function () { URL.revokeObjectURL(href); }, 60000);
                };

                // Estrategia robusta: traemos el PDF con fetch y lo descargamos como BLOB.
                // Así SIEMPRE se DESCARGA (guarda el archivo) y NO se ABRE en el visor, aunque
                // el navegador ignore el atributo `download` (lo ignora cuando la URL no es del
                // mismo origen — p.ej. si redirige a Drive). Mismo enfoque que printPdfFromPreview.
                // Si el fetch falla (CORS, red), caemos al enlace directo (comportamiento previo).
                fetch(url, { credentials: 'include', cache: 'force-cache' })
                    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
                    .then(function (blob) { downloadViaAnchor(URL.createObjectURL(blob), true); })
                    .catch(function () { downloadViaAnchor(url, false); });
            };

            // Imprime el PDF que esta en el visor sin descargarlo. Estrategia:
            //   1) iframe.contentWindow.print() directo — solo funciona si el PDF
            //      es same-origin (raro con Drive, pero gratis intentarlo).
            //   2) fetch -> Blob -> iframe oculto -> print() — funciona si el
            //      navegador ya tiene el PDF en cache (lo trae del cache HTTP).
            //      Si Drive bloquea por CORS, esta opcion falla.
            //   3) Fallback: abrir en pestana nueva. El usuario imprime con Ctrl+P.
            window.printPdfFromPreview = function () {
                const printBtn = document.getElementById('pdfPrintBtn');
                const dlBtn = document.getElementById('pdfDownloadBtn');
                const url = dlBtn ? dlBtn.dataset.url : '';
                if (!url) {
                    alert('No hay documento para imprimir.');
                    return;
                }

                const setBtnLoading = (loading) => {
                    if (!printBtn) return;
                    printBtn.disabled = loading;
                    printBtn.innerHTML = loading
                        ? '<span class="material-icons" style="font-size: 16px; animation: spin 1s linear infinite;">sync</span><span class="btn-label">Preparando...</span>'
                        : '<i class="material-icons" style="font-size: 16px;">print</i><span class="btn-label">Imprimir</span>';
                };

                // 1) Intento same-origin sobre el iframe ya abierto
                try {
                    const visibleFrame = document.getElementById('pdfPreviewFrame');
                    if (visibleFrame && visibleFrame.contentWindow && visibleFrame.style.display !== 'none') {
                        visibleFrame.contentWindow.focus();
                        visibleFrame.contentWindow.print();
                        return;
                    }
                } catch (_) { /* cross-origin: cae al fetch */ }

                // 2) Fetch (usa cache HTTP si existe) -> Blob -> iframe oculto
                setBtnLoading(true);
                fetch(url, { credentials: 'include', cache: 'force-cache' })
                    .then(r => {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.blob();
                    })
                    .then(blob => {
                        const blobUrl = URL.createObjectURL(blob);
                        const printFrame = document.createElement('iframe');
                        printFrame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden;';
                        printFrame.src = blobUrl;
                        document.body.appendChild(printFrame);
                        printFrame.onload = () => {
                            try {
                                printFrame.contentWindow.focus();
                                printFrame.contentWindow.print();
                            } catch (e) {
                                console.warn('print iframe error:', e);
                            } finally {
                                setBtnLoading(false);
                                // El blobUrl + iframe se limpian un rato despues
                                // para no cancelar el dialogo de impresion abierto.
                                setTimeout(() => {
                                    URL.revokeObjectURL(blobUrl);
                                    if (printFrame.parentNode) printFrame.parentNode.removeChild(printFrame);
                                }, 60000);
                            }
                        };
                    })
                    .catch(err => {
                        console.warn('No se pudo imprimir via fetch:', err);
                        setBtnLoading(false);
                        // 3) Fallback: nueva pestana — el usuario imprime con Ctrl+P
                        const w = window.open(url, '_blank');
                        if (!w) {
                            alert('No se pudo imprimir el PDF. El navegador bloqueo el popup. Permite popups e intenta de nuevo, o usa el boton Descargar.');
                        }
                    });
            };

            window.openPdfPreview = function (url, docType, label, equipoId, uploadUrl, skipMetadata, module) {
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
                const printBtn = document.getElementById('pdfPrintBtn');
                const showActions = !!url && url.length >= 5;
                if (downloadBtn) {
                    downloadBtn.dataset.url = url;
                    downloadBtn.dataset.label = label || 'documento';
                    downloadBtn.style.display = showActions ? 'flex' : 'none';
                }
                if (printBtn) {
                    printBtn.style.display = showActions ? 'flex' : 'none';
                }

                // Botones "Subir/reemplazar" (pdfUpdateLabel) y "Eliminar" (pdfDeleteBtn):
                // pertenecen al flujo de gestion documental de equipos (Drive + BD). NO aplican
                // a PDFs que el backend genera en vivo con TCPDF (Nota de Entrega del almacen,
                // Reporte de Fallas, etc.). Deteccion: si NO viene un uploadUrl NI un equipoId,
                // este preview es "solo lectura" -> ocultamos ambos. El gate por permisos del
                // Blade (la directiva can/super.admin) ya pudo no haberlos renderizado; aqui
                // solo nos aseguramos de no mostrarlos cuando el documento no es gestionable.
                const docGestionable = !!uploadUrl || !!equipoId;
                const updateLabel = document.getElementById('pdfUpdateLabel');
                const deleteBtn   = document.getElementById('pdfDeleteBtn');
                if (updateLabel) updateLabel.style.display = docGestionable ? 'flex' : 'none';
                if (deleteBtn)   deleteBtn.style.display   = docGestionable ? 'flex' : 'none';

                // Track timing to ensure loader shows for minimum duration
                const loaderStartTime = Date.now();
                // Minimo que el loader permanece visible. Bajado 800ms → 250ms: cuando el PDF
                // carga rapido, el visor se siente mucho mas agil (antes se forzaba 800ms aunque
                // ya estuviera listo). 250ms sigue evitando el parpadeo si carga casi instantaneo.
                const minimumLoaderDuration = 250; // Minimum time (ms) to show loader

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
                        // FILTRO ANTI-SPURIOUS: el setter iframe.src='' anterior
                        // dispara un evento load asincrono para about:blank ANTES
                        // de que cargue el PDF real. Sin este filtro, el handler
                        // se ejecuta para about:blank y oculta el spinner antes
                        // de que el PDF empiece siquiera a cargar — el bug que
                        // mostraba "modal abierto + sin spinner + gris + PDF
                        // tarde". Ignoramos cualquier load que no sea del PDF.
                        const src = this.src || '';
                        if (!src || src === 'about:blank' || src.indexOf('about:blank') !== -1) {
                            return;
                        }

                        // El evento onload del iframe (con src real) se dispara
                        // cuando el RECURSO termina de descargar — pero el visor
                        // PDF nativo del navegador (PDFium / PDF.js) tarda algunos
                        // cientos de ms mas en renderizar la primera pagina.
                        // Buffer extra para que el visor pinte antes de revelar
                        // el iframe. Bajado 1500 → 400ms: 1500 sobraba (el render
                        // real son ~cientos de ms) y anadia ~1s de espera percibida
                        // en CADA documento. (loaderTimeout de 5s sigue como
                        // fallback maximo si onload nunca dispara para el PDF real.)
                        const PDF_RENDER_BUFFER = 400;
                        setTimeout(hideLoaderWhenReady, PDF_RENDER_BUFFER);
                    };

                    iframe.onerror = function () {
                        clearTimeout(loaderTimeout);
                        // Balancear el showPreloader() de arriba: sin esto, al fallar la
                        // carga del PDF el contador del preloader global quedaba sin
                        // decrementar (loaderTimeout ya fue cancelado) y el spinner se
                        // colgaba / quedaba descalibrado para la siguiente operación.
                        if (typeof window.hidePreloader === 'function') window.hidePreloader();
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
                        const fallback = document.getElementById('pdfMobileFallback');
                        if (fallback) fallback.style.display = 'none';
                        iframe.style.display = 'block';
                        iframe.src = url + '#toolbar=0&navpanes=0&scrollbar=0&zoom=100';
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
                // module: 'equipo' (default) | 'auxiliar'. Determina si load/save
                // metadata pegan a /admin/equipos/.. o a /admin/equipos-auxiliares/..
                window.currentPdfContext = { equipoId, docType, label, uploadUrl, module: module || 'equipo' };

                // Auto-open metadata panel on desktop only (no ocultar el PDF en móviles)
                // Si skipMetadata=true (modulos no equipos como auxiliares), el panel
                // queda colapsado y no se llama loadMetadata para evitar mostrar campos
                // de la tabla equivocada.
                const panel = document.getElementById('pdfMetadataPanel');
                if (panel) {
                    panel.style.width = '0';
                    if (!skipMetadata) {
                        setTimeout(() => {
                            const isMobile = window.innerWidth <= 768;
                            if (!isMobile) {
                                panel.style.width = '300px';
                                loadMetadata();
                            }
                        }, 400);
                    }
                }
            };

            // Permission Flag (Global & Exposed to External Scripts)
            // CAN_UPDATE_INFO habilita: boton lapiz del modal detalles, upload
            // PDF del modal detalles, submit de edicion de ficha. SOLO mira
            // 'user.edit'. Gate::before resuelve super.admin automaticamente
            // dentro de ->can('user.edit') (no hay que repetirlo aca).
            window.CAN_UPDATE_INFO = {{ auth()->user() && auth()->user()->can('user.edit') ? 'true' : 'false' }};
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
                    const baseUrl = ctx.module === 'auxiliar'
                        ? `/admin/equipos-auxiliares/${ctx.equipoId}/metadata`
                        : `/admin/equipos/${ctx.equipoId}/metadata`;
                    const res = await fetch(`${baseUrl}?type=${ctx.docType}`);
                    const data = await res.json();
                    if (data.success) {
                        const info = data.data;
                        let html = '';
                        const commonInputStyle = "background: #4a5568; border: 1px solid #718096; color: white; padding: 6px 8px; border-radius: 4px; width: 100%; box-sizing: border-box; font-size: 13px; height: 32px;";
                        const labelStyle = "display: block; font-size: 12px; color: #cbd5e0; margin-bottom: 4px; font-weight: 600;";
                        const containerStyle = "margin-bottom: 12px;";
                        const disabledAttr = !window.CAN_UPDATE_INFO ? `disabled style="${commonInputStyle} opacity: 0.7; cursor: not-allowed;"` : `style="${commonInputStyle}"`;
                        // Modulo auxiliares: campos propios del aux (no hay tabla
                        // documentacion paralela). Propiedad => datos basicos;
                        // certificado => fecha de vencimiento + datos basicos.
                        if (ctx.module === 'auxiliar') {
                            if (ctx.docType === 'propiedad') {
                                html += `
                                <div style="${containerStyle}"><label style="${labelStyle}">Serial</label><input type="text" name="serial" value="${info.serial || ''}" ${disabledAttr} autocomplete="off"></div>
                                <div style="${containerStyle}"><label style="${labelStyle}">Código Interno</label><input type="text" name="codigo" value="${info.codigo || ''}" ${disabledAttr} autocomplete="off"></div>
                                <div style="${containerStyle}"><label style="${labelStyle}">Tipo</label><input type="text" name="tipo" value="${info.tipo || ''}" ${disabledAttr} autocomplete="off"></div>
                                <div style="${containerStyle}"><label style="${labelStyle}">Marca</label><input type="text" name="marca" value="${info.marca || ''}" ${disabledAttr} autocomplete="off"></div>
                                <div style="${containerStyle}"><label style="${labelStyle}">Modelo</label><input type="text" name="modelo" value="${info.modelo || ''}" ${disabledAttr} autocomplete="off"></div>
                                <div style="${containerStyle}"><label style="${labelStyle}">Capacidad</label><input type="text" name="capacidad" value="${info.capacidad || ''}" ${disabledAttr} autocomplete="off"></div>
                                <div style="${containerStyle}"><label style="${labelStyle}">Año</label><input type="number" name="anio" value="${info.anio || ''}" ${disabledAttr} autocomplete="off"></div>
                            `;
                            } else if (ctx.docType === 'certificado') {
                                html += `
                                <div style="${containerStyle}"><label style="${labelStyle}">Fecha Vencimiento</label><input type="date" name="fecha_vencimiento" value="${info.fecha_vencimiento || ''}" ${disabledAttr} autocomplete="off"></div>
                            `;
                            }
                            container.innerHTML = html;
                            return;
                        }
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
                        } else if (ctx.docType === 'rotc' || ctx.docType === 'racda' || ctx.docType === 'adicional') {
                            // Compraventa (adicional_2) NO requiere fecha de vencimiento.
                            // Antes el Certificado (adicional) solo mostraba fecha si la categoria era
                            // FLOTA LIVIANA — los equipos FLOTA PESADA quedaban con panel vacio.
                            // Removida esa restriccion: el campo aparece siempre, el usuario decide
                            // si llena la fecha o la deja vacia segun corresponda al equipo.
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
                    const saveUrl = ctx.module === 'auxiliar'
                        ? `/admin/equipos-auxiliares/${ctx.equipoId}/update-metadata`
                        : `/admin/equipos/${ctx.equipoId}/update-metadata`;
                    const res = await fetch(saveUrl, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'), 'Accept': 'application/json' },
                        body: formData
                    });
                    const data = await res.json();
                    if (data.success) {
                        if (window.showToast) window.showToast('Datos actualizados correctamente', 'success');
                        // Modulo auxiliares: refresca la tabla y termina (no aplica el
                        // flujo de showDetailsImproved/activeEquipoButton del modulo equipos).
                        if (ctx.module === 'auxiliar') {
                            if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
                            return;
                        }
                        if (window.activeEquipoButton) {
                            const d = window.activeEquipoButton.dataset;
                            const eqId = d.equipoId;
                            const cache = (window.equiposData && eqId) ? window.equiposData[eqId] : null;
                            // showDetailsImproved hace d = {...dataset, ...equiposData[id]} y equiposData
                            // PISA al dataset. Por eso aplicamos los cambios a AMBOS: si solo tocáramos el
                            // dataset, la fecha nueva quedaría tapada por la vieja del cache (síntoma: había
                            // que recargar la página — se notaba sobre todo en el Certificado/adicional).
                            const aplicar = (obj) => {
                                if (!obj) return;
                                if (ctx.docType === 'propiedad') {
                                    obj.nroDoc = formData.get('nro_documento'); obj.titular = formData.get('titular');
                                    obj.placa = formData.get('placa'); obj.marca = formData.get('marca');
                                    obj.modelo = formData.get('modelo'); obj.chasis = formData.get('serial_chasis');
                                    obj.motorSerial = formData.get('serial_motor');
                                } else {
                                    // Misma fuente de verdad (DOC_FIELD_MAP.vencKey) que subida/borrado.
                                    const vk = (window.DOC_FIELD_MAP && window.DOC_FIELD_MAP[ctx.docType]) ? window.DOC_FIELD_MAP[ctx.docType].vencKey : null;
                                    if (vk) obj[vk] = formData.get('fecha_vencimiento');
                                    if (ctx.docType === 'poliza') obj.seguro = formData.get('nombre_aseguradora');
                                }
                            };
                            aplicar(d);
                            aplicar(cache);
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

            // Permiso super.admin (renderizado server-side desde el flag de auth).
            // El boton de borrar solo se muestra cuando el usuario tiene super.admin,
            // pero esta variable se usa por el JS para defensa-en-profundidad ante
            // intentos de DOM-inject. El backend tambien valida con can:super.admin.
            window.CAN_DELETE_DOCS = {{ auth()->user() && auth()->user()->can('super.admin') ? 'true' : 'false' }};

            // Borrado de PDF desde el modal de preview. Borra del Google Drive Y de la BD.
            // Por ahora solo soporta el modulo 'equipo'; auxiliares no implementado todavia.
            // Usa confirm() nativo del browser (no window.showModal) porque el standardModal
            // queda detras del pdfPreviewModal por el stacking context.
            window.deletePdfFromPreview = async function () {
                if (!window.CAN_DELETE_DOCS) {
                    if (window.showToast) window.showToast('No tienes permisos para eliminar documentos.', 'error');
                    return;
                }
                const ctx = window.currentPdfContext;
                if (!ctx || !ctx.equipoId || !ctx.docType) {
                    if (window.showToast) window.showToast('Contexto de documento no disponible.', 'error');
                    return;
                }
                if (ctx.module === 'auxiliar') {
                    if (!window.confirm('¿Eliminar este documento?\n\nEsta acción NO se puede deshacer.')) return;
                    const btnAux = document.getElementById('pdfDeleteBtn');
                    if (btnAux) btnAux.disabled = true;
                    if (typeof window.showPreloader === 'function') window.showPreloader();
                    try {
                        const csrfA = document.querySelector('meta[name="csrf-token"]');
                        const r = await fetch(`/admin/equipos-auxiliares/${ctx.equipoId}/delete-doc?doc_type=${encodeURIComponent(ctx.docType)}`, {
                            method: 'DELETE',
                            headers: { 'X-CSRF-TOKEN': csrfA ? csrfA.getAttribute('content') : '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        });
                        const d = await r.json().catch(() => ({}));
                        if (r.ok && d.success) {
                            if (window.showToast) window.showToast(d.message || 'Documento eliminado.', 'success');
                            // Actualizar la cache + icono del modal de detalles (a "Subir"/cloud_upload)
                            // EN VIVO: el modal de detalles queda abierto detrás del visor, así que al
                            // cerrar el visor ya muestra el estado correcto sin recargar la página.
                            if (typeof window.syncAuxDocCache === 'function') window.syncAuxDocCache(ctx.equipoId, ctx.docType, null);
                            const modal = document.getElementById('pdfPreviewModal');
                            if (modal) modal.classList.remove('active');
                            if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares(); // refresca la lista
                        } else {
                            if (window.showToast) window.showToast(d.message || 'No se pudo eliminar el documento.', 'error');
                        }
                    } catch (e) {
                        if (window.showToast) window.showToast('Error de red al eliminar el documento.', 'error');
                    } finally {
                        if (typeof window.hidePreloader === 'function') window.hidePreloader();
                        if (btnAux) btnAux.disabled = false;
                    }
                    return;
                }

                const confirmed = window.confirm(
                    '¿Eliminar este documento del Google Drive y de la base de datos?\n\nEsta acción NO se puede deshacer.'
                );
                if (!confirmed) return;

                const btn = document.getElementById('pdfDeleteBtn');
                if (btn) btn.disabled = true;
                if (typeof window.showPreloader === 'function') window.showPreloader();

                try {
                    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    if (!csrfMeta) {
                        if (window.showToast) window.showToast('Token CSRF no disponible. Recarga la página.', 'error');
                        return;
                    }
                    // doc_type como query param: la ruta es DELETE, asi que enviamos
                    // los datos en la URL para evitar problemas con bodies en DELETE
                    // (algunos middlewares y proxies los strippean).
                    const res = await fetch(`/admin/equipos/${ctx.equipoId}/delete-doc?doc_type=${encodeURIComponent(ctx.docType)}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-TOKEN': csrfMeta.getAttribute('content'),
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                    });
                    const data = await res.json().catch(() => ({}));

                    if (res.ok && data.success) {
                        if (window.showToast) window.showToast(data.message || 'Documento eliminado.', 'success');

                        // ── Sincronizar la UI in-memory para que NO haya que recargar la pagina ──
                        // 1) Limpiar campos en el dataset del boton del listado (icono de PDF cargado).
                        // 2) Limpiar campos en window.equiposData[id] (cache JS de la tabla, lo usa el AJAX paginado).
                        // 3) Re-renderizar el modal de detalles si esta abierto.
                        const equipoIdNum = ctx.equipoId;
                        const activeBtn = window.activeEquipoButton;
                        if (typeof window.clearDocFields === 'function') {
                            if (activeBtn && activeBtn.dataset) {
                                window.clearDocFields(activeBtn.dataset, ctx.docType);
                            }
                            if (window.equiposData && window.equiposData[equipoIdNum]) {
                                window.clearDocFields(window.equiposData[equipoIdNum], ctx.docType);
                            }
                        }

                        window.closePdfPreview();

                        // Si el modal de detalles esta abierto, re-renderizarlo con los datos
                        // ya limpiados para que el icono "PDF cargado" desaparezca al instante.
                        const detailsModal = document.getElementById('detailsModal');
                        const detailsOpen = detailsModal && detailsModal.classList.contains('active');
                        if (detailsOpen && activeBtn && typeof window.showDetailsImproved === 'function') {
                            try { window.showDetailsImproved(activeBtn); } catch (_) { /* noop */ }
                        }

                        // Refrescar el resto de vistas que pueden estar mostrando el documento.
                        if (typeof window.refreshDashboardAlerts === 'function') window.refreshDashboardAlerts();
                        if (typeof window.loadHistorialDocumentos === 'function') window.loadHistorialDocumentos();
                    } else {
                        const msg = (data && data.message) ? data.message : `Error HTTP ${res.status}`;
                        if (window.showToast) window.showToast(msg, 'error');
                        console.error('deletePdfFromPreview: backend rechazo', res.status, data);
                    }
                } catch (e) {
                    console.error('deletePdfFromPreview: excepcion de red', e);
                    if (window.showToast) window.showToast('Error de red al eliminar el documento.', 'error');
                } finally {
                    if (btn) btn.disabled = false;
                    if (typeof window.hidePreloader === 'function') window.hidePreloader();
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
                // uploadUrl override desde window.currentPdfContext (otros modulos
                // como aux pueden inyectar su propio endpoint). Si no, fallback a
                // /admin/equipos/{id}/upload-doc.
                const targetUrl = (window.currentPdfContext && window.currentPdfContext.uploadUrl)
                    ? window.currentPdfContext.uploadUrl
                    : `/admin/equipos/${equipoId}/upload-doc`;
                xhr.open('POST', targetUrl, true);
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

                                    // Load new PDF with force-refresh since file changed.
                                    // data.link YA trae ?v=<timestamp> del backend (uploadDoc) — concatenar
                                    // "?upd=" producia una URL con DOS '?' (invalida) y el iframe NO cargaba
                                    // el PDF nuevo ("no se ve que cargue"). Usamos '&' si ya hay query string.
                                    var _sep = data.link.indexOf('?') > -1 ? '&' : '?';
                                    iframe.src = data.link + _sep + 'upd=' + new Date().getTime() + '#toolbar=0&navpanes=0&scrollbar=0&zoom=100';
                                }

                                // Update Download Button
                                const downloadBtn = document.getElementById('pdfDownloadBtn');
                                if (downloadBtn) downloadBtn.dataset.url = data.link;

                                // Sincroniza dataset + equiposData usando el helper unico (DOC_FIELD_MAP).
                                // Solo re-renderiza el modal detalles si sigue abierto debajo del preview;
                                // asi evitamos reabrirlo por encima del preview y manejamos race conditions
                                // (nodo muerto, SPA nav) gracias al guard de activeEquipoButton.
                                // SOLO para EQUIPOS. Sin este guard, al reemplazar un doc de un
                                // AUXILIAR la rama de equipos igual corría (activeEquipoButton no se
                                // resetea al abrir un aux) y escribía el link del doc del AUX en el
                                // dataset/caché del EQUIPO → el equipo mostraba el PDF equivocado.
                                const _esAux = window.currentPdfContext && window.currentPdfContext.module === 'auxiliar';
                                const btnFP = window.activeEquipoButton;
                                const btnFPAlive = btnFP && document.body.contains(btnFP);
                                if (!_esAux && btnFPAlive && typeof window.applyDocUpload === 'function') {
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

                                // AUXILIARES: el bloque de arriba solo refresca el modal de EQUIPOS.
                                // Para aux, traemos el detalle FRESCO de /details, actualizamos su
                                // cache (auxDetailsMap) y re-renderizamos el modal si sigue abierto,
                                // para que el documento recien subido se refleje al instante (antes
                                // quedaba con el estado viejo). NOTA: openAuxDetailsModal espera un
                                // BOTON (btn.dataset.auxId), no un id, por eso el refetch va directo.
                                if (window.currentPdfContext && window.currentPdfContext.module === 'auxiliar') {
                                    var _auxId = window.currentPdfContext.equipoId;
                                    var _auxModal = document.getElementById('auxDetailsModal');
                                    if (_auxId && _auxModal && _auxModal.classList.contains('active')
                                        && typeof window.renderAuxDetailsModal === 'function') {
                                        fetch('/admin/equipos-auxiliares/' + _auxId + '/details', {
                                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                                            credentials: 'same-origin'
                                        })
                                        .then(function (r) { return r.ok ? r.json() : null; })
                                        .then(function (d) {
                                            if (!d) return;
                                            (window.auxDetailsMap = window.auxDetailsMap || {})[_auxId] = d;
                                            window.renderAuxDetailsModal(d);
                                        })
                                        .catch(function () { /* silencioso: el toast de exito ya salio */ });
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

            // filterDropdownOptions se define UNA sola vez en uicomponents.js (versión que
            // normaliza acentos y respeta el filtrado por frente 'eq-tipo-oculto' de Equipos).
            // Antes se redefinía aquí una versión más pobre (solo toUpperCase, sin acentos ni
            // eq-tipo-oculto) que, al cargar DESPUÉS del <script src>, PISABA a la buena — el
            // filtro de Tipo de equipos re-mostraba tipos que el frente había ocultado.

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
                    const stActivos = document.getElementById('stats_activos');
                    const stDist = document.getElementById('distributionStatsContainer');

                    if (stTotal) stTotal.textContent = '0';
                    if (stInact) stInact.textContent = '0';
                    if (stActivos) stActivos.textContent = '0';
                    if (stDist) stDist.innerHTML = '';
                    // Reset de las secciones de Distribución (equipos/detalles/aux): al vaciar el
                    // contenedor sin recargar, evitar que un clic posterior reinyecte HTML viejo.
                    window.__distribAuxHtml = '';
                    window.__distribUbiHtml = '';
                    window.__distribHtml = '';
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
        {{-- Bulk upload: andamiaje compartido (window.createBulkPreview) ANTES de los módulos --}}
        <script
            src="{{ asset('js/maquinaria/bulk_preview_factory.js') }}?v={{ @filemtime(public_path('js/maquinaria/bulk_preview_factory.js')) }}"></script>
        {{-- Bulk upload de equipos (Global: @yield('extra_js') queda fuera del .main-viewport → SPA no lo re-ejecutaría) --}}
        <script
            src="{{ asset('js/maquinaria/equipos_bulk.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipos_bulk.js')) }}"></script>
        {{-- Bulk upload de equipos auxiliares (mismo patron SPA-compat) --}}
        <script
            src="{{ asset('js/maquinaria/auxiliares_bulk.js') }}?v={{ @filemtime(public_path('js/maquinaria/auxiliares_bulk.js')) }}"></script>

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

        {{-- ===== OFFLINE (Fase 1): baja la copia de datos a IndexedDB para consultar sin internet ===== --}}
        <script src="{{ asset('js/offline/offline-sync.js') }}?v={{ @filemtime(public_path('js/offline/offline-sync.js')) }}" defer></script>
        {{-- Botón "Copia local" (menú Configuraciones): fuerza la
             bajada del snapshot AHORA y da feedback. La lógica de descarga vive en
             offline-sync.js (OfflineDB.sync(true)); aquí solo va la parte de UI. --}}
        <script>
            window.descargarSnapshotOffline = function (ev) {
                if (ev) ev.preventDefault();
                var toast = function (m, t) { if (window.showToast) window.showToast(m, t || 'info'); };
                if (!navigator.onLine) {
                    return toast('Necesitas conexión a internet para descargar la copia.', 'error');
                }
                if (!window.OfflineDB || typeof window.OfflineDB.sync !== 'function') {
                    return toast('El módulo offline aún no está listo. Espera unos segundos e inténtalo de nuevo.', 'error');
                }
                if (window._descargandoSnapshot) {
                    return toast('Ya hay una descarga en curso, espera un momento…', 'info');
                }
                window._descargandoSnapshot = true;
                toast('Descargando copia de la base de datos…', 'info');
                window.OfflineDB.sync(true)
                    .then(function (ok) {
                        if (ok) toast('Copia actualizada. Ya puedes trabajar sin internet.', 'success');
                        else    toast('No se pudo descargar la copia. Revisa tu conexión e inténtalo de nuevo.', 'error');
                    })
                    .catch(function () { toast('Error al descargar la copia.', 'error'); })
                    .finally(function () { window._descargandoSnapshot = false; });
            };
        </script>
        {{-- Confirma el verificador de login offline (el servidor aceptó las credenciales). --}}
        <script src="{{ asset('js/offline/offline-auth.js') }}?v={{ @filemtime(public_path('js/offline/offline-auth.js')) }}" defer></script>
        {{-- Fase 2: motor de sincronización del outbox (sube acciones hechas sin internet). --}}
        <script src="{{ asset('js/offline/outbox-sync.js') }}?v={{ @filemtime(public_path('js/offline/outbox-sync.js')) }}" defer></script>
        {{-- Fase 2: bandeja de pendientes (badge flotante #outboxTray). --}}
        <script>
            (function () {
                function $(id) { return document.getElementById(id); }
                function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
                function razon(k) { return (window.OfflineOutbox && window.OfflineOutbox.razon) ? window.OfflineOutbox.razon(k) : (k || ''); }

                function refrescar() {
                    var tray = $('outboxTray'); if (!tray || !window.OfflineDB) return;
                    window.OfflineDB.outboxList().then(function (items) {
                        if (!items.length) {
                            tray.style.display = 'none';
                            var p0 = $('outboxTrayPanel'); if (p0) p0.style.display = 'none';
                            return;
                        }
                        tray.style.display = 'block';
                        $('outboxTrayCount').textContent = items.length;
                        var hayErr = items.some(function (i) { return i.status === 'error'; });
                        $('outboxTrayBtn').style.background = hayErr ? '#dc2626' : '#0067b1';

                        var list = $('outboxTrayList');
                        list.innerHTML = items.map(function (it) {
                            var esErr = it.status === 'error';
                            var color = esErr ? '#dc2626' : '#0067b1';
                            var estado = esErr ? ('Error · ' + razon(it.reason)) : 'Por subir';
                            var acciones = esErr
                                ? '<div style="display:flex;gap:8px;margin-top:6px;">' +
                                      '<button data-retry="' + esc(it.client_uuid) + '" style="background:#0067b1;color:#fff;border:none;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;">Reintentar</button>' +
                                      '<button data-discard="' + esc(it.client_uuid) + '" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;">Descartar</button>' +
                                  '</div>'
                                : '';
                            return '<div style="padding:11px 14px;border-bottom:1px solid #f1f5f9;">' +
                                       '<div style="font-size:12.5px;font-weight:700;color:#1e293b;">' + esc(it.label || it.action) + '</div>' +
                                       '<div style="font-size:11.5px;font-weight:700;color:' + color + ';margin-top:3px;">' + esc(estado) + '</div>' +
                                       acciones +
                                   '</div>';
                        }).join('');
                    });
                }

                document.addEventListener('click', function (e) {
                    var t = e.target;
                    if (t.closest && t.closest('#outboxTrayBtn')) {
                        var p = $('outboxTrayPanel'); if (p) p.style.display = (p.style.display === 'none' ? 'block' : 'none');
                        refrescar(); return;
                    }
                    if (t.closest && t.closest('#outboxTraySubir')) {
                        if (window.OfflineOutbox) window.OfflineOutbox.drain(); return;
                    }
                    var rt = t.closest && t.closest('[data-retry]');
                    if (rt) {
                        window.OfflineDB.outboxUpdate(rt.getAttribute('data-retry'), { status: 'pending', reason: '' })
                            .then(function () { if (window.OfflineOutbox) window.OfflineOutbox.drain(); refrescar(); });
                        return;
                    }
                    var dc = t.closest && t.closest('[data-discard]');
                    if (dc) {
                        // Descartar: sale del outbox y se re-baja el snapshot para corregir
                        // cualquier cambio optimista que no llegó a aplicarse en el servidor.
                        window.OfflineDB.outboxRemove(dc.getAttribute('data-discard'))
                            .then(function () { if (window.OfflineDB.sync) window.OfflineDB.sync(true); refrescar(); });
                        return;
                    }
                    // Click fuera del tray cierra el panel.
                    var panel = $('outboxTrayPanel');
                    if (panel && panel.style.display === 'block' && !(t.closest && t.closest('#outboxTray'))) panel.style.display = 'none';
                });

                window.addEventListener('outbox-actualizado', refrescar);
                window.addEventListener('online', refrescar);
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', refrescar);
                else refrescar();
            })();
        </script>
        {{-- Render offline por módulo (GLOBAL: se (re)inicializan en cada navegación SPA vía
             su propio listener 'spa:contentLoaded'; en @section('content') NO se ejecutaban
             porque la SPA omite los <script src> ya cargados). --}}
        <script src="{{ asset('js/maquinaria/almacen-offline.js') }}?v={{ @filemtime(public_path('js/maquinaria/almacen-offline.js')) }}" defer></script>
        <script src="{{ asset('js/maquinaria/equipos-offline.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipos-offline.js')) }}" defer></script>
        <script src="{{ asset('js/maquinaria/movilizaciones-offline.js') }}?v={{ @filemtime(public_path('js/maquinaria/movilizaciones-offline.js')) }}" defer></script>
        <script src="{{ asset('js/maquinaria/movimientos-offline.js') }}?v={{ @filemtime(public_path('js/maquinaria/movimientos-offline.js')) }}" defer></script>

        {{-- ── WebAuthn: prompt de registro biométrico tras login con contraseña ── --}}
        @if(session('webauthn_prompt'))
        <script src="{{ asset('js/webauthn.js') }}?v={{ @filemtime(public_path('js/webauthn.js')) }}"></script>
        <div id="webauthnModal" style="display:none;position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
            <div style="background:#fff;border-radius:16px;padding:28px 24px;max-width:360px;width:90%;text-align:center;box-shadow:0 8px 32px rgba(0,0,0,.25);">
                <div style="width:64px;height:64px;margin:0 auto 16px;background:#00004d;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <i class="material-icons" style="font-size:36px;color:#fff;">fingerprint</i>
                </div>
                <h3 style="margin:0 0 8px;font-size:18px;font-weight:800;color:#111;">Activar acceso con huella</h3>
                <p style="margin:0 0 20px;font-size:13.5px;color:#555;line-height:1.45;">La próxima vez podrá entrar sin contraseña.</p>
                <div style="display:flex;gap:10px;">
                    <button id="webauthnDismiss" style="flex:1;padding:11px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-weight:700;font-size:13.5px;cursor:pointer;">Ahora no</button>
                    <button id="webauthnAccept" style="flex:1;padding:11px;border:none;border-radius:8px;background:#00004d;color:#fff;font-weight:700;font-size:13.5px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,77,.3);">Activar huella</button>
                </div>
            </div>
        </div>
        <script>
        (function() {
            if (typeof VidalsaWebAuthn === 'undefined' || !VidalsaWebAuthn.soportado()) return;
            if (!/Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) return;
            if (VidalsaWebAuthn.tieneCredenciales()) return;
            VidalsaWebAuthn.plataformaDisponible().then(function(ok) {
                if (!ok) return;
                var modal = document.getElementById('webauthnModal');
                if (!modal) return;
                modal.style.display = 'flex';

                document.getElementById('webauthnDismiss').addEventListener('click', function() {
                    modal.style.display = 'none';
                });

                document.getElementById('webauthnAccept').addEventListener('click', function() {
                    var btn = this;
                    btn.disabled = true;
                    btn.textContent = 'Registrando...';
                    VidalsaWebAuthn.registrar().then(function(ok) {
                        modal.style.display = 'none';
                        if (ok) {
                            var toast = document.createElement('div');
                            toast.textContent = 'Huella registrada correctamente';
                            toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#059669;color:#fff;padding:12px 24px;border-radius:8px;font-weight:700;font-size:14px;z-index:999999;box-shadow:0 4px 12px rgba(0,0,0,.2);';
                            document.body.appendChild(toast);
                            setTimeout(function() { toast.remove(); }, 3500);
                        }
                    }).catch(function() {
                        modal.style.display = 'none';
                    });
                });
            });
        })();
        </script>
        @endif
</body>

</html>