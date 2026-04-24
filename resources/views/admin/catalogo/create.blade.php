@extends('layouts.estructura_base')

@section('title', 'Nuevo Modelo - Catálogo')

@section('content')
<div style="max-width: 900px; margin: 0 auto;">
    <section class="page-title-card" style="margin: 0 auto 10px auto; text-align: center;">
        <h1 class="page-title">
            <span class="page-title-line2" style="color: #000;">Registro de Modelo</span>
        </h1>
    </section>

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

