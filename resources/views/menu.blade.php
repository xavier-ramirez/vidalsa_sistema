@extends('layouts.estructura_base')

@section('title', 'Tablero de Control')

@section('content')

<style>
    /* Forzar fondo blanco solo en el dashboard */
    body, .main-viewport {
        background-color: #ffffff !important;
    }
</style>

<!-- SVG Background unificado -->
@include('partials.background_svg')

<link rel="stylesheet" href="{{ asset('css/vistas/menu.css') }}?v={{ @filemtime(public_path('css/vistas/menu.css')) }}">

<div class="dashboard-container" style="padding: 10px 20px; position: relative; z-index: 1;">

    {{-- ── Hero: imagen de fondo con overlay oscuro y título blanco ── --}}
    <section class="menu-hero">
        <div class="menu-hero-stripes">
            {{-- ?v=filemtime: el service worker sirve /images/ con match EXACTO de URL, asi que
                 sin la firma una imagen reemplazada seguiria saliendo de cache. --}}
            @php $heroImg = asset('images/maquinaria_login_new.webp')
                 . '?v=' . @filemtime(public_path('images/maquinaria_login_new.webp')); @endphp
            <div><img src="{{ $heroImg }}" alt="" draggable="false" style="object-position: left center;"></div>
            <div><img src="{{ $heroImg }}" alt="" draggable="false" style="object-position: center center;"></div>
            <div><img src="{{ $heroImg }}" alt="" draggable="false" style="object-position: 85% 20%;"></div>
        </div>
        <div class="menu-hero-overlay"></div>
        <div class="menu-hero-content">
            <h1 class="menu-hero-title">
                Sistema de Gestión<br>
                de <span class="accent">Equipos Operacionales</span>
            </h1>
        </div>
        <div class="menu-hero-stat">
            <div class="menu-hero-stat-label">Flota activa</div>
            <div class="menu-hero-stat-value">{{ $totalFlotaActiva ?? 0 }}</div>
            <div class="menu-hero-stat-caption">Equipos en operación</div>
        </div>
    </section>

    <!-- Main Grid -->
    <div class="dashboard-grid">

        <!-- Column 1: Resumen Rápido (Cards) -->
        <div class="card-section" style="grid-column: span 12;">
            <div class="cards-wrapper">

                {{-- MOVILIZACIONES / "Equipos Por Confirmar Recepción": funcionalidad ELIMINADA
                     por completo (antes vivía en el centro de notificaciones del navbar, ya removido). --}}

                {{-- ── Card "Salud Operacional" (al lado de Alertas, 1 col del cards-wrapper) ── --}}
                @php
                    $_flotaTotal = $totalFlotaActiva ?? 0;
                    $_ok  = $equiposOperativos ?? 0;
                    $_mt  = $equiposMantenimiento ?? 0;
                    $_bad = $equiposInoperativos ?? 0;
                    $_pctOk  = $_flotaTotal > 0 ? round(($_ok  / $_flotaTotal) * 100, 1) : 0;
                    $_pctMt  = $_flotaTotal > 0 ? round(($_mt  / $_flotaTotal) * 100, 1) : 0;
                    $_pctBad = $_flotaTotal > 0 ? round(($_bad / $_flotaTotal) * 100, 1) : 0;
                @endphp
                <div class="salud-card">
                    <div class="salud-main-block">
                        <div class="salud-body">
                            <span class="salud-label">Salud Operacional</span>
                            <div class="salud-main">
                                <span class="salud-percent">{{ $_pctOk }}%</span>
                                <span class="salud-percent-sub">Operativos</span>
                            </div>
                            <div class="salud-bar" title="Operativos {{ $_pctOk }}% · Mantenimiento {{ $_pctMt }}% · Inoperativos {{ $_pctBad }}%">
                                <span class="salud-bar-ok"    style="width: {{ $_pctOk }}%"></span>
                                <span class="salud-bar-maint" style="width: {{ $_pctMt }}%"></span>
                                <span class="salud-bar-bad"   style="width: {{ $_pctBad }}%"></span>
                            </div>
                        </div>
                    </div>
                    <div class="salud-stats-group">
                        <div class="salud-stat ok">
                            <div class="salud-stat-value">{{ $_ok }}</div>
                            <div class="salud-stat-name">Operativos</div>
                        </div>
                        <div class="salud-stat maint">
                            <div class="salud-stat-value">{{ $_mt }}</div>
                            <div class="salud-stat-name">Mantenim.</div>
                        </div>
                        <div class="salud-stat bad">
                            <div class="salud-stat-value">{{ $_bad }}</div>
                            <div class="salud-stat-name">Inoperat.</div>
                        </div>
                    </div>
                </div>

                {{-- ── Boton "Mapa" (entre Salud y Alertas): abre el modulo de mapa.
                     Mismo estilo que Salud/Alertas: icono Material en caja oscura a la
                     izquierda + texto. Navegación SPA (como Equipos): mapa_index.js se
                     pide al detectar el contenedor del mapa y carga Leaflet e inicializa
                     en spa:contentLoaded, así que NO necesita recarga completa
                     (data-no-spa) ni preloader manual. --}}
                <a href="{{ route('mapa') }}" class="mapa-card"
                   title="Abrir mapa de ubicación de los proyectos">
                    <div class="mapa-card-icon">
                        <i class="material-icons">satellite_alt</i>
                    </div>
                    <div class="mapa-card-body">
                        <span class="mapa-card-label">Mapa</span>
                        <span class="mapa-card-sub">Ubicación de los proyectos</span>
                    </div>
                </a>

                <!-- ALERTAS: la card solo abre/cierra el modal #expiredDocsContainer (más abajo),
                     que va centrado sobre la pantalla y no empuja el layout del tablero. -->
                <div style="position: relative;">
                    <!-- Card trigger -->
                    <div class="alertas-card" onclick="toggleExpiredDocs()" role="button" tabindex="0"
                         onkeydown="if(event.key==='Enter'||event.key===' ') { event.preventDefault(); toggleExpiredDocs(); }">
                        <div class="alertas-card-icon">
                            <i class="material-icons {{ ($totalAlerts ?? 0) > 0 ? 'bell-shake' : '' }}">notifications_active</i>
                        </div>
                        <div class="alertas-card-body">
                            <span class="alertas-card-label">Alertas Documentos</span>
                            <div class="alertas-card-main">
                                {{-- "…" hasta que llegue la lista, si su total aún no estaba en caché. --}}
                                <span class="alertas-card-value">{{ $totalAlerts ?? '…' }}</span>
                                <span class="alertas-card-sub">Por Renovar</span>
                            </div>
                        </div>
                        <div class="alertas-card-chev">
                            <i class="material-icons">chevron_right</i>
                        </div>
                    </div>

                </div>

                {{-- Modal Alertas Documentos (abierto desde la card) --}}
                <div class="alertas-modal-overlay" id="expiredDocsContainer" onclick="if(event.target===this) toggleExpiredDocs()">
                    <div class="alertas-modal-content" role="dialog" aria-modal="true" aria-label="Alertas de Documentos">
                        <div class="alertas-panel-header">
                            <div class="alertas-panel-title">
                                <i class="material-icons">description</i>
                                <span>Alertas de Documentos</span>
                            </div>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <button type="button"
                                        onclick="abrirFormatoReporteAlertas()"
                                        class="alertas-header-btn"
                                        title="Descargar reporte">
                                    <i class="material-icons">download</i>
                                </button>
                                <button type="button" onclick="toggleExpiredDocs()"
                                        class="alertas-header-btn" title="Cerrar">
                                    <i class="material-icons">close</i>
                                </button>
                            </div>
                        </div>
                        <div class="alertas-panel-toolbar">
                            <div class="alertas-search-wrap">
                                <i class="material-icons">search</i>
                                <input type="text" id="alertSearch" class="alertas-search-input"
                                       placeholder="Buscar por placa, chasis, modelo…"
                                       onkeyup="filterDashboardAlerts()"
                                       autocomplete="off">
                            </div>
                        </div>
                        <div class="alertas-panel-body">
                            {{-- La lista llega en segundo plano, con el menú ya a la vista
                                 (menu.js · cargarAlertasDashboard). data-clave: la de sus datos,
                                 para reutilizar la de la visita anterior mientras no cambie. --}}
                            <div id="dashboardAlertsList" data-clave="{{ $claveAlertas }}">
                                <div class="empty-state js-alertas-cargando">
                                    <i class="material-icons" style="animation: spin-mini .8s linear infinite;">refresh</i>
                                    <p>Cargando alertas…</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Elegir formato del reporte de Alertas. Reutiliza el overlay y la tarjeta
                     del modal de alertas (.alertas-modal-overlay/.alertas-modal-content) para
                     que se vea igual; mientras está abierto, aquel se retira de pantalla.
                     Las dos rutas devuelven el mismo reporte con las mismas filas: lo único
                     que cambia es el formato del archivo. --}}
                <div class="alertas-modal-overlay formato-modal-overlay" id="formatoReporteModal"
                     onclick="if(event.target===this) cerrarFormatoReporteAlertas()">
                    <div class="alertas-modal-content formato-modal-content" role="dialog" aria-modal="true"
                         aria-label="Formato del reporte">
                        <div class="alertas-panel-header">
                            <div class="alertas-panel-title">
                                <i class="material-icons">download</i>
                                <span>Descargar reporte</span>
                            </div>
                            <button type="button" onclick="cerrarFormatoReporteAlertas()"
                                    class="alertas-header-btn" title="Cerrar">
                                <i class="material-icons">close</i>
                            </button>
                        </div>
                        <div class="formato-modal-body">
                            <p class="formato-modal-texto">¿En qué formato lo quieres?</p>
                            <div class="formato-opciones">
                                <button type="button" class="formato-opcion formato-opcion--pdf"
                                        onclick="descargarReporteAlertas(this, '{{ route('dashboard.exportDocumentsPDF') }}', 'pdf')">
                                    <i class="material-icons">picture_as_pdf</i>
                                    <span class="formato-opcion-nombre">PDF</span>
                                </button>
                                <button type="button" class="formato-opcion formato-opcion--excel"
                                        onclick="descargarReporteAlertas(this, '{{ route('dashboard.exportDocumentsExcel') }}', 'excel')">
                                    <i class="material-icons">table_chart</i>
                                    <span class="formato-opcion-nombre">Excel</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>


            </div>

            {{-- Slot PWA: boton "Instalar App". Fuera del .cards-wrapper (grid)
                 para que SIEMPRE quede debajo de las dos cards, nunca al lado.
                 Solo se inyecta contenido en mobile/no-standalone via pwa-install.js. --}}
            <div id="pwaInstallSlot" style="margin-top: 12px;"></div>

            {{-- ── Catálogo Destacado ──
                 Carrusel: se pintan 8 tarjetas (el CSS muestra de 2 a 7 según el ancho; la de
                 más es la que entra al deslizar) y TODOS los modelos van en #catRotPool: la que
                 sale por la izquierda vuelve por la derecha con el siguiente (script de abajo).
                 Tarjetas y lista salen del MISMO arreglo (data-idx = su posición), así el texto
                 de una tarjeta reciclada es idéntico al de una pintada aquí. --}}
            @if(isset($catalogosDestacados) && $catalogosDestacados->count() > 0)
                @php
                    $catItems = $catalogosDestacados->map(function ($c) {
                        $drive = \App\Models\CaracteristicaModelo::idDrive($c->FOTO_REFERENCIAL);
                        return [
                            'foto'   => $drive ? url('/storage/google/' . $drive . '?sz=w300') : null,
                            'modelo' => (string) $c->MODELO,
                            'anio'   => (string) $c->ANIO_ESPEC,
                            // TIPO (principal) y MARCA (secundario); sin TIPO cae al MODELO.
                            'titulo' => (string) ($c->TIPO ?: $c->MODELO),
                            'specs'  => $c->marca_calculada
                                ? $c->marca_calculada . (($c->MODELO && $c->TIPO) ? ' · ' . $c->MODELO : '')
                                : '',
                        ];
                    })->values();
                @endphp
                <div class="dashboard-catalogo-section" style="margin-top: 12px;">
                    <div class="cat-mini-grid" id="catMiniGrid">
                        <div class="cat-mini-track">
                        @foreach($catItems->take(8) as $item)
                            <a class="cat-mini-card" data-idx="{{ $loop->index }}" href="{{ route('catalogo.index') }}"
                               style="text-decoration:none; color:inherit;" title="Ver el catálogo de equipos">
                                <div class="cat-mini-photo">
                                    @if($item['foto'])
                                        <img src="{{ $item['foto'] }}"
                                             alt="{{ $item['modelo'] }}"
                                             loading="lazy"
                                             decoding="async"
                                             style="opacity:0; transition:opacity 0.25s ease;"
                                             onload="this.style.opacity=1"
                                             onerror="this.outerHTML='<i class=&quot;material-icons placeholder&quot;>image_not_supported</i>'">
                                    @else
                                        <i class="material-icons placeholder">precision_manufacturing</i>
                                    @endif

                                    <span class="cat-mini-anio-badge">
                                        <i class="material-icons" style="font-size:10px;">event</i>
                                        <span class="cat-mini-anio">{{ $item['anio'] }}</span>
                                    </span>
                                </div>
                                <div class="cat-mini-body">
                                    <span class="cat-mini-modelo">{{ $item['titulo'] }}</span>
                                    <span class="cat-mini-specs">{{ $item['specs'] }}</span>
                                </div>
                            </a>
                        @endforeach
                        </div>
                    </div>
                    <script type="application/json" id="catRotPool">@json($catItems)</script>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Pie informativo: breve descripción de la empresa + crédito de desarrollo.
         Datos tomados del sitio oficial https://www.vidalsa27.com (sobre-nosotros). --}}
    <footer class="menu-about">
        <div class="menu-about-cols">
            <div class="menu-about-left">
                <div class="menu-about-head">
                    <div class="menu-about-logo">
                        <img src="{{ asset('images/maquinaria/logo.webp') }}" alt="Constructora Vidalsa 27, C.A."
                             onerror="this.onerror=null;this.src='{{ asset('images/maquinaria/logo.png') }}';">
                    </div>
                </div>
                <p class="menu-about-desc">
                    Empresa venezolana de construcción con más de 15 años de experiencia
                    en el sector petrolero y de vivienda, ejecutando oleoductos, líneas de flujo
                    y obras civiles de impacto nacional.
                </p>
            </div>
            <div class="menu-about-right">
                <span class="dev">
                    <span class="collab-title">Sistema desarrollado</span>
                    <a href="mailto:fsanchez@cvidalsa27.com" class="dev-link" title="Enviar correo">
                        <i class="material-icons">badge</i>Fernando Sánchez · fsanchez@cvidalsa27.com
                    </a>
                </span>
                <span class="dev menu-about-collab">
                    <span class="collab-title">Levantamiento y gestión de información</span>
                    <a href="mailto:azerpa@cvidalsa27.com" class="dev-link" title="Escribir a Alejandro Zerpa">
                        <i class="material-icons">badge</i>Alejandro Zerpa · azerpa@cvidalsa27.com
                    </a>
                    <a href="mailto:bromero@cvidalsa27.com" class="dev-link" title="Escribir a Benny Romero">
                        <i class="material-icons">badge</i>Benny Romero · bromero@cvidalsa27.com
                    </a>
                </span>
            </div>
        </div>
    </footer>
</div>

    {{-- El panel del usuario se movió al header (ver layouts/estructura_base.blade.php) --}}

    <!-- Partial Modal for Equipment Details (Used by Alerts) -->
    @include('admin.equipos.partials.equipment_details_modal')

    {{-- Scripts inline del dashboard. Van dentro de @section('content') (no en
         @yield('extra_js')) para que el navegador SPA los re-ejecute al volver
         a /menu desde otra página — si vivieran en extra_js quedarían fuera de
         .main-viewport y window.descargarReporteAlertas sería undefined tras
         navegar via SPA. --}}
    <script>
        // Las Alertas de Documentos llegan en segundo plano, con el menú ya a la vista. En una
        // carga completa (tras el login, al recargar) menu.js va al final del body y aún no
        // llegó: se espera al DOM. Por la navegación de la app ya está cargado.
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function () { window.cargarAlertasDashboard(); });
        } else {
            window.cargarAlertasDashboard();
        }

        // ── Catálogo Destacado: carrusel ─────────────────────────────────────────────
        // Cada 5 s la tira avanza UNA tarjeta hacia la izquierda (deslizando 0,6 s), en bucle:
        // la que sale por la izquierda pasa al final —ya fuera de la vista— con el modelo que
        // lleva más tiempo sin mostrarse, así el DOM tiene siempre las mismas 8 tarjetas.
        // Pensado para gastar lo mínimo:
        //   · un solo temporizador, en pausa si la pestaña está oculta, si la sección no está
        //     a la vista (IntersectionObserver) o si el mouse está encima;
        //   · la foto del modelo nuevo se precarga mientras su tarjeta está fuera de la vista,
        //     y es la miniatura w300 que el navegador guarda 21 días: cada una se baja una vez;
        //   · nada si el sistema pide menos movimiento (prefers-reduced-motion).
        // Este <script> se re-ejecuta en cada vuelta a /menu por SPA: el temporizador y el
        // observer de la visita anterior se cancelan aquí, y el ciclo se apaga solo en cuanto
        // la sección ya no está en la página.
        (function () {
            if (window.__catRot) { clearTimeout(window.__catRot.timer); window.__catRot.io && window.__catRot.io.disconnect(); window.__catRot = null; }
            var grid = document.getElementById('catMiniGrid'), poolEl = document.getElementById('catRotPool');
            var track = grid && grid.querySelector('.cat-mini-track');
            if (!track || !poolEl) return;
            if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            var items = [];
            try { items = JSON.parse(poolEl.textContent) || []; } catch (e) { return; }

            var INTERVALO = 5000, DESLIZ = 600;
            var estado = { timer: null, io: null, enVista: true };
            window.__catRot = estado;
            var mostrado = [];   // idx → cuándo entró por última vez a una tarjeta (0 = nunca)
            function tarjetas() { return Array.prototype.slice.call(track.children); }
            tarjetas().forEach(function (c) { mostrado[+c.dataset.idx] = Date.now(); });

            if ('IntersectionObserver' in window) {
                estado.io = new IntersectionObserver(function (en) {
                    estado.enVista = en[0].isIntersecting;
                    // A la vista: las fotos de las tarjetas que esperan fuera del visor se bajan
                    // ya (con loading=lazy no cargarían hasta asomarse y entrarían en blanco).
                    if (estado.enVista) track.querySelectorAll('img[loading="lazy"]').forEach(function (i) { i.loading = 'eager'; });
                });
                estado.io.observe(grid);
            }

            // El modelo con foto que lleva más tiempo sin verse y no está en ninguna tarjeta
            // (-1 si todos están: con pocos modelos el carrusel gira con los mismos).
            function siguiente() {
                var ocupados = tarjetas().map(function (c) { return +c.dataset.idx; }), mejor = -1;
                items.forEach(function (it, i) {
                    if (!it.foto || ocupados.indexOf(i) !== -1) return;
                    if (mejor === -1 || (mostrado[i] || 0) < (mostrado[mejor] || 0)) mejor = i;
                });
                return mejor;
            }
            function programar() { estado.timer = setTimeout(tick, INTERVALO); }

            function tick() {
                // Se salió del menú (SPA): se apaga del todo.
                if (!document.body.contains(track)) { estado.io && estado.io.disconnect(); if (window.__catRot === estado) window.__catRot = null; return; }
                var lista = tarjetas();
                var aLaVista = parseInt(getComputedStyle(grid).getPropertyValue('--cat-vis'), 10) || 1;
                if (document.hidden || !estado.enVista || grid.matches(':hover') || lista.length <= aLaVista) { programar(); return; }
                var primera = lista[0];
                var paso = primera.getBoundingClientRect().width + (parseFloat(getComputedStyle(track).columnGap) || 0);
                track.style.transition = 'transform ' + DESLIZ + 'ms ease';
                track.style.transform = 'translateX(' + (-paso) + 'px)';
                setTimeout(function () {
                    // Sin transición: la primera pasa al final y la tira vuelve a su sitio en el
                    // mismo cuadro, así el salto no se ve.
                    track.style.transition = 'none';
                    track.appendChild(primera);
                    track.style.transform = '';
                    rellenar(primera);
                    programar();
                }, DESLIZ);
            }

            // La tarjeta que quedó al final (fuera de la vista) toma el siguiente modelo.
            function rellenar(card) {
                var idx = siguiente();
                if (idx === -1) return;
                var it = items[idx], pre = new Image();
                pre.onload = function () {
                    var foto = card.querySelector('.cat-mini-photo'), img = foto.querySelector('img');
                    if (!img) {   // la tarjeta tenía el ícono de "sin foto": se le pone su <img>
                        img = document.createElement('img');
                        img.decoding = 'async';
                        var ph = foto.querySelector('.placeholder');
                        if (ph) ph.replaceWith(img); else foto.prepend(img);
                    }
                    img.style.opacity = 1;
                    img.alt = it.modelo;
                    img.src = it.foto;   // ya precargada: sale de la caché
                    card.querySelector('.cat-mini-anio').textContent = it.anio;
                    card.querySelector('.cat-mini-modelo').textContent = it.titulo;
                    card.querySelector('.cat-mini-specs').textContent = it.specs;
                    card.dataset.idx = idx;
                    mostrado[idx] = Date.now();
                };
                pre.onerror = function () { mostrado[idx] = Infinity; };   // foto rota: no se vuelve a intentar
                pre.src = it.foto;
            }

            programar();
        })();

        // Animación slideDown: la usan modales de otras pantallas (equipos, movilizaciones)
        // que no la definen; una vez inyectada vale para toda la pestaña.
        (function () {
            if (window.__menuStyleRDInjected) return;
            window.__menuStyleRDInjected = true;
            const _styleRD = document.createElement('style');
            _styleRD.textContent = '@keyframes slideDown { from { transform: translateY(-30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }';
            document.head.appendChild(_styleRD);
        })();

        // ── Reporte de Alertas de Documentos: elegir formato y descargar ──────────
        // El botón del panel ya no baja el PDF directo: abre este modal para escoger.

        window.abrirFormatoReporteAlertas = function () {
            const modal = document.getElementById('formatoReporteModal');
            if (modal) modal.classList.add('open');
            // El de alertas se retira mientras se elige formato (lo devuelve el cerrar).
            const alertas = document.getElementById('expiredDocsContainer');
            if (alertas) alertas.classList.add('oculto-tras-formato');
        };

        window.cerrarFormatoReporteAlertas = function () {
            const modal = document.getElementById('formatoReporteModal');
            if (modal) modal.classList.remove('open');
            const alertas = document.getElementById('expiredDocsContainer');
            if (alertas) alertas.classList.remove('oculto-tras-formato');
        };

        // Descarga el reporte en el formato pedido. Los dos formatos comparten TODO el
        // camino —misma petición, mismo spinner, mismo blob, mismo <a download>— porque
        // lo único que cambia entre ellos es la URL y el tipo de archivo que se acepta.
        //
        // El nombre del archivo lo manda el servidor en Content-Disposition; el de aquí
        // es solo el respaldo por si esa cabecera no llega.
        //
        // La tabla vive DENTRO de la función y no en el bloque: este <script> se re-ejecuta
        // en cada vuelta a /menu por SPA (ver el comentario de arriba), y un `const` suelto
        // reventaba la segunda ejecución entera con "Identifier has already been declared".
        // Por eso todo aquí cuelga de window o vive dentro de una función. Rearmar dos
        // objetos por clic no cuesta nada.
        window.descargarReporteAlertas = async function (btn, url, formato) {
            if (btn && btn.disabled) return;

            const FORMATOS = {
                pdf:   { accept: 'application/pdf',
                         respaldo: 'Reporte_Documentos.pdf',
                         error: 'Error generando el PDF. Intente de nuevo.' },
                excel: { accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                         respaldo: 'Reporte_Documentos.xlsx',
                         error: 'Error generando el Excel. Intente de nuevo.' },
            };
            const cfg = FORMATOS[formato] || FORMATOS.pdf;

            if (btn) btn.disabled = true;
            // El modal de formato se cierra de una —la espera la cuenta el spinner
            // global— y con eso vuelve el panel de alertas que había reemplazado.
            window.cerrarFormatoReporteAlertas();
            if (window.showPreloader) window.showPreloader();

            try {
                const response = await window.apiFetch(url, {
                    method: 'GET',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': cfg.accept }
                });

                if (!response.ok) throw new Error('El servidor no pudo generar el reporte');

                const blob = await response.blob();
                const objUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = objUrl;
                let filename = cfg.respaldo;
                const disposition = response.headers.get('Content-Disposition');
                if (disposition && disposition.indexOf('filename=') !== -1) {
                    const m = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
                    if (m && m[1]) filename = m[1].replace(/['"]/g, '');
                }
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { document.body.removeChild(a); window.URL.revokeObjectURL(objUrl); }, 100);

                window.toast('Reporte descargado correctamente.', 'success');

            } catch (err) {
                console.error(err);
                window.toast(cfg.error, 'error');
            } finally {
                if (window.hidePreloader) window.hidePreloader();
                if (btn) btn.disabled = false;
            }
        };
    </script>

@endsection
