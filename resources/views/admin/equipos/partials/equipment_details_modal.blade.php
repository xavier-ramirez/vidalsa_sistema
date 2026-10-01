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
                            onclick="openGpsModal(this)"
                            data-equipo-id="" data-url="" data-equipo-name="" data-equipo-serial="" data-equipo-tipo=""
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
                        <span>Documentaci&oacute;n Legal</span>
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

                            {{-- Documento de embarque (BL): solo se ve si el equipo tiene uno. Lo pide
                                 uicomponents.js al abrir el detalle y lo pone la carga masiva. --}}
                            <div id="d_row_embarque" class="detail-row-doc"
                                style="display:none;align-items:center;justify-content:space-between;gap:4px;padding:5px 0;border-top:1px dashed #f1f5f9;">
                                <span style="color:#64748b;font-size:12px;font-weight:500;">Embarque</span>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <span id="d_embarque_txt" style="color:#333;font-size:13px;"></span>
                                    <a id="d_btn_embarque" target="_blank" rel="noopener" title="Ver documento de embarque"
                                        style="display:flex;text-decoration:none;"><span class="pdf-doc-btn"><i class="material-icons">description</i></span></a>
                                </div>
                            </div>

                        </div>
                    </div>
                </details>

                {{-- Sub-activos vinculados (auxiliares) — colocado JUSTO debajo de
                     "Documentación Legal" (antes iba al final del modal). --}}
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
<div id="gpsTrackerModal" style="display:none;">
    <div class="gps-modal-container" role="dialog" aria-modal="true" aria-label="Rastreo satelital">

        {{-- Encabezado: solo el rótulo y el botón de cerrar (pedido del cliente, 01-10-2026, que
             lo quiere de vuelta). Qué equipo es lo dice la ficha, así que aquí no se repite. --}}
        <div class="gps-header">
            <span class="gps-header-icono"><i class="material-icons">gps_fixed</i></span>
            <span class="gps-header-titulo">Rastreo Satelital en Vivo</span>
            <button type="button" class="gps-cerrar" onclick="closeGpsModal()" aria-label="Cerrar">
                <i class="material-icons">close</i>
            </button>
        </div>

        {{-- Cuerpo: mapa satelital a la izquierda y los datos del GPS a la derecha. Los datos los
             pide el servidor a GPS51 (MapaController::equipoGps, la misma lectura que la capa
             Equipos de /mapa). El mapa es Leaflet con el satélite de Google y sus nombres de
             calles (GpsFicha.HIBRIDO, ver mapaListo): antes era Google Maps incrustado, que
             bajaba todo Google Maps (scripts, recuadro del lugar, controles) y tardaba en verse,
             sobre todo en el teléfono. --}}
        <div class="gps-body">
            <div class="gps-panel-map">
                <div id="gps_mapa"></div>
                <div id="gps_mapa_aviso" class="gps-mapa-aviso">
                    <div class="spinner-circle"></div>
                </div>
            </div>

            <aside class="gps-info">
                <div class="gps-info-cuerpo">
                    {{-- Qué equipo es mientras la ficha no está (cargando o con un problema del
                         enlace): sin el encabezado, si no, no habría forma de saber de cuál se habla. --}}
                    <p id="gps_ident" class="gps-ident"></p>
                    {{-- La ficha entera la pinta window.GpsFicha (gps_ficha.js), el MISMO componente
                         que usa la capa Equipos de /mapa: frente; tipo y marca; modelo y placa (o
                         serial); la rejilla de datos; dirección y coordenada. Antes aquí había una
                         maqueta propia (placa gigante, chip suelto y una lista de dos columnas)
                         que no se parecía en nada a la del mapa. --}}
                    <div id="gps_ficha"></div>
                    <p id="gps_mensaje" class="gps-mensaje" hidden></p>

                    {{-- Sin botones (pedido del cliente, 28-09-2026): "Abrir en Google Maps",
                         "Abrir enlace del GPS" y "Actualizar" se quitaron. El de Actualizar además
                         sobraba: la ficha se refresca sola cada GPS_REFRESCO_MS mientras está
                         abierta (ver el setInterval de openGpsModal). --}}
                    <p id="gps_vence" class="gps-vence" hidden></p>
                </div>
            </aside>
        </div>
    </div>
</div>

<style>
    details[name="equipment_accordion"] summary { cursor: default; }

    /* ── Modal Rastreo Satelital: mapa satelital + ficha blanca con letra negra ──
       PC: mapa a la izquierda y ficha a la derecha. Tablet: igual, ficha más angosta.
       Teléfono de pie: pantalla completa, mapa arriba y ficha debajo (un solo scroll).
       Teléfono acostado: pantalla completa, mapa y ficha lado a lado. */
    #gpsTrackerModal {
        position: fixed; inset: 0; z-index: 99999; padding: 20px;
        background: rgba(15,23,42,0.8); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
        align-items: center; justify-content: center; font-family: 'Nunito', sans-serif;
    }
    .gps-modal-container {
        background: #fff; border-radius: 16px; border: 1px solid #e2e8f0; overflow: hidden;
        width: 100%; max-width: 1150px; height: min(760px, 90vh);
        display: flex; flex-direction: column; box-shadow: 0 25px 60px rgba(0,0,0,0.2);
    }

    .gps-header {
        display: flex; align-items: center; gap: 10px; flex-shrink: 0;
        padding: 12px 16px; background: #f8fafc; border-bottom: 1px solid #e2e8f0;
    }
    .gps-header-icono {
        width: 32px; height: 32px; border-radius: 50%; background: #10b981; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
    }
    .gps-header-icono .material-icons { font-size: 18px; color: #fff; }
    .gps-header-titulo { flex: 1; min-width: 0; color: #1e293b; font-size: 15px; font-weight: 800; }
    /* Cerrar: a la derecha del encabezado. */
    .gps-cerrar {
        width: 32px; height: 32px; flex-shrink: 0; border-radius: 8px; cursor: default;
        display: flex; align-items: center; justify-content: center;
        background: #f1f5f9; border: 1px solid #e2e8f0; color: #64748b; transition: all 0.2s;
    }
    .gps-cerrar .material-icons { font-size: 18px; }
    .gps-cerrar:hover { background: #fee2e2; color: #ef4444; border-color: #fecaca; }

    .gps-body { display: flex; flex: 1; min-height: 0; }
    .gps-panel-map { flex: 1; min-width: 0; position: relative; background: #e2e8f0; }
    #gps_mapa { position: absolute; inset: 0; width: 100%; height: 100%; }
    /* Encima de todo lo de Leaflet (sus capas y controles llegan a z-index 1000). */
    .gps-mapa-aviso {
        position: absolute; inset: 0; z-index: 1001; background: #f8fafc;
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;
        padding: 24px; text-align: center; color: #0f172a; font-size: 14px; font-weight: 700;
    }
    .gps-mapa-aviso .material-icons { font-size: 40px; color: #94a3b8; }

    .gps-info {
        flex-shrink: 0; min-height: 0; display: flex; flex-direction: column;
        background: #fff; color: #000; border-left: 1px solid #e2e8f0;
    }
    .gps-info-cuerpo {
        width: 360px; flex: 1; min-height: 0; padding: 18px 20px; overflow-y: auto;
        display: flex; flex-direction: column; gap: 12px;
    }
    /* Solo para los problemas del enlace (vencido, ajeno, sin reportar). El aviso de posición
       fuera del país NO va aquí: lo pinta la propia ficha (.mapa-eq-dudosa), igual que en el mapa. */
    .gps-mensaje { margin: 0; font-size: 14px; font-weight: 700; color: #000; }
    .gps-ident { margin: 0; font-size: 14px; font-weight: 800; color: #0f172a; }
    .gps-ident:empty { display: none; }
    /* Encabezado de la ficha en VERDE, el tono del icono de "Rastreo Satelital en Vivo" (pedido
       del cliente, 01-10-2026; la raya de la izquierda la pone el color que se le pasa).
       El hueco que la ficha deja a la derecha para la × de Leaflet (en /mapa) aquí no hace falta:
       la × del modal va en el encabezado del modal. */
    #gpsTrackerModal .mapa-eq-head { background: #ecfdf5; border-bottom-color: #bbf7d0; }
    #gpsTrackerModal .mapa-eq-frente { padding-right: 0; border-bottom-color: #bbf7d0; }
    #gpsTrackerModal .mapa-eq-rot { font-size: 10.5px; color: #047857; }
    #gpsTrackerModal .mapa-eq-dudosa { padding-right: 12px; }
    .gps-vence { margin: 0; font-size: 11px; font-weight: 600; color: #475569; text-align: center; }

    /* Los seis datos van en TRES filas de dos, como en /mapa (pedido del cliente, 01-10-2026;
       antes dos filas de tres): es la rejilla de la propia ficha, sin regla aquí. */
    /* Letra más grande en el modal (pedido del cliente, 01-10-2026): los rótulos y los datos
       chicos de la ficha (9,5-11,5 px) costaban de leer. Solo aquí, que hay sitio; la tarjeta
       de /mapa es más angosta y se queda como está. */
    #gpsTrackerModal .mapa-eq-frente,
    #gpsTrackerModal .mapa-eq-tit b,
    #gpsTrackerModal .mapa-eq-desc { font-size: 13px; }
    #gpsTrackerModal .mapa-eq-cel span { font-size: 11px; }
    #gpsTrackerModal .mapa-eq-cel b { font-size: 14px; }
    #gpsTrackerModal .mapa-eq-cel small { font-size: 12px; }
    #gpsTrackerModal .mapa-eq-vieja,
    #gpsTrackerModal .mapa-eq-dir { font-size: 13px; }
    #gpsTrackerModal .mapa-eq-coord { font-size: 12px; }

    /* Tablet: la ficha se angosta para dejarle ancho al mapa. */
    @media (max-width: 1024px) {
        .gps-info-cuerpo { width: 300px; padding: 16px; }
    }

    /* PC o laptop con poca altura: ficha más compacta para que quepa sin desplazarse. */
    @media (min-width: 769px) and (max-height: 760px) {
        .gps-modal-container { height: 94vh; }
        .gps-info-cuerpo { gap: 8px; padding-top: 14px; padding-bottom: 14px; }
    }

    /* Teléfono de pie: pantalla completa; mapa arriba y ficha debajo, con un solo scroll. */
    @media (max-width: 768px) {
        #gpsTrackerModal { padding: 0; }
        .gps-modal-container { max-width: none; height: 100vh; height: 100dvh; border: 0; border-radius: 0; }
        .gps-body { flex-direction: column; overflow-y: auto; -webkit-overflow-scrolling: touch; }
        /* El mapa es lo principal en el teléfono (pedido del cliente, 01-10-2026): más de la
           mitad de la pantalla; la ficha queda debajo, con un solo scroll. */
        .gps-panel-map { flex: none; height: 55vh; height: 55dvh; min-height: 260px; }
        .gps-info { border-left: 0; border-top: 1px solid #e2e8f0; }
        .gps-info-cuerpo { width: auto; overflow: visible; padding: 14px 16px 20px; }
    }

    /* Teléfono acostado: poca altura, así que mapa y ficha van lado a lado a pantalla completa. */
    @media (max-width: 1024px) and (max-height: 500px) and (orientation: landscape) {
        #gpsTrackerModal { padding: 0; }
        .gps-modal-container { max-width: none; height: 100vh; height: 100dvh; border: 0; border-radius: 0; }
        /* Con tan poco alto, el encabezado se queda en lo justo. */
        .gps-header { padding: 6px 12px; }
        .gps-header-icono { display: none; }
        .gps-body { flex-direction: row; overflow: hidden; }
        .gps-panel-map { flex: 1; height: auto; min-height: 0; }
        .gps-info { border-top: 0; border-left: 1px solid #e2e8f0; }
        .gps-info-cuerpo { width: 290px; overflow-y: auto; padding: 10px 14px; gap: 8px; }
    }

</style>

<script>
// Guard: el SPA router re-ejecuta los <script> inline al navegar. Sin este wrap
// las `const` top-level revientan con "already declared" en la segunda carga.
if (!window._gpsModalScriptLoaded) {
    window._gpsModalScriptLoaded = true;
    (function () {
        // Un poco más que la caché del servidor (Gps51Service::TTL_POSICION = 120 s): con el mismo
        // periodo la consulta cae justo antes de que caduque y trae la posición vieja otra vez.
        var GPS_REFRESCO_MS = 130000;
        var GPS_MOTIVOS = {
            enlace_vencido:  'El enlace de este GPS está vencido. Hay que renovarlo en GPS51.',
            enlace_invalido: 'GPS51 no reconoce el enlace de este GPS. Revísalo en la ficha del equipo.',
            sin_posicion:    'Este GPS todavía no ha reportado ninguna posición.'
        };
        // Estado del modal abierto: equipo, última coordenada pintada, temporizador y un turno
        // para descartar respuestas viejas. El enlace de GPS51 ya no se guarda: los datos los
        // sirve el propio sistema (/mapa/equipos-gps/{id}) y el botón que abría el enlace crudo
        // se quitó, así que nadie lo leía.
        var S = { id: null, lat: null, lng: null, timer: null, turno: 0 };
        var esc = window.escapeHtml;   // dom_helpers.js

        function $(id) { return document.getElementById(id); }
        function limpio(v, vacios) {
            v = (v == null ? '' : String(v)).trim();
            return (v && v !== 'null' && v !== 'undefined' && (vacios || []).indexOf(v) === -1) ? v : '';
        }

        function avisoMapa(html) {
            var av = $('gps_mapa_aviso');
            if (!av) return;
            av.innerHTML = html || '';
            av.style.display = html ? 'flex' : 'none';
        }
        // ── Mapa: Leaflet con el satélite de Google y sus nombres de calles (GpsFicha.HIBRIDO).
        // Leaflet lo baja window.cargarLeaflet (lazy_loader.js), el mismo cargador de /mapa: si ya
        // se abrió el mapa no se vuelve a bajar. Se pide al ABRIR el modal, a la vez que los datos
        // del GPS. El icono y las teselas salen de window.GpsFicha.
        var M = { mapa: null, marca: null };
        function soltarMapa() {
            if (M.mapa) M.mapa.remove();
            M.mapa = M.marca = null;
        }
        // El mapa se crea UNA vez y se reutiliza en cada apertura. Si la SPA rehízo el modal (otra
        // página y vuelta), el contenedor es otro: se suelta el viejo y se crea de nuevo.
        function mapaListo() {
            return window.cargarLeaflet().then(function () {
                var cont = $('gps_mapa');
                if (!cont) throw new Error('sin contenedor');
                if (M.mapa && M.mapa.getContainer() !== cont) soltarMapa();
                if (!M.mapa) {
                    M.mapa = L.map(cont, {
                        zoomControl: true,
                        attributionControl: false,   // sin el texto de créditos, igual que /mapa
                        // En el teléfono el mapa ocupa media pantalla: con un dedo se desplaza la
                        // página hasta la ficha, en vez de arrastrar el mapa (se acerca con dos).
                        dragging: !L.Browser.mobile
                    });
                    // Satélite de Google con los nombres de calles y lugares ya puestos: sin las
                    // nubes que trae el de Esri en algunas zonas (pedido del cliente, 01-10-2026).
                    L.tileLayer(window.GpsFicha.HIBRIDO, {
                        subdomains: window.GpsFicha.SUBDOMINIOS_GOOGLE, maxZoom: 19
                    }).addTo(M.mapa);
                }
                return M.mapa;
            });
        }
        // Una navegación de la SPA se lleva el modal: el mapa viejo no se queda vivo en memoria.
        window.addEventListener('spa:contentLoaded', function () {
            if (M.mapa && !M.mapa.getContainer().isConnected) soltarMapa();
        });
        // Centra en el equipo al abrir. En los refrescos solo mueve el marcador, sin tocar el zoom
        // ni el encuadre que haya puesto el usuario, y lo sigue únicamente si el equipo se movió
        // (`movido`) y quedó fuera de la vista.
        function pintarMapa(g, movido) {
            if (S.lat === null) return;
            var turno = S.turno, lat = S.lat, lng = S.lng;
            mapaListo().then(function (mapa) {
                if (turno !== S.turno || S.lat !== lat || S.lng !== lng) return;   // cerrado u otro equipo
                // Al REUTILIZAR el mapa: con el modal cerrado Leaflet midió el contenedor oculto
                // (0×0) si cambió el tamaño de la ventana; sin esto el equipo no quedaba centrado.
                mapa.invalidateSize();
                var ll = [lat, lng];
                if (!M.marca) M.marca = L.marker(ll, { keyboard: false }).addTo(mapa);
                M.marca.setLatLng(ll).setIcon(window.GpsFicha.icono(g));
                if (!S.centrado) { mapa.setView(ll, 17, { animate: false }); S.centrado = true; }
                else if (movido && !mapa.getBounds().contains(ll)) mapa.panTo(ll);
                avisoMapa('');
            }).catch(function () {
                if (turno !== S.turno) return;
                avisoMapa('<i class="material-icons">map</i><span>No se pudo cargar el mapa.</span>');
            });
        }

        // "hace 5 min" / "hace 3 días": lo calcula window.tiempoHace (dom_helpers.js, en el
        // <head>), el MISMO que usa la ficha del mapa — un solo sitio para un solo criterio.

        function pararRefresco() { clearInterval(S.timer); S.timer = null; }

        // Qué equipo es, en una línea ("CAMION DE SERVICIO  Placa: A51EX9P"), para cuando la
        // ficha no está. También es el nombre del diálogo para los lectores de pantalla.
        function ponerIdent(eq) {
            var tipo = limpio(eq.tipo, ['N/A', 'SIN TIPO']).toUpperCase();
            var ident = limpio(eq.ident);
            var texto = [tipo, ident ? (eq.ident_por ? eq.ident_por + ': ' : '') + ident : '']
                .filter(Boolean).join('  ');
            $('gps_ident').textContent = texto;
            var dlg = document.querySelector('#gpsTrackerModal .gps-modal-container');
            if (dlg) dlg.setAttribute('aria-label', 'Rastreo satelital' + (texto ? ': ' + texto : ''));
        }

        // Pinta la ficha con lo que devuelve MapaController::equipoGps (ver Gps51Service::normalizar).
        function pintarDatos(r) {
            var ficha = $('gps_ficha'), msg = $('gps_mensaje'), vence = $('gps_vence');
            var g = r && r.gps;

            // GPS51 no contestó esta vez (lento u ocupado): si ya había datos se dejan en pantalla
            // con un aviso, y la siguiente vuelta lo vuelve a intentar.
            if (!g && S.lat !== null) {
                msg.textContent = 'GPS51 no respondió: se muestran los últimos datos recibidos.';
                msg.hidden = false;
                return;
            }

            ficha.innerHTML = '';
            msg.hidden = true; vence.hidden = true;
            if (r && r.equipo) ponerIdent(r.equipo);

            var problema = null;
            if (r && r.gps51 === false) problema = 'El enlace de este GPS no es de GPS51: no se pueden leer sus datos aquí.';
            // Sin mandar a pulsar "Actualizar": ese botón ya no existe y la ficha se reintenta
            // sola mientras esté abierta (el setInterval de openGpsModal).
            else if (!g) problema = 'GPS51 no respondió. Se vuelve a intentar solo, deja la ventana abierta.';
            else if (!g.ok) problema = GPS_MOTIVOS[g.motivo] || GPS_MOTIVOS.enlace_invalido;
            if (problema) {
                // Enlace ajeno, vencido o inválido no se arregla solo: se deja de consultar.
                // "Todavía no ha reportado" SÍ se arregla solo en cuanto el aparato mande su
                // primera posición, así que ese se sigue reintentando mientras el modal esté abierto.
                if ((g && g.motivo !== 'sin_posicion') || (r && r.gps51 === false)) pararRefresco();
                S.lat = S.lng = null;
                msg.textContent = problema;
                msg.hidden = false;
                avisoMapa('<i class="material-icons">location_off</i><span>' + esc(problema) + '</span>');
                return;
            }

            // La ficha ENTERA la pinta el componente compartido, el mismo del mapa. El color del
            // frente no se le pasa: ahí sirve para distinguir equipos de frentes distintos en el
            // mismo mapa, y aquí solo hay uno. En su lugar va el verde del icono del encabezado.
            var eq = (r && r.equipo) || {};
            // Con la ficha a la vista su encabezado ya dice qué equipo es.
            $('gps_ident').textContent = '';
            ficha.innerHTML = window.GpsFicha.html({
                tipo: eq.tipo, modelo: eq.modelo, marca: eq.marca,
                ident: eq.ident, identPor: eq.ident_por,
                frente: eq.frente, color: '#10b981', gps: g
            }, {
                dudosa: !!g.fuera_de_venezuela,
                // La dirección se pide aparte (tarda ~2 s): la ficha reserva su hueco y
                // cargarDireccion la rellena por este data-eqdir cuando llega.
                // Con la posición fuera del país no se pide dirección (ver más abajo), así que
                // tampoco se reserva su hueco: dejaría un "Buscando dirección…" que no llega.
                sinDireccion: !!g.fuera_de_venezuela,
                dirAttr: 'modal', direccion: S.direccion || null
            });

            if (g.vence) {
                // Solo el día, sin hora: window.fechaLocal (dom_helpers.js), en hora de Venezuela.
                vence.textContent = 'Enlace del GPS vigente hasta el ' + window.fechaLocal(g.vence);
                vence.hidden = false;
            }

            // Fuera de Venezuela: el mapa SE PINTA IGUAL (pedido del cliente, 28-09-2026) — el
            // cliente quiere ver dónde dice el GPS que está. El AVISO de que ese punto no es el
            // equipo lo pone la propia ficha (GpsFicha, opción `dudosa`), el mismo que en el mapa;
            // aquí solo se decide si vale la pena buscar la dirección escrita: la de una
            // coordenada de otro país no dice nada y sería una consulta para nada.
            var cambio = S.lat !== g.lat || S.lng !== g.lng;
            S.lat = g.lat; S.lng = g.lng;
            pintarMapa(g, cambio);
            if (!g.fuera_de_venezuela && (cambio || !S.direccion)) cargarDireccion();
        }

        // Dirección escrita (la misma que da GPS51): va aparte porque tarda ~2 s más. Solo se pinta
        // si el equipo sigue en la coordenada por la que se preguntó (si se movió mientras tanto,
        // llega la de la posición nueva).
        function cargarDireccion() {
            var turno = S.turno, lat = S.lat, lng = S.lng;
            window.apiFetch('/mapa/equipos-gps/' + encodeURIComponent(S.id) + '/direccion', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; })
                .then(function (j) {
                    if (turno !== S.turno || S.lat !== lat || S.lng !== lng || !j || !j.direccion) return;
                    // La dirección es de la posición que el servidor tiene AHORA: si ya no es la que
                    // enseña la ficha (el equipo se movió entre las dos consultas), no se pone.
                    var a4 = function (n) { return Number(n).toFixed(4); };
                    if (a4(j.lat) !== a4(lat) || a4(j.lng) !== a4(lng)) return;
                    // Se guarda en S para que el refresco que repinta la ficha no la pierda, y se
                    // escribe en el hueco que la ficha dejó (data-eqdir), sin repintarla entera.
                    S.direccion = j.direccion;
                    window.GpsFicha.ponerDireccion(document.querySelector('#gps_ficha [data-eqdir]'), j.direccion);
                });
        }

        function cargar() {
            var turno = S.turno;
            return window.apiFetch('/mapa/equipos-gps/' + encodeURIComponent(S.id), { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .catch(function () { return null; })
                .then(function (j) {
                    if (turno !== S.turno) return;   // se cerró o se abrió otro equipo mientras tanto
                    pintarDatos(j || { gps: null });
                });
        }

        // Lo abre el botón "VER GPS EN VIVO" del detalle (uicomponents.js le pone los data-*).
        window.openGpsModal = function (btn) {
            var modal = $('gpsTrackerModal');
            if (!modal || !btn) return;
            var ds = btn.dataset;

            pararRefresco();
            S = { id: ds.equipoId, lat: null, lng: null, timer: null, turno: S.turno + 1, direccion: null, centrado: false };

            // Mientras el servidor contesta, qué equipo es sale de lo que trae el botón.
            var placa  = limpio(ds.equipoName, ['N/A', 'Sin Placa']);
            var serial = limpio(ds.equipoSerial, ['N/A', 'Sin Chasis']);
            ponerIdent({ tipo: ds.equipoTipo, ident: placa || serial, ident_por: placa ? 'Placa' : 'Serial' });

            // Estado inicial: todo vacío y el mapa cargando.
            $('gps_ficha').innerHTML = '';
            ['gps_mensaje', 'gps_vence'].forEach(function (id) { $(id).hidden = true; });
            avisoMapa('<div class="spinner-circle"></div><span>Consultando el GPS…</span>');
            // Leaflet se baja YA, en paralelo con los datos del GPS: cuando lleguen, el mapa está.
            window.cargarLeaflet().catch(function () {});

            modal.style.display = 'flex';
            // Mismo ayudante que el detalle y el visor de PDF (layout_ui.js).
            window.bloquearScrollFondo();

            if (!S.id) { pintarDatos({ gps: null }); return; }
            cargar();
            S.timer = setInterval(function () {
                // Si la SPA navegó a otra página con el modal abierto, se deja de consultar.
                if (!modal.isConnected || modal.style.display !== 'flex') { pararRefresco(); return; }
                cargar();
            }, GPS_REFRESCO_MS);
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
            var modal = $('gpsTrackerModal');
            if (modal && modal.style.display === 'flex') {
                modal.style.display = 'none';
                pararRefresco();
                S.turno++;   // descarta la respuesta que venga en camino
                // Se abre desde el detalle del equipo, que sigue abierto debajo: restaurarScrollFondo
                // mantiene el bloqueo mientras quede una capa (layout_ui.js · _CAPAS_SCROLL).
                window.restaurarScrollFondo();
            }
        };

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') window.closeGpsModal();
        });
    })();
}
</script>
