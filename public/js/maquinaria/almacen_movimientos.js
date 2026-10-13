/*
 * Modulo MOVIMIENTOS de inventario (/admin/almacen/movimientos) — todo su JavaScript.
 *
 * Vivia dentro de movimientos.blade.php y viajaba en el HTML en CADA apertura del modulo.
 * Aqui se baja UNA vez y el navegador lo reutiliza.
 *
 * Lo que depende de la peticion —rutas y las listas de notas— llega en window.ALM_MOV_CFG,
 * que el Blade escribe justo antes de cargar este archivo.
 *
 * OJO con la navegacion SPA: un <script src> ya cargado NO se vuelve a ejecutar
 * (navegacion.js · executeScripts), asi que este archivo corre una sola vez en toda la
 * sesion. Por eso las dos partes:
 *  - window.almMovArrancar(CFG): la bitacora (filtros, tabla, exportar). El Blade la llama
 *    en CADA apertura, igual que antes se re-ejecutaba el <script> de la vista.
 *  - Los modales de Eliminar Nota y el borrado de filas del kardex: se montan una sola vez
 *    (su guard _almNotaModalsReady ya lo hacia asi cuando estaban dentro del HTML).
 */
window.almMovArrancar = function (CFG) {
    'use strict';
    if (!document.getElementById('almMovTableBody')) return;

    var ROUTE = CFG.rutas.movimientos;

    function el(id) { return document.getElementById(id); }
    // Busca los hidden de los filtros por name. NOTA: no restringimos a #almMovFilters porque el dropdown de
    // almacén vive ahora junto al título (fuera de ese contenedor); el atributo data-filter-value es el ancla.
    // Acepta tanto `<input type=hidden>` (custom-dropdowns: almacen, frente) como
    // `<select>` (tipo — vive ahora dentro del panel Filtros Avanzados).
    function hv(name) { var e = document.querySelector('[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    // SPA: al re-montar esta vista el Blade vuelve a llamar a almMovArrancar. Redefinir las funciones de
    // window y releer el estado de la URL es DESEABLE (así el filtro por producto y la lista de
    // notas se re-siembran con los datos frescos), pero volver a registrar los listeners de
    // `document`/`window` los APILA: un clic de paginación dispararía loadMovimientos() una vez
    // por cada visita a la página. Por eso esta función sí se re-ejecuta entera y sólo el
    // registro de listeners globales queda protegido por bandera.
    //
    // Los handlers cierran sobre ROUTE/el/hv (estables entre montajes) y llaman a las funciones
    // por `window.*`, así que los del primer montaje siguen siendo válidos para los siguientes.
    var _globalsBound = window._almMovGlobalsBound === true;
    window._almMovGlobalsBound = true;
    function docOn(type, fn, capture) { if (!_globalsBound) document.addEventListener(type, fn, capture); }
    function winOn(type, fn) { if (!_globalsBound) window.addEventListener(type, fn); }

    // Producto(s) elegido(s) en el buscador, como CSV de IDs — ESTADO ÚNICO del filtro de
    // producto. Un clic en una sugerencia manda todos los IDs de esa descripción (uno solo si
    // no tiene varias presentaciones), así la bitácora filtra por ID y no por substring.
    // Se siembra desde la URL aceptando las DOS formas: id_producto_in (sugerencia agrupada) e
    // id_producto (link puntual, p.ej. desde el detalle de un producto). Al teclear / Enter /
    // limpiar vuelve a null → manda la búsqueda por texto (LIKE).
    // Lo lee también el modo offline (movimientos-offline.js), que replica estos filtros.
    window.almMovPickedIds = (function () {
        var q = new URLSearchParams(window.location.search);
        return q.get('id_producto_in') || q.get('id_producto') || null;
    })();

    function buildParams(pageUrl) {
        var p = new URLSearchParams();
        // OJO: cuando el usuario elige "Todos los almacenes" mandamos `id_almacen=all`
        // explícito. Si no lo enviamos, el controller cree que NO vino el param y
        // aplica el default-por-frente, volviendo a filtrar (el usuario veía solo su
        // almacén aunque hubiera pedido "todos").
        var alm = hv('id_almacen'); if (alm) p.set('id_almacen', alm);
        var tipo = hv('tipo'); if (tipo && tipo !== 'all') p.set('tipo', tipo);
        var fr = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
        var d = el('almMovDesde'); if (d && d.value) p.set('desde', d.value);
        var h = el('almMovHasta'); if (h && h.value) p.set('hasta', h.value);
        var nt = el('almMovNota'); if (nt && nt.value.trim()) p.set('nota', nt.value.trim());
        // Producto(s) elegido(s): trae SOLO esos y sus equivalencias en el consumo. Tiene
        // precedencia sobre `search` (LIKE), así que cuando hay pick NO mandamos `search`
        // (el cuadro muestra "Nº · descripción", que como texto no encontraría nada).
        var s = el('almMovSearch');
        if (window.almMovPickedIds) {
            p.set('id_producto_in', window.almMovPickedIds);
        } else if (s && s.value.trim()) {
            p.set('search', s.value.trim());
        }
        // pageUrl presente = paginación: el ranking de consumo no cambia entre páginas,
        // así que pedimos al backend que lo omita (skip_consumo) y no recalcule el agregado.
        if (pageUrl) {
            try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {}
            p.set('skip_consumo', '1');
        }
        return p;
    }

    // Trae SOLO el ranking de consumo (agregado ~90ms) en una petición aparte, para que NO
    // bloquee la aparición de la tabla. Se llama en paralelo al cambiar filtros/búsqueda
    // (no en paginación: el ranking no cambia entre páginas). Si falla, se conserva el previo.
    function cargarConsumoMov() {
        var cc = el('almMovConsumoContainer'); if (!cc) return;
        var p = buildParams();            // filtros actuales (sin pageUrl)
        p.set('consumo_only', '1');
        window.apiFetch(ROUTE + '?' + p.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.consumo !== undefined) cc.innerHTML = d.consumo; })
            .catch(function () {});
    }

    window.loadMovimientos = function (pageUrl) {
        var body = el('almMovTableBody'); if (!body) return;
        var p = buildParams(pageUrl);
        // URL "limpia" para el historial (sin flags internos de rendimiento).
        var pHist = new URLSearchParams(p.toString()); pHist.delete('skip_consumo');
        // La TABLA nunca espera el agregado de consumo (~90ms): siempre skip_consumo → aparece rápido.
        p.set('skip_consumo', '1');
        var url = ROUTE + '?' + p.toString();
        body.style.opacity = '0.5';
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.html !== undefined) body.innerHTML = data.html;
                var pg = el('almMovPagination'); if (pg) pg.innerHTML = data.pagination || '';
                { var e = el('almMovTotal'); if (e && data.total !== undefined) e.textContent = data.total; }
                almMovBuscarUI();   // marca "activo" del buscador + iconos (x / escanear)
                try { window.history.replaceState(null, '', ROUTE + '?' + pHist.toString()); } catch (e) {}
            })
            .catch(function () {
                body.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:#dc2626;">No se pudieron cargar los movimientos.</td></tr>';
                // El aviso "Sin conexión" con su botón lo saca el interceptor global de
                // fetch (estructura_base) para CUALQUIER petición de la app.
            })
            .finally(function () { body.style.opacity = '1'; if (window.hidePreloader) window.hidePreloader(); });

        // El ranking de consumo se refresca SOLO al cambiar filtros/búsqueda (no en paginación),
        // en paralelo — la tabla ya no lo espera.
        if (!pageUrl) cargarConsumoMov();
    };

    // Filtro de fecha ROBUSTO (Desde/Hasta). Un <input type="date"> devuelve '' mientras
    // la fecha esté INCOMPLETA (falta día, mes o año) y su valor completo solo cuando los
    // tres segmentos están puestos. Antes solo se escuchaba 'change', que al TECLEAR exige
    // salir del campo (blur) para dispararse → parecía "no funcionar" si el usuario no
    // salía. Ahora escuchamos también 'input': recarga EN CUANTO la fecha queda completa
    // (o al limpiarla), sin blur. El dedup por dataset.lastApplied evita recargas repetidas
    // mientras se teclea (valor '' incompleto no cambia) y la doble recarga input+change.
    window.almMovFechaFiltro = function (el) {
        if (!el) return;
        var v = el.value || '';
        // Sincronizar el icono "X" (limpiar) y el fondo azul de la caja con si hay fecha,
        // también cuando se elige en vivo por el calendario/tecleo (no solo en la carga).
        var clr = document.getElementById(el.id + 'Clear'); if (clr) clr.style.display = v ? 'inline-flex' : 'none';
        var box = document.getElementById(el.id + 'Box');   if (box) box.style.background = v ? '#e1effa' : 'white';
        if (el.dataset.lastApplied === v) return; // sin cambio real → no recargar
        el.dataset.lastApplied = v;
        window.loadMovimientos();
    };

    // ── Lista de productos para el autocomplete del filtro de búsqueda ──
    // Catálogo para el buscador FuzzySearch de la bitácora. ANTES se embebía inline aquí
    // (~300 KB de los 1155 productos) y el módulo abría pesado. AHORA arranca vacío y se carga
    // por AJAX apenas la página queda lista, REUSANDO el mismo endpoint compartido del índice
    // (almacen.productos-autocomplete, misma fuente listaAutocomplete) → no bloquea el render y
    // no se duplica la lista. El buscador lo usa por evento, así que carga async es seguro.
    window.almMovProductosLista = [];
    (function () {
        if (window.almMovProductosCargando) return;
        window.almMovProductosCargando = true;
        window.apiFetch(CFG.rutas.productosAutocomplete, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (lista) { window.almMovProductosLista = Array.isArray(lista) ? lista : []; window.almMovProductosCargados = true; })
        .catch(function () { /* silencioso: el buscador queda vacío hasta que reintente */ })
        .finally(function () { window.almMovProductosCargando = false; });
    })();

    window.almMovSuggestHide = function () { var b = document.getElementById('almMovSuggest'); if (b) b.classList.remove('open'); };

    window.almMovSuggestFn = function () {
        var inp = document.getElementById('almMovSearch'), box = document.getElementById('almMovSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var lista = window.almMovProductosLista || [];

        // Agrupación por descripción + ranking + dedupe: reglas COMPARTIDAS con el filtro
        // "Descripción" del inventario (window.ProductoSuggest). Una descripción con varias
        // presentaciones (distinta UM = producto aparte) sale UNA vez con un badge de cuántas
        // tiene; al elegirla se filtran TODAS (id_producto_in). Los filtros no se agrupan:
        // los del mismo nombre son modelos distintos.
        var grupos  = window.ProductoSuggest.agrupar(lista);
        var matches = window.ProductoSuggest.dedupe(window.ProductoSuggest.rankear(lista, rawTerm), grupos, 17);

        var html = '';
        if (!matches.length) {
            // Mientras el catálogo async no cargó, "Cargando…" en vez de "Sin coincidencias"
            // (que sugeriría por error que el producto no existe). El Enter→servidor sigue vivo.
            html = (!window.almMovProductosCargados)
                ? '<div class="amf-suggest-empty">Cargando productos…</div>'
                : '<div class="amf-suggest-empty">Sin coincidencias.</div>';
        } else {
            html = matches.map(function (p) {
                var nom = (p.NOMBRE || '').replace(/[<>&"]/g, '');
                var cod = (p.CODIGO || '').replace(/[<>&"]/g, '');
                // El código/serial NO se muestra en la lista (igual que el buscador del
                // inventario): la sugerencia queda limpia con SOLO la descripción. Igual se
                // PUEDE filtrar por serial/código — el ranking lo incluye en el haystack
                // (CODIGO + NOMBRE + EQUIV) — y el código sigue en el title de la fila (hover).
                var grp = grupos[window.ProductoSuggest.claveGrupo(p)] || { count: 1, ids: [p.ID_PRODUCTO] };
                // data-pids = los IDs de ESTA descripción cuando tiene varias presentaciones;
                // si es única, es su solo id. En ambos casos el clic filtra por id (nunca por
                // substring, a diferencia del LIKE de `search`).
                var pids  = (grp.ids || [p.ID_PRODUCTO]).join(',');
                var badge = window.ProductoSuggest.badgePresentaciones(grp, 'amf-suggest-cod');
                var parteMostrar = window.FuzzySearch.matchedPart(rawTerm, p.PARTES, p.PARTE);
                // Nº de parte / equivalencia que COINCIDE con lo buscado, DELANTE de la
                // descripción (misma lógica que inventario/recepción). Mismo tamaño/tipo que la
                // descripción (.nom): 13.5px, #475569, peso 600 — así toda la letra se ve igual.
                var parteSafe   = parteMostrar ? String(parteMostrar).replace(/[<>&"]/g, '') : '';
                var partePrefix = parteSafe
                    ? '<span class="amf-suggest-parte" style="font-size:13.5px;color:#475569;font-weight:600;margin-right:6px;white-space:nowrap;">' + parteSafe + '</span>'
                    : '';
                // data-pick = texto que va al cuadro (el nº de parte que coincidió DELANTE de la
                // descripción, si matcheó por equivalencia).
                var pickText = parteSafe ? (parteSafe + ' · ' + nom) : nom;
                return '<div class="amf-suggest-item" data-pids="' + pids + '" data-pick="' + pickText + '" title="' + cod + '">'
                     + '<div class="amf-suggest-line">' + partePrefix + '<span class="nom">' + nom + '</span>' + badge + '</div>'
                     + '</div>';
            }).join('');
        }
        box.innerHTML = html;
        box.classList.add('open');
    };

    // ── Reglas del filtro de producto (las MISMAS que el filtro "Descripción" de
    //    /admin/almacen) ─────────────────────────────────────────────────────────
    //   (a) Escribir refresca solo la LISTA, NO la tabla; y descarta el producto elegido antes
    //       (si el usuario vuelve a teclear, ya quiere otra cosa).
    //   (b) Clic en una sugerencia → los IDs de esa descripción (uno, o todos si tiene varias
    //       presentaciones) → id_producto_in: filtra por ID, nunca por substring.
    //   (c) Enter → similitudes vía LIKE %term% del backend (sin IDs).
    //   (d) X → limpia texto + producto elegido y recarga sin filtro.

    // ¿Hay filtro de producto puesto? Texto tecleado O IDs elegidos por clic (que pueden venir
    // de la URL con el cuadro vacío). Criterio ÚNICO: lo consultan el estado visual de abajo,
    // el icono de escaneo (cfg.activo de QrScan) y el modo offline.
    function almMovProdActivo() {
        var i = el('almMovSearch');
        return !!((i && i.value.trim()) || window.almMovPickedIds);
    }
    // Estado visual del buscador: fondo azul si hay filtro de producto activo y los iconos
    // "x" / "escanear" alternándose (solo uno a la vez). Punto ÚNICO para los cuatro flujos.
    function almMovBuscarUI() {
        var inp = el('almMovSearch'); if (!inp) return;
        var activo = almMovProdActivo();
        var caja = inp.closest('.amf-search-box'); if (caja) caja.classList.toggle('active', activo);
        var x = el('almMovSearchClear'); if (x) x.style.display = activo ? 'block' : 'none';
        window.QrScan.iconToggle();   // escanear visible solo si NO hay filtro de producto
    }

    /**
     * ÚNICO punto de escritura del filtro de producto.
     *   texto  → lo que queda en el cuadro ('' lo vacía)
     *   idsCsv → IDs elegidos ('' / null = sin pick, vuelve la búsqueda por texto)
     * Los tres flujos que lo cambian (clic en sugerencia, Enter, X) pasan por aquí, así que
     * el estado, la UI y la recarga no se pueden desincronizar.
     */
    window.almMovBuscarPick = function (texto, idsCsv) {
        var inp = el('almMovSearch'); if (inp) inp.value = texto || '';
        window.almMovPickedIds = idsCsv ? String(idsCsv) : null;
        almMovBuscarUI();
        window.almMovSuggestHide();
        window.loadMovimientos();
    };
    window.almMovBuscarLimpiar = function () { window.almMovBuscarPick('', null); };

    window.almMovBuscarInput = function () {
        // Editar el texto descarta el producto elegido, pero NO recarga la tabla todavía
        // (eso lo hacen Enter / clic en sugerencia / X).
        window.almMovPickedIds = null;
        almMovBuscarUI();
        window.almMovSuggestFn();
    };
    window.almMovBuscarKey = function (ev) {
        if (!ev) return;
        if (ev.key === 'Escape') { window.almMovSuggestHide(); return; }
        if (ev.key !== 'Enter') return;
        ev.preventDefault();
        var inp = el('almMovSearch');
        // Enter = buscar por TEXTO (LIKE), descartando el pick anterior.
        window.almMovBuscarPick(inp ? inp.value : '', null);
    };

    // Escaneo QR sobre ESTE buscador (icono + cámara en teléfono + lector USB en PC). El
    // código escaneado se resuelve contra el catálogo y entra por el MISMO pick que un clic
    // en la sugerencia → filtra por el ID de ese producto.
    window.QrScan.init({
        input:      'almMovSearch',
        icono:      'almMovBuscarScan',
        activo:     almMovProdActivo,
        onProducto: function (p, label) { window.almMovBuscarPick(label, String(p.id)); },
    });

    // Delegación de clic en sugerencias
    docOn('click', function (e) {
        var item = e.target.closest('#almMovSuggest .amf-suggest-item');
        if (item) {
            window.almMovBuscarPick(item.getAttribute('data-pick') || '', item.getAttribute('data-pids') || null);
            return;
        }
        if (!e.target.closest('#almMovSearch') && !e.target.closest('#almMovSuggest')) window.almMovSuggestHide();
    });

    // ── Autocomplete del filtro "Nota de entrega" ──────────────────────────────
    // Lista de N° de Nota de Entrega (NE-…) de salida en almacenes visibles — la pasa el
    // controller a TODOS los usuarios (a diferencia de la del modal "Eliminar Nota", que
    // va gateada). Sugiere por substring al escribir; clic en una → completa el filtro y
    // recarga. Con el campo vacío (foco) muestra las más recientes.
    window.almMovNotasFiltro = CFG.notasFiltro;
    window.almMovNotaSuggestHide = function () { var b = document.getElementById('almMovNotaSuggest'); if (b) b.classList.remove('open'); };
    window.almMovNotaSuggest = function () {
        var inp = document.getElementById('almMovNota'), box = document.getElementById('almMovNotaSuggest');
        if (!inp || !box) return;
        var q = String(inp.value || '').trim().toUpperCase();
        var lista = window.almMovNotasFiltro || [];
        var matches = q
            ? lista.filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; }).slice(0, 12)
            : lista.slice(0, 12);
        if (!matches.length) {
            box.innerHTML = '<div class="amf-suggest-empty">' + (q ? 'Sin Notas que coincidan.' : 'No hay Notas de Entrega.') + '</div>';
        } else {
            box.innerHTML = matches.map(function (n) {
                var safe = String(n).replace(/[<>&"]/g, '');
                return '<div class="amf-suggest-item" data-pick-nota="' + safe + '"><div class="amf-suggest-line"><span class="amf-suggest-cod">' + safe + '</span></div></div>';
            }).join('');
        }
        box.classList.add('open');
    };
    // Delegación de clic en las sugerencias del filtro de Nota (mismo patrón que el buscador).
    docOn('click', function (e) {
        var item = e.target.closest('#almMovNotaSuggest .amf-suggest-item');
        if (item) {
            var inp = document.getElementById('almMovNota');
            if (inp) inp.value = item.getAttribute('data-pick-nota') || '';
            var clr = document.getElementById('almMovNotaClear'); if (clr) clr.style.display = (inp && inp.value) ? 'block' : 'none';
            window.almMovNotaSuggestHide();
            window.loadMovimientos();
            return;
        }
        if (!e.target.closest('#almMovNota') && !e.target.closest('#almMovNotaSuggest')) window.almMovNotaSuggestHide();
    });

    // Selección en los custom-dropdown → recargar
    winOn('dropdown-selection', function (e) {
        if (!document.getElementById('almMovTableBody')) return;
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almMovFiltroAlmacen' || id === 'almMovFiltroFrente' || id === 'almMovTipoDropdown') {
            window.loadMovimientos();
        }
    });

    // Click en una fila del ranking de consumo → filtra la bitácora a ESE producto. Reusa el
    // mismo "pick" del buscador (filtra por ID): antes solo pegaba el nombre y recargaba, así
    // que un producto elegido antes en el buscador seguía pegado y ganaba.
    window.almMovFiltrarPorProducto = function (idProducto, nombre) {
        window.almMovBuscarPick(nombre || '', idProducto ? String(idProducto) : null);
    };

    // Paginación AJAX
    docOn('click', function (e) {
        var a = e.target.closest('#almMovPagination a.page-link') || e.target.closest('#almMovPagination a');
        if (a) { e.preventDefault(); e.stopImmediatePropagation(); window.loadMovimientos(a.href); }
    }, true);

    // ── Tarjeta en mobile: tocar la DESPLIEGA (y la resalta en azul) ──
    // Recogida enseña producto, cantidad, fecha y destino; desplegada, además quién la registró,
    // el tipo, el stock, Corregida / Devuelto y la celda de la nota (PDF, Modificar, deshacer):
    // todo eso lo decide el CSS con .mv-row-selected (admin_almacen_movimientos.css). Una a la
    // vez: abrir otra recoge la anterior. En escritorio la clase no tiene estilo: no cambia nada.
    //
    // Early return: un click dentro de .mv-td-ref (el N° de Nota, Modificar, deshacer) deja
    // correr su propio onclick sin recoger la tarjeta.
    docOn('click', function (e) {
        if (e.target.closest('#almMovTableBody .mv-td-ref')) return;
        var tr = e.target.closest('#almMovTableBody tr.alm-mov-row');
        if (!tr) return;
        document.querySelectorAll('#almMovTableBody tr.alm-mov-row.mv-row-selected').forEach(function (other) {
            if (other !== tr) other.classList.remove('mv-row-selected');
        });
        tr.classList.toggle('mv-row-selected');
        // El PDF lo abre SOLO el N° de Nota (.mv-nota-link), nunca el toque en la tarjeta: antes
        // tocarla lanzaba el visor encima, sin aviso y sin poder verla sin abrirlo.
    });

    // Panel de fechas
    window.almMovToggleFechas = function (ev) {
        if (ev) ev.stopPropagation();
        var p = el('almMovFechasPanel'); if (!p) return;
        var m = el('splitDropdownMenuMovInv'); if (m) m.style.display = 'none';
        
        // Forma correcta de cerrar los custom-dropdowns de uicomponents.js
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        // Limpiar cualquier estilo inline residual que hayamos inyectado por error
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    };
    // "Limpiar Todo" del panel: limpia Desde/Hasta + el filtro Tipo (custom-dropdown).
    // clearDropdownFilter ya emite el evento dropdown-selection — el listener de arriba
    // dispara loadMovimientos, asi que no lo llamamos dos veces.
    window.almMovLimpiarFechas = function () {
        if (el('almMovDesde')) el('almMovDesde').value = '';
        if (el('almMovHasta')) el('almMovHasta').value = '';
        if (document.getElementById('almMovTipoDropdown')
            && typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter('almMovTipoDropdown');
        } else {
            window.loadMovimientos();
        }
    };
    docOn('click', function (e) {
        var p = el('almMovFechasPanel');
        if (p && p.style.display === 'block' && !e.target.closest('#almMovFechasPanel') && !e.target.closest('#btnAdvancedFilterMov')) p.style.display = 'none';
    });

    // Descarga la bitácora en XLSX con los filtros que se están viendo. Reutiliza
    // buildParams() —el mismo que arma la petición de la tabla— para que el archivo no pueda
    // traer un recorte distinto del que hay en pantalla. Va por window.location y no por
    // navigateTo: es una descarga, no una navegación SPA.
    window.almMovExportarExcel = function () {
        var m = document.getElementById('splitDropdownMenuMovInv');
        if (m) m.style.display = 'none';
        var qs = buildParams().toString();
        window.location.href = CFG.rutas.exportar + (qs ? ('?' + qs) : '');
    };

    // ── Dropdown "Acciones" (toggle + cierre al click fuera) ────────────────
    // Mismo patrón del dropdown de /admin/movilizaciones (toggleAccionesMov):
    // sufijo "Inv" para no colisionar con el del módulo de movilizaciones si
    // ambos cargan en una SPA.
    window.toggleAccionesMovInv = function (ev) {
        if (ev) ev.stopPropagation();
        var m = el('splitDropdownMenuMovInv'); if (!m) return;
        var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';

        // Forma correcta de cerrar los custom-dropdowns de uicomponents.js
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        // Limpiar cualquier estilo inline residual
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');

        // Refrescar el href del link "Historial de Notas de Entrega" con los filtros activos para
        // que la vista por nota se abra ya filtrada por almacén / frente / fechas.
        //
        // Solo se reenvían los filtros que la vista por nota interpreta igual que la bitácora:
        //   · El `search` de la bitácora es el PRODUCTO; en notas() matchea NUMERO_NOTA / RQ /
        //     CONTRATO / SOLICITANTE. Reenviarlo filtraba números de nota por el nombre del
        //     material y devolvía la lista vacía → no se manda.
        //   · El filtro `nota` SÍ equivale al `search` de notas() (ambos son el N° de nota).
        //   · El `tipo` de la bitácora son las claves de grupo ENTRADAS/SALIDAS; notas() espera
        //     los TIPO exactos (SALIDA / TRASPASO_SALIDA) y ya lista ambos por defecto, que es
        //     justo lo que significa el grupo SALIDAS → no hay nada que reenviar.
        var lnk = el('lnkBitNotas');
        if (lnk) {
            var p2 = new URLSearchParams();
            var alm = hv('id_almacen'); if (alm) p2.set('id_almacen', alm);
            var fr  = hv('id_frente'); if (fr && fr !== 'all') p2.set('id_frente', fr);
            var nt  = el('almMovNota'); if (nt && nt.value.trim()) p2.set('search', nt.value.trim());
            var d   = el('almMovDesde'); if (d && d.value) p2.set('desde', d.value);
            var h   = el('almMovHasta'); if (h && h.value) p2.set('hasta', h.value);
            var base = CFG.rutas.notas;
            var qs = p2.toString();
            lnk.href = base + (qs ? ('?' + qs) : '');
        }

        m.style.display = (m.style.display === 'block') ? 'none' : 'block';
    };

    // Cerrar nuestros paneles personalizados si se abre un .custom-dropdown estándar
    // FUERA del panel. Antes este handler cerraba el panel SIEMPRE que se clickee
    // un .dropdown-trigger — bug: el filtro "Tipo" vive DENTRO del panel y su
    // trigger es un .dropdown-trigger tambien, asi que al tocarlo el panel se
    // cerraba antes de que su lista pudiera abrirse. Ahora solo cerramos si el
    // trigger esta FUERA del contenedor correspondiente.
    docOn('click', function (e) {
        var t = e.target.closest('.dropdown-trigger');
        if (!t) return;
        if (!t.closest('#almMovFechasPanel')) {
            var p = el('almMovFechasPanel'); if (p) p.style.display = 'none';
        }
        if (!t.closest('#splitDropdownMenuMovInv')) {
            var m = el('splitDropdownMenuMovInv'); if (m) m.style.display = 'none';
        }
    }, true);
    docOn('click', function (e) {
        var m = el('splitDropdownMenuMovInv');
        if (m && m.style.display === 'block' && !e.target.closest('#splitDropdownMenuMovInv') && !e.target.closest('#btnAccionesMov')) m.style.display = 'none';
    });
};

(function(){
    // Bandera por instancia: si el modal ya tiene listeners (SPA: misma vista re-montada)
    // no duplicar handlers de Escape ni redefinir funciones.
    if (window._almNotaModalsReady) return;
    window._almNotaModalsReady = true;
    var CFG = window.ALM_MOV_CFG;

    var URL_DESTROY  = CFG.rutas.notaDestroy;

    function fb(id, type, msg) {
        var el = document.getElementById(id); if (!el) return;
        var colors = {
            info:    { bg:'#e0f2fe', border:'#bae6fd', color:'#075985' },
            error:   { bg:'#fee2e2', border:'#fecaca', color:'#b91c1c' },
            success: { bg:'#dcfce7', border:'#bbf7d0', color:'#15803d' },
        };
        var c = colors[type] || colors.info;
        el.style.cssText = 'display:block;padding:10px 12px;border-radius:8px;font-size:12.5px;font-weight:600;background:' + c.bg + ';border:1px solid ' + c.border + ';color:' + c.color + ';';
        el.textContent = msg;
    }

    // ── Eliminar Nota ───────────────────────────────────────────────────
    // Sólo se monta si el modal existe (gateado por blade @can a almacen.nota.eliminar).
    if (document.getElementById('eliminarNotaOverlay')) {
        // Lista de N° de Nota (SALIDA, almacenes visibles) para el autocomplete.
        // La provee el controller SOLO si el usuario tiene almacen.nota.eliminar.

        window.openEliminarNotaModal = function () {
            var ov = document.getElementById('eliminarNotaOverlay'); if (!ov) return;
            ov.style.display = 'flex';
            var i = document.getElementById('eliminarNotaInput');
            if (i) { i.value = ''; setTimeout(function(){ i.focus(); }, 80); }
            var elFb = document.getElementById('eliminarNotaFeedback'); if (elFb) elFb.style.display = 'none';
            var box = document.getElementById('eliminarNotaBox'); if (box) box.style.borderColor = '#e2e8f0';
            document.body.style.overflow = 'hidden';
        };
        window.closeEliminarNotaModal = function () {
            var ov = document.getElementById('eliminarNotaOverlay'); if (ov) ov.style.display = 'none';
            var sug = document.getElementById('eliminarNotaSuggest'); if (sug) sug.classList.remove('open');
            document.body.style.overflow = '';
        };

        // Autocomplete: la lista se despliega SOLO al escribir y muestra hasta 4
        // coincidencias por substring — basta escribir parte del número (p. ej.
        // los últimos 4 dígitos). Con el campo vacío la lista permanece cerrada.
        window.eliminarNotaSuggest = function () {
            var input = document.getElementById('eliminarNotaInput');
            var box   = document.getElementById('eliminarNotaSuggest');
            if (!input || !box) return;
            var q = String(input.value || '').trim().toUpperCase();
            if (q === '') { box.classList.remove('open'); return; }
            // Se lee aquí y no al montar: este bloque corre una vez por pestaña y el Blade
            // renueva window.ALM_MOV_CFG en cada apertura (notas emitidas mientras tanto).
            var matches = (window.ALM_MOV_CFG.numerosNotas || [])
                .filter(function (n) { return String(n).toUpperCase().indexOf(q) !== -1; })
                .slice(0, 4);
            if (matches.length === 0) {
                box.innerHTML = '<div class="eliminar-nota-sug-empty">Sin Notas que coincidan</div>';
            } else {
                box.innerHTML = matches.map(function (n) {
                    var safe = String(n).replace(/'/g, "\\'");
                    return '<div class="eliminar-nota-sug-item" onclick="window.eliminarNotaPick(\'' + safe + '\')">' + n + '</div>';
                }).join('');
            }
            box.classList.add('open');
        };
        window.eliminarNotaPick = function (numero) {
            var input = document.getElementById('eliminarNotaInput'); if (input) input.value = numero;
            var box = document.getElementById('eliminarNotaSuggest'); if (box) box.classList.remove('open');
            var bx  = document.getElementById('eliminarNotaBox'); if (bx) bx.style.borderColor = '#e2e8f0';
        };
        window.submitEliminarNota = async function () {
            var input = document.getElementById('eliminarNotaInput');
            var raw = (input && input.value || '').trim().toUpperCase();
            if (!raw) {
                var box = document.getElementById('eliminarNotaBox'); if (box) box.style.borderColor = '#ef4444';
                fb('eliminarNotaFeedback', 'error', 'Ingresa un N° de Nota para continuar.');
                if (input) input.focus(); return;
            }
            // El propio modal (botón rojo "Eliminar" + aviso "Acción irreversible")
            // ES la confirmación — sin confirm() nativo del navegador encima.
            var sug = document.getElementById('eliminarNotaSuggest'); if (sug) sug.classList.remove('open');

            var btn = document.getElementById('eliminarNotaSubmitBtn');
            var html = btn ? btn.innerHTML : '';
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="material-icons" style="font-size:17px;animation:spin 1s linear infinite;">sync</i> Eliminando...'; }
            fb('eliminarNotaFeedback', 'info', 'Eliminando la nota ' + raw + ' y reversando stock…');
            try {
                var r = await window.apiFetch(URL_DESTROY + '?numero=' + encodeURIComponent(raw), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    method: 'DELETE'
                });
                var data = await r.json().catch(function(){ return {}; });
                if (!r.ok) {
                    fb('eliminarNotaFeedback', 'error', data.message || ('Error del servidor (' + r.status + ').'));
                    return;
                }
                var okMsg = data.message || 'Nota eliminada y stock revertido.';
                fb('eliminarNotaFeedback', 'success', okMsg);
                // Toast global: la notificación persiste DESPUÉS de que el modal se
                // cierre. Sin esto el único aviso era el recuadro dentro del modal,
                // que desaparecía con él (800ms) y era fácil de no ver.
                window.toast(okMsg, 'success');
                // Recargar la tabla de movimientos para que aparezcan las devoluciones de la reversión.
                if (window.loadMovimientos) window.loadMovimientos();
                setTimeout(function(){ window.closeEliminarNotaModal(); }, 800);
            } catch (err) {
                console.error('[Eliminar Nota]', err);
                fb('eliminarNotaFeedback', 'error', 'No se pudo contactar al servidor.');
            } finally {
                if (btn) { btn.disabled = false; btn.innerHTML = html; }
            }
        };
    }

    // Escape cierra el modal de Eliminar Nota si está abierto.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var x = document.getElementById('eliminarNotaOverlay');
        if (x && x.style.display === 'flex' && window.closeEliminarNotaModal) window.closeEliminarNotaModal();
    });
})();

// ── Borrar movimiento del kardex (SOLO super.admin) ─────────────────────────
// Dos botones, ambos renderizados por blade solo para super.admin y con su ruta DELETE
// gateada con can:super.admin (defensa real, no solo ocultar el botón):
//   · .alm-mov-undo  (deshacer)            → revierte el stock y recalcula los saldos.
//   · .alm-mov-purge (eliminar historial)  → borra el rastro SIN tocar el stock.
// Confirmación con el modal estilizado de la app (window.showModal), NO el confirm()
// nativo del navegador (que muestra la IP 127.0.0.1 y rompe el diseño). Las dos variantes
// comparten TODA la mecánica (DELETE + toast + recarga) en almBorrarFilaKardex; solo cambian
// la URL (data-undo-url / data-purge-url) y los textos de confirmación.
    function almBorrarFilaKardex(btn, opts) {
        if (!btn || btn.disabled) return;
        var url = btn.getAttribute(opts.urlAttr);
        if (!url) return;

        var ejecutar = function () {
            btn.disabled = true;
            if (window.showPreloader) window.showPreloader();
            window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                method: 'DELETE'
            })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, status: r.status, d: d }; });
            })
            .then(function (res) {
                if (window.hidePreloader) window.hidePreloader();
                if (!res.ok) {
                    window.toast(res.d.message || ('Error del servidor (' + res.status + ').'), 'error');
                    btn.disabled = false;
                    return;
                }
                window.toast(res.d.message || opts.okMsg, 'success');
                // Recargar la bitácora: la fila desaparece. En el deshacer los saldos
                // posteriores ya vienen recalculados; en el borrado solo-historial NO
                // (a propósito) — el stock no se tocó.
                if (window.loadMovimientos) window.loadMovimientos();
            })
            .catch(function () {
                if (window.hidePreloader) window.hidePreloader();
                window.toast('No se pudo contactar al servidor.', 'error');
                btn.disabled = false;
            });
        };

        if (window.showModal) {
            window.showModal({
                type: 'danger',
                title: opts.title,
                message: opts.message,
                confirmText: opts.confirmText,
                cancelText: 'Cancelar',
                onConfirm: ejecutar
            });
        } else {
            // Fallback defensivo si showModal no estuviera cargado.
            if (window.confirm(opts.title + ' ' + opts.message)) ejecutar();
        }
    }

    // El texto de la confirmación lo da el servidor (data-impacto-url →
    // AlmacenController::impactoDeshacerMovimiento): dice qué más se lleva el deshacer —el
    // resto de la Nota de Entrega, las devoluciones, el envío en Recepción— con las mismas
    // reglas que el deshacer de verdad. Si no responde, queda el aviso genérico.
    window.almDeshacerMovimiento = function (btn) {
        if (!btn || btn.disabled) return;
        var confirmar = function (avisos) {
            almBorrarFilaKardex(btn, {
                urlAttr: 'data-undo-url',
                title: '¿Deshacer este movimiento?',
                message: avisos.map(window.escapeHtml).join('<br>'),
                confirmText: 'Deshacer',
                okMsg: 'Movimiento deshecho.'
            });
        };
        var generico = ['Se revierte el stock y se borra sin dejar rastro.'];
        var url = btn.getAttribute('data-impacto-url');
        if (!url) { confirmar(generico); return; }

        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (d) {
                if (window.hidePreloader) window.hidePreloader();
                confirmar(d && Array.isArray(d.avisos) && d.avisos.length ? d.avisos : generico);
            });
    };

    window.almEliminarSoloHistorial = function (btn) {
        almBorrarFilaKardex(btn, {
            urlAttr: 'data-purge-url',
            title: '¿Eliminar del historial?',
            message: 'Se borra del historial; el stock no cambia. Irreversible.',
            confirmText: 'Eliminar del historial',
            okMsg: 'Registro eliminado del historial.'
        });
    };
