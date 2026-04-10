/**
 * Mantenimiento Index - Main module JS
 * Handles: tabs, report listing, consolidated view, stats, timeline search
 */
(function () {
    'use strict';

    // Shared across JS files via window
    window.currentReporteId = null;
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    /* ═══════════════════════════════════════════
       TABS
    ═══════════════════════════════════════════ */
    window.switchTab = function (tabId) {
        document.querySelectorAll('.mant-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.mant-panel').forEach(p => p.classList.remove('active'));
        document.querySelector(`.mant-tab[data-tab="${tabId}"]`)?.classList.add('active');
        const panel = document.getElementById('panel-' + tabId);
        if (panel) panel.classList.add('active');

        if (tabId === 'consolidado') cargarConsolidado();
    };

    /* ═══════════════════════════════════════════
       STATS
    ═══════════════════════════════════════════ */
    async function cargarStats() {
        try {
            const resp = await fetch('/admin/mantenimiento/stats', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await resp.json();
            const el = (id) => document.getElementById(id);
            if (el('statFallasAbiertas')) el('statFallasAbiertas').textContent = data.fallas_abiertas_hoy ?? 0;
            if (el('statInoperativos')) el('statInoperativos').textContent = data.equipos_inoperativos ?? 0;
            if (el('statResueltas')) el('statResueltas').textContent = data.fallas_resueltas_hoy ?? 0;
            if (el('statReportes')) el('statReportes').textContent = data.reportes_hoy ?? 0;
        } catch (e) {
            console.error('Error cargando stats:', e);
        }
    }

    /* ═══════════════════════════════════════════
       REPORTES DIARIOS
    ═══════════════════════════════════════════ */
    window.cargarReportes = async function () {
        const frente = document.getElementById('filterFrente')?.value || '';
        const fecha = document.getElementById('filterFecha')?.value || '';
        const estado = document.getElementById('filterEstado')?.value || '';
        // Store frente for use in fault registration
        window._mantCurrentFrente = frente;

        const params = new URLSearchParams();
        if (frente) params.set('frente', frente);
        if (fecha) params.set('fecha', fecha);
        if (estado) params.set('estado', estado);

        const container = document.getElementById('reportesTableContainer');
        if (container) container.style.opacity = '0.5';

        try {
            const resp = await fetch('/admin/mantenimiento/reportes?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });

            if (!resp.ok) {
                throw new Error('HTTP ' + resp.status);
            }

            const data = await resp.json();
            if (container) {
                container.innerHTML = data.html || '<div class="mant-empty"><i class="material-icons">folder_open</i><p>Sin resultados</p></div>';
                container.style.opacity = '1';
            }
            const pagEl = document.getElementById('reportesPagination');
            if (pagEl) pagEl.innerHTML = data.pagination || '';

            // Update stats
            const statReportes = document.getElementById('statReportes');
            if (statReportes) statReportes.textContent = data.total ?? 0;
        } catch (e) {
            console.error('Error cargando reportes:', e);
            if (container) {
                container.innerHTML = '<div class="mant-empty"><i class="material-icons">error</i><p>Error al cargar reportes. Verifica que las migraciones se hayan ejecutado.</p></div>';
                container.style.opacity = '1';
            }
        }
    };

    window.crearReporteHoy = async function () {
        const frenteSelect = document.getElementById('filterFrente');
        let frenteId = frenteSelect?.value;

        if (!frenteId) {
            if (window.showModal) {
                window.showModal({ type: 'warning', title: 'Selecciona un Frente', message: 'Debes seleccionar un frente de trabajo para crear el reporte del día.', confirmText: 'Aceptar', hideCancel: true });
            }
            return;
        }

        try {
            const resp = await fetch('/admin/mantenimiento/reporte-hoy', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ ID_FRENTE: frenteId }),
            });
            const data = await resp.json();
            if (data.success) {
                cargarReportes();
                if (data.reporte) verReporte(data.reporte.ID_REPORTE);
            }
        } catch (e) {
            console.error('Error creando reporte:', e);
        }
    };

    window.verReporte = async function (id) {
        window.currentReporteId = id;
        const card = document.getElementById('fallaDetailCard');
        const container = document.getElementById('fallasTableContainer');
        if (!card || !container) return;

        card.style.display = 'block';
        container.innerHTML = '<div class="mant-empty"><p>Cargando fallas...</p></div>';

        try {
            const resp = await fetch('/admin/mantenimiento/reporte/' + id, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await resp.json();
            container.innerHTML = data.html;

            // Update title
            const title = document.getElementById('fallaDetailTitle');
            if (title && data.reporte) {
                title.innerHTML = `<i class="material-icons">error_outline</i> ${data.reporte.FRENTE} — ${data.reporte.FECHA_REPORTE} (${data.reporte.total_fallas} fallas)`;
            }

            // Show/hide close button
            const btnCerrar = document.getElementById('btnCerrarReporte');
            if (btnCerrar && data.reporte) {
                btnCerrar.style.display = data.reporte.ESTADO_REPORTE === 'ABIERTO' ? 'inline-flex' : 'none';
            }

            // Update resolved stats
            const statResueltas = document.getElementById('statResueltas');
            if (statResueltas && data.reporte) statResueltas.textContent = data.reporte.resueltas ?? 0;

            // Scroll to detail
            card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (e) {
            console.error('Error cargando reporte:', e);
            container.innerHTML = '<div class="mant-empty"><p>Error al cargar el reporte</p></div>';
        }
    };

    window.cerrarReporteActual = function () {
        if (!window.currentReporteId) return;
        if (window.showModal) {
            window.showModal({
                type: 'warning',
                title: 'Cerrar Reporte',
                message: '¿Estás seguro de cerrar este reporte diario? No se podrán agregar más fallas.',
                confirmText: 'Sí, Cerrar',
                cancelText: 'Cancelar',
                onConfirm: async function () {
                    try {
                        await fetch('/admin/mantenimiento/reporte/' + window.currentReporteId + '/cerrar', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                        });
                        cargarReportes();
                        verReporte(window.currentReporteId);
                    } catch (e) {
                        console.error('Error cerrando reporte:', e);
                    }
                }
            });
        }
    };

    window.exportarPdfReporte = function () {
        if (!window.currentReporteId) return;
        // Submit as form POST for PDF download
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/admin/mantenimiento/reporte/' + window.currentReporteId + '/pdf';
        form.target = '_blank';
        const csrf = document.createElement('input');
        csrf.type = 'hidden'; csrf.name = '_token'; csrf.value = csrfToken;
        form.appendChild(csrf);
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    };

    window.exportarPdfConsolidado = function () {
        const fecha = document.getElementById('consolidadoFecha')?.value || new Date().toISOString().split('T')[0];
        window.open('/admin/mantenimiento/consolidado/pdf?fecha=' + fecha, '_blank');
    };

    /* ═══════════════════════════════════════════
       CONSOLIDADO
    ═══════════════════════════════════════════ */
    window.cargarConsolidado = async function () {
        const fecha = document.getElementById('consolidadoFecha')?.value || '';
        const container = document.getElementById('consolidadoContainer');
        if (!container) return;

        container.innerHTML = '<div class="mant-empty"><p>Cargando consolidado...</p></div>';

        try {
            const resp = await fetch('/admin/mantenimiento/consolidado?fecha=' + fecha, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await resp.json();
            container.innerHTML = data.html;
        } catch (e) {
            console.error('Error cargando consolidado:', e);
            container.innerHTML = '<div class="mant-empty"><p>Error al cargar</p></div>';
        }
    };

    /* ═══════════════════════════════════════════
       TIMELINE SEARCH
    ═══════════════════════════════════════════ */
    let timelineSearchTimeout = null;
    // Use event delegation so it works after SPA content replacement
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'timelineEquipoSearch') {
            clearTimeout(timelineSearchTimeout);
            timelineSearchTimeout = setTimeout(() => buscarEquipoTimeline(e.target.value), 400);
        }
    });

    async function buscarEquipoTimeline(query) {
        const resultsDiv = document.getElementById('timelineSearchResults');
        if (!query || query.length < 2) {
            if (resultsDiv) resultsDiv.style.display = 'none';
            return;
        }

        try {
            const resp = await fetch('/admin/mantenimiento/buscar-equipos?q=' + encodeURIComponent(query), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const equipos = await resp.json();

            if (resultsDiv && equipos.length > 0) {
                resultsDiv.style.display = 'block';
                resultsDiv.innerHTML = '<div class="mant-card" style="padding:0; overflow:hidden;">' +
                    equipos.slice(0, 8).map(eq =>
                        `<div style="padding:10px 16px; border-bottom:1px solid #f1f5f9; cursor:pointer; display:flex; align-items:center; gap:10px; transition:background 0.15s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'" onclick="cargarTimeline(${eq.ID_EQUIPO})">
                            <i class="material-icons" style="font-size:20px; color:#0067b1;">agriculture</i>
                            <div>
                                <div style="font-size:13px; font-weight:700; color:#1e293b;">${eq.tipo || ''} — ${eq.MARCA || ''} ${eq.MODELO || ''}</div>
                                <div style="font-size:11px; color:#64748b;">${eq.SERIAL_CHASIS || eq.CODIGO_PATIO || ''}</div>
                            </div>
                            <span class="badge-estado ${(eq.ESTADO_OPERATIVO || '').toLowerCase() === 'inoperativo' ? 'abierta' : 'resuelta'}" style="margin-left:auto;">${eq.ESTADO_OPERATIVO || ''}</span>
                        </div>`
                    ).join('') + '</div>';
            } else if (resultsDiv) {
                resultsDiv.style.display = 'block';
                resultsDiv.innerHTML = '<div class="mant-card" style="padding:14px; text-align:center; color:#94a3b8; font-size:13px;">No se encontraron equipos</div>';
            }
        } catch (e) {
            console.error('Error buscando equipo:', e);
        }
    }

    window.cargarTimeline = async function (equipoId) {
        const container = document.getElementById('timelineContainer');
        const resultsDiv = document.getElementById('timelineSearchResults');
        if (resultsDiv) resultsDiv.style.display = 'none';
        if (!container) return;

        container.innerHTML = '<div class="mant-empty"><p>Cargando timeline...</p></div>';

        try {
            const resp = await fetch('/admin/mantenimiento/timeline/' + equipoId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await resp.json();
            container.innerHTML = data.html;
        } catch (e) {
            console.error('Error cargando timeline:', e);
            container.innerHTML = '<div class="mant-empty"><p>Error al cargar timeline</p></div>';
        }
    };

    /* ═══════════════════════════════════════════
       LOCAL FILTER (Client-side search in table)
    ═══════════════════════════════════════════ */
    window.filtrarTablaLocal = function (query) {
        query = query.toLowerCase();
        document.querySelectorAll('.mant-table tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    };

    /* ═══════════════════════════════════════════
       INIT
    ═══════════════════════════════════════════ */
    cargarStats();
    cargarReportes();

})();
