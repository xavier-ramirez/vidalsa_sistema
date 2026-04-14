/**
 * uicomponents.js - Shared UI Components
 * Version: 2.0 - Clean Architecture with Event Delegation
 */

// Global click handler for dropdowns (event delegation)
// Helper to close all dropdowns except the one passed
window.closeAllDropdowns = function (exceptElement) {
    // Close standard .custom-dropdown components
    document
        .querySelectorAll(".custom-dropdown, .custom-multiselect")
        .forEach((el) => {
            if (el !== exceptElement) el.classList.remove("active");
        });

    // Close special filters (Equipos Index) if they are not the exception
    // We check if the exceptElement *contains* the special list to avoid closing it if we are interacting with it?
    // Actually, 'exceptElement' is usually the container being opened.

    // yearList
    const yearList = document.getElementById("yearList");
    if (
        yearList &&
        yearList !== exceptElement &&
        !exceptElement?.contains(yearList)
    ) {
        yearList.style.display = "none";
    }

    // modelList
    const modelList = document.getElementById("modelList");
    if (
        modelList &&
        modelList !== exceptElement &&
        !exceptElement?.contains(modelList)
    ) {
        modelList.style.display = "none";
    }

    // marcaList
    const marcaList = document.getElementById("marcaList");
    if (
        marcaList &&
        marcaList !== exceptElement &&
        !exceptElement?.contains(marcaList)
    ) {
        marcaList.style.display = "none";
    }
};

// Global click handler for dropdowns (event delegation) - ROBUST VERSION
document.addEventListener("click", function (e) {
    // 1. Identify if click is inside a dropdown trigger
    const trigger = e.target.closest(".dropdown-trigger, .multiselect-trigger");

    // If not clicking a trigger, check if clicking INSIDE an already open dropdown
    if (!trigger) {
        const isClickInside =
            e.target.closest(".custom-dropdown, .custom-multiselect") ||
            e.target.closest("#yearList") ||
            e.target.closest("#modelList") ||
            e.target.closest("#marcaList") ||
            e.target.id === "searchModelInput" ||
            e.target.id === "searchMarcaInput";

        if (!isClickInside) {
            closeAllDropdowns(null); // Close everything
        }
        return;
    }

    // 2. Resolve the parent dropdown component
    const parent = trigger.closest(".custom-dropdown, .custom-multiselect");
    if (!parent) return; // If clicking a trigger without parent (like Año inline), return early (it handles itself)

    // 3. Logic: Always Toggle, but be smart about Inputs
    const isInput = e.target.tagName === "INPUT";
    const isOpen = parent.classList.contains("active");

    // GUARD: If clicking an input that is already active, DO NOTHING.
    // This prevents accidental closing or re-toggling when trying to type.
    if (isInput && isOpen) {
        return;
    }

    // Close ALL OTHER dropdowns first
    closeAllDropdowns(parent);

    // 4. Handle the Toggle
    if (isInput) {
        // If clicking the input and it's closed -> Open it
        if (!isOpen) {
            parent.classList.add("active");
        }
        // If it's already open and we clicked the input, DO NOTHING (let user type)
    } else {
        // Clicking the container (icon, padding, etc) -> Toggle normally
        parent.classList.toggle("active");

        // If we just opened it, focus the input if it exists
        if (parent.classList.contains("active")) {
            const input = parent.querySelector('input[type="text"]');
            if (input) setTimeout(() => input.focus(), 50);
        }
    }

    e.stopPropagation();
});

// Global focus handler to open dropdowns on tab/click-focus and close others
document.addEventListener("focusin", function (e) {
    if (e.target.matches('.dropdown-trigger input[type="text"]')) {
        const parent = e.target.closest(
            ".custom-dropdown, .custom-multiselect",
        );
        if (parent) {
            // Close others
            closeAllDropdowns(parent);

            // Open this one if not active
            if (!parent.classList.contains("active")) {
                parent.classList.add("active");
            }
        }
    }
});

// Manual toggle function for inline handlers (forms, etc.)
// toggleDropdown is defined below (complete version with input/label guard)

/**
 * ═══════════════════════════════════════════════════════════════════════
 * GLOBAL selectOption - ID-AGNOSTIC ARCHITECTURE (v2.0)
 * ═══════════════════════════════════════════════════════════════════════
 * Uses data attributes for all lookups. No hardcoded IDs.
 *
 * Required HTML structure:
 * <div class="custom-dropdown" id="uniqueId" data-filter-type="type" data-default-label="...">
 *     <input type="hidden" data-filter-value>
 *     <div class="dropdown-trigger">
 *         <input type="text" data-filter-search>
 *         <i data-clear-btn>close</i>
 *     </div>
 *     <div class="dropdown-content">
 *         <div class="dropdown-item" data-value="val">Label</div>
 *     </div>
 * </div>
 * ═══════════════════════════════════════════════════════════════════════
 */
window.selectOption = function (dropdownId, value, label, legacyType) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) {
        console.warn("[selectOption] Dropdown not found:", dropdownId);
        return;
    }

    // Determine filter type from data attribute or legacy parameter
    const type = dropdown.dataset.filterType || legacyType || dropdownId;

    // Find elements using data attributes (PRIMARY) or fallback to legacy patterns
    let hiddenInput = dropdown.querySelector("[data-filter-value]");
    let searchInput = dropdown.querySelector("[data-filter-search]");
    let labelSpan = dropdown.querySelector("[data-filter-label]");
    let clearBtn = dropdown.querySelector("[data-clear-btn]");

    // LEGACY FALLBACK: Support old structure while migrating
    if (!hiddenInput) {
        hiddenInput = dropdown.querySelector('input[type="hidden"]');
    }
    if (!searchInput) {
        searchInput = dropdown.querySelector(
            '.dropdown-trigger input[type="text"]',
        );
    }
    if (!labelSpan && legacyType) {
        // Try legacy ID pattern: #label_tipo, #label_rol, etc.
        labelSpan = document.getElementById("label_" + legacyType);
    }
    if (!clearBtn) {
        // Try to find by class pattern used in some modules
        clearBtn = dropdown.querySelector(
            ".dropdown-trigger .material-icons[data-clear-btn]",
        );
    }

    // Normalize value
    const effectiveValue =
        value === null || value === undefined ? "" : String(value);

    // Update hidden input
    if (hiddenInput) {
        hiddenInput.value = effectiveValue;
    }

    // Update search input placeholder (for filter dropdowns)
    if (searchInput) {
        searchInput.placeholder = label;
        searchInput.value = "";
    }

    // Update label span text (for form dropdowns)
    if (labelSpan) {
        labelSpan.textContent = label;
    }

    // Visual feedback on trigger
    const trigger = dropdown.querySelector(".dropdown-trigger");
    if (trigger) {
        if (
            effectiveValue &&
            effectiveValue !== "all" &&
            effectiveValue !== ""
        ) {
            trigger.style.background = "#e1effa";
            trigger.style.borderColor = "#0067b1";
        } else {
            trigger.style.background = "#fbfcfd";
            trigger.style.borderColor = "#cbd5e0";
        }
    }

    // Update selected state on items
    dropdown.querySelectorAll(".dropdown-item").forEach((item) => {
        const itemValue =
            item.dataset.value !== undefined ? item.dataset.value : null;
        const isSelected =
            itemValue !== null
                ? itemValue === effectiveValue
                : item.innerText.trim() === label.trim();
        item.classList.toggle("selected", isSelected);
    });

    // Close dropdown
    dropdown.classList.remove("active");

    // Toggle clear button visibility
    if (clearBtn) {
        clearBtn.style.display =
            effectiveValue && effectiveValue !== "all" && effectiveValue !== ""
                ? "block"
                : "none";
    }

    // Dispatch custom event for module-specific reactions
    window.dispatchEvent(
        new CustomEvent("dropdown-selection", {
            detail: {
                dropdownId,
                value: effectiveValue,
                label,
                inputName: type,
            },
        }),
    );
};

/**
 * Generic clear function for any dropdown
 */
window.clearDropdownFilter = function (dropdownId) {
    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    const defaultLabel = dropdown.dataset.defaultLabel || "Seleccionar...";
    window.selectOption(dropdownId, "", defaultLabel);
};

window.updateSelectedCount = function () {
    const checkboxes = document.querySelectorAll(
        'input[name="PERMISOS[]"]:checked',
    );
    const countSpan = document.getElementById("selectedCount");
    if (!countSpan) return;

    if (checkboxes.length === 0) {
        countSpan.innerText = "Seleccione permisos...";
        countSpan.style.color = "#a0aec0";
    } else {
        const labels = Array.from(checkboxes).map((cb) => cb.value);
        countSpan.innerText = labels.join(", ");
        countSpan.style.color = "inherit";
    }
};

// Confirm Delete (Hybrid: Custom Modal if available, fallback to Native)
window.confirmDelete = function (id, name) {
    // Try to find custom modal elements (used in Usuarios, etc.)
    const modal = document.getElementById("deleteModal");
    const nameSpan = document.getElementById("deleteModalUserName");
    const confirmBtn = document.getElementById("confirmDeleteBtn");
    const form = document.getElementById("delete-form-global"); // Global form preferred

    if (modal && nameSpan && confirmBtn && form) {
        // UI: Use Custom Modal
        nameSpan.innerText = name;

        // Handle routes dynamically based on context if needed, but standard is /admin/usuarios/id
        // If we need to support multiple modules, we might need a type argument, or use the form's data-action-base
        // For now, defaulting to standard global form behavior or dynamic path setting

        // Check if form has a specific base action or default to usuarios
        // To be safe and generic: We assume the caller or the form setup knows the route,
        // OR we infer it.
        // Given existing code used /admin/usuarios/, let's support that default but be flexible.

        // Strategy: If form has 'action', use it? No, we need to append ID.
        // Let's assume this is mostly for Usuarios as per previous code.
        // If we want it generic, we should pass the URL.
        // But for now, let's keep the previous behavior:

        // If we are functioning globally, we need to know the Model.
        // But confirmDelete(id, name) signature lacks Model.
        // Falls back to Usuarios logic for now as it was the only one using it.
        // OR checks if we are on a specific page.

        if (window.location.pathname.includes("usuarios")) {
            form.action = `/admin/usuarios/${id}`;
        } else {
            // Fallback for other modules if they introduce this modal
            form.action =
                window.location.pathname.replace(/\/create|\/edit/, "") +
                "/" +
                id;
        }

        confirmBtn.onclick = function () {
            window.closeDeleteModal();
            if (window.showPreloader) window.showPreloader();
            form.submit();
        };

        modal.style.display = "flex";
        requestAnimationFrame(() => {
            modal.classList.add("active");
            modal.style.opacity = "1";
        });
    } else {
        // Fallback: Use native confirm
        if (
            confirm(
                `¿Estás seguro de que deseas eliminar a "${name}"?\n\nEsta acción no se puede deshacer.`,
            )
        ) {
            // Check for specific form pattern (delete-form-ID) or global form
            let specificForm = document.getElementById("delete-form-" + id);
            if (specificForm) {
                specificForm.submit();
            } else if (form) {
                if (window.location.pathname.includes("usuarios")) {
                    form.action = `/admin/usuarios/${id}`;
                } else {
                    form.action =
                        window.location.pathname.replace(
                            /\/create|\/edit/,
                            "",
                        ) +
                        "/" +
                        id;
                }
                form.submit();
            } else {
                console.error("Delete form not found");
            }
        }
    }
};

window.closeDeleteModal = function () {
    const modal = document.getElementById("deleteModal");
    if (modal) {
        modal.classList.remove("active");
        modal.style.opacity = "0";
        setTimeout(() => {
            modal.style.display = "none";
        }, 300);
    }
};

// Manual toggle function for inline handlers (forms, etc.) - CONSOLIDATED & ROBUST
window.toggleDropdown = function (dropdownId, event) {
    if (event) event.stopPropagation();

    const dropdown = document.getElementById(dropdownId);
    if (!dropdown) return;

    // Prevent closing if clicking an input/label inside an open dropdown (e.g., Search Frentes)
    const e = event || window.event;
    if (
        e &&
        e.target &&
        (e.target.tagName === "INPUT" || e.target.tagName === "LABEL") &&
        dropdown.classList.contains("active")
    ) {
        return;
    }

    // Close all other dropdowns first using the CENTRAL helper
    closeAllDropdowns(dropdown);

    // Toggle this one
    dropdown.classList.toggle("active");

    // Focus input automatically when opening
    if (dropdown.classList.contains("active")) {
        const input = dropdown.querySelector('input[type="text"]');
        if (input) setTimeout(() => input.focus(), 50);
    }
};

window.toggleMultiselect = function () {
    const multiselect = document.getElementById("permissionsSelect");
    if (!multiselect) return;

    document
        .querySelectorAll(".custom-dropdown, .custom-multiselect")
        .forEach((el) => {
            if (el !== multiselect) el.classList.remove("active");
        });
    multiselect.classList.toggle("active");
};

window.filterDropdownOptions = function (input) {
    const dropdown = input.closest(".custom-dropdown");
    if (!dropdown) return;

    // Standard Client-Side Filtering (Original Logic) for small lists (Frente, Tipo, etc.)
    // Normalize helper: lowercase and remove accents
    const normalize = (str) => {
        return str
            .toLowerCase()
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "");
    };

    const filter = normalize(input.value);
    const items = dropdown.querySelectorAll(
        ".dropdown-item, .filter-option-item",
    );

    items.forEach((item) => {
        const text = normalize(item.innerText);
        const shouldShow = text.includes(filter);

        // Use setProperty to override any potential CSS conflicts
        item.style.setProperty(
            "display",
            shouldShow ? "block" : "none",
            "important",
        );
    });

    // Reset scroll position to top to ensure results are seen
    const content =
        dropdown.querySelector(".dropdown-content") ||
        dropdown.querySelector(".dropdown-item-list");
    if (content) content.scrollTop = 0;

    if (filter.length > 0) {
        dropdown.classList.add("active");
    }
};

// Global input listener is REDUNDANT because we added inline oninput handlers explicitly.
// Removing it to prevent double-execution and potential conflicts.

/**
 * ═══════════════════════════════════════════════════════════════════════
 * GLOBAL DROPDOWN FUNCTIONS - SINGLE SOURCE OF TRUTH
 * ═══════════════════════════════════════════════════════════════════════
 * These functions are called from inline handlers in Blade templates across
 * different modules (Equipos, Catálogo, Movilizaciones, etc.).
 *
 * ⚠️ DO NOT duplicate these in module files - this is the authoritative source!
 * ═══════════════════════════════════════════════════════════════════════
 */

// CATÁLOGO: Advanced filter selection (Modelo, Año)
window.selectAdvancedOption = function (type, value, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    if (type === "modelo") {
        const input = document.getElementById("searchModeloInput");
        if (input) {
            input.value = value;
            input.placeholder = value ? value : "Buscar Modelo...";
        }
        const hidden = document.getElementById("input_modelo_filter");
        if (hidden) hidden.value = value;

        const clearBtn = document.getElementById("btn_clear_modelo");
        if (clearBtn) clearBtn.style.display = value ? "block" : "none";

        const dropdown = document.getElementById("modeloFilterSelect");
        if (dropdown) dropdown.classList.remove("active");
    }

    if (type === "anio") {
        const input = document.getElementById("searchAnioInput");
        if (input) {
            input.value = value;
            input.placeholder = value ? value : "Buscar Año...";
        }
        const hidden = document.getElementById("input_anio_filter");
        if (hidden) hidden.value = value;

        const clearBtn = document.getElementById("btn_clear_anio");
        if (clearBtn) clearBtn.style.display = value ? "block" : "none";

        const dropdown = document.getElementById("anioFilterSelect");
        if (dropdown) dropdown.classList.remove("active");
    }

    // Trigger catalog load if function exists
    if (typeof window.loadCatalogo === "function") {
        window.loadCatalogo();
    }
};

// EQUIPOS: Advanced filter selection (Modelo, Marca, Año, Frente, Tipo, Search)
window.selectAdvancedFilter = function (key, value) {
    if (window.searchTimeout) clearTimeout(window.searchTimeout);

    if (key === "modelo") {
        // Find the modelo container
        const container = document.querySelector(
            '[data-advanced-filter="modelo"]',
        );
        if (container) {
            const hiddenInput = container.querySelector("[data-filter-value]");
            const searchInput = container.querySelector("[data-filter-search]");
            const list = container.querySelector(".filter-list");
            const btn = container.querySelector("[data-clear-btn]");

            if (hiddenInput) hiddenInput.value = value;
            if (searchInput) searchInput.value = value;
            if (list) list.style.display = "none";
            if (btn) btn.style.display = value ? "block" : "none";
        }
    }

    if (key === "marca") {
        // Find the marca container
        const container = document.querySelector(
            '[data-advanced-filter="marca"]',
        );
        if (container) {
            const hiddenInput = container.querySelector("[data-filter-value]");
            const searchInput = container.querySelector("[data-filter-search]");
            const list = container.querySelector(".filter-list");
            const btn = container.querySelector("[data-clear-btn]");

            if (hiddenInput) hiddenInput.value = value;
            if (searchInput) searchInput.value = value;
            if (list) list.style.display = "none";
            if (btn) btn.style.display = value ? "block" : "none";
        }
    }

    if (key === "anio") {
        const input = document.querySelector('input[name="anio"]');
        if (input) input.value = value;
        const labelSpan = document
            .querySelector("#yearList")
            ?.previousElementSibling?.querySelector("span");
        if (labelSpan) labelSpan.innerText = value || "Seleccionar Año";
        const list = document.getElementById("yearList");
        if (list) list.style.display = "none";
        const btn = document.getElementById("btn_clear_anio");
        if (btn) btn.style.display = value ? "block" : "none";
    }

    if (key === "frente" || key === "id_frente") {
        const input = document.querySelector('input[name="id_frente"]');
        if (input) input.value = value;
        const dropdown = document.getElementById("frenteFilterSelect");
        if (dropdown) {
            const searchInput = dropdown.querySelector("[data-filter-search]");
            const btn = dropdown.querySelector("[data-clear-btn]");
            if (searchInput)
                searchInput.placeholder = value ? value : "Filtrar Frente...";
            if (btn) btn.style.display = value ? "block" : "none";
            dropdown.classList.remove("active");
        }
    }

    if (key === "tipo" || key === "id_tipo") {
        const input = document.querySelector('input[name="id_tipo"]');
        if (input) input.value = value;
        const dropdown = document.getElementById("tipoFilterSelect");
        if (dropdown) {
            const searchInput = dropdown.querySelector("[data-filter-search]");
            const btn = dropdown.querySelector("[data-clear-btn]");
            if (searchInput)
                searchInput.placeholder = value ? value : "Filtrar Tipo...";
            if (btn) btn.style.display = value ? "block" : "none";
            dropdown.classList.remove("active");
        }
    }

    if (key === "search") {
        const input = document.getElementById("searchInput");
        if (input) input.value = value;
        const btn = document.getElementById("btn_clear_search");
        if (btn) btn.style.display = value ? "block" : "none";
    }

    // Dispatch al módulo activo según el DOM (no por typeof — ambas funciones siempre existen)
    if (document.getElementById('equiposTableBody') && typeof window.loadEquipos === 'function') {
        window.loadEquipos();
    } else if (document.getElementById('movilizacionesTableBody') && typeof window.loadMovilizaciones === 'function') {
        window.loadMovilizaciones();
    }
};

// EQUIPOS: Clear all advanced filters
window.clearAdvancedFilters = function () {
    if (window.searchTimeout) clearTimeout(window.searchTimeout);

    // Clear all Custom Dropdown Advanced Filters
    const advFilters = ['modeloAdvFilter', 'marcaAdvFilter', 'anioAdvFilter', 'categoriaAdvFilter', 'estadoAdvFilter'];
    advFilters.forEach(id => {
        if (document.getElementById(id) && typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter(id);
        }
    });

    // Clear Doc Filters (Equipos specific)
    ["chk_propiedad", "chk_poliza", "chk_rotc", "chk_racda"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.checked = false;
    });

    // Dispatch al módulo activo según el DOM
    if (document.getElementById('equiposTableBody') && typeof window.loadEquipos === 'function') {
        window.loadEquipos();
    } else if (document.getElementById('movilizacionesTableBody') && typeof window.loadMovilizaciones === 'function') {
        window.loadMovilizaciones();
    }
};

// EQUIPOS: Helper alias for inline onclick (maps view filter names to internal keys)
window.clearFilter = function (filterName) {
    const map = {
        id_frente: "frente",
        id_tipo: "tipo",
        modelo: "modelo",
        anio: "anio",
        marca: "marca",
    };

    const key = map[filterName] || filterName;
    window.selectAdvancedFilter(key, "");
};

// Register with Module Manager
// Since we use Event Delegation now, we don't need to re-attach listeners on navigation!
// This makes the app much lighter and faster.
ModuleManager.register(
    "uicomponents",
    () => false, // Return false prevents re-initialization since it's now globally handled
    () => { }, // No-op initializer
);

// Global Frentes Search Function (Called via inline attributes for SPA robustness)
window.searchFrentes = function (input) {
    const query = input.value.trim();
    const resultsDiv = document.getElementById("search-results");
    const clearIcon = document.getElementById("clear_search");

    // Toggle clear icon
    if (clearIcon) {
        clearIcon.style.display = query.length > 0 ? "block" : "none";

        // Ensure the click handler is attached (inline onclick handles logic, but display is here)
    }

    // Safety check
    if (!resultsDiv) return;

    // Debounce logic
    clearTimeout(input.searchTimeout);

    // Immediate toggle for empty (show list)
    if (query.length === 0) {
        performFrentesFetch("", resultsDiv);
        return;
    }

    input.searchTimeout = setTimeout(() => {
        performFrentesFetch(query, resultsDiv);
    }, 300);
};

// Helper for fetching
window.performFrentesFetch = function (query, resultsDiv) {
    if (!resultsDiv) return;

    fetch(`/admin/frentes/buscar?query=${encodeURIComponent(query)}`)
        .then((response) => response.json())
        .then((data) => {
            resultsDiv.innerHTML = "";
            if (data.length > 0) {
                data.forEach((item) => {
                    const div = document.createElement("div");
                    div.className = "search-result-item";
                    div.textContent = item.NOMBRE_FRENTE;
                    const safeName = item.NOMBRE_FRENTE.replace(/'/g, "\\'");
                    div.onclick = () =>
                        window.selectFrente(item.ID_FRENTE, safeName);
                    resultsDiv.appendChild(div);
                });
                resultsDiv.style.display = "block";
            } else {
                const div = document.createElement("div");
                div.className = "search-result-item";
                div.style.color = "#94a3b8";
                div.style.cursor = "default";
                div.textContent = "No se encontraron resultados";
                resultsDiv.appendChild(div);
                resultsDiv.style.display = "block";
            }
        })
        .catch((error) => console.error("Error:", error));
};

// Selection handler
window.selectFrente = function (id, name) {
    if (window.showPreloader) window.showPreloader();
    window.location.href = `/admin/frentes/${id}/edit`;
};

// Close handler (Global)
document.addEventListener("click", function (e) {
    const resultsDiv = document.getElementById("search-results");
    const searchInput = document.getElementById("search_query");
    if (
        resultsDiv &&
        searchInput &&
        !searchInput.contains(e.target) &&
        !resultsDiv.contains(e.target)
    ) {
        resultsDiv.style.display = "none";
    }
});

// Clear handler
window.clearFrentesSearch = function () {
    const searchInput = document.getElementById("search_query");
    if (searchInput) {
        searchInput.value = "";
        window.searchFrentes(searchInput); // Refresh list
        searchInput.focus();
    }
};
// Confirm Delete Frente (Dynamic Modal)
window.confirmDeleteFrente = function (id, name) {
    window.showModal({
        type: "error",
        title: "¿Eliminar Frente?",
        message: `¿Estás seguro de que deseas eliminar el frente "${name}"? Esta acción no se puede deshacer.`,
        confirmText: "Sí, Eliminar",
        onConfirm: () => {
            const form = document.getElementById("deleteFrenteForm");
            if (form) {
                if (window.showPreloader) window.showPreloader();
                form.submit();
            }
        },
    });
};

/**
 * ═══════════════════════════════════════════════════════════════════════
 * GLOBAL DETAILS MODAL LOGIC (IMPROVED)
 * ═══════════════════════════════════════════════════════════════════════
 */
window.showDetailsImproved = function (target, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    if (!target || !target.dataset) {
        console.error("showDetailsImproved called without valid target");
        return;
    }

    const d = target.dataset;
    const modal = document.getElementById("detailsModal");

    // Reset Accordions (Close all sections so they are closed by default)
    if (modal) {
        modal
            .querySelectorAll("details")
            .forEach((det) => det.removeAttribute("open"));
    }

    // Helper to identify empty values (also rejects the string "null" emitted by PHP when value is null)
    const isValid = (val) => val && val !== "N/A" && val !== "" && val !== "null" && val !== "undefined";

    // Helper to set text
    const set = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.innerText = val || "N/A";
    };

    // Helper to format date YYYY-MM-DD -> DD/MM/YYYY
    const formatDate = (dateStr) => {
        if (!dateStr || dateStr === "N/A" || dateStr.trim() === "")
            return "N/A";
        const parts = dateStr.split("-");
        if (parts.length === 3) {
            return `${parts[2]}/${parts[1]}/${parts[0]}`;
        }
        return dateStr;
    };

    // ── Título y subtítulo del modal
    // FORCE UPDATE title with Type
    const typeText = target.getAttribute("data-tipo") || d.tipo || "Equipo";
    const titleVal =
        typeText !== "undefined" && typeText !== "null" ? typeText : "Equipo";
    set("modal_equipo_title", titleVal);
    const titleEl = document.getElementById("modal_equipo_title");
    if (titleEl) titleEl.style.textTransform = "uppercase";

    const subtitleParts = [];
    if (d.placa && d.placa !== "N/A") subtitleParts.push(`Placa: ${d.placa}`);
    if (d.chasis && d.chasis !== "N/A")
        subtitleParts.push(`Serial: ${d.chasis}`);
    set("modal_equipo_subtitle", subtitleParts.join(" - "));

    // GPS Button
    const gpsBtn = document.getElementById("modal_gps_btn");
    if (gpsBtn) {
        const rawGps = (d.linkGps || "").trim();
        if (isValid(rawGps)) {
            gpsBtn.dataset.url = rawGps;

            // Limpiar si el dato guardado en base de datos ya trae la palabra "Placa:" o "Serial:" adentro
            let rawPlaca = d.placa ? d.placa.toString().replace(/^(placa|serial)[\:\s\-]+/i, '').trim() : '';
            let rawChasis = d.chasis ? d.chasis.toString().replace(/^(placa|serial)[\:\s\-]+/i, '').trim() : '';

            let strPlaca = (isValid(rawPlaca) && rawPlaca !== "N/A") ? rawPlaca : "Sin Placa";
            let strChasis = isValid(rawChasis) ? rawChasis : "Sin Chasis";

            gpsBtn.dataset.equipoName   = strPlaca;
            gpsBtn.dataset.equipoSerial = strChasis;
            gpsBtn.dataset.equipoTipo   = (d.tipo && d.tipo !== 'null' && d.tipo !== 'undefined') ? d.tipo : '';

            gpsBtn.style.display = "inline-flex";
        } else {
            gpsBtn.style.display = "none";
        }
    }


    // General Info (d_marca, d_modelo, d_motor_serial ocultos — ya aparecen en la tabla principal)
    set("d_anio", d.anio);
    set("d_categoria", d.categoria);
    set("d_combustible", d.combustible);
    set("d_consumo", d.consumo);

    // Sección / Ubicación específica
    const detalleUbi = d.detalleUbicacion || '';
    const detalleEl = document.getElementById('d_detalle_ubicacion');
    if (detalleEl) detalleEl.innerText = detalleUbi || '—';
    // Guardar id y valor del equipo activo para el Quick Edit
    window._quickEditEquipoId   = d.equipoId || '';
    window._quickEditUbicacion  = detalleUbi;
    // Ocultar modo edición al abrir nuevo equipo
    cancelEditUbicacion();

    // Docs
    set("d_titular", d.titular);
    set("d_placa", d.placa);
    set("d_nro_doc", d.nroDoc);

    const vencSeguroEl = document.getElementById("d_venc_seguro");
    if (vencSeguroEl) {
        vencSeguroEl.innerText = formatDate(d.vencSeguro);
        // Add color logic if needed for expiration
    }

    set("d_fecha_rotc", formatDate(d.fechaRotc));
    set("d_fecha_racda", formatDate(d.fechaRacda));

    // Document Action Buttons Generator
    const createDocBtn = (containerId, type, link, label, equipoId) => {
        const container = document.getElementById(containerId);
        if (!container) return;

        if (isValid(link)) {
            // PDF existe — solo botón de Ver
            container.innerHTML = `
                <div class="pdf-btn-container">
                    <button type="button"
                        onclick="event.stopPropagation(); openPdfPreview('${link}', '${type}', '${label}', '${equipoId}')"
                        style="background: none; border: none; padding: 0; cursor: pointer; display: flex; align-items: center; justify-content: center;"
                        title="Ver PDF: ${label}">
                        <i class="material-icons" style="font-size: 22px; color: #ef4444;">picture_as_pdf</i>
                    </button>
                </div>
            `;
        } else {
            // Sin PDF — mostrar botón de carga solo si tiene permiso
            if (typeof window.CAN_UPDATE_INFO !== "undefined" && window.CAN_UPDATE_INFO === false) {
                container.innerHTML = `<span style="color: #94a3b8; font-size: 12px; font-style: italic; display: flex; align-items: center; justify-content: flex-end; height: 36px;">Sin Documento</span>`;
                return;
            }

            const inputId = `input_upload_${type}_${equipoId}`;
            container.innerHTML = `
                <div style="position: relative; width: 30px; height: 30px;">
                    <input type="file" id="${inputId}" accept="application/pdf" style="display: none;" onchange="uploadDocument(this, '${type}', '${equipoId}', '${containerId}', '${label}')">
                    <label for="${inputId}"
                        style="display: flex; align-items: center; justify-content: center; width: 100%; height: 100%; background: #fbfcfd; color: #3b82f6; border: 1px dashed #3b82f6; border-radius: 6px; transition: 0.2s; cursor: default;"
                        onmouseover="this.style.background='#eff6ff'"
                        onmouseout="this.style.background='#fbfcfd'"
                        title="Cargar ${label}">
                        <i class="material-icons" style="font-size: 18px;">cloud_upload</i>
                    </label>
                </div>
            `;
        }

    };

    const eqId = d.equipoId;
    createDocBtn(
        "d_btn_propiedad",
        "propiedad",
        d.linkPropiedad,
        "Propiedad",
        eqId,
    );
    createDocBtn("d_btn_poliza", "poliza", d.linkSeguro, "Póliza", eqId);
    createDocBtn("d_btn_rotc", "rotc", d.linkRotc, "ROTC", eqId);
    createDocBtn("d_btn_racda", "racda", d.linkRacda, "RACDA", eqId);
    createDocBtn(
        "d_btn_adicional",
        "adicional",
        d.linkAdicional,
        "Adicional",
        eqId,
    );

    // Show Modal
    if (modal) {
        modal.style.display = "flex";
        // Force reflow
        void modal.offsetWidth;
        modal.classList.add("active");
    }

    window.activeEquipoButton = target;

    // ── Cargar Sub-activos vinculados ────────────────────────
    const saAccordion = document.getElementById('sa_accordion');
    const saList      = document.getElementById('sa_list');
    const saBadge     = document.getElementById('sa_count_badge');
    const subCount = parseInt(d.subCount || "0", 10);

    if (saAccordion && saList && eqId) {
        if (subCount > 0) {
            saAccordion.style.display = 'block';
            saList.innerHTML = '<p style="color:#94a3b8;font-size:12px;text-align:center;padding:8px;">Cargando...</p>';
            if (saBadge) saBadge.textContent = subCount;

            const SA_TIPO_CFG = {
            MAQUINA_SOLDADURA: { icon: 'construction', color: '#f59e0b', bg: '#fff7ed', label: 'Máq. Soldadura' },
            PLANTA_ELECTRICA:  { icon: 'bolt',          color: '#eab308', bg: '#fefce8', label: 'Planta Eléc.'   },
            CONTENEDOR:        { icon: 'inventory_2',   color: '#6366f1', bg: '#eef2ff', label: 'Contenedor'     },
            COMPRESOR:         { icon: 'air',           color: '#0ea5e9', bg: '#f0f9ff', label: 'Compresor'      },
            OTRO:              { icon: 'handyman',       color: '#64748b', bg: '#f1f5f9', label: 'Otro'           },
        };
        fetch(`/admin/sub-activos?host=${eqId}`, { headers:{'X-Requested-With':'XMLHttpRequest'} })
            .then(r => r.json())
            .then(json => {
                if (!json.ok || json.data.length === 0) {
                    saList.innerHTML = '<p style="color:#94a3b8;font-size:12px;text-align:center;padding:8px;">No hay sub-activos directamente vinculados.</p>';
                    return;
                }
                saAccordion.style.display = 'block';
                if (saBadge) saBadge.textContent = json.data.length;
                
                saList.innerHTML = json.data.map(sa => {
                    const tc = SA_TIPO_CFG[sa.tipo] || SA_TIPO_CFG.OTRO;
                    const estadoColor = sa.estado === 'OPERATIVO' ? '#16a34a' : (sa.estado === 'INOPERATIVO' ? '#dc2626' : '#64748b');
                    const estadoBg    = sa.estado === 'OPERATIVO' ? '#f0fdf4'  : (sa.estado === 'INOPERATIVO' ? '#fef2f2'  : '#f1f5f9');

                    // Foto: placeholder gris sin fondo colorido amarillento
                    const foto = `<div style="width:44px;height:44px;border-radius:9px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;border:1px solid #cbd5e0;flex-shrink:0;">
                                      <span class="material-icons" style="font-size:22px;color:#94a3b8;">${tc.icon}</span>
                                  </div>`;

                    const infoExtra = [sa.marca, sa.modelo, sa.capacidad, sa.anio].filter(Boolean).join(' · ');

                    return `<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;transition:background .15s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#f8fafc'">
                        ${foto}
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:12px;font-weight:700;color:#334155;">${tc.label}</div>
                            <div style="font-family:monospace;font-size:12px;color:#1e293b;font-weight:700;margin-top:1px;">${sa.serial || '—'}</div>
                            ${infoExtra ? `<div style="font-size:10px;color:#94a3b8;margin-top:1px;">${infoExtra}</div>` : ''}
                        </div>
                        <span style="font-size:10px;font-weight:800;color:${estadoColor};background:${estadoBg};padding:3px 9px;border-radius:10px;flex-shrink:0;letter-spacing:.3px;">${sa.estado.replace('_',' ')}</span>
                    </div>`;
                }).join('');
            })
            .catch(() => { 
                saList.innerHTML = '<p style="color:#dc2626;font-size:12px;text-align:center;padding:8px;">Error al cargar.</p>'; 
            });
        } else {
            saAccordion.style.display = 'none';
        }
    }

    // ── Cargar Responsables Asignados ────────────────────────
    const respAccordion = document.getElementById('responsable_accordion');
    if (respAccordion && eqId) {
        respAccordion.style.display = 'block';
        window.loadResponsables(eqId);
    }
};

window.closeDetailsModal = function (event) {
    if (event) event.preventDefault();
    
    // Auto-save quick edit if it's currently open
    const editWrapper = document.getElementById("ubicacion_edit_wrapper");
    if (editWrapper && editWrapper.style.display !== "none") {
        if (typeof window.saveUbicacion === "function") {
            window.saveUbicacion();
        }
    }

    // Auto-save responsable si hay texto en los campos
    const respCedula = document.getElementById('resp_cedula');
    const respNombre = document.getElementById('resp_nombre');
    if (respCedula && respNombre && (respCedula.value.trim() !== '' || respNombre.value.trim() !== '')) {
        if (typeof window.saveResponsable === "function") {
            window.saveResponsable(true); // true = autoSave al cerrar
        }
    }

    const modal = document.getElementById("detailsModal");
    if (modal) {
        modal.classList.remove("active");
        setTimeout(() => {
            modal.style.display = "none";
        }, 300);
    }
};

window.loadResponsables = function(equipoId) {
    const list = document.getElementById('responsable_list');
    
    // Al cargar historial, aseguremos que el formulario de edición esté cerrado y limpio
    const formContainer = document.getElementById('responsable_form_container');
    const inputCed = document.getElementById('resp_cedula');
    const inputNom = document.getElementById('resp_nombre');
    
    if (formContainer && inputCed && inputNom) {
        // En lugar de ocultar siempre con display='none', lo ocultamos DENTRO del fetch SI hay responsables.
        // Mientras carga, lo dejamos visible provisionalmente para dar respuesta inmediata al usuario.
        formContainer.style.display = 'flex'; 
        inputCed.value = '';
        inputNom.value = '';
    }

    if (!list) return;

    list.innerHTML = '<p style="color:#94a3b8;font-size:12px;text-align:center;padding:8px;">Cargando responsables...</p>';

    fetch(`/admin/equipos/${equipoId}/responsables`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success || res.data.length === 0) {
            list.innerHTML = '';
            // Si no hay responsables nunca, abrir el formulario para que asigne uno
            if (formContainer) {
                formContainer.style.display = 'flex';
            }
            return;
        }

        if (formContainer) {
            formContainer.style.display = 'none';
        }

        list.innerHTML = res.data.map((r, index) => {
            const isCurrent = index === 0;
            const bg = isCurrent ? '#f0fdf4' : '#f8fafc';
            const border = isCurrent ? '#bbf7d0' : '#e2e8f0';
            // Edit button on current user line
            const editBtnEl = isCurrent ? `
            <button type="button" onclick="document.getElementById('responsable_form_container').style.display='flex';" title="Editar Responsable" style="background: white; border: 1px solid #cbd5e1; color: #475569; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='white'">
                <i class="material-icons" style="font-size: 14px;">edit</i>
            </button>` : '';

            // Format fecha
            const f = new Date(r.FECHA_ASIGNACION);
            const dStr = isNaN(f.getTime()) ? r.FECHA_ASIGNACION : f.toLocaleDateString('es-VE');

            return `
            <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:${bg};border-radius:10px;border:1px solid ${border};transition:background .15s;">
                <div style="width:36px;height:36px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;color:#64748b;font-weight:700;font-size:14px;">
                    ${r.PERSONA_ASIGNADA.charAt(0).toUpperCase()}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-size:13px;font-weight:700;color:#1e293b;word-wrap:break-word;overflow-wrap:break-word;line-height:1.2;">${r.PERSONA_ASIGNADA}</span>
                    </div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">
                        C.I. ${r.CEDULA_RESPONSABLE} &nbsp;&bull;&nbsp; Asignado el ${dStr}
                    </div>
                </div>
                ${editBtnEl}
            </div>`;
        }).join('');
    })
    .catch(() => {
        list.innerHTML = '<p style="color:#dc2626;font-size:12px;text-align:center;padding:8px;">Error al cargar responsables.</p>';
    });
};

window.saveResponsable = function(isAutoSave = false) {
    const equipoId = window._quickEditEquipoId;
    if (!equipoId) return;

    const cedulaInput = document.getElementById('resp_cedula');
    const nombreInput = document.getElementById('resp_nombre');
    if (!cedulaInput || !nombreInput) return;

    const cedula = cedulaInput.value.trim();
    const nombre = nombreInput.value.trim();

    if (!cedula || !nombre) {
        if (isAutoSave && (cedula || nombre)) {
            if (window.showToast) window.showToast('No se guardó el responsable: Cédula y nombre son obligatorios.', 'warning');
        } else if (!isAutoSave) {
            if (window.showToast) window.showToast('La cédula y el nombre son obligatorios.', 'error');
        }
        return;
    }

    // Al guardar al cerrar, la lista puede que no sea visible, pero mostramos un toast
    fetch(`/admin/equipos/${equipoId}/responsables`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            CEDULA_RESPONSABLE: cedula,
            PERSONA_ASIGNADA: nombre
        })
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            cedulaInput.value = '';
            nombreInput.value = '';
            if (window.showToast) window.showToast('Responsable asignado con éxito.', 'success');
            // Recargamos historial en caso de que el modal siga vivo (ej. guardado manual si se volviera a agregar el botón)
            window.loadResponsables(equipoId); 
        } else {
            if (window.showToast) window.showToast(res.message || 'Error al guardar responsable', 'error');
        }
    })
    .catch(err => {
        console.error(err);
        if (window.showToast) window.showToast('Error de conexión al guardar responsable', 'error');
    });
};

window.uploadDocument = function (input, type, equipoId, containerId, label) {
    // PERMISSION CHECK (Defense in depth)
    if (
        typeof window.CAN_UPDATE_INFO !== "undefined" &&
        window.CAN_UPDATE_INFO === false
    ) {
        input.value = ""; // Clear input
        if (window.showToast) {
            window.showToast("No tienes permisos para cargar documentos.", "error");
        } else {
            alert("Acceso Denegado: No tienes permisos.");
        }
        return;
    }

    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    
    // IMPORTANTE: Limpiamos el input enseguida para permitir reelección del MISMO archivo en caso de fallo
    input.value = ""; 

    if (window.showPreloader) window.showPreloader();

    const formData = new FormData();
    formData.append("file", file);
    formData.append("doc_type", type);

    const xhr = new XMLHttpRequest();
    xhr.open("POST", `/admin/equipos/${equipoId}/upload-doc`, true);
    // CSRF fetch
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta)
        xhr.setRequestHeader("X-CSRF-TOKEN", meta.getAttribute("content"));
    xhr.setRequestHeader("Accept", "application/json");

    xhr.onload = function () {
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    // Update UI
                    const container = document.getElementById(containerId);
                    if (container) {
                        container.innerHTML = `
                            <div class="pdf-btn-container">
                                <button type="button" 
                                    onclick="event.stopPropagation(); openPdfPreview('${data.link}', '${type}', '${label}', '${equipoId}')" 
                                    style="width: 36px; height: 36px; border-radius: 8px; background: #f8f9fa; border: 1px solid #dee2e6; display: flex; align-items: center; justify-content: center; transition: all 0.2s; cursor: pointer;"
                                    onmouseover="this.style.background='#e9ecef'" 
                                    onmouseout="this.style.background='#f8f9fa'"
                                    title="Ver PDF: ${label}">
                                    <i class="material-icons" style="font-size: 20px; color: #6c757d;">picture_as_pdf</i>
                                </button>
                            </div>
                        `;
                    }

                    if (window.activeEquipoButton) {
                        const d = window.activeEquipoButton.dataset;
                        if (type === "propiedad") d.linkPropiedad = data.link;
                        if (type === "poliza") d.linkSeguro = data.link;
                        if (type === "rotc") d.linkRotc = data.link;
                        if (type === "racda") d.linkRacda = data.link;
                        if (type === "adicional") d.linkAdicional = data.link;
                    }

                    if (typeof window.refreshDashboardAlerts === "function") {
                        window.refreshDashboardAlerts();
                    }

                    if (typeof window.openPdfPreview === "function") {
                        setTimeout(() => {
                            window.openPdfPreview(data.link, type, label, equipoId);
                            setTimeout(() => {
                                if (window.hidePreloader) window.hidePreloader();
                            }, 150);
                        }, 50);
                    } else {
                        if (window.hidePreloader) window.hidePreloader();
                    }
                } else {
                    if (window.hidePreloader) window.hidePreloader();
                    if (window.showToast) {
                        window.showToast(data.message || "Error al procesar el archivo.", "error");
                    }
                }
            } catch (e) {
                console.error("Error interpetando respuesta del servidor:", e);
                if (window.hidePreloader) window.hidePreloader();
                if (window.showToast) {
                    window.showToast("Error subiendo el PDF. El archivo podría ser demasiado pesado.", "error");
                }
            }
        } else {
            // Manejar errores como 413 Payload Too Large u otros
            if (window.hidePreloader) window.hidePreloader();
            if (window.showToast) {
                let msgError = (xhr.status === 413) 
                    ? "Error: El archivo pesa más del límite permitido." 
                    : `Error del servidor (Código: ${xhr.status}). Verifique su archivo.`;
                window.showToast(msgError, "error");
            }
        }
    };

    xhr.onerror = function () {
        if (window.hidePreloader) window.hidePreloader();
        if (window.showToast) {
            window.showToast("Error de conexión.", "error");
        }
    };

    xhr.send(formData);
};

/**
 * Global Preloader Management
 * Reuses the existing #preloader element from estructura_base.blade.php
 * to avoid DOM duplication and maintain consistency.
 */
window.showPreloader = function () {
    const preloader = document.getElementById("preloader");
    if (preloader) {
        // Remove fade-out class if present
        preloader.classList.remove("fade-out");

        // Make visible immediately
        preloader.style.display = "flex";
        preloader.style.opacity = "1";
        preloader.style.visibility = "visible";
        preloader.style.zIndex = "99999";
    }
};

window.hidePreloader = function () {
    const preloader = document.getElementById("preloader");
    if (preloader) {
        // Add fade-out class for smooth transition
        preloader.classList.add("fade-out");

        // Hide after transition completes (500ms as defined in CSS)
        setTimeout(() => {
            if (preloader.classList.contains("fade-out")) {
                preloader.style.display = "none";
            }
        }, 500);
    }
};

/**
 * showToast - Lightweight notification system
 * @param {string} message
 * @param {string} type - success, error, info (default: info)
 */
window.showToast = function (message, type = "info") {
    // 1. Create or get container
    let container = document.querySelector(".toast-container");
    if (!container) {
        container = document.createElement("div");
        container.className = "toast-container";
        document.body.appendChild(container);
    }

    // 2. Create toast element
    const toast = document.createElement("div");
    toast.className = `toast-notification ${type}`;

    // Icon mapping
    const icons = {
        success: "check_circle",
        error: "error",
        info: "info",
    };
    const icon = icons[type] || "info";

    toast.innerHTML = `
        <i class="material-icons">${icon}</i>
        <span>${message}</span>
    `;

    // 3. Add to container
    container.appendChild(toast);

    // 4. Auto-remove
    setTimeout(() => {
        toast.classList.add("fade-out");
        setTimeout(() => {
            toast.remove();
            // Remove container if empty
            if (container.children.length === 0) {
                container.remove();
            }
        }, 300);
    }, 4000);
};
/**
 * ════════════════════════════════════════════════════════
 * QUICK EDIT: SECCIÓN / UBICACIÓN EN FRENTE
 * ════════════════════════════════════════════════════════
 */
window.startEditUbicacion = function () {
    const displayWrapper = document.getElementById('ubicacion_display_wrapper');
    const editWrapper    = document.getElementById('ubicacion_edit_wrapper');
    const input          = document.getElementById('input_ubicacion');
    if (!displayWrapper || !editWrapper || !input) return;

    // Rellenar input con valor actual
    input.value = window._quickEditUbicacion || '';
    displayWrapper.style.display = 'none';
    editWrapper.style.display    = 'flex';
    input.focus();
    input.select();
};

window.cancelEditUbicacion = function () {
    const displayWrapper = document.getElementById('ubicacion_display_wrapper');
    const editWrapper    = document.getElementById('ubicacion_edit_wrapper');
    if (!displayWrapper || !editWrapper) return;
    displayWrapper.style.display = 'flex';
    editWrapper.style.display    = 'none';
};

window.saveUbicacion = async function () {
    const input    = document.getElementById('input_ubicacion');
    const detalleEl = document.getElementById('d_detalle_ubicacion');
    const equipoId  = window._quickEditEquipoId;
    if (!input || !equipoId) return;

    const nuevoValor = input.value.trim().toUpperCase();
    const btn        = input.nextElementSibling; // botón Guardar
    const originalText = btn ? btn.textContent : '';

    // Estado cargando
    if (btn) { btn.textContent = '...'; btn.disabled = true; }

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]');
        const res = await fetch(`/admin/equipos/${equipoId}/ubicacion`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrfToken ? csrfToken.content : '',
            },
            body: JSON.stringify({ DETALLE_UBICACION_ACTUAL: nuevoValor }),
        });

        const data = await res.json();

        if (!res.ok) throw new Error(data.message || 'Error al guardar');

        // Actualizar display en el modal
        window._quickEditUbicacion = nuevoValor;
        if (detalleEl) detalleEl.innerText = nuevoValor || '—';

        // Actualizar tooltip en la tabla de equipos (si el botón activo tiene data-equipo-id)
        const activeBtn = window.activeEquipoButton;
        if (activeBtn) {
            activeBtn.setAttribute('data-detalle-ubicacion', nuevoValor);
            // Actualizar el tooltip visible en la fila
            const row     = activeBtn.closest('tr');
            if (row) {
                const bubble = row.querySelector('.tooltip-bubble');
                if (bubble) {
                    if (nuevoValor) {
                        bubble.childNodes[0].textContent = '\uD83D\uDCCD ' + nuevoValor;
                        bubble.style.display = '';
                    } else {
                        bubble.remove();
                    }
                } else if (nuevoValor) {
                    // No existía el tooltip: mostrar indicador simple
                    const freneCell = row.querySelector('.tooltip-wrapper');
                    if (freneCell && !freneCell.querySelector('.tooltip-bubble')) {
                        freneCell.insertAdjacentHTML('beforeend',
                            `<div class="tooltip-bubble" style="pointer-events:none;opacity:0;visibility:hidden;position:absolute;bottom:100%;left:50%;transform:translateX(-50%) translateY(5px);background-color:#1e293b;color:#fff;padding:6px 10px;border-radius:6px;font-size:11px;font-weight:500;white-space:nowrap;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);transition:all 0.2s ease-in-out;z-index:50;margin-bottom:5px;">\uD83D\uDCCD ${nuevoValor}<div style="position:absolute;top:100%;left:50%;margin-left:-4px;border-width:4px;border-style:solid;border-color:#1e293b transparent transparent transparent;"></div></div>`
                        );
                    }
                }
            }
        }

        cancelEditUbicacion();
        showToast('Ubicación actualizada', 'success');

    } catch (err) {
        showToast('Error: ' + err.message, 'error');
        if (btn) { btn.textContent = originalText; btn.disabled = false; }
    }
};
