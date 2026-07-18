/**
 * bulk_preview_factory.js — fábrica compartida para la "Carga Masiva vía Excel".
 *
 * equipos_bulk.js y auxiliares_bulk.js compartían ~150 líneas byte-idénticas
 * (validación de archivo, subida, render del preview, renumerar, conteo de
 * errores, botón X por fila, cancelar). Esta fábrica centraliza ese andamiaje y
 * recibe como CONFIG sólo lo que de verdad difiere entre módulos:
 *   - ids de los elementos del DOM y endpoints,
 *   - columns del preview,
 *   - cómo se marca un <option> como seleccionado (exacto vs case-insensitive),
 *   - atributos data-* extra por fila y cómo se recolecta cada fila (collectRow),
 *   - mapeo de errores del servidor y la UX del 422 (modal vs toast).
 *
 * applyServerErrors usa la forma ROBUSTA (regrupa tanto las claves planas de
 * Laravel "rows.{idx}.{field}" como la forma anidada {idx:{field}}), válida para
 * ambos módulos.
 *
 * Uso: window.createBulkPreview({...}). Devuelve la función init para registrarla
 * en ModuleManager desde el archivo del módulo.
 * SPA-safe: el guard dataset.bulkInit evita doble-init.
 */
(function () {
    'use strict';

    window.createBulkPreview = function (config) {
        function init() {
            const el = {
                btn:      document.getElementById(config.ids.btn),
                file:     document.getElementById(config.ids.file),
                panel:    document.getElementById(config.ids.panel),
                table:    document.getElementById(config.ids.table),
                header:   document.getElementById(config.ids.header),
                save:     document.getElementById(config.ids.save),
                cancel:   document.getElementById(config.ids.cancel),
            };
            if (!el.btn || !el.file) return;
            if (el.btn.dataset.bulkInit === '1') return;
            el.btn.dataset.bulkInit = '1';

            const formCard = () => document.getElementById(config.ids.formCard);
            const ciMatch = !!config.ciSelectMatch;
            const esc = window.escapeHtml;

            // ── 1) Selección de archivo ──────────────────────────────────────
            el.btn.addEventListener('click', () => el.file.click());

            el.file.addEventListener('change', () => {
                const file = el.file.files[0];
                if (!file) return;
                if (!/\.(xlsx|xls)$/i.test(file.name)) {
                    modal('error', 'Archivo inválido', 'Selecciona un archivo .xlsx o .xls');
                    el.file.value = '';
                    return;
                }
                if (file.size > 10 * 1024 * 1024) {
                    modal('error', 'Archivo muy grande', 'Tamaño máximo: 10MB');
                    el.file.value = '';
                    return;
                }
                uploadAndPreview(file);
            });

            function uploadAndPreview(file) {
                const fd = new FormData();
                fd.append('archivo_excel', file);
                if (window.showPreloader) window.showPreloader();
                fetch(config.previewUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.getCsrf()
                    },
                    body: fd
                })
                .then(r => r.json().then(body => ({ status: r.status, body })))
                .then(({ status, body }) => {
                    if (window.hidePreloader) window.hidePreloader();
                    if (status === 200 && body.success) {
                        renderPreview(body.rows, body.options);
                    } else {
                        modal('error', 'Error al procesar', body.message || 'No se pudo procesar el archivo', 'Cerrar');
                    }
                })
                .catch(err => {
                    if (window.hidePreloader) window.hidePreloader();
                    console.error(err);
                    modal('error', 'Error de red', 'No se pudo subir el archivo.', 'Cerrar');
                })
                .finally(() => { el.file.value = ''; });
            }

            // ── 2) Render del preview ────────────────────────────────────────
            function renderPreview(rows, options) {
                const columns = config.columns;
                const errorCount = rows.reduce((acc, r) => acc + (Object.keys(r.errors || {}).length ? 1 : 0), 0);
                setHeader(rows.length, errorCount);

                const tiposList = (options.tipos || []).map(t => (typeof t === 'string' ? t : (t.nombre ?? String(t))));
                let datalistHtml = `<datalist id="${config.datalistId}">`;
                tiposList.forEach(t => { datalistHtml += `<option value="${esc(t)}"></option>`; });
                datalistHtml += '</datalist>';

                let thead = '<thead><tr>';
                thead += `<th style="${config.numHeaderStyle || 'width:50px;'}">#</th>`;
                columns.forEach(c => {
                    const styleAttr = c.style ? ` style="${c.style}"` : '';
                    thead += `<th${styleAttr}>${c.label}</th>`;
                });
                thead += `<th style="${config.deleteHeaderStyle || 'width:40px;'}" title="Eliminar fila"></th></tr></thead>`;

                let tbody = '<tbody>';
                rows.forEach((row, idx) => {
                    const extraAttrs = config.buildRowDataset ? config.buildRowDataset(row, esc) : '';
                    tbody += `<tr data-row-idx="${idx}"${extraAttrs ? ' ' + extraAttrs : ''}>`;
                    tbody += `<td class="row-num"${config.rowNumStyle ? ` style="${config.rowNumStyle}"` : ''}>${idx + 1}</td>`;
                    columns.forEach(col => {
                        const val = row.data[col.key] ?? '';
                        const errKey = config.errKey ? config.errKey(col) : col.key;
                        const err = (row.errors && (row.errors[col.key] || row.errors[errKey])) || '';
                        const styleAttr = col.style ? ` style="${col.style}"` : '';
                        tbody += `<td class="${err ? 'cell-error' : ''}" data-field="${col.key}" title="${esc(err)}"${styleAttr}>`;
                        if (col.type === 'select') {
                            const list = col.optionsKey
                                ? options[col.optionsKey].map(o => (col.optionValue ? col.optionValue(o) : o))
                                : col.options;
                            tbody += `<select data-field="${col.key}"><option value="">-- Seleccionar --</option>`;
                            list.forEach(opt => {
                                const v = typeof opt === 'string' ? opt : String(opt);
                                const selected = ciMatch
                                    ? v.toUpperCase() === String(val).toUpperCase()
                                    : v === val;
                                tbody += `<option value="${esc(v)}"${selected ? ' selected' : ''}>${esc(v)}</option>`;
                            });
                            tbody += '</select>';
                        } else if (col.type === 'datalist') {
                            const isNew = val !== '' && !tiposList.some(t => t.toLowerCase() === String(val).toLowerCase());
                            tbody += `<input type="text" data-field="${col.key}" list="${config.datalistId}" value="${esc(String(val))}" placeholder="Selecciona o escribe nuevo" />`;
                            if (isNew) tbody += `<div class="cell-info-msg">Nuevo — se creará al guardar</div>`;
                        } else {
                            tbody += `<input type="${col.type}" data-field="${col.key}" value="${esc(String(val))}" />`;
                        }
                        if (err) tbody += `<div class="cell-error-msg">${esc(err)}</div>`;
                        tbody += '</td>';
                    });
                    tbody += `<td class="row-delete" style="text-align:center;">
                <button type="button" class="bulk-row-delete" title="Eliminar fila"
                    style="background:transparent;border:none;color:#94a3b8;cursor:pointer;width:28px;height:28px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;"
                    onmouseover="this.style.background='#fee2e2';this.style.color='#dc2626';"
                    onmouseout="this.style.background='transparent';this.style.color='#94a3b8';">
                    <i class="material-icons" style="font-size:18px;">close</i>
                </button>
            </td>`;
                    tbody += '</tr>';
                });
                tbody += '</tbody>';

                el.table.innerHTML = thead + tbody + datalistHtml;
                el.panel.style.display = 'block';

                // Botón X: elimina la fila, renumera y, si quedan 0, cierra el panel.
                el.table.querySelectorAll('.bulk-row-delete').forEach(btn => {
                    btn.addEventListener('click', () => {
                        const tr = btn.closest('tr');
                        if (!tr) return;
                        tr.remove();
                        renumberRows();
                        updateErrorCount();
                        if (!el.table.querySelectorAll('tbody tr').length) {
                            resetPanel();
                            const fc = formCard(); if (fc) fc.style.display = '';
                        }
                    });
                });

                const fc = formCard(); if (fc) fc.style.display = 'none';

                // Limpiar error al editar + badge "Nuevo" para el campo tipo.
                el.table.querySelectorAll('input,select').forEach(elm => {
                    elm.addEventListener('input', () => {
                        const td = elm.closest('td');
                        if (!td) return;
                        if (td.classList.contains('cell-error')) {
                            td.classList.remove('cell-error');
                            const msg = td.querySelector('.cell-error-msg');
                            if (msg) msg.remove();
                            td.removeAttribute('title');
                        }
                        if (elm.dataset.field === config.tipoField) {
                            const v = elm.value.trim();
                            const isNew = v !== '' && !tiposList.some(t => t.toLowerCase() === v.toLowerCase());
                            const existing = td.querySelector('.cell-info-msg');
                            if (isNew && !existing) {
                                const div = document.createElement('div');
                                div.className = 'cell-info-msg';
                                div.textContent = 'Nuevo — se creará al guardar';
                                td.appendChild(div);
                            } else if (!isNew && existing) {
                                existing.remove();
                            }
                        }
                    });
                });

                // Hook por módulo para wiring extra (ej. resolver id_frente/tipo_codigo en aux).
                if (config.bindRowExtras) config.bindRowExtras(el.table, options, tiposList);

                el.panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            // ── 3) Recolección + guardado ────────────────────────────────────
            function collectRows() {
                const rows = [];
                el.table.querySelectorAll('tbody tr').forEach(tr => {
                    rows.push(config.collectRow(tr));
                });
                return rows;
            }

            function renumberRows() {
                el.table.querySelectorAll('tbody tr').forEach((tr, i) => {
                    const cell = tr.querySelector('.row-num');
                    if (cell) cell.textContent = i + 1;
                });
            }

            function setHeader(total, errorCount) {
                el.header.innerHTML = `<strong>${total}</strong> filas cargadas. <strong style="color:${errorCount ? '#e53e3e' : '#38a169'}">${errorCount}</strong> con errores.`;
            }

            function updateErrorCount() {
                const trs = el.table.querySelectorAll('tbody tr');
                const errorCount = Array.from(trs).filter(tr => tr.querySelector('td.cell-error')).length;
                setHeader(trs.length, errorCount);
            }

            function applyServerErrors(errorsMap) {
                el.table.querySelectorAll('td.cell-error').forEach(td => {
                    td.classList.remove('cell-error');
                    const msg = td.querySelector('.cell-error-msg');
                    if (msg) msg.remove();
                    td.removeAttribute('title');
                });

                const map = config.serverFieldMap || {};
                const grouped = {};
                Object.entries(errorsMap || {}).forEach(([key, val]) => {
                    const m = String(key).match(/^rows\.(\d+)\.(.+)$/);
                    if (m) {
                        const idx = m[1];
                        const uiField = map[m[2]] || m[2];
                        grouped[idx] = grouped[idx] || {};
                        grouped[idx][uiField] = Array.isArray(val) ? val[0] : String(val);
                    } else if (val && typeof val === 'object') {
                        grouped[key] = grouped[key] || {};
                        Object.entries(val).forEach(([field, msg]) => {
                            const uiField = map[field] || field;
                            grouped[key][uiField] = Array.isArray(msg) ? msg[0] : String(msg);
                        });
                    }
                });

                let firstErrorTr = null;
                Object.entries(grouped).forEach(([idx, fieldErrors]) => {
                    const tr = el.table.querySelector(`tr[data-row-idx="${idx}"]`);
                    if (!tr) return;
                    if (!firstErrorTr) firstErrorTr = tr;
                    Object.entries(fieldErrors).forEach(([field, msg]) => {
                        const td = tr.querySelector(`td[data-field="${field}"]`);
                        if (td) {
                            td.classList.add('cell-error');
                            td.setAttribute('title', msg);
                            if (!td.querySelector('.cell-error-msg')) {
                                const div = document.createElement('div');
                                div.className = 'cell-error-msg';
                                div.textContent = msg;
                                td.appendChild(div);
                            }
                        }
                    });
                });

                updateErrorCount();
                if (firstErrorTr) firstErrorTr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            el.save && el.save.addEventListener('click', () => {
                const rows = collectRows();
                if (!rows.length) return;
                if (window.showPreloader) window.showPreloader();
                fetch(config.storeUrl, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': window.getCsrf()
                    },
                    body: JSON.stringify({ rows })
                })
                .then(r => r.json().then(body => ({ status: r.status, body })))
                .then(({ status, body }) => {
                    if (status === 200 && body.success) {
                        // Éxito: guardar toast en sessionStorage para que se muestre
                        // después de la navegación (el preloader se queda activo hasta
                        // que la nueva página cargue — sin doble spinner).
                        var msg = body.message || config.successMessage;
                        try {
                            sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({ message: msg, type: 'success' }));
                        } catch (_) {}
                        // Handoff de spinner: dejamos el preloader ENCENDIDO (no hacemos hide)
                        // y marcamos el flag para que loadPage HEREDE este show en vez de sumar
                        // otro — igual que equipos_form/catalogo_create/form_logic. Sin el flag,
                        // con el preloader contado por referencias este show quedaba huérfano y
                        // el spinner se colgaba en el destino tras un guardado masivo.
                        window.__vidalsaRedirecting = true;
                        var dest = typeof config.redirect === 'function' ? config.redirect(body) : (body.redirect || config.redirect);
                        if (window.navigateTo) window.navigateTo(dest);
                        else window.location.href = dest;
                    } else if (status === 422) {
                        if (window.hidePreloader) window.hidePreloader();
                        if (body.errors) applyServerErrors(body.errors);
                        var errMsg = body.message || 'Algunas filas tienen errores. Revísalas y reintenta.';
                        if (window.showToast) window.showToast(errMsg, 'error');
                        else modal('warning', 'Errores encontrados', errMsg);
                    } else {
                        if (window.hidePreloader) window.hidePreloader();
                        modal('error', 'Error', body.message || 'No se pudo guardar.', 'Cerrar');
                    }
                })
                .catch(err => {
                    if (window.hidePreloader) window.hidePreloader();
                    console.error(err);
                    modal('error', 'Error de red', 'No se pudo guardar.', 'Cerrar');
                });
            });

            el.cancel && el.cancel.addEventListener('click', () => {
                resetPanel();
                const fc = formCard(); if (fc) fc.style.display = '';
            });

            function resetPanel() {
                el.panel.style.display = 'none';
                el.table.innerHTML = '';
                el.header.innerHTML = '';
            }

            function modal(type, title, message, confirmText) {
                window.showModal && window.showModal({
                    type, title, message,
                    confirmText: confirmText || 'Entendido', hideCancel: true
                });
            }
        }

        return init;
    };
})();
