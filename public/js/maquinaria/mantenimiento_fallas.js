/**
 * Mantenimiento Fallas - Fault registration, recommendations, detail modal
 */
(function () {
    'use strict';

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    /* ═══════════════════════════════════════════
       MODAL: Registrar Falla
    ═══════════════════════════════════════════ */
    window.abrirFormularioFalla = function () {
        const modal = document.getElementById('modalRegistrarFalla');
        if (modal) modal.style.display = 'flex';

        // Pre-fill frente from page filter if selected
        const filterFrenteVal = document.getElementById('filterFrente')?.value;
        if (filterFrenteVal) {
            const fallaFrenteHidden = document.getElementById('fallaFrente');
            if (fallaFrenteHidden) fallaFrenteHidden.value = filterFrenteVal;
            // Update dropdown placeholder to show selected frente name
            const frenteDropdown = document.getElementById('fallaFrenteDropdown');
            if (frenteDropdown) {
                const matchItem = frenteDropdown.querySelector('.dropdown-item[data-value="' + filterFrenteVal + '"]');
                if (matchItem && window.selectOption) {
                    selectOption('fallaFrenteDropdown', filterFrenteVal, matchItem.textContent.trim());
                }
            }
        }
    };

    window.cerrarModalFalla = function () {
        const modal = document.getElementById('modalRegistrarFalla');
        if (modal) modal.style.display = 'none';
        // Reset hidden inputs
        const eq = document.getElementById('fallaEquipo');
        if (eq) eq.value = '';
        const tipo = document.getElementById('fallaTipo');
        if (tipo) tipo.value = 'MECANICA';
        const desc = document.getElementById('fallaDescripcion');
        if (desc) desc.value = '';
        const sis = document.getElementById('fallaSistema');
        if (sis) sis.value = '';
        const rec = document.getElementById('recomendacionesContainer');
        if (rec) { rec.style.display = 'none'; rec.innerHTML = ''; }
        // Reset priority to MEDIA
        const mediaRadio = document.querySelector('input[name="fallaPrioridad"][value="MEDIA"]');
        if (mediaRadio) mediaRadio.checked = true;
        // Reset custom dropdowns
        if (window.clearDropdownFilter) {
            clearDropdownFilter('fallaFrenteDropdown');
            clearDropdownFilter('fallaEquipoDropdown');
            clearDropdownFilter('fallaTipoDropdown');
        }
        const frenteHidden = document.getElementById('fallaFrente');
        if (frenteHidden) frenteHidden.value = '';
        // Restore tipo placeholder
        const tipoSearch = document.querySelector('#fallaTipoDropdown [data-filter-search]');
        if (tipoSearch) tipoSearch.placeholder = 'Mecánica';
    };

    window.guardarFalla = async function () {
        const equipoId = document.getElementById('fallaEquipo')?.value;
        const tipoFalla = document.getElementById('fallaTipo')?.value || 'MECANICA';
        const sistemaAfectado = document.getElementById('fallaSistema')?.value;
        const descripcion = document.getElementById('fallaDescripcion')?.value;
        const prioridad = document.querySelector('input[name="fallaPrioridad"]:checked')?.value || 'MEDIA';

        // Inline validation errors inside the modal
        clearModalErrors();

        if (!equipoId) {
            showModalError('Selecciona un equipo');
            return;
        }
        if (!descripcion || descripcion.trim().length < 5) {
            showModalError('Describe la falla (mínimo 5 caracteres)');
            highlightField('fallaDescripcion');
            return;
        }

        // Get frente from modal dropdown first, then filter bar fallback
        const frenteId = document.getElementById('fallaFrente')?.value || window._mantCurrentFrente || document.getElementById('filterFrente')?.value;
        if (!frenteId) {
            showModalError('Selecciona un frente de trabajo');
            return;
        }

        try {
            // Ensure we have a report for today
            const repResp = await fetch('/admin/mantenimiento/reporte-hoy', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ ID_FRENTE: frenteId }),
            });
            const repData = await repResp.json();

            if (!repData.success || !repData.reporte) {
                showModalError('No se pudo crear/obtener el reporte del día');
                return;
            }

            // Now create the fault
            const resp = await fetch('/admin/mantenimiento/falla', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    ID_REPORTE: repData.reporte.ID_REPORTE,
                    ID_EQUIPO: equipoId,
                    TIPO_FALLA: tipoFalla,
                    SISTEMA_AFECTADO: sistemaAfectado || null,
                    DESCRIPCION_FALLA: descripcion,
                    PRIORIDAD: prioridad,
                }),
            });

            const data = await resp.json();

            if (resp.ok && data.success) {
                cerrarModalFalla();
                // Show snackbar on success
                showSnackbar(data.message || 'Falla registrada correctamente');
                // Refresh views
                if (typeof cargarReportes === 'function') cargarReportes();
                if (typeof verReporte === 'function') verReporte(repData.reporte.ID_REPORTE);
                // Refresh stats
                cargarStatsQuick();
            } else if (data.errors) {
                // Laravel validation errors
                const firstError = Object.values(data.errors)[0];
                showModalError(Array.isArray(firstError) ? firstError[0] : firstError);
            } else {
                showModalError(data.error || data.message || 'Error al registrar la falla');
            }
        } catch (e) {
            console.error('Error guardando falla:', e);
            showModalError('Error de conexión al servidor');
        }
    };

    /* ═══════════════════════════════════════════
       AUTO-RECOMENDACIÓN DE MATERIALES
    ═══════════════════════════════════════════ */
    window.onEquipoSelected = async function () {
        const equipoId = document.getElementById('fallaEquipo')?.value;
        console.log('Equipo seleccionado:', equipoId);
        const container = document.getElementById('recomendacionesContainer');
        if (!container) return;

        if (!equipoId) {
            container.style.display = 'none';
            container.innerHTML = '';
            return;
        }

        try {
            const resp = await fetch('/admin/mantenimiento/recomendar/' + equipoId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await resp.json();

            if (data.recomendaciones && data.recomendaciones.length > 0) {
                container.style.display = 'block';
                container.innerHTML = renderRecomendaciones(data);
            } else {
                container.style.display = 'block';
                container.innerHTML = '<div style="padding:8px 12px; background:#f8fafc; border-radius:8px; font-size:11px; color:#94a3b8;"><i class="material-icons" style="font-size:14px; vertical-align:middle;">info</i> Este equipo no tiene especificaciones de catálogo vinculadas.</div>';
            }
        } catch (e) {
            console.error('Error cargando recomendaciones:', e);
        }
    };

    function renderRecomendaciones(data) {
        let html = '<div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px 14px;">';
        html += '<div style="font-size:11px; font-weight:700; color:#2563eb; margin-bottom:8px; display:flex; align-items:center; gap:4px;"><i class="material-icons" style="font-size:14px;">auto_awesome</i> Recomendaciones para ' + (data.equipo?.MARCA || '') + ' ' + (data.equipo?.MODELO || '') + '</div>';
        html += '<div style="display:flex; flex-wrap:wrap; gap:6px;">';

        data.recomendaciones.forEach(rec => {
            html += `<span onclick="copiarRecomendacion('${escapeHtml(rec.DESCRIPCION_MATERIAL)}', '${escapeHtml(rec.ESPECIFICACION || '')}')"
                style="display:inline-flex; align-items:center; gap:4px; padding:5px 10px; background:white; border:1px solid #93c5fd; border-radius:8px; font-size:11px; font-weight:600; color:#1e40af; cursor:pointer; transition:all 0.15s;"
                onmouseover="this.style.background='#dbeafe'" onmouseout="this.style.background='white'"
                title="Click para copiar al portapapeles">
                <i class="material-icons" style="font-size:12px;">add_circle</i> ${escapeHtml(rec.DESCRIPCION_MATERIAL)}
            </span>`;
        });

        html += '</div>';

        // Historical materials
        if (data.historicos && data.historicos.length > 0) {
            html += '<div style="margin-top:8px; padding-top:8px; border-top:1px solid #bfdbfe;">';
            html += '<div style="font-size:10px; font-weight:600; color:#64748b; margin-bottom:4px;">Materiales usados anteriormente:</div>';
            html += '<div style="font-size:11px; color:#475569;">' + data.historicos.map(m => escapeHtml(m)).join(', ') + '</div>';
            html += '</div>';
        }

        html += '</div>';
        return html;
    }

    window.copiarRecomendacion = function (desc, spec) {
        navigator.clipboard.writeText(desc).catch(() => {});
        showMsg('success', 'Copiado: ' + desc);
    };

    /* ═══════════════════════════════════════════
       RESOLVER FALLA (Quick action)
    ═══════════════════════════════════════════ */
    window.resolverFalla = function (fallaId) {
        // Show modal with resolution description input
        const modalHtml = '<div style="margin-bottom:12px;">' +
            '<label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:6px;">Descripción de la resolución:</label>' +
            '<textarea id="resolucionTexto" rows="3" placeholder="Describe qué se hizo para resolver la falla..." ' +
            'style="width:100%; padding:10px 14px; border:1px solid #cbd5e0; border-radius:10px; font-size:13px; background:#f8fafc; outline:none; resize:vertical; box-sizing:border-box; font-family:inherit;"></textarea>' +
            '</div>';

        if (window.showModal) {
            window.showModal({
                type: 'info',
                title: 'Resolver Falla',
                message: modalHtml,
                confirmText: 'Resolver Falla',
                cancelText: 'Cancelar',
                onConfirm: async function () {
                    const descripcion = document.getElementById('resolucionTexto')?.value || 'Resuelta desde panel de mantenimiento';
                    try {
                        const resp = await fetch('/admin/mantenimiento/falla/' + fallaId, {
                            method: 'PUT',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                ESTADO_FALLA: 'RESUELTA',
                                DESCRIPCION_RESOLUCION: descripcion,
                            }),
                        });
                        const data = await resp.json();
                        if (data.success) {
                            showSnackbar('Falla resuelta correctamente');
                            if (typeof cargarReportes === 'function') cargarReportes();
                            if (typeof verReporte === 'function' && window.currentReporteId) {
                                verReporte(window.currentReporteId);
                            }
                            cargarStatsQuick();
                        } else {
                            showMsg('error', data.error || 'Error al resolver la falla');
                        }
                    } catch (e) {
                        console.error('Error resolviendo falla:', e);
                        showMsg('error', 'Error de conexión al resolver la falla');
                    }
                }
            });
        }
    };

    /**
     * Cambiar estado de falla a EN_PROCESO
     */
    window.enProcesoFalla = function (fallaId) {
        if (window.showModal) {
            window.showModal({
                type: 'info',
                title: 'Iniciar Proceso',
                message: '¿Marcar esta falla como "En Proceso"?',
                confirmText: 'Sí, En Proceso',
                cancelText: 'Cancelar',
                onConfirm: async function () {
                    try {
                        const resp = await fetch('/admin/mantenimiento/falla/' + fallaId, {
                            method: 'PUT',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({ ESTADO_FALLA: 'EN_PROCESO' }),
                        });
                        const data = await resp.json();
                        if (data.success) {
                            showSnackbar('Falla en proceso');
                            if (typeof cargarReportes === 'function') cargarReportes();
                            if (typeof verReporte === 'function' && window.currentReporteId) {
                                verReporte(window.currentReporteId);
                            }
                        }
                    } catch (e) {
                        console.error('Error actualizando falla:', e);
                    }
                }
            });
        }
    };

    /* ═══════════════════════════════════════════
       DETALLE FALLA MODAL
    ═══════════════════════════════════════════ */
    window.verDetalleFalla = async function (fallaId) {
        const modal = document.getElementById('modalDetalleFalla');
        const body = document.getElementById('detalleFallaBody');
        if (!modal || !body) return;

        modal.style.display = 'flex';
        body.innerHTML = '<p style="color:#94a3b8; text-align:center; padding:20px;">Cargando...</p>';

        try {
            const resp = await fetch('/admin/mantenimiento/falla/' + fallaId, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const data = await resp.json();
            const f = data.falla;

            const prioColors = { CRITICA: '#dc2626', ALTA: '#ea580c', MEDIA: '#ca8a04', BAJA: '#16a34a' };
            const estadoColors = { ABIERTA: '#dc2626', EN_PROCESO: '#ea580c', RESUELTA: '#16a34a' };
            const prioColor = prioColors[f.PRIORIDAD] || '#64748b';
            const estadoColor = estadoColors[f.ESTADO_FALLA] || '#64748b';

            let materialesHtml = '';
            if (f.materiales && f.materiales.length > 0) {
                materialesHtml = '<div style="margin-bottom:16px;">' +
                    '<label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px;">Materiales Recomendados</label>' +
                    '<div style="margin-top:6px;">';
                f.materiales.forEach(m => {
                    materialesHtml += '<div style="display:flex; align-items:center; gap:8px; padding:6px 10px; background:#f8fafc; border-radius:8px; margin-bottom:4px; font-size:12px;">' +
                        '<i class="material-icons" style="font-size:14px; color:#3b82f6;">inventory_2</i>' +
                        '<span style="font-weight:600; color:#1e293b;">' + escapeHtml(m.DESCRIPCION_MATERIAL) + '</span>' +
                        (m.ESPECIFICACION ? '<span style="color:#64748b;">(' + escapeHtml(m.ESPECIFICACION) + ')</span>' : '') +
                        '<span style="margin-left:auto; font-weight:700; color:#475569;">' + m.CANTIDAD + ' ' + escapeHtml(m.UNIDAD) + '</span>' +
                        '</div>';
                });
                materialesHtml += '</div></div>';
            }

            let resolucionHtml = '';
            if (f.ESTADO_FALLA === 'RESUELTA' && f.DESCRIPCION_RESOLUCION) {
                resolucionHtml = '<div style="margin-bottom:16px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; padding:12px;">' +
                    '<label style="font-size:11px; font-weight:700; color:#16a34a; text-transform:uppercase; letter-spacing:0.5px;">Resolución</label>' +
                    '<p style="font-size:13px; color:#166534; margin:6px 0 0;">' + escapeHtml(f.DESCRIPCION_RESOLUCION) + '</p>' +
                    (f.FECHA_RESOLUCION ? '<p style="font-size:11px; color:#4ade80; margin:4px 0 0;">Resuelta: ' + f.FECHA_RESOLUCION + '</p>' : '') +
                    '</div>';
            }

            body.innerHTML =
                '<div style="display:flex; align-items:center; gap:12px; margin-bottom:16px; padding:12px; background:#f8fafc; border-radius:12px;">' +
                    '<i class="material-icons" style="font-size:28px; color:#0067b1;">agriculture</i>' +
                    '<div style="flex:1;">' +
                        '<div style="font-size:14px; font-weight:800; color:#1e293b;">' + escapeHtml(f.equipo.tipo) + '</div>' +
                        '<div style="font-size:12px; color:#64748b;">' + escapeHtml(f.equipo.MARCA) + ' ' + escapeHtml(f.equipo.MODELO) + ' | ' + escapeHtml(f.equipo.SERIAL_CHASIS || f.equipo.CODIGO_PATIO || '') + '</div>' +
                        '<div style="font-size:11px; color:#94a3b8;">' + escapeHtml(f.equipo.frente) + '</div>' +
                    '</div>' +
                    '<span style="padding:4px 10px; border-radius:50px; font-size:11px; font-weight:700; background:' + (f.equipo.ESTADO_OPERATIVO === 'INOPERATIVO' ? '#fef2f2' : '#f0fdf4') + '; color:' + (f.equipo.ESTADO_OPERATIVO === 'INOPERATIVO' ? '#dc2626' : '#16a34a') + ';">' + escapeHtml(f.equipo.ESTADO_OPERATIVO) + '</span>' +
                '</div>' +

                '<div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-bottom:16px;">' +
                    '<div>' +
                        '<label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Tipo</label>' +
                        '<p style="font-size:13px; font-weight:700; color:#1e293b; margin:4px 0;">' + escapeHtml(f.TIPO_FALLA) + '</p>' +
                    '</div>' +
                    '<div>' +
                        '<label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Prioridad</label>' +
                        '<p style="margin:4px 0;"><span style="padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; color:' + prioColor + ';">' + escapeHtml(f.PRIORIDAD) + '</span></p>' +
                    '</div>' +
                    '<div>' +
                        '<label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Estado</label>' +
                        '<p style="margin:4px 0;"><span style="padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; color:' + estadoColor + ';">' + (f.ESTADO_FALLA || '').replace('_', ' ') + '</span></p>' +
                    '</div>' +
                '</div>' +

                (f.SISTEMA_AFECTADO ? '<div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase;">Sistema Afectado</label><p style="font-size:13px; color:#334155; margin:4px 0;">' + escapeHtml(f.SISTEMA_AFECTADO) + '</p></div>' : '') +

                '<div style="margin-bottom:16px;">' +
                    '<label style="font-size:11px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px;">Descripción</label>' +
                    '<p style="font-size:13px; color:#334155; margin:6px 0; line-height:1.5;">' + escapeHtml(f.DESCRIPCION_FALLA) + '</p>' +
                '</div>' +

                materialesHtml +
                resolucionHtml +

                '<div style="display:flex; align-items:center; gap:12px; font-size:11px; color:#94a3b8; padding-top:8px; border-top:1px solid #f1f5f9;">' +
                    '<span><i class="material-icons" style="font-size:14px; vertical-align:middle;">person</i> ' + escapeHtml(f.usuario) + '</span>' +
                    '<span><i class="material-icons" style="font-size:14px; vertical-align:middle;">schedule</i> ' + (f.HORA_REGISTRO || '') + '</span>' +
                    '<span><i class="material-icons" style="font-size:14px; vertical-align:middle;">location_on</i> ' + escapeHtml(f.frente_reporte) + '</span>' +
                '</div>';

        } catch (e) {
            console.error('Error cargando detalle falla:', e);
            body.innerHTML = '<p style="color:#dc2626; text-align:center; padding:20px;">Error al cargar el detalle de la falla</p>';
        }
    };

    window.cerrarDetalleFalla = function () {
        const modal = document.getElementById('modalDetalleFalla');
        if (modal) modal.style.display = 'none';
    };

    /* ═══════════════════════════════════════════
       HELPERS
    ═══════════════════════════════════════════ */
    function showMsg(type, message) {
        console.log('[showMsg]', type, message);
        if (window.showModal) {
            try {
                window.showModal({ type: type, title: type === 'success' ? '¡Éxito!' : type === 'error' ? 'Error' : 'Aviso', message: message, confirmText: 'Aceptar', hideCancel: true });
            } catch (e) {
                alert(message);
            }
        } else {
            alert(message);
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ── Inline modal error messages ── */
    function showModalError(msg) {
        clearModalErrors();
        const modal = document.getElementById('modalRegistrarFalla');
        if (!modal) return;
        const body = modal.querySelector('[style*="overflow-y:auto"]');
        if (!body) return;
        const errDiv = document.createElement('div');
        errDiv.className = 'mant-modal-error';
        errDiv.style.cssText = 'background:#fef2f2; border:1px solid #fecaca; color:#dc2626; padding:10px 14px; border-radius:10px; font-size:13px; font-weight:600; margin-bottom:12px; display:flex; align-items:center; gap:8px; animation:slideDown 0.2s ease-out;';
        errDiv.innerHTML = '<i class="material-icons" style="font-size:18px;">error</i>' + escapeHtml(msg);
        body.insertBefore(errDiv, body.firstChild);
        body.scrollTop = 0;
    }

    function clearModalErrors() {
        document.querySelectorAll('.mant-modal-error').forEach(el => el.remove());
        document.querySelectorAll('.mant-field-error').forEach(el => el.classList.remove('mant-field-error'));
    }

    function showSnackbar(msg, type) {
        type = type || 'success';
        // Remove existing snackbar
        const old = document.getElementById('mantSnackbar');
        if (old) old.remove();

        const colors = {
            success: { bg: '#16a34a', icon: 'check_circle' },
            error: { bg: '#dc2626', icon: 'error' },
            warning: { bg: '#d97706', icon: 'warning' },
        };
        const c = colors[type] || colors.success;

        const bar = document.createElement('div');
        bar.id = 'mantSnackbar';
        bar.style.cssText = 'position:fixed; bottom:30px; left:50%; transform:translateX(-50%) translateY(20px); background:' + c.bg + '; color:white; padding:12px 24px; border-radius:12px; font-size:14px; font-weight:700; display:flex; align-items:center; gap:10px; z-index:100000; box-shadow:0 8px 24px rgba(0,0,0,0.2); opacity:0; transition:all 0.3s ease;';
        bar.innerHTML = '<i class="material-icons" style="font-size:20px;">' + c.icon + '</i>' + escapeHtml(msg);
        document.body.appendChild(bar);

        // Animate in
        requestAnimationFrame(() => {
            bar.style.opacity = '1';
            bar.style.transform = 'translateX(-50%) translateY(0)';
        });

        // Auto-dismiss
        setTimeout(() => {
            bar.style.opacity = '0';
            bar.style.transform = 'translateX(-50%) translateY(20px)';
            setTimeout(() => bar.remove(), 300);
        }, 3000);
    }

    function highlightField(fieldId) {
        const el = document.getElementById(fieldId);
        if (el) {
            el.classList.add('mant-field-error');
            el.style.borderColor = '#dc2626';
            el.focus();
            el.addEventListener('input', function handler() {
                el.style.borderColor = '#cbd5e0';
                el.classList.remove('mant-field-error');
                el.removeEventListener('input', handler);
            });
        }
    }

    async function cargarStatsQuick() {
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
        } catch (e) { /* silent */ }
    }

})();
