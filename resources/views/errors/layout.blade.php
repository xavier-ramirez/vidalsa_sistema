{{-- Base BRANDED para las páginas de error (404/405/419/500/503/403).
     Autocontenida a propósito: NO extiende el layout principal (que requiere sesión,
     JS y assets que durante un error podrían fallar). Solo HTML + CSS inline + el logo.
     Las páginas concretas definen @section('code'/'title'/'message'/'cta'). --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($__code = trim($__env->yieldContent('code')))
    <title>{{ $__code !== '' ? $__code . ' · ' : '' }}Sistema Vidalsa</title>
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
            padding: 44px 36px 38px;
            /* Más profundidad: sombra ámplia azulada + uno cercano para apoyo. */
            box-shadow: 0 26px 60px rgba(0,51,122,0.16), 0 8px 20px rgba(15,23,42,0.07);
            animation: err-rise 0.45s cubic-bezier(0.16, 1, 0.3, 1) both;
        }
        @keyframes err-rise { from { opacity: 0; transform: translateY(14px) scale(0.98); } }
        .err-logo { height: 50px; margin-bottom: 20px; object-fit: contain; }
        /* Badge circular opcional (focal del aviso cuando no se muestra el número). */
        .err-icon {
            width: 82px; height: 82px; margin: 2px auto 20px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: #00337a;
            background: radial-gradient(circle at 30% 25%, #eaf2ff, #d6e6ff);
            box-shadow: inset 0 0 0 1px rgba(0,51,122,0.07), 0 6px 16px rgba(0,51,122,0.10);
        }
        .err-icon svg { width: 38px; height: 38px; display: block; }
        .err-code {
            font-size: 72px; font-weight: 800; line-height: 1; letter-spacing: -3px;
            color: #00337a;
        }
        .err-title { font-size: 21px; font-weight: 700; margin: 14px 0 6px; color: #0f172a; }
        .err-msg { font-size: 14px; line-height: 1.55; color: #64748b; margin-bottom: 28px; }
        .err-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%;
            background: #00337a; color: #fff; font-weight: 800; font-size: 14.5px;
            text-decoration: none; padding: 14px 24px; border-radius: 12px;
            box-shadow: 0 8px 18px rgba(0,51,122,0.22);
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }
        .err-btn:hover { transform: translateY(-2px); background: #003e94; box-shadow: 0 12px 26px rgba(0,51,122,0.30); }
        .err-btn:active { transform: translateY(0); }
    </style>
</head>
<body>
    <div class="err-card">
        <img class="err-logo" src="{{ asset('images/maquinaria/logo.webp') }}"
             alt="Constructora Vidalsa" onerror="this.style.display='none'">
        @hasSection('icon')
            <div class="err-icon">@yield('icon')</div>
        @endif
        @if($__code !== '')
            <div class="err-code">{{ $__code }}</div>
        @endif
        <div class="err-title">@yield('title')</div>
        <div class="err-msg">@yield('message')</div>
        <a class="err-btn" href="{{ url('/') }}">@yield('cta', 'Ir al inicio de sesión')</a>
    </div>
</body>
</html>
