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
    /* Estilo unificado para opciones de filtros avanzados (igual al main) */
    .aux-adv-opt {
        padding: 8px 12px !important;
        font-size: 14px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        cursor: pointer;
        border-radius: 6px;
    }
    .aux-adv-opt[data-val=""] {
        font-size: 13px !important;
        color: #475569 !important;
        font-weight: 600 !important;
    }
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
        /* Frente y Tipo: cada uno una fila completa */
        #auxFiltersForm > div[data-aux-role="dropdown"] {
            flex: 1 0 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
        }
        /* Serial + boton Filtros Avanzados: misma fila */
        #auxFiltersForm > .search-wrapper {
            flex: 1 1 auto !important;
            min-width: 0 !important;
            max-width: none !important;
        }
        #auxFiltersForm > div[data-aux-role="adv"] {
            flex: 0 0 45px !important;
            width: 45px !important;
        }
        /* Contenedor del boton Acciones: fila propia full-width */
        #auxFiltersForm > div[data-aux-role="acciones"] {
            flex: 1 0 100% !important;
            display: flex !important;
        }
        #auxFiltersForm #auxAccionesBtn {
            flex: 1 !important;
            justify-content: center !important;
        }
        #auxAdvPanel {
            width: calc(100vw - 24px) !important;
            max-width: calc(100vw - 24px) !important;
            right: 0 !important;
            left: auto !important;
        }
    }
</style>

<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
    <div>
        <h1 class="page-title" style="margin-bottom: 2px;">
            <span class="page-title-line2" style="color: #000;">Equipos Auxiliares</span>
        </h1>
        <p class="aux-page-subtitle" style="margin: 0; font-size: 12px; color: #64748b; font-weight: 500; line-height: 1.3;">
            Máquinas de soldar, compresores, luminarias, plantas eléctricas, contenedores y otros.
        </p>
    </div>
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
            <div data-aux-role="dropdown" style="flex:1;min-width:180px;max-width:260px;position:relative;">
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
            <div data-aux-role="dropdown" style="flex:1;min-width:180px;max-width:260px;position:relative;">
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
            <div class="search-wrapper" style="flex:1;min-width:200px;max-width:260px;border:1px solid {{ request('search') ? '#0067b1' : '#cbd5e0' }};border-radius:12px;background:{{ request('search') ? '#e1effa' : '#fbfcfd' }};display:flex;align-items:center;height:45px;overflow:hidden;">
                <div style="padding:0 12px;display:flex;align-items:center;color:#64748b;"><i class="material-icons" style="font-size:18px;">search</i></div>
                {{-- Busqueda Serial: solo dispara consulta auto con 4+ caracteres o cuando se vacia
                     el campo (para limpiar resultados). Enter fuerza la busqueda inmediata. --}}
                <input type="text" id="auxSearchInput" name="search" value="{{ request('search') }}" placeholder="Filtrar Serial (min. 4 chars)..."
                       oninput="window._auxDebounce && clearTimeout(window._auxDebounce); const __v=this.value.trim(); if(__v.length===0||__v.length>=4){ window._auxDebounce = setTimeout(cargarAuxiliares, 300); }"
                       onkeydown="if(event.key==='Enter'){ event.preventDefault(); window._auxDebounce && clearTimeout(window._auxDebounce); cargarAuxiliares(); }"
                       style="flex:1;border:none;background:transparent;padding:12px 5px;font-size:13px;outline:none;min-width:0;" autocomplete="off">
                <i class="material-icons"
                   style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('search') ? 'block' : 'none' }};"
                   onclick="event.stopPropagation(); document.getElementById('auxSearchInput').value=''; cargarAuxiliares();">close</i>
            </div>

            @php
                $advActive = request()->filled('marca') || request()->filled('modelo') || request()->filled('estado') || request()->filled('capacidad');
            @endphp
            <div data-aux-role="adv" style="position:relative;flex-shrink:0;">
                <button type="button" id="auxAdvBtn" title="Filtros Avanzados"
                        onclick="const p=document.getElementById('auxAdvPanel'); p.style.display = (p.style.display==='none'||!p.style.display) ? 'block' : 'none'; event.stopPropagation();"
                        class="btn-primary-maquinaria"
                        style="height:45px;width:45px;min-width:45px;padding:0;display:flex;align-items:center;justify-content:center;background:{{ $advActive ? '#fee2e2' : 'white' }};border:1px solid {{ $advActive ? '#ef4444' : '#cbd5e0' }};color:{{ $advActive ? '#ef4444' : '#64748b' }};box-shadow:none;">
                    <i class="material-icons">filter_list</i>
                </button>
                <div id="auxAdvPanel" style="display:none;position:absolute;top:100%;right:0;width:300px;max-width:calc(100vw - 20px);background:#e2e8f0;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);margin-top:10px;padding:15px;z-index:500;overflow:visible;">
                    <h4 style="margin:0 0 15px 0;font-size:14px;font-weight:700;color:#334155;display:flex;justify-content:space-between;align-items:center;">
                        Filtros Avanzados
                        <span style="font-size:11px;color:#64748b;font-weight:400;text-decoration:underline;cursor:pointer;"
                              onclick="auxAdvClear('marca');auxAdvClear('modelo');auxAdvClear('capacidad');auxAdvClear('estado');cargarAuxiliares();">Limpiar Todo</span>
                    </h4>
                    <div style="display:flex;flex-direction:column;gap:10px;">

                        {{-- Marca --}}
                        <div>
                            <span style="display:block;font-size:12px;font-weight:600;color:#64748b;margin-bottom:4px;">Marca</span>
                            <div style="position:relative;">
                                <input type="hidden" id="aux_val_marca" name="marca" value="{{ request('marca') }}">
                                <div style="display:flex;align-items:center;background:{{ request('marca') ? '#e1effa' : '#fbfcfd' }};border:1px solid {{ request('marca') ? '#0067b1' : '#cbd5e0' }};border-radius:6px;height:32px;" id="aux_box_marca">
                                    <i class="material-icons" style="padding:0 8px;color:#64748b;font-size:18px;">search</i>
                                    <input type="text" id="aux_txt_marca" placeholder="Ej: Miller" value="{{ request('marca') }}" autocomplete="off"
                                           style="flex:1;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('marca',this.value)"
                                           onfocus="auxAdvOpen('marca')"
                                           onblur="setTimeout(()=>auxAdvClose('marca'),200)">
                                    <i class="material-icons" id="aux_clr_marca" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('marca') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('marca');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_marca" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    <div class="aux-adv-opt" data-val="" onmousedown="event.preventDefault();auxAdvSelect('marca','','Ej: Miller');cargarAuxiliares();" style="padding:10px 15px;font-size:13px;color:#64748b;cursor:pointer;font-weight:600;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">TODAS LAS MARCAS</div>
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
                                           style="flex:1;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('modelo',this.value)"
                                           onfocus="auxAdvOpen('modelo')"
                                           onblur="setTimeout(()=>auxAdvClose('modelo'),200)">
                                    <i class="material-icons" id="aux_clr_modelo" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('modelo') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('modelo');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_modelo" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    <div class="aux-adv-opt" data-val="" onmousedown="event.preventDefault();auxAdvSelect('modelo','','Ej: Bobcat 225');cargarAuxiliares();" style="padding:10px 15px;font-size:13px;color:#64748b;cursor:pointer;font-weight:600;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">TODOS LOS MODELOS</div>
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
                                           style="flex:1;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('capacidad',this.value)"
                                           onfocus="auxAdvOpen('capacidad')"
                                           onblur="setTimeout(()=>auxAdvClose('capacidad'),200)">
                                    <i class="material-icons" id="aux_clr_capacidad" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('capacidad') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('capacidad');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_capacidad" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    <div class="aux-adv-opt" data-val="" onmousedown="event.preventDefault();auxAdvSelect('capacidad','','Ej: 300A, 20 pies');cargarAuxiliares();" style="padding:10px 15px;font-size:13px;color:#64748b;cursor:pointer;font-weight:600;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">TODAS LAS CAPACIDADES</div>
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
                                           style="flex:1;border:none;background:transparent;padding:6px 5px;font-size:13px;outline:none;color:#334155;"
                                           oninput="auxAdvFilter('estado',this.value)"
                                           onfocus="auxAdvOpen('estado')"
                                           onblur="setTimeout(()=>auxAdvClose('estado'),200)">
                                    <i class="material-icons" id="aux_clr_estado" style="padding:0 8px;color:#64748b;font-size:18px;cursor:pointer;display:{{ request('estado') ? 'block' : 'none' }};"
                                       onmousedown="event.preventDefault();auxAdvClear('estado');cargarAuxiliares();">close</i>
                                </div>
                                <div id="aux_list_estado" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:white;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.1);max-height:160px;overflow-y:auto;margin-top:4px;padding:5px;">
                                    <div class="aux-adv-opt" data-val="" onmousedown="event.preventDefault();auxAdvSelect('estado','','Todos los estados');cargarAuxiliares();" style="padding:10px 15px;font-size:13px;color:#64748b;cursor:pointer;font-weight:600;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">TODOS LOS ESTADOS</div>
                                    @foreach($estados as $k => $label)
                                    <div class="aux-adv-opt" data-val="{{ $k }}" onmousedown="event.preventDefault();auxAdvSelect('estado','{{ $k }}','{{ strtoupper($label) }}');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;" onmouseover="this.style.background='#f0f4f8'" onmouseout="this.style.background='white'">{{ strtoupper($label) }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            {{-- Acciones --}}
            <div data-aux-role="acciones" style="position:relative;flex-shrink:0;">
                <button type="button" id="auxAccionesBtn" class="btn-primary-maquinaria"
                        style="height:45px;padding:0 16px;border-radius:12px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:700;cursor:pointer;"
                        onclick="const d=document.getElementById('auxAccionesDropdown'); d.style.display = d.style.display==='none'||!d.style.display ? 'block' : 'none'; event.stopPropagation();">
                    <i class="material-icons" style="font-size:18px;">settings</i>
                    <span>Acciones</span>
                    <i class="material-icons" style="font-size:16px;">expand_more</i>
                </button>
                <div id="auxAccionesDropdown" style="display:none;position:absolute;top:calc(100% + 5px);right:0;min-width:240px;background:#e2e8f0;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 10px 20px -5px rgba(15,23,42,0.18);overflow:hidden;z-index:50;">
                    @php $canCreateAux = auth()->user() && auth()->user()->can('equipos.create'); @endphp
                    <a href="{{ $canCreateAux ? route('equipos-auxiliares.create') : '#' }}"
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
                    <a href="{{ route('equipos-auxiliares.catalogo') }}"
                       onclick="document.getElementById('auxAccionesDropdown').style.display='none';"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;{{ auth()->user()?->can('super.admin') ? 'border-bottom:1px solid #f1f5f9;' : '' }}"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#eff6ff;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">menu_book</i></div>
                        <span>Catálogo por Modelo</span>
                    </a>

                    @can('super.admin')
                    {{-- Eliminar Auxiliares Seleccionados (soft-delete con auditoria
                         de quien borro). Visible solo para super.admin. --}}
                    <a href="#" onclick="event.preventDefault(); document.getElementById('auxAccionesDropdown').style.display='none'; window.bulkDeleteAuxiliaresSeleccionados();"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#fee2e2;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#dc2626;">delete_outline</i></div>
                        <span>Eliminar Seleccionados</span>
                    </a>
                    @endcan
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
                    <span style="font-size:22px;font-weight:800;line-height:1;">{{ $stats['total'] }}</span>
                </div>
                <div onclick="window.auxFilterByEstado('OPERATIVO')" style="flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 4px;border-radius:10px;background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.3);cursor:pointer;">
                    <span style="font-size:10px;font-weight:700;color:#86efac;margin-bottom:2px;"><i class="material-icons" style="font-size:11px;vertical-align:middle;">check_circle</i> OPER.</span>
                    <span style="color:white;font-size:22px;font-weight:800;line-height:1;">{{ $stats['operativos'] }}</span>
                </div>
                <div onclick="window.auxFilterByEstado('INOPERATIVO')" style="flex:1;display:flex;flex-direction:column;align-items:center;padding:8px 4px;border-radius:10px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.3);cursor:pointer;">
                    <span style="font-size:10px;font-weight:700;color:#fca5a5;margin-bottom:2px;"><i class="material-icons" style="font-size:11px;vertical-align:middle;">cancel</i> INOP.</span>
                    <span style="color:white;font-size:22px;font-weight:800;line-height:1;">{{ $stats['inoperativos'] }}</span>
                </div>
            </div>
        </div>

        <div class="custom-scrollbar-container" style="overflow-x:auto;">
            <table class="admin-table table-equipos-mobile" id="auxTable" style="width:100%;">
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

        <div id="auxPagination" style="margin-top:14px;">
            {{ $auxiliares->links('vendor.pagination.custom-sliding') }}
        </div>

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
                        <span id="auxStatsTotal" style="font-size: 36px; font-weight: 800; line-height: 1;">{{ $stats['total'] }}</span>
                        <span style="font-size: 13px; opacity: 0.8; font-weight: 700; margin-top: 2px;">TOTAL</span>
                    </div>

                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px; flex: 1;">
                        <div title="Filtrar solo Operativos" onclick="window.auxFilterByEstado('OPERATIVO')"
                             style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(34, 197, 94, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(34, 197, 94, 0.25); cursor: pointer; transition: transform 0.15s;"
                             onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                            <i class="material-icons" style="font-size: 18px; color: #22c55e; margin-bottom: 2px;">check_circle</i>
                            <strong id="auxStatsOperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['operativos'] }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Operativos</span>
                        </div>
                        <div title="Filtrar solo Inoperativos" onclick="window.auxFilterByEstado('INOPERATIVO')"
                             style="display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(239, 68, 68, 0.15); padding: 6px 2px; border-radius: 8px; border: 1px solid rgba(239, 68, 68, 0.25); cursor: pointer; transition: transform 0.15s;"
                             onmouseover="this.style.transform='scale(1.04)'" onmouseout="this.style.transform='scale(1)'">
                            <i class="material-icons" style="font-size: 18px; color: #ef4444; margin-bottom: 2px;">cancel</i>
                            <strong id="auxStatsInoperativos" style="font-weight: 800; font-size: 16px; color: white;">{{ $stats['inoperativos'] }}</strong>
                            <span style="font-size: 8px; letter-spacing: -0.2px; opacity: 0.9; font-weight: 700; text-transform: uppercase;">Inoperativos</span>
                        </div>
                    </div>
                </div>
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

@can('equipos.edit')
{{-- ═══════════════════════════════════════════════════════════
     BARRA FLOTANTE DE SELECCION MASIVA (estilo /admin/equipos)
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxBulkBar" class="selection-floating-bar">
    <div class="selection-counter">
        <div style="background: rgba(255,255,255,0.1); padding: 5px; border-radius: 50%; display: flex;">
            <i class="material-icons" style="font-size: 18px; color: white;">functions</i>
        </div>
        <span id="auxBulkCount">0</span>
    </div>
    <div style="width: 1px; height: 24px; background: rgba(255,255,255,0.2);"></div>
    <div style="display: flex; gap: 10px;">
        <button type="button" onclick="window.auxClearSelection()" class="btn-bulk-clear"
                onmouseover="this.style.color='white'" onmouseout="this.style.color='#94a3b8'">
            <span class="desktop-text">Limpiar</span>
        </button>
        @can('equipos.assign')
        <button type="button" onclick="window.openAuxAnclarBulkModal()" class="btn-bulk-action" style="background: #10b981;">
            <i class="material-icons" style="font-size: 18px;">anchor</i>
            <span class="desktop-text">Anclar</span>
        </button>
        @endcan
        @can('equipos.assign')
        <button type="button" onclick="window.openAuxMovilizarModal()" class="btn-bulk-action">
            <i class="material-icons" style="font-size: 18px;">local_shipping</i>
            <span class="desktop-text">Asignar</span>
        </button>
        @endcan
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════
     MODAL MOVILIZACION MASIVA (pick frente destino)
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxMovilizarModal" class="modal-overlay" style="display:none;"
     onclick="if(event.target===this) window.closeAuxMovilizarModal()">
    <div class="modal-content"
         style="width: 90%; max-width: 480px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: white; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">
        <div style="background: var(--maquinaria-dark-blue); color: white; padding: 14px 20px; display: flex; justify-content: space-between; align-items: center;">
            <h2 style="margin:0; font-size:16px; font-weight:700; display:flex; align-items:center; gap:8px;">
                <i class="material-icons">local_shipping</i>
                Movilización de Auxiliares
            </h2>
            <button type="button" onclick="window.closeAuxMovilizarModal()"
                    style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 28px; height: 28px; display: flex; align-items: center; justify-content: center;">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>
        <div style="padding: 20px;">
            <div id="auxMovilizarSummary" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:12px; color:#475569;">
                {{-- poblado via JS --}}
            </div>

            <label for="auxMovilizarFrente" style="display:block; font-weight:700; margin-bottom:6px; color:var(--maquinaria-dark-blue); font-size:13px;">
                <i class="material-icons" style="font-size:14px; vertical-align:middle;">place</i>
                Frente de Destino <span style="color:var(--maquinaria-red);">*</span>
            </label>
            <select id="auxMovilizarFrente" class="form-input-custom" style="width:100%;">
                <option value="">— Seleccione un frente —</option>
                @foreach($frentes as $f)
                    <option value="{{ $f->ID_FRENTE }}">{{ $f->NOMBRE_FRENTE }}</option>
                @endforeach
            </select>

            <div style="display:flex; gap:10px; margin-top:18px; justify-content:flex-end;">
                <button type="button" onclick="window.closeAuxMovilizarModal()" class="btn-primary-maquinaria btn-secondary">
                    Cancelar
                </button>
                <button type="button" onclick="window.auxSubmitMovilizar()" class="btn-primary-maquinaria">
                    <i class="material-icons" style="font-size:16px;">send</i>
                    Confirmar
                </button>
            </div>
        </div>
    </div>
</div>
@endcan

{{-- ═══════════════════════════════════════════════════════════
     MODAL DETALLES DE EQUIPO AUXILIAR
     Mismo estilo que /admin/equipos (modal-overlay + modal-content +
     header azul oscuro + accordion details). Solo muestra campos
     que NO estan en la tabla.
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxDetailsModal" class="modal-overlay"
     onclick="if(event.target===this) window.closeAuxDetailsModal()">
    <div class="modal-content"
        style="width: 90%; max-width: 400px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #f8fafc; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">

        {{-- HEADER --}}
        <div style="background: var(--maquinaria-dark-blue); color: white;">
            <div style="padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0;">
                    <h2 id="auxDetailsTitle" style="margin: 0; font-size: 17px; font-weight: 700; word-break: break-word; line-height: 1.2;">—</h2>
                    <p id="auxDetailsSubtitle" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px; word-break: break-word;">—</p>
                </div>
                <div style="display: flex; gap: 6px; flex-shrink: 0;">
                    @can('equipos.edit')
                        <button type="button" id="auxDetailsEditBtn" title="Editar datos"
                            style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                            onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                            onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                            <i class="material-icons" style="font-size: 17px;">edit</i>
                        </button>
                    @endcan
                    <button type="button" onclick="window.closeAuxDetailsModal()"
                        style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        <i class="material-icons" style="font-size: 18px;">close</i>
                    </button>
                </div>
            </div>
        </div>

        {{-- BODY --}}
        <div class="modal-body-scroll" style="padding: 25px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div id="auxDetailsBody" style="display: flex; flex-direction: column; gap: 15px;">
                {{-- Contenido inyectado via JS (renderAuxDetailsModal) --}}
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    // ── Modal de detalles ──
    window.openAuxDetailsModal = function (btn, e) {
        if (e) e.stopPropagation();
        const id = btn.dataset.auxId;
        if (!id) {
            console.warn('openAuxDetailsModal: boton sin data-aux-id');
            return;
        }
        const modal = document.getElementById('auxDetailsModal');
        const body  = document.getElementById('auxDetailsBody');
        if (!modal || !body) {
            console.warn('openAuxDetailsModal: modal/body no encontrado en DOM');
            return;
        }

        // Data pre-cargada en window.auxDetailsMap por el controller (seed
        // inicial) y por cargarAuxiliares (AJAX de paginacion/filtro). El modal
        // abre INSTANTANEO sin fetch ni preloader — todos los datos del
        // auxiliar visible ya estan en memoria.
        const map = window.auxDetailsMap || {};
        const data = map[id] || map[String(id)];
        if (data) {
            window.renderAuxDetailsModal(data);
            modal.style.display = '';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
            return;
        }

        // Fallback de seguridad: si por alguna razon el ID no esta en el map
        // (cache stale, navegacion SPA con datos parciales), hace fetch con
        // preloader global y SIN spinner interno en el modal.
        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch('/admin/equipos-auxiliares/' + id + '/details', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(d => {
            (window.auxDetailsMap = window.auxDetailsMap || {})[id] = d;
            window.renderAuxDetailsModal(d);
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            modal.style.display = '';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        })
        .catch(err => {
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            if (typeof window.showToast === 'function') {
                window.showToast('Error al cargar detalles. ' + (err.message || ''), 'error');
            }
        });
    };

    window.renderAuxDetailsModal = function (d) {
        // Title + subtitle en el header
        const title = document.getElementById('auxDetailsTitle');
        const sub   = document.getElementById('auxDetailsSubtitle');
        if (title) title.textContent = (d.tipo_label || d.tipo || 'Auxiliar');
        if (sub)   sub.textContent   = ((d.marca || '') + ' ' + (d.modelo || '')).trim() || '—';

        // Enlazar edit en el boton del header (usa SPA navigateTo si esta disponible)
        const editBtn = document.getElementById('auxDetailsEditBtn');
        if (editBtn) editBtn.onclick = () => {
            window.closeAuxDetailsModal();
            if (typeof window.navigateTo === 'function') {
                window.navigateTo(d.edit_url);
            } else {
                // Fallback: click en un <a> para que el interceptor SPA global lo tome
                const a = document.createElement('a');
                a.href = d.edit_url;
                document.body.appendChild(a);
                a.click();
                a.remove();
            }
        };

        // Helper: fila de detalle con label + valor alineados
        const row = (label, value) => `
            <div class="detail-row-basic" style="display:flex; align-items:flex-start; justify-content:space-between; gap:8px; padding:6px 0; border-bottom:1px dashed #f1f5f9;">
                <span style="color:#64748b; font-size:12px; white-space:nowrap;">${label}</span>
                <span style="color:#333; font-size:13px; text-align:right; word-wrap:break-word; line-height:1.3; flex:1; max-width:65%;">${value || '—'}</span>
            </div>`;

        // Helper: seccion accordion (name="aux_details_accordion" -> solo una
        // abierta a la vez; al abrir otra, la actual se cierra automaticamente)
        const section = (title, icon, content, open = false) => `
            <details ${open ? 'open' : ''} name="aux_details_accordion" style="background:white; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden;">
                <summary style="padding:15px 20px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:10px; background:#f8fafc; list-style:none; cursor:pointer;">
                    <i class="material-icons" style="font-size:20px; color:#64748b;">${icon}</i>
                    <span>${title}</span>
                </summary>
                <div style="padding:12px 18px; border-top:1px solid #e2e8f0; display:flex; flex-direction:column; gap:2px;">
                    ${content}
                </div>
            </details>`;


        // Boton PDF (idem form_fields del modulo equipos):
        // - Si hay PDF: gradiente azul circular 30x30 con icono description (Ver).
        // - Si NO hay PDF y tiene permiso user.edit: dashed circular 30x30 con cloud_upload (Subir).
        // - Si NO hay PDF y NO hay permiso: span gris "No cargado".
        const pdfBtn = (url, docType) => {
            if (url) {
                // Abre la ventana de PDF preview interna (definida en estructura_base.blade.php).
                // Pasamos equipoId=null para que el panel de metadata muestre el mensaje
                // generico (no aplica a auxiliares — solo se usa el visor del PDF).
                const labelHr = docType === 'propiedad' ? 'Doc. Propiedad' : 'Certificado';
                const safeUrl = url.replace(/'/g, "\\'");
                // uploadUrl: endpoint propio de aux para que el boton de
                // subir/reemplazar dentro del visor SI funcione en este modulo.
                const uploadUrl = '/admin/equipos-auxiliares/' + d.id + '/upload-doc';
                // Para certificado, despues de abrir el visor inyectamos un editor
                // de fecha de vencimiento (no aplica a propiedad).
                const fechaActual = (docType === 'certificado' && d.fecha_vencimiento_cert) ? d.fecha_vencimiento_cert : '';
                const onclickHandler = `window.openPdfPreview('${safeUrl}','${docType}','${labelHr}',${d.id},'${uploadUrl}',true);` +
                    (docType === 'certificado' && d.can_upload_pdf ? `window.auxInjectCertExpiryEditor(${d.id},'${fechaActual}');` : '');
                return `<button type="button" title="Ver PDF" onclick="${onclickHandler}" style="display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:#0067b1; box-shadow:0 2px 6px rgba(0,103,177,0.35); border:none; cursor:pointer; flex-shrink:0;"><i class="material-icons" style="font-size:17px; color:white;">description</i></button>`;
            }
            if (d.can_upload_pdf && docType) {
                return `<label title="Subir PDF" style="display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:#fff; border:1.5px dashed #cbd5e1; color:#0067b1; cursor:pointer; flex-shrink:0;"><i class="material-icons" style="font-size:16px;">cloud_upload</i><input type="file" accept="application/pdf" style="display:none;" onchange="window.auxUploadDoc(${d.id}, '${docType}', this)"></label>`;
            }
            return '<span style="color:#94a3b8; font-size:12px;">No cargado</span>';
        };

        // Fila Doc. Propiedad: label + boton (sin fecha)
        const rowPropiedad = `
            <div class="detail-row-basic" style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:6px 0; border-bottom:1px dashed #f1f5f9;">
                <span style="color:#64748b; font-size:12px; white-space:nowrap;">Doc. Propiedad</span>
                ${pdfBtn(d.link_doc_propiedad, 'propiedad')}
            </div>`;

        // Fila Certificado: label + fecha de vencimiento + boton (todo en linea)
        let fechaInline = '<span style="color:#94a3b8; font-size:12px;">Sin fecha</span>';
        if (d.fecha_vencimiento_cert) {
            const venc = new Date(d.fecha_vencimiento_cert);
            const hoy = new Date(); hoy.setHours(0,0,0,0);
            const diff = Math.floor((venc - hoy) / (1000*60*60*24));
            const txt  = d.fecha_vencimiento_cert;
            let bg='#f0fdf4', co='#16a34a', extra='';
            if (diff < 0)       { bg='#fef2f2'; co='#dc2626'; extra=' (VENCIDO)'; }
            else if (diff < 30) { bg='#fffbeb'; co='#d97706'; extra=' ('+diff+' días)'; }
            fechaInline = `<span style="background:${bg}; color:${co}; padding:3px 8px; border-radius:6px; font-weight:700; font-size:12px; white-space:nowrap;">${txt}${extra}</span>`;
        }
        const rowCertificado = `
            <div class="detail-row-basic" style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:6px 0; border-bottom:1px dashed #f1f5f9;">
                <span style="color:#64748b; font-size:12px; white-space:nowrap;">Certificado</span>
                <div style="display:inline-flex; align-items:center; gap:8px;">
                    ${fechaInline}
                    ${pdfBtn(d.link_certificado, 'certificado')}
                </div>
            </div>`;

        // Tarjeta del equipo vinculado (host) - estilo "etiqueta" del modal de anclajes /admin/equipos
        let hostCard;
        if (d.host_id) {
            const idPrincipal = d.host_placa || d.host_serial_chasis || ('#' + d.host_id);
            const tipoUpper   = (d.host_tipo || 'Sin Tipo').toUpperCase();
            const marca       = d.host_marca || '';
            const frente      = d.host_frente || '';
            const fotoThumb = d.host_foto
                ? `<img src="${d.host_foto}" alt="" style="width:48px;height:40px;object-fit:contain;border-radius:6px;background:#fff;border:1px solid #e2e8f0;flex-shrink:0;" onerror="this.outerHTML='<div style=&quot;width:48px;height:40px;border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;flex-shrink:0;&quot;><i class=&quot;material-icons&quot; style=&quot;color:#cbd5e1;font-size:20px;&quot;>directions_car</i></div>'">`
                : `<div style="width:48px;height:40px;border-radius:6px;background:#fff;display:flex;align-items:center;justify-content:center;border:1px solid #e2e8f0;flex-shrink:0;"><i class="material-icons" style="color:#cbd5e1;font-size:20px;">directions_car</i></div>`;
            // Tipo y marca van en la MISMA linea (separados por punto medio)
            const tipoMarcaLine = marca
                ? `${tipoUpper} <span style="color:#cbd5e1;font-weight:600;">·</span> ${marca.toUpperCase()}`
                : tipoUpper;
            hostCard = `
                <div style="display:flex;align-items:center;gap:10px;background:#f8fafc;padding:8px 10px;border-radius:8px;border:1px solid #e2e8f0;">
                    ${fotoThumb}
                    <div style="display:flex;flex-direction:column;flex:1;min-width:0;gap:2px;">
                        <span style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${tipoMarcaLine}</span>
                        <span style="font-size:14px;font-weight:800;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25;">${idPrincipal}</span>
                        ${frente ? `<span style="font-size:11px;color:#059669;font-weight:600;display:inline-flex;align-items:center;gap:3px;margin-top:1px;"><i class="material-icons" style="font-size:13px;">place</i>${frente}</span>` : `<span style="font-size:11px;color:#94a3b8;font-style:italic;display:inline-flex;align-items:center;gap:3px;margin-top:1px;"><i class="material-icons" style="font-size:13px;">location_off</i>Sin frente</span>`}
                    </div>
                </div>`;
        } else {
            hostCard = '<div style="text-align:center;padding:12px;color:#94a3b8;font-size:12px;font-style:italic;">Sin equipo vinculado.</div>';
        }

        // IMPORTANTE: solo campos NO presentes en la tabla del index.
        // En la tabla ya se ven: frente, foto, tipo, marca/modelo, serial, capacidad, estado.
        // Aqui mostramos: codigo interno, año, observaciones, equipo vinculado,
        // documentacion (propiedad + certificado + vencimiento) y auditoria.
        const body = document.getElementById('auxDetailsBody');
        body.innerHTML = `
            ${section('Documentación Legal y Soportes', 'description',
                rowPropiedad + rowCertificado
            )}

            ${section('Información Adicional', 'info',
                row('Código Interno',       d.codigo_interno ? '#' + d.codigo_interno : '—') +
                row('Año',                  d.anio) +
                row('Observaciones',        d.observaciones)
            )}

            ${section('Vinculación', 'link', hostCard)}
        `;
    };

    window.closeAuxDetailsModal = function () {
        const modal = document.getElementById('auxDetailsModal');
        if (modal) {
            modal.classList.remove('active');
            modal.style.display = '';
        }
        document.body.style.overflow = '';
    };

    // Cerrar con Escape
    if (!window._auxDetailsEscBound) {
        window._auxDetailsEscBound = true;
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') window.closeAuxDetailsModal();
        });
    }

})();
</script>

<script>
(function () {
    // Carga AJAX — reemplaza tabla + paginacion + stats + distribucion
    window.cargarAuxiliares = function () {
        const form   = document.getElementById('auxFiltersForm');
        const params = new URLSearchParams(new FormData(form));
        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch('{{ route("equipos-auxiliares.index") }}?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('auxTableBody').innerHTML = data.html;
            document.getElementById('auxPagination').innerHTML = data.pagination;
            // Refrescar el mapa de detalles para que los nuevos auxiliares
            // visibles tras paginacion/filtro abran el modal del ojo instant.
            if (data.auxDetailsMap) {
                window.auxDetailsMap = Object.assign(window.auxDetailsMap || {}, data.auxDetailsMap);
            }
            if (data.stats) {
                const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? 0; };
                set('auxStatsTotal',       data.stats.total);
                set('auxStatsOperativos',  data.stats.operativos);
                set('auxStatsInoperativos',data.stats.inoperativos);
            }
            if (data.distribucion) renderDistribucion(data.distribucion);
            // Restaurar checks seleccionados tras rerender del body
            if (typeof window.auxRestoreSelection === 'function') window.auxRestoreSelection();
        })
        .catch(e => console.error('auxiliares load:', e))
        .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
    };

    function renderDistribucion(rows) {
        const cont = document.getElementById('auxDistribucionContainer');
        if (!cont) return;
        if (!rows || !rows.length) {
            cont.innerHTML = '<h4 style="margin:0 0 12px 0;font-size:12px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;">Distribución</h4><p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">Sin datos para mostrar.</p>';
            return;
        }
        const total = rows.reduce((a,r) => a + parseInt(r.total,10), 0);
        const TIPOS = @json($tipos);
        let html = '<h4 style="margin:0 0 12px 0;font-size:12px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;"><i class="material-icons" style="font-size:18px;color:#3b82f6;">pie_chart</i>Distribución</h4>';
        html += '<ul style="list-style:none;padding:0;margin:0;max-height:50vh;overflow-y:auto;display:flex;flex-direction:column;gap:4px;">';
        rows.forEach(r => {
            const pct = total > 0 ? (parseInt(r.total,10) / total) * 100 : 0;
            const label = (TIPOS[r.TIPO] || r.TIPO).toUpperCase();
            html += '<li onclick="window.auxFilterByTipo(\''+r.TIPO+'\')" style="padding:4px 6px;border-bottom:1px dashed #f1f5f9;cursor:pointer;border-radius:6px;transition:background 0.15s;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'transparent\'"><div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;"><span style="color:#334155;font-size:12.5px;font-weight:600;line-height:1.25;flex:1;text-transform:uppercase;">'+label+'</span><span style="font-weight:700;color:#1e293b;font-size:12.5px;background:#f1f5f9;padding:2px 8px;border-radius:4px;">'+r.total+'</span></div><div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;"><div style="width:'+pct+'%;height:100%;background:linear-gradient(90deg,#3b82f6 0%,#2563eb 100%);"></div></div></li>';
        });
        html += '</ul>';
        cont.innerHTML = html;
    }

    // Helpers para filtrar desde Consolidado + Distribucion (clicks).
    window.auxFilterByTipo = function (tipo) {
        const tiposMap = @json($tipos);
        const label = tiposMap[tipo] || tipo;
        selectOption('auxTipoFilterSelect', tipo, label);
        cargarAuxiliares();
    };
    window.auxFilterByEstado = function (estado) {
        const val = (estado === 'all') ? '' : estado;
        window.auxAdvSelect('estado', val, val ? val : 'Todos los estados');
        cargarAuxiliares();
    };

    // ═══════════════════════════════════════════════════════════
    // SELECCION MASIVA + MOVILIZACION (patron /admin/equipos — row click)
    // Click en cualquier parte de la fila alterna selecto/deselecto.
    // Guarda { id -> { id, codigo, frente } } en window._auxSelectedMap.
    // ═══════════════════════════════════════════════════════════
    window._auxSelectedMap = window._auxSelectedMap || {};

    window.auxToggleRow = function (tr) {
        if (!tr || !tr.dataset.auxId) return;
        const id = tr.dataset.auxId;
        if (id in window._auxSelectedMap) {
            delete window._auxSelectedMap[id];
            tr.classList.remove('selected-row-maquinaria');
        } else {
            window._auxSelectedMap[id] = {
                id: id,
                codigo: tr.dataset.codigo || ('#' + id),
                frente: tr.dataset.frente || ''
            };
            tr.classList.add('selected-row-maquinaria');
        }
        window.auxRefreshBulkBar();
    };

    window.auxRefreshBulkBar = function () {
        const bar = document.getElementById('auxBulkBar');
        const count = document.getElementById('auxBulkCount');
        if (!bar) return;
        const n = Object.keys(window._auxSelectedMap).length;
        if (count) count.textContent = String(n);
        // La barra usa la clase .active para animar entrada (CSS .selection-floating-bar
        // controla opacity/transform; display NO la oculta por si solo).
        if (n > 0) bar.classList.add('active');
        else       bar.classList.remove('active');
    };

    window.auxClearSelection = function () {
        window._auxSelectedMap = {};
        document.querySelectorAll('#auxTableBody tr.selected-row-maquinaria').forEach(tr => {
            tr.classList.remove('selected-row-maquinaria');
        });
        window.auxRefreshBulkBar();
    };

    // Restaurar highlight tras AJAX refresh de la tabla
    window.auxRestoreSelection = function () {
        document.querySelectorAll('#auxTableBody tr[data-aux-id]').forEach(tr => {
            if (tr.dataset.auxId in window._auxSelectedMap) {
                tr.classList.add('selected-row-maquinaria');
            }
        });
        window.auxRefreshBulkBar();
    };

    // Delegacion de click en filas (solo con can:equipos.edit via clase)
    if (!window._auxRowClickBound) {
        window._auxRowClickBound = true;
        document.addEventListener('click', function (e) {
            const tr = e.target.closest('#auxTableBody tr.aux-row-clickable');
            if (!tr) return;
            // Ignorar clicks en elementos interactivos o en la celda de acciones
            // (asi el click sobre el boton del ojo NO selecciona la fila aunque
            // caiga sobre el padding del TD).
            if (e.target.closest('button, a, input, .aux-status-trigger, .material-icons, .btn-details-mini, .aux-action-cell')) return;
            window.auxToggleRow(tr);
        });
    }

    window.openAuxMovilizarModal = function () {
        const ids = Object.keys(window._auxSelectedMap);
        if (ids.length === 0) return;
        const modal = document.getElementById('auxMovilizarModal');
        const sum   = document.getElementById('auxMovilizarSummary');
        if (!modal) return;
        const items = ids.map(k => window._auxSelectedMap[k].codigo);
        if (sum) sum.innerHTML = '<strong>' + ids.length + '</strong> equipo(s) a movilizar: <span style="color:#334155;">' + items.slice(0,6).join(', ') + (items.length > 6 ? ', +' + (items.length - 6) + ' más' : '') + '</span>';
        document.getElementById('auxMovilizarFrente').value = '';
        // Usar la clase .active (CSS global maneja display:flex + opacity:1).
        // El display:none inline del HTML necesita limpiarse para que .active
        // pueda tomar control de la visibilidad.
        modal.style.display = '';
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    window.closeAuxMovilizarModal = function () {
        const modal = document.getElementById('auxMovilizarModal');
        if (modal) {
            modal.classList.remove('active');
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
    };

    window.auxSubmitMovilizar = function () {
        const frenteId = document.getElementById('auxMovilizarFrente').value;
        if (!frenteId) {
            if (window.showToast) window.showToast('Selecciona un frente destino.', 'warning');
            return;
        }
        const ids = Object.keys(window._auxSelectedMap).map(x => parseInt(x, 10));
        if (!ids.length) { window.closeAuxMovilizarModal(); return; }

        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch('{{ route("equipos-auxiliares.bulkMove") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? ''
            },
            body: JSON.stringify({ ids: ids, id_frente: parseInt(frenteId, 10) })
        })
        .then(r => r.json().then(body => ({ status: r.status, body })))
        .then(({ status, body }) => {
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message || 'Movilización exitosa.', 'success');
                window.auxClearSelection();
                window.closeAuxMovilizarModal();
                cargarAuxiliares();
            } else {
                if (window.showModal) {
                    window.showModal({ type:'error', title:'Error', message: body.message || 'No se pudo movilizar.', confirmText:'Entendido', hideCancel:true });
                } else if (window.showToast) {
                    window.showToast(body.message || 'Error al movilizar.', 'error');
                }
            }
        })
        .catch(err => {
            console.error('auxSubmitMovilizar:', err);
            if (window.showToast) window.showToast('Error de red.', 'error');
        })
        .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
    };

    if (!window.auxPaginationAttached) {
        window.auxPaginationAttached = true;
        document.addEventListener('click', (e) => {
            const link = e.target.closest('#auxPagination a');
            if (!link) return;
            e.preventDefault();
            const u = new URL(link.href);
            const form = document.getElementById('auxFiltersForm');
            if (form) {
                const p = new URLSearchParams(new FormData(form));
                p.forEach((v, k) => u.searchParams.set(k, v));
            }
            if (typeof window.showPreloader === 'function') window.showPreloader();
            fetch(u.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }})
                .then(r => r.json())
                .then(data => {
                    document.getElementById('auxTableBody').innerHTML = data.html;
                    document.getElementById('auxPagination').innerHTML = data.pagination;
                })
                .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
        });
    }

    if (!window.auxAccionesOutsideBound) {
        window.auxAccionesOutsideBound = true;
        document.addEventListener('click', (e) => {
            // Cerrar dropdown Acciones
            const d = document.getElementById('auxAccionesDropdown');
            const btn = document.getElementById('auxAccionesBtn');
            if (d && btn && !d.contains(e.target) && !btn.contains(e.target)) d.style.display = 'none';
            // Cerrar panel Filtros Avanzados
            const adv = document.getElementById('auxAdvPanel');
            const advBtn = document.getElementById('auxAdvBtn');
            if (adv && advBtn && !adv.contains(e.target) && !advBtn.contains(e.target)) adv.style.display = 'none';
            // Cerrar menu de estado de fila
            const sm = document.getElementById('auxStatusMenu');
            if (sm && !sm.contains(e.target) && !e.target.closest('.aux-status-trigger')) sm.style.display = 'none';
        });
    }

    // ── Menu de cambio de estado (estilo /admin/equipos openSharedStatusMenu) ──
    const AUX_STATUS_CFG = {
        OPERATIVO:      { color: '#16a34a', bg: '#f0fdf4', icon: 'check_circle', label: 'Operativo' },
        INOPERATIVO:    { color: '#dc2626', bg: '#fef2f2', icon: 'cancel',       label: 'Inoperativo' },
        EN_ALMACEN:     { color: '#1e40af', bg: '#eff6ff', icon: 'inventory_2',  label: 'En Almacén' },
        DESINCORPORADO: { color: '#475569', bg: '#f1f5f9', icon: 'block',        label: 'Desincorp.' },
    };

    function getOrCreateAuxStatusMenu() {
        let menu = document.getElementById('auxStatusMenu');
        if (menu) return menu;
        menu = document.createElement('div');
        menu.id = 'auxStatusMenu';
        menu.style.cssText = 'position:absolute; display:none; min-width:200px; background:white; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 25px -5px rgba(15,23,42,0.18); overflow:hidden; z-index:9999;';
        document.body.appendChild(menu);
        return menu;
    }

    let _auxStatusTrigger = null;

    window.openAuxStatusMenu = function (trigger) {
        const menu = getOrCreateAuxStatusMenu();
        if (_auxStatusTrigger === trigger && menu.style.display !== 'none') {
            menu.style.display = 'none'; _auxStatusTrigger = null; return;
        }
        _auxStatusTrigger = trigger;
        const currentStatus = trigger.dataset.status;
        menu.innerHTML = '';
        Object.entries(AUX_STATUS_CFG).forEach(([key, cfg]) => {
            const item = document.createElement('div');
            item.style.cssText = 'display:flex; align-items:center; gap:8px; padding:10px 12px; cursor:pointer; border-bottom:1px solid #f8fafc;';
            item.innerHTML = `
                <div style="background:${cfg.bg}; padding:4px; border-radius:4px; display:flex;">
                    <i class="material-icons" style="font-size:16px; color:${cfg.color};">${cfg.icon}</i>
                </div>
                <span style="font-size:12px; font-weight:600; color:#334155;">${cfg.label}</span>
                ${key === currentStatus
                    ? '<i class="material-icons" style="font-size:14px; color:'+cfg.color+'; margin-left:auto;">check</i>'
                    : ''}
            `;
            item.addEventListener('mouseover', () => item.style.background = '#f8fafc');
            item.addEventListener('mouseout',  () => item.style.background = 'white');
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                menu.style.display = 'none';
                const t = _auxStatusTrigger;
                _auxStatusTrigger = null;
                window.auxChangeStatus(t, key);
            });
            menu.appendChild(item);
        });
        const r = trigger.getBoundingClientRect();
        menu.style.top  = (window.scrollY + r.bottom + 4) + 'px';
        menu.style.left = (window.scrollX + r.left) + 'px';
        menu.style.display = 'block';
    };

    window.auxChangeStatus = function (trigger, newStatus) {
        if (!trigger) return;
        const oldStatus = trigger.dataset.status;
        if (oldStatus === newStatus) return;
        const url = trigger.dataset.statusUrl;
        const cfg = AUX_STATUS_CFG[newStatus] || AUX_STATUS_CFG['DESINCORPORADO'];

        const iconEl  = trigger.querySelector('.material-icons:first-child') || trigger.querySelector('div > .material-icons');
        const labelEl = trigger.querySelector('.aux-status-label');

        // Optimistic UI (mismo estilo que equipos)
        if (iconEl)  { iconEl.textContent = cfg.icon; iconEl.style.color = cfg.color; }
        if (labelEl)   labelEl.textContent = cfg.label;
        const innerBlock = trigger.querySelector('div');
        if (innerBlock) innerBlock.style.color = cfg.color;
        trigger.dataset.status = newStatus;

        fetch(url, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? ''
            },
            body: JSON.stringify({ ESTADO_OPERATIVO: newStatus })
        })
        .then(r => r.json().then(body => ({ status: r.status, body })))
        .then(({ status, body }) => {
            if (status === 200) {
                // UI ya actualizada optimisticamente arriba. Sin recarga ni spinner.
                if (window.showToast) window.showToast('Estado actualizado.', 'success');
            } else {
                throw new Error(body.message || 'Error');
            }
        })
        .catch(err => {
            const oldCfg = AUX_STATUS_CFG[oldStatus] || AUX_STATUS_CFG['DESINCORPORADO'];
            if (iconEl)  { iconEl.textContent = oldCfg.icon; iconEl.style.color = oldCfg.color; }
            if (labelEl)   labelEl.textContent = oldCfg.label;
            if (innerBlock) innerBlock.style.color = oldCfg.color;
            trigger.dataset.status = oldStatus;
            if (window.showToast) window.showToast('No se pudo actualizar el estado.', 'error');
            console.error('auxChangeStatus:', err);
        });
    };

    // Exportar XLSX respetando filtros activos.
    // Usamos fetch() + Blob en vez de <a> click para que el navegador NO muestre
    // el spinner de pestana: solo el preloader propio de la app.
    window.exportAuxiliaresXlsx = function () {
        const form = document.getElementById('auxFiltersForm');
        const params = form ? new URLSearchParams(new FormData(form)) : new URLSearchParams();
        const url = '{{ route("equipos-auxiliares.export") }}' + (params.toString() ? '?' + params.toString() : '');

        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const cd = r.headers.get('Content-Disposition') || '';
                const m = cd.match(/filename="?([^";]+)"?/i);
                const filename = m ? m[1] : 'Listado_Equipos_Auxiliares.xlsx';
                return r.blob().then(blob => ({ blob, filename }));
            })
            .then(({ blob, filename }) => {
                const objUrl = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = objUrl;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                setTimeout(() => URL.revokeObjectURL(objUrl), 1000);
            })
            .catch(err => {
                console.error('export auxiliares:', err);
                if (window.showToast) window.showToast('No se pudo exportar. Intenta nuevamente.', 'error');
            })
            .finally(() => { if (typeof window.hidePreloader === 'function') window.hidePreloader(); });
    };

    // ═══════════════════════════════════════════════════════════
    // AUTOCOMPLETE FILTROS AVANZADOS (Marca / Modelo / Capacidad / Estado)
    // Sistema propio: controla display directo, sin depender de CSS .active
    // ═══════════════════════════════════════════════════════════
    window.auxAdvFilter = function (prefix, q) {
        var list = document.getElementById('aux_list_' + prefix);
        if (!list) return;
        list.style.display = 'block';
        var term = (q || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        list.querySelectorAll('.aux-adv-opt').forEach(function (opt) {
            var text = opt.textContent.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            opt.style.display = (term === '' || text.includes(term)) ? 'block' : 'none';
        });
        var clr = document.getElementById('aux_clr_' + prefix);
        if (clr) clr.style.display = q ? 'block' : 'none';
    };

    window.auxAdvOpen = function (prefix) {
        var list = document.getElementById('aux_list_' + prefix);
        if (!list) return;
        list.style.display = 'block';
        list.querySelectorAll('.aux-adv-opt').forEach(function (opt) {
            opt.style.display = 'block';
        });
    };

    window.auxAdvClose = function (prefix) {
        var list = document.getElementById('aux_list_' + prefix);
        if (list) list.style.display = 'none';
    };

    window.auxAdvSelect = function (prefix, value, label) {
        var hidden = document.getElementById('aux_val_' + prefix);
        var txt    = document.getElementById('aux_txt_' + prefix);
        var clr    = document.getElementById('aux_clr_' + prefix);
        var box    = document.getElementById('aux_box_' + prefix);
        var list   = document.getElementById('aux_list_' + prefix);
        if (hidden) hidden.value = value;
        if (txt)    { txt.value = value; txt.placeholder = value ? value : label; }
        if (clr)    clr.style.display = value ? 'block' : 'none';
        if (box)    {
            box.style.background  = value ? '#e1effa' : '#fbfcfd';
            box.style.borderColor = value ? '#0067b1' : '#cbd5e0';
        }
        if (list)   list.style.display = 'none';
    };

    window.auxAdvClear = function (prefix) {
        window.auxAdvSelect(prefix, '', '');
    };

    // ═══════════════════════════════════════════════════════════
    // AUTOCOMPLETE FILTROS PRINCIPALES (Frente / Tipo)
    // Mismo patron que auxAdv* pero sobre elementos aux_main_*
    // ═══════════════════════════════════════════════════════════
    window.auxMainFilter = function (prefix, q) {
        var list = document.getElementById('aux_main_list_' + prefix);
        if (!list) return;
        list.style.display = 'block';
        var term = (q || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
        list.querySelectorAll('.aux-main-opt').forEach(function (opt) {
            var text = (opt.dataset.label || opt.textContent).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            opt.style.display = (term === '' || text.includes(term)) ? 'block' : 'none';
        });
    };

    window.auxMainOpen = function (prefix) {
        var list = document.getElementById('aux_main_list_' + prefix);
        if (!list) return;
        list.style.display = 'block';
        list.querySelectorAll('.aux-main-opt').forEach(function (opt) {
            opt.style.display = 'block';
        });
    };

    window.auxMainClose = function (prefix) {
        var list = document.getElementById('aux_main_list_' + prefix);
        if (list) list.style.display = 'none';
    };

    window.auxMainSelect = function (prefix, value, label) {
        var hidden = document.getElementById('aux_main_val_' + prefix);
        var txt    = document.getElementById('aux_main_txt_' + prefix);
        var clr    = document.getElementById('aux_main_clr_' + prefix);
        var box    = document.getElementById('aux_main_box_' + prefix);
        var list   = document.getElementById('aux_main_list_' + prefix);
        var isReal = value && value !== 'all' && value !== '';
        if (hidden) hidden.value = isReal ? value : '';
        if (txt)    { txt.value = isReal ? label : ''; txt.placeholder = label; }
        if (clr)    clr.style.display = isReal ? 'block' : 'none';
        if (box)    {
            box.style.background  = isReal ? '#e1effa' : '#fbfcfd';
            box.style.borderColor = isReal ? '#0067b1' : '#cbd5e0';
        }
        if (list)   list.style.display = 'none';
    };

    window.auxMainClear = function (prefix) {
        window.auxMainSelect(prefix, '', prefix === 'frente' ? 'Filtrar Frente...' : 'Filtrar Tipo...');
    };

    // ═══════════════════════════════════════════════════════════
    // ANCLAR MASIVO desde la barra flotante: el usuario elige UN host y
    // el frontend itera POST /anchor por cada aux seleccionado.
    // ═══════════════════════════════════════════════════════════
    window.openAuxAnclarBulkModal = function () {
        const ids = Object.keys(window._auxSelectedMap || {});
        if (ids.length === 0) return;
        let overlay = document.getElementById('auxAnclarBulkOverlay');
        if (overlay) overlay.remove();
        // Modal con la misma estructura/estilo del modal de anclaje en
        // /admin/equipos (equipos_index.js L1568): width 90% / max-width 440px,
        // header 1e293b centrado, lista bg #f8fafc con padding 8px.
        overlay = document.createElement('div');
        overlay.id = 'auxAnclarBulkOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2500;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(2px);';
        overlay.innerHTML = `
            <div style="background:white;border-radius:16px;width:90%;max-width:400px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
                <div style="background:#1e293b;padding:18px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="material-icons" style="color:#10b981;font-size:20px;">anchor</i>
                        <h2 style="margin:0;font-size:16px;font-weight:700;">Anclar Auxiliares</h2>
                    </div>
                    <button type="button" onclick="document.getElementById('auxAnclarBulkOverlay').remove();" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                        <i class="material-icons">close</i>
                    </button>
                </div>
                <div style="padding:20px;">
                    <div style="font-size:11px;color:#64748b;margin-bottom:8px;text-align:center;">${ids.length} auxiliar${ids.length>1?'es':''} a anclar</div>
                    <!-- Buscador (mismo patron que /admin/equipos) -->
                    <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:10px;background:white;overflow:hidden;margin-bottom:12px;transition:border-color 0.2s;" id="auxABBox">
                        <i class="material-icons" style="padding:0 10px;color:#94a3b8;font-size:18px;flex-shrink:0;">search</i>
                        <input type="text" id="auxABInput" placeholder="Buscar por tipo, marca, serial..." autocomplete="off" style="flex:1;border:none;outline:none;padding:9px 6px;font-size:13px;background:transparent;">
                    </div>
                    <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;display:flex;align-items:center;gap:6px;" id="auxABRecommendLabel">
                        <i class="material-icons" style="font-size:14px;color:#10b981;">recommend</i>
                        Recomendados (Flota Liviana)
                    </div>
                    <div id="auxABList" style="max-height:340px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:8px;margin-bottom:14px;">
                        <div style="padding:20px;text-align:center;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:22px;color:#94a3b8;">sync</i></div>
                    </div>
                    <button id="auxABConfirmBtn" type="button" disabled
                            style="width:100%;height:46px;border-radius:12px;font-weight:700;font-size:14px;background:#10b981;color:white;border:none;display:flex;align-items:center;justify-content:center;gap:8px;opacity:0.5;cursor:not-allowed;transition:all 0.2s;">
                        <i class="material-icons">check_circle</i> Confirmar Anclaje
                    </button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const input = document.getElementById('auxABInput');
        const list  = document.getElementById('auxABList');
        const label = document.getElementById('auxABRecommendLabel');
        let timer = null;
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

        // Carga inicial: recomendaciones (flota liviana). Si el usuario teclea
        // 2+ caracteres, se reemplaza por busqueda libre.
        const fetchAndRender = (urlParams, isRecommend) => {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:22px;">sync</i></div>';
            if (label) {
                if (isRecommend) {
                    label.style.display = 'flex';
                    label.innerHTML = '<i class="material-icons" style="font-size:14px;color:#10b981;">recommend</i>Recomendados (Flota Liviana)';
                } else {
                    label.style.display = 'flex';
                    label.innerHTML = '<i class="material-icons" style="font-size:14px;color:#0067b1;">search</i>Resultados de búsqueda';
                }
            }
            fetch('{{ route("equipos-auxiliares.searchHosts") }}?' + urlParams, {
                headers: {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
            })
            .then(r => r.json())
                .then(rows => {
                    if (!rows || !rows.length) {
                        list.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="font-size:32px;display:block;margin:0 auto 10px;">search_off</i>Sin resultados</div>';
                        return;
                    }
                    // Tarjeta 1:1 con la del modal de anclaje en /admin/equipos
                    // (equipos_index.js L1667): foto 40x40 + TIPO uppercase +
                    // MARCA + [fingerprint serial] [featured_play_list placa]
                    // + location_on frente.
                    const esc = (s) => (s || '').toString().replace(/"/g, '&quot;').replace(/'/g, "\\'");
                    list.innerHTML = rows.map(r => {
                        const dis = r.disponible ? '' : 'cursor:not-allowed;opacity:0.6;';
                        const badge = r.disponible
                            ? ''
                            : `<i class="material-icons" style="color:#cbd5e0;font-size:18px;margin-left:auto;" title="Lleno (${r.auxiliares_anclados}/2)">lock</i>`;
                        // object-fit:contain para mostrar la foto COMPLETA (sin recortar
                        // el largo del equipo). Background blanco rellena el sobrante
                        // del cuadrado para que se vea limpia.
                        const fotoHtml = r.foto
                            ? `<img src="${esc(r.foto)}" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:20px;color:#cbd5e0;&quot;>image_not_supported</i>'">`
                            : `<i class="material-icons" style="font-size:20px;color:#cbd5e0;">image_not_supported</i>`;
                        const placaPart = r.placa
                            ? `<span style="font-size:10px;color:#0067b1;font-weight:700;display:flex;align-items:center;gap:2px;"><i class="material-icons" style="font-size:10px;">featured_play_list</i>${esc(r.placa)}</span>`
                            : '';
                        const frenteBadge = r.frente_nombre
                            ? `<div style="font-size:10px;color:#f97316;font-weight:700;display:flex;align-items:center;gap:2px;margin-top:2px;"><i class="material-icons" style="font-size:10px;">location_on</i>${esc(r.frente_nombre)}</div>`
                            : '';
                        // Selection-then-confirm: click marca la card como seleccionada
                        // (verde + check) y habilita el boton Confirmar Anclaje. NO
                        // dispara el anclaje hasta que el usuario presione Confirmar.
                        const clickHandler = r.disponible
                            ? `onclick="window.auxABSelectHost(this, ${r.id}, '${esc(r.placa || r.serial_chasis || ('#' + r.id))}')"`
                            : '';
                        return `
                            <div class="aux-ab-host-card" style="padding:10px;border-radius:8px;background:white;border:1px solid #e2e8f0;margin-bottom:6px;display:flex;align-items:center;gap:12px;transition:all 0.2s;position:relative;${dis}"
                                 onmouseover="if(!this.dataset.locked && !this.dataset.selected){this.style.borderColor='#10b981';this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.05)';}"
                                 onmouseout="if(!this.dataset.locked && !this.dataset.selected){this.style.borderColor='#e2e8f0';this.style.boxShadow='none';}"
                                 ${!r.disponible ? 'data-locked="1"' : ''}
                                 ${clickHandler}>
                                <div style="width:40px;height:40px;background:#f1f5f9;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${fotoHtml}</div>
                                <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;">
                                    <span style="font-weight:800;font-size:13px;color:#1e293b;text-transform:uppercase;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.tipo || 'S/TIPO')}</span>
                                    <div style="font-size:11px;color:#475569;font-weight:600;">${esc(r.marca || '')}</div>
                                    <div style="display:flex;align-items:center;gap:8px;margin-top:1px;">
                                        <span style="font-size:10px;color:#64748b;display:flex;align-items:center;gap:2px;"><i class="material-icons" style="font-size:10px;">fingerprint</i>${esc(r.serial_chasis || 'S/S')}</span>
                                        ${placaPart}
                                    </div>
                                    ${frenteBadge}
                                </div>
                                ${badge}
                            </div>`;
                    }).join('');
                });
        };

        // Carga inicial: recomendaciones FLOTA LIVIANA
        fetchAndRender('recommend=1', true);

        // Busqueda libre: 2+ chars, debounce 280ms
        input.addEventListener('input', () => {
            const q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 2) {
                // Si vacian el input, recargamos recomendaciones
                if (q.length === 0) fetchAndRender('recommend=1', true);
                else list.innerHTML = '<div style="padding:18px;text-align:center;color:#94a3b8;font-size:12px;">Escribe al menos 2 caracteres...</div>';
                window._auxABSelected = null;
                _auxABRefreshConfirmBtn();
                return;
            }
            timer = setTimeout(() => {
                fetchAndRender('q=' + encodeURIComponent(q), false);
            }, 280);
        });

        // Boton Confirmar Anclaje
        const confirmBtn = document.getElementById('auxABConfirmBtn');
        if (confirmBtn) {
            confirmBtn.onclick = function () {
                if (!window._auxABSelected) return;
                window.auxAnclarBulkConfirm(
                    window._auxABSelected.id,
                    window._auxABSelected.label
                );
            };
        }
    };

    // Selecciona un host en el modal: highlight verde + check + habilita Confirmar.
    window.auxABSelectHost = function (cardEl, hostId, hostLabel) {
        // Limpia seleccion previa
        document.querySelectorAll('.aux-ab-host-card[data-selected="1"]').forEach(c => {
            c.dataset.selected = '';
            c.style.borderColor = '#e2e8f0';
            c.style.background = 'white';
            const chk = c.querySelector('.aux-ab-check');
            if (chk) chk.remove();
        });
        // Marca la nueva
        cardEl.dataset.selected = '1';
        cardEl.style.borderColor = '#10b981';
        cardEl.style.background = '#f0fdf4';
        cardEl.style.boxShadow = '0 4px 6px -1px rgba(16,185,129,0.15)';
        if (!cardEl.querySelector('.aux-ab-check')) {
            const ck = document.createElement('div');
            ck.className = 'aux-ab-check';
            ck.style.cssText = 'color:#10b981;flex-shrink:0;display:flex;align-items:center;';
            ck.innerHTML = '<i class="material-icons" style="font-size:22px;">check_circle</i>';
            cardEl.appendChild(ck);
        }
        window._auxABSelected = { id: hostId, label: hostLabel };
        _auxABRefreshConfirmBtn();
    };

    function _auxABRefreshConfirmBtn() {
        const btn = document.getElementById('auxABConfirmBtn');
        if (!btn) return;
        if (window._auxABSelected) {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
        }
    }

    window.auxAnclarBulkConfirm = function (hostId, hostLabel) {
        const ids = Object.keys(window._auxSelectedMap || {});
        if (!ids.length) return;
        // Sin confirm() nativo: el flujo ya es explicito (seleccionar host +
        // click en "Confirmar Anclaje"); el browser-modal feo solo agregaba
        // una capa de friccion innecesaria.
        document.getElementById('auxAnclarBulkOverlay')?.remove();
        if (window.showPreloader) window.showPreloader();
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        let ok = 0, fail = 0;
        Promise.allSettled(ids.map(auxId => fetch('/admin/equipos-auxiliares/' + auxId + '/anchor', {
            method:'POST',
            headers:{'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},
            body: JSON.stringify({id_equipo_host: hostId})
        }).then(r => r.json().then(b => r.status === 200 && b.success ? ok++ : fail++))))
        .then(() => {
            if (window.hidePreloader) window.hidePreloader();
            if (window.showToast) {
                if (fail === 0) window.showToast(`${ok} auxiliar(es) anclado(s) a ${hostLabel}.`, 'success');
                else window.showToast(`${ok} ok / ${fail} fallidos.`, 'warning');
            }
            window.auxClearSelection && window.auxClearSelection();
            if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
        });
    };

    // ═══════════════════════════════════════════════════════════
    // EDITOR DE FECHA DE VENCIMIENTO DEL CERTIFICADO (dentro del visor PDF)
    // Se inyecta como mini banner sobre el iframe del visor; PATCH a
    // /admin/equipos-auxiliares/{id}/cert-expiry (require user.edit).
    // ═══════════════════════════════════════════════════════════
    window.auxInjectCertExpiryEditor = function (auxId, fechaActual) {
        // Esperar a que el visor de PDF este montado
        setTimeout(() => {
            const modal = document.getElementById('pdfPreviewModal');
            if (!modal) return;
            // Limpiar instancia previa
            const old = document.getElementById('auxCertExpiryBanner');
            if (old) old.remove();
            const banner = document.createElement('div');
            banner.id = 'auxCertExpiryBanner';
            banner.style.cssText = 'position:absolute; top:14px; right:80px; z-index:10; background:rgba(15,23,42,0.92); color:white; padding:8px 14px; border-radius:10px; display:flex; align-items:center; gap:10px; box-shadow:0 4px 14px rgba(0,0,0,0.35); font-size:12.5px;';
            banner.innerHTML = `
                <i class="material-icons" style="font-size:18px; color:#fcd34d;">event</i>
                <label style="font-weight:700;">Vence:</label>
                <input type="date" id="auxCertExpiryInput" value="${fechaActual || ''}" style="padding:5px 8px; border-radius:6px; border:1px solid #475569; background:#1e293b; color:white; font-size:12.5px; outline:none;">
                <button type="button" id="auxCertExpirySave" style="padding:5px 10px; border-radius:6px; background:#0067b1; color:white; border:none; cursor:pointer; font-weight:700; font-size:12px;">Guardar</button>
            `;
            modal.querySelector('.modal-content').appendChild(banner);
            document.getElementById('auxCertExpirySave').onclick = () => {
                const v = document.getElementById('auxCertExpiryInput').value;
                const csrf = document.querySelector('meta[name="csrf-token"]').content;
                const fd = new FormData();
                fd.append('_method', 'PATCH');
                fd.append('fecha_vencimiento_cert', v || '');
                fetch('/admin/equipos-auxiliares/' + auxId + '/cert-expiry', {
                    method: 'POST',
                    headers: {'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf},
                    body: fd
                })
                .then(r => r.json())
                .then(b => {
                    if (b.success) {
                        if (window.showToast) window.showToast(b.message || 'Fecha actualizada.', 'success');
                    } else {
                        if (window.showToast) window.showToast(b.message || 'No se pudo actualizar.', 'error');
                    }
                })
                .catch(err => {
                    console.error('updateCertExpiry:', err);
                    if (window.showToast) window.showToast('Error de red al guardar la fecha.', 'error');
                });
            };
            // Limpiar el banner al cerrar el visor
            const closeObserver = new MutationObserver(() => {
                if (!modal.classList.contains('active')) {
                    banner.remove();
                    closeObserver.disconnect();
                }
            });
            closeObserver.observe(modal, { attributes: true, attributeFilter: ['class'] });
        }, 350);
    };

    // ═══════════════════════════════════════════════════════════
    // SUBIR/ELIMINAR PDF DEL MODAL DE DETALLES (require equipos.edit)
    // ═══════════════════════════════════════════════════════════
    window.auxUploadDoc = function (auxId, docType, input) {
        const file = input.files && input.files[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('doc_type', docType);
        if (window.showPreloader) window.showPreloader();
        fetch('/admin/equipos-auxiliares/' + auxId + '/upload-doc', {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            body: fd
        })
        .then(r => r.json().then(b => ({status:r.status, body:b})))
        .then(({status, body}) => {
            if (window.hidePreloader) window.hidePreloader();
            input.value = '';
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message || 'PDF cargado.', 'success');
                const trigger = document.querySelector('.btn-details-mini[data-aux-id="'+auxId+'"]');
                if (trigger) {
                    window.closeAuxDetailsModal();
                    setTimeout(() => window.openAuxDetailsModal(trigger), 100);
                }
            } else {
                if (window.showToast) window.showToast(body.message || 'No se pudo cargar el PDF.', 'error');
            }
        })
        .catch(err => {
            if (window.hidePreloader) window.hidePreloader();
            input.value = '';
            console.error('uploadDoc:', err);
            if (window.showToast) window.showToast('Error de red al cargar el PDF.', 'error');
        });
    };


    // ═══════════════════════════════════════════════════════════
    // MODAL "VER ANCLAJES" - Muestra aux anclados a equipos host
    // Mismo patron visual que /admin/equipos (openAnclajesListModal)
    // ═══════════════════════════════════════════════════════════

    // Helper compartido: construye el querystring de filtros heredados
    // del listado principal (frente/tipo) leyendo los hidden inputs que
    // el AJAX del listado mantiene actualizados. La URL del browser no
    // siempre refleja el estado del filtro (cuando se filtra via AJAX
    // sin pushState), por eso priorizamos los hidden inputs.
    window._auxBuildAnclajesFilterQS = function () {
        var qs = new URLSearchParams();
        var fEl = document.getElementById('aux_main_val_frente');
        var tEl = document.getElementById('aux_main_val_tipo');
        var f = fEl ? (fEl.value || '').trim() : '';
        var t = tEl ? (tEl.value || '').trim() : '';
        // Fallback a la URL por si los hidden no estan presentes (defensivo)
        if (!f) { var sp = new URLSearchParams(window.location.search); f = sp.get('id_frente') || ''; }
        if (!t) { var sp2 = new URLSearchParams(window.location.search); t = sp2.get('tipo') || ''; }
        if (f && f !== 'all' && f !== 'none') qs.set('id_frente', f);
        if (t && t !== 'all' && t !== 'none') qs.set('tipo', t);
        return qs.toString();
    };

    window.openAuxAnclajesModal = function () {
        let modal = document.getElementById('auxAnclajesModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'auxAnclajesModal';
            modal.className = 'modal-overlay';
            modal.style.zIndex = '10000';
            modal.innerHTML = `
                <div class="modal-content" style="width:90%; max-width:820px; max-height:90vh; background:#fff; border-radius:12px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div style="background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%); padding:14px 18px; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="background:rgba(255,255,255,0.1); padding:8px; border-radius:8px;"><i class="material-icons" style="color:#fff; font-size:20px;">link</i></div>
                            <h3 style="margin:0; color:#fff; font-size:16px; font-weight:600;">Anclaje de Auxiliares</h3>
                            <span id="auxAnclajesCount" style="background:#10b981;color:white;font-size:11px;font-weight:800;padding:2px 8px;border-radius:10px;">0</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <button type="button" id="btnAuxAnclajesExport" title="Exportar a Excel (.xlsx)"
                                style="background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.28); color:#ffffff; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:6px; border-radius:6px; transition:all 0.2s;"
                                onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                                <i class="material-icons" style="font-size:18px;">download</i>
                            </button>
                            <button type="button" onclick="document.getElementById('auxAnclajesModal').classList.remove('active')" style="background:transparent; border:none; color:#94a3b8; cursor:pointer; display:flex; padding:4px;">
                                <i class="material-icons">close</i>
                            </button>
                        </div>
                    </div>
                    <div id="auxAnclajesLoading" style="padding:40px; text-align:center; color:#64748b;">
                        <i class="material-icons" style="font-size:32px; animation:fleetSpin 1s linear infinite;">refresh</i>
                        <p style="margin-top:10px; font-size:14px;">Cargando anclajes...</p>
                    </div>
                    <div id="auxAnclajesBody" style="display:none; padding:14px 16px; overflow-y:auto; flex:1; background:#f8fafc;">
                        <div id="auxAnclajesGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:10px;"></div>
                    </div>
                </div>`;
            document.body.appendChild(modal);
            // Boton de export — descarga via blob (mismo patron que /admin/equipos)
            // para usar el preloader global y evitar el spinner nativo del navegador.
            // Hereda los filtros del listado principal (id_frente, tipo) si estan activos.
            document.getElementById('btnAuxAnclajesExport').addEventListener('click', function () {
                var qs = window._auxBuildAnclajesFilterQS ? window._auxBuildAnclajesFilterQS() : '';
                var url = '{{ route("equipos-auxiliares.exportAnclajes") }}' + (qs ? ('?' + qs) : '');
                if (typeof window.showPreloader === 'function') window.showPreloader();
                fetch(url, { credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
                    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); var cd=r.headers.get('content-disposition')||''; var m=cd.match(/filename="?([^";]+)"?/i); var fname=m?m[1]:('Anclajes_Auxiliares_'+new Date().toISOString().slice(0,10)+'.xlsx'); return r.blob().then(function(b){return {blob:b, fname:fname};}); })
                    .then(function(o){ var u=URL.createObjectURL(o.blob); var a=document.createElement('a'); a.href=u; a.download=o.fname; a.style.display='none'; document.body.appendChild(a); a.click(); setTimeout(function(){document.body.removeChild(a); URL.revokeObjectURL(u);},300); if(window.showToast) window.showToast('Descarga lista: '+o.fname,'success'); })
                    .catch(function(err){ console.error('[exportAuxAnclajes]', err); if(window.showToast) window.showToast('Error al descargar el Excel.','error'); })
                    .finally(function(){ if(typeof window.hidePreloader==='function') window.hidePreloader(); });
            });
        }
        modal.classList.add('active');
        document.getElementById('auxAnclajesLoading').style.display = 'block';
        document.getElementById('auxAnclajesBody').style.display = 'none';

        // Hereda los filtros del listado principal (id_frente, tipo) si los tiene activos.
        // Lee de los hidden inputs (#aux_main_val_frente / #aux_main_val_tipo) que el
        // listado AJAX mantiene actualizados — la URL no siempre tiene los params.
        var _qsIn = window._auxBuildAnclajesFilterQS ? window._auxBuildAnclajesFilterQS() : '';
        var _urlIn = '{{ route("equipos-auxiliares.anchoredList") }}' + (_qsIn ? ('?' + _qsIn) : '');
        fetch(_urlIn, {
            headers: { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
        .then(data => {
            const items = Array.isArray(data.items) ? data.items : [];
            document.getElementById('auxAnclajesLoading').style.display = 'none';
            document.getElementById('auxAnclajesBody').style.display = 'block';
            const grid = document.getElementById('auxAnclajesGrid');
            const countBadge = document.getElementById('auxAnclajesCount');
            if (countBadge) countBadge.textContent = items.length;
            if (items.length === 0) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:30px; color:#94a3b8; background:#fff; border-radius:8px; border:1px dashed #cbd5e1;">No hay auxiliares anclados actualmente.</div>';
                return;
            }
            const esc = (s) => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

            // Agrupar por host: 1 tarjeta por equipo host con TODOS sus auxiliares anclados.
            // Antes se renderizaba 1 tarjeta por (auxiliar, host) — duplicaba el host.
            const groups = {};
            items.forEach(a => {
                const key = a.host_id ?? ('NOHOST_'+ (a.host_codigo||'') + (a.host_serial||''));
                if (!groups[key]) {
                    groups[key] = {
                        host_id:    a.host_id,
                        host_codigo:a.host_codigo,
                        host_placa: a.host_placa,
                        host_serial:a.host_serial,
                        host_tipo:  a.host_tipo,
                        host_marca: a.host_marca,
                        host_modelo:a.host_modelo,
                        host_frente:a.host_frente,
                        host_foto:  a.host_foto,
                        auxes:      []
                    };
                }
                groups[key].auxes.push(a);
            });

            grid.innerHTML = Object.values(groups).map(g => {
                const hostLabel = g.host_placa || g.host_serial || g.host_codigo || ('#' + g.host_id);
                const hostType  = (g.host_tipo || 'Equipo').toString();
                const hostMarca = g.host_marca ? esc(g.host_marca) : '';
                const hostFotoHtml = g.host_foto
                    ? `<img src="${esc(g.host_foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:22px;color:#1e40af;&quot;>directions_car</i>'">`
                    : '<i class="material-icons" style="font-size:22px;color:#1e40af;">directions_car</i>';

                const auxRowsHtml = g.auxes.map(a => {
                    const auxLabel   = a.serial || ((a.marca || '') + ' ' + (a.modelo || '')).trim() || '—';
                    const auxFotoHtml = a.foto
                        ? `<img src="${esc(a.foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:16px;color:#f59e0b;&quot;>construction</i>'">`
                        : '<i class="material-icons" style="font-size:16px;color:#f59e0b;">construction</i>';
                    return `<div style="display:flex; align-items:center; gap:8px; padding:6px 8px; background:#fff7ed; border-radius:6px; border:1px solid #fed7aa;">
                        <div style="background:#fff;padding:0;border-radius:5px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #fed7aa;">${auxFotoHtml}</div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:9px; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:0.3px;">${esc(a.tipo_label || a.tipo || 'AUXILIAR')}</div>
                            <div style="font-size:12px; font-weight:800; color:#7c2d12; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(auxLabel)}</div>
                            ${a.marca || a.modelo ? `<div style="font-size:10px; color:#9a3412;">${esc(a.marca||'')} ${esc(a.modelo||'')}</div>` : ''}
                        </div>
                    </div>`;
                }).join('');

                return `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:8px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
                    <div style="display:flex; align-items:center; gap:10px; padding:8px 10px; background:#eff6ff; border-radius:8px; border:1px solid #bfdbfe;">
                        <div style="background:#fff;padding:0;border-radius:6px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #bfdbfe;">${hostFotoHtml}</div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:9.5px; font-weight:700; color:#1e3a8a; text-transform:uppercase; letter-spacing:0.4px;">${esc(hostType)}</div>
                            <div style="font-size:14px; font-weight:800; color:#1e3a8a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(hostLabel)}</div>
                            ${hostMarca ? `<div style="font-size:10.5px; color:#1d4ed8; margin-top:1px;">${hostMarca} ${esc(g.host_modelo||'')}</div>` : ''}
                        </div>
                        <span style="background:#10b981;color:white;font-size:10px;font-weight:800;padding:2px 8px;border-radius:10px;flex-shrink:0;">${g.auxes.length}</span>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:5px;">${auxRowsHtml}</div>
                </div>`;
            }).join('');
        })
        .catch(err => {
            console.error('openAuxAnclajesModal:', err);
            document.getElementById('auxAnclajesLoading').style.display = 'none';
            document.getElementById('auxAnclajesBody').style.display = 'block';
            document.getElementById('auxAnclajesGrid').innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#ef4444; padding:20px;">Error al cargar anclajes.</div>';
        });
    };

})();
</script>

{{-- Seed inicial de window.auxDetailsMap: TODOS los detalles de los auxiliares
     visibles en esta pagina. El modal del ojo abre instantaneamente porque la
     data ya esta en memoria. Las cargas AJAX de cargarAuxiliares hacen
     Object.assign para refrescar el mapa al paginar/filtrar (ver L920+). --}}
@if(!empty($auxDetailsMap))
<script>
    window.auxDetailsMap = Object.assign(window.auxDetailsMap || {}, @json($auxDetailsMap));
</script>
@endif

@can('super.admin')
{{-- Bulk delete de auxiliares (soft-delete con auditoria). Lee el set de
     IDs seleccionados desde window._auxSelectedMap (que el row-click ya
     mantiene actualizado). Reusa el preloader/showToast/showModal globales. --}}
<script>
window.bulkDeleteAuxiliaresSeleccionados = function () {
    var ids = Object.keys(window._auxSelectedMap || {}).map(function (x) { return parseInt(x, 10); });
    if (!ids.length) {
        if (window.showToast) window.showToast('Selecciona al menos un auxiliar (checkbox) antes de eliminar.', 'warning');
        else alert('Selecciona al menos un auxiliar antes de eliminar.');
        return;
    }
    var proceed = function () {
        if (window.showPreloader) window.showPreloader();
        var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch('{{ route("equipos-auxiliares.bulkDelete") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ ids: ids })
        })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) {
                if (window.showToast) window.showToast(res.body.message || 'Auxiliares eliminados.', 'success');
                if (typeof window.auxClearSelection === 'function') window.auxClearSelection();
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
            } else {
                if (window.showToast) window.showToast((res.body && res.body.message) || 'No se pudo eliminar.', 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            if (window.showToast) window.showToast('Error de red al eliminar.', 'error');
        });
    };
    if (typeof window.showModal === 'function') {
        window.showModal({
            type: 'warning',
            title: 'Eliminar Auxiliares',
            message: '¿Eliminar ' + ids.length + ' auxiliar(es) seleccionado(s)?\n\nLos datos quedan en papelera y pueden recuperarse.',
            confirmText: 'Sí, eliminar',
            cancelText: 'Cancelar',
            onConfirm: proceed
        });
    } else if (confirm('¿Eliminar ' + ids.length + ' auxiliar(es)?')) {
        proceed();
    }
};
</script>
@endcan
@endsection
