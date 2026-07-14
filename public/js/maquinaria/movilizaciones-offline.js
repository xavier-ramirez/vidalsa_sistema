/**
 * Movilizaciones — render OFFLINE (consulta sin internet, filtros COMPLETOS)
 * --------------------------------------------------------------------------
 * Llena la tabla del historial (#movilizacionesTableBody) desde IndexedDB. NO se activa
 * solo: se registra en OfflineMode y pinta al tocar "Trabajar sin conexión". SOLO LECTURA.
 * Las fotos y el acta PDF no viajan offline.
 *
 * MOTOR DE FILTROS LOCAL: toda la UI de filtros online converge en
 * window.loadMovilizaciones() (movilizaciones_index.js). Con el modo offline activo se
 * INTERCEPTA y se filtra la copia local leyendo el mismo estado del DOM que usa el online:
 * #searchInput, hidden input[name=id_frente]/[name=id_tipo] (custom-dropdowns),
 * #filterFechaDesde/#filterFechaHasta y #filterDireccionFrente. Semántica replicada de
 * MovilizacionController@index: frente = origen O destino (o solo uno según dirección);
 * tipo = 'tipo_eq:N' (tipo de equipo) / 'tipo_aux:STR' (tipo de auxiliar) / N legado.
 * Los ids (id_origen/id_destino/id_tipo/aux_tipo) viajan en el snapshot schema-v2.
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

    // ── Estado de filtros leído del DOM (mismos controles que usa el online) ─────
    function estadoFiltros() {
        const mvCard = document.querySelector('.movilizaciones-main-card');
        const hv = function (name) {
            const el = (mvCard || document).querySelector('input[name="' + name + '"][data-filter-value]');
            const v = el ? String(el.value).trim() : '';
            return (v && v !== 'all') ? v : '';
        };
        const val = function (id) {
            const el = document.getElementById(id);
            return el && el.value ? String(el.value).trim() : '';
        };
        return {
            q:         val('searchInput').toLowerCase(),
            idFrente:  hv('id_frente') ? parseInt(hv('id_frente'), 10) : null,
            tipo:      hv('id_tipo'),
            desde:     val('filterFechaDesde'),   // YYYY-MM-DD
            hasta:     val('filterFechaHasta'),
            direccion: val('filterDireccionFrente'), // '' | 'entrada' | 'salida'
        };
    }

    function filtrar(movils, f) {
        return movils.filter(function (m) {
            if (f.q) {
                const blob = [m.equipo_serial, m.equipo_placa, m.equipo_codigo, m.codigo, m.origen, m.destino, m.usuario, m.auxiliar]
                    .filter(Boolean).join(' ').toLowerCase();
                if (blob.indexOf(f.q) < 0) return false;
            }
            if (f.idFrente) {
                // Igual que el backend: 'entrada' = destino, 'salida' = origen, sin
                // dirección = cualquiera de los dos.
                const esDestino = m.id_destino === f.idFrente;
                const esOrigen  = m.id_origen === f.idFrente;
                if (f.direccion === 'entrada' ? !esDestino
                    : f.direccion === 'salida' ? !esOrigen
                    : !(esDestino || esOrigen)) return false;
            }
            if (f.tipo) {
                if (f.tipo.indexOf('tipo_eq:') === 0) {
                    if (!m.id_equipo || m.id_tipo !== parseInt(f.tipo.slice(8), 10)) return false;
                } else if (f.tipo.indexOf('tipo_aux:') === 0) {
                    if (m.id_equipo || String(m.aux_tipo || '') !== f.tipo.slice(9)) return false;
                } else if (m.id_tipo !== parseInt(f.tipo, 10)) return false; // valor legado numérico
            }
            // fecha viene 'YYYY-MM-DD HH:MM' → comparar solo el día (rango inclusivo).
            const dia = String(m.fecha || '').slice(0, 10);
            if (f.desde && dia < f.desde) return false;
            if (f.hasta && dia > f.hasta) return false;
            return true;
        });
    }

    function fila(m) {
        const esAct = (m.tipo === 'RECEPCION_DIRECTA' || m.tipo === 'ACT.');
        const estadoTxt = esAct ? 'ACTUALIZACIÓN DE UBICACIÓN' : 'MOVILIZACIÓN';
        const estadoColor = esAct ? '#3730a3' : '#1e40af';
        const estadoIcon = esAct ? 'input' : 'swap_horiz';

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
            '<tr data-offline="1">' +
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

        const filas = filtrar(movils, estadoFiltros());
        if (!filas.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px;color:#94a3b8;">No hay movilizaciones en la copia local que coincidan con los filtros.</td></tr>';
            return;
        }
        tbody.innerHTML = filas.map(fila).join('');
    }

    function init() {
        if (!getBody()) return;      // no estamos en /admin/movilizaciones

        OM.registrar('movilizaciones', function () { OM.conOfflineDB(render); });

        // Punto ÚNICO de intercepción: buscador (oninput con debounce), dropdowns de
        // frente/tipo, fechas y dirección terminan todos en loadMovilizaciones() —
        // offline se filtra local en vez del fetch. Se re-parchea en cada init por si
        // otro script re-envolvió la función (p.ej. el hook de estilos de
        // movilizaciones_index.js).
        if (typeof window.loadMovilizaciones === 'function' && window.loadMovilizaciones !== window._mvOffPatchedLoad) {
            window._origLoadMovilizaciones = window.loadMovilizaciones;
            window._mvOffPatchedLoad = function () {
                if (OM.estaActivo()) { OM.conOfflineDB(render); return Promise.resolve(); }
                return window._origLoadMovilizaciones.apply(null, arguments);
            };
            window.loadMovilizaciones = window._mvOffPatchedLoad;
        }

        if (OM.estaActivo()) OM.conOfflineDB(render);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
    window.addEventListener('spa:contentLoaded', init);
})();
