// catalogo_create.js - Catalog Form Handler
// Compatible with SPA navigation (navegacion.js)

(function () {
    'use strict';

    function handleSubmit(e) {
        e.preventDefault();
        const form = e.target;

        // Show global preloader IMMEDIATELY
        if (typeof window.showPreloader === 'function') {
            window.showPreloader();
        } else {
            console.warn('window.showPreloader is not defined');
        }

        // Lock submit button
        const submitBtn = form.querySelector('button[type="submit"]');
        let originalBtnContent = '';

        if (submitBtn) {
            originalBtnContent = submitBtn.innerHTML;
            submitBtn.style.width = submitBtn.offsetWidth + 'px';
            submitBtn.disabled = true;
        }

        const formData = new FormData(form);

        fetch(form.action, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                // Add CSRF Token explicitly if needed, though cookie usually handles it. 
                // equipso_form.js adds it manually, so we should too for consistency.
                'X-CSRF-TOKEN': window.getCsrf()
            }
        })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        try {
                            // Try parsing as JSON first
                            const errorData = JSON.parse(text);
                            throw { status: response.status, body: errorData };
                        } catch (e) {
                            // If parsing fails, it's likely an HTML error page (500, etc.)
                            console.error('Raw Server Error Response:', text);
                            throw {
                                status: response.status,
                                message: `Error del Servidor (${response.status}) - Ver consola para detalles.`,
                                raw: text
                            };
                        }
                    });
                }
                return response.json();
            })
            .then(data => {
                // Success: mantenemos el preloader activo y navegamos inmediato
                // (SPA si disponible, full-reload como fallback). El toast aparece
                // en la pagina destino via sessionStorage + hook global de
                // estructura_base. Evita parpadeo del form reseteado.
                if (data.redirect) {
                    try {
                        sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                            message: data.message || 'Modelo guardado correctamente.',
                            type: 'success',
                        }));
                    } catch (_) { /* silencioso si sessionStorage no disponible */ }
                    window.__vidalsaRedirecting = true;
                    if (typeof window.navigateTo === 'function') {
                        window.navigateTo(data.redirect);
                    } else {
                        window.location.href = data.redirect;
                    }
                    return;
                }
                // Fallback: apagar preloader y notificar inline.
                if (window.hidePreloader) window.hidePreloader();
                if (window.showToast) {
                    window.showToast(data.message || 'Modelo guardado correctamente.', 'success');
                } else {
                    alert(data.message || 'Operación realizada correctamente.');
                }
            })
            .catch(error => {
                if (window.hidePreloader) window.hidePreloader(); // Hide on error

                console.error('Error:', error);
                let errorMsg = 'Ocurrió un error inesperado.';

                if (error.status === 422 && error.body && error.body.errors) {
                    errorMsg = Object.values(error.body.errors).flat().join('\n');
                } else if (error.body && error.body.message) {
                    errorMsg = error.body.message;
                } else if (error.message) {
                    errorMsg = error.message;
                }

                if (window.showModal) {
                    window.showModal({
                        type: 'error',
                        title: 'Error',
                        message: errorMsg,
                        confirmText: 'Entendido',
                        hideCancel: true
                    });
                } else {
                    alert(errorMsg);
                }
            })
            .finally(() => {
                // Si estamos redirigiendo (success), NO apagamos preloader ni
                // restauramos el boton: el navegador esta cargando la siguiente
                // pagina y queremos que el spinner se mantenga hasta la transicion.
                if (window.__vidalsaRedirecting) return;
                if (window.hidePreloader) window.hidePreloader();
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnContent;
                }
            });
    }

    // Sugerencia de MODELO según el TIPO elegido. Rellena el dropdown de Modelo con
    // los modelos de equipos de ese tipo (/admin/catalogo/models-from-equipos?tipo=...).
    // El campo sigue siendo de escritura libre: el usuario puede teclear uno nuevo.
    function scopeCatalogoModelos() {
        const tipoInput = document.getElementById('TIPO');
        const modeloInput = document.getElementById('MODELO');
        if (!tipoInput || !modeloInput) return;
        const container = modeloInput.closest('.custom-form-autocomplete');
        const dropdown = container ? container.querySelector('.dropdown-list') : null;
        if (!dropdown) return;

        const tipo = (tipoInput.value || '').trim();
        const url = '/admin/catalogo/models-from-equipos' + (tipo ? ('?tipo=' + encodeURIComponent(tipo)) : '');

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(models => {
                dropdown.innerHTML = '';
                (models || []).forEach(m => {
                    if (!m || !String(m).trim()) return;
                    const div = document.createElement('div');
                    div.className = 'dropdown-item';
                    div.textContent = m;
                    div.onmousedown = function () { window.selectDropdownItem(this, m); };
                    dropdown.appendChild(div);
                });
                if (!dropdown.children.length) {
                    dropdown.innerHTML = '<div class="dropdown-item" style="color:#a0aec0;font-style:italic;">Sin modelos para este tipo (puedes escribir uno nuevo)</div>';
                }
                // Marcar cargado para que showFormDropdown (form_logic.js) no lo pise
                // con la lista completa de modelos.
                dropdown.dataset.loaded = 'true';
            })
            .catch(() => { /* silencioso: si falla, queda la lista previa */ });
    }

    // Initialize form handler
    function initCatalogoForm() {
        const form = document.getElementById('catalogoForm');
        if (!form) return;

        // Evita re-bind múltiple. NO clonamos el form porque eso destruiría las
        // selecciones de archivos del usuario y las referencias DOM existentes.
        if (form.dataset.formInitialized === 'true') return;
        form.dataset.formInitialized = 'true';

        form.addEventListener('submit', handleSubmit);

        // Auto-fetch years when Model changes (Manual typing)
        const modelInput = form.querySelector('#MODELO');
        if (modelInput) {
            modelInput.addEventListener('blur', function () {
                if (window.checkCatalogMatch) window.checkCatalogMatch();
            });
        }

        // Al elegir/cambiar el TIPO, sugerir los modelos de ese tipo en el campo Modelo.
        // (selectDropdownItem despacha 'change'; escribir + salir del campo también.)
        const tipoInput = form.querySelector('#TIPO');
        if (tipoInput && tipoInput.dataset.scopeBound !== 'true') {
            tipoInput.dataset.scopeBound = 'true';
            tipoInput.addEventListener('change', scopeCatalogoModelos);
        }

        // Preview de la foto referencial al elegir archivo
        const fileInput = form.querySelector('#foto_referencial');
        if (fileInput) {
            fileInput.addEventListener('change', function (e) {
                if (e.target.files && e.target.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function (evt) {
                        const preview = document.getElementById('preview_referencial');
                        if (preview) {
                            preview.innerHTML = `<img src="${evt.target.result}" style="max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 4px;">`;
                            preview.style.borderColor = 'var(--maquinaria-blue)';
                        }
                    }
                    reader.readAsDataURL(e.target.files[0]);
                }
            });
        }
    }

    // Run on initial page load
    document.addEventListener('DOMContentLoaded', initCatalogoForm);

    // Run after SPA navigation (navegacion.js dispatches this event)
    window.addEventListener('spa:contentLoaded', initCatalogoForm);

    // Also try immediately in case DOM is already ready
    if (document.readyState !== 'loading') {
        initCatalogoForm();
    }
})();
