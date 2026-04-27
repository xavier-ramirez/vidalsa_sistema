// --- Delete Modal Logic (AJAX via standardModal) ---
window.confirmDeleteCatalogo = function (id, modelName) {
    if (!id || String(id).trim() === '') {
        console.error('ID missing for confirmDeleteCatalogo');
        return;
    }

    window.showModal({
        type: 'danger',
        title: '¿Eliminar registro?',
        message: `¿Estás seguro de que deseas eliminar "<strong>${modelName}</strong>"?<br>Esta acción no se puede deshacer.`,
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
                    window.loadCatalogo(window.location.href);
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




// Global AbortController to cancel pending requests
window.currentRequestController = null;


// Standardized Load Function (Matches Equipos Logic)
window.loadCatalogo = async function (url = null, showSpinner = true) {
    // 1. Cancel previous pending request
    if (window.currentRequestController) {
        window.currentRequestController.abort();
    }
    window.currentRequestController = new AbortController();
    const signal = window.currentRequestController.signal;

    const tableBody = document.getElementById('catalogoTableBody');
    if (!tableBody) return;

    let baseUrl = url || '/admin/catalogo';

    // Explicitly gather inputs (Single Source of Truth)
    const modeloInput = document.querySelector('input[name="modelo"]');
    const anioInput = document.querySelector('input[name="anio"]');

    // Unified Filter Object
    const filters = {
        modelo: (modeloInput?.value !== '') ? modeloInput?.value : null,
        anio: (anioInput?.value !== '') ? anioInput?.value : null,
        ajax_load: '1'
    };

    const params = new URLSearchParams();

    // Cleanly append only valid filter values
    Object.entries(filters).forEach(([key, value]) => {
        if (value && typeof value === 'string' && value.trim() !== '') {
            params.append(key, value.trim());
        }
    });

    // Removed the !hasAnyInput check here. The catalog should always query the server
    // even if filters are empty, because emptying filters means "show all paginated records".

    // Strip existing params from baseUrl if we are rebuilding them (unless it's pagination link)
    if (!url && baseUrl.includes('?')) {
        baseUrl = baseUrl.split('?')[0];
    }

    // If url passed (pagination), use its params + force ajax_load
    let finalUrl;
    if (url) {
        const urlObj = new URL(url, window.location.origin);
        urlObj.searchParams.set('ajax_load', '1');
        
        // Merge current UI filters into the pagination URL
        // This ensures that if the user clicks a stale pagination link,
        // it still applies the latest filters they selected.
        Object.entries(filters).forEach(([key, value]) => {
            if (key !== 'ajax_load') {
                if (value && typeof value === 'string' && value.trim() !== '') {
                    urlObj.searchParams.set(key, value.trim());
                } else {
                    urlObj.searchParams.delete(key);
                }
            }
        });
        
        finalUrl = urlObj.toString();
    } else {
        finalUrl = baseUrl + '?' + params.toString();
    }

    // UI Feedback
    if (tableBody) tableBody.style.opacity = '0.5';
    if (showSpinner && typeof window.showPreloader === 'function') window.showPreloader();

    try {
        const response = await fetch(finalUrl, {
            signal: signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        if (!response.ok) throw new Error('Network response was not ok');

        const data = await response.json();

        // Update Table Content
        if (tableBody) {
            tableBody.innerHTML = data.html;
            tableBody.style.opacity = '1';
        }

        // Re-initalize Lazy Loading
        initCatalogo();

        // Update Pagination
        const paginationContainer = document.getElementById('catalogoPagination');
        if (paginationContainer && data.pagination !== undefined) {
            paginationContainer.innerHTML = data.pagination;
        }

        // Update Stats Sidebar
        const statsContainer = document.getElementById('statsSidebarContainer');
        if (statsContainer && data.stats) {
            statsContainer.innerHTML = data.stats;
        }

        // Update Browser URL (for shareable links)
        const cleanUrl = new URL(finalUrl, window.location.origin);
        cleanUrl.searchParams.delete('ajax_load');
        window.history.pushState({}, '', cleanUrl.toString());

    } catch (error) {
        if (error.name === 'AbortError') return;
        console.error('Error loading catalogo:', error);
        if (tableBody) tableBody.style.opacity = '1';
    } finally {
        if (window.currentRequestController === null || (window.currentRequestController && window.currentRequestController.signal === signal)) {
            if (showSpinner && typeof window.hidePreloader === 'function') window.hidePreloader();
            if (window.currentRequestController && window.currentRequestController.signal === signal) {
                window.currentRequestController = null;
            }
        }
    }
};

// Initialize Catalogo Module
function initCatalogo() {
    if (!document.getElementById('catalogoTableBody')) return;

    // Lazy Load Images
    const lazyImages = document.querySelectorAll('img.lazy-catalog-img');
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.onload = () => img.style.opacity = 1;
                    imageObserver.unobserve(img);
                }
            });
        });
        lazyImages.forEach(img => imageObserver.observe(img));
    } else {
        // Fallback
        lazyImages.forEach(img => {
            img.src = img.dataset.src;
            img.onload = () => img.style.opacity = 1;
        });
    }

    // Removed buggy AJAX reload on initial load. 
    // Laravel SSR handles initial parameters perfectly.
    window.catalogoInitialLoadDone = true;
}

// Global Event Delegation for Pagination (Solves intermittent click failures)
if (!window.catalogoPaginationAttached) {
    window.catalogoPaginationAttached = true;
    document.addEventListener('click', function(e) {
        const paginationLink = e.target.closest('#catalogoPagination a');
        if (paginationLink) {
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation(); // Crucial to prevent 'navegacion.js' SPA conflict
            window.loadCatalogo(paginationLink.href);
        }
    }, true); // Use capture phase so this fires BEFORE generic SPA handlers
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
