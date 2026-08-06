/**
 * Lógica AJAX de filtros, búsqueda y paginación para /admin/historial-documentos.
 * Compatible con SPA (navegacion.js): se inicializa en cada carga de contenido.
 */

window.loadHistorialDocumentos = async function (pageUrl = null) {
    const tableBody = document.getElementById('historialTableBody');
    if (!tableBody) return;

    if (window.showPreloader) window.showPreloader();
    tableBody.style.opacity = '0.5';

    try {
        const params = new URLSearchParams();

        // 1. Filtro de Correo (Autor)
        const sCorreo = document.getElementById('searchCorreo');
        if (sCorreo && sCorreo.value.trim()) {
            params.append('search_correo', sCorreo.value.trim());
        }

        // 2. Filtro de Equipo (Placa/Serial)
        const sEquipo = document.getElementById('searchEquipo');
        if (sEquipo && sEquipo.value.trim()) {
            params.append('search_equipo', sEquipo.value.trim());
        }

        // 3. Filtro Tipo
        const mCard = document.querySelector('.admin-card');
        if (mCard) {
            const el = mCard.querySelector('input[name="search_tipo"][data-filter-value]');
            if (el && el.value.trim() && el.value.trim() !== 'all') {
                params.append('search_tipo', el.value.trim());
            }
        }

        // 4. Filtros avanzados: rango de fechas (panel "Filtros Avanzados")
        const fDesde = document.getElementById('hdFechaDesde');
        if (fDesde && fDesde.value) {
            params.append('fecha_desde', fDesde.value);
        }
        const fHasta = document.getElementById('hdFechaHasta');
        if (fHasta && fHasta.value) {
            params.append('fecha_hasta', fHasta.value);
        }

        // Página (para paginación AJAX)
        if (pageUrl && typeof pageUrl === 'string') {
            try {
                const page = new URL(pageUrl, window.location.origin).searchParams.get('page');
                if (page) params.set('page', page);
            } catch (_) { }
        }

        const finalUrl = '/admin/historial-documentos?' + params.toString();

        // "Ver solo seleccionados": cuando el contador está en modo filtro, se pide
        // SOLO la whitelist de eventos seleccionados (hash md5, mismo que data-hd-id),
        // ignorando los demás filtros — mismo patrón que ids_in en Equipos. Es estado
        // EFÍMERO del contador: va al fetch pero NO al pushState de la URL.
        let fetchUrl = finalUrl;
        if (window._hdSoloSel && window._hdSelectedIds && window._hdSelectedIds.size) {
            const ids = Array.from(window._hdSelectedIds);
            fetchUrl = '/admin/historial-documentos?hd_ids=' + encodeURIComponent(ids.join(','));
            const pg = params.get('page');
            if (pg) fetchUrl += '&page=' + pg;
        }

        const response = await window.apiFetch(fetchUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });

        if (!response.ok) throw new Error('HTTP ' + response.status);

        const data = await response.json();

        // Actualizar tabla
        tableBody.innerHTML = data.html || '';
        tableBody.style.opacity = '1';

        // Actualizar paginación
        const paginationDiv = document.getElementById('historialPagination');
        if (paginationDiv) paginationDiv.innerHTML = data.pagination || '';

        // Actualizar contador
        const contador = document.getElementById('historial-count-text');
        if (contador && data.total !== undefined) contador.innerText = data.total;

        // Actualizar URL sin recargar
        if (window.history && window.history.pushState) {
            window.history.pushState(null, '', finalUrl);
        }

    } catch (e) {
        console.error('[loadHistorialDocumentos] Error:', e);
        if (tableBody) tableBody.style.opacity = '1';
    } finally {
        if (window.hidePreloader) window.hidePreloader();
    }
};

if (!window._hdPaginationRegistered) {
    window._hdPaginationRegistered = true;
    document.addEventListener('click', function (e) {
        const link = e.target.closest('#historialPagination a.page-link');
        if (link) {
            e.preventDefault();
            e.stopImmediatePropagation();
            window.loadHistorialDocumentos(link.href);
        }
    });

    window.addEventListener('dropdown-selection', function (e) {
        if (!document.getElementById('historialTableBody')) return;
        if (e.detail.dropdownId === 'tipoDocFilterSelect') {
            window.loadHistorialDocumentos();
        }
    });
}

function _hdInit() {
    if (!document.getElementById('historialTableBody')) return;
    if (window.location.search.length > 1) {
        window.loadHistorialDocumentos();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _hdInit);
} else {
    _hdInit();
}

window.addEventListener('spa:contentLoaded', _hdInit);

window.clearHistorialFilter = function(filterId, inputId) {
    const input = document.getElementById(inputId);
    if(input) input.value = '';
    const btn = document.getElementById(filterId);
    if(btn) btn.style.display = 'none';
    window.loadHistorialDocumentos();
};

window.checkHistorialClearBtn = function(inputId, btnId) {
    const input = document.getElementById(inputId);
    const btn = document.getElementById(btnId);
    if(input && btn) {
        btn.style.display = input.value.trim().length > 0 ? 'block' : 'none';
    }
};

// Toggle de X para inputs type=date (Fecha desde / Fecha hasta).
// El picker nativo emite onchange tanto al elegir como al borrar, asi la X
// se sincroniza con el valor real del input sin depender de keyup.
window.hdToggleDateClear = function(inputId, btnId) {
    const input = document.getElementById(inputId);
    const btn = document.getElementById(btnId);
    if(input && btn) {
        btn.style.display = input.value ? 'inline-flex' : 'none';
    }
};

// Listeners manuales para los text inputs
document.addEventListener('DOMContentLoaded', function() {
    const inputs = ['searchCorreo', 'searchEquipo'];
    inputs.forEach(id => {
        const input = document.getElementById(id);
        if(input) {
            input.addEventListener('keyup', function(e) {
                window.checkHistorialClearBtn(id, 'btn_clear_' + id);
                if (e.key === 'Enter') {
                    window.loadHistorialDocumentos();
                }
            });
        }
    });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SELECCIÓN DE FILAS Y CONTADOR FLOTANTE (SPA COMPATIBLE)
// ═══════════════════════════════════════════════════════════════════════════════

if (!window._hdSelectedIds) {
    window._hdSelectedIds = new Set();
}

// ── Aplica/quita el estilo de selección ──
window.applyRowStyleHd = function(tr, selected) {
    if (selected) {
        tr.classList.add('selected-row-maquinaria');
    } else {
        tr.classList.remove('selected-row-maquinaria');
    }
};

// ── Actualiza el chip contador ──
window.updateChipHd = function() {
    const chip  = document.getElementById('hd-selection-chip');
    const count = document.getElementById('hd-selection-count');
    if (!chip || !count) return;

    const n = window._hdSelectedIds.size;
    count.textContent = n;
    if (n > 0) {
        chip.classList.add('active');
    } else {
        chip.classList.remove('active');
    }
};

// ── Re-aplica estilos tras carga AJAX ──
window.reapplyStylesHd = function() {
    document.querySelectorAll('.hd-selectable-row').forEach(tr => {
        const id = tr.dataset.hdId;
        window.applyRowStyleHd(tr, window._hdSelectedIds.has(id));
    });
    window.updateChipHd();
};

// ── Attacher de click (delegación global) ──
if (!window._hdRowClickRegistered) {
    window._hdRowClickRegistered = true;

    document.addEventListener('click', function(e) {
        if (e.target.closest('.custom-dropdown') || e.target.closest('button') || e.target.closest('a')) return;

        const tr = e.target.closest('.hd-selectable-row');
        if (!tr) return;

        if (tr.classList.contains('hd-has-cambios')) return;

        const id = tr.dataset.hdId;
        if (!id) return;

        if (window._hdSelectedIds.has(id)) {
            window._hdSelectedIds.delete(id);
            window.applyRowStyleHd(tr, false);
        } else {
            window._hdSelectedIds.add(id);
            window.applyRowStyleHd(tr, true);
        }
        window.updateChipHd();

        // Si se vacía la selección mientras "ver solo seleccionados" está activo,
        // apagarlo y volver a la vista normal (evita quedar con tabla vacía).
        if (window._hdSoloSel && window._hdSelectedIds.size === 0) {
            window._hdSoloSel = false;
            const counter = document.querySelector('#hd-selection-chip .selection-counter');
            if (counter) counter.classList.remove('is-filtering');
            window.loadHistorialDocumentos();
        }
    });
}

// ── "Ver solo seleccionados": al tocar el contador filtra la tabla mostrando
//    ÚNICAMENTE los eventos seleccionados (whitelist server-side vía hd_ids) y
//    resalta el número en un círculo ámbar. Volver a tocar lo apaga. Mismo patrón
//    que el contador del módulo Equipos (toggleEquiposSoloSel). ──
window.hdToggleSoloSel = function (e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    if (!window._hdSelectedIds || !window._hdSelectedIds.size) {
        if (typeof window.showToast === 'function') {
            window.showToast('No hay eventos seleccionados todavía.', 'error');
        }
        return;
    }
    window._hdSoloSel = !window._hdSoloSel;
    const counter = document.querySelector('#hd-selection-chip .selection-counter');
    if (counter) counter.classList.toggle('is-filtering', window._hdSoloSel);
    window.loadHistorialDocumentos();
};

// ── Limpiar selección ──
window.hdClearSelection = function() {
    window._hdSelectedIds.clear();
    document.querySelectorAll('.hd-selectable-row.selected-row-maquinaria').forEach(tr => tr.classList.remove('selected-row-maquinaria'));
    // Si estaba "ver solo seleccionados", apagarlo y recargar la vista normal.
    if (window._hdSoloSel) {
        window._hdSoloSel = false;
        const counter = document.querySelector('#hd-selection-chip .selection-counter');
        if (counter) counter.classList.remove('is-filtering');
        window.loadHistorialDocumentos();
    }
    window.updateChipHd();
};

// ── Hook post-AJAX para re-aplicar estilos en paginación/filtros ──
if (!window._hdLoadHooked) {
    window._hdLoadHooked = true;
    const _origLoadHd = window.loadHistorialDocumentos;
    window.loadHistorialDocumentos = async function(...args) {
        await _origLoadHd(...args);
        window.reapplyStylesHd();
    };
}

// ── Aplicar al cargar la página en SPA ──
window.addEventListener('spa:contentLoaded', function() {
    if (window._hdSelectedIds) {
        window._hdSelectedIds.clear();
    }
    // Resetear "ver solo seleccionados" y quitar el círculo ámbar al cambiar de vista.
    window._hdSoloSel = false;
    const counter = document.querySelector('#hd-selection-chip .selection-counter');
    if (counter) counter.classList.remove('is-filtering');
    setTimeout(window.reapplyStylesHd, 50);
});

// ═══════════════════════════════════════════════════════════════════════════════
// GESTIÓN DE IPS BLOQUEADAS
// ═══════════════════════════════════════════════════════════════════════════════
window.unlockIp = function(id, ipAddress) {
    if (typeof window.showModal !== 'function') {
        console.error("showModal no está definido");
        return;
    }
    
    window.showModal({
        type: 'danger',
        title: 'Desbloquear IP',
        message: 'Esta acción eliminará el bloqueo de la IP ' + ipAddress + '.<br>¿Continuar?',
        confirmText: 'Sí, Desbloquear',
        cancelText: 'Cancelar',
        onConfirm: async () => {
            if (window.showPreloader) window.showPreloader();
            
            try {
                const response = await window.apiFetch('/admin/historial-documentos/unlock-ip/' + id, { headers: { 'Accept': 'application/json' },
                    method: 'DELETE'});
                
                const data = await response.json();
                
                if (window.hidePreloader) window.hidePreloader();
                
                if (data.success) {
                    window.toast(data.message, 'success');
                    
                    const ipElement = document.getElementById('blocked-ip-' + id);
                    if (ipElement) {
                        ipElement.style.transition = 'all 0.3s ease';
                        ipElement.style.opacity = '0';
                        ipElement.style.transform = 'translateX(10px)';
                        setTimeout(function() {
                            ipElement.remove();
                            var countElement = document.getElementById('blocked-ip-count');
                            if (countElement) {
                                var currentCount = parseInt(countElement.innerText);
                                if (currentCount > 1) {
                                    countElement.innerText = currentCount - 1;
                                } else {
                                    var container = document.getElementById('blocked-ips-container');
                                    if (container) container.style.display = 'none';
                                }
                            }
                        }, 300);
                    }
                } else {
                    window.toast(data.message || 'Error al desbloquear la IP', 'error');
                }
            } catch (error) {
                if (window.hidePreloader) window.hidePreloader();
                console.error('Error unlocking IP:', error);
                window.toast('Error de red al intentar desbloquear la IP', 'error');
            }
        }
    });
};

// Delegado de eventos para botones .btn-unlock-ip
if (!window._hdIpClickRegistered) {
    window._hdIpClickRegistered = true;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-unlock-ip');
        if (!btn) return;
        var id = btn.dataset.ipId;
        var ip = btn.dataset.ipAddress;
        if (id && ip) {
            window.unlockIp(id, ip);
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
// FILTRO EN TIEMPO REAL — IPs BLOQUEADAS
// ═══════════════════════════════════════════════════════════════════════════════
/**
 * Filtra los ítems del listado de IPs bloqueadas en tiempo real.
 * Solo opera sobre el DOM — no hace ninguna petición al servidor.
 * @param {string} query - Texto ingresado por el usuario
 */
window.filterBlockedIps = function(query) {
    var list  = document.getElementById('blocked-ips-list');
    var empty = document.getElementById('ip-filter-empty');
    if (!list) return;

    var term    = (query || '').trim().toLowerCase();
    var items   = list.querySelectorAll('[data-ip-text]');
    var visible = 0;

    items.forEach(function(item) {
        var ipText = (item.dataset.ipText || '').toLowerCase();
        var match  = !term || ipText.indexOf(term) !== -1;
        item.style.display = match ? '' : 'none';
        if (match) visible++;
    });

    // Mostrar/ocultar mensaje de sin resultados
    if (empty) {
        empty.style.display = (term && visible === 0) ? 'block' : 'none';
    }
};

