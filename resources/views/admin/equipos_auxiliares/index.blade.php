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

    /* ── Lista desplegable de autocomplete para Frente/Tipo/Serial ── */
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
        padding: 10px 15px;
        font-size: 14px;
        font-weight: 600;
        color: var(--maquinaria-dark-blue);
        cursor: pointer;
        border-radius: 6px;
    }
    .aux-main-opt:hover {
        background: #f0f4f8;
    }
    .aux-main-opt.placeholder {
        font-size: 13px;
        color: #64748b;
    }
    /* Tipo: la lista completa en mayusculas */
    #aux_main_list_tipo .aux-main-opt {
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
                <input type="text" id="auxSearchInput" name="search" value="{{ request('search') }}" placeholder="Filtrar Serial..."
                       oninput="window._auxDebounce && clearTimeout(window._auxDebounce); window._auxDebounce = setTimeout(cargarAuxiliares, 300);"
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
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:{{ $canCreateAux ? '#475569' : '#94a3b8' }};font-size:13px;font-weight:600;border-bottom:1px solid #f1f5f9;{{ $canCreateAux ? '' : 'cursor:not-allowed;' }}"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:{{ $canCreateAux ? '#fff7ed' : '#f1f5f9' }};padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:{{ $canCreateAux ? '#f59e0b' : '#94a3b8' }};">{{ $canCreateAux ? 'add_circle' : 'lock' }}</i></div>
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
                    <a href="#" onclick="event.preventDefault(); if(window.showToast){window.showToast('Catálogo por Modelo en desarrollo.', 'info');} document.getElementById('auxAccionesDropdown').style.display='none';"
                       style="display:flex;align-items:center;gap:10px;padding:12px 14px;text-decoration:none;color:#475569;font-size:13px;font-weight:600;"
                       onmouseover="this.style.background='#cbd5e1'" onmouseout="this.style.background='transparent'">
                        <div style="background:#eff6ff;padding:6px;border-radius:6px;display:flex;"><i class="material-icons" style="font-size:18px;color:#0067b1;">menu_book</i></div>
                        <span>Catálogo por Modelo</span>
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

        // Sin franja-expansion: fetch primero, modal se abre COMPLETO de una vez.
        fetch('/admin/equipos-auxiliares/' + id + '/details', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(d => {
            window.renderAuxDetailsModal(d);
            modal.style.display = '';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        })
        .catch(err => {
            console.error('openAuxDetailsModal:', err);
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
                return `<button type="button" title="Ver PDF" onclick="window.openPdfPreview('${safeUrl}', '${docType}', '${labelHr}', ${d.id}, '${uploadUrl}', true)" style="display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:#0067b1; box-shadow:0 2px 6px rgba(0,103,177,0.35); border:none; cursor:pointer; flex-shrink:0;"><i class="material-icons" style="font-size:17px; color:white;">description</i></button>`;
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
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    };

    window.closeAuxMovilizarModal = function () {
        const modal = document.getElementById('auxMovilizarModal');
        if (modal) modal.style.display = 'none';
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
        overlay = document.createElement('div');
        overlay.id = 'auxAnclarBulkOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000010;display:flex;align-items:center;justify-content:center;padding:16px;backdrop-filter:blur(2px);';
        overlay.innerHTML = `
            <div style="background:white;border-radius:14px;width:100%;max-width:520px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.4);">
                <div style="background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%);padding:14px 18px;color:white;display:flex;justify-content:space-between;align-items:center;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="background:rgba(16,185,129,0.2);padding:6px;border-radius:8px;display:flex;"><i class="material-icons" style="font-size:18px;color:#10b981;">anchor</i></div>
                        <div>
                            <h3 style="margin:0;font-size:15px;font-weight:700;">Anclar Auxiliares</h3>
                            <p style="margin:2px 0 0 0;font-size:11px;color:#94a3b8;">${ids.length} auxiliar${ids.length>1?'es':''} seleccionado${ids.length>1?'s':''}</p>
                        </div>
                    </div>
                    <button type="button" onclick="document.getElementById('auxAnclarBulkOverlay').remove();" style="background:transparent;border:none;color:#94a3b8;cursor:pointer;display:flex;padding:4px;"><i class="material-icons">close</i></button>
                </div>
                <div style="padding:14px 16px;background:#f8fafc;display:flex;flex-direction:column;gap:10px;overflow:hidden;flex:1;min-height:0;">
                    <div style="display:flex;align-items:center;background:white;border:2px solid #e2e8f0;border-radius:10px;overflow:hidden;flex-shrink:0;" id="auxABBox">
                        <i class="material-icons" style="padding:0 10px;color:#94a3b8;font-size:20px;">search</i>
                        <input type="text" id="auxABInput" placeholder="Buscar host por placa, serial chasis o motor..." autocomplete="off" style="flex:1;border:none;outline:none;padding:11px 6px;font-size:13.5px;background:transparent;">
                    </div>
                    <div id="auxABList" style="overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;background:white;flex:1;min-height:140px;">
                        <div style="padding:24px;text-align:center;color:#94a3b8;font-size:12.5px;"><i class="material-icons" style="font-size:28px;display:block;margin:0 auto 8px;color:#cbd5e0;">search</i>Escribe al menos 2 caracteres.</div>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const input = document.getElementById('auxABInput');
        const list  = document.getElementById('auxABList');
        let timer = null;
        input.focus();
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

        input.addEventListener('input', () => {
            const q = input.value.trim();
            if (q.length < 2) {
                list.innerHTML = '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:12.5px;"><i class="material-icons" style="font-size:28px;display:block;margin:0 auto 8px;color:#cbd5e0;">search</i>Escribe al menos 2 caracteres.</div>';
                return;
            }
            clearTimeout(timer);
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:22px;">sync</i></div>';
            timer = setTimeout(() => {
                fetch('{{ route("equipos-auxiliares.searchHosts") }}?q=' + encodeURIComponent(q), {
                    headers: {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
                })
                .then(r => r.json())
                .then(rows => {
                    if (!rows || !rows.length) { list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;font-size:12.5px;">Sin resultados.</div>'; return; }
                    list.innerHTML = rows.map(r => {
                        const idStr = r.placa || r.serial_chasis || ('#' + r.id);
                        const lbl = r.placa ? 'Placa' : (r.serial_chasis ? 'Chasis' : 'ID');
                        const dis = r.disponible ? '' : 'opacity:0.55;pointer-events:none;';
                        const badge = r.disponible
                            ? '<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Disponible</span>'
                            : `<span style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Lleno (${r.auxiliares_anclados}/2)</span>`;
                        return `<div style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;${dis}" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'" onclick="window.auxAnclarBulkConfirm(${r.id}, '${(idStr+'').replace(/'/g,"\\'")}')">
                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:2px;">
                                    <strong style="color:#1e293b;font-size:13px;"><span style="color:#94a3b8;font-size:9.5px;font-weight:600;text-transform:uppercase;">${lbl}:</span> ${idStr}</strong>
                                    ${badge}
                                </div>
                                <div style="font-size:11.5px;color:#475569;">${r.tipo || ''}${r.tipo && r.marca ? ' · ' : ''}${r.marca || ''}</div>
                            </div>
                        </div>`;
                    }).join('');
                });
            }, 280);
        });
    };

    window.auxAnclarBulkConfirm = function (hostId, hostLabel) {
        const ids = Object.keys(window._auxSelectedMap || {});
        if (!ids.length) return;
        if (!confirm(`Anclar ${ids.length} auxiliar(es) al host ${hostLabel}?`)) return;
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
    window.openAuxAnclajesModal = function () {
        let modal = document.getElementById('auxAnclajesModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'auxAnclajesModal';
            modal.className = 'modal-overlay';
            modal.style.zIndex = '10000';
            modal.innerHTML = `
                <div class="modal-content" style="width:90%; max-width:800px; max-height:90vh; background:#fff; border-radius:12px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div style="background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%); padding:15px 20px; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="background:rgba(255,255,255,0.1); padding:8px; border-radius:8px;"><i class="material-icons" style="color:#fff; font-size:20px;">link</i></div>
                            <h3 style="margin:0; color:#fff; font-size:16px; font-weight:600;">Anclaje de Auxiliares</h3>
                        </div>
                        <button type="button" onclick="document.getElementById('auxAnclajesModal').classList.remove('active')" style="background:transparent; border:none; color:#94a3b8; cursor:pointer; display:flex; padding:4px;">
                            <i class="material-icons">close</i>
                        </button>
                    </div>
                    <div id="auxAnclajesLoading" style="padding:40px; text-align:center; color:#64748b;">
                        <i class="material-icons" style="font-size:32px; animation:fleetSpin 1s linear infinite;">refresh</i>
                        <p style="margin-top:10px; font-size:14px;">Cargando anclajes...</p>
                    </div>
                    <div id="auxAnclajesBody" style="display:none; padding:14px 16px; overflow-y:auto; flex:1; background:#f8fafc;">
                        <div id="auxAnclajesGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:10px;"></div>
                    </div>
                </div>`;
            document.body.appendChild(modal);
        }
        modal.classList.add('active');
        document.getElementById('auxAnclajesLoading').style.display = 'block';
        document.getElementById('auxAnclajesBody').style.display = 'none';

        fetch('{{ route("equipos-auxiliares.index") }}?anchored=1', {
            headers: { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
        .then(data => {
            const list = Array.isArray(data.data) ? data.data : (Array.isArray(data) ? data : []);
            const anchored = list.filter(a => a.ID_EQUIPO_HOST || a.id_equipo_host || a.host_id);
            document.getElementById('auxAnclajesLoading').style.display = 'none';
            document.getElementById('auxAnclajesBody').style.display = 'block';
            const grid = document.getElementById('auxAnclajesGrid');
            if (anchored.length === 0) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:30px; color:#94a3b8; background:#fff; border-radius:8px; border:1px dashed #cbd5e1;">No hay auxiliares anclados actualmente.</div>';
                return;
            }
            grid.innerHTML = anchored.map(a => {
                const tipo = (a.TIPO || a.tipo || 'Auxiliar').toString().toUpperCase();
                const marca = a.MARCA || a.marca || '';
                const modelo = a.MODELO || a.modelo || '';
                const serial = a.SERIAL || a.serial || '';
                const hostCodigo = a.host_codigo || a.HOST_CODIGO || '#' + (a.ID_EQUIPO_HOST || a.host_id);
                const hostPlaca = a.host_placa || a.HOST_PLACA || '';
                return `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px; display:flex; flex-direction:column; gap:6px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
                    <div style="display:flex; align-items:center; gap:8px; padding:6px 8px; background:#f8fafc; border-radius:6px;">
                        <div style="background:#fff7ed; padding:5px; border-radius:5px; display:flex;"><i class="material-icons" style="font-size:14px; color:#f59e0b;">construction</i></div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:9px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.4px;">${tipo}</div>
                            <div style="font-size:12px; font-weight:800; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${serial || (marca + ' ' + modelo).trim() || '—'}</div>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:center; height:14px; position:relative;">
                        <div style="position:absolute; inset:0 calc(50% - 1px); background:#e2e8f0; width:1px; margin:0 auto;"></div>
                        <div style="background:#dbeafe; width:18px; height:18px; border-radius:50%; color:#2563eb; z-index:2; border:2px solid #fff; display:flex; align-items:center; justify-content:center; position:relative;"><i class="material-icons" style="font-size:10px; transform:rotate(90deg);">link</i></div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; padding:6px 8px; background:#f8fafc; border-radius:6px;">
                        <div style="background:#eff6ff; padding:5px; border-radius:5px; display:flex;"><i class="material-icons" style="font-size:14px; color:#1e40af;">directions_car</i></div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:9px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.4px;">EQUIPO HOST</div>
                            <div style="font-size:12px; font-weight:800; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${hostPlaca || hostCodigo}</div>
                        </div>
                    </div>
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
@endsection
