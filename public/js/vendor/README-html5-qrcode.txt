html5-qrcode — librería de escaneo por cámara (módulo Almacén / Etiquetas)
================================================================================

QUÉ ES
------
`html5-qrcode.min.js` es la librería que abre la cámara del teléfono y lee el
CÓDIGO DE BARRAS (Code 128) de la etiqueta del producto (icono de escanear en los
buscadores de Inventario, Movimientos y Recepción). Pese al nombre, lee también
códigos de barras; window.LectorBarras la limita a Code 128.

Se sirve LOCALMENTE (no depende de internet ni de un CDN en tiempo de ejecución).
No va en un <script> de página: public/js/maquinaria/lector_barras.js la inyecta
bajo demanda desde la ruta que lleva el modal (data-lib en
resources/views/admin/almacen/partials/scan_modal.blade.php).

Las etiquetas las dibuja JsBarcode (js/vendor/JsBarcode-3.11.6.all.min.js, MIT).

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
