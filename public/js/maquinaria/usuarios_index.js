// usuarios_index.js - Usuarios Module Logic with Filters
// Version: 4.0 - Equipos-Style Filter Architecture

// Window-level functions for dropdown interaction
window.clearUsuariosFilter = function (filterName) {
    if (filterName === 'id_frente' || filterName === 'frente_filter') {
        // Use the new generic function from uicomponents.js
        window.clearDropdownFilter('frenteFilterSelect');
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

// Main load function - Equipos-style architecture
window.loadUsuarios = function (url = null) {
    const tableBody = document.getElementById('usuariosTableBody');
    if (!tableBody) return;

    let baseUrl = url || window.location.pathname;
    const searchInput = document.getElementById('searchInput');
    const frenteInput = document.querySelector('input[name="id_frente"]');
    const fechaInput = document.querySelector('input[name="fecha_creacion"]');

    // Unified Filter Object (Single Source of Truth)
    const filters = {
        search: searchInput?.value,
        id_frente: (frenteInput?.value !== '') ? frenteInput?.value : null,
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

    // OPTIMIZATION: Check if there are any meaningful filters
    // Strategy: Only skip server request if EVERYTHING is null/empty (truly no input from user)
    const hasAnyInput = Object.values(filters).some(value => {
        if (value === null || value === '' || value === undefined) return false;
        if (typeof value === 'string' && value.trim() === '') return false;
        if (value === 'all') return true; // 'all' is a valid filter
        return true; // Any non-empty value means user provided input
    });

    // If truly no input at all, clear UI without server request
    if (!hasAnyInput) {
        // Clear table with friendly message
        tableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; padding: 40px; color: #94a3b8; font-style: italic;">SELECCIONE UN FILTRO PARA VISUALIZAR LOS USUARIOS</td></tr>';
        tableBody.style.opacity = '1';

        // Clear pagination
        const paginationContainer = document.getElementById('usuariosPagination');
        if (paginationContainer) paginationContainer.innerHTML = '';

        // Clear user count badge
        const badgeText = document.getElementById('user-count-text');
        const badge = document.getElementById('user-count-badge');
        if (badgeText) badgeText.innerText = '0';
        if (badge) badge.innerText = '0';

        // Update URL to reflect empty state
        window.history.pushState(null, '', window.location.pathname);
        if (window.hidePreloader) window.hidePreloader();

        return Promise.resolve();
    }

    const finalUrl = baseUrl + '?' + params.toString();
    tableBody.style.opacity = '0.5';
    if (window.showPreloader) window.showPreloader();

    fetch(finalUrl, {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json'
        }
    })
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

// ── Autocompletado del buscador (nombre / correo) ───────────────────────────
// La lista completa de usuarios (nombre + correo) viene embebida como JSON en el
// DOM (#usuariosSugerenciasData). Filtramos en el cliente al escribir y mostramos
// las coincidencias en #searchSuggest. Al elegir una, se rellena el input y se
// dispara loadUsuarios() de inmediato.
function usuariosNorm(s) {
    return s ? String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase() : '';
}

function escHtmlUsuarios(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
}

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

function renderSearchSuggest(term) {
    const box = document.getElementById('searchSuggest');
    if (!box) return;
    const t = usuariosNorm((term || '').trim());
    if (t.length < 2) { hideSearchSuggest(); return; }

    const matches = getUsuariosSugerencias().filter(function (u) {
        return u._n.indexOf(t) > -1; // _n = nombre+correo ya normalizados (sin normalize() por tecla)
    }).slice(0, 8);

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
        searchInput.addEventListener('keyup', function (e) {
            const val = this.value;
            const clearBtn = document.getElementById('btn_clear_search');
            if (clearBtn) clearBtn.style.display = (val.length > 0) ? 'block' : 'none';

            // Escape cierra las sugerencias. Enter aplica el filtro vía submit del
            // form (más abajo) — aquí solo cerramos la lista.
            if (e && e.key === 'Escape') { hideSearchSuggest(); return; }
            if (e && e.key === 'Enter')  { hideSearchSuggest(); return; }

            // Al ESCRIBIR solo se muestran sugerencias; la tabla NO se filtra en cada
            // tecla. El filtro se aplica al ELEGIR una sugerencia (handler de clic en
            // #searchSuggest .usuarios-suggest-item → loadUsuarios) o al presionar Enter.
            renderSearchSuggest(val);

            // Excepción: si el campo queda vacío, recargar para limpiar el filtro
            // (no tendría sentido dejar la tabla filtrada con el buscador en blanco).
            if (val.length === 0) {
                clearTimeout(window.searchTimeout);
                window.searchTimeout = setTimeout(() => window.loadUsuarios(), 300);
            }
        });
        // Al enfocar, si ya hay texto, reabrir las sugerencias.
        searchInput.addEventListener('focus', function () {
            if (this.value.trim().length >= 2) renderSearchSuggest(this.value);
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

