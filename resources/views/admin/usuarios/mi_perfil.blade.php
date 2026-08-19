@extends('layouts.estructura_base')

@section('title', 'Mi Usuario')

@section('content')

    <style>
        /* ══ Mi Perfil ═══════════════════════════════════════════════════════════
           Paleta y tipografía heredadas del sistema (azul #0067b1 + Nunito). Los
           neutros llevan una pizca de azul en vez de gris puro, para que la pantalla
           se lea como parte de la marca y no como un formulario genérico.
           Todo vive dentro de UNA .admin-card angosta: cabecera (avatar + nombre +
           estado), una fila de etiquetas con rol y niveles, y debajo el formulario.
           Antes eran cinco bloques apilados —dos de ellos tarjetas con título propio
           para mostrar dos palabras— que estiraban la pantalla sin aportar nada. */
        /* La tarjeta se centra también EN VERTICAL dentro del área de contenido. El 190px
           descuenta el encabezado del layout; min-height (no height) para que en pantallas
           bajas o con el teclado abierto la tarjeta empuje y siga siendo scrollable en vez
           de quedar recortada. */
        .perfil-centro {
            min-height: calc(100vh - 190px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .perfil-wrap {
            --pf-azul: #0067b1;
            --pf-tinta: #0f172a;
            --pf-gris: #5b6b7f;
            --pf-borde: #e3e9f0;
            /* Angosta a propósito: el contenido son tres datos de lectura y dos campos.
               Bajó de 720 a 520 y de 520 a 420 por la misma razón — las líneas quedaban
               perdidas en un ancho que no usaban. A 420, descontando los 24 de padding a
               cada lado, quedan 372 px útiles para los dos campos. La pareja de botones no
               corre riesgo: .perfil-acciones lleva flex-wrap, así que si no entraran en una
               fila bajan una debajo de otra en vez de desbordar. */
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            padding: 24px;
        }

        /* ── Identidad: avatar + nombre, correo, estado y etiquetas ── */
        /* Cabecera en HORIZONTAL: el avatar a la izquierda y toda la identidad a su
           derecha. Antes eran cinco bloques centrados uno debajo del otro (avatar, nombre,
           correo, estado y la fila de etiquetas), que para seis datos cortos gastaba media
           pantalla antes de llegar al formulario, que es a lo que se entra. */
        /* En COLUMNA y centrada: el avatar arriba y debajo nombre, correo y cargo.
           Antes iba en fila con el texto a la izquierda, pero al quitar el estado y las
           etiquetas de nivel quedaba un bloque corto descolgado al lado del avatar. */
        .perfil-hero {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 10px;
        }
        .perfil-avatar {
            width: 56px;
            height: 56px;
            flex: 0 0 auto;
            border-radius: 50%;
            display: grid;
            place-items: center;
            color: #fff;
            background: linear-gradient(135deg, #00355c, var(--pf-azul));
        }
        /* Columna de identidad: nombre + estado en la primera línea, correo en la segunda
           y las etiquetas debajo. min-width:0 para que un correo largo recorte en vez de
           empujar el avatar fuera de la tarjeta. */
        /* align-items:center para que los chips también queden centrados bajo el correo. */
        .perfil-ident { min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 2px; }
        .perfil-avatar .material-icons { font-size: 30px; }
        .perfil-nombre {
            font-size: 18px;
            font-weight: 800;
            line-height: 1.2;
            color: var(--pf-tinta);
        }
        .perfil-correo {
            font-size: 13px;
            font-weight: 600;
            color: var(--pf-gris);
            word-break: break-word;
        }


        /* ── Etiquetas de rol y niveles: tres datos cortos en una sola fila ── */
        .perfil-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }
        .pf-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 7px;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--pf-gris);
            background: #f6f9fc;
            border: 1px solid var(--pf-borde);
        }
        .pf-chip-rol {
            color: var(--pf-azul);
            background: rgba(0, 103, 177, .07);
            border-color: rgba(0, 103, 177, .2);
            text-transform: uppercase;
            letter-spacing: .5px;
            font-size: 11px;
        }

        .perfil-sep {
            border: none;
            border-top: 1px solid var(--pf-borde);
            margin: 14px 0 14px;
        }

        .perfil-section-title {
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--pf-tinta);
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .perfil-section-title .material-icons { font-size: 16px; color: var(--pf-azul); }
        .perfil-section-sub {
            font-size: 13px;
            color: var(--pf-gris);
            margin-bottom: 16px;
        }
        .pw-label {
            display: block;
            font-size: 10.5px;
            font-weight: 800;
            color: var(--pf-gris);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 5px;
        }

        /* En pantallas bajas el centrado vertical estorba: deja de centrar y la tarjeta
           arranca arriba, para que no haya que hacer scroll dentro de un hueco vacío. */
        @media (max-height: 700px) {
            .perfil-centro { min-height: 0; align-items: flex-start; }
        }
        /* Teléfono: la cabecera SIGUE horizontal (apilarla devolvería el alto que este
           cambio vino a recortar), pero el avatar y las separaciones ceden unos píxeles
           para que al nombre y al correo les quede ancho útil. */
        @media (max-width: 420px) {
            .perfil-wrap { padding: 18px; }
            .perfil-hero { gap: 11px; }
            .perfil-avatar { width: 46px; height: 46px; }
            .perfil-avatar .material-icons { font-size: 25px; }
            .perfil-nombre { font-size: 16.5px; }
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
            background: #fff;
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

        /* Botonera de "Cambiar contraseña": los botones quedan EXACTAMENTE como los del
           resto de la app (tamaño, radio y relleno los pone .btn-primary-maquinaria).
           Aquí solo se coloca la fila; nada de altos ni anchos propios, que era lo que
           hacía que esta pantalla se viera distinta a los demás módulos. */
        .perfil-acciones {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 8px;
            flex-wrap: wrap;
        }
    </style>

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
                            autocomplete="new-password">
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
                            placeholder="Repite la contraseña..." autocomplete="new-password">
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