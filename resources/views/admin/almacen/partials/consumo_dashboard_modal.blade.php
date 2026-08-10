{{-- ════════════════════════════════════════════════════════════════════════
     Modal "Dashboard de Consumo" — COMPARTIDO por /admin/almacen y
     /admin/almacen/movimientos (ambos lo incluyen y lo abren desde su botón
     Acciones con window.abrirConsumoDashboard()).

     INDEPENDIENTE de los filtros generales del módulo: tiene sus PROPIOS filtros
     (categoría + rango de meses Desde/Hasta). Datos: GET almacen.consumoDashboard
     (JSON) — consumo real (SALIDA) de todos los almacenes visibles.

     Chart.js se carga GLOBAL en el layout (la SPA omite <script src> en content).
     Las funciones se cuelgan de window para sobrevivir la navegación SPA.
═══════════════════════════════════════════════════════════════════════════ --}}
<style>
    .cdash-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.55); z-index:10050; align-items:flex-start; justify-content:center; padding:24px 14px; overflow-y:auto; }
    .cdash-overlay.open { display:flex; }
    .cdash-modal { background:#f1f5f9; border-radius:16px; width:100%; max-width:980px; box-shadow:0 20px 40px -12px rgba(0,0,0,0.35); overflow:hidden; animation:slideDown .2s ease-out; }
    .cdash-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 20px; background:#fff; border-bottom:1px solid #e2e8f0; }
    .cdash-head h3 { margin:0; font-size:16px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:9px; }
    .cdash-head h3 .material-icons { color:var(--maquinaria-blue,#0067b1); }
    .cdash-x { cursor:pointer; color:#64748b; border:none; background:transparent; display:flex; padding:4px; border-radius:8px; transition:background .15s; }
    .cdash-x:hover { background:#f1f5f9; color:#0f172a; }
    .cdash-body { padding:18px 20px 22px; }
    /* Barra de filtros PROPIA del dashboard (no depende de los filtros del módulo). */
    .cdash-filtros { display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px; margin-bottom:16px; }
    .cdash-filtros .f-group { display:flex; flex-direction:column; gap:3px; min-width:0; }
    .cdash-filtros .f-group-desc { flex:1 1 240px; }  /* Descripción: filtro principal, crece */
    .cdash-filtros .f-group-cat  { flex:0 1 180px; }  /* Categoría: ancho reducido */
    .cdash-filtros input[type="month"] { box-sizing:border-box; height:36px; width:130px; max-width:100%; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; font-size:13px; color:#0f172a; background:#fff; outline:none; cursor:pointer; }
    /* El navegador dibuja el <input type="month"> en español como "septiembre de 2026"
       (vacío: "---------- de ----"). Esta pseudo oculta ESE separador "de" nativo; los
       campos de mes y año siguen visibles. Solo WebKit/Blink (Chrome/Edge/Safari). */
    .cdash-filtros input[type="month"]::-webkit-datetime-edit-text { color:transparent; }
    /* Caja de texto compartida por Descripción y Categoría (mismo look). */
    .cdash-inp-box { display:flex; align-items:center; height:36px; border:1px solid #cbd5e0; border-radius:8px; background:#fff; overflow:hidden; }
    .cdash-inp-box.active { border-color:var(--maquinaria-blue,#0067b1); background:#e1effa; }
    .cdash-inp-box input { flex:1; border:none; background:transparent; outline:none; padding:0 8px; font-size:13px; color:#0f172a; min-width:0; }
    .cdash-inp-box i.material-icons { padding:0 6px; color:#64748b; font-size:16px; }
    .cdash-inp-box i.clr { cursor:pointer; }
    .cdash-cat-wrap { position:relative; width:100%; min-width:150px; }
    .cdash-cat-box { cursor:pointer; }
    .cdash-cat-box input { cursor:pointer; }
    .cdash-cat-list { display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 10px 22px rgba(0,0,0,.12); max-height:220px; overflow-y:auto; padding:4px; z-index:20; }
    .cdash-cat-list.open { display:block; }
    .cdash-cat-item { padding:7px 10px; border-radius:6px; font-size:13px; font-weight:600; color:#1e293b; cursor:pointer; }
    .cdash-cat-item:hover { background:#f0f4f8; }
    /* Filtro de CATEGORÍA en mayúsculas (placeholder, texto tecleado y opciones). */
    #cdashCatInput { text-transform: uppercase; }
    #cdashCatInput::placeholder { text-transform: uppercase; }
    #cdashCatList .cdash-cat-item { text-transform: uppercase; }
    /* Botón + panel de Filtros Avanzados (rango de meses + frente de destino). */
    .cdash-adv-btn { display:flex; align-items:center; justify-content:center; height:36px; width:36px; padding:0; border:1px solid #cbd5e0; border-radius:8px; background:#fff; color:#334155; cursor:pointer; transition:background .15s, border-color .15s; }
    .cdash-adv-btn:hover { background:#f8fafc; border-color:#94a3b8; }
    .cdash-adv-btn.active { background:#eff6ff; border-color:#0067b1; color:#0067b1; }
    .cdash-adv-btn .material-icons { font-size:18px; }
    .cdash-avanzados { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px 14px; margin-bottom:12px; animation:slideDown .18s ease-out; }
    .cdash-adv-row { display:flex; gap:12px; flex-wrap:wrap; }
    .cdash-adv-field { display:flex; flex-direction:column; gap:4px; font-size:11px; font-weight:700; color:#64748b; }
    .cdash-adv-field input, .cdash-adv-field select { height:36px; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; font-size:13px; color:#0f172a; background:#fff; outline:none; min-width:150px; }
    .cdash-adv-field input:focus, .cdash-adv-field select:focus { border-color:var(--maquinaria-blue,#0067b1); }
    .cdash-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .cdash-card { background:#fff; border:1px solid #e9eef5; border-radius:14px; padding:16px 18px; min-width:0;
        box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .cdash-card.full { grid-column:1 / -1; }
    .cdash-card h4 { margin:0 0 14px 0; font-size:13px; font-weight:800; color:#1e293b; display:flex; align-items:center; gap:8px; letter-spacing:.2px; }
    .cdash-card h4::before { content:''; width:4px; height:15px; border-radius:3px; background:linear-gradient(180deg,#0ea5e9,#0067b1); flex:0 0 auto; }
    /* Ícono para descargar cada gráfico individual (cámara, arriba a la derecha de la tarjeta). */
    .cdash-chart-dl { margin-left:auto; display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; padding:0; border:1px solid #e2e8f0; border-radius:7px; background:#fff; color:#64748b; cursor:pointer; transition:background .15s, color .15s, border-color .15s; }
    .cdash-chart-dl:hover { background:#eff6ff; color:#0067b1; border-color:#bfdbfe; }
    .cdash-chart-dl .material-icons { font-size:16px; }
    .cdash-canvas-wrap { position:relative; height:240px; }
    /* Top productos: más alto para que entren las 20 barras legibles. */
    .cdash-canvas-wrap.tall { height:520px; }
    .cdash-empty { color:#94a3b8; font-size:13px; text-align:center; padding:40px 0; }
    .cdash-loading { text-align:center; color:#64748b; font-size:14px; padding:50px 0; font-weight:600; display:flex; flex-direction:column; align-items:center; gap:10px; }
    .cdash-loading .cdash-spin { animation:cdashSpin .8s linear infinite; font-size:28px; color:#0067b1; }
    @keyframes cdashSpin { 100% { transform:rotate(360deg); } }
    @media (max-width: 760px) {
        .cdash-grid { grid-template-columns:1fr; }
        .cdash-body { padding-left:14px; padding-right:14px; }   /* más ancho útil en móvil */
        /* Descripción y Categoría cada una en su propia fila (van primero); Desde y Hasta UNO AL
           LADO DEL OTRO. Ancho por FLEX. Gracias a box-sizing:border-box + min-width:0 el input de
           mes encoge y los dos caben sin salirse del modal en cualquier teléfono. Se reduce
           fuente/padding y se oculta el icono del calendario (el campo igual abre el selector al
           tocarlo) para más holgura. */
        .cdash-filtros { gap:8px; }
        .cdash-filtros .f-group { flex:1 1 0; min-width:0; }
        /* Descripción ocupa su propia fila (es el filtro principal y su texto es largo).
           Categoría y el botón de filtros avanzados COMPARTEN la siguiente: el botón mide lo
           suyo (flex:0 0 auto) y Categoría se queda con el resto. Antes el botón caía a una
           tercera fila él solo, desperdiciando alto en la pantalla más estrecha. */
        .cdash-filtros .f-group-desc { flex:1 1 100%; }
        .cdash-filtros .f-group-cat  { flex:1 1 auto; }
        .cdash-filtros .f-group-adv  { flex:0 0 auto; }
        .cdash-filtros input[type="month"] { width:100%; min-width:0; font-size:12px; padding:0 6px; }
        .cdash-filtros input[type="month"]::-webkit-calendar-picker-indicator { display:none; }
    }
</style>

<div id="consumoDashModal" class="cdash-overlay" onclick="if(event.target===this) window.cerrarConsumoDashboard()">
    <div class="cdash-modal">
        <div class="cdash-head">
            {{-- Solo el título: el subtítulo descriptivo se quitó para que el encabezado
                 ocupe menos alto y quede más contenido a la vista sin hacer scroll. --}}
            <h3><i class="material-icons">analytics</i> Dashboard de Consumo</h3>
            <div style="display:flex;align-items:center;gap:4px;">
                <button type="button" class="cdash-x" onclick="window._cdashDescargarImagen()" aria-label="Descargar imagen" title="Descargar como imagen (PNG)"><i class="material-icons">download</i></button>
                <button type="button" class="cdash-x" onclick="window.cerrarConsumoDashboard()" aria-label="Cerrar"><i class="material-icons">close</i></button>
            </div>
        </div>
        <div class="cdash-body">
            {{-- Filtros propios del dashboard. Orden: Descripción primero (filtro principal),
                 luego Categoría (ancho reducido) y el rango de meses Desde/Hasta a la derecha.
                 Sin títulos: cada control se identifica por su placeholder/valor. --}}
            <div class="cdash-filtros">
                <div class="f-group f-group-desc">
                    {{-- .cdash-cat-wrap: reutiliza el posicionamiento (position:relative) para
                         que el dropdown de recomendaciones (#cdashDescList) caiga bajo el input. --}}
                    <div class="cdash-cat-wrap">
                        <div class="cdash-inp-box" id="cdashDescBox">
                            <i class="material-icons">search</i>
                            <input type="text" id="cdashDescripcion" placeholder="Descripción del producto…" autocomplete="off"
                                   oninput="window._cdashDescInput()"
                                   onkeydown="if(event.key==='Enter'){event.preventDefault();window._cdashDescCloseSug();clearTimeout(window._cdashDescTimer);window._cdashFetch();}"
                                   onblur="setTimeout(function(){window._cdashDescCloseSug();},180)">
                            <i class="material-icons clr" id="cdashDescClear" style="display:none;" onclick="window._cdashDescClear()">close</i>
                        </div>
                        {{-- Recomendaciones (nombres de producto) — mismo look que la lista de Categoría. --}}
                        <div class="cdash-cat-list" id="cdashDescList"></div>
                    </div>
                </div>
                <div class="f-group f-group-cat">
                    <div class="cdash-cat-wrap">
                        <input type="hidden" id="cdashCategoria" value="">
                        <div class="cdash-inp-box cdash-cat-box" id="cdashCatBox" onclick="window._cdashCatOpen()">
                            <i class="material-icons">search</i>
                            <input type="text" id="cdashCatInput" placeholder="Categoría" autocomplete="off"
                                   oninput="window._cdashCatFilter(this.value)"
                                   onfocus="window._cdashCatOpen()"
                                   onblur="setTimeout(function(){window._cdashCatClose()},180)">
                            <i class="material-icons clr" id="cdashCatClear" style="display:none;" onmousedown="event.preventDefault();window._cdashCatSelect('',CDASH_CAT_LBL);">close</i>
                        </div>
                        <div class="cdash-cat-list" id="cdashCatList"></div>
                    </div>
                </div>
                <div class="f-group f-group-adv">
                    <button type="button" id="cdashAdvBtn" class="cdash-adv-btn" onclick="window._cdashToggleAvanzados()" title="Filtros avanzados (fechas + frente)" aria-label="Filtros avanzados">
                        <i class="material-icons">tune</i>
                    </button>
                </div>
            </div>

            {{-- Panel de Filtros Avanzados: rango de meses + frente de destino del consumo.
                 Oculto hasta abrir con el botón de embudo (#cdashAdvBtn). Sin título propio:
                 el botón que lo abre ya lo identifica y cada campo lleva su etiqueta. --}}
            <div id="cdashAvanzados" class="cdash-avanzados" style="display:none;">
                <div class="cdash-adv-row">
                    <label class="cdash-adv-field"><span>Desde (mes)</span>
                        <input type="month" id="cdashDesde" title="Desde (mes)" onchange="window._cdashFetch()" onclick="try{ this.showPicker(); }catch(e){}">
                    </label>
                    <label class="cdash-adv-field"><span>Hasta (mes)</span>
                        <input type="month" id="cdashHasta" title="Hasta (mes)" onchange="window._cdashFetch()" onclick="try{ this.showPicker(); }catch(e){}">
                    </label>
                    <label class="cdash-adv-field"><span>Frente de destino</span>
                        <select id="cdashFrente" onchange="window._cdashFetch()">
                            <option value="">Todos los frentes</option>
                        </select>
                    </label>
                </div>
            </div>

            <div id="cdashLoading" class="cdash-loading"><i class="material-icons cdash-spin">refresh</i><span>Cargando datos de consumo…</span></div>
            <div id="cdashContent" style="display:none;">
                <div class="cdash-grid">
                    <div class="cdash-card full"><h4>Consumo por mes<button type="button" class="cdash-chart-dl" onclick="window._cdashDescargarGrafico(this,'consumo-por-mes')" title="Descargar gráfico" aria-label="Descargar gráfico"><i class="material-icons">photo_camera</i></button></h4><div class="cdash-canvas-wrap"><canvas id="cdashChartMes"></canvas></div></div>
                    <div class="cdash-card full"><h4>Top 20 productos consumidos<button type="button" class="cdash-chart-dl" onclick="window._cdashDescargarGrafico(this,'top-20-consumidos')" title="Descargar gráfico" aria-label="Descargar gráfico"><i class="material-icons">photo_camera</i></button></h4><div class="cdash-canvas-wrap tall"><canvas id="cdashChartTop"></canvas></div></div>
                    <div class="cdash-card full"><h4>Consumo por almacén<button type="button" class="cdash-chart-dl" onclick="window._cdashDescargarGrafico(this,'consumo-por-almacen')" title="Descargar gráfico" aria-label="Descargar gráfico"><i class="material-icons">photo_camera</i></button></h4><div class="cdash-canvas-wrap"><canvas id="cdashChartAlm"></canvas></div></div>
                </div>
            </div>
            <div id="cdashEmpty" class="cdash-empty" style="display:none;">No hay consumo registrado para los filtros seleccionados.</div>
        </div>
    </div>
</div>

<script>
    // URL del endpoint (sin querystring). El dashboard NO usa los filtros del módulo:
    // arma su propio querystring desde sus controles (desde/hasta/categoría).
    window.CONSUMO_DASH_URL = "{{ route('almacen.consumoDashboard') }}";

    // Instancias de Chart para destruirlas antes de re-renderizar (evita el error
    // "Canvas is already in use" al reabrir el modal o al cambiar un filtro).
    window._cdashCharts = window._cdashCharts || {};
    // El <select> de categoría se llena una sola vez (con lo que devuelve el endpoint).
    window._cdashCatsCargadas = false;
    // Las recomendaciones del filtro Descripción (nombres de producto) también se cargan
    // UNA sola vez: el modal pide la lista con con_productos=1 en el primer fetch y la cachea.
    window._cdashProdsCargados = false;
    // El <select> de "Frente de destino" del panel avanzado también se llena una sola vez.
    window._cdashFrentesCargados = false;

    // Formato de número estilo VE: miles con punto, decimales con coma. Sin decimales
    // si es entero (las unidades suelen serlo, pero soporta fraccionarios).
    window.cdashFmt = function (n) {
        n = Number(n) || 0;
        var dec = (n % 1 === 0) ? 0 : 2;
        return n.toLocaleString('es-VE', { minimumFractionDigits: dec, maximumFractionDigits: 2 });
    };

    // "2026-06" → "Jun 2026" (mes en palabra, no en número). Robusto si no llega bien formado.
    window.cdashMesLabel = function (ym) {
        var M = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        var p = String(ym || '').split('-');
        if (p.length < 2) return String(ym || '');
        var i = parseInt(p[1], 10) - 1;
        return (M[i] || p[1]) + ' ' + p[0];
    };

    window.cerrarConsumoDashboard = function () {
        var m = document.getElementById('consumoDashModal');
        if (m) m.classList.remove('open');
    };

    // Muestra/oculta el panel de Filtros Avanzados (rango de meses + frente de destino).
    window._cdashToggleAvanzados = function () {
        var panel = document.getElementById('cdashAvanzados');
        var btn = document.getElementById('cdashAdvBtn');
        if (!panel) return;
        var abrir = panel.style.display === 'none';
        panel.style.display = abrir ? 'block' : 'none';
        if (btn) btn.classList.toggle('active', abrir);
    };

    // Carga html2canvas bajo demanda y ejecuta el callback. Lo usan tanto la descarga
    // del dashboard completo como la de cada gráfico. La inyección la hace el cargador
    // compartido (js/maquinaria/lazy_loader.js), que ya garantiza una sola descarga.
    window._cdashConHtml2Canvas = function (cb) {
        {{-- ?v= obligatorio: nginx sirve /js con Cache-Control immutable, así que un
             asset sin versión se queda pegado en el navegador PARA SIEMPRE y una
             actualización de la librería no llegaría nunca. --}}
        window.cargarScriptUnaVez(
            "{{ asset('js/html2canvas.min.js') . '?v=' . @filemtime(public_path('js/html2canvas.min.js')) }}",
            function () { return typeof html2canvas !== 'undefined'; }
        ).then(cb).catch(function () {
            alert('No se pudo cargar la librería de captura. Revisa tu conexión.');
        });
    };

    // Captura un elemento del DOM y lo baja como PNG. Los botones de cámara se ocultan
    // en el CLON que html2canvas renderiza (onclone) — no en el DOM real: así la foto
    // sale limpia sin que la pantalla parpadee. Mismo patrón que descargarPanelHtmlFDM
    // (fleet_dashboard.js) y capturaPanelHtml (consumibles/graficos).
    window._cdashCapturarPng = function (elemento, nombre) {
        if (!elemento) return;
        window._cdashConHtml2Canvas(function () {
            html2canvas(elemento, {
                backgroundColor: '#ffffff', scale: 2, useCORS: true, logging: false,
                onclone: function (doc) {
                    doc.querySelectorAll('.cdash-chart-dl').forEach(function (b) { b.style.display = 'none'; });
                }
            }).then(function (canvas) {
                var a = document.createElement('a');
                a.href = canvas.toDataURL('image/png');
                a.download = (nombre || 'grafico') + '.png';
                document.body.appendChild(a); a.click(); a.remove();
            }).catch(function () {});
        });
    };

    // Descarga el dashboard entero (los tres gráficos) como una sola imagen PNG.
    window._cdashDescargarImagen = function () {
        var cont = document.getElementById('cdashContent');
        if (!cont || cont.style.display === 'none') return; // aún cargando o sin datos
        window._cdashCapturarPng(cont, 'dashboard-consumo');
    };

    // Descarga UN gráfico como PNG. Captura la TARJETA completa (título + gráfico +
    // fondo blanco con su borde), no solo el canvas: así la foto se entiende sola.
    // `btn` es el propio botón de cámara — de él se cuelga el .cdash-card contenedor.
    window._cdashDescargarGrafico = function (btn, nombre) {
        var card = btn && btn.closest ? btn.closest('.cdash-card') : null;
        window._cdashCapturarPng(card, nombre);
    };

    window.abrirConsumoDashboard = function () {
        var m = document.getElementById('consumoDashModal');
        if (!m) return;
        m.classList.add('open');
        window._cdashFetch();
    };

    // Lee los filtros PROPIOS del modal y pide los datos. Independiente del módulo.
    window._cdashFetch = function () {
        // Cierra las sugerencias de Descripción para que el spinner de carga quede
        // visible (mismo feedback que al filtrar por Categoría).
        if (window._cdashDescCloseSug) window._cdashDescCloseSug();
        var ldEl = document.getElementById('cdashLoading');
        ldEl.style.display = 'flex';
        ldEl.innerHTML = '<i class="material-icons cdash-spin">refresh</i><span>Cargando datos de consumo…</span>';
        document.getElementById('cdashContent').style.display = 'none';
        document.getElementById('cdashEmpty').style.display = 'none';

        var desde = (document.getElementById('cdashDesde') || {}).value || '';
        var hasta = (document.getElementById('cdashHasta') || {}).value || '';
        var cat    = (document.getElementById('cdashCategoria') || {}).value || '';
        var desc   = ((document.getElementById('cdashDescripcion') || {}).value || '').trim();
        var frente = (document.getElementById('cdashFrente') || {}).value || '';

        // Los <input type="month"> dan "YYYY-MM", pero el backend filtra por FECHA (día)
        // con whereDate. Si se manda el mes crudo, "<= YYYY-MM" se toma como YYYY-MM-00
        // y EXCLUYE todo el mes (el dashboard quedaba en 0 al elegir "Hasta"). Por eso
        // AMBOS se expanden igual: Desde → primer día del mes; Hasta → último día del mes.
        if (desde && desde.length === 7) desde = desde + '-01';
        if (hasta && hasta.length === 7) {
            var hp = hasta.split('-');
            var ultimoDia = new Date(parseInt(hp[0], 10), parseInt(hp[1], 10), 0).getDate();
            hasta = hasta + '-' + String(ultimoDia).padStart(2, '0');
        }

        var p = new URLSearchParams();
        if (desde) p.set('desde', desde);
        if (hasta) p.set('hasta', hasta);
        if (cat)    p.set('categoria', cat);
        if (desc)   p.set('descripcion', desc);
        if (frente) p.set('frente', frente);
        // Pide la lista de nombres para las recomendaciones SOLO la primera vez (luego se cachea).
        if (!window._cdashProdsCargados) p.set('con_productos', '1');
        var qs = p.toString();

        // Chart.js va EN PARALELO con el fetch: el modal ya no depende de que el layout
        // lo hubiera cargado en todas las páginas, lo pide al abrirse (idempotente, así
        // que reabrirlo no vuelve a descargar nada).
        var chartListo = window.ensureChartJS();

        Promise.all([
            window.apiFetch(window.CONSUMO_DASH_URL + (qs ? ('?' + qs) : ''), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); }),
            chartListo
        ])
            .then(function (res) { window._cdashRender(res[0]); })
            .catch(function () {
                var ldErr = document.getElementById('cdashLoading');
                ldErr.innerHTML = '<i class="material-icons" style="font-size:28px;color:#ef4444;">error_outline</i><span>No se pudo cargar el dashboard.</span>';
            });
    };

    // Su único llamador la invoca tras el Promise.all de arriba, así que aquí Chart ya
    // está cargado: sobra comprobarlo (si la carga falla, el .catch de allí es quien
    // avisa). Antes hacía falta porque dependía de que el layout lo hubiera traído.
    window._cdashRender = function (data) {

        if (!window._cdashCatsCargadas && Array.isArray(data.categorias)) {
            window._cdashCatsData = data.categorias;
            window._cdashCatsCargadas = true;
            window._cdashCatRenderList();
        }

        // Frentes de destino (panel avanzado): se llenan la primera vez que llegan.
        if (!window._cdashFrentesCargados && Array.isArray(data.frentes)) {
            window._cdashFrentesCargados = true;
            var selFr = document.getElementById('cdashFrente');
            if (selFr) {
                data.frentes.forEach(function (f) {
                    var o = document.createElement('option');
                    o.value = f.id; o.textContent = f.nombre;
                    selFr.appendChild(o);
                });
            }
        }

        // Recomendaciones del filtro Descripción: se cachean la primera vez que llegan.
        if (!window._cdashProdsCargados && Array.isArray(data.productos)) {
            window._cdashProdsData = data.productos;
            window._cdashProdsCargados = true;
        }

        var sinDatos = (!data.por_mes || !data.por_mes.length) &&
                       (!data.top_productos || !data.top_productos.length);
        document.getElementById('cdashLoading').style.display = 'none';
        if (sinDatos) {
            document.getElementById('cdashContent').style.display = 'none';
            document.getElementById('cdashEmpty').style.display = 'block';
            return;
        }
        document.getElementById('cdashEmpty').style.display = 'none';
        document.getElementById('cdashContent').style.display = 'block';

        // Destruir charts previos.
        Object.keys(window._cdashCharts).forEach(function (key) {
            if (window._cdashCharts[key]) { window._cdashCharts[key].destroy(); window._cdashCharts[key] = null; }
        });

        var fmt = window.cdashFmt;

        // chartjs-plugin-datalabels se registra GLOBALMENTE en Chart cuando el usuario pasa
        // por /admin/consumibles/graficos o por el dashboard de flota (Chart.register(...)).
        // Como la app es SPA, ese registro sobrevive a la navegación y este dashboard —que
        // pinta sus valores con el plugin propio cdValLabels— terminaba mostrando CADA
        // cantidad dos veces: la del plugin global dentro de la barra y la de cdValLabels
        // fuera. Apagarlo por gráfico es inofensivo si el plugin nunca llegó a cargarse.
        var CD_SIN_DATALABELS = { display: false };

        // Estilo COMÚN, formal y coherente (paleta corporativa azul, sin arcoíris).
        var cdTooltip = {
            backgroundColor: 'rgba(15,23,42,0.92)', titleColor: '#fff', bodyColor: '#e2e8f0',
            padding: 10, cornerRadius: 8, displayColors: false,
            titleFont: { weight: '700', size: 12 }, bodyFont: { size: 12 }
        };
        var cdGrid  = { color: 'rgba(148,163,184,0.18)', drawBorder: false, borderDash: [4, 4] };
        var cdTick  = { color: '#94a3b8', font: { size: 11 } };
        // Degradado vertical (claro arriba → marca abajo) para barras verticales.
        function cdVGrad(c, a, b) { var ar = c.chart.chartArea; if (!ar) return b; var g = c.chart.ctx.createLinearGradient(0, ar.top, 0, ar.bottom); g.addColorStop(0, a); g.addColorStop(1, b); return g; }
        // Degradado horizontal (marca izq → claro der) para barras horizontales.
        function cdHGrad(c, a, b) { var ar = c.chart.chartArea; if (!ar) return a; var g = c.chart.ctx.createLinearGradient(ar.left, 0, ar.right, 0); g.addColorStop(0, a); g.addColorStop(1, b); return g; }

        // Plugin: dibuja la CANTIDAD sobre cada barra/segmento (visible SIN pasar el mouse).
        // Soporta barras verticales (encima), horizontales (al final; dentro si la barra es
        // muy larga) y dona (en el centro del segmento).
        var cdValLabels = {
            id: 'cdValLabels',
            afterDatasetsDraw: function (chart) {
                var ctx = chart.ctx;
                var horizontal = chart.options.indexAxis === 'y';
                var isDoughnut = chart.config.type === 'doughnut';
                var area = chart.chartArea;
                chart.data.datasets.forEach(function (ds, di) {
                    chart.getDatasetMeta(di).data.forEach(function (el, i) {
                        var v = ds.data[i];
                        if (v == null || v === 0) return;
                        var txt = fmt(v);
                        ctx.save();
                        ctx.font = "700 11px 'Inter','Segoe UI',sans-serif";
                        if (isDoughnut) {
                            var p = el.tooltipPosition ? el.tooltipPosition() : { x: el.x, y: el.y };
                            ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                            ctx.fillText(txt, p.x, p.y);
                        } else if (horizontal) {
                            var dentro = area && el.x > area.left + (area.right - area.left) * 0.82;
                            ctx.textBaseline = 'middle';
                            if (dentro) { ctx.fillStyle = '#fff'; ctx.textAlign = 'right'; ctx.fillText(txt, el.x - 6, el.y); }
                            else { ctx.fillStyle = '#334155'; ctx.textAlign = 'left'; ctx.fillText(txt, el.x + 6, el.y); }
                        } else {
                            ctx.fillStyle = '#334155'; ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
                            ctx.fillText(txt, el.x, el.y - 4);
                        }
                        ctx.restore();
                    });
                });
            }
        };

        // ── 1) Consumo por mes (barras, azul con degradado) ──────────────────
        var mes = data.por_mes || [];
        window._cdashCharts.mes = new Chart(document.getElementById('cdashChartMes'), {
            type: 'bar',
            plugins: [cdValLabels],
            data: {
                labels: mes.map(function (x) { return window.cdashMesLabel(x.mes); }),
                datasets: [{ label: 'Consumo', data: mes.map(function (x) { return x.total; }),
                    backgroundColor: function (c) { return cdVGrad(c, '#38bdf8', '#0067b1'); },
                    hoverBackgroundColor: function (c) { return cdVGrad(c, '#0ea5e9', '#005a9e'); },
                    borderRadius: 6, borderSkipped: false, maxBarThickness: 44 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, datalabels: CD_SIN_DATALABELS, tooltip: Object.assign({}, cdTooltip, { callbacks: { label: function (c) { return fmt(c.parsed.y) + ' und'; } } }) },
                scales: {
                    x: { grid: { display: false, drawBorder: false }, ticks: cdTick },
                    y: { beginAtZero: true, grid: cdGrid, ticks: Object.assign({ callback: function (v) { return fmt(v); } }, cdTick) }
                }
            }
        });

        // ── 2) Top productos (barras horizontales, escala secuencial azul) ───
        // Azul corporativo un tono más oscuro que "Consumo por mes" (#005a9e en vez de
        // #0067b1): distingue los dos gráficos sin salirse de la paleta de la app.
        var top = data.top_productos || [];
        window._cdashCharts.top = new Chart(document.getElementById('cdashChartTop'), {
            type: 'bar',
            plugins: [cdValLabels],
            data: {
                // Rótulo = Nº DE PARTE principal (identifica el filtro exacto). Muchos filtros
                // comparten descripción, así que rotular por descripción se veía "combinado".
                // El total ya es por producto (ID_PRODUCTO), no por descripción.
                labels: top.map(function (x) { return x.parte || x.nombre; }),
                datasets: [{ label: 'Consumo', data: top.map(function (x) { return x.total; }),
                    backgroundColor: function (c) { return cdHGrad(c, '#005a9e', '#38bdf8'); },
                    {{-- El hover OSCURECE, igual que en "Consumo por mes". Con el degradado
                         invertido (hover más claro que la barra) los dos gráficos reaccionaban
                         al revés uno del otro. --}}
                    hoverBackgroundColor: function (c) { return cdHGrad(c, '#005a9e', '#0ea5e9'); },
                    borderRadius: 5, borderSkipped: false }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, datalabels: CD_SIN_DATALABELS, tooltip: Object.assign({}, cdTooltip, {
                    // Título en NEGRITA (titleFont weight 700): nombre + cantidad y unidad.
                    // El cuerpo (normal) lleva nº de parte y equipos, uno debajo del otro.
                    // La cantidad va SOLO en el título (no se repite en el cuerpo).
                    callbacks: {
                        title: function (items) {
                            var c = items[0] || {}; var d = top[c.dataIndex] || {};
                            return [ (d.nombre || c.label || ''), fmt(c.parsed.x) + '   ' + (d.um || 'UND') ];
                        },
                        label: function (c) {
                            var d = top[c.dataIndex] || {};
                            var out = [];
                            // Nº de parte: lista completa (el eje ya muestra el principal).
                            if (d.partes && d.partes.length) {
                                out.push('Nº de parte: ' + d.partes.slice(0, 6).join(' / ') +
                                         (d.partes.length > 6 ? ' …' : ''));
                            }
                            if (d.equipos && d.equipos.length) {
                                out.push('Equipos que lo usan:');
                                d.equipos.slice(0, 8).forEach(function (e) { out.push('•  ' + e); });
                                if (d.equipos.length > 8) out.push('… y ' + (d.equipos.length - 8) + ' más');
                            }
                            return out;
                        }
                    }
                }) },
                scales: {
                    x: { beginAtZero: true, grid: cdGrid, ticks: Object.assign({ callback: function (v) { return fmt(v); } }, cdTick) },
                    y: { grid: { display: false, drawBorder: false }, ticks: { color: '#475569', font: { size: 10 }, callback: function (v) { var l = this.getLabelForValue(v); return l.length > 28 ? l.slice(0, 28) + '…' : l; } } }
                }
            }
        });

        // ── 3) Consumo por almacén (dona con total al centro) ────────────────
        var alm = data.por_almacen || [];
        // Paleta CATEGÓRICA (un color por almacén, sin relación de orden). Arranca en el
        // azul corporativo. Todos en versión OSCURA (700/600) a propósito: cdValLabels
        // escribe la cantidad en BLANCO dentro del segmento, y sobre un ámbar o un verde
        // claro esa cifra no se leía. Con estos tonos el texto blanco contrasta en los ocho.
        var paleta = ['#0067b1', '#0f766e', '#4f46e5', '#b45309', '#db2777', '#15803d', '#c2410c', '#475569'];
        var almTotal = alm.reduce(function (s, x) { return s + (Number(x.total) || 0); }, 0);
        window._cdashCharts.alm = new Chart(document.getElementById('cdashChartAlm'), {
            type: 'doughnut',
            data: {
                labels: alm.map(function (x) { return x.nombre; }),
                datasets: [{ data: alm.map(function (x) { return x.total; }),
                    backgroundColor: alm.map(function (_, i) { return paleta[i % paleta.length]; }),
                    borderColor: '#fff', borderWidth: 2, hoverOffset: 6 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '64%',
                plugins: {
                    legend: { position: 'bottom', labels: { color: '#475569', font: { size: 11 }, boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 12 } },
                    datalabels: CD_SIN_DATALABELS,
                    tooltip: Object.assign({}, cdTooltip, { callbacks: { label: function (c) {
                        var pct = almTotal ? Math.round((c.parsed / almTotal) * 100) : 0;
                        return c.label + ': ' + fmt(c.parsed) + ' (' + pct + '%)';
                    } } })
                }
            },
            plugins: [cdValLabels, {
                id: 'cdashCenter',
                beforeDraw: function (chart) {
                    var ar = chart.chartArea; if (!ar) return;
                    var ctx = chart.ctx, cx = (ar.left + ar.right) / 2, cy = (ar.top + ar.bottom) / 2;
                    ctx.save(); ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.fillStyle = '#0f172a'; ctx.font = "800 20px 'Inter','Segoe UI',sans-serif"; ctx.fillText(fmt(almTotal), cx, cy - 6);
                    ctx.fillStyle = '#94a3b8'; ctx.font = "700 10px 'Inter','Segoe UI',sans-serif"; ctx.fillText('TOTAL', cx, cy + 12);
                    ctx.restore();
                }
            }]
        });
    };

    // ── Filtro Descripción (texto libre sobre el NOMBRE del producto) ──────────
    // Debounce: al escribir NO dispara en cada tecla; espera 350ms de pausa. Enter
    // consulta al instante (ver onkeydown). Comparte estética con Categoría (.cdash-inp-box).
    window._cdashDescTimer = null;
    window._cdashDescInput = function () {
        var inp = document.getElementById('cdashDescripcion');
        var val = inp ? inp.value.trim() : '';
        var clr = document.getElementById('cdashDescClear'); if (clr) clr.style.display = val ? 'block' : 'none';
        var box = document.getElementById('cdashDescBox'); if (box) box.classList.toggle('active', !!val);
        window._cdashDescRenderSug();   // recomendaciones en vivo (NO dispara fetch)
        clearTimeout(window._cdashDescTimer);
        window._cdashDescTimer = setTimeout(function () { window._cdashFetch(); }, 350);
    };
    window._cdashDescClear = function () {
        var inp = document.getElementById('cdashDescripcion'); if (inp) inp.value = '';
        var clr = document.getElementById('cdashDescClear'); if (clr) clr.style.display = 'none';
        var box = document.getElementById('cdashDescBox'); if (box) box.classList.remove('active');
        window._cdashDescCloseSug();
        clearTimeout(window._cdashDescTimer);
        window._cdashFetch();
    };

    // ── Recomendaciones del filtro Descripción (nombres de producto) ───────────
    // Mismo look que la lista de Categoría (.cdash-cat-list/.cdash-cat-item) y mismos
    // escapes (escHtml/escAttr, definidos más abajo). Los nombres se cargaron 1 vez en
    // _cdashProdsData. Solo filtran/rellenan el input — NO tocan el back más allá del fetch.
    window._cdashProdsData = window._cdashProdsData || [];
    window._cdashDescRenderSug = function () {
        var list = document.getElementById('cdashDescList'); if (!list) return;
        var inp = document.getElementById('cdashDescripcion');
        var q = (inp ? inp.value : '').trim().toLowerCase();
        if (!q) { list.classList.remove('open'); list.innerHTML = ''; return; }
        // Primero los que EMPIEZAN por lo escrito, luego los que lo CONTIENEN. Máx 12.
        var starts = [], contains = [];
        window._cdashProdsData.forEach(function (n) {
            var i = String(n).toLowerCase().indexOf(q);
            if (i === 0) starts.push(n); else if (i > 0) contains.push(n);
        });
        var res = starts.concat(contains).slice(0, 12);
        if (!res.length) { list.classList.remove('open'); list.innerHTML = ''; return; }
        list.innerHTML = res.map(function (n) {
            var safe = escAttr(n);
            return '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashDescSelectSug(\'' + safe + '\');">' + escHtml(n) + '</div>';
        }).join('');
        list.classList.add('open');
    };
    window._cdashDescSelectSug = function (name) {
        var inp = document.getElementById('cdashDescripcion'); if (inp) inp.value = name;
        var clr = document.getElementById('cdashDescClear'); if (clr) clr.style.display = 'block';
        var box = document.getElementById('cdashDescBox'); if (box) box.classList.add('active');
        window._cdashDescCloseSug();
        clearTimeout(window._cdashDescTimer);
        window._cdashFetch();
    };
    window._cdashDescCloseSug = function () {
        var list = document.getElementById('cdashDescList'); if (list) list.classList.remove('open');
    };

    window._cdashCatsData = window._cdashCatsData || [];
    // El nombre de categoría es texto libre editable desde el catálogo de productos: hay que
    // escaparlo en LOS DOS contextos donde se interpola, o una categoría llamada
    // `<img src=x onerror=...>` ejecuta al abrir la lista (XSS almacenado).
    //   escAttr → dentro de la cadena JS del onmousedown (comillas + < > & para cerrar el atributo).
    //   escHtml → como texto visible del <div>.
    // Ambos son los helpers centrales (dom_helpers.js): la pareja que estaba escrita aquí
    // se repetía casi igual en recepcion/index y _machinery.
    var escHtml = window.escapeHtml;
    var escAttr = window.escapeAttrJs;
    // Rótulo del campo cuando NO hay categoría elegida. Una sola definición: la comparten
    // la ✕, la opción "todas" de la lista y el respaldo de _cdashCatSelect — antes el mismo
    // literal estaba escrito en cuatro sitios.
    var CDASH_CAT_LBL = 'Categoría';
    window._cdashCatRenderList = function (filter) {
        var list = document.getElementById('cdashCatList'); if (!list) return;
        var q = (filter || '').toLowerCase();
        // La opción DICE "Todas las categorías" (describe qué hace al elegirla) pero deja el
        // campo rotulado con CDASH_CAT_LBL: el nombre del filtro, no su valor. Sin esto,
        // limpiar desde la lista devolvía el rótulo viejo y contradecía a la ✕.
        var html = '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'\',CDASH_CAT_LBL);">Todas las categorías</div>';
        window._cdashCatsData.forEach(function (c) {
            var s = String(c);
            if (q && s.toLowerCase().indexOf(q) === -1) return;
            var safe = escAttr(s);
            html += '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'' + safe + '\',\'' + safe + '\');">' + escHtml(s) + '</div>';
        });
        list.innerHTML = html;
    };
    window._cdashCatOpen = function () { var l = document.getElementById('cdashCatList'); if (l) { l.classList.add('open'); window._cdashCatRenderList(); } };
    window._cdashCatClose = function () { var l = document.getElementById('cdashCatList'); if (l) l.classList.remove('open'); };
    window._cdashCatFilter = function (v) { window._cdashCatRenderList(v); var l = document.getElementById('cdashCatList'); if (l) l.classList.add('open'); };
    window._cdashCatSelect = function (val, label) {
        var h = document.getElementById('cdashCategoria'); if (h) h.value = val;
        var inp = document.getElementById('cdashCatInput'); if (inp) { inp.value = ''; inp.placeholder = label || CDASH_CAT_LBL; }
        var box = document.getElementById('cdashCatBox'); if (box) box.classList.toggle('active', !!val);
        var clr = document.getElementById('cdashCatClear'); if (clr) clr.style.display = val ? 'block' : 'none';
        window._cdashCatClose();
        window._cdashFetch();
    };
</script>
