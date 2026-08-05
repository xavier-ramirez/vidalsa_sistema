// ═══════════════════════════════════════════════════════════════════════════
// Módulo "Mapa Satelital" — compatible con navegación SPA.
//
// Este archivo se carga UNA sola vez en el layout y se engancha a DOMContentLoaded +
// spa:contentLoaded para montar el mapa cuando aparece el contenedor #mapa-leaflet.
// (La SPA de navegacion.js SÍ re-ejecuta los <script> inline del contenido, clonándolos
// antes de disparar spa:contentLoaded — de ahí salen window.mapaFrentes y
// window.mapaPuedeEditar, que la vista define en un <script> propio.) Leaflet + el geocoder se cargan de forma diferida
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
        // z 466: encima del satélite, de los estados (400), municipios (462) y de la TUBERÍA (465),
        // para que los nombres de lugares/carreteras nunca queden tapados por esas capas. No recibe
        // eventos (pointerEvents:none), así que la tubería debajo sigue recibiendo el hover/clic.
        map.getPane('labelsPane').style.zIndex = 466;
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
        // Color base por nombre (hash estable). Es el fallback y el color de arranque de un
        // municipio SIN vecinos coloreados; el coloreado por adyacencia lo puede sustituir.
        function colorHash(nombre) {
            var h = 0, s = String(nombre || '');
            for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0;
            return PALETA_MUNI[h % PALETA_MUNI.length];
        }
        // Coloreado tipo MAPA: municipios que se tocan nunca comparten color ni uno parecido.
        // _coloresMuni { muniKey(estado,municipio) → '#hex' } se calcula una vez
        // (construirColoresMuni) al cargar el geojson; hasta entonces colorMuni cae al hash.
        // Todos los sitios que pintan municipios (capa, leyenda, export) usan colorMuni, así
        // que quedan coherentes. Se indexa por municipio ÚNICO (estado+municipio), NO por
        // nombre: hay 27 nombres repetidos en varios estados (Sucre, Bolívar, Paéz…) que,
        // fusionados, inflaban el grafo de vecindad. Por eso colorMuni recibe también el estado.
        var _coloresMuni = null;
        function colorMuni(nombre, estado) {
            return (_coloresMuni && _coloresMuni[muniKey(estado, nombre)]) || colorHash(nombre);
        }
        function hexToRgb(h) {
            h = String(h).replace('#', '');
            return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
        }
        // Distancia perceptual entre colores (aprox. "redmean"): 0 = idéntico, mayor = más
        // distinto. Sirve para elegir el color MÁS diferente al de los vecinos.
        function colorDist(a, b) {
            var rm = (a[0] + b[0]) / 2, dr = a[0] - b[0], dg = a[1] - b[1], db = a[2] - b[2];
            return (2 + rm / 256) * dr * dr + 4 * dg * dg + (2 + (255 - rm) / 256) * db * db;
        }
        // Construye _coloresMuni: grafo de adyacencia (vértices de borde compartidos) + coloreo
        // voraz Welsh-Powell que, para cada municipio, toma el color de la paleta más distinto a
        // los de sus vecinos ya coloreados. Determinista (mismo resultado en cada carga).
        function construirColoresMuni(features) {
            if (_coloresMuni || !features || !features.length) return;
            var GRID = 1e4;    // 4 decimales (~11 m): detecta el vértice compartido en un borde común
            var puntos = {};   // 'x_y' → { claveMuni: 1 }  municipios con un vértice en ese punto
            var vecinos = {};  // claveMuni → { claveVecina: 1 }
            var nombreDe = {}; // claveMuni → nombre (para el color de arranque por hash)
            features.forEach(function (f) {
                var p = f.properties || {}, m = p.municipio;
                if (!m) return;
                var clave = muniKey(p.estado, m); // ÚNICO por estado+municipio (evita fusionar homónimos)
                nombreDe[clave] = m;
                if (!vecinos[clave]) vecinos[clave] = {};
                forEachCoord(f.geometry, function (x, y) {
                    var key = Math.round(x * GRID) + '_' + Math.round(y * GRID);
                    (puntos[key] || (puntos[key] = {}))[clave] = 1;
                });
            });
            // Un punto compartido por >1 municipio ⇒ esos municipios son vecinos entre sí.
            Object.keys(puntos).forEach(function (k) {
                var ns = Object.keys(puntos[k]);
                if (ns.length < 2) return;
                for (var i = 0; i < ns.length; i++)
                    for (var j = i + 1; j < ns.length; j++) {
                        vecinos[ns[i]][ns[j]] = 1; vecinos[ns[j]][ns[i]] = 1;
                    }
            });
            // Welsh-Powell: procesar los de MÁS vecinos primero (desempate por clave = estable).
            var nodos = Object.keys(vecinos).sort(function (a, b) {
                return Object.keys(vecinos[b]).length - Object.keys(vecinos[a]).length ||
                       (a < b ? -1 : a > b ? 1 : 0);
            });
            var rgb = PALETA_MUNI.map(hexToRgb), asign = {};
            nodos.forEach(function (n) {
                var usados = [];
                Object.keys(vecinos[n]).forEach(function (v) { if (asign[v]) usados.push(hexToRgb(asign[v])); });
                if (!usados.length) { asign[n] = colorHash(nombreDe[n]); return; } // sin restricción → color natural
                var mejorIdx = 0, mejorDist = -1;
                for (var c = 0; c < rgb.length; c++) {
                    var dmin = Infinity;
                    for (var u = 0; u < usados.length; u++) dmin = Math.min(dmin, colorDist(rgb[c], usados[u]));
                    if (dmin > mejorDist) { mejorDist = dmin; mejorIdx = c; }
                }
                asign[n] = PALETA_MUNI[mejorIdx];
            });
            _coloresMuni = asign;
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

        // Pane SOLO para los NÚMEROS de municipio, justo encima del de los polígonos.
        // Sin él, número y polígono compartían pane y competían por z-index: Leaflet le pone
        // al SVG de los polígonos un z-index fijo de 200 y a cada marcador uno igual a su
        // posición VERTICAL, así que los números de la mitad de arriba (z < 200) quedaban
        // DEBAJO del polígono y no se podían agarrar para moverlos — unos sí y otros no,
        // según dónde cayeran. Con pane propio el orden ya no depende de la altura.
        map.createPane('muniNumPane');
        map.getPane('muniNumPane').style.zIndex = 463;

        // Panes de la CAPA PETROLERA: encima del satélite y de los estados (400), y DEBAJO de los
        // municipios (462) y de la tubería (465), que son la información propia de la empresa y
        // deben quedar siempre legibles.
        // Son DOS panes para que el orden NO dependa de en qué orden se enciendan las capas: las
        // áreas de la Faja son el fondo y los bloques van siempre encima.
        map.createPane('fajaPane');
        map.getPane('fajaPane').style.zIndex = 458;
        map.createPane('bloquesPane');
        map.getPane('bloquesPane').style.zIndex = 459;
        // El CONTORNO de las divisiones de la Faja va aparte y MÁS ALTO que las etiquetas de
        // Google (labelsPane, 466): una carretera pintada encima del borde lo partía y dejaba
        // trozos sin línea. El relleno se queda abajo (fajaPane) para no tapar los nombres.
        // Sin eventos: el hover/clic derecho lo siguen recibiendo los bloques de debajo.
        map.createPane('fajaBordePane');
        map.getPane('fajaBordePane').style.zIndex = 467;
        map.getPane('fajaBordePane').style.pointerEvents = 'none';

        // Pane propio para la TUBERÍA, POR ENCIMA de los estados (overlayPane, z 400) y de los
        // municipios (muniIntPane, z 462). Esos polígonos hacían bringToFront() al pasar el mouse
        // y quedaban encima de la línea, robándole el hover/clic → no salía la longitud. Aquí la
        // tubería queda siempre encima de ellos, y debajo de las velas (markerPane, z 600).
        map.createPane('tuberiaPane');
        map.getPane('tuberiaPane').style.zIndex = 465;
        // Las etiquetas de las velas y sus líneas van por encima de los pines (markerPane, z 600)
        // y por debajo de los popups, igual que estaban los tooltips.
        map.createPane('etqPane');
        map.getPane('etqPane').style.zIndex = 655;
        map.getPane('etqPane').style.pointerEvents = 'none';
        var etqCapa = L.layerGroup().addTo(map); // cada capa de dentro fija su propio pane

        var muniData = null; // GeoJSON completo (para filtrar por estado)
        // Capa "municipios de los estados activados por clic derecho" — COLORES + nombre al pasar el mouse.
        var muniEstado = L.geoJSON(null, {
            pane: 'muniIntPane',
            style: function (f) {
                var c = colorMuni(f && f.properties && f.properties.municipio, f && f.properties && f.properties.estado);
                // Relleno MÁS transparente para ver el satélite debajo.
                return { color: c, weight: 1, opacity: 0.85, fill: true, fillColor: c, fillOpacity: 0.12 };
            },
            onEachFeature: function (f, layer) {
                var m = (f.properties && f.properties.municipio) || 'Municipio';
                var e = (f.properties && f.properties.estado) || '';
                layer.bindTooltip('<b>Municipio ' + esc(nombreBonito(m)) + '</b>' + (e ? '<br><span style="opacity:.85;">Estado ' + esc(nombreBonito(e)) + '</span>' : ''),
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

        // ── Números de municipio MOVIDOS A MANO ────────────────────────────────────────────
        // El número se puede arrastrar a un hueco donde no tape nada. Se guarda su LatLng (no un
        // desplazamiento en píxeles) para que aguante zoom/paneo y salga en el MISMO sitio en la
        // foto, que también proyecta latlng→canvas. Vive en localStorage: la selección de
        // municipios tampoco se persiste en el servidor, así que sería incoherente (y una tabla
        // de más) guardar esto en la BD. Clave "ESTADO|MUNICIPIO" → [lat, lng].
        var MUNI_POS_LS = 'mapa.muniNumPos';
        var muniNumPos = (function () {
            try { return JSON.parse(localStorage.getItem(MUNI_POS_LS)) || {}; } catch (e) { return {}; }
        })();
        function guardarMuniNumPos() {
            try { localStorage.setItem(MUNI_POS_LS, JSON.stringify(muniNumPos)); } catch (e) { /* cuota llena: se pierde al recargar, no rompe */ }
        }
        // Posición del número: la que puso el usuario o, si no la movió, el centro visual.
        function posNumeroMuni(layer, key) {
            var p = muniNumPos[key];
            return (p && p.length === 2) ? L.latLng(p[0], p[1]) : centroVisualMunicipio(layer);
        }
        // Arrastre PROPIO del número, con pointer events sobre el icono. NO se usa el
        // `draggable` de L.marker: en el pane de municipios Leaflet no llega a enganchar el
        // mousedown del icono y quien acababa arrastrando era el MAPA (el número parecía
        // moverse solo porque se movía todo el mapa con él).
        // Se mueve por latlng, no por márgenes, así lo que se guarda es exactamente lo que se ve.
        var UMBRAL_ARRASTRE = 3; // px que hay que mover para que cuente como arrastre y no como clic
        function habilitarArrastreNumero(mk) {
            var ic = mk._icon;
            if (!ic || ic._arrastreListo) return;
            ic._arrastreListo = true;
            var off = null;      // desfase entre el puntero y el centro del número al agarrarlo
            var origen = null;   // latlng de partida, para poder revertir si fue solo un clic
            var movio = false;   // ¿se llegó a arrastrar de verdad? (ver UMBRAL_ARRASTRE)
            L.DomEvent.on(ic, 'pointerdown', function (e) {
                if (e.button !== 0) return;
                L.DomEvent.stop(e); // sin esto el mapa se desplaza en vez de moverse el número
                var p = map.mouseEventToContainerPoint(e);
                // El esquive automático aparta el número con un margin sobre el icono: ese
                // desplazamiento se ve pero no está en la latlng, así que se incorpora ANTES de
                // empezar a arrastrar y se deja de usar el margin.
                var mx = parseFloat(ic.style.marginLeft) || 0, my = parseFloat(ic.style.marginTop) || 0;
                var m = map.latLngToContainerPoint(mk.getLatLng()).add(L.point(mx, my));
                ic.style.marginLeft = '0px'; ic.style.marginTop = '0px';
                origen = mk.getLatLng();
                mk.setLatLng(map.containerPointToLatLng(m));
                off = m.subtract(p);
                movio = false;
                if (ic.setPointerCapture) ic.setPointerCapture(e.pointerId);
            });
            L.DomEvent.on(ic, 'pointermove', function (e) {
                if (!off) return;
                L.DomEvent.stop(e);
                var destino = map.mouseEventToContainerPoint(e).add(off);
                if (!movio && destino.distanceTo(map.latLngToContainerPoint(mk.getLatLng())) < UMBRAL_ARRASTRE) return;
                movio = true;
                mk.setLatLng(map.containerPointToLatLng(destino));
            });
            // pointercancel además de pointerup: si el navegador aborta el gesto, se guarda
            // igual donde quedó en vez de dejarlo a medias.
            L.DomEvent.on(ic, 'pointerup pointercancel', function (e) {
                if (!off) return;
                off = null;
                L.DomEvent.stop(e);
                // Un CLIC sin arrastrar no debe fijar nada: si se guardara, el municipio quedaría
                // excluido para siempre del esquive automático y, peor, se congelaría la posición
                // temporal que le hubiera dado ese esquive. Se revierte y no se toca lo guardado.
                if (!movio) { mk.setLatLng(origen); declutterVelas(true); return; }
                var ll = mk.getLatLng();
                muniNumPos[mk._muniKey] = [ll.lat, ll.lng];
                guardarMuniNumPos();
                declutterVelas(true); // el número cambió de sitio → recolocar etiquetas
            });
        }
        // ¿Debe mostrarse este municipio? (por estado o individual, y NO excluido)
        function muniVisible(e, m) {
            var k = muniKey(e, m);
            if (muniExcluidos.has(k)) return false;
            return estadosConMuni.has(normEstado(e)) || muniIndividuales.has(k);
        }

        // ── Centro VISUAL de un municipio (polo de inaccesibilidad) ──
        // getBounds().getCenter() usa el centro del RECTÁNGULO delimitador: en municipios
        // con forma de L / media luna / con apéndices, ese punto cae FUERA del polígono y
        // el número queda "por fuera". polylabel encuentra el punto interior más lejano de
        // cualquier borde = el centro del espacio más grande. Adaptado de mapbox/polylabel (ISC).
        function ppDistSq(px, py, ax, ay, bx, by) {
            var x = ax, y = ay, dx = bx - ax, dy = by - ay;
            if (dx || dy) {
                var t = ((px - x) * dx + (py - y) * dy) / (dx * dx + dy * dy);
                if (t > 1) { x = bx; y = by; } else if (t > 0) { x += dx * t; y += dy * t; }
            }
            dx = px - x; dy = py - y;
            return dx * dx + dy * dy;
        }
        // Distancia con signo del punto al polígono (+ dentro, − fuera). rings = [exterior, ...huecos].
        function distPuntoPoligono(x, y, rings) {
            var inside = false, minDistSq = Infinity;
            for (var k = 0; k < rings.length; k++) {
                var ring = rings[k];
                for (var i = 0, len = ring.length, j = len - 1; i < len; j = i++) {
                    var a = ring[i], b = ring[j];
                    if ((a[1] > y) !== (b[1] > y) &&
                        (x < (b[0] - a[0]) * (y - a[1]) / (b[1] - a[1]) + a[0])) inside = !inside;
                    minDistSq = Math.min(minDistSq, ppDistSq(x, y, a[0], a[1], b[0], b[1]));
                }
            }
            return (inside ? 1 : -1) * Math.sqrt(minDistSq);
        }
        function areaAnillo(ring) {
            var s = 0;
            for (var i = 0, j = ring.length - 1; i < ring.length; j = i++)
                s += (ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1]);
            return s / 2;
        }
        function polylabel(rings) {
            var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity, ext = rings[0];
            for (var i = 0; i < ext.length; i++) {
                var p = ext[i];
                if (p[0] < minX) minX = p[0];
                if (p[1] < minY) minY = p[1];
                if (p[0] > maxX) maxX = p[0];
                if (p[1] > maxY) maxY = p[1];
            }
            var width = maxX - minX, height = maxY - minY, cellSize = Math.min(width, height);
            if (cellSize === 0) return [minX, minY];
            var precision = cellSize / 100, h = cellSize / 2, SQRT2 = Math.SQRT2;
            function cell(cxx, cyy, hh) {
                var d = distPuntoPoligono(cxx, cyy, rings);
                return { x: cxx, y: cyy, h: hh, d: d, max: d + hh * SQRT2 };
            }
            var cells = [];
            for (var cx = minX; cx < maxX; cx += cellSize)
                for (var cy = minY; cy < maxY; cy += cellSize)
                    cells.push(cell(cx + h, cy + h, h));
            var best = cell((minX + maxX) / 2, (minY + maxY) / 2, 0), guard = 0;
            while (cells.length && guard++ < 100000) {
                var bi = 0;
                for (var q = 1; q < cells.length; q++) if (cells[q].max > cells[bi].max) bi = q;
                var c = cells.splice(bi, 1)[0];
                if (c.d > best.d) best = c;
                if (c.max - best.d <= precision) continue;
                var hh = c.h / 2;
                cells.push(cell(c.x - hh, c.y - hh, hh));
                cells.push(cell(c.x + hh, c.y - hh, hh));
                cells.push(cell(c.x - hh, c.y + hh, hh));
                cells.push(cell(c.x + hh, c.y + hh, hh));
            }
            return [best.x, best.y];
        }
        // Recorre TODAS las coordenadas de una geometría Polygon/MultiPolygon → cb(lng,lat).
        function forEachCoord(g, cb) {
            if (!g) return;
            var polys = g.type === 'MultiPolygon' ? g.coordinates : g.type === 'Polygon' ? [g.coordinates] : [];
            for (var a = 0; a < polys.length; a++)
                for (var b = 0; b < polys[a].length; b++)
                    for (var c = 0; c < polys[a][b].length; c++)
                        cb(polys[a][b][c][0], polys[a][b][c][1]);
        }
        // Centro VISUAL de una feature GeoJSON (donde va el número). Cachea en f.__centroVisual
        // para computarlo una sola vez por municipio. Se usa tanto para dibujar el número como
        // para ordenar la numeración de arriba a abajo (misma fuente = todo coherente).
        function centroVisualFeature(f) {
            if (f && f.__centroVisual) return f.__centroVisual;
            var ll = null;
            try {
                var g = f && f.geometry;
                var polys = g && g.type === 'MultiPolygon' ? g.coordinates
                          : g && g.type === 'Polygon' ? [g.coordinates] : null;
                if (polys && polys.length) {
                    // Parte de MAYOR ÁREA: en municipios multi-parte (islas/exclaves) el
                    // número va en la porción principal, no en un islote.
                    var mejor = polys[0], mejorArea = -1;
                    for (var i = 0; i < polys.length; i++) {
                        var ar = Math.abs(areaAnillo(polys[i][0]));
                        if (ar > mejorArea) { mejorArea = ar; mejor = polys[i]; }
                    }
                    var pt = polylabel(mejor); // [lng, lat]
                    if (pt && !isNaN(pt[0]) && !isNaN(pt[1])) ll = L.latLng(pt[1], pt[0]);
                }
            } catch (e) { ll = null; }
            if (!ll) { // fallback: centro del bounding box de la propia geometría
                var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity, n = 0;
                forEachCoord(f && f.geometry, function (x, y) {
                    if (x < minX) minX = x; if (y < minY) minY = y;
                    if (x > maxX) maxX = x; if (y > maxY) maxY = y; n++;
                });
                if (n) ll = L.latLng((minY + maxY) / 2, (minX + maxX) / 2);
            }
            if (ll && f) f.__centroVisual = ll;
            return ll;
        }
        function centroVisualMunicipio(layer) {
            return centroVisualFeature(layer.feature) || layer.getBounds().getCenter();
        }

        function repintarMuniEstado() {
            // Punto unico donde se dispara la carga perezosa: si hay municipios que pintar y el
            // geojson aun no esta, se pide y se repinta al llegar. Asi las 5 llamadas que
            // existen a esta funcion no tienen que saber nada de la descarga.
            if (!muniData && (estadosConMuni.size || muniIndividuales.size)) {
                cargarMunicipios().then(function (gj) { if (gj) repintarMuniEstado(); });
                return;
            }
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
                    var key = muniKey(e, m);
                    var num = byKey[key];
                    if (num && layer.getBounds) {
                        // interactive: el número se arrastra (habilitarArrastreNumero) y el clic
                        // derecho lo devuelve al centro del municipio.
                        var mk = L.marker(posNumeroMuni(layer, key), {
                            icon: L.divIcon({ className: 'muni-num', html: '<span>' + num + '</span>', iconSize: [20, 20], iconAnchor: [10, 10] }),
                            keyboard: false, pane: 'muniNumPane',
                            title: 'Arrastra para moverlo · clic derecho para devolverlo a su sitio'
                        });
                        mk._muniKey = key;
                        mk._muniLayer = layer;
                        mk.on('add', function () { habilitarArrastreNumero(this); });
                        mk.on('contextmenu', function (ev) {
                            L.DomEvent.stop(ev);
                            delete muniNumPos[this._muniKey];
                            guardarMuniNumPos();
                            this.setLatLng(centroVisualMunicipio(this._muniLayer));
                            declutterVelas(true);
                        });
                        muniNumeros.addLayer(mk);
                    }
                });
            }
            actualizarLeyenda(); // refleja municipios en la leyenda
            sincronizarBotonMuni(); // encendido/apagado del botón-miniatura de municipios
            declutterVelas(true); // los números cambiaron → recolocar velas/etiquetas
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
                '<div class="mapa-ctx-title">' + esc(nombreBonito(municipio)) + '</div>' +
                filaCoord(coordM) +
                '<button type="button" class="mapa-ctx-item" data-a="quitar"><i class="material-icons">remove_circle_outline</i>Quitar Este Municipio</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="solo"><i class="material-icons">filter_center_focus</i>Mostrar Solo Este Municipio</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="ocultar"><i class="material-icons">layers_clear</i>Ocultar Municipios (Todos)</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="resaltar"><i class="material-icons">star_border</i>Resaltar Estado</button>';
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

        // ── Municipios: carga PEREZOSA ────────────────────────────────────────────
        // El geojson de municipios es el archivo mas pesado del mapa y solo hace falta si el
        // usuario activa "Todos los municipios" o pide los de un estado concreto. Antes se
        // descargaba SIEMPRE al abrir el mapa, aunque no se tocara la capa. Ahora se pide la
        // primera vez que se necesita; el resultado se memoriza en muniPromesa para que varias
        // llamadas seguidas compartan una sola descarga.
        var muniUrl = el.getAttribute('data-municipios');
        // Miniaturas de los botones de capas (imágenes pre-generadas: tools/generar_miniaturas_mapa.php).
        var miniMuniUrl    = el.getAttribute('data-mini-muni');
        var miniFajaUrl    = el.getAttribute('data-mini-faja');
        var miniBloquesUrl = el.getAttribute('data-mini-bloques');
        var muniPromesa = null;
        function cargarMunicipios() {
            if (muniPromesa) return muniPromesa;
            if (!muniUrl) return Promise.resolve(null);
            muniPromesa = fetch(muniUrl).then(function (r) { return r.json(); }).then(function (gj) {
                muniData = gj;
                construirColoresMuni(gj.features); // coloreado por adyacencia (vecinos ≠ color) antes de pintar
                return gj;
            }).catch(function () {
                // Si fallo se limpia la promesa para permitir un reintento en el SIGUIENTE
                // uso (otro clic del usuario). Quien llama debe comprobar que el resultado
                // no sea null antes de repintar: si no, el .then() volveria a pedir la
                // descarga al instante y se entraria en un bucle infinito de peticiones.
                muniPromesa = null;
                return null;
            });
            return muniPromesa;
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
                '<div class="mapa-ctx-title">' + (nombre ? esc(nombreBonito(nombre)) : 'Estado') + '</div>' +
                filaCoord(coordE) +
                '<button type="button" class="mapa-ctx-item" data-accion="resaltar">' +
                    '<i class="material-icons">' + (fijado ? 'star' : 'star_border') + '</i>' +
                    (fijado ? 'Quitar Resaltado' : 'Dejar Resaltado') +
                '</button>' +
                '<button type="button" class="mapa-ctx-item" data-accion="muni">' +
                    '<i class="material-icons">' + (muniOn ? 'layers_clear' : 'account_tree') + '</i>' +
                    (muniOn ? 'Ocultar Municipios' : 'Ver Municipios') +
                '</button>' +
                // Guardar un punto (oleoducto) en esta coordenada — solo con permiso de gestión.
                (PUEDE_EDITAR ? '<button type="button" class="mapa-ctx-item" data-accion="guardar">' +
                    '<i class="material-icons">add_location_alt</i>Guardar Punto</button>' : '');
            var x = ev.originalEvent ? ev.originalEvent.clientX : 0;
            var y = ev.originalEvent ? ev.originalEvent.clientY : 0;
            menu.style.left = Math.min(x, window.innerWidth - 210) + 'px';
            menu.style.top  = Math.min(y, window.innerHeight - 180) + 'px';
            // En pantalla completa, el menú debe ir DENTRO del elemento en fullscreen
            // (lo de fuera no se ve). Si no, va al body normal.
            (document.fullscreenElement || document.body).appendChild(menu);
            menu.addEventListener('click', function (e) {
                if (e.target.closest && e.target.closest('.mapa-ctx-coordcopy')) { copiarCoordenada(coordE); menu.remove(); return; }
                var b = e.target.closest ? e.target.closest('.mapa-ctx-item') : null; if (!b) return;
                var acc = b.getAttribute('data-accion');
                if (acc === 'guardar') { menu.remove(); if (ev.latlng) oleoPopupGuardar(ev.latlng, ''); return; }
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
                // Con la Faja encendida el tooltip dice ADEMÁS en qué división está el cursor.
                // Se recalcula al mover el ratón (no al entrar) porque un estado puede cruzar
                // dos divisiones — o entrar y salir de la Faja sin cambiar de estado.
                var ponerTexto = function (e) {
                    var div = divisionFajaEnPunto(e.latlng);
                    e.target.setTooltipContent(esc(nombre) +
                        (div ? '<br><span style="opacity:.85;">División ' + esc(div) + '</span>' : ''));
                };
                layer.on({
                    // No tocar el estilo si el estado está FIJADO (clic derecho).
                    mouseover: function (e) { if (!estadosFijados.has(e.target)) e.target.setStyle(estiloEstadoHover); e.target.bringToFront(); ponerTexto(e); },
                    mousemove: ponerTexto,
                    mouseout:  function (e) { if (!estadosFijados.has(e.target)) estados.resetStyle(e.target); },
                    contextmenu: function (e) { menuEstado(e, e.target, nombre); }
                });
            }
        }).addTo(map);

        // ¿En qué ESTADO cae una coordenada? (ray casting sobre sus anillos).
        // Hace falta para el clic derecho sobre un BLOQUE petrolero: ese polígono se queda con el
        // evento, así que hay que buscar a mano el estado de debajo y abrir SU menú (resaltar /
        // ver municipios). Si no, con la capa de bloques encendida no habría forma de activar los
        // municipios desde el mapa.
        function puntoEnAnillo(ll, anillo) {
            var dentro = false;
            for (var i = 0, j = anillo.length - 1; i < anillo.length; j = i++) {
                var yi = anillo[i].lat, xi = anillo[i].lng, yj = anillo[j].lat, xj = anillo[j].lng;
                if (((yi > ll.lat) !== (yj > ll.lat)) && (ll.lng < (xj - xi) * (ll.lat - yi) / (yj - yi) + xi)) dentro = !dentro;
            }
            return dentro;
        }
        function puntoEnLatLngs(ll, latlngs) {
            if (!latlngs || !latlngs.length) return false;
            if (latlngs[0] instanceof L.LatLng) return puntoEnAnillo(ll, latlngs);
            // Polígono: el PRIMER anillo es el exterior y los siguientes son huecos (un punto en
            // un hueco no cuenta). Multipolígono: basta con que caiga en una de sus partes.
            if (latlngs[0][0] instanceof L.LatLng) {
                if (!puntoEnAnillo(ll, latlngs[0])) return false;
                for (var h = 1; h < latlngs.length; h++) if (puntoEnAnillo(ll, latlngs[h])) return false;
                return true;
            }
            for (var i = 0; i < latlngs.length; i++) if (puntoEnLatLngs(ll, latlngs[i])) return true;
            return false;
        }
        function estadoEnPunto(ll) {
            var res = null;
            estados.eachLayer(function (l) {
                if (res || !l.getBounds().contains(ll)) return; // el bounds descarta rápido
                if (puntoEnLatLngs(ll, l.getLatLngs())) res = l;
            });
            return res;
        }
        // Menú de clic derecho para una capa que TAPA a los estados (bloques petroleros): abre el
        // menú del estado de debajo y, si no hay ninguno (mar), solo la coordenada.
        function menuSobreEstadoOCoordenada(ev) {
            var e = estadoEnPunto(ev.latlng);
            if (e) menuEstado(ev, e, (e.feature && e.feature.properties && e.feature.properties.shapeName) || 'Estado');
            else menuCoordenada(ev);
        }

        var geojsonUrl = el.getAttribute('data-geojson');
        if (geojsonUrl) {
            fetch(geojsonUrl).then(function (r) { return r.json(); }).then(function (gj) { estados.addData(gj); }).catch(function () {});
        }

        // Interruptor "Todos los municipios" (botón-miniatura de arriba-derecha, ver agregarMiniCapa).
        // Los bordes de los estados quedan SIEMPRE visibles (estados.addTo(map) arriba); los
        // municipios no son una capa simple: se pintan según estadosConMuni + repintarMuniEstado.
        var todosMuniOn = false;
        // Activa TODOS los municipios de TODOS los estados (limpia exclusiones previas).
        function activarTodosMunicipios() {
            todosMuniOn = true;
            if (!muniData) {
                // Carga perezosa: se pide el geojson y, al llegar, se reintenta (si el usuario
                // no ha desmarcado la capa mientras tanto).
                cargarMunicipios().then(function (gj) { if (gj && todosMuniOn) activarTodosMunicipios(); });
                return;
            }
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
        // ── Botón MINIATURA de municipios (arriba-derecha) ────────────────────────────────
        // Sustituye al control de capas de Leaflet (icono gris que al pasar el mouse se abría en
        // un checkbox): ahora se ve un mini mapa de Venezuela ya pintado con los MISMOS colores
        // que tendrán los municipios, así se sabe qué enciende el botón sin leer nada.
        // La miniatura es una imagen pre-generada (php tools/generar_miniaturas_mapa.php, ~40 KB que el
        // navegador cachea): NO descarga el geojson de municipios (≈1 MB), que se sigue pidiendo
        // solo cuando el usuario enciende la capa de verdad.
        var _btnMuni = null;
        // ¿Hay municipios encendidos? (todos por el botón, o los de un estado por clic derecho).
        // Incluye todosMuniOn para que el botón se vea encendido ya durante la descarga del geojson.
        function hayMunicipiosVisibles() { return !!(todosMuniOn || estadosConMuni.size || muniIndividuales.size); }
        // Mantiene el botón al día: lo llama repintarMuniEstado, el único sitio por el que pasan
        // TODOS los cambios de la capa (botón, menús de clic derecho, etc.).
        function sincronizarBotonMuni() {
            if (!_btnMuni) return;
            _btnMuni.classList.toggle('activo', hayMunicipiosVisibles());
            _btnMuni.title = todosMuniOn ? 'Ocultar los municipios' : 'Ver TODOS los municipios (colores por municipio)';
        }
        // ── CAJA DE CAPAS (arriba-derecha) ────────────────────────────────────────────
        // UN solo control que agrupa los tres botones-miniatura (Municipios, Faja, Bloques).
        // En PC se ven los tres siempre, como antes. En TELÉFONO la caja se recoge en un
        // único cuadrado con icono de capas: al tocarlo se despliegan las tres miniaturas,
        // cada una marcada si está encendida. El CSS decide cuál de las dos formas se ve,
        // así que rotar el teléfono ajusta solo (no hay medición de ancho en el JS).
        var capasBox, capasPanel; // los rellena onAdd, síncrono en el addControl de abajo
        var CapasCtrl = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function () {
                capasBox = L.DomUtil.create('div', 'mapa-capas-box');
                var toggle = L.DomUtil.create('button', 'mapa-fit-btn mapa-capas-toggle', capasBox);
                toggle.type = 'button';
                toggle.title = 'Capas del mapa';
                toggle.innerHTML = '<i class="material-icons">layers</i>';
                capasPanel = L.DomUtil.create('div', 'mapa-capas-panel', capasBox);
                L.DomEvent.disableClickPropagation(capasBox);
                L.DomEvent.disableScrollPropagation(capasBox);
                L.DomEvent.on(toggle, 'click', function () { capasBox.classList.toggle('abierto'); });
                // El cuadrado se pinta ENCENDIDO cuando alguna capa lo está. Se vigila la clase
                // .activo de las miniaturas en vez de avisar desde cada capa: así CUALQUIER
                // camino que las encienda (su botón, el menú de clic derecho de un estado, la
                // carga perezosa del geojson) queda reflejado sin tocar esas rutas.
                new MutationObserver(function () {
                    toggle.classList.toggle('activo', !!capasPanel.querySelector('.mapa-mini-capa.activo'));
                }).observe(capasPanel, { subtree: true, attributes: true, attributeFilter: ['class'] });
                return capasBox;
            }
        });
        map.addControl(new CapasCtrl()); // deja listos capasBox/capasPanel (onAdd es síncrono)
        // Cierra la caja de capas (la usa el clic en el mapa: tocar fuera la recoge).
        function cerrarCapas() { capasBox.classList.remove('abierto'); }

        // Molde del botón-miniatura, COMPARTIDO por las tres capas (Municipios, Faja y Bloques):
        // imagen pre-generada + rótulo. Cada capa pone qué hace el clic y qué necesita del botón
        // recién creado (guardarlo para poder encenderlo/apagarlo). Todos cuelgan de capasPanel:
        // el disableClickPropagation de la caja ya cubre a los hijos.
        function agregarMiniCapa(urlImagen, rotulo, alClic, alCrear) {
            var btn = L.DomUtil.create('button', 'mapa-mini-capa', capasPanel);
            btn.type = 'button';
            btn.innerHTML = '<img class="mapa-mini-capa-img" src="' + esc(urlImagen) + '" alt="" draggable="false">' +
                            '<span class="mapa-mini-capa-lbl">' + esc(rotulo) + '</span>';
            alCrear(btn);
            L.DomEvent.on(btn, 'click', alClic);
        }
        if (miniMuniUrl) agregarMiniCapa(miniMuniUrl, 'Municipios', function () {
            // El botón es el de TODOS: si no están todos (aunque haya municipios sueltos
            // encendidos por clic derecho), enciéndelos; solo apaga cuando ya están todos.
            // Antes miraba hayMunicipiosVisibles() y con un estado suelto encendido el primer
            // clic apagaba en vez de activar todos.
            if (todosMuniOn) ocultarTodosMunicipios(); else activarTodosMunicipios();
            sincronizarBotonMuni(); // por si la carga perezosa aún no repintó
        }, function (btn) { _btnMuni = btn; sincronizarBotonMuni(); });

        // ── CAPA PETROLERA: Faja Petrolífera del Orinoco + bloques ────────────────────────
        // Sirve para ubicar los frentes: en qué área de la Faja (Boyacá, Junín, Ayacucho,
        // Carabobo) y en qué bloque petrolero cae cada punto.
        // Los datos son de los servicios PÚBLICOS de ArcGIS Online del "Mapa Petrolífero de
        // Venezuela" (LSIGMA), pero NO se consultan en vivo: tools/generar_geo_faja.php los descarga y
        // adelgaza a GeoJSON propio en public/geo (faja-poligonal / faja-bloques). Se sirven
        // desde nuestro servidor — rápido, sin depender de Esri.
        // Son DOS capas con su propio botón, independientes: se puede ver solo la Faja, solo los
        // bloques o las dos superpuestas. Cada una baja su geojson la PRIMERA vez que se enciende
        // (carga perezosa, igual que los municipios).
        // NO usar renderizador de CANVAS aquí, por más que el SVG sea más lento con 336 bloques:
        // el canvas es UN elemento que tapa todo el mapa y se queda con los eventos del ratón, así
        // que los ESTADOS de debajo dejan de recibir el hover — se pierden su tooltip (con la
        // división de la Faja) y el cursor normal. Probado y revertido.
        var fajaPoligonalUrl = el.getAttribute('data-faja-poligonal');
        var fajaBloquesUrl   = el.getAttribute('data-faja-bloques');
        // Las 4 divisiones de la Faja, de oeste a este: color (en tonos OSCUROS, para que se lean
        // sobre el satélite) y nombre con acentos (el geojson los trae sin ellos). Este mismo
        // orden es el de la leyenda. A pedido del cliente: Junín en azul (antes ámbar) — distinto
        // al de las velas (#0067b1) para no confundirlos — y Carabobo en negro (antes rojo, que
        // sobre el verde del satélite tiraba a naranja).
        // OJO: los mismos colores están en tools/generar_miniaturas_mapa.php (la miniatura del botón).
        var AREAS_FAJA = [
            { clave: 'BOYACA',   nombre: 'Boyacá',   color: '#15803d' },
            { clave: 'JUNIN',    nombre: 'Junín',    color: '#1d4ed8' },
            { clave: 'AYACUCHO', nombre: 'Ayacucho', color: '#a21caf' },
            { clave: 'CARABOBO', nombre: 'Carabobo', color: '#000000' }
        ];
        var _areaFaja = {};
        AREAS_FAJA.forEach(function (a) { _areaFaja[a.clave] = a; });
        function colorAreaFaja(nombre) { var a = _areaFaja[normEstado(nombre)]; return a ? a.color : '#c2410c'; }
        function nombreAreaFaja(nombre) { var a = _areaFaja[normEstado(nombre)]; return a ? a.nombre : nombreBonito(nombre); }
        // Divisiones que van en la leyenda: solo cuando la capa está encendida. La comprobación
        // de capaFaja es por orden: esto se declara ANTES que la capa y la leyenda podría
        // dibujarse primero.
        function areasFajaVisibles() { return (capaFaja && capaFaja.on) ? AREAS_FAJA : []; }
        // Borde NEGRO en las divisiones de la Faja: el relleno ya dice cuál es cada área y así el
        // contorno se distingue del de los bloques (que va en gris claro). El MISMO trazo lo usan
        // las dos capas de la Faja: la del relleno y la copia solo-contorno de fajaBordePane.
        var COLOR_BORDE_FAJA = '#000000';
        function trazoFaja(extra) {
            var t = { color: COLOR_BORDE_FAJA, weight: 2, opacity: 0.95, dashArray: '7 5', fill: false };
            for (var k in extra) t[k] = extra[k];
            return t;
        }
        // Bloques en GRIS a propósito: son cientos y en color tapaban el mapa. El color queda para
        // la Faja (4 áreas) y para los municipios.
        // El borde se queda CLARO a propósito: con el relleno oscuro es lo único que separa un
        // bloque del de al lado. La foto lee estos mismos valores de la capa (ver dibujarFaja).
        var estiloBloque      = { color: '#cbd5e1', weight: 0.9, opacity: 0.85, fill: true, fillColor: '#1e293b', fillOpacity: 0.4 };
        var estiloBloqueHover = { color: '#ffffff', weight: 2.2, fillOpacity: 0.6 };
        // Bloque encontrado con el buscador: se queda MARCADO (mismo amarillo que el resaltado de
        // estados) hasta que se busque otro o se limpie. Antes solo se resaltaba 4 s y, con el
        // mapa aún acercándose, no daba tiempo a ver cuál era.
        var estiloBloqueMarcado = { color: '#ffd23f', weight: 3.5, opacity: 1, fill: true, fillColor: '#ffd23f', fillOpacity: 0.45 };
        var _bloqueMarcado = null;

        // Ficha del bloque al pasar el mouse: nombre, área de la Faja/zona, empresa y superficie.
        // 116 de los 336 bloques (los del Lago y Costa Afuera) vienen SIN nombre propio en la
        // fuente: ahí el título es el campo ("Bloque XV", "La Ceiba") y no se repite abajo.
        function tituloBloque(p) {
            var titulo = p.nombre ? 'Bloque ' + nombreBonito(p.nombre) : (p.campo ? nombreBonito(p.campo) : 'Bloque petrolero');
            // La etiqueta corta (J5, A4…) es la que aparece en el mapa petrolero oficial.
            if (p.etiqueta && String(p.etiqueta).length <= 4 && p.etiqueta !== p.nombre) titulo += ' (' + p.etiqueta + ')';
            return titulo;
        }
        function tooltipBloque(p) {
            var conNombre = !!p.nombre;
            var html = '<b>' + esc(tituloBloque(p)) + '</b>';
            if (p.campo && conNombre) html += '<br><span style="opacity:.85;">División ' + esc(nombreAreaFaja(p.campo)) + '</span>';
            // La empresa va TAL CUAL viene (en mayúsculas): son razones sociales con siglas
            // (PDVSA, CVP, S.A.) que nombreBonito estropearía ("Pdvsa").
            if (p.empresa) html += '<br>' + esc(p.empresa) + (p.pais ? ' <span style="opacity:.85;">(' + esc(nombreBonito(p.pais)) + ')</span>' : '');
            if (p.caracteristica) html += '<br><span style="opacity:.85;">' + esc(nombreBonito(p.caracteristica)) + '</span>';
            if (p.area_km2) html += '<br><span style="opacity:.85;">' + Number(p.area_km2).toLocaleString('es-VE', { maximumFractionDigits: 0 }) + ' km²</span>';
            return html;
        }
        // Molde de "capa perezosa con botón-miniatura": descarga (una sola vez), encendido/apagado
        // y estado del botón. Lo comparten la Faja y los Bloques — solo cambia cómo se pinta.
        function capaPetrolera(url, crearCapa, tituloOff, tituloOn, alCambiar) {
            var est = { on: false, capa: null, promesa: null, btn: null };
            est.sincronizar = function () {
                if (!est.btn) return;
                est.btn.classList.toggle('activo', est.on);
                est.btn.title = est.on ? tituloOn : tituloOff;
            };
            est.montar = function (btn) { est.btn = btn; est.sincronizar(); }; // se lo pasa agregarMiniCapa
            est.cargar = function () {
                if (est.promesa) return est.promesa;
                if (!url) return Promise.resolve(false);
                spinOn();
                est.promesa = fetch(url).then(function (r) { return r.json(); }).then(function (gj) {
                    spinOff();
                    est.capa = crearCapa(gj);
                    return true;
                }).catch(function () {
                    // Igual que en los municipios: se limpia la promesa para poder reintentar en el
                    // siguiente clic (si no, quedaría rota para siempre).
                    spinOff();
                    est.promesa = null;
                    if (window.showToast) window.showToast('No se pudo cargar la capa petrolera.', 'error');
                    return false;
                });
                return est.promesa;
            };
            est.alternar = function () {
                est.on = !est.on;
                est.sincronizar();  // responde al instante, aunque el geojson aún se esté bajando
                if (alCambiar) alCambiar(est); // cada capa refresca LO SUYO (leyenda / buscador)
                if (!est.on) { if (est.capa) map.removeLayer(est.capa); return; }
                est.cargar().then(function (ok) {
                    if (!ok) { est.on = false; est.sincronizar(); if (alCambiar) alCambiar(est); return; }
                    if (est.on) est.capa.addTo(map);   // si la apagó mientras cargaba, no se pinta
                    if (alCambiar) alCambiar(est);     // ya con la capa cargada (el buscador la necesita)
                });
            };
            return est;
        }

        // Faja: las 4 divisiones, cada una de su color y con su nombre encima. No son
        // interactivas a propósito: son fondo de referencia y, si recibieran el ratón, se
        // comerían el clic derecho (coordenada) y el hover de los bloques que van encima.
        var capaFaja = capaPetrolera(fajaPoligonalUrl, function (gj) {
            // Sin rótulos fijos encima del mapa (tapaban demasiado): qué área es cada color lo
            // dice la LEYENDA, y al pasar el mouse el nombre sale junto al del estado.
            var relleno = L.geoJSON(gj, {
                pane: 'fajaPane',
                interactive: false,
                style: function (f) {
                    return trazoFaja({ fill: true, fillColor: colorAreaFaja(f && f.properties && f.properties.nombre), fillOpacity: 0.28 });
                }
            });
            // Copia SOLO-CONTORNO en fajaBordePane: es la que garantiza que la línea negra se vea
            // siempre, aunque encima pase una carretera o un nombre del mapa base. La de abajo
            // también lo trae para que la FOTO salga igual con una sola pasada (ver dibujarFaja).
            var borde = L.geoJSON(gj, { pane: 'fajaBordePane', interactive: false, style: function () { return trazoFaja(); } });
            var grupo = L.layerGroup([relleno, borde]);
            grupo.areas = relleno; // el "¿en qué división estoy?" mira solo esta (la del contorno es una copia)
            return grupo;
        }, 'Ver la Faja Petrolífera del Orinoco (Boyacá, Junín, Ayacucho y Carabobo)', 'Ocultar la Faja Petrolífera',
           function () { actualizarLeyendaFaja(); });

        // Bloques petroleros de todo el país, en gris, con su ficha al pasar el mouse.
        var capaBloques = capaPetrolera(fajaBloquesUrl, function (gj) {
            var capa = L.geoJSON(gj, {
                pane: 'bloquesPane',
                style: function () { return estiloBloque; },
                onEachFeature: function (f, layer) {
                    // Contenido en FUNCIÓN: Leaflet lo evalúa al abrir la ficha. Armar el HTML de
                    // los 336 bloques al cargar la capa solo servía para retrasar el encendido.
                    var p = f.properties || {};
                    layer.bindTooltip(function () { return tooltipBloque(p); }, { sticky: true, direction: 'top', className: 'estado-tooltip' });
                    layer.on({
                        // El bloque MARCADO por el buscador conserva su amarillo: si el hover lo
                        // pisara, bastaría pasar el ratón por encima para perder la marca.
                        mouseover: function (ev) { if (ev.target !== _bloqueMarcado) ev.target.setStyle(estiloBloqueHover); ev.target.bringToFront(); },
                        mouseout:  function (ev) { if (ev.target !== _bloqueMarcado) capa.resetStyle(ev.target); },
                        // Sin esto el clic derecho sobre un bloque no abriría NADA (el polígono se
                        // lo queda y el handler general del mapa lo ignora por interactivo) y con
                        // los bloques encendidos no se podrían activar los municipios de un estado.
                        contextmenu: function (ev) { L.DomEvent.stop(ev); menuSobreEstadoOCoordenada(ev); }
                    });
                }
            });
            // Índice para el buscador: se arma UNA vez, al cargar. `buscable` junta nombre,
            // etiqueta, división y empresa para poder encontrar el bloque por cualquiera.
            capa.indice = [];
            capa.eachLayer(function (l) {
                var p = (l.feature && l.feature.properties) || {};
                capa.indice.push({
                    titulo: tituloBloque(p),
                    sub: [p.campo ? 'División ' + nombreAreaFaja(p.campo) : '', p.empresa || ''].filter(Boolean).join(' · '),
                    buscable: [p.nombre, p.etiqueta, p.campo, p.empresa, p.pais].filter(Boolean).join(' '),
                    poligono: l
                });
            });
            return capa;
        }, 'Ver los bloques petroleros', 'Ocultar los bloques petroleros',
           function () { sincronizarBuscadorBloques(); });

        // ¿En qué división de la Faja cae una coordenada? Devuelve su nombre bonito, o null si la
        // capa está apagada o el punto queda fuera. Lo usa el tooltip del estado, que lo pide en
        // CADA mousemove: como el cálculo recorre miles de vértices, se guarda el último resultado
        // y solo se recalcula si el puntero se movió de verdad (o pasaron 150 ms). Mover el ratón
        // dentro de la misma división no vuelve a calcular nada.
        var _divCache = { lat: null, lng: null, t: 0, val: null };
        function divisionFajaEnPunto(ll) {
            if (!capaFaja.on || !capaFaja.capa || !capaFaja.capa.areas) return null;
            var ahora = Date.now();
            if (_divCache.lat !== null && (ahora - _divCache.t) < 150 &&
                Math.abs(ll.lat - _divCache.lat) < 0.01 && Math.abs(ll.lng - _divCache.lng) < 0.01) {
                return _divCache.val;
            }
            var res = null;
            capaFaja.capa.areas.eachLayer(function (l) {
                if (res || !l.getLatLngs || !l.getBounds().contains(ll)) return; // el bounds descarta rápido
                if (puntoEnLatLngs(ll, l.getLatLngs())) res = l.feature && l.feature.properties && l.feature.properties.nombre;
            });
            _divCache = { lat: ll.lat, lng: ll.lng, t: ahora, val: res ? nombreAreaFaja(res) : null };
            return _divCache.val;
        }
        if (miniFajaUrl && fajaPoligonalUrl) agregarMiniCapa(miniFajaUrl, 'Faja', capaFaja.alternar, capaFaja.montar);
        if (miniBloquesUrl && fajaBloquesUrl) agregarMiniCapa(miniBloquesUrl, 'Bloques', capaBloques.alternar, capaBloques.montar);

        // Ancho máximo (px) de la barra de escala. Fuente única: lo usan el control de la
        // pantalla, el cálculo de escalaKm() y la regla que dibuja la foto, así los tres eligen
        // SIEMPRE el mismo escalón redondo (50 km, 100 km…).
        var ESCALA_MAX_PX = 160;
        // Escala al lado de la brújula (abajo-derecha), más grande.
        L.control.scale({ imperial: false, position: 'bottomright', maxWidth: ESCALA_MAX_PX }).addTo(map);

        // ── Buscador de zonas (geocoder) sesgado a Venezuela ──
        // Buscador: Photon (autocompletar, sin rate-limit) + detección de COORDENADAS.
        // Si escribes "lat, lng" (ej. 8.72370, -62.90443) va DIRECTO a ese punto.
        // lat/lon aquí son solo el valor inicial: sesgarPorMapa() los sustituye por el centro del
        // mapa antes de cada consulta. bbox restringe al país (antes lat/lon solo SESGABAN y
        // colaban resultados de otros países). limit 10: suficiente tras el filtro por país y
        // respuesta más liviana/rápida que el remoto (photon.komoot.io).
        var _photon = L.Control.Geocoder.photon({ geocodingQueryParams: { lat: 8, lon: -66, limit: 10, bbox: '-73.4,0.6,-59.8,12.6' } });
        // Zonas UTM que cubren Venezuela: 20 = oriente (meridiano central −63°), 19 = centro,
        // 18 = occidente. Así no hay que escribir la zona. OJO: el MISMO par de metros puede caer
        // dentro del país en dos zonas distintas, así que esto no la "adivina": manda la 20 (donde
        // están los frentes, y la única que se usaba antes) y solo se prueban 19 y 18 cuando con
        // la 20 el punto se sale de Venezuela. REGVEN ≈ WGS84.
        var UTM_ZONAS = [20, 19, 18];
        function utmAlatLng(E, N) {
            for (var i = 0; i < UTM_ZONAS.length; i++) {
                try {
                    var ll = proj4('+proj=utm +zone=' + UTM_ZONAS[i] + ' +datum=WGS84 +units=m +no_defs', 'WGS84', [E, N]);
                    var p = L.latLng(ll[1], ll[0]); // proj4 devuelve [lng, lat]
                    if (VENEZUELA.contains(p)) return p;
                } catch (e) {}
            }
            return null;
        }
        // Texto es-VE a número: el "." separa MILES (siempre va seguido de 3 dígitos) y la ","
        // es el decimal. "1.108.784" → 1108784 · "317.429,25" → 317429.25 · "317429.25" → 317429.25.
        function aNumero(t) {
            return parseFloat(String(t).replace(/\.(?=\d{3}(?:\D|$))/g, '').replace(',', '.'));
        }
        // Números de la línea que pueden ser metros UTM. Los pequeños se descartan (las
        // profundidades "20,5 / 20,00 m" y el 39 de "P-39"): una coordenada UTM del país nunca
        // baja de 100.000 m. La expresión se crea aquí dentro a propósito: una /g compartida
        // arrastra lastIndex entre llamadas y se saltaría números.
        function numerosUTM(s) {
            var re = /\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d{6,8}(?:[.,]\d+)?/g;
            var out = [], m;
            while ((m = re.exec(s))) {
                var v = aNumero(m[0]);
                if (!isNaN(v) && v >= 100000 && v <= 9999999) out.push(v);
            }
            return out;
        }
        // Coordenada UTM. Acepta DOS formas:
        //  · Con la letra delante: "N- 1.032.594,40 E- 501.623,42" (en cualquier orden).
        //  · Solo los dos números, como vienen en las tablas de perforaciones/calicatas:
        //    "Perforación P-39 Este 1.108.784 317.429 20,5 20,00 m" → Norte y luego Este.
        function parseUTM(q) {
            if (typeof proj4 === 'undefined') return null;
            var s = String(q || '').toUpperCase();
            // El \b es imprescindible: sin él, la E final de "ESTE 1.108.784" se tomaba como la
            // letra del Este y se llevaba el valor del Norte.
            var mN = s.match(/\bN\b\s*[-:]?\s*(\d[\d.,]*)/);
            var mE = s.match(/\bE\b\s*[-:]?\s*(\d[\d.,]*)/);
            var etiquetado = !!(mN && mE), N, E;
            if (etiquetado) { N = aNumero(mN[1]); E = aNumero(mE[1]); }
            else {
                var nums = numerosUTM(s);
                if (nums.length < 2) return null;
                N = nums[0]; E = nums[1];   // orden de las tablas: Norte y después Este
            }
            if (isNaN(N) || isNaN(E)) return null;
            // El Este de una zona UTM nunca pasa de 999.999 m: si llega uno mayor, los dos
            // números venían al revés (esto vale también con letras: es imposible, no una duda).
            if (E > 999999 && N <= 999999) { var aux = N; N = E; E = aux; }
            if (E < 100000 || E > 999999 || N < 0 || N > 10000000) return null;
            var pt = utmAlatLng(E, N);
            // SIN letras el orden es una suposición (hay tablas que ponen el Este primero): si
            // así no cae en Venezuela, se prueba cambiado. CON letras no se toca — el usuario ya
            // dijo cuál es cuál, y devolver otro punto sería inventarse una ubicación.
            if (!pt && !etiquetado && N >= 100000 && N <= 999999) pt = utmAlatLng(N, E);
            return pt;
        }
        function parseCoord(q) {
            var s = String(q || '').trim();
            // 1) Decimal "lat, lng" (ej. 8.8388, -63.1105).
            var m = s.replace(/[°]/g, '').match(/^(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)$/);
            if (m) {
                var lat = parseFloat(m[1]), lng = parseFloat(m[2]);
                if (!isNaN(lat) && !isNaN(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180) return L.latLng(lat, lng);
            }
            // 2) UTM: con letra ("N- 1.032.594,40 E- 501.623,42") o los dos números sueltos
            //    dentro de una fila pegada ("Perforación P-39 Este 1.108.784 317.429 20,5").
            return parseUTM(s);
        }
        function resCoord(c) { return [{ name: '📍 ' + c.lat.toFixed(5) + ', ' + c.lng.toFixed(5), center: c, bbox: c.toBounds(1200) }]; }
        // Deja SOLO resultados de Venezuela (countrycode 'VE'). La bbox ya recorta en el
        // servidor, pero puede colar bordes de Colombia/Brasil/Guyana; esto los descarta.
        // Un resultado sin countrycode se conserva (por si Photon lo omite). Máx 8 tras filtrar.
        function soloVenezuela(results) {
            if (!results || !results.length) return results || [];
            return results.filter(function (r) {
                var cc = r && r.properties && r.properties.countrycode;
                return !cc || cc === 'VE';
            }).slice(0, 8);
        }
        // Sesga la búsqueda hacia DONDE ESTÁ MIRANDO EL MAPA (como Google Maps): antes lat/lon
        // estaban fijos en el centro del país, así que buscar "Arecuna" estando en Anzoátegui
        // recomendaba las de cualquier otro estado. Se actualiza en cada consulta.
        function sesgarPorMapa() {
            var c = map.getCenter();
            _photon.options.geocodingQueryParams.lat = c.lat;
            _photon.options.geocodingQueryParams.lon = c.lng;
            return c;
        }
        // Y además ordena los resultados por CERCANÍA a ese centro: el sesgo del servidor solo
        // desempata, esto garantiza que "Arecuna 2 / Arecuna 3" salgan primero si estás encima.
        function porCercania(results, centro) {
            return (results || []).slice().sort(function (a, b) {
                var ca = a && a.center, cb2 = b && b.center;
                if (!ca || !cb2) return 0;
                return centro.distanceTo(ca) - centro.distanceTo(cb2);
            });
        }
        // Caché de sugerencias por término: al reescribir/borrar hacia un término ya buscado,
        // la lista sale INSTANTÁNEA sin volver a llamar al servidor remoto (photon.komoot.io,
        // en Europa → es lo que tarda). Se limpia sola al pasar de ~80 términos por sesión.
        var _sugCache = {};
        function cacheSug(key, val) {
            var ks = Object.keys(_sugCache);
            if (ks.length > 80) delete _sugCache[ks[0]];
            _sugCache[key] = val;
        }
        var geocoderMapa = {
            geocode: function (q, cb, ctx) {
                var c = parseCoord(q);
                if (c) { cb.call(ctx, resCoord(c)); return; }
                var centro = sesgarPorMapa();
                _photon.geocode(q, function (results) {
                    cb.call(ctx, porCercania(soloVenezuela(results), centro));
                }, ctx);
            },
            suggest: function (q, cb, ctx) {
                var c = parseCoord(q);
                if (c) { cb.call(ctx, resCoord(c)); return; }
                var centro = sesgarPorMapa();
                // La clave incluye la zona del mapa (redondeada a ~0.1°): el mismo término
                // buscado desde otro estado debe recomendar lo de ALLÁ, no lo cacheado de acá.
                var key = String(q || '').trim().toLowerCase() + '@' +
                          centro.lat.toFixed(1) + ',' + centro.lng.toFixed(1);
                if (_sugCache[key]) { cb.call(ctx, _sugCache[key]); return; } // ya buscado → instantáneo
                if (_photon.suggest) _photon.suggest(q, function (results) {
                    var ve = porCercania(soloVenezuela(results), centro);
                    cacheSug(key, ve); cb.call(ctx, ve);
                }, ctx);
                else cb.call(ctx, []);
            }
        };
        // VELA azul (pin de ubicación tipo Google Maps) para los puntos y la búsqueda.
        // Logo de la empresa (el mismo del favicon de la pestaña) para ponerlo dentro de la vela.
        var LOGO_URL = (document.querySelector('link[rel~="icon"]') || {}).href || '/favicon.png';
        // El MISMO logo, precargado como <img>, para poder dibujarlo en el canvas del export
        // (el SVG de la vela en pantalla lo trae por <image href>, que el canvas no puede usar).
        var logoImg = null;
        var logoListo = cargarImg(LOGO_URL).then(function (img) { logoImg = img; });
        var velaSeq = 0; // ids únicos para el clip-path de cada vela (evita que se mezclen entre SVGs)
        // Tamaño del pin en pantalla. Se dejó más pequeño que antes (era 34×45): con varios
        // proyectos el pin tapaba los puntos vecinos y sus etiquetas. VELA_TIP_Y = a qué altura
        // sobre la punta queda el bulbo (donde se ancla la etiqueta).
        var VELA_W = 20, VELA_H = 26, VELA_TIP_Y = 18;
        // Dos velas a menos de VELA_THRESH px se funden en una. ESC_LEJOS = cuánto encoge el pin
        // en vista lejana (debe coincidir con .mapa-velas-lejos del CSS). LEJOS_KM = a partir de
        // qué escala se considera "lejos". Estaban escritos a mano en pantalla y en el export por
        // separado: si se cambiaba uno, la foto dejaba de salir igual que el mapa.
        var VELA_THRESH = 24, ESC_LEJOS = 0.65, LEJOS_KM = 300;
        // DETALLE_KM = escala hasta la que la etiqueta muestra el nombre del PUNTO además del
        // proyecto: hasta 50 km salen los dos; del siguiente escalón de la barra (100 km) en
        // adelante SOLO el nombre del proyecto, porque ahí las velas ya se funden y un mismo
        // contenedor junta varios proyectos: los nombres de punto sobran y no cabrían.
        // Es un umbral APARTE de LEJOS_KM a propósito: aquel decide cuánto encoge el pin, que es
        // otra cosa. Vale igual para la pantalla y para la foto.
        var DETALLE_KM = 50;
        // Colocación de la cajita respecto al pin: ETQ_SEP_X = separación a su costado (deja
        // sitio para la línea que la une con la vela),
        // ETQ_SUBE_Y = cuánto queda por encima del bulbo. Valores pequeños = etiqueta pegada al
        // pin y a su altura, en vez de flotando arriba y separada. Los usan IGUAL la pantalla y
        // la foto, así que tocarlos aquí mueve las dos.
        var ETQ_SEP_X = 18, ETQ_SUBE_Y = 4;
        // Cajas apiladas de un punto compartido: aire entre ellas y cuántas ranuras verticales se
        // prueban antes de rendirse (0 = a la altura del pin, luego arriba/abajo alternando).
        var ETQ_HUECO_Y = 4, ETQ_RANURAS = 7;
        // Anillos de separación: si la cajita no cabe junto al pin, se prueba a ETQ_ANILLO_PASO px
        // más de distancia cada vez, hasta ETQ_ANILLOS intentos. La línea guía la sigue uniendo,
        // así que alejarla no la deja huérfana. Sirve para que al apiñarse los frentes (mapa lejos)
        // los nombres se abran hacia afuera en vez de irse al hover.
        var ETQ_ANILLOS = 5, ETQ_ANILLO_PASO = 26;
        // De CADA cajita sale su propia línea curva, que arranca en el BORDE de la vela. Vale
        // igual para un punto normal (una cajita) y para uno compartido (una por proyecto).
        // Grosores: cada línea se pinta DOS veces, un borde oscuro debajo y el blanco encima, para
        // que se lea igual sobre satélite claro y oscuro. Un solo sitio donde están los valores:
        // los usan la pantalla y la foto.
        var ETQ_TRAZO_W = 1.8, ETQ_BORDE_W = 3.4, ETQ_BORDE_OP = 0.5;
        // Color de la línea y de su halo. El halo va del color contrario para que la línea se lea
        // igual sobre satélite claro y oscuro.
        var ETQ_TRAZO_COLOR = '#ffffff', ETQ_BORDE_COLOR = '#0f172a';
        // Cuánto se arquea la línea (parábola). 0 = recta; 0.35 = arco marcado.
        var ETQ_COMBA = 0.28;
        // En pantalla la curva se aproxima con tramos rectos (Leaflet no dibuja bezier); en la
        // foto se traza con bezierCurveTo, que es exacto. Con estos tramos no se nota.
        var ETQ_CURVA_PASOS = 18;
        // Radio del circulito con el número del municipio. Debe cuadrar con .muni-num del CSS
        // (20 px de lado). Antes estaba escrito como 11, como 10 y como width/2 en sitios distintos:
        // la caja que se reservaba para no taparlo no era del tamaño del círculo que se dibujaba.
        var MUNI_NUM_R = 10;
        // Fondo del PNG de "solo la leyenda": gris neutro, el tono con el que se ve el panel
        // translúcido encima del mapa. Sin esto el archivo salía azul marino.
        var LEYENDA_BG_SOLA = '#32363c';
        function vistaLejana() { return escalaKm() > LEJOS_KM; }
        function velaIcon(color) {
            var c = color || '#0067b1';
            var cid = 'vclip' + (++velaSeq);
            // Pin de gota con el LOGO de la empresa recortado en círculo dentro del bulbo.
            return L.divIcon({
                className: 'mapa-vela',
                html: '<svg width="' + VELA_W + '" height="' + VELA_H + '" viewBox="0 0 24 32" xmlns="http://www.w3.org/2000/svg">' +
                      '<defs><clipPath id="' + cid + '"><circle cx="12" cy="11.4" r="7.5"/></clipPath></defs>' +
                      '<path d="M12 .6C6 .6 1.2 5.4 1.2 11.4c0 7.6 9.2 18.4 10 19.4.4.5 1.2.5 1.6 0 .8-1 10-11.8 10-19.4C22.8 5.4 18 .6 12 .6z" fill="' + c + '" stroke="#ffffff" stroke-width="1.5"/>' +
                      '<circle cx="12" cy="11.4" r="7.8" fill="#ffffff"/>' +
                      (LOGO_URL
                        ? '<image href="' + LOGO_URL + '" x="6.2" y="6" width="11.6" height="11" clip-path="url(#' + cid + ')" preserveAspectRatio="xMidYMid meet"/>'
                        : '<circle cx="12" cy="11.4" r="5" fill="' + c + '"/>') +
                      '</svg>',
                iconSize: [VELA_W, VELA_H], iconAnchor: [VELA_W / 2, VELA_H - 1],
                popupAnchor: [0, -(VELA_H - 4)], tooltipAnchor: [0, -VELA_TIP_Y]
            });
        }
        var buscadorMarker = null;
        function marcarBusqueda(c) {
            if (buscadorMarker) { map.removeLayer(buscadorMarker); buscadorMarker = null; }
            if (!c) return;
            buscadorMarker = L.marker(c, { icon: velaIcon('#0067b1'), zIndexOffset: 2000 }).addTo(map);
        }

        // ── Botón para RECOGER/DESPLEGAR el buscador (solo teléfono) ──────────────────
        // Se añade ANTES del geocoder para que quede a su izquierda (la barra topleft es
        // un flex en fila y respeta el orden de alta). En PC el CSS lo esconde y la barra
        // de búsqueda va siempre abierta, como hasta ahora; en teléfono ocupaba TODO el
        // ancho del mapa de forma permanente, así que allí arranca recogida detrás de este
        // cuadrado con lupa. Volver a tocarlo la recoge. La clase la lleva el contenedor
        // del mapa (#mapa-leaflet.mapa-buscar-abierto) y es el CSS quien decide qué se ve,
        // así que rotar el teléfono ajusta solo.
        var BuscadorToggle = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                var btn = L.DomUtil.create('button', 'mapa-fit-btn mapa-buscar-toggle');
                btn.type = 'button';
                btn.title = 'Buscar un lugar';
                btn.innerHTML = '<i class="material-icons">search</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', function () {
                    var abierto = el.classList.toggle('mapa-buscar-abierto');
                    btn.classList.toggle('activo', abierto);
                    if (!abierto) { cerrarBuscador(); return; }
                    var input = el.querySelector('.leaflet-control-geocoder-form input');
                    if (input) input.focus();
                });
                return btn;
            }
        });
        map.addControl(new BuscadorToggle());

        L.Control.geocoder({
            position: 'topleft',
            placeholder: 'Buscar lugar o coordenada…',
            defaultMarkGeocode: false, // el marcador lo ponemos nosotros (bola azul fiable)
            collapsed: false,          // barra de búsqueda SIEMPRE visible (no el iconito)
            suggestMinLength: 2,       // sugiere desde 2 letras (más ágil)
            suggestTimeout: 80,        // dispara la sugerencia antes tras dejar de teclear
            geocoder: geocoderMapa
        }).on('markgeocode', function (e) {
            var c = e.geocode.center;
            if (e.geocode.bbox) map.fitBounds(e.geocode.bbox, { maxZoom: 16 });
            else if (c) map.setView(c, 15);
            cerrarBuscador();
            marcarBusqueda(c); // bola azul en el punto encontrado
            // Ofrecer GUARDAR ese punto en un proyecto. Si el resultado es una coordenada
            // (nombre "📍 …"), no sugerir nombre; si es un lugar, prefijarlo. YA NO se abre el
            // formulario completo de una: se muestra un popup PEQUEÑO para que el usuario decida
            // (con permiso). Sin permiso, solo la coordenada (lectura).
            if (c) setTimeout(function () {
                var nom = e.geocode.name || '';
                nom = (nom.indexOf('📍') === 0) ? '' : nom.split(',')[0];
                if (PUEDE_EDITAR) oleoPopupPreguntar(c, nom); else oleoPopupGuardar(c, nom);
            }, 420);
        }).addTo(map);

        // Botón "X" para vaciar el buscador (la librería no lo trae). Se muestra solo cuando
        // hay texto; al pulsarlo limpia el campo, cierra la lista de resultados y quita la
        // vela de búsqueda. Se inyecta en el contenedor del geocoder (a la derecha del input).
        (function () {
            var cont  = document.querySelector('#mapa-leaflet .leaflet-control-geocoder');
            var input = cont && cont.querySelector('.leaflet-control-geocoder-form input');
            if (!cont || !input) return;
            var clear = document.createElement('button');
            clear.type = 'button';
            clear.className = 'mapa-geo-clear';
            clear.title = 'Vaciar búsqueda';
            clear.innerHTML = '<i class="material-icons">close</i>';
            cont.appendChild(clear);
            var toggle = function () { clear.style.display = input.value ? 'inline-flex' : 'none'; };
            input.addEventListener('input', toggle);
            L.DomEvent.disableClickPropagation(clear);
            clear.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                input.value = '';
                toggle();
                cerrarBuscador();        // cierra la lista de sugerencias
                marcarBusqueda(null);    // quita la vela de búsqueda si la hubiera
                input.focus();
            });
            toggle();
        })();

        // La vela de búsqueda es EFÍMERA: solo marca el lugar mientras está abierto el popup de
        // "Guardar punto". Al cerrarse ese popup (se guarde o se cancele) se quita, para no dejar
        // una vela de ubicación PEGADA junto a la del punto ya guardado (se veían dos iguales).
        map.on('popupclose', function (e) {
            if (e.popup && e.popup.options && e.popup.options.className === 'mapa-oleo-pop') marcarBusqueda(null);
        });

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
                // mapa-ctrl-mobile-hide: en teléfono se ocultan TODOS los botones de la barra
                // (Ver toda Venezuela, Pantalla completa, Descargar, Dibujar, Proyectos); solo
                // queda el buscador. En PC se ven todos.
                var btn = L.DomUtil.create('button', 'mapa-fit-btn mapa-ctrl-mobile-hide');
                btn.type = 'button';
                btn.title = 'Ver toda Venezuela';
                btn.innerHTML = '<i class="material-icons">public</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', function () { map.fitBounds(VENEZUELA); });
                return btn;
            }
        });
        map.addControl(new FitVE());

        // ── Pantalla completa (usa el contenedor del mapa como raíz) ──
        // Salir NO se hace con este mismo botón: en pantalla completa se oculta (CSS) y
        // aparece la "X" de arriba-derecha (control CerrarFS). Así hay UN solo control
        // para salir, no dos que hagan lo mismo.
        function salirPantallaCompleta() {
            var exit = document.exitFullscreen || document.webkitExitFullscreen;
            if (exit) exit.call(document);
        }
        var FullScreen = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                // mapa-ctrl-mobile-hide: en teléfono NO se muestra "Pantalla completa" (pedido).
                var btn = L.DomUtil.create('button', 'mapa-fit-btn mapa-fs-toggle mapa-ctrl-mobile-hide');
                btn.type = 'button';
                btn.title = 'Pantalla completa';
                btn.innerHTML = '<i class="material-icons">fullscreen</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', function () {
                    var req = el.requestFullscreen || el.webkitRequestFullscreen;
                    if (req) req.call(el);
                });
                return btn;
            }
        });
        map.addControl(new FullScreen());

        // ── Botón "X" para SALIR de pantalla completa (solo visible en pantalla completa,
        //    lo controla el CSS con #mapa-leaflet:fullscreen). Va arriba-derecha porque
        //    arriba-izquierda queda el buscador. ──
        var CerrarFS = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function () {
                var btn = L.DomUtil.create('button', 'mapa-fit-btn mapa-fs-close');
                btn.type = 'button';
                btn.title = 'Salir de pantalla completa';
                btn.innerHTML = '<i class="material-icons">close</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', salirPantallaCompleta);
                return btn;
            }
        });
        map.addControl(new CerrarFS());

        // Recalcula el tamaño del mapa al entrar/salir de pantalla completa (cambia el alto).
        var onFsChange = function () { setTimeout(function () { map.invalidateSize(); }, 120); };
        document.addEventListener('fullscreenchange', onFsChange);
        document.addEventListener('webkitfullscreenchange', onFsChange);

        // ── DESMONTAJE al navegar por la SPA ──────────────────────────────────────────────
        // Estos listeners cuelgan de document/window, no del contenedor, así que NO se van
        // solos cuando la SPA reemplaza la vista: sin esto cada visita a /mapa dejaba un par
        // vivos, cada uno reteniendo el mapa anterior entero y llamando invalidateSize()
        // sobre mapas ya huérfanos. Se comprueba en cada navegación si el contenedor sigue en
        // el documento; si ya no está, se sueltan los listeners y se destruye el mapa.
        var obsTam = null;   // ResizeObserver del contenedor (se asigna más abajo)
        var onOrientacion = function () { setTimeout(function () { map.invalidateSize({ pan: false }); }, 250); };
        var alNavegar = function () {
            if (document.body.contains(el)) return;   // seguimos en el mapa
            window.removeEventListener('spa:contentLoaded', alNavegar);
            document.removeEventListener('fullscreenchange', onFsChange);
            document.removeEventListener('webkitfullscreenchange', onFsChange);
            window.removeEventListener('orientationchange', onOrientacion);
            if (obsTam) obsTam.disconnect();
            map.remove();   // suelta también todos los listeners internos de Leaflet
        };
        window.addEventListener('spa:contentLoaded', alNavegar);

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
        // Fuente ÚNICA del texto: lo pintan igual la pantalla (aquí) y la foto (dibujarCreditos).
        var CREDITOS = [
            ['ELABORADO POR:', ' Fernando Sánchez | Ingeniero Industrial'],
            ['FUENTE CARTOGRÁFICA:', ' Delimitación Municipal, Instituto Geográfico de Venezuela Simón Bolívar (IGVSB). Cartografía Oficial 2016.']
        ];
        var Creditos = L.Control.extend({
            options: { position: 'bottomleft' },
            onAdd: function () {
                var d = L.DomUtil.create('div', 'mapa-creditos');
                d.innerHTML = CREDITOS.map(function (c) {
                    return '<div><b>' + c[0] + '</b>' + esc(c[1]) + '</div>';
                }).join('');
                return d;
            }
        });
        map.addControl(new Creditos());

        // ── Clic izquierdo en el mapa: solo recoge lo que esté desplegado. ──
        // La coordenada YA NO sale en un popup al hacer clic; se consulta con clic DERECHO
        // (menú de contexto), donde aparece junto a su botón de copiar.
        map.on('click', function () {
            if (edMode) return; // en modo edición el clic dibuja
            cerrarBuscador();
            cerrarCapas();      // tocar el mapa recoge la caja de capas del teléfono
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
        // Ubicaciones SIN frente: en el buscador del formulario son una opción más de la lista
        // (SUELTO_ID es un id centinela, no existe en frentes_trabajo) y en la leyenda/panel el
        // grupo se rotula con SUELTO_LABEL. Cambiar ese texto aquí lo cambia en todas partes.
        var SUELTO_ID = '__sin_proyecto__';
        // En MAYÚSCULAS como los nombres de los frentes, para que la leyenda se lea pareja.
        var SUELTO_LABEL = 'CONVERGENCIA ADMINISTRATIVA';
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
            // pane 'tuberiaPane' (z 465): por encima de estados/municipios para que la línea
            // reciba el hover/clic (longitud). Solo si ese pane existe en el mapa destino.
            var pane = (m.getPane && m.getPane('tuberiaPane')) ? 'tuberiaPane' : undefined;
            var borde  = L.polyline(trazo, { pane: pane, color: '#0a1620', weight: p.borde, opacity: 0.85, lineJoin: 'round', lineCap: 'round', smoothFactor: 1 }).addTo(m);
            var cuerpo = L.polyline(trazo, { pane: pane, color: color, weight: p.cuerpo, opacity: 1, lineJoin: 'round', lineCap: 'round', smoothFactor: 1 }).addTo(m);
            var brillo = L.polyline(trazo, { pane: pane, color: aclararColor(color, 0.65), weight: p.brillo, opacity: 0.85, lineJoin: 'round', lineCap: 'round', smoothFactor: 1 }).addTo(m);
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
            if (edLineLayers) aplicar(edLineLayers);
        }
        map.on('zoomend', actualizarPesoTuberias);

        function oleoDibujar(o) {
            // El grupo de ubicaciones sin frente se rotula SIEMPRE con SUELTO_LABEL: el nombre
            // guardado en BD no manda, así cambiar el texto es tocar una sola constante y no
            // hace falta renombrar la fila. Se normaliza aquí, el único sitio por el que pasan
            // TODOS los grupos, para que leyenda, panel y exportación digan lo mismo.
            if (o.suelto) o.nombre = SUELTO_LABEL;
            if (oleoMap[o.id]) {
                (oleoMap[o.id].lines || []).forEach(function (l) { map.removeLayer(l); });
                (oleoMap[o.id].markers || []).forEach(function (m) { map.removeLayer(m); });
            }
            var pts = puntosOrdenados(o);
            // La tubería SOLO aparece si se dibujó a mano con el lápiz (recorrido). Los puntos
            // NO se unen con una línea automática: cada punto es una "vela" (marcador de ubicación).
            var lines = (o.recorrido && o.recorrido.length >= 2)
                ? tuberiaCapas(o.recorrido.map(function (c) { return [c[0], c[1]]; }), o.color)
                : [];
            // Clic derecho sobre la tubería: editar o eliminar la línea (solo con permiso).
            if (PUEDE_EDITAR) lines.forEach(function (l) { l.on('contextmenu', function (ev) { menuLinea(ev, o.id); }); });
            // LONGITUD total (km) de la tubería. Solo si hay recorrido dibujado. Visible para
            // todos (es info, no edición). Se ofrece de dos formas:
            //  · CLIC en la línea: popup con la longitud destacada (se cierra con la ✕).
            //    (Sin tooltip de hover: la longitud sale SOLO al hacer clic, no al pasar el mouse.)
            if (lines.length) {
                var km = longitudKm(o.recorrido);
                var kmTxt = km.toFixed(2).replace('.', ',') + ' km';
                // Sin el nombre del proyecto (ya se ve en las velas de los puntos): solo la etiqueta
                // y los km, en letra compacta.
                var popKm = '<div class="oleo-km-pop"><span class="oleo-km-lbl">Longitud de la tubería</span>' +
                            '<span class="oleo-km-val">' + kmTxt + '</span></div>';
                // Sale al CLIC y se CIERRA AL PERDER EL FOCO (cuando el mouse sale de la línea). El
                // timeout con cancelación evita parpadeo entre las 3 capas superpuestas de la tubería:
                // al pasar de una a otra, el mouseover cancela el cierre pendiente.
                var kmCierra = null;
                lines.forEach(function (l) {
                    l.bindPopup(popKm, { className: 'oleo-km-popup', closeButton: true });
                    l.on('mouseover', function () { if (kmCierra) { clearTimeout(kmCierra); kmCierra = null; } });
                    l.on('mouseout',  function () { kmCierra = setTimeout(function () { map.closePopup(); }, 150); });
                });
            }
            var markers = pts.map(function (p) {
                var mk = L.marker([p.lat, p.lng], { icon: velaIcon('#0067b1'), zIndexOffset: 500 }).addTo(map);
                // Nombre que el usuario le puso al punto (ej. "PROGRESIVA 47+100"), la línea de
                // abajo de la etiqueta. El nombre del PROYECTO lo pone declutterVelas y el HTML
                // lo arma colocarEtiquetas, que necesita medir el texto para evitar solapes.
                // La COORDENADA NO va aquí: solo se muestra en el historial (leyenda).
                mk._velaPunto = p.nombre || 'Punto';
                // Clic derecho sobre la vela: eliminar ese punto del proyecto (solo con permiso).
                if (PUEDE_EDITAR) mk.on('contextmenu', function (ev) { menuVela(ev, o.id, p); });
                return mk;
            });
            oleoMap[o.id] = { data: o, lines: lines, markers: markers };
            declutterVelas(true); // (re)etiqueta las velas recién creadas de este proyecto
        }

        // Escala en km que muestra la barra del mapa (lo que ve el usuario: 100 km, 300 km…).
        // Se CALCULA con la misma cuenta que hace Leaflet para pintar la barra: la distancia real
        // que abarcan ESCALA_MAX_PX px a media altura, redondeada a 1/2/3/5×10ⁿ. Antes se leía el
        // TEXTO del control (document.querySelector), y eso daba 0 —o el valor de otro mapa— si la
        // barra aún no estaba pintada o quedaba un contenedor viejo en el DOM tras navegar por la
        // SPA; con 0 el mapa se creía en vista de detalle y sacaba los nombres de los puntos a
        // cualquier escala. Calculándolo sobre ESTE mapa el dato no puede estar desfasado.
        function escalaKm() {
            var y = map.getSize().y / 2;
            var m = map.distance(map.containerPointToLatLng([0, y]), map.containerPointToLatLng([ESCALA_MAX_PX, y]));
            if (!m || !isFinite(m)) return 0;
            return numRedondo(m) / 1000; // metros redondeados (lo que rotula la barra) → km
        }
        var FAM_ETQ = 'Inter, "Segoe UI", sans-serif'; // fuente de la etiqueta al pintarla en canvas
        // Cuánto se agranda la LETRA de las etiquetas en la imagen descargada respecto a la
        // pantalla (pedido: en la descarga se leía muy chica). 1 = igual que en pantalla.
        var ETQ_EXPORT_K = 1.35;
        // Tamaño EXACTO (px) de la cajita de una etiqueta. Se mide con un clon oculto que lleva
        // las mismas clases CSS que el tooltip real (misma fuente, mismo padding, mismo
        // interlineado): medirlo con canvas se quedaba corto y las cajas que se reservaban para
        // repartir salían más chicas que las de verdad, así que aún se rozaban.
        // `k` escala la medida (1 = pantalla; en el export, el factor de la hoja).
        var _probeEtq = null;
        function medidaEtiqueta(proyectos, puntos, k) {
            k = k || 1;
            if (!_probeEtq) {
                _probeEtq = document.createElement('div');
                _probeEtq.className = 'leaflet-tooltip estado-tooltip vela-label';
                _probeEtq.style.cssText = 'position:absolute;left:-9999px;top:-9999px;opacity:0;pointer-events:none;';
                el.appendChild(_probeEtq);
            }
            _probeEtq.innerHTML = etiquetaHtml(proyectos, puntos);
            return { w: Math.ceil(_probeEtq.offsetWidth) * k, h: Math.ceil(_probeEtq.offsetHeight) * k };
        }
        // Viñeta que lleva delante cada nombre de punto (se leen como lista cuando una etiqueta
        // junta varios). En PANTALLA la pinta el CSS (.vela-label b::before); esta constante es
        // la de la FOTO, que se dibuja a mano en el canvas: si se cambia una, cambiar la otra.
        var VINETA_PUNTO = '•';
        // Máximo de nombres de punto listados en una etiqueta; el resto se resume en "+N más"
        // (si no, un montón de velas juntas generaría una cajita enorme).
        var MAX_PUNTOS_ETQ = 4;
        // Nombres de punto que se pintan, con el resumen "+N más" al final si se pasan.
        // Devuelve {txt, resumen}: el resumen es un contador, no un punto, así que va SIN viñeta.
        function lineasPunto(puntos) {
            if (!puntos || !puntos.length) return [];
            var lista = puntos.slice(0, MAX_PUNTOS_ETQ).map(function (n) { return { txt: n, resumen: false }; });
            if (puntos.length > MAX_PUNTOS_ETQ) {
                lista.push({ txt: '+' + (puntos.length - MAX_PUNTOS_ETQ) + ' más', resumen: true });
            }
            return lista;
        }
        // HTML de la etiqueta: una línea azul por PROYECTO (varias cuando las velas de proyectos
        // distintos quedan encimadas y se funden en una sola) y, debajo, UNA LÍNEA POR PUNTO
        // (varias cuando dos velas del mismo proyecto se juntan: así se ven los dos nombres).
        // La viñeta • de cada punto la pone el CSS (.vela-label b::before); la línea "+N más"
        // lleva la clase vela-mas para quedarse sin viñeta.
        function etiquetaHtml(proyectos, puntos) {
            return proyectos.map(function (n) { return '<span class="vela-proj">' + esc(n) + '</span>'; }).join('') +
                   lineasPunto(puntos).map(function (l) {
                       return '<b' + (l.resumen ? ' class="vela-mas"' : '') + '>' + esc(l.txt) + '</b>';
                   }).join('');
        }
        function chocaCaja(a, b) { return a.x1 < b.x2 && a.x2 > b.x1 && a.y1 < b.y2 && a.y2 > b.y1; }
        // Qué nombres de punto lleva una vela: todos hasta DETALLE_KM (50 km) y NINGUNO de 100 km
        // en adelante (ahí solo cabe el nombre del frente). Única definición de la regla — la usan
        // pantalla y foto.
        function conDetalle() { return escalaKm() <= DETALLE_KM; }
        function puntosVisibles(r) { return conDetalle() ? r.puntos : null; }

        // Reparte las etiquetas de TODAS las velas (de todos los proyectos) evitando solapes:
        // se prueba a la DERECHA del pin y, si ahí choca con otra etiqueta o con un pin, a la
        // IZQUIERDA (solo a los costados). Si la etiqueta completa no cabe en ningún lado, se
        // reintenta con SOLO el nombre del proyecto (cajita más chica) antes de renunciar: con
        // varios puntos apiñados es preferible ver de qué proyecto son que no ver nada.
        // Antes cada proyecto se resolvía por separado y siempre a la derecha: por eso dos puntos
        // cercanos de proyectos distintos pegaban sus burbujas una encima de la otra.
        // reps: [{ x, y, proys, puntos }] — a cada uno se le añade .lado y .caja ({x1,y1,w,h}), y
        // .puntos queda en null si hubo que recortar. .caja = null si no hubo sitio de ninguna
        // forma. La usan IGUAL la pantalla y la foto que se descarga.
        // kEtq = escala SOLO del texto de la cajita (la foto la agranda; por defecto = k).
        function repartirEtiquetas(reps, k, bloqueos, kEtq) {
            k = k || 1; kEtq = kEtq || k;
            var pinW = VELA_W * k, tipY = VELA_TIP_Y * k;
            var sepX = pinW / 2 + ETQ_SEP_X * k;   // del centro del pin al borde de la cajita
            var hueco = ETQ_HUECO_Y * kEtq;             // aire entre cajas apiladas
            // Los pines ocupan sitio, y `bloqueos` añade los números de municipio: ninguna
            // etiqueta debe tapar ni un pin ni el número identificador de un municipio.
            var duros = cajasPines(reps, k);            // pines y otras etiquetas: nunca se pisan
            var blandos = bloqueos || [];               // números de municipio: se evitan SI se puede
            // Orden estable (arriba→abajo, izquierda→derecha): no cambia al hacer pan, así las
            // etiquetas no saltan de lado solas.
            reps.slice().sort(function (a, b) { return (a.y - b.y) || (a.x - b.x); }).forEach(function (r) {
                // Un punto que está en VARIOS proyectos saca UNA CAJITA POR PROYECTO (no una
                // cajita con varios nombres dentro), y uno normal saca una sola. En los dos casos
                // las cajitas se apilan al costado, a la misma separación, y cada una se une a la
                // vela con su propia línea (ver pintarLineasEtq / dibujarLineasEtq).
                var grupos = r.proys.length ? r.proys.map(function (n) { return [n]; }) : [[]];
                r.cajas = [];
                var yc = r.y - tipY - ETQ_SUBE_Y * k;   // centro vertical de la primera cajita
                grupos.forEach(function (proys) {
                    // Cada cajita lleva su proyecto Y el nombre del punto, también cuando el
                    // punto está compartido: así cada contenedor se entiende por sí solo.
                    var textos = (r.puntos && r.puntos.length) ? [r.puntos, null] : [null];
                    var puesta = null, medidas = {};
                    // Se prueba, en orden: (1) texto completo sin tapar NADA, (2) solo el nombre
                    // del proyecto, y (3)(4) lo mismo admitiendo tapar un número de municipio.
                    // Los números son obstáculos BLANDOS: con muchos municipios encendidos cubren
                    // el mapa entero y, si fueran duros, ninguna etiqueta cabría en ningún lado.
                    var intentos = [];
                    [true, false].forEach(function (evitarNum) {
                        textos.forEach(function (tx) { intentos.push({ txt: tx, evitarNum: evitarNum }); });
                    });
                    for (var t = 0; t < intentos.length && !puesta; t++) {
                        var ocupado = intentos[t].evitarNum ? duros.concat(blandos) : duros;
                        var clave = String(intentos[t].txt);
                        // medidaEtiqueta hace un reflow del DOM: se cachea por texto.
                        var m = medidas[clave] || (medidas[clave] = medidaEtiqueta(proys, intentos[t].txt, kEtq));
                        // Anillos: se prueba pegada al pin y, si no cabe, cada vez más lejos (la
                        // línea la sigue conectando). Con los frentes apiñados al alejar el mapa,
                        // los nombres se abren hacia afuera en vez de esconderse en el hover.
                        for (var ring = 0; ring < ETQ_ANILLOS && !puesta; ring++) {
                            var sepXr = sepX + ring * ETQ_ANILLO_PASO * k;
                            // Ranuras verticales: la primera a la altura del pin y las siguientes
                            // arriba/abajo alternando, para que las cajas de un mismo punto queden
                            // apiladas en columna y no una encima de otra.
                            for (var slot = 0; slot < ETQ_RANURAS && !puesta; slot++) {
                                var dy = (slot === 0) ? 0 : (Math.ceil(slot / 2) * (m.h + hueco) * (slot % 2 ? -1 : 1));
                                var lados = [
                                    { lado: 'right', x1: r.x + sepXr },
                                    { lado: 'left', x1: r.x - sepXr - m.w }
                                ];
                                for (var i = 0; i < lados.length && !puesta; i++) {
                                    var y1 = yc + dy - m.h / 2;
                                    var c = { x1: lados[i].x1 - 2 * k, x2: lados[i].x1 + m.w + 2 * k, y1: y1 - 2 * k, y2: y1 + m.h + 2 * k };
                                    var libre = true;
                                    for (var j = 0; j < ocupado.length && libre; j++) libre = !chocaCaja(c, ocupado[j]);
                                    if (libre) {
                                        puesta = { x1: lados[i].x1, y1: y1, w: m.w, h: m.h, lado: lados[i].lado,
                                                   proys: proys, puntos: intentos[t].txt };
                                        duros.push(c); // la etiqueta ya colocada estorba a las demás
                                    }
                                }
                            }
                        }
                    }
                    if (puesta) r.cajas.push(puesta);
                });
                // Los proyectos que no cupieron: su nombre sale al pasar el mouse por el pin.
                r.sinSitio = r.proys.filter(function (n) {
                    return !r.cajas.some(function (c) { return c.proys.indexOf(n) > -1; });
                });
            });
            return reps;
        }

        // Botón del OJO (arriba-izq): oculta los rótulos de proyecto/punto dejando SOLO las
        // velas; el nombre y las coordenadas del punto salen al pasar el mouse por la vela.
        // A PEDIDO DEL CLIENTE arranca SIEMPRE encendido (mapa limpio al abrir) y NO se recuerda:
        // el botón lo apaga mientras dure la visita, pero al volver a entrar vuelve a estar activo.
        var soloVelas = true;

        // Pantalla: pinta las cajas que decidió repartirEtiquetas como marcadores propios, cada
        // una unida al pin por su línea guía. Se usan marcadores (y no tooltips) porque un
        // marcador solo admite UN tooltip y un punto compartido necesita una caja por proyecto;
        // además así Leaflet las mueve solo al arrastrar el mapa, igual que los pines.
        // Todo vive en `etqCapa`, que se vacía y se vuelve a llenar en cada recálculo.
        function colocarEtiquetas(reps, lejos, bloqueos) {
            var escala = lejos ? ESC_LEJOS : 1;
            // Modo "solo velas" (botón del ojo): sin rótulos ni líneas guía. El dato del punto
            // (proyecto + nombre) aparece al pasar el mouse por la vela, justo encima de ella.
            if (soloVelas) {
                etqCapa.clearLayers();
                reps.forEach(function (r) {
                    r.mk.unbindTooltip();
                    r.mk.bindTooltip(etiquetaHtml(r.proys, r.puntos), {
                        permanent: false, direction: 'top', opacity: 1,
                        offset: [0, -VELA_H * escala],
                        className: 'estado-tooltip vela-label'
                    });
                });
                return;
            }
            var pts = reps.map(function (r) {
                return {
                    x: r.x, y: r.y, mk: r.mk, proys: r.proys,
                    // Varias velas fundidas en una → se listan TODOS sus nombres de punto (antes
                    // se perdían y solo quedaba el proyecto).
                    puntos: puntosVisibles(r)
                };
            });
            // kEtq = 1 SIEMPRE: en vista lejana el CSS encoge el pin pero NO la etiqueta, así que
            // medirla al 65% reservaba cajas más chicas que las reales y volvían a solaparse.
            repartirEtiquetas(pts, escala, bloqueos, 1);

            etqCapa.clearLayers();
            pts.forEach(function (r) {
                pintarLineasEtq(r, escala);   // toda cajita va unida a su vela por una línea
                r.cajas.forEach(function (c) {
                    var caja = '<div class="estado-tooltip vela-label">' +
                        etiquetaHtml(c.proys, c.puntos) + '</div>';
                    etqCapa.addLayer(L.marker(map.containerPointToLatLng([c.x1, c.y1]), {
                        icon: L.divIcon({ className: 'vela-etq', html: caja, iconSize: null, iconAnchor: [0, 0] }),
                        interactive: false, keyboard: false, pane: 'etqPane'
                    }));
                });
                // Los proyectos que no cupieron salen al pasar el mouse por el pin.
                r.mk.unbindTooltip();
                if (r.sinSitio && r.sinSitio.length) {
                    r.mk.bindTooltip(etiquetaHtml(r.sinSitio, r.puntos), {
                        permanent: false, direction: 'right',
                        offset: [VELA_W / 2 * escala + ETQ_SEP_X * escala, -ETQ_SUBE_Y * escala],
                        className: 'estado-tooltip vela-label'
                    });
                }
            });
        }

        // Líneas en PANTALLA: una curva por cajita, del borde de la vela hasta ella. Misma
        // geometría que la foto (curvaEtiqueta), así salen idénticas.
        function pintarLineasEtq(r, k) {
            var aLL = function (p) { return map.containerPointToLatLng(p); };
            var comun = { pane: 'etqPane', interactive: false, lineCap: 'round', lineJoin: 'round' };
            // Cada línea se pinta dos veces: halo debajo y trazo encima.
            var trazo = function (puntos) {
                var lls = puntos.map(aLL);
                etqCapa.addLayer(L.polyline(lls, Object.assign({ color: ETQ_BORDE_COLOR, weight: ETQ_BORDE_W * k, opacity: ETQ_BORDE_OP }, comun)));
                etqCapa.addLayer(L.polyline(lls, Object.assign({ color: ETQ_TRAZO_COLOR, weight: ETQ_TRAZO_W * k, opacity: 0.97 }, comun)));
            };
            r.cajas.forEach(function (c) { trazo(puntosCurva(curvaEtiqueta(r, c, k), ETQ_CURVA_PASOS)); });
        }

        // Geometría de la línea de una cajita. Única definición — la usan la pantalla y la foto,
        // así salen idénticas. Arranca en el BORDE de la vela (del lado hacia el que va la cajita,
        // a la altura del bulbo) y llega al borde interior de la cajita, con una curva suave.
        function curvaEtiqueta(r, c, k) {
            var haciaDerecha = (c.lado === 'right');
            var desde = [r.x + (haciaDerecha ? 1 : -1) * (VELA_W / 2) * k, r.y - VELA_TIP_Y * k];
            var hasta = [haciaDerecha ? c.x1 : (c.x1 + c.w), c.y1 + c.h / 2];
            // PARÁBOLA: los dos tiradores se separan de la recta hacia ARRIBA, en proporción a lo
            // larga que sea la línea. Así cada cajita sale con su propio arco en vez de una recta.
            var dx = hasta[0] - desde[0], dy = hasta[1] - desde[1];
            var comba = ETQ_COMBA * Math.sqrt(dx * dx + dy * dy);
            return {
                desde: desde,
                c1: [desde[0] + dx * 0.33, desde[1] + dy * 0.33 - comba],
                c2: [desde[0] + dx * 0.66, desde[1] + dy * 0.66 - comba],
                hasta: hasta
            };
        }
        // Puntos de la curva (para dibujarla con polilíneas en pantalla).
        function puntosCurva(rama, n) {
            var pts = [];
            for (var i = 0; i <= n; i++) {
                var t = i / n, u = 1 - t;
                pts.push([
                    u * u * u * rama.desde[0] + 3 * u * u * t * rama.c1[0] + 3 * u * t * t * rama.c2[0] + t * t * t * rama.hasta[0],
                    u * u * u * rama.desde[1] + 3 * u * u * t * rama.c1[1] + 3 * u * t * t * rama.c2[1] + t * t * t * rama.hasta[1]
                ]);
            }
            return pts;
        }


        // Caja (px) que ocupa cada pin en pantalla/canvas. La usan tanto el reparto de etiquetas
        // como el esquive de los números de municipio.
        function cajasPines(reps, k) {
            var w = VELA_W * k, h = VELA_H * k;
            return reps.map(function (r) { return { x1: r.x - w / 2, x2: r.x + w / 2, y1: r.y - h, y2: r.y }; });
        }
        // Saltos que se prueban (px, sin escalar) para apartar el número de un municipio cuando
        // una vela le cae encima: primero donde está, luego abajo/arriba/lados y diagonales.
        var DESPL_NUM = [[0, 0], [0, 24], [0, -24], [-24, 0], [24, 0], [-22, 22], [22, 22], [-22, -22], [22, -22], [0, 44], [0, -44]];
        // Devuelve el desplazamiento [dx, dy] que deja el número libre de obstáculos, o [0,0] si
        // no encuentra hueco (entonces se queda en su sitio: mejor tapado que fuera del municipio).
        function esquivarNumero(x, y, r, obstaculos, k) {
            for (var i = 0; i < DESPL_NUM.length; i++) {
                var dx = DESPL_NUM[i][0] * k, dy = DESPL_NUM[i][1] * k;
                var caja = { x1: x + dx - r, x2: x + dx + r, y1: y + dy - r, y2: y + dy + r };
                var libre = true;
                for (var j = 0; j < obstaculos.length && libre; j++) libre = !chocaCaja(caja, obstaculos[j]);
                if (libre) return [dx, dy];
            }
            return [0, 0];
        }

        // Funde en una sola vela las que quedan encimadas (<thresh px) AUNQUE sean de proyectos
        // distintos: la primera se queda y absorbe los nombres de proyecto de las demás (sin
        // repetir). `ocultar` esconde el marcador absorbido (solo aplica en pantalla).
        // Se usa igual en el mapa y en la foto que se descarga.
        function fundirVelas(reps, thresh, ocultar) {
            var out = [];
            reps.forEach(function (r) {
                for (var i = 0; i < out.length; i++) {
                    var dx = out[i].x - r.x, dy = out[i].y - r.y;
                    if (dx * dx + dy * dy < thresh * thresh) {
                        r.proys.forEach(function (n) { if (out[i].proys.indexOf(n) < 0) out[i].proys.push(n); });
                        // Los nombres de punto también se acumulan: la etiqueta única los lista todos.
                        (r.puntos || []).forEach(function (n) { if (out[i].puntos.indexOf(n) < 0) out[i].puntos.push(n); });
                        if (ocultar && r.mk) ocultar(r.mk);
                        return;
                    }
                }
                out.push(r);
            });
            return out;
        }

        // ÚNICA implementación de la regla de agrupación (antes estaba escrita dos veces, una para
        // la pantalla y otra para la foto, y podían desincronizarse):
        //  1) las velas del MISMO proyecto que se solapan (<thresh px) se funden en una, que se
        //     lleva los NOMBRES de todas (así se leen los dos puntos, no solo el del proyecto);
        //  2) después se funden también entre proyectos DISTINTOS que queden encimados.
        // `puntosDe(o)` devuelve los puntos ya proyectados a píxeles: [{x, y, nombre, mk?}].
        // `ocultar(mk)` esconde el marcador absorbido (solo lo usa la pantalla).
        function agruparVelas(puntosDe, thresh, ocultar) {
            var todas = [];
            // gruposOrdenados y no Object.keys: al fundirse velas de frentes distintos, sus
            // nombres se acumulan EN ESTE ORDEN, y debe ser el mismo que muestran la leyenda
            // y el panel (Object.keys los daba por id).
            gruposOrdenados().forEach(function (o) {
                if (proyOculto(o)) return;   // oculto → no genera velas
                var grupo = [];
                puntosDe(o).forEach(function (p) {
                    var rep = null;
                    for (var i = 0; i < grupo.length; i++) {
                        var dx = grupo[i].x - p.x, dy = grupo[i].y - p.y;
                        if (dx * dx + dy * dy < thresh * thresh) { rep = grupo[i]; break; }
                    }
                    if (rep) {
                        if (rep.puntos.indexOf(p.nombre) < 0) rep.puntos.push(p.nombre);
                        if (ocultar && p.mk) ocultar(p.mk);
                    } else {
                        grupo.push({ x: p.x, y: p.y, mk: p.mk, proys: [o.nombre], puntos: [p.nombre] });
                    }
                });
                todas = todas.concat(grupo);
            });
            return fundirVelas(todas, thresh, ocultar);
        }

        var declutterZoom = null, declutterLejos = null, declutterDetalle = null; // último estado, para no recalcular en paneo
        function declutterVelas(force) {
            var z = map.getZoom();
            // A MÁS de 300 km (500 km…) el pin se encoge. El nombre del PUNTO es otro umbral
            // (DETALLE_KM): hasta 50 km sale, más lejos solo el proyecto. Ambos por ESCALA.
            var lejos = vistaLejana(), detalle = conDetalle();
            // En un PAN puro (mismo zoom y mismos umbrales) las distancias en px entre velas son
            // idénticas (coordenadas globales de Mercator): no hace falta re-agrupar ni re-enlazar
            // todos los tooltips (evita flicker/jank). Se vigilan LOS DOS umbrales porque al
            // desplazarse en latitud la escala cambia sin cambiar el zoom. `force` re-ejecuta al
            // crear/recargar velas.
            if (!force && z === declutterZoom && lejos === declutterLejos && detalle === declutterDetalle) return;
            declutterZoom = z; declutterLejos = lejos; declutterDetalle = detalle;
            // A más de 300 km el pin se ve exageradamente grande → se encoge por CSS (clase en el mapa).
            el.classList.toggle('mapa-velas-lejos', lejos);
            // Los pines/tuberías de un proyecto oculto se quitan del mapa; los visibles se
            // reponen. Los pines visibles los vuelve a añadir agruparVelas; las tuberías, aquí.
            Object.keys(oleoMap).forEach(function (id) {
                var g = oleoMap[id], oculto = !!proyOcultos[id];
                (g.markers || []).forEach(function (mk) { if (oculto) map.removeLayer(mk); });
                (g.lines || []).forEach(function (l) {
                    if (oculto) map.removeLayer(l); else if (!map.hasLayer(l)) l.addTo(map);
                });
            });
            // El umbral de fusión va con el tamaño al que se DIBUJA el pin (en vista lejana el CSS
            // lo encoge): así dos velas se funden justo cuando se pisarían. Mismo criterio que la foto.
            var escalaPin = lejos ? ESC_LEJOS : 1;
            var THRESH = VELA_THRESH * escalaPin;
            // 1+2) Agrupar y fundir con la MISMA regla que usa la foto (agruparVelas).
            var todas = agruparVelas(function (o) {
                return (oleoMap[o.id].markers || []).map(function (mk) {
                    if (!map.hasLayer(mk)) mk.addTo(map);
                    var p = map.latLngToContainerPoint(mk.getLatLng());
                    return { x: p.x, y: p.y, nombre: mk._velaPunto, mk: mk };
                });
            }, THRESH, function (mk) { map.removeLayer(mk); });
            // 3) Los NÚMEROS de municipio que queden debajo de una vela se apartan un poco
            //    (siguen dentro del municipio) y se reservan para que tampoco los tape una
            //    etiqueta: antes la vela o su cajita escondían el número por completo.
            var numeros = apartarNumerosMuni(escalaPin);
            // 4) Las etiquetas de todas las velas se reparten juntas (derecha/izquierda).
            colocarEtiquetas(todas, lejos, numeros);
        }
        // Aparta los números de municipio que queden bajo una vela. Trabaja con las cajas REALES
        // del DOM (no recalculando coordenadas) para que lo que se compara sea exactamente lo que
        // se ve. Devuelve las cajas donde quedaron, para que las etiquetas también los respeten.
        function apartarNumerosMuni(escalaPin) {
            var cajas = [];
            // getBoundingClientRect da coordenadas de VENTANA; el reparto de etiquetas trabaja en
            // coordenadas del CONTENEDOR del mapa. Se resta el origen del contenedor para que ambos
            // hablen el mismo idioma (antes coincidían solo si el mapa arrancaba pegado a 0,0).
            var org = el.getBoundingClientRect();
            function aCont(r) {
                return { x1: r.left - org.left, y1: r.top - org.top, x2: r.right - org.left, y2: r.bottom - org.top };
            }
            // El div del pin mide siempre 20×26: el scale() del CSS va en el <svg> de dentro y no
            // cambia esa caja de layout. Se encoge aquí a mano (desde la PUNTA, como hace
            // transform-origin) para que los números esquiven el pin que de verdad se ve, que es el
            // mismo que reservan las etiquetas.
            var e = escalaPin || 1, pines = [];
            el.querySelectorAll('.mapa-vela').forEach(function (n) {
                var b = aCont(n.getBoundingClientRect()), cx = (b.x1 + b.x2) / 2, w = (b.x2 - b.x1) * e;
                pines.push({ x1: cx - w / 2, x2: cx + w / 2, y1: b.y2 - (b.y2 - b.y1) * e, y2: b.y2 });
            });
            muniNumeros.eachLayer(function (mk) {
                if (!mk._icon) return;
                mk._icon.style.marginLeft = '0px'; mk._icon.style.marginTop = '0px'; // partir de su sitio
                var b = aCont(mk._icon.getBoundingClientRect()), r = MUNI_NUM_R;
                // Si el usuario lo colocó a mano, se respeta tal cual: el esquive automático solo
                // actúa sobre los que siguen en el centro del municipio.
                var d = muniNumPos[mk._muniKey] ? [0, 0] : esquivarNumero(b.x1 + r, b.y1 + r, r, pines, 1);
                if (d[0] || d[1]) { mk._icon.style.marginLeft = d[0] + 'px'; mk._icon.style.marginTop = d[1] + 'px'; }
                cajas.push({ x1: b.x1 + d[0], y1: b.y1 + d[1], x2: b.x2 + d[0], y2: b.y2 + d[1] });
            });
            return cajas;
        }
        map.on('zoomend moveend', function () { declutterVelas(); }); // moveend: recalcula tras asentarse la vista (carga/fit)
        // Al cambiar de escala las cajitas y sus líneas se recolocan (zoomend). Durante la
        // animación del zoom se ocultan: si no, la línea se estira y se despega de la vela
        // hasta que termina el recálculo.
        map.on('zoomstart', function () { etqCapa.clearLayers(); });

        function oleoRenderLista() {
            actualizarLeyenda(); // mantiene sincronizada la tabla-leyenda del mapa
            var cont = document.getElementById('oleoLista');
            if (!cont) return;
            var grupos = gruposOrdenados(); // mismo orden que la leyenda y la foto
            if (!grupos.length) { cont.innerHTML = '<div class="oleo-vacio">Sin frentes aún. Busca un lugar y vincúlalo a un frente.</div>'; return; }
            cont.innerHTML = grupos.map(function (o) {
                var act = String(oleoActivo) === String(o.id);
                return '<div class="oleo-item' + (act ? ' oleo-item-activo' : '') + '" data-id="' + o.id + '">' +
                    '<span class="oleo-dot" style="background:' + o.color + '"></span>' +
                    '<span class="oleo-nom">' + esc(o.nombre) + '</span>' +
                    '<span class="oleo-cnt">' + (o.puntos ? o.puntos.length : 0) + '</span>' +
                    (PUEDE_EDITAR ? '<button class="oleo-del" title="Borrar" data-del="' + o.id + '">&times;</button>' : '') +
                '</div>';
            }).join('');
        }

        // Guarda un punto en el proyecto de un FRENTE (el backend find-or-crea el oleoducto de
        // ese frente) + redibuja. Si el proyecto se creó ahora, lo dibuja; si ya existía, agrega
        // el punto y redibuja. cb(ok).
        // idFrente = null → ubicación SUELTA (sin proyecto): va al endpoint del grupo reservado.
        function oleoGuardarPuntoFrente(idFrente, latlng, nombre, cb) {
            var color = OLEO_PALETA[Object.keys(oleoMap).length % OLEO_PALETA.length];
            var url = idFrente ? ('/mapa/oleoductos/frente/' + idFrente + '/puntos') : '/mapa/oleoductos/puntos';
            spinOn();
            oleoApi(url, 'POST',
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
                    if (window.showToast) window.showToast(idFrente ? 'Ubicación guardada en el frente.' : 'Ubicación guardada sin frente.', 'success');
                    if (cb) cb(true);
                } else { if (window.showToast) window.showToast('No se pudo guardar la ubicación.', 'error'); if (cb) cb(false); }
            }).catch(function () { spinOff(); if (cb) cb(false); });
        }

        // ── Selector de FRENTE con recomendaciones ──────────────────────────────────
        // Lo usan los DOS popups que piden un proyecto: "Guardar punto" y "Agregar a otro
        // proyecto". Antes solo lo tenía el de guardar; el otro pintaba una lista fija de
        // botones y por eso solo dejaba elegir entre proyectos que YA tenían puntos.
        //
        //   pickFrenteHtml(cfg) → markup      cfg: { valor, etiqueta, placeholder }
        //   pickFrenteWire(cont, cfg) → api   cfg: { conSuelto, excluir:[ids], alElegir }
        //
        // api.valor() resuelve el frente elegido: el de la lista o, si se tecleó el nombre
        // exacto sin tocar la lista, el que coincida. '' si no hay nada válido.
        function pickFrenteHtml(cfg) {
            cfg = cfg || {};
            return '<div class="oleo-save-pick">' +
                '<input type="hidden" class="oleo-save-frente" value="' + esc(cfg.valor || '') + '">' +
                '<input type="text" class="oleo-save-search" autocomplete="off"' +
                    ' placeholder="' + esc(cfg.placeholder || 'Escribe para buscar el frente…') + '"' +
                    ' value="' + esc(cfg.etiqueta || '') + '">' +
                '<div class="oleo-save-list"></div>' +
            '</div>';
        }
        // Mensaje de error del popup (.oleo-save-err). Devuelve la función que lo pinta o lo
        // limpia. Los dos popups que piden proyecto lo tenían escrito igual por separado.
        function errorPopup(cont) {
            var err = cont.querySelector('.oleo-save-err');
            return function (msg) { if (err) { err.textContent = msg || ''; err.style.display = msg ? '' : 'none'; } };
        }
        // La lista de sugerencias es position:absolute y casi siempre sobresale del popup.
        // Al elegir una opción se esconde en el pointerdown, así que cuando el usuario SUELTA
        // el botón el cursor ya está sobre el MAPA: el navegador dispara ese clic contra el
        // mapa y Leaflet cierra el popup entero (closePopupOnClick) antes de que se pueda
        // pulsar "Agregar al proyecto". No sirve apagar map.options.closePopupOnClick: Leaflet
        // engancha su handler al abrir el popup y ya no lo relee.
        //
        // Se traga ESE clic huérfano y solo ese: en fase de captura para llegar antes que
        // Leaflet, y dejando pasar cualquier clic que sí caiga dentro del popup (si no, el
        // propio botón dejaría de funcionar). Se desarma solo, y el timeout es la red por si
        // el usuario arrastra fuera y el clic nunca llega.
        function tragarClicHuerfano() {
            var contMapa = map && map.getContainer ? map.getContainer() : null;
            if (!contMapa) return;
            var quitar;
            var tragar = function (ev) {
                if (ev.target && ev.target.closest && ev.target.closest('.leaflet-popup')) return;
                ev.stopPropagation();
                quitar();
            };
            quitar = function () {
                clearTimeout(tmr);
                contMapa.removeEventListener('click', tragar, true);
            };
            var tmr = setTimeout(quitar, 700);
            contMapa.addEventListener('click', tragar, true);
        }

        function pickFrenteWire(cont, cfg) {
            cfg = cfg || {};
            var pick = cont.querySelector('.oleo-save-pick'); if (!pick) return null;
            var hid    = pick.querySelector('.oleo-save-frente');
            var search = pick.querySelector('.oleo-save-search');
            var list   = pick.querySelector('.oleo-save-list');

            var excluir  = (cfg.excluir || []).map(String);
            var opciones = oleoFrentes.filter(function (f) { return excluir.indexOf(String(f.id)) === -1; });
            // "Sin proyecto" es una opción más de la lista (siempre la primera) donde aplica.
            var OP_SUELTO = { id: SUELTO_ID, nombre: SUELTO_LABEL };

            function renderSug() {
                var term = search.value || '', arr;
                if (window.FuzzySearch && window.FuzzySearch.rank) {
                    arr = window.FuzzySearch.rank(opciones, term, function (f) { return { label: f.nombre, haystack: f.nombre }; });
                } else {
                    var q = term.toLowerCase();
                    arr = opciones.filter(function (f) { return !q || String(f.nombre).toLowerCase().indexOf(q) > -1; });
                }
                var h = cfg.conSuelto
                    ? '<div class="oleo-save-op oleo-save-op-sin" data-fid="' + SUELTO_ID + '">' + esc(SUELTO_LABEL) + '</div>'
                    : '';
                arr.slice(0, 8).forEach(function (f) { h += '<div class="oleo-save-op" data-fid="' + esc(String(f.id)) + '">' + esc(f.nombre) + '</div>'; });
                if (!h) h = '<div class="oleo-save-op oleo-save-op-vacio">Sin coincidencias</div>';
                list.innerHTML = h;
                list.style.display = 'block';
            }
            function valor() {
                if (hid.value) return hid.value;
                var t = (search.value || '').trim().toLowerCase();
                if (!t) return '';
                var m = opciones.concat(cfg.conSuelto ? [OP_SUELTO] : []).filter(function (f) {
                    return String(f.nombre).toLowerCase() === t;
                })[0];
                return m ? String(m.id) : '';
            }

            search.addEventListener('focus', renderSug);
            search.addEventListener('input', function () { hid.value = ''; renderSug(); if (cfg.alElegir) cfg.alElegir(''); });
            search.addEventListener('blur',  function () { setTimeout(function () { list.style.display = 'none'; }, 150); });
            // 'pointerdown' y NO 'mousedown': en pantalla táctil el navegador retrasa los
            // eventos de ratón hasta ~300 ms después de levantar el dedo, así que el blur del
            // campo (que esconde la lista a los 150 ms) llegaba ANTES y el toque caía en el
            // vacío — la lista "se quitaba" y no se elegía nada. pointerdown llega enseguida
            // con dedo y con ratón. Fallback a mousedown para navegadores sin Pointer Events.
            var EV_ELEGIR = ('onpointerdown' in window) ? 'pointerdown' : 'mousedown';
            list.addEventListener(EV_ELEGIR, function (e) {
                var op = e.target.closest ? e.target.closest('.oleo-save-op') : null;
                if (!op || op.classList.contains('oleo-save-op-vacio')) return;
                e.preventDefault();           // no mover el foco: el campo sigue activo
                hid.value    = op.getAttribute('data-fid') || '';
                search.value = op.textContent;
                list.style.display = 'none';
                tragarClicHuerfano();
                if (cfg.alElegir) cfg.alElegir(hid.value);
            });

            return {
                valor:   valor,
                enfocar: function () { search.focus(); renderSug(); }
            };
        }

        // Popup tras buscar/colocar una ubicación. AMBOS campos son OBLIGATORIOS: el NOMBRE del
        // punto y el PROYECTO (se elige de tus FRENTES de trabajo con un buscador que recomienda;
        // no se crean a mano). Al Guardar, el punto se PERSISTE en ese frente y aparece en la
        // leyenda. Si hay un proyecto activo, preselecciona SU frente (para cargar varios puntos
        // seguidos al mismo).
        // Popup PEQUEÑO tras una búsqueda: muestra el lugar y un botón "Guardar punto". El
        // formulario completo (oleoPopupGuardar) solo se abre si el usuario pulsa el botón —
        // antes se abría solo, de una. La lógica del botón está en el handler 'popupopen'.
        function oleoPopupPreguntar(latlng, nombreSugerido) {
            var nom = nombreSugerido || '';
            var titulo = nom || (latlng.lat.toFixed(5) + ', ' + latlng.lng.toFixed(5));
            var html = '<div class="oleo-ask">' +
                '<div class="oleo-ask-loc">📍 ' + esc(titulo) + '</div>' +
                '<button type="button" class="oleo-ask-btn" data-nom="' + esc(nom) + '">' +
                    '<i class="material-icons">add_location_alt</i>Guardar punto</button>' +
                '</div>';
            L.popup({ className: 'mapa-oleo-pop', minWidth: 180, autoPan: true }).setLatLng(latlng).setContent(html).openOn(map);
        }
        function oleoPopupGuardar(latlng, nombreSugerido) {
            var coords = latlng.lat.toFixed(6) + ', ' + latlng.lng.toFixed(6);
            // Sin permiso 'super.admin' → solo consulta: se muestra la coordenada, sin formulario.
            if (!PUEDE_EDITAR) {
                var htmlRO = '<div class="oleo-save"><div class="oleo-save-c">' + coords + '</div>' +
                    '<div style="font-size:11.5px;color:#64748b;line-height:1.35;margin-top:2px;">Solo lectura. Para guardar puntos necesitas el permiso de gestión del mapa.</div></div>';
                L.popup({ className: 'mapa-oleo-pop', minWidth: 220, autoPan: true }).setLatLng(latlng).setContent(htmlRO).openOn(map);
                return;
            }
            var activo = (oleoActivo && oleoMap[oleoActivo]) ? oleoMap[oleoActivo].data : null;
            // Igual que se preselecciona el frente activo, si lo último que guardaste fue una
            // ubicación suelta se deja marcado "sin proyecto" (para cargar varias seguidas).
            var sueltoActivo = !!(activo && activo.suelto);
            var frenteActivo = activo ? activo.id_frente : null;
            var faObj = frenteActivo ? oleoFrentes.filter(function (f) { return String(f.id) === String(frenteActivo); })[0] : null;
            // Selector de proyecto tipo BUSCADOR (recomienda al escribir), no un <select> plano.
            var html = '<div class="oleo-save">' +
                '<label class="oleo-save-lbl">Nombre del punto <span class="oleo-req">*</span></label>' +
                '<input type="text" class="oleo-save-in" placeholder="Ej. PROGRESIVA 47+100" value="' + esc(nombreSugerido || '') + '">' +
                '<div class="oleo-save-c">' + coords + '</div>' +
                '<label class="oleo-save-lbl">Frente de trabajo <span class="oleo-req">*</span></label>' +
                pickFrenteHtml({
                    valor:    sueltoActivo ? SUELTO_ID : (frenteActivo ? String(frenteActivo) : ''),
                    etiqueta: sueltoActivo ? SUELTO_LABEL : (faObj ? faObj.nombre : '')
                }) +
                '<button type="button" class="oleo-save-btn">Guardar punto</button>' +
                '<div class="oleo-save-err" style="display:none;"></div>' +
                '</div>';
            L.popup({ className: 'mapa-oleo-pop', minWidth: 240, autoPan: true }).setLatLng(latlng).setContent(html).openOn(map);
        }

        // Lógica del popup al abrirse: buscador de proyecto con recomendaciones + validación de los
        // dos campos obligatorios (nombre del punto y proyecto) antes de persistir con "Guardar".
        map.on('popupopen', function (ev) {
            var cont = ev.popup.getElement(); if (!cont) return;
            // Popup PEQUEÑO de "¿guardar?": su botón abre el formulario completo en la misma
            // coordenada (y conserva la vela mientras se llena).
            var ask = cont.querySelector('.oleo-ask-btn');
            if (ask) {
                var llAsk = ev.popup.getLatLng();
                ask.addEventListener('click', function () {
                    oleoPopupGuardar(llAsk, ask.getAttribute('data-nom') || '');
                    marcarBusqueda(llAsk);
                });
                return;
            }
            // El popup de "agregar a otro proyecto" comparte el estilo .oleo-save-btn, asi que
            // se descarta por su clase PROPIA: ese lo atiende su propio handler mas abajo.
            var btn   = cont.querySelector('.oleo-save-btn');
            var input = cont.querySelector('.oleo-save-in');
            if (!btn || !input || btn.classList.contains('oleo-vinc-btn')) return;

            var mostrarErr = errorPopup(cont);
            if (input) setTimeout(function () { input.focus(); }, 30);

            // conSuelto: aquí "sin proyecto" SÍ es una opción (guarda la ubicación suelta).
            // Los listeners se enganchan sin guarda: el popup se crea de cero en cada apertura.
            var picker = pickFrenteWire(cont, { conSuelto: true, alElegir: function () { mostrarErr(''); } });
            if (!picker) return;

            // AMBOS campos son OBLIGATORIOS: nombre del punto + una opción de la lista (un
            // frente o SUELTO_LABEL, que guarda el punto sin frente).
            btn.addEventListener('click', function () {
                var nombre = ((input && input.value) || '').trim();
                if (!nombre) { mostrarErr('Escribe el nombre del punto.'); if (input) input.focus(); return; }
                var elegido = picker.valor();
                if (!elegido) { mostrarErr('Elige una opción de la lista.'); picker.enfocar(); return; }
                var idFrente = (elegido === SUELTO_ID) ? '' : elegido; // vacío = sin frente
                mostrarErr('');
                var ll = ev.popup.getLatLng();
                btn.disabled = true; btn.textContent = 'Guardando…';
                oleoGuardarPuntoFrente(idFrente, ll, nombre, function (ok) {
                    if (ok) { marcarBusqueda(null); map.closePopup(); }
                    else { btn.disabled = false; btn.textContent = 'Guardar punto'; }
                });
            });
        });

        // Panel de control "Proyectos": botón (timeline) que abre la lista de proyectos. Va en la
        // barra TOP-LEFT junto a "Dibujar" (se agrega al mapa más abajo, tras DibujarCtrl).
        var OleoCtrl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                // mapa-ctrl-mobile-hide: oculto en teléfono (ver FitVE arriba).
                var wrap = L.DomUtil.create('div', 'oleo-ctrl mapa-ctrl-mobile-hide');
                wrap.innerHTML =
                    '<button type="button" class="oleo-toggle" title="Frentes de trabajo (puntos unidos por una línea)"><i class="material-icons">timeline</i></button>' +
                    '<div class="oleo-panel" style="display:none;">' +
                        '<div class="oleo-panel-h">Frentes de trabajo</div>' +
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
                        if (!confirm('¿Borrar este frente del mapa y todos sus puntos?')) return;
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
                            spinOff(); if (window.showToast) window.showToast('Frente borrado.', 'success');
                        }).catch(function () { spinOff(); });
                        return;
                    }
                    var item = e2.target.closest ? e2.target.closest('.oleo-item') : null;
                    if (item) { oleoActivo = item.getAttribute('data-id'); oleoRenderLista(); }
                });
                return wrap;
            }
        });
        // OleoCtrl (Proyectos) se agrega al mapa MÁS ABAJO (tras DibujarCtrl) para que su botón
        // quede JUNTO al de "Dibujar" en la barra top-left.

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
                else { if (window.showToast) window.showToast('Selecciona en el panel Frentes de trabajo (arriba a la izquierda) el frente a editar.', 'error'); abrirPanelOleo(); return; }
            }
            entrarDibujo(oleoActivo);
        }
        function entrarDibujo(id) {
            if (!id || !oleoMap[id]) { if (window.showToast) window.showToast('Selecciona un frente primero.', 'error'); return; }
            if (edMode) return;
            var o = oleoMap[id].data;
            // Las ubicaciones sin frente son puntos sueltos, no un tendido: NO se les traza línea
            // (el backend rechaza igual el recorrido de ese grupo).
            if (o.suelto) { if (window.showToast) window.showToast('Los puntos sin frente no llevan línea.', 'error'); return; }
            var base = (o.recorrido && o.recorrido.length >= 2)
                ? edSubmuestrear(o.recorrido, 24).map(function (c) { return L.latLng(c[0], c[1]); })
                : puntosOrdenados(o).map(function (p) { return L.latLng(p.lat, p.lng); });
            if (base.length < 2) { if (window.showToast) window.showToast('Agrega al menos 2 puntos al frente para trazar la línea.', 'error'); return; }
            edMode = true; edId = id; edPts = base;
            if (oleoMap[id]) { (oleoMap[id].lines || []).forEach(function (l) { map.removeLayer(l); }); } // oculta la tubería normal mientras se edita
            edRender();
            mostrarBarraDibujo();
            var panel = document.querySelector('.oleo-panel'); if (panel) panel.style.display = 'none';
        }
        function salirDibujo() {
            edMode = false;
            edQuitarCapas();
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
                '<button type="button" class="mapa-ctx-item" data-a="editar"><i class="material-icons">edit</i>Editar Línea</button>' +
                '<button type="button" class="mapa-ctx-item" data-a="eliminar"><i class="material-icons">delete</i>Eliminar Línea</button>';
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
            if (!confirm('¿Eliminar la línea (recorrido) de este frente? Los puntos se conservan.')) return;
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
        function menuVela(ev, oleoId, punto) {
            if (ev.originalEvent) { ev.originalEvent.preventDefault(); }
            L.DomEvent.stop(ev);
            document.querySelectorAll('.mapa-ctx-menu').forEach(function (m) { m.remove(); });
            var menu = document.createElement('div'); menu.className = 'mapa-ctx-menu';
            // Si el punto está compartido se dice en cuántos proyectos está: así queda claro que
            // "quitar" solo lo saca de ESTE, no lo borra de los demás.
            var enVarios = (punto.proyectos || 1) > 1;
            menu.innerHTML =
                '<div class="mapa-ctx-title">' + esc(punto.nombre || 'Punto') +
                    (enVarios ? '<span class="mapa-ctx-sub">En ' + punto.proyectos + ' proyectos</span>' : '') + '</div>' +
                '<button type="button" class="mapa-ctx-item" data-a="vinc"><i class="material-icons">playlist_add</i>Agregar a otro proyecto</button>' +
                '<button type="button" class="mapa-ctx-item mapa-ctx-danger" data-a="del"><i class="material-icons">delete_outline</i>' +
                    (enVarios ? 'Quitar de este proyecto' : 'Eliminar Punto') + '</button>';
            var x = ev.originalEvent ? ev.originalEvent.clientX : 0, y = ev.originalEvent ? ev.originalEvent.clientY : 0;
            menu.style.left = Math.min(x, window.innerWidth - 200) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 100) + 'px';
            (document.fullscreenElement || document.body).appendChild(menu);
            menu.addEventListener('click', function (e2) {
                var b = e2.target.closest ? e2.target.closest('.mapa-ctx-item') : null; if (!b) return;
                menu.remove();
                var a = b.getAttribute('data-a');
                if (a === 'del') eliminarPunto(oleoId, punto);
                else if (a === 'vinc') popupVincularProyecto(punto);
            });
            var cerrar = function () { menu.remove(); document.removeEventListener('click', cerrar); };
            setTimeout(function () { document.addEventListener('click', cerrar); }, 0);
        }
        // Mete un punto QUE YA EXISTE en otro proyecto. Se elige entre TODOS los frentes con el
        // mismo buscador que "Guardar punto" (se escribe y recomienda). Antes era una lista fija
        // con los proyectos que YA tenían puntos: como un proyecto solo nace al guardar su primer
        // punto, no había forma de compartir un punto con un frente que aún no tuviera ninguno.
        // Se excluyen los frentes donde el punto ya está; las ubicaciones sueltas no se comparten.
        var _vincPunto = null;   // punto del popup "agregar a otro proyecto" que está abierto
        function popupVincularProyecto(punto) {
            // Frentes donde YA está: se sacan de los proyectos que lo contienen (los sueltos no
            // tienen frente, así que se caen solos al no aportar id_frente).
            var yaEn = Object.keys(oleoMap).map(function (id) { return oleoMap[id].data; })
                .filter(function (o) { return (o.puntos || []).some(function (p) { return String(p.id) === String(punto.id); }); })
                .map(function (o) { return String(o.id_frente || ''); })
                .filter(function (x) { return x !== ''; });

            if (oleoFrentes.filter(function (f) { return yaEn.indexOf(String(f.id)) === -1; }).length === 0) {
                if (window.showToast) window.showToast('Este punto ya está en todos tus frentes.', 'info');
                return;
            }
            var html = '<div class="oleo-save">' +
                '<label class="oleo-save-lbl">Agregar "' + esc(punto.nombre || 'Punto') + '" a:</label>' +
                pickFrenteHtml({ placeholder: 'Escribe para buscar el proyecto…' }) +
                '<button type="button" class="oleo-save-btn oleo-vinc-btn">Agregar al proyecto</button>' +
                '<div class="oleo-save-err" style="display:none;"></div>' +
                '<div style="font-size:11px;color:#64748b;line-height:1.35;margin-top:6px;">' +
                'Es el MISMO punto, no una copia: si se corrige, cambia en todos los proyectos.</div>' +
                '</div>';
            // ANTES de abrir, no después: openOn() dispara 'popupopen' de forma SÍNCRONA, así
            // que el handler lee esto durante la propia llamada. Dejándolo debajo, encontraba
            // null y cerraba el popup en el acto.
            _vincPunto = { punto: punto, yaEn: yaEn };
            L.popup({ className: 'mapa-oleo-pop', minWidth: 240, autoPan: true })
                .setLatLng(L.latLng(punto.lat, punto.lng)).setContent(html).openOn(map);
        }

        // Popup de "agregar a otro proyecto": buscador de frentes + botón.
        map.on('popupopen', function (ev) {
            var cont = ev.popup.getElement(); if (!cont) return;
            var btn = cont.querySelector('.oleo-vinc-btn'); if (!btn) return;
            // Lo que dejó popupVincularProyecto. Se consume aquí y se borra: es un traspaso de
            // una sola vez, no un estado que deba quedar vivo. El punto viaja por aquí y no se
            // busca por coordenada, que podía enganchar OTRO punto en la misma coordenada.
            var ctx = _vincPunto; _vincPunto = null;
            if (!ctx || !ctx.punto) { map.closePopup(); return; }
            var punto = ctx.punto;
            var mostrarErr = errorPopup(cont);

            // Sin conSuelto: una ubicación suelta no es un proyecto que compartir.
            var picker = pickFrenteWire(cont, { excluir: ctx.yaEn, alElegir: function () { mostrarErr(''); } });
            if (!picker) return;
            picker.enfocar();

            btn.addEventListener('click', function () {
                var idFrente = picker.valor();
                if (!idFrente) { mostrarErr('Elige un proyecto de la lista.'); picker.enfocar(); return; }
                mostrarErr('');
                btn.disabled = true; btn.textContent = 'Agregando…';
                spinOn();
                // Color por si el frente todavía no tenía proyecto y hay que crearlo: el mismo
                // criterio de paleta que al guardar un punto nuevo.
                var color = OLEO_PALETA[Object.keys(oleoMap).length % OLEO_PALETA.length];
                oleoApi('/mapa/oleoductos/frente/' + idFrente + '/puntos/' + punto.id + '/vincular', 'POST', { color: color })
                    .then(function (res) {
                    spinOff();
                    if (!res || !res.success) {
                        btn.disabled = false; btn.textContent = 'Agregar al proyecto';
                        if (window.showToast) window.showToast('No se pudo agregar el punto.', 'error');
                        return;
                    }
                    map.closePopup();
                    var oid = res.oleoducto_id;
                    if (res.oleoducto_nuevo) {                 // el frente no tenía proyecto: nace aquí
                        res.oleoducto_nuevo.puntos = [res.punto];
                        oleoDibujar(res.oleoducto_nuevo);
                    } else if (oleoMap[oid]) {
                        oleoMap[oid].data.puntos.push(res.punto);
                        oleoDibujar(oleoMap[oid].data);
                    }
                    sincronizarConteoProyectos(punto.id, res.punto.proyectos);
                    oleoRenderLista();
                    if (window.showToast) window.showToast('Punto agregado al proyecto.', 'success');
                }).catch(function () {
                    spinOff();
                    btn.disabled = false; btn.textContent = 'Agregar al proyecto';
                    if (window.showToast) window.showToast('No se pudo agregar el punto.', 'error');
                });
            });
        });

        // Quita el punto DE ESE proyecto. Si estaba compartido sigue en los demás; si era su
        // último proyecto, el backend lo borra del todo y responde borrado = true.
        function eliminarPunto(oleoId, punto) {
            var enVarios = (punto.proyectos || 1) > 1;
            var aviso = enVarios
                ? '¿Quitar "' + (punto.nombre || 'este punto') + '" de este proyecto?' + '\n\n' +
                  'Seguirá en los otros ' + (punto.proyectos - 1) + ' proyecto(s) donde está.'
                : '¿Eliminar este punto? No se puede deshacer.';
            if (!confirm(aviso)) return;
            spinOn();
            oleoApi('/mapa/oleoductos/' + oleoId + '/puntos/' + punto.id, 'DELETE').then(function (res) {
                spinOff();
                if (!res || !res.success) { if (window.showToast) window.showToast('No se pudo quitar el punto.', 'error'); return; }
                // Se quita SOLO del proyecto del que se desvinculó; si el backend lo borró del todo
                // (era su último proyecto), entonces sí se quita de todos.
                Object.keys(oleoMap).forEach(function (id) {
                    if (!res.borrado && String(id) !== String(oleoId)) return;
                    var pts = oleoMap[id].data.puntos || [], idx = -1;
                    for (var i = 0; i < pts.length; i++) { if (String(pts[i].id) === String(punto.id)) { idx = i; break; } }
                    if (idx > -1) { pts.splice(idx, 1); oleoDibujar(oleoMap[id].data); }
                });
                // Al quedar en menos proyectos, las copias que sobreviven deben reflejar el conteo.
                if (!res.borrado) sincronizarConteoProyectos(punto.id, res.quedan);
                oleoRenderLista(); // refresca la leyenda
                if (window.showToast) window.showToast(res.borrado ? 'Punto eliminado.' : 'Punto quitado de este proyecto.', 'success');
            }).catch(function () { spinOff(); if (window.showToast) window.showToast('No se pudo quitar el punto.', 'error'); });
        }

        // Mantiene al día el "en cuántos proyectos está" de un punto en TODAS sus copias en memoria.
        function sincronizarConteoProyectos(puntoId, n) {
            Object.keys(oleoMap).forEach(function (id) {
                (oleoMap[id].data.puntos || []).forEach(function (p) {
                    if (String(p.id) === String(puntoId)) p.proyectos = n;
                });
            });
        }

        // ══════════════════════════════════════════════════════════════════════
        //  TABLA-LEYENDA (historial) + EXPORTAR IMAGEN
        //  - Leyenda transparente (abajo-izq): proyectos que TIENEN puntos.
        //  - Exportar: arma un PNG nítido del encuadre actual, a la escala de
        //    pantalla, en el tamaño de hoja elegido, con escala gráfica + leyenda.
        // ══════════════════════════════════════════════════════════════════════
        // Orden en que se PRESENTAN los grupos (panel, leyenda y foto). Se decide aquí y no en
        // el backend porque el orden de la API se pierde: oleoMap se indexa por id y
        // Object.keys devuelve las claves numéricas ordenadas por NÚMERO, no por inserción.
        // Frentes alfabéticos y el grupo sin frente SIEMPRE al final (no es un frente).
        function gruposOrdenados() {
            return Object.keys(oleoMap).map(function (id) { return oleoMap[id].data; })
                .sort(function (a, b) {
                    if (!!a.suelto !== !!b.suelto) return a.suelto ? 1 : -1;
                    return String(a.nombre || '').localeCompare(String(b.nombre || ''), 'es');
                });
        }
        function proyectosConPuntos() {
            return gruposOrdenados().filter(function (o) { return o.puntos && o.puntos.length > 0; });
        }
        // Reglas de la LEYENDA compartidas por la pantalla y la foto, para que no diverjan:
        // los puntos van por su campo `orden`, y el rótulo "Coordenadas" lo lleva el primer
        // frente DESPLEGADO (si fuera el primero a secas y estuviera recogido, quedaría
        // rotulando una columna sin ninguna coordenada debajo).
        function puntosOrdenados(o) {
            return (o.puntos || []).slice().sort(function (a, b) { return (a.orden || 0) - (b.orden || 0); });
        }
        function indiceCoordHead(items) {
            return items.findIndex(function (o) { return !proyColapsados[o.id]; });
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
                if (muniVisible(e, m)) out.push({ municipio: m, estado: e, _f: fe });
            });
            // Numerar de ARRIBA a ABAJO: por latitud del centro visual (norte→sur) y, a igual
            // altura, de oeste a este. Así el nº1 es el más alto en pantalla, el 2 el siguiente,
            // etc. (antes se numeraba en el orden crudo del GeoJSON, sin criterio visual).
            out.sort(function (a, b) {
                var ca = centroVisualFeature(a._f), cb = centroVisualFeature(b._f);
                var la = ca ? ca.lat : 0, lb = cb ? cb.lat : 0;
                if (lb !== la) return lb - la;          // mayor latitud primero (más arriba)
                return (ca ? ca.lng : 0) - (cb ? cb.lng : 0); // desempate: oeste→este
            });
            out.forEach(function (mu, i) { mu.num = i + 1; delete mu._f; });
            return out;
        }

        // Estado de plegado de la leyenda: toda la leyenda + los puntos de cada proyecto.
        // Arranca RECOGIDA (solo la cabecera "Leyenda" + su botón) en TODOS los dispositivos:
        // expandida tapaba parte del mapa al abrir. Un clic en el botón la despliega. Solo es
        // el estado INICIAL — si el usuario la abre, no se la volvemos a cerrar.
        var legendColapsada = true;
        var proyColapsados = {};      // id de proyecto → true si sus puntos están RECOGIDOS en la leyenda
        var proyOcultos = {};         // id de proyecto → true si está OCULTO del mapa (ojo tachado)
        // Un proyecto oculto no dibuja ni pin, ni etiqueta, ni tubería (en el mapa y en la foto).
        // Sirve para ver uno solo: se ocultan los demás. Sigue en la leyenda, para volver a verlo.
        function proyOculto(o) { return !!proyOcultos[o.id]; }
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
                    if (del) {
                        e.stopPropagation();
                        var oid = del.getAttribute('data-ptoleo'), pid = del.getAttribute('data-ptdel');
                        var pt = (((oleoMap[oid] || {}).data || {}).puntos || []).filter(function (x) { return String(x.id) === String(pid); })[0];
                        if (pt) eliminarPunto(oid, pt);
                        return;
                    }
                    if (e.target.closest && e.target.closest('[data-descargar]')) { e.stopPropagation(); descargarLeyendaSola(); return; }
                    if (e.target.closest && e.target.closest('[data-fold="all"]')) { legendColapsada = !legendColapsada; actualizarLeyenda(); return; }
                    var ver = e.target.closest && e.target.closest('[data-ver]');
                    if (ver) {
                        e.stopPropagation();
                        var vid = ver.getAttribute('data-ver');
                        proyOcultos[vid] = !proyOcultos[vid];
                        declutterVelas(true); // aplica el cambio en el mapa (pines/tuberías/etiquetas)
                        actualizarLeyenda();  // refresca el icono del ojo
                        return;
                    }
                    var pr = e.target.closest && e.target.closest('[data-proy]');
                    if (pr) { var id = pr.getAttribute('data-proy'); proyColapsados[id] = !proyColapsados[id]; actualizarLeyenda(); }
                });
            }

            // Cabecera con botón para recoger/expandir toda la leyenda.
            var html = '<div class="mapa-leyenda-head">' +
                '<span class="mapa-leyenda-titulo">Leyenda</span>' +
                '<span class="mapa-leyenda-acciones">' +
                '<button type="button" class="mapa-leyenda-fold" data-descargar="1" title="Descargar solo la leyenda (PNG)">' +
                    '<i class="material-icons">file_download</i></button>' +
                '<button type="button" class="mapa-leyenda-fold" data-fold="all" title="' + (legendColapsada ? 'Expandir leyenda' : 'Recoger leyenda') + '">' +
                    '<i class="material-icons">' + (legendColapsada ? 'expand_more' : 'expand_less') + '</i></button>' +
                '</span></div>';

            // El cuerpo SIEMPRE se arma, aunque esté recogido: colapsado se le pone la clase
            // -plegado (alto 0 + overflow oculto en el CSS) en vez de quitarlo del DOM, así el
            // contenido sigue marcando el ANCHO y la leyenda recogida mide igual que expandida.
            html += '<div class="mapa-leyenda-body' + (legendColapsada ? ' mapa-leyenda-body-plegado' : '') + '">';
            if (items.length) {
                html += '<div class="mapa-leyenda-t">Frentes de trabajo</div>';
                // Sin fila de cabecera propia: "Coordenadas" rotula la columna desde la MISMA
                // línea del frente, así no se gasta un renglón en un encabezado suelto (y "Punto"
                // sobraba: los nombres ya se leen como tales). Va en el primer frente DESPLEGADO,
                // no en el primero a secas: si ese está recogido, el rótulo quedaría sin ninguna
                var idxCoordHead = indiceCoordHead(items);
                items.forEach(function (o, iO) {
                    var col = !!proyColapsados[o.id];
                    var oculto = !!proyOcultos[o.id];
                    html += '<div class="mapa-leyenda-row mapa-leyenda-proy' + (oculto ? ' mapa-leyenda-proy-oculto' : '') + '" data-proy="' + o.id + '" title="' + (col ? 'Mostrar puntos' : 'Recoger puntos') + '">' +
                        '<button type="button" class="mapa-leyenda-ver" data-ver="' + o.id + '" title="' + (oculto ? 'Mostrar en el mapa' : 'Ocultar del mapa') + '">' +
                            '<i class="material-icons">' + (oculto ? 'visibility_off' : 'visibility') + '</i></button>' +
                        '<span class="mapa-leyenda-nom">' + esc(o.nombre) + '</span>' +
                        (iO === idxCoordHead ? '<span class="mapa-leyenda-coord-head">Coordenadas</span>' : '') +
                        '<i class="material-icons mapa-leyenda-chevron">' + (col ? 'chevron_right' : 'expand_more') + '</i></div>';
                    if (!col) {
                        puntosOrdenados(o).forEach(function (p) {
                            html += '<div class="mapa-leyenda-pt"><span class="mapa-leyenda-pt-n">' + esc(p.nombre || 'Punto') + '</span>' +
                                '<span class="mapa-leyenda-pt-c">' + p.lat.toFixed(5) + ', ' + p.lng.toFixed(5) + '</span>' +
                                // Sin permiso no hay botón, pero el hueco se reserva igual: si no,
                                // las coordenadas se corren a la derecha y dejan de cuadrar con el
                                // rótulo "COORDENADAS" de la fila del frente.
                                (PUEDE_EDITAR
                                    ? '<button type="button" class="mapa-leyenda-pt-del" data-ptdel="' + p.id + '" data-ptoleo="' + o.id + '" title="' + ((p.proyectos || 1) > 1 ? 'Quitar de este proyecto' : 'Eliminar Punto') + '">&times;</button>'
                                    : '<span class="mapa-leyenda-pt-del"></span>') + '</div>';
                        });
                    }
                });
            }
            if (munis.length) {
                html += '<div class="mapa-leyenda-t mapa-leyenda-t2">Municipios</div>';
                // Con varios municipios se reparten en DOS columnas para que la leyenda no
                // quede tan larga verticalmente (rellena de arriba a abajo: 1-N | N+1-...).
                html += '<div class="mapa-leyenda-munis' + (munis.length > 6 ? ' dos-col' : '') + '">';
                munis.forEach(function (mu) {
                    html += '<div class="mapa-leyenda-row">' +
                        '<span class="mapa-leyenda-num">' + mu.num + '</span>' +
                        '<span class="mapa-leyenda-nom">' + esc(nombreBonito(mu.municipio)) + '</span></div>';
                });
                html += '</div>';
            }
            html += '</div>';
            d.innerHTML = html;
        }

        // ── Buscador de BLOQUES (arriba-derecha, bajo los botones de capa) ────────────────
        // Aparte del buscador de lugares (ese es el geocoder de arriba-izquierda): este solo
        // busca dentro de los bloques ya cargados y solo existe mientras la capa está encendida.
        // Recomienda al escribir con el MISMO ranking del resto de la app (window.FuzzySearch).
        var _busBloques = null;   // { caja, input, lista } una vez creado el control
        var BUS_MAX = 8;          // sugerencias visibles: más no caben sin tapar el mapa

        // Muestra u oculta el buscador según esté la capa de bloques (y limpia lo escrito).
        function sincronizarBuscadorBloques() {
            if (!_busBloques) return;
            var visible = !!(capaBloques.on && capaBloques.capa && capaBloques.capa.indice);
            _busBloques.caja.style.display = visible ? '' : 'none';
            if (!visible) { _busBloques.input.value = ''; cerrarListaBloques(); limpiarMarcaBloque(); }
        }
        function cerrarListaBloques() {
            if (!_busBloques) return;
            _busBloques.lista.innerHTML = '';
            _busBloques.lista.classList.remove('abierta');
            _busBloques._sug = null; // si no, el Enter saltaría a una sugerencia ya descartada
        }
        // Quita la marca del bloque encontrado (al buscar otro, al limpiar o al apagar la capa).
        function limpiarMarcaBloque() {
            if (!_bloqueMarcado) return;
            if (capaBloques.capa) capaBloques.capa.resetStyle(_bloqueMarcado);
            _bloqueMarcado.closeTooltip();
            _bloqueMarcado = null;
        }
        // Lleva el mapa al bloque elegido y lo deja MARCADO en amarillo, con su ficha abierta,
        // hasta que se busque otro o se limpie el buscador.
        function irABloque(item) {
            if (!item || !item.poligono.getBounds) return;
            limpiarMarcaBloque();
            _bloqueMarcado = item.poligono;
            item.poligono.setStyle(estiloBloqueMarcado);
            item.poligono.bringToFront(); // que no lo tape el borde de un bloque vecino
            // La ficha se abre al TERMINAR el encuadre: durante la animación Leaflet la coloca con
            // las coordenadas viejas y queda descuadrada. El once() va ANTES del fitBounds porque
            // en un salto grande Leaflet no anima y dispara moveend en el acto: registrado
            // después, el handler no llegaba a tiempo y la ficha no se abría nunca.
            map.once('moveend', function () {
                if (_bloqueMarcado === item.poligono) item.poligono.openTooltip(item.poligono.getBounds().getCenter());
            });
            map.fitBounds(item.poligono.getBounds(), { maxZoom: 11, padding: [40, 40] });
        }
        function renderSugBloques() {
            if (!_busBloques) return;
            var term = _busBloques.input.value || '';
            var indice = (capaBloques.capa && capaBloques.capa.indice) || [];
            _busBloques.caja.classList.toggle('con-texto', !!term);
            if (!term.trim()) { cerrarListaBloques(); return; }
            var arr;
            if (window.FuzzySearch && window.FuzzySearch.rank) {
                arr = window.FuzzySearch.rank(indice, term, function (b) { return { label: b.titulo, haystack: b.buscable }; });
            } else {
                var q = term.toLowerCase();
                arr = indice.filter(function (b) { return b.buscable.toLowerCase().indexOf(q) > -1; });
            }
            if (!arr.length) {
                _busBloques.lista.innerHTML = '<div class="mapa-bloque-vacio">Sin bloques que coincidan</div>';
                _busBloques.lista.classList.add('abierta');
                _busBloques._sug = null;
                return;
            }
            _busBloques.lista.innerHTML = arr.slice(0, BUS_MAX).map(function (b, i) {
                return '<div class="mapa-bloque-item" data-i="' + i + '"><b>' + esc(b.titulo) + '</b>' +
                       (b.sub ? '<span>' + esc(b.sub) + '</span>' : '') + '</div>';
            }).join('');
            _busBloques.lista.classList.add('abierta');
            _busBloques._sug = arr.slice(0, BUS_MAX); // lo que se está mostrando, para el clic/Enter
        }
        var BuscadorBloquesCtrl = L.Control.extend({
            options: { position: 'topright' },
            onAdd: function () {
                var caja = L.DomUtil.create('div', 'mapa-bloque-buscador mapa-ctrl-mobile-hide');
                caja.style.display = 'none';
                caja.innerHTML =
                    '<div class="mapa-bloque-in">' +
                        '<i class="material-icons">search</i>' +
                        '<input type="text" placeholder="Buscar bloque…" autocomplete="off">' +
                        '<button type="button" class="mapa-bloque-x" title="Limpiar"><i class="material-icons">close</i></button>' +
                    '</div><div class="mapa-bloque-lista"></div>';
                L.DomEvent.disableClickPropagation(caja);
                L.DomEvent.disableScrollPropagation(caja);
                _busBloques = { caja: caja, input: caja.querySelector('input'), lista: caja.querySelector('.mapa-bloque-lista') };
                _busBloques.input.addEventListener('input', renderSugBloques);
                _busBloques.input.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') { _busBloques.input.value = ''; renderSugBloques(); limpiarMarcaBloque(); }
                    // Enter = la primera sugerencia, sin tener que apuntar con el ratón.
                    else if (e.key === 'Enter' && _busBloques._sug && _busBloques._sug.length) { irABloque(_busBloques._sug[0]); cerrarListaBloques(); }
                });
                caja.querySelector('.mapa-bloque-x').addEventListener('click', function () {
                    _busBloques.input.value = ''; renderSugBloques(); limpiarMarcaBloque(); _busBloques.input.focus();
                });
                _busBloques.lista.addEventListener('click', function (e) {
                    var it = e.target.closest && e.target.closest('.mapa-bloque-item');
                    if (!it || !_busBloques._sug) return;
                    irABloque(_busBloques._sug[+it.getAttribute('data-i')]);
                    cerrarListaBloques();
                });
                return caja;
            }
        });
        if (fajaBloquesUrl) map.addControl(new BuscadorBloquesCtrl());

        // ── Leyenda PROPIA de la Faja (panel aparte del de los frentes) ──────────────────
        // Tiene lo mismo que aquella: se recoge, se descarga sola en PNG y sale en la foto solo
        // si NO está recogida. Va en su propio panel porque la de frentes lista puntos y
        // municipios: mezclarlas obligaba a recoger las dos a la vez.
        var fajaLegendColapsada = false;
        var fajaLegendClickBound = false;

        function actualizarLeyendaFaja() {
            var d = document.getElementById('mapaLeyendaFaja'); if (!d) return;
            var areas = areasFajaVisibles();
            if (!areas.length) { d.style.display = 'none'; d.innerHTML = ''; return; }
            d.style.display = 'block';
            if (!fajaLegendClickBound) {
                fajaLegendClickBound = true;
                d.addEventListener('click', function (e) {
                    if (e.target.closest && e.target.closest('[data-descargar]')) { e.stopPropagation(); descargarLeyendaFajaSola(); return; }
                    if (e.target.closest && e.target.closest('[data-fold]')) { fajaLegendColapsada = !fajaLegendColapsada; actualizarLeyendaFaja(); }
                });
            }
            var html = '<div class="mapa-leyenda-head">' +
                '<span class="mapa-leyenda-titulo">Faja Petrolífera del Orinoco</span>' +
                '<span class="mapa-leyenda-acciones">' +
                '<button type="button" class="mapa-leyenda-fold" data-descargar="1" title="Descargar solo esta leyenda (PNG)">' +
                    '<i class="material-icons">file_download</i></button>' +
                '<button type="button" class="mapa-leyenda-fold" data-fold="1" title="' + (fajaLegendColapsada ? 'Expandir' : 'Recoger') + '">' +
                    '<i class="material-icons">' + (fajaLegendColapsada ? 'expand_more' : 'expand_less') + '</i></button>' +
                '</span></div>';
            // El cuerpo se arma siempre (recogido solo se le pone la clase -plegado), para que el
            // panel mida igual abierto que cerrado. Mismo criterio que la leyenda de frentes.
            html += '<div class="mapa-leyenda-body' + (fajaLegendColapsada ? ' mapa-leyenda-body-plegado' : '') + '">';
            areas.forEach(function (a) {
                html += '<div class="mapa-leyenda-row">' +
                    '<span class="mapa-leyenda-color" style="background:' + a.color + '"></span>' +
                    '<span class="mapa-leyenda-nom">' + esc(a.nombre) + '</span></div>';
            });
            html += '</div>';
            d.innerHTML = html;
        }

        var LeyendaFajaCtrl = L.Control.extend({
            options: { position: 'bottomleft' },
            onAdd: function () {
                var d = L.DomUtil.create('div', 'mapa-leyenda');
                d.id = 'mapaLeyendaFaja'; d.style.display = 'none';
                L.DomEvent.disableClickPropagation(d);
                return d;
            }
        });
        map.addControl(new LeyendaFajaCtrl()); // debajo de la leyenda de frentes, sobre los créditos

        // Botón de descarga (arriba-izq, junto al buscador/globo/pantalla completa).
        var ExportarCtrl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                // mapa-ctrl-mobile-hide: oculto en teléfono (ver FitVE arriba).
                var btn = L.DomUtil.create('button', 'mapa-fit-btn mapa-ctrl-mobile-hide');
                btn.type = 'button';
                btn.title = 'Descargar imagen del mapa';
                btn.innerHTML = '<i class="material-icons">photo_camera</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', abrirDialogoExport);
                return btn;
            }
        });
        map.addControl(new ExportarCtrl());

        // Botón OJO (arriba-izq): oculta/muestra los rótulos de proyecto y punto. Con los
        // rótulos ocultos, el dato del punto sale al pasar el mouse por su vela (ver `soloVelas`
        // en colocarEtiquetas). Visible para TODOS: es preferencia de vista, no edición.
        var OjoCtrl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                // mapa-ctrl-mobile-hide: oculto en teléfono, igual que el resto de la barra.
                var btn = L.DomUtil.create('button', 'mapa-fit-btn mapa-ctrl-mobile-hide');
                btn.type = 'button';
                btn.innerHTML = '<i class="material-icons"></i>';
                var sync = function () {
                    btn.classList.toggle('activo', soloVelas);
                    btn.title = soloVelas
                        ? 'Mostrar nombres de proyectos y puntos'
                        : 'Ocultar nombres (solo velas; el dato sale al pasar el mouse)';
                    btn.querySelector('.material-icons').textContent = soloVelas ? 'visibility_off' : 'visibility';
                };
                sync();
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', function () {
                    soloVelas = !soloVelas;
                    sync();
                    declutterVelas(true); // re-renderiza velas/rótulos con el nuevo estado
                });
                return btn;
            }
        });
        map.addControl(new OjoCtrl());

        // Botón LÁPIZ (arriba-izq): dibuja a mano el recorrido/curva de la tubería.
        var DibujarCtrl = L.Control.extend({
            options: { position: 'topleft' },
            onAdd: function () {
                // mapa-ctrl-mobile-hide: oculto en teléfono (ver FitVE arriba). Dibujar a
                // mano una curva con el dedo, sobre un mapa que también hace pan/zoom, no
                // es usable en pantalla chica; se hace desde escritorio.
                var btn = L.DomUtil.create('button', 'mapa-fit-btn mapa-ctrl-mobile-hide');
                btn.type = 'button';
                btn.title = 'Dibujar tubería a mano (curva)';
                btn.innerHTML = '<i class="material-icons">gesture</i>';
                L.DomEvent.disableClickPropagation(btn);
                L.DomEvent.on(btn, 'click', iniciarDibujo);
                return btn;
            }
        });
        if (PUEDE_EDITAR) map.addControl(new DibujarCtrl()); // dibujar la línea: solo con permiso
        map.addControl(new OleoCtrl()); // "Proyectos" (timeline) — JUNTO a Dibujar en la barra top-left

        // Exportación con MARCO DE RECORTE: muestra un recuadro (aspecto de la hoja) para
        // cuadrar; se exporta EXACTAMENTE lo que quede dentro del marco.
        var EXPORT_MM = { carta: [279, 216], a4: [297, 210] };
        // "Personalizado": el usuario da el tamaño EXACTO en píxeles y la imagen sale con esas
        // medidas justas. El resto de la lógica es la misma que la de Carta/A4 (k para el tamaño
        // de los rótulos, teselas al zoom que toque); lo único distinto es cómo se dimensiona el
        // marco de recorte: a ESCALA FIJA en vez de estirarlo hasta llenar la pantalla (ver
        // EXPORT_PERS_REF y ajustarFrameExport).
        var EXPORT_PX_MIN = 200, EXPORT_PX_MAX = 8000;
        // Tamaño de referencia de "Personalizado": ESTOS píxeles son los que llenan el área
        // visible del mapa. Cualquier otro tamaño se dibuja a esa misma escala, así el campo de
        // ancho mueve solo el ANCHO del marco y el de alto solo su ALTO.
        var EXPORT_PERS_REF = [3600, 2400];
        var expPersW = 3840, expPersH = 2160;
        var expTamSel = 'carta', expOriSel = 'horizontal', expDetalleSel = false;
        // Orientación solo aplica a las hojas: en "Pantalla" manda lo que se ve y en
        // "Personalizado" el propio ancho×alto ya la define.
        function expUsaOrientacion(tam) {
            var t = (tam === undefined) ? expTamSel : tam;
            return t !== 'pantalla' && t !== 'personalizado';
        }
        function abrirDialogoExport() {
            cerrarDialogoExport();
            var frame = document.createElement('div'); frame.id = 'mapaExportFrame'; frame.className = 'mapa-export-frame';
            frame.innerHTML = '<span class="mapa-export-frame-lbl">Área a exportar — mueve/zoom el mapa para cuadrar</span>';
            el.appendChild(frame);
            var bar = document.createElement('div'); bar.id = 'mapaExportBar'; bar.className = 'mapa-export-bar';
            var opt = function (val, txt, sel) { return '<option value="' + val + '"' + (sel === val ? ' selected' : '') + '>' + txt + '</option>'; };
            // DOS bloques: los CAMPOS (se reparten en varias líneas si no caben) y los BOTONES,
            // que van siempre AL LADO. Antes todo colgaba directo de la barra con flex-wrap y, al
            // aparecer el campo "Tamaño (px)" de Personalizado, Cancelar/Descargar se iban a una
            // segunda línea debajo de todo.
            bar.innerHTML =
                '<div class="mapa-export-fields">' +
                    '<label class="mapa-export-field">Tamaño de hoja' +
                        '<select id="expTam">' +
                            opt('pantalla', 'Pantalla (tal cual)', expTamSel) +
                            opt('carta', 'Carta', expTamSel) + opt('a4', 'A4', expTamSel) +
                            opt('personalizado', 'Personalizado', expTamSel) +
                        '</select>' +
                    '</label>' +
                    '<label class="mapa-export-field">Orientación' +
                        '<select id="expOri">' +
                            opt('horizontal', 'Horizontal', expOriSel) + opt('vertical', 'Vertical', expOriSel) +
                        '</select>' +
                    '</label>' +
                    // Solo visible con "Personalizado": ancho × alto en píxeles de la imagen final.
                    '<label class="mapa-export-field mapa-export-pers" style="display:none;">Tamaño (px)' +
                        '<span class="mapa-export-pers-in">' +
                            '<input type="number" id="expPersW" min="' + EXPORT_PX_MIN + '" max="' + EXPORT_PX_MAX + '" step="10" value="' + expPersW + '">' +
                            '<i>×</i>' +
                            '<input type="number" id="expPersH" min="' + EXPORT_PX_MIN + '" max="' + EXPORT_PX_MAX + '" step="10" value="' + expPersH + '">' +
                        '</span>' +
                    '</label>' +
                    '<label class="mapa-export-check" title="Sin marcar: la foto sale IGUAL a lo que ves en pantalla. Marcado: más nitidez y más detalle. No aplica al tamaño «Pantalla».">' +
                        '<input type="checkbox" id="expDetalle"' + (expDetalleSel ? ' checked' : '') + '>' +
                        '<span>Más detalle</span>' +
                    '</label>' +
                '</div>' +
                '<div class="mapa-export-acts">' +
                    '<button type="button" class="mapa-dibujo-btn mapa-export-cancel">Cancelar</button>' +
                    '<button type="button" class="mapa-dibujo-btn primary mapa-export-go">Descargar</button>' +
                '</div>';
            (document.fullscreenElement || el).appendChild(bar);
            L.DomEvent.disableClickPropagation(bar); L.DomEvent.disableScrollPropagation(bar);
            var oriSel = bar.querySelector('#expOri');
            var persBox = bar.querySelector('.mapa-export-pers');
            var persW = bar.querySelector('#expPersW'), persH = bar.querySelector('#expPersH');
            var detChk = bar.querySelector('#expDetalle');
            // Muestra/oculta los campos de tamaño y activa la orientación según el tipo elegido.
            var sincTipo = function () {
                oriSel.disabled = !expUsaOrientacion();
                persBox.style.display = (expTamSel === 'personalizado') ? '' : 'none';
                // "Más detalle" solo tiene efecto fuera del modo "Pantalla" (ver exportarImagen):
                // ahí se deshabilita en vez de dejar una casilla que no hace nada.
                detChk.disabled = (expTamSel === 'pantalla');
                detChk.parentElement.style.opacity = detChk.disabled ? '0.45' : '';
            };
            var leerPers = function () {
                var lim = function (v, def) {
                    v = Math.round(parseFloat(v));
                    if (!isFinite(v)) return def;
                    return Math.max(EXPORT_PX_MIN, Math.min(EXPORT_PX_MAX, v));
                };
                expPersW = lim(persW.value, expPersW);
                expPersH = lim(persH.value, expPersH);
                persW.value = expPersW; persH.value = expPersH; // refleja el valor ya acotado
                ajustarFrameExport();
            };
            bar.querySelector('#expTam').addEventListener('change', function () { expTamSel = this.value; sincTipo(); ajustarFrameExport(); });
            // 'change' y no 'input': acotar mientras se teclea impide escribir (al poner "1" de
            // "1920" saltaría al mínimo). Se valida al salir del campo o pulsar Enter.
            persW.addEventListener('change', leerPers);
            persH.addEventListener('change', leerPers);
            oriSel.addEventListener('change', function () { expOriSel = this.value; ajustarFrameExport(); });
            detChk.addEventListener('change', function () { expDetalleSel = this.checked; });
            sincTipo();
            bar.querySelector('.mapa-export-cancel').addEventListener('click', cerrarDialogoExport);
            bar.querySelector('.mapa-export-go').addEventListener('click', function () { exportarImagen(expTamSel, expOriSel, expDetalleSel); });
            // Recoloca el marco si el usuario hace scroll o cambia el tamaño de la ventana.
            window.addEventListener('scroll', ajustarFrameExport, { passive: true });
            window.addEventListener('resize', ajustarFrameExport);
            ajustarFrameExport();
        }
        // Dimensiona y CENTRA el marco de recorte en la parte del mapa que el usuario ve en
        // pantalla: las hojas se estiran lo más GRANDE que quepa con su proporción; "Personalizado"
        // va a escala fija (ver EXPORT_PERS_REF) y "Pantalla" cubre todo lo visible.
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
            var maxW = vw * 0.96;
            var maxH = visH - 92;          // deja hueco para la barra inferior y el rótulo
            var fw, fh;
            if (expTamSel === 'personalizado') {
                // ESCALA FIJA px-de-exportación → px-de-pantalla: el campo de ancho mueve solo el
                // ancho del marco y el de alto solo su alto (más px = marco más grande = más mapa
                // dentro de la foto). Antes el marco se estiraba hasta llenar el área visible
                // respetando solo la PROPORCIÓN, y como esa área es apaisada el alto quedaba
                // siempre clavado en su máximo: los dos campos acababan moviendo únicamente el
                // ancho y el recorte vertical no cambiaba nunca.
                var f = Math.min(maxW / EXPORT_PERS_REF[0], maxH / EXPORT_PERS_REF[1]);
                // Tope: por muchos px que se pidan, el marco no puede salirse de lo que se ve.
                f = Math.min(f, maxW / expPersW, maxH / expPersH);
                fw = expPersW * f; fh = expPersH * f;
            } else {
                // Hojas: el marco se estira todo lo que cabe, con la proporción de sus milímetros
                // según la orientación.
                var mm = EXPORT_MM[expTamSel] || EXPORT_MM.carta;
                var aspect = (expOriSel === 'vertical') ? mm[1] / mm[0] : mm[0] / mm[1]; // ancho/alto
                if (maxW / maxH > aspect) { fh = maxH; fw = fh * aspect; } else { fw = maxW; fh = fw / aspect; }
            }
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
        // Dibuja la VELA (pin de gota, igual que velaIcon) con la PUNTA en (x, y).
        function dibujarVela(ctx, x, y, k, color) {
            color = color || '#0067b1';
            var s = k * VELA_W / 24; // el SVG mide 24×32 en su viewBox → mismo tamaño que en pantalla
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
            // Bulbo blanco + LOGO de la empresa recortado en círculo: lo MISMO que velaIcon()
            // dibuja en pantalla (antes el export ponía solo un puntito blanco y la foto salía
            // distinta a lo que se ve en el mapa).
            ctx.beginPath(); ctx.arc(12, 11.4, 7.8, 0, Math.PI * 2); ctx.fillStyle = '#ffffff'; ctx.fill();
            if (logoImg) {
                ctx.save();
                ctx.beginPath(); ctx.arc(12, 11.4, 7.5, 0, Math.PI * 2); ctx.clip();
                // "contain" dentro del círculo, igual que preserveAspectRatio="xMidYMid meet".
                var cw = 11.6, ch = 11, r = Math.min(cw / logoImg.width, ch / logoImg.height);
                var iw = logoImg.width * r, ih = logoImg.height * r;
                ctx.drawImage(logoImg, 12 - iw / 2, 11.4 - ih / 2, iw, ih);
                ctx.restore();
            } else {
                ctx.beginPath(); ctx.arc(12, 11.4, 5, 0, Math.PI * 2); ctx.fillStyle = color; ctx.fill();
            }
            ctx.restore();
        }
        // Etiqueta FIJA de la vela en el canvas: cajita blanca con el nombre del PROYECTO (azul)
        // y debajo una línea por cada PUNTO. La POSICIÓN (`caja`) la decide
        // repartirEtiquetas(), la misma que usa la pantalla, así la foto sale igual a lo que se ve.
        // `k` aquí es la escala del TEXTO (kEtq): debe ser la misma con la que se midió la caja.
        // Líneas en la FOTO: idénticas a pantalla (misma geometría, curvaEtiqueta).
        function dibujarLineasEtq(ctx, r, k) {
            ctx.save(); ctx.lineCap = 'round'; ctx.lineJoin = 'round';
            var trazo = function (dibujo) {
                ctx.beginPath(); dibujo();
                ctx.globalAlpha = ETQ_BORDE_OP;
                ctx.strokeStyle = ETQ_BORDE_COLOR; ctx.lineWidth = ETQ_BORDE_W * k; ctx.stroke();
                ctx.globalAlpha = 0.97;
                ctx.strokeStyle = ETQ_TRAZO_COLOR; ctx.lineWidth = ETQ_TRAZO_W * k; ctx.stroke();
                ctx.globalAlpha = 1;
            };
            r.cajas.forEach(function (c) {
                var b = curvaEtiqueta(r, c, k);
                trazo(function () {
                    ctx.moveTo(b.desde[0], b.desde[1]);
                    ctx.bezierCurveTo(b.c1[0], b.c1[1], b.c2[0], b.c2[1], b.hasta[0], b.hasta[1]);
                });
            });
            ctx.restore();
        }
        function dibujarEtiquetaVela(ctx, caja, proyectos, puntos, k) {
            var padX = 5 * k, fProj = 8.5 * k, fPt = 8 * k;   // cuadran con .vela-label del CSS
            ctx.save();
            rrect(ctx, caja.x1, caja.y1, caja.w, caja.h, 6 * k);
            ctx.fillStyle = '#ffffff'; ctx.fill();
            ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
            var ty = caja.y1 + 3 * k;
            ctx.font = '800 ' + fProj + 'px ' + FAM_ETQ; ctx.fillStyle = '#0067b1';
            proyectos.forEach(function (n) {   // una línea azul por proyecto
                ty += fProj;
                ctx.fillText(String(n).toUpperCase(), caja.x1 + padX, ty);
                ty += 2.5 * k;
            });
            ctx.font = '600 ' + fPt + 'px ' + FAM_ETQ; ctx.fillStyle = '#0f172a';
            var lineas = lineasPunto(puntos);
            // Hueco que deja la viñeta antes del nombre: su ancho + los 3px que el CSS pone como
            // margin-right en .vela-label b::before, para que la foto salga igual a la pantalla.
            var vinAncho = lineas.length ? ctx.measureText(VINETA_PUNTO).width + 3 * k : 0;
            lineas.forEach(function (l) {   // una línea negra por punto
                ty += fPt;
                if (!l.resumen) ctx.fillText(VINETA_PUNTO, caja.x1 + padX, ty);
                ctx.fillText(l.txt, caja.x1 + padX + (l.resumen ? 0 : vinAncho), ty);
                ty += 2.5 * k;
            });
            ctx.restore();
        }
        // `velas` viene ya calculado por el llamador (lo necesita antes para los números de
        // municipio); `bloqueos` son las cajas de esos números, que las etiquetas deben esquivar.
        function dibujarVectores(ctx, k, proj, velas, bloqueos, kPin) {
            k = k || 1;
            ctx.save();
            ctx.strokeStyle = 'rgba(255,255,255,0.85)'; ctx.lineWidth = 1.4 * k; ctx.lineJoin = 'round';
            estados.eachLayer(function (layer) { if (layer.getLatLngs) trazarAnillos(ctx, layer.getLatLngs(), proj); });
            // Mismo orden de apilado que en pantalla (la API entrega los grupos con este
            // criterio): con dos tuberías superpuestas, la de encima es la misma en los dos.
            gruposOrdenados().forEach(function (o) {
                if (proyOculto(o)) return;   // oculto → sin tubería
                if (o.recorrido && o.recorrido.length >= 2) {
                    var line = o.recorrido.map(function (c) { return proj([c[0], c[1]]); });
                    ctx.lineJoin = 'round'; ctx.lineCap = 'round';
                    ctx.beginPath(); line.forEach(function (pt, i) { if (i === 0) ctx.moveTo(pt.x, pt.y); else ctx.lineTo(pt.x, pt.y); });
                    var pw = pesoTuberia();
                    ctx.strokeStyle = '#0a1620'; ctx.lineWidth = pw.borde * k; ctx.stroke();
                    ctx.strokeStyle = o.color; ctx.lineWidth = pw.cuerpo * k; ctx.stroke();
                    ctx.strokeStyle = aclararColor(o.color, 0.65); ctx.lineWidth = pw.brillo * k; ctx.stroke();
                }
            });
            // La foto sale a más resolución que la pantalla y la letra se veía diminuta: el TEXTO
            // de las etiquetas se agranda ETQ_EXPORT_K. Se aplica igual a la medida de la cajita
            // y a la fuente del canvas, si no el texto no cuadraría con la caja reservada.
            var kEtq = k * ETQ_EXPORT_K;
            repartirEtiquetas(velas, kPin, bloqueos, kEtq);
            velas.forEach(function (r) { dibujarVela(ctx, r.x, r.y, kPin, '#0067b1'); }); // pines primero
            // Una cajita por proyecto, cada una con su línea guía al pin: EXACTAMENTE lo mismo
            // que se ve en pantalla (misma geometría: curvaEtiqueta).
            velas.forEach(function (r) {
                dibujarLineasEtq(ctx, r, kPin);
                r.cajas.forEach(function (c) { dibujarEtiquetaVela(ctx, c, c.proys, c.puntos, kEtq); });
            });
            ctx.restore();
        }

        // Agrupa/funde las velas del export EXACTAMENTE igual que declutterVelas en pantalla y
        // decide qué texto lleva cada una. Se calcula aparte (antes de pintar) porque los
        // números de municipio necesitan saber dónde van los pines para apartarse.
        // kPin = escala a la que se DIBUJAN los pines en el canvas. El umbral de fusion va
        // con ella (no con `k`, la escala de diseno de la hoja): asi en la foto dos velas se
        // funden exactamente cuando se pisarian, igual que en pantalla.
        function calcularVelas(proj, kPin) {
            var velas = agruparVelas(function (o) {
                return puntosOrdenados(o).map(function (p) {
                    var pt = proj([p.lat, p.lng]);
                    return { x: pt.x, y: pt.y, nombre: p.nombre || 'Punto' };
                });
            }, VELA_THRESH * kPin);
            // Misma regla que en pantalla (puntosVisibles), para que la foto salga igual.
            velas.forEach(function (r) { r.puntos = puntosVisibles(r); });
            return velas;
        }

        function recortarTexto(ctx, txt, maxW) {
            if (ctx.measureText(txt).width <= maxW) return txt;
            var t = txt;
            while (t.length > 1 && ctx.measureText(t + '…').width > maxW) t = t.slice(0, -1);
            return t + '…';
        }
        // Escala gráfica (abajo-derecha) tipo regla. El ancho es ESCALA_MAX_PX (el mismo del
        // control de la pantalla): con otro ancho máximo la foto elegiría un escalón redondo
        // distinto al que se ve en el mapa. ESCALA_FONT cuadra con .leaflet-control-scale-line.
        var ESCALA_FONT = 12;
        function dibujarEscala(ctx, rightX, bottomY, mppx, k) {
            k = k || 1;
            var maxPx = ESCALA_MAX_PX * k, m = numRedondo(maxPx * mppx), px = m / mppx;
            var label = m >= 1000 ? (m / 1000) + ' km' : m + ' m';
            var x = rightX - px, y = bottomY;
            ctx.save();
            ctx.strokeStyle = 'rgba(0,0,0,0.55)'; ctx.lineWidth = 5 * k; ctx.lineCap = 'butt';
            ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + px, y); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x, y - 9 * k); ctx.lineTo(x, y + 3 * k); ctx.moveTo(x + px, y - 9 * k); ctx.lineTo(x + px, y + 3 * k); ctx.stroke();
            ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 3 * k;
            ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x + px, y); ctx.stroke();
            ctx.beginPath(); ctx.moveTo(x, y - 8 * k); ctx.lineTo(x, y + 2 * k); ctx.moveTo(x + px, y - 8 * k); ctx.lineTo(x + px, y + 2 * k); ctx.stroke();
            ctx.font = 'bold ' + Math.round(ESCALA_FONT * k) + 'px Arial, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
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
        // Cajita de fondo de un panel de leyenda (la misma para el de frentes y el de la Faja).
        // Sobre el mapa es translúcida y el satélite la tiñe de gris; SUELTA (descarga del panel)
        // no hay mapa detrás, así que se pinta OPACA con ese mismo gris — con el azul marino de la
        // versión translúcida el PNG salía azulado y no se parecía a lo que se ve en pantalla.
        function fondoPanelLeyenda(ctx, x, y, W, H, k, suelta) {
            rrect(ctx, x, y, W, H, 12 * k);
            ctx.fillStyle = suelta ? LEYENDA_BG_SOLA : 'rgba(15,23,42,0.62)'; ctx.fill();
            ctx.strokeStyle = 'rgba(255,255,255,0.28)'; ctx.lineWidth = 1 * k; ctx.stroke();
        }
        function dibujarLeyendaCanvas(ctx, x, y, fechaTxt, k, bottomY, soloLeyenda) {
            k = k || 1;
            // Si el usuario tiene la leyenda RECOGIDA en pantalla, tampoco va en la foto.
            if (legendColapsada && !soloLeyenda) return;
            var items = proyectosConPuntos(), munis = municipiosActivos();
            if (!items.length && !munis.length) return;
            var pad = 12 * k, sw = 12 * k, gap = 8 * k;
            var sangriaPt = 19 * k; // = padding-left de .mapa-leyenda-pt en el CSS
            var fT = Math.round(13 * k), fRow = Math.round(13 * k), fPt = Math.round(11 * k), fDate = Math.round(10 * k);
            var rowH = 20 * k, ptH = 15 * k, titleH = 24 * k;
            // ── Sección PROYECTOS (una columna) ──
            var filas = [];
            if (items.length) {
                filas.push({ t: 'titulo', txt: 'FRENTES DE TRABAJO', fecha: fechaTxt });
                var idxCoordHead = indiceCoordHead(items);
                items.forEach(function (o, iO) {
                    // "Coordenadas" rotula la columna desde la línea del frente, sin fila de
                    // cabecera aparte y sin la palabra "Punto".
                    filas.push({ t: 'row', txt: o.nombre, coordHead: iO === idxCoordHead });
                    // Un frente RECOGIDO en pantalla tampoco lista sus puntos en la foto: ya se
                    // respeta el plegado global (legendColapsada), sería incoherente honrar uno
                    // y el otro no, y la leyenda saldría mucho más alta de lo que el usuario ve.
                    if (proyColapsados[o.id]) return;
                    puntosOrdenados(o).forEach(function (p) {
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
                if (fi.t === 'row') {
                    ctx.font = '600 ' + fRow + 'px Arial, sans-serif';
                    var wRow = pad * 2 + ctx.measureText(fi.txt).width;
                    // El primer frente comparte línea con el rótulo "COORDENADAS": si no se suma
                    // aquí, la caja sale corta y el nombre se recorta contra el rótulo.
                    if (fi.coordHead) { ctx.font = 'bold ' + Math.round(fPt * 0.85) + 'px Arial, sans-serif'; wRow += gap + ctx.measureText('COORDENADAS').width; }
                    W = Math.max(W, wRow);
                }
                else if (fi.t === 'pt') { ctx.font = fPt + 'px Arial, sans-serif'; W = Math.max(W, pad * 2 + sangriaPt + ctx.measureText(fi.nom + '    ' + fi.coord).width); }
                else { ctx.font = 'bold ' + fT + 'px Arial, sans-serif'; W = Math.max(W, pad * 2 + ctx.measureText(fi.txt).width + 46 * k); }
            });
            if (munis.length) W = Math.max(W, pad * 2 + muniBlockW);
            W = Math.min(W, cap);

            // ── Alto total ──
            var H = pad * 2;
            filas.forEach(function (fi) { H += (fi.t === 'titulo') ? titleH : (fi.t === 'pt' ? ptH : rowH); });
            if (munis.length) H += titleH + muniRowsPerCol * rowH;
            if (soloLeyenda === 'medir') return { W: W, H: H };  // solo se pidió el tamaño
            if (bottomY != null) y = bottomY - H; // anclar abajo (modo "Pantalla")

            // ── Fondo ──
            ctx.save();
            fondoPanelLeyenda(ctx, x, y, W, H, k, soloLeyenda);
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
                    // Sin cuadrito de color: el nombre del frente arranca en el margen (igual
                    // que en la leyenda de la pantalla).
                    var yTxt = yy + fRow + (rowH - fRow) / 2 - 2 * k;
                    // El primer frente lleva a su derecha el rótulo de la columna de coordenadas.
                    var headW = 0;
                    if (fi.coordHead) {
                        ctx.font = 'bold ' + Math.round(fPt * 0.85) + 'px Arial, sans-serif';
                        ctx.fillStyle = 'rgba(255,255,255,0.55)'; ctx.textAlign = 'right';
                        ctx.fillText('COORDENADAS', x + W - pad, yTxt);
                        headW = ctx.measureText('COORDENADAS').width + gap;
                    }
                    ctx.textAlign = 'left'; ctx.fillStyle = '#fff'; ctx.font = '600 ' + fRow + 'px Arial, sans-serif';
                    ctx.fillText(recortarTexto(ctx, fi.txt, W - pad * 2 - headW), x + pad, yTxt);
                    yy += rowH;
                } else {
                    ctx.font = fPt + 'px Arial, sans-serif';
                    ctx.fillStyle = 'rgba(255,255,255,0.85)';
                    var coordW = fi.coord ? ctx.measureText(fi.coord).width + gap : 0;
                    ctx.textAlign = 'left'; ctx.fillText(recortarTexto(ctx, fi.nom, W - pad * 2 - sangriaPt - coordW), x + pad + sangriaPt, yy + fPt);
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
            return { W: W, H: H, y: y }; // y = tope real, para apilar el panel de la Faja al lado
        }

        // Panel de la leyenda de la FAJA en el canvas: la misma cajita que la de frentes, pero
        // solo con el color y el nombre de cada división. Recogido en pantalla ⇒ no sale en la
        // foto (igual que la otra). `bottomY` la ancla por abajo; 'medir' devuelve solo su tamaño.
        function dibujarLeyendaFajaCanvas(ctx, x, y, k, bottomY, solo) {
            k = k || 1;
            if (fajaLegendColapsada && !solo) return;
            var areas = areasFajaVisibles();
            if (!areas.length) return;
            var pad = 12 * k, sw = 12 * k, gap = 8 * k;
            var fT = Math.round(13 * k), fRow = Math.round(13 * k);
            var rowH = 20 * k, titleH = 24 * k;

            var W = 200 * k;
            ctx.font = 'bold ' + fT + 'px Arial, sans-serif';
            W = Math.max(W, pad * 2 + ctx.measureText('FAJA PETROLÍFERA DEL ORINOCO').width);
            areas.forEach(function (a) {
                ctx.font = '600 ' + fRow + 'px Arial, sans-serif';
                W = Math.max(W, pad * 2 + sw + gap + ctx.measureText(a.nombre).width);
            });
            var H = pad * 2 + titleH + areas.length * rowH;
            if (solo === 'medir') return { W: W, H: H };
            if (bottomY != null) y = bottomY - H;

            ctx.save();
            fondoPanelLeyenda(ctx, x, y, W, H, k, solo);
            ctx.textAlign = 'left'; ctx.textBaseline = 'alphabetic';
            var yy = y + pad;
            ctx.fillStyle = '#fff'; ctx.font = 'bold ' + fT + 'px Arial, sans-serif';
            ctx.fillText('FAJA PETROLÍFERA DEL ORINOCO', x + pad, yy + fT);
            yy += titleH;
            areas.forEach(function (a, i) {
                var cy = yy + i * rowH + (rowH - sw) / 2 - 1 * k;
                ctx.fillStyle = a.color; ctx.fillRect(x + pad, cy, sw, sw);
                // Borde claro: Carabobo es negro y sin él se perdería contra el panel oscuro.
                ctx.strokeStyle = 'rgba(255,255,255,0.85)'; ctx.lineWidth = 1 * k; ctx.strokeRect(x + pad, cy, sw, sw);
                ctx.fillStyle = '#fff'; ctx.font = '600 ' + fRow + 'px Arial, sans-serif';
                ctx.fillText(a.nombre, x + pad + sw + gap, yy + i * rowH + fRow + (rowH - fRow) / 2 - 2 * k);
            });
            ctx.restore();
            return { W: W, H: H, y: y };
        }

        // Descarga un PANEL de leyenda SOLO como PNG (sin el mapa): el mismo dibujo que va en la
        // foto, recortado a su cajita. Lo comparten los dos paneles (frentes y Faja); `dibujar`
        // recibe (ctx, x, y, bottomY, solo) y devuelve {W,H} al medir.
        function descargarPanelLeyenda(dibujar, nombre) {
            var med = dibujar(document.createElement('canvas').getContext('2d'), 0, 0, null, 'medir');
            if (!med) { if (window.showToast) window.showToast('No hay nada en la leyenda todavía.', 'info'); return; }
            var m = 20; // margen transparente fijo, para que no se coma el borde redondeado
            var cv = document.createElement('canvas');
            cv.width = Math.ceil(med.W + m * 2); cv.height = Math.ceil(med.H + m * 2);
            // Sin fondo propio: el margen queda TRANSPARENTE y solo se ve el panel, con el mismo
            // diseño (bordes redondeados + borde blanco) que cuando sale dentro de la foto.
            dibujar(cv.getContext('2d'), m, m, null, true);
            cv.toBlob(function (blob) {
                if (!blob) return;
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = nombre + '_' + new Date().toISOString().slice(0, 10) + '.png';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
            }, 'image/png');
        }
        function descargarLeyendaSola() {
            var k = 2, fecha = new Date().toLocaleDateString('es-VE');
            descargarPanelLeyenda(function (ctx, x, y, bottomY, solo) {
                return dibujarLeyendaCanvas(ctx, x, y, fecha, k, bottomY, solo);
            }, 'leyenda');
        }
        function descargarLeyendaFajaSola() {
            var k = 2;
            descargarPanelLeyenda(function (ctx, x, y, bottomY, solo) {
                return dibujarLeyendaFajaCanvas(ctx, x, y, k, bottomY, solo);
            }, 'leyenda_faja');
        }

        // Créditos sobre el canvas (abajo-izq), escalados.
        // Créditos en su CAJITA BLANCA (igual a .mapa-creditos de la página): etiqueta en negrita
        // + texto normal, con envoltura a un ancho máximo. `bottomY` = borde inferior de la caja.
        // Devuelve la Y del TOPE de la caja (para apilar la leyenda encima en modo "Pantalla").
        function dibujarCreditos(ctx, x, bottomY, k) {
            k = k || 1;
            var padX = 10 * k, padY = 6 * k, fs = Math.round(11 * k), lh = Math.round(fs * 1.35), maxTW = 440 * k;
            var fontB = '800 ' + fs + 'px Arial, sans-serif', fontN = fs + 'px Arial, sans-serif';
            // Construye las líneas (segmentos negrita/normal) envolviendo al ancho máximo.
            // CREDITOS es el mismo texto que la cajita de la pantalla.
            var lineas = [], maxLineW = 0;
            CREDITOS.forEach(function (e) {
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
        // Rellena anillos de polígono (lat/lng) — municipios resaltados y capa petrolera en la
        // foto. `alpha` = opacidad del relleno (0.42 por defecto, el de los municipios) y
        // `colBorde` = color del contorno cuando no es el mismo del relleno (la Faja lo lleva
        // negro con relleno de color).
        function rellenarAnillos(ctx, arr, col, k, proj, alpha, colBorde) {
            if (!arr || !arr.length) return;
            if (arr[0] instanceof L.LatLng) {
                ctx.beginPath();
                for (var i = 0; i < arr.length; i++) { var p = proj(arr[i]); if (i === 0) ctx.moveTo(p.x, p.y); else ctx.lineTo(p.x, p.y); }
                ctx.closePath();
                ctx.globalAlpha = (alpha == null) ? 0.42 : alpha; ctx.fillStyle = col; ctx.fill();
                ctx.globalAlpha = 0.95; ctx.strokeStyle = colBorde || col; ctx.lineWidth = 1.4 * k; ctx.stroke();
                ctx.globalAlpha = 1;
            } else { for (var j = 0; j < arr.length; j++) rellenarAnillos(ctx, arr[j], col, k, proj, alpha, colBorde); }
        }
        // Capa petrolera en la foto: las MISMAS áreas de la Faja y bloques que se ven en pantalla
        // (cada una solo si su botón está encendido). Va antes que los municipios para quedar
        // debajo, igual que en pantalla (fajaPane 458 / bloquesPane 459 < muniIntPane 462).
        // El orden del array es el de los panes (áreas al fondo, bloques encima) y los colores se
        // leen de la PROPIA capa (l.options): así la foto no puede desincronizarse de la pantalla.
        function dibujarFaja(ctx, k, proj) {
            ctx.save(); ctx.lineJoin = 'round';
            var pintar = function (capa) {
                capa.eachLayer(function (l) {
                    if (l.eachLayer) { pintar(l); return; }   // la Faja es un grupo (relleno + contorno)
                    var o = l.options || {};
                    // La copia solo-contorno se salta: en la foto no hay etiquetas por encima que
                    // tapen nada y, sin relleno, se pintaría como una mancha negra.
                    if (!l.getLatLngs || o.fill === false) return;
                    // El punteado del borde (las áreas lo llevan) también va a la foto, a escala.
                    ctx.setLineDash(String(o.dashArray || '').split(/[\s,]+/).filter(Boolean).map(function (n) { return n * k; }));
                    rellenarAnillos(ctx, l.getLatLngs(), o.fillColor || o.color, k, proj, o.fillOpacity, o.color);
                });
            };
            [capaFaja, capaBloques].forEach(function (c) { if (c.on && c.capa) pintar(c.capa); });
            ctx.restore();
        }
        // Municipios ACTIVOS resaltados (color pleno) + su NÚMERO (círculo blanco) en la foto.
        // `pines` = cajas de las velas, para que los números se aparten de ellas.
        // Devuelve las cajas donde quedaron los números.
        function dibujarMunicipios(ctx, k, proj, pines) {
            var cajas = [];
            if (!muniEstado) return cajas;
            ctx.save(); ctx.lineJoin = 'round';
            muniEstado.eachLayer(function (layer) {
                var m = layer.feature && layer.feature.properties && layer.feature.properties.municipio;
                var e = layer.feature && layer.feature.properties && layer.feature.properties.estado;
                if (layer.getLatLngs) rellenarAnillos(ctx, layer.getLatLngs(), colorMuni(m, e), k, proj);
            });
            var byKey = {};
            municipiosActivos().forEach(function (mu) { byKey[muniKey(mu.estado, mu.municipio)] = mu.num; });
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            muniEstado.eachLayer(function (layer) {
                var e = layer.feature.properties.estado, m = layer.feature.properties.municipio;
                var key = muniKey(e, m);
                var num = byKey[key];
                if (!num || !layer.getBounds) return;
                // Misma posición que en pantalla (la que arrastró el usuario o, si no la tocó, el
                // centro visual polylabel) → la foto sale igual a lo que se ve. Coherente con muniNumeros.
                var p = proj(posNumeroMuni(layer, key)), r = MUNI_NUM_R * k;
                // Si una vela le cae encima, el número se aparta — salvo que lo haya puesto el
                // usuario a mano, que entonces manda su posición (igual que en pantalla).
                var d = muniNumPos[key] ? [0, 0] : esquivarNumero(p.x, p.y, r, pines || [], k);
                var cx2 = p.x + d[0], cy2 = p.y + d[1];
                cajas.push({ x1: cx2 - r, x2: cx2 + r, y1: cy2 - r, y2: cy2 + r });
                // Círculo transparente con borde blanco y número blanco (con halo oscuro para legibilidad).
                ctx.beginPath(); ctx.arc(cx2, cy2, r, 0, Math.PI * 2);
                ctx.lineWidth = 3 * k; ctx.strokeStyle = 'rgba(0,0,0,0.5)'; ctx.stroke();
                ctx.lineWidth = 1.6 * k; ctx.strokeStyle = '#ffffff'; ctx.stroke();
                ctx.font = 'bold ' + Math.round(11 * k) + 'px Arial, sans-serif';
                ctx.lineWidth = 3 * k; ctx.strokeStyle = 'rgba(0,0,0,0.75)'; ctx.strokeText(String(num), cx2, cy2);
                ctx.fillStyle = '#ffffff'; ctx.fillText(String(num), cx2, cy2);
            });
            ctx.restore();
            return cajas; // para que las etiquetas de las velas tampoco los tapen
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
            } else if (tam === 'personalizado') {
                // Tamaño EXACTO pedido por el usuario: el PNG sale con estos píxeles justos.
                // La misma k que las hojas, así los rótulos, la leyenda y los trazos guardan
                // la misma proporción que en Carta/A4.
                Pw = expPersW; Ph = expPersH;
                k = Math.max(Pw, Ph) / 1650;
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
            //  · CON "Más detalle" (hojas y tamaño personalizado): sube el zoom → más nítido.
            //  · SIN detalle / "Pantalla": antes se usaba round(Z0). Problema: en un zoom
            //    intermedio (ej. 8.7) redondeaba a 9 (o a 8) y las teselas se REESCALABAN con
            //    drawImage → los NOMBRES de las ubicaciones del mapa base salían borrosos
            //    (más cuanto más lejos el zoom real del entero). Fix: usar el entero SUPERIOR
            //    (ceil) → las teselas traen más resolución nativa y al ajustarse quedan nítidas
            //    en todas las escalas. Cap a 17 (máx del proveedor).
            var zMax = Math.min(17, Math.max(0, Math.ceil(Z0 + Math.log(Pw / fw) / Math.LN2)));
            var zBase = Math.min(17, Math.max(0, Math.ceil(Z0)));
            // max(zBase, zMax): con un tamaño personalizado MÁS CHICO que el marco de pantalla,
            // zMax sale por debajo de zBase y "Más detalle" habría BAJADO el zoom — la foto
            // marcada saldría más borrosa que sin marcar. Nunca por debajo del zoom normal.
            var z = (detalle && !pantalla) ? Math.max(zBase, zMax) : zBase;
            // Los NOMBRES del mapa base (ciudades, carreteras) van SIEMPRE al zoom zBase, aunque
            // el satélite suba a zMax con "Más detalle". El texto de una tesela mide lo mismo en
            // píxeles sea cual sea el zoom, así que al pedirlas a zMax entraban ~Pw/fw veces más
            // teselas en la misma hoja y los nombres salían minúsculos e ilegibles — parecía que
            // ya no se guardaban las ubicaciones. Separando el zoom por capa, la imagen conserva
            // la nitidez extra y los nombres recuperan el tamaño con el que se veían antes.
            var pTL = map.project(b.getNorthWest(), z), pBR = map.project(b.getSouthEast(), z);
            var spanX = pBR.x - pTL.x || 1, scale = Pw / spanX;
            var proj4k = function (ll) {
                var wp = map.project(ll instanceof L.LatLng ? ll : L.latLng(ll[0], ll[1]), z);
                return { x: (wp.x - pTL.x) * scale, y: (wp.y - pTL.y) * scale };
            };
            var TS = 256;

            var canvas = document.createElement('canvas'); canvas.width = Pw; canvas.height = Ph;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#0b1a2b'; ctx.fillRect(0, 0, Pw, Ph);

            // Cada capa calcula su PROPIA geometría a partir de su zoom: satélite y nombres ya no
            // comparten zoom, así que no pueden compartir el recorte de teselas ni la escala.
            var pintarCapa = function (tipo, zc) {
                var cTL = map.project(b.getNorthWest(), zc), cBR = map.project(b.getSouthEast(), zc);
                var cScale = Pw / ((cBR.x - cTL.x) || 1), nc = Math.pow(2, zc);
                var cx0 = Math.floor(cTL.x / TS), cx1 = Math.floor((cBR.x - 0.001) / TS);
                var cy0 = Math.floor(cTL.y / TS), cy1 = Math.floor((cBR.y - 0.001) / TS);
                var tasks = [];
                for (var tx = cx0; tx <= cx1; tx++) {
                    for (var ty = cy0; ty <= cy1; ty++) {
                        if (ty < 0 || ty >= nc) continue;
                        (function (tx, ty) {
                            var dx = (tx * TS - cTL.x) * cScale, dy = (ty * TS - cTL.y) * cScale, ds = TS * cScale + 1;
                            tasks.push(cargarImg(tileURL(zc, tx, ty, tipo)).then(function (img) { if (img) { try { ctx.drawImage(img, dx, dy, ds, ds); } catch (e) {} } }));
                        })(tx, ty);
                    }
                }
                return Promise.all(tasks);
            };

            // logoListo: asegura que el logo de la vela ya esté cargado antes de dibujarla.
            pintarCapa('sat', z).then(function () { return pintarCapa('lbl', zBase); }).then(function () { return logoListo; }).then(function () {
                // Orden: se calculan las velas → los números de municipio se apartan de ellas →
                // las etiquetas de las velas esquivan esos números ya reubicados.
                var kPin4k = k * (vistaLejana() ? ESC_LEJOS : 1);
                var velas4k = calcularVelas(proj4k, kPin4k);
                dibujarFaja(ctx, k, proj4k); // capa petrolera (si está encendida), debajo de todo lo demás
                var cajasNum = dibujarMunicipios(ctx, k, proj4k, cajasPines(velas4k, kPin4k));
                dibujarVectores(ctx, k, proj4k, velas4k, cajasNum, kPin4k);
                var outMppx = 156543.03392 * Math.cos(b.getCenter().lat * Math.PI / 180) / Math.pow(2, z) / scale;
                var fecha = new Date().toLocaleDateString('es-VE');
                // Créditos en su cajita abajo-izquierda (devuelve su tope). En "Pantalla" la leyenda
                // se apila ENCIMA de esa caja (igual que en la pantalla); en hojas va arriba-izquierda.
                var credTop = dibujarCreditos(ctx, 26 * k, Ph - 22 * k, k);
                // Los DOS paneles se apilan en el MISMO orden que en pantalla, donde (de abajo
                // arriba) van créditos → leyenda de frentes → leyenda de la Faja: en las esquinas
                // de ABAJO Leaflet mete cada control con insertBefore, así que el último añadido
                // (la Faja) queda ARRIBA. Si uno no sale (vacío o recogido), el otro ocupa su
                // sitio y no queda hueco.
                var yPila = pantalla ? (credTop - 12 * k) : (26 * k);
                if (pantalla) {
                    // La pila crece hacia ARRIBA desde los créditos → primero el de abajo (frentes).
                    var legFrentes = dibujarLeyendaCanvas(ctx, 26 * k, yPila, fecha, k, yPila);
                    if (legFrentes) yPila = legFrentes.y - 12 * k;
                    dibujarLeyendaFajaCanvas(ctx, 26 * k, yPila, k, yPila);
                } else {
                    // Hoja: crece hacia ABAJO desde la esquina → primero el de arriba (la Faja).
                    var legFaja = dibujarLeyendaFajaCanvas(ctx, 26 * k, yPila, k, null);
                    if (legFaja) yPila = legFaja.y + legFaja.H + 12 * k;
                    dibujarLeyendaCanvas(ctx, 26 * k, yPila, fecha, k, null);
                }
                // Brújula y regla colocadas COMO EN LA PANTALLA: la brújula sola en la esquina
                // (96 px de lado, 10 de margen → su centro cae a 58 del borde) y la regla a su
                // IZQUIERDA, no debajo. Antes la regla se dibujaba pegada al borde derecho y
                // quedaba justo bajo la brújula, encimadas.
                dibujarNorte(ctx, Pw - 58 * k, Ph - 58 * k, k);
                dibujarEscala(ctx, Pw - 128 * k, Ph - 34 * k, outMppx, k); // 10 + 96 de brújula + 22 de aire
                canvas.toBlob(function (blob) {
                    overlayExport(false);
                    if (!blob) { if (window.showToast) window.showToast('No se pudo generar la imagen.', 'error'); return; }
                    var a = document.createElement('a');
                    a.href = URL.createObjectURL(blob);
                    a.download = 'mapa_' + tam + (expUsaOrientacion(tam) ? '_' + ori : '') + '_' + Pw + 'x' + Ph + '_' + new Date().toISOString().slice(0, 10) + '.png';
                    document.body.appendChild(a); a.click(); a.remove();
                    setTimeout(function () { URL.revokeObjectURL(a.href); }, 5000);
                    if (window.showToast) window.showToast('Imagen descargada · ' + tam.toUpperCase() + ' · ' + Pw + '×' + Ph + ' px', 'success');
                }, 'image/png');
            }).catch(function () {
                overlayExport(false);
                if (window.showToast) window.showToast('No se pudo exportar el mapa.', 'error');
            });
        }

        // Cargar los oleoductos existentes y dibujarlos.
        oleoApi('/mapa/oleoductos').then(function (res) {
            // Se dibujan en el MISMO orden que usa la foto (gruposOrdenados): con dos tuberías
            // superpuestas, la que queda encima debe ser la misma en el mapa y en el PNG.
            // Antes la pantalla usaba el orden que trae la API (ordenado en SQL, con la
            // colación de la BD) y la foto lo reordenaba en JS con localeCompare: podían
            // discrepar por acentos o mayúsculas.
            (res && res.oleoductos ? res.oleoductos : []).forEach(function (o) { oleoMap[o.id] = { data: o }; });
            gruposOrdenados().forEach(oleoDibujar);
            oleoRenderLista();
            // Recalcular las etiquetas cuando la escala/vista ya se asentó (el setView inicial no
            // dispara moveend, y la barra de escala se renderiza un instante después).
            setTimeout(function () { declutterVelas(true); }, 300);
            setTimeout(function () { declutterVelas(true); }, 900);
        }).catch(function (e) { console.error('[mapa] fallo al cargar/dibujar los frentes:', e); });

        // Tras insertar el contenedor por SPA, Leaflet puede calcular mal el tamaño;
        // invalidar en el siguiente tick asegura que las teselas llenen el área.
        setTimeout(function () { map.invalidateSize(); }, 60);

        // ── Redimensionamiento (sobre todo en TELÉFONO) ──────────────────────────────
        // Leaflet solo mide el contenedor al montarse: si su alto cambia después, las
        // teselas no se recalculan y quedan franjas grises. En móvil eso pasa seguido —
        // al rotar el teléfono y cada vez que la barra de URL del navegador se oculta o
        // reaparece (nuestro alto es 100dvh, así que se mueve con ella). Observamos el
        // CONTENEDOR, no window: cubre además el teclado virtual y los cambios de layout
        // por SPA, que un listener de 'resize' no ve. Se guarda en obsTam para desconectarlo
        // en el desmontaje (ver alNavegar), junto con el resto.
        // rAF para colapsar la ráfaga de eventos de una rotación en un solo recálculo.
        if (window.ResizeObserver) {
            var rafId = 0;
            obsTam = new ResizeObserver(function () {
                if (rafId) return;
                rafId = requestAnimationFrame(function () {
                    rafId = 0;
                    map.invalidateSize({ pan: false }); // pan:false = no mover el centro
                });
            });
            obsTam.observe(el);
        } else {
            // Navegadores sin ResizeObserver: rotación vía window (orientationchange llega
            // antes de que el layout se asiente, de ahí el respiro).
            window.addEventListener('orientationchange', onOrientacion);
        }

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
