{{-- Modal para ver detalle de falla + materiales. Rendered via JS. --}}
<div id="modalDetalleFalla" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10001; justify-content:center; align-items:center;">
    <div style="background:white; width:95%; max-width:600px; max-height:90vh; border-radius:16px; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25); display:flex; flex-direction:column; overflow:hidden;">
        <div style="background:linear-gradient(135deg, #1e293b, #0f172a); padding:14px 18px; color:white; flex-shrink:0;">
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="display:flex; align-items:center; gap:10px;">
                    <i class="material-icons" style="font-size:22px;">error_outline</i>
                    <h3 style="margin:0; font-size:15px; font-weight:800;" id="detalleFallaTitle">Detalle de Falla</h3>
                </div>
                <button type="button" onclick="cerrarDetalleFalla()" style="background:rgba(255,255,255,0.2); border:none; color:white; width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; cursor:pointer;">
                    <i class="material-icons" style="font-size:18px;">close</i>
                </button>
            </div>
        </div>
        <div style="padding:20px 25px; overflow-y:auto; flex:1;" id="detalleFallaBody">
            <p style="color:#94a3b8; text-align:center;">Cargando...</p>
        </div>
    </div>
</div>
