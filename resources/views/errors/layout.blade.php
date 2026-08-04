{{-- Base BRANDED para las páginas de error (404/405/419/500/503/403).
     Autocontenida a propósito: NO extiende el layout principal (que requiere sesión,
     JS y assets que durante un error podrían fallar). Solo HTML + CSS inline + el logo.
     Las páginas concretas definen @section('code'/'title'/'message'/'cta'). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    {{-- Solo modo claro, igual que el resto de la app (ver estructura_base). --}}
    <meta name="color-scheme" content="light">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($__code = trim($__env->yieldContent('code')))
    <title>{{ $__code !== '' ? $__code . ' · ' : '' }}Sistema Vidalsa</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    {{-- Mismas fuentes que el login (Nunito) para que la tipografía coincida.
         Es solo CSS estático con @font-face: si fallara, cae al fallback de abajo. --}}
    <link href="{{ asset('css/fonts.css') }}?v={{ @filemtime(public_path('css/fonts.css')) }}" rel="stylesheet">
    <style>
        /* Azul del botón del login (--maquinaria-blue en inicio_sesion.css). */
        :root { --login-blue: #00004d; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Nunito', 'Segoe UI', Tahoma, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; color: #1e293b;
            /* Fondo blanco (igual pedido del cliente). */
            background: #fff;
        }
        /* Tarjeta con el MISMO tamaño, forma y sombra de la del login
           (.login-container-float-center: 400px, radius 12px, sombra 360°). */
        .err-card {
            width: 100%; max-width: 400px; text-align: center;
            background: #fff;
            border-radius: 12px;
            padding: 44px 40px 40px;
            box-shadow: 0 0 25px -5px rgba(0, 0, 0, 0.25), 0 0 10px -5px rgba(0, 0, 0, 0.15);
            animation: err-rise 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes err-rise { from { opacity: 0; transform: translateY(14px) scale(0.98); } }
        .err-logo { height: 50px; margin-bottom: 20px; object-fit: contain; }
        .err-code {
            font-size: 72px; font-weight: 800; line-height: 1; letter-spacing: -3px;
            color: var(--login-blue);
        }
        .err-title { font-size: 21px; font-weight: 700; margin: 14px 0 6px; color: #0f172a; }
        .err-msg { font-size: 14px; line-height: 1.55; color: #64748b; margin-bottom: 28px; }
        /* Botón con el azul del login (#00004d), forma y altura del .btn-maquinaria-primary. */
        .err-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; height: 50px;
            background: var(--login-blue); color: #fff; font-weight: 700; font-size: 16px;
            text-decoration: none; border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }
        .err-btn:hover { transform: translateY(-2px); background: #000066; box-shadow: 0 8px 16px rgba(0, 0, 77, 0.28); }
        .err-btn:active { transform: translateY(0); }
    </style>
</head>
<body>
    <div class="err-card">
        <img class="err-logo" src="{{ asset('images/maquinaria/logo.webp') }}"
             alt="Constructora Vidalsa" onerror="this.style.display='none'">
        @if($__code !== '')
            <div class="err-code">{{ $__code }}</div>
        @endif
        <div class="err-title">@yield('title')</div>
        <div class="err-msg">@yield('message')</div>
        <a class="err-btn" href="{{ url('/') }}">@yield('cta', 'Ir al inicio de sesión')</a>
    </div>
</body>
</html>
