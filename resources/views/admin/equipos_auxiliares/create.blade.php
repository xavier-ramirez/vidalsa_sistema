@extends('layouts.estructura_base')
@section('title', 'Nuevo Equipo Auxiliar')

@section('content')
<div class="dashboard-container" style="padding:20px;position:relative;z-index:1;">
    <div style="max-width:900px;margin:0 auto;background:white;border-radius:14px;box-shadow:0 2px 8px rgba(15,23,42,0.08);overflow:hidden;">
        <div style="background:#1e293b;color:white;padding:16px 22px;">
            <h2 style="margin:0;font-size:18px;font-weight:700;display:flex;align-items:center;gap:10px;">
                <i class="material-icons">add_circle</i>
                Registrar Equipo Auxiliar
            </h2>
        </div>
        <form action="{{ route('equipos-auxiliares.store') }}" method="POST" style="padding:22px;">
            @csrf
            @include('admin.equipos_auxiliares.partials.form_fields')
            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:22px;">
                <a href="{{ route('equipos-auxiliares.index') }}"
                   style="padding:10px 18px;border-radius:10px;background:#f1f5f9;color:#475569;text-decoration:none;font-weight:700;font-size:13px;">
                    Cancelar
                </a>
                <button type="submit" class="btn-primary-maquinaria"
                        style="padding:10px 22px;border-radius:10px;font-weight:700;font-size:13px;border:none;cursor:pointer;display:flex;align-items:center;gap:8px;">
                    <i class="material-icons" style="font-size:18px;">save</i>
                    Guardar
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
