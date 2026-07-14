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
    .cdash-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:16px 20px; background:#fff; border-bottom:1px solid #e2e8f0; }
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
    .cdash-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .cdash-card { background:#fff; border:1px solid #e9eef5; border-radius:14px; padding:16px 18px; min-width:0;
        box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .cdash-card.full { grid-column:1 / -1; }
    .cdash-card h4 { margin:0 0 14px 0; font-size:13px; font-weight:800; color:#1e293b; display:flex; align-items:center; gap:8px; letter-spacing:.2px; }
    .cdash-card h4::before { content:''; width:4px; height:15px; border-radius:3px; background:linear-gradient(180deg,#0ea5e9,#0067b1); flex:0 0 auto; }
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
        .cdash-filtros .f-group-desc,
        .cdash-filtros .f-group-cat { flex:1 1 100%; }
        .cdash-filtros input[type="month"] { width:100%; min-width:0; font-size:12px; padding:0 6px; }
        .cdash-filtros input[type="month"]::-webkit-calendar-picker-indicator { display:none; }
    }
</style>

<div id="consumoDashModal" class="cdash-overlay" onclick="if(event.target===this) window.cerrarConsumoDashboard()">
    <div class="cdash-modal">
        <div class="cdash-head">
            <h3><i class="material-icons">insights</i> Dashboard de Consumo</h3>
            <button type="button" class="cdash-x" onclick="window.cerrarConsumoDashboard()" aria-label="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="cdash-body">
            {{-- Filtros propios del dashboard. Orden: Descripción primero (filtro principal),
                 luego Categoría (ancho reducido) y el rango de meses Desde/Hasta a la derecha.
                 Sin títulos: cada control se identifica por su placeholder/valor. --}}
            <div class="cdash-filtros">
                <div class="f-group f-group-desc">
                    <div class="cdash-inp-box" id="cdashDescBox">
                        <i class="material-icons">search</i>
                        <input type="text" id="cdashDescripcion" placeholder="Descripción del producto…" autocomplete="off"
                               oninput="window._cdashDescInput()"
                               onkeydown="if(event.key==='Enter'){event.preventDefault();clearTimeout(window._cdashDescTimer);window._cdashFetch();}">
                        <i class="material-icons clr" id="cdashDescClear" style="display:none;" onclick="window._cdashDescClear()">close</i>
                    </div>
                </div>
                <div class="f-group f-group-cat">
                    <div class="cdash-cat-wrap">
                        <input type="hidden" id="cdashCategoria" value="">
                        <div class="cdash-inp-box cdash-cat-box" id="cdashCatBox" onclick="window._cdashCatToggle()">
                            <i class="material-icons">search</i>
                            <input type="text" id="cdashCatInput" placeholder="Todas las categorías" autocomplete="off"
                                   oninput="window._cdashCatFilter(this.value)"
                                   onfocus="window._cdashCatOpen()"
                                   onblur="setTimeout(function(){window._cdashCatClose()},180)">
                            <i class="material-icons clr" id="cdashCatClear" style="display:none;" onmousedown="event.preventDefault();window._cdashCatSelect('','Todas las categorías');">close</i>
                        </div>
                        <div class="cdash-cat-list" id="cdashCatList"></div>
                    </div>
                </div>
                <div class="f-group">
                    <input type="month" id="cdashDesde" title="Desde (mes)" onchange="window._cdashFetch()"
                           onclick="try{ this.showPicker(); }catch(e){}">
                </div>
                <div class="f-group">
                    <input type="month" id="cdashHasta" title="Hasta (mes)" onchange="window._cdashFetch()"
                           onclick="try{ this.showPicker(); }catch(e){}">
                </div>
            </div>

            <div id="cdashLoading" class="cdash-loading"><i class="material-icons cdash-spin">refresh</i><span>Cargando datos de consumo…</span></div>
            <div id="cdashContent" style="display:none;">
                <div class="cdash-grid">
                    <div class="cdash-card full"><h4>Consumo por mes</h4><div class="cdash-canvas-wrap"><canvas id="cdashChartMes"></canvas></div></div>
                    <div class="cdash-card full"><h4>Top 20 productos consumidos</h4><div class="cdash-canvas-wrap tall"><canvas id="cdashChartTop"></canvas></div></div>
                    <div class="cdash-card full"><h4>Consumo por almacén</h4><div class="cdash-canvas-wrap"><canvas id="cdashChartAlm"></canvas></div></div>
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

    // Formato de número estilo VE: miles con punto, decimales con coma. Sin decimales
    // si es entero (las unidades suelen serlo, pero soporta fraccionarios).
    window.cdashFmt = function (n) {
        n = Number(n) || 0;
        var dec = (n % 1 === 0) ? 0 : 2;
        return n.toLocaleString('es-VE', { minimumFractionDigits: dec, maximumFractionDigits: 2 });
    };

    window.cerrarConsumoDashboard = function () {
        var m = document.getElementById('consumoDashModal');
        if (m) m.classList.remove('open');
    };

    window.abrirConsumoDashboard = function () {
        var m = document.getElementById('consumoDashModal');
        if (!m) return;
        m.classList.add('open');
        window._cdashFetch();
    };

    // Lee los filtros PROPIOS del modal y pide los datos. Independiente del módulo.
    window._cdashFetch = function () {
        var ldEl = document.getElementById('cdashLoading');
        ldEl.style.display = 'flex';
        ldEl.innerHTML = '<i class="material-icons cdash-spin">refresh</i><span>Cargando datos de consumo…</span>';
        document.getElementById('cdashContent').style.display = 'none';
        document.getElementById('cdashEmpty').style.display = 'none';

        var desde = (document.getElementById('cdashDesde') || {}).value || '';
        var hasta = (document.getElementById('cdashHasta') || {}).value || '';
        var cat   = (document.getElementById('cdashCategoria') || {}).value || '';
        var desc  = ((document.getElementById('cdashDescripcion') || {}).value || '').trim();

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
        if (cat)   p.set('categoria', cat);
        if (desc)  p.set('descripcion', desc);
        var qs = p.toString();

        fetch(window.CONSUMO_DASH_URL + (qs ? ('?' + qs) : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) { window._cdashRender(data); })
            .catch(function () {
                var ldErr = document.getElementById('cdashLoading');
                ldErr.innerHTML = '<i class="material-icons" style="font-size:28px;color:#ef4444;">error_outline</i><span>No se pudo cargar el dashboard.</span>';
            });
    };

    window._cdashRender = function (data) {
        if (typeof Chart === 'undefined') {
            document.getElementById('cdashLoading').textContent = 'Chart.js no está disponible.';
            return;
        }

        if (!window._cdashCatsCargadas && Array.isArray(data.categorias)) {
            window._cdashCatsData = data.categorias;
            window._cdashCatsCargadas = true;
            window._cdashCatRenderList();
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

        // Estilo COMÚN, formal y coherente (paleta corporativa azul/teal, sin arcoíris).
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

        // ── 1) Consumo por mes (barras, azul con degradado) ──────────────────
        var mes = data.por_mes || [];
        window._cdashCharts.mes = new Chart(document.getElementById('cdashChartMes'), {
            type: 'bar',
            data: {
                labels: mes.map(function (x) { return x.mes; }),
                datasets: [{ label: 'Consumo', data: mes.map(function (x) { return x.total; }),
                    backgroundColor: function (c) { return cdVGrad(c, '#38bdf8', '#0067b1'); },
                    hoverBackgroundColor: function (c) { return cdVGrad(c, '#0ea5e9', '#005a9e'); },
                    borderRadius: 6, borderSkipped: false, maxBarThickness: 44 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: Object.assign({}, cdTooltip, { callbacks: { label: function (c) { return fmt(c.parsed.y) + ' und'; } } }) },
                scales: {
                    x: { grid: { display: false, drawBorder: false }, ticks: cdTick },
                    y: { beginAtZero: true, grid: cdGrid, ticks: Object.assign({ callback: function (v) { return fmt(v); } }, cdTick) }
                }
            }
        });

        // ── 2) Top productos (barras horizontales, escala secuencial azul) ───
        var top = data.top_productos || [];
        window._cdashCharts.top = new Chart(document.getElementById('cdashChartTop'), {
            type: 'bar',
            data: {
                labels: top.map(function (x) { return x.nombre; }),
                datasets: [{ label: 'Consumo', data: top.map(function (x) { return x.total; }),
                    backgroundColor: function (c) { return cdHGrad(c, '#0067b1', '#7dd3fc'); },
                    hoverBackgroundColor: function (c) { return cdHGrad(c, '#005a9e', '#38bdf8'); },
                    borderRadius: 5, borderSkipped: false }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: Object.assign({}, cdTooltip, { callbacks: { label: function (c) { return fmt(c.parsed.x) + ' und'; } } }) },
                scales: {
                    x: { beginAtZero: true, grid: cdGrid, ticks: Object.assign({ callback: function (v) { return fmt(v); } }, cdTick) },
                    y: { grid: { display: false, drawBorder: false }, ticks: { color: '#475569', font: { size: 10 }, callback: function (v) { var l = this.getLabelForValue(v); return l.length > 28 ? l.slice(0, 28) + '…' : l; } } }
                }
            }
        });

        // ── 3) Consumo por almacén (dona con total al centro) ────────────────
        var alm = data.por_almacen || [];
        var paleta = ['#0067b1', '#0ea5e9', '#14b8a6', '#6366f1', '#f59e0b', '#ec4899', '#22c55e', '#64748b'];
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
                    tooltip: Object.assign({}, cdTooltip, { callbacks: { label: function (c) {
                        var pct = almTotal ? Math.round((c.parsed / almTotal) * 100) : 0;
                        return c.label + ': ' + fmt(c.parsed) + ' (' + pct + '%)';
                    } } })
                }
            },
            plugins: [{
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
        clearTimeout(window._cdashDescTimer);
        window._cdashDescTimer = setTimeout(function () { window._cdashFetch(); }, 350);
    };
    window._cdashDescClear = function () {
        var inp = document.getElementById('cdashDescripcion'); if (inp) inp.value = '';
        var clr = document.getElementById('cdashDescClear'); if (clr) clr.style.display = 'none';
        var box = document.getElementById('cdashDescBox'); if (box) box.classList.remove('active');
        clearTimeout(window._cdashDescTimer);
        window._cdashFetch();
    };

    window._cdashCatsData = window._cdashCatsData || [];
    // El nombre de categoría es texto libre editable desde el catálogo de productos: hay que
    // escaparlo en LOS DOS contextos donde se interpola, o una categoría llamada
    // `<img src=x onerror=...>` ejecuta al abrir la lista (XSS almacenado).
    //   escAttr → dentro de la cadena JS del onmousedown (comillas + < > & para cerrar el atributo).
    //   escHtml → como texto visible del <div>.
    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escAttr(s) { return escHtml(String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'")); }
    window._cdashCatRenderList = function (filter) {
        var list = document.getElementById('cdashCatList'); if (!list) return;
        var q = (filter || '').toLowerCase();
        var html = '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'\',\'Todas las categorías\');">Todas las categorías</div>';
        window._cdashCatsData.forEach(function (c) {
            var s = String(c);
            if (q && s.toLowerCase().indexOf(q) === -1) return;
            var safe = escAttr(s);
            html += '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'' + safe + '\',\'' + safe + '\');">' + escHtml(s) + '</div>';
        });
        list.innerHTML = html;
    };
    window._cdashCatToggle = function () { var l = document.getElementById('cdashCatList'); if (l) { l.classList.toggle('open'); if (l.classList.contains('open')) window._cdashCatRenderList(); } };
    window._cdashCatOpen = function () { var l = document.getElementById('cdashCatList'); if (l) { l.classList.add('open'); window._cdashCatRenderList(); } };
    window._cdashCatClose = function () { var l = document.getElementById('cdashCatList'); if (l) l.classList.remove('open'); };
    window._cdashCatFilter = function (v) { window._cdashCatRenderList(v); var l = document.getElementById('cdashCatList'); if (l) l.classList.add('open'); };
    window._cdashCatSelect = function (val, label) {
        var h = document.getElementById('cdashCategoria'); if (h) h.value = val;
        var inp = document.getElementById('cdashCatInput'); if (inp) { inp.value = ''; inp.placeholder = label || 'Todas las categorías'; }
        var box = document.getElementById('cdashCatBox'); if (box) box.classList.toggle('active', !!val);
        var clr = document.getElementById('cdashCatClear'); if (clr) clr.style.display = val ? 'block' : 'none';
        window._cdashCatClose();
        window._cdashFetch();
    };
</script>
