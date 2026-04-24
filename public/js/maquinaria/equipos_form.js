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

        // En modo edit, pasar el id del equipo para excluirlo del check (sino
        // el propio valor del equipo se detecta como "duplicado consigo mismo").
        const editMethodInput = form.querySelector('input[name="_method"][value="PUT"]');
        const equipoId = editMethodInput
            ? (form.action.match(/equipos\/(\d+)/) || [])[1]
            : null;
        const idParam = equipoId ? `&id=${encodeURIComponent(equipoId)}` : '';

        // AbortController con timeout: evita que un fetch colgado bloquee
        // indefinidamente el setInterval del submit (deadlock que congela UI).
        const ctrl = new AbortController();
        const timeoutId = setTimeout(() => ctrl.abort(), 8000);

        fetch(`/admin/equipos/check-unique?field=${fieldName}&value=${encodeURIComponent(input.value.trim())}${idParam}`, { signal: ctrl.signal })
            .then(r => r.json())
            .then(data => {
                clearTimeout(timeoutId);
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
                clearTimeout(timeoutId);
                // Fuerza ocultar el loader aunque el fetch haya fallado, asi
                // el submit no queda esperando forever via setInterval.
                feedbackLoader.style.display = 'none';
                console.error('checkUniqueness:', err);
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
    const executeSubmission = () => {
        // B. Clear Errors
        const summary = document.getElementById('errorSummary');
        if (summary) summary.style.display = 'none';

        // Resolve button references from dataset (set by the submit listener before calling us)
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalBtnContent = form.dataset.originalBtnContent || '';

        /** Restore the submit button to its original state */
        const restoreBtn = () => {
            if (submitBtn && originalBtnContent) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnContent;
                submitBtn.style.width = '';
            }
            form.dataset.isSubmitting = 'false';
        };

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
            if (!input) return; // campo no existe en este formulario, saltar
            if (!input.value.trim()) {
                showFieldError(input, `El campo ${label} es obligatorio.`);
                hasEmpty = true;
            }
        });

        const invalidInputs = form.querySelectorAll('.is-invalid');
        if (hasEmpty || invalidInputs.length > 0) {
            if (window.hidePreloader) window.hidePreloader();
            showGlobalSummary();
            restoreBtn();
            return;
        }

        // D. Submit
        const formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: formData
        })
            .then(r => {
                // Guardia: si la respuesta no es JSON (p.ej. redirect 302 / HTML),
                // el servidor no processó la solicitud como AJAX. Lanzar error claro.
                const ct = r.headers.get('Content-Type') || '';
                if (!ct.includes('application/json')) {
                    throw new Error('La respuesta del servidor no es JSON. Posible redirect inesperado.');
                }
                return r.json().then(data => ({ status: r.status, body: data }));
            })
            .then(({ status, body }) => {
                if (status === 200 || status === 201) {
                    const isEdit = form.querySelector('input[name="_method"][value="PUT"]');

                    if (isEdit) {
                        // UX: mantener el preloader activo y navegar INMEDIATO al listado.
                        // El toast se muestra en la pagina destino via sessionStorage + hook
                        // de estructura_base. Evita el parpadeo "form visible con toast" antes
                        // del redirect que causaba el setTimeout(1200ms).
                        try {
                            sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                                message: body.message || 'Equipo actualizado correctamente.',
                                type: 'success',
                            }));
                        } catch (_) { /* silencioso si sessionStorage no disponible */ }

                        const backUrl = body.redirect || '/admin/equipos';
                        window.__vidalsaRedirecting = true;
                        if (typeof window.navigateTo === 'function') {
                            window.navigateTo(backUrl);
                        } else {
                            window.location.href = backUrl;
                        }
                        return;
                    } else {
                        if (window.hidePreloader) window.hidePreloader();
                        // CREATE MODE: Reset form
                        form.reset();

                        form.querySelectorAll('.custom-dropdown').forEach(dropdown => {
                            const input = dropdown.querySelector('input[type="hidden"]');
                            const label = dropdown.querySelector('[data-filter-label]');
                            if (input) input.value = '';
                            if (label) label.innerText = 'SELECCIONE';
                            dropdown.classList.remove('active', 'is-invalid');
                        });

                        form.querySelectorAll('.custom-form-autocomplete input[type="text"]').forEach(input => {
                            input.value = '';
                        });

                        form.querySelectorAll('input[type="text"], input[type="number"], textarea').forEach(input => {
                            input.value = '';
                        });

                        form.querySelectorAll('.error-message-inline').forEach(el => el.remove());
                        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));

                        form.querySelectorAll('.flatpickr-input').forEach(input => {
                            if (input._flatpickr) input._flatpickr.clear();
                        });

                        form.querySelectorAll('[id^="file_"]').forEach(el => el.style.display = 'none');

                        form.querySelectorAll('input[type="file"]').forEach(input => {
                            if (window.updatePdfBtn && input.dataset.metaTarget) {
                                input.dispatchEvent(new Event('change'));
                            }
                        });

                        const imgPreview = document.getElementById('preview_equipo');
                        if (imgPreview) {
                            imgPreview.innerHTML = '<i class="material-icons" style="font-size: 16px; color: #cbd5e0;">photo_camera</i>';
                            imgPreview.style.borderColor = '#cbd5e0';
                        }

                        // Restore button after successful create
                        restoreBtn();

                        form.querySelectorAll('[data-last-checked]').forEach(input => {
                            delete input.dataset.lastChecked;
                            delete input.dataset.isDuplicate;
                        });

                        if (typeof window.catalogReset === 'function') {
                            window.catalogReset();
                        } else if (typeof window.ignoreCatalogSuggestion === 'function') {
                            window.ignoreCatalogSuggestion();
                        }

                        window.scrollTo({ top: 0, behavior: 'smooth' });

                        if (typeof window.showToast === 'function') {
                            window.showToast(body.message || '¡Equipo registrado correctamente!', 'success');
                        }
                    }
                } else if (status === 422) {
                    if (window.hidePreloader) window.hidePreloader();
                    restoreBtn();

                    const serverToClientMap = {
                        'TIPO_EQUIPO':        'input_tipo_equipo',
                        'CATEGORIA_FLOTA':    'input_categoria_flota',
                        'ID_FRENTE_ACTUAL':   'input_frente_trabajo',
                        'FRENTE_TRABAJO':     'input_frente_trabajo',
                        'ESTADO_OPERATIVO':   'input_estatus',
                        'CODIGO_PATIO':       'codigo_patio',
                        'SERIAL_CHASIS':      'serial_chasis',
                        'SERIAL_DE_MOTOR':    'serial_motor',
                        'MARCA':              'marca',
                        'MODELO':             'modelo',
                        'ANIO':               'anio',
                    };

                    Object.entries(body.errors).forEach(([field, msgs]) => {
                        const inputId = serverToClientMap[field] || field;
                        let input = document.getElementById(inputId) || form.querySelector(`[name="${field}"]`);

                        if (!input && field.includes('.')) {
                            const parts = field.split('.');
                            const bracketName = parts.shift() + parts.map(p => `[${p}]`).join('');
                            input = form.querySelector(`[name="${bracketName}"]`);
                        }

                        if (input) showFieldError(input, msgs[0]);
                    });

                    showGlobalSummary();
                } else {
                    throw new Error(body.message || 'Error desconocido.');
                }
            })
            .catch(err => {
                if (window.hidePreloader) window.hidePreloader();
                restoreBtn();
                console.error('Form submit error:', err);
                const msg = (err && err.message && err.message !== 'Failed to fetch')
                    ? err.message
                    : 'Ocurrió un error de red. Verifique su conexión e intente de nuevo.';
                if (typeof window.showModal === 'function') window.showModal({ type: 'error', title: 'Error', message: msg, confirmText: 'Cerrar', hideCancel: true });
            });
    };

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopPropagation();

        if (form.dataset.isSubmitting === 'true') return;
        form.dataset.isSubmitting = 'true';

        // Disparar espiner global blanco INMEDIATAMENTE
        if (typeof window.showPreloader === 'function') {
            window.showPreloader();
        }

        // Lock submit button
        const submitBtn = form.querySelector('button[type="submit"]');
        let originalBtnContent = '';
        if (submitBtn) {
            originalBtnContent = submitBtn.innerHTML;
            submitBtn.disabled = true;
        }

        // 0. Permission Check
        const isEdit = form.querySelector('input[name="_method"][value="PUT"]');
        const canSubmit = isEdit ? window.CAN_UPDATE_INFO : window.CAN_CREATE_EQUIPOS;

        if (typeof canSubmit === 'undefined' || canSubmit === false) {
            if (window.hidePreloader) window.hidePreloader();
            form.dataset.isSubmitting = 'false';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnContent;
            }
            if (typeof window.showModal === 'function') {
                window.showModal({
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

        const safeExecute = () => {
            form.dataset.originalBtnContent = originalBtnContent;
            executeSubmission();
        };

        // A. Pending Validation Check (Wait Mode)
        const pendingValidations = () => Array.from(form.querySelectorAll('.validation-loader')).filter(el => el.style.display !== 'none');

        if (pendingValidations().length > 0) {
            const checkInterval = setInterval(() => {
                if (pendingValidations().length === 0) {
                    clearInterval(checkInterval);
                    safeExecute();
                }
            }, 100);
            return;
        }

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                safeExecute();
            });
        });
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

// Direct init fallback (ModuleManager may init before modules register)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEquiposForm);
} else {
    initEquiposForm();
}

// SPA navigation listener
window.addEventListener('spa:contentLoaded', function () {
    const form = document.getElementById('createEquipoForm') || document.getElementById('editEquipoForm');
    if (form) {
        // Reset flag para permitir reinicialización.
        // Usar delete (no = null): el setter de dataset convierte null a la string "null", que es truthy
        // y bloquea el early-return en initEquiposForm (línea ~177), dejando al form sin handler de submit.
        delete form.dataset.handlerAttached;
        delete form.dataset.isSubmitting;
        initEquiposForm();
    }
});

