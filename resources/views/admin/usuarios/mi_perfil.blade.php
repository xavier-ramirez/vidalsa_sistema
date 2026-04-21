@extends('layouts.estructura_base')

@section('title', 'Mi Usuario')

@section('content')

    <style>
        .perfil-avatar {
            width: 72px;
            height: 72px;
            background: linear-gradient(135deg, #0067b1, #1e293b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            font-weight: 800;
            flex-shrink: 0;
            box-shadow: 0 4px 15px rgba(0, 103, 177, 0.35);
        }

        .perfil-info-block {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .perfil-info-block .pi-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .perfil-info-block .pi-value {
            font-size: 15px;
            color: #1e293b;
            font-weight: 600;
        }

        .perfil-section-title {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #0067b1;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .perfil-divider {
            border: none;
            border-top: 1px solid #e2e8f0;
            margin: 16px 0;
        }

        .pw-input-wrap {
            position: relative;
        }

        .pw-input-wrap input {
            width: 100%;
            padding: 10px 44px 10px 15px;
            border: 1.5px solid #cbd5e0;
            border-radius: 10px;
            font-size: 14px;
            background: #f8fafc;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: inherit;
            color: #1e293b;
        }

        .pw-input-wrap input:focus {
            border-color: #0067b1;
            box-shadow: 0 0 0 3px rgba(0, 103, 177, 0.1);
            background: #fff;
        }

        .pw-toggle-icon {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            font-size: 20px;
            transition: color 0.2s;
            user-select: none;
        }

        .pw-toggle-icon:hover {
            color: #0067b1;
        }

        .alert-success-perfil {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            border: 1px solid #6ee7b7;
            color: #065f46;
            border-radius: 10px;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 20px;
            animation: fadeIn 0.4s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-6px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .badge-rol {
            background: rgba(0, 103, 177, 0.1);
            color: #0067b1;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            border: 1px solid rgba(0, 103, 177, 0.2);
        }
    </style>

    <div class="admin-card" style="max-width: 680px; margin: 0 auto; padding: 20px 30px;">

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

        {{-- ── Información del Usuario (solo lectura) ── --}}
        <div class="perfil-section-title">
            <i class="material-icons" style="font-size: 16px;">person</i>
            Información de la Cuenta
        </div>

        <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 12px;">
            <div class="perfil-avatar">
                {{ strtoupper(substr($user->NOMBRE_COMPLETO ?? 'U', 0, 1)) }}
            </div>
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #1e293b; line-height: 1.2;">
                    {{ $user->NOMBRE_COMPLETO ?? '—' }}
                </div>
                <div style="margin-top: 6px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <span style="font-size: 13px; color: #64748b;">{{ $user->CORREO_ELECTRONICO ?? '—' }}</span>
                    <span class="badge-rol">{{ $user->rol->NOMBRE_ROL ?? 'Sin Rol' }}</span>
                </div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 8px;">
            <div class="perfil-info-block">
                <i class="material-icons" style="color: #94a3b8; font-size: 22px;">shield</i>
                <div>
                    <div class="pi-label">Nivel de Acceso</div>
                    <div class="pi-value">
                        @if($user->NIVEL_ACCESO == 1)
                            Global
                        @elseif($user->NIVEL_ACCESO == 2)
                            Local
                        @else
                            —
                        @endif
                    </div>
                </div>
            </div>
            <div class="perfil-info-block">
                <i class="material-icons" style="color: #94a3b8; font-size: 22px;">fiber_manual_record</i>
                <div>
                    <div class="pi-label">Estado</div>
                    <div class="pi-value" style="color: {{ $user->ESTATUS === 'ACTIVO' ? '#059669' : '#dc2626' }};">
                        {{ $user->ESTATUS ?? '—' }}
                    </div>
                </div>
            </div>
        </div>

        <hr class="perfil-divider">

        {{-- ── Cambio de Contraseña ── --}}
        <div class="perfil-section-title">
            <i class="material-icons" style="font-size: 16px;">lock</i>
            Cambiar Contraseña
        </div>

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
                    <label
                        style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px;">
                        Nueva Contraseña
                    </label>
                    <div class="pw-input-wrap">
                        <input type="password" name="password" id="pw_nueva" placeholder="Mínimo 6 caracteres..."
                            autocomplete="new-password">
                        <i class="material-icons pw-toggle-icon"
                            onclick="window.togglePw('pw_nueva', this)">visibility_off</i>
                    </div>
                </div>

                <div>
                    <label
                        style="display: block; font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 4px;">
                        Confirmar Contraseña
                    </label>
                    <div class="pw-input-wrap">
                        <input type="password" name="password_confirmation" id="pw_confirm"
                            placeholder="Repite la contraseña..." autocomplete="new-password">
                        <i class="material-icons pw-toggle-icon"
                            onclick="window.togglePw('pw_confirm', this)">visibility_off</i>
                    </div>
                </div>

                <div id="pw-strength-msg" style="font-size: 12px; font-weight: 600; color: #94a3b8; min-height: 18px;">
                </div>

                <div style="display: flex; gap: 12px; justify-content: center; margin-top: 4px;">
                    <a href="{{ route('menu') }}" class="btn-primary-maquinaria btn-secondary">
                        Cancelar
                    </a>
                    <button type="submit" class="btn-primary-maquinaria" id="btnGuardarClave">
                        Actualizar Contraseña
                    </button>
                </div>
            </div>
        </form>
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
                const originalHtml = btn ? btn.innerHTML : 'Actualizar Contraseña';

                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="material-icons" style="animation: spin 1s linear infinite; font-size:18px;">sync</span> Guardando...';
                }

                // Mostrar preloader global (el de fondo blanco)
                if (typeof window.showPreloader === 'function') window.showPreloader();

                try {
                    const formData = new FormData(frmClave);
                    const response = await fetch(frmClave.action, {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    });

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