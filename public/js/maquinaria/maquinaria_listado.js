/*
 * maquinaria_listado.js
 *
 * Este codigo vivia dentro del HTML de la vista y viajaba entero en CADA apertura del
 * modulo. Aqui se baja una sola vez y el navegador lo reutiliza.
 *
 * Es una FUNCION de arranque, no un bloque suelto, porque el modulo necesita volver a
 * correr en cada apertura para engancharse a la tabla nueva: la SPA no re-ejecuta un
 * <script src> ya cargado, asi que es el Blade quien llama a maquinariaArrancar(CFG)
 * cada vez que se monta la pantalla. Lo que cambia entre una apertura y otra —rutas,
 * permisos y catalogos— llega en ese CFG.
 */
window.maquinariaArrancar = function (MAQ_CFG) {
    MAQ_CFG = MAQ_CFG || {};
(function () {
    // En /admin/equipos, window.cargarAuxiliares (con la que las acciones de aquí refrescan
    // la lista tras cambiar un auxiliar) la define resources/views/admin/equipos/index.blade.php:
    // recarga con loadEquipos, respetando los filtros activos.

    // ═══════════════════════════════════════════════════════════
    // SELECCION de auxiliares (patron /admin/equipos — row click)
    // Click en cualquier parte de la fila alterna selecto/deselecto.
    // Guarda { id -> { id, codigo, frente, frenteId, tipo } } en window._auxSelectedMap.
    // La barra, "ver solo seleccionados", Detalle y Movilización son los de equipos
    // (equipos_index.js), que trabajan con equipos y auxiliares a la vez.
    // ═══════════════════════════════════════════════════════════
    window._auxSelectedMap = window._auxSelectedMap || {};

    window.auxToggleRow = function (tr) {
        if (!tr || !tr.dataset.auxId) return;
        const id = tr.dataset.auxId;
        if (id in window._auxSelectedMap) {
            delete window._auxSelectedMap[id];
            tr.classList.remove('selected-row-maquinaria');
            // En modo "solo seleccionados", ocultar la fila al deseleccionar.
            if (window._auxSoloSel) tr.style.display = 'none';
        } else {
            window._auxSelectedMap[id] = {
                id: id,
                codigo: tr.dataset.codigo || ('#' + id),
                frente: tr.dataset.frente || '',
                frenteId: tr.dataset.frenteId || '',
                tipo: tr.dataset.tipo || ''
            };
            tr.classList.add('selected-row-maquinaria');
        }
        window.auxRefreshBulkBar();
    };

    // La barra es la de equipos: cuenta los dos.
    window.auxRefreshBulkBar = function () {
        if (typeof window.updateSelectionUI === 'function') window.updateSelectionUI();
    };

    window.auxClearSelection = function () {
        window._auxSelectedMap = {};
        // Por tr[data-aux-id]: en #equiposTableBody conviven filas de equipos y de
        // auxiliares, y esto solo debe tocar las de auxiliares.
        document.querySelectorAll('tr[data-aux-id].selected-row-maquinaria').forEach(tr => {
            tr.classList.remove('selected-row-maquinaria');
        });
        // Con "solo seleccionados" activo, sin auxiliares elegidos sus filas se ocultan todas
        // (el modo lo apaga la barra cuando ya no queda nada seleccionado).
        if (window._auxSoloSel) {
            document.querySelectorAll('tr[data-aux-id]').forEach(tr => { tr.style.display = 'none'; });
        }
        window.auxRefreshBulkBar();
    };

    // Restaurar highlight tras AJAX refresh de la tabla
    window.auxRestoreSelection = function () {
        document.querySelectorAll('tr[data-aux-id]').forEach(tr => {
            const inSel = tr.dataset.auxId in window._auxSelectedMap;
            if (inSel) tr.classList.add('selected-row-maquinaria');
            // Filtro de visibilidad de "solo seleccionados" (lo enciende y apaga la barra).
            tr.style.display = (!window._auxSoloSel || inSel) ? '' : 'none';
        });
        window.auxRefreshBulkBar();
    };

    // Delegacion de click en filas (solo con can:equipos.edit via clase)
    if (!window._auxRowClickBound) {
        window._auxRowClickBound = true;
        document.addEventListener('click', function (e) {
            const tr = e.target.closest('tr.aux-row-clickable[data-aux-id]');
            if (!tr) return;
            // Ignorar clicks en elementos interactivos o en la celda de acciones
            // (asi el click sobre el boton del ojo NO selecciona la fila aunque
            // caiga sobre el padding del TD).
            if (e.target.closest('button, a, input, .aux-status-trigger, .material-icons, .btn-details-mini, .aux-action-cell')) return;
            window.auxToggleRow(tr);
        });
    }


    if (!window.auxAccionesOutsideBound) {
        window.auxAccionesOutsideBound = true;
        document.addEventListener('click', (e) => {
            // Cerrar menu de estado de fila
            const sm = document.getElementById('auxStatusMenu');
            if (sm && !sm.contains(e.target) && !e.target.closest('.aux-status-trigger')) sm.style.display = 'none';
        });
    }

    // ── Menu de cambio de estado (estilo /admin/equipos openSharedStatusMenu) ──
    const AUX_STATUS_CFG = {
        'OPERATIVO':      { color: '#16a34a', bg: '#f0fdf4', icon: 'check_circle', label: 'Operativo' },
        'INOPERATIVO':    { color: '#dc2626', bg: '#fef2f2', icon: 'cancel',       label: 'Inoperativo' },
        'EN MANTENIMIENTO': { color: '#eab308', bg: '#fefce8', icon: 'build',        label: 'En Mantenimiento' },
        'EN_ALMACEN':     { color: '#1e40af', bg: '#eff6ff', icon: 'inventory_2',  label: 'En Almacén' },
        'DESINCORPORADO': { color: '#475569', bg: '#f1f5f9', icon: 'block',        label: 'Desincorp.' },
    };

    function getOrCreateAuxStatusMenu() {
        let menu = document.getElementById('auxStatusMenu');
        if (menu) return menu;
        menu = document.createElement('div');
        menu.id = 'auxStatusMenu';
        menu.style.cssText = 'position:absolute; display:none; min-width:200px; background:white; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 25px -5px rgba(15,23,42,0.18); overflow:hidden; z-index:9999;';
        document.body.appendChild(menu);
        return menu;
    }

    let _auxStatusTrigger = null;

    window.openAuxStatusMenu = function (trigger) {
        const menu = getOrCreateAuxStatusMenu();
        if (_auxStatusTrigger === trigger && menu.style.display !== 'none') {
            menu.style.display = 'none'; _auxStatusTrigger = null; return;
        }
        _auxStatusTrigger = trigger;
        const currentStatus = trigger.dataset.status;
        menu.innerHTML = '';
        Object.entries(AUX_STATUS_CFG).forEach(([key, cfg]) => {
            const item = document.createElement('div');
            item.style.cssText = 'display:flex; align-items:center; gap:8px; padding:10px 12px; cursor:pointer; border-bottom:1px solid #f8fafc;';
            item.innerHTML = `
                <div style="background:${cfg.bg}; padding:4px; border-radius:4px; display:flex;">
                    <i class="material-icons" style="font-size:16px; color:${cfg.color};">${cfg.icon}</i>
                </div>
                <span style="font-size:12px; font-weight:600; color:#334155;">${cfg.label}</span>
                ${key === currentStatus
                    ? '<i class="material-icons" style="font-size:14px; color:'+cfg.color+'; margin-left:auto;">check</i>'
                    : ''}
            `;
            item.addEventListener('mouseover', () => item.style.background = '#f8fafc');
            item.addEventListener('mouseout',  () => item.style.background = 'white');
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.style.display = 'none';
                const t = _auxStatusTrigger;
                _auxStatusTrigger = null;
                window.auxChangeStatus(t, key);
            });
            menu.appendChild(item);
        });
        const r = trigger.getBoundingClientRect();
        menu.style.top  = (window.scrollY + r.bottom + 4) + 'px';
        menu.style.left = (window.scrollX + r.left) + 'px';
        menu.style.display = 'block';
    };

    // Aplica el estado al trigger de aux (icono/label/color/dataset).
    function _applyAuxStatusVisual(trigger, status) {
        const cfg = AUX_STATUS_CFG[status] || AUX_STATUS_CFG['DESINCORPORADO'];
        const iconEl  = trigger.querySelector('.material-icons:first-child') || trigger.querySelector('div > .material-icons');
        const labelEl = trigger.querySelector('.aux-status-label');
        const innerBlock = trigger.querySelector('div');
        if (iconEl)  { iconEl.textContent = cfg.icon; iconEl.style.color = cfg.color; }
        if (labelEl)   labelEl.textContent = cfg.label;
        if (innerBlock) innerBlock.style.color = cfg.color;
        trigger.dataset.status = status;
    }

    // Contexto para marcar el aux INOPERATIVO tras crear el reporte desde el desplegable.
    let _auxFallaCtx = null;

    window.auxChangeStatus = function (trigger, newStatus) {
        if (!trigger) return;
        const oldStatus = trigger.dataset.status;
        if (oldStatus === newStatus) return;

        // INOPERATIVO se gestiona CREANDO un reporte de falla (el backend deja el
        // auxiliar inoperativo). Abrimos el modal "Nuevo Reporte" pre-seleccionado; si
        // se cancela no cambia nada; al crear, handleFallaCreatedAux marca el estado.
        if (newStatus === 'INOPERATIVO' && typeof window.flOpenForActivo === 'function') {
            _auxFallaCtx = { trigger: trigger, oldStatus: oldStatus };
            window.flOpenForActivo('equipo_auxiliar', trigger.dataset.auxId,
                trigger.dataset.label || ('Auxiliar #' + trigger.dataset.auxId),
                function () { _auxFallaCtx = null; });
            return;
        }

        const url = trigger.dataset.statusUrl;
        const revert = function () { _applyAuxStatusVisual(trigger, oldStatus); };

        // Optimistic UI
        _applyAuxStatusVisual(trigger, newStatus);

        window.apiFetch(url, {
            method: 'PATCH',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ ESTADO_OPERATIVO: newStatus })
        })
        .then(r => r.json().then(body => ({ status: r.status, body })))
        .then(({ status, body }) => {
            if (status === 200) {
                window.toast('Estado actualizado.', 'success');
                return;
            }
            // El auxiliar tiene un reporte ABIERTO: revertir y abrir el modal de cierre
            // (compartido falla_create_modal.js → flAbrirCierre).
            if (body && body.falla_abierta) {
                revert();
                window.flAbrirCierre(body.falla_abierta);
                return;
            }
            throw new Error(body.message || 'Error');
        })
        .catch(err => {
            revert();
            window.toast('No se pudo actualizar el estado.', 'error');
            console.error('auxChangeStatus:', err);
        });
    };

    // Tras crear un reporte desde el desplegable de estado: marca el aux INOPERATIVO.
    window.handleFallaCreatedAux = function () {
        const ctx = _auxFallaCtx;
        _auxFallaCtx = null;
        if (ctx && ctx.trigger) _applyAuxStatusVisual(ctx.trigger, 'INOPERATIVO');
    };

    // El cierre de reporte desde la tabla usa el modal COMPARTIDO
    // (falla_create_modal.js → flAbrirCierre / flConfirmarCierre), igual que equipos.
    // FALLA_MODAL_CFG.onClosed (en equipos/index.blade.php) recarga la lista.


    // ═══════════════════════════════════════════════════════════
    // SUBIR/ELIMINAR PDF DEL MODAL DE DETALLES (require equipos.edit)
    // ═══════════════════════════════════════════════════════════

    // Sincroniza la cache del modal de detalles (auxDetailsMap) tras subir o borrar
    // un documento y, si el modal sigue abierto, lo re-renderiza para que el icono
    // cambie EN VIVO (description=Ver ↔ cloud_upload=Subir). link=null => se borró.
    // Fuente única usada por auxUploadDoc (subir) y deletePdfFromPreview (borrar).
    // `vencimiento`: la fecha con la que se subió el certificado (la pedida antes de subir).
    window.syncAuxDocCache = function (auxId, docType, link, vencimiento) {
        const map = window.auxDetailsMap || {};
        const d = map[auxId] || map[String(auxId)];
        if (!d) return;
        if (docType === 'propiedad') {
            d.link_doc_propiedad = link || null;
        } else if (docType === 'certificado') {
            d.link_certificado = link || null;
            // Sin certificado no aplica la fecha; con uno nuevo, la suya.
            d.fecha_vencimiento_cert = link ? (vencimiento || d.fecha_vencimiento_cert) : null;
        }
        const modal = document.getElementById('auxDetailsModal');
        if (modal && modal.classList.contains('active') && typeof window.renderAuxDetailsModal === 'function') {
            window.renderAuxDetailsModal(d);
        }
    };

    window.auxUploadDoc = function (auxId, docType, input, vencimiento) {
        const file = input.files && input.files[0];
        if (!file) return;
        // El certificado vence: el PDF elegido se abre en el visor y su fecha se escribe en
        // el campo del panel de datos (ver pedirVencimientoEnVisor). Cancelar = no se sube
        // nada, y se vacia el input para que elegir el MISMO archivo vuelva a disparar el
        // change.
        if (!vencimiento && window.docVence('auxiliar', docType)) {
            window.pedirVencimientoEnVisor(file, {
                type: docType, label: 'Certificado', equipoId: auxId, module: 'auxiliar',
                uploadUrl: '/admin/equipos-auxiliares/' + auxId + '/upload-doc',
            }).then(function (fecha) {
                if (fecha) window.auxUploadDoc(auxId, docType, input, fecha);
                else input.value = '';
            });
            return;
        }
        const fd = new FormData();
        fd.append('file', file);
        fd.append('doc_type', docType);
        if (vencimiento) fd.append('fecha_vencimiento_cert', vencimiento);
        // Preloader global durante upload + render del visor (mismo patron
        // que /admin/equipos: el spinner queda visible hasta que el PDF
        // esta listo en el iframe del visor).
        if (window.showPreloader) window.showPreloader();
        window.apiFetch('/admin/equipos-auxiliares/' + auxId + '/upload-doc', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            method: 'POST',
            body: fd
        })
        .then(r => r.json().then(b => ({status:r.status, body:b})))
        .then(({status, body}) => {
            input.value = '';
            if (status === 200 && body.success) {
                window.toast(body.message || 'PDF cargado.', 'success');

                // 0) Actualizar la caché en memoria (auxDetailsMap) con el link nuevo y
                //    refrescar el icono del modal de detalles (a "cargado"/description) sin
                //    recargar la página. No dependemos del refresco async de la tabla (que
                //    puede no traer este aux por paginación/filtro y dejaba el icono viejo).
                // `vencimiento` y no body.fecha_vencimiento_cert: la del body sale del cast
                // 'date' en ISO con hora, y la ficha espera aaaa-mm-dd (como /details).
                if (body.link) window.syncAuxDocCache(auxId, docType, body.link, vencimiento);

                // 1) Cerrar el modal de detalles para que el visor del PDF
                //    no quede solapado por el card del aux.
                if (typeof window.closeAuxDetailsModal === 'function') {
                    window.closeAuxDetailsModal();
                }

                // 2) Refrescar la tabla en background: el partial re-renderiza
                //    el boton del PDF como "description" (azul) en vez del
                //    cloud_upload. Asi no queda el icono de cargar pegado y
                //    no hace falta recargar la pagina.
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();

                // 3) Abrir el visor del PDF al instante (igual que vehiculos):
                //    skipMetadata=false + module='auxiliar' para que el panel
                //    lateral cargue los datos correctos.
                if (body.link && typeof window.openPdfPreview === 'function') {
                    const labelHr  = docType === 'propiedad' ? 'Doc. Propiedad' : 'Certificado';
                    const uploadUrl = '/admin/equipos-auxiliares/' + auxId + '/upload-doc';
                    window.openPdfPreview(body.link, docType, labelHr, auxId, uploadUrl, false, 'auxiliar');
                }
                // Cierra el preloader que abrió ESTA función para la subida. openPdfPreview
                // ya no enciende ninguno: muestra su propio loader dentro del modal.
                if (window.hidePreloader) window.hidePreloader();
            } else {
                if (window.hidePreloader) window.hidePreloader();
                window.toast(body.message || 'No se pudo cargar el PDF.', 'error');
            }
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            input.value = '';
            console.error('uploadDoc:', err);
            window.toast('Error de red al cargar el PDF.', 'error');
        });
    };

})();
};
