@extends('layouts.estructura_base')

@section('title', 'Mi Usuario')

@section('content')

    <link rel="stylesheet" href="{{ asset('css/vistas/admin_usuarios_mi_perfil.css') }}?v={{ @filemtime(public_path('css/vistas/admin_usuarios_mi_perfil.css')) }}">

    <div class="perfil-centro">
    <div class="admin-card perfil-wrap">

        {{-- Alertas Globales (Fallback si no usa AJAX) --}}
        @if(session('success_perfil'))
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof window.showToast === 'function') {
                        window.showToast("{{ session('success_perfil') }}", 'success');
                    }
                });
            </script>
        @endif

        {{-- ── Identidad + datos, en una sola cabecera compacta ── --}}
        {{-- Cabecera EN COLUMNA y centrada: avatar arriba, y debajo nombre, correo y cargo.
             Se quitaron a pedido del cliente el estado ACTIVO/INACTIVO y las etiquetas de
             nivel (Equipos/Almacén): son datos que el usuario no puede cambiar desde aquí y
             esta pantalla es solo para su contraseña. --}}
        <div class="perfil-hero">
            <div class="perfil-avatar"><i class="material-icons">person</i></div>
            <div class="perfil-ident">
                <div class="perfil-nombre">{{ $user->NOMBRE_COMPLETO ?? '—' }}</div>
                <div class="perfil-correo">{{ $user->CORREO_ELECTRONICO ?? '—' }}</div>
                <div class="perfil-chips">
                    <span class="pf-chip pf-chip-rol">{{ $user->rol->NOMBRE_ROL ?? 'Sin Rol' }}</span>
                </div>
            </div>
        </div>

        <hr class="perfil-sep">

        {{-- ── Cambio de Contraseña ── --}}
        <div class="perfil-section-title">
            <i class="material-icons">lock</i>
            Cambiar contraseña
        </div>
        <p class="perfil-section-sub">Usa al menos 6 caracteres. Se cierra la sesión en los demás dispositivos.</p>

        @if($errors->any())
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    if (typeof window.showToast === 'function') {
                        window.showToast("{{ $errors->first() }}", 'error');
                    }
                });
            </script>
        @endif

        <form method="POST" action="{{ route('usuarios.actualizarMiClave') }}" id="frmMiClave" autocomplete="off">
            @csrf
            @method('PUT')

            <div style="display: flex; flex-direction: column; gap: 10px;">

                <div>
                    <label class="pw-label">
                        Nueva Contraseña
                    </label>
                    <div class="pw-input-wrap">
                        <input type="password" name="password" id="pw_nueva" placeholder="Mínimo 6 caracteres..."
                            autocomplete="new-password" readonly data-sin-autollenado>
                        <i class="material-icons pw-toggle-icon"
                            onclick="window.togglePw('pw_nueva', this)">visibility_off</i>
                    </div>
                </div>

                <div>
                    <label class="pw-label">
                        Confirmar Contraseña
                    </label>
                    <div class="pw-input-wrap">
                        <input type="password" name="password_confirmation" id="pw_confirm"
                            placeholder="Repite la contraseña..." autocomplete="new-password" readonly data-sin-autollenado>
                        <i class="material-icons pw-toggle-icon"
                            onclick="window.togglePw('pw_confirm', this)">visibility_off</i>
                    </div>
                </div>

                <div id="pw-strength-msg" style="font-size: 12px; font-weight: 600; color: #94a3b8; min-height: 18px;">
                </div>

                <div class="perfil-acciones">
                    <a href="{{ route('menu') }}" class="btn-primary-maquinaria btn-secondary">
                        Cancelar
                    </a>
                    <button type="submit" class="btn-primary-maquinaria" id="btnGuardarClave">
                        Actualizar
                    </button>
                </div>
            </div>
        </form>
    </div>
    </div>

    <script>
    (function() {
        // Indicador de fortaleza de contraseña
        const pwNueva = document.getElementById('pw_nueva');
        const pwConfirm = document.getElementById('pw_confirm');
        const strengthMsg = document.getElementById('pw-strength-msg');

        if (pwNueva) {
            pwNueva.addEventListener('input', function () {
                const v = this.value;
                let msg = '', color = '#94a3b8';
                if (v.length === 0) {
                    msg = '';
                } else if (v.length < 6) {
                    msg = '⚠ Muy corta (mínimo 6 caracteres)';
                    color = '#dc2626';
                } else if (v.length < 10 || !/[A-Z]/.test(v) || !/[0-9]/.test(v)) {
                    msg = '✓ Contraseña aceptable';
                    color = '#d97706';
                } else {
                    msg = '✓✓ Contraseña fuerte';
                    color = '#059669';
                }
                strengthMsg.textContent = msg;
                strengthMsg.style.color = color;
            });
        }

        // Manejo de envío por AJAX para evitar recarga de página y mostrar preloader
        const frmClave = document.getElementById('frmMiClave');
        if (frmClave) {
            frmClave.addEventListener('submit', async function (e) {
                e.preventDefault(); // Evita la recarga de la página

                const btn = document.getElementById('btnGuardarClave');
                const originalHtml = btn ? btn.innerHTML : 'Actualizar';

                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="material-icons" style="animation: spin 1s linear infinite; font-size:18px;">sync</span> Guardando...';
                }

                // Mostrar preloader global (el de fondo blanco)
                if (typeof window.showPreloader === 'function') window.showPreloader();

                try {
                    const formData = new FormData(frmClave);
                    const response = await window.apiFetch(frmClave.action, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        method: 'POST',
                        body: formData});

                    const data = await response.json();

                    if (typeof window.hidePreloader === 'function') window.hidePreloader();

                    if (response.ok && data.success) {
                        frmClave.reset();
                        const msgEl = document.getElementById('pw-strength-msg');
                        if (msgEl) { msgEl.textContent = ''; }
                        
                        if (typeof window.showToast === 'function') {
                            window.showToast(data.message, 'success');
                        } else {
                            alert(data.message);
                        }
                    } else {
                        // Errores de validación (422) u otros errores
                        let errorMsg = data.message || 'Ocurrió un error al actualizar la contraseña.';
                        if (data.errors) {
                            const firstKey = Object.keys(data.errors)[0];
                            errorMsg = data.errors[firstKey][0];
                        }
                        
                        if (typeof window.showToast === 'function') {
                            window.showToast(errorMsg, 'error');
                        } else {
                            alert(errorMsg);
                        }
                    }
                } catch (error) {
                    console.error('Error:', error);
                    if (typeof window.hidePreloader === 'function') window.hidePreloader();
                    if (typeof window.showToast === 'function') {
                        window.showToast('Error de conexión con el servidor.', 'error');
                    } else {
                        alert('Error de conexión con el servidor.');
                    }
                } finally {
                    // Restaurar el botón
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                }
            });
        }
    })();
    </script>
@endsection