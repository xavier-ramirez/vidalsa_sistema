/* fallas_index.js — Módulo Control de Fallas
 * Patrón global idéntico al de equipos_index.js / movilizaciones_index.js.
 * Las rutas se leen de window.FALLAS_CFG, definido en el Blade del módulo.
 */
(function () {
    if (window._fallasReady) return;
    window._fallasReady = true;

    const cfg  = () => window.FALLAS_CFG || {};
    const csrf = () => document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // ─── Listado: AJAX recarga ───
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
        // Indicador visual del botón avanzado
        const hasAdv = ta || fr || resp || marca || mod || fd || fh;
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
                document.getElementById('statTotal').textContent = data.stats.total;
                document.getElementById('statInoperativo').textContent = data.stats.inoperativo;
                document.getElementById('statMantenimiento').textContent = data.stats.mantenimiento;
                document.getElementById('statAbiertos').textContent = data.stats.reportes_abiertos;
            }
        })
        .catch(e => console.error('cargarFallas:', e))
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
    };

    // ─── Limpiar filtros avanzados ───
    window.flClearAdv = function () {
        ['fallasTipoActivo','fallasFrente','fallasResponsable'].forEach(id => {
            const el = document.getElementById(id); if (el) el.value = '';
        });
        ['fallasMarca','fallasModelo','fallasFechaDesde','fallasFechaHasta'].forEach(id => {
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

    // ─── Modal Nuevo Reporte ───
    window.openNuevoReporteModal = function () {
        document.getElementById('nuevoReporteOverlay').classList.add('active');
        // Reset
        document.getElementById('fl_search_activo').value = '';
        document.getElementById('fl_activo_seleccionado').style.display = 'none';
        document.getElementById('fl_activo_tipo').value = '';
        document.getElementById('fl_activo_id').value = '';
        document.getElementById('nuevoReporteForm').reset();
        window.flSetTipo('corto');
    };
    window.closeNuevoReporteModal = function () {
        document.getElementById('nuevoReporteOverlay').classList.remove('active');
    };

    window.flSetTipo = function (tipo) {
        document.getElementById('fl_tipo_reporte').value = tipo;
        document.querySelectorAll('#nuevoReporteForm .fl-toggle-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.tipo === tipo);
        });
        document.getElementById('fl_fields_extenso').style.display = tipo === 'extenso' ? 'flex' : 'none';
    };

    // Buscador de activos compartido (modal Nuevo + modal Cambio Estado)
    function _buildSearcher(prefix) {
        let timer = null;
        return function (q) {
            clearTimeout(timer);
            timer = setTimeout(() => {
                const resBox = document.getElementById(prefix + '_search_results');
                if (!q || q.length < 2) { resBox.style.display = 'none'; return; }
                fetch(cfg().urlSearch + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.results || !data.results.length) {
                        resBox.innerHTML = '<div style="padding:12px; text-align:center; color:#94a3b8; font-size:13px;">Sin resultados</div>';
                        resBox.style.display = 'block';
                        return;
                    }
                    resBox.innerHTML = data.results.map(r => {
                        const labelTipo = r.tipo === 'equipo' ? '🚛' : '🔧';
                        const placa  = r.placa  ? ('Placa: ' + r.placa)  : '';
                        const serial = r.serial ? ('S/N: '  + r.serial)  : '';
                        const fotoHtml = r.foto
                            ? `<img src="${r.foto.startsWith('http') || r.foto.startsWith('/') ? r.foto : '/' + r.foto}" alt="">`
                            : `<div style="width:36px;height:36px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#cbd5e0;"><i class="material-icons">image</i></div>`;
                        return `
                            <div class="fl-search-result" onclick="window.flSelectActivo('${prefix}', '${r.tipo}', ${r.id}, '${(r.label||'').replace(/'/g,"\\'")}', '${(placa||serial||'').replace(/'/g,"\\'")}')">
                                ${fotoHtml}
                                <div style="flex:1; min-width:0;">
                                    <div style="font-weight:700; color:#1e293b;">${labelTipo} ${r.label || '(sin marca/modelo)'}</div>
                                    <div style="font-size:12px; color:#64748b;">${placa} ${serial} · Estado: ${r.estado || '—'}</div>
                                </div>
                            </div>
                        `;
                    }).join('');
                    resBox.style.display = 'block';
                });
            }, 250);
        };
    }
    window.flSearchActivos   = _buildSearcher('fl');
    window.flSearchActivosCe = _buildSearcher('ce');

    window.flSelectActivo = function (prefix, tipo, id, label, info) {
        document.getElementById(prefix + '_activo_tipo').value = tipo;
        document.getElementById(prefix + '_activo_id').value = id;
        const box = document.getElementById(prefix + '_activo_seleccionado');
        box.innerHTML = '<strong>✓ Seleccionado:</strong> ' + label + ' <span style="color:#64748b; font-size:12px; margin-left:4px;">' + (info || '') + '</span>';
        box.style.display = 'block';
        document.getElementById(prefix + '_search_results').style.display = 'none';
        document.getElementById(prefix + '_search_activo').value = label;
    };

    window.submitNuevoReporte = function () {
        const form = document.getElementById('nuevoReporteForm');
        const fd = new FormData(form);
        if (!fd.get('activo_id')) {
            if (window.showToast) window.showToast('Selecciona un equipo primero.', 'error');
            return;
        }
        if (window.showPreloader) window.showPreloader();
        fetch(cfg().urlStore, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: fd
        })
        .then(r => r.json().then(b => ({status: r.status, body: b})))
        .then(({status, body}) => {
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message, 'success');
                window.closeNuevoReporteModal();
                window.cargarFallas();
                // Si es extenso, abre el PDF directamente
                if (body.falla && body.falla.TIPO_REPORTE === 'extenso' && body.falla.ID_FALLA) {
                    setTimeout(() => {
                        window.open(cfg().urlBase + '/' + body.falla.ID_FALLA + '/pdf', '_blank');
                    }, 300);
                }
            } else {
                if (window.showToast) window.showToast(body.message || 'No se pudo crear el reporte', 'error');
            }
        })
        .catch(e => { console.error(e); if (window.showToast) window.showToast('Error de red', 'error'); })
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
    };

    // ─── Modal Cambiar Estado ───
    window.openCambioEstadoModal = function () {
        document.getElementById('cambioEstadoOverlay').classList.add('active');
        document.getElementById('ce_search_activo').value = '';
        document.getElementById('ce_activo_seleccionado').style.display = 'none';
        document.getElementById('ce_activo_tipo').value = '';
        document.getElementById('ce_activo_id').value = '';
    };
    window.closeCambioEstadoModal = function () {
        document.getElementById('cambioEstadoOverlay').classList.remove('active');
    };

    window.submitCambioEstado = function () {
        const fd = new FormData(document.getElementById('cambioEstadoForm'));
        if (!fd.get('activo_id')) {
            if (window.showToast) window.showToast('Selecciona un equipo primero.', 'error');
            return;
        }
        if (window.showPreloader) window.showPreloader();
        fetch(cfg().urlChangeEstado, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: fd
        })
        .then(r => r.json().then(b => ({status: r.status, body: b})))
        .then(({status, body}) => {
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message, 'success');
                window.closeCambioEstadoModal();
                window.cargarFallas();
            } else {
                if (window.showToast) window.showToast(body.message || 'No se pudo cambiar el estado', 'error');
            }
        })
        .catch(e => { console.error(e); if (window.showToast) window.showToast('Error de red', 'error'); })
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
    };

    // ─── Cerrar reporte (modal con observaciones + opción restaurar) ───
    let _cierreId = null;

    window.cerrarFalla = function (id, codigo, equipo) {
        _cierreId = id;
        const msg = document.getElementById('cierreInfoMsg');
        if (msg) msg.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle; color:#d97706;">report_problem</i> Cerrando reporte <strong>' + (codigo || '#' + id) + '</strong>' + (equipo ? ' · ' + equipo : '');
        document.getElementById('cierreObservaciones').value = '';
        document.getElementById('cierreRestaurar').checked = true;
        document.getElementById('cierreReporteOverlay').classList.add('active');
    };

    window.closeCierreModal = function () {
        document.getElementById('cierreReporteOverlay').classList.remove('active');
        _cierreId = null;
    };

    window.submitCierreReporte = function () {
        if (!_cierreId) return;
        const btn = document.getElementById('btnConfirmarCierre');
        const fd = new FormData();
        fd.append('_method', 'PATCH');
        fd.append('restaurar_estado', document.getElementById('cierreRestaurar').checked ? '1' : '0');
        fd.append('observaciones_cierre', document.getElementById('cierreObservaciones').value);
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
