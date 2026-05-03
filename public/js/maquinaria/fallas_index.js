/* fallas_index.js â€” MÃ³dulo Control de Fallas
 * PatrÃ³n global idÃ©ntico al de equipos_index.js / movilizaciones_index.js.
 * Las rutas se leen de window.FALLAS_CFG, definido en el Blade del mÃ³dulo.
 */
(function () {
    if (window._fallasReady) return;
    window._fallasReady = true;

    const cfg  = () => window.FALLAS_CFG || {};
    const csrf = () => document.querySelector('meta[name="csrf-token"]').getAttribute('content');

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
                document.getElementById('statInoperativo').textContent = data.stats.inoperativo;
                document.getElementById('statMantenimiento').textContent = data.stats.mantenimiento;
                document.getElementById('statAbiertos').textContent = data.stats.reportes_abiertos;
            }
        })
        .catch(e => console.error('cargarFallas:', e))
        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
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

    // â”€â”€â”€ Modal Nuevo Reporte â”€â”€â”€
    window.openNuevoReporteModal = function () {
        document.getElementById('nuevoReporteOverlay').classList.add('active');

        // ── Reset completo: deja el modal en blanco para nueva operación ──
        // 1. Input de búsqueda: vaciar valor Y restaurar placeholder original
        const searchInput = document.getElementById('fl_search_activo');
        if (searchInput) {
            searchInput.value = '';
            searchInput.placeholder = 'Ej: ABC123 / 1HGCM82...';
        }
        // 2. Resultados de búsqueda: ocultar y limpiar contenido
        const resBox = document.getElementById('fl_search_results');
        if (resBox) { resBox.innerHTML = ''; resBox.style.display = 'none'; }
        // 3. Tarjeta de equipo seleccionado: ocultar y limpiar HTML interno
        const selBox = document.getElementById('fl_activo_seleccionado');
        if (selBox) { selBox.innerHTML = ''; selBox.style.display = 'none'; }
        // 4. Spinner: ocultar por si quedó visible
        const spinner = document.getElementById('fl_search_spinner');
        if (spinner) spinner.style.display = 'none';
        // 5. Campos ocultos del activo seleccionado
        document.getElementById('fl_activo_tipo').value = '';
        document.getElementById('fl_activo_id').value = '';
        // 6. Reset completo del formulario (descripción, estado, etc.)
        document.getElementById('nuevoReporteForm').reset();
        // 6b. El dropdown custom de Estado no lo resetea form.reset() — forzarlo a INOPERATIVO
        const estadoHidden = document.getElementById('fl_estado_al_crear');
        const estadoLabel  = document.getElementById('fl_estado_al_crear_label');
        if (estadoHidden) estadoHidden.value = 'INOPERATIVO';
        if (estadoLabel)  estadoLabel.innerText = 'Inoperativo';
        document.querySelectorAll('#flEstadoCrearDD .dropdown-item').forEach((item, i) => {
            item.classList.toggle('selected', i === 0);
        });
        // Cerrar el dropdown por si quedó abierto
        const ddContent = document.querySelector('#flEstadoCrearDD .dropdown-content');
        if (ddContent) ddContent.style.display = 'none';
        // 7. Tipo de reporte → corto por defecto
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
            const spinner = document.getElementById(prefix + '_search_spinner');
            if (spinner) spinner.style.display = 'block';

            timer = setTimeout(() => {
                const resBox = document.getElementById(prefix + '_search_results');
                if (!q || q.length < 2) { 
                    resBox.style.display = 'none'; 
                    if (spinner) spinner.style.display = 'none';
                    return; 
                }
                fetch(cfg().urlSearch + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(r => r.json())
                .then(data => {
                    if (spinner) spinner.style.display = 'none';
                    if (!data.results || !data.results.length) {
                        resBox.innerHTML = '<div style="padding:14px; text-align:center; color:#94a3b8; font-size:13px;">Sin resultados</div>';
                        resBox.style.display = 'block';
                        return;
                    }
                    resBox.innerHTML = data.results.map(r => {
                        // ——— Foto ———
                        const rawFoto = r.foto ? r.foto.replace(/\\/g, '/') : '';
                        const fotoSrc = rawFoto ? (rawFoto.startsWith('http') || rawFoto.startsWith('/') ? rawFoto : '/' + rawFoto) : '';
                        const fotoHtml = fotoSrc
                            ? `<img src="${fotoSrc}" alt="" style="width:50px;height:42px;object-fit:contain;border-radius:6px;background:#f8fafc;flex-shrink:0;">`
                            : `<div style="width:50px;height:42px;border-radius:6px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;color:#cbd5e0;flex-shrink:0;"><i class="material-icons" style="font-size:22px;">image_not_supported</i></div>`;

                        const tipoNom   = r.tipo_nombre || (r.tipo === 'equipo' ? 'VEHÍCULO' : 'AUX');
                        const marca     = r.marca || '';

                        // Chips de identidad
                        const icon = (name, txt) =>
                            txt ? `<span style="display:inline-flex;align-items:center;gap:2px;white-space:nowrap;"><i class="material-icons" style="font-size:12px;color:#94a3b8;">${name}</i> ${txt}</span>` : '';
                        const chips = [
                            icon('fingerprint', r.serial),
                            icon('settings',    r.serial_motor),
                            icon('featured_play_list', r.placa),
                            icon('tag',         r.codigo),
                        ].filter(Boolean).join('');

                        // Frente / Ubicación (protagonista)
                        const frenteHtml = r.frente
                            ? `<div style="margin-top:5px;display:flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600;color:#3b82f6;"><i class="material-icons" style="font-size:13px;color:#3b82f6;">location_on</i> ${r.frente}</div>`
                            : `<div style="margin-top:5px;font-size:11px;color:#cbd5e1;font-style:italic;">Sin ubicación asignada</div>`;

                        // Info para el campo de texto al seleccionar
                        const displayInfo  = r.placa || r.serial || r.codigo || '';

                        return `
                            <div class="fl-search-result"
                                 onclick="window.flSelectActivo('${prefix}', '${r.tipo}', ${r.id}, '${tipoNom.replace(/'/g,"\\'")}', '${displayInfo.replace(/'/g,"\\'")}', '${fotoSrc.replace(/'/g,"\\'")}')">
                                ${fotoHtml}
                                <div style="flex:1;min-width:0;margin-left:10px;">
                                    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                        <span style="font-weight:700;color:#1e293b;font-size:13px;">${tipoNom}</span>
                                        ${marca  ? `<span style="font-size:12px;color:#475569;font-weight:600;">${marca}</span>` : ''}
                                    </div>
                                    ${chips ? `<div style="display:flex;flex-wrap:wrap;gap:4px 10px;margin-top:4px;font-size:11.5px;color:#64748b;">${chips}</div>` : ''}
                                    ${frenteHtml}
                                </div>
                            </div>
                        `;
                    }).join('');
                    resBox.style.display = 'block';
                }).catch(() => {
                    if (spinner) spinner.style.display = 'none';
                });
            }, 300);
        };
    }
    window.flSearchActivos   = _buildSearcher('fl');

    window.flSelectActivo = function(prefix, tipo, id, tipoNom, info, fotoSrc) {
        document.getElementById(prefix + '_activo_tipo').value = tipo;
        document.getElementById(prefix + '_activo_id').value = id;

        const fotoHtml = fotoSrc && fotoSrc !== 'undefined'
            ? `<img src="${fotoSrc}" alt="" style="width:40px;height:40px;object-fit:contain;border-radius:6px;background:#ffffff;flex-shrink:0;">`
            : `<div style="width:40px;height:40px;border-radius:6px;background:#ffffff;display:flex;align-items:center;justify-content:center;color:#cbd5e0;flex-shrink:0;"><i class="material-icons" style="font-size:20px;">image_not_supported</i></div>`;

        const box = document.getElementById(prefix + '_activo_seleccionado');
        box.innerHTML = `
            <div style="display:flex; align-items:center; gap:10px;">
                ${fotoHtml}
                <div style="display:flex; flex-direction:column; flex:1;">
                    <strong style="color:#1e293b; font-size:13.5px; line-height:1.2;">${tipoNom}</strong>
                    <span style="color:#64748b; font-size:12px;">${info || ''}</span>
                </div>
                <div style="margin-left:auto;">
                    <i class="material-icons" style="font-size:18px;color:#059669;">check_circle</i>
                </div>
            </div>
        `;
        box.style.display = 'block';
        box.style.padding = '8px 12px';
        box.style.background = '#f0fdf4';
        box.style.border = '1px solid #bbf7d0';
        box.style.borderRadius = '8px';
        
        document.getElementById(prefix + '_search_results').style.display = 'none';
        document.getElementById(prefix + '_search_activo').value = '';
        document.getElementById(prefix + '_search_activo').placeholder = "Equipo seleccionado";
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


    // ─── Cerrar reporte (modal con observaciones; el activo siempre vuelve a OPERATIVO) ───
    let _cierreId = null;

    window.cerrarFalla = function (id, codigo, equipo) {
        _cierreId = id;
        const msg = document.getElementById('cierreInfoMsg');
        if (msg) msg.innerHTML = '<i class="material-icons" style="font-size:16px; vertical-align:middle; color:#d97706;">report_problem</i> Cerrando reporte <strong>' + (codigo || '#' + id) + '</strong>' + (equipo ? ' Â· ' + equipo : '');
        document.getElementById('cierreObservaciones').value = '';
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
