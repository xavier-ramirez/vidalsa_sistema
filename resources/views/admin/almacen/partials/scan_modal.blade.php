{{-- ── Escanear el código de barras del producto (compartido por los módulos de inventario) ──
     Lo incluyen /admin/almacen, /admin/almacen/movimientos y /admin/almacen/recepcion:
     trae el modal de cámara, el estilo del icono de escanear que va DENTRO del buscador y
     las rutas que necesita window.LectorBarras (public/js/maquinaria/lector_barras.js, global).

     Read-only y SOLO móvil: la cámara (html5-qrcode) arranca en contexto seguro
     (HTTPS/localhost) pidiendo el permiso de una al abrir. Con «Filtrar aquí» resuelve el
     CODIGO vía almacen.buscar-codigo y se lo entrega a la vista (onProducto); con «En la PC»
     lo manda a almacen.lector-remoto y lo filtra la PC del mismo usuario. En PC no se abre:
     el lector USB escribe en el buscador o lo resuelve el listener global, y la PC recoge lo
     del teléfono por /api/lector-remoto con la llave data-canal.

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
       máscara, del color del texto. Mide 1em, como los íconos de la fuente (en el buscador,
       20 px por el font-size del .lbr-ic), y queda centrado dentro de su relleno. Lo usan
       también los botones de Etiquetas del inventario, que incluye este partial. */
    .icono-barras {
        --icono-barras: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M2 4h2v16H2zM5 4h1v16H5zM7 4h2v16H7zM10 4h1v16h-1zM12 4h3v16h-3zM16 4h1v16h-1zM18 4h1v16h-1zM20 4h2v16h-2z'/%3E%3C/svg%3E");
        display: inline-block; width: 1em; height: 1em; box-sizing: content-box; vertical-align: middle;
        background-color: currentColor;
        -webkit-mask: var(--icono-barras) center / 1em 1em no-repeat;
                mask: var(--icono-barras) center / 1em 1em no-repeat;
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
    /* Línea láser que sube y baja dentro del recuadro de lectura, como en los lectores de las
       tiendas: dice dónde poner las barras y que está leyendo. Cuelga de la sombra que pinta
       html5-qrcode (#qr-shaded-region, la crea al arrancar la cámara), cuyo hueco es justo
       el recuadro. Se mueve con transform y no con top: lo resuelve la tarjeta gráfica, sin
       recalcular la página en cada cuadro mientras la cámara lee. La caja ocupa el alto útil
       del recuadro y la línea es una franja de su borde de arriba: bajar la caja su propio
       alto la lleva al fondo. */
    #lbrReader #qr-shaded-region::after {
        content: ''; position: absolute; left: 4%; right: 4%; top: 6%; height: 88%; pointer-events: none;
        background: linear-gradient(to bottom, rgba(239,68,68,0), rgba(239,68,68,.45) 2px, #ef4444 3px 5px,
                    rgba(239,68,68,.45) 6px, rgba(239,68,68,0) 8px) top / 100% 8px no-repeat;
        animation: lbr-laser 1.6s ease-in-out infinite alternate;
    }
    @keyframes lbr-laser { from { transform: translateY(-4px); } to { transform: translateY(calc(100% - 4px)); } }

    /* «Filtrar aquí | En la PC»: dónde se aplica lo que lee la cámara (LectorBarras.destino). */
    .lbr-destino { display: flex; gap: 4px; padding: 3px; background: #f1f5f9; border-radius: 10px; }
    .lbr-destino button {
        flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px;
        padding: 8px 6px; border: none; border-radius: 8px; background: transparent;
        color: #475569; font-size: 13px; font-weight: 600; cursor: pointer;
    }
    .lbr-destino button .material-icons { font-size: 18px; }
    .lbr-destino button[aria-pressed="true"] { background: #fff; color: #7c3aed; box-shadow: 0 1px 3px rgba(15,23,42,.15); }

    /* Recuadro reservado mientras arranca la cámara (LectorBarras.reservarRecuadro): el
       modal sale ya de su tamaño y aquí gira el aviso de espera hasta que llega el video. */
    #lbrReader { position: relative; }
    #lbrReader.lbr-cargando::before {
        content: ''; position: absolute; top: 50%; left: 50%; width: 30px; height: 30px; margin: -15px 0 0 -15px;
        border: 3px solid rgba(255,255,255,.25); border-top-color: #fff; border-radius: 50%;
        animation: lbr-gira .8s linear infinite;
    }
    @keyframes lbr-gira { to { transform: rotate(360deg); } }
</style>

{{-- data-enviar / data-estado: el teléfono manda el código a la PC y avisa si la cámara está
     abierta en «En la PC». data-pendientes + data-canal: la PC pregunta por lo que llegó, sin
     sesión, con una llave firmada de 12 h (App\Services\LectorRemoto::canal) que se renueva
     en cada visita a la pantalla.
     data-buscar: endpoint que resuelve el CODIGO escaneado. data-lib: copia LOCAL de
     html5-qrcode (public/js/vendor/). LectorBarras las lee del propio modal cuando las necesita
     —no hay <script> de configuración ni, por tanto, orden obligatorio entre este partial y
     el script de la vista. La librería NO se carga con un <script> de página: se inyecta bajo
     demanda (local → CDN). Para (re)descargarla, ver public/js/vendor/README-html5-qrcode.txt. --}}
<div id="lbrModal" class="lbr-overlay"
     data-buscar="{{ route('almacen.buscar-codigo') }}"
     data-enviar="{{ route('almacen.lector-remoto.enviar') }}"
     data-estado="{{ route('almacen.lector-remoto.estado') }}"
     data-pendientes="{{ route('lector-remoto.pendientes') }}"
     data-canal="{{ \App\Services\LectorRemoto::canal(auth()->user()) }}"
     data-lib="{{ asset('js/vendor/html5-qrcode.min.js') }}?v={{ @filemtime(public_path('js/vendor/html5-qrcode.min.js')) }}">
    <div class="lbr-modal">
        <div class="lbr-head">
            <h3><i class="icono-barras" style="font-size:20px;"></i> Escanear producto</h3>
            <i class="material-icons lbr-x" onclick="window.LectorBarras.cerrar()">close</i>
        </div>
        <div class="lbr-body">
            <div class="lbr-destino" role="group" aria-label="Dónde filtrar">
                <button type="button" id="lbrDestAqui" aria-pressed="true" onclick="window.LectorBarras.destino('aqui')">
                    <i class="material-icons">smartphone</i>Filtrar aquí
                </button>
                <button type="button" id="lbrDestPc" aria-pressed="false" onclick="window.LectorBarras.destino('pc')">
                    <i class="material-icons">computer</i>En la PC
                </button>
            </div>
            {{-- Recuadro de cámara: oculto hasta abrir; al abrir se reserva su alto (ver arriba). --}}
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
