// equipos_index.js - Equipos Module Logic
// Version: 2.5 - IntersectionObserver Image Loader (Concurrency-Safe)

// Use window to ensure persistent state across SPA reloads if the script is re-executed
window.selectedEquipos = window.selectedEquipos || {};
window.equiposData     = window.equiposData     || {};

// ── CONTROLLED IMAGE LOADER ───────────────────────────────────────────────
// Usa IntersectionObserver para diferir la carga hasta que la imagen es visible,
// más un semáforo que limita a MAX_CONCURRENT peticiones simultáneas.
// Esto evita el congelamiento del browser cuando el usuario vuelve a la pestaña
// y el browser intenta cargar decenas de imágenes al mismo tiempo contra el
// servidor de un solo hilo (php artisan serve).
(function () {
    const MAX_CONCURRENT = 20; // Incrementado: Google CDN maneja alta concurrencia
    let _active = 0;
    let _queue  = [];
    let _observer = null;

    function _doLoad(img) {
        _active++;
        const src = img.dataset.src;
        img.removeAttribute('data-src'); // impide doble-encola miento

        img.onload = function () {
            img.style.opacity = '1';
            _active--;
            _processQueue();
        };
        img.onerror = function () {
            const w = img.closest('.table-image-wrapper');
            if (w) {
                w.innerHTML = '<span class="material-icons" style="color:#cbd5e0;font-size:24px;">image_not_supported</span>';
                w.classList.add('placeholder');
            }
            _active--;
            _processQueue();
        };
        img.src = src;
    }

    function _processQueue() {
        while (_active < MAX_CONCURRENT && _queue.length > 0) {
            const img = _queue.shift();
            // Saltar si fue removido del DOM (filtro cambiado) o ya cargó
            if (img && document.contains(img) && img.dataset.src) {
                _doLoad(img);
            }
        }
    }

    function _getObserver() {
        if (!_observer) {
            _observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        var img = entry.target;
                        _observer.unobserve(img);
                        if (img.dataset.src) { // puede ya haber sido cargada
                            _queue.push(img);
                            _processQueue();
                        }
                    }
                });
            }, { rootMargin: '300px' });
        }
        return _observer;
    }

    // Registra las imágenes de un contenedor para observación.
    // Llamar después de insertar filas en el DOM.
    window._registerLazyImages = function (container) {
        var obs = _getObserver();
        container.querySelectorAll('img[data-src]').forEach(function (img) {
            obs.observe(img);
        });
    };

    // Limpia la cola al cambiar de filtro.
    // Las imágenes activas terminan normalmente (no cancelar mid-flight).
    window._resetImageLoader = function () {
        _queue = [];
        // Desconectar el observer y recrearlo en el siguiente registerLazyImages.
        // Esto evita que el observer antiguo observe nodos de la búsqueda anterior.
        if (_observer) {
            _observer.disconnect();
            _observer = null;
        }
    };
})();

// STATUS_CONFIG compartido (evita redeclaración duplicada)
const STATUS_CONFIG = {
    'OPERATIVO':        { color: '#16a34a', bg: '#f0fdf4', icon: 'check_circle',  label: 'Operativo' },
    'INOPERATIVO':      { color: '#dc2626', bg: '#fef2f2', icon: 'cancel',        label: 'Inoperativo' },
    'EN MANTENIMIENTO': { color: '#d97706', bg: '#fffbeb', icon: 'engineering',   label: 'Mantenimiento' },
    'DESINCORPORADO':   { color: '#475569', bg: '#f1f5f9', icon: 'archive',       label: 'Desincorp.' },
};

// ── SHARED STATUS MENU ──────────────────────────────────────────────────────
// Un único menú flotante reutilizado para todos los equipos.
// Elimina los 1,620+ nodos y 3,240+ event listeners que congelaban el navegador.
(function () {
    let _activeTrigger = null;

    function getOrCreateMenu() {
        let menu = document.getElementById('sharedStatusMenu');
        if (menu) return menu;

        menu = document.createElement('div');
        menu.id = 'sharedStatusMenu';
        // z-index 100001: por encima del modal de detalles (z-index 99999)
        // para que el menu sea visible cuando el modal esta abierto.
        menu.style.cssText = 'display:none; position:fixed; min-width:180px; background:white; border-radius:8px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.15); border:1px solid #e2e8f0; z-index:100001; overflow:hidden;';

        Object.entries(STATUS_CONFIG).forEach(([key, cfg]) => {
            const item = document.createElement('div');
            item.dataset.statusKey = key;
            item.style.cssText = 'display:flex; align-items:center; gap:8px; padding:10px 12px; cursor:pointer; border-bottom:1px solid #f8fafc;';
            item.innerHTML = `
                <div style="background:${cfg.bg}; padding:4px; border-radius:4px; display:flex;">
                    <i class="material-icons" style="font-size:16px; color:${cfg.color};">${cfg.icon}</i>
                </div>
                <span style="font-size:12px; font-weight:600; color:#334155;">${cfg.label}</span>
                <i class="material-icons check-icon" style="font-size:14px; color:${cfg.color}; margin-left:auto; display:none;">check</i>
            `;
            item.addEventListener('mouseover', () => item.style.background = '#f8fafc');
            item.addEventListener('mouseout',  () => item.style.background = 'white');
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                if (!_activeTrigger) return;
                // Capturar trigger ANTES de closeSharedMenu(), porque este resetea
                // _activeTrigger a null y changeStatusLite recibiria un null.
                const trigger = _activeTrigger;
                const id  = trigger.dataset.equipoId;
                const url = trigger.dataset.statusUrl;
                closeSharedMenu();
                window.changeStatusLite(id, key, url, trigger);
            });
            menu.appendChild(item);
        });

        document.body.appendChild(menu);
        return menu;
    }

    function closeSharedMenu() {
        const menu = document.getElementById('sharedStatusMenu');
        if (menu) menu.style.display = 'none';
        _activeTrigger = null;
    }

    window.openSharedStatusMenu = function (trigger) {
        if (window.CAN_CHANGE_STATUS === false || window.CAN_CHANGE_STATUS === 'false') {
            if (window.showModal) window.showModal({ type: 'error', title: 'Acceso Denegado', message: 'No tienes permisos para cambiar el estatus.', confirmText: 'Entendido', hideCancel: true });
            return;
        }

        const menu = getOrCreateMenu();
        const currentStatus = trigger.dataset.status;

        // Toggle: cerrar si ya está abierto para este trigger
        if (_activeTrigger === trigger && menu.style.display !== 'none') {
            closeSharedMenu(); return;
        }

        // Marcar el item activo con un check
        menu.querySelectorAll('[data-status-key]').forEach(item => {
            item.querySelector('.check-icon').style.display = (item.dataset.statusKey === currentStatus) ? 'inline' : 'none';
        });

        // Posicionar el menú justo debajo del trigger.
        // Con position:fixed, las coordenadas son relativas al viewport, por lo
        // que NO sumamos window.scrollY (antes lo hacia y el menu aparecia fuera
        // de pantalla cuando habia scroll). Tambien clamp left para que no se
        // salga del viewport a la derecha (critico en mobile).
        const rect = trigger.getBoundingClientRect();
        menu.style.display = 'block';
        const menuH = menu.offsetHeight;
        const menuW = menu.offsetWidth;
        const spaceBelow = window.innerHeight - rect.bottom;
        const top = spaceBelow >= menuH ? rect.bottom + 4 : rect.top - menuH - 4;

        // Clamp horizontal: no salir por la derecha ni por la izquierda del viewport.
        let left = rect.left;
        const maxLeft = window.innerWidth - menuW - 8; // 8px de margen del borde
        if (left > maxLeft) left = maxLeft;
        if (left < 8) left = 8;

        menu.style.top  = top + 'px';
        menu.style.left = left + 'px';

        _activeTrigger = trigger;
    };

    // Estos listeners son globales — registrar UNA sola vez aunque el script
    // se re-ejecute en cada navegación SPA
    if (!window._sharedMenuListenersReady) {
        window._sharedMenuListenersReady = true;
        // Cerrar al hacer click fuera
        document.addEventListener('click', (e) => {
            const menu = document.getElementById('sharedStatusMenu');
            if (menu && !menu.contains(e.target)) closeSharedMenu();
        });
        // Cerrar al hacer scroll
        document.addEventListener('scroll', closeSharedMenu, true);
    }
})();

// Aplica el estado al trigger (icono/label/dataset). Reutilizado por el cambio
// normal y por el "reporte creado" (que deja el equipo INOPERATIVO).
function _applyStatusVisual(triggerEl, status) {
    const cfg = STATUS_CONFIG[status] ?? STATUS_CONFIG['DESINCORPORADO'];
    const iconEl = triggerEl.querySelector('.material-icons');
    const spanEl = triggerEl.querySelector('span');
    if (iconEl) { iconEl.textContent = cfg.icon; iconEl.style.color = cfg.color; }
    if (spanEl) spanEl.textContent = cfg.label;
    triggerEl.dataset.status = status;
}

// Manejador de cambio de estatus para el menú compartido
window.changeStatusLite = function (id, newStatus, url, triggerEl) {
    // Usa STATUS_CONFIG del ámbito de módulo (definido al inicio del archivo)
    const oldStatus = triggerEl.dataset.status;
    if (oldStatus === newStatus) return;

    // ── OFFLINE (Fase 2): delega en el módulo offline (encola + copia local +
    // repinta). Lógica única en equipos-offline.js (window.eqOffSetEstado). ──
    if (window.OfflineMode && window.OfflineMode.estaActivo()) {
        // INOPERATIVO exige crear un reporte de falla (modal no disponible offline).
        if (newStatus === 'INOPERATIVO') {
            if (window.showToast) window.showToast('INOPERATIVO requiere crear un reporte de falla; hazlo con internet.', 'error');
            return;
        }
        if (typeof window.eqOffSetEstado === 'function') {
            window.eqOffSetEstado(Number(id), newStatus, triggerEl.dataset.label);
        }
        return;
    }

    // INOPERATIVO se gestiona CREANDO un reporte de falla (el backend deja el
    // equipo inoperativo). Abrimos el modal "Nuevo Reporte" pre-seleccionado; si
    // se cancela, no cambia nada; al crear, handleFallaCreatedEquipo marca el estado.
    if (newStatus === 'INOPERATIVO' && typeof window.flOpenForActivo === 'function') {
        window._fallaStatusCtx = { triggerEl: triggerEl, oldStatus: oldStatus };
        window.flOpenForActivo('equipo', id, triggerEl.dataset.label || ('Equipo #' + id),
            function () { window._fallaStatusCtx = null; });
        return;
    }

    const revert = function () { _applyStatusVisual(triggerEl, oldStatus); };

    // Actualizar visualmente el trigger de inmediato (optimistic UI)
    _applyStatusVisual(triggerEl, newStatus);

    fetch(url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': window.getCsrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ status: newStatus })
    })
    .then(r => r.json().then(body => ({ status: r.status, body })))
    .then(({ status, body }) => {
        if (status === 200 && body && body.success) {
            if (window.updateLocalStats) window.updateLocalStats(oldStatus, newStatus);
            if (window.showToast) window.showToast('Estatus actualizado correctamente.', 'success');
            return;
        }
        // El equipo tiene un reporte ABIERTO: revertir y abrir el modal de cierre.
        if (body && body.falla_abierta) {
            revert();
            if (typeof window.flAbrirCierre === 'function') window.flAbrirCierre(body.falla_abierta);
            return;
        }
        throw new Error((body && body.message) ?? 'Error desconocido');
    })
    .catch(err => {
        revert();
        if (window.showToast) window.showToast('Error al cambiar el estatus: ' + err.message, 'error');
    });
};

// ── Confirmación de presencia en sitio (CONFIRMADO_EN_SITIO) ──────────────────
// Toggle desde el chip de la lista (celda del frente) o el botón del modal de
// detalles. Update OPTIMISTA de todos los elementos del mismo equipo (chips +
// botón del modal) + PATCH. Mismo patrón que changeStatusLite.
function _pintarConfirmSitio(id, confirmado) {
    document.querySelectorAll('.confirm-sitio-chip[data-equipo-id="' + id + '"]').forEach(function (el) {
        el.dataset.confirmado = confirmado ? '1' : '0';
        el.style.color = confirmado ? '#16a34a' : '#cbd5e0';
        el.textContent = confirmado ? 'check_circle' : 'radio_button_unchecked';
        el.title = confirmado ? 'Confirmado en sitio (click para quitar)' : 'Sin confirmar (click para confirmar)';
    });
    var mb = document.getElementById('btn_confirmar_sitio_modal');
    if (mb && String(mb.dataset.equipoId) === String(id)) {
        mb.dataset.confirmado = confirmado ? '1' : '0';
        var mic = mb.querySelector('.material-icons');
        if (mic) mic.textContent = confirmado ? 'check_circle' : 'radio_button_unchecked';
        mb.style.color = confirmado ? '#4ade80' : 'white';
        mb.title = confirmado ? 'Confirmado en sitio (click para quitar)' : 'Confirmar presencia en sitio';
    }
}

window.toggleConfirmacionSitio = function (el) {
    var id = (el && el.dataset) ? el.dataset.equipoId : null;
    if (!id) return;
    var actual = el.dataset.confirmado === '1';
    var nuevo = !actual;
    _pintarConfirmSitio(id, nuevo); // optimista

    fetch('/admin/equipos/' + id + '/confirmar-sitio', {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': window.getCsrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ confirmado: nuevo ? 1 : 0 }),
    })
    .then(function (r) { return r.json().then(function (b) { return { status: r.status, body: b }; }); })
    .then(function (res) {
        if (res.status === 200 && res.body && res.body.success) {
            _pintarConfirmSitio(id, res.body.confirmado === 1);
            if (window.showToast) window.showToast(res.body.confirmado === 1 ? 'Confirmado en sitio.' : 'Marcado como sin confirmar.', 'success');
            return;
        }
        throw new Error((res.body && res.body.message) || 'Error desconocido');
    })
    .catch(function (err) {
        _pintarConfirmSitio(id, actual); // revertir
        if (window.showToast) window.showToast('No se pudo actualizar la confirmación: ' + err.message, 'error');
    });
};

// Tras crear un reporte desde el desplegable de estado: marca el equipo INOPERATIVO.
window.handleFallaCreatedEquipo = function () {
    const ctx = window._fallaStatusCtx;
    window._fallaStatusCtx = null;
    if (!ctx || !ctx.triggerEl) return;
    _applyStatusVisual(ctx.triggerEl, 'INOPERATIVO');
    if (window.updateLocalStats) window.updateLocalStats(ctx.oldStatus, 'INOPERATIVO');
};

// Selection UI Update Tracker
function updateSelectionUI() {
    const ids = Object.keys(window.selectedEquipos);
    const count = ids.length;
    const bar = document.getElementById("bulkFloatingBar");
    const text = document.getElementById("bulkCountText");

    if (bar && text) {
        if (count > 0) {
            text.innerText = count;
            bar.classList.add("active");

            const selections = Object.values(window.selectedEquipos);

            const isValidId = (val) => val && val !== "null" && val !== "";

            // Determinar si alguno de los seleccionados YA está anclado
            const someAnchored = selections.some(s => isValidId(s.anchorId));

            // Determinar si alguno puede anclar (rol válido Y sin ancla activa)
            const canAnchor = !someAnchored && selections.some(s =>
                (s.rolAnclaje === 'REMOLCADOR' || s.rolAnclaje === 'REMOLCABLE') &&
                !isValidId(s.anchorId)
            );

            // ── Anclar: solo si nadie está anclado y alguno puede anclarse ──
            const anchorBtn = document.getElementById('btnAnclar');
            if (anchorBtn) {
                anchorBtn.style.display = canAnchor ? 'flex' : 'none';
            }

            // ── Desanclar: solo si alguno YA está anclado ──
            const unanchorBtn = document.getElementById('btnUnanchor');
            if (unanchorBtn) {
                unanchorBtn.style.display = someAnchored ? 'flex' : 'none';
            }

        } else {
            // Resetear botones de anclaje a estado neutro antes de ocultar la barra.
            // Sin esto, al limpiar y volver a seleccionar pueden aparecer con el
            // display del ciclo anterior (incorrecto).
            const anchorBtn   = document.getElementById('btnAnclar');
            const unanchorBtn = document.getElementById('btnUnanchor');
            if (anchorBtn)   anchorBtn.style.display   = 'none';
            if (unanchorBtn) unanchorBtn.style.display = 'none';
            bar.classList.remove("active");

            // Si estaba "ver solo seleccionados" y ya no queda nada seleccionado,
            // apagamos el modo y recargamos para volver a mostrar todos los equipos.
            if (window._equiposSoloSel) {
                window._equiposSoloSel = false;
                const counter = bar.querySelector('.selection-counter');
                if (counter) counter.classList.remove('is-filtering');
                if (typeof window.loadEquipos === 'function') window.loadEquipos();
            }
        }
    }
}

// "Ver solo seleccionados": al tocar el contador de la barra, recarga la tabla
// mostrando ÚNICAMENTE los equipos seleccionados (whitelist server-side vía
// ids_in, ignorando los demás filtros). Volver a tocar lo apaga. Mismo patrón
// que el contador del módulo Almacén (almToggleSoloSel).
window.toggleEquiposSoloSel = function (e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const ids = Object.keys(window.selectedEquipos || {});
    if (!ids.length) {
        if (typeof window.showToast === 'function') {
            window.showToast('No hay equipos seleccionados todavía.', 'error');
        }
        return;
    }
    window._equiposSoloSel = !window._equiposSoloSel;
    const counter = document.querySelector('#bulkFloatingBar .selection-counter');
    if (counter) counter.classList.toggle('is-filtering', window._equiposSoloSel);
    window.loadEquipos(null, false, { offset: 0 });
    if (window._equiposSoloSel) {
        const tbody = document.getElementById('equiposTableBody');
        if (tbody) tbody.scrollIntoView({ block: 'start' });
    }
};

// Re-apply blue highlight to all rows that are in selectedEquipos
// Called after every table render to keep visual state in sync
function reApplySelections() {
    if (
        !window.selectedEquipos ||
        Object.keys(window.selectedEquipos).length === 0
    )
        return;

    const tableBody = document.getElementById("equiposTableBody");
    if (!tableBody) return;

    tableBody.querySelectorAll("tr").forEach((row) => {
        if (row.dataset.auxId) return; // filas aux: su highlight lo maneja auxRestoreSelection
        const btn = row.querySelector(".btn-details-mini");
        if (!btn) return;
        const id = String(btn.dataset.equipoId);
        if (window.selectedEquipos.hasOwnProperty(id)) {
            row.classList.add("selected-row-maquinaria");
        }
    });
}

// Global Selection Action
window.clearSelection = function (event) {
    // Prevent event bubbling to avoid conflicts
    if (event) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
    }

    // Defensive check to prevent re-execution
    if (
        !window.selectedEquipos ||
        Object.keys(window.selectedEquipos).length === 0
    ) {
        return;
    }

    window.selectedEquipos = {};
    document.querySelectorAll(".selected-row-maquinaria").forEach((row) => {
        row.classList.remove("selected-row-maquinaria");
    });
    updateSelectionUI();
};

// Row Click Logic (Delegated)
function handleRowClick(e) {
    // Look for target row in the equipos table
    const row = e.target.closest("#equiposTableBody tr");
    if (!row) return;

    // Modo aux: las filas de auxiliares viven en #equiposTableBody pero NO son equipos
    // (tienen data-aux-id, no data-equipo-id). Su seleccion la maneja la maquinaria aux
    // (auxToggleRow); aqui se ignoran para no meter basura en selectedEquipos ni
    // doble-seleccionar la fila.
    if (row.dataset.auxId) return;

    // Ignore if clicking interactive elements
    if (
        e.target.closest("button") ||
        e.target.closest(".custom-dropdown") ||
        e.target.closest(".material-icons") ||
        e.target.closest("a") ||
        e.target.closest("input")
    )
        return;

    const btnDetails = row.querySelector(".btn-details-mini");
    if (!btnDetails) return;

    const id = btnDetails.dataset.equipoId;
    const code = btnDetails.dataset.codigo;
    const placa = btnDetails.dataset.placa;   // PLACA del documento
    const chasis = btnDetails.dataset.chasis; // SERIAL_CHASIS
    const tipo = btnDetails.dataset.tipo || 'SIN TIPO'; // Nombre del tipo de equipo
    const frenteId = btnDetails.dataset.frenteId;
    const rolAnclaje = btnDetails.dataset.rolAnclaje;
    let anchorId = btnDetails.dataset.anchorId;
    if (anchorId === "null" || anchorId === "") {
        anchorId = null;
    }

    const isSelecting = !(id in window.selectedEquipos);

    const toggleSelection = (
        targetId,
        targetCode,
        targetPlaca,
        targetChasis,
        targetTipo,
        targetFrente,
        targetRol,
        targetAnchorId,
        targetRow,
    ) => {
        if (isSelecting) {
            window.selectedEquipos[targetId] = {
                id: targetId,
                code: targetCode,
                placa: targetPlaca,
                chasis: targetChasis,
                tipo: targetTipo,       // Nombre del tipo: EXCAVADORA, CAMIÓN VOLTEO, etc.
                frenteId: targetFrente,
                rolAnclaje: targetRol,
                anchorId: targetAnchorId,
            };
            window.lastSelectedEquipoId = targetId; // Guardar el más reciente para anclaje
            if (targetRow) targetRow.classList.add("selected-row-maquinaria");
        } else {
            delete window.selectedEquipos[targetId];
            if (targetRow)
                targetRow.classList.remove("selected-row-maquinaria");
        }
    };

    // Toggle main equipment
    toggleSelection(id, code, placa, chasis, tipo, frenteId, rolAnclaje, anchorId, row);

    // Toggle anchored partner if exists
    if (anchorId && anchorId !== "" && anchorId !== "null") {
        const partnerCode = btnDetails.dataset.anchorCode;
        const partnerPlaca = btnDetails.dataset.anchorPlaca;
        const partnerSerial = btnDetails.dataset.anchorSerial;
        const partnerRol = btnDetails.dataset.anchorRol;

        // Try to find partner row in DOM for visual feedback
        const partnerBtn = document.querySelector(
            `.btn-details-mini[data-equipo-id="${anchorId}"]`,
        );
        const partnerRow = partnerBtn ? partnerBtn.closest("tr") : null;

        // Tipo del partner: leído desde el DOM si está visible, o desde
        // data-anchor-tipo-nombre del botón principal como fallback.
        const partnerTipoName = partnerBtn
            ? (partnerBtn.dataset.tipo || 'SIN TIPO')
            : (btnDetails.dataset.anchorTipoNombre || 'SIN TIPO');

        toggleSelection(
            anchorId,
            partnerCode || (partnerBtn ? partnerBtn.dataset.codigo : ""),
            partnerPlaca || (partnerBtn ? partnerBtn.dataset.placa : ""),
            partnerSerial || (partnerBtn ? partnerBtn.dataset.chasis : ""),
            partnerTipoName,
            frenteId,
            partnerRol || (partnerBtn ? partnerBtn.dataset.rolAnclaje : ""),
            partnerBtn ? partnerBtn.dataset.anchorId : id,
            partnerRow,
        );

        // Selection Feedback (Toast)
        if (window.showToast) {
            // Priority: Partner in DOM > Clicked row dataset
            const partnerTipo = partnerBtn
                ? (partnerBtn.dataset.tipo || 'Equipo')
                : (btnDetails.dataset.anchorTipoNombre || 'Equipo');
            const toastSerial = partnerBtn
                ? partnerBtn.dataset.chasis
                : btnDetails.dataset.anchorSerial;
            const toastPlaca = partnerBtn
                ? partnerBtn.dataset.placa
                : btnDetails.dataset.anchorPlaca;

            // Identificador: TIPO · SERIAL > PLACA > SERIAL solo > CÓDIGO > ID
            const identLabel = toastSerial
                ? `${partnerTipo} · ${toastSerial}`
                : (toastPlaca && toastPlaca !== 'N/A' && toastPlaca !== ''
                    ? toastPlaca
                    : (partnerCode || anchorId));

            if (isSelecting) {
                window.showToast(`El equipo seleccionado está anclado a: ${identLabel}`, 'info');
            }
        }
    }

    updateSelectionUI();
}

// Global Event Listeners — registrar UNA sola vez aunque el script se re-ejecute
// en cada navegación SPA. Sin este guard, cada visita a /equipos acumula un
// listener adicional, causando procesamiento exponencial en clicks.
if (!window._equiposClickListenersReady) {
    window._equiposClickListenersReady = true;

    document.addEventListener("click", handleRowClick);

    document.addEventListener("click", function (e) {
        // Clear Advanced Filters Button
        const clearBtn = e.target.closest('[data-action="clear-advanced-filters"]');
        if (clearBtn) {
            e.preventDefault();
            e.stopPropagation();
            window.clearAdvancedFilters();
            return;
        }

        // Clear Specific Filter (Generic)
        const clearSpecific = e.target.closest("[data-clear-target]");
        if (clearSpecific) {
            e.preventDefault();
            e.stopPropagation();
            const target = clearSpecific.dataset.clearTarget;
            window.selectAdvancedFilter(target, "");
        }
    });
}

// ─── DESANCLAR EQUIPOS (NUEVA LÓGICA DESDE CERO) ──────────────────────────────
window.unanchorEquipos = async function (e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }

    const selections = Object.values(window.selectedEquipos || {});
    
    // 1. Validar que hay un elemento seleccionado
    if (selections.length === 0) {
        if (window.showModal) window.showModal({ type: 'warning', title: 'Sin Selección', message: 'Selecciona al menos un equipo para desanclar.', confirmText: 'Ok', hideCancel: true });
        return;
    }

    // 2. Extraer equipos que tengan anclaje (ignorar los que no)
    const equiposConAnclaje = selections.filter(item => item.anchorId && item.anchorId !== 'null' && String(item.anchorId).trim() !== '');

    if (equiposConAnclaje.length === 0) {
        if (window.showModal) window.showModal({ type: 'warning', title: 'Atención', message: 'Los equipos seleccionados no están anclados a nada.', confirmText: 'Ok', hideCancel: true });
        return;
    }

    // 3. Recopilar IDs únicos (el propio equipo anclado y el destino del anclaje)
    const idsToClear = new Set();
    equiposConAnclaje.forEach(eq => {
        if(eq.id) idsToClear.add(String(eq.id));
        if(eq.anchorId) idsToClear.add(String(eq.anchorId));
    });

    const idsArray = Array.from(idsToClear);

    // 4. Función de ejecución
    const executeUnanchor = async () => {
        if (window.showPreloader) window.showPreloader();
        try {
            const token = window.getCsrf();
            const baseUrl = document.querySelector('meta[name="base-url"]')?.content || '';
            const url = `${baseUrl}/admin/equipos/clear-anchor`;

            const resp = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ ids: idsArray })
            });

            let data = {};
            try { data = await resp.json(); } catch(jsonError) {}

            if (resp.status === 419 || resp.status === 401) {
                window.location.reload();
                return;
            }

            if (resp.ok && data.success) {
                // EXITO: Limpieza absoluta
                window.selectedEquipos = {};
                
                // Actualizar interfaz
                document.querySelectorAll('.selected-row-maquinaria').forEach(el => el.classList.remove('selected-row-maquinaria'));
                if (typeof updateSelectionUI === 'function') updateSelectionUI();
                
                // Refrescar tabla silenciosamente
                if (typeof window.loadEquipos === 'function') {
                    await window.loadEquipos(null, true); 
                }

                if (window.showToast) window.showToast('Desanclaje completado con éxito', 'success');
            } else {
                throw new Error(data.message || data.error || 'Ocurrió un error en el servidor al intentar desanclar.');
            }
        } catch (error) {
            console.error('[Desanclaje Error]:', error);
            if (window.showModal) window.showModal({ type: 'error', title: 'Fallo al desanclar', message: error.message, confirmText: 'Ok', hideCancel: true });
        } finally {
            if (window.hidePreloader) window.hidePreloader();
        }
    };

    // 5. Confirmación con el UI que esté disponible
    if (window.showModal) {
        window.showModal({
            type: 'warning',
            title: 'Confirmar Acción',
            message: '¿Estás seguro que deseas desanclar<br>los equipos seleccionados?',
            confirmText: 'Desanclar',
            cancelText: 'Cancelar',
            onConfirm: executeUnanchor
        });
    } else {
        if (confirm('¿Estás seguro que deseas desanclar\nlos equipos seleccionados?')) {
            executeUnanchor();
        }
    }
};



window.enlargeImage = function (src) {
    const overlay = document.getElementById("imageOverlay");
    const img = document.getElementById("enlargedImg");
    if (!overlay || !img) return;
    img.src = src;
    overlay.style.display = "flex";
};

window.toggleDocFilter = function (type) {
    // Al cambiar qué documentos están tildados volvemos al lado "Con" (default
    // histórico: tildar un doc muestra los que lo tienen). El usuario elige
    // "Sin" o "Todos" clicando los bloques del Consolidado después.
    window.__equiposDocPresence = 'con';
    window.loadEquipos();
};

// Resalta el bloque activo del Consolidado (Con / Sin / Total) según
// __equiposDocPresence cuando estamos en modo documento. Fuera de ese modo no
// resalta nada (los bloques filtran por estado y conservan su estilo normal).
window.__updateDocPresenceUI = function () {
    const docMode  = !!window.__equiposDocMode;
    const presence = window.__equiposDocPresence || 'con';
    // Cada lado cubre su bloque de escritorio (#id) y el de móvil (.eq-block-*),
    // para que el resaltado del lado activo sea coherente en ambas vistas.
    [
        { sel: '#block_total, .eq-block-total', key: 'all' },
        { sel: '#block_oper,  .eq-block-oper',  key: 'con' },
        { sel: '#block_inop,  .eq-block-inop',  key: 'sin' },
    ].forEach(({ sel, key }) => {
        const active = docMode && presence === key;
        document.querySelectorAll(sel).forEach((el) => {
            el.style.outline = active ? '2px solid #ffffff' : 'none';
            el.style.outlineOffset = active ? '-2px' : '0';
        });
    });
};

window.filterByStatus = function (status) {
    // En modo "Con / Sin documento" los bloques NO filtran por estado: filtran la
    // LISTA por presencia del documento. Verde = Con · Rojo = Sin · TOTAL = ambos.
    if (window.__equiposDocMode) {
        let presence = null;
        if (status === "OPERATIVO")        presence = "con";
        else if (status === "INOPERATIVO") presence = "sin";
        else if (status === "")            presence = "all";
        if (presence) {
            window.__equiposDocPresence = presence;
            window.__updateDocPresenceUI();
            window.loadEquipos();
            return;
        }
    }

    const dropdown = document.getElementById("estadoAdvFilter");
    if (!dropdown) return;

    if (status === "") {
        window.clearDropdownFilter("estadoAdvFilter");
    } else {
        // Obtenemos el input oculto para verificar si ya está seleccionado (toggle)
        const hiddenInput = dropdown.querySelector('input[name="estado"]');
        if (hiddenInput && hiddenInput.value === status) {
            window.clearDropdownFilter("estadoAdvFilter");
        } else {
            window.selectOption("estadoAdvFilter", status, status);
        }
    }

    // El sistema selectOption despacha un evento, pero llamamos manualmente para asegurar inmediatez
    window.loadEquipos();
};

window.loadEquipos = function (url = null, silent = false, opts = {}) {
    // Defensa: si el primer argumento es boolean (caller antiguo que usaba loadEquipos(true)),
    // interpretarlo como el flag silent para no romper con "baseUrl.includes is not a function".
    if (typeof url === 'boolean') { silent = url; url = null; }
    if (url !== null && typeof url !== 'string') { url = null; }
    const offset = Math.max(0, parseInt(opts.offset, 10) || 0);
    const append = !!opts.append;
    const tableBody = document.getElementById("equiposTableBody");
    if (!tableBody) return Promise.resolve();

    // Cancelar cualquier petición anterior en vuelo antes de iniciar una nueva.
    if (window._loadEquiposAbortController) {
        window._loadEquiposAbortController.abort();
    }
    const abortController = new AbortController();
    window._loadEquiposAbortController = abortController;

    // Limpiar la cola de imágenes: los nodos viejos serán reemplazados por los nuevos.
    if (window._resetImageLoader) window._resetImageLoader();

    let baseUrl = url || window.location.pathname;
    const advancedPanel = document.getElementById("advancedFilterPanel");

    // Helper robusto para obtener valores de inputs
    const getVal = (selector, parent = document) => {
        const el = parent.querySelector(selector);
        if (!el) return null;
        return el.value && el.value.trim() !== "" ? el.value.trim() : null;
    };

    // Unified Filter Object
    const filters = {
        search_query: getVal("#searchInput"),
        id_frente: getVal('input[name="id_frente"]'),
        id_tipo: getVal('input[name="id_tipo"]'),
        modelo: getVal('input[name="modelo"]', advancedPanel || document),
        marca: getVal('input[name="marca"]', advancedPanel || document),
        detalle_ubicacion: getVal('input[name="detalle_ubicacion"]', advancedPanel || document),
        anio: getVal('input[name="anio"]', advancedPanel || document),
        categoria: getVal('input[name="categoria"]', advancedPanel || document),
        estado: getVal('input[name="estado"]', advancedPanel || document),
        gps: getVal('input[name="gps"]', advancedPanel || document),
        color: getVal('input[name="color"]', advancedPanel || document),
        confirmado: getVal('input[name="confirmado"]', advancedPanel || document),
        filter_propiedad: document.getElementById("chk_propiedad")?.checked
            ? "true"
            : null,
        filter_poliza: document.getElementById("chk_poliza")?.checked
            ? "true"
            : null,
        filter_rotc: document.getElementById("chk_rotc")?.checked
            ? "true"
            : null,
        filter_racda: document.getElementById("chk_racda")?.checked
            ? "true"
            : null,
        filter_adicional: document.getElementById("chk_adicional")?.checked
            ? "true"
            : null,
        filter_adicional_2: document.getElementById("chk_adicional_2")?.checked
            ? "true"
            : null,
        // Dirección del filtro de documento (bloques clicables del Consolidado).
        // Solo se envía si NO es el default 'con', para no ensuciar la URL.
        doc_presence: (window.__equiposDocPresence && window.__equiposDocPresence !== "con")
            ? window.__equiposDocPresence
            : null,
    };

    const params = new URLSearchParams();

    // Lógica dinámica para poner ROJO el botón de Filtros Avanzados si hay alguno activo
    const btnAdv = document.getElementById('btnAdvancedFilter');
    if (btnAdv) {
        const hasAdv = !!(filters.modelo || filters.marca || filters.anio || filters.categoria || filters.estado || filters.gps || filters.color || filters.confirmado || filters.filter_propiedad || filters.filter_poliza || filters.filter_rotc || filters.filter_racda || filters.filter_adicional || filters.filter_adicional_2);
        if (hasAdv) {
            btnAdv.style.background = '#fee2e2';
            btnAdv.style.borderColor = '#ef4444';
            btnAdv.style.color = '#ef4444';
        } else {
            // Estado original
            btnAdv.style.background = 'white';
            btnAdv.style.borderColor = '#cbd5e0';
            btnAdv.style.color = '#64748b';
        }
    }

    // Cleanly append only valid filter values (non-null, non-empty)
    Object.entries(filters).forEach(([key, value]) => {
        if (value && typeof value === "string" && value.trim() !== "") {
            params.append(key, value.trim());
        } else if (value && typeof value !== "string") {
            params.append(key, value);
        }
    });

    // Paginación server-side: enviar offset para lotes subsiguientes (infinite scroll)
    if (offset > 0) params.append('offset', String(offset));

    // NOTE: reApplySelections() is NOT called here because the table
    // shows a "no filters" message with no real rows to highlight.

    const paramStr = params.toString();
    const finalUrl = paramStr
        ? baseUrl + (baseUrl.includes('?') ? '&' : '?') + paramStr
        : baseUrl;

    // "Ver solo seleccionados": ids_in es estado EFÍMERO del contador. Va en la
    // PETICIÓN (para que el backend filtre por whitelist) pero NO en finalUrl, que
    // es lo que se empuja a la URL con pushState — así un refresh no deja pegado un
    // filtro de IDs que ya no están seleccionados (la selección es solo de cliente).
    let fetchUrl = finalUrl;
    if (window._equiposSoloSel) {
        const selIds = Object.keys(window.selectedEquipos || {});
        if (selIds.length) {
            fetchUrl += (fetchUrl.includes('?') ? '&' : '?') + 'ids_in=' + encodeURIComponent(selIds.join(','));
        } else {
            window._equiposSoloSel = false; // sin selección → modo apagado
        }
    }
    tableBody.style.opacity = "0.5";

    if (!silent && window.showPreloader) window.showPreloader();

    return fetch(fetchUrl, {
        signal: abortController.signal,
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
            "Cache-Control": "no-cache, no-store, must-revalidate",
            "Pragma": "no-cache",
        },
    })
        .then((response) => {
            // Si fue abortada por una nueva petición, ignorar silenciosamente
            if (abortController.signal.aborted) return Promise.reject(new DOMException('Aborted', 'AbortError'));
            if (response.status === 419 || response.status === 401 || (response.redirected && response.url.includes('/login'))) {
                window.location.href = '/login';
                return Promise.reject(new Error('Sesión expirada.'));
            }
            const contentType = response.headers.get("content-type");
            if (!contentType || !contentType.includes("application/json")) {
                window.location.href = '/login';
                return Promise.reject(new Error("Sesión expirada o respuesta inválida del servidor."));
            }
            if (!response.ok) throw new Error("Network response was not ok");
            return response.json();
        })
        .then((data) => {
            if (!data) return;

            // Guards defensivos: si red mala devuelve respuesta parcial,
            // evitamos TypeErrors que rompan la cadena y dejen filtros mudos.
            data.stats = data.stats || {};
            if (typeof data.html !== "string") data.html = "";
            if (typeof data.distribution !== "string") data.distribution = "";

            // Cargar datos en memoria ANTES de tocar el DOM
            if (data.equiposData) {
                window.equiposData = { ...window.equiposData, ...data.equiposData };
            }

            // Modo aux (se eligio un tipo AUXILIAR en el dropdown): la tabla viene del
            // modulo de auxiliares. Fusionamos su mapa de detalles para que el modal del
            // ojo (openAuxDetailsModal) abra instant sin fetch, igual que en /admin/equipos-auxiliares.
            if (data.mode === 'aux' && data.auxData) {
                window.auxDetailsMap = Object.assign(window.auxDetailsMap || {}, data.auxData);
            }

            // Coherencia de controles: en modo aux se ocultan los controles propios de
            // equipos que no aplican a auxiliares (clase .eq-hide-in-aux via CSS).
            document.body.classList.toggle('eq-aux-mode', data.mode === 'aux');

            // Stats / distribución / URL: solo en la primera página (offset=0).
            // En lotes subsiguientes (append) los totales ya están correctos y no se tocan.
            if (!append) {
                // Al cambiar de modo (volver a equipos o a un tipo de vehiculo), limpiar la
                // seleccion de auxiliares para no dejar su barra flotante activa de fondo.
                if (data.mode !== 'aux' && typeof window.auxClearSelection === 'function') {
                    window.auxClearSelection();
                }
                const hasActiveFilters = !!paramStr;
                const displayStat = (val) => hasActiveFilters ? val : '--';
                const setEl = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };

                // Titulo del Consolidado segun el modo (Equipos vs Auxiliares). El server-render
                // pone el inicial; aqui se actualiza al cambiar de modo via AJAX (sin esto
                // quedaba "de Equipos" al pasar a auxiliares, o viceversa).
                document.querySelectorAll('.consolidado-scope').forEach(function (el) {
                    el.textContent = (data.mode === 'aux') ? 'Equipos Auxiliares' : 'Equipos y Maquinaria';
                });

                // Consolidado: modo "Con / Sin documento". La lista sigue filtrada
                // por documento como siempre; aquí solo cambia el Consolidado:
                // los bloques verde/rojo muestran cuántos equipos tienen el doc y
                // cuántos no, y el TOTAL pasa a ser el universo del frente sin el
                // recorte de doc (doc_total). Misma lógica que el render en Blade.
                const docMode = !!(data.stats && data.stats.doc_mode);
                window.__equiposDocMode = docMode;
                const docLabel  = (data.stats && data.stats.doc_label) || '';
                const totalVal  = docMode ? data.stats.doc_total : data.stats.total;
                const operVal   = docMode ? data.stats.doc_con   : data.stats.activos;
                const inopVal   = docMode ? data.stats.doc_sin   : data.stats.inactivos;

                setEl('stats_total',            displayStat(totalVal));
                setEl('stats_activos',          displayStat(operVal));
                setEl('stats_inactivos',        displayStat(inopVal));
                setEl('mobile_stats_total',     displayStat(totalVal));
                setEl('mobile_stats_activos',   displayStat(operVal));
                setEl('mobile_stats_inactivos', displayStat(inopVal));

                // ── Consolidado de Equipos Auxiliares (panel propio, debajo del de equipos) ──
                // Refleja el filtro de FRENTE (mismas reglas de display: '--' sin filtro).
                // Se oculta en modo aux: ahí el consolidado principal de arriba ya es de
                // auxiliares, así no se duplica.
                const auxCons = data.auxConsolidado || null;
                if (auxCons) {
                    setEl('aux_stats_total',            displayStat(auxCons.total));
                    setEl('aux_stats_activos',          displayStat(auxCons.activos));
                    setEl('aux_stats_inactivos',        displayStat(auxCons.inactivos));
                    setEl('aux_mobile_stats_total',     displayStat(auxCons.total));
                    setEl('aux_mobile_stats_activos',   displayStat(auxCons.activos));
                    setEl('aux_mobile_stats_inactivos', displayStat(auxCons.inactivos));
                }
                // Mostrar la card de auxiliares solo cuando NO se filtra por un tipo
                // concreto (frente o sin filtro → los dos; tipo equipo → solo equipos;
                // tipo aux → solo auxiliares).
                const showAux = !!data.showAuxConsolidado;
                ['auxConsolidadoCard', 'auxConsolidadoCardMobile'].forEach(function (id) {
                    const el = document.getElementById(id);
                    if (el) el.style.display = showAux ? '' : 'none';
                });

                // Etiquetas dinámicas de los dos bloques.
                setEl('stats_oper_label',  docMode ? 'Con ' + docLabel  : 'Operativo');
                setEl('stats_inop_label',  docMode ? 'Sin ' + docLabel  : 'Inoperativo');
                setEl('mobile_oper_label', docMode ? 'CON' : 'OPER.');
                setEl('mobile_inop_label', docMode ? 'SIN' : 'INOP.');

                // En modo documento el label es más largo: .is-doc lo achica y lo
                // mantiene en una sola línea (ver CSS .consolidado-stat-label.is-doc).
                ['stats_oper_label', 'stats_inop_label'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.classList.toggle('is-doc', docMode);
                });

                // En modo documento los bloques verde/rojo/total filtran la LISTA por
                // presencia (Con/Sin/Todos), no por estado. Cursor pointer + tooltips
                // acordes, y resaltar el lado activo (__updateDocPresenceUI).
                const blkOper  = document.getElementById('block_oper');
                const blkInop  = document.getElementById('block_inop');
                const blkTotal = document.getElementById('block_total');
                if (blkOper) {
                    blkOper.style.cursor = 'pointer';
                    blkOper.title = docMode ? ('Ver solo los que tienen ' + docLabel) : 'Filtrar: Operativos';
                }
                if (blkInop) {
                    blkInop.style.cursor = 'pointer';
                    blkInop.title = docMode ? ('Ver solo los que NO tienen ' + docLabel) : 'Filtrar: Inoperativos';
                }
                if (blkTotal) {
                    blkTotal.title = docMode ? 'Ver todos (con + sin)' : 'Ver todos los equipos';
                }
                // Fuera de docMode, resetear la dirección para la próxima vez.
                if (!docMode) window.__equiposDocPresence = 'con';
                window.__updateDocPresenceUI();

                const distroContainer = document.getElementById('distributionStatsContainer');
                if (distroContainer) distroContainer.innerHTML = data.distribution;

                // Ubicaciones (DETALLE_UBICACION_ACTUAL) — solo para frentes TIPO_FRENTE=ESPECIAL.
                // El filtro por detalle se activa desde este panel lateral (ya no es un filtro avanzado).
                const ubicacionesCard      = document.getElementById('ubicacionesStatsCard');
                const ubicacionesContainer = document.getElementById('ubicacionesStatsContainer');
                const showUbi = !!(data && data.showUbicaciones);

                if (ubicacionesCard && ubicacionesContainer) {
                    if (showUbi && data.ubicaciones) {
                        ubicacionesContainer.innerHTML = data.ubicaciones;
                        ubicacionesCard.style.display = '';
                    } else {
                        ubicacionesCard.style.display = 'none';
                        ubicacionesContainer.innerHTML = '';
                    }
                }

                // Al salir de un frente especial, limpiar el detalle para que no quede colgado.
                const detalleUbicEl = document.getElementById('detalleUbicacionFilter');
                if (!showUbi && detalleUbicEl && detalleUbicEl.value !== '') {
                    detalleUbicEl.value = '';
                }

                window.history.pushState(null, '', finalUrl);
            }

            // ── RENDERIZADO PROGRESIVO ───────────────────────────────────────
            // Parseamos el HTML en un contenedor temporal FUERA del DOM
            // (sin esto el navegador no calcula layout → no bloquea)
            const temp = document.createElement('tbody');
            temp.innerHTML = data.html;
            const allRows = Array.from(temp.children);

            if (!append) {
                tableBody.innerHTML = '';
            }
            tableBody.style.opacity = '1';

            const CHUNK_SIZE = 30; // filas por lote
            let index = 0;
            let scrollObserver = null;
            let pausedResumeHandler = null;

            function renderNextChunk(entries, observer) {
                // Si es invocado por el IntersectionObserver, asegurar que está intersectando
                if (entries && entries[0] && !entries[0].isIntersecting) return;

                // Desconectar el observador viejo para evitar fugas de memoria
                if (observer) observer.disconnect();

                // Guard #1: nueva petición canceló esta → no insertar filas obsoletas
                if (abortController.signal.aborted) return;
                // Guard #2: tabla quitada del DOM (nav SPA) → cancelar loop
                if (!document.contains(tableBody)) return;

                // Guard #3: tab oculto → pausar rendering y reanudar cuando vuelva visible.
                // Evita que al volver al navegador el main thread procese N chunks colapsados
                // en un solo frame (síntoma: pantalla "congelada" tras alt-tab).
                if (document.hidden) {
                    if (!pausedResumeHandler) {
                        pausedResumeHandler = () => {
                            if (!document.hidden) {
                                document.removeEventListener('visibilitychange', pausedResumeHandler);
                                pausedResumeHandler = null;
                                renderNextChunk();
                            }
                        };
                        document.addEventListener('visibilitychange', pausedResumeHandler);
                    }
                    return;
                }

                const chunk = allRows.slice(index, index + CHUNK_SIZE);
                if (chunk.length === 0) {
                    reApplySelections();
                    // Modo aux: restaurar el highlight de seleccion de las filas aux (su set
                    // de seleccion lo mantiene la maquinaria aux embebida, no reApplySelections).
                    if (data.mode === 'aux' && typeof window.auxRestoreSelection === 'function') {
                        window.auxRestoreSelection();
                    }
                    // Infinite scroll: si el backend dice que hay más, observar la última fila
                    // del conjunto actual y disparar nuevo fetch con offset = nextOffset.
                    if (data && data.hasMore && typeof data.nextOffset === 'number') {
                        const lastRow = tableBody.lastElementChild;
                        if (lastRow && !lastRow.dataset.infiniteObserved) {
                            lastRow.dataset.infiniteObserved = '1';
                            const infObs = new IntersectionObserver((entries, obs) => {
                                if (entries[0] && entries[0].isIntersecting) {
                                    obs.disconnect();
                                    window.loadEquipos(null, true, { offset: data.nextOffset, append: true });
                                }
                            }, { root: null, rootMargin: '400px', threshold: 0 });
                            infObs.observe(lastRow);
                        }
                    }
                    return;
                }

                const fragment = document.createDocumentFragment();
                chunk.forEach(node => fragment.appendChild(node));
                tableBody.appendChild(fragment);

                // Registrar imágenes del chunk recién insertado con el loader controlado
                if (window._registerLazyImages) window._registerLazyImages(tableBody);

                index += CHUNK_SIZE;

                // Crear un sensor en la antepenúltima fila del chunk para cargar el siguiente
                if (index < allRows.length) {
                    const triggerIndex = Math.max(0, chunk.length - 5);
                    const triggerRow = chunk[triggerIndex];

                    if (triggerRow) {
                        scrollObserver = new IntersectionObserver((obsEntries, obs) => {
                            renderNextChunk(obsEntries, obs);
                        }, {
                            root: null,
                            rootMargin: "300px", // Detectar 300px antes de llegar a la fila
                            threshold: 0
                        });
                        scrollObserver.observe(triggerRow);
                    }
                } else {
                    // Terminamos de inyectar todo
                    reApplySelections();
                }
            }

            // Nota: ya no mostramos toast de truncado — ahora hay infinite scroll real.

            // Iniciar la inyección del primer chunk
            renderNextChunk();
        })
        .catch((error) => {
            // Si esta peticion fue ABORTADA por una mas nueva, no tocar el DOM —
            // la nueva ya hizo su propio tableBody.style.opacity = '0.5' al arrancar
            // y va a setearlo en 1 cuando termine. Si pisamos opacity=1 aca, se ve
            // un flicker visual (0.5 → 1 → 0.5 → 1) durante busquedas encadenadas.
            // Tambien evitamos loguear: AbortError es normal en este flujo.
            if (abortController.signal.aborted) return;
            console.error('Error loading equipos:', error);
            tableBody.style.opacity = '1';
        })
        .finally(() => {
            // BUG #1 (race-condition por aborto): si una peticion mas nueva ABORTO esta
            // (loadEquipos llamada de nuevo antes de que terminara la primera), .finally
            // de la peticion abortada corria igual y ocultaba el spinner — pero el fetch
            // nuevo seguia en vuelo y aun no habia traido el equipo. Sintoma del usuario:
            // "el spinner desaparece tan rapido que el equipo buscado todavia no esta".
            // Fix: si esta peticion fue abortada, dejar el spinner — el .finally del
            // fetch ganador lo va a ocultar cuando corresponda.
            if (abortController.signal.aborted) return;
            //
            // BUG #2 (PWA — solo en chromium standalone, Windows): el preloader se quitaba
            // ANTES de que la fila aparezca → blank → fila. Causa: .finally corre como
            // microtask justo despues de .then; renderNextChunk sincronicamente inserta el
            // primer chunk en el DOM, pero el navegador todavia no ha commiteado un paint
            // con esa insertion.
            // Fix: doble rAF. El primer rAF agenda la callback para el frame siguiente
            // (corre antes del layout/paint de ese frame); el segundo rAF la agenda para
            // el frame DESPUES del paint que ya tiene la fila — recien ahi arranca el
            // fade-out del preloader, garantizando "fila visible → spinner se va".
            if (window.hidePreloader) {
                requestAnimationFrame(() => requestAnimationFrame(() => window.hidePreloader()));
            }
        });
};

window.filterList = function (inputArg, listArg) {
    // Support both element references and ID strings (backward compatible)
    const input =
        typeof inputArg === "string"
            ? document.getElementById(inputArg)
            : inputArg;
    const list =
        typeof listArg === "string"
            ? document.getElementById(listArg)
            : listArg;
    if (!input || !list) return;

    const filter = input.value.toUpperCase();
    const items = list.querySelectorAll(".filter-option-item");

    items.forEach((item) => {
        const txt = item.textContent || item.innerText;
        item.style.display =
            txt.toUpperCase().indexOf(filter) > -1 ? "" : "none";
    });

    list.style.display = "block";
};


/**
 * Modal de asignacion masiva de DETALLE_UBICACION_ACTUAL.
 * Todos los equipos seleccionados deben estar en el MISMO frente
 * (la ubicacion especifica es relativa al frente donde estan).
 */
window.openUbicacionBulkModal = function (event) {
    if (event) { event.preventDefault(); event.stopPropagation(); }

    // Permiso: asignar ubicacion especifica es parte del flujo de asignacion
    // (mismo que movilizar). Backend valida can('equipos.assign') igual.
    if (window.CAN_ASSIGN_EQUIPOS === false || window.CAN_ASSIGN_EQUIPOS === 'false') {
        if (typeof window.showToast === 'function') {
            window.showToast('No tienes permisos para actualizar detalles.', 'error');
        }
        return;
    }

    const selections = Object.values(window.selectedEquipos || {});
    if (selections.length === 0) {
        if (typeof window.showToast === 'function') window.showToast('Selecciona al menos un equipo.', 'error');
        return;
    }

    // Validar mismo frente: la ubicacion especifica solo tiene sentido dentro
    // de un frente concreto. Si hay mezcla → toast moderno (sin modal bloqueante).
    const frentesUnicos = [...new Set(selections.map(s => s.frenteId || ''))];
    if (frentesUnicos.length > 1 || frentesUnicos[0] === '') {
        if (typeof window.showToast === 'function') {
            window.showToast('Todos los equipos seleccionados deben estar en el MISMO frente. Revisa tu selección.', 'error');
        } else {
            alert('Todos los equipos seleccionados deben estar en el MISMO frente. Revisa tu selección.');
        }
        return;
    }

    // ── Leer valor actual de DETALLE_UBICACION_ACTUAL desde equiposData ──
    // Si todos los equipos seleccionados tienen el MISMO valor → pre-cargar.
    // Si difieren (o no hay datos en cache) → dejar vacío para evitar sobreescritura accidental.
    const ids = selections.map(s => s.id);
    const valoresActuales = ids.map(id => {
        const eq = (window.equiposData && window.equiposData[id]) ? window.equiposData[id] : null;
        return eq ? (eq.detalleUbicacion || '') : null;
    });
    // Si algún equipo no está en cache (null), el valor previo es desconocido → no pre-cargar
    const todosEnCache = valoresActuales.every(v => v !== null);
    const valoresUnicos = todosEnCache ? [...new Set(valoresActuales)] : [];
    // Valor previo común (solo si todos coinciden exactamente)
    const valorPrevioComun = (todosEnCache && valoresUnicos.length === 1) ? valoresUnicos[0] : '';
    // Para el hint informativo: si hay valores distintos, resumirlos
    const hayValoresMixtos = todosEnCache && valoresUnicos.length > 1;

    // Construir modal
    const overlay = document.createElement('div');
    overlay.id = 'ubicacionBulkOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(3px);z-index:10001;display:flex;align-items:center;justify-content:center;padding:20px;';

    // Agrupar por tipo y contar (mismo resumen limpio que el modal de Movilización):
    // muestra el total por tipo en vez de la placa/serial individual de cada equipo.
    const ubTipoCount = {};
    selections.forEach(s => {
        const tipoNombre = (s.tipo && s.tipo.trim() !== '') ? s.tipo.trim().toUpperCase() : 'SIN TIPO';
        ubTipoCount[tipoNombre] = (ubTipoCount[tipoNombre] || 0) + 1;
    });
    const chipsHtml = Object.entries(ubTipoCount)
        .sort((a, b) => b[1] - a[1])
        .map(([tipoNombre, cant]) => {
            // Mismo chip que el modal de Movilización: círculo (cantidad) + nombre,
            // sin caja; el grid exterior alinea las columnas entre todas las filas.
            return `<div style="display:flex;align-items:center;gap:7px;">
                <div style="width:20px;height:20px;background:#1e293b;color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;flex-shrink:0;">${cant}</div>
                <span style="font-size:10px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.1px;">${tipoNombre}</span>
            </div>`;
        }).join('');

    overlay.innerHTML = `
        <div style="background:white;width:100%;max-width:440px;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;animation:ubBulkIn 0.22s cubic-bezier(0.16,1,0.3,1);">
            <!-- Header mismo patron que modal de Anclaje: fondo #1e293b + titulo centrado con icono de acento -->
            <div style="background:#1e293b;padding:18px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <i class="material-icons" style="color:#0284c7;font-size:20px;">description</i>
                    <h2 style="margin:0;font-size:16px;font-weight:700;">Detalle a resaltar</h2>
                </div>
                <button type="button" id="ub-close" aria-label="Cerrar" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                    <i class="material-icons">close</i>
                </button>
            </div>
            <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
                <div>
                    <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">${selections.length} equipo${selections.length !== 1 ? 's' : ''} seleccionado${selections.length !== 1 ? 's' : ''}</p>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:5px 14px;max-height:110px;overflow-y:auto;">
                        ${chipsHtml}
                    </div>
                </div>
                <div>
                    <div id="ub-inputbox" style="display:flex;align-items:center;border:1.5px solid ${valorPrevioComun ? '#0284c7' : '#e2e8f0'};border-radius:10px;background:white;overflow:hidden;transition:border-color 0.2s;">
                        <i class="material-icons" style="padding:0 10px;color:#94a3b8;font-size:18px;flex-shrink:0;">location_on</i>
                        <input type="text" id="ub-input" maxlength="150" autocomplete="off"
                            value="${valorPrevioComun}"
                            placeholder="Aspecto a resaltar"
                            style="flex:1;border:none;outline:none;padding:10px 6px;font-size:13px;background:transparent;text-transform:uppercase;letter-spacing:0.3px;">
                    </div>
                    ${(valorPrevioComun || hayValoresMixtos) ? ('<small style="display:block;margin-top:6px;font-size:11px;line-height:1.4;">' + (valorPrevioComun ? '<span style="color:#0284c7;font-weight:600;">Deja el campo en blanco y guarda para borrar el detalle actual.</span>' : '<span style="color:#d97706;font-weight:600;">Los equipos seleccionados tienen detalles distintos.</span>') + '</small>') : ''}
                </div>
                <div id="ub-feedback" style="display:none;padding:10px 12px;border-radius:8px;font-size:12.5px;font-weight:600;"></div>
                <button type="button" id="ub-submit" style="width:100%;height:46px;border-radius:12px;font-weight:700;font-size:14px;background:#1e293b;color:white;border:none;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;transition:all 0.2s;">
                    <i class="material-icons">check_circle</i> Aplicar Detalle
                </button>
            </div>
        </div>
    `;

    // Keyframe comparte el `reimprimirIn` ya inyectado en index.blade; si no existe, inyectamos.
    if (!document.getElementById('ub-keyframes')) {
        const st = document.createElement('style');
        st.id = 'ub-keyframes';
        st.textContent = '@keyframes ubBulkIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } } #ub-inputbox:focus-within { border-color:#0284c7; box-shadow:0 0 0 3px rgba(2,132,199,0.15); }';
        document.head.appendChild(st);
    }

    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    const closeModal = () => { overlay.remove(); document.body.style.overflow = ''; };
    const input = overlay.querySelector('#ub-input');
    const box   = overlay.querySelector('#ub-inputbox');
    const fb    = overlay.querySelector('#ub-feedback');
    const submitBtn = overlay.querySelector('#ub-submit');
    setTimeout(() => input.focus(), 80);

    overlay.querySelector('#ub-close').onclick  = closeModal;
    overlay.onclick = (e) => { if (e.target === overlay) closeModal(); };
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doSubmit(); } });

    function showFb(type, msg) {
        const c = {
            info:    { bg:'#e0f2fe', border:'#bae6fd', color:'#075985' },
            error:   { bg:'#fee2e2', border:'#fecaca', color:'#b91c1c' },
            success: { bg:'#dcfce7', border:'#bbf7d0', color:'#15803d' },
        }[type] || { bg:'#e0f2fe', border:'#bae6fd', color:'#075985' };
        fb.style.cssText = 'display:block;padding:10px 12px;border-radius:8px;font-size:12.5px;font-weight:600;background:' + c.bg + ';border:1px solid ' + c.border + ';color:' + c.color + ';';
        fb.textContent = msg;
    }

    async function doSubmit() {
        const valor = (input.value || '').trim();

        // Caso 1: campo vacío + había valor previo → borrado directo sin
        // confirmación (pedido del cliente: el modal extra estorbaba la
        // operación). Si el usuario manda submit con el input vacío y
        // el equipo tenía detalle previo, asumimos que QUIERE borrarlo.
        if (!valor && valorPrevioComun) {
            _enviarUbicacion('');
            return;
        }

        // Caso 2: campo vacío + sin valor previo → nada que guardar, informar y cerrar
        if (!valor && !valorPrevioComun) {
            if (typeof window.showToast === 'function') {
                window.showToast('Escribe un detalle o cierra el modal sin cambios.', 'info');
            }
            input.focus();
            return;
        }

        // Caso 3: hay texto → guardar normalmente
        _enviarUbicacion(valor);
    }

    async function _enviarUbicacion(valorFinal) {
        submitBtn.disabled = true;
        // Spinner GLOBAL tradicional (fondo blanco sobre toda la pagina) en vez
        // del micro-spinner inline dentro del boton.
        if (typeof window.showPreloader === 'function') window.showPreloader();
        try {
            const res = await fetch('/admin/equipos/bulk-ubicacion', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.getCsrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ ids: ids, detalle_ubicacion: valorFinal }),
            });
            if (!res.ok) {
                const err = await res.json().catch(() => ({}));
                throw new Error(err.message || ('Error ' + res.status));
            }
            const data = await res.json();

            // ── Actualizar cache local INMEDIATAMENTE ──────────────────────────
            // loadEquipos puede paginar y no devolver este equipo en la ventana
            // actual, dejando equiposData con el valor viejo. Actualizamos aquí
            // de forma directa para que el próximo openUbicacionBulkModal lea
            // el valor correcto sin depender del refresh de tabla.
            const nuevoDetalle = valorFinal ? valorFinal.toUpperCase() : '';
            if (window.equiposData) {
                ids.forEach(id => {
                    if (window.equiposData[id]) {
                        window.equiposData[id].detalleUbicacion = nuevoDetalle;
                    }
                });
            }

            if (typeof window.clearSelection === 'function') window.clearSelection();
            closeModal();
            // Esperar a que la tabla termine el primer render antes de apagar el preloader.
            // Evita el parpadeo donde el spinner se quita mientras la tabla aun se esta
            // redibujando con los datos nuevos.
            if (typeof window.loadEquipos === 'function') {
                await window.loadEquipos(null, true);
            }
            const toastMsg = valorFinal
                ? 'Detalle actualizado en ' + (data.count || selections.length) + ' equipo(s).'
                : 'Detalle borrado en ' + (data.count || selections.length) + ' equipo(s).';
            if (typeof window.showToast === 'function') window.showToast(toastMsg, 'success');
        } catch (err) {
            console.error('[Ubicacion bulk]', err);
            showFb('error', err.message || 'No se pudo actualizar.');
            submitBtn.disabled = false;
        } finally {
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
        }
    }
    overlay.querySelector('#ub-submit').onclick = doSubmit;
};

window.openBulkModal = function (event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
    }

    // PERMISSION CHECK (Specific to Assignment/Mobilization)
    if (window.CAN_ASSIGN_EQUIPOS === false || window.CAN_ASSIGN_EQUIPOS === 'false') {
        if (typeof window.showToast === 'function') {
            window.showToast('Acceso Denegado: No tienes permisos para movilizar equipos.', 'error');
        } else {
            alert("Acceso Denegado: No tienes permisos.");
        }
        return;
    }

    // 1. Validation
    if (
        !window.selectedEquipos ||
        Object.keys(window.selectedEquipos).length === 0
    ) {
        alert("Por favor seleccione equipos primero.");
        return;
    }

    // ── OFFLINE (Fase 2): el modal online crea frentes, pide ubicación y genera
    // acta (todo server-side). Sin internet abrimos un modal SIMPLE que solo mueve
    // a un frente EXISTENTE y encola la acción. El acta queda disponible al sincronizar.
    if (window.OfflineMode && window.OfflineMode.estaActivo() && typeof window.abrirModalMovilizarOffline === 'function') {
        window.abrirModalMovilizarOffline(Object.values(window.selectedEquipos));
        return;
    }

    // 2. Nuclear Cleanup: Remove any existing dynamic modals
    document.querySelectorAll(".dynamic-bulk-modal").forEach((el) => el.remove());

    // 3. Collect selected equipment codes
    const selectedList = Object.values(window.selectedEquipos);

    // 4. Collect frentes from datalist in DOM. data-ubicacion permite saber
    // si el frente registrado tiene ubicacion en BD: si esta vacia, el modal
    // tambien pide ubicacion (escenario "frente registrado sin ubicacion").
    const frentesData = [];
    const dl = document.querySelector("#dynamicFrentesList");
    if (dl) {
        dl.querySelectorAll("option").forEach((opt) => {
            const nombre = opt.getAttribute("value") || "";
            const id = opt.getAttribute("data-id") || "";
            const ubicacion = (opt.getAttribute("data-ubicacion") || "").trim();
            if (nombre) frentesData.push({ nombre, id, ubicacion });
        });
    }

    // 5. Create Overlay
    const overlay = document.createElement("div");
    overlay.className = "dynamic-bulk-modal";
    // z-index 10001 (no 2500): por encima de la barra flotante de selección
    // (.selection-floating-bar = 9999), para que ésta quede ATRÁS y atenuada por
    // el backdrop — mismo comportamiento que el modal "Asignar Detalle".
    overlay.style.cssText = "position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.55);z-index:10001;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(3px);";

    // 6. Create Content Box
    const content = document.createElement("div");
    content.style.cssText = "background:white;border-radius:16px;width:90%;max-width:480px;max-height:92vh;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.30);animation:slideDown 0.2s ease-out;display:flex;flex-direction:column;";

    // 7. Header — mismo patron que Anclaje/Ubicacion: fondo #1e293b solido,
    // titulo centrado con icono de acento, close button absoluto arriba-derecha.
    const header = document.createElement("div");
    header.style.cssText = "background:#1e293b;padding:18px;color:white;display:flex;justify-content:center;align-items:center;position:relative;";
    header.innerHTML = `
        <div style="display:flex;align-items:center;gap:10px;">
            <i class="material-icons" style="color:#0067b1;font-size:20px;">local_shipping</i>
            <h2 style="margin:0;font-size:16px;font-weight:700;">Movilización</h2>
        </div>
        <button type="button" id="btnCloseDynamic" aria-label="Cerrar" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
            <i class="material-icons">close</i>
        </button>
    `;

    // 8. Body
    const body = document.createElement("div");
    body.style.cssText = "padding:22px 24px;display:flex;flex-direction:column;gap:18px;overflow-y:auto;flex:1;";

    // Agrupar los equipos seleccionados por tipo y contar cuántos hay de cada uno.
    // Esto reemplaza mostrar el serial/placa individual de cada equipo por un resumen
    // limpio: "3 × EXCAVADORA HIDRÁULICA", "2 × CAMIÓN VOLTEO", etc.
    const tipoCount = {};
    selectedList.forEach(item => {
        const tipoNombre = (item.tipo && item.tipo.trim() !== '') ? item.tipo.trim().toUpperCase() : 'SIN TIPO';
        tipoCount[tipoNombre] = (tipoCount[tipoNombre] || 0) + 1;
    });

    // Ordenar por cantidad descendente para mostrar los más numerosos primero
    const tipoChipsHtml = Object.entries(tipoCount)
        .sort((a, b) => b[1] - a[1])
        .map(([tipoNombre, cant]) => {
            // Sin contenedor gris: solo el círculo (cantidad) + el nombre. El grid
            // exterior alinea ambas columnas (círculo y nombre) entre todas las filas.
            return `<div style="display:flex;align-items:center;gap:7px;">
                <div style="width:20px;height:20px;background:#1e293b;color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:800;flex-shrink:0;">${cant}</div>
                <span style="font-size:10px;font-weight:700;color:#1e293b;text-transform:uppercase;letter-spacing:0.1px;">${tipoNombre}</span>
            </div>`;
        }).join("");

    body.innerHTML = `
        <div>
            <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Equipos a movilizar</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:5px 14px;">
                ${tipoChipsHtml}
            </div>
        </div>
        <div>
            <label style="display:block;font-size:13px;font-weight:700;color:#475569;margin-bottom:8px;">
                <i class="material-icons" style="font-size:14px;vertical-align:middle;margin-right:4px;">place</i>
                Frente de Destino <span style="color:#ef4444;">*</span>
            </label>
            <div style="position:relative;" id="bm-frente-wrapper">
                <div style="display:flex;align-items:center;border:2px solid #e2e8f0;border-radius:10px;background:white;overflow:hidden;transition:border-color 0.2s;" id="bm-input-box">
                    <i class="material-icons" style="padding:0 10px;color:#94a3b8;font-size:20px;flex-shrink:0;">search</i>
                    <input type="text" id="bm-frente-search"
                        placeholder="Buscar frente de destino..."
                        autocomplete="off"
                        style="flex:1;border:none;outline:none;padding:11px 6px;font-size:14px;background:transparent;">
                    <i class="material-icons" id="bm-frente-clear" style="padding:0 10px;color:#94a3b8;font-size:18px;cursor:pointer;display:none;">close</i>
                </div>
                <input type="hidden" id="bm-frente-value">
            </div>
            <div id="bm-ubicacion-wrapper" style="margin-top: 12px; display: none;">
                <label for="bm-ubicacion-dest" style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px;">
                    <i class="material-icons" style="font-size:14px;vertical-align:middle;margin-right:3px;">location_on</i>
                    Ubicación del destino <span style="color:#ef4444;">*</span>
                </label>
                <input type="text" id="bm-ubicacion-dest" placeholder="Ej: CALLE / SECTOR / ZONA"
                    autocomplete="off"
                    style="width:100%;padding:10px 12px;border:2px solid #e2e8f0;border-radius:10px;font-size:13px;background:white;box-sizing:border-box;text-transform:uppercase;">
            </div>
            <div style="margin-top: 15px; display: flex; align-items: center; gap: 8px; padding: 10px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <input type="checkbox" id="bm-generar-pdf" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e293b;">
                <label for="bm-generar-pdf" style="font-size: 13px; font-weight: 600; color: #475569; cursor: pointer; user-select: none; margin: 0;">
                    Acta de Traslado en formato PDF
                </label>
            </div>
        </div>
        <button type="button" id="bm-submit-btn" style="width:100%;height:48px;border-radius:10px;font-weight:700;font-size:15px;background:#1e293b;color:white;border:none;display:flex;align-items:center;justify-content:center;gap:10px;cursor:pointer;transition:background 0.2s;">
            <i class="material-icons" style="font-size:18px;">send</i> Confirmar Movilización
        </button>
    `;

    // 9. Assemble
    content.appendChild(header);
    content.appendChild(body);
    overlay.appendChild(content);

    document.body.appendChild(overlay);

    // ── Dropdown portal: renderizado en document.body para escapar del overflow modal ──
    const listBox = document.createElement('div');
    listBox.id = 'bm-frente-list-portal';
    // z-index por encima del overlay del modal de Movilización (10001) para que la
    // lista de sugerencias de frente NO quede oculta detrás del formulario.
    listBox.style.cssText = 'display:none;position:fixed;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);z-index:100020;max-height:240px;overflow-y:auto;';
    document.body.appendChild(listBox);

    const ubicWrapper = overlay.querySelector('#bm-ubicacion-wrapper');
    const ubicInput = overlay.querySelector('#bm-ubicacion-dest');
    function toggleUbicacionField(dest) {
        const d = (dest || '').trim().toUpperCase();
        const matched = d ? frentesData.find(f => (f.nombre || '').toUpperCase() === d) : null;
        const needs = d && (!matched || !matched.ubicacion);
        ubicWrapper.style.display = needs ? 'block' : 'none';
        if (!needs && matched && matched.ubicacion) ubicInput.value = matched.ubicacion;
        else if (!needs) ubicInput.value = '';
    }
    ubicInput.addEventListener('input', () => { ubicInput.style.borderColor = '#e2e8f0'; });

    // Reposiciona el portal justo debajo del input
    function positionListBox() {
        const rect = inputBox.getBoundingClientRect();
        listBox.style.top = (rect.bottom + 4) + 'px';
        listBox.style.left = rect.left + 'px';
        listBox.style.width = rect.width + 'px';
    }

    function renderFrenteList(filter) {
        listBox.innerHTML = '';
        const q = (filter || '').trim().toUpperCase();
        const filtered = q ? frentesData.filter(f => f.nombre.toUpperCase().includes(q)) : frentesData;
        if (filtered.length === 0) {
            // Sin resultados: contraer el dropdown en vez de mostrar mensaje.
            listBox.style.display = 'none';
            return;
        } else {
            filtered.forEach(f => {
                const item = document.createElement('div');
                item.style.cssText = 'padding:11px 16px;cursor:pointer;font-size:14px;color:#1e293b;border-bottom:1px solid #f8fafc;transition:background 0.15s;';
                item.textContent = f.nombre;
                item.onmouseover = () => item.style.background = '#eff6ff';
                item.onmouseout = () => item.style.background = 'white';
                item.onmousedown = (e) => {
                    e.preventDefault();
                    searchInput.value = f.nombre;
                    hiddenInput.value = f.nombre;
                    clearBtn.style.display = 'flex';
                    listBox.style.display = 'none';
                    inputBox.style.borderColor = '#0067b1';
                    toggleUbicacionField(f.nombre);
                };
                listBox.appendChild(item);
            });
        }
        positionListBox();
        listBox.style.display = 'block';
    }

    // Limpiar el portal cuando se cierre el modal
    function removePortal() { listBox.remove(); }

    const searchInput = overlay.querySelector('#bm-frente-search');
    const hiddenInput = overlay.querySelector('#bm-frente-value');
    const clearBtn = overlay.querySelector('#bm-frente-clear');
    const inputBox = overlay.querySelector('#bm-input-box');

    searchInput.addEventListener('focus', () => {
        inputBox.style.borderColor = '#0067b1';
        renderFrenteList(searchInput.value);
    });
    searchInput.addEventListener('input', () => {
        hiddenInput.value = searchInput.value.trim();
        clearBtn.style.display = searchInput.value ? 'flex' : 'none';
        renderFrenteList(searchInput.value);
        toggleUbicacionField(searchInput.value);
    });
    searchInput.addEventListener('blur', () => {
        setTimeout(() => { listBox.style.display = 'none'; inputBox.style.borderColor = '#e2e8f0'; }, 150);
    });
    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        hiddenInput.value = '';
        clearBtn.style.display = 'none';
        searchInput.focus();
        toggleUbicacionField('');
    });

    // ── Close handlers ──
    const _closeModal = () => { removePortal(); overlay.remove(); };
    overlay.querySelector('#btnCloseDynamic').onclick = _closeModal;
    overlay.onclick = (e) => { if (e.target === overlay) _closeModal(); };

    // ── Submit ──
    overlay.querySelector("#bm-submit-btn").onclick = async function () {
        const dest = (hiddenInput.value || searchInput.value).trim();
        const generarPdfBox = overlay.querySelector("#bm-generar-pdf");
        let generarPdf = generarPdfBox ? generarPdfBox.checked : true;

        if (!dest) {
            inputBox.style.borderColor = "#ef4444";
            searchInput.focus();
            return;
        }

        const destUpper = dest.toUpperCase();
        const matchedFrente = frentesData.find(f => (f.nombre || '').toUpperCase() === destUpper);
        const isNewFrente = !matchedFrente;
        const needsUbicacion = isNewFrente || !matchedFrente.ubicacion;

        // Si el frente es nuevo/sin ubicación, la ubicación se captura en el campo
        // inline del modal (bm-ubicacion-dest) — ya no se fuerza el Acta para eso.
        let destUbicacion = '';
        if (needsUbicacion) {
            destUbicacion = (ubicInput.value || '').trim().toUpperCase();
            if (!destUbicacion) {
                ubicInput.style.borderColor = '#ef4444';
                ubicInput.focus();
                if (window.showToast) window.showToast('Indica la ubicación del frente de destino.', 'error');
                return;
            }
        } else {
            destUbicacion = matchedFrente.ubicacion || '';
        }

        const btn = this;
        const ids = Object.keys(window.selectedEquipos);

        // Estado EDITABLE del acta. Arranca con lo del modal; la vista previa puede
        // mutarlo (quitar equipos → ids; re-rutear destino; override cosmético de
        // origen/zona/firmas para el PDF). ejecutarCommit y la vista previa leen de aquí.
        //   - ids / destination / destination_ubicacion → afectan el REGISTRO real.
        //   - origin / origin_zona / firmas → SOLO el documento impreso (override).
        const actaState = {
            ids: ids.slice(),
            destination: dest,
            destination_ubicacion: destUbicacion,
            origin: '',
            origin_zona: '',
            firmas: null // null = firmas por defecto del frente de origen
        };

        // Registro real (bulk-mobilize) + post-proceso (cerrar modal, refrescar
        // tabla, descargar acta final). Se llama directo si NO se genera acta, o
        // desde el botón "Confirmar" de la VISTA PREVIA cuando sí se genera.
        const ejecutarCommit = async function () {
        btn.innerHTML = '<i class="material-icons" style="font-size:18px;animation:spin 1s linear infinite;">sync</i> Procesando...';
        btn.disabled = true;
        btn.style.opacity = "0.7";
        if (window.showPreloader) window.showPreloader();

        try {
            const res = await fetch("/admin/equipos/bulk-mobilize", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-TOKEN": window.getCsrf(),
                    Accept: "application/json",
                },
                body: JSON.stringify({
                    ids: actaState.ids,
                    destination: actaState.destination,
                    destination_ubicacion: actaState.destination_ubicacion, // requerido si el frente es nuevo O si existe pero sin UBICACION
                    generar_pdf: generarPdf,
                    // Datos del acta (origen/zona/firmas editados en la vista previa) para
                    // que la movilización quede registrada en auditoría con esos valores.
                    origin: actaState.origin || '',
                    origin_zona: actaState.origin_zona || '',
                    firmas: actaState.firmas // null si no se editaron firmas
                }),
            });

            // Sesión expirada
            if (res.status === 419) {
                if (window.hidePreloader) window.hidePreloader();
                if (typeof window.showModal === 'function') {
                    window.showModal({
                        type: "error",
                        title: "Sesión Expirada",
                        message: "Su sesión ha expirado. La página se recargará.",
                        confirmText: "Recargar",
                        hideCancel: true,
                        onConfirm: () => window.location.reload(),
                    });
                } else {
                    window.location.reload();
                }
                return;
            }

            // Cualquier otro error HTTP: leer el body para mostrar el mensaje real
            if (!res.ok) {
                let errMsg = `Error del servidor (${res.status})`;
                try {
                    const errData = await res.json();
                    errMsg = errData?.message || errData?.error || errMsg;
                } catch (_) { /* ignorar si el body no es JSON */ }
                throw new Error(errMsg);
            }

            const data = await res.json();

            // Cerrar modal y limpiar selección, pero NO apagamos el preloader:
            // lo mantenemos activo hasta que loadEquipos termine su primer render
            // (loadEquipos en modo no-silent gestiona el hidePreloader en su .finally).
            // Así evitamos el "parpadeo" donde el spinner desaparece antes de que
            // la tabla se haya redibujado con los datos nuevos.
            removePortal();
            overlay.remove();
            window.clearSelection();

            // ── Actualizar el frente en memoria (frentesData) si se guardó una
            // ubicación nueva para él. Sin esto, al reabrir el modal en la misma
            // sesión el campo de ubicación volvería a aparecer aunque ya fue guardado.
            if (actaState.destination_ubicacion) {
                const destUpperNow = (actaState.destination || '').toUpperCase();
                const idx = frentesData.findIndex(f => (f.nombre || '').toUpperCase() === destUpperNow);
                if (idx !== -1) {
                    frentesData[idx].ubicacion = actaState.destination_ubicacion.toUpperCase();
                }
                // También actualizar el datalist del DOM para que futuras
                // instancias del modal lean el valor correcto desde el HTML.
                const dl = document.querySelector('#dynamicFrentesList');
                if (dl) {
                    const opt = Array.from(dl.querySelectorAll('option')).find(o =>
                        (o.getAttribute('value') || '').toUpperCase() === destUpperNow
                    );
                    if (opt) opt.setAttribute('data-ubicacion', actaState.destination_ubicacion.toUpperCase());
                }
            }

            // Refrescar tabla con preloader visible hasta completar el render inicial
            window.loadEquipos(null, false);

            // Descarga del acta si aplica.
            // Antes usabamos un <a href> + click(): el navegador inicia la
            // descarga en background y nuestro preloader se apagaba ANTES de
            // que el PDF estuviera listo. Ahora hacemos fetch->blob: el
            // preloader queda visible mientras el servidor genera el PDF
            // (TCPDF puede tardar varios segundos con muchos equipos), y solo
            // se apaga cuando el blob llega listo para guardar.
            if (data.generar_pdf) {
                const firstId =
                    data.movilizacion_ids && data.movilizacion_ids.length > 0
                        ? data.movilizacion_ids[0]
                        : null;

                if (firstId) {
                    if (typeof window.showPreloader === 'function') window.showPreloader();
                    // Si el usuario editó origen/firmas en la vista previa → POST con esos
                    // overrides (cosméticos) para que el acta FINAL salga igual a la previa.
                    // Sin ediciones → GET normal (acta directo del frente).
                    const tieneOverride = (actaState.origin && actaState.origin.trim() !== '') || actaState.firmas !== null;
                    const actaReq = tieneOverride
                        ? {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/pdf',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': window.getCsrf()
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                override_origin: actaState.origin || '',
                                override_origin_zona: actaState.origin_zona || '',
                                override_firmas: actaState.firmas // null o array
                            })
                        }
                        : { headers: { 'Accept': 'application/pdf' }, credentials: 'same-origin' };
                    fetch(`/admin/movilizaciones/${firstId}/acta-traslado`, actaReq)
                        .then(r => {
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.blob();
                        })
                        .then(blob => {
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `Acta_Traslado_${firstId}.pdf`;
                            a.style.display = 'none';
                            a.setAttribute('data-no-spa', 'true');
                            document.body.appendChild(a);
                            a.click();
                            setTimeout(() => {
                                document.body.removeChild(a);
                                URL.revokeObjectURL(url);
                            }, 1000);
                        })
                        .catch(err => {
                            console.error('[Acta PDF Error]:', err);
                            if (typeof window.showToast === 'function') {
                                window.showToast('No se pudo descargar el acta de traslado.', 'error');
                            }
                        })
                        .finally(() => {
                            if (typeof window.hidePreloader === 'function') window.hidePreloader();
                        });
                }
            }

            // Toast de éxito. Si algún equipo ya estaba en el frente destino, el backend
            // lo omite (no se movilizó) y lo informa en data.omitidos.
            if (typeof window.showToast === 'function') {
                const omit = data.omitidos > 0
                    ? ` (${data.omitidos} ya ${data.omitidos === 1 ? 'estaba' : 'estaban'} en el frente, omitido${data.omitidos === 1 ? '' : 's'})`
                    : '';
                if (data.generar_pdf) {
                    window.showToast(`¡Movilización exitosa! Descargando acta de ${data.count} traslado(s)...${omit}`, 'success');
                } else {
                    window.showToast(`Actualización de ubicación exitosa${omit}`, 'success');
                }
            }

            if (document.activeElement) document.activeElement.blur();
            document
                .querySelectorAll(".custom-dropdown.active")
                .forEach((el) => el.classList.remove("active"));

        } catch (err) {
            console.error('[Movilización Error]:', err);

            if (window.hidePreloader) window.hidePreloader();

            // Restaurar el botón solo si el overlay aún existe
            if (document.body.contains(overlay)) {
                btn.innerHTML = '<i class="material-icons" style="font-size:18px;">send</i> Confirmar Movilización';
                btn.disabled = false;
                btn.style.opacity = "1";
                btn.style.cursor = "pointer";
            }

            if (typeof window.showModal === 'function') {
                window.showModal({
                    type: "error",
                    title: "Error en Movilización",
                    message: err.message || "Hubo un error al procesar la movilización. Por favor intente nuevamente.",
                    confirmText: "Entendido",
                    hideCancel: true,
                });
            } else {
                alert('Error: ' + (err.message || 'Error al procesar la movilización.'));
            }
        }
        }; // ── fin ejecutarCommit ──

        // Checkbox NO tildado → ejecutar movilización directo (sin Acta).
        // Checkbox tildado + frente viejo con datos completos → PDF directo.
        // Checkbox tildado + frente nuevo/sin responsables → formulario para firmas.
        if (generarPdf) {
            window._mostrarVistaPreviaActa(actaState, ejecutarCommit, { editarDirecto: isNewFrente });
            return;
        }
        ejecutarCommit();
    };
};

// Vista previa del Acta de Traslado ANTES de oficializar: pide el PDF al backend
// (POST /admin/movilizaciones/preview-acta, sin commitear ni consumir N°) y lo
// muestra en un modal. "Editar" abre un formulario para ajustar (SOLO para este
// acta) frente de origen/destino y firmas (nombre/cargo/cédula); "Aceptar" re-genera
// el PDF con esos cambios; "Confirmar" ejecuta el commit real
// (onConfirm = ejecutarCommit) usando el estado editado (actaState).
window._mostrarVistaPreviaActa = async function (actaState, onConfirm, opts) {
    var csrf = window.getCsrf; // helper central (dom_helpers.js)
    // editarDirecto = abrir el FORMULARIO del acta de una (frente nuevo/sin ubicación),
    // sin mostrar primero la vista previa del PDF. previewMostrada controla a dónde
    // vuelve el botón "Cancelar" del editor: si nunca se mostró la previa (editarDirecto),
    // "Cancelar" cierra el acta y regresa al modal de Movilización (que sigue detrás).
    var editarDirecto = !!(opts && opts.editarDirecto);
    var previewMostrada = false;
    var escA = window.escapeHtml; // helper central (dom_helpers.js)

    // N° de firmas efectivas del último PDF (header X-Acta-Firmas). 0 = el frente de
    // origen no tiene responsables → pediremos esos datos en el formulario.
    var ultimaFirmasCount = null;
    var sinResponsablesOrigen = false;
    // Blob del último PDF generado: en teléfono el PDF se dibuja con PDF.js sobre
    // <canvas> a partir de su arrayBuffer (el iframe no renderiza PDF en móvil).
    var pdfBlob = null;

    // Pide el PDF al backend con los overrides actuales del estado → blob URL.
    async function pedirPreview() {
        var res = await fetch('/admin/movilizaciones/preview-acta', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/pdf' },
            body: JSON.stringify({
                ids: actaState.ids,
                destination: actaState.destination,
                destination_ubicacion: actaState.destination_ubicacion,
                origin: actaState.origin || '',
                origin_zona: actaState.origin_zona || '',
                firmas: actaState.firmas
            })
        });
        if (!res.ok) {
            var msg = 'No se pudo generar la vista previa.';
            try { var j = await res.json(); msg = j.message || msg; } catch (_) {}
            throw new Error(msg);
        }
        var hf = res.headers.get('X-Acta-Firmas');
        ultimaFirmasCount = (hf === null || hf === '') ? null : parseInt(hf, 10);
        pdfBlob = await res.blob();
        return URL.createObjectURL(pdfBlob);
    }

    if (window.showPreloader) window.showPreloader();
    var pdfUrl = null;
    try { pdfUrl = await pedirPreview(); }
    catch (e) {
        if (window.hidePreloader) window.hidePreloader();
        if (window.showToast) window.showToast(e.message || 'No se pudo generar la vista previa.', 'error');
        return;
    }
    if (window.hidePreloader) window.hidePreloader();

    // Estilos responsive (una sola vez): en teléfono el modal pasa a pantalla
    // completa, la grid del editor a 1 columna, las filas de firma a 2 columnas
    // y el footer envuelve sus botones. Usa !important porque el markup lleva
    // estilos inline que de otro modo ganarían a la media query.
    if (!document.getElementById('mov-prev-responsive-styles')) {
        var st = document.createElement('style');
        st.id = 'mov-prev-responsive-styles';
        st.textContent =
            '@media (max-width:640px){' +
                '#movPreviewOverlay{padding:0 !important;}' +
                '#mov-prev-card{max-width:100% !important;width:100% !important;height:100dvh !important;max-height:100dvh !important;border-radius:0 !important;}' +
                '#mov-prev-card .mov-ed-grid{grid-template-columns:1fr !important;}' +
                '#mov-prev-card .ed-firma-row{grid-template-columns:1fr auto !important;}' +
                '#mov-prev-card .ed-firma-row .ed-f-label{grid-column:1;grid-row:1;}' +
                '#mov-prev-card .ed-firma-row .ed-firma-del{grid-column:2;grid-row:1;}' +
                '#mov-prev-card .ed-firma-row .ed-f-car,#mov-prev-card .ed-firma-row .ed-f-nom,#mov-prev-card .ed-firma-row .ed-f-ced{grid-column:1 / -1;}' +
                '#mov-prev-foot{flex-wrap:wrap !important;}' +
                '#mov-prev-foot button{flex:1 1 140px;justify-content:center;}' +
            '}';
        document.head.appendChild(st);
    }

    var ov = document.createElement('div');
    ov.id = 'movPreviewOverlay';
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);z-index:100050;display:flex;align-items:center;justify-content:center;padding:16px;';
    ov.innerHTML =
        '<div id="mov-prev-card" style="background:white;width:100%;max-width:1100px;max-height:96vh;border-radius:14px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.35);transition:max-width 0.2s ease;">' +
            '<div style="background:#f1f5f9;padding:10px 16px;color:#1e293b;display:flex;align-items:center;justify-content:center;position:relative;border-bottom:1px solid #e2e8f0;">' +
                '<div style="display:flex;align-items:center;gap:8px;"><i class="material-icons" style="font-size:20px;color:#0284c7;">visibility</i><h2 style="margin:0;font-size:15px;font-weight:800;">Vista previa del Acta de Traslado</h2></div>' +
                '<button type="button" id="mov-prev-x" title="Cerrar" style="position:absolute;right:12px;background:#e2e8f0;border:none;color:#475569;width:28px;height:28px;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;"><i class="material-icons" style="font-size:16px;">close</i></button>' +
            '</div>' +
            '<div id="mov-prev-body" style="flex:1;min-height:0;overflow:auto;background:#475569;"></div>' +
            '<div id="mov-prev-foot" style="padding:10px 16px;display:flex;gap:10px;justify-content:center;border-top:1px solid #e2e8f0;background:white;"></div>' +
        '</div>';
    document.body.appendChild(ov);

    var cardEl = ov.querySelector('#mov-prev-card');
    var bodyEl = ov.querySelector('#mov-prev-body');
    var footEl = ov.querySelector('#mov-prev-foot');

    function revoke() { try { URL.revokeObjectURL(pdfUrl); } catch (_) {} }
    function cerrar() { revoke(); ov.remove(); }

    ov.querySelector('#mov-prev-x').onclick = cerrar;
    ov.onclick = function (e) { if (e.target === ov) cerrar(); };

    // ── Render del PDF en TELÉFONO con PDF.js ───────────────────────────────────
    // Los navegadores móviles no renderizan PDF embebido en <iframe>, así que en
    // teléfono/tablet dibujamos el PDF en <canvas> con PDF.js (mismo enfoque que la
    // vista previa del módulo de inventario). PDF.js se carga del CDN solo la 1ª vez
    // y queda cacheado en window para no recargarlo en aperturas posteriores.
    function esMovilPdf() {
        return window.innerWidth <= 768 || /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
    }
    function ensurePdfJs() {
        if (window.pdfjsLib) return Promise.resolve();
        if (window._equiposPdfJsPromise) return window._equiposPdfJsPromise;
        window._equiposPdfJsPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js';
            s.onload = function () {
                try { window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.worker.min.js'; } catch (e) {}
                resolve();
            };
            s.onerror = function () { window._equiposPdfJsPromise = null; reject(new Error('No se pudo cargar el visor de PDF.')); };
            document.head.appendChild(s);
        });
        return window._equiposPdfJsPromise;
    }
    // Dibuja el blob PDF en el contenedor (una <canvas> por página, ajustada al ancho).
    function renderPdfCanvas(cont, blob) {
        if (!cont || !blob) return Promise.resolve();
        cont.innerHTML = '<div style="color:#cbd5e0;text-align:center;padding:30px;font-size:13px;">Cargando vista previa…</div>';
        return ensurePdfJs()
            .then(function () { return blob.arrayBuffer(); })
            .then(function (buf) { return window.pdfjsLib.getDocument({ data: buf }).promise; })
            .then(function (pdf) {
                cont.innerHTML = '';
                var dpr = window.devicePixelRatio || 1;
                var ancho = cont.clientWidth - 20; // descontar el padding del contenedor
                if (ancho <= 0) ancho = Math.min(window.innerWidth - 40, 900);
                var seq = Promise.resolve();
                for (var i = 1; i <= pdf.numPages; i++) {
                    (function (n) {
                        seq = seq.then(function () {
                            return pdf.getPage(n).then(function (page) {
                                var base = page.getViewport({ scale: 1 });
                                var vp = page.getViewport({ scale: (ancho / base.width) * dpr });
                                var canvas = document.createElement('canvas');
                                canvas.width = vp.width; canvas.height = vp.height;
                                canvas.style.width = '100%'; canvas.style.height = 'auto';
                                canvas.style.display = 'block'; canvas.style.margin = '0 auto 10px';
                                canvas.style.background = '#fff'; canvas.style.boxShadow = '0 1px 6px rgba(0,0,0,0.25)';
                                cont.appendChild(canvas);
                                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                            });
                        });
                    })(i);
                }
                return seq;
            })
            .catch(function (e) {
                cont.innerHTML = '<div style="color:#fecaca;text-align:center;padding:30px;font-size:13px;">No se pudo mostrar la vista previa. ' + (e && e.message ? e.message : '') + '</div>';
            });
    }

    // ── Vista de PREVIEW: iframe (escritorio) / canvas (móvil) + [Editar | Confirmar] ──
    function renderPreview() {
        previewMostrada = true; // ya hubo previa → el "Cancelar" del editor vuelve aquí
        if (cardEl) cardEl.style.maxWidth = '1100px'; // PDF: ancho para leerlo cómodo
        bodyEl.style.background = '#475569';
        if (esMovilPdf()) {
            // TELÉFONO/TABLET: el iframe no muestra PDF → lo dibujamos en <canvas> con PDF.js.
            bodyEl.innerHTML = '<div id="mov-prev-canvas" style="width:100%;min-height:520px;padding:10px;box-sizing:border-box;"></div>';
            renderPdfCanvas(bodyEl.querySelector('#mov-prev-canvas'), pdfBlob);
        } else {
            // ESCRITORIO: visor PDF nativo del navegador en el iframe.
            bodyEl.innerHTML = '<iframe src="' + pdfUrl + '#toolbar=0&navpanes=0&scrollbar=0&view=FitH" style="width:100%;height:72vh;min-height:520px;border:none;background:#fff;" title="Vista previa Acta de Traslado"></iframe>';
        }
        footEl.innerHTML =
            '<button type="button" id="mov-prev-edit" style="padding:9px 16px;border-radius:10px;border:1px solid #e2e8f0;background:#e2e8f0;color:#475569;font-size:13px;font-weight:700;cursor:pointer;"><i class="material-icons" style="font-size:16px;vertical-align:-3px;margin-right:3px;">edit</i>Editar</button>' +
            '<button type="button" id="mov-prev-ok" style="padding:9px 18px;border-radius:10px;border:none;background:#0284c7;color:white;font-size:13px;font-weight:800;cursor:pointer;"><i class="material-icons" style="font-size:16px;vertical-align:-3px;margin-right:3px;">check_circle</i>Confirmar</button>';
        footEl.querySelector('#mov-prev-edit').onclick = abrirEditor;
        footEl.querySelector('#mov-prev-ok').onclick = function () {
            // No se puede registrar sin firmantes válidos cuando el frente no tiene
            // responsables: reabrimos el editor para que complete los datos.
            if (!firmasCompletas()) {
                if (window.showToast) window.showToast('Indica quién revisa y quién aprueba (cargo, nombre y cédula) antes de registrar.', 'error');
                abrirEditor();
                return;
            }
            cerrar();
            if (typeof onConfirm === 'function') onConfirm();
        };
    }

    // input con icono para el editor
    function grpInput(id, label, value, icon, placeholder) {
        return '<div>' +
            '<label for="' + id + '" style="display:block;font-size:10.5px;font-weight:700;color:#64748b;margin-bottom:2px;text-transform:uppercase;letter-spacing:0.3px;">' + label + '</label>' +
            '<div style="display:flex;align-items:center;border:1px solid #e2e8f0;border-radius:8px;background:#fbfcfd;overflow:hidden;">' +
                '<i class="material-icons" style="padding:0 6px;color:#94a3b8;font-size:16px;">' + icon + '</i>' +
                '<input id="' + id + '" value="' + escA(value) + '" placeholder="' + escA(placeholder || '') + '" style="flex:1;border:none;outline:none;padding:9px 8px;font-size:12.5px;background:transparent;text-transform:uppercase;">' +
            '</div>' +
        '</div>';
    }

    // Roles válidos del acta: el rol (ELABORADO/REVISADO/APROBADO/RECIBIDO) es un SELECT
    // cerrado, NO texto libre — antes la gente podía escribir cualquier cosa en "quién
    // elabora / quién aprueba". Cargo · Nombre · Cédula sí quedan libres (varían por persona).
    var ROLES_ACTA = ['ELABORADO:', 'REVISADO:', 'APROBADO:', 'RECIBIDO:'];

    function firmasRowsHtml() {
        var fs = actaState.firmas || [];
        if (!fs.length) {
            return '<p style="margin:0;font-size:12px;color:#94a3b8;font-style:italic;">Sin firmantes. Usa el botón "Firma" para añadir uno.</p>';
        }
        return fs.map(function (f, i) {
            var cur = (f.label || '').trim();
            // Preserva un rol heredado que no esté en la lista estándar (no perder datos viejos),
            // pero a partir de ahora solo se elige de las opciones del select.
            var roleOpts = ROLES_ACTA.slice();
            if (cur && roleOpts.indexOf(cur) === -1) roleOpts.unshift(cur);
            var labelSelect = '<select class="ed-f-label" style="padding:8px 7px;border:1px solid #e2e8f0;border-radius:6px;font-size:11.5px;min-width:0;background:white;cursor:pointer;">' +
                roleOpts.map(function (o) { return '<option value="' + escA(o) + '"' + (o === cur ? ' selected' : '') + '>' + escA(o) + '</option>'; }).join('') +
                '</select>';
            return '<div class="ed-firma-row" data-i="' + i + '" style="display:grid;grid-template-columns:0.7fr 1.7fr 1.3fr 1fr 26px;gap:5px;align-items:center;margin-bottom:4px;">' +
                labelSelect +
                '<input class="ed-f-car" value="' + escA(f.car) + '" placeholder="Cargo" style="padding:8px 7px;border:1px solid #e2e8f0;border-radius:6px;font-size:11.5px;min-width:0;">' +
                '<input class="ed-f-nom" value="' + escA(f.nom) + '" placeholder="Nombre y apellido" style="padding:8px 7px;border:1px solid #e2e8f0;border-radius:6px;font-size:11.5px;min-width:0;">' +
                '<input class="ed-f-ced" value="' + escA(f.ced) + '" placeholder="Cédula" style="padding:8px 7px;border:1px solid #e2e8f0;border-radius:6px;font-size:11.5px;min-width:0;">' +
                '<button type="button" class="ed-firma-del" title="Quitar firma" style="background:#fee2e2;border:none;color:#b91c1c;width:26px;height:26px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;"><i class="material-icons" style="font-size:15px;">close</i></button>' +
            '</div>';
        }).join('');
    }

    // Lee los inputs de firmas del DOM hacia actaState (no perder lo tecleado al
    // re-renderizar tras agregar/quitar una fila).
    function syncFirmasFromDOM() {
        var arr = [];
        bodyEl.querySelectorAll('.ed-firma-row').forEach(function (row) {
            arr.push({
                label: row.querySelector('.ed-f-label').value.trim(),
                car: row.querySelector('.ed-f-car').value.trim(),
                nom: row.querySelector('.ed-f-nom').value.trim(),
                ced: row.querySelector('.ed-f-ced').value.trim()
            });
        });
        actaState.firmas = arr;
    }

    // ¿Las firmas están completas? Obligatorio SOLO cuando el frente de origen no
    // tiene responsables: en ese caso cada firma debe tener cargo, nombre y cédula
    // (si el frente sí tiene responsables, se respeta su data tal cual, sin exigir).
    function firmasCompletas() {
        if (!sinResponsablesOrigen) return true;
        var fs = actaState.firmas || [];
        return fs.length > 0 && fs.every(function (f) {
            return (f.car || '').trim() && (f.nom || '').trim() && (f.ced || '').trim();
        });
    }

    var metaCargada = false;

    // ── Vista de EDICIÓN: formulario + [Cancelar | Aceptar] ──
    async function abrirEditor() {
        // Primera apertura: precargar origen/zona/firmas por defecto desde el backend.
        if (!metaCargada) {
            metaCargada = true;
            try {
                if (window.showPreloader) window.showPreloader();
                var r = await fetch('/admin/movilizaciones/preview-acta-meta', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
                    body: JSON.stringify({ ids: actaState.ids })
                });
                if (r.ok) {
                    var m = await r.json();
                    if (!actaState.origin) actaState.origin = m.origin || '';
                    if (!actaState.origin_zona) actaState.origin_zona = m.origin_zona || '';
                    if (actaState.firmas === null) {
                        actaState.firmas = Array.isArray(m.firmas) ? m.firmas.map(function (f) {
                            return { label: f.label || '', car: f.car || '', nom: f.nom || '', ced: f.ced || '' };
                        }) : [];
                    }
                }
            } catch (_) { }
            finally { if (window.hidePreloader) window.hidePreloader(); }
        }
        if (actaState.firmas === null) actaState.firmas = [];

        // Frente de origen SIN responsables → sembramos las filas que el acta necesita
        // para firmar (REVISADO / APROBADO) con los campos vacíos, para que el usuario
        // solo complete nombre · cargo · cédula.
        if (sinResponsablesOrigen && actaState.firmas.length === 0) {
            actaState.firmas = [
                { label: 'REVISADO:', car: '', nom: '', ced: '' },
                { label: 'APROBADO:', car: '', nom: '', ced: '' }
            ];
        }

        // Aviso en ROJO cuando el frente no tiene responsables.
        var avisoSinResp = sinResponsablesOrigen
            ? '<div style="display:flex;gap:7px;align-items:flex-start;background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;border-radius:9px;padding:7px 10px;font-size:11.5px;font-weight:600;line-height:1.35;">' +
                '<i class="material-icons" style="font-size:16px;flex-shrink:0;">error_outline</i>' +
                '<span>Este frente no tiene responsables. Indica quién <b>revisa</b> y quién <b>aprueba</b> para la firma del acta.</span>' +
              '</div>'
            : '';

        if (cardEl) cardEl.style.maxWidth = '720px'; // formulario: modal más angosto/compacto
        bodyEl.style.background = '#fff';
        bodyEl.innerHTML =
            '<div style="padding:12px 16px;display:flex;flex-direction:column;gap:10px;">' +
                avisoSinResp +
                '<div class="mov-ed-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px 10px;">' +
                    grpInput('ed-origin', 'Frente de origen', actaState.origin, 'place') +
                    grpInput('ed-zona', 'Lugar / zona de origen (ciudad)', actaState.origin_zona, 'location_city', 'Ej: MATURÍN') +
                    grpInput('ed-dest', 'Frente de destino', actaState.destination, 'flag') +
                    grpInput('ed-destubic', 'Ubicación del destino', actaState.destination_ubicacion, 'location_on', 'Ej: CALLE / SECTOR') +
                '</div>' +
                '<div>' +
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">' +
                        '<label style="font-size:11.5px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:0.4px;">Firmas (nombre · cargo · cédula)</label>' +
                        '<button type="button" id="ed-firma-add" style="background:#0284c7;border:none;color:white;font-size:11.5px;font-weight:700;padding:4px 9px;border-radius:6px;cursor:pointer;display:inline-flex;align-items:center;gap:4px;"><i class="material-icons" style="font-size:14px;">add</i>Firma</button>' +
                    '</div>' +
                    '<div id="ed-firmas">' + firmasRowsHtml() + '</div>' +
                '</div>' +
            '</div>';

        footEl.innerHTML =
            '<button type="button" id="mov-ed-cancel" style="padding:9px 16px;border-radius:10px;border:1px solid #e2e8f0;background:#e2e8f0;color:#475569;font-size:13px;font-weight:700;cursor:pointer;">Cancelar</button>' +
            '<button type="button" id="mov-ed-apply" style="padding:9px 18px;border-radius:10px;border:none;background:#0284c7;color:white;font-size:13px;font-weight:800;cursor:pointer;"><i class="material-icons" style="font-size:16px;vertical-align:-3px;margin-right:3px;">check</i>Aceptar</button>';

        // "Cancelar": si ya se mostró la vista previa del PDF, vuelve a ella; si el editor
        // se abrió directo (frente nuevo, sin previa), cierra el acta y regresa al modal de
        // Movilización (que sigue detrás). NUNCA registra ni genera PDF.
        footEl.querySelector('#mov-ed-cancel').onclick = function () {
            if (previewMostrada) renderPreview();
            else cerrar();
        };
        footEl.querySelector('#mov-ed-apply').onclick = aplicarEdicion;

        // Agregar / quitar firma (delegación sobre #ed-firmas, que persiste).
        bodyEl.querySelector('#ed-firma-add').onclick = function () {
            syncFirmasFromDOM();
            actaState.firmas.push({ label: 'APROBADO:', car: '', nom: '', ced: '' }); // rol por defecto válido (el select lo restringe)
            bodyEl.querySelector('#ed-firmas').innerHTML = firmasRowsHtml();
        };
        bodyEl.querySelector('#ed-firmas').addEventListener('click', function (ev) {
            var del = ev.target.closest('.ed-firma-del');
            if (!del) return;
            var idx = parseInt(del.closest('.ed-firma-row').getAttribute('data-i'), 10);
            syncFirmasFromDOM();
            actaState.firmas.splice(idx, 1);
            bodyEl.querySelector('#ed-firmas').innerHTML = firmasRowsHtml();
        });
    }

    async function aplicarEdicion() {
        actaState.origin = bodyEl.querySelector('#ed-origin').value.trim();
        actaState.origin_zona = bodyEl.querySelector('#ed-zona').value.trim();
        actaState.destination = bodyEl.querySelector('#ed-dest').value.trim();
        actaState.destination_ubicacion = bodyEl.querySelector('#ed-destubic').value.trim();
        syncFirmasFromDOM();

        if (!actaState.destination) { if (window.showToast) window.showToast('El frente de destino no puede quedar vacío.', 'error'); return; }
        if (!actaState.ids.length) { if (window.showToast) window.showToast('Debe quedar al menos un equipo.', 'error'); return; }

        // Lugar/zona de origen y Ubicación del destino son OBLIGATORIOS: el acta los
        // imprime en el encabezado y junto al frente de destino. Se resalta en rojo el
        // contenedor del primer campo vacío (el borde vive en el wrapper, no en el input).
        var reqCampos = [
            { sel: '#ed-zona',    vacio: !actaState.origin_zona,          msg: 'El lugar / zona de origen (ciudad) es obligatorio.' },
            { sel: '#ed-destubic', vacio: !actaState.destination_ubicacion, msg: 'La ubicación del destino es obligatoria.' }
        ];
        var faltante = null;
        reqCampos.forEach(function (c) {
            var inp = bodyEl.querySelector(c.sel);
            if (inp && inp.parentElement) inp.parentElement.style.borderColor = c.vacio ? '#ef4444' : '#e2e8f0';
            if (c.vacio && !faltante) faltante = c;
        });
        if (faltante) {
            if (window.showToast) window.showToast(faltante.msg, 'error');
            var fInp = bodyEl.querySelector(faltante.sel);
            if (fInp) fInp.focus();
            return;
        }

        // Firmas OBLIGATORIAS cuando el frente no tiene responsables: cada firma debe
        // llevar cargo, nombre y cédula. Se resalta en rojo el primer campo vacío.
        if (sinResponsablesOrigen) {
            var filas = bodyEl.querySelectorAll('.ed-firma-row');
            if (filas.length === 0) {
                if (window.showToast) window.showToast('Agrega al menos un firmante (quién revisa y quién aprueba).', 'error');
                return;
            }
            var primerVacio = null;
            filas.forEach(function (row) {
                ['.ed-f-car', '.ed-f-nom', '.ed-f-ced'].forEach(function (sel) {
                    var inp = row.querySelector(sel);
                    if (!inp) return;
                    var vacio = inp.value.trim() === '';
                    inp.style.borderColor = vacio ? '#ef4444' : '#e2e8f0';
                    if (vacio && !primerVacio) primerVacio = inp;
                });
            });
            if (primerVacio) {
                if (window.showToast) window.showToast('Completa cargo, nombre y cédula de cada firma.', 'error');
                primerVacio.focus();
                return;
            }
        }

        if (window.showPreloader) window.showPreloader();
        try {
            revoke();
            pdfUrl = await pedirPreview();
            renderPreview();
        } catch (e) {
            if (window.showToast) window.showToast(e.message || 'No se pudo actualizar la vista previa.', 'error');
        } finally {
            if (window.hidePreloader) window.hidePreloader();
        }
    }

    if (editarDirecto) {
        // Frente nuevo/sin ubicación: abrir el FORMULARIO de una (sin previa primero), para
        // capturar ubicación + firmas. Si el frente de ORIGEN no tiene responsables,
        // sembramos las filas de firma igual que en el flujo normal.
        if (ultimaFirmasCount === 0) sinResponsablesOrigen = true;
        abrirEditor();
    } else {
        renderPreview();

        // Si el frente de ORIGEN no tiene responsables (0 firmas efectivas) y el usuario
        // aún no las editó, abrimos el editor automáticamente con las filas REVISADO /
        // APROBADO para que complete los datos que faltan — de forma proactiva, sin que
        // tenga que descubrir que el acta saldría sin bloque de firma.
        if (ultimaFirmasCount === 0 && actaState.firmas === null) {
            sinResponsablesOrigen = true;
            abrirEditor();
        }
    }
};

window.openAnchorModal = async function (event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    // Permiso: anclar/desanclar es parte del flujo de asignacion. El backend
    // (routes/web.php) valida lo mismo con middleware can:equipos.assign;
    // este guard solo evita que el usuario vea un modal inutil.
    if (window.CAN_ASSIGN_EQUIPOS === false || window.CAN_ASSIGN_EQUIPOS === 'false') {
        if (typeof window.showToast === 'function') {
            window.showToast('No tienes permiso para anclar equipos.', 'error');
        } else if (typeof window.showModal === 'function') {
            window.showModal({ type: 'error', title: 'Acceso Denegado', message: 'No tienes permiso para anclar equipos.', confirmText: 'Entendido', hideCancel: true });
        }
        return;
    }

    const selections = Object.entries(window.selectedEquipos);
    if (selections.length === 0) return;

    // Tomar el último seleccionado, o fallback al primero válido
    let sourceId = window.lastSelectedEquipoId;
    let sourceData = window.selectedEquipos[sourceId];

    if (!sourceData || (sourceData.rolAnclaje !== 'REMOLCADOR' && sourceData.rolAnclaje !== 'REMOLCABLE')) {
        const valid = selections.find(([id, data]) => data.rolAnclaje === 'REMOLCADOR' || data.rolAnclaje === 'REMOLCABLE');
        if (valid) {
            sourceId = valid[0];
            sourceData = valid[1];
        } else {
            return;
        }
    }
    const firstFrenteId = sourceData.frenteId;
    const sourceRole = sourceData.rolAnclaje;

    if (!firstFrenteId || firstFrenteId === "null") {
        window.showModal({
            type: "warning",
            title: "Frente no Asignado",
            message: "Los equipos seleccionados no tienen un frente asignado.",
            confirmText: "Entendido",
            hideCancel: true,
        });
        return;
    }

    // Modal Construction
    const oldModals = document.querySelectorAll(".dynamic-anchor-modal");
    oldModals.forEach((el) => el.remove());

    const overlay = document.createElement("div");
    overlay.className = "dynamic-anchor-modal";
    overlay.style.cssText =
        "position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.5); z-index:2500; display:flex; justify-content:center; align-items:center; backdrop-filter:blur(2px);";

    const content = document.createElement("div");
    content.style.cssText =
        "background:white; border-radius:16px; width:90%; max-width:440px; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);";

    content.innerHTML = `
        <div style="background:#1e293b; padding:18px; color:white; display:flex; justify-content:center; align-items:center; position:relative;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i class="material-icons" style="color:#10b981; font-size:20px;">anchor</i>
                <h2 style="margin:0; font-size:16px; font-weight:700;">Anclaje de Equipos</h2>
            </div>
            <button type="button" id="btnCloseAnchor" style="position:absolute; right:15px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                <i class="material-icons">close</i>
            </button>
        </div>
        <div style="padding:20px;">
            <!-- Buscador -->
            <div style="display:flex; align-items:center; border:1.5px solid #e2e8f0; border-radius:10px; background:white; overflow:hidden; margin-bottom:12px; transition:border-color 0.2s;" id="anchor-search-box">
                <i class="material-icons" style="padding:0 10px; color:#94a3b8; font-size:18px; flex-shrink:0;">search</i>
                <input type="text" id="anchorSearchInput"
                    placeholder="Buscar por tipo, marca, serial..."
                    autocomplete="off"
                    style="flex:1; border:none; outline:none; padding:9px 6px; font-size:13px; background:transparent;">
                <i class="material-icons" id="anchorSearchClear" style="padding:0 10px; color:#94a3b8; font-size:16px; cursor:pointer; display:none;">close</i>
            </div>
            <div id="anchorEquiposList" style="max-height:360px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:12px; margin-bottom:20px; background:#f8fafc; padding:8px;">
                <div style="padding:20px; text-align:center;"><i class="material-icons spin">sync</i> Cargando equipos...</div>
            </div>
            <button id="btnConfirmAnchor" disabled style="width:100%; height:46px; border-radius:12px; font-weight:700; font-size:14px; background:#10b981; color:white; border:none; display:flex; align-items:center; justify-content:center; gap:8px; opacity:0.5; cursor:not-allowed; transition:all 0.2s;">
                <i class="material-icons">check_circle</i> Confirmar Anclaje
            </button>
        </div>
    `;

    overlay.appendChild(content);
    document.body.appendChild(overlay);

    overlay.querySelector("#btnCloseAnchor").onclick = () => overlay.remove();

    // Fetch Equipos del frente actual (carga inicial)
    const baseUrl = `/admin/equipos/get-equipos-by-frente?id_frente=${firstFrenteId}&source_role=${sourceRole}`;

    const listContainer = content.querySelector('#anchorEquiposList');
    const anchorSearchInput = content.querySelector('#anchorSearchInput');
    const anchorSearchClear = content.querySelector('#anchorSearchClear');
    const anchorSearchBox = content.querySelector('#anchor-search-box');
    const selectedIds = selections.map((s) => String(s[0]));

    // ── Helper: renderiza items en el contenedor ──
    function renderAnchorItems(equipos) {
        listContainer.innerHTML = '';
        if (!equipos || equipos.length === 0) {
            listContainer.innerHTML = '<div style="padding:40px 20px; text-align:center; color:#94a3b8;"><i class="material-icons" style="font-size:32px; display:block; margin: 0 auto 10px;">search_off</i>Sin resultados</div>';
            return;
        }
        equipos.forEach((eq) => {
            const isSelected = selectedIds.includes(String(eq.ID_EQUIPO));
            const item = document.createElement('div');
            item.className = 'anchor-option-item';
            item.style.cssText = `padding:10px; border-radius:8px; background:white; border:1px solid #e2e8f0; margin-bottom:6px; cursor:${isSelected ? 'not-allowed' : 'pointer'}; opacity:${isSelected ? '0.6' : '1'}; display:flex; align-items:center; gap:12px; transition:all 0.2s; position:relative;`;

            if (!isSelected) {
                item.onmouseover = () => { if (!item.dataset.selected) item.style.borderColor = '#10b981'; item.style.boxShadow = '0 4px 6px -1px rgba(0,0,0,0.05)'; };
                item.onmouseout = () => { if (!item.dataset.selected) item.style.borderColor = '#e2e8f0'; item.style.boxShadow = 'none'; };
                item.onclick = () => {
                    content.querySelectorAll('.anchor-option-item').forEach((el) => {
                        el.style.borderColor = '#e2e8f0';
                        el.style.background = 'white';
                        el.dataset.selected = '';
                        el.querySelector('.check-mark').style.display = 'none';
                    });
                    item.style.borderColor = '#10b981';
                    item.style.background = '#f0fdf4';
                    item.dataset.selected = 'true';
                    item.querySelector('.check-mark').style.display = 'block';
                    window.selectedMasterId = eq.ID_EQUIPO;
                    const btn = content.querySelector('#btnConfirmAnchor');
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                };
            }

            // Foto
            let fotoHtml = '';
            if (eq.FOTO) {
                const driveId = eq.FOTO.replace(/^.*\/storage\/google\//, '').split('?')[0];
                fotoHtml = `<img src="/storage/google/${driveId}" style="width:100%; height:100%; object-fit:cover;">`;
            } else {
                fotoHtml = `<i class="material-icons" style="font-size:20px; color:#cbd5e0;">image_not_supported</i>`;
            }

            // Badge de frente distinto (solo aparece en búsqueda global)
            const frenteBadge = eq.ES_FRENTE_DISTINTO && eq.FRENTE_NOMBRE
                ? `<div style="font-size:10px; color:#f97316; font-weight:700; display:flex; align-items:center; gap:2px; margin-top:2px;"><i class="material-icons" style="font-size:10px;">location_on</i>${eq.FRENTE_NOMBRE}</div>`
                : '';

            item.innerHTML = `
                <div style="width:40px; height:40px; background:#f1f5f9; border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0;">${fotoHtml}</div>
                <div style="flex:1; min-width:0; display:flex; flex-direction:column; gap:2px;">
                    <span style="font-weight:800; font-size:13px; color:#1e293b; text-transform:uppercase; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${eq.TIPO_NOMBRE || 'S/TIPO'}</span>
                    <div style="font-size:11px; color:#475569; font-weight:600;">${eq.MARCA}</div>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:1px;">
                        <span style="font-size:10px; color:#64748b; display:flex; align-items:center; gap:2px;"><i class="material-icons" style="font-size:10px;">fingerprint</i>${eq.SERIAL_CHASIS || 'S/S'}</span>
                        ${eq.PLACA ? `<span style="font-size:10px; color:#0067b1; font-weight:700; display:flex; align-items:center; gap:2px;"><i class="material-icons" style="font-size:10px;">featured_play_list</i>${eq.PLACA}</span>` : ''}
                    </div>
                    ${frenteBadge}
                </div>
                <div class="check-mark" style="display:none; color:#10b981;"><i class="material-icons" style="font-size:20px;">check_circle</i></div>
                ${isSelected ? `<i class="material-icons" style="color:#cbd5e0; font-size:20px; margin-left:auto;">lock</i>` : ''}
            `;
            listContainer.appendChild(item);
        });
    }

    // ── Carga inicial: equipos del mismo frente ──
    try {
        const response = await fetch(baseUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const equipos = await response.json();

        if (equipos.length === 0) {
            listContainer.innerHTML = '<div style="padding:40px 20px; text-align:center; color:#94a3b8;"><i class="material-icons" style="font-size:32px; display:block; margin: 0 auto 10px;">assignment_late</i>No existe equipos disponibles en este frente</div>';
        } else {
            renderAnchorItems(equipos);
        }

        // ── Búsqueda server-side con debounce ──
        let searchTimer = null;
        anchorSearchInput.addEventListener('input', () => {
            const val = anchorSearchInput.value.trim();
            anchorSearchClear.style.display = val ? 'block' : 'none';
            anchorSearchBox.style.borderColor = val ? '#10b981' : '#e2e8f0';
            clearTimeout(searchTimer);

            if (!val) {
                // Sin búsqueda: restaurar lista del frente
                renderAnchorItems(equipos);
                return;
            }

            // Mostrar spinner mientras busca
            listContainer.innerHTML = '<div style="padding:20px; text-align:center; color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite; font-size:24px;">sync</i></div>';

            searchTimer = setTimeout(async () => {
                try {
                    const url = `${baseUrl}&search=${encodeURIComponent(val)}`;
                    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                    const result = await res.json();
                    renderAnchorItems(result);
                } catch (e) {
                    listContainer.innerHTML = '<div style="padding:20px; text-align:center; color:#ef4444; font-size:13px;">Error buscando equipos</div>';
                }
            }, 400);
        });

        anchorSearchClear.addEventListener('click', () => {
            anchorSearchInput.value = '';
            anchorSearchClear.style.display = 'none';
            anchorSearchBox.style.borderColor = '#e2e8f0';
            renderAnchorItems(equipos);
            anchorSearchInput.focus();
        });


    } catch (error) {
        console.error(error);
        overlay.remove();
    }

    content.querySelector("#btnConfirmAnchor").onclick = async function () {
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons spin">sync</i> Procesando...';

        try {
            const response = await fetch("/admin/equipos/bulk-anchor", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Accept": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-TOKEN": window.getCsrf(),
                },
                body: JSON.stringify({
                    ids: selections.map((s) => s[0]),
                    master_id: window.selectedMasterId,
                }),
            });

            // 403 permiso denegado: cerramos modal y mostramos toast.
            // Sin este guard, response.json() explotaba con "Unexpected token '<'"
            // cuando el servidor devolvia HTML de Symfony en lugar de JSON.
            if (response.status === 403) {
                let msg = 'No tienes permiso para anclar equipos.';
                try { const body = await response.json(); if (body && body.message) msg = body.message; } catch (_) {}
                overlay.remove();
                if (typeof window.showToast === 'function') window.showToast(msg, 'error');
                else if (typeof window.showModal === 'function') window.showModal({ type: 'error', title: 'Acceso Denegado', message: msg, confirmText: 'Entendido', hideCancel: true });
                return;
            }

            // Cualquier otro error HTTP: leer body o mostrar mensaje genérico
            if (!response.ok) {
                let msg = 'Error del servidor (' + response.status + ').';
                try { const body = await response.json(); if (body && (body.message || body.error)) msg = body.message || body.error; } catch (_) {}
                if (typeof window.showModal === 'function') window.showModal({ type: 'error', title: 'Error', message: msg, confirmText: 'Cerrar', hideCancel: true });
                return;
            }

            const data = await response.json();
            if (data.success) {
                overlay.remove();
                window.clearSelection();
                window.loadEquipos();
                if (typeof window.showToast === 'function') {
                    window.showToast(data.message || 'Equipos anclados mutuamente con éxito', 'success');
                }
            } else {
                window.showModal({ type: 'error', title: 'Error', message: data.error || 'Error al anclar equipos.', confirmText: 'Cerrar', hideCancel: true });
            }
        } catch (error) {
            console.error('[bulkAnchor]', error);
            if (typeof window.showToast === 'function') window.showToast('Error de red al anclar equipos.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML =
                '<i class="material-icons">save</i> Confirmar Anclaje';
        }
    };
};

window.updateLocalStats = function (oldStatus, newStatus) {
    // El Consolidado muestra: Total · Operativo · Inoperativo (Mantenimiento ya
    // no se lista). Ajustamos en caliente Operativo/Inoperativo tanto en la vista
    // de escritorio (stats_*) como en las pills móviles (mobile_stats_*). El Total
    // no cambia con un simple cambio de estatus (mantenimiento sigue contando en
    // total); solo se recalcula al refrescar desde el servidor.
    const elOper = document.getElementById("stats_activos");
    const elInop = document.getElementById("stats_inactivos");

    const adjust = (el, amount) => {
        if (el) {
            let val = parseInt(el.textContent.replace(/\D/g, "")) || 0;
            val += amount;
            el.textContent = val < 0 ? 0 : val;
        }
    };
    const adjustMirror = (mobileId, amount) => adjust(document.getElementById(mobileId), amount);

    // Restar del estatus anterior
    if (oldStatus === "OPERATIVO") { adjust(elOper, -1); adjustMirror("mobile_stats_activos", -1); }
    if (oldStatus === "INOPERATIVO" || oldStatus === "DESINCORPORADO") { adjust(elInop, -1); adjustMirror("mobile_stats_inactivos", -1); }

    // Sumar al nuevo estatus
    if (newStatus === "OPERATIVO") { adjust(elOper, 1); adjustMirror("mobile_stats_activos", 1); }
    if (newStatus === "INOPERATIVO" || newStatus === "DESINCORPORADO") { adjust(elInop, 1); adjustMirror("mobile_stats_inactivos", 1); }
};

window.exportEquipos = function () {
    // Modo aux (se eligio un tipo AUXILIAR): exportar AUXILIARES, no equipos. Reusa la
    // exportacion del modulo de auxiliares (equipos-auxiliares/export) con el tipo +
    // frente + busqueda actuales. Descarga directa (el server responde el xlsx adjunto).
    const _tipoAuxEl = document.querySelector('input[name="id_tipo"]');
    const _tipoAuxVal = ((_tipoAuxEl && _tipoAuxEl.value) || '').trim();
    if (_tipoAuxVal.indexOf('tipo_aux:') === 0) {
        const ap = new URLSearchParams();
        ap.append('tipo', _tipoAuxVal.slice(9));
        const _frAux = ((document.querySelector('input[name="id_frente"]') || {}).value || '').trim();
        if (_frAux) ap.append('id_frente', _frAux);
        const _sAux = ((document.getElementById('searchInput') || {}).value || '').trim();
        if (_sAux) ap.append('search', _sAux);
        if (window.showPreloader) window.showPreloader();
        window.location.href = '/admin/equipos-auxiliares/export?' + ap.toString();
        setTimeout(function () { if (window.hidePreloader) window.hidePreloader(); }, 1500);
        return;
    }

    const searchInput = document.getElementById("searchInput");
    const frenteInput = document.querySelector('input[name="id_frente"]');
    const tipoInput = document.querySelector('input[name="id_tipo"]');
    const advancedPanel = document.getElementById("advancedFilterPanel");

    // Prioritize inputs within the Advanced Filter Panel if it exists
    const modeloInput = advancedPanel
        ? advancedPanel.querySelector('input[name="modelo"]')
        : document.querySelector('input[name="modelo"]');
    const anioInput = advancedPanel
        ? advancedPanel.querySelector('input[name="anio"]')
        : document.querySelector('input[name="anio"]');
    const marcaInput = advancedPanel
        ? advancedPanel.querySelector('input[name="marca"]')
        : document.querySelector('input[name="marca"]');
    const detalleUbicacionInput = advancedPanel
        ? advancedPanel.querySelector('input[name="detalle_ubicacion"]')
        : document.querySelector('input[name="detalle_ubicacion"]');
    const categoriaInput = advancedPanel
        ? advancedPanel.querySelector('input[name="categoria"]')
        : document.querySelector('input[name="categoria"]');
    const estadoInput = advancedPanel
        ? (advancedPanel.querySelector('input[name="estado"]') || document.querySelector('input[name="estado"]'))
        : document.querySelector('input[name="estado"]');
    const colorInput = advancedPanel
        ? advancedPanel.querySelector('input[name="color"]')
        : document.querySelector('input[name="color"]');
    const confirmadoInput = advancedPanel
        ? advancedPanel.querySelector('input[name="confirmado"]')
        : document.querySelector('input[name="confirmado"]');

    const params = new URLSearchParams();

    // Helper to append if valid
    const appendIfValid = (key, value) => {
        if (
            value &&
            typeof value === "string" &&
            value.trim() !== "" &&
            value.trim() !== "all"
        ) {
            params.append(key, value.trim());
            return true;
        }
        return false;
    };

    // Track if we have any filter
    let hasAnyFilter = false;

    hasAnyFilter |= appendIfValid("search_query", searchInput?.value);

    // id_frente: 'all' es un filtro explícito válido (Todos los Frentes)
    const frenteVal = frenteInput?.value?.trim();
    if (frenteVal === "all") {
        params.append("id_frente", "all");
        hasAnyFilter = true;
    } else {
        hasAnyFilter |= appendIfValid("id_frente", frenteVal);
    }

    hasAnyFilter |= appendIfValid("id_tipo", tipoInput?.value);
    hasAnyFilter |= appendIfValid("modelo", modeloInput?.value);
    hasAnyFilter |= appendIfValid("marca", marcaInput?.value);
    hasAnyFilter |= appendIfValid("detalle_ubicacion", detalleUbicacionInput?.value);
    hasAnyFilter |= appendIfValid("anio", anioInput?.value);
    hasAnyFilter |= appendIfValid("categoria", categoriaInput?.value);
    hasAnyFilter |= appendIfValid("estado", estadoInput?.value);
    hasAnyFilter |= appendIfValid("color", colorInput?.value);
    hasAnyFilter |= appendIfValid("confirmado", confirmadoInput?.value);

    // Documentation Boolean Filters
    if (document.getElementById("chk_propiedad")?.checked) {
        params.append("filter_propiedad", "true");
        hasAnyFilter = true;
    }
    if (document.getElementById("chk_poliza")?.checked) {
        params.append("filter_poliza", "true");
        hasAnyFilter = true;
    }
    if (document.getElementById("chk_rotc")?.checked) {
        params.append("filter_rotc", "true");
        hasAnyFilter = true;
    }
    if (document.getElementById("chk_racda")?.checked) {
        params.append("filter_racda", "true");
        hasAnyFilter = true;
    }
    if (document.getElementById("chk_adicional")?.checked) {
        params.append("filter_adicional", "true");
        hasAnyFilter = true;
    }
    if (document.getElementById("chk_adicional_2")?.checked) {
        params.append("filter_adicional_2", "true");
        hasAnyFilter = true;
    }

    // Validate: At least one filter must be active
    if (!hasAnyFilter) {
        window.showModal({
            type: "warning",
            title: "Filtro Requerido",
            message:
                "Debe aplicar al menos un filtro antes de exportar datos. Esto previene la descarga masiva de toda la base de datos.",
            confirmText: "Entendido",
            hideCancel: true,
        });
        return;
    }

    // Descargar vía fetch → blob (NO form.submit()): así se puede MOSTRAR el spinner
    // mientras el backend genera el Excel y ocultarlo al terminar. Un form.submit()
    // descarga en segundo plano sin avisar cuándo acaba, por eso no salía el spinner.
    const url = '/admin/equipos/export?' + params.toString();
    if (window.showPreloader) window.showPreloader();

    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var d = new Date();
    var fname = 'Listado_Maquinarias_Equipos_' + d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + '_' + pad(d.getHours()) + '-' + pad(d.getMinutes()) + '.xlsx';

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
        credentials: 'same-origin'
    })
        .then(function (r) {
            if (!r.ok) throw new Error('No se pudo generar el Excel.');
            // Si el backend respondió HTML (p.ej. redirect por falta de filtro) en vez del
            // archivo, no lo descargamos como .xlsx corrupto.
            var ct = (r.headers.get('Content-Type') || '').toLowerCase();
            if (ct.indexOf('spreadsheet') === -1 && ct.indexOf('octet-stream') === -1) {
                throw new Error('Aplica al menos un filtro antes de exportar.');
            }
            return r.blob();
        })
        .then(function (blob) {
            var burl = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = burl;
            a.download = fname;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(function () { try { URL.revokeObjectURL(burl); } catch (e) {} }, 1500);
        })
        .catch(function (e) {
            if (window.showToast) window.showToast(e.message || 'Error al exportar el Excel.', 'error');
        })
        .finally(function () {
            if (window.hidePreloader) window.hidePreloader();
        });
};

function initEquipos() {
    if (!document.getElementById("equiposTableBody")) return;

    // En movil, al disparar la busqueda hay que cerrar el teclado virtual:
    // el browser solo lo oculta cuando el input pierde el foco. Ejecutamos
    // blur() solo en viewports angostos para no romper el flujo en desktop.
    const _hideMobileKeyboard = (el) => {
        if (!el) return;
        if (window.matchMedia && window.matchMedia('(max-width: 768px)').matches) {
            el.blur();
        }
    };

    const searchInput = document.getElementById("searchInput");
    // Guard: only attach listener once per DOM instance
    if (searchInput && !searchInput.dataset.equiposInitialized) {
        searchInput.dataset.equiposInitialized = 'true';
        searchInput.addEventListener("keyup", function (e) {
            const val = this.value;
            const clearBtn = document.getElementById("btn_clear_search");
            if (clearBtn)
                clearBtn.style.display = val.length > 0 ? "block" : "none";

            clearTimeout(window.searchTimeout);
            if (val.length >= 4 || val.length === 0) {
                const self = this;
                window.searchTimeout = setTimeout(() => {
                    window.loadEquipos();
                    _hideMobileKeyboard(self);
                }, 1000);
            }
            if (e.key === 'Enter') {
                clearTimeout(window.searchTimeout);
                window.loadEquipos();
                _hideMobileKeyboard(this);
            }
        });
    }

    const form = document.getElementById("search-form");
    if (form) {
        form.onsubmit = function (e) {
            e.preventDefault();
            window.loadEquipos();
            _hideMobileKeyboard(document.getElementById("searchInput"));
            return false;
        };
    }

    updateSelectionUI();
}

// window-level listeners — UNA sola vez aunque el script se re-ejecute en SPA
if (!window._equiposWindowListenersReady) {
    window._equiposWindowListenersReady = true;

    // Reinicializar módulo al navegar A equipos, limpiar al salir
    window.addEventListener("spa:contentLoaded", function () {
        const isOnEquiposPage = document.getElementById("equiposTableBody") !== null;
        if (isOnEquiposPage) {
            initEquipos();
        } else if (window.selectedEquipos && Object.keys(window.selectedEquipos).length > 0) {
            window.selectedEquipos = {};
            updateSelectionUI();
        }
    });

    // Destacar botón de filtros avanzados al seleccionar un valor de dropdown
    window.addEventListener("dropdown-selection", function (e) {
        if (!document.getElementById("equiposTableBody")) return;
        const advBtn = document.getElementById("btnAdvancedFilter");
        if (advBtn && e.detail.value) {
            advBtn.style.background = "#e1effa";
            advBtn.style.color = "#0067b1";
            advBtn.style.border = "1px solid #0067b1";
        }
    });

    // Cerrar custom-dropdowns y el menú de Acciones al hacer clic fuera
    document.addEventListener("click", function(e) {
        // 1. Cerrar custom dropdowns (Filtros Avanzados)
        if (!e.target.closest('.custom-dropdown')) {
            document.querySelectorAll('.custom-dropdown.active').forEach(function(dd) {
                dd.classList.remove('active');
            });
        }

        // 2. Manejar splitDropdownMenu
        const splitMenu = document.getElementById('splitDropdownMenu');

        if (splitMenu) {
            if (!e.target.closest('#splitDropdownMenu') && !e.target.closest('#btnAcciones')) {
                // Clic fuera del menú lo cierra
                splitMenu.style.display = 'none';
            }
        }

        // 3. Manejar advancedFilterPanel
        const advPanel = document.getElementById('advancedFilterPanel');
        if (advPanel && advPanel.style.display === 'block') {
            if (!e.target.closest('#advancedFilterPanel') && !e.target.closest('#btnAdvancedFilter')) {
                advPanel.style.display = 'none';
            }
        }
    });
}

// ==========================================
// FLEET DASHBOARD LOGIC
// NOTE: openFleetDashboard / closeFleetDashboard / loadFleetDashboardData
// are defined in fleet_dashboard.js (authoritative source).
// ==========================================

// Permission Handler for Create Action
window.handleCreateCheck = function (event) {
    // 1. Check Permission
    if (
        typeof window.CAN_CREATE_INFO !== "undefined" &&
        window.CAN_CREATE_INFO === false
    ) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }

        // Notificacion moderna (toast) en lugar del modal bloqueante.
        // Patron consistente con el resto de validaciones de permiso del
        // modulo (/admin/equipos movilizar, anclar, ubicar, etc).
        if (typeof window.showToast === 'function') {
            window.showToast('No tienes permisos para crear nuevos equipos.', 'error');
        }
        return false;
    }

    // 2. SPA Navigation via navigateTo (spinner SPA)
    if (window.CREATE_URL) {
        if (typeof window.navigateTo === 'function') {
            if (event) { event.preventDefault(); event.stopPropagation(); }
            window.navigateTo(window.CREATE_URL);
        } else {
            // Fallback si el router SPA no está disponible aún
            window.location.href = window.CREATE_URL;
        }
    }
    return true;
};


// [End of dashboard cleanup]

// NOTA: NO registramos 'equipos' con ModuleManager.
// El módulo tiene su PROPIO listener 'spa:contentLoaded' (línea ~1579)
// protegido con _equiposWindowListenersReady. Si además se registrara en
// ModuleManager (que también escucha spa:contentLoaded), initEquipos() se
// llamaría DOBLE en cada navegación SPA → doble fetch + doble updateSelectionUI.

// Direct init en carga inicial (hard-refresh).
// En SPA nav, el listener spa:contentLoaded (línea ~1579) se encarga → no duplicar.
// Usamos _equiposSpaReady para distinguir: si ya se registró el listener SPA,
// significa que el script ya corrió una vez y esta re-ejecución es por SPA → omitir.
if (!window._equiposSpaReady) {
    // Primera ejecución real (hard-refresh o carga directa)
    window._equiposSpaReady = true;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEquipos);
    } else {
        initEquipos();
    }
}
