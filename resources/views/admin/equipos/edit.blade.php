@extends('layouts.estructura_base')

@section('title', 'Editar Equipo')

@section('content')
<section class="page-title-card" style="max-width: 95%; margin: 0 auto;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Edición de Equipo</span>
    </h1>
</section>

<div class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="editEquipoForm" action="{{ route('equipos.update', $equipo->ID_EQUIPO) }}" method="POST" enctype="multipart/form-data" novalidate>
        @csrf
        @method('PUT')


        @include('admin.equipos.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('equipos.index') }}" class="btn-primary-maquinaria btn-secondary">
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
