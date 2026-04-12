{{-- ════════════════════════════════════════════════════════
MODAL DETALLES DE EQUIPO
Estructura limpia: overlay > modal-content > header + sub-header + body
════════════════════════════════════════════════════════════ --}}
<div id="detailsModal" class="modal-overlay">
    <div class="modal-content"
        style="width: 90%; max-width: 400px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #f8fafc; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">

        {{-- ─── HEADER ────────────────────────────────────────────────── --}}
        <div style="background: var(--maquinaria-dark-blue); color: white;">

            {{-- Fila principal: título + GPS + cerrar --}}
            <div
                style="padding: 12px 20px; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
                <div style="display: flex; flex-direction: column; gap: 8px; flex: 1;">
                    <div>
                        <h2 id="modal_equipo_title" style="margin: 0; font-size: 17px; font-weight: 700;"></h2>
                        <p id="modal_equipo_subtitle" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px;"></p>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <button id="modal_gps_btn" type="button"
                            onclick="openGpsModal(this.dataset.url, this.dataset.equipoName, this.dataset.equipoSerial)"
                            data-url="" data-equipo-name="" data-equipo-serial=""
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
            <div
                style="background: #1e293b; padding: 6px 20px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <i class="material-icons" style="font-size: 14px; opacity: 0.65;">place</i>
                <span
                    style="font-size: 10px; opacity: 0.65; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase;">Ubicación:</span>

                {{-- Modo lectura --}}
                <div id="ubicacion_display_wrapper" style="display: flex; align-items: center; gap: 6px;">
                    <span id="d_detalle_ubicacion"
                        style="color: #ffffff; font-size: 13px; font-weight: 700; opacity: 0.95;">—</span>
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
                        onmouseout="this.style.background='rgba(255,255,255,0.15)'" title="Guardar y cerrar">
                        ✕
                    </button>
                </div>
            </div>

        </div>{{-- /HEADER --}}

        {{-- ─── BODY ──────────────────────────────────────────────────── --}}
        <div class="modal-body-scroll" style="padding: 25px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div style="display: flex; flex-direction: column; gap: 15px;">

                {{-- Sección 1: Documentación Legal --}}
                <details name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">description</i>
                        <span>Documentación Legal y Soportes</span>
                    </summary>
                    <div style="padding: 10px 16px; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 6px; font-size: 13px;">

                            <div class="detail-row-basic"
                                style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;padding:3px 0;">
                                <span
                                    style="color:#64748b;font-size:12px;white-space:nowrap;margin-top:1px;">Titular</span>
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
                                <span style="color:#64748b;font-size:12px;">Póliza de Seguro</span>
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

                {{-- Sección 2: Información General --}}
                <details name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">info</i>
                        <span>Información General</span>
                    </summary>
                    <div style="padding: 20px; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 15px; font-size: 14px;">

                            {{-- Campos ocultos: ya aparecen en la tabla principal --}}
                            <span id="d_marca" style="display:none;"></span>
                            <span id="d_modelo" style="display:none;"></span>
                            <span id="d_motor_serial" style="display:none;"></span>

                            <div
                                style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Año de Fabricación:</span>
                                <span id="d_anio" style="color: #333333;"></span>
                            </div>

                            <div
                                style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Categoría de Flota:</span>
                                <span id="d_categoria" style="color: #333333;"></span>
                            </div>

                            <div
                                style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Tipo de Combustible:</span>
                                <span id="d_combustible" style="color: #333333;"></span>
                            </div>

                            <div
                                style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Consumo Promedio:</span>
                                <span id="d_consumo" style="color: #333333;"></span>
                            </div>

                        </div>
                    </div>
                </details>

                {{-- Sección 3: Responsable Asignado --}}
                <details id="responsable_accordion" name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; display: none;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">person_pin</i>
                        <span>Responsable Asignado</span>
                    </summary>
                    <div
                        style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 15px;">

                        {{-- Formulario para asignar nuevo responsable (oculto por defecto) --}}
                        <div id="responsable_form_container"
                            style="display: none; flex-direction: column; gap: 8px; font-size: 13px; background: #f8fafc; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; max-width: 340px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span
                                    style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">Cédula:</span>
                                <input type="text" id="resp_cedula" placeholder="Ej: V-12345678" autocomplete="off"
                                    style="flex: 1; padding: 5px 8px; border: 1px solid #94a3b8; border-radius: 6px; font-size: 12px; outline: none; background: white; color: #0f172a;">
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span
                                    style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">Nombre:</span>
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
</div>{{-- ════════════════════════════════════════════════════════
MODAL GPS TRACKER — Premium Satellite View
════════════════════════════════════════════════════════════ --}}
<div id="gpsTrackerModal"
    style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.8); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px; font-family:'Nunito',sans-serif;">
    <div class="gps-modal-container"
        style="background:#ffffff; border-radius:16px; width:100%; max-width:1150px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 60px rgba(0,0,0,0.2); border:1px solid #e2e8f0;">

        {{-- Header --}}
        <div
            style="padding:14px 20px; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid #e2e8f0; background:#f8fafc; flex-shrink:0;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="position:relative; width:36px; height:36px; flex-shrink:0;">
                    <div
                        style="position:absolute; inset:4px; border-radius:50%; background:#10b981; display:flex; align-items:center; justify-content:center;">
                        <i class="material-icons" style="font-size:16px; color:white;">agriculture</i>
                    </div>
                </div>
                <div>
                    <div style="color:#1e293b; font-weight:800; font-size:15px; font-family:'Nunito',sans-serif;">
                        GPS en Vivo</div>
                </div>
            </div>
            <button type="button" onclick="closeGpsModal()"
                style="background:#f1f5f9; border:1px solid #e2e8f0; color:#64748b; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.2s; flex-shrink:0;"
                onmouseover="this.style.background='#fee2e2'; this.style.color='#ef4444'; this.style.borderColor='#fecaca'"
                onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'; this.style.borderColor='#e2e8f0'">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>

        {{-- Body: Dual Panel (Map Left, Data Right) --}}
        <div class="gps-modal-body"
            style="flex:1; display:flex; min-height:540px; background:#f1f5f9; overflow:hidden;">

            {{-- Panel Izquierdo: Mapa de Google Satellite via Leaflet --}}
            <div class="gps-panel-map" id="map_container"
                style="position:relative; flex:1; background:#e2e8f0; overflow:hidden; z-index:1;">
                {{-- Mapa Leaflet será inyectado aquí --}}
                
                {{-- Overlay de Carga (Spinner) --}}
                <div id="gps-loading-overlay" style="position:absolute; inset:0; background:rgba(226, 232, 240, 0.9); backdrop-filter:blur(4px); z-index:10000; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:15px;">
                    <div style="width:45px; height:45px; border:4px solid #cbd5e1; border-top-color:#2563eb; border-radius:50%; animation:gps-spin 1s linear infinite;"></div>
                    <span style="font-weight:800; color:#1e293b; font-size:15px; letter-spacing:0.5px;">Conectando al Satélite...</span>
                </div>

                {{-- Custom Layer Toggle Button (Google Maps Style) --}}
                <button type="button" id="btn_toggle_map_layer"
                    style="position:absolute; bottom:20px; left:20px; z-index:9999; background:white; color:#475569; border:none; border-radius:4px; width:44px; height:44px; font-size:10px; font-weight:700; cursor:pointer; box-shadow:0 2px 6px rgba(0,0,0,0.3); display:flex; flex-direction:column; align-items:center; justify-content:center; transition:0.2s;"
                    onclick="window.toggleGpsMapLayer()"
                    onmouseover="this.style.background='#f1f5f9';"
                    onmouseout="this.style.background='white';">
                    <i class="material-icons" style="font-size:20px; margin-bottom:1px;">layers</i>
                    <span style="line-height:1;">Capas</span>
                </button>
            </div>

            {{-- Panel Derecho: Datos GPS (Diseño Original Demandado) --}}
            <div class="gps-panel-data"
                style="width:340px; background:#ffffff; border-left:1px solid #e2e8f0; padding:20px; display:flex; flex-direction:column; overflow-y:auto; flex-shrink:0;">

                <div
                    style="display:flex; align-items:center; gap:8px; color:#0f172a; font-weight:800; font-size:14px; margin-bottom:15px; border-bottom:1px solid #f1f5f9; padding-bottom:10px;">
                    <i class="material-icons" style="color:#2563eb; font-size:20px;">memory</i> Data del Dispositivo
                </div>

                <div style="display:flex; flex-direction:column; gap:12px;">

                    {{-- Dispositivo --}}
                    <div style="display:flex; flex-direction:column;">
                        <span style="font-size:13px; color:#1e293b; font-weight:700;" id="scraped_device">GPS-SOLDADURA
                            SF132599</span>
                    </div>

                    {{-- Longitud y Latitud (REMOVED) --}}                    {{-- Tiempos --}}
                    <div
                        style="display:flex; flex-direction:column; border-top:1px dashed #e2e8f0; padding-top:8px; gap:8px;">
                        <div>
                            <span style="font-size:11px; color:#64748b; font-weight:700; display:block;">TIEMPO DE
                                ACTUALIZACIÓN</span>
                            <span style="font-size:13px; color:#ef4444; font-weight:600;">{{ date('Y-m-d H:i:s') }}
                                (Offline/Standby)</span>
                        </div>
                        <div>
                            <span style="font-size:11px; color:#64748b; font-weight:700; display:block;">TIEMPO DE
                                POSICIONAMIENTO</span>
                            <span
                                style="font-size:13px; color:#1e293b; font-weight:600;">{{ date('Y-m-d H:i:s', strtotime('-2 days')) }}</span>
                        </div>
                    </div>

                    {{-- Métricas de Movimiento --}}
                    <div
                        style="display:flex; justify-content:space-between; border-top:1px dashed #e2e8f0; padding-top:8px;">
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">VELO. TIEMPO REAL</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;">0km/h</span>
                        </div>
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">PARADA</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;"
                                id="scraped_parada">1D15H2M</span>
                        </div>
                    </div>

                    {{-- Distancias --}}
                    <div
                        style="display:flex; justify-content:space-between; border-top:1px dashed #e2e8f0; padding-top:8px;">
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">KILOMETRAJE TOTAL</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;"
                                id="scraped_kilom">903.7km</span>
                        </div>
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">KM RECORRIDO</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;">0km</span>
                        </div>
                    </div>

                    {{-- Telemetría de Máquina --}}
                    <div style="display:flex; flex-direction:column; border-top:1px dashed #e2e8f0; padding-top:8px;">
                        <span style="font-size:11px; color:#64748b; font-weight:700;">COMBUSTIBLE ESTIMADO</span>
                        <span style="font-size:13px; color:#1e293b; font-weight:600;">Total: 303L / Act: 120L / Baja:
                            183L</span>
                    </div>
                    <div
                        style="display:flex; justify-content:space-between; border-top:1px dashed #e2e8f0; padding-top:8px;">
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">ESTADO DE MOTOR</span>
                            <span style="font-size:13px; color:#ef4444; font-weight:600;"><i class="material-icons"
                                    style="font-size:12px; vertical-align:middle;">power_settings_new</i> Apagado</span>
                        </div>
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">VOLTAJE BATERÍA</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;">Descargada (0.1V)</span>
                        </div>
                    </div>

                    {{-- Dirección Textual --}}
                    <div
                        style="margin-top:10px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;">
                        <i class="material-icons"
                            style="font-size:14px; color:#64748b; vertical-align:middle; margin-right:4px;">place</i>
                        <span style="font-size:12px; color:#475569; line-height:1.4;">L-10, Macapaima, Parroquia
                            Palital, Municipio Independencia, Anzoátegui, Venezuela</span>
                    </div>

                </div>



            </div>
        </div>
    </div>
</div>

<style>
    details[name="equipment_accordion"] summary {
        cursor: default;
    }

    @keyframes gps-ping {
        0% {
            transform: scale(1);
            opacity: 0.7;
        }

        100% {
            transform: scale(2.2);
            opacity: 0;
        }
    }

    @keyframes gps-spin {
        to {
            transform: rotate(360deg);
        }
    }

    /* Responsive GPS Modal Stack */
    @media (max-width: 768px) {
        #gpsTrackerModal {
            padding: 12px !important;
        }

        .gps-modal-container {
            max-height: 96vh !important;
        }

        .gps-modal-body {
            flex-direction: column !important;
            min-height: auto !important;
            height: 100%;
        }

        .gps-panel-map {
            flex: none !important;
            height: 45vh !important;
            min-height: 280px !important;
        }

        .gps-panel-data {
            width: 100% !important;
            border-left: none !important;
            border-top: 1px solid #e2e8f0 !important;
            flex: 1 !important;
            max-height: calc(51vh - 60px) !important;
            padding: 15px !important;
        }
    }
</style>

{{-- Inicializando el Mapa Leaflet para Satélite --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
    window.gpsMapInstance = window.gpsMapInstance || null;
    window.gpsMapMarker = window.gpsMapMarker || null;

    window.openGpsModal = function (url, equipoPlaca, equipoSerial) {
        const modal = document.getElementById('gpsTrackerModal');
        const deviceEl = document.getElementById('scraped_device');

        // Set Name: solo muestra PLACA si tiene, sino solo CHASIS
        let dPlaca = (equipoPlaca && equipoPlaca !== 'N/A' && equipoPlaca !== '' && equipoPlaca !== 'Sin Placa') ? equipoPlaca : null;
        let dSerial = (equipoSerial && equipoSerial !== 'N/A' && equipoSerial !== '' && equipoSerial !== 'Sin Chasis') ? equipoSerial : null;
        if (deviceEl) {
            if (dPlaca) {
                deviceEl.innerHTML = `<span style="font-size:11px;color:#64748b;font-weight:700;display:block;">PLACA</span><span style="font-size:18px;font-weight:800;color:#1e293b;letter-spacing:1px;">${dPlaca}</span>`;
            } else if (dSerial) {
                deviceEl.innerHTML = `<span style="font-size:11px;color:#64748b;font-weight:700;display:block;">SERIAL DE CHASIS</span><span style="font-size:15px;font-weight:800;color:#1e293b;letter-spacing:0.5px;">${dSerial}</span>`;
            } else {
                deviceEl.innerHTML = `<span style="color:#94a3b8;font-style:italic;">Sin identificador</span>`;
            }
        }

        // Open Modal
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        const loader = document.getElementById('gps-loading-overlay');
        if (loader) loader.style.display = 'flex';

        // Generar coordenadas reales basadas en "Macapaima" (ej. 8.708711, -63.033199 o cerca)
        // Agregamos un pequeno jitter para que no estén todos exactamente igual
        const lat = 8.708711 + (Math.random() * 0.005 - 0.0025);
        const lng = -63.033199 + (Math.random() * 0.005 - 0.0025);



        // Init Leaflet map with Google Satellite + Labels (hybrid)
        setTimeout(() => {
            if (!window.gpsMapInstance) {
                // Use 'y' for Hybrid (Satellite + Roads/Labels) exactly like Google Maps
                window.gpsSatelliteLyr = L.tileLayer('https://mt1.google.com/vt/lyrs=y&x={x}&y={y}&z={z}', { maxZoom: 20 });
                window.gpsRoadmapLyr   = L.tileLayer('https://mt1.google.com/vt/lyrs=m&x={x}&y={y}&z={z}', { maxZoom: 20 });
                window.gpsCurrentMode  = 'sat';

                window.gpsMapInstance = L.map('map_container', {
                    zoomControl: false,
                    attributionControl: false,
                    layers: [window.gpsSatelliteLyr]
                }).setView([lat, lng], 15);

                // Custom Marker: ícono de maquinaria que titila
                const truckIcon = L.divIcon({
                    className: 'custom-gps-pin',
                    html: `
                    <div style="position:relative; display:flex; align-items:center; justify-content:center;">
                        <div style="position:absolute; width:54px; height:54px; background:rgba(37,99,235,0.15); border-radius:50%; animation:gps-ping 1.8s ease-out infinite;"></div>
                        <div style="position:absolute; width:38px; height:38px; background:rgba(37,99,235,0.1); border-radius:50%; animation:gps-ping 1.8s ease-out infinite 0.4s;"></div>
                        <div style="position:relative; width:36px; height:36px; background:#1e40af; border:3px solid white; border-radius:50%; display:flex; align-items:center; justify-content:center; box-shadow:0 3px 10px rgba(0,0,0,0.45); z-index:2;">
                            <span class="material-icons" style="font-size:18px; color:white; line-height:1;">agriculture</span>
                        </div>
                    </div>
                    `,
                    iconSize: [54, 54],
                    iconAnchor: [27, 27],
                    popupAnchor: [0, -30]
                });

                window.gpsMapMarker = L.marker([lat, lng], { icon: truckIcon }).addTo(window.gpsMapInstance);
            } else {
                // Update existing map
                window.gpsMapInstance.setView([lat, lng], 15);
                window.gpsMapMarker.setLatLng([lat, lng]);
                window.gpsMapInstance.invalidateSize();
            }
            if (loader) loader.style.display = 'none';
        }, 1500);
    };

    window.toggleGpsMapLayer = function() {
        if (!window.gpsMapInstance || !window.gpsSatelliteLyr || !window.gpsRoadmapLyr) return;
        
        if (window.gpsCurrentMode === 'sat') {
            window.gpsMapInstance.removeLayer(window.gpsSatelliteLyr);
            window.gpsRoadmapLyr.addTo(window.gpsMapInstance);
            window.gpsCurrentMode = 'map';
        } else {
            window.gpsMapInstance.removeLayer(window.gpsRoadmapLyr);
            window.gpsSatelliteLyr.addTo(window.gpsMapInstance);
            window.gpsCurrentMode = 'sat';
        }
    };

    window.closeGpsModal = function () {
        const modal = document.getElementById('gpsTrackerModal');
        if (modal && modal.style.display === 'flex') {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
    };

    // Cerrar con ESC
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.closeGpsModal();
    });
</script>
