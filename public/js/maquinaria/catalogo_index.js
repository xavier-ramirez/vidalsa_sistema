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
                const response = await window.apiFetch(`/admin/catalogo/${id}`, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    method: 'DELETE'});

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
// hasMore = el server indico que quedan paginas; loading = carga en curso (fetch
// y pintado); gen = numero de la grilla actual, sube cada vez que se rearma (entrar
// al modulo, cambiar un filtro) para descartar lo que llegue de una anterior;
// fallos = cargas seguidas que fallaron (espacia el reintento).
window.catState = window.catState || { page: 1, hasMore: false, loading: false, gen: 0, fallos: 0 };

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
        resp = await window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal
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
        // rootMargin ALTO (1500px): dispara la carga de la siguiente página MUCHO antes de
        // llegar al fondo, para que las tarjetas nuevas ya estén cuando el usuario llega —
        // antes (800px) se alcanzaba el final y aún no habían cargado.
        window.catScrollObserver = new IntersectionObserver(function (entries) {
            if (entries[0].isIntersecting) catLoadMore();
        }, { rootMargin: '1500px 0px' });
    }
    window.catScrollObserver.disconnect();
    if (window.catState.hasMore) window.catScrollObserver.observe(sentinel);
}

// Una carga que falla (red caida, servidor lento) no puede dejar muerto el scroll: el
// observer solo avisa cuando el centinela ENTRA en pantalla y tras el fallo ya estaba
// dentro, asi que no volvia a pedir nada hasta recargar la pagina. Se rearma solo, cada
// vez mas espaciado (2 s, 4 s... hasta 30 s), o en cuanto vuelva la conexion.
function catReintentar(gen) {
    const st = window.catState;
    st.fallos = (st.fallos || 0) + 1;
    const rearmar = function () { if (st.gen === gen) catRefreshSentinel(); };
    if (navigator.onLine === false) { window.addEventListener('online', rearmar, { once: true }); return; }
    setTimeout(rearmar, Math.min(30000, 1000 * Math.pow(2, st.fallos)));
}

// Carga la SIGUIENTE pagina y agrega sus tarjetas al final del grid.
async function catLoadMore() {
    const st = window.catState;
    if (st.loading || !st.hasMore) return;
    const tableBody = document.getElementById('catalogoTableBody');
    if (!tableBody) return;

    // Lo que llegue cuando la grilla ya es otra (cambio un filtro, o se salio del modulo
    // y se volvio a entrar) no se toca: el estado ya es de la nueva. Antes esa respuesta
    // vieja movia el contador de pagina de la nueva y se saltaba un lote de tarjetas.
    const gen = st.gen;
    const esLaActual = function () { return st.gen === gen && tableBody.isConnected; };
    st.loading = true;
    const spinner = document.getElementById('catalogoLoadingSpinner');
    if (spinner) spinner.style.display = '';

    let data = null;
    try {
        data = await catFetch(catBuildUrl(st.page + 1));
    } catch (error) {
        console.error('Error en scroll infinito del catalogo:', error);
    }
    if (!esLaActual()) return;
    if (spinner) spinner.style.display = 'none';
    if (!data) { st.loading = false; catReintentar(gen); return; }
    st.fallos = 0;

    // Insert PROGRESIVO: en vez de meter las 24 tarjetas del lote de golpe (un tirón que
    // "congela" el scroll mientras el navegador calcula layout de las 24 a la vez), se
    // parsea el HTML fuera del DOM y se agregan en grupos pequeños (6) por frame con
    // requestAnimationFrame. Así el scroll sigue fluido y las tarjetas aparecen poco a poco.
    // 'loading' sigue puesto hasta pintar la ultima: el lote siguiente no se pide antes,
    // asi dos lotes nunca se intercalan.
    const tmp = document.createElement('div');
    tmp.innerHTML = data.html || '';
    const nuevas = Array.from(tmp.children);
    const CAT_INSERT_CHUNK = 6;
    let i = 0;
    (function pintarLote() {
        if (!esLaActual()) return;
        const frag = document.createDocumentFragment();
        for (let n = 0; n < CAT_INSERT_CHUNK && i < nuevas.length; n++, i++) {
            frag.appendChild(nuevas[i]);
        }
        tableBody.appendChild(frag);
        if (i < nuevas.length) { requestAnimationFrame(pintarLote); return; }
        st.page    = data.page || (st.page + 1);
        st.hasMore = !!data.hasMore;
        st.loading = false;
        catRefreshSentinel();
    })();
}

// Carga la PRIMERA pagina y REEMPLAZA el grid. La usan el cambio de filtro
// (catSubmit en index.blade.php) y el borrado de un modelo. Reinicia el
// scroll infinito a la pagina 1.
window.loadCatalogo = async function (showSpinner = true) {
    const tableBody = document.getElementById('catalogoTableBody');
    if (!tableBody) return;

    // Desde aqui la grilla es otra: una carga del scroll a medias se descarta, y no se
    // pide la pagina siguiente de los filtros viejos mientras llega la primera.
    const st = window.catState;
    const antes = { page: st.page, hasMore: st.hasMore };
    const gen = st.gen = (st.gen || 0) + 1;
    st.hasMore = false;
    st.loading = false;
    const spinnerMas = document.getElementById('catalogoLoadingSpinner');
    if (spinnerMas) spinnerMas.style.display = 'none';

    tableBody.style.opacity = '0.5';
    if (showSpinner && typeof window.showPreloader === 'function') window.showPreloader();

    try {
        const data = await catFetch(catBuildUrl(1));
        // null o gen distinto = la reemplazo una peticion mas nueva; esa se hace cargo
        // (tambien de devolverle la opacidad a la grilla cuando llegue).
        if (!data || st.gen !== gen) return;

        tableBody.innerHTML = data.html;
        tableBody.style.opacity = '1';

        // Reinicia el estado del scroll infinito a la pagina recien cargada.
        st.page    = data.page || 1;
        st.hasMore = !!data.hasMore;
        st.fallos  = 0;
        catRefreshSentinel();

        // El contador lateral (Total Registros, Vehículos/Auxiliares) es el de los filtros.
        const statsContainer = document.getElementById('statsSidebarContainer');
        if (statsContainer && data.stats) statsContainer.innerHTML = data.stats;

        // URL navegable con los filtros aplicados (sin ajax_load).
        const cleanUrl = new URL(catBuildUrl(1), window.location.origin);
        cleanUrl.searchParams.delete('ajax_load');
        window.history.pushState({}, '', cleanUrl.toString());
    } catch (error) {
        console.error('Error loading catalogo:', error);
        // Se queda la grilla anterior: que su scroll siga donde iba.
        if (st.gen === gen) {
            tableBody.style.opacity = '1';
            st.page = antes.page; st.hasMore = antes.hasMore;
            catRefreshSentinel();
        }
    } finally {
        if (showSpinner && typeof window.hidePreloader === 'function') window.hidePreloader();
    }
};

// Inicializa el modulo: siembra el estado desde los data-attributes que
// renderiza el SSR en #catalogoTableBody y arma el observer del centinela.
function initCatalogo() {
    const tableBody = document.getElementById('catalogoTableBody');
    if (!tableBody) return;

    // Grilla nueva (entrar al modulo): lo que siguiera en vuelo de la visita anterior
    // se cancela y, si igual llega, se descarta (ver catLoadMore).
    if (window.currentRequestController) window.currentRequestController.abort();
    window.currentRequestController = null;
    const st = window.catState;
    st.gen     = (st.gen || 0) + 1;
    st.page    = parseInt(tableBody.dataset.page || '1', 10) || 1;
    st.hasMore = tableBody.dataset.hasMore === '1';
    st.loading = false;
    st.fallos  = 0;
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
