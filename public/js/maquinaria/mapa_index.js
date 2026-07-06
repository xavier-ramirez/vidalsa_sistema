// ═══════════════════════════════════════════════════════════════════════════
// Módulo "Mapa Satelital" — compatible con navegación SPA.
//
// La SPA (navegacion.js) IGNORA los <script> dentro de @section('content'), así
// que este archivo se carga UNA sola vez en el layout y se engancha a
// DOMContentLoaded + spa:contentLoaded para inicializar el mapa cuando aparece
// el contenedor #mapa-leaflet. Leaflet + el geocoder se cargan de forma diferida
// (una sola vez, sin bloquear otras páginas) desde el PROPIO servidor
// (public/vendor/leaflet) — antes venían de unpkg.com; alojarlos local hace que el
// mapa cargue más rápido y no dependa de un CDN externo. Los tiles satelitales SÍ
// siguen viniendo de Esri/Google (no se pueden alojar: son terabytes bajo demanda).
// ═══════════════════════════════════════════════════════════════════════════
(function () {
    'use strict';

    var LEAFLET_CSS  = '/vendor/leaflet/leaflet.css';
    var GEOCODER_CSS = '/vendor/leaflet/Control.Geocoder.css';
    var LEAFLET_JS   = '/vendor/leaflet/leaflet.js';
    var GEOCODER_JS  = '/vendor/leaflet/Control.Geocoder.js';
    var PROJ4_JS     = '/vendor/leaflet/proj4.js'; // convertir UTM ↔ lat/lng

    // Inserta un <link rel=stylesheet> una sola vez.
    function ensureCss(href) {
        if (document.querySelector('link[data-mapa-css="' + href + '"]')) return;
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        l.setAttribute('data-mapa-css', href);
        document.head.appendChild(l);
    }

    // Carga un <script src> una sola vez; devuelve Promise que resuelve al cargar.
    var _scriptPromises = {};
    function loadScript(src) {
        if (_scriptPromises[src]) return _scriptPromises[src];
        _scriptPromises[src] = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.async = false; // preserva el orden (el geocoder depende de Leaflet)
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('No se pudo cargar ' + src)); };
            document.head.appendChild(s);
        });
        return _scriptPromises[src];
    }

    // Garantiza Leaflet + geocoder disponibles (CSS + JS), luego resuelve.
    function ensureLeaflet() {
        ensureCss(LEAFLET_CSS);
        ensureCss(GEOCODER_CSS);
        var chain = (typeof L !== 'undefined' && L.map) ? Promise.resolve() : loadScript(LEAFLET_JS);
        return chain.then(function () {
            if (typeof L !== 'undefined' && L.Control && L.Control.Geocoder) return;
            return loadScript(GEOCODER_JS);
        }).then(function () {
            if (typeof proj4 !== 'undefined') return;
            return loadScript(PROJ4_JS).catch(function () {}); // proj4 opcional (UTM)
        });
    }

    // Construye el mapa dentro del contenedor dado.
    function buildMap(el) {
        if (el._leaflet_id) return; // ya inicializado (evita doble init)

        // ── Bounding box de Venezuela (aprox) ──
        var VENEZUELA = L.latLngBounds([0.6, -73.4], [12.6, -59.8]);

        var map = L.map(el, {
            center: [7.5, -66.0],
            zoom: 6,
            maxZoom: 21,
            zoomControl: false, // sin botones +/−: se acerca/aleja con la rueda del mouse
            doubleClickZoom: false, // el doble-clic NO acerca (pedido del usuario)
            worldCopyJump: true,
            attributionControl: false, // oculta el texto "Leaflet | Imágenes © …"
            // Zoom MÁS SUAVE con la rueda: más píxeles por nivel (menos brusco) + pasos finos.
            zoomSnap: 0.25,
            zoomDelta: 0.5,
            wheelPxPerZoomLevel: 140,
            wheelDebounceTime: 45
        });

        // ── PANE de etiquetas con z-index FIJO (encima del satélite) ──
        // La opción zIndex por-capa de Leaflet se reasignaba y dejaba las etiquetas
        // DEBAJO del satélite (no se veían los nombres). Con un pane dedicado es fijo.
        map.createPane('labelsPane');
        map.getPane('labelsPane').style.zIndex = 450; // encima del satélite y de los estados
        map.getPane('labelsPane').style.pointerEvents = 'none'; // deja pasar el clic al mapa

        // ── SATÉLITE Esri World Imagery (el mapa base — el que se ve) ──
        // maxNativeZoom 17: pasado de ahí Esri devuelve la tesela "Map data not yet
        // available" en zonas rurales; con 17 reescala la última imagen real en vez de
        // ese cartel (se ve borroso al máximo, pero limpio, sin texto ni blanco).
        var sateliteEsri = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            {
                maxZoom: 21,
                maxNativeZoom: 17,
                crossOrigin: 'anonymous', // permite exportar el mapa a imagen (canvas sin "tainting")
                attribution: 'Imágenes &copy; Esri, Maxar'
            }
        );

        // Etiquetas de Google (lyrs=h): nombres de ciudades, calles y carreteras,
        // transparentes — pane labelsPane (ENCIMA del satélite). Los mismos de Google Maps.
        var etiquetas = L.tileLayer('https://{s}.google.com/vt/lyrs=h&x={x}&y={y}&z={z}', {
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
            maxZoom: 21,
            maxNativeZoom: 20,
            pane: 'labelsPane',
            crossOrigin: 'anonymous', // permite exportar el mapa a imagen (canvas sin "tainting")
            attribution: 'Etiquetas &copy; Google'
        });

        // Base Esri + etiquetas de nombres encima.
        sateliteEsri.addTo(map);
        etiquetas.addTo(map);
        map.fitBounds(VENEZUELA);

        // Escapa HTML (para tooltips/menús).
        function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
        // "ANZOÁTEGUI" → "Anzoátegui" (para mostrar bonito).
        function nombreBonito(s) { return String(s || '').toLowerCase().replace(/(^|\s)\S/g, function (c) { return c.toUpperCase(); }); }

        // ── Forma de Venezuela + estados (GeoJSON local) ──
        var estiloEstado       = { color: '#ffffff', weight: 1.4, opacity: 0.9, fill: true, fillColor: '#ffffff', fillOpacity: 0.04 };
        var estiloEstadoHover  = { color: '#ffffff', weight: 2.6, opacity: 1, fillColor: '#ffffff', fillOpacity: 0.12 };
        // Resaltado FIJADO (clic derecho): amarillo con relleno; borde MÁS FINO que antes.
        var estiloEstadoFijado = { color: '#ffd23f', weight: 2.2, opacity: 1, fill: true, fillColor: '#ffd23f', fillOpacity: 0.28 };
        var estadosFijados = new Set();

        // Normaliza + ALIAS de estados renombrados, para cruzar estados ↔ municipios:
        //   municipios "BOLIVARIANO MIRANDA" → estado "Miranda"; municipios "VARGAS" → "La Guaira".
        function normEstado(s) {
            var n = String(s || '').toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
            n = n.replace(/^BOLIVARIANO\s+/, '').replace(/^ESTADO\s+/, '');
            if (n === 'VARGAS') n = 'LA GUAIRA';
            return n;
        }

        // Color estable por nombre para distinguir municipios (24 tonos: incluye gris,
        // negro y marrón para que se repitan MENOS).
        var PALETA_MUNI = [
            '#ef4444', '#f97316', '#f59e0b', '#eab308', '#84cc16', '#22c55e', '#10b981', '#14b8a6',
            '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7', '#d946ef', '#ec4899',
            '#f43f5e', '#92400e', '#b45309', '#78716c', '#64748b', '#334155', '#0f172a', '#65a30d'
        ];
        function colorMuni(nombre) {
            var h = 0, s = String(nombre || '');
            for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
            return PALETA_MUNI[h % PALETA_MUNI.length];
        }

        // Fija/quita el resaltado de un ESTADO buscándolo por nombre (usado desde el menú de municipio).
        function fijarEstadoPorNombre(nombreEstado) {
            var key = normEstado(nombreEstado);
            estados.eachLayer(function (l) {
                if (l.feature && normEstado(l.feature.properties.shapeName) === key) {
                    if (estadosFijados.has(l)) { estadosFijados.delete(l); estados.resetStyle(l); }
                    else { estadosFijados.add(l); l.setStyle(estiloEstadoFijado); l.bringToFront(); }
                }
            });
        }

        // ── MUNICIPIOS (solo por estado, vía clic derecho "Ver municipios") ──
        // Pane interactivo: municipios activados por estado — nombre al pasar el mouse.
        map.createPane('muniIntPane');
        map.getPane('muniIntPane').style.zIndex = 462;

        var muniData = null; // GeoJSON completo (para filtrar por estado)
        // Capa "municipios de los estados activados por clic derecho" — COLORES + nombre al pasar el mouse.
        var muniEstado = L.geoJSON(null, {
            pane: 'muniIntPane',
            style: function (f) {
                var c = colorMuni(f && f.properties && f.properties.municipio);
                // Relleno MÁS transparente para ver el satélite debajo.
                return { color: c, weight: 1, opacity: 0.85, fill: true, fillColor: c, fillOpacity: 0.12 };
            },
            onEachFeature: function (f, layer) {
                var m = (f.properties && f.properties.municipio) || 'Municipio';
                var e = (f.properties && f.properties.estado) || '';
                layer.bindTooltip('<b>Municipio ' + esc(m) + '</b>' + (e ? '<br><span style="opacity:.85;">Estado ' + esc(nombreBonito(e)) + '</span>' : ''),
                    { sticky: true, direction: 'top', className: 'estado-tooltip' });
                layer.on({
                    mouseover: function (ev) { ev.target.setStyle({ weight: 2, fillOpacity: 0.32 }); ev.target.bringToFront(); },
                    mouseout:  function (ev) { muniEstado.resetStyle(ev.target); },
                    contextmenu: function (ev) { menuMunicipio(ev, e, m); }
                });
            }
        }).addTo(map);
        // Números de identificación de cada municipio (círculo blanco con el número, sobre el mapa).
        var muniNumeros = L.layerGroup().addTo(map);
        var estadosConMuni = new Set();   // estados (normalizados) con TODOS sus municipios activados
        var muniIndividuales = new Set(); // municipios sueltos activados: clave "ESTADO|MUNICIPIO"
        var muniExcluidos = new Set();    // municipios quitados de la selección (aunque su estado esté activo)

        function muniKey(estado, municipio) { return normEstado(estado) + '|' + String(municipio || '').trim().toUpperCase(); }
        // ¿Debe mostrarse este municipio? (por estado o individual, y NO excluido)
        function muniVisible(e, m) {
            var k = muniKey(e, m);
            if (muniExcluidos.has(k)) return false;
            return estadosConMuni.has(normEstado(e)) || muniIndividuales.has(k);
        }

        function repintarMuniEstado() {
            muniEstado.clearLayers();
            muniNumeros.clearLayers();
            if (muniData && (estadosConMuni.size || muniIndividuales.size)) {
                var feats = muniData.features.filter(function (f) {
                    return muniVisible(f.properties && f.properties.estado, f.properties && f.properties.municipio);
                });
                if (feats.length) muniEstado.addData({ type: 'FeatureCollection', features: feats });
                // Número (identificador) al centro de cada municipio.
                var byKey = {};
                municipiosActivos().forEach(function (mu) { byKey[muniKey(mu.estado, mu.municipio)] = mu.num; });
                muniEstado.eachLayer(function (layer) {
                    var e = layer.feature.properties.estado, m = layer.feature.properties.municipio;
                    var num = byKey[muniKey(e, m)];
                    if (num && layer.getBounds) {
                        muniNumeros.addLayer(L.marker(layer.getBounds().getCenter(), {
                            icon: L.divIcon({ className: 'muni-num', html: '<span>' + num + '</span>', iconSize: [20, 20], iconAnchor: [10, 10] }),
                            interactive: false, keyboard: false, pane: 'muniIntPane'
                        }));
                    }
                });
            }
            if (typeof actualizarLeyenda === 'function') actualizarLeyenda(); // refleja municipios en la leyenda
        }
        function limpiarClavesEstado(set, k) { set.forEach(function (key) { if (key.indexOf(k + '|') === 0) set.delete(key); }); }
        function toggleMuniEstado(nombreEstado) {
            var k = normEstado(nombreEstado);
            if (estadosConMuni.has(k)) estadosConMuni.delete(k); else estadosConMuni.add(k);
            limpiarClavesEstado(muniExcluidos, k); // "Ver municipios" reinicia: muestra TODOS del estado
            repintarMuniEstado();
        }
        // Muestra SOLO ese municipio (apaga los "todos" de su estado, deja únicamente este).
        function mostrarSoloMunicipio(estado, municipio) {
            estadosConMuni.delete(normEstado(estado));
            muniExcluidos.delete(muniKey(estado, municipio));
            muniIndividuales.add(muniKey(estado, municipio));
            repintarMuniEstado();
        }
        // QUITA un municipio de la selección (deja los demás) → permite elegir VARIOS por estado.
        function quitarMunicipio(estado, municipio) {
            var k = muniKey(estado, municipio);
            muniIndividuales.delete(k);
            if (estadosConMuni.has(normEstado(estado))) muniExcluidos.add(k); // el estado muestra todos → excluye este
            repintarMuniEstado();
        }
        // Oculta todo lo de ese estado (los "todos", los sueltos y los excluidos de ese estado).
        function ocultarMunicipiosEstado(estado) {
            var k = normEstado(estado);
            estadosConMuni.delete(k);
            limpiarClavesEstado(muniIndividuales, k);
            limpiarClavesEstado(muniExcluidos, k);
            repintarMuniEstado();
        }

        // Menú de CLIC DERECHO sobre un MUNICIPIO: ocultar municipios de su estado / resaltar el estado.
        function menuMunicipio(ev, estado, municipio) {
            if (ev.originalEvent) { ev.originalEvent.preventDefault(); ev.originalEvent.stopPropagation(); }
            document.querySelectorAll('.mapa-ctx-menu').forEach(function (mm) { mm.remove(); });
            var menu = document.createElement('div');
            menu.className = 'mapa-ctx-menu';
            var coordM = ev.latlng ? (ev.latlng.lat.toFixed(6) + ', ' + ev.latlng.lng.toFixed(6)) : '';
            menu.innerHTML =
                '<div class="mapa-ctx-title">' + esc(municipio) + '</div>' +
                filaCoord(coordM) +
                '<button type="button" class="mapa-ctx-item" data-a="quitar"><i class="material-icons">remove_circle_outline</i>Quitar este municipio</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="solo"><i class="material-icons">filter_center_focus</i>Mostrar solo este municipio</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="ocultar"><i class="material-icons">layers_clear</i>Ocultar municipios (todos)</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="resaltar"><i class="material-icons">star_border</i>Resaltar estado</button>';
            var x = ev.originalEvent ? ev.originalEvent.clientX : 0;
            var y = ev.originalEvent ? ev.originalEvent.clientY : 0;
            menu.style.left = Math.min(x, window.innerWidth - 210) + 'px';
            menu.style.top  = Math.min(y, window.innerHeight - 140) + 'px';
            // En pantalla completa, el menú debe ir DENTRO del elemento en fullscreen
            // (lo de fuera no se ve). Si no, va al body normal.
            (document.fullscreenElement || document.body).appendChild(menu);
            menu.addEventListener('click', function (e2) {
                if (e2.target.closest && e2.target.closest('.mapa-ctx-coordcopy')) { copiarCoordenada(coordM); menu.remove(); return; }
                var b = e2.target.closest ? e2.target.closest('.mapa-ctx-item') : null; if (!b) return;
                var a = b.getAttribute('data-a');
                if (a === 'quitar') quitarMunicipio(estado, municipio);
                else if (a === 'solo') mostrarSoloMunicipio(estado, municipio);
                else if (a === 'ocultar') ocultarMunicipiosEstado(estado);
                else fijarEstadoPorNombre(estado);
                menu.remove();
            });
            var cerrar = function () { menu.remove(); document.removeEventListener('click', cerrar); };
            setTimeout(function () { document.addEventListener('click', cerrar); }, 0);
        }

        var muniUrl = el.getAttribute('data-municipios');
        if (muniUrl) {
            fetch(muniUrl).then(function (r) { return r.json(); }).then(function (gj) {
                muniData = gj;
                // Si marcaron "Todos los municipios" antes de que cargara el geojson, aplicarlo ahora.
                if (todosMuniOn) activarTodosMunicipios(); else repintarMuniEstado();
            }).catch(function () {});
        }

        // ── Menú de CLIC DERECHO sobre un estado: resaltar + ver municipios de ESE estado ──
        function menuEstado(ev, layer, nombre) {
            if (ev.originalEvent) { ev.originalEvent.preventDefault(); ev.originalEvent.stopPropagation(); }
            document.querySelectorAll('.mapa-ctx-menu').forEach(function (m) { m.remove(); });
            var fijado = estadosFijados.has(layer);
            var muniOn = estadosConMuni.has(normEstado(nombre));
            var coordE = ev.latlng ? (ev.latlng.lat.toFixed(6) + ', ' + ev.latlng.lng.toFixed(6)) : '';
            var menu = document.createElement('div');
            menu.className = 'mapa-ctx-menu';
            menu.innerHTML =
                '<div class="mapa-ctx-title">' + (nombre || 'Estado') + '</div>' +
                filaCoord(coordE) +
                '<button type="button" class="mapa-ctx-item" data-accion="resaltar">' +
                    '<i class="material-icons">' + (fijado ? 'star' : 'star_border') + '</i>' +
                    (fijado ? 'Quitar resaltado' : 'Dejar resaltado') +
                '</button>' +
                '<button type="button" class="mapa-ctx-item" data-accion="muni">' +
                    '<i class="material-icons">' + (muniOn ? 'layers_clear' : 'account_tree') + '</i>' +
                    (muniOn ? 'Ocultar municipios' : 'Ver municipios') +
                '</button>';
            var x = ev.originalEvent ? ev.originalEvent.clientX : 0;
            var y = ev.originalEvent ? ev.originalEvent.clientY : 0;
            menu.style.left = Math.min(x, window.innerWidth - 210) + 'px';
            menu.style.top  = Math.min(y, window.innerHeight - 140) + 'px';
            // En pantalla completa, el menú debe ir DENTRO del elemento en fullscreen
            // (lo de fuera no se ve). Si no, va al body normal.
            (document.fullscreenElement || document.body).appendChild(menu);
            menu.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('.mapa-ctx-coordcopy')) { copiarCoordenada(coordE); menu.remove(); return; }
                var b = e.target.closest ? e.target.closest('.mapa-ctx-item') : null; if (!b) return;
                var acc = b.getAttribute('data-accion');
                if (acc === 'resaltar') {
                    if (estadosFijados.has(layer)) { estadosFijados.delete(layer); estados.resetStyle(layer); }
                    else { estadosFijados.add(layer); layer.setStyle(estiloEstadoFijado); layer.bringToFront(); }
                } else {
                    toggleMuniEstado(nombre);
                }
                menu.remove();
            });
            var cerrar = function () { menu.remove(); document.removeEventListener('click', cerrar); };
            setTimeout(function () { document.addEventListener('click', cerrar); }, 0);
        }

        // Fila de coordenada para los menús de clic derecho: la coordenada y su botón de
        // copiar EN LA MISMA LÍNEA (el icono justo al lado, no debajo).
        function filaCoord(coord) {
            if (!coord) return '';
            return '<div class="mapa-ctx-coordrow">' +
                       '<span class="mapa-ctx-coord">' + coord + '</span>' +
                       '<button type="button" class="mapa-ctx-coordcopy" title="Copiar coordenada"><i class="material-icons">content_copy</i></button>' +
                   '</div>';
        }
        // Copia una coordenada al portapapeles con aviso.
        function copiarCoordenada(coord) {
            if (!coord) return;
            copyToClipboard(coord).then(function () { if (window.showToast) window.showToast('Coordenada copiada: ' + coord, 'success'); })
                .catch(function () { if (window.showToast) window.showToast('No se pudo copiar la coordenada.', 'error'); });
        }
        // Menú de CLIC DERECHO en zona sin estado (mar/vacío): muestra la coordenada + copiar.
        function menuCoordenada(ev) {
            document.querySelectorAll('.mapa-ctx-menu').forEach(function (m) { m.remove(); });
            var coord = ev.latlng.lat.toFixed(6) + ', ' + ev.latlng.lng.toFixed(6);
            var menu = document.createElement('div'); menu.className = 'mapa-ctx-menu';
            menu.innerHTML = filaCoord(coord);
            var x = ev.originalEvent ? ev.originalEvent.clientX : 0, y = ev.originalEvent ? ev.originalEvent.clientY : 0;
            menu.style.left = Math.min(x, window.innerWidth - 210) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 110) + 'px';
            (document.fullscreenElement || document.body).appendChild(menu);
            menu.addEventListener('click', function (e2) { if (e2.target.closest && e2.target.closest('.mapa-ctx-coordcopy')) { copiarCoordenada(coord); menu.remove(); } });
            var cerrar = function () { menu.remove(); document.removeEventListener('click', cerrar); };
            setTimeout(function () { document.addEventListener('click', cerrar); }, 0);
        }
        // Clic derecho general: si NO cayó sobre un estado/municipio (mar, vacío), muestra la coordenada.
        map.on('contextmenu', function (ev) {
            var t = ev.originalEvent && ev.originalEvent.target;
            if (t && t.classList && t.classList.contains('leaflet-interactive')) return; // lo maneja menuEstado/menuMunicipio
            menuCoordenada(ev);
        });

        var estados = L.geoJSON(null, {
            style: function () { return estiloEstado; },
            onEachFeature: function (feature, layer) {
                var nombre = (feature.properties && feature.properties.shapeName) || 'Estado';
                layer.bindTooltip(nombre, { sticky: true, direction: 'top', className: 'estado-tooltip' });
                layer.on({
                    // No tocar el estilo si el estado está FIJADO (clic derecho).
                    mouseover: function (e) { if (!estadosFijados.has(e.target)) e.target.setStyle(estiloEstadoHover); e.target.bringToFront(); },
                    mouseout:  function (e) { if (!estadosFijados.has(e.target)) estados.resetStyle(e.target); },
                    contextmenu: function (e) { menuEstado(e, e.target, nombre); }
                });
            }
        }).addTo(map);

        var geojsonUrl = el.getAttribute('data-geojson');
        if (geojsonUrl) {
            fetch(geojsonUrl).then(function (r) { return r.json(); }).then(function (gj) { estados.addData(gj); }).catch(function () {});
        }

        // Control de capas: un único interruptor "Todos los municipios". Los bordes de los
        // estados quedan SIEMPRE visibles (estados.addTo(map) arriba). Como los municipios no
        // son una capa simple (se pintan según estadosConMuni + repintarMuniEstado), usamos una
        // capa "fantasma" (layerGroup vacío) como respaldo del checkbox y reaccionamos a
        // overlayadd/overlayremove: marcar = activar todos, desmarcar = ocultar todos.
        var todosMuniOn = false;                 // estado del interruptor "Todos los municipios"
        var todosMuniToggle = L.layerGroup();    // capa fantasma solo para tener el checkbox
        // Activa TODOS los municipios de TODOS los estados (limpia exclusiones previas).
        function activarTodosMunicipios() {
            todosMuniOn = true;
            if (!muniData) return; // aún no cargó el geojson; al terminar la carga se reaplica
            muniExcluidos.clear();
            muniData.features.forEach(function (f) {
                var e = f.properties && f.properties.estado;
                if (e) estadosConMuni.add(normEstado(e));
            });
            repintarMuniEstado();
        }
        // Oculta TODOS los municipios (por estado, sueltos y exclusiones).
        function ocultarTodosMunicipios() {
            todosMuniOn = false;
            estadosConMuni.clear();
            muniIndividuales.clear();
            muniExcluidos.clear();
            repintarMuniEstado();
        }
        L.control.layers(
            null,
            { 'Todos los municipios': todosMuniToggle },
            { position: 'topright', collapsed: true }
        ).addTo(map);
        map.on('overlayadd',    function (e) { if (e.layer === todosMuniToggle) activarTodosMunicipios(); });
        map.on('overlayremove', function (e) { if (e.layer === todosMuniToggle) ocultarTodosMunicipios(); });

        // Escala al lado de la brújula (abajo-derecha), más grande.
        L.control.scale({ imperial: false, position: 'bottomright', maxWidth: 160 }).addTo(map);

        // ── Buscador de zonas (geocoder) sesgado a Venezuela ──
        // Buscador: Photon (autocompletar, sin rate-limit) + detección de COORDENADAS.
        // Si escribes "lat, lng" (ej. 8.72370, -62.90443) va DIRECTO a ese punto.
        var _photon = L.Control.Geocoder.photon({ geocodingQueryParams: { lat: 8, lon: -66, limit: 8 } });
        // UTM zona 20N (Venezuela oriental, meridiano central −63°). REGVEN ≈ WGS84.
        var UTM20N = '+proj=utm +zone=20 +datum=WGS84 +units=m +no_defs';
        function parseUTM(q) {
            if (typeof proj4 === 'undefined') return null;
            var s = String(q || '').toUpperCase();
            // Captura N y E (con o sin guion, en cualquier orden). Miles con "." y decimal con ",".
            var mN = s.match(/N\s*[-:]?\s*([\d.]+,?\d*)/);
            var mE = s.match(/E\s*[-:]?\s*([\d.]+,?\d*)/);
            if (!mN || !mE) return null;
            var N = parseFloat(mN[1].replace(/\./g, '').replace(',', '.'));
            var E = parseFloat(mE[1].replace(/\./g, '').replace(',', '.'));
            if (isNaN(N) || isNaN(E) || E < 100000 || E > 900000 || N < 0 || N > 10000000) return null;
            try {
                var ll = proj4(UTM20N, 'WGS84', [E, N]); // [lng, lat]
                if (ll[1] < -5 || ll[1] > 20 || ll[0] < -80 || ll[0] > -55) return null; // sanity: Venezuela
                return L.latLng(ll[1], ll[0]);
            } catch (e) { return null; }
        }
        function parseCoord(q) {
            var s = String(q || '').trim();
            // 1) Decimal "lat, lng" (ej. 8.8388, -63.1105).
            var m = s.replace(/[°]/g, '').match(/^(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)$/);
            if (m) {
                var lat = parseFloat(m[1]), lng = parseFloat(m[2]);
                if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) return L.latLng(lat, lng);
            }
            // 2) UTM "N- 1.032.594,40 E- 501.623,42".
            return parseUTM(s);
        }
        function resCoord(c) { return [{ name: '📍 ' + c.lat.toFixed(5) + ', ' + c.lng.toFixed(5), center: c, bbox: c.toBounds(1200) }]; }
        var geocoderMapa = {
            geocode: function (q, cb, ctx) {
                var c = parseCoord(q);
                if (c) { cb.call(ctx, resCoord(c)); return; }
                _photon.geocode(q, cb, ctx);
            },
            suggest: function (q, cb, ctx) {
                var c = parseCoord(q);
                if (c) { cb.call(ctx, resCoord(c)); return; }
                if (_photon.suggest) _photon.suggest(q, cb, ctx); else cb.call(ctx, []);
            }
        };
        // VELA azul (pin de ubicación tipo Google Maps) para los puntos y la búsqueda.
        // Logo de la empresa (el mismo del favicon de la pestaña) para ponerlo dentro de la vela.
        var LOGO_URL = (document.querySelector('link[rel~="icon"]') || {}).href || '/favicon.png';
        var velaSeq = 0; // ids únicos para el clip-path de cada vela (evita que se mezclen entre SVGs)
        function velaIcon(color) {
            var c = color || '#0067b1';
            var cid = 'vclip' + (++velaSeq);
            // Pin de gota con el LOGO de la empresa recortado en círculo dentro del bulbo.
            return L.divIcon({
                className: 'mapa-vela',
                html: '<svg width="34" height="45" viewBox="0 0 24 32" xmlns="http://www.w3.org/2000/svg">' +
                      '<defs><clipPath id="' + cid + '"><circle cx="12" cy="11.4" r="7.5"/></clipPath></defs>' +
                      '<path d="M12 .6C6 .6 1.2 5.4 1.2 11.4c0 7.6 9.2 18.4 10 19.4.4.5 1.2.5 1.6 0 .8-1 10-11.8 10-19.4C22.8 5.4 18 .6 12 .6z" fill="' + c + '" stroke="#ffffff" stroke-width="1.5"/>' +
                      '<circle cx="12" cy="11.4" r="7.8" fill="#ffffff"/>' +
                      (LOGO_URL
                        ? '<image href="' + LOGO_URL + '" x="6.2" y="6" width="11.6" height="11" clip-path="url(#' + cid + ')" preserveAspectRatio="xMidYMid meet"/>'
                        : '<circle cx="12" cy="11.4" r="5" fill="' + c + '"/>') +
                      '</svg>',
                iconSize: [34, 45], iconAnchor: [17, 44], popupAnchor: [0, -41], tooltipAnchor: [0, -41]
            });
        }
        var buscadorMarker = null;
        function marcarBusqueda(c) {
            if (buscadorMarker) { map.removeLayer(buscadorMarker); buscadorMarker = null; }
            if (!c) return;
            buscadorMarker = L.marker(c, { icon: velaIcon('#0067b1'), zIndexOffset: 2000 }).addTo(map);
        }

        L.Control.geocoder({
            position: 'topleft',
            placeholder: 'Buscar lugar o coordenada…',
            defaultMarkGeocode: false, // el marcador lo ponemos nosotros (bola azul fiable)
            collapsed: false,          // barra de búsqueda SIEMPRE visible (no el iconito)
            suggestMinLength: 2,       // sugiere desde 2 letras (más ágil)
            suggestTimeout: 120,       // menos espera entre tecla y sugerencia
            geocoder: geocoderMapa
        }).on('markgeocode', function (e) {
            var c = e.geocode.center;
            if (e.geocode.bbox) map.fitBounds(e.geocode.bbox, { maxZoom: 16 });
            else if (c) map.setView(c, 15);
            cerrarBuscador();
            marcarBusqueda(c); // bola azul en el punto encontrado
            // Ofrecer GUARDAR ese punto en un proyecto. Si el resultado es una coordenada
            // (nombre "📍 …"), no sugerir nombre; si es un lugar, prefijarlo.
            if (c) setTimeout(function () {
                var nom = e.geocode.name || '';
                nom = (nom.indexOf('📍') === 0) ? '' : nom.split(',')[0];
                oleoPopupGuardar(c, nom);
            }, 420);
        }).addTo(map);

        // Colapsa/limpia la lista de resultados del buscador (no dejarla expandida tras elegir).
        function cerrarBuscador() {
            var alt = document.querySelector('.leaflet-control-geocoder-alternatives'); if (alt) alt.innerHTML = '';
            var gc = document.querySelector('.leaflet-control-geocoder');
            if (gc) { gc.classList.remove('leaflet-control-geocoder-options-open'); gc.classList.remove('leaflet-control-geocoder-error'); }
            var input = document.querySelector('.leaflet-control-geocoder-form input'); if (input) input.blur();
        }

        // ── Botón "Ver toda Venezuela" ──
        var FitVE = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('button', 'mapa-fit-btn');
                btn.type = 'button';
                btn.title = 'Ver toda Venezuela';
                btn.innerHTML = '<i class="material-icons">public</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', function () { map.fitBounds(VENEZUELA); });
                return btn;
            }
        });
        map.addControl(new FitVE());

        // ── Botón "Pantalla completa" (usa el contenedor del mapa como raíz) ──
        var FullScreen = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('button', 'mapa-fit-btn');
                btn.type = 'button';
                btn.title = 'Pantalla completa';
                btn.innerHTML = '<i class="material-icons">fullscreen</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', function () {
                    var enFS = document.fullscreenElement || document.webkitFullscreenElement;
                    if (!enFS) {
                        var req = el.requestFullscreen || el.webkitRequestFullscreen;
                        if (req) req.call(el);
                    } else {
                        var exit = document.exitFullscreen || document.webkitExitFullscreen;
                        if (exit) exit.call(document);
                    }
                });
                // Cambia el icono y recalcula el tamaño al entrar/salir de pantalla completa.
                var onFsChange = function () {
                    var enFS = (document.fullscreenElement === el) || (document.webkitFullscreenElement === el);
                    var ic = btn.querySelector('i');
                    if (ic) ic.textContent = enFS ? 'fullscreen_exit' : 'fullscreen';
                    setTimeout(function () { map.invalidateSize(); }, 120);
                };
                document.addEventListener('fullscreenchange', onFsChange);
                document.addEventListener('webkitfullscreenchange', onFsChange);
                return btn;
            }
        });
        map.addControl(new FullScreen());

        // ── Brújula (N/S/E/O): indicador fijo del norte (el mapa siempre está al norte). ──
        var Brujula = L.Control.extend({
            options: { position: 'bottomright' },
            onAdd: function () {
                var d = L.DomUtil.create('div', 'mapa-compass');
                d.title = 'Norte';
                // Brújula en SVG (sin círculo): aguja negra (norte) / gris (sur) + N/S/E/O.
                d.innerHTML =
                    '<svg viewBox="0 0 48 48" width="96" height="96" aria-hidden="true">' +
                        // Aguja: norte NEGRO, sur gris
                        '<polygon points="24,13 28,24 20,24" fill="#0f172a"/>' +
                        '<polygon points="24,35 20,24 28,24" fill="#64748b"/>' +
                        '<circle cx="24" cy="24" r="2" fill="#0f172a"/>' +
                        // N / S / E / O en negro, letras más FINAS (peso 500)
                        '<text x="24" y="9.5" text-anchor="middle" font-size="10" font-weight="500" fill="#0f172a" font-family="Inter,Segoe UI,sans-serif">N</text>' +
                        '<text x="24" y="47"  text-anchor="middle" font-size="9"  font-weight="500" fill="#0f172a" font-family="Inter,Segoe UI,sans-serif">S</text>' +
                        '<text x="45" y="27"  text-anchor="middle" font-size="9"  font-weight="500" fill="#0f172a" font-family="Inter,Segoe UI,sans-serif">E</text>' +
                        '<text x="4"  y="27"  text-anchor="middle" font-size="9"  font-weight="500" fill="#0f172a" font-family="Inter,Segoe UI,sans-serif">O</text>' +
                    '</svg>';
                L.DomEvent.disableClickPropagation(d);
                return d;
            }
        });
        map.addControl(new Brujula());

        // ── Créditos / fuente cartográfica (abajo-izquierda) ──
        var Creditos = L.Control.extend({
            options: { position: 'bottomleft' },
            onAdd: function () {
                var d = L.DomUtil.create('div', 'mapa-creditos');
                d.innerHTML =
                    '<div><b>ELABORADO POR:</b> Fernando Sánchez | Ingeniero Industrial</div>' +
                    '<div><b>FUENTE CARTOGRÁFICA:</b> Delimitación Municipal, Instituto Geográfico de Venezuela Simón Bolívar (IGVSB). Cartografía Oficial 2016.</div>';
                return d;
            }
        });
        map.addControl(new Creditos());

        // ── Clic izquierdo en el mapa: solo cierra el buscador. ──
        // La coordenada YA NO sale en un popup al hacer clic; se consulta con clic DERECHO
        // (menú de contexto), donde aparece junto a su botón de copiar.
        map.on('click', function () {
            if (typeof edMode !== 'undefined' && edMode) return; // en modo edición el clic dibuja
            cerrarBuscador();
        });

        // ══════════════════════════════════════════════════════════════════════
        //  PROYECTOS: cada proyecto tiene puntos (coordenadas con nombre) unidos por
        //  una línea que se dibuja como una tubería (guardados en la BD, API
        //  /mapa/oleoductos). Se busca un lugar/coordenada, se le pone nombre y se
        //  ELIGE a qué proyecto asociarlo → se agrega el punto y se dibuja la línea.
        // ══════════════════════════════════════════════════════════════════════
        var oleoMap = {};          // id oleoducto -> { data, lines:[], markers:[] }
        var oleoActivo = null;     // id del oleoducto activo (preseleccionado al guardar)
        // Frentes de trabajo = proyectos. Vienen del backend (window.mapaFrentes = [{id, nombre}]).
        // El selector "Proyecto" del popup se arma con estos; NO se crean proyectos a mano.
        var oleoFrentes = Array.isArray(window.mapaFrentes) ? window.mapaFrentes : [];
        // ¿Puede GESTIONAR proyectos? Depende del PERMISO 'super.admin' (window.mapaPuedeEditar,
        // que pone MapaController según usuarios.PERMISOS). Si es false → mapa de CONSULTA: sin
        // crear/asociar puntos, sin dibujar, sin borrar. El backend además valida las rutas.
        var PUEDE_EDITAR = !!window.mapaPuedeEditar;
        var OLEO_PALETA = ['#00e5ff', '#ff4081', '#76ff03', '#ffea00', '#ff6d00', '#d500f9', '#00e676', '#2979ff'];
        var oleoCSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

        function oleoApi(url, method, body) {
            return fetch(url, {
                method: method || 'GET',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': oleoCSRF, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: body ? JSON.stringify(body) : undefined
            }).then(function (r) { return r.json().catch(function () { return {}; }); });
        }
        // Spinner tradicional de la app (preloader contado por referencias).
        function spinOn() { if (window.showPreloader) window.showPreloader(); }
        function spinOff() { if (window.hidePreloader) window.hidePreloader(); }

        // Aclara un color hex mezclándolo con blanco (para el brillo de la tubería).
        function aclararColor(hex, f) {
            hex = String(hex || '#00e5ff').replace('#', '');
            if (hex.length === 3) hex = hex.split('').map(function (c) { return c + c; }).join('');
            var r = parseInt(hex.substr(0, 2), 16), g = parseInt(hex.substr(2, 2), 16), b = parseInt(hex.substr(4, 2), 16);
            if (isNaN(r) || isNaN(g) || isNaN(b)) return '#ffffff';
            r = Math.round(r + (255 - r) * f); g = Math.round(g + (255 - g) * f); b = Math.round(b + (255 - b) * f);
            return 'rgb(' + r + ',' + g + ',' + b + ')';
        }
        // Grosor de la tubería SEGÚN EL ZOOM: fina de lejos, gruesa de cerca (no se ve
        // enorme al alejarse). Devuelve los 3 pesos (borde, cuerpo, brillo).
        function pesoTuberia() {
            var cuerpo = Math.max(1.6, Math.min(9, (map.getZoom() - 4.5) * 0.95));
            return { borde: cuerpo + 3.5, cuerpo: cuerpo, brillo: Math.max(0.7, cuerpo * 0.32) };
        }
        // Dibuja la línea como TUBERÍA 3D: borde oscuro + cuerpo de color + brillo claro encima.
        // Longitud total de un trazo ([[lat,lng],…]) en KM: suma las distancias geodésicas
        // (map.distance = metros) entre puntos consecutivos. 0 si el trazo tiene menos de 2 puntos.
        function longitudKm(trazo) {
            if (!trazo || trazo.length < 2) return 0;
            var total = 0;
            for (var i = 1; i < trazo.length; i++) {
                total += map.distance(L.latLng(trazo[i - 1][0], trazo[i - 1][1]), L.latLng(trazo[i][0], trazo[i][1]));
            }
            return total / 1000;
        }
        function tuberiaCapas(trazo, color, target) {
            var m = target || map, p = pesoTuberia();
            var borde  = L.polyline(trazo, { color: '#0a1620', weight: p.borde, opacity: 0.85, lineJoin: 'round', lineCap: 'round', smoothFactor: 1 }).addTo(m);
            var cuerpo = L.polyline(trazo, { color: color, weight: p.cuerpo, opacity: 1, lineJoin: 'round', lineCap: 'round', smoothFactor: 1 }).addTo(m);
            var brillo = L.polyline(trazo, { color: aclararColor(color, 0.65), weight: p.brillo, opacity: 0.85, lineJoin: 'round', lineCap: 'round', smoothFactor: 1 }).addTo(m);
            return [borde, cuerpo, brillo];
        }
        // Reajusta el grosor de TODAS las tuberías al cambiar el zoom.
        function actualizarPesoTuberias() {
            var p = pesoTuberia();
            var aplicar = function (ls) {
                if (!ls) return;
                if (ls[0]) ls[0].setStyle({ weight: p.borde });
                if (ls[1]) ls[1].setStyle({ weight: p.cuerpo });
                if (ls[2]) ls[2].setStyle({ weight: p.brillo });
            };
            Object.keys(oleoMap).forEach(function (id) { aplicar(oleoMap[id].lines); });
            if (typeof edLineLayers !== 'undefined') aplicar(edLineLayers);
        }
        map.on('zoomend', actualizarPesoTuberias);

        function oleoDibujar(o) {
            if (oleoMap[o.id]) {
                (oleoMap[o.id].lines || []).forEach(function (l) { map.removeLayer(l); });
                (oleoMap[o.id].markers || []).forEach(function (m) { map.removeLayer(m); });
            }
            var pts = (o.puntos || []).slice().sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); });
            // La tubería SOLO aparece si se dibujó a mano con el lápiz (recorrido). Los puntos
            // NO se unen con una línea automática: cada punto es una "vela" (marcador de ubicación).
            var lines = (o.recorrido && o.recorrido.length >= 2)
                ? tuberiaCapas(o.recorrido.map(function (c) { return [c[0], c[1]]; }), o.color)
                : [];
            // Clic derecho sobre la tubería: editar o eliminar la línea (solo con permiso).
            if (PUEDE_EDITAR) lines.forEach(function (l) { l.on('contextmenu', function (ev) { menuLinea(ev, o.id); }); });
            // Tooltip con la LONGITUD total (km) al pasar el mouse por la línea. Solo si hay
            // recorrido dibujado (la tubería existe). Visible para todos (es info, no edición).
            if (lines.length) {
                var km = longitudKm(o.recorrido);
                var tipKm = '<b>' + esc(o.nombre) + '</b><br>Longitud: <b>' + km.toFixed(2).replace('.', ',') + ' km</b>';
                lines.forEach(function (l) { l.bindTooltip(tipKm, { sticky: true, direction: 'top', className: 'estado-tooltip' }); });
            }
            var markers = pts.map(function (p) {
                var mk = L.marker([p.lat, p.lng], { icon: velaIcon('#0067b1'), zIndexOffset: 500 }).addTo(map);
                // Etiqueta de la vela: nombre del PROYECTO arriba y, debajo, el nombre que el
                // usuario le puso al punto (ej. "PROGRESIVA 47+100"). La COORDENADA NO va aquí:
                // solo se muestra en el historial (leyenda).
                mk._velaTip = '<span class="vela-proj">' + esc(o.nombre) + '</span>' +
                              '<b>' + esc(p.nombre || 'Punto') + '</b>';
                // Clic derecho sobre la vela: eliminar ese punto del proyecto (solo con permiso).
                if (PUEDE_EDITAR) mk.on('contextmenu', function (ev) { menuVela(ev, p.id, p.nombre); });
                return mk;
            });
            oleoMap[o.id] = { data: o, lines: lines, markers: markers };
            declutterVelas(); // agrupa velas que se superponen en el zoom actual
        }

        // Agrupa las velas del MISMO proyecto que se superponen (según el zoom) y decide su etiqueta:
        //  · MÁS DE 300 km (zoom < 6): NO se muestran etiquetas fijas (se pegaban entre proyectos).
        //    Solo los PINES; el nombre del proyecto aparece al pasar el mouse (hover).
        //  · Más cerca: etiqueta FIJA por vela — si una vela AGRUPA varios puntos, solo el nombre del
        //    PROYECTO (no el del punto); si es un punto suelto, proyecto + nombre del punto.
        // Escala en km que muestra la barra del mapa (lo que ve el usuario: 300 km, 500 km…).
        // Se lee del control de escala; con moveend/zoomend la barra ya está actualizada.
        function escalaKm() {
            var el2 = document.querySelector('.leaflet-control-scale-line');
            if (!el2) return 0;
            var m = (el2.textContent || '').match(/([\d.]+)\s*(km|m)\b/);
            if (!m) return 0;
            var v = parseFloat(m[1]);
            return m[2] === 'km' ? v : v / 1000; // metros → km
        }
        function declutterVelas() {
            var THRESH = 38; // px — mayor que el ancho del pin (34) para que dos velas que se solapan se unan en una
            // A 300 km se ve el nombre del proyecto; a MÁS de 300 km (500 km…) solo los pines
            // (el nombre aparece al hacer foco). Umbral por la ESCALA, referencia del usuario.
            var lejos = escalaKm() > 300;
            // A más de 300 km el pin se ve exageradamente grande → se encoge por CSS (clase en el mapa).
            el.classList.toggle('mapa-velas-lejos', lejos);
            Object.keys(oleoMap).forEach(function (id) {
                var o = oleoMap[id].data, mks = oleoMap[id].markers || [], reps = [];
                mks.forEach(function (mk) {
                    if (!map.hasLayer(mk)) mk.addTo(map);
                    var p = map.latLngToContainerPoint(mk.getLatLng()), rep = null;
                    for (var i = 0; i < reps.length; i++) { if (reps[i].p.distanceTo(p) < THRESH) { rep = reps[i]; break; } }
                    if (rep) { rep.count++; map.removeLayer(mk); } else reps.push({ p: p, mk: mk, count: 1 });
                });
                var proyLbl = '<span class="vela-proj">' + esc(o.nombre) + '</span>';
                reps.forEach(function (r) {
                    r.mk.unbindTooltip();
                    if (lejos) {
                        // Solo el pin; el nombre del proyecto se ve al pasar el mouse (NO permanente).
                        r.mk.bindTooltip(proyLbl, { permanent: false, direction: 'right', offset: [10, -12], className: 'estado-tooltip vela-label' });
                    } else {
                        // Vela AGRUPADA (varios puntos) → solo proyecto; punto suelto → proyecto + punto.
                        r.mk.bindTooltip(r.count > 1 ? proyLbl : r.mk._velaTip, { permanent: true, direction: 'right', offset: [10, -12], className: 'estado-tooltip vela-label' });
                    }
                });
            });
        }
        map.on('zoomend moveend', declutterVelas); // moveend: recalcula tras asentarse la vista (carga/fit)

        function oleoRenderLista() {
            actualizarLeyenda(); // mantiene sincronizada la tabla-leyenda del mapa
            var cont = document.getElementById('oleoLista');
            if (!cont) return;
            var ids = Object.keys(oleoMap);
            if (!ids.length) { cont.innerHTML = '<div class="oleo-vacio">Sin proyectos aún. Busca un lugar y vincúlalo a un frente.</div>'; return; }
            cont.innerHTML = ids.map(function (id) {
                var o = oleoMap[id].data;
                var act = String(oleoActivo) === String(id);
                return '<div class="oleo-item' + (act ? ' oleo-item-activo' : '') + '" data-id="' + id + '">' +
                    '<span class="oleo-dot" style="background:' + o.color + '"></span>' +
                    '<span class="oleo-nom">' + esc(o.nombre) + '</span>' +
                    '<span class="oleo-cnt">' + (o.puntos ? o.puntos.length : 0) + '</span>' +
                    (PUEDE_EDITAR ? '<button class="oleo-del" title="Borrar" data-del="' + id + '">&times;</button>' : '') +
                '</div>';
            }).join('');
        }

        // Guarda un punto en el proyecto de un FRENTE (el backend find-or-crea el oleoducto de
        // ese frente) + redibuja. Si el proyecto se creó ahora, lo dibuja; si ya existía, agrega
        // el punto y redibuja. cb(ok).
        function oleoGuardarPuntoFrente(idFrente, latlng, nombre, cb) {
            var color = OLEO_PALETA[Object.keys(oleoMap).length % OLEO_PALETA.length];
            spinOn();
            oleoApi('/mapa/oleoductos/frente/' + idFrente + '/puntos', 'POST',
                { nombre: nombre || '', lat: latlng.lat, lng: latlng.lng, color: color }).then(function (res) {
                spinOff();
                if (res && res.success && res.oleoducto_id) {
                    var oid = res.oleoducto_id;
                    if (res.oleoducto_nuevo) {              // proyecto creado en esta llamada
                        res.oleoducto_nuevo.puntos = [res.punto];
                        oleoDibujar(res.oleoducto_nuevo);
                    } else if (oleoMap[oid]) {              // el proyecto del frente ya existía
                        oleoMap[oid].data.puntos.push(res.punto);
                        oleoDibujar(oleoMap[oid].data);
                    }
                    oleoActivo = oid;
                    oleoRenderLista();
                    if (window.showToast) window.showToast('Ubicación guardada en el proyecto.', 'success');
                    if (cb) cb(true);
                } else { if (window.showToast) window.showToast('No se pudo guardar la ubicación.', 'error'); if (cb) cb(false); }
            }).catch(function () { spinOff(); if (cb) cb(false); });
        }

        // Popup tras buscar/colocar una ubicación. AMBOS campos son OBLIGATORIOS: el NOMBRE del
        // punto y el PROYECTO (se elige de tus FRENTES de trabajo con un buscador que recomienda;
        // no se crean a mano). Al Guardar, el punto se PERSISTE en ese frente y aparece en la
        // leyenda. Si hay un proyecto activo, preselecciona SU frente (para cargar varios puntos
        // seguidos al mismo).
        function oleoPopupGuardar(latlng, nombreSugerido) {
            var coords = latlng.lat.toFixed(6) + ', ' + latlng.lng.toFixed(6);
            // Sin permiso 'super.admin' → solo consulta: se muestra la coordenada, sin formulario.
            if (!PUEDE_EDITAR) {
                var htmlRO = '<div class="oleo-save"><div class="oleo-save-c">' + coords + '</div>' +
                    '<div style="font-size:11.5px;color:#64748b;line-height:1.35;margin-top:2px;">Solo lectura. Para guardar puntos necesitas el permiso de gestión del mapa.</div></div>';
                L.popup({ className: 'mapa-oleo-pop', minWidth: 220, autoPan: true }).setLatLng(latlng).setContent(htmlRO).openOn(map);
                return;
            }
            var frenteActivo = (oleoActivo && oleoMap[oleoActivo]) ? oleoMap[oleoActivo].data.id_frente : null;
            var faObj = frenteActivo ? oleoFrentes.filter(function (f) { return String(f.id) === String(frenteActivo); })[0] : null;
            // Selector de proyecto tipo BUSCADOR (recomienda al escribir), no un <select> plano.
            var html = '<div class="oleo-save">' +
                '<label class="oleo-save-lbl">Nombre del punto <span class="oleo-req">*</span></label>' +
                '<input type="text" class="oleo-save-in" placeholder="Ej. PROGRESIVA 47+100" value="' + esc(nombreSugerido || '') + '">' +
                '<div class="oleo-save-c">' + coords + '</div>' +
                '<label class="oleo-save-lbl">Proyecto <span class="oleo-req">*</span></label>' +
                '<div class="oleo-save-pick">' +
                    '<input type="hidden" class="oleo-save-frente" value="' + (frenteActivo ? esc(String(frenteActivo)) : '') + '">' +
                    '<input type="text" class="oleo-save-search" placeholder="Escribe para buscar el frente…" autocomplete="off" value="' + esc(faObj ? faObj.nombre : '') + '">' +
                    '<div class="oleo-save-list"></div>' +
                '</div>' +
                '<button type="button" class="oleo-save-btn">Guardar punto</button>' +
                '<div class="oleo-save-err" style="display:none;"></div>' +
                '</div>';
            L.popup({ className: 'mapa-oleo-pop', minWidth: 240, autoPan: true }).setLatLng(latlng).setContent(html).openOn(map);
        }

        // Lógica del popup al abrirse: buscador de proyecto con recomendaciones + validación de los
        // dos campos obligatorios (nombre del punto y proyecto) antes de persistir con "Guardar".
        map.on('popupopen', function (ev) {
            var cont = ev.popup.getElement(); if (!cont) return;
            var pick = cont.querySelector('.oleo-save-pick');
            var btn  = cont.querySelector('.oleo-save-btn');
            if (!pick || !btn) return; // no es el popup de guardar punto

            var hid    = pick.querySelector('.oleo-save-frente');
            var search = pick.querySelector('.oleo-save-search');
            var list   = pick.querySelector('.oleo-save-list');
            var input  = cont.querySelector('.oleo-save-in');
            var err    = cont.querySelector('.oleo-save-err');
            if (input) setTimeout(function () { input.focus(); }, 30);

            function mostrarErr(msg) { if (err) { err.textContent = msg || ''; err.style.display = msg ? '' : 'none'; } }

            // Sugerencias del buscador: RECOMIENDA al escribir (reutiliza window.FuzzySearch.rank).
            function renderSug() {
                var term = search.value || '', arr;
                if (window.FuzzySearch && window.FuzzySearch.rank) {
                    arr = window.FuzzySearch.rank(oleoFrentes, term, function (f) { return { label: f.nombre, haystack: f.nombre }; });
                } else {
                    var q = term.toLowerCase();
                    arr = oleoFrentes.filter(function (f) { return !q || String(f.nombre).toLowerCase().indexOf(q) > -1; });
                }
                var h = '';
                arr.slice(0, 8).forEach(function (f) { h += '<div class="oleo-save-op" data-fid="' + esc(String(f.id)) + '">' + esc(f.nombre) + '</div>'; });
                list.innerHTML = h || '<div class="oleo-save-op oleo-save-op-none">Sin coincidencias</div>';
                list.style.display = 'block';
            }
            // Resuelve el frente: por selección de la lista, o por el nombre EXACTO tecleado.
            function resolverFrente() {
                if (hid.value) return hid.value;
                var t = (search.value || '').trim().toLowerCase();
                if (!t) return '';
                var m = oleoFrentes.filter(function (f) { return String(f.nombre).toLowerCase() === t; })[0];
                return m ? String(m.id) : '';
            }
            if (!btn._ob) {
                btn._ob = true;
                search.addEventListener('focus', renderSug);
                search.addEventListener('input', function () { hid.value = ''; mostrarErr(''); renderSug(); });
                search.addEventListener('blur', function () { setTimeout(function () { list.style.display = 'none'; }, 150); });
                list.addEventListener('mousedown', function (e) {
                    var op = e.target.closest ? e.target.closest('.oleo-save-op') : null;
                    if (!op || op.classList.contains('oleo-save-op-none')) return;
                    e.preventDefault();
                    hid.value = op.getAttribute('data-fid') || '';
                    search.value = op.textContent;
                    list.style.display = 'none';
                    mostrarErr('');
                });
                // AMBOS campos son OBLIGATORIOS: nombre del punto + proyecto.
                btn.addEventListener('click', function () {
                    var nombre = ((input && input.value) || '').trim();
                    if (!nombre) { mostrarErr('Escribe el nombre del punto.'); if (input) input.focus(); return; }
                    var idFrente = resolverFrente();
                    if (!idFrente) { mostrarErr('Elige un proyecto de la lista.'); search.focus(); renderSug(); return; }
                    mostrarErr('');
                    var ll = ev.popup.getLatLng();
                    btn.disabled = true; btn.textContent = 'Guardando…';
                    oleoGuardarPuntoFrente(idFrente, ll, nombre, function (ok) {
                        if (ok) { marcarBusqueda(null); map.closePopup(); }
                        else { btn.disabled = false; btn.textContent = 'Guardar punto'; }
                    });
                });
            }
        });

        // Panel de control "Proyectos" (arriba-derecha).
        var OleoCtrl = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function () {
                var wrap = L.DomUtil.create('div', 'oleo-ctrl');
                wrap.innerHTML =
                    '<button type="button" class="oleo-toggle" title="Proyectos (puntos unidos por una línea)"><i class="material-icons">timeline</i></button>' +
                    '<div class="oleo-panel" style="display:none;">' +
                        '<div class="oleo-panel-h">Proyectos</div>' +
                        '<div id="oleoLista" class="oleo-panel-lista"></div>' +
                    '</div>';
                L.DomEvent.disableClickPropagation(wrap);
                L.DomEvent.disableScrollPropagation(wrap);
                var panel = wrap.querySelector('.oleo-panel');
                wrap.querySelector('.oleo-toggle').addEventListener('click', function () { panel.style.display = (panel.style.display === 'none') ? 'block' : 'none'; });
                wrap.querySelector('#oleoLista').addEventListener('click', function (e2) {
                    var del = e2.target.closest ? e2.target.closest('[data-del]') : null;
                    if (del) {
                        var idd = del.getAttribute('data-del');
                        if (!confirm('¿Borrar este proyecto y todos sus puntos?')) return;
                        // Optimista: quita del mapa/lista YA (no espera al servidor) → se siente instantáneo.
                        if (oleoMap[idd]) {
                            (oleoMap[idd].lines || []).forEach(function (l) { map.removeLayer(l); });
                            (oleoMap[idd].markers || []).forEach(function (m) { map.removeLayer(m); });
                            delete oleoMap[idd];
                            if (String(oleoActivo) === String(idd)) oleoActivo = null;
                            oleoRenderLista();
                        }
                        spinOn();
                        oleoApi('/mapa/oleoductos/' + idd, 'DELETE').then(function () {
                            spinOff(); if (window.showToast) window.showToast('Proyecto borrado.', 'success');
                        }).catch(function () { spinOff(); });
                        return;
                    }
                    var item = e2.target.closest ? e2.target.closest('.oleo-item') : null;
                    if (item) { oleoActivo = item.getAttribute('data-id'); oleoRenderLista(); }
                });
                return wrap;
            }
        });
        map.addControl(new OleoCtrl());

        // ══════════════════════════════════════════════════════════════════════
        //  EDITOR DE RECORRIDO (tubería): parte UNIENDO los puntos del proyecto (o el
        //  recorrido ya dibujado) y lo AJUSTAS: arrastras los vértices y usas el "+"
        //  de cada tramo para curvar. Clic derecho en un vértice lo quita. Se guarda
        //  como `recorrido` del proyecto.
        // ══════════════════════════════════════════════════════════════════════
        var edMode = false, edId = null;
        var edPts = [];          // vértices editables (L.LatLng)
        var edLineLayers = [];   // capas de la tubería (preview)
        var edVertHandles = [];  // marcadores de vértice (arrastrables)
        var edMidHandles = [];   // marcadores "+" de punto medio (agregar vértice)

        function edSubmuestrear(arr, max) {
            max = max || 24;
            if (arr.length <= max) return arr.slice();
            var step = (arr.length - 1) / (max - 1), out = [];
            for (var i = 0; i < max; i++) out.push(arr[Math.round(i * step)]);
            return out;
        }
        function edQuitarCapas() {
            edLineLayers.forEach(function (l) { map.removeLayer(l); }); edLineLayers = [];
            edVertHandles.forEach(function (m) { map.removeLayer(m); }); edVertHandles = [];
            edMidHandles.forEach(function (m) { map.removeLayer(m); }); edMidHandles = [];
        }
        function edActualizarLinea() { edLineLayers.forEach(function (l) { if (l.setLatLngs) l.setLatLngs(edPts); }); }
        function edRender() {
            edQuitarCapas();
            var col = (oleoMap[edId] && oleoMap[edId].data.color) || '#00e5ff';
            if (edPts.length >= 2) edLineLayers = tuberiaCapas(edPts, col);
            // Vértices arrastrables.
            edPts.forEach(function (ll, i) {
                var mk = L.marker(ll, { icon: L.divIcon({ className: 'mapa-ed-vert', html: '', iconSize: [16, 16], iconAnchor: [8, 8] }), draggable: true, zIndexOffset: 1200 });
                mk.on('drag', function (e) { edPts[i] = e.target.getLatLng(); edActualizarLinea(); });
                mk.on('dragend', function () { edRender(); });
                mk.on('contextmenu', function (ev) { if (ev.originalEvent) ev.originalEvent.preventDefault(); if (edPts.length > 2) { edPts.splice(i, 1); edRender(); } });
                mk.addTo(map); edVertHandles.push(mk);
            });
            // "+" en cada tramo para insertar un vértice y curvar ese tramo.
            for (var k = 0; k < edPts.length - 1; k++) {
                var mid = L.latLng((edPts[k].lat + edPts[k + 1].lat) / 2, (edPts[k].lng + edPts[k + 1].lng) / 2);
                (function (idx) {
                    var mk = L.marker(mid, { icon: L.divIcon({ className: 'mapa-ed-mid', html: '+', iconSize: [16, 16], iconAnchor: [8, 8] }), draggable: true, zIndexOffset: 1100 });
                    var puesto = false;
                    mk.on('dragstart', function (e) { edPts.splice(idx + 1, 0, e.target.getLatLng()); puesto = true; });
                    mk.on('drag', function (e) { if (puesto) { edPts[idx + 1] = e.target.getLatLng(); edActualizarLinea(); } });
                    mk.on('dragend', function () { edRender(); });
                    mk.on('click', function () { edPts.splice(idx + 1, 0, mk.getLatLng()); edRender(); });
                    mk.addTo(map); edMidHandles.push(mk);
                })(k);
            }
        }

        function abrirPanelOleo() { var p = document.querySelector('.oleo-panel'); if (p) p.style.display = 'block'; }
        // Punto de entrada del botón LÁPIZ: elige el proyecto y entra a editar la línea.
        function iniciarDibujo() {
            var ids = Object.keys(oleoMap);
            if (!oleoActivo || !oleoMap[oleoActivo]) {
                if (ids.length === 1) { oleoActivo = ids[0]; oleoRenderLista(); }
                else if (!ids.length) { if (window.showToast) window.showToast('Primero vincula ubicaciones a un frente (busca un lugar en el mapa).', 'error'); abrirPanelOleo(); return; }
                else { if (window.showToast) window.showToast('Selecciona en el panel (arriba-derecha) el proyecto a editar.', 'error'); abrirPanelOleo(); return; }
            }
            entrarDibujo(oleoActivo);
        }
        function entrarDibujo(id) {
            if (!id || !oleoMap[id]) { if (window.showToast) window.showToast('Selecciona un proyecto primero.', 'error'); return; }
            if (edMode) return;
            var o = oleoMap[id].data;
            var base = (o.recorrido && o.recorrido.length >= 2)
                ? edSubmuestrear(o.recorrido, 24).map(function (c) { return L.latLng(c[0], c[1]); })
                : (o.puntos || []).slice().sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); }).map(function (p) { return L.latLng(p.lat, p.lng); });
            if (base.length < 2) { if (window.showToast) window.showToast('Agrega al menos 2 puntos al proyecto para trazar la línea.', 'error'); return; }
            edMode = true; edId = id; edPts = base;
            if (oleoMap[id]) { (oleoMap[id].lines || []).forEach(function (l) { map.removeLayer(l); }); } // oculta la tubería normal mientras se edita
            el.classList.add('mapa-editando');
            edRender();
            mostrarBarraDibujo();
            var panel = document.querySelector('.oleo-panel'); if (panel) panel.style.display = 'none';
        }
        function salirDibujo() {
            edMode = false;
            edQuitarCapas();
            el.classList.remove('mapa-editando');
            var bar = document.getElementById('mapaDibujoBar'); if (bar) bar.remove();
            var id = edId; edId = null; edPts = [];
            if (id && oleoMap[id]) oleoDibujar(oleoMap[id].data); // re-dibuja la tubería normal
        }
        function guardarDibujo() {
            var id = edId, ruta = edPts.map(function (ll) { return [ll.lat, ll.lng]; });
            spinOn();
            oleoApi('/mapa/oleoductos/' + id + '/recorrido', 'POST', { recorrido: ruta }).then(function (res) {
                spinOff();
                if (res && res.success && oleoMap[id]) {
                    oleoMap[id].data.recorrido = res.recorrido;
                    if (window.showToast) window.showToast(res.recorrido ? 'Línea guardada.' : 'Línea quitada.', 'success');
                    salirDibujo();
                } else if (window.showToast) window.showToast('No se pudo guardar la línea.', 'error');
            }).catch(function () { spinOff(); if (window.showToast) window.showToast('No se pudo guardar la línea.', 'error'); });
        }
        function mostrarBarraDibujo() {
            var old = document.getElementById('mapaDibujoBar'); if (old) old.remove();
            var nom = (oleoMap[edId] && oleoMap[edId].data.nombre) || 'proyecto';
            var bar = document.createElement('div'); bar.id = 'mapaDibujoBar'; bar.className = 'mapa-dibujo-bar';
            bar.innerHTML =
                '<div class="mapa-dibujo-txt">' +
                    '<span class="mapa-dibujo-titulo"><i class="material-icons">timeline</i>Editando ' + esc(nom) + '</span>' +
                    '<span class="mapa-dibujo-tip">Arrastra los puntos · <b>+</b> curva un tramo · clic derecho quita</span>' +
                '</div>' +
                '<button type="button" class="mapa-dibujo-btn primary" data-a="guardar">Guardar</button>' +
                '<button type="button" class="mapa-dibujo-btn" data-a="salir">Salir</button>';
            (document.fullscreenElement || el).appendChild(bar);
            L.DomEvent.disableClickPropagation(bar);
            L.DomEvent.disableScrollPropagation(bar);
            bar.addEventListener('click', function (e2) {
                var b = e2.target.closest ? e2.target.closest('[data-a]') : null; if (!b) return;
                if (b.getAttribute('data-a') === 'guardar') guardarDibujo(); else salirDibujo();
            });
        }

        // Menú de CLIC DERECHO sobre la línea (tubería): editar o eliminar.
        function menuLinea(ev, id) {
            if (ev.originalEvent) { ev.originalEvent.preventDefault(); }
            L.DomEvent.stop(ev);
            document.querySelectorAll('.mapa-ctx-menu').forEach(function (m) { m.remove(); });
            var nom = (oleoMap[id] && oleoMap[id].data.nombre) || 'proyecto';
            var menu = document.createElement('div'); menu.className = 'mapa-ctx-menu';
            menu.innerHTML =
                '<div class="mapa-ctx-title">' + esc(nom) + '</div>' +
                '<button type="button" class="mapa-ctx-item" data-a="editar"><i class="material-icons">edit</i>Editar línea</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="eliminar"><i class="material-icons">delete</i>Eliminar línea</button>';
            var x = ev.originalEvent ? ev.originalEvent.clientX : 0, y = ev.originalEvent ? ev.originalEvent.clientY : 0;
            menu.style.left = Math.min(x, window.innerWidth - 210) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 120) + 'px';
            (document.fullscreenElement || document.body).appendChild(menu);
            menu.addEventListener('click', function (e2) {
                var b = e2.target.closest ? e2.target.closest('.mapa-ctx-item') : null; if (!b) return;
                var a = b.getAttribute('data-a'); menu.remove();
                if (a === 'editar') entrarDibujo(id); else eliminarLinea(id);
            });
            var cerrar = function () { menu.remove(); document.removeEventListener('click', cerrar); };
            setTimeout(function () { document.addEventListener('click', cerrar); }, 0);
        }
        function eliminarLinea(id) {
            if (!confirm('¿Eliminar la línea (recorrido) de este proyecto? Los puntos se conservan.')) return;
            spinOn();
            oleoApi('/mapa/oleoductos/' + id + '/recorrido', 'POST', { recorrido: [] }).then(function (res) {
                spinOff();
                if (res && res.success && oleoMap[id]) {
                    oleoMap[id].data.recorrido = null;
                    oleoDibujar(oleoMap[id].data);
                    if (window.showToast) window.showToast('Línea eliminada (los puntos quedan).', 'success');
                }
            }).catch(function () { spinOff(); });
        }

        // Menú de CLIC DERECHO sobre una VELA (punto): eliminar ese punto del proyecto.
        function menuVela(ev, puntoId, puntoNombre) {
            if (ev.originalEvent) { ev.originalEvent.preventDefault(); }
            L.DomEvent.stop(ev);
            document.querySelectorAll('.mapa-ctx-menu').forEach(function (m) { m.remove(); });
            var menu = document.createElement('div'); menu.className = 'mapa-ctx-menu';
            menu.innerHTML =
                '<div class="mapa-ctx-title">' + esc(puntoNombre || 'Punto') + '</div>' +
                '<button type="button" class="mapa-ctx-item mapa-ctx-danger" data-a="del"><i class="material-icons">delete_outline</i>Eliminar punto</button>';
            var x = ev.originalEvent ? ev.originalEvent.clientX : 0, y = ev.originalEvent ? ev.originalEvent.clientY : 0;
            menu.style.left = Math.min(x, window.innerWidth - 200) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 100) + 'px';
            (document.fullscreenElement || document.body).appendChild(menu);
            menu.addEventListener('click', function (e2) {
                var b = e2.target.closest ? e2.target.closest('.mapa-ctx-item') : null; if (!b) return;
                menu.remove();
                if (b.getAttribute('data-a') === 'del') eliminarPunto(puntoId);
            });
            var cerrar = function () { menu.remove(); document.removeEventListener('click', cerrar); };
            setTimeout(function () { document.addEventListener('click', cerrar); }, 0);
        }
        // Elimina un punto de un proyecto (backend: DELETE /mapa/oleoductos/puntos/{id}).
        // Quita el punto de su proyecto en memoria, redibuja las velas y refresca la leyenda.
        function eliminarPunto(puntoId) {
            if (!confirm('¿Eliminar este punto del proyecto? No se puede deshacer.')) return;
            spinOn();
            oleoApi('/mapa/oleoductos/puntos/' + puntoId, 'DELETE').then(function (res) {
                spinOff();
                if (res && res.success) {
                    Object.keys(oleoMap).forEach(function (id) {
                        var pts = oleoMap[id].data.puntos || [];
                        var idx = -1;
                        for (var i = 0; i < pts.length; i++) { if (String(pts[i].id) === String(puntoId)) { idx = i; break; } }
                        if (idx > -1) { pts.splice(idx, 1); oleoDibujar(oleoMap[id].data); }
                    });
                    oleoRenderLista(); // refresca la leyenda
                    if (window.showToast) window.showToast('Punto eliminado.', 'success');
                } else if (window.showToast) window.showToast('No se pudo eliminar el punto.', 'error');
            }).catch(function () { spinOff(); if (window.showToast) window.showToast('No se pudo eliminar el punto.', 'error'); });
        }

        // ══════════════════════════════════════════════════════════════════════
        //  TABLA-LEYENDA (historial) + EXPORTAR IMAGEN
        //  - Leyenda transparente (abajo-izq): proyectos que TIENEN puntos.
        //  - Exportar: arma un PNG nítido del encuadre actual, a la escala de
        //    pantalla, en el tamaño de hoja elegido, con escala gráfica + leyenda.
        // ══════════════════════════════════════════════════════════════════════
        function proyectosConPuntos() {
            return Object.keys(oleoMap).map(function (id) { return oleoMap[id].data; })
                .filter(function (o) { return o.puntos && o.puntos.length > 0; });
        }

        // Tabla-leyenda en el mapa (se sincroniza desde oleoRenderLista).
        var LeyendaCtrl = L.Control.extend({
            options: { position: 'bottomleft' },
            onAdd: function () {
                var d = L.DomUtil.create('div', 'mapa-leyenda');
                d.id = 'mapaLeyenda'; d.style.display = 'none';
                L.DomEvent.disableClickPropagation(d);
                return d;
            }
        });
        map.addControl(new LeyendaCtrl());

        // Municipios activados, NUMERADOS (el número identifica cada uno; el color puede repetirse).
        function municipiosActivos() {
            var out = [];
            if (!muniData || (!estadosConMuni.size && !muniIndividuales.size)) return out;
            muniData.features.forEach(function (fe) {
                var e = fe.properties && fe.properties.estado, m = fe.properties && fe.properties.municipio;
                if (muniVisible(e, m)) out.push({ municipio: m, estado: e, color: colorMuni(m) });
            });
            out.forEach(function (mu, i) { mu.num = i + 1; });
            return out;
        }

        // Estado de plegado de la leyenda: toda la leyenda + los puntos de cada proyecto.
        var legendColapsada = false;
        var proyColapsados = {};      // id de proyecto → true si sus puntos están recogidos
        var legendClickBound = false;

        function actualizarLeyenda() {
            var d = document.getElementById('mapaLeyenda'); if (!d) return;
            var items = proyectosConPuntos();
            var munis = municipiosActivos();
            if (!items.length && !munis.length) { d.style.display = 'none'; d.innerHTML = ''; return; }
            d.style.display = 'block';

            // Delegación de clics (una sola vez, sobrevive a los re-render de innerHTML):
            // recoger/expandir TODA la leyenda, o los puntos de UN proyecto.
            if (!legendClickBound) {
                legendClickBound = true;
                d.addEventListener('click', function (e) {
                    var del = e.target.closest && e.target.closest('[data-ptdel]');
                    if (del) { e.stopPropagation(); eliminarPunto(del.getAttribute('data-ptdel')); return; }
                    if (e.target.closest && e.target.closest('[data-fold="all"]')) { legendColapsada = !legendColapsada; actualizarLeyenda(); return; }
                    var pr = e.target.closest && e.target.closest('[data-proy]');
                    if (pr) { var id = pr.getAttribute('data-proy'); proyColapsados[id] = !proyColapsados[id]; actualizarLeyenda(); }
                });
            }

            // Cabecera con botón para recoger/expandir toda la leyenda.
            var html = '<div class="mapa-leyenda-head">' +
                '<span class="mapa-leyenda-titulo">Leyenda</span>' +
                '<button type="button" class="mapa-leyenda-fold" data-fold="all" title="' + (legendColapsada ? 'Expandir leyenda' : 'Recoger leyenda') + '">' +
                    '<i class="material-icons">' + (legendColapsada ? 'expand_more' : 'expand_less') + '</i></button>' +
                '</div>';

            if (!legendColapsada) {
                html += '<div class="mapa-leyenda-body">';
                if (items.length) {
                    html += '<div class="mapa-leyenda-t">Proyectos</div>';
                    items.forEach(function (o) {
                        var col = !!proyColapsados[o.id];
                        html += '<div class="mapa-leyenda-row mapa-leyenda-proy" data-proy="' + o.id + '" title="' + (col ? 'Mostrar puntos' : 'Recoger puntos') + '">' +
                            '<span class="mapa-leyenda-dot" style="background:' + o.color + '"></span>' +
                            '<span class="mapa-leyenda-nom">' + esc(o.nombre) + '</span>' +
                            '<i class="material-icons mapa-leyenda-chevron">' + (col ? 'chevron_right' : 'expand_more') + '</i></div>';
                        if (!col) {
                            o.puntos.slice().sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); }).forEach(function (p) {
                                html += '<div class="mapa-leyenda-pt"><span class="mapa-leyenda-pt-n">' + esc(p.nombre || 'Punto') + '</span>' +
                                    '<span class="mapa-leyenda-pt-c">' + p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + '</span>' +
                                    (PUEDE_EDITAR ? '<button type="button" class="mapa-leyenda-pt-del" data-ptdel="' + p.id + '" title="Eliminar punto">&times;</button>' : '') + '</div>';
                            });
                        }
                    });
                }
                if (munis.length) {
                    html += '<div class="mapa-leyenda-t mapa-leyenda-t2">Municipios</div>';
                    // Con varios municipios se reparten en DOS columnas para que la leyenda no
                    // quede tan larga verticalmente (rellena de arriba a abajo: 1-N | N+1-…).
                    html += '<div class="mapa-leyenda-munis' + (munis.length > 6 ? ' dos-col' : '') + '">';
                    munis.forEach(function (mu) {
                        html += '<div class="mapa-leyenda-row">' +
                            '<span class="mapa-leyenda-num">' + mu.num + '</span>' +
                            '<span class="mapa-leyenda-nom">' + esc(nombreBonito(mu.municipio)) + '</span></div>';
                    });
                    html += '</div>';
                }
                html += '</div>';
            }
            d.innerHTML = html;
        }

        // Botón de descarga (arriba-izq, junto al buscador/globo/pantalla completa).
        var ExportarCtrl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('button', 'mapa-fit-btn');
                btn.type = 'button';
                btn.title = 'Descargar imagen del mapa';
                btn.innerHTML = '<i class="material-icons">photo_camera</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', abrirDialogoExport);
                return btn;
            }
        });
        map.addControl(new ExportarCtrl());

        // Botón LÁPIZ (arriba-izq): dibuja a mano el recorrido/curva de la tubería.
        var DibujarCtrl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('button', 'mapa-fit-btn');
                btn.type = 'button';
                btn.title = 'Dibujar tubería a mano (curva)';
                btn.innerHTML = '<i class="material-icons">gesture</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', iniciarDibujo);
                return btn;
            }
        });
        if (PUEDE_EDITAR) map.addControl(new DibujarCtrl()); // dibujar la línea: solo con permiso

        // Exportación con MARCO DE RECORTE: muestra un recuadro (aspecto de la hoja) para
        // cuadrar; se exporta EXACTAMENTE lo que quede dentro del marco.
        var EXPORT_MM = { carta: [279, 216], a4: [297, 210], a3: [420, 297], a2: [594, 420] };
        var expTamSel = 'carta', expOriSel = 'horizontal', expDetalleSel = false;
        function abrirDialogoExport() {
            cerrarDialogoExport();
            var frame = document.createElement('div'); frame.id = 'mapaExportFrame'; frame.className = 'mapa-export-frame';
            frame.innerHTML = '<span class="mapa-export-frame-lbl">Área a exportar — mueve/zoom el mapa para cuadrar</span>';
            el.appendChild(frame);
            var bar = document.createElement('div'); bar.id = 'mapaExportBar'; bar.className = 'mapa-export-bar';
            var opt = function (val, txt, sel) { return '<option value="' + val + '"' + (sel === val ? ' selected' : '') + '>' + txt + '</option>'; };
            bar.innerHTML =
                '<label class="mapa-export-field">Tamaño de hoja' +
                    '<select id="expTam">' +
                        opt('pantalla', 'Pantalla (tal cual)', expTamSel) +
                        opt('carta', 'Carta', expTamSel) + opt('a4', 'A4', expTamSel) +
                        opt('a3', 'A3', expTamSel) + opt('a2', 'A2', expTamSel) +
                    '</select>' +
                '</label>' +
                '<label class="mapa-export-field">Orientación' +
                    '<select id="expOri">' +
                        opt('horizontal', 'Horizontal', expOriSel) + opt('vertical', 'Vertical', expOriSel) +
                    '</select>' +
                '</label>' +
                '<label class="mapa-export-check" title="Sin marcar: la foto sale IGUAL a lo que ves en pantalla. Marcado: más nitidez y más detalle (más nombres).">' +
                    '<input type="checkbox" id="expDetalle"' + (expDetalleSel ? ' checked' : '') + '>' +
                    '<span>Más detalle</span>' +
                '</label>' +
                '<button type="button" class="mapa-dibujo-btn mapa-export-cancel">Cancelar</button>' +
                '<button type="button" class="mapa-dibujo-btn primary mapa-export-go">Descargar 4K</button>';
            (document.fullscreenElement || el).appendChild(bar);
            L.DomEvent.disableClickPropagation(bar); L.DomEvent.disableScrollPropagation(bar);
            var oriSel = bar.querySelector('#expOri');
            var sincOri = function () { oriSel.disabled = (expTamSel === 'pantalla'); }; // orientación no aplica en "Pantalla"
            bar.querySelector('#expTam').addEventListener('change', function () { expTamSel = this.value; sincOri(); ajustarFrameExport(); });
            oriSel.addEventListener('change', function () { expOriSel = this.value; ajustarFrameExport(); });
            bar.querySelector('#expDetalle').addEventListener('change', function () { expDetalleSel = this.checked; });
            sincOri();
            bar.querySelector('.mapa-export-cancel').addEventListener('click', cerrarDialogoExport);
            bar.querySelector('.mapa-export-go').addEventListener('click', function () { exportarImagen(expTamSel, expOriSel, expDetalleSel); });
            // Recoloca el marco si el usuario hace scroll o cambia el tamaño de la ventana.
            window.addEventListener('scroll', ajustarFrameExport, { passive: true });
            window.addEventListener('resize', ajustarFrameExport);
            ajustarFrameExport();
        }
        // Coloca el marco de recorte lo más GRANDE posible y CENTRADO en la parte del mapa que
        // el usuario ve en pantalla, respetando la proporción de la hoja elegida.
        function ajustarFrameExport() {
            var frame = document.getElementById('mapaExportFrame'); if (!frame) return;
            var rect = el.getBoundingClientRect();
            var vw = el.clientWidth;
            // Región VISIBLE del mapa dentro de la ventana (por si sobresale del borde inferior).
            var visTop = Math.max(0, -rect.top);
            var visBottom = Math.min(el.clientHeight, window.innerHeight - rect.top);
            var visH = Math.max(160, visBottom - visTop);
            // "Pantalla (tal cual)": el marco cubre TODA el área visible del mapa.
            if (expTamSel === 'pantalla') {
                frame.style.left = '0px'; frame.style.top = Math.round(visTop) + 'px';
                frame.style.width = Math.round(vw) + 'px'; frame.style.height = Math.round(visH) + 'px';
                return;
            }
            var mm = EXPORT_MM[expTamSel] || EXPORT_MM.carta;
            var aspect = (expOriSel === 'vertical') ? mm[1] / mm[0] : mm[0] / mm[1]; // ancho/alto
            var maxW = vw * 0.96;
            var maxH = visH - 92;          // deja hueco para la barra inferior y el rótulo
            var fw, fh;
            if (maxW / maxH > aspect) { fh = maxH; fw = fh * aspect; } else { fw = maxW; fh = fw / aspect; }
            frame.style.width = Math.round(fw) + 'px';
            frame.style.height = Math.round(fh) + 'px';
            frame.style.left = Math.round((vw - fw) / 2) + 'px';
            frame.style.top = Math.round(visTop + (visH - fh) / 2 - 14) + 'px'; // centrado en lo visible
        }
        function cerrarDialogoExport() {
            window.removeEventListener('scroll', ajustarFrameExport);
            window.removeEventListener('resize', ajustarFrameExport);
            var f = document.getElementById('mapaExportFrame'); if (f) f.remove();
            var b = document.getElementById('mapaExportBar'); if (b) b.remove();
        }

        function overlayExport(on) {
            var id = 'mapaExportLoad';
            var e = document.getElementById(id);
            if (on) {
                if (e) return;
                var o = document.createElement('div'); o.id = id; o.className = 'mapa-export-load';
                o.innerHTML = '<div class="mapa-export-spin"></div><div>Generando imagen…</div>';
                (document.fullscreenElement || document.body).appendChild(o);
            } else if (e) { e.remove(); }
        }

        // ── Helpers de dibujo en canvas ──
        function rrect(ctx, x, y, w, h, r) {
            ctx.beginPath();
            ctx.moveTo(x + r, y);
            ctx.arcTo(x + w, y, x + w, y + h, r);
            ctx.arcTo(x + w, y + h, x, y + h, r);
            ctx.arcTo(x, y + h, x, y, r);
            ctx.arcTo(x, y, x + w, y, r);
            ctx.closePath();
        }
        function numRedondo(num) { // 1/2/3/5 ×10ⁿ (como la escala de Leaflet)
            var pow10 = Math.pow(10, (Math.floor(num) + '').length - 1);
            var d = num / pow10;
            d = d >= 10 ? 10 : d >= 5 ? 5 : d >= 3 ? 3 : d >= 2 ? 2 : 1;
            return pow10 * d;
        }

        // Proyección por defecto (pantalla). En el export se pasa una que apunta al canvas 4K.
        function projPantalla(ll) { return map.latLngToContainerPoint(ll); }
        // Traza recursivamente anillos de lat/lng (polígonos de estados) con la proyección dada.
        function trazarAnillos(ctx, arr, proj) {
            if (!arr || !arr.length) return;
            if (arr[0] instanceof L.LatLng) {
                ctx.beginPath();
                for (var i = 0; i < arr.length; i++) {
                    var p = proj(arr[i]);
                    if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y);
                }
                ctx.stroke();
            } else {
                for (var j = 0; j < arr.length; j++) trazarAnillos(ctx, arr[j], proj);
            }
        }
        // Dibuja bordes de estados + líneas/puntos de proyectos (proyectados al canvas).
        // `k` escala el grosor; `proj` proyecta lat/lng → píxel del canvas.
        // Dibuja la VELA (pin de gota, igual que velaIcon) con la PUNTA en (x, y).
        function dibujarVela(ctx, x, y, k, color) {
            color = color || '#0067b1';
            var s = k; // proporcional a los rótulos (en pantalla el pin es 24×32)
            ctx.save();
            ctx.translate(x - 12 * s, y - 31 * s); ctx.scale(s, s); ctx.lineJoin = 'round';
            ctx.beginPath();
            ctx.moveTo(12, 0.6);
            ctx.bezierCurveTo(6, 0.6, 1.2, 5.4, 1.2, 11.4);
            ctx.bezierCurveTo(1.2, 19, 10.4, 29.8, 11.2, 30.8);
            ctx.bezierCurveTo(11.6, 31.3, 12.4, 31.3, 12.8, 30.8);
            ctx.bezierCurveTo(13.6, 29.8, 22.8, 19, 22.8, 11.4);
            ctx.bezierCurveTo(22.8, 5.4, 18, 0.6, 12, 0.6);
            ctx.closePath();
            ctx.fillStyle = color; ctx.fill();
            ctx.lineWidth = 1.8; ctx.strokeStyle = '#ffffff'; ctx.stroke();
            ctx.beginPath(); ctx.arc(12, 11.4, 4, 0, Math.PI * 2); ctx.fillStyle = '#ffffff'; ctx.fill();
            ctx.restore();
        }
        // Etiqueta FIJA de la vela (igual que en pantalla): cajita blanca a la derecha del pin,
        // con el nombre del PROYECTO (azul) y, si el punto está separado, el nombre del PUNTO debajo.
        function dibujarEtiquetaVela(ctx, tipX, tipY, proyecto, punto, k) {
            var padX = 8 * k, padY = 4 * k, fProj = Math.round(9.5 * k), fPt = Math.round(12 * k), lineGap = 2 * k;
            var proj = (proyecto || '').toUpperCase();
            ctx.save();
            ctx.font = '800 ' + fProj + 'px Arial, sans-serif'; var wProj = ctx.measureText(proj).width;
            var wPt = 0; if (punto) { ctx.font = '700 ' + fPt + 'px Arial, sans-serif'; wPt = ctx.measureText(punto).width; }
            var boxW = Math.max(wProj, wPt) + padX * 2;
            var boxH = padY * 2 + fProj + (punto ? (lineGap + fPt) : 0);
            var bx = tipX + 11 * k, by = (tipY - 24 * k) - boxH / 2; // a la derecha del pin, junto al bulbo
            rrect(ctx, bx, by, boxW, boxH, 6 * k);
            ctx.fillStyle = '#ffffff'; ctx.fill();
            ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
            var ty = by + padY + fProj;
            ctx.font = '800 ' + fProj + 'px Arial, sans-serif'; ctx.fillStyle = '#0067b1';
            ctx.fillText(proj, bx + padX, ty);
            if (punto) {
                ty += lineGap + fPt;
                ctx.font = '700 ' + fPt + 'px Arial, sans-serif'; ctx.fillStyle = '#0f172a';
                ctx.fillText(punto, bx + padX, ty);
            }
            ctx.restore();
        }
        function dibujarVectores(ctx, k, proj) {
            k = k || 1; proj = proj || projPantalla;
            ctx.save();
            ctx.strokeStyle = 'rgba(255,255,255,0.85)'; ctx.lineWidth = 1.4 * k; ctx.lineJoin = 'round';
            estados.eachLayer(function (layer) { if (layer.getLatLngs) trazarAnillos(ctx, layer.getLatLngs(), proj); });
            Object.keys(oleoMap).forEach(function (id) {
                var o = oleoMap[id].data;
                var pts = (o.puntos || []).slice().sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); });
                if (o.recorrido && o.recorrido.length >= 2) {
                    var line = o.recorrido.map(function (c) { return proj([c[0], c[1]]); });
                    ctx.lineJoin = 'round'; ctx.lineCap = 'round';
                    ctx.beginPath(); line.forEach(function (pt, i) { if (i === 0) ctx.moveTo(pt.x, pt.y); else ctx.lineTo(pt.x, pt.y); });
                    var pw = pesoTuberia();
                    ctx.strokeStyle = '#0a1620'; ctx.lineWidth = pw.borde * k; ctx.stroke();
                    ctx.strokeStyle = o.color; ctx.lineWidth = pw.cuerpo * k; ctx.stroke();
                    ctx.strokeStyle = aclararColor(o.color, 0.65); ctx.lineWidth = pw.brillo * k; ctx.stroke();
                }
                // Agrupación IGUAL que en pantalla (declutterVelas): las velas que se solapan
                // (<26px proporcional) se colapsan en UNA; separadas, cada una con su etiqueta.
                var THRESH = 38 * k, reps = []; // igual que en pantalla: velas solapadas se unen
                pts.forEach(function (p) {
                    var pt = proj([p.lat, p.lng]), rep = null;
                    for (var i = 0; i < reps.length; i++) {
                        var dx = reps[i].pt.x - pt.x, dy = reps[i].pt.y - pt.y;
                        if (dx * dx + dy * dy < THRESH * THRESH) { rep = reps[i]; break; }
                    }
                    if (rep) rep.count++; else reps.push({ pt: pt, count: 1, nombre: p.nombre });
                });
                reps.forEach(function (r) { dibujarVela(ctx, r.pt.x, r.pt.y, k, '#0067b1'); }); // pines primero
                reps.forEach(function (r) { // etiqueta: vela agrupada → solo proyecto; punto suelto → proyecto + punto
                    dibujarEtiquetaVela(ctx, r.pt.x, r.pt.y, o.nombre, r.count > 1 ? null : (r.nombre || 'Punto'), k);
                });
            });
            ctx.restore();
        }

        // Escala gráfica (abajo-derecha) tipo regla.
        function recortarTexto(ctx, txt, maxW) {
            if (ctx.measureText(txt).width <= maxW) return txt;
            var t = txt;
            while (t.length > 1 && ctx.measureText(t + '…').width > maxW) t = t.slice(0, -1);
            return t + '…';
        }
        function dibujarEscala(ctx, rightX, bottomY, mppx, k) {
            k = k || 1;
            var maxPx = 200 * k, m = numRedondo(maxPx * mppx), px = m / mppx;
            var label = m >= 1000 ? (m / 1000) + ' km' : m + ' m';
            var x = rightX - px, y = bottomY;
            ctx.save();
            ctx.strokeStyle = 'rgba(0,0,0,0.55)'; ctx.lineWidth = 5 * k; ctx.lineCap = 'butt';
            ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + px, y); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x, y - 9 * k); ctx.lineTo(x, y + 3 * k); ctx.moveTo(x + px, y - 9 * k); ctx.lineTo(x + px, y + 3 * k); ctx.stroke();
            ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 3 * k;
            ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + px, y); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x, y - 8 * k); ctx.lineTo(x, y + 2 * k); ctx.moveTo(x + px, y - 8 * k); ctx.lineTo(x + px, y + 2 * k); ctx.stroke();
            ctx.font = 'bold ' + Math.round(15 * k) + 'px Arial, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
            ctx.lineWidth = 3 * k; ctx.strokeStyle = 'rgba(0,0,0,0.75)'; ctx.strokeText(label, x + px / 2, y - 12 * k);
            ctx.fillStyle = '#ffffff'; ctx.fillText(label, x + px / 2, y - 12 * k);
            ctx.restore();
        }

        // Brújula (Norte) — MISMO diseño que la de la página: aguja norte NEGRA / sur GRIS,
        // punto central y N/S/E/O, con halo blanco para resaltar sobre el satélite. `cx,cy` = CENTRO.
        function dibujarNorte(ctx, cx, cy, k) {
            k = k || 1;
            ctx.save();
            // La página muestra la brújula a 96px (viewBox 48 → escala 2×). Igualamos ese tamaño.
            ctx.translate(cx, cy); ctx.scale(k * 2, k * 2); ctx.lineJoin = 'round';
            // viewBox 48×48 centrado en (24,24): restamos 24 para centrar en el origen.
            var tri = function (pts, fill) {
                ctx.beginPath();
                pts.forEach(function (p, i) { var x = p[0] - 24, y = p[1] - 24; i ? ctx.lineTo(x, y) : ctx.moveTo(x, y); });
                ctx.closePath();
                ctx.lineWidth = 2.4; ctx.strokeStyle = 'rgba(255,255,255,0.95)'; ctx.stroke(); // halo
                ctx.fillStyle = fill; ctx.fill();
            };
            tri([[24, 13], [28, 24], [20, 24]], '#0f172a'); // aguja norte (negra)
            tri([[24, 35], [20, 24], [28, 24]], '#64748b'); // aguja sur (gris)
            ctx.beginPath(); ctx.arc(0, 0, 2, 0, Math.PI * 2);
            ctx.lineWidth = 2; ctx.strokeStyle = 'rgba(255,255,255,0.95)'; ctx.stroke();
            ctx.fillStyle = '#0f172a'; ctx.fill();
            ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
            var letra = function (t, x, y, size) {
                ctx.font = '600 ' + size + 'px Arial, sans-serif';
                ctx.lineWidth = 2.6; ctx.strokeStyle = 'rgba(255,255,255,0.95)';
                ctx.strokeText(t, x - 24, y - 24);
                ctx.fillStyle = '#0f172a'; ctx.fillText(t, x - 24, y - 24);
            };
            letra('N', 24, 9.5, 10); letra('S', 24, 47, 9); letra('E', 45, 27, 9); letra('O', 4, 27, 9);
            ctx.restore();
        }

        // Tabla-leyenda (historial) sobre el canvas: proyectos + sus puntos con coordenadas,
        // y los municipios activos con su color. `k` = escala según el tamaño de hoja.
        function dibujarLeyendaCanvas(ctx, x, y, fechaTxt, k, bottomY) {
            k = k || 1;
            var items = proyectosConPuntos(), munis = municipiosActivos();
            if (!items.length && !munis.length) return;
            var pad = 12 * k, sw = 12 * k, gap = 8 * k;
            var fT = Math.round(13 * k), fRow = Math.round(13 * k), fPt = Math.round(11 * k), fDate = Math.round(10 * k);
            var rowH = 20 * k, ptH = 15 * k, titleH = 24 * k;
            // ── Sección PROYECTOS (una columna) ──
            var filas = [];
            if (items.length) {
                filas.push({ t: 'titulo', txt: 'PROYECTOS', fecha: fechaTxt });
                items.forEach(function (o) {
                    filas.push({ t: 'row', color: o.color, txt: o.nombre });
                    o.puntos.slice().sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); }).forEach(function (p) {
                        filas.push({ t: 'pt', nom: (p.nombre || 'Punto'), coord: p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) });
                    });
                });
            }
            // ── Sección MUNICIPIOS: DOS columnas cuando son >6 (igual que en la página) ──
            var colGap = 16 * k;
            var muniCols = munis.length > 6 ? 2 : 1;
            var muniRowsPerCol = Math.ceil(munis.length / muniCols);
            var muniColW = 0;
            munis.forEach(function (mu) {
                ctx.font = '600 ' + fRow + 'px Arial, sans-serif';
                muniColW = Math.max(muniColW, sw + gap + ctx.measureText(nombreBonito(mu.municipio)).width + 8 * k);
            });
            var muniBlockW = muniCols * muniColW + (muniCols - 1) * colGap;

            // ── Ancho total (con tope) ──
            var W = 200 * k, cap = 540 * k;
            filas.forEach(function (fi) {
                if (fi.t === 'row') { ctx.font = '600 ' + fRow + 'px Arial, sans-serif'; W = Math.max(W, pad * 2 + sw + gap + ctx.measureText(fi.txt).width); }
                else if (fi.t === 'pt') { ctx.font = fPt + 'px Arial, sans-serif'; W = Math.max(W, pad * 2 + 16 * k + ctx.measureText(fi.nom + '    ' + fi.coord).width); }
                else { ctx.font = 'bold ' + fT + 'px Arial, sans-serif'; W = Math.max(W, pad * 2 + ctx.measureText(fi.txt).width + 46 * k); }
            });
            if (munis.length) W = Math.max(W, pad * 2 + muniBlockW);
            W = Math.min(W, cap);

            // ── Alto total ──
            var H = pad * 2;
            filas.forEach(function (fi) { H += (fi.t === 'titulo') ? titleH : (fi.t === 'pt' ? ptH : rowH); });
            if (munis.length) H += titleH + muniRowsPerCol * rowH;
            if (bottomY != null) y = bottomY - H; // anclar abajo (modo "Pantalla")

            // ── Fondo ──
            ctx.save();
            rrect(ctx, x, y, W, H, 12 * k);
            ctx.fillStyle = 'rgba(15,23,42,0.62)'; ctx.fill();
            ctx.strokeStyle = 'rgba(255,255,255,0.28)'; ctx.lineWidth = 1 * k; ctx.stroke();
            ctx.textBaseline = 'alphabetic';

            // Dibuja un municipio (círculo blanco con número + nombre) en (cx, yy).
            var muniRow = function (cx, yy, num, txt, maxW) {
                var ncx = cx + sw / 2, ncy = yy + rowH / 2 - 1 * k;
                ctx.beginPath(); ctx.arc(ncx, ncy, sw / 2 + 1 * k, 0, Math.PI * 2); ctx.fillStyle = '#ffffff'; ctx.fill();
                ctx.strokeStyle = '#0f172a'; ctx.lineWidth = 1.2 * k; ctx.stroke();
                ctx.fillStyle = '#0f172a'; ctx.font = 'bold ' + Math.round(10 * k) + 'px Arial, sans-serif';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(String(num), ncx, ncy + 0.5 * k); ctx.textBaseline = 'alphabetic';
                ctx.textAlign = 'left'; ctx.fillStyle = '#fff'; ctx.font = '600 ' + fRow + 'px Arial, sans-serif';
                ctx.fillText(recortarTexto(ctx, txt, maxW - sw - gap), cx + sw + gap, yy + fRow + (rowH - fRow) / 2 - 2 * k);
            };

            // ── PROYECTOS (una columna) ──
            var yy = y + pad;
            filas.forEach(function (fi) {
                if (fi.t === 'titulo') {
                    ctx.textAlign = 'left'; ctx.fillStyle = '#fff'; ctx.font = 'bold ' + fT + 'px Arial, sans-serif';
                    ctx.fillText(fi.txt, x + pad, yy + fT);
                    if (fi.fecha) { ctx.textAlign = 'right'; ctx.font = fDate + 'px Arial, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,0.75)'; ctx.fillText(fi.fecha, x + W - pad, yy + fT - 1); }
                    yy += titleH;
                } else if (fi.t === 'row') {
                    ctx.fillStyle = fi.color; rrect(ctx, x + pad, yy + (rowH - sw) / 2 - 1 * k, sw, sw, 3 * k); ctx.fill();
                    ctx.strokeStyle = 'rgba(255,255,255,0.6)'; ctx.lineWidth = 1 * k; ctx.stroke();
                    ctx.textAlign = 'left'; ctx.fillStyle = '#fff'; ctx.font = '600 ' + fRow + 'px Arial, sans-serif';
                    ctx.fillText(recortarTexto(ctx, fi.txt, W - pad * 2 - sw - gap), x + pad + sw + gap, yy + fRow + (rowH - fRow) / 2 - 2 * k);
                    yy += rowH;
                } else {
                    ctx.font = fPt + 'px Arial, sans-serif'; ctx.fillStyle = 'rgba(255,255,255,0.85)';
                    var coordW = fi.coord ? ctx.measureText(fi.coord).width + gap : 0;
                    ctx.textAlign = 'left'; ctx.fillText(recortarTexto(ctx, fi.nom, W - pad * 2 - 16 * k - coordW), x + pad + 16 * k, yy + fPt);
                    if (fi.coord) { ctx.textAlign = 'right'; ctx.fillText(fi.coord, x + W - pad, yy + fPt); }
                    yy += ptH;
                }
            });

            // ── MUNICIPIOS (título + una/dos columnas, rellena de arriba a abajo) ──
            if (munis.length) {
                ctx.textAlign = 'left'; ctx.fillStyle = '#fff'; ctx.font = 'bold ' + fT + 'px Arial, sans-serif';
                ctx.fillText('MUNICIPIOS', x + pad, yy + fT);
                yy += titleH;
                var colW = (W - pad * 2 - (muniCols - 1) * colGap) / muniCols;
                munis.forEach(function (mu, i) {
                    var col = Math.floor(i / muniRowsPerCol), rowInCol = i % muniRowsPerCol;
                    muniRow(x + pad + col * (colW + colGap), yy + rowInCol * rowH, mu.num, nombreBonito(mu.municipio), colW);
                });
            }
            ctx.restore();
        }

        // Créditos sobre el canvas (abajo-izq), escalados.
        // Créditos en su CAJITA BLANCA (igual a .mapa-creditos de la página): etiqueta en negrita
        // + texto normal, con envoltura a un ancho máximo. `bottomY` = borde inferior de la caja.
        // Devuelve la Y del TOPE de la caja (para apilar la leyenda encima en modo "Pantalla").
        function dibujarCreditos(ctx, x, bottomY, k) {
            k = k || 1;
            var padX = 10 * k, padY = 6 * k, fs = Math.round(11 * k), lh = Math.round(fs * 1.35), maxTW = 440 * k;
            var fontB = '800 ' + fs + 'px Arial, sans-serif', fontN = fs + 'px Arial, sans-serif';
            var entradas = [
                ['ELABORADO POR:', ' Fernando Sánchez | Ingeniero Industrial'],
                ['FUENTE CARTOGRÁFICA:', ' Delimitación Municipal, Instituto Geográfico de Venezuela Simón Bolívar (IGVSB). Cartografía Oficial 2016.']
            ];
            // Construye las líneas (segmentos negrita/normal) envolviendo al ancho máximo.
            var lineas = [], maxLineW = 0;
            entradas.forEach(function (e) {
                ctx.font = fontB; var segs = [{ t: e[0], bold: true }], curW = ctx.measureText(e[0]).width;
                e[1].split(' ').forEach(function (w) {
                    if (!w) return;
                    ctx.font = fontN; var ww = ctx.measureText(' ' + w).width;
                    if (curW + ww > maxTW && segs.length) {
                        lineas.push(segs); maxLineW = Math.max(maxLineW, curW);
                        curW = ctx.measureText(w).width; segs = [{ t: w, bold: false }];
                    } else { segs.push({ t: ' ' + w, bold: false }); curW += ww; }
                });
                lineas.push(segs); maxLineW = Math.max(maxLineW, curW);
            });
            var boxW = maxLineW + padX * 2, boxH = lineas.length * lh + padY * 2, y = bottomY - boxH;
            ctx.save();
            rrect(ctx, x, y, boxW, boxH, 6 * k);
            ctx.fillStyle = 'rgba(255,255,255,0.9)'; ctx.fill();
            ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
            var ty = y + padY + fs;
            lineas.forEach(function (segs) {
                var tx = x + padX;
                segs.forEach(function (s) {
                    ctx.font = s.bold ? fontB : fontN; ctx.fillStyle = '#0f172a';
                    ctx.fillText(s.t, tx, ty); tx += ctx.measureText(s.t).width;
                });
                ty += lh;
            });
            ctx.restore();
            return y; // tope de la caja
        }

        // Descarga una imagen (crossOrigin) — resuelve con la img, o null si falla.
        // Reintenta un par de veces (con cache-bust) para que no queden teselas sin cargar.
        function cargarImg(url, reintentos) {
            reintentos = (reintentos == null) ? 2 : reintentos;
            return new Promise(function (res) {
                var img = new Image(); img.crossOrigin = 'anonymous';
                img.onload = function () { res(img); };
                img.onerror = function () {
                    if (reintentos > 0) cargarImg(url + (url.indexOf('?') > -1 ? '&' : '?') + 'rt=' + reintentos, reintentos - 1).then(res);
                    else res(null);
                };
                img.src = url;
            });
        }
        // URL de tesela: 'sat' = Esri World Imagery; 'lbl' = etiquetas de Google.
        function tileURL(z, x, y, tipo) {
            var n = Math.pow(2, z); x = ((x % n) + n) % n; // envolver longitud
            if (tipo === 'lbl') return 'https://mt' + (Math.abs(x + y) % 4) + '.google.com/vt/lyrs=h&x=' + x + '&y=' + y + '&z=' + z;
            return 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/' + z + '/' + y + '/' + x;
        }
        // Rellena anillos de polígono (lat/lng) con color — municipios resaltados en la foto.
        function rellenarAnillos(ctx, arr, col, k, proj) {
            if (!arr || !arr.length) return;
            if (arr[0] instanceof L.LatLng) {
                ctx.beginPath();
                for (var i = 0; i < arr.length; i++) { var p = proj(arr[i]); if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y); }
                ctx.closePath();
                ctx.globalAlpha = 0.42; ctx.fillStyle = col; ctx.fill();
                ctx.globalAlpha = 0.95; ctx.strokeStyle = col; ctx.lineWidth = 1.4 * k; ctx.stroke();
                ctx.globalAlpha = 1;
            } else { for (var j = 0; j < arr.length; j++) rellenarAnillos(ctx, arr[j], col, k, proj); }
        }
        // Municipios ACTIVOS resaltados (color pleno) + su NÚMERO (círculo blanco) en la foto.
        function dibujarMunicipios(ctx, k, proj) {
            if (!muniEstado) return;
            proj = proj || projPantalla;
            ctx.save(); ctx.lineJoin = 'round';
            muniEstado.eachLayer(function (layer) {
                var m = layer.feature && layer.feature.properties && layer.feature.properties.municipio;
                if (layer.getLatLngs) rellenarAnillos(ctx, layer.getLatLngs(), colorMuni(m), k, proj);
            });
            var byKey = {};
            municipiosActivos().forEach(function (mu) { byKey[muniKey(mu.estado, mu.municipio)] = mu.num; });
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            muniEstado.eachLayer(function (layer) {
                var e = layer.feature.properties.estado, m = layer.feature.properties.municipio;
                var num = byKey[muniKey(e, m)];
                if (!num || !layer.getBounds) return;
                var p = proj(layer.getBounds().getCenter()), r = 10 * k;
                // Círculo transparente con borde blanco y número blanco (con halo oscuro para legibilidad).
                ctx.beginPath(); ctx.arc(p.x, p.y, r, 0, Math.PI * 2);
                ctx.lineWidth = 3 * k; ctx.strokeStyle = 'rgba(0,0,0,0.5)'; ctx.stroke();
                ctx.lineWidth = 1.6 * k; ctx.strokeStyle = '#ffffff'; ctx.stroke();
                ctx.font = 'bold ' + Math.round(11 * k) + 'px Arial, sans-serif';
                ctx.lineWidth = 3 * k; ctx.strokeStyle = 'rgba(0,0,0,0.75)'; ctx.strokeText(String(num), p.x, p.y);
                ctx.fillStyle = '#ffffff'; ctx.fillText(String(num), p.x, p.y);
            });
            ctx.restore();
        }

        // Exporta la imagen en 4K NÍTIDA con EXACTAMENTE lo que quedó dentro del marco.
        // Descarga las teselas DIRECTAMENTE (no redimensiona el mapa) → todas cargan; luego
        // compone municipios resaltados + estados + tuberías/velas + historial + escala + brújula + créditos.
        function exportarImagen(tam, ori, detalle) {
            var LONG = 3840; // lado largo = 4K
            var Z0 = map.getZoom();
            var frame = document.getElementById('mapaExportFrame');
            var cr = el.getBoundingClientRect();
            var fr = frame ? frame.getBoundingClientRect() : cr;
            var fw = fr.width, fh = fr.height;
            // Límites geográficos EXACTOS del marco (lo que se ve dentro del recuadro).
            var b = L.latLngBounds(
                map.containerPointToLatLng([fr.left - cr.left, fr.top - cr.top]),
                map.containerPointToLatLng([fr.right - cr.left, fr.bottom - cr.top])
            );

            var pantalla = (tam === 'pantalla'), Pw, Ph, k;
            if (pantalla) {
                // "Tal cual pantalla": misma PROPORCIÓN que el área visible; los rótulos/leyenda se
                // dibujan proporcionales a la pantalla (k = ampliación respecto a los px de pantalla).
                if (fw >= fh) { Pw = LONG; Ph = Math.round(LONG * fh / fw); }
                else { Ph = LONG; Pw = Math.round(LONG * fw / fh); }
                k = Pw / fw;
            } else {
                var mm = EXPORT_MM[tam] || EXPORT_MM.carta;
                var cortoPx = Math.round(LONG * mm[1] / mm[0]);
                Pw = (ori === 'vertical') ? cortoPx : LONG;
                Ph = (ori === 'vertical') ? LONG : cortoPx;
                k = Math.max(Pw, Ph) / 1650; // escala de rótulos/trazos
            }
            cerrarDialogoExport();
            overlayExport(true);

            // Zoom ENTERO de las teselas:
            //  · "Pantalla" o SIN "Más detalle": mismo zoom que la PANTALLA (round de Z0) → la foto
            //    muestra EXACTAMENTE los mismos nombres/detalles que ves, ni más ni menos.
            //  · CON "Más detalle" (solo hojas): sube el zoom para 4K más nítido (más nombres).
            var zMax = Math.min(17, Math.max(0, Math.ceil(Z0 + Math.log(Pw / fw) / Math.LN2)));
            var z = (detalle && !pantalla) ? zMax : Math.min(17, Math.max(0, Math.round(Z0)));
            var pTL = map.project(b.getNorthWest(), z), pBR = map.project(b.getSouthEast(), z);
            var spanX = pBR.x - pTL.x || 1, scale = Pw / spanX;
            var proj4k = function (ll) {
                var wp = map.project(ll instanceof L.LatLng ? ll : L.latLng(ll[0], ll[1]), z);
                return { x: (wp.x - pTL.x) * scale, y: (wp.y - pTL.y) * scale };
            };
            var TS = 256, n = Math.pow(2, z);
            var tx0 = Math.floor(pTL.x / TS), tx1 = Math.floor((pBR.x - 0.001) / TS);
            var ty0 = Math.floor(pTL.y / TS), ty1 = Math.floor((pBR.y - 0.001) / TS);

            var canvas = document.createElement('canvas'); canvas.width = Pw; canvas.height = Ph;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#0b1a2b'; ctx.fillRect(0, 0, Pw, Ph);

            var pintarCapa = function (tipo) {
                var tasks = [];
                for (var tx = tx0; tx <= tx1; tx++) {
                    for (var ty = ty0; ty <= ty1; ty++) {
                        if (ty < 0 || ty >= n) continue;
                        (function (tx, ty) {
                            var dx = (tx * TS - pTL.x) * scale, dy = (ty * TS - pTL.y) * scale, ds = TS * scale + 1;
                            tasks.push(cargarImg(tileURL(z, tx, ty, tipo)).then(function (img) { if (img) { try { ctx.drawImage(img, dx, dy, ds, ds); } catch (e) {} } }));
                        })(tx, ty);
                    }
                }
                return Promise.all(tasks);
            };

            pintarCapa('sat').then(function () { return pintarCapa('lbl'); }).then(function () {
                dibujarMunicipios(ctx, k, proj4k);
                dibujarVectores(ctx, k, proj4k);
                var outMppx = 156543.03392 * Math.cos(b.getCenter().lat * Math.PI / 180) / Math.pow(2, z) / scale;
                var fecha = new Date().toLocaleDateString('es-VE');
                // Créditos en su cajita abajo-izquierda (devuelve su tope). En "Pantalla" la leyenda
                // se apila ENCIMA de esa caja (igual que en la pantalla); en hojas va arriba-izquierda.
                var credTop = dibujarCreditos(ctx, 26 * k, Ph - 22 * k, k);
                dibujarLeyendaCanvas(ctx, 26 * k, 26 * k, fecha, k, pantalla ? (credTop - 12 * k) : null);
                dibujarEscala(ctx, Pw - 34 * k, Ph - 40 * k, outMppx, k);
                dibujarNorte(ctx, Pw - 74 * k, Ph - 116 * k, k);
                canvas.toBlob(function (blob) {
                    overlayExport(false);
                    if (!blob) { if (window.showToast) window.showToast('No se pudo generar la imagen.', 'error'); return; }
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'mapa_' + tam + '_' + ori + '_4k_' + new Date().toISOString().slice(0, 10) + '.png';
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
                    if (window.showToast) window.showToast('Imagen 4K descargada (' + tam.toUpperCase() + ', ' + ori + ').', 'success');
                }, 'image/png');
            }).catch(function () {
                overlayExport(false);
                if (window.showToast) window.showToast('No se pudo exportar el mapa.', 'error');
            });
        }

        // Cargar los oleoductos existentes y dibujarlos.
        oleoApi('/mapa/oleoductos').then(function (res) {
            (res && res.oleoductos ? res.oleoductos : []).forEach(oleoDibujar);
            oleoRenderLista();
            // Recalcular las etiquetas cuando la escala/vista ya se asentó (el setView inicial no
            // dispara moveend, y la barra de escala se renderiza un instante después).
            setTimeout(declutterVelas, 300);
            setTimeout(declutterVelas, 900);
        }).catch(function () {});

        // Tras insertar el contenedor por SPA, Leaflet puede calcular mal el tamaño;
        // invalidar en el siguiente tick asegura que las teselas llenen el área.
        setTimeout(function () { map.invalidateSize(); }, 60);

        // Cuando existan equipos con lat/lng se agregarán marcadores aquí, p.ej:
        //   L.marker([lat, lng]).addTo(map).bindPopup(nombreEquipo);
    }

    // Punto de entrada: se llama en carga directa y en cada navegación SPA.
    function initMapa() {
        var el = document.getElementById('mapa-leaflet');
        if (!el || el._leaflet_id) return; // no estamos en /mapa (o ya inicializado)
        ensureLeaflet()
            .then(function () { buildMap(el); })
            .catch(function () { /* sin internet: no se puede cargar Leaflet */ });
    }

    // Copia texto al portapapeles (Clipboard API con fallback a execCommand).
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            try {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve() : reject();
            } catch (err) { reject(err); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMapa);
    } else {
        initMapa();
    }
    window.addEventListener('spa:contentLoaded', initMapa);
})();
