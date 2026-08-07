{{-- ── Escanear QR de producto (compartido por los módulos de inventario) ──────
     Lo incluyen /admin/almacen, /admin/almacen/movimientos y /admin/almacen/recepcion:
     trae el modal de cámara, el estilo del icono QR que va DENTRO del buscador y las
     rutas que necesita window.QrScan (public/js/maquinaria/qr_scan.js, global).

     Read-only y SOLO móvil: la cámara (html5-qrcode) arranca en contexto seguro
     (HTTPS/localhost) pidiendo el permiso de una al abrir, resuelve el CODIGO vía
     almacen.buscar-codigo y se lo entrega a la vista (onProducto). En PC no se abre:
     el lector USB escribe en el buscador o lo resuelve el listener global.

     La vista que lo incluye pone el icono en su buscador y engancha el escaneo:
         <i class="material-icons qrs-ic" id="miScan" title="Escanear código QR"
            onclick="window.QrScan.abrir()">&#xf206;</i>
         window.QrScan.init({ input: 'miBuscador', icono: 'miScan', activo: fn, onProducto: fn });
     El @include puede ir en cualquier punto del contenido: las rutas viajan como data-*
     del modal y QrScan las lee al usarlas, así que no hay orden obligatorio de scripts.
--}}

<style>
    /* Icono "escanear QR" DENTRO del buscador (patrón apps de súper). Mutuamente
       excluyente con la "x" de limpiar: visible solo cuando el buscador está vacío
       (lo alterna QrScan.iconToggle). Sirve igual en las tres cajas de búsqueda
       (.alm-filter-box, .amf-search-box, .tr-search-box) porque solo aporta espaciado. */
    .qrs-ic { padding: 0 8px; color: #64748b; font-size: 20px; cursor: pointer; transition: color .15s; align-items: center; flex: 0 0 auto; }
    .qrs-ic:hover { color: #7c3aed; }

    /* Velo + caja del modal. Propios (no reusa .alm-modal-overlay) para que el partial
       funcione igual en Movimientos y Recepción, que no tienen ese CSS. */
    .qrs-overlay {
        display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45);
        z-index: 10000; align-items: center; justify-content: center; padding: 16px;
    }
    .qrs-overlay.open { display: flex; }
    .qrs-modal {
        background: #fff; border-radius: 14px; width: 100%; max-width: 340px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.25); overflow: hidden;
        display: flex; flex-direction: column; max-height: 90vh;
    }
    .qrs-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #e2e8f0; }
    .qrs-head h3 { margin: 0; font-size: 16px; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
    .qrs-head .qrs-x { color: #64748b; cursor: pointer; font-size: 20px; }
    .qrs-body { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 10px; overflow-y: auto; min-height: 0; }
</style>

{{-- data-buscar: endpoint que resuelve el CODIGO escaneado. data-lib: copia LOCAL de
     html5-qrcode (public/js/vendor/). QrScan las lee del propio modal cuando las necesita
     —no hay <script> de configuración ni, por tanto, orden obligatorio entre este partial y
     el script de la vista. La librería NO se carga con un <script> de página: se inyecta bajo
     demanda (local → CDN). Para (re)descargarla, ver public/js/vendor/README-html5-qrcode.txt. --}}
<div id="qrsModal" class="qrs-overlay"
     data-buscar="{{ route('almacen.buscar-codigo') }}"
     data-lib="{{ asset('js/vendor/html5-qrcode.min.js') }}?v={{ @filemtime(public_path('js/vendor/html5-qrcode.min.js')) }}">
    <div class="qrs-modal">
        <div class="qrs-head">
            <h3><i class="material-icons" style="font-size:20px;color:#7c3aed;">&#xf206;</i> Escanear producto</h3>
            <i class="material-icons qrs-x" onclick="window.QrScan.cerrar()">close</i>
        </div>
        <div class="qrs-body">
            {{-- Recuadro de cámara: oculto por defecto. Se muestra cuando la cámara arranca. --}}
            <div id="qrsReader" style="display:none;width:100%;border-radius:10px;overflow:hidden;background:#0f172a;"></div>
            <div id="qrsHint" style="font-size:12px;color:#64748b;text-align:center;"></div>
            {{-- Botón de reintento: aparece SOLO si el arranque/permiso de la cámara falla.
                 En el flujo normal queda oculto (la cámara pide permiso de una al abrir). --}}
            <button type="button" id="qrsActivar" onclick="window.QrScan.reintentar()"
                    style="display:none;width:100%;padding:11px;border:none;border-radius:8px;background:#7c3aed;color:#fff;font-weight:700;font-size:14px;cursor:pointer;">
                <i class="material-icons" style="font-size:19px;vertical-align:-4px;margin-right:6px;">photo_camera</i>Activar cámara
            </button>
        </div>
    </div>
</div>
