@extends('layouts.estructura_base')

@section('title', 'Editar Equipo y Maquinaria')

@section('content')
<style>
    @media (max-width: 768px) {
        body:has(#editEquipoForm) .page-title-card {
            margin-bottom: 6px !important;
            padding: 4px 0 !important;
        }
        body:has(#editEquipoForm) .main-viewport {
            width: 100% !important;
            max-width: 100% !important;
            padding-left: 8px !important;
            padding-right: 8px !important;
            box-sizing: border-box !important;
        }
        body:has(#editEquipoForm) .admin-card {
            width: 100% !important;
            max-width: 100% !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
    }
</style>
@include('admin.partials.page_header', [
    'titulo'  => 'Edición de Equipo y Maquinaria',
    'align'   => 'center',
    'margin'  => '0 auto 6px auto',
    'padding' => '4px 0',
])

<div class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="editEquipoForm" action="{{ route('equipos.update', $equipo->ID_EQUIPO) }}" method="POST" enctype="multipart/form-data" novalidate>
        @csrf
        @method('PUT')

        {{-- Listado al que volver con sus filtros (frente/búsqueda) al Cancelar o Guardar.
             Lo provee el controlador ($returnUrl, saneado contra open-redirect); el lápiz
             del modal de detalles lo manda como ?return=…. Sin esto la tabla volvía vacía. --}}
        <input type="hidden" name="return_url" value="{{ $returnUrl ?? route('equipos.index') }}">

        @include('admin.equipos.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: center;">
            <a href="{{ $returnUrl ?? route('equipos.index') }}" class="btn-primary-maquinaria btn-secondary">
                Cancelar
            </a>
            <button type="submit" class="btn-primary-maquinaria"
                @cannot('user.edit')
                onclick="event.preventDefault(); showModal({ type: 'error', title: 'Acceso Denegado', message: 'No tienes permiso para actualizar la información del equipo.', confirmText: 'Entendido', hideCancel: true });"
                @endcannot
            >
                <i class="material-icons">save</i>
                Actualizar Equipo
            </button>
        </div>
    </form>
</div>
@endsection

@section('extra_js')
    {{-- JS manejado por form_logic.js y equipos_form.js (cargados globalmente). --}}
@endsection
