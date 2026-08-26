// menu.js - Dashboard Interaction Logic
// Handlers for "Movilizaciones Hoy" and "Alertas Documentos" cards

// Assign directly to window to ensure global availability across SPA navigation
// No IIFE wrapper to prevent any scoping issues

window.toggleExpiredDocs = function () {
    const overlay = document.getElementById('expiredDocsContainer');
    if (!overlay) return;
    const isOpen = overlay.classList.toggle('open');
    // Evita scroll del body mientras el modal está abierto
    document.body.style.overflow = isOpen ? 'hidden' : '';
    if (isOpen) {
        // Autofocus al buscador para UX rápida
        const searchInput = document.getElementById('alertSearch');
        if (searchInput) setTimeout(() => searchInput.focus(), 60);
    }
};

// Cerrar modal de alertas con tecla Escape (registrado una sola vez)
if (!window._alertasModalEscHandler) {
    window._alertasModalEscHandler = true;
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        const overlay = document.getElementById('expiredDocsContainer');
        if (overlay && overlay.classList.contains('open')) {
            window.toggleExpiredDocs();
        }
    });
}

// togglePendingMovs removido: la funcionalidad "Equipos Por Confirmar" se eliminó
// por completo (vivía en el centro de notificaciones del navbar, ya removido).

// Event Delegation para "Gestionar" fue reemplazada por onclick directo
// usando window.iniciarGestionCustom para evitar conflictos de propagación.

// Function to refresh alerts list via AJAX without page reload
window.refreshDashboardAlerts = async function () {
    const listContainer = document.getElementById('dashboardAlertsList');
    if (!listContainer) return;

    try {
        // Add timestamp to prevent browser caching
        const response = await window.apiFetch(`/dashboard/alerts-html?t=${Date.now()}`);
        if (!response.ok) throw new Error('Network response was not ok');

        const data = await response.json();

        // Update List HTML — use !== undefined to handle empty list (all docs up to date)
        if (data.html !== undefined) {
            listContainer.innerHTML = data.html;
            // Re-apply fade-in effect if desired
            listContainer.style.opacity = '0';
            setTimeout(() => {
                listContainer.style.transition = 'opacity 0.3s ease';
                listContainer.style.opacity = '1';
            }, 50);
        }

        // Contador "Por Renovar" de la card + campanita. La clase es .alertas-card-value
        // (el rediseño del dashboard renombró la vieja .card-yellow .card-value, que ya
        // no existe en el blade: con el selector viejo el número se quedaba congelado
        // hasta recargar la página aunque la lista sí se refrescara).
        if (data.totalAlerts !== undefined) {
            const totalBadge = document.querySelector('.alertas-card-value');
            if (totalBadge) totalBadge.innerText = data.totalAlerts;
            // Misma regla que menu.blade.php: solo se agita si queda algo por renovar.
            const bell = document.querySelector('.alertas-card-icon .material-icons');
            if (bell) bell.classList.toggle('bell-shake', data.totalAlerts > 0);
        }

    } catch (error) {
        console.error('Failed to refresh dashboard alerts:', error);
    }
};

// Function to filter dashboard alerts by search input
window.filterDashboardAlerts = function () {
    const input = document.getElementById('alertSearch');
    if (!input) return;

    const normalizeStr = str => str ? str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : '';
    const filter = normalizeStr(input.value);

    const list = document.getElementById('dashboardAlertsList');
    if (!list) return;

    const items = list.querySelectorAll('.alert-card');
    let hasVisibleItems = false;

    items.forEach(item => {
        const textToSearch = [
            item.getAttribute('data-placa'),
            item.getAttribute('data-chasis'),
            item.getAttribute('data-motor-serial'),
            item.getAttribute('data-marca'),
            item.getAttribute('data-modelo'),
            item.innerText
        ].map(normalizeStr).join(' ');

        if (textToSearch.indexOf(filter) > -1) {
            // IMPORTANTE: restaurar a "flex" (NO ""). El display:flex de .alert-card es
            // inline y no hay regla CSS que lo respalde; con "" la tarjeta volvería a
            // display:block y los botones (visibility/gestión) caerían debajo del texto.
            item.style.display = "flex";
            hasVisibleItems = true;
        } else {
            item.style.display = "none";
        }
    });

    // Ocultar los encabezados de sección (Vencidas / Próximas) mientras hay búsqueda
    // activa: con el filtro la lista es plana, y dejarlos visibles mostraría "Vencidas 30"
    // aunque no haya coincidencias. Al limpiar la búsqueda se restauran.
    list.querySelectorAll('.js-alert-section-header').forEach(h => {
        h.style.display = filter.length > 0 ? 'none' : 'flex';
    });

    let emptyState = document.getElementById('search-empty-state');
    if (!hasVisibleItems && filter.length > 0) {
        if (!emptyState) {
            emptyState = document.createElement('div');
            emptyState.id = 'search-empty-state';
            emptyState.style.padding = '20px';
            emptyState.style.textAlign = 'center';
            emptyState.style.color = '#64748b';
            emptyState.innerHTML = `<p>No se encontraron resultados.</p>`;
            list.appendChild(emptyState);
        } else {
            emptyState.style.display = 'block';
        }
    } else if (emptyState) {
        emptyState.style.display = 'none';
    }
};

// filterPendingMovs removido: la b\u00fasqueda ya no aplica, la lista vive en el dropdown
// de notificaciones del navbar, que muestra un set acotado sin filtro UI.

// Modal compacto para iniciar gestión
window.iniciarGestionCustom = function (equipoId, docType, event) {
    if (event) {
        event.stopPropagation();
        event.preventDefault();
    }

    // CHECK PERMISSION FIRST
    if (typeof window.CAN_UPDATE_INFO !== 'undefined' && window.CAN_UPDATE_INFO === false) {
        if (typeof window.showToast === 'function') {
            window.showToast('No tienes permisos para realizar esta acción.', 'error');
        } else if (typeof window.showModal === 'function') {
            window.showModal({
                type: 'error',
                title: 'Acceso Denegado',
                message: 'No tienes permisos para realizar esta acción.',
                confirmText: 'Entendido',
                hideCancel: true
            });
        } else {
            alert('Acceso Denegado: No tienes permisos para actualizar información.');
        }
        return;
    }

    const existing = document.getElementById('dashboardGestionModal');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.id = 'dashboardGestionModal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);z-index:20000;display:flex;justify-content:center;align-items:center;opacity:0;transition:opacity 0.2s ease;';

    overlay.innerHTML = `
        <div style="background:white;width:90%;max-width:320px;border-radius:14px;overflow:hidden;box-shadow:0 20px 40px -10px rgba(0,0,0,0.3);transform:scale(0.95);transition:transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);text-align:center;">
            <div style="padding:26px 16px 20px;">
                <p style="margin:0 0 16px 0;font-size:14px;color:#1e293b;font-weight:800;line-height:1.4;">
                    ¿Confirmas que comenzarás a<br>gestionar este documento?
                </p>
                <div style="background:#f1f5f9;padding:12px;border-radius:8px;border:1px solid #e2e8f0;margin:0 auto;max-width:260px;">
                    <p style="margin:0;font-size:12px;color:#64748b;line-height:1.5;">
                        Se registrará tu frente como<br><b style="color:#1e293b;font-size:13px;">responsable de la renovación</b>.
                    </p>
                </div>
            </div>
            <div style="padding:0 16px 20px;display:flex;gap:8px;justify-content:center;">
                <button id="btnCancelGestion"
                    style="flex:1;max-width:130px;padding:9px;background:white;border:1px solid #cbd5e1;border-radius:8px;font-size:12px;font-weight:600;color:#64748b;transition:all 0.2s;"
                    onmouseover="this.style.background='#f8fafc';this.style.color='#475569'" onmouseout="this.style.background='white';this.style.color='#64748b'">
                    Cancelar
                </button>
                <button id="btnConfirmGestion"
                    style="flex:1;max-width:130px;padding:9px;background:#1e293b;border:none;border-radius:8px;font-size:12px;font-weight:700;color:white;transition:background 0.2s;"
                    onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">
                    Aceptar
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);
    
    // Animate in
    requestAnimationFrame(() => {
        overlay.style.opacity = '1';
        overlay.children[0].style.transform = 'scale(1)';
    });

    const closeHandler = () => {
        overlay.style.opacity = '0';
        overlay.children[0].style.transform = 'scale(0.95)';
        setTimeout(() => overlay.remove(), 200);
    };

    document.getElementById('btnCancelGestion').onclick = closeHandler;

    document.getElementById('btnConfirmGestion').onclick = async function () {
        // Cerrar el modal de confirmacion y dejar que el preloader global
        // de la app sea el unico indicador de carga mientras procesamos.
        closeHandler();
        if (typeof window.showPreloader === 'function') window.showPreloader();

        try {
            const csrf = window.getCsrf();
            const response = await window.apiFetch('/dashboard/iniciar-gestion', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    equipo_id: equipoId,
                    doc_type: docType
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message || 'Error al iniciar gestión');
            }

            // El POST ya terminó (rápido: 1 update + limpiar caché). Damos feedback
            // y ocultamos el spinner DE INMEDIATO. El refresco de la lista regenera
            // TODAS las alertas en el servidor (generateAlertsList recorre la flota),
            // así que lo lanzamos en SEGUNDO PLANO (sin await) para no dejar al usuario
            // mirando el spinner — antes se esperaba a ese refresco y por eso tardaba.
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            if (typeof window.showToast === 'function') {
                window.showToast('Gestión iniciada con éxito', 'success');
            }
            if (typeof window.refreshDashboardAlerts === 'function') {
                window.refreshDashboardAlerts(); // background: la lista se actualiza sola al terminar
            }
        } catch (error) {
            console.error('Error:', error);
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            if (typeof window.showToast === 'function') {
                window.showToast(error.message, 'error');
            } else if (typeof window.showModal === 'function') {
                window.showModal({ type: 'error', title: 'Error', message: error.message });
            } else {
                alert(error.message);
            }
        }
    };
};

// refreshPendingMovs removido: la lista de pendientes vive en el dropdown de notificaciones
// del navbar (ver layouts/estructura_base.blade.php). Si necesitas forzar refresh desde otra
// parte del código, dispatcha: window.dispatchEvent(new Event('notif:refresh')).
