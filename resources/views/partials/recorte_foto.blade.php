{{-- Recorte de foto antes de subirla (Cropper.js, cargado bajo demanda: la SPA no lo trae
     en el layout). UNA sola copia para los modulos que suben fotos: Catalogo e Inventario.
     Uso: window._openCropModal(file, function (recortada) { ...subir recortada... }).
     Entrega un File WebP de hasta 1200 px; el servidor lo vuelve a comprimir
     (ConvertsImageToWebp). --}}
<style>
    .crop-modal-overlay {
        display: none; position: fixed; inset: 0; z-index: 99999;
        background: rgba(0,0,0,0.7); backdrop-filter: blur(4px);
        align-items: center; justify-content: center;
        padding: 10px;
    }
    .crop-modal-box {
        background: #fff; border-radius: 14px;
        width: 100%; max-width: 600px; max-height: 90vh;
        display: flex; flex-direction: column; overflow: hidden;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,0.4);
    }
    .crop-modal-header {
        background: #1e293b; padding: 12px 16px; color: #fff;
        display: flex; justify-content: space-between; align-items: center;
        flex-shrink: 0;
    }
    .crop-modal-body {
        flex: 1; overflow: hidden; background: #0f172a;
        min-height: 250px; max-height: 55vh; position: relative;
    }
    .crop-modal-body img { display: block; max-width: 100%; }
    .crop-modal-footer {
        padding: 12px 16px; display: flex; justify-content: center;
        gap: 10px; border-top: 1px solid #e2e8f0; flex-shrink: 0;
    }
    .crop-btn {
        padding: 10px 24px; border-radius: 10px; font-size: 14px;
        font-weight: 700; cursor: pointer; display: flex;
        align-items: center; gap: 6px; transition: opacity 0.2s;
    }
    .crop-btn:active { opacity: 0.8; }
    .crop-btn-cancel {
        border: 1px solid #cbd5e0; background: #fff; color: #475569;
    }
    .crop-btn-confirm {
        border: none; background: #0067b1; color: #fff;
    }
    @media (max-width: 768px) {
        .crop-modal-overlay { padding: 0; align-items: flex-end; }
        .crop-modal-box {
            max-width: 100%; max-height: 100vh; height: 100vh;
            border-radius: 0;
        }
        .crop-modal-body { min-height: 0; max-height: none; flex: 1; }
        .crop-modal-footer { padding: 10px 12px; }
        .crop-btn { flex: 1; justify-content: center; padding: 12px 10px; }
    }
    .cropper-line { background-color: #fff !important; opacity: 1 !important; }
    .cropper-line.line-n, .cropper-line.line-s { height: 3px !important; }
    .cropper-line.line-w, .cropper-line.line-e { width: 3px !important; }
    .cropper-point { background-color: #fff !important; opacity: 1 !important; width: 10px !important; height: 10px !important; }
    .cropper-dashed { border-color: rgba(255,255,255,0.5) !important; }
</style>
<div id="cropModal" class="crop-modal-overlay">
    <div class="crop-modal-box">
        <div class="crop-modal-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <i class="material-icons" style="font-size:18px; color:#38bdf8;">crop</i>
                <span style="font-size:14px; font-weight:700;">Recortar Foto</span>
            </div>
            <button type="button" onclick="window._closeCropModal()" style="background:transparent; border:none; color:#fff; cursor:pointer; padding:4px;">
                <i class="material-icons" style="font-size:20px;">close</i>
            </button>
        </div>
        <div class="crop-modal-body"></div>
        <div class="crop-modal-footer">
            <button type="button" onclick="window._closeCropModal()" class="crop-btn crop-btn-cancel">
                <i class="material-icons" style="font-size:16px;">close</i> Cancelar
            </button>
            <button type="button" onclick="window._confirmCrop()" class="crop-btn crop-btn-confirm">
                <i class="material-icons" style="font-size:16px;">check</i> Confirmar
            </button>
        </div>
    </div>
</div>
<script>
(function() {
    if (typeof Cropper !== 'undefined') return;
    if (document.querySelector('script[src*="cropper.min.js"]')) return;
    var s = document.createElement('script');
    // ?v= obligatorio: nginx sirve /js y /css con Cache-Control immutable.
    s.src = '{{ asset("js/cropper.min.js") }}?v={{ @filemtime(public_path("js/cropper.min.js")) }}';
    document.head.appendChild(s);
    if (!document.querySelector('link[href*="cropper.min.css"]')) {
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = '{{ asset("css/cropper.min.css") }}?v={{ @filemtime(public_path("css/cropper.min.css")) }}';
        document.head.appendChild(l);
    }
})();
</script>
<script>
// ── Cropper: modal compartido para recortar la foto antes de subirla ──
// _cropPending guarda el callback que se ejecuta al confirmar el recorte.
window._cropPending = null;
window._cropperInstance = null;

window._openCropModal = function (file, onConfirm) {
    var modal = document.getElementById('cropModal');
    var body  = document.querySelector('.crop-modal-body');
    if (!modal || !body) return onConfirm(file);

    if (window._cropperInstance) { window._cropperInstance.destroy(); window._cropperInstance = null; }
    window._cropPending = onConfirm;
    body.innerHTML = '';

    modal.style.display = 'flex';

    var reader = new FileReader();
    reader.onload = function (e) {
        var img = document.createElement('img');
        img.style.cssText = 'display:block; max-width:100%;';
        body.appendChild(img);

        img.onload = function () {
            var tries = 0;
            var tryInit = function () {
                if (typeof Cropper !== 'undefined') {
                    window._cropperInstance = new Cropper(img, {
                        aspectRatio: NaN,
                        viewMode: 1,
                        autoCropArea: 0.9,
                        responsive: true,
                        background: false,
                        dragMode: 'move',
                        toggleDragModeOnDblclick: false,
                    });
                } else if (tries < 30) {
                    tries++;
                    setTimeout(tryInit, 100);
                }
            };
            setTimeout(tryInit, 150);
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
};

window._confirmCrop = function () {
    if (!window._cropperInstance || !window._cropPending) return;
    var canvas = window._cropperInstance.getCroppedCanvas({ maxWidth: 1200, maxHeight: 1200, imageSmoothingQuality: 'high' });
    canvas.toBlob(function (blob) {
        if (!blob) { window.toast('Error al recortar la imagen.', 'error'); return; }
        var croppedFile = new File([blob], 'foto_recortada.webp', { type: 'image/webp' });
        window._cropPending(croppedFile);
        window._closeCropModal();
    }, 'image/webp', 0.88);
};

window._closeCropModal = function () {
    var modal = document.getElementById('cropModal');
    if (modal) modal.style.display = 'none';
    if (window._cropperInstance) { window._cropperInstance.destroy(); window._cropperInstance = null; }
    window._cropPending = null;
    var body = document.querySelector('.crop-modal-body');
    if (body) body.innerHTML = '';
};
</script>
