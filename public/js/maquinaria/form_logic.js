// form_logic.js - Externalized Logic for Form Fields to comply with CSP
// Version: 3.0 - FINAL FIX - SPA Ready with Guards (Feb 2, 2026)

// --- CRITICAL: Global Dropdown Functions (Must be outside IIFE for inline handlers) ---
// NOTE: toggleDropdown is now in uicomponents.js (single source of truth)

window.updateSelectedCount = function () {
    const checkboxes = document.querySelectorAll('input[name="PERMISOS[]"]:checked');
    const label = document.getElementById('selectedCount');
    if (label) {
        if (checkboxes.length === 0) {
            label.innerText = 'Seleccione permisos...';
        } else if (checkboxes.length === 1) {
            // Get text from the label sibling of the checkbox
            const text = checkboxes[0].nextElementSibling.innerText;
            label.innerText = text;
        } else {
            label.innerText = `${checkboxes.length} permisos seleccionados`;
        }
    }
};

// Render generico de chips de frentes dentro del trigger de un multiselect.
// Reutilizado por "Frentes Asignados" (azul) y "Frentes Bloqueados" (rojo) para
// no duplicar la logica. Cuando 0 seleccionados, el placeholder informa.
window.__renderFrenteChips = function (selectId, spanId, inputId, emptyPlaceholder, chipBg, chipColor) {
    const checks = document.querySelectorAll('#' + selectId + ' input[type="checkbox"]:checked');
    const span  = document.getElementById(spanId);
    const input = document.getElementById(inputId);
    if (!span) return;
    if (checks.length === 0) {
        span.innerHTML = '';
        if (input) input.placeholder = emptyPlaceholder;
        return;
    }
    const esc = (s) => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    span.innerHTML = Array.from(checks).map(c => {
        const name = c.closest('label').querySelector('span').textContent.trim();
        return '<span style="display:inline-flex;align-items:center;background:' + chipBg + ';color:' + chipColor + ';font-weight:600;padding:2px 10px;border-radius:999px;font-size:12.5px;line-height:1.6;">' + esc(name) + '</span>';
    }).join('');
    // Placeholder mas corto cuando ya hay chips — el campo se entiende como "buscar mas".
    if (input) input.placeholder = 'Buscar...';
};

window.updateFrentesCount = function () {
    window.__renderFrenteChips('frentesSelect', 'frentesSelectedCount', 'frentesSearchInput',
        'Seleccione o escriba frentes...', '#e0f2fe', '#0284c7');
};

// "Frentes Bloqueados" (lista negra): chips rojos para diferenciarlos visualmente.
window.updateFrentesBloqueadosCount = function () {
    window.__renderFrenteChips('frentesBloqueadosSelect', 'frentesBloqueadosSelectedCount', 'frentesBloqueadosSearchInput',
        'Seleccione frentes a ocultar...', '#fee2e2', '#dc2626');
};

// NOTE: selectOption is now in uicomponents.js (single source of truth)

// --- RESTORED: Custom Form Autocomplete Logic (Requested by User) ---
// --- RESTORED & UPGRADED: Custom Form Autocomplete Logic ---
window.showFormDropdown = function (input) {
    const container = input.closest('.custom-form-autocomplete');
    if (!container) return;
    const dropdown = container.querySelector('.dropdown-list');
    if (!dropdown) return;

    // Show dropdown
    dropdown.style.display = 'block';

    // Reset filter (show all) if input is empty
    if (input.value.trim() === '') {
        const items = dropdown.querySelectorAll('.dropdown-item');
        items.forEach(item => item.style.display = 'block');
    } else {
        // Trigger filter to match current value
        window.filterFormDropdown(input);
    }

    // Load models dynamically if this is the modelo field
    if ((input.id === 'modelo' || input.id === 'MODELO') && dropdown.children.length === 0 && window.loadModelsList) {
        window.loadModelsList();
    }

    // Load brands dynamically if this is the marca field
    if ((input.id === 'marca' || input.id === 'MARCA') && dropdown.children.length === 0 && window.loadBrandsList) {
        window.loadBrandsList();
    }

    // Safety check: specific logic for Years if empty
    if ((input.id === 'ANIO' || input.id === 'ANIO_ESPEC') && dropdown.children.length === 0 && window.updateYearsList) {
        const modelInput = document.getElementById('MODELO') || document.getElementById('modelo');
        if (modelInput && modelInput.value) {
            window.updateYearsList(modelInput.value);
        }
    }
};

window.hideFormDropdownDelayed = function (input) {
    setTimeout(() => {
        const container = input.closest('.custom-form-autocomplete');
        if (!container) return; // Safety check
        const dropdown = container.querySelector('.dropdown-list');
        if (dropdown) dropdown.style.display = 'none';
    }, 200);
};

window.filterFormDropdown = function (input) {
    const container = input.closest('.custom-form-autocomplete');
    if (!container) return; // Safety check
    const dropdown = container.querySelector('.dropdown-list');
    if (!dropdown) return; // Safety check
    const items = dropdown.querySelectorAll('.dropdown-item');
    const query = input.value.toUpperCase();

    items.forEach(item => {
        const text = item.textContent.trim().toUpperCase();
        if (text.includes(query)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
};

window.selectDropdownItem = function (element, value) {
    const container = element.closest('.custom-form-autocomplete');
    const input = container.querySelector('input[type="text"]');
    const dropdown = container.querySelector('.dropdown-list');

    input.value = value;
    dropdown.style.display = 'none';

    // Trigger generic change/blur events
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new Event('blur', { bubbles: true }));
    input.dispatchEvent(new Event('input', { bubbles: true }));

    // Trigger custom event for legacy support
    input.dispatchEvent(new CustomEvent('dropdown-selection', {
        bubbles: true,
        detail: { value: value, id: input.id }
    }));

    // TRIGGER DYNAMIC YEAR UPDATE
    if ((input.id === 'MODELO' || input.id === 'modelo') && window.updateYearsList) {
        window.updateYearsList(value);
    }
};


// --- Dynamic Dropdown Data Loading (Consolidated from form_selects.js) ---


// --- Dynamic Dropdown Data Loading (Consolidated from form_selects.js) ---

window.updateYearsList = function (model) {
    const anioInput = document.getElementById('ANIO_ESPEC') || document.getElementById('anio') || document.getElementById('ANIO');
    if (!anioInput || !model) return;

    const container = anioInput.closest('.custom-form-autocomplete');
    if (!container) return;

    const dropdown = container.querySelector('.dropdown-list');
    if (!dropdown) return;

    dropdown.innerHTML = '<div class="dropdown-item" style="color: #ecc94b; font-style: italic;">Cargando años...</div>';

    fetch(`/admin/catalogo/years-from-equipos?model=${encodeURIComponent(model)}`)
        .then(response => response.json())
        .then(years => {
            dropdown.innerHTML = '';

            if (!years || years.length === 0) {
                dropdown.innerHTML = '<div class="dropdown-item" style="color: #a0aec0; font-style: italic;">Sin registros previos</div>';
            } else {
                years.forEach(anio => {
                    const div = document.createElement('div');
                    div.className = 'dropdown-item';
                    div.textContent = anio;
                    div.onmousedown = function () { window.selectDropdownItem(this, anio); };
                    dropdown.appendChild(div);
                });
            }
        })
        .catch(err => {
            console.error('Error loading years:', err);
            dropdown.innerHTML = '<div class="dropdown-item" style="color: #e53e3e;">Error cargando años</div>';
        });
};

window.loadBrandsList = function () {
    const marcaInput = document.getElementById('marca') || document.getElementById('MARCA');
    if (!marcaInput) return;

    const container = marcaInput.closest('.custom-form-autocomplete');
    if (!container) return;

    const dropdown = container.querySelector('.dropdown-list');
    // Only load if not already loaded
    if (!dropdown || dropdown.dataset.loaded === 'true') return;

    dropdown.innerHTML = '<div class="dropdown-item" style="color: #ecc94b; font-style: italic;">Cargando marcas...</div>';

    fetch('/admin/catalogo/brands-from-equipos')
        .then(response => response.json())
        .then(brands => {
            dropdown.innerHTML = '';
            if (!brands || brands.length === 0) {
                dropdown.innerHTML = '<div class="dropdown-item" style="color: #a0aec0; font-style: italic;">Sin marcas registradas</div>';
            } else {
                brands.forEach(marca => {
                    if (!marca || marca.trim() === '') return;
                    const div = document.createElement('div');
                    div.className = 'dropdown-item';
                    div.textContent = marca;
                    div.onmousedown = function () { window.selectDropdownItem(this, marca); };
                    dropdown.appendChild(div);
                });
                dropdown.dataset.loaded = 'true';
            }
        })
        .catch(err => {
            dropdown.innerHTML = '<div class="dropdown-item" style="color: #e53e3e;">Error cargando marcas</div>';
        });
};

window.loadModelsList = function () {
    const modeloInput = document.getElementById('modelo') || document.getElementById('MODELO');
    if (!modeloInput) return;

    const container = modeloInput.closest('.custom-form-autocomplete');
    if (!container) return;

    const dropdown = container.querySelector('.dropdown-list');
    if (!dropdown || dropdown.dataset.loaded === 'true') return;

    dropdown.innerHTML = '<div class="dropdown-item" style="color: #ecc94b; font-style: italic;">Cargando modelos...</div>';

    fetch('/admin/catalogo/models-from-equipos')
        .then(response => response.json())
        .then(models => {
            dropdown.innerHTML = '';
            if (!models || models.length === 0) {
                dropdown.innerHTML = '<div class="dropdown-item" style="color: #a0aec0; font-style: italic;">Sin modelos registrados</div>';
            } else {
                models.forEach(modelo => {
                    if (!modelo || modelo.trim() === '') return;
                    const div = document.createElement('div');
                    div.className = 'dropdown-item';
                    div.textContent = modelo;
                    div.onmousedown = function () { window.selectDropdownItem(this, modelo); };
                    dropdown.appendChild(div);
                });
                dropdown.dataset.loaded = 'true';
            }
        })
        .catch(err => {
            dropdown.innerHTML = '<div class="dropdown-item" style="color: #e53e3e;">Error cargando modelos</div>';
        });
};

// Recomendación por TIPO (form de equipos): al elegir/cambiar el Tipo de Equipo se
// reconstruyen los dropdowns de MARCA y MODELO con SOLO los valores usados por equipos
// de ese tipo (endpoints brands/models-from-equipos?tipo=...). Sin tipo → lista completa.
window.scopeByTipo = function () {
    var tipoInput = document.getElementById('input_tipo_equipo');
    if (!tipoInput) return; // No es el form de equipos
    var tipo = (tipoInput.value || '').trim();

    [['marca', '/admin/catalogo/brands-from-equipos'],
     ['modelo', '/admin/catalogo/models-from-equipos']].forEach(function (pair) {
        var input = document.getElementById(pair[0]);
        if (!input) return;
        var container = input.closest('.custom-form-autocomplete');
        var dropdown = container ? container.querySelector('.dropdown-list') : null;
        if (!dropdown) return;

        var url = pair[1] + (tipo ? ('?tipo=' + encodeURIComponent(tipo)) : '');
        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (items) {
                dropdown.innerHTML = '';
                (items || []).forEach(function (val) {
                    if (!val || !String(val).trim()) return;
                    var div = document.createElement('div');
                    div.className = 'dropdown-item';
                    div.textContent = val;
                    div.onmousedown = function () { window.selectDropdownItem(this, val); };
                    dropdown.appendChild(div);
                });
                if (!dropdown.children.length) {
                    dropdown.innerHTML = '<div class="dropdown-item" style="color:#a0aec0;font-style:italic;">Sin registros para este tipo</div>';
                }
                dropdown.dataset.loaded = 'true';
            })
            .catch(function () { /* silencioso: si falla, queda la lista previa */ });
    });
};

// Listener único: al cambiar el Tipo de Equipo, re-scopear MARCA/MODELO.
// (selectDropdownItem despacha 'change'; escribir + salir también lo dispara.)
if (!window.tipoScopeListenerAttached) {
    document.addEventListener('change', function (e) {
        if (e.target && e.target.id === 'input_tipo_equipo') {
            window.scopeByTipo();
        }
    });
    window.tipoScopeListenerAttached = true;
}

// --- Initialization Logic ---
function initFormItems() {
    const form = document.getElementById('equipoForm') || document.getElementById('userForm');

    // UNIVERSAL GUARD: Prevent multiple initializations
    if (form && form.dataset.logicInitialized === 'true') {
        console.log('⏭️ Form already initialized, skipping');
        return;
    }

    console.log('✅ Initializing form items...');

    // 1. Initialize Dropdowns (Pre-fill years if model exists)
    const modelInput = document.getElementById('MODELO') || document.getElementById('modelo');
    if (modelInput && modelInput.value) {
        const anioInput = document.getElementById('ANIO_ESPEC') || document.getElementById('anio') || document.getElementById('ANIO');
        if (anioInput) {
            const container = anioInput.closest('.custom-form-autocomplete');
            const dropdown = container ? container.querySelector('.dropdown-list') : null;
            if (dropdown && dropdown.children.length === 0) {
                window.updateYearsList(modelInput.value);
            }
        }
    }

    // 2. Initialize PDF/Image logic if needed
    // (Existing logic is handled by inline events or other bindings, but we can double check here)

    // Mark as initialized
    if (form) {
        form.dataset.logicInitialized = 'true';
    }
}

// ROBUST INITIALIZATION: Listen for SPA content changes via MutationObserver
// This ensures that whenever the form is injected, we initialize it.
const formObserver = new MutationObserver((mutations) => {
    const form = document.getElementById('equipoForm') || document.getElementById('userForm'); // Check for main form
    if (form && form.dataset.logicInitialized !== 'true') {
        initFormItems();
        form.dataset.logicInitialized = 'true';
    }
});

formObserver.observe(document.body, { childList: true, subtree: true });

// Global Click Listener for closing dropdowns (One-time setup)
if (!window.dropdownClickListenerAttached) {
    document.addEventListener('click', function (e) {
        const dropdowns = document.querySelectorAll('.custom-form-autocomplete');
        dropdowns.forEach(container => {
            if (!container.contains(e.target)) {
                const dropdown = container.querySelector('.dropdown-list');
                if (dropdown) dropdown.style.display = 'none';
            }
        });
    });
    window.dropdownClickListenerAttached = true;
}

// Standard Init
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFormItems);
} else {
    initFormItems();
}

// Explicit SPA Event: Reset flags and re-init
window.addEventListener('spa:contentLoaded', function () {

    // Reset form flag so it can be re-initialized
    const form = document.getElementById('equipoForm') || document.getElementById('userForm');
    if (form) {
        form.dataset.logicInitialized = 'false';
    }

    // Re-init after small delay
    setTimeout(initFormItems, 100);
});

(function () {
    // Helper function to update PDF button with loading animation
    window.updatePdfBtn = function (input, wrapperId) {
        const wrapper = document.getElementById(wrapperId);
        if (!wrapper) return;

        if (input.files && input.files[0]) {
            const file = input.files[0];

            // 1. Loading State (Spinner)
            wrapper.className = 'upload-placeholder-mini';
            wrapper.style.width = '30px';
            wrapper.style.height = '30px';
            wrapper.style.borderRadius = '50%';
            wrapper.style.border = '2px solid rgba(0, 103, 177, 0.1)';

            // Spinner HTML
            wrapper.innerHTML = `
                <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;">
                    <i class="material-icons" style="font-size: 18px; color: var(--maquinaria-blue); animation: spin 0.8s linear infinite;">sync</i>
                </div>
            `;

            // Simulate processing time then show success
            setTimeout(() => {
                // 2. Success State (Green Circle Check)
                wrapper.innerHTML = `
                    <label for="${input.id}" title="${file.name}" style="width: 100%; height: 100%; border-radius: 50%; background: #2c7a7b; color: white; display: flex; align-items: center; justify-content: center; border: none; animation: popIn 0.3s ease;">
                        <i class="material-icons" style="font-size: 18px;">check</i>
                    </label>
                `;
                // Clear border style after success
                wrapper.style.border = 'none';

            }, 600);

        } else {
            // Revert to "Add" style if cleared
            wrapper.className = 'upload-placeholder-mini';
            wrapper.style.borderRadius = '50%';
            wrapper.style.border = 'none';
            wrapper.innerHTML = `
                <label for="${input.id}" title="Cargar Documento" style="border-radius: 50%; border-style: solid; border-width: 2px;">
                    <i class="material-icons" style="font-size: 18px;">add</i>
                </label>
             `;
        }
        if (window.validateDocPair) window.validateDocPair();
    };

    window.updateFileName = window.updatePdfBtn; // Alias for backwards compatibility

    // Image preview function with loading animation
    window.previewImage = function (input, previewId) {
        const preview = document.getElementById(previewId);
        if (!preview) return;

        if (input.files && input.files[0]) {
            preview.innerHTML = `<i class="material-icons" style="font-size: 16px; color: var(--maquinaria-blue); animation: spin 1s linear infinite;">sync</i>`;
            preview.style.borderColor = 'var(--maquinaria-blue)';

            const startTime = Date.now();
            const minTime = 1200;

            const reader = new FileReader();
            reader.onload = function (e) {
                const elapsed = Date.now() - startTime;
                const remaining = Math.max(0, minTime - elapsed);

                setTimeout(() => {
                    preview.innerHTML = `<img src="${e.target.result}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;">`;
                    preview.style.transform = 'scale(1.1)';
                    setTimeout(() => preview.style.transform = 'scale(1)', 200);
                }, remaining);
            }
            reader.readAsDataURL(input.files[0]);
        }
    };

    // Validation for document pairs (meta field + file input)
    window.validateDocPair = function () {
        try {
            const metaInputs = document.querySelectorAll('.doc-meta');
            const fileInputs = document.querySelectorAll('.doc-file');

            // 1. Meta -> File requirement
            metaInputs.forEach(meta => {
                const fileId = meta.dataset.fileTarget;
                const fileInput = document.getElementById(fileId);
                if (!fileInput) return;
                const hasExisting = meta.dataset.hasExisting === 'true';

                if (meta.value.trim() !== '') {
                    if (!hasExisting && (!fileInput.files || fileInput.files.length === 0)) {
                        // Optional validation logic
                    }
                }
            });

            // 2. File -> Meta requirement
            fileInputs.forEach(fileInput => {
                const metaId = fileInput.dataset.metaTarget;
                const meta = document.getElementById(metaId);
                if (!meta) return;

                const errorMsg = document.getElementById('error_meta_' + metaId);

                // Logic: If new file selected AND meta is empty -> ERROR
                if (fileInput.files && fileInput.files.length > 0) {
                    if (meta.value.trim() === '') {
                        meta.style.borderColor = '#e53e3e';
                        meta.setCustomValidity('Debe indicar el dato asociado.');
                        if (errorMsg) errorMsg.style.display = 'block';
                    } else {
                        meta.style.borderColor = '';
                        meta.setCustomValidity('');
                        if (errorMsg) errorMsg.style.display = 'none';
                    }
                } else {
                    if (meta.style.borderColor === 'rgb(229, 62, 62)' || meta.style.borderColor === '#e53e3e') {
                        meta.style.borderColor = '';
                        meta.setCustomValidity('');
                        if (errorMsg) errorMsg.style.display = 'none';
                    }
                }
            });
        } catch (e) {
            console.error('Validation logic error', e);
        }
    };

    // Attach event listeners to PDF file inputs
    function attachPdfListeners() {
        // PDF document file inputs (doc_propiedad, poliza_seguro, doc_rotc, doc_racda)
        const pdfInputs = {
            'doc_propiedad': 'wrapper_propiedad',
            'poliza_seguro': 'wrapper_poliza',
            'doc_rotc': 'wrapper_rotc',
            'doc_racda': 'wrapper_racda'
        };

        Object.entries(pdfInputs).forEach(([inputId, wrapperId]) => {
            const input = document.getElementById(inputId);
            if (input && !input.dataset.listenerAttached) {
                input.addEventListener('change', function () {
                    window.updatePdfBtn(this, wrapperId);
                });
                input.dataset.listenerAttached = 'true';
            }
        });

        // Photo input listener
        const fotoInput = document.getElementById('foto_equipo');
        if (fotoInput && !fotoInput.dataset.listenerAttached) {
            fotoInput.addEventListener('change', function () {
                window.previewImage(this, 'preview_equipo');
            });
            fotoInput.dataset.listenerAttached = 'true';
        }

        // Catalog Model Photo input listener
        const catalogFotoInput = document.getElementById('foto_referencial');
        if (catalogFotoInput && !catalogFotoInput.dataset.listenerAttached) {
            catalogFotoInput.addEventListener('change', function () {
                window.previewImage(this, 'preview_referencial');
            });
            catalogFotoInput.dataset.listenerAttached = 'true';
        }
    }

    // --- Dynamic Autocomplete Logic ---
    function setupAutocomplete() {
        const fields = [
            { id: 'marca', listId: 'marcas_list', type: 'MARCA' },
            { id: 'modelo', listId: 'modelos_list', type: 'MODELO' }
        ];

        fields.forEach(field => {
            const input = document.getElementById(field.id);
            const dataList = document.getElementById(field.listId);

            if (!input || !dataList) return;
            // Prevent multiple attachments if SPA re-runs
            if (input.dataset.autocompleteAttached) return;

            input.dataset.autocompleteAttached = 'true';

            let timeout = null;

            input.addEventListener('input', function () {
                const val = this.value;
                if (val.length < 2) return; // Wait for 2 chars

                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    fetch(`/admin/equipos/search-field?field=${field.type}&query=${encodeURIComponent(val)}`)
                        .then(r => r.json())
                        .then(data => {
                            dataList.innerHTML = '';
                            data.forEach(item => {
                                const option = document.createElement('option');
                                option.value = item;
                                dataList.appendChild(option);
                            });
                        })
                        .catch(e => console.error('Autocomplete error', e));
                }, 300); // 300ms debounce
            });
        });

        // Specs Search Listener (Programmatic Attachment)
        const specsInput = document.getElementById('searchInputSpecs');
        if (specsInput && !specsInput.dataset.searchAttached) {
            specsInput.addEventListener('input', function () {
                window.searchSpecs(this);
            });
            specsInput.dataset.searchAttached = 'true';
        }
    }

    // Initialize on page load
    function initFormLogic() {
        // Attach PDF upload listeners
        attachPdfListeners();

        // Run initial validation
        if (window.validateDocPair) window.validateDocPair();

        // Setup Autocomplete
        setupAutocomplete();

        // Attach validation listeners to meta inputs
        document.querySelectorAll('.doc-meta').forEach(input => {
            if (!input.dataset.validationAttached) {
                input.addEventListener('input', window.validateDocPair);
                input.dataset.validationAttached = 'true';
            }
        });

        // Attach validation listeners to file inputs
        document.querySelectorAll('.doc-file').forEach(input => {
            if (!input.dataset.validationAttached) {
                input.addEventListener('change', window.validateDocPair);
                input.dataset.validationAttached = 'true';
            }
        });
    }

    // Execute when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFormLogic);
    } else {
        initFormLogic();
    }

    // RE-INITIALIZE when SPA navigation loads new content
    // This handles navigation via the "Nuevo" button from index page
    window.addEventListener('spa:contentLoaded', function () {
        // Small delay to ensure DOM is fully updated
        setTimeout(initFormLogic, 50);
    });



    let specsTimeout = null;
    window.searchSpecs = function (input) {
        const val = input.value;
        const list = document.getElementById('specs_results_list');

        if (!list) return;

        if (val.length < 1) {
            list.innerHTML = '<div class="dropdown-item" onclick="selectOption(\'specSelect\', \'\', \'Ninguno\', \'spec\')">Sin Ficha Técnica</div>';
            return;
        }

        clearTimeout(specsTimeout);
        specsTimeout = setTimeout(() => {
            list.innerHTML = '<div style="padding:10px; color:#cbd5e0; font-size:12px; text-align:center;">Buscando...</div>';

            fetch(`/admin/equipos/search-specs?query=${encodeURIComponent(val)}`)
                .then(r => r.json())
                .then(data => {
                    list.innerHTML = '';
                    // Always add 'None' option
                    const noneDiv = document.createElement('div');
                    noneDiv.className = 'dropdown-item';
                    noneDiv.onclick = () => window.selectOption('specSelect', '', 'Ninguno', 'spec');
                    noneDiv.innerText = 'Sin Ficha Técnica';
                    list.appendChild(noneDiv);

                    if (data.length === 0) {
                        const empty = document.createElement('div');
                        empty.style.padding = '10px';
                        empty.style.color = '#718096';
                        empty.style.fontSize = '12px';
                        empty.innerText = 'No se encontraron resultados';
                        list.appendChild(empty);
                    }

                    data.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'dropdown-item';
                        div.onclick = () => window.selectOption('specSelect', item.ID_ESPEC, item.MODELO, 'spec');
                        div.innerText = item.MODELO;
                        list.appendChild(div);
                    });
                })
                .catch(e => {
                    console.error('Specs search error', e);
                    list.innerHTML = '<div style="padding:10px; color:red; font-size:12px;">Error al buscar</div>';
                });
        }, 300);
    };



    // --- User Form Logic (Centralized) ---
    document.addEventListener('submit', function (e) {
        if (e.target && e.target.id === 'userForm') {
            e.preventDefault();
            handleUserFormSubmit(e.target);
        }
    });

    function handleUserFormSubmit(form) {
        // Clear previous errors
        document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        document.querySelectorAll('.is-invalid-border').forEach(el => el.classList.remove('is-invalid-border'));
        document.querySelectorAll('.error-message-inline').forEach(el => el.remove());

        if (window.showPreloader) window.showPreloader();

        const formData = new FormData(form);

        // Client-side validation
        const clientErrors = validateClientSide(formData);
        if (Object.keys(clientErrors).length > 0) {
            if (window.hidePreloader) window.hidePreloader();
            handleValidationErrors(clientErrors);
            window.showModal({
                type: 'warning',
                title: 'Atención',
                message: 'Por favor complete todos los campos obligatorios marcados en rojo.',
                confirmText: 'Entendido',
                hideCancel: true
            });
            return;
        }

        const url = form.action;
        const methodInput = form.querySelector('input[name="_method"]');
        const method = methodInput ? methodInput.value : 'POST';

        fetch(url, {
            method: method === 'GET' ? 'GET' : 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
            .then(async response => {
                if (response.status === 419 || response.status === 401 || (response.redirected && response.url.includes('/login'))) {
                    window.location.href = '/login';
                    return Promise.reject(new Error('Sesión expirada. Redirigiendo al inicio de sesión...'));
                }
                
                const contentType = response.headers.get("content-type");
                if (!contentType || !contentType.includes("application/json")) {
                    return Promise.reject(new Error("Sesión expirada o respuesta inválida del servidor."));
                }
                
                const data = await response.json();
                return { status: response.status, body: data };
            })
            .then(({ status, body }) => {
                if (status === 200 || status === 201) {
                    // Check if it's Edit or Create
                    const isEdit = form.querySelector('input[name="_method"][value="PUT"]') ||
                        form.querySelector('input[name="_method"][value="PATCH"]');

                    // En CREATE el usuario se queda en la pagina, apagamos el
                    // preloader. En EDIT vamos a navegar al index manteniendo
                    // el preloader visible (sin parpadeo) hasta completar la
                    // transicion - se vera un solo spinner de inicio a fin.
                    if (!isEdit && window.hidePreloader) window.hidePreloader();

                    if (!isEdit) {
                        // CREATE MODE: Reset Form without reload
                        form.reset();

                        const resets = [
                            { id: 'input_rol', label: 'label_rol', text: 'Seleccione un rol...' },
                            { id: 'input_nivel', label: 'label_nivel', text: 'Seleccione nivel de acceso...' },
                            { id: 'input_estatus', label: 'label_estatus', text: 'ACTIVO', val: 'ACTIVO' } // Default Active
                        ];

                        resets.forEach(field => {
                            const input = document.getElementById(field.id);
                            const label = document.getElementById(field.label);
                            if (input) input.value = field.val || '';
                            if (label) label.innerText = field.text;
                        });

                        // Reset Permissions Multiselect
                        document.querySelectorAll('input[name="PERMISOS[]"]').forEach(cb => cb.checked = false);
                        const permLabel = document.getElementById('selectedCount');
                        if (permLabel) permLabel.innerText = 'Seleccione permisos...';

                        // Reset Frentes Multiselect
                        document.querySelectorAll('input[name="ID_FRENTE_ASIGNADO[]"]').forEach(cb => cb.checked = false);
                        if (window.updateFrentesCount) window.updateFrentesCount();
                        // Remove 'selected' class from all dropdown items
                        document.querySelectorAll('.dropdown-item.selected').forEach(el => el.classList.remove('selected'));
                        // Re-select default ACTIVO
                        const activeItem = document.querySelector(`[onclick*="'ACTIVO', 'ACTIVO'"]`);
                        if (activeItem) activeItem.classList.add('selected');

                        if (window.showToast) {
                            window.showToast(body.message || 'Usuario creado correctamente.', 'success');
                        } else {
                            window.showModal({
                                type: 'success',
                                title: '¡Éxito!',
                                message: body.message || 'Usuario creado correctamente.',
                                confirmText: 'Aceptar',
                                hideCancel: true
                            });
                        }

                        // No reload needed. User can register another immediately.

                    } else {
                        // EDIT MODE: navegar al index manteniendo el preloader
                        // activo durante la transicion (un solo spinner). El
                        // toast de exito se muestra al cargar el destino via
                        // sessionStorage flash (estructura_base.blade.php lo lee
                        // y dispara showToast). Asi se evita el "doble spinner":
                        // antes ocurria preloader OFF -> toast -> preloader ON
                        // (al navegar) -> destino. Ahora: preloader ON sostenido
                        // -> destino con toast.
                        try {
                            sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                                message: body.message || 'Usuario actualizado correctamente.',
                                type: 'success'
                            }));
                        } catch (_) { /* silencioso si sessionStorage no disponible */ }

                        window.__vidalsaRedirecting = true;
                        if (body.redirect) {
                            if (window.navigateTo) {
                                window.navigateTo(body.redirect);
                            } else {
                                window.location.href = body.redirect;
                            }
                        } else {
                            window.location.reload();
                        }
                    }

                } else if (status === 422) {
                    if (window.hidePreloader) window.hidePreloader();
                    handleValidationErrors(body.errors);
                    window.showModal({
                        type: 'warning',
                        title: 'Atención',
                        message: 'El formulario contiene errores. Por favor revise los campos marcados.',
                        confirmText: 'Entendido',
                        hideCancel: true
                    });
                } else {
                    throw new Error(body.message || 'Error desconocido del servidor');
                }
            })
            .catch(error => {
                if (window.hidePreloader) window.hidePreloader();
                console.error('Submission error:', error);
                window.showModal({
                    type: 'error',
                    title: 'Error',
                    message: error.message || 'Ocurrió un error al procesar la solicitud.',
                    confirmText: 'Cerrar',
                    hideCancel: true
                });
            });
    }

    function validateClientSide(formData) {
        const errors = {};

        // 1. Basic Fields
        if (!formData.get('NOMBRE_COMPLETO') || formData.get('NOMBRE_COMPLETO').trim() === '') {
            errors['NOMBRE_COMPLETO'] = ['El nombre completo es obligatorio.'];
        }

        const email = formData.get('CORREO_ELECTRONICO');
        if (!email || email.trim() === '') {
            errors['CORREO_ELECTRONICO'] = ['El correo electrónico es obligatorio.'];
        } else if (!email.includes('@cvidalsa27.com')) {
            errors['CORREO_ELECTRONICO'] = ['Solo se permiten correos con el dominio @cvidalsa27.com'];
        }

        // 2. Dropdowns (Hidden Inputs)
        if (!formData.get('ID_ROL')) errors['ID_ROL'] = ['Debes asignar un rol al usuario.'];
        if (!formData.get('NIVEL_ACCESO')) errors['NIVEL_ACCESO'] = ['El nivel de acceso es obligatorio.'];
        if (!formData.get('ESTATUS')) errors['ESTATUS'] = ['El estatus es obligatorio.'];
        // ID_FRENTE_ASIGNADO es ahora un array (checkbox multiselect), usar getAll()
        const frentesSeleccionados = formData.getAll('ID_FRENTE_ASIGNADO[]');
        // Frentes son opcionales (usuarios GLOBAL no necesitan frente)

        // 3. Permissions
        let hasPermissions = false;
        for (const key of formData.keys()) {
            if (key.startsWith('PERMISOS')) {
                hasPermissions = true;
                break;
            }
        }
        if (!hasPermissions) {
            errors['PERMISOS'] = ['Debes seleccionar al menos un permiso.'];
        }

        // 4. Password (Only if creating)
        const method = formData.get('_method');
        const isUpdate = method === 'PUT' || method === 'PATCH';

        if (!isUpdate) {
            const password = formData.get('password');
            if (!password || password.length < 6) {
                errors['password'] = ['La clave de acceso es obligatoria y debe tener al menos 6 caracteres.'];
            }
        }

        return errors;
    }

    function handleValidationErrors(errors) {
        for (const [field, messages] of Object.entries(errors)) {
            const input = document.querySelector(`[name="${field}"]`) || document.querySelector(`[name="${field}[]"]`);
            if (input) {
                input.classList.add('is-invalid');

                const dropdown = input.closest('.custom-dropdown') || input.closest('.custom-multiselect');
                if (dropdown) {
                    const trigger = dropdown.querySelector('.dropdown-trigger') || dropdown.querySelector('.multiselect-trigger');
                    if (trigger) trigger.classList.add('is-invalid-border');
                }

                const errorSpan = document.createElement('span');
                errorSpan.className = 'error-message-inline';
                errorSpan.innerText = messages[0];

                let parent = input.closest('div');
                if (dropdown) {
                    parent = dropdown.closest('div');
                }

                if (parent) {
                    parent.appendChild(errorSpan);
                }
            }
        }
    }

})();
