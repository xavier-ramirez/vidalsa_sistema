/**
 * Equipos — render OFFLINE (Fase 1: consulta sin internet)
 * --------------------------------------------------------
 * Llena la tabla (#equiposTableBody) desde IndexedDB, reproduciendo partials/table_rows.
 * NO se activa solo: se registra en OfflineMode y pinta al tocar "Trabajar sin conexión".
 * El botón de detalle abre el HISTORIAL DE MOVILIZACIONES del equipo (también local).
 * SOLO LECTURA. La foto no viaja offline (vive en Drive) → ícono placeholder.
 *
 * Global + (re)init en DOMContentLoaded y 'spa:contentLoaded'; render() reconsulta el DOM.
 */
(function () {
    'use strict';

    const OM = window.OfflineMode;
    if (!OM) return;
    const esc = OM.esc;
    const COLS = 6;
    let movilCache = null;

    const ESTADOS = {
        'OPERATIVO':        { color: '#16a34a', icon: 'check_circle', label: 'OPERATIVO' },
        'INOPERATIVO':      { color: '#dc2626', icon: 'cancel',       label: 'INOPERATIVO' },
        'EN MANTENIMIENTO': { color: '#d97706', icon: 'engineering',  label: 'MANTENIMIENTO' },
        'DESINCORPORADO':   { color: '#475569', icon: 'archive',      label: 'DESINCORP.' },
    };

    function getBody() { return document.getElementById('equiposTableBody'); }

    function aplicarFiltro() {
        const tbody = getBody(); if (!tbody) return;
        const inp = document.getElementById('searchInput');
        const q = (inp && inp.value ? inp.value : '').trim().toLowerCase();
        tbody.querySelectorAll('tr[data-offline]').forEach(function (tr) {
            const blob = tr.getAttribute('data-buscar') || '';
            tr.style.display = (!q || blob.indexOf(q) >= 0) ? '' : 'none';
        });
    }

    function filaEquipo(e) {
        const est = ESTADOS[e.estado] || ESTADOS['DESINCORPORADO'];
        const buscar = [e.serial_chasis, e.serial_motor, e.placa, e.etiqueta, e.marca, e.modelo, e.tipo, e.codigo_patio]
            .filter(Boolean).join(' ').toLowerCase();

        const finalizado = e.frente_finalizado
            ? '<div style="margin-top:3px;"><span style="background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;border:1px solid #fecaca;">FINALIZADO</span></div>' : '';
        const etiqueta = e.etiqueta
            ? '<span style="font-weight:700;color:var(--maquinaria-blue);margin-left:6px;white-space:nowrap;"><i class="material-icons" style="font-size:13px;vertical-align:-2px;">tag</i>' + esc(e.etiqueta) + '</span>' : '';
        const categoria = e.categoria
            ? '<div style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;margin-top:5px;">' + esc(e.categoria) + '</div>' : '';
        const modelo = e.modelo ? '<div style="font-size:13.5px;color:#475569;font-weight:500;text-transform:uppercase;margin-top:4px;">' + esc(e.modelo) + '</div>' : '';
        const anio = e.anio ? '<div style="font-size:12.5px;color:#64748b;margin-top:5px;">Año: ' + esc(e.anio) + '</div>' : '';
        const motor = e.serial_motor ? '<div style="margin-top:3px;"><strong style="color:#64748b;">M:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_motor) + '</span></div>' : '';
        const placa = e.placa
            ? '<div style="margin-top:3px;"><strong style="color:#64748b;">P:</strong> <span style="color:var(--maquinaria-blue);font-weight:700;text-transform:uppercase;">' + esc(e.placa) + '</span></div>'
            : '<div style="margin-top:3px;"><strong style="color:#64748b;">P:</strong> <span style="color:#a0aec0;font-style:italic;">Sin Placa</span></div>';
        const tituloHist = ((e.tipo || '') + ' ' + (e.etiqueta || e.codigo_patio || '')).replace(/'/g, '');

        return '' +
            '<tr data-offline="1" data-buscar="' + esc(buscar) + '">' +
            '<td class="table-cell-custom table-cell-center" style="padding:6px 4px;width:150px;">' +
                '<div style="font-size:13px;color:#000;margin-bottom:5px;font-weight:700;text-align:center;text-transform:uppercase;">' + esc(e.frente || 'SIN ASIGNAR') + finalizado + '</div>' +
                '<div class="table-image-wrapper placeholder"><span class="material-icons">image_not_supported</span></div>' +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:14.5px;color:#000;">' +
                '<div style="font-weight:700;text-transform:uppercase;line-height:1.3;">' + esc(e.tipo || '—') + etiqueta + '</div>' + categoria +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:13px;color:#000;">' +
                '<div style="font-weight:700;text-transform:uppercase;line-height:1.3;">' + esc(e.marca || '—') + '</div>' + modelo + anio +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:14px;color:#4a5568;">' +
                '<div style="line-height:1.5;word-break:break-all;"><strong style="color:#64748b;">S:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_chasis || '—') + '</span></div>' +
                motor + placa +
                '<div style="margin-top:3px;"><strong style="color:#64748b;">ID:</strong> <span style="color:#1e293b;font-weight:600;">#' + esc(e.codigo_patio || '—') + '</span></div>' +
            '</td>' +
            '<td class="table-cell-custom" style="padding:8px 2px;width:145px;">' +
                '<div style="padding:6px 10px;border-radius:8px;display:flex;align-items:center;gap:5px;font-size:12.5px;font-weight:700;background:white;border:1px solid #e2e8f0;color:' + est.color + ';text-transform:uppercase;">' +
                    '<i class="material-icons" style="font-size:16px;">' + est.icon + '</i><span style="color:#334155;">' + est.label + '</span>' +
                '</div>' +
            '</td>' +
            '<td class="table-cell-center" style="width:72px;text-align:center;">' +
                '<button type="button" class="btn-details-mini" title="Historial de movilizaciones" onclick="window.eqHistorialOffline(' + e.id + ',\'' + esc(tituloHist) + '\')"><i class="material-icons">history</i></button>' +
            '</td>' +
            '</tr>';
    }

    async function render() {
        const tbody = getBody(); if (!tbody) return;
        movilCache = null; // refresca el cache del historial al repintar
        const equipos = await window.OfflineDB.get('equipos').catch(() => []);

        if (!equipos || !equipos.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px;color:#94a3b8;"><i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">cloud_off</i>No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.</td></tr>';
            return;
        }

        tbody.innerHTML = equipos.map(filaEquipo).join('');
        aplicarFiltro();
    }

    // ── Historial de movilizaciones de un equipo (overlay offline) ──
    window.eqHistorialOffline = async function (idEquipo, titulo) {
        if (movilCache === null) movilCache = await window.OfflineDB.get('movilizaciones').catch(() => []);
        const items = (movilCache || []).filter(function (m) { return m.id_equipo === idEquipo; });

        let cuerpo;
        if (!items.length) {
            cuerpo = '<div style="padding:30px;text-align:center;color:#94a3b8;">Sin movilizaciones en la copia local para este equipo.</div>';
        } else {
            cuerpo = items.map(function (m) {
                const tipoTxt = (m.tipo === 'RECEPCION_DIRECTA' || m.tipo === 'ACT.') ? 'ACTUALIZACIÓN DE UBICACIÓN' : 'MOVILIZACIÓN';
                return '<div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;margin-bottom:8px;">' +
                    '<div style="display:flex;justify-content:space-between;font-size:12px;color:#64748b;font-weight:700;">' +
                        '<span>' + esc(m.codigo || '—') + '</span><span>' + esc(m.fecha || '') + '</span></div>' +
                    '<div style="margin-top:6px;font-size:13px;color:#1e293b;font-weight:600;">' +
                        esc(m.origen || 'Sin Origen') + ' <i class="material-icons" style="font-size:14px;vertical-align:middle;color:#cbd5e0;">east</i> ' + esc(m.destino || 'Sin Destino') + '</div>' +
                    '<div style="margin-top:4px;font-size:11px;color:#1e40af;font-weight:700;">' + tipoTxt + '</div>' +
                '</div>';
            }).join('');
        }

        let ov = document.getElementById('eqHistOverlay');
        if (!ov) {
            ov = document.createElement('div');
            ov.id = 'eqHistOverlay';
            ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;';
            ov.addEventListener('click', function (e) { if (e.target === ov) ov.remove(); });
            document.body.appendChild(ov);
        }
        ov.innerHTML =
            '<div style="background:#fff;border-radius:14px;max-width:520px;width:100%;max-height:80vh;overflow:hidden;display:flex;flex-direction:column;">' +
                '<div style="background:#1e293b;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;align-items:center;">' +
                    '<div style="font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px;"><i class="material-icons" style="font-size:20px;">history</i> Historial · ' + esc(titulo || '') + '</div>' +
                    '<i class="material-icons" style="cursor:pointer;" onclick="document.getElementById(\'eqHistOverlay\').remove()">close</i>' +
                '</div>' +
                '<div style="padding:14px 16px;overflow-y:auto;">' + cuerpo + '</div>' +
            '</div>';
    };

    function init() {
        if (!getBody()) return;      // no estamos en /admin/equipos
        OM.registrar('equipos', function () { OM.conOfflineDB(render); });
        const inp = document.getElementById('searchInput');
        if (inp && !inp.dataset.offWiredEq) {
            inp.dataset.offWiredEq = '1';
            inp.addEventListener('input', function () { if (OM.estaActivo()) aplicarFiltro(); });
        }
        if (OM.estaActivo()) OM.conOfflineDB(render);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
