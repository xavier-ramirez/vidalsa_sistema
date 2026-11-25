{{-- Cargar consumibles → modal «Cupos de Gasoil»: se elige el patio y de él se ve el cupo (se edita
     ahí), se cargan las cisternas surtidas (fecha y litros) y se ve su historial mes a mes. Lo abre
     «Cupos de gasoil» del menú Acciones; con ?cupos=1 en la dirección se abre solo (desde las otras
     pantallas). El cuerpo se repinta solo (consumibles.cupos?parcial=1). --}}
<style>
    .cupos-modal { width: 94%; max-width: 640px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; text-align: left; }
    .cupos-modal-cab { background: var(--maquinaria-dark-blue); color: #fff; padding: 12px 18px; display: flex;
        justify-content: space-between; align-items: center; gap: 10px; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
    .cupos-modal:focus { outline: none; }
    .cupos-modal-x { background: none; border: none; color: #fff; cursor: pointer; display: flex; padding: 0; }
    #cuposContenido { padding: 16px 18px 18px; overflow-y: auto; }

    .cupos-elegir { display: flex; background: #f1f5f9; border-radius: 10px; padding: 4px; gap: 4px; }
    .cupos-elegir button { flex: 1; border: none; background: none; border-radius: 8px; padding: 9px 12px; font-size: 13px;
        font-weight: 600; color: #64748b; cursor: pointer; font-family: inherit; transition: background .15s, color .15s; }
    .cupos-elegir button.activo { background: var(--color); color: #fff; box-shadow: 0 2px 6px rgba(0,0,0,.15); }

    .cupos-resumen { display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; margin-top: 16px; }
    .cupos-resumen small { display: block; font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
    .cupos-resumen b { font-size: 18px; color: #0f172a; }
    .cupos-quedan { text-align: right; }
    .cupos-quedan b { font-size: 24px; }
    .cupos-cupo-ver { display: flex; align-items: center; gap: 6px; }
    .cupos-cupo-ver[hidden] { display: none; }
    .cupos-lapiz { border: none; background: none; cursor: pointer; color: #0067b1; padding: 0; display: flex; }
    .cupos-lapiz i { font-size: 18px; }
    .cupos-cupo-editar { display: flex; gap: 4px; align-items: center; margin-top: 2px; }
    .cupos-cupo-editar[hidden] { display: none; }
    .cupos-cupo-editar input { width: 150px; height: 32px; border: 1px solid #0067b1; border-radius: 6px; padding: 0 8px;
        font-size: 13px; font-family: inherit; outline: none; box-sizing: border-box; }
    .cupos-mini { border: none; border-radius: 6px; background: #0067b1; color: #fff; height: 32px; width: 32px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
    .cupos-mini i { font-size: 18px; }
    .cupos-mini-gris { background: #e2e8f0; color: #475569; }
    .cupos-barra { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 8px; }
    .cupos-barra i { display: block; height: 100%; border-radius: 4px; }

    .cupos-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 16px; padding: 10px;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
    .cupos-form input { height: 36px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fff; padding: 0 10px;
        font-size: 13px; font-family: inherit; outline: none; min-width: 0; box-sizing: border-box; flex: 1; }
    .cupos-form input:focus { border-color: #0067b1; }
    .cupos-form .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; }

    /* Historial con el estilo de la tabla de la Lista de Consumibles; una fila por mes con su resumen. */
    .cupos-tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 14px; }
    .cupos-tbl { width: 100%; border-collapse: collapse; font-size: 12px; }
    .cupos-tbl th { background: #1e293b; color: #e2e8f0; font-weight: 700; padding: 8px; font-size: 11px; text-transform: uppercase;
        letter-spacing: .4px; white-space: nowrap; text-align: left; }
    .cupos-tbl td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; color: #1e293b; }
    .cupos-tbl tr.cupos-mes td { background: #eef4fa; color: #475569; border-left: 4px solid var(--color); }
    .cupos-tbl tr.cupos-mes b { color: #0f172a; }
    .cupos-tbl tr.cupos-mes span { float: right; }
    .cupos-tbl .num { text-align: right; white-space: nowrap; }
    .cupos-tbl .cupos-acc { width: 34px; text-align: center; }
    .cupos-vacio { color: #94a3b8; font-style: italic; text-align: center; }
    .cupos-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 2px; display: inline-flex; }
    .cupos-borrar:hover { color: #dc2626; }
    .cupos-borrar i { font-size: 18px; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }
    @media (max-width: 600px) {
        .cupos-form .btn-primary-maquinaria { flex: 1 1 100%; justify-content: center; }
        .cupos-tbl tr.cupos-mes span { float: none; display: block; }
    }
</style>

<div id="cuposModal" class="modal-overlay" data-abrir="{{ request()->boolean('cupos') ? '1' : '' }}">
    <div class="modal-content cupos-modal" role="dialog" aria-labelledby="cuposModalTitulo" tabindex="-1">
        <div class="cupos-modal-cab">
            <h2 id="cuposModalTitulo"><i class="material-icons">local_gas_station</i> Cupos de Gasoil</h2>
            <button type="button" class="cupos-modal-x" data-cerrar-modal title="Cerrar"><i class="material-icons">close</i></button>
        </div>
        <div id="cuposContenido">
            @include('admin.consumibles.partials.cupos_contenido')
        </div>
    </div>
</div>

<script>
    (function () {
        var URL_LISTA = @json(route('consumibles.cupos', ['parcial' => 1]));
        var URL_CARGAR = @json(route('consumibles.cargar'));
        var URL_CUPO = @json(route('consumibles.cupos.guardar'));
        var URL_CISTERNA = @json(route('consumibles.cupos.cisterna'));
        var URL_BORRAR = @json(url('admin/consumibles/cupos/cisternas')) + '/';
        var JSON_H = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var modal = document.getElementById('cuposModal');
        if (!modal) return;

        function caja() { return document.getElementById('cuposContenido'); }
        function aviso(msg, tipo) { if (window.showToast) window.showToast(msg, tipo); else console.log(msg); }

        // El patio elegido se mantiene al repintar y al volver a abrir.
        function mostrarPatio(patio) {
            if (!modal.querySelector('[data-panel="' + patio + '"]')) patio = 'TIGRE';
            window.__cuposPatio = patio;
            modal.querySelectorAll('[data-tab]').forEach(function (t) {
                t.classList.toggle('activo', t.dataset.tab === patio);
                t.setAttribute('aria-selected', t.dataset.tab === patio ? 'true' : 'false');
            });
            modal.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== patio; });
        }
        mostrarPatio(window.__cuposPatio || 'TIGRE');

        // Al abrir, el foco entra al modal (Escape lo cierra sin tocar antes con el ratón).
        function abrir() { modal.classList.add('active'); modal.querySelector('.cupos-modal').focus(); }
        // Al cerrar, la dirección vuelve a Cargar sola: recargar no lo abre de nuevo.
        function cerrar() { modal.classList.remove('active'); history.replaceState(history.state, '', URL_CARGAR); }
        if (modal.dataset.abrir) abrir();

        // Repinta el cuerpo (sin recargar la página).
        function repintar() {
            var c = caja();
            c.classList.add('cupos-ocupado');
            return window.apiFetch(URL_LISTA, { headers: JSON_H })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) { c.innerHTML = html; mostrarPatio(window.__cuposPatio); })
                .catch(function () { aviso('No se pudo actualizar. Revise la conexión.', 'error'); })
                .finally(function () { c.classList.remove('cupos-ocupado'); });
        }

        function enviar(url, metodo, cuerpo) {
            return window.apiFetch(url, { method: metodo, headers: JSON_H, body: cuerpo })
                .then(function (r) {
                    return r.json().catch(function () { return {}; }).then(function (b) {
                        if (!r.ok) return Promise.reject(b.message || 'No se pudo guardar.');
                        return b;
                    });
                });
        }

        // «Cupos de gasoil» del menú Acciones: abre el modal y cierra el menú.
        document.querySelectorAll('[data-abrir-cupos]').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.preventDefault();
                var menu = document.getElementById('splitDropdownMenu');
                if (menu) menu.style.display = 'none';
                abrir();
            });
        });

        modal.addEventListener('submit', function (e) {
            var f = e.target.closest('form[data-accion]');
            if (!f) return;
            e.preventDefault();
            // Un envío a la vez: Enter dos veces no guarda la misma cisterna dos veces.
            if (f.dataset.enviando) return;
            f.dataset.enviando = '1';
            var esCupo = f.dataset.accion === 'cupo';
            f.classList.add('cupos-ocupado');
            enviar(esCupo ? URL_CUPO : URL_CISTERNA, 'POST', new FormData(f))
                .then(function () { aviso(esCupo ? 'Cupo guardado.' : 'Cisterna agregada.', 'success'); return repintar(); })
                .catch(function (m) { aviso(typeof m === 'string' ? m : 'No se pudo guardar.', 'error'); })
                .finally(function () { delete f.dataset.enviando; f.classList.remove('cupos-ocupado'); });
        });

        // Se cierra al tocar fuera solo si el toque EMPEZÓ fuera (no al soltar ahí una selección de texto).
        var abajoEnFondo = false;
        modal.addEventListener('mousedown', function (e) { abajoEnFondo = e.target === modal; });
        modal.addEventListener('click', function (e) {
            if ((e.target === modal && abajoEnFondo) || e.target.closest('[data-cerrar-modal]')) { cerrar(); return; }
            var tab = e.target.closest('[data-tab]');
            if (tab) { mostrarPatio(tab.dataset.tab); return; }
            var panel = e.target.closest('[data-panel]');
            if (e.target.closest('[data-editar-cupo]')) {
                panel.querySelector('.cupos-cupo-ver').hidden = true;
                var f = panel.querySelector('.cupos-cupo-editar');
                f.hidden = false;
                f.querySelector('input[name=litros]').focus();
                return;
            }
            if (e.target.closest('[data-cancelar-cupo]')) { cancelarCupo(panel); return; }
            var b = e.target.closest('[data-borrar]');
            if (!b) return;
            window.confirmarAccion({
                title: 'Quitar cisterna',
                message: 'Se quitará: <b>' + b.dataset.texto.replace(/</g, '&lt;') + '</b>',
                confirmText: 'Quitar', type: 'warning',
            }, function () {
                enviar(URL_BORRAR + b.dataset.borrar, 'DELETE')
                    .then(function () { aviso('Cisterna quitada.', 'success'); return repintar(); })
                    .catch(function (m) { aviso(typeof m === 'string' ? m : 'No se pudo quitar.', 'error'); });
            });
        });

        function cancelarCupo(panel) {
            panel.querySelector('.cupos-cupo-editar').hidden = true;
            panel.querySelector('.cupos-cupo-ver').hidden = false;
            var lapiz = panel.querySelector('[data-editar-cupo]');
            if (lapiz) lapiz.focus();   // el foco sigue en el modal (el siguiente Escape lo cierra)
        }
        // Escape: en el campo del cupo cancela solo ese cambio; si no, cierra el modal.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !modal.classList.contains('active')) return;
            e.stopPropagation();
            var editando = e.target.closest && e.target.closest('.cupos-cupo-editar');
            if (editando) cancelarCupo(editando.closest('[data-panel]')); else cerrar();
        });
    })();
</script>
