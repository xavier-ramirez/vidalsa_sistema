<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Maquinaria - Inicio de Sesión</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- Fonts (Local) -->
    <link href="{{ asset('css/fonts.css') }}?v={{ @filemtime(public_path('css/fonts.css')) }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/maquinaria/inicio_sesion.css') }}?v={{ @filemtime(public_path('css/maquinaria/inicio_sesion.css')) }}">
    <style>
        /* Force hide browser default password reveal button - Inline Critical CSS */
        input[type='password']::-ms-reveal,
        input[type='password']::-ms-clear {
            display: none !important;
        }
        input[type='password']::-webkit-contacts-auto-fill-button,
        input[type='password']::-webkit-credentials-auto-fill-button {
            visibility: hidden;
            display: none !important;
            pointer-events: none;
            height: 0;
            width: 0;
            margin: 0;
        }
    </style>
</head>
<body>
    <!-- Preloader / Splash Screen -->
    <div id="loginPreloader" class="preloader">
        <div class="preloader-content">
            <img class="preloader-logo" src="{{ asset('images/maquinaria/logo.webp') }}" alt="Logo Vidalsa">
            <div class="spinner-circle"></div>
        </div>
    </div>

    <div class="login-container">
        <!-- SVG Background: Parcial reutilizado -->
        @include('partials.background_svg')

        <!-- Título independiente -->
        <div class="page-title-container">
            <h1 class="page-title">
                <span class="page-title-line1">Sistema de Gestión de</span>
                <span class="page-title-line2">Equipos Operacionales</span>
            </h1>
            
            <div class="features-container">
                <div class="feature-card">
                    <i class="material-icons feature-card-icon">description</i>
                    <span class="feature-text">Acceso a Documentación</span>
                </div>
                <div class="feature-card">
                    <i class="material-icons feature-card-icon">location_on</i>
                    <span class="feature-text">Estado y Ubicación</span>
                </div>
                <div class="feature-card">
                    <i class="material-icons feature-card-icon">engineering</i>
                    <span class="feature-text">Control de Mantenimiento</span>
                </div>
            </div>
        </div>

        <!-- Maquinaria en la parte inferior derecha -->
        <div class="machinery-fixed-bottom">
            <div class="machinery-wrapper">
                <img src="{{ asset('images/maquinaria_login_new.webp') }}" alt="Maquinaria Vidalsa" loading="lazy">
            </div>
        </div>





        <div class="login-container-float-center">


            <div class="login-container-logo">
                <img class="logo-login" src="{{ asset('images/maquinaria/logo.webp') }}" alt="Vidalsa Logo">
            </div>

            <div class="login-container-form">
                <form id="loginForm" action="{{ route('login.post') }}" method="POST">

                    @csrf
                    <div class="form-group">
                        <div class="custom-form-field">
                            <input type="text" name="login_identifier" id="login_identifier" class="custom-input @error('login_error') input-error @enderror" placeholder=" " required autocomplete="off" value="{{ old('login_identifier') }}">
                            <label for="login_identifier" class="custom-label">Correo corporativo</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="custom-form-field password-field">
                            <input type="password" name="password" id="password" class="custom-input" placeholder=" " required autocomplete="off">
                            <button type="button" class="password-toggle" aria-label="Mostrar contraseña" onclick="togglePassword()">
                                <span id="passwordToggleIcon">
                                    <svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                </span>
                            </button>
                            <label for="password" class="custom-label">Contraseña</label>
                        </div>
                    </div>
                    @error('login_error')
                        <div class="login-alert">
                            <i class="material-icons">error_outline</i>
                            <span>{{ $message }}</span>
                        </div>
                    @enderror
                    @if(session('info'))
                        <div class="login-alert">
                            <i class="material-icons">error_outline</i>
                            <span>{{ session('info') }}</span>
                        </div>
                    @endif
                    <div class="button-login-container">
                        <button type="submit" id="btnOnlineLogin" class="btn-maquinaria-primary">Iniciar sesión</button>
                    </div>
                    <div id="btnBiometricLogin" style="display:none;flex-direction:column;align-items:center;gap:4px;margin-top:16px;cursor:pointer;">
                        <i class="material-icons" style="font-size:48px;color:#00004d;transition:transform 0.15s ease;">fingerprint</i>
                        <span id="bioLabel" style="font-size:11px;font-weight:600;color:#6e7781;letter-spacing:0.2px;">Identificación biométrica</span>
                    </div>
                    {{-- Botón de acceso OFFLINE: solo aparece sin internet y si ya iniciaste
                         sesión con internet al menos una vez en este equipo. --}}
                    <button type="button" id="btnOfflineLogin"
                            style="display:none;align-items:center;justify-content:center;gap:8px;width:100%;margin-top:12px;background:#fff;color:#b45309;border:1.5px solid #fdba74;border-radius:8px;padding:11px;font-weight:800;font-size:14px;cursor:pointer;">
                        Entrar sin conexión
                    </button>
                    <div id="offlineLoginMsg" style="display:none;margin-top:8px;color:#b45309;font-size:12.5px;text-align:center;font-weight:600;"></div>
                </form>
            </div>
        </div>
    </div>
</body>
<script>
    function togglePassword() {
        const input = document.getElementById('password');
        const iconSpan = document.getElementById('passwordToggleIcon');
        if (!input || !iconSpan) return;
        if (input.type === 'password') {
            input.type = 'text';
            iconSpan.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/></svg>';
        } else {
            input.type = 'password';
            iconSpan.innerHTML = '<svg viewBox="0 0 24 24"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>';
        }
        if (input.value.trim()) input.classList.add('has-value');
    }
    document.addEventListener('DOMContentLoaded', () => {
        var preloader = document.getElementById('loginPreloader');
        if (preloader) preloader.classList.add('fade-out');

        // Estamos en el login: limpia cualquier bandera "login reciente" que haya
        // quedado de un intento fallido/cancelado, para que no suprima el spinner
        // general en una página autenticada posterior.
        try { sessionStorage.removeItem('vidalsaJustLoggedIn'); } catch (e) {}

        setInterval(() => { window.location.reload(); }, 1000 * 60 * 20);

        const loginFormElement = document.getElementById('loginForm');
        if (loginFormElement) loginFormElement.reset();

        const inputs = document.querySelectorAll('.custom-input');
        const checkValue = (input) => {
            if (input.value.trim() !== "") input.classList.add('has-value');
            else input.classList.remove('has-value');
        };
        inputs.forEach(input => {
            checkValue(input);
            input.addEventListener('input', () => checkValue(input));
            input.addEventListener('change', () => checkValue(input));
            input.addEventListener('blur', () => checkValue(input));
            input.addEventListener('focus', () => checkValue(input));
        });
        setTimeout(() => inputs.forEach(input => checkValue(input)), 300);
        setTimeout(() => inputs.forEach(input => checkValue(input)), 1000);
    });

    // Punto ÚNICO para la bandera que consume estructura_base en la primera carga
    // tras el login (para NO mostrar el spinner general: el logo ya cubrió la
    // transición). Centraliza la clave 'vidalsaJustLoggedIn'.
    window.marcarLoginReciente = function () {
        try { sessionStorage.setItem('vidalsaJustLoggedIn', '1'); } catch (e) {}
    };

    const loginForm = document.querySelector('form');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Stop native submission immediately

            // Show Preloader
            const preloader = document.getElementById('loginPreloader');
            if (preloader) {
                preloader.classList.remove('fade-out');
                preloader.style.display = 'flex';
            }

            // Optimista: el POST navega sin saber el resultado aquí. Si el login
            // falla, el re-render del login limpia la bandera en su DOMContentLoaded.
            window.marcarLoginReciente();

            // Guarda el verificador OFFLINE (hash de correo+clave, nunca la clave en texto)
            // para poder entrar sin internet luego. Se "confirma" al llegar al menú. La
            // función es asíncrona pero el handshake de abajo da tiempo de sobra a que termine.
            if (window.OfflineAuth) {
                window.OfflineAuth.guardarPendiente(
                    (document.getElementById('login_identifier') || {}).value,
                    (document.getElementById('password') || {}).value
                );
            }

            // 1. Handshake: pedir un token CSRF fresco.
            //    cache:'no-store' es OBLIGATORIO: el HTML del login se sirve desde la
            //    caché del Service Worker (token viejo), y si el navegador también
            //    cacheara /refresh-csrf devolvería un token caducado -> 419 al primer
            //    intento. Forzamos red para traer SIEMPRE el token de la sesión actual.
            fetch('/refresh-csrf', { cache: 'no-store', credentials: 'same-origin' })
                .then(response => {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.text();
                })
                .then(newToken => {
                    // 2. Inyectar el token nuevo (validando que sea un token, no HTML
                    //    de una página de error: evita romper el _token con basura).
                    newToken = (newToken || '').trim();
                    const tokenInput = loginForm.querySelector('input[name="_token"]');
                    if (tokenInput && newToken && newToken.length < 100 && newToken.indexOf('<') === -1) {
                        tokenInput.value = newToken;
                    }
                    // 3. Enviar
                    loginForm.submit();
                })
                .catch(error => {
                    console.error('Handshake failed:', error);
                    // Fallback: enviar igual con el token del HTML, que el servidor decida.
                    loginForm.submit();
                });
        });
    }
</script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(function(){});
}
</script>
{{-- Login OFFLINE: botón "Entrar sin conexión" + verificación por hash local. --}}
<script src="{{ asset('js/offline/offline-auth.js') }}?v={{ @filemtime(public_path('js/offline/offline-auth.js')) }}" defer></script>
{{-- WebAuthn: login biométrico (huella/rostro) sin contraseña. --}}
<script src="{{ asset('js/webauthn.js') }}?v={{ @filemtime(public_path('js/webauthn.js')) }}" defer></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var btnBio = document.getElementById('btnBiometricLogin');
    if (!btnBio) return;
    if (typeof VidalsaWebAuthn === 'undefined' || !VidalsaWebAuthn.soportado() || !VidalsaWebAuthn.tieneCredenciales()) return;

    VidalsaWebAuthn.plataformaDisponible().then(function(ok) {
        if (ok) btnBio.style.display = 'flex';
    });

    var bioLabel = document.getElementById('bioLabel');

    btnBio.addEventListener('click', function() {
        btnBio.style.pointerEvents = 'none';
        btnBio.style.opacity = '0.6';
        if (bioLabel) bioLabel.textContent = 'Verificando...';

        var preloader = document.getElementById('loginPreloader');
        if (preloader) { preloader.classList.remove('fade-out'); preloader.style.display = 'flex'; }

        VidalsaWebAuthn.autenticar()
            .then(function(data) {
                if (data && data.success && data.redirect) {
                    window.marcarLoginReciente(); // solo en éxito confirmado
                    window.location.href = data.redirect;
                    return;
                }
                if (preloader) preloader.classList.add('fade-out');
                btnBio.style.pointerEvents = '';
                btnBio.style.opacity = '';
                if (bioLabel) bioLabel.textContent = 'Identificación biométrica';
            })
            .catch(function(err) {
                if (preloader) preloader.classList.add('fade-out');
                btnBio.style.pointerEvents = '';
                btnBio.style.opacity = '';
                if (bioLabel) bioLabel.textContent = 'Identificación biométrica';
                if (err.message === 'USER_CANCELLED') return;
                if (err.message === 'NO_CREDENTIALS') return;
                var msgDiv = document.getElementById('offlineLoginMsg');
                if (msgDiv) { msgDiv.style.display = 'block'; msgDiv.textContent = err.message || 'Error de autenticación biométrica'; }
            });
    });
});
</script>
</html>

