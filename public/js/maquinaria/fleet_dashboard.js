// Fleet Dashboard Modal Manager - Filtered by Frente
// Uses Chart.js for visualizations + DataLabels Plugin

// SPA-safe globals
if (!window.fleetCharts) window.fleetCharts = {};
if (!window.currentFrenteId) window.currentFrenteId = '';

if (!window.CHART_COLORS) {
    window.CHART_COLORS = {
        status: {
            'OPERATIVO': '#110a50ff',
            'EN MANTENIMIENTO': '#69696dff',
            'INOPERATIVO': '#a31616ff',
            'DESINCORPORADO': '#07090aff'
        },
        age: ['#110a50ff', '#a31616ff'],
        category: ['#a31616ff', '#110a50ff', '#69696dff'],
        inoperative: ['#dc2626', '#f59e0b', '#0f172a']
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
                    <span style="font-size:12px;font-weight:700;color:#94a3b8;width:20px;"></span>
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

    if (typeof Chart === 'undefined') {
        await loadChartJS();
    }

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
    await loadFleetDashboardData(firstFrenteId);
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
 * Load Chart.js library dynamically
 */
async function loadChartJS() {
    return new Promise((resolve, reject) => {
        const baseUrl = document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '';

        const script = document.createElement('script');
        script.src = baseUrl + '/js/chart.umd.min.js';

        script.onload = () => {
            const pluginScript = document.createElement('script');
            pluginScript.src = baseUrl + '/js/chartjs-plugin-datalabels.min.js';
            pluginScript.onload = () => {
                Chart.register(ChartDataLabels);
                
                // Also load html2canvas for downloads
                if (typeof html2canvas === 'undefined') {
                    const canvasScript = document.createElement('script');
                    canvasScript.src = baseUrl + '/js/html2canvas.min.js';
                    canvasScript.onload = () => resolve();
                    canvasScript.onerror = () => {
                        console.warn('Failed to load html2canvas, downloads might fail.');
                        resolve();
                    };
                    document.head.appendChild(canvasScript);
                } else {
                    resolve();
                }
            };
            pluginScript.onerror = () => {
                console.warn('Failed to load DataLabels plugin, charts will work without it.');
                
                if (typeof html2canvas === 'undefined') {
                    const canvasScript = document.createElement('script');
                    canvasScript.src = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
                    canvasScript.onload = () => resolve();
                    canvasScript.onerror = () => resolve();
                    document.head.appendChild(canvasScript);
                } else {
                    resolve();
                }
            };
            document.head.appendChild(pluginScript);
        };

        script.onerror = () => reject(new Error('Failed to load Chart.js'));
        document.head.appendChild(script);
    });
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
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        if (!response.ok) {
            const errText = await response.text();
            console.error('Fleet Stats HTTP error:', response.status, errText);
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }

        const data = await response.json();

        if (!data || data.success === false) {
            throw new Error(data.message || 'El servidor devolvi├│ un error');
        }

        updateStatCards(data.stats);

        // Render equipos asignados por frente panel
        renderFleetEquiposAsignados(data.equiposPorFrente || []);

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
        throw new Error('Chart.js no est├í disponible. Verifique su conexi├│n a internet.');
    }

    const canvasStatus = document.getElementById('chartStatusByFront');
    const canvasAge = document.getElementById('chartAgeByType');
    const canvasCat = document.getElementById('chartCategoryByType');
    const canvasInop = document.getElementById('chartInoperativeByType');

    if (!canvasStatus || !canvasAge || !canvasCat) {
        throw new Error('No se encontraron los contenedores de gr├íficos en el DOM.');
    }

    destroyAllCharts();

    const chartsGrid = document.getElementById('fleetChartsGrid');
    


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

    // 1. Estado Operativo - Doughnut
    if (canvasStatus && data.byStatus && data.byStatus.labels && data.byStatus.labels.length > 0) {
        const parent = canvasStatus.parentElement;
        const emptyMsg = parent.querySelector('.fleet-empty-msg');
        if (emptyMsg) emptyMsg.remove();
        canvasStatus.style.display = '';

        window.fleetCharts.byStatus = new Chart(canvasStatus, {
            type: 'doughnut',
            data: {
                labels: data.byStatus.labels,
                datasets: [{
                    data: data.byStatus.values,
                    backgroundColor: data.byStatus.labels.map(label => window.CHART_COLORS.status[label] || '#64748b'),
                    borderWidth: 2,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: LEGEND_STYLE,
                    tooltip: TOOLTIP_STYLES,
                    datalabels: {
                        color: 'white',
                        font: { weight: 'bold', size: 12, family: "'Inter', 'Segoe UI', sans-serif" },
                        formatter: (value) => value > 0 ? value : ''
                    }
                }
            }
        });
    } else {
        showEmptyState(canvasStatus, 'chartStatusByFront', 'Sin datos operativos en esta selección.');
    }

    // 2. Flota Nueva vs Vieja por Tipo - Stacked Horizontal Bar
    if (canvasAge && data.ageByType && data.ageByType.labels && data.ageByType.labels.length > 0) {
        window.fleetCharts.ageByType = createStackedBarChart('chartAgeByType', {
            labels: data.ageByType.labels,
            datasets: data.ageByType.datasets.map((ds, idx) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: window.CHART_COLORS.age[idx],
                borderWidth: 0,
                borderRadius: 0,
                borderSkipped: false
            }))
        });
    } else {
        showEmptyState(canvasAge, 'chartAgeByType', 'Sin equipos registrados para este frente.');
    }

    // 3. Flota Pesada vs Liviana por Tipo - Stacked Horizontal Bar
    if (canvasCat && data.categoryByType && data.categoryByType.labels && data.categoryByType.labels.length > 0) {
        window.fleetCharts.categoryByType = createStackedBarChart('chartCategoryByType', {
            labels: data.categoryByType.labels,
            datasets: data.categoryByType.datasets.map((ds, idx) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: window.CHART_COLORS.category[idx],
                borderWidth: 0,
                borderRadius: 0,
                borderSkipped: false
            }))
        });
    } else {
        showEmptyState(canvasCat, 'chartCategoryByType', 'Sin categorias asociadas en este frente.');
    }

    // 4. Inoperatividad por Tipo de Equipo - Stacked Horizontal Bar
    if (canvasInop && data.inoperativeByType && data.inoperativeByType.labels.length > 0) {
        window.fleetCharts.inoperativeByType = createStackedBarChart('chartInoperativeByType', {
            labels: data.inoperativeByType.labels,
            datasets: data.inoperativeByType.datasets.map((ds, idx) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: window.CHART_COLORS.inoperative[idx] || '#64748b',
                borderWidth: 0,
                borderRadius: 0,
                borderSkipped: false
            }))
        });
    } else if (canvasInop) {
        // Show empty state
        const parent = canvasInop.parentElement;
        const msg = document.createElement('p');
        msg.style.cssText = 'color:#94a3b8;font-size:13px;text-align:center;padding:30px 0;';
        msg.textContent = 'Sin equipos inoperativos en esta selecci├│n.';
        canvasInop.style.display = 'none';
        if (!parent.querySelector('.fleet-empty-msg')) {
            msg.classList.add('fleet-empty-msg');
            parent.appendChild(msg);
        }
    }
}

/**
 * Create Clean Stacked Horizontal Bar Chart with rounded bars
 */
function createStackedBarChart(canvasId, config) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    // Remove any empty state message if re-rendering
    const parent = ctx.parentElement;
    const emptyMsg = parent.querySelector('.fleet-empty-msg');
    if (emptyMsg) emptyMsg.remove();
    ctx.style.display = '';

    // Dynamic height: smarter formula with max cap
    // - ≤5 labels: 44px each   → comfortable spacing
    // - 6-10 labels: 36px each → compact but readable
    // - >10 labels: 28px each  → dense but still visible
    // Hard cap: 320px (never taller than a screen panel)
    const labelCount = config.labels ? config.labels.length : 1;
    const pxPerLabel = labelCount <= 5 ? 36 : labelCount <= 10 ? 32 : 28;
    const dynamicHeight = Math.min(320, Math.max(160, labelCount * pxPerLabel + 40));
    ctx.style.height = dynamicHeight + 'px';
    ctx.style.maxHeight = dynamicHeight + 'px';

    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: config.labels,
            datasets: config.datasets.map(ds => ({
                ...ds,
                maxBarThickness: 28
            }))
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: LEGEND_STYLE,
                tooltip: {
                    ...TOOLTIP_STYLES,
                    callbacks: {
                        title: function (tooltipItems) {
                            return tooltipItems[0]?.label || '';
                        }
                    }
                },
                datalabels: {
                    color: 'white',
                    font: { weight: 'bold', size: 12, family: "'Inter', 'Segoe UI', sans-serif" },
                    display: function (context) {
                        return context.dataset.data[context.dataIndex] > 0;
                    },
                    formatter: Math.round
                }
            },
            scales: {
                x: {
                    stacked: true,
                    display: false,
                    grid: { display: false }
                },
                y: {
                    stacked: true,
                    grid: { display: false },
                    ticks: {
                        font: { size: 12, weight: '600', family: "'Inter', 'Segoe UI', sans-serif" },
                        color: '#475569'
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
window.descargarPanelHtmlFDM = function(panelId, nombre) {
    const el = document.getElementById(panelId);
    if (!el || el.style.display === 'none') {
        alert('El panel no est├í visible.'); return;
    }
    if (typeof html2canvas === 'undefined') {
        alert('La librer├¡a de captura a├║n est├í cargando. Int├®ntalo en unos segundos.'); return;
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
