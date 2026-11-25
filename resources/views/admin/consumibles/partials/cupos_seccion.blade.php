{{-- Cargar consumibles → modal «Cupos de Gasoil»: el cupo fijo de cada patio y lo que queda, un solo
     formulario para anotar la cisterna surtida (patio, fecha y litros) y una tabla con las del mes. Lo
     abre «Cupos de gasoil» del menú Acciones; con ?cupos=1 o ?mes= en la dirección se abre solo (desde
     las otras pantallas). El cuerpo se repinta solo (consumibles.cupos?parcial=1). --}}
<style>
    .cupos-modal { width: 94%; max-width: 760px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; text-align: left; }
    .cupos-modal-cab { background: var(--maquinaria-dark-blue); color: #fff; padding: 12px 18px; display: flex;
        justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
    .cupos-modal-cab-der { display: flex; align-items: center; gap: 8px; }
    .cupos-modal-cab input { height: 32px; border-radius: 8px; border: none; padding: 0 10px; font-size: 13px; font-family: inherit; cursor: pointer; }
    .cupos-modal-x { background: none; border: none; color: #fff; cursor: pointer; display: flex; padding: 0; }
    #cuposContenido { padding: 0 18px 18px; overflow-y: auto; }

    .cupos-patios { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; padding-top: 16px; }
    .cupos-patio { border: 1px solid #e2e8f0; border-left: 5px solid var(--color); border-radius: 12px; padding: 12px 14px; min-width: 0;
        background: linear-gradient(135deg, #fff 60%, #f1f5f9); }
    .cupos-patio-cab { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 13px; color: #475569; }
    .cupos-patio-cab > b { font-size: 15px; color: #0f172a; }

    .cupos-quedan { font-size: 24px; font-weight: 800; margin-top: 8px; line-height: 1.1; }
    .cupos-quedan small { font-size: 12px; font-weight: 500; color: #64748b; }
    .cupos-surtido { font-size: 12px; color: #64748b; margin-top: 6px; }
    .cupos-cupo-ver { display: flex; align-items: center; gap: 6px; }
    .cupos-cupo-ver[hidden] { display: none; }
    .cupos-lapiz { border: none; background: none; cursor: pointer; color: #0067b1; padding: 0; display: flex; }
    .cupos-lapiz i { font-size: 17px; }
    .cupos-cupo-editar { display: flex; gap: 4px; align-items: center; margin: 2px 0; }
    .cupos-cupo-editar[hidden] { display: none; }
    .cupos-cupo-editar input { width: 130px; min-width: 0; height: 30px; border: 1px solid #0067b1; border-radius: 6px; padding: 0 8px;
        font-size: 13px; font-family: inherit; outline: none; box-sizing: border-box; }
    .cupos-mini { border: none; border-radius: 6px; background: #0067b1; color: #fff; height: 30px; width: 30px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
    .cupos-mini i { font-size: 17px; }
    .cupos-mini-gris { background: #e2e8f0; color: #475569; }
    .cupos-barra { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 8px; }
    .cupos-barra i { display: block; height: 100%; border-radius: 3px; }

    .cupos-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 16px 0 0; padding: 10px;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
    .cupos-form input { height: 36px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fbfcfd; padding: 0 10px;
        font-size: 13px; font-family: inherit; outline: none; min-width: 0; box-sizing: border-box; }
    .cupos-form input:focus { border-color: #0067b1; }
    .cupos-form input[name=fecha] { flex: 1; min-width: 130px; }
    .cupos-form input[name=litros] { flex: 1; min-width: 100px; }
    .cupos-elegir { display: flex; border: 1px solid #cbd5e0; border-radius: 8px; overflow: hidden; height: 36px; background: #fff; }
    .cupos-elegir label { display: flex; align-items: center; padding: 0 14px; font-size: 13px; cursor: pointer; color: #475569; transition: background .15s; }
    .cupos-elegir label + label { border-left: 1px solid #cbd5e0; }
    .cupos-elegir input { position: absolute; opacity: 0; pointer-events: none; }
    .cupos-elegir label:has(input:checked) { background: var(--color); color: #fff; font-weight: 600; }
    .cupos-elegir label:has(input:focus-visible) { outline: 2px solid #0067b1; outline-offset: -2px; }
    .cupos-form .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; }

    /* Tabla con el estilo de la Lista de Consumibles. */
    .cupos-tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 12px; }
    .cupos-tbl { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 300px; }
    .cupos-tbl th { background: #1e293b; color: #e2e8f0; font-weight: 700; padding: 8px; font-size: 11px; text-transform: uppercase;
        letter-spacing: .4px; border-right: 1px solid #334155; white-space: nowrap; text-align: left; }
    .cupos-tbl th:last-child { border-right: none; }
    .cupos-tbl td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; border-right: 1px solid #f1f5f9; color: #1e293b; }
    .cupos-tbl tbody tr:nth-child(even) td { background: #f8fafc; }
    .cupos-tbl tbody tr:hover td { background: #eff6ff; }
    .cupos-tbl tbody tr:last-child td { border-bottom: none; }
    .cupos-tbl .num { text-align: right; white-space: nowrap; }
    .cupos-tbl .cupos-n { text-align: center; color: #94a3b8; font-size: 10px; font-weight: 700; width: 28px; background: #f8fafc; border-right: 2px solid #e2e8f0; }
    .cupos-tbl .cupos-acc { width: 34px; text-align: center; border-right: none; }
    .cupos-vacio { color: #94a3b8; font-style: italic; text-align: center; }
    .cupos-punto { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
    .cupos-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 2px; display: inline-flex; }
    .cupos-borrar:hover { color: #dc2626; }
    .cupos-borrar i { font-size: 18px; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }
    @media (max-width: 600px) {
        .cupos-patios { grid-template-columns: 1fr; }
        .cupos-elegir { flex: 1 1 100%; }
        .cupos-elegir label { flex: 1; justify-content: center; }
        .cupos-form .btn-primary-maquinaria { flex: 1 1 100%; justify-content: center; }
    }
</style>

<div id="cuposModal" class="modal-overlay" data-abrir="{{ request()->boolean('cupos') || request()->filled('mes') ? '1' : '' }}">
    <div class="modal-content cupos-modal" role="dialog" aria-labelledby="cuposModalTitulo">
        <div class="cupos-modal-cab">
            <h2 id="cuposModalTitulo"><i class="material-icons">local_gas_station</i> Cupos de Gasoil</h2>
            <div class="cupos-modal-cab-der">
                <input type="month" id="cuposMes" class="native-date" value="{{ $mes->format('Y-m') }}" title="Mes"
                    onclick="try{this.showPicker()}catch(e){}">
                <button type="button" class="cupos-modal-x" data-cerrar-modal title="Cerrar"><i class="material-icons">close</i></button>
            </div>
        </div>
        <div id="cuposContenido">
            @include('admin.consumibles.partials.cupos_contenido')
        </div>
    </div>
</div>

<script>
    (function () {
        var URL_LISTA = @json(route('consumibles.cupos'));
        var URL_CARGAR = @json(route('consumibles.cargar'));
        var URL_CUPO = @json(route('consumibles.cupos.guardar'));
        var URL_CISTERNA = @json(route('consumibles.cupos.cisterna'));
        var URL_BORRAR = @json(url('admin/consumibles/cupos/cisternas')) + '/';
        var JSON_H = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var modal = document.getElementById('cuposModal');
        if (!modal) return;

        function caja() { return document.getElementById('cuposContenido'); }
        function mesActual() { var d = document.getElementById('cuposDatos'); return d ? d.dataset.mes : ''; }
        function aviso(msg, tipo) { if (window.showToast) window.showToast(msg, tipo); else console.log(msg); }

        // El patio elegido en el formulario se mantiene al repintar (se suelen anotar varias seguidas).
        function marcarPatio() {
            var r = window.__cuposPatio && modal.querySelector('input[name=patio][value="' + window.__cuposPatio + '"]');
            if (r) r.checked = true;
        }
        marcarPatio();

        function abrir() { modal.classList.add('active'); }
        // Al cerrar, la dirección vuelve a Cargar sola: recargar no lo abre de nuevo.
        function cerrar() { modal.classList.remove('active'); history.replaceState(history.state, '', URL_CARGAR); }
        if (modal.dataset.abrir) abrir();

        // Repinta el cuerpo del mes (sin recargar la página) y deja el mes en la dirección.
        function repintar(mes) {
            var c = caja();
            c.classList.add('cupos-ocupado');
            return window.apiFetch(URL_LISTA + '?parcial=1&mes=' + encodeURIComponent(mes), { headers: JSON_H })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) {
                    c.innerHTML = html;
                    marcarPatio();
                    document.getElementById('cuposMes').value = mes;
                    history.replaceState(history.state, '', URL_CARGAR + '?mes=' + mes);
                })
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

        document.getElementById('cuposMes').addEventListener('change', function () {
            if (/^\d{4}-\d{2}$/.test(this.value)) repintar(this.value);
        });

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
            var datos = new FormData(f);
            datos.append('mes', mesActual());
            var esCupo = f.dataset.accion === 'cupo';
            if (!esCupo) window.__cuposPatio = datos.get('patio');
            f.classList.add('cupos-ocupado');
            enviar(esCupo ? URL_CUPO : URL_CISTERNA, 'POST', datos)
                .then(function () {
                    aviso(esCupo ? 'Cupo guardado.' : 'Cisterna agregada.', 'success');
                    return repintar(mesActual());
                })
                .catch(function (m) { aviso(typeof m === 'string' ? m : 'No se pudo guardar.', 'error'); })
                .finally(function () { f.classList.remove('cupos-ocupado'); });
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('[data-cerrar-modal]')) { cerrar(); return; }
            var panel = e.target.closest('[data-panel]');
            if (e.target.closest('[data-editar-cupo]')) {
                panel.querySelector('.cupos-cupo-ver').hidden = true;
                var f = panel.querySelector('.cupos-cupo-editar');
                f.hidden = false;
                f.querySelector('input').focus();
                return;
            }
            if (e.target.closest('[data-cancelar-cupo]')) {
                panel.querySelector('.cupos-cupo-editar').hidden = true;
                panel.querySelector('.cupos-cupo-ver').hidden = false;
                return;
            }
            var b = e.target.closest('[data-borrar]');
            if (!b) return;
            window.confirmarAccion({
                title: 'Quitar cisterna',
                message: 'Se quitará: <b>' + b.dataset.texto.replace(/</g, '&lt;') + '</b>',
                confirmText: 'Quitar', type: 'warning',
            }, function () {
                enviar(URL_BORRAR + b.dataset.borrar, 'DELETE')
                    .then(function () { aviso('Cisterna quitada.', 'success'); return repintar(mesActual()); })
                    .catch(function (m) { aviso(typeof m === 'string' ? m : 'No se pudo quitar.', 'error'); });
            });
        });

        modal.addEventListener('keydown', function (e) { if (e.key === 'Escape') cerrar(); });
    })();
</script>
