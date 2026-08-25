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
    {{-- Solo modo claro. El porqué, en :root de estilos_globales.css; aquí va en el <head>
         para que aplique desde el primer pintado, antes de descargar la hoja de estilos. --}}
    <meta name="color-scheme" content="light">
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

    {{-- Helpers DOM compartidos (window.getCsrf / escapeHtml / escapeAttrJs).
         VAN EN EL <head>, no abajo con el resto de los scripts: los <script> inline
         de @yield('content') corren ANTES que el bloque de scripts del final, y varios
         hacen `var esc = window.escapeHtml;` al evaluarse. Si el helper cargara después,
         esos alias guardarían undefined y reventarían al primer uso.
         Es seguro en el <head>: solo define funciones, no toca el DOM al cargar. --}}
    <script
        src="{{ asset('js/maquinaria/dom_helpers.js') }}?v={{ @filemtime(public_path('js/maquinaria/dom_helpers.js')) }}"></script>
    {{-- Por el MISMO motivo van aquí estos dos, que usan los módulos de inventario:
         · producto_suggest.js (window.ProductoSuggest) — reglas de los autocompletes de
           producto: agrupar por descripción, dedupe y badge de presentaciones.
         · qr_scan.js (window.QrScan) — escaneo de QR de los buscadores.
         Los <script> inline de Inventario / Movimientos / Recepción los invocan AL
         EVALUARSE (alias de funciones y QrScan.init), no solo dentro de callbacks, así que
         abajo llegarían tarde. Ambos solo definen funciones; qr_scan además registra sus
         listeners en `document`, que en el <head> ya existe. --}}
    <script
        src="{{ asset('js/maquinaria/producto_suggest.js') }}?v={{ @filemtime(public_path('js/maquinaria/producto_suggest.js')) }}"></script>
    <script
        src="{{ asset('js/maquinaria/qr_scan.js') }}?v={{ @filemtime(public_path('js/maquinaria/qr_scan.js')) }}"></script>
    {{-- Y por lo mismo lazy_loader.js (window.cargarScriptUnaVez / ensureChartJS): los
         <script> inline de los gráficos de consumibles lo llaman al evaluarse. Pesa 3 KB
         y NO trae nada consigo — solo sabe pedir lo pesado cuando de verdad hace falta. --}}
    <script
        src="{{ asset('js/maquinaria/lazy_loader.js') }}?v={{ @filemtime(public_path('js/maquinaria/lazy_loader.js')) }}"></script>
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
            #pdfDownloadBtn .btn-label,
            #pdfAnexarBtn .btn-label {
                display: none;
            }
            #pdfPrintBtn,
            #pdfDownloadBtn,
            #pdfAnexarBtn {
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
            /* Azul general del proyecto (#00004d sólido); mismo que el avatar en PC */
            background: #00004d;
            color: #fff;
            border-radius: 10px;
            line-height: 1.15;
        }
        /* Mismo criterio que .hup-avatar: dentro va el icono `person` y el tamaño lo fija
           el glifo, así que el contenedor no necesita font-weight ni font-size. */
        .mobile-user-header-compact .muhc-avatar .material-icons { font-size: 19px; }
        .mobile-user-header-compact .muhc-avatar {
            flex-shrink: 0;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: rgba(255,255,255,0.18);
            border: 1.5px solid rgba(255,255,255,0.3);
            color: #fff;
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

        /* El encabezado grande del drawer (.mobile-user-header y sus hijas) se eliminó:
           llevaba tiempo marcado como legacy, no lo usaba ninguna vista ni ningún JS, y
           su reemplazo es .mobile-user-header-compact, justo arriba. */

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
        /* Dentro va el icono `person`, no una inicial: el tamaño lo fija el glifo. Por eso
           el contenedor ya no lleva font-weight/font-size/letter-spacing — eran para la
           letra y sobre un <i> no pintan nada. */
        .header-user-panel .hup-avatar .material-icons { font-size: 20px; }
        .header-user-panel .hup-avatar {
            position: relative;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            /* Azul general del proyecto (#00004d sólido); mismo color en PC y teléfono */
            background: #00004d;
            color: #fff;
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

    <script
        src="{{ asset('js/maquinaria/fetch_interceptor.js') }}?v={{ @filemtime(public_path('js/maquinaria/fetch_interceptor.js')) }}"></script>

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

    {{-- PRECARGA de los archivos que salieron del bloque inline del layout.
         Ese codigo antes VIAJABA DENTRO de este HTML: cero peticiones y cero
         espera. Al extraerlo, el HTML adelgazo 113 KB pero aparecieron 5
         descargas nuevas, y la peor es layout_ui.js (82 KB): su <script> vive
         al final del body, asi que el navegador no se entera de que existe
         hasta parsear todo el documento, y ahi se para en seco a bajarlo. Ese
         es el "abre mas lento" que se noto despues de la extraccion.

         preload y NO defer (ver la regla del orden SPA, mas abajo): esto NO
         cambia cuando se ejecuta nada — los <script> siguen sincronos y en el
         mismo orden. Solo adelanta la DESCARGA al primer byte del HTML y en
         paralelo, para que al llegar el parser el archivo ya este en memoria.

         Las URL deben coincidir EXACTAMENTE con las del <script src> (mismo
         ?v=filemtime) o el navegador baja el archivo dos veces. --}}
    @foreach ([
        'js/maquinaria/layout_ui.js',
        'js/maquinaria/offline_mode.js',
        'js/maquinaria/fetch_interceptor.js',
        'js/maquinaria/global_handlers.js',
        'js/maquinaria/preloader.js',
    ] as $_precarga)
        <link rel="preload" as="script" href="{{ asset($_precarga) }}?v={{ @filemtime(public_path($_precarga)) }}">
    @endforeach

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
         restaurada" en verde y luego desaparece. La logica vive en
         public/js/maquinaria/offline_mode.js (antes era un <script> inline aqui mismo). --}}
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

                // Notas por recibir: el MISMO número en los tres badges (Almacén contraído,
                // Recepción de escritorio y Recepción de móvil). También aquí arriba, antes de
                // los dos menús, para que ninguno dependa de que el otro se haya pintado.
                $__navPorRecibir = $traspasosPorRecibir ?? 0;
            @endphp
            {{-- Almacén Dropdown: Inventario + Recepción (con badge si hay envíos pendientes) + Kardex --}}
            <div class="nav-dropdown">
                <a href="#"
                    class="nav-link {{ request()->is('admin/almacen*') ? 'active' : '' }}"
                    style="display: flex; align-items: center; gap: 4px;">
                    <i class="material-icons" style="font-size: 18px;">warehouse</i>Almacén
                    {{-- Mismo contador que Recepción, para verlo SIN desplegar el menú. Al
                         desplegarlo se oculta por CSS (.nav-dropdown.active) y queda el de
                         Recepción, que es el que dice de qué se trata.
                         El contador ($__navPorRecibir) se calcula una sola vez arriba, con
                         $recepcionUrl: antes cada badge repetía `$traspasosPorRecibir ?? 0`
                         en su propia variable. --}}
                    <span id="navBadgeAlmacen" class="nav-badge nav-badge-almacen" data-count="{{ $__navPorRecibir }}">{{ $__navPorRecibir }}</span>
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
                        <span id="navBadgeRecepcion" class="nav-badge" data-count="{{ $__navPorRecibir }}">{{ $__navPorRecibir }}</span>
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
                    {{-- Icono de persona en vez de la inicial: el nombre completo ya está
                         al lado, así que la letra suelta no aportaba nada y con nombres que
                         empiezan igual todos los avatares se veían idénticos. --}}
                    <div class="hup-avatar">
                        <i class="material-icons">person</i>
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
                    <i class="material-icons">person</i>
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
                    <span id="navBadgeRecepcionMobile" class="nav-badge" data-count="{{ $__navPorRecibir }}">{{ $__navPorRecibir }}</span>
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
                {{-- "Catálogo de Modelos" NO va en el menú de TELÉFONO (pedido del cliente):
                     es una pantalla de mantenimiento de escritorio. NO queda huérfana — se
                     sigue llegando desde la tarjeta del menú principal (/menu), desde el
                     desplegable de Equipos y desde el de Auxiliares. Este menú lateral de
                     escritorio nunca la tuvo. --}}
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

                    {{-- Comparar: parte el visor en dos y pone el original a la izquierda y la
                         corrección elegida a la derecha. Lo enciende _pdfPintarAnexos SOLO si
                         hay alguna corrección —sin ella no hay nada que comparar— y solo en
                         pantallas anchas: partir 500 px en dos no deja leer ninguno de los dos. --}}
                    <button type="button" id="pdfCompararBtn"
                        onclick="window.pdfCompararToggle()"
                        title="Ver el original y la corrección al mismo tiempo"
                        style="display:none; background:transparent; color:#cbd5e0; border:1px solid #4a5568; padding:6px 10px; font-size:12px; font-weight:600; align-items:center; gap:5px; border-radius:4px; cursor:pointer; transition:background .15s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.08)'"
                        onmouseout="this.style.background=this.dataset.on === '1' ? 'rgba(99,102,241,0.25)' : 'transparent'">
                        <i class="material-icons" style="font-size:16px;">vertical_split</i><span class="btn-label">Comparar</span>
                    </button>

                    {{-- "Anexar corrección" vive aquí, con Descargar e Imprimir, y no en la
                         barra de abajo: es una ACCIÓN sobre el documento, como las otras, y
                         abajo obligaba a pintar la barra aunque no hubiera ni una corrección
                         que enseñar. Lo muestra y lo esconde el mismo sitio que decide si el
                         documento admite correcciones (_pdfPintarAnexos / _pdfOcultarAnexos). --}}
                    @if(auth()->user() && auth()->user()->can('user.edit'))
                    {{-- Un solo gesto: se pulsa y se elige el PDF. El nombre de la
                         pestaña lo pone el backend numerando las correcciones. --}}
                    <div id="pdfAnexarZona" style="display:none; align-items:center; gap:6px; flex-shrink:0;">
                        <button type="button" id="pdfAnexarBtn"
                            style="background:transparent; color:#93c5fd; border:1px dashed #3b82f6; padding:4px 10px; font-size:12px; font-weight:600; display:flex; align-items:center; gap:5px; border-radius:6px; cursor:pointer; transition:background .15s;"
                            onmouseover="this.style.background='rgba(59,130,246,0.12)'"
                            onmouseout="this.style.background='transparent'"
                            title="Anexar una corrección: se guarda junto al documento, sin reemplazarlo">
                            <i class="material-icons" style="font-size:15px;">attach_file</i><span class="btn-label">Anexar corrección</span>
                        </button>
                        <input type="file" id="pdfAnexarInput" accept="application/pdf" style="display:none;">
                    </div>
                    @endif

                    {{-- Misma regla que el boton "Anexar correccion" de aqui al lado, y por
                         el mismo motivo: uploadDoc exige 'user.edit' en el servidor. Antes se
                         listaba tambien 'equipos.edit', asi que quien solo tuviera ese veia
                         el boton y solo se enteraba al elegir el archivo (el guard JS
                         CAN_UPDATE_INFO lo paraba con un toast). --}}
                    @if(auth()->user() && auth()->user()->can('user.edit'))
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

            {{-- Tira de CORRECCIONES ANEXAS.
                 El documento principal y sus correcciones estan los dos vigentes (una
                 poliza con una falta de ortografia y su correccion, por ejemplo), asi
                 que no van escondidas en un panel de historial: van aqui, a la vista,
                 en pestanas. Cambiar de pestana solo cambia el src del iframe y
                 reaprovecha el loader, el desenfoque y el respaldo de 5 s que ya tiene
                 el visor. Solo pestanas: el boton de anexar se subio a la cabecera, asi
                 que sin correcciones esta barra no se pinta y el visor se ve igual que
                 siempre. --}}
            <div id="pdfAnexosBar"
                style="display:none; background:#1f2937; border-bottom:1px solid #374151; padding:6px 12px; align-items:center; gap:8px; overflow-x:auto;">
                <div id="pdfAnexosTabs" style="display:flex; align-items:center; gap:6px; flex:1; min-width:0;"></div>
                {{-- 'user.edit' A SECAS, que es LO MISMO que exige anexarDoc en el servidor.
                     No se listan 'equipos.edit' ni 'super.admin': el primero es un permiso
                     DISTINTO (gobierna changeStatus/confirmarSitio, no la edicion de ficha),
                     asi que quien solo lo tuviera veia el boton, elegia el PDF, esperaba a
                     que subiera entero y recibia un 403; y el segundo lo resuelve Gate::before
                     dentro de ->can('user.edit'), igual que en window.CAN_UPDATE_INFO. --}}
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
                        style="width: 100%; height: 100%; border: none; opacity: 0; transition: opacity 0.25s, filter 0.5s ease-out; position: relative; z-index: 20;"
                        allowfullscreen></iframe>

                    {{-- Rótulo del lado izquierdo. Solo se ve comparando: con un único
                         documento en pantalla no hay nada que distinguir. --}}
                    <div id="pdfComparaEtiquetaIzq"
                        style="display:none; position:absolute; top:0; left:0; right:0; z-index:40; background:rgba(45,55,72,0.92); color:#e2e8f0; font-size:11px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; padding:5px 10px; text-align:center;">
                        Original
                    </div>

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

                {{-- Segundo panel: la corrección. Nace oculto y sin src —un iframe con
                     documento cargado consume memoria aunque no se vea— y lo llena
                     pdfCompararToggle() al encenderlo. --}}
                <div id="pdfComparaPanel"
                    style="display:none; flex:1; position:relative; border-left:3px solid #1a202c; min-width:0;">
                    <div id="pdfComparaEtiquetaDer"
                        style="position:absolute; top:0; left:0; right:0; z-index:40; background:rgba(45,55,72,0.92); color:#93c5fd; font-size:11px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; padding:5px 10px; text-align:center;">
                        Corrección
                    </div>
                    <iframe id="pdfComparaFrame" src=""
                        style="width:100%; height:100%; border:none; background:#4a5568;"
                        allowfullscreen></iframe>
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

                            {{-- Tercera guarda del visor con la MISMA regla que las otras dos
                                 (Actualizar Documento y Anexar correccion): updateMetadata
                                 tambien exige 'user.edit'. Ademas los campos de arriba ya se
                                 pintan disabled cuando falta CAN_UPDATE_INFO —que es ese mismo
                                 permiso—, asi que con la guarda laxa salia un formulario
                                 bloqueado con un boton "Guardar Cambios" activo encima. --}}
                            @if(auth()->user() && auth()->user()->can('user.edit'))
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
        {{-- Los TRES siguientes eran un unico <script> inline del layout. Van seguidos y EN
             ESTE ORDEN a proposito: es el orden en que se ejecutaba el bloque original. --}}
        <script
            src="{{ asset('js/maquinaria/preloader.js') }}?v={{ @filemtime(public_path('js/maquinaria/preloader.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/offline_mode.js') }}?v={{ @filemtime(public_path('js/maquinaria/offline_mode.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/global_handlers.js') }}?v={{ @filemtime(public_path('js/maquinaria/global_handlers.js')) }}"></script>

        {{-- Core Scripts (Always Loaded) --}}

        {{-- dom_helpers.js NO va aquí: se carga en el <head> (ver arriba) porque los
             scripts inline del contenido lo usan antes de llegar a este bloque. --}}
        <script
            src="{{ asset('js/maquinaria/module_manager.js') }}?v={{ @filemtime(public_path('js/maquinaria/module_manager.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/uicomponents.js') }}?v={{ @filemtime(public_path('js/maquinaria/uicomponents.js')) }}"></script>
        {{-- Buscador "estilo Google" compartido (window.FuzzySearch): lo usan Inventario
             y Recepción. Global aquí → sobrevive a la navegación SPA. --}}
        <script
            src="{{ asset('js/maquinaria/fuzzy_search.js') }}?v={{ @filemtime(public_path('js/maquinaria/fuzzy_search.js')) }}"></script>
        {{-- producto_suggest.js y qr_scan.js NO van aquí: se cargan en el <head> (ver arriba),
             por el mismo motivo que dom_helpers.js. --}}
        <script
            src="{{ asset('js/maquinaria/navegacion.js') }}?v={{ @filemtime(public_path('js/maquinaria/navegacion.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/form_logic.js') }}?v={{ @filemtime(public_path('js/maquinaria/form_logic.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/equipo_catalog_linking.js') }}?v={{ @filemtime(public_path('js/maquinaria/equipo_catalog_linking.js')) }}"></script>

        {{-- Chart.js NO va aquí: son 205 KB que solo usan tres pantallas, y estos <script>
             son síncronos, así que parsearlo en todas retrasaba la interactividad de todas.
             Lo piden con window.ensureChartJS() — ver js/maquinaria/lazy_loader.js. --}}

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
        {{-- Módulo "Mapa Satelital": 265 KB que solo usa /mapa. Iba como <script> fijo,
             o sea que TODAS las páginas lo parseaban para nada. Se pide al detectar su
             contenedor, en la carga inicial y en cada navegación SPA (ModuleManager ya
             reevalúa los detectores en spa:contentLoaded). Sigue siendo global: se
             inyecta en el <head>, no dentro del contenido, así que la navegación SPA no
             lo pierde. Al llegar con el DOM ya listo, mapa_index se autoinicializa
             —su initMapa() corre solo si readyState !== 'loading'—, y es idempotente
             (sale si #mapa-leaflet ya tiene mapa montado). --}}
        <script>
            window.ModuleManager.register('mapa_satelital',
                function () { return !!document.getElementById('mapa-leaflet'); },
                function () {
                    // Sin test de "ya cargado": cargarScriptUnaVez cachea la promesa por
                    // URL, así que volver a /mapa reusa la descarga y no re-inyecta nada.
                    // A partir de la segunda visita monta el propio listener de
                    // spa:contentLoaded que mapa_index registró al cargarse.
                    //
                    // EL SPINNER TIENE QUE ESPERAR A ESTO. mapa_index.js son 268 KB que ya
                    // no viajan en el layout: se piden AHORA, al entrar al módulo. loadPage
                    // solo espera a que esté pintado el CASCARÓN, así que apagaba el spinner
                    // con #mapa-leaflet todavía vacío y el mapa aparecía después, en seco —
                    // "se quita el spinner y aún no ha cargado el mapa". Antes no pasaba
                    // porque el script ya venía cargado desde el layout.
                    //
                    // Se toma una referencia del preloader (es un CONTADOR: el spinner no se
                    // va hasta que todas las operaciones que lo pidieron terminan) y se
                    // suelta cuando el script ya corrió, es decir con el mapa montado —
                    // initMapa se autoejecuta al cargar y L.map() monta síncrono. Los tiles
                    // siguen llegando después, como en cualquier mapa, pero el usuario ya ve
                    // el mapa dibujado y no un hueco.
                    if (window.showPreloader) window.showPreloader();
                    window.cargarScriptUnaVez(
                        window.lazyBaseUrl() + '/js/maquinaria/mapa_index.js?v={{ @filemtime(public_path('js/maquinaria/mapa_index.js')) }}'
                    )
                    .catch(function (e) { console.error('Mapa Satelital no cargó:', e); })
                    .finally(function () {
                        // Doble rAF, igual que en loadPage: soltar la referencia DESPUÉS del
                        // paint que ya trae el mapa, no en el frame en que acaba el script.
                        // Va en finally para que un fallo de descarga no deje el spinner
                        // colgado: la referencia se devuelve pase lo que pase.
                        requestAnimationFrame(function () {
                            requestAnimationFrame(function () {
                                // Solo se devuelve la referencia si SEGUIMOS en el mapa. Si
                                // el usuario se fue a otro módulo mientras bajaban los 268 KB,
                                // loadPage ya arrancó su navegación con hidePreloader(true),
                                // que PONE EL CONTADOR A CERO: la referencia de aquí dejó de
                                // existir. Restar entonces se la quitaría a la navegación
                                // NUEVA y la destaparía a medio cargar — el mismo fallo que
                                // el contador documenta para las peticiones "silent". Como
                                // el reset ya la borró, no devolverla tampoco fuga nada.
                                if (!document.getElementById('mapa-leaflet')) return;
                                if (window.hidePreloader) window.hidePreloader();
                            });
                        });
                    });
                }
            );

            {{-- Selector de TIPO AUX y de EQUIPO VINCULADO (los nueve manejadores
                 window.auxTipo*/auxHost*). Lo usan /admin/equipos/create y la ficha del
                 auxiliar, y las dos lo pintan DENTRO de .main-viewport: por eso se pide
                 desde aqui y no desde la vista. Un <script src> dentro del contenido no
                 llega a ejecutarse al entrar por navegacion SPA —executeScripts lo
                 descarta al encontrarse el nodo inerte que dejo innerHTML con esa misma
                 URL—, y los combos quedaban muertos.

                 Mismo trato que el mapa: detector + cargarScriptUnaVez (que cachea por
                 URL), inyectado en el <head>, asi que la navegacion SPA no lo pierde y
                 volver a la pantalla no lo vuelve a descargar. #auxTipoCombo es el unico
                 ancla que existe en LAS DOS pantallas. --}}
            window.ModuleManager.register('aux_form_widgets',
                function () { return !!document.getElementById('auxTipoCombo'); },
                function () {
                    window.cargarScriptUnaVez(
                        window.lazyBaseUrl() + '/js/maquinaria/aux_form_widgets.js?v={{ @filemtime(public_path('js/maquinaria/aux_form_widgets.js')) }}'
                    ).catch(function (e) { console.error('Selectores de auxiliar no cargaron:', e); });
                }
            );
        </script>

        <script
            src="{{ asset('js/maquinaria/frentes_spa.js') }}?v={{ @filemtime(public_path('js/maquinaria/frentes_spa.js')) }}"></script>
        <script
            src="{{ asset('js/maquinaria/consumibles_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_index.js')) }}"></script>
        <script>
            {{-- Flags de permiso renderizados server-side. Los LEE layout_ui.js (cargado
                 justo debajo), que por ser un archivo estatico ya no puede traerlos consigo.
                 Van ANTES del <script src> a proposito: conservan el mismo orden que tenian
                 dentro del bloque inline, asi que equipos_index.js —cargado mas arriba, y que
                 tambien escribe CAN_ASSIGN_EQUIPOS / CAN_CHANGE_STATUS— sigue quedando pisado
                 por estos valores, igual que antes. --}}
            // Permission Flag (Global & Exposed to External Scripts)
            // CAN_UPDATE_INFO habilita: boton lapiz del modal detalles, upload
            // PDF del modal detalles, submit de edicion de ficha. SOLO mira
            // 'user.edit'. Gate::before resuelve super.admin automaticamente
            // dentro de ->can('user.edit') (no hay que repetirlo aca).
            window.CAN_UPDATE_INFO = {{ auth()->user() && auth()->user()->can('user.edit') ? 'true' : 'false' }};
            window.CAN_CREATE_EQUIPOS = {{ auth()->user() && auth()->user()->can('equipos.create') ? 'true' : 'false' }};
            window.CAN_ASSIGN_EQUIPOS = {{ auth()->user() && auth()->user()->can('equipos.assign') ? 'true' : 'false' }};
            window.CAN_CHANGE_STATUS = {{ auth()->user() && auth()->user()->can('equipos.edit') ? 'true' : 'false' }};
            // Permiso super.admin (renderizado server-side desde el flag de auth).
            // El boton de borrar solo se muestra cuando el usuario tiene super.admin,
            // pero esta variable se usa por el JS para defensa-en-profundidad ante
            // intentos de DOM-inject. El backend tambien valida con can:super.admin.
            window.CAN_DELETE_DOCS = {{ auth()->user() && auth()->user()->can('super.admin') ? 'true' : 'false' }};
        </script>
        <script
            src="{{ asset('js/maquinaria/layout_ui.js') }}?v={{ @filemtime(public_path('js/maquinaria/layout_ui.js')) }}"></script>
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
        {{-- El overlay "Actualizando…" va SOLO en el login (inicio_sesion.blade.php): en páginas
             internas una recarga forzada por actualización perdería trabajo del usuario. Aquí el
             SW nuevo se aplica sin interrumpir (pwa-install.js hace SKIP_WAITING). --}}

        {{-- ===== OFFLINE (Fase 1): baja la copia de datos a IndexedDB para consultar sin internet ===== --}}
        <script src="{{ asset('js/offline/offline-sync.js') }}?v={{ @filemtime(public_path('js/offline/offline-sync.js')) }}" defer></script>
        {{-- Botón "Copia local" (menú Configuraciones): fuerza la
             bajada del snapshot AHORA y da feedback. La lógica de descarga vive en
             offline-sync.js (OfflineDB.sync(true)); aquí solo va la parte de UI. --}}
        <script>
            window.descargarSnapshotOffline = function (ev) {
                if (ev) ev.preventDefault();
                var toast = window.toast;   // helper central (dom_helpers.js)
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
                    .then(function (r) {
                        // sync() resuelve con {ok, cambios}. Se distinguen los TRES casos: antes
                        // "ya estabas al día" (cambios=false) salía como "revisa tu conexión",
                        // que es el error opuesto — la copia estaba perfecta.
                        if (!r || !r.ok) toast('No se pudo descargar la copia. Revisa tu conexión e inténtalo de nuevo.', 'error');
                        else if (r.cambios) toast('Copia actualizada. Ya puedes trabajar sin internet.', 'success');
                        else toast('Tu copia local ya estaba al día.', 'success');
                    })
                    .catch(function () { toast('Error al descargar la copia.', 'error'); })
                    .finally(function () { window._descargandoSnapshot = false; });
            };
        </script>
        {{-- offline-auth.js NO se carga aquí a propósito: solo tiene trabajo en la pantalla
             de login (preparar y confirmar el verificador de acceso sin conexión, y el botón
             "Entrar sin conexión"). Antes se cargaba para "confirmar" un verificador que
             quedaba pendiente en localStorage, y eso ascendía a bueno el de un intento
             FALLIDO cuando se llegaba al menú por otra vía (huella, o el cambio de clave
             obligatorio). Ahora el pendiente vive en memoria y lo resuelve el propio login. --}}
        {{-- Fase 2: motor de sincronización del outbox (sube acciones hechas sin internet). --}}
        <script src="{{ asset('js/offline/outbox-sync.js') }}?v={{ @filemtime(public_path('js/offline/outbox-sync.js')) }}" defer></script>
        {{-- Fase 2: bandeja de pendientes (badge flotante #outboxTray). --}}
        <script>
            (function () {
                function $(id) { return document.getElementById(id); }
                var esc = window.escapeHtml;   // helper central (dom_helpers.js)
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