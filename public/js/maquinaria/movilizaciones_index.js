/**
 * movilizaciones_index.js
 * Historial de movilizaciones (/admin/movilizaciones): filtros, búsqueda, paginación y filas.
 *
 * Todo sale de la COPIA LOCAL (window.CopiaLocal, dominio 'movilizaciones'): la misma
 * lógica con y sin internet, y sin esperar al servidor en cada filtro. El servidor ya no
 * filtra; el Excel se pide con los ids que dejaron estos filtros.
 * Compatible con SPA (navegacion.js): se inicializa en cada carga de contenido.
 */

// ─── Filtros compartidos ─────────────────────────────────────────────────────
// Filtros ACTUALES de la pantalla, leídos de sus propios controles.
//
// Punto ÚNICO: los usan la tabla (loadMovilizaciones), la URL de la pantalla y la
// exportación a Excel (_exportarHistorial).
window._mvFiltrosActuales = function () {
    // Scopear inputs al contenedor principal para evitar conflictos SPA
    const mvCard = document.querySelector('.movilizaciones-main-card');

    const getHiddenVal = (name, container) => {
        const el = (container || document).querySelector(`input[name="${name}"][data-filter-value]`);
        return el ? el.value.trim() : '';
    };

    const params = new URLSearchParams();

    // Búsqueda de texto libre
    const searchEl = document.getElementById('searchInput');
    if (searchEl && searchEl.value.trim()) {
        params.append('search', searchEl.value.trim());
    }

    // Filtro Frente
    const frenteVal = getHiddenVal('id_frente', mvCard);
    if (frenteVal && frenteVal !== 'all') {
        params.append('id_frente', frenteVal);
    }

    // Filtro Tipo
    const tipoVal = getHiddenVal('id_tipo', mvCard);
    if (tipoVal && tipoVal !== 'all') {
        params.append('id_tipo', tipoVal);
    }

    // Rango de fechas
    const fechaDesde = document.getElementById('filterFechaDesde');
    if (fechaDesde && fechaDesde.value) params.append('fecha_desde', fechaDesde.value);

    const fechaHasta = document.getElementById('filterFechaHasta');
    if (fechaHasta && fechaHasta.value) params.append('fecha_hasta', fechaHasta.value);

    // Dirección del frente
    const direccion = document.getElementById('filterDireccionFrente');
    if (direccion && direccion.value) params.append('direccion_frente', direccion.value);

    return params;
};

// ─── Filtrar la copia local ──────────────────────────────────────────────────
// Las reglas de siempre del historial: MV-123 busca el N° de control, DD-MM-AAAA el código
// de patio, #123 la etiqueta, y cualquier otro texto el serial, la placa o el código del
// equipo, o el serial, la marca o el modelo del auxiliar.
const MV_POR_PAGINA = 16;

function _mvContiene(valor, texto) {
    return valor != null && String(valor).toUpperCase().indexOf(texto) !== -1;
}

function _mvCoincideBusqueda(m, search) {
    const S = search.toUpperCase();
    const e = m.equipo, a = m.aux;
    if (/^MV-?\d+/i.test(search)) {
        const limpio = S.replace(/MV-?/, '').replace(/^0+/, '');
        return _mvContiene(m.codigo, S) || _mvContiene(m.codigo, limpio);
    }
    if (/\d{2}-\d{2}-\d{4}/.test(search)) return !!e && _mvContiene(e.codigo, S);
    if (search.charAt(0) === '#') return !!e && _mvContiene(e.etiqueta, S.slice(1));
    return (!!e && (_mvContiene(e.serial, S) || _mvContiene(e.codigo, S) || _mvContiene(e.placa, S)))
        || (!!a && (_mvContiene(a.serial, S) || _mvContiene(a.marca, S) || _mvContiene(a.modelo, S)));
}

window._mvFiltrar = function (filas, p) {
    const search = p.get('search') || '';
    const frente = p.get('id_frente') ? Number(p.get('id_frente')) : null;
    const direccion = p.get('direccion_frente') || '';
    const tipo = p.get('id_tipo') || '';
    const desde = p.get('fecha_desde') || '';
    const hasta = p.get('fecha_hasta') || '';

    return filas.filter(function (m) {
        if (search && !_mvCoincideBusqueda(m, search)) return false;
        if (frente !== null) {
            const entra = m.idDestino === frente, sale = m.idOrigen === frente;
            if (direccion === 'entrada' ? !entra : direccion === 'salida' ? !sale : !(entra || sale)) return false;
        }
        if (tipo.indexOf('tipo_aux:') === 0) {
            if (!m.aux || m.aux.tipo !== tipo.slice(9)) return false;
        } else if (tipo) {
            const idTipo = Number(tipo.indexOf('tipo_eq:') === 0 ? tipo.slice(8) : tipo);
            if (!m.equipo || m.equipo.idTipo !== idTipo) return false;
        }
        const dia = (m.fecha || '').slice(0, 10);
        if (desde && dia < desde) return false;
        if (hasta && dia > hasta) return false;
        return true;
    }).sort(function (x, y) {
        return x.fecha === y.fecha ? y.id - x.id : (x.fecha < y.fecha ? 1 : -1);
    });
};

// Descarga el historial FILTRADO en XLSX: un POST con los ids que se están viendo (y los
// filtros, que solo ponen el subtítulo). Un formulario normal deja que el navegador maneje
// la descarga y el nombre del archivo (Content-Disposition). El Excel lo arma el servidor:
// sin conexión el envío sacaría al usuario a la página de error del navegador.
window._exportarHistorial = function () {
    if (!navigator.onLine || (window._mvVista && !window._mvVista.estado.conectado)) {
        window.toast('Para exportar a Excel hace falta conexión a internet.', 'error');
        return;
    }
    const params = window._mvFiltrosActuales();
    const filas = window._mvVista ? window._mvFiltrar(window._mvVista.filas('movilizaciones'), params) : [];
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/admin/movilizaciones/export';
    form.setAttribute('data-no-spa', 'true');
    form.style.display = 'none';
    const campo = function (nombre, valor) {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = nombre; i.value = valor;
        form.appendChild(i);
    };
    campo('_token', window.getCsrf());
    params.forEach(function (v, k) { campo(k, v); });
    filas.forEach(function (m) { campo('ids[]', m.id); });
    document.body.appendChild(form);
    form.submit();
    setTimeout(function () { if (form.parentNode) form.parentNode.removeChild(form); }, 800);
};

// ─── Filas ───────────────────────────────────────────────────────────────────
function _mvFoto(fotoId) {
    return fotoId
        ? '<div class="table-image-wrapper"><img src="/storage/google/' + encodeURIComponent(fotoId) + '?sz=w120" alt="Foto"></div>'
        : '<div class="table-image-wrapper placeholder"><span class="material-icons">image_not_supported</span></div>';
}

function _mvFila(m, superAdmin) {
    const h = window.escapeHtml;
    const e = m.equipo, a = m.aux;
    const fecha = m.fecha ? new Date(m.fecha.replace(' ', 'T')) : null;
    const dd = function (n) { return String(n).padStart(2, '0'); };
    const dia = fecha ? dd(fecha.getDate()) + '/' + dd(fecha.getMonth() + 1) + '/' + fecha.getFullYear() : '--';
    const hora = fecha ? dd(fecha.getHours() % 12 || 12) + ':' + dd(fecha.getMinutes()) + ' ' + (fecha.getHours() < 12 ? 'AM' : 'PM') : '';
    const datos = function (tipo, lineas) {
        return '<div style="display: flex; flex-direction: column; flex: 1; min-width: 0; word-break: break-word; overflow-wrap: break-word;">'
            + '<span style="font-size: 13px; color: #718096; font-weight: 700; text-transform: uppercase;">' + h(tipo) + '</span>' + lineas + '</div>';
    };

    const equipo = a
        ? _mvFoto(a.fotoId) + datos(a.tipoLabel || 'AUXILIAR',
            '<div style="color: #4a5568; font-size: 13px;"><strong>S:</strong> ' + h(a.serial || '—') + '</div>'
            + '<div style="color: #475569; font-size: 12.5px; text-transform: uppercase;">' + h((a.marca || '') + ' ' + (a.modelo || '')) + '</div>')
        : _mvFoto(e && e.fotoId) + datos((e && e.tipo) || 'N/A',
            '<div style="color: #4a5568; font-size: 13px;"><strong>S:</strong> ' + h((e && e.serial) || 'S/S') + '</div>'
            + '<div style="color: var(--maquinaria-blue); font-size: 13px;"><strong>P:</strong> ' + h((e && e.placa) || 'S/P') + '</div>');

    const tramo = function (etiqueta, color, nombre, peso, colorNombre) {
        return '<div class="mv-trayecto-item" style="display: flex; flex-direction: column; align-items: center; max-width: 160px; text-align: center;">'
            + '<span class="mv-trayecto-label" style="font-size: 11px; color: ' + color + '; font-weight: 800; text-transform: uppercase;">' + etiqueta + '</span>'
            + '<span class="mv-frente-nombre" style="font-weight: ' + peso + '; color: ' + colorNombre + '; font-size: 13px; line-height: 1.2;">' + h(nombre) + '</span></div>';
    };

    // Sin N° de control no hay acta (p. ej. una actualización de ubicación): ni icono ni hueco.
    const acta = m.mv
        ? '<a href="/admin/movilizaciones/' + m.id + '/acta-traslado" class="mv-acta" data-titulo="Acta de Traslado ' + h(m.mv) + '"'
          + ' data-no-spa="true" target="_blank" rel="noopener" title="Ver Acta de Traslado (PDF)"'
          + ' style="font-weight: 800; color: #0067b1; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;">'
          + '<i class="material-icons" style="font-size: 16px;">picture_as_pdf</i>' + h(m.mv) + '</a>'
        : '';
    // Deshacer: devuelve el equipo a su frente de ORIGEN y borra el registro. Solo super.admin.
    const deshacer = superAdmin
        ? '<button type="button" onclick="window.movDeshacer(' + m.id + ')" title="Deshacer: devolver el equipo a su frente de origen"'
          + ' aria-label="Deshacer movilización" class="mv-undo-btn"><i class="material-icons">undo</i><span class="mv-undo-text">Deshacer</span></button>'
        : '';
    const estado = (m.tipoMov === 'RECEPCION_DIRECTA' || m.tipoMov === 'ACT.')
        ? '<span style="color: #3730a3;">ACTUALIZACIÓN DE UBICACIÓN</span>'
        : '<i class="material-icons" style="font-size: 16px; color: #1e40af;">swap_horiz</i><span style="color: #1e40af;">MOVILIZACIÓN</span>';

    return '<tr class="mv-row-card mv-selectable-row" data-mv-id="' + m.id + '">'
        + '<td class="mv-td-equipo"><div style="display: flex; align-items: center; justify-content: flex-start; gap: 10px;">' + equipo + '</div></td>'
        + '<td class="mv-td-trayecto"><div class="mv-trayecto-container" style="display: flex; align-items: center; justify-content: center; gap: 12px; word-break: break-word; overflow-wrap: break-word;">'
        +   tramo('Origen', '#64748b', m.origen || 'Sin Origen', 600, '#4a5568')
        +   '<i class="material-icons mv-trayecto-arrow" style="font-size: 18px; color: #cbd5e0; flex-shrink: 0;">east</i>'
        +   tramo('Destino', '#0067b1', m.destino || 'Sin Destino', 700, 'var(--maquinaria-dark-blue)')
        + '</div></td>'
        + '<td class="mv-td-fechas"><div style="display: flex; flex-direction: column; align-items: center; line-height: 1.2; justify-content: center; height: 100%; gap: 4px;">'
        +   '<div class="mv-meta" style="display: flex; align-items: center; gap: 4px; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; border: 1px solid #e2e8f0;">'
        +     '<i class="material-icons" style="font-size: 16px; color: #64748b;">event</i><span style="font-size: 13px; color: #334155; font-weight: 700;">' + dia + '</span></div>'
        +   (hora ? '<div class="mv-meta" style="font-size: 11px; color: #94a3b8; font-weight: 600; display: flex; align-items: center; gap: 3px;"><i class="material-icons" style="font-size: 12px;">schedule</i>' + hora + '</div>' : '')
        + '</div></td>'
        + '<td class="mv-col-op"><div style="display: flex; flex-direction: column; align-items: center; line-height: 1.2; gap: 2px;">'
        +   acta
        +   '<div class="mv-meta" style="display: flex; align-items: center; gap: 4px; color: #64748b; font-size: 13px; font-weight: 600;"><i class="material-icons" style="font-size: 15px;">person</i>' + h(m.usuario || '') + '</div>'
        +   deshacer
        + '</div></td>'
        + '<td class="mv-td-estado"><div style="display: flex; align-items: center; justify-content: center; gap: 5px; font-size: 11px; font-weight: 800;">' + estado + '</div></td>'
        + '</tr>';
}

function _mvMensaje(texto) {
    return '<tr><td colspan="5" style="padding: 60px 20px; border: none; background: transparent;">'
        + '<div style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: #94a3b8; border: 1px dashed #cbd5e0; border-radius: 12px; padding: 60px 20px;">'
        + '<i class="material-icons" style="font-size: 48px; opacity: 0.3;">local_shipping</i>'
        + '<p style="font-weight: 600; font-size: 14px; margin: 0;">' + texto + '</p></div></td></tr>';
}

// ─── Pintar ──────────────────────────────────────────────────────────────────
window._mvPagina = 1;

window.loadMovilizaciones = function (pagina) {
    const tableBody = document.getElementById('movilizacionesTableBody');
    if (!tableBody) return; // No estamos en la sección de movilizaciones

    const vista = window._mvVista;
    const params = window._mvFiltrosActuales();
    const pag = document.getElementById('movilizacionesPagination');
    const totalEl = document.getElementById('totalTransitoCount');

    if (!vista || !vista.estado.listo) {
        tableBody.innerHTML = _mvMensaje(vista && !vista.estado.conectado
            ? 'Sin conexión: este equipo todavía no tiene el historial guardado.'
            : 'Cargando historial…');
        if (pag) pag.innerHTML = '';
        if (totalEl) totalEl.innerText = '—';
        if (vista) window.pintarEstadoCopia('mvCopiaEstado', vista.estado);
        return;
    }

    const filas = window._mvFiltrar(vista.filas('movilizaciones'), params);
    const ultima = Math.max(1, Math.ceil(filas.length / MV_POR_PAGINA));
    window._mvPagina = Math.min(Math.max(1, Number(pagina) || 1), ultima);
    const desde = (window._mvPagina - 1) * MV_POR_PAGINA;
    const superAdmin = tableBody.dataset.superAdmin === '1';

    tableBody.innerHTML = filas.length
        ? filas.slice(desde, desde + MV_POR_PAGINA).map(function (m) { return _mvFila(m, superAdmin); }).join('')
        : _mvMensaje('No se encontraron movilizaciones registradas.');
    if (pag) pag.innerHTML = window.paginadorHtml(window._mvPagina, ultima);
    if (totalEl) totalEl.innerText = filas.length;
    window.pintarEstadoCopia('mvCopiaEstado', vista.estado);

    // La URL refleja los filtros y la página (F5 o compartir el enlace vuelven aquí).
    if (window._mvPagina > 1) params.set('page', window._mvPagina);
    if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '/admin/movilizaciones' + (params.toString() ? '?' + params.toString() : ''));
    }
    window.reapplyStylesMv();
};

// ─── Paginación y acta (delegación de eventos, una sola vez) ─────────────────
// stopImmediatePropagation() es CRÍTICO: evita que navegacion.js (SPA) también
// capture el click y haga una navegación completa.
if (!window._mvPaginationRegistered) {
    window._mvPaginationRegistered = true;
    document.addEventListener('click', function (e) {
        const link = e.target.closest('#movilizacionesPagination a.page-link');
        if (link) {
            e.preventDefault();
            e.stopImmediatePropagation(); // Impide que el SPA capture este click
            window.loadMovilizaciones(link.dataset.page);
            return;
        }
        // El acta se abre en el visor de PDF global; el controlador la responde inline.
        const acta = e.target.closest('#movilizacionesTableBody a.mv-acta');
        if (acta && typeof window.openPdfPreview === 'function') {
            e.preventDefault();
            e.stopImmediatePropagation();
            window.openPdfPreview(acta.href, 'acta_traslado', acta.dataset.titulo, 0, '', true, 'movilizaciones');
        }
    });
}

// La copia se puso al día (o cambió la conexión): se repinta la página en la que se está.
if (!window._mvCopiaRegistered) {
    window._mvCopiaRegistered = true;
    window.addEventListener('copia-local', function (e) {
        if (e.detail.dominio !== 'movilizaciones' || !document.getElementById('movilizacionesTableBody')) return;
        if (e.detail.cambio) window.loadMovilizaciones(window._mvPagina);
        else window.pintarEstadoCopia('mvCopiaEstado', e.detail.estado);
    });
}

// ─── Dropdowns: Frente y Tipo (delegación de eventos, una sola vez) ──────────
// selectOption() en uicomponents.js dispara 'dropdown-selection' al cambiar valor.
if (!window._mvDropdownSelectionRegistered) {
    window._mvDropdownSelectionRegistered = true;
    window.addEventListener('dropdown-selection', function (e) {
        if (!document.getElementById('movilizacionesTableBody')) return;
        if (e.detail.dropdownId === 'frenteFilterSelect' || e.detail.dropdownId === 'tipoFilterSelect') {
            window.loadMovilizaciones();
        }
    });
}

// ─── Inicialización (carga directa o via SPA) ────────────────────────────────
function _mvInit() {
    const tbody = document.getElementById('movilizacionesTableBody');
    if (!tbody) return;

    // Sincronizar estado del panel de filtros avanzados con el DOM
    const panel = document.getElementById('advancedFilterPanel');
    if (panel) window.advancedFilterOpen = panel.style.display === 'block';

    const pagina = new URLSearchParams(window.location.search).get('page');
    window.loadMovilizaciones(pagina);   // "Cargando…" hasta que abra la copia
    window.CopiaLocal.usar('movilizaciones', tbody).then(function (vista) {
        window._mvVista = vista;
        if (document.getElementById('movilizacionesTableBody') === tbody) window.loadMovilizaciones(pagina);
    });
}

// Carga directa (F5 o URL directa)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _mvInit);
} else {
    _mvInit();
}

// Navegación interna vía SPA
window.addEventListener('spa:contentLoaded', _mvInit);


// ═══════════════════════════════════════════════════════════════════════════════
// FILTROS AVANZADOS (Fechas y Dirección)
// ═══════════════════════════════════════════════════════════════════════════════

window.advancedFilterOpen = false;

window.toggleAdvancedFilter = function (e) {
    if (e) e.stopPropagation();
    const panel = document.getElementById('advancedFilterPanel');
    const btn = document.getElementById('btnAdvancedFilter');
    const accionesMenu = document.getElementById('splitDropdownMenuMov');
    if (!panel) return;

    if (accionesMenu && accionesMenu.style.display === 'block') {
        accionesMenu.style.display = 'none';
    }

    window.advancedFilterOpen = !window.advancedFilterOpen;

    if (window.advancedFilterOpen) {
        panel.style.display = 'block';
        btn.style.background = '#e1effa';
        btn.style.borderColor = '#0067b1';
        btn.style.color = '#0067b1';
    } else {
        panel.style.display = 'none';
        btn.style.background = 'white';
        btn.style.borderColor = '#cbd5e0';
        btn.style.color = '#64748b';
    }
};

window.toggleAccionesMov = function (e) {
    if (e) e.stopPropagation();
    const menu = document.getElementById('splitDropdownMenuMov');
    if (!menu) return;

    if (window.advancedFilterOpen) {
        window.toggleAdvancedFilter();
    }

    menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
};

// Cerrar panel al hacer click fuera
if (!window._mvPanelClickListenerRegistered) {
    window._mvPanelClickListenerRegistered = true;
    document.addEventListener('click', function (e) {
        const panel = document.getElementById('advancedFilterPanel');
        const btn = document.getElementById('btnAdvancedFilter');
        if (panel && window.advancedFilterOpen) {
            if (!panel.contains(e.target) && btn && !btn.contains(e.target)) {
                panel.style.display = 'none';
                btn.style.background = 'white';
                btn.style.borderColor = '#cbd5e0';
                btn.style.color = '#64748b';
                window.advancedFilterOpen = false;
            }
        }

        const accionesMenu = document.getElementById('splitDropdownMenuMov');
        const btnAcciones = document.getElementById('btnAccionesMov');
        if (accionesMenu && accionesMenu.style.display === 'block') {
            if (!accionesMenu.contains(e.target) && btnAcciones && !btnAcciones.contains(e.target)) {
                accionesMenu.style.display = 'none';
            }
        }
    });
}

window.clearDateFilters = function () {
    const desde = document.getElementById('filterFechaDesde');
    const hasta = document.getElementById('filterFechaHasta');
    if (desde) desde.value = '';
    if (hasta) hasta.value = '';
    window.setDireccionFilter('', false);
    window.loadMovilizaciones();
};

window.setDireccionFilter = function (value, reload = true) {
    const input = document.getElementById('filterDireccionFrente');
    if (input) input.value = value;

    const styles = {
        filterDireccionTodas: { active: !value, border: '#0067b1', bg: '#e1effa', color: '#0067b1' },
        filterDireccionEntrada: { active: value === 'entrada', border: '#16a34a', bg: '#dcfce7', color: '#16a34a' },
        filterDireccionSalida: { active: value === 'salida', border: '#dc2626', bg: '#fee2e2', color: '#dc2626' }
    };

    Object.entries(styles).forEach(([id, s]) => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.style.border = `1px solid ${s.active ? s.border : '#e2e8f0'}`;
        btn.style.background = s.active ? s.bg : 'white';
        btn.style.color = s.active ? s.color : '#64748b';
    });

    // Indicador en el botón principal
    const btnAdv = document.getElementById('btnAdvancedFilter');
    const fechaDesde = document.getElementById('filterFechaDesde');
    const fechaHasta = document.getElementById('filterFechaHasta');
    if (btnAdv) {
        const anyActive = value || fechaDesde?.value || fechaHasta?.value;
        btnAdv.style.background = anyActive ? '#e1effa' : 'white';
        btnAdv.style.borderColor = anyActive ? '#0067b1' : '#cbd5e0';
        btnAdv.style.color = anyActive ? '#0067b1' : '#64748b';
    }

    if (reload) window.loadMovilizaciones();
};


// ═══════════════════════════════════════════════════════════════════════════════
// SELECCIÓN Y ELIMINACIÓN (SPA COMPATIBLE)
// ═══════════════════════════════════════════════════════════════════════════════

// Set global de IDs seleccionados — persiste entre cambios de página
if (!window._mvSelectedIds) {
    window._mvSelectedIds = new Set();
}

// (El cierre del dropdown de Acciones al clic fuera ya lo maneja el listener con
//  guard _mvPanelClickListenerRegistered de arriba — no se duplica aquí.)

// ── Aplica/quita el estilo de selección en una fila ──
window.applyRowStyleMv = function (tr, selected) {
    if (selected) {
        tr.classList.add('selected-row-maquinaria');
    } else {
        tr.classList.remove('selected-row-maquinaria');
    }
}

// ── Actualiza el chip contador ──
window.updateChipMv = function () {
    const chip = document.getElementById('mv-selection-chip');
    const count = document.getElementById('mv-selection-count');
    if (!chip || !count) return;

    const n = window._mvSelectedIds.size;
    count.textContent = n;
    if (n > 0) {
        chip.classList.add('active');
    } else {
        chip.classList.remove('active');
    }
}

// ── Re-aplica estilos a todas las filas visibles tras pintar la tabla ──
window.reapplyStylesMv = function () {
    // En móvil, selección ⟺ despliegue: una tarjeta seleccionada debe reabrir su
    // detalle tras repintar el tbody (paginación), no quedar azul y colapsada.
    const esMovil = window.innerWidth <= 1024;
    document.querySelectorAll('.mv-selectable-row').forEach(tr => {
        const id = tr.dataset.mvId;
        const sel = window._mvSelectedIds.has(id);
        window.applyRowStyleMv(tr, sel);
        if (esMovil) tr.classList.toggle('mv-expanded', sel);
    });
    window.updateChipMv();
}

// ── Delegación global de clicks (registrada UNA sola vez por sesión) ──
if (!window._mvRowClickRegistered) {
    window._mvRowClickRegistered = true;

    document.addEventListener('click', function (e) {
        // Ignorar cualquier botón, enlace o dropdown (no deben togglear nada).
        if (e.target.closest('.custom-dropdown') || e.target.closest('button') || e.target.closest('a')) return;

        const tr = e.target.closest('.mv-selectable-row');
        if (!tr) return;

        // ── Móvil (tarjeta): el tap SELECCIONA la tarjeta (azul, IGUAL que los
        // demás módulos: equipos, historial, etc.) y a la vez DESPLIEGA su detalle
        // (fecha/hora + PDF + usuario + deshacer). Selección y despliegue van
        // atados: seleccionada ⟺ desplegada, así el azul de selección es también
        // la señal de "abierta". Es multi-selección (para borrado en lote de
        // super.admin), por eso NO se cierran las demás. El layout de tarjeta se
        // activa a <=1024px (ver estilos_globales.css).
        if (window.innerWidth <= 1024) {
            const mRowId = tr.dataset.mvId;
            if (!mRowId) return;
            if (window._mvSelectedIds.has(mRowId)) {
                window._mvSelectedIds.delete(mRowId);
                window.applyRowStyleMv(tr, false);
                tr.classList.remove('mv-expanded');
            } else {
                window._mvSelectedIds.add(mRowId);
                window.applyRowStyleMv(tr, true);
                tr.classList.add('mv-expanded');
            }
            window.updateChipMv();
            return;
        }

        // ── Desktop (tabla): el click SELECCIONA la fila (para eliminar en lote) ──
        const rowId = tr.dataset.mvId;
        if (!rowId) return;

        if (window._mvSelectedIds.has(rowId)) {
            window._mvSelectedIds.delete(rowId);
            window.applyRowStyleMv(tr, false);
        } else {
            window._mvSelectedIds.add(rowId);
            window.applyRowStyleMv(tr, true);
        }
        window.updateChipMv();
    });
}

// ── Limpiar selección ──
window.mvClearSelection = function () {
    window._mvSelectedIds.clear();
    document.querySelectorAll('.selected-row-maquinaria').forEach(tr => tr.classList.remove('selected-row-maquinaria'));
    // Selección ⟺ despliegue en móvil: al limpiar la selección, colapsar el detalle.
    document.querySelectorAll('#movilizacionesTable tr.mv-row-card.mv-expanded').forEach(tr => tr.classList.remove('mv-expanded'));
    window.updateChipMv();
};

// ── Aplicar al cargar la página en SPA
window.addEventListener('spa:contentLoaded', function () {
    if (window._mvSelectedIds) {
        window._mvSelectedIds.clear();
    }
    window.reapplyStylesMv();
});
