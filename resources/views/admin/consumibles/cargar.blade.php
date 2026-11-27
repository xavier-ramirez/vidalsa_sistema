@extends('layouts.estructura_base')
@section('title', 'Cargar Consumibles')

@section('content')
    <style>
        /* ── RESUMEN de lo que hay en la tabla (total y por frente) ── */
        .resumen-carga {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 6px 8px;
            margin-bottom: 12px;
            padding: 8px 12px;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            background: #f8fbff;
            font-size: 12.5px;
            color: #1e293b;
        }

        /* Vacía no se ve (display:flex le ganaba al atributo hidden). */
        .resumen-carga[hidden] { display: none; }

        .resumen-carga .rc-total { font-weight: 800; color: #0f3d7a; margin-right: 4px; }
        .resumen-carga .rc-frente { background: #fff; border: 1px solid #cbd5e1; border-radius: 20px; padding: 2px 10px; white-space: nowrap; }
        .resumen-carga .rc-frente b { color: #0067b1; }
        .resumen-carga .rc-sin { border-color: #fca5a5; background: #fef2f2; color: #991b1b; }
        .resumen-carga .rc-sin b { color: #991b1b; }
        .resumen-carga .rc-saltar { border-style: dashed; color: #64748b; }
        .resumen-carga .rc-saltar b { color: #64748b; }
        /* Cada frente es un botón: muestra en la tabla solo sus filas (otro clic, todas). */
        .resumen-carga button.rc-frente { font: inherit; color: inherit; cursor: pointer; }
        .resumen-carga button.rc-frente:hover { border-color: #0067b1; }
        .resumen-carga button.rc-frente.activo { background: #0067b1; border-color: #0067b1; color: #fff; }
        .resumen-carga button.rc-frente.activo b { color: #fff; }
        .resumen-carga .rc-ayuda { flex-basis: 100%; font-size: 11.5px; color: #64748b; }
        .resumen-carga .rc-todas { font: inherit; font-weight: 700; color: #0067b1; background: none; border: 0; cursor: pointer; text-decoration: underline; }
        #cuerpoTabla tr.fila-filtrada { display: none; }

        /* Sugerencia para una fila de proyecto sin reconocer: el frente donde está su equipo. */
        .fila-sugerido { display: block; margin-top: 3px; padding: 2px 8px; border: 1px solid #93c5fd; border-radius: 6px;
            background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 600; cursor: pointer; text-align: left; font-family: inherit; }
        .fila-sugerido:hover { background: #dbeafe; }

        /* ── TABLA TIPO EXCEL ── */
        .tbl-wrap {
            overflow-x: auto;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
        }

        /* La tabla baja dentro de su caja (con el encabezado fijo): miles de filas del Excel no
           alejan el botón Guardar. */
        .tabla-scroll { max-height: 70vh; overflow: auto; border-radius: 8px; }
        /* Cada fila es su propia rejilla (mismas columnas), no una tabla: al sumar filas el
           navegador no vuelve a acomodar las miles de antes, y las que no se ven no se dibujan
           (content-visibility). Medido con 2.600 filas: de ~16 s a ~3,6 s, y sin congelar la
           página. El encabezado queda fijo arriba de la caja. */
        #tablaFilas, #tablaFilas thead, #tablaFilas tbody { display: block; }
        #tablaFilas thead { position: sticky; top: 0; z-index: 2; }
        #tablaFilas tr {
            display: grid;
            grid-template-columns: 36px 150px minmax(140px, 1.3fr) minmax(140px, 1.3fr) 120px 110px minmax(200px, 1.4fr) 40px;
        }
        /* Aceite y caucho: con la columna de viscosidad / modelo. */
        /* La tabla mide al menos lo que suman las columnas: content-visibility recorta lo que se sale
           de la fila, y en un teléfono la columna FRENTE y la X quedaban sin dibujar. */
        #tablaFilas { min-width: 936px; }
        #tablaFilas:has(#thEspec:not([style*="none"])) { min-width: 1016px; }
        #tablaFilas:has(#thEspec:not([style*="none"])) tr {
            grid-template-columns: 36px 150px minmax(130px, 1.2fr) minmax(130px, 1.2fr) 110px 100px minmax(130px, 1fr) minmax(190px, 1.3fr) 40px;
        }
        #cuerpoTabla tr { content-visibility: auto; contain-intrinsic-size: auto 40px; }
        #tablaFilas td { display: flex; flex-direction: column; justify-content: center; min-width: 0; }
        /* El ancho lo da la rejilla (el width de la tabla vieja, más el relleno, la corría). */
        #tablaFilas thead th { width: auto !important; min-width: 0; box-sizing: border-box; overflow: hidden; text-overflow: ellipsis; }
        #tablaFilas td:first-child, #tablaFilas td:last-child { align-items: center; }

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
            box-sizing: border-box;   /* con el relleno no se sale de su columna (las filas son rejillas) */
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

        /* Cancelar y Guardar, en la fila de los filtros y del mismo alto. */
        .con-acciones {
            display: flex;
            gap: 8px;
            align-items: center;
            flex: 0 0 auto;
        }

        .con-acciones .btn-primary-maquinaria {
            margin-left: 0;
            height: 42px;
            padding: 0 15px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .con-solo-admin { font-size: 12px; color: #94a3b8; padding: 8px 12px; border: 1px dashed #cbd5e1; border-radius: 8px; }

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

        {{-- Sugerencias de la columna VISCOSIDAD / MODELO CAUCHO (aceite y caucho). --}}
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

            <div class="tbl-wrap" style="padding: 15px; background: #fff;">

                {{-- Selectores, Cancelar/Guardar y Acciones, en una fila sobre la tabla. --}}
                <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:end; margin-bottom: 20px;">
                    {{-- FRENTE: el de las filas sin proyecto. --}}
                    <div style="flex: 1; min-width: 200px;">
                        <div style="position:relative;">
                            <div id="frenteCaja"
                                style="box-sizing:border-box; padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; position:relative;">
                                <input type="text" id="frenteSearch"
                                    placeholder="Frente de Trabajo (filas sin proyecto)" autocomplete="off"
                                    style="width:100%; border:none; background:transparent; padding:0 14px; font-size:13px; outline:none; height:100%; font-family:inherit;"
                                    oninput="escribirFrente(this.value)" onfocus="mostrarTodosLosFrente()">
                                <i class="material-icons"
                                    style="padding:0 10px; color:#94a3b8; font-size:18px; cursor:default;"
                                    onclick="document.getElementById('frenteSearch').focus()">arrow_drop_down</i>
                            </div>
                            <input type="hidden" name="id_frente" id="idFrenteHidden" value="">
                            <div id="frenteDropdown"
                                style="display:none; position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #cbd5e0; border-radius:10px; box-shadow:0 8px 20px rgba(0,0,0,.12); z-index:200; max-height:240px; overflow-y:auto; margin-top:4px;">
                            </div>
                        </div>
                    </div>

                    {{-- TIPO CONSUMIBLE --}}
                    <div style="flex: 1; min-width: 160px; position:relative;">
                        <div class="custom-dropdown" id="tipoDropdownCargar" data-default-label="— Seleccionar —">
                            <input type="hidden" name="tipo_consumible" id="tipoSelect" value="">
                            <div class="dropdown-trigger"
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; cursor:default; position:relative;">
                                <input type="text" data-filter-search id="search_tipo_cargar"
                                    readonly value=""
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
                                        <div class="dropdown-item"
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
                            <input type="hidden" name="unidad" id="unidadSelect" value="LITROS">
                            <div class="dropdown-trigger"
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; cursor:default; position:relative;">
                                <input type="text" data-filter-search id="search_unidad_cargar"
                                    readonly value="Litros"
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
                                        <div class="dropdown-item {{ $val === 'LITROS' ? 'selected' : '' }}"
                                            data-value="{{ $val }}"
                                            onclick="window.selectOption('unidadDropdownCargar', '{{ $val }}', '{{ addslashes($label) }}');">
                                            {{ $label }}
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Cancelar y Guardar al lado de los filtros, a la vista sin bajar hasta el final.
                         "Guardar" solo con permiso super.admin (el middleware del POST /consumibles/guardar-lote). --}}
                    <div class="con-acciones">
                        <a href="{{ route('consumibles.index') }}" class="btn-primary-maquinaria btn-secondary">Cancelar</a>
                        @can('super.admin')
                            <button type="submit" class="btn-primary-maquinaria">
                                <i class="material-icons">save</i>
                                Guardar
                            </button>
                        @else
                            <span class="con-solo-admin">Solo un Super Admin puede guardar.</span>
                        @endcan
                    </div>

                    {{-- ACCIONES --}}
                    <div style="position:relative; flex: 0 0 auto; margin-top: auto;">
                        <button type="button" id="btnAcciones" class="btn-primary-maquinaria"
                            style="padding: 0 15px; height: 42px; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);"
                            onclick="document.getElementById('splitDropdownMenu').style.display = document.getElementById('splitDropdownMenu').style.display === 'none' ? 'block' : 'none';">
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

                {{-- Total de lo que hay en la tabla y por frente (resumenCarga), antes de guardar. --}}
                <div id="resumenCarga" class="resumen-carga" hidden></div>

                <div id="tablaScroll" class="tabla-scroll">
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
                            <th>FRENTE <span style="font-weight:400;opacity:.7;">(proyecto)</span></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoTabla"></tbody>
                </table>
                </div>
                {{-- Lista para elegir el frente de una fila: una sola, se coloca bajo la celda. --}}
                <div id="frenteFilaDropdown"></div>
            </div>

        </div>

    </form>

    @include('admin.consumibles.partials.cupos_seccion')
    @can('super.admin')
        @include('admin.consumibles.partials.excel_cargados')
    @endcan

    {{-- ═══ JAVASCRIPT ═══ --}}
    <script>
        var FRENTES = @json($frentes->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]));
        var filaCount = 0;
        var URL_FRENTES_DE_TEXTOS = @json(route('consumibles.frentesDeTextos'));
        // Reconocer frentes y guardar piden super.admin (sus rutas): sin él no se pregunta al servidor.
        var PUEDE_GUARDAR = @json((bool) auth()->user()?->can('super.admin'));
        // Nombre de cada frente para el resumen: los activos y los que llegan reconocidos o
        // sugeridos aunque no estén activos (el del equipo, el del Excel).
        var NOMBRE_FRENTE = {};
        FRENTES.forEach(f => { NOMBRE_FRENTE[f.id] = f.nombre; });

        // ── Tipo ──────────────────────────────────────────────────────────
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
                'CAUCHO': 'UNIDADES'
            };
            const labelMapa = {
                'GASOIL': 'Litros', 'GASOLINA': 'Litros', 'ACEITE': 'Litros',
                'CAUCHO': 'Unidades'
            };
            const nuevaUnidad = mapa[tipo] || 'LITROS';
            const nuevaLabel = labelMapa[tipo] || 'Litros';

            window.selectOption('unidadDropdownCargar', nuevaUnidad, nuevaLabel);

            const espec = necesitaEspec();
            const th = document.getElementById('thEspec');

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
            programarResumen();   // la unidad del resumen
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

        // Escribir en el cuadro suelta el frente elegido: sin esto, borrar el texto (o escribir otro
        // sin elegirlo) dejaba guardar las filas sin proyecto en el frente de antes.
        function escribirFrente(q) {
            document.getElementById('idFrenteHidden').value = '';
            filtrarFrentes(q);
            programarResumen();
        }

        window.seleccionarFrente = function (id, nombre) {
            document.getElementById('idFrenteHidden').value = id;
            document.getElementById('frenteSearch').value = nombre;
            document.getElementById('frenteDropdown').style.display = 'none';
            programarResumen();
        };

        // ── Desplegables de la pantalla: solo uno abierto a la vez ──────────
        // Frente, Acciones, Tipo/Unidad (.custom-dropdown) y Frente de cada fila. Con CLIC y con FOCO sin clic (Tab, «siguiente» del
        // teclado del teléfono): el que se toca se queda, los demás se cierran. Por eso el botón
        // Acciones no corta el clic (stopPropagation). Una sola vez por pestaña (SPA).
        function desplegableDe(el) {
            if (!el || !el.closest) return null;
            if (el.closest('#frenteCaja, #frenteDropdown')) return 'frente';
            if (el.closest('#btnAcciones, #splitDropdownMenu')) return 'acciones';
            if (el.closest('[data-fila-frente], #frenteFilaDropdown')) return 'fila';
            return el.closest('.custom-dropdown');
        }
        function cerrarDesplegablesCargar(dueno) {
            const dd = document.getElementById('frenteDropdown');
            if (dd && dueno !== 'frente') dd.style.display = 'none';
            const acc = document.getElementById('splitDropdownMenu');
            if (acc && dueno !== 'acciones') acc.style.display = 'none';
            if (dueno !== 'fila') cerrarListaFrenteFila();
            // Entre .custom-dropdown ya se cierran solos (uicomponents.js).
            if (!(dueno instanceof Element && dueno.classList.contains('custom-dropdown')) && window.closeAllDropdowns) {
                window.closeAllDropdowns(null);
            }
        }
        if (!window.__cargarDesplegablesBound) {
            window.__cargarDesplegablesBound = true;
            ['click', 'focusin'].forEach(ev => document.addEventListener(ev, e => {
                if (!document.getElementById('cuerpoTabla')) return;   // solo en esta pantalla
                cerrarDesplegablesCargar(desplegableDe(e.target));
            }));
        }

        // ── Frente de cada fila ───────────────────────────────────────────
        // Lo pegado puede traer varios proyectos: cada fila lleva su texto (columna FRENTE) y el
        // frente que se le reconoce con las reglas del Excel de gasoil (frentesDeTextos →
        // IdentificadorConsumo). Lo que no se reconoce se elige en la fila y, al guardar, queda
        // aprendido. Una fila sin texto usa el frente de arriba.
        // sug: el frente donde está registrado el equipo de la fila ({id, nombre, equipo}), para el
        // proyecto que no se reconoce: se ofrece con un botón; no se aplica solo.
        function estadoFrenteFila(inp, id, nombre, sug) {
            const td = inp.closest('td');
            const texto = inp.value.trim();
            if (id && nombre) NOMBRE_FRENTE[id] = nombre;
            td.querySelector('[name$="[id_frente]"]').value = texto && id ? id : '';
            inp.dataset.resuelto = texto;
            // El texto que no se reconoció: al elegir su frente se vuelve a él (así se aprende).
            // Con la sugerencia tomada sigue siendo el no reconocido: elegir luego en la lista lo aprende.
            if (texto && !id) inp.dataset.sinReconocer = inp.dataset.textoNoReconocido = texto;
            else if (id && !inp.dataset.porSugerencia) delete inp.dataset.sinReconocer;
            // Elegido con la sugerencia (frente del equipo): vale solo para esta fila y no se aprende.
            if (!id || !inp.dataset.porSugerencia) delete inp.dataset.porSugerencia;
            const est = td.querySelector('.fila-frente-estado');
            est.className = 'fila-frente-estado' + (!texto ? '' : (id ? ' ok' : ' mal'));
            est.textContent = !texto ? '' : (id ? '✓ ' + nombre : 'No reconocido: elige el frente');
            if (texto && !id && sug && sug.id) {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'fila-sugerido';
                b.dataset.sugId = sug.id;
                b.dataset.sugNombre = sug.nombre;
                b.title = sug.equipo ? sug.equipo + ' está registrado en ' + sug.nombre : '';
                b.textContent = 'Su equipo está en ' + sug.nombre + ' · usar';
                est.appendChild(b);
            }
            programarResumen();
        }
        // Reconoce en el servidor los textos de las filas que aún no lo están. Una petición a la
        // vez: la siguiente espera a la que va en curso (y ya no repite lo que ella resolvió).
        // Por tandas de 500 filas: frentesDeTextos acepta hasta 500 textos y 500 placas.
        var reconociendo = null;
        var TANDA_FRENTES = 500;
        function resolverFrentesFilas() {
            if (!PUEDE_GUARDAR) return Promise.resolve(true);
            if (reconociendo) return reconociendo.then(() => resolverFrentesFilas());
            const placaDe = i => (i.closest('tr').querySelector('[name$="[identificador]"]').value || '').trim();
            const pend = [...document.querySelectorAll('#cuerpoTabla [data-fila-frente]')]
                .filter(i => i.value.trim() && i.dataset.resuelto !== i.value.trim())
                .map(i => ({ i, texto: i.value.trim(), placa: placaDe(i) }));
            if (!pend.length) return Promise.resolve(true);
            const pedir = trozo => window.apiFetch(URL_FRENTES_DE_TEXTOS, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ textos: [...new Set(trozo.map(p => p.texto))], equipos: [...new Set(trozo.map(p => p.placa).filter(Boolean))] }),
            }).then(r => r.ok ? r.json() : Promise.reject());
            let cadena = Promise.resolve({ frentes: {}, sugeridos: {} });
            for (let k = 0; k < pend.length; k += TANDA_FRENTES) {
                const trozo = pend.slice(k, k + TANDA_FRENTES);
                cadena = cadena.then(acc => pedir(trozo).then(b => ({
                    frentes: Object.assign(acc.frentes, b.frentes || {}),
                    sugeridos: Object.assign(acc.sugeridos, b.sugeridos || {}),
                })));
            }
            reconociendo = cadena
                .then(b => {
                    pend.forEach(({ i, texto, placa }) => {
                        // Cambió el texto o la placa mientras tanto: la respuesta es vieja; queda pendiente.
                        if (!i.isConnected || i.value.trim() !== texto || placaDe(i) !== placa) return;
                        const f = (b.frentes || {})[texto];
                        const sug = (b.sugeridos || {})[placa];
                        const td = i.closest('td');
                        // La sugerencia ya tomada sigue valiendo si el equipo está en el mismo frente.
                        if (!f && i.dataset.porSugerencia && sug && String(sug.id) === td.querySelector('[name$="[id_frente]"]').value) {
                            estadoFrenteFila(i, sug.id, sug.nombre);
                        } else {
                            estadoFrenteFila(i, f && f.id, f && f.nombre, sug);
                        }
                    });
                    return true;
                })
                .catch(() => {
                    if (window.showToast) window.showToast('No se pudieron reconocer los frentes de las filas. Intenta de nuevo.', 'warning');
                    return false;
                })
                .finally(() => { reconociendo = null; });
            return reconociendo;
        }
        function listaFrenteFila(inp) {
            const dd = document.getElementById('frenteFilaDropdown');
            // El texto del Excel que no se reconoció no se parece a ningún frente («Sin resultados»):
            // con él en la celda se ofrecen todos; al escribir otra cosa, se busca lo escrito.
            const q = inp.value.trim();
            const lista = q && q !== inp.dataset.textoNoReconocido ? window.FuzzySearch.filtrar(FRENTES, q, f => f.nombre) : FRENTES;
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

        // ── Resumen de la tabla ───────────────────────────────────────────
        // Filas, litros y litros por frente de todo lo que se va a guardar: lo que se ve y lo del
        // Excel que aún no se pintó. Frente de una fila: el reconocido o elegido; sin texto, el de
        // arriba. Aparte: «Frente no reconocido» (hay que elegirlo), «Sin proyecto» (sin texto ni
        // frente arriba) y los ya cargados o repetidos (se saltan al guardar, no suman).
        // Cada grupo es un botón: la tabla muestra solo sus filas (filtroFrente); otro clic, todas.
        var temporizadorResumen = null;
        var filtroFrente = null;
        function programarResumen() {
            // Mientras se pintan las filas del Excel no se recuenta en cada cuadro (recorrer miles de
            // filas cada vez lo volvía lento): ya se contó al empezar y se cuenta al terminar.
            if (pintandoExcel) return;
            clearTimeout(temporizadorResumen);
            temporizadorResumen = setTimeout(resumenCarga, 150);
        }
        // Grupo de una fila: 'saltar', 'mal:TEXTO' (proyecto sin reconocer, uno por cada texto del
        // Excel: así se ve qué escribieron distinto), 'sin' (sin proyecto) o el id del frente.
        // El filtro 'mal' abarca todos los no reconocidos.
        function grupoFila(texto, id, saltar) {
            if (saltar) return 'saltar';
            if (id) return String(id);
            if (texto) return 'mal:' + texto.toUpperCase().replace(/\s+/g, ' ');
            const arriba = document.getElementById('idFrenteHidden').value;
            return arriba ? String(arriba) : 'sin';
        }
        function grupoDeTr(tr) {
            const v = n => (tr.querySelector(`[name$="[${n}]"]`) || {}).value || '';
            // Sin nada escrito no es de ningún grupo; con algo escrito sí, aunque le falte la cantidad
            // (si no, el aviso de Guardar la nombraba y el filtro la escondía).
            const algo = [...tr.querySelectorAll('input:not([type=hidden])')].some(i => i.value.trim() !== '' && !i.closest('[data-espec-cell][style*="none"]'));
            if (!algo) return '';
            return grupoFila(v('frente').trim(), v('id_frente'), !!tr.dataset.saltar);
        }
        function entraEnFiltro(grupo, filtro) {
            return grupo === filtro || (filtro === 'mal' && grupo.startsWith('mal:'));
        }
        function nombreGrupo(k) {
            if (k === 'mal') return 'Todos los no reconocidos';
            if (k.startsWith('mal:')) return 'No reconocido «' + k.slice(4) + '»';
            if (k === 'sin') return 'Sin proyecto';
            if (k === 'saltar') return 'Ya cargadas (se saltan)';
            return NOMBRE_FRENTE[k] || 'Frente ' + k;
        }
        // Oculta las filas de otro grupo. Corre al elegir el grupo y al pintar filas nuevas, no al
        // escribir: la fila que se está corrigiendo no desaparece de golpe.
        function aplicarFiltroFrente(trs) {
            (trs || [...document.getElementById('cuerpoTabla').children]).forEach(tr => {
                tr.classList.toggle('fila-filtrada', filtroFrente !== null && !entraEnFiltro(grupoDeTr(tr), filtroFrente));
            });
        }
        function filtrarPorFrente(k) {
            filtroFrente = k === null || k === filtroFrente ? null : k;
            aplicarFiltroFrente();
            resumenCarga();
            const caja = document.getElementById('tablaScroll');
            if (caja) caja.scrollTop = 0;
        }
        function resumenCarga() {
            const caja = document.getElementById('resumenCarga');
            if (!caja) return;
            const porFrente = {};
            let filas = 0, total = 0;
            const sumar = (cant, texto, id, saltar) => {
                const v = parseFloat(cant);
                if (!(v > 0)) return;
                const k = grupoFila(texto, id, saltar);
                if (k !== 'saltar') { filas++; total += v; }
                porFrente[k] = porFrente[k] || { l: 0, n: 0 };
                porFrente[k].l += v; porFrente[k].n++;
            };
            [...document.getElementById('cuerpoTabla').children].forEach(tr => {
                const v = n => (tr.querySelector(`[name$="[${n}]"]`) || {}).value || '';
                sumar(v('cantidad'), v('frente').trim(), v('id_frente'), !!tr.dataset.saltar);
            });
            excelPorPintar.forEach(f => {
                if (f.id_frente && f.nombre_frente) NOMBRE_FRENTE[f.id_frente] = f.nombre_frente;
                sumar(f.cantidad, String(f.frente || '').trim(), f.frente && f.id_frente ? f.id_frente : '', !!f.saltar);
            });
            caja.hidden = !filas && !porFrente.saltar && filtroFrente === null;
            if (caja.hidden) { caja.innerHTML = ''; return; }
            const fmt = x => Number(x).toLocaleString('es-VE', { maximumFractionDigits: 2 });
            const unidad = (document.getElementById('unidadSelect').value || 'LITROS') === 'LITROS' ? 'L' : document.getElementById('unidadSelect').value.toLowerCase();
            // Primero lo que pide atención; luego los frentes de mayor a menor; al final lo que se salta.
            // Con más de un texto sin reconocer, además un botón para verlos todos juntos.
            const males = Object.keys(porFrente).filter(k => k.startsWith('mal:'));
            if (males.length > 1) porFrente.mal = males.reduce((a, k) => ({ l: a.l + porFrente[k].l, n: a.n + porFrente[k].n }), { l: 0, n: 0 });
            const peso = k => k === 'mal' ? 0 : k.startsWith('mal:') ? 1 : k === 'sin' ? 2 : k === 'saltar' ? 4 : 3;
            const claves = Object.keys(porFrente).sort((a, b) => peso(a) - peso(b) || porFrente[b].l - porFrente[a].l);
            const chips = claves.map(k => {
                const g = porFrente[k];
                const nombre = nombreGrupo(k);
                const clase = k === 'mal' || k.startsWith('mal:') || k === 'sin' ? ' rc-sin' : k === 'saltar' ? ' rc-saltar' : '';
                return `<button type="button" class="rc-frente${clase}${filtroFrente === k ? ' activo' : ''}" data-grupo="${window.escapeHtml(k)}" title="Ver solo estas filas">`
                    + `${window.escapeHtml(nombre)}: <b>${fmt(g.l)} ${unidad}</b> (${fmt(g.n)})</button>`;
            }).join('');
            const ayuda = filtroFrente !== null
                ? `<span class="rc-ayuda">Viendo solo: ${window.escapeHtml(nombreGrupo(filtroFrente))}. <button type="button" class="rc-todas" data-grupo-todas>Ver todas las filas</button></span>`
                : (claves.length > 1 ? '<span class="rc-ayuda">Toca un frente para ver solo sus filas en la tabla.</span>' : '');
            caja.innerHTML = `<span class="rc-total">${fmt(filas)} ${filas === 1 ? 'fila' : 'filas'} · ${fmt(total)} ${unidad}</span>` + chips + ayuda;
        }

        // ── Gestión de filas ──────────────────────────────────────────────
        // destino: dónde se pone la fila (por omisión, la tabla). El Excel subido arma sus filas en
        // un fragmento aparte y las mete de una vez: fila por fila, la página se congelaba.
        window.agregarFila = function (data = {}, idx = null, destino = null) {
            filaCount++;
            const n = filaCount;
            const espec = necesitaEspec();
            const dlId = espec ? datalistIdActual() : '';
            const tr = document.createElement('tr');
            tr.id = `fila_${n}`;
            // Del Excel, ya cargado o repetido: se ve, pero al guardar se salta (no suma en el resumen).
            if (data.saltar) { tr.dataset.saltar = '1'; tr.title = 'Ya cargado o repetido: se salta al guardar'; }
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
                                                <td style="min-width:190px;">
                                                    <input type="text" name="filas[${n}][frente]" value="${limpiar(data.frente)}" tabindex="${n * 10 + 7}"
                                                           data-fila-frente placeholder="el de arriba" autocomplete="off">
                                                    <input type="hidden" name="filas[${n}][id_frente]" value="">
                                                    <div class="fila-frente-estado"></div>
                                                    ${data.imp ? `<input type="hidden" name="filas[${n}][imp]" value="${Number(data.imp)}"><input type="hidden" name="filas[${n}][ref]" value="${Number(data.ref)}">` : ''}
                                                </td>
                                                <td><button type="button" class="btn-del" onclick="window.eliminarFila(${n})" title="Eliminar">
                                                        <i class="material-icons" style="font-size:16px;">close</i>
                                                    </button></td>`;
            (destino || document.getElementById('cuerpoTabla')).appendChild(tr);
            // Del Excel subido el frente ya viene reconocido por el servidor: no se vuelve a pedir.
            if (data.imp) estadoFrenteFila(tr.querySelector('[data-fila-frente]'), data.id_frente, data.nombre_frente, data.sugerido);
            return tr;
        };

        // Helper central (dom_helpers.js).
        var limpiar = window.escapeHtml;

        window.eliminarFila = function (n) {
            const el = document.getElementById(`fila_${n}`);
            if (el) el.remove();
            // Las filas nuevas salen al escribir en la última: sin ninguna, ya no se podría escribir.
            const cuerpo = document.getElementById('cuerpoTabla');
            if (cuerpo && !cuerpo.children.length) agregarFila();
            programarResumen();
        };

        // ── Pegado: filas, encabezados, fechas y números como vienen del Excel ──
        /**
         * Filas del texto que Excel pone en el portapapeles (TSV): una celda entre comillas puede
         * traer saltos de línea (Alt+Enter) y comillas dobladas (""). Espacios de cada celda colapsados.
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
         * la hay (no la hora ni el km de salida, ni la ENTRADA del tanque).
         */
        function mapaEncabezado(cols) {
            const m = {};
            let desc, salida, otraCantidad;
            const pon = (k, i) => { if (m[k] === undefined) m[k] = i; };
            cols.map(normEncabezado).forEach((h, i) => {
                if (!h || h.indexOf('ENTRADA') !== -1 || /^(HORA|KM|KILOMETRAJE|ODOMETRO)\b/.test(h)) return;
                if (h.indexOf('FECHA') === 0) pon('fecha', i);
                else if (/SALIDA|VOLUMEN/.test(h)) {
                    if (salida === undefined) salida = i;
                } else if (/CANTIDAD|LITROS (DESPACHADOS|SURTIDOS)/.test(h) || EXACTO_LITROS.indexOf(h) !== -1) {
                    if (otraCantidad === undefined) otraCantidad = i;
                } else if (h === 'CI' || h === 'C I' || /C\. ?I|CEDULA/.test(h)) pon('ci', i);
                // Origen, cisterna o guía: no se cargan, y van antes que PLACA para que la
                // «PLACA CISTERNA» no se tome por la del vehículo.
                else if (/ORIGEN|SUMINISTRO|CISTERNA|GUIA/.test(h)) return;
                else if (/VISCOSIDAD|MEDIDA|ESPECIFICACION|MODELO (DEL )?CAUCHO/.test(h)) pon('espec', i);
                else if (/PLACA|SERIAL|IDENTIFICADOR/.test(h)) pon('id', i);
                else if (/RESPONSABLE|CHOFER|OPERADOR|CONDUCTOR/.test(h)) pon('nombre', i);
                // ACTIVIDAD: el proyecto en el reporte de ventas de Patio El Tigre (igual que el lector).
                else if (/^(PROYECTO|FRENTE|OBRA|ACTIVIDAD)\b/.test(h)) pon('frente', i);
                else if (/DESCRIPCION|CARACTERISTICAS|VEHICULO ?\/ ?EQUIPO/.test(h) || h === 'EQUIPO' || h === 'VEHICULO') {
                    if (desc === undefined) desc = i;
                } else if (h.indexOf('NOMBRE') !== -1) pon('nombre', i);
            });
            m.cantidad = salida !== undefined ? salida : otraCantidad;
            m.desc = desc;   // la descripción del equipo: va cuando la fila no trae placa/serial
            if (m.fecha === undefined || m.cantidad === undefined) return null;
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
         * Cantidad del Excel → «1234.5», con la misma regla que el lector del servidor
         * (LectorExcelCombustible::numero): con punto y coma, el último es el decimal («1.234,5»,
         * «1,234.5»); el punto de miles («1.000», «1.234.567») se quita; la coma sola es decimal («40,5»).
         */
        function numeroPegado(s) {
            const t = String(s || '').replace(/\s/g, '');
            const coma = t.lastIndexOf(','), punto = t.lastIndexOf('.');
            if (coma !== -1 && punto !== -1) {
                return coma > punto ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
            }
            if (/^\d{1,3}(\.\d{3})+$/.test(t)) return t.replace(/\./g, '');
            if (t.split(',').length > 2) return t.split(',').join('');
            return t.replace(',', '.');
        }

        // ── PEGAR DESDE EXCEL ─────────────────────────────────────────────
        // Funciona en TODA la página (no solo dentro de la tabla)
        window.pasteLoteCargarHandler = window.pasteLoteCargarHandler || function (e) {
            // El listener queda en document al salir por la SPA: fuera de esta pantalla, no toca nada.
            if (!document.getElementById('formLote')) return;
            // Lo pegado en el buscador de frente va a ese campo, no a la tabla.
            const active = document.activeElement;
            if (active && active.id === 'frenteSearch') return;
            // Ni lo pegado en el modal de Cupos de Gasoil (va a su campo, no a la tabla).
            if (active && active.closest && active.closest('#cuposModal')) return;

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

            // Lo pegado va SIEMPRE a esta tabla. El reporte de gasoil
            // con encabezados pasa antes por el lector, como un Excel subido (consImpPegar): así
            // al guardar se saltan los despachos ya cargados (los reportes son acumulados) y sus
            // miles de filas se pintan por tandas. Si el lector no lo entiende, se pega aquí.
            if (mapa && tipoPegado === 'GASOIL' && window.consImpPegar) {
                window.consImpPegar(text).then(ok => {
                    if (!ok && document.getElementById('cuerpoTabla')) pegarFilas(filas, mapa, desde);
                });
                return;
            }

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
        // Antes de pegar o de poner un Excel: si la tabla solo tiene filas vacías, se vacía.
        function vaciarSiNoHayDatos() {
            if (excelPorPintar.length) return;
            const filasSinDatos = [...document.getElementById('cuerpoTabla').querySelectorAll('tr')]
                .every(tr => [...tr.querySelectorAll('input:not([type=hidden])')].every(i => !i.value.trim()));
            if (filasSinDatos) {
                document.getElementById('cuerpoTabla').innerHTML = '';
                filaCount = 0;
            }
        }
        function pegarFilas(filas, mapa, desde) {
            vaciarSiNoHayDatos();
            if (filtroFrente !== null) filtrarPorFrente(null);   // lo pegado se ve entero
            const frag = document.createDocumentFragment();

            // Con encabezados, cada columna va a la suya por su nombre; sin ellos, por posición:
            // fecha, placa/serial, nombre, C.I., cantidad, [viscosidad o modelo del caucho,] proyecto.
            const espec = necesitaEspec();
            let insertadas = 0, sinFecha = 0;
            // Fecha en tres columnas (día | mes | año), como el reporte de ventas de Patio El Tigre:
            // el encabezado FECHA ocupa una celda y las dos de al lado vienen vacías
            // (LectorExcelCombustible::fechaPartida hace lo mismo con los Excel subidos).
            const enc = mapa && desde > 0 ? filas[desde - 1] : null;
            const fp = mapa ? mapa.fecha : undefined;
            const fechaPartida = !!(enc && fp !== undefined && !enc[fp + 1] && !enc[fp + 2]);
            filas.slice(desde).forEach(cols => {
                if (cols.some(c => /^(SUB ?)?TOTAL(ES)?\b/i.test(c))) return;   // fila de total
                // Sin encabezado, las filas de títulos antes de los datos (texto, no fecha ni número).
                if (!mapa && insertadas === 0 && cols[0] && !fechaISO(cols[0]) && isNaN(parseFloat(cols[0]))) return;
                const col = (campo, pos) => mapa
                    ? (mapa[campo] !== undefined ? (cols[mapa[campo]] || '') : '')
                    : (cols[pos] || '');
                // Sin litros no es despacho (la recarga del tanque); si no se supo cuál es la cantidad, sin fecha tampoco.
                if (mapa && (mapa.cantidad !== undefined ? !col('cantidad') : !col('fecha'))) return;
                const textoFecha = fechaPartida && /^\d{1,2}$/.test(cols[fp + 1] || '') && /^\d{4}$/.test(cols[fp + 2] || '')
                    ? cols[fp] + '/' + cols[fp + 1] + '/' + cols[fp + 2]
                    : col('fecha', 0);
                const fecha = fechaISO(textoFecha);
                if (!fecha && textoFecha) sinFecha++;
                agregarFila({
                    fecha: fecha,
                    id: col('id', 1) || (mapa ? col('desc') : ''),
                    nombre: col('nombre', 2),
                    ci: col('ci', 3),
                    cantidad: numeroPegado(col('cantidad', 4)),
                    espec: (mapa || espec) ? col('espec', 5) : '',
                    frente: col('frente', espec ? 6 : 5),
                }, null, frag);
                insertadas++;
            });
            // De una vez y al final de lo que ya hay (las filas del Excel aún sin pintar van detrás).
            document.getElementById('cuerpoTabla').appendChild(frag);
            resolverFrentesFilas();
            if (sinFecha && window.showToast) {
                window.showToast(sinFecha + (sinFecha === 1 ? ' fila trae una fecha que no se reconoce' : ' filas traen una fecha que no se reconoce') + ': complétala a mano.', 'warning');
            }

            programarResumen();
        }
        document.removeEventListener('paste', window.pasteLoteCargarHandler);
        document.addEventListener('paste', window.pasteLoteCargarHandler);

        // ── EXCEL SUBIDO (consumibles_importar.js) ────────────────────────
        // Sus despachos caen en esta misma tabla, como lo pegado, y se guardan con el mismo
        // Guardar. Cada fila lleva su carga y su lugar en ella (imp/ref): con eso el servidor
        // salta al guardar los que ya estaban cargados (ImportadorCombustible::aplicar).
        // Miles de filas con sus campos, de una vez, congelan la página: se pintan TODAS solas, por
        // tandas, una tanda por cuadro, y la página sigue respondiendo mientras tanto. Lo caro es
        // acomodarlas en pantalla (~1 ms por fila, medido; un ancho fijo de columnas no lo baja), así
        // que la tanda se ajusta a la PC: si el cuadro anterior tardó, la siguiente es más chica. La
        // tabla baja dentro de su propia caja (.tabla-scroll), así el botón Guardar no se aleja. Lo
        // que aún no se pintó está en excelPorPintar y se guarda igual.
        var excelPorPintar = [];
        var tandaExcel = 40;
        var pintando = null, cuadroAnterior = 0, pintandoExcel = false;
        function pintarTandaExcel(ahora) {
            pintando = null;
            const cuerpo = document.getElementById('cuerpoTabla');
            if (!cuerpo || !excelPorPintar.length) { pintandoExcel = false; return; }
            const paso = cuadroAnterior ? ahora - cuadroAnterior : 0;
            // Cada cuadro tiene además un costo fijo que crece con la tabla: tandas muy chicas suman
            // más cuadros y tardan más en total. Se apunta a cuadros de ~0,1–0,2 s.
            if (paso > 220) tandaExcel = Math.max(40, Math.round(tandaExcel * 0.7));
            else if (paso && paso < 110) tandaExcel = Math.min(400, Math.round(tandaExcel * 1.4));
            cuadroAnterior = ahora;
            const frag = document.createDocumentFragment();
            const nuevas = excelPorPintar.splice(0, tandaExcel).map(f => agregarFila(f, null, frag));
            cuerpo.appendChild(frag);
            if (filtroFrente !== null) aplicarFiltroFrente(nuevas);
            if (excelPorPintar.length) { pintando = requestAnimationFrame(pintarTandaExcel); return; }
            pintandoExcel = false;
            resumenCarga();
        }
        function pintarExcelPendiente() {
            if (pintando !== null || !excelPorPintar.length) return;
            resumenCarga();   // lo de la cola ya cuenta: el resumen sale completo desde el principio
            pintandoExcel = true;
            cuadroAnterior = 0;
            pintando = requestAnimationFrame(pintarTandaExcel);
        }
        function filaExcelParaGuardar(f) {
            return {
                fecha: f.fecha, identificador: f.id, resp_nombre: f.nombre, resp_ci: f.ci, cantidad: String(f.cantidad),
                frente: f.frente, id_frente: f.frente && f.id_frente ? String(f.id_frente) : '', imp: String(f.imp), ref: String(f.ref),
            };
        }

        window.consCargarFilasExcel = function (r) {
            if (!document.getElementById('cuerpoTabla')) return;   // se fue a otra pantalla
            // El Excel es de gasoil en litros. Con filas escritas de otro tipo o en otra unidad, cambiarlos
            // las guardaba como gasoil (o mezclaba galones con los litros del Excel): primero esas filas.
            const unidad = document.getElementById('unidadSelect').value;
            if (tipoActual() !== 'GASOIL' || unidad !== 'LITROS') {
                const conDatos = excelPorPintar.length || [...document.getElementById('cuerpoTabla').children]
                    .some(tr => [...tr.querySelectorAll('input:not([type=hidden])')].some(i => i.value.trim()));
                if (conDatos) {
                    window.showModal({ type: 'warning', title: 'Otro tipo en la tabla', message: 'La tabla tiene filas de otro tipo o unidad. Guárdalas o bórralas y vuelve a subir el Excel de gasoil (va en litros).', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                const item = document.querySelector('#tipoDropdownCargar .dropdown-item[data-value="GASOIL"]');
                window.selectOption('tipoDropdownCargar', 'GASOIL', item ? item.textContent.trim() : 'Gasoil');
                actualizarUnidad();
            }
            vaciarSiNoHayDatos();
            // Primero las que piden atención (proyecto sin reconocer): quedan arriba en la tabla.
            const filas = r.filas.map(f => Object.assign({ imp: r.importacion }, f));
            const mal = f => f.frente && !f.id_frente;
            if (filtroFrente !== null) filtrarPorFrente(null);
            // Las de proyecto sin reconocer se pintan YA, todas (arriba): su frente se elige en la
            // tabla y vale para todas las del mismo texto, así que tienen que estar todas en ella.
            const frag = document.createDocumentFragment();
            filas.filter(mal).forEach(f => agregarFila(f, null, frag));
            const cuerpo = document.getElementById('cuerpoTabla');
            cuerpo.insertBefore(frag, cuerpo.firstChild);
            excelPorPintar = excelPorPintar.concat(filas.filter(f => !mal(f)));
            programarResumen();
            pintarExcelPendiente();

            const n = r.filas.length, cargados = r.cargados || 0, repetidos = r.repetidos || 0;
            const fmt = x => Number(x).toLocaleString('es-VE');
            const partes = [];
            if (cargados) partes.push(fmt(cargados) + (cargados === 1 ? ' ya estaba cargado' : ' ya estaban cargados'));
            if (repetidos) partes.push(fmt(repetidos) + (repetidos === 1 ? ' se repite en otro de los archivos' : ' se repiten en otro de los archivos'));
            let msj = fmt(n) + (n === 1 ? ' despacho del Excel en la tabla.' : ' despachos del Excel en la tabla.');
            if (partes.length) msj += ' ' + partes.join(' y ') + ': se saltan al guardar.';
            if (window.showToast) window.showToast(msj, 'success');
            if (r.avisos && r.avisos.length && window.showToast) window.showToast(r.avisos.join(' '), 'warning');
            document.getElementById('resumenCarga').scrollIntoView({ behavior: 'smooth', block: 'start' });
        };

        // ── Envío AJAX con spinner ────────────────────────────────────────
        if (document.getElementById('formLote')) {
            // Un envío a la vez: un doble clic en Guardar mientras se reconocían los frentes guardaba dos veces.
            document.getElementById('formLote').addEventListener('submit', async function (e) {
                e.preventDefault();
                if (this.dataset.enviando) return;
                this.dataset.enviando = '1';
                try { await enviarLote(this); } finally { delete this.dataset.enviando; }
            });
        }
        async function enviarLote(form) {
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
                    const algo = [...tr.querySelectorAll('input:not([type=hidden])')].some(inp => inp.value.trim() !== '' && !inp.closest('[data-espec-cell][style*="none"]'));
                    // El número es el de la columna «#» (tras borrar filas no coincide con la posición).
                    if (algo && (!v('fecha') || !(parseFloat(v('cantidad')) > 0))) aMedias.push(tr.firstElementChild.textContent.trim());
                    // Frente: el reconocido o elegido en la fila; sin texto, el de arriba.
                    // La ya cargada o repetida se salta al guardar: no necesita frente.
                    if (tr.dataset.saltar) return;
                    if (algo && v('frente').trim() && !v('id_frente')) sinReconocer.push(tr.firstElementChild.textContent.trim());
                    if (algo && !v('frente').trim()) sinProyecto = true;
                });
                // Las del Excel aún sin pintar (el proyecto sin reconocer va primero, ya pintado).
                if (excelPorPintar.some(f => !f.saltar && !String(f.frente || '').trim())) sinProyecto = true;

                if (sinReconocer.length) {
                    // Con muchas, la lista de números no se lee: se muestran en la tabla solo esas filas.
                    const lista = sinReconocer.length > 12 ? sinReconocer.slice(0, 12).join(', ') + ' y ' + (sinReconocer.length - 12).toLocaleString('es-VE') + ' más' : sinReconocer.join(', ');
                    filtroFrente = 'mal'; aplicarFiltroFrente(); resumenCarga();
                    window.showModal({ type: 'warning', title: 'Frente sin reconocer', message: 'No se reconoce el proyecto de ' + (sinReconocer.length === 1 ? 'la fila ' : 'las filas ') + lista + ': elige su frente en la columna FRENTE. En la tabla quedan solo esas filas.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                if (sinProyecto && !frente) {
                    window.showModal({ type: 'warning', title: 'Campo requerido', message: 'Selecciona el frente de trabajo de arriba para las filas sin proyecto, o escríbelo en su columna FRENTE.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                if (!tipo) {
                    window.showModal({ type: 'warning', title: 'Campo requerido', message: 'Selecciona el tipo de consumible.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                if (aMedias.length) {
                    window.showModal({ type: 'warning', title: 'Filas incompletas', message: 'Falta la fecha o la cantidad en ' + (aMedias.length === 1 ? 'la fila ' : 'las filas ') + aMedias.join(', ') + '. Complétalas o bórralas.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }
                if (!filas && !excelPorPintar.length) {
                    window.showModal({ type: 'warning', title: 'Tabla vacía', message: 'Agrega al menos una fila con datos.', confirmText: 'Entendido', hideCancel: true });
                    return;
                }

                window.showPreloader();

                // En JSON y no como formulario: PHP lee hasta 1000 campos (max_input_vars) y una
                // tabla de más de ~140 filas perdía el resto sin avisar.
                const campos = ['fecha', 'identificador', 'resp_nombre', 'resp_ci', 'cantidad', 'especificacion', 'frente', 'id_frente', 'imp', 'ref'];
                const datos = {
                    tipo_consumible: tipo,
                    unidad: document.getElementById('unidadSelect').value,
                    id_frente: frente,
                    filas: [...document.getElementById('cuerpoTabla').children].map(tr => {
                        const o = {};
                        campos.forEach(c => { const i = tr.querySelector(`[name$="[${c}]"]`); if (i) o[c] = i.value; });
                        if ((tr.querySelector('[data-fila-frente]') || {}).dataset?.porSugerencia) o.sugerido = '1';
                        return o;
                    }).concat(excelPorPintar.map(filaExcelParaGuardar)),
                };

                const conExcel = datos.filas.some(f => f.imp);
                return window.apiFetch(form.action, { headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    method: 'POST',
                    body: JSON.stringify(datos)
                })
                    .then(async response => {
                        // Los 401/419 los ataja el interceptor global de fetch (estructura_base).
                        const data = await response.json().catch(() => ({}));
                        return { status: response.status, body: data };
                    })
                    .then(({ status, body }) => {
                        window.hidePreloader();

                        if (status === 200 || status === 201) {
                            // ── Éxito: limpiar formulario y mostrar toast ────────
                            // 1. Vaciar tabla y poner 5 filas limpias
                            document.getElementById('cuerpoTabla').innerHTML = '';
                            filaCount = 0;
                            excelPorPintar = [];
                            filtroFrente = null;
                            for (var i = 0; i < 5; i++) agregarFila();
                            programarResumen();

                            // 2. Resetear frente
                            document.getElementById('idFrenteHidden').value = '';
                            document.getElementById('frenteSearch').value = '';

                            // 3. Aviso de lo guardado
                            const n = Number(body.insertados || 0).toLocaleString('es-VE');
                            const saltados = body.saltados ? ` · ${Number(body.saltados).toLocaleString('es-VE')} repetidos se saltaron` : '';
                            window.showToast(`✓ ${n} registros guardados${saltados}`, 'success');
                            // La carga del Excel recién guardada sale en «Cargas anteriores» (para deshacerla).
                            if (conExcel && typeof window.navigateTo === 'function') window.navigateTo(location.href);

                        } else if (status === 422 && body.errors) {
                            const msgs = Object.values(body.errors).flat().map(window.escapeHtml).join('<br>');
                            window.showModal({ type: 'warning', title: 'Errores de validación', message: msgs, confirmText: 'Entendido', hideCancel: true });
                        } else {
                            throw new Error(body.message || 'Error desconocido del servidor.');
                        }
                    })
                    .catch(err => {
                        window.hidePreloader();
                        if (err.message === 'Sesión expirada.') return;
                        window.showModal({ type: 'error', title: 'Error', message: window.escapeHtml(err.message || 'Ocurrió un error al guardar.'), confirmText: 'Cerrar', hideCancel: true });
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
            // (estilo hoja de cálculo).
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
                // Lo escrito cambia el resumen (litros y frentes).
                tabla.addEventListener('input', programarResumen);
                // Sugerencia del frente del equipo: solo esta fila, sin aprender el texto.
                tabla.addEventListener('click', function (e) {
                    var b = e.target.closest('.fila-sugerido');
                    if (!b) return;
                    var inp = b.closest('td').querySelector('[data-fila-frente]');
                    inp.dataset.porSugerencia = '1';
                    estadoFrenteFila(inp, Number(b.dataset.sugId), b.dataset.sugNombre);
                });
                // Otra placa/serial en una fila de proyecto sin reconocer: la sugerencia era del equipo
                // anterior. Se quita su botón y se vuelve a pedir al salir de la celda; la ya tomada se
                // conserva hasta la respuesta (si el equipo nuevo está en otro frente, se pide elegir).
                tabla.addEventListener('input', function (e) {
                    if (!e.target.matches('[name$="[identificador]"]')) return;
                    var f = e.target.closest('tr').querySelector('[data-fila-frente]');
                    if (!f.value.trim() || f.value.trim() !== f.dataset.sinReconocer) return;
                    var td = f.closest('td');
                    if (td.querySelector('[name$="[id_frente]"]').value && !f.dataset.porSugerencia) return;   // elegido en la lista: se queda
                    f.dataset.resuelto = '';
                    var b = td.querySelector('.fila-sugerido');
                    if (b) b.remove();
                });
                tabla.addEventListener('focusout', function (e) {
                    if (e.target.matches('[name$="[identificador]"]')) resolverFrentesFilas();
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
                    delete e.target.dataset.porSugerencia;
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
                    // Elegido en la lista: se aprende. Las que tomaron la sugerencia de su equipo se quedan con ella.
                    delete inp.dataset.porSugerencia;
                    filas.forEach(function (i) {
                        i.value = original || nombre;
                        estadoFrenteFila(i, id, nombre);
                    });
                    cerrarListaFrenteFila();
                });
                // La lista es fija en la pantalla: al desplazar la tabla o la página se cierra.
                document.getElementById('tablaFilas').closest('.tbl-wrap').addEventListener('scroll', cerrarListaFrenteFila, { passive: true });
                document.getElementById('tablaScroll').addEventListener('scroll', cerrarListaFrenteFila, { passive: true });
                // Resumen: un grupo muestra solo sus filas; «Ver todas las filas», todas.
                document.getElementById('resumenCarga').addEventListener('click', function (e) {
                    if (e.target.closest('[data-grupo-todas]')) { filtrarPorFrente(null); return; }
                    var b = e.target.closest('[data-grupo]');
                    if (b) filtrarPorFrente(b.dataset.grupo);
                });
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
            // Llegada por la SPA: el DOM ya está.
            setTimeout(initCargarConsumibles, 50);
        }
    </script>
@endsection