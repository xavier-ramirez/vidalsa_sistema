{{-- ────────────────────────────────────────────────────────────────
     Campos de la NOTA DE ENTREGA de una salida: Proyecto, Almacén destino,
     Fecha, RQ N°, Solicitante, Departamento, Observaciones y Chofer/Vehículo.
     Un solo formulario para las dos pantallas que despachan con nota:
       · /admin/almacen → modal "Registrar salida" (index.blade.php)
       · Recepción de materiales → modal "Registrar y despachar" (recepcion/partials/despacho_modales,
         en la Entrada por ODC y en el menú Acciones de la bandeja)
     Mismos ids en las dos: los maneja public/js/maquinaria/almacen_salida_nota.js y los
     estilos están en css/vistas/admin_almacen_salida_nota.css. Necesita $frentesLista
     (App\Support\DatosNotaSalida::frentes()).
     ──────────────────────────────────────────────────────────────── --}}
{{-- Cabecera tipo "Nota de Entrega de Materiales" ─────────────────────────────
     Layout inspirado en el Excel VID-FO-GEN-019, optimizado para ocupar menos
     alto vertical:
       PROYECTO                                          (full)
       FECHA DE ENTREGA | RQ N° | Solicitante            (3 columnas)
       DEPARTAMENTO                                      (full)
       OBSERVACIONES                                     (full)
       CHOFER | CÉDULA  y  VEHÍCULO | PLACA              (2 columnas)
     Entre la 1ª y la 2ª fila se intercala ALMACÉN DESTINO, que solo aparece cuando
     hace falta (ver su bloque): no es parte de la hoja del Excel, sino de la
     decisión de a dónde va el material.
     RQ N° se oculta cuando el almacén de origen emite la nota en formato
     HORIZONTAL (esa hoja no lo imprime): la fila queda en FECHA | Solicitante.
     Lo hace almSalidaAplicarFormatoNota()
     (almacen_salida_nota.js) al abrir el modal, que es el único sitio que conoce esa
     diferencia. --}}
<div id="almSalidaNotaWrap" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;margin-bottom:14px;">
    {{-- NOTA: el título "Nota de Entrega de Materiales" se incluye SOLO en el PDF
         generado por NotaEntregaPDF. En el modal es ruido visual — el título
         del modal ("Registrar salida" / "Registrar y despachar") ya identifica el formulario.
         La metadata del formulario (CÓDIGO, FECHA EMIS, REV) ya no sale en ningún
         lado: describía la plantilla, no la entrega. Ver NotaEntregaPDF::Header. --}}

    {{-- PROYECTO. El Contrato N° no se pide: la Nota lleva el del proyecto (CONTRATOS del
         frente, ver DatosNotaSalida::contratoDe). --}}
    <div style="margin-bottom:10px;">
        {{-- El for= apunta al input VISIBLE (data-filter-search) en vez de al
             hidden #almSalidaProyecto: Chrome marca como inválido un <label for=>
             que rotula un <input type="hidden"> (no es focuseable ni autofillable),
             y rompe la asociación a11y. El hidden mantiene su id porque el JS lo
             lee con el('almSalidaProyecto').value para enviar el ID del frente. --}}
        <label class="alm-nota-label" for="almSalidaProyectoSearch">Proyecto</label>
        {{-- Custom-dropdown estándar de la app: hidden #almSalidaProyecto guarda el ID
             (lo que lee el JS de envío); el trigger tiene un input data-filter-search
             que filtra los items mientras el usuario escribe (autocomplete nativo del
             componente). Cuando se elige una opción, dispatchea el evento
             `dropdown-selection` que el listener de almSalida usa para decidir el
             almacén destino. --}}
        <div class="custom-dropdown" id="almSalidaProyectoDropdown" data-default-label="Selecciona un frente de trabajo">
            <input type="hidden" id="almSalidaProyecto" data-filter-value value="">
            <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:38px;">
                <input type="text" id="almSalidaProyectoSearch" data-filter-search autocomplete="off"
                       placeholder="Selecciona un frente de trabajo"
                       style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;color:#0f172a;outline:none;min-width:0;"
                       oninput="window.filterDropdownOptions(this)">
                {{-- Este trigger NO lleva caret: la ✕ es su único icono. La lista se abre
                     igual, porque el handler global de uicomponents.js delega el clic en
                     .dropdown-trigger, no en el icono. --}}
                <i class="material-icons" data-clear-btn style="padding:0 12px;color:#64748b;font-size:18px;display:none;cursor:pointer;"
                   onclick="event.stopPropagation(); clearDropdownFilter('almSalidaProyectoDropdown');">close</i>
            </div>
            <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                <div class="dropdown-item-list" style="max-height:240px;overflow-y:auto;">
                    @foreach(($frentesLista ?? collect()) as $f)
                        <div class="dropdown-item" data-value="{{ $f->ID_FRENTE }}"
                             onclick="selectOption('almSalidaProyectoDropdown','{{ $f->ID_FRENTE }}','{{ addslashes($f->NOMBRE_FRENTE) }}');">
                            {{ $f->NOMBRE_FRENTE }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ALMACÉN DESTINO — solo aparece cuando el proyecto elegido está asignado a
         MÁS DE UN almacén, que es cuando el destino no se puede deducir. Con uno
         solo queda oculto y lo deduce el backend: preguntar lo obvio sería un clic
         de más en casi todas las salidas.
         Lo llena almSalidaSyncAlmacenDestino() al elegir proyecto. --}}
    <div id="almSalidaDestinoWrap" style="display:none;margin-bottom:10px;">
        <label class="alm-nota-label" for="almSalidaAlmacenDestinoSearch">Almacén destino *</label>
        <div class="custom-dropdown" id="almSalidaAlmacenDestinoDropdown" data-default-label="Selecciona uno">
            <input type="hidden" id="almSalidaAlmacenDestino" data-filter-value value="">
            <div class="dropdown-trigger" style="padding:0;display:flex;align-items:center;background:#fff;overflow:hidden;border:1px solid #cbd5e0;border-radius:7px;height:38px;">
                <input type="text" id="almSalidaAlmacenDestinoSearch" data-filter-search autocomplete="off"
                       placeholder="Selecciona uno"
                       style="flex:1;border:none;background:transparent;padding:0 10px;font-size:13.5px;color:#0f172a;outline:none;min-width:0;"
                       oninput="window.filterDropdownOptions(this)">
                <i class="material-icons" style="padding:0 8px;color:#94a3b8;font-size:20px;">expand_more</i>
            </div>
            <div class="dropdown-content" style="padding:5px;max-height:none;overflow:visible;">
                <div class="dropdown-item-list" id="almSalidaAlmacenDestinoItems" style="max-height:240px;overflow-y:auto;"></div>
            </div>
        </div>
        <div class="alm-hint">
            Este proyecto se maneja en varios almacenes: indica a cuál se envía el material.
        </div>
    </div>

    {{-- FECHA DE ENTREGA | RQ N° | Solicitante (3 columnas en una sola fila — como en el Excel) --}}
    <div id="almSalidaGridDatos" class="alm-modal-grid alm-modal-grid-3" style="display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:10px;margin-bottom:10px;">
        <div>
            <label class="alm-nota-label" for="almSalidaFecha">Fecha de entrega</label>
            {{-- Wrapper clickable: cualquier click en el campo abre el calendario
                 (en navegadores que soportan showPicker). YA NO incluye un icono
                 custom (event) porque el <input type="date"> nativo de Chrome/Edge
                 pinta su propio indicador de calendario a la derecha — antes se veian
                 DOS calendarios (custom izq + nativo der). Dejamos solo el nativo. --}}
            <div style="display:flex;align-items:center;background:#fff;border:1px solid #cbd5e0;border-radius:7px;height:38px;overflow:hidden;cursor:pointer;"
                 onclick="var i=document.getElementById('almSalidaFecha'); if(i){ i.focus(); if(i.showPicker){ try{ i.showPicker(); }catch(e){} } }">
                <input type="date" id="almSalidaFecha" class="alm-nota-input" style="flex:1;width:auto;min-width:0;border:none;background:transparent;height:36px;padding:0 10px;border-radius:0;">
            </div>
        </div>
        <div id="almSalidaRqWrap">
            <label class="alm-nota-label" for="almSalidaRq">RQ N°</label>
            <input type="text" id="almSalidaRq" class="alm-nota-input" maxlength="100" placeholder="Ej: RQ-001" autocomplete="off">
        </div>
        <div>
            <label class="alm-nota-label" for="almSalidaSolicitante">Solicitante</label>
            <input type="text" id="almSalidaSolicitante" class="alm-nota-input" maxlength="200" placeholder="Nombre y apellido" autocomplete="off">
        </div>
    </div>

    {{-- DEPARTAMENTO (full width) --}}
    <div style="margin-bottom:10px;">
        <label class="alm-nota-label" for="almSalidaDepartamento">Departamento</label>
        {{-- list: sugiere los departamentos que ESTE usuario ya usó (ver
             almDeptoRecordar). autocomplete="off" sigue puesto para que el
             navegador no meta además su propio historial. --}}
        <input type="text" id="almSalidaDepartamento" class="alm-nota-input" maxlength="150"
               placeholder="Ej: Mantenimiento" autocomplete="off" list="almSalidaDeptoLista">
        <datalist id="almSalidaDeptoLista"></datalist>
    </div>

    {{-- OBSERVACIONES (full width), debajo de Departamento (pedido del cliente, 23-11-2026) —
         campo libre de la Nota de Entrega (mapea a MOTIVO en BD). Se envía siempre en el
         flujo unificado: tanto en SALIDA pura (consumo) como en SALIDA vía traspaso a otro
         proyecto. --}}
    <div style="margin-bottom:10px;">
        <label class="alm-nota-label" for="almSalidaMotivo">Observaciones</label>
        <input type="text" id="almSalidaMotivo" class="alm-nota-input" maxlength="200" placeholder="Ej: entrega parcial, urgente, etc." autocomplete="off">
    </div>

    {{-- TRANSPORTE (primero el chofer, luego el vehículo) — lo imprime el bloque "Datos del
         vehículo / Datos del chofer" de la nota, que antes salía en blanco. Opcional: lo que quede vacío sale en blanco
         para llenarlo a mano. Sugiere la logística del almacén y la flota de sus frentes
         (almLogCargar): el vehículo se busca ESCRIBIENDO su placa o su serial de chasis
         y elegir uno llena los dos campos de su fila. Los dos comparten la lista, que
         sale debajo del campo que se está escribiendo (almLogSugerir). --}}
    <div class="alm-modal-grid alm-modal-grid-2" style="display:grid;grid-template-columns:1.6fr 1fr;gap:10px;margin-bottom:10px;">
        <div>
            <label class="alm-nota-label" for="almSalidaChofer">Chofer</label>
            <input type="text" id="almSalidaChofer" class="alm-nota-input" maxlength="150" placeholder="Nombre y apellido" autocomplete="off"
                   data-log="choferes" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
        </div>
        <div>
            <label class="alm-nota-label" for="almSalidaCedula">Cédula del chofer</label>
            <input type="text" id="almSalidaCedula" class="alm-nota-input" maxlength="30" placeholder="Ej: 17.902.185" autocomplete="off"
                   data-log="choferes" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
        </div>
        <div class="alm-suggest-inline alm-suggest-float" id="almSalidaChoferesSug"></div>
    </div>
    <div class="alm-modal-grid alm-modal-grid-2" style="display:grid;grid-template-columns:1.6fr 1fr;gap:10px;">
        <div>
            <label class="alm-nota-label" for="almSalidaVehiculo">Vehículo</label>
            <input type="text" id="almSalidaVehiculo" class="alm-nota-input" maxlength="150" placeholder="Ej: Camioneta Toyota Hilux" autocomplete="off"
                   data-log="vehiculos" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
        </div>
        <div>
            <label class="alm-nota-label" for="almSalidaPlaca">Placa</label>
            <input type="text" id="almSalidaPlaca" class="alm-nota-input" maxlength="30" placeholder="Placa o serial de chasis" autocomplete="off"
                   data-log="vehiculos" oninput="window.almLogSugerir(this)" onfocus="window.almLogSugerir(this, true)">
        </div>
        <div class="alm-suggest-inline alm-suggest-float" id="almSalidaVehiculosSug"></div>
    </div>

</div>
