/**
 * equipos_form.js - Logic for Create/Edit Equipo Forms
 * SPA-Compatible via ModuleManager
 */

function initEquiposForm() {
    const form = document.getElementById('createEquipoForm') || document.getElementById('editEquipoForm'); // Support both
    if (!form) return;

    // --- HELPER FUNCTIONS (Unified Validation Engine) ---
    const showFieldError = (input, message) => {
        if (!input) return;
        input.classList.add('is-invalid');

        // Custom Dropdown & Autocomplete Support
        const dropdown = input.closest('.custom-dropdown') || input.closest('.custom-form-autocomplete');
        if (dropdown) {
            const trigger = dropdown.querySelector('.dropdown-trigger'); // Only applies to strict dropdowns
            if (trigger) trigger.style.borderColor = '#e53e3e';
        }

        let parent = input.parentNode;
        // If inside a custom wrapper, target the wrapper's parent (where Blade errors live)
        if (dropdown) {
            parent = dropdown.parentNode;
        }

        if (!parent) return;

        // Remove existing
        const existing = parent.querySelectorAll('.error-message-inline');
        existing.forEach(el => el.remove());

        // Add new
        const feedback = document.createElement('span');
        feedback.className = 'error-message-inline';
        feedback.innerText = message;
        parent.appendChild(feedback);
    };

    const clearFieldError = (input) => {
        if (!input) return;
        input.classList.remove('is-invalid');

        // Custom Dropdown & Autocomplete Support
        const dropdown = input.closest('.custom-dropdown') || input.closest('.custom-form-autocomplete');
        if (dropdown) {
            dropdown.classList.remove('is-invalid');
            const trigger = dropdown.querySelector('.dropdown-trigger');
            if (trigger) trigger.style.borderColor = '';
        }

        let parent = input.parentNode;
        // If inside a custom wrapper, target the wrapper's parent
        if (dropdown) {
            parent = dropdown.parentNode;
        }

        if (parent) {
            const existing = parent.querySelectorAll('.error-message-inline');
            existing.forEach(el => el.remove());
        }
    };

    const showGlobalSummary = (messages = []) => {
        const existing = document.getElementById('errorSummary');
        if (existing) existing.remove();

        const summaryHtml = `
            <div id="errorSummary" style="background: #fff5f5; border: 1px solid #fed7d7; color: #c53030; padding: 12px 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                <i class="material-icons" style="color: var(--maquinaria-red);">error_outline</i>
                <span>Atención: Hemos detectado errores. Por favor, verifica los campos marcados en rojo.</span>
            </div>
        `;
        form.insertAdjacentHTML('afterbegin', summaryHtml);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    // --- LIVE VALIDATION ---
    const checkUniqueness = (input) => {
        if (!input.value.trim()) return;
        if (input.dataset.lastChecked === input.value.trim()) return;

        const fieldMap = {
            'SERIAL_CHASIS': 'SERIAL_CHASIS',
            'SERIAL_DE_MOTOR': 'SERIAL_DE_MOTOR',
            'CODIGO_PATIO': 'CODIGO_PATIO',
            'PLACA': 'PLACA',
            'documentacion[PLACA]': 'PLACA'
        };

        const fieldName = fieldMap[input.name] || fieldMap[input.id] || (input.id === 'placa' ? 'PLACA' : null);
        if (!fieldName) return;

        input.dataset.lastChecked = input.value.trim();

        // Loader
        let feedbackLoader = input.parentNode.querySelector('.validation-loader');
        if (!feedbackLoader) {
            feedbackLoader = document.createElement('span');
            feedbackLoader.className = 'validation-loader';
            feedbackLoader.style.fontSize = '12px';
            feedbackLoader.style.color = '#0067b1';
            feedbackLoader.style.fontWeight = '600';

            // Layout Shift Fix: Absolute positioning
            input.parentNode.style.position = 'relative';
            feedbackLoader.style.position = 'absolute';
            feedbackLoader.style.right = '10px';
            feedbackLoader.style.bottom = '8px'; // Adjust based on input height
            feedbackLoader.style.zIndex = '10';

            feedbackLoader.innerText = 'Verificando...';
            input.parentNode.appendChild(feedbackLoader);
        }
        feedbackLoader.style.display = 'block';

        // Assuming endpoint exists
        fetch(`/admin/equipos/check-unique?field=${fieldName}&value=${encodeURIComponent(input.value.trim())}`)
            .then(r => r.json())
            .then(data => {
                feedbackLoader.style.display = 'none';
                if (data.exists) {
                    showFieldError(input, `Este valor ya ha sido registrado.`);
                    input.dataset.isDuplicate = "true";
                } else {
                    clearFieldError(input);
                    input.dataset.isDuplicate = "false";
                }
            })
            .catch(err => {
                console.error(err);
                feedbackLoader.style.display = 'none';
            });
    };

    // Match 'blur' behavior for dropdowns
    window.addEventListener('dropdown-selection', function (e) {
        // e.detail = { dropdownId, value, label, inputName } sent from uicomponents.js
        // Map type (suffix) to input ID
        const type = e.detail.type || e.detail.inputName;
        const inputId = 'input_' + type;
        const input = document.getElementById(inputId);
        if (input) {
            clearFieldError(input);
            // Optionally triggering checkUniqueness if needed (for sensitive dropdowns)
        }
    });

    // FIX 1 & 4: Attach Blur Listeners FIRST (without destructive cloning)
    ['serial_chasis', 'serial_motor', 'codigo_patio', 'placa'].forEach(id => {
        const input = document.getElementById(id);
        if (input && !input.dataset.blurAttached) {
            input.addEventListener('blur', () => checkUniqueness(input));
            input.dataset.blurAttached = 'true';
        }
    });

    // FIX 4: AUTO-CLEAR VALIDATION ERRORS (after blur listeners, prevents conflicts)
    form.querySelectorAll('input, select, textarea').forEach(input => {
        if (!input.dataset.clearAttached) {
            input.addEventListener('input', function () {
                clearFieldError(this);
            });
            input.addEventListener('change', function () {
                clearFieldError(this);
            });
            input.dataset.clearAttached = 'true';
        }
    });

    // --- SUBMIT HANDLER ---
    // Remove previous listener by cloning form? No, risky with other plugins.
    // We rely on ModuleManager running this ONCE per page load.

    // Check if handler already attached?
    if (form.dataset.handlerAttached) return;

    // --- SUBMIT CORE LOGIC ---
    const executeSubmission = (skipPreloader = false) => {
        // B. Clear Errors
        const summary = document.getElementById('errorSummary');
        if (summary) summary.style.display = 'none';

        // C. Client Validation
        let hasEmpty = false;

        form.querySelectorAll('[required]').forEach(input => {
            if (!input.value.trim()) {
                let label = input.closest('div').querySelector('label')?.innerText.replace('*', '').trim() || input.name;
                showFieldError(input, `El campo ${label} es obligatorio.`);
                hasEmpty = true;
            } else {
                clearFieldError(input);
            }
        });

        // FIX 2: Correct field ID mapping (dropdowns use input_* pattern)
        const criticalFields = {
            'input_tipo_equipo': 'Tipo de Equipo',
            'input_categoria_flota': 'Categoría de Flota',
            'input_frente_trabajo': 'Frente de Trabajo',
            'input_estatus': 'Estatus',
            'marca': 'Marca',
            'modelo': 'Modelo'
        };

        Object.entries(criticalFields).forEach(([inputId, label]) => {
            const input = document.getElementById(inputId);
            if (input && !input.value.trim()) {
                showFieldError(input, `El campo ${label} es obligatorio.`);
                hasEmpty = true;
            }
        });

        const invalidInputs = form.querySelectorAll('.is-invalid');
        if (hasEmpty || invalidInputs.length > 0) {
            if (window.hidePreloader) window.hidePreloader();
            showGlobalSummary();
            return;
        }

        // D. Submit - Only show preloader if not already shown
        if (!skipPreloader && typeof window.showPreloader === 'function') {
            window.showPreloader();
        }

        // Lock submit button & Add Spinner
        const submitBtn = form.querySelector('button[type="submit"]');
        let originalBtnContent = '';

        if (submitBtn) {
            originalBtnContent = submitBtn.innerHTML;
            submitBtn.style.width = submitBtn.offsetWidth + 'px'; // Maintain width
            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <i class="material-icons" style="font-size: 18px; animation: spin 1s infinite linear; display: inline-block; vertical-align: middle; margin-right: 5px;">sync</i>
                Procesando...
            `;
        }

        const formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token\"]').getAttribute('content')
            },
            body: formData
        })
            .then(r => r.json().then(data => ({ status: r.status, body: data })))
            .then(({ status, body }) => {
                if (window.hidePreloader) window.hidePreloader();

                if (status === 200 || status === 201) {
                    // DETECT MODE: If editing, redirect to index. If creating, reset form.
                    const isEdit = form.querySelector('input[name="_method"][value="PUT"]');

                    if (isEdit) {
                        // Show success message and redirect
                        if (typeof window.showModal === 'function') {
                            window.showModal({
                                type: 'success',
                                title: '¡Éxito!',
                                message: body.message || 'Equipo actualizado correctamente.',
                                confirmText: 'Aceptar',
                                hideCancel: true,
                                onConfirm: () => {
                                    window.location.href = '/admin/equipos';
                                }
                            });
                        } else {
                            window.location.href = '/admin/equipos';
                        }
                    } else {
                        // CREATE MODE: Reset form immediately (before showing modal)
                        // 1. Standard Form Reset
                        form.reset();

                        // 2. Clear Visual Elements (Custom Dropdowns)
                        form.querySelectorAll('.custom-dropdown').forEach(dropdown => {
                            const input = dropdown.querySelector('input[type="hidden"]');
                            const label = dropdown.querySelector('[data-filter-label]');
                            if (input) input.value = '';
                            if (label) label.innerText = 'SELECCIONE';
                            dropdown.classList.remove('active', 'is-invalid');
                        });

                        // 3. Clear autocomplete inputs (Marca/Modelo)
                        form.querySelectorAll('.custom-form-autocomplete input[type="text"]').forEach(input => {
                            input.value = '';
                        });

                        // 4. Ensure Text Inputs are Empty (Browser consistency)
                        form.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(input => {
                            input.value = '';
                        });

                        // 5. Clear Validation Visuals
                        form.querySelectorAll('.error-message-inline').forEach(el => el.remove());
                        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
                        
                        // 6. Clear Flatpickr calendars
                        form.querySelectorAll('.flatpickr-input').forEach(input => {
                            if (input._flatpickr) input._flatpickr.clear();
                        });

                        // 7. Clear Previsualizations & File buttons natively
                        form.querySelectorAll('[id^="file_"]').forEach(el => el.style.display = 'none');
                        
                        form.querySelectorAll('input[type="file"]').forEach(input => {
                            if (window.updatePdfBtn && input.dataset.metaTarget) {
                                // Trigger empty change to revert wrapper to Add button state
                                input.dispatchEvent(new Event('change'));
                            }
                        });
                        
                        const imgPreview = document.getElementById('preview_equipo');
                        if (imgPreview) {
                            imgPreview.innerHTML = '<i class="material-icons" style="font-size: 16px; color: #cbd5e0;">photo_camera</i>';
                            imgPreview.style.borderColor = '#cbd5e0';
                        }

                        // 6. Reset button
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnContent;
                        }

                        // 7. Reset lastChecked data (for uniqueness validation)
                        form.querySelectorAll('[data-last-checked]').forEach(input => {
                            delete input.dataset.lastChecked;
                            delete input.dataset.isDuplicate;
                        });

                        // 8. Reset Catalog Linking Widget
                        if (typeof window.catalogReset === 'function') {
                            window.catalogReset();
                        } else if (typeof window.ignoreCatalogSuggestion === 'function') {
                            window.ignoreCatalogSuggestion();
                        }

                        window.scrollTo({ top: 0, behavior: 'smooth' });

                        // Show success message AFTER reset
                        if (typeof window.showModal === 'function') {
                            window.showModal({
                                type: 'success',
                                title: '¡Éxito!',
                                message: body.message || 'Equipo registrado correctamente.',
                                confirmText: 'Aceptar',
                                hideCancel: true
                            });
                        }
                    }
                } else if (status === 422) {

                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalBtnContent;
                    }

                    // FIX 3: Server-to-Client error mapping (handle dropdown prefixes)
                    const serverToClientMap = {
                        'TIPO_EQUIPO': 'input_tipo_equipo',
                        'CATEGORIA_FLOTA': 'input_categoria_flota',
                        'FRENTE_TRABAJO': 'input_frente_trabajo',
                        'ESTADO_OPERATIVO': 'input_estatus'
                    };

                    let errorsDisplayed = 0;
                    Object.entries(body.errors).forEach(([field, msgs]) => {
                        const inputId = serverToClientMap[field] || field;
                        let input = document.getElementById(inputId) || form.querySelector(`[name="${field}"]`);

                        if (!input && field.includes('.')) {
                            const parts = field.split('.');
                            const bracketName = parts.shift() + parts.map(p => `[${p}]`).join('');
                            input = form.querySelector(`[name="${bracketName}"]`);
                        }

                        if (input) {
                            showFieldError(input, msgs[0]);
                            errorsDisplayed++;
                        }
                    });

                    showGlobalSummary();
                } else {
                    throw new Error(body.message || 'Error desconocido.');
                }
            })
            .catch(err => {
                if (window.hidePreloader) window.hidePreloader();
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnContent;
                }
                console.error(err);
                if (typeof window.showModal === 'function') window.showModal({ type: 'error', title: 'Error', message: 'Ocurrió un error inesperado.', confirmText: 'Cerrar', hideCancel: true });
            });
    };

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();

        // 0. Permission Check
        const isEdit = form.querySelector('input[name="_method"][value="PUT"]');
        const canSubmit = isEdit ? window.CAN_UPDATE_INFO : window.CAN_CREATE_EQUIPOS;

        // Safety: If permission flag is somehow undefined, assume false for security
        if (typeof canSubmit === 'undefined' || canSubmit === false) {
            if (window.showModal) {
                showModal({
                    type: 'error',
                    title: 'Acceso Denegado',
                    message: isEdit ? 'No tienes permisos para actualizar esta información.' : 'No tienes permisos para registrar equipos.',
                    confirmText: 'Entendido',
                    hideCancel: true
                });
            } else {
                alert('Acceso Denegado: No tienes permisos.');
            }
            return;
        }

        // A. Pending Validation Check (Wait Mode)
        const pendingValidations = () => Array.from(form.querySelectorAll('.validation-loader')).filter(el => el.style.display !== 'none');

        if (pendingValidations().length > 0) {
            // Show Preloader once
            if (typeof window.showPreloader === 'function') window.showPreloader();

            // Poll for completion
            const checkInterval = setInterval(() => {
                if (pendingValidations().length === 0) {
                    clearInterval(checkInterval);
                    // Proceed with skipPreloader=true to avoid double show
                    executeSubmission(true);
                }
            }, 100);
            return;
        }

        // Proceed immediately if no checks pending
        executeSubmission();
    });

    form.dataset.handlerAttached = "true";
}

// Register with Module Manager if available
if (typeof ModuleManager !== 'undefined') {
    ModuleManager.register('equipos_form',
        () => document.getElementById('createEquipoForm') !== null || document.getElementById('editEquipoForm') !== null,
        initEquiposForm
    );
}

// Standard Init (Fallback/Primary)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEquiposForm);
} else {
    initEquiposForm();
}

// Listen for SPA navigation to reset handler flag
window.addEventListener('spa:contentLoaded', function () {
    const form = document.getElementById('createEquipoForm') || document.getElementById('editEquipoForm');
    if (form) {
        // Reset flag to allow reinitialization
        form.dataset.handlerAttached = null;
        initEquiposForm();
    }
});
