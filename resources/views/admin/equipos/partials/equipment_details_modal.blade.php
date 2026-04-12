{{-- ════════════════════════════════════════════════════════
     MODAL DETALLES DE EQUIPO
     Estructura limpia: overlay > modal-content > header + sub-header + body
════════════════════════════════════════════════════════════ --}}
<div id="detailsModal" class="modal-overlay">
    <div class="modal-content" style="width: 90%; max-width: 400px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #f8fafc; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">

        {{-- ─── HEADER ────────────────────────────────────────────────── --}}
        <div style="background: var(--maquinaria-dark-blue); color: white;">

            {{-- Fila principal: título + GPS + cerrar --}}
            <div style="padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="display: flex; flex-direction: column; gap: 8px; flex: 1;">
                    <div>
                        <h2 id="modal_equipo_title" style="margin: 0; font-size: 17px; font-weight: 700;"></h2>
                        <p id="modal_equipo_subtitle" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px;"></p>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <button id="modal_gps_btn" type="button"
                            onclick="openGpsModal(this.dataset.url, this.dataset.equipoName)" data-url="" data-equipo-name=""
                            style="display: none; background: linear-gradient(135deg,#10b981,#059669); color: white; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; border: none; cursor: pointer; align-items: center; gap: 5px; transition: all 0.2s; box-shadow: 0 2px 8px rgba(16,185,129,0.35);"
                            onmouseover="this.style.transform='scale(1.04)'; this.style.boxShadow='0 4px 14px rgba(16,185,129,0.5)'"
                            onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 8px rgba(16,185,129,0.35)'">
                            <i class="material-icons" style="font-size: 15px; vertical-align: middle;">gps_fixed</i>
                            <span style="vertical-align: middle;">VER GPS EN VIVO</span>
                        </button>
                    </div>
                </div>
                <button type="button" onclick="closeDetailsModal(event)"
                    style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: default; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s; flex-shrink: 0;"
                    onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                    onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                    <i class="material-icons" style="font-size: 18px;">close</i>
                </button>
            </div>

            {{-- Fila secundaria: Ubicación Específica (Quick Edit) --}}
            <div style="background: #1e293b; padding: 6px 20px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <i class="material-icons" style="font-size: 14px; opacity: 0.65;">place</i>
                <span style="font-size: 10px; opacity: 0.65; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase;">Ubicación:</span>

                {{-- Modo lectura --}}
                <div id="ubicacion_display_wrapper" style="display: flex; align-items: center; gap: 6px;">
                    <span id="d_detalle_ubicacion" style="color: #ffffff; font-size: 13px; font-weight: 700; opacity: 0.95;">—</span>
                    <button type="button" id="btn_edit_ubicacion" title="Editar ubicación"
                        style="background: rgba(255,255,255,0.1); border: none; padding: 3px 6px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; color: rgba(255,255,255,0.6); transition: all 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)';this.style.color='white'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)';this.style.color='rgba(255,255,255,0.6)'"
                        onclick="startEditUbicacion()">
                        <i class="material-icons" style="font-size: 14px;">edit</i>
                    </button>
                </div>

                {{-- Modo edición --}}
                <div id="ubicacion_edit_wrapper" style="display: none; align-items: center; gap: 6px; flex: 1;">
                    <input type="text" id="input_ubicacion" maxlength="150"
                        style="flex: 1; min-width: 140px; padding: 2px 8px; border: 1px solid rgba(255,255,255,0.35); border-radius: 6px; font-size: 12px; color: #1e293b; outline: none; background: white;"
                        placeholder="Ej: Fase 2, Estacionamiento..."
                        onkeydown="if(event.key==='Enter') saveUbicacion(); if(event.key==='Escape') saveUbicacion();">
                    <button type="button" onclick="saveUbicacion()"
                        style="background: rgba(255,255,255,0.15); color: white; border: none; border-radius: 8px; padding: 4px 10px; font-size: 12px; cursor: pointer; transition: background 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.15)'"
                        title="Guardar y cerrar">
                        ✕
                    </button>
                </div>
            </div>

        </div>{{-- /HEADER --}}

        {{-- ─── BODY ──────────────────────────────────────────────────── --}}
        <div class="modal-body-scroll" style="padding: 25px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div style="display: flex; flex-direction: column; gap: 15px;">

                {{-- Sección 1: Documentación Legal --}}
                <details name="equipment_accordion" style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">description</i>
                        <span>Documentación Legal y Soportes</span>
                    </summary>
                    <div style="padding: 10px 16px; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 6px; font-size: 13px;">

                            <div class="detail-row-basic" style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:3px 0;">
                                <span style="color:#64748b;font-size:12px;white-space:nowrap;margin-top:1px;">Titular</span>
                                <span id="d_titular" style="color:#333;font-size:13px;text-align:right;word-wrap:break-word;overflow-wrap:break-word;line-height:1.3;flex:1;max-width:75%;"></span>
                            </div>

                            <div class="detail-row-basic" style="display:flex;align-items:center;justify-content:space-between;gap:4px;">
                                <span style="color:#64748b;font-size:12px;">Placa Identificadora</span>
                                <span id="d_placa" style="color:#333;font-size:13px;"></span>
                            </div>

                            <div class="detail-row-doc" style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">Nro. Documento</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_nro_doc" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_propiedad"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc" style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">Póliza de Seguro</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_venc_seguro" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_poliza"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc" style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">Registro ROTC</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_fecha_rotc" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_rotc"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc" style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-bottom:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;">Registro RACDA</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_fecha_racda" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_racda"></div>
                                </div>
                            </div>

                            <div class="detail-row-doc" style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;">
                                <span style="color:#64748b;font-size:12px;font-weight:500;">Documento Adicional</span>
                                <div id="d_btn_adicional"></div>
                            </div>

                        </div>
                    </div>
                </details>

                {{-- Sección 2: Información General --}}
                <details name="equipment_accordion" style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">info</i>
                        <span>Información General</span>
                    </summary>
                    <div style="padding: 20px; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 15px; font-size: 14px;">

                            {{-- Campos ocultos: ya aparecen en la tabla principal --}}
                            <span id="d_marca"        style="display:none;"></span>
                            <span id="d_modelo"       style="display:none;"></span>
                            <span id="d_motor_serial" style="display:none;"></span>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Año de Fabricación:</span>
                                <span id="d_anio" style="color: #333333;"></span>
                            </div>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Categoría de Flota:</span>
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

                {{-- Sección 3: Responsable Asignado --}}
                <details id="responsable_accordion" name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; display: none;">
                    <summary style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">person_pin</i>
                        <span>Responsable Asignado</span>
                    </summary>
                    <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 15px;">

                        {{-- Formulario para asignar nuevo responsable (oculto por defecto) --}}
                        <div id="responsable_form_container" style="display: none; flex-direction: column; gap: 8px; font-size: 13px; background: #f8fafc; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; max-width: 340px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">Cédula:</span>
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

                {{-- Sección 4: Sub-activos vinculados (oculta por defecto, se muestra via JS) --}}
                <details id="sa_accordion" name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; display: none;">
                    <summary style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">construction</i>
                        <span>Sub-activos vinculados</span>
                        <span id="sa_count_badge" style="margin-left: 6px; background: #475569; color: white; font-size: 11px; font-weight: 800; padding: 1px 8px; border-radius: 20px;">0</span>
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
</div>{{-- ════════════════════════════════════════════════════════
     MODAL GPS TRACKER — Premium Satellite View
════════════════════════════════════════════════════════════ --}}
<div id="gpsTrackerModal" style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.8); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px; font-family:'Nunito',sans-serif;">
    <div class="gps-modal-container" style="background:#ffffff; border-radius:16px; width:100%; max-width:1150px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 60px rgba(0,0,0,0.2); border:1px solid #e2e8f0;">

        {{-- Header --}}
        <div style="padding:14px 20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e2e8f0; background:#f8fafc; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="position:relative; width:36px; height:36px; flex-shrink:0;">
                    <div style="position:absolute; inset:4px; border-radius:50%; background:#10b981; display:flex; align-items:center; justify-content:center;">
                        <i class="material-icons" style="font-size:16px; color:white;">gps_fixed</i>
                    </div>
                </div>
                <div>
                    <div style="color:#1e293b; font-weight:800; font-size:15px; font-family:'Nunito',sans-serif;">Rastreo Satelital en Vivo</div>
                </div>
            </div>
            <button type="button" onclick="closeGpsModal()"
                style="background:#f1f5f9; border:1px solid #e2e8f0; color:#64748b; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; flex-shrink:0;"
                onmouseover="this.style.background='#fee2e2'; this.style.color='#ef4444'; this.style.borderColor='#fecaca'"
                onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'; this.style.borderColor='#e2e8f0'">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>

        {{-- Body: iframe GPS + panel info --}}
        <div class="gps-modal-body" style="flex:1; display:flex; min-height:540px; background:#0f172a; overflow:hidden;">

            {{-- Panel Izquierdo: iframe GPS real del equipo --}}
            <div class="gps-panel-map" style="position:relative; flex:1; overflow:hidden; background:#0f172a;">

                {{-- Spinner de carga --}}
                <div id="gps_loading_state" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; background:#0f172a; z-index:3;">
                    <div style="width:48px; height:48px; border:3px solid #334155; border-top-color:#10b981; border-radius:50%; animation:gps-spin 0.8s linear infinite;"></div>
                    <div style="color:#94a3b8; font-size:14px; font-weight:700;">Conectando con el dispositivo GPS...</div>
                </div>

                {{-- iframe con el tracking real de gps51 --}}
                <iframe id="gps_iframe"
                    src="about:blank"
                    style="position:absolute; inset:0; width:100%; height:100%; border:none; z-index:1;"
                    allowfullscreen
                    loading="lazy"
                    onload="document.getElementById('gps_loading_state').style.display='none';"
                ></iframe>

                {{-- Botón abrir en nueva pestaña (esquina inferior izquierda) --}}
                <a id="gps_open_link" href="#" target="_blank"
                    style="position:absolute; bottom:16px; left:16px; z-index:10; background:rgba(15,23,42,0.85); color:white; padding:7px 13px; border-radius:8px; font-size:11px; font-weight:700; text-decoration:none; display:flex; align-items:center; gap:6px; backdrop-filter:blur(4px); border:1px solid rgba(255,255,255,0.1); transition:all 0.2s;"
                    onmouseover="this.style.background='rgba(16,185,129,0.9)'"
                    onmouseout="this.style.background='rgba(15,23,42,0.85)'">
                    <i class="material-icons" style="font-size:15px;">open_in_new</i>
                    Abrir en GPS51
                </a>
            </div>

            {{-- Panel Derecho: Info del equipo --}}
            <div class="gps-panel-data" style="width:280px; background:#1e293b; border-left:1px solid #334155; padding:20px; display:flex; flex-direction:column; gap:16px; overflow-y:auto; flex-shrink:0;">

                <div style="color:#f8fafc; font-weight:800; font-size:13px; border-bottom:1px solid #334155; padding-bottom:10px; display:flex; align-items:center; gap:8px;">
                    <i class="material-icons" style="font-size:18px; color:#10b981;">gps_fixed</i>
                    Rastreo en Vivo
                </div>

                {{-- Equipo --}}
                <div style="background:#0f172a; border-radius:10px; padding:14px; border:1px solid #334155;">
                    <div style="font-size:10px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.6px; margin-bottom:4px;">Equipo</div>
                    <div id="scraped_device" style="font-size:13px; color:#f1f5f9; font-weight:700; line-height:1.4;">—</div>
                </div>

                {{-- Estado conexión --}}
                <div style="background:#0f172a; border-radius:10px; padding:14px; border:1px solid #334155;">
                    <div style="font-size:10px; color:#64748b; font-weight:700; text-transform:uppercase; letter-spacing:.6px; margin-bottom:6px;">Estado de Conexión</div>
                    <div style="display:flex; align-items:center; gap:8px;">
                        <span id="gps_status_dot" style="width:10px; height:10px; background:#10b981; border-radius:50%; display:inline-block; box-shadow:0 0 6px #10b981;"></span>
                        <span id="gps_status_text" style="font-size:13px; color:#10b981; font-weight:700;">Conectado</span>
                    </div>
                </div>

                {{-- Nota informativa --}}
                <div style="background:#0f172a; border-radius:10px; padding:14px; border:1px solid #1e3a5f;">
                    <div style="font-size:10px; color:#3b82f6; font-weight:700; text-transform:uppercase; letter-spacing:.6px; margin-bottom:6px; display:flex; align-items:center; gap:5px;">
                        <i class="material-icons" style="font-size:13px;">info</i> Información
                    </div>
                    <div style="font-size:11px; color:#94a3b8; line-height:1.5;">
                        La ubicación en vivo se muestra directamente desde la plataforma <strong style="color:#60a5fa;">GPS51</strong>. Cada equipo tiene su propio dispositivo y posición en tiempo real.
                    </div>
                </div>

                {{-- Botón ver en nueva pestaña (fallback) --}}
                <a id="gps_fallback_btn" href="#" target="_blank"
                    style="display:flex; align-items:center; justify-content:center; gap:8px; background:linear-gradient(135deg,#10b981,#059669); color:white; padding:11px; border-radius:10px; text-decoration:none; font-size:12px; font-weight:800; transition:all 0.2s; box-shadow:0 4px 12px rgba(16,185,129,0.3);"
                    onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 6px 18px rgba(16,185,129,0.45)'"
                    onmouseout="this.style.transform=''; this.style.boxShadow='0 4px 12px rgba(16,185,129,0.3)'">
                    <i class="material-icons" style="font-size:16px;">satellite_alt</i>
                    Ver GPS Completo
                </a>

            </div>
        </div>
    </div>
</div>

<style>
details[name="equipment_accordion"] summary { cursor: default; }
@keyframes gps-ping {
    0%   { transform:scale(1); opacity:0.7; }
    100% { transform:scale(2.2); opacity:0; }
}
@keyframes gps-spin {
    to { transform: rotate(360deg); }
}
@keyframes gps-ping-dot {
    0%, 100% { opacity:1; }
    50% { opacity:0.3; }
}

/* Responsive GPS Modal Stack */
@media (max-width: 768px) {
    #gpsTrackerModal { padding: 12px !important; }
    .gps-modal-container { max-height: 96vh !important; }
    .gps-modal-body { flex-direction: column !important; min-height: auto !important; height:100%; }
    .gps-panel-map { flex: none !important; height: 45vh !important; min-height: 280px !important; }
    .gps-panel-data { width: 100% !important; border-left: none !important; border-top: 1px solid #e2e8f0 !important; flex: 1 !important; max-height: calc(51vh - 60px) !important; padding: 15px !important; }
}
</style>

{{-- Leaflet eliminado — se usa iframe directo al GPS real del equipo --}}

<script>
window.openGpsModal = function(url, equipoName) {
    if (!url) return;

    const modal   = document.getElementById('gpsTrackerModal');
    const loading = document.getElementById('gps_loading_state');
    const iframe  = document.getElementById('gps_iframe');
    const openLink   = document.getElementById('gps_open_link');
    const fallbackBtn = document.getElementById('gps_fallback_btn');
    const deviceEl   = document.getElementById('scraped_device');

    // Mostrar nombre del equipo en el panel derecho
    const displayName = equipoName || 'Equipo Seleccionado';
    if (deviceEl) deviceEl.textContent = displayName;

    // Actualizar ambos botones de "Abrir en GPS51" con el link real
    if (openLink)    { openLink.href    = url; }
    if (fallbackBtn) { fallbackBtn.href = url; }

    // Mostrar spinner y ocultar modal hasta que cargue
    if (loading) loading.style.display = 'flex';
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Cargar el iframe con el link GPS REAL del equipo
    // Cada equipo tiene su propio authcode único en gps51.com
    if (iframe) {
        iframe.src = url;
    }
};

window.closeGpsModal = function() {
    const modal  = document.getElementById('gpsTrackerModal');
    const iframe = document.getElementById('gps_iframe');

    if (modal && modal.style.display === 'flex') {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        // Limpiar iframe al cerrar para detener cualquier conexión activa
        if (iframe) iframe.src = 'about:blank';
    }
};

// Cerrar con ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') window.closeGpsModal();
});
</script>



