/**
 * auxiliares_bulk.js - Carga Masiva de Equipos Auxiliares vía Excel.
 * El andamiaje vive en bulk_preview_factory.js (window.createBulkPreview);
 * aquí sólo va la config específica de auxiliares. SPA-Compatible vía ModuleManager.
 */

var initAuxiliaresBulk = window.createBulkPreview({
    ids: {
        btn: 'btnCargarExcelAux', file: 'bulkExcelInputAux', panel: 'bulkPreviewPanelAux',
        table: 'bulkPreviewTableAux', header: 'bulkPreviewHeaderAux',
        save: 'btnGuardarBulkAux', cancel: 'btnCancelBulkAux', formCard: 'formUnificadoCard',
    },
    datalistId: 'bulkTiposAuxDatalist',
    previewUrl: '/admin/equipos-auxiliares/bulk-preview',
    storeUrl: '/admin/equipos-auxiliares/bulk-store-batch',
    // Tras la carga masiva se vuelve al modulo UNIFICADO (/admin/equipos), donde ya se ven
    // equipos y auxiliares juntos. Antes apuntaba a '/admin/equipos-auxiliares': la carga
    // masiva de auxiliares se lanza desde /admin/equipos/create (mismo formUnificadoCard),
    // asi que al guardar el usuario acababa en el modulo VIEJO de solo-auxiliares sin
    // haberlo pedido. Misma forma que equipos_bulk.js, por si el server empieza a mandar
    // su propio `redirect` en el body. NO se toca la logica de guardado, solo el destino.
    redirect: (body) => body.redirect || '/admin/equipos',
    redirectDelay: 800,
    successMessage: 'Equipos auxiliares creados correctamente',
    // El detalle de errores ya se ve en cada celda; en el 422 basta un toast efímero.
    on422: 'toast',
    flashToast: true,
    tipoField: 'tipo',
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
    // id_frente_resuelto viaja en un data-* para el submit final. El TIPO va como se ve en la
    // celda: el servidor lo traduce a su código (codigoDeTipo), igual que en el alta a mano.
    buildRowDataset: (row) => `data-id-frente="${row.data.id_frente_resuelto || ''}"`,
    // El server valida con keys "rows.{idx}.{field}" (id_frente_resuelto,
    // frente_de_trabajo…); este mapeo las lleva a las claves de celda del preview.
    serverFieldMap: {
        'tipo': 'tipo', 'marca': 'marca', 'modelo': 'modelo',
        'serial': 'serial', 'capacidad': 'capacidad', 'anio': 'anio',
        'id_frente_resuelto': 'frente', 'frente_de_trabajo': 'frente', 'frente': 'frente',
        'estado': 'estado',
    },
    // El server devuelve el error del frente como "frente_de_trabajo".
    errKey: (col) => (col.key === 'frente' ? 'frente_de_trabajo' : col.key),
    // Al cambiar el frente, resolver su id en el data-* de la fila.
    bindRowExtras: (tableEl, options) => {
        tableEl.querySelectorAll('select[data-field="frente"]').forEach(el => {
            el.addEventListener('change', () => {
                const tr = el.closest('tr'); if (!tr) return;
                const sel = el.value.trim().toLowerCase();
                const match = (options.frentes || []).find(f => String(f.nombre).trim().toLowerCase() === sel);
                tr.dataset.idFrente = match ? match.id : '';
            });
        });
    },
    collectRow: (tr) => {
        const data = {
            id_frente_resuelto: tr.dataset.idFrente ? parseInt(tr.dataset.idFrente, 10) : null,
        };
        tr.querySelectorAll('[data-field]').forEach(el => {
            if (!el.matches('input,select')) return;
            const f = el.dataset.field;
            const v = el.value.trim();
            if (f === 'anio') data.anio = v === '' ? null : parseInt(v, 10);
            else data[f] = v;
        });
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
