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
            padding: 24px; color: #1e293b;
            /* Fondo apenas tintado para que la sombra de la tarjeta blanca resalte. */
            background: #f4f7fb;
        }
        .err-card {
            width: 100%; max-width: 410px; text-align: center;
            background: #fff;
            border: 1px solid #eef2f7; border-radius: 24px;
            padding: 44px 36px 36px;
            /* Más profundidad: sombra ámplia azulada + uno cercano para apoyo. */
            box-shadow: 0 26px 60px rgba(0,51,122,0.16), 0 8px 20px rgba(15,23,42,0.07);
        }
        .err-logo { height: 52px; margin-bottom: 22px; object-fit: contain; }
        .err-code {
            font-size: 72px; font-weight: 800; line-height: 1; letter-spacing: -3px;
            color: #00337a;
        }
        .err-title { font-size: 21px; font-weight: 700; margin: 14px 0 6px; color: #0f172a; }
        .err-msg { font-size: 14px; line-height: 1.55; color: #64748b; margin-bottom: 26px; }
        .err-btn {
            display: inline-flex; align-items: center; gap: 8px;
            background: #00337a; color: #fff; font-weight: 800; font-size: 14px;
            text-decoration: none; padding: 13px 24px; border-radius: 11px;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .err-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,51,122,0.25); }
        .err-foot { margin-top: 22px; font-size: 11.5px; color: #94a3b8; letter-spacing: 0.3px; }
    </style>
</head>
<body>
    <div class="err-card">
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
