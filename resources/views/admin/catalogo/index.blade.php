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
        padding: 11px 12px 12px;
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .cat-modelo {
        font-size: 14px;
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
    /* Mobile: el boton "Nuevo" del catalogo se va al final de la fila de
       filtros y ocupa el ancho completo. Breakpoint subido a 768px para que
       en telefonos medianos (~601-768px) tampoco se vea "muy finito".
       height:48px + font 14px para mejor area de tap. */
    @media (max-width: 768px) {
        #catalogoFilters {
            flex-direction: column;
            align-items: stretch;
            width: 100%;
            box-sizing: border-box;
        }
        #catalogoFilters .cat-filter,
        #catalogoFilters > a.btn-primary-maquinaria {
            max-width: none !important;
            min-width: 0 !important;
            width: 100% !important;
            flex: 1 1 100% !important;
            box-sizing: border-box !important;
        }
        #catalogoFilters > a.btn-primary-maquinaria {
            justify-content: center;
            order: 99;
            height: 48px !important;
            padding: 0 14px !important;
            font-size: 14px !important;
            font-weight: 700 !important;
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
        $reqTipo   = request('id_tipo');
        $modeloLabel = ($reqModelo && $reqModelo !== 'all') ? $reqModelo : '';
        $anioLabel   = ($reqAnio   && $reqAnio   !== 'all') ? $reqAnio   : '';
        // Buscar el nombre del tipo seleccionado para mostrarlo en el placeholder
        $tipoLabel = '';
        if ($reqTipo && $reqTipo !== 'all') {
            $found = ($availableTipos ?? collect())->firstWhere('id', (int) $reqTipo);
            $tipoLabel = $found ? $found->nombre : '';
        }
    @endphp
    <form id="catalogoFilters" method="GET" action="{{ route('catalogo.index') }}"
          onsubmit="event.preventDefault(); catSubmit();">

        {{-- Tipo de Equipo --}}
        <div class="cat-filter {{ $reqTipo && $reqTipo !== 'all' ? 'active' : '' }}">
            <input type="hidden" id="catValTipo" name="id_tipo" value="{{ $reqTipo && $reqTipo !== 'all' ? $reqTipo : '' }}" data-filter-value>
            <div class="cat-filter-box">
                <div style="padding:0 12px; display:flex; align-items:center; color:#64748b;">
                    <i class="material-icons" style="font-size:18px;">search</i>
                </div>
                <input type="text" id="catTxtTipo" name="filter_search_dropdown_t" placeholder="{{ $tipoLabel ?: 'Filtrar Tipo...' }}"
                       autocomplete="off"
                       oninput="catFilterList('tipo', this.value)"
                       onfocus="catOpenList('tipo')"
                       onclick="catOpenList('tipo')"
                       onblur="setTimeout(()=>catCloseList('tipo'),200)">
                <i class="material-icons filter-clear"
                   style="display: {{ $reqTipo && $reqTipo !== 'all' ? 'flex' : 'none' }};"
                   onmousedown="event.preventDefault(); catSelect('tipo','','');">close</i>
            </div>
            <div id="catListTipo" class="cat-list">
                <div class="cat-opt placeholder" data-label="TODOS LOS TIPOS"
                     onmousedown="event.preventDefault(); catSelect('tipo','','TODOS LOS TIPOS');">TODOS LOS TIPOS</div>
                @foreach(($availableTipos ?? []) as $t)
                    <div class="cat-opt" data-label="{{ $t->nombre }}"
                         onmousedown="event.preventDefault(); catSelect('tipo','{{ $t->id }}','{{ addslashes($t->nombre) }}');">
                        {{ $t->nombre }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Modelo --}}
        <div class="cat-filter {{ $reqModelo && $reqModelo !== 'all' ? 'active' : '' }}">
            <input type="hidden" id="catValModelo" name="modelo" value="{{ $reqModelo && $reqModelo !== 'all' ? $reqModelo : '' }}" data-filter-value>
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
                   style="display: {{ $reqModelo && $reqModelo !== 'all' ? 'flex' : 'none' }};"
                   onmousedown="event.preventDefault(); catSelect('modelo','','');">close</i>
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

    // Subida de foto haciendo click en la foto de la tarjeta — sin abrir el formulario
    // de edición. Abre un selector de archivo, valida tamaño y sube a catalogo.uploadFoto.
    // UX: spinner → toast → refresco del grid por AJAX (loadCatalogo, sin recargar la página).
    window.catUploadPhoto = function (id) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/jpg,image/png,image/webp';
        input.style.display = 'none';
        input.addEventListener('change', function () {
            if (!input.files || !input.files[0]) return;
            var file = input.files[0];
            if (file.size > 5 * 1024 * 1024) {
                if (window.showToast) window.showToast('La foto supera los 5MB.', 'error');
                return;
            }
            var fd = new FormData();
            fd.append('foto', file);
            var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            if (typeof window.showPreloader === 'function') window.showPreloader();
            fetch('{{ url('admin/catalogo') }}/' + id + '/photo', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (window.hidePreloader) window.hidePreloader();
                if (res.ok && res.body.success) {
                    if (window.showToast) window.showToast(res.body.message || 'Foto actualizada correctamente.', 'success');
                    // Refrescar el grid (preserva filtros) para mostrar la foto nueva del
                    // modelo y de cualquier equipo que la herede vía auto-link.
                    setTimeout(function () {
                        if (typeof window.loadCatalogo === 'function') { window.loadCatalogo(); }
                        else { window.location.reload(); }
                    }, 1200);
                } else {
                    var msg = (res.body && res.body.message) || 'No se pudo subir la foto.';
                    if (window.showToast) window.showToast(msg, 'error');
                }
            })
            .catch(function () {
                if (window.hidePreloader) window.hidePreloader();
                if (window.showToast) window.showToast('Error de red al subir la foto.', 'error');
            });
        });
        document.body.appendChild(input);
        input.click();
        setTimeout(function () { if (input.parentNode) document.body.removeChild(input); }, 1000);
    };
</script>
@endsection
