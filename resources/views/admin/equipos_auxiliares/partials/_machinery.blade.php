{{-- ═══════════════════════════════════════════════════════════
     BARRA FLOTANTE DE SELECCION MASIVA (estilo /admin/equipos)
     SIEMPRE visible — los botones validan permiso en JS y muestran
     toast si el usuario no tiene equipos.assign (mismo patron que
     /admin/equipos con CAN_ASSIGN_EQUIPOS).
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxBulkBar" class="selection-floating-bar">
    <div class="selection-counter" onclick="window.toggleAuxSoloSel(event)" title="Ver solo los seleccionados (toca de nuevo para ver todos)" style="cursor: pointer;">
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
        {{-- "Anclar" (anclar un auxiliar a un equipo host): SOLO en el modulo standalone
             de auxiliares. En la vista embebida de /admin/equipos no se ofrece anclaje para
             auxiliares (solo Asignar/movilizar y Detalle). Se gatea con $embeddedInEquipos. --}}
        @if(!($embeddedInEquipos ?? false))
        <button type="button" onclick="window.openAuxAnclarBulkModal()" class="btn-bulk-action" style="background: #10b981;">
            <i class="material-icons" style="font-size: 18px;">anchor</i>
            <span class="desktop-text">Anclar</span>
        </button>
        @endif
        <button type="button" onclick="window.openAuxUbicacionBulkModal()" class="btn-bulk-action" style="background: #64748b;">
            <i class="material-icons" style="font-size: 18px;">description</i>
            <span class="desktop-text">Detalle</span>
        </button>
        <button type="button" onclick="window.openAuxMovilizarModal()" class="btn-bulk-action">
            <i class="material-icons" style="font-size: 18px;">local_shipping</i>
            <span class="desktop-text">Asignar</span>
        </button>
    </div>
</div>

{{-- Globals de permiso para los handlers JS de la barra bulk. Igual al
     patron de /admin/equipos (window.CAN_ASSIGN_EQUIPOS). --}}
<script>
    window.CAN_ASSIGN_AUX = {{ auth()->user() && (auth()->user()->can('equipos.assign') || auth()->user()->can('super.admin')) ? 'true' : 'false' }};
    window.CAN_EDIT_AUX   = {{ auth()->user() && (auth()->user()->can('equipos.edit')   || auth()->user()->can('super.admin')) ? 'true' : 'false' }};
</script>

{{-- ═══════════════════════════════════════════════════════════
     MODAL MOVILIZACION MASIVA (pick frente destino)
     Mismo patron visual que /admin/equipos: header centrado con icono
     azul, chips de equipos seleccionados, dropdown con buscador para
     el frente destino, un solo boton "Confirmar Movilización" full-width.
     ═══════════════════════════════════════════════════════════ --}}
<div id="auxMovilizarModal" class="modal-overlay"
     onclick="if(event.target===this) window.closeAuxMovilizarModal()">
    <div class="modal-content"
         style="width: 90%; max-width: 480px; max-height: 92vh; padding: 0; border-radius: 16px; overflow: visible; background: white; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.30); display: flex; flex-direction: column;">

        {{-- Header centrado: icono + titulo en el centro, close absoluto a la derecha.
             border-radius en el header (no via overflow del modal-content) porque
             el modal usa overflow:visible para que el dropdown del frente no se
             recorte — sin border-radius aqui las esquinas superiores quedaban cuadradas. --}}
        <div style="background:#1e293b; padding:18px; color:white; display:flex; justify-content:center; align-items:center; position:relative; border-radius:16px 16px 0 0;">
            <div style="display:flex; align-items:center; gap:10px;">
                <i class="material-icons" style="color:#0067b1; font-size:20px;">local_shipping</i>
                <h2 style="margin:0; font-size:16px; font-weight:700;">Movilización</h2>
            </div>
            <button type="button" onclick="window.closeAuxMovilizarModal()" aria-label="Cerrar"
                    style="position:absolute; right:15px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;"
                    onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                <i class="material-icons">close</i>
            </button>
        </div>

        {{-- Body. overflow:visible para que el dropdown del autocomplete del
             frente no se recorte cuando se extiende debajo del modal. El
             scroll vertical se aplica solo al modal-content via max-height. --}}
        <div style="padding:22px 24px; display:flex; flex-direction:column; gap:18px; overflow:visible; flex:1; border-radius:0 0 16px 16px;">

            {{-- Chips de auxiliares seleccionados (poblados por JS) --}}
            <div>
                <p style="margin:0 0 8px; font-size:12px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Auxiliares a movilizar</p>
                <div id="auxMovilizarChips" style="display:flex; flex-wrap:wrap; gap:6px; padding:10px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; max-height:120px; overflow-y:auto;">
                    {{-- poblado via JS --}}
                </div>
            </div>

            {{-- Frente destino con buscador (replica el bloque de /admin/equipos):
                 permite escribir un frente NUEVO si no esta en la lista, y si el
                 frente seleccionado no tiene UBICACION cargada, muestra el campo
                 de ubicacion para que el usuario lo capture en el momento. --}}
            <div>
                <label style="display:block; font-size:13px; font-weight:700; color:#475569; margin-bottom:8px;">
                    <i class="material-icons" style="font-size:14px; vertical-align:middle; margin-right:4px;">place</i>
                    Frente de Destino <span style="color:#ef4444;">*</span>
                </label>
                <div style="position:relative;">
                    <div style="display:flex; align-items:center; border:2px solid #e2e8f0; border-radius:10px; background:white; overflow:hidden; transition:border-color 0.2s;" id="auxMovilizarBox">
                        <i class="material-icons" style="padding:0 10px; color:#94a3b8; font-size:20px; flex-shrink:0;">search</i>
                        <input type="text" id="auxMovilizarSearch"
                               placeholder="Buscar o escribir frente de destino..."
                               autocomplete="off"
                               oninput="auxMovOnInput(this.value)"
                               onfocus="auxMovOpenList()"
                               onclick="auxMovOpenList()"
                               onblur="setTimeout(auxMovCloseList, 200)"
                               style="flex:1; border:none; outline:none; padding:11px 6px; font-size:14px; background:transparent; text-transform:uppercase;">
                        <i class="material-icons" id="auxMovilizarClear"
                           onclick="auxMovClear()"
                           style="padding:0 10px; color:#94a3b8; font-size:18px; cursor:pointer; display:none;">close</i>
                    </div>
                    <input type="hidden" id="auxMovilizarFrente" value="">
                    <div id="auxMovilizarList"
                         style="position:absolute; top:calc(100% + 4px); left:0; right:0; background:white; border:1px solid #cbd5e0; border-radius:10px; box-shadow:0 4px 12px rgba(15,23,42,0.10); max-height:240px; overflow-y:auto; z-index:50; display:none;">
                        @foreach($frentes as $f)
                            <div class="aux-mov-opt"
                                 data-id="{{ $f->ID_FRENTE }}"
                                 data-label="{{ mb_strtoupper($f->NOMBRE_FRENTE) }}"
                                 data-ubicacion="{{ trim((string) ($f->UBICACION ?? '')) }}"
                                 onmousedown="event.preventDefault(); auxMovSelect({{ $f->ID_FRENTE }}, '{{ addslashes(mb_strtoupper($f->NOMBRE_FRENTE)) }}');"
                                 style="padding:10px 14px; font-size:13px; color:#334155; cursor:pointer; border-bottom:1px solid #f1f5f9;"
                                 onmouseover="this.style.background='#f1f5f9'"
                                 onmouseout="this.style.background='white'">
                                {{ mb_strtoupper($f->NOMBRE_FRENTE) }}
                            </div>
                        @endforeach
                    </div>
                </div>

            </div>

            {{-- Generar Informe — checkbox opcional. Cuando esta activo, el
                 backend asigna CODIGO_CONTROL y registra DESPACHO con fecha. --}}
            <div style="display:flex; align-items:center; gap:8px; padding:10px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                <input type="checkbox" id="auxMovilizarGenerarPdf" style="width:16px; height:16px; cursor:pointer; accent-color:#1e293b;">
                <label for="auxMovilizarGenerarPdf" style="font-size:13px; font-weight:600; color:#475569; cursor:pointer; user-select:none; margin:0;">
                    Generar Informe (Acta de Asignación)
                </label>
            </div>

            {{-- Boton unico full-width: "Confirmar Movilización" --}}
            <button type="button" onclick="window.auxSubmitMovilizar()" id="auxMovilizarSubmitBtn"
                    style="width:100%; height:48px; border-radius:10px; font-weight:700; font-size:15px; background:#1e293b; color:white; border:none; display:flex; align-items:center; justify-content:center; gap:10px; cursor:pointer; transition:background 0.2s;"
                    onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">
                <i class="material-icons" style="font-size:18px;">send</i> Confirmar Movilización
            </button>
        </div>
    </div>
</div>

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
                    <h2 id="auxDetailsTitle" style="margin: 0; font-size: 14px; font-weight: 700; word-break: break-word; line-height: 1.2;">—</h2>
                    <p id="auxDetailsSubtitle" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px; word-break: break-word;">—</p>
                </div>
                <div style="display: flex; gap: 6px; flex-shrink: 0;">
                    @can('equipos.edit')
                        <button type="button" id="btn_confirmar_sitio_aux_modal" data-aux-id="" data-confirmado="0"
                            onclick="window.toggleConfirmacionSitioAux(this)" title="Confirmar presencia en sitio"
                            style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                            onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                            onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                            <i class="material-icons" style="font-size: 18px;">radio_button_unchecked</i>
                        </button>
                    @endcan
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

{{-- Los modales de Reporte de Falla (create_modal/close_modal, compartidos) los
     incluye la PAGINA contenedora (equipos-auxiliares o equipos), NO este partial:
     asi no se duplican cuando la maquinaria se embebe en /admin/equipos (esa pagina
     ya incluye esos modales por su cuenta). --}}

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
            window.bloquearScrollFondo();
            return;
        }

        // Fallback de seguridad: si por alguna razon el ID no esta en el map
        // (cache stale, navegacion SPA con datos parciales), hace fetch con
        // preloader global y SIN spinner interno en el modal.
        if (typeof window.showPreloader === 'function') window.showPreloader();
        window.apiFetch('/admin/equipos-auxiliares/' + id + '/details', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(d => {
            (window.auxDetailsMap = window.auxDetailsMap || {})[id] = d;
            window.renderAuxDetailsModal(d);
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            modal.style.display = '';
            modal.classList.add('active');
            window.bloquearScrollFondo();
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
        if (sub)   sub.textContent   = d.serial ? 'Serial: ' + d.serial : ((d.marca || '') + ' ' + (d.modelo || '')).trim() || '—';

        // Botón "confirmar en sitio" del header: sincroniza id + estado
        const confBtn = document.getElementById('btn_confirmar_sitio_aux_modal');
        if (confBtn) {
            const conf = String(d.confirmado_en_sitio) === '1';
            confBtn.dataset.auxId = d.id;
            confBtn.dataset.confirmado = conf ? '1' : '0';
            const ci = confBtn.querySelector('.material-icons');
            if (ci) ci.textContent = conf ? 'check_circle' : 'radio_button_unchecked';
            confBtn.style.color = conf ? '#4ade80' : 'white';
            confBtn.title = conf ? 'Confirmado en sitio (click para quitar)' : 'Confirmar presencia en sitio';
        }

        // Enlazar edit en el boton del header (usa SPA navigateTo si esta disponible).
        // Pasa la URL actual como ?ref= para que el formulario de edición sepa
        // a dónde volver (fix: desde /admin/equipos redirigía a /admin/equipos-auxiliares).
        const editBtn = document.getElementById('auxDetailsEditBtn');
        if (editBtn) editBtn.onclick = () => {
            window.closeAuxDetailsModal();
            const editUrl = d.edit_url + (d.edit_url.includes('?') ? '&' : '?') + 'ref=' + encodeURIComponent(window.location.href);
            if (typeof window.navigateTo === 'function') {
                window.navigateTo(editUrl);
            } else {
                // Fallback: click en un <a> para que el interceptor SPA global lo tome
                const a = document.createElement('a');
                a.href = editUrl;
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
                const labelHr = docType === 'propiedad' ? 'Doc. Propiedad' : 'Certificado';
                const safeUrl = url.replace(/'/g, "\\'");
                const uploadUrl = '/admin/equipos-auxiliares/' + d.id + '/upload-doc';
                const onclickHandler = `window.openPdfPreview('${safeUrl}','${docType}','${labelHr}',${d.id},'${uploadUrl}',false,'auxiliar');`;
                return `<button class="pdf-doc-btn" type="button" title="Ver PDF" onclick="${onclickHandler}" ><i class="material-icons">description</i></button>`;
            }
            if (d.can_upload_pdf && docType) {
                // Usamos div+onclick en lugar de label para evitar que Materialize CSS
                // sobreescriba display a block dentro del contenedor flex.
                const uid = 'auxUp_' + d.id + '_' + docType;
                return `<div title="Subir PDF" style="display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:6px; background:#fbfcfd; border:1px dashed #3b82f6; color:#3b82f6; cursor:pointer; flex-shrink:0;" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='#fbfcfd'" onclick="document.getElementById('${uid}').click()"><i class="material-icons" style="font-size:18px;">cloud_upload</i><input type="file" id="${uid}" accept="application/pdf" style="display:none;" onchange="window.auxUploadDoc(${d.id}, '${docType}', this)"></div>`;
            }
            return '<span style="color:#94a3b8; font-size:12px;">No cargado</span>';
        };

        // Fila Doc. Propiedad: label + boton (sin fecha).
        // Usa detail-row-doc (NO detail-row-basic): en móvil la regla global
        // fuerza detail-row-basic a flex-direction:column, apilando el icono
        // debajo de la etiqueta. detail-row-doc mantiene nowrap horizontal.
        const rowPropiedad = `
            <div class="detail-row-doc" style="display:flex !important; align-items:center !important; justify-content:space-between !important; gap:6px; padding:6px 0; border-bottom:1px dashed #f1f5f9; flex-wrap:nowrap; min-width:0; width:100%;">
                <span style="color:#64748b; font-size:12px; white-space:nowrap; flex-shrink:0;">Doc. Propiedad</span>
                <div style="display:flex !important; align-items:center; justify-content:flex-end; flex-shrink:0;">
                    ${pdfBtn(d.link_doc_propiedad, 'propiedad')}
                </div>
            </div>`;

        // Fila Certificado: label + fecha de vencimiento + boton (todo en linea)
        //
        // La fecha va como en el detalle de EQUIPOS: texto plano en #333 y dd/mm/aaaa.
        // Antes era una etiqueta de color —verde si faltaba mucho, ambar por debajo de 30
        // dias, roja y con "(VENCIDO)" si ya paso—, y era el UNICO sitio del sistema que
        // pintaba asi una fecha: en el detalle de equipos, poliza, ROTC, RACDA y
        // certificado salen sin colorear, y las tablas tampoco colorean. Quien avisa de
        // los vencimientos es el panel de alertas del tablero, no esta ficha.
        let fechaInline = '<span style="color:#94a3b8; font-size:12px;">Sin fecha</span>';
        if (d.fecha_vencimiento_cert) {
            // Mismo formateo que la ficha de equipos, y literalmente la misma funcion:
            // window.formatearFecha, en dom_helpers.js.
            const txt = window.formatearFecha(d.fecha_vencimiento_cert);
            // Sin title: repetiria palabra por palabra lo que ya se lee, y el de equipos
            // tampoco lo lleva. Lo tenia la version de antes porque la etiqueta de color
            // podia recortar el texto con puntos suspensivos.
            fechaInline = `<span style="color:#333; font-size:13px; white-space:nowrap;">${txt}</span>`;
        }
        const rowCertificado = `
            <div class="detail-row-doc" style="display:flex !important; align-items:center !important; justify-content:space-between !important; gap:6px; padding:6px 0; border-bottom:1px dashed #f1f5f9; flex-wrap:nowrap; min-width:0; width:100%;">
                <span style="color:#64748b; font-size:12px; white-space:nowrap; flex-shrink:0;">Certificado</span>
                <div style="display:flex !important; align-items:center; gap:6px; flex-shrink:1; min-width:0; overflow:hidden;">
                    <div style="flex-shrink:1; min-width:0; overflow:hidden;">${fechaInline}</div>
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
            // Fila de documento del equipo HOST — mismo estilo que los docs propios del aux
            // (nombre en gris + botón-ícono para ver el PDF, o "No cargado"). Solo-lectura
            // (equipoId=0 + skipMetadata=true → sin gestión desde aquí). Link crudo.
            const hostDocRow = (label, link, docType) => `
                <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;padding:3px 0;border-bottom:1px dashed #f1f5f9;">
                    <span style="color:#64748b;font-size:12px;">${label}</span>
                    ${link
                        ? `<button class="pdf-doc-btn" type="button" title="Ver PDF" onclick="event.stopPropagation(); window.openPdfPreview('${link}','${docType}','${label}',0,'',true);" ><i class="material-icons">description</i></button>`
                        : `<span style="color:#94a3b8;font-size:12px;">No cargado</span>`}
                </div>`;
            // Tarjeta desplegable: la fila (summary) + los DOCUMENTOS del equipo host (los
            // seriales ya van en el summary/tabla, no se repiten).
            hostCard = `
                <details style="background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;">
                    <summary style="display:flex;align-items:center;gap:10px;padding:8px 10px;cursor:pointer;list-style:none;">
                        ${fotoThumb}
                        <div style="display:flex;flex-direction:column;flex:1;min-width:0;gap:2px;">
                            {{-- Tipo·marca y serial en NEGRO (antes #94a3b8 y #1e293b): son los
                                 datos que identifican al equipo vinculado y en gris se perdían
                                 sobre el fondo #f8fafc de la tarjeta. Mismo criterio que la
                                 tarjeta gemela del modal de Equipos (uicomponents.js). --}}
                            <span style="font-size:10px;font-weight:700;color:#000;text-transform:uppercase;letter-spacing:0.4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${tipoMarcaLine}</span>
                            <span style="font-size:11px;font-weight:700;color:#000;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25;">${idPrincipal}</span>
                            ${frente ? `<span style="font-size:11px;color:#059669;font-weight:600;display:inline-flex;align-items:center;gap:3px;margin-top:1px;"><i class="material-icons" style="font-size:13px;">place</i>${frente}</span>` : `<span style="font-size:11px;color:#94a3b8;font-style:italic;display:inline-flex;align-items:center;gap:3px;margin-top:1px;"><i class="material-icons" style="font-size:13px;">location_off</i>Sin frente</span>`}
                        </div>
                        <i class="material-icons" style="font-size:18px;color:#94a3b8;flex-shrink:0;" title="Ver documentos del equipo">expand_more</i>
                    </summary>
                    <div style="padding:1px 10px 4px;border-top:1px dashed #e2e8f0;background:#fff;">
                        ${hostDocRow('Propiedad', d.host_link_propiedad, 'propiedad')}
                        ${hostDocRow('Póliza', d.host_link_seguro, 'poliza')}
                        ${hostDocRow('ROTC', d.host_link_rotc, 'rotc')}
                        ${hostDocRow('RACDA', d.host_link_racda, 'racda')}
                    </div>
                </details>`;
        } else {
            hostCard = '<div style="text-align:center;padding:12px;color:#94a3b8;font-size:12px;font-style:italic;">Sin equipo vinculado.</div>';
        }

        // IMPORTANTE: solo campos NO presentes en la tabla del index.
        // En la tabla ya se ven: frente, foto, tipo, marca/modelo, serial, capacidad, estado.
        // Aqui mostramos, en este orden: documentacion (propiedad + certificado + vencimiento),
        // el equipo vinculado (host) y datos adicionales (codigo interno, año, observaciones).
        const body = document.getElementById('auxDetailsBody');
        body.innerHTML = `
            ${section('Documentación Legal', 'description',
                rowPropiedad + rowCertificado
            )}

            ${section('Vinculación', 'link', hostCard)}

            ${section('Combustible y Consumo', 'local_gas_station',
                row('Tipo de Combustible',  d.combustible) +
                row('Consumo Promedio',     d.consumo ? d.consumo + ' L/día' : null)
            )}

            ${section('Información Adicional', 'info',
                row('Código Interno',       d.codigo_interno ? '#' + d.codigo_interno : '—') +
                row('Año',                  d.anio) +
                row('Observaciones',        d.observaciones)
            )}
        `;
    };

    window.closeAuxDetailsModal = function () {
        const modal = document.getElementById('auxDetailsModal');
        if (modal) {
            modal.classList.remove('active');
            modal.style.display = '';
        }
        // Libera html + body salvo que quede el visor de PDF abierto (restaurarScrollFondo).
        window.restaurarScrollFondo();
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

@php
    // Lo que el JavaScript del modulo necesita de ESTA apertura. El codigo esta en
    // public/js/maquinaria/maquinaria_listado.js, que el navegador cachea.
    $maq_cfg = [
        'nextOffset' => (int) ($nextOffset ?? 0),
        'hasMore' => !empty($hasMore),
        'rutaEquiposAuxiliaresIndex' => route("equipos-auxiliares.index"),
        'rutaEquiposAuxiliaresIndex2' => route("equipos-auxiliares.index"),
        'tipos' => $tipos,
        'rutaEquiposAuxiliaresBulkMove' => route("equipos-auxiliares.bulkMove"),
        'rutaEquiposAuxiliaresExport' => route("equipos-auxiliares.export"),
        'rutaEquiposAuxiliaresSearchHosts' => route("equipos-auxiliares.searchHosts"),
        'rutaEquiposAuxiliaresExportAnclajes' => route("equipos-auxiliares.exportAnclajes"),
        'rutaEquiposAuxiliaresAnchoredList' => route("equipos-auxiliares.anchoredList"),
    ];
@endphp
<script>
    window.MAQ_CFG = @json($maq_cfg);
</script>
<script src="{{ asset('js/maquinaria/maquinaria_listado.js') }}?v={{ @filemtime(public_path('js/maquinaria/maquinaria_listado.js')) }}"></script>
<script>
    // El archivo de arriba se carga UNA vez en toda la sesion; esta llamada es la que
    // monta la pantalla, y corre en cada apertura del modulo (tambien al volver por la
    // navegacion interna, que es cuando el <script src> ya no se re-ejecuta).
    window.maquinariaArrancar(window.MAQ_CFG);
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

{{-- Bulk delete de auxiliares (soft-delete con auditoria). Lee el set de
     IDs seleccionados desde window._auxSelectedMap (que el row-click ya
     mantiene actualizado). Reusa el preloader/showToast/showModal globales.
     El boton es siempre visible; el permiso (user.delete) se valida en JS
     y tambien en el middleware can:user.delete de la ruta. --}}
<script>
    // user.delete es EXCLUSIVA (Usuario::PERMISOS_EXPLICITOS): ni super.admin
    // la hereda. Por eso aqui se pregunta SOLO la clave literal — el `|| super.admin`
    // que tenia antes ya no tiene sentido (creaba botones que el server rechazaba).
    window.CAN_DELETE_AUX = {{ auth()->user() && auth()->user()->can('user.delete') ? 'true' : 'false' }};
</script>
<script>
window.bulkDeleteAuxiliaresSeleccionados = function () {
    // Permiso: el boton es siempre visible; sin la clave literal user.delete
    // (en PERMISOS_EXPLICITOS — ni super.admin la hereda) → toast moderno.
    if (window.CAN_DELETE_AUX === false || window.CAN_DELETE_AUX === 'false') {
        if (typeof window.showToast === 'function') {
            window.showToast('No tienes permiso para eliminar auxiliares.', 'error');
        } else {
            alert('No tienes permiso para eliminar auxiliares.');
        }
        return;
    }
    var ids = Object.keys(window._auxSelectedMap || {}).map(function (x) { return parseInt(x, 10); });
    if (!ids.length) {
        if (window.showToast) window.showToast('Por favor, selecciona al menos un auxiliar en la tabla antes de eliminar.', 'warning');
        else alert('Por favor, selecciona al menos un auxiliar en la tabla antes de eliminar.');
        return;
    }
    var proceed = function () {
        if (window.showPreloader) window.showPreloader();
        var csrf = window.getCsrf();   // helper central (dom_helpers.js)
        window.apiFetch('{{ route("equipos-auxiliares.bulkDelete") }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ ids: ids })
        })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) {
                window.toast(res.body.message || 'Auxiliares eliminados.', 'success');
                if (typeof window.auxClearSelection === 'function') window.auxClearSelection();
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
            } else {
                window.toast((res.body && res.body.message) || 'No se pudo eliminar.', 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            window.toast('Error de red al eliminar.', 'error');
        });
    };
    if (typeof window.showModal === 'function') {
        window.showModal({
            type: 'warning',
            title: 'Eliminar Auxiliares',
            message: '¿Eliminar ' + ids.length + ' auxiliar(es) seleccionado(s)?',
            confirmText: 'Eliminar',
            cancelText: 'Cancelar',
            onConfirm: proceed
        });
    } else if (confirm('¿Eliminar ' + ids.length + ' auxiliar(es)?')) {
        proceed();
    }
};

window.toggleAuxAdv = function(event) {
    if(event) { event.preventDefault(); event.stopPropagation(); }
    var advPanel = document.getElementById('auxAdvPanel');
    var accDropdown = document.getElementById('auxAccionesDropdown');
    
    if (accDropdown && accDropdown.style.display === 'block') {
        accDropdown.style.display = 'none';
    }
    
    if (advPanel) {
        advPanel.style.display = (advPanel.style.display === 'none' || !advPanel.style.display) ? 'block' : 'none';
    }
};

window.toggleAuxAcciones = function(event) {
    if(event) { event.preventDefault(); event.stopPropagation(); }
    var advPanel = document.getElementById('auxAdvPanel');
    var accDropdown = document.getElementById('auxAccionesDropdown');
    
    if (advPanel && advPanel.style.display === 'block') {
        advPanel.style.display = 'none';
    }
    
    if (accDropdown) {
        accDropdown.style.display = (accDropdown.style.display === 'none' || !accDropdown.style.display) ? 'block' : 'none';
    }
};


</script>

