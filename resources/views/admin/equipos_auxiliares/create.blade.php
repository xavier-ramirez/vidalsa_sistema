@extends('layouts.estructura_base')

@section('title', 'Registrar Equipo Auxiliar')

@section('content')
<section class="page-title-card" style="margin: 0 auto 10px auto; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Registro de Equipo Auxiliar</span>
    </h1>
</section>

<div id="formEquipoAuxiliarCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="equipoAuxiliarForm" action="{{ route('equipos-auxiliares.store') }}" method="POST" enctype="multipart/form-data" novalidate>
        @csrf

        @include('admin.equipos_auxiliares.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('equipos-auxiliares.index') }}" class="btn-primary-maquinaria btn-secondary">
                Cancelar
            </a>
            <button type="submit" class="btn-primary-maquinaria">
                <i class="material-icons">save</i>
                Registrar
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('equipoAuxiliarForm');
    if (!form || form.dataset.ajaxBound === '1') return;
    form.dataset.ajaxBound = '1';

    // ── Helper: mostrar errores de validacion estilo /admin/equipos/create ──
    // Banner arriba + .is-invalid en inputs + .error-message-inline debajo.
    window.auxApplyValidationErrors = function (errors) {
        // Limpiar errores anteriores
        form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
        form.querySelectorAll('.error-message-inline').forEach(el => el.remove());
        const oldSummary = document.getElementById('errorSummary');
        if (oldSummary) oldSummary.remove();

        // Banner global arriba del form
        const summaryHtml = `
            <div id="errorSummary" style="background: #fff5f5; border: 1px solid #fed7d7; color: #c53030; padding: 12px 15px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 14px; font-weight: 600;">
                <i class="material-icons" style="color: var(--maquinaria-red);">error_outline</i>
                <span>Atención: Hemos detectado errores. Por favor, verifica los campos marcados en rojo.</span>
            </div>
        `;
        form.insertAdjacentHTML('afterbegin', summaryHtml);

        // Marcar cada campo con error + mensaje debajo
        Object.entries(errors).forEach(([field, msgs]) => {
            const msg = Array.isArray(msgs) ? msgs[0] : String(msgs);
            // Map server field -> input id (server devuelve el name del campo)
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

        // Scroll al primer campo con error
        const firstInvalid = form.querySelector('.is-invalid');
        if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (form.dataset.submitting === '1') return;
        form.dataset.submitting = '1';

        if (typeof window.showPreloader === 'function') window.showPreloader();

        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? ''
            },
            body: formData,
            credentials: 'same-origin'
        })
        .then(r => r.json().then(body => ({ status: r.status, body })))
        .then(({ status, body }) => {
            if (status === 200 || status === 201) {
                const msg = body.message || 'Equipo auxiliar registrado correctamente.';
                // Disparar toast INMEDIATO (si existe) ademas de guardarlo en
                // sessionStorage como fallback para cuando navigateTo recargue.
                if (typeof window.showToast === 'function') window.showToast(msg, 'success');
                try {
                    sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({ message: msg, type: 'success' }));
                } catch (_) {}
                const redirect = body.redirect || '{{ route("equipos-auxiliares.index") }}';
                if (typeof window.navigateTo === 'function') window.navigateTo(redirect);
                else window.location.href = redirect;
                return;
            }

            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            form.dataset.submitting = '0';

            if (status === 422 && body.errors) {
                // Patron identico a /admin/equipos/create: banner arriba + .is-invalid
                // en cada input + .error-message-inline debajo.
                window.auxApplyValidationErrors(body.errors);
                return;
            }

            const msg = body.message || 'No se pudo registrar el equipo auxiliar.';
            if (window.showModal) window.showModal({ type:'error', title:'Error', message: msg, confirmText:'Entendido', hideCancel:true });
            else if (window.showToast) window.showToast(msg, 'error');
        })
        .catch(err => {
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            form.dataset.submitting = '0';
            console.error('store auxiliar:', err);
            if (window.showModal) window.showModal({ type:'error', title:'Error de red', message:'No se pudo contactar el servidor.', confirmText:'Entendido', hideCancel:true });
        });
    });
})();
</script>
@endsection
