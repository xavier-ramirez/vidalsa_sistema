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
    /* Foto: aspect-ratio 4/3 + altura minima para que la card no quede
       demasiado comprimida verticalmente (antes max-height:130px hacia que
       las cards se vieran apretadas). Subimos a min/max razonables. */
    .cat-photo {
        width: 100%;
        aspect-ratio: 4 / 3;
        min-height: 160px;
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
    .cat-action-btn.cat-del-photo { color: #ef4444; bottom: 6px; left: 6px; z-index: 4; }
    .cat-action-btn.cat-del-photo:hover { background: #ef4444; color: #fff; }
    /* Overlay "Cambiar foto": cubre la foto y aparece al hover. pointer-events:none
       para que el click llegue al .cat-photo (que tiene el onclick de subida). Los
       botones de acción van por encima (z-index:3). */
    .cat-photo-overlay {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        color: #fff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 5px;
        opacity: 0;
        transition: opacity 0.18s ease;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        pointer-events: none;
        z-index: 1;
    }
    .cat-photo-overlay .material-icons { font-size: 26px; }
    .cat-photo:hover .cat-photo-overlay { opacity: 1; }
    .cat-body {
        padding: 8px 12px 10px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .cat-modelo {
        font-size: 12px;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        text-transform: uppercase;
        word-break: break-word;
    }
    /* Badge(s) de Tipo de Equipo: flotan sobre la foto en la esquina superior
       izquierda, simétricos al cat-anio-badge (superior derecha). Misma forma
       (pill redondeada) y tamaño, gris oscuro semitransparente (igual que el
       catálogo de equipos auxiliares) para que contraste sobre cualquier foto.
       Si hay varios tipos se apilan vertical. */
    .cat-tipo-badges {
        position: absolute;
        top: 6px;
        left: 6px;
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
        max-width: calc(100% - 60px); /* deja espacio al cat-anio-badge en la derecha */
        z-index: 2;
    }
    .cat-tipo-badge {
        background: rgba(15,23,42,0.85);
        color: white;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        padding: 2px 8px;
        border-radius: 999px;
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
        box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    }
    /* Specs en rejilla de 2 columnas: cada celda apila label (pequeño, muteado)
       sobre valor (bold). Con esto las ~8 specs ocupan ~4 filas en vez de 8 y la
       tarjeta no se dispara de alto. Solo se renderizan los campos con valor. */
    .cat-specs {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px 12px;
        margin-top: 4px;
        border-top: 1px dashed #e2e8f0;
        padding-top: 8px;
    }
    .cat-spec-row {
        display: flex;
        flex-direction: column;
        gap: 0;
        min-width: 0; /* habilita el truncado del valor dentro de la celda del grid */
    }
    .cat-spec-label {
        color: #94a3b8;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        font-size: 9px;
        line-height: 1.3;
    }
    .cat-spec-value {
        color: #1e293b;
        font-weight: 700;
        font-size: 12px;
        line-height: 1.3;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }
    /* Mobile: filtros y botón ocupan el ancho completo en columna. */
    @media (max-width: 768px) {
        #catalogoFilters {
            flex-direction: column;
            align-items: stretch;
            width: 100%;
            box-sizing: border-box;
        }
        #catalogoFilters .cat-filter {
            max-width: none !important;
            min-width: 0 !important;
            width: 100% !important;
            flex: 1 1 100% !important;
            box-sizing: border-box !important;
        }
        #catalogoFilters > a.btn-primary-maquinaria {
            max-width: none !important;
            width: 100% !important;
            box-sizing: border-box !important;
            justify-content: center;
            order: 99;
            height: 45px !important;
            padding: 0 15px !important;
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
        // Filtros agrupados VEHÍCULOS/AUXILIARES (valores tipo_eq:{id}/tipo_aux:{TIPO} y
        // modelo_eq:{m}/modelo_aux:{m}); el Año aplica a ambas clases.
        $reqTipo   = (string) request('tipo', '');
        $reqModelo = (string) request('modelo', '');
        $reqAnio   = request('anio');
        $anioLabel = ($reqAnio && $reqAnio !== 'all') ? $reqAnio : '';

        $tipoLabel = '';
        if (str_starts_with($reqTipo, 'tipo_eq:')) {
            $f = ($tiposVehiculo ?? collect())->firstWhere('id', (int) substr($reqTipo, 8));
            $tipoLabel = $f ? $f->nombre : '';
        } elseif (str_starts_with($reqTipo, 'tipo_aux:')) {
            $k = substr($reqTipo, 9);
            $tipoLabel = $tiposAux[$k] ?? $k;
        }
        $modeloLabel = '';
        if (str_starts_with($reqModelo, 'modelo_eq:'))      $modeloLabel = substr($reqModelo, 10);
        elseif (str_starts_with($reqModelo, 'modelo_aux:')) $modeloLabel = substr($reqModelo, 11);

        // Header de grupo para los filtros Modelo/Año (texto gris + borde fino).
        $catGrpHdr = 'padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;';
    @endphp
    <form id="catalogoFilters" method="GET" action="{{ route('catalogo.index') }}"
          onsubmit="event.preventDefault(); catSubmit();">

        {{-- Tipo (agrupado VEHÍCULOS / AUXILIARES) — mismo diseño que /admin/movilizaciones --}}
        <div class="cat-filter {{ $reqTipo ? 'active' : '' }}" style="flex: 1.4 1 260px; max-width: 360px;">
            <div class="custom-dropdown" id="catTipoDropdown" data-filter-type="cat_tipo" data-default-label="Filtrar Tipo...">
                <input type="hidden" id="catValTipo" name="tipo" value="{{ $reqTipo }}" data-filter-value>
                <div class="dropdown-trigger {{ $reqTipo ? 'filter-active' : '' }}" style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:12px; height:45px;">
                    <div style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text, #64748b);">
                        <i class="material-icons" style="font-size:18px;">search</i>
                    </div>
                    <input type="text" name="filter_search_dropdown" data-filter-search
                           placeholder="{{ $tipoLabel ?: 'Filtrar Tipo...' }}"
                           style="flex:1; border:none; background:transparent; padding:10px 5px; font-size:14px; outline:none; min-width:0;"
                           oninput="window.filterDropdownOptions(this)"
                           autocomplete="off">
                    <i class="material-icons" data-clear-btn
                       style="padding:0 5px; color:var(--maquinaria-gray-text, #64748b); font-size:18px; display:{{ $reqTipo ? 'block' : 'none' }}; cursor:pointer;"
                       onclick="event.stopPropagation(); clearDropdownFilter('catTipoDropdown'); catSelect('tipo','','');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:300px; overflow-y:auto;">
                        <div class="dropdown-item {{ !$reqTipo ? 'selected' : '' }}" data-value="" onclick="selectOption('catTipoDropdown','','TODOS LOS TIPOS'); catSelect('tipo','','');">
                            TODOS LOS TIPOS
                        </div>
                        <div style="{{ $catGrpHdr }}">VEHÍCULOS</div>
                        @foreach(($tiposVehiculo ?? []) as $t)
                            <div class="dropdown-item {{ $reqTipo === 'tipo_eq:'.$t->id ? 'selected' : '' }}" data-value="tipo_eq:{{ $t->id }}" onclick="selectOption('catTipoDropdown','tipo_eq:{{ $t->id }}','{{ addslashes($t->nombre) }}'); catSelect('tipo','tipo_eq:{{ $t->id }}','{{ addslashes($t->nombre) }}');">
                                {{ $t->nombre }}
                            </div>
                        @endforeach
                        <div style="{{ $catGrpHdr }}">AUXILIARES</div>
                        @foreach(($tiposAux ?? []) as $k => $label)
                            <div class="dropdown-item {{ $reqTipo === 'tipo_aux:'.$k ? 'selected' : '' }}" data-value="tipo_aux:{{ $k }}" onclick="selectOption('catTipoDropdown','tipo_aux:{{ $k }}','{{ addslashes($label) }}'); catSelect('tipo','tipo_aux:{{ $k }}','{{ addslashes($label) }}');">
                                {{ $label }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Modelo (agrupado VEHÍCULOS / AUXILIARES) --}}
        <div class="cat-filter {{ $reqModelo ? 'active' : '' }}">
            <input type="hidden" id="catValModelo" name="modelo" value="{{ $reqModelo }}" data-filter-value>
            <div class="cat-filter-box">
                <div style="padding:0 12px; display:flex; align-items:center; color:#64748b;">
                    <i class="material-icons" style="font-size:18px;">search</i>
                </div>
                <input type="text" id="catTxtModelo" name="filter_search_dropdown_m" placeholder="{{ $modeloLabel ?: 'Filtrar Modelo...' }}"
                       autocomplete="off"
                       oninput="catFilterList('modelo', this.value)"
                       onfocus="catOpenList('modelo')"
                       onclick="catOpenList('modelo')"
                       onblur="setTimeout(()=>catCloseList('modelo'),200)">
                <i class="material-icons filter-clear"
                   style="display: {{ $reqModelo ? 'flex' : 'none' }};"
                   onmousedown="event.preventDefault(); catSelect('modelo','','');">close</i>
            </div>
            <div id="catListModelo" class="cat-list">
                <div style="{{ $catGrpHdr }}">VEHÍCULOS</div>
                @foreach(($modelosVehiculo ?? []) as $mod)
                    <div class="cat-opt" data-label="{{ $mod }}"
                         onmousedown="event.preventDefault(); catSelect('modelo','modelo_eq:{{ addslashes($mod) }}','{{ addslashes($mod) }}');">{{ $mod }}</div>
                @endforeach
                <div style="{{ $catGrpHdr }}">AUXILIARES</div>
                @foreach(($modelosAux ?? []) as $mod)
                    <div class="cat-opt" data-label="{{ $mod }}"
                         onmousedown="event.preventDefault(); catSelect('modelo','modelo_aux:{{ addslashes($mod) }}','{{ addslashes($mod) }}');">{{ $mod }}</div>
                @endforeach
            </div>
        </div>

        {{-- Año --}}
        <div class="cat-filter {{ $reqAnio && $reqAnio !== 'all' ? 'active' : '' }}">
            <input type="hidden" id="catValAnio" name="anio" value="{{ $reqAnio && $reqAnio !== 'all' ? $reqAnio : '' }}" data-filter-value>
            <div class="cat-filter-box">
                <div style="padding:0 12px; display:flex; align-items:center; color:#64748b;">
                    <i class="material-icons" style="font-size:18px;">search</i>
                </div>
                <input type="text" id="catTxtAnio" name="filter_search_dropdown_a" placeholder="{{ $anioLabel ?: 'Filtrar Año...' }}"
                       autocomplete="off"
                       oninput="catFilterList('anio', this.value)"
                       onfocus="catOpenList('anio')"
                       onclick="catOpenList('anio')"
                       onblur="setTimeout(()=>catCloseList('anio'),200)">
                <i class="material-icons filter-clear"
                   style="display: {{ $reqAnio && $reqAnio !== 'all' ? 'flex' : 'none' }};"
                   onmousedown="event.preventDefault(); catSelect('anio','','');">close</i>
            </div>
            <div id="catListAnio" class="cat-list">
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
           style="height:45px; display:inline-flex; align-items:center; padding:0 28px; text-decoration:none; gap:8px; flex:0 0 auto; min-width:180px; justify-content:center;"
           @cannot('equipos.create')
               onclick="event.preventDefault(); if(window.showToast) window.showToast('No tienes permiso para registrar nuevos modelos.', 'error');"
           @endcannot>
            <i class="material-icons" style="font-size:18px;">add_circle</i>
            Nuevo
        </a>
    </form>

    {{-- Grid de tarjetas. catalogoTableBody mantiene el ID para que
         loadCatalogo() (catalogo_index.js) replace innerHTML normalmente. --}}
    <div id="catalogoTableBody" class="cat-grid" style="font-size:14px;"
         data-page="{{ $catalogos->currentPage() }}"
         data-has-more="{{ $catalogos->hasMorePages() ? '1' : '0' }}">
        @include('admin.catalogo.partials.table_rows')
    </div>

    {{-- Scroll infinito: el centinela dispara la carga de la siguiente página
         al entrar en viewport (IntersectionObserver en catalogo_index.js).
         Reemplaza al paginado clásico « Anterior / Siguiente ». --}}
    <div id="catalogoSentinel" style="margin-top:14px; min-height:1px; text-align:center;">
        <div id="catalogoLoadingSpinner" style="display:none; padding:22px; color:#64748b; font-size:15px; font-weight:600;">
            <i class="material-icons" style="font-size:30px; vertical-align:middle; animation:spin-mini .8s linear infinite;">refresh</i>
            Cargando más modelos…
        </div>
        <div id="catalogoEndMsg" style="display:none; padding:16px; color:#94a3b8; font-size:12px;">
            — No hay más modelos —
        </div>
    </div>
</div>

{{-- Sidebar de stats (modelos count, total) --}}
<div class="counter-sidebar" id="statsSidebarContainer" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 15px;">
    @include('admin.catalogo.partials.stats_sidebar')
</div>

</div> {{-- /page-layout-grid --}}

{{-- Modal de recorte de foto (Cropper.js — cargado dinámicamente para SPA) --}}
<style>
    .crop-modal-overlay {
        display: none; position: fixed; inset: 0; z-index: 99999;
        background: rgba(0,0,0,0.7); backdrop-filter: blur(4px);
        align-items: center; justify-content: center;
        padding: 10px;
    }
    .crop-modal-box {
        background: #fff; border-radius: 14px;
        width: 100%; max-width: 600px; max-height: 90vh;
        display: flex; flex-direction: column; overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);
    }
    .crop-modal-header {
        background: #1e293b; padding: 12px 16px; color: #fff;
        display: flex; justify-content: space-between; align-items: center;
        flex-shrink: 0;
    }
    .crop-modal-body {
        flex: 1; overflow: hidden; background: #0f172a;
        min-height: 250px; max-height: 55vh; position: relative;
    }
    .crop-modal-body img { display: block; max-width: 100%; }
    .crop-modal-footer {
        padding: 12px 16px; display: flex; justify-content: center;
        gap: 10px; border-top: 1px solid #e2e8f0; flex-shrink: 0;
    }
    .crop-btn {
        padding: 10px 24px; border-radius: 10px; font-size: 14px;
        font-weight: 700; cursor: pointer; display: flex;
        align-items: center; gap: 6px; transition: opacity 0.2s;
    }
    .crop-btn:active { opacity: 0.8; }
    .crop-btn-cancel {
        border: 1px solid #cbd5e0; background: #fff; color: #475569;
    }
    .crop-btn-confirm {
        border: none; background: #0067b1; color: #fff;
    }
    @media (max-width: 768px) {
        .crop-modal-overlay { padding: 0; align-items: flex-end; }
        .crop-modal-box {
            max-width: 100%; max-height: 100vh; height: 100vh;
            border-radius: 0;
        }
        .crop-modal-body { min-height: 0; max-height: none; flex: 1; }
        .crop-modal-footer { padding: 10px 12px; }
        .crop-btn { flex: 1; justify-content: center; padding: 12px 10px; }
    }
    .cropper-line { background-color: #fff !important; opacity: 1 !important; }
    .cropper-line.line-n, .cropper-line.line-s { height: 3px !important; }
    .cropper-line.line-w, .cropper-line.line-e { width: 3px !important; }
    .cropper-point { background-color: #fff !important; opacity: 1 !important; width: 10px !important; height: 10px !important; }
    .cropper-dashed { border-color: rgba(255,255,255,0.5) !important; }
</style>
<div id="cropModal" class="crop-modal-overlay">
    <div class="crop-modal-box">
        <div class="crop-modal-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons" style="font-size:18px; color:#38bdf8;">crop</i>
                <span style="font-size:14px; font-weight:700;">Recortar Foto</span>
            </div>
            <button type="button" onclick="window._closeCropModal()" style="background:transparent; border:none; color:#fff; cursor:pointer; padding:4px;">
                <i class="material-icons" style="font-size:20px;">close</i>
            </button>
        </div>
        <div class="crop-modal-body"></div>
        <div class="crop-modal-footer">
            <button type="button" onclick="window._closeCropModal()" class="crop-btn crop-btn-cancel">
                <i class="material-icons" style="font-size:16px;">close</i> Cancelar
            </button>
            <button type="button" onclick="window._confirmCrop()" class="crop-btn crop-btn-confirm">
                <i class="material-icons" style="font-size:16px;">check</i> Confirmar
            </button>
        </div>
    </div>
</div>
<script>
(function() {
    if (typeof Cropper !== 'undefined') return;
    if (document.querySelector('script[src*="cropper.min.js"]')) return;
    var s = document.createElement('script');
    s.src = '{{ asset("js/cropper.min.js") }}';
    document.head.appendChild(s);
    if (!document.querySelector('link[href*="cropper.min.css"]')) {
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = '{{ asset("css/cropper.min.css") }}';
        document.head.appendChild(l);
    }
})();
</script>

<script>
    function catSubmit() {
        if (typeof window.loadCatalogo === 'function') {
            window.loadCatalogo();
        } else {
            document.getElementById('catalogoFilters').submit();
        }
    }
    function _catCap(p) {
        if (p === 'modelo') return 'Modelo';
        if (p === 'anio')   return 'Anio';
        if (p === 'tipo')   return 'Tipo';
        return p;
    }
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
    // Placeholder por defecto de cada filtro — se restaura al limpiar / elegir "TODOS".
    var CAT_PH_DEFAULT = { modelo: 'Filtrar Modelo...', tipo: 'Filtrar Tipo...', anio: 'Filtrar Año...' };
    function catSelect(p, value, label) {
        var cap = _catCap(p);
        var hidden = document.getElementById('catVal' + cap);
        var txt    = document.getElementById('catTxt' + cap);
        if (hidden) hidden.value = value || '';
        // Igual que /admin/equipos: la opcion elegida queda como PLACEHOLDER
        // (texto de fondo) y el input se vacia — asi se escribe la siguiente
        // busqueda sin tener que borrar lo anterior.
        if (txt) {
            txt.value = '';
            txt.placeholder = value ? label : (CAT_PH_DEFAULT[p] || 'Filtrar...');
        }
        // El formulario de filtros no se re-renderiza en la recarga AJAX
        // (loadCatalogo solo reemplaza la tabla), asi que togglear aqui la
        // "x" de limpiar y el resaltado azul del filtro activo.
        var wrapper = hidden ? hidden.closest('.cat-filter') : null;
        if (wrapper) {
            wrapper.classList.toggle('active', !!value);
            var clearIcon = wrapper.querySelector('.filter-clear');
            if (clearIcon) clearIcon.style.display = value ? 'flex' : 'none';
        }
        catCloseList(p);
        catSubmit();
    }

    // ── Cropper: modal compartido para recortar la foto antes de subirla ──
    // _cropPending guarda el callback que se ejecuta al confirmar el recorte.
    window._cropPending = null;
    window._cropperInstance = null;

    window._openCropModal = function (file, onConfirm) {
        var modal = document.getElementById('cropModal');
        var body  = document.querySelector('.crop-modal-body');
        if (!modal || !body) return onConfirm(file);

        if (window._cropperInstance) { window._cropperInstance.destroy(); window._cropperInstance = null; }
        window._cropPending = onConfirm;
        body.innerHTML = '';

        modal.style.display = 'flex';

        var reader = new FileReader();
        reader.onload = function (e) {
            var img = document.createElement('img');
            img.style.cssText = 'display:block; max-width:100%;';
            body.appendChild(img);

            img.onload = function () {
                var tries = 0;
                var tryInit = function () {
                    if (typeof Cropper !== 'undefined') {
                        window._cropperInstance = new Cropper(img, {
                            aspectRatio: NaN,
                            viewMode: 1,
                            autoCropArea: 0.9,
                            responsive: true,
                            background: false,
                            dragMode: 'move',
                            toggleDragModeOnDblclick: false,
                        });
                    } else if (tries < 30) {
                        tries++;
                        setTimeout(tryInit, 100);
                    }
                };
                setTimeout(tryInit, 150);
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    };

    window._confirmCrop = function () {
        if (!window._cropperInstance || !window._cropPending) return;
        var canvas = window._cropperInstance.getCroppedCanvas({ maxWidth: 1200, maxHeight: 1200, imageSmoothingQuality: 'high' });
        canvas.toBlob(function (blob) {
            if (!blob) { if (window.showToast) window.showToast('Error al recortar la imagen.', 'error'); return; }
            var croppedFile = new File([blob], 'foto_recortada.webp', { type: 'image/webp' });
            window._cropPending(croppedFile);
            window._closeCropModal();
        }, 'image/webp', 0.88);
    };

    window._closeCropModal = function () {
        var modal = document.getElementById('cropModal');
        if (modal) modal.style.display = 'none';
        if (window._cropperInstance) { window._cropperInstance.destroy(); window._cropperInstance = null; }
        window._cropPending = null;
        var body = document.querySelector('.crop-modal-body');
        if (body) body.innerHTML = '';
    };

    // ── Helpers de subida (post-recorte) ──
    function _pickFileAndCrop(onCropped) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/jpg,image/png,image/webp';
        input.style.display = 'none';
        input.addEventListener('change', function () {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            if (file.size > 10 * 1024 * 1024) {
                if (window.showToast) window.showToast('La foto supera los 10 MB.', 'error');
                return;
            }
            window._openCropModal(file, onCropped);
        });
        document.body.appendChild(input);
        input.click();
        setTimeout(function () { if (input.parentNode) document.body.removeChild(input); }, 1000);
    }

    function _uploadBlob(url, fd, onSuccess, onError) {
        var csrf = window.getCsrf();   // helper central (dom_helpers.js)
        if (typeof window.showPreloader === 'function') window.showPreloader();
        window.apiFetch(url, {
            method: 'POST',
            body: fd})
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) { onSuccess(res.body); }
            else { onError((res.body && res.body.message) || 'No se pudo subir la foto.'); }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            onError('Error de red al subir la foto.');
        });
    }

    // ── Subida AUXILIAR ──
    window.auxCatUploadPhoto = function (photoEl) {
        var tipo = photoEl.dataset.tipo || '', marca = photoEl.dataset.marca || '',
            modelo = photoEl.dataset.modelo || '', anio = photoEl.dataset.anio || '';
        if (!tipo || !marca || !modelo) {
            if (window.showToast) window.showToast('Este modelo no tiene marca registrada; no se puede asociar la foto.', 'error');
            return;
        }
        _pickFileAndCrop(function (croppedFile) {
            var fd = new FormData();
            fd.append('foto', croppedFile); fd.append('tipo', tipo); fd.append('marca', marca); fd.append('modelo', modelo);
            if (anio) fd.append('anio', anio);
            _uploadBlob('{{ route("equipos-auxiliares.catalogo.uploadPhoto") }}', fd,
                function (body) {
                    if (window.showToast) window.showToast(body.message || 'Foto actualizada.', 'success');
                    if (body.foto) {
                        var img = photoEl.querySelector('img');
                        if (img) { img.src = body.foto; }
                        else {
                            var ph = photoEl.querySelector('.placeholder'); if (ph) ph.remove();
                            var n = document.createElement('img'); n.src = body.foto; n.alt = (marca + ' ' + modelo).trim();
                            n.onerror = function () { this.outerHTML = '<i class="material-icons placeholder">image_not_supported</i>'; };
                            photoEl.insertBefore(n, photoEl.firstChild);
                        }
                    }
                },
                function (msg) { if (window.showToast) window.showToast(msg, 'error'); }
            );
        });
    };

    // ── Borrar foto VEHÍCULO (solo super.admin) ──
    window.catDeletePhoto = function (id, photoEl) {
        if (!confirm('¿Eliminar la foto de este modelo?')) return;
        var csrf = window.getCsrf();   // helper central (dom_helpers.js)
        if (typeof window.showPreloader === 'function') window.showPreloader();
        window.apiFetch('{{ url("admin/catalogo") }}/' + id + '/photo', {
            method: 'DELETE'})
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) {
                if (window.showToast) window.showToast(res.body.message || 'Foto eliminada.', 'success');
                if (photoEl) {
                    var img = photoEl.querySelector('img');
                    if (img) img.outerHTML = '<i class="material-icons placeholder">precision_manufacturing</i>';
                    var delBtn = photoEl.querySelector('.cat-del-photo');
                    if (delBtn) delBtn.remove();
                }
            } else {
                if (window.showToast) window.showToast((res.body && res.body.message) || 'No se pudo eliminar la foto.', 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            if (window.showToast) window.showToast('Error de red al eliminar la foto.', 'error');
        });
    };

    // ── Borrar foto AUXILIAR (solo super.admin) ──
    window.auxCatDeletePhoto = function (photoEl) {
        if (!confirm('¿Eliminar la foto de este modelo auxiliar?')) return;
        var tipo = photoEl.dataset.tipo || '', marca = photoEl.dataset.marca || '',
            modelo = photoEl.dataset.modelo || '', anio = photoEl.dataset.anio || '';
        if (!tipo || !marca || !modelo) {
            if (window.showToast) window.showToast('No se pudo identificar el modelo.', 'error');
            return;
        }
        var csrf = window.getCsrf();   // helper central (dom_helpers.js)
        var fd = new FormData();
        fd.append('_method', 'DELETE');
        fd.append('tipo', tipo); fd.append('marca', marca); fd.append('modelo', modelo);
        if (anio) fd.append('anio', anio);
        if (typeof window.showPreloader === 'function') window.showPreloader();
        window.apiFetch('{{ route("equipos-auxiliares.catalogo.deletePhoto") }}', {
            method: 'POST',
            body: fd})
        .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) {
                if (window.showToast) window.showToast(res.body.message || 'Foto eliminada.', 'success');
                if (photoEl) {
                    var img = photoEl.querySelector('img');
                    if (img) img.outerHTML = '<i class="material-icons placeholder">construction</i>';
                    var delBtn = photoEl.querySelector('.cat-del-photo');
                    if (delBtn) delBtn.remove();
                }
            } else {
                if (window.showToast) window.showToast((res.body && res.body.message) || 'No se pudo eliminar la foto.', 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            if (window.showToast) window.showToast('Error de red al eliminar la foto.', 'error');
        });
    };

    // ── Subida VEHÍCULO ──
    window.catUploadPhoto = function (id, photoEl) {
        _pickFileAndCrop(function (croppedFile) {
            var fd = new FormData();
            fd.append('foto', croppedFile);
            _uploadBlob('{{ url("admin/catalogo") }}/' + id + '/photo', fd,
                function (body) {
                    if (window.showToast) window.showToast(body.message || 'Foto actualizada correctamente.', 'success');
                    if (body.foto && photoEl) {
                        var img = photoEl.querySelector('img');
                        if (img) { img.src = body.foto; }
                        else {
                            var ph = photoEl.querySelector('.placeholder'); if (ph) ph.remove();
                            var n = document.createElement('img'); n.src = body.foto; n.alt = '';
                            n.style.cssText = 'opacity:1; width:100%; height:100%; object-fit:contain; background:#f8fafc;';
                            n.onerror = function () { this.outerHTML = '<i class="material-icons placeholder">image_not_supported</i>'; };
                            photoEl.insertBefore(n, photoEl.firstChild);
                        }
                    }
                },
                function (msg) { if (window.showToast) window.showToast(msg, 'error'); }
            );
        });
    };
</script>
@endsection
