{{-- ═══════════════════════════════════════════════════════════════════════════════
     Envío por AJAX del formulario de equipo auxiliar.

     HOY LO USA UNA SOLA VISTA: edit.blade.php. Nació para dejar de estar copiado
     entre create y edit —62 de las 83 líneas útiles de create eran las mismas de
     edit, el 75%— pero en ese mismo trabajo se vio que create.blade.php no lo pintaba
     nadie: EquipoAuxiliarController::create() hace redirect() al formulario unificado
     de /admin/equipos/create, que tiene su propio envío. Por eso create se borró.
     Se conserva como partial porque es lo que deja edit.blade.php en 41 líneas
     legibles en vez de 160, con el <script> metido dentro.

     Recibe (hoy los tres llegan fijos desde edit; siguen parametrizados para que sea
     la VISTA quien decida los textos, no este archivo):
       $verbo    → 'actualizado'  (mensaje de éxito)
       $verboMal → 'actualizar'   (mensaje de error)
       $accion   → 'update'       (etiqueta del console.error)

     El formulario se busca por id (#equipoAuxiliarForm).
═══════════════════════════════════════════════════════════════════════════════ --}}
<script>
(function () {
    const form = document.getElementById('equipoAuxiliarForm');
    // Guard SPA: navegacion.js re-ejecuta los <script> de la vista en cada entrada.
    // Sin esto se acumularía un listener de submit por visita y el POST saldría
    // repetido tantas veces como se hubiera entrado al formulario.
    if (!form || form.dataset.ajaxBound === '1') return;
    form.dataset.ajaxBound = '1';

    // ── Errores de validación, mismo patrón que /admin/equipos ──────────────────
    // Banner arriba del form + .is-invalid en cada input + .error-message-inline
    // debajo. Global porque también lo llama el flujo de anclaje.
    window.auxApplyValidationErrors = function (errors) {
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.error-message-inline').forEach(el => el.remove());
        const oldSummary = document.getElementById('errorSummary');
        if (oldSummary) oldSummary.remove();

        const summaryHtml = `
            <div id="errorSummary" style="background: #fff5f5; border: 1px solid #fed7d7; color: #c53030; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 600;">
                <i class="material-icons" style="color: var(--maquinaria-red);">error_outline</i>
                <span>Atención: Hemos detectado errores. Por favor, verifica los campos marcados en rojo.</span>
            </div>
        `;
        form.insertAdjacentHTML('afterbegin', summaryHtml);

        Object.entries(errors).forEach(([field, msgs]) => {
            const msg = Array.isArray(msgs) ? msgs[0] : String(msgs);
            // El servidor devuelve el NAME del campo; el id coincide en casi todos.
            const input = document.getElementById(field)
                       || document.querySelector(`[name="${field}"]`);
            if (!input) return;

            input.classList.add('is-invalid');
            const dropdown = input.closest('.custom-dropdown');
            if (dropdown) {
                dropdown.classList.add('is-invalid');
                const trigger = dropdown.querySelector('.dropdown-trigger');
                if (trigger) trigger.style.borderColor = '#e53e3e';
            }

            const parent = dropdown ? dropdown.parentNode : input.parentNode;
            if (!parent) return;
            const feedback = document.createElement('span');
            feedback.className = 'error-message-inline';
            feedback.innerText = msg;
            parent.appendChild(feedback);
        });

        const firstInvalid = form.querySelector('.is-invalid');
        if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    // ── Envío ───────────────────────────────────────────────────────────────────
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        // Doble clic en Guardar: sin esto salían dos POST y se creaban dos equipos.
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1';
        if (typeof window.showPreloader === 'function') window.showPreloader();

        const formData = new FormData(form);
        window.apiFetch(form.action, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            method: 'POST',
            body: formData
        })
        .then(r => r.json().then(body => ({ status: r.status, body })))
        .then(({ status, body }) => {
            if (status === 200 || status === 201) {
                const msg = body.message || 'Equipo auxiliar {{ $verbo }} correctamente.';
                // El aviso viaja a la pantalla destino: aquí se navega enseguida y un
                // toast pintado ahora se iría con el DOM antes de que diera tiempo a leerlo.
                try {
                    sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({ message: msg, type: 'success' }));
                } catch (_) {}

                // Al módulo UNIFICADO por defecto (si no vino ?ref=), no al viejo de solo-auxiliares.
                const redirect = body.redirect || '{{ route("equipos.index") }}';

                // HANDOFF del spinner (ver loadPage en navegacion.js): cedemos el show()
                // de arriba, que aquí no se balancea. Sin el flag, loadPage suma otro
                // show() y su único hide() deja el contador en 1 → el spinner se queda
                // tapando el módulo destino ya cargado.
                window.__vidalsaRedirecting = true;
                if (typeof window.navigateTo === 'function') window.navigateTo(redirect);
                else window.location.href = redirect;
                return;
            }

            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            form.dataset.submitting = '0';

            if (status === 422 && body.errors) {
                window.auxApplyValidationErrors(body.errors);
                return;
            }

            const msg = body.message || 'No se pudo {{ $verboMal }} el equipo auxiliar.';
            if (window.showModal) window.showModal({ type: 'error', title: 'Error', message: msg, confirmText: 'Entendido', hideCancel: true });
            else window.toast(msg, 'error');
        })
        .catch(err => {
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            form.dataset.submitting = '0';
            console.error('{{ $accion }} auxiliar:', err);
            if (window.showModal) window.showModal({ type: 'error', title: 'Error de red', message: 'No se pudo conectar con el servidor. Intenta de nuevo.', confirmText: 'Entendido', hideCancel: true });
        });
    });
})();
</script>
