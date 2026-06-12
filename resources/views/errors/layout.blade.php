{{-- Base BRANDED para las páginas de error (404/405/419/500/503/403).
     Autocontenida a propósito: NO extiende el layout principal (que requiere sesión,
     JS y assets que durante un error podrían fallar). Solo HTML + CSS inline + el logo.
     Las páginas concretas definen @section('code'/'title'/'message'/'cta'). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('code', 'Error') · Sistema Vidalsa</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; color: #fff;
            background: linear-gradient(135deg, #00004d 0%, #0a2a6b 55%, #0067b1 100%);
        }
        .err-card {
            width: 100%; max-width: 460px; text-align: center;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.15); border-radius: 22px;
            padding: 42px 34px; box-shadow: 0 24px 60px rgba(0,0,0,0.3);
        }
        .err-logo { height: 56px; margin-bottom: 24px; object-fit: contain; }
        .err-code {
            font-size: 68px; font-weight: 800; line-height: 1; letter-spacing: -2px;
            background: linear-gradient(180deg, #ffffff, #bcd6f0);
            -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
        }
        .err-title { font-size: 21px; font-weight: 700; margin: 12px 0 8px; }
        .err-msg { font-size: 14px; line-height: 1.6; opacity: 0.82; margin-bottom: 28px; }
        .err-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: #fff; color: #00337a; font-weight: 800; font-size: 14px;
            text-decoration: none; padding: 13px 24px; border-radius: 11px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .err-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.28); }
        .err-foot { margin-top: 22px; font-size: 11.5px; opacity: 0.5; letter-spacing: 0.3px; }
    </style>
</head>
<body>
    <div class="err-card">
        {{-- onerror: si el logo no carga, simplemente se oculta (no rompe la página). --}}
        <img class="err-logo" src="{{ asset('images/maquinaria/logo.webp') }}"
             alt="Constructora Vidalsa" onerror="this.style.display='none'">
        <div class="err-code">@yield('code')</div>
        <div class="err-title">@yield('title')</div>
        <div class="err-msg">@yield('message')</div>
        <a class="err-btn" href="{{ url('/') }}">@yield('cta', 'Ir al inicio de sesión')</a>
        <div class="err-foot">Sistema de Gestión · Constructora Vidalsa 27, C.A.</div>
    </div>
</body>
</html>
