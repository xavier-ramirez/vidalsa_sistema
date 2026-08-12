/**
 * Catalog Linking System - FIXED VERSION
 * Connects Equipment Form (Model/Year) with Catalog Database
 * 
 * FIXES:
 * - Added initialization guard to prevent double init
 * - Consolidated search triggers to prevent duplicate API calls
 * - Proper cleanup of event listeners
 */

(function () {
    'use strict';

    // Configuration
    const CONFIG = {
        modelInputId: 'modelo',
        yearInputId: 'anio',
        tipoInputId: 'input_tipo_equipo',
        widgetId: 'catalog_link_widget',
        previewId: 'catalog_preview',
        hiddenInputId: 'linked_id_espec',
        searchUrl: '/admin/equipos/search-catalog'
    };

    // State
    let matches = [];
    let currentIndex = 0;
    let linkedId = null;
    let searchTimer = null;
    let isInitialized = false; // GUARD FLAG
    let lastSearchKey = ''; // Prevent duplicate searches for same values

    // Initialize on DOM ready OR SPA navigation
    function init() {
        const widget = document.getElementById(CONFIG.widgetId);
        if (!widget) return; // Not on equipment form

        // GUARD: Prevent double initialization
        if (isInitialized && widget.dataset.catalogBound === 'true') {
            return;
        }


        // Reset state for fresh init (SPA navigation)
        matches = [];
        currentIndex = 0;
        linkedId = null;
        lastSearchKey = '';

        // Bind Events (with cleanup)
        bindInputEvents();
        bindDropdownEvents();

        // Mark as initialized
        isInitialized = true;
        widget.dataset.catalogBound = 'true';

    }

    // Cleanup function for SPA navigation
    function cleanup() {
        document.removeEventListener('input', handleDelegatedInput);
        window.removeEventListener('dropdown-selection', handleDropdownSelection);
        isInitialized = false;
    }

    function bindInputEvents() {
        // Remove previous listeners first (defensive)
        document.removeEventListener('input', handleDelegatedInput);

        // Bind global listeners (Delegation)
        document.addEventListener('input', handleDelegatedInput);
    }

    function handleDelegatedInput(e) {
        const id = e.target.id;
        if (id === CONFIG.modelInputId || id === CONFIG.yearInputId || id === CONFIG.tipoInputId ||
            id === 'MODELO' || id === 'ANIO' || id === 'anio') {
            debounceSearch();
        }
    }

    function bindDropdownEvents() {
        // Remove previous listener first
        window.removeEventListener('dropdown-selection', handleDropdownSelection);
        window.addEventListener('dropdown-selection', handleDropdownSelection);
    }

    function handleDropdownSelection(e) {
        // Only respond to model and year dropdowns
        const relevantIds = ['modelo', 'anio', 'MODELO', 'ANIO', 'ANIO_ESPEC', 'input_tipo_equipo'];
        if (e.detail && e.detail.id && relevantIds.includes(e.detail.id)) {
            // Clear any pending debounce and search immediately
            clearTimeout(searchTimer);
            setTimeout(attemptSearch, 150); // Small delay for DOM to update
        }
    }

    function debounceSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(attemptSearch, 600);
    }

    function attemptSearch() {
        const modelInput = document.getElementById(CONFIG.modelInputId);
        const yearInput = document.getElementById(CONFIG.yearInputId);

        if (!modelInput || !yearInput) {
            return; // Silently fail, inputs not on page
        }

        const model = modelInput.value.trim();
        const year = yearInput.value.trim();
        const tipoInput = document.getElementById(CONFIG.tipoInputId);
        const tipo = tipoInput ? tipoInput.value.trim() : '';

        if (model.length < 2 || year.length < 4) {
            hideWidget();
            return;
        }

        // GUARD: Prevent duplicate searches for same values (incluye TIPO).
        const searchKey = `${model}|${year}|${tipo}`;
        if (searchKey === lastSearchKey) {
            return;
        }
        lastSearchKey = searchKey;


        let url = `${CONFIG.searchUrl}?model=${encodeURIComponent(model)}&year=${encodeURIComponent(year)}`;
        if (tipo) url += `&tipo=${encodeURIComponent(tipo)}`;

        window.apiFetch(url)
            .then(r => r.json())
            .then(res => {
                if (res.found && res.data.length > 0) {
                    matches = res.data;
                    currentIndex = 0;
                    renderWidget();
                } else {
                    matches = [];
                    hideWidget();
                }
            })
            .catch(err => {
                console.error('❌ Search Error:', err);
                hideWidget();
            });
    }

    function renderWidget() {
        const widget = document.getElementById(CONFIG.widgetId);
        const preview = document.getElementById(CONFIG.previewId);

        if (!widget || !preview || matches.length === 0) return;

        const data = matches[currentIndex];
        const isLinked = (linkedId == data.ID_ESPEC);

        // Build preview HTML
        let html = '';

        // Navigation if multiple
        if (matches.length > 1) {
            html += `
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0;">
                    <button type="button" onclick="window.catalogPrev()" ${currentIndex === 0 ? 'disabled' : ''} 
                            style="background: #f1f5f9; border: 1px solid #cbd5e0; padding: 6px 12px; border-radius: 6px;">
                        ← Anterior
                    </button>
                    <span style="font-size: 12px; color: #64748b;">Opción ${currentIndex + 1} de ${matches.length}</span>
                    <button type="button" onclick="window.catalogNext()" ${currentIndex === matches.length - 1 ? 'disabled' : ''}
                            style="background: #f1f5f9; border: 1px solid #cbd5e0; padding: 6px 12px; border-radius: 6px;">
                        Siguiente →
                    </button>
                </div>
            `;
        }

        // Inject responsive styles if not present
        if (!document.getElementById('catalog-widget-styles')) {
            const style = document.createElement('style');
            style.id = 'catalog-widget-styles';
            style.textContent = `
                .catalog-grid {
                    display: grid;
                    gap: 3px 12px;
                    font-size: 12px;
                }
                /* Desktop: 4 Columns */
                @media (min-width: 768px) {
                    .catalog-grid { grid-template-columns: repeat(4, 1fr); }
                     .catalog-flex-container { display: flex; gap: 12px; align-items: flex-start; }
                     .catalog-photo-wrapper { width: 110px; flex-shrink: 0; }
                }
                /* Mobile: 2 Columns */
                @media (max-width: 767px) {
                     .catalog-grid { grid-template-columns: repeat(2, 1fr); }
                     .catalog-flex-container { display: flex; flex-direction: column; gap: 10px; }
                     .catalog-photo-wrapper { width: 100%; display: flex; justify-content: center; margin-bottom: 6px; }
                     .catalog-photo-wrapper img, .catalog-photo-wrapper div { width: 100% !important; max-width: 190px; height: auto !important; max-height: 110px; object-fit: contain; }
                }
            `;
            document.head.appendChild(style);
        }

        // Main Content Container (Row)
        html += '<div class="catalog-flex-container">';

        // Photo (Left Column)
        if (data.FOTO_REFERENCIAL) {
            html += `
                <div class="catalog-photo-wrapper">
                    <img src="${data.FOTO_REFERENCIAL}" style="width: 100%; height: 92px; border-radius: 8px; object-fit: contain; background: #f8fafc; border: 1px solid #e2e8f0;">
                </div>`;
        } else {
            // Placeholder if no photo
            html += `
                <div class="catalog-photo-wrapper">
                    <div style="width: 110px; height: 92px; background: #f1f5f9; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #cbd5e0;">
                        <i class="material-icons" style="font-size: 32px;">image</i>
                    </div>
                </div>`;
        }

        // Data Grid (Right Column) - Responsive Class
        // Sin "Combustible" ni "Consumo": son datos de la UNIDAD (equipos.COMBUSTIBLE
        // y equipos.CONSUMO_PROMEDIO), no de la ficha del modelo. Se editan en el
        // formulario del equipo.
        html += `
            <div style="flex: 1;" class="catalog-grid">
                <div>
                    <span style="display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">Motor</span>
                    <span style="color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${data.MOTOR || '--'}</span>
                </div>
                <div>
                    <span style="display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">Batería</span>
                    <span style="color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${data.TIPO_BATERIA || '--'}</span>
                </div>
                <div>
                    <span style="display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">Aceite Motor</span>
                    <span style="color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${data.ACEITE_MOTOR || '--'}</span>
                </div>
                <div>
                    <span style="display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">Aceite Caja</span>
                    <span style="color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${data.ACEITE_CAJA || '--'}</span>
                </div>
                <div>
                    <span style="display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">Liga Freno</span>
                    <span style="color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${data.LIGA_FRENO || '--'}</span>
                </div>
                <div>
                    <span style="display: block; font-size: 10px; color: #64748b; font-weight: 700; text-transform: uppercase;">Refrigerante</span>
                    <span style="color: #1e293b; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">${data.REFRIGERANTE || '--'}</span>
                </div>
            </div>
        `;

        html += '</div>'; // End Main Content Container

        preview.innerHTML = html;
        widget.style.display = 'block';

        // Título: alterna entre "¡Encontramos…!" y "✅ Vinculado". El texto de la
        // descripción y los botones (Vincular/Ignorar) ya viven en el blade con su tamaño
        // definido — NO se tocan desde aquí. (Antes este bloque referenciaba ids
        // 'btn_link_catalog'/'btn_ignore_catalog' y .widget-desc que NO existen en el
        // markup → código muerto; y forzaba la descripción a 14px, deshaciendo la
        // compactación de la tarjeta.)
        const titleEl = widget.querySelector('h4');

        // Update visual state
        if (isLinked) {
            widget.style.background = 'linear-gradient(135deg, #dcfce7 0%, #f0fdf4 100%)';
            widget.style.borderColor = '#22c55e';
            if (titleEl) titleEl.innerText = '✅ Vinculado';
        } else {
            widget.style.background = 'linear-gradient(135deg, #ebf8ff 0%, #f0f9ff 100%)';
            widget.style.borderColor = '#0284c7';
            if (titleEl) titleEl.innerText = '¡Encontramos este modelo en el Catálogo!';
        }
    }

    function hideWidget() {
        const widget = document.getElementById(CONFIG.widgetId);
        if (widget) widget.style.display = 'none';
    }

    function linkToCatalog() {
        const match = matches[currentIndex];
        if (!match) return;

        const hiddenInput = document.getElementById(CONFIG.hiddenInputId);
        if (hiddenInput) {
            hiddenInput.value = match.ID_ESPEC;
            linkedId = match.ID_ESPEC;
            renderWidget();
            showToast('✅ Vinculado correctamente');
        }
    }

    function ignoreCatalog() {
        const hiddenInput = document.getElementById(CONFIG.hiddenInputId);
        if (hiddenInput) hiddenInput.value = '';
        linkedId = null;
        hideWidget();
    }

    function prevMatch() {
        if (currentIndex > 0) {
            currentIndex--;
            renderWidget();
        }
    }

    function nextMatch() {
        if (currentIndex < matches.length - 1) {
            currentIndex++;
            renderWidget();
        }
    }

    function showToast(msg) {
        const div = document.createElement('div');
        div.innerText = msg;
        div.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#22c55e;color:white;padding:12px 24px;border-radius:8px;z-index:10000;font-weight:600;';
        document.body.appendChild(div);
        setTimeout(() => div.remove(), 3000);
    }

    function resetFullState() {
        matches = [];
        currentIndex = 0;
        linkedId = null;
        lastSearchKey = ''; // Critical: Clear cache so identical consecutive searches work
        const hiddenInput = document.getElementById(CONFIG.hiddenInputId);
        if (hiddenInput) hiddenInput.value = '';
        hideWidget();
    }

    // Expose to window for onclick handlers
    window.linkToCatalog = linkToCatalog;
    window.ignoreCatalogSuggestion = ignoreCatalog;
    window.catalogPrev = prevMatch;
    window.catalogNext = nextMatch;
    window.searchCatalog = attemptSearch;
    window.catalogCleanup = cleanup; // Expose cleanup for SPA
    window.catalogReset = resetFullState; // Expose full reset

    // ROBUST INITIALIZATION STRATEGY: MutationObserver
    // Instead of guessing WHEN the content arrives (timers/events), 
    // we watch for the widget to APPEAR in the DOM.
    const observer = new MutationObserver((mutations, obs) => {
        const widget = document.getElementById(CONFIG.widgetId);
        if (widget && widget.dataset.catalogBound !== 'true') {
            // Widget found and not initialized!
            init();
        }
    });

    // Start observing the body for added nodes
    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    // Also check immediately in case it's already there
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Explicit SPA Event: Reset flags and allow re-init
    window.addEventListener('spa:contentLoaded', function () {

        // Clean up old state
        cleanup();
        isInitialized = false;

        // Reset widget flag so observer can detect it as "new"
        const widget = document.getElementById(CONFIG.widgetId);
        if (widget) {
            widget.dataset.catalogBound = 'false';
        }

        // Force init as backup (observer handles it, but this is failsafe)
        setTimeout(init, 150);
    });

})();
