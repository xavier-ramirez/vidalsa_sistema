@extends('layouts.estructura_base')

@section('title', 'Editar Equipo Auxiliar')

@section('content')
<section class="page-title-card" style="margin: 0 auto 10px auto; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Editar Equipo Auxiliar #{{ $auxiliar->ID_AUXILIAR }}</span>
    </h1>
    <div style="margin-top: 10px;">
        <a href="{{ route('equipos-auxiliares.acta', $auxiliar->ID_AUXILIAR) }}" target="_blank"
           class="btn-primary-maquinaria btn-secondary"
           style="display: inline-flex; align-items: center; gap: 6px;">
            <i class="material-icons" style="font-size: 18px;">description</i>
            Acta de Asignación
        </a>
    </div>
</section>

<div id="formEquipoAuxiliarCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="equipoAuxiliarForm" action="{{ route('equipos-auxiliares.update', $auxiliar->ID_AUXILIAR) }}" method="POST" novalidate data-is-edit="1">
        @csrf
        @method('PATCH')

        @include('admin.equipos_auxiliares.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                @can('super.admin')
                <form action="{{ route('equipos-auxiliares.destroy', $auxiliar->ID_AUXILIAR) }}" method="POST"
                      onsubmit="return confirm('¿Eliminar este equipo auxiliar? Esta acción no se puede deshacer.')"
                      style="margin: 0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-primary-maquinaria"
                            style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">
                        <i class="material-icons">delete</i>
                        Eliminar
                    </button>
                </form>
                @endcan
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="{{ route('equipos-auxiliares.index') }}" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </a>
                <button type="submit" class="btn-primary-maquinaria">
                    <i class="material-icons">save</i>
                    Guardar cambios
                </button>
            </div>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('equipoAuxiliarForm');
    if (!form || form.dataset.ajaxBound === '1') return;
    form.dataset.ajaxBound = '1';

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
                try {
                    sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                        message: body.message || 'Equipo auxiliar actualizado correctamente.',
                        type: 'success'
                    }));
                } catch (_) {}
                const redirect = body.redirect || '{{ route("equipos-auxiliares.index") }}';
                if (typeof window.navigateTo === 'function') window.navigateTo(redirect);
                else window.location.href = redirect;
                return;
            }

            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            form.dataset.submitting = '0';

            if (status === 422 && body.errors) {
                const firstField = Object.keys(body.errors)[0];
                const firstMsg   = body.errors[firstField]?.[0] ?? 'Datos invalidos.';
                if (window.showModal) {
                    window.showModal({
                        type: firstField === 'SERIAL' || firstField === 'CODIGO_INTERNO' ? 'warning' : 'error',
                        title: firstField === 'SERIAL' ? 'Serial duplicado' : (firstField === 'CODIGO_INTERNO' ? 'Codigo interno duplicado' : 'Revisa los datos'),
                        message: firstMsg,
                        confirmText: 'Entendido',
                        hideCancel: true
                    });
                } else if (window.showToast) {
                    window.showToast(firstMsg, 'error');
                } else {
                    alert(firstMsg);
                }
                return;
            }

            const msg = body.message || 'No se pudo actualizar el equipo auxiliar.';
            if (window.showModal) window.showModal({ type:'error', title:'Error', message: msg, confirmText:'Entendido', hideCancel:true });
            else if (window.showToast) window.showToast(msg, 'error');
        })
        .catch(err => {
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            form.dataset.submitting = '0';
            console.error('update auxiliar:', err);
            if (window.showModal) window.showModal({ type:'error', title:'Error de red', message:'No se pudo contactar el servidor.', confirmText:'Entendido', hideCancel:true });
        });
    });
})();
</script>
@endsection
