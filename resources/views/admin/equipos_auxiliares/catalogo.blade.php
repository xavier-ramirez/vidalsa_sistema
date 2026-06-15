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
        /* contain en lugar de cover: muestra la foto completa sin recortarla.
           El background gris (#f1f5f9) del wrapper rellena los bordes cuando
           la foto no calza el aspect-ratio 16:11. Esto evita que la foto se
           vea "demasiado cerca" cuando la imagen original es vertical o cuadrada. */
        object-fit: contain;
        background: #f8fafc;
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
    /* Mismo estilo que .aux-main-list / .aux-main-opt del listado principal
       para que la lista de filtros del catalogo se vea identica al resto del
       modulo. Padding 5px del wrapper, opciones con border-radius. */
    .aux-cat-list {
        position: absolute;
        top: 100%;
        left: 0; right: 0;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        max-height: 260px;
        overflow-y: auto;
        margin-top: 4px;
        padding: 5px;
        z-index: 9999;
        display: none;
    }
    .aux-cat-opt {
        padding: 8px 12px;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        cursor: pointer;
        border-radius: 6px;
    }
    .aux-cat-opt:hover { background: #f0f4f8; }
    .aux-cat-opt.placeholder {
        font-size: 13px;
        color: #475569;
        font-weight: 600;
    }
    #auxCatListTipo .aux-cat-opt { text-transform: uppercase; }
    /* Overlay "Cambiar foto" sobre la foto al hacer hover. Indica que se
       puede subir una nueva foto representativa para este modelo+ano. */
    .aux-cat-photo-overlay {
        position: absolute;
        inset: 0;
        background: rgba(15, 23, 42, 0.55);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-direction: column;
        gap: 6px;
        opacity: 0;
        transition: opacity 0.18s ease;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        pointer-events: none;
    }
    .aux-cat-photo-overlay .material-icons { font-size: 28px; }
    .aux-cat-card:hover .aux-cat-photo-overlay { opacity: 1; }

    /* Boton flotante "Editar foto" — siempre visible (descubrible en touch
       donde el hover no aplica). Esquina inferior derecha de la foto. */
    .aux-cat-edit-btn {
        position: absolute;
        right: 8px;
        bottom: 8px;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.95);
        border: 1px solid #e2e8f0;
        color: #0067b1;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 8px rgba(15,23,42,0.18);
        transition: background 0.15s, transform 0.15s;
        z-index: 3;
    }
    .aux-cat-edit-btn:hover {
        background: #0067b1;
        color: #fff;
        transform: scale(1.05);
    }
    .aux-cat-edit-btn .material-icons { font-size: 18px; }

    @media (max-width: 600px) {
        .aux-cat-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
    }
    /* Mobile: filtros y boton "Nuevo" todos al MISMO ancho (100%), uno
       arriba del otro. Mismo patron que /admin/catalogo. */
    @media (max-width: 600px) {
        #auxCatFilters {
            display: flex !important;
            flex-direction: column !important;
            align-items: stretch !important;
            width: 100% !important;
            box-sizing: border-box !important;
            gap: 8px !important;
        }
        #auxCatFilters .aux-cat-filter,
        #auxCatFilters > a.btn-primary-maquinaria {
            max-width: none !important;
            min-width: 0 !important;
            width: 100% !important;
            flex: 1 1 100% !important;
            box-sizing: border-box !important;
        }
        #auxCatFilters > a.btn-primary-maquinaria {
            justify-content: center !important;
            order: 99;
            padding: 0 12px !important;
        }
    }
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 class="page-title" style="margin-bottom:2px;">
            <span class="page-title-line2" style="color:#000;">Catálogo de Auxiliares</span>
        </h1>
    </div>
    {{-- Boton "Nuevo" se desplazo dentro del row de filtros (al lado del
         filtro de Marca) para mantener el patron de /admin/catalogo y para
         que en mobile siga aparte sin estorbar el titulo. --}}
</div>

<div class="admin-card" style="margin:0;min-height:60vh;padding:14px;">

    {{-- Filtros: mismo estilo que /admin/equipos-auxiliares (autocomplete con
         lista desplegable). Al elegir/limpiar se hace submit automaticamente.
         No hay boton "Aplicar". --}}
    @php
        $reqSearch = request('search');
        $reqTipo   = request('tipo');
        $reqMarca  = request('marca');
        $reqModelo = request('modelo');
        $tipoLabel  = ($reqTipo && $reqTipo !== 'all') ? strtoupper($tipos[$reqTipo] ?? $reqTipo) : '';
        $marcaLabel = ($reqMarca && $reqMarca !== 'all') ? $reqMarca : '';
        $modeloLabel = ($reqModelo && $reqModelo !== 'all') ? $reqModelo : '';
    @endphp
    <form id="auxCatFilters" method="GET" action="{{ route('equipos-auxiliares.catalogo') }}"
          onsubmit="event.preventDefault(); auxCatSubmit();">

        {{-- Tipo --}}
        <div class="aux-cat-filter {{ $reqTipo && $reqTipo !== 'all' ? 'active' : '' }}" data-cat-role="dropdown">
            <input type="hidden" id="auxCatValTipo" name="tipo" value="{{ $reqTipo && $reqTipo !== 'all' ? $reqTipo : '' }}">
            <div class="aux-cat-filter-box">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                <input type="text" id="auxCatTxtTipo" placeholder="{{ $tipoLabel ?: 'Filtrar Tipo...' }}"
                       value="{{ $tipoLabel }}" autocomplete="off"
                       oninput="auxCatFilterList('tipo', this.value)"
                       onfocus="auxCatOpenList('tipo')"
                       onclick="auxCatOpenList('tipo')"
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
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                <input type="text" id="auxCatTxtMarca" placeholder="{{ $marcaLabel ?: 'Filtrar Marca...' }}"
                       value="{{ $marcaLabel }}" autocomplete="off"
                       oninput="auxCatFilterList('marca', this.value)"
                       onfocus="auxCatOpenList('marca')"
                       onclick="auxCatOpenList('marca')"
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

        {{-- Modelo --}}
        <div class="aux-cat-filter {{ $reqModelo && $reqModelo !== 'all' ? 'active' : '' }}" data-cat-role="dropdown">
            <input type="hidden" id="auxCatValModelo" name="modelo" value="{{ $reqModelo && $reqModelo !== 'all' ? $reqModelo : '' }}">
            <div class="aux-cat-filter-box">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                <input type="text" id="auxCatTxtModelo" placeholder="{{ $modeloLabel ?: 'Filtrar Modelo...' }}"
                       value="{{ $modeloLabel }}" autocomplete="off"
                       oninput="auxCatFilterList('modelo', this.value)"
                       onfocus="auxCatOpenList('modelo')"
                       onclick="auxCatOpenList('modelo')"
                       onblur="setTimeout(()=>auxCatCloseList('modelo'),200)">
                @if($reqModelo && $reqModelo !== 'all')
                    <i class="material-icons filter-clear" onmousedown="event.preventDefault(); auxCatSelect('modelo','','');">close</i>
                @endif
            </div>
            <div id="auxCatListModelo" class="aux-cat-list">
                <div class="aux-cat-opt placeholder" data-label="TODOS LOS MODELOS"
                     onmousedown="event.preventDefault(); auxCatSelect('modelo','','TODOS LOS MODELOS');">TODOS LOS MODELOS</div>
                @foreach($modelos as $m)
                    <div class="aux-cat-opt" data-label="{{ $m }}"
                         onmousedown="event.preventDefault(); auxCatSelect('modelo','{{ $m }}','{{ addslashes($m) }}');">
                        {{ $m }}
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Boton "Nuevo" — al lado del ultimo filtro (mismo patron de
             /admin/catalogo). Visible siempre, valida permiso al click. --}}
        <a href="{{ route('equipos-auxiliares.create') }}" class="btn-primary-maquinaria"
           style="height:45px;display:flex;align-items:center;padding:0 15px;text-decoration:none;gap:8px;flex:0 0 auto;"
           @cannot('equipos.create')
               onclick="event.preventDefault(); if(window.showToast) window.showToast('No tienes permiso para registrar nuevos auxiliares.', 'error');"
           @endcannot>
            <i class="material-icons" style="font-size:18px;">add_circle</i>
            Nuevo
        </a>
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
                <div class="aux-cat-card" style="position:relative;">
                    <div class="aux-cat-photo" style="cursor:pointer;"
                         data-tipo="{{ $it['tipo'] }}"
                         data-marca="{{ $it['marca'] }}"
                         data-modelo="{{ $it['modelo'] }}"
                         data-anio="{{ $it['anio'] ?? '' }}"
                         onclick="event.preventDefault(); event.stopPropagation(); auxCatUploadPhoto(this);"
                         title="Click para cambiar la foto del modelo">
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
                        @can('equipos.create')
                            <div class="aux-cat-photo-overlay">
                                <i class="material-icons">photo_camera</i>
                                <span>Cambiar foto</span>
                            </div>
                            {{-- Boton de edicion explicito (visible siempre): el overlay solo
                                 aparece al hover, este boton hace la accion descubrible en
                                 mobile/touch donde no hay hover. --}}
                            <button type="button" class="aux-cat-edit-btn"
                                    onclick="event.preventDefault(); event.stopPropagation(); auxCatUploadPhoto(this.parentElement);"
                                    title="Cambiar foto del modelo">
                                <i class="material-icons">edit</i>
                            </button>
                        @endcan
                    </div>
                    <a href="{{ $linkFiltro }}" class="aux-cat-body" style="text-decoration:none;color:inherit;" title="Ver auxiliares de este modelo">
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
                    </a>
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
    // Submit del form (sin boton "Aplicar"). Activa el preloader global de la
    // app antes de enviar — asi el usuario ve el spinner mientras Laravel
    // recarga la pagina con los nuevos filtros (mismo patron que el resto del
    // sistema usa con showPreloader/hidePreloader).
    function auxCatSubmit() {
        if (typeof window.showPreloader === 'function') window.showPreloader();
        document.getElementById('auxCatFilters').submit();
    }

    // Dropdown helpers (mismo patron que auxMain* del listado principal).
    // Soporta prefijos: tipo / marca / modelo.
    function _auxCatCap(prefix) {
        return prefix === 'tipo' ? 'Tipo' : (prefix === 'marca' ? 'Marca' : 'Modelo');
    }
    function auxCatOpenList(prefix) {
        var l = document.getElementById('auxCatList' + _auxCatCap(prefix));
        if (l) {
            l.style.display = 'block';
            // Al reabrir, mostramos todas las opciones (resetea filtros previos)
            l.querySelectorAll('.aux-cat-opt').forEach(function (o) { o.style.display = ''; });
        }
    }
    function auxCatCloseList(prefix) {
        var l = document.getElementById('auxCatList' + _auxCatCap(prefix));
        if (l) l.style.display = 'none';
    }
    function auxCatFilterList(prefix, query) {
        var list = document.getElementById('auxCatList' + _auxCatCap(prefix));
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
        var capPrefix = _auxCatCap(prefix);
        var hidden = document.getElementById('auxCatVal' + capPrefix);
        var txt    = document.getElementById('auxCatTxt' + capPrefix);
        if (hidden) hidden.value = value || '';
        if (txt)    txt.value    = value ? label : '';
        auxCatCloseList(prefix);
        auxCatSubmit();
    }

    // ─── Upload de foto representativa por modelo+ano ──────────────────
    // Click en la foto de una tarjeta abre el file picker. La foto se
    // convierte a WebP en el servidor y se aplica a TODAS las unidades
    // del mismo TIPO+MARCA+MODELO+ANIO (mismo patron que /admin/catalogo).
    @can('equipos.create')
    window.auxCatUploadPhoto = function (photoEl) {
        var tipo   = photoEl.dataset.tipo   || '';
        var marca  = photoEl.dataset.marca  || '';
        var modelo = photoEl.dataset.modelo || '';
        var anio   = photoEl.dataset.anio   || '';
        if (!tipo || !marca || !modelo) {
            if (window.showToast) window.showToast('Datos del modelo incompletos.', 'error');
            return;
        }

        // Crear un input file invisible y disparar el click
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
            fd.append('tipo', tipo);
            fd.append('marca', marca);
            fd.append('modelo', modelo);
            if (anio) fd.append('anio', anio);
            var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            if (typeof window.showPreloader === 'function') window.showPreloader();
            fetch('{{ route("equipos-auxiliares.catalogo.uploadPhoto") }}', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (res.ok && res.body.success) {
                    // 1) Apagar el spinner ANTES de confirmar (antes seguía girando y la
                    //    recarga a 600ms cortaba el toast → parecía que no confirmaba).
                    if (window.hidePreloader) window.hidePreloader();
                    // 2) Notificación profesional de éxito.
                    if (window.showToast) window.showToast(res.body.message || 'Foto actualizada correctamente.', 'success');
                    // 3) Recargar para refrescar TODAS las tarjetas del modelo, con tiempo
                    //    suficiente para que el usuario alcance a ver la confirmación.
                    setTimeout(function () { window.location.reload(); }, 1400);
                } else {
                    if (window.hidePreloader) window.hidePreloader();
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
        setTimeout(function () { document.body.removeChild(input); }, 1000);
    };
    @else
    window.auxCatUploadPhoto = function () {
        if (window.showToast) window.showToast('No tienes permiso para cambiar la foto del modelo.', 'error');
    };
    @endcan
</script>
@endsection
