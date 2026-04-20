// equipos_index.js - Equipos Module Logic
// Version: 2.2 - Global Selection & CSP Fixes

// Use window to ensure persistent state across SPA reloads if the script is re-executed
window.selectedEquipos = window.selectedEquipos || {};

// Global Status Dropdown Logic
window.toggleStatusDropdown = function (trigger) {
    if (!trigger) return;

    // PERMISSION CHECK
    if (
        typeof window.CAN_CHANGE_STATUS !== "undefined" &&
        window.CAN_CHANGE_STATUS === false
    ) {
        if (window.showModal) {
            window.showModal({
                type: "error",
                title: "Acceso Denegado",
                message: "No tienes permisos para cambiar el estatus.",
                confirmText: "Entendido",
                hideCancel: true,
            });
        }
        return;
    }

    document.querySelectorAll(".status-dropdown-menu").forEach((menu) => {
        if (menu.previousElementSibling !== trigger) {
            menu.style.display = "none";
        }
    });

    const menu = trigger.nextElementSibling;
    if (menu) {
        const isHidden =
            menu.style.display === "none" || menu.style.display === "";
        menu.style.display = isHidden ? "block" : "none";
    }
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

            // ── Anclar button ──
            const anchorBtn = document.getElementById('btnAnclar');
            if (anchorBtn) {
                const canAnchor =
                    selections.length === 1 &&
                    (selections[0].rolAnclaje === 'REMOLCADOR' ||
                        selections[0].rolAnclaje === 'REMOLCABLE');
                anchorBtn.style.display = canAnchor ? 'flex' : 'none';
            }

            // ── Desanclar button ──
            const unanchorBtn = document.getElementById('btnUnanchor');
            if (unanchorBtn) {
                let canUnanchor = false;
                const isValidId = (val) => val && val !== "null" && val !== "";
                
                if (selections.length === 1 && isValidId(selections[0].anchorId)) {
                    canUnanchor = true;
                } else if (selections.length === 2) {
                    const s1 = selections[0];
                    const s2 = selections[1];
                    if (
                        (isValidId(s1.anchorId) && String(s1.anchorId) === String(s2.id)) || 
                        (isValidId(s2.anchorId) && String(s2.anchorId) === String(s1.id))
                    ) {
                        canUnanchor = true;
                    }
                }
                unanchorBtn.style.display = canUnanchor ? 'flex' : 'none';
            }

        } else {
            bar.classList.remove("active");
        }
    }
}

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
                frenteId: targetFrente,
                rolAnclaje: targetRol,
                anchorId: targetAnchorId,
            };
            if (targetRow) targetRow.classList.add("selected-row-maquinaria");
        } else {
            delete window.selectedEquipos[targetId];
            if (targetRow)
                targetRow.classList.remove("selected-row-maquinaria");
        }
    };

    // Toggle main equipment
    toggleSelection(id, code, placa, chasis, frenteId, rolAnclaje, anchorId, row);

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

        toggleSelection(
            anchorId,
            partnerCode || (partnerBtn ? partnerBtn.dataset.codigo : ""),
            partnerPlaca || (partnerBtn ? partnerBtn.dataset.placa : ""),
            partnerSerial || (partnerBtn ? partnerBtn.dataset.chasis : ""),
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

// Global Event Listeners (Always attach via delegation - safe to re-run)
// NOTE: Event delegation to document allows these to work even after DOM changes
document.addEventListener("click", handleRowClick);

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
            const token = document.querySelector('meta[name="csrf-token"]').content;
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
            message: '¿Estás seguro que deseas desanclar los equipos seleccionados? Se separarán de forma permanente.',
            confirmText: 'Sí, desanclar',
            cancelText: 'Cancelar',
            onConfirm: executeUnanchor
        });
    } else {
        if (confirm('¿Estás seguro que deseas desanclar los equipos seleccionados?')) {
            executeUnanchor();
        }
    }
};


document.addEventListener("click", function (e) {
    // Close status dropdowns when clicking outside
    if (!e.target.closest(".custom-dropdown")) {
        document.querySelectorAll(".status-dropdown-menu").forEach((menu) => {
            menu.style.display = "none";
        });
    }

    // 1. Identify specific clear actions
    // (Search, Filter etc are handled by global selectors or inline)

    // 2. Clear Advanced Filters Button
    const clearBtn = e.target.closest('[data-action="clear-advanced-filters"]');
    if (clearBtn) {
        e.preventDefault();
        e.stopPropagation();
        window.clearAdvancedFilters();
        return;
    }

    // 3. Clear Specific Filter (Generic)
    const clearSpecific = e.target.closest("[data-clear-target]");
    if (clearSpecific) {
        e.preventDefault();
        e.stopPropagation();
        const target = clearSpecific.dataset.clearTarget; // 'id_frente' or 'modelo' etc

        // All filters now use selectAdvancedFilter
        window.selectAdvancedFilter(target, "");
    }
});

window.enlargeImage = function (src) {
    const overlay = document.getElementById("imageOverlay");
    const img = document.getElementById("enlargedImg");
    if (!overlay || !img) return;
    img.src = src;
    overlay.style.display = "flex";
};

window.toggleDocFilter = function (type) {
    window.loadEquipos();
};

window.filterByStatus = function (status) {
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

window.loadEquipos = function (url = null, silent = false) {
    const tableBody = document.getElementById("equiposTableBody");
    if (!tableBody) return Promise.resolve();

    let baseUrl = url || window.location.pathname;
    const searchInput = document.getElementById("searchInput");
    const frenteInput = document.querySelector('input[name="id_frente"]');
    const tipoInput = document.querySelector('input[name="id_tipo"]');
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
        anio: getVal('input[name="anio"]', advancedPanel || document),
        categoria: getVal('input[name="categoria"]', advancedPanel || document),
        estado: getVal('input[name="estado"]', advancedPanel || document),
        gps: getVal('input[name="gps"]', advancedPanel || document),
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
    };

    const params = new URLSearchParams();

    // Lógica dinámica para poner ROJO el botón de Filtros Avanzados si hay alguno activo
    const btnAdv = document.getElementById('btnAdvancedFilter');
    if (btnAdv) {
        const hasAdv = !!(filters.modelo || filters.marca || filters.anio || filters.categoria || filters.estado || filters.gps || filters.filter_propiedad || filters.filter_poliza || filters.filter_rotc || filters.filter_racda);
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

    // NOTE: reApplySelections() is NOT called here because the table
    // shows a "no filters" message with no real rows to highlight.

    const paramStr = params.toString();
    const finalUrl = paramStr
        ? baseUrl + (baseUrl.includes('?') ? '&' : '?') + paramStr
        : baseUrl;
    tableBody.style.opacity = "0.5";

    if (!silent && window.showPreloader) window.showPreloader();

    return fetch(finalUrl, {
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
            "Cache-Control": "no-cache, no-store, must-revalidate",
            "Pragma": "no-cache",
        },
    })
        .then((response) => {
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

            tableBody.innerHTML = data.html;
            tableBody.style.opacity = "1";

            // Re-apply blue highlight to all previously selected rows
            // Uses dedicated function with String() cast to avoid int/string key mismatches
            reApplySelections();

            const paginationContainer =
                document.getElementById("equiposPagination");
            if (paginationContainer) paginationContainer.innerHTML = "";

            // Elementos del DOM para stats (desktop + móvil)
            const statsTotal        = document.getElementById('stats_total');
            const statsInactivos    = document.getElementById('stats_inactivos');
            const statsMantenimiento = document.getElementById('stats_mantenimiento');

            // Show '--' when there are no active filters
            const hasActiveFilters = !!paramStr;
            const displayStat = (val) => hasActiveFilters ? val : '--';

            if (statsTotal)         statsTotal.textContent         = displayStat(data.stats.total);
            if (statsInactivos)     statsInactivos.textContent     = displayStat(data.stats.inactivos);
            if (statsMantenimiento) statsMantenimiento.textContent = displayStat(data.stats.mantenimiento);

            // Sincronizar pills móviles
            const mTotal = document.getElementById("mobile_stats_total");
            const mInop  = document.getElementById("mobile_stats_inactivos");
            const mMant  = document.getElementById("mobile_stats_mantenimiento");
            
            if (mTotal) mTotal.textContent = displayStat(data.stats.total);
            if (mInop)  mInop.textContent  = displayStat(data.stats.inactivos);
            if (mMant)  mMant.textContent  = displayStat(data.stats.mantenimiento);

            const distroContainer = document.getElementById(
                "distributionStatsContainer",
            );
            if (distroContainer) distroContainer.innerHTML = data.distribution;

            window.history.pushState(null, "", finalUrl);
        })
        .catch((error) => {
            console.error("Error loading equipos:", error);
            tableBody.style.opacity = "1";
        })
        .finally(() => {
            if (window.hidePreloader) window.hidePreloader();
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

window.changeStatus = function (id, newStatus, url, element) {
    // PERMISSION CHECK
    if (window.CAN_CHANGE_STATUS === false || window.CAN_CHANGE_STATUS === 'false') {
        if (typeof window.showModal === 'function') {
            window.showModal({
                type: "error",
                title: "Acceso Denegado",
                message: "No tienes permisos para cambiar el estado de los equipos.",
                confirmText: "Entendido",
                hideCancel: true
            });
        }
        return;
    }

    if (!element) return;
    const dropdown = element.closest(".custom-dropdown");
    if (!dropdown) return;

    const oldStatus = dropdown.getAttribute("data-current-status");
    if (oldStatus === newStatus) {
        window.toggleStatusDropdown(dropdown.querySelector(".status-trigger"));
        return;
    }

    const trigger = dropdown.querySelector(".status-trigger");
    const menu = dropdown.querySelector(".status-dropdown-menu");

    const statusConfig = {
        OPERATIVO: {
            color: "#16a34a",
            icon: "check_circle",
            label: "Operativo",
        },
        INOPERATIVO: { color: "#dc2626", icon: "cancel", label: "Inoperativo" },
        "EN MANTENIMIENTO": {
            color: "#d97706",
            icon: "engineering",
            label: "Mantenimiento",
        },
        DESINCORPORADO: {
            color: "#475569",
            icon: "archive",
            label: "Desincorp.",
        },
    };

    const config = statusConfig[newStatus] || statusConfig["DESINCORPORADO"];
    if (trigger) {
        trigger.innerHTML = `
            <div style="display: flex; align-items: center; gap: 6px; color: ${config.color};">
                <i class="material-icons" style="font-size: 16px;">${config.icon}</i>
                <span style="color: #334155;">${config.label}</span>
            </div>
            <i class="material-icons" style="font-size: 16px; color: #94a3b8;">expand_more</i>
        `;
    }
    if (menu) menu.style.display = "none";

    window.updateLocalStats(oldStatus, newStatus);
    dropdown.setAttribute("data-current-status", newStatus);

    fetch(url, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": document
                .querySelector('meta[name="csrf-token"]')
                .getAttribute("content"),
            "X-HTTP-Method-Override": "PATCH",
        },
        body: JSON.stringify({ status: newStatus }),
    })
        .then((response) => {
            if (response.status === 419 || response.status === 401) {
                window.location.reload();
            }
        })
        .catch((error) => {
            console.error("Update failed:", error);
            window.loadEquipos();
        });
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

    // 2. Nuclear Cleanup: Remove any existing dynamic modals
    document.querySelectorAll(".dynamic-bulk-modal").forEach((el) => el.remove());

    // 3. Collect selected equipment codes
    const selectedList = Object.values(window.selectedEquipos);
    const count = selectedList.length;

    // 4. Collect frentes from datalist in DOM
    const frentesData = [];
    const dl = document.querySelector("#dynamicFrentesList");
    if (dl) {
        dl.querySelectorAll("option").forEach((opt) => {
            const nombre = opt.getAttribute("value") || "";
            const id = opt.getAttribute("data-id") || "";
            if (nombre) frentesData.push({ nombre, id });
        });
    }

    // 5. Create Overlay
    const overlay = document.createElement("div");
    overlay.className = "dynamic-bulk-modal";
    overlay.style.cssText = "position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.55);z-index:2500;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(3px);";

    // 6. Create Content Box
    const content = document.createElement("div");
    content.style.cssText = "background:white;border-radius:16px;width:90%;max-width:480px;max-height:92vh;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.30);animation:slideDown 0.2s ease-out;display:flex;flex-direction:column;";

    // 7. Header
    const header = document.createElement("div");
    header.style.cssText = "background:linear-gradient(135deg,#1e293b,#0f172a);padding:18px 22px;color:white;display:flex;justify-content:space-between;align-items:center;";
    header.innerHTML = `
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="background:rgba(255,255,255,0.15);border-radius:10px;width:38px;height:38px;display:flex;align-items:center;justify-content:center;">
                <i class="material-icons" style="font-size:22px;">local_shipping</i>
            </div>
            <div>
                <h2 style="margin:0;font-size:17px;font-weight:800;">Movilización</h2>
                <p style="margin:0;font-size:12px;opacity:0.8;">${count} equipo${count !== 1 ? 's' : ''} seleccionado${count !== 1 ? 's' : ''}</p>
            </div>
        </div>
        <button type="button" id="btnCloseDynamic" style="background:rgba(255,255,255,0.15);border:none;color:white;width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;">
            <i class="material-icons" style="font-size:20px;">close</i>
        </button>
    `;

    // 8. Body
    const body = document.createElement("div");
    body.style.cssText = "padding:22px 24px;display:flex;flex-direction:column;gap:18px;overflow-y:auto;flex:1;";

    const chipsHtml = selectedList.map(item => {
        const isValid = v => v && String(v).trim() !== '' && v !== 'N/A';
        const placa  = isValid(item.placa)  ? item.placa  : null;
        const chasis = isValid(item.chasis) ? item.chasis : null;
        const code   = isValid(item.code)   ? item.code   : null;
        const label  = placa || chasis || code || `ID:${item.id || '?'}`;
        return `<span style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap;">${label}</span>`;
    }).join("");

    body.innerHTML = `
        <div>
            <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">Equipos a movilizar</p>
            <div style="display:flex;flex-wrap:wrap;gap:6px;padding:10px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;">
                ${chipsHtml}
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
            <div style="margin-top: 15px; display: flex; align-items: center; gap: 8px; padding: 10px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <input type="checkbox" id="bm-generar-pdf" style="width: 16px; height: 16px; cursor: pointer; accent-color: #1e293b;">
                <label for="bm-generar-pdf" style="font-size: 13px; font-weight: 600; color: #475569; cursor: pointer; user-select: none; margin: 0;">
                    Generar Informe (Acta de Traslado)
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
    listBox.style.cssText = 'display:none;position:fixed;background:white;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);z-index:9999;max-height:240px;overflow-y:auto;';
    document.body.appendChild(listBox);

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
            listBox.innerHTML = `<div style="padding:14px;text-align:center;color:#94a3b8;font-size:13px;">Sin resultados</div>`;
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
    });
    searchInput.addEventListener('blur', () => {
        setTimeout(() => { listBox.style.display = 'none'; inputBox.style.borderColor = '#e2e8f0'; }, 150);
    });
    clearBtn.addEventListener('click', () => {
        searchInput.value = '';
        hiddenInput.value = '';
        clearBtn.style.display = 'none';
        searchInput.focus();
    });

    // ── Close handlers ──
    const _closeModal = () => { removePortal(); overlay.remove(); };
    overlay.querySelector('#btnCloseDynamic').onclick = _closeModal;
    overlay.onclick = (e) => { if (e.target === overlay) _closeModal(); };

    // ── Submit ──
    overlay.querySelector("#bm-submit-btn").onclick = function () {
        const dest = (hiddenInput.value || searchInput.value).trim();
        const generarPdfBox = overlay.querySelector("#bm-generar-pdf");
        const generarPdf = generarPdfBox ? generarPdfBox.checked : true;

        if (!dest) {
            inputBox.style.borderColor = "#ef4444";
            searchInput.focus();
            return;
        }

        const btn = this;
        btn.innerHTML = '<i class="material-icons" style="font-size:18px;animation:spin 1s linear infinite;">sync</i> Procesando...';
        btn.disabled = true;
        btn.style.opacity = "0.7";

        const ids = Object.keys(window.selectedEquipos);
        if (window.showPreloader) window.showPreloader();

        fetch("/admin/equipos/bulk-mobilize", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN":
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute("content") || "",
                Accept: "application/json",
            },
            body: JSON.stringify({ ids: ids, destination: dest, generar_pdf: generarPdf }),
        })
            .then(function (res) {
                if (res.status === 419) {
                    if (window.hidePreloader) window.hidePreloader();
                    window.showModal({
                        type: "error",
                        title: "Sesión Expirada",
                        message:
                            "Su sesión ha expirado. La página se recargará.",
                        confirmText: "Recargar",
                        hideCancel: true,
                        onConfirm: () => window.location.reload(),
                    });
                    return;
                }
                if (!res.ok) throw new Error("Error en la respuesta");
                return res.json();
            })
            .then(function (data) {
                if (!data) return; // Session expired case

                // Hide preloader
                if (window.hidePreloader) window.hidePreloader();

                removePortal();
                overlay.remove();
                window.clearSelection();

                // CRITICAL: Wait for table to fully reload before showing success
                return window.loadEquipos().then(() => data); // Pasar data al siguiente then
            })
            .then(function (data) {
                if (!data) return;

                // 1. Iniciar Descarga Automática (Si hay ID)
                if (data.generar_pdf) {
                    const firstId =
                        data.movilizacion_ids && data.movilizacion_ids.length > 0
                            ? data.movilizacion_ids[0]
                            : null;

                    if (firstId) {
                        const downloadLink = document.createElement("a");
                        downloadLink.href = `/admin/movilizaciones/${firstId}/acta-traslado`;
                        downloadLink.style.display = "none";
                        document.body.appendChild(downloadLink);

                        // Pequeño delay para asegurar que el DOM lo procese
                        setTimeout(() => {
                            downloadLink.click();
                            setTimeout(
                                () => document.body.removeChild(downloadLink),
                                1000,
                            );
                        }, 100);
                    }
                }

                // 2. Notificación rápida de éxito
                if (typeof window.showToast === 'function') {
                    if (data.generar_pdf) {
                        window.showToast(`¡Movilización exitosa! Descargando acta de ${data.count} traslado(s)...`, 'success');
                    } else {
                        window.showToast('Actualización de ubicación exitosa', 'success');
                    }
                }

                if (document.activeElement) document.activeElement.blur();
                document
                    .querySelectorAll(".custom-dropdown.active")
                    .forEach((el) => el.classList.remove("active"));
            })
            .catch(function (err) {
                console.error(err);

                // Hide preloader
                if (window.hidePreloader) window.hidePreloader();

                // Remove overlay and portal to prevent UI blocking
                removePortal();
                overlay.remove();

                // Restore button state (though overlay is gone, this variable reference persists)
                btn.innerHTML = '<i class="material-icons" style="font-size:18px;">send</i> Confirmar Movilización';
                btn.disabled = false;
                btn.style.opacity = "1";
                btn.style.cursor = "pointer";

                window.showModal({
                    type: "error",
                    title: "Error",
                    message:
                        "Hubo un error al procesar la movilización. Por favor intente nuevamente.",
                    confirmText: "Entendido",
                    hideCancel: true,
                });
            });
    };
};

window.openAnchorModal = async function (event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const selections = Object.entries(window.selectedEquipos);
    if (selections.length !== 1) return; // Only 1-to-1

    const sourceId = selections[0][0];
    const sourceData = selections[0][1];
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

    // Fetch Equipos
    try {
        const response = await fetch(
            `/admin/equipos/get-equipos-by-frente?id_frente=${firstFrenteId}&source_role=${sourceRole}`,
            {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            },
        );
        const equipos = await response.json();
        const listContainer = content.querySelector("#anchorEquiposList");
        listContainer.innerHTML = "";

        const selectedIds = selections.map((s) => String(s[0]));

        if (equipos.length === 0) {
            listContainer.innerHTML =
                '<div style="padding:40px 20px; text-align:center; color:#94a3b8;"><i class="material-icons" style="font-size:32px; display:block; margin-bottom:10px;">assignment_late</i>No existe equipos disponibles</div>';
        } else {
            // ── Búsqueda en tiempo real ──
            const anchorSearchInput = content.querySelector('#anchorSearchInput');
            const anchorSearchClear = content.querySelector('#anchorSearchClear');
            const anchorSearchBox = content.querySelector('#anchor-search-box');

            function filterAnchorList(query) {
                const q = (query || '').trim().toUpperCase();
                const items = listContainer.querySelectorAll('.anchor-option-item');
                let anyVisible = false;
                items.forEach(item => {
                    const text = (item.dataset.searchText || '').toUpperCase();
                    const match = !q || text.includes(q);
                    item.style.display = match ? '' : 'none';
                    if (match) anyVisible = true;
                });
                let noResult = listContainer.querySelector('.anchor-no-result');
                if (!anyVisible) {
                    if (!noResult) {
                        noResult = document.createElement('div');
                        noResult.className = 'anchor-no-result';
                        noResult.style.cssText = 'padding:30px 20px; text-align:center; color:#94a3b8; font-size:13px;';
                        noResult.innerHTML = '<i class="material-icons" style="font-size:28px; display:block; margin-bottom:6px;">search_off</i>Sin resultados';
                        listContainer.appendChild(noResult);
                    }
                } else if (noResult) {
                    noResult.remove();
                }
            }

            anchorSearchInput.addEventListener('input', () => {
                const val = anchorSearchInput.value;
                anchorSearchClear.style.display = val ? 'block' : 'none';
                anchorSearchBox.style.borderColor = val ? '#10b981' : '#e2e8f0';
                filterAnchorList(val);
            });
            anchorSearchClear.addEventListener('click', () => {
                anchorSearchInput.value = '';
                anchorSearchClear.style.display = 'none';
                anchorSearchBox.style.borderColor = '#e2e8f0';
                filterAnchorList('');
                anchorSearchInput.focus();
            });

            // ── Render de items ──
            equipos.forEach((eq) => {
                const isSelected = selectedIds.includes(String(eq.ID_EQUIPO));
                const item = document.createElement("div");
                item.className = "anchor-option-item";
                item.style.cssText = `padding:10px; border-radius:8px; background:white; border:1px solid #e2e8f0; margin-bottom:6px; cursor:${isSelected ? "not-allowed" : "pointer"}; opacity:${isSelected ? "0.6" : "1"}; display:flex; align-items:center; gap:12px; transition:all 0.2s; position:relative;`;
                item.dataset.searchText = [
                    eq.TIPO_NOMBRE || '',
                    eq.CODIGO_PATIO || '',
                    eq.MARCA || '',
                    eq.MODELO || '',
                    eq.SERIAL_CHASIS || '',
                    eq.PLACA || '',
                ].join(' ');

                if (!isSelected) {
                    item.onmouseover = () => {
                        if (!item.dataset.selected)
                            item.style.borderColor = "#10b981";
                        item.style.boxShadow =
                            "0 4px 6px -1px rgba(0,0,0,0.05)";
                    };
                    item.onmouseout = () => {
                        if (!item.dataset.selected)
                            item.style.borderColor = "#e2e8f0";
                        item.style.boxShadow = "none";
                    };
                    item.onclick = () => {
                        content
                            .querySelectorAll(".anchor-option-item")
                            .forEach((el) => {
                                el.style.borderColor = "#e2e8f0";
                                el.style.background = "white";
                                el.dataset.selected = "";
                                el.querySelector(".check-mark").style.display =
                                    "none";
                            });
                        item.style.borderColor = "#10b981";
                        item.style.background = "#f0fdf4";
                        item.dataset.selected = "true";
                        item.querySelector(".check-mark").style.display =
                            "block";

                        window.selectedMasterId = eq.ID_EQUIPO;
                        const btn = content.querySelector("#btnConfirmAnchor");
                        btn.disabled = false;
                        btn.style.opacity = "1";
                        btn.style.cursor = "pointer";
                    };
                }

                // Photo Handling
                let fotoHtml = "";
                if (eq.FOTO) {
                    const driveId = eq.FOTO.replace(/^.*\/storage\/google\//, "").split("?")[0];
                    fotoHtml = `<img src="/storage/google/${driveId}" style="width:100%; height:100%; object-fit:cover;">`;
                } else {
                    fotoHtml = `<i class="material-icons" style="font-size:20px; color:#cbd5e0;">image_not_supported</i>`;
                }

                item.innerHTML = `
                    <div style="width:40px; height:40px; background:#f1f5f9; border-radius:6px; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        ${fotoHtml}
                    </div>
                    <div style="flex:1; min-width:0; display:flex; flex-direction:column; gap:2px;">
                        <span style="font-weight:800; font-size:13px; color:#1e293b; text-transform:uppercase; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${eq.TIPO_NOMBRE || 'S/TIPO'}</span>
                        <div style="font-size:11px; color:#475569; font-weight:600;">${eq.MARCA}</div>
                        <div style="display:flex; align-items:center; gap:8px; margin-top:1px;">
                            <span style="font-size:10px; color:#64748b; display:flex; align-items:center; gap:2px;"><i class="material-icons" style="font-size:10px;">fingerprint</i>${eq.SERIAL_CHASIS || 'S/S'}</span>
                            ${eq.PLACA ? `<span style="font-size:10px; color:#0067b1; font-weight:700; display:flex; align-items:center; gap:2px;"><i class="material-icons" style="font-size:10px;">featured_play_list</i>${eq.PLACA}</span>` : ""}
                        </div>
                    </div>
                    <div class="check-mark" style="display:none; color:#10b981;">
                        <i class="material-icons" style="font-size:20px;">check_circle</i>
                    </div>
                    ${isSelected ? `<i class="material-icons" style="color:#cbd5e0; font-size:20px; margin-left:auto;">lock</i>` : ""}
                `;
                listContainer.appendChild(item);
            });
        } // fin else
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
                    "X-CSRF-TOKEN": document.querySelector(
                        'meta[name="csrf-token"]',
                    ).content,
                },
                body: JSON.stringify({
                    ids: selections.map((s) => s[0]),
                    master_id: window.selectedMasterId,
                }),
            });
            const data = await response.json();
            if (data.success) {
                overlay.remove();
                window.clearSelection();
                window.loadEquipos();
                window.showModal({
                    type: "success",
                    title: "¡Operación Exitosa!",
                    message: data.message,
                    confirmText: "Aceptar",
                    hideCancel: true,
                });
            } else {
                window.showModal({ type: 'error', title: 'Error', message: data.error || 'Error al anclar equipos.', confirmText: 'Cerrar', hideCancel: true });
            }
        } catch (error) {
            console.error(error);
        } finally {
            btn.disabled = false;
            btn.innerHTML =
                '<i class="material-icons">save</i> Confirmar Anclaje';
        }
    };
};

window.updateLocalStats = function (oldStatus, newStatus) {
    const elOper = document.getElementById("stats_activos");
    const elInop = document.getElementById("stats_inactivos");
    const elMant = document.getElementById("stats_mantenimiento");

    const adjust = (el, amount) => {
        if (el) {
            let val = parseInt(el.textContent.replace(/\D/g, "")) || 0;
            val += amount;
            el.textContent = val < 0 ? 0 : val;
        }
    };

    // Espejo: actualizar también las pills móviles
    const adjustMirror = (mobileId, amount) => {
        const el = document.getElementById(mobileId);
        if (el) {
            let val = parseInt(el.textContent.replace(/\D/g, "")) || 0;
            val += amount;
            el.textContent = val < 0 ? 0 : val;
        }
    };

    if (oldStatus === "OPERATIVO") adjust(elOper, -1);
    if (oldStatus === "INOPERATIVO" || oldStatus === "DESINCORPORADO")
        adjust(elInop, -1);
    if (oldStatus === "EN MANTENIMIENTO") adjust(elMant, -1);

    // Espejo móvil
    if (oldStatus === "INOPERATIVO" || oldStatus === "DESINCORPORADO") adjustMirror("mobile_stats_inactivos", -1);
    if (oldStatus === "EN MANTENIMIENTO") adjustMirror("mobile_stats_mantenimiento", -1);

    if (newStatus === "OPERATIVO") adjust(elOper, 1);
    if (newStatus === "INOPERATIVO" || newStatus === "DESINCORPORADO")
        adjust(elInop, 1);
    if (newStatus === "EN MANTENIMIENTO") adjust(elMant, 1);
};

window.exportEquipos = function () {
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
    const categoriaInput = advancedPanel
        ? advancedPanel.querySelector('input[name="categoria"]')
        : document.querySelector('input[name="categoria"]');
    const estadoInput = advancedPanel
        ? (advancedPanel.querySelector('input[name="estado"]') || document.querySelector('input[name="estado"]'))
        : document.querySelector('input[name="estado"]');

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
    hasAnyFilter |= appendIfValid("anio", anioInput?.value);
    hasAnyFilter |= appendIfValid("categoria", categoriaInput?.value);
    hasAnyFilter |= appendIfValid("estado", estadoInput?.value);

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

    // Descargar mediante formulario invisible (GET) para que el archivo
    // se descargue directamente sin abrir ninguna pestaña nueva.
    const form = document.createElement('form');
    form.method = 'GET';
    form.action = '/admin/equipos/export';
    form.style.display = 'none';

    params.forEach((value, key) => {
        const input = document.createElement('input');
        input.type  = 'hidden';
        input.name  = key;
        input.value = value;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
};

function initEquipos() {
    if (!document.getElementById("equiposTableBody")) return;

    const searchInput = document.getElementById("searchInput");
    // Guard: only attach listener once per DOM instance
    if (searchInput && !searchInput.dataset.equiposInitialized) {
        searchInput.dataset.equiposInitialized = 'true';
        searchInput.addEventListener("keyup", function () {
            const val = this.value;
            const clearBtn = document.getElementById("btn_clear_search");
            if (clearBtn)
                clearBtn.style.display = val.length > 0 ? "block" : "none";

            clearTimeout(window.searchTimeout);
            if (val.length >= 4 || val.length === 0) {
                window.searchTimeout = setTimeout(
                    () => window.loadEquipos(),
                    1000,
                );
            }
        });
    }

    const form = document.getElementById("search-form");
    if (form) {
        form.onsubmit = function (e) {
            e.preventDefault();
            window.loadEquipos();
            return false;
        };
    }

    updateSelectionUI();
}

// Listen for SPA navigation
window.addEventListener("spa:contentLoaded", function () {
    const isOnEquiposPage =
        document.getElementById("equiposTableBody") !== null;

    if (isOnEquiposPage) {
        // Reinitialize module when navigating TO equipos
        initEquipos();
    } else if (
        window.selectedEquipos &&
        Object.keys(window.selectedEquipos).length > 0
    ) {
        // Clear selections when navigating AWAY from equipos
        window.selectedEquipos = {};
        updateSelectionUI();
    }
});

// ==========================================
// ADVANCED FILTER LOGIC
// NOTE: clearAdvancedFilters is defined in uicomponents.js (authoritative source).
// It only clears advanced filters (modelo/marca/año/checkboxes) — intentionally
// does NOT touch the main Frente/Tipo dropdowns so their X buttons stay visible.
// ==========================================

// NOTE: selectAdvancedFilter, clearAdvancedFilters, clearDropdownFilter, clearFilter
// are ALL defined in uicomponents.js (authoritative source) — do NOT duplicate here.
// The uicomponents.js version handles all filter types and calls loadEquipos() correctly.

// NOTE: selectOption is defined in uicomponents.js (global, supports 4-param legacy).
// Equipos-specific visual side-effect (btnAdvancedFilter highlight) is applied via
// the dropdown-selection event so it does NOT override the global function.

window.addEventListener("dropdown-selection", function (e) {
    // Only apply Equipos advanced-filter button highlight when on the equipos page
    if (!document.getElementById("equiposTableBody")) return;
    const advBtn = document.getElementById("btnAdvancedFilter");
    if (advBtn && e.detail.value) {
        advBtn.style.background = "#e1effa";
        advBtn.style.color = "#0067b1";
        advBtn.style.border = "1px solid #0067b1";
    }
});

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

        window.showModal({
            type: "error",
            title: "Acceso Denegado",
            message: "No tienes permisos para crear nuevos equipos.",
            confirmText: "Entendido",
            hideCancel: true,
        });
        return false;
    }

    // 2. SPA Navigation Strategy
    // Instead of forcing location.href, we inject the URL into the link and let the event bubble.
    // The global 'navegacion.js' script will catch the click, see the valid href, and perform SPA load.
    if (event && window.CREATE_URL) {
        const link = event.currentTarget || event.target.closest("a");
        if (link) {
            link.href = window.CREATE_URL;
            // Do NOT preventDefault() here. Let it bubble to 'navegacion.js'.
            return true;
        }
    }

    // 3. Fallback (if called programmatically or something failed)
    if (window.CREATE_URL) {
        window.location.href = window.CREATE_URL;
    }
    return true;
};

// [End of dashboard cleanup]

// Register with Module Manager (records module, no-op if already initialized)
if (typeof ModuleManager !== 'undefined') {
    ModuleManager.register(
        "equipos",
        () => document.getElementById("equiposTableBody") !== null,
        initEquipos,
    );
}

// Direct init on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEquipos);
} else {
    initEquipos();
}
