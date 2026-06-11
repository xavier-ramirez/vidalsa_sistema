@extends('layouts.estructura_base')
@section('title', 'Gráficos de Consumibles')

@section('content')
    <style>
        .g-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }

        .g-grid-1 {
            margin-bottom: 20px;
        }

        .g-card {
            background: #fff;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .06), 0 8px 24px rgba(0, 0, 0, .06);
        }

        .g-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .g-title i {
            font-size: 18px;
            color: #0067b1;
        }

        .g-subtitle {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 400;
            margin-left: 4px;
        }

        .btn-green {
            background: linear-gradient(135deg, #059669, #047857);
        }

        @media (max-width: 900px) {
            .g-grid-2 {
                grid-template-columns: 1fr;
            }

            .acciones-wrapper {
                flex: 1 1 100%;
                min-width: 100%;
            }

            #btnAcciones {
                width: 100%;
                justify-content: center;
            }

            #splitDropdownMenu {
                width: 100%;
                min-width: 100%;
            }

            .hide-on-mobile {
                display: none !important;
            }

            /* Forzar el ancho completo en mobile, reduciendo el padding del viewport */
            body:has(.graficos-layout) .main-viewport {
                padding-left: 8px !important;
                padding-right: 8px !important;
                width: 100% !important;
                max-width: 100% !important;
            }

            .admin-card {
                padding: 15px !important;
            }

            /* Centrar el titulo solo en mobile */
            .graficos-header {
                justify-content: center !important;
            }
            .graficos-title-wrapper {
                text-align: center;
                width: 100%;
            }
        }
        /* Ranking "Total de Consumo por Frente" - compactar en pantallas chicas */
        @media (max-width: 640px) {
            .g-card { padding: 12px !important; }
            .g-title { font-size: 12px !important; }
            .g-title i { font-size: 15px !important; }
            .frow {
                display: flex !important;
                flex-wrap: wrap !important;
                align-items: center !important;
                gap: 4px !important;
                padding: 6px 0 !important;
            }
            .frow-num  { flex: 0 0 16px !important; font-size: 9.5px !important; }
            .frow-name { flex: 1 !important; min-width: 100px !important; font-size: 9.5px !important; line-height: 1.1 !important; }
            .frow-val  { flex: 0 0 auto !important; font-size: 10px !important; padding-left: 2px !important; text-align: right !important; }
            .frow-dep  { display: inline-block !important; margin-left: 4px !important; font-size: 8.5px !important; }
            .frow-bar-wrap { flex: 0 0 100% !important; height: 5px !important; margin-top: 2px !important; }
        }

        @media (max-width: 420px) {
            .g-card { padding: 10px !important; }
            .g-title { font-size: 11px !important; }
            .frow {
                padding: 5px 0 !important;
            }
            .frow-num  { font-size: 8.5px !important; }
            .frow-name { font-size: 9px !important; }
            .frow-val  { font-size: 9px !important; }
            .frow-dep  { font-size: 8px !important; }
        }

        /* Tarjetas resumen */
        .resumen-grid {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .resumen-card {
            flex: 1;
            min-width: 150px;
            background: linear-gradient(135deg, #1e293b, #0f172a);
            border-radius: 14px;
            padding: 16px 18px;
            color: #fff;
        }

        /* Tarjetas resumen — estilos internos manejados por JS */

        /* Ranking frentes — grid de 4 columnas, valor auto-ancho para alinear */
        .frow {
            display: grid;
            grid-template-columns: 24px 190px 1fr auto;
            align-items: center;
            gap: 8px;
            padding: 7px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .frow:last-child {
            border: none;
        }

        .frow-num {
            font-size: 11px;
            color: #94a3b8;
            font-weight: 700;
            text-align: center;
        }

        .frow-name {
            font-weight: 700;
            font-size: 12px;
            color: #1e293b;
            word-break: break-word;
            line-height: 1.3;
        }

        .frow-bar-wrap {
            background: #f1f5f9;
            border-radius: 20px;
            height: 10px;
            overflow: hidden;
        }

        .frow-bar {
            height: 100%;
            border-radius: 20px;
            transition: width .6s;
        }

        .frow-val {
            text-align: left;
            font-weight: 800;
            font-size: 13px;
            color: #003a70;
            white-space: nowrap;
            padding-left: 4px;
        }

        .frow-dep {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: #0077cc;
            margin-top: 1px;
        }

        /* Ranking "Top 20" — BOARD horizontal: cada FRENTE es una COLUMNA (cabecera arriba
           + sus equipos apilados debajo). Las columnas van lado a lado; si no caben, hay
           scroll horizontal. La ubicación del frente vive en la cabecera (.eq-frente-head),
           fuera de las tarjetas, que son compactas para que entren la mayor cantidad. */
        .eq-board { display:flex; gap:12px; overflow-x:auto; align-items:flex-start; padding-bottom:6px; }
        .eq-frente-group { flex:0 0 190px; min-width:190px; display:flex; flex-direction:column; gap:8px; }
        .eq-frente-head { display:flex; flex-direction:column; gap:2px; padding:6px 10px;
                          background:#eef2f7; border-radius:8px; border-left:4px solid #0067b1; }
        .eq-frente-name { font-size:12px; font-weight:800; color:#1e293b;
                          white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .eq-frente-meta { font-size:11px; font-weight:700; color:#0077cc; }
        .eq-col-cards { display:flex; flex-direction:column; gap:8px; }
        /* .eq-grid la usa renderInoperativos (otra sección que COMPARTE .eq-card/.eq-tipo/
           .eq-modelo). El ranking ya NO la usa (pasó a board por columnas), pero NO se
           elimina: rompería la grilla de inoperativos. */
        .eq-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:8px; }
        .eq-card { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
                   padding:5px 8px; transition:box-shadow .2s; }
        .eq-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.08); }
        .eq-tipo { font-size:9px; font-weight:700; color:#0067b1; text-transform:uppercase;
                   letter-spacing:.4px; margin-bottom:0px; }
        .eq-modelo { font-size:12px; font-weight:700; color:#1e293b; margin-bottom:2px;
                     white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        /* Monospace SOLO en las tarjetas del ranking (no en inoperativos, que comparte .eq-modelo). */
        .eq-col-cards .eq-modelo { font-family:monospace; letter-spacing:.5px; }
        .eq-total { font-size:17px; font-weight:800; color:#003a70; line-height:1; }
        .eq-unidad{ font-size:10px; color:#94a3b8; margin-left:3px; }
        .eq-desp  { font-size:11px; font-weight:700; color:#0077cc; margin-top:2px; }
        .eq-bar   { margin-top:4px; background:#e2e8f0; border-radius:20px; height:4px; overflow:hidden; }
        .eq-bar-fill { height:100%; border-radius:20px; transition:width .6s; }
        .eq-desp-badge { display:inline-flex; align-items:center; gap:4px; background:#003a70;
                         color:#fff; border-radius:20px; padding:2px 10px; font-size:12px; font-weight:700; }

        .loading-overlay {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 180px;
            color: #94a3b8;
            font-size: 13px;
            gap: 8px;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg)
            }

            to {
                transform: rotate(360deg)
            }
        }
    </style>

    {{-- HEADER --}}
    <div class="graficos-layout graficos-header"
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
        <div class="graficos-title-wrapper">
            <h1 class="page-title" style="margin:0;">
                <span class="page-title-line2" style="color:#000;">Análisis de Consumibles</span>
            </h1>
        </div>
    </div>

    <div class="admin-card" style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 20px 25px; margin-bottom: 20px;">
        {{-- FILTROS --}}
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:center;">
            <div style="flex: 1; min-width: 180px;">
                <div class="custom-dropdown" id="frenteFilterSelect" data-filter-type="frente"
                    data-default-label="Todos los frentes">
                    <input type="hidden" id="fFrente" data-filter-value value="">

                    <div class="dropdown-trigger"
                        style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px;">
                        <div
                            style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text, #94a3b8);">
                            <i class="material-icons" style="font-size:18px;">search</i>
                        </div>
                        <input type="text" id="graficos_frente_search" name="graficos_frente_search" data-filter-search
                            placeholder="Todos los frentes"
                            style="width:100%; border:none; background:transparent; padding:0 5px; font-size:13px; outline:none; height:100%;"
                            oninput="window.filterDropdownOptions(this)" autocomplete="off">
                        <i class="material-icons" data-clear-btn
                            style="padding:0 5px; color:var(--maquinaria-gray-text, #94a3b8); font-size:18px; display:none; cursor:pointer;"
                            onclick="event.stopPropagation(); window.clearDropdownFilter('frenteFilterSelect'); setTimeout(window.cargarDatos, 50);">close</i>
                    </div>

                    <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                        <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                            <div class="dropdown-item selected" data-value=""
                                onclick="window.selectOption('frenteFilterSelect', '', 'Todos los frentes'); window.cargarDatos();">
                                Todos los frentes
                            </div>
                            @foreach($frentes as $f)
                                <div class="dropdown-item" data-value="{{ $f->ID_FRENTE }}"
                                    onclick="window.selectOption('frenteFilterSelect', '{{ $f->ID_FRENTE }}', '{{ $f->NOMBRE_FRENTE }}'); window.cargarDatos();">
                                    {{ $f->NOMBRE_FRENTE }}
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div style="flex: 1; min-width: 140px;">
                <div class="custom-dropdown" id="tipoFilterSelect" data-filter-type="tipo" data-default-label="Gasoil">
                    <input type="hidden" id="fTipo" data-filter-value value="GASOIL">

                    <div class="dropdown-trigger"
                        style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; cursor:pointer;">
                        <div
                            style="padding:0 10px; display:flex; align-items:center; color:var(--maquinaria-gray-text, #94a3b8);">
                            <i class="material-icons" style="font-size:18px;">local_gas_station</i>
                        </div>
                        <input type="text" id="graficos_tipo_search" name="graficos_tipo_search" data-filter-search readonly
                            value="Gasoil"
                            style="width:100%; border:none; background:transparent; padding:0 5px; font-size:13px; outline:none; height:100%; cursor:pointer;"
                            autocomplete="off" placeholder="Caucho">
                        <i class="material-icons"
                            style="padding:0 10px; color:var(--maquinaria-gray-text, #94a3b8); font-size:18px;">arrow_drop_down</i>
                    </div>

                    <div class="dropdown-content" style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                        <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                            @foreach(\App\Models\Consumible::tiposLabel() as $v => $l)
                                @if(!in_array($v, ['REFRIGERANTE', 'OTRO']))
                                    <div class="dropdown-item {{ $v == 'GASOIL' ? 'selected' : '' }}" data-value="{{ $v }}"
                                        onclick="window.selectOption('tipoFilterSelect', '{{ $v }}', '{{ $l }}'); window.cargarDatos();">
                                        {{ $l }}
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div style="position: relative;">
                <button type="button" id="btnAdvancedFilter" class="btn-primary-maquinaria"
                    style="height: 42px; width: 42px; padding: 0; display: flex; align-items: center; justify-content: center; background: white; border: 1px solid #cbd5e0; color: #64748b; box-shadow: none;"
                    onclick="window.toggleAdvancedFilterGraficos(event)">
                    <i class="material-icons">filter_list</i>
                </button>

                <div id="advancedFilterPanel" class="no-close-on-click"
                    style="display: none; position: absolute; top: 100%; right: 0; width: 240px; box-sizing: border-box; background: #e2e8f0; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15); border: 1px solid #cbd5e1; z-index: 1000; margin-top: 10px; padding: 15px;">
                    <h4
                        style="margin: 0 0 15px 0; font-size: 14px; font-weight: 700; color: #334155; display: flex; justify-content: space-between; align-items: center;">
                        Filtros Avanzados
                    </h4>

                    <div style="margin-bottom: 15px;">
                        <span
                            style="display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px;">Período (por mes)</span>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                            {{-- Dos meses: filtra UN mes (mismo valor en ambos, o solo uno) o un
                                 PERÍODO de varios meses (meses distintos). Se traducen a día en getParams. --}}
                            <input type="month" id="fMesDesde" class="native-date" onchange="window.cargarDatos()" title="Mes inicial (desde)"
                                style="width: 100%; box-sizing: border-box; height: 36px; border-radius: 6px; border: 1px solid #cbd5e0; background: #fbfcfd; outline: none; padding: 0 12px; font-size:12px; color: #1e293b; cursor: pointer;"
                                onclick="try{this.showPicker()}catch(e){}">
                            <input type="month" id="fMesHasta" class="native-date" onchange="window.cargarDatos()" title="Mes final (hasta)"
                                style="width: 100%; box-sizing: border-box; height: 36px; border-radius: 6px; border: 1px solid #cbd5e0; background: #fbfcfd; outline: none; padding: 0 12px; font-size:12px; color: #1e293b; cursor: pointer;"
                                onclick="try{this.showPicker()}catch(e){}">
                        </div>
                    </div>

                </div>
            </div>
            <!-- Botón Acciones -->
            <div class="acciones-wrapper" style="position: relative; flex-shrink: 0;">
                <button type="button" id="btnAcciones"
                    onclick="window.toggleAccionesMenuGraficos(event)"
                    class="btn-primary-maquinaria"
                    style="padding: 0 15px; height: 42px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
                    <i class="material-icons">settings</i>
                    <span>Acciones</span>
                    <i class="material-icons" style="font-size: 18px; margin-left: 2px;">expand_more</i>
                </button>
                <div id="splitDropdownMenu"
                    style="display: none; position: absolute; top: 100%; right: 0; min-width: 260px; background: #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; z-index: 1050; margin-top: 10px; overflow: hidden;">

                    {{-- Navegación Estándar --}}
                    <a href="{{ route('consumibles.index') }}" class="dropdown-item-custom hide-on-mobile"
                        style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; border-bottom: 1px solid #f1f5f9; background: transparent; transition: all 0.2s;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                        <div style="background: #eff6ff; padding: 6px; border-radius: 6px; display: flex;">
                            <i class="material-icons" style="font-size: 18px; color: #3b82f6;">list_alt</i>
                        </div>
                        <span style="font-size:14px; font-weight:500;">Lista de Consumibles</span>
                    </a>

                    <a href="{{ route('consumibles.cargar') }}" class="dropdown-item-custom hide-on-mobile"
                        style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; border-bottom: 1px solid #cbd5e1; background: transparent; transition: all 0.2s;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                        <div style="background: #fff7ed; padding: 6px; border-radius: 6px; display: flex;">
                            <i class="material-icons" style="font-size: 18px; color: #ea580c;">note_add</i>
                        </div>
                        <span style="font-size:14px; font-weight:500;">Cargar Lote (Masivo)</span>
                    </a>

                    {{-- Acciones Locales --}}
                    <a href="#"
                        onclick="document.getElementById('splitDropdownMenu').style.display='none'; descargarExcel(); return false;"
                        class="dropdown-item-custom"
                        style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; transition: all 0.2s; border-bottom: 1px solid #f1f5f9; background: transparent;"
                        onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                        <div style="background: #f1f5f9; padding: 6px; border-radius: 6px; display: flex;">
                            <i class="material-icons" style="font-size: 18px; color: #64748b;">download</i>
                        </div>
                        <span style="font-size: 14px; font-weight: 500;">Exportación de Excel</span>
                    </a>
                </div>
            </div>

        </div>
    </div>
    <script>
        window.toggleAdvancedFilterGraficos = function(event) {
            if(event) { event.preventDefault(); event.stopPropagation(); }
            const ap = document.getElementById('advancedFilterPanel');
            const am = document.getElementById('splitDropdownMenu');
            if (am && am.style.display === 'block') {
                am.style.display = 'none';
            }
            if (ap) {
                ap.style.display = (ap.style.display === 'none' || ap.style.display === '') ? 'block' : 'none';
            }
        };

        window.toggleAccionesMenuGraficos = function(event) {
            if(event) { event.preventDefault(); event.stopPropagation(); }
            const am = document.getElementById('splitDropdownMenu');
            const ap = document.getElementById('advancedFilterPanel');
            if (ap && ap.style.display === 'block') {
                ap.style.display = 'none';
            }
            if (am) {
                am.style.display = (am.style.display === 'none' || am.style.display === '') ? 'block' : 'none';
            }
        };

        if (!window._consumiblesGraficosGlobalClickBound) {
            window._consumiblesGraficosGlobalClickBound = true;
            document.addEventListener('click', function (e) {
                let accMenu = document.getElementById('splitDropdownMenu');
                let btnAcciones = document.getElementById('btnAcciones');
                if (accMenu && accMenu.style.display === 'block') {
                    if (!accMenu.contains(e.target) && btnAcciones && !btnAcciones.contains(e.target)) {
                        accMenu.style.display = 'none';
                    }
                }
                let advMenu = document.getElementById('advancedFilterPanel');
                let btnAdvanced = document.getElementById('btnAdvancedFilter');
                if (advMenu && advMenu.style.display === 'block') {
                    if (!advMenu.contains(e.target) && btnAdvanced && !btnAdvanced.contains(e.target)) {
                        advMenu.style.display = 'none';
                    }
                }
            });
        }
    </script>

    {{-- TARJETAS RESUMEN --}}
    <div class="resumen-grid" id="resumenGrid">
        <div class="loading-overlay" style="width:100%;">
            <i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i> Cargando...
        </div>
    </div>

    {{-- TOTAL POR FRENTE --}}
    <div class="g-grid-1" id="totalFrenteWrapper">
        <div class="g-card" id="panelTotalFrente">
            <p class="g-title" style="justify-content:space-between; flex-wrap: wrap; gap: 8px;">
                <span style="display:flex;align-items:center;gap:8px; min-width: 0; flex: 1;">
                    <i class="material-icons">bar_chart</i>
                    <span id="tituloTotalFrente" style="word-break: break-word;">Total de Consumo por Frente</span>
                </span>
                <button onclick="descargarPanelHtml('panelTotalFrente','consumo_por_frente')" title="Descargar imagen"
                    style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s; flex-shrink: 0;"
                    onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                    <i class="material-icons" style="font-size:17px;">photo_camera</i>
                </button>
            </p>
            <div id="loadingTotalFrente" class="loading-overlay">
                <i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i>
            </div>
            <div id="totalFrenteBody" style="display:none;"></div>
        </div>
    </div>

    {{-- EQUIPOS QUE SURTIERON POR FRENTE (solo con frente seleccionado) --}}
    <div class="g-grid-1" id="secEqFrente" style="display:none;">
        <div class="g-card" id="panelEqFrente">
            <p class="g-title" style="justify-content:space-between; flex-wrap: wrap; gap: 8px;">
                <span style="display:flex;align-items:center;gap:8px; min-width: 0; flex: 1;">
                    <i class="material-icons" style="color:#0067b1; flex-shrink: 0;">local_shipping</i>
                    <span style="word-break: break-word;">Equipos que Surtieron <span class="g-subtitle"
                            id="subtitleEqFrente" style="display:inline-block;">— selecciona un frente</span></span>
                </span>
                <button onclick="descargarGrafico('chartEqFrente','equipos_surtieron')" title="Descargar imagen"
                    style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s; flex-shrink: 0;"
                    onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                    <i class="material-icons" style="font-size:17px;">photo_camera</i>
                </button>
            </p>
            <div id="loadingEqFrente" class="loading-overlay">
                <i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i>
            </div>
            <div style="position: relative; height:320px; width:100%;">
                <canvas id="chartEqFrente" style="display:none;"></canvas>
            </div>
        </div>
    </div>

    {{-- TOP EQUIPOS — GRID DE TARJETAS --}}
    <div class="g-grid-1" id="panelRankingEquipos">
        <div class="g-card">
            <p class="g-title" style="justify-content:space-between;">
                <span style="display:flex;align-items:center;gap:8px;">
                    Top 20 Equipos Mayor Consumo
                </span>
                <button onclick="descargarRanking()" title="Descargar imagen"
                    style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;"
                    onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                    <i class="material-icons" style="font-size:17px;">photo_camera</i>
                </button>
            </p>
            <div id="loadingRanking" class="loading-overlay">
                <i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i>
            </div>
            <div id="rankingBody" style="display:none;"></div>
        </div>
    </div>

    {{-- EQUIPOS INOPERATIVOS EN FRENTE --}}
    <div class="g-grid-1" id="secInoperativos" style="display:none;">
        <div class="g-card">
            <p class="g-title" style="justify-content:space-between;">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="material-icons" style="color:#ef4444;">build_circle</i>
                    Equipos Inoperativos — Frente Seleccionado
                    <span class="g-subtitle">— registra la causa de inoperatividad</span>
                </span>
                <button onclick="descargarPanelInoperativos()" title="Descargar imagen"
                    style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;"
                    onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                    <i class="material-icons" style="font-size:17px;">photo_camera</i>
                </button>
            </p>
            <div id="loadingInoperativos" class="loading-overlay">
                <i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i>
            </div>
            <div id="inoperativosBody" style="display:none;"></div>
        </div>
    </div>


    {{-- ESPECIFICACION POR FRENTE --}}
    <div class="g-grid-1" id="secEspecFrente" style="display:none;">
        <div class="g-card">
            <p class="g-title" id="titleEspecFrente" style="justify-content:space-between;">
                <span style="display:flex;align-items:center;gap:8px;">
                    <i class="material-icons" id="iconEspecFrente" style="color:#1b5e20;">tire_repair</i>
                    <span><span id="txtEspecFrente">Consumo por Frente — Desglose por Especificación</span> <span
                            class="g-subtitle">— todos los registros</span></span>
                </span>
                <button onclick="descargarPanelEspecFrente('desglose_especificacion')" title="Descargar imagen"
                    style="border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px 8px;border-radius:8px;transition:background .2s;"
                    onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='transparent'">
                    <i class="material-icons" style="font-size:17px;">photo_camera</i>
                </button>
            </p>
            <div id="loadingAceiteFrente" class="loading-overlay">
                <i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i>
            </div>
            <div id="aceiteFrente-body" style="display:none;"></div>
        </div>
    </div>

    {{-- ESPECIFICACION POR EQUIPO --}}
    <div class="g-grid-1" id="secEspecEquipo" style="display:none;">
        <div class="g-card">
            <p class="g-title" id="titleEspecEquipo">
                <i class="material-icons" id="iconEspecEquipo" style="color:#0067b1;">settings</i>
                <span id="txtEspecEquipo">Consumo por Equipo — Desglose por Especificación</span>
                <span class="g-subtitle">— solo equipos identificados</span>
            </p>
            <div id="loadingAceiteEquipo" class="loading-overlay">
                <i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i>
            </div>
            <div id="aceiteEquipoBody" style="display:none; overflow-x:auto;"></div>
        </div>
    </div>


    {{-- Auditoria de Catalogo removida: la auditoria de cambios en
         CaracteristicaModelo se accede directamente desde /admin/catalogo
         (cada modelo tiene su historial). El panel saturaba la vista de
         graficos sin aportar contexto de consumo. --}}

    {{-- Chart.js se carga GLOBAL en el layout (estructura_base) — NO se repite aquí.
         El setInterval de abajo espera a que `Chart` exista (venga de donde venga) antes
         de registrar el plugin, así que esta página funciona con la carga global.
         Solo quedan los específicos de esta vista: datalabels (etiquetas) y html2canvas. --}}
    <script src="{{ asset('js/chartjs-plugin-datalabels.min.js') }}"></script>
    <script src="{{ asset('js/html2canvas.min.js') }}"></script>
    <script>
        // Paleta corporativa: variada y profunda
        window.COLORES = window.COLORES || {
            'GASOIL': '#003a70',   // azul marino
            'GASOLINA': '#c41c00',   // rojo intenso
            'ACEITE': '#0077cc',   // azul eléctrico
            'CAUCHO': '#1b5e20',   // verde oscuro
            'REFRIGERANTE': '#00838f',   // teal
            'OTRO': '#546e7a',   // gris azulado
        };
        window.TIPO_LABEL = window.TIPO_LABEL || {
            'GASOIL': 'Gasoil', 'GASOLINA': 'Gasolina', 'ACEITE': 'Aceite',
            'CAUCHO': 'Caucho', 'REFRIGERANTE': 'Refrigerante', 'OTRO': 'Otro'
        };

        window.chartTipoEq = window.chartTipoEq || null;
        window.chartEqFrente = window.chartEqFrente || null;
        // chartCauchoModelo declarado removido junto con el panel de Cauchos.

        if (window.chartCheckInterval) clearInterval(window.chartCheckInterval);
        window.chartCheckInterval = setInterval(() => {
            if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
                Chart.register(ChartDataLabels);
                clearInterval(window.chartCheckInterval);
            }
        }, 100);
        setTimeout(() => clearInterval(window.chartCheckInterval), 5000);

        var COLORES = window.COLORES;
        var TIPO_LABEL = window.TIPO_LABEL;
        var chartTipoEq = window.chartTipoEq;
        var chartEqFrente = window.chartEqFrente;

        function getParams() {
            var p = new URLSearchParams();
            var frente = document.getElementById('fFrente') ? document.getElementById('fFrente').value : '';
            var tipo = document.getElementById('fTipo') ? document.getElementById('fTipo').value : '';
            // Filtro por PERÍODO de meses (inputs type=month → "YYYY-MM"). Cada mes se traduce
            // a día para el backend (que espera desde/hasta): desde = primer día del mes inicial;
            // hasta = último día del mes final. Independientes: un solo mes = mismo valor en
            // ambos (o solo uno); varios meses = meses distintos. Backend sin cambios.
            var mDesde = document.getElementById('fMesDesde') ? document.getElementById('fMesDesde').value : '';
            var mHasta = document.getElementById('fMesHasta') ? document.getElementById('fMesHasta').value : '';
            if (frente) p.set('id_frente', frente);
            if (tipo) p.set('tipo', tipo);
            if (mDesde) p.set('desde', mDesde + '-01');
            if (mHasta) {
                var ph = mHasta.split('-');                        // [YYYY, MM]
                var ult = new Date(parseInt(ph[0], 10), parseInt(ph[1], 10), 0).getDate();
                p.set('hasta', mHasta + '-' + (ult < 10 ? '0' + ult : ult));
            }
            return p;
        }

        window.clearAdvancedFilters = function () {
            var d = document.getElementById('fMesDesde'); if (d) d.value = '';
            var h = document.getElementById('fMesHasta'); if (h) h.value = '';
        };

        function show(id) { document.getElementById(id).style.display = ''; }
        function hide(id) { document.getElementById(id).style.display = 'none'; }
        function canvas(id) { document.getElementById(id).style.display = 'block'; }

        window.cargarDatos = function () {
            if (window._cargarDatosTimer) clearTimeout(window._cargarDatosTimer);
            window._cargarDatosTimer = setTimeout(_cargarDatosLocal, 250);
        };

        function _cargarDatosLocal() {
            // Preloader global SIEMPRE durante la carga (inicial y subsecuente).
            // Antes confiabamos en el spinner del SPA solo para la primera carga,
            // pero el SPA spinner se apaga al recibir el HTML inicial — los
            // gráficos (Chart.js) se renderizan despues, dejando ver canvas
            // vacios y placeholders. showPreloader() es idempotente: si ya esta
            // visible (del SPA) no causa flash; si no, lo enciende. hidePreloader()
            // solo se llama en el finally cuando los charts ya estan pintados.
            // Solo mostrar preloader global en recargas por filtro (no en la primera carga).
            // La primera carga la maneja el SPA; si llamamos showPreloader() aqui tambien
            // provoca un doble-flash (spinner aparece, desaparece y vuelve a aparecer).
            if (window._graficosFirstRunDone && typeof window.showPreloader === 'function') {
                window.showPreloader();
            }
            const params = getParams();

            // ── Loading: mostrar spinners de las secciones siempre visibles ──
            ['loadingTotalFrente', 'loadingRanking', 'loadingInoperativos'].forEach(show);

            // ── Ocultar contenido previo (prev carga) ────────────────────────
            ['totalFrenteBody', 'rankingBody', 'inoperativosBody'].forEach(hide);

            // ── Secciones de especificación: ocultar antes de cada carga ─────
            // Evita que queden datos viejos visibles durante la nueva carga.
            hide('secEspecFrente');
            hide('secEspecEquipo');
            hide('secInoperativos');
            hide('aceiteFrente-body');
            hide('aceiteEquipoBody');

            document.getElementById('resumenGrid').innerHTML =
                '<div class="loading-overlay" style="width:100%;"><i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i> Cargando...</div>';

            fetch(`{{ route('consumibles.graficosData') }}?${params}`)
                .then(r => r.json())
                .then(data => {
                    const frenteSeleccionado = document.getElementById('fFrente').value;
                    const tipoFiltro = document.getElementById('fTipo').value;

                    renderResumen(data.resumen);

                    if (tipoFiltro === 'GASOIL') {
                        document.getElementById('totalFrenteWrapper').style.display = 'block';
                        renderTotalFrente(data.por_frente);
                    } else {
                        document.getElementById('totalFrenteWrapper').style.display = 'none';
                    }
                    // Solo mostrar equipos×frente cuando hay un frente específico seleccionado
                    if (frenteSeleccionado) {
                        document.getElementById('secEqFrente').style.display = '';
                        renderEquiposPorFrente(data.equipos_por_frente, frenteSeleccionado);
                    } else {
                        document.getElementById('secEqFrente').style.display = 'none';
                    }
                    if (frenteSeleccionado) {
                        document.getElementById('secInoperativos').style.display = '';
                        const fname = (data.inoperativos && data.inoperativos.length > 0)
                            ? data.inoperativos[0].frente_nombre
                            : document.querySelector('#frenteFilterSelect [data-filter-search]')?.placeholder;
                        renderInoperativos(data.inoperativos || [], fname || 'Frente Seleccionado');
                    } else {
                        document.getElementById('secInoperativos').style.display = 'none';
                    }
                    renderRanking(data.top_equipos);

                    renderEspecFrente(data.espec_frente, data.tipo_activo);
                    renderEspecEquipo(data.espec_equipo, data.tipo_activo);
                })
                .catch(err => {
                    console.error('Error cargando datos de gráficos:', err);
                    ['loadingTotalFrente', 'loadingRanking', 'loadingInoperativos'].forEach(id => {
                            const el = document.getElementById(id);
                            if (el) el.innerHTML = '<span style="color:#ef4444;">Error al cargar datos</span>';
                        });
                })
                .finally(() => {
                    // Apagar preloader DESPUES de que charts y DOM esten pintados.
                    // Chart.js no expone un hook "termine de pintar" deterministico —
                    // 2x requestAnimationFrame era insuficiente con muchos canvas.
                    // Sumamos un buffer de 300ms para que canvas + datalabels +
                    // animaciones iniciales tengan tiempo de salir en pantalla.
                    const _afterPaint = () => {
                        setTimeout(() => {
                            if (typeof window.hidePreloader === 'function') window.hidePreloader();
                            window._graficosFirstRunDone = true;
                        }, 300);
                    };
                    requestAnimationFrame(() => requestAnimationFrame(_afterPaint));
                });
        }

        // Exponer cargarDatos globalmente para el ModuleManager SPA
        // window.cargarDatos = window.cargarDatos; (Ya asignado arriba)

        // ── Tarjetas resumen ───────────────────────────────────────────────
        function renderResumen(datos) {
            const grid = document.getElementById('resumenGrid');
            if (!datos || datos.length === 0) {
                grid.innerHTML = '<p style="color:#94a3b8;font-size:13px;padding:10px;">Sin datos con los filtros aplicados.</p>';
                return;
            }
            grid.innerHTML = datos.map(d => {
                return `
            <div class="resumen-card" style="position:relative; padding-right:40px;">
                <button onclick="descargarPanelResumen('resumen_general')" title="Descargar imagen"
                    style="position:absolute; top:8px; right:8px; border:none;background:transparent;cursor:pointer;color:#94a3b8;display:flex;align-items:center;padding:4px;border-radius:8px;transition:background .2s;"
                    onmouseover="this.style.background='rgba(255,255,255,0.1)'" onmouseout="this.style.background='transparent'">
                    <i class="material-icons" style="font-size:17px;">photo_camera</i>
                </button>
                <div style="display:flex; align-items:baseline; gap:8px; flex-wrap:wrap; line-height:1.2;">
                    <span style="font-size:28px; font-weight:800; color:#fff;">
                        ${parseFloat(d.total).toLocaleString('es-VE', { minimumFractionDigits: 0, maximumFractionDigits: 1 })}
                    </span>
                    <span style="font-size:12px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px;">
                        ${d.unidad} · ${TIPO_LABEL[d.TIPO_CONSUMIBLE] || d.TIPO_CONSUMIBLE}
                    </span>
                </div>
                <div style="font-size:11px; color:#64748b; margin-top:6px;">
                    ${d.registros} despachos · ${d.equipos_distintos} eq
                </div>
            </div>`;
            }).join('');
        }

        // ── Total por frente (barras horizontales) ───────────────────────────────
        function renderTotalFrente(datos) {
            const searchInput = document.querySelector('#frenteFilterSelect [data-filter-search]');
            const nombreFrente = searchInput ? searchInput.placeholder : '';
            const hayFrenteSeleccionado = !!document.getElementById('fFrente').value;
            const tituloEl = document.getElementById('tituloTotalFrente');
            if (tituloEl) {
                tituloEl.textContent = hayFrenteSeleccionado
                    ? `Consumo — ${nombreFrente}`
                    : 'Total de Consumo por Frente';
            }
            hide('loadingTotalFrente');
            const body = document.getElementById('totalFrenteBody');
            body.style.display = 'block';
            if (!datos || datos.length === 0) {
                body.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px;">Sin datos.</p>';
                return;
            }


            const mapaTotal = {};
            const mapaDespachos = {};
            const mapaUnidad = {};
            datos.forEach(d => {
                mapaTotal[d.NOMBRE_FRENTE] = (mapaTotal[d.NOMBRE_FRENTE] || 0) + parseFloat(d.total);
                mapaDespachos[d.NOMBRE_FRENTE] = (mapaDespachos[d.NOMBRE_FRENTE] || 0) + parseInt(d.despachos || 0);
                if (!mapaUnidad[d.NOMBRE_FRENTE]) mapaUnidad[d.NOMBRE_FRENTE] = d.unidad || '';
            });
            const ordenado = Object.entries(mapaTotal).sort((a, b) => b[1] - a[1]);
            const maxVal = ordenado[0]?.[1] || 1;
            const n = ordenado.length;

            body.innerHTML = ordenado.map(([frente, total], i) => {
                const pct = (total / maxVal * 100).toFixed(1);
                const dep = mapaDespachos[frente] || 0;
                const unidad = mapaUnidad[frente] || '';
                let color;
                if (i === 0) {
                    color = 'linear-gradient(90deg,#7f1d1d,#b91c1c)';
                } else if (i === 1) {
                    color = 'linear-gradient(90deg,#b91c1c,#ef4444)';
                } else {
                    const blueIdx = i - 2;
                    const blueN = Math.max(n - 2, 1);
                    const lightA = Math.round(22 + (28 * blueIdx / blueN));
                    const lightB = Math.round(32 + (22 * blueIdx / blueN));
                    const satA = Math.round(82 - (18 * blueIdx / blueN));
                    color = `linear-gradient(90deg,hsl(213,${satA}%,${lightA}%),hsl(213,${satA - 5}%,${lightB}%))`;
                }
                return `
            <div class="frow">
                <span class="frow-num">#${i + 1}</span>
                <span class="frow-name" title="${frente}">${frente}</span>
                <div class="frow-bar-wrap">
                    <div class="frow-bar" style="width:${pct}%; background:${color};"></div>
                </div>
                <span class="frow-val">
                    ${total.toLocaleString('es-VE', { minimumFractionDigits: 0, maximumFractionDigits: 1 })} ${unidad}
                    <span class="frow-dep">&#9981; ${dep} llenado${dep !== 1 ? 's' : ''}</span>
                </span>
            </div>`;
            }).join('');
        }







        // Paleta armoniosa para cauchos — misma en badges y gráfico de barras
        window.PALETA_CAUCHO_GLOBAL = [
            '#1b5e20', // verde bosque
            '#0d47a1', // azul marino
            '#e65100', // naranja quemado
            '#4a148c', // púrpura oscuro
            '#006064', // teal oscuro
            '#b71c1c', // rojo oscuro
            '#37474f', // gris azulado
            '#4e342e', // marrón cacao
            '#1a237e', // azul índigo
            '#827717', // oliva
        ];

        // renderCauchosPorModelo + chartCauchoModelo removidos: el panel
        // "Cauchos por Tipo de Equipo y Medida" se elimino del modulo.

        // ── Equipos individuales que surtieron en el frente seleccionado ────
        function renderEquiposPorFrente(datos, frenteId) {
            const loadEl = document.getElementById('loadingEqFrente');
            const canvEl = document.getElementById('chartEqFrente');

            // Actualizar subtítulo
            const searchInput = document.querySelector('#frenteFilterSelect [data-filter-search]');
            const nombre = searchInput ? searchInput.placeholder : 'Frente';
            document.getElementById('subtitleEqFrente').textContent = `— equipos en: ${nombre}`;

            // Siempre reset: mostrar spinner, ocultar canvas
            loadEl.innerHTML = '<i class="material-icons" style="animation:spin 1s linear infinite;">refresh</i>';
            loadEl.style.display = 'flex';
            canvEl.style.display = 'none';
            try { if (window.chartEqFrente) { window.chartEqFrente.destroy(); window.chartEqFrente = null; } } catch (e) { }

            if (!datos || datos.length === 0) {
                loadEl.innerHTML = '<span style="color:#94a3b8;font-size:13px;">Sin equipos identificados en este frente.</span>';
                return;
            }

            const PALETA_EQ = ['#003a70', '#c41c00', '#0077cc', '#7b1fa2', '#e65100', '#1b5e20', '#00838f', '#546e7a', '#f57f17', '#4a148c'];

            // Agrupar por equipo individual — key: ID_EQUIPO para no mezclar equipos del mismo tipo
            const equiposMap = new Map();
            datos.forEach(d => {
                // Identificador para el tooltip: placa preferida, sino serial
                const idTooltip = (d.CODIGO_PATIO && d.CODIGO_PATIO.trim())
                    ? d.CODIGO_PATIO.trim()
                    : (d.SERIAL_CHASIS && d.SERIAL_CHASIS.trim())
                        ? d.SERIAL_CHASIS.trim()
                        : '—';
                const key = d.ID_EQUIPO || idTooltip;
                if (!equiposMap.has(key)) {
                    equiposMap.set(key, {
                        tipo: d.tipo_equipo || 'S/T',   // ← label en el eje X
                        idLabel: idTooltip,                 // ← placa o serial (tooltip)
                        modelo: d.MODELO || '',            // ← modelo (tooltip)
                        total: 0,
                        desp: 0,
                        unidad: d.unidad,
                    });
                }
                const eq = equiposMap.get(key);
                eq.total += parseFloat(d.total);
                eq.desp += parseInt(d.despachos || 0);
            });

            const equipos = [...equiposMap.values()];
            // Ordenar de mayor a menor consumo
            equipos.sort((a, b) => b.total - a.total);

            // Color consistente por tipo de equipo
            const tiposUnicos = [...new Set(equipos.map(e => e.tipo))];
            const colorPorTipo = {};
            tiposUnicos.forEach((t, i) => { colorPorTipo[t] = PALETA_EQ[i % PALETA_EQ.length]; });

            // Si hay tipos repetidos, añadir sufijo ordinal (Camión #1, Camión #2…)
            const countPorTipo = {};
            equipos.forEach(e => countPorTipo[e.tipo] = (countPorTipo[e.tipo] || 0) + 1);

            const contadorIdx = {};
            const labelsFinal = equipos.map(e => {
                if (countPorTipo[e.tipo] === 1) return e.tipo;
                contadorIdx[e.tipo] = (contadorIdx[e.tipo] || 0) + 1;
                return `${e.tipo} #${contadorIdx[e.tipo]}`;
            });

            const values = equipos.map(e => e.total);
            const colors = equipos.map(e => colorPorTipo[e.tipo] || '#94a3b8');

            // Mostrar canvas y ocultar spinner
            loadEl.style.display = 'none';
            canvEl.style.display = 'block';

            let retriesE = 0;
            const drawE = () => {
                if (typeof Chart === 'undefined') {
                    if (retriesE++ < 50) setTimeout(drawE, 100);
                    return;
                }
                try { if (window.chartEqFrente) { window.chartEqFrente.destroy(); window.chartEqFrente = null; } } catch (e) { }
                try { const existingE = Chart.getChart(canvEl); if (existingE) existingE.destroy(); } catch (e) { }

                try {
                    window.chartEqFrente = new Chart(canvEl, {
                        type: 'bar',
                        data: {
                            labels: labelsFinal,
                            datasets: [{
                                label: nombre,
                                data: values,
                                backgroundColor: colors,
                                borderRadius: 6,
                                borderSkipped: false,
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            layout: { padding: { top: 22 } },
                            plugins: {
                                legend: { display: false },
                                datalabels: {
                                    anchor: 'end', align: 'end',
                                    color: '#1e293b',
                                    font: { size: 10, weight: '700' },
                                    formatter: v => v > 0 ? v.toLocaleString('es-VE', { maximumFractionDigits: 1 }) : '',
                                    clip: false,
                                },
                                tooltip: {
                                    callbacks: {
                                        title: ctx => {
                                            const eq = equipos[ctx[0].dataIndex];
                                            return `🎦 ${eq.idLabel}`;
                                        },
                                        label: ctx => {
                                            const eq = equipos[ctx.dataIndex];
                                            return ` ⛽ ${eq.desp} despacho${eq.desp !== 1 ? 's' : ''}`;
                                        },
                                        afterLabel: () => undefined,
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: { font: { size: 11, weight: '600' } }
                                },
                                y: { beginAtZero: true, grid: { color: '#f1f5f9' } }
                            }
                        }
                    });
                } catch (e) { console.error(e); }
            };
            drawE();
        }

        // ── Equipos Inoperativos ────────────────────────────────────────────────
        window.renderInoperativos = function (datos, frente) {
            const loadEl = document.getElementById('loadingInoperativos');
            if (loadEl) loadEl.style.display = 'none';

            const box = document.getElementById('inoperativosBody');
            box.style.display = 'block';

            if (!datos || datos.length === 0) {
                box.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;">No hay equipos inoperativos registrados en este frente.</p>';
                return;
            }

            box.innerHTML = `<div class="eq-grid">${datos.map(d => {
                const placa = (d.PLACA && d.PLACA !== 'S/P' && d.PLACA !== '') ? d.PLACA : d.SERIAL_CHASIS;
                const fotoRow = d.FOTO_REFERENCIAL || d.FOTO_EQUIPO;
                let fotoHtml = '';
                if (fotoRow) {
                    const driveId = fotoRow.replace(/^.*\/storage\/google\//, '').split('?')[0];
                    fotoHtml = `<img src="/storage/google/${driveId}" style="width:100%;height:100%;object-fit:cover;">`;
                } else {
                    fotoHtml = `<i class="material-icons" style="color:#cbd5e0;font-size:32px;">directions_car</i>`;
                }

                const total = d.total ? parseFloat(d.total).toLocaleString('es-VE') : '0';
                const despachos = d.despachos || 0;
                const unidad = d.unidad || 'LITROS';

                return `
                <div class="eq-card" style="border-left:4px solid #ef4444; background-color:#f2f2f2; display:flex; flex-direction:column; gap:6px; padding:10px 12px;">
                    <div style="display:flex; gap:10px; align-items:flex-start;">
                        <div style="width:40px; height:40px; border-radius:6px; background:#e2e8f0; overflow:hidden; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                            ${fotoHtml}
                        </div>
                        <div style="overflow:hidden; flex:1; min-width:0;">
                            <div class="eq-tipo" style="font-size:11px;">${d.tipo}</div>
                            <div class="eq-modelo" style="font-size:12px;">${placa}</div>
                            <div style="font-size:10px; color:#475569; font-weight:600; margin-top:2px; display:flex; align-items:flex-start; gap:2px;">
                                <i class="material-icons" style="font-size:11px; flex-shrink:0; margin-top:1px;">place</i>
                                <span style="word-break:break-word; line-height:1.3;">${d.frente_nombre || frente}</span>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top:2px; border-top:1px dashed #cbd5e0; padding-top:6px; text-align:left;">
                        <div style="font-size:10px; color:#94a3b8; font-weight:700;">CAUSA DE INOPERATIVIDAD:</div>
                        <div style="width:100%; height:20px; border-bottom:1px solid #94a3b8;"></div>
                        <div style="width:100%; height:20px; border-bottom:1px solid #94a3b8;"></div>
                    </div>
                </div>`;
            }).join('')
                }</div>`;
        };

        window.descargarPanelInoperativos = function () {
            capturaPanelHtml('secInoperativos', 'equipos_inoperativos');
        };


        // ── TOP EQUIPOS — Grid de tarjetas compactas ───────────────────────
        window._rankingData = window._rankingData || [];
        var _rankingData = window._rankingData;
        function renderRanking(datos) {
            hide('loadingRanking');
            window._rankingData = datos || [];
            _rankingData = window._rankingData;
            const body = document.getElementById('rankingBody');
            body.style.display = 'block';
            if (!_rankingData.length) {
                body.innerHTML = '<p style="color:#94a3b8;font-size:13px;text-align:center;padding:20px;">Sin datos.</p>';
                return;
            }
            const maxVal = Math.max(..._rankingData.map(d => parseFloat(d.total)));

            // Identificador del equipo: Placa primero, sino Serial, sino Código, sino Modelo.
            const idEquipo = (d) => (d.PLACA && d.PLACA.trim()) ? d.PLACA :
                (d.SERIAL_CHASIS && d.SERIAL_CHASIS.trim()) ? d.SERIAL_CHASIS :
                    (d.CODIGO_PATIO && d.CODIGO_PATIO.trim()) ? d.CODIGO_PATIO :
                        (d.MODELO || 'S/ID');

            // Tarjeta COMPACTA por equipo — SIN el frente adentro (ahora va de cabecera del
            // grupo). El color de la barra va por magnitud relativa al máximo global.
            const tarjeta = (d) => {
                const total = parseFloat(d.total);
                const pct = maxVal > 0 ? (total / maxVal * 100) : 0;   // numérico (para color y ancho)
                const desp = d.despachos || 0;
                // Escala de calor VIVA por magnitud (rojo = mayor consumo → verde = menor).
                const barColor = pct >= 70 ? '#ef4444' : pct >= 45 ? '#f97316' : pct >= 20 ? '#eab308' : '#22c55e';
                const idTexto = idEquipo(d);
                return `
                <div class="eq-card">
                    <div class="eq-tipo">${d.tipo || 'S/T'}</div>
                    <div class="eq-modelo" title="${idTexto}">${idTexto}</div>
                    <div class="eq-total">${total.toLocaleString('es-VE', { minimumFractionDigits: 0, maximumFractionDigits: 1 })}<span class="eq-unidad">${d.unidad}</span></div>
                    <div class="eq-desp">⛽ ${desp} despacho${desp !== 1 ? 's' : ''}</div>
                    <div class="eq-bar"><div class="eq-bar-fill" style="width:${pct.toFixed(1)}%; background:${barColor};"></div></div>
                </div>`;
            };

            // Agrupar por FRENTE: la ubicación pasa a ser la cabecera del grupo (fuera de las
            // tarjetas), con su total + nº de equipos. Conserva el orden de mayor consumo del
            // backend dentro de cada grupo (los datos ya vienen ordenados desc por total).
            const grupos = {};
            const orden = [];
            _rankingData.forEach((d) => {
                const fr = (d.frente && d.frente.trim()) ? d.frente : 'Sin frente';
                if (!grupos[fr]) { grupos[fr] = { items: [], total: 0, unidad: d.unidad }; orden.push(fr); }
                grupos[fr].items.push(d);
                grupos[fr].total += parseFloat(d.total) || 0;
            });

            // Todas las cabeceras de frente usan el MISMO color (uniforme, vía CSS); no se
            // pinta un acento distinto por frente.
            body.innerHTML = '<div class="eq-board">' + orden.map((fr) => {
                const g = grupos[fr];
                return `
                <div class="eq-frente-group">
                    <div class="eq-frente-head">
                        <span class="eq-frente-name">📍 ${fr}</span>
                        <span class="eq-frente-meta">${g.total.toLocaleString('es-VE', { maximumFractionDigits: 1 })} ${g.unidad || ''} · ${g.items.length} equipo${g.items.length !== 1 ? 's' : ''}</span>
                    </div>
                    <div class="eq-col-cards">${g.items.map(tarjeta).join('')}</div>
                </div>`;
            }).join('') + '</div>';
        }

        function descargarRanking() {
            if (!_rankingData || !_rankingData.length) {
                alert('No hay datos para descargar. Carga los gráficos primero.');
                return;
            }
            capturaPanelHtml('panelRankingEquipos', 'top_equipos_consumo');
        }

        window._todosData = window._todosData || [];
        window._currentFilteredData = null;
        window._currentPageEq = 1;

        function renderTodosEquipos(datos) {
            hide('loadingTodosEq');
            document.getElementById('wrapTodosEq').style.display = 'block';
            window._todosData = datos || [];
            window._currentFilteredData = window._todosData;
            window._currentPageEq = 1;
            document.getElementById('subtotalEquipos').textContent =
                `— ${window._todosData.length} equipo${window._todosData.length !== 1 ? 's' : ''} registrados`;

            // Populate filter dropdowns
            const tipos = [...new Set(window._todosData.map(d => d.tipo))].filter(Boolean).sort();
            const frentes = [...new Set(window._todosData.map(d => d.frente))].filter(Boolean).sort();

            const tipoSelect = document.getElementById('eqFilterTipo');
            const frentesSelect = document.getElementById('eqFilterFrente');

            // Save current values to restore them after repopulating
            const currTipo = tipoSelect.value;
            const currFrente = frentesSelect.value;

            tipoSelect.innerHTML = '<option value="">Todos los Tipos</option>' +
                tipos.map(t => `<option value="${t}">${t}</option>`).join('');
            frentesSelect.innerHTML = '<option value="">Todos los Frentes</option>' +
                frentes.map(f => `<option value="${f}">${f}</option>`).join('');

            tipoSelect.value = tipos.includes(currTipo) ? currTipo : '';
            frentesSelect.value = frentes.includes(currFrente) ? currFrente : '';

            filtrarTablaEquipos();
        }

        var ITEMS_PER_PAGE = 15;

        function llenarTablaEquipos(datos, page = 1) {
            const body = document.getElementById('bodyTodosEq');
            const containerPag = document.getElementById('paginacionEquipos');

            if (!datos || datos.length === 0) {
                body.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:30px;color:#94a3b8;">Sin datos disponibles.</td></tr>`;
                if (containerPag) containerPag.innerHTML = '';
                return;
            }

            const totalPages = Math.ceil(datos.length / ITEMS_PER_PAGE);
            window._currentPageEq = Math.min(Math.max(1, page), totalPages);

            const start = (window._currentPageEq - 1) * ITEMS_PER_PAGE;
            const paginated = datos.slice(start, start + ITEMS_PER_PAGE);

            body.innerHTML = paginated.map((d, i) => {
                const total = parseFloat(d.total);
                const ids = (d.identificadores || d.CODIGO_PATIO || '—');
                const front = d.frente || '—';
                return `<tr>
                <td style="font-size:11px;font-weight:700;color:#0067b1;text-transform:uppercase;">${d.tipo}</td>
                <td>
                    <span style="font-family:monospace;font-weight:700;color:#1e293b;font-size:12px;">${ids}</span>
                </td>
                <td style="font-size:12px;">${d.MARCA} ${d.MODELO}</td>
                <td style="font-size:12px; color:#475569;">${front}</td>
                <td style="text-align:right;"><span class="eq-desp-badge">⛽ ${d.despachos}</span></td>
                <td style="text-align:right;font-weight:800;color:#0067b1;">
                    ${total.toLocaleString('es-VE', { minimumFractionDigits: 0, maximumFractionDigits: 1 })}
                    <span style="font-size:10px;color:#94a3b8;font-weight:400;">${d.unidad}</span>
                </td>
            </tr>`;
            }).join('');

            renderPaginacion(datos.length, totalPages);
        }

        function renderPaginacion(totalItems, totalPages) {
            const container = document.getElementById('paginacionEquipos');
            if (!container) return;

            if (totalPages <= 1) {
                container.innerHTML = '';
                return;
            }

            // Contenedor principal — sin texto 'Mostrando X de Y'
            let html = `
        <div style="display:flex; flex-direction:column; align-items:center; gap:15px; margin-top:20px; padding-top:10px; border-top:1px solid #e2e8f0;">
            <ul style="display:flex; flex-wrap:wrap; list-style:none; padding:0; margin:0; justify-content:center; gap:6px;">
        `;

            // Estilos base
            const baseBtn = "display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; border:1px solid #cbd5e1; background:#fff; transition:all 0.2s;";
            const activeBtn = "display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; font-weight:700; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8;";
            const disabledBtn = "display:flex; align-items:center; justify-content:center; padding:6px 12px; border-radius:6px; font-size:13px; border:1px solid #e2e8f0; background:#f8fafc; color:#94a3b8; cursor:not-allowed;";

            // Botón Anterior
            if (window._currentPageEq === 1) {
                html += `<li><span style="${disabledBtn}">&laquo; Anterior</span></li>`;
            } else {
                html += `<li><button onclick="cambiarPaginaEq(${window._currentPageEq - 1})" style="${baseBtn} color:#475569;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">&laquo; Anterior</button></li>`;
            }

            // Calcular rango de páginas visibles (5 a cada lado) sin mostrar puntos nunca
            let startPage = Math.max(1, window._currentPageEq - 3);
            let endPage = Math.min(totalPages, window._currentPageEq + 3);

            // Números de página continuos dentro del rango
            for (let p = startPage; p <= endPage; p++) {
                if (p === window._currentPageEq) {
                    html += `<li><span style="${activeBtn}">${p}</span></li>`;
                } else {
                    html += `<li><button onclick="cambiarPaginaEq(${p})" style="${baseBtn} color:#0f172a;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">${p}</button></li>`;
                }
            }

            // Botón Siguiente
            if (window._currentPageEq === totalPages) {
                html += `<li><span style="${disabledBtn}">Siguiente &raquo;</span></li>`;
            } else {
                html += `<li><button onclick="cambiarPaginaEq(${window._currentPageEq + 1})" style="${baseBtn} color:#475569;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='#fff'">Siguiente &raquo;</button></li>`;
            }

            html += `
            </ul>
        </div>`;

            container.innerHTML = html;
        }

        window.cambiarPaginaEq = function (newPage) {
            const datos = window._currentFilteredData || window._todosData;
            const totalPages = Math.ceil(datos.length / ITEMS_PER_PAGE);
            if (newPage < 1 || newPage > totalPages) return;
            llenarTablaEquipos(datos, newPage);
        };

        function filtrarTablaEquipos() {
            const q = (document.getElementById('eqSearch').value || '').toLowerCase();
            const fTipo = document.getElementById('eqFilterTipo').value;
            const fFrente = document.getElementById('eqFilterFrente').value;

            window._currentFilteredData = window._todosData.filter(d => {
                const matchesText = !q || (
                    (d.identificadores || '').toLowerCase().includes(q) ||
                    (d.CODIGO_PATIO || '').toLowerCase().includes(q) ||
                    (d.MARCA || '').toLowerCase().includes(q) ||
                    (d.MODELO || '').toLowerCase().includes(q)
                );
                const matchesTipo = !fTipo || d.tipo === fTipo;
                const matchesFrente = !fFrente || d.frente === fFrente;
                return matchesText && matchesTipo && matchesFrente;
            });

            window._currentPageEq = 1;
            llenarTablaEquipos(window._currentFilteredData, 1);
        }

        window._sortDir = window._sortDir || -1; // -1=desc, 1=asc

        function sortTabla(col) {
            window._sortDir *= -1;
            const keys = ['tipo', 'identificadores', 'MARCA', 'frente', 'despachos', 'total'];
            const key = keys[col];

            const currData = window._currentFilteredData || window._todosData;

            window._currentFilteredData = [...currData].sort((a, b) => {
                const aRaw = a[key] || '';
                const bRaw = b[key] || '';

                // Handle numeric sorting for despachos and total
                if (key === 'despachos' || key === 'total') {
                    const numA = parseFloat(aRaw) || 0;
                    const numB = parseFloat(bRaw) || 0;
                    return numA > numB ? window._sortDir : numA < numB ? -window._sortDir : 0;
                }

                // Handle string sorting (case insensitive)
                const strA = String(aRaw).toLowerCase();
                const strB = String(bRaw).toLowerCase();
                return strA > strB ? window._sortDir : strA < strB ? -window._sortDir : 0;
            });

            window._currentPageEq = 1;
            llenarTablaEquipos(window._currentFilteredData, 1);
        }


        // ── Especificación por frente (Aceite × Viscosidad ó Caucho × Modelo) ──────
        function renderEspecFrente(datos, tipoActivo) {
            const sec = document.getElementById('secEspecFrente');
            const load = document.getElementById('loadingAceiteFrente');
            const body = document.getElementById('aceiteFrente-body');
            if (!datos || datos.length === 0) { sec.style.display = 'none'; return; }

            const esCaucho = tipoActivo === 'CAUCHO';
            const UNIDAD = esCaucho ? 'Un' : 'L';
            const TITULO = esCaucho
                ? 'Caucho por Frente — Desglose por Modelo'
                : 'Aceite por Frente — Desglose por Viscosidad';

            // Icono y color dinámicos según tipo
            const iconEl = document.getElementById('iconEspecFrente');
            if (iconEl) {
                iconEl.textContent = esCaucho ? 'tire_repair' : 'opacity';
                iconEl.style.color = esCaucho ? '#1b5e20' : '#0067b1';
            }
            document.getElementById('txtEspecFrente').textContent = TITULO;

            sec.style.display = '';
            load.style.display = 'none';
            body.style.display = 'block';
            body.innerHTML = '';

            // ── Mapas de totales ───────────────────────────────────────────────
            const mapaFrente = {};
            const mapEspec = {};
            datos.forEach(d => {
                const val = parseFloat(d.total) || 0;
                mapaFrente[d.NOMBRE_FRENTE] = (mapaFrente[d.NOMBRE_FRENTE] || 0) + val;
                mapEspec[d.ESPECIFICACION] = (mapEspec[d.ESPECIFICACION] || 0) + val;
            });
            const frentes = Object.keys(mapaFrente).sort((a, b) => mapaFrente[b] - mapaFrente[a]);
            const especs = Object.keys(mapEspec).sort((a, b) => mapEspec[b] - mapEspec[a]);
            const maxTotal = Math.max(...Object.values(mapaFrente));

            // Paleta armoniosa — misma que el gráfico de barras de cauchos
            const PALETA = esCaucho
                ? (window.PALETA_CAUCHO_GLOBAL || ['#1b5e20', '#0d47a1', '#e65100', '#4a148c', '#006064', '#b71c1c', '#37474f', '#4e342e'])
                : ['#0d47a1', '#b71c1c', '#1b5e20', '#4a148c', '#e65100', '#006064', '#37474f', '#827717'];

            // ── Fila por frente: barra segmentada + badges de modelo ─────────
            body.innerHTML = frentes.map((frente, i) => {
                const filas = datos
                    .filter(d => d.NOMBRE_FRENTE === frente)
                    .sort((a, b) => parseFloat(b.total) - parseFloat(a.total));
                const tot = mapaFrente[frente];

                const barSegs = filas.map(f => {
                    const pct = parseFloat(f.total) / tot * 100;
                    const color = PALETA[especs.indexOf(f.ESPECIFICACION) % PALETA.length];
                    return `<div title="${f.ESPECIFICACION}: ${parseFloat(f.total).toFixed(0)} ${UNIDAD} (${pct.toFixed(0)}%)"
                             style="width:${pct}%;background:${color};height:100%;"></div>`;
                }).join('');

                const badges = filas.map(f => {
                    const color = PALETA[especs.indexOf(f.ESPECIFICACION) % PALETA.length];
                    const v = parseFloat(f.total).toLocaleString('es-VE', { maximumFractionDigits: 0 });
                    return `<span style="display:inline-flex;align-items:center;gap:5px;
                            background:#f8fafc;color:#475569;border:1px solid #cbd5e1;border-radius:20px;
                            padding:2px 8px;font-size:10px;font-weight:600;white-space:nowrap;">
                            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};"></span>
                            ${f.ESPECIFICACION}
                            <span style="background:#e2e8f0;color:#1e293b;border-radius:20px;padding:1px 5px;font-size:10px;font-weight:700;">${v}</span>
                        </span>`;
                }).join('');

                const wb = Math.round(tot / maxTotal * 100);
                return `<div style="padding:8px 0;border-bottom:1px solid #f1f5f9;">
                <div style="display:grid;grid-template-columns:24px 190px 1fr auto;align-items:center;gap:8px;">
                    <span style="font-size:11px;color:#94a3b8;font-weight:700;text-align:center;">#${i + 1}</span>
                    <span style="font-weight:700;font-size:12px;color:#1e293b;word-break:break-word;line-height:1.3;">${frente}</span>
                    <div style="background:#f1f5f9;border-radius:20px;height:10px;overflow:hidden;">
                        <div style="display:flex;height:100%;width:${wb}%;">${barSegs}</div>
                    </div>
                    <span style="font-weight:800;font-size:13px;color:#003a70;white-space:nowrap;padding-left:4px;">
                        ${tot.toLocaleString('es-VE', { maximumFractionDigits: 0 })} ${UNIDAD}
                    </span>
                </div>
                ${badges ? `<div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:5px;padding-left:22px;">${badges}</div>` : ''}
            </div>`;
            }).join('');

            // ── Total global por viscosidad/modelo: badges sutiles uno al lado
            // del otro (display:flex + flex-wrap). Antes cada badge venia
            // envuelto en su propio <div> bloque, lo que los apilaba en columna
            // perdiendo espacio horizontal y dificultando la comparacion visual.
            const totalBadges = especs.map((e, i) => {
                const color = PALETA[i % PALETA.length];
                const v = mapEspec[e].toLocaleString('es-VE', { maximumFractionDigits: 0 });
                return `<span style="display:inline-flex;align-items:center;gap:6px;
                background:#f8fafc;color:#334155;border:1px solid #cbd5e1;border-radius:20px;padding:4px 13px;
                font-size:12px;font-weight:600;white-space:nowrap;">
                <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${color};"></span>
                ${e}
                <span style="background:#e2e8f0;color:#0f172a;border-radius:20px;padding:1px 7px;font-size:11px;font-weight:700;margin-left:2px;">${v} ${UNIDAD}</span>
            </span>`;
            }).join('');

            body.insertAdjacentHTML('beforeend',
                `<div style="margin-top:14px;padding-top:12px;border-top:2px dashed #e2e8f0; display:flex; flex-wrap:wrap; gap:8px;">
                ${totalBadges}
            </div>`
            );
        }

        // ── DESCARGA PANEL ESPCFRENTE ────────────────────────────────────
        function descargarPanelEspecFrente(nombre) {
            const body = document.getElementById('aceiteFrente-body');
            if (!body || !body.innerHTML) {
                alert('No hay datos para descargar.'); return;
            }
            const card = body.closest('.g-card') || body.parentElement;
            capturaPanelHtml(card?.id || 'secEspecFrente', nombre);
        }

        // ── Especificación por equipo (tabla) ────────────────────────────────
        function renderEspecEquipo(datos, tipoActivo) {
            const sec = document.getElementById('secEspecEquipo');
            const load = document.getElementById('loadingAceiteEquipo');
            const body = document.getElementById('aceiteEquipoBody');
            if (!datos || datos.length === 0) { sec.style.display = 'none'; return; }

            const esCaucho = tipoActivo === 'CAUCHO';
            const COLOR_BASE = esCaucho ? '#059669' : '#0067b1';
            const UNIDAD = esCaucho ? 'Un' : 'L';
            const TITULO = esCaucho
                ? 'Caucho por Equipo — Desglose por Modelo'
                : 'Aceite por Equipo — Desglose por Viscosidad';

            document.getElementById('txtEspecEquipo').textContent = TITULO;
            document.getElementById('iconEspecEquipo').style.color = COLOR_BASE;

            sec.style.display = '';
            load.style.display = 'none';
            body.style.display = 'block';

            // Calcular volumen total por especificación de nuevo (misma lógica)
            const mapEspec = {};
            datos.forEach(d => {
                mapEspec[d.ESPECIFICACION] = (mapEspec[d.ESPECIFICACION] || 0) + (parseFloat(d.total) || 0);
            });
            const especs = Object.keys(mapEspec).sort((a, b) => mapEspec[b] - mapEspec[a]);

            const PALETA = [
                '#b91c1c', // [0] Rojo intenso
                '#0067b1', // [1] Azul corporativo
                '#059669', // [2] Verde oscuro
                '#475569', // [3] Gris slate oscuro
                '#64748b', // [4] Gris slate medio
                '#94a3b8', // [5] Gris slate claro
                '#cbd5e1', // [6] Gris slate más claro
                '#e2e8f0'  // [7] Gris base
            ];

            const eq = {};
            datos.forEach(d => {
                // Prioridad: PLACA → SERIAL_CHASIS → CODIGO_PATIO → IDENTIFICADOR (código de referencia)
                const idTexto = (d.PLACA && d.PLACA.trim()) ? d.PLACA.trim()
                    : (d.SERIAL_CHASIS && d.SERIAL_CHASIS.trim()) ? d.SERIAL_CHASIS.trim()
                        : (d.CODIGO_PATIO && d.CODIGO_PATIO.trim()) ? d.CODIGO_PATIO.trim()
                            : (d.identificador || 'S/ID');

                // Agrupar por ID_EQUIPO para no mezclar equipos distintos con el mismo modelo
                const k = d.ID_EQUIPO || idTexto;
                if (!eq[k]) eq[k] = { idTexto, modelo: d.MODELO, tipo: d.tipo_equipo, especs: {} };
                eq[k].especs[d.ESPECIFICACION] = (eq[k].especs[d.ESPECIFICACION] || 0) + parseFloat(d.total);
            });
            const filas = Object.values(eq).sort((a, b) =>
                Object.values(b.especs).reduce((s, v) => s + v, 0) -
                Object.values(a.especs).reduce((s, v) => s + v, 0)
            );

            const thead = `<tr>
            <th>Equipo / Código</th><th>Tipo</th>
            ${especs.map((e, i) => `<th style="text-align:right;color:${PALETA[i % PALETA.length]};">${e}</th>`).join('')}
            <th style="text-align:right;">Total</th>
        </tr>`;
            const tbody = filas.map(f => {
                const tot = Object.values(f.especs).reduce((s, v) => s + v, 0);
                const cells = especs.map((e, i) => {
                    const v = f.especs[e];
                    return v
                        ? `<td style="text-align:right;font-weight:700;color:${PALETA[i % PALETA.length]};">${v.toLocaleString('es-VE', { maximumFractionDigits: 0 })}</td>`
                        : `<td style="text-align:right;color:#cbd5e0;">—</td>`;
                }).join('');
                return `<tr>
                <td style="font-weight:700;font-family:monospace;font-size:12px;">${f.idTexto}
                    <span style="display:block;color:#64748b;font-weight:400;font-size:10px;font-family:sans-serif;">${f.modelo || ''}</span>
                </td>
                <td style="font-size:11px;color:#64748b;">${f.tipo}</td>
                ${cells}
                <td style="text-align:right;font-weight:800;color:${COLOR_BASE};">${tot.toLocaleString('es-VE', { maximumFractionDigits: 0 })} ${UNIDAD}</td>
            </tr>`;
            }).join('');
            body.innerHTML = `<table class="admin-table"><thead>${thead}</thead><tbody>${tbody}</tbody></table>`;
        }

        // ── EXCEL ────────────────────────────────────────────────────────────
        function descargarExcel() {
            window.location.href = `{{ route('consumibles.exportarCsv') }}?${getParams()}`;
        }

        // ── Descargar gráfico como imagen PNG ─────────────────────────────
        function descargarGrafico(canvasId, nombre) {
            const canvas = document.getElementById(canvasId);
            if (!canvas || canvas.style.display === 'none') {
                alert('El gráfico no está visible aún. Aplica los filtros primero.');
                return;
            }
            const fecha = new Date().toISOString().slice(0, 10);
            const link = document.createElement('a');
            link.download = `${nombre}_${fecha}.png`;
            link.href = canvas.toDataURL('image/png');
            link.click();
        }

        // ── Helper: capturar cualquier panel del DOM tal como se ve en pantalla ──────
        function capturaPanelHtml(panelId, nombre, callbackClone) {
            const el = document.getElementById(panelId);
            if (!el || el.style.display === 'none') {
                alert('El panel no está visible. Aplica los filtros primero.'); return;
            }
            if (typeof html2canvas === 'undefined') {
                if (window.showPreloader) window.showPreloader();
                const script = document.createElement('script');
                script.src = "{{ asset('js/html2canvas.min.js') }}";
                script.onload = () => {
                    if (window.hidePreloader) window.hidePreloader();
                    capturaPanelHtml(panelId, nombre, callbackClone);
                };
                script.onerror = () => {
                    if (window.hidePreloader) window.hidePreloader();
                    alert('Error al cargar la librería de captura. Revisa tu conexión.');
                };
                document.head.appendChild(script);
                return;
            }
            const fecha = new Date().toISOString().slice(0, 10);
            html2canvas(el, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff',
                logging: false,
                onclone: function (clonedDoc) {
                    if (callbackClone) callbackClone(clonedDoc);
                }
            }).then(canvas => {
                const link = document.createElement('a');
                link.download = nombre + '_' + fecha + '.png';
                link.href = canvas.toDataURL('image/png');
                link.click();
            });
        }

        // ── Descargar "Total por Frente" — captura del DOM real ───────────────────────
        function descargarPanelHtml(panelId, nombre) {
            const body = document.getElementById('totalFrenteBody');
            if (!body || !body.querySelector('.frow')) {
                alert('No hay datos para descargar.'); return;
            }
            // Captura el panel completo (g-card padre)
            const card = body.closest('.g-card') || body.parentElement;
            capturaPanelHtml(card?.id || 'panelTotalFrente', nombre);
            if (!card?.id) {
                // Si no tiene id, asignar temporalmente
                card.id = '__tmp_panel_frente';
                capturaPanelHtml('__tmp_panel_frente', nombre);
                setTimeout(() => { if (card) card.removeAttribute('id'); }, 2000);
            }
        }

        // ── Descargar "Tarjetas Resumen" como PNG ─────────────────
        function descargarPanelResumen(nombre) {
            capturaPanelHtml('resumenGrid', nombre || 'resumen_general');
        }





    </script>
    <script
        src="{{ asset('js/maquinaria/consumibles_graficos.js') }}?v={{ @filemtime(public_path('js/maquinaria/consumibles_graficos.js')) }}"></script>
    <script>
        // Carga inicial de datos — se ejecuta tras cargar todos los scripts.
        // La bandera _graficosDataLoaded evita que ModuleManager (spa:contentLoaded)
        // duplique la llamada cuando la página se carga por primera vez vía SPA.
        //
        // IMPORTANTE: resetear _graficosFirstRunDone a false en cada montaje
        // de la vista. Sin esto, una segunda visita via SPA (salir y volver)
        // ve el flag con true de la visita previa y dispara el preloader
        // global durante el first run, causando el doble-spinner con el de SPA.
        window._graficosFirstRunDone = false;
        if (typeof window.cargarDatos === 'function') {
            window._graficosDataLoaded = true;
            window.cargarDatos();
        }

        // El IIFE de Auditoria de Catalogo fue eliminado. Bash el guard
        // if(false) anterior aun referenciaba route("consumibles.auditoriaCatalogo")
        // dentro del fetch, y Blade resolvia ese helper a tiempo de compilacion
        // ANTES de evaluar el if(false) JS — tirando RouteNotFoundException.
    </script>
@endsection