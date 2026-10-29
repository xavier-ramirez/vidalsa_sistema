@extends('layouts.estructura_base')

@section('title', 'Auditoría de Documentos')

@section('content')
<link rel="stylesheet" href="{{ asset('css/vistas/admin_historial_documentos_index.css') }}?v={{ @filemtime(public_path('css/vistas/admin_historial_documentos_index.css')) }}">

{{-- Sin título de página: las pestañas ya dicen dónde se está (pedido del cliente, 19-10-2026). --}}
<div class="hd-pest-fila">
    <div class="hd-pest">
    {{-- Las tres para quien entra (Usuario::veAuditoriaDocumentos). Con solo la carga masiva,
         el historial y la compresión son de mirar: borrar y la Papelera piden super.admin.
         La revisión va primero y es la que se abre (HistorialDocumentosController::index). --}}
    {{-- "Revisión automática" (pedido del cliente, 21-10-2026). En el teléfono, cada nombre sin
         su .hd-pest-mas: "Revisión", "Historial", "Compresión". --}}
    <button type="button" class="{{ $pestana === 'documentos' ? 'on' : '' }}" onclick="window.hdPestana('documentos')">
        Revisión<span class="hd-pest-mas"> automática</span>
        @if ($docsParaRevisar) <span class="hd-pend" title="Documentos que hay que revisar a mano">{{ $docsParaRevisar }}</span> @endif
    </button>
    <button type="button" class="{{ $pestana === 'historial' ? 'on' : '' }}" onclick="window.hdPestana('historial')">Historial<span class="hd-pest-mas"> de cambios</span></button>
    <button type="button" class="{{ $pestana === 'compresion' ? 'on' : '' }}" onclick="window.hdPestana('compresion')">Compresión<span class="hd-pest-mas"> de PDF</span></button>
    </div>
</div>
<script>
    // Cambiar de pestaña = pedir la misma pantalla con ?pestana=..., por la SPA.
    window.hdPestana = function (cual) {
        var url = @json(route('historial-documentos.index')) + '?pestana=' + cual;
        if (typeof window.navigateTo === 'function') window.navigateTo(url);
        else window.location.href = url;
    };
</script>

@include('admin.historial_documentos.partials.desplegables')
@include('admin.historial_documentos.partials.papelera')
{{-- Sin el permiso el modal NI SE CARGA: así no existe ni su HTML ni su
     window.abrirCargaMasiva, y no basta con esconder el botón del menú Acciones. --}}
@can('docs.carga.masiva')
    @include('admin.historial_documentos.partials.carga_masiva')
@endcan

@if ($pestana !== 'historial')
    @include('admin.compresion_pdf.panel')
@else
<div class="maquinaria-layout-container hd-layout-grid" style="display: grid; grid-template-columns: 1fr 280px; gap: 20px; width: 98%; max-width: 1600px; margin: 0 auto;">
    
    <!-- Left Column (Main Content) -->
    <div>
        <div class="admin-card">
            <div class="filter-toolbar-container hd-filter-row" style="margin-bottom: 8px;">
                <!-- Search Correo -->
                <div class="filter-item aligned-filter responsive-filter-item" style="position: relative;">
                    <form style="width: 100%;" onsubmit="event.preventDefault(); window.loadHistorialDocumentos();">
                        <div class="search-wrapper" style="width: 100%; border-color: {{ request('search_correo') ? '#0067b1' : '#cbd5e0' }}; background: {{ request('search_correo') ? '#e1effa' : '#fbfcfd' }}; height: 45px;">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="searchCorreo" name="search_correo"
                                value="{{ request('search_correo') }}"
                                placeholder="Buscar nombre o correo..."
                                class="search-input-field"
                                style="height: 100%;"
                                autocomplete="off"
                                oninput="window.hdCorreoSuggest && window.hdCorreoSuggest()"
                                onfocus="window.hdCorreoSuggest && window.hdCorreoSuggest()"
                                onkeyup="window.checkHistorialClearBtn('searchCorreo', 'btn_clear_searchCorreo')">
                            <i id="btn_clear_searchCorreo" class="material-icons clear-icon" style="display: {{ request('search_correo') ? 'block' : 'none' }};" onclick="clearHistorialFilter('btn_clear_searchCorreo', 'searchCorreo');">close</i>
                        </div>
                        <div id="hdCorreosSuggest" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1000; margin-top:6px; max-height:280px; overflow-y:auto; background:#fff; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 6px 16px rgba(0,0,0,0.08); padding:5px;"></div>
                    </form>
                </div>

                @php
                    // Opciones del filtro de acción: viven en HistorialDocumentosController (la
                    // misma lista la cuenta el resumen de la barra lateral).
                    $tipoGrupos = \App\Http\Controllers\HistorialDocumentosController::TIPOS_ACCION;
                    $reqTipo = request('search_tipo');
                    $tipoActivo = $reqTipo && $reqTipo !== 'all';
                    // Etiqueta del valor pedido, para que el placeholder no muestre el
                    // valor crudo ('cat_*') al recargar la página.
                    $reqTipoLabel = collect($tipoGrupos)->collapse()->get($reqTipo, $reqTipo);
                @endphp
                {{-- Acción, entre el usuario y el serial. Elegir una opción la aplica: selectOption lanza
                     'dropdown-selection' y ese evento recarga la tabla
                     (historial_documentos_index.js) — por eso los onclick NO llaman además a
                     loadHistorialDocumentos. --}}
                <div class="filter-item aligned-filter responsive-filter-item">
                    <div class="custom-dropdown" id="tipoDocFilterSelect" data-filter-type="tipo_filter" data-default-label="Filtrar Acción..." style="width: 100%;">
                        <input type="hidden" name="search_tipo" data-filter-value value="{{ $reqTipo ?: '' }}">

                        <div class="dropdown-trigger {{ $tipoActivo ? 'filter-active' : '' }}" style="background: {{ $tipoActivo ? '#e1effa' : '#fbfcfd' }}; border: 1px solid {{ $tipoActivo ? '#0067b1' : '#cbd5e0' }}; border-radius: 12px; height: 45px; display: flex; align-items: center; justify-content: space-between; padding: 0; width: 100%; overflow: hidden;">
                            <div style="padding: 0 10px; display: flex; align-items: center; color: var(--maquinaria-gray-text);">
                                <i class="material-icons" style="font-size: 18px;">search</i>
                            </div>
                            <input type="text" name="filter_search_dropdown" data-filter-search
                                placeholder="{{ $tipoActivo ? $reqTipoLabel : 'Filtrar Acción...' }}"
                                style="width: 100%; border: none; background: transparent; padding: 10px 5px; font-size: 14px; outline: none; color: #4a5568;"
                                onkeyup="window.filterDropdownOptions(this)"
                                autocomplete="off">
                            <div style="display: flex; align-items: center; padding-right: 10px;">
                                <i class="material-icons" data-clear-btn
                                   style="font-size: 18px; color: #a0aec0; margin-right: 5px; display: {{ $tipoActivo ? 'block' : 'none' }};"
                                   onclick="event.stopPropagation(); clearDropdownFilter('tipoDocFilterSelect');"
                                   title="Limpiar filtro">close</i>
                            </div>
                        </div>

                        <div class="dropdown-content" style="padding: 5px; max-height: none; overflow: visible;">
                            <div class="dropdown-item-list" style="max-height: 320px; overflow-y: auto;">
                                <div class="dropdown-item {{ !$tipoActivo ? 'selected' : '' }}" data-value="all" data-label="TODAS LAS ACCIONES"
                                     onclick="selectOption('tipoDocFilterSelect', this.dataset.value, this.dataset.label)">
                                    TODAS LAS ACCIONES
                                </div>
                                @foreach($tipoGrupos as $grupo => $opciones)
                                    <div style="padding:4px 8px 2px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.5px; border-top:1px solid #e2e8f0; margin-top:4px;">{{ $grupo }}</div>
                                    @foreach($opciones as $valor => $etiqueta)
                                        <div class="dropdown-item {{ $reqTipo === $valor ? 'selected' : '' }}" data-value="{{ $valor }}" data-label="{{ $etiqueta }}"
                                             onclick="selectOption('tipoDocFilterSelect', this.dataset.value, this.dataset.label)">{{ $etiqueta }}</div>
                                    @endforeach
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Equipo (Placa/Serial) -->
                <div class="filter-item aligned-filter responsive-filter-item">
                    <form style="width: 100%;" onsubmit="event.preventDefault(); window.loadHistorialDocumentos();">
                        <div class="search-wrapper" style="width: 100%; border-color: #cbd5e0; background: #fbfcfd; height: 45px;">
                            <i class="material-icons search-icon">search</i>
                            <input type="text" id="searchEquipo" name="search_equipo"
                                value="{{ request('search_equipo') }}"
                                placeholder="Buscar serial..."
                                class="search-input-field"
                                style="height: 100%;"
                                autocomplete="off"
                                onkeyup="window.checkHistorialClearBtn('searchEquipo', 'btn_clear_searchEquipo')">
                            <i id="btn_clear_searchEquipo" class="material-icons clear-icon" style="display: {{ request('search_equipo') ? 'block' : 'none' }};" onclick="clearHistorialFilter('btn_clear_searchEquipo', 'searchEquipo');">close</i>
                        </div>
                    </form>
                </div>

                @include('admin.historial_documentos.partials.filtro_fechas', ['alCambiar' => 'window.loadHistorialDocumentos'])

                @include('admin.historial_documentos.partials.acciones')
            </div>

            <!-- Unified Responsive Table -->
            <div class="custom-scrollbar-container">
                <table class="admin-table table-historial-mobile" id="historialDocumentosTable">
                    <thead>
                        {{-- Igual que las cabeceras de Revisión y Compresión (compresion_pdf/panel):
                             sin rayas entre columnas, rótulos cortos y la de los botones sin título. --}}
                        <tr class="tabla-cabecera">
                            <th style="width: 130px;">Fecha</th>
                            <th style="width: 24%;">Autor</th>
                            <th style="width: 22%;">Tipo de acción</th>
                            <th>Equipo</th>
                            <th style="width: 90px;"></th>
                        </tr>
                    </thead>
                    <tbody id="historialTableBody" style="font-size: 13px;">
                        @include('admin.historial_documentos.partials.table_rows', ['events' => $events])
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div id="historialPagination" style="margin-top: 15px;">
                {{ $events->links('vendor.pagination.custom-sliding') }}
            </div>

        </div>
    </div>

    <!-- Right Sidebar -->
    <div class="counter-sidebar historial-sidebar" id="historialSidebar" style="position: sticky; top: 90px; align-self: start; display: flex; flex-direction: column; gap: 10px; z-index: 10;">

        <!-- Total Card -->
        <div class="hd-total-card" style="background: linear-gradient(135deg, #001a52 0%, #0a4a91 100%); border-radius: 12px; padding: 15px; color: white; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); position: relative; overflow: hidden;">
            <i class="material-icons" style="position: absolute; right: -15px; bottom: -15px; font-size: 80px; opacity: 0.1; transform: rotate(-15deg);">history</i>
            <div style="position: relative; z-index: 2;">
                <div style="font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 5px;">Total Auditoría</div>
                <div style="display: flex; align-items: baseline; gap: 5px;">
                    <span id="historial-count-text" style="font-size: 32px; font-weight: 800; line-height: 1; letter-spacing: -1px;">
                        {{ $total }}
                    </span>
                    <span style="font-size: 12px; font-weight: 500;">registros</span>
                </div>
            </div>
        </div>

        {{-- Resumen de la lista (con sus filtros): cada fila filtra la tabla al pulsarla. Se
             repinta con cada filtrado (resumen_html, ver historial_documentos_index.js). --}}
        <div id="hdResumen">@include('admin.historial_documentos.partials.resumen_lateral', ['resumen' => $resumen])</div>

    </div>
</div>

{{-- ─── CONTADOR FLOTANTE DE SELECCIÓN (el mismo de las tres pestañas) ─── --}}
@include('admin.historial_documentos.partials.chip_seleccion', ['id' => 'hd-selection-chip',
    'alTocar' => 'window.hdToggleSoloSel', 'alLimpiar' => 'window.hdClearSelection'])

<style>
    /* Hover en filas seleccionables. Los fondos llevan !important porque .admin-table
       (estilos_globales.css) fija el de las celdas con !important. */
    #historialDocumentosTable .hd-selectable-row:not(.selected-row-maquinaria):hover td {
        background: #f8fafc !important;
        transition: background 0.15s;
    }

    /* Desktop: fila abierta (burbuja de cambios) o seleccionada = fondo azul claro, borde
       azul y una barra a la izquierda. La barra es una sombra interior y no un borde de 4 px,
       que corría el contenido de la fila. */
    @media (min-width: 769px) {
        /* .hd-selectable-row va en el selector para igualar la especificidad del hover de
           arriba y, por estar después, ganarle: la fila se abre justo al pasar el ratón. */
        #historialDocumentosTable .hd-selectable-row.hd-has-cambios.hd-detail-open td {
            background: #eff6ff !important;
            border-color: #93c5fd !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td {
            background: #e1effa !important;
            color: #0067b1;
            border-color: #93c5fd !important;
            transition: background 0.2s ease;
        }
        #historialDocumentosTable .hd-has-cambios.hd-detail-open td:first-child,
        #historialDocumentosTable tr.selected-row-maquinaria td:first-child {
            box-shadow: inset 7px 0 0 #0067b1;
        }

        /* ── Cambios editados: BURBUJA FLOTANTE en PC ──
           Antes el detalle se expandía inline y empujaba/agrandaba el registro.
           Ahora flota como popover anclado a la celda del equipo (4ª col): no
           altera la estructura de la fila — flota sobre el contenido. */
        /* El contenedor usa overflow-x:auto (que segun el spec recorta tambien en
           vertical) y cliparia la burbuja en las ultimas filas. En desktop la tabla
           cabe sin scroll horizontal, asi que liberamos el overflow para que flote. */
        .custom-scrollbar-container { overflow: visible; }
        #historialDocumentosTable .hd-has-cambios td:nth-child(4) { position: relative; }
        #historialDocumentosTable .hd-cambios-detail {
            position: absolute;
            top: calc(100% - 2px);
            left: 0;
            z-index: 200;
            width: max-content;
            min-width: 240px;
            max-width: 380px;
            margin-top: 0 !important;
        }
        #historialDocumentosTable .hd-cambios-detail > div {
            max-height: 320px;
            overflow-y: auto;
        }
    }
    /* Móvil: la tarjeta entera con el azul de la PC (pedido del cliente, 21-10-2026). */
    @media (max-width: 768px) {
        #historialDocumentosTable tr.selected-row-maquinaria {
            border: 2px solid var(--maquinaria-blue, #0067b1) !important;
            background-color: #e1effa !important;
            /* La franja de la izquierda, bien marcada: es lo que se ve de un vistazo. */
            box-shadow: inset 7px 0 0 var(--maquinaria-blue, #0067b1), 0 4px 12px rgba(0, 103, 177, 0.15) !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td {
            background-color: transparent !important;
            border-color: transparent !important;
            color: inherit !important;
        }
        #historialDocumentosTable tr.selected-row-maquinaria td:nth-child(4) {
            border-top: 1px solid #bfdbfe !important;
            border-bottom: 1px solid #bfdbfe !important;
        }
    }

</style>


{{-- ── Desplegables del módulo: solo uno abierto a la vez ──
     Sugerencias de correo, desplegable de acción, Filtros Avanzados (fechas) y
     Acciones. Los botones NO hacen stopPropagation: cada cierre vive en un listener de
     document que ignora los clics dentro de su propio bloque (closest). El de acción lo
     cierra uicomponents.js al hacer clic fuera; las sugerencias, el mousedown de
     hdCorreoSuggest. Con FOCO sin clic (Tab, "siguiente" del teclado del teléfono)
     focusin hace el mismo cierre. Listeners una sola vez (SPA-safe). --}}




<script>
(function () {
    if (window.__hdCorreoAutoInit) return;
    window.__hdCorreoAutoInit = true;
    var AUTORES = @json($autoresSugeridos ?? []);
    var esc = window.escapeHtml;   // helper central (dom_helpers.js)

    window.hdCorreoSuggest = function () {
        var inp = document.getElementById('searchCorreo');
        var box = document.getElementById('hdCorreosSuggest');
        if (!inp || !box) return;
        var term = (inp.value || '').trim().toLowerCase();
        // Casa por nombre O por correo (substring).
        var m = AUTORES.filter(function (a) {
            return String(a.nombre || '').toLowerCase().indexOf(term) !== -1
                || String(a.correo || '').toLowerCase().indexOf(term) !== -1;
        }).slice(0, 50);
        if (!m.length) { box.style.display = 'none'; return; }
        box.innerHTML = m.map(function (a) {
            var nombre = a.nombre || '';
            var inner = nombre
                ? '<span style="font-size:14px;font-weight:600;color:#1e3a5f;">' + esc(nombre) + '</span>'
                  + '<span style="font-size:12px;color:#64748b;">' + esc(a.correo) + '</span>'
                : '<span style="font-size:14px;font-weight:600;color:#1e3a5f;">' + esc(a.correo) + '</span>';
            return '<div class="hd-correo-item" data-val="' + esc(a.correo) + '"'
                 + ' onmouseover="this.style.background=\'#f0f4f8\'" onmouseout="this.style.background=\'transparent\'"'
                 + ' style="padding:9px 14px;border-radius:8px;cursor:pointer;display:flex;flex-direction:column;gap:2px;background:transparent;">'
                 + inner + '</div>';
        }).join('');
        box.style.display = 'block';
    };

    // Un solo listener: selecciona si se clickea un item, cierra si el clic es fuera.
    document.addEventListener('mousedown', function (e) {
        var it = e.target.closest ? e.target.closest('.hd-correo-item') : null;
        var box = document.getElementById('hdCorreosSuggest');
        var inp = document.getElementById('searchCorreo');
        if (it) {
            e.preventDefault();
            if (inp) inp.value = it.getAttribute('data-val');
            if (box) box.style.display = 'none';
            if (typeof window.checkHistorialClearBtn === 'function') window.checkHistorialClearBtn('searchCorreo', 'btn_clear_searchCorreo');
            if (typeof window.loadHistorialDocumentos === 'function') window.loadHistorialDocumentos();
            return;
        }
        if (box && e.target !== inp && !box.contains(e.target)) box.style.display = 'none';
    });
})();
</script>

<script>
// Los listeners van DELEGADOS en `document`, así funcionan con las filas cargadas por
// AJAX (filtros/paginación) sin re-bindear. Guard anti-doble-registro: este bloque
// inline se re-ejecuta en cada navegación SPA; sin el guard se acumulaban N copias del
// handler y, como el de la burbuja hace toggle (abrir/cerrar), al dispararse 2+ veces
// en un solo clic la burbuja se abría y se cerraba al instante → parecía no responder
// hasta recargar la página (F5). Con el guard se registra UNA sola vez.
if (!window._hdInlineClickRegistered) {
    window._hdInlineClickRegistered = true;

    // Abrir y cerrar viven en un punto unico: los usan el raton, el toque y la X.
    // Global porque la X de dentro de la burbuja se pinta en table_rows con un
    // onclick inline: sin exponerla, ese onclick no alcanza a una funcion
    // declarada aqui dentro del guard.
    window.hdCerrarCambios = hdCerrarTodo;

    function hdCerrarTodo() {
        document.querySelectorAll('.hd-cambios-detail').forEach(function (d) { d.style.display = 'none'; });
        document.querySelectorAll('.hd-detail-open').forEach(function (r) { r.classList.remove('hd-detail-open'); });
    }

    function hdAbrir(row) {
        var detail = row.querySelector('.hd-cambios-detail');
        if (!detail) return;
        detail.style.display = 'block';
        row.classList.add('hd-detail-open');
    }

    // Pantallas sin raton (telefono, tablet): el toque hace de "pasar por encima".
    // Tocar una tarjeta con la burbuja cerrada muestra sus cambios (y cierra la de
    // otra); tocarla con la burbuja abierta la cierra. La burbuja NO depende de la
    // seleccion —que es multiple y la alterna historial_documentos_index.js con el
    // mismo toque—: atada a ella, tras tocar otra tarjeta o cerrar con la X habia
    // que tocar dos veces para volver a ver los cambios. La X cierra sin tocar la
    // seleccion (para la propagacion).
    var hdSinHover = window.matchMedia('(hover: none)');

    document.addEventListener('click', function (e) {
        if (!hdSinHover.matches) return;
        var row = e.target.closest('.hd-has-cambios');
        if (!row || e.target.closest('button, a')) return;
        var abierta = row.classList.contains('hd-detail-open');
        hdCerrarTodo();
        if (!abierta) hdAbrir(row);
    });

    // ── Vista previa al pasar el raton ──────────────────────────────────────
    // Basta con pasar por encima de la fila para ver qué cambió; el clic queda
    // libre para SELECCIONAR (contador flotante / "ver solo seleccionados").
    //
    // Con un retardo corto: sin el, al recorrer la tabla se abrian y cerraban
    // todas las burbujas de golpe y era imposible leer nada.
    //
    // Solo con raton: el navegador del telefono simula un mouseover en cada toque,
    // y ese timer reabriría la burbuja de una tarjeta que el toque acaba de cerrar.
    var hdPreviaTimer = null;

    document.addEventListener('mouseover', function (e) {
        if (hdSinHover.matches) return;
        var row = e.target.closest('.hd-has-cambios');
        if (!row) return;
        if (row.classList.contains('hd-detail-open')) return;
        clearTimeout(hdPreviaTimer);
        hdPreviaTimer = setTimeout(function () {
            hdCerrarTodo();
            hdAbrir(row);
        }, 180);
    });

    document.addEventListener('mouseout', function (e) {
        if (hdSinHover.matches) return;
        var row = e.target.closest('.hd-has-cambios');
        if (!row) return;
        // Moverse DENTRO de la misma fila (o hacia la propia burbuja) no cuenta
        // como salir: si no, leerla era imposible porque se cerraba sola.
        if (e.relatedTarget && row.contains(e.relatedTarget)) return;
        clearTimeout(hdPreviaTimer);
        hdPreviaTimer = setTimeout(function () {
            if (!row.matches(':hover')) hdCerrarTodo();
        }, 180);
    });
}
</script>

@endif
@endsection
