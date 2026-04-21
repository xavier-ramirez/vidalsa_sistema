// --- Delete Modal Logic (AJAX via standardModal) ---
window.confirmDeleteCatalogo = function (id, modelName) {
    if (!id || String(id).trim() === '') {
        console.error('ID missing for confirmDeleteCatalogo');
        return;
    }

    window.showModal({
        type: 'warning',
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
                    if (window.showModal) {
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



// --- Specific Catalog Logic (Standardized) ---
// NOTE: selectAdvancedOption is now consolidated in uicomponents.js (global version)
// This module-specific version is kept for backwards compatibility but can be removed
window.selectAdvancedOption = function (type, value, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    if (type === 'modelo') {
        const input = document.getElementById('searchModeloInput');
        if (input) {
            input.value = value;
            input.placeholder = value ? value : 'Buscar Modelo...'; // Update placeholder if selected
        }
        const hidden = document.getElementById('input_modelo_filter');
        if (hidden) hidden.value = value;

        const clearBtn = document.getElementById('btn_clear_modelo');
        if (clearBtn) clearBtn.style.display = value ? 'block' : 'none';

        const dropdown = document.getElementById('modeloFilterSelect');
        if (dropdown) dropdown.classList.remove('active');
    }

    if (type === 'anio') {
        const input = document.getElementById('searchAnioInput');
        if (input) {
            input.value = value;
            input.placeholder = value ? value : 'Buscar Año...';
        }
        const hidden = document.getElementById('input_anio_filter');
        if (hidden) hidden.value = value;

        const clearBtn = document.getElementById('btn_clear_anio');
        if (clearBtn) clearBtn.style.display = value ? 'block' : 'none';

        const dropdown = document.getElementById('anioFilterSelect');
        if (dropdown) dropdown.classList.remove('active');
    }

    // Trigger Load
    window.loadCatalogo();
};

window.debounceTimer = null;
window.debounceLoadCatalogo = function () {
    if (window.debounceTimer) clearTimeout(window.debounceTimer);
    window.debounceTimer = setTimeout(() => {
        // Sync text inputs to hidden inputs for free text search
        const modInput = document.getElementById('searchModeloInput');
        const anioInput = document.getElementById('searchAnioInput');

        if (modInput) document.getElementById('input_modelo_filter').value = modInput.value;
        if (anioInput) document.getElementById('input_anio_filter').value = anioInput.value;

        window.loadCatalogo();
    }, 600); // Increased to 600ms to match Vehicles and prevent frequent preloader flashes
};

// Global AbortController to cancel pending requests
window.currentRequestController = null;

// Clear individual catalog filter (standardized function)
window.clearCatalogoFilter = function (filterName) {
    if (window.debounceTimer) clearTimeout(window.debounceTimer);

    // Update UI Elements
    if (filterName === 'modelo') {
        const input = document.getElementById('searchModeloInput');
        if (input) {
            input.value = '';
            input.placeholder = 'Buscar Modelo...';
        }
        document.getElementById('input_modelo_filter').value = '';
        document.getElementById('btn_clear_modelo').style.display = 'none';

        // Reset dropdown highlighting
        const dropdown = document.getElementById('modeloFilterSelect');
        if (dropdown) {
            dropdown.querySelectorAll('.dropdown-item').forEach(item => {
                item.classList.remove('selected');
                item.style.fontWeight = '';
                item.style.color = '';
            });
            // Re-select "Todos"
            const allOption = dropdown.querySelector('.dropdown-item:first-child');
            if (allOption) allOption.classList.add('selected');
        }
    }

    if (filterName === 'anio') {
        const input = document.getElementById('searchAnioInput');
        if (input) {
            input.value = '';
            input.placeholder = 'Buscar Año...';
        }
        document.getElementById('input_anio_filter').value = '';
        document.getElementById('btn_clear_anio').style.display = 'none';

        // Reset dropdown highlighting
        const dropdown = document.getElementById('anioFilterSelect');
        if (dropdown) {
            dropdown.querySelectorAll('.dropdown-item').forEach(item => item.classList.remove('selected'));
        }
    }

    // Rely on standard load to fetch data with remaining filters
    window.loadCatalogo();
};


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

    // If url passed (pagination), use its params + force ajax_load, OR merge?
    // Usually pagination links already include params. 
    // IF url is passed, we normally trust it but ensure ajax_load is there.
    let finalUrl;
    if (url) {
        const urlObj = new URL(url, window.location.origin);
        urlObj.searchParams.set('ajax_load', '1');
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

    // Reload via AJAX solo si hay parámetros de búsqueda (en carga inicial)
    var hasParams = window.location.search.length > 1;
    if (hasParams && !window.catalogoInitialLoadDone) { 
        window.catalogoInitialLoadDone = true;
        window.loadCatalogo(); 
    }
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
