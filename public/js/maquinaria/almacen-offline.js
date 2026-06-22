/**
 * Almacén — render OFFLINE (Fase 1: consulta sin internet)
 * --------------------------------------------------------
 * Llena la tabla de inventario (#almTableBody) desde IndexedDB (copia de offline-sync.js).
 * NO se activa solo: se registra en OfflineMode (banner del layout) y solo pinta cuando el
 * usuario toca "Trabajar sin conexión". SOLO LECTURA. Con internet la página manda.
 *
 * Se carga GLOBAL en el layout y se (re)inicializa en cada carga y navegación SPA
 * (init en DOMContentLoaded + 'spa:contentLoaded'). render() reconsulta el DOM cada vez,
 * así no quedan referencias viejas tras intercambiar el contenido.
 */
(function () {
    'use strict';

    const OM = window.OfflineMode;
    if (!OM) return;                 // sin controlador offline no hay nada que hacer
    const esc = OM.esc;
    // 6 columnas: Código · Descripción · Categoría · Stock (con unidad) · Salida · Detalles.
    // (La columna "UND" se fusionó en Stock — debe coincidir con el thead y $cols del partial.)
    const COLS = 6;

    function getBody() { return document.getElementById('almTableBody'); }

    function fmt(n) {
        const v = Number(n) || 0;
        let s = v.toFixed(3);
        if (s.indexOf('.') >= 0) s = s.replace(/0+$/, '').replace(/\.$/, '');
        const partes = s.split('.');
        partes[0] = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return partes.join('.') || '0';
    }

    function filaMensaje(html) {
        return '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px 16px;color:#94a3b8;font-size:14px;">' + html + '</td></tr>';
    }

    function aplicarFiltroBuscador() {
        const tbody = getBody(); if (!tbody) return;
        const inp = document.getElementById('almFiltroBuscar');
        const q = (inp && inp.value ? inp.value : '').trim().toLowerCase();
        tbody.querySelectorAll('tr[data-offline]').forEach(function (tr) {
            const blob = tr.getAttribute('data-buscar') || '';
            tr.style.display = (!q || blob.indexOf(q) >= 0) ? '' : 'none';
        });
    }

    async function render() {
        const tbody = getBody(); if (!tbody) return;
        const stock = await window.OfflineDB.get('stock').catch(() => []);

        if (!stock || !stock.length) {
            tbody.innerHTML = filaMensaje(
                '<i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">cloud_off</i>' +
                'No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.'
            );
            return;
        }

        const selEl = document.getElementById('almSelAlmacen');
        const idAlm = selEl && selEl.value ? parseInt(selEl.value, 10) : null;

        let filas = idAlm ? stock.filter(function (s) { return s.id_almacen === idAlm; }) : stock.slice();
        filas.sort(function (a, b) { return String(a.nombre).localeCompare(String(b.nombre), 'es'); });

        if (!filas.length) {
            tbody.innerHTML = filaMensaje('No hay productos en la copia local para este almacén.');
            return;
        }

        tbody.innerHTML = filas.map(function (p) {
            const saldo = Number(p.cantidad) || 0;
            const bajo = (Number(p.minima) || 0) > 0 && saldo <= Number(p.minima);
            const buscar = (String(p.codigo) + ' ' + String(p.nombre)).toLowerCase();
            return '' +
                '<tr class="alm-row ' + (bajo ? 'alm-row-bajo' : '') + '" data-offline="1" data-buscar="' + esc(buscar) + '">' +
                '<td class="alm-td-codigo" style="font-family:monospace;font-weight:700;color:#0f172a;white-space:nowrap;">' + esc(p.codigo) + '</td>' +
                '<td class="alm-td-nombre" data-codigo="' + esc(p.codigo) + '" style="font-weight:600;color:#1e293b;">' + esc(p.nombre) + '</td>' +
                '<td class="alm-td-cat" style="color:#475569;">' + (p.categoria ? esc(p.categoria) : '—') + '</td>' +
                '<td class="alm-td-stock" style="text-align:center;font-weight:800;font-size:15px;color:#0f172a;">' + fmt(saldo) + '<span class="alm-stock-um">' + esc(p.um) + '</span>' +
                    (bajo ? ' <i class="material-icons" style="font-size:14px;color:#f59e0b;vertical-align:middle;" title="Stock en o por debajo del mínimo">warning</i>' : '') +
                '</td>' +
                '<td class="alm-td-cant" style="text-align:center;color:#cbd5e0;">—</td>' +
                '<td class="alm-td-det" style="text-align:center;color:#cbd5e0;">—</td>' +
                '</tr>';
        }).join('');

        aplicarFiltroBuscador();
    }

    // (Re)inicializa en cada carga/navegación: registra el render (por clave, sobreescribe)
    // y cablea el buscador. Si el modo offline ya está activo (se navegó sin internet),
    // pinta de una.
    function init() {
        if (!getBody()) return;      // no estamos en la página de inventario
        OM.registrar('almacen', function () { OM.conOfflineDB(render); });
        const inp = document.getElementById('almFiltroBuscar');
        if (inp && !inp.dataset.offWired) {
            inp.dataset.offWired = '1';
            inp.addEventListener('input', function () { if (OM.estaActivo()) aplicarFiltroBuscador(); });
        }
        if (OM.estaActivo()) OM.conOfflineDB(render);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
