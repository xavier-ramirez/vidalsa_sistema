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

    // Inyecta una sola vez la regla .eq-hide-mobile: la versión online la trae en el
    // <style> del partial, pero al repintar offline reemplazamos el tbody y ese style
    // desaparece. Sin esto, las tarjetas offline mostrarían CATEGORÍA/MODELO/AÑO que la
    // online oculta en móvil → diseño distinto.
    function ensureHideStyle() {
        if (document.getElementById('eqOfflineHideStyle')) return;
        const st = document.createElement('style');
        st.id = 'eqOfflineHideStyle';
        st.textContent = '@media(max-width:900px){.eq-hide-mobile{display:none!important;}}';
        document.head.appendChild(st);
    }

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

        // FINALIZADO: mismo badge que online (wrapper flex centrado + icono ⚠).
        const finalizado = e.frente_finalizado
            ? '<div style="display:flex;align-items:center;justify-content:center;gap:3px;margin-top:3px;">' +
                '<span style="background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:8px;font-size:9px;font-weight:700;display:inline-flex;align-items:center;gap:2px;border:1px solid #fecaca;">' +
                    '<i class="material-icons" style="font-size:10px;">warning</i>FINALIZADO</span>' +
              '</div>' : '';
        const etiqueta = e.etiqueta
            ? '<span style="font-weight:700;color:var(--maquinaria-blue);margin-left:6px;white-space:nowrap;"><i class="material-icons" style="font-size:13px;vertical-align:-2px;">tag</i>' + esc(e.etiqueta) + '</span>' : '';
        // eq-hide-mobile: CATEGORIA/MODELO/AÑO se OCULTAN en móvil (≤900px), igual que la
        // tabla online (partials/table_rows.blade.php) — así la tarjeta offline luce idéntica.
        const categoria = e.categoria
            ? '<div class="eq-hide-mobile" style="font-size:12px;color:#64748b;font-weight:600;text-transform:uppercase;margin-top:5px;letter-spacing:0.3px;">' + esc(e.categoria) + '</div>' : '';
        const modelo = e.modelo ? '<div class="eq-hide-mobile" style="font-size:13.5px;color:#475569;font-weight:500;text-transform:uppercase;margin-top:4px;line-height:1.3;">' + esc(e.modelo) + '</div>' : '';
        const anio = e.anio ? '<div class="eq-hide-mobile" style="font-size:12.5px;color:#64748b;margin-top:5px;font-weight:500;">Año: ' + esc(e.anio) + '</div>' : '';
        const motor = e.serial_motor ? '<div style="line-height:1.5;margin-top:3px;word-break:break-all;"><strong style="color:#64748b;">M:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_motor) + '</span></div>' : '';
        const placa = e.placa
            ? '<div style="line-height:1.4;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><strong style="color:#64748b;">P:</strong> <span style="color:var(--maquinaria-blue);font-weight:700;text-transform:uppercase;">' + esc(e.placa) + '</span></div>'
            : '<div style="line-height:1.4;margin-top:3px;"><strong style="color:#64748b;">P:</strong> <span style="color:#a0aec0;font-style:italic;">Sin Placa</span></div>';

        return '' +
            '<tr data-offline="1" data-buscar="' + esc(buscar) + '">' +
            '<td class="table-cell-custom table-cell-center" style="padding:6px 4px;width:150px;">' +
                '<div class="tooltip-wrapper" style="font-size:13px;color:#000;margin-bottom:5px;line-height:1.25;font-weight:700;text-align:center;text-transform:uppercase;word-wrap:break-word;position:relative;cursor:default;">' + esc(e.frente || 'SIN ASIGNAR') + finalizado + '</div>' +
                '<div class="table-image-wrapper placeholder"><span class="material-icons">image_not_supported</span></div>' +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:14.5px;color:#000;word-wrap:break-word;">' +
                '<div style="font-weight:700;text-transform:uppercase;line-height:1.3;">' + esc(e.tipo || '—') + etiqueta + '</div>' + categoria +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:13px;color:#000;word-wrap:break-word;">' +
                '<div style="font-weight:700;text-transform:uppercase;line-height:1.3;">' + esc(e.marca || '—') + '</div>' + modelo + anio +
            '</td>' +
            '<td class="table-cell-custom" style="font-size:14px;color:#4a5568;">' +
                '<div style="line-height:1.5;word-break:break-all;"><strong style="color:#64748b;">S:</strong> <span style="color:#1e293b;font-weight:600;text-transform:uppercase;">' + esc(e.serial_chasis || '—') + '</span></div>' +
                motor + placa +
                '<div style="line-height:1.4;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><strong style="color:#64748b;">ID:</strong> <span style="color:#1e293b;font-weight:600;">#' + esc(e.codigo_patio || '—') + '</span></div>' +
            '</td>' +
            // Estatus: mismo look que el trigger online (chip blanco + chevron + sombra).
            // Offline es SOLO LECTURA (no se puede cambiar sin internet) → sin onclick; el
            // chevron queda por paridad visual con la versión online.
            '<td class="table-cell-custom" style="padding:8px 2px;width:145px;">' +
                '<div title="Estado (solo lectura sin conexión)" style="padding:6px 10px;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:5px;font-size:12.5px;font-weight:700;background:white;border:1px solid #e2e8f0;box-shadow:0 1px 2px rgba(0,0,0,0.05);">' +
                    '<div style="display:flex;align-items:center;gap:5px;color:' + est.color + ';">' +
                        '<i class="material-icons" style="font-size:16px;">' + est.icon + '</i><span style="color:#334155;text-transform:uppercase;">' + est.label + '</span>' +
                    '</div>' +
                    '<i class="material-icons" style="font-size:16px;color:#94a3b8;">expand_more</i>' +
                '</div>' +
            '</td>' +
            // Acciones: MISMO botón "Ver Detalles" que online (showDetailsImproved abre
            // #detailsModal, incluido en index.blade y cacheado por la PWA). Los data-* se
            // llenan con lo que hay en el snapshot; los campos no descargados (seguros, docs,
            // GPS) el modal los muestra como "N/A"/"Sin Documento" — degrada sin romper, NO
            // hace llamadas de red al abrir.
            '<td class="table-cell-center" style="padding:8px 5px;width:72px;text-align:center;vertical-align:middle;">' +
                '<div style="display:flex;justify-content:center;align-items:center;gap:4px;">' +
                    '<button type="button" class="btn-details-mini" title="Ver Detalles"' +
                        ' data-equipo-id="' + e.id + '"' +
                        ' data-codigo="' + esc(e.codigo_patio || '') + '"' +
                        ' data-chasis="' + esc(e.serial_chasis || '') + '"' +
                        ' data-placa="' + esc(e.placa || 'N/A') + '"' +
                        ' data-tipo="' + esc(e.tipo || 'SIN TIPO') + '"' +
                        ' data-anio="' + esc(e.anio || '') + '"' +
                        ' data-categoria="' + esc(e.categoria || '') + '"' +
                        ' onclick="showDetailsImproved(this, event)">' +
                        '<i class="material-icons">visibility</i>' +
                    '</button>' +
                '</div>' +
            '</td>' +
            '</tr>';
    }

    async function render() {
        const tbody = getBody(); if (!tbody) return;
        ensureHideStyle();
        const equipos = await window.OfflineDB.get('equipos').catch(() => []);

        if (!equipos || !equipos.length) {
            tbody.innerHTML = '<tr><td colspan="' + COLS + '" style="text-align:center;padding:40px;color:#94a3b8;"><i class="material-icons" style="font-size:42px;color:#cbd5e0;display:block;margin:0 auto 8px;">cloud_off</i>No hay copia local de datos todavía. Conéctate a internet una vez para descargarla.</td></tr>';
            return;
        }

        tbody.innerHTML = equipos.map(filaEquipo).join('');
        aplicarFiltro();
    }

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
