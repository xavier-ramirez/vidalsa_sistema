{{-- Modal de CIERRE de reporte (lean) COMPARTIDO — equipos / auxiliares.
     Se abre cuando se intenta cambiar el estado de un equipo con un reporte
     ABIERTO. Lógica en falla_create_modal.js (flAbrirCierre / flConfirmarCierre).
     Reutiliza las clases .fl-modal* del partial create_modal (incluir ambos). --}}
<div id="flCierreOverlay" class="fl-modal-overlay" onclick="if(event.target===this) window.flCerrarCierreModal()">
    <div class="fl-modal" style="max-width:460px;">
        <div class="fl-modal-header" style="justify-content:center; position:relative;">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons" style="font-size:20px;">check_circle</i>
                <h3 style="margin:0; font-size:16px; font-weight:800;">Cerrar Reporte de Falla</h3>
            </div>
            <button type="button" onclick="window.flCerrarCierreModal()"
                style="position:absolute; right:14px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;"><i class="material-icons">close</i></button>
        </div>
        <div class="fl-modal-body">
            <div id="flCierreMsg" style="font-size:13px; color:#475569; background:#fff7ed; border:1px solid #fed7aa; border-radius:10px; padding:10px 12px;"></div>
            <div style="font-size:12.5px; color:#64748b;">Al cerrar, el equipo vuelve a <strong>OPERATIVO</strong> (si no le quedan otros reportes abiertos).</div>
            <div>
                <label class="fl-field-label" for="flCierreObs">Observaciones de cierre (opcional)</label>
                <textarea id="flCierreObs" class="fl-textarea" placeholder="Notas del cierre..."></textarea>
            </div>
            <div style="display:flex; gap:10px;">
                <button type="button" onclick="window.flCerrarCierreModal()"
                    style="height:44px; flex:1; display:inline-flex; align-items:center; justify-content:center; gap:6px; border:1px solid #e2e8f0; background:white; color:#475569; border-radius:10px; font-weight:700; cursor:pointer;">
                    <i class="material-icons" style="font-size:16px;">close</i> Cancelar
                </button>
                <button type="button" id="flBtnConfirmarCierre" onclick="window.flConfirmarCierre()" class="fl-submit-btn" style="flex:1;">
                    <i class="material-icons" style="font-size:16px;">check_circle</i> Confirmar
                </button>
            </div>
        </div>
    </div>
</div>
