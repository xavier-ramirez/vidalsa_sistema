@extends('layouts.estructura_base')

@section('title', 'Registrar Equipo')

@section('content')
<style>
    @media (max-width: 768px) {
        body:has(#formUnificadoCard) .page-title-card {
            margin-bottom: 6px !important;
            padding: 4px 0 !important;
        }
        body:has(#formUnificadoCard) .main-viewport {
            width: 100% !important;
            max-width: 100% !important;
            padding-left: 8px !important;
            padding-right: 8px !important;
            box-sizing: border-box !important;
        }
        body:has(#formUnificadoCard) .admin-card {
            width: 100% !important;
            max-width: 100% !important;
            margin-left: 0 !important;
            margin-right: 0 !important;
        }
    }
    .cat-card {
        flex: 1;
        min-width: 100px;
        padding: 14px 10px;
        border-radius: 12px;
        border: 2px solid #e2e8f0;
        background: #f8fafc;
        cursor: pointer;
        text-align: center;
        transition: all 0.25s;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 6px;
    }
    .cat-card:hover { border-color: #94a3b8; background: #f1f5f9; }
    .cat-card.active { border-color: #0067b1; background: #eff6ff; box-shadow: 0 0 0 3px rgba(0,103,177,0.15); }
    .cat-card .cat-icon { font-size: 32px; transition: color 0.25s; color: #94a3b8; }
    .cat-card.active .cat-icon { color: #0067b1; }
    .cat-card .cat-label { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.3px; color: #64748b; }
    .cat-card.active .cat-label { color: #0067b1; }
    .custom-form-autocomplete { position: relative; width: 100%; }
    .custom-form-autocomplete .dropdown-list {
        position: absolute; top: 100%; left: 0; right: 0; background: white;
        border: 1px solid #cbd5e0; border-radius: 8px; margin-top: 4px;
        max-height: 250px; overflow-y: auto; z-index: 50; display: none;
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
    }
    .custom-form-autocomplete .dropdown-item {
        padding: 10px 16px; border-bottom: 1px solid #f1f5f9;
        color: #4a5568; font-size: 14px; transition: all 0.2s; cursor: pointer;
    }
    .custom-form-autocomplete .dropdown-item:last-child { border-bottom: none; }
    .custom-form-autocomplete .dropdown-item:hover { background: #f7fafc; color: #2b6cb0; padding-left: 20px; }

    /* Marca y Modelo sugieren según el modo: en EQUIPO las de `equipos`, en AUXILIAR las de
       `equipos_auxiliares`. Va con !important porque filterFormDropdown() escribe
       display:block inline al teclear y si no, reaparecerían las del otro mundo. */
    .dropdown-list[data-modo="equipo"] .dropdown-item[data-scope="aux"],
    .dropdown-list[data-modo="aux"]    .dropdown-item[data-scope="equipo"] { display: none !important; }
</style>

@include('admin.partials.page_header', [
    'titulo'   => 'Registro de Equipos y Maquinarias',
    'tituloId' => 'pageTitleText',
    'align'    => 'center',
    'margin'   => '0 auto 6px auto',
    'padding'  => '18px 0 4px 0',
])

{{-- ══ Bulk Upload: Equipos ══ --}}
@can('equipos.create')
<div id="bulkEquipoWrapper" style="display: none;">
    @include('admin.partials.bulk_upload_card', [
        'suffix'        => '',
        'templateRoute' => 'equipos.bulkTemplate',
        'subtitulo'     => 'Descarga la plantilla, completa los equipos y súbelo para registrar varios a la vez.',
    ])
</div>
<div id="bulkAuxWrapper" style="display: none;">
    @include('admin.partials.bulk_upload_card', [
        'suffix'        => 'Aux',
        'templateRoute' => 'equipos-auxiliares.bulkTemplate',
        'subtitulo'     => 'Descarga la plantilla, completa los equipos auxiliares y súbelo para registrar varios a la vez.',
    ])
</div>
@endcan

<div id="formUnificadoCard" class="admin-card" style="max-width: 95%; margin: 0 auto;">
    <form id="createUnifiedForm" action="{{ route('equipos.storeUnified') }}" method="POST" enctype="multipart/form-data" novalidate>
        @csrf
        <input type="hidden" name="__modo" id="__modo" value="equipo">
        <input type="hidden" name="CATEGORIA_FLOTA" id="__categoriaFlota" value="">

        {{-- ═══ SELECTOR DE CATEGORÍA ═══ --}}
        <div style="margin-bottom: 24px;">
            <label style="display: block; font-weight: 700; margin-bottom: 10px; color: var(--maquinaria-dark-blue); font-size: 14px;">
                ¿Qué vas a registrar?
            </label>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                <div class="cat-card" onclick="window.switchUnifiedMode('liviana')" id="catLiviana">
                    <i class="material-icons cat-icon">directions_car</i>
                    <span class="cat-label">Flota Liviana</span>
                </div>
                <div class="cat-card" onclick="window.switchUnifiedMode('pesada')" id="catPesada">
                    <i class="material-icons cat-icon">local_shipping</i>
                    <span class="cat-label">Flota Pesada</span>
                </div>
                <div class="cat-card" onclick="window.switchUnifiedMode('auxiliar')" id="catAuxiliar">
                    <i class="material-icons cat-icon">construction</i>
                    <span class="cat-label">Auxiliar</span>
                </div>
            </div>
        </div>

        {{-- ═══ FORMULARIO (oculto hasta seleccionar categoría) ═══ --}}
        <div id="unifiedFormBody" style="display: none;">

            {{-- ── CAMPOS COMUNES ── --}}
            <h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin-bottom: 20px;">Información General</h3>

            <div class="grid-responsive-5">

                {{-- TIPO: slot compartido, contenido cambia por modo --}}
                <div id="tipoSlot">
                    {{-- Tipo Equipo (modo equipo) --}}
                    <div id="tipoEquipoWrap">
                        <label for="input_tipo_equipo" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Tipo de Equipo</label>
                        <div class="custom-form-autocomplete">
                            <input type="text" id="input_tipo_equipo" name="TIPO_EQUIPO"
                                   class="form-input-custom" value="{{ old('TIPO_EQUIPO') }}"
                                   placeholder="Seleccione o escriba..." maxlength="35" autocomplete="off"
                                   onfocus="showFormDropdown(this); window.__reorderTipoDropdown(this);"
                                   onblur="hideFormDropdownDelayed(this); window.__checkTipoEquipoCategoria();"
                                   oninput="filterFormDropdown(this)"
                                   onchange="window.__checkTipoEquipoCategoria();">
                            <div class="dropdown-list">
                                @foreach($tipos_equipo as $tipo)
                                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $tipo }}')">{{ $tipo }}</div>
                                @endforeach
                            </div>
                        </div>
                        {{-- Aviso no-bloqueante: el tipo escrito/elegido ya está registrado con
                             OTRA categoría de flota (tipo_equipos es un catálogo global, sin
                             columna de categoría — se infiere del historial, ver
                             EquipoController::create / __tipoCategoriaMap). --}}
                        <div id="tipoEquipoWarn" style="display:none;font-size:12px;font-weight:600;color:#b45309;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;margin-top:6px;"></div>
                    </div>
                    {{-- Tipo Auxiliar (modo auxiliar) --}}
                    <div id="tipoAuxWrap" style="display: none;">
                        <label for="input_tipo_aux" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                            Tipo <span style="color: var(--maquinaria-red);">*</span>
                        </label>
                        <div class="custom-dropdown" id="auxTipoCombo" style="position: relative;">
                            <input type="text" id="input_tipo_aux" name="TIPO" autocomplete="off" maxlength="80"
                                   value="{{ old('TIPO') }}" class="form-input-custom"
                                   placeholder="Selecciona o escribe..."
                                   onfocus="window.auxTipoOpen && window.auxTipoOpen(this)"
                                   oninput="window.auxTipoFilter && window.auxTipoFilter(this)"
                                   onblur="setTimeout(()=>window.auxTipoClose && window.auxTipoClose(),150)"
                                   style="text-transform: uppercase;">
                            <div class="dropdown-content" id="auxTipoContent">
                                @foreach($tiposAux as $k => $label)
                                    <div class="dropdown-item" data-label="{{ mb_strtolower($label) }}" onmousedown="event.preventDefault(); window.auxTipoPick('{{ addslashes($label) }}')">{{ $label }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                {{-- MARCA --}}
                <div>
                    <label for="marca" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        Marca <span style="color: var(--maquinaria-red);">*</span>
                    </label>
                    <div class="custom-form-autocomplete">
                        <input type="text" id="marca" name="MARCA" class="form-input-custom" value="{{ old('MARCA') }}"
                               placeholder="Escribe marca..." autocomplete="off" required
                               onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)" oninput="filterFormDropdown(this)"
                               style="text-transform: uppercase;">
                        {{-- data-scope: de qué mundo viene cada marca (equipo / aux / ambos). El
                             CSS de .dropdown-list[data-modo] oculta las que no aplican al modo
                             activo, y switchUnifiedMode() cambia ese data-modo. --}}
                        <div class="dropdown-list" data-modo="equipo">
                            @foreach($marcasScope as $op)
                                <div class="dropdown-item" data-scope="{{ $op['s'] }}" onmousedown="selectDropdownItem(this, @js($op['v']))">{{ $op['v'] }}</div>
                            @endforeach
                        </div>
                    </div>
                    @error('MARCA') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- MODELO --}}
                <div>
                    <label for="modelo" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        Modelo <span style="color: var(--maquinaria-red);">*</span>
                    </label>
                    <div class="custom-form-autocomplete">
                        <input type="text" id="modelo" name="MODELO" class="form-input-custom" value="{{ old('MODELO') }}"
                               placeholder="Escribe modelo..." autocomplete="off" required
                               onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)" oninput="filterFormDropdown(this)"
                               style="text-transform: uppercase;">
                        <div class="dropdown-list" data-modo="equipo">
                            @foreach($modelosScope as $op)
                                <div class="dropdown-item" data-scope="{{ $op['s'] }}" onmousedown="selectDropdownItem(this, @js($op['v']))">{{ $op['v'] }}</div>
                            @endforeach
                        </div>
                    </div>
                    @error('MODELO') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- AÑO --}}
                <div>
                    <label for="anio" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Año</label>
                    <div class="custom-form-autocomplete">
                        <input type="text" id="anio" name="ANIO" class="form-input-custom" value="{{ old('ANIO') }}"
                               placeholder="Ej: 2020" maxlength="4" autocomplete="off"
                               oninput="this.value = this.value.replace(/[^0-9]/g, ''); filterFormDropdown(this)"
                               onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)">
                        <div class="dropdown-list">
                            @foreach($aniosList ?? [] as $a)
                                <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $a }}')">{{ $a }}</div>
                            @endforeach
                        </div>
                    </div>
                    @error('ANIO') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- SERIAL (común, name cambia por JS) --}}
                <div>
                    <label for="serial_principal" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        <span id="serialLabel">Serial de Chasis</span> <span style="color: var(--maquinaria-red);">*</span>
                    </label>
                    <input type="text" id="serial_principal" name="SERIAL_CHASIS" class="form-input-custom" value="{{ old('SERIAL_CHASIS', old('SERIAL')) }}"
                           placeholder="Serial único" required autocomplete="off" style="text-transform: uppercase;">
                    @error('SERIAL_CHASIS') <span class="error-message-inline">{{ $message }}</span> @enderror
                    @error('SERIAL') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- SERIAL MOTOR (solo equipo, justo después del serial de chasis) --}}
                <div id="serialMotorWrap">
                    <label for="serial_motor" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Serial de Motor</label>
                    <input type="text" id="serial_motor" name="SERIAL_DE_MOTOR" class="form-input-custom" value="{{ old('SERIAL_DE_MOTOR') }}" placeholder="Opcional" autocomplete="off" style="text-transform: uppercase;">
                </div>

                {{-- COLOR (solo equipo). Vivía en la rejilla de más abajo; se sube aquí, al
                     lado del serial de motor, a pedido del cliente. Al salir de
                     #equipoFieldsSection pierde el ocultado y el `disabled` que esa sección
                     aplicaba en bloque, así que se le da su PROPIO envoltorio con id y se
                     engancha al mismo patrón que #serialMotorWrap. Sin eso, en modo auxiliar
                     el campo seguiría visible y su valor viajaría en el POST a una tabla que
                     no tiene esa columna. --}}
                <div id="colorWrap">
                <div>
                    <label for="color" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Color</label>
                    <input type="text" id="color" name="COLOR" class="form-input-custom" value="{{ old('COLOR') }}" placeholder="Ej: BLANCO" maxlength="50" autocomplete="off" oninput="this.value = this.value.toUpperCase()">
                </div>

                {{-- COMBUSTIBLE (solo equipo: `equipos_auxiliares` no tiene esa columna).
                     Es dato de la UNIDAD, no del modelo: un mismo MODELO puede traer motor
                     a gasolina o a gasoil (HILUX 2.7 vs 2.4 diésel, F-350 Triton vs Power
                     Stroke). Opciones desde Equipo::COMBUSTIBLES, la misma fuente que valida
                     el controlador. Se oculta/deshabilita por modo igual que #serialMotorWrap. --}}
                <div id="combustibleWrap">
                    <span style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Combustible</span>
                    <div class="custom-dropdown" id="combustibleSelect">
                        <input type="hidden" name="COMBUSTIBLE" id="input_combustible" data-filter-value value="{{ old('COMBUSTIBLE') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('combustibleSelect', event)" tabindex="0" role="button" style="cursor: default;">
                            <span id="label_combustible" data-filter-label>{{ old('COMBUSTIBLE') ?: 'SELECCIONE' }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            @foreach(\App\Models\Equipo::COMBUSTIBLES as $comb)
                                <div class="dropdown-item" onclick="selectOption('combustibleSelect', '{{ $comb }}', '{{ $comb }}', 'combustible')">{{ $comb }}</div>
                            @endforeach
                        </div>
                    </div>
                    @error('COMBUSTIBLE') <span class="error-message-inline">{{ $message }}</span> @enderror
                </div>

                {{-- CONSUMO DIARIO (solo equipo, misma razón que COMBUSTIBLE). Alimenta la
                     proyección de gasoil por frente: por eso es numérico, para poder sumarse. --}}
                <div id="consumoWrap">
                    <label for="CONSUMO_PROMEDIO" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Consumo (L/día)</label>
                    <input type="number" id="CONSUMO_PROMEDIO" name="CONSUMO_PROMEDIO" class="form-input-custom no-spinner" value="{{ old('CONSUMO_PROMEDIO') }}" placeholder="Ej: 200" min="0" max="99999" step="0.01" autocomplete="off">
                </div>

                {{-- CAPACIDAD --}}
                <div>
                    <label for="CAPACIDAD" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Capacidad</label>
                    <input type="text" id="CAPACIDAD" name="CAPACIDAD" class="form-input-custom" value="{{ old('CAPACIDAD') }}"
                           placeholder="Ej: 20 TON, 300A, 50kVA" maxlength="80" autocomplete="off" style="text-transform: uppercase;">
                </div>

                {{-- CÓDIGO INTERNO (común: CODIGO_PATIO en equipos, CODIGO_INTERNO en auxiliares) --}}
                <div>
                    <label for="codigo_interno" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Código Interno</label>
                    <input type="text" id="codigo_interno" name="CODIGO_PATIO" class="form-input-custom"
                           value="{{ old('CODIGO_PATIO', old('CODIGO_INTERNO')) }}"
                           placeholder="Ej: V-01" maxlength="50" autocomplete="off" style="text-transform: uppercase;">
                </div>

                {{-- ESTADO (opciones cambian por modo) --}}
                <div>
                    <span style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Estatus</span>
                    <div class="custom-dropdown" id="estadoSelect">
                        <input type="hidden" name="ESTADO_OPERATIVO" id="input_estatus" data-filter-value value="{{ old('ESTADO_OPERATIVO', 'OPERATIVO') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('estadoSelect', event)" tabindex="0" role="button" style="cursor: default;">
                            <span id="label_estatus" data-filter-label>{{ old('ESTADO_OPERATIVO', 'OPERATIVO') }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content" id="estadoOptions">
                            {{-- Opciones equipo --}}
                            @foreach(['OPERATIVO', 'INOPERATIVO', 'EN MANTENIMIENTO' => 'MANTENIMIENTO', 'DESINCORPORADO'] as $key => $val)
                                @php $vd = is_numeric($key) ? $val : $val; $vv = is_numeric($key) ? $val : $key; @endphp
                                <div class="dropdown-item estado-opt-eq" onclick="selectOption('estadoSelect', '{{ $vv }}', '{{ $vd }}', 'estatus')">{{ $vd }}</div>
                            @endforeach
                            {{-- Opciones auxiliar (ocultas inicialmente) --}}
                            @foreach($estadosAux as $k => $label)
                                <div class="dropdown-item estado-opt-aux" style="display: none;" onclick="selectOption('estadoSelect', '{{ $k }}', '{{ addslashes($label) }}', 'estatus')">{{ $label }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- LINK GPS (solo equipo). Movido aquí, junto al estatus, a pedido del
                     cliente. Mismo motivo que #colorWrap para el envoltorio propio: fuera de
                     #equipoFieldsSection nadie lo ocultaría ni lo desactivaría en modo
                     auxiliar. --}}
                <div id="linkGpsWrap">
                <div>
                    <label for="link_gps" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Link GPS</label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="url" id="link_gps" name="LINK_GPS" class="form-input-custom" value="{{ old('LINK_GPS') }}" placeholder="https://..." style="flex: 1;">
                        <span style="color: #10b981; display: flex;"><i class="material-icons" style="font-size: 20px;">gps_fixed</i></span>
                    </div>
                </div>
                </div>
                </div>

                {{-- FRENTE DE TRABAJO --}}
                <div>
                    <span style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">
                        Frente de Trabajo <span id="frenteReqMark" style="color: var(--maquinaria-red); display: none;">*</span>
                    </span>
                    <div class="custom-dropdown" id="frenteSelect" data-fuzzy>
                        <input type="hidden" name="ID_FRENTE_ACTUAL" id="input_frente_trabajo" data-filter-value value="{{ old('ID_FRENTE_ACTUAL') }}">
                        {{-- El campo ES la caja de texto: se escribe directamente y la lista se va
                             reduciendo, en vez de abrir y buscar dentro. Es el patron canonico del
                             proyecto (mismo montaje que el filtro de Frente en /admin/equipos):
                             el placeholder muestra la seleccion actual, `data-filter-search` hace que
                             selectOption lo limpie al elegir y toggleDropdown lo enfoca al abrir.
                             El filtrado lo hace el helper CENTRAL window.filterDropdownOptions y
                             `data-fuzzy` le pide ordenar por relevancia (tolera erratas). --}}
                        {{-- SIN padding:0 en el trigger: el .dropdown-trigger ya trae su relleno (8px 15px)
                             y quitarlo dejaba la caja en 26px de alto frente a los 42 del Estatus de al lado.
                             El input va sin relleno propio para no sumar altura sobre la del componente. --}}
                        {{-- SIN onclick inline: el manejador delegado de uicomponents.js ya atiende
                             todos los .dropdown-trigger y sabe distinguir un clic en la CAJA (abrir y
                             dejar teclear) de uno en el contenedor (alternar). Teniendo ademas el
                             onclick, cada clic ejecutaba la apertura DOS veces. Es el mismo montaje
                             que el filtro de Frente de /admin/equipos, que tampoco lleva onclick. --}}
                        <div class="dropdown-trigger" tabindex="0" role="button"
                             style="display: flex; align-items: center; gap: 6px; overflow: hidden;">
                            <input type="text" data-filter-search id="label_frente_trabajo"
                                   placeholder="{{ old('ID_FRENTE_ACTUAL') ? ($frentes[old('ID_FRENTE_ACTUAL')] ?? 'SELECCIONE') : 'SELECCIONE' }}"
                                   autocomplete="off" aria-label="Frente de Trabajo"
                                   oninput="window.filterDropdownOptions(this)"
                                   style="flex:1; min-width:0; border:none; background:transparent; padding:0; font-size:14px; line-height:normal; outline:none; color:#0f172a; cursor:text;">
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            <div class="dropdown-item" onclick="selectOption('frenteSelect', '', 'Sin Asignar', 'frente_trabajo')">Sin Asignar</div>
                            @foreach($frentes as $id => $nombre)
                                {{-- addslashes en el nombre: hay frentes con apostrofes (TUBERÍA DE 12'' AGUA
                                     SALADA). Blade los escapa a &#039;, pero el navegador los DEVUELVE a ' antes
                                     de leer el onclick como JS, asi que la llamada quedaba rota
                                     ("SyntaxError: missing ) after argument list") y ESOS frentes no se podian
                                     elegir. Mismo escape que ya usan el estado de arriba y los filtros del indice. --}}
                                <div class="dropdown-item" onclick="selectOption('frenteSelect', '{{ $id }}', '{{ addslashes($nombre) }}', 'frente_trabajo')">{{ $nombre }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>
                {{-- EQUIPO VINCULADO (solo auxiliar) — vive en la rejilla COMUN, al lado del
                     Frente de Trabajo, porque es la otra pregunta de "donde esta". Se muestra y se
                     deshabilita por modo igual que #serialMotorWrap, en vez de vivir dentro de
                     #auxiliarFieldsSection: alli quedaba en otra fila, lejos del frente. --}}
                <div id="hostFieldWrap" style="display:none;">
                        <div>
                            <label for="hostSearchInput" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Equipo Vinculado</label>
                            <div id="auxHostPicker" style="position: relative;">
                                <input type="hidden" name="ID_EQUIPO_HOST" id="ID_EQUIPO_HOST" value="{{ old('ID_EQUIPO_HOST') }}">
                                <div id="hostSearchWrapper" style="position:relative;">
                                    <input type="text" id="hostSearchInput" autocomplete="off" class="form-input-custom"
                                           placeholder="Buscar por serial, placa o código..."
                                           oninput="window.auxHostSearch && window.auxHostSearch(this)"
                                           onfocus="window.auxHostSearch && window.auxHostSearch(this)"
                                           onblur="setTimeout(()=>window.auxHostClose && window.auxHostClose(),200)">
                                    <div id="hostResultsBox" style="display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; background:white; border:1px solid #e2e8f0; border-radius:10px; box-shadow:0 10px 20px -5px rgba(15,23,42,0.18); max-height:360px; overflow-y:auto; z-index:50;"></div>
                                </div>
                                <div id="hostSelectedCard" style="display:none; background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%); border:1px solid #93c5fd; border-radius:10px; padding:10px 12px; align-items:center; gap:12px;">
                                    <div style="background:#1e40af; color:white; padding:8px; border-radius:8px; display:flex; flex-shrink:0;">
                                        <i class="material-icons" style="font-size:20px;">directions_car</i>
                                    </div>
                                    <div style="flex:1; min-width:0;">
                                        <div id="hostSelectedPrimary" style="font-weight:800; color:#1e293b; font-size:14px;"></div>
                                        <div id="hostSelectedSecondary" style="color:#475569; font-size:12px; margin-top:2px;"></div>
                                        <div id="hostSelectedTertiary" style="color:#64748b; font-size:11px; margin-top:2px;"></div>
                                    </div>
                                    <button type="button" onclick="window.auxHostClear && window.auxHostClear()" title="Cambiar" style="background:white; border:1px solid #cbd5e1; color:#475569; cursor:pointer; border-radius:6px; padding:6px 10px; display:flex; align-items:center; gap:4px; font-size:12px; font-weight:600;">
                                        <i class="material-icons" style="font-size:16px;">swap_horiz</i> Cambiar
                                    </button>
                                </div>
                            </div>
                            <small style="display:block;margin-top:4px;font-size:11px;color:#94a3b8;">Opcional. Máx. {{ \App\Models\EquipoAuxiliar::ANCHOR_MAX_PER_HOST }} por equipo.</small>
                        </div>
                </div>


                {{-- DETALLE UBICACIÓN: no se pide al registrar; se asigna después via edición o movilización. --}}
            </div>

            {{-- ═══ CAMPOS EXCLUSIVOS EQUIPO ═══ --}}
            <div id="equipoFieldsSection">
                {{-- Catalog Linking Widget --}}
                <div id="catalog_link_widget" style="display: none; margin: 16px 0; padding: 12px 14px; background: linear-gradient(135deg, #ebf8ff 0%, #f0f9ff 100%); border: 1px solid #0284c7; border-radius: 12px;">
                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                        <div style="background: #0284c7; padding: 6px; border-radius: 50%; display: flex;">
                            <i class="material-icons" style="color: white; font-size: 18px;">inventory_2</i>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="margin: 0; color: #0c4a6e; font-size: 13.5px; font-weight: 800;">¡Encontramos este modelo en el Catálogo!</h4>
                            <p style="margin: 2px 0 0 0; color: #075985; font-size: 12px;">Vincula las especificaciones técnicas si coinciden.</p>
                        </div>
                    </div>
                    <div id="catalog_preview" style="background: white; padding: 12px; border-radius: 10px; margin-bottom: 10px; border: 1px solid #bae6fd;"></div>
                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                        <button type="button" onclick="ignoreCatalogSuggestion()" style="background: white; color: #64748b; border: 1px solid #cbd5e0; padding: 7px 14px; border-radius: 8px; font-weight: 600; font-size: 13px;">
                            <i class="material-icons" style="font-size: 16px; vertical-align: middle; margin-right: 4px;">close</i> Ignorar
                        </button>
                        <button type="button" onclick="linkToCatalog()" style="background: #0284c7; color: white; border: none; padding: 7px 16px; border-radius: 8px; font-weight: 700; font-size: 13px;">
                            <i class="material-icons" style="font-size: 16px; vertical-align: middle; margin-right: 4px;">link</i> Vincular
                        </button>
                    </div>
                </div>
                <input type="hidden" id="linked_id_espec" name="ID_ESPEC" value="{{ old('ID_ESPEC') }}">

                {{-- Documentación Legal (Equipos) --}}
                <h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 10px; margin-bottom: 20px; margin-top: 30px;">Documentación Legal</h3>
                <div class="grid-responsive-5">
                    <div>
                        <label for="placa" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Placa</label>
                        <input type="text" id="placa" name="documentacion[PLACA]" class="form-input-custom" value="{{ old('documentacion.PLACA') }}" placeholder="Ej: A00BC12">
                    </div>
                    <div>
                        <label for="titular" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Titular del Registro</label>
                        <input type="text" id="titular" name="documentacion[NOMBRE_DEL_TITULAR]" class="form-input-custom" value="{{ old('documentacion.NOMBRE_DEL_TITULAR') }}" placeholder="Nombre propietario" autocomplete="off">
                    </div>
                    <div style="position: relative;">
                        <label for="nro_doc_propiedad" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Nro Título</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="text" id="nro_doc_propiedad" name="documentacion[NRO_DE_DOCUMENTO]" class="form-input-custom doc-meta" data-file-target="doc_propiedad" data-has-existing="false" value="{{ old('documentacion.NRO_DE_DOCUMENTO') }}" style="flex: 1;" autocomplete="off">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="doc_propiedad" title="Cargar PDF de Propiedad" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="doc_propiedad" name="doc_propiedad" class="doc-file" data-meta-target="nro_doc_propiedad" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                    <div>
                        <label for="seguro" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Póliza</label>
                        <div class="custom-form-autocomplete">
                            <input type="text" id="seguro" name="documentacion[NOMBRE_SEGURO]" class="form-input-custom" value="{{ old('documentacion.NOMBRE_SEGURO') }}" placeholder="Escriba aseguradora." autocomplete="off"
                                   onfocus="showFormDropdown(this)" onblur="hideFormDropdownDelayed(this)" oninput="filterFormDropdown(this)">
                            <div class="dropdown-list">
                                @foreach($seguros as $s)
                                    <div class="dropdown-item" onmousedown="selectDropdownItem(this, '{{ $s }}')">{{ $s }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="venc_poliza" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Vencimiento Póliza</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="venc_poliza" name="documentacion[FECHA_VENC_POLIZA]" class="form-input-custom doc-meta" data-file-target="poliza_seguro" data-has-existing="false" value="{{ old('documentacion.FECHA_VENC_POLIZA') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="poliza_seguro" title="Cargar PDF de Póliza" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="poliza_seguro" name="poliza_seguro" class="doc-file" data-meta-target="venc_poliza" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="fecha_rotc" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Fecha ROTC</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="fecha_rotc" name="documentacion[FECHA_ROTC]" class="form-input-custom doc-meta" data-file-target="doc_rotc" data-has-existing="false" value="{{ old('documentacion.FECHA_ROTC') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="doc_rotc" title="Cargar PDF ROTC" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="doc_rotc" name="doc_rotc" class="doc-file" data-meta-target="fecha_rotc" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="fecha_racda" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Fecha RACDA</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="fecha_racda" name="documentacion[FECHA_RACDA]" class="form-input-custom doc-meta" data-file-target="doc_racda" data-has-existing="false" value="{{ old('documentacion.FECHA_RACDA') }}" style="flex: 1; cursor: pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="doc_racda" title="Cargar PDF RACDA" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="doc_racda" name="doc_racda" class="doc-file" data-meta-target="fecha_racda" accept=".pdf" style="display: none;">
                        </div>
                    </div>
                </div>
            </div>{{-- /equipoFieldsSection --}}

            {{-- ═══ CAMPOS EXCLUSIVOS AUXILIAR ═══ --}}
            <div id="auxiliarFieldsSection" style="display: none;">

                {{-- Documentación Auxiliar --}}
                <h3 style="color: var(--maquinaria-blue); font-size: 16px; border-bottom: 2px solid #f0f2f5; padding-bottom: 8px; margin: 18px 0 12px 0;">Documentación Legal</h3>
                <div class="grid-responsive-5">
                    <div style="position: relative;">
                        <label style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Documento de Propiedad</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="text" id="aux_doc_propiedad_meta" class="form-input-custom" placeholder="Sin documento" readonly style="flex: 1; background:#f8fafc; cursor:default;">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="aux_doc_propiedad" title="Cargar PDF de Propiedad" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="aux_doc_propiedad" name="doc_propiedad" accept=".pdf" style="display:none;"
                                   onchange="document.getElementById('aux_doc_propiedad_meta').value = this.files[0] ? this.files[0].name : '';">
                        </div>
                    </div>
                    <div style="position: relative;">
                        <label for="fecha_vencimiento_cert" style="display: block; font-weight: 700; margin-bottom: 8px; color: var(--maquinaria-dark-blue);">Vencimiento Certif.</label>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <input type="date" id="fecha_vencimiento_cert" name="fecha_vencimiento_cert" class="form-input-custom" value="{{ old('fecha_vencimiento_cert') }}" style="flex: 1; cursor:pointer;" onclick="try{this.showPicker()}catch(e){}">
                            <div class="pdf-btn-container" style="display:flex; align-items:center; gap:6px;">
                                <label for="aux_certificado" title="Cargar PDF de certificado" style="display:flex; align-items:center; justify-content:center; width:30px; height:30px; border:1px dashed #3b82f6; color:#3b82f6; border-radius:6px; cursor:pointer;">
                                    <i class="material-icons" style="font-size:18px;">cloud_upload</i>
                                </label>
                            </div>
                            <input type="file" id="aux_certificado" name="certificado" accept=".pdf" style="display:none;">
                        </div>
                    </div>
                </div>
            </div>{{-- /auxiliarFieldsSection --}}

        </div>{{-- /unifiedFormBody --}}

        <div id="submitRow" style="margin-top: 40px; display: none; gap: 12px; justify-content: center;">
            <a href="{{ route('equipos.index') }}" class="btn-primary-maquinaria btn-secondary">Cancelar</a>
            <button type="submit" class="btn-primary-maquinaria" id="btnSubmitUnified">
                <i class="material-icons">save</i>
                <span id="btnSubmitLabel">Registrar</span>
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    // ── Tipo Aux combobox ──
    window.auxTipoOpen = function (input) {
        var cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'block';
        window.auxTipoFilter(input);
    };
    window.auxTipoClose = function () {
        var cont = document.getElementById('auxTipoContent');
        if (cont) cont.style.display = 'none';
    };
    window.auxTipoFilter = function (input) {
        var q = (input.value || '').toLowerCase().trim();
        document.querySelectorAll('#auxTipoContent .dropdown-item').forEach(function (it) {
            it.style.display = (!q || it.dataset.label.includes(q)) ? '' : 'none';
        });
    };
    window.auxTipoPick = function (label) {
        var input = document.getElementById('input_tipo_aux');
        if (input) input.value = label;
        window.auxTipoClose();
    };

    // ── Host picker (auxiliar) ──
    var _hostDebounce = null, _hostLastQuery = '';
    window.auxHostSearch = function (input) {
        var q = (input.value || '').trim();
        if (q.length < 2) { document.getElementById('hostResultsBox').style.display = 'none'; return; }
        if (q === _hostLastQuery) return;
        _hostLastQuery = q;
        clearTimeout(_hostDebounce);
        _hostDebounce = setTimeout(function () {
            window.apiFetch('{{ route("equipos-auxiliares.searchHosts") }}?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) { return r.json(); }).then(function (data) { window.auxHostRender(data); })
            .catch(function (e) { console.error('searchHosts:', e); });
        }, 280);
    };
    window.auxHostRender = function (rows) {
        var box = document.getElementById('hostResultsBox');
        if (!box) return;
        if (!rows || !rows.length) { box.innerHTML = '<div style="padding:14px; text-align:center; color:#94a3b8; font-size:12px;">Sin resultados.</div>'; box.style.display = 'block'; return; }
        var esc = window.escapeHtml;   // helper central (dom_helpers.js): antes solo escapaba "
        box.innerHTML = rows.map(function (r) {
            var dis = r.disponible ? '' : 'opacity:0.55; pointer-events:none;';
            var badge = r.disponible
                ? '<span style="background:#dcfce7;color:#166534;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Disponible</span>'
                : '<span style="background:#fee2e2;color:#991b1b;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">Lleno (' + r.auxiliares_anclados + '/2)</span>';
            var idP = r.placa || r.serial_chasis || ('#' + r.id);
            var primary = idP;
            var secondary = [r.tipo, r.marca].filter(function (x) { return x; }).join(' · ');
            var tertiary = r.codigo ? ('Código: ' + r.codigo) : '';
            return '<div style="padding:12px 14px; border-bottom:1px solid #f1f5f9; cursor:pointer; display:flex; align-items:center; gap:12px; ' + dis + '" onmousedown="event.preventDefault(); window.auxHostPick(' + r.id + ', this)" data-primary="' + esc(primary) + '" data-secondary="' + esc(secondary) + '" data-tertiary="' + esc(tertiary) + '" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'white\'">' +
                '<div style="width:40px;height:40px;border-radius:8px;background:#eff6ff;color:#1e40af;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><i class="material-icons" style="font-size:20px;">directions_car</i></div>' +
                '<div style="flex:1; min-width:0;"><div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:2px;"><strong style="color:#1e293b; font-size:13px;">' + idP + '</strong>' + badge + '</div><div style="font-size:12px; color:#475569;">' + (secondary || '') + '</div></div></div>';
        }).join('');
        box.style.display = 'block';
    };
    window.auxHostPick = function (id, el) {
        document.getElementById('ID_EQUIPO_HOST').value = id;
        document.getElementById('hostSelectedPrimary').textContent = el.dataset.primary || ('#' + id);
        document.getElementById('hostSelectedSecondary').textContent = el.dataset.secondary || '';
        document.getElementById('hostSelectedTertiary').textContent = el.dataset.tertiary || '';
        document.getElementById('hostSearchWrapper').style.display = 'none';
        document.getElementById('hostSelectedCard').style.display = 'flex';
        document.getElementById('hostSearchInput').value = '';
        window.auxHostClose();
    };
    window.auxHostClose = function () { var b = document.getElementById('hostResultsBox'); if (b) b.style.display = 'none'; };
    window.auxHostClear = function () {
        document.getElementById('ID_EQUIPO_HOST').value = '';
        document.getElementById('hostSearchInput').value = '';
        document.getElementById('hostSearchWrapper').style.display = 'block';
        document.getElementById('hostSelectedCard').style.display = 'none';
        _hostLastQuery = '';
        window.auxHostClose();
        document.getElementById('hostSearchInput').focus();
    };

    // ── Mode switching ──
    window.switchUnifiedMode = function (mode) {
        var isAux = (mode === 'auxiliar');
        var modoVal = isAux ? 'auxiliar' : 'equipo';
        document.getElementById('__modo').value = modoVal;
        document.getElementById('__categoriaFlota').value = mode === 'liviana' ? 'FLOTA LIVIANA' : (mode === 'pesada' ? 'FLOTA PESADA' : '');

        // Cards
        document.querySelectorAll('.cat-card').forEach(function (c) { c.classList.remove('active'); });
        var active = mode === 'liviana' ? 'catLiviana' : (mode === 'pesada' ? 'catPesada' : 'catAuxiliar');
        document.getElementById(active).classList.add('active');

        // Show form body + submit
        document.getElementById('unifiedFormBody').style.display = '';
        document.getElementById('submitRow').style.display = 'flex';

        // Toggle sections
        document.getElementById('equipoFieldsSection').style.display = isAux ? 'none' : '';
        document.getElementById('auxiliarFieldsSection').style.display = isAux ? '' : 'none';

        // Tipo slot
        document.getElementById('tipoEquipoWrap').style.display = isAux ? 'none' : '';
        document.getElementById('tipoAuxWrap').style.display = isAux ? '' : 'none';

        // Marca y Modelo: el mismo campo sirve a los dos mundos, así que se cambia el juego
        // de sugerencias. El CSS oculta las opciones fuera de ámbito (ver data-scope arriba).
        ['marca', 'modelo'].forEach(function (id) {
            var inp = document.getElementById(id);
            var lista = inp && inp.closest('.custom-form-autocomplete');
            lista = lista && lista.querySelector('.dropdown-list');
            if (lista) lista.dataset.modo = isAux ? 'aux' : 'equipo';
        });

        // Serial name
        var serialInput = document.getElementById('serial_principal');
        serialInput.name = isAux ? 'SERIAL' : 'SERIAL_CHASIS';
        document.getElementById('serialLabel').textContent = isAux ? 'Serial' : 'Serial de Chasis';

        // Serial Motor (solo equipo)
        var serialMotorWrap = document.getElementById('serialMotorWrap');
        if (serialMotorWrap) {
            serialMotorWrap.style.display = isAux ? 'none' : '';
            var smInput = document.getElementById('serial_motor');
            if (smInput) smInput.disabled = isAux;
        }

        // Color y Link GPS (solo equipo): mismo patron que #serialMotorWrap. Antes
        // los tapaba #equipoFieldsSection entero; al mudarlos junto a los campos con los
        // que se leen mejor, cada uno se apaga por su cuenta.
        [['colorWrap', 'color'], ['linkGpsWrap', 'link_gps']].forEach(function (par) {
            var wrap = document.getElementById(par[0]);
            if (!wrap) return;
            wrap.style.display = isAux ? 'none' : '';
            var input = document.getElementById(par[1]);
            if (input) input.disabled = isAux;
        });

        // Combustible (solo equipo): `equipos_auxiliares` no tiene esa columna, asi que en
        // modo auxiliar se oculta Y se deshabilita el hidden para no enviarlo en el POST.
        var combustibleWrap = document.getElementById('combustibleWrap');
        if (combustibleWrap) {
            combustibleWrap.style.display = isAux ? 'none' : '';
            var combInput = document.getElementById('input_combustible');
            if (combInput) combInput.disabled = isAux;
        }

        // Consumo diario (solo equipo): mismo patron que #combustibleWrap.
        var consumoWrap = document.getElementById('consumoWrap');
        if (consumoWrap) {
            consumoWrap.style.display = isAux ? 'none' : '';
            var consInput = document.getElementById('CONSUMO_PROMEDIO');
            if (consInput) consInput.disabled = isAux;
        }

        // Equipo Vinculado (solo auxiliar). Mismo patron que serialMotorWrap: al vivir en la
        // rejilla COMUN ya no lo alcanza el disabled masivo de #auxiliarFieldsSection, asi que
        // sus dos inputs se deshabilitan aqui para no enviar ID_EQUIPO_HOST en modo equipo.
        var hostFieldWrap = document.getElementById('hostFieldWrap');
        if (hostFieldWrap) {
            hostFieldWrap.style.display = isAux ? '' : 'none';
            ['ID_EQUIPO_HOST', 'hostSearchInput'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.disabled = !isAux;
            });
        }

        // Código Interno name (CODIGO_PATIO en equipos, CODIGO_INTERNO en auxiliares)
        var codigoInput = document.getElementById('codigo_interno');
        if (codigoInput) codigoInput.name = isAux ? 'CODIGO_INTERNO' : 'CODIGO_PATIO';

        // Año required
        document.getElementById('anio').required = !isAux;

        // Frente required
        var frenteInput = document.getElementById('input_frente_trabajo');
        frenteInput.required = isAux;
        document.getElementById('frenteReqMark').style.display = isAux ? '' : 'none';

        // Estado opciones
        document.querySelectorAll('.estado-opt-eq').forEach(function (el) { el.style.display = isAux ? 'none' : ''; });
        document.querySelectorAll('.estado-opt-aux').forEach(function (el) { el.style.display = isAux ? '' : 'none'; });
        // Reset estado a OPERATIVO al cambiar modo
        document.getElementById('input_estatus').value = 'OPERATIVO';
        document.getElementById('label_estatus').textContent = 'OPERATIVO';

        // Tipo required + disabled. Los dos inputs de tipo (TIPO_EQUIPO y TIPO) viven en
        // el #tipoSlot COMÚN, fuera de las dos secciones que se deshabilitan más abajo, y
        // solo se ocultaban con display → el del modo inactivo se enviaba igual. El
        // backend ignora el sobrante, pero mandábamos un campo que no corresponde al modo.
        var tipoEq = document.getElementById('input_tipo_equipo');
        var tipoAux = document.getElementById('input_tipo_aux');
        if (tipoEq) { tipoEq.required = !isAux; tipoEq.disabled = isAux; }
        if (tipoAux) { tipoAux.required = isAux; tipoAux.disabled = !isAux; }

        // Bulk upload toggle
        var bulkEq = document.getElementById('bulkEquipoWrapper');
        var bulkAux = document.getElementById('bulkAuxWrapper');
        if (bulkEq) bulkEq.style.display = isAux ? 'none' : '';
        if (bulkAux) bulkAux.style.display = isAux ? '' : 'none';

        // Title
        var titleMap = { liviana: 'Registro de Equipos — Flota Liviana', pesada: 'Registro de Maquinarias — Flota Pesada', auxiliar: 'Registro de Equipo Auxiliar' };
        document.getElementById('pageTitleText').textContent = titleMap[mode] || 'Registro de Equipos y Maquinarias';

        // Submit label
        document.getElementById('btnSubmitLabel').textContent = isAux ? 'Registrar Auxiliar' : 'Registrar Equipo';

        // Disable hidden inputs in inactive section to prevent them from being submitted
        document.querySelectorAll('#equipoFieldsSection input, #equipoFieldsSection select').forEach(function (el) { el.disabled = isAux; });
        document.querySelectorAll('#auxiliarFieldsSection input, #auxiliarFieldsSection select').forEach(function (el) { el.disabled = !isAux; });

        // Avisar a la validación en vivo: el modo cambió, debe limpiar errores/caché de
        // los campos compartidos (un duplicado en un modo puede no serlo en el otro).
        window.dispatchEvent(new Event('unified-mode-changed'));
        window.__checkTipoEquipoCategoria();
    };

    // ── Tipo de Equipo ↔ Categoría de flota (Liviana/Pesada) ──────────────────────
    // tipo_equipos es un catálogo GLOBAL (sin columna de categoría): un mismo nombre de
    // tipo puede en teoría usarse en cualquier categoría. __tipoCategoriaMap (inyectado por
    // EquipoController::create) dice con qué categoría(s) se usó CADA tipo hasta ahora,
    // inferido de los equipos ya registrados. Con eso: (a) al abrir el dropdown, los tipos
    // de la categoría elegida se recomiendan primero; (b) si se escribe/elige un tipo que
    // solo se ha usado con la OTRA categoría, se avisa (no bloquea: es una recomendación,
    // no una regla de negocio — un tipo nuevo o mixto es válido).
    window.__tipoCategoriaMap = @json($tipoCategoriaMap ?? []);
    function __normTipo(s) { return String(s || '').trim().toUpperCase(); }

    window.__reorderTipoDropdown = function (input) {
        var actual = document.getElementById('__categoriaFlota').value;
        if (!actual) return; // modo auxiliar: no aplica
        var container = input.closest('.custom-form-autocomplete');
        var dropdown = container && container.querySelector('.dropdown-list');
        if (!dropdown) return;
        var items = Array.prototype.slice.call(dropdown.querySelectorAll('.dropdown-item'));
        items.sort(function (a, b) {
            var ca = window.__tipoCategoriaMap[__normTipo(a.textContent)] || [];
            var cb = window.__tipoCategoriaMap[__normTipo(b.textContent)] || [];
            var scoreOf = function (cats) { return cats.length === 0 ? 1 : (cats.indexOf(actual) !== -1 ? 0 : 2); };
            return scoreOf(ca) - scoreOf(cb);
        });
        items.forEach(function (it) { dropdown.appendChild(it); });
    };

    window.__checkTipoEquipoCategoria = function () {
        var input = document.getElementById('input_tipo_equipo');
        var warn  = document.getElementById('tipoEquipoWarn');
        var actual = document.getElementById('__categoriaFlota').value;
        if (!input || !warn) return;
        var cats = window.__tipoCategoriaMap[__normTipo(input.value)];
        if (actual && cats && cats.length && cats.indexOf(actual) === -1) {
            var etiqueta = { 'FLOTA LIVIANA': 'Flota Liviana', 'FLOTA PESADA': 'Flota Pesada' };
            var otras = cats.map(function (c) { return etiqueta[c] || c; }).join(' / ');
            warn.textContent = '⚠ Este tipo ya está registrado como ' + otras + ', no como ' + (etiqueta[actual] || actual) + '.';
            warn.style.display = '';
        } else {
            warn.style.display = 'none';
        }
    };

    // ── AJAX Submit ──
    var form = document.getElementById('createUnifiedForm');
    if (form && form.dataset.ajaxBound !== '1') {
        form.dataset.ajaxBound = '1';
        // El flag `submitting` ya bloquea el doble envío, pero el botón seguía habilitado
        // y clicable: sin feedback visual el usuario vuelve a pulsar creyendo que no pasó
        // nada. Se libera en TODOS los desenlaces (éxito, 422, error de red): tras guardar
        // el formulario se queda en pantalla listo para el siguiente equipo.
        var btnSubmit = document.getElementById('btnSubmitUnified');
        function liberarSubmit() {
            form.dataset.submitting = '0';
            if (btnSubmit) btnSubmit.disabled = false;
        }

        // Banner + marcas rojas del último 422. Se usa antes de enviar y al dejar el
        // formulario listo para otro registro.
        function limpiarErroresForm() {
            form.querySelectorAll('.is-invalid').forEach(function (el) { el.classList.remove('is-invalid'); });
            form.querySelectorAll('.error-message-inline').forEach(function (el) { el.remove(); });
            var oldSummary = document.getElementById('errorSummary');
            if (oldSummary) oldSummary.remove();
        }

        // Modo actualmente elegido, leído de la tarjeta activa (no de #__modo, que no
        // distingue liviana de pesada — las dos valen 'equipo').
        function modoActual() {
            var card = document.querySelector('.cat-card.active');
            if (!card) return 'liviana';
            return card.id === 'catPesada' ? 'pesada' : (card.id === 'catAuxiliar' ? 'auxiliar' : 'liviana');
        }

        // Tras guardar NO se navega al listado: se deja el formulario vacío y en el MISMO
        // modo, que es lo normal al cargar equipos en tanda. Volver a /admin/equipos
        // obligaba a esperar el listado completo (cientos de filas + stats) y a elegir otra
        // vez la categoría.
        // form.reset() devuelve cada campo a su value del HTML, incluidos los <input file> y
        // los del modo inactivo, y switchUnifiedMode() recompone lo que depende del modo
        // (required/disabled, estado OPERATIVO, hidden __modo/__categoriaFlota) y dispara
        // 'unified-mode-changed', que ya limpia el error y la caché de unicidad de los
        // campos compartidos — por eso aquí no se repite esa lógica.
        function prepararOtroRegistro() {
            var modo = modoActual();
            form.reset();
            window.switchUnifiedMode(modo);
            limpiarErroresForm();
            // form.reset() vacía el hidden ID_ESPEC, pero el widget de catálogo guarda su
            // propio `linkedId` y seguiría mostrándose vinculado al equipo anterior.
            // ignoreCatalogSuggestion() es su limpieza oficial (hidden + estado + ocultar).
            if (typeof window.ignoreCatalogSuggestion === 'function') window.ignoreCatalogSuggestion();
            var card = document.getElementById('formUnificadoCard');
            if (card && card.scrollIntoView) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            // Sin focus() automático en el campo "Tipo": su onfocus abre el desplegable
            // (showFormDropdown / auxTipoOpen), así que enfocarlo tras guardar dejaba una
            // lista desplegada sola, encima del toast y mientras la página aún hace scroll.
        }
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (form.dataset.submitting === '1') return;
            form.dataset.submitting = '1';
            if (btnSubmit) btnSubmit.disabled = true;

            if (typeof window.showPreloader === 'function') window.showPreloader();

            limpiarErroresForm();

            var formData = new FormData(form);
            window.apiFetch(form.action, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                method: 'POST',
                body: formData})
            .then(function (r) { return r.json().then(function (body) { return { status: r.status, body: body }; }); })
            .then(function (res) {
                if (res.status === 200 || res.status === 201) {
                    // El backend sigue devolviendo `redirect` (lo usan otros clientes); aquí se
                    // ignora a propósito: nos quedamos para registrar el siguiente equipo.
                    var msg = res.body.message || 'Registrado correctamente.';
                    if (typeof window.hidePreloader === 'function') window.hidePreloader();
                    liberarSubmit();
                    prepararOtroRegistro();
                    window.toast(msg + ' Puedes registrar otro.', 'success');
                    return;
                }
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
                liberarSubmit();

                if (res.status === 422 && res.body.errors) {
                    // Banner global
                    form.insertAdjacentHTML('afterbegin', '<div id="errorSummary" style="background:#fff5f5; border:1px solid #fed7d7; color:#c53030; padding:12px 15px; border-radius:12px; margin-bottom:20px; display:flex; align-items:center; gap:10px; font-size:14px; font-weight:600;"><i class="material-icons" style="color:var(--maquinaria-red);">error_outline</i><span>Verifica los campos marcados en rojo.</span></div>');
                    Object.entries(res.body.errors).forEach(function (pair) {
                        var field = pair[0], msgs = pair[1];
                        var msg = Array.isArray(msgs) ? msgs[0] : String(msgs);
                        var input = document.getElementById(field) || document.querySelector('[name="' + field + '"]');
                        if (!input) return;
                        // Los desplegables propios guardan el valor en un <input type="hidden">, que NO
                        // tiene caja: marcarlo de rojo no se veia y el scrollIntoView de abajo no movia
                        // la pantalla, asi que el error del Frente pasaba desapercibido y parecia que el
                        // boton "no hacia nada". Se marca el disparador VISIBLE del desplegable.
                        var dd = input.closest('.custom-dropdown');
                        var visible = (input.type === 'hidden' && dd)
                            ? (dd.querySelector('.dropdown-trigger') || dd)
                            : input;
                        visible.classList.add('is-invalid');
                        var parent = dd ? dd.parentNode : input.parentNode;
                        if (parent) {
                            var fb = document.createElement('span');
                            fb.className = 'error-message-inline';
                            fb.innerText = msg;
                            parent.appendChild(fb);
                        }
                    });
                    var first = form.querySelector('.is-invalid');
                    if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }
                var errMsg = res.body.message || 'No se pudo registrar.';
                if (window.showModal) window.showModal({ type: 'error', title: 'Error', message: errMsg, confirmText: 'Entendido', hideCancel: true });
            })
            .catch(function (err) {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
                liberarSubmit();
                if (window.showModal) window.showModal({ type: 'error', title: 'Error de red', message: 'No se pudo contactar el servidor.', confirmText: 'Entendido', hideCancel: true });
            });
        });
    }

    // ── Validación en vivo de unicidad (segundo plano) ──
    // El form de EDIT usa equipos_form.js; este form es 'createUnifiedForm' (que
    // equipos_form.js NO reconoce), así que la verificación en vivo se maneja aquí.
    // Es consciente del modo (equipo vs auxiliar) para consultar la tabla correcta:
    // SERIAL_CHASIS/CODIGO_PATIO/PLACA/SERIAL_DE_MOTOR → equipos; SERIAL/CODIGO_INTERNO → auxiliares.
    (function setupLiveUnique() {
        var ENDPOINTS = {
            equipo: '{{ route("equipos.checkUnique") }}',
            aux:    '{{ route("equipos-auxiliares.checkUnique") }}'
        };
        var FIELDS = ['serial_principal', 'serial_motor', 'codigo_interno', 'placa'];

        function mapField(id, isAux) {
            switch (id) {
                case 'serial_principal': return { field: isAux ? 'SERIAL' : 'SERIAL_CHASIS', url: isAux ? ENDPOINTS.aux : ENDPOINTS.equipo };
                case 'codigo_interno':   return { field: isAux ? 'CODIGO_INTERNO' : 'CODIGO_PATIO', url: isAux ? ENDPOINTS.aux : ENDPOINTS.equipo };
                case 'serial_motor':     return isAux ? null : { field: 'SERIAL_DE_MOTOR', url: ENDPOINTS.equipo }; // solo equipo
                case 'placa':            return isAux ? null : { field: 'PLACA', url: ENDPOINTS.equipo };           // solo equipo
                default: return null;
            }
        }
        function setErr(input, msg) {
            input.classList.add('is-invalid');
            var parent = input.parentNode;
            if (!parent) return;
            parent.querySelectorAll('.error-message-inline').forEach(function (el) { el.remove(); });
            var fb = document.createElement('span');
            fb.className = 'error-message-inline';
            fb.innerText = msg;
            parent.appendChild(fb);
        }
        function clrErr(input) {
            input.classList.remove('is-invalid');
            var parent = input.parentNode;
            if (parent) parent.querySelectorAll('.error-message-inline').forEach(function (el) { el.remove(); });
        }
        function check(input) {
            var isAux = (document.getElementById('__modo').value === 'auxiliar');
            var m = mapField(input.id, isAux);
            if (!m) return;
            var val = (input.value || '').trim();
            if (!val) { clrErr(input); input.dataset.isDuplicate = 'false'; input.dataset.lastChecked = ''; return; }
            // Cachea por modo+valor: cambiar de modo fuerza re-chequeo (otra tabla).
            var key = (isAux ? 'A:' : 'E:') + val.toUpperCase();
            if (input.dataset.lastChecked === key) return;
            input.dataset.lastChecked = key;

            var ctrl = new AbortController();
            var to = setTimeout(function () { ctrl.abort(); }, 8000);
            window.apiFetch(m.url + '?field=' + encodeURIComponent(m.field) + '&value=' + encodeURIComponent(val), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: ctrl.signal})
            .then(function (r) { return r.json(); })
            .then(function (d) {
                clearTimeout(to);
                if (d && d.exists) { setErr(input, 'Este valor ya está registrado.'); input.dataset.isDuplicate = 'true'; }
                else { clrErr(input); input.dataset.isDuplicate = 'false'; }
            })
            .catch(function () { clearTimeout(to); }); // timeout/red: silencioso (el server revalida al enviar)
        }

        FIELDS.forEach(function (id) {
            var el = document.getElementById(id);
            if (el && el.dataset.liveBound !== '1') {
                el.dataset.liveBound = '1';
                el.addEventListener('blur', function () { check(el); });
            }
        });
        // Al cambiar de modo: limpiar error/caché de los campos compartidos.
        // Guard: la navegación SPA re-ejecuta este <script>, y un listener sobre `window`
        // NO muere con el DOM de la página (a diferencia de los de arriba, atados a los
        // inputs vía data-liveBound). Sin la bandera se apilaba uno por cada visita.
        // Resuelve los campos por getElementById en cada disparo, así el handler del
        // primer montaje sigue siendo válido para los siguientes.
        if (!window.__eqCreateModeListenerBound) {
            window.__eqCreateModeListenerBound = true;
            window.addEventListener('unified-mode-changed', function () {
                FIELDS.forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) { clrErr(el); el.dataset.lastChecked = ''; el.dataset.isDuplicate = 'false'; }
                });
            });
        }
    })();
})();
</script>
@endsection
