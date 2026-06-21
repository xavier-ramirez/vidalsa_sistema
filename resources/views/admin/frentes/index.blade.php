@extends('layouts.estructura_base')

@section('title', 'Gestión de Frentes')

@section('content')
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Gestión de Frentes de Trabajo</span>
    </h1>
    <a href="{{ route('frentes.create') }}" class="btn-primary-maquinaria" style="height:45px; padding:0 15px; display:flex; align-items:center; gap:8px; flex-shrink:0;">
        <i class="material-icons">add_circle</i>
        <span>Nuevo Frente</span>
    </a>
</div>

<!-- Stats Cards -->
<div class="dashboard-stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 20px;">
    <!-- Frentes Activos -->
    <div class="stat-card" style="background: white; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
        <div style="background: #ecfdf5; padding: 10px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
            <i class="material-icons" style="color: #10b981; font-size: 24px;">check_circle</i>
        </div>
        <div>
            <div style="font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Activos</div>
            <div style="font-size: 24px; font-weight: 800; color: #1e293b;">{{ $activos ?? 0 }}</div>
        </div>
    </div>

    <!-- Frentes Finalizados -->
    <div class="stat-card" style="background: white; padding: 15px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
        <div style="background: #fef2f2; padding: 10px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
            <i class="material-icons" style="color: #ef4444; font-size: 24px;">archive</i>
        </div>
        <div>
            <div style="font-size: 13px; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Finalizados</div>
            <div style="font-size: 24px; font-weight: 800; color: #1e293b;">{{ $finalizados ?? 0 }}</div>
        </div>
    </div>
</div>

<!-- Search & Data Table -->
<div class="admin-card">
    <div class="admin-card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h2 class="admin-card-title" style="font-size: 16px; font-weight: 700; color: #1e293b; margin: 0;">
            Listado General
        </h2>
        
        <!-- Search Form -->
        <form action="{{ route('frentes.index') }}" method="GET" style="display: flex; align-items: center; background: #f8fafc; padding: 5px 10px; border-radius: 8px; border: 1px solid #cbd5e0;">
            <i class="material-icons" style="font-size: 18px; color: #94a3b8;">search</i>
            <input type="text" name="search" value="{{ request('search') }}" 
                placeholder="Buscar frente..." 
                style="border: none; background: transparent; padding: 5px; outline: none; font-size: 13px; width: 200px; color: #475569;">
            @if(request('search'))
                <a href="{{ route('frentes.index') }}" title="Limpiar búsqueda">
                    <i class="material-icons" style="font-size: 16px; color: #ef4444; cursor: pointer;">close</i>
                </a>
            @endif
        </form>
    </div>

    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Nombre del Frente</th>
                    <th style="width: 20%;">Ubicación</th>
                    <th style="width: 10%;">Tipo</th>
                    <th style="width: 25%;">Sub-divisiones / Patios</th>
                    <th style="width: 10%;">Estatus</th>
                    <th style="width: 10%; text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse($frentes as $frente)
                    <tr>
                        <td>
                            <div style="font-weight: 700; color: #1e293b;">{{ $frente->NOMBRE_FRENTE }}</div>
                            <div style="font-size: 11px; color: #64748b;">Resp: {{ $frente->RESP_1_NOM }}</div>
                        </td>
                        <td>
                            <div style="display: flex; align-items: center; gap: 5px;">
                                <i class="material-icons" style="font-size: 14px; color: #94a3b8;">place</i>
                                <span style="color: #475569; font-size: 13px;">{{ $frente->UBICACION }}</span>
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $frente->TIPO_FRENTE == 'OPERACION' ? 'badge-blue' : ($frente->TIPO_FRENTE == 'ESPECIAL' ? 'badge-orange' : 'badge-purple') }}"
                                  style="padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700;">
                                {{ $frente->TIPO_FRENTE }}
                            </span>
                        </td>
                        <td>
                            @if(!empty($frente->SUBDIVISIONES))
                                <div style="display: flex; flex-wrap: wrap; gap: 4px;">
                                    @foreach(explode(',', $frente->SUBDIVISIONES) as $sub)
                                        @php $sub = trim($sub); @endphp
                                        @if(!empty($sub))
                                            <span style="background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; padding: 2px 6px; border-radius: 4px; font-size: 11px;">
                                                {{ $sub }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @else
                                <span style="color: #cbd5e0; font-size: 12px; font-style: italic;">Sin subdivisiones</span>
                            @endif
                        </td>
                        <td>
                            <span class="status-indicator {{ $frente->ESTATUS_FRENTE == 'ACTIVO' ? 'status-active' : 'status-inactive' }}">
                                {{ $frente->ESTATUS_FRENTE }}
                            </span>
                        </td>
                        <td style="text-align: right;">
                            <a href="{{ route('frentes.edit', $frente->ID_FRENTE) }}" 
                               class="btn-icon-action" 
                               title="Editar Frente">
                                <i class="material-icons">edit</i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: #94a3b8;">
                            <i class="material-icons" style="font-size: 48px; margin-bottom: 10px; opacity: 0.5;">search_off</i>
                            <div>No se encontraron frentes de trabajo.</div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <div style="padding: 15px; border-top: 1px solid #f1f5f9;">
        {{ $frentes->links() }}
    </div>
</div>
@endsection
