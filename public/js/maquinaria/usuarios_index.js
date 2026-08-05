// usuarios_index.js - Usuarios Module Logic with Filters
// Version: 4.0 - Equipos-Style Filter Architecture

// Window-level functions for dropdown interaction
window.clearUsuariosFilter = function (filterName) {
    if (filterName === 'id_frente' || filterName === 'frente_filter') {
        // Use the new generic function from uicomponents.js
        window.clearDropdownFilter('frenteFilterSelect');
    } else if (filterName === 'id_rol' || filterName === 'rol_filter') {
        window.clearDropdownFilter('rolFilterSelect');
    } else if (filterName === 'search') {
        const input = document.getElementById('searchInput');
        if (input) input.value = '';
        const clearBtn = document.getElementById('btn_clear_search');
        if (clearBtn) clearBtn.style.display = 'none';
        // Cerrar la lista de sugerencias si quedó abierta con resultados previos.
        if (typeof hideSearchSuggest === 'function') hideSearchSuggest();
    }

    // Reload usuarios after clearing filter
    window.loadUsuarios();
};

// Filtra la tabla por un usuario concreto (clic en el panel "Usuarios Activos").
// Reutiliza el buscador: mete el CORREO (único) como término, así el filtro de la
// tabla —que matchea por nombre O correo— devuelve exactamente esa fila. No añade
// una vía de filtrado nueva: es el mismo camino que escribir en el buscador.
window.filtrarUsuarioActivo = function (correo) {
    const input = document.getElementById('searchInput');
    if (!input) return;
    input.value = correo || '';
    const clearBtn = document.getElementById('btn_clear_search');
    if (clearBtn) clearBtn.style.display = correo ? 'block' : 'none';
    if (typeof hideSearchSuggest === 'function') hideSearchSuggest();
    window.loadUsuarios();
    // En móvil el panel de activos queda arriba/aparte: llevar la vista a la tabla.
    const table = document.getElementById('usuariosTableBody');
    if (table && table.scrollIntoView) table.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
};

// Main load function - Equipos-style architecture
window.loadUsuarios = function (url = null) {
    const tableBody = document.getElementById('usuariosTableBody');
    if (!tableBody) return;

    let baseUrl = url || window.location.pathname;
    const searchInput = document.getElementById('searchInput');
    const frenteInput = document.querySelector('input[name="id_frente"]');
    const rolInput = document.querySelector('input[name="id_rol"]');
    const fechaInput = document.querySelector('input[name="fecha_creacion"]');

    // Unified Filter Object (Single Source of Truth)
    const filters = {
        search: searchInput?.value,
        id_frente: (frenteInput?.value !== '') ? frenteInput?.value : null,
        id_rol: (rolInput?.value !== '') ? rolInput?.value : null,
        fecha_creacion: fechaInput?.value || null
    };

    const params = new URLSearchParams();

    // Cleanly append only valid filter values (non-null, non-empty)
    Object.entries(filters).forEach(([key, value]) => {
        if (value && typeof value === 'string' && value.trim() !== '') {
            params.append(key, value.trim());
        }
    });

    // Handle pagination URL
    if (url && url.includes('page=')) {
        try {
            const urlObj = new URL(url, window.location.origin);
            const page = urlObj.searchParams.get('page');
            if (page) params.append('page', page);
            baseUrl = urlObj.pathname;
        } catch (e) {
            console.error('URL parsing error:', e);
        }
    }

    // Sin filtros = mostrar TODOS los usuarios (el backend pagina sin exigir filtro).
    // Antes aquí se cortaba y se pintaba "SELECCIONE UN FILTRO", dejando la tabla en blanco
    // al borrar el buscador — inconsistente con la carga inicial (que SÍ muestra todos).
    // Ahora la tabla siempre se llena: buscador vacío → todos; con texto → filtrado.
    const finalUrl = baseUrl + '?' + params.toString();
    tableBody.style.opacity = '0.5';
    if (window.showPreloader) window.showPreloader();

    window.apiFetch(finalUrl)
        .then(response => {
            if (!response.ok) throw new Error('Network response was not ok');
            return response.json();
        })
        .then(data => {
            tableBody.innerHTML = data.html;
            tableBody.style.opacity = '1';

            const paginationContainer = document.getElementById('usuariosPagination');
            if (paginationContainer && data.pagination !== undefined) {
                paginationContainer.innerHTML = data.pagination;
            }

            if (data.count !== undefined) {
                const badgeText = document.getElementById('user-count-text');
                if (badgeText) {
                    badgeText.innerText = data.count;
                } else {
                    const badge = document.getElementById('user-count-badge');
                    if (badge) badge.innerText = data.count;
                }
            }

            window.history.pushState(null, '', finalUrl);
            if (window.hidePreloader) window.hidePreloader();
        })
        .catch(error => {
            console.error('Error loading usuarios:', error);
            tableBody.style.opacity = '1';
            if (window.hidePreloader) window.hidePreloader();
        });
};

// Event Listener for Dropdown Selection (Decoupled architecture)
window.addEventListener('dropdown-selection', function (e) {
    if (e.detail.inputName === 'frente_filter' && document.getElementById('usuariosTableBody')) {
        const clearBtn = document.getElementById('btn_clear_frente');
        if (clearBtn) {
            clearBtn.style.display = e.detail.value ? 'block' : 'none';
        }
        window.loadUsuarios();
    }
});

// Pagination click handler
document.addEventListener('click', function (e) {
    const link = e.target.closest('#usuariosPagination a');
    if (link) {
        e.preventDefault();
        window.loadUsuarios(link.getAttribute('href'));
    }
});

// Móvil: tocar una tarjeta de usuario muestra su detalle (fecha de creación + frentes) en
// una burbuja flotante por encima (en escritorio sale al pasar el mouse). Solo en el layout
// de tarjeta (≤768px). Una sola burbuja abierta a la vez; se cierra al tocar fuera o en un
// botón de acción. Listener único a nivel documento (SPA-safe: sobrevive al re-render).
document.addEventListener('click', function (e) {
    if (!window.matchMedia || !window.matchMedia('(max-width: 768px)').matches) return;
    const body = document.getElementById('usuariosTableBody');
    if (!body) return;
    const fila = e.target.closest('.table-usuarios-mobile tbody tr');
    const enAccion = e.target.closest('a, button, .btn-action-maquinaria');
    const abiertas = body.querySelectorAll('tr.tip-abierto');
    // Tocar fuera de una tarjeta (o en un botón de acción) → cerrar lo que hubiera abierto.
    if (!fila || !body.contains(fila) || enAccion) {
        abiertas.forEach(function (t) { t.classList.remove('tip-abierto'); });
        return;
    }
    const yaAbierta = fila.classList.contains('tip-abierto');
    abiertas.forEach(function (t) { t.classList.remove('tip-abierto'); }); // solo una a la vez
    if (!yaAbierta) fila.classList.add('tip-abierto');
});

// ── Autocompletado del buscador (nombre / correo) ───────────────────────────
// La lista completa de usuarios (nombre + correo) viene embebida como JSON en el
// DOM (#usuariosSugerenciasData). Filtramos en el cliente al escribir y mostramos
// las coincidencias en #searchSuggest. Al elegir una, se rellena el input y se
// dispara loadUsuarios() de inmediato.
function usuariosNorm(s) {
    return s ? String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() : '';
}

// Delegado al helper central (dom_helpers.js).
function escHtmlUsuarios(s) { return window.escapeHtml(s); }

// Cache: parseamos el JSON y PRE-NORMALIZAMOS nombre+correo UNA sola vez. Antes se
// re-parseaba y re-normalizaba la lista COMPLETA de usuarios en CADA tecla → esa era
// la lentitud. Se reconstruye solo si el nodo de datos cambia (recarga que lo reemplace).
let _usuariosSugCache = null;
let _usuariosSugNode = null;
function getUsuariosSugerencias() {
    const node = document.getElementById('usuariosSugerenciasData');
    if (!node) return [];
    if (_usuariosSugCache && _usuariosSugNode === node) return _usuariosSugCache;
    try {
        const list = JSON.parse(node.dataset.list || '[]');
        const arr = Array.isArray(list) ? list : [];
        // _n = nombre+correo ya normalizados → el filtro por tecla es solo un indexOf
        // (sin volver a llamar normalize() por cada usuario en cada pulsación).
        _usuariosSugCache = arr.map(function (u) {
            return { nombre: u.nombre, correo: u.correo, _n: usuariosNorm(u.nombre) + ' ' + usuariosNorm(u.correo) };
        });
        _usuariosSugNode = node;
        return _usuariosSugCache;
    } catch (e) {
        return [];
    }
}

function hideSearchSuggest() {
    const box = document.getElementById('searchSuggest');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

function renderSearchSuggest(term, browseIfEmpty) {
    const box = document.getElementById('searchSuggest');
    if (!box) return;
    const t = usuariosNorm((term || '').trim());
    const all = getUsuariosSugerencias();
    let matches;
    if (t.length < 1) {
        // Vacío: si se pidió "browse" (al enfocar/click en el filtro), mostramos los primeros
        // usuarios para poder elegir SIN escribir; si no (p.ej. al borrar), ocultamos la lista.
        if (!browseIfEmpty) { hideSearchSuggest(); return; }
        matches = all.slice(0, 8);
    } else {
        // Desde la PRIMERA letra: filtra nombre+correo (ya normalizados → indexOf por tecla).
        matches = all.filter(function (u) { return u._n.indexOf(t) > -1; }).slice(0, 8);
    }

    if (!matches.length) {
        box.innerHTML = '<div style="padding:10px 15px; font-size:13px; color:#94a3b8;">Sin coincidencias</div>';
        box.style.display = 'block';
        return;
    }

    box.innerHTML = matches.map(function (u) {
        return '<div class="usuarios-suggest-item" data-value="' + escHtmlUsuarios(u.nombre) + '" ' +
            'style="padding:9px 14px; border-radius:8px; cursor:pointer; display:flex; flex-direction:column; gap:2px;" ' +
            'onmouseover="this.style.background=\'#f0f4f8\'" onmouseout="this.style.background=\'transparent\'">' +
            '<span style="font-size:14px; font-weight:600; color:#1e3a5f;">' + escHtmlUsuarios(u.nombre) + '</span>' +
            '<span style="font-size:12px; color:#64748b;">' + escHtmlUsuarios(u.correo) + '</span>' +
            '</div>';
    }).join('');
    box.style.display = 'block';
}

// Listeners globales (una sola vez): elegir una sugerencia / cerrar al hacer clic fuera.
if (!window.__usuariosSuggestBound) {
    window.__usuariosSuggestBound = true;

    document.addEventListener('click', function (e) {
        const item = e.target.closest('#searchSuggest .usuarios-suggest-item');
        if (item) {
            const input = document.getElementById('searchInput');
            if (input) {
                input.value = item.getAttribute('data-value') || '';
                const clearBtn = document.getElementById('btn_clear_search');
                if (clearBtn) clearBtn.style.display = input.value.length > 0 ? 'block' : 'none';
            }
            hideSearchSuggest();
            if (window.loadUsuarios) window.loadUsuarios();
            return;
        }
        // Clic fuera del buscador → cerrar sugerencias.
        if (!e.target.closest('#search-form') && !e.target.closest('#searchSuggest')) {
            hideSearchSuggest();
        }
    });
}

// Initialize on page load
function initUsuarios() {
    if (!document.getElementById('usuariosTableBody')) return;

    const searchInput = document.getElementById('searchInput');
    // Guard: only attach listener once per DOM instance
    if (searchInput && !searchInput.dataset.usuariosInitialized) {
        searchInput.dataset.usuariosInitialized = 'true';
        // Sugerencias en el evento `input` (NO `keyup`): `input` dispara en CADA cambio de
        // valor — tipeo, pegar, autofill, IME, tipeo rapido — asi las sugerencias salen
        // SIEMPRE y consistentes. Con `keyup` a veces no aparecian (paste/autofill/teclas
        // que no liberan keyup). Al ESCRIBIR solo se muestran sugerencias; la tabla NO se
        // filtra por tecla (el filtro se aplica al ELEGIR una sugerencia o con Enter).
        searchInput.addEventListener('input', function () {
            const val = this.value;
            const clearBtn = document.getElementById('btn_clear_search');
            if (clearBtn) clearBtn.style.display = (val.length > 0) ? 'block' : 'none';

            renderSearchSuggest(val);

            // Si el campo queda vacío, recargar para limpiar el filtro.
            if (val.length === 0) {
                clearTimeout(window.searchTimeout);
                window.searchTimeout = setTimeout(() => window.loadUsuarios(), 300);
            }
        });
        // Escape cierra las sugerencias (Enter aplica el filtro vía submit del form, más abajo).
        searchInput.addEventListener('keydown', function (e) {
            if (e && e.key === 'Escape') hideSearchSuggest();
        });
        // Al enfocar, si ya hay texto, reabrir las sugerencias.
        searchInput.addEventListener('focus', function () {
            // Al hacer click/enfocar el filtro: mostrar sugerencias SIEMPRE (browse) — con
            // texto filtra, vacío muestra los primeros usuarios para elegir sin escribir.
            renderSearchSuggest(this.value, true);
        });
    }

    const form = document.getElementById('search-form');
    if (form) {
        form.onsubmit = function (e) {
            e.preventDefault();
            // Enter en el buscador: cerrar sugerencias y filtrar con el texto escrito.
            if (typeof hideSearchSuggest === 'function') hideSearchSuggest();
            window.loadUsuarios();
            return false;
        };
    }
}


// Register with Module Manager for SPA compatibility
if (typeof ModuleManager !== 'undefined') {
    ModuleManager.register('usuarios',
        () => document.getElementById('usuariosTableBody') !== null,
        initUsuarios
    );
}

// Direct init fallback (ModuleManager may init before modules register)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initUsuarios);
} else {
    initUsuarios();
}

// SPA navigation listener
window.addEventListener('spa:contentLoaded', function () {
    if (document.getElementById('usuariosTableBody')) {
        initUsuarios();
    }
});

// confirmDelete and closeDeleteModal removed - using global versions in uicomponents.js

