/* ════════════════════════════════════════════════════════════════════════════
   Kits por equipo — modal de /admin/almacen (Acciones → Kits por equipo).
   Markup y estilos: resources/views/admin/almacen/partials/kits_modal.blade.php.
   Servidor: App\Http\Controllers\AlmacenKitController + App\Services\KitAlmacenService.

   Un kit es una RECETA: materiales con lo que lleva cada kit, para uno o varios modelos de
   equipo. Aquí se busca (placa, modelo o nombre; filtros tipo → modelo en cascada), se ve
   cuántos alcanzan con la existencia del almacén del inventario y se carga en la salida con
   window.almKitCargarEnSalida (inline del inventario): los materiales quedan seleccionados
   con la cantidad × kits y se sigue con el "Registrar salida" de siempre.

   Sin conexión: los kits vienen de la copia offline (tabla `kits`, el MISMO catálogo que da el
   servidor) y la existencia de su tabla `stock`, así que se ven igual. Cargar en la salida y
   editar necesitan conexión (la salida no se registra offline) y se avisa.

   Vive en window y sobrevive a la navegación SPA: el DOM se vuelve a buscar en cada llamada.
   ════════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';
    if (window.AlmKits) return;

    var S = {
        kits: [],            // KitAlmacenService::catalogo()
        existencias: {},     // { id_producto: cantidad } del almacén del inventario
        idAlmacen: '',
        q: '', tipo: '', modelo: '',
        sel: null,           // id del kit a la vista
        cuantos: 1,
        modelos: null,       // modelos para elegir en el editor (se piden una vez)
        sugeridos: {},       // clave de modelo → productos ligados (caché del editor)
        ed: null,            // kit en edición: { id, modelos:[], items:[] }
    };
    var GENERAL = '__general__';   // "tipo" de los kits sin equipos

    // ── utilidades ───────────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }
    function modal() { return $('almKitsModal'); }
    function esc(s) { return window.escapeHtml(String(s == null ? '' : s)); }
    function fmt(n) { return (parseFloat(n) || 0).toLocaleString('es-ES', { maximumFractionDigits: 3 }); }
    function toast(m, t) { window.toast(m, t || 'success'); }
    function offline() { return !!(window.OfflineMode && window.OfflineMode.estaActivo()); }
    function puede(clave) { var m = modal(); return !!m && m.dataset[clave] === '1'; }
    function sinAcento(s) { return String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase(); }
    function alfanum(s) { return sinAcento(s).replace(/[^A-Z0-9]/g, ''); }
    function json(r) {
        return r.json().catch(function () { return {}; }).then(function (b) {
            if (!r.ok) {
                var errs = b && b.errors ? Object.keys(b.errors).map(function (k) { return b.errors[k][0]; }) : [];
                throw new Error(errs[0] || (b && b.message) || ('Error ' + r.status));
            }
            return b;
        });
    }
    // Todo pasa por aqui. El token CSRF lo pone apiFetch (dom_helpers.js) en cuanto el
    // metodo no es GET: no hay que repetirlo en cada llamada.
    function pedir(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});
        return window.apiFetch(url, opts).then(json);
    }
    function almacenActual() { var s = $('almSelAlmacen'); return s ? String(s.value || '') : ''; }
    function nombreAlmacen(id) {
        var it = id && document.querySelector('#almSelAlmacenDropdown .dropdown-item[data-value="' + id + '"]');
        return it ? it.textContent.trim() : '';
    }
    function tiposDe(k) { return k.modelos.length ? k.modelos.map(function (m) { return m.tipo; }) : [GENERAL]; }
    function kitPorId(id) { return S.kits.filter(function (k) { return k.id === id; })[0] || null; }

    // ── datos ────────────────────────────────────────────────────────────────
    function cargar() {
        S.idAlmacen = almacenActual();
        if (offline()) return cargarOffline();
        var u = new URL(modal().dataset.urlIndex, window.location.origin);
        if (S.idAlmacen) u.searchParams.set('id_almacen', S.idAlmacen);
        return pedir(u.toString()).then(function (b) {
            S.kits = b.kits || [];
            S.existencias = b.existencias || {};
        });
    }
    // Copia offline: el mismo catálogo (tabla `kits`) y la existencia de la tabla `stock`, que
    // ya viene sumada por almacén como la del servidor. De `stock` también se toma el nombre
    // vigente del producto (la tabla de kits solo se reenvía cuando cambian los catálogos).
    function cargarOffline() {
        return window.OfflineMode.conOfflineDB(function () {
            return Promise.all([window.OfflineDB.get('kits'), window.OfflineDB.get('stock')]).then(function (r) {
                var ex = {}, prod = {};
                (r[1] || []).forEach(function (f) {
                    prod[f.id_producto] = f;
                    if (String(f.id_almacen) === S.idAlmacen) ex[f.id_producto] = f.cantidad;
                });
                S.kits = (r[0] || []).map(function (k) {
                    return Object.assign({}, k, { items: k.items.map(function (i) {
                        var p = prod[i.id_producto];
                        return p ? Object.assign({}, i, { codigo: p.codigo, nombre: p.nombre, um: p.um }) : i;
                    }) });
                });
                S.existencias = ex;
            });
        });
    }

    // ── cuánto alcanza ───────────────────────────────────────────────────────
    function hay(idProducto) { return parseFloat(S.existencias[idProducto]) || 0; }
    // Kits completos que se pueden armar con la existencia: el material más escaso manda.
    function alcanza(k) {
        if (!S.idAlmacen || !k.items.length) return null;
        return k.items.reduce(function (min, i) { return Math.min(min, Math.floor(hay(i.id_producto) / i.cantidad + 1e-9)); }, Infinity);
    }
    function badge(k) {
        var n = alcanza(k);
        if (n === null) return '<span class="akit-badge gris">' + (k.items.length ? 'Elige almacén' : 'Sin materiales') + '</span>';
        return n > 0 ? '<span class="akit-badge ok">Alcanza para ' + n + '</span>' : '<span class="akit-badge no">Sin stock</span>';
    }

    // ── filtros ──────────────────────────────────────────────────────────────
    // Placa (5+ letras o números, sin espacios ni guiones: la misma regla que "Vincular equipo")
    // o texto con el buscador del módulo (FuzzySearch): nombre, descripción y modelos. Lo hallado
    // por placa va primero.
    function porBusqueda() {
        var q = S.q.trim();
        if (!q) return S.kits.slice();
        var qa = alfanum(q), porPlaca = [];
        if (qa.length >= 5) {
            porPlaca = S.kits.filter(function (k) { return k.placas.some(function (p) { return alfanum(p).indexOf(qa) >= 0; }); });
        }
        var porTexto = window.FuzzySearch.rank(S.kits, q, function (k) {
            var mods = k.modelos.map(function (m) { return m.tipo + ' ' + m.modelo; }).join(' ');
            return { haystack: k.nombre + ' ' + k.descripcion + ' ' + mods, label: k.nombre };
        });
        return porPlaca.concat(porTexto.filter(function (k) { return porPlaca.indexOf(k) < 0; }));
    }
    function placaBuscada(k) {
        var qa = alfanum(S.q);
        if (qa.length < 5) return '';
        return k.placas.filter(function (p) { return alfanum(p).indexOf(qa) >= 0; })[0] || '';
    }
    function contar(kits, clave) {
        var n = {};
        kits.forEach(function (k) {
            clave(k).filter(function (v, i, a) { return a.indexOf(v) === i; }).forEach(function (v) { n[v] = (n[v] || 0) + 1; });
        });
        return n;
    }
    function chip(valor, etiqueta, n, on, accion) {
        return '<button type="button" class="akit-chip' + (on ? ' on' : '') + '" onclick="window.AlmKits.' + accion + '(' + esc(JSON.stringify(valor)) + ')">'
            + esc(etiqueta) + '<span class="n">' + n + '</span></button>';
    }

    // ── pintado de la lista ──────────────────────────────────────────────────
    function pintar() {
        var base = porBusqueda();
        // Tipo → modelo, cada chip con cuántos kits quedan con él (dentro de la búsqueda).
        var nTipo = contar(base, tiposDe);
        if (S.tipo && !nTipo[S.tipo]) { S.tipo = ''; S.modelo = ''; }
        var tipos = Object.keys(nTipo).sort(function (a, b) {
            return a === GENERAL ? 1 : b === GENERAL ? -1 : a.localeCompare(b, 'es');
        });
        // La fila de chips se esconde SOLO cuando lo único que hay es "Uso general": ahí
        // saldrían "Todos 3" y "Uso general 3", dos botones con el mismo número que llevan
        // a la misma lista. Con un tipo REAL (p.ej. todos los kits son de CAMIÓN) el chip
        // SE QUEDA aunque sea el único: es la puerta a los modelos —pulsarlo es lo que
        // despliega HOWO / SINOTRUK / JAC—, y esconderlo dejaría esa cascada inalcanzable.
        var boxTipos = $('almKitsTipos');
        var soloGeneral = tipos.length === 1 && tipos[0] === GENERAL;
        var hayChips = base.length > 0 && !soloGeneral;
        boxTipos.innerHTML = hayChips
            ? chip('', 'Todos', base.length, !S.tipo, 'filtrarTipo')
              + tipos.map(function (t) { return chip(t, t === GENERAL ? 'Uso general' : t, nTipo[t], S.tipo === t, 'filtrarTipo'); }).join('')
            : '';
        boxTipos.hidden = !hayChips;
        // Sin chips en pantalla no habría forma de quitar el filtro, así que se suelta.
        if (!hayChips) S.tipo = '';
        var enTipo = S.tipo ? base.filter(function (k) { return tiposDe(k).indexOf(S.tipo) >= 0; }) : base;
        var boxMod = $('almKitsModelos');
        if (S.tipo && S.tipo !== GENERAL) {
            var nMod = contar(enTipo, function (k) {
                return k.modelos.filter(function (m) { return m.tipo === S.tipo; }).map(function (m) { return m.modelo; });
            });
            if (S.modelo && !nMod[S.modelo]) S.modelo = '';
            var mods = Object.keys(nMod).sort(function (a, b) { return a.localeCompare(b, 'es'); });
            boxMod.innerHTML = mods.length > 1
                ? chip('', 'Todos los modelos', enTipo.length, !S.modelo, 'filtrarModelo')
                  + mods.map(function (m) { return chip(m, m, nMod[m], S.modelo === m, 'filtrarModelo'); }).join('')
                : '';
            boxMod.hidden = mods.length < 2;
        } else {
            S.modelo = '';
            boxMod.hidden = true;
            boxMod.innerHTML = '';
        }
        var lista = S.modelo
            ? enTipo.filter(function (k) { return k.modelos.some(function (m) { return m.tipo === S.tipo && m.modelo === S.modelo; }); })
            : enTipo;

        var cards = $('almKitsCards');
        if (!lista.length) {
            cards.innerHTML = '<div class="akit-vacio"><i class="material-icons">inventory_2</i>'
                + (S.kits.length ? 'Ningún kit coincide con la búsqueda.' : 'Todavía no hay kits.<br>Arma el primero con <b>Nuevo kit</b>.')
                + '</div>';
            S.sel = null;
        } else {
            if (!lista.some(function (k) { return k.id === S.sel; })) { S.sel = lista[0].id; S.cuantos = 1; }
            cards.innerHTML = lista.map(function (k) {
                var mods = k.modelos.length
                    ? k.modelos.map(function (m) { return esc(m.tipo + ' · ' + m.modelo); }).join('<br>')
                    : '<span class="gen">Uso general</span>';
                var placa = placaBuscada(k);
                return '<button type="button" class="akit-card' + (k.id === S.sel ? ' on' : '') + '" onclick="window.AlmKits.ver(' + k.id + ')">'
                    + '<div class="akit-card-top"><span class="akit-card-nombre">' + esc(k.nombre) + '</span>' + badge(k) + '</div>'
                    + '<div class="akit-card-mod">' + mods + '</div>'
                    + '<div class="akit-card-pie">' + k.items.length + (k.items.length === 1 ? ' material' : ' materiales')
                    + (placa ? ' · placa ' + esc(placa) : '') + '</div>'
                    + '</button>';
            }).join('');
        }
        pintarAlmacen();
        pintarDetalle();
    }

    function pintarAlmacen() {
        var box = $('almKitsAlmacen');
        // Con almacén elegido NO se enseña nada: el modal se abre DESDE ese almacén, con su
        // nombre en la barra del inventario justo detrás, así que "Existencias en BARCELONA"
        // solo repetía lo que el usuario acababa de elegir y robaba una línea del modal.
        // El aviso sí se queda cuando NO hay almacén: sin él, los números de "En almacén" y
        // el "no alcanza" no se sostienen y nadie sabría por qué salen en cero.
        if (S.idAlmacen) {
            box.hidden = true;
            box.innerHTML = '';
            box.removeAttribute('title');
            return;
        }
        box.hidden = false;
        box.classList.add('sin');
        box.innerHTML = '<i class="material-icons">warehouse</i>Elige un almacén en el inventario';
        box.title = 'Para ver cuánto alcanza, elige primero el almacén en el inventario';
    }

    function pintarDetalle() {
        var box = $('almKitsDetalle');
        var k = kitPorId(S.sel);
        if (!k) { box.innerHTML = '<div class="akit-vacio"><i class="material-icons">touch_app</i>Elige un kit para ver sus materiales.</div>'; return; }
        var n = alcanza(k);
        if (n !== null && n > 0 && S.cuantos > n) S.cuantos = n;
        var cuantos = Math.max(1, S.cuantos);
        var tags = k.modelos.length
            ? k.modelos.map(function (m) { return '<span class="akit-tag">' + esc(m.tipo + ' · ' + m.modelo) + '</span>'; }).join('')
            : '<span class="akit-tag gen">Uso general</span>';
        var filas = k.items.map(function (i) {
            var total = i.cantidad * cuantos, h = hay(i.id_producto);
            var falta = S.idAlmacen && h < total;
            return '<tr' + (falta ? ' class="falta"' : '') + '>'
                + '<td><span class="cod">' + esc(i.codigo) + '</span>' + esc(i.nombre) + '</td>'
                + '<td class="num">' + fmt(i.cantidad) + ' ' + esc(i.um) + '</td>'
                + '<td class="num"><b>' + fmt(total) + '</b></td>'
                + '<td class="num hay">' + (S.idAlmacen ? fmt(h) : '—') + '</td>'
                + '</tr>';
        }).join('');

        var aviso, clase, bloqueado = true;
        if (offline()) {
            aviso = 'Sin conexión no se registran salidas: podrás cargarlo al volver la conexión.'; clase = 'gris';
        } else if (!S.idAlmacen) {
            aviso = 'Elige el almacén en el inventario para ver lo que alcanza.'; clase = 'gris';
        } else if (!k.items.length) {
            aviso = 'Este kit no tiene materiales activos.'; clase = 'no';
        } else if (n === 0) {
            var escasos = k.items.filter(function (i) { return hay(i.id_producto) < i.cantidad; }).slice(0, 2)
                .map(function (i) { return esc(i.nombre) + ' (hay ' + fmt(hay(i.id_producto)) + ' de ' + fmt(i.cantidad) + ')'; });
            aviso = 'No alcanza para un kit. Falta: ' + escasos.join('; ') + '.'; clase = 'no';
        } else {
            aviso = 'Alcanza para ' + n + (n === 1 ? ' kit' : ' kits') + ' en este almacén.'; clase = 'ok'; bloqueado = false;
        }
        var max = n !== null && n > 0 ? n : 1;

        box.innerHTML =
              '<div class="akit-det-cab"><div>'
            +   '<h4 class="akit-det-nombre">' + esc(k.nombre) + '</h4>'
            +   (k.descripcion ? '<p class="akit-det-desc">' + esc(k.descripcion) + '</p>' : '')
            +   '<div class="akit-det-mods">' + tags + '</div></div>'
            +   '<div class="akit-det-acc">'
            +     '<button type="button" class="akit-ico" title="Editar kit" onclick="window.AlmKits.editar(' + k.id + ')"><i class="material-icons">edit</i></button>'
            +     '<button type="button" class="akit-ico rojo" title="Eliminar kit" onclick="window.AlmKits.eliminar(' + k.id + ')"><i class="material-icons">delete_outline</i></button>'
            +   '</div></div>'
            + '<div class="akit-tabla-caja"><table class="akit-tabla"><thead><tr>'
            +   '<th>Material</th><th class="num">Por kit</th><th class="num">Total × ' + cuantos + '</th><th class="num">En almacén</th>'
            + '</tr></thead><tbody>' + filas + '</tbody></table></div>'
            + '<div class="akit-det-pie">'
            +   '<div class="akit-cuantos">¿Cuántos kits?'
            +     '<div class="akit-stepper">'
            +       '<button type="button" onclick="window.AlmKits.cuantos(-1)"' + (cuantos <= 1 || bloqueado ? ' disabled' : '') + ' aria-label="Uno menos"><i class="material-icons" style="font-size:18px;">remove</i></button>'
            +       '<input type="text" inputmode="numeric" id="almKitCuantos" value="' + cuantos + '"' + (bloqueado ? ' disabled' : '') + ' onchange="window.AlmKits.cuantos(0, this.value)">'
            +       '<button type="button" onclick="window.AlmKits.cuantos(1)"' + (cuantos >= max || bloqueado ? ' disabled' : '') + ' aria-label="Uno más"><i class="material-icons" style="font-size:18px;">add</i></button>'
            +     '</div></div>'
            +   '<div class="akit-aviso ' + clase + '">' + aviso + '</div>'
            +   '<button type="button" class="btn-primary-maquinaria akit-btn-cargar" onclick="window.AlmKits.cargarEnSalida()"' + (bloqueado ? ' disabled' : '') + '>'
            +     '<i class="material-icons" style="font-size:18px;">playlist_add_check</i>Cargar a la salida</button>'
            + '</div>';
    }

    // ── editor ───────────────────────────────────────────────────────────────
    function mostrarVista(editor) {
        $('almKitsLista').hidden = editor;
        $('almKitsEditor').hidden = !editor;
        $('almKitsPieEditor').hidden = !editor;
        $('almKitsTitulo').textContent = editor ? (S.ed && S.ed.id ? 'Editar kit' : 'Nuevo kit') : 'Kits por equipo';
        // El editor es un formulario de campos apilados: con el ancho de la lista quedaba
        // medio modal vacio a la derecha. La lista SI necesita ese ancho (tiene columnas:
        // material, por kit, total, en almacen), asi que se estrecha solo mientras se edita.
        var caja = $('almKitsModal') && $('almKitsModal').querySelector('.alm-modal');
        if (caja) caja.classList.toggle('akit-editando', !!editor);
    }
    function puedeEditar() {
        if (offline()) { toast('Sin conexión no se pueden armar ni cambiar kits.', 'error'); return false; }
        if (!puede('puedeProductos')) { toast('No tienes permiso para armar kits (almacen.productos).', 'error'); return false; }
        return true;
    }
    function abrirEditor(kit) {
        S.ed = kit
            ? { id: kit.id, modelos: kit.modelos.slice(), items: kit.items.map(function (i) { return Object.assign({}, i); }) }
            : { id: null, modelos: [], items: [] };
        $('almKitNombre').value = kit ? kit.nombre : '';
        $('almKitModeloBuscar').value = '';
        $('almKitProdBuscar').value = '';
        $('almKitError').textContent = '';
        $('almKitModeloSug').hidden = true;
        $('almKitProdSug').hidden = true;
        mostrarVista(true);
        pintarEditor();
        if (typeof window.almCargarProductos === 'function') window.almCargarProductos();
        if (!S.modelos) {
            pedir(modal().dataset.urlModelos)
                .then(function (b) { S.modelos = b.modelos || []; })
                .catch(function (e) { toast(e.message || 'No se pudieron cargar los modelos.', 'error'); });
        }
        setTimeout(function () { $('almKitNombre').focus(); }, 30);
    }
    function pintarEditor() {
        var ed = S.ed;
        $('almKitModelosSel').innerHTML = ed.modelos.length
            ? ed.modelos.map(function (m) {
                return '<span class="akit-tag">' + esc(m.tipo + ' · ' + m.modelo)
                    + '<i class="material-icons" title="Quitar" onclick="window.AlmKits.quitarModelo(' + esc(JSON.stringify(m.clave)) + ')">close</i></span>';
            }).join('')
            : '<span class="akit-tag gen">Uso general (sin equipos)</span>';
        pintarSugeridos();
        var tb = $('almKitItems');
        tb.innerHTML = ed.items.length
            ? ed.items.map(function (i, n) {
                return '<tr><td><span class="cod">' + esc(i.codigo) + '</span>' + esc(i.nombre) + '</td>'
                    + '<td class="num"><input type="text" inputmode="decimal" class="akit-cant" value="' + fmt(i.cantidad) + '" onchange="window.AlmKits.cantidadItem(' + n + ', this)"> ' + esc(i.um) + '</td>'
                    + '<td><button type="button" class="akit-ico rojo" title="Quitar" onclick="window.AlmKits.quitarItem(' + n + ')"><i class="material-icons">close</i></button></td></tr>';
            }).join('')
            : '<tr><td colspan="3" class="akit-vacio" style="padding:14px;">Agrega los materiales con el buscador de arriba o desde los compatibles.</td></tr>';
    }
    // Productos ligados a los equipos elegidos (su compatibilidad): se piden por modelo, una vez.
    function pintarSugeridos() {
        var ed = S.ed, caja = $('almKitSugeridosCaja');
        if (!ed.modelos.length) { caja.hidden = true; return; }
        var faltan = ed.modelos.filter(function (m) { return !S.sugeridos[m.clave]; });
        if (faltan.length) {
            Promise.all(faltan.map(function (m) {
                var u = new URL(modal().dataset.urlSugeridos, window.location.origin);
                u.searchParams.set('origen', m.origen);
                m.refs.forEach(function (r) { u.searchParams.append('refs[]', r); });
                return pedir(u.toString()).then(function (b) { S.sugeridos[m.clave] = b.productos || []; });
            })).then(function () { if (S.ed === ed) pintarSugeridos(); })
              .catch(function (e) { toast(e.message || 'No se pudieron cargar los compatibles.', 'error'); });
            return;
        }
        var vistos = {}, lista = [];
        ed.modelos.forEach(function (m) {
            S.sugeridos[m.clave].forEach(function (p) { if (!vistos[p.id_producto]) { vistos[p.id_producto] = 1; lista.push(p); } });
        });
        caja.hidden = !lista.length;
        var ya = {}; ed.items.forEach(function (i) { ya[i.id_producto] = 1; });
        $('almKitSugeridos').innerHTML = lista.map(function (p) {
            return '<button type="button" class="akit-sugerido" onclick="window.AlmKits.agregarSugerido(' + p.id_producto + ')"' + (ya[p.id_producto] ? ' disabled title="Ya está en el kit"' : '') + '>'
                + '<i class="material-icons">' + (ya[p.id_producto] ? 'check' : 'add') + '</i>' + esc(p.nombre) + '</button>';
        }).join('');
    }
    function agregarItem(p) {
        var ed = S.ed;
        if (ed.items.some(function (i) { return i.id_producto === p.id_producto; })) { toast('Ese material ya está en el kit.', 'error'); return; }
        ed.items.push({ id_producto: p.id_producto, codigo: p.codigo, nombre: p.nombre, um: p.um, cantidad: p.cantidad || 1 });
        pintarEditor();
    }
    function sugLista(box, html) { box.innerHTML = html; box.hidden = false; }
    function cerrarSugerencias(e) {
        ['almKitModeloSug', 'almKitProdSug'].forEach(function (id) {
            var b = $(id);
            if (b && !b.hidden && !(e && e.target.closest && e.target.closest('.akit-pick'))) b.hidden = true;
        });
    }
    function numero(v) { return parseFloat(String(v || '').replace(/\./g, '').replace(',', '.')); }

    // ── API pública (los onclick del parcial) ────────────────────────────────
    window.AlmKits = {
        abrir: function () {
            var m = modal(); if (!m) return;
            S.q = ''; S.tipo = ''; S.modelo = ''; S.cuantos = 1; S.ed = null;
            $('almKitsBuscar').value = '';
            $('almKitsBuscarX').hidden = true;
            mostrarVista(false);
            if (typeof window.showPreloader === 'function') window.showPreloader();
            cargar().then(function () {
                m.classList.add('open');
                pintar();
                setTimeout(function () { $('almKitsBuscar').focus(); }, 30);
            }).catch(function (e) {
                toast(e.message || 'No se pudieron cargar los kits.', 'error');
            }).finally(function () {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
            });
        },
        cerrar: function () { var m = modal(); if (m) m.classList.remove('open'); S.ed = null; },
        buscar: function (q, enfocar) {
            S.q = String(q || '');
            if (enfocar) { $('almKitsBuscar').value = ''; $('almKitsBuscar').focus(); }
            $('almKitsBuscarX').hidden = !S.q;
            pintar();
        },
        filtrarTipo: function (t) { S.tipo = t; S.modelo = ''; pintar(); },
        filtrarModelo: function (m) { S.modelo = m; pintar(); },
        ver: function (id) {
            if (S.sel !== id) { S.sel = id; S.cuantos = 1; }
            pintar();
            // En el teléfono el detalle queda debajo de la lista: se lleva a la vista.
            if (window.matchMedia('(max-width: 768px)').matches) $('almKitsDetalle').scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        cuantos: function (paso, valor) {
            var k = kitPorId(S.sel); if (!k) return;
            var n = alcanza(k) || 1;
            var v = paso ? S.cuantos + paso : parseInt(valor, 10);
            if (!isFinite(v) || v < 1) v = 1;
            if (v > n) { v = n; toast('Solo alcanza para ' + n + (n === 1 ? ' kit' : ' kits') + ' en este almacén.', 'error'); }
            S.cuantos = v;
            pintarDetalle();
        },
        cargarEnSalida: function () {
            var k = kitPorId(S.sel); if (!k) return;
            if (offline()) { toast('Sin conexión no se registran salidas.', 'error'); return; }
            if (!puede('puedeMover')) { toast('No tienes permiso para registrar salidas (almacen.movimiento).', 'error'); return; }
            var n = alcanza(k);
            if (!n) return;
            var cuantos = Math.min(Math.max(1, S.cuantos), n);
            var equipo = placaBuscada(k)
                || (k.modelos.length === 1 ? k.modelos[0].tipo + ' ' + k.modelos[0].modelo : '');
            var motivo = k.nombre + ' × ' + cuantos + (equipo ? ' · ' + equipo : '');
            var lineas = k.items.map(function (i) { return { id_producto: i.id_producto, cantidad: i.cantidad * cuantos }; });
            if (typeof window.almKitCargarEnSalida !== 'function') { toast('Recarga la página para cargar el kit.', 'error'); return; }
            if (typeof window.showPreloader === 'function') window.showPreloader();
            window.almKitCargarEnSalida(lineas, motivo).then(function (res) {
                window.AlmKits.cerrar();
                var msg = 'Kit cargado: ' + lineas.length + (lineas.length === 1 ? ' material' : ' materiales') + ' × ' + cuantos + ' en la salida.';
                if (res.sinParte) msg += ' Elige el nº de parte de ' + res.sinParte + (res.sinParte === 1 ? ' producto' : ' productos') + '.';
                if (res.noEstan) msg += ' ' + res.noEstan + ' no están en este almacén.';
                toast(msg, res.sinParte || res.noEstan ? 'info' : 'success');
            }).catch(function (e) {
                if (e && e.message && e.message !== 'permiso' && e.message !== 'almacen') toast('No se pudo cargar el kit. Inténtalo de nuevo.', 'error');
            }).finally(function () {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
            });
        },

        nuevo: function () { if (puedeEditar()) abrirEditor(null); },
        editar: function (id) { var k = kitPorId(id); if (k && puedeEditar()) abrirEditor(k); },
        eliminar: function (id) {
            var k = kitPorId(id); if (!k || !puedeEditar()) return;
            window.confirmarAccion({
                type: 'danger', title: 'Eliminar kit',
                message: '¿Eliminar el kit <b>' + esc(k.nombre) + '</b>? Los productos y su stock no se tocan.',
                confirmText: 'Eliminar', cancelText: 'Volver',
            }, function () {
                pedir(modal().dataset.urlStore + '/' + id, { method: 'DELETE' })
                    .then(function (b) { toast(b.message || 'Kit eliminado.'); return cargar(); })
                    .then(pintar)
                    .catch(function (e) { toast(e.message || 'No se pudo eliminar el kit.', 'error'); });
            });
        },
        cancelarEdicion: function () { S.ed = null; mostrarVista(false); pintar(); },

        buscarModelo: function (q) {
            var box = $('almKitModeloSug');
            if (!S.modelos) { sugLista(box, '<div class="vacio">Cargando modelos…</div>'); return; }
            var ya = {}; S.ed.modelos.forEach(function (m) { ya[m.clave] = 1; });
            var t = sinAcento(q).trim();
            var lista = S.modelos.filter(function (m) { return !ya[m.clave] && (!t || sinAcento(m.tipo + ' ' + m.modelo).indexOf(t) >= 0); }).slice(0, 40);
            sugLista(box, lista.length
                ? lista.map(function (m) {
                    return '<button type="button" onclick="window.AlmKits.agregarModelo(' + esc(JSON.stringify(m.clave)) + ')"><span class="t">' + esc(m.tipo) + '</span>' + esc(m.modelo) + '</button>';
                }).join('')
                : '<div class="vacio">Ningún modelo coincide.</div>');
        },
        agregarModelo: function (clave) {
            var m = (S.modelos || []).filter(function (x) { return x.clave === clave; })[0];
            if (!m || !S.ed) return;
            S.ed.modelos.push(m);
            $('almKitModeloBuscar').value = '';
            $('almKitModeloSug').hidden = true;
            pintarEditor();
        },
        quitarModelo: function (clave) {
            S.ed.modelos = S.ed.modelos.filter(function (m) { return m.clave !== clave; });
            pintarEditor();
        },
        agregarSugerido: function (id) {
            var p = null;
            S.ed.modelos.some(function (m) { return (p = (S.sugeridos[m.clave] || []).filter(function (x) { return x.id_producto === id; })[0]); });
            if (p) agregarItem(p);
        },
        buscarProducto: function (q) {
            var box = $('almKitProdSug');
            if (!String(q || '').trim()) { box.hidden = true; return; }
            if (!window.almProductosCargados) { sugLista(box, '<div class="vacio">Cargando el catálogo…</div>'); return; }
            var ya = {}; S.ed.items.forEach(function (i) { ya[i.id_producto] = 1; });
            var lista = window.FuzzySearch.rank(window.almProductosLista, q, function (p) {
                return { haystack: p.CODIGO + ' ' + p.NOMBRE + ' ' + (p.EQUIV || ''), label: p.NOMBRE };
            }).filter(function (p) { return !ya[p.ID_PRODUCTO]; }).slice(0, 30);
            sugLista(box, lista.length
                ? lista.map(function (p) {
                    return '<button type="button" onclick="window.AlmKits.agregarProducto(' + Number(p.ID_PRODUCTO) + ')"><span class="t">' + esc(p.CODIGO) + '</span>' + esc(p.NOMBRE) + '</button>';
                }).join('')
                : '<div class="vacio">Ningún material coincide.</div>');
        },
        agregarProducto: function (id) {
            var p = (window.almProductosLista || []).filter(function (x) { return Number(x.ID_PRODUCTO) === id; })[0];
            if (!p) return;
            agregarItem({ id_producto: id, codigo: p.CODIGO, nombre: p.NOMBRE, um: p.UM, cantidad: 1 });
            $('almKitProdBuscar').value = '';
            $('almKitProdSug').hidden = true;
            $('almKitProdBuscar').focus();
        },
        cantidadItem: function (n, inp) {
            var v = numero(inp.value), it = S.ed.items[n];
            if (!it) return;
            if (!isFinite(v) || v <= 0) { inp.value = fmt(it.cantidad); toast('La cantidad por kit tiene que ser mayor que cero.', 'error'); return; }
            it.cantidad = Math.round(v * 1000) / 1000;
            inp.value = fmt(it.cantidad);
        },
        quitarItem: function (n) { S.ed.items.splice(n, 1); pintarEditor(); },

        guardar: function () {
            var ed = S.ed; if (!ed || !puedeEditar()) return;
            var err = $('almKitError');
            var nombre = $('almKitNombre').value.trim();
            if (!nombre) { err.textContent = 'Ponle un nombre al kit.'; $('almKitNombre').focus(); return; }
            if (!ed.items.length) { err.textContent = 'El kit necesita al menos un material.'; $('almKitProdBuscar').focus(); return; }
            err.textContent = '';
            var cuerpo = {
                nombre: nombre,
                items: ed.items.map(function (i) { return { id_producto: i.id_producto, cantidad: i.cantidad }; }),
                modelos: ed.modelos.map(function (m) { return { origen: m.origen, refs: m.refs }; }),
            };
            var btn = $('almKitGuardar'); btn.disabled = true;
            pedir(ed.id ? modal().dataset.urlStore + '/' + ed.id : modal().dataset.urlStore, {
                method: ed.id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(cuerpo),
            }).then(function (b) {
                toast(b.message || 'Kit guardado.');
                S.ed = null; S.sel = b.id; S.cuantos = 1; S.q = ''; S.tipo = ''; S.modelo = '';
                $('almKitsBuscar').value = ''; $('almKitsBuscarX').hidden = true;
                mostrarVista(false);
                return cargar().then(pintar);
            }).catch(function (e) {
                err.textContent = e.message || 'No se pudo guardar el kit.';
            }).finally(function () { btn.disabled = false; });
        },
    };

    // Las listas de sugerencias del editor se cierran al tocar fuera (una a la vez, sin
    // stopPropagation: el mismo criterio que el resto de desplegables del sistema).
    document.addEventListener('click', cerrarSugerencias);
    // Cambiar de almacén en el inventario con el modal abierto no pasa (el modal lo tapa),
    // pero pasar a modo sin conexión sí: se vuelve a leer de la copia local.
    window.addEventListener('offline-datos-actualizados', function () {
        var m = modal();
        if (m && m.classList.contains('open') && !S.ed && offline()) cargar().then(pintar);
    });
})();
