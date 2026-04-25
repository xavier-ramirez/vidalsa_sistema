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

    /* Toolbar de filtros — mismo patron visual que /admin/equipos-auxiliares */
    #auxCatFilters {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin-top: 8px;
    }
    .aux-cat-filter {
        flex: 1 1 220px;
        min-width: 180px;
        max-width: 280px;
        position: relative;
    }
    .aux-cat-filter-box {
        display: flex;
        align-items: center;
        background: #fbfcfd;
        border: 1px solid #cbd5e0;
        border-radius: 12px;
        height: 45px;
        overflow: hidden;
    }
    .aux-cat-filter.active .aux-cat-filter-box {
        background: #e1effa;
        border-color: var(--maquinaria-blue, #0067b1);
    }
    .aux-cat-filter input[type="text"] {
        flex: 1;
        border: none;
        background: transparent;
        outline: none;
        font-size: 14px;
        color: #1e293b;
        padding: 10px 5px;
        min-width: 0;
    }
    .aux-cat-filter .filter-clear {
        padding: 0 8px;
        color: #64748b;
        font-size: 18px;
        cursor: pointer;
    }
    .aux-cat-list {
        position: absolute;
        top: calc(100% + 4px);
        left: 0; right: 0;
        background: white;
        border: 1px solid #cbd5e0;
        border-radius: 10px;
        box-shadow: 0 4px 12px rgba(15,23,42,0.08);
        max-height: 240px;
        overflow-y: auto;
        z-index: 50;
        display: none;
    }
    .aux-cat-opt {
        padding: 10px 14px;
        font-size: 13px;
        color: #334155;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
    }
    .aux-cat-opt:last-child { border-bottom: none; }
    .aux-cat-opt:hover { background: #f1f5f9; }
    .aux-cat-opt.placeholder { color: #94a3b8; font-style: italic; }
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
            Una tarjeta por modelo+año. La foto representa a TODAS las unidades de ese modelo y año.
        </p>
    </div>
    <a href="{{ route('equipos-auxiliares.index') }}" class="btn-primary-maquinaria btn-secondary"
       style="padding:10px 16px;display:inline-flex;align-items:center;gap:6px;text-decoration:none;height:45px;">
        <i class="material-icons" style="font-size:18px;">arrow_back</i>
        Volver
    </a>
</div>

<div class="admin-card" style="margin:0;min-height:60vh;padding:14px;">

    {{-- Filtros: mismo estilo que /admin/equipos-auxiliares (autocomplete con
         lista desplegable). Al elegir/limpiar se hace submit automaticamente.
         No hay boton "Aplicar". --}}
    @php
        $reqSearch = request('search');
        $reqTipo   = request('tipo');
        $reqMarca  = request('marca');
        $tipoLabel = ($reqTipo && $reqTipo !== 'all') ? strtoupper($tipos[$reqTipo] ?? $reqTipo) : '';
        $marcaLabel = ($reqMarca && $reqMarca !== 'all') ? $reqMarca : '';
    @endphp
    <form id="auxCatFilters" method="GET" action="{{ route('equipos-auxiliares.catalogo') }}"
          onsubmit="event.preventDefault(); auxCatSubmit();">

        {{-- Search libre --}}
        <div class="aux-cat-filter {{ $reqSearch ? 'active' : '' }}">
            <div class="aux-cat-filter-box">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                <input type="text" id="auxCatSearch" name="search" value="{{ $reqSearch }}"
                       placeholder="Marca, modelo o capacidad..." autocomplete="off"
                       oninput="auxCatDebounce()">
                @if($reqSearch)
                    <i class="material-icons filter-clear" onclick="document.getElementById('auxCatSearch').value=''; auxCatSubmit();">close</i>
                @endif
            </div>
        </div>

        {{-- Tipo --}}
        <div class="aux-cat-filter {{ $reqTipo && $reqTipo !== 'all' ? 'active' : '' }}" data-cat-role="dropdown">
            <input type="hidden" id="auxCatValTipo" name="tipo" value="{{ $reqTipo && $reqTipo !== 'all' ? $reqTipo : '' }}">
            <div class="aux-cat-filter-box">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">category</i></div>
                <input type="text" id="auxCatTxtTipo" placeholder="{{ $tipoLabel ?: 'Filtrar Tipo...' }}"
                       value="{{ $tipoLabel }}" autocomplete="off"
                       oninput="auxCatFilterList('tipo', this.value)"
                       onfocus="auxCatOpenList('tipo')"
                       onblur="setTimeout(()=>auxCatCloseList('tipo'),200)">
                @if($reqTipo && $reqTipo !== 'all')
                    <i class="material-icons filter-clear" onmousedown="event.preventDefault(); auxCatSelect('tipo','','');">close</i>
                @endif
            </div>
            <div id="auxCatListTipo" class="aux-cat-list">
                <div class="aux-cat-opt placeholder" data-label="TODOS LOS TIPOS"
                     onmousedown="event.preventDefault(); auxCatSelect('tipo','','TODOS LOS TIPOS');">TODOS LOS TIPOS</div>
                @foreach($tipos as $code => $label)
                    <div class="aux-cat-opt" data-label="{{ strtoupper($label) }}"
                         onmousedown="event.preventDefault(); auxCatSelect('tipo','{{ $code }}','{{ addslashes(strtoupper($label)) }}');">
                        {{ strtoupper($label) }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Marca --}}
        <div class="aux-cat-filter {{ $reqMarca && $reqMarca !== 'all' ? 'active' : '' }}" data-cat-role="dropdown">
            <input type="hidden" id="auxCatValMarca" name="marca" value="{{ $reqMarca && $reqMarca !== 'all' ? $reqMarca : '' }}">
            <div class="aux-cat-filter-box">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">factory</i></div>
                <input type="text" id="auxCatTxtMarca" placeholder="{{ $marcaLabel ?: 'Filtrar Marca...' }}"
                       value="{{ $marcaLabel }}" autocomplete="off"
                       oninput="auxCatFilterList('marca', this.value)"
                       onfocus="auxCatOpenList('marca')"
                       onblur="setTimeout(()=>auxCatCloseList('marca'),200)">
                @if($reqMarca && $reqMarca !== 'all')
                    <i class="material-icons filter-clear" onmousedown="event.preventDefault(); auxCatSelect('marca','','');">close</i>
                @endif
            </div>
            <div id="auxCatListMarca" class="aux-cat-list">
                <div class="aux-cat-opt placeholder" data-label="TODAS LAS MARCAS"
                     onmousedown="event.preventDefault(); auxCatSelect('marca','','TODAS LAS MARCAS');">TODAS LAS MARCAS</div>
                @foreach($marcas as $m)
                    <div class="aux-cat-opt" data-label="{{ $m }}"
                         onmousedown="event.preventDefault(); auxCatSelect('marca','{{ $m }}','{{ addslashes($m) }}');">
                        {{ $m }}
                    </div>
                @endforeach
            </div>
        </div>
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
                    $tipoLabelCard = strtoupper($it['tipo_label']);
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
                        <span class="aux-cat-tipo-badge">{{ $tipoLabelCard }}</span>
                        <span class="aux-cat-total-badge">
                            <i class="material-icons" style="font-size:13px;">inventory</i>
                            {{ $it['total'] }}
                        </span>
                    </div>
                    <div class="aux-cat-body">
                        <span class="aux-cat-marca">{{ $it['marca'] }}</span>
                        <span class="aux-cat-modelo">{{ $it['modelo'] }}</span>
                        <div class="aux-cat-meta">
                            @if($it['anio'])
                                <span class="aux-cat-chip"><i class="material-icons" style="font-size:13px;">event</i>{{ $it['anio'] }}</span>
                            @endif
                            @if($it['capacidad'])
                                <span class="aux-cat-chip"><i class="material-icons" style="font-size:13px;">bolt</i>{{ $it['capacidad'] }}</span>
                            @endif
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>

<script>
    // Submit del form (sin boton "Aplicar"). Reusa la URL actual + nuevos params.
    function auxCatSubmit() {
        document.getElementById('auxCatFilters').submit();
    }

    // Debounce sobre el input de busqueda libre — submit automatico tras 350ms
    // de inactividad. Para search vacio, dispara inmediato (limpiar resultados).
    var _auxCatDebTimer = null;
    function auxCatDebounce() {
        clearTimeout(_auxCatDebTimer);
        var v = document.getElementById('auxCatSearch').value.trim();
        var delay = v.length === 0 ? 0 : 350;
        _auxCatDebTimer = setTimeout(auxCatSubmit, delay);
    }

    // Dropdown helpers (mismo patron que auxMain* del listado principal).
    function auxCatOpenList(prefix) {
        var l = document.getElementById('auxCatList' + (prefix === 'tipo' ? 'Tipo' : 'Marca'));
        if (l) l.style.display = 'block';
    }
    function auxCatCloseList(prefix) {
        var l = document.getElementById('auxCatList' + (prefix === 'tipo' ? 'Tipo' : 'Marca'));
        if (l) l.style.display = 'none';
    }
    function auxCatFilterList(prefix, query) {
        var list = document.getElementById('auxCatList' + (prefix === 'tipo' ? 'Tipo' : 'Marca'));
        if (!list) return;
        list.style.display = 'block';
        var q = (query || '').toUpperCase().trim();
        list.querySelectorAll('.aux-cat-opt').forEach(function (opt) {
            if (opt.classList.contains('placeholder')) return;
            var label = (opt.dataset.label || '').toUpperCase();
            opt.style.display = (!q || label.indexOf(q) !== -1) ? '' : 'none';
        });
    }
    // Al elegir una opcion, fija el value en el hidden y submitea — sin boton.
    function auxCatSelect(prefix, value, label) {
        var capPrefix = prefix === 'tipo' ? 'Tipo' : 'Marca';
        var hidden = document.getElementById('auxCatVal' + capPrefix);
        var txt    = document.getElementById('auxCatTxt' + capPrefix);
        if (hidden) hidden.value = value || '';
        if (txt)    txt.value    = value ? label : '';
        auxCatCloseList(prefix);
        auxCatSubmit();
    }
</script>
@endsection
