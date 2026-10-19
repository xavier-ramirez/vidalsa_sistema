@extends('layouts.estructura_base')

@section('title', 'Gestión de Frentes')

@section('content')
    {{-- El CSS de esta pantalla vive en estilos_globales.css, sección "Frentes de Trabajo". --}}
    @include('admin.partials.page_header', [
    'titulo'       => (isset($frente) && $frente->exists) ? 'Edición de Frente de Trabajo' : 'Registro de Frente de Trabajo',
    'tituloId'     => 'formTitle',
    'tituloEstilo' => 'font-size:26px;',
    'align'        => 'center',
    'margin'       => '0 auto 8px auto',
])

    <div class="admin-card frentes-card" style="max-width: 800px; margin: 0 auto;">

        {{-- Buscador de frentes (para editar uno) + botón "Sin equipos". Ocupa el ancho del
             formulario: sus bordes coinciden con los de los campos de abajo. --}}
        <div class="frentes-buscar-fila">
            <div class="frentes-buscar">
                <div class="custom-dropdown" id="frenteSearchDropdown" style="width: 100%;">
                    <div class="dropdown-trigger"
                        style="background: #fff; border: 1px solid #cbd5e0; border-radius: 10px; height: 38px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">

                        <div
                            style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                            <i class="material-icons" style="font-size: 18px;">search</i>
                        </div>

                        <input type="text" id="filterSearchInput" placeholder="Buscar frente para editar..."
                            style="width: 100%; border: none; background: transparent; padding: 6px 5px; font-size: 13.5px; outline: none; color: #4a5568;"
                            autocomplete="off" oninput="window.filterFrentesDropdown(this)">

                        <div style="display: flex; align-items: center; padding-right: 10px;">
                            <i id="btn_clear_search_frente" class="material-icons"
                                style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: none;"
                                onclick="event.stopPropagation(); window.clearFrentesSearchSPA();">close</i>
                        </div>
                    </div>

                    <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                        <div class="dropdown-item-list" id="frenteItemsList" style="max-height: 250px; overflow-y: auto;">
                            @if(isset($allFrentes))
                                @foreach($allFrentes as $f)
                                    {{-- data-id: identidad estable del item. El nombre se puede
                                         EDITAR, y sin el id addToSearchList no reconocia el item
                                         ya listado y dejaba dos entradas del mismo frente. --}}
                                    <div class="dropdown-item search-result-item" data-id="{{ $f->ID_FRENTE }}"
                                        data-name="{{ $f->NOMBRE_FRENTE }}"
                                        onclick="selectFrenteSPA('{{ $f->ID_FRENTE }}')">
                                        {{ $f->NOMBRE_FRENTE }}
                                    </div>
                                @endforeach
                            @endif
                            <div id="no-results-msg"
                                style="display: none; padding: 10px 15px; color: #94a3b8; font-size: 14px;">No se
                                encontraron resultados</div>
                        </div>
                    </div>
                </div>
            </div>
            {{-- Botón "Sin equipos" (solo el ícono): abre un modal con los frentes que no tienen
                 ningún equipo ni auxiliar asignado —de cualquier estatus— para borrarlos. --}}
            <button type="button" id="btnSinEquipos" onclick="window.abrirModalSinEquipos()" title="Frentes sin equipos asignados"
                aria-label="Frentes sin equipos asignados" class="btn-primary-maquinaria btn-secondary">
                <i class="material-icons">domain_disabled</i>
            </button>
            {{-- Eliminar el frente cargado: solo el ícono de papelera, junto al buscador donde se
                 eligió, y solo con un frente cargado (frentes_spa.js lo muestra/oculta). Borrado
                 LÓGICO: sale de listas y selectores, el historial conserva su nombre; el servidor
                 lo rechaza si tiene equipos, auxiliares o stock de almacén. --}}
            <button type="button" id="btnEliminarFrente" class="btn-primary-maquinaria btn-eliminar-frente"
                title="Eliminar este frente" aria-label="Eliminar este frente"
                data-frente-id="{{ $frente->ID_FRENTE ?? '' }}"
                data-frente-nombre="{{ $frente->NOMBRE_FRENTE ?? '' }}"
                onclick="window.eliminarFrenteSPA(this.dataset.frenteId, this.dataset.frenteNombre)"
                @unless(isset($frente) && $frente->exists) hidden @endunless>
                <i class="material-icons">delete_outline</i>
            </button>
        </div>

        <form
            action="{{ (isset($frente) && $frente->exists) ? route('frentes.update', $frente->ID_FRENTE) : route('frentes.store') }}"
            {{-- SIN onsubmit que encienda el spinner. frentes_spa.js atrapa este submit,
                 hace preventDefault() y guarda por fetch, y él YA enciende y apaga el spinner
                 por su cuenta. Teniéndolo aquí también se encendía DOS veces y solo se apagaba
                 una: como el preloader lleva un contador de referencias, quedaba en 1 y el
                 spinner no se iba nunca. Eso era el "le di guardar y se quedó cargando": el
                 frente SÍ se guardaba, pero la pantalla se quedaba tapada. Y como el submit
                 nativo está cancelado, tampoco había recarga que lo limpiara. --}}
            method="POST" id="frenteForm">
            @csrf
            @if(isset($frente) && $frente->exists) @method('PUT') @endif
            <input type="hidden" id="ID_FRENTE" name="ID_FRENTE" value="{{ $frente->ID_FRENTE ?? '' }}">

            <div class="form-grid">
                <!-- Row 1 -->
                <div>
                    <label for="NOMBRE_FRENTE" class="form-label">Nombre del Frente <span
                            style="color: red;">*</span></label>
                    <input type="text" id="NOMBRE_FRENTE" name="NOMBRE_FRENTE" class="form-input-custom"
                        placeholder="Ej: MINA 1"
                        value="{{ old('NOMBRE_FRENTE', $frente->NOMBRE_FRENTE ?? '') }}" required autocomplete="off">
                </div>

                <div>
                    <div class="form-label">Tipo de Frente <span style="color: red;">*</span></div>
                    <div class="custom-dropdown" id="tipoSelect">
                        <input type="hidden" name="TIPO_FRENTE" id="input_tipo"
                            value="{{ old('TIPO_FRENTE', $frente->TIPO_FRENTE ?? '') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('tipoSelect', event)">
                            @php $tipoActual = old('TIPO_FRENTE', $frente->TIPO_FRENTE ?? ''); @endphp
                            <span id="label_tipo">{{ \App\Models\FrenteTrabajo::TIPOS[$tipoActual] ?? 'Seleccione Tipo...' }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        {{-- Opciones desde FrenteTrabajo::TIPOS (valor => nombre en pantalla). VENDIDO y
                             TRÁMITE DE VENTA quedan fuera de la flota, como ESPECIAL, y en Equipos se resaltan. --}}
                        <div class="dropdown-content">
                            @foreach(\App\Models\FrenteTrabajo::TIPOS as $valorTipo => $nombreTipo)
                                <div class="dropdown-item" data-value="{{ $valorTipo }}"
                                    onclick="selectOption('tipoSelect', '{{ $valorTipo }}', '{{ $nombreTipo }}', 'tipo')">{{ $nombreTipo }}</div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <!-- Row 2 -->
                <div>
                    <label for="UBICACION" class="form-label">Ubicación / Geografía <span
                            style="color: red;">*</span></label>
                    <input type="text" id="UBICACION" name="UBICACION" class="form-input-custom"
                        placeholder="Ej: Sector 4" value="{{ old('UBICACION', $frente->UBICACION ?? '') }}" required
                        autocomplete="off">
                </div>

                {{-- ZONA: nombre corto (zona/ciudad) que se imprime en el renglón
                     "Lugar, fecha" del Acta de Traslado (ej: "MATURÍN 02/06/2026").
                     Opcional: si se deja vacío, el acta usa el Nombre del Frente. --}}
                <div>
                    <label for="ZONA" class="form-label">Zona / Ciudad <span style="font-weight:400; color:#94a3b8; font-size:12px;">(sale en el Acta de Traslado)</span></label>
                    <input type="text" id="ZONA" name="ZONA" class="form-input-custom"
                        placeholder="Ej: MATURÍN" value="{{ old('ZONA', $frente->ZONA ?? '') }}" maxlength="100"
                        autocomplete="off">
                </div>

                <div>
                    <div class="form-label">Estatus del Proyecto <span style="color: red;">*</span></div>
                    <div class="custom-dropdown" id="statusSelect">
                        <input type="hidden" name="ESTATUS_FRENTE" id="input_estatus"
                            value="{{ old('ESTATUS_FRENTE', $frente->ESTATUS_FRENTE ?? '') }}">
                        <div class="dropdown-trigger" onclick="toggleDropdown('statusSelect', event)">
                            <span
                                id="label_estatus">{{ old('ESTATUS_FRENTE', $frente->ESTATUS_FRENTE ?? 'Seleccione Estatus...') }}</span>
                            <i class="material-icons">expand_more</i>
                        </div>
                        <div class="dropdown-content">
                            <div class="dropdown-item"
                                onclick="selectOption('statusSelect', 'ACTIVO', 'ACTIVO', 'estatus')">ACTIVO</div>
                            <div class="dropdown-item"
                                onclick="selectOption('statusSelect', 'FINALIZADO', 'FINALIZADO', 'estatus')">FINALIZADO
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Contratos asociados al proyecto (chips multi-valor; se sugieren en el modal
                     "Registrar salida" del inventario cuando se elige este frente como proyecto).
                     Ocupa 1 columna y el contenedor imita exactamente el estilo de los demas
                     `.form-input-custom` del formulario: border-radius 12px, border 1px #cbd5e0,
                     background blanco, padding 8px 12px. Asi el chip-box se ve como un input
                     normal (misma altura y curvatura que UBICACION, NOMBRE_FRENTE, etc.). -->
                <div>
                    <label class="form-label" for="contratos_input">N° de Contrato(s) asociados al proyecto</label>
                    {{-- min-height 36px + padding/borde/radio iguales a .form-input-custom
                         (8px 12px de alto efectivo) para que el chip-box se vea EXACTAMENTE
                         como un input normal (mismo alto que Zona/Ubicación) cuando está
                         vacío, y crezca solo si se agregan chips. --}}
                    <div id="contratos_chip_box"
                         style="background:white;border:1px solid #cbd5e0;border-radius:12px;padding:4px 12px;min-height:36px;display:flex;flex-wrap:wrap;align-items:center;gap:5px;box-sizing:border-box;transition:border-color .2s;">
                        <input type="text" id="contratos_input" autocomplete="off"
                               placeholder="Escribe y pulsa Enter o coma"
                               style="flex:1;min-width:110px;border:none;outline:none;background:transparent;font-size:13.5px;padding:0 2px;text-transform:uppercase;line-height:1.4;">
                    </div>
                    {{-- El hidden guarda los contratos como CSV; el backend lo splittea + normaliza. --}}
                    <input type="hidden" id="CONTRATOS_HIDDEN" name="CONTRATOS"
                           value="{{ old('CONTRATOS', is_array($frente->CONTRATOS ?? null) ? implode(',', $frente->CONTRATOS) : '') }}">
                </div>

                @php
                    // Los cinco responsables que firman el Acta de Traslado (columnas RESP_n_*):
                    // título, quién suele ser y un ejemplo de cargo. Un bloque arranca ABIERTO si
                    // trae nombre/cargo/cédula (datos guardados o reenvío con error); el resto,
                    // recogido. Al elegir un frente en el buscador lo mismo lo hace frentes_spa.js.
                    $responsables = [
                        1 => ['SOLICITADO',      'Coord. Mecánica Liviana', 'Ej: Gerente'],
                        2 => ['SOLICITADO Alt.', 'Coord. Mecánica Pesada',  'Ej: Supervisor'],
                        3 => ['ELABORADO',       'Transporte y Logística',  'Ej: Jefe de Taller'],
                        4 => ['REVISADO',        'Sub-gerente',             'Ej: Residente'],
                        5 => ['APROBADO',        'Gerente',                 'Ej: Gerente General'],
                    ];
                @endphp

                @foreach($responsables as $i => [$titulo, $quien, $ejemploCargo])
                    @php
                        $abierto = filled(old("RESP_{$i}_NOM", $frente->{"RESP_{$i}_NOM"} ?? ''))
                                || filled(old("RESP_{$i}_CAR", $frente->{"RESP_{$i}_CAR"} ?? ''))
                                || filled(old("RESP_{$i}_CED", $frente->{"RESP_{$i}_CED"} ?? ''));
                        $equ = old("RESP_{$i}_EQU", $frente->{"RESP_{$i}_EQU"} ?? '');
                    @endphp
                    <div id="respHead{{ $i }}" class="resp-head {{ $abierto ? '' : 'cerrado' }}" onclick="window.toggleResp({{ $i }})">
                        <span class="resp-titulo">{{ $titulo }}</span>
                        <span class="resp-quien">{{ $quien }}</span>
                        <i class="material-icons resp-chevron">expand_more</i>
                    </div>

                    <div class="resp-grid {{ $abierto ? '' : 'resp-collapsed' }}" id="respBody{{ $i }}" style="grid-column: span 2;">
                        <div>
                            <label for="RESP_{{ $i }}_NOM" class="form-label">Nombre Completo</label>
                            <input type="text" id="RESP_{{ $i }}_NOM" name="RESP_{{ $i }}_NOM" class="form-input-custom"
                                placeholder="Nombre" value="{{ old("RESP_{$i}_NOM", $frente->{"RESP_{$i}_NOM"} ?? '') }}" autocomplete="off">
                        </div>
                        <div>
                            <label for="RESP_{{ $i }}_CAR" class="form-label">Cargo</label>
                            <input type="text" id="RESP_{{ $i }}_CAR" name="RESP_{{ $i }}_CAR" class="form-input-custom"
                                placeholder="{{ $ejemploCargo }}" value="{{ old("RESP_{$i}_CAR", $frente->{"RESP_{$i}_CAR"} ?? '') }}" autocomplete="off">
                        </div>
                        <div>
                            <label for="RESP_{{ $i }}_CED" class="form-label">Cédula</label>
                            <input type="text" id="RESP_{{ $i }}_CED" name="RESP_{{ $i }}_CED" class="form-input-custom"
                                placeholder="Ej: V-12345678" value="{{ old("RESP_{$i}_CED", $frente->{"RESP_{$i}_CED"} ?? '') }}" maxlength="20" autocomplete="off">
                        </div>
                        <div>
                            <span class="form-label">Filtro Firma <span class="form-label-hint">(Opcional)</span></span>
                            <div class="custom-dropdown" id="resp{{ $i }}EquSelect">
                                <input type="hidden" name="RESP_{{ $i }}_EQU" id="input_resp{{ $i }}_equ" value="{{ $equ }}">
                                <div class="dropdown-trigger" onclick="toggleDropdown('resp{{ $i }}EquSelect', event)">
                                    <span id="label_resp{{ $i }}_equ">{{ $equ ?: 'SIN FILTRO' }}</span>
                                    <i class="material-icons">expand_more</i>
                                </div>
                                <div class="dropdown-content">
                                    <div class="dropdown-item" onclick="selectOption('resp{{ $i }}EquSelect', '', 'SIN FILTRO', 'resp{{ $i }}_equ')">SIN FILTRO</div>
                                    @foreach($categorias as $cat)
                                        <div class="dropdown-item" onclick="selectOption('resp{{ $i }}EquSelect', '{{ $cat }}', '{{ $cat }}', 'resp{{ $i }}_equ')">{{ $cat }}</div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Cancelar y Registrar/Guardar, compactos como en el formulario de usuarios.
                 Cancelar: editando un frente vuelve a "Registrar nuevo"; si no, sale al menú. --}}
            <div class="frentes-btn-row">
                <button type="button" class="btn-primary-maquinaria btn-secondary btn-compacto" onclick="window.cancelarFrente()">
                    Cancelar
                </button>

                <button type="submit" class="btn-primary-maquinaria btn-compacto">
                    <i class="material-icons" id="submitBtnIcon">{{ (isset($frente) && $frente->exists) ? 'save' : 'add_circle' }}</i>
                    <span id="submitBtnText">{{ (isset($frente) && $frente->exists) ? 'Guardar' : 'Registrar' }}</span>
                </button>
            </div>
        </form>

        {{-- Chips de contratos: van aquí (y no en frentes_spa.js) porque se arman sobre el
             DOM de ESTA carga; la SPA vuelve a correr este <script> en cada visita. --}}
        <script>
            // ── Chips de "Contratos asociados" ──────────────────────────────────
            // El hidden #CONTRATOS_HIDDEN guarda los valores como CSV
            // ("CTR-2026-0042,CTR-2026-0099"). El backend (FrenteRequest::prepareForValidation)
            // splittea por coma/punto-y-coma/salto de línea y normaliza a array UPPER.
            (function () {
                var hidden = document.getElementById('CONTRATOS_HIDDEN');
                var box    = document.getElementById('contratos_chip_box');
                var input  = document.getElementById('contratos_input');
                if (!hidden || !box || !input) return;

                function parseList() {
                    return String(hidden.value || '')
                        .split(',')
                        .map(function (s) { return s.trim(); })
                        .filter(function (s) { return s.length > 0; });
                }
                function writeList(list) {
                    var dedup = [];
                    list.forEach(function (v) { if (v && dedup.indexOf(v) === -1) dedup.push(v); });
                    hidden.value = dedup.join(',');
                    render();
                }
                function render() {
                    // Limpia los chips anteriores (todo lo que no sea el input).
                    Array.prototype.slice.call(box.children).forEach(function (c) {
                        if (c !== input) c.remove();
                    });
                    parseList().forEach(function (val) {
                        var chip = document.createElement('span');
                        chip.style.cssText = 'background:#e0f2fe;color:#0369a1;padding:3px 4px 3px 10px;border-radius:99px;font-size:12.5px;font-weight:700;display:inline-flex;align-items:center;gap:3px;font-family:monospace;';
                        chip.innerHTML = window.escapeHtml(val) +
                            ' <i class="material-icons" style="font-size:15px;cursor:pointer;color:#0284c7;" title="Quitar">close</i>';
                        chip.querySelector('i').addEventListener('click', function () {
                            var current = parseList();
                            var idx = current.indexOf(val);
                            if (idx !== -1) { current.splice(idx, 1); writeList(current); }
                        });
                        box.insertBefore(chip, input);
                    });
                }
                function addFromInput() {
                    var raw = String(input.value || '').toUpperCase().trim();
                    if (!raw) return;
                    var partes = raw.split(/[,;\n\r]+/).map(function (s) { return s.trim(); }).filter(Boolean);
                    if (partes.length === 0) return;
                    writeList(parseList().concat(partes));
                    input.value = '';
                }
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
                        e.preventDefault();
                        addFromInput();
                    } else if (e.key === 'Backspace' && input.value === '') {
                        var current = parseList();
                        if (current.length) { current.pop(); writeList(current); }
                    }
                });
                input.addEventListener('blur', addFromInput);
                // Clic en cualquier parte del wrapper enfoca el input.
                box.addEventListener('click', function (e) { if (e.target === box) input.focus(); });

                // Punto de entrada para la SPA (frentes_spa.js): al seleccionar un frente en
                // el buscador o al limpiar el formulario hay que reescribir el hidden Y
                // repintar los chips. Se expone el writeList de aqui en vez de duplicar el
                // render en el .js: los chips los dibuja un solo sitio.
                window.frentesSetContratos = function (valor) {
                    // Acepta el array que devuelve el endpoint (columna JSON) o un CSV.
                    var lista = Array.isArray(valor)
                        ? valor
                        : String(valor == null ? '' : valor).split(',');
                    writeList(lista.map(function (s) { return String(s).trim(); }));
                };

                // Render inicial — usa el value renderizado por blade (old() o frente existente).
                render();
            })();
        </script>
    </div>
{{-- JS de esta pantalla (solo lo usa ella; antes iba en el layout y lo bajaban todas). La SPA lo ejecuta una vez por pestaña. --}}
<script src="{{ asset('js/maquinaria/frentes_spa.js') }}?v={{ @filemtime(public_path('js/maquinaria/frentes_spa.js')) }}"></script>
@endsection