@extends('layouts.estructura_base')

@section('title', 'Gestión de Frentes')

@section('content')
@include('admin.partials.page_header', [
    'titulo'  => 'Gestión de Frentes de Trabajo',
    'align'   => 'left',
    'margin'  => '0 0 16px 0',
    'padding' => '0',
])

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
{{-- Mismo contenedor que /admin/equipos: la tarjeta blanca vive dentro de
     .page-layout-grid para heredar el ancho/márgenes móviles globales (8px
     laterales, full-width) sin reglas .main-viewport propias. --}}
<div class="page-layout-grid frentes-grid">
<div class="admin-card frentes-card">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-bottom:10px;">
        <div class="frentes-search-row">
            <form action="{{ route('frentes.index') }}" method="GET" style="flex:1 1 0;min-width:0;">
                <div class="search-wrapper" style="width:100%;border-color:{{ request('search') ? '#0067b1' : '#cbd5e0' }};background:{{ request('search') ? '#e1effa' : '#fbfcfd' }};height:45px;">
                    <i class="material-icons search-icon">search</i>
                    <input type="text" name="search" value="{{ request('search') }}"
                        placeholder="Buscar frente..."
                        class="search-input-field"
                        style="height:100%;"
                        autocomplete="off">
                    @if(request('search'))
                        <a href="{{ route('frentes.index') }}" title="Limpiar búsqueda" style="display:flex;align-items:center;padding:0 8px;">
                            <i class="material-icons" style="font-size:18px;color:#64748b;">close</i>
                        </a>
                    @endif
                </div>
            </form>
            @if(isset($sinEquipos) && $sinEquipos > 0)
            <button type="button" onclick="window.location.href='{{ route('frentes.index', ['sin_equipos' => 1]) }}'" class="btn-primary-maquinaria" style="height:45px;padding:0 12px;display:flex;align-items:center;gap:6px;background:{{ request('sin_equipos') ? '#fee2e2' : '#fff' }};border:1px solid {{ request('sin_equipos') ? '#ef4444' : '#cbd5e0' }};color:{{ request('sin_equipos') ? '#ef4444' : '#64748b' }};box-shadow:none;white-space:nowrap;flex-shrink:0;">
                <i class="material-icons" style="font-size:18px;">domain_disabled</i>
                <span>Sin equipos</span>
            </button>
            @endif
        </div>
        <a href="{{ route('frentes.create') }}" class="btn-primary-maquinaria" style="height:45px;padding:0 15px;display:flex;align-items:center;gap:8px;flex-shrink:0;margin-left:auto;">
            <i class="material-icons">add_circle</i>
            <span>Nuevo Frente</span>
        </a>
    </div>

    <div class="frentes-table-scroll">
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
</div>{{-- /.frentes-grid --}}

<style>
.frentes-search-row {
    display: flex;
    gap: 8px;
    align-items: center;
    flex: 1 1 0;
    min-width: 0;
}

/* Frentes no tiene sidebar: en escritorio el grid es de una sola columna
   (page-layout-grid base es 1fr 300px, pensado para módulos con contador). */
@media (min-width: 769px) {
    .frentes-grid { grid-template-columns: 1fr !important; }
}

/* Scroll horizontal propio de la tabla. NO usamos .custom-scrollbar-container
   porque el global lo fuerza a overflow:visible en móvil (para módulos que
   convierten la tabla en tarjetas, que frentes no hace) y recortaría las
   columnas derechas. */
.frentes-table-scroll {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

@media (max-width: 768px) {
    /* El ancho/margen del contenedor blanco lo da .page-layout-grid (global). */
    .page-title-card { margin-bottom: 10px !important; }
    .dashboard-stats-grid { grid-template-columns: 1fr 1fr !important; gap: 8px !important; margin-bottom: 12px !important; }
    .stat-card { padding: 10px !important; }
    .stat-card div:last-child div:first-child { font-size: 11px !important; }
    .stat-card div:last-child div:last-child { font-size: 20px !important; }
    /* Padding interno compacto para aprovechar el ancho en móvil. */
    .frentes-card { padding: 12px 10px !important; }
    /* La tabla no se aplasta: mantiene un ancho mínimo legible y se desliza. */
    .frentes-table-scroll .admin-table { min-width: 640px; }
}
</style>
@endsection
