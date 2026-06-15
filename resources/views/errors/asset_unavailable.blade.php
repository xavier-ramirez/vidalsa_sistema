{{-- Página mostrada cuando el PROXY de archivos (GoogleDriveController::proxy) no
     encuentra el archivo en Drive. A diferencia de errors/404 (que es de NAVEGACIÓN
     y lleva botón "Ir al inicio de sesión"), esta se sirve DENTRO de un visor/iframe
     de PDF o imagen — el usuario YA está logueado, así que aquí un botón de login no
     tiene sentido. Es autocontenida (sin layout principal) y solo informa. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documento no disponible · Sistema Vidalsa</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px; background: #ffffff;
        }
        .au-card {
            width: 100%; max-width: 360px; text-align: center;
            background: #ffffff;
            border: 1px solid #e5e9f0; border-radius: 18px;
            padding: 32px 28px;
            box-shadow: 0 12px 34px rgba(15, 40, 90, 0.12);
        }
        .au-icon { font-size: 38px; line-height: 1; margin-bottom: 14px; }
        .au-title { font-size: 18px; font-weight: 700; color: #00337a; margin-bottom: 8px; }
        .au-msg { font-size: 13px; line-height: 1.55; color: #6b7787; }
        .au-foot { margin-top: 20px; font-size: 11px; color: #aab4c2; letter-spacing: 0.3px; }
    </style>
</head>
<body>
    <div class="au-card">
        <div class="au-icon">📄</div>
        <div class="au-title">Documento no disponible</div>
        <div class="au-msg">El archivo ya no existe o fue movido. Recargá la página.</div>
        <div class="au-foot">Constructora Vidalsa 27, C.A.</div>
    </div>
</body>
</html>
