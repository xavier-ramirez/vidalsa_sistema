/**
 * auxiliares_bulk.js - Carga Masiva de Equipos Auxiliares vía Excel.
 * El andamiaje vive en bulk_preview_factory.js (window.createBulkPreview);
 * aquí sólo va la config específica de auxiliares. SPA-Compatible vía ModuleManager.
 */

var initAuxiliaresBulk = window.createBulkPreview({
    ids: {
        btn: 'btnCargarExcelAux', file: 'bulkExcelInputAux', panel: 'bulkPreviewPanelAux',
        table: 'bulkPreviewTableAux', header: 'bulkPreviewHeaderAux',
        save: 'btnGuardarBulkAux', cancel: 'btnCancelBulkAux', formCard: 'formEquipoAuxiliarCard',
    },
    datalistId: 'bulkTiposAuxDatalist',
    previewUrl: '/admin/equipos-auxiliares/bulk-preview',
    storeUrl: '/admin/equipos-auxiliares/bulk-store-batch',
    redirect: '/admin/equipos-auxiliares',
    redirectDelay: 800,
    successMessage: 'Equipos auxiliares creados correctamente',
    // El detalle de errores ya se ve en cada celda; en el 422 basta un toast efímero.
    on422: 'toast',
    flashToast: true,
    tipoField: 'tipo',
    ciSelectMatch: true, // los estados se comparan case-insensitive
    columns: [
        { key: 'tipo', label: 'Tipo', type: 'datalist' },
        { key: 'marca', label: 'Marca', type: 'text' },
        { key: 'modelo', label: 'Modelo', type: 'text' },
        { key: 'serial', label: 'Serial', type: 'text' },
        { key: 'capacidad', label: 'Capacidad', type: 'text' },
        { key: 'anio', label: 'Año', type: 'number' },
        { key: 'frente', label: 'Frente', type: 'select', optionsKey: 'frentes', optionValue: o => o.nombre },
        { key: 'estado', label: 'Estado', type: 'select', optionsKey: 'estados' },
    ],
    // tipo_codigo + id_frente_resuelto viajan en data-* para el submit final.
    buildRowDataset: (row, esc) =>
        `data-tipo-codigo="${esc(row.data.tipo_codigo || '')}" data-id-frente="${row.data.id_frente_resuelto || ''}"`,
    // El server valida con keys "rows.{idx}.{field}" (tipo_codigo, id_frente_resuelto,
    // frente_de_trabajo…); este mapeo las lleva a las claves de celda del preview.
    serverFieldMap: {
        'tipo_codigo': 'tipo', 'tipo': 'tipo', 'marca': 'marca', 'modelo': 'modelo',
        'serial': 'serial', 'capacidad': 'capacidad', 'anio': 'anio',
        'id_frente_resuelto': 'frente', 'frente_de_trabajo': 'frente', 'frente': 'frente',
        'estado': 'estado',
    },
    // El server devuelve el error del frente como "frente_de_trabajo".
    errKey: (col) => (col.key === 'frente' ? 'frente_de_trabajo' : col.key),
    // Al cambiar frente/tipo, resolver id_frente / tipo_codigo en los data-* de la fila.
    bindRowExtras: (tableEl, options, tiposList) => {
        tableEl.querySelectorAll('input,select').forEach(el => {
            if (el.tagName === 'SELECT' && el.dataset.field === 'frente') {
                el.addEventListener('change', () => {
                    const tr = el.closest('tr'); if (!tr) return;
                    const sel = el.value.trim().toLowerCase();
                    const match = (options.frentes || []).find(f => String(f.nombre).trim().toLowerCase() === sel);
                    tr.dataset.idFrente = match ? match.id : '';
                });
            }
            if (el.dataset.field === 'tipo') {
                el.addEventListener('change', () => {
                    const tr = el.closest('tr'); if (!tr) return;
                    const v = el.value.trim();
                    const found = tiposList.find(t => t.toLowerCase() === v.toLowerCase());
                    tr.dataset.tipoCodigo = found
                        ? found.toUpperCase().replace(/\s+/g, '_')
                        : v.toUpperCase().replace(/\s+/g, '_');
                });
            }
        });
    },
    collectRow: (tr) => {
        const data = {
            tipo_codigo: tr.dataset.tipoCodigo || '',
            id_frente_resuelto: tr.dataset.idFrente ? parseInt(tr.dataset.idFrente, 10) : null,
        };
        tr.querySelectorAll('[data-field]').forEach(el => {
            if (!el.matches('input,select')) return;
            const f = el.dataset.field;
            const v = el.value.trim();
            if (f === 'anio') data.anio = v === '' ? null : parseInt(v, 10);
            else data[f] = v;
        });
        // Si el user editó el tipo manualmente y no disparó change, fallback al value.
        if (!data.tipo_codigo && data.tipo) {
            data.tipo_codigo = String(data.tipo).toUpperCase().replace(/\s+/g, '_');
        }
        return data;
    },
});

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
