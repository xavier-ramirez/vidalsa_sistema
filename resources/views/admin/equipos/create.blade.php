@extends('layouts.estructura_base')

@section('title', 'Registrar Equipo')

@section('content')
<section class="page-title-card" style="margin: 0 auto 10px auto; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Registro de Equipo y Maquinaria</span>
    </h1>
</section>

<div class="admin-card" style="max-width: 95%; margin: 0 auto;">
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
    {{-- No se requiere JS adicional en esta vista. El formulario es manejado por form_logic.js (global). --}}
@endsection
