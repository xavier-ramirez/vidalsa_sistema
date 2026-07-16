// Fleet Dashboard Modal Manager - Filtered by Frente
// Uses Chart.js for visualizations + DataLabels Plugin

// SPA-safe globals
if (!window.fleetCharts) window.fleetCharts = {};
if (!window.currentFrenteId) window.currentFrenteId = '';

if (!window.CHART_COLORS) {
    window.CHART_COLORS = {
        // 'status' (doughnut Estado Operativo) e 'inoperative' (Inoperatividad por Tipo)
        // se eliminaron junto con esos gráficos. 'age' lo usan Flota por Tipo y Auxiliares.
        age: ['#110a50ff', '#a31616ff']
    };
}

// Shared professional legend style
const LEGEND_STYLE = {
    position: 'bottom',
    labels: {
        padding: 18,
        font: { size: 12, weight: '600', family: "'Inter', 'Segoe UI', sans-serif" },
        boxWidth: 12,
        boxHeight: 12,
        color: '#374151',
        usePointStyle: true,
        pointStyle: 'rectRounded'
    }
};

// Common tooltip styles
const TOOLTIP_STYLES = {
    backgroundColor: '#1e293b',
    titleColor: '#ffffff',
    bodyColor: '#e2e8f0',
    borderColor: '#334155',
    borderWidth: 1,
    padding: 10,
    cornerRadius: 8,
    displayColors: true,
    boxWidth: 10,
    boxHeight: 10
};

/**
 * Update stat cards with data
 */
function updateStatCards(stats) {
    const total = document.getElementById('stat_total');
    const fleetNew = document.getElementById('stat_fleet_new');
    const fleetOld = document.getElementById('stat_fleet_old');
    const consumption = document.getElementById('stat_consumption');

    if (total) total.textContent = stats.total || 0;
    if (fleetNew) fleetNew.textContent = stats.fleet_new || 0;
    if (fleetOld) fleetOld.textContent = stats.fleet_old || 0;
    if (consumption) consumption.textContent = stats.total_consumption || 0;
}

/**
 * Render Equipos Asignados por Frente panel (cajitas estilo consumibles)
 */
function renderFleetEquiposAsignados(lista) {
    const loading = document.getElementById('fleetEqAsigLoading');
    const body = document.getElementById('fleetEqAsigBody');
    if (!body) return;

    if (loading) loading.style.display = 'none';
    body.style.display = 'block';

    if (!lista || lista.length === 0) {
        body.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px;">Sin datos de equipos asignados.</p>';
        return;
    }

    // Ordenar de mayor a menor por cantidad de equipos asignados
    lista = [...lista].sort((a, b) => (Number(b.total) || 0) - (Number(a.total) || 0));

    const COLOR = '#475569'; // gris corporativo fijo

    body.innerHTML = `<div style="display:flex;flex-wrap:wrap;gap:10px;">${lista.map((row, i) => `
            <div style="
                background:${COLOR};
                color:#fff;
                border-radius:12px;
                padding:12px 16px;
                min-width:180px;
                flex:1;
                display:flex;
                flex-direction:column;
                align-items:flex-start;
                justify-content:center;
                gap:8px;
                box-shadow:0 2px 8px rgba(0,0,0,.15);
            ">
                <div style="display:flex; align-items:center; gap:8px; width:100%;">
                    <span style="font-size:12px;font-weight:700;color:#fff;opacity:0.85;flex-shrink:0;">#${i + 1}</span>
                    <span style="font-size:12px;font-weight:700;line-height:1.2;word-break:break-word;flex:1;" title="${row.frente}">${row.frente}</span>
                </div>
                <div style="display:flex;align-items:baseline;gap:5px;">
                    <span style="font-size:26px;font-weight:900;line-height:1;">${row.total}</span>
                    <span style="font-size:13px;font-weight:600;opacity:.85;">equipo${row.total !== 1 ? 's' : ''}</span>
                </div>
            </div>`
    ).join('')
        }</div>`;
}

/**
 * Open Fleet Dashboard Modal
 */
window.openFleetDashboard = async function () {
    const modal = document.getElementById('fleetDashboardModal');
    if (!modal) return;

    modal.classList.add('active');
    modal.style.display = 'flex';

    // Dispara la carga de Chart.js (+DataLabels) en paralelo con el fetch.
    // loadFleetDashboardData consulta _fleetChartReady antes de instanciar
    // `new Chart(...)`, asi el fetch avanza mientras Chart.js se descarga.
    // SIEMPRE llamamos loadChartJS(): aunque Chart ya venga del layout (chart.umd
    // estatico), el plugin DataLabels NO se carga ahi y hay que cargarlo+registrarlo
    // para que los valores salgan dentro de cada barra. loadChartJS es idempotente
    // (loadScriptOnce no re-inyecta Chart si ya existe).
    window._fleetChartReady = loadChartJS();
    const chartReady = window._fleetChartReady;

    setupDropdownEvents();

    // ÔöÇÔöÇ Leer frente con prioridades claras ÔöÇÔöÇ
    // Prioridad 1: Filtro activo en la p├ígina (?id_frente=16) ÔÇö aplica para TODOS
    // Prioridad 2: Campo oculto inyectado por el servidor (Blade) ÔÇö cubre usuarios locales
    const hiddenId   = document.getElementById('dashboardSelectedFrenteId');
    const hiddenName = document.getElementById('dashboardSelectedFrenteNombre');
    const isGlobalUser = !!document.getElementById('dashboardFrenteSearch');

    // Leer el filtro activo en la URL de la p├ígina
    const pageFilterInput = document.querySelector('input[name="id_frente"][data-filter-value]');
    const activeFrenteId  = (pageFilterInput && pageFilterInput.value && pageFilterInput.value !== 'all')
        ? pageFilterInput.value : '';

    let firstFrenteId   = '';
    let firstFrenteName = '';

    if (activeFrenteId) {
        // Prioridad 1: Filtro activo en la p├ígina ÔÇö igual para LOCAL y GLOBAL
        firstFrenteId = activeFrenteId;

        // Intentar resolver el nombre desde el dropdown visible
        const selectedOption = document.querySelector(
            `#frenteFilterSelect .dropdown-item[data-value="${activeFrenteId}"]`
        );
        firstFrenteName = selectedOption ? selectedOption.textContent.trim() : (hiddenName?.value || '');

        // Actualizar los campos ocultos para que exportFleetStats tambi├®n use el correcto
        if (hiddenId)   hiddenId.value   = firstFrenteId;
        if (hiddenName) hiddenName.value = firstFrenteName;
    } else {
        // Prioridad 2: Valor pre-inyectado por el servidor (el Blade ya calcul├│ el mejor frente)
        firstFrenteId   = hiddenId?.value   || '';
        firstFrenteName = hiddenName?.value || '';
    }

    const searchInput = document.getElementById('dashboardFrenteSearch');
    if (searchInput) {
        searchInput.value = firstFrenteName;
        dashboardToggleClearBtn();
    }

    window.currentFrenteId = firstFrenteId;

    // Ejecutar FETCH y CARGA DE CHART EN PARALELO. loadFleetDashboardData ya
    // hace fetch a /fleet-stats; mientras llega la respuesta, Chart.js tambien
    // se esta bajando. Al terminar ambos, los charts se renderizan al instante.
    await Promise.all([
        chartReady,
        loadFleetDashboardData(firstFrenteId)
    ]);
};

/**
 * Export Fleet Statistics to Excel (CSV/XLSX)
 */
window.exportFleetStats = function () {
    const frenteId = window.currentFrenteId || document.getElementById('dashboardSelectedFrenteId')?.value;
    const url = new URL('/admin/equipos/fleet-export', window.location.origin);
    if (frenteId && frenteId !== 'all') {
        url.searchParams.set('frente_id', frenteId);
    }
    
    if (window.showPreloader) window.showPreloader();

    fetch(url, { method: 'GET' })
        .then(response => {
            if (!response.ok) throw new Error('Error al generar el archivo');
            return response.blob();
        })
        .then(blob => {
            if (window.hidePreloader) window.hidePreloader();
            const downloadUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = downloadUrl;
            
            // Generate filename based on actual local time or static string
            const dateStr = new Date().toISOString().slice(0,16).replace(/T|:/g, '-');
            a.download = `analisis_flota_${dateStr}.xlsx`;
            
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(downloadUrl);
            document.body.removeChild(a);
            
            if (window.showToast) window.showToast('Descarga completada', 'success');
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            console.error('Export Error:', err);
            if (window.showToast) window.showToast('Ocurrió un error al generar el archivo.', 'error');
            else alert('Ocurrió un error al generar el archivo.');
        });
};

/**
 * Setup Dropdown Events (Close when clicking outside) ÔÇö runs only once
 */
if (typeof window.dropdownEventsInitialized === 'undefined') window.dropdownEventsInitialized = false;

function setupDropdownEvents() {
    if (window.dropdownEventsInitialized) return;

    const container = document.getElementById('dashboardFrenteDropdown');
    if (!container) return;

    document.addEventListener('click', function (event) {
        const dropdown = document.getElementById('dashboardFrenteList');
        if (dropdown && !container.contains(event.target)) {
            dropdown.style.display = 'none';
        }
    });

    window.dropdownEventsInitialized = true;
}

/**
 * Toggle visibility of the X clear button
 */
window.dashboardToggleClearBtn = function () {
    const input = document.getElementById('dashboardFrenteSearch');
    const clearBtn = document.getElementById('dashboardFrenteClearBtn');
    if (!input || !clearBtn) return;
    clearBtn.style.display = input.value.trim() !== '' ? 'inline-flex' : 'none';
};

/**
 * Clear the frente search input ÔÇö NO data reload (just clears the field)
 */
window.dashboardClearFrenteSearch = function () {
    const input = document.getElementById('dashboardFrenteSearch');
    const clearBtn = document.getElementById('dashboardFrenteClearBtn');
    const dropdown = document.getElementById('dashboardFrenteList');

    if (input) {
        input.value = '';
        input.focus();
    }
    if (clearBtn) clearBtn.style.display = 'none';

    // Restore all dropdown options visibility
    if (dropdown) {
        const options = dropdown.getElementsByClassName('dashboard-frente-option');
        for (let i = 0; i < options.length; i++) {
            options[i].style.display = '';
        }
        dropdown.style.display = 'block';
    }
    // NOTE: intentionally NOT calling loadFleetDashboardData here
};

/**
 * Toggle Dropdown Visibility
 */
window.dashboardToggleFrente = function (event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const dropdown = document.getElementById('dashboardFrenteList');
    if (dropdown) {
        const isHidden = (dropdown.style.display === 'none' || dropdown.style.display === '');
        dropdown.style.display = isHidden ? 'block' : 'none';

        if (isHidden) {
            const search = document.getElementById('dashboardFrenteSearch');
            if (search) setTimeout(() => search.focus(), 100);
        }
    }
};

/**
 * Filter Frentes List by typed text
 */
window.dashboardFilterFrentes = function () {
    const input = document.getElementById('dashboardFrenteSearch');
    const dropdown = document.getElementById('dashboardFrenteList');
    if (!input || !dropdown) return;

    const filter = input.value.toUpperCase();
    const options = dropdown.getElementsByClassName('dashboard-frente-option');

    for (let i = 0; i < options.length; i++) {
        const txt = options[i].textContent || options[i].innerText;
        options[i].style.display = txt.toUpperCase().includes(filter) ? '' : 'none';
    }
    dropdown.style.display = 'block';
};

/**
 * Select a Frente from the dropdown
 */
window.dashboardSelectFrente = async function (id, name, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }

    const hiddenId = document.getElementById('dashboardSelectedFrenteId');
    if (hiddenId) hiddenId.value = id;

    const search = document.getElementById('dashboardFrenteSearch');
    if (search) {
        search.value = name;
        dashboardToggleClearBtn();
    }

    const list = document.getElementById('dashboardFrenteList');
    if (list) list.style.display = 'none';

    window.currentFrenteId = id;
    await loadFleetDashboardData(id);
};

/**
 * Close Fleet Dashboard Modal
 */
window.closeFleetDashboard = function () {
    const modal = document.getElementById('fleetDashboardModal');
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
    destroyAllCharts();
};

/**
 * Helper: carga un <script> externo una sola vez. Devuelve la misma Promise
 * si ya se estaba cargando (evita duplicados en SPA re-entries).
 */
function loadScriptOnce(src, testLoaded) {
    if (testLoaded && testLoaded()) return Promise.resolve();
    if (!window._fleetScriptCache) window._fleetScriptCache = {};
    if (window._fleetScriptCache[src]) return window._fleetScriptCache[src];

    const p = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.onload = () => resolve();
        s.onerror = () => reject(new Error('Failed to load ' + src));
        document.head.appendChild(s);
    });
    window._fleetScriptCache[src] = p;
    return p;
}

/**
 * Load Chart.js + DataLabels plugin EN PARALELO.
 * html2canvas NO se carga aqui: es on-demand desde exportFleetStats (solo se
 * necesita al exportar, no para el render inicial del dashboard).
 */
async function loadChartJS() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';
    const chartLoaded  = () => typeof Chart !== 'undefined';
    const labelsLoaded = () => typeof ChartDataLabels !== 'undefined';

    // Core Chart.js primero (DataLabels lo necesita disponible para registrarse)
    await loadScriptOnce(baseUrl + '/js/chart.umd.min.js', chartLoaded);

    // DataLabels plugin — si falla, charts funcionan sin labels
    try {
        await loadScriptOnce(baseUrl + '/js/chartjs-plugin-datalabels.min.js', labelsLoaded);
        if (typeof ChartDataLabels !== 'undefined' && typeof Chart !== 'undefined') {
            const _alreadyReg = Chart.registry?.plugins?.items &&
                Object.values(Chart.registry.plugins.items).some(p => p.id === 'datalabels');
            if (!_alreadyReg) Chart.register(ChartDataLabels);
        }
    } catch (e) {
        console.warn('DataLabels plugin no cargo, charts continuan sin etiquetas:', e.message);
    }
}

/**
 * Lazy-load html2canvas — solo cuando se pulsa Exportar.
 */
async function ensureHtml2Canvas() {
    const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';
    try {
        await loadScriptOnce(baseUrl + '/js/html2canvas.min.js', () => typeof html2canvas !== 'undefined');
    } catch (e) {
        console.warn('html2canvas no cargo, downloads podrian fallar:', e.message);
    }
}

/**
 * Fetch fleet statistics from backend
 */
async function loadFleetDashboardData(frenteId) {
    const spinner = document.getElementById('fleetDashboardSpinner');

    try {
        if (spinner) spinner.style.display = 'flex';

        // Reset equipos panel to loading state
        const eqLoading = document.getElementById('fleetEqAsigLoading');
        const eqBody = document.getElementById('fleetEqAsigBody');
        if (eqLoading) eqLoading.style.display = 'flex';
        if (eqBody) eqBody.style.display = 'none';

        const url = new URL('/admin/equipos/fleet-stats', window.location.origin);
        if (frenteId && frenteId !== 'all') {
            url.searchParams.set('frente_id', frenteId);
        }

        const response = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.getCsrf()
            }
        });

        if (!response.ok) {
            const errText = await response.text();
            console.error('Fleet Stats HTTP error:', response.status, errText);
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();

        if (!data || data.success === false) {
            throw new Error(data.message || 'El servidor devolvió un error');
        }

        // Render inmediato de lo que NO requiere Chart (stats numericos y panel lateral)
        updateStatCards(data.stats);
        
        // Mostrar 'Equipos Asignados por Frente' SOLO si se seleccionan 'Todos'
        const panelAssigned = document.getElementById('fdm-panel-assigned');
        if (!frenteId || frenteId === 'all') {
            if (panelAssigned) panelAssigned.style.display = 'block';
            renderFleetEquiposAsignados(data.equiposPorFrente || []);
        } else {
            if (panelAssigned) panelAssigned.style.display = 'none';
        }

        // Esperar Chart.js si aun se esta bajando (carga paralela disparada en openFleetDashboard)
        if (window._fleetChartReady) {
            try { await window._fleetChartReady; } catch (e) { console.warn('Chart.js no cargo:', e); }
        }

        createCharts(data);

        setTimeout(() => {
            if (spinner) spinner.style.display = 'none';
        }, 300);

    } catch (error) {
        console.error('Fleet Dashboard Error:', error.message, error);
        if (spinner) spinner.style.display = 'none';

        if (window.showModal) {
            showModal({
                type: 'error',
                title: 'Error',
                message: 'No se pudieron cargar las estad├¡sticas de la flota. Detalle: ' + error.message,
                confirmText: 'Cerrar',
                hideCancel: true
            });
        }
    }
}

/**
 * Create all charts with data from selected frente
 */
function createCharts(data) {
    if (typeof Chart === 'undefined') {
        throw new Error('Chart.js no está disponible. Verifique que los archivos JS estén instalados en /public/js/.');
    }

    const canvasAge = document.getElementById('chartAgeByType');

    if (!canvasAge) {
        throw new Error('No se encontraron los contenedores de gr├íficos en el DOM.');
    }

    destroyAllCharts();


    // Función auxiliar para mostrar mensaje de vacío
    const showEmptyState = (canvas, parentId, emptyText) => {
        if (canvas) {
            const parent = canvas.parentElement;
            const msg = document.createElement('p');
            msg.style.cssText = 'color:#94a3b8;font-size:13px;text-align:center;padding:30px 0;width:100%;';
            msg.textContent = emptyText;
            canvas.style.display = 'none';
            if (!parent.querySelector('.fleet-empty-msg')) {
                msg.classList.add('fleet-empty-msg');
                parent.appendChild(msg);
            }
        }
    };

    // NOTA: los gráficos "Estado Operativo de Equipos" (doughnut) e "Inoperatividad
    // por Tipo de Equipo" se eliminaron a pedido del cliente; sus canvas ya no existen.

    // 1. Flota Nueva vs Vieja por Tipo - Stacked Horizontal Bar
    if (canvasAge && data.ageByType && data.ageByType.labels && data.ageByType.labels.length > 0) {
        window.fleetCharts.ageByType = createStackedBarChart('chartAgeByType', {
            labels: data.ageByType.labels,
            datasets: data.ageByType.datasets.map((ds, idx) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: window.CHART_COLORS.age[idx],
                borderWidth: 0,
                borderRadius: 5,
                borderSkipped: false
            }))
        });
    } else {
        showEmptyState(canvasAge, 'chartAgeByType', 'Sin equipos registrados para este frente.');
    }

    // 2. Equipos Auxiliares por Tipo - Stacked Horizontal Bar (global)
    const canvasAux = document.getElementById('chartAuxByType');
    if (canvasAux && data.auxByType && data.auxByType.labels && data.auxByType.labels.length > 0) {
        window.fleetCharts.auxByType = createStackedBarChart('chartAuxByType', {
            labels: data.auxByType.labels,
            datasets: data.auxByType.datasets.map((ds, idx) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: window.CHART_COLORS.age[idx] || '#64748b',
                borderWidth: 0,
                borderRadius: 5,
                borderSkipped: false
            }))
        });
    } else if (canvasAux) {
        showEmptyState(canvasAux, 'chartAuxByType', 'Sin equipos auxiliares registrados.');
    }
}

/**
 * Wrap a label string into lines of at most maxChars characters,
 * breaking on spaces. Words longer than maxChars are hard-broken in a loop.
 * Returns an array (multi-line) or the original string (single line).
 */
function wrapLabel(label, maxChars) {
    if (!label || label.length <= maxChars) return label;
    const words = label.split(' ');
    const lines = [];
    let current = '';
    words.forEach(function (w) {
        // Hard-break words longer than maxChars in a loop (not just once)
        while (w.length > maxChars) {
            if (current) { lines.push(current); current = ''; }
            lines.push(w.slice(0, maxChars));
            w = w.slice(maxChars);
        }
        const test = current ? current + ' ' + w : w;
        if (test.length <= maxChars) {
            current = test;
        } else {
            if (current) lines.push(current);
            current = w;
        }
    });
    if (current) lines.push(current);
    return lines.length > 1 ? lines : label;
}

/**
 * Stacked horizontal bar chart.
 * — Valor de cada segmento dentro de la barra (visible para TODO segmento con dato > 0,
 *   sin importar su tamaño, para no tener que pasar el mouse por encima).
 * — Total al final de la barra completa (siempre visible, texto oscuro, fuera de la barra).
 */
function createStackedBarChart(canvasId, config) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const parent = ctx.parentElement;
    const emptyMsg = parent.querySelector('.fleet-empty-msg');
    if (emptyMsg) emptyMsg.remove();
    ctx.style.display = '';

    const labelCount = config.labels ? config.labels.length : 1;
    // maxChars más alto = menos cortes a mitad de palabra; Chart.js auto-ensancha el
    // eje para mostrar la descripción COMPLETA del tipo de equipo (afterFit solo fija
    // un mínimo). En móvil se subió de 12 a 15 para que no se vean nombres partidos.
    const maxChars = window.innerWidth < 480 ? 15 : 18;
    const wrappedLabels = config.labels.map(function (l) { return wrapLabel(l, maxChars); });
    const totalLines = wrappedLabels.reduce(function (sum, l) {
        return sum + (Array.isArray(l) ? l.length : 1);
    }, 0);
    // Altura dinámica según la cantidad de barras. El tope se subió de 400 a 1200 px
    // porque con muchos tipos de equipo las barras quedaban demasiado finas y los
    // valores se solapaban (sobre todo en la captura de imagen). Ahora que los
    // gráficos van en una sola columna a todo el ancho, el gráfico puede crecer y el
    // contenedor del modal hace scroll. pxPerLine también sube un poco con >10 tipos.
    const pxPerLine = labelCount <= 5 ? 26 : labelCount <= 10 ? 22 : 20;
    const dynamicHeight = Math.min(1200, Math.max(120, totalLines * pxPerLine + 55));
    ctx.style.height = dynamicHeight + 'px';
    ctx.style.maxHeight = dynamicHeight + 'px';

    const lastIdx = config.datasets.length - 1;

    // Config de label de segmento (dentro de la barra)
    const segmentLabel = {
        anchor: 'center',
        align: 'center',
        color: 'white',
        textShadowBlur: 4,
        textShadowColor: 'rgba(0,0,0,0.6)',
        font: { weight: '700', size: 9, family: "'Inter', 'Segoe UI', sans-serif" },
        // Muestra el valor de TODO segmento con dato (> 0), sin importar su tamaño,
        // para no tener que pasar el mouse por encima para verlo.
        display: function (ctx) {
            const v = ctx.dataset.data[ctx.dataIndex] || 0;
            return v > 0;
        },
        formatter: Math.round
    };

    // Config de label de total al final de la barra (siempre visible)
    const totalLabel = {
        anchor: 'end',
        align: 'right',
        offset: 5,
        color: '#1e293b',
        textShadowBlur: 0,
        font: { weight: '700', size: 10, family: "'Inter', 'Segoe UI', sans-serif" },
        display: function (ctx) {
            const total = ctx.chart.data.datasets.reduce(
                function (s, d) { return s + (Number(d.data[ctx.dataIndex]) || 0); }, 0
            );
            return total > 0;
        },
        formatter: function (value, ctx) {
            return ctx.chart.data.datasets.reduce(
                function (s, d) { return s + (Number(d.data[ctx.dataIndex]) || 0); }, 0
            );
        }
    };

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: wrappedLabels,
            datasets: config.datasets.map(function (ds, idx) {
                const base = Object.assign({}, ds, { maxBarThickness: 26, categoryPercentage: 0.82, barPercentage: 0.9 });
                // Etiquetas SIEMPRE visibles (sin pasar el mouse). chartjs-plugin-datalabels
                // exige el objeto { labels: {...} } para mostrar varias etiquetas por barra:
                // un array NO es válido y dejaba el valor sin verse. El último dataset suma
                // además el total al final de la barra.
                base.datalabels = (idx === lastIdx)
                    ? { labels: { value: segmentLabel, total: totalLabel } }
                    : { labels: { value: segmentLabel } };
                return base;
            })
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            // Espacio derecho para la etiqueta de total fuera de la barra
            layout: { padding: { right: 38 } },
            plugins: {
                legend: LEGEND_STYLE,
                tooltip: {
                    ...TOOLTIP_STYLES,
                    callbacks: {
                        title: function (tooltipItems) {
                            const raw = tooltipItems[0]?.label || '';
                            return Array.isArray(raw) ? raw.join(' ') : raw;
                        }
                    }
                },
                // Config base para todos los datasets (sobreescrita por dataset.datalabels)
                datalabels: segmentLabel
            },
            scales: {
                x: { stacked: true, display: false, grid: { display: false } },
                y: {
                    stacked: true,
                    grid: { display: false },
                    ticks: {
                        font: { size: window.innerWidth < 480 ? 10 : 11, weight: '500', family: "'Inter', 'Segoe UI', sans-serif" },
                        color: '#475569',
                        maxRotation: 0,
                        minRotation: 0,
                        autoSkip: false
                    },
                    afterFit: function (scale) {
                        const minW = window.innerWidth < 480 ? 118 : 150;
                        if (scale.width < minW) scale.width = minW;
                    }
                }
            }
        }
    });
}

/**
 * Destroy all chart instances
 */
function destroyAllCharts() {
    for (const key in window.fleetCharts) {
        if (window.fleetCharts[key] && typeof window.fleetCharts[key].destroy === 'function') {
            window.fleetCharts[key].destroy();
        }
    }
    window.fleetCharts = {};
}

/**
 * Capture DOM panel as image and download
 */
window.descargarPanelHtmlFDM = async function(panelId, nombre) {
    const el = document.getElementById(panelId);
    if (!el || el.style.display === 'none') {
        alert('El panel no está visible.'); return;
    }
    if (typeof html2canvas === 'undefined') {
        if (window.showPreloader) window.showPreloader();
        await ensureHtml2Canvas();
        if (window.hidePreloader) window.hidePreloader();
        if (typeof html2canvas === 'undefined') {
            alert('Error al cargar la librería de captura.');
            return;
        }
    }
    const fecha = new Date().toISOString().slice(0, 10);
    html2canvas(el, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
        logging: false,
        onclone: function (clonedDoc) {
            const clonedEl = clonedDoc.getElementById(panelId);
            if (clonedEl) {
                // Remove the camera button from the screenshot
                const btns = clonedEl.querySelectorAll('button');
                btns.forEach(b => b.style.display = 'none');
                
                // Fix Material Icons text misalignments in headings
                const titles = clonedEl.querySelectorAll('span, h4');
                titles.forEach(t => {
                    if (t.style.display === 'flex') {
                        t.style.alignItems = 'center'; // Ensure alignment is preserved
                    }
                });

                // Force column layout for the "Equipos Asignados" panel list to avoid squeezing
                if (panelId === 'fdm-panel-assigned') {
                    const asigBody = clonedEl.querySelector('#fleetEqAsigBody > div');
                    if (asigBody) {
                        asigBody.style.flexDirection = 'column';
                        asigBody.style.flexWrap = 'nowrap';
                        
                        // Shrink the card width so the column doesn't stretch weirdly across the screen
                        clonedEl.style.width = '350px';
                        clonedEl.style.margin = '0 auto';
                    }
                    
                    // Fix the title wrapping specifically for Equipos Asignados
                    const headerSpan = clonedEl.querySelector('.material-icons').parentElement;
                    if (headerSpan) {
                        headerSpan.style.flexWrap = 'wrap';
                    }
                }
            }
        }
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = nombre + '_' + fecha + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
};
