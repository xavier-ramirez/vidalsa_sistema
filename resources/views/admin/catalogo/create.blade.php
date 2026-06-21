@extends('layouts.estructura_base')

@section('title', 'Nuevo Modelo - Catálogo')

@section('content')
<style>
    @media (max-width: 768px) {
        .cat-create-wrapper {
            max-width: 100% !important;
            padding: 0 !important;
        }
        body:has(.cat-create-wrapper) .main-viewport {
            padding-left: 8px !important;
            padding-right: 8px !important;
            width: 100% !important;
            max-width: 100% !important;
        }
    }
</style>
<div class="cat-create-wrapper" style="max-width: 1100px; margin: 0 auto; padding: 0 12px;">
    {{-- Título con el MISMO markup que /admin/equipos y que el edit del catálogo
         (div flex + margin-bottom:16px): mismo tamaño y separación vertical. --}}
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
        <h1 class="page-title">
            <span class="page-title-line2" style="color: #000;">Registro de Modelo</span>
        </h1>
    </div>

    <div class="admin-card">
        <form id="catalogoForm" action="{{ route('catalogo.store') }}" method="POST" enctype="multipart/form-data">
            @csrf
            
            @include('admin.catalogo.partials.form_fields')

            <div style="margin-top: 30px; display: flex; gap: 12px; justify-content: center;">
                <a href="{{ route('catalogo.index') }}" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </a>
                <button type="submit" class="btn-primary-maquinaria"
                    @cannot('equipos.create')
                    onclick="event.preventDefault(); if(window.showToast) window.showToast('Acceso denegado: No tienes permiso para guardar este modelo.', 'error');"
                    @endcannot
                >
                    <i class="material-icons">save</i>
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

