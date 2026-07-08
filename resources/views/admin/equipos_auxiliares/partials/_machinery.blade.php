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
            document.body.style.overflow = 'hidden';
            return;
        }

        // Fallback de seguridad: si por alguna razon el ID no esta en el map
        // (cache stale, navegacion SPA con datos parciales), hace fetch con
        // preloader global y SIN spinner interno en el modal.
        if (typeof window.showPreloader === 'function') window.showPreloader();
        fetch('/admin/equipos-auxiliares/' + id + '/details', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(d => {
            (window.auxDetailsMap = window.auxDetailsMap || {})[id] = d;
            window.renderAuxDetailsModal(d);
            if (typeof window.hidePreloader === 'function') window.hidePreloader();
            modal.style.display = '';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
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
                return `<button type="button" title="Ver PDF" onclick="${onclickHandler}" style="display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:7px; background:#0067b1; box-shadow:0 2px 6px rgba(0,103,177,0.35); border:none; cursor:pointer; flex-shrink:0;"><i class="material-icons" style="font-size:17px; color:white;">description</i></button>`;
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
        let fechaInline = '<span style="color:#94a3b8; font-size:12px;">Sin fecha</span>';
        if (d.fecha_vencimiento_cert) {
            const venc = new Date(d.fecha_vencimiento_cert);
            const hoy = new Date(); hoy.setHours(0,0,0,0);
            const diff = Math.floor((venc - hoy) / (1000*60*60*24));
            const txt  = d.fecha_vencimiento_cert;
            let bg='#f0fdf4', co='#16a34a', extra='';
            if (diff < 0)       { bg='#fef2f2'; co='#dc2626'; extra=' (VENCIDO)'; }
            else if (diff < 30) { bg='#fffbeb'; co='#d97706'; extra=' ('+diff+'d)'; }
            fechaInline = `<span style="background:${bg}; color:${co}; padding:2px 6px; border-radius:6px; font-weight:700; font-size:11px; white-space:nowrap; display:inline-block; max-width:100%; overflow:hidden; text-overflow:ellipsis;" title="${txt}${extra}">${txt}${extra}</span>`;
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
                        ? `<button type="button" title="Ver PDF" onclick="event.stopPropagation(); window.openPdfPreview('${link}','${docType}','${label}',0,'',true);" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:#0067b1;box-shadow:0 2px 6px rgba(0,103,177,0.35);border:none;cursor:pointer;flex-shrink:0;"><i class="material-icons" style="font-size:17px;color:white;">description</i></button>`
                        : `<span style="color:#94a3b8;font-size:12px;">No cargado</span>`}
                </div>`;
            // Tarjeta desplegable: la fila (summary) + los DOCUMENTOS del equipo host (los
            // seriales ya van en el summary/tabla, no se repiten).
            hostCard = `
                <details style="background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;">
                    <summary style="display:flex;align-items:center;gap:10px;padding:8px 10px;cursor:pointer;list-style:none;">
                        ${fotoThumb}
                        <div style="display:flex;flex-direction:column;flex:1;min-width:0;gap:2px;">
                            <span style="font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${tipoMarcaLine}</span>
                            <span style="font-size:11px;font-weight:700;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.25;">${idPrincipal}</span>
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
            ${section('Documentación Legal y Soportes', 'description',
                rowPropiedad + rowCertificado
            )}

            ${section('Vinculación', 'link', hostCard)}

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
    // Estado del scroll infinito — inicializado desde el SSR para que la primera
    // carga (sin AJAX) ya conozca cuántos hay y pueda disparar el observer.
    window._auxScrollState = {
        offset: {{ (int) ($nextOffset ?? 0) }},
        hasMore: {{ !empty($hasMore) ? 'true' : 'false' }},
        loading: false
    };

    // Carga AJAX — primer lote (replace) o lote siguiente (append) con scroll infinito.
    // opts.append=true → APPEND filas y mantener stats/distribucion.
    // opts.append=false → REPLACE tabla, resetear offset a 0 y refrescar todo.
    window.cargarAuxiliares = function (opts) {
        opts = opts || {};
        const append = opts.append === true;
        const form   = document.getElementById('auxFiltersForm');
        const params = new URLSearchParams(new FormData(form));

        if (append) {
            if (window._auxScrollState.loading || !window._auxScrollState.hasMore) return Promise.resolve();
            window._auxScrollState.loading = true;
            params.set('offset', String(window._auxScrollState.offset));
            const ld = document.getElementById('auxLoadingMore');
            if (ld) ld.style.display = '';
        } else {
            window._auxScrollState = { offset: 0, hasMore: false, loading: true };
            params.set('offset', '0');
            if (typeof window.showPreloader === 'function') window.showPreloader();
        }

        return fetch('{{ route("equipos-auxiliares.index") }}?' + params.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(data => {
            const tbody = document.getElementById('auxTableBody');
            if (append) {
                // Insertar nuevas filas al final del tbody
                tbody.insertAdjacentHTML('beforeend', data.html || '');
            } else {
                tbody.innerHTML = data.html;
            }

            // Actualizar estado del scroll
            window._auxScrollState.offset = data.nextOffset || 0;
            window._auxScrollState.hasMore = !!data.hasMore;

            // Refrescar el mapa de detalles para que los nuevos auxiliares
            // visibles tras paginacion/filtro abran el modal del ojo instant.
            if (data.auxDetailsMap) {
                window.auxDetailsMap = Object.assign(window.auxDetailsMap || {}, data.auxDetailsMap);
            }
            // Stats y distribucion solo en la primera carga (offset=0)
            if (append) {
                window._auxScrollState.loading = false;
                const ld = document.getElementById('auxLoadingMore');
                if (ld) ld.style.display = 'none';
                // Restaurar selección masiva sobre las filas nuevas
                if (typeof window.auxRestoreSelection === 'function') window.auxRestoreSelection();
                return;
            }
            if (data.stats) {
                const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v ?? 0; };
                set('auxStatsTotal',       data.stats.total);
                set('auxStatsOperativos',  data.stats.operativos);
                set('auxStatsInoperativos',data.stats.inoperativos);
            }
            if (data.distribucionHtml) {
                const cont = document.getElementById('auxDistribucionContainer');
                if (cont) {
                    cont.innerHTML = data.distribucionHtml;
                }
            }

            // Toggle del filtro "Detalle" (DETALLE_UBICACION_ACTUAL): solo se
            // muestra cuando el frente seleccionado es TIPO_FRENTE='ESPECIAL'.
            // Tambien refrescamos las opciones del dropdown segun el frente.
            const ubicWrap = document.getElementById('aux_adv_ubic_wrapper');
            if (ubicWrap) {
                if (data.showUbicaciones) {
                    ubicWrap.style.display = '';
                    var labelSpan = ubicWrap.querySelector('span');
                    if (labelSpan && data.frenteEspecialNombre) {
                        labelSpan.innerHTML = '<i class="material-icons" style="font-size:13px;vertical-align:middle;color:#0067b1;">place</i> Detalle (' + (String(data.frenteEspecialNombre).toUpperCase()) + ')';
                    }
                    var listEl = document.getElementById('aux_list_detalle_ubicacion');
                    if (listEl && Array.isArray(data.availableUbicaciones)) {
                        // Sin opción "TODOS LOS DETALLES": el reset se hace con el icono "✕"
                        // del filtro o con "Limpiar Todo" (coherente con los demás filtros avanzados).
                        var optsHtml = '';
                        data.availableUbicaciones.forEach(function (u) {
                            if (!u || !String(u).trim()) return;
                            var safe = String(u).replace(/'/g, "\\'");
                            optsHtml += '<div class="aux-adv-opt" data-val="' + u + '" onmousedown="event.preventDefault();auxAdvSelect(\'detalle_ubicacion\',\'' + safe + '\',\'' + safe + '\');cargarAuxiliares();" style="padding:10px 15px;font-size:14px;font-weight:600;color:var(--maquinaria-dark-blue);cursor:pointer;text-transform:uppercase;" onmouseover="this.style.background=\'#f0f4f8\'" onmouseout="this.style.background=\'white\'">' + u + '</div>';
                        });
                        listEl.innerHTML = optsHtml;
                    }
                } else {
                    ubicWrap.style.display = 'none';
                    var hiddenUb = document.getElementById('aux_val_detalle_ubicacion');
                    if (hiddenUb && hiddenUb.value) {
                        hiddenUb.value = '';
                        var txtUb = document.getElementById('aux_txt_detalle_ubicacion');
                        if (txtUb) txtUb.value = '';
                    }
                }
            }

            // Card "Detalles" del sidebar — paralelo al filtro de arriba.
            // Mostrar/ocultar segun showUbicaciones + reemplazar contenido
            // con el HTML pre-renderizado del backend.
            var ubicCard = document.getElementById('auxUbicacionesStatsCard');
            var ubicContainer = document.getElementById('auxUbicacionesStatsContainer');
            if (ubicCard && ubicContainer) {
                if (data.showUbicaciones && typeof data.ubicacionesHtml === 'string') {
                    ubicContainer.innerHTML = data.ubicacionesHtml;
                    ubicCard.style.display = '';
                } else {
                    ubicCard.style.display = 'none';
                    ubicContainer.innerHTML = '';
                }
            }

            // Restaurar checks seleccionados tras rerender del body
            if (typeof window.auxRestoreSelection === 'function') window.auxRestoreSelection();
        })
        .catch(e => {
            console.error('auxiliares load:', e);
            if (append) {
                const ld = document.getElementById('auxLoadingMore');
                if (ld) ld.style.display = 'none';
            }
        })
        .finally(() => {
            if (!append) {
                window._auxScrollState.loading = false;
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
            }
        });
    };

    // ── IntersectionObserver: dispara la siguiente carga cuando el sentinel
    // entra en viewport. Idéntico patrón a /admin/equipos. ────────────────
    // Ejecución inmediata (NO DOMContentLoaded): los elementos ya existen
    // porque este <script> está después de ellos en el HTML. Además, en
    // navegación SPA DOMContentLoaded no se re-dispara.
    (function () {
        if (window._auxScrollObserver) { try { window._auxScrollObserver.disconnect(); } catch(_){} }
        const sentinel = document.getElementById('auxScrollSentinel');
        if (!sentinel || typeof IntersectionObserver === 'undefined') return;
        const obs = new IntersectionObserver(function (entries) {
            for (const entry of entries) {
                if (entry.isIntersecting && window._auxScrollState && window._auxScrollState.hasMore) {
                    window.cargarAuxiliares({ append: true });
                }
            }
        }, { rootMargin: '300px 0px' });
        obs.observe(sentinel);
        window._auxScrollObserver = obs;
    })();

    // El "Consolidado" / "Distribución" del sidebar NO se calcula en el render
    // inicial (para que el módulo abra rápido). Se rellena aquí de forma
    // diferida con un fetch ligero — SIN preloader, sin tocar la tabla.
    (function () {
        // Embebido en /admin/equipos: sin el form de filtros aux NO corre el fetch del
        // sidebar de auxiliares (ese panel no existe alli; el Consolidado es de equipos).
        if (!document.getElementById('auxFiltersForm')) return;
        try {
            var form = document.getElementById('auxFiltersForm');
            var params = form ? new URLSearchParams(new FormData(form)) : new URLSearchParams();
            params.set('offset', '0');
            fetch('{{ route("equipos-auxiliares.index") }}?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.stats) {
                    var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = (v == null ? 0 : v); };
                    set('auxStatsTotal',        data.stats.total);
                    set('auxStatsOperativos',   data.stats.operativos);
                    set('auxStatsInoperativos', data.stats.inoperativos);
                }
                if (data && data.distribucionHtml) {
                    var cont = document.getElementById('auxDistribucionContainer');
                    if (cont) cont.innerHTML = data.distribucionHtml;
                }
            })
            .catch(function () { /* silencioso: si falla, el sidebar queda en "—" */ });
        } catch (e) { /* noop */ }
    })();



    // Helpers para filtrar desde Consolidado + Distribucion (clicks).
    // El listado usa auxMainSelect (autocomplete custom) — antes se llamaba
    // selectOption('auxTipoFilterSelect', ...) que ya no existe (refactor de
    // los filtros principales). Ahora usamos auxMainSelect directo.
    window.auxFilterByTipo = function (tipo) {
        const tiposMap = @json($tipos);
        const label = tiposMap[tipo] || tipo;
        if (typeof window.auxMainSelect === 'function') {
            window.auxMainSelect('tipo', tipo, label);
        }
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
            // En modo "solo seleccionados", ocultar la fila al deseleccionar.
            if (window._auxSoloSel) tr.style.display = 'none';
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
        // Selectores container-agnosticos (tr[data-aux-id]) para que esta
        // maquinaria funcione tanto en #auxTableBody (modulo aux) como en
        // #equiposTableBody (embebida en /admin/equipos). Solo afecta filas aux.
        document.querySelectorAll('tr[data-aux-id].selected-row-maquinaria').forEach(tr => {
            tr.classList.remove('selected-row-maquinaria');
        });
        // Resetear filtro "solo seleccionados" si estaba activo.
        if (window._auxSoloSel) {
            window._auxSoloSel = false;
            document.querySelectorAll('tr[data-aux-id]').forEach(tr => { tr.style.display = ''; });
            const counter = document.querySelector('#auxBulkBar .selection-counter');
            if (counter) counter.classList.remove('is-filtering');
        }
        window.auxRefreshBulkBar();
    };

    // Filtro cliente "ver solo los auxiliares seleccionados".
    // Como los auxiliares se renderizan en cliente (no hay paginación AJAX
    // como en equipos), se ocultan/muestran filas directamente sin recargar.
    window.toggleAuxSoloSel = function (e) {
        if (e) e.stopPropagation();
        if (Object.keys(window._auxSelectedMap || {}).length === 0) {
            if (window.showToast) window.showToast('No hay auxiliares seleccionados.', 'error');
            return;
        }
        window._auxSoloSel = !window._auxSoloSel;
        const counter = document.querySelector('#auxBulkBar .selection-counter');
        if (counter) counter.classList.toggle('is-filtering', window._auxSoloSel);
        document.querySelectorAll('tr[data-aux-id]').forEach(tr => {
            if (window._auxSoloSel) {
                tr.style.display = tr.dataset.auxId in window._auxSelectedMap ? '' : 'none';
            } else {
                tr.style.display = '';
            }
        });
    };

    // Restaurar highlight tras AJAX refresh de la tabla
    window.auxRestoreSelection = function () {
        document.querySelectorAll('tr[data-aux-id]').forEach(tr => {
            const inSel = tr.dataset.auxId in window._auxSelectedMap;
            if (inSel) tr.classList.add('selected-row-maquinaria');
            // Re-aplicar filtro de visibilidad si "solo seleccionados" está activo.
            if (window._auxSoloSel) tr.style.display = inSel ? '' : 'none';
        });
        window.auxRefreshBulkBar();
    };

    // Delegacion de click en filas (solo con can:equipos.edit via clase)
    if (!window._auxRowClickBound) {
        window._auxRowClickBound = true;
        document.addEventListener('click', function (e) {
            const tr = e.target.closest('tr.aux-row-clickable[data-aux-id]');
            if (!tr) return;
            // Ignorar clicks en elementos interactivos o en la celda de acciones
            // (asi el click sobre el boton del ojo NO selecciona la fila aunque
            // caiga sobre el padding del TD).
            if (e.target.closest('button, a, input, .aux-status-trigger, .material-icons, .btn-details-mini, .aux-action-cell')) return;
            window.auxToggleRow(tr);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // ASIGNAR UBICACION ESPECIFICA (DETALLE_UBICACION_ACTUAL) bulk
    // Mismo patron que window.openUbicacionBulkModal del modulo equipos:
    // valida permiso + mismo frente, lanza modal y persiste via
    // /admin/equipos-auxiliares/bulk-ubicacion (require equipos.assign).
    // ═══════════════════════════════════════════════════════════
    window.openAuxUbicacionBulkModal = function (event) {
        if (event) { event.preventDefault(); event.stopPropagation(); }

        if (window.CAN_ASSIGN_AUX === false || window.CAN_ASSIGN_AUX === 'false') {
            if (window.showToast) window.showToast('No tienes permiso para actualizar detalles.', 'error');
            return;
        }

        const ids = Object.keys(window._auxSelectedMap || {});
        if (ids.length === 0) {
            if (window.showToast) window.showToast('Selecciona al menos un auxiliar.', 'error');
            return;
        }

        // Validar mismo frente: la ubicacion especifica solo tiene sentido
        // dentro de un frente concreto (mismo criterio que /admin/equipos).
        const frentesUnicos = [...new Set(ids.map(k => window._auxSelectedMap[k].frente || ''))];
        if (frentesUnicos.length > 1 || frentesUnicos[0] === '') {
            if (typeof window.showModal === 'function') {
                window.showModal({
                    type: 'error',
                    title: 'Selección no compatible',
                    message: 'Todos los auxiliares seleccionados deben estar en el MISMO frente. Revisa tu selección e inténtalo de nuevo.',
                    confirmText: 'Entendido',
                    hideCancel: true,
                });
            } else if (typeof window.showToast === 'function') {
                window.showToast('Todos los auxiliares deben estar en el mismo frente.', 'error');
            }
            return;
        }

        // ── Leer valor actual de DETALLE_UBICACION_ACTUAL desde auxDetailsMap ──
        // Si todos tienen el MISMO valor → pre-cargar. Si difieren → dejar vacío.
        const valoresActuales = ids.map(id => {
            const aux = (window.auxDetailsMap && window.auxDetailsMap[id]) ? window.auxDetailsMap[id] : null;
            return aux ? (aux.detalle_ubicacion || '') : null;
        });
        const todosEnCache     = valoresActuales.every(v => v !== null);
        const valoresUnicos    = todosEnCache ? [...new Set(valoresActuales)] : [];
        const valorPrevioComun = (todosEnCache && valoresUnicos.length === 1) ? valoresUnicos[0] : '';
        const hayValoresMixtos = todosEnCache && valoresUnicos.length > 1;

        const overlay = document.createElement('div');
        overlay.id = 'auxUbicacionBulkOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,0.55);backdrop-filter:blur(3px);z-index:10001;display:flex;align-items:center;justify-content:center;padding:20px;';

        const esc = s => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        const chipsHtml = ids.slice(0, 12).map(k => {
            const lbl = window._auxSelectedMap[k].codigo || ('#' + k);
            return `<span style="background:#e1effa;color:#0c4a6e;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;">${esc(lbl)}</span>`;
        }).join('') + (ids.length > 12 ? `<span style="color:#64748b;font-size:12px;">+${ids.length - 12} más</span>` : '');

        overlay.innerHTML = `
            <div style="background:white;width:100%;max-width:440px;border-radius:16px;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);overflow:hidden;animation:auxUbBulkIn 0.22s cubic-bezier(0.16,1,0.3,1);">
                <div style="background:#1e293b;padding:18px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="material-icons" style="color:#0284c7;font-size:20px;">description</i>
                        <h2 style="margin:0;font-size:16px;font-weight:700;">Asignar Detalle</h2>
                    </div>
                    <button type="button" id="auxUb-close" aria-label="Cerrar" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                        <i class="material-icons">close</i>
                    </button>
                </div>
                <div style="padding:20px;display:flex;flex-direction:column;gap:14px;">
                    <div>
                        <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;">${ids.length} auxiliar${ids.length !== 1 ? 'es' : ''} seleccionado${ids.length !== 1 ? 's' : ''}</p>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;padding:10px;background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;max-height:90px;overflow-y:auto;">
                            ${chipsHtml}
                        </div>
                    </div>
                    <div>
                        <label for="auxUb-input" style="display:block;font-size:13px;font-weight:700;color:#475569;margin-bottom:6px;">
                            <i class="material-icons" style="font-size:14px;vertical-align:middle;margin-right:4px;color:#0284c7;">place</i>
                            Aspecto a resaltar
                        </label>
                        <div id="auxUb-inputbox" style="display:flex;align-items:center;border:1.5px solid ${valorPrevioComun ? '#0284c7' : '#e2e8f0'};border-radius:10px;background:white;overflow:hidden;transition:border-color 0.2s;">
                            <i class="material-icons" style="padding:0 10px;color:#94a3b8;font-size:18px;flex-shrink:0;">location_on</i>
                            <input type="text" id="auxUb-input" maxlength="150" autocomplete="off"
                                value="${esc(valorPrevioComun)}"
                                placeholder="${hayValoresMixtos ? 'Múltiples valores — escribe para sobreescribir todos' : 'Ej: PATIO 2, TALLER, ESTACIONAMIENTO A'}"
                                style="flex:1;border:none;outline:none;padding:10px 6px;font-size:13px;background:transparent;text-transform:uppercase;letter-spacing:0.3px;">
                        </div>
                        <small style="display:block;margin-top:6px;font-size:11px;color:#94a3b8;line-height:1.4;">
                            Indica la zona, patio o fila dentro del frente, u otro aspecto a resaltar del auxiliar.
                            ${valorPrevioComun ? '<br><span style="color:#0284c7;font-weight:600;">Deja el campo en blanco y guarda para borrar el detalle actual.</span>' : (hayValoresMixtos ? '<br><span style="color:#d97706;font-weight:600;">Los auxiliares seleccionados tienen detalles distintos.</span>' : '')}
                        </small>
                    </div>
                    <div id="auxUb-feedback" style="display:none;padding:10px 12px;border-radius:8px;font-size:12.5px;font-weight:600;"></div>
                    <button type="button" id="auxUb-submit" style="width:100%;height:46px;border-radius:12px;font-weight:700;font-size:14px;background:#1e293b;color:white;border:none;display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;transition:all 0.2s;">
                        <i class="material-icons">check_circle</i> Aplicar Detalle
                    </button>
                </div>
            </div>
        `;

        if (!document.getElementById('auxUb-keyframes')) {
            const st = document.createElement('style');
            st.id = 'auxUb-keyframes';
            st.textContent = '@keyframes auxUbBulkIn { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } } #auxUb-inputbox:focus-within { border-color:#0284c7; box-shadow:0 0 0 3px rgba(2,132,199,0.15); }';
            document.head.appendChild(st);
        }

        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        const closeModal = () => { overlay.remove(); document.body.style.overflow = ''; };
        const input     = overlay.querySelector('#auxUb-input');
        const fb        = overlay.querySelector('#auxUb-feedback');
        const submitBtn = overlay.querySelector('#auxUb-submit');
        setTimeout(() => input.focus(), 80);

        overlay.querySelector('#auxUb-close').onclick = closeModal;
        overlay.onclick = (e) => { if (e.target === overlay) closeModal(); };
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); doSubmit(); } });

        function showFb(type, msg) {
            const c = {
                error:   { bg:'#fee2e2', border:'#fecaca', color:'#b91c1c' },
                success: { bg:'#dcfce7', border:'#bbf7d0', color:'#15803d' },
            }[type] || { bg:'#e0f2fe', border:'#bae6fd', color:'#075985' };
            fb.style.cssText = 'display:block;padding:10px 12px;border-radius:8px;font-size:12.5px;font-weight:600;background:' + c.bg + ';border:1px solid ' + c.border + ';color:' + c.color + ';';
            fb.textContent = msg;
        }

        async function doSubmit() {
            const valor = (input.value || '').trim();

            // Caso 1: campo vacío + había valor previo → borrado directo sin
            // confirmación (pedido del cliente: el modal extra estorbaba la
            // operación). Mismo criterio que el modal de equipos.
            if (!valor && valorPrevioComun) {
                _enviarUbicacion('');
                return;
            }

            // Caso 2: campo vacío + sin valor previo → nada que guardar
            if (!valor && !valorPrevioComun) {
                if (typeof window.showToast === 'function') {
                    window.showToast('Escribe un detalle o cierra el modal sin cambios.', 'info');
                }
                input.focus();
                return;
            }

            // Caso 3: hay texto → guardar normalmente
            _enviarUbicacion(valor);
        }

        async function _enviarUbicacion(valorFinal) {
            submitBtn.disabled = true;
            if (typeof window.showPreloader === 'function') window.showPreloader();
            try {
                const res = await fetch('/admin/equipos-auxiliares/bulk-ubicacion', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ ids: ids.map(Number), detalle_ubicacion: valorFinal }),
                });
                if (!res.ok) {
                    const err = await res.json().catch(() => ({}));
                    throw new Error(err.message || ('Error ' + res.status));
                }
                const data = await res.json();

                // ── Actualizar cache local INMEDIATAMENTE ──────────────────────
                // cargarAuxiliares puede paginar y no devolver este auxiliar en
                // la ventana actual, dejando auxDetailsMap con el valor viejo.
                const nuevoDetalle = valorFinal ? valorFinal.toUpperCase() : '';
                if (window.auxDetailsMap) {
                    ids.forEach(id => {
                        if (window.auxDetailsMap[id]) {
                            window.auxDetailsMap[id].detalle_ubicacion = nuevoDetalle;
                        }
                    });
                }

                if (typeof window.auxClearSelection === 'function') window.auxClearSelection();
                closeModal();
                if (typeof window.cargarAuxiliares === 'function') {
                    await window.cargarAuxiliares();
                }
                const toastMsg = valorFinal
                    ? 'Detalle actualizado en ' + (data.count || ids.length) + ' auxiliar(es).'
                    : 'Detalle borrado en ' + (data.count || ids.length) + ' auxiliar(es).';
                if (typeof window.showToast === 'function') window.showToast(toastMsg, 'success');
            } catch (err) {
                console.error('[Aux Ubicacion bulk]', err);
                showFb('error', err.message || 'No se pudo actualizar.');
                submitBtn.disabled = false;
            } finally {
                if (typeof window.hidePreloader === 'function') window.hidePreloader();
            }
        }
        overlay.querySelector('#auxUb-submit').onclick = doSubmit;
    };

    // opts.presetFrente: nombre del frente a pre-seleccionar (usado cuando se
    // abre automáticamente tras confirmar la movilización de equipos, para
    // reutilizar el mismo destino sin que el usuario lo tenga que reescribir).
    window.openAuxMovilizarModal = function (opts) {
        opts = opts || {};
        const ids = Object.keys(window._auxSelectedMap);
        if (ids.length === 0) return;
        // Permiso: la barra es siempre visible para mostrar el conteo, pero la
        // accion solo se ejecuta si el usuario tiene equipos.assign.
        if (window.CAN_ASSIGN_AUX === false || window.CAN_ASSIGN_AUX === 'false') {
            if (window.showToast) window.showToast('No tienes permiso para movilizar auxiliares.', 'error');
            else alert('No tienes permiso para movilizar auxiliares.');
            return;
        }
        const modal = document.getElementById('auxMovilizarModal');
        if (!modal) return;

        // Poblar chips: 1 chip por auxiliar seleccionado (codigo/serial).
        const esc = s => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        const chips = document.getElementById('auxMovilizarChips');
        if (chips) {
            chips.innerHTML = ids.map(k => {
                const lbl = window._auxSelectedMap[k].codigo || ('#' + k);
                return '<span style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:600;white-space:nowrap;">' + esc(lbl) + '</span>';
            }).join('');
        }

        // Reset de todos los campos del modal (frente + ubicacion + checkbox)
        document.getElementById('auxMovilizarFrente').value = '';
        const search = document.getElementById('auxMovilizarSearch');
        if (search) { search.value = ''; }
        const clr = document.getElementById('auxMovilizarClear');
        if (clr) clr.style.display = 'none';
        const list = document.getElementById('auxMovilizarList');
        if (list) {
            list.style.display = 'none';
            list.querySelectorAll('.aux-mov-opt').forEach(o => o.style.display = '');
        }
        const pdfChk = document.getElementById('auxMovilizarGenerarPdf');
        if (pdfChk) pdfChk.checked = false;

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';

        // Pre-seleccionar frente si viene desde la movilización de equipos.
        if (opts.presetFrente) {
            const target = (opts.presetFrente || '').toUpperCase().trim();
            const opt = list ? Array.from(list.querySelectorAll('.aux-mov-opt'))
                .find(o => (o.dataset.label || '').toUpperCase().trim() === target) : null;
            if (opt) {
                window.auxMovSelect(opt.dataset.id, opt.dataset.label);
            } else if (search) {
                // Frente nuevo: rellenar el texto aunque no haya coincidencia exacta
                search.value = target;
                if (clr) clr.style.display = 'block';
            }
        }
    };

    window.closeAuxMovilizarModal = function () {
        const modal = document.getElementById('auxMovilizarModal');
        if (modal) modal.classList.remove('active');
        document.body.style.overflow = '';
    };

    // Helpers del autocomplete del frente destino (mismo patron que /admin/equipos).
    window.auxMovOpenList = function () {
        const list = document.getElementById('auxMovilizarList');
        if (list) list.style.display = 'block';
    };
    window.auxMovCloseList = function () {
        const list = document.getElementById('auxMovilizarList');
        if (list) list.style.display = 'none';
    };
    window.auxMovFilterList = function (q) {
        const list = document.getElementById('auxMovilizarList');
        if (!list) return;
        list.style.display = 'block';
        const qu = (q || '').toUpperCase().trim();
        list.querySelectorAll('.aux-mov-opt').forEach(opt => {
            const lbl = (opt.dataset.label || '').toUpperCase();
            opt.style.display = (!qu || lbl.indexOf(qu) !== -1) ? '' : 'none';
        });
    };

    // Input handler: filtra la lista y sincroniza el hidden con el id del frente
    // seleccionado cuando hay match exacto. Sin match → frente nuevo, hidden queda vacío.
    window.auxMovOnInput = function (val) {
        const v = (val || '').toUpperCase().trim();
        const list = document.getElementById('auxMovilizarList');
        const hidden = document.getElementById('auxMovilizarFrente');
        const clr = document.getElementById('auxMovilizarClear');
        if (!list || !hidden) return;
        list.style.display = 'block';

        let exactMatch = null;
        list.querySelectorAll('.aux-mov-opt').forEach(opt => {
            const lbl = (opt.dataset.label || '').toUpperCase();
            opt.style.display = (!v || lbl.indexOf(v) !== -1) ? '' : 'none';
            if (lbl === v) exactMatch = opt;
        });

        hidden.value = exactMatch ? (exactMatch.dataset.id || '') : '';
        if (clr) clr.style.display = v ? 'block' : 'none';
    };

    window.auxMovSelect = function (id, label) {
        document.getElementById('auxMovilizarFrente').value = id;
        document.getElementById('auxMovilizarSearch').value = label;
        document.getElementById('auxMovilizarClear').style.display = 'block';
        window.auxMovCloseList();
    };
    window.auxMovClear = function () {
        document.getElementById('auxMovilizarFrente').value = '';
        document.getElementById('auxMovilizarSearch').value = '';
        document.getElementById('auxMovilizarClear').style.display = 'none';
        document.getElementById('auxMovilizarList').querySelectorAll('.aux-mov-opt').forEach(o => o.style.display = '');
    };

    window.auxSubmitMovilizar = function () {
        const destination = (document.getElementById('auxMovilizarSearch').value || '').trim();
        const frenteId    = document.getElementById('auxMovilizarFrente').value;
        const generarPdf  = !!document.getElementById('auxMovilizarGenerarPdf')?.checked;

        if (!destination) {
            if (window.showToast) window.showToast('Selecciona o escribe un frente destino.', 'warning');
            return;
        }
        const ids = Object.keys(window._auxSelectedMap).map(x => parseInt(x, 10));
        if (!ids.length) { window.closeAuxMovilizarModal(); return; }

        // Determinar si el frente necesita ubicacion (frente nuevo o existente sin UBICACION).
        // Solo cuando se genera el Acta PDF se abre la Vista Previa para capturarla; sin PDF
        // se moviliza directo y el frente queda sin ubicacion — mismo patron que /admin/equipos.
        const listEl = document.getElementById('auxMovilizarList');
        const destUp = destination.toUpperCase();
        let matchedOpt = null;
        if (listEl) listEl.querySelectorAll('.aux-mov-opt').forEach(o => {
            if ((o.dataset.label || '').toUpperCase() === destUp) matchedOpt = o;
        });
        const isNewFrente    = !matchedOpt || !frenteId;
        const needsUbicacion = isNewFrente || !(matchedOpt && (matchedOpt.dataset.ubicacion || '').trim());
        const destUbicacion  = needsUbicacion ? '' : ((matchedOpt && matchedOpt.dataset.ubicacion) || '');

        const bulkMoveUrl = '{{ route("equipos-auxiliares.bulkMove") }}';
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

        const actaState = {
            ids: ids,
            type: 'auxiliar',
            destination: destination,
            destination_ubicacion: destUbicacion,
            origin: '',
            origin_zona: '',
            firmas: null
        };

        // ejecutarCommit: llama al endpoint de aux, descarga el acta si corresponde.
        // Patron identico a /admin/equipos (fetch→blob para mantener preloader visible).
        const ejecutarCommit = async function () {
            if (window.showPreloader) window.showPreloader();
            try {
                const res = await fetch(bulkMoveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
                    body: JSON.stringify({
                        ids: actaState.ids,
                        id_frente:   frenteId ? parseInt(frenteId, 10) : null,
                        destination: actaState.destination,
                        ubicacion:   actaState.destination_ubicacion,
                        generar_pdf: generarPdf
                    })
                });
                if (!res.ok) {
                    let errMsg = 'Error del servidor (' + res.status + ')';
                    try { const e = await res.json(); errMsg = e.message || e.error || errMsg; } catch(_) {}
                    throw new Error(errMsg);
                }
                const body = await res.json();
                if (!body.success) throw new Error(body.message || 'No se pudo movilizar.');

                window.auxClearSelection();
                window.closeAuxMovilizarModal();
                if (typeof cargarAuxiliares === 'function') cargarAuxiliares();

                if (body.generar_pdf && Array.isArray(body.movilizacion_ids) && body.movilizacion_ids.length) {
                    const firstId = body.movilizacion_ids[0];
                    if (window.showPreloader) window.showPreloader();
                    // Si el usuario editó origen/firmas en la vista previa, los pasa como
                    // override al POST del acta (mismo patron que /admin/equipos).
                    const tieneOverride = (actaState.origin && actaState.origin.trim() !== '') || actaState.firmas !== null;
                    const actaReq = tieneOverride
                        ? { method: 'POST', headers: { 'Accept': 'application/pdf', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, credentials: 'same-origin',
                            body: JSON.stringify({ override_origin: actaState.origin || '', override_origin_zona: actaState.origin_zona || '', override_firmas: actaState.firmas }) }
                        : { headers: { 'Accept': 'application/pdf' }, credentials: 'same-origin' };
                    fetch('/admin/movilizaciones/' + firstId + '/acta-traslado', actaReq)
                        .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); })
                        .then(blob => {
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url; a.download = 'Acta_Traslado_' + firstId + '.pdf';
                            a.style.display = 'none'; a.setAttribute('data-no-spa', 'true');
                            document.body.appendChild(a); a.click();
                            setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 1000);
                        })
                        .catch(() => { if (window.showToast) window.showToast('No se pudo descargar el acta.', 'error'); })
                        .finally(() => { if (window.hidePreloader) window.hidePreloader(); });
                    if (window.showToast) window.showToast('Movilización exitosa. Descargando acta...', 'success');
                } else if (window.showToast) {
                    window.showToast(body.message || 'Movilización exitosa.', 'success');
                }
            } catch(err) {
                if (window.hidePreloader) window.hidePreloader();
                if (window.showModal) {
                    window.showModal({ type:'error', title:'Error', message: err.message || 'Error al movilizar.', confirmText:'Entendido', hideCancel:true });
                } else if (window.showToast) {
                    window.showToast(err.message || 'Error al movilizar.', 'error');
                }
            } finally {
                if (window.hidePreloader) window.hidePreloader();
            }
        };

        // Sin Acta PDF → movilizar directo (el backend permite crear/mover a un frente sin
        // ubicacion cuando no se genera el acta). generarPdf=true → Vista Previa del Acta;
        // frente nuevo/sin ubicacion abre el formulario de edicion directamente (editarDirecto).
        if (generarPdf && typeof window._mostrarVistaPreviaActa === 'function') {
            window._mostrarVistaPreviaActa(actaState, ejecutarCommit, { editarDirecto: isNewFrente || needsUbicacion });
            return;
        }
        ejecutarCommit();
    };

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
        'OPERATIVO':      { color: '#16a34a', bg: '#f0fdf4', icon: 'check_circle', label: 'Operativo' },
        'INOPERATIVO':    { color: '#dc2626', bg: '#fef2f2', icon: 'cancel',       label: 'Inoperativo' },
        'EN MANTENIMIENTO': { color: '#eab308', bg: '#fefce8', icon: 'build',        label: 'En Mantenimiento' },
        'EN_ALMACEN':     { color: '#1e40af', bg: '#eff6ff', icon: 'inventory_2',  label: 'En Almacén' },
        'DESINCORPORADO': { color: '#475569', bg: '#f1f5f9', icon: 'block',        label: 'Desincorp.' },
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

    // Aplica el estado al trigger de aux (icono/label/color/dataset).
    function _applyAuxStatusVisual(trigger, status) {
        const cfg = AUX_STATUS_CFG[status] || AUX_STATUS_CFG['DESINCORPORADO'];
        const iconEl  = trigger.querySelector('.material-icons:first-child') || trigger.querySelector('div > .material-icons');
        const labelEl = trigger.querySelector('.aux-status-label');
        const innerBlock = trigger.querySelector('div');
        if (iconEl)  { iconEl.textContent = cfg.icon; iconEl.style.color = cfg.color; }
        if (labelEl)   labelEl.textContent = cfg.label;
        if (innerBlock) innerBlock.style.color = cfg.color;
        trigger.dataset.status = status;
    }

    // Contexto para marcar el aux INOPERATIVO tras crear el reporte desde el desplegable.
    let _auxFallaCtx = null;

    window.auxChangeStatus = function (trigger, newStatus) {
        if (!trigger) return;
        const oldStatus = trigger.dataset.status;
        if (oldStatus === newStatus) return;

        // INOPERATIVO se gestiona CREANDO un reporte de falla (el backend deja el
        // auxiliar inoperativo). Abrimos el modal "Nuevo Reporte" pre-seleccionado; si
        // se cancela no cambia nada; al crear, handleFallaCreatedAux marca el estado.
        if (newStatus === 'INOPERATIVO' && typeof window.flOpenForActivo === 'function') {
            _auxFallaCtx = { trigger: trigger, oldStatus: oldStatus };
            window.flOpenForActivo('equipo_auxiliar', trigger.dataset.auxId,
                trigger.dataset.label || ('Auxiliar #' + trigger.dataset.auxId),
                function () { _auxFallaCtx = null; });
            return;
        }

        const url = trigger.dataset.statusUrl;
        const revert = function () { _applyAuxStatusVisual(trigger, oldStatus); };

        // Optimistic UI
        _applyAuxStatusVisual(trigger, newStatus);

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
                if (window.showToast) window.showToast('Estado actualizado.', 'success');
                return;
            }
            // El auxiliar tiene un reporte ABIERTO: revertir y abrir el modal de cierre
            // (compartido falla_create_modal.js → flAbrirCierre).
            if (body && body.falla_abierta) {
                revert();
                window.flAbrirCierre(body.falla_abierta);
                return;
            }
            throw new Error(body.message || 'Error');
        })
        .catch(err => {
            revert();
            if (window.showToast) window.showToast('No se pudo actualizar el estado.', 'error');
            console.error('auxChangeStatus:', err);
        });
    };

    // Tras crear un reporte desde el desplegable de estado: marca el aux INOPERATIVO.
    window.handleFallaCreatedAux = function () {
        const ctx = _auxFallaCtx;
        _auxFallaCtx = null;
        if (ctx && ctx.trigger) _applyAuxStatusVisual(ctx.trigger, 'INOPERATIVO');
    };

    // El cierre de reporte desde la tabla usa el modal COMPARTIDO
    // (falla_create_modal.js → flAbrirCierre / flConfirmarCierre), igual que equipos.
    // FALLA_MODAL_CFG.onClosed (más abajo) recarga el listado de auxiliares.

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
        // Muestra la ETIQUETA legible (label) cuando hay selección, no el código crudo:
        // filtros como "confirmado" usan value='SI'/'NO' pero label='CONFIRMADO'/'PENDIENTE'.
        // En los demás filtros value===label, así que el comportamiento no cambia. Al
        // limpiar (value=''), el cuadro queda vacío y el placeholder muestra la pista.
        if (txt)    { txt.value = value ? (label || value) : ''; txt.placeholder = value ? value : label; }
        if (clr)    clr.style.display = value ? 'block' : 'none';
        if (box)    {
            box.style.background  = value ? '#e1effa' : '#fbfcfd';
            box.style.borderColor = value ? '#0067b1' : '#cbd5e0';
        }
        if (list)   list.style.display = 'none';
    };

    // Placeholder por defecto de cada filtro avanzado — se restaura al limpiar
    // (con la opción "TODOS" eliminada, el reset vive en el icono ✕ / "Limpiar Todo").
    window.AUX_ADV_PLACEHOLDERS = {
        marca: 'Ej: Miller',
        modelo: 'Ej: Bobcat 225',
        capacidad: 'Ej: 300A, 20 pies',
        estado: 'Todos los estados',
        detalle_ubicacion: 'Seleccionar detalle...',
        confirmado: 'Todas'
    };

    window.auxAdvClear = function (prefix) {
        window.auxAdvSelect(prefix, '', (window.AUX_ADV_PLACEHOLDERS[prefix] || ''));
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
        // X visible mientras se escribe (mismo patron que /admin/equipos): asi el
        // usuario puede limpiar el input antes incluso de elegir una opcion.
        var clr = document.getElementById('aux_main_clr_' + prefix);
        if (clr) clr.style.display = (q && q.length > 0) ? 'block' : 'none';
    };

    // Toggle de la X del filtro Serial mientras el usuario escribe. El input
    // de Serial es texto libre (no autocomplete) por eso no pasa por auxMainSelect.
    window.auxToggleSerialClear = function (input) {
        var clr = document.getElementById('aux_main_clr_search');
        if (!clr) return;
        clr.style.display = (input && input.value && input.value.length > 0) ? 'block' : 'none';
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
        // Importante: cuando el usuario elige explicitamente "TODOS" (value='all')
        // preservamos 'all' en el hidden — asi el backend reconoce que hay UNA
        // seleccion activa (hasFilter=true) y carga todos los registros sin
        // aplicar where. Si guardaramos vacio, el listado mostraria 0 filas
        // (patron empty-when-no-filter para evitar dump masivo en initial load).
        if (hidden) hidden.value = isReal ? value : (value === 'all' ? 'all' : '');
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
        // Mismo gating que movilizar: barra visible siempre, accion gated.
        if (window.CAN_ASSIGN_AUX === false || window.CAN_ASSIGN_AUX === 'false') {
            if (window.showToast) window.showToast('No tienes permiso para anclar auxiliares.', 'error');
            else alert('No tienes permiso para anclar auxiliares.');
            return;
        }
        let overlay = document.getElementById('auxAnclarBulkOverlay');
        if (overlay) overlay.remove();
        // Modal con la misma estructura/estilo del modal de anclaje en
        // /admin/equipos (equipos_index.js L1568): width 90% / max-width 440px,
        // header 1e293b centrado, lista bg #f8fafc con padding 8px.
        overlay = document.createElement('div');
        overlay.id = 'auxAnclarBulkOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.5);z-index:2500;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(2px);';
        overlay.innerHTML = `
            <div style="background:white;border-radius:16px;width:90%;max-width:400px;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
                <div style="background:#1e293b;padding:18px;color:white;display:flex;justify-content:center;align-items:center;position:relative;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <i class="material-icons" style="color:#10b981;font-size:20px;">anchor</i>
                        <h2 style="margin:0;font-size:16px;font-weight:700;">Anclar Auxiliares</h2>
                    </div>
                    <button type="button" onclick="document.getElementById('auxAnclarBulkOverlay').remove();" style="position:absolute;right:15px;background:transparent;border:none;color:white;cursor:pointer;opacity:0.7;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.7'">
                        <i class="material-icons">close</i>
                    </button>
                </div>
                <div style="padding:20px;">
                    <div style="font-size:11px;color:#64748b;margin-bottom:8px;text-align:center;">${ids.length} auxiliar${ids.length>1?'es':''} a anclar</div>
                    <!-- Buscador (mismo patron que /admin/equipos) -->
                    <div style="display:flex;align-items:center;border:1.5px solid #e2e8f0;border-radius:10px;background:white;overflow:hidden;margin-bottom:12px;transition:border-color 0.2s;" id="auxABBox">
                        <i class="material-icons" style="padding:0 10px;color:#94a3b8;font-size:18px;flex-shrink:0;">search</i>
                        <input type="text" id="auxABInput" placeholder="Buscar por tipo, marca, serial..." autocomplete="off" style="flex:1;border:none;outline:none;padding:9px 6px;font-size:13px;background:transparent;">
                    </div>
                    <div style="font-size:11px;color:#64748b;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:6px;display:flex;align-items:center;gap:6px;" id="auxABRecommendLabel">
                        <i class="material-icons" style="font-size:14px;color:#10b981;">recommend</i>
                        Recomendados (Flota Liviana)
                    </div>
                    <div id="auxABList" style="max-height:340px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;padding:8px;margin-bottom:14px;">
                        <div style="padding:20px;text-align:center;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:22px;color:#94a3b8;">sync</i></div>
                    </div>
                    <button id="auxABConfirmBtn" type="button" disabled
                            style="width:100%;height:46px;border-radius:12px;font-weight:700;font-size:14px;background:#10b981;color:white;border:none;display:flex;align-items:center;justify-content:center;gap:8px;opacity:0.5;cursor:not-allowed;transition:all 0.2s;">
                        <i class="material-icons">check_circle</i> Confirmar Anclaje
                    </button>
                </div>
            </div>`;
        document.body.appendChild(overlay);
        const input = document.getElementById('auxABInput');
        const list  = document.getElementById('auxABList');
        const label = document.getElementById('auxABRecommendLabel');
        let timer = null;
        overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

        // Carga inicial: recomendaciones (flota liviana). Si el usuario teclea
        // 2+ caracteres, se reemplaza por busqueda libre.
        const fetchAndRender = (urlParams, isRecommend) => {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="animation:spin 1s linear infinite;font-size:22px;">sync</i></div>';
            if (label) {
                if (isRecommend) {
                    label.style.display = 'flex';
                    label.innerHTML = '<i class="material-icons" style="font-size:14px;color:#10b981;">recommend</i>Recomendados (Flota Liviana)';
                } else {
                    label.style.display = 'flex';
                    label.innerHTML = '<i class="material-icons" style="font-size:14px;color:#0067b1;">search</i>Resultados de búsqueda';
                }
            }
            fetch('{{ route("equipos-auxiliares.searchHosts") }}?' + urlParams, {
                headers: {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
            })
            .then(r => r.json())
                .then(rows => {
                    if (!rows || !rows.length) {
                        list.innerHTML = '<div style="padding:40px 20px;text-align:center;color:#94a3b8;"><i class="material-icons" style="font-size:32px;display:block;margin:0 auto 10px;">search_off</i>Sin resultados</div>';
                        return;
                    }
                    // Tarjeta 1:1 con la del modal de anclaje en /admin/equipos
                    // (equipos_index.js L1667): foto 40x40 + TIPO uppercase +
                    // MARCA + [fingerprint serial] [featured_play_list placa]
                    // + location_on frente.
                    const esc = (s) => (s || '').toString().replace(/"/g, '&quot;').replace(/'/g, "\\'");
                    list.innerHTML = rows.map(r => {
                        const dis = r.disponible ? '' : 'cursor:not-allowed;opacity:0.6;';
                        const badge = r.disponible
                            ? ''
                            : `<i class="material-icons" style="color:#cbd5e0;font-size:18px;margin-left:auto;" title="Lleno (${r.auxiliares_anclados}/2)">lock</i>`;
                        // object-fit:contain para mostrar la foto COMPLETA (sin recortar
                        // el largo del equipo). Background blanco rellena el sobrante
                        // del cuadrado para que se vea limpia.
                        const fotoHtml = r.foto
                            ? `<img src="${esc(r.foto)}" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:20px;color:#cbd5e0;&quot;>image_not_supported</i>'">`
                            : `<i class="material-icons" style="font-size:20px;color:#cbd5e0;">image_not_supported</i>`;
                        const placaPart = r.placa
                            ? `<span style="font-size:10px;color:#0067b1;font-weight:700;display:flex;align-items:center;gap:2px;"><i class="material-icons" style="font-size:10px;">featured_play_list</i>${esc(r.placa)}</span>`
                            : '';
                        const frenteBadge = r.frente_nombre
                            ? `<div style="font-size:10px;color:#f97316;font-weight:700;display:flex;align-items:center;gap:2px;margin-top:2px;"><i class="material-icons" style="font-size:10px;">location_on</i>${esc(r.frente_nombre)}</div>`
                            : '';
                        // Selection-then-confirm: click marca la card como seleccionada
                        // (verde + check) y habilita el boton Confirmar Anclaje. NO
                        // dispara el anclaje hasta que el usuario presione Confirmar.
                        const clickHandler = r.disponible
                            ? `onclick="window.auxABSelectHost(this, ${r.id}, '${esc(r.placa || r.serial_chasis || ('#' + r.id))}')"`
                            : '';
                        return `
                            <div class="aux-ab-host-card" style="padding:10px;border-radius:8px;background:white;border:1px solid #e2e8f0;margin-bottom:6px;display:flex;align-items:center;gap:12px;transition:all 0.2s;position:relative;${dis}"
                                 onmouseover="if(!this.dataset.locked && !this.dataset.selected){this.style.borderColor='#10b981';this.style.boxShadow='0 4px 6px -1px rgba(0,0,0,0.05)';}"
                                 onmouseout="if(!this.dataset.locked && !this.dataset.selected){this.style.borderColor='#e2e8f0';this.style.boxShadow='none';}"
                                 ${!r.disponible ? 'data-locked="1"' : ''}
                                 ${clickHandler}>
                                <div style="width:40px;height:40px;background:#f1f5f9;border-radius:6px;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0;">${fotoHtml}</div>
                                <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:2px;">
                                    <span style="font-weight:800;font-size:13px;color:#1e293b;text-transform:uppercase;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(r.tipo || 'S/TIPO')}</span>
                                    <div style="font-size:11px;color:#475569;font-weight:600;">${esc(r.marca || '')}</div>
                                    <div style="display:flex;align-items:center;gap:8px;margin-top:1px;">
                                        <span style="font-size:10px;color:#64748b;display:flex;align-items:center;gap:2px;"><i class="material-icons" style="font-size:10px;">fingerprint</i>${esc(r.serial_chasis || 'S/S')}</span>
                                        ${placaPart}
                                    </div>
                                    ${frenteBadge}
                                </div>
                                ${badge}
                            </div>`;
                    }).join('');
                });
        };

        // Carga inicial: recomendaciones FLOTA LIVIANA
        fetchAndRender('recommend=1', true);

        // Busqueda libre: 2+ chars, debounce 280ms
        input.addEventListener('input', () => {
            const q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 2) {
                // Si vacian el input, recargamos recomendaciones
                if (q.length === 0) fetchAndRender('recommend=1', true);
                else list.innerHTML = '<div style="padding:18px;text-align:center;color:#94a3b8;font-size:12px;">Escribe al menos 2 caracteres...</div>';
                window._auxABSelected = null;
                _auxABRefreshConfirmBtn();
                return;
            }
            timer = setTimeout(() => {
                fetchAndRender('q=' + encodeURIComponent(q), false);
            }, 280);
        });

        // Boton Confirmar Anclaje
        const confirmBtn = document.getElementById('auxABConfirmBtn');
        if (confirmBtn) {
            confirmBtn.onclick = function () {
                if (!window._auxABSelected) return;
                window.auxAnclarBulkConfirm(
                    window._auxABSelected.id,
                    window._auxABSelected.label
                );
            };
        }
    };

    // Selecciona un host en el modal: highlight verde + check + habilita Confirmar.
    window.auxABSelectHost = function (cardEl, hostId, hostLabel) {
        // Limpia seleccion previa
        document.querySelectorAll('.aux-ab-host-card[data-selected="1"]').forEach(c => {
            c.dataset.selected = '';
            c.style.borderColor = '#e2e8f0';
            c.style.background = 'white';
            const chk = c.querySelector('.aux-ab-check');
            if (chk) chk.remove();
        });
        // Marca la nueva
        cardEl.dataset.selected = '1';
        cardEl.style.borderColor = '#10b981';
        cardEl.style.background = '#f0fdf4';
        cardEl.style.boxShadow = '0 4px 6px -1px rgba(16,185,129,0.15)';
        if (!cardEl.querySelector('.aux-ab-check')) {
            const ck = document.createElement('div');
            ck.className = 'aux-ab-check';
            ck.style.cssText = 'color:#10b981;flex-shrink:0;display:flex;align-items:center;';
            ck.innerHTML = '<i class="material-icons" style="font-size:22px;">check_circle</i>';
            cardEl.appendChild(ck);
        }
        window._auxABSelected = { id: hostId, label: hostLabel };
        _auxABRefreshConfirmBtn();
    };

    function _auxABRefreshConfirmBtn() {
        const btn = document.getElementById('auxABConfirmBtn');
        if (!btn) return;
        if (window._auxABSelected) {
            btn.disabled = false;
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
        } else {
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
        }
    }

    window.auxAnclarBulkConfirm = function (hostId, hostLabel) {
        const ids = Object.keys(window._auxSelectedMap || {});
        if (!ids.length) return;
        // Sin confirm() nativo: el flujo ya es explicito (seleccionar host +
        // click en "Confirmar Anclaje"); el browser-modal feo solo agregaba
        // una capa de friccion innecesaria.
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
        // Preloader global durante upload + render del visor (mismo patron
        // que /admin/equipos: el spinner queda visible hasta que el PDF
        // esta listo en el iframe del visor).
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
            input.value = '';
            if (status === 200 && body.success) {
                if (window.showToast) window.showToast(body.message || 'PDF cargado.', 'success');

                // 0) Actualizar la caché en memoria (auxDetailsMap) con el link nuevo,
                //    para que al REABRIR el modal de detalles el botón salga como
                //    "cargado" (description) sin recargar la página. Igual que
                //    applyDocUpload en el módulo de equipos: no dependemos del
                //    refresco async de la tabla (que puede no traer este aux por
                //    paginación/filtro y dejaba el icono viejo hasta un F5).
                const _map = (window.auxDetailsMap = window.auxDetailsMap || {});
                const _cached = _map[auxId] || _map[String(auxId)];
                if (_cached && body.link) {
                    if (docType === 'propiedad')        _cached.link_doc_propiedad = body.link;
                    else if (docType === 'certificado') _cached.link_certificado   = body.link;
                }

                // 1) Cerrar el modal de detalles para que el visor del PDF
                //    no quede solapado por el card del aux.
                if (typeof window.closeAuxDetailsModal === 'function') {
                    window.closeAuxDetailsModal();
                }

                // 2) Refrescar la tabla en background: el partial re-renderiza
                //    el boton del PDF como "description" (azul) en vez del
                //    cloud_upload. Asi no queda el icono de cargar pegado y
                //    no hace falta recargar la pagina.
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();

                // 3) Abrir el visor del PDF al instante (igual que vehiculos):
                //    skipMetadata=false + module='auxiliar' para que el panel
                //    lateral cargue los datos correctos.
                if (body.link && typeof window.openPdfPreview === 'function') {
                    const labelHr  = docType === 'propiedad' ? 'Doc. Propiedad' : 'Certificado';
                    const uploadUrl = '/admin/equipos-auxiliares/' + auxId + '/upload-doc';
                    window.openPdfPreview(body.link, docType, labelHr, auxId, uploadUrl, false, 'auxiliar');
                }
                // hidePreloader: openPdfPreview enciende su propio loader interno
                // del iframe; apagamos el global para no dejarlo encimado.
                if (window.hidePreloader) window.hidePreloader();
            } else {
                if (window.hidePreloader) window.hidePreloader();
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

    // Helper compartido: construye el querystring de filtros heredados
    // del listado principal (frente/tipo) leyendo los hidden inputs que
    // el AJAX del listado mantiene actualizados. La URL del browser no
    // siempre refleja el estado del filtro (cuando se filtra via AJAX
    // sin pushState), por eso priorizamos los hidden inputs.
    window._auxBuildAnclajesFilterQS = function () {
        var qs = new URLSearchParams();
        var fEl = document.getElementById('aux_main_val_frente');
        var tEl = document.getElementById('aux_main_val_tipo');
        var f = fEl ? (fEl.value || '').trim() : '';
        var t = tEl ? (tEl.value || '').trim() : '';
        // Fallback a la URL por si los hidden no estan presentes (defensivo)
        if (!f) { var sp = new URLSearchParams(window.location.search); f = sp.get('id_frente') || ''; }
        if (!t) { var sp2 = new URLSearchParams(window.location.search); t = sp2.get('tipo') || ''; }
        if (f && f !== 'all' && f !== 'none') qs.set('id_frente', f);
        if (t && t !== 'all' && t !== 'none') qs.set('tipo', t);
        return qs.toString();
    };

    window.openAuxAnclajesModal = function () {
        let modal = document.getElementById('auxAnclajesModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'auxAnclajesModal';
            modal.className = 'modal-overlay';
            modal.style.zIndex = '10000';
            modal.innerHTML = `
                <div class="modal-content" style="width:90%; max-width:820px; max-height:90vh; background:#fff; border-radius:12px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                    <div style="background:linear-gradient(135deg,#1e293b 0%,#0f172a 100%); padding:14px 18px; display:flex; justify-content:space-between; align-items:center;">
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="background:rgba(255,255,255,0.1); padding:8px; border-radius:8px;"><i class="material-icons" style="color:#fff; font-size:20px;">link</i></div>
                            <h3 style="margin:0; color:#fff; font-size:16px; font-weight:600;">Anclaje de Auxiliares</h3>
                            <span id="auxAnclajesCount" style="background:#10b981;color:white;font-size:11px;font-weight:800;padding:2px 8px;border-radius:10px;">0</span>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <button type="button" id="btnAuxAnclajesExport" title="Exportar a Excel (.xlsx)"
                                style="background:rgba(255,255,255,0.12); border:1px solid rgba(255,255,255,0.28); color:#ffffff; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:6px; border-radius:6px; transition:all 0.2s;"
                                onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                                <i class="material-icons" style="font-size:18px;">download</i>
                            </button>
                            <button type="button" onclick="document.getElementById('auxAnclajesModal').classList.remove('active')" style="background:transparent; border:none; color:#94a3b8; cursor:pointer; display:flex; padding:4px;">
                                <i class="material-icons">close</i>
                            </button>
                        </div>
                    </div>
                    <div id="auxAnclajesLoading" style="padding:40px; text-align:center; color:#64748b;">
                        <i class="material-icons" style="font-size:32px; animation:fleetSpin 1s linear infinite;">refresh</i>
                        <p style="margin-top:10px; font-size:14px;">Cargando anclajes...</p>
                    </div>
                    <div id="auxAnclajesBody" style="display:none; padding:14px 16px; overflow-y:auto; flex:1; background:#f8fafc;">
                        <div id="auxAnclajesGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:10px;"></div>
                    </div>
                </div>`;
            document.body.appendChild(modal);
            // Boton de export — descarga via blob (mismo patron que /admin/equipos)
            // para usar el preloader global y evitar el spinner nativo del navegador.
            // Hereda los filtros del listado principal (id_frente, tipo) si estan activos.
            document.getElementById('btnAuxAnclajesExport').addEventListener('click', function () {
                var qs = window._auxBuildAnclajesFilterQS ? window._auxBuildAnclajesFilterQS() : '';
                var url = '{{ route("equipos-auxiliares.exportAnclajes") }}' + (qs ? ('?' + qs) : '');
                if (typeof window.showPreloader === 'function') window.showPreloader();
                fetch(url, { credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'} })
                    .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); var cd=r.headers.get('content-disposition')||''; var m=cd.match(/filename="?([^";]+)"?/i); var fname=m?m[1]:('Anclajes_Auxiliares_'+new Date().toISOString().slice(0,10)+'.xlsx'); return r.blob().then(function(b){return {blob:b, fname:fname};}); })
                    .then(function(o){ var u=URL.createObjectURL(o.blob); var a=document.createElement('a'); a.href=u; a.download=o.fname; a.style.display='none'; document.body.appendChild(a); a.click(); setTimeout(function(){document.body.removeChild(a); URL.revokeObjectURL(u);},300); if(window.showToast) window.showToast('Descarga lista: '+o.fname,'success'); })
                    .catch(function(err){ console.error('[exportAuxAnclajes]', err); if(window.showToast) window.showToast('Error al descargar el Excel.','error'); })
                    .finally(function(){ if(typeof window.hidePreloader==='function') window.hidePreloader(); });
            });
        }
        modal.classList.add('active');
        document.getElementById('auxAnclajesLoading').style.display = 'block';
        document.getElementById('auxAnclajesBody').style.display = 'none';

        // Hereda los filtros del listado principal (id_frente, tipo) si los tiene activos.
        // Lee de los hidden inputs (#aux_main_val_frente / #aux_main_val_tipo) que el
        // listado AJAX mantiene actualizados — la URL no siempre tiene los params.
        var _qsIn = window._auxBuildAnclajesFilterQS ? window._auxBuildAnclajesFilterQS() : '';
        var _urlIn = '{{ route("equipos-auxiliares.anchoredList") }}' + (_qsIn ? ('?' + _qsIn) : '');
        fetch(_urlIn, {
            headers: { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' },
            credentials: 'same-origin'
        })
        .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
        .then(data => {
            const items = Array.isArray(data.items) ? data.items : [];
            document.getElementById('auxAnclajesLoading').style.display = 'none';
            document.getElementById('auxAnclajesBody').style.display = 'block';
            const grid = document.getElementById('auxAnclajesGrid');
            const countBadge = document.getElementById('auxAnclajesCount');
            if (countBadge) countBadge.textContent = items.length;
            if (items.length === 0) {
                grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; padding:30px; color:#94a3b8; background:#fff; border-radius:8px; border:1px dashed #cbd5e1;">No hay auxiliares anclados actualmente.</div>';
                return;
            }
            const esc = (s) => String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

            // Agrupar por host: 1 tarjeta por equipo host con TODOS sus auxiliares anclados.
            // Antes se renderizaba 1 tarjeta por (auxiliar, host) — duplicaba el host.
            const groups = {};
            items.forEach(a => {
                const key = a.host_id ?? ('NOHOST_'+ (a.host_codigo||'') + (a.host_serial||''));
                if (!groups[key]) {
                    groups[key] = {
                        host_id:    a.host_id,
                        host_codigo:a.host_codigo,
                        host_placa: a.host_placa,
                        host_serial:a.host_serial,
                        host_tipo:  a.host_tipo,
                        host_marca: a.host_marca,
                        host_modelo:a.host_modelo,
                        host_frente:a.host_frente,
                        host_foto:  a.host_foto,
                        auxes:      []
                    };
                }
                groups[key].auxes.push(a);
            });

            grid.innerHTML = Object.values(groups).map(g => {
                const hostLabel = g.host_placa || g.host_serial || g.host_codigo || ('#' + g.host_id);
                const hostType  = (g.host_tipo || 'Equipo').toString();
                const hostMarca = g.host_marca ? esc(g.host_marca) : '';
                const hostFotoHtml = g.host_foto
                    ? `<img src="${esc(g.host_foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:22px;color:#1e40af;&quot;>directions_car</i>'">`
                    : '<i class="material-icons" style="font-size:22px;color:#1e40af;">directions_car</i>';

                const auxRowsHtml = g.auxes.map(a => {
                    const auxLabel   = a.serial || ((a.marca || '') + ' ' + (a.modelo || '')).trim() || '—';
                    const auxFotoHtml = a.foto
                        ? `<img src="${esc(a.foto)}" alt="" style="width:100%;height:100%;object-fit:contain;background:white;" onerror="this.outerHTML='<i class=&quot;material-icons&quot; style=&quot;font-size:16px;color:#f59e0b;&quot;>construction</i>'">`
                        : '<i class="material-icons" style="font-size:16px;color:#f59e0b;">construction</i>';
                    return `<div style="display:flex; align-items:center; gap:8px; padding:6px 8px; background:#fff7ed; border-radius:6px; border:1px solid #fed7aa;">
                        <div style="background:#fff;padding:0;border-radius:5px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #fed7aa;">${auxFotoHtml}</div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:9px; font-weight:700; color:#92400e; text-transform:uppercase; letter-spacing:0.3px;">${esc(a.tipo_label || a.tipo || 'AUXILIAR')}</div>
                            <div style="font-size:12px; font-weight:800; color:#7c2d12; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(auxLabel)}</div>
                            ${a.marca || a.modelo ? `<div style="font-size:10px; color:#9a3412;">${esc(a.marca||'')} ${esc(a.modelo||'')}</div>` : ''}
                        </div>
                    </div>`;
                }).join('');

                return `<div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:10px; display:flex; flex-direction:column; gap:8px; box-shadow:0 1px 4px rgba(0,0,0,0.06);">
                    <div style="display:flex; align-items:center; gap:10px; padding:8px 10px; background:#eff6ff; border-radius:8px; border:1px solid #bfdbfe;">
                        <div style="background:#fff;padding:0;border-radius:6px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;border:1px solid #bfdbfe;">${hostFotoHtml}</div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-size:9.5px; font-weight:700; color:#1e3a8a; text-transform:uppercase; letter-spacing:0.4px;">${esc(hostType)}</div>
                            <div style="font-size:14px; font-weight:800; color:#1e3a8a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${esc(hostLabel)}</div>
                            ${hostMarca ? `<div style="font-size:10.5px; color:#1d4ed8; margin-top:1px;">${hostMarca} ${esc(g.host_modelo||'')}</div>` : ''}
                        </div>
                        <span style="background:#10b981;color:white;font-size:10px;font-weight:800;padding:2px 8px;border-radius:10px;flex-shrink:0;">${g.auxes.length}</span>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:5px;">${auxRowsHtml}</div>
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
        var csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        fetch('{{ route("equipos-auxiliares.bulkDelete") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ ids: ids })
        })
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (res) {
            if (window.hidePreloader) window.hidePreloader();
            if (res.ok && res.body.success) {
                if (window.showToast) window.showToast(res.body.message || 'Auxiliares eliminados.', 'success');
                if (typeof window.auxClearSelection === 'function') window.auxClearSelection();
                if (typeof window.cargarAuxiliares === 'function') window.cargarAuxiliares();
            } else {
                if (window.showToast) window.showToast((res.body && res.body.message) || 'No se pudo eliminar.', 'error');
            }
        })
        .catch(function () {
            if (window.hidePreloader) window.hidePreloader();
            if (window.showToast) window.showToast('Error de red al eliminar.', 'error');
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

