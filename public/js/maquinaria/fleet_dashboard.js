// Dashboard de Flota (modal de /admin/equipos), filtrado por frente.
// Los gráficos por tipo son HTML (filas con barras), no Chart.js: el texto nunca se
// encima, se lee igual en cualquier ancho y el modal ya no descarga la librería.

if (!window.currentFrenteId) window.currentFrenteId = '';

// [0] Nueva = azul #004a80, [1] Antigua = rojo #911a24: tonos OSCUROS pedidos expresamente
// por el cliente (2026-07-30), NO aclararlos por estilo. [2] Gris = "Sin año" (solo
// auxiliares): neutro a propósito, se lee como dato
// faltante y no como una tercera categoría. FUENTE ÚNICA: los puntos y bloques del blade
// (data-serie) también se pintan desde aquí.
const FDM_COLORES = ['#004a80', '#911a24', '#64748b'];

/**
 * Update stat cards with data
 */
function pintarPuntosDeSerie() {
    // El color de cada serie llega al blade como variable --serie (lo usan los bloques
    // del resumen), no como un hex repetido allí: si se cambia la paleta, se cambian solos.
    document.querySelectorAll('#fleetDashboardModal [data-serie]').forEach(function (el) {
        const c = FDM_COLORES[Number(el.dataset.serie)];
        if (c) el.style.setProperty('--serie', c);
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

    // Desglose por combustible. Cuenta SOLO la tabla de equipos, así que las dos
    // cifras suman como mucho el "Σ Equipos" de al lado (la diferencia son los
    // eléctricos, los 'NO APLICA' y los que aún no tienen combustible cargado).
    const gasoil = document.getElementById('stat_gasoil_count');
    const gasolina = document.getElementById('stat_gasolina_count');
    if (gasoil) gasoil.textContent = stats.equipos_gasoil || 0;
    if (gasolina) gasolina.textContent = stats.equipos_gasolina || 0;

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
 * Panel «Equipos Asignados por Frente»: una tarjeta por frente, de mayor a menor. Sale
 * siempre, elija el frente que elija (el backend manda todos los del usuario); el frente
 * elegido en el dashboard se resalta con .fdm-asig--actual (borde azul).
 */
function renderFleetEquiposAsignados(lista, frenteActual) {
    const loading = document.getElementById('fleetEqAsigLoading');
    const body = document.getElementById('fleetEqAsigBody');
    if (!body) return;

    if (loading) loading.style.display = 'none';
    body.style.display = 'block';

    if (!lista || lista.length === 0) {
        body.innerHTML = '<p class="fdm-vacio">Sin datos de equipos asignados.</p>';
        return;
    }

    lista = [...lista].sort((a, b) => (Number(b.total) || 0) - (Number(a.total) || 0));
    const max = Number(lista[0].total) || 1;
    const esc = window.escapeHtml;
    const actual = (frenteActual || '').trim().toUpperCase();

    // Tarjeta blanca de una línea (puesto · frente · cifra); el color, por tramos según el
    // puesto, va solo en el puesto y en la barra (ver .fdm-asig--b*). La barra es la
    // proporción respecto al frente con más equipos.
    const BANDAS = ['fdm-asig--b0', 'fdm-asig--b1', 'fdm-asig--b2', 'fdm-asig--b3'];
    body.innerHTML = `<div class="fdm-asig-grid">${lista.map((row, i) => {
        const banda = BANDAS[Math.floor(i * BANDAS.length / lista.length)];
        const esActual = actual && String(row.frente).trim().toUpperCase() === actual;
        const pct = Math.max(4, Math.round((Number(row.total) || 0) * 100 / max));
        return `
            <div class="fdm-asig ${banda}${esActual ? ' fdm-asig--actual' : ''}">
                <span class="fdm-asig-pos">${i + 1}</span>
                <span class="fdm-asig-nom" title="${esc(row.frente)}">${esc(row.frente)}</span>
                <div class="fdm-asig-cifra">
                    <b>${row.total}</b><span>equipo${row.total !== 1 ? 's' : ''}</span>
                </div>
                <div class="fdm-asig-prop"><i style="width:${pct}%"></i></div>
            </div>`;
    }).join('')}</div>`;
}

/**
 * Muestra/quita el aviso de "esto necesita internet" dentro del área de gráficos.
 *
 * Los paneles se OCULTAN, no se borran, y esto NO es un detalle de estilo: pintarPorTipo()
 * y renderFleetEquiposAsignados() buscan sus contenedores por id (chartAgeByType,
 * fleetEqAsigBody, …), así que reemplazar el innerHTML del contenedor los destruye para
 * siempre. Si después vuelve la conexión SIN recargar la página —caso real: el banner de
 * "Conexión restaurada" no recarga si el usuario nunca activó el modo offline— el
 * Dashboard ya no podría pintar nada.
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
        ['stat_total', 'stat_aux_total', 'stat_consumption',
         'stat_gasoil_count', 'stat_gasolina_count'].forEach((id) => {
            const e = document.getElementById(id);
            if (e) e.textContent = '--';
        });
        avisoSinConexion(true);
        return;
    }
    avisoSinConexion(false);   // por si el modal se abrió sin conexión en esta misma página

    modal.classList.add('active');
    modal.style.display = 'flex';

    setupDropdownEvents();

    // ── Leer frente con prioridades claras ──
    // Prioridad 1: Filtro activo en la página (?id_frente=16) — aplica para TODOS
    // Prioridad 2: Campo oculto inyectado por el servidor (Blade) — cubre usuarios locales
    const hiddenId   = document.getElementById('dashboardSelectedFrenteId');
    const hiddenName = document.getElementById('dashboardSelectedFrenteNombre');
    const isGlobalUser = !!document.getElementById('dashboardFrenteSearch');

    // Leer el filtro activo en la URL de la página
    const pageFilterInput = document.querySelector('input[name="id_frente"][data-filter-value]');
    const activeFrenteId  = (pageFilterInput && pageFilterInput.value && pageFilterInput.value !== 'all')
        ? pageFilterInput.value : '';

    let firstFrenteId   = '';
    let firstFrenteName = '';

    if (activeFrenteId) {
        // Prioridad 1: Filtro activo en la página — igual para LOCAL y GLOBAL
        firstFrenteId = activeFrenteId;

        // Intentar resolver el nombre desde el dropdown visible
        const selectedOption = document.querySelector(
            `#frenteFilterSelect .dropdown-item[data-value="${activeFrenteId}"]`
        );
        // .frente-nombre: solo el nombre, sin el estado de venta que va al lado en la lista.
        firstFrenteName = selectedOption ? (selectedOption.querySelector('.frente-nombre') || selectedOption).textContent.trim() : (hiddenName?.value || '');

        // Actualizar los campos ocultos para que exportFleetStats también use el correcto
        if (hiddenId)   hiddenId.value   = firstFrenteId;
        if (hiddenName) hiddenName.value = firstFrenteName;
    } else {
        // Prioridad 2: Valor pre-inyectado por el servidor (el Blade ya calculó el mejor frente)
        firstFrenteId   = hiddenId?.value   || '';
        firstFrenteName = hiddenName?.value || '';
    }

    const searchInput = document.getElementById('dashboardFrenteSearch');
    if (searchInput) {
        searchInput.value = firstFrenteName;
        dashboardToggleClearBtn();
    }

    window.currentFrenteId = firstFrenteId;

    // Si el frente NO vino del listado (prioridad 2: se lo eligió el Blade), el Dashboard
    // arranca enseñando un frente que la tabla no tiene filtrado. En móvil, con la card de
    // distribución mudada aquí dentro, eso es justo lo que se veía: cifras de COMOR FASE III
    // arriba y '--' debajo, sin tocar nada. Se alinea el listado con lo que el modal ya está
    // mostrando. Con prioridad 1 no se hace nada: el frente SALIÓ del filtro de la tabla, así
    // que ya están de acuerdo y volver a pedir la lista sería una consulta de más.
    if (!activeFrenteId && firstFrenteId) {
        sincronizarDistribucionConFrente(firstFrenteId, firstFrenteName);
    }

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
 * Setup Dropdown Events (Close when clicking outside) — runs only once
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
 * Clear the frente search input — NO data reload (just clears the field)
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
 * Hace que la card de distribución siga al frente elegido en ESTE modal.
 *
 * "Equipos y Maquinaria" (TOTAL/OPER./INOP. y su lista) NO se alimenta del Dashboard:
 * la pinta _eqRenderDistribucion() con lo que devuelve el AJAX de LA TABLA, o sea que
 * obedece a los filtros del listado. En móvil colocarDistribucionMovil() la MUDA dentro
 * de este modal, y entonces las dos cosas quedan en la misma caja respondiendo a fuentes
 * distintas: el Dashboard mostraba las cifras del frente elegido (Σ Equipos, gasoil) y la
 * card seguía en '--', porque la tabla de detrás no tenía filtro y esos '--' los pone el
 * Blade cuando !$hasFilter.
 *
 * Se sincroniza el filtro de frente del listado por el MISMO camino que usa el dropdown
 * de la tabla (selectOption + loadEquipos, ver el onclick de #frenteFilterSelect), para
 * no abrir una segunda forma de filtrar. loadEquipos va en modo SILENT: el modal ya tiene
 * su propio spinner y el global lo taparía entero.
 *
 * La condición es el estado REAL del DOM —la card metida en el hueco del modal— y no una
 * media query repetida: así el único que decide dónde vive la card sigue siendo
 * colocarDistribucionMovil(), y esto se limita al caso que confunde. En escritorio la card
 * se queda en el sidebar y el listado no se toca.
 *
 * @param {string} id    Frente elegido en el Dashboard ('' o 'all' = todos).
 * @param {string} name  Su nombre, para la etiqueta del dropdown de la tabla.
 */
function sincronizarDistribucionConFrente(id, name) {
    const slot = document.getElementById('fdmDistribucionSlot');
    const card = document.getElementById('distribucionCard');
    if (!slot || !card || card.parentElement !== slot) return;
    if (typeof window.selectOption !== 'function' || typeof window.loadEquipos !== 'function') return;

    const sinFrente = !id || id === 'all';
    // Con 'all' se repone la etiqueta por defecto que el propio dropdown declara en
    // data-default-label (cambia según el usuario: "Todos Mis Frentes" para los locales),
    // en vez de escribir aquí un texto que se desincronizaría del Blade.
    const dd = document.getElementById('frenteFilterSelect');
    const etiqueta = sinFrente ? ((dd && dd.dataset.defaultLabel) || 'TODOS LOS FRENTES') : name;

    window.selectOption('frenteFilterSelect', sinFrente ? 'all' : id, etiqueta);
    window.loadEquipos(true);   // true = silent: sin preloader global
}

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

    sincronizarDistribucionConFrente(id, name);
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
};

/**
 * Lazy-load html2canvas — solo cuando se pulsa Exportar. La inyeccion la hace el
 * cargador compartido (js/maquinaria/lazy_loader.js), que garantiza una sola descarga
 * aunque lo pidan varias pantallas.
 */
async function ensureHtml2Canvas() {
    try {
        await window.cargarScriptUnaVez(
            window.lazyBaseUrl() + '/js/html2canvas.min.js',
            () => typeof html2canvas !== 'undefined'
        );
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

        updateStatCards(data.stats);
        
        // 'Equipos Asignados por Frente' sale SIEMPRE; con un frente elegido, ese se resalta.
        const nombreElegido = (!frenteId || frenteId === 'all')
            ? '' : (document.getElementById('dashboardFrenteSearch') || {}).value;
        renderFleetEquiposAsignados(data.equiposPorFrente || [], nombreElegido);

        pintarPorTipo('chartAgeByType', data.ageByType, 'Sin equipos registrados para este frente.');
        pintarPorTipo('chartAuxByType', data.auxByType, 'Sin equipos auxiliares registrados.');

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
                message: 'No se pudieron cargar las estadísticas de la flota. Detalle: ' + error.message,
                confirmText: 'Cerrar',
                hideCancel: true
            });
        }
    }
}

/**
 * Gráfico "nuevo vs antiguo por tipo" (equipos y auxiliares), en HTML.
 *
 * En la cabecera de cada panel, el RESUMEN (total de cada grupo, que llena
 * updateStatCards): aquí se le pone su porcentaje y se pinta la barra de proporción;
 * sin datos no se muestra ninguno de los dos. Debajo, UNA FILA POR TIPO, de mayor a
 * menor y una debajo de otra: nombre | barra | desglose por serie y total. El largo de la
 * barra es relativo al tipo con más unidades, así se comparan los tipos entre sí; los
 * tramos son las series.
 */
function pintarPorTipo(contId, datos, textoVacio) {
    const cont = document.getElementById(contId);
    if (!cont) return;
    const panel = cont.closest('.fdm-panel');
    const esc = window.escapeHtml;

    const labels = (datos && datos.labels) || [];
    const series = ((datos && datos.datasets) || []).map(function (ds) { return ds.data || []; });
    const filas = labels.map(function (nombre, i) {
        const valores = series.map(function (d) { return Number(d[i]) || 0; });
        return { nombre: nombre, valores: valores, total: valores.reduce(function (a, b) { return a + b; }, 0) };
    }).filter(function (f) { return f.total > 0; })
      .sort(function (a, b) { return b.total - a.total || String(a.nombre).localeCompare(String(b.nombre)); });

    // Resumen: porcentaje de cada serie y barra de proporción.
    const totSerie = series.map(function (_, k) {
        return filas.reduce(function (a, f) { return a + f.valores[k]; }, 0);
    });
    const totGeneral = totSerie.reduce(function (a, b) { return a + b; }, 0);
    if (panel) {
        panel.querySelectorAll('.fdm-res[data-serie]').forEach(function (el) {
            const k = Number(el.dataset.serie);
            const pct = el.querySelector('.fdm-res-pct');
            if (pct) pct.textContent = totGeneral ? Math.round((totSerie[k] || 0) * 100 / totGeneral) + '%' : '—';
        });
        const prop = panel.querySelector('.fdm-res-prop');
        if (prop) {
            prop.innerHTML = totSerie.map(function (v, k) {
                return v > 0 ? '<i style="flex:' + v + ';background:' + FDM_COLORES[k] + '"></i>' : '';
            }).join('');
            prop.style.display = totGeneral ? '' : 'none';
        }
        const resumen = panel.querySelector('.fdm-resumen');
        if (resumen) resumen.style.display = totGeneral ? '' : 'none';
    }

    if (!filas.length) {
        cont.innerHTML = '<p class="fdm-vacio">' + esc(textoVacio) + '</p>';
        return;
    }

    const max = filas[0].total;
    const nombresSerie = ((datos && datos.datasets) || []).map(function (ds) { return ds.label || ''; });
    cont.innerHTML = filas.map(function (f) {
        const desglose = f.valores.map(function (v, k) {
            return v > 0
                ? '<span class="fdm-fila-serie" title="' + esc(nombresSerie[k]) + '"><i style="background:' + FDM_COLORES[k] + '"></i>' + v + '</span>'
                : '';
        }).join('');
        const tramos = f.valores.map(function (v, k) {
            return v > 0 ? '<i style="flex:' + v + ';background:' + FDM_COLORES[k] + '"></i>' : '';
        }).join('');
        return '<div class="fdm-fila">' +
                   '<span class="fdm-fila-nom">' + esc(f.nombre) + '</span>' +
                   '<div class="fdm-fila-pista"><div class="fdm-fila-barra" style="width:' + (f.total * 100 / max).toFixed(2) + '%">' + tramos + '</div></div>' +
                   '<span class="fdm-fila-cifras">' + desglose + '<b>' + f.total + '</b></span>' +
               '</div>';
    }).join('');
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

                // Un panel con scroll saldría RECORTADO a lo que se ve en pantalla (la card
                // de distribución lista ~50 tipos dentro de 62vh). En una foto debe salir la
                // lista completa. Solo se tocan los nodos que REALMENTE desbordan, así los
                // paneles sin scroll no cambian en nada.
                [clonedEl, ...clonedEl.querySelectorAll('*')].forEach(n => {
                    const ov = clonedDoc.defaultView.getComputedStyle(n).overflowY;
                    if (ov === 'auto' || ov === 'scroll') {
                        n.style.maxHeight = 'none';
                        n.style.overflow = 'visible';
                    }
                });
                
                // (Se quitó un bucle que buscaba <span>/<h4> con display:flex INLINE para
                //  centrarlos: los <h4> ya no existen en estos paneles y el display:flex de
                //  .fdm-panel-title vive en una clase CSS, no en el style inline — la
                //  condición nunca era cierta y el bucle no hacía nada.)

                // Force column layout for the "Equipos Asignados" panel list to avoid squeezing
                if (panelId === 'fdm-panel-assigned') {
                    const asigBody = clonedEl.querySelector('#fleetEqAsigBody > .fdm-asig-grid');
                    if (asigBody) {
                        asigBody.style.gridTemplateColumns = '1fr';
                        
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
    }).catch(err => {
        // Sin este catch el fallo era MUDO: se pulsaba la camara y no bajaba nada,
        // sin aviso ni error en consola (la promesa quedaba rechazada y ya).
        console.error('[descargarPanelHtmlFDM]', err);
        alert('No se pudo generar la imagen: ' + (err && err.message ? err.message : err));
    });
};
