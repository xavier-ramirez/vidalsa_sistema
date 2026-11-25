@extends('layouts.estructura_base')
@section('title', 'Cargar Consumibles')

@section('content')
    <style>
        /* ── ZONA DE PEGADO ── (aviso: el pegado real es Ctrl+V sobre la tabla) */
        .paste-zone {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 8px 15px;
            margin-bottom: 12px;
            border: 2px dashed #bfdbfe;
            border-radius: 8px;
            background: #f0f9ff;
            color: #1d4ed8;
            text-align: center;
        }

        .paste-zone i {
            font-size: 20px;
        }

        .paste-zone span {
            font-size: 13px;
            font-weight: 600;
        }

        /* ── TABLA TIPO EXCEL ── */
        .tbl-wrap {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        #tablaFilas {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            min-width: 700px;
        }

        #tablaFilas thead th {
            background: #1e293b;
            color: #e2e8f0;
            font-weight: 700;
            padding: 9px 8px;
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .4px;
            border-right: 1px solid #334155;
            white-space: nowrap;
        }

        #tablaFilas thead th:first-child {
            width: 36px;
            text-align: center;
        }

        #tablaFilas thead th:last-child {
            width: 36px;
            border-right: none;
        }

        #tablaFilas tbody tr:nth-child(even) {
            background: #f8fafc;
        }

        #tablaFilas tbody tr:hover {
            background: #eff6ff;
        }

        #tablaFilas td {
            padding: 0;
            border-bottom: 1px solid #e2e8f0;
            border-right: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        #tablaFilas td:first-child {
            text-align: center;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 4px;
            background: #f8fafc;
            border-right: 2px solid #e2e8f0;
        }

        #tablaFilas td:last-child {
            border-right: none;
            text-align: center;
            background: #fafafa;
        }

        /* Frente de la fila: lo reconocido sale debajo (verde) o «no reconocido» (rojo). */
        .fila-frente-estado { font-size: 11px; padding: 0 8px 5px; line-height: 1.25; }
        .fila-frente-estado:empty { display: none; }
        .fila-frente-estado.ok { color: #059669; }
        .fila-frente-estado.mal { color: #dc2626; font-weight: 600; }
        #frenteFilaDropdown { display: none; position: fixed; background: #fff; border: 1px solid #cbd5e0; border-radius: 10px;
            box-shadow: 0 8px 20px rgba(0,0,0,.12); z-index: 1200; max-height: 240px; overflow-y: auto; }
        #frenteFilaDropdown div { padding: 9px 12px; cursor: pointer; font-size: 13px; color: #1e293b; }
        #frenteFilaDropdown div:hover { background: #f1f5f9; }

        /* Celdas editables */
        #tablaFilas td input[type=text],
        #tablaFilas td input[type=date],
        #tablaFilas td input[type=number] {
            width: 100%;
            border: none;
            background: transparent;
            outline: none;
            padding: 8px 8px;
            font-size: 13px;
            color: #1e293b;
            font-family: inherit;
        }

        #tablaFilas td input:focus {
            background: #eff6ff;
            outline: 2px solid #0067b1;
            outline-offset: -2px;
            border-radius: 4px;
        }

        #tablaFilas .btn-del {
            background: none;
            border: none;
            color: #cbd5e0;
            padding: 6px;
            cursor: pointer;
            border-radius: 6px;
            transition: all .2s;
        }

        #tablaFilas .btn-del:hover {
            background: #fee2e2;
            color: #ef4444;
        }

        /* Los tres selectores con el mismo relleno: el CSS global de .custom-dropdown
           dejaba el texto de Tipo/Unidad pegado al borde, distinto al de Frente. */
        #tipoDropdownCargar .dropdown-trigger input,
        #unidadDropdownCargar .dropdown-trigger input {
            padding: 0 14px !important;
        }

        .con-acciones {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            align-items: center;
            margin-top: 18px;
        }

        .con-acciones .btn-primary-maquinaria {
            margin-left: 0;
        }

        @media (max-width: 900px) {
            .con-acciones {
                justify-content: center;
            }

            .hide-on-mobile {
                display: none !important;
            }
        }
    </style>

    {{-- CABECERA --}}
    <div
        style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; flex-wrap:wrap; gap:12px;">
        <div>
            <h1 class="page-title" style="margin-bottom:4px;">
                <span class="page-title-line2" style="color:#000;">Cargar Consumibles</span>
            </h1>
        </div>
    </div>

    @can('super.admin')
        {{-- Las dos formas de cargar en la misma pantalla, como en Registrar equipos: arriba la
             carga masiva desde Excel; abajo la tabla. --}}
        @include('admin.consumibles.partials.cargar_excel')
    @endcan

    <form method="POST" action="{{ route('consumibles.guardarLote') }}" id="formLote">
        @csrf



        {{-- Datalists para autocompletado en columna Espec --}}
        <datalist id="listAceite">
            <option value="15W-40">
            <option value="10W-30">
            <option value="10W-40">
            <option value="5W-30">
            <option value="5W-40">
            <option value="SAE 30">
            <option value="SAE 40">
            <option value="SAE 90">
            <option value="SAE 140">
            <option value="80W-90">
            <option value="85W-140">
            <option value="ATF Dexron">
            <option value="ISO 46">
            <option value="ISO 68">
        </datalist>
        <datalist id="listCaucho">
            <option value="11R22.5">
            <option value="295/80R22.5">
            <option value="315/80R22.5">
            <option value="385/65R22.5">
            <option value="275/70R22.5">
            <option value="10.00R20">
            <option value="12.00R20">
            <option value="900R20">
            <option value="1200R24">
            <option value="265/65R17">
            <option value="245/70R16">
        </datalist>

        {{-- ═══ TABLA / ZONA DE PEGADO ═══ --}}
        <div class="admin-card" style="box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); padding: 25px; margin-bottom: 20px;">

            {{-- Tabla envuelta con Selectores --}}
            <div class="tbl-wrap" style="padding: 15px; background: #fff;">

                {{-- SELECTORES INTEGRADAMENTE DENTRO DEL CONTENEDOR --}}
                <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:end; margin-bottom: 20px;">
                    {{-- FRENTE (primer filtro, a pedido del cliente) --}}
                    <div style="flex: 1; min-width: 200px;">
                        <div style="position:relative;">
                            <div
                                style="box-sizing:border-box; padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; position:relative;">
                                <input type="text" id="frenteSearch" name="search_frente_cargar"
                                    placeholder="Frente de Trabajo (filas sin proyecto)" autocomplete="off"
                                    style="width:100%; border:none; background:transparent; padding:0 14px; font-size:13px; outline:none; height:100%; font-family:inherit;"
                                    oninput="filtrarFrentes(this.value)" onfocus="mostrarTodosLosFrente()">
                                <i class="material-icons"
                                    style="padding:0 10px; color:#94a3b8; font-size:18px; cursor:default;"
                                    onclick="document.getElementById('frenteSearch').focus()">arrow_drop_down</i>
                            </div>
                            <input type="hidden" name="id_frente" id="idFrenteHidden" value="{{ old('id_frente') }}">
                            <div id="frenteDropdown"
                                style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #cbd5e0; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,.12); z-index:200; max-height:240px; overflow-y:auto; margin-top:4px;">
                            </div>
                        </div>
                    </div>

                    {{-- TIPO CONSUMIBLE --}}
                    <div style="flex: 1; min-width: 160px; position:relative;">
                        <div class="custom-dropdown" id="tipoDropdownCargar" data-default-label="— Seleccionar —">
                            <input type="hidden" name="tipo_consumible" id="tipoSelect" value="{{ old('tipo_consumible') }}"
                                required>
                            <div class="dropdown-trigger"
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; cursor:default; position:relative;">
                                <input type="text" data-filter-search id="search_tipo_cargar" name="search_tipo_cargar"
                                    readonly
                                    value="{{ old('tipo_consumible') ? \App\Models\Consumible::tiposLabel()[old('tipo_consumible')] : '' }}"
                                    placeholder="Tipo de Consumible *"
                                    style="width:100%; border:none; background:transparent; padding:0 14px; font-size:13px; outline:none; height:100%; cursor:default; font-family:inherit;"
                                    autocomplete="off">
                                <i class="material-icons"
                                    style="padding:0 10px; color:#94a3b8; font-size:18px;">arrow_drop_down</i>
                            </div>
                            <div class="dropdown-content"
                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                                    @foreach($tipos as $val => $label)
                                        <div class="dropdown-item {{ old('tipo_consumible') == $val ? 'selected' : '' }}"
                                            data-value="{{ $val }}"
                                            onclick="window.selectOption('tipoDropdownCargar', '{{ $val }}', '{{ addslashes($label) }}'); window.actualizarUnidad();">
                                            {{ $label }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- UNIDAD --}}
                    <div style="flex: 1; min-width: 120px;">
                        <div class="custom-dropdown" id="unidadDropdownCargar" data-default-label="Litros">
                            <input type="hidden" name="unidad" id="unidadSelect" value="{{ old('unidad', 'LITROS') }}"
                                required>
                            <div class="dropdown-trigger"
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; cursor:default; position:relative;">
                                <input type="text" data-filter-search id="search_unidad_cargar" name="search_unidad_cargar"
                                    readonly
                                    value="{{ ['LITROS' => 'Litros', 'GALONES' => 'Galones', 'UNIDADES' => 'Unidades', 'KG' => 'Kg'][old('unidad', 'LITROS')] ?? 'Litros' }}"
                                    placeholder="Unidad *"
                                    style="width:100%; border:none; background:transparent; padding:0 14px; font-size:13px; outline:none; height:100%; cursor:default; font-family:inherit;"
                                    autocomplete="off">
                                <i class="material-icons"
                                    style="padding:0 10px; color:#94a3b8; font-size:18px;">arrow_drop_down</i>
                            </div>
                            <div class="dropdown-content"
                                style="padding:5px; max-height:none; overflow:visible; z-index:1000;">
                                <div class="dropdown-item-list" style="max-height:250px; overflow-y:auto;">
                                    @foreach($unidades as $val => $label)
                                        <div class="dropdown-item {{ old('unidad', 'LITROS') == $val ? 'selected' : '' }}"
                                            data-value="{{ $val }}"
                                            onclick="window.selectOption('unidadDropdownCargar', '{{ $val }}', '{{ addslashes($label) }}');">
                                            {{ $label }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- ACCIONES --}}
                    <div style="position:relative; flex: 0 0 auto; margin-top: auto;">
                        <button type="button" id="btnAcciones" class="btn-primary-maquinaria"
                            style="padding: 0 15px; height: 42px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"
                            onclick="document.getElementById('splitDropdownMenu').style.display = document.getElementById('splitDropdownMenu').style.display === 'none' ? 'block' : 'none'; event.stopPropagation();">
                            <i class="material-icons">settings</i>
                            <span>Acciones</span>
                            <i class="material-icons" style="font-size: 18px; margin-left: 2px;">expand_more</i>
                        </button>
                        <div id="splitDropdownMenu"
                            style="display: none; position: absolute; top: 100%; right: 0; width: 220px; background: #e2e8f0; border-radius: 8px; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); border: 1px solid #e2e8f0; z-index: 1050; margin-top: 5px; overflow: hidden; animation: slideDown 0.2s ease-out;">

                            @include('admin.consumibles.partials.acciones_navegacion', ['actual' => 'cargar'])


                        </div>
                    </div>
                </div>

                {{-- Instrucciones de Pegado Rápido --}}
                <div class="paste-zone" id="pasteZone">
                    <i class="material-icons">content_paste</i>
                    <span>Elija el tipo y pegue filas de Excel con Ctrl+V: con encabezados, cada columna va a su lugar; sin ellos, en este orden: <span id="pasteOrden">fecha, placa/serial, nombre, C.I., cantidad, origen, proyecto</span></span>
                </div>

                <table id="tablaFilas">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>FECHA *</th>
                            <th>IDENTIFICADOR <span style="font-weight:400;opacity:.7;">(placa / serial)</span></th>
                            <th>RESP. NOMBRE</th>
                            <th>RESP. C.I.</th>
                            <th>CANTIDAD *</th>
                            <th id="thEspec" style="display:none; color:#0067b1;">VISCOSIDAD</th>
                            <th>ORIGEN SUMINISTRO</th>
                            <th>FRENTE <span style="font-weight:400;opacity:.7;">(proyecto)</span></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoTabla"></tbody>
                </table>
                {{-- Lista para elegir el frente de una fila: una sola, se coloca bajo la celda. --}}
                <div id="frenteFilaDropdown"></div>
            </div>

            {{-- El botón "Agregar Fila" se eliminó: ahora la fila se agrega sola al escribir
                 en la última (auto-row en initCargarConsumibles). --}}

            {{-- ACCIONES, al pie de la tarjeta y compactas (como en Usuarios/Frentes).
                 "Guardar" solo visible con permiso super.admin
                 (coincide con el middleware del POST /consumibles/guardar-lote). --}}
            <div class="con-acciones">
                <a href="{{ route('consumibles.index') }}" class="btn-primary-maquinaria btn-secondary btn-compacto">
                    Cancelar
                </a>
                @can('super.admin')
                    <button type="submit" class="btn-primary-maquinaria btn-compacto">
                        <i class="material-icons">save</i>
                        Guardar
                    </button>
                @else
                    <span style="font-size:12px; color:#94a3b8; padding:8px 14px; border:1px dashed #cbd5e1; border-radius:8px;">
                        Solo un Super Admin puede guardar.
                    </span>
                @endcan
            </div>
        </div>

    </form>

    {{-- ═══ JAVASCRIPT ═══ --}}
    <script>
        var FRENTES = @json($frentes->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]));
        var filaCount = 0;
        var URL_FRENTES_DE_TEXTOS = @json(route('consumibles.frentesDeTextos'));

        // ── Tipo helper ──────────────────    ───────────────────────────────    ─────
        var TIPOS_ACEITE = ['ACEITE'];
        var TIPOS_CAUCHO = ['CAUCHO'];
        function tipoActual() { return document.getElementById('tipoSelect').value; }
        function necesitaEspec() { const t = tipoActual(); return TIPOS_ACEITE.includes(t) || TIPOS_CAUCHO.includes(t); }
        function datalistIdActual() { return TIPOS_ACEITE.includes(tipoActual()) ? 'listAceite' : 'listCaucho'; }

        // ── Unidad automática + mostrar/ocultar columna VISCOSIDAD/MEDIDA ────
        function actualizarUnidad() {
            const tipo = tipoActual();

            // Unidad automática
            const mapa = {
                'GASOIL': 'LITROS', 'GASOLINA': 'LITROS', 'ACEITE': 'LITROS',
                'CAUCHO': 'UNIDADES', 'REFRIGERANTE': 'LITROS', 'OTRO': 'LITROS'
            };
            const labelMapa = {
                'GASOIL': 'Litros', 'GASOLINA': 'Litros', 'ACEITE': 'Litros',
                'CAUCHO': 'Unidades', 'REFRIGERANTE': 'Litros', 'OTRO': 'Litros'
            };
            const nuevaUnidad = mapa[tipo] || 'LITROS';
            const nuevaLabel = labelMapa[tipo] || 'Litros';

            // Check if the dropdown UI exists (using custom dropdowns)
            if (document.getElementById('unidadDropdownCargar')) {
                window.selectOption('unidadDropdownCargar', nuevaUnidad, nuevaLabel);
            } else {
                document.getElementById('unidadSelect').value = nuevaUnidad;
            }

            const espec = necesitaEspec();
            const th = document.getElementById('thEspec');

            // El orden del pegado sin encabezados (pasteLoteCargarHandler): con aceite o caucho, la
            // viscosidad o el modelo va antes del origen.
            const orden = document.getElementById('pasteOrden');
            if (orden) orden.textContent = 'fecha, placa/serial, nombre, C.I., cantidad, '
                + (espec ? (TIPOS_ACEITE.includes(tipo) ? 'viscosidad, ' : 'modelo del caucho, ') : '') + 'origen, proyecto';

            // Cabecera de columna
            if (espec) {
                th.style.display = '';
                if (TIPOS_ACEITE.includes(tipo)) {
                    th.textContent = '⬡ VISCOSIDAD';
                    th.style.color = '#0067b1';
                } else {
                    th.textContent = '⬡ MODELO CAUCHO';
                    th.style.color = '#059669';
                }
            } else {
                th.style.display = 'none';
            }

            // Actualizar celdas existentes en la tabla
            const dlId = espec ? datalistIdActual() : '';
            document.querySelectorAll('[data-espec-cell]').forEach(td => {
                td.style.display = espec ? '' : 'none';
                const inp = td.querySelector('input');
                if (inp) {
                    inp.setAttribute('list', dlId);   // el valor se queda: el servidor lo ignora si el tipo no lo usa
                }
            });
        }


        // ── Frente dropdown ───────────────────────────────────────────────
        function renderDropdownFrente(lista) {
            const dd = document.getElementById('frenteDropdown');
            if (!dd) return;
            dd.innerHTML = lista.length === 0
                ? '<div style="padding:10px 14px; color:#94a3b8; font-size:13px;">Sin resultados</div>'
                : lista.map(f =>
                    // El nombre escapado y fuera del onclick: una comilla en él no rompe la lista.
                    `<div data-frente-id="${Number(f.id)}" onclick="window.seleccionarFrente(Number(this.dataset.frenteId), this.textContent.trim())"
                          style="padding:10px 14px; cursor:pointer; font-size:13px; color:#1e293b; transition:background .15s;"
                          onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">${window.escapeHtml(f.nombre)}</div>`
                ).join('');
            dd.style.display = 'block';
        }

        function mostrarTodosLosFrente() { renderDropdownFrente(FRENTES); }

        function filtrarFrentes(q) {
            if (!q || q.length < 1) { renderDropdownFrente(FRENTES); return; }
            // Las palabras en cualquier orden, sin acentos, con margen si nada sale exacto
            // (fuzzy_search.js · filtrar, la misma regla que los filtros Frente de las pantallas).
            renderDropdownFrente(window.FuzzySearch.filtrar(FRENTES, q, f => f.nombre));
        }

        // Agregado window. prefix preventivo
        window.seleccionarFrente = function (id, nombre) {
            document.getElementById('idFrenteHidden').value = id;
            document.getElementById('frenteSearch').value = nombre;
            document.getElementById('frenteDropdown').style.display = 'none';
            const badge = document.getElementById('frenteSeleccionado');
            if (badge) {
                badge.textContent = '✓ ' + nombre;
                badge.style.display = 'block';
            }
        };

        // Guardia SPA: navegacion.js re-ejecuta este <script> en cada visita; sin la bandera
        // este listener de document se apila una vez por visita. Como es una arrow anónima no
        // se puede quitar luego, así que se registra UNA sola vez por pestaña.
        if (!window.__cargarClickBound) {
        window.__cargarClickBound = true;
        document.addEventListener('click', e => {
            const dd = document.getElementById('frenteDropdown');
            if (dd && !e.target.closest('#frenteSearch') && !e.target.closest('#frenteDropdown')) {
                dd.style.display = 'none';
            }

            const accMenu = document.getElementById('splitDropdownMenu');
            if (accMenu && accMenu.style.display === 'block' && !e.target.closest('#btnAcciones') && !e.target.closest('#splitDropdownMenu')) {
                accMenu.style.display = 'none';
            }
        });
        } // /guardia __cargarClickBound

        // ── Frente de cada fila ───────────────────────────────────────────
        // Lo pegado puede traer varios proyectos: cada fila lleva su texto (columna FRENTE) y el
        // frente que se le reconoce con las reglas del Excel de gasoil (frentesDeTextos →
        // IdentificadorConsumo). Lo que no se reconoce se elige en la fila y, al guardar, queda
        // aprendido. Una fila sin texto usa el frente de arriba.
        function estadoFrenteFila(inp, id, nombre) {
            const td = inp.closest('td');
            const texto = inp.value.trim();
            td.querySelector('[name$="[id_frente]"]').value = texto && id ? id : '';
            inp.dataset.resuelto = texto;
            // El texto que no se reconoció: al elegir su frente se vuelve a él (así se aprende).
            if (texto && !id) inp.dataset.sinReconocer = texto;
            else if (id) delete inp.dataset.sinReconocer;
            const est = td.querySelector('.fila-frente-estado');
            est.className = 'fila-frente-estado' + (!texto ? '' : (id ? ' ok' : ' mal'));
            est.textContent = !texto ? '' : (id ? '✓ ' + nombre : 'No reconocido: elige el frente');
        }
        // Reconoce en el servidor los textos de las filas que aún no lo están.
        function resolverFrentesFilas() {
            const pend = [...document.querySelectorAll('#cuerpoTabla [data-fila-frente]')]
                .filter(i => i.value.trim() && i.dataset.resuelto !== i.value.trim());
            if (!pend.length) return Promise.resolve(true);
            return window.apiFetch(URL_FRENTES_DE_TEXTOS, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ textos: [...new Set(pend.map(i => i.value.trim()))] }),
            }).then(r => r.ok ? r.json() : Promise.reject())
                .then(b => {
                    pend.forEach(i => {
                        const f = (b.frentes || {})[i.value.trim()];
                        estadoFrenteFila(i, f && f.id, f && f.nombre);
                    });
                    return true;
                })
                .catch(() => {
                    if (window.showToast) window.showToast('No se pudieron reconocer los frentes de las filas. Intenta de nuevo.', 'warning');
                    return false;
                });
        }
        function listaFrenteFila(inp) {
            const dd = document.getElementById('frenteFilaDropdown');
            const q = inp.value.trim();
            const lista = q ? window.FuzzySearch.filtrar(FRENTES, q, f => f.nombre) : FRENTES;
            dd.innerHTML = lista.length
                ? lista.map(f => `<div data-frente-id="${Number(f.id)}">${window.escapeHtml(f.nombre)}</div>`).join('')
                : '<div style="color:#94a3b8; cursor:default;">Sin resultados</div>';
            const r = inp.getBoundingClientRect();
            dd.style.left = r.left + 'px';
            dd.style.top = (r.bottom + 2) + 'px';
            dd.style.width = Math.max(r.width, 240) + 'px';
            dd.style.display = 'block';
            dd._inp = inp;
        }
        function cerrarListaFrenteFila() {
            const dd = document.getElementById('frenteFilaDropdown');
            if (dd) { dd.style.display = 'none'; dd._inp = null; }
        }

        // ── Gestión de filas ──────────────────────────────────────────────
        window.agregarFila = function (data = {}, idx = null) {
            filaCount++;
            const n = filaCount;
            const espec = necesitaEspec();
            const dlId = espec ? datalistIdActual() : '';
            const tr = document.createElement('tr');
            tr.id = `fila_${n}`;
            tr.innerHTML = `
                                                <td>${n}</td>
                                                <td><input type="date"   name="filas[${n}][fecha]"         value="${limpiar(data.fecha)}"    tabindex="${n * 10 + 1}" class="native-date" style="cursor:pointer;"></td>
                                                <td><input type="text"   name="filas[${n}][identificador]" value="${limpiar(data.id)}"       tabindex="${n * 10 + 2}" placeholder="placa / serial"></td>
                                                <td><input type="text"   name="filas[${n}][resp_nombre]"   value="${limpiar(data.nombre)}"   tabindex="${n * 10 + 3}" placeholder="Nombre responsable"></td>
                                                <td><input type="text"   name="filas[${n}][resp_ci]"       value="${limpiar(data.ci)}"       tabindex="${n * 10 + 4}" placeholder="C.I."></td>
                                                <td><input type="number" name="filas[${n}][cantidad]"      value="${limpiar(data.cantidad)}" tabindex="${n * 10 + 5}" placeholder="0" step="0.01" min="0" style="max-width:100px;"></td>
                                                <td data-espec-cell style="display:${espec ? '' : 'none'}">
                                                    <input type="text" name="filas[${n}][especificacion]" value="${limpiar(data.espec)}"
                                                           data-espec-input list="${dlId}" tabindex="${n * 10 + 6}"
                                                           placeholder="${dlId === 'listAceite' ? '15W-40, SAE 90...' : 'Ej: Goodyear G177, Bridgestone M854...'}"
                                                           style="min-width:90px;">
                                                </td>
                                                <td><input type="text"   name="filas[${n}][raw_origen]"    value="${limpiar(data.origen)}"   tabindex="${n * 10 + 7}" placeholder="Cisterna, guía... (opcional)"></td>
                                                <td style="min-width:190px;">
                                                    <input type="text" name="filas[${n}][frente]" value="${limpiar(data.frente)}" tabindex="${n * 10 + 8}"
                                                           data-fila-frente placeholder="el de arriba" autocomplete="off">
                                                    <input type="hidden" name="filas[${n}][id_frente]" value="">
                                                    <div class="fila-frente-estado"></div>
                                                </td>
                                                <td><button type="button" class="btn-del" onclick="window.eliminarFila(${n})" title="Eliminar">
                                                        <i class="material-icons" style="font-size:16px;">close</i>
                                                    </button></td>`;
            document.getElementById('cuerpoTabla').appendChild(tr);
            window.actualizarContador();
            if (filaCount === 1) { const f = tr.querySelector('input'); if (f) f.id = 'primerInput'; }
            return tr;
        };

        // Helper central (dom_helpers.js): la copia de aqui no escapaba la comilla simple.
        var limpiar = window.escapeHtml;

        window.eliminarFila = function (n) {
            const el = document.getElementById(`fila_${n}`);
            if (el) el.remove();
            window.actualizarContador();
        };


        window.actualizarContador = function () {
            const contador = document.getElementById('contadorFilas');
            if (contador) {
                const n = document.getElementById('cuerpoTabla').children.length;
                contador.textContent = n + (n === 1 ? ' fila' : ' filas');
            }
        };

        // ── Pegado: filas, encabezados, fechas y números como vienen del Excel ──
        /**
         * Filas del texto que Excel pone en el portapapeles (TSV), como lo lee fgetcsv en
         * ConsumiblesImportacionController::pegar: una celda entre comillas puede traer saltos de
         * línea (Alt+Enter) y comillas dobladas (""). Espacios de cada celda colapsados.
         */
        function filasTSV(text) {
            const filas = [];
            let fila = [], celda = '', comillas = false, inicio = true;
            const cerrarCelda = () => { fila.push(celda.replace(/\s+/g, ' ').trim()); celda = ''; inicio = true; };
            for (let i = 0; i < text.length; i++) {
                const c = text[i];
                if (comillas) {
                    if (c === '"' && text[i + 1] === '"') { celda += '"'; i++; }
                    else if (c === '"') comillas = false;
                    else celda += c;
                } else if (c === '"' && inicio) {
                    comillas = true; inicio = false;
                } else if (c === '\t') {
                    cerrarCelda();
                } else if (c === '\r' || c === '\n') {
                    if (c === '\r' && text[i + 1] === '\n') i++;
                    cerrarCelda(); filas.push(fila); fila = [];
                } else {
                    celda += c; inicio = false;
                }
            }
            if (celda !== '' || fila.length) { cerrarCelda(); filas.push(fila); }
            return filas;
        }
        function normEncabezado(c) {
            return String(c || '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
        }
        var EXACTO_LITROS = ['LITROS', 'LTS', 'LTS.', 'LT'];
        /**
         * Columna de cada campo de la tabla según la fila de encabezados pegada, con las variantes
         * de nombre de los reportes (las de LectorExcelCombustible::campoDe). null si la fila no
         * trae FECHA y la cantidad: entonces no es encabezado. La cantidad es la SALIDA/VOLUMEN si
         * la hay (no la hora ni el km de salida, ni la ENTRADA del tanque). reporte: lo que
         * LectorExcelCombustible::esEncabezadoDeDespachos toma por la tabla de un reporte de gasoil.
         */
        function mapaEncabezado(cols) {
            const m = {};
            let desc, salida, otraCantidad, fechaPura = false, equipo = false;
            const pon = (k, i) => { if (m[k] === undefined) m[k] = i; };
            cols.map(normEncabezado).forEach((h, i) => {
                if (!h || h.indexOf('ENTRADA') !== -1 || /^(HORA|KM|KILOMETRAJE|ODOMETRO)\b/.test(h)) return;
                if (h.indexOf('FECHA') === 0) {
                    pon('fecha', i);
                    if (!/SALIDA|VOLUMEN/.test(h)) fechaPura = true;
                } else if (/SALIDA|VOLUMEN/.test(h)) {
                    if (salida === undefined) salida = i;
                } else if (/CANTIDAD|LITROS (DESPACHADOS|SURTIDOS)/.test(h) || EXACTO_LITROS.indexOf(h) !== -1) {
                    if (otraCantidad === undefined) otraCantidad = i;
                } else if (h === 'CI' || h === 'C I' || /C\. ?I|CEDULA/.test(h)) pon('ci', i);
                else if (/ORIGEN|SUMINISTRO|CISTERNA|GUIA/.test(h)) pon('origen', i);
                else if (/VISCOSIDAD|MEDIDA|ESPECIFICACION|MODELO (DEL )?CAUCHO/.test(h)) pon('espec', i);
                else if (/PLACA|SERIAL|IDENTIFICADOR/.test(h)) { pon('id', i); equipo = true; }
                else if (/RESPONSABLE|CHOFER|OPERADOR|CONDUCTOR/.test(h)) pon('nombre', i);
                else if (/^(PROYECTO|FRENTE|OBRA)\b/.test(h)) pon('frente', i);
                else if (/DESCRIPCION|CARACTERISTICAS|VEHICULO ?\/ ?EQUIPO/.test(h) || h === 'EQUIPO' || h === 'VEHICULO') {
                    if (desc === undefined) desc = i;
                    equipo = true;
                } else if (h.indexOf('NOMBRE') !== -1) pon('nombre', i);
            });
            m.cantidad = salida !== undefined ? salida : otraCantidad;
            if (m.id === undefined && desc !== undefined) m.id = desc;   // sin placa/serial, la descripción
            if (m.fecha === undefined || m.cantidad === undefined) return null;
            m.reporte = fechaPura && (salida !== undefined || equipo);
            return m;
        }
        var MESES = { ENE: 1, JAN: 1, FEB: 2, MAR: 3, ABR: 4, APR: 4, MAY: 5, JUN: 6, JUL: 7, AGO: 8, AUG: 8, SEP: 9, SET: 9, OCT: 10, NOV: 11, DIC: 12, DEC: 12 };
        /**
         * Fecha del Excel → aaaa-mm-dd, como LectorExcelCombustible::fecha: día/mes/año (siempre
         * así, Venezuela) con / - . o espacio, año de 2 o 4 cifras, el mes en número o escrito
         * («01-ago-26», «1 de agosto de 2026»), año primero («2026/08/01») y con la hora detrás.
         * '' si no es una fecha que exista.
         */
        function fechaISO(s) {
            const t = normEncabezado(s).replace(/\s+\d{1,2}:\d{2}.*$/, '').replace(/\bDEL?\b/g, ' ')
                .replace(/[A-Z]+/g, w => ' ' + (MESES[w.slice(0, 3)] !== undefined ? MESES[w.slice(0, 3)] : w) + ' ');
            const p = t.split(/[\s\/.\-]+/).filter(Boolean);
            if (p.length !== 3 || p.some(x => !/^\d+$/.test(x))) return '';
            let [d, m, a] = p.map(Number);
            if (p[0].length === 4) [a, m, d] = [d, m, a];
            else if (p[2].length === 2) a = a <= 50 ? 2000 + a : 1900 + a;   // 26 → 2026, 99 → 1999
            else if (p[2].length !== 4) return '';
            const f = new Date(Date.UTC(a, m - 1, d));
            if (f.getUTCFullYear() !== a || f.getUTCMonth() !== m - 1 || f.getUTCDate() !== d) return '';
            return a + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
        }
        /**
         * Cantidad del Excel → «1234.5». Con punto y coma, el último es el decimal («1.234,5»,
         * «1,234.5»). Con uno solo de los dos, una vez es el decimal («3.785», «40,5») y repetido,
         * de miles («1.234.567»).
         */
        function numeroPegado(s) {
            const t = String(s || '').replace(/\s/g, '');
            const coma = t.lastIndexOf(','), punto = t.lastIndexOf('.');
            if (coma !== -1 && punto !== -1) {
                return coma > punto ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
            }
            const sep = coma !== -1 ? ',' : '.';
            if (t.split(sep).length > 2) return t.split(sep).join('');
            return t.replace(',', '.');
        }

        // ── PEGAR DESDE EXCEL ─────────────────────────────────────────────
        // Funciona en TODA la página (no solo dentro de la tabla)
        window.pasteLoteCargarHandler = window.pasteLoteCargarHandler || function (e) {
            // El listener queda en document al salir por la SPA: fuera de esta pantalla, no toca nada.
            if (!document.getElementById('formLote')) return;
            // Si el foco está en un input del lote de configuración → no interceptar
            const active = document.activeElement;
            if (active && (active.id === 'frenteSearch' || active.id === 'tipoSelect' || active.id === 'unidadSelect')) return;

            const text = (e.clipboardData || window.clipboardData).getData('text');
            if (!text || (!text.includes('\t') && !text.includes('\n'))) return;

            e.preventDefault();

            // Una tabla se está reconociendo con IA: otro Ctrl+V ahora la pegaría dos veces.
            if (window.__consPegandoIA) {
                if (window.showToast) window.showToast('Espera: se están reconociendo las columnas de lo que pegaste.', 'warning');
                return;
            }

            // Sin tipo no se sabe qué columnas trae (viscosidad, modelo del caucho) ni si va al
            // lector de gasoil: primero el tipo, y el resultado no depende del orden.
            const tipoPegado = tipoActual();
            if (!tipoPegado) {
                window.showModal({ type: 'warning', title: 'Primero el tipo', message: 'Elige el tipo de consumible y vuelve a pegar (Ctrl+V).', confirmText: 'Entendido', hideCancel: true });
                return;
            }

            const filas = filasTSV(text).filter(cols => cols.some(c => c));
            // El encabezado, en las primeras 15 filas (antes puede haber títulos), como el lector.
            let mapa = null, desde = 0;
            for (let i = 0; i < Math.min(15, filas.length) && !mapa; i++) {
                mapa = mapaEncabezado(filas[i]);
                if (mapa) desde = i + 1;
            }

            // La tabla completa de un reporte de gasoil (con sus encabezados) va al mismo lector que
            // un Excel subido arriba (consumibles_importar.js): columnas por nombre, repetidos fuera,
            // revisión y deshacer. Lo demás, a la tabla.
            if (tipoPegado === 'GASOIL' && mapa && mapa.reporte && window.consImpPegar && window.consImpPegar(text)) return;

            // Las reglas no reconocieron los encabezados: si lo pegado no encaja en el orden fijo
            // (más columnas de las que tiene la tabla, o una 1ª fila de títulos), se le pregunta a la
            // IA qué columna es cada dato. Sin respuesta útil, se pega por posición como siempre.
            const espec0 = necesitaEspec();
            const enOrden = espec0 ? 8 : 7;
            const raro = filas.length >= 2 && (filas.some(c => c.filter(x => x !== '').length > enOrden)
                || (filas[0][0] && !fechaISO(filas[0][0]) && isNaN(parseFloat(filas[0][0]))));
            if (!mapa && raro && window.consImpColumnas) {
                window.__consPegandoIA = true;
                window.consImpColumnas(filas, espec0).then(r => {
                    if (!document.getElementById('cuerpoTabla')) return;   // se fue a otra pantalla
                    if (r) pegarFilas(filas, r.mapa, r.fila === null ? 0 : r.fila + 1);
                    else pegarFilas(filas, null, 0);
                }).finally(() => { window.__consPegandoIA = false; });
                return;
            }
            pegarFilas(filas, mapa, desde);
        };
        function pegarFilas(filas, mapa, desde) {
            // Limpiar tabla antes de pegar (solo si todas sus filas están vacías)
            const filasSinDatos = [...document.getElementById('cuerpoTabla').querySelectorAll('tr')]
                .every(tr => [...tr.querySelectorAll('input')].every(i => !i.value.trim()));
            if (filasSinDatos) {
                document.getElementById('cuerpoTabla').innerHTML = '';
                filaCount = 0;
            }

            // Con encabezados, cada columna va a la suya por su nombre; sin ellos, por posición:
            // el orden que dice #pasteOrden (con aceite o caucho, la viscosidad o el modelo).
            const espec = necesitaEspec();
            let insertadas = 0, sinFecha = 0;
            filas.slice(desde).forEach(cols => {
                if (cols.some(c => /^(SUB ?)?TOTAL(ES)?\b/i.test(c))) return;   // fila de total
                // Sin encabezado, las filas de títulos antes de los datos (texto, no fecha ni número).
                if (!mapa && insertadas === 0 && cols[0] && !fechaISO(cols[0]) && isNaN(parseFloat(cols[0]))) return;
                const col = (campo, pos) => mapa
                    ? (mapa[campo] !== undefined ? (cols[mapa[campo]] || '') : '')
                    : (cols[pos] || '');
                if (mapa && !col('fecha') && !col('cantidad')) return;   // fila sin despacho
                const fecha = fechaISO(col('fecha', 0));
                if (!fecha && col('fecha', 0)) sinFecha++;
                agregarFila({
                    fecha: fecha,
                    id: col('id', 1),
                    nombre: col('nombre', 2),
                    ci: col('ci', 3),
                    cantidad: numeroPegado(col('cantidad', 4)),
                    espec: (mapa || espec) ? col('espec', 5) : '',
                    origen: col('origen', espec ? 6 : 5),
                    frente: col('frente', espec ? 7 : 6),
                });
                insertadas++;
            });
            resolverFrentesFilas();
            if (sinFecha && window.showToast) {
                window.showToast(sinFecha + (sinFecha === 1 ? ' fila trae una fecha que no se reconoce' : ' filas traen una fecha que no se reconoce') + ': complétala a mano.', 'warning');
            }

            if (insertadas > 0) {
                const pz = document.getElementById('pasteZone');
                if (pz) {
                    pz.style.borderColor = '#059669';
                    setTimeout(() => { pz.style.borderColor = ''; }, 1000);
                }
            }
        }
        document.removeEventListener('paste', window.pasteLoteCargarHandler);
        document.addEventListener('paste', window.pasteLoteCargarHandler);

        // ── Envío AJAX con spinner ────────────────────────────────────────
        if (document.getElementById('formLote')) {
            document.getElementById('formLote').addEventListener('submit', async function (e) {
                e.preventDefault();

                // Lo escrito en la columna FRENTE y aún sin reconocer, antes de revisar.
                if (!(await resolverFrentesFilas())) return;
                const frente = document.getElementById('idFrenteHidden').value;
                const tipo = document.getElementById('tipoSelect').value;
                const filas = document.getElementById('cuerpoTabla').children.length;
                // Una fila con algo escrito pero sin fecha o sin cantidad el servidor la descarta
                // sin avisar: se dice antes cuál es, para no perder el consumo.
                const aMedias = [], sinReconocer = [];
                let sinProyecto = false;
                [...document.getElementById('cuerpoTabla').children].forEach(tr => {
                    const v = n => (tr.querySelector(`[name$="[${n}]"]`) || {}).value || '';
                    // Solo lo que se ve: la viscosidad o el modelo de otro tipo quedan ocultos y el
                    // servidor los ignora.
                    const algo = [...tr.querySelectorAll('input')].some(inp => inp.value.trim() !== '' && !inp.closest('[data-espec-cell][style*="none"]'));
                    // El número es el de la columna «#» (tras borrar filas no coincide con la posición).
                    if (algo && (!v('fecha') || !(parseFloat(v('cantidad')) > 0))) aMedias.push(tr.firstElementChild.textContent.trim());
                    // Frente: el reconocido o elegido en la fila; sin texto, el de arriba.
                    if (algo && v('frente').trim() && !v('id_frente')) sinReconocer.push(tr.firstElementChild.textContent.trim());
                    if (algo && !v('frente').trim()) sinProyecto = true;
                });

                if (sinReconocer.length) {
                    window.showModal({ type: 'warning', title: 'Frente sin reconocer', message: 'No se reconoce el proyecto de ' + (sinReconocer.length === 1 ? 'la fila ' : 'las filas ') + sinReconocer.join(', ') + ': elige su frente en la columna FRENTE.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                if (sinProyecto && !frente) {
                    window.showModal({ type: 'warning', title: 'Campo requerido', message: 'Selecciona el frente de trabajo de arriba para las filas sin proyecto, o escríbelo en su columna FRENTE.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                if (!tipo) {
                    if (window.showModal) {
                        window.showModal({ type: 'warning', title: 'Campo requerido', message: 'Selecciona el tipo de consumible.', confirmText: 'Entendido', hideCancel: true });
                    } else { alert('Selecciona el tipo de consumible.'); }
                    return;
                }
                if (aMedias.length) {
                    window.showModal({ type: 'warning', title: 'Filas incompletas', message: 'Falta la fecha o la cantidad en ' + (aMedias.length === 1 ? 'la fila ' : 'las filas ') + aMedias.join(', ') + '. Complétalas o bórralas.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                if (!filas) {
                    if (window.showModal) {
                        window.showModal({ type: 'warning', title: 'Tabla vacía', message: 'Agrega al menos una fila con datos.', confirmText: 'Entendido', hideCancel: true });
                    } else { alert('Agrega al menos una fila.'); }
                    return;
                }

                if (window.showPreloader) window.showPreloader();

                const formData = new FormData(this);

                window.apiFetch(this.action, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    method: 'POST',
                    body: formData
                })
                    .then(async response => {
                        // Los 401/419 los ataja el interceptor global de fetch
                        // (estructura_base); esta rama no podía ejecutarse. Y la
                        // comprobación de la URL final que la acompañaba tampoco: /login
                        // redirige a /, así que esa URL nunca lo contiene.
                        const data = await response.json().catch(() => ({}));
                        return { status: response.status, body: data };
                    })
                    .then(({ status, body }) => {
                        if (window.hidePreloader) window.hidePreloader();

                        if (status === 200 || status === 201) {
                            // ── Éxito: limpiar formulario y mostrar toast ────────
                            // 1. Vaciar tabla y poner 5 filas limpias
                            document.getElementById('cuerpoTabla').innerHTML = '';
                            filaCount = 0;
                            for (var i = 0; i < 5; i++) agregarFila();

                            // 2. Resetear frente
                            document.getElementById('idFrenteHidden').value = '';
                            document.getElementById('frenteSearch').value = '';
                            const badge = document.getElementById('frenteSeleccionado');
                            if (badge) { badge.textContent = ''; badge.style.display = 'none'; }

                            // 3. Toast de éxito (pequeño, no intrusivo)
                            const n = body.insertados || '?';
                            if (window.showToast) {
                                window.showToast(`✓ ${n} registros guardados correctamente`, 'success');
                            } else if (window.showModal) {
                                window.showModal({
                                    type: 'success',
                                    title: '¡Guardado!',
                                    message: body.message || `${n} registros guardados.`,
                                    confirmText: 'Listo',
                                    hideCancel: true,
                                });
                            }

                        } else if (status === 422 && body.errors) {
                            const msgs = Object.values(body.errors).flat().join('\n');
                            if (window.showModal) {
                                window.showModal({ type: 'warning', title: 'Errores de validación', message: msgs, confirmText: 'Entendido', hideCancel: true });
                            } else { alert(msgs); }
                        } else {
                            throw new Error(body.message || 'Error desconocido del servidor.');
                        }
                    })
                    .catch(err => {
                        if (window.hidePreloader) window.hidePreloader();
                        if (err.message === 'Sesión expirada.') return;
                        if (window.showModal) {
                            window.showModal({ type: 'error', title: 'Error', message: err.message || 'Ocurrió un error al guardar.', confirmText: 'Cerrar', hideCancel: true });
                        } else { alert(err.message); }
                    });
            });
        }


        // ── Inicio: 5 filas vacías + auto-agregado de fila ────────────────
        function initCargarConsumibles() {
            var tabla = document.getElementById('cuerpoTabla');
            if (!tabla) return;
            if (tabla.children.length === 0) {
                for (var i = 0; i < 5; i++) agregarFila();
            }
            // Auto-agregar fila: al escribir en la ÚLTIMA fila aparece otra vacía debajo
            // (estilo hoja de cálculo) — por eso ya no hace falta el botón "Agregar Fila".
            // Delegado en el tbody (sobrevive al alta/baja de filas); guard para no duplicar
            // el listener si init corre de nuevo (navegación SPA).
            if (!tabla._autoRowBound) {
                tabla._autoRowBound = true;
                tabla.addEventListener('input', function (e) {
                    var fila = e.target.closest('tr');
                    if (!fila || fila !== tabla.lastElementChild) return;
                    var algunDato = Array.prototype.some.call(
                        fila.querySelectorAll('input'),
                        function (inp) { return inp.value.trim() !== ''; }
                    );
                    if (algunDato) window.agregarFila();
                });
                // Columna FRENTE: al escribir, la lista de frentes; al salir, se reconoce lo escrito.
                tabla.addEventListener('focusin', function (e) {
                    if (e.target.matches('[data-fila-frente]')) listaFrenteFila(e.target);
                });
                tabla.addEventListener('input', function (e) {
                    if (!e.target.matches('[data-fila-frente]')) return;
                    // Cambió el texto: lo reconocido antes ya no vale (se vuelve a reconocer al salir).
                    var td = e.target.closest('td');
                    td.querySelector('[name$="[id_frente]"]').value = '';
                    td.querySelector('.fila-frente-estado').textContent = '';
                    e.target.dataset.resuelto = '';
                    listaFrenteFila(e.target);
                });
                tabla.addEventListener('focusout', function (e) {
                    if (!e.target.matches('[data-fila-frente]')) return;
                    cerrarListaFrenteFila();
                    resolverFrentesFilas();
                });
            }
            // Elegir en la lista: mousedown para que gane al focusout de la celda.
            var dd = document.getElementById('frenteFilaDropdown');
            if (dd && !dd._bound) {
                dd._bound = true;
                dd.addEventListener('mousedown', function (e) {
                    var op = e.target.closest('[data-frente-id]');
                    e.preventDefault();
                    if (!op || !dd._inp) return;
                    var inp = dd._inp, id = Number(op.dataset.frenteId), nombre = op.textContent.trim();
                    // Para un texto no reconocido: la fila lo conserva (se aprende al guardar) y el
                    // frente vale para todas las que traen ese mismo texto. Si no, el nombre elegido.
                    var original = inp.dataset.sinReconocer || '';
                    var filas = original
                        ? [].filter.call(document.querySelectorAll('#cuerpoTabla [data-fila-frente]'), function (i) {
                            return i === inp || (i.value.trim() === original && !i.closest('td').querySelector('[name$="[id_frente]"]').value);
                        })
                        : [inp];
                    filas.forEach(function (i) {
                        i.value = original || nombre;
                        estadoFrenteFila(i, id, nombre);
                    });
                    cerrarListaFrenteFila();
                });
                // La lista es fija en la pantalla: al desplazar la tabla o la página se cierra.
                document.getElementById('tablaFilas').closest('.tbl-wrap').addEventListener('scroll', cerrarListaFrenteFila, { passive: true });
            }
            if (!window.__cargarFrenteScroll) {
                window.__cargarFrenteScroll = true;
                window.addEventListener('scroll', function () {
                    var l = document.getElementById('frenteFilaDropdown');
                    if (l) l.style.display = 'none';
                }, { passive: true });
            }
        }

        if (document.readyState === 'loading') {
            window.addEventListener('DOMContentLoaded', initCargarConsumibles);
        } else {
            // SPA Nav case
            setTimeout(initCargarConsumibles, 50);
        }
    </script>
@endsection