/**
 * equipos_bulk.js - Carga Masiva de Equipos vía Excel
 * SPA-Compatible via ModuleManager
 */

function initEquiposBulk() {
    const btnCargar = document.getElementById('btnCargarExcel');
    const fileInput = document.getElementById('bulkExcelInput');
    const panel = document.getElementById('bulkPreviewPanel');
    const tableEl = document.getElementById('bulkPreviewTable');
    const headerEl = document.getElementById('bulkPreviewHeader');
    const btnGuardar = document.getElementById('btnGuardarBulk');
    const btnCancelar = document.getElementById('btnCancelBulk');
    if (!btnCargar || !fileInput) return;
    if (btnCargar.dataset.bulkInit === '1') return;
    btnCargar.dataset.bulkInit = '1';

    let currentOptions = null;

    // 1) click en botón abre file picker
    btnCargar.addEventListener('click', () => fileInput.click());

    // 2) change en file input
    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];
        if (!file) return;
        if (!/\.(xlsx|xls)$/i.test(file.name)) {
            window.showModal && window.showModal({type:'error',title:'Archivo inválido',message:'Selecciona un archivo .xlsx o .xls',confirmText:'Entendido',hideCancel:true});
            fileInput.value = '';
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            window.showModal && window.showModal({type:'error',title:'Archivo muy grande',message:'Tamaño máximo: 10MB',confirmText:'Entendido',hideCancel:true});
            fileInput.value = '';
            return;
        }
        uploadAndPreview(file);
    });

    function uploadAndPreview(file) {
        const fd = new FormData();
        fd.append('archivo_excel', file);
        if (window.showPreloader) window.showPreloader();
        fetch('/admin/equipos/bulk-preview', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: fd
        })
        .then(r => r.json().then(body => ({status: r.status, body})))
        .then(({status, body}) => {
            if (window.hidePreloader) window.hidePreloader();
            if (status === 200 && body.success) {
                currentOptions = body.options;
                renderPreview(body.rows, body.options);
            } else {
                window.showModal && window.showModal({type:'error',title:'Error al procesar',message:body.message||'No se pudo procesar el archivo',confirmText:'Cerrar',hideCancel:true});
            }
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            console.error(err);
            window.showModal && window.showModal({type:'error',title:'Error de red',message:'No se pudo subir el archivo.',confirmText:'Cerrar',hideCancel:true});
        })
        .finally(() => { fileInput.value = ''; });
    }

    function renderPreview(rows, options) {
        const columns = [
            {key:'tipo_equipo', label:'Tipo de Equipo', type:'datalist', optionsKey:'tipos', optionValue:o=>o.nombre},
            {key:'categoria_flota', label:'Categoría de Flota', type:'select', options:options.categorias},
            {key:'marca', label:'Marca', type:'text'},
            {key:'modelo', label:'Modelo', type:'text'},
            // Año: 4 digitos. N° Etiqueta: 3-4 digitos. Ambos numericos cortos —
            // no necesitan ancho de columna de marca/modelo. width fijo compacto.
            {key:'anio', label:'Año', type:'number', style:'width:62px;min-width:62px;max-width:62px;'},
            {key:'numero_etiqueta', label:'N° Etiqueta', type:'text', style:'width:90px;min-width:90px;'},
            {key:'serial_chasis', label:'Serial de Chasis', type:'text'},
            {key:'serial_de_motor', label:'Serial de Motor', type:'text'},
            {key:'frente_trabajo', label:'Frente de Trabajo', type:'select', optionsKey:'frentes', optionValue:o=>o.nombre},
            {key:'status', label:'Status', type:'select', options:options.statuses},
        ];

        const errorCount = rows.reduce((acc,r)=>acc+(Object.keys(r.errors||{}).length?1:0),0);
        headerEl.innerHTML = `<strong>${rows.length}</strong> filas cargadas. <strong style="color:${errorCount?'#e53e3e':'#38a169'}">${errorCount}</strong> con errores.`;

        // datalist compartido para tipos de equipo (permite escribir valor nuevo)
        const tiposList = (options.tipos || []).map(t => t.nombre);
        let datalistHtml = '<datalist id="bulkTiposDatalist">';
        tiposList.forEach(t => { datalistHtml += `<option value="${escapeHtml(t)}"></option>`; });
        datalistHtml += '</datalist>';

        // thead — soporta `style` opcional por columna para ajustar ancho.
        let thead = '<thead><tr>';
        thead += '<th style="width:32px;min-width:32px;max-width:32px;padding-left:4px;padding-right:4px;text-align:center;">#</th>';
        columns.forEach(c => {
            const styleAttr = c.style ? ` style="${c.style}"` : '';
            thead += `<th${styleAttr}>${c.label}</th>`;
        });
        thead += '<th style="width:40px;" title="Eliminar fila"></th>';
        thead += '</tr></thead>';

        // tbody
        let tbody = '<tbody>';
        rows.forEach((row, idx) => {
            tbody += `<tr data-row-idx="${idx}">`;
            tbody += `<td class="row-num" style="text-align:center;padding-left:4px;padding-right:4px;">${idx+1}</td>`;
            columns.forEach(col => {
                const val = row.data[col.key] ?? '';
                const err = (row.errors && row.errors[col.key]) || '';
                const errClass = err ? 'cell-error' : '';
                const styleAttr = col.style ? ` style="${col.style}"` : '';
                tbody += `<td class="${errClass}" data-field="${col.key}" title="${escapeHtml(err)}"${styleAttr}>`;
                if (col.type === 'select') {
                    const list = col.optionsKey
                        ? options[col.optionsKey].map(o => (col.optionValue ? col.optionValue(o) : o))
                        : col.options;
                    tbody += `<select data-field="${col.key}">`;
                    tbody += '<option value="">-- Seleccionar --</option>';
                    list.forEach(opt => {
                        const v = typeof opt === 'string' ? opt : String(opt);
                        tbody += `<option value="${escapeHtml(v)}"${v===val?' selected':''}>${escapeHtml(v)}</option>`;
                    });
                    tbody += '</select>';
                } else if (col.type === 'datalist') {
                    // Input con datalist: permite escribir valor nuevo o elegir de la lista
                    const isNew = val !== '' && !tiposList.some(t => t.toLowerCase() === String(val).toLowerCase());
                    tbody += `<input type="text" data-field="${col.key}" list="bulkTiposDatalist" value="${escapeHtml(String(val))}" placeholder="Selecciona o escribe nuevo" />`;
                    if (isNew) {
                        tbody += `<div class="cell-info-msg">Nuevo — se creará al guardar</div>`;
                    }
                } else {
                    tbody += `<input type="${col.type}" data-field="${col.key}" value="${escapeHtml(String(val))}" />`;
                }
                if (err) { tbody += `<div class="cell-error-msg">${escapeHtml(err)}</div>`; }
                tbody += '</td>';
            });
            // Boton X para eliminar la fila completa del preview antes de guardar
            // (mismo patron que en /admin/equipos-auxiliares bulk).
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

        tableEl.innerHTML = thead + tbody + datalistHtml;
        panel.style.display = 'block';

        // Boton X: elimina la fila del preview, renumera y actualiza el header.
        // Si quedan 0 filas, cierra el panel (no tiene sentido seguir con un
        // preview vacio).
        tableEl.querySelectorAll('.bulk-row-delete').forEach(btn => {
            btn.addEventListener('click', () => {
                const tr = btn.closest('tr');
                if (!tr) return;
                tr.remove();
                renumberRows();
                updateErrorCount();
                if (!tableEl.querySelectorAll('tbody tr').length) {
                    panel.style.display = 'none';
                    tableEl.innerHTML = '';
                    headerEl.innerHTML = '';
                    const formCard = document.getElementById('formEquipoCard');
                    if (formCard) formCard.style.display = '';
                }
            });
        });

        // Ocultar el card del formulario de equipo individual mientras se ve el preview
        const formCard = document.getElementById('formEquipoCard');
        if (formCard) formCard.style.display = 'none';

        // clear error on user edit + actualizar badge "Nuevo" en tipo_equipo
        tableEl.querySelectorAll('input,select').forEach(el => {
            el.addEventListener('input', () => {
                const td = el.closest('td');
                if (!td) return;
                if (td.classList.contains('cell-error')) {
                    td.classList.remove('cell-error');
                    const msg = td.querySelector('.cell-error-msg');
                    if (msg) msg.remove();
                    td.removeAttribute('title');
                }
                // Refresh badge "Nuevo" para tipo_equipo
                if (el.dataset.field === 'tipo_equipo') {
                    const v = el.value.trim();
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

        panel.scrollIntoView({behavior:'smooth', block:'start'});
    }

    function collectRows() {
        const rows = [];
        tableEl.querySelectorAll('tbody tr').forEach(tr => {
            const data = {};
            tr.querySelectorAll('[data-field]').forEach(el => {
                if (el.matches('input,select')) data[el.dataset.field] = el.value.trim();
            });
            rows.push(data);
        });
        return rows;
    }

    // Re-numera la columna # despues de eliminar filas en el preview.
    function renumberRows() {
        tableEl.querySelectorAll('tbody tr').forEach((tr, i) => {
            const cell = tr.querySelector('.row-num');
            if (cell) cell.textContent = i + 1;
        });
    }

    // Actualiza el header con el conteo actual de filas y filas con error.
    function updateErrorCount() {
        const trs = tableEl.querySelectorAll('tbody tr');
        const errorCount = Array.from(trs).filter(tr => tr.querySelector('td.cell-error')).length;
        headerEl.innerHTML = `<strong>${trs.length}</strong> filas cargadas. <strong style="color:${errorCount?'#e53e3e':'#38a169'}">${errorCount}</strong> con errores.`;
    }

    function applyServerErrors(errorsMap) {
        // errorsMap = {"0": {"serial_chasis": "Ya existe"}, ...}
        // limpiar errores previos
        tableEl.querySelectorAll('td.cell-error').forEach(td => {
            td.classList.remove('cell-error');
            const msg = td.querySelector('.cell-error-msg');
            if (msg) msg.remove();
            td.removeAttribute('title');
        });
        let firstErrorTr = null;
        Object.entries(errorsMap).forEach(([idx, fieldErrors]) => {
            const tr = tableEl.querySelector(`tr[data-row-idx="${idx}"]`);
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
        if (firstErrorTr) firstErrorTr.scrollIntoView({behavior:'smooth', block:'center'});
    }

    btnGuardar && btnGuardar.addEventListener('click', () => {
        const rows = collectRows();
        if (!rows.length) return;
        if (window.showPreloader) window.showPreloader();
        fetch('/admin/equipos/bulk-store-batch', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify({rows})
        })
        .then(r => r.json().then(body => ({status: r.status, body})))
        .then(({status, body}) => {
            if (window.hidePreloader) window.hidePreloader();
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message || 'Equipos creados correctamente', 'success');
                setTimeout(() => {
                    if (window.navigateTo) window.navigateTo(body.redirect || '/admin/equipos');
                    else window.location.href = body.redirect || '/admin/equipos';
                }, 1000);
            } else if (status === 422 && body.errors) {
                applyServerErrors(body.errors);
                window.showModal && window.showModal({type:'warning',title:'Corrige los errores',message:'Algunas filas tienen errores. Revísalas y reintenta.',confirmText:'Entendido',hideCancel:true});
            } else {
                window.showModal && window.showModal({type:'error',title:'Error',message:body.message||'No se pudo guardar.',confirmText:'Cerrar',hideCancel:true});
            }
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            console.error(err);
            window.showModal && window.showModal({type:'error',title:'Error de red',message:'No se pudo guardar.',confirmText:'Cerrar',hideCancel:true});
        });
    });

    btnCancelar && btnCancelar.addEventListener('click', () => {
        panel.style.display = 'none';
        tableEl.innerHTML = '';
        headerEl.innerHTML = '';
        // Restaurar el card del formulario de equipo individual
        const formCard = document.getElementById('formEquipoCard');
        if (formCard) formCard.style.display = '';
    });

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
}

// Register with Module Manager if available
if (typeof ModuleManager !== 'undefined') {
    ModuleManager.register('equipos_bulk',
        () => document.getElementById('btnCargarExcel') !== null,
        initEquiposBulk
    );
}

// Direct init fallback (ModuleManager may init before modules register)
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initEquiposBulk);
} else {
    initEquiposBulk();
}

// La reinicialización tras nav SPA la maneja ModuleManager (ver register arriba).
// No registrar aquí un segundo listener spa:contentLoaded: causaría que
// initEquiposBulk() corra dos veces con reset del guard entre medio, attachando
// handlers duplicados al botón (efecto: file picker se abre/cierra y no funciona).
