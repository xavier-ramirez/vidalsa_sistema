<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Maquinaria - Inicio de Sesión</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Pagina SOLO clara: sin esto, con el telefono en modo nocturno Chrome invierte los
         colores por su cuenta y pinta en oscuro los campos nativos --incluido el de
         contraseña--. Esta vista NO extiende estructura_base, asi que lleva su propia
         declaracion. --}}
    <meta name="color-scheme" content="light">
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
        <!-- Figuras geométricas (nuevo diseño), compartidas con el menú vía background_svg. -->
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


            {{-- Figuras geométricas ARRIBA de la tarjeta, ancladas a ella (bottom:100%).
                 Solo se muestran en móvil (en escritorio las de arriba van en background_svg). --}}
            <div class="login-figs-arriba" aria-hidden="true">
                <svg viewBox="0 0 400 80" preserveAspectRatio="xMidYMax meet" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="55" cy="45" r="20" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.40"/>
                    <circle cx="55" cy="45" r="11" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.50"/>
                    <circle cx="120" cy="22" r="6" fill="#00004d" opacity="0.40"/>
                    <rect x="170" y="30" width="28" height="28" rx="7" fill="none" stroke="#00004d" stroke-width="3" opacity="0.34" transform="rotate(15 184 44)"/>
                    <path d="M255 32 h18 M264 23 v18" stroke="#0067b1" stroke-width="3" opacity="0.45"/>
                    <polygon points="315,68 349,68 332,40" fill="#0067b1" opacity="0.30"/>
                    <circle cx="365" cy="30" r="6" fill="#00004d" opacity="0.40"/>
                </svg>
            </div>

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
                    <div id="offlineLoginMsg" style="display:none;margin-top:20px;color:#b45309;font-size:12.5px;text-align:center;font-weight:600;"></div>
                </form>
            </div>
            {{-- Figuras geométricas ABAJO de la tarjeta — ancladas a la propia tarjeta
                 (position:absolute; top:100%) para que SIEMPRE queden justo debajo, sin que la
                 tarjeta las tape, sin importar el alto de la pantalla (la tarjeta va centrada). --}}
            <div class="login-figs-abajo" aria-hidden="true">
                <svg viewBox="0 0 400 120" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="48" cy="58" r="22" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.40"/>
                    <circle cx="48" cy="58" r="12" fill="none" stroke="#0067b1" stroke-width="3" opacity="0.50"/>
                    <circle cx="112" cy="30" r="6" fill="#00004d" opacity="0.40"/>
                    <rect x="160" y="40" width="32" height="32" rx="8" fill="none" stroke="#00004d" stroke-width="3" opacity="0.34" transform="rotate(15 176 56)"/>
                    <path d="M245 42 h20 M255 32 v20" stroke="#0067b1" stroke-width="3" opacity="0.45"/>
                    <polygon points="300,92 336,92 318,60" fill="#0067b1" opacity="0.30"/>
                    <polygon points="360,50 380,61 380,83 360,94 340,83 340,61" fill="none" stroke="#00004d" stroke-width="3" opacity="0.30"/>
                    <circle cx="210" cy="95" r="6" fill="#00004d" opacity="0.40"/>
                </svg>
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
    // Guard anti-preloader-pegado: independiente de DOMContentLoaded, corre apenas
    // el navegador ejecuta este script (muy al inicio de la carga). Si a los 4s el
    // preloader sigue de pie (CSS lento desde el cache del Service Worker, primer
    // arranque de la PWA sin red, etc.) lo oculta a la fuerza — nunca debe quedar
    // el usuario viendo solo el logo sin poder llegar al formulario.
    // EXCEPTO con un login EN CURSO (_loginEnCurso): ahí el spinner debe seguir
    // hasta el redirect o el error — sin esta condición, un login de >4s quitaba
    // el spinner, se veía el formulario un instante y recién después el menú.
    window._loginEnCurso = false;
    setTimeout(function () {
        if (window._loginEnCurso) return;
        var pl = document.getElementById('loginPreloader');
        if (pl) pl.classList.add('fade-out');
    }, 4000);

    document.addEventListener('DOMContentLoaded', () => {
        var preloader = document.getElementById('loginPreloader');
        if (preloader) preloader.classList.add('fade-out');

        // Estamos en el login: limpia cualquier bandera "login reciente" que haya
        // quedado de un intento fallido/cancelado, para que no suprima el spinner
        // general en una página autenticada posterior.
        try { sessionStorage.removeItem('vidalsaJustLoggedIn'); } catch (e) {}

        // NO recargamos la página periódicamente para "refrescar el CSRF": el submit YA hace un
        // handshake /refresh-csrf + reintento automático ante un 419 (ver más abajo), que se
        // autocura si el token de invitado caducó por sesión corta. El reload periódico era
        // redundante y, con SESSION_LIFETIME bajo (10 min), recaía cada ~9 min borrando lo
        // tecleado y reiniciando la precarga WebAuthn. Eliminado.

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

    // Muestra un mensaje en #offlineLoginMsg, oculta el spinner y rehabilita el
    // botón. Lo usan el aviso "sin conexión", los errores de credenciales y el
    // login biométrico (script de abajo) — por eso viven a nivel de script, no
    // dentro del bloque del formulario.
    function mostrarMsgLogin(texto) {
        window._loginEnCurso = false; // el intento terminó: el guard de 4s vuelve a aplicar
        const pl = document.getElementById('loginPreloader');
        if (pl) { pl.classList.add('fade-out'); pl.style.display = 'none'; }
        const msg = document.getElementById('offlineLoginMsg');
        if (msg) { msg.textContent = texto; msg.style.display = 'block'; }
        const btnOn = document.getElementById('btnOnlineLogin');
        if (btnOn) btnOn.disabled = false;
    }
    // Aviso "sin conexión": evita que el navegador muestre su página de error (la
    // "boba fea") cuando el login no puede contactar al servidor. Si hay credenciales
    // offline guardadas, sugiere "Entrar sin conexión".
    function avisarSinConexion() {
        const btnOff = document.getElementById('btnOfflineLogin');
        const hayOffline = btnOff && btnOff.style.display !== 'none';
        mostrarMsgLogin(hayOffline
            ? 'Sin conexión. Usa "Entrar sin conexión" o revisa tu red.'
            : 'Sin conexión. Revisa tu red.');
    }

    // ── Precalentar sesión + token CSRF ANTES de que el usuario pulse "Entrar" ──
    // El HTML del login se sirve stale-while-revalidate desde la caché del Service
    // Worker, así que su @csrf puede venir de una sesión de invitado ya caducada
    // (SESSION_LIFETIME corto + pestaña abierta un rato). Con ese token viejo, el
    // primer POST daba 419 y, aunque el submit reintenta, a veces caían DOS 419
    // seguidos → window.location.reload(): el usuario veía el spinner irse y tenía
    // que pulsar el botón otra vez para entrar. Pidiendo el token fresco al cargar
    // (y al volver a la pestaña) la sesión queda viva y el primer clic ya entra.
    // Reutiliza el mismo handshake /refresh-csrf del submit (no-store, excluido del SW).
    function precalentarCsrf() {
        if (!navigator.onLine) return; // sin red: el submit ya avisa "sin conexión"
        fetch('/refresh-csrf', { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (token) {
                token = (token || '').trim();
                // Validar que sea un token, no HTML de una página de error.
                if (!token || token.length >= 100 || token.indexOf('<') !== -1) return;
                var form = document.getElementById('loginForm');
                var input = form && form.querySelector('input[name="_token"]');
                if (input) input.value = token;
            })
            .catch(function () {});
    }
    precalentarCsrf();
    // Al volver de otra pestaña o del bfcache el token pudo caducar: refréscalo para
    // que el primer clic siga entrando a la primera.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') precalentarCsrf();
    });
    window.addEventListener('pageshow', function (e) { if (e.persisted) precalentarCsrf(); });

    const loginForm = document.querySelector('form');
    if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Stop native submission immediately

            // Sin red según el navegador → no intentes navegar (evita la página de
            // error fea); avisa y quédate en el login.
            if (!navigator.onLine) { avisarSinConexion(); return; }

            // Limpia un mensaje previo y muestra el spinner. La bandera evita que el
            // guard de 4s lo oculte a mitad del intento (ver arriba); se limpia en
            // mostrarMsgLogin (todos los caminos de fallo pasan por ahí).
            window._loginEnCurso = true;
            const msgPrev = document.getElementById('offlineLoginMsg');
            if (msgPrev) msgPrev.style.display = 'none';
            const preloader = document.getElementById('loginPreloader');
            if (preloader) {
                preloader.classList.remove('fade-out');
                preloader.style.display = 'flex';
            }

            // Guarda el verificador OFFLINE (hash de correo+clave, nunca la clave en texto)
            // para poder entrar sin internet luego. Se "confirma" al llegar al menú.
            if (window.OfflineAuth) {
                window.OfflineAuth.guardarPendiente(
                    (document.getElementById('login_identifier') || {}).value,
                    (document.getElementById('password') || {}).value
                );
            }

            // Handshake + POST, con UN reintento automático si el primer intento da 419.
            // Antes ese 419 hacía reload() silencioso: el usuario veía "presioné Entrar
            // y no pasó nada" y tenía que darle Entrar otra vez. Ahora el segundo intento
            // (con la cookie de sesión que dejó el POST fallido + token fresco) sale solo.
            function intentarLogin(esReintento) {
                // 1. Handshake: pedir un token CSRF fresco.
                //    cache:'no-store' es OBLIGATORIO: el HTML del login se sirve desde la
                //    caché del Service Worker (token viejo); forzamos red para el token actual.
                return fetch('/refresh-csrf', { cache: 'no-store', credentials: 'same-origin' })
                    .then(response => {
                        if (!response.ok) throw new Error('HTTP ' + response.status);
                        return response.text();
                    })
                    .then(newToken => {
                        // 2. Inyectar el token nuevo (validando que sea un token, no HTML).
                        newToken = (newToken || '').trim();
                        const tokenInput = loginForm.querySelector('input[name="_token"]');
                        if (tokenInput && newToken && newToken.length < 100 && newToken.indexOf('<') === -1) {
                            tokenInput.value = newToken;
                        }
                        // 3. Enviar por FETCH (no navegación nativa): así un bajón de red en el
                        //    POST se atrapa en el .catch de abajo y NO dispara la página de
                        //    error del navegador — te quedas en el login para reintentar.
                        return fetch(loginForm.action, {
                            method: 'POST',
                            body: new FormData(loginForm),
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            credentials: 'same-origin',
                        }).then(function (resp) {
                            if (resp.status === 419) {
                                // CSRF expiró pese al handshake (sesión recién recreada).
                                if (!esReintento) return intentarLogin(true);
                                window.location.reload(); // segundo 419 seguido: último recurso
                                return;
                            }
                            return resp.json().then(function (data) {
                                if (resp.ok && data && data.success && data.redirect) {
                                    window.marcarLoginReciente();          // solo en éxito confirmado
                                    window.location.href = data.redirect;  // → menú
                                    return;
                                }
                                // Credenciales/negocio (422): mostrar el mensaje, quedarse en el login.
                                mostrarMsgLogin((data && data.message) || 'Usuario o clave incorrecta.');
                            }).catch(function () {
                                // Respuesta no-JSON inesperada: recargar para mostrar el server-render.
                                window.location.reload();
                            });
                        });
                    });
            }

            intentarLogin(false)
                .catch(error => {
                    console.error('Login failed:', error);
                    // Bajón de red / servidor inalcanzable en el handshake O en el POST: NO
                    // navegamos (eso dispara la página de error del navegador). Avisamos y
                    // nos quedamos en el login para reintentar o entrar sin conexión.
                    avisarSinConexion();
                });
        });
    }
</script>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(function(){});
}
</script>
{{-- Overlay "Actualizando…": si hay un SW nuevo bajándose (tras un despliegue), avisa y
     recarga al terminar. Solo en ese caso; en login normal no aparece. --}}
<script src="{{ asset('js/pwa-update-overlay.js') }}?v={{ @filemtime(public_path('js/pwa-update-overlay.js')) }}" defer></script>
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
        if (ok) {
            btnBio.style.display = 'flex';
            // Arranca el ciclo de precarga del challenge (prefetch + refresh
            // periódico + eventos online/visible): al tocar el botón el lector
            // de huella se abre de inmediato, sin esperar la red.
            VidalsaWebAuthn.iniciarPrecarga();
        }
    });

    var bioLabel = document.getElementById('bioLabel');

    btnBio.addEventListener('click', function() {
        window._loginEnCurso = true; // el guard de 4s no debe quitar el spinner a mitad del intento
        btnBio.style.pointerEvents = 'none';
        btnBio.style.opacity = '0.6';
        if (bioLabel) bioLabel.textContent = 'Verificando...';

        var preloader = document.getElementById('loginPreloader');
        if (preloader) { preloader.classList.remove('fade-out'); preloader.style.display = 'flex'; }

        // El re-primado del challenge tras cada intento lo maneja el propio
        // módulo VidalsaWebAuthn (dueño único del precache).
        VidalsaWebAuthn.autenticar()
            .then(function(data) {
                if (data && data.success && data.redirect) {
                    window.marcarLoginReciente(); // solo en éxito confirmado
                    window.location.href = data.redirect;
                    return;
                }
                window._loginEnCurso = false;
                if (preloader) preloader.classList.add('fade-out');
                btnBio.style.pointerEvents = '';
                btnBio.style.opacity = '';
                if (bioLabel) bioLabel.textContent = 'Identificación biométrica';
            })
            .catch(function(err) {
                window._loginEnCurso = false;
                if (preloader) preloader.classList.add('fade-out');
                btnBio.style.pointerEvents = '';
                btnBio.style.opacity = '';
                if (bioLabel) bioLabel.textContent = 'Identificación biométrica';
                if (err.message === 'USER_CANCELLED') return;
                if (err.message === 'NO_CREDENTIALS') return;
                if (err.message === 'SIN_CONEXION') { avisarSinConexion(); return; }
                var msgDiv = document.getElementById('offlineLoginMsg');
                if (msgDiv) { msgDiv.style.display = 'block'; msgDiv.textContent = err.message || 'Error de autenticación biométrica'; }
            });
    });
});
</script>
</html>

