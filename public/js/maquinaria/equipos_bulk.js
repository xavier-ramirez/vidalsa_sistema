/**
 * equipos_bulk.js - Carga Masiva de Equipos vía Excel.
 * El andamiaje vive en bulk_preview_factory.js (window.createBulkPreview);
 * aquí sólo va la config específica de equipos. SPA-Compatible vía ModuleManager.
 */

var initEquiposBulk = window.createBulkPreview({
    ids: {
        btn: 'btnCargarExcel', file: 'bulkExcelInput', panel: 'bulkPreviewPanel',
        table: 'bulkPreviewTable', header: 'bulkPreviewHeader',
        save: 'btnGuardarBulk', cancel: 'btnCancelBulk', formCard: 'formUnificadoCard',
    },
    datalistId: 'bulkTiposDatalist',
    previewUrl: '/admin/equipos/bulk-preview',
    storeUrl: '/admin/equipos/bulk-store-batch',
    redirect: (body) => body.redirect || '/admin/equipos',
    successMessage: 'Equipos creados correctamente',
    on422: 'modal',
    tipoField: 'tipo_equipo',
    // Año (4 dígitos) y N° Etiqueta: numéricos/cortos → ancho fijo compacto (se estrecharon
    // para dar ese espacio a Serial/Frente/Status, que necesitan más ancho horizontal).
    numHeaderStyle: 'width:32px;min-width:32px;max-width:32px;padding-left:4px;padding-right:4px;text-align:center;',
    rowNumStyle: 'text-align:center;padding-left:4px;padding-right:4px;',
    // Columna "Eliminar fila" (la X) más angosta; ese ancho se cede a Status.
    deleteHeaderStyle: 'width:26px;',
    columns: [
        { key: 'tipo_equipo', label: 'Tipo de Equipo', type: 'datalist' },
        { key: 'categoria_flota', label: 'Categoría de Flota', type: 'select', optionsKey: 'categorias' },
        { key: 'marca', label: 'Marca', type: 'text' },
        { key: 'modelo', label: 'Modelo', type: 'text' },
        { key: 'anio', label: 'Año', type: 'number', style: 'width:48px;min-width:48px;max-width:48px;' },
        { key: 'numero_etiqueta', label: 'N° Etiqueta', type: 'text', style: 'width:66px;min-width:66px;max-width:66px;' },
        { key: 'serial_chasis', label: 'Serial de Chasis', type: 'text', style: 'min-width:170px;' },
        { key: 'serial_de_motor', label: 'Serial de Motor', type: 'text', style: 'min-width:170px;' },
        { key: 'frente_trabajo', label: 'Frente de Trabajo', type: 'select', optionsKey: 'frentes', optionValue: o => o.nombre, style: 'min-width:170px;' },
        { key: 'status', label: 'Status', type: 'select', optionsKey: 'statuses', style: 'min-width:150px;' },
    ],
    collectRow: (tr) => {
        const data = {};
        tr.querySelectorAll('[data-field]').forEach(el => {
            if (el.matches('input,select')) data[el.dataset.field] = el.value.trim();
        });
        return data;
    },
});

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
