@extends('layouts.estructura_base')

@section('title', 'Catálogo de Modelos')

@section('content')
<style>
    /* ─── Catalogo de Modelos: card grid compacto ─── */
    .cat-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
        gap: 10px;
        margin-top: 6px;
    }
    .cat-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        position: relative;
    }
    .cat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 18px -6px rgba(15, 23, 42, 0.12);
    }
    /* Foto: aspect-ratio 4/3 (mas baja que 16/11), 130px maximo de alto */
    .cat-photo {
        width: 100%;
        aspect-ratio: 4 / 3;
        max-height: 130px;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        position: relative;
    }
    .cat-photo img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        background: #f8fafc;
    }
    .cat-photo .placeholder {
        color: #cbd5e0;
        font-size: 44px;
    }
    .cat-anio-badge {
        position: absolute;
        top: 6px;
        right: 6px;
        background: var(--maquinaria-blue, #0067b1);
        color: white;
        font-size: 10px;
        font-weight: 700;
        padding: 2px 7px;
        border-radius: 999px;
        display: inline-flex;
        align-items: center;
        gap: 3px;
    }
    .cat-action-btn {
        position: absolute;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid #e2e8f0;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(15, 23, 42, 0.18);
        transition: background 0.15s, transform 0.15s;
        text-decoration: none;
        z-index: 3;
    }
    .cat-action-btn:hover { transform: scale(1.05); }
    .cat-action-btn .material-icons { font-size: 14px; }
    .cat-action-btn.edit  { color: #0067b1; bottom: 6px; right: 38px; }
    .cat-action-btn.edit:hover  { background: #0067b1; color: #fff; }
    .cat-action-btn.del   { color: #ef4444; bottom: 6px; right: 6px; }
    .cat-action-btn.del:hover   { background: #ef4444; color: #fff; }
    .cat-body {
        padding: 9px 11px 10px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .cat-modelo {
        font-size: 13.5px;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        text-transform: uppercase;
        word-break: break-word;
    }
    /* Tabla compacta de specs: 1 fila por campo. Label izquierda muteado,
       valor derecha en bold. Solo se renderizan los campos con valor. */
    .cat-specs {
        display: flex;
        flex-direction: column;
        gap: 2px;
        margin-top: 2px;
        border-top: 1px dashed #e2e8f0;
        padding-top: 6px;
    }
    .cat-spec-row {
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 8px;
        font-size: 10.5px;
        line-height: 1.3;
        padding: 1px 0;
    }
    .cat-spec-label {
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        font-size: 9.5px;
        flex-shrink: 0;
    }
    .cat-spec-value {
        color: #1e293b;
        font-weight: 700;
        text-align: right;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 140px;
    }
    /* Mobile: el boton "Nuevo" del catalog se va al final de la fila de
       filtros y ocupa el ancho completo. Asi en pantallas chicas no queda
       comprimido al lado de los filtros. */
    @media (max-width: 600px) {
        #catalogoFilters { flex-direction: column; align-items: stretch; }
        #catalogoFilters .cat-filter { max-width: none; flex: 1 1 100%; }
        #catalogoFilters > a.btn-primary-maquinaria {
            width: 100%;
            justify-content: center;
            order: 99;
        }
    }

    .cat-empty {
        background: white;
        border: 1px dashed #cbd5e0;
        border-radius: 14px;
        padding: 60px 20px;
        text-align: center;
        color: #94a3b8;
        margin-top: 20px;
        grid-column: 1 / -1;
    }
    .cat-empty .material-icons {
        font-size: 56px;
        color: #cbd5e0;
        display: block;
        margin: 0 auto 10px;
    }

    /* Filtros: replica del estilo /admin/equipos-auxiliares/catalogo
       (autocomplete con dropdown, sin boton "Aplicar") */
    #catalogoFilters {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        align-items: center;
        margin-top: 4px;
    }
    .cat-filter {
        flex: 1 1 220px;
        min-width: 180px;
        max-width: 280px;
        position: relative;
    }
    .cat-filter-box {
        display: flex;
        align-items: center;
        background: #fbfcfd;
        border: 1px solid #cbd5e0;
        border-radius: 12px;
        height: 45px;
        overflow: hidden;
    }
    .cat-filter.active .cat-filter-box {
        background: #e1effa;
        border-color: var(--maquinaria-blue, #0067b1);
    }
    .cat-filter input[type="text"] {
        flex: 1;
        border: none;
        background: transparent;
        outline: none;
        font-size: 14px;
        color: #1e293b;
        padding: 10px 5px;
        min-width: 0;
    }
    .cat-filter .filter-clear {
        padding: 0 8px;
        color: #64748b;
        font-size: 18px;
        cursor: pointer;
    }
    .cat-list {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        max-height: 260px;
        overflow-y: auto;
        margin-top: 4px;
        padding: 5px;
        z-index: 9999;
    }
    .cat-opt {
        padding: 8px 12px;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        cursor: pointer;
        border-radius: 6px;
    }
    .cat-opt:hover { background: #f0f4f8; }
    .cat-opt.placeholder {
        font-size: 13px;
        color: #475569;
        font-weight: 600;
    }
</style>

<section class="page-title-card" style="text-align: left; margin: 0 0 10px 0;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Catálogo por Modelo</span>
    </h1>
</section>

<div class="page-layout-grid">
<div class="admin-card" style="margin: 0; min-height: 80vh; min-width: 0; width: 100%; padding: 14px;">

    {{-- Filtros — autocomplete con onChange-submit, sin boton Aplicar --}}
    @php
        $reqModelo = request('modelo');
        $reqAnio   = request('anio');
        $modeloLabel = ($reqModelo && $reqModelo !== 'all') ? $reqModelo : '';
        $anioLabel   = ($reqAnio   && $reqAnio   !== 'all') ? $reqAnio   : '';
    @endphp
    <form id="catalogoFilters" method="GET" action="{{ route('catalogo.index') }}"
          onsubmit="event.preventDefault(); catSubmit();">

        {{-- Modelo --}}
        <div class="cat-filter {{ $reqModelo && $reqModelo !== 'all' ? 'active' : '' }}">
            <input type="hidden" id="catValModelo" name="modelo" value="{{ $reqModelo && $reqModelo !== 'all' ? $reqModelo : '' }}" data-filter-value>
            <div class="cat-filter-box">
                <div style="padding:0 12px; display:flex; align-items:center; color:#64748b;">
                    <i class="material-icons" style="font-size:18px;">search</i>
                </div>
                <input type="text" id="catTxtModelo" name="filter_search_dropdown_m" placeholder="{{ $modeloLabel ?: 'Filtrar Modelo...' }}"
                       value="{{ $modeloLabel }}" autocomplete="off"
                       oninput="catFilterList('modelo', this.value)"
                       onfocus="catOpenList('modelo')"
                       onclick="catOpenList('modelo')"
                       onblur="setTimeout(()=>catCloseList('modelo'),200)">
                @if($reqModelo && $reqModelo !== 'all')
                    <i class="material-icons filter-clear" onmousedown="event.preventDefault(); catSelect('modelo','','');">close</i>
                @endif
            </div>
            <div id="catListModelo" class="cat-list">
                <div class="cat-opt placeholder" data-label="TODOS LOS MODELOS"
                     onmousedown="event.preventDefault(); catSelect('modelo','','TODOS LOS MODELOS');">TODOS LOS MODELOS</div>
                @foreach($availableModelos as $mod)
                    <div class="cat-opt" data-label="{{ $mod }}"
                         onmousedown="event.preventDefault(); catSelect('modelo','{{ $mod }}','{{ addslashes($mod) }}');">
                        {{ $mod }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Año --}}
        <div class="cat-filter {{ $reqAnio && $reqAnio !== 'all' ? 'active' : '' }}">
            <input type="hidden" id="catValAnio" name="anio" value="{{ $reqAnio && $reqAnio !== 'all' ? $reqAnio : '' }}" data-filter-value>
            <div class="cat-filter-box">
                <div style="padding:0 12px; display:flex; align-items:center; color:#64748b;">
                    <i class="material-icons" style="font-size:18px;">calendar_today</i>
                </div>
                <input type="text" id="catTxtAnio" name="filter_search_dropdown_a" placeholder="{{ $anioLabel ?: 'Filtrar Año...' }}"
                       value="{{ $anioLabel }}" autocomplete="off"
                       oninput="catFilterList('anio', this.value)"
                       onfocus="catOpenList('anio')"
                       onclick="catOpenList('anio')"
                       onblur="setTimeout(()=>catCloseList('anio'),200)">
                @if($reqAnio && $reqAnio !== 'all')
                    <i class="material-icons filter-clear" onmousedown="event.preventDefault(); catSelect('anio','','');">close</i>
                @endif
            </div>
            <div id="catListAnio" class="cat-list">
                <div class="cat-opt placeholder" data-label="TODOS LOS AÑOS"
                     onmousedown="event.preventDefault(); catSelect('anio','','TODOS LOS AÑOS');">TODOS LOS AÑOS</div>
                @foreach($availableAnios as $a)
                    <div class="cat-opt" data-label="{{ $a }}"
                         onmousedown="event.preventDefault(); catSelect('anio','{{ $a }}','{{ $a }}');">
                        {{ $a }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Boton Nuevo: visible siempre, valida permiso al click --}}
        <a href="{{ route('catalogo.create') }}" class="btn-primary-maquinaria"
           style="height:45px; display:inline-flex; align-items:center; padding:0 15px; text-decoration:none; gap:8px; flex:0 0 auto;"
           @cannot('equipos.create')
               onclick="event.preventDefault(); if(window.showToast) window.showToast('No tienes permiso para registrar nuevos modelos.', 'error');"
           @endcannot>
            <i class="material-icons" style="font-size:18px;">add_circle</i>
            Nuevo
        </a>
    </form>

    {{-- Grid de tarjetas. catalogoTableBody mantiene el ID para que
         loadCatalogo() (catalogo_index.js) replace innerHTML normalmente. --}}
    <div id="catalogoTableBody" class="cat-grid" style="font-size:14px;">
        @include('admin.catalogo.partials.table_rows')
    </div>

    <div style="margin-top: 18px;" id="catalogoPagination">
        {{ $catalogos->links('vendor.pagination.custom-sliding') }}
    </div>
</div>

{{-- Sidebar de stats (modelos count, total) --}}
<div class="counter-sidebar" id="statsSidebarContainer" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 15px;">
    @include('admin.catalogo.partials.stats_sidebar')
</div>

</div> {{-- /page-layout-grid --}}

<script>
    function catSubmit() {
        if (typeof window.loadCatalogo === 'function') {
            window.loadCatalogo();
        } else {
            document.getElementById('catalogoFilters').submit();
        }
    }
    function _catCap(p) { return p === 'modelo' ? 'Modelo' : 'Anio'; }
    function catOpenList(p) {
        var l = document.getElementById('catList' + _catCap(p));
        if (!l) return;
        l.style.display = 'block';
        l.querySelectorAll('.cat-opt').forEach(function (o) { o.style.display = ''; });
    }
    function catCloseList(p) {
        var l = document.getElementById('catList' + _catCap(p));
        if (l) l.style.display = 'none';
    }
    function catFilterList(p, q) {
        var list = document.getElementById('catList' + _catCap(p));
        if (!list) return;
        list.style.display = 'block';
        var qu = (q || '').toUpperCase().trim();
        list.querySelectorAll('.cat-opt').forEach(function (opt) {
            if (opt.classList.contains('placeholder')) return;
            var lbl = (opt.dataset.label || '').toUpperCase();
            opt.style.display = (!qu || lbl.indexOf(qu) !== -1) ? '' : 'none';
        });
    }
    function catSelect(p, value, label) {
        var cap = _catCap(p);
        var hidden = document.getElementById('catVal' + cap);
        var txt    = document.getElementById('catTxt' + cap);
        if (hidden) hidden.value = value || '';
        if (txt)    txt.value    = value ? label : '';
        catCloseList(p);
        catSubmit();
    }
</script>
@endsection
