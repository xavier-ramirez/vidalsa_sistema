{{-- Modal de CIERRE de reporte (lean) COMPARTIDO — equipos / auxiliares.
     Se abre cuando se intenta cambiar el estado de un equipo con un reporte
     ABIERTO. Lógica en falla_create_modal.js (flAbrirCierre / flConfirmarCierre).
     Reutiliza las clases .fl-modal* del partial create_modal (incluir ambos). --}}
<div id="flCierreOverlay" class="fl-modal-overlay" onclick="if(event.target===this) window.flCerrarCierreModal()">
    <div class="fl-modal" style="max-width:460px;">
        <div class="fl-modal-header" style="justify-content:center; position:relative;">
            <div>
                <div style="display:flex; align-items:center; justify-content:center; gap:8px;">
                    <i class="material-icons" style="font-size:20px;">check_circle</i>
                    <h3 style="margin:0; font-size:16px; font-weight:800;">Cerrar Reporte de Falla</h3>
                </div>
                {{-- Qué equipo: lo pinta flEncabezadoCierre. --}}
                <div id="flCierreEquipo" class="fl-modal-subtitulo"></div>
            </div>
            <button type="button" onclick="window.flCerrarCierreModal()"
                style="position:absolute; right:14px; background:transparent; border:none; color:white; cursor:pointer; opacity:0.7;"><i class="material-icons">close</i></button>
        </div>
        <div class="fl-modal-body">
            <div style="font-size:12.5px; color:#64748b; text-align:center; line-height:1.5;">
                Al cerrar, el equipo vuelve a <strong>OPERATIVO</strong><br>
                (si no le quedan otros reportes abiertos).
            </div>
            <div>
                <label class="fl-field-label" for="flCierreObs">Observaciones de cierre (opcional)</label>
                <textarea id="flCierreObs" class="fl-textarea" placeholder="Notas del cierre..."></textarea>
            </div>
            <div class="fl-acciones-par">
                <button type="button" onclick="window.flCerrarCierreModal()" class="fl-btn-cancelar">
                    Cancelar
                </button>
                <button type="button" id="flBtnConfirmarCierre" onclick="window.flConfirmarCierre()" class="fl-submit-btn">
                    <i class="material-icons" style="font-size:16px;">check_circle</i> Confirmar
                </button>
            </div>
        </div>
    </div>
</div>
