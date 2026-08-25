/**
 * Almacén — render OFFLINE (consulta sin internet, filtros COMPLETOS)
 * -------------------------------------------------------------------
 * Llena la tabla de inventario (#almTableBody) desde IndexedDB (copia de offline-sync.js).
 * NO se activa solo: se registra en OfflineMode (banner del layout) y solo pinta cuando el
 * usuario toca "Trabajar sin conexión". SOLO LECTURA. Con internet la página manda.
 *
 * MOTOR DE FILTROS LOCAL: toda la UI de filtros online (buscador, categoría, badges
 * "Con stock"/"Stock bajo", "Ver todo", selector de almacén) converge en window.almCargar().
 * Con el modo offline activo, almCargar se INTERCEPTA y en vez del fetch al servidor se
 * filtra la copia local leyendo el MISMO estado del DOM que usa el online:
 *   - #almSelAlmacen (value), #almFiltroBuscar / #almFiltroCat (value || dataset.active —
 *     patrón placeholder-background del online), badges por su clase .is-on.
 * Semántica replicada del backend (AlmacenController::inventarioBaseQuery):
 *   categoría = LIKE %cat%; solo_bajo = mínimo definido y saldo <= mínimo;
 *   solo_con_saldo = saldo > 0. Los KPIs del header se recalculan de la copia local.
 *
 * Se carga GLOBAL en el layout y se (re)inicializa en cada carga y navegación SPA
 * (init en DOMContentLoaded + 'spa:contentLoaded'). render() reconsulta el DOM cada vez.
 */
(function () {
    'use strict';

    const OM = window.OfflineMode;
    if (!OM) return;                 // sin controlador offline no hay nada que hacer
    const esc = OM.esc;
    // 6 columnas: Código · Descripción · Categoría · Stock (con unidad) · Salida · Detalles.
    // (La columna "UND" se fusionó en Stock — debe coincidir con el thead y $cols del partial.)
    const COLS = 6;
    // Filas por lote: el MISMO $PAGE_SIZE del backend (AlmacenController::inventario).
    const PAGE_SIZE = 120;

    function getBody() { return document.getElementById('almTableBody'); }

    // Helpers compartidos del controlador offline (fuente única, ver offline_mode.js):
    // norm = normalización de búsqueda; fmt = números en formato latino, idéntico al
    // number_format(n,3,',','.') de la tabla online — las filas deben verse iguales.
    const norm = OM.norm;
    const fmt  = OM.fmt;

    function filaMensaje(html) {
        return '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">' + html + '</td></tr>';
    }

    // ── Estado de filtros leído del DOM (misma fuente que usa el online) ─────────
    // value || dataset.active: el online "promueve" el texto tecleado a dataset.active
    // (patrón placeholder-background), así que un filtro aplicado vive ahí, no en value.
    function valorFiltro(id) {
        const e = document.getElementById(id);
        if (!e) return '';
        return String(e.value || '').trim() || String(e.dataset.active || '').trim();
    }

    // "Ver todo el stock": el online lo lleva en una variable de su closure
    // (almVerTodoActivo), que desde aquí no se puede leer. Se replica su MISMA regla —
    // lo enciende almCargar({verTodo:true}) y cualquier otra recarga lo apaga — leyendo
    // el argumento en el punto donde ya se intercepta almCargar (ver init).
    let verTodo = false;

    function estadoFiltros() {
        const selEl = document.getElementById('almSelAlmacen');
        const bajo = document.getElementById('almBadgeBajo');
        const conS = document.getElementById('almBadgeConSaldo');
        return {
            idAlm:        selEl && selEl.value ? parseInt(selEl.value, 10) : null,
            q:            norm(valorFiltro('almFiltroBuscar')),
            cat:          norm(valorFiltro('almFiltroCat')),
            soloBajo:     !!(bajo && bajo.classList.contains('is-on')),
            soloConSaldo: !!(conS && conS.classList.contains('is-on')),
            verTodo:      verTodo,
        };
    }

    // Réplica del $hayFiltro del backend (AlmacenController::inventario): la tabla del
    // almacén ARRANCA VACÍA y solo lista cuando hay un filtro de CONTENIDO. Sin esta
    // puerta, al abrir Inventario sin conexión se volcaba el inventario entero, que no
    // es lo que hace la web.
    // OJO: el almacén seleccionado NO cuenta — es el contexto, no un filtro (mismo
    // criterio que el backend, que lo excluye a propósito de esta comprobación).
    function hayFiltro(f) {
        return !!(f.q || f.cat || f.soloBajo || f.soloConSaldo || f.verTodo);
    }

    // minima llega como float (null → 0 en el snapshot): mínimo > 0 = "tiene mínimo
    // configurado", igual que el pintado de filas rojas online.
    function esBajo(p) {
        const min = Number(p.minima) || 0;
        return min > 0 && (Number(p.cantidad) || 0) <= min;
    }

    function filtrar(stock, f) {
        return stock.filter(function (p) {
            if (f.idAlm && p.id_almacen !== f.idAlm) return false;
            if (f.q && (norm(p.codigo) + ' ' + norm(p.nombre)).indexOf(f.q) < 0) return false;
            if (f.cat && norm(p.categoria).indexOf(f.cat) < 0) return false;      // LIKE %cat%
            if (f.soloBajo && !esBajo(p)) return false;
            if (f.soloConSaldo && (Number(p.cantidad) || 0) <= 0) return false;
            return true;
        });
    }

    // KPIs del header desde la copia local: sobre el almacén seleccionado, SIN los
    // filtros de contenido (igual que online, donde stats no depende de search/cat).
    function pintarStats(stock, idAlm) {
        const base = idAlm ? stock.filter(function (s) { return s.id_almacen === idAlm; }) : stock;
        const num = function (id, v) {
            const e = document.getElementById(id);
            if (e) e.textContent = Number(v).toLocaleString('es-ES');
        };
        num('almStatsTotal', base.length);
        num('almStatsConSaldo', base.filter(function (p) { return (Number(p.cantidad) || 0) > 0; }).length);
        num('almStatsBajo', base.filter(esBajo).length);
    }

    async function render() {
        const tbody = getBody(); if (!tbody) return;
        // Cancela el scroll infinito del pintado anterior: los caminos que terminan en un
        // MENSAJE no pasan por porLotes y dejarían su observador vivo.
        OM.detenerLotes(tbody);
        // Los almacenes viajan en el snapshot: hacen falta para nombrar el almacén en el
        // estado vacío igual que online (#almSelAlmacen es un input hidden con el ID, no
        // un <select>, así que el nombre no está en el DOM).
        const [stock, almacenes] = await Promise.all([
            window.OfflineDB.get('stock').catch(() => []),
            window.OfflineDB.get('almacenes').catch(() => []),
        ]);

        if (!stock || !stock.length) {
            tbody.innerHTML = filaMensaje(
                '<i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">cloud_off</i>' +
                'No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.'
            );
            return;
        }

        const f = estadoFiltros();
        pintarStats(stock, f.idAlm);

        // Sin filtro de contenido: mismo estado inicial que online, no el inventario entero.
        if (!hayFiltro(f)) {
            const alm = (almacenes || []).find(function (a) { return Number(a.id) === Number(f.idAlm); });
            const nombre = alm ? alm.nombre : '';
            tbody.innerHTML = filaMensaje(
                '<i class="material-icons" style="font-size:46px;color:#cbd5e0;display:block;margin:0 auto 10px;">filter_alt</i>' +
                'Usa los filtros para ver el inventario' + (nombre ? ' de <strong>' + esc(nombre) + '</strong>' : '') + '.'
            );
            return;
        }

        let filas = filtrar(stock, f);
        filas.sort(function (a, b) { return String(a.nombre).localeCompare(String(b.nombre), 'es'); });

        if (!filas.length) {
            tbody.innerHTML = filaMensaje(
                '<i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">search_off</i>' +
                'Sin coincidencias en la copia local.'
            );
            return;
        }

        // Por lotes con scroll infinito (helper compartido), igual que la tabla online:
        // volcar los ~1.600 productos de una vez trababa el teléfono sin necesidad.
        OM.porLotes(tbody, filas, function (p) {
            const saldo = Number(p.cantidad) || 0;
            const bajo = esBajo(p);
            return '' +
                '<tr class="alm-row ' + (bajo ? 'alm-row-bajo' : '') + '" data-offline="1">' +
                '<td class="alm-td-codigo" style="font-family:monospace;font-weight:700;color:#0f172a;white-space:nowrap;">' + esc(p.codigo) + '</td>' +
                '<td class="alm-td-nombre" data-codigo="' + esc(p.codigo) + '" style="font-weight:600;color:#1e293b;">' + esc(p.nombre) + '</td>' +
                '<td class="alm-td-cat" style="color:#475569;">' + (p.categoria ? esc(p.categoria) : '—') + '</td>' +
                '<td class="alm-td-stock" style="text-align:center;font-weight:800;font-size:15px;color:#0f172a;">' + fmt(saldo) + '<span class="alm-stock-um">' + esc(p.um) + '</span>' +
                    (bajo ? ' <i class="material-icons" style="font-size:14px;color:#f59e0b;vertical-align:middle;" title="Stock en o por debajo del mínimo">warning</i>' : '') +
                '</td>' +
                '<td class="alm-td-cant" style="text-align:center;color:#cbd5e0;">—</td>' +
                '<td class="alm-td-det" style="text-align:center;color:#cbd5e0;">—</td>' +
                '</tr>';
        }, PAGE_SIZE);
    }

    // (Re)inicializa en cada carga/navegación: registra el render (por clave, sobreescribe),
    // parchea almCargar (el inline del blade lo REDEFINE en cada montaje SPA, así que se
    // re-parchea aquí) y cablea el filtrado en vivo de los dos inputs de texto. Si el modo
    // offline ya está activo (se navegó sin internet), pinta de una.
    function init() {
        if (!getBody()) return;      // no estamos en la página de inventario
        OM.registrar('almacen', function () { return OM.conOfflineDB(render); });

        // Punto ÚNICO de intercepción: badges, categoría, sugerencias, "Ver todo" y el
        // selector de almacén terminan todos en almCargar() — offline se filtra local.
        if (typeof window.almCargar === 'function' && window.almCargar !== window._almOffPatchedCargar) {
            window._origAlmCargar = window.almCargar;
            window._almOffPatchedCargar = function (opts) {
                // MISMA regla que el online (`if (!append) almVerTodoActivo = !!opts.verTodo`):
                // solo almCargar({verTodo:true}) lo enciende y cualquier otra recarga lo apaga;
                // el append del scroll infinito lo conserva.
                if (typeof opts === 'string' || opts == null) opts = {};
                if (!(opts.append && opts.offset > 0)) verTodo = !!opts.verTodo;
                if (OM.estaActivo()) { OM.conOfflineDB(render); return; }
                // Sin conexión pero SIN activar el modo offline: bloqueamos el filtro (no
                // pegarle al servidor caído) y avisamos que pulse "Trabajar sin conexión".
                if (OM.pendienteActivar && OM.pendienteActivar()) { OM.avisarActivar(); return; }
                return window._origAlmCargar.apply(null, arguments);
            };
            window.almCargar = window._almOffPatchedCargar;
        }

        // Filtrado en vivo al teclear (el online espera Enter/sugerencia; offline no hay
        // paginación del servidor de por medio, así que filtrar por tecla es gratis).
        ['almFiltroBuscar', 'almFiltroCat'].forEach(function (id) {
            const inp = document.getElementById(id);
            if (inp && !inp.dataset.offWired) {
                inp.dataset.offWired = '1';
                inp.addEventListener('input', function () { if (OM.estaActivo()) OM.conOfflineDB(render); });
            }
        });

        if (OM.estaActivo()) OM.conOfflineDB(render);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
