@extends('layouts.estructura_base')

@section('title', 'Registrar Equipo')

@section('content')
<style>
    @media (max-width: 768px) {
        body:has(#formEquipoCard) .page-title-card {
            margin-bottom: 6px !important;
            padding: 4px 0 !important;
        }
    }
</style>
<section class="page-title-card" style="margin: 0 auto 6px auto; padding: 4px 0; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Registro de Equipo y Maquinaria</span>
    </h1>
</section>

@can('equipos.create')
@include('admin.partials.bulk_upload_card', [
    'suffix'        => '',
    'templateRoute' => 'equipos.bulkTemplate',
    'subtitulo'     => 'Descarga la plantilla, completa los equipos y súbelo para registrar varios a la vez.',
])
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
