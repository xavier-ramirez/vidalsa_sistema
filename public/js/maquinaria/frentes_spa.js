/**
 * frentes_spa.js
 * SPA Logic for Frentes de Trabajo Module
 * Architecture: Global Event Delegation (Optimal & Robust)
 *
 * This approach eliminates the need for manual initialization,
 * MutationObservers, or ModuleManagers. It works natively
 * with the DOM regardless of when elements are injected.
 */

document.addEventListener("DOMContentLoaded", () => {
    // Optional: Initial setup if needed, but delegation handles dynamic content.
});

// --- 1. FORM SUBMISSION DELEGATION ---
document.addEventListener("submit", function (e) {
    if (e.target && e.target.id === "frenteForm") {
        e.preventDefault();
        submitFrenteForm(e.target);
    }
});

// ==========================================
//           BUSINESS LOGIC
// ==========================================

function populateFrenteForm(data) {
    const form = document.getElementById("frenteForm");
    if (!form) return;

    form.action = `/admin/frentes/${data.ID_FRENTE}`;

    // Add PUT method
    let methodField = form.querySelector('input[name="_method"]');
    if (!methodField) {
        methodField = document.createElement("input");
        methodField.type = "hidden";
        methodField.name = "_method";
        form.appendChild(methodField);
    }
    methodField.value = "PUT";

    // Set Data
    document.getElementById("ID_FRENTE").value = data.ID_FRENTE;
    const title = document.getElementById("formTitle");
    if (title) title.innerText = "Edición de Frente de Trabajo";

    const btnText = document.getElementById("submitBtnText");
    if (btnText) btnText.innerText = "Guardar Cambios";

    const btnIcon = document.getElementById("submitBtnIcon");
    if (btnIcon) btnIcon.innerText = "save";

    document.getElementById("NOMBRE_FRENTE").value = data.NOMBRE_FRENTE || "";
    document.getElementById("UBICACION").value = data.UBICACION || "";

    // Responsables
    document.getElementById("RESP_1_NOM").value = data.RESP_1_NOM || "";
    document.getElementById("RESP_1_CAR").value = data.RESP_1_CAR || "";
    if (document.getElementById("RESP_1_CED")) document.getElementById("RESP_1_CED").value = data.RESP_1_CED || "";

    document.getElementById("RESP_2_NOM").value = data.RESP_2_NOM || "";
    document.getElementById("RESP_2_CAR").value = data.RESP_2_CAR || "";
    if (document.getElementById("RESP_2_CED")) document.getElementById("RESP_2_CED").value = data.RESP_2_CED || "";

    document.getElementById("RESP_3_NOM").value = data.RESP_3_NOM || "";
    document.getElementById("RESP_3_CAR").value = data.RESP_3_CAR || "";
    if (document.getElementById("RESP_3_CED")) document.getElementById("RESP_3_CED").value = data.RESP_3_CED || "";

    document.getElementById("RESP_4_NOM").value = data.RESP_4_NOM || "";
    document.getElementById("RESP_4_CAR").value = data.RESP_4_CAR || "";
    if (document.getElementById("RESP_4_CED")) document.getElementById("RESP_4_CED").value = data.RESP_4_CED || "";

    document.getElementById("RESP_5_NOM").value = data.RESP_5_NOM || "";
    document.getElementById("RESP_5_CAR").value = data.RESP_5_CAR || "";
    if (document.getElementById("RESP_5_CED")) document.getElementById("RESP_5_CED").value = data.RESP_5_CED || "";

    // Equipment Filters (Dropdown labels & hidden inputs)
    const setEquFilter = (id, val) => {
        const input = document.getElementById("input_resp" + id + "_equ");
        const label = document.getElementById("label_resp" + id + "_equ");
        if (input) input.value = val || "";
        if (label) label.innerText = val || "SIN FILTRO";
    };

    setEquFilter("1", data.RESP_1_EQU);
    setEquFilter("2", data.RESP_2_EQU);
    setEquFilter("3", data.RESP_3_EQU);
    setEquFilter("4", data.RESP_4_EQU);
    setEquFilter("5", data.RESP_5_EQU);

    // Dropdowns (Tipo/Estatus)
    document.getElementById("input_tipo").value = data.TIPO_FRENTE;
    document.getElementById("label_tipo").innerText = data.TIPO_FRENTE;
    document.getElementById("input_estatus").value = data.ESTATUS_FRENTE;
    document.getElementById("label_estatus").innerText = data.ESTATUS_FRENTE;

    // Update Search Dropdown Input (to show what we are editing)
    const searchInput = document.getElementById("filterSearchInput");
    if (searchInput) {
        searchInput.value = data.NOMBRE_FRENTE;
        const clearIcon = document.getElementById("btn_clear_search_frente");
        if (clearIcon) clearIcon.style.display = "block";
    }

    // Mostrar boton Eliminar y actualizar dataset con el frente cargado
    const btnEliminar = document.getElementById("btnEliminarFrente");
    if (btnEliminar) {
        btnEliminar.dataset.frenteId = data.ID_FRENTE;
        btnEliminar.dataset.frenteNombre = data.NOMBRE_FRENTE || "";
        btnEliminar.style.display = "inline-flex";
    }
}

// --- 2. SEARCH & DROPDOWN LOGIC ---
window.filterFrentesDropdown = function (input) {
    const filter = input.value.toUpperCase();
    const list = document.getElementById("frenteItemsList");
    if (!list) return;

    const items = list.getElementsByClassName("search-result-item");
    const clearIcon = document.getElementById("btn_clear_search_frente");
    let hasVisible = false;

    if (clearIcon)
        clearIcon.style.display = filter.length > 0 ? "block" : "none";

    for (let i = 0; i < items.length; i++) {
        const txtValue = items[i].dataset.name || items[i].textContent || "";
        if (txtValue.toUpperCase().indexOf(filter) > -1) {
            items[i].style.display = "";
            hasVisible = true;
        } else {
            items[i].style.display = "none";
        }
    }

    const noResults = document.getElementById("no-results-msg");
    if (noResults) noResults.style.display = hasVisible ? "none" : "block";

    // Auto-open dropdown if typing
    const dropdown = document.getElementById("frenteSearchDropdown");
    if (dropdown && !dropdown.classList.contains("active")) {
        dropdown.classList.add("active");
    }
};

window.clearFrentesSearchSPA = function () {
    const input = document.getElementById("filterSearchInput");
    if (input) {
        input.value = "";
        window.filterFrentesDropdown(input);
        const dropdown = document.getElementById("frenteSearchDropdown");
        if (dropdown) dropdown.classList.remove("active");
    }
    // Optional: Reset form if clearing search?
    // window.resetFrentesForm();
};

window.selectFrenteSPA = function (id) {
    // Close dropdown
    const dropdown = document.getElementById("frenteSearchDropdown");
    if (dropdown) dropdown.classList.remove("active");

    // Clear search input but keep the selected text or placeholder?
    // For now, let's clear it to show it was selected
    const input = document.getElementById("filterSearchInput");
    // if (input) input.value = ''; // Optional

    if (window.showPreloader) window.showPreloader();

    fetch(`/admin/frentes/${id}/edit?json=true`, {
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            Accept: "application/json",
        },
    })
        .then((response) => {
            if (!response.ok) throw new Error("HTTP Status " + response.status);
            return response.json();
        })
        .then((data) => {
            if (window.hidePreloader) window.hidePreloader();
            populateFrenteForm(data);

            // Update input with selected name
            if (input) input.value = data.NOMBRE_FRENTE;
            const clearIcon = document.getElementById(
                "btn_clear_search_frente",
            );
            if (clearIcon) clearIcon.style.display = "block";
        })
        .catch((error) => {
            if (window.hidePreloader) window.hidePreloader();
            console.error("Error fetching details:", error);
            showNotification("error", "Error al cargar los datos");
        });
};
// --- SUBMIT FORM ---
function submitFrenteForm(form) {
    if (window.showPreloader) window.showPreloader();

    const formData = new FormData(form);
    let url = form.action;

    // Append force json param
    url += (url.includes("?") ? "&" : "?") + "json=true";

    fetch(url, {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "X-CSRF-TOKEN": document
                .querySelector('meta[name="csrf-token"]')
                .getAttribute("content"),
            Accept: "application/json",
        },
        body: formData,
    })
        .then((response) =>
            response
                .json()
                .then((data) => ({ status: response.status, body: data })),
        )
        .then(({ status, body }) => {
            if (window.hidePreloader) window.hidePreloader();

            if (status === 200 || status === 201) {
                showNotification(
                    "success",
                    body.message || "Operación exitosa",
                );

                // Dynamic Update Logic
                if (body.frente) {
                    window.addToSearchList(body.frente);
                }

                window.resetFrentesForm();
            } else if (status === 422) {
                let msg = "Error de validación";
                if (body.errors)
                    msg = Object.values(body.errors).flat().join("\n");
                else if (body.message) msg = body.message;
                showNotification("error", msg);
            } else {
                showNotification("error", body.message || "Error desconocido");
            }
        })
        .catch((error) => {
            if (window.hidePreloader) window.hidePreloader();
            console.error("Error:", error);
            showNotification("error", "Error de conexión");
        });
}

// --- RESET & DELETE HELPERS ---
window.resetFrentesForm = function () {
    const form = document.getElementById("frenteForm");
    if (form) {
        form.reset();
        form.action = "/admin/frentes";

        const methodField = form.querySelector('input[name="_method"]');
        if (methodField) methodField.value = "POST";

        document.getElementById("ID_FRENTE").value = "";
        const title = document.getElementById("formTitle");
        if (title) title.innerText = "Registro de Frente de Trabajo";

        const btnText = document.getElementById("submitBtnText");
        if (btnText) btnText.innerText = "Registrar";

        const btnIcon = document.getElementById("submitBtnIcon");
        if (btnIcon) btnIcon.innerText = "add_circle";

        // Ocultar boton Eliminar al volver a modo "Registrar nuevo"
        const btnEliminar = document.getElementById("btnEliminarFrente");
        if (btnEliminar) {
            btnEliminar.dataset.frenteId = "";
            btnEliminar.dataset.frenteNombre = "";
            btnEliminar.style.display = "none";
        }

        document.getElementById("input_tipo").value = "";
        document.getElementById("label_tipo").innerText = "Seleccione Tipo...";
        document.getElementById("input_estatus").value = "";
        document.getElementById("label_estatus").innerText =
            "Seleccione Estatus...";

        // Reset all Resp Equ and Cedula filters
        for (let i = 1; i <= 5; i++) {
            const input = document.getElementById("input_resp" + i + "_equ");
            const label = document.getElementById("label_resp" + i + "_equ");
            const cedula = document.getElementById("RESP_" + i + "_CED");
            if (input) input.value = "";
            if (label) label.innerText = "SIN FILTRO";
            if (cedula) cedula.value = "";
        }

        const searchInput = document.getElementById("filterSearchInput");
        if (searchInput) {
            searchInput.value = "";
            // Reset Dropdown
            window.filterFrentesDropdown(searchInput);
        }

        const dropdown = document.getElementById("frenteSearchDropdown");
        if (dropdown) dropdown.classList.remove("active");

        const clear = document.getElementById("btn_clear_search_frente");
        if (clear) clear.style.display = "none";
    }
};

// --- DYNAMIC DOM UPDATE ---
window.addToSearchList = function (frente) {
    const list = document.getElementById("frenteItemsList");
    if (!list) return;

    // Check if exists
    let existing = null;
    const items = list.getElementsByClassName("search-result-item");
    for (let item of items) {
        if (item.textContent.trim() === frente.NOMBRE_FRENTE) {
            existing = item;
            break;
        }
    }

    if (existing) {
        // Update functionality if needed, but it's just a selector
        return;
    }

    // Create new item
    const div = document.createElement("div");
    div.className = "dropdown-item search-result-item";
    div.dataset.name = frente.NOMBRE_FRENTE;
    div.textContent = frente.NOMBRE_FRENTE;
    div.onclick = function () {
        window.selectFrenteSPA(String(frente.ID_FRENTE));
    };

    // Insert alphabetically (simple optional, or just append)
    list.insertBefore(div, list.firstChild);

    // Hide no results
    const noMsg = document.getElementById("no-results-msg");
    if (noMsg) noMsg.style.display = "none";
};

// Quita un frente del dropdown de búsqueda por NOMBRE (al desactivarlo o
// eliminarlo desde el modal "Frentes sin equipos"), para no dejarlo listado.
window.removeFromSearchList = function (nombre) {
    const list = document.getElementById("frenteItemsList");
    if (!list || nombre == null) return;
    const target = String(nombre).trim();
    const items = list.getElementsByClassName("search-result-item");
    for (let item of items) {
        if (((item.dataset.name || item.textContent) || "").trim() === target) {
            item.remove();
            break;
        }
    }
};

// Bind Confirm Button

function showNotification(type, message) {
    // Toast efimero (auto-cierra en ~3s) en vez de modal con "Aceptar":
    // las confirmaciones de exito/error de operaciones puntuales no requieren
    // que el usuario tenga que cerrar manualmente. Mismo patron que el resto
    // de modulos (equipos, auxiliares, catalogo).
    if (typeof window.showToast === "function") {
        window.showToast(message, type);
    } else if (typeof window.showModal === "function") {
        // Fallback: si no hay toast disponible, modal sin boton Cancelar.
        window.showModal({
            type: type,
            title: type === "success" ? "Éxito" : "Error",
            message: message,
            confirmText: "Aceptar",
            hideCancel: true,
        });
    } else {
        alert(message);
    }
}
