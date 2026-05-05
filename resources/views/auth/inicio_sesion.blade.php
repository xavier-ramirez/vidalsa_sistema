<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestión de Maquinaria - Inicio de Sesión</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <!-- Fonts (Local) -->
    <link href="{{ asset('css/fonts.css') }}" rel="stylesheet">
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
                <img src="{{ asset('images/maquinaria_login_new.webp') }}" alt="Maquinaria Vidalsa">
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
                        <button type="submit" class="btn-maquinaria-primary">Iniciar sesión</button>
                    </div>

                    {{-- Botón "Iniciar sesión sin internet" — solo móvil con credenciales locales --}}
                    <div id="offlineLoginWrap" style="display:none; margin-top:14px; text-align:center;">
                        <button type="button" id="btnOfflineLogin"
                            style="background: white; color: #0067b1; border: 2px solid #0067b1; padding: 10px 20px;
                                   border-radius: 12px; font-weight: 700; font-size: 14px; cursor: pointer; width: 100%;
                                   font-family: inherit;">
                            <i class="material-icons" style="font-size:18px; vertical-align:middle;">cloud_off</i>
                            <span style="vertical-align:middle;">Iniciar sesión sin Internet</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal informativo offline --}}
    <div id="offlineLoginInfo" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.7); z-index:9000; align-items:center; justify-content:center; padding:20px;">
        <div style="background:white; max-width:420px; width:100%; border-radius:16px; padding:24px; box-shadow:0 20px 50px rgba(0,0,0,0.3);">
            <div style="text-align:center; margin-bottom:16px;">
                <i class="material-icons" style="font-size:48px; color:#0067b1;">cloud_off</i>
                <h3 style="margin:8px 0 4px; color:#0f172a; font-weight:800;">Modo Sin Conexión</h3>
                <p style="color:#64748b; font-size:13px; margin:0;">Los datos provienen de la última sincronización exitosa.</p>
            </div>
            <div id="offlineInfoBody" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; font-size:13px; color:#475569; margin-bottom:16px;"></div>
            <div style="display:flex; gap:8px;">
                <button type="button" onclick="document.getElementById('offlineLoginInfo').style.display='none';"
                    style="flex:1; padding:12px; background:white; border:1px solid #e2e8f0; border-radius:10px; font-weight:700; cursor:pointer; color:#64748b; font-family:inherit;">
                    Cancelar
                </button>
                <button type="button" id="btnConfirmOffline"
                    style="flex:1; padding:12px; background:linear-gradient(135deg,#0067b1,#004481); border:none; border-radius:10px; font-weight:700; cursor:pointer; color:white; font-family:inherit;">
                    Continuar offline
                </button>
            </div>
        </div>
    </div>

    {{-- Carga sql.js + auth-offline para que el botón "Sin internet" funcione --}}
    <script src="{{ asset('js/sync/db.js') }}?v={{ @filemtime(public_path('js/sync/db.js')) }}"></script>
    <script src="{{ asset('js/sync/auth-offline.js') }}?v={{ @filemtime(public_path('js/sync/auth-offline.js')) }}"></script>
    <script>
        (async function () {
            // Detección móvil — incluye PWA standalone (que a veces no tiene "Mobile" en UA)
            const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent)
                          || window.matchMedia('(display-mode: standalone)').matches
                          || window.matchMedia('(max-width: 900px)').matches;
            if (!isMobile) return;

            const wrap = document.getElementById('offlineLoginWrap');
            if (!wrap) return;
            // SIEMPRE mostramos el botón en móvil — adentro decidimos qué hacer
            wrap.style.display = '';

            let has = false, cachedEmail = '';
            try {
                if (window.vidalsaDB && window.vidalsaAuthOffline) {
                    await window.vidalsaDB.init();
                    has = await window.vidalsaAuthOffline.hasLocalCredentials();
                    if (has) cachedEmail = await window.vidalsaAuthOffline.cachedEmail();
                }
            } catch (e) { console.warn('[offline-login]', e); }

            document.getElementById('btnOfflineLogin').addEventListener('click', () => {
                if (!has) {
                    // No hay credenciales locales — explicar qué hacer
                    document.getElementById('offlineInfoBody').innerHTML =
                        `<strong style="color:#dc2626;">Aún no puedes entrar sin Internet.</strong><br>` +
                        `<small>La primera vez DEBES iniciar sesión con Internet (datos o WiFi).<br>` +
                        `Después de eso, espera unos segundos en la pantalla principal para que se descarguen los datos del servidor.<br>` +
                        `Una vez descargados, podrás entrar sin Internet con el mismo correo y contraseña.</small>`;
                    document.getElementById('btnConfirmOffline').style.display = 'none';
                } else {
                    document.getElementById('offlineInfoBody').innerHTML =
                        `<strong>Cuenta cacheada:</strong> ${cachedEmail || '—'}<br>` +
                        `<small>Ingresa la misma contraseña que usaste online.</small>`;
                    document.getElementById('btnConfirmOffline').style.display = '';
                }
                document.getElementById('offlineLoginInfo').style.display = 'flex';
            });

            document.getElementById('btnConfirmOffline').addEventListener('click', async () => {
                const correo = document.getElementById('login_identifier').value.trim();
                const password = document.getElementById('password').value;
                if (!correo || !password) {
                    alert('Ingresa tu correo y contraseña.');
                    return;
                }
                const result = await window.vidalsaAuthOffline.verify(correo, password);
                if (result.ok) {
                    sessionStorage.setItem('vidalsa_offline_session', JSON.stringify(result.user));
                    sessionStorage.setItem('vidalsa_offline_mode', '1');
                    window.location.href = '{{ route("menu") }}?offline=1';
                } else {
                    alert(result.error || 'No se pudo iniciar sesión offline.');
                }
            });
        })();
    </script>
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
    }
    document.addEventListener('DOMContentLoaded', () => {
        // Auto-Refresh to prevent 419 Page Expired (CSRF Token Mismatch)
        // Refreshes every 20 minutes to keep the token fresh
        setInterval(() => {
            window.location.reload();
        }, 1000 * 60 * 20);

        const loginFormElement = document.getElementById('loginForm');
        if (loginFormElement) {
            loginFormElement.reset(); // Vaciar campos al cargar la página
        }

        const inputs = document.querySelectorAll('.custom-input');
        const checkValue = (input) => {
            if (input.value.trim() !== "") input.classList.add('has-value');
            else input.classList.remove('has-value');
        };
        inputs.forEach(input => {
            checkValue(input);
            input.addEventListener('input', () => checkValue(input));
            input.addEventListener('change', () => checkValue(input));
        });
        setTimeout(() => inputs.forEach(input => checkValue(input)), 300);
    });
    const pageLoadTime = Date.now();

    window.addEventListener('load', function() {
        const preloader = document.getElementById('loginPreloader');
        setTimeout(() => { if (preloader) preloader.classList.add('fade-out'); }, 500);
    });

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

            // 1. Handshake: Request fresh security token
            fetch('/refresh-csrf')
                .then(response => response.text())
                .then(newToken => {
                    // 2. Inject new token into form
                    const tokenInput = loginForm.querySelector('input[name="_token"]');
                    if (tokenInput) {
                        tokenInput.value = newToken;
                    }
                    
                    // 3. Submit form securely
                    loginForm.submit();
                })
                .catch(error => {
                    console.error('Handshake failed:', error);
                    // Fallback: submit anyway, let server decide
                    loginForm.submit();
                });
        });
    }
</script>
</html>

