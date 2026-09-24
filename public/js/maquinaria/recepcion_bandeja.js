/*
 * recepcion_bandeja.js
 *
 * Este codigo vivia dentro del HTML de la vista y viajaba entero en CADA apertura del
 * modulo. Aqui se baja una sola vez y el navegador lo reutiliza.
 *
 * Es una FUNCION de arranque, no un bloque suelto, porque el modulo necesita volver a
 * correr en cada apertura para engancharse a la tabla nueva: la SPA no re-ejecuta un
 * <script src> ya cargado, asi que es el Blade quien llama a recepcionBandejaArrancar(CFG)
 * cada vez que se monta la pantalla. Lo que cambia entre una apertura y otra —rutas,
 * permisos y catalogos— llega en ese CFG.
 */
window.recepcionBandejaArrancar = function (RECB_CFG) {
    RECB_CFG = RECB_CFG || {};
(function () {
    'use strict';
    if (!document.getElementById('trTableBody')) return;
    var ROUTE = RECB_CFG.rutaAlmacenRecepcionIndex;
    // Valor del pseudo-estado "sin filtro de estado". Sale del modelo para que el JS no
    // repita el literal 'all' que ya define Traspaso::FILTRO_TODAS.
    var TR_FILTRO_TODAS = RECB_CFG.traspasoFiltroTodas;

    // SPA-safe: este archivo se descarga UNA vez, pero la navegación interna vuelve a
    // llamar a recepcionBandejaArrancar() en cada visita al módulo. Las funciones window.*
    // se redefinen sin problema, PERO los listeners en document/window se DUPLICARÍAN en
    // cada montaje → acciones como classList.toggle correrían 2 veces (la fila se marca y
    // desmarca = "no pasa nada hasta recargar"). Por eso los listeners GLOBALES se
    // registran una sola vez por pestaña (guardia _trBindGlobal); siguen llamando a las
    // window.* más recientes, así no quedan obsoletos. Las funciones sí se redefinen en
    // cada montaje (operan sobre el DOM vivo, que es nuevo).
    var _trBindGlobal = !window.__trRecepcionGlobalBound;
    window.__trRecepcionGlobalBound = true;

    // Lista de N° de nota visibles para el usuario (TR-YYYY-NNNN). Cargada desde
    // el controller en cada render; 300 más recientes — suficiente para el
    // autocomplete sin pedir un endpoint extra.
    var TR_NUMEROS = RECB_CFG.numerosNotas;

    // Catálogo para el buscador por PRODUCTO (con equivalencias) y para el modal de compra
    // directa. ANTES viajaba embebido en el HTML: 1439 productos = 214 KB y ~48 ms de
    // servidor en CADA apertura. Ahora arranca vacío y se trae por AJAX del endpoint
    // compartido (misma fuente, listaAutocomplete), igual que el inventario, la bitácora y
    // la entrada por ODC. Los dos sitios que lo leen lo hacen AL TECLEAR, así que no
    // bloquea nada.
    //
    // El guard es por SESIÓN, no por montaje: el módulo se abre y se cierra muchas veces en
    // la navegación interna y la lista no cambia entre aperturas — sin él se pediría otra
    // vez en cada entrada.
    window.trProductosLista = window.trProductosLista || [];
    (function () {
        if (window.trProductosCargados || window.trProductosCargando) return;
        if (!RECB_CFG.urlProductos) return;
        window.trProductosCargando = true;
        window.apiFetch(RECB_CFG.urlProductos, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (lista) {
                window.trProductosLista = Array.isArray(lista) ? lista : [];
                window.trProductosCargados = true;
            })
            .catch(function () { /* silencioso: el buscador queda vacío y se reintenta al volver */ })
            .finally(function () { window.trProductosCargando = false; });
    })();

    // KPI activo del panel "Resumen de la bandeja": '' | 'por_revisar' | 'recientes' |
    // 'urgentes'. Solo 'recientes'/'urgentes' se mandan al backend (filtro datetime);
    // 'por_revisar' = vista default de pendientes (sin parámetro extra).
    var _trKpi = '';

    function el(id) { return document.getElementById(id); }
    function v(id) { var e = el(id); return e ? String(e.value).trim() : ''; }
    // Lectura de los hidden inputs de los custom-dropdown (por atributo data-filter-value).
    // Necesario para el dropdown "Almacén destino" del header (#trDestHeaderDropdown),
    // que no tiene un <select> tradicional. Patrón calcado de /admin/almacen/movimientos.
    function hv(name) { var e = document.querySelector('input[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    // ── Autocomplete del filtro "N° de nota" ──────────────────────────────
    // Mismo comportamiento que los buscadores de Inventario / Equipos: las sugerencias
    // se calculan en el cliente (lista TR_NUMEROS ya cargada) → instantáneas. La tabla
    // NO se filtra al escribir; se filtra cuando el usuario:
    //   (a) elige una sugerencia de la lista [trSearchPick],
    //   (b) pulsa Enter [trSearchEnter], o
    //   (c) limpia con la X [trSearchClear].

    // Muestra/actualiza la lista de sugerencias al instante. Al hacer FOCO con el campo
    // vacío muestra las más recientes (como las listas rápidas de Equipos); con texto,
    // filtra por substring. No toca la tabla.
    window.trSearchSuggest = function () {
        var input = el('trSearch');
        var box   = el('trSearchSuggest');
        if (!input || !box) return;
        // 12 sugerencias, el mismo tope que el buscador de nota de /almacen/movimientos
        // (antes 8 aquí: con dos notas del mismo día la lista se cortaba antes de tiempo).
        var TOPE = 12;
        var q = String(input.value || '').trim().toUpperCase();
        var matches = (q === '' ? TR_NUMEROS
            : TR_NUMEROS.filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; })
        ).slice(0, TOPE);

        if (matches.length === 0) {
            box.innerHTML = '<div class="tr-suggest-empty">Sin coincidencias</div>';
        } else {
            // El N° de nota sale de REFERENCIA, que es TEXTO LIBRE capturado por el usuario.
            // Se interpola en dos contextos y hay que escapar en los dos, o una referencia
            // llamada `<img src=x onerror=...>` ejecuta al abrir las sugerencias (XSS
            // almacenado). El buscador hermano (trProdSuggest) ya saneaba; este no.
            //   escapeHtml   → texto visible del <div>.
            //   escapeAttrJs → además escapa la comilla que delimita la cadena JS del onclick.
            // Los dos son los helpers centrales (dom_helpers.js).
            box.innerHTML = matches.map(function (n) {
                var s = String(n);
                return '<div class="tr-suggest-item" onclick="window.trSearchPick(\''
                    + window.escapeAttrJs(s) + '\')">' + window.escapeHtml(s) + '</div>';
            }).join('');
        }
        box.classList.add('open');
    };

    // Sincroniza la X y el tinte azul (.active) del buscador según haya texto.
    function trSearchToggleClear() {
        var input = el('trSearch'); if (!input) return;
        var has = !!input.value.trim();
        var x = el('trSearchClear'); if (x) x.style.display = has ? 'flex' : 'none';
        var box = input.closest('.tr-search-box'); if (box) box.classList.toggle('active', has);
    }

    // ── Al ABRIR la bandeja los buscadores arrancan en el valor del SERVIDOR (vacíos si la URL
    //    no trae filtro ?search / ?id_producto). El HTML ya viene vacío en ese caso, pero algunos
    //    navegadores / la restauración de formularios (bfcache) reponen lo que se había tecleado;
    //    esto revierte los campos a su defaultValue (valor del servidor) al abrir y al restaurar. ──
    window.trResetBuscadores = function () {
        var s = el('trSearch');     if (s) s.value = s.defaultValue;
        var p = el('trProdSearch'); if (p) p.value = p.defaultValue;
        var h = el('trIdProducto'); if (h) h.value = h.defaultValue;
        trSearchToggleClear();
        trProdToggleClear();   // tinte + X + icono de escaneo del buscador de producto
    };
    // Escaneo QR sobre el buscador de producto (icono + cámara en teléfono + lector USB en PC):
    // el código resuelto entra por el MISMO pick que un clic en la sugerencia. `activo` = hay
    // producto elegido (el texto del cuadro solo lo acompaña), así el icono de escaneo y la "x"
    // de limpiar nunca se ven a la vez. Va ANTES de trResetBuscadores porque ese ya sincroniza
    // el icono (via trProdToggleClear) y necesita el enganche puesto. Las funciones que usa
    // —trProdPick, trProdToggleClear— se resuelven al invocarse, más abajo en este mismo script.
    window.QrScan.init({
        input:      'trProdSearch',
        icono:      'trProdScan',
        activo:     function () { return !!v('trIdProducto'); },
        onProducto: function (p, label) { window.trProdPick(label, p.id); },
    });

    window.trResetBuscadores();
    // bfcache (botón "atrás"): el <script> NO se re-ejecuta, pero el evento pageshow sí dispara.
    if (_trBindGlobal) window.addEventListener('pageshow', function (ev) {
        if (ev.persisted && typeof window.trResetBuscadores === 'function') window.trResetBuscadores();
    });

    // Escribir: sale del modo KPI, refresca la X y las sugerencias, y BUSCA SOLO con un
    // respiro (TR_MIN_BUSCA / TR_ESPERA_MS, arriba). Antes solo buscaba con
    // Enter o eligiendo una sugerencia: tras filtrar una vez, seguir escribiendo no cambiaba
    // nada y había que pulsar la X para poder buscar otra cosa.
    //   · 3+ caracteres → busca (un N° de nota se reconoce con poco: "249", "NE-2026").
    //   · campo vacío   → recarga sin filtro, sin tener que tocar la X.
    //   · 1-2 caracteres → no busca (demasiado amplio); Enter sigue forzando la búsqueda.
    var TR_MIN_BUSCA = 3, TR_ESPERA_MS = 450, _trST;
    window.trSearchInput = function () {
        // Se cancela el temporizador de la tecla ANTERIOR: sin esto cada pulsación dejaba
        // el suyo vivo y escribir "MARTILLO" lanzaba CINCO cargas a la bandeja (el
        // clearTimeout de trLoad solo alcanza al último id guardado, no a los del medio).
        clearTimeout(_trST);
        window.trResetKpi();
        trSearchToggleClear();
        window.trSearchSuggest();
        var txt = (el('trSearch') ? el('trSearch').value : '').trim();
        if (txt.length >= TR_MIN_BUSCA || txt.length === 0) {
            _trST = setTimeout(function () { window.trLoad(); }, TR_ESPERA_MS);
        }
    };

    window.trSearchPick = function (numero) {
        var input = el('trSearch'); if (!input) return;
        input.value = numero;
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        trSearchToggleClear();
        window.trLoad();
    };

    // Enter en el buscador → filtra por el texto tal cual (similitudes vía LIKE del
    // backend), sin tener que elegir una sugerencia. Igual que el módulo Inventario.
    window.trSearchEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        window.trLoad();
    };

    // X = vaciar el filtro y recargar sin filtro (mismo patrón que almBuscarLimpiar del
    // módulo Inventario).
    window.trSearchClear = function () {
        var input = el('trSearch'); if (!input) return;
        input.value = '';
        var box = el('trSearchSuggest'); if (box) box.classList.remove('open');
        trSearchToggleClear();
        window.trResetKpi();
        window.trLoad();
    };

    // ── Buscador por PRODUCTO (con equivalencias) — mismo FuzzySearch compartido que
    //    inventario/movimientos. Al ELEGIR una sugerencia se fija id_producto y se recarga
    //    la bandeja (backend filtra las notas que contienen ese producto). Editar el texto
    //    descarta el producto elegido (como en inventario). ──────────────────────────────
    function trProdToggleClear() {
        var input = el('trProdSearch'); if (!input) return;
        var has = !!v('trIdProducto');
        var x = el('trProdClear'); if (x) x.style.display = has ? 'flex' : 'none';
        var box = input.closest('.tr-search-box'); if (box) box.classList.toggle('active', has);
        window.QrScan.iconToggle();   // escanear visible solo mientras no haya producto elegido
    }
    window.trProdSuggest = function () {
        var inp = el('trProdSearch'), box = el('trProdSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var lista = window.trProductosLista || [];
        var matches = window.FuzzySearch.rank(lista, rawTerm, function (p) {
            return { haystack: (p.CODIGO || '') + ' ' + (p.NOMBRE || '') + ' ' + (p.EQUIV || ''), label: p.NOMBRE || '' };
        }).slice(0, 17);
        var html = '';
        if (!matches.length) {
            html = '<div class="tr-suggest-empty">Sin coincidencias.</div>';
        } else {
            html = matches.map(function (p) {
                // Helpers centrales (dom_helpers.js), NO un replace que borre caracteres:
                // 237 productos del catálogo llevan comillas en el nombre porque ahí van las
                // pulgadas (DISCO DE CORTE 7"). Quitándolas, la sugerencia mostraba
                // 'DISCO DE CORTE 7' y el texto que se copiaba al cuadro perdía la medida.
                var nom = window.escapeHtml(p.NOMBRE || '');
                // escapeHtml y NO escapeAttrJs: el title y los data-* se leen con
                // getAttribute, no los evalua ningun JS. escapeAttrJs anade ademas la capa de
                // literal JS (' y \), y esos backslashes se quedarian EN EL TEXTO.
                var cod = window.escapeHtml(p.CODIGO || '');
                // Equivalencia que COINCIDE con lo buscado (nº de parte alterno) delante del
                // nombre — helper compartido (misma lógica que inventario/movimientos).
                var parteMostrar = window.FuzzySearch.matchedPart(rawTerm, p.PARTES, p.PARTE);
                var parteSafe = parteMostrar ? window.escapeHtml(String(parteMostrar)) : '';
                var parteB = parteSafe ? '<span style="color:#475569;font-weight:700;margin-right:6px;white-space:nowrap;">' + parteSafe + '</span>' : '';
                // data-pick = texto que va al cuadro al elegir: "Nº de parte · descripción",
                // igual que el buscador del inventario. Así el nº de parte buscado (p.ej.
                // P164378) queda VISIBLE en el cuadro y no se pierde. data-pid = match EXACTO.
                // Va en un data-* que se lee con getAttribute: escapeHtml (ver arriba).
                var pickText = window.escapeHtml(
                    (parteMostrar ? String(parteMostrar) + ' · ' : '') + (p.NOMBRE || '')
                );
                return '<div class="tr-suggest-item" data-pid="' + (p.ID_PRODUCTO || '') + '" data-pick="' + pickText + '" title="' + cod + '">' + parteB + '<span>' + nom + '</span></div>';
            }).join('');
        }
        box.innerHTML = html;
        box.classList.add('open');
    };
    // Escribir en el buscador de producto: si había uno elegido, editarlo lo DESCARTA
    // (quita el filtro y recarga). Luego refresca las sugerencias.
    window.trProdInput = function () {
        var hid = el('trIdProducto');
        if (hid && hid.value) { hid.value = ''; trProdToggleClear(); window.trResetKpi(); window.trLoad(); }
        window.trProdSuggest();
    };
    // Clic en una sugerencia: fija id_producto + el nombre y recarga la bandeja.
    // SPA-safe: se registra UNA sola vez por pestaña (guard _trBindGlobal), igual que los
    // demás listeners globales; sin esto se duplicaba en cada navegación SPA → un clic
    // disparaba N recargas de la bandeja (flicker + carrera de respuestas).
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var it = e.target.closest('#trProdSuggest .tr-suggest-item');
        if (!it) return;
        window.trProdPick(it.getAttribute('data-pick') || '', it.getAttribute('data-pid') || '');
    });
    // Fija el producto del filtro y recarga la bandeja. Punto único: lo usan el clic en una
    // sugerencia y el escaneo de un QR (que resuelve el CODIGO contra el catálogo).
    window.trProdPick = function (texto, idProducto) {
        var hid = el('trIdProducto'); if (hid) hid.value = idProducto || '';
        var inp = el('trProdSearch'); if (inp) inp.value = texto || '';
        var box = el('trProdSuggest'); if (box) box.classList.remove('open');
        trProdToggleClear();
        window.trResetKpi();
        window.trLoad();
    };
    // Limpiar = elegir "ningún producto": mismo camino que el pick, sin duplicar el cuerpo.
    window.trProdClear = function () { window.trProdPick('', ''); };

    function params(pageUrl) {
        // Sin `estado` el backend aplica su default (En tránsito + Confirmada parcial). Aquí
        // mandamos los filtros del UI (search/estado/destino/fechas). El estado SIEMPRE se envía
        // —incluido 'all' (Todas/historial) y 'ENVIADO'— para que el backend sepa
        // exactamente qué se pidió y no caiga en el default cuando el usuario eligió otro.
        var p = new URLSearchParams();
        if (v('trSearch'))                                 p.set('search', v('trSearch'));
        if (v('trIdProducto'))                             p.set('id_producto', v('trIdProducto'));

        // Estado: ahora es un custom-dropdown → se lee del hidden input (data-filter-value).
        if (hv('estado'))                                  p.set('estado', hv('estado'));
        // El "Almacén destino" ahora vive en el dropdown del header (no en el panel
        // avanzado). Se lee del hidden input que el custom-dropdown mantiene.
        var dest = hv('id_almacen_destino');
        if (dest)                                          p.set('id_almacen_destino', dest);
        if (v('trDesde'))                                  p.set('desde', v('trDesde'));
        if (v('trHasta'))                                  p.set('hasta', v('trHasta'));
        // KPI del panel: las 3 métricas van al backend (fuerza ESTADO=ENVIADO en las tres;
        // recientes/urgentes añaden además su ventana de tiempo). Antes 'por_revisar' no se
        // mandaba y la tabla caía en el default de la bandeja, que hoy incluye las parciales
        // → se listaban notas que ese número no cuenta.
        if (_trKpi)                                        p.set('kpi', _trKpi);
        if (pageUrl) { try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {} }
        return p;
    }

    // Refresca el tinte azul de los filtros de FECHA (Desde / Hasta) según si tienen valor,
    // y el tinte rojo del botón que abre "Filtros avanzados" si HAY algo activo ahí dentro
    // (estado concreto o alguna fecha). El trigger del Estado se pinta solo: lo maneja el
    // selectOption global del custom-dropdown. Se llama en trLoad para mantener UI = filtros.
    // Sin el repintado del botón, elegir una fecha o un estado por AJAX dejaba el botón gris
    // (el panel quedaba filtrando "en secreto") hasta recargar la página entera.
    function trUpdateChips() {
        var paint = function (id, on) { var e = el(id); if (e) e.style.background = on ? '#e1effa' : '#fff'; };
        var sel   = function (id) { var e = el(id); return e ? e.value : ''; };
        var desde = !!sel('trDesde'), hasta = !!sel('trHasta');
        paint('trDesdeBox', desde);
        paint('trHastaBox', hasta);

        // MISMO criterio que $panelActivo del render (estado concreto = distinto de vacío y
        // de "Todas"). Los colores viven en .btn-filtro-avanzado.activo, aquí solo se alterna la clase.
        var estado = hv('estado');
        var btn = el('trAdvBtn');
        if (btn) btn.classList.toggle('activo', desde || hasta || (estado !== '' && estado !== TR_FILTRO_TODAS));
    }

    // Actualiza las 3 métricas del panel "Resumen de la bandeja" con los conteos frescos
    // que manda cada respuesta AJAX de la bandeja (ver trLoad). Sin esto el panel quedaba
    // pegado al valor calculado en el render inicial de la página.
    window.trUpdateBandejaStats = function (stats) {
        var map = { por_revisar: 'tr-sub-rev', recientes: 'tr-sub-rec', urgentes: 'tr-sub-urg' };
        Object.keys(map).forEach(function (key) {
            var box = document.querySelector('.' + map[key] + '[data-kpi]');
            var strong = box && box.querySelector('strong');
            if (strong && typeof stats[key] === 'number') strong.textContent = stats[key];
        });
    };

    // Actualiza el badge rojo "[N]" del menú "Recepción" (desktop + mobile) sin recargar
    // la página. Mismo motivo que trUpdateBandejaStats: la bandeja se refresca por AJAX
    // pero el badge vive en el layout, fuera de esta vista.
    window.trUpdateNavBadge = function (count) {
        // TODOS los .nav-badge del layout (Recepción escritorio, Recepción móvil y el del
        // menú padre Almacén, que se ve con el menú contraído) pintan el MISMO
        // $traspasosPorRecibir, así que se actualizan por clase y no por una lista de ids:
        // el día que se agregue un cuarto sitio no hay que acordarse de tocar esta vista.
        document.querySelectorAll('.nav-badge').forEach(function (span) {
            span.textContent = count;
            // data-count NO es decorativo: de él cuelga la regla .nav-badge[data-count="0"]
            // de menu.css, que es la única que decide si el badge se ve. Por eso aquí no se
            // toca style.display — sería la misma regla escrita por segunda vez.
            span.dataset.count = count;
        });
    };

    // Nº de la última carga pedida. Con la búsqueda por debounce es normal tener DOS
    // peticiones en vuelo (se teclea mientras la anterior viaja) y la que responde última
    // gana, aunque traiga la consulta vieja. Cada respuesta se descarta si ya no es la
    // última pedida — más barato que abortar y sin tocar el fetch.
    var _trSeq = 0;
    window.trLoad = function (pageUrl) {
        var body = el('trTableBody'); if (!body) return;
        // Punto ÚNICO donde se cancela el debounce del buscador: da igual por dónde se pida
        // la recarga (sugerencia, Enter, X, estado, fechas, KPI, paginación…), ninguna deja
        // atrás un temporizador que dispare una segunda carga 450 ms después.
        clearTimeout(_trST);
        var miSeq = ++_trSeq;
        trUpdateChips();
        var url = ROUTE + '?' + params(pageUrl).toString();
        body.style.opacity = '0.5';
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (miSeq !== _trSeq) return;   // llegó tarde: ya se pidió otra búsqueda
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('trPagination'); if (pg) pg.innerHTML = data.pagination || '';
                // Refrescar las sugerencias del buscador con las del almacén/filtros actuales
                // (el backend las recalcula y las manda en cada respuesta). Sin esto, al
                // cambiar el "Almacén destino" seguían apareciendo notas del almacén anterior.
                if (Array.isArray(data.numerosNotas)) TR_NUMEROS = data.numerosNotas;
                // Panel "Resumen de la bandeja" y badge del menú: el backend ya manda los
                // conteos frescos en cada respuesta AJAX. Sin esto, al confirmar/enviar/
                // cancelar una nota la fila desaparecía de la tabla pero "Por revisar" y el
                // badge rojo del menú "Recepción" seguían con el número de la carga inicial
                // hasta recargar la página entera.
                if (data.bandejaStats) window.trUpdateBandejaStats(data.bandejaStats);
                if (typeof data.traspasosPorRecibir === 'number') window.trUpdateNavBadge(data.traspasosPorRecibir);
                try { window.history.replaceState(null, '', url); } catch (e) {}
            })
            .catch(function () { body.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar las notas de entrega.</td></tr>'; })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });
    };

    // Click en fila → abrir modal de detalle
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var row = e.target.closest('#trTableBody tr[data-id]');
        if (row) window.trOpenModal(row.dataset.id);
    });

    // ── Modal de detalle/recepción ──────────────────────────────────
    var DETALLE_URL = RECB_CFG.urlDetalle;
    var _trModalId  = null;
    // Evita doble guardado: lo ponen en true las acciones explícitas (confirmar/
    // cancelar/enviar) ANTES de postear, para que el cierre del modal que disparan
    // al terminar no vuelva a auto-guardar. Se resetea al abrir un modal nuevo.
    var _trModalSubmitted = false;

    window.trOpenModal = function (id) {
        _trModalId = id;
        _trModalSubmitted = false;
        var overlay = el('trDetalleOverlay');
        var box     = el('trDetalleBox');
        if (!overlay || !box) return;
        box.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px;"><i class="material-icons" style="font-size:32px;color:#94a3b8;animation:spin 1s linear infinite;">autorenew</i></div>';
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';

        window.apiFetch(DETALLE_URL + '/' + id, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (data) {
                box.innerHTML = data.html || '';
                _trModalId = data.id || id;
            })
            .catch(function () {
                box.innerHTML = '<div style="padding:40px;text-align:center;color:#dc2626;font-weight:600;">No se pudo cargar el detalle.</div>';
            });
    };

    window.trCloseModal = function () {
        // Auto-guardado PARCIAL al cerrar: si el modal es una recepción activa, hay AL
        // MENOS una fila marcada (.recibida) y no se confirmó/canceló ya con un botón
        // (_trModalSubmitted), al cerrar se guarda lo marcado (las no marcadas quedan
        // PENDIENTES → el backend marca la nota "Confirmada parcial" y sigue en la
        // bandeja). Si no hay nada marcado, cerrar NO guarda nada (evita confirmaciones
        // accidentales).
        var box = el('trDetalleBox');
        if (!_trModalSubmitted && box && box.querySelector('.dtm-linea-rec.recibida')) {
            window.trModalConfirmar(); // postea (marca _trModalSubmitted) y al terminar reentra aquí para cerrar
            return;
        }
        var overlay = el('trDetalleOverlay');
        if (overlay) overlay.classList.remove('open');
        document.body.style.overflow = '';
        _trModalId = null;
        _trModalSubmitted = false;
    };

    // Botón "Cancelar" del pie: cerrar SIN guardar nada. Desmarca todo ANTES de cerrar, porque
    // trCloseModal auto-guarda lo marcado (ver arriba) — sin este paso, "Cancelar" habría
    // confirmado la recepción parcial, exactamente lo contrario de lo que dice el botón.
    // Avisa solo si había algo marcado: salir con la nota intacta no necesita confirmación.
    window.trDescartarYCerrar = function () {
        var box = el('trDetalleBox');
        var marcadas = box ? box.querySelectorAll('.dtm-linea-rec.recibida').length : 0;

        var descartar = function () {
            if (box) {
                Array.prototype.forEach.call(box.querySelectorAll('.dtm-linea-rec'), function (r) { trMarcarFila(r, false); });
                window.trUpdateConfirmBtn();
            }
            window.trCloseModal();
        };

        // Sin nada marcado no hay nada que perder: se sale directo.
        if (!marcadas) { descartar(); return; }

        // Confirmación con el modal estándar de la app (window.showModal), igual que el
        // "Cancelar" de /almacen/recepcion/nueva — no con el confirm() del navegador, que
        // saca un cuadro del sistema encima del modal y desentona con el resto del módulo.
        window.confirmarAccion({
            type:        'warning',
            title:       'Salir sin confirmar',
            message:     'Perderás <strong>' + marcadas + ' línea' + (marcadas === 1 ? '' : 's') + '</strong> que ya marcaste. La nota queda sin confirmar.',
            confirmText: 'Salir',
            cancelText:  'Seguir revisando',
        }, descartar);
    };

    // Escape = el gesto reflejo de "salir sin hacer nada", así que va por trDescartarYCerrar
    // igual que el botón Cancelar. Con trCloseModal a secas POSTEABA la recepción parcial sin
    // avisar (ese auto-guardado es a propósito, pero solo al cerrar con la ✕).
    // El guard del overlay NO es de adorno: el listener es GLOBAL y vive mientras se esté en
    // el módulo. Sin él, cualquier Escape con el modal cerrado entraba igual a trCloseModal,
    // que suelta `body.style.overflow` sin mirar si hay OTRO overlay abierto (el showModal de
    // la app lo pone en 'hidden'): el fondo se ponía a hacer scroll por debajo de un modal
    // todavía abierto. uicomponents.js ya tiene ese cuidado; aquí faltaba.
    if (_trBindGlobal) document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var ov = el('trDetalleOverlay');
        if (ov && ov.classList.contains('open')) window.trDescartarYCerrar();
    });

    // Fila marcada (.recibida) = recibida por la cantidad enviada (data-enviada). Sin
    // marcar = no se envia esa linea: queda PENDIENTE y la nota sigue en la bandeja.
    // Punto ÚNICO de lectura del campo "Recibido": acepta coma o punto decimal (el campo es
    // type=text, ver el modal) y devuelve 0 ante cualquier cosa no numérica. Mismo criterio
    // que el campo de cantidad del inventario.
    function trParseCant(valor) {
        return parseFloat(String(valor == null ? '' : valor).replace(',', '.')) || 0;
    }

    // Envía SOLO las filas tildadas (.recibida). Las que no se tildaron NO viajan: el backend
    // las deja PENDIENTES y por eso la nota queda "Confirmada parcial" y sigue en la bandeja.
    // Antes se mandaban todas, las no marcadas con cantidad 0, así que el backend no podía
    // distinguir "no la revisé" de "revisé y no llegó nada" — y toda nota salía confirmada.
    // La cantidad sale del INPUT de cada fila, no de data-enviada: el usuario puede haber
    // escrito menos (faltante) o más (sobrante). El backend acepta cualquier valor ≥ 0 y
    // calcula la diferencia contra lo enviado.
    function trCollectLineas() {
        var box = el('trDetalleBox');
        if (!box) return [];
        var lineas = [];
        // Sin fallback a data-enviada: .dtm-linea-rec solo se pinta cuando también se pinta
        // el input (ver detalle_modal), así que aquí inp existe siempre.
        box.querySelectorAll('.dtm-linea-rec.recibida').forEach(function (card) {
            lineas.push({
                id_linea:          parseInt(card.dataset.idLinea),
                cantidad_recibida: trParseCant(card.querySelector('.dtm-rec-input').value),
            });
        });
        return lineas;
    }

    // Marca/desmarca UNA fila (clase + campo "Recibido"). Fuente única: la usan el clic en la
    // fila y "Marcar todas", así las dos rutas dejan exactamente el mismo estado.
    // trParseCant, no parseFloat: es la MISMA función con la que trCollectLineas lee este
    // data-enviada al enviar. Con dos parsers distintos para el mismo dato, cualquier
    // formato que uno acepte y el otro no (una coma decimal) los pone en desacuerdo.
    function trMarcarFila(row, marcada) {
        row.classList.toggle('recibida', marcada);
        var inp = row.querySelector('.dtm-rec-input');
        if (inp) inp.value = marcada ? trParseCant(row.dataset.enviada) : '';
    }

    // Filas de recepción que el buscador del modal NO está ocultando.
    function trFilasVisibles(box) {
        return Array.prototype.filter.call(
            box.querySelectorAll('.dtm-linea-rec'),
            function (r) { return r.style.display !== 'none'; }
        );
    }

    // Tocar una fila de la recepción activa la marca/desmarca como recibida (azul) y rellena
    // o vacía su campo "Recibido" con la cantidad enviada — el caso normal es "llegó todo".
    // Delegado en #trDetalleBox porque el contenido del modal se carga por AJAX.
    // El input lleva su propio stopPropagation: escribir dentro no debe desmarcar la fila.
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var row = e.target.closest('#trDetalleBox .dtm-linea-rec');
        if (!row) return;
        var marcar = !row.classList.contains('recibida');
        trMarcarFila(row, marcar);
        // Al MARCAR una fila suelta, el campo queda enfocado con la cantidad seleccionada:
        // el caso normal es "llegó todo" (ya viene puesta), y si llegó menos basta teclear el
        // número real encima sin tener que borrar. Solo al marcar una a una — "Marcar todas"
        // usa trMarcarFila directo y no roba el foco a 20 campos.
        if (marcar) {
            var inp = row.querySelector('.dtm-rec-input');
            if (inp) { inp.focus(); if (inp.select) inp.select(); }
        }
        window.trUpdateConfirmBtn();
    });

    // "Marcar todas" (va al lado del buscador de materiales): el atajo para la nota completa.
    // Actúa sobre las filas VISIBLES — si el buscador del modal está filtrando, marca lo que
    // el usuario tiene delante, no líneas que no puede ver. Si ya están todas marcadas,
    // el mismo botón las quita (es un interruptor, no una acción de una sola dirección).
    window.trMarcarTodas = function () {
        var box = el('trDetalleBox'); if (!box) return;
        var filas = trFilasVisibles(box);
        if (!filas.length) return;
        var faltaAlguna = filas.some(function (r) { return !r.classList.contains('recibida'); });
        filas.forEach(function (r) { trMarcarFila(r, faltaAlguna); });
        window.trUpdateConfirmBtn();
    };

    // Escribir una cantidad marca la fila; borrarla (o poner 0) la desmarca. Así el estado
    // visual y lo que se enviará al backend no pueden contradecirse. Se lee con trParseCant,
    // la misma función que usa trCollectLineas al enviar.
    window.trRecInput = function (inp) {
        var row = inp.closest('.dtm-linea-rec');
        if (!row) return;
        // La fila cuenta como REVISADA si el usuario escribió un número, aunque sea CERO.
        // Antes el criterio era "> 0" y eso dejaba sin registrar el faltante total: al
        // teclear 0 ("mandaron 5, no llegó ninguno") la fila se DESmarcaba, trCollectLineas
        // no la recogía y la línea se quedaba PENDIENTE — justo lo contrario de lo que
        // busca este modal, que es distinguir "no la revisé" de "revisé y no llegó nada".
        // El servidor lo admite (cantidad_recibida: numeric|min:0) y calcula la diferencia.
        // Campo VACÍO = no revisada; texto que no es un número tampoco marca.
        var txt = String(inp.value == null ? '' : inp.value).trim();
        var num = parseFloat(txt.replace(',', '.'));
        row.classList.toggle('recibida', txt !== '' && isFinite(num) && num >= 0);
        window.trUpdateConfirmBtn();
    };

    // Buscador de materiales del modal. Filtra EN EL CLIENTE contra data-buscar (código +
    // descripción + nºs de parte, ya en minúsculas desde el Blade): las líneas de una nota
    // están todas en el DOM, así que no hay motivo para ir al servidor.
    //
    // OJO: sólo OCULTA filas, no las desmarca. Una línea tildada que queda fuera del filtro
    // sigue habilitando "Aceptar" y se envía igual — si el filtro la des-tildara, buscar
    // un producto perdería en silencio lo que el usuario ya había confirmado.
    window.trFiltrarLineas = function (texto, limpiar) {
        var box = el('trDetalleBox'); if (!box) return;
        var input = box.querySelector('#dtmBuscar');
        if (limpiar && input) input.value = '';
        var q = (limpiar ? '' : String(texto || '')).trim().toLowerCase();

        var caja = box.querySelector('#dtmBuscarBox');
        if (caja) caja.classList.toggle('active', q !== '');

        var visibles = 0;
        box.querySelectorAll('.dtm-linea').forEach(function (tr) {
            var hay = !q || (tr.getAttribute('data-buscar') || '').indexOf(q) !== -1;
            tr.style.display = hay ? '' : 'none';
            if (hay) visibles++;
        });

        // Mensaje de "sin resultados": se crea una sola vez y se reutiliza. No lleva
        // .dtm-linea-rec, así que trCollectLineas no lo recoge al confirmar.
        var tabla = box.querySelector('.dtm-table');
        var tbody = tabla && tabla.querySelector('tbody'); if (!tbody) return;
        var vacio = tbody.querySelector('.dtm-sin-resultados');
        if (!visibles) {
            if (!vacio) {
                // La tabla tiene 4 columnas al recibir y 6 al consultar una nota cerrada:
                // se lee del thead en vez de fijar un número.
                var cols = tabla.querySelectorAll('thead th').length || 4;
                vacio = document.createElement('tr');
                vacio.className = 'dtm-sin-resultados';
                vacio.innerHTML = '<td colspan="' + cols + '">Ningún material coincide con la búsqueda.</td>';
                tbody.appendChild(vacio);
            }
            vacio.style.display = '';
        } else if (vacio) {
            vacio.style.display = 'none';
        }

        // Cambió el conjunto visible → "Marcar todas" puede tener que cambiar de rótulo.
        window.trUpdateConfirmBtn();

        if (limpiar && input) input.focus();
    };

    // Botón "Aceptar": única acción de confirmación. Siempre VISIBLE; se deshabilita
    // mientras no haya filas tildadas (.recibida), en vez de ocultarse — un pie con solo
    // "Cancelar" no dejaba ver que la nota se acepta marcando lo que llegó.
    // Window-function porque el listener global (bind único) la llama.
    window.trUpdateConfirmBtn = function () {
        var box = el('trDetalleBox'); if (!box) return;

        // "Marcar todas" ↔ "Quitar todas": el rótulo sigue al estado de las filas visibles,
        // se llegue como se llegue (botón, clic en una fila, escribir una cantidad o filtrar).
        // Por eso vive aquí, el único punto por el que pasan todas esas rutas.
        var btnTodas = box.querySelector('#dtmMarcarTodas');
        if (btnTodas) {
            var filas  = trFilasVisibles(box);
            var todas  = filas.length > 0 && filas.every(function (r) { return r.classList.contains('recibida'); });
            btnTodas.classList.toggle('activo', todas);
            var rotulo = todas ? 'Quitar todas' : 'Marcar todas';
            var txt = btnTodas.querySelector('.desktop-text');
            if (txt) txt.textContent = rotulo;
            btnTodas.title = rotulo; // en teléfono el texto se oculta y solo queda el title
        }

        var btnSel = box.querySelector('#trConfirmSelBtn'); if (!btnSel) return;
        // Habilitado si hay AL MENOS una marcada, incluidas las que el buscador esté ocultando:
        // son las que se van a enviar (ver trFiltrarLineas).
        btnSel.disabled = !box.querySelector('.dtm-linea-rec.recibida');
    };

    // Guard de doble envío: sin él, un doble clic en "Aceptar" (o en "Enviar") mandaba DOS
    // peticiones, con dos avisos de éxito y dos recargas de la bandeja. El stock estaba a
    // salvo —TraspasoService recibe con lockForUpdate y salta las líneas ya confirmadas—,
    // pero la pantalla se volvía loca. Sus dos hermanos (compra directa y entrada por ODC)
    // ya tenían esta protección; aquí faltaba.
    var _trEnviando = false;

    function trModalPost(url, payload, successMsg) {
        if (_trEnviando) return;
        _trEnviando = true;
        var btn = document.getElementById('trConfirmSelBtn');
        if (btn) btn.disabled = true;
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
        .then(function (res) {
            if (res.ok) {
                window.toast(successMsg || res.data.message || 'Operación exitosa', 'success');
                window.trCloseModal();
                window.trLoad();
            } else {
                // La acción NO se aplicó: se deshace la marca de "ya enviado" para que el
                // modal, que sigue abierto, vuelva a comportarse como uno sin confirmar. Sin
                // esto, tras un 403 el cierre con la ✕ dejaba de auto-guardar lo marcado.
                _trModalSubmitted = false;
                window.toast(res.data.message || 'Error en la operación', 'error');
            }
        })
        .catch(function () {
            _trModalSubmitted = false;
            window.toast('Error de conexión', 'error');
        })
        .finally(function () {
            if (window.hidePreloader) window.hidePreloader();
            // Se suelta el guard SIEMPRE: si la petición falló, el modal sigue abierto y el
            // usuario tiene que poder reintentar. trUpdateConfirmBtn devuelve al botón su
            // estado real (habilitado solo si queda alguna fila marcada).
            _trEnviando = false;
            if (window.trUpdateConfirmBtn) window.trUpdateConfirmBtn();
        });
    }

    // "Aceptar": confirma SOLO las filas tildadas (.recibida); las demás quedan PENDIENTES
    // → el backend marca la nota "Confirmada parcial" y la deja en la bandeja. Si se tildan
    // todas, la nota queda cerrada (RECIBIDO) aunque alguna cantidad no cuadre.
    window.trModalConfirmarSeleccionados = function () {
        if (!_trModalId) return;
        var box = el('trDetalleBox');
        if (!box || box.querySelectorAll('.dtm-linea-rec.recibida').length === 0) return;
        window.trModalConfirmar();
    };

    window.trModalConfirmar = function () {
        if (!_trModalId) return;
        var lineas = trCollectLineas();
        if (!lineas.length) return;
        _trModalSubmitted = true;
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/recibir',
            { lineas: lineas },
            'Recepción confirmada'
        );
    };

    // ANULAR la nota: la deja CANCELADA y devuelve el stock al origen. Confirma con el modal
    // estándar (window.showModal, type danger = botón rojo), igual que trDescartarYCerrar: el
    // confirm() del navegador saca un cuadro del sistema ENCIMA del modal y, en la acción más
    // destructiva del módulo, era justo donde peor quedaba.
    window.trModalCancelar = function (neNumero) {
        if (!_trModalId) return;
        var anular = function () {
            _trModalSubmitted = true; // acción explícita → el cierre posterior no auto-guarda
            trModalPost(DETALLE_URL + '/' + _trModalId + '/cancelar', {}, 'Nota anulada');
        };
        // El mensaje dice lo que PASA (el stock vuelve al origen), no solo que es irreversible:
        // quien la anula tiene que saber dónde queda la mercancía.
        window.confirmarAccion({
            type:        'danger',
            title:       'Anular la nota',
            message:     'La nota <strong>' + (neNumero || _trModalId) + '</strong> quedará ANULADA y el stock volverá al almacén de origen. No se puede deshacer.',
            confirmText: 'Anular',
            cancelText:  'No, volver',
        }, anular);
    };

    window.trModalEnviar = function () {
        if (!_trModalId) return;
        _trModalSubmitted = true; // acción explícita → el cierre posterior no auto-guarda
        trModalPost(
            DETALLE_URL + '/' + _trModalId + '/enviar',
            {},
            'Nota enviada'
        );
    };

    // Paginación AJAX
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var a = e.target.closest('#trPagination a.page-link') || e.target.closest('#trPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.trLoad(a.href); }
    }, true);

    // Los custom-dropdowns disparan 'dropdown-selection' cuando el usuario elige una
    // opcion. Recargamos la tabla al cambiar el almacen destino (header) o el Estado.
    if (_trBindGlobal) window.addEventListener('dropdown-selection', function (e) {
        var id = e.detail && e.detail.dropdownId;
        // trSetEstadoDefault limpia el Estado con el helper global y recarga por su cuenta:
        // sin este guard el evento del helper dispararía un segundo trLoad y, peor, un
        // trResetKpi que borraría el KPI que trKpiFilter acaba de fijar.
        if (window.__trSkipDropEvt) return;
        // Cambiar Estado/Almacén a mano sale del modo KPI.
        if (id === 'trDestHeaderDropdown' || id === 'trEstadoDropdown') { window.trResetKpi(); window.trLoad(); }
    });

    // ── Panel "Filtros avanzados" (botón filter_list, patrón /admin/equipos) ──
    window.trToggleAdvanced = function (ev) {
        if (ev) ev.stopPropagation();
        var p = el('trAdvPanel'); if (!p) return;
        var opening = p.style.display !== 'block';
        // Al abrir el panel cerramos cualquier custom-dropdown que esté activo.
        if (opening && window.closeAllDropdowns) window.closeAllDropdowns(null);
        p.style.display = opening ? 'block' : 'none';
    };
    // "Limpiar" del panel: vacía TODO lo que vive dentro (Estado + Desde/Hasta) — antes solo
    // limpiaba las fechas y el Estado, ya mudado al panel, quedaba filtrando por detrás.
    // NO toca los buscadores del toolbar (producto / N° de nota): esos tienen su propia X.
    window.trClearAvanzados = function () {
        trSetEstadoDefault();
        ['trDesde', 'trHasta'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        window.trResetKpi();
        window.trLoad();
    };

    // ── KPIs del panel "Resumen de la bandeja" (clic → filtra la bandeja) ──
    // Resalta la métrica activa (anillo blanco) en el panel.
    function trPaintKpi() {
        document.querySelectorAll('.tr-stats-sub[data-kpi]').forEach(function (c) {
            c.classList.toggle('active', _trKpi !== '' && c.dataset.kpi === _trKpi);
        });
    }
    // Sale del modo KPI (lo llaman búsqueda/fechas/estado al cambiar a mano).
    window.trResetKpi = function () { _trKpi = ''; trPaintKpi(); };

    // Deja el dropdown de Estado sin filtro, con la MISMA llamada que usa su X
    // (selectOption con valor y etiqueta vacíos): hidden vacío, placeholder vacío, colores
    // neutros, sin X y sin opción resaltada. Antes esto se reimplementaba a mano aquí y ya
    // divergía del helper global (no restauraba las opciones que el buscador del dropdown
    // hubiera ocultado).
    // La bandera evita la recarga DOBLE: el helper emite 'dropdown-selection' y quien llama
    // aquí (KPIs / "Limpiar") hace su propio trLoad después. Los KPIs filtran vía `kpi`, no
    // vía estado, así que el dropdown debe quedar visualmente sin filtro.
    // La bandera vive en window (no en el IIFE): el listener se registra UNA vez por pestaña
    // (_trBindGlobal) y en una navegación SPA seguiría leyendo la variable de la primera
    // corrida mientras esta función escribiría la de la nueva → el guard no dispararía.
    function trSetEstadoDefault() {
        if (!el('trEstadoDropdown') || typeof window.selectOption !== 'function') return;
        window.__trSkipDropEvt = true;
        // selectOption con etiqueta VACÍA, no clearDropdownFilter: ese helper pone
        // "Seleccionar..." cuando no hay data-default-label, y este campo debe quedar vacío.
        // finally: el evento se despacha síncrono dentro del helper, así que al salir del
        // try la bandera ya cumplió su función — y no queda encendida si el helper lanza.
        try { window.selectOption('trEstadoDropdown', '', ''); } finally { window.__trSkipDropEvt = false; }
    }

    // Clic en una métrica: filtra por ese criterio. Las 3 son de pendientes (ENVIADO);
    // 'recientes'/'urgentes' añaden su ventana de tiempo (la calcula el backend con el
    // mismo criterio que el conteo). 'por_revisar' = todas las pendientes.
    window.trKpiFilter = function (kpi) {
        _trKpi = kpi;
        trSetEstadoDefault();
        ['trDesde', 'trHasta'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        trPaintKpi();
        window.trLoad();
    };

    // Resaltado inicial: solo si la URL trae un KPI. La carga SIN filtros ya NO equivale a
    // "Por revisar" — el default de la bandeja incluye las confirmadas parciales — así que
    // marcar esa métrica al abrir mentía sobre lo que se está viendo.
    (function () {
        var k = new URLSearchParams(window.location.search).get('kpi');
        if (k === 'por_revisar' || k === 'recientes' || k === 'urgentes') _trKpi = k;
        trPaintKpi();
    })();
    // Cerrar el panel al hacer clic fuera (ni en el panel ni en su botón).
    // Capture phase (true) para que dispare ANTES de que uicomponents.js llame
    // stopPropagation al manejar un custom-dropdown — de lo contrario el clic en
    // el trigger del Estado nunca llega aquí y el panel queda abierto.
    if (_trBindGlobal) document.addEventListener('click', function (e) {
        var p = el('trAdvPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#trAdvPanel') && !e.target.closest('#trAdvBtn')) {
            p.style.display = 'none';
        }
    }, true);
})();
};
