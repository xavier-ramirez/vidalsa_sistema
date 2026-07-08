/**
 * movilizaciones_index.js
 * Lógica AJAX de filtros, búsqueda y paginación para /admin/movilizaciones.
 * Compatible con SPA (navegacion.js): se inicializa en cada carga de contenido.
 */

// ─── Función principal AJAX ──────────────────────────────────────────────────
window.loadMovilizaciones = async function (pageUrl = null) {
    const tableBody = document.getElementById('movilizacionesTableBody');
    if (!tableBody) return; // No estamos en la sección de movilizaciones

    if (window.showPreloader) window.showPreloader();
    tableBody.style.opacity = '0.5';

    try {
        // Scopear inputs al contenedor principal para evitar conflictos SPA
        const mvCard = document.querySelector('.movilizaciones-main-card');

        const getHiddenVal = (name, container) => {
            const el = (container || document).querySelector(`input[name="${name}"][data-filter-value]`);
            return el ? el.value.trim() : '';
        };

        const params = new URLSearchParams();

        // Búsqueda de texto libre
        const searchEl = document.getElementById('searchInput');
        if (searchEl && searchEl.value.trim()) {
            params.append('search', searchEl.value.trim());
        }

        // Filtro Frente
        const frenteVal = getHiddenVal('id_frente', mvCard);
        if (frenteVal && frenteVal !== 'all') {
            params.append('id_frente', frenteVal);
        }

        // Filtro Tipo
        const tipoVal = getHiddenVal('id_tipo', mvCard);
        if (tipoVal && tipoVal !== 'all') {
            params.append('id_tipo', tipoVal);
        }

        // Rango de fechas
        const fechaDesde = document.getElementById('filterFechaDesde');
        if (fechaDesde && fechaDesde.value) params.append('fecha_desde', fechaDesde.value);

        const fechaHasta = document.getElementById('filterFechaHasta');
        if (fechaHasta && fechaHasta.value) params.append('fecha_hasta', fechaHasta.value);

        // Dirección del frente
        const direccion = document.getElementById('filterDireccionFrente');
        if (direccion && direccion.value) params.append('direccion_frente', direccion.value);

        // Página (para paginación AJAX)
        if (pageUrl && typeof pageUrl === 'string') {
            try {
                const page = new URL(pageUrl, window.location.origin).searchParams.get('page');
                if (page) params.set('page', page);
            } catch (_) { /* URL inválida → ignorar */ }
        }

        const finalUrl = '/admin/movilizaciones?' + params.toString();

        const response = await fetch(finalUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        // Actualizar tabla
        tableBody.innerHTML = data.html || '';
        tableBody.style.opacity = '1';

        // Actualizar paginación
        const paginationDiv = document.getElementById('movilizacionesPagination');
        if (paginationDiv) paginationDiv.innerHTML = data.pagination || '';

        // Actualizar contador (sidebar de escritorio)
        const totalEl = document.getElementById('totalTransitoCount');
        if (totalEl && data.totalTransito !== undefined) totalEl.innerText = data.totalTransito;

        // Actualizar URL sin recargar
        if (window.history && window.history.pushState) {
            window.history.pushState(null, '', finalUrl);
        }

    } catch (e) {
        console.error('[loadMovilizaciones] Error:', e);
        const tb = document.getElementById('movilizacionesTableBody');
        if (tb) tb.style.opacity = '1';
    } finally {
        if (window.hidePreloader) window.hidePreloader();
    }
};

// ─── Paginación AJAX (delegación de eventos, una sola vez) ───────────────────
// stopImmediatePropagation() es CRÍTICO: evita que navegacion.js (SPA) también
// capture el click y haga una navegación completa que reinicie la tabla a pág. 1.
if (!window._mvPaginationRegistered) {
    window._mvPaginationRegistered = true;
    document.addEventListener('click', function (e) {
        const link = e.target.closest('#movilizacionesPagination a.page-link');
        if (link) {
            e.preventDefault();
            e.stopImmediatePropagation(); // Impide que el SPA capture este click
            window.loadMovilizaciones(link.href);
        }
    });
}

// ─── Dropdowns: Frente y Tipo (delegación de eventos, una sola vez) ──────────
// selectOption() en uicomponents.js dispara 'dropdown-selection' al cambiar valor.
if (!window._mvDropdownSelectionRegistered) {
    window._mvDropdownSelectionRegistered = true;
    window.addEventListener('dropdown-selection', function (e) {
        if (!document.getElementById('movilizacionesTableBody')) return;
        if (e.detail.dropdownId === 'frenteFilterSelect' || e.detail.dropdownId === 'tipoFilterSelect') {
            window.loadMovilizaciones();
        }
    });
}

// ─── Inicialización (carga directa o via SPA) ────────────────────────────────
function _mvInit() {
    if (!document.getElementById('movilizacionesTableBody')) return;

    // Sincronizar estado del panel de filtros avanzados con el DOM
    const panel = document.getElementById('advancedFilterPanel');
    if (panel) window.advancedFilterOpen = panel.style.display === 'block';

    // Si había parámetros activos en la URL, recargar via AJAX para consistencia
    if (window.location.search.length > 1) {
        window.loadMovilizaciones();
    }
}

// Carga directa (F5 o URL directa)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _mvInit);
} else {
    _mvInit();
}

// Navegación interna vía SPA
window.addEventListener('spa:contentLoaded', _mvInit);


// ═══════════════════════════════════════════════════════════════════════════════
// RECEPCIÓN DIRECTA
// ═══════════════════════════════════════════════════════════════════════════════

let rdEquiposSeleccionados = [];

window.abrirRecepcionDirecta = function () {
    const modal = document.getElementById('recepcionDirectaModal');
    if (!modal) return;

    rdEquiposSeleccionados = [];
    document.getElementById('rdSearchInput').value = '';
    document.getElementById('rdResultados').style.display = 'none';
    document.getElementById('rdUbicacionInput').value = '';

    // Pre-cargar subdivisiones del frente del usuario
    const idFrente = document.getElementById('rdFrenteInput').value;
    if (idFrente) {
        fetch(`/admin/movilizaciones/subdivisiones/${idFrente}`)
            .then(r => r.json())
            .then(data => {
                const subs = data.tiene_subdivisiones ? (data.subdivisiones || []) : [];
                window.loadUbicacionSuggestions('rd-ubicacion-suggestions', subs);
            })
            .catch(() => window.loadUbicacionSuggestions('rd-ubicacion-suggestions', []));
    } else {
        window.loadUbicacionSuggestions('rd-ubicacion-suggestions', []);
    }

    modal.style.display = 'flex';
};

window.cerrarRecepcionDirecta = function () {
    const modal = document.getElementById('recepcionDirectaModal');
    if (modal) modal.style.display = 'none';
};

window.buscarEquiposRD = function (fromEnter = false) {
    const search = document.getElementById('rdSearchInput').value.trim();
    const list = document.getElementById('rdResultadosList');
    const container = document.getElementById('rdResultados');

    if (!search) {
        container.style.display = 'none';
        list.innerHTML = '';
        return;
    }

    if (!fromEnter && search.length < 3) return;

    list.innerHTML = '<div style="padding:15px;text-align:center;color:#94a3b8;"><i class="material-icons spin">sync</i> Buscando...</div>';
    container.style.display = 'block';

    fetch(`/admin/movilizaciones/buscar-equipos-recepcion?search=${encodeURIComponent(search)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                list.innerHTML = '<div style="padding:15px;text-align:center;color:#94a3b8;">No se encontraron equipos</div>';
                return;
            }

            list.innerHTML = '';
            data.forEach(eq => {
                const isSelected = rdEquiposSeleccionados.some(s => s.ID_EQUIPO === eq.ID_EQUIPO);
                const item = document.createElement('div');
                item.id = `card-equipo-${eq.ID_EQUIPO}`;
                item.className = 'dropdown-item';

                const baseStyle = `display:flex;align-items:stretch;gap:0;transition:all 0.2s;cursor:default;position:relative;overflow:hidden;min-height:110px;`;
                item.style.cssText = isSelected
                    ? baseStyle + 'background:#f0f9ff;border:2px solid #0067b1;border-radius:12px;transform:scale(0.99);margin-bottom:8px;box-shadow:0 4px 6px -1px rgba(0,103,177,0.1);'
                    : baseStyle + 'border-bottom:1px solid #f1f5f9;';

                const radiusStyle = isSelected ? 'border-top-left-radius:10px;border-bottom-left-radius:10px;' : '';
                let fotoHtml = eq.FOTO
                    ? `<div style="width:85px;min-width:85px;align-self:stretch;position:relative;overflow:hidden;background:#f1f5f9;padding:4px;box-sizing:border-box;${radiusStyle}">
                           <img src="/storage/google/${eq.FOTO.replace(/^.*\/storage\/google\//, '').split('?')[0]}" alt="" loading="lazy"
                               style="width:100%;height:100%;object-fit:contain;border-radius:6px;"
                               onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                           <div style="display:none;width:100%;height:100%;align-items:center;justify-content:center;">
                               <span class="material-icons" style="font-size:32px;color:#cbd5e0;">directions_car</span>
                           </div>
                       </div>`
                    : `<div style="width:85px;min-width:85px;align-self:stretch;background:#f1f5f9;display:flex;align-items:center;justify-content:center;${radiusStyle}">
                           <span class="material-icons" style="font-size:32px;color:#cbd5e0;">directions_car</span>
                       </div>`;

                const tipo = eq.TIPO || '';
                const serial = eq.SERIAL_CHASIS || null;
                const placa = eq.PLACA && eq.PLACA !== 'S/P' ? eq.PLACA : null;
                const marcaModeloAnio = [eq.MARCA, eq.MODELO, eq.ANIO ? String(eq.ANIO) : ''].filter(Boolean).join(' · ');

                const warningFinalizado = eq.FRENTE_ACTUAL_ESTATUS === 'FINALIZADO'
                    ? '<span style="background:#fef2f2;color:#dc2626;padding:1px 6px;border-radius:10px;font-size:9px;font-weight:700;margin-left:4px;">FRENTE CERRADO</span>'
                    : '';

                item.innerHTML = `
                    <div style="display:flex;flex-direction:row;align-items:stretch;width:100%;">
                        ${fotoHtml}
                        <div style="flex:1;padding:12px 14px;display:flex;flex-direction:column;justify-content:center;gap:3px;min-width:0;">
                            <div style="font-weight:900;font-size:14px;color:#000;line-height:1.2;text-transform:uppercase;">${tipo}${warningFinalizado}</div>
                            <div style="font-size:13px;color:#000;font-weight:700;margin-bottom:4px;">${marcaModeloAnio || '<span style="color:#94a3b8;font-weight:400;font-style:italic;">Sin detalles</span>'}</div>
                            ${serial ? `<div style="font-size:12px;color:#000;font-weight:600;">${serial}</div>` : ''}
                            ${placa ? `<div style="font-size:12px;color:#000;font-weight:600;">P: ${placa}</div>` : ''}
                            <div style="font-size:11px;color:#94a3b8;margin-top:4px;display:flex;align-items:center;gap:4px;">
                                <i class="material-icons" style="font-size:12px;">place</i>${eq.FRENTE_ACTUAL}
                            </div>
                        </div>
                        ${isSelected ? `
                        <div class="rd-check-indicator" style="display:flex;align-items:center;padding-right:15px;">
                            <div style="width:28px;height:28px;background:#0067b1;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                                <i class="material-icons" style="color:white;font-size:18px;">check</i>
                            </div>
                        </div>` : ''}
                    </div>`;

                item.onclick = function () {
                    const sel = rdEquiposSeleccionados.some(s => s.ID_EQUIPO === eq.ID_EQUIPO);
                    if (sel) rdRemoverEquipo(eq.ID_EQUIPO);
                    else rdAgregarEquipo(eq);
                    rdToggleVisual(document.getElementById(`card-equipo-${eq.ID_EQUIPO}`), !sel);
                };

                if (!isSelected) {
                    item.onmouseover = () => { item.style.background = '#f8fafc'; };
                    item.onmouseout = () => { item.style.background = 'white'; };
                }

                list.appendChild(item);
            });
        })
        .catch(e => {
            console.error('[buscarEquiposRD]', e);
            list.innerHTML = '<div style="padding:15px;text-align:center;color:#dc2626;">Error al buscar</div>';
        });
};

function rdAgregarEquipo(eq) {
    if (!rdEquiposSeleccionados.some(s => s.ID_EQUIPO === eq.ID_EQUIPO)) {
        rdEquiposSeleccionados.push(eq);
    }
}

function rdRemoverEquipo(id) {
    rdEquiposSeleccionados = rdEquiposSeleccionados.filter(s => s.ID_EQUIPO !== id);
    const card = document.getElementById(`card-equipo-${id}`);
    if (card) rdToggleVisual(card, false);
}

function rdToggleVisual(card, isSelected) {
    if (!card) return;
    let checkDiv = card.querySelector('.rd-check-indicator');

    if (isSelected) {
        card.style.cssText = card.style.cssText.replace(/border-bottom.*?;/, '');
        card.style.background = '#f0f9ff';
        card.style.border = '2px solid #0067b1';
        card.style.borderRadius = '12px';
        card.style.transform = 'scale(0.99)';
        card.style.marginBottom = '8px';
        card.style.boxShadow = '0 4px 6px -1px rgba(0,103,177,0.1)';

        const fotoDiv = card.querySelector('div[style*="min-width:85px"]');
        if (fotoDiv) {
            fotoDiv.style.borderTopLeftRadius = '10px';
            fotoDiv.style.borderBottomLeftRadius = '10px';
        }

        if (!checkDiv) {
            checkDiv = document.createElement('div');
            checkDiv.className = 'rd-check-indicator';
            checkDiv.style.cssText = 'display:flex;align-items:center;padding-right:15px;';
            checkDiv.innerHTML = `<div style="width:28px;height:28px;background:#0067b1;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 4px rgba(0,0,0,0.1);"><i class="material-icons" style="color:white;font-size:18px;">check</i></div>`;
            card.firstElementChild.appendChild(checkDiv);
        }

        card.onmouseover = null;
        card.onmouseout = null;
    } else {
        card.style.background = 'white';
        card.style.border = '';
        card.style.borderBottom = '1px solid #f1f5f9';
        card.style.borderRadius = '';
        card.style.transform = '';
        card.style.marginBottom = '';
        card.style.boxShadow = '';

        const fotoDiv = card.querySelector('div[style*="min-width:85px"]');
        if (fotoDiv) {
            fotoDiv.style.borderTopLeftRadius = '0';
            fotoDiv.style.borderBottomLeftRadius = '0';
        }

        if (checkDiv) checkDiv.remove();

        card.onmouseover = () => { card.style.background = '#f8fafc'; };
        card.onmouseout = () => { card.style.background = 'white'; };
    }
}

window.confirmarRecepcionDirecta = function () {
    const ids = rdEquiposSeleccionados.map(s => s.ID_EQUIPO);
    const idFrente = document.getElementById('rdFrenteInput').value;
    const ubicacion = document.getElementById('rdUbicacionInput').value;

    if (!ids.length) {
        if (typeof window.showModal === 'function') {
            window.showModal({ type: 'warning', title: 'Atención', message: 'Seleccione al menos un equipo para continuar.', confirmText: 'Entendido', hideCancel: true });
        }
        return;
    }

    const btn = document.getElementById('btnConfirmarRD');
    if (!btn || btn.disabled) return;
    btn.disabled = true;

    window.cerrarRecepcionDirecta();

    const csrf = window.getCsrf();

    fetch('/admin/movilizaciones/recepcion-directa', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({ ids, ID_FRENTE_DESTINO: idFrente, DETALLE_UBICACION: ubicacion })
    })
        .then(r => {
            if (r.status === 403) {
                window.showModal({ type: 'warning', title: 'Sin Permisos', message: 'No tienes permiso para confirmar la recepción.', confirmText: 'Entendido', hideCancel: true });
                throw new Error('403');
            }
            return r.json();
        })
        .then(data => {
            if (data.success) {
                if (typeof window.showToast === 'function') {
                    window.showToast(data.message || 'Recepción directa registrada correctamente.', 'success');
                } else if (typeof window.showModal === 'function') {
                    window.showModal({
                        type: 'success',
                        title: '¡Recepción Exitosa!',
                        message: data.message || 'Recepción directa registrada correctamente.',
                        confirmText: 'Aceptar',
                        hideCancel: true
                    });
                }
                // En la página de movilizaciones: refresca la tabla AJAX
                // En el menú (sin tabla): recarga la página para actualizar la lista pendiente
                if (document.getElementById('movilizacionesTableBody')) {
                    window.loadMovilizaciones();
                } else {
                    window.location.reload();
                }
            } else {
                if (typeof window.showModal === 'function') {
                    window.showModal({ type: 'error', title: 'Error', message: data.error || data.message || 'No se pudo procesar.', confirmText: 'Cerrar', hideCancel: true });
                }
            }
        })
        .catch(e => {
            if (e.message === '403') return;
            console.error('[confirmarRecepcionDirecta]', e);
            window.showModal({ type: 'error', title: 'Error de Conexión', message: 'Error de comunicación con el servidor.', confirmText: 'Cerrar', hideCancel: true });
        })
        .finally(() => { btn.disabled = false; });
};


// ═══════════════════════════════════════════════════════════════════════════════
// FILTROS AVANZADOS (Fechas y Dirección)
// ═══════════════════════════════════════════════════════════════════════════════

window.advancedFilterOpen = false;

window.toggleAdvancedFilter = function (e) {
    if (e) e.stopPropagation();
    const panel = document.getElementById('advancedFilterPanel');
    const btn = document.getElementById('btnAdvancedFilter');
    const accionesMenu = document.getElementById('splitDropdownMenuMov');
    if (!panel) return;

    if (accionesMenu && accionesMenu.style.display === 'block') {
        accionesMenu.style.display = 'none';
    }

    window.advancedFilterOpen = !window.advancedFilterOpen;

    if (window.advancedFilterOpen) {
        panel.style.display = 'block';
        btn.style.background = '#e1effa';
        btn.style.borderColor = '#0067b1';
        btn.style.color = '#0067b1';
    } else {
        panel.style.display = 'none';
        btn.style.background = 'white';
        btn.style.borderColor = '#cbd5e0';
        btn.style.color = '#64748b';
    }
};

window.toggleAccionesMov = function (e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('splitDropdownMenuMov');
    if (!menu) return;

    if (window.advancedFilterOpen) {
        window.toggleAdvancedFilter();
    }

    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
};

// Cerrar panel al hacer click fuera
if (!window._mvPanelClickListenerRegistered) {
    window._mvPanelClickListenerRegistered = true;
    document.addEventListener('click', function (e) {
        const panel = document.getElementById('advancedFilterPanel');
        const btn = document.getElementById('btnAdvancedFilter');
        if (panel && window.advancedFilterOpen) {
            if (!panel.contains(e.target) && btn && !btn.contains(e.target)) {
                panel.style.display = 'none';
                btn.style.background = 'white';
                btn.style.borderColor = '#cbd5e0';
                btn.style.color = '#64748b';
                window.advancedFilterOpen = false;
            }
        }

        const accionesMenu = document.getElementById('splitDropdownMenuMov');
        const btnAcciones = document.getElementById('btnAccionesMov');
        if (accionesMenu && accionesMenu.style.display === 'block') {
            if (!accionesMenu.contains(e.target) && btnAcciones && !btnAcciones.contains(e.target)) {
                accionesMenu.style.display = 'none';
            }
        }
    });
}

window.clearDateFilters = function () {
    const desde = document.getElementById('filterFechaDesde');
    const hasta = document.getElementById('filterFechaHasta');
    if (desde) desde.value = '';
    if (hasta) hasta.value = '';
    window.setDireccionFilter('', false);
    window.loadMovilizaciones();
};

window.setDireccionFilter = function (value, reload = true) {
    const input = document.getElementById('filterDireccionFrente');
    if (input) input.value = value;

    const styles = {
        filterDireccionTodas: { active: !value, border: '#0067b1', bg: '#e1effa', color: '#0067b1' },
        filterDireccionEntrada: { active: value === 'entrada', border: '#16a34a', bg: '#dcfce7', color: '#16a34a' },
        filterDireccionSalida: { active: value === 'salida', border: '#dc2626', bg: '#fee2e2', color: '#dc2626' }
    };

    Object.entries(styles).forEach(([id, s]) => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.style.border = `1px solid ${s.active ? s.border : '#e2e8f0'}`;
        btn.style.background = s.active ? s.bg : 'white';
        btn.style.color = s.active ? s.color : '#64748b';
    });

    // Indicador en el botón principal
    const btnAdv = document.getElementById('btnAdvancedFilter');
    const fechaDesde = document.getElementById('filterFechaDesde');
    const fechaHasta = document.getElementById('filterFechaHasta');
    if (btnAdv) {
        const anyActive = value || fechaDesde?.value || fechaHasta?.value;
        btnAdv.style.background = anyActive ? '#e1effa' : 'white';
        btnAdv.style.borderColor = anyActive ? '#0067b1' : '#cbd5e0';
        btnAdv.style.color = anyActive ? '#0067b1' : '#64748b';
    }

    if (reload) window.loadMovilizaciones();
};


// ═══════════════════════════════════════════════════════════════════════════════
// SUGERENCIAS DE UBICACIÓN (Recepción Directa)
// ═══════════════════════════════════════════════════════════════════════════════

window.loadUbicacionSuggestions = function (containerId, items) {
    const box = document.getElementById(containerId);
    if (!box) return;
    box._allItems = items || [];
    _renderUbicacionSuggestions(box, box._allItems);
};

function _renderUbicacionSuggestions(box, items) {
    box.innerHTML = '';
    if (!items || !items.length) { box.style.display = 'none'; return; }

    const input = box.closest('div').querySelector('input[type="text"]');
    items.forEach(item => {
        const d = document.createElement('div');
        d.textContent = item;
        d.style.cssText = 'padding:9px 14px;font-size:13px;color:#1e293b;cursor:default;border-bottom:1px solid #f1f5f9;transition:background 0.15s;';
        d.onmouseover = () => d.style.background = '#f0f9ff';
        d.onmouseout = () => d.style.background = 'white';
        d.onmousedown = (e) => {
            e.preventDefault();
            if (input) input.value = item;
            box.style.display = 'none';
        };
        box.appendChild(d);
    });
}

window.showUbicacionSuggestions = function (containerId) {
    const box = document.getElementById(containerId);
    if (!box || !box._allItems || !box._allItems.length) return;
    const input = box.parentElement.querySelector('input[type="text"]');
    const typed = input ? input.value.trim().toUpperCase() : '';
    const filtered = typed ? box._allItems.filter(i => i.toUpperCase().includes(typed)) : box._allItems;
    if (!filtered.length) { box.style.display = 'none'; return; }
    _renderUbicacionSuggestions(box, filtered);
    box.style.display = 'block';
};

window.hideUbicacionSuggestions = function (containerId) {
    const box = document.getElementById(containerId);
    if (box) box.style.display = 'none';
};

window.filterUbicacionSuggestions = function (input, containerId) {
    const box = document.getElementById(containerId);
    if (!box || !box._allItems) return;
    const typed = input.value.trim().toUpperCase();
    const filtered = typed ? box._allItems.filter(i => i.toUpperCase().includes(typed)) : box._allItems;
    if (!filtered.length) { box.style.display = 'none'; return; }
    _renderUbicacionSuggestions(box, filtered);
    box.style.display = 'block';
};

// ═══════════════════════════════════════════════════════════════════════════════
// SELECCIÓN Y ELIMINACIÓN (SPA COMPATIBLE)
// ═══════════════════════════════════════════════════════════════════════════════

// Set global de IDs seleccionados — persiste entre cambios de página AJAX
if (!window._mvSelectedIds) {
    window._mvSelectedIds = new Set();
}

// (El cierre del dropdown de Acciones al clic fuera ya lo maneja el listener con
//  guard _mvPanelClickListenerRegistered de arriba — no se duplica aquí.)

// ── Aplica/quita el estilo de selección en una fila ──
window.applyRowStyleMv = function (tr, selected) {
    if (selected) {
        tr.classList.add('selected-row-maquinaria');
    } else {
        tr.classList.remove('selected-row-maquinaria');
    }
}

// ── Actualiza el chip contador ──
window.updateChipMv = function () {
    const chip = document.getElementById('mv-selection-chip');
    const count = document.getElementById('mv-selection-count');
    if (!chip || !count) return;

    const n = window._mvSelectedIds.size;
    count.textContent = n;
    if (n > 0) {
        chip.classList.add('active');
    } else {
        chip.classList.remove('active');
    }
}

// ── Re-aplica estilos a todas las filas visibles tras carga AJAX ──
window.reapplyStylesMv = function () {
    document.querySelectorAll('.mv-selectable-row').forEach(tr => {
        const id = tr.dataset.mvId;
        window.applyRowStyleMv(tr, window._mvSelectedIds.has(id));
    });
    window.updateChipMv();
}

// ── Delegación global de clicks (registrada UNA sola vez por sesión) ──
if (!window._mvRowClickRegistered) {
    window._mvRowClickRegistered = true;

    document.addEventListener('click', function (e) {
        // Ignorar cualquier botón, enlace o dropdown (no deben togglear nada).
        if (e.target.closest('.custom-dropdown') || e.target.closest('button') || e.target.closest('a')) return;

        const tr = e.target.closest('.mv-selectable-row');
        if (!tr) return;

        // ── Móvil (tarjeta): el tap DESPLIEGA el detalle (fecha/hora + PDF +
        // usuario + deshacer). Acordeón: solo una tarjeta abierta a la vez — al
        // abrir una se cierran las demás, nunca hay dos desplegadas. La selección
        // para borrado en lote es solo de escritorio. El layout de tarjeta se
        // activa a <=1024px (ver estilos_globales.css).
        if (window.innerWidth <= 1024) {
            const abrir = !tr.classList.contains('mv-expanded');
            document.querySelectorAll('#movilizacionesTable tr.mv-row-card.mv-expanded')
                .forEach(function (r) { if (r !== tr) r.classList.remove('mv-expanded'); });
            tr.classList.toggle('mv-expanded', abrir);
            return;
        }

        // ── Desktop (tabla): el click SELECCIONA la fila (para eliminar en lote) ──
        const rowId = tr.dataset.mvId;
        if (!rowId) return;

        if (window._mvSelectedIds.has(rowId)) {
            window._mvSelectedIds.delete(rowId);
            window.applyRowStyleMv(tr, false);
        } else {
            window._mvSelectedIds.add(rowId);
            window.applyRowStyleMv(tr, true);
        }
        window.updateChipMv();
    });
}

// ── Limpiar selección ──
window.mvClearSelection = function () {
    window._mvSelectedIds.clear();
    document.querySelectorAll('.selected-row-maquinaria').forEach(tr => tr.classList.remove('selected-row-maquinaria'));
    window.updateChipMv();
};

// ── Hook post-AJAX: re-aplicar estilos cuando loadMovilizaciones actualiza el tbody ──
if (!window._mvLoadHooked) {
    window._mvLoadHooked = true;
    const _origLoad = window.loadMovilizaciones;
    window.loadMovilizaciones = async function (...args) {
        await _origLoad(...args);
        window.reapplyStylesMv();
    };
}


// ── Aplicar al cargar la página en SPA
window.addEventListener('spa:contentLoaded', function () {
    if (window._mvSelectedIds) {
        window._mvSelectedIds.clear();
    }
    window.reapplyStylesMv();
});
