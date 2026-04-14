// menu.js - Dashboard Interaction Logic
// Handlers for "Movilizaciones Hoy" and "Alertas Documentos" cards

// Assign directly to window to ensure global availability across SPA navigation
// No IIFE wrapper to prevent any scoping issues

window.toggleExpiredDocs = function () {
    const expiredContainer = document.getElementById('expiredDocsContainer');
    const pendingContainer = document.getElementById('pendingMovsContainer');

    if (!expiredContainer) return;

    if (expiredContainer.style.display === 'none') {
        expiredContainer.style.display = 'flex';
        // Close the other list if it exists
        if (pendingContainer) pendingContainer.style.display = 'none';
    } else {
        expiredContainer.style.display = 'none';
    }
};

window.togglePendingMovs = function () {
    const pendingContainer = document.getElementById('pendingMovsContainer');
    const expiredContainer = document.getElementById('expiredDocsContainer');

    if (!pendingContainer) return;

    if (pendingContainer.style.display === 'none') {
        pendingContainer.style.display = 'flex';
        // Close the other list if it exists
        if (expiredContainer) expiredContainer.style.display = 'none';
    } else {
        pendingContainer.style.display = 'none';
    }
};

console.log('✅ Menu Dashboard Functions Loaded (Global Scope)');

// ── Event Delegation: Botón "Gestionar" en lista de alertas ──────────────
// Usamos delegación en vez de onclick inline para evitar problemas con
// AJAX content replacement y listeners preventivos de terceros.
document.addEventListener('click', function (e) {
    // Buscar si el clic fue sobre (o dentro de) un botón [data-gestion-equipo]
    const btn = e.target.closest('[data-gestion-equipo]');
    if (!btn) return;

    e.stopPropagation(); // Evitar que el clic llegue a elementos debajo
    e.preventDefault();

    const equipoId = btn.getAttribute('data-gestion-equipo');
    const docType  = btn.getAttribute('data-gestion-type');

    if (equipoId && docType && typeof window.iniciarGestion === 'function') {
        window.iniciarGestion(equipoId, docType);
    }
}, true); // <<< captura en fase de CAPTURA para máxima prioridad

// Function to refresh alerts list via AJAX without page reload
window.refreshDashboardAlerts = async function () {
    const listContainer = document.getElementById('dashboardAlertsList');
    if (!listContainer) return;

    try {
        // Add timestamp to prevent browser caching
        const response = await fetch(`/dashboard/alerts-html?t=${Date.now()}`);
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

        // Update Total Badge (if exists)
        const totalBadge = document.querySelector('.card-yellow .card-value');
        if (totalBadge && data.totalAlerts !== undefined) {
            totalBadge.innerText = data.totalAlerts;
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
            item.style.display = "";
            hasVisibleItems = true;
        } else {
            item.style.display = "none";
        }
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

window.filterPendingMovs = function () {
    const input = document.getElementById('pendingMovSearch');
    if (!input) return;

    const normalizeStr = str => str ? str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase() : '';
    let val = input.value.trim();
    const isTagSearch = val.startsWith('#');
    const filter = normalizeStr(isTagSearch ? val.substring(1) : val);

    const container = document.getElementById('pendingMovsContainer');
    if (!container) return;

    const items = container.querySelectorAll('.activity-item');
    let hasVisibleItems = false;

    items.forEach(item => {
        const placa = normalizeStr(item.getAttribute('data-placa'));
        const chasis = normalizeStr(item.getAttribute('data-chasis'));
        const etiqueta = normalizeStr(item.getAttribute('data-etiqueta'));
        const fullText = normalizeStr(item.innerText);

        let match = false;
        if (isTagSearch) {
            match = etiqueta.indexOf(filter) > -1;
        } else {
            match = placa.indexOf(filter) > -1 || chasis.indexOf(filter) > -1 || fullText.indexOf(filter) > -1 || etiqueta.indexOf(filter) > -1;
        }

        if (match) {
            item.style.display = "flex";
            hasVisibleItems = true;
        } else {
            item.style.display = "none";
        }
    });

    let emptyState = document.getElementById('movs-search-empty-state');
    const list = container.querySelector('.activity-list');

    if (!hasVisibleItems && val.length > 0) {
        if (!emptyState) {
            emptyState = document.createElement('div');
            emptyState.id = 'movs-search-empty-state';
            emptyState.style.padding = '20px';
            emptyState.style.textAlign = 'center';
            emptyState.style.color = '#64748b';
            emptyState.innerHTML = `<p>No se encontraron equipos pendientes con ese criterio.</p>`;
            list.appendChild(emptyState);
        } else {
            emptyState.style.display = 'block';
        }
    } else if (emptyState) {
        emptyState.style.display = 'none';
    }
};

// Function to start management (replacing tomarResponsabilidad)
window.iniciarGestion = function (equipoId, docType) {
    // CHECK PERMISSION FIRST
    if (typeof window.CAN_UPDATE_INFO !== 'undefined' && window.CAN_UPDATE_INFO === false) {
        if (typeof window.showModal === 'function') {
            window.showModal({
                type: 'error',
                title: 'Acceso Denegado',
                message: 'No tienes permisos para realizar esta acción (Actualizar Información).',
                confirmText: 'Entendido',
                hideCancel: true
            });
        } else {
            alert('Acceso Denegado: No tienes permisos para actualizar información.');
        }
        return;
    }

    // Check if modal system exists
    if (typeof window.showModal === 'function') {
        window.showModal({
            type: 'info',
            title: 'Iniciar Gestión',
            message: '¿Confirma que su frente comenzará a gestionar este documento? <br><small>Se registrará su frente como responsable de la renovación.</small>',
            confirmText: 'Aceptar',
            cancelText: 'Cancelar',
            onConfirm: async () => {
                await ejecutarIniciarGestion(equipoId, docType);
            }
        });
    } else {
        if (confirm('¿Confirma que comenzará a gestionar este documento?')) {
            ejecutarIniciarGestion(equipoId, docType);
        }
    }
};

async function ejecutarIniciarGestion(equipoId, docType) {
    // Show global preloader
    const preloader = document.getElementById('preloader');
    if (preloader) preloader.style.display = 'flex';

    try {
        const response = await fetch('/dashboard/iniciar-gestion', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                equipo_id: equipoId,
                doc_type: docType
            })
        });

        const data = await response.json();
        if (data.success) {
            await refreshDashboardAlerts();
            // Hide preloader after refresh
            if (preloader) preloader.style.display = 'none';
            if (typeof window.showToast === 'function') window.showToast('Gestión iniciada con éxito', 'success');
        } else {
            if (preloader) preloader.style.display = 'none';
            throw new Error(data.message || 'Error al iniciar gestión');
        }
    } catch (error) {
        if (preloader) preloader.style.display = 'none';
        console.error('Error:', error);
        if (typeof window.showToast === 'function') {
            window.showToast(`Error: ${error.message}`, 'error');
        } else if (typeof window.showModal === 'function') {
            window.showModal({ type: 'error', title: 'Error', message: error.message });
        } else {
            alert(error.message);
        }
    }
}

// ─────────────────────────────────────────────────────────
// RECIBIR MOVILIZACIÓN — AJAX (sin recarga de página)
// ─────────────────────────────────────────────────────────

/**
 * Refresca la lista de movilizaciones pendientes vía AJAX
 * y actualiza los contadores de las cards.
 */
window.refreshPendingMovs = async function (silent = false) {
    const listContainer = document.getElementById('pendingMovsList');
    if (!listContainer) return;

    try {
        const response = await fetch(`/dashboard/pending-movs-html?t=${Date.now()}`);
        if (!response.ok) throw new Error('Network error');

        const data = await response.json();

        // Actualizar lista
        if (data.html !== undefined) {
            if (silent) {
                // Actualización silenciosa sin "titileo"
                listContainer.innerHTML = data.html;
            } else {
                listContainer.style.opacity = '0';
                setTimeout(() => {
                    listContainer.innerHTML = data.html;
                    listContainer.style.transition = 'opacity 0.3s ease';
                    listContainer.style.opacity = '1';
                }, 100);
            }
        }

        // Actualizar contador "Por Confirmar" (x|N Por Confirmar — card-subtext-inline)
        if (data.pendientes !== undefined) {
            const subtextEl = document.querySelector('.card-blue .card-subtext-inline');
            if (subtextEl) subtextEl.innerText = `| ${data.pendientes} Por Confirmar`;
        }

        // Actualizar contador "Movilizaciones Hoy" (card-value dentro de card-blue)
        if (data.movilizacionesHoy !== undefined) {
            const hoyEl = document.querySelector('.card-blue .card-value');
            if (hoyEl) hoyEl.innerText = data.movilizacionesHoy;
        }

    } catch (error) {
        console.error('refreshPendingMovs error:', error);
    }
};

/**
 * Confirmación y ejecución AJAX de recepción de una movilización.
 * @param {number} movId  - ID de la movilización
 * @param {HTMLElement} btn - referencia al botón clickeado (para feedback visual)
 */
window.recibirMovilizacion = function (movId, btn) {
    if (typeof showModal === 'function') {
        showModal({
            type: 'info',
            title: 'Confirmar Recepción',
            message: '¿Confirmas la recepción de este equipo en tu frente?',
            confirmText: 'Sí, Recibir',
            cancelText: 'Cancelar',
            onConfirm: () => _ejecutarRecepcion(movId, btn)
        });
    } else {
        if (confirm('¿Confirmas la recepción de este equipo?')) {
            _ejecutarRecepcion(movId, btn);
        }
    }
};

async function _ejecutarRecepcion(movId, btn) {
    // Feedback visual inmediato en el botón y optimistic UI
    const cardItem = btn ? btn.closest('.activity-item') : null;
    
    if (btn) {
        btn.disabled = true;
    }
    
    if (cardItem) {
        // Optimistic UI: Hide the card immediately
        cardItem.style.opacity = '0.5';
        cardItem.style.pointerEvents = 'none';
    }

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        const response = await fetch(`/admin/movilizaciones/${movId}/status`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ status: 'RECIBIDO' })
        });

        // Interceptar 403 antes de parsear JSON
        if (response.status === 403) {
            if (cardItem) {
                cardItem.style.opacity = '1';
                cardItem.style.pointerEvents = 'auto';
            }
            if (btn) btn.disabled = false;
            
            if (typeof showModal === 'function') {
                showModal({
                    type: 'warning',
                    title: 'Sin Permisos',
                    message: 'No tienes permiso para confirmar la recepción de equipos.',
                    confirmText: 'Entendido',
                    hideCancel: true
                });
            } else {
                alert('Sin Permisos: No tienes permiso para confirmar la recepción de equipos.');
            }
            return;
        }

        const data = await response.json();

        if (data.success) {
            // Refrescar solo la lista en segundo plano y de manera silenciosa
            await refreshPendingMovs();
        } else {
            throw new Error(data.error || data.message || 'Error al confirmar recepción');
        }

    } catch (error) {
        // Restaurar botón/card en caso de error
        if (cardItem) {
            cardItem.style.opacity = '1';
            cardItem.style.pointerEvents = 'auto';
        }
        if (btn) btn.disabled = false;
        
        console.error('_ejecutarRecepcion error:', error);
        if (typeof showModal === 'function') {
            showModal({ type: 'error', title: 'Error', message: error.message });
        } else {
            alert(error.message);
        }
    }
}

/**
 * Abre el modal de recepción con campo de ubicación desde el dashboard/menú.
 * Reutiliza el modal #recepcionModal si está disponible en la página,
 * de lo contrario crea un mini-modal inline.
 */
window.iniciarRecepcionDesdeDashboard = function (movId, nombreFrente, subdivisiones, idFrenteDestino) {
    // Intentar usar el modal principal de movilizaciones si está en el DOM
    const modal = document.getElementById('recepcionModal');
    if (modal && typeof window.iniciarRecepcion === 'function') {
        window.iniciarRecepcion(movId, nombreFrente, subdivisiones, idFrenteDestino);
        return;
    }

    // Si no está en la página de movilizaciones, crear mini-modal
    const existing = document.getElementById('dashboardRecepcionModal');
    if (existing) existing.remove();

    const subs = (subdivisiones && subdivisiones.trim() !== '')
        ? subdivisiones.split(',').map(s => s.trim()).filter(Boolean)
        : [];

    const overlay = document.createElement('div');
    overlay.id = 'dashboardRecepcionModal';
    overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:20000;display:flex;justify-content:center;align-items:center;';

    overlay.innerHTML = `
        <div style="background:white;width:95%;max-width:400px;border-radius:16px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3);">
            <div style="background:linear-gradient(135deg,#1e293b,#0f172a);padding:14px 18px;color:white;display:flex;justify-content:space-between;align-items:center;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <i class="material-icons" style="font-size:20px;">check_circle</i>
                    <div>
                        <h3 style="margin:0;font-size:14px;font-weight:800;">Confirmar Recepción</h3>
                        <p style="margin:0;font-size:11px;opacity:0.8;">El equipo ha llegado a ${nombreFrente}</p>
                    </div>
                </div>
                <button onclick="document.getElementById('dashboardRecepcionModal').remove()"
                    style="background:rgba(255,255,255,0.2);border:none;color:white;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:default;">
                    <i class="material-icons" style="font-size:16px;">close</i>
                </button>
            </div>
            <div style="padding:20px;">
                <label style="display:block;font-size:13px;font-weight:700;color:#475569;margin-bottom:8px;">
                    UBICACIÓN DETALLADA <span style="font-weight:400;color:#94a3b8;">(Opcional)</span>
                </label>
                <div style="position:relative;">
                    <input type="text" id="dashRdUbicacion"
                        placeholder="Ej. Patio de maniobras..."
                        autocomplete="off"
                        style="width:100%;padding:10px 14px;border:1px solid #cbd5e0;border-radius:10px;font-size:14px;background:#f8fafc;outline:none;box-sizing:border-box;"
                        onfocus="this.style.borderColor='#1e293b'" onblur="this.style.borderColor='#cbd5e0'">
                    <div id="dashRdSuggestions" style="display:none;position:absolute;top:100%;left:0;right:0;background:white;border:1px solid #cbd5e0;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);z-index:500;max-height:140px;overflow-y:auto;margin-top:4px;"></div>
                </div>
            </div>
            <div style="padding:0 20px 20px;display:flex;gap:10px;">
                <button onclick="document.getElementById('dashboardRecepcionModal').remove()"
                    style="flex:1;padding:10px;background:white;border:1px solid #e2e8f0;border-radius:10px;font-weight:600;color:#64748b;cursor:default;">
                    Cancelar
                </button>
                <button id="dashBtnConfirmarRecep"
                    style="flex:1;padding:10px;background:#1e293b;border:none;border-radius:10px;font-weight:700;color:white;cursor:default;transition:background 0.2s;"
                    onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">
                    Confirmar
                </button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    // Cargar sugerencias de subdivisiones
    if (subs.length > 0) {
        const input = document.getElementById('dashRdUbicacion');
        const sugBox = document.getElementById('dashRdSuggestions');
        subs.forEach(s => {
            const opt = document.createElement('div');
            opt.textContent = s;
            opt.style.cssText = 'padding:8px 12px;cursor:default;font-size:13px;border-bottom:1px solid #f1f5f9;';
            opt.onmouseover = () => opt.style.background = '#f1f5f9';
            opt.onmouseout = () => opt.style.background = '';
            opt.onclick = () => { input.value = s; sugBox.style.display = 'none'; };
            sugBox.appendChild(opt);
        });
        input.onfocus = () => { input.style.borderColor = '#1e293b'; if (subs.length > 0) sugBox.style.display = 'block'; };
        document.addEventListener('click', function handler(e) {
            if (!overlay.contains(e.target)) { sugBox.style.display = 'none'; document.removeEventListener('click', handler); }
        });
    }

    // Confirmación
    document.getElementById('dashBtnConfirmarRecep').onclick = async function () {
        const ubicacion = document.getElementById('dashRdUbicacion').value.trim();
        const btn = this;
        btn.disabled = true;

        // Cerrar modal de inmediato para mayor fluidez (Optimistic UI)
        overlay.remove();

        try {
            const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            const response = await fetch(`/admin/movilizaciones/${movId}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    status: 'RECIBIDO',
                    DETALLE_UBICACION: ubicacion || null
                })
            });

            // Interceptar 403 antes de parsear JSON
            if (response.status === 403) {
                if (typeof showModal === 'function') {
                    showModal({
                        type: 'warning',
                        title: 'Sin Permisos',
                        message: 'No tienes permiso para confirmar la recepción de equipos.',
                        confirmText: 'Entendido',
                        hideCancel: true
                    });
                } else {
                    alert('Sin Permisos: No tienes permiso para confirmar la recepción de equipos.');
                }
                return;
            }

            const data = await response.json();

            if (data.success) {
                if (typeof window.showToast === 'function') {
                    window.showToast('Recepción confirmada con éxito', 'success');
                }

                // Animación elegante de salida optimista antes de refrescar DB
                const rowItem = document.getElementById(`mov-item-${movId}`);
                if (rowItem) {
                    rowItem.style.transition = 'all 0.35s cubic-bezier(0.4, 0, 0.2, 1)';
                    rowItem.style.opacity = '0';
                    rowItem.style.transform = 'translateX(20px)';
                    rowItem.style.padding = '0';
                    rowItem.style.height = rowItem.offsetHeight + 'px'; // Fix height for sliding
                    
                    // Slide up after fade out
                    setTimeout(() => {
                        rowItem.style.height = '0px';
                        rowItem.style.borderBottom = 'none';
                        setTimeout(async () => {
                            rowItem.remove();
                            // Actualizar la lista en segundo plano de manera silenciosa
                            await window.refreshPendingMovs(true);
                        }, 350);
                    }, 200);
                } else {
                    await window.refreshPendingMovs(true);
                }
            } else {
                throw new Error(data.error || 'Error al confirmar recepción');
            }
        } catch (error) {
            console.error(error);
            if (typeof showModal === 'function') {
                showModal({ type: 'error', title: 'Error', message: error.message, confirmText: 'Cerrar', hideCancel: true });
            }
        }
    };
};

