{{-- ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
     MODAL DETALLES DE EQUIPO
     Estructura limpia: overlay > modal-content > header + sub-header + body
ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ --}}
<div id="detailsModal" class="modal-overlay">
    <div class="modal-content" style="width: 90%; max-width: 400px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #f8fafc; margin: auto; max-height: 95vh; display: flex; flex-direction: column;">

        {{-- ÔöÇÔöÇÔöÇ HEADER ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ --}}
        <div style="background: var(--maquinaria-dark-blue); color: white;">

            {{-- Fila principal: t├¡tulo + GPS + cerrar --}}
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

            {{-- Fila secundaria: Ubicaci├│n Espec├¡fica (Quick Edit) --}}
            <div style="background: #1e293b; padding: 6px 20px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <i class="material-icons" style="font-size: 14px; opacity: 0.65;">place</i>
                <span style="font-size: 10px; opacity: 0.65; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase;">Ubicaci├│n:</span>

                {{-- Modo lectura --}}
                <div id="ubicacion_display_wrapper" style="display: flex; align-items: center; gap: 6px;">
                    <span id="d_detalle_ubicacion" style="color: #ffffff; font-size: 13px; font-weight: 700; opacity: 0.95;">ÔÇö</span>
                    <button type="button" id="btn_edit_ubicacion" title="Editar ubicaci├│n"
                        style="background: rgba(255,255,255,0.1); border: none; padding: 3px 6px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; color: rgba(255,255,255,0.6); transition: all 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)';this.style.color='white'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)';this.style.color='rgba(255,255,255,0.6)'"
                        onclick="startEditUbicacion()">
                        <i class="material-icons" style="font-size: 14px;">edit</i>
                    </button>
                </div>

                {{-- Modo edici├│n --}}
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
                        Ô£ò
                    </button>
                </div>
            </div>

        </div>{{-- /HEADER --}}

        {{-- ÔöÇÔöÇÔöÇ BODY ÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇÔöÇ --}}
        <div class="modal-body-scroll" style="padding: 25px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div style="display: flex; flex-direction: column; gap: 15px;">

                {{-- Secci├│n 1: Documentaci├│n Legal --}}
                <details name="equipment_accordion" style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">description</i>
                        <span>Documentaci├│n Legal y Soportes</span>
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
                                <span style="color:#64748b;font-size:12px;">P├│liza de Seguro</span>
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

                {{-- Secci├│n 2: Informaci├│n General --}}
                <details name="equipment_accordion" style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
                    <summary style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">info</i>
                        <span>Informaci├│n General</span>
                    </summary>
                    <div style="padding: 20px; border-top: 1px solid #e2e8f0;">
                        <div style="display: flex; flex-direction: column; gap: 15px; font-size: 14px;">

                            {{-- Campos ocultos: ya aparecen en la tabla principal --}}
                            <span id="d_marca"        style="display:none;"></span>
                            <span id="d_modelo"       style="display:none;"></span>
                            <span id="d_motor_serial" style="display:none;"></span>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">A├▒o de Fabricaci├│n:</span>
                                <span id="d_anio" style="color: #333333;"></span>
                            </div>

                            <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #f1f5f9; padding-bottom: 8px;">
                                <span style="color: #64748b;">Categor├¡a de Flota:</span>
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

                {{-- Secci├│n 3: Responsable Asignado --}}
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
                                <span style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">C├®dula:</span>
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

                {{-- Secci├│n 4: Sub-activos vinculados (oculta por defecto, se muestra via JS) --}}
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
</div>{{-- ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ
     MODAL GPS TRACKER ÔÇö Premium Satellite View
ÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉÔòÉ --}}
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

        {{-- Body: Dual Panel (Map Left, Data Right) --}}
        <div class="gps-modal-body" style="flex:1; display:flex; min-height:540px; background:#f1f5f9; overflow:hidden;">
            
            {{-- Panel Izquierdo: Mapa --}}
            <div class="gps-panel-map" style="position:relative; flex:1; background:#e2e8f0; overflow:hidden;">
                {{-- Spinner de carga --}}
                <div id="gps_loading_state" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; background:#f8fafc; z-index:2;">
                    <div style="width:48px; height:48px; border:3px solid #cbd5e0; border-top-color:#1e293b; border-radius:50%; animation:gps-spin 0.8s linear infinite;"></div>
                    <div style="color:#475569; font-size:14px; font-weight:700;">Conectando sat├®lite...</div>
                </div>

                {{-- Capa Leaflet nativa limpia (Reemplaza el iframe rebelde) --}}
                <div id="gps_map_container" style="position:absolute; inset:0; z-index:1;"></div>

                {{-- Bot├│n Miniatura de Capas (Estilo Google Maps Moderno) --}}
                <div id="btn_toggle_layer" onclick="toggleCapaGPS()" style="position:absolute; bottom:24px; left:24px; z-index:10; width:48px; height:48px; border-radius:8px; background-image:url('https://mt0.google.com/vt/lyrs=m&hl=es&x=37&y=62&z=7'); background-size:cover; border:2px solid white; box-shadow:0 1px 4px rgba(0,0,0,0.4); overflow:hidden; transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'" title="Cambiar vista">
                    <div id="btn_toggle_text" style="position:absolute; bottom:0; padding:2px 0; width:100%; background:rgba(255,255,255,0.85); color:#1e293b; font-size:9px; font-weight:600; text-align:center; font-family:Roboto, Arial, sans-serif;">Mapa</div>
                </div>
            </div>

            {{-- Panel Derecho: Datos GPS --}}
            <div class="gps-panel-data" style="width:340px; background:#ffffff; border-left:1px solid #e2e8f0; padding:20px; display:flex; flex-direction:column; overflow-y:auto; flex-shrink:0;">
                
                <div style="color:#0f172a; font-weight:800; font-size:14px; margin-bottom:12px; border-bottom:1px solid #f1f5f9; padding-bottom:6px;">
                    Telemetr├¡a del Veh├¡culo
                </div>

                <div style="display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; flex-direction:column;">
                        <span style="font-size:11px; color:#64748b; font-weight:700;">EQUIPO</span>
                        <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_device">Cargando...</span>
                    </div>
                    <div style="display:flex; flex-direction:column; border-top:1px dashed #e2e8f0; margin-top:2px; padding-top:6px;">
                        <span style="font-size:11px; color:#64748b; font-weight:700;">LATITUD Y LONGITUD</span>
                        <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_coords">-- , --</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; border-top:1px dashed #e2e8f0; margin-top:2px; padding-top:6px;">
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">ACTUALIZACI├ôN</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_actualizacion">--</span>
                        </div>
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">POSICIONAMIENTO</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_posicion">--</span>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between; border-top:1px dashed #e2e8f0; margin-top:2px; padding-top:6px;">
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">VELOCIDAD REAL</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_speed">-- km/h</span>
                        </div>
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">PARADA</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_parada">--</span>
                        </div>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">KILOMETRAJE</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_kilom">-- km</span>
                        </div>
                        <div style="display:flex; flex-direction:column; width:48%;">
                            <span style="font-size:11px; color:#64748b; font-weight:700;">KM TOTAL</span>
                            <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_kmtot">-- km</span>
                        </div>
                    </div>
                    <div style="display:flex; flex-direction:column; border-top:1px dashed #e2e8f0; margin-top:2px; padding-top:6px;">
                        <span style="font-size:11px; color:#64748b; font-weight:700;">ESTADO MOTOR Y BATER├ìA</span>
                        <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_acc">--</span>
                    </div>
                    <div style="display:flex; flex-direction:column;">
                        <span style="font-size:11px; color:#64748b; font-weight:700;">COMBUSTIBLE</span>
                        <span style="font-size:13px; color:#1e293b; font-weight:600;" id="scraped_combustible">-- / --</span>
                    </div>
                </div>
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

{{-- LIBRER├ìAS DE MAPAS LIGERAS (LEAFLET) PARA EVITAR LIMITACIONES DEL IFRAME DE GOOGLE --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
var gpsMapInstance = null;
var gpsMarker = null;
var capaSatelite = null;
var capaMapa = null;
window.gpsModoActual = 'y'; // Por defecto Sat├®lite

window.toggleCapaGPS = function() {
    if(!gpsMapInstance) return;
    
    const btnContainer = document.getElementById('btn_toggle_layer');
    const btnText = document.getElementById('btn_toggle_text');
    
    if(window.gpsModoActual === 'y') {
        // Est├íbamos en Sat├®lite, pasamos a Mapa. El bot├│n mostrar├í miniatura del Sat├®lite.
        gpsMapInstance.addLayer(capaMapa);
        gpsMapInstance.removeLayer(capaSatelite);
        window.gpsModoActual = 'm';
        
        btnContainer.style.backgroundImage = "url('https://mt0.google.com/vt/lyrs=s&hl=es&x=37&y=62&z=7')";
        btnText.textContent = "Sat├®lite";
        btnText.style.background = "rgba(0,0,0,0.5)";
        btnText.style.color = "white";
    } else {
        // Est├íbamos en Mapa, pasamos a Sat├®lite. El bot├│n mostrar├í miniatura del Mapa.
        gpsMapInstance.addLayer(capaSatelite);
        gpsMapInstance.removeLayer(capaMapa);
        window.gpsModoActual = 'y';
        
        btnContainer.style.backgroundImage = "url('https://mt0.google.com/vt/lyrs=m&hl=es&x=37&y=62&z=7')";
        btnText.textContent = "Mapa";
        btnText.style.background = "rgba(255,255,255,0.85)";
        btnText.style.color = "#1e293b";
    }
};

window.openGpsModal = function(url, equipoName) {
    if (!url) return;

    const modal   = document.getElementById('gpsTrackerModal');
    const loading = document.getElementById('gps_loading_state');

    // UI Elements del panel lateral
    let displayName = equipoName || 'Equipo Seleccionado';
    // Si displayName ya empieza por "Serial:" o "Placa:", evitar repetirlo
    let prefix = (displayName.toLowerCase().includes('serial:') || displayName.toLowerCase().includes('placa:')) ? '' : 'Dato: ';
    
    document.getElementById('scraped_device').textContent = prefix + displayName + ' | En L\u00ednea';
    document.getElementById('scraped_coords').textContent = '-- , --';
    document.getElementById('scraped_actualizacion').textContent = '...';
    document.getElementById('scraped_posicion').textContent = '...';
    document.getElementById('scraped_speed').textContent = '-- km/h';
    document.getElementById('scraped_parada').textContent = '--';
    document.getElementById('scraped_kilom').textContent = '--';
    document.getElementById('scraped_kmtot').textContent = '--';
    document.getElementById('scraped_acc').textContent = 'Verificando...';
    document.getElementById('scraped_combustible').textContent = 'Calculando...';

    const globalLoader = document.getElementById('preloader');
    if (globalLoader) globalLoader.style.display = 'flex';

    loading.style.display = 'flex';

    // SIMULACI├ôN DE SCRAPER (Carga R├ípida Optimizada)
    setTimeout(() => {
        // En etapa final, esto vendr├í del ScraperController leyendo gps51 directamente
        document.getElementById('scraped_device').textContent = prefix + displayName + ' | Conectado';
        document.getElementById('scraped_coords').textContent = '-64.234510, 8.918608';
        
        let d = new Date();
        document.getElementById('scraped_actualizacion').textContent = d.toLocaleString() + ' (Offline)';
        document.getElementById('scraped_posicion').textContent = d.toLocaleString();
        
        document.getElementById('scraped_speed').textContent = '0 km/h (Se├▒al:88%)';
        document.getElementById('scraped_parada').textContent = '1D 12H 12M';
        document.getElementById('scraped_kilom').textContent = '30334.4 km';
        document.getElementById('scraped_kmtot').textContent = '0 km';
        document.getElementById('scraped_acc').textContent = 'ACC OFF 1D12H3M / Voltaje 25.0V';
        document.getElementById('scraped_combustible').textContent = 'Total: 662L (Tanque A: 542L | Tanque B: 120L)';

        // Renderizar Mapa Limpio Leaflet (+Tiles H├¡bridos Google)
        const lat = 8.918608;
        const lng = -64.234510;
        
        if (!gpsMapInstance) {
            gpsMapInstance = L.map('gps_map_container', {
                zoomControl: false,       // Quitar botones gigantes
                attributionControl: false,// Quitar barras inferiores
                doubleClickZoom: false
            }).setView([lat, lng], 17);

            // lyrs=y es H├¡brido (Sat├®lite + Calles)
            capaSatelite = L.tileLayer('https://mt0.google.com/vt/lyrs=y&hl=es&x={x}&y={y}&z={z}');
            // lyrs=m es Mapa est├índar
            capaMapa = L.tileLayer('https://mt0.google.com/vt/lyrs=m&hl=es&x={x}&y={y}&z={z}');

            capaSatelite.addTo(gpsMapInstance); // Sat├®lite por default
            
            // Detectar si es Camioneta, Maquinaria, o Cami├│n seg├║n d_categoria
            let sysIcon = 'local_shipping'; // Por default: chuto cami├│n
            const cText = document.getElementById('d_categoria').textContent.toLowerCase();
            if(cText.includes('camioneta') || cText.includes('liviano') || cText.includes('r├║stico') || cText.includes('auto')) {
                sysIcon = 'directions_car';
            } else if(cText.includes('maquinaria') || cText.includes('excavador') || cText.includes('pesad')) {
                sysIcon = 'agriculture';
            }

            // Ping Marker Din├ímico
            const iconHtml = `<div style="width:34px; height:34px; background:#1e293b; border:2px solid white; border-radius:50%; box-shadow:0 3px 10px rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; color:white;">
                                <i class="material-icons" style="font-size:20px;">${sysIcon}</i>
                              </div>`;
            const customIcon = L.divIcon({ html: iconHtml, className: '', iconSize: [34, 34], iconAnchor: [17, 17] });
            
            gpsMarker = L.marker([lat, lng], {icon: customIcon}).addTo(gpsMapInstance);
        } else {
            // Actualizar vista si ya exist├¡a
            gpsMapInstance.setView([lat, lng], 17);
            gpsMarker.setLatLng([lat, lng]);
            setTimeout(() => gpsMapInstance.invalidateSize(), 300); // refresh layout
        }

        loading.style.display = 'none';
        
        // Simulaci├│n: Apagar spinner blanco global y MOSTRAR modal
        if (globalLoader) globalLoader.style.display = 'none';
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        
        // Forzar actualizaci├│n del canvas del mapa una vez visible el modal
        setTimeout(() => { if(gpsMapInstance) gpsMapInstance.invalidateSize(); }, 400);

    }, 800); // 800ms de simulacion del scraper
};

window.closeGpsModal = function() {
    const modal = document.getElementById('gpsTrackerModal');
    if (modal && modal.style.display === 'flex') {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
};

// Solo cerrar con ESC ÔÇö NO al clickear afuera
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') window.closeGpsModal();
});
</script>



