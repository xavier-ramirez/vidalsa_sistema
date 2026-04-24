@extends('layouts.estructura_base')

@section('title', 'Registrar Equipo Auxiliar')

@section('content')
<section class="page-title-card" style="margin: 0 auto 10px auto; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Registro de Equipo Auxiliar</span>
    </h1>
</section>

<div id="formEquipoAuxiliarCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="createEquipoAuxiliarForm" action="{{ route('equipos-auxiliares.store') }}" method="POST" novalidate>
        @csrf

        @include('admin.equipos_auxiliares.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: center;">
            <a href="{{ route('equipos-auxiliares.index') }}" class="btn-primary-maquinaria btn-secondary">
                Cancelar
            </a>
            <button type="submit" class="btn-primary-maquinaria">
                <i class="material-icons">save</i>
                Registrar Equipo Auxiliar
            </button>
        </div>
    </form>
</div>
@endsection
