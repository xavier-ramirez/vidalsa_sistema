/**
 * frentes_spa.js
 * SPA Logic for Frentes de Trabajo Module
 * Architecture: Global Event Delegation (Optimal & Robust)
 *
 * This approach eliminates the need for manual initialization,
 * MutationObservers, or ModuleManagers. It works natively
 * with the DOM regardless of when elements are injected.
 */

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

// Modo del formulario, en UN solo sitio: con un frente → "Edición" (PUT a /admin/frentes/{id},
// "Guardar" y la papelera de ese frente); con null → "Registro" (POST, "Registrar",
// sin papelera). Lo usan populateFrenteForm (al elegir un frente) y resetFrentesForm.
function ponerModoFormulario(form, frente) {
    form.action = frente ? `/admin/frentes/${frente.ID_FRENTE}` : "/admin/frentes";
    let methodField = form.querySelector('input[name="_method"]');
    if (!methodField) {
        methodField = document.createElement("input");
        methodField.type = "hidden";
        methodField.name = "_method";
        form.appendChild(methodField);
    }
    methodField.value = frente ? "PUT" : "POST";
    document.getElementById("ID_FRENTE").value = frente ? frente.ID_FRENTE : "";

    const title = document.getElementById("formTitle");
    if (title) title.innerText = frente ? "Edición de Frente de Trabajo" : "Registro de Frente de Trabajo";
    const btnText = document.getElementById("submitBtnText");
    if (btnText) btnText.innerText = frente ? "Guardar" : "Registrar";
    const btnIcon = document.getElementById("submitBtnIcon");
    if (btnIcon) btnIcon.innerText = frente ? "save" : "add_circle";

    // Papelera (junto al buscador): solo con un frente cargado, y apuntando a ESE frente.
    const papelera = document.getElementById("btnEliminarFrente");
    if (papelera) {
        papelera.dataset.frenteId = frente ? frente.ID_FRENTE : "";
        papelera.dataset.frenteNombre = frente ? (frente.NOMBRE_FRENTE || "") : "";
        papelera.hidden = !frente;
    }
}

function populateFrenteForm(data) {
    const form = document.getElementById("frenteForm");
    if (!form) return;
    ponerModoFormulario(form, data);

    document.getElementById("NOMBRE_FRENTE").value = data.NOMBRE_FRENTE || "";
    document.getElementById("UBICACION").value = data.UBICACION || "";

    // ZONA y CONTRATOS tienen que rellenarse SI O SI: el formulario los manda
    // siempre, asi que lo que quede en pantalla es lo que se guarda. Si no se
    // pintan aqui, seleccionar un frente en el buscador y darle "Guardar"
    // sin tocar nada le BORRA la zona (la que sale en el Acta de Traslado) y le
    // mete los contratos del frente que se estuviera viendo antes.
    const zona = document.getElementById("ZONA");
    if (zona) zona.value = data.ZONA || "";
    if (typeof window.frentesSetContratos === "function") {
        window.frentesSetContratos(data.CONTRATOS);
    }

    // Los cinco responsables: nombre, cargo, cédula y filtro de firma. El bloque queda
    // abierto si trae datos y recogido si no (lo mismo que hace la vista al abrir /edit).
    for (let i = 1; i <= 5; i++) {
        llenarResponsable(i, data);
    }

    // Dropdowns (Tipo/Estatus)
    document.getElementById("input_tipo").value = data.TIPO_FRENTE;
    // El nombre en pantalla del tipo (p. ej. TRAMITE_VENTA → "TRÁMITE DE VENTA") sale de la propia opción.
    const opcionTipo = document.querySelector('#tipoSelect .dropdown-item[data-value="' + data.TIPO_FRENTE + '"]');
    document.getElementById("label_tipo").innerText = opcionTipo ? opcionTipo.textContent.trim() : data.TIPO_FRENTE;
    document.getElementById("input_estatus").value = data.ESTATUS_FRENTE;
    document.getElementById("label_estatus").innerText = data.ESTATUS_FRENTE;

    // Update Search Dropdown Input (to show what we are editing)
    const searchInput = document.getElementById("filterSearchInput");
    if (searchInput) {
        searchInput.value = data.NOMBRE_FRENTE;
        const clearIcon = document.getElementById("btn_clear_search_frente");
        if (clearIcon) clearIcon.style.display = "block";
    }
}

// Escribe el responsable n (de los datos del frente, o vacío si data es null) y abre su
// bloque solo si trae nombre, cargo o cédula.
function llenarResponsable(n, data) {
    let conDatos = false;
    ["NOM", "CAR", "CED"].forEach((campo) => {
        const input = document.getElementById("RESP_" + n + "_" + campo);
        const valor = (data && data["RESP_" + n + "_" + campo]) || "";
        if (input) input.value = valor;
        if (valor) conDatos = true;
    });
    const equ = (data && data["RESP_" + n + "_EQU"]) || "";
    const input = document.getElementById("input_resp" + n + "_equ");
    const label = document.getElementById("label_resp" + n + "_equ");
    if (input) input.value = equ;
    if (label) label.innerText = equ || "SIN FILTRO";
    window.toggleResp(n, conDatos);
}

// Acordeón de responsables: clic en el encabezado abre/cierra su bloque. Con `abrir`
// (true/false) lo deja en ese estado en vez de alternar.
window.toggleResp = function (n, abrir) {
    const body = document.getElementById("respBody" + n);
    const head = document.getElementById("respHead" + n);
    if (!body) return;
    const cerrado = body.classList.toggle("resp-collapsed", abrir === undefined ? undefined : !abrir);
    if (head) head.classList.toggle("cerrado", cerrado);
};

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
    // Limpiar la busqueda NO descarga el frente que se este editando: para volver
    // a "Registrar nuevo" esta el boton Cancelar (window.resetFrentesForm).
};

window.selectFrenteSPA = function (id) {
    // Close dropdown
    const dropdown = document.getElementById("frenteSearchDropdown");
    if (dropdown) dropdown.classList.remove("active");

    // El buscador se queda con el nombre del frente elegido para que se vea cual se
    // esta editando: lo escribe populateFrenteForm() al llegar la respuesta.

    if (window.showPreloader) window.showPreloader();

    window.apiFetch(`/admin/frentes/${id}/edit?json=true`, { headers: { 'Accept': "application/json", 'X-Requested-With': 'XMLHttpRequest' } })
        .then((response) => {
            if (!response.ok) throw new Error("HTTP Status " + response.status);
            return response.json();
        })
        .then((data) => {
            if (window.hidePreloader) window.hidePreloader();
            populateFrenteForm(data);
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

    window.apiFetch(url, { headers: { 'Accept': "application/json", 'X-Requested-With': 'XMLHttpRequest' },
        method: "POST",
        body: formData
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
        ponerModoFormulario(form, null);

        // form.reset() devuelve cada campo a su valor RENDERIZADO, no a vacio: abierto
        // en /frentes/{id}/edit, deja escritos los datos de ESE frente. Cancelar pasaba
        // el formulario a modo "Registrar" con el nombre y la ubicacion del frente ya
        // puestos: al guardar saltaba "Ya existe un frente con este nombre" o, si se
        // retocaba el nombre, se creaba un duplicado con la ubicacion heredada.
        // Los contratos, ademas, son DOM dibujado por JS que reset() ni mira.
        ["NOMBRE_FRENTE", "UBICACION", "ZONA"].forEach((id) => {
            const campo = document.getElementById(id);
            if (campo) campo.value = "";
        });
        if (typeof window.frentesSetContratos === "function") {
            window.frentesSetContratos("");
        }

        document.getElementById("input_tipo").value = "";
        document.getElementById("label_tipo").innerText = "Seleccione Tipo...";
        document.getElementById("input_estatus").value = "";
        document.getElementById("label_estatus").innerText =
            "Seleccione Estatus...";

        // Los cinco responsables, vacíos y recogidos. Se limpian a mano por lo mismo que
        // arriba (en /edit vienen renderizados con los datos del frente).
        for (let i = 1; i <= 5; i++) {
            llenarResponsable(i, null);
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

    // Se busca por ID, no por nombre: una EDICION que cambia el nombre seguia
    // sin encontrar su propio item y dejaba el frente dos veces en la lista
    // (con el nombre viejo y con el nuevo).
    const id = String(frente.ID_FRENTE);
    let existing = null;
    const items = list.getElementsByClassName("search-result-item");
    for (let item of items) {
        if ((item.dataset.id || "") === id) { existing = item; break; }
    }

    // Ya estaba listado: solo se refresca el texto por si le cambiaron el nombre.
    if (existing) {
        existing.dataset.name = frente.NOMBRE_FRENTE;
        existing.textContent = frente.NOMBRE_FRENTE;
        return;
    }

    // Create new item
    const div = document.createElement("div");
    div.className = "dropdown-item search-result-item";
    div.dataset.id = id;
    div.dataset.name = frente.NOMBRE_FRENTE;
    div.textContent = frente.NOMBRE_FRENTE;
    div.onclick = function () {
        window.selectFrenteSPA(id);
    };

    // Arriba del todo: el recien creado es el que el usuario acaba de tocar.
    list.insertBefore(div, list.firstChild);

    // Hide no results
    const noMsg = document.getElementById("no-results-msg");
    if (noMsg) noMsg.style.display = "none";
};

// Quita un frente del desplegable de búsqueda al eliminarlo, para no dejarlo listado. Por ID: el nombre se puede
// editar, el ID no cambia nunca.
window.removeFromSearchList = function (id) {
    const list = document.getElementById("frenteItemsList");
    if (!list || id == null) return;
    const target = String(id).trim();
    const items = list.getElementsByClassName("search-result-item");
    for (let item of items) {
        if ((item.dataset.id || "") === target) {
            item.remove();
            break;
        }
    }
};

// --- CANCELAR Y ELIMINAR ---
// Cancelar: editando un frente vuelve a "Registrar nuevo"; con el formulario en modo
// registro, sale al menú.
window.cancelarFrente = function () {
    const id = document.getElementById("ID_FRENTE");
    if (id && id.value) {
        window.resetFrentesForm();
        return;
    }
    if (window.navigateTo) window.navigateTo("/menu");
    else window.location.href = "/menu";
};

// Eliminar (papelera junto al buscador y modal "Sin equipos"): UN solo flujo. Pide
// confirmación, borra por AJAX (borrado lógico) y, si sale bien, quita el frente del
// buscador, vuelve el formulario a "Registrar" si era el que se editaba y refresca el
// modal si está abierto. El servidor lo rechaza (422) si tiene equipos, auxiliares o
// stock de almacén, y ese mensaje es el que se muestra.
window.eliminarFrenteSPA = function (id, nombre) {
    if (!id) return;
    window.showModal({
        type: "warning",
        title: "Eliminar Frente",
        message: '¿Eliminar el frente "' + window.escapeHtml(nombre || "") + '"?\n\nDejará de aparecer en las listas; el historial lo conserva.',
        confirmText: "Sí, eliminar",
        cancelText: "Cancelar",
        onConfirm: function () { borrarFrente(id); },
    });
};

function borrarFrente(id) {
    if (window.showPreloader) window.showPreloader();
    window.apiFetch("/admin/frentes/" + id, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        method: "DELETE",
    })
        .then((r) => r.json().catch(() => ({})).then((body) => ({ ok: r.ok, body })))
        .then(({ ok, body }) => {
            if (window.hidePreloader) window.hidePreloader();
            if (!ok || !body.success) {
                showNotification("error", body.message || "No se pudo eliminar el frente.");
                return;
            }
            showNotification("success", body.message || "Frente eliminado.");
            window.removeFromSearchList(id);
            const actual = document.getElementById("ID_FRENTE");
            if (actual && String(actual.value) === String(id)) window.resetFrentesForm();
            if (document.getElementById("sinEquiposList")) window.cargarFrentesSinEquipos();
        })
        .catch(() => {
            if (window.hidePreloader) window.hidePreloader();
            showNotification("error", "Error de red al eliminar el frente.");
        });
}

// --- MODAL "FRENTES SIN EQUIPOS" (candidatos a eliminar) ---
window.abrirModalSinEquipos = function () {
    const previo = document.getElementById("sinEquiposOverlay");
    if (previo) previo.remove();

    const overlay = document.createElement("div");
    overlay.id = "sinEquiposOverlay";
    overlay.className = "sin-equipos-overlay";
    overlay.innerHTML =
        '<div class="sin-equipos-modal">' +
            '<div class="sin-equipos-cab">' +
                '<i class="material-icons">domain_disabled</i>' +
                "<h2>Frentes sin equipos</h2>" +
                '<button type="button" id="btnCloseSinEquipos" title="Cerrar"><i class="material-icons">close</i></button>' +
            "</div>" +
            '<div class="sin-equipos-cuerpo"><div id="sinEquiposList" class="sin-equipos-lista">' +
                '<div class="sin-equipos-vacio"><i class="material-icons sin-equipos-gira">sync</i></div>' +
            "</div></div>" +
        "</div>";
    document.body.appendChild(overlay);
    overlay.addEventListener("click", (e) => { if (e.target === overlay) overlay.remove(); });
    document.getElementById("btnCloseSinEquipos").onclick = () => overlay.remove();

    window.cargarFrentesSinEquipos();
};

window.cargarFrentesSinEquipos = function () {
    const list = document.getElementById("sinEquiposList");
    if (!list) return;
    window.apiFetch("/admin/frentes/sin-equipos", { headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
        .then((r) => r.json())
        .then((data) => {
            if (!data.frentes || data.frentes.length === 0) {
                list.innerHTML = '<div class="sin-equipos-vacio"><i class="material-icons">check_circle</i>Todos los frentes tienen equipos asignados</div>';
                return;
            }
            const esc = window.escapeHtml;
            list.innerHTML = data.frentes.map((f) =>
                '<div class="sin-equipos-fila">' +
                    '<div class="sin-equipos-ico"><i class="material-icons">domain_disabled</i></div>' +
                    '<div class="sin-equipos-datos">' +
                        '<div class="sin-equipos-nombre">' + esc(f.nombre) + " <span>" + esc(f.estatus || "—") + "</span></div>" +
                        (f.ubicacion ? '<div class="sin-equipos-ubic"><i class="material-icons">place</i>' + esc(f.ubicacion) + "</div>" : "") +
                    "</div>" +
                    '<button type="button" class="btn-primary-maquinaria btn-eliminar-frente" title="Eliminar este frente" aria-label="Eliminar este frente"' +
                        ' data-id="' + f.id + '" data-nombre="' + esc(f.nombre) + '"><i class="material-icons">delete_outline</i></button>' +
                "</div>"
            ).join("");
            // data-* y no onclick con el nombre dentro del JS: un nombre con comillas no rompe nada.
            list.querySelectorAll("button[data-id]").forEach((b) => {
                b.onclick = () => window.eliminarFrenteSPA(b.dataset.id, b.dataset.nombre);
            });
        })
        .catch(() => {
            list.innerHTML = '<div class="sin-equipos-vacio sin-equipos-error">Error al cargar los frentes.</div>';
        });
};

function showNotification(type, message) {
    // Toast efimero (auto-cierra en ~3s) en vez de modal con "Aceptar":
    // las confirmaciones de exito/error de operaciones puntuales no requieren
    // que el usuario tenga que cerrar manualmente. Mismo patron que el resto
    // de modulos (equipos, auxiliares, catalogo).
    // window.toast (dom_helpers.js) devuelve false si aun no hay toasts: solo
    // entonces se cae al modal.
    if (window.toast(message, type)) return;
    window.showModal({
        type: type,
        title: type === "success" ? "Éxito" : "Error",
        message: message,
        confirmText: "Aceptar",
        hideCancel: true,
    });
}
