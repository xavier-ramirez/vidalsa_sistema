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

        // Página (para paginación AJAX)
        if (pageUrl && typeof pageUrl === 'string') {
            try {
                const page = new URL(pageUrl, window.location.origin).searchParams.get('page');
                if (page) params.set('page', page);
            } catch (_) { }
        }

        const finalUrl = '/admin/historial-documentos?' + params.toString();

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
    });
}

// ── Limpiar selección ──
window.hdClearSelection = function() {
    window._hdSelectedIds.clear();
    document.querySelectorAll('.hd-selectable-row.selected-row-maquinaria').forEach(tr => tr.classList.remove('selected-row-maquinaria'));
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
                const token = document.querySelector('meta[name="csrf-token"]');
                const response = await fetch('/admin/historial-documentos/unlock-ip/' + id, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': token ? token.content : '',
                        'Accept': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (window.hidePreloader) window.hidePreloader();
                
                if (data.success) {
                    if (window.showToast) window.showToast(data.message, 'success');
                    
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
                    if (window.showToast) window.showToast(data.message || 'Error al desbloquear la IP', 'error');
                }
            } catch (error) {
                if (window.hidePreloader) window.hidePreloader();
                console.error('Error unlocking IP:', error);
                if (window.showToast) window.showToast('Error de red al intentar desbloquear la IP', 'error');
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

