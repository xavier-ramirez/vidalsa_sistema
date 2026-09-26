/*
 * Modulo ALMACEN (/admin/almacen) — todo su JavaScript.
 *
 * Vivia dentro de index.blade.php, y eran ~250 KB que viajaban y se parseaban en CADA
 * apertura del modulo aunque no se ejecutaran (el IIFE sale por su guard en la segunda
 * visita). Aqui se baja UNA vez y el navegador lo reutiliza; el HTML del modulo adelgaza
 * otro tanto.
 *
 * Lo que depende de la peticion —rutas, permisos del usuario y catalogos— NO esta aqui:
 * llega en window.ALM_CFG, que el Blade escribe justo antes de cargar este archivo.
 *
 * OJO con la navegacion SPA: un <script src> ya cargado NO se vuelve a ejecutar
 * (navegacion.js · executeScripts), asi que este cuerpo corre una sola vez en toda la
 * sesion. Al reabrir el modulo, el propio Blade llama a window.almResetOnRemount() para
 * poner el estado al dia con el DOM nuevo — que es lo que hacia el guard de aqui abajo
 * cuando el codigo viajaba dentro del HTML.
 */
(function () {
    'use strict';

    // Datos de ESTA apertura (rutas, permisos, catalogos). Los escribe el Blade.
    var CFG = window.ALM_CFG || {};
    if (!CFG.rutas) { console.error('almacen_index.js: falta window.ALM_CFG'); return; }
    // Guard: si el módulo se re-monta (navegación SPA) no re-bindear listeners
    // de documento; las funciones window.alm* del primer montaje siguen válidas.
    if (window.__almIndexInit) {
        // Re-montaje SPA: el cuerpo del IIFE NO vuelve a correr, así que las variables del
        // closure del montaje anterior siguen vivas (selección fantasma que infla el contador,
        // pick pegado, "ver todo" activo…). Reseteamos TODO el estado contra el DOM nuevo.
        if (typeof window.almResetOnRemount === 'function') window.almResetOnRemount();
        return;
    }
    window.__almIndexInit = true;
    // Aviso para el Blade: el cuerpo ACABA de correr en esta apertura, asi que no hace falta
    // que llame a almResetOnRemount() (el estado ya nace limpio). El propio Blade lo borra.
    window.__almIndexArrancoAhora = true;

    var ROUTE_INDEX = CFG.rutas.index;
    // ROUTE_LOTE cubre TODOS los movimientos: ENTRADA, SALIDA (consumo) y SALIDA hacia otro
    // proyecto (el backend crea internamente el Traspaso). El frontend solo conoce este endpoint.
    var ROUTE_LOTE  = CFG.rutas.lote;
    // ── Flags de permiso del usuario actual, leidos desde Blade ──
    // Cada funcion CRUD verifica el flag relevante antes de actuar; si falta, salta
    // toast en lugar de ejecutar. Antes los botones se ocultaban; el cliente pidio
    // "ver todo + notificacion" para que ningun acceso quede silencioso.
    var HAS_ALM_MANAGE = CFG.puedeAlmManage;
    var HAS_PRODUCTOS  = CFG.puedeProductos;
    var HAS_MOVER      = CFG.puedeMover;
    var HAS_NOTA_ELIMINAR = CFG.puedeEliminar;
    // Helper: chequea permiso y si falta, emite toast con la razon. Devuelve true
    // si el usuario PASA (puede proceder). Asi las funciones se leen como:
    //   if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para crear almacenes.')) return;
    function ensurePerm(flag, msg) {
        if (flag) return true;
        if (window.showToast) window.showToast(msg, 'error'); else alert(msg);
        return false;
    }
    // Endpoint del preview PDF (sin commit a BD) — se usa antes del registro real
    // para que el usuario vea como quedaria la Nota y pueda editar/confirmar.
    var ROUTE_PREVIEW_SALIDA = CFG.rutas.salidaPreview;
    var ROUTE_PROD  = CFG.rutas.productosStore;
    // Catálogo de productos (CODIGO/NOMBRE/UM/PARTE) — lo usan el buscador FuzzySearch y los
    // selects de los modales. ANTES se embebía inline aquí (~500 KB de los 1155 productos) y el
    // módulo abría lento. AHORA arranca vacío y se carga por AJAX apenas la página queda lista
    // (no bloquea el render → abre de una). El buscador "tipear + Enter" del servidor sigue como
    // fallback mientras carga. La sincronización al crear/editar producto (más abajo) opera sobre
    // esta misma lista una vez cargada.
    window.almProductosLista = [];
    window.almProductosCargados = false;
    window.almCargarProductos = function () {
        if (window.almProductosCargados || window._almProductosCargando) return Promise.resolve();
        window._almProductosCargando = true;
        return window.apiFetch(CFG.rutas.productosAutocomplete, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (lista) {
            window.almProductosLista = Array.isArray(lista) ? lista : [];
            window.almProductosCargados = true;
        })
        .catch(function () { /* silencioso: el buscador tipear+Enter del servidor sigue como fallback */ })
        .finally(function () { window._almProductosCargando = false; });
    };
    window.almCargarProductos();
    // Categorías ya registradas — alimentan la lista del campo "Categoría" del modal de producto.
    window.almCategoriasLista = CFG.categorias;
    // Unidades de medida distintas ya registradas — alimentan el autocomplete del campo "UM" del modal.
    window.almUnidadesMedida = CFG.unidadesMedida;
    // Mapa { ID_FRENTE: ["CTR-2026-0042", ...] } para sugerir contratos en el modal "Registrar salida".
    // Los contratos se gestionan en /admin/frentes (columna CONTRATOS JSON de frentes_trabajo).
    window.almFrenteContratos = CFG.frenteContratos;
    function ROUTE_MIN(idAlm)   { return ROUTE_INDEX + '/almacenes/' + idAlm + '/minimo'; }
    // Delega en window.toast (dom_helpers.js); aqui solo el default de esta pantalla.
    function toast(msg, type) { if (!window.toast(msg, type || 'success') && type === 'error') alert(msg); }
    function pre()  { if (typeof window.showPreloader === 'function') window.showPreloader(); }
    function unpre(){ if (typeof window.hidePreloader === 'function') window.hidePreloader(); }
    function el(id){ return document.getElementById(id); }
    function val(id){ var e = el(id); return e ? String(e.value).trim() : ''; }
    var escHtml = window.escapeHtml;   // helper central (dom_helpers.js)

    // ── estado de los filtros que no tienen control visible propio ──
    // Estos dos atajos del header (Con stock / Stock bajo) son SIEMPRE off al entrar al
    // modulo, sin importar lo que diga la URL. Es preferencia del cliente: "cuando entro
    // al modulo no debe estar nada activo" — ver feedback Stock-bajo-no-persist. Si la
    // URL trae los parametros (link viejo en historial, etc.) los limpiamos abajo via
    // replaceState para que no queden contaminando la barra de direcciones.
    var _almInitParams = (function () { try { return new URLSearchParams(window.location.search); } catch (e) { return new URLSearchParams(); } })();
    var soloConSaldo = false; // atajo "Con stock" — el usuario lo enciende explicitamente
    var soloBajo     = false; // atajo "Stock bajo" — el usuario lo enciende explicitamente
    // "Ver todo el stock" (acción explícita): sin filtros la tabla abre vacía, así que "Ver
    // todo" es la ÚNICA forma de pedir TODO el inventario, y manda ver_todo=1. Lo enciende
    // almVerTodo(); cualquier otra recarga (sin opts.verTodo) lo apaga; la auto-carga
    // (append) lo conserva.
    var almVerTodoActivo = false;
    (function () {
        if (!_almInitParams.has('solo_bajo') && !_almInitParams.has('solo_con_saldo')) return;
        try {
            var u = new URL(window.location.href);
            u.searchParams.delete('solo_bajo');
            u.searchParams.delete('solo_con_saldo');
            window.history.replaceState({}, '', u.toString());
        } catch (e) {}
    })();



    // Cuando el usuario hace clic en una sugerencia del filtro Descripción, guardamos
    // aquí el ID del producto elegido → el backend filtra por match exacto (`id_producto`).
    // Si el usuario edita el texto, presiona Enter o limpia el campo, se borra → vuelve
    // al comportamiento LIKE %term% (búsqueda por similitudes).
    // Init desde URL: si llegamos por link directo con ?id_producto=NNN, sincronizar el
    // estado JS para que la primera llamada AJAX SI mande id_producto (sin esto la
    // pagina dropearia el parametro y el sidebar cruzado nunca apareceria).
    var almBuscarPickedId = (function () {
        var v = _almInitParams.get('id_producto');
        if (!v) return null;
        var n = parseInt(v, 10);
        return isFinite(n) && n > 0 ? n : null;
    })();
    // almBuscarPickedIds: CSV de IDs de presentaciones cuando se clickea una sugerencia
    // AGRUPADA (misma descripcion, varias UM). Manda id_producto_in → el backend devuelve
    // EXACTAMENTE esas presentaciones, no substrings (a diferencia del LIKE de `search`).
    var almBuscarPickedIds = null;
    // Auto-seleccion en el primer render: si llegamos por URL con ?id_producto=NNN,
    // marcamos la fila como si el usuario la hubiera clickeado (resaltado azul +
    // entrada en almSeleccion). Asi el usuario llega listo para escribir cantidad
    // y abrir la Nota de Entrega — sin el paso extra de "clic en la fila".
    // Solo dispara UNA VEZ: tras el primer almSelApplyToVisible se pone en false
    // para que clicks de deseleccion posteriores no se "deshagan" al recargar el tbody.
    var _almPendingAutoSelect = (almBuscarPickedId != null);

    // ID de producto cuyo input de cantidad debe recibir el foco tras la PRÓXIMA recarga del
    // tbody (lo consume almSelApplyToVisible una sola vez). Lo usa la Auditoría: al Guardar se
    // recarga la tabla, la fila sigue seleccionada, y así el teclado queda listo en el input
    // de cantidad sin tener que deseleccionar/reseleccionar. null = sin foco pendiente.
    var _almPendingFocusId = null;

    // Descarta el "pick" de producto (match EXACTO id_producto / id_producto_in de una
    // sugerencia). Punto ÚNICO de reset: lo llaman tanto los helpers del buscador (al
    // reteclear / limpiar) como los atajos del sidebar y los badges (categoría, "Con
    // stock", "Stock bajo", "Ver todo"). Sin esto el pick quedaba PEGADO y como el backend
    // prioriza id_producto(_in) sobre categoría/stock, esos atajos encendían el badge pero
    // NO cambiaban la tabla (seguía mostrando solo lo picado). Fuente única, sin duplicar.
    function almResetPick() {
        almBuscarPickedId = null;
        almBuscarPickedIds = null;
        // "Ver solo seleccionados" es OTRO filtro exclusivo: con él encendido, filtros()
        // manda id_producto_in y el backend hace whitelist por esos IDs IGNORANDO
        // búsqueda, categoría, UM y los badges de stock. Sin apagarlo aquí pasaba lo mismo
        // que con el pick pegado: pulsar "Stock bajo" encendía el badge y la tabla no
        // cambiaba. Se apaga la variable y su círculo ámbar a mano, sin llamar a
        // almAplicarSoloSel(false), que llama a esta función (sería recursión) y recargaría
        // la tabla por segunda vez — quien llama a almResetPick ya recarga.
        almSoloSel = false;
        var btnSoloSel = el('almBulkCounter');
        if (btnSoloSel) btnSoloSel.classList.remove('is-filtering');
    }

    // Criterio ÚNICO "es filtro" (categoría que CONTIENE 'FILTRO'): vive en el módulo compartido
    // window.ProductoSuggest, que es donde lo consultan también los autocompletes de Movimientos.
    // Los alias quedan en el scope del IIFE (no dentro de una función) para que los compartan el
    // buscador, la sección de equivalencias del modal y el guardado.
    var esCatFiltro = window.ProductoSuggest.esCategoriaFiltro;   // recibe la CATEGORIA
    var esFiltroCat = window.ProductoSuggest.esFiltro;            // recibe el PRODUCTO
    // Construye una entry del autocomplete con la MISMA forma que listaAutocomplete (backend):
    // ID_PRODUCTO, CODIGO, NOMBRE, UM, CATEGORIA, EQUIV, PARTE, PARTES. Punto ÚNICO para que el
    // producto creado/editado/restaurado se cachee completo — antes solo se guardaba
    // {ID,CODIGO,NOMBRE,UM} y tras editar dejaba de reconocerse como filtro / por nº de parte
    // hasta recargar. `equivs` (opcional) = nºs de parte conocidos en el cliente (los del modal).
    function almProdEntry(p, equivs) {
        var parts = Array.isArray(equivs) ? equivs.slice()
                  : (Array.isArray(p.PARTES) ? p.PARTES.slice() : []);
        return {
            ID_PRODUCTO: p.ID_PRODUCTO,
            CODIGO:      p.CODIGO,
            NOMBRE:      p.NOMBRE,
            UM:          p.UM,
            CATEGORIA:   p.CATEGORIA || '',
            EQUIV:       parts.join(' '),
            PARTE:       parts[0] || '',
            PARTES:      parts
        };
    }

    // Resuelve el valor "real" de un filtro con patron placeholder-background:
    //   - Si el usuario tipeo algo → ese texto GANA y se promueve a data-active
    //     (clear el value y poner el typed como placeholder, asi sigue visible
    //     pero el input queda listo para reescribir sin borrar).
    //   - Si no tipeo nada → cae al data-active (filtro previo que se mantiene).
    // Mismo patron que /admin/equipos: el filtro activo se muestra como
    // background gris, no como texto editable que toca borrar.
    function valActive(id) {
        var e = el(id); if (!e) return '';
        var typed = String(e.value || '').trim();
        if (typed) {
            e.dataset.active = typed;
            e.value = '';
            e.placeholder = typed;
            return typed;
        }
        return String(e.dataset.active || '').trim();
    }

    // ¿Este campo de filtro tiene algo puesto? Patrón placeholder-background: texto recién
    // tecleado (value) o el filtro ya aplicado (data-active, que es lo que se ve en gris).
    // Criterio ÚNICO: lo usan la "x" de limpiar y el icono de escaneo (que comparten sitio
    // dentro del cuadro y nunca deben verse a la vez).
    function filtroPuesto(i) {
        if (!i) return false;
        return !!((i.value && i.value.trim()) || (i.dataset.active && i.dataset.active.trim()));
    }
    function buscarActivo() { return filtroPuesto(el('almFiltroBuscar')); }

    // ── filtros → params (única fuente de verdad de los filtros activos) ──
    function filtros() {
        var p = new URLSearchParams();
        var alm = val('almSelAlmacen'); if (alm) p.set('id_almacen', alm);
        var b   = valActive('almFiltroBuscar'); if (b) p.set('search', b);
        // id_producto se manda SOLO si vino de un clic en sugerencia (match exacto).
        // Se prioriza sobre `search` en el backend (que sigue yendo para que la UI
        // muestre el texto y la URL compartible mantenga el contexto).
        if (almBuscarPickedId) p.set('id_producto', String(almBuscarPickedId));
        // Clic en sugerencia AGRUPADA (varias presentaciones): mandamos los IDs exactos como
        // id_producto_in → el backend devuelve SOLO esas presentaciones (no substrings del LIKE).
        else if (almBuscarPickedIds) p.set('id_producto_in', almBuscarPickedIds);
        var cat = valActive('almFiltroCat'); if (cat) p.set('categoria', cat);
        var um  = val('almFiltroUm');         if (um)  p.set('um', um);
        if (soloBajo)                   p.set('solo_bajo', '1');
        if (soloConSaldo)               p.set('solo_con_saldo', '1');
        if (almVerTodoActivo)           p.set('ver_todo', '1'); // "Ver todo el stock" explícito
        // "Ver solo seleccionados" (bulk counter clickado): manda los IDs como CSV. El
        // backend hace whitelist por estos IDs e IGNORA search/categoria/solo_bajo —
        // asi el usuario ve TODOS sus seleccionados, incluso si los otros filtros los
        // habian excluido de la vista cuando seleccionaba. Solo se manda si hay algo
        // seleccionado (si no, el backend caeria al modo normal sin filtro).
        // (Si vino de un clic en sugerencia agrupada ya pusimos id_producto_in arriba; el
        // bulk "solo seleccionados" no debe pisarlo.)
        if (!almBuscarPickedIds && almSoloSel && typeof almSelCount === 'function' && almSelCount() > 0) {
            p.set('id_producto_in', Object.keys(almSeleccion).join(','));
        }
        // reflejar estado "active" en los wrappers
        var setActive = function (sel, on) { var w = sel && sel.closest('.alm-filter'); if (w) w.classList.toggle('active', !!on); };
        setActive(el('almFiltroBuscar'), b); setActive(el('almFiltroCat'), cat && cat !== 'all');
        // toggle de la "x" de limpiar — visible si hay typed value O data-active (filtroPuesto).
        var tx = function (inputId) {
            var i = el(inputId); if (!i) return;
            var x = i.parentElement.querySelector('.filter-clear'); if (!x) return;
            x.style.display = filtroPuesto(i) ? 'flex' : 'none';
        };
        tx('almFiltroBuscar'); tx('almFiltroCat');
        window.QrScan.iconToggle();   // escanear visible solo si el buscador quedó vacío
        return p;
    }

    // ── Carga AJAX de la tabla + sidebar — con SCROLL INFINITO PEREZOSO ──────────
    // almCargar(opts?) acepta { offset, append, gen, verTodo, mostrar }:
    //   • Sin args (o offset=0)    → reemplaza la tabla, refresca stats + distribución
    //                                y actualiza la URL para compartir.
    //   • { offset>0, append }     → trae la siguiente página y la appendea al tbody.
    //   • { verTodo }              → enciende "Ver todo el stock" (cualquier otra recarga lo apaga).
    //   • { mostrar: idProducto }  → al terminar deja ese producto a la vista y resaltado
    //                                (ver almRecargarMostrando).
    // El siguiente lote NO se auto-encadena: lo dispara un IntersectionObserver sobre la
    // última fila cuando el usuario se acerca (mismo patrón que /admin/equipos). Antes se
    // encadenaban TODAS las páginas de golpe, lo que causaba el lag al llegar al final y
    // que el navegador quedara congelado al volver de otra pestaña (los lotes pendientes
    // se procesaban todos juntos). El observer no dispara con la pestaña oculta.
    //
    // Generación de carga: cada recarga completa (offset 0) la incrementa. Cada lote lleva
    // su generación; si el usuario filtra/recarga mientras baja el resto, los lotes viejos
    // se descartan (no pintan datos obsoletos ni siguen trayendo).
    var almLoadGen = 0;
    var almFiltrosVigentes = null; // filtros congelados de la carga fresca; los append los reusan
    window.almCargar = function (opts) {
        // back-compat: si llaman almCargar() sin args o almCargar('url-string') se trata
        // como recarga completa (offset=0). Si se pasa un objeto, respetamos sus opciones.
        if (typeof opts === 'string' || opts == null) opts = {};
        var offset = Math.max(0, parseInt(opts.offset || 0, 10));
        var append = !!opts.append && offset > 0;
        // ver_todo solo lo activa almVerTodo({verTodo:true}); cualquier otra recarga lo
        // apaga. En la auto-carga (append) NO se toca, para conservar "ver todo".
        if (!append) almVerTodoActivo = !!opts.verTodo;
        var body = el('almTableBody'); if (!body) return;
        var loadMore = el('almLoadingMore');

        // Generación: una recarga completa invalida la cadena de auto-carga en vuelo;
        // cada append hereda la suya y se aborta si ya cambió (ver comentario arriba).
        var gen;
        if (!append) {
            gen = ++almLoadGen;
        } else {
            gen = (typeof opts.gen === 'number') ? opts.gen : almLoadGen;
            if (gen !== almLoadGen) return; // una recarga nueva ya reemplazó esta cadena
        }

        // Construir URL preservando los filtros activos + offset.
        // En una carga fresca (!append) calculamos los filtros con filtros() —que TIENE efectos
        // secundarios (valActive vacía el input y lo promueve a data-active)— y CONGELAMOS el
        // resultado. En los append del scroll infinito NO volvemos a llamar filtros(): reusamos
        // los congelados. Antes filtros() corría en cada append y, si un lote llegaba mientras
        // el usuario tecleaba, le borraba lo escrito; además garantiza que la paginación use
        // exactamente los mismos filtros que la carga inicial.
        var f;
        if (!append) {
            f = filtros();
            almFiltrosVigentes = f.toString();
        } else {
            f = new URLSearchParams(almFiltrosVigentes || '');
        }
        f.set('offset', String(offset));
        var finalUrl = ROUTE_INDEX + '?' + f.toString();
        if (append) {
            if (loadMore) loadMore.style.display = 'block';
        } else {
            body.style.opacity = '0.5';
            pre();
        }
        window.apiFetch(finalUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                // Si una recarga nueva cambió la generación mientras volaba este fetch,
                // descartamos el resultado para no pintar datos obsoletos.
                if (gen !== almLoadGen) return;
                if (data.html !== undefined) {
                    if (append) {
                        var tmp = document.createElement('tbody');
                        tmp.innerHTML = data.html;
                        var _nuevasRows = [];
                        // La fila que almMostrarProducto fijó arriba (a lo sumo una) no se repite
                        // cuando llega su lote. Se busca UNA vez por lote, no por fila: el tbody
                        // crece a miles de filas.
                        var _fijada = body.querySelector('tr.alm-row[data-fijada="1"]');
                        var _idFijada = _fijada ? _fijada.getAttribute('data-id-producto') : null;
                        while (tmp.firstElementChild) {
                            var _r = tmp.firstElementChild;
                            if (_idFijada && _r.getAttribute('data-id-producto') === _idFijada) {
                                _r.remove();
                                continue;
                            }
                            body.appendChild(_r);
                            if (_r.nodeType === 1 && _r.classList.contains('alm-row')) _nuevasRows.push(_r);
                        }
                        // SOLO las filas nuevas (no re-itera todo el tbody en cada lote → evita el freeze).
                        almSelApplyToRows(_nuevasRows);
                    } else {
                        body.innerHTML = data.html;
                        almSelApplyToVisible();
                        if (opts.mostrar) almMostrarProducto(opts.mostrar, gen);
                    }
                }
                // El aviso se repinta SOLO en la primera pagina. El backend manda la
                // bandera siempre (va en $resp, fuera del if que omite stats en el scroll),
                // pero repintarlo en cada lote del scroll borraria el aviso de la busqueda
                // que se esta viendo.
                if (!append) {
                    // El termino sale de los filtros CONGELADOS, que son los que se
                    // consultaron de verdad. Leerlo del input aqui daria el texto de ahora:
                    // si el usuario siguio tecleando mientras volaba la peticion, el aviso
                    // nombraria una palabra distinta de la que hay en la tabla. (Y valActive
                    // ademas vacia el input, que es justo lo que este .then() no debe tocar.)
                    almPintarAvisoBusqueda(
                        data.aproximada,
                        new URLSearchParams(almFiltrosVigentes || '').get('search') || ''
                    );
                    // Si esta busqueda salio aproximada, se marca en los filtros congelados
                    // que reusan las paginas del scroll: asi cada una va DIRECTA al modo
                    // tolerante en vez de repetir la consulta exacta que ya se sabe vacia.
                    if (data.aproximada) {
                        var _fa = new URLSearchParams(almFiltrosVigentes || '');
                        _fa.set('aprox', '1');
                        almFiltrosVigentes = _fa.toString();
                    }
                }
                // Stats + distribución solo en la primera página (el backend ya las omite
                // cuando offset>0; aquí evitamos rebajar a "—" lo que ya pintamos).
                if (!append && data.stats) {
                    var num = function (id, v) {
                        var e = el(id); if (!e) return;
                        // KPIs (conteos): miles con punto (formato latino). '—' cuando no hay valor.
                        var f = parseFloat(v);
                        e.textContent = (v == null) ? '—' : (isNaN(f) ? v : f.toLocaleString('es-ES'));
                    };
                    num('almStatsTotal',    data.stats.total);
                    num('almStatsConSaldo', data.stats.con_saldo);
                    num('almStatsBajo',     data.stats.stock_bajo);
                }
                if (!append && data.distribucionHtml !== undefined) {
                    // Un clic en una fila que aún no respondió ya no debe pisar este panel.
                    _almOtrosPedido++;
                    var dc = el('almDistribucionContainer'); if (dc) dc.innerHTML = data.distribucionHtml;
                }
                // URL para compartir — solo en recarga completa (offset no va a la URL).
                // id_producto_in queda EXCLUIDO porque es estado efimero del bulk counter
                // (la seleccion vive solo en memoria JS; meterla en la URL confundiria a
                // quien abra el link: veria los productos pero sin badge de seleccion).
                if (!append) {
                    try {
                        var cleanU = new URL(ROUTE_INDEX, window.location.origin);
                        filtros().forEach(function (v, k) {
                            // id_producto_in (selección efímera) y ver_todo (acción transitoria)
                            // NO van a la URL: no se honran al recargar y solo confundirían.
                            if (k === 'id_producto_in' || k === 'ver_todo') return;
                            cleanU.searchParams.set(k, v);
                        });
                        window.history.replaceState({}, '', cleanU.toString());
                    } catch (e) {}
                }
                // Scroll infinito PEREZOSO (mismo patrón que /admin/equipos): en vez de
                // auto-encadenar TODAS las páginas de golpe (causaba el lag al llegar al final
                // y el congelamiento del navegador al volver de otra pestaña), observamos la
                // ÚLTIMA fila y traemos el siguiente lote SOLO cuando el usuario se acerca.
                // El IntersectionObserver NO dispara con la pestaña oculta → al volver no se
                // acumula un atasco de lotes pendientes.
                if (data.hasMore && typeof data.nextOffset === 'number') {
                    var _rows = body.querySelectorAll('tr.alm-row');
                    var lastRow = _rows.length ? _rows[_rows.length - 1] : null;
                    if (lastRow && !lastRow.dataset.infObserved) {
                        lastRow.dataset.infObserved = '1';
                        var _nextOffset = data.nextOffset, _gen = gen;
                        var infObs = new IntersectionObserver(function (entries, obs) {
                            if (!entries[0] || !entries[0].isIntersecting) return;
                            obs.disconnect();
                            if (_gen !== almLoadGen) return; // una recarga nueva ya reemplazó esta lista
                            window.almCargar({ offset: _nextOffset, append: true, gen: _gen });
                        }, { root: null, rootMargin: '1000px', threshold: 0 });
                        infObs.observe(lastRow);
                    }
                }
            })
            .catch(function () {
                toast('No se pudo cargar el inventario.', 'error');
                // El aviso "Sin conexión" con su botón lo saca el interceptor global de
                // fetch (estructura_base) para CUALQUIER petición de la app.
            })
            .finally(function () {
                if (append) {
                    // Carga perezosa: el spinner de "cargando más" se oculta al terminar cada lote
                    // (el siguiente lo dispara el IntersectionObserver al acercarse a la última fila).
                    if (loadMore) loadMore.style.display = 'none';
                } else {
                    body.style.opacity = '1'; unpre();
                }
            });
    };

    // ── Tras operar sobre UN producto (Auditoría, Stock mínimo, Ubicación, Editar, Crear) ──
    // La tabla se recarga para confirmar con el dato fresco, pero SIN perder el contexto y
    // dejando ese producto a la vista y resaltado, para que se note el cambio:
    //  - "Ver todo el stock" se conserva: almCargar() a secas lo apaga (solo lo enciende
    //    almCargar({verTodo:true})) y la tabla quedaba vacía, sin el producto recién tocado.
    //  - Si los filtros ya no lo incluyen (se renombró o cambió de categoría, salió de
    //    "Stock bajo"/"Con stock", o estaba en un lote del scroll que aún no se recarga),
    //    se trae su fila sola y se fija arriba (data-fijada). El append del scroll no la
    //    repite al llegar su lote (ver almCargar).
    function almRecargarMostrando(idProducto) {
        almCargar({ verTodo: almVerTodoActivo, mostrar: idProducto ? String(idProducto) : null });
    }

    // Lo llama almCargar al terminar una recarga completa con opts.mostrar. `gen` es la
    // generación de esa recarga: si mientras volaba la fila suelta el usuario ya filtró de
    // nuevo, se descarta para no meter una fila en una tabla que ya es otra.
    function almMostrarProducto(idProducto, gen) {
        var body = el('almTableBody'); if (!body) return;
        var tr = body.querySelector('tr.alm-row[data-id-producto="' + idProducto + '"]');
        if (tr) { almResaltarFila(tr); return; }
        // solo_filas: el servidor no calcula KPIs ni distribución (aquí solo sirve la fila).
        var url = ROUTE_INDEX + '?id_almacen=' + encodeURIComponent(val('almSelAlmacen')) + '&id_producto=' + encodeURIComponent(idProducto) + '&solo_filas=1';
        window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (gen !== almLoadGen || !data || !data.html) return;
                // Mientras volaba esta petición pudo llegar el lote del scroll que la trae
                // (la recarga deja la página abajo y el observador pide el siguiente al
                // instante): entonces ya está en su sitio y solo se resalta, sin fijar otra.
                var yaEsta = body.querySelector('tr.alm-row[data-id-producto="' + idProducto + '"]');
                if (yaEsta) { almResaltarFila(yaEsta); return; }
                var tmp = document.createElement('tbody');
                tmp.innerHTML = data.html;
                var nueva = tmp.querySelector('tr.alm-row');
                if (!nueva) return;
                // Tabla en estado vacío ("usa los filtros" / "sin coincidencias"): se quita el aviso.
                if (!body.querySelector('tr.alm-row')) body.innerHTML = '';
                nueva.dataset.fijada = '1';
                body.insertBefore(nueva, body.firstChild);
                almSelApplyToRows([nueva]);
                almResaltarFila(nueva);
            })
            .catch(function () { /* la tabla ya quedó recargada; solo falta el resalte */ });
    }

    function almResaltarFila(tr) {
        tr.classList.remove('alm-row-recien');
        void tr.offsetWidth;                         // reinicia la animación si se repite
        tr.classList.add('alm-row-recien');
        tr.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        setTimeout(function () { tr.classList.remove('alm-row-recien'); }, 2600);
    }

    function formatNum(n) {
        n = parseFloat(n || 0);
        if (isNaN(n)) return '0';
        // Formato latino: miles con punto, decimal con coma, hasta 3 decimales sin ceros sobrantes.
        return n.toLocaleString('es-ES', { maximumFractionDigits: 3 });
    }

    // ── helpers desde el sidebar / distribución ──
    // Limpia buscar + categoría (mismo bloque que antes vivía inline solo en almVerTodo).
    // Punto ÚNICO: lo reutilizan almVerTodo y los badges "Con stock"/"Stock bajo" al
    // ENCENDERSE — encender un atajo global es una acción explícita para ver ESA vista,
    // igual que "Ver todo", así que no debe quedar intersectada en silencio con una
    // búsqueda/categoría que hubiera quedado activa de antes (misma causa que arregló
    // almResetBadges, pero en la dirección inversa: aquí el atajo es la acción nueva y
    // la búsqueda es la que quedaba pegada).
    function almLimpiarBusquedaYCategoria() {
        var bi = el('almFiltroBuscar');
        if (bi) { bi.value = ''; bi.dataset.active = ''; bi.placeholder = bi.dataset.placeholderEmpty || 'Buscar por código o descripción…'; }
        var ci = el('almFiltroCat');
        if (ci) { ci.value = ''; ci.dataset.active = ''; ci.placeholder = ci.dataset.placeholderEmpty || 'Filtrar por categoría…'; }
        almSuggestHide(); almCatSuggestHide();
    }
    // Suelta la unidad de medida del panel avanzado. La llaman "Limpiar Todo", "Ver todo" y
    // los que piden UN producto puntual (sugerencia, QR, "En otros almacenes"): con otra
    // unidad puesta, ese producto quedaba escondido y la tabla salía vacía.
    // Con la MISMA llamada que su X (selectOption), para que el desplegable quede también sin
    // filtro a la vista. La bandera evita la recarga doble: quien suelta la unidad recarga
    // por su cuenta. Vive en window: el listener de 'dropdown-selection' se registra una vez.
    function almSoltarUm() {
        if (!el('almFiltroUmDropdown')) return;
        window.__almUmSilencio = true;
        try { window.selectOption('almFiltroUmDropdown', '', 'Todas'); } finally { window.__almUmSilencio = false; }
    }
    window.almVerTodo = function () {
        almLimpiarBusquedaYCategoria();
        almSoltarUm();
        soloBajo = false; soloConSaldo = false;
        almPintarBadges();
        almResetPick(); // descartar match exacto (id_producto/_in) si quedó pegado de un clic previo
        almCargar({ verTodo: true }); // acción explícita: mostrar TODO el inventario del almacén
    };
    // Los dos badges del header son TOGGLES: clic con el mismo filtro activo lo apaga.
    // Clic en uno mientras el otro estaba encendido los hace mutuamente exclusivos.
    // En cualquier caso, almPintarBadges() refleja el estado para que el usuario VEA
    // cual filtro esta limitando la tabla (anillo blanco + fondo saturado en .is-on).
    // `force`=true la ENCIENDE siempre (no togglea): lo usa la sugerencia "VER TODO
    // EL STOCK" del buscador, que debe mostrar SOLO los productos con existencias (>0).
    window.almFiltrarConSaldo = function (force) {
        soloConSaldo = force ? true : !soloConSaldo;
        if (soloConSaldo) { soloBajo = false; almLimpiarBusquedaYCategoria(); }
        almResetPick(); // un badge global no debe quedar anulado por un pick exacto pegado
        almPintarBadges(); almCargar();
    };
    window.almFiltrarBajo = function () {
        soloBajo = !soloBajo;
        if (soloBajo) { soloConSaldo = false; almLimpiarBusquedaYCategoria(); }
        almResetPick(); // un badge global no debe quedar anulado por un pick exacto pegado
        almPintarBadges(); almCargar();
    };
    function almPintarBadges() {
        var bcs = el('almBadgeConSaldo'); if (bcs) bcs.classList.toggle('is-on', !!soloConSaldo);
        var bb  = el('almBadgeBajo');     if (bb)  bb.classList.toggle('is-on',  !!soloBajo);
        almPintarAvanzado();
    }
    // Botón "Filtros avanzados" en rojo si hay algo puesto dentro (la unidad de medida). Pasa
    // por almPintarBadges: así lo pintan también el arranque y el re-montaje de la vista.
    function almPintarAvanzado() {
        var btn = el('almAdvBtn');
        if (btn) btn.classList.toggle('activo', !!val('almFiltroUm'));
    }
    // Sin stopPropagation: el clic sigue hasta document, donde los cierres de siempre bajan
    // Acciones, las sugerencias y los desplegables (almacén…) — un desplegable a la vez.
    window.almToggleAvanzado = function () {
        var p = el('almAdvPanel'); if (!p) return;
        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    };
    window.almAvanzadoUm = function () {
        almResetPick();
        almPintarAvanzado();
        almCargar();
    };
    // "Limpiar Todo" limpia lo del panel; las tarjetas Con stock / Stock bajo van aparte.
    // Sin nada puesto no hace nada: antes recargaba la tabla igual y parpadeaba por nada.
    window.almAvanzadoLimpiar = function () {
        if (!val('almFiltroUm')) return;
        almSoltarUm();
        almPintarAvanzado();
        almResetPick();
        almCargar();
    };
    // Los atajos globales del Consolidado ("Stock bajo" / "Con stock") son excluyentes con
    // filtrar por texto o categoría, y se SUELTAN al APLICAR el filtro nuevo (almBuscarEnter,
    // almBuscarPick, almCatEnter, almCatPick llaman a almResetBadges), NO al hacer foco en el
    // campo: soltarlos al foco recargaba la tabla sin ningún filtro y, como el almacén sin
    // filtros abre vacío, la tabla quedaba en blanco antes de escribir nada (18-09-2026).
    // Mientras se escribe, la tabla sigue mostrando lo del atajo.
    window.almResetBadges = function() {
        soloConSaldo = false;
        soloBajo = false;
        almPintarBadges();
    };
    // Reset COMPLETO del estado del módulo — lo llama el guard cuando la vista se re-monta por
    // navegación SPA. Reúne las piezas que ya limpian cada cosa (badges + pick + selección) para
    // no duplicar lógica; almSelClear vacía almSeleccion/almSoloSel y refresca la barra flotante.
    window.almResetOnRemount = function () {
        window.almResetBadges();
        if (typeof almResetPick === 'function') almResetPick();
        almVerTodoActivo = false;
        almUltimaVista = null;
        if (typeof window.almSelClear === 'function') window.almSelClear();
        // Recolocar Consolidado y "En otros almacenes" en el DOM NUEVO: el cuerpo del IIFE
        // (que los coloca al montar) no vuelve a correr en una re-entrada por SPA.
        if (typeof window.almColocarSidebarMovil === 'function') window.almColocarSidebarMovil();
        // Mismo motivo: el observador de la lista de frentes apunta al <div> del montaje
        // anterior, que ya no existe (ver almFrentesObservar).
        if (typeof almFrentesObservar === 'function') almFrentesObservar();
        // Red de seguridad del guard anti doble-alta: vive en window, así que si una
        // excepción SÍNCRONA cortara almGuardarProducto antes de lanzar la petición, el
        // finally no correría y el modal se quedaría mudo hasta recargar la página. Al
        // reabrir el módulo no hay ninguna alta en vuelo, así que soltarlo es seguro.
        window._almGuardandoProducto = false;
    };
    // Pintar al inicio para reflejar el estado leido de la URL.
    almPintarBadges();

    // ── Autocompletado del filtro "Buscar" (código o descripción), con el look de los desplegables de la app ──
    // Normalizacion (sin acentos + minusculas): delega en el modulo compartido FuzzySearch.
    function almNorm(s) { return window.FuzzySearch.norm(s); }
    function almSuggestHide() { var box = el('almFiltroBuscarSuggest'); if (box) box.classList.remove('open'); }
    function almCatSuggestHide() { var box = el('almFiltroCatSuggest'); if (box) box.classList.remove('open'); }

    // Helpers compartidos por todos los autocompletes del modulo (almBuscar/almCat/almProdCat/almProdUm).
    // `almSuggestFilter` aplica el patron "lista filtrada por term normalizado o todo si forceAll/term vacio".
    // `almSuggestApply` setea el HTML del box (con fallback a empty state) y lo abre.
    function almSuggestFilter(lista, term, getKey, forceAll) {
        if (forceAll || term === '') return (lista || []).slice(0);
        return (lista || []).filter(function (it) { return almNorm(getKey(it)).indexOf(term) > -1; });
    }
    function almSuggestApply(box, html, emptyHtml) {
        if (!box) return;
        // Mutex con el menu Acciones: si las sugerencias se abren mientras Acciones
        // estaba desplegado, cerramos Acciones (no deben coexistir dos overlays).
        var accMenu = document.getElementById('almAccionesMenu');
        if (accMenu && accMenu.style.display === 'block') accMenu.style.display = 'none';
        box.innerHTML = html || (emptyHtml || '<div class="alm-suggest-empty">Sin coincidencias.</div>');
        box.classList.add('open');
        almSuggestAnclar(box);
    }
    // Coloca una lista .alm-suggest-float (position:fixed) justo debajo de su campo: el input que
    // diga data-ancla (cuando dos campos comparten la lista, el que se está escribiendo) o, si no,
    // su contenedor. data-ancho-min: ancho mínimo en px, si el modal tiene sitio. Solo actúa sobre
    // esas: las sugerencias de la barra de filtros son absolute normales y no necesitan anclaje.
    // Si no cabe debajo, se abre hacia arriba.
    function almSuggestAnclar(box) {
        if (!box || !box.classList.contains('alm-suggest-float') || !box.classList.contains('open')) return;
        var campo = (box.dataset.ancla && el(box.dataset.ancla)) || box.parentElement; if (!campo) return;
        almAnclarFlotante(box, campo, parseInt(box.dataset.anchoMin, 10) || 0);
    }
    // La matemática del anclaje, en UN solo sitio: la usan los suggest de UM/categoría y la
    // lista de frentes del modal de almacén, que flota por el mismo motivo (ver su CSS).
    // anchoMin: la caja puede ser más ancha que su campo (cédula, placa o vehículo en PC) si el
    // modal tiene sitio; si así se sale por la derecha, se alinea con el borde derecho del campo.
    // En el teléfono el campo ya ocupa casi todo el modal: ahí manda su ancho, o quedaría descuadrada.
    function almAnclarFlotante(caja, ancla, anchoMin) {
        var r = ancla.getBoundingClientRect(), ancho = r.width, izq = r.left;
        if (anchoMin > r.width) {
            var cont = (ancla.closest('.alm-modal') || document.documentElement).getBoundingClientRect();
            if (cont.width - 24 - r.width >= 48) {
                ancho = Math.min(anchoMin, cont.width - 24);
                if (izq + ancho > cont.right - 12) izq = Math.max(cont.left + 12, r.right - ancho);
            }
        }
        caja.style.left  = izq + 'px';
        caja.style.width = ancho + 'px';   // el ancho se fija ANTES de medir el alto
        var alto = caja.offsetHeight;
        var cabeAbajo = (window.innerHeight - r.bottom - 8) >= alto;
        caja.style.top = (!cabeAbajo && r.top > alto ? (r.top - alto - 4) : (r.bottom + 4)) + 'px';
    }
    // Ancla la lista de frentes contra su propia caja. El multiselect lo abre/cierra el
    // componente global (uicomponents.js) poniendo .active en el contenedor, así que aquí
    // no se toca ese comportamiento: solo se coloca la lista cuando ya está abierta.
    function almFrentesAnclar() {
        var caja = document.getElementById('almNvFrentesSelect');
        if (!caja || !caja.classList.contains('active')) return;
        var lista = caja.querySelector('.multiselect-content');
        if (lista) almAnclarFlotante(lista, caja);
    }
    // Al ser fixed, la lista no sigue sola a su campo: se reancla si la ventana cambia de
    // tamaño o si algo se desplaza (el cuerpo del modal, con scroll en captura porque el
    // evento scroll de un elemento no burbujea).
    function almSuggestReanclar() {
        document.querySelectorAll('.alm-suggest-float.open').forEach(almSuggestAnclar);
        almFrentesAnclar();
    }
    window.addEventListener('resize', almSuggestReanclar);
    document.addEventListener('scroll', almSuggestReanclar, true);
    // Quién ABRE la lista de frentes es el componente global (uicomponents.js) al poner
    // .active en el contenedor. En vez de tocar ese componente —lo comparten Permisos y
    // otros módulos— se observa esa clase: cada vez que cambia, se reancla. Así da igual
    // por dónde se abra (clic en el trigger, en el input, o cerrarla desde fuera).
    // Engancha —o RE-engancha— el observador a la caja de frentes que haya AHORA en el DOM.
    // Al volver al módulo por la navegación interna ese <div> es un nodo NUEVO: el
    // observador del primer montaje se quedaba vigilando uno ya desechado, así que la lista
    // no se anclaba AL ABRIRSE y salía descolocada hasta que un scroll o un resize la
    // recolocaban (almSuggestReanclar sí relee el nodo en cada evento). Por eso se vuelve a
    // llamar desde almResetOnRemount, igual que almColocarSidebarMovil.
    function almFrentesObservar() {
        // El disconnect va PRIMERO: si al remontar la caja todavía no estuviera en el DOM,
        // salir antes dejaría vivo el observador del montaje anterior, vigilando un nodo
        // huérfano. Desconectar sin nada que observar no cuesta nada.
        if (window.__almFrentesObs) { window.__almFrentesObs.disconnect(); window.__almFrentesObs = null; }
        var caja = document.getElementById('almNvFrentesSelect');
        if (!caja) return;
        window.__almFrentesObs = new MutationObserver(function () {
            // rAF: la clase se pone antes de que el navegador pinte la lista, y hasta que la
            // pinta su offsetHeight es 0 — anclar en ese momento la colocaría mal.
            requestAnimationFrame(almFrentesAnclar);
        });
        window.__almFrentesObs.observe(caja, { attributes: true, attributeFilter: ['class'] });
    }
    almFrentesObservar();
    // ── Buscador "estilo Google" — fuzzy + ranking por relevancia ─────────────
    //   El algoritmo (normaliza, tokeniza, tolera typos por Levenshtein y rankea por
    //   relevancia) vive en el módulo compartido window.FuzzySearch
    //   (public/js/maquinaria/fuzzy_search.js, cargado global en el layout base → SPA-safe),
    //   reutilizado también por Recepción. Aquí solo queda el alias del tokenizado (lo
    //   usa almBuscarSuggest para el link "VER TODO"); el ranking se hace con
    //   FuzzySearch.rank. Mismo criterio reflejado en el backend (AlmacenController::index)
    //   para el fallback de "tipear + Enter".
    function almTokenizar(raw) { return window.FuzzySearch.tokenize(raw); }

    window.almBuscarSuggest = function () {
        almCatSuggestHide();
        var inp = el('almFiltroBuscar'), box = el('almFiltroBuscarSuggest');
        if (!inp || !box) return;
        var rawTerm = inp.value.trim();
        var tokens = almTokenizar(rawTerm);
        var rawNorm = almNorm(rawTerm).replace(/\s+/g, ' ');
        var lista = window.almProductosLista || [];

        // "VER TODO EL STOCK" se comporta como una recomendación más de la
        // lista, igual que "TODOS LOS FRENTES" en el filtro de /admin/equipos:
        // sale con el campo vacío y, al escribir, solo si el texto coincide con
        // ella (substring). Al clickearla filtra a "Con stock" (solo con existencias >0).
        var verTodoLink = (tokens.length === 0 || (rawNorm && 'ver todo el stock'.indexOf(rawNorm) !== -1))
            ? '<div class="alm-suggest-item" data-action="ver-todo"><span class="nom">VER TODO EL STOCK</span></div>'
            : '';


        // Categoría ACTIVA (la que filtra la tabla). Lectura NO mutante: vive en data-active
        // tras un almCatPick; si el usuario tipeó pero no aplicó, cae al value. Sirve para
        // avisar cuando un material existe pero pertenece a otra categoría (badge + toast).
        var catActiva = (function () { var e = el('almFiltroCat'); if (!e) return ''; return String(e.dataset.active || e.value || '').trim(); })();
        var catActivaNorm = almNorm(catActiva);
        // Mismo criterio que el backend (CATEGORIA LIKE %cat%): "pertenece" = la categoría del
        // producto CONTIENE el texto filtrado (normalizado). Sin filtro → todo pertenece.
        function perteneceACat(catProd) {
            if (!catActivaNorm) return true;
            return almNorm(catProd || '').indexOf(catActivaNorm) !== -1;
        }

        // Agrupacion por DESCRIPCION (regla compartida, window.ProductoSuggest): desde que
        // Recepcion permite la misma descripcion en varias presentaciones (distinta UM =
        // producto aparte), el catalogo puede tener N productos con identico NOMBRE. La lista
        // muestra UNA sola entrada por descripcion con un badge de cuantas presentaciones
        // tiene; al clickearla, si tiene >1 se mandan TODOS sus ids (id_producto_in) para que
        // la tabla liste exactamente esas, y si es unica se fija id_producto (match exacto).
        var grupos = window.ProductoSuggest.agrupar(lista);

        // Recorremos la lista una vez y recogemos TODOS los matches del catalogo (esten o no
        // en este almacen). Razon (pedido del cliente 2026-05-19): si un producto existe en el
        // sistema, debe SIEMPRE aparecer en la sugerencia — sino la gente cree que no esta
        // registrado y crea duplicados. Los que no tienen fila en almacen_stock del almacen
        // actual se marcan con un badge "sin stock aquí" pero IGUAL se pueden clickear.
        // Con la invariante de storeProducto (asegurarStock para todos los almacenes activos)
        // este caso debería ser raro, pero es defensa en profundidad por si un almacén nuevo
        // se crea después de un producto o por importaciones legacy.
        //
        // Ranking + dedupe compartidos: término vacío → catálogo en su orden natural (NOMBRE);
        // con término → mejores por relevancia (fuzzy + score, incluyendo nºs de parte
        // equivalentes). El dedupe deja una entrada por descripción (los filtros, una por
        // producto: son modelos distintos) hasta 17. Lo ÚNICO propio de esta vista es el
        // filtro por categoría activa, que va como predicado `aceptar`.
        var matches = window.ProductoSuggest.dedupe(
            window.ProductoSuggest.rankear(lista, rawTerm), grupos, 17,
            function (p, grp) {
                if (!catActivaNorm) return true;
                // Un filtro se juzga por su propia categoría; una descripción agrupada entra si
                // ALGUNA de sus presentaciones pertenece (suelen compartir categoría).
                if (esFiltroCat(p)) return perteneceACat(p.CATEGORIA);
                return !!(grp && grp.items.some(function (x) { return perteneceACat(x.CATEGORIA); }));
            }
        );

        if (!matches.length) {
            // Si el catálogo async aún no cargó, mostramos "Cargando…" en vez de "Sin
            // coincidencias" (que sugeriría por error que el producto no existe → riesgo de
            // crear duplicados). El fallback "teclear + Enter" contra el servidor sigue vivo.
            var vacioHtml = (!window.almProductosCargados)
                ? '<div class="alm-suggest-empty">Cargando productos…</div>'
                : '<div class="alm-suggest-empty">Sin coincidencias.</div>';
            box.innerHTML = verTodoLink + vacioHtml;
        } else {
            // Mostrar SOLO el NOMBRE; data-pick guarda el texto que va al cuadro al elegir: el
            // NOMBRE y —en filtros que matchearon por nº de parte— ese nº DELANTE del nombre, para
            // que se vea CUÁL equivalencia buscaste. Escribir encima del texto pegado sigue dando
            // coincidencias via LIKE %term% del backend (tokeniza y matchea nº de parte + nombre).
            // (El badge "sin stock aquí" se retiró a pedido del cliente.) Los filtros muestran su
            // número de parte delante del nombre y NO se agrupan (cada uno es un modelo distinto).
            var html = verTodoLink + matches.map(function (p) {
                // escHtml (helper central), NO un replace que BORRE caracteres: 237 productos
                // llevan comillas en el nombre porque ahi van las pulgadas (DISCO DE CORTE 7").
                // Borrandolas, la sugerencia perdia la medida y el texto que se copia al cuadro
                // salia mutilado.
                //
                // escHtml y NO escapeAttrJs aunque vaya en un atributo: estos (title, data-*)
                // los lee getAttribute, no los evalua ningun JS. escapeAttrJs anade ademas la
                // capa de literal JS (\' y \\), y esos backslashes se quedarian EN EL TEXTO.
                // escapeAttrJs solo vale cuando el valor va dentro de un onclick="fn('…')".
                var nom = escHtml(p.NOMBRE || '');
                var cod = escHtml(p.CODIGO || '');
                // grupo de ESTA sugerencia (los filtros tienen el suyo propio, de 1: cada uno
                // es un modelo aparte, no una presentación — lo resuelve claveGrupo).
                var grp = grupos[window.ProductoSuggest.claveGrupo(p)] || { count: 1, ids: [p.ID_PRODUCTO] };
                var multi = grp.count > 1;
                // Clic en la sugerencia:
                //  - Descripcion UNICA → data-pid = id exacto (match de 1 producto).
                //  - VARIAS presentaciones → data-pids = CSV de los IDs de ESAS presentaciones;
                //    el clic manda id_producto_in y la tabla muestra EXACTAMENTE esas (no
                //    substrings: "ABRAZADERA" no debe arrastrar "ABRAZADERA 5\" PARA MANGUERA…").
                var pid  = multi ? '' : (p.ID_PRODUCTO || '');
                var pids = multi ? (grp.ids || []).join(',') : '';
                // El codigo/serial NO se muestra en la lista (pedido cliente): la sugerencia
                // queda limpia con SOLO la descripcion. Igual se PUEDE buscar por serial (el
                // scoring del autocomplete y el backend matchean CODIGO) y el serial sigue en
                // el title de la fila (hover). Cuando una descripcion tiene varias
                // presentaciones se muestra un ICONO compacto (layers) + el numero, en
                // vez del texto "N pres." (robaba ancho a la descripcion). El detalle
                // completo queda en el tooltip.
                var rightBadge = window.ProductoSuggest.badgePresentaciones(grp, 'alm-suggest-cod');
                // Filtros: el nº de parte va DELANTE del tipo. Se muestra la EQUIVALENCIA que
                // COINCIDE con lo buscado (si buscas "AL-7723" sale ese, no la principal) —
                // helper compartido de FuzzySearch (misma lógica que movimientos/recepción).
                var parteMostrar = window.FuzzySearch.matchedPart(rawTerm, p.PARTES, p.PARTE);
                // Mismo tipo de letra/color/tamaño que la descripción (.nom): 13.5px, #475569,
                // peso 600 — para que el nº de parte se lea igual que el tipo, no más apagado.
                var parteSafe   = parteMostrar ? escHtml(String(parteMostrar)) : '';
                var partePrefix = parteSafe
                    ? '<span class="alm-suggest-parte" style="font-size:13.5px;color:#475569;font-weight:600;margin-right:7px;white-space:nowrap;">' + parteSafe + '</span>'
                    : '';
                // Texto que queda en el cuadro al elegir: si la sugerencia matcheó por nº de parte
                // (equivalencia, p.ej. "P164378"), ese nº va DELANTE de la descripción para que se
                // vea CUÁL equivalencia buscaste — no solo la descripción. El filtrado real sigue
                // usando id_producto (match exacto), así que este texto es solo lo que se muestra.
                // Va en un data-* que se lee con getAttribute: escHtml sobre el texto ORIGINAL
                // (nom ya viene escapado, escaparlo otra vez lo doblaria). NO escapeAttrJs:
                // sus backslashes de literal JS se quedarian escritos en el cuadro.
                var pickText    = escHtml(
                    (parteMostrar ? String(parteMostrar) + ' · ' : '') + (p.NOMBRE || '')
                );
                return '<div class="alm-suggest-item" data-pid="' + pid + '" data-pids="' + pids + '" data-pick="' + pickText + '" title="' + cod + '">'
                     /* nº de parte (si es filtro) + nom; el badge de presentaciones va a la derecha. */
                     + '<div class="alm-suggest-line">' + partePrefix + '<span class="nom">' + nom + '</span>' + rightBadge + '</div>'
                     + '</div>';
            }).join('');
            box.innerHTML = html;
        }
        box.classList.add('open');
    };
    // Reglas del filtro "Descripción":
    //   (a) Escribir refresca solo la LISTA de sugerencias, NO la tabla. Si el usuario
    //       venía de un clic previo (id_producto fijado), se DESCARTA en cuanto edita el
    //       texto — porque ya quiere algo distinto.
    //   (b) Clic en una sugerencia [almBuscarPick] → fija id_producto = match EXACTO
    //       (solo aparece esa fila en la tabla).
    //   (c) Enter, o tocar la lupa del campo [almBuscarEnter] → similitudes via LIKE %term%
    //       del backend (sin id_producto), ordenadas de la mas parecida a la mas lejana
    //       (AlmacenController::ordenarInventarioPorRelevancia).
    //   (d) Limpiar [almBuscarLimpiar] → quita texto + id_producto, recarga sin filtro.
    window.almBuscarInput = function () {
        // Si el texto ya no coincide con la última sugerencia elegida, el id pegado deja
        // de aplicar. Lo más simple: descartar siempre que se vuelva a teclear.
        almResetPick();
        window.QrScan.iconToggle();   // ocultar el icono escanear mientras hay texto
        window.almBuscarSuggest();
    };
    // Buscar por código o descripción: el foco solo abre sugerencias; los atajos "Stock
    // bajo"/"Con stock" se sueltan al aplicar la búsqueda (ver almResetBadges).
    window.almBuscarFocus = function () {
        // Reintenta cargar el catálogo async si la 1ª carga falló (blip de red): al hacer foco
        // en el buscador se vuelve a intentar. almCargarProductos está guardado (no re-fetchea
        // si ya cargó o está en curso), así que es seguro llamarlo aquí.
        if (!window.almProductosCargados && typeof window.almCargarProductos === 'function') window.almCargarProductos();
        window.almBuscarSuggest();
    };
    // Se llama de dos sitios: la tecla del teclado (con evento) y el clic en la lupa del
    // campo (sin evento). Sin `ev` es siempre una peticion explicita de buscar.
    // El keyCode 13 va ademas del key 'Enter' porque algunos teclados de telefono mandan
    // el codigo pero no ponen `key` (llega como 'Unidentified'), y entonces no filtraba.
    window.almBuscarEnter = function (ev) {
        if (ev && ev.key !== 'Enter' && ev.keyCode !== 13) return;
        if (ev) ev.preventDefault();
        // Sin nada escrito no hay busqueda que hacer, y seguir seria DESTRUCTIVO: mas abajo
        // se sueltan "Stock bajo"/"Con stock", asi que tocar la lupa con el campo vacio
        // dejaba la tabla sin ningun filtro y en "Usa los filtros...". En el telefono la
        // lupa cae justo donde se toca para enfocar el campo, asi que pasaba facil.
        var _inp = el('almFiltroBuscar');
        if (_inp && !_inp.value.trim() && !(_inp.dataset.active || '').trim()) {
            _inp.focus();
            return;
        }
        almResetPick();
        // Buscar algo nuevo es una acción explícita del usuario para ver OTRA cosa —
        // no debe quedar recortada en silencio por un atajo "Con stock"/"Stock bajo"
        // que seguía encendido de antes (mismo bug que resolvía almVerTodo). Sin esto
        // el backend hacía search AND solo_bajo y el usuario veía resultados
        // incoherentes con lo que pidió. Ver almResetBadges().
        almResetBadges();
        almSuggestHide();
        almCargar();
    };
    window.almBuscarPick = function (texto, idProducto, idsCsv) {
        // Patron placeholder-background: el termino elegido va al value temporalmente
        // para que filtros() -> valActive() lo promueva a data-active + placeholder.
        var inp = el('almFiltroBuscar'); if (inp) inp.value = texto;
        almBuscarPickedId  = idProducto ? parseInt(idProducto, 10) : null;
        // idsCsv: presentaciones agrupadas (misma descripcion). Si viene, el filtro usa
        // id_producto_in con esos IDs exactos en vez del LIKE por texto.
        almBuscarPickedIds = (idsCsv && idsCsv.length) ? idsCsv : null;
        // Nota: las sugerencias ya se limitan a la categoría activa (ver almBuscarSuggest),
        // así que el clic nunca trae un material de otra categoría — no hay que tocar el
        // filtro de categoría aquí.
        // Mismo motivo que almBuscarEnter: un clic en sugerencia pide ver ESE producto
        // puntual — un "Stock bajo" o una unidad de medida puestos de antes podían ocultarlo.
        almSoltarUm();
        almResetBadges();
        almSuggestHide();
        almCargar();
    };
    // Ver el MISMO producto en otro almacén (clic en el sidebar "En otros almacenes").
    // Antes hacía window.location.href → recarga completa de la página. Ahora reusa el
    // flujo AJAX del módulo (preloader + almCargar, igual que cambiar el almacén en el
    // dropdown): fija el producto enfocado y cambia el almacén con selectOption, que
    // dispara 'dropdown-selection' → almCargar (refresca tabla + KPIs + el propio sidebar).
    window.almVerProductoEnAlmacen = function (idAlmacen, nombre, idProducto) {
        // Solo el producto enfocado (sin arrastrar el texto de búsqueda previo), igual
        // que el link viejo que solo llevaba id_almacen + id_producto.
        var inp = el('almFiltroBuscar');
        if (inp) { inp.value = ''; inp.dataset.active = ''; inp.placeholder = inp.dataset.placeholderEmpty || 'Buscar por código o descripción…'; }
        almBuscarPickedId  = idProducto ? parseInt(idProducto, 10) : null;
        almBuscarPickedIds = null;
        // Un "Stock bajo"/"Con stock" pegado del almacén anterior podía ocultar este
        // producto puntual en el nuevo almacén (solo_bajo/solo_con_saldo NO se
        // exceptúan para id_producto — ver inventarioBaseQuery en el backend). Igual la unidad.
        almSoltarUm();
        almResetBadges();
        window.QrScan.iconToggle();
        almSuggestHide();
        if (typeof selectOption === 'function') {
            selectOption('almSelAlmacenDropdown', String(idAlmacen), nombre);
        } else {
            var h = el('almSelAlmacen'); if (h) h.value = idAlmacen;
            almCargar();
        }
    };
    window.almBuscarLimpiar = function () {
        var inp = el('almFiltroBuscar');
        if (inp) {
            inp.value = '';
            inp.dataset.active = '';                                  // borrar el filtro activo
            inp.placeholder = inp.dataset.placeholderEmpty || 'Buscar por código o descripción…';
        }
        almResetPick();
        almSuggestHide();
        almCargar();   // → filtros() sincroniza la "x" y el icono de escanear
    };
    // ── Autocompletado del filtro "Categoría" (lista de categorías ya registradas), mismo look que "Buscar" ──
    window.almCatSuggest = function () {
        almSuggestHide();
        var inp = el('almFiltroCat'), box = el('almFiltroCatSuggest');
        if (!inp || !box) return;
        var lista = (window.almCategoriasLista || []);
        var matches = almSuggestFilter(lista, almNorm(inp.value.trim()), function (c) { return c; }, false);
        var html = matches.map(function (c) {
            // Aquí NO hay id que salve la situación, a diferencia del buscador de productos:
            // lo que se elija viaja tal cual como `categoria=` y el backend filtra con LIKE.
            // Con el replace que BORRABA caracteres, una categoría con &, <, > o comillas se
            // ofrecía ya mutilada y su LIKE no encontraba nada: quedaba inalcanzable desde la
            // sugerencia.
            //
            // escHtml para los DOS (texto y data-pick): el atributo se lee con getAttribute,
            // así que el navegador deshace las entidades y llega el texto original. Con
            // escapeAttrJs se colarían sus backslashes (CABLE 3' → CABLE 3\') y el LIKE
            // volvería a no encontrar nada, que es justo lo que se está arreglando.
            var texto = escHtml(String(c));
            return '<div class="alm-suggest-item" data-pick="' + texto + '"><span class="nom">' + texto + '</span></div>';
        }).join('');
        var empty = '<div class="alm-suggest-empty">' + (lista.length ? 'Sin categorías que coincidan.' : 'No hay categorías registradas.') + '</div>';
        almSuggestApply(box, html, empty);
    };
    // Escribir SOLO refresca la lista de sugerencias — NO dispara la búsqueda en la tabla.
    // La tabla se filtra cuando el usuario (a) elige una sugerencia [almCatPick],
    // (b) pulsa Enter [almCatEnter], o (c) limpia el campo con la X [almCatLimpiar].
    window.almCatInput = function () { window.almCatSuggest(); };
    window.almCatFocus = function () { window.almCatSuggest(); };
    window.almCatEnter = function (ev) {
        if (ev && ev.key !== 'Enter') return;
        if (ev) ev.preventDefault();
        almResetPick(); // filtrar por categoría no debe quedar anulado por un pick exacto pegado
        almResetBadges(); // idem almBuscarEnter: no combinar en silencio con "Stock bajo"/"Con stock"
        almCatSuggestHide();
        almCargar();
    };
    window.almCatPick = function (cat) {
        var inp = el('almFiltroCat'); if (inp) inp.value = cat;
        almResetPick(); // filtrar por categoría no debe quedar anulado por un pick exacto pegado
        almResetBadges(); // idem almBuscarEnter: no combinar en silencio con "Stock bajo"/"Con stock"
        almCatSuggestHide();
        almCargar();
    };
    window.almCatLimpiar = function () {
        var inp = el('almFiltroCat');
        if (inp) {
            inp.value = '';
            inp.dataset.active = '';
            inp.placeholder = inp.dataset.placeholderEmpty || 'Filtrar por categoría…';
        }
        almCatSuggestHide();
        almCargar();
    };

    // ── Filtro de almacén: ahora usa el componente custom-dropdown global (selectOption / dropdown-selection).
    //    El hidden #almSelAlmacen sigue siendo la fuente de verdad que lee filtros(); el listener de abajo
    //    recarga la tabla cuando el usuario elige un almacén distinto.
    window.addEventListener('dropdown-selection', function (e) {
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almSelAlmacenDropdown') {
            // Al cambiar de almacén, DESCARTAR la selección de productos del almacén anterior:
            // una Salida / Nota de Entrega es SIEMPRE de un único almacén, así que arrastrar
            // productos de otro permitiría un movimiento mezclado (los del otro almacén tienen
            // saldo 0 aquí). Se limpia antes de recargar para que la barra flotante y la tabla
            // reflejen solo el almacén nuevo.
            if (typeof window.almSelClear === 'function') window.almSelClear();
            almCargar();
        }
        // Modal "Registrar salida": al elegir proyecto destino se rellena la lista del
        // dropdown "Contrato N°" con los contratos de ese proyecto (o mensaje "sin
        // contratos") y se decide el almacén destino. El panel NO se abre solo.
        if (id === 'almSalidaProyectoDropdown' && typeof window.almSalidaOnProyectoChange === 'function') {
            window.almSalidaOnProyectoChange();
        }
        // Unidad de medida del panel "Filtros avanzados".
        if (id === 'almFiltroUmDropdown' && !window.__almUmSilencio) window.almAvanzadoUm();
    });

    // Click en una sugerencia (Buscar / Categoría) / click fuera / Escape — el filtro Almacén ya no usa este sistema.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almFiltroBuscarSuggest .alm-suggest-item');
        if (item) {
            e.preventDefault();
            // Item especial "VER TODO EL STOCK" → muestra SOLO los productos con
            // existencias (>0), encendiendo el badge "Con stock". El catálogo completo
            // (incl. stock 0) sigue disponible al pulsar el total "PRODUCTOS" del Consolidado.
            if (item.getAttribute('data-action') === 'ver-todo') {
                almSuggestHide();
                almSoltarUm();
                if (window.almFiltrarConSaldo) window.almFiltrarConSaldo(true);
                return;
            }
            // data-pid → match exacto en el backend; data-pick → texto visible en el input.
            window.almBuscarPick(item.getAttribute('data-pick') || '', item.getAttribute('data-pid') || '', item.getAttribute('data-pids') || '');
            return;
        }
        var catItem = e.target.closest('#almFiltroCatSuggest .alm-suggest-item');
        if (catItem) { e.preventDefault(); window.almCatPick(catItem.getAttribute('data-pick') || ''); return; }
        if (!e.target.closest('.alm-filter')) { almSuggestHide(); almCatSuggestHide(); }
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { almSuggestHide(); almCatSuggestHide(); } });

    // El paginador clásico fue reemplazado por auto-carga continua por offset (ver almCargar).
    // ════════════════════════════════════════════════════════════════════════
    //  Selección de productos en la tabla — IGUAL que /admin/equipos:
    //  clic en una fila → se resalta en azul (.selected-row-maquinaria) y aparece
    //  la barra flotante #almBulkBar con el conteo y las acciones.
    //  Las cantidades a sacar/enviar viven AHORA en la propia fila de la tabla:
    //  cada fila tiene un <input.alm-row-cant> que se habilita al seleccionarla. El
    //  valor se guarda en almSeleccion[id].cantidad y sobrevive a recargas del tbody
    //  (paginación/filtros) gracias a almSelApplyToVisible(). El modal #almSalidaModal
    //  ya NO muestra una tabla de productos — solo los campos de la Nota de Entrega.
    // ════════════════════════════════════════════════════════════════════════
    var almSeleccion = {}; // { id_producto: { codigo, nombre, um, saldo, cantidad } }
    // Producto de la última fila cuyo detalle se abrió con el ojo (almMarcarVista). Queda
    // marcada al cerrar el modal; almSelApplyToRows la repone tras cada repintado.
    var almUltimaVista = null;
    // IDs de productos seleccionados que NO tienen cantidad válida en el último intento de
    // "Registrar salida". Sobrevive a recargas del tbody y se limpia cuando el usuario
    // teclea una cantidad válida, deselecciona el producto, o limpia toda la selección.
    var almFaltantes = {};
    // IDs de productos cuya cantidad tecleada EXCEDE el saldo disponible. Misma mecánica
    // que almFaltantes pero distinto motivo de bloqueo: aquí el usuario SÍ puso un número,
    // pero ese número es mayor que el stock del almacén. Mantenemos lo escrito (no recortamos)
    // para que el usuario vea el valor inválido y lo corrija — la fila se pinta de rojo y el
    // modal "Registrar salida" queda bloqueado hasta que la cantidad baje al saldo o menos.
    var almExceden = {};
    // Modo "Ver solo seleccionados" activado desde el contador de la barra flotante. Al
    // (re)activarlo, almToggleSoloSel recarga vía AJAX mandando id_producto_in con la
    // selección actual → el backend devuelve SOLO esos productos. Deseleccionar una fila NO
    // la oculta al instante: queda visible (sin marcar) para poder re-seleccionarla si fue un
    // clic accidental; desaparece recién al volver a pulsar el toggle (nueva recarga). Se
    // desactiva automáticamente al limpiar la selección.
    var almSoloSel = false;
    // Observación que deja el último kit cargado ("KIT 250H × 3 · CHUTO HOWO"): el modal de la
    // salida la pone al abrirse (el usuario puede cambiarla). Se olvida al limpiar la selección,
    // que es también lo que pasa al registrar la salida.
    var almKitMotivo = '';
    function almSelCount() { return Object.keys(almSeleccion).length; }
    function almAplicarFaltantes() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            var id = tr.getAttribute('data-id-producto');
            tr.classList.toggle('alm-row-missing-cant', !!almFaltantes[id]);
        });
    }
    function almLimpiarFaltante(id) {
        if (!almFaltantes[id]) return;
        delete almFaltantes[id];
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (tr) tr.classList.remove('alm-row-missing-cant');
        almPintarAvisoSalida();
    }
    function almAplicarExceden() {
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            var id = tr.getAttribute('data-id-producto');
            tr.classList.toggle('alm-row-exceeds-stock', !!almExceden[id]);
        });
    }
    function almLimpiarExceden(id) {
        if (!almExceden[id]) return;
        delete almExceden[id];
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (tr) tr.classList.remove('alm-row-exceeds-stock');
        almPintarAvisoSalida();
    }
    function almMarcarExceden(id) {
        almExceden[id] = true;
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (tr) tr.classList.add('alm-row-exceeds-stock');
        almPintarAvisoSalida();
    }

    // ── Aviso de busqueda aproximada (#almBuscarAviso) ──
    // Lo enciende el backend (aproximada=true) cuando lo escrito no coincidio con NADA y
    // hubo que buscar parecidos perdonando un error de tipeo. Sin este aviso la tabla
    // mostraria filas que no contienen lo escrito y pareceria que el filtro esta roto.
    function almPintarAvisoBusqueda(aproximada, termino) {
        var box = el('almBuscarAviso'); if (!box) return;
        if (!aproximada) { box.hidden = true; box.innerHTML = ''; return; }
        box.innerHTML = '<i class="material-icons">info_outline</i>'
                      + '<div>Sin coincidencias exactas de <b>' + escHtml(termino || '') + '</b>. '
                      + 'Mostrando resultados parecidos.</div>';
        box.hidden = false;
    }

    // ── Aviso de la salida por corregir (#almSalidaAviso) ──
    // Lo enciende almSelAccion al fallar y se repinta con cada corrección (almLimpiar* /
    // almMarcarExceden / almSelRefreshBar) hasta que no queda nada: entonces se apaga solo.
    // Antes era un aviso emergente, que se iba a los segundos y se apilaba al volver a pulsar.
    var almAvisoSalidaActivo = false;
    // Resume cuántos productos tienen cada problema; cuáles son lo dice la tabla, que ya muestra
    // solo los de la salida con el motivo bajo cada caja de cantidad.
    function almPintarAvisoSalida() {
        var box = el('almSalidaAviso'); if (!box) return;
        var falta = 0, supera = 0, sinStock = 0, sinParte = 0;
        Object.keys(almSeleccion).forEach(function (id) { if (almPideParte(id)) sinParte++; });
        Object.keys(almExceden).forEach(function (id) { if (almSeleccion[id]) supera++; });
        Object.keys(almFaltantes).forEach(function (id) {
            var s = almSeleccion[id]; if (!s) return;
            // Sin saldo no hay cantidad que valga: se pide quitarlo, no escribirla.
            if ((parseFloat(s.saldo) || 0) <= 0) sinStock++; else falta++;
        });
        var partes = [];
        if (sinParte) partes.push('falta elegir la equivalencia en ' + sinParte + (sinParte === 1 ? ' producto' : ' productos'));
        if (falta) partes.push('falta la cantidad en ' + falta + (falta === 1 ? ' producto' : ' productos'));
        if (supera) partes.push(supera === 1 ? '1 producto pide más de lo que hay en stock' : supera + ' productos piden más de lo que hay en stock');
        if (sinStock) partes.push(sinStock === 1 ? '1 producto no tiene stock en este almacén (quítalo de la salida)' : sinStock + ' productos no tienen stock en este almacén (quítalos de la salida)');
        if (!partes.length) almAvisoSalidaActivo = false;
        box.hidden = !almAvisoSalidaActivo;
        if (box.hidden) { box.innerHTML = ''; return; }
        var unir = function (l, y) { return l.length === 1 ? l[0] : l.slice(0, -1).join(', ') + y + l[l.length - 1]; };
        // Dónde mirar: las filas por corregir van en rojo y su caja de Salida lleva el motivo
        // (los mismos textos del ::after de .alm-td-cant).
        var rotulos = [];
        if (falta) rotulos.push('«Falta la cantidad»');
        if (supera) rotulos.push('«Supera el stock»');
        if (sinStock) rotulos.push('«Sin stock»');
        var enRojo = falta + supera + sinStock, pista = '';
        if (enRojo) pista += (enRojo === 1 ? 'Está resaltado en rojo y su caja de Salida dice ' : 'Están resaltados en rojo y su caja de Salida dice ') + unir(rotulos, ' o ') + '. ';
        if (sinParte) pista += 'Toca el número de parte que entregas y luego pon la cantidad.';
        box.innerHTML = '<i class="material-icons">error_outline</i><div>'
            + '<strong>No se puede registrar la salida:</strong> ' + unir(partes, ' y ') + '.'
            + '<div class="alm-aviso-pista">' + pista + '</div></div>';
    }
    // Lleva a la fila de un producto por corregir y deja el cursor en su cantidad.
    function almIrAProblema(id) {
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (!tr) return;
        tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
        almEnfocarCantidad(tr, true);
    }

    // Enciende o apaga "ver solo seleccionados": la tabla se recarga con SOLO los productos de
    // la selección (filtros() manda id_producto_in) y el contador de la barra lo marca.
    function almAplicarSoloSel(on) {
        // El reset va ANTES de fijar el estado: descarta el pick exacto para no mandar
        // id_producto e id_producto_in a la vez (query contradictoria + URL incoherente), y
        // de paso apaga este mismo modo — por eso el valor pedido se asigna después, o el
        // reset lo dejaría siempre apagado.
        almResetPick();
        almSoloSel = on;
        // El circulo ambar en el numero (.is-filtering) refleja el estado actual.
        var btn = el('almBulkCounter');
        if (btn) btn.classList.toggle('is-filtering', almSoloSel);
        // Recargar la tabla via AJAX. Cuando solo_sel esta ON, filtros() manda
        // id_producto_in y el backend hace whitelist por esos IDs (ignorando los
        // demas filtros de contenido). Cuando esta OFF, vuelve al filtrado normal.
        almCargar();
        if (almSoloSel) {
            // Llevar al usuario al inicio de la tabla para que vea inmediatamente las filas
            // filtradas (la primera seleccionada). Sin "smooth" para que sea instantáneo.
            var tbody = el('almTableBody');
            if (tbody) tbody.scrollIntoView({ block: 'start' });
        }
    }
    window.almToggleSoloSel = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        if (!almSelCount()) { toast('No hay productos seleccionados todavía.', 'error'); return; }
        almAplicarSoloSel(!almSoloSel);
    };
    function almSelRefreshBar() {
        almPintarAvisoSalida();
        var bar = el('almBulkBar'); if (!bar) return;
        var n = almSelCount();
        bar.classList.toggle('active', n > 0);
        var c = el('almBulkCount'); if (c) c.textContent = n;
        // Si la selección quedó vacía y el filtro "solo seleccionados" estaba activo,
        // hay que apagarlo y volver a mostrar todas las filas (la barra se oculta sola).
        if (n === 0 && almSoloSel) {
            almSoloSel = false;
            document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) { tr.style.display = ''; });
            var btn = el('almBulkCounter'); if (btn) btn.classList.remove('is-filtering');
        }
    }
    function almSelMarkRow(tr, on) {
        if (!tr) return;
        tr.classList.toggle('selected-row-maquinaria', !!on);
        // Stepper de cantidad: el input + los dos botones +/− se habilitan en bloque.
        // El estilo "activo" lo aporta la clase .is-active sobre el wrapper (CSS arriba).
        var wrap = tr.querySelector('.alm-cant-stepper');
        var inp  = tr.querySelector('.alm-row-cant');
        // Con varias equivalencias y ninguna elegida, la cantidad espera a que se elija.
        var pide = !!on && almPideParte(tr.getAttribute('data-id-producto'));
        tr.classList.toggle('alm-row-pide-parte', pide);
        if (wrap) wrap.classList.toggle('is-active', !!on && !pide);
        // Desmarcar la fila olvida la equivalencia elegida: al volver a seleccionarla se vuelve
        // a pedir, igual que la cantidad, que se borra aquí abajo.
        if (!on) almRowParteReset(tr);
        if (!inp) return;
        var btns = tr.querySelectorAll('.alm-cant-btn');
        if (on) {
            var id = tr.getAttribute('data-id-producto');
            var s  = id ? almSeleccion[id] : null;
            inp.disabled = pide;
            inp.value = (s && s.cantidad != null && s.cantidad !== '') ? s.cantidad : '';
            btns.forEach(function (b) { b.disabled = pide; });
        } else {
            inp.disabled = true;
            inp.value = '';
            btns.forEach(function (b) { b.disabled = true; });
        }
    }
    // Stepper +/−: incrementa/decrementa la cantidad del producto de esa fila. Mínimo 1
    // (no permite 0 ni negativos — el "−" se queda en 1 cuando ya está en 1). El paso es
    // entero porque en general se entregan unidades enteras; si el usuario necesita
    // decimales puede teclearlos directamente en el input. Si la cantidad supera el stock,
    // NO se recorta: se permite teclear/subir el valor y la fila se pinta de rojo
    // (.alm-row-exceeds-stock) para que el usuario vea el error y corrija. El modal de
    // "Registrar salida" queda bloqueado hasta que la cantidad vuelva a ser <= saldo.
    window.almRowCantStep = function (btn, dir) {
        var tr = btn.closest('tr.alm-row'); if (!tr) return;
        var inp = tr.querySelector('.alm-row-cant'); if (!inp || inp.disabled) return;
        var id = tr.getAttribute('data-id-producto');
        var s  = id ? almSeleccion[id] : null; if (!s) return;
        var cur = parseFloat(String(inp.value || '0').replace(',', '.')) || 0;
        var next = cur + (dir > 0 ? 1 : -1);
        if (next < 1) next = 1;
        inp.value = String(next);
        s.cantidad = String(next);
        var saldo = parseFloat(s.saldo) || 0;
        if (next > saldo) almMarcarExceden(id);
        else              almLimpiarExceden(id);
        if (next > 0)     almLimpiarFaltante(id);
    };
    // Bloquea en el teclado los caracteres prohibidos (signos, "e", letras) para que el
    // input solo acepte dígitos y un único separador decimal. Permite teclas de control
    // (Backspace, Delete, flechas, Tab, Enter, Home/End, copiar/pegar/cortar/seleccionar).
    window.almRowCantKeyDown = function (e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return true;
        var k = e.key || '';
        var control = ['Backspace','Delete','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','Tab','Enter','Home','End','Escape'];
        if (control.indexOf(k) !== -1) return true;
        // Solo dígitos o un único punto/coma decimal.
        if (/^[0-9]$/.test(k)) return true;
        if ((k === '.' || k === ',') && e.target && (e.target.value || '').indexOf('.') === -1 && (e.target.value || '').indexOf(',') === -1) return true;
        e.preventDefault();
        return false;
    };
    // Sanitiza lo pegado: deja solo dígitos y a lo más un punto decimal.
    window.almRowCantPaste = function (e) {
        try {
            var raw = (e.clipboardData || window.clipboardData).getData('text');
            if (raw == null) return true;
            var clean = String(raw).replace(',', '.').replace(/[^0-9.]/g, '');
            var parts = clean.split('.');
            if (parts.length > 2) clean = parts[0] + '.' + parts.slice(1).join('');
            e.preventDefault();
            if (e.target) {
                e.target.value = clean;
                if (typeof window.almRowCantInput === 'function') window.almRowCantInput(e.target);
            }
        } catch (_) {}
        return false;
    };
    // Re-pinta el resaltado azul + estado del input cantidad tras cada recarga AJAX del tbody.
    function almSelApplyToVisible() {
        // Auto-seleccion desde URL (?id_producto=NNN): si es el primer render y la
        // fila del producto pickeado esta en el tbody, la promovemos a almSeleccion
        // antes de marcar las filas. Tras esto, _almPendingAutoSelect se apaga para
        // que clicks de deseleccion posteriores no se reviertan en cada recarga.
        if (_almPendingAutoSelect && almBuscarPickedId) {
            var trPick = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + almBuscarPickedId + '"]');
            if (trPick && !almSeleccion[almBuscarPickedId]) {
                // Almacén por proyecto: primero se pregunta de cuál sale (al elegir queda
                // seleccionada); si no, se selecciona directo.
                if (almBolsasDeFila(trPick).length) almPedirBolsa(trPick);
                else { almSeleccion[almBuscarPickedId] = almSelNuevaEntrada(trPick); almSelRefreshBar(); }
            }
            // Apagar el flag aunque la fila no haya aparecido (ej. backend filtro vacio)
            // — sin esto, el siguiente almCargar() volveria a auto-seleccionar y se
            // perderia la intencion del usuario.
            _almPendingAutoSelect = false;
        }
        almSelApplyToRows(document.querySelectorAll('#almTableBody tr.alm-row'));
        // Foco pendiente (Auditoría, salida por corregir): tras recargar, dejar el teclado listo
        // en el input de cantidad de esa fila (si sigue seleccionada). Se consume UNA vez.
        if (_almPendingFocusId) {
            var trF = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + _almPendingFocusId + '"]');
            _almPendingFocusId = null;
            if (trF && almSeleccion[trF.getAttribute('data-id-producto')]) {
                trF.scrollIntoView({ block: 'center' });
                almEnfocarCantidad(trF);
            }
        }
    }
    // Aplica TODO el estado de una fila (selección azul + última vista + faltante/exceso de
    // stock + filtro "solo seleccionados") en UNA sola pasada por fila. En el scroll-infinito
    // (append) se llama SOLO con las filas recién agregadas — antes se re-iteraba todo
    // el tbody (×4) en cada lote, lo que era O(n²) y congelaba el navegador al cargarse
    // todo el stock.
    function almSelApplyToRows(rows) {
        rows.forEach(function (tr) {
            var id = tr.getAttribute('data-id-producto');
            // Refrescar el saldo CACHEADO de la selección con el del render fresco. Tras una
            // auditoría/movimiento el saldo del servidor cambió, y el control "excede stock" de
            // la salida compara la cantidad contra ESTE saldo cacheado. Sin esto comparaba contra
            // el saldo viejo (antes de auditar) y pintaba la fila de rojo como si la salida
            // excediera, aunque el usuario aún no realiza la salida.
            var sel = almSeleccion[id];
            if (sel) {
                var ds = parseFloat(tr.getAttribute('data-saldo'));
                if (!isNaN(ds)) {
                    sel.saldo = ds;
                    // Re-evaluar el "excede" con el saldo nuevo por si la cantidad ya tecleada
                    // dejó de exceder (o pasó a exceder) tras el cambio de stock.
                    var cant = parseFloat(String(sel.cantidad || '').replace(',', '.'));
                    if (!isNaN(cant) && cant > 0) {
                        if (cant > ds) almExceden[id] = true; else delete almExceden[id];
                    }
                }
            }
            almSelMarkRow(tr, !!almSeleccion[id]);
            tr.classList.toggle('alm-row-vista', id === almUltimaVista);
            tr.classList.toggle('alm-row-missing-cant', !!almFaltantes[id]);
            tr.classList.toggle('alm-row-exceeds-stock', !!almExceden[id]);
            if (almSoloSel && !almSeleccion[id]) tr.style.display = 'none';
            // Re-aplicar el nº de parte elegido (filtros) tras recargas del tbody: resalta el
            // número clickeado en la nueva fila para conservar la elección.
            var psel = almSeleccion[id] && almSeleccion[id].parte;
            if (psel) {
                tr.dataset.parteSel = psel;
                tr.querySelectorAll('.alm-parte-opt').forEach(function (o) {
                    o.classList.toggle('alm-parte-on', o.getAttribute('data-parte') === psel);
                });
            }
        });
    }
    // Selecciona una fila (idempotente): crea su entrada en almSeleccion, la marca y enfoca
    // el input de cantidad. Si ya estaba seleccionada NO hace nada (nunca deselecciona).
    // Fuente única de la lógica de "seleccionar" — la usan el clic en la fila y el clic en un
    // número de parte. Devuelve true si quedó seleccionada; false si espera a que se elija el
    // proyecto en el modal «¿De qué proyecto sale?» (almBolsaElegir la selecciona entonces).
    function almSelEnsureRow(tr) {
        var id = tr.getAttribute('data-id-producto'); if (!id) return false;
        if (almSeleccion[id]) return true;
        if (almBolsasDeFila(tr).length) { almPedirBolsa(tr); return false; }
        almSeleccion[id] = almSelNuevaEntrada(tr);
        almSelMarkRow(tr, true);
        almEnfocarCantidad(tr);
        return true;
    }
    // Entrada de almSeleccion para una fila. Fuente única: la usan el clic en la fila y la
    // auto-selección por URL (?id_producto=).
    function almSelNuevaEntrada(tr) {
        return {
            codigo: tr.getAttribute('data-codigo') || '',
            nombre: tr.getAttribute('data-nombre') || '',
            um:     tr.getAttribute('data-um') || '',
            saldo:  parseFloat(tr.getAttribute('data-saldo') || '0') || 0,
            cantidad: '',
            // Equivalencias (filtros): con más de una hay que elegir cuál se entrega.
            partes: (tr.getAttribute('data-equiv') || '').split('|').filter(Boolean).length,
            // Nº de parte a entregar: el elegido en la fila, o el único que tenga. Vacío en
            // productos sin equivalencias y en los de varias hasta que se elija.
            parte:  tr.getAttribute('data-parte-sel') || '',
            // Proyecto del que se descuenta (ID_FRENTE; 0 = saldo común), elegido en el modal
            // «¿De qué proyecto sale?». Vacío = sin elección: el almacén no separa por proyecto.
            bolsa:  '',
        };
    }
    // Reparto por proyecto de una fila ([{f: frente, n: nombre, q: cantidad, c: común}]). Solo
    // lo traen las filas con saldo de un almacén que separa por proyecto (data-bolsas, ver
    // partials/table_rows).
    function almBolsasDeFila(tr) {
        var raw = tr && tr.getAttribute('data-bolsas');
        if (!raw) return [];
        try { return JSON.parse(raw) || []; } catch (e) { return []; }
    }
    // Fila que espera la elección del modal «¿De qué proyecto sale?».
    var almBolsaFila = null;
    // Abre el modal con cuánto tiene cada proyecto del producto. La fila NO se selecciona aún:
    // lo hace almBolsaElegir, así que cancelar la deja como estaba.
    function almPedirBolsa(tr) {
        var esc = window.escapeHtml, um = esc(tr.getAttribute('data-um') || '');
        almBolsaFila = tr;
        el('almBolsaProducto').innerHTML = '<b>' + esc(tr.getAttribute('data-codigo') || '') + '</b> · ' + esc(tr.getAttribute('data-nombre') || '');
        el('almBolsaLista').innerHTML = almBolsasDeFila(tr).map(function (b) {
            return '<button type="button" class="alm-bolsa-opcion" data-frente="' + parseInt(b.f, 10) + '" onclick="window.almBolsaElegir(this)">'
                +   '<span class="nom' + (b.c ? ' comun' : '') + '">' + esc(b.n) + '</span>'
                +   '<span class="guia" aria-hidden="true"></span>'
                +   '<span class="qty">' + formatNum(b.q) + '<small>' + um + '</small></span>'
                + '</button>';
        }).join('');
        almOpen('almBolsaModal');
    }
    // Elegir un proyecto: selecciona la fila con esa bolsa y deja lista la cantidad. Si mientras
    // tanto se repintó la tabla, se busca la fila nueva del mismo producto.
    window.almBolsaElegir = function (op) {
        var tr = almBolsaFila; almBolsaFila = null;
        almCerrar('almBolsaModal');
        if (!tr) return;
        var id = tr.getAttribute('data-id-producto');
        if (!tr.isConnected) tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (!tr) return;
        almSeleccion[id] = almSelNuevaEntrada(tr);
        almSeleccion[id].bolsa = op.getAttribute('data-frente') || '';
        almSelMarkRow(tr, true);
        almEnfocarCantidad(tr);
        almSelRefreshBar();
    };
    // Quita la equivalencia elegida de una fila con varias (con una sola no hay nada que elegir).
    function almRowParteReset(tr) {
        if ((tr.getAttribute('data-equiv') || '').split('|').filter(Boolean).length < 2) return;
        tr.dataset.parteSel = '';
        tr.querySelectorAll('.alm-parte-opt.alm-parte-on').forEach(function (o) { o.classList.remove('alm-parte-on'); });
    }
    // ¿Falta elegir la equivalencia de este producto seleccionado?
    function almPideParte(id) {
        var s = id ? almSeleccion[id] : null;
        return !!s && s.partes > 1 && !s.parte;
    }
    // Hace destellar los números de parte de la fila: "elige uno primero".
    function almPedirParte(tr) {
        var l = tr.querySelector('.alm-parte-list'); if (!l) return;
        l.classList.remove('alm-parte-pulso'); void l.offsetWidth; l.classList.add('alm-parte-pulso');
    }
    // Deja el cursor en la cantidad de la fila; si falta la equivalencia, la pide en su lugar.
    // Único punto por el que se vuelve a habilitar y enfocar la caja fuera de almSelMarkRow.
    function almEnfocarCantidad(tr, seleccionar) {
        if (almPideParte(tr.getAttribute('data-id-producto'))) { almPedirParte(tr); return; }
        var inp = tr.querySelector('.alm-row-cant'); if (!inp) return;
        inp.disabled = false;
        setTimeout(function () { try { inp.focus(); if (seleccionar) inp.select(); } catch (e) {} }, 30);
    }
    // Clic en un número de parte de la descripción: lo marca como el que se ENTREGA (resalta
    // dentro de la fila), lo guarda en la fila y en almSeleccion, y SELECCIONA la fila si no
    // lo estaba. Cada número de parte lleva data-no-toggle (su clic no pasa por el handler
    // genérico de la fila), así que sin esto tocar un número no seleccionaba nada. El hueco y los
    // separadores de la línea no llevan la marca: ahí el clic selecciona la fila como en el resto.
    window.almRowPartePick = function (el) {
        var tr = el.closest('tr.alm-row'); if (!tr) return;
        var id = tr.getAttribute('data-id-producto');
        var parte = el.getAttribute('data-parte') || '';
        tr.querySelectorAll('.alm-parte-opt').forEach(function (o) { o.classList.toggle('alm-parte-on', o === el); });
        tr.dataset.parteSel = parte;
        if (!almSeleccion[id]) { almSelEnsureRow(tr); almSelRefreshBar(); return; }
        // Ya seleccionada (p. ej. esperando la equivalencia): se habilita la cantidad.
        almSeleccion[id].parte = parte;
        almSelMarkRow(tr, true);
        almEnfocarCantidad(tr);
        almSelRefreshBar();
    };
    window.almSelClear = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        almSeleccion = {};
        almFaltantes = {};
        almExceden = {};
        almKitMotivo = '';
        almSoloSel = false; // sin selección, el filtro local no tiene sentido
        document.querySelectorAll('#almTableBody tr.alm-row').forEach(function (tr) {
            almSelMarkRow(tr, false);
            tr.classList.remove('alm-row-missing-cant');
            tr.classList.remove('alm-row-exceeds-stock');
            tr.style.display = '';
        });
        var btn = el('almBulkCounter'); if (btn) btn.classList.remove('is-filtering');
        almSelRefreshBar();
    };
    // Carga en la salida los materiales de un kit (Acciones → Kits, js/maquinaria/almacen_kits.js):
    // lineas = [{id_producto, cantidad}], con la cantidad YA multiplicada por los kits. Es lo
    // mismo que seleccionar cada fila a mano: trae las filas del almacén actual aunque no estén
    // en pantalla (id_producto_in), crea cada entrada con almSelNuevaEntrada y, si el producto ya
    // estaba en la salida, SUMA. La bolsa queda en automático (la cascada del servidor) y el nº
    // de parte, cuando hay varios, lo pide la fila como siempre. Termina mostrando solo lo
    // seleccionado. Resuelve con { sinParte, noEstan } (cuántos piden parte / no llegaron).
    window.almKitCargarEnSalida = function (lineas, motivo) {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return Promise.reject(new Error('permiso'));
        var idAlm = almSelAlmacenActual();
        if (!idAlm) { toast('Elige primero el almacén del que sale el material.', 'error'); return Promise.reject(new Error('almacen')); }
        var p = new URLSearchParams({
            id_almacen: idAlm, solo_filas: '1',
            id_producto_in: lineas.map(function (l) { return l.id_producto; }).join(','),
        });
        return window.apiFetch(ROUTE_INDEX + '?' + p.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (b) {
                var filas = document.createElement('tbody');
                filas.innerHTML = b.html || '';
                var res = { sinParte: 0, noEstan: 0 };
                lineas.forEach(function (l) {
                    var id = String(l.id_producto);
                    var tr = filas.querySelector('tr.alm-row[data-id-producto="' + id + '"]');
                    if (!tr) { res.noEstan++; return; }
                    var s = almSeleccion[id] || (almSeleccion[id] = almSelNuevaEntrada(tr));
                    var previa = parseFloat(String(s.cantidad || '').replace(',', '.')) || 0;
                    s.cantidad = String(Math.round((previa + Number(l.cantidad)) * 1000) / 1000);
                    delete almFaltantes[id];
                    if (almPideParte(id)) res.sinParte++;
                });
                almKitMotivo = String(motivo || '').slice(0, 200);
                almSelRefreshBar();
                almAplicarSoloSel(true);
                return res;
            });
    };
    // Handler del input de cantidad en cada fila — guarda en almSeleccion (sobrevive a
    // recargas del tbody). Sanitiza (sin letras ni negativos) PERO NO recorta al stock:
    // si el usuario teclea más que el saldo disponible, dejamos el valor tal cual y
    // pintamos la fila en rojo (.alm-row-exceeds-stock). El bloqueo del modal "Registrar
    // salida" se hace en almSelAccion(); aquí solo marcamos visualmente el error para que
    // el usuario vea exactamente qué número tecleó y pueda corregirlo sin perder dígitos.
    window.almRowCantInput = function (inp) {
        var tr = inp.closest('tr.alm-row'); if (!tr) return;
        var id = tr.getAttribute('data-id-producto'); if (!id) return;
        var s  = almSeleccion[id]; if (!s) return;

        // Sanitizar: dejar solo dígitos y un único punto decimal.
        var raw = String(inp.value == null ? '' : inp.value).replace(',', '.');
        raw = raw.replace(/[^0-9.]/g, '');
        var parts = raw.split('.');
        if (parts.length > 2) raw = parts[0] + '.' + parts.slice(1).join('');

        if (raw !== inp.value) inp.value = raw;
        s.cantidad = raw;

        var c = parseFloat(raw);
        var saldo = parseFloat(s.saldo) || 0;
        // Marcar/desmarcar exceso de stock. Solo aplica si tecleó un número finito > 0,
        // de lo contrario es "faltante" — esa otra condición la chequea almSelAccion al
        // intentar abrir el modal (no queremos pintar rojo apenas se vacía el input).
        if (isFinite(c) && c > 0 && c > saldo) almMarcarExceden(id);
        else                                   almLimpiarExceden(id);
        // Si ahora la cantidad es válida (mayor que cero), limpiar el resaltado rojo "faltante".
        if (isFinite(c) && c > 0) almLimpiarFaltante(id);
    };
    // Panel lateral "En otros almacenes" del producto de la fila tocada, sin recargar la tabla:
    // con varias filas de búsqueda es la forma de saber si ese producto hay en otro almacén.
    // Solo pinta la respuesta del ÚLTIMO pedido (una anterior que llegue tarde se descarta).
    var ROUTE_OTROS = CFG.rutas.productosOtros;
    var _almOtrosPedido = 0;
    function almPanelOtros(idProducto) {
        var dc = el('almDistribucionContainer'); if (!dc || !idProducto) return;
        var pedido = ++_almOtrosPedido, idAlm = almSelAlmacenActual();
        // SPINNER AL INSTANTE, antes de pedir nada. Con internet flojo el panel se quedaba
        // con el reparto por categoria de la busqueda anterior hasta que llegara la
        // respuesta, y el toque parecia perdido: el usuario tocaba otra fila, y otra.
        // Se congela el alto que tenia el panel para que no pegue un salto al vaciarse.
        dc.innerHTML = '<div class="alm-panel-cargando" style="min-height:' +
            Math.max(90, dc.offsetHeight) + 'px"><div class="spinner-mini"></div></div>';
        window.apiFetch(ROUTE_OTROS.replace('__PID__', idProducto) + (idAlm ? '?id_almacen=' + encodeURIComponent(idAlm) : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (pedido !== _almOtrosPedido) return;   // llego tarde: manda el ultimo toque
                dc.innerHTML = (d && d.html) || almPanelFallo();
            })
            // Con el spinner puesto, vaciar el panel en un fallo lo hace DESAPARECER (la regla
            // :has(:empty) del wrapper), y eso se lee como "este producto no esta en ningun
            // lado", que es una respuesta falsa. Mejor decir que no se pudo cargar.
            .catch(function () { if (pedido === _almOtrosPedido) dc.innerHTML = almPanelFallo(); });
    }
    function almPanelFallo() {
        return '<div class="alm-panel-cargando alm-panel-fallo">' +
               '<i class="material-icons">cloud_off</i>' +
               '<span>No se pudo cargar. Toca el producto otra vez.</span></div>';
    }
    // Clic en una fila de la tabla → toggle de selección. Ignora clics sobre botones / inputs
    // (incluido el input .alm-row-cant que va dentro de un td[data-no-toggle]), salvo la celda de
    // cantidad de una fila sin marcar, que la selecciona.
    document.addEventListener('click', function (e) {
        var tr = e.target.closest('#almTableBody tr.alm-row');
        if (!tr) return;
        if (tr.classList.contains('alm-row-pide-parte') && e.target.closest('.alm-td-cant')) { almPedirParte(tr); return; }
        var id = tr.getAttribute('data-id-producto'); if (!id) return;
        // La celda de cantidad de una fila SIN marcar la selecciona: en el teléfono es media
        // tarjeta y el toque se perdía (ni selección, ni modal de proyecto, ni panel, y el toque
        // siguiente quedaba desfasado). Ya marcada, esa celda es para escribir la cantidad.
        var cantidadLibre = !almSeleccion[id] && e.target.closest('.alm-td-cant');
        if (!cantidadLibre) {
            if (e.target.closest('[data-no-toggle]')) return;
            if (e.target.closest('button') || e.target.closest('a') || e.target.closest('input') || e.target.closest('select') || e.target.closest('.custom-dropdown')) return;
        }
        if (almSeleccion[id]) { delete almSeleccion[id]; almSelMarkRow(tr, false); almLimpiarFaltante(id); almLimpiarExceden(id); }
        else almSelEnsureRow(tr);
        // En modo "Ver solo seleccionados" NO re-ocultamos la fila al deseleccionar: queda
        // visible (sin marcar) para poder volver a seleccionarla si el clic fue accidental.
        // La fila desaparece recién al volver a pulsar el toggle (recarga → almSelApplyToRows).
        almPanelOtros(id);
        almSelRefreshBar();
    });
    function almSelAlmacenActual() { var s = el('almSelAlmacen'); return s ? s.value : ''; }
    // Único botón de la barra flotante: abre el modal Nota de Entrega.
    // Salida con productos por corregir: la tabla pasa a mostrar SOLO los productos de la salida
    // —como el contador de la barra— para que ninguno quede fuera del filtro que hubiera, el
    // aviso fijo de arriba dice qué falta, cada caja de cantidad marca el suyo y el cursor va a
    // la cantidad del primero.
    function almMostrarProblemasSalida(primerId) {
        almAvisoSalidaActivo = true;
        almPintarAvisoSalida();
        var box = el('almSalidaAviso');
        if (box) { box.classList.remove('alm-aviso-sacude'); void box.offsetWidth; box.classList.add('alm-aviso-sacude'); }
        if (!almSoloSel) {
            _almPendingFocusId = primerId;   // lo consume almSelApplyToVisible al recargar
            almAplicarSoloSel(true);
            return;
        }
        almIrAProblema(primerId);
    }
    // El backend decide si es SALIDA (consumo en el mismo almacén) o TRASPASO (envío
    // a otro almacén) según el frente destino elegido en el formulario.
    window.almSelAccion = function () {
        if (!almSelCount()) { toast('Selecciona al menos un producto (clic en su fila).', 'error'); return; }
        // Guard de permiso: registrar una salida exige la clave 'almacen.movimiento'
        // (mismo patrón que el modal de Auditoría). Sin la clave no se abre el modal.
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return;
        var idAlm = almSelAlmacenActual();
        if (!idAlm) { toast('No hay un almacén seleccionado.', 'error'); return; }
        // Bloquear apertura del modal si alguna fila seleccionada (a) excede el stock o
        // (b) no tiene cantidad válida. Las dos se marcan en su fila de forma persistente
        // (sobrevive a recargas/filtros) hasta que el usuario corrija — teclee una cantidad
        // <= saldo, deseleccione el producto, o limpie toda la selección — y el aviso fijo
        // de arriba dice qué corregir en cada una.
        //
        // Un input vacío NO marca exceso, así que ningún producto cae en los dos casos.
        almExceden = {};
        almFaltantes = {};
        var exceden = [];   // ids de producto, en cada lista
        var faltan  = [];
        // Sin cantidad + sin saldo: no es que el usuario "olvidara" escribirla, es que no hay
        // nada que sacar. Se separa de `faltan` para no pedirle una cantidad que ningún valor
        // válido podría satisfacer (cualquier c > 0 caería luego en `exceden`).
        var sinSaldo = [];
        var sinParte = [];   // varias equivalencias y ninguna elegida (la cantidad aún no se pudo poner)
        Object.keys(almSeleccion).forEach(function (id) {
            var s = almSeleccion[id] || {};
            if (almPideParte(id)) { sinParte.push(id); return; }
            var c = parseFloat(String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim());
            var saldo = parseFloat(s.saldo) || 0;
            if (!isFinite(c) || c <= 0) {
                (saldo <= 0 ? sinSaldo : faltan).push(id);
                almFaltantes[id] = true;
            } else if (c > saldo) {
                exceden.push(id);
                almExceden[id] = true;
            }
        });
        // Repintar SIEMPRE: si el usuario corrigió antes de pulsar el botón, las marcas rojas
        // antiguas se borran solas; si quedan errores, se vuelven a pintar las filas afectadas.
        almAplicarFaltantes();
        almAplicarExceden();
        // Primero la equivalencia (sin ella no se puede escribir la cantidad), luego el exceso,
        // sin saldo y por último sin cantidad: a un producto sin saldo no se le pide una
        // cantidad que ningún valor podría satisfacer.
        var problemas = sinParte.concat(exceden, sinSaldo, faltan);
        if (problemas.length) {
            almMostrarProblemasSalida(problemas[0]);
            return;
        }
        window.almAbrirSalidaModal(idAlm);
    };

    // ── Campo "Categoría" del modal de producto: desplegable de categorías ya registradas + "escribir una nueva" ──
    // Es un <input> normal (puedes teclear cualquier cosa) con un caret que abre la lista de
    // categorías existentes. Si lo que escribes no está en la lista, aparece "Usar nueva categoría: …"
    // y al guardar el producto esa categoría queda registrada (la lista se deriva de productos_inventario).
    function almProdCatHide() {
        var b = el('almProdCatSuggest'); if (b) b.classList.remove('open');
        var c = el('almProdCatCaret');   if (c) c.classList.remove('open');
    }
    // forceAll = true → muestra TODAS las categorías ignorando el texto actual (lo usan el caret y el focus).
    window.almProdCatSuggest = function (forceAll) {
        var inp = el('almProdCategoria'), box = el('almProdCatSuggest'), caret = el('almProdCatCaret');
        if (!inp || !box) return;
        var term = almNorm(inp.value.trim());
        var matches = almSuggestFilter(window.almCategoriasLista, term, function (c) { return c; }, !!forceAll);
        // Solo categorias existentes; el usuario puede escribir una nueva y se guardara al crear el producto.
        var html = matches.map(function (c) {
            var sel = almNorm(c) === term ? ' si-sel' : '';
            return '<div class="si-item' + sel + '" data-cat="' + escHtml(c) + '">' + escHtml(c) + '</div>';
        }).join('');
        almSuggestApply(box, html, '<div class="alm-suggest-empty">Sin coincidencias. Escribe para crear una nueva categoría.</div>');
        if (caret) caret.classList.add('open');
    };
    window.almProdCatToggle = function (e) {
        if (e) { e.preventDefault(); e.stopPropagation(); }
        var box = el('almProdCatSuggest');
        if (box && box.classList.contains('open')) { almProdCatHide(); return; }
        window.almProdCatSuggest(true);
        var inp = el('almProdCategoria'); if (inp) inp.focus();
    };
    window.almProdCatPick = function (cat) {
        var inp = el('almProdCategoria'); if (inp) inp.value = cat; almProdCatHide();
        // Re-sincroniza la visibilidad del campo de Equivalencias: solo aplica a FILTROS.
        // El oninput ya lo hace al teclear; aquí lo hacemos al ELEGIR del dropdown / Enter,
        // si no, cambiar la categoría por la lista dejaba el campo mostrado/oculto de forma
        // incoherente (las equivalencias son SOLO para la lógica de los filtros).
        if (window.almProdEquivSyncVisible) window.almProdEquivSyncVisible();
    };

    // Delegación: click en una opción de la lista / click fuera del campo lo cierra.
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almProdCatSuggest .si-item');
        if (item) { e.preventDefault(); window.almProdCatPick(item.getAttribute('data-cat') || ''); return; }
        // No cerrar si el click fue dentro del propio campo (input + caret + lista,
        // que ahora vive DENTRO de .alm-cat-field — por eso un solo closest cubre todo).
        if (!e.target.closest('.alm-cat-field')) almProdCatHide();
    });
    // Enter dentro del input → si hay coincidencia exacta o "nueva", la fija y cierra.
    var _almProdCatInp = el('almProdCategoria');
    if (_almProdCatInp) _almProdCatInp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); window.almProdCatPick(this.value.trim()); }
        else if (e.key === 'Escape') { almProdCatHide(); }
    });

    // ── Campo "Unidad de Medida" del modal de producto: autocomplete con las UMs ya registradas ──
    // Permite seleccionar una UM existente o escribir una nueva libremente.
    function almProdUmHide() {
        var b = el('almProdUmSuggestBox'); if (b) b.classList.remove('open');
    }
    window.almProdUmSuggest = function (forceAll) {
        var inp = el('almProdUm'), box = el('almProdUmSuggestBox');
        if (!inp || !box) return;
        var term = almNorm(inp.value.trim());
        var lista = (window.almUnidadesMedida || []);
        var matches = almSuggestFilter(lista, term, function (u) { return u; }, !!forceAll);
        // Solo lista las UMs existentes; si el usuario escribe una nueva, queda en el
        // input tal cual y se guarda al crear el producto — no se ofrece como sugerencia.
        var html = matches.map(function (u) {
            var sel = almNorm(u) === term ? ' si-sel' : '';
            return '<div class="si-item' + sel + '" data-um="' + escHtml(u) + '">' + escHtml(u) + '</div>';
        }).join('');
        almSuggestApply(box, html, '<div class="alm-suggest-empty">Sin coincidencias.</div>');
    };
    // Delegación de clic para las opciones del autocomplete de UM
    document.addEventListener('click', function (e) {
        var item = e.target.closest('#almProdUmSuggestBox .si-item');
        if (item) {
            e.preventDefault();
            var inp = el('almProdUm');
            if (inp) inp.value = item.getAttribute('data-um') || '';
            almProdUmHide();
            return;
        }
        if (!e.target.closest('#almProdUm') && !e.target.closest('#almProdUmSuggestBox')) almProdUmHide();
    });
    var _almProdUmInp = el('almProdUm');
    if (_almProdUmInp) _almProdUmInp.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') almProdUmHide();
    });

    // ── modales ──
    // almOpen (no `open`): `open` a secas sombreaba window.open en todo el IIFE — foot-gun
    // para cualquier uso futuro de window.open dentro del closure. Renombrado explícito.
    function almOpen(id)  { var m = el(id); if (m) m.classList.add('open'); }
    window.almCerrar = function (id) { var m = el(id); if (m) m.classList.remove('open'); };
    // El cierre por clic en el backdrop fue removido por preferencia del usuario:
    // cada modal tiene su propio botón "✕" / "Cancelar". Escape sí lo sigue cerrando.
    // Caso especial: el modal #almPreviewModal tiene cleanup propio (revoca el blob
    // URL del PDF y reabre el modal de salida) — delegamos en almPreviewCerrar para
    // no filtrar memoria ni dejar al usuario sin camino de vuelta a la edicion.
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        // El visor de la foto va encima de todo: Escape lo cierra a él solo.
        var visor = el('almVisorFoto');
        if (visor && visor.classList.contains('abierto')) { window.almCerrarFoto(); return; }
        var preview = el('almPreviewModal');
        if (preview && preview.classList.contains('open') && typeof window.almPreviewCerrar === 'function') {
            window.almPreviewCerrar();
            return; // almPreviewCerrar ya manejo el cleanup + reabrir salida; no cerrar mas.
        }
        // Detalles del producto: cerrar con Escape también devuelve el foco al input de
        // cantidad de la fila seleccionada (mismo criterio que la "✕", vía almDetalleCerrar).
        var detalle = el('almDetalleModal');
        if (detalle && detalle.classList.contains('open') && typeof window.almDetalleCerrar === 'function') {
            window.almDetalleCerrar();
            return;
        }
        document.querySelectorAll('.alm-modal-overlay.open').forEach(function (m) { m.classList.remove('open'); });
    });

    // ── Botón "Acciones" (dropdown estilo /admin/equipos) ──
    window.almToggleAcciones = function (e) {
        if (e) e.stopPropagation();
        var m = el('almAccionesMenu'); if (!m) return;

        // Cerrar los demás filtros estándar si están abiertos
        if (typeof window.closeAllDropdowns === 'function') window.closeAllDropdowns();
        document.querySelectorAll('.custom-dropdown.active').forEach(d => d.classList.remove('active'));
        document.querySelectorAll('.dropdown-content').forEach(d => d.style.display = '');
        // Mutex con los paneles de sugerencias (Buscar / Categoría): si estaban
        // abiertos los cerramos ahora — no debe haber dos overlays a la vez.
        almSuggestHide();
        almCatSuggestHide();
        var adv = el('almAdvPanel'); if (adv) adv.style.display = 'none';

        m.style.display = (m.style.display === 'block') ? 'none' : 'block';
    };
    // El panel de filtros avanzados se cierra con un clic fuera o cuando el foco sale de él
    // (Tab / "siguiente" del teclado del teléfono): un desplegable a la vez.
    function almCerrarAvanzadoSiFuera(e) {
        var adv = el('almAdvPanel'), t = e.target;
        if (adv && adv.style.display === 'block' && t && t.closest && !t.closest('#almAdvPanel') && !t.closest('#almAdvBtn')) {
            adv.style.display = 'none';
        }
    }
    document.addEventListener('focusin', almCerrarAvanzadoSiFuera);
    document.addEventListener('click', function (e) {
        almCerrarAvanzadoSiFuera(e);
        var m = el('almAccionesMenu');
        if (m && m.style.display === 'block') {
            // Cerrar si hace clic fuera, o si hace clic en cualquier otro botón de filtro (dropdown-trigger)
            if (!e.target.closest('#almAccionesMenu') && !e.target.closest('#almBtnAcciones') || e.target.closest('.dropdown-trigger')) {
                m.style.display = 'none';
            }
        }
    });
    window.almAccion = function (which) {
        var m = el('almAccionesMenu'); if (m) m.style.display = 'none';
        switch (which) {
            case 'admin':    if (window.almAbrirAdminAlmacenes) window.almAbrirAdminAlmacenes(); break;
            case 'almacen':  if (window.almAbrirAlmacen)        window.almAbrirAlmacen();        break;
            case 'producto': if (window.almAbrirProducto)       window.almAbrirProducto();       break;
            case 'export':
                // El export sale con los MISMOS FILTROS que la tabla. Reusamos
                // filtros() —la única fuente de verdad de los filtros activos: almacén,
                // búsqueda/producto puntual, categoría, stock bajo/con saldo— en vez de
                // armar la URL a mano. Antes solo mandaba almacén + categoría, así que
                // ignoraba el producto buscado y exportaba toda la categoría.
                var u = new URL(CFG.rutas.export, window.location.origin);
                filtros().forEach(function (v, k) { u.searchParams.set(k, v); });
                almDescargarExcel(u.toString());
                break;
        }
    };

    // ── Descarga genérica con preloader ───────────────────────────────────
    // Baja el archivo vía fetch + blob (no window.location.href / window.open) para
    // mostrar el spinner global MIENTRAS el servidor lo genera y forzar la DESCARGA
    // (en vez de abrir otra pestaña). El nombre sale del Content-Disposition; si no
    // viene, usa el fallback. La usan la Copia de Inventario (Excel) y las Etiquetas
    // QR (PDF) — misma UX de descarga que el resto del módulo.
    function almDescargarArchivo(url, fallbackName, okMsg, errMsg) {
        pre();
        return window.apiFetch(url)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                var cd = r.headers.get('Content-Disposition') || '';
                var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
                var nombre = m ? decodeURIComponent(m[1]) : fallbackName;
                return r.blob().then(function (blob) { return { blob: blob, nombre: nombre }; });
            })
            .then(function (res) {
                var objUrl = URL.createObjectURL(res.blob);
                var a = document.createElement('a');
                a.href = objUrl; a.download = res.nombre;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(objUrl); }, 2000);
                unpre();
                if (okMsg && window.showToast) window.showToast(okMsg, 'success');
            })
            .catch(function () {
                unpre();
                window.toast(errMsg, 'error');
            });
    }

    function almDescargarExcel(url) {
        almDescargarArchivo(url, 'Copia_Inventario.xlsx', 'Excel descargado.', 'No se pudo generar el Excel.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  ETIQUETAS QR + ESCANEO  (como las etiquetas de producto del supermercado)
    //   · Imprimir: almEtiquetasModal (elige formato) → PDF GET almacen.etiquetas.
    //   · Escanear: window.QrScan (módulo compartido con Movimientos y Recepción) →
    //     resuelve el CODIGO vía almacen.buscar-codigo → filtra la tabla a ese producto.
    //  Read-only: sin permiso especial (mismo criterio que el export del inventario).
    // ═══════════════════════════════════════════════════════════════════════
    var ROUTE_ETIQUETAS = CFG.rutas.etiquetas;

    // Engancha el escaneo (icono del buscador + cámara + lector USB) a ESTE buscador.
    // Al resolver un código, reusa el "pick" del filtro (almBuscarPick → almCargar),
    // que ya deja la tabla en ese producto y muestra su saldo en el almacén activo.
    window.QrScan.init({
        input:      'almFiltroBuscar',
        icono:      'almBuscarScan',
        // En PC ese hueco lo ocupa el acceso a KITS: alli el QR no abria nada (el lector
        // USB teclea directo en el buscador). Quien decide cual se ve es QrScan.iconToggle.
        iconoPc:    'almBuscarKits',
        // Mismo criterio que la "x" de limpiar en filtros() (patrón placeholder-background:
        // texto tecleado o filtro aplicado en data-active) → los dos iconos, que comparten
        // sitio dentro del cuadro, nunca se ven a la vez.
        activo:     function () { return !!buscarActivo(); },
        onProducto: function (p, label) { window.almBuscarPick(label, p.id); },
    });

    // Abre el modal de etiquetas. Según cuántos productos lleguen en `lista`
    // ([{ id, codigo, nombre }]) se arma de dos formas:
    //   · sin lista o UNO → solo la fila de abajo: la cantidad (#almEtqCopias) y el lápiz del
    //     tamaño; al generar manda ?copias. Se etiqueta lo que indique idsCsv, o el filtro de
    //     categoría si viene vacío.
    //   · VARIOS          → cada producto lleva su propio campo y la cantidad de abajo se
    //     esconde; al generar manda ?items=ID:CANT,ID:CANT.
    // El tamaño arranca siempre escondido y en 50 × 30: si se quedara el de la vez anterior
    // sin verse, se imprimiría en otro tamaño sin que nadie lo notara.
    window.almAbrirEtiquetas = function (idsCsv, lista) {
        var m = el('almEtiquetasModal'); if (!m) return;
        m.dataset.ids = idsCsv || '';
        almEtqFormatoPorDefecto();
        almEtqMostrarFormato(false);

        // El bloque de productos SOLO aparece cuando hay VARIOS: ahí cada uno lleva su propio
        // campo de cantidad al lado y el código + descripción es lo que dice cuál es cuál.
        // Con UN solo producto ese rótulo sobra —se acaba de elegir ese producto— y el
        // cliente pidió quitarlo; su cantidad la toma el campo de abajo, junto al lápiz.
        var porProducto = Array.isArray(lista) && lista.length > 1;
        var wrapLista = el('almEtqModoLista'), copias = el('almEtqCopias');
        if (wrapLista) wrapLista.style.display = porProducto ? '' : 'none';
        if (copias)    copias.style.display    = porProducto ? 'none' : '';

        var cont = el('almEtqLista');
        // Vaciar SIEMPRE antes de repintar: si no, al abrir el modal desde el menú Acciones
        // después de haberlo usado con varios productos, los campos .alm-etq-cant de aquella
        // selección seguían en el DOM (ocultos) y almEtiquetasGenerar los tomaba como modo
        // "por producto" → se etiquetaba lo de la vez anterior en vez de lo pedido ahora.
        if (cont) cont.innerHTML = '';

        if (porProducto) {
            if (cont) {
                // Ficha por producto CENTRADA y sin recuadro: código y descripción con el
                // MISMO color y cuerpo, uno debajo del otro. Antes cada una iba en una caja
                // gris con el código en azul y más chico que la descripción — tres estilos
                // distintos para dos datos del mismo producto.
                cont.innerHTML = lista.map(function (it) {
                    var id  = escHtml(String(it.id));
                    var cod = escHtml(String(it.codigo || ''));
                    // it.label es el formato viejo "COD — NOMBRE"; se conserva como respaldo.
                    var nom = escHtml(String(it.nombre || it.label || ('#' + it.id)));
                    return '<div style="display:flex;align-items:center;gap:10px;">'
                        +   '<div style="flex:1;min-width:0;text-align:center;font-size:13px;color:#1e293b;line-height:1.35;">'
                        +     (cod ? '<div>' + cod + '</div>' : '')
                        +     '<div title="' + nom + '">' + nom + '</div>'
                        +   '</div>'
                        +   '<input type="number" class="alm-etq-cant" id="almEtqCant' + id + '" name="etq_cant_' + id + '" data-id="' + id + '" value="1" min="1" max="200" step="1" '
                        +     'aria-label="Cantidad de etiquetas de ' + nom + '" '
                        +     'style="width:62px;height:32px;border:1px solid #cbd5e0;border-radius:6px;padding:0 8px;font-size:13px;text-align:center;outline:none;flex:0 0 auto;">'
                        + '</div>';
                }).join('');
            }
        }
        almOpen('almEtiquetasModal');
    };
    // Vuelve el tamaño a 50 × 30 (el de data-default-label). selectOption lo deja pintado como
    // "filtro activo" (azul) con cualquier valor, también si ya era 50 × 30; aquí no es un
    // filtro, así que el campo recupera siempre su aspecto de inicio (el del HTML) para verse
    // igual cada vez que se abre el lápiz.
    function almEtqFormatoPorDefecto() {
        var dd = el('almEtqFormatoDropdown');
        if (!dd) return;
        window.selectOption('almEtqFormatoDropdown', '50x30', dd.dataset.defaultLabel);
        var t = dd.querySelector('.dropdown-trigger');
        t.classList.remove('filter-active');
        t.style.background = '#fff';
        t.style.borderColor = '#cbd5e0';
    }
    // Lápiz del modal de etiquetas: muestra u oculta el tamaño de la tira.
    function almEtqMostrarFormato(ver) {
        var dd = el('almEtqFormatoDropdown'), btn = el('almEtqFormatoBtn');
        if (!dd || !btn) return;
        dd.hidden = !ver;
        if (!ver) dd.classList.remove('active');   // que no quede la lista abierta al esconderlo
        btn.classList.toggle('activo', ver);
        btn.setAttribute('aria-expanded', ver ? 'true' : 'false');
    }
    window.almEtqVerFormato = function () {
        var dd = el('almEtqFormatoDropdown');
        if (dd) almEtqMostrarFormato(dd.hidden);
    };
    window.almEtiquetasGenerar = function () {
        var m = el('almEtiquetasModal'); if (!m) return;
        var fmt = (el('almEtqFormato') && el('almEtqFormato').value) || '50x30';
        var u = new URL(ROUTE_ETIQUETAS, window.location.origin);
        u.searchParams.set('formato', fmt);

        // El modo se decide por la PRESENCIA de campos por producto (solo existen con VARIOS),
        // no por si la lista está visible: mirar el display llegó a mandar items= vacío →
        // "No hay productos para etiquetar" con el producto elegido.
        var camposPorProducto = el('almEtqLista') ? el('almEtqLista').querySelectorAll('.alm-etq-cant') : [];
        if (camposPorProducto.length) {
            // MODO POR PRODUCTO → items=ID:CANT,ID:CANT (cada uno con su cantidad).
            var pares = [];
            camposPorProducto.forEach(function (inp) {
                var id = inp.getAttribute('data-id');
                var q = parseInt(inp.value, 10);
                if (!isFinite(q) || q < 1) q = 1;
                if (q > 200) q = 200;
                if (id) pares.push(id + ':' + q);
            });
            if (!pares.length) { toast('No hay productos para etiquetar.', 'error'); return; }
            u.searchParams.set('items', pares.join(','));
        } else {
            // MODO ÚNICO → misma cantidad para todos (?copias) sobre ids o categoría.
            var ids = m.dataset.ids || '';
            var copias = parseInt((el('almEtqCopias') && el('almEtqCopias').value) || '1', 10);
            if (!isFinite(copias) || copias < 1) copias = 1;
            if (copias > 200) copias = 200;
            u.searchParams.set('copias', String(copias));
            if (ids) {
                u.searchParams.set('ids', ids);
            } else {
                // Sin selección: respeta el filtro de categoría APLICADO (data-active), mismo
                // criterio que el export. El almacén no aplica (la etiqueta es del catálogo).
                var catEl = el('almFiltroCat');
                var cat = catEl ? String(catEl.dataset.active || '').trim() : '';
                if (cat) u.searchParams.set('categoria', cat);
            }
        }
        // Cierra el modal y baja el PDF con spinner (misma UX que la Copia de
        // Inventario y la Nota de Entrega): nada de abrir otra pestaña.
        almCerrar('almEtiquetasModal');
        almDescargarArchivo(u.toString(), 'Etiquetas_QR_' + fmt + '.pdf', 'Etiquetas descargadas.', 'No se pudieron generar las etiquetas.');
    };
    // Botón "Etiquetas" de la barra de selección masiva → MODO POR PRODUCTO: cada
    // producto seleccionado con su propio campo de cantidad.
    window.almSelEtiquetas = function () {
        if (!almSelCount()) { toast('Selecciona al menos un producto (clic en su fila).', 'error'); return; }
        var ids = Object.keys(almSeleccion);
        var lista = ids.map(function (id) {
            var s = almSeleccion[id] || {};
            // codigo y nombre van SEPARADOS para que la ficha del modal los maquete en dos
            // renglones; `label` se mantiene por compatibilidad con el render de respaldo.
            var label = (s.codigo || '') + (s.codigo && s.nombre ? ' — ' : '') + (s.nombre || ('#' + id));
            return { id: id, codigo: s.codigo || '', nombre: s.nombre || '', label: label };
        });
        window.almAbrirEtiquetas(ids.join(','), lista);
    };

    function hoy() { var d = new Date(); var p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }
    function showErr(id, msg) { var e = el(id); if (e) { e.textContent = msg; e.style.display = msg ? 'block' : 'none'; } }
    // Resalta un campo input con borde rojo cuando hay error, lo quita cuando msg está vacío.
    function almProdFieldErr(fieldId, hasError) {
        var f = el(fieldId);
        if (!f) return;
        if (hasError) {
            f.style.borderColor = '#dc2626';
            f.style.boxShadow  = '0 0 0 2px rgba(220,38,38,0.18)';
            f.style.background = '#fff5f5';
        } else {
            f.style.borderColor = '';
            f.style.boxShadow  = '';
            f.style.background = '';
        }
    }

    // Funciones almAbrirMovimiento / almGuardarMovimiento ELIMINADAS en 2026-05-13
    // junto con el modal #almMovModal. El flujo de entrada/salida ya no se hace
    // por producto individual: ENTRADA → /admin/almacen/recepcion · SALIDA →
    // selección de filas + barra flotante (Nota de Entrega). Para AJUSTE puntual
    // se usa el modal #almAjusteModal (Auditoría de Inventario).

    // ── Página de movimientos (módulo aparte: /admin/almacen/movimientos) ──
    var ROUTE_MOVIMIENTOS = CFG.rutas.movimientos;

    // ── Modal "Detalles del producto" (lo abre el ojo de cada fila; agrupa todas las acciones) ──
    // El tooltip de equipos (.tooltip-bubble) vive dentro de la celda, pero el wrap de la tabla
    // tiene overflow (recorta) y el thead sticky lo tapaba en búsquedas de una sola fila. Al
    // pasar por la fila la sacamos con position:fixed, por encima de todo y del lado que tenga
    // sitio (almTipShow). Se rastrea UNA sola burbuja activa y se RESTAURA al salir de la fila
    // o al hacer scroll/clic — si no, quedaba "flotando dentro de la lista" — y se vuelve a
    // colocar si el mouse sigue encima (almTipRecolocar).
    var _almTipActiva = null;
    function almTipReset() {
        var b = _almTipActiva; if (!b) return; _almTipActiva = null;
        // Restaurar el ancla POR DEFECTO del blade: ARRIBA de la celda (bottom:100%).
        // OJO: NO limpiar `bottom` a '' — eso borraba el `bottom:100%` inline del blade y
        // la burbuja caía DEBAJO de la fila (bug: "sale por abajo"). Se restauran los
        // valores originales para que, aunque almTipShow no alcance a re-posicionar, la
        // burbuja quede siempre arriba.
        b.style.position = 'absolute';
        b.style.left = '0';
        b.style.right = 'auto';
        b.style.bottom = '100%';
        b.style.top = 'auto';
        b.style.transform = 'translateY(5px)';
        b.style.margin = '';
        b.style.zIndex = '';
        b.style.maxHeight = '';
        b.style.overflow = '';
        b.classList.remove('alm-tip-on', 'alm-tip-abajo');
    }
    function almTipShow(cell) {
        var bub = cell.querySelector(':scope > .tooltip-bubble'); if (!bub) { almTipReset(); return; }
        if (_almTipActiva === bub) return;   // ya colocada: mover el mouse dentro de la fila no la recalcula
        if (_almTipActiva) almTipReset();
        var r = cell.getBoundingClientRect();
        bub.style.position = 'fixed';
        bub.style.right = 'auto';
        bub.style.transform = 'none';
        bub.style.margin = '0';
        bub.style.zIndex = '10050';
        bub.style.maxHeight = '';
        bub.style.overflow = '';
        // Del lado que tenga sitio: ARRIBA de la fila si cabe entre ella y la barra superior de
        // la app; si no, DEBAJO; si no cabe en ninguno (muchos equipos en una pantalla baja),
        // en el más amplio con el alto justo. Así nunca queda debajo de la barra superior, ni
        // encima de la fila que describe, ni fuera de la pantalla — antes iba siempre arriba
        // y, con la tabla filtrada (fila cerca del tope), subía hasta tapar la barra o se cortaba.
        var h = bub.offsetHeight, w = bub.offsetWidth;
        var barra = document.querySelector('.dashboard-header');
        var tope = Math.max(6, barra ? barra.getBoundingClientRect().bottom + 6 : 6);
        var arriba = r.top - 6 - tope, abajo = window.innerHeight - r.bottom - 12;
        var top;
        if (h <= arriba) top = r.top - h - 6;
        else if (h <= abajo) top = r.bottom + 6;
        else if (arriba >= abajo) { bub.style.maxHeight = arriba + 'px'; bub.style.overflow = 'hidden'; top = tope; }
        else { bub.style.maxHeight = abajo + 'px'; bub.style.overflow = 'hidden'; top = r.bottom + 6; }
        bub.style.top = top + 'px';
        bub.style.bottom = 'auto';
        bub.style.left = Math.max(6, Math.min(r.left, window.innerWidth - w - 6)) + 'px';
        bub.classList.toggle('alm-tip-abajo', top > r.top);
        bub.classList.add('alm-tip-on');
        _almTipActiva = bub;
    }
    // La burbuja de la fila bajo el mouse (la celda de la descripción es la que la lleva).
    function almTipDeFila(tr) {
        var cell = tr && tr.querySelector('.alm-td-nombre');
        if (cell) almTipShow(cell); else almTipReset();
    }
    // Tras un clic o un desplazamiento la fila se mueve o se reacomoda (se selecciona, salen
    // los botones de las equivalencias…): se quita la burbuja y, si el mouse sigue encima, se
    // vuelve a colocar donde toca.
    var _almTipEspera = null;
    function almTipRecolocar(ms) {
        almTipReset();
        clearTimeout(_almTipEspera);
        _almTipEspera = setTimeout(function () {
            var tr = document.querySelector('#almTableBody tr.alm-row:hover');
            if (tr) almTipDeFila(tr);
        }, ms);
    }
    document.addEventListener('mouseover', function (e) {
        almTipDeFila(e.target.closest ? e.target.closest('#almTableBody tr.alm-row') : null);
    });
    // Captura = true para atrapar también el scroll del wrap de la tabla.
    window.addEventListener('scroll', function () { almTipRecolocar(150); }, true);
    document.addEventListener('click', function () { almTipRecolocar(60); }, true);
    // Marca la fila del producto cuyo detalle se abre (y desmarca la anterior).
    function almMarcarVista(id) {
        almUltimaVista = String(id);
        document.querySelectorAll('#almTableBody tr.alm-row.alm-row-vista').forEach(function (tr) {
            tr.classList.remove('alm-row-vista');
        });
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + almUltimaVista + '"]');
        if (tr) tr.classList.add('alm-row-vista');
    }
    // Tope de espera de la compatibilidad antes de abrir la ficha igual (ver almAbrirDetalle).
    var ALM_DET_ESPERA_MS = 2500;
    window.almAbrirDetalle = function (id, cod, nom, um, cat, saldo, minimo, ubicacion, foto) {
        var m = el('almDetalleModal'); if (!m) return;
        almDetFotoPintar(foto || '');
        almMarcarVista(id);
        var hasMin = (minimo !== null && minimo !== undefined && minimo !== '');
        m.dataset.id = id;
        m.dataset.cod = cod || ''; m.dataset.nom = nom || ''; m.dataset.um = um || ''; m.dataset.cat = cat || '';
        m.dataset.ubicacion = ubicacion || '';
        m.dataset.saldo = (saldo == null ? '0' : String(saldo));
        m.dataset.minimo = hasMin ? String(minimo) : '';
        var bajo = hasMin && parseFloat(saldo || 0) <= parseFloat(minimo);
        // 'flex' (no '' ni 'block'): el badge es una columna flex centrada (título con su
        // ícono y la explicación debajo). Ver markup arriba.
        el('almDetBajoBadge').style.display = bajo ? 'flex' : 'none';
        if (el('almDetUbicacion')) { el('almDetUbicacion').value = ubicacion || ''; showErr('almDetUbicacionError', ''); }

        // La ficha abre DE UNA SOLA VEZ: primero llega la compatibilidad (nº de parte, equipos
        // y reparto por proyecto) y recién entonces se muestra, para que esas secciones no
        // aparezcan un instante después empujando los botones hacia abajo. Si el servidor
        // falla, abre igual sin ellas. Si mientras tanto se pidió otro producto, abre solo ese.
        // Con la red muy lenta no se espera más de ALM_DET_ESPERA_MS: abre y las secciones
        // llegan después, antes que dejar al usuario mirando el spinner.
        var abierto = false, espera = null;
        var abrir = function () {
            if (abierto) return;
            abierto = true;
            clearTimeout(espera);
            unpre();
            if (String(m.dataset.id) === String(id)) almOpen('almDetalleModal');
        };
        pre();
        espera = setTimeout(abrir, ALM_DET_ESPERA_MS);
        window.almCargarCompat(id).finally(abrir);
    };

    // ── Foto del producto ─────────────────────────────────────────────────────
    // La tabla y la ficha piden la MINIATURA (?sz=): el proxy la guarda en disco y pesa unos
    // KB, en vez de la foto de 945 px para un cuadrito de 58. El visor pide la foto entera.
    // Mismo sufijo en la fila que pinta el servidor (partials/table_rows).
    function almFotoMini(url, sz) { return url + (url.indexOf('?') < 0 ? '?' : '&') + 'sz=' + sz; }

    // Un solo sitio que decide qué se ve: la imagen o el círculo vacío, y lo que dice la
    // cámara al pasar el mouse. Lo llaman la apertura de la ficha y la subida.
    function almDetFotoPintar(url) {
        var img = el('almDetFotoImg'), sin = el('almDetFotoSin');
        if (!img || !sin) return;
        if (url) { img.src = almFotoMini(url, 'w160'); img.style.display = ''; sin.style.display = 'none'; }
        else     { img.removeAttribute('src'); img.style.display = 'none'; sin.style.display = 'flex'; }
        var caja = el('almDetFotoCaja');
        if (caja && caja.classList.contains('editable')) caja.title = url ? 'Cambiar foto' : 'Subir foto';
        // El dataset manda: la tabla se repinta con él al cerrar la ficha.
        var m = el('almDetalleModal'); if (m) m.dataset.foto = url || '';
    }

    // Deja la fila de la tabla al día sin recargar el módulo entero.
    function almDetFotoEnLaTabla(id, url) {
        var fila = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        var celda = fila && fila.querySelector('.alm-td-foto');
        if (!celda) return;
        celda.innerHTML = url
            ? '<img src="' + almFotoMini(url, 'w120') + '" alt="" class="alm-foto" loading="lazy" onclick="event.stopPropagation(); window.almVerFoto(this.src)">'
            : '<span class="alm-foto alm-foto-sin" title="Sin foto"><i class="material-icons">inventory_2</i></span>';
    }

    // Elegir archivo -> encuadrarlo en el recorte (el mismo del Catálogo) -> subir el recorte.
    window.almDetFotoElegir = function (input) {
        var archivo = input && input.files && input.files[0];
        input.value = '';                      // permite volver a elegir el mismo archivo
        if (!archivo) return;
        if (archivo.size > 10 * 1024 * 1024) { window.toast('La foto supera los 10 MB.', 'error'); return; }
        window._openCropModal(archivo, almDetFotoSubir);
    };

    function almDetFotoSubir(archivo) {
        var id = el('almDetalleModal').dataset.id;
        var caja = el('almDetFotoCaja');
        if (caja) caja.classList.add('subiendo');   // la cámara gira y no se admite otro clic

        window.apiPostForm(CFG.rutas.productosBase + '/' + id + '/foto',
                           { foto: archivo }, 'No se pudo subir la foto.')
            .then(function (b) {
                almDetFotoPintar(b.foto);
                almDetFotoEnLaTabla(id, b.foto);
                window.toast('Foto actualizada.', 'success');
            })
            .catch(function (e) { window.toast(e.message, 'error'); })
            .finally(function () { if (caja) caja.classList.remove('subiendo'); });
    }

    // Visor de la foto: lo abren la miniatura de la tabla y la foto de la ficha (sin permiso).
    window.almVerFoto = function (src) {
        if (!src) return;
        // Llega la miniatura de la tabla o de la ficha: en grande va la foto entera.
        el('almVisorFotoImg').src = src.replace(/[?&]sz=[^&]*$/, '');
        el('almVisorFoto').classList.add('abierto');
    };
    window.almCerrarFoto = function () {
        var v = el('almVisorFoto'); if (!v) return;
        v.classList.remove('abierto');
        el('almVisorFotoImg').removeAttribute('src');
    };

    // Trae equivalencias + equipos del filtro y los pinta en el detalle; devuelve la promesa
    // (almAbrirDetalle espera a que termine para abrir). Si el usuario abre otro producto
    // mientras carga, se ignora la respuesta vieja (compara el id del modal).
    window.almCargarCompat = function (id) {
        var esc = window.escapeHtml;   // helper central (dom_helpers.js)
        var wrap = el('almDetCompat'); if (!wrap) return Promise.resolve();
        var proyWrap = el('almDetProyectosWrap'), proyBox = el('almDetProyectos');
        if (proyWrap) proyWrap.style.display = 'none';
        if (proyBox) proyBox.innerHTML = '';
        almDetFormCerrar();
        // Cada ficha abre con las dos secciones cerradas, aunque en la anterior se hubieran abierto.
        almDetSeccion('almDetPartesWrap', false);
        almDetSeccion('almDetEquiposWrap', false);
        almDetCompatPintar({ equivalencias: [], equipos: [] }, true);

        // El almacén abierto viaja en la URL: sin él el backend no sabe de qué inventario
        // sacar el reparto por proyecto (la compatibilidad no depende del almacén).
        var idAlm = (el('almSelAlmacen') || {}).value || '';
        var url = CFG.rutas.compatibilidad.replace('__PID__', id)
                + (idAlm ? '?id_almacen=' + encodeURIComponent(idAlm) : '');
        return window.apiFetch(url, { headers: { 'Accept': 'application/json' } })
            // Un error del servidor NO es "sin datos": pintarlo mostraría "(0)" como si el
            // producto no tuviera equipos. Se descarta y la sección queda oculta.
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                var m = el('almDetalleModal');
                if (!m || String(m.dataset.id) !== String(id)) return; // cambió de producto mientras cargaba
                // Reparto por proyecto: viene vacío en los almacenes que no separan.
                var proyectos = d.proyectos || [];
                // Mismo formato de cantidad que el panel lateral, que pinta ESTE MISMO dato
                // desde PHP: OfflineMode.fmt es la réplica exacta de number_format(n,3,',','.')
                // (toLocaleString no agrupa los miles de 4 dígitos y "1663" desentonaría con
                // el "1.663" del panel). formatNum queda de reserva por si el global no cargó.
                var fmtQty = (window.OfflineMode && window.OfflineMode.fmt) || formatNum;
                if (proyectos.length && proyBox && proyWrap) {
                    proyBox.innerHTML = proyectos.map(function (p) {
                        // La bolsa común va en cursiva y gris: es saldo real, pero de nadie
                        // en particular — el mismo criterio del panel lateral.
                        var nombre = p.comun
                            ? '<span style="font-style:italic;color:#64748b;">' + esc(p.proyecto) + '</span>'
                            : '<span style="font-weight:600;color:#334155;">' + esc(p.proyecto) + '</span>';
                        return '<div style="display:flex;align-items:center;gap:8px;background:#f8fafc;border:1px solid #eef2f7;border-radius:7px;padding:6px 9px;">' +
                               '<span style="font-size:12.5px;flex:1;min-width:0;">' + nombre + '</span>' +
                               '<span style="font-size:13px;font-weight:800;color:#0f172a;">' + fmtQty(p.cantidad) + '</span></div>';
                    }).join('');
                    proyWrap.style.display = 'block';
                }
                almDetCompatPintar(d);
            })
            .catch(function () { /* silencioso: el detalle sigue usable sin la compatibilidad */ });
    };

    // Pinta números de parte y equipos. `oculta` = estado de carga (todo escondido). Sin
    // permiso de edición solo se ven las secciones con datos, y nada si no hay ninguno (sin
    // mensaje de "vacío": el cliente lo pidió fuera); con permiso, las dos con su +.
    var almDetEquiposPintados = [], almDetEquipoOpciones = [];
    function almDetCompatPintar(d, oculta) {
        var esc = window.escapeHtml;
        var partes = d.equivalencias || [], equipos = d.equipos || [];
        var edita = HAS_PRODUCTOS;
        var quitar = function (attrs, titulo) {
            return edita ? '<button type="button" class="alm-det-quitar" title="' + titulo + '" ' + attrs + '><i class="material-icons">close</i></button>' : '';
        };
        el('almDetPartes').innerHTML = partes.map(function (p) {
            return '<span class="alm-det-chip">' + esc(p) + quitar('data-parte="' + esc(p) + '" onclick="window.almDetParteQuitar(this.dataset.parte)"', 'Quitar este número de parte') + '</span>';
        }).join('');
        el('almDetPartesCount').textContent = '(' + partes.length + ')';
        el('almDetEquiposCount').textContent = '(' + equipos.length + ')';
        // La × manda los `ids` de la fila (un modelo con varias fichas del catálogo son varios).
        almDetEquiposPintados = equipos;
        // La ETAPA (primario/secundario) va por EQUIPO, no por producto: el mismo filtro puede
        // ser primario en una máquina y secundario en otra. Sin confirmar no se muestra.
        el('almDetEquipos').innerHTML = equipos.map(function (e, i) {
            return '<div class="alm-det-eq">'
                + '<span class="alm-det-eq-tipo" title="' + esc(e.tipo) + '">' + esc(e.tipo) + '</span>'
                + '<span class="alm-det-eq-mod">' + esc(e.modelo) + '</span>'
                + (e.cant > 1 ? '<span class="alm-det-eq-dato">x' + e.cant + '</span>' : '')
                + (e.etapa ? '<span class="alm-det-eq-etapa">' + esc(e.etapa) + '</span>' : '')
                + quitar('onclick="window.almDetEquipoQuitar(' + i + ')"', 'Desvincular este equipo')
                + '</div>';
        }).join('');
        // Sin elementos no hay nada que desplegar: la flecha se apaga y la sección se cierra
        // (salvo que su formulario esté abierto: con permiso, el "+" sigue funcionando).
        [['almDetPartesWrap', partes.length, 'almDetParteForm'], ['almDetEquiposWrap', equipos.length, 'almDetEquipoForm']].forEach(function (s) {
            el(s[0]).querySelector('.alm-det-sec-tog').disabled = !s[1];
            if (!s[1] && el(s[2]).hidden) almDetSeccion(s[0], false);
        });
        document.querySelectorAll('#almDetCompat .alm-det-solo-edita').forEach(function (b) { b.hidden = !edita; });
        el('almDetPartesWrap').hidden = !(edita || partes.length);
        el('almDetEquiposWrap').hidden = !(edita || equipos.length);
        el('almDetCompat').hidden = !!oculta || !(edita || partes.length || equipos.length);
    }
    // Abre (true), cierra (false) o alterna (sin segundo argumento) un desplegable de la ficha.
    function almDetSeccion(idWrap, abrir) {
        var wrap = el(idWrap); if (!wrap) return;
        var cuerpo = wrap.querySelector('.alm-det-sec-cuerpo'), tog = wrap.querySelector('.alm-det-sec-tog');
        var abierto = abrir === undefined ? cuerpo.hidden : !!abrir;
        cuerpo.hidden = !abierto;
        tog.setAttribute('aria-expanded', abierto ? 'true' : 'false');
    }
    window.almDetSeccion = almDetSeccion;

    function almDetCompatMsg(texto) {
        var m = el('almDetCompatMsg'); if (!m) return;
        m.textContent = texto || ''; m.hidden = !texto;
    }
    function almDetFormCerrar() {
        ['almDetParteForm', 'almDetEquipoForm'].forEach(function (f) { var x = el(f); if (x) x.hidden = true; });
        var s = el('almDetEquipoSug'); if (s) s.innerHTML = '';
        almDetCompatMsg('');
    }
    window.almDetFormCerrar = almDetFormCerrar;

    // Guarda un cambio de compatibilidad (+ / ×) del producto abierto. El servidor responde con
    // la compatibilidad ya al día y se repinta; la fila de la tabla se recarga (lleva los
    // números de parte y de ahí los toma "Editar producto" y la salida).
    var ROUTE_COMPAT = {
        equivalencias: CFG.rutas.equivalenciasStore,
        equipos:       CFG.rutas.equiposStore,
        opciones:      CFG.rutas.equiposOpciones,
    };
    // Uno a la vez: un doble clic mandaba dos veces lo mismo y el segundo volvía con "ya está".
    var almDetCompatOcupado = false;
    function almDetCompatCambiar(que, metodo, cuerpo) {
        var m = el('almDetalleModal'); var id = m ? m.dataset.id : '';
        if (almDetCompatOcupado || !id || !ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para editar productos.')) return;
        almDetCompatOcupado = true;
        almDetCompatMsg('');
        window.apiFetch(ROUTE_COMPAT[que].replace('__PID__', id), {
            method: metodo,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            body: JSON.stringify(cuerpo)
        })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) {
                if (String(el('almDetalleModal').dataset.id) !== String(id)) return;
                if (!res.ok) {
                    var errs = res.b.errors ? Object.values(res.b.errors).map(function (e) { return e[0]; }) : [];
                    almDetCompatMsg(errs[0] || res.b.message || 'No se pudo guardar.');
                    return;
                }
                almDetFormCerrar();
                almDetCompatPintar(res.b);
                almDetCompatAplicarASeleccion(id, res.b.equivalencias || []);
                almRecargarMostrando(id);
            })
            .catch(function () { almDetCompatMsg('No se pudo contactar al servidor.'); })
            .finally(function () { almDetCompatOcupado = false; });
    }
    // Si el producto está en la salida en curso, su entrada sigue a las equivalencias nuevas:
    // con una sola, es esa; si se quitó la que estaba elegida, se vuelve a pedir.
    function almDetCompatAplicarASeleccion(id, partes) {
        var s = almSeleccion[id]; if (!s) return;
        s.partes = partes.length;
        if (s.parte && partes.indexOf(s.parte) === -1) s.parte = '';
        if (!s.parte && partes.length === 1) s.parte = partes[0];
    }

    window.almDetParteAbrir = function () {
        almDetFormCerrar();
        almDetSeccion('almDetPartesWrap', true);
        el('almDetParteForm').hidden = false;
        var i = el('almDetParteInput'); i.value = ''; i.focus();
    };
    window.almDetParteGuardar = function () {
        var np = (el('almDetParteInput').value || '').trim();
        if (!np) { almDetCompatMsg('Escribe el número de parte.'); return; }
        almDetCompatCambiar('equivalencias', 'POST', { numero_parte: np });
    };
    window.almDetParteQuitar = function (np) { almDetCompatCambiar('equivalencias', 'DELETE', { numero_parte: np }); };

    var _almDetEquipoEspera = null, _almDetEquipoPedido = 0;
    window.almDetEquipoAbrir = function () {
        almDetFormCerrar();
        almDetSeccion('almDetEquiposWrap', true);
        el('almDetEquipoForm').hidden = false;
        var i = el('almDetEquipoInput'); i.value = ''; i.focus();
        window.almDetEquipoBuscar();
    };
    // "placa X" bajo el modelo de la sugerencia. Con la placa a medio escribir un mismo modelo
    // puede traer muchas (medido: 9 con "A46BN"): se muestran 2 y "+N", y la lista entera va en el title.
    function almDetPlacasEtiqueta(placas) {
        if (!placas || !placas.length) return '';
        var esc = window.escapeHtml;
        // \u00a0 (espacio que no parte): el "+N" nunca queda solo en otra línea.
        var txt = placas.slice(0, 2).join(', ') + (placas.length > 2 ? '\u00a0+' + (placas.length - 2) : '');
        return '<span class="alm-det-sug-placa" title="' + esc(placas.join(', ')) + '">placa ' + esc(txt) + '</span>';
    }
    // Sugerencias del servidor (modelos del catálogo y auxiliares que el producto aún no tiene;
    // escribiendo una placa, el modelo de ese equipo, marcado con la placa).
    // Solo pinta la respuesta de la ÚLTIMA búsqueda: si una anterior llega tarde, se descarta.
    window.almDetEquipoBuscar = function () {
        clearTimeout(_almDetEquipoEspera);
        _almDetEquipoEspera = setTimeout(function () {
            var m = el('almDetalleModal'); var id = m ? m.dataset.id : ''; if (!id) return;
            var q = (el('almDetEquipoInput').value || '').trim(), pedido = ++_almDetEquipoPedido;
            window.apiFetch(ROUTE_COMPAT.opciones.replace('__PID__', id) + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    var box = el('almDetEquipoSug'); if (!box || el('almDetEquipoForm').hidden || pedido !== _almDetEquipoPedido) return;
                    var esc = window.escapeHtml, ops = d.opciones || [];
                    almDetEquipoOpciones = ops;
                    box.innerHTML = ops.length
                        ? ops.map(function (o, i) {
                            return '<div class="alm-det-sug-item" onclick="window.almDetEquipoVincular(' + i + ')">'
                                + '<span class="alm-det-eq-tipo" title="' + esc(o.tipo) + '">' + esc(o.tipo) + '</span>'
                                + '<span class="alm-det-eq-mod">' + esc(o.modelo) + almDetPlacasEtiqueta(o.placas) + '</span>'
                                + '</div>';
                          }).join('')
                        : '<div class="alm-det-sug-vacio">Ningún equipo coincide' + (q ? ' con «' + esc(q) + '»' : '') + '.</div>';
                })
                .catch(function () {});
        }, 180);
    };
    window.almDetEquipoVincular = function (i) {
        var o = almDetEquipoOpciones[i]; if (!o) return;
        almDetCompatCambiar('equipos', 'POST', { origen: o.origen, refs: o.refs });
    };
    window.almDetEquipoQuitar = function (i) {
        var e = almDetEquiposPintados[i]; if (!e) return;
        almDetCompatCambiar('equipos', 'DELETE', { origen: e.origen, ids: e.ids });
    };
    // Cierra "Detalles del producto" y, si la fila de ese producto SIGUE seleccionada,
    // devuelve el foco a su input de cantidad — así el usuario escribe la salida de una
    // (como al recién seleccionar). Sin esto el registro quedaba seleccionado pero el input
    // sin foco tras el modal, y había que deseleccionar/reseleccionar para poder escribir.
    window.almDetalleCerrar = function () {
        var m  = el('almDetalleModal');
        var id = m ? (m.dataset.id || '') : '';
        // Persistir la ubicación tecleada ANTES de cerrar (el modal ya no tiene botón
        // "Guardar"). Es no-op si el texto no cambió. Único punto: por aquí pasan tanto
        // la "✕" como el Escape del handler global.
        if (window.almGuardarUbicacionDetalle) window.almGuardarUbicacionDetalle();
        almCerrar('almDetalleModal');
        if (!id || !almSeleccion[id]) return;
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + id + '"]');
        if (tr) almEnfocarCantidad(tr);
    };
    // Cancelar de un sub-modal (Auditoría / Stock mínimo / Editar): cierra ese modal y, si
    // venía de "Detalles del producto" (window.almDesdeDetalle), REABRE Detalles — sus datos
    // siguen en el dataset. La X de cada modal cierra del todo (no llama a esto). El flag se
    // pone en false al abrir "Nuevo producto" (almAbrirProducto), que comparte el modal Editar
    // pero NO viene de Detalles.
    window.almVolverADetalle = function (subId) {
        almCerrar(subId);
        var det = el('almDetalleModal');
        if (window.almDesdeDetalle && det && det.dataset.id) almOpen('almDetalleModal');
        window.almDesdeDetalle = false;
    };
    window.almDetalleAccion = function (which) {
        var m = el('almDetalleModal'); if (!m) return;
        var d = m.dataset, id = parseInt(d.id, 10);
        var minimo = (d.minimo === '' ? null : parseFloat(d.minimo));
        var saldo  = parseFloat(d.saldo || 0);
        // Ubicación TECLEADA (puede diferir de d.ubicacion, que es la última guardada).
        var ubicInput = el('almDetUbicacion');
        var ubicViva  = ubicInput ? (ubicInput.value || '').trim() : (d.ubicacion || '');

        // Salir a un sub-modal también abandona "Detalles", así que la ubicación tecleada se
        // persiste igual que al cerrar — si no, escribirla y tocar "Auditoría" la perdía en
        // silencio (ya no hay botón "Guardar" que la respalde). Dos excepciones:
        //   · 'editar'  → el modal de edición YA guarda UBICACION; le pasamos el valor vivo y
        //                 dejamos que él lo persista. Guardar aquí sería un PATCH duplicado.
        //   · 'eliminar'→ el producto se va; guardarle la ubicación antes es trabajo perdido.
        if (which !== 'editar' && which !== 'eliminar') window.almGuardarUbicacionDetalle();

        almCerrar('almDetalleModal');
        window.almDesdeDetalle = true;   // los sub-modales que siguen se abrieron desde Detalles
        switch (which) {
            // 'entrada'/'salida' removidos (esos flujos ya no van por producto individual).
            case 'ajuste':   if (window.almAbrirAjuste)         window.almAbrirAjuste(id, d.cod, d.nom, d.um, saldo); break;
            // 'minimo' → modal propio (separado de la Auditoría) para configurar el stock minimo.
            case 'minimo':   if (window.almAbrirMinimo)         window.almAbrirMinimo(id, d.nom, minimo); break;
            // 'kardex' antes navegaba a /admin/almacen/movimientos; ahora abre un
            // modal local con los movimientos solo de este producto + filtros mínimos.
            case 'kardex':   if (window.almAbrirKardexProducto) window.almAbrirKardexProducto(id, d.cod, d.nom, d.um, saldo); break;
            case 'editar':   if (window.almEditarProducto)      window.almEditarProducto(id, d.cod, d.nom, d.um, d.cat, ubicViva); break;
            case 'eliminar': if (window.almEliminarProducto)    window.almEliminarProducto(id); break;
        }
    };

    // Guarda SOLO la ubicación desde "Detalles del producto" (sin pasar por el modal
    // completo de Editar). Reusa el mismo endpoint PATCH que almGuardarProducto, mandando
    // el resto de campos (NOMBRE/UM/CATEGORIA/CODIGO) tal cual están en el dataset del
    // modal para no pisarlos — este endpoint no soporta PATCH parcial (ver validarProducto).
    //
    // Se dispara desde DOS sitios (ya no hay botón "Guardar"): Enter en el input y el cierre
    // del modal vía almDetalleCerrar. Por eso arranca comparando contra el valor con el que
    // se abrió el modal: sin ese corte, cada cierre lanzaría un PATCH y un toast aunque el
    // usuario no hubiera tocado el campo.
    window.almGuardarUbicacionDetalle = function () {
        var m = el('almDetalleModal'); if (!m || !m.dataset.id) return;
        var input = el('almDetUbicacion'); if (!input) return;
        var ubicacion = (input.value || '').trim();
        if (ubicacion === (m.dataset.ubicacion || '').trim()) return; // sin cambios → no molestar
        // El permiso se chequea DESPUÉS de detectar el cambio: si no, cerrar el modal sin
        // tocar nada le lanzaría el toast de "no tienes permiso" a cualquier usuario de solo lectura.
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para editar la ubicación.')) {
            input.value = m.dataset.ubicacion || ''; // revertir lo tecleado
            return;
        }
        var id = parseInt(m.dataset.id, 10);
        showErr('almDetUbicacionError', '');
        pre();
        window.apiFetch(ROUTE_PROD_ITEM(id), {
            method: 'PATCH',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify({
                NOMBRE: m.dataset.nom, UM: m.dataset.um, CATEGORIA: m.dataset.cat || null,
                UBICACION: ubicacion || null
            })
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                m.dataset.ubicacion = ubicacion;
                toast('Ubicación actualizada.');
                almRecargarMostrando(id);
            } else {
                // El error va TAMBIÉN por toast: si el guardado se disparó al cerrar el modal,
                // el mensaje inline queda dentro de un modal ya oculto y nadie lo vería.
                var msg = (res.b && res.b.message) || 'No se pudo guardar la ubicación.';
                showErr('almDetUbicacionError', msg);
                toast(msg, 'error');
            }
        })
        .catch(function () {
            unpre();
            showErr('almDetUbicacionError', 'Error de red.');
            toast('Error de red al guardar la ubicación.', 'error');
        });
    };

    // ── Modal "Movimientos del producto" (kardex local de UN producto) ──
    // Reusa AlmacenController::movimientos con ?mini=1 (partial de 5 columnas)
    // y filtra por id_producto + id_almacen actual. Estado en window.__almKp.
    window.__almKp = { idProducto: null, tipo: '', desde: '', hasta: '' };

    // Deja los tres controles (tipo + ambas fechas) en blanco y sincroniza el estado.
    // NO recarga: quien la llama decide cuándo pedir los datos. La usan tanto la
    // apertura del modal como el botón "Limpiar" — antes estaba copiada en las dos.
    function almKpResetFiltros() {
        if (el('almKpDesde'))      el('almKpDesde').value = '';
        if (el('almKpHasta'))      el('almKpHasta').value = '';
        if (el('almKpTipoSelect')) el('almKpTipoSelect').value = '';
        window.__almKp.tipo = window.__almKp.desde = window.__almKp.hasta = '';
    }

    window.almAbrirKardexProducto = function (idProducto, codigo, nombre, um, saldo) {
        window.__almKp.idProducto = idProducto;
        almKpResetFiltros();
        el('almKpCodigo').textContent = codigo || '—';
        el('almKpNombre').textContent = nombre || '';
        el('almKpSaldo').textContent  = formatNum(saldo);
        el('almKpUm').textContent     = um || '';
        // Saldo en cero → en rojo, mismo criterio visual que una cantidad que resta.
        var box = el('almKpSaldoBox');
        if (box) box.classList.toggle('cero', !(parseFloat(saldo) > 0));
        almOpen('almKardexProductoModal');
        window.almKpCargar();
    };

    // almKpChipSelect: gestiona el filtro de tipo desde el <select> del modal kardex.
    window.almKpChipSelect = function (tipo) {
        window.__almKp.tipo = tipo || '';
        window.almKpCargar();
    };

    // Quita los tres filtros de golpe y recarga. El botón que la llama solo se ve
    // cuando hay alguno puesto (lo gobierna almKpCargar).
    window.almKpLimpiar = function () {
        almKpResetFiltros();
        window.almKpCargar();
    };

    window.almKpCargar = function (pageUrl) {
        if (!window.__almKp.idProducto) return;
        window.__almKp.desde = (el('almKpDesde') && el('almKpDesde').value) || '';
        window.__almKp.hasta = (el('almKpHasta') && el('almKpHasta').value) || '';

        // Fecha sin elegir → su texto nativo (dd/mm/aaaa) en gris claro; y "Limpiar"
        // aparece solo si algún filtro está puesto.
        if (el('almKpDesde')) el('almKpDesde').classList.toggle('alm-kp-sinfecha', !window.__almKp.desde);
        if (el('almKpHasta')) el('almKpHasta').classList.toggle('alm-kp-sinfecha', !window.__almKp.hasta);
        var btnLimpiar = el('almKpBtnLimpiar');
        if (btnLimpiar) btnLimpiar.hidden = !(window.__almKp.tipo || window.__almKp.desde || window.__almKp.hasta);

        var p = new URLSearchParams();
        p.set('id_producto', window.__almKp.idProducto);
        p.set('mini', '1');
        if (val('almSelAlmacen')) p.set('id_almacen', val('almSelAlmacen'));
        if (window.__almKp.tipo)  p.set('tipo',  window.__almKp.tipo);
        if (window.__almKp.desde) p.set('desde', window.__almKp.desde);
        if (window.__almKp.hasta) p.set('hasta', window.__almKp.hasta);
        if (pageUrl) {
            try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (e) {}
        }

        var body = el('almKpBody'); if (body) body.style.opacity = '0.5';
        window.apiFetch(ROUTE_MOVIMIENTOS + '?' + p.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (body && data.html !== undefined) body.innerHTML = data.html;
            var pg = el('almKpPag'); if (pg) pg.innerHTML = data.pagination || '';
        })
        .catch(function () {
            if (body) body.innerHTML = '<tr><td colspan="5" class="alm-kp-estado error">No se pudieron cargar los movimientos.</td></tr>';
        })
        .finally(function () { if (body) body.style.opacity = '1'; });
    };

    // Click en links de paginación del kardex del producto.
    document.addEventListener('click', function (e) {
        var a = e.target.closest('#almKpPag a'); if (!a) return;
        e.preventDefault(); e.stopImmediatePropagation();
        window.almKpCargar(a.href);
    }, true);

    window.almAbrirAjuste = function (idProducto, codigo, nombre, um, saldo) {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return;
        var m = el('almAjusteModal');
        m.dataset.idProducto = idProducto;
        // Mostrar el saldo actual (sistema) para que el usuario sepa desde qué valor ajusta.
        var sv = el('almAjSaldoActual');
        if (sv) { var s = parseFloat(saldo); sv.textContent = (isNaN(s) ? '—' : formatNum(s)) + (um ? ' ' + um : ''); }
        el('almAjNuevoSaldo').value = '';
        showErr('almAjError', ''); almOpen('almAjusteModal');
    };

    // Reevalúa el resaltado de "stock bajo" de una fila con un saldo nuevo, SIN esperar la
    // recarga: actualiza data-saldo y togglea .alm-row-bajo comparando contra data-minimo.
    // Así el color (fondo/franja roja) se actualiza AL INSTANTE tras una auditoría (la
    // recarga posterior lo confirma). Antes el color quedaba "pegado" hasta deseleccionar
    // o recargar. Reutilizable por cualquier operación que cambie el stock de un producto visible.
    function almReevaluarStockFila(idProducto, nuevoSaldo) {
        var tr = document.querySelector('#almTableBody tr.alm-row[data-id-producto="' + idProducto + '"]');
        if (!tr) return;
        var saldo = parseFloat(nuevoSaldo);
        if (isNaN(saldo)) return;
        tr.dataset.saldo = String(saldo);
        var minStr = tr.dataset.minimo;
        var bajo = (minStr !== undefined && minStr !== '') && saldo <= parseFloat(minStr);
        tr.classList.toggle('alm-row-bajo', bajo);
        tr.dataset.bajo = bajo ? '1' : '0';
        // Sincronizar el saldo CACHEADO de la selección (si la fila está seleccionada) para que
        // el control "excede stock" use el saldo nuevo. La RE-EVALUACIÓN del "excede" no se hace
        // aquí: la auditoría siempre recarga a continuación (almRecargarMostrando), y almSelApplyToRows la
        // recalcula en la recarga (evitamos duplicar esa lógica).
        if (almSeleccion[idProducto]) almSeleccion[idProducto].saldo = saldo;
    }

    window.almGuardarAjuste = function () {
        // Guard de permiso: la Auditoría registra un AJUSTE de inventario, que exige la
        // clave almacen.movimiento. (El stock mínimo se movió a su propio modal/flujo.)
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para registrar movimientos de inventario.')) return;
        var m = el('almAjusteModal');
        var idAlm = val('almSelAlmacen'); if (!idAlm) { showErr('almAjError', 'No hay almacén seleccionado.'); return; }
        var nuevoSaldoRaw = val('almAjNuevoSaldo');
        if (nuevoSaldoRaw === '') { showErr('almAjError', 'Indica el saldo según el conteo físico.'); return; }
        var ns = parseFloat(nuevoSaldoRaw);
        if (isNaN(ns) || ns < 0) { showErr('almAjError', 'El nuevo saldo debe ser un número ≥ 0.'); return; }

        pre();
        // Endpoint unificado de lote: la Auditoría se registra como un lote de 1 línea con
        // tipo=AJUSTE. El backend ignora los campos de Nota de Entrega para AJUSTE.
        window.apiFetch(ROUTE_LOTE, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify({
                id_almacen: idAlm,
                tipo: 'AJUSTE',
                motivo: 'Auditoría de Inventario',
                lineas: [{ id_producto: m.dataset.idProducto, cantidad: ns }]
            })
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
          .then(function (res) {
              unpre();
              // Error: se recarga para ver el dato vigente (conservando "Ver todo"), pero SIN el
              // resalte de "cambiado", porque no cambió nada.
              if (!res.ok) { showErr('almAjError', (res.b && res.b.message) || 'No se pudo registrar la auditoría.'); almCargar({ verTodo: almVerTodoActivo }); return; }
              // Feedback instantáneo del resaltado (rojo/normal) con el saldo auditado;
              // almRecargarMostrando recarga, confirma con el dato fresco y deja la fila a la vista.
              almReevaluarStockFila(m.dataset.idProducto, ns);
              // Tras la recarga, dejar el teclado listo en el input de cantidad de esta fila
              // (sigue seleccionada) — sin tener que deseleccionar/reseleccionar para escribir.
              _almPendingFocusId = m.dataset.idProducto;
              almCerrar('almAjusteModal'); toast('Auditoría registrada.'); almRecargarMostrando(m.dataset.idProducto);
          }).catch(function () { unpre(); showErr('almAjError', 'Error de red.'); });
    };

    // ── Modal "Stock mínimo (alerta)" — setea SOLO el mínimo de alerta del producto en el
    //    almacén actual (PATCH almacen.minimo). Separado de la Auditoría: son dos
    //    operaciones distintas con su propio botón en "Detalles del producto". ──
    window.almAbrirMinimo = function (idProducto, nombre, minimo) {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para configurar el stock mínimo.')) return;
        var m = el('almMinimoModal'); if (!m) return;
        m.dataset.idProducto = idProducto;
        m.dataset.minimoOrig = (minimo == null ? '' : String(minimo)); // para detectar si cambió
        el('almMinValor').value = (minimo == null ? '' : minimo);
        showErr('almMinError', ''); almOpen('almMinimoModal');
    };

    window.almGuardarMinimo = function () {
        if (!ensurePerm(HAS_MOVER, 'No tienes permiso para configurar el stock mínimo.')) return;
        var m = el('almMinimoModal');
        var idAlm = val('almSelAlmacen'); if (!idAlm) { showErr('almMinError', 'No hay almacén seleccionado.'); return; }
        var minimoRaw = val('almMinValor');
        // Si no cambió respecto al valor original, no hay nada que guardar.
        if (minimoRaw === (m.dataset.minimoOrig || '')) { almCerrar('almMinimoModal'); return; }
        var nuevoMinimo = null;
        if (minimoRaw !== '') {
            // El mínimo de alerta debe ser > 0 (un mínimo de 0 no avisa de nada, equivale a
            // "sin alerta" — que ya se logra dejando el campo vacío).
            nuevoMinimo = parseFloat(minimoRaw);
            if (isNaN(nuevoMinimo) || nuevoMinimo <= 0) { showErr('almMinError', 'El mínimo debe ser un número mayor que 0 (o dejarlo vacío para quitar la alerta).'); return; }
        }
        pre();
        window.apiFetch(ROUTE_MIN(idAlm), {
            method: 'PATCH',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify({ id_producto: m.dataset.idProducto, cantidad_minima: (minimoRaw === '' ? null : nuevoMinimo) })
        }).then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
          .then(function (res) {
              unpre();
              if (!res.ok) { showErr('almMinError', (res.b && res.b.message) || 'No se pudo actualizar el stock mínimo.'); return; }
              almCerrar('almMinimoModal'); toast('Stock mínimo actualizado.'); almRecargarMostrando(m.dataset.idProducto);
          }).catch(function () { unpre(); showErr('almMinError', 'Error de red.'); });
    };

    // confirmación reutilizable (usa el modal estándar de la app si existe; si no, confirm()).
    // Atajo local sobre window.confirmarAccion (layout_ui.js), que ya trae dentro el
    // respaldo al confirm() del navegador y el quitado de etiquetas del mensaje. Ese
    // helper existe justamente para no repetir el if/else en cada módulo; aquí solo se
    // fijan el tipo y los rótulos que usa todo el inventario.
    function almConfirm(msg, onYes) {
        window.confirmarAccion({
            type: 'danger', title: '¿Confirmar?', message: msg,
            confirmText: 'Aceptar', cancelText: 'Cancelar',
        }, onYes);
    }

    // ── Bloque CRUD de Almacenes + Productos ──
    // Las funciones se definen SIEMPRE (sin guard de Blade alrededor del bloque).
    // Cada una llama a ensurePerm(...) antes de actuar — si el usuario no tiene
    // la clave necesaria, se muestra toast moderno y no se ejecuta nada mas. Esto
    // reemplaza al viejo patron donde el bloque entero estaba envuelto en un
    // condicional Blade con stubs en la rama alternativa, que dejaba al usuario
    // sin feedback visible (los botones se ocultaban).
    // NOTA: NUNCA escribas directivas Blade textualmente dentro de comentarios
    // JavaScript — Blade las compila aunque esten en un comentario y produce PHP
    // invalido al renderizar la vista.
    var ROUTE_ALM = CFG.rutas.almacenesStore;
    function ROUTE_ALM_ITEM(id) { return ROUTE_INDEX + '/almacenes/' + id; }
    function ROUTE_ALM_LOGISTICA(id) { return ROUTE_ALM_ITEM(id) + '/logistica'; }
    function ROUTE_PROD_ITEM(id) { return ROUTE_INDEX + '/productos/' + id; }
    // Datos de los almacenes visibles (para el modal de edición): { id: {NOMBRE,TIPO,CODIGO,UBICACION,frentes:[ids]} }
    window.almAlmacenesData = CFG.almacenesData;

    // Selección del custom-dropdown "Tipo" en el modal de almacén
    window.almNvTipoSelect = function (value, label) {
        var hidden = document.getElementById('almNvTipo');
        var display = document.getElementById('almNvTipoDisplay');
        var dropdown = document.getElementById('almNvTipoDropdown');
        if (hidden) hidden.value = value;
        if (display) display.value = label;
        // Marcar el item seleccionado
        dropdown.querySelectorAll('.dropdown-item').forEach(function(i) {
            i.classList.toggle('selected', i.dataset.value === value);
        });
        // Cerrar el dropdown (dejar que el CSS lo oculte al quitar .active)
        dropdown.classList.remove('active');
        var content = dropdown.querySelector('.dropdown-content');
        if (content) content.style.display = '';
        var trigger = dropdown.querySelector('.dropdown-trigger');
        if (trigger) trigger.style.borderColor = '#cbd5e0';
        // Actualizar visibilidad del panel de frentes
        window.almToggleFrentes();
        // El campo Nombre cambia de sentido con el tipo: en PROYECTO es el nombre del
        // proyecto (se elige de la lista); en GENERAL es un almacén central, que no
        // corresponde a ningún frente y por eso se escribe.
        window.almNvNombreModo();
    };

    // Ajusta el campo Nombre al tipo elegido. UN solo sitio decide qué se ve, para que el
    // texto de ayuda, el placeholder y la lista no puedan quedar diciendo cosas distintas.
    // En GENERAL no lleva texto de ayuda: el placeholder ya dice qué escribir.
    window.almNvNombreModo = function () {
        var esProyecto = (el('almNvTipo') || {}).value !== 'GENERAL';
        var inp  = el('almNvNombre');
        var hint = el('almNvNombreHint');
        var dd   = el('almNvNombreDropdown');
        if (inp)  inp.placeholder = esProyecto ? 'Elige el proyecto…' : 'Ej: ALMACÉN CENTRAL CARACAS';
        if (hint) hint.hidden = !esProyecto;
        // En GENERAL la lista de proyectos sobra: se oculta y el campo queda como uno de
        // texto normal (el caret desaparece con ella).
        if (dd) {
            dd.classList.toggle('alm-dd-sin-lista', !esProyecto);
            if (!esProyecto) dd.classList.remove('active');
        }
    };

    // Filtra la lista de proyectos por lo que se va escribiendo. Mismo comportamiento que
    // "Contrato N°": la lista guía, pero lo que vale es el texto del input.
    window.almNvNombreFilter = function (inp) {
        var t = (inp.value || '').trim().toLowerCase();
        var items = document.querySelectorAll('#almNvNombreItems .dropdown-item');
        var visibles = 0;
        items.forEach(function (i) {
            var ok = !t || (i.dataset.nombre || '').toLowerCase().indexOf(t) !== -1;
            i.style.display = ok ? '' : 'none';
            if (ok) visibles++;
        });
        var nm = el('almNvNombreNoMatch'); if (nm) nm.style.display = (visibles === 0 && t) ? '' : 'none';
        // En GENERAL no hay lista que abrir (la oculta .alm-dd-sin-lista): marcarla como
        // abierta dejaria el desplegable en un estado que no se ve pero existe.
        var dd = el('almNvNombreDropdown');
        if (dd && !dd.classList.contains('alm-dd-sin-lista')) dd.classList.add('active');
    };

    // Elegir un proyecto pone su nombre Y lo tilda abajo en "Frentes que usan este almacén":
    // es el mismo dato, y dejar el almacén llamado como un frente que no atiende era el
    // error mas facil de cometer. Los demas frentes ya tildados se respetan (un almacen de
    // proyecto puede servir a varios, como Patio El Tigre).
    window.almNvNombrePick = function (idFrente, nombre) {
        var inp = el('almNvNombre'); if (inp) inp.value = nombre;
        var chk = el('almNvFrente_' + idFrente);
        if (chk && !chk.checked) { chk.checked = true; window.almNvFrentesUpdate(); }
        var dd = el('almNvNombreDropdown'); if (dd) dd.classList.remove('active');
    };

    // Formato por defecto (Almacen::FORMATO_NOTA_VERTICAL). Los formatos VÁLIDOS no se
    // repiten aquí: son los checks que el blade ya pintó desde Almacen::FORMATOS_NOTA, así
    // que una lista aparte en JS solo podría desincronizarse.
    var ALM_FORMATO_NOTA_DEF = CFG.formatoNotaDef;
    // El valor HORIZONTAL sale del modelo (Almacen::FORMATO_NOTA_HORIZONTAL) y no escrito a
    // mano: lo lee almSalidaAplicarFormatoNota() para decidir qué campos pide el modal de
    // salida. Es el ÚNICO formato que el JS necesita nombrar (el resto se comporta como el
    // vertical de siempre), por eso va este solo y no una copia de FORMATOS_NOTA.
    var ALM_FORMATO_NOTA_HORIZONTAL = CFG.formatoNotaHorizontal;

    // Firmantes fijos de la nota horizontal: ÚNICO sitio que empareja cada campo del modal
    // con su columna en la BD. Lo usan el reset, la carga al editar y el guardado, así que
    // esos tres no se pueden desincronizar (antes de esto habría que repetir la lista 3 veces
    // y un renombre a medias dejaba el campo guardándose vacío sin avisar).
    var ALM_FIRMANTES_CAMPOS = [
        { id: 'almNvCedulaAlmacenista', col: 'CEDULA_ALMACENISTA' },
        { id: 'almNvSop1Nom',           col: 'SOPORTE_1_NOM' },
        { id: 'almNvSop1Car',           col: 'SOPORTE_1_CAR' },
        { id: 'almNvSop1Ced',           col: 'SOPORTE_1_CED' },
        { id: 'almNvSop2Nom',           col: 'SOPORTE_2_NOM' },
        { id: 'almNvSop2Car',           col: 'SOPORTE_2_CAR' },
        { id: 'almNvSop2Ced',           col: 'SOPORTE_2_CED' },
        { id: 'almNvSegNom',            col: 'SEGURIDAD_NOM' },
        { id: 'almNvSegCar',            col: 'SEGURIDAD_CAR' },
        { id: 'almNvSegCed',            col: 'SEGURIDAD_CED' }
    ];

    // Deja tildado UN formato y destilda el resto. Es el ÚNICO sitio que escribe el hidden
    // #almNvFormato, así que los checks y el valor que se guarda no se pueden separar: lo
    // llaman los propios checks (onchange), el reset y la carga al editar.
    //
    // Volver a tildar el que ya estaba lo deja igual: el navegador lo destilda al hacer clic
    // y aquí se vuelve a marcar, así que SIEMPRE queda exactamente uno.
    window.almNvFormatoSelect = function (value) {
        var checks = Array.prototype.slice.call(document.querySelectorAll('#almNvFormatoOpts input[type="checkbox"]'));
        // Un valor que no corresponde a ningún check cae al default, igual que
        // Almacen::normalizarFormatoNota en el backend: el modal nunca queda con un formato
        // que el servidor no acepta.
        if (!checks.some(function (c) { return c.value === value; })) value = ALM_FORMATO_NOTA_DEF;
        var hidden = el('almNvFormato');
        if (hidden) hidden.value = value;
        checks.forEach(function (c) { c.checked = (c.value === value); });
        // Los dos SOPORTADO solo existen en el formato horizontal (ENTREGADO no: lo imprimen
        // los dos, ver el modal). Se OCULTAN, no se limpian: alternar de formato no debe
        // borrar lo que el usuario ya escribió.
        var firm = el('almNvFirmantesWrap');
        if (firm) firm.hidden = (value !== 'HORIZONTAL');
    };

    window.almToggleFrentes = function () {
        // El selector de frentes aplica a AMBOS tipos de almacén: la visibilidad
        // para los usuarios LOCAL se define por los frentes asociados, sea GENERAL
        // o PROYECTO (ver Almacen::visiblesPara). Antes se ocultaba para GENERAL.
        var wrap = el('almNvFrentesWrap');
        if (wrap) wrap.style.display = '';
    };
    // Checkboxes del multiselect de frentes del modal de almacén.
    function almNvFrenteChecks() { return Array.prototype.slice.call(document.querySelectorAll('#almNvFrentesSelect input[type="checkbox"]')); }
    // Filtra las opciones de frente al escribir en el input principal del trigger.
    window.almNvFrentesFilter = function (inp) {
        var v = (inp && inp.value || '').toLowerCase().trim();
        var visibles = 0;
        document.querySelectorAll('#almNvFrentesSelect .alm-frente-opt').forEach(function (i) {
            var match = v === '' || i.textContent.toLowerCase().indexOf(v) > -1;
            i.style.display = match ? '' : 'none';
            if (match) visibles++;
        });
        var noMatch = el('almNvFrentesNoMatch');
        if (noMatch) noMatch.style.display = (v !== '' && visibles === 0) ? '' : 'none';
        // Mientras filtra, mantener el menú abierto.
        var box = el('almNvFrentesSelect');
        if (box && v !== '' && !box.classList.contains('active')) box.classList.add('active');
    };
    // Actualiza el placeholder del trigger según cuántos frentes están marcados.
    window.almNvFrentesUpdate = function () {
        var inp = el('almNvFrentesInput'); if (!inp) return;
        var sel = almNvFrenteChecks().filter(function (c) { return c.checked; });
        if (sel.length === 0)      inp.placeholder = 'Selecciona los frentes…';
        else if (sel.length === 1) {
            var sp = sel[0].closest('.multiselect-item').querySelector('span');
            inp.placeholder = sp ? sp.textContent.trim() : '1 frente';
        }
        else inp.placeholder = sel.length + ' frentes seleccionados';
    };
    function almNvSetFrentes(ids) {
        var set = {}; (ids || []).forEach(function (x) { set[String(x)] = true; });
        almNvFrenteChecks().forEach(function (c) { c.checked = !!set[c.value]; });
        // Reset del filtro (vaciar el input principal y volver a mostrar todas las opciones).
        var inp = el('almNvFrentesInput'); if (inp) inp.value = '';
        var box = el('almNvFrentesSelect');
        if (box) {
            box.querySelectorAll('.alm-frente-opt').forEach(function (i) { i.style.display = ''; });
            var nm = el('almNvFrentesNoMatch'); if (nm) nm.style.display = 'none';
            box.classList.remove('active');
        }
        window.almNvFrentesUpdate();
    }
    // Logística del almacén en el modal: una fila editable por chofer o vehículo. Se manda al
    // guardar SOLO si se terminó de cargar (ALM_NV_LOG_LISTA): guardar una lista que no llegó
    // borraría la del almacén.
    var ALM_NV_LOG_LISTA = false;
    var ALM_NV_LOG = {
        choferes:  { caja: 'almNvLogChoferes',  doc: 'Cédula', max: 30, vacio: 'Sin choferes.' },
        vehiculos: { caja: 'almNvLogVehiculos', doc: 'Placa',  max: 30, vacio: 'Sin vehículos.' }
    };
    function almNvLogFila(tipo, it) {
        var cfg = ALM_NV_LOG[tipo];
        return '<div class="alm-firm-fila alm-log-fila">'
            + '<input type="text" data-campo="nombre" maxlength="150" autocomplete="off" aria-label="' + (tipo === 'choferes' ? 'Nombre del chofer' : 'Vehículo') + '"'
            +   ' placeholder="' + (tipo === 'choferes' ? 'Nombre y apellido' : 'Tipo, marca y modelo') + '" value="' + escHtml(it.nombre || '') + '">'
            + '<input type="text" data-campo="documento" maxlength="' + cfg.max + '" autocomplete="off" aria-label="' + cfg.doc + '" placeholder="' + cfg.doc + '" value="' + escHtml(it.documento || '') + '">'
            + '<button type="button" class="alm-det-quitar" title="Quitar" onclick="window.almNvLogQuitar(this)"><i class="material-icons">close</i></button>'
            + '</div>';
    }
    function almNvLogPintar(tipo, lista) {
        var caja = el(ALM_NV_LOG[tipo].caja); if (!caja) return;
        caja.innerHTML = lista.length ? lista.map(function (it) { return almNvLogFila(tipo, it); }).join('')
            : '<div class="alm-log-vacio">' + ALM_NV_LOG[tipo].vacio + '</div>';
    }
    window.almNvLogAgregar = function (tipo) {
        var caja = el(ALM_NV_LOG[tipo].caja); if (!caja) return;
        var vacio = caja.querySelector('.alm-log-vacio'); if (vacio) vacio.remove();
        caja.insertAdjacentHTML('beforeend', almNvLogFila(tipo, {}));
        caja.lastElementChild.querySelector('input').focus();
    };
    window.almNvLogQuitar = function (btn) {
        var caja = btn.closest('.alm-log-filas');
        btn.closest('.alm-log-fila').remove();
        if (caja && !caja.querySelector('.alm-log-fila')) {
            almNvLogPintar(caja.id === ALM_NV_LOG.choferes.caja ? 'choferes' : 'vehiculos', []);
        }
    };
    function almNvLogLeer(tipo) {
        return Array.prototype.map.call(document.querySelectorAll('#' + ALM_NV_LOG[tipo].caja + ' .alm-log-fila'), function (f) {
            return { nombre: f.querySelector('[data-campo="nombre"]').value.trim(), documento: f.querySelector('[data-campo="documento"]').value.trim() };
        }).filter(function (x) { return x.nombre || x.documento; });
    }
    function almNvLogCargar(id) {
        ALM_NV_LOG_LISTA = false;
        window.apiFetch(ROUTE_ALM_LOGISTICA(id), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || el('almAlmacenModal').dataset.idAlmacen !== String(id)) return;
                // Solo las de la lista del almacén: la flota se sugiere sola y no se edita aquí.
                var propias = function (l) { return (l || []).filter(function (x) { return x.origen === 'almacen'; }); };
                almNvLogPintar('choferes', propias(d.choferes));
                almNvLogPintar('vehiculos', propias(d.vehiculos));
                ALM_NV_LOG_LISTA = true;
            })
            .catch(function () {});
    }
    function almResetAlmacenModal() {
        delete el('almAlmacenModal').dataset.idAlmacen;
        almNvLogPintar('choferes', []); almNvLogPintar('vehiculos', []);
        ALM_NV_LOG_LISTA = true;   // almacén nuevo: su lista es la vacía de arriba
        el('almNvNombre').value = ''; el('almNvUbicacion').value = '';
        if (el('almNvAlmacenista'))      el('almNvAlmacenista').value = '';
        if (el('almNvCargoAlmacenista')) el('almNvCargoAlmacenista').value = '';
        ALM_FIRMANTES_CAMPOS.forEach(function (c) { var e = el(c.id); if (e) e.value = ''; });
        // Almacén nuevo = formato por defecto; cambiarlo es una decisión explícita.
        almNvFormatoSelect(ALM_FORMATO_NOTA_DEF);
        // Reset del filtro de la lista de proyectos del campo Nombre.
        var nvNom = el('almNvNombre'); if (nvNom) window.almNvNombreFilter(nvNom);
        var nvDd  = el('almNvNombreDropdown'); if (nvDd) nvDd.classList.remove('active');
        // Ya deja el Nombre en modo PROYECTO (almNvTipoSelect llama a almNvNombreModo).
        almNvTipoSelect('PROYECTO', 'Proyecto (Limitado a frentes específicos)');
        almNvSetFrentes([]);
        showErr('almNvError', '');
    }
    // Al abrir el modal el cursor va al Nombre, pero SIN desplegar la lista de proyectos:
    // el focusin global de uicomponents.js abre cualquier desplegable al enfocar su campo,
    // y el modal aparecía con la lista ya abierta tapando el formulario. La lista se abre al
    // hacer clic en el campo o al escribir (almNvNombreFilter). En el teléfono no se enfoca:
    // subiría el teclado encima del modal nada más abrirlo.
    function almNvEnfocarNombre() {
        if (!window.matchMedia('(hover: hover)').matches) return;
        var inp = el('almNvNombre'); if (!inp) return;
        inp.focus();
        var dd = el('almNvNombreDropdown'); if (dd) dd.classList.remove('active');
    }
    window.almAbrirAlmacen = function () {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para crear almacenes.')) return;
        almResetAlmacenModal();
        el('almNvTitulo').textContent = 'Nuevo almacén';
        almOpen('almAlmacenModal'); setTimeout(almNvEnfocarNombre, 60);
    };
    window.almEditarAlmacen = function (id) {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para editar almacenes.')) return;
        var d = (window.almAlmacenesData || {})[id]; if (!d) { toast('No se encontró el almacén.', 'error'); return; }
        almResetAlmacenModal();
        el('almAlmacenModal').dataset.idAlmacen = id;
        el('almNvTitulo').textContent = 'Editar almacén';
        el('almNvNombre').value = d.NOMBRE || ''; el('almNvUbicacion').value = d.UBICACION || '';
        if (el('almNvAlmacenista'))      el('almNvAlmacenista').value      = d.ALMACENISTA || '';
        if (el('almNvCargoAlmacenista')) el('almNvCargoAlmacenista').value = d.CARGO_ALMACENISTA || '';
        ALM_FIRMANTES_CAMPOS.forEach(function (c) { var e = el(c.id); if (e) e.value = d[c.col] || ''; });
        // Va DESPUÉS de rellenar los firmantes: es quien decide si el bloque se ve o se oculta.
        almNvFormatoSelect(d.FORMATO_NOTA);
        var tipo = d.TIPO || 'PROYECTO';
        almNvTipoSelect(tipo, tipo === 'GENERAL' ? 'General (almacén central)' : 'Proyecto (Limitado a frentes específicos)');
        almNvSetFrentes(d.frentes || []);
        window.almToggleFrentes();
        almNvLogCargar(id);
        almCerrar('almAdminAlmacenesModal');
        almOpen('almAlmacenModal'); setTimeout(almNvEnfocarNombre, 60);
    };
    window.almGuardarAlmacen = function () {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para guardar almacenes.')) return;
        var m = el('almAlmacenModal');
        if (!m) { toast('Modal no encontrado.', 'error'); return; } // defensa: nunca deberia pasar
        var id = m.dataset.idAlmacen || null;
        var nombre = val('almNvNombre'), tipo = val('almNvTipo') || 'PROYECTO';
        var almacenista = val('almNvAlmacenista');
        var cargo       = val('almNvCargoAlmacenista');
        // Siempre se manda: al editar, omitirlo dejaría el formato como estaba (el backend no
        // lo toca si no viene), y aquí SÍ queremos que mande lo que el usuario ve tildado.
        var formato     = val('almNvFormato') || ALM_FORMATO_NOTA_DEF;
        // Validacion local: mostramos banner + toast + foco. Sin esto el usuario solo
        // veia una linea chiquita al pie del modal y reportaba "el boton no hace nada".
        function _fail(msg, focusId) {
            showErr('almNvError', msg);
            toast(msg, 'error');
            if (focusId) { var inp = el(focusId); if (inp) inp.focus(); }
        }
        if (!nombre)      { _fail('El nombre es obligatorio.',                'almNvNombre');          return; }
        if (!tipo)        { _fail('El tipo es obligatorio.',                  'almNvTipoDisplay');     return; }
        if (!almacenista) { _fail('El nombre de quien ENTREGA es obligatorio.', 'almNvAlmacenista');     return; }
        if (!cargo)       { _fail('El cargo de quien ENTREGA es obligatorio.',  'almNvCargoAlmacenista');return; }
        // Frentes para AMBOS tipos: la asociación define qué usuarios LOCAL ven el
        // almacén (ver Almacen::visiblesPara). Mínimo 1, sea GENERAL o PROYECTO.
        var frentes = [];
        almNvFrenteChecks().forEach(function (c) { if (c.checked) frentes.push(parseInt(c.value, 10)); });
        if (frentes.length === 0) { _fail('Selecciona al menos un frente.', 'almNvFrentesInput'); return; }
        // Firmantes: se mandan SIEMPRE, tildado el formato que esté. Si se mandaran solo con
        // HORIZONTAL, pasar un almacén a Vertical y volver a Horizontal perdería lo escrito en
        // ese guardado intermedio. El backend los acepta en los dos formatos y el vertical
        // simplemente no los imprime.
        var cuerpo = {
            NOMBRE:            nombre,
            TIPO:              tipo,
            UBICACION:         val('almNvUbicacion') || null,
            ALMACENISTA:       val('almNvAlmacenista') || null,
            CARGO_ALMACENISTA: val('almNvCargoAlmacenista') || null,
            FORMATO_NOTA:      formato,
            frentes:           frentes
        };
        ALM_FIRMANTES_CAMPOS.forEach(function (c) { cuerpo[c.col] = val(c.id) || null; });
        if (ALM_NV_LOG_LISTA) {
            var logistica = { choferes: almNvLogLeer('choferes'), vehiculos: almNvLogLeer('vehiculos') };
            var incompleto = logistica.choferes.concat(logistica.vehiculos).filter(function (x) { return !x.nombre || !x.documento; })[0];
            if (incompleto) { _fail('En la logística, cada chofer lleva nombre y cédula y cada vehículo, descripción y placa.'); return; }
            cuerpo.logistica = logistica;
        }

        var url = id ? ROUTE_ALM_ITEM(id) : ROUTE_ALM;
        pre();
        window.apiFetch(url, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify(cuerpo)
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                almCerrar('almAlmacenModal'); toast(res.b.message || (id ? 'Almacén actualizado.' : 'Almacén creado.'));
                var newId = res.b.almacen && (res.b.almacen.ID_ALMACEN || res.b.almacen.id);
                // recargar: cambió la lista del selector / nombres
                setTimeout(function () { window.location = ROUTE_INDEX + ((id || newId) ? ('?id_almacen=' + (id || newId)) : ''); }, 500);
            } else {
                // Error del servidor (validacion 422, conflicto, etc.). Mostramos
                // banner + toast — el toast es la garantia visual de que algo paso.
                var msg = (res.b && res.b.message) || 'No se pudo guardar el almacén.';
                if (res.b && res.b.errors) { msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' '); }
                showErr('almNvError', msg);
                toast(msg, 'error');
            }
        })
        .catch(function () { unpre(); showErr('almNvError', 'Error de red.'); toast('Error de red.', 'error'); });
    };
    window.almAbrirAdminAlmacenes = function () {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para gestionar almacenes.')) return;
        almOpen('almAdminAlmacenesModal');
    };
    window.almEliminarAlmacen = function (id, nombre) {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para eliminar almacenes.')) return;
        // El nombre va escapado: el mensaje se pinta con innerHTML (showModal) y el nombre
        // del almacén es texto libre escrito en este mismo módulo.
        almConfirm('¿Eliminar el almacén "<strong>' + escHtml(String(nombre)) + '</strong>"? Si tiene movimientos registrados se desactivará en lugar de borrarse.', function () {
            pre();
            window.apiFetch(ROUTE_ALM_ITEM(id), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, method: 'DELETE'})
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) {
                unpre();
                if (!res.ok) { toast((res.b && res.b.message) || 'No se pudo eliminar.', 'error'); return; }
                toast(res.b.message || 'Almacén eliminado.');
                // Actualización EN SITIO — sin window.location. Antes se redirigía a
                // ROUTE_INDEX: eso disparaba el spinner de recarga total de la página
                // y, durante el delay de 500ms, se seguía viendo el almacén ya borrado.
                // 1) Quitar la fila del modal "Gestionar almacenes".
                var fila = document.querySelector('#almAdminAlmacenesModal .alm-admin-row[data-id="' + id + '"]');
                if (fila) fila.remove();
                var lista = document.querySelector('#almAdminAlmacenesModal .alm-admin-list');
                if (lista && !lista.querySelector('.alm-admin-row')) {
                    lista.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px 0;">No hay almacenes. Usa "Nuevo almacén" para crear el primero.</p>';
                }
                // 2) Quitar la opción del dropdown "Almacén" del header.
                var opt = document.querySelector('#almSelAlmacenDropdown .dropdown-item[data-value="' + id + '"]');
                if (opt) opt.remove();
                // 3) Si el almacén borrado era el filtro activo, limpiarlo para no
                //    pedirle al backend un id que ya no existe.
                var sel = el('almSelAlmacen');
                if (sel && String(sel.value) === String(id)) sel.value = '';
                // 4) Refrescar la tabla de inventario con el filtro vigente (AJAX).
                if (window.almCargar) window.almCargar();
            })
            .catch(function () { unpre(); toast('Error de red.', 'error'); });
        });
    };

    function almResetProductoModal() {
        delete el('almProductoModal').dataset.idProducto;
        delete el('almProductoModal').dataset.ubicacion;
        el('almProdNombre').value = ''; el('almProdUm').value = 'UND'; el('almProdCategoria').value = '';
        if (el('almProdCantInicial')) el('almProdCantInicial').value = '';
        var cs = el('almProdCatSuggest'); if (cs) cs.innerHTML = '';
        var us = el('almProdUmSuggestBox'); if (us) { us.innerHTML = ''; us.classList.remove('open'); }
        almProdCatHide();
        // Limpiar resaltados de error de todos los campos del modal
        almProdFieldErr('almProdNombre',  false);
        almProdFieldErr('almProdUm',      false);
        showErr('almProdError', '');
        // Reset de la lista de equivalencias (filtros).
        window._almProdEquivs = [];
        var _ei = el('almProdEquivInput'); if (_ei) _ei.value = '';
        almProdEquivRender();
        var _ew = el('almProdEquivWrap'); if (_ew) _ew.style.display = 'none';
    }
    // ── Equivalencias del filtro: lista editable dentro de "Editar producto" ──────────
    // Estado en memoria; se sincroniza al Guardar (updateProducto manda el conjunto completo).
    // Solo FILTROS y solo al EDITAR (para crear, primero se crea el filtro y luego se edita).
    window._almProdEquivs = [];
    function almProdEquivRender() {
        var box = el('almProdEquivList'); if (!box) return;
        if (!window._almProdEquivs.length) {
            box.innerHTML = '<span style="font-size:12px;color:#94a3b8;font-style:italic;">Sin equivalencias aún.</span>';
            return;
        }
        box.innerHTML = window._almProdEquivs.map(function (np, i) {
            var safe = window.escapeHtml(np);   // helper central: antes solo escapaba & y <
            return '<span style="display:inline-flex;align-items:center;gap:6px;background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;border-radius:14px;padding:3px 6px 3px 10px;font-size:12.5px;font-weight:600;">'
                 + safe
                 + '<button type="button" title="Quitar" onclick="window.almProdEquivRemove(' + i + ')" style="border:none;background:#e2e8f0;color:#475569;border-radius:50%;width:18px;height:18px;line-height:1;cursor:pointer;font-weight:700;padding:0;">&times;</button>'
                 + '</span>';
        }).join('');
    }
    window.almProdEquivAdd = function () {
        var inp = el('almProdEquivInput'); if (!inp) return;
        var np = (inp.value || '').trim();
        if (!np) { inp.focus(); return; }
        var norm = function (s) { return String(s).replace(/\s+/g, '').toUpperCase(); }; // sin distinguir may/espacios
        if (window._almProdEquivs.some(function (x) { return norm(x) === norm(np); })) {
            toast('Ese número de parte ya está en la lista.', 'error'); inp.select(); return;
        }
        window._almProdEquivs.push(np);
        almProdEquivRender();
        inp.value = ''; inp.focus();
    };
    window.almProdEquivRemove = function (i) {
        window._almProdEquivs.splice(i, 1);
        almProdEquivRender();
    };
    // Muestra la sección SOLO si se EDITA (idProducto) un producto de categoría FILTROS.
    window.almProdEquivSyncVisible = function () {
        var wrap = el('almProdEquivWrap'); if (!wrap) return;
        var editing = !!el('almProductoModal').dataset.idProducto;
        var esFiltro = esCatFiltro(val('almProdCategoria'));
        wrap.style.display = (editing && esFiltro) ? '' : 'none';
    };
    window.almAbrirProducto = function () {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para crear productos.')) return;
        window.almDesdeDetalle = false;   // "Nuevo producto" NO viene de Detalles → Cancelar no regresa allí
        almResetProductoModal();
        el('almProdIcono').textContent = 'add_circle';
        el('almProdTitulo').textContent = 'Nuevo producto'; el('almProdSubmit').textContent = 'Guardar';
        // Mostrar "Cantidad inicial" solo si hay un almacén seleccionado (el producto se
        // registrará en ese almacén). Si no hay, ocultamos el campo (no tiene sentido).
        var wrap = el('almProdCantInicialWrap');
        if (wrap) wrap.style.display = almSelAlmacenActual() ? '' : 'none';
        almOpen('almProductoModal'); setTimeout(function () { el('almProdNombre').focus(); }, 60);
    };
    window.almEditarProducto = function (id, cod, nom, um, cat, ubicacion) {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para editar productos.')) return;
        almResetProductoModal();
        el('almProductoModal').dataset.idProducto = id;
        // La ubicación ya NO se edita en este modal (se movió a "Detalles del producto"),
        // pero almGuardarProducto la reenvía tal cual para no borrarla al editar otro campo.
        el('almProductoModal').dataset.ubicacion = ubicacion || '';
        // El código no se edita (lo puso el sistema al crear): va en el título, de referencia.
        el('almProdIcono').textContent = 'edit';
        el('almProdTitulo').textContent = 'Editar producto' + (cod ? ' · ' + cod : ''); el('almProdSubmit').textContent = 'Guardar';
        el('almProdNombre').value = nom || ''; el('almProdUm').value = um || 'UND'; el('almProdCategoria').value = cat || '';
        // Cantidad inicial: solo aplica al CREAR. Al editar se oculta — el saldo se cambia
        // desde el modal de Ajuste / Entrada / Salida.
        var wrap = el('almProdCantInicialWrap'); if (wrap) wrap.style.display = 'none';
        // Equivalencias (filtros): se cargan desde la fila (data-equiv) y la sección se muestra
        // solo si el producto es FILTRO. La lista se sincroniza al Guardar.
        var trE = document.querySelector('tr.alm-row[data-id-producto="' + id + '"]');
        window._almProdEquivs = (trE && trE.dataset.equiv) ? trE.dataset.equiv.split('|').filter(Boolean) : [];
        almProdEquivRender();
        window.almProdEquivSyncVisible();
        almOpen('almProductoModal'); setTimeout(function () { el('almProdNombre').focus(); }, 60);
    };

    // ── Papelera de productos (eliminados / soft-delete): buscar + restaurar ──────
    var ROUTE_PROD_PAPELERA = ROUTE_INDEX + '/productos/papelera';
    function ROUTE_PROD_RESTAURAR(id) { return ROUTE_INDEX + '/productos/' + id + '/restaurar'; }
    function ROUTE_PROD_ELIMINAR_PERM(id) { return ROUTE_INDEX + '/productos/' + id + '/permanente'; }
    var _almPapeleraTimer = null;

    window.almAbrirPapelera = function () {
        if (!ensurePerm(HAS_NOTA_ELIMINAR, 'No tienes permiso para ver o restaurar productos eliminados.')) return;
        var inp = el('almPapeleraSearch'); if (inp) inp.value = '';
        almOpen('almPapeleraModal');
        window.almPapeleraBuscar();
        setTimeout(function () { if (inp) inp.focus(); }, 60);
    };

    window.almPapeleraBuscar = function () {
        clearTimeout(_almPapeleraTimer);
        _almPapeleraTimer = setTimeout(function () {
            var cont = el('almPapeleraLista'); if (!cont) return;
            var term = (el('almPapeleraSearch') ? el('almPapeleraSearch').value : '').trim();
            cont.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:13px;padding:24px 0;">Cargando…</div>';
            window.apiFetch(ROUTE_PROD_PAPELERA + (term ? ('?search=' + encodeURIComponent(term)) : ''), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var rows = (data && data.productos) || [];
                if (!rows.length) {
                    cont.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:13px;padding:24px 0;">No hay productos eliminados' + (term ? ' que coincidan.' : '.') + '</div>';
                    return;
                }
                // Misma fila que "Gestionar almacenes" (.alm-admin-row): icono + bloque de
                // texto + botones SOLO icono (.alm-btn), estos apilados en columna. Código y
                // descripción van en la MISMA línea, sin negrita y en cuerpo chico, y la
                // descripción se muestra completa (envuelve en varias líneas si hace falta)
                // — antes se cortaba con puntos suspensivos.
                cont.innerHTML = rows.map(function (p) {
                    // escHtml en TODO: CODIGO/NOMBRE/UM/CATEGORIA son texto libre editable en el
                    // modal de producto. Sin escapar, una categoría tipo "<img src=x onerror=…>"
                    // ejecutaría script al verse en la papelera (XSS almacenado).
                    var cod = escHtml(p.CODIGO ? String(p.CODIGO) : '—');
                    var nom = escHtml(String(p.NOMBRE || ''));
                    var meta = escHtml((p.UM || '') + (p.CATEGORIA ? (' · ' + p.CATEGORIA) : ''));
                    // Código, descripción y unidad/categoría van con el MISMO cuerpo y el MISMO
                    // color: son datos del mismo producto y antes se veían en tres tonos y dos
                    // tamaños distintos (código gris, descripción oscura, meta aún más clara y
                    // pequeña), lo que hacía parecer que la última línea era menos fiable.
                    return '<div class="alm-admin-row">' +
                        '<i class="material-icons" style="font-size:18px;color:#94a3b8;flex:0 0 auto;">inventory_2</i>' +
                        '<div style="flex:1;min-width:0;font-size:12.5px;color:#1e293b;line-height:1.35;">' +
                            '<div>' + cod + ' ' + nom + '</div>' +
                            '<div>' + meta + '</div>' +
                        '</div>' +
                        '<div style="display:flex;flex-direction:column;gap:4px;flex:0 0 auto;">' +
                            '<button type="button" onclick="window.almRestaurarProducto(' + p.ID_PRODUCTO + ')" class="alm-btn alm-btn-restore" title="Restaurar">' +
                                '<i class="material-icons" style="font-size:16px;">restore</i></button>' +
                            // Borrado permanente: solo super.admin (HAS_ALM_MANAGE).
                            (HAS_ALM_MANAGE ? ('<button type="button" onclick="window.almEliminarPermanenteProducto(' + p.ID_PRODUCTO + ')" class="alm-btn alm-btn-del" title="Eliminar de la papelera (permanente)">' +
                                '<i class="material-icons" style="font-size:16px;">delete_forever</i></button>') : '') +
                        '</div>' +
                    '</div>';
                }).join('');
            })
            .catch(function () {
                cont.innerHTML = '<div style="text-align:center;color:#dc2626;font-size:13px;padding:24px 0;">No se pudo cargar la papelera.</div>';
            });
        }, 250);
    };

    window.almRestaurarProducto = function (id) {
        if (!ensurePerm(HAS_NOTA_ELIMINAR, 'No tienes permiso para restaurar productos.')) return;
        pre();
        window.apiFetch(ROUTE_PROD_RESTAURAR(id), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            method: 'POST'})
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                toast(res.b.message || 'Producto restaurado.');
                window.almPapeleraBuscar();
                if (typeof window.almCargar === 'function') window.almCargar();

                if (res.b && res.b.producto && window.almProductosCargados && Array.isArray(window.almProductosLista)) {
                    var p = res.b.producto;
                    var ya = window.almProductosLista.some(function (x) { return String(x.ID_PRODUCTO) === String(p.ID_PRODUCTO); });
                    if (!ya) {
                        // Restaurar no trae los nºs de parte en la respuesta; quedan vacíos hasta el
                        // próximo F5 (la relación sigue en BD). La forma de la entry sí es completa.
                        window.almProductosLista.push(almProdEntry(p));
                        // El catálogo se mutó EN SITIO → la agrupación cacheada por descripción
                        // quedó vieja (ver ProductoSuggest.agrupar).
                        window.ProductoSuggest.invalidar();
                    }
                }
            } else {
                toast((res.b && res.b.message) || 'No se pudo restaurar el producto.', 'error');
            }
        })
        .catch(function () { unpre(); toast('Error de red al restaurar.', 'error'); });
    };

    // Borrado PERMANENTE desde la papelera (forceDelete) — solo super.admin.
    window.almEliminarPermanenteProducto = function (id) {
        if (!ensurePerm(HAS_ALM_MANAGE, 'No tienes permiso para eliminar productos de la papelera.')) return;
        almConfirm('Vas a eliminar este producto <strong>de forma permanente</strong>. No se puede deshacer.', function () {
            pre();
            window.apiFetch(ROUTE_PROD_ELIMINAR_PERM(id), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                method: 'DELETE'})
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) {
                unpre();
                if (res.ok) {
                    toast(res.b.message || 'Producto eliminado permanentemente.');
                    window.almPapeleraBuscar();   // refresca la papelera (ya no aparece)
                } else {
                    toast((res.b && res.b.message) || 'No se pudo eliminar el producto.', 'error');
                }
            })
            .catch(function () { unpre(); toast('Error de red al eliminar.', 'error'); });
        });
    };

    window.almGuardarProducto = function () {
        if (!ensurePerm(HAS_PRODUCTOS, 'No tienes permiso para guardar productos.')) return;
        // Guard de doble envío. NO basta con que el preloader tape la pantalla: el clic de
        // ratón sí lo frena, pero el ENTER no — hay un keydown en document (ver el <script>
        // del final de index.blade.php) que dispara esta función mientras el modal esté
        // abierto, y el preloader no bloquea el teclado. Dos Enter seguidos con la red lenta
        // = dos POST = producto CREADO DOS VECES (el PATCH de edición es idempotente, el
        // alta no). Se suelta en el finally, para poder reintentar si falló.
        if (window._almGuardandoProducto) return;
        window._almGuardandoProducto = true;
        var m = el('almProductoModal'), id = m.dataset.idProducto || null;
        // Sin botón "Agregar": si quedó un nº de parte escrito (sin Enter) en la sección de
        // equivalencias visible, recógelo antes de guardar para no perderlo.
        var _eqW = el('almProdEquivWrap'), _eqI = el('almProdEquivInput');
        if (_eqW && _eqW.style.display !== 'none' && _eqI && _eqI.value.trim() && typeof window.almProdEquivAdd === 'function') {
            window.almProdEquivAdd();
        }
        var nombre = val('almProdNombre'), um = val('almProdUm') || 'UND', cat = val('almProdCategoria');
        // La ubicación ya no se edita aquí (ver "Detalles del producto"): al crear no hay
        // ninguna todavía; al editar viajó en el dataset (almEditarProducto) para no perderla.
        var ubicacion = m.dataset.ubicacion || '';
        // Validaciones previas al envío.
        // Soltar el guard en CADA salida temprana: si no, tras un error de validación el
        // modal se quedaba mudo y había que cerrarlo y volver a abrirlo para poder guardar.
        if (!nombre) {
            window._almGuardandoProducto = false;
            almProdFieldErr('almProdNombre', true);
            showErr('almProdError', 'La descripción es obligatoria.');
            return;
        }
        // Cantidad inicial (solo al CREAR y solo si hay almacén seleccionado).
        var idAlmacen = !id ? almSelAlmacenActual() : '';
        var cantInicial = 0;
        if (!id && idAlmacen) {
            var rawCant = val('almProdCantInicial');
            if (rawCant !== '' && rawCant != null) {
                var nCant = Number(rawCant);
                if (!isFinite(nCant) || nCant < 0) {
                    window._almGuardandoProducto = false;   // ver el guard de arriba
                    showErr('almProdError', 'La cantidad inicial debe ser un número ≥ 0.');
                    return;
                }
                cantInicial = nCant;
            }
        }
        // Limpiar errores visuales antes de enviar
        almProdFieldErr('almProdNombre', false);
        pre();
        var bodyCreate = { NOMBRE: nombre, UM: um, CATEGORIA: cat || null, UBICACION: ubicacion || null };
        if (idAlmacen) {
            bodyCreate.id_almacen      = parseInt(idAlmacen, 10);
            bodyCreate.cantidad_inicial = cantInicial;
        }
        window.apiFetch(id ? ROUTE_PROD_ITEM(id) : ROUTE_PROD, {
            method: id ? 'PATCH' : 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify(id
                // Al editar: sin CODIGO (no se cambia). En FILTROS se manda la lista COMPLETA
                // de equivalencias para sincronizarla en el backend.
                ? Object.assign(
                    { NOMBRE: nombre, UM: um, CATEGORIA: cat || null, UBICACION: ubicacion || null },
                    esCatFiltro(cat) ? { equivalencias: window._almProdEquivs } : {}
                  )
                // Al crear: opcionalmente id_almacen + cantidad_inicial para asegurar/abrir la fila en el almacén actual.
                : bodyCreate
            )
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                almCerrar('almProductoModal');
                toast(res.b.message || (id ? 'Producto actualizado.' : 'Producto creado.'));

                // FILTROS editados: refleja las equivalencias en la fila (data-equiv) para que el
                // modal de Detalles y el tooltip queden consistentes sin recargar. La descripción
                // visible de la tabla se rehace al recargar/filtrar.
                if (id && esCatFiltro(cat)) {
                    var trU = document.querySelector('tr.alm-row[data-id-producto="' + id + '"]');
                    if (trU) trU.dataset.equiv = window._almProdEquivs.join('|');
                }

                // Sincronizar window.almProductosLista (cache en memoria que usa el dropdown
                // de sugerencias) para que el producto nuevo / editado aparezca en la busqueda
                // sin tener que recargar la pestaña. Antes: el producto recien creado solo
                // aparecia tras un F5 porque la lista se cargaba 1 vez al render del server.
                if (res.b && res.b.producto && window.almProductosCargados && Array.isArray(window.almProductosLista)) {
                    var p = res.b.producto;
                    // Filtro editado → sus nºs de parte están en _almProdEquivs (el modal). Para
                    // no-filtros o creación, parts queda vacío. Así la entry cacheada conserva
                    // CATEGORIA y equivalencias y la búsqueda sigue funcionando sin recargar.
                    var equivs = esCatFiltro(p.CATEGORIA) && Array.isArray(window._almProdEquivs)
                        ? window._almProdEquivs : [];
                    var entry = almProdEntry(p, equivs);
                    if (id) {
                        // EDICION: reemplazar la entry existente
                        var idx = window.almProductosLista.findIndex(function (x) {
                            return String(x.ID_PRODUCTO) === String(p.ID_PRODUCTO);
                        });
                        if (idx !== -1) window.almProductosLista[idx] = entry;
                    } else {
                        // CREACION: agregar al final
                        window.almProductosLista.push(entry);
                    }
                    // El catálogo se mutó EN SITIO (no se reemplazó el array) → hay que tirar la
                    // agrupación cacheada por descripción, o el producto nuevo/renombrado se
                    // seguiría agrupando con los datos viejos (ver ProductoSuggest.agrupar).
                    window.ProductoSuggest.invalidar();
                }

                almRecargarMostrando(id || (res.b && res.b.producto && res.b.producto.ID_PRODUCTO));
            }
            else {
                var msg = (res.b && res.b.message) || 'No se pudo guardar el producto.';
                var fieldError = false;
                if (res.b && res.b.errors) {
                    msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                    // Resaltar el campo específico según la clave de error
                    if (res.b.errors.NOMBRE)  { almProdFieldErr('almProdNombre', true);  fieldError = true; }
                    if (res.b.errors.UM)      { almProdFieldErr('almProdUm',     true);  fieldError = true; }
                }
                showErr('almProdError', msg);
            }
        })
        .catch(function () { unpre(); showErr('almProdError', 'Error de red.'); })
        .finally(function () { window._almGuardandoProducto = false; });
    };
    window.almEliminarProducto = function (id) {
        if (!ensurePerm(HAS_NOTA_ELIMINAR, 'No tienes permiso para eliminar productos.')) return;
        almConfirm('¿Eliminar este producto?', function () {
            pre();
            window.apiFetch(ROUTE_PROD_ITEM(id), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, method: 'DELETE'})
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
            .then(function (res) { unpre(); if (res.ok) { toast(res.b.message || 'Producto eliminado.'); almCargar(); } else { toast((res.b && res.b.message) || 'No se pudo eliminar.', 'error'); } })
            .catch(function () { unpre(); toast('Error de red.', 'error'); });
        });
    };
    // Stubs de la rama alternativa fueron removidos: cada funcion CRUD verifica
    // permiso via ensurePerm(...) al inicio y muestra toast si falta. Sin guard
    // duplicado en el bloque.

    if (CFG.puedeMover) {
    // Todo lo que sigue solo tiene sentido con permiso para mover material: su HTML (los
    // modales de salida y de vista previa) tampoco se pinta sin el. Antes este trozo ni
    // siquiera se enviaba al navegador: lo envolvia un condicional de Blade.
    // ── Modal "Registrar salida" unificado ─────────────────────────────────────
    //  Un solo formulario para ambos casos: salida para consumo (mismo almacén) o
    //  salida hacia otro proyecto (TRASPASO). El backend decide qué hacer según el
    //  frente destino — ambos generan Nota de Entrega NE-YYYY-NNNN.
    //  ALM_SAL.idAlmacen = almacén de origen (el que muestra la tabla).
    var ALM_SAL = { idAlmacen: '' };
    // El formulario pide SOLO lo que imprime la hoja del almacén de origen. La nota
    // HORIZONTAL (admin.almacen.nota_entrega_horizontal_pdf) no imprime CONTRATO N° ni
    // RQ N° —son datos de la contratación y del pedido, no del despacho físico que esa
    // hoja controla—, así que esos dos campos se ocultan y las filas se reacomodan para
    // no dejar el hueco. Todo lo demás lo llevan los DOS formatos: Proyecto, Solicitante,
    // Departamento y Observaciones en el cuerpo, y la Fecha —que el horizontal estampa en
    // el sello del cabezote en vez del cuerpo, ver renderNotaEntregaPdfBinary—.
    //
    // El formato se lee de window.almAlmacenesData, que ya viene normalizado por
    // Almacen::formatoNota() (nunca null ni basura). Si el almacén no estuviera en el mapa
    // se cae al formulario completo: pedir de más no rompe ninguna nota, ocultar de menos sí.
    function almSalidaAplicarFormatoNota(idAlmacen) {
        var data = (window.almAlmacenesData || {})[String(idAlmacen || '')];
        var horizontal = !!data && data.FORMATO_NOTA === ALM_FORMATO_NOTA_HORIZONTAL;
        var wrapC = el('almSalidaContratoWrap'); if (wrapC) wrapC.style.display = horizontal ? 'none' : '';
        var wrapR = el('almSalidaRqWrap');       if (wrapR) wrapR.style.display = horizontal ? 'none' : '';
        // Reflow de las dos filas del grid. En mobile el CSS las fuerza a 1fr con
        // !important, así que estos anchos solo mandan en escritorio.
        var g1 = el('almSalidaGridProyecto'); if (g1) g1.style.gridTemplateColumns = horizontal ? '1fr' : '2fr 1fr';
        var g2 = el('almSalidaGridDatos');    if (g2) g2.style.gridTemplateColumns = horizontal ? '1fr 1.4fr' : '1fr 1fr 1.4fr';
    }
    // ── Transporte de la salida: vehículo + placa y chofer + cédula ──
    // Campo del modal → campo que manda el payload (MovimientoInventario::CAMPOS_TRANSPORTE) y
    // lista a la que pertenece. ÚNICO sitio que los empareja: lo usan el reset, el payload y
    // las sugerencias.
    var ALM_LOG_CAMPOS = [
        { id: 'almSalidaVehiculo', campo: 'transporte_vehiculo', lista: 'vehiculos', parte: 'nombre' },
        { id: 'almSalidaPlaca',    campo: 'transporte_placa',    lista: 'vehiculos', parte: 'documento' },
        { id: 'almSalidaChofer',   campo: 'transporte_chofer',   lista: 'choferes',  parte: 'nombre' },
        { id: 'almSalidaCedula',   campo: 'transporte_cedula',   lista: 'choferes',  parte: 'documento' }
    ];
    // Sugerencias del almacén que despacha (las pide cada vez que se abre la salida: una nota
    // registrada recién pudo agregar un chofer). Si llegan tarde de otro almacén, se descartan.
    var ALM_LOG = { idAlmacen: '', choferes: [], vehiculos: [] };
    function almLogCargar(idAlmacen) {
        ALM_LOG = { idAlmacen: String(idAlmacen || ''), choferes: [], vehiculos: [] };
        if (!idAlmacen) return;
        window.apiFetch(ROUTE_ALM_LOGISTICA(idAlmacen), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d || ALM_LOG.idAlmacen !== String(idAlmacen)) return;
                ALM_LOG.choferes = d.choferes || []; ALM_LOG.vehiculos = d.vehiculos || [];
            })
            .catch(function () { /* sin sugerencias el transporte se escribe a mano */ });
    }
    function almLogOcultar() {
        ['almSalidaVehiculosSug', 'almSalidaChoferesSug'].forEach(function (id) { var b = el(id); if (b) b.classList.remove('open'); });
    }
    // Lista de su fila, filtrada por lo escrito. Vehículos: se busca ESCRIBIENDO la placa, el
    // serial de chasis o el nombre, y la lista no se abre entera al entrar (eran decenas para
    // elegir a ojo); cada renglón va con placa, serial de chasis y tipo (almLogRenglonVehiculo). Choferes: al entrar
    // a un campo vacío se ve la lista entera (son pocos). Sin nada que sugerir no se abre: el
    // campo sigue siendo libre.
    // Renglón de un vehículo: placa, serial de chasis y tipo, sin marca ni modelo (pedido del
    // cliente). El que no tiene placa de verdad trae el serial como documento, así que no se repite.
    // Los de la lista del almacén traen serial y tipo si también están en la flota; si no, se ven
    // con el nombre con que se guardaron (no hay tipo del que tirar).
    function almLogRenglonVehiculo(it) {
        var serial = it.serial || '', placa = serial && it.documento === serial ? '' : (it.documento || '');
        return '<span class="alm-log-doc' + (placa ? '' : ' sin-placa') + '">' + escHtml(placa || 'SIN PLACA') + '</span>'
            + (serial ? '<span class="alm-log-serial">S/C ' + escHtml(serial) + '</span>' : '')
            + '<span class="alm-log-tipo">' + escHtml(it.tipo || (it.origen === 'flota' ? '' : (it.nombre || ''))) + '</span>';
    }
    window.almLogSugerir = function (inp, alEntrar) {
        var tipo = inp.getAttribute('data-log'), lista = ALM_LOG[tipo] || [];
        var box = el(tipo === 'choferes' ? 'almSalidaChoferesSug' : 'almSalidaVehiculosSug');
        if (!box) return;
        almLogOcultar();
        var veh = tipo === 'vehiculos';
        var term = almNorm(inp.value.trim());
        if (!lista.length || (veh && !term)) return;
        var html = '', grupo = '', n = 0;
        lista.forEach(function (it, i) {
            var serial = it.serial || '';
            if (n >= 80 || !(alEntrar && !term || almNorm(it.nombre + ' ' + it.documento + ' ' + serial).indexOf(term) > -1)) return;
            var g = it.origen === 'flota' ? 'Flota de los frentes' : 'Logística del almacén';
            if (g !== grupo) { html += '<div class="alm-log-grupo">' + g + '</div>'; grupo = g; }
            html += '<div class="si-item alm-log-item' + (veh ? ' veh' : '') + '" data-log="' + tipo + '" data-i="' + i + '">'
                + (veh ? almLogRenglonVehiculo(it)
                       : '<span class="alm-log-nom">' + escHtml(it.nombre) + '</span><span class="alm-log-doc">' + escHtml(it.documento) + '</span>')
                + '</div>';
            n++;
        });
        // Debajo del campo que se escribe y con su ancho: colgada de la fila cruzaba el modal
        // entero y en el teléfono la del chofer salía debajo de la cédula. Si el modal tiene sitio,
        // la lista no baja de un ancho legible (chofer y cédula; el vehículo en una sola línea).
        box.dataset.ancla = inp.id;
        box.dataset.anchoMin = veh ? '420' : '260';
        almSuggestApply(box, html, '<div class="alm-suggest-empty">Sin coincidencias: se imprime lo que escribas.</div>');
    };
    // Elegir uno llena los dos campos de su fila (nombre y documento).
    document.addEventListener('click', function (e) {
        var item = e.target.closest('.alm-log-item');
        if (item) {
            e.preventDefault();
            var tipo = item.getAttribute('data-log'), it = (ALM_LOG[tipo] || [])[parseInt(item.getAttribute('data-i'), 10)];
            if (it) ALM_LOG_CAMPOS.forEach(function (c) { if (c.lista === tipo) el(c.id).value = it[c.parte] || ''; });
            almLogOcultar();
            return;
        }
        if (!e.target.closest('[data-log]') && !e.target.closest('#almSalidaVehiculosSug, #almSalidaChoferesSug')) almLogOcultar();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && e.target.closest && e.target.closest('[data-log]')) almLogOcultar();
    });

    window.almAbrirSalidaModal = function (idAlmacen) {
        ALM_SAL = { idAlmacen: String(idAlmacen || '') };
        // Antes de mostrar nada: el almacén de origen decide qué campos se piden. Va aquí
        // (y no una sola vez al cargar la página) porque el usuario puede cambiar de almacén
        // sin recargar — el modal es el mismo nodo para todos.
        almSalidaAplicarFormatoNota(ALM_SAL.idAlmacen);
        // Limpiar campos de Nota de Entrega y poner FECHA = hoy por default.
        ['almSalidaContrato','almSalidaRq','almSalidaSolicitante','almSalidaDepartamento','almSalidaMotivo'].forEach(function (id) { var e = el(id); if (e) e.value = ''; });
        // La salida viene de un kit: su nombre, cuántos y el equipo quedan en Observaciones
        // (el campo que imprime la Nota), para que conste de dónde salió el material.
        if (almKitMotivo) { var mo = el('almSalidaMotivo'); if (mo) mo.value = almKitMotivo; }
        ALM_LOG_CAMPOS.forEach(function (c) { var e = el(c.id); if (e) e.value = ''; });
        almLogCargar(ALM_SAL.idAlmacen);
        // El campo Proyecto es un custom-dropdown: lo reseteamos con su helper para que
        // el placeholder vuelva al default y el hidden #almSalidaProyecto quede vacío.
        if (typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter('almSalidaProyectoDropdown');
        }
        var fe = el('almSalidaFecha'); if (fe) fe.value = new Date().toISOString().slice(0, 10);
        // Reset del dropdown de contratos: vaciar items, cerrar el panel, ocultar el clear-btn.
        // La lista se rellena cuando el usuario elige proyecto destino.
        var citems = el('almSalidaContratoItems'); if (citems) citems.innerHTML = '';
        var cdd    = el('almSalidaContratoDropdown'); if (cdd) cdd.classList.remove('active');
        var cbtn   = el('almSalidaContratoClearBtn'); if (cbtn) cbtn.style.display = 'none';
        showErr('almSalidaError', '');
        // Asegurar que el dropdown de Proyecto NO quede abierto si una sesion previa lo
        // dejo con .active (el helper global focusin auto-abre cuando el input del trigger
        // recibe foco — por eso evitamos hacer .focus() automatico al abrir el modal).
        var ddProy = el('almSalidaProyectoDropdown');
        if (ddProy) ddProy.classList.remove('active');
        // "Almacén destino" arranca oculto y vacío: se decide al elegir proyecto. Sin este
        // reset, reabrir el modal desde OTRO almacén conservaría el destino del anterior.
        if (typeof window.almSalidaSyncAlmacenDestino === 'function') {
            window.almSalidaSyncAlmacenDestino();
        }
        var ddDest = el('almSalidaAlmacenDestinoDropdown');
        if (ddDest) ddDest.classList.remove('active');
        almOpen('almSalidaModal');
    };
    // Campo "Contrato N°" del modal Registrar salida — es un custom-dropdown (mismo
    // componente que Proyecto) con UNA particularidad: el input es libre. El usuario
    // puede (a) elegir un contrato de la lista, (b) escribir uno nuevo que no este en
    // la lista, o (c) dejarlo en blanco (es opcional). La fuente de la verdad para el
    // payload es SIEMPRE el .value del input visible #almSalidaContrato — no hay hidden.
    //
    // Lista de items: se rebuilds desde window.almFrenteContratos[idFrente] cada vez que
    // cambia el Proyecto destino. La apertura/cierre del panel la maneja el sistema global
    // de custom-dropdown (uicomponents.js) — no duplicamos esa logica aqui.
    function almSalidaContratoSync() {
        // Mostrar/ocultar el boton "x" de limpiar segun haya texto en el input.
        var inp = el('almSalidaContrato');
        var btn = el('almSalidaContratoClearBtn');
        if (!inp || !btn) return;
        btn.style.display = (inp.value || '').trim() ? 'block' : 'none';
    }
    function almSalidaContratoBuildList(idFrente) {
        var box = el('almSalidaContratoItems');
        if (!box) return;
        var list = ((window.almFrenteContratos || {})[idFrente] || []);
        if (!list.length) {
            // Mensaje informativo dentro del propio panel del dropdown — no rompe layout
            // (el panel flota absolutamente, no empuja el modal hacia abajo).
            box.innerHTML = '<div style="padding:8px 12px;font-size:12px;color:#94a3b8;font-style:italic;">Sin contratos previos — puedes escribirlo.</div>';
            return;
        }
        // dropdown-item es la misma clase que usa Proyecto — hereda el hover/selected del
        // sistema global. El click llama almSalidaContratoPick (no selectOption, porque NO
        // queremos que el sistema toque hidden/placeholder/clear-btn — eso lo manejamos aqui).
        box.innerHTML = list.map(function (c) {
            var safe = escHtml(c);
            // El argumento del onclick va por escapeAttrJs (helper central). Antes se
            // escapaban las comillas simples SOBRE el texto ya pasado por escHtml, que las
            // había convertido en &#39;: el replace no encontraba ninguna, el navegador las
            // decodificaba a ' al evaluar el atributo, y un contrato con apóstrofo reventaba
            // con SyntaxError — dejaba de ser clickeable.
            var jsArg = window.escapeAttrJs(String(c));
            return '<div class="dropdown-item" data-value="' + safe + '" onclick="window.almSalidaContratoPick(\'' + jsArg + '\')">' + safe + '</div>';
        }).join('');
    }
    window.almSalidaContratoFilter = function (input) {
        // Filtro local de items por lo que el usuario teclea — mismo comportamiento que
        // filterDropdownOptions del sistema global, pero contenido al item-list de contrato.
        // Reimplementamos en lugar de delegar para evitar acoplarse a data-filter-type/value
        // (este dropdown no usa hidden + label, es input libre).
        var box = el('almSalidaContratoItems');
        if (!box) return;
        var norm = function (s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
        var term = norm(input.value);
        box.querySelectorAll('.dropdown-item').forEach(function (item) {
            var show = !term || norm(item.textContent).indexOf(term) !== -1;
            item.style.setProperty('display', show ? 'block' : 'none', 'important');
        });
        almSalidaContratoSync();
    };
    window.almSalidaContratoPick = function (c) {
        var inp = el('almSalidaContrato');
        if (inp) inp.value = c || '';
        // Re-mostrar TODOS los items para la proxima apertura (el filtro previo pudo ocultar varios).
        var box = el('almSalidaContratoItems');
        if (box) box.querySelectorAll('.dropdown-item').forEach(function (item) { item.style.removeProperty('display'); });
        var dd = el('almSalidaContratoDropdown');
        if (dd) dd.classList.remove('active');
        almSalidaContratoSync();
    };
    window.almSalidaContratoClear = function () {
        var inp = el('almSalidaContrato'); if (inp) inp.value = '';
        var box = el('almSalidaContratoItems');
        if (box) box.querySelectorAll('.dropdown-item').forEach(function (item) { item.style.removeProperty('display'); });
        almSalidaContratoSync();
        if (inp) inp.focus();
    };

    // Mapa frente → almacenes PROYECTO, del backend (ver AlmacenController::index).
    var ALM_POR_FRENTE = CFG.almacenesPorFrente;

    // Almacenes a los que PUEDE ir el material del proyecto elegido: los del frente
    // menos el de origen (mandarse material a uno mismo no es un traspaso).
    function almSalidaDestinosDe(idFrente) {
        var lista = ALM_POR_FRENTE[idFrente] || [];
        // ALM_SAL.idAlmacen es TEXTO y a.id viene numérico del JSON: sin igualar el tipo,
        // el almacén de origen nunca se descartaría y saldría ofrecido como destino.
        var origen = parseInt(ALM_SAL.idAlmacen, 10);
        return lista.filter(function (a) { return a.id !== origen; });
    }

    // Enseña u oculta "Almacén destino" según el proyecto elegido. Con 0 o 1 destino no
    // hay nada que preguntar (0 = consumo en el propio almacén, 1 = lo deduce el backend).
    window.almSalidaSyncAlmacenDestino = function () {
        var wrap = el('almSalidaDestinoWrap');
        var caja = el('almSalidaAlmacenDestinoItems');
        var sel  = el('almSalidaProyecto');
        if (!wrap || !caja || !sel) return;

        var destinos = almSalidaDestinosDe(sel.value);

        // Se limpia SIEMPRE al cambiar de proyecto: si no, una elección del proyecto
        // anterior viajaría en el payload del siguiente y el backend la rechazaría por
        // no pertenecer al frente.
        window.clearDropdownFilter('almSalidaAlmacenDestinoDropdown');

        if (destinos.length < 2) {
            wrap.style.display = 'none';
            caja.innerHTML = '';
            return;
        }

        caja.innerHTML = destinos.map(function (a) {
            // Los dos por los helpers centrales: el nombre del almacén es texto libre que se
            // escribe en este mismo módulo. Escapando solo la comilla simple, un almacén con
            // comilla DOBLE cerraba el atributo onclick y rompía el desplegable entero.
            var nombre = escHtml(String(a.nombre));
            var jsArg  = window.escapeAttrJs(String(a.nombre));
            return '<div class="dropdown-item" data-value="' + a.id + '"' +
                   ' onclick="selectOption(\'almSalidaAlmacenDestinoDropdown\',\'' + a.id + '\',\'' +
                   jsArg + '\');">' + nombre + '</div>';
        }).join('');
        wrap.style.display = '';
    };

    window.almSalidaOnProyectoChange = function () {
        var sel  = el('almSalidaProyecto');
        if (!sel) return;
        almSalidaContratoBuildList(sel.value);
        window.almSalidaSyncAlmacenDestino();
        // NO auto-abrimos el panel: el campo Contrato N° es opcional y abrirlo
        // automaticamente al elegir proyecto resultaba intrusivo. El usuario decide
        // cuando ver la lista haciendo clic en el trigger o en el input.
    };
    // Payload de la salida congelado al apretar "Vista previa". Lo reusamos en
    // "Registrar" del preview para que el PDF final corresponda EXACTAMENTE al que
    // el usuario revisó (si edita despues, se regenera al apretar "Vista previa"
    // de nuevo). Tambien guardamos el blob URL del preview para revocarlo al
    // cerrar el modal y no acumular memoria.
    var almSalidaDraft   = null;
    var almPreviewBlobUrl = null;
    // Texto del aviso de saldo prestado de la ÚLTIMA vista previa (cabecera X-Salida-Aviso).
    // Se guarda entre el fetch y el pintado del modal, y se limpia en cada previsualización.
    var almPreviewAvisoTexto = '';

    // Construye el payload desde los campos del modal + almSeleccion. Devuelve
    // null y muestra error si falta el frente destino o no hay lineas validas.
    // Lo usan almSalidaVistaPrevia (POST a preview) y almPreviewConfirmar (POST
    // a lote real) — separado para garantizar consistencia: el PDF preview y el
    // registro final se generan a partir del MISMO payload.
    function almSalidaConstruirPayload() {
        var v = function (id) { var e = el(id); return e ? e.value.trim() : ''; };
        var idFrenteDest = v('almSalidaProyecto');
        if (!idFrenteDest) { showErr('almSalidaError', 'Elige el proyecto / frente destino.'); return null; }
        // Mismo criterio que la validación previa a abrir el modal (ver almSelAccion):
        // "sin cantidad + sin saldo" se reporta como stock insuficiente, no como un olvido.
        var lineas = [], faltan = [], sinSaldo = [];
        Object.keys(almSeleccion).forEach(function (id) {
            var s   = almSeleccion[id] || {};
            var raw = String(s.cantidad == null ? '' : s.cantidad).replace(',', '.').trim();
            var c   = parseFloat(raw);
            var nombre = s.nombre || ('#' + id);
            if (!isFinite(c) || c <= 0) ((parseFloat(s.saldo) || 0) <= 0 ? sinSaldo : faltan).push(nombre);
            else lineas.push({
                id_producto:  parseInt(id, 10),
                cantidad:     c,
                numero_parte: s.parte || null,
                // Proyecto elegido en el modal «¿De qué proyecto sale?». null = sin elección (la bolsa
                // del destino). Se compara con '' y no por truthy: 0 es la bolsa común, una elección válida.
                id_frente_saldo: (s.bolsa === '' || s.bolsa == null) ? null : parseInt(s.bolsa, 10),
            });
        });
        var listar = function (arr) { return arr.slice(0, 4).join(', ') + (arr.length > 4 ? '…' : ''); };
        if (sinSaldo.length) { showErr('almSalidaError', 'Stock insuficiente de: ' + listar(sinSaldo) + '.'); return null; }
        if (!lineas.length) { showErr('almSalidaError', 'Indica una cantidad mayor que cero en al menos un producto (columna "Salida" de la tabla).'); return null; }
        if (faltan.length)  { showErr('almSalidaError', 'Falta indicar la cantidad de salida (debe ser mayor que cero) en: ' + listar(faltan) + '. Corrígelos en la tabla o deselecciónalos.'); return null; }
        showErr('almSalidaError', '');

        // Único endpoint: registrarMovimientoLote tipo=SALIDA + id_frente_destino.
        // El backend decide internamente:
        //   - Si el frente destino comparte el almacén origen → SALIDA pura (consumo).
        //   - Si el frente destino tiene OTRO almacén → crea un Traspaso + envía + asigna
        //     NUMERO_NOTA. En ambos casos se devuelve nota_url con el PDF.
        var payload = {
            tipo:               'SALIDA',
            id_almacen:         ALM_SAL.idAlmacen,
            id_frente_destino:  parseInt(idFrenteDest, 10),
            id_frente:          parseInt(idFrenteDest, 10), // back-compat: SALIDA mismo-almacén usa id_frente
            lineas:             lineas,
        };

        // Almacén destino: solo viaja cuando el campo está visible, o sea cuando el
        // proyecto se maneja en varios almacenes y hay que decir a cuál va. Con uno solo
        // lo deduce el backend y mandarlo sería ruido.
        var wrapDest = el('almSalidaDestinoWrap');
        if (wrapDest && wrapDest.style.display !== 'none') {
            var idDest = v('almSalidaAlmacenDestino');
            if (!idDest) { showErr('almSalidaError', 'Este proyecto se maneja en varios almacenes: elige el almacén destino.'); return null; }
            payload.id_almacen_destino = parseInt(idDest, 10);
        }
        var fecha  = v('almSalidaFecha');         if (fecha)  payload.fecha = fecha;
        var contr  = v('almSalidaContrato');      if (contr)  payload.numero_contrato = contr;
        var rqN    = v('almSalidaRq');            if (rqN)    payload.numero_rq = rqN;
        var solic  = v('almSalidaSolicitante');   if (solic)  payload.solicitante = solic;
        var depto  = v('almSalidaDepartamento');  if (depto)  payload.departamento = depto;
        var motivo = v('almSalidaMotivo');        if (motivo) payload.motivo = motivo;
        ALM_LOG_CAMPOS.forEach(function (c) { var t = v(c.id); if (t) payload[c.campo] = t; });
        return payload;
    }

    // Vista previa: POSTea al endpoint /salida/preview-pdf (NO commitea nada),
    // recibe el binario del PDF y lo carga en el iframe del modal #almPreviewModal.
    // Guarda el payload en almSalidaDraft para que "Registrar" del preview lo
    // reuse exactamente igual.
    window.almSalidaVistaPrevia = function () {
        var payload = almSalidaConstruirPayload();
        if (!payload) return;
        almSalidaDraft = payload;

        pre();
        window.apiFetch(ROUTE_PREVIEW_SALIDA, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json', 'Accept': 'application/pdf, application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) {
            // Aviso de saldo prestado (cabecera, no cuerpo: el cuerpo es el PDF). Viene
            // rawurlencode-ado porque una cabecera HTTP no admite acentos.
            almPreviewAvisoTexto = '';
            var av = r.headers.get('X-Salida-Aviso');
            if (av) { try { almPreviewAvisoTexto = decodeURIComponent(av); } catch (e) { almPreviewAvisoTexto = av; } }
            // El backend devuelve PDF binario en exito, JSON con {message} en error.
            var ct = r.headers.get('Content-Type') || '';
            if (!r.ok) {
                return r.json().then(function (b) { throw new Error(b.message || 'No se pudo generar la vista previa.'); });
            }
            if (ct.indexOf('application/pdf') === -1) {
                throw new Error('Respuesta inesperada del servidor (no es PDF).');
            }
            return r.blob();
        })
        .then(function (blob) {
            unpre();
            // Revocar blob URL viejo (sesion anterior) antes de crear uno nuevo
            // para no filtrar memoria si el usuario reabre el preview varias veces.
            if (almPreviewBlobUrl) { try { URL.revokeObjectURL(almPreviewBlobUrl); } catch (e) {} }
            almPreviewBlobUrl = URL.createObjectURL(blob);
            var frame = el('almPreviewFrame');
            var cont  = el('almPreviewCanvas');
            // Ocultar el modal de salida (sin destruir sus inputs — almCerrar solo
            // quita .open, los valores quedan listos para "Editar") y abrir el preview
            // ANTES de renderizar, para que el canvas mida bien el ancho disponible.
            almCerrar('almSalidaModal');
            var avisoBox = el('almPreviewAviso');
            if (avisoBox) {
                avisoBox.innerHTML = almPreviewAvisoTexto
                    ? '<i class="material-icons" style="font-size:18px;color:#b45309;flex-shrink:0;">info</i>'
                      + '<span><b>Saldo de otros proyectos:</b> ' + window.escapeHtml(almPreviewAvisoTexto) + '</span>'
                    : '';
                avisoBox.style.display = almPreviewAvisoTexto ? 'flex' : 'none';
            }
            almOpen('almPreviewModal');
            if (window.pdfEsMovil()) {
                // TELÉFONO: el iframe no muestra PDF → lo dibujamos con PDF.js en canvas.
                if (frame) { frame.src = 'about:blank'; frame.style.display = 'none'; }
                if (cont)  { cont.style.display = 'block'; }
                window.pintarPdfEnCanvas(el('almPreviewCanvas'), blob);   // dom_helpers.js
            } else {
                // ESCRITORIO: visor nativo del navegador en el iframe. Mostramos "Cargando…"
                // en el contenedor del canvas (oculto en escritorio) HASTA que el iframe dispare
                // load — antes el spinner global se apagaba al llegar el blob y el iframe quedaba
                // en blanco unos instantes mientras el navegador renderiza el PDF (mismo criterio
                // que el visor del acta). Fallback a 8s por si onload no dispara.
                if (cont) {
                    cont.style.display = 'flex';
                    cont.style.alignItems = 'center';
                    cont.style.justifyContent = 'center';
                    cont.style.color = '#cbd5e0';
                    cont.innerHTML = '<div style="text-align:center;font-size:13px;"><i class="material-icons" style="font-size:34px;animation:spin 1s linear infinite;display:block;margin:0 auto 8px;">sync</i>Cargando vista previa…</div>';
                }
                if (frame) {
                    frame.style.display = 'none';
                    frame.onload = function () {
                        if ((frame.src || '').indexOf('about:blank') !== -1) return;
                        if (cont) { cont.style.display = 'none'; cont.innerHTML = ''; }
                        frame.style.display = '';
                    };
                    frame.src = almPreviewBlobUrl + '#toolbar=0&navpanes=0&scrollbar=0&view=FitH';
                    setTimeout(function () {
                        if (cont && cont.style.display !== 'none') { cont.style.display = 'none'; cont.innerHTML = ''; }
                        frame.style.display = '';
                    }, 8000);
                }
            }
        })
        .catch(function (err) {
            unpre();
            showErr('almSalidaError', err.message || 'Error generando vista previa.');
        });
    };

    // "Editar" del modal preview: vuelve al modal de salida con todos los datos
    // intactos (los inputs no se destruyen, solo se ocultan via .open). El usuario
    // puede cambiar campos del formulario o salir, modificar la seleccion en la
    // tabla, y volver a apretar "Vista previa" — se regenera el PDF.
    window.almPreviewEditar = function () {
        almCerrar('almPreviewModal');
        almOpen('almSalidaModal');
    };

    // Cerrar preview con la X: equivalente a "Editar" — vuelve al modal de salida
    // con los datos preservados, asi el usuario decide si cancela todo (boton
    // Cancelar del modal de salida) o continua. Limpia el blob URL para no
    // acumular memoria, pero NO descarta el draft (lo descarta almAbrirSalidaModal
    // cuando se reabre el modal con un id distinto).
    window.almPreviewCerrar = function () {
        almCerrar('almPreviewModal');
        if (almPreviewBlobUrl) { try { URL.revokeObjectURL(almPreviewBlobUrl); } catch (e) {} almPreviewBlobUrl = null; }
        var frame = el('almPreviewFrame'); if (frame) frame.src = 'about:blank';
        var cont = el('almPreviewCanvas'); if (cont) { cont.innerHTML = ''; cont.style.display = 'none'; }
        almOpen('almSalidaModal');
    };

    // "Registrar" del preview: POSTea el draft al endpoint real (movimientos-lote)
    // que SI guarda en BD, asigna NUMERO_NOTA y devuelve la URL del PDF final.
    // Tras el commit, descargamos el PDF al disco (fetch → blob → anchor) y
    // dejamos al usuario en el modulo de inventario — NO abrimos visor in-page
    // (el flujo ya pidio aprobacion en el modal #almPreviewModal). El payload
    // viene del draft sin reconstruir, asi el PDF final = exactamente lo aprobado.
    // ── Departamento: lo que cada quien usa ───────────────────────────────────
    // Vive en el NAVEGADOR de cada usuario (localStorage), no en la base: es la costumbre de
    // quien despacha, no un catálogo de la empresa. Por eso la clave lleva su id — si dos
    // personas comparten el equipo, cada una ve lo suyo.
    //
    // Solo se recuerda lo que llegó a una nota REGISTRADA: escribir algo y arrepentirse no
    // deja rastro. Y un valor usado UNA sola vez se descarta a los DEPTO_OLVIDO_DIAS: así un
    // dedazo ("Mantenimeinto") deja de sugerirse solo, mientras que el que se repite se queda.
    var DEPTO_CLAVE        = 'alm_deptos_u' + CFG.idUsuario;
    var DEPTO_TOPE         = 8;     // cuántos se ofrecen, de más usado a menos
    var DEPTO_OLVIDO_DIAS  = 60;

    function almDeptoLeer() {
        try { return JSON.parse(localStorage.getItem(DEPTO_CLAVE)) || {}; } catch (e) { return {}; }
    }

    /** Ordenados por uso (y, a igualdad, por el más reciente), ya sin los olvidados. */
    function almDeptoLista() {
        var datos = almDeptoLeer();
        var corte = Date.now() - DEPTO_OLVIDO_DIAS * 86400000;
        return Object.keys(datos)
            .filter(function (k) { return datos[k].n > 1 || datos[k].t > corte; })
            .sort(function (a, b) { return (datos[b].n - datos[a].n) || (datos[b].t - datos[a].t); })
            .slice(0, DEPTO_TOPE);
    }

    function almDeptoPintar() {
        var lista = el('almSalidaDeptoLista');
        if (!lista) return;
        var esc = window.escapeHtml || function (x) { return String(x); };
        lista.innerHTML = almDeptoLista().map(function (d) {
            return '<option value="' + esc(d) + '"></option>';
        }).join('');
    }

    /** Lo llama SOLO el registro de la salida, nunca el tecleo. */
    function almDeptoRecordar(valor) {
        var v = String(valor || '').replace(/\s+/g, ' ').trim();
        if (v.length < 3) return;                     // ni vacío ni dos letras sueltas
        try {
            var datos = almDeptoLeer();
            var corte = Date.now() - DEPTO_OLVIDO_DIAS * 86400000;
            // Se limpia al escribir, no en un barrido aparte: así no crece sin fin.
            Object.keys(datos).forEach(function (k) {
                if (datos[k].n <= 1 && datos[k].t <= corte) delete datos[k];
            });
            datos[v] = { n: ((datos[v] && datos[v].n) || 0) + 1, t: Date.now() };
            localStorage.setItem(DEPTO_CLAVE, JSON.stringify(datos));
        } catch (e) { /* sin localStorage (modo privado): el campo sigue funcionando igual */ }
        almDeptoPintar();
    }

    almDeptoPintar();

    window.almPreviewConfirmar = function () {
        if (!almSalidaDraft) { toast('Sin datos para registrar — vuelve a "Editar" y aprieta "Vista previa".', 'error'); return; }
        // Anti doble-submit: si ya hay un registro en curso, no dispares otro (evita movimiento
        // + Nota de Entrega DUPLICADOS). Antes solo lo tapaba el preloader; ahora es explícito,
        // igual que "Registrar entrada" de recepción. Se libera en el .finally.
        if (window._almConfirmando) return;
        window._almConfirmando = true;
        var payload = almSalidaDraft;

        pre();
        window.apiFetch(ROUTE_LOTE, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest',  'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, b: b }; }); })
        .then(function (res) {
            unpre();
            if (res.ok) {
                // Exito: cerramos ambos modales, limpiamos seleccion y el draft, recargamos
                // la tabla y DESCARGAMOS el PDF al disco (NO abrimos el visor in-page —
                // el usuario ya aprobo la vista previa, ese segundo visor sobra).
                almCerrar('almPreviewModal');
                almCerrar('almSalidaModal');
                if (almPreviewBlobUrl) { try { URL.revokeObjectURL(almPreviewBlobUrl); } catch (e) {} almPreviewBlobUrl = null; }
                var frame0 = el('almPreviewFrame'); if (frame0) frame0.src = 'about:blank';
                // El departamento se recuerda AQUI: la nota quedó registrada, así que ese
                // texto es un departamento de verdad y no un borrador a medias.
                almDeptoRecordar(almSalidaDraft && almSalidaDraft.departamento);
                almSalidaDraft = null;
                if (window.almSelClear) window.almSelClear();
                toast(res.b.message || 'Movimiento registrado.');
                almCargar();
                if (res.b && res.b.nota_url) {
                    // fetch → blob → anchor con download: garantiza que el navegador SIEMPRE
                    // guarde como archivo, sin importar Content-Disposition (el endpoint manda
                    // 'inline'). Si usaramos solo `<a href download>`, algunos navegadores
                    // navegan a la URL en la misma pestaña y el usuario "pierde" la pagina
                    // del inventario — con blob URL eso no ocurre nunca.
                    var dlName = res.b.numero_nota ? ('Nota_' + res.b.numero_nota + '.pdf') : 'nota_entrega.pdf';
                    window.apiFetch(res.b.nota_url, { headers: { 'X-Requested-With': 'XMLHttpRequest',  'Accept': 'application/pdf'}})
                        .then(function (rr) { return rr.ok ? rr.blob() : null; })
                        .then(function (blob) {
                            if (!blob) return;
                            var burl = URL.createObjectURL(blob);
                            var a = document.createElement('a');
                            a.href = burl; a.download = dlName; a.style.display = 'none';
                            document.body.appendChild(a); a.click(); document.body.removeChild(a);
                            setTimeout(function () { try { URL.revokeObjectURL(burl); } catch (e) {} }, 2000);
                        })
                        .catch(function () { /* silencioso: el movimiento ya se registro, la descarga es secundaria */ });
                }
            } else {
                // Error tardio (algo cambio entre el preview y el confirm: stock se
                // movio, validacion fallo, etc.). Cerramos preview y reabrimos salida
                // con el mensaje. Mostramos el error EN DOS LADOS:
                //   1) toast — visible incluso si el usuario cierra el modal de salida
                //      sin leer (clave para no creer que se registro cuando no fue asi).
                //   2) inline (almSalidaError) — contexto al pie del modal de salida.
                var msg = (res.b && res.b.message) || 'No se pudo registrar el movimiento.';
                if (res.b && res.b.errors) msg = Object.values(res.b.errors).map(function (a) { return a.join(' '); }).join(' ');
                window.almPreviewCerrar(); // tambien reabre salida
                showErr('almSalidaError', msg);
                toast(msg, 'error');
            }
        })
        .catch(function () {
            unpre();
            window.almPreviewCerrar();
            var netMsg = 'Error de red al confirmar la salida.';
            showErr('almSalidaError', netMsg);
            toast(netMsg, 'error');
        })
        .finally(function () { window._almConfirmando = false; }); // libera la guarda anti doble-submit
    };
    }   // fin de CFG.puedeMover

    // La tabla ya viene pintada con los últimos productos movidos (el servidor los manda en
    // la carga inicial). Si la URL trae un filtro de contenido (search / categoria / um /
    // id_producto), se recarga al entrar para aplicarlo; si no, se queda con esa vista sin
    // pedir nada más. Con el patron placeholder-background, value="" siempre — leemos del
    // data-active. Incluir almBuscarPickedId garantiza que un link directo del tipo
    // ?id_producto=NNN dispare la carga y pinte el sidebar cruzado "En otros almacenes".
    (function () {
        var b = el('almFiltroBuscar'), c = el('almFiltroCat'), u = el('almFiltroUm');
        var bActivo = b && ((b.value && b.value.trim()) || (b.dataset.active && b.dataset.active.trim()));
        var cActivo = c && ((c.value && c.value.trim()) || (c.dataset.active && c.dataset.active.trim()));
        if (bActivo || cActivo || (u && u.value) || almBuscarPickedId) window.almCargar();
    })();

    // ── Posicion de Consolidado + "En otros almacenes" en mobile ─────────────
    // Default DOM (desktop): .counter-sidebar es sibling del .admin-card dentro
    // de .page-layout-grid. Contiene el Consolidado (totales) Y el #almDistWrapper
    // (panel "En otros almacenes" / chart de categorias).
    //
    // Mobile: el cliente quiere los DOS bloques en posiciones DISTINTAS:
    //   - Consolidado: debajo del boton Acciones (despues de #almFilters), arriba
    //     de la tabla. Movemos la .counter-sidebar entera ahi (el #almDistWrapper
    //     hijo se saca antes para no arrastrarlo).
    //   - #almDistWrapper (En otros almacenes): al FINAL de la tabla, separado.
    //     Anchor: #almLoadingMore (vive justo despues del .alm-table-wrap).
    //
    // Desktop: restaurar el #almDistWrapper DENTRO de .counter-sidebar y la
    // sidebar al .page-layout-grid — vuelve al layout original.
    (function placeSidebarMobile() {
        // 1024px: MISMO corte que el colapso a 1 columna de .page-layout-grid y que
        // la regla CSS que muestra/apila el Consolidado (ver el @media de arriba en
        // este archivo). Antes era 768 — entre 769 y 1024px el grid ya colapsaba
        // pero el JS no reubicaba nada, así que el Consolidado quedaba flotando en
        // su posición de grid por defecto (debajo de la tabla).
        var BREAKPOINT = 1024;
        function place() {
            var sidebar  = document.querySelector('.counter-sidebar');
            var distWrap = document.getElementById('almDistWrapper');
            var filters  = document.getElementById('almFilters');
            var anchor   = document.getElementById('almLoadingMore');
            var grid     = document.querySelector('.page-layout-grid');
            if (!sidebar || !filters || !grid) return;
            if (window.innerWidth <= BREAKPOINT) {
                // Sacar el #almDistWrapper de la sidebar (si aun esta dentro) y
                // anclarlo despues de la tabla.
                if (distWrap && anchor) {
                    if (distWrap.previousElementSibling !== anchor) {
                        anchor.parentNode.insertBefore(distWrap, anchor.nextSibling);
                    }
                }
                // Sidebar (ahora solo con el Consolidado) → despues de #almFilters
                if (sidebar.previousElementSibling !== filters) {
                    filters.parentNode.insertBefore(sidebar, filters.nextSibling);
                }
            } else {
                // Desktop: restaurar el #almDistWrapper dentro de la sidebar.
                if (distWrap && sidebar && distWrap.parentNode !== sidebar) {
                    sidebar.appendChild(distWrap);
                }
                // Y la sidebar como sibling del admin-card.
                if (sidebar.parentNode !== grid) {
                    grid.appendChild(sidebar);
                }
            }
        }
        place();
        // Se expone porque este IIFE vive DENTRO del bloque protegido por
        // window.__almIndexInit: en una re-entrada por SPA ese guard sale antes y place()
        // NO se vuelve a ejecutar, aunque el DOM sea nuevo. Sin esto el Consolidado se
        // quedaba en su posición por defecto del grid (debajo de la tabla) en vez de subir
        // bajo el botón Acciones — el "a veces sí, a veces no" que reportó el cliente:
        // salía bien al entrar por URL o al recargar (primer montaje) y mal al llegar por
        // el menú. Lo llama almResetOnRemount(), que es el punto por el que ya pasa todo
        // re-montaje para resincronizarse contra el DOM nuevo.
        window.almColocarSidebarMovil = place;
        var resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(place, 100);
        });
    })();
})();
