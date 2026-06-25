{{--
    Tarjeta "Carga Masiva desde Excel" — partial compartido entre
    /admin/equipos/create y /admin/equipos-auxiliares/create.

    @param string $suffix      Sufijo para los ids del DOM ('' para equipos, 'Aux' para auxiliares).
    @param string $templateRoute  Nombre de la ruta de descarga de la plantilla.
    @param string $subtitulo   Texto descriptivo debajo del título.
--}}
@php
    $sfx = $suffix ?? '';
    $cardId = 'bulkUploadCard' . $sfx;
@endphp

<style>
    @media (max-width: 768px) {
        #{{ $cardId }} { display: none !important; }
    }
    #{{ $cardId }} .btn-primary-maquinaria { padding: 7px 13px; font-size: 12.5px; }
    #{{ $cardId }} .btn-primary-maquinaria .material-icons { font-size: 16px; }
</style>
<div id="{{ $cardId }}" class="admin-card" style="max-width: 95%; margin: 0 auto 20px auto; padding: 10px 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 style="margin: 0; color: #0067b1; font-size: 14.5px; font-weight: 700;">
                <i class="material-icons" style="vertical-align: middle;">upload_file</i>
                Carga Masiva desde Excel
            </h3>
            <p style="margin: 4px 0 0; color: #718096; font-size: 12px;">
                {{ $subtitulo ?? 'Descarga la plantilla, completa los datos y súbelo para registrar varios a la vez.' }}
            </p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route($templateRoute) }}"
               id="btnDescargarPlantilla{{ $sfx }}"
               class="btn-primary-maquinaria btn-secondary"
               data-no-spa
               download
               onclick="if(window.showPreloader) window.showPreloader(); setTimeout(() => { if(window.hidePreloader) window.hidePreloader(); }, 2500);">
                <i class="material-icons">download</i> Descargar Plantilla
            </a>
            <button type="button" id="btnCargarExcel{{ $sfx }}" class="btn-primary-maquinaria">
                <i class="material-icons">upload</i> Cargar Excel
            </button>
            <input type="file" id="bulkExcelInput{{ $sfx }}" accept=".xlsx,.xls" style="display: none;">
        </div>
    </div>

    <div id="bulkPreviewPanel{{ $sfx }}" style="display: none; margin-top: 20px;">
        <div id="bulkPreviewHeader{{ $sfx }}" style="margin-bottom: 12px; font-size: 14px; color: #4a5568;"></div>
        <div id="bulkPreviewTableWrapper{{ $sfx }}" style="overflow: auto; max-height: 60vh; border: 1px solid #e2e8f0; border-radius: 8px;">
            <table id="bulkPreviewTable{{ $sfx }}" class="bulk-preview-table"></table>
        </div>
        <div style="margin-top: 16px; display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" id="btnCancelBulk{{ $sfx }}" class="btn-primary-maquinaria btn-secondary">Cancelar</button>
            <button type="button" id="btnGuardarBulk{{ $sfx }}" class="btn-primary-maquinaria">
                <i class="material-icons">save</i> Guardar Todo
            </button>
        </div>
    </div>
</div>
