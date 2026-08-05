@extends('layouts.estructura_base')
@section('title', 'Cargar Consumibles')

@section('content')
    <style>
        .con-select,
        .con-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #cbd5e0;
            border-radius: 10px;
            font-size: 13px;
            color: #1e293b;
            background: #fbfcfd;
            outline: none;
            transition: border .2s;
        }

        .con-select:focus,
        .con-input:focus {
            border-color: #0067b1;
            background: #fff;
        }

        .btn-green {
            background: linear-gradient(135deg, #059669, #047857);
        }

        /* ── ZONA DE PEGADO ── */
        .paste-zone {
            border: 3px dashed #bfdbfe;
            border-radius: 12px;
            background: #f0f9ff;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all .2s;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }



        .paste-zone i {
            font-size: 28px;
            color: #0067b1;
        }

        .paste-zone p {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #1d4ed8;
        }

        .paste-zone small {
            display: block;
            font-size: 12px;
            color: #64748b;
            margin-top: 3px;
            font-weight: 400;
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

        .badge-count {
            display: inline-block;
            background: #0067b1;
            color: #fff;
            border-radius: 20px;
            padding: 1px 10px;
            font-size: 12px;
            font-weight: 700;
            margin-left: 8px;
        }

        @media (max-width: 900px) {
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
            <div class="tbl-wrap" style="padding: 15px 15px 0 15px; background: #fff;">

                {{-- SELECTORES INTEGRADAMENTE DENTRO DEL CONTENEDOR --}}
                <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:end; margin-bottom: 20px;">
                    {{-- FRENTE (primer filtro, a pedido del cliente) --}}
                    <div style="flex: 1; min-width: 200px;">
                        <div style="position:relative;">
                            <div
                                style="padding:0; display:flex; align-items:center; background:#fbfcfd; overflow:hidden; border:1px solid #cbd5e0; border-radius:10px; height:42px; position:relative;">
                                <input type="text" id="frenteSearch" name="search_frente_cargar"
                                    placeholder="Frente de Trabajo *" autocomplete="off"
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
                                            onclick="window.selectOption('tipoDropdownCargar', '{{ $val }}', '{{ $label }}'); window.actualizarUnidad();">
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
                                            onclick="window.selectOption('unidadDropdownCargar', '{{ $val }}', '{{ $label }}');">
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
                            style="display:none; position:absolute; top:100%; right:0; min-width:260px; background:#e2e8f0; border-radius:8px; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); border:1px solid #e2e8f0; z-index:1050; margin-top:10px; overflow:hidden;">

                            <a href="{{ route('consumibles.index') }}" class="dropdown-item-custom hide-on-mobile"
                                style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; border-bottom: 1px solid #f1f5f9; background: transparent; transition: all 0.2s; cursor: default;"
                                onclick="if(window.showPreloader) window.showPreloader();"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <div style="background: #e0f2fe; padding: 6px; border-radius: 6px; display: flex;">
                                    <i class="material-icons" style="font-size: 18px; color: #0284c7;">list</i>
                                </div>
                                <span style="font-size:14px; font-weight:500;">Lista de Consumibles</span>
                            </a>

                            <a href="{{ route('consumibles.graficos') }}" class="dropdown-item-custom hide-on-mobile"
                                style="display: flex; align-items: center; gap: 10px; padding: 12px 15px; color: #475569; text-decoration: none; border-bottom: 1px solid #f1f5f9; background: transparent; transition: all 0.2s; cursor: default;"
                                onclick="if(window.showPreloader) window.showPreloader();"
                                onmouseover="this.style.background='#f8fafc'"
                                onmouseout="this.style.background='transparent'">
                                <div style="background: #eff6ff; padding: 6px; border-radius: 6px; display: flex;">
                                    <i class="material-icons" style="font-size: 18px; color: #3b82f6;">analytics</i>
                                </div>
                                <span style="font-size:14px; font-weight:500;">Gráficos y Reportes</span>
                            </a>

                        </div>
                    </div>
                </div>

                {{-- Instrucciones de Pegado Rápido --}}
                <div class="paste-zone" onclick="document.execCommand('paste');"
                    title="También puede presionar Ctrl+V en su teclado"
                    style="padding: 8px 15px; margin-bottom: 12px; border-radius: 8px; flex-direction: row; gap: 8px; border-width: 2px;">
                    <i class="material-icons" style="font-size: 20px; color:#1d4ed8;">content_paste</i>
                    <span style="font-size: 13px; font-weight: 600; color: #1d4ed8;">Copie de Excel y pegue en esta tabla
                        (presione Ctrl+V)</span>
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
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoTabla"></tbody>
                </table>
            </div>

            {{-- El botón "Agregar Fila" se eliminó: ahora la fila se agrega sola al escribir
                 en la última (auto-row en initCargarConsumibles). --}}
        </div>

        {{-- ACCIONES. "Guardar Lote" solo visible con permiso super.admin
             (coincide con el middleware del POST /consumibles/guardar-lote). --}}
        <div style="margin-top: 30px; display: flex; gap: 12px; justify-content: center; padding-bottom:40px;">
            <a href="{{ route('consumibles.index') }}" class="btn-primary-maquinaria btn-secondary">
                Cancelar
            </a>
            @can('super.admin')
                <button type="submit" class="btn-primary-maquinaria">
                    <i class="material-icons">save</i>
                    Guardar Lote
                </button>
            @else
                <span style="font-size:12px; color:#94a3b8; padding:10px 14px; border:1px dashed #cbd5e1; border-radius:8px;">
                    Solo un Super Admin puede guardar el lote.
                </span>
            @endcan
        </div>

    </form>

    {{-- ═══ JAVASCRIPT ═══ --}}
    <script>
        var FRENTES = @json($frentes->map(fn($f) => ['id' => $f->ID_FRENTE, 'nombre' => $f->NOMBRE_FRENTE]));
        var filaCount = 0;

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
                    inp.setAttribute('list', dlId);
                    if (!espec) inp.value = '';
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
                    `<div onclick="window.seleccionarFrente(${f.id},'${f.nombre.replace(/'/g, "\\'")}')"
                                                          style="padding:10px 14px; cursor:pointer; font-size:13px; color:#1e293b; transition:background .15s;"
                                                          onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                                                        ${f.nombre}
                                                     </div>`
                ).join('');
            dd.style.display = 'block';
        }

        function mostrarTodosLosFrente() { renderDropdownFrente(FRENTES); }

        function filtrarFrentes(q) {
            if (!q || q.length < 1) { renderDropdownFrente(FRENTES); return; }
            renderDropdownFrente(FRENTES.filter(f => f.nombre.toLowerCase().includes(q.toLowerCase())));
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
                                                <td><input type="date"   name="filas[${n}][fecha]"         value="${limpiar(data.fecha)}"    tabindex="${n * 10 + 1}" class="native-date" onclick="this.showPicker()" style="cursor:pointer;"></td>
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

        // ── PEGAR DESDE EXCEL ─────────────────────────────────────────────
        // Funciona en TODA la página (no solo dentro de la tabla)
        window.pasteLoteCargarHandler = window.pasteLoteCargarHandler || function (e) {
            // Si el foco está en un input del lote de configuración → no interceptar
            const active = document.activeElement;
            if (active && (active.id === 'frenteSearch' || active.id === 'tipoSelect' || active.id === 'unidadSelect')) return;

            const text = (e.clipboardData || window.clipboardData).getData('text');
            if (!text || (!text.includes('\t') && !text.includes('\n'))) return;

            e.preventDefault();

            // Limpiar tabla antes de pegar (opcional: solo si hay filas vacías)
            const filasSinDatos = [...document.getElementById('cuerpoTabla').querySelectorAll('tr')]
                .every(tr => [...tr.querySelectorAll('input')].every(i => !i.value.trim()));
            if (filasSinDatos) {
                document.getElementById('cuerpoTabla').innerHTML = '';
                filaCount = 0;
            }

            // Parsear líneas
            const lineas = text.trim().split('\n');
            let insertadas = 0;

            lineas.forEach(linea => {
                const cols = linea.split('\t').map(c => c.trim().replace(/\r/g, ''));
                // Saltar si la línea está completamente vacía
                if (cols.every(c => !c)) return;

                // Autodetectar si la primera línea es encabezado (texto, no fecha ni número)
                if (insertadas === 0 && isNaN(Date.parse(cols[0])) && isNaN(parseFloat(cols[0])) && cols[0] && !/^\d{1,2}\/\d{1,2}/.test(cols[0])) {
                    return; // saltar encabezado
                }

                // ── Parsear fecha ─────────────────────────────────────────
                let fecha = cols[0] || '';

                // Formato Excel venezolano: DD-MM-YY  →  19-02-26 = 19 feb 2026
                if (/^\d{1,2}-\d{1,2}-\d{2}$/.test(fecha)) {
                    const p = fecha.split('-');
                    const yy = parseInt(p[2]);
                    const yyyy = yy <= 50 ? 2000 + yy : 1900 + yy;  // 26→2026, 99→1999
                    fecha = `${yyyy}-${p[1].padStart(2, '0')}-${p[0].padStart(2, '0')}`;
                }
                // Formato DD-MM-YYYY con 4 dígitos de año
                else if (/^\d{1,2}-\d{1,2}-\d{4}$/.test(fecha)) {
                    const p = fecha.split('-');
                    fecha = `${p[2]}-${p[1].padStart(2, '0')}-${p[0].padStart(2, '0')}`;
                }
                // Formato DD/MM/YYYY o MM/DD/YYYY con barras
                else if (/^\d{1,2}\/\d{1,2}\/\d{4}$/.test(fecha)) {
                    const p = fecha.split('/');
                    // Siempre asumiremos DD/MM/YYYY (Formato de Venezuela)
                    fecha = `${p[2]}-${p[1].padStart(2, '0')}-${p[0].padStart(2, '0')}`;
                }
                // YYYY-MM-DD ya está en formato correcto → no tocar

                agregarFila({
                    fecha: fecha,
                    id: cols[1] || '',
                    nombre: cols[2] || '',
                    ci: cols[3] || '',
                    cantidad: (cols[4] || '').replace(',', '.'),
                    espec: necesitaEspec() ? (cols[5] || '') : '',
                    origen: necesitaEspec() ? (cols[6] || '') : (cols[5] || ''),
                });
                insertadas++;
            });

            if (insertadas > 0) {
                const pz = document.getElementById('pasteZone');
                if (pz) {
                    pz.style.borderColor = '#059669';
                    setTimeout(() => { pz.style.borderColor = ''; }, 1000);
                }
            }
        };
        document.removeEventListener('paste', window.pasteLoteCargarHandler);
        document.addEventListener('paste', window.pasteLoteCargarHandler);

        // ── Envío AJAX con spinner ────────────────────────────────────────
        if (document.getElementById('formLote')) {
            document.getElementById('formLote').addEventListener('submit', function (e) {
                e.preventDefault();

                const frente = document.getElementById('idFrenteHidden').value;
                const tipo = document.getElementById('tipoSelect').value;
                const filas = document.getElementById('cuerpoTabla').children.length;

                if (!frente) {
                    if (window.showModal) {
                        window.showModal({ type: 'warning', title: 'Campo requerido', message: 'Selecciona un frente de trabajo.', confirmText: 'Entendido', hideCancel: true });
                    } else { alert('Selecciona un frente de trabajo.'); }
                    return;
                }
                if (!tipo) {
                    if (window.showModal) {
                        window.showModal({ type: 'warning', title: 'Campo requerido', message: 'Selecciona el tipo de consumible.', confirmText: 'Entendido', hideCancel: true });
                    } else { alert('Selecciona el tipo de consumible.'); }
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

                fetch(this.action, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': window.getCsrf()
                    },
                    body: formData
                })
                    .then(async response => {
                        if (response.status === 419 || response.status === 401 ||
                            (response.redirected && response.url.includes('/login'))) {
                            window.location.href = '/login';
                            return Promise.reject(new Error('Sesión expirada.'));
                        }
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
                                    title: '¡Lote guardado!',
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