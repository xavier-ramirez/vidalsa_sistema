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
            // submitBtn.innerHTML = '<div style="display: inline-flex; align-items: center; gap: 8px;"><div class="spinner-mini-white"></div><span>Guardando...</span></div>';
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
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
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
                // Success: mantenemos el preloader activo y redirigimos inmediatamente.
                // El toast de exito sale en la pagina destino via session flash
                // (bloque @if(session('success')) en estructura_base.blade.php).
                // Evita parpadeo del form reseteado antes del redirect.
                if (data.redirect) {
                    window.__catalogoRedirecting = true;
                    window.location.href = data.redirect;
                    return;
                }
                // Fallback si el backend no envia redirect: apagar preloader y notificar.
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
                // Si estamos redirigiendo (success), NO apagamos el preloader ni
                // restauramos el boton: el navegador esta cargando la siguiente pagina
                // y queremos que el spinner se mantenga hasta que termine la transicion.
                if (window.__catalogoRedirecting) return;
                if (window.hidePreloader) window.hidePreloader();
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnContent;
                }
            });
    }

    // Initialize form handler
    function initCatalogoForm() {
        const form = document.getElementById('catalogoForm');
        if (!form) return;

        // Ensure we don't bind multiple times, but never clone the form 
        // because cloning destroys user file selections and DOM references
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

        // Re-attach preview logic since we cloned the form
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

        console.log('Catalog Form Handler Initialized (Robust Mode)');
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
