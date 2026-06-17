{{-- Modal "Nuevo Reporte de Falla" COMPARTIDO (estilos + HTML autocontenidos).
     Se incluye en /admin/fallas, /admin/equipos y /admin/equipos-auxiliares.
     La lógica vive en public/js/maquinaria/falla_create_modal.js (parametrizado
     por window.FALLA_MODAL_CFG). NO duplicar: incluir este partial una sola vez por página. --}}
<style>
    .fl-modal-overlay {
        position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55);
        backdrop-filter: blur(3px); z-index: 10001; display: none;
        align-items: center; justify-content: center; padding: 16px;
    }
    .fl-modal-overlay.active { display: flex; }
    .fl-modal {
        background: white; width: 100%; max-width: 520px; max-height: 92vh;
        overflow-y: auto; border-radius: 14px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    }
    .fl-modal-header {
        background: #1e293b; color: white; padding: 14px 18px; display: flex;
        justify-content: space-between; align-items: center; border-radius: 14px 14px 0 0;
    }
    .fl-modal-body { padding: 18px; display: flex; flex-direction: column; gap: 14px; }
    .fl-field-label { display: block; font-size: 12px; font-weight: 700; color: #475569; margin-bottom: 5px; }
    .fl-input, .fl-textarea {
        width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 10px;
        font-size: 13.5px; box-sizing: border-box; background: white; color: #1e293b;
        outline: none; transition: all 0.2s;
    }
    .fl-input:focus, .fl-textarea:focus { border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0, 103, 177, 0.1); }
    .fl-textarea { resize: vertical; min-height: 80px; font-family: inherit; }
    .fl-toggle-row { display: flex; gap: 8px; }
    .fl-toggle-btn {
        flex: 1; padding: 8px 10px; border: 1.5px solid #cbd5e1; border-radius: 8px;
        background: white; cursor: pointer; font-size: 12.5px; font-weight: 700;
        color: #64748b; text-align: center; transition: all 0.15s;
    }
    .fl-toggle-btn.active { border-color: #0067b1; background: #e1effa; color: #0067b1; }
    .fl-search-result {
        padding: 8px; cursor: pointer; display: flex; gap: 0; align-items: center;
        background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06); margin-bottom: 8px;
        transition: box-shadow 0.18s, border-color 0.18s;
    }
    .fl-search-result:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12); border-color: #cbd5e1; }
    .fl-search-result:last-child { margin-bottom: 0; }
    .fl-search-result img { width: 88px; height: 72px; border-radius: 8px; object-fit: contain; background: #f8fafc; flex-shrink: 0; }
    /* Botón de crear (autocontenido: no depende de .falla-btn de la página de fallas). */
    .fl-submit-btn {
        height: 44px; width: 100%; border: none; border-radius: 8px; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        background: #0067b1; color: white; font-size: 14.5px; font-weight: 800; letter-spacing: 0.3px;
    }
    .fl-submit-btn:hover { background: #0a5599; }
    @keyframes fl-spin { 100% { transform: translateY(-50%) rotate(360deg); } }
</style>

{{-- Modal: NO se cierra al hacer clic afuera (solo con la X) --}}
<div id="nuevoReporteOverlay" class="fl-modal-overlay">
    <div class="fl-modal">
        <div class="fl-modal-header" style="justify-content: center; position: relative;">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons" style="font-size: 20px;">report_problem</i>
                <h3 style="margin:0; font-size:16px; font-weight:800;">Nuevo Reporte de Falla</h3>
            </div>
            <button type="button" onclick="window.closeNuevoReporteModal()"
                style="background:transparent; border:none; color:white; cursor:pointer; opacity:0.7; position: absolute; right: 18px;"><i
                    class="material-icons">close</i></button>
        </div>
        <form id="nuevoReporteForm" class="fl-modal-body"
            onsubmit="event.preventDefault(); window.submitNuevoReporte();">

            {{-- Tipo de reporte --}}
            <div>
                <div class="fl-toggle-row">
                    <div class="fl-toggle-btn active" data-tipo="corto" onclick="window.flSetTipo('corto')">⚡
                        Reporte Rápido</div>
                    <div class="fl-toggle-btn" data-tipo="extenso" onclick="window.flSetTipo('extenso')">📄 Acta
                        Detallada (PDF)</div>
                </div>
                <input type="hidden" id="fl_tipo_reporte" name="tipo_reporte" value="corto">
            </div>

            {{-- Buscador de activo — se OCULTA (fl_search_block) cuando el modal se abre
                 con el equipo ya pre-seleccionado desde equipos/auxiliares. --}}
            <div id="fl_search_block">
                <label class="fl-field-label" for="fl_search_activo">Buscar Equipo (placa / serial / cod.
                    motor)</label>
                <div style="position:relative;">
                    <input type="text" id="fl_search_activo" class="fl-input" placeholder="Ej: ABC123 / 1HGCM82..."
                        autocomplete="off" oninput="window.flSearchActivos(this.value)"
                        style="padding-right: 35px;">
                    <i id="fl_search_spinner" class="material-icons"
                        style="display:none; position:absolute; right:10px; top:50%; transform:translateY(-50%); font-size:18px; color:#94a3b8; animation: fl-spin 1s linear infinite;">sync</i>
                </div>
                <div id="fl_search_results"
                    style="border:1px solid #e2e8f0; border-radius:10px; max-height:240px; overflow-y:auto; margin-top:6px; display:none; background:#f8fafc; padding:6px;">
                </div>
            </div>
            <div id="fl_activo_seleccionado" style="display:none; margin-top:8px;"></div>
            <input type="hidden" id="fl_activo_tipo" name="activo_tipo" value="">
            <input type="hidden" id="fl_activo_id" name="activo_id" value="">

            {{-- Campos extensos (visibles solo si tipo=extenso) — formato Acta REPORTE DE FALLAS --}}
            <div id="fl_fields_extenso" style="display:none; flex-direction:column; gap:10px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label class="fl-field-label" for="fl_kilometraje">Kilometraje</label>
                        <input type="text" id="fl_kilometraje" name="kilometraje" class="fl-input" placeholder="Ej: 12500 km">
                    </div>
                    <div>
                        <label class="fl-field-label" for="fl_horas">Horas</label>
                        <input type="text" id="fl_horas" name="horas" class="fl-input" placeholder="Ej: 3200 hrs">
                    </div>
                </div>
                <div>
                    <label class="fl-field-label">Tipo de Mantenimiento</label>
                    <div class="custom-dropdown" id="flMantenimientoDD" style="width:100%;">
                        <input type="hidden" id="fl_tipo_mantenimiento" name="tipo_mantenimiento" value="">
                        <div class="dropdown-trigger"
                            style="padding:0 12px; display:flex; align-items:center; background:white; border:1px solid #cbd5e1; border-radius:10px; height:45px; justify-content:space-between;"
                            onclick="event.stopPropagation(); const c=this.nextElementSibling; document.querySelectorAll('.dropdown-content').forEach(el=>el!==c?el.style.display='none':null); c.style.display=(c.style.display==='none'||!c.style.display)?'block':'none';">
                            <span id="fl_tipo_mantenimiento_label" style="font-size:13.5px; color:#94a3b8;">Seleccionar...</span>
                            <i class="material-icons" style="font-size:18px; color:#94a3b8;">expand_more</i>
                        </div>
                        <div class="dropdown-content"
                            style="padding:5px; margin-top:5px; border-radius:10px; z-index:1001; box-shadow:0 10px 25px -5px rgba(0,0,0,0.15);">
                            <div class="dropdown-item-list" style="max-height:200px; overflow-y:auto;">
                                <div class="dropdown-item selected"
                                    onclick="document.getElementById('fl_tipo_mantenimiento').value=''; document.getElementById('fl_tipo_mantenimiento_label').innerText='Seleccionar...'; document.getElementById('fl_tipo_mantenimiento_label').style.color='#94a3b8'; document.querySelectorAll('#flMantenimientoDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">
                                    Seleccionar...</div>
                                @foreach(\App\Models\Falla::tiposMantenimiento() as $k => $v)
                                    <div class="dropdown-item"
                                        onclick="document.getElementById('fl_tipo_mantenimiento').value='{{ $k }}'; document.getElementById('fl_tipo_mantenimiento_label').innerText='{{ $v }}'; document.getElementById('fl_tipo_mantenimiento_label').style.color='#1e293b'; document.querySelectorAll('#flMantenimientoDD .dropdown-item').forEach(i=>i.classList.remove('selected')); this.classList.add('selected'); this.parentElement.parentElement.style.display='none';">
                                        {{ $v }}</div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Descripcion de la averia — visible siempre (corto y extenso) --}}
            <div>
                <label class="fl-field-label" for="fl_descripcion">Descripción de la falla o requerimiento <span style="color:#ef4444;">*</span></label>
                <textarea id="fl_descripcion" name="descripcion" class="fl-textarea" required
                    placeholder="Describe brevemente la falla detectada..."></textarea>
            </div>

            {{-- Seccion 4: Exclusiva para taller (opcional al crear; tambien editable al cerrar) --}}
            <div id="fl_fields_extenso_taller" style="display:none; flex-direction:column; gap:10px;">
                <div style="border-top:1px dashed #cbd5e1; padding-top:10px; margin-top:2px;">
                    <span style="font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px;">Sección Taller (opcional)</span>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label class="fl-field-label" for="fl_mecanico">Mecánico Asignado</label>
                        <input type="text" id="fl_mecanico" name="mecanico_asignado" class="fl-input" placeholder="Nombre del mecánico">
                    </div>
                    <div>
                        <label class="fl-field-label" for="fl_fecha_recepcion">Fecha de Recepción</label>
                        <input type="date" id="fl_fecha_recepcion" name="fecha_recepcion" class="fl-input" style="cursor:pointer;" onclick="if(this.showPicker)this.showPicker()">
                    </div>
                </div>
                <div>
                    <label class="fl-field-label" for="fl_diagnostico">Diagnóstico</label>
                    <textarea id="fl_diagnostico" name="diagnostico" class="fl-textarea"></textarea>
                </div>
                <div>
                    <label class="fl-field-label" for="fl_acciones">Acciones Realizadas</label>
                    <textarea id="fl_acciones" name="acciones_realizadas" class="fl-textarea"></textarea>
                </div>
            </div>

            <button type="submit" id="fl_submit_btn" class="fl-submit-btn">
                <i class="material-icons">send</i> Crear Reporte
            </button>
        </form>
    </div>
</div>
