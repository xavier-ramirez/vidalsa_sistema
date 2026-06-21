{{-- ════════════════════════════════════════════════════════════════════════
     Modal "Dashboard de Consumo" — COMPARTIDO por /admin/almacen y
     /admin/almacen/movimientos (ambos lo incluyen y lo abren desde su botón
     Acciones con window.abrirConsumoDashboard()).

     INDEPENDIENTE de los filtros generales del módulo: tiene sus PROPIOS filtros
     (rango de meses Desde/Hasta + categoría). Datos: GET almacen.consumoDashboard
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
    .cdash-filtros label { font-size:10.5px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.4px; }
    .cdash-filtros input[type="month"] { height:36px; border:1px solid #cbd5e0; border-radius:8px; padding:0 10px; font-size:13px; color:#0f172a; background:#fff; outline:none; }
    .cdash-cat-wrap { position:relative; min-width:180px; }
    .cdash-cat-box { display:flex; align-items:center; height:36px; border:1px solid #cbd5e0; border-radius:8px; background:#fff; overflow:hidden; cursor:pointer; }
    .cdash-cat-box.active { border-color:var(--maquinaria-blue,#0067b1); background:#e1effa; }
    .cdash-cat-box input { flex:1; border:none; background:transparent; outline:none; padding:0 8px; font-size:13px; color:#0f172a; min-width:0; cursor:pointer; }
    .cdash-cat-box i.clr { padding:0 6px; color:#64748b; font-size:16px; cursor:pointer; }
    .cdash-cat-list { display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 10px 22px rgba(0,0,0,.12); max-height:220px; overflow-y:auto; padding:4px; z-index:20; }
    .cdash-cat-list.open { display:block; }
    .cdash-cat-item { padding:7px 10px; border-radius:6px; font-size:13px; font-weight:600; color:#1e293b; cursor:pointer; }
    .cdash-cat-item:hover { background:#f0f4f8; }
    .cdash-kpis { display:flex; gap:10px; margin-bottom:16px; }
    .cdash-kpi { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; min-width:0; flex:1; }
    .cdash-kpi .k-val { font-size:20px; font-weight:800; color:#0f172a; line-height:1.1; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .cdash-kpi .k-lbl { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.3px; margin-top:2px; }
    .cdash-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
    .cdash-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px; min-width:0; }
    .cdash-card.full { grid-column:1 / -1; }
    .cdash-card h4 { margin:0 0 10px 0; font-size:13px; font-weight:800; color:#334155; }
    .cdash-canvas-wrap { position:relative; height:240px; }
    /* Top productos: más alto para que entren las 20 barras legibles. */
    .cdash-canvas-wrap.tall { height:520px; }
    .cdash-empty { color:#94a3b8; font-size:13px; text-align:center; padding:40px 0; }
    .cdash-loading { text-align:center; color:#64748b; font-size:14px; padding:50px 0; font-weight:600; display:flex; flex-direction:column; align-items:center; gap:10px; }
    .cdash-loading .cdash-spin { animation:cdashSpin .8s linear infinite; font-size:28px; color:#0067b1; }
    @keyframes cdashSpin { 100% { transform:rotate(360deg); } }
    @media (max-width: 760px) {
        .cdash-kpis { grid-template-columns:1fr; }
        .cdash-grid { grid-template-columns:1fr; }
        .cdash-filtros .f-group, .cdash-filtros select, .cdash-filtros input { flex:1 1 100%; }
    }
</style>

<div id="consumoDashModal" class="cdash-overlay" onclick="if(event.target===this) window.cerrarConsumoDashboard()">
    <div class="cdash-modal">
        <div class="cdash-head">
            <h3><i class="material-icons">insights</i> Dashboard de Consumo</h3>
            <button type="button" class="cdash-x" onclick="window.cerrarConsumoDashboard()" aria-label="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div class="cdash-body">
            {{-- Filtros propios del dashboard: rango de meses + categoría. --}}
            <div class="cdash-filtros">
                <div class="f-group">
                    <label for="cdashDesde">Desde (mes)</label>
                    <input type="month" id="cdashDesde" onchange="window._cdashFetch()">
                </div>
                <div class="f-group">
                    <label for="cdashHasta">Hasta (mes)</label>
                    <input type="month" id="cdashHasta" onchange="window._cdashFetch()">
                </div>
                <div class="f-group">
                    <label>Categoría</label>
                    <div class="cdash-cat-wrap">
                        <input type="hidden" id="cdashCategoria" value="">
                        <div class="cdash-cat-box" id="cdashCatBox" onclick="window._cdashCatToggle()">
                            <i class="material-icons" style="padding:0 6px;color:#64748b;font-size:16px;">search</i>
                            <input type="text" id="cdashCatInput" placeholder="Todas las categorías" autocomplete="off"
                                   oninput="window._cdashCatFilter(this.value)"
                                   onfocus="window._cdashCatOpen()"
                                   onblur="setTimeout(function(){window._cdashCatClose()},180)">
                            <i class="material-icons clr" id="cdashCatClear" style="display:none;" onmousedown="event.preventDefault();window._cdashCatSelect('','Todas las categorías');">close</i>
                        </div>
                        <div class="cdash-cat-list" id="cdashCatList"></div>
                    </div>
                </div>
            </div>

            <div id="cdashLoading" class="cdash-loading"><i class="material-icons cdash-spin">refresh</i><span>Cargando datos de consumo…</span></div>
            <div id="cdashContent" style="display:none;">
                <div class="cdash-kpis">
                    <div class="cdash-kpi"><div class="k-val" id="cdashKpiUnidades">0</div><div class="k-lbl">Unidades consumidas</div></div>
                    <div class="cdash-kpi"><div class="k-val" id="cdashKpiMovs">0</div><div class="k-lbl">Movimientos de salida</div></div>
                    <div class="cdash-kpi"><div class="k-val" id="cdashKpiProductos">0</div><div class="k-lbl">Productos distintos</div></div>
                </div>
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

        var k = data.kpis || {};
        document.getElementById('cdashKpiUnidades').textContent  = window.cdashFmt(k.total_unidades);
        document.getElementById('cdashKpiMovs').textContent       = window.cdashFmt(k.total_movimientos);
        document.getElementById('cdashKpiProductos').textContent  = window.cdashFmt(k.productos_distintos);

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
        var mesColores = ['#0067b1','#0ea5e9','#0891b2','#0d9488','#059669','#16a34a','#65a30d','#ca8a04','#ea580c','#dc2626','#e11d48','#9333ea'];
        var topColores = ['#0067b1','#0ea5e9','#22c55e','#16a34a','#0d9488','#0891b2','#6366f1','#8b5cf6','#a855f7','#ec4899','#f43f5e','#ef4444','#f97316','#f59e0b','#eab308','#84cc16','#14b8a6','#06b6d4','#3b82f6','#6d28d9'];

        // ── 1) Consumo por mes (barras) ──────────────────────────────────────
        var mes = data.por_mes || [];
        window._cdashCharts.mes = new Chart(document.getElementById('cdashChartMes'), {
            type: 'bar',
            data: {
                labels: mes.map(function (x) { return x.mes; }),
                datasets: [{ label: 'Consumo', data: mes.map(function (x) { return x.total; }),
                    backgroundColor: mes.map(function (_, i) { return mesColores[i % mesColores.length]; }), borderRadius: 6, maxBarThickness: 46 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return fmt(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return fmt(v); } } } }
            }
        });

        // ── 2) Top productos (barra horizontal) ──────────────────────────────
        var top = data.top_productos || [];
        window._cdashCharts.top = new Chart(document.getElementById('cdashChartTop'), {
            type: 'bar',
            data: {
                labels: top.map(function (x) { return x.nombre; }),
                datasets: [{ label: 'Consumo', data: top.map(function (x) { return x.total; }),
                    backgroundColor: top.map(function (_, i) { return topColores[i % topColores.length]; }), borderRadius: 5 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return fmt(c.parsed.x); } } } },
                scales: { x: { beginAtZero: true, ticks: { callback: function (v) { return fmt(v); } } },
                          y: { ticks: { font: { size: 10 }, callback: function (v) { var l = this.getLabelForValue(v); return l.length > 28 ? l.slice(0, 28) + '…' : l; } } } }
            }
        });

        // ── 3) Consumo por almacén (dona) ────────────────────────────────────
        var alm = data.por_almacen || [];
        var paleta = ['#0067b1', '#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6', '#14b8a6', '#ec4899'];
        window._cdashCharts.alm = new Chart(document.getElementById('cdashChartAlm'), {
            type: 'doughnut',
            data: {
                labels: alm.map(function (x) { return x.nombre; }),
                datasets: [{ data: alm.map(function (x) { return x.total; }),
                    backgroundColor: alm.map(function (_, i) { return paleta[i % paleta.length]; }), borderWidth: 0 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 12 } },
                           tooltip: { callbacks: { label: function (c) { return c.label + ': ' + fmt(c.parsed); } } } }
            }
        });
    };

    window._cdashCatsData = window._cdashCatsData || [];
    window._cdashCatRenderList = function (filter) {
        var list = document.getElementById('cdashCatList'); if (!list) return;
        var q = (filter || '').toLowerCase();
        var html = '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'\',\'Todas las categorías\');">Todas las categorías</div>';
        window._cdashCatsData.forEach(function (c) {
            var s = String(c);
            if (q && s.toLowerCase().indexOf(q) === -1) return;
            var safe = s.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            html += '<div class="cdash-cat-item" onmousedown="event.preventDefault();window._cdashCatSelect(\'' + safe + '\',\'' + safe + '\');">' + s + '</div>';
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
