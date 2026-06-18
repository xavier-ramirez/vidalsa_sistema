@extends('layouts.estructura_base')

@section('title', 'Editar Modelo - Catálogo')

@section('content')
<div style="max-width: 1100px; margin: 0 auto; padding: 0 12px;">
    {{-- Título con el MISMO markup que /admin/equipos (div flex + margin-bottom:16px,
         .page-title/.page-title-line2): mismo tamaño y separación vertical, en móvil
         baja a 18px por la regla global responsive. --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h1 class="page-title">
            <span class="page-title-line2" style="color: #000;">Edición: {{ $catalogo->MODELO }}</span>
        </h1>
    </div>

    <div class="admin-card">
        <form id="catalogoForm" action="{{ route('catalogo.update', $catalogo->ID_ESPEC) }}" method="POST" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            
            @include('admin.catalogo.partials.form_fields')

            <div style="margin-top: 30px; display: flex; gap: 12px; justify-content: center;">
                <a href="{{ route('catalogo.index') }}" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </a>
                <button type="submit" class="btn-primary-maquinaria"
                    @cannot('equipos.create')
                    onclick="event.preventDefault(); if(window.showToast) window.showToast('Acceso denegado: No tienes permiso para actualizar este modelo.', 'error');"
                    @endcannot
                >
                    <i class="material-icons">save</i>
                    Actualizar
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
