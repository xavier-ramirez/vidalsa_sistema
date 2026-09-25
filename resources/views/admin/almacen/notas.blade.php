@extends('layouts.estructura_base')

@section('title', 'Historial de Notas de Entrega')

@section('content')
@php
    // `idAlmacenActivo` lo provee el controller (incluyendo el default-merge por frente).
    $reqAlmacen = $idAlmacenActivo ?? null;
    $reqTipo    = request('tipo');
    $reqFrente  = request('id_frente');
    $reqSearch  = request('search');
    $reqDesde   = request('desde');
    $reqHasta   = request('hasta');
    $reqCat     = request('categoria');
    $hayAdv     = $reqDesde || $reqHasta || ($reqCat && $reqCat !== 'all') || $reqTipo;
    $almSel     = $reqAlmacen ? ($almacenes ?? collect())->firstWhere('ID_ALMACEN', (int) $reqAlmacen) : null;
    $tipos = [
        'ENTRADAS'  => ['label' => 'Entradas', 'sub' => ''],
        'SALIDAS'   => ['label' => 'Salidas', 'sub' => ''],
        'AUDITORIA' => ['label' => 'Auditoría', 'sub' => ''],
    ];
    $tipoSelLabel = ($reqTipo && isset($tipos[$reqTipo])) ? $tipos[$reqTipo]['label'] . ($tipos[$reqTipo]['sub'] ? ' ' . $tipos[$reqTipo]['sub'] : '') : null;
    $frenteSel    = ($reqFrente && $reqFrente !== 'all') ? ($frentesLista ?? collect())->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
    $catSel       = ($reqCat && $reqCat !== 'all') ? $reqCat : null;

    // URL para la "vista por producto" (regresa al kardex original) conservando filtros.
    $backParams = array_filter([
        'id_almacen' => $reqAlmacen,
        'id_frente'  => $reqFrente,
        'desde'      => $reqDesde,
        'hasta'      => $reqHasta,
        'categoria'  => $reqCat,
        // 'tipo' del kardex es libre; no lo trasladamos para no acotarlo a SALIDA.
    ], fn ($v) => $v !== null && $v !== '');
@endphp

@include('admin.partials.page_header', [
    'titulo'    => 'Historial de Notas de Entrega',
    'align'     => 'left',
    'margin'    => '0 0 10px 0',
    'separador' => true,
    'acciones'  => 'admin.almacen.partials.filtro_almacen_header',
    'filtroId'  => 'almNotFiltroAlmacen',
])

<link rel="stylesheet" href="{{ asset('css/vistas/admin_almacen_notas.css') }}?v={{ @filemtime(public_path('css/vistas/admin_almacen_notas.css')) }}">

<div class="page-layout-grid">
<div class="admin-card" style="margin:0;min-height:70vh;min-width:0;width:100%;padding:14px;">

    <div class="anf-stat-pill"><i class="material-icons">description</i> <span>{{ $total }}</span> notas</div>

    {{-- ── Filtros ── --}}
    <div id="almNotFilters">
        {{-- 1. Frente --}}
        <div class="anf-item">
            <div class="custom-dropdown" id="almNotFiltroFrente" data-filter-type="id_frente" data-default-label="Todos los frentes">
                <input type="hidden" name="id_frente" data-filter-value value="{{ $reqFrente && $reqFrente !== 'all' ? $reqFrente : '' }}">
                <div class="dropdown-trigger {{ $frenteSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:#fbfcfd;overflow:hidden;border:1px solid #cbd5e0;border-radius:12px;height:45px;">
                    <span style="padding:0 10px;display:flex;align-items:center;color:var(--maquinaria-gray-text);"><i class="material-icons" style="font-size:18px;transform:none !important;">search</i></span>
                    <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                           placeholder="{{ $frenteSel ? $frenteSel->NOMBRE_FRENTE : 'Todos los frentes' }}"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="window.filterDropdownOptions(this)">
                    <i class="material-icons" data-clear-btn style="padding:0 5px;color:var(--maquinaria-gray-text);font-size:18px;display:{{ $frenteSel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                       onclick="event.stopPropagation(); clearDropdownFilter('almNotFiltroFrente');">close</i>
                </div>
                <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                    <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                        <div class="dropdown-item {{ !$frenteSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almNotFiltroFrente','all','TODOS LOS FRENTES');">TODOS LOS FRENTES</div>
                        @foreach(($frentesLista ?? collect()) as $f)
                            <div class="dropdown-item {{ $frenteSel && $frenteSel->ID_FRENTE == $f->ID_FRENTE ? 'selected' : '' }}" data-value="{{ $f->ID_FRENTE }}"
                                 onclick="selectOption('almNotFiltroFrente','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}');">{{ $f->NOMBRE_FRENTE }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- 2. Buscar N° de Nota --}}
        <div class="anf-item anf-search">
            <div class="anf-search-box {{ $reqSearch ? 'active' : '' }}">
                <i class="material-icons lupa">search</i>
                <input type="text" id="almNotSearch" autocomplete="off" placeholder="Nota de entrega…" value="{{ $reqSearch }}"
                       onkeydown="if(event.key==='Enter'){event.preventDefault();window.loadNotas();}">
                <i class="material-icons clr" id="almNotSearchClear" style="display:{{ $reqSearch ? 'block' : 'none' }};"
                   onclick="document.getElementById('almNotSearch').value=''; this.style.display='none'; window.loadNotas();">close</i>
            </div>
        </div>

        {{-- 3. Filtros Avanzados (al lado del buscador) --}}
        <div style="position:relative;flex:0 0 auto;">
            <button type="button" id="btnAdvancedFilterNot" class="btn-primary-maquinaria btn-filtro-avanzado {{ $hayAdv ? 'activo' : '' }}" title="Filtros Avanzados"
                    onclick="window.almNotToggleFechas(event)">
                <i class="material-icons">filter_list</i>
            </button>
            <div id="almNotFechasPanel" class="panel-filtro-avanzado ancho" style="display:none;">
                <h4 class="panel-filtro-avanzado-titulo">
                    Filtros Avanzados
                    <span class="panel-filtro-avanzado-limpiar" onclick="window.almNotLimpiarFechas(event)">Limpiar Todo</span>
                </h4>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;align-items:start;">
                  <div style="min-width:0;">
                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Tipo</span>
                    <div class="custom-dropdown" id="almNotFiltroTipo" data-filter-type="tipo" data-default-label="Todos los tipos">
                        <input type="hidden" name="tipo" data-filter-value value="{{ $reqTipo && isset($tipos[$reqTipo]) ? $reqTipo : '' }}">
                        <div class="dropdown-trigger {{ $tipoSelLabel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:{{ $tipoSelLabel ? '#e1effa' : '#fff' }};overflow:hidden;border:1px solid #cbd5e0;border-radius:8px;height:32px;">
                            <span style="padding:0 6px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:14px;transform:none !important;">search</i></span>
                            <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                                   placeholder="{{ $tipoSelLabel ?: 'Todos' }}"
                                   style="flex:1;border:none;background:transparent;padding:0 4px;font-size:12px;color:#0f172a;outline:none;min-width:0;"
                                   oninput="window.filterDropdownOptions(this)">
                            <i class="material-icons" data-clear-btn style="padding:0 6px;color:#64748b;font-size:16px;display:{{ $tipoSelLabel ? 'block' : 'none' }};cursor:pointer;transform:none !important;"
                               onclick="event.stopPropagation(); clearDropdownFilter('almNotFiltroTipo');">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                            <div class="dropdown-item-list" style="max-height:250px;overflow-y:auto;">
                                @foreach($tipos as $k => $t)
                                    <div class="dropdown-item {{ $reqTipo === $k ? 'selected' : '' }}" data-value="{{ $k }}" onclick="selectOption('almNotFiltroTipo','{{ $k }}','{{ addslashes($t['label']) }}');">{{ $t['label'] }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                  </div>
                  <div style="min-width:0;">
                    <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Categoría</span>
                    <div class="custom-dropdown" id="almNotFiltroCat" data-filter-type="categoria" data-default-label="Todas las categorías">
                        <input type="hidden" name="categoria" data-filter-value value="{{ $catSel ?: '' }}">
                        <div class="dropdown-trigger {{ $catSel ? 'filter-active' : '' }}" style="padding:0;display:flex;align-items:center;background:{{ $catSel ? '#e1effa' : 'white' }};overflow:hidden;border:1px solid #e2e8f0;border-radius:8px;height:32px;">
                            <span style="padding:0 6px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:14px;transform:none !important;">search</i></span>
                            <input type="text" name="filter_search_dropdown" data-filter-search autocomplete="off"
                                   placeholder="{{ $catSel ?: 'Todas' }}"
                                   style="flex:1;border:none;background:transparent;padding:0 4px;font-size:12px;outline:none;min-width:0;color:#334155;"
                                   oninput="window.filterDropdownOptions(this)">
                            <i class="material-icons" data-clear-btn style="padding:0 6px;color:#64748b;font-size:14px;display:{{ $catSel ? 'inline-flex' : 'none' }};cursor:pointer;transform:none !important;"
                               onclick="event.stopPropagation(); clearDropdownFilter('almNotFiltroCat');">close</i>
                        </div>
                        <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                            <div class="dropdown-item-list" style="max-height:200px;overflow-y:auto;">
                                <div class="dropdown-item {{ !$catSel ? 'selected' : '' }}" data-value="all" onclick="selectOption('almNotFiltroCat','all','TODAS LAS CATEGORÍAS');">TODAS LAS CATEGORÍAS</div>
                                @foreach(($categorias ?? collect()) as $c)
                                    <div class="dropdown-item {{ $catSel === $c ? 'selected' : '' }}" data-value="{{ $c }}"
                                         onclick="selectOption('almNotFiltroCat','{{ addslashes($c) }}','{{ addslashes($c) }}');">{{ $c }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                  </div>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Desde</span>
                        <div id="almNotDesdeBox" style="display:flex;align-items:center;background:{{ $reqDesde ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 6px;cursor:pointer;"
                             onclick="var i=document.getElementById('almNotDesde'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:4px;pointer-events:none;">event</i>
                            <input type="date" id="almNotDesde" value="{{ $reqDesde }}" onchange="window.loadNotas()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almNotDesdeClear"
                               style="display:{{ $reqDesde ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almNotDesde'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almNotDesdeBox').style.background='white'; window.loadNotas();">close</i>
                        </div>
                    </div>
                    <div>
                        <span style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:4px;">Hasta</span>
                        <div id="almNotHastaBox" style="display:flex;align-items:center;background:{{ $reqHasta ? '#e1effa' : 'white' }};border:1px solid #e2e8f0;border-radius:6px;height:32px;padding:0 6px;cursor:pointer;"
                             onclick="var i=document.getElementById('almNotHasta'); if(i){ i.focus(); if(i.showPicker) try{i.showPicker();}catch(e){} }">
                            <i class="material-icons" style="font-size:16px;color:#94a3b8;margin-right:4px;pointer-events:none;">event</i>
                            <input type="date" id="almNotHasta" value="{{ $reqHasta }}" onchange="window.loadNotas()"
                                   style="flex:1;min-width:0;border:none;background:transparent;padding:6px 2px;font-size:12px;outline:none;color:#334155;cursor:pointer;">
                            <i class="material-icons" id="almNotHastaClear"
                               style="display:{{ $reqHasta ? 'inline-flex' : 'none' }};font-size:14px;color:#64748b;cursor:pointer;padding:2px;border-radius:50%;"
                               onclick="event.stopPropagation(); var i=document.getElementById('almNotHasta'); if(i){ i.value=''; } this.style.display='none'; document.getElementById('almNotHastaBox').style.background='white'; window.loadNotas();">close</i>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <div class="anf-btn-ver" style="margin-left:auto;flex:0 0 auto;">
            <a href="{{ route('almacen.movimientos', $backParams) }}"
               class="btn-primary-maquinaria"
               style="padding:0 15px;height:45px;display:inline-flex;align-items:center;gap:8px;text-decoration:none;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);"
               onclick="event.preventDefault(); if(window.navigateTo) window.navigateTo(this.href); else window.location.href=this.href;">
                <span>Ver por producto</span>
            </a>
        </div>
    </div>

    {{-- ── Tabla ── 5 columnas: Fecha (con el tipo debajo) · N° de Nota · Almacén
         origen · Frente · Acciones (PDF y devolución). Se quitaron "N° Líneas" y
         "Cant. total" (info que ya vive dentro de la propia Nota / PDF). --}}
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:12px;">
        <table class="alm-not-table">
            <thead>
                <tr>
                    <th style="width:130px;">Fecha</th>
                    <th style="width:170px;">N° de Nota</th>
                    <th>Almacén origen</th>
                    <th>Frente</th>
                    <th style="width:100px;">Acciones</th>
                </tr>
            </thead>
            <tbody id="almNotTableBody">
                @include('admin.almacen.partials.notas_rows', ['notas' => $notas, 'almById' => $almById, 'freById' => $freById, 'conDevolucion' => $conDevolucion])
            </tbody>
        </table>
    </div>

    <div id="almNotPagination" style="margin-top:14px;">
        {{ $notas->links('vendor.pagination.custom-sliding') }}
    </div>

</div>

{{-- Sidebar: contador "Total Notas" + ranking "Consumo de Inventario" (mismo
     partial y mismas dimensiones que el sidebar del kardex en /admin/almacen/movimientos). --}}
<div class="counter-sidebar" style="position:sticky;top:20px;display:flex;flex-direction:column;gap:8px;">
    <div style="background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 100%);border-radius:12px;padding:15px;color:#fff;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1);position:relative;overflow:hidden;">
        <i class="material-icons" style="position:absolute;right:-12px;bottom:-12px;font-size:78px;opacity:0.12;transform:rotate(-12deg);">description</i>
        <div style="position:relative;z-index:2;">
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;opacity:0.9;margin-bottom:5px;">Total Notas</div>
            <div style="display:flex;align-items:baseline;gap:6px;">
                <span id="almNotTotalCount" style="font-size:32px;font-weight:800;line-height:1;letter-spacing:-1px;">{{ $total }}</span>
                <span style="font-size:12px;opacity:0.85;font-weight:500;">notas (según filtros)</span>
            </div>
        </div>
    </div>

    {{-- Ranking de Consumo de Inventario — mismo partial y mismas dimensiones que el
         sidebar de /admin/almacen/movimientos. Click en una fila lleva al kardex
         filtrado por ese producto (la fn almMovFiltrarPorProducto la define más
         abajo en este propio archivo para que el partial funcione cross-page). --}}
    <div style="background:white;border-radius:12px;padding:15px;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);overflow:hidden;">
        @include('admin.almacen.partials.consumo_stats', ['consumo' => $consumo])
    </div>
</div>

</div>{{-- /page-layout-grid --}}

<script>
(function () {
    'use strict';
    if (!document.getElementById('almNotTableBody')) return;

    function el(id) { return document.getElementById(id); }
    function hv(name) { var e = document.querySelector('input[name="' + name + '"][data-filter-value]'); return e ? String(e.value).trim() : ''; }

    window.loadNotas = async function (pageUrl) {
        var body = el('almNotTableBody');
        if (!body) return;

        if (window.showPreloader) window.showPreloader();
        body.style.opacity = '0.5';

        try {
            var p = new URLSearchParams();
            var alm = hv('id_almacen'); if (alm) p.set('id_almacen', alm);
            var tipo = hv('tipo'); if (tipo && tipo !== 'all') p.set('tipo', tipo);
            var fr = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
            var cat = hv('categoria'); if (cat && cat !== 'all') p.set('categoria', cat);
            var s = el('almNotSearch'); if (s && s.value.trim()) p.set('search', s.value.trim());
            var d = el('almNotDesde'); if (d && d.value) p.set('desde', d.value);
            var h = el('almNotHasta'); if (h && h.value) p.set('hasta', h.value);

            if (pageUrl && typeof pageUrl === 'string') {
                try { var pg = new URL(pageUrl, window.location.origin).searchParams.get('page'); if (pg) p.set('page', pg); } catch (_) {}
            }

            var baseUrl = @json(route('almacen.notas'));
            var qs = p.toString();
            var finalUrl = baseUrl + (qs ? ('?' + qs) : '');

            var resp = await window.apiFetch(finalUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            var data = await resp.json();

            body.innerHTML = data.html || '';
            body.style.opacity = '1';

            var pag = document.getElementById('almNotPagination');
            if (pag) pag.innerHTML = data.pagination || '';

            var cnt = document.getElementById('almNotTotalCount');
            if (cnt && data.total !== undefined) cnt.textContent = data.total;

            if (window.history && window.history.pushState) {
                window.history.pushState(null, '', finalUrl);
            }

            var sb = el('almNotSearchClear');
            if (sb) sb.style.display = (s && s.value.trim()) ? 'block' : 'none';
            var box = s ? s.closest('.anf-search-box') : null;
            if (box) { if (s.value.trim()) box.classList.add('active'); else box.classList.remove('active'); }
        } catch (e) {
            console.error('[loadNotas]', e);
            if (body) body.style.opacity = '1';
        } finally {
            if (window.hidePreloader) window.hidePreloader();
        }
    };

    // Selección en cualquier custom-dropdown → recarga via AJAX.
    // uicomponents.js emite 'dropdown-selection' al seleccionar/limpiar.
    //
    // SPA: navegacion.js re-ejecuta este <script> en cada visita. Los listeners de
    // document/window se APILAN si no se protegen → un cambio de filtro dispararía
    // loadNotas() una vez por visita. Guardia por bandera (una sola vez por pestaña); las
    // funciones window.* que invocan se redefinen en cada montaje, así siguen vivas.
    var _almNotBound = window.__almNotGlobalBound === true;
    window.__almNotGlobalBound = true;
    // Supresión de la ráfaga de 'dropdown-selection' que emiten los custom-dropdown al
    // inicializar su valor por defecto en cada montaje. En window (no var local) porque el
    // listener se registra UNA vez pero el script corre en cada visita: se reinicia aquí y el
    // handler persistente lee siempre el valor de la visita actual.
    window.__almNotReady = false;
    setTimeout(function () { window.__almNotReady = true; }, 500);
    if (!_almNotBound) window.addEventListener('dropdown-selection', function (e) {
        if (!window.__almNotReady) return;
        if (!document.getElementById('almNotTableBody')) return;
        var id = e.detail && e.detail.dropdownId;
        if (id === 'almNotFiltroAlmacen' || id === 'almNotFiltroFrente' || id === 'almNotFiltroTipo' || id === 'almNotFiltroCat') {
            window.loadNotas();
        }
    });

    // Panel "Filtros Avanzados" (Desde / Hasta / Categoría).
    window.almNotToggleFechas = function (e) {
        if (e) e.stopPropagation();
        var p = el('almNotFechasPanel'); if (!p) return;
        p.style.display = (p.style.display === 'block') ? 'none' : 'block';
    };
    // "Limpiar Todo" del panel avanzados: borra tipo, categoria, desde y hasta.
    // Usa clearDropdownFilter para resetear la UI visual de los dropdowns pero
    // desactiva el guard _almNotReady para no disparar loadNotas por cada clear.
    window.almNotLimpiarFechas = function (ev) {
        if (ev) { ev.preventDefault(); ev.stopPropagation(); }
        var d = el('almNotDesde'), h = el('almNotHasta');
        if (d) d.value = '';
        if (h) h.value = '';
        var dc = el('almNotDesdeClear'); if (dc) dc.style.display = 'none';
        var hc = el('almNotHastaClear'); if (hc) hc.style.display = 'none';
        var db = el('almNotDesdeBox'); if (db) db.style.background = 'white';
        var hb = el('almNotHastaBox'); if (hb) hb.style.background = 'white';
        _almNotReady = false;
        if (typeof clearDropdownFilter === 'function') {
            clearDropdownFilter('almNotFiltroTipo');
            clearDropdownFilter('almNotFiltroCat');
        }
        _almNotReady = true;
        window.loadNotas();
    };
    if (!_almNotBound) document.addEventListener('click', function (e) {
        var p = el('almNotFechasPanel'); var b = el('btnAdvancedFilterNot');
        if (!p || !b) return;
        if (p.style.display === 'block' && !p.contains(e.target) && !b.contains(e.target)) {
            p.style.display = 'none';
        }
    });

    // Shim cross-page para el partial consumo_stats (compartido con /admin/almacen/movimientos):
    // su <li> dispara window.almMovFiltrarPorProducto(id, nombre). Aquí no hay una tabla de
    // movimientos que recargar, así que redirigimos al kardex con el filtro de búsqueda
    // pre-aplicado (mismo patrón que usa el botón "Ver por producto" del header).
    //
    // Se asigna SIEMPRE, sin preguntar si ya existe. Antes iba dentro de un
    // `if (typeof ... !== 'function')` y en la SPA eso fallaba: al entrar primero a
    // /movimientos quedaba pegada la versión de esa página, y al pasar a Notas el clic
    // en el ranking llamaba a almMovBuscarPick + loadMovimientos, que buscan una tabla
    // que aquí no existe (el clic no hacía nada). Como el script de cada página corre
    // en cada navegación, asignar directo deja mandando a la página en la que estás.
    window.almMovFiltrarPorProducto = function (_idProducto, nombre) {
        var p = new URLSearchParams();
        var alm = hv('id_almacen'); if (alm) p.set('id_almacen', alm);
        var fr  = hv('id_frente'); if (fr && fr !== 'all') p.set('id_frente', fr);
        var cat = hv('categoria'); if (cat && cat !== 'all') p.set('categoria', cat);
        var d   = el('almNotDesde'); if (d && d.value) p.set('desde', d.value);
        var h   = el('almNotHasta'); if (h && h.value) p.set('hasta', h.value);
        if (nombre) p.set('search', nombre);
        window.location.href = @json(route('almacen.movimientos')) + '?' + p.toString();
    };

    // Paginación AJAX
    if (!_almNotBound) document.addEventListener('click', function (e) {
        var link = e.target.closest('#almNotPagination a.page-link');
        if (link) { e.preventDefault(); e.stopImmediatePropagation(); window.loadNotas(link.href); }
    });
})();
</script>
@endsection
