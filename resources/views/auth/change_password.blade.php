<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambio de Contraseña</title>
    <!-- Fonts -->
    <link href="{{ asset('css/fonts.css') }}?v={{ @filemtime(public_path('css/fonts.css')) }}" rel="stylesheet">
    <style>
        /* --- PRELOADER STYLES --- */
        .preloader {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background-color: #fff;
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            transition: opacity 0.5s ease-out, visibility 0.5s;
        }
        .preloader.fade-out { opacity: 0; visibility: hidden; }
        .preloader-content { text-align: center; }
        .preloader-logo {
            max-height: 80px;
            margin-bottom: 20px;
            animation: pulse 2s infinite ease-in-out;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        .spinner-circle {
            width: 60px; height: 60px;
            border: 5px solid rgba(0, 0, 77, 0.1);
            border-top-color: #00004d;
            border-radius: 50%;
            animation: spin-circular 1s linear infinite;
            margin: 20px auto;
        }
        @keyframes spin-circular { to { transform: rotate(360deg); } }

        body {
            font-family: 'Inter', sans-serif;
            background-color: #dddcdcee;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            min-height: 100vh;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            background: white;
            padding: 40px;
            border-radius: 12px;
            /* border: none; (White/Invisible as requested) */
            box-shadow: 0 0 25px -5px rgba(0, 0, 0, 0.25), 0 0 10px -5px rgba(0, 0, 0, 0.15); /* Sombra un poco más oscura */
        }

        .form-group { margin-bottom: 20px; }
        
        .custom-label { 
            display: block; 
            font-weight: 600; 
            color: #374151; 
            margin-bottom: 8px; 
            font-size: 14px; 
        }

        .input-wrapper { 
            position: relative; 
            width: 100%;
        }

        .custom-input {
            width: 100%;
            padding: 12px 40px 12px 42px; /* Left padding for lock icon, Right for eye */
            border: 1px solid #d1d5db;
            border-radius: 6px;
            box-sizing: border-box;
            font-size: 15px;
            color: #1f2937;
            /* transition: border-color 0.2s; REMOVED FOR STABILITY */
            height: 48px;
        }

        .custom-input:focus { 
            outline: none; 
            /* No border change on click */
        }

        /* Left Icon (Lock) */
        .icon-left { 
            position: absolute; 
            left: 12px; 
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af; 
            pointer-events: none;
            font-size: 20px;
        }

        /* Right Icon (Eye Toggle) */
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9ca3af;
            padding: 4px; /* Hitbox increase */
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: color 0.2s, background-color 0.2s;
        }

        .password-toggle:hover { 
            color: #4b5563; 
            background-color: #f3f4f6;
        }

        .password-toggle .material-icons {
            font-size: 20px;
        }

        .btn-primary {
            width: 100%;
            background: #00004d;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 16px;
            transition: background-color 0.2s;
            margin-top: 10px;
        }
        
        .btn-primary:hover { background: #656565; }

        .btn-link {
            background: none;
            border: none;
            color: #6b7280;
            text-decoration: underline;
            font-size: 14px;
            padding: 0;
            font-family: inherit;
        }
        .btn-link:hover { color: #374151; }

        /* FORCE AUTOFILL STYLE OVERRIDE */
        input:-webkit-autofill,
        input:-webkit-autofill:hover, 
        input:-webkit-autofill:focus, 
        input:-webkit-autofill:active{
            -webkit-box-shadow: 0 0 0 30px white inset !important;
            -webkit-text-fill-color: #1f2937 !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        /* Mobile Responsive */
        @media (max-width: 480px) {
            .login-card {
                width: auto;
                padding: 25px;
                margin: 20px;
            }
            .custom-title {
                font-size: 20px !important;
            }
            body {
                align-items: flex-start;
                padding-top: 40px;
            }
        }
    </style>
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
</body>
</html>
