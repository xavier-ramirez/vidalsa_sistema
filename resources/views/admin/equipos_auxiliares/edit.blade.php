@extends('layouts.estructura_base')

@section('title', 'Editar Equipo Auxiliar')

@section('content')
<section class="page-title-card" style="margin: 0 auto 10px auto; text-align: center;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Editar Equipo Auxiliar #{{ $auxiliar->ID_AUXILIAR }}</span>
    </h1>
    <div style="margin-top: 10px;">
        <a href="{{ route('equipos-auxiliares.acta', $auxiliar->ID_AUXILIAR) }}" target="_blank"
           class="btn-primary-maquinaria btn-secondary"
           style="display: inline-flex; align-items: center; gap: 6px;">
            <i class="material-icons" style="font-size: 18px;">description</i>
            Acta de Asignación
        </a>
    </div>
</section>

<div id="formEquipoAuxiliarCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="editEquipoAuxiliarForm" action="{{ route('equipos-auxiliares.update', $auxiliar->ID_AUXILIAR) }}" method="POST" novalidate>
        @csrf
        @method('PATCH')

        @include('admin.equipos_auxiliares.partials.form_fields')

        <div style="margin-top: 40px; display: flex; gap: 12px; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                @can('super.admin')
                <form action="{{ route('equipos-auxiliares.destroy', $auxiliar->ID_AUXILIAR) }}" method="POST"
                      onsubmit="return confirm('¿Eliminar este equipo auxiliar? Esta acción no se puede deshacer.')"
                      style="margin: 0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-primary-maquinaria"
                            style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">
                        <i class="material-icons">delete</i>
                        Eliminar
                    </button>
                </form>
                @endcan
            </div>
            <div style="display: flex; gap: 12px;">
                <a href="{{ route('equipos-auxiliares.index') }}" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </a>
                <button type="submit" class="btn-primary-maquinaria">
                    <i class="material-icons">save</i>
                    Guardar cambios
                </button>
            </div>
        </div>
    </form>
</div>
@endsection
