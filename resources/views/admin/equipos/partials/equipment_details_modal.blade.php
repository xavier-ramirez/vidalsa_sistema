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
                <div style="display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 0;">
                    <div>
                        <h2 id="modal_equipo_title" style="margin: 0; font-size: 14px; font-weight: 700; word-break: break-word; line-height: 1.2;"></h2>
                        <p id="modal_equipo_subtitle" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px; word-break: break-word;"></p>
                    </div>
                    <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                        <button id="modal_gps_btn" type="button"
                            onclick="openGpsModal(this.dataset.url, this.dataset.equipoName, this.dataset.equipoSerial, this.dataset.equipoTipo)"
                            data-url="" data-equipo-name="" data-equipo-serial="" data-equipo-tipo=""
                            style="display: none; background: linear-gradient(135deg,#10b981,#059669); color: white; padding: 6px 14px; border-radius: 8px; font-size: 11px; font-weight: 700; border: none; cursor: default; align-items: center; gap: 5px; transition: all 0.2s; box-shadow: 0 2px 8px rgba(16,185,129,0.35);"
                            onmouseover="this.style.transform='scale(1.04)'; this.style.boxShadow='0 4px 14px rgba(16,185,129,0.5)'"
                            onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 2px 8px rgba(16,185,129,0.35)'">
                            <i class="material-icons" style="font-size: 15px; vertical-align: middle;">gps_fixed</i>
                            <span style="vertical-align: middle;">VER GPS EN VIVO</span>
                        </button>
                        

                    </div>
                </div>
                <div style="display: flex; gap: 6px; flex-shrink: 0;">
                    {{-- Confirmar presencia en sitio: estado e id los setea showDetailsImproved.
                         Mismo toggle que el chip de la lista (window.toggleConfirmacionSitio). --}}
                    @can('equipos.edit')
                    <button type="button" id="btn_confirmar_sitio_modal" data-equipo-id="" data-confirmado="0"
                        onclick="window.toggleConfirmacionSitio(this)" title="Confirmar presencia en sitio"
                        style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: default; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        <i class="material-icons" style="font-size: 18px;">radio_button_unchecked</i>
                    </button>
                    @endcan
                    @can('user.edit')
                    <button type="button" id="btn_edit_equipo_detalles" title="Editar datos del equipo"
                        onclick="editEquipoFromDetails(event)"
                        style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: default; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        <i class="material-icons" style="font-size: 17px;">edit</i>
                    </button>
                    @endcan
                    <button type="button" onclick="closeDetailsModal(event)"
                        style="background: rgba(255,255,255,0.1); border: none; color: white; cursor: default; border-radius: 50%; width: 30px; height: 30px; display: flex; align-items: center; justify-content: center; transition: 0.2s;"
                        onmouseover="this.style.background='rgba(255,255,255,0.2)'"
                        onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        <i class="material-icons" style="font-size: 18px;">close</i>
                    </button>
                </div>
            </div>

            {{-- Bloque "Ubicación Específica (Quick Edit)" removido por solicitud del usuario. --}}

        </div>{{-- /HEADER --}}

        {{-- BODY --}}
        <div class="modal-body-scroll" style="padding: 25px; max-height: 80vh; overflow-y: auto; overflow-x: hidden;">
            <div style="display: flex; flex-direction: column; gap: 15px;">

                {{-- Documentacion Legal --}}
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

                            {{-- Certificado Asociado: SOLO FLOTA LIVIANA --}}
                            <div id="d_row_adicional" class="detail-row-doc"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;">
                                <span id="d_label_adicional" style="color:#64748b;font-size:12px;font-weight:500;">Certificado Asociado</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_fecha_adicional" style="color:#333;font-size:13px;"></span>
                                    <div id="d_btn_adicional"></div>
                                </div>
                            </div>

                            {{-- Compraventa: NO tiene fecha de vencimiento --}}
                            <div id="d_row_adicional_2" class="detail-row-doc"
                                style="display:flex;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;">
                                <span id="d_label_adicional_2" style="color:#64748b;font-size:12px;font-weight:500;">Compraventa</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <div id="d_btn_adicional_2"></div>
                                </div>
                            </div>

                        </div>
                    </div>
                </details>

                {{-- Sub-activos vinculados (auxiliares) — colocado JUSTO debajo de "Documentación
                     Legal y Soportes" (antes iba al final del modal). --}}
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

                {{-- Informacion General --}}
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

                {{-- Responsable Asignado --}}
                <details id="responsable_accordion" name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; display: none; position: relative;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">person_pin</i>
                        <span>Responsable Asignado</span>
                    </summary>
                    {{-- Boton lapiz para registrar nuevo responsable.
                         Solo visible con permiso user.edit (escritura). Los
                         usuarios sin el permiso ven el historial en modo lectura.
                         Sta FUERA de <summary> porque HTML prohibe elementos
                         interactivos como descendientes de summary (accesibilidad). --}}
                    @can('user.edit')
                    <button type="button" id="responsable_edit_pencil_header" title="Registrar nuevo responsable"
                        onclick="const f=document.getElementById('responsable_form_container'); if(f){f.style.display='flex'; const n=document.getElementById('resp_nombre'); if(n) n.focus();}"
                        style="position:absolute; top:12px; right:16px; z-index:2; background:#f1f5f9; border:1px solid #cbd5e1; color:#475569; width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; cursor:default; transition:all 0.15s;"
                        onmouseover="this.style.background='#e2e8f0'; this.style.color='#1e293b'"
                        onmouseout="this.style.background='#f1f5f9'; this.style.color='#475569'">
                        <i class="material-icons" style="font-size: 16px;">edit</i>
                    </button>
                    @endcan
                    <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 15px;">

                        @can('user.edit')
                        {{-- Formulario para asignar nuevo responsable (oculto por defecto) --}}
                        <div id="responsable_form_container"
                            style="display: none; flex-direction: column; gap: 8px; font-size: 13px; background: #f8fafc; padding: 10px 12px; border-radius: 8px; border: 1px solid #e2e8f0; max-width: 340px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">Nombre:</span>
                                <input type="text" id="resp_nombre" placeholder="Nombre completo" autocomplete="off"
                                    style="flex: 1; padding: 5px 8px; border: 1px solid #94a3b8; border-radius: 6px; font-size: 12px; outline: none; background: white; color: #0f172a;">
                            </div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="color: #475569; font-weight: 600; white-space: nowrap; min-width: 90px;">C&eacute;dula:</span>
                                <input type="text" id="resp_cedula" placeholder="Ej: V-12345678" autocomplete="off"
                                    style="flex: 1; padding: 5px 8px; border: 1px solid #94a3b8; border-radius: 6px; font-size: 12px; outline: none; background: white; color: #0f172a;">
                            </div>
                        </div>
                        @endcan

                        {{-- Lista de responsables (historial) --}}
                        <div id="responsable_list" style="display: flex; flex-direction: column; gap: 8px;">
                            {{-- Llenado por JS --}}
                        </div>
                    </div>
                </details>

                {{-- Equipo Anclado (REMOLCADOR/REMOLCABLE).
                     Solo visible si el equipo tiene ID_ANCLAJE. La poblacion la
                     hace fillEquipoAnclajeSection() en uicomponents.js. --}}
                <details id="anclaje_accordion" name="equipment_accordion"
                    style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; display: none;">
                    <summary
                        style="padding: 15px 20px; font-weight: 700; color: #1e293b; display: flex; align-items: center; gap: 10px; background: #f8fafc; list-style: none;">
                        <i class="material-icons" style="font-size: 20px; color: #64748b;">link</i>
                        <span>Equipo Anclado</span>
                    </summary>
                    <div style="padding: 16px 20px; border-top: 1px solid #e2e8f0;">
                        <div id="anclaje_card" style="display:flex; align-items:center; gap:12px; padding:12px 14px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0;">
                            {{-- Llenado por JS --}}
                        </div>
                    </div>
                </details>

                {{-- Movilizaciones del equipo.
                     BOTON y no un <details> como los bloques de arriba a proposito: los
                     acordeones pintan datos que YA vienen en la fila de la tabla, mientras
                     que esto exige ir a la BD. Con un <details> la consulta saldria (o el
                     bloque quedaria vacio) cada vez que se abre un equipo, aunque nadie
                     mire el historial; con el boton solo se paga cuando se pide.
                     El id del equipo lo pone showDetailsImproved en data-equipo-id. --}}
                <button type="button" id="btn_ver_movilizaciones" data-equipo-id=""
                    onclick="window.abrirMovilizacionesEquipo(this.dataset.equipoId)"
                    {{-- Mismos valores que la cabecera de los acordeones de arriba (padding,
                         fondo, borde, radio, tipografia y el icono suelto de 20px en #64748b),
                         para que se lea como uno mas de la lista y no como un boton aparte.
                         Sin chevron: ninguno de los otros lo lleva.
                         En TELEFONO ese "igual que los acordeones" lo sostiene el bloque
                         @media de estilos_globales.css, donde este id comparte selector con
                         #detailsModal details>summary. Si se cambia el estilo de aqui, hay
                         que mirar alla: por ser <button> y no <summary>, es facil que se
                         quede fuera y vuelva a verse mas grande que el resto en movil. --}}
                    style="background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; padding: 15px 20px; width: 100%; box-sizing: border-box; display: flex; align-items: center; gap: 10px; font-family: inherit; font-size: inherit; font-weight: 700; color: #1e293b; text-align: left; cursor: pointer;">
                    <i class="material-icons" style="font-size: 20px; color: #64748b;">local_shipping</i>
                    <span>Movilizaciones</span>
                </button>

            </div>
        </div>{{-- /BODY --}}

    </div>{{-- /modal-content --}}
</div>{{-- /detailsModal --}}

{{-- ═══════════════════════════════════════════════════════════
MODAL MOVILIZACIONES DEL EQUIPO
Lo abre el boton "Movilizaciones" del modal de detalles. Los datos se piden a
equipos.movilizaciones al pulsar el boton (nunca al abrir el detalle) y los pinta el
bloque "MODAL MOVILIZACIONES DEL EQUIPO" del final de uicomponents.js.

z-index 10002: uicomponents.js sube #detailsModal a 10000 al abrirlo, y el visor de
PDF (.modal-overlay-front) usa 10001. Este sale DESDE detalles, asi que tiene que
taparlos a los dos. Sigue por debajo de #standardModal (1000001), que debe poder
taparlo todo. NO se hereda el 2000 de .modal-overlay: quedaria por detras.
═══════════════════════════════════════════════════════════════ --}}
<div id="movilizacionesModal" class="modal-overlay" style="z-index: 10002;">
    <div class="modal-content"
        {{-- max-height en dvh, no vh: en telefono vh NO descuenta la barra de URL, asi que
             el modal quedaba mas alto que lo que se ve y el final de la lista (con el aviso
             de "mostrando las N mas recientes") caia debajo del borde. Se deja 90vh delante
             como respaldo para navegadores sin dvh. Es la misma unidad que usan las reglas
             mobile de #detailsModal, de donde sale este modal. --}}
        style="width: 90%; max-width: 460px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden; background: #f8fafc; margin: auto; max-height: 90vh; max-height: 90dvh; display: flex; flex-direction: column;">

        {{-- HEADER --}}
        <div style="background: var(--maquinaria-dark-blue); color: white; padding: 14px 18px; display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
            <i class="material-icons" style="font-size: 20px;">local_shipping</i>
            <div style="min-width: 0; flex: 1;">
                <h2 style="margin: 0; font-size: 14px; font-weight: 700; line-height: 1.2;">Movilizaciones</h2>
                <p id="mov_subtitulo" style="margin: 2px 0 0 0; opacity: 0.8; font-size: 12px; word-break: break-word;"></p>
            </div>
            <button type="button" onclick="window.cerrarMovilizacionesEquipo()" title="Cerrar"
                style="background: transparent; border: none; color: white; cursor: pointer; display: flex; align-items: center; padding: 4px;">
                <i class="material-icons" style="font-size: 22px;">close</i>
            </button>
        </div>

        {{-- BODY: los cuatro estados son EXCLUYENTES (cargando / error / vacio / lista).
             Los pinta y los alterna el bloque "MODAL MOVILIZACIONES DEL EQUIPO" del
             final de uicomponents.js; aqui solo se declaran. --}}
        <div style="padding: 16px 18px; overflow-y: auto; flex: 1;">

            {{-- Cargando --}}
            <div id="mov_cargando" style="display: none; flex-direction: column; align-items: center; gap: 12px; padding: 34px 0;">
                {{-- .spinner-mini ya existe en estilos_globales.css; no se declara otro. --}}
                <div class="spinner-mini"></div>
                <span style="color: #64748b; font-size: 13px;">Cargando movilizaciones&hellip;</span>
            </div>

            {{-- Error --}}
            <div id="mov_error" style="display: none; flex-direction: column; align-items: center; gap: 10px; padding: 26px 0; text-align: center;">
                <i class="material-icons" style="font-size: 34px; color: #ef4444;">error_outline</i>
                <span id="mov_error_texto" style="color: #64748b; font-size: 13px;"></span>
                <button type="button" id="mov_reintentar"
                    style="margin-top: 4px; background: #f1f5f9; border: 1px solid #cbd5e1; color: #1e293b; padding: 7px 16px; border-radius: 8px; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer;">
                    Reintentar
                </button>
            </div>

            {{-- Sin movilizaciones --}}
            <div id="mov_vacio" style="display: none; flex-direction: column; align-items: center; gap: 10px; padding: 30px 0; text-align: center;">
                <i class="material-icons" style="font-size: 34px; color: #cbd5e1;">inbox</i>
                <span style="color: #64748b; font-size: 13px;">Este equipo no tiene movilizaciones registradas.</span>
            </div>

            {{-- Lista --}}
            <div id="mov_lista" style="display: none; flex-direction: column; gap: 8px;">
                {{-- Llenado por JS --}}
            </div>

            {{-- Aviso de recorte: solo si el backend informa hay_mas --}}
            <p id="mov_truncado" style="display: none; margin: 12px 0 0 0; font-size: 12px; color: #64748b; text-align: center;"></p>

        </div>
    </div>{{-- /modal-content --}}
</div>{{-- /movilizacionesModal --}}

{{-- ═══════════════════════════════════════════════════════════
MODAL GPS TRACKER — Rastreo Satelital en Vivo
═══════════════════════════════════════════════════════════════ --}}
<div id="gpsTrackerModal"
    style="display:none; position:fixed; inset:0; z-index:99999; background:rgba(15,23,42,0.8); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px; font-family:'Nunito',sans-serif;">
    <div class="gps-modal-container"
        style="background:#ffffff; border-radius:16px; width:100%; max-width:1150px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 60px rgba(0,0,0,0.2); border:1px solid #e2e8f0;">

        {{-- Header GPS --}}
        <div style="padding:14px 20px; display:flex; align-items:flex-start; justify-content:space-between; border-bottom:1px solid #e2e8f0; background:#f8fafc; flex-shrink:0;">
            <div style="display:flex; align-items:flex-start; gap:12px; flex:1; min-width:0; padding-right:10px;">
                <div style="width:36px; height:36px; border-radius:50%; background:#10b981; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i class="material-icons" style="font-size:18px; color:white;">gps_fixed</i>
                </div>
                <div style="display:flex; flex-direction:column; gap:2px; min-width:0; flex:1;">
                    <span style="color:#64748b; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px;">Rastreo Satelital en Vivo</span>
                    <span id="gps_equipo_title" style="color:#1e293b; font-weight:800; font-size:15px; word-break:break-word; max-width:100%; line-height:1.2;">&mdash;</span>
                </div>
            </div>
            <button type="button" onclick="closeGpsModal()"
                style="background:#f1f5f9; border:1px solid #e2e8f0; color:#64748b; width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:default; transition:all 0.2s; flex-shrink:0;"
                onmouseover="this.style.background='#fee2e2'; this.style.color='#ef4444'; this.style.borderColor='#fecaca'"
                onmouseout="this.style.background='#f1f5f9'; this.style.color='#64748b'; this.style.borderColor='#e2e8f0'">
                <i class="material-icons" style="font-size:18px;">close</i>
            </button>
        </div>

        {{-- iframe GPS --}}
        <div class="gps-panel-map" style="flex:1; min-height:540px; overflow:hidden; position:relative;">
            <div id="gps-loading-overlay"
                style="position:absolute; inset:0; background:white; z-index:10; display:flex; align-items:center; justify-content:center;">
                <div class="spinner-circle"></div>
            </div>
            {{-- Solo se usa 'allow="...fullscreen..."'; 'allowfullscreen' es
                 redundante y dispara warning en consola ("Allow attribute will
                 take precedence over allowfullscreen"). --}}
            <iframe id="gps_iframe" src="about:blank"
                style="width:100%; height:100%; border:none; display:none; min-height:540px; opacity:0; transition:opacity 0.35s ease-in;"
                allow="geolocation; fullscreen"
                onload="if (window.handleGpsIframeLoad) window.handleGpsIframeLoad(this);"></iframe>
        </div>
    </div>
</div>

<style>
    details[name="equipment_accordion"] summary { cursor: default; }

    @keyframes gps-spin {
        to { transform: rotate(360deg); }
    }

    @media (max-width: 768px) {
        #gpsTrackerModal { padding: 12px !important; }
        .gps-modal-container { max-height: 96vh !important; }
        .gps-panel-map { height: 80vh !important; min-height: 350px !important; }
    }
</style>

<script>
// Guard: el SPA router re-ejecuta los <script> inline al navegar. Sin este wrap
// las `const` top-level revientan con "already declared" en la segunda carga.
if (!window._gpsModalScriptLoaded) {
    window._gpsModalScriptLoaded = true;
    (function () {
        // Portal GPS51 es cross-origin: iframe.onload dispara al cargar el documento raíz
        // pero su JS de tiles y mapa siguen renderizando por 1-2s. Mantenemos el spinner
        // visible un extra para cubrir ese render, luego fade-in del iframe.
        var GPS_IFRAME_EXTRA_DELAY_MS = 1400;

        window.handleGpsIframeLoad = function (iframe) {
            var overlay = document.getElementById('gps-loading-overlay');
            if (!iframe || iframe.src === 'about:blank') return;
            setTimeout(function () {
                // Si el usuario cerró el modal durante el delay, no revelar el iframe.
                var modal = document.getElementById('gpsTrackerModal');
                if (!modal || modal.style.display !== 'flex') return;
                if (overlay) overlay.style.display = 'none';
                iframe.style.display = 'block';
                requestAnimationFrame(function () { iframe.style.opacity = '1'; });
            }, GPS_IFRAME_EXTRA_DELAY_MS);
        };

        window.openGpsModal = function (url, equipoPlaca, equipoSerial, equipoTipo) {
            if (window.showPreloader) window.showPreloader();

            var modal   = document.getElementById('gpsTrackerModal');
            var titleEl = document.getElementById('gps_equipo_title');
            var iframe  = document.getElementById('gps_iframe');
            var overlay = document.getElementById('gps-loading-overlay');

            var dTipo   = (equipoTipo   && equipoTipo   !== 'null' && equipoTipo   !== '') ? equipoTipo.toUpperCase() : null;
            var dPlaca  = (equipoPlaca  && equipoPlaca  !== 'N/A'  && equipoPlaca  !== 'Sin Placa')  ? equipoPlaca  : null;
            var dSerial = (equipoSerial && equipoSerial !== 'N/A'  && equipoSerial !== 'Sin Chasis') ? equipoSerial : null;

            if (titleEl) {
                var parts = [];
                if (dTipo)        parts.push('<span style="font-weight:800;color:#1e293b;">' + dTipo + '</span>');
                if (dPlaca)       parts.push('<span style="color:#64748b;font-size:13px;">Placa: <strong>' + dPlaca + '</strong></span>');
                else if (dSerial) parts.push('<span style="color:#64748b;font-size:13px;">Chasis: <strong>' + dSerial + '</strong></span>');
                titleEl.innerHTML = parts.join('<span style="color:#cbd5e1;margin:0 6px;">|</span>') || '&mdash;';
            }

            if (url && url !== 'null' && url !== '') {
                if (overlay) overlay.style.display = 'flex';
                if (iframe) {
                    iframe.style.display = 'none';
                    iframe.style.opacity = '0';
                    iframe.src = url;
                }
            }

            setTimeout(function () {
                if (window.hidePreloader) window.hidePreloader();
                // Guard: si la SPA navego antes de que dispare el timeout, el
                // modal ya no esta conectado al DOM. Evitamos aplicar overflow
                // hidden al body de la pagina destino (ej. /edit) lo que dejaba
                // al usuario sin scroll vertical.
                if (!modal || !modal.isConnected) return;
                modal.style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }, 1200);
        };

        // Abre la pantalla de edición del equipo que está siendo mostrado en el modal de detalles.
        // Usa SPA nav si está disponible para mantener la experiencia fluida.
        window.editEquipoFromDetails = function (event) {
            if (event) { event.preventDefault(); event.stopPropagation(); }
            // Doble check de permiso: la directiva Blade oculta el botón server-side,
            // pero evitamos que invocaciones vía consola/DOM hack abran la pantalla de edición.
            if (typeof window.CAN_UPDATE_INFO !== 'undefined' && window.CAN_UPDATE_INFO === false) {
                if (window.showModal) {
                    window.showModal({ type: 'error', title: 'Acceso Denegado', message: 'No tienes permisos para editar equipos.', confirmText: 'Entendido', hideCancel: true });
                }
                return;
            }
            var equipoId = window._quickEditEquipoId;
            if (!equipoId) {
                if (window.showModal) {
                    window.showModal({ type: 'error', title: 'No disponible', message: 'No se pudo identificar el equipo seleccionado.', confirmText: 'Entendido', hideCancel: true });
                }
                return;
            }
            // Conservar el listado activo CON sus filtros (frente/búsqueda) para volver a
            // él al Cancelar/Guardar — sin esto el editor regresaba a /admin/equipos pelado
            // y la tabla salía vacía. El modal de detalles SIEMPRE se abre desde el índice,
            // así que location.pathname+search es la URL del listado que el usuario veía.
            var listUrl = window.location.pathname + window.location.search;
            var url = '/admin/equipos/' + encodeURIComponent(equipoId) + '/edit?return=' + encodeURIComponent(listUrl);
            if (typeof window.closeDetailsModal === 'function') {
                try { window.closeDetailsModal(); } catch (e) { /* noop */ }
            }
            if (typeof window.navigateTo === 'function') {
                window.navigateTo(url);
            } else {
                window.location.href = url;
            }
        };

        window.closeGpsModal = function () {
            var modal  = document.getElementById('gpsTrackerModal');
            var iframe = document.getElementById('gps_iframe');
            if (modal && modal.style.display === 'flex') {
                modal.style.display = 'none';
                document.body.style.overflow = '';
                if (iframe) iframe.src = 'about:blank';
            }
        };

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') window.closeGpsModal();
        });
    })();
}
</script>
