@extends('layouts.estructura_base')

@section('title', 'Catálogo de Modelos')

@section('content')
<link rel="stylesheet" href="{{ asset('css/vistas/admin_catalogo_index.css') }}?v={{ @filemtime(public_path('css/vistas/admin_catalogo_index.css')) }}">

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
