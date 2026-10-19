{{-- ── Escanear el código de barras del producto (compartido por los módulos de inventario) ──
     Lo incluyen /admin/almacen, /admin/almacen/movimientos y /admin/almacen/recepcion:
     trae el modal de cámara, el estilo del icono de escanear que va DENTRO del buscador y
     las rutas que necesita window.LectorBarras (public/js/maquinaria/lector_barras.js, global).

     Read-only y SOLO móvil: la cámara (html5-qrcode) arranca en contexto seguro
     (HTTPS/localhost) pidiendo el permiso de una al abrir, resuelve el CODIGO vía
     almacen.buscar-codigo y se lo entrega a la vista (onProducto). En PC no se abre:
     el lector USB escribe en el buscador o lo resuelve el listener global.

     La vista que lo incluye pone el icono en su buscador y engancha el escaneo:
         <i class="lbr-ic icono-barras" id="miScan" title="Escanear código de barras"
            onclick="window.LectorBarras.abrir()"></i>
         window.LectorBarras.init({ input: 'miBuscador', icono: 'miScan', activo: fn, onProducto: fn });
     El @include puede ir en cualquier punto del contenido: las rutas viajan como data-*
     del modal y LectorBarras las lee al usarlas, así que no hay orden obligatorio de scripts.
--}}

<style>
    /* Icono "escanear" DENTRO del buscador (patrón apps de súper). Mutuamente
       excluyente con la "x" de limpiar: visible solo cuando el buscador está vacío
       (lo alterna LectorBarras.iconToggle). Sirve igual en las tres cajas de búsqueda
       (.alm-filter-box, .amf-search-box, .tr-search-box) porque solo aporta espaciado. */
    .lbr-ic { padding: 0 8px; color: #64748b; font-size: 20px; cursor: pointer; transition: color .15s; align-items: center; flex: 0 0 auto; }
    .lbr-ic:hover { color: #7c3aed; }
    /* Ícono de código de barras (la fuente de íconos no trae uno): barras en SVG como
       máscara, del color del texto. Mide lo mismo que los íconos de la fuente (20 px) y
       queda centrado dentro del relleno del .lbr-ic. */
    .icono-barras {
        display: inline-block; width: 20px; height: 20px; box-sizing: content-box; background-color: currentColor;
        -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M2 4h2v16H2zM5 4h1v16H5zM7 4h2v16H7zM10 4h1v16h-1zM12 4h3v16h-3zM16 4h1v16h-1zM18 4h1v16h-1zM20 4h2v16h-2z'/%3E%3C/svg%3E") center / 20px 20px no-repeat;
                mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M2 4h2v16H2zM5 4h1v16H5zM7 4h2v16H7zM10 4h1v16h-1zM12 4h3v16h-3zM16 4h1v16h-1zM18 4h1v16h-1zM20 4h2v16h-2z'/%3E%3C/svg%3E") center / 20px 20px no-repeat;
    }

    /* Velo + caja del modal. Propios (no reusa .alm-modal-overlay) para que el partial
       funcione igual en Movimientos y Recepción, que no tienen ese CSS. */
    .lbr-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45);
        z-index: 10000; align-items: center; justify-content: center; padding: 16px;
    }
    .lbr-overlay.open { display: flex; }
    .lbr-modal {
        background: #fff; border-radius: 14px; width: 100%; max-width: 340px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.25); overflow: hidden;
        display: flex; flex-direction: column; max-height: 90vh;
    }
    /* Mismo encabezado que los demás modales de Almacén, Equipos y Recepción. */
    .lbr-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: #1e293b; }
    .lbr-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #fff; display: flex; align-items: center; gap: 8px; }
    .lbr-head h3 .material-icons { color: #fff; }
    .lbr-head .lbr-x { color: #fff; opacity: .75; cursor: pointer; font-size: 20px; }
    .lbr-head .lbr-x:hover { opacity: 1; }
    .lbr-body { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 10px; overflow-y: auto; min-height: 0; }
</style>

{{-- data-buscar: endpoint que resuelve el CODIGO escaneado. data-lib: copia LOCAL de
     html5-qrcode (public/js/vendor/). LectorBarras las lee del propio modal cuando las necesita
     —no hay <script> de configuración ni, por tanto, orden obligatorio entre este partial y
     el script de la vista. La librería NO se carga con un <script> de página: se inyecta bajo
     demanda (local → CDN). Para (re)descargarla, ver public/js/vendor/README-html5-qrcode.txt. --}}
<div id="lbrModal" class="lbr-overlay"
     data-buscar="{{ route('almacen.buscar-codigo') }}"
     data-lib="{{ asset('js/vendor/html5-qrcode.min.js') }}?v={{ @filemtime(public_path('js/vendor/html5-qrcode.min.js')) }}">
    <div class="lbr-modal">
        <div class="lbr-head">
            <h3><i class="icono-barras"></i> Escanear producto</h3>
            <i class="material-icons lbr-x" onclick="window.LectorBarras.cerrar()">close</i>
        </div>
        <div class="lbr-body">
            {{-- Recuadro de cámara: oculto por defecto. Se muestra cuando la cámara arranca. --}}
            <div id="lbrReader" style="display:none;width:100%;border-radius:10px;overflow:hidden;background:#0f172a;"></div>
            <div id="lbrHint" style="font-size:12px;color:#64748b;text-align:center;"></div>
            {{-- Botón de reintento: aparece SOLO si el arranque/permiso de la cámara falla.
                 En el flujo normal queda oculto (la cámara pide permiso de una al abrir). --}}
            <button type="button" id="lbrActivar" onclick="window.LectorBarras.reintentar()"
                    style="display:none;width:100%;padding:11px;border:none;border-radius:8px;background:#7c3aed;color:#fff;font-weight:700;font-size:14px;cursor:pointer;">
                <i class="material-icons" style="font-size:19px;vertical-align:-4px;margin-right:6px;">photo_camera</i>Activar cámara
            </button>
        </div>
    </div>
</div>
