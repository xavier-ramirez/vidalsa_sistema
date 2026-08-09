// Fleet Dashboard Modal Manager - Filtered by Frente
// Uses Chart.js for visualizations + DataLabels Plugin

// SPA-safe globals
if (!window.fleetCharts) window.fleetCharts = {};
if (!window.currentFrenteId) window.currentFrenteId = '';

if (!window.CHART_COLORS) {
    window.CHART_COLORS = {
        // 'status' (doughnut Estado Operativo) e 'inoperative' (Inoperatividad por Tipo)
        // se eliminaron junto con esos gráficos. 'age' lo usan Flota por Tipo y Auxiliares.
        // [0] Nueva = azul #004a80, [1] Antigua = rojo #911a24. Tonos OSCUROS pedidos
        // expresamente por el cliente (2026-07-30): NO aclararlos por criterio de estilo.
        // Validado con scripts/validate_palette.js: separacion para daltonismo holgada
        // (protan dE 15.3, normal 24.5 — los minimos son 8 y 15) y contraste >= 3:1.
        // La UNICA comprobacion que no pasa es la banda de luminosidad (L 0.401 y 0.429
        // frente a un minimo de 0.43): al ser tan oscuros las barras pesan mas sobre el
        // fondo blanco. Es el intercambio aceptado a cambio del tono pedido.
        // Referencia: el par mas claro que aprobaba TODO era #005a9c + #a91d28.
        // FUENTE UNICA de estos colores: los puntos de la cabecera (.fdm-dot) tambien se
        // pintan desde aqui, no los repite el blade.
        // [2] Gris = "Sin año", solo lo usa el grafico de auxiliares (los equipos siempre
        // tienen ANIO). Es neutro a proposito: no compite con los dos tonos del cliente y
        // se lee como "dato faltante", no como una tercera categoria de flota. Antes este
        // mismo gris estaba escrito a mano como respaldo en createCharts.
        age: ['#004a80', '#911a24', '#64748b']
    };
}

/**
 * Devuelve la tinta legible ENCIMA de un relleno: blanco o casi negro, la que mas
 * contraste da.
 *
 * Con la paleta ACTUAL (#004a80 9.17:1 y #911a24 8.83:1 en blanco) siempre sale blanco,
 * asi que hoy no cambia nada: es un SEGURO para cuando se toque la paleta. Se anadio
 * porque al probar un ocre #d97a1f el numero blanco dentro del tramo se quedo en 3.11:1
 * — ilegible — y eso paso desapercibido hasta medirlo. Con esto, cambiar CHART_COLORS
 * no puede volver a romper la legibilidad en silencio.
 */
function tintaSobre(relleno) {
    const lum = function (hex) {
        const c = [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16) / 255; })
            .map(function (v) { return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
    };
    const ratio = function (a, b) {
        const l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    };
    return ratio('#ffffff', relleno) >= ratio('#1a1205', relleno) ? '#ffffff' : '#1a1205';
}

// Shared professional legend style
const LEGEND_STYLE = {
    position: 'bottom',
    labels: {
        padding: 16,
        font: { size: 11.5, weight: '500', family: "'Inter', 'Segoe UI', sans-serif" },
        boxWidth: 9,
        boxHeight: 9,
        // Literal y no var(--fd-ink-3): Chart.js pinta en canvas y no resuelve variables CSS.
        // Equivale a --fd-ink-3 (3.06:1 sobre blanco) — vale porque es mobiliario, no texto.
        color: '#8a94a6',
        usePointStyle: true,
        pointStyle: 'rectRounded'
    }
};

// Common tooltip styles
const TOOLTIP_STYLES = {
    backgroundColor: 'rgba(15,23,42,0.94)',
    titleColor: '#ffffff',
    bodyColor: '#e2e8f0',
    borderColor: 'transparent',
    borderWidth: 0,
    padding: 11,
    cornerRadius: 10,
    displayColors: true,
    boxWidth: 10,
    boxHeight: 10
};

/**
 * Update stat cards with data
 */
function pintarPuntosDeSerie() {
    // Los puntos de color de la cabecera del grafico de edad salen de CHART_COLORS.age,
    // no de un hex repetido en el blade: si se cambia la paleta, se cambian solos.
    document.querySelectorAll('#fleetDashboardModal .fdm-dot[data-serie]').forEach(function (el) {
        const c = window.CHART_COLORS.age[Number(el.dataset.serie)];
        if (c) el.style.background = c;
    });
}

function updateStatCards(stats) {
    const total = document.getElementById('stat_total');
    const fleetNew = document.getElementById('stat_fleet_new');
    const fleetOld = document.getElementById('stat_fleet_old');
    const consumption = document.getElementById('stat_consumption');

    if (total) total.textContent = stats.total || 0;
    if (fleetNew) fleetNew.textContent = stats.fleet_new || 0;
    if (fleetOld) fleetOld.textContent = stats.fleet_old || 0;
    if (consumption) consumption.textContent = stats.total_consumption || 0;

    // Claves del panel de auxiliares. La de "Sin año" solo se muestra si hay alguno:
    // con el año cargado en todos, la cabecera queda igual que la de equipos.
    const auxNew = document.getElementById('stat_aux_new');
    const auxOld = document.getElementById('stat_aux_old');
    const auxSin = document.getElementById('stat_aux_sin');
    const auxSinKey = document.getElementById('stat_aux_sin_key');
    if (auxNew) auxNew.textContent = stats.aux_new || 0;
    if (auxOld) auxOld.textContent = stats.aux_old || 0;
    if (auxSin) auxSin.textContent = stats.aux_sin_anio || 0;
    if (auxSinKey) auxSinKey.style.display = (stats.aux_sin_anio || 0) > 0 ? '' : 'none';

    // Tarjeta "Σ Auxiliares": el backend no manda un total propio, se suma de las tres
    // cifras de arriba. Se calcula AQUI y no aparte para que salga siempre de los mismos
    // numeros que las claves del panel de auxiliares y no puedan descuadrar entre si.
    const auxTotal = document.getElementById('stat_aux_total');
    if (auxTotal) {
        auxTotal.textContent = (stats.aux_new || 0) + (stats.aux_old || 0) + (stats.aux_sin_anio || 0);
    }

    pintarPuntosDeSerie();
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
        body.innerHTML = '<p style="color:var(--fd-ink-2);font-size:13px;text-align:center;padding:20px;">Sin datos de equipos asignados.</p>';
        return;
    }

    // Ordenar de mayor a menor por cantidad de equipos asignados
    lista = [...lista].sort((a, b) => (Number(b.total) || 0) - (Number(a.total) || 0));

    // Mismo lenguaje que las tarjetas KPI de arriba: fondo blanco, anillo de un pelo y la
    // CIFRA como único elemento con peso. Antes eran bloques grises saturados con sombra y
    // texto en 900 — leían muy fuerte y no encajaban con el resto del modal.
    body.innerHTML = `<div style="display:flex;flex-wrap:wrap;gap:8px;">${lista.map((row, i) => `
            <div style="
                background:#fff;
                border:1px solid var(--fd-ring);
                border-radius:10px;
                padding:10px 13px;
                min-width:150px;
                flex:1;
                display:flex;
                flex-direction:column;
                gap:3px;
            ">
                <div style="display:flex; align-items:baseline; gap:6px; width:100%;">
                    <span style="font-size:10.5px;font-weight:600;color:var(--fd-ink-2);opacity:.55;flex-shrink:0;">${i + 1}</span>
                    <span style="font-size:11px;font-weight:500;color:var(--fd-ink-2);line-height:1.25;word-break:break-word;flex:1;" title="${row.frente}">${row.frente}</span>
                </div>
                <div style="display:flex;align-items:baseline;gap:5px;">
                    <span style="font-size:21px;font-weight:700;line-height:1.1;color:var(--fd-ink);letter-spacing:-0.5px;">${row.total}</span>
                    <span style="font-size:11px;font-weight:500;color:var(--fd-ink-2);">equipo${row.total !== 1 ? 's' : ''}</span>
                </div>
            </div>`
    ).join('')
        }</div>`;
}

/**
 * Open Fleet Dashboard Modal
 */
/**
 * Muestra/quita el aviso de "esto necesita internet" dentro del área de gráficos.
 *
 * Los paneles se OCULTAN, no se borran, y esto NO es un detalle de estilo: createCharts()
 * busca sus <canvas> por id (chartAgeByType, …), así que reemplazar el innerHTML del
 * contenedor los destruye para siempre. Si después vuelve la conexión SIN recargar la
 * página —caso real: el banner de "Conexión restaurada" no recarga si el usuario nunca
 * activó el modo offline— el Dashboard ya no podría dibujar ningún gráfico.
 */
function avisoSinConexion(activo) {
    const charts = document.getElementById('fleetChartsGrid');
    if (!charts) return;
    const ID = 'fdmAvisoOffline';

    Array.prototype.forEach.call(charts.children, (el) => {
        if (el.id !== ID) el.style.display = activo ? 'none' : '';
    });

    const previo = document.getElementById(ID);
    if (!activo) { if (previo) previo.remove(); return; }
    if (previo) return;

    const aviso = document.createElement('div');
    aviso.id = ID;
    aviso.style.cssText = 'background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:28px 20px;text-align:center;color:#94a3b8;font-size:13px;';
    aviso.innerHTML =
        '<i class="material-icons" style="font-size:40px;color:#cbd5e0;display:block;margin:0 auto 10px;">cloud_off</i>' +
        'Los gráficos y el resumen de flota se calculan en el servidor: se ven al recuperar internet.<br>' +
        '<span style="font-size:12px;">La lista de abajo sí sale de la copia local.</span>';
    charts.appendChild(aviso);
}

window.openFleetDashboard = async function () {
    const modal = document.getElementById('fleetDashboardModal');
    if (!modal) return;

    // SIN CONEXIÓN el modal SÍ se abre, pero no se le pide nada al servidor.
    //
    // No se puede simplemente bloquearlo: en TELÉFONO la tarjeta de Distribución
    // ("Equipos y Maquinaria" / "Ubicación por Frente") se muda DENTRO de este modal
    // (colocarDistribucionMovil), así que cerrarlo dejaba sin acceso a una lista que sí
    // se calcula de la copia local — la pinta equipos-offline.js.
    //
    // Lo que sí necesita servidor son las cifras de /admin/equipos/fleet-stats (Σ
    // Auxiliares y el gasoil ni siquiera viajan en el snapshot) y los gráficos. Antes se
    // intentaba el fetch igual, fallaba y quedaba encima un segundo modal de error con el
    // detalle técnico ("Failed to fetch"). Ahora esas partes se marcan como no disponibles
    // y el resto del modal queda usable.
    const OM = window.OfflineMode;
    if (OM && (OM.estaActivo() || (OM.pendienteActivar && OM.pendienteActivar()))) {
        modal.classList.add('active');
        modal.style.display = 'flex';
        const spinner = document.getElementById('fleetDashboardSpinner');
        if (spinner) spinner.style.display = 'none';
        ['stat_total', 'stat_aux_total', 'stat_consumption'].forEach((id) => {
            const e = document.getElementById(id);
            if (e) e.textContent = '--';
        });
        avisoSinConexion(true);
        return;
    }
    avisoSinConexion(false);   // por si el modal se abrió sin conexión en esta misma página

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

    window.apiFetch(url, { method: 'GET' })
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
            
            window.toast('Descarga completada', 'success');
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

        const response = await window.apiFetch(url, { headers: { 'Accept': 'application/json' } });

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
    const showEmptyState = (canvas, emptyText) => {
        if (canvas) {
            const parent = canvas.parentElement;
            const msg = document.createElement('p');
            // var(--fd-ink-2): es texto, y #94a3b8 daba 2.56:1 sobre blanco.
            // padding 12px (antes 30px): un panel vacío son 3 renglones de aire arriba y abajo
            // del mensaje, y dejaba un hueco enorme entre este panel y el de arriba.
            msg.style.cssText = 'color:var(--fd-ink-2);font-size:13px;text-align:center;padding:12px 0;width:100%;';
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
            // Sin leyenda de Chart.js: la cabecera del panel ya lleva las claves de serie
            // CON su total (.fdm-keys), asi que repetirla abajo seria decir lo mismo dos
            // veces. Los DOS graficos van igual, los dos tienen claves en cabecera.
            sinLeyenda: true,
            // Solo etiqueta, datos y color: el ASPECTO de la barra (grosor, hueco entre
            // tramos, redondeo) lo pone createStackedBarChart en un único sitio.
            datasets: data.ageByType.datasets.map((ds, idx) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: window.CHART_COLORS.age[idx]
            }))
        });
    } else {
        showEmptyState(canvasAge, 'Sin equipos registrados para este frente.');
    }

    // 2. Auxiliares por Tipo, con el MISMO corte de edad que el grafico de arriba
    //    (nueva >= 2025 / antigua < 2025, y "Sin año" si los hay). Mismos colores, mismas
    //    claves en cabecera y misma ausencia de leyenda: son dos vistas de lo mismo.
    const canvasAux = document.getElementById('chartAuxByType');
    if (canvasAux && data.auxByType && data.auxByType.labels && data.auxByType.labels.length > 0) {
        window.fleetCharts.auxByType = createStackedBarChart('chartAuxByType', {
            labels: data.auxByType.labels,
            sinLeyenda: true,
            datasets: data.auxByType.datasets.map((ds, idx) => ({
                label: ds.label,
                data: ds.data,
                backgroundColor: window.CHART_COLORS.age[idx]
            }))
        });
    } else if (canvasAux) {
        showEmptyState(canvasAux, 'Sin equipos auxiliares registrados.');
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
    // Altura dinámica según la cantidad de barras. Se reservan DOS cosas por separado:
    // el alto de cada LÍNEA de texto del tick y un HUECO entre categorías. El cálculo
    // anterior usaba un solo "px por línea" (15-20) que hacía de las dos cosas a la vez,
    // así que con etiquetas de 2+ líneas dos categorías vecinas quedaban pegadas y los
    // nombres se solapaban en pantalla (p.ej. "CAMION DE SOLDADURA" encima de "CAMION
    // ELEVADOR 8 TON"). Al separarlos, cada categoría recibe SIEMPRE el alto real de su
    // etiqueta envuelta más el aire entre barras.
    const lineH = window.innerWidth < 480 ? 13 : 14;   // alto de línea del tick (fuente 10/11px)
    const gapPorBarra = labelCount <= 10 ? 14 : 10;    // aire entre barras
    const dynamicHeight = Math.min(
        1400,
        Math.max(120, totalLines * lineH + labelCount * gapPorBarra + 50)
    );
    ctx.style.height = dynamicHeight + 'px';
    ctx.style.maxHeight = dynamicHeight + 'px';

    const lastIdx = config.datasets.length - 1;

    // Config de label de segmento (dentro de la barra)
    const segmentLabel = {
        anchor: 'center',
        align: 'center',
        // La tinta se decide POR TRAMO segun su relleno (ver tintaSobre): sobre el azul
        // va blanca, sobre el ocre va oscura. Sin textShadow: con la tinta correcta no
        // hace falta, y la sombra emborronaba un numero de 9px.
        color: function (ctx) {
            const bg = ctx.dataset.backgroundColor;
            // El respaldo sale de CHART_COLORS, no de un hex suelto: escrito a mano se
            // quedaba apuntando a un color que ya no estaba en la paleta.
            return tintaSobre(typeof bg === 'string' ? bg : window.CHART_COLORS.age[0]);
        },
        font: { weight: '600', size: 9.5, family: "'Inter', 'Segoe UI', sans-serif" },
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
        color: '#64748b',
        textShadowBlur: 0,
        font: { weight: '600', size: 10.5, family: "'Inter', 'Segoe UI', sans-serif" },
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
                // Separación entre segmentos: un borde de 2px del COLOR DE LA SUPERFICIE
                // (blanco), no un contorno de color. Se lee como aire entre los tramos y no
                // como tinta extra; antes los tramos se tocaban y el límite entre azul y rojo
                // quedaba duro.
                //
                // BARRAS CUADRADAS por decisión del cliente (2026-07-30): sin borderRadius.
                // NO volver a redondearlas "por estilo" — se pidió expresamente así.
                const base = Object.assign({}, ds, {
                    maxBarThickness: 18,
                    categoryPercentage: 0.82,
                    barPercentage: 0.9,
                    borderColor: '#fff',
                    // Solo el lado IZQUIERDO, que es por donde un tramo toca al anterior.
                    // Con los 4 lados a 2px la barra perdía 4px de grosor (18 -> 14) porque
                    // Chart.js dibuja el borde DENTRO de la caja de la barra.
                    borderWidth: { left: 2, top: 0, right: 0, bottom: 0 },
                    borderSkipped: false,
                    borderRadius: 0
                });
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
                legend: config.sinLeyenda ? { display: false } : LEGEND_STYLE,
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
                        color: '#8a94a6',   // = --fd-ink-3 (ver LEGEND_STYLE)
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
                
                // (Se quitó un bucle que buscaba <span>/<h4> con display:flex INLINE para
                //  centrarlos: los <h4> ya no existen en estos paneles y el display:flex de
                //  .fdm-panel-title vive en una clase CSS, no en el style inline — la
                //  condición nunca era cierta y el bucle no hacía nada.)

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
                    
                    // Fix the title wrapping specifically for Equipos Asignados.
                    // Se busca por .fdm-panel-title (la clase del título) y no por el primer
                    // .material-icons + parentElement: eso llegaba al título de casualidad y
                    // reventaba con TypeError si el panel no tuviera iconos.
                    const headerSpan = clonedEl.querySelector('.fdm-panel-title');
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
