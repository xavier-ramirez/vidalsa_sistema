@extends('layouts.estructura_base')
@section('title', 'Equipos Auxiliares')

@section('content')
<style>
    /* Mobile compaction igual a /admin/equipos para el sidebar */
    @media (max-width: 900px) {
        .counter-sidebar [style*="font-size: 13px"] { font-size: 11px !important; }
        .counter-sidebar [style*="font-size: 36px"] { font-size: 26px !important; }
        .counter-sidebar [style*="font-size: 18px"] { font-size: 15px !important; }
        .counter-sidebar [style*="font-size: 16px"] { font-size: 14px !important; }
        .counter-sidebar [style*="font-size: 8px"] { font-size: 7.5px !important; letter-spacing: -0.3px !important; }
        .counter-sidebar h4 { font-size: 11px !important; margin-bottom: 8px !important; }
        .counter-sidebar h4 .material-icons { font-size: 15px !important; }
        .counter-sidebar li span { font-size: 9.5px !important; }
        .counter-sidebar { gap: 10px !important; }
    }

    /* ── Desktop: los 3 filtros siempre en una sola fila (no wrap por falta de ancho) ── */
    @media (min-width: 769px) {
        #auxFiltersForm {
            flex-wrap: nowrap !important;
        }
        #auxFiltersForm > .custom-dropdown,
        #auxFiltersForm > .search-wrapper {
            min-width: 0 !important;
        }
    }

    /* ── Reservar el espacio del scrollbar para evitar el "salto" de la tabla
       al abrir/cerrar el modal de detalles (cuando overflow:hidden remueve la
       barra de scroll del body). --- */
    html { scrollbar-gutter: stable; }

    /* Lista desplegable de autocomplete para Frente/Tipo/Serial.
       Tipografia 1:1 con .dropdown-item de /admin/equipos (estilos_globales.css
       L1129) pero con peso 600 y color #1e293b para igualar la legibilidad
       que el usuario ve en el modulo de Vehiculos. */
    .aux-main-list {
        display: none;
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        z-index: 9999;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        max-height: 260px;
        overflow-y: auto;
        margin-top: 4px;
        padding: 5px;
    }
    .aux-main-opt {
        padding: 8px 12px;
        font-size: 14px;
        font-weight: 600;
        color: #1e293b;
        cursor: pointer;
        border-radius: 6px;
    }
    .aux-main-opt:hover {
        background: #f0f4f8;
    }
    .aux-main-opt.placeholder {
        font-size: 13px;
        color: #475569;
        font-weight: 600;
    }
    /* Tipo: la lista completa en mayusculas */
    #aux_main_list_tipo .aux-main-opt {
        text-transform: uppercase;
    }
    /* Estilo unificado para opciones de filtros avanzados (igual al main).
       Fuente más compacta + truncado con elipsis para que los valores largos
       (y los cuadros) no se salgan de la lista estrecha del panel. */
    .aux-adv-opt {
        padding: 8px 12px !important;
        font-size: 12.5px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        cursor: pointer;
        border-radius: 6px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    /* Los filtros de a 2 por fila deben poder encoger dentro del panel (300px)
       para no desbordarse: el min-width:auto por defecto del grid lo impide. */
    .aux-adv-grid > div { min-width: 0; }
    /* Texto de los inputs del panel de filtros avanzados, un poco más pequeño. */
    #auxAdvPanel input[type="text"] { font-size: 12px; }
    /* Texto del input del filtro (Frente/Tipo/Serial) en oscuro como equipos.
       Sin esto el browser usa un gris muy claro por defecto que se percibia
       lavado vs el modulo de Vehiculos. */
    #auxFiltersForm input[type="text"] {
        color: #1e293b;
    }
    #auxFiltersForm input[type="text"]::placeholder {
        color: #94a3b8;
        opacity: 1;
    }
    /* Filtro Tipo: texto del input en MAYUSCULAS para coincidir con como se
       guardan los tipos en BD ("COMPRESOR_DE_AIRE" etc.). Se aplica solo al
       campo Tipo; Frente y Serial mantienen su capitalizacion natural. */
    #aux_main_txt_tipo {
        text-transform: uppercase;
    }
    #aux_main_txt_tipo::placeholder {
        text-transform: uppercase;
    }

    /* ── Responsive filtros en mobile: Frente y Tipo en linea propia, Serial+adv en misma fila, Acciones abajo ── */
    @media (max-width: 768px) {
        /* Subtitulo de la cabecera se oculta en mobile para dar mas espacio al contenido */
        .aux-page-subtitle {
            display: none !important;
        }
        #auxFiltersForm {
            flex-wrap: wrap !important;
            gap: 10px !important;
            align-items: stretch !important;
        }
        /* Frente y Tipo: cada uno en su propia fila (100%) para evitar que se aplasten */
        #auxFiltersForm > div[data-aux-role="dropdown"] {
            flex: 1 1 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
        }
        /* Comprimir altura de cajas de texto a 40px en móvil */
        #auxFiltersForm .search-wrapper, 
        #auxFiltersForm div[id^="aux_main_box_"] {
            height: 40px !important;
        }
        #auxFiltersForm input[type="text"] {
            font-size: 13px !important;
            padding: 8px 5px !important;
        }
        /* Serial + boton Filtros Avanzados: misma fila. */
        #auxFiltersForm > .search-wrapper {
            flex: 1 1 0 !important;
            min-width: 0 !important;
            max-width: none !important;
            width: auto !important;
        }
        /* Boton Filtros Avanzados compacto (40px) */
        #auxFiltersForm > div[data-aux-role="adv"] {
            flex: 0 0 40px !important;
            width: 40px !important;
            min-width: 40px !important;
        }
        #auxAdvBtn {
            height: 40px !important;
            width: 40px !important;
            min-width: 40px !important;
        }
        /* Contenedor del boton Acciones: fila propia full-width */
        #auxFiltersForm > div[data-aux-role="acciones"] {
            flex: 1 0 100% !important;
            display: flex !important;
        }
        #auxFiltersForm #auxAccionesBtn {
            flex: 1 !important;
            height: 48px !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            justify-content: center !important;
        }
        /* El posicionamiento/centrado del panel en mobile vive ahora en
           estilos_globales.css (regla unica para todos los modulos). Aqui solo
           quedan los ajustes de contenido interno del panel. */
        /* Compactar campos del panel para que no ocupen tanto vertical */
        #auxAdvPanel > h4 { margin: 0 0 10px 0 !important; font-size: 13px !important; }
        #auxAdvPanel span { font-size: 11.5px !important; margin-bottom: 3px !important; }
        #auxAdvPanel input[type="text"] { font-size: 13px !important; }
        /* Filtros doc Propiedad/Certificado (chips redondos): mas compactos */
        #auxAdvPanel label[style*="cursor:pointer"] { padding: 6px 10px !important; font-size: 12px !important; }
        
        /* Ajustar los items internos de las listas para que se ajusten bien */
        #auxAdvPanel .aux-adv-opt {
            padding: 10px 12px !important;
            font-size: 13px !important;
            white-space: normal !important;
            word-wrap: break-word !important;
            line-height: 1.3 !important;
        }
    }
    /* Tablets / pantallas medias: limitar el ancho del panel para que no
       se vea desproporcionado al redimensionar entre 769-1100px */
    @media (min-width: 769px) and (max-width: 1100px) {
        #auxAdvPanel {
            right: 0 !important;
        }
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
    <h1 class="page-title">
        <span class="page-title-line2" style="color: #000;">Equipos Auxiliares</span>
    </h1>
</div>

<div class="page-layout-grid">

    {{-- Columna izq: Filtros + Tabla --}}
    <div class="admin-card" data-page="equipos-auxiliares" style="margin: 0; min-height: 70vh; min-width: 0; width: 100%;">

        <form id="auxFiltersForm" onsubmit="event.preventDefault(); cargarAuxiliares();"
              style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:5px;align-items:center;">

            {{-- Frente (autocomplete propio, patron auxMainFilter) --}}
            @php
                $reqFrente = request('id_frente');
                $frenteActual = ($reqFrente && $reqFrente !== 'all') ? $frentes->firstWhere('ID_FRENTE', (int) $reqFrente) : null;
            @endphp
            <div data-aux-role="dropdown" style="flex:2;min-width:240px;position:relative;">
                <input type="hidden" id="aux_main_val_frente" name="id_frente" value="{{ $reqFrente ?: '' }}">
                <div style="display:flex;align-items:center;background:{{ $frenteActual ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ $frenteActual ? '#0067b1' : '#cbd5e0' }};border-radius:12px;height:45px;overflow:hidden;" id="aux_main_box_frente">
                    <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                    <input type="text" id="aux_main_txt_frente"
                           placeholder="{{ $frenteActual ? $frenteActual->NOMBRE_FRENTE : 'Filtrar Frente...' }}"
                           value="{{ $frenteActual ? $frenteActual->NOMBRE_FRENTE : '' }}"
                           autocomplete="off"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="auxMainFilter('frente', this.value)"
                           onfocus="auxMainOpen('frente')"
                           onclick="auxMainOpen('frente')"
                           onblur="setTimeout(()=>auxMainClose('frente'),200)">
                    <i class="material-icons" id="aux_main_clr_frente"
                       style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ $frenteActual ? 'block' : 'none' }};"
                       onmousedown="event.preventDefault();auxMainClear('frente');cargarAuxiliares();">close</i>
                </div>
                <div id="aux_main_list_frente" class="aux-main-list">
                    <div class="aux-main-opt placeholder" data-val="all" data-label="TODOS LOS FRENTES"
                         onmousedown="event.preventDefault();auxMainSelect('frente','all','TODOS LOS FRENTES');cargarAuxiliares();">TODOS LOS FRENTES</div>
                    {{-- Sentinel "none": auxiliares sin ID_FRENTE_ACTUAL en BD --}}
                    <div class="aux-main-opt" data-val="none" data-label="SIN ASIGNAR"
                         style="font-style: italic; color: #94a3b8;"
                         onmousedown="event.preventDefault();auxMainSelect('frente','none','SIN ASIGNAR');cargarAuxiliares();">SIN ASIGNAR</div>
                    @foreach($frentes as $frente)
                        @php $frenteNombreUpper = mb_strtoupper(trim($frente->NOMBRE_FRENTE)); @endphp
                        <div class="aux-main-opt" data-val="{{ $frente->ID_FRENTE }}" data-label="{{ $frenteNombreUpper }}"
                             onmousedown="event.preventDefault();auxMainSelect('frente','{{ $frente->ID_FRENTE }}','{{ addslashes($frenteNombreUpper) }}');cargarAuxiliares();">
                            {{ $frenteNombreUpper }}
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Tipo (autocomplete propio, patron auxMainFilter) --}}
            @php
                $reqTipo = request('tipo');
                $tipoLabel = ($reqTipo && $reqTipo !== 'all') ? ($tipos[$reqTipo] ?? 'Filtrar Tipo...') : 'Filtrar Tipo...';
                $tipoActivo = $reqTipo && $reqTipo !== 'all';
            @endphp
            <div data-aux-role="dropdown" style="flex:1.5;min-width:200px;position:relative;">
                <input type="hidden" id="aux_main_val_tipo" name="tipo" value="{{ $reqTipo ?: '' }}">
                <div style="display:flex;align-items:center;background:{{ $tipoActivo ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ $tipoActivo ? '#0067b1' : '#cbd5e0' }};border-radius:12px;height:45px;overflow:hidden;" id="aux_main_box_tipo">
                    <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                    <input type="text" id="aux_main_txt_tipo"
                           placeholder="{{ $tipoActivo ? $tipoLabel : 'Filtrar Tipo...' }}"
                           value="{{ $tipoActivo ? $tipoLabel : '' }}"
                           autocomplete="off"
                           style="flex:1;border:none;background:transparent;padding:10px 5px;font-size:14px;outline:none;min-width:0;"
                           oninput="auxMainFilter('tipo', this.value)"
                           onfocus="auxMainOpen('tipo')"
                           onclick="auxMainOpen('tipo')"
                           onblur="setTimeout(()=>auxMainClose('tipo'),200)">
                    <i class="material-icons" id="aux_main_clr_tipo"
                       style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ $tipoActivo ? 'block' : 'none' }};"
                       onmousedown="event.preventDefault();auxMainClear('tipo');cargarAuxiliares();">close</i>
                </div>
                <div id="aux_main_list_tipo" class="aux-main-list">
                    <div class="aux-main-opt placeholder" data-val="all" data-label="TODOS LOS TIPOS"
                         onmousedown="event.preventDefault();auxMainSelect('tipo','all','TODOS LOS TIPOS');cargarAuxiliares();">TODOS LOS TIPOS</div>
                    @foreach($tipos as $k => $label)
                        <div class="aux-main-opt" data-val="{{ $k }}" data-label="{{ $label }}"
                             onmousedown="event.preventDefault();auxMainSelect('tipo','{{ $k }}','{{ addslashes($label) }}');cargarAuxiliares();">
                            {{ $label }}
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Serial --}}
            <div class="search-wrapper" style="flex:1.5;min-width:200px;border:1px solid {{ request('search') ? '#0067b1' : '#cbd5e0' }};border-radius:12px;background:{{ request('search') ? '#e1effa' : '#fbfcfd' }};display:flex;align-items:center;height:45px;overflow:hidden;">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                {{-- Busqueda Serial: solo dispara consulta auto con 4+ caracteres o cuando se vacia
                     el campo (para limpiar resultados). Enter fuerza la busqueda inmediata. --}}
                <input type="text" id="auxSearchInput" name="search" value="{{ request('search') }}" placeholder="Filtrar Serial..."
                       oninput="window.auxToggleSerialClear && window.auxToggleSerialClear(this); window._auxDebounce && clearTimeout(window._auxDebounce); const __v=this.value.trim(); if(__v.length===0||__v.length>=4){ window._auxDebounce = setTimeout(cargarAuxiliares, 300); }"
                       onkeydown="if(event.key==='Enter'){ event.preventDefault(); window._auxDebounce && clearTimeout(window._auxDebounce); cargarAuxiliares(); }"
                       style="flex:1;border:none;background:transparent;padding:12px 5px;font-size:13px;outline:none;min-width:0;" autocomplete="off">
                <i id="aux_main_clr_search" class="material-icons"
                   style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('search') ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); var i=document.getElementById('auxSearchInput'); i.value=''; this.style.display='none'; cargarAuxiliares();">close</i>
            </div>

            @php
                // Mismo patron que /admin/equipos: el boton se resalta en rojo si
                // CUALQUIER filtro avanzado esta activo, incluyendo los checks de
                // documentacion (Propiedad/Certificado) y la ubicacion especifica.
                $advActive = request()->filled('marca')
                    || request()->filled('modelo')
                    || request()->filled('estado')
                    || request()->filled('capacidad')
                    || request()->filled('detalle_ubicacion')
                    || request()->boolean('con_propiedad')
                    || request()->boolean('con_certificado');
            @endphp
            <div data-aux-role="adv" style="position:relative;flex-shrink:0;">
                <button type="button" id="auxAdvBtn" title="Filtros Avanzados"
                        onclick="window.toggleAuxAdv(event)"
                        class="btn-primary-maquinaria"
                        style="height:45px;width:45px;min-width:45px;padding:0;display:flex;align-items:center;justify-content:center;background:{{ $advActive ? '#fee2e2' : 'white' }};border:1px solid {{ $advActive ? '#ef4444' : '#cbd5e0' }};color:{{ $advActive ? '#ef4444' : '#64748b' }};box-shadow:none;">
                    <i class="material-icons">filter_list</i>
                </button>
                <div id="auxAdvPanel" style="display:none;position:absolute;top:100%;right:0;width:300px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);margin-top:10px;padding:15px;z-index:500;overflow:visible;">
                    <h4 style="margin:0 0 15px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                        Filtros Avanzados
                        <span style="font-size:11px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;"
                              onclick="auxAdvClear('marca');auxAdvClear('modelo');auxAdvClear('capacidad');auxAdvClear('estado');auxAdvClear('detalle_ubicacion');auxAdvClear('confirmado'); var p=document.getElementById('aux_chk_propiedad'); if(p)p.checked=false; var c=document.getElementById('aux_chk_certificado'); if(c)c.checked=false; cargarAuxiliares();">Limpiar Todo</span>
                    </h4>
                    {{-- Filtros de a DOS por fila (Marca|Modelo, Capacidad|Estado). El
                         min-width:0 en los hijos (regla .aux-adv-grid>div) evita que se
                         desborden del panel de 300px. --}}
                    <div class="aux-adv-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;align-items:start;">

                        {{-- Filtro "Detalle" (DETALLE_UBICACION_ACTUAL) — solo
                             visible cuando el frente seleccionado es TIPO_FRENTE='ESPECIAL'
                             (patio, almacen, taller). Mismo patron que /admin/equipos:
                             el controller ya devuelve $availableUbicaciones y $frenteEspecial. --}}
                        <div id="aux_adv_ubic_wrapper" style="{{ isset($frenteEspecial) && $frenteEspecial ? '' : 'display:none;' }}">
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">
                                <i class="material-icons" style="font-size:13px;vertical-align:middle;color:#0067b1;">place</i>
                                Detalle{{ isset($frenteEspecial) && $frenteEspecial ? ' (' . mb_strtoupper($frenteEspecial->NOMBRE_FRENTE) . ')' : '' }}
                            </span>
                            <div style="position:relative;">
                                <input type="hidden" id="aux_val_detalle_ubicacion" name="detalle_ubicacion" value="{{ request('detalle_ubicacion') }}">
                                <div style="display:flex;align-items:center;background:{{ request('detalle_ubicacion') ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ request('detalle_ubicacion') ? '#0067b1' : '#cbd5e0' }};border-radius:6px;height:32px;" id="aux_box_detalle_ubicacion">
                                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;">search</i>
                                    <input type="text" id="aux_txt_detalle_ubicacion" placeholder="Seleccionar detalle..." value="{{ request('detalle_ubicacion') }}" autocomplete="off"
                                           style="flex:1;min-width:0;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;text-transform:uppercase;"
                                           oninput="auxAdvFilter('detalle_ubicacion',this.value)"
                                           onfocus="auxAdvOpen('detalle_ubicacion')"
                                           onblur="setTimeout(()=>auxAdvClose('detalle_ubicacion'),200)">
                                    <i class="material-icons" id="aux_clr_detalle_ubicacion" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('detalle_ubicacion') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('detalle_ubicacion');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_detalle_ubicacion" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    @if(isset($availableUbicaciones))
                                        @foreach($availableUbicaciones as $ubi)
                                            @if(trim($ubi) !== '')
                                                <div class="aux-adv-opt" data-val="{{ $ubi }}" onmousedown="event.preventDefault();auxAdvSelect('detalle_ubicacion','{{ addslashes(trim($ubi)) }}','{{ addslashes(trim($ubi)) }}');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;text-transform:uppercase;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">{{ $ubi }}</div>
                                            @endif
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Marca --}}
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">Marca</span>
                            <div style="position:relative;">
                                <input type="hidden" id="aux_val_marca" name="marca" value="{{ request('marca') }}">
                                <div style="display:flex;align-items:center;background:{{ request('marca') ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ request('marca') ? '#0067b1' : '#cbd5e0' }};border-radius:6px;height:32px;" id="aux_box_marca">
                                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;">search</i>
                                    <input type="text" id="aux_txt_marca" placeholder="Ej: Miller" value="{{ request('marca') }}" autocomplete="off"
                                           style="flex:1;min-width:0;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('marca',this.value)"
                                           onfocus="auxAdvOpen('marca')"
                                           onblur="setTimeout(()=>auxAdvClose('marca'),200)">
                                    <i class="material-icons" id="aux_clr_marca" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('marca') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('marca');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_marca" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    @foreach($availableMarcas as $m)
                                    <div class="aux-adv-opt" data-val="{{ $m }}" onmousedown="event.preventDefault();auxAdvSelect('marca','{{ $m }}','{{ addslashes($m) }}');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">{{ $m }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- Modelo --}}
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">Modelo</span>
                            <div style="position:relative;">
                                <input type="hidden" id="aux_val_modelo" name="modelo" value="{{ request('modelo') }}">
                                <div style="display:flex;align-items:center;background:{{ request('modelo') ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ request('modelo') ? '#0067b1' : '#cbd5e0' }};border-radius:6px;height:32px;" id="aux_box_modelo">
                                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;">search</i>
                                    <input type="text" id="aux_txt_modelo" placeholder="Ej: Bobcat 225" value="{{ request('modelo') }}" autocomplete="off"
                                           style="flex:1;min-width:0;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('modelo',this.value)"
                                           onfocus="auxAdvOpen('modelo')"
                                           onblur="setTimeout(()=>auxAdvClose('modelo'),200)">
                                    <i class="material-icons" id="aux_clr_modelo" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('modelo') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('modelo');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_modelo" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    @foreach($availableModelos as $mod)
                                    <div class="aux-adv-opt" data-val="{{ $mod }}" onmousedown="event.preventDefault();auxAdvSelect('modelo','{{ $mod }}','{{ addslashes($mod) }}');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">{{ $mod }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- Capacidad --}}
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">Capacidad</span>
                            <div style="position:relative;">
                                <input type="hidden" id="aux_val_capacidad" name="capacidad" value="{{ request('capacidad') }}">
                                <div style="display:flex;align-items:center;background:{{ request('capacidad') ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ request('capacidad') ? '#0067b1' : '#cbd5e0' }};border-radius:6px;height:32px;" id="aux_box_capacidad">
                                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;">search</i>
                                    <input type="text" id="aux_txt_capacidad" placeholder="Ej: 300A, 20 pies" value="{{ request('capacidad') }}" autocomplete="off"
                                           style="flex:1;min-width:0;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('capacidad',this.value)"
                                           onfocus="auxAdvOpen('capacidad')"
                                           onblur="setTimeout(()=>auxAdvClose('capacidad'),200)">
                                    <i class="material-icons" id="aux_clr_capacidad" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('capacidad') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('capacidad');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_capacidad" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    @foreach($availableCapacidades as $cap)
                                    <div class="aux-adv-opt" data-val="{{ $cap }}" onmousedown="event.preventDefault();auxAdvSelect('capacidad','{{ $cap }}','{{ addslashes($cap) }}');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">{{ $cap }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- Estado --}}
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">Estado</span>
                            <div style="position:relative;">
                                <input type="hidden" id="aux_val_estado" name="estado" value="{{ request('estado') }}">
                                <div style="display:flex;align-items:center;background:{{ request('estado') ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ request('estado') ? '#0067b1' : '#cbd5e0' }};border-radius:6px;height:32px;" id="aux_box_estado">
                                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;">flag</i>
                                    <input type="text" id="aux_txt_estado" placeholder="{{ request('estado') ? strtoupper($estados[request('estado')] ?? request('estado')) : 'Todos los estados' }}" value="" autocomplete="off"
                                           style="flex:1;min-width:0;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('estado',this.value)"
                                           onfocus="auxAdvOpen('estado')"
                                           onblur="setTimeout(()=>auxAdvClose('estado'),200)">
                                    <i class="material-icons" id="aux_clr_estado" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('estado') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('estado');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_estado" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    @foreach($estados as $k => $label)
                                    <div class="aux-adv-opt" data-val="{{ $k }}" onmousedown="event.preventDefault();auxAdvSelect('estado','{{ $k }}','{{ strtoupper($label) }}');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">{{ strtoupper($label) }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        {{-- Confirmación en sitio (espejo del filtro 'confirmado' de /admin/equipos).
                             SI = confirmado · NO = pendiente. El form se serializa con FormData,
                             así que el name="confirmado" llega solo a applyAuxiliarFilters(). --}}
                        @php $cfdReq = strtoupper((string) request('confirmado')); $cfdLbl = $cfdReq === 'SI' ? 'CONFIRMADO' : ($cfdReq === 'NO' ? 'PENDIENTE' : ''); @endphp
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">Confirmación en sitio</span>
                            <div style="position:relative;">
                                <input type="hidden" id="aux_val_confirmado" name="confirmado" value="{{ $cfdReq }}">
                                <div style="display:flex;align-items:center;background:{{ $cfdReq ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ $cfdReq ? '#0067b1' : '#cbd5e0' }};border-radius:6px;height:32px;" id="aux_box_confirmado">
                                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;">check_circle</i>
                                    <input type="text" id="aux_txt_confirmado" placeholder="{{ $cfdLbl ?: 'Todas' }}" value="" autocomplete="off"
                                           style="flex:1;min-width:0;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('confirmado',this.value)"
                                           onfocus="auxAdvOpen('confirmado')"
                                           onblur="setTimeout(()=>auxAdvClose('confirmado'),200)">
                                    <i class="material-icons" id="aux_clr_confirmado" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ $cfdReq ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('confirmado');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_confirmado" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    <div class="aux-adv-opt" data-val="SI" onmousedown="event.preventDefault();auxAdvSelect('confirmado','SI','CONFIRMADO');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">CONFIRMADO</div>
                                    <div class="aux-adv-opt" data-val="NO" onmousedown="event.preventDefault();auxAdvSelect('confirmado','NO','PENDIENTE');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">PENDIENTE</div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Documentation Filters (mismo patron que /admin/equipos) -->
                    <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #cbd5e1;">
                        <span style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 8px;">Documentación Cargada</span>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <label for="aux_chk_propiedad" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="aux_chk_propiedad" name="con_propiedad" value="1"
                                       {{ request('con_propiedad') ? 'checked' : '' }}
                                       onchange="cargarAuxiliares()"
                                       style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Propiedad
                            </label>

                            <label for="aux_chk_certificado" style="display: flex; align-items: center; font-size: 13px; color: #334155; cursor: pointer;">
                                <input type="checkbox" id="aux_chk_certificado" name="con_certificado" value="1"
                                       {{ request('con_certificado') ? 'checked' : '' }}
                                       onchange="cargarAuxiliares()"
                                       style="margin-right: 8px; accent-color: var(--maquinaria-blue);">
                                Certificado
                            </label>
                        </div>

                    </div>
                </div>
            </div>

            {{-- Acciones --}}
            <div data-aux-role="acciones" style="position:relative;flex-shrink:0;">
                <button type="button" id="auxAccionesBtn" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;border-radius:12px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;cursor:pointer;"
                        onclick="window.toggleAuxAcciones(event)">
                    <i class="material-icons" style="font-size:18px;">settings</i>
                    <span>Acciones</span>
                    <i class="material-icons" style="font-size:16px;">expand_more</i>
                </button>
                <div id="auxAccionesDropdown" style="display:none;position:absolute;top:calc(100% + 5px);right:0;min-width:240px;background:#e2e8f0;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 10px 20px -5px rgba(15,23,42,0.18);overflow:hidden;z-index:50;">
                    @php $canCreateAux = auth()->user() && auth()->user()->can('equipos.create'); @endphp
                    {{-- Al formulario UNIFICADO (/admin/equipos/create), que registra equipos y
                         auxiliares desde el mismo sitio. Apuntaba al formulario viejo de
                         solo-auxiliares, que es el que al guardar sacaba del modulo unificado. --}}
                    <a href="{{ $canCreateAux ? route('equipos.create') : '#' }}"
                       @if(!$canCreateAux) onclick="event.preventDefault(); if(window.showToast){window.showToast('No tienes permiso para crear equipos auxiliares.', 'warning');} document.getElementById('auxAccionesDropdown').style.display='none';" @endif
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;{{ $canCreateAux ? '' : 'cursor:not-allowed;' }}"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#fff7ed;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#f59e0b;">add_circle</i></div>
                        <span>Nuevo Equipo Auxiliar</span>
                    </a>
                    <a href="#" onclick="event.preventDefault(); document.getElementById('auxAccionesDropdown').style.display='none'; window.openAuxAnclajesModal();"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#e0f2fe;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0284c7;">link</i></div>
                        <span>Ver Anclajes</span>
                    </a>
                    <a href="#" onclick="event.preventDefault(); window.exportAuxiliaresXlsx(); document.getElementById('auxAccionesDropdown').style.display='none';"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#f1f5f9;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#64748b;">download</i></div>
                        <span>Exportación de Data</span>
                    </a>
                    <a href="{{ route('catalogo.index') }}"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;{{ auth()->user()?->can('super.admin') ? 'border-bottom:1px solid #f1f5f9;' : '' }}"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#eff6ff;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">menu_book</i></div>
                        <span>Catálogo por Modelo</span>
                    </a>

                    {{-- Eliminar Auxiliares Seleccionados — siempre visible. La
                         validacion del permiso (user.delete) la hace JS al click.
                         La eliminacion es soft-delete con auditoria de quien
                         borro y queda en la papelera de /admin/historial-documentos. --}}
                    <a href="#" onclick="event.preventDefault(); document.getElementById('auxAccionesDropdown').style.display='none'; window.bulkDeleteAuxiliaresSeleccionados();"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#fee2e2;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#dc2626;">delete_outline</i></div>
                        <span>Eliminar Seleccionados</span>
                    </a>
                </div>
            </div>
        </form>

        {{-- Stats compactas SOLO en movil (el sidebar se oculta <=900px) --}}
        <div class="equipos-mobile-stats">
            <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;opacity:0.75;margin-bottom:6px;display:flex;align-items:center;gap:5px;">
                <i class="material-icons" style="font-size:13px;">pie_chart</i>
                Consolidado de Auxiliares
            </div>
            <div style="display:flex;gap:8px;justify-content:space-between;">
                <div onclick="window.auxFilterByEstado('all')" style="flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 4px;border-radius:10px;background:rgba(255,255,255,0.15);box-shadow:0 2px 4px rgba(0,0,0,0.1);cursor:pointer;">
                    <span style="font-size:10px;font-weight:700;opacity:0.8;margin-bottom:2px;">TOTAL</span>
                    <span style="font-size:22px;font-weight:800;line-height:1;">{{ $stats['total'] ?? '—' }}</span>
                </div>
                <div onclick="window.auxFilterByEstado('OPERATIVO')" style="flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 4px;border-radius:10px;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);cursor:pointer;">
                    <span style="font-size:10px;font-weight:700;color:#86efac;margin-bottom:2px;"><i class="material-icons" style="font-size:11px;vertical-align:middle;">check_circle</i> OPER.</span>
                    <span style="color:white;font-size:22px;font-weight:800;line-height:1;">{{ $stats['operativos'] ?? '—' }}</span>
                </div>
                <div onclick="window.auxFilterByEstado('INOPERATIVO')" style="flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 4px;border-radius:10px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);cursor:pointer;">
                    <span style="font-size:10px;font-weight:700;color:#fca5a5;margin-bottom:2px;"><i class="material-icons" style="font-size:11px;vertical-align:middle;">cancel</i> INOP.</span>
                    <span style="color:white;font-size:22px;font-weight:800;line-height:1;">{{ $stats['inoperativos'] ?? '—' }}</span>
                </div>
            </div>
        </div>

        <div class="custom-scrollbar-container" style="overflow-x:auto; -webkit-overflow-scrolling:touch;">
            <table class="admin-table table-equipos-mobile" id="auxTable" style="width:100%; min-width:900px; border-collapse:separate; border-spacing:0 6px;">
                <thead>
                    <tr class="table-row-header">
                        <th class="table-header-custom" style="width: 150px;"></th>
                        <th class="table-header-custom" style="width: 22%;">TIPO</th>
                        <th class="table-header-custom" style="width: 15%;">MARCA / MODELO</th>
                        <th class="table-header-custom" style="width: 25%;">SERIAL</th>
                        <th class="table-header-custom" style="width: 110px;">ESTADO</th>
                        <th class="table-cell-center" style="width: 90px;"></th>
                    </tr>
                </thead>
                <tbody id="auxTableBody">
                    @include('admin.equipos_auxiliares.partials.table_rows')
                </tbody>
            </table>
        </div>

        {{-- Scroll infinito: el sentinel dispara IntersectionObserver para pedir el siguiente lote.
             Mismo patron que /admin/equipos (offset += 150). --}}
        <div id="auxLoadingMore" style="display:none; margin-top:14px; text-align:center; padding:20px; color:#64748b; font-size:13px;">
            <i class="material-icons" style="font-size:18px; vertical-align:middle; animation: spin 1s linear infinite;">sync</i>
            <span>Cargando más auxiliares…</span>
        </div>
        <div id="auxScrollSentinel" style="height:1px;"></div>

    </div>{{-- /admin-card (columna izq) --}}

    {{-- Columna der: Consolidado + Distribucion (sidebar sticky) --}}
    <div class="counter-sidebar" style="position: sticky; top: 20px; display: flex; flex-direction: column; gap: 8px;">

        {{-- Consolidado de Equipos Auxiliares --}}
        <div style="background: linear-gradient(135deg, #1a365d 0%, #2c5282 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
            <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">construction</i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.5px; opacity: 0.8; margin-bottom: 12px; display: flex; align-items: center; gap: 6px;">
                    <i class="material-icons" style="font-size: 14px;">pie_chart</i>
                    Consolidado
                </div>

                <div style="display: flex; align-items: center; gap: 8px;">
                    <div title="Cargar todos (limpia filtro de estado)" onclick="window.auxFilterByEstado('all')"
                         style="display: flex; flex-direction: column; align-items: center; background: rgba(255,255,255,0.15); padding: 8px 6px; border-radius: 10px; min-width: 65px; cursor: pointer; transition: transform 0.15s;"
                         onmouseover="this.style.transform='scale(1.03)'" onmouseout="this.style.transform='scale(1)'">
                        <span id="auxStatsTotal" style="font-size: 36px; font-weight: 800; line-height: 1;">{{ $stats['total'] ?? '—' }}</span>
                        <span style="font-size: 13px; opacity: 0.8; font-weight: 700; margin-top: 2px;">TOTAL</span>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; flex: 1;">
                        <div title="Filtrar solo Operativos" onclick="window.auxFilterByEstado('OPERATIVO')"
                             style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25); cursor: pointer; transition: transform 0.15s;"
                             onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                            <i class="material-icons" style="font-size: 18px; color: #22c55e; margin-bottom: 2px;">check_circle</i>
                            <strong id="auxStatsOperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['operativos'] ?? '—' }}</strong>
                            <span style="font-size: 11px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Operativos</span>
                        </div>
                        <div title="Filtrar solo Inoperativos" onclick="window.auxFilterByEstado('INOPERATIVO')"
                             style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); cursor: pointer; transition: transform 0.15s;"
                             onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                            <i class="material-icons" style="font-size: 18px; color: #ef4444; margin-bottom: 2px;">cancel</i>
                            <strong id="auxStatsInoperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['inoperativos'] ?? '—' }}</strong>
                            <span style="font-size: 11px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Inoperativos</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Card "Detalles" (DETALLE_UBICACION_ACTUAL) — visible solo cuando el
             frente filtrado es TIPO_FRENTE='ESPECIAL'. Mismo patron que
             /admin/equipos. cargarAuxiliares (AJAX) muestra/oculta segun
             showUbicaciones del response. --}}
        <div id="auxUbicacionesStatsCard"
             style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden; {{ isset($frenteEspecial) && $frenteEspecial ? '' : 'display: none;' }}">
            <div id="auxUbicacionesStatsContainer">
                @include('admin.equipos_auxiliares.partials.ubicaciones_stats')
            </div>
        </div>

        {{-- Distribucion por tipo --}}
        <div style="background: white; border-radius: 12px; padding: 15px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); overflow: hidden;">
            <div id="auxDistribucionContainer">
                @include('admin.equipos_auxiliares.partials.distribucion_stats')
            </div>
        </div>
    </div>

</div>{{-- /page-layout-grid --}}

@include('admin.equipos_auxiliares.partials._machinery')

{{-- Modales de Reporte de Falla (compartidos): "Nuevo Reporte" (al poner INOPERATIVO)
     y "Cerrar Reporte" (al cambiar el estado de un aux con reporte abierto). Los
     incluye la pagina (no el partial _machinery) para no duplicarlos al embeber. --}}
@include('admin.fallas.partials.create_modal')
@include('admin.fallas.partials.close_modal')

{{-- Integración con Reportes de Falla (modal compartido). --}}
<script>
    window.FALLA_MODAL_CFG = {
        urlSearch: '{{ route("fallas.searchActivos") }}',
        urlStore:  '{{ route("fallas.store") }}',
        urlBase:   '{{ url("admin/fallas") }}',
        onCreated: function () { if (window.handleFallaCreatedAux) window.handleFallaCreatedAux(); },
        onClosed:  function () { if (window.cargarAuxiliares) window.cargarAuxiliares(); }
    };
</script>
{{-- falla_create_modal.js se carga GLOBAL en el layout (SPA-safe). --}}
@endsection
