@extends('layouts.estructura_base')

@section('title', 'Editar Equipo Auxiliar')

@section('content')
@include('admin.equipos_auxiliares.partials.form_estilos')
@include('admin.partials.page_header', [
    'titulo'  => 'Edición de Equipo Auxiliar',
    'align'   => 'center',
    'margin'  => '0 auto 6px auto',
    'padding' => '4px 0',
])

<div id="formEquipoAuxiliarCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="equipoAuxiliarForm" action="{{ route('equipos-auxiliares.update', $auxiliar->ID_AUXILIAR) }}" method="POST" enctype="multipart/form-data" novalidate data-is-edit="1">
        @csrf
        @method('PATCH')
        @if(!empty($ref))
            <input type="hidden" name="__unified_redirect" value="{{ $ref }}">
        @endif

        @include('admin.equipos_auxiliares.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: center; align-items: center; flex-wrap: wrap;">
            <a href="{{ $ref ?: route('equipos.index') }}" class="btn-primary-maquinaria btn-secondary">
                Cancelar
            </a>
            <button type="submit" class="btn-primary-maquinaria">
                <i class="material-icons">save</i>
                Guardar
            </button>
        </div>
    </form>
</div>

@include('admin.equipos_auxiliares.partials.form_envio', [
    'verbo'    => 'actualizado',
    'verboMal' => 'actualizar',
    'accion'   => 'update',
])
@endsection
