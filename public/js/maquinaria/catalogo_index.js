// --- Delete Modal Logic (AJAX via standardModal) ---
window.confirmDeleteCatalogo = function (id, modelName) {
    if (!id || String(id).trim() === '') {
        console.error('ID missing for confirmDeleteCatalogo');
        return;
    }

    window.showModal({
        type: 'danger',
        title: '¿Eliminar registro?',
        message: `¿Estás seguro de que deseas eliminar "<strong>${modelName}</strong>"?`,
        confirmText: 'Eliminar',
        cancelText: 'Cancelar',
        onConfirm: async function () {
            if (typeof window.showPreloader === 'function') window.showPreloader();
            try {
                const response = await fetch(`/admin/catalogo/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    }
                });

                let data = {};
                try { data = await response.json(); } catch (e) {}

                if (response.ok) {
                    if (window.showToast) {
                        window.showToast(data.message || 'Registro eliminado correctamente.', 'success');
                    } else if (window.showModal) {
                        window.showModal({
                            type: 'success',
                            title: 'Eliminado',
                            message: data.message || 'Registro eliminado correctamente.',
                            hideCancel: true
                        });
                    }
                    window.loadCatalogo();
                } else {
                    throw new Error(data.message || 'Error al eliminar');
                }
            } catch (error) {
                console.error('Delete Error:', error);
                if (window.showModal) {
                    window.showModal({ type: 'error', title: 'Error', message: error.message, hideCancel: true });
                }
            } finally {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
            }
        }
    });
};

// ─────────────────────────────────────────────────────────────────────
//  CATALOGO — Scroll infinito
//  Reemplaza al paginado clasico « Anterior / Siguiente ». Un
//  IntersectionObserver vigila el centinela (#catalogoSentinel); cuando
//  entra al viewport se pide la siguiente pagina y sus tarjetas se
//  AGREGAN al final del grid.
//  Optimizado: no escucha el evento 'scroll' (que dispara decenas de
//  veces/seg) — usa IntersectionObserver; rootMargin precarga antes de
//  llegar al fondo; el flag 'loading' evita peticiones duplicadas; un
//  AbortController cancela una carga en curso si cambia un filtro.
// ─────────────────────────────────────────────────────────────────────

// Estado del scroll infinito. page = ultima pagina en el grid;
// hasMore = el server indico que quedan paginas; loading = fetch en curso.
window.catState = window.catState || { page: 1, hasMore: false, loading: false };

// AbortController de la peticion en curso — compartido a proposito: un
// cambio de filtro cancela una carga incremental que estuviera a medias.
window.currentRequestController = null;

// Arma la URL de la peticion con los filtros activos + la pagina pedida.
function catBuildUrl(page) {
    const params = new URLSearchParams();
    const add = function (key, selector) {
        const el = document.querySelector(selector);
        const v = (el && el.value ? el.value : '').trim();
        if (v) params.append(key, v);
    };
    add('modelo', 'input[name="modelo"]');
    add('anio',   'input[name="anio"]');
    add('tipo',   'input[name="tipo"]');
    params.append('ajax_load', '1');
    if (page && page > 1) params.append('page', String(page));
    return '/admin/catalogo?' + params.toString();
}

// Peticion AJAX al catalogo. Cancela cualquier peticion anterior (un cambio
// de filtro invalida una carga incremental en curso). Devuelve el JSON, o
// null si esta peticion fue abortada por otra mas nueva.
async function catFetch(url) {
    if (window.currentRequestController) window.currentRequestController.abort();
    const controller = new AbortController();
    window.currentRequestController = controller;
    let resp;
    try {
        resp = await fetch(url, {
            signal: controller.signal,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
    } catch (e) {
        if (e.name === 'AbortError') return null;
        throw e;
    }
    // Solo soltamos el controller si sigue siendo el nuestro (no lo piso una
    // peticion mas reciente).
    if (window.currentRequestController === controller) window.currentRequestController = null;
    if (!resp.ok) throw new Error('Network response was not ok');
    return await resp.json();
}

// Sincroniza el centinela con el estado: muestra "no hay mas" y arma o
// desarma el observer segun queden paginas. Re-observar (disconnect +
// observe) fuerza al observer a re-evaluar: si el centinela sigue visible
// tras agregar tarjetas, se encadena otra carga hasta llenar el viewport.
function catRefreshSentinel() {
    const tableBody = document.getElementById('catalogoTableBody');
    const sentinel  = document.getElementById('catalogoSentinel');
    const endMsg    = document.getElementById('catalogoEndMsg');
    // "No hay mas" solo si hay tarjetas y no quedan paginas — con 0
    // resultados ya se muestra el cartel .cat-empty del partial (no duplicar).
    const hasCards  = !!(tableBody && tableBody.querySelector('.cat-card'));
    if (endMsg) endMsg.style.display = (!window.catState.hasMore && hasCards) ? '' : 'none';
    if (!sentinel || !('IntersectionObserver' in window)) return;

    if (!window.catScrollObserver) {
        window.catScrollObserver = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) catLoadMore();
        }, { rootMargin: '800px 0px' });
    }
    window.catScrollObserver.disconnect();
    if (window.catState.hasMore) window.catScrollObserver.observe(sentinel);
}

// Carga la SIGUIENTE pagina y agrega sus tarjetas al final del grid.
async function catLoadMore() {
    const st = window.catState;
    if (st.loading || !st.hasMore) return;
    const tableBody = document.getElementById('catalogoTableBody');
    if (!tableBody) return;

    st.loading = true;
    const spinner = document.getElementById('catalogoLoadingSpinner');
    if (spinner) spinner.style.display = '';

    try {
        const data = await catFetch(catBuildUrl(st.page + 1));
        st.loading = false;
        if (spinner) spinner.style.display = 'none';
        // null = abortada por un cambio de filtro; loadCatalogo() ya recarga.
        if (!data) return;

        if (data.html) tableBody.insertAdjacentHTML('beforeend', data.html);
        st.page    = data.page || (st.page + 1);
        st.hasMore = !!data.hasMore;
        catRefreshSentinel();
    } catch (error) {
        st.loading = false;
        if (spinner) spinner.style.display = 'none';
        // Error real: NO re-armamos el observer (evita bucle de errores) —
        // reintenta solo cuando el usuario vuelva a scrollear el centinela.
        console.error('Error en scroll infinito del catalogo:', error);
    }
}

// Carga la PRIMERA pagina y REEMPLAZA el grid. La usan el cambio de filtro
// (catSubmit en index.blade.php) y el borrado de un modelo. Reinicia el
// scroll infinito a la pagina 1.
window.loadCatalogo = async function (showSpinner = true) {
    const tableBody = document.getElementById('catalogoTableBody');
    if (!tableBody) return;

    tableBody.style.opacity = '0.5';
    if (showSpinner && typeof window.showPreloader === 'function') window.showPreloader();

    try {
        const data = await catFetch(catBuildUrl(1));
        // null = abortada por una peticion mas nueva; esa se hace cargo.
        if (!data) { tableBody.style.opacity = '1'; return; }

        tableBody.innerHTML = data.html;
        tableBody.style.opacity = '1';

        // Reinicia el estado del scroll infinito a la pagina recien cargada.
        window.catState.page    = data.page || 1;
        window.catState.hasMore = !!data.hasMore;
        window.catState.loading = false;
        catRefreshSentinel();

        // Stats sidebar: solo en el reemplazo (no cambia durante el scroll).
        const statsContainer = document.getElementById('statsSidebarContainer');
        if (statsContainer && data.stats) statsContainer.innerHTML = data.stats;

        // URL navegable con los filtros aplicados (sin ajax_load).
        const cleanUrl = new URL(catBuildUrl(1), window.location.origin);
        cleanUrl.searchParams.delete('ajax_load');
        window.history.pushState({}, '', cleanUrl.toString());
    } catch (error) {
        console.error('Error loading catalogo:', error);
        tableBody.style.opacity = '1';
    } finally {
        if (showSpinner && typeof window.hidePreloader === 'function') window.hidePreloader();
    }
};

// Inicializa el modulo: siembra el estado desde los data-attributes que
// renderiza el SSR en #catalogoTableBody y arma el observer del centinela.
function initCatalogo() {
    const tableBody = document.getElementById('catalogoTableBody');
    if (!tableBody) return;

    window.catState.page    = parseInt(tableBody.dataset.page || '1', 10) || 1;
    window.catState.hasMore = tableBody.dataset.hasMore === '1';
    window.catState.loading = false;
    catRefreshSentinel();
}

// Register with Module Manager for SPA compatibility
if (typeof ModuleManager !== 'undefined') {
    ModuleManager.register('catalogo',
        () => document.getElementById('catalogoTableBody') !== null,
        initCatalogo
    );
}

// Direct init fallback (ModuleManager may init before modules register)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCatalogo);
} else {
    initCatalogo();
}
