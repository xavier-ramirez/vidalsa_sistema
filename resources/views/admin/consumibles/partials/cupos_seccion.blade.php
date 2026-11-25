{{-- Cargar consumibles → «Cupos de Gasoil»: un formulario para anotar las cisternas pedidas de cada
     patio (El Tigre, Maturín) y un modal para el cupo mensual, que casi nunca cambia. Se repinta solo
     tras guardar (consumibles.cupos?parcial=1). --}}
<style>
    .cupos-card { padding: 16px 20px; margin-top: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .cupos-cab { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 12px; }
    .cupos-cab-der { display: flex; gap: 8px; align-items: center; }
    .cupos-cab-der input { height: 36px; border-radius: 8px; border: 1px solid #cbd5e0; background: #fbfcfd; padding: 0 10px;
        font-size: 13px; font-family: inherit; cursor: pointer; }
    .cupos-cab-der .btn-primary-maquinaria { margin-left: 0; }
    .cupos-patios { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 10px; }
    .cupo-patio { border-left: 4px solid; background: #f8fafc; border-radius: 8px; padding: 8px 12px; font-size: 12px; color: #475569;
        display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: baseline; }
    .cupo-patio > b { color: #0f172a; font-size: 13px; flex-basis: 100%; }
    .cupo-patio span b { color: #0f172a; }
    .cupo-barra { flex-basis: 100%; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 2px; }
    .cupo-barra i { display: block; height: 100%; border-radius: 3px; }
    .cupo-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 12px; }
    .cupo-form input[type=date], .cupo-form input[type=text], .cupo-form input[type=number], .cupos-modal-campo input {
        height: 36px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fbfcfd; padding: 0 10px;
        font-size: 13px; font-family: inherit; outline: none; min-width: 0; box-sizing: border-box; }
    .cupo-form input:focus, .cupos-modal-campo input:focus { border-color: #0067b1; }
    .cupo-form input[name=fecha] { width: 140px; }
    .cupo-form input[name=cisterna] { flex: 1; min-width: 150px; }
    .cupo-form input[name=litros] { width: 110px; }
    .cupo-form .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; }
    .cupo-patio-elegir { display: flex; border: 1px solid #cbd5e0; border-radius: 8px; overflow: hidden; height: 36px; }
    .cupo-patio-elegir label { display: flex; align-items: center; padding: 0 12px; font-size: 13px; cursor: pointer; color: #475569; }
    .cupo-patio-elegir label + label { border-left: 1px solid #cbd5e0; }
    .cupo-patio-elegir input { position: absolute; opacity: 0; pointer-events: none; }
    .cupo-patio-elegir label:has(input:checked) { background: #0067b1; color: #fff; font-weight: 600; }
    .cupo-tabla { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 12px; }
    .cupo-tabla th { background: #1e293b; color: #e2e8f0; font-size: 11px; text-transform: uppercase; letter-spacing: .4px;
        padding: 7px 8px; text-align: left; white-space: nowrap; }
    .cupo-tabla td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; color: #0f172a; }
    .cupo-tabla tbody tr:nth-child(even) { background: #f8fafc; }
    .cupo-vacio { color: #94a3b8; font-style: italic; text-align: center; }
    .cupo-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 2px; display: flex; }
    .cupo-borrar:hover { color: #dc2626; }
    .cupo-borrar i { font-size: 18px; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }
    .cupos-modal { width: 92%; max-width: 520px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; }
    .cupos-modal-cab { background: var(--maquinaria-dark-blue); color: #fff; padding: 12px 18px; display: flex; justify-content: space-between; gap: 8px; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; }
    .cupos-modal-cab p { margin: 3px 0 0; font-size: 12px; opacity: .8; }
    .cupos-modal-x { background: none; border: none; color: #fff; cursor: pointer; display: flex; padding: 0; }
    .cupos-modal-cuerpo { padding: 16px 18px; overflow-y: auto; text-align: left; }
    .cupos-modal-campo { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
    .cupos-modal-campo span { font-size: 13px; font-weight: 600; color: #0f172a; display: flex; flex-direction: column; }
    .cupos-modal-campo small { font-weight: 400; color: #64748b; font-size: 11px; }
    .cupos-modal-campo input { width: 170px; }
</style>

<div id="cuposSeccion" class="admin-card cupos-card">
    <div class="cupos-cab">
        <h3 class="imp-carga-titulo"><i class="material-icons">local_gas_station</i> Cupos de Gasoil</h3>
        <div class="cupos-cab-der">
            <input type="month" id="cuposMes" class="native-date" value="{{ $mes->format('Y-m') }}" title="Mes"
                onclick="try{this.showPicker()}catch(e){}">
            <button type="button" class="btn-primary-maquinaria btn-secondary btn-compacto" data-abrir-modal>
                <i class="material-icons">tune</i> Cupo mensual
            </button>
        </div>
    </div>

    <div id="cuposContenido">
        @include('admin.consumibles.partials.cupos_contenido')
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
        var seccion = document.getElementById('cuposSeccion');
        if (!seccion) return;

        function caja() { return document.getElementById('cuposContenido'); }
        function modal() { return document.getElementById('cuposModal'); }
        function mesActual() { var d = document.getElementById('cuposDatos'); return d ? d.dataset.mes : ''; }
        function aviso(msg, tipo) { if (window.showToast) window.showToast(msg, tipo); else console.log(msg); }
        // El patio elegido se mantiene al repintar (se suelen anotar varias del mismo patio seguidas).
        function marcarPatio() {
            var r = window.__cuposPatio && seccion.querySelector('input[name=patio][value="' + window.__cuposPatio + '"]');
            if (r) r.checked = true;
        }
        marcarPatio();

        // Repinta el contenido del mes (sin recargar la página) y deja el mes en la dirección.
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

        seccion.addEventListener('submit', function (e) {
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

        seccion.addEventListener('click', function (e) {
            if (e.target.closest('[data-abrir-modal]')) { modal().classList.add('active'); return; }
            if (e.target.closest('[data-cerrar-modal]')) { modal().classList.remove('active'); return; }
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

        seccion.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal().classList.contains('active')) modal().classList.remove('active');
        });
    })();
</script>
