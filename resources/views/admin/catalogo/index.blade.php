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
    /* Borde marcado + sombra leve: sobre el panel blanco, el gris claro de antes casi no
       separaba una tarjeta de otra. */
    .cat-card {
        background: white;
        border: 1.5px solid #cbd5e1;
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.08);
        border-radius: 12px;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        position: relative;
    }
    .cat-card:hover {
        border-color: #94a3b8;
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
    .cat-action-btn[hidden] { display: none; }   /* el display:flex de arriba le ganaba a hidden */
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
    /* Marca bajo el nombre del modelo, en una línea: label + valor de las specs. */
    .cat-marca { display: flex; align-items: baseline; gap: 5px; min-width: 0; }
    /* Colores del modelo: una mini-tarjeta por color con SU foto (o su muestra y el ícono
       tachado si no tiene), su nombre y cuántas unidades hay. La activa es la que se ve en
       la foto grande y a la que se aplican "Cambiar foto" y borrar. Van en UNA fila que se
       desliza (con el dedo o con las flechas); las flechas solo se ven si no caben todas
       (.con-flechas, catColoresFlechas) y se apagan en cada extremo. */
    .cat-colores-carrusel { display: flex; align-items: center; gap: 3px; margin: 2px 0 8px; }
    .cat-colores-carrusel:last-child { margin-bottom: 0; }
    .cat-colores {
        flex: 1; min-width: 0; display: flex; gap: 5px;
        overflow-x: auto; scroll-snap-type: x mandatory; scroll-behavior: smooth;
        scrollbar-width: none; padding: 2px; scroll-padding-inline: 2px;   /* el padding deja ver el anillo de la activa */
    }
    .cat-colores::-webkit-scrollbar { display: none; }
    .cat-col-flecha {
        display: none; flex: 0 0 22px; height: 44px; padding: 0; border: 1px solid #e2e8f0;
        border-radius: 6px; background: #fff; color: #334155; cursor: pointer;
        align-items: center; justify-content: center;
    }
    .cat-colores-carrusel.con-flechas .cat-col-flecha { display: flex; }
    .cat-col-flecha:hover:not(:disabled) { border-color: #93c5fd; color: var(--maquinaria-blue, #0067b1); }
    .cat-col-flecha:disabled { opacity: 0.35; cursor: default; }
    .cat-col-flecha .material-icons { font-size: 18px; }
    .cat-color {
        flex: 0 0 62px; scroll-snap-align: start;
        display: flex; flex-direction: column; gap: 3px; min-width: 0;
        padding: 3px; border: 1.5px solid #e2e8f0; border-radius: 8px; background: #fff;
        cursor: pointer; font-family: inherit; transition: border-color 0.15s, box-shadow 0.15s;
    }
    .cat-color:hover:not(:disabled) { border-color: #93c5fd; }
    .cat-color.activo { border-color: var(--maquinaria-blue, #0067b1); box-shadow: 0 0 0 2px rgba(0, 103, 177, 0.15); }
    .cat-color:disabled { cursor: default; }
    .cat-color-foto { position: relative; height: 38px; border-radius: 5px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; }
    /* Cuántas unidades hay de ese color, sobre la esquina de la foto (así el nombre cabe). */
    .cat-color-total { position: absolute; top: 2px; right: 2px; min-width: 14px; padding: 0 3px; border-radius: 999px; background: rgba(15, 23, 42, 0.8); color: #fff; font-size: 8.5px; font-weight: 800; line-height: 13px; text-align: center; }
    .cat-color-foto img { width: 100%; height: 100%; object-fit: contain; }
    .cat-color-sinfoto { color: #cbd5e1; font-size: 18px; }
    .cat-color-nombre {
        display: flex; align-items: center; justify-content: center; gap: 2px; min-width: 0;
        font-size: 8.5px; font-weight: 800; color: #334155; text-transform: uppercase; white-space: nowrap;
    }
    .cat-color-nombre > span:last-child { overflow: hidden; text-overflow: ellipsis; }
    .cat-color-muestra { width: 8px; height: 8px; border-radius: 50%; border: 1px solid rgba(15, 23, 42, 0.25); flex-shrink: 0; }
    /* Modelo con equipos pero sin ficha: solo el BORDE en azul (pendiente); la foto, el ícono
       y el botón para crearla quedan en gris como el resto de la tarjeta. */
    .cat-card.cat-sin-ficha { border-color: #60a5fa; }
    .cat-card.cat-sin-ficha:hover { border-color: #0067b1; }
    .cat-crear-ficha {
        margin-top: 6px; align-self: flex-start;
        display: inline-flex; align-items: center; gap: 5px;
        padding: 5px 10px; border-radius: 8px;
        border: 1px solid #cbd5e1; background: #f8fafc; color: #334155;
        font-size: 11px; font-weight: 800; cursor: pointer; font-family: inherit;
    }
    .cat-crear-ficha:hover:not(:disabled) { background: #f1f5f9; border-color: #94a3b8; }
    .cat-crear-ficha:disabled { opacity: 0.6; cursor: wait; }
    .cat-crear-ficha .material-icons { font-size: 15px; }
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
        /* width:100% como cada .cat-filter de arriba: sin él el renglón se quedaba a lo que
           mide su contenido y salía centrado y más angosto que Tipo y Modelo. */
        #catalogoFilters .cat-fila-marca { display: flex; gap: 10px; width: 100%; }
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
    /* Opción de otro tipo que el elegido (catSyncTipo): fuera aunque coincida con lo escrito. */
    .cat-opt.cat-opt-fuera { display: none !important; }
    /* Marca + botón de filtros avanzados: en escritorio son dos piezas más de la fila
       (display:contents); en el teléfono van juntos en un renglón (ver @media arriba). */
    .cat-fila-marca { display: contents; }
    .cat-adv { position: relative; flex: 0 0 auto; }
    .cat-adv .panel-filtro-avanzado .cat-filter { max-width: none; min-width: 0; }
    .cat-opt.placeholder {
        font-size: 13px;
        color: #475569;
        font-weight: 600;
    }
</style>

@include('admin.partials.page_header', [
    'titulo' => 'Catálogo por Modelo',
    'align'  => 'left',
    'margin' => '0 0 10px 0',
])

<div class="page-layout-grid">
<div class="admin-card" style="margin: 0; min-height: 80vh; min-width: 0; width: 100%; padding: 14px;">

    {{-- Filtros — autocomplete con onChange-submit, sin boton Aplicar --}}
    @php
        // Filtros agrupados VEHÍCULOS/AUXILIARES (valores tipo_eq:{id}/tipo_aux:{TIPO} y
        // modelo_eq:{m}/modelo_aux:{m}); la Marca y el Año aplican a ambas clases.
        $reqTipo   = (string) request('tipo', '');
        $reqModelo = (string) request('modelo', '');
        $reqMarca  = mb_strtoupper(trim((string) request('marca', '')));
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
            {{-- Cada opción lleva los tipos en que aparece (data-tipos): con un Tipo elegido,
                 catSyncTipo esconde las de otros tipos. Igual en Marca y Año. --}}
            <div id="catListModelo" class="cat-list">
                @foreach(['VEHÍCULOS' => ['modelo_eq:', $opcionesFiltro['modelosVehiculo']], 'AUXILIARES' => ['modelo_aux:', $opcionesFiltro['modelosAux']]] as $grupo => [$prefijo, $modelos])
                    <div class="cat-grupo">
                        <div style="{{ $catGrpHdr }}">{{ $grupo }}</div>
                        @foreach($modelos as $mod => $tipos)
                            <div class="cat-opt" data-value="{{ $prefijo . $mod }}" data-label="{{ $mod }}" data-tipos="{{ implode(' ', $tipos) }}"
                                 onmousedown="event.preventDefault(); catElegir('modelo', this);">{{ $mod }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Marca (vehículos y auxiliares) --}}
        <div class="cat-fila-marca">
            <div class="cat-filter {{ $reqMarca ? 'active' : '' }}">
                <input type="hidden" id="catValMarca" name="marca" value="{{ $reqMarca }}" data-filter-value>
                <div class="cat-filter-box">
                    <div style="padding:0 12px; display:flex; align-items:center; color:#64748b;">
                        <i class="material-icons" style="font-size:18px;">search</i>
                    </div>
                    <input type="text" id="catTxtMarca" name="filter_search_dropdown_ma" placeholder="{{ $reqMarca ?: 'Filtrar Marca...' }}"
                           autocomplete="off"
                           oninput="catFilterList('marca', this.value)"
                           onfocus="catOpenList('marca')"
                           onclick="catOpenList('marca')"
                           onblur="setTimeout(()=>catCloseList('marca'),200)">
                    <i class="material-icons filter-clear"
                       style="display: {{ $reqMarca ? 'flex' : 'none' }};"
                       onmousedown="event.preventDefault(); catSelect('marca','','');">close</i>
                </div>
                <div id="catListMarca" class="cat-list">
                    @foreach($opcionesFiltro['marcas'] as $marca => $tipos)
                        <div class="cat-opt" data-value="{{ $marca }}" data-label="{{ $marca }}" data-tipos="{{ implode(' ', $tipos) }}"
                             onmousedown="event.preventDefault(); catElegir('marca', this);">{{ $marca }}</div>
                    @endforeach
                </div>
            </div>

            {{-- Filtros avanzados: el mismo botón de Almacén (.btn-filtro-avanzado), en rojo
                 cuando hay un filtro puesto dentro. Por ahora lleva el Año. --}}
            <div class="cat-adv">
                <button type="button" id="catAdvBtn" class="btn-primary-maquinaria btn-filtro-avanzado {{ $anioLabel ? 'activo' : '' }}"
                        title="Filtros avanzados" onclick="catToggleAvanzado()">
                    <i class="material-icons">filter_list</i>
                </button>
                <div id="catAdvPanel" class="panel-filtro-avanzado" style="display:none;">
                    <h4 class="panel-filtro-avanzado-titulo">
                        Filtros Avanzados
                        <span class="panel-filtro-avanzado-limpiar" onclick="catSelect('anio','','')">Limpiar Todo</span>
                    </h4>
                    <span class="panel-filtro-avanzado-label">Año</span>
                    <div class="cat-filter {{ $anioLabel ? 'active' : '' }}">
                        <input type="hidden" id="catValAnio" name="anio" value="{{ $anioLabel }}" data-filter-value>
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
                               style="display: {{ $anioLabel ? 'flex' : 'none' }};"
                               onmousedown="event.preventDefault(); catSelect('anio','','');">close</i>
                        </div>
                        <div id="catListAnio" class="cat-list">
                            @foreach($opcionesFiltro['anios'] as $a => $tipos)
                                <div class="cat-opt" data-value="{{ $a }}" data-label="{{ $a }}" data-tipos="{{ implode(' ', $tipos) }}"
                                     onmousedown="event.preventDefault(); catElegir('anio', this);">{{ $a }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Boton Nuevo: visible siempre, valida permiso al click --}}
        <a href="{{ route('catalogo.create') }}" class="btn-primary-maquinaria"
           style="height:45px; display:inline-flex; align-items:center; padding:0 28px; text-decoration:none; gap:8px; flex:0 0 auto; min-width:180px; box-sizing:border-box; justify-content:center;"
           @cannot('equipos.create')
               onclick="event.preventDefault(); window.toast('No tienes permiso para registrar nuevos modelos.', 'error');"
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

@include('partials.recorte_foto')

@php
    // Lo que el JavaScript del modulo necesita de ESTA apertura. El codigo esta en
    // public/js/maquinaria/catalogo_vista.js, que el navegador cachea.
    $cat_cfg = [
        'rutaEquiposAuxiliaresCatalogoUploadPhoto' => route("equipos-auxiliares.catalogo.uploadPhoto"),
        'rutaCatalogoAsegurarFicha' => route("catalogo.asegurarFicha"),
        'urlCatalogo' => url("admin/catalogo"),
        'rutaEquiposAuxiliaresCatalogoDeletePhoto' => route("equipos-auxiliares.catalogo.deletePhoto"),
    ];
@endphp
<script>
    window.CAT_CFG = @json($cat_cfg);
</script>
{{-- catalogo_index.js ANTES de catalogo_vista.js: este llama a loadCatalogo. Solo lo usa esta pantalla. --}}
<script src="{{ asset('js/maquinaria/catalogo_index.js') }}?v={{ @filemtime(public_path('js/maquinaria/catalogo_index.js')) }}"></script>
<script src="{{ asset('js/maquinaria/catalogo_vista.js') }}?v={{ @filemtime(public_path('js/maquinaria/catalogo_vista.js')) }}"></script>
<script>
    // El archivo de arriba se carga UNA vez en toda la sesion; esta llamada es la que
    // monta la pantalla, y corre en cada apertura del modulo (tambien al volver por la
    // navegacion interna, que es cuando el <script src> ya no se re-ejecuta).
    window.catalogoArrancar(window.CAT_CFG);
</script>
@endsection
