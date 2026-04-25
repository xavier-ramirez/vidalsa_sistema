@extends('layouts.estructura_base')
@section('title', 'Catálogo de Equipos Auxiliares')

@section('content')
<style>
    /* Layout grid responsivo de cards */
    .aux-cat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
        gap: 16px;
        margin-top: 18px;
    }
    .aux-cat-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .aux-cat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 18px -6px rgba(15,23,42,0.12);
    }
    .aux-cat-photo {
        width: 100%;
        aspect-ratio: 16 / 11;
        background: #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
    }
    .aux-cat-photo img {
        width: 100%; height: 100%;
        object-fit: cover;
    }
    .aux-cat-photo .placeholder {
        color: #cbd5e0;
        font-size: 56px;
    }
    .aux-cat-tipo-badge {
        position: absolute;
        top: 10px; left: 10px;
        background: rgba(15,23,42,0.85);
        color: white;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 4px 10px;
        border-radius: 999px;
    }
    .aux-cat-total-badge {
        position: absolute;
        top: 10px; right: 10px;
        background: var(--maquinaria-blue, #0067b1);
        color: white;
        font-size: 11px;
        font-weight: 700;
        padding: 4px 10px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .aux-cat-body {
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .aux-cat-marca {
        font-size: 11px;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.6px;
    }
    .aux-cat-modelo {
        font-size: 15px;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
    }
    .aux-cat-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 4px;
    }
    .aux-cat-chip {
        background: #f1f5f9;
        color: #475569;
        font-size: 11px;
        font-weight: 600;
        padding: 3px 9px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .aux-cat-stats {
        display: flex;
        gap: 6px;
        margin-top: 8px;
        padding-top: 10px;
        border-top: 1px dashed #e2e8f0;
    }
    .aux-cat-stat {
        flex: 1;
        text-align: center;
        background: #f8fafc;
        border-radius: 8px;
        padding: 6px 4px;
    }
    .aux-cat-stat-num {
        font-size: 16px;
        font-weight: 800;
        line-height: 1;
    }
    .aux-cat-stat-lbl {
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        margin-top: 2px;
    }
    .aux-cat-empty {
        background: white;
        border: 1px dashed #cbd5e0;
        border-radius: 14px;
        padding: 60px 20px;
        text-align: center;
        color: #94a3b8;
        margin-top: 20px;
    }
    .aux-cat-empty .material-icons {
        font-size: 56px;
        color: #cbd5e0;
        display: block;
        margin: 0 auto 10px;
    }
    /* Toolbar de filtros: simple, alineado horizontalmente */
    #auxCatFilters {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin-top: 8px;
    }
    #auxCatFilters .filter-control {
        flex: 1 1 220px;
        min-width: 180px;
        max-width: 280px;
        display: flex;
        align-items: center;
        background: #fbfcfd;
        border: 1px solid #cbd5e0;
        border-radius: 12px;
        height: 45px;
        padding: 0 12px;
        gap: 8px;
    }
    #auxCatFilters .filter-control.active {
        background: #e1effa;
        border-color: var(--maquinaria-blue, #0067b1);
    }
    #auxCatFilters .filter-control input,
    #auxCatFilters .filter-control select {
        flex: 1;
        border: none;
        background: transparent;
        outline: none;
        font-size: 14px;
        color: #1e293b;
        min-width: 0;
    }
    #auxCatFilters .filter-control select {
        cursor: pointer;
    }
    @media (max-width: 600px) {
        .aux-cat-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
    }
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-title" style="margin-bottom:2px;">
            <span class="page-title-line2" style="color:#000;">Catálogo de Auxiliares</span>
        </h1>
        <p style="margin:0;font-size:12px;color:#64748b;font-weight:500;line-height:1.3;">
            Modelos agrupados por tipo, marca y capacidad. Vista consolidada de la flota auxiliar.
        </p>
    </div>
    <a href="{{ route('equipos-auxiliares.index') }}" class="btn-primary-maquinaria btn-secondary"
       style="padding:10px 16px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;height:45px;">
        <i class="material-icons" style="font-size:18px;">arrow_back</i>
        Volver
    </a>
</div>

<div class="admin-card" style="margin:0;min-height:60vh;padding:14px;">

    <form id="auxCatFilters" onsubmit="event.preventDefault();" method="GET" action="{{ route('equipos-auxiliares.catalogo') }}">
        <div class="filter-control {{ request('search') ? 'active' : '' }}">
            <i class="material-icons" style="color:#64748b;font-size:18px;">search</i>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Marca, modelo o capacidad...">
        </div>

        <div class="filter-control {{ request('tipo') && request('tipo')!=='all' ? 'active' : '' }}">
            <i class="material-icons" style="color:#64748b;font-size:18px;">category</i>
            <select name="tipo" onchange="this.form.submit()">
                <option value="all">Todos los tipos</option>
                @foreach($tipos as $code => $label)
                    <option value="{{ $code }}" {{ request('tipo')==$code?'selected':'' }}>{{ strtoupper($label) }}</option>
                @endforeach
            </select>
        </div>

        <div class="filter-control {{ request('marca') && request('marca')!=='all' ? 'active' : '' }}">
            <i class="material-icons" style="color:#64748b;font-size:18px;">factory</i>
            <select name="marca" onchange="this.form.submit()">
                <option value="all">Todas las marcas</option>
                @foreach($marcas as $m)
                    <option value="{{ $m }}" {{ request('marca')==$m?'selected':'' }}>{{ $m }}</option>
                @endforeach
            </select>
        </div>

        <button type="submit" class="btn-primary-maquinaria" style="height:45px;padding:0 16px;display:inline-flex;align-items:center;gap:6px;">
            <i class="material-icons" style="font-size:18px;">filter_list</i>
            Aplicar
        </button>
        @if(request('search') || request('tipo') || request('marca'))
            <a href="{{ route('equipos-auxiliares.catalogo') }}" class="btn-primary-maquinaria btn-secondary"
               style="height:45px;padding:0 14px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;">
                <i class="material-icons" style="font-size:18px;">close</i>
                Limpiar
            </a>
        @endif
    </form>

    @if($items->isEmpty())
        <div class="aux-cat-empty">
            <i class="material-icons">inventory_2</i>
            <div style="font-size:14px;font-weight:600;color:#475569;margin-bottom:4px;">Sin modelos en el catálogo</div>
            <div style="font-size:12px;">No hay auxiliares registrados que coincidan con los filtros.</div>
        </div>
    @else
        <div class="aux-cat-grid">
            @foreach($items as $it)
                @php
                    $foto = $it['foto'] ?? null;
                    $tipoLabel = strtoupper($it['tipo_label']);
                    $linkFiltro = route('equipos-auxiliares.index', [
                        'tipo'  => $it['tipo'],
                        'marca' => $it['marca'] !== '—' ? $it['marca'] : null,
                        'modelo'=> $it['modelo'] !== '—' ? $it['modelo'] : null,
                    ]);
                @endphp
                <a class="aux-cat-card" href="{{ $linkFiltro }}" style="text-decoration:none;color:inherit;" title="Ver auxiliares de este modelo">
                    <div class="aux-cat-photo">
                        @if($foto)
                            <img src="{{ asset($foto) }}" alt="{{ $it['marca'] }} {{ $it['modelo'] }}"
                                 onerror="this.outerHTML='<i class=&quot;material-icons placeholder&quot;>image_not_supported</i>'">
                        @else
                            <i class="material-icons placeholder">construction</i>
                        @endif
                        <span class="aux-cat-tipo-badge">{{ $tipoLabel }}</span>
                        <span class="aux-cat-total-badge">
                            <i class="material-icons" style="font-size:13px;">inventory</i>
                            {{ $it['total'] }}
                        </span>
                    </div>
                    <div class="aux-cat-body">
                        <span class="aux-cat-marca">{{ $it['marca'] }}</span>
                        <span class="aux-cat-modelo">{{ $it['modelo'] }}</span>
                        <div class="aux-cat-meta">
                            @if($it['capacidad'])
                                <span class="aux-cat-chip"><i class="material-icons" style="font-size:13px;">bolt</i>{{ $it['capacidad'] }}</span>
                            @endif
                            @if($it['rango_anios'])
                                <span class="aux-cat-chip"><i class="material-icons" style="font-size:13px;">event</i>{{ $it['rango_anios'] }}</span>
                            @endif
                        </div>
                        <div class="aux-cat-stats">
                            <div class="aux-cat-stat" style="background:#f0fdf4;">
                                <div class="aux-cat-stat-num" style="color:#16a34a;">{{ $it['operativos'] }}</div>
                                <div class="aux-cat-stat-lbl">Operativos</div>
                            </div>
                            <div class="aux-cat-stat" style="background:#fef2f2;">
                                <div class="aux-cat-stat-num" style="color:#dc2626;">{{ $it['inoperativos'] }}</div>
                                <div class="aux-cat-stat-lbl">Inoper.</div>
                            </div>
                            <div class="aux-cat-stat" style="background:#fffbeb;">
                                <div class="aux-cat-stat-num" style="color:#d97706;">{{ $it['en_almacen'] }}</div>
                                <div class="aux-cat-stat-lbl">Almacén</div>
                            </div>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
