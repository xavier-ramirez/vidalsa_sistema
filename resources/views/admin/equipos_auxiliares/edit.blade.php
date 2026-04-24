@extends('layouts.estructura_base')
@section('title', 'Editar Equipo Auxiliar')

@section('content')
<div class="dashboard-container" style="padding:20px;position:relative;z-index:1;">
    <div style="max-width:900px;margin:0 auto;background:white;border-radius:14px;box-shadow:0 2px 8px rgba(15,23,42,0.08);overflow:hidden;">
        <div style="background:#1e293b;color:white;padding:16px 22px;display:flex;justify-content:space-between;align-items:center;">
            <h2 style="margin:0;font-size:18px;font-weight:700;display:flex;align-items:center;gap:10px;">
                <i class="material-icons">edit</i>
                Editar Equipo Auxiliar #{{ $auxiliar->ID_AUXILIAR }}
            </h2>
            <a href="{{ route('equipos-auxiliares.acta', $auxiliar->ID_AUXILIAR) }}" target="_blank"
               style="background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.28);color:white;padding:6px 12px;border-radius:8px;text-decoration:none;font-size:12px;font-weight:700;display:inline-flex;align-items:center;gap:6px;">
                <i class="material-icons" style="font-size:16px;">description</i>
                Acta de Asignación
            </a>
        </div>
        <form action="{{ route('equipos-auxiliares.update', $auxiliar->ID_AUXILIAR) }}" method="POST" style="padding:22px;">
            @csrf
            @method('PATCH')
            @include('admin.equipos_auxiliares.partials.form_fields')
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:22px;">
                @can('super.admin')
                <form action="{{ route('equipos-auxiliares.destroy', $auxiliar->ID_AUXILIAR) }}" method="POST"
                      onsubmit="return confirm('¿Eliminar este equipo auxiliar? Esta acción no se puede deshacer.')" style="margin:0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            style="padding:10px 18px;border-radius:10px;background:#fee2e2;color:#991b1b;border:none;cursor:pointer;font-weight:700;font-size:13px;display:inline-flex;align-items:center;gap:6px;">
                        <i class="material-icons" style="font-size:16px;">delete</i> Eliminar
                    </button>
                </form>
                @else
                <span></span>
                @endcan
                <div style="display:flex;gap:10px;">
                    <a href="{{ route('equipos-auxiliares.index') }}"
                       style="padding:10px 18px;border-radius:10px;background:#f1f5f9;color:#475569;text-decoration:none;font-weight:700;font-size:13px;">
                        Cancelar
                    </a>
                    <button type="submit" class="btn-primary-maquinaria"
                            style="padding:10px 22px;border-radius:10px;font-weight:700;font-size:13px;border:none;cursor:pointer;display:flex;align-items:center;gap:8px;">
                        <i class="material-icons" style="font-size:18px;">save</i>
                        Guardar cambios
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
