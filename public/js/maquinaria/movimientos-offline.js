/**
 * Movimientos (kardex de almacén) — render OFFLINE (consulta sin internet, filtros COMPLETOS)
 * -------------------------------------------------------------------------------------------
 * Llena la bitácora (#almMovTableBody) desde IndexedDB con la copia de offline-sync.js.
 * NO se activa solo: se registra en OfflineMode y pinta al tocar "Trabajar sin conexión".
 * SOLO LECTURA — los PDFs de nota y los botones de deshacer/purgar no viajan offline.
 *
 * MOTOR DE FILTROS LOCAL: toda la UI de filtros online converge en window.loadMovimientos()
 * (inline de movimientos.blade.php). Con el modo offline activo se INTERCEPTA y se filtra
 * la copia local leyendo el mismo estado del DOM que usa el online: los hidden de los
 * custom-dropdowns ([name=id_almacen|tipo|id_frente][data-filter-value]), #almMovSearch
 * (o window.almMovPickedIds si se clickeó una sugerencia = filtro por ID de producto),
 * #almMovDesde/#almMovHasta y #almMovNota. Semántica replicada del backend
 * (AlmacenController::movimientos): ENTRADAS/SALIDAS pliegan traspasos y AJUSTES por su
 * signo (resultante vs anterior); nota = LIKE. La fila replica kardex_rows degradada:
 * sin hora, sin usuario/observaciones (no viajan en el snapshot) y la nota sin link PDF.
 *
 * Global + (re)init en DOMContentLoaded y 'spa:contentLoaded'; render() reconsulta el DOM.
 */
(function () {
    'use strict';

    const OM = window.OfflineMode;
    if (!OM) return;
    const esc = OM.esc;
    const COLS = 6;
    // Filas por lote: el mismo per_page del kardex online (AlmacenController::movimientos).
    const PAGE_SIZE = 50;

    // Réplica de MovimientoInventario::TIPO_META — [label, color, icono].
    const TIPO_META = {
        'ENTRADA':          ['Entrada',   '#16a34a', 'add'],
        'TRASPASO_ENTRADA': ['Entrada',   '#16a34a', 'south_west'],
        'SALIDA':           ['Salida',    '#dc2626', 'remove'],
        'TRASPASO_SALIDA':  ['Salida',    '#dc2626', 'north_east'],
        'AJUSTE':           ['Auditoría', '#0067b1', 'fact_check'],
    };
    const TIPO_META_DEFAULT = ['?', '#475569', 'swap_vert'];

    function getBody() { return document.getElementById('almMovTableBody'); }

    // Helpers compartidos del controlador offline (fuente única, ver estructura_base):
    // norm = normalización de búsqueda; fmt = misma presentación numérica que el kardex
    // online (coma decimal, punto de miles).
    const norm = OM.norm;
    const fmt  = OM.fmt;

    function fechaLatina(iso) { // 'YYYY-MM-DD' → 'DD/MM/YYYY'
        const p = String(iso || '').slice(0, 10).split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : (iso || '--');
    }

    // ── Estado de filtros leído del DOM (mismos controles que usa el online) ─────
    function estadoFiltros() {
        const hv = function (name) {
            const e = document.querySelector('[name="' + name + '"][data-filter-value]');
            const v = e ? String(e.value).trim() : '';
            return (v && v !== 'all') ? v : '';
        };
        const val = function (id) {
            const e = document.getElementById(id);
            return e && e.value ? String(e.value).trim() : '';
        };
        return {
            idAlm:    hv('id_almacen') ? parseInt(hv('id_almacen'), 10) : null,
            tipo:     hv('tipo'),                 // '' | 'ENTRADAS' | 'SALIDAS' | TIPO exacto
            idFrente: hv('id_frente') ? parseInt(hv('id_frente'), 10) : null,
            desde:    val('almMovDesde'),         // YYYY-MM-DD
            hasta:    val('almMovHasta'),
            nota:     norm(val('almMovNota')),
            // CSV de IDs del producto elegido en el buscador (una descripción puede tener
            // varias presentaciones). Mismo estado que usa el online en buildParams.
            pickedIds: (String(window.almMovPickedIds || '')
                .split(',').map(function (x) { return parseInt(x, 10); })
                .filter(function (n) { return n > 0; })),
            q:        norm(val('almMovSearch')),
        };
    }

    // ¿El movimiento cuenta como entrada? (AJUSTE por signo, igual que el backend.)
    function esEntrada(m) {
        if (m.tipo === 'ENTRADA' || m.tipo === 'TRASPASO_ENTRADA') return true;
        if (m.tipo === 'AJUSTE') return (Number(m.resultante) || 0) > (Number(m.anterior) || 0);
        return false;
    }
    function esSalida(m) {
        if (m.tipo === 'SALIDA' || m.tipo === 'TRASPASO_SALIDA') return true;
        if (m.tipo === 'AJUSTE') return (Number(m.resultante) || 0) < (Number(m.anterior) || 0);
        return false;
    }

    function filtrar(movs, f) {
        return movs.filter(function (m) {
            if (f.idAlm && m.id_almacen !== f.idAlm) return false;
            if (f.tipo === 'ENTRADAS') { if (!esEntrada(m)) return false; }
            else if (f.tipo === 'SALIDAS') { if (!esSalida(m)) return false; }
            else if (f.tipo && m.tipo !== f.tipo) return false; // TIPO exacto (link viejo)
            if (f.idFrente && m.id_frente !== f.idFrente) return false;
            const dia = String(m.fecha || '').slice(0, 10);
            if (f.desde && dia < f.desde) return false;
            if (f.hasta && dia > f.hasta) return false;
            if (f.nota && norm(m.nota).indexOf(f.nota) < 0) return false;
            // Producto elegido = filtro por ID (igual que el online); si no hay pick, texto libre.
            if (f.pickedIds.length) { if (f.pickedIds.indexOf(m.id_producto) < 0) return false; }
            else if (f.q && (norm(m.codigo) + ' ' + norm(m.producto)).indexOf(f.q) < 0) return false;
            return true;
        });
    }

    function fila(m) {
        const meta = TIPO_META[m.tipo] || TIPO_META_DEFAULT;
        const entra = esEntrada(m);
        // AJUSTE: la magnitud es el delta |resultante − anterior|; el resto usa CANTIDAD.
        const mag = m.tipo === 'AJUSTE'
            ? Math.abs((Number(m.resultante) || 0) - (Number(m.anterior) || 0))
            : (Number(m.cantidad) || 0);
        const signo = entra ? '+' : '−';
        return '' +
            '<tr class="alm-mov-row" data-offline="1" style="--mov-color:' + meta[1] + '">' +
            '<td class="mv-td-fecha" data-label="Fecha" style="white-space:nowrap;line-height:1.6;">' +
                '<div>' + fechaLatina(m.fecha) + '</div>' +
                '<span class="mv-tipo-inline" style="display:inline-flex;align-items:center;gap:3px;color:' + meta[1] + ';font-weight:700;font-size:11px;margin-top:3px;">' +
                    '<i class="material-icons" style="font-size:13px;">' + meta[2] + '</i>' + meta[0] +
                '</span>' +
            '</td>' +
            '<td class="col-producto mv-td-producto" data-label="Producto" style="font-weight:400;font-size:12.5px;">' +
                (m.codigo ? '<span style="color:#0f172a;">' + esc(m.codigo) + '</span> ' : '') +
                esc(m.producto || '—') +
            '</td>' +
            '<td class="mv-td-cantidad" data-label="Cantidad" style="font-weight:800;color:' + (entra ? '#16a34a' : '#dc2626') + ';white-space:nowrap;">' +
                signo + fmt(mag) + ' <span style="color:#64748b;font-weight:600;font-size:10.5px;">' + esc(m.um || '') + '</span>' +
            '</td>' +
            '<td class="mv-td-stock" data-label="Stock" title="Antes: ' + fmt(m.anterior) + ' → Después: ' + fmt(m.resultante) + '" style="white-space:nowrap;">' + fmt(m.resultante) + '</td>' +
            '<td class="mv-td-destino" data-label="Destino" style="font-size:12.5px;">' + (m.frente ? esc(m.frente) : '—') + '</td>' +
            // Ref: solo el N° de nota, SIN link — el PDF vive en el servidor.
            '<td class="mv-td-ref" data-label="Ref">' + (m.nota ? '<span class="mv-nota-num">' + esc(m.nota) + '</span>' : '<span class="mv-ref-empty">—</span>') + '</td>' +
            '</tr>';
    }

    async function render() {
        const tbody = getBody(); if (!tbody) return;
        // Cancela el scroll infinito del pintado anterior: los caminos que terminan en un
        // MENSAJE no pasan por porLotes y dejarían su observador vivo.
        OM.detenerLotes(tbody);
        const movs = await window.OfflineDB.get('movimientos').catch(() => []);

        if (!movs || !movs.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px;color:#94a3b8;"><i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">cloud_off</i>No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.</td></tr>';
            return;
        }

        const filas = filtrar(movs, estadoFiltros());
        // El snapshot ya viene ordenado del más reciente al más viejo (orderByDesc ID).
        // Por lotes con scroll infinito (helper compartido) en vez de volcar los hasta
        // 1.500 movimientos de una vez: la primera pantalla aparece al instante.
        if (filas.length) OM.porLotes(tbody, filas, fila, PAGE_SIZE);
        else tbody.innerHTML = '<tr><td colspan="' + COLS + '" style="text-align:center;padding:36px 16px;color:#94a3b8;font-size:14px;"><i class="material-icons" style="font-size:40px;color:#cbd5e0;display:block;margin:0 auto 8px;">receipt_long</i>No hay movimientos en la copia local que coincidan con los filtros.</td></tr>';

        // Total + paginación: offline se pinta TODO lo filtrado (por lotes), sin páginas.
        const tot = document.getElementById('almMovTotal'); if (tot) tot.textContent = String(filas.length);
        const pag = document.getElementById('almMovPagination'); if (pag) pag.innerHTML = '';
    }

    function init() {
        if (!getBody()) return;      // no estamos en /admin/almacen/movimientos

        OM.registrar('movimientos', function () { return OM.conOfflineDB(render); });

        // Punto ÚNICO de intercepción: dropdowns, fechas, nota, buscador y paginación
        // terminan todos en loadMovimientos() — offline se filtra local en vez del fetch.
        // El inline del blade REDEFINE loadMovimientos en cada montaje SPA → re-parchear.
        if (typeof window.loadMovimientos === 'function' && window.loadMovimientos !== window._movOffPatchedLoad) {
            window._origLoadMovimientos = window.loadMovimientos;
            window._movOffPatchedLoad = function () {
                if (OM.estaActivo()) { OM.conOfflineDB(render); return; }
                // Sin conexión pero SIN activar el modo offline: bloqueamos el filtro (no
                // pegarle al servidor caído) y avisamos que pulse "Trabajar sin conexión".
                if (OM.pendienteActivar && OM.pendienteActivar()) { OM.avisarActivar(); return; }
                return window._origLoadMovimientos.apply(null, arguments);
            };
            window.loadMovimientos = window._movOffPatchedLoad;
        }

        if (OM.estaActivo()) OM.conOfflineDB(render);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
