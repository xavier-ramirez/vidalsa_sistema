{{-- Modales de "Registrar y despachar" de Recepción de materiales: lo usan la Entrada por ODC
     (recepcion/nueva) y el menú Acciones de la bandeja (recepcion/index). La lógica vive en
     public/js/maquinaria/recepcion_despacho.js (window.RecepcionDespacho).

     1) #almSalidaModal: la NOTA DE ENTREGA, con el mismo formulario que "Registrar salida" de
        /admin/almacen (partials/salida_nota_campos). El id es el mismo a propósito: le aplican
        los estilos de admin_almacen_salida_nota.css, también en el teléfono.
     2) #entDespPreviewModal: la vista previa del PDF. Editar (o la ✕ / Escape) vuelve a la nota
        con lo escrito; Registrar hace la entrada y la salida y descarga el PDF. Id propio: el
        Escape de /admin/almacen busca #almPreviewModal por id. --}}
<div id="almSalidaModal" class="alm-modal-overlay">
    <div class="alm-modal alm-modal-wide" style="max-width:660px;" role="dialog" aria-modal="true" aria-labelledby="entDespTitulo">
        <div class="alm-modal-head">
            <h3><i class="material-icons" style="font-size:20px;">local_shipping</i> <span id="entDespTitulo">Registrar y despachar</span></h3>
            <i class="material-icons alm-x" onclick="window.entCerrarDespacho()">close</i>
        </div>
        <div class="alm-modal-body">
            @include('admin.almacen.partials.salida_nota_campos')

            <div id="entDespError" class="rdesp-error"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.entCerrarDespacho()">Cancelar</button>
            <button type="button" class="btn-primary-maquinaria" onclick="window.entDespVistaPrevia()">Previsualizar</button>
        </div>
    </div>
</div>

<div id="entDespPreviewModal" class="alm-modal-overlay">
    <div class="alm-modal alm-modal-wide" style="max-width:1180px;max-height:98vh;" role="dialog" aria-modal="true">
        <div class="alm-modal-head" style="padding:8px 40px;">
            <h3><i class="material-icons" style="font-size:20px;">visibility</i> <span>Vista previa de la Nota de Entrega</span></h3>
            <i class="material-icons alm-x" onclick="window.entDespPreviewEditar()">close</i>
        </div>
        <div class="alm-modal-body" style="padding:0;gap:0;background:#475569;">
            {{-- ESCRITORIO: el visor de PDF del navegador. TELÉFONO: PDF.js en canvas. --}}
            <iframe id="entDespPreviewFrame" src="about:blank" style="width:100%;height:82vh;min-height:560px;border:none;background:#fff;" title="Vista previa Nota de Entrega"></iframe>
            <div id="entDespPreviewCanvas" style="display:none;width:100%;height:82vh;min-height:560px;overflow:auto;background:#475569;padding:10px;box-sizing:border-box;"></div>
        </div>
        <div class="alm-modal-foot">
            <button type="button" class="btn-primary-maquinaria" style="background:#e2e8f0;color:#475569;box-shadow:none;" onclick="window.entDespPreviewEditar()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">edit</i>Editar</button>
            <button type="button" class="btn-primary-maquinaria" id="entDespSubmit" onclick="window.entDespachar()"><i class="material-icons" style="font-size:17px;vertical-align:-3px;margin-right:4px;">check_circle</i>Registrar</button>
        </div>
    </div>
</div>
