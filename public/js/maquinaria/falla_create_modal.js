/* falla_create_modal.js — Modal "Nuevo Reporte de Falla" COMPARTIDO.
 *
 * Lo usan 3 páginas: /admin/fallas, /admin/equipos y /admin/equipos-auxiliares.
 * Cada página define window.FALLA_MODAL_CFG antes de cargar este script:
 *   {
 *     urlSearch: ruta de búsqueda de activos (solo se usa en modo buscador),
 *     urlStore:  ruta POST de creación de reporte,
 *     urlBase:   base de /admin/fallas (para abrir el PDF del acta),
 *     openPdf:   bool — abrir el PDF al crear un reporte extenso,
 *     onCreated: function(falla) — callback tras crear (recargar lista / marcar fila),
 *   }
 *
 * Para crear desde equipos/auxiliares con el equipo YA elegido:
 *   window.flOpenForActivo(tipo, id, label, onCancel)
 *   - oculta el buscador y pre-selecciona el activo.
 *   - onCancel() se dispara si se cierra el modal SIN crear (para revertir el estado).
 */
(function () {
    if (window._fallaCreateModalReady) return;
    window._fallaCreateModalReady = true;

    const CFG  = () => window.FALLA_MODAL_CFG || {};
    const csrf = () => document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // Callback de cancelación pendiente (solo cuando se abre pre-seleccionado).
    let _pendingCancel = null;

    // ─── Abrir / cerrar ───
    window.openNuevoReporteModal = function () {
        _pendingCancel = null;            // apertura normal (botón "Reporte"): sin revertir
        _resetModal();
        // Modo buscador: el bloque de búsqueda visible.
        const block = document.getElementById('fl_search_block');
        if (block) block.style.display = '';
        document.getElementById('nuevoReporteOverlay').classList.add('active');
    };

    // Abrir con el activo ya elegido (desde el desplegable de estado de equipos/aux).
    window.flOpenForActivo = function (tipo, id, label, onCancel) {
        _resetModal();
        _pendingCancel = (typeof onCancel === 'function') ? onCancel : null;
        // Oculta el buscador y fija el activo.
        const block = document.getElementById('fl_search_block');
        if (block) block.style.display = 'none';
        window.flSelectActivo('fl', tipo, id, label || '', '');
        document.getElementById('nuevoReporteOverlay').classList.add('active');
    };

    window.closeNuevoReporteModal = function () {
        document.getElementById('nuevoReporteOverlay').classList.remove('active');
        // Si se abrió pre-seleccionado y se cierra SIN crear, revertir (estado previo).
        const cancel = _pendingCancel;
        _pendingCancel = null;
        if (cancel) cancel();
    };

    function _resetModal() {
        const searchInput = document.getElementById('fl_search_activo');
        if (searchInput) { searchInput.value = ''; searchInput.placeholder = 'Ej: ABC123 / 1HGCM82...'; }
        const resBox = document.getElementById('fl_search_results');
        if (resBox) { resBox.innerHTML = ''; resBox.style.display = 'none'; }
        const selBox = document.getElementById('fl_activo_seleccionado');
        if (selBox) { selBox.innerHTML = ''; selBox.style.display = 'none'; }
        const spinner = document.getElementById('fl_search_spinner');
        if (spinner) spinner.style.display = 'none';
        document.getElementById('fl_activo_tipo').value = '';
        document.getElementById('fl_activo_id').value = '';
        document.getElementById('nuevoReporteForm').reset();
        // Dropdown custom de Tipo de Mantenimiento (form.reset() no toca el label custom).
        const mantHidden = document.getElementById('fl_tipo_mantenimiento');
        const mantLabel  = document.getElementById('fl_tipo_mantenimiento_label');
        if (mantHidden) mantHidden.value = '';
        if (mantLabel) { mantLabel.innerText = 'Seleccionar...'; mantLabel.style.color = '#94a3b8'; }
        document.querySelectorAll('#flMantenimientoDD .dropdown-item').forEach((item, i) => {
            item.classList.toggle('selected', i === 0);
        });
        window.flSetTipo('corto');
    }

    window.flSetTipo = function (tipo) {
        document.getElementById('fl_tipo_reporte').value = tipo;
        document.querySelectorAll('#nuevoReporteForm .fl-toggle-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.tipo === tipo);
        });
        const showExt = tipo === 'extenso' ? 'flex' : 'none';
        document.getElementById('fl_fields_extenso').style.display = showExt;
        const tallerBlock = document.getElementById('fl_fields_extenso_taller');
        if (tallerBlock) tallerBlock.style.display = showExt;
        // El equipo siempre queda INOPERATIVO al crear el reporte (forzado en backend).
    };

    // ─── Buscador de activos ───
    let _timer = null;
    window.flSearchActivos = function (q) {
        clearTimeout(_timer);
        const spinner = document.getElementById('fl_search_spinner');
        if (spinner) spinner.style.display = 'block';
        _timer = setTimeout(() => {
            const resBox = document.getElementById('fl_search_results');
            if (!q || q.length < 2) {
                resBox.style.display = 'none';
                if (spinner) spinner.style.display = 'none';
                return;
            }
            fetch(CFG().urlSearch + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                if (spinner) spinner.style.display = 'none';
                if (!data.results || !data.results.length) {
                    resBox.innerHTML = '<div style="padding:14px; text-align:center; color:#94a3b8; font-size:13px;">Sin resultados</div>';
                    resBox.style.display = 'block';
                    return;
                }
                resBox.innerHTML = data.results.map(r => {
                    const rawFoto = r.foto ? r.foto.replace(/\\/g, '/') : '';
                    const fotoSrc = rawFoto ? (rawFoto.startsWith('http') || rawFoto.startsWith('/') ? rawFoto : '/' + rawFoto) : '';
                    const fotoHtml = fotoSrc
                        ? `<img src="${fotoSrc}" alt="" style="width:88px;height:72px;object-fit:contain;border-radius:8px;background:#f8fafc;flex-shrink:0;">`
                        : `<div style="width:88px;height:72px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#cbd5e0;flex-shrink:0;"><i class="material-icons" style="font-size:34px;">image_not_supported</i></div>`;
                    const tipoNom = r.tipo_nombre || (r.tipo === 'equipo' ? 'VEHÍCULO' : 'AUX');
                    const icon = (name, txt) =>
                        txt ? `<span style="display:inline-flex;align-items:center;gap:2px;white-space:nowrap;"><i class="material-icons" style="font-size:12px;color:#94a3b8;">${name}</i> ${txt}</span>` : '';
                    const chips = [
                        icon('fingerprint', r.serial),
                        icon('settings', r.serial_motor),
                        icon('featured_play_list', r.placa),
                        icon('tag', r.codigo),
                    ].filter(Boolean).join('');
                    const frenteHtml = r.frente
                        ? `<div style="margin-top:5px;font-size:11.5px;font-weight:600;color:#3b82f6;">${r.frente}</div>`
                        : `<div style="margin-top:5px;font-size:11px;color:#cbd5e1;font-style:italic;">Sin ubicación asignada</div>`;
                    const displayInfo = r.placa || r.serial || r.codigo || '';
                    return `
                        <div class="fl-search-result"
                             onclick="window.flSelectActivo('fl', '${r.tipo}', ${r.id}, '${tipoNom.replace(/'/g, "\\'")}', '${displayInfo.replace(/'/g, "\\'")}')">
                            ${fotoHtml}
                            <div style="flex:1;min-width:0;margin-left:10px;">
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <span style="font-weight:700;color:#1e293b;font-size:13px;">${tipoNom}</span>
                                </div>
                                ${chips ? `<div style="display:flex;flex-wrap:wrap;gap:4px 10px;margin-top:4px;font-size:11.5px;color:#64748b;">${chips}</div>` : ''}
                                ${frenteHtml}
                            </div>
                        </div>`;
                }).join('');
                resBox.style.display = 'block';
            }).catch(() => { if (spinner) spinner.style.display = 'none'; });
        }, 300);
    };

    window.flSelectActivo = function (prefix, tipo, id, tipoNom, info) {
        document.getElementById(prefix + '_activo_tipo').value = tipo;
        document.getElementById(prefix + '_activo_id').value = id;
        const box = document.getElementById(prefix + '_activo_seleccionado');
        box.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons" style="font-size:18px;color:#059669;flex-shrink:0;">check_circle</i>
                <div style="display:flex; flex-direction:column; flex:1; min-width:0;">
                    <strong style="color:#1e293b; font-size:13px; line-height:1.2;">${tipoNom}</strong>
                    <span style="color:#64748b; font-size:12px;">${info || ''}</span>
                </div>
            </div>`;
        box.style.display = 'block';
        box.style.padding = '8px 12px';
        box.style.background = '#f0fdf4';
        box.style.border = '1px solid #bbf7d0';
        box.style.borderRadius = '8px';
        const res = document.getElementById(prefix + '_search_results');
        if (res) res.style.display = 'none';
        const inp = document.getElementById(prefix + '_search_activo');
        if (inp) { inp.value = ''; inp.placeholder = 'Equipo seleccionado'; }
    };

    // ════════════════════════════════════════════════════════════════════
    //  Modal de CIERRE de reporte (lean) — compartido por equipos/auxiliares.
    //  Se abre cuando se intenta cambiar el estado de un equipo que tiene un
    //  reporte ABIERTO (el backend responde 409 con falla_abierta). Cierra el
    //  reporte (POST a urlBase/{id}/close) y el activo vuelve a OPERATIVO.
    //  La página define FALLA_MODAL_CFG.onClosed() para recargar su listado.
    // ════════════════════════════════════════════════════════════════════
    let _cierreId = null;
    window.flAbrirCierre = function (falla) {
        _cierreId = falla.id;
        const msg = document.getElementById('flCierreMsg');
        if (msg) {
            const esActa = falla.tipo === 'extenso';
            const ref = (esActa && falla.codigo) ? falla.codigo : (falla.equipo || ('#' + falla.id));
            const extra = (esActa && falla.equipo) ? (' · ' + falla.equipo) : '';
            msg.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle; color:#d97706;">report_problem</i> Cerrando reporte <strong>' + ref + '</strong>' + extra;
        }
        const obs = document.getElementById('flCierreObs');
        if (obs) obs.value = '';
        document.getElementById('flCierreOverlay').classList.add('active');
    };
    window.flCerrarCierreModal = function () {
        document.getElementById('flCierreOverlay').classList.remove('active');
        _cierreId = null;
    };
    window.flConfirmarCierre = function () {
        if (!_cierreId) return;
        const btn = document.getElementById('flBtnConfirmarCierre');
        const fd = new FormData();
        fd.append('_method', 'PATCH');
        const obs = document.getElementById('flCierreObs');
        fd.append('observaciones_cierre', obs ? obs.value : '');
        if (btn) btn.disabled = true;
        if (window.showPreloader) window.showPreloader();
        fetch(CFG().urlBase + '/' + _cierreId + '/close', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: fd
        })
        .then(r => r.json())
        .then(body => {
            if (body.success) {
                if (window.showToast) window.showToast(body.message || 'Reporte cerrado. El equipo vuelve a OPERATIVO.', 'success');
                window.flCerrarCierreModal();
                const cb = CFG().onClosed;
                if (typeof cb === 'function') cb();
            } else {
                if (window.showToast) window.showToast(body.message || 'No se pudo cerrar el reporte.', 'error');
            }
        })
        .catch(() => { if (window.showToast) window.showToast('Error de red al cerrar el reporte.', 'error'); })
        .finally(() => { if (btn) btn.disabled = false; if (window.hidePreloader) window.hidePreloader(); });
    };

    window.submitNuevoReporte = function () {
        const form = document.getElementById('nuevoReporteForm');
        const fd = new FormData(form);
        if (!fd.get('activo_id')) {
            if (window.showToast) window.showToast('Selecciona un equipo primero.', 'error');
            return;
        }
        if (window.showPreloader) window.showPreloader();
        fetch(CFG().urlStore, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
            body: fd
        })
        .then(r => r.json().then(b => ({ status: r.status, body: b })))
        .then(({ status, body }) => {
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message, 'success');
                _pendingCancel = null;           // creado: NO revertir el estado
                window.closeNuevoReporteModal();
                const cb = CFG().onCreated;
                if (typeof cb === 'function') cb(body.falla);
                if (CFG().openPdf && body.falla && body.falla.TIPO_REPORTE === 'extenso' && body.falla.ID_FALLA) {
                    setTimeout(() => { window.open(CFG().urlBase + '/' + body.falla.ID_FALLA + '/pdf', '_blank'); }, 300);
                }
            } else {
                if (window.showToast) window.showToast(body.message || 'No se pudo crear el reporte', 'error');
            }
        })
        .catch(e => { console.error(e); if (window.showToast) window.showToast('Error de red', 'error'); })
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
    };
})();
