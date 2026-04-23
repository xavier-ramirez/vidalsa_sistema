@extends('layouts.estructura_base')

@section('title', 'Registrar Equipo')

@section('content')
<section class="page-title-card" style="margin: 0 auto 10px auto; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Registro de Equipo y Maquinaria</span>
    </h1>
</section>

@can('equipos.create')
<div class="admin-card" style="max-width: 95%; margin: 0 auto 20px auto; padding: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
            <h3 style="margin: 0; color: #0067b1; font-size: 16px; font-weight: 700;">
                <i class="material-icons" style="vertical-align: middle;">upload_file</i>
                Carga Masiva desde Excel
            </h3>
            <p style="margin: 4px 0 0; color: #718096; font-size: 13px;">
                Descarga la plantilla, completa los equipos y súbelo para registrar varios a la vez.
            </p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('equipos.bulkTemplate') }}"
               id="btnDescargarPlantilla"
               class="btn-primary-maquinaria btn-secondary"
               data-no-spa
               download
               onclick="if(window.showPreloader) window.showPreloader(); setTimeout(() => { if(window.hidePreloader) window.hidePreloader(); }, 2500);">
                <i class="material-icons">download</i> Descargar Plantilla
            </a>
            <button type="button" id="btnCargarExcel" class="btn-primary-maquinaria">
                <i class="material-icons">upload</i> Cargar Excel
            </button>
            <input type="file" id="bulkExcelInput" accept=".xlsx,.xls" style="display: none;">
        </div>
    </div>

    <div id="bulkPreviewPanel" style="display: none; margin-top: 20px;">
        <div id="bulkPreviewHeader" style="margin-bottom: 12px; font-size: 14px; color: #4a5568;"></div>
        <div id="bulkPreviewTableWrapper" style="overflow: auto; max-height: 60vh; border: 1px solid #e2e8f0; border-radius: 8px;">
            <table id="bulkPreviewTable" class="bulk-preview-table"></table>
        </div>
        <div style="margin-top: 16px; display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" id="btnCancelBulk" class="btn-primary-maquinaria btn-secondary">Cancelar</button>
            <button type="button" id="btnGuardarBulk" class="btn-primary-maquinaria">
                <i class="material-icons">save</i> Guardar Todo
            </button>
        </div>
    </div>
</div>
@endcan

<div id="formEquipoCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="createEquipoForm" action="{{ route('equipos.store') }}" method="POST" enctype="multipart/form-data" novalidate>
        @csrf


        @include('admin.equipos.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('equipos.index') }}" class="btn-primary-maquinaria btn-secondary">
                Cancelar
            </a>
            <button type="submit" class="btn-primary-maquinaria"
                @cannot('equipos.create')
                onclick="event.preventDefault(); showModal({ type: 'error', title: 'Acceso Denegado', message: 'No tienes permiso para registrar equipos.', confirmText: 'Entendido', hideCancel: true });"
                @endcannot
            >
                <i class="material-icons">save</i>
                Registrar Equipo
            </button>
        </div>
    </form>
</div>



@endsection

@section('extra_js')
    {{-- equipos_bulk.js se carga globalmente desde layouts/estructura_base.blade.php
         para garantizar compatibilidad SPA (el @yield('extra_js') queda fuera del .main-viewport). --}}
@endsection
