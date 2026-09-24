/*
 * equipos_busqueda_masiva.js
 *
 * Este codigo vivia dentro del HTML de la vista y viajaba entero en CADA apertura del
 * modulo. Aqui se baja una sola vez y el navegador lo reutiliza.
 *
 * Es una FUNCION de arranque, no un bloque suelto, porque el modulo necesita volver a
 * correr en cada apertura para engancharse a la tabla nueva: la SPA no re-ejecuta un
 * <script src> ya cargado, asi que es el Blade quien llama a equiposBusquedaMasivaArrancar(CFG)
 * cada vez que se monta la pantalla. Lo que cambia entre una apertura y otra —rutas,
 * permisos y catalogos— llega en ese CFG.
 */
window.equiposBusquedaMasivaArrancar = function (EQBM_CFG) {
    EQBM_CFG = EQBM_CFG || {};
(function () {
    const URL_BULK_LOOKUP = EQBM_CFG.rutaEquiposBulkLookup;
    const MAX_TERMS = 2000;
    const csrf = window.getCsrf;   // helper central (dom_helpers.js)

    let lastMissingTerms = [];

    // escapeHtml lo aporta dom_helpers.js (global). La copia que vivia aqui era una
    // 'function' de nivel superior, asi que SOBRESCRIBIA window.escapeHtml para toda la
    // pagina — se elimino para que haya una sola fuente de verdad.

    function getTextarea() { return document.getElementById('bulkLookupTextarea'); }
    // Devuelve el input oculto del custom-dropdown de frente (tiene .value con el ID).
    function getFrenteSelect() { return document.getElementById('bulkLookupFrenteValue'); }

    // Unica fuente de verdad de "que vamos a buscar". Splittea por CUALQUIER
    // whitespace (espacio/tab/newline) — sirve para datos pegados desde Excel
    // (que vienen con \t y \r\n), CSV (con saltos de linea), o tipeado manual
    // (separado por espacios o cada uno en su linea). El textarea elimina el
    // bug del paste anterior donde >X filas no se distribuian correctamente.
    function collectTerms() {
        const raw = (getTextarea().value || '');
        return raw.split(/\s+/)
                  .map(s => s.trim().toUpperCase())
                  .filter(v => v !== '');
    }

    function updateCountHint() {
        const hint = document.getElementById('bulkLookupCountHint');
        if (!hint) return;
        const values = collectTerms();
        const unique = new Set(values);
        const dupes = values.length - unique.size;
        let html = unique.size + ' valor(es) único(s)';
        // Avisar duplicados en rojo: el backend deduplica antes de buscar.
        if (dupes > 0) {
            html += ' <span style="color:#dc2626;font-weight:700;">(' + dupes + ' duplicado(s) — se ignoran)</span>';
        }
        hint.innerHTML = html;
    }

    function clearInputs() {
        getTextarea().value = '';
        // Resetear el custom-dropdown de frente (valor + etiqueta + estilo) vía la
        // función global, no solo el hidden input, para dejar la UI consistente.
        if (typeof window.clearDropdownFilter === 'function') {
            window.clearDropdownFilter('bulkLookupFrenteDropdown');
        } else {
            const sel = getFrenteSelect();
            if (sel) sel.value = '';
        }
        updateCountHint();
    }

    // ── ABRIR / CERRAR / VOLVER ─────────────────────────────────────────────
    function showInputPhase() {
        // display:'' (no inline) → el CSS decide: block en escritorio, flex en
        // teléfono (media query) para que el textarea llene el alto disponible.
        document.getElementById('bulkLookupInputPhase').style.display = '';
        document.getElementById('bulkLookupResultsPhase').style.display = 'none';
        document.getElementById('bulkLookupBackBtn').style.display = 'none';
        document.getElementById('bulkLookupCopyMissingBtn').style.display = 'none';
        var movBtn = document.getElementById('bulkLookupMovilizarBtn');
        if (movBtn) movBtn.style.display = 'none';
        var detBtn = document.getElementById('bulkLookupDetalleBtn');
        if (detBtn) detBtn.style.display = 'none';
        document.getElementById('bulkLookupSearchBtn').style.display = 'flex';
        // Fase de pegado: el modal no necesita ser ancho (solo dropdown + textarea).
        var mcIn = document.querySelector('#bulkLookupModal .modal-content');
        if (mcIn) mcIn.style.maxWidth = '480px';
    }

    // Vuelca los equipos ENCONTRADOS en la selección global (reemplazándola: la
    // acción siempre opera sobre "éstos"). Lo reusan los botones Movilizar y Detalle
    // de Búsqueda Masiva para no duplicar el armado de la selección.
    function seleccionarEncontrados(found) {
        window.selectedEquipos = {};
        found.forEach(function (r) {
            window.selectedEquipos[r.id] = {
                id: r.id,
                code: r.codigo || '',
                placa: r.placa || '',
                chasis: r.chasis || '',
                tipo: r.tipo_nombre || '',
                frenteId: r.id_frente_actual || '',
                rolAnclaje: r.rol_anclaje || '',
                anchorId: r.anchor_id || null
            };
        });
        if (typeof window.updateSelectionUI === 'function') window.updateSelectionUI();
    }

    // Movilizar TODOS los equipos encontrados de una vez: los pasa a la selección
    // global y abre el modal de Movilización (openBulkModal) con ellos.
    window.movilizarEncontrados = function () {
        var found = window._bulkLookupFound || [];
        if (!found.length) {
            window.toast('No hay equipos encontrados para movilizar.', 'error');
            return;
        }
        if (window.CAN_ASSIGN_EQUIPOS === false || window.CAN_ASSIGN_EQUIPOS === 'false') {
            window.toast('No tienes permiso para movilizar equipos.', 'error');
            return;
        }
        seleccionarEncontrados(found);
        if (typeof window.closeBulkLookupModal === 'function') window.closeBulkLookupModal();
        if (typeof window.openBulkModal === 'function') window.openBulkModal();
    };

    // Asignar Detalle a los equipos encontrados: los pasa a la selección y abre el
    // modal "Asignar Detalle" (openUbicacionBulkModal), que valida permiso y "mismo
    // frente" por su cuenta.
    window.detalleEncontrados = function () {
        var found = window._bulkLookupFound || [];
        if (!found.length) {
            window.toast('No hay equipos encontrados para asignar detalle.', 'error');
            return;
        }
        if (window.CAN_ASSIGN_EQUIPOS === false || window.CAN_ASSIGN_EQUIPOS === 'false') {
            window.toast('No tienes permiso para actualizar detalles.', 'error');
            return;
        }
        seleccionarEncontrados(found);
        if (typeof window.closeBulkLookupModal === 'function') window.closeBulkLookupModal();
        if (typeof window.openUbicacionBulkModal === 'function') window.openUbicacionBulkModal();
    };

    window.openBulkLookupModal = function () {
        // Cierra otros popovers para no superponerlos.
        const adv = document.getElementById('advancedFilterPanel');
        if (adv) adv.style.display = 'none';
        const sm = document.getElementById('splitDropdownMenu');
        if (sm) sm.style.display = 'none';

        // Ocultar la barra flotante de selección mientras el modal esté abierto: su
        // z-index (9999) es mayor que el del modal (2500), así que se vería encima.
        const fbar = document.getElementById('bulkFloatingBar');
        if (fbar) fbar.style.display = 'none';

        showInputPhase();
        lastMissingTerms = [];
        clearInputs();

        const modal = document.getElementById('bulkLookupModal');
        modal.classList.add('active');
        setTimeout(() => { getTextarea().focus(); }, 50);
    };

    window.closeBulkLookupModal = function () {
        document.getElementById('bulkLookupModal').classList.remove('active');
        // Restaurar la barra flotante: el CSS (.active) decide si se ve según haya
        // o no selección. Si se cerró para movilizar/asignar detalle, el siguiente
        // modal queda por encima igual.
        const fbar = document.getElementById('bulkFloatingBar');
        if (fbar) fbar.style.display = '';
    };

    window.bulkLookupBack = showInputPhase;

    window.bulkLookupCopyMissing = function () {
        if (!lastMissingTerms.length) return;
        navigator.clipboard.writeText(lastMissingTerms.join('\n')).then(() => {
            window.toast(lastMissingTerms.length + ' término(s) no encontrado(s) copiado(s) al portapapeles.', 'success');
        }).catch(() => {
            window.toast('No se pudo copiar al portapapeles.', 'error');
        });
    };

    // ── RESULTADOS ──────────────────────────────────────────────────────────
    function renderResults(payload, frenteNombre) {
        const tbody = document.getElementById('bulkLookupResultsBody');
        const summary = document.getElementById('bulkLookupSummary');
        const yellowLegend   = document.getElementById('bulkLookupYellowLegend');
        const ambiguoLegend  = document.getElementById('bulkLookupAmbiguoLegend');
        const parcialLegend  = document.getElementById('bulkLookupParcialLegend');
        if (!tbody || !summary) return;

        const hayFiltroFrente = !!frenteNombre;
        const confirmed = payload.confirmed || 0;

        const compareEl = document.getElementById('bulkLookupFrenteCompare');
        if (compareEl) {
            if (frenteNombre) {
                compareEl.innerHTML = '<i class="material-icons" style="font-size: 15px;">flag</i> Comparando con: ' + escapeHtml(frenteNombre);
                compareEl.style.display = 'inline-flex';
            } else {
                compareEl.style.display = 'none';
            }
        }

        const results = payload.results || [];
        const found = payload.found || 0;
        const missing = payload.missing || 0;
        const total = payload.total || 0;
        const inOther = payload.in_other_frente || 0;

        let summaryHtml = `
            <span style="font-size: 12px; font-weight: 700; color: #334155;">Total: ${total}</span>
            <span style="font-size: 12px; font-weight: 700; color: #166534;">
                <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: #16a34a;">check_circle</i> Encontrados: ${found}
            </span>
        `;
        if (confirmed > 0) {
            summaryHtml += `
                <span style="font-size: 12px; font-weight: 700; color: #0369a1;">
                    <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: #0284c7;">verified</i> Confirmados en sitio: ${confirmed}
                </span>
            `;
        }
        // El backend NO confirma en sitio sin el permiso 'equipos.edit' (es una escritura).
        // Sin este aviso el usuario veía la búsqueda correcta y creía haber confirmado.
        if (payload.confirm_denied) {
            summaryHtml += `
                <span style="font-size: 12px; font-weight: 700; color: #854d0e;">
                    <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: #ca8a04;">lock</i> Sin permiso para confirmar en sitio
                </span>
            `;
        }
        if (inOther > 0) {
            summaryHtml += `
                <span style="font-size: 12px; font-weight: 700; color: #854d0e;">
                    <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: #ca8a04;">warning</i> En otro frente: ${inOther}
                </span>
            `;
        }
        summaryHtml += `
            <span style="font-size: 12px; font-weight: 700; color: ${missing > 0 ? '#991b1b' : '#475569'};">
                <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: ${missing > 0 ? '#dc2626' : '#94a3b8'};">cancel</i> No encontrados: ${missing}
            </span>
        `;
        // Con listas enormes el backend deja de intentar la búsqueda parcial (tope
        // BULK_PARCIAL_MAX). Se dice, porque si no esos "no encontrados" parecerían
        // buscados igual que el resto y no lo fueron.
        if ((payload.parcial_omitidos || 0) > 0) {
            summaryHtml += `
                <span style="font-size: 12px; font-weight: 700; color: #854d0e;"
                      title="Son demasiados para cruzarlos por coincidencia parcial. Búsquelos en una lista más corta.">
                    <i class="material-icons" style="font-size: 13px; vertical-align: -2px; color: #ca8a04;">more_horiz</i> Sin buscar por coincidencia parcial: ${payload.parcial_omitidos}
                </span>
            `;
        }
        summary.innerHTML = summaryHtml;

        if (confirmed > 0 && window.showToast) {
            window.showToast(confirmed + ' equipo(s) confirmado(s) en sitio.', 'success');
        }

        if (yellowLegend) yellowLegend.style.display = inOther > 0 ? 'block' : 'none';
        const hayAmbiguos = results.some(r => !r.found && (r.ambiguo || 0) > 1);
        const hayParciales = results.some(r => r.found && r.parcial);
        if (ambiguoLegend) ambiguoLegend.style.display = hayAmbiguos ? 'block' : 'none';
        if (parcialLegend) parcialLegend.style.display = hayParciales ? 'block' : 'none';

        lastMissingTerms = [];
        const cellBase    = "padding: 6px 10px; border-bottom: 1px solid #f1f5f9; color: #334155; word-break: break-word;";
        const cellMissing = "padding: 6px 10px; border-bottom: 1px solid #fee2e2; color: #b91c1c; word-break: break-word;";
        const cellOther   = "padding: 6px 10px; border-bottom: 1px solid #fde68a; color: #854d0e; word-break: break-word;";

        const estadoTexto = function (estado) {
            const e = (estado || 'N/A').toUpperCase();
            let col = '#475569';
            if (e === 'OPERATIVO') col = '#166534';
            else if (e === 'INOPERATIVO') col = '#991b1b';
            else if (e === 'EN MANTENIMIENTO') col = '#854d0e';
            else if (e === 'DESINCORPORADO') col = '#334155';
            return '<span style="font-size:11px; font-weight:700; color:' + col + '; white-space:nowrap;">' + escapeHtml(e) + '</span>';
        };

        const checkIcon = '<i class="material-icons" style="font-size:14px; color:#16a34a; vertical-align:-2px; margin-right:4px;">check_circle</i>';

        const rowsHtml = results.map(r => {
            if (!r.found) {
                lastMissingTerms.push(r.term);
                // Un fragmento que SI existe pero en varios equipos no es un "no existe":
                // decirselo asi deja al usuario reescribiendo lo mismo. Se le dice cuantos
                // son, que es la pista para que agregue digitos.
                const varios = (r.ambiguo || 0) > 1;
                const aviso  = varios
                    ? `Coincide con ${r.ambiguo} equipos — complete el serial`
                    : 'No encontrado en la base de datos';
                return `
                    <tr style="background: ${varios ? '#fffbeb' : '#fef2f2'};">
                        <td data-label="Buscado" style="${varios ? cellOther : cellMissing}">${escapeHtml(r.term)}</td>
                        <td colspan="3" style="${varios ? cellOther : cellMissing} font-style: italic;">
                            <i class="material-icons" style="font-size: 13px; vertical-align: -2px;">${varios ? 'filter_alt' : 'error_outline'}</i>
                            ${escapeHtml(aviso)}
                        </td>
                    </tr>
                `;
            }
            // Sin distintivo "AUX" (pedido del cliente): pegado al tipo se leía como parte del
            // nombre ("AUXPLANTA_ELECTRICA"). La respuesta sigue trayendo es_auxiliar, que es
            // lo que decide que estos NO entren en la selección para movilizar.
            const equipoInfo = [r.tipo_nombre, r.marca].filter(Boolean).join(' · ') || '—';
            const frente = r.frente_nombre === 'SIN ASIGNAR'
                ? '<span style="font-style: italic;">SIN ASIGNAR</span>'
                : escapeHtml(r.frente_nombre);
            const buscadoPrefix = (hayFiltroFrente && r.in_selected_frente) ? checkIcon : '';
            // r.parcial trae el valor COMPLETO contra el que caso el fragmento (null si fue
            // exacto). Se muestra debajo del termino: sin eso, quien escribe medio serial no
            // tiene como comprobar que el equipo devuelto es el que buscaba.
            const parcialNota = r.parcial
                ? `<div style="font-size:10.5px; color:#92400e; font-weight:600; margin-top:2px;">≈ ${escapeHtml(r.parcial)}</div>`
                : '';
            if (r.in_selected_frente === false) {
                return `
                    <tr style="background: #fef9c3;">
                        <td data-label="Buscado" style="${cellOther}">${escapeHtml(r.term)}${parcialNota}</td>
                        <td data-label="Equipo" style="${cellOther}">${escapeHtml(equipoInfo)}</td>
                        <td data-label="Estado" style="${cellOther}">${estadoTexto(r.estado)}</td>
                        <td data-label="Frente" style="${cellOther} text-align: center;">${frente}</td>
                    </tr>
                `;
            }
            return `
                <tr style="background: white;">
                    <td data-label="Buscado" style="${cellBase}">${buscadoPrefix}${escapeHtml(r.term)}${parcialNota}</td>
                    <td data-label="Equipo" style="${cellBase}">${escapeHtml(equipoInfo)}</td>
                    <td data-label="Estado" style="${cellBase}">${estadoTexto(r.estado)}</td>
                    <td data-label="Frente" style="${cellBase} text-align: center;">${frente}</td>
                </tr>
            `;
        }).join('');

        tbody.innerHTML = rowsHtml || '<tr><td colspan="4" style="padding: 14px; text-align: center; color: #94a3b8;">Sin resultados</td></tr>';

        document.getElementById('bulkLookupInputPhase').style.display = 'none';
        // display:'' (no inline) → el CSS decide: block en escritorio, flex en
        // teléfono para que la lista de tarjetas llene el alto disponible.
        document.getElementById('bulkLookupResultsPhase').style.display = '';
        // Fase de resultados: ensanchar para que la tabla (4 columnas) respire.
        var mcRes = document.querySelector('#bulkLookupModal .modal-content');
        if (mcRes) mcRes.style.maxWidth = '860px';
        document.getElementById('bulkLookupBackBtn').style.display = 'flex';
        document.getElementById('bulkLookupSearchBtn').style.display = 'none';
        document.getElementById('bulkLookupCopyMissingBtn').style.display = lastMissingTerms.length > 0 ? 'flex' : 'none';

        // Equipos ENCONTRADOS (con id) → para movilizarlos/asignarles detalle en bloque.
        // El filtro por `r.id` deja fuera a los AUXILIARES a propósito: la búsqueda también
        // los encuentra, pero el backend les manda id null porque no se movilizan por esta
        // vía. Por eso "Encontrados: N" del resumen puede ser mayor que el "(N)" del botón
        // Movilizar — la diferencia son los auxiliares.
        window._bulkLookupFound = results.filter(function (r) { return r.found && r.id; });
        var hayEncontrados = window._bulkLookupFound.length > 0;
        var movBtn = document.getElementById('bulkLookupMovilizarBtn');
        var movCnt = document.getElementById('bulkLookupMovilizarCount');
        var detBtn = document.getElementById('bulkLookupDetalleBtn');
        if (movBtn) {
            if (hayEncontrados && movCnt) movCnt.textContent = '(' + window._bulkLookupFound.length + ')';
            movBtn.style.display = hayEncontrados ? 'flex' : 'none';
        }
        if (detBtn) detBtn.style.display = hayEncontrados ? 'flex' : 'none';
    }

    window.runBulkLookup = function () {
        const terms = collectTerms();

        if (terms.length === 0) {
            if (window.showToast) window.showToast('Agrega al menos una placa o serial.', 'warning');
            else alert('Agrega al menos una placa o serial.');
            getTextarea().focus();
            return;
        }
        if (terms.length > MAX_TERMS) {
            if (window.showToast) window.showToast('Máximo ' + MAX_TERMS + ' términos por búsqueda. Cargados: ' + terms.length, 'error');
            else alert('Máximo ' + MAX_TERMS + ' términos por búsqueda.');
            return;
        }

        const frenteIdRaw = (getFrenteSelect() && getFrenteSelect().value) || '';
        const body = { terms: terms };
        // Nombre del frente seleccionado (para el rótulo "Comparando con: ..."):
        // lo leemos del item elegido en el dropdown. '' si no se filtró por frente.
        let frenteNombre = '';
        if (frenteIdRaw) {
            body.frente_id = parseInt(frenteIdRaw, 10);
            const selItem = document.querySelector('#bulkLookupFrenteDropdown .dropdown-item[data-value="' + frenteIdRaw + '"]');
            if (selItem) frenteNombre = selItem.textContent.trim();
        }

        if (window.showPreloader) window.showPreloader();
        window.apiFetch(URL_BULK_LOOKUP, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(body)
        })
        .then(r => r.json().then(d => ({ ok: r.ok, body: d })))
        .then(res => {
            if (window.hidePreloader) window.hidePreloader();
            if (!res.ok) {
                const msg = (res.body && res.body.message) || 'Error en la búsqueda.';
                if (window.showToast) window.showToast(msg, 'error');
                else alert(msg);
                return;
            }
            renderResults(res.body, frenteNombre);
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            console.error('[bulkLookup]', err);
            if (window.showToast) window.showToast('Error de red en la búsqueda masiva.', 'error');
            else alert('Error de red.');
        });
    };

    // ── BIND ────────────────────────────────────────────────────────────────
    // Delegado en document y con guarda, NO dentro de DOMContentLoaded: el SPA reinyecta
    // esta vista con innerHTML y vuelve a ejecutar este <script>, pero DOMContentLoaded
    // ya paso y no se dispara otra vez (ver executeScripts en navegacion.js). Atado al
    // nodo, ademas, el bind moria con el modal viejo en cada render.
    // Efecto real de aquello: llegando a /admin/equipos desde el menu, el textarea dejaba
    // de pasar a mayusculas y ni Escape ni el clic fuera cerraban el modal. Buscar seguia
    // funcionando porque los botones van por onclick, y por eso no se notaba.
    // La guarda evita apilar un juego de listeners por cada navegacion SPA.
    if (!window.__bulkLookupBound) {
        window.__bulkLookupBound = true;

        // Forzar mayusculas al teclear/pegar — backend tambien hace upper.
        document.addEventListener('input', function (e) {
            const ta = e.target;
            if (!ta || ta.id !== 'bulkLookupTextarea') return;
            const pos = ta.selectionStart;
            const upper = ta.value.toUpperCase();
            if (upper !== ta.value) {
                ta.value = upper;
                try { ta.setSelectionRange(pos, pos); } catch (_) {}
            }
            updateCountHint();
        });

        // Clic en el fondo del overlay (no en su contenido) = cerrar.
        document.addEventListener('click', function (e) {
            if (e.target && e.target.id === 'bulkLookupModal') window.closeBulkLookupModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            // Se busca el modal AQUI, no al atar: el de entonces ya no esta en el DOM.
            const modal = document.getElementById('bulkLookupModal');
            if (modal && modal.classList.contains('active')) window.closeBulkLookupModal();
        });
    }
})();
};
