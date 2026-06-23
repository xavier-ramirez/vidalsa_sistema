/* fallas_index.js â€” MÃ³dulo Control de Fallas
 * PatrÃ³n global idÃ©ntico al de equipos_index.js / movilizaciones_index.js.
 * Las rutas se leen de window.FALLAS_CFG, definido en el Blade del mÃ³dulo.
 */
(function () {
    if (window._fallasReady) return;
    window._fallasReady = true;

    const cfg  = () => window.FALLAS_CFG || {};
    const csrf = window.getCsrf; // helper central (dom_helpers.js)

    // Aviso moderno (modal de la app) cuando el usuario no tiene permiso para
    // registrar fallas — en vez del alert() del navegador. Mismo patrón que equipos.
    window.flAccesoDenegado = function () {
        const msg = 'No tienes los permisos requeridos para registrar fallas.';
        if (window.showModal) {
            window.showModal({ type: 'error', title: 'Acceso Denegado', message: msg, confirmText: 'Entendido', hideCancel: true });
        } else if (window.showToast) {
            window.showToast(msg, 'error');
        } else {
            alert(msg);
        }
    };

    // â”€â”€â”€ Listado: AJAX recarga â”€â”€â”€
    window.cargarFallas = function () {
        const params = new URLSearchParams();
        const sv   = document.getElementById('fallasSearch')?.value || '';
        const es   = document.getElementById('fallasEstatus')?.value || '';
        const ta   = document.getElementById('fallasTipoActivo')?.value || '';
        const fr   = document.getElementById('fallasFrente')?.value || '';
        const resp = document.getElementById('fallasResponsable')?.value || '';
        const marca= document.getElementById('fallasMarca')?.value || '';
        const mod  = document.getElementById('fallasModelo')?.value || '';
        const fd   = document.getElementById('fallasFechaDesde')?.value || '';
        const fh   = document.getElementById('fallasFechaHasta')?.value || '';
        if (sv)   params.set('search', sv);
        if (es)   params.set('estatus', es);
        if (ta)   params.set('tipo_activo', ta);
        if (fr)   params.set('id_frente', fr);
        if (resp) params.set('responsable', resp);
        if (marca)params.set('marca', marca);
        if (mod)  params.set('modelo', mod);
        if (fd)   params.set('fecha_desde', fd);
        if (fh)   params.set('fecha_hasta', fh);
        // Indicador visual del botÃ³n avanzado
        const hasAdv = es || ta || resp || marca || mod || fd || fh;
        const advBtn = document.getElementById('fallasAdvBtn');
        if (advBtn) {
            advBtn.style.background = hasAdv ? '#fee2e2' : 'white';
            advBtn.style.border     = '1px solid ' + (hasAdv ? '#ef4444' : '#cbd5e0');
            advBtn.style.color      = hasAdv ? '#ef4444' : '#64748b';
        }

        if (window.showPreloader) window.showPreloader();
        fetch(cfg().urlIndex + '?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('fallasTableBody').innerHTML = data.html;
            document.getElementById('fallasPagination').innerHTML = data.pagination || '';
            if (data.stats) {
                // Consolidado: Total / Abiertos / Cerrados (los ids pueden no existir en móvil).
                const st = document.getElementById('statTotal');
                const sa = document.getElementById('statAbiertos');
                const sc = document.getElementById('statCerrados');
                if (st) st.textContent = data.stats.total_reportes;
                if (sa) sa.textContent = data.stats.reportes_abiertos;
                if (sc) sc.textContent = data.stats.reportes_cerrados;
                // Gráfico de mantenimiento (dona) — actualiza ambos paneles (móvil + desktop).
                window.flRenderMantChart(data.stats.preventivo, data.stats.correctivo, data.stats.rapidos);
            }
        })
        .catch(e => console.error('cargarFallas:', e))
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
    };

    // â”€â”€â”€ Dona de tipo de mantenimiento (Consolidado) â”€â”€â”€
    // Recalcula conic-gradient + leyenda para TODAS las instancias (.fmc-*),
    // de modo que sirve igual para el panel mÃ³vil y el de escritorio.
    // Colores: deben coincidir con partials/mant_chart.blade.php.
    window.flRenderMantChart = function (prev, corr, rap) {
        prev = +prev || 0; corr = +corr || 0; rap = +rap || 0;
        const tot = prev + corr + rap;
        const base = Math.max(1, tot);
        const pPct = (prev / base * 100).toFixed(2);
        const cPct = ((prev + corr) / base * 100).toFixed(2);
        const grad = tot > 0
            ? `conic-gradient(#34d399 0 ${pPct}%, #fbbf24 ${pPct}% ${cPct}%, #60a5fa ${cPct}% 100%)`
            : 'conic-gradient(rgba(255,255,255,0.15) 0 100%)';
        document.querySelectorAll('.fmc-donut').forEach(d => { d.style.background = grad; });
        document.querySelectorAll('.fmc-total').forEach(e => { e.textContent = tot; });
        document.querySelectorAll('.fmc-prev').forEach(e => { e.textContent = prev; });
        document.querySelectorAll('.fmc-corr').forEach(e => { e.textContent = corr; });
        document.querySelectorAll('.fmc-rap').forEach(e => { e.textContent = rap; });
    };

    // â”€â”€â”€ Limpiar filtros avanzados â”€â”€â”€
    // Los dropdowns son custom-dropdown (estilo /admin/equipos): para resetearlos
    // se debe usar window.clearDropdownFilter para que tambien se limpie el
    // placeholder, el clear-btn y el highlight visual del trigger.
    window.flClearAdv = function () {
        const advDDs = ['fallasEstatusDD','fallasTipoActivoDD','fallasResponsableDD','fallasMarcaDD','fallasModeloDD'];
        advDDs.forEach(ddId => {
            if (document.getElementById(ddId) && typeof window.clearDropdownFilter === 'function') {
                window.clearDropdownFilter(ddId);
            }
        });
        // Inputs nativos (date)
        ['fallasFechaDesde','fallasFechaHasta'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
        const panel = document.getElementById('fallasAdvPanel');
        if (panel) panel.style.display = 'none';
        window.cargarFallas();
    };

    // Cerrar panel avanzado al hacer clic fuera
    document.addEventListener('click', function (e) {
        const panel = document.getElementById('fallasAdvPanel');
        const btn   = document.getElementById('fallasAdvBtn');
        if (panel && panel.style.display !== 'none') {
            if (!panel.contains(e.target) && btn && !btn.contains(e.target)) {
                panel.style.display = 'none';
            }
        }
    });

    // Mostrar/ocultar el 'x' del buscador
    document.addEventListener('input', function (e) {
        if (e.target && e.target.id === 'fallasSearch') {
            const clr = document.getElementById('fallasSearchClear');
            if (clr) clr.style.display = e.target.value ? 'block' : 'none';
        }
    });

    // ─── Modal "Nuevo Reporte de Falla" ───
    // Su lógica (open/close/setTipo/buscador/select/submit) vive en el módulo
    // COMPARTIDO public/js/maquinaria/falla_create_modal.js (lo usan también
    // /admin/equipos y /admin/equipos-auxiliares). Aquí solo se configura vía
    // window.FALLA_MODAL_CFG (en el Blade) con onCreated = recargar el listado.


    // ─── Cerrar reporte (modal con observaciones; el activo siempre vuelve a OPERATIVO) ───
    let _cierreId = null;

    // Recibe el botón "Cerrar" y lee del dataset los datos del reporte (incluido
    // lo ya cargado en la sección Taller) para PRE-LLENAR el modal de cierre.
    window.cerrarFalla = function (btn) {
        const d = (btn && btn.dataset) ? btn.dataset : {};
        const id     = d.id;
        const codigo = d.codigo || '';
        const equipo = d.equipo || '';
        const tipo   = d.tipo   || '';
        _cierreId = id;

        const msg = document.getElementById('cierreInfoMsg');
        if (msg) {
            // El código RF solo identifica a las actas (extenso); en el reporte
            // rápido se muestra el equipo (mismo criterio que la lista).
            var esActa = tipo === 'extenso';
            var ref    = (esActa && codigo) ? codigo : (equipo || ('#' + id));
            var extra  = (esActa && equipo) ? (' &middot; ' + equipo) : '';
            msg.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle; color:#d97706;">report_problem</i> Cerrando reporte <strong>' + ref + '</strong>' + extra;
        }

        document.getElementById('cierreObservaciones').value = '';

        // Sección Taller: solo en actas (extenso). Se PRE-LLENA con lo ya cargado
        // al crear el reporte — el cierre es para revisar/confirmar y completar lo
        // que falte (el reporte se pudo crear sin estos datos).
        const taller = document.getElementById('cierreTallerFields');
        if (taller) taller.style.display = tipo === 'extenso' ? 'flex' : 'none';
        const setVal = (fid, val) => { const el = document.getElementById(fid); if (el) el.value = val || ''; };
        setVal('cierreMecanico',       d.mecanico);
        setVal('cierreFechaRecepcion', d.fechaRecepcion);
        setVal('cierreDiagnostico',    d.diagnostico);
        setVal('cierreAcciones',       d.acciones);

        document.getElementById('cierreReporteOverlay').classList.add('active');
    };

    window.closeCierreModal = function () {
        document.getElementById('cierreReporteOverlay').classList.remove('active');
        _cierreId = null;
    };

    // â”€â”€â”€ Borrado DURO de un reporte (solo super.admin) â”€â”€â”€
    // Irreversible y sin rastro; pide confirmaciÃ³n con la referencia del reporte.
    window.eliminarFalla = function (btn) {
        const d   = (btn && btn.dataset) ? btn.dataset : {};
        const id  = d.id;
        const ref = d.ref || ('#' + id);
        if (!id) return;
        if (!confirm('Eliminar definitivamente el reporte ' + ref + '?\n\nEsta acciÃ³n es irreversible y no deja rastro.')) return;

        const fd = new FormData();
        fd.append('_method', 'DELETE');
        if (window.showPreloader) window.showPreloader();
        fetch(cfg().urlBase + '/' + id, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: fd
        })
        .then(r => r.json())
        .then(body => {
            if (body.success) {
                if (window.showToast) window.showToast(body.message, 'success');
                window.cargarFallas();
            } else {
                if (window.showToast) window.showToast(body.message || 'Error al eliminar', 'error');
            }
        })
        .catch(e => { console.error(e); if (window.showToast) window.showToast('Error de red', 'error'); })
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
    };

    window.submitCierreReporte = function () {
        if (!_cierreId) return;
        const btn = document.getElementById('btnConfirmarCierre');
        const fd = new FormData();
        fd.append('_method', 'PATCH');
        fd.append('observaciones_cierre', document.getElementById('cierreObservaciones').value);
        // Campos del taller (acta extensa): solo se envian si el bloque esta visible.
        const taller = document.getElementById('cierreTallerFields');
        if (taller && taller.style.display !== 'none') {
            fd.append('mecanico_asignado',   document.getElementById('cierreMecanico').value);
            fd.append('fecha_recepcion',     document.getElementById('cierreFechaRecepcion').value);
            fd.append('diagnostico',         document.getElementById('cierreDiagnostico').value);
            fd.append('acciones_realizadas', document.getElementById('cierreAcciones').value);
        }
        btn.disabled = true;
        if (window.showPreloader) window.showPreloader();
        fetch(cfg().urlBase + '/' + _cierreId + '/close', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: fd
        })
        .then(r => r.json())
        .then(body => {
            if (body.success) {
                if (window.showToast) window.showToast(body.message, 'success');
                window.closeCierreModal();
                window.cargarFallas();
            } else {
                if (window.showToast) window.showToast(body.message || 'Error al cerrar', 'error');
            }
        })
        .catch(e => { console.error(e); if (window.showToast) window.showToast('Error de red', 'error'); })
        .finally(() => { btn.disabled = false; if (window.hidePreloader) window.hidePreloader(); });
    };
})();
