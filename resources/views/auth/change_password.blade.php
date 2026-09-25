<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Solo modo claro. El porqué, en :root de estilos_globales.css. Esta vista tiene su
         PROPIO <head> (no extiende estructura_base), por eso lo declara aparte. --}}
    <meta name="color-scheme" content="light">
    <title>Cambio de Contraseña</title>
    <!-- Fonts -->
    <link href="{{ asset('css/fonts.css') }}?v={{ @filemtime(public_path('css/fonts.css')) }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/vistas/auth_change_password.css') }}?v={{ @filemtime(public_path('css/vistas/auth_change_password.css')) }}">
</head>
<body>
    <!-- Preloader / Splash Screen -->
    <div id="loginPreloader" class="preloader fade-out" style="display: none;">
        <div class="preloader-content">
            <img class="preloader-logo" src="{{ asset('images/maquinaria/logo.webp') }}" alt="Logo Vidalsa">
            <div class="spinner-circle"></div>
        </div>
    </div>

    <div class="login-card">
        <div style="text-align: center; margin-bottom: 30px;">
            <img src="{{ asset('images/maquinaria/logo.webp') }}" alt="Logo Vidalsa" style="height: 60px; margin-bottom: 20px;">
            <br>
            <h2 class="custom-title" style="color: #111827; font-size: 24px; margin: 0; font-weight: 700;">Cambio de Contraseña</h2>
            <p style="color: #6b7280; font-size: 14px; margin-top: 8px;">
                Por motivos de seguridad, su cuenta requiere <br> que actualice su contraseña.
            </p>
        </div>

        @if ($errors->any())
            <div style="background-color: #fee2e2; border-left: 4px solid #ef4444; color: #b91c1c; padding: 12px; margin-bottom: 20px; border-radius: 4px; font-size: 14px;">
                <ul style="margin: 0; padding-left: 20px;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="updatePasswordForm" method="POST" action="{{ route('password.update') }}">
            @csrf

            <div class="form-group">
                <label for="password" class="custom-label">Nueva Contraseña</label>
                <div class="input-wrapper">
                    <i class="material-icons icon-left">lock</i>
                    <input type="password" id="password" name="password" required class="custom-input" placeholder="Mínimo 6 caracteres">
                    <button type="button" class="password-toggle" onclick="togglePassword('password', 'icon-pass')" title="Mostrar contraseña">
                        <i class="material-icons" id="icon-pass">visibility</i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="password_confirmation" class="custom-label">Confirmar Contraseña</label>
                <div class="input-wrapper">
                    <i class="material-icons icon-left">lock_clock</i>
                    <input type="password" id="password_confirmation" name="password_confirmation" required class="custom-input" placeholder="Repita la nueva contraseña">
                    <button type="button" class="password-toggle" onclick="togglePassword('password_confirmation', 'icon-confirm')" title="Mostrar contraseña">
                        <i class="material-icons" id="icon-confirm">visibility</i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary">
                Actualizar Contraseña
            </button>
        </form>

        <div style="margin-top: 25px; text-align: center; border-top: 1px solid #e5e7eb; padding-top: 20px;">
            <p style="font-size: 13px; color: #6b7280; margin-bottom: 10px;">¿Necesita salir?</p>
            <form id="logoutForm" action="{{ route('logout') }}" method="POST">
                @csrf
                <button type="submit" class="btn-link">
                    Cerrar Sesión
                </button>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const updateForm = document.getElementById('updatePasswordForm');
            if (updateForm) {
                updateForm.addEventListener('submit', function() {
                    // El desbloqueo SIN INTERNET guarda un hash de la clave ANTERIOR: al
                    // cambiarla se borra, o este equipo se quedaría pidiendo la vieja para
                    // abrir la copia cacheada (y quien conociera la vieja seguiría
                    // entrando). Se recrea solo en el próximo inicio de sesión con
                    // internet. Se borra al enviar y no al confirmar porque desde aquí no
                    // se sabe el resultado: fallar cerrado es lo correcto.
                    if (window.OfflineAuth) window.OfflineAuth.olvidar();

                    const preloader = document.getElementById('loginPreloader');
                    if (preloader) {
                        preloader.style.display = 'flex';
                        preloader.offsetHeight; // Force reflow
                        preloader.classList.remove('fade-out');
                    }
                });
            }

            const logoutForm = document.getElementById('logoutForm');
            if (logoutForm) {
                logoutForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // Prevent default early to stabilize
                    
                    const preloader = document.getElementById('loginPreloader');
                    if (preloader) {
                        preloader.style.display = 'flex';
                        preloader.offsetHeight; // Force reflow
                        preloader.classList.remove('fade-out');
                    }

                    // Handshake: token fresco antes de enviar. cache:'no-store' igual que
                    // el login: nunca inyectar un token cacheado/caducado (evita 419).
                    fetch('/refresh-csrf', { cache: 'no-store', credentials: 'same-origin' })
                        .then(response => {
                            if (!response.ok) throw new Error('HTTP ' + response.status);
                            return response.text();
                        })
                        .then(newToken => {
                            newToken = (newToken || '').trim();
                            const tokenInput = logoutForm.querySelector('input[name="_token"]');
                            if (tokenInput && newToken && newToken.length < 100 && newToken.indexOf('<') === -1) {
                                tokenInput.value = newToken;
                            }
                            HTMLFormElement.prototype.submit.call(logoutForm);
                        })
                        .catch(error => {
                            console.error('Handshake failed:', error);
                            HTMLFormElement.prototype.submit.call(logoutForm); // Fallback
                        });
                });
            }
        });

        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            
            if (input.type === "password") {
                input.type = "text";
                icon.textContent = "visibility_off";
            } else {
                input.type = "password";
                icon.textContent = "visibility";
            }
        }
    </script>
    {{-- Solo por la API window.OfflineAuth (esta vista no tiene formulario de login, así
         que el script sale enseguida): al cambiar la clave hay que borrar el verificador
         de acceso sin conexión, que guarda un hash de la clave ANTERIOR. --}}
    <script src="{{ asset('js/offline/offline-auth.js') }}?v={{ @filemtime(public_path('js/offline/offline-auth.js')) }}" defer></script>
</body>
</html>
