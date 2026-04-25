/**
 * auxiliares_bulk.js - Carga Masiva de Equipos Auxiliares via Excel
 * Patron identico a equipos_bulk.js adaptado al esquema de auxiliares.
 * SPA-Compatible via ModuleManager.
 */

function initAuxiliaresBulk() {
    const btnCargar   = document.getElementById('btnCargarExcelAux');
    const fileInput   = document.getElementById('bulkExcelInputAux');
    const panel       = document.getElementById('bulkPreviewPanelAux');
    const tableEl     = document.getElementById('bulkPreviewTableAux');
    const headerEl    = document.getElementById('bulkPreviewHeaderAux');
    const btnGuardar  = document.getElementById('btnGuardarBulkAux');
    const btnCancelar = document.getElementById('btnCancelBulkAux');
    if (!btnCargar || !fileInput) return;
    if (btnCargar.dataset.bulkInit === '1') return;
    btnCargar.dataset.bulkInit = '1';

    btnCargar.addEventListener('click', () => fileInput.click());

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
        fetch('/admin/equipos-auxiliares/bulk-preview', {
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
        // Columns del preview. Coincide con el formato devuelto por bulkPreview
        // del controller. Los selects/datalists se alimentan de options.
        const columns = [
            {key:'tipo',           label:'Tipo',            type:'datalist'},
            {key:'marca',          label:'Marca',           type:'text'},
            {key:'modelo',         label:'Modelo',          type:'text'},
            {key:'serial',         label:'Serial',          type:'text'},
            {key:'capacidad',      label:'Capacidad',       type:'text'},
            {key:'anio',           label:'Año',             type:'number'},
            {key:'frente',         label:'Frente',          type:'select', optionsKey:'frentes', optionValue:o=>o.nombre},
            {key:'estado',         label:'Estado',          type:'select', options: options.estados},
        ];

        const errorCount = rows.reduce((acc,r)=>acc+(Object.keys(r.errors||{}).length?1:0),0);
        headerEl.innerHTML = `<strong>${rows.length}</strong> filas cargadas. <strong style="color:${errorCount?'#e53e3e':'#38a169'}">${errorCount}</strong> con errores.`;

        const tiposList = (options.tipos || []).map(t => String(t));
        let datalistHtml = '<datalist id="bulkTiposAuxDatalist">';
        tiposList.forEach(t => { datalistHtml += `<option value="${escapeHtml(t)}"></option>`; });
        datalistHtml += '</datalist>';

        let thead = '<thead><tr><th style="width:50px;">#</th>';
        columns.forEach(c => { thead += `<th>${c.label}</th>`; });
        thead += '</tr></thead>';

        let tbody = '<tbody>';
        rows.forEach((row, idx) => {
            // tipo_codigo + id_frente_resuelto van en data-* para el submit final
            tbody += `<tr data-row-idx="${idx}" data-tipo-codigo="${escapeHtml(row.data.tipo_codigo || '')}" data-id-frente="${row.data.id_frente_resuelto || ''}">`;
            tbody += `<td class="row-num">${idx+1}</td>`;
            columns.forEach(col => {
                const val = row.data[col.key] ?? '';
                // El server devuelve errors con la key "frente_de_trabajo" pero la columna se llama "frente"
                const errKey = col.key === 'frente' ? 'frente_de_trabajo' : col.key;
                const err = (row.errors && (row.errors[col.key] || row.errors[errKey])) || '';
                const errClass = err ? 'cell-error' : '';
                tbody += `<td class="${errClass}" data-field="${col.key}" title="${escapeHtml(err)}">`;
                if (col.type === 'select') {
                    const list = col.optionsKey
                        ? options[col.optionsKey].map(o => (col.optionValue ? col.optionValue(o) : o))
                        : col.options;
                    tbody += `<select data-field="${col.key}">`;
                    tbody += '<option value="">-- Seleccionar --</option>';
                    list.forEach(opt => {
                        const v = typeof opt === 'string' ? opt : String(opt);
                        tbody += `<option value="${escapeHtml(v)}"${v.toUpperCase()===String(val).toUpperCase()?' selected':''}>${escapeHtml(v)}</option>`;
                    });
                    tbody += '</select>';
                } else if (col.type === 'datalist') {
                    const isNew = val !== '' && !tiposList.some(t => t.toLowerCase() === String(val).toLowerCase());
                    tbody += `<input type="text" data-field="${col.key}" list="bulkTiposAuxDatalist" value="${escapeHtml(String(val))}" placeholder="Selecciona o escribe nuevo" />`;
                    if (isNew) {
                        tbody += `<div class="cell-info-msg">Nuevo — se creará al guardar</div>`;
                    }
                } else {
                    tbody += `<input type="${col.type}" data-field="${col.key}" value="${escapeHtml(String(val))}" />`;
                }
                if (err) { tbody += `<div class="cell-error-msg">${escapeHtml(err)}</div>`; }
                tbody += '</td>';
            });
            tbody += '</tr>';
        });
        tbody += '</tbody>';

        tableEl.innerHTML = thead + tbody + datalistHtml;
        panel.style.display = 'block';

        // Ocultar form individual mientras preview esta activo
        const formCard = document.getElementById('formEquipoAuxiliarCard');
        if (formCard) formCard.style.display = 'none';

        // Clear error on edit + badge "Nuevo" para tipo
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
                if (el.dataset.field === 'tipo') {
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
            // Al cambiar el frente del select, actualizar data-id-frente de la fila
            if (el.tagName === 'SELECT' && el.dataset.field === 'frente') {
                el.addEventListener('change', () => {
                    const tr = el.closest('tr');
                    if (!tr) return;
                    const sel = el.value.trim().toLowerCase();
                    const match = (options.frentes || []).find(f => String(f.nombre).trim().toLowerCase() === sel);
                    tr.dataset.idFrente = match ? match.id : '';
                });
            }
            // Al escribir un tipo nuevo en el datalist, limpiar tipo_codigo para recalcular server-side
            if (el.dataset.field === 'tipo') {
                el.addEventListener('change', () => {
                    const tr = el.closest('tr');
                    if (!tr) return;
                    const v = el.value.trim();
                    // Si coincide con un tipo existente, usar ese code; sino, normalizar
                    const found = tiposList.find(t => t.toLowerCase() === v.toLowerCase());
                    tr.dataset.tipoCodigo = found
                        ? found.toUpperCase().replace(/\s+/g, '_')
                        : v.toUpperCase().replace(/\s+/g, '_');
                });
            }
        });

        panel.scrollIntoView({behavior:'smooth', block:'start'});
    }

    function collectRows() {
        const rows = [];
        tableEl.querySelectorAll('tbody tr').forEach(tr => {
            const data = {
                tipo_codigo:        tr.dataset.tipoCodigo || '',
                id_frente_resuelto: tr.dataset.idFrente ? parseInt(tr.dataset.idFrente, 10) : null,
            };
            tr.querySelectorAll('[data-field]').forEach(el => {
                if (!el.matches('input,select')) return;
                const f = el.dataset.field;
                const v = el.value.trim();
                if (f === 'anio') data.anio = v === '' ? null : parseInt(v, 10);
                else data[f] = v;
            });
            // Si el user edito el tipo manualmente y no dispara change, fallback al value del input
            if (!data.tipo_codigo && data.tipo) {
                data.tipo_codigo = String(data.tipo).toUpperCase().replace(/\s+/g, '_');
            }
            rows.push(data);
        });
        return rows;
    }

    function applyServerErrors(errorsMap) {
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
        fetch('/admin/equipos-auxiliares/bulk-store-batch', {
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
                if (window.showToast) window.showToast(body.message || 'Equipos auxiliares creados correctamente', 'success');
                try {
                    sessionStorage.setItem('vidalsa_flash_toast', JSON.stringify({
                        message: body.message || 'Equipos auxiliares creados correctamente',
                        type: 'success'
                    }));
                } catch (_) {}
                setTimeout(() => {
                    if (window.navigateTo) window.navigateTo('/admin/equipos-auxiliares');
                    else window.location.href = '/admin/equipos-auxiliares';
                }, 800);
            } else if (status === 422 && body.errors) {
                applyServerErrors(body.errors);
                window.showModal && window.showModal({type:'warning',title:'Corrige los errores',message: body.message || 'Algunas filas tienen errores. Revísalas y reintenta.',confirmText:'Entendido',hideCancel:true});
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
        const formCard = document.getElementById('formEquipoAuxiliarCard');
        if (formCard) formCard.style.display = '';
    });

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
}

// Register with Module Manager if available
if (typeof ModuleManager !== 'undefined') {
    ModuleManager.register('auxiliares_bulk',
        () => document.getElementById('btnCargarExcelAux') !== null,
        initAuxiliaresBulk
    );
}

// Direct init fallback
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAuxiliaresBulk);
} else {
    initAuxiliaresBulk();
}

// La reinicializacion tras nav SPA la maneja ModuleManager (no registrar un
// segundo listener spa:contentLoaded — causaria handlers duplicados).
