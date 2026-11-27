{{-- Cargar consumibles → modal «Excel cargados» (menú Acciones, solo super.admin): los Excel
     guardados que siguen cargados. «Borrar» se lleva todos los consumos que entraron con ese Excel
     (ConsumiblesImportacionController::deshacer) y el archivo sale de la lista; se puede volver a
     subir. La lista se pide al abrir (consumibles.importar.cargas): siempre al día. --}}
<style>
    .xc-modal { width: 96%; max-width: 860px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #f8fafc; margin: auto; max-height: 88vh; display: flex; flex-direction: column; text-align: left; }
    .xc-modal:focus { outline: none; }
    .xc-cab { position: relative; background: var(--maquinaria-dark-blue); color: #fff; padding: 14px 56px; text-align: center; flex-shrink: 0; }
    .xc-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .xc-x { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); width: 30px; height: 30px; border-radius: 8px;
        border: 1px solid rgba(255,255,255,.2); background: rgba(255,255,255,.15); color: #fff; cursor: pointer;
        display: flex; align-items: center; justify-content: center; padding: 0; }
    .xc-x:hover { background: rgba(255,255,255,.28); }
    .xc-x .material-icons { font-size: 18px; }
    #xcCuerpo { padding: 16px; overflow-y: auto; }
    .xc-ayuda { margin: 0 0 12px; font-size: 13px; color: #64748b; }
    .xc-tabla-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
    .xc-tabla { width: 100%; border-collapse: collapse; font-size: 13px; }
    .xc-tabla th { background: #1e293b; color: #e2e8f0; font-size: 11px; text-transform: uppercase; letter-spacing: .4px;
        text-align: left; padding: 9px 10px; white-space: nowrap; }
    .xc-tabla td { padding: 9px 10px; border-top: 1px solid #f1f5f9; color: #334155; vertical-align: middle; }
    .xc-tabla .num { text-align: right; white-space: nowrap; }
    .xc-tabla .xc-archivo { font-weight: 600; color: #0f172a; word-break: break-word; }
    .xc-borrar { display: inline-flex; align-items: center; gap: 4px; background: #fef2f2; border: 1px solid #fecaca; color: #dc2626;
        border-radius: 8px; padding: 5px 10px; font-weight: 600; font-size: 12.5px; cursor: pointer; white-space: nowrap; font-family: inherit; }
    .xc-borrar:hover { background: #fee2e2; }
    .xc-borrar .material-icons { font-size: 16px; }
    .xc-vacio { text-align: center; color: #94a3b8; padding: 30px 10px; font-size: 13.5px; }
    .xc-vacio .material-icons { display: block; font-size: 34px; margin-bottom: 6px; color: #cbd5e0; }
</style>

<div id="excelCargadosModal" class="modal-overlay">
    <div class="modal-content xc-modal" role="dialog" aria-labelledby="xcTitulo" tabindex="-1">
        <div class="xc-cab">
            <h2 id="xcTitulo"><i class="material-icons">description</i> Excel cargados</h2>
            <button type="button" class="xc-x" data-xc-cerrar title="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div id="xcCuerpo"></div>
    </div>
</div>

<script>
    (function () {
        var URL_CARGAS = @json(route('consumibles.importar.cargas'));
        var modal = document.getElementById('excelCargadosModal');
        if (!modal) return;
        var cuerpo = document.getElementById('xcCuerpo');
        var esc = function (t) { return window.escapeHtml ? window.escapeHtml(String(t)) : String(t); };
        var fmt = function (n) { return Number(n).toLocaleString('es-VE', { maximumFractionDigits: 0 }); };

        function pintar(cargas) {
            if (!cargas.length) {
                cuerpo.innerHTML = '<div class="xc-vacio"><i class="material-icons">inbox</i>No hay Excel cargados.</div>';
                return;
            }
            cuerpo.innerHTML = '<p class="xc-ayuda">Al borrar un Excel se borran todos los consumos que entraron con él. Después puedes volver a subirlo.</p>'
                + '<div class="xc-tabla-wrap"><table class="xc-tabla"><thead><tr><th>Archivo</th><th>Fecha</th><th>Usuario</th>'
                + '<th class="num">Consumos</th><th class="num">Litros</th><th></th></tr></thead><tbody>'
                + cargas.map(function (c, i) {
                    return '<tr><td class="xc-archivo">' + esc(c.archivos || '—') + '</td><td>' + esc(c.fecha || '') + '</td><td>' + esc(c.usuario)
                        + '</td><td class="num">' + fmt(c.consumos) + '</td><td class="num">' + fmt(c.litros) + '</td>'
                        + '<td><button type="button" class="xc-borrar" data-xc-borrar="' + i + '"><i class="material-icons">delete</i> Borrar</button></td></tr>';
                }).join('')
                + '</tbody></table></div>';
            cuerpo._cargas = cargas;
        }

        function cargar() {
            cuerpo.innerHTML = '<div class="xc-vacio">Cargando…</div>';
            return window.apiFetch(URL_CARGAS, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
                .then(function (d) { if (modal.classList.contains('active')) pintar(d.cargas || []); })
                .catch(function () { cuerpo.innerHTML = '<div class="xc-vacio">No se pudo cargar la lista. Revisa la conexión.</div>'; });
        }
        function abrir() {
            modal.classList.add('active');
            modal.querySelector('.xc-modal').focus();
            cargar();
        }
        function cerrar() { modal.classList.remove('active'); }

        document.querySelectorAll('[data-abrir-excel-cargados]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                var menu = document.getElementById('splitDropdownMenu');
                if (menu) menu.style.display = 'none';
                abrir();
            });
        });
        var abajoEnFondo = false;
        modal.addEventListener('mousedown', function (e) { abajoEnFondo = e.target === modal; });
        modal.addEventListener('click', function (e) {
            if ((e.target === modal && abajoEnFondo) || e.target.closest('[data-xc-cerrar]')) { cerrar(); return; }
            var b = e.target.closest('[data-xc-borrar]');
            if (!b) return;
            var c = (cuerpo._cargas || [])[+b.dataset.xcBorrar];
            if (c) window.consImpDeshacer(c.borrar, c.consumos, c.archivos, cargar);
        });
        modal.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !document.querySelector('#standardModal.active')) { e.stopPropagation(); cerrar(); }
        });
    })();
</script>
