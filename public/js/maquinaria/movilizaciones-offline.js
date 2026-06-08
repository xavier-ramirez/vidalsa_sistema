/**
 * Movilizaciones — render OFFLINE (Fase 1: consulta sin internet)
 * --------------------------------------------------------------
 * Llena la tabla del historial (#movilizacionesTableBody) desde IndexedDB. NO se activa
 * solo: se registra en OfflineMode y pinta al tocar "Trabajar sin conexión". SOLO LECTURA.
 * Las fotos y el acta PDF no viajan offline.
 *
 * Global + (re)init en DOMContentLoaded y 'spa:contentLoaded'; render() reconsulta el DOM.
 */
(function () {
    'use strict';

    const OM = window.OfflineMode;
    if (!OM) return;
    const esc = OM.esc;
    const COLS = 5;

    function getBody() { return document.getElementById('movilizacionesTableBody'); }

    function aplicarFiltro() {
        const tbody = getBody(); if (!tbody) return;
        const inp = document.getElementById('searchInput');
        const q = (inp && inp.value ? inp.value : '').trim().toLowerCase();
        tbody.querySelectorAll('tr[data-offline]').forEach(function (tr) {
            const blob = tr.getAttribute('data-buscar') || '';
            tr.style.display = (!q || blob.indexOf(q) >= 0) ? '' : 'none';
        });
    }

    function fila(m) {
        const esAct = (m.tipo === 'RECEPCION_DIRECTA' || m.tipo === 'ACT.');
        const estadoTxt = esAct ? 'ACTUALIZACIÓN DE UBICACIÓN' : 'MOVILIZACIÓN';
        const estadoColor = esAct ? '#3730a3' : '#1e40af';
        const estadoIcon = esAct ? 'input' : 'swap_horiz';
        const buscar = [m.equipo_serial, m.equipo_placa, m.equipo_codigo, m.codigo, m.origen, m.destino, m.usuario, m.auxiliar]
            .filter(Boolean).join(' ').toLowerCase();

        let equipoCell;
        if (m.id_equipo) {
            equipoCell =
                '<span style="font-size:13px;color:#718096;font-weight:700;text-transform:uppercase;">' + esc(m.equipo_tipo || 'N/A') + '</span>' +
                '<div style="color:#4a5568;font-size:13px;"><strong>S:</strong> ' + esc(m.equipo_serial || 'S/S') + '</div>' +
                '<div style="color:var(--maquinaria-blue);font-size:13px;"><strong>P:</strong> ' + esc(m.equipo_placa || 'S/P') + '</div>' +
                '<div style="color:#2d3748;font-size:13px;font-weight:600;"><strong>ID:</strong> ' + esc(m.equipo_codigo || 'N/D') + '</div>';
        } else {
            equipoCell =
                '<span style="font-size:11px;color:#c2410c;font-weight:800;text-transform:uppercase;">AUXILIAR</span>' +
                '<div style="color:#475569;font-size:12.5px;text-transform:uppercase;">' + esc(m.auxiliar || '—') + '</div>';
        }

        return '' +
            '<tr data-offline="1" data-buscar="' + esc(buscar) + '">' +
            '<td class="mv-td-equipo"><div style="display:flex;align-items:center;gap:10px;">' +
                '<div style="width:50px;height:35px;border-radius:4px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#cbd5e0;flex-shrink:0;border:1px dashed #e2e8f0;"><i class="material-icons" style="font-size:20px;">image_not_supported</i></div>' +
                '<div style="display:flex;flex-direction:column;min-width:0;">' + equipoCell + '</div>' +
            '</div></td>' +
            '<td class="mv-td-trayecto"><div style="display:flex;align-items:center;justify-content:center;gap:12px;">' +
                '<div style="display:flex;flex-direction:column;align-items:center;max-width:160px;text-align:center;"><span style="font-size:11px;color:#64748b;font-weight:800;text-transform:uppercase;">Origen</span><span style="font-weight:600;color:#4a5568;font-size:13px;">' + esc(m.origen || 'Sin Origen') + '</span></div>' +
                '<i class="material-icons" style="font-size:18px;color:#cbd5e0;">east</i>' +
                '<div style="display:flex;flex-direction:column;align-items:center;max-width:160px;text-align:center;"><span style="font-size:11px;color:#0067b1;font-weight:800;text-transform:uppercase;">Destino</span><span style="font-weight:700;color:var(--maquinaria-dark-blue);font-size:13px;">' + esc(m.destino || 'Sin Destino') + '</span></div>' +
            '</div></td>' +
            '<td class="mv-td-fechas mv-mobile-hidden"><div style="display:flex;justify-content:center;"><div style="display:flex;align-items:center;gap:4px;background:#f1f5f9;padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;"><i class="material-icons" style="font-size:16px;color:#64748b;">event</i><span style="font-size:13px;color:#334155;font-weight:700;">' + esc(m.fecha || '--') + '</span></div></div></td>' +
            '<td class="mv-col-op mv-mobile-hidden"><div style="display:flex;flex-direction:column;align-items:center;gap:2px;">' +
                '<span style="font-weight:800;color:#0067b1;font-size:13px;">' + esc(m.codigo || '--') + '</span>' +
                '<div style="display:flex;align-items:center;gap:4px;color:#64748b;font-size:13px;font-weight:600;"><i class="material-icons" style="font-size:15px;">person</i>' + esc(m.usuario || '') + '</div>' +
            '</div></td>' +
            '<td class="mv-td-estado"><div style="display:flex;align-items:center;justify-content:center;gap:5px;font-size:11px;font-weight:800;color:' + estadoColor + ';">' +
                '<i class="material-icons" style="font-size:16px;">' + estadoIcon + '</i><span>' + estadoTxt + '</span>' +
            '</div></td>' +
            '</tr>';
    }

    async function render() {
        const tbody = getBody(); if (!tbody) return;
        const movils = await window.OfflineDB.get('movilizaciones').catch(() => []);

        if (!movils || !movils.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px;color:#94a3b8;"><i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">cloud_off</i>No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.</td></tr>';
            return;
        }

        tbody.innerHTML = movils.map(fila).join('');
        aplicarFiltro();
    }

    function init() {
        if (!getBody()) return;      // no estamos en /admin/movilizaciones
        OM.registrar('movilizaciones', function () { OM.conOfflineDB(render); });
        const inp = document.getElementById('searchInput');
        if (inp && !inp.dataset.offWiredMv) {
            inp.dataset.offWiredMv = '1';
            inp.addEventListener('input', function () { if (OM.estaActivo()) aplicarFiltro(); });
        }
        if (OM.estaActivo()) OM.conOfflineDB(render);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
