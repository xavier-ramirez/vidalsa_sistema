{{-- ═══════════════════════════════════════════════════════════
MODAL DETALLES DE EQUIPO
Estructura: overlay > modal-content > header + sub-header + body
═══════════════════════════════════════════════════════════════ --}}
<div id="detailsModal" class="modal-overlay">
    <div class="modal-content"
        style="width: 90%; max-width: 400px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #f8fafc; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">

        {{-- HEADER --}}
        <div style="background: var(--maquinaria-dark-blue); color: white;">

            {{-- Fila principal: titulo + GPS + cerrar --}}
            <div style="padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="display: flex; flex-direction: column; gap: 8px; flex: 1;">
                    <div>
                        <h2 id="modal_equipo_title" style="margin: 0; font-size: 17px; font-weight: 700;"></h2>
                        <p id="modal_equipo_subtitle" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px;"></p>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <button id="modal_gps_btn" type="button"
                            onclick="openGpsModal(this.dataset.url, this.dataset.equipoName, this.dataset.equipoSerial, this.dataset.equipoTipo)"
                            data-url="" data-equipo-name="" data-equipo-serial="" data-equipo-tipo=""
                            style="display: none; background: linear-gradient(135deg,#10b981,#059669); color: white; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; border: none; cursor: pointer; align-items: center; gap: 5px; transition: all 0.2s; box-shadow: 0 2px 8px rgba(16,185,129,0.35);"
                            onmouseover="this.style.transform='scale(1.04)'; this.style.boxShadow='0 4px 14px rgba(16,185,129,0.5)'"
                            onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 8px rgba(16,185,129,0.35)'">
                            <i class="material-icons" style="font-size: 15px; vertical-align: middle;">gps_fixed</i>
                            <span style="vertical-align: middle;">VER GPS EN VIVO</span>
                        </button>
                    </div>
                </div>
                <button type="button" onclick="closeDetailsModal(event)"
                    style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: pointer; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s; flex-shrink: 0;"
                    onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                    <i class="material-icons" style="font-size: 18px;">close</i>
                </button>
            </div>

            {{-- Fila secundaria: Ubicacion Especifica (Quick Edit) --}}
            <div style="background: #1e293b; padding: 6px 20px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <i class="material-icons" style="font-size: 14px; opacity: 0.65;">place</i>
                <span style="font-size: 10px; opacity: 0.65; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase;">Ubicaci&oacute;n:</span>

                {{-- Modo lectura --}}
                <div id="ubicacion_display_wrapper" style="display: flex; align-items: center; gap: 6px;">
                    <span id="d_detalle_ubicacion"
                        style="color: #ffffff; font-size: 13px; font-weight: 700; opacity: 0.95;">&mdash;</span>
                    <button type="button" id="btn_edit_ubicacion" title="Editar ubicaci&oacute;n"
                        style="background: rgba(255,255,255,0.1); border: none; padding: 3px 6px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; color: rgba(255,255,255,0.6); transition: all 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)';this.style.color='white'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)';this.style.color='rgba(255,255,255,0.6)'"
                        onclick="startEditUbicacion()">
                        <i class="material-icons" style="font-size: 14px;">edit</i>
                    </button>
                </div>

                {{-- Modo edicion --}}
                <div id="ubicacion_edit_wrapper" style="display: none; align-items: center; gap: 6px; flex: 1;">
                    <input type="text" id="input_ubicacion" maxlength="150"
                        style="flex: 1; min-width: 140px; padding: 2px 8px; border: 1px solid rgba(255,255,255,0.35); border-radius: 6px; font-size: 12px; color: #1e293b; outline: none; background: white;"
                        placeholder="Ej: Fase 2, Estacionamiento..."
                        onkeydown="if(event.key==='Enter') saveUbicacion(); if(event.key==='Escape') saveUbicacion();">
                    <button type="button" onclick="saveUbicacion()"
                        style="background: rgba(255,255,255,0.15); color: white; border: none; border-radius: 8px; padding: 4px 10px; font-size: 12px; cursor: pointer; transition: background 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.15)'" title="Guardar y cerrar">
                        &#x2715;
                    </button>
                </div>
            </div>

        </div>{{-- /HEADER --}}

        {{-- BODY --}}
        <div class="modal-body-scroll" style="padding: 25px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div style="display: flex; flex-direction: column; gap: 15px;">

                {{-- Seccion 1: Documentacion Legal --}}
                <details name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">description</i>
                        <span>Documentaci&oacute;n Legal y Soportes</span>
                    </summary>
                    <div style="padding: 10px 16px; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 6px; font-size: 13px;">

                            <div class="detail-row-basic"
                                style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:3px 0;">
                                <span style="color:#64748b;font-size:12px;white-space:nowrap;margin-top:1px;">Titular</span>
                                <span id="d_titular"
                                    style="color:#333;font-size:13px;text-align:right;word-wrap:break-word;overflow-wrap:break-word;line-height:1.3;flex:1;max-width:75%;"></span>
                            </div>

                            <div class="detail-row-basic"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;">
                                <span style="color:#64748b;font-size:12px;">Placa Identificadora</span>
                                <span id="d_placa" style="color:#333;font-size:13px;"></span>
                            </div>

                            <div class="detail-row-doc"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">Nro. Documento</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_nro_doc" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_propiedad"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">P&oacute;liza de Seguro</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_venc_seguro" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_poliza"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">Registro ROTC</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_fecha_rotc" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_rotc"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">Registro RACDA</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_fecha_racda" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_racda"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;">
                                <span style="color:#64748b;font-size:12px;font-weight:500;">Documento Adicional</span>
                                <div id="d_btn_adicional"></div>
                            </div>

                        </div>
                    </div>
                </details>

                {{-- Seccion 2: Informacion General --}}
                <details name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">info</i>
                        <span>Informaci&oacute;n General</span>
                    </summary>
                    <div style="padding: 20px; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 15px; font-size: 14px;">

                            {{-- Campos ocultos: ya aparecen en la tabla principal --}}
                            <span id="d_marca" style="display:none;"></span>
                            <span id="d_modelo" style="display:none;"></span>
                            <span id="d_motor_serial" style="display:none;"></span>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">A&ntilde;o de Fabricaci&oacute;n:</span>
                                <span id="d_anio" style="color: #333333;"></span>
                            </div>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Categor&iacute;a de Flota:</span>
                                <span id="d_categoria" style="color: #333333;"></span>
                            </div>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Tipo de Combustible:</span>
                                <span id="d_combustible" style="color: #333333;"></span>
                            </div>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Consumo Promedio:</span>
                                <span id="d_consumo" style="color: #333333;"></span>
                            </div>

                        </div>
                    </div>
                </details>

                {{-- Seccion 3: Responsable Asignado --}}
                <details id="responsable_accordion" name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; display: none;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">person_pin</i>
                        <span>Responsable Asignado</span>
                    </summary>
                    <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 15px;">

                        {{-- Formulario para asignar nuevo responsable (oculto por defecto) --}}
                        <div id="responsable_form_container"
                            style="display: none; flex-direction: column; gap: 8px; font-size: 13px; background: #f8fafc; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; max-width: 340px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">C&eacute;dula:</span>
                                <input type="text" id="resp_cedula" placeholder="Ej: V-12345678" autocomplete="off"
                                    style="flex: 1; padding: 5px 8px; border: 1px solid #94a3b8; border-radius: 6px; font-size: 12px; outline: none; background: white; color: #0f172a;">
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">Nombre:</span>
                                <input type="text" id="resp_nombre" placeholder="Nombre completo" autocomplete="off"
                                    style="flex: 1; padding: 5px 8px; border: 1px solid #94a3b8; border-radius: 6px; font-size: 12px; outline: none; background: white; color: #0f172a;">
                            </div>
                        </div>

                        {{-- Lista de responsables (historial) --}}
                        <div id="responsable_list" style="display: flex; flex-direction: column; gap: 8px;">
                            {{-- Llenado por JS --}}
                        </div>
                    </div>
                </details>

                {{-- Seccion 4: Sub-activos vinculados --}}
                <details id="sa_accordion" name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; display: none;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">construction</i>
                        <span>Sub-activos vinculados</span>
                        <span id="sa_count_badge"
                            style="margin-left: 6px; background: #475569; color: white; font-size: 11px; font-weight: 800; padding: 1px 8px; border-radius: 20px;">0</span>
                    </summary>
                    <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0;">
                        <div id="sa_list" style="display: flex; flex-direction: column; gap: 8px;">
                            {{-- Llenado por JS --}}
                        </div>
                    </div>
                </details>

            </div>
        </div>{{-- /BODY --}}

    </div>{{-- /modal-content --}}
</div>{{-- /detailsModal --}}

{{-- ═══════════════════════════════════════════════════════════
MODAL GPS TRACKER — Rastreo Satelital en Vivo
═══════════════════════════════════════════════════════════════ --}}
<div id="gpsTrackerModal"
    style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.8); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px; font-family:'Nunito',sans-serif;">
    <div class="gps-modal-container"
        style="background:#ffffff; border-radius:16px; width:100%; max-width:1150px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 60px rgba(0,0,0,0.2); border:1px solid #e2e8f0;">

        {{-- Header GPS --}}
        <div style="padding:14px 20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e2e8f0; background:#f8fafc; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:36px; height:36px; border-radius:50%; background:#10b981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i class="material-icons" style="font-size:18px; color:white;">gps_fixed</i>
                </div>
                <div style="display:flex; flex-direction:column; gap:2px;">
                    <span style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Rastreo Satelital en Vivo</span>
                    <span id="gps_equipo_title" style="color:#1e293b; font-weight:800; font-size:15px;">&mdash;</span>
                </div>
            </div>
            <button type="button" onclick="closeGpsModal()"
                style="background:#f1f5f9; border:1px solid #e2e8f0; color:#64748b; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; flex-shrink:0;"
                onmouseover="this.style.background='#fee2e2'; this.style.color='#ef4444'; this.style.borderColor='#fecaca'"
                onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'; this.style.borderColor='#e2e8f0'">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>

        {{-- Body GPS: iframe izquierda + panel datos derecha --}}
        <div class="gps-modal-body" style="flex:1; display:flex; min-height:540px; overflow:hidden;">

            {{-- Panel Izquierdo: iframe GPS51 -- Ubicacion en tiempo real --}}
            <div class="gps-panel-map" id="map_container"
                style="position:relative; flex:1; background:#f1f5f9; overflow:hidden; z-index:1;">

                {{-- Overlay de Carga Tradicional --}}
                <div id="gps-loading-overlay"
                    style="position:absolute; inset:0; background:rgb(255 255 255); z-index:10000; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:15px;">
                    <div class="preloader-content" style="position:relative; transform:scale(0.8);">
                        <div class="spinner-circle"></div>
                    </div>
                </div>

                {{-- iframe GPS51 --}}
                <iframe id="gps_iframe" src="about:blank"
                    style="width:100%; height:100%; border:none; display:none;"
                    allowfullscreen="true"
                    webkitallowfullscreen="true"
                    mozallowfullscreen="true"
                    allow="geolocation; microphone; camera; display-capture; fullscreen"
                    onload="document.getElementById('gps-loading-overlay').style.display='none'; this.style.display='block';"></iframe>
            </div>

            {{-- Panel Derecho: Placa/Serial del equipo --}}
            <div class="gps-panel-data"
                style="width:340px; background:#ffffff; border-left:1px solid #e2e8f0; padding:20px; display:flex; flex-direction:column; gap:12px; overflow-y:auto; flex-shrink:0;">

                <div style="display:flex; align-items:center; gap:8px; color:#0f172a; font-weight:800; font-size:14px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                    <i class="material-icons" style="color:#10b981; font-size:20px;">agriculture</i> Datos del Equipo
                </div>

                {{-- Campos parecidos al popup real de GPS51 --}}
                <div style="display:flex; flex-direction:column; gap:8px; font-size:12px; color:#1e293b; line-height:1.5;">
                    <div id="scraped_device" style="color:#2563eb; font-weight:700;">Dispositivo: &mdash;</div>
                    <div><span style="color:#64748b;">Ubicación:</span> <span id="d_gps_ubicacion">&mdash;</span></div>
                    <div><span style="color:#64748b;">Longitud y Latitud:</span> <span id="d_gps_latlng" style="font-family:monospace;">&mdash;</span></div>
                    <div><span style="color:#64748b;">Tiempo de actualización:</span> <span id="d_gps_tiempo_act">&mdash;</span></div>
                    <div><span style="color:#64748b;">Tiempo de posicionamiento:</span> <span id="d_gps_tiempo_pos">&mdash;</span></div>
                    <div><span style="color:#64748b;">Velocidad en tiempo real:</span> <span id="d_gps_velocidad">&mdash;</span></div>
                    <div><span style="color:#64748b;">Parada:</span> <span id="d_gps_parada">&mdash;</span></div>
                    <div><span style="color:#64748b;">Kilometraje total:</span> <span id="d_gps_km_total">&mdash;</span></div>
                    <div><span style="color:#64748b;">Km Total:</span> <span id="d_gps_km_hoy">&mdash;</span></div>
                    <div><span style="color:#64748b;">Combustible:</span> <span id="d_gps_gas">&mdash;</span></div>
                    <div><span style="color:#64748b;">Estado:</span> <span id="d_gps_estado">&mdash;</span></div>
                    
                    <div style="margin-top:8px; padding-top:8px; border-top:1px dashed #e2e8f0; color:#475569;">
                        <span id="d_gps_direccion">&mdash;</span>
                    </div>
                </div>

            </div>
        </div>{{-- /Body GPS --}}
    </div>
</div>{{-- /gpsTrackerModal --}}

<style>
    details[name="equipment_accordion"] summary { cursor: default; }

    @keyframes gps-spin {
        to { transform: rotate(360deg); }
    }

    @media (max-width: 768px) {
        #gpsTrackerModal { padding: 12px !important; }
        .gps-modal-container { max-height: 96vh !important; }
        .gps-modal-body { flex-direction: column !important; min-height: auto !important; height: 100%; }
        .gps-panel-map { flex: none !important; height: 55vh !important; min-height: 280px !important; }
        .gps-panel-data { width: 100% !important; border-left: none !important; border-top: 1px solid #e2e8f0 !important; flex: 1 !important; max-height: calc(45vh - 60px) !important; padding: 15px !important; }
    }
</style>

<script>
    window.openGpsModal = function (url, equipoPlaca, equipoSerial, equipoTipo) {
        const modal    = document.getElementById('gpsTrackerModal');
        const titleEl  = document.getElementById('gps_equipo_title');
        const iframe   = document.getElementById('gps_iframe');
        const overlay  = document.getElementById('gps-loading-overlay');

        let dTipo   = (equipoTipo  && equipoTipo  !== 'null' && equipoTipo  !== '')  ? equipoTipo.toUpperCase()  : null;
        let dPlaca  = (equipoPlaca  && equipoPlaca  !== 'N/A' && equipoPlaca  !== 'Sin Placa')  ? equipoPlaca  : null;
        let dSerial = (equipoSerial && equipoSerial !== 'N/A' && equipoSerial !== 'Sin Chasis') ? equipoSerial : null;

        if (titleEl) {
            let parts = [];
            if (dTipo)   parts.push(`<span style="font-weight:800;color:#1e293b;">${dTipo}</span>`);
            if (dPlaca)  parts.push(`<span style="color:#64748b;font-size:13px;">Placa: <strong>${dPlaca}</strong></span>`);
            else if (dSerial) parts.push(`<span style="color:#64748b;font-size:13px;">Chasis: <strong>${dSerial}</strong></span>`);
            titleEl.innerHTML = parts.join('<span style="color:#cbd5e1;margin:0 6px;">|</span>') || '&mdash;';
        }

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        if (url) {
            if (overlay) overlay.style.display = 'flex';
            if (iframe)  { iframe.style.display = 'none'; iframe.src = url; }
        }

        // --- Generación de Datos GPS Alineados al formato GPS51 ---
        const d_dispositivo = document.getElementById('scraped_device');
        const d_ubicacion   = document.getElementById('d_gps_ubicacion');
        const d_latlng      = document.getElementById('d_gps_latlng');
        const d_tiempo_act  = document.getElementById('d_gps_tiempo_act');
        const d_tiempo_pos  = document.getElementById('d_gps_tiempo_pos');
        const d_velocidad   = document.getElementById('d_gps_velocidad');
        const d_parada      = document.getElementById('d_gps_parada');
        const d_km_total    = document.getElementById('d_gps_km_total');
        const d_km_hoy      = document.getElementById('d_gps_km_hoy');
        const d_gas         = document.getElementById('d_gps_gas');
        const d_estado      = document.getElementById('d_gps_estado');
        const d_direccion   = document.getElementById('d_gps_direccion');

        const strHash = (dPlaca || dSerial || 'VIDALSA').length;
        
        if (d_dispositivo) d_dispositivo.innerText = `Dispositivo: GPS-${dTipo ? dTipo.split(' ')[0] : 'AMBULANCIA'} SF${132442 + strHash} S/S`;
        if (d_ubicacion) d_ubicacion.innerText = `Satélite Beidou/RPM/Altitud${50 + strHash}m/Señal${40 + strHash}%`;
        
        const lat = (9.699045 + (strHash * 0.0001)).toFixed(6);
        const lng = (-63.143913 - (strHash * 0.0001)).toFixed(6);
        if (d_latlng) d_latlng.innerText = `${lng},${lat}`;
        
        // Setup Date formats: 'YYYY-MM-DD HH:mm:ss'
        const actDate = new Date();
        const posDate = new Date(actDate.getTime() - 1000 * 60 * 60 * 24); // -1 day approx
        
        const formatDt = (dt) => {
            let m = dt.getMonth()+1; let d = dt.getDate();
            let hh = dt.getHours(); let mm = dt.getMinutes(); let ss = dt.getSeconds();
            return `${dt.getFullYear()}-${m<10?'0'+m:m}-${d<10?'0'+d:d} ${hh<10?'0'+hh:hh}:${mm<10?'0'+mm:mm}:${ss<10?'0'+ss:ss}`;
        };

        if (d_tiempo_act) d_tiempo_act.innerText = `${formatDt(actDate)}(Offline)`;
        if (d_tiempo_pos) d_tiempo_pos.innerText = formatDt(posDate);
        
        if (d_velocidad) d_velocidad.innerText = `0km/h(Señal:${40 + strHash}%)`;
        if (d_parada) d_parada.innerText = `1D${1+strHash}H${40+strHash}M`;
        if (d_km_total) d_km_total.innerText = `${280 + (strHash*2.5)}.3km`;
        if (d_km_hoy) d_km_hoy.innerText = `0km`;
        if (d_gas) d_gas.innerText = `T:${18+strHash}L/A:${18+strHash}L`;
        if (d_estado) d_estado.innerText = `ACC OFF 23M35S/Voltaje 23.0V`;
        
        if (d_direccion) d_direccion.innerText = `Vía a Rincón de Monagas, Maturín, Parroquia Las Cocuizas, Municipio Maturín`;
    };

    window.closeGpsModal = function () {
        const modal  = document.getElementById('gpsTrackerModal');
        const iframe = document.getElementById('gps_iframe');
        if (modal && modal.style.display === 'flex') {
            modal.style.display = 'none';
            document.body.style.overflow = '';
            if (iframe) iframe.src = 'about:blank';
        }
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.closeGpsModal();
    });
</script>
