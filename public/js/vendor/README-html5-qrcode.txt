html5-qrcode — librería de escaneo de QR por cámara (módulo Almacén / Etiquetas)
================================================================================

QUÉ ES
------
`html5-qrcode.min.js` es la librería que abre la cámara del dispositivo y lee el
código QR de la etiqueta del producto (botón "Escanear" en /admin/almacen).

Se sirve LOCALMENTE (no depende de internet ni de un CDN en tiempo de ejecución).
La vista la carga así:

    resources/views/admin/almacen/index.blade.php
    <script src="{{ asset('js/vendor/html5-qrcode.min.js') }}" ...>

Versión usada: 2.3.8

CÓMO DESCARGARLA EN EL SERVIDOR (o re-descargarla)
--------------------------------------------------
Desde la raíz del proyecto, ejecutar UNO de estos:

  # Linux / Mac / Git-Bash
  curl -L https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js \
       -o public/js/vendor/html5-qrcode.min.js

  # Windows PowerShell
  Invoke-WebRequest -Uri "https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js" `
       -OutFile "public/js/vendor/html5-qrcode.min.js"

Verificar que quedó como JS (debe empezar con "var __Html5QrcodeLibrary__"):

  head -c 60 public/js/vendor/html5-qrcode.min.js

NOTAS
-----
- La cámara solo funciona sobre HTTPS (o localhost). Sobre HTTP la app igual
  permite escanear con lector USB o tecleando el código (degrada solo).
- Si este archivo falta, la vista intenta el CDN de unpkg como respaldo (onerror),
  pero lo correcto en producción es tenerlo aquí, local.
- Los iconos (Material Icons) y el logo del PDF YA son locales:
    fonts/MaterialIcons-Regular.ttf   (iconos)
    public/img/imagen_uno.jpg         (logo Nota de Entrega)
