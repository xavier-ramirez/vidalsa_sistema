{{-- Cargar consumibles → «Cupos de Gasoil»: una tarjeta con lo disponible de cada patio y el botón
     que abre el modal (pestaña por patio: cupo editable, cisternas del mes y su historial). El cuerpo
     del modal se repinta solo (consumibles.cupos?parcial=1). Con ?mes= en la dirección se abre solo. --}}
<style>
    .cupos-card { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;
        padding: 10px 20px; margin-top: 20px; }
    .cupos-res { margin: 4px 0 0; color: #718096; font-size: 12px; display: flex; flex-wrap: wrap; gap: 4px 16px; }
    .cupos-card .btn-primary-maquinaria { padding: 7px 13px; font-size: 12.5px; margin: 0; }
    .cupos-card .btn-primary-maquinaria .material-icons { font-size: 16px; }

    .cupos-modal { width: 94%; max-width: 920px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; text-align: left; }
    .cupos-modal-cab { background: var(--maquinaria-dark-blue); color: #fff; padding: 12px 18px; display: flex;
        justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 6px; }
    .cupos-modal-cab-der { display: flex; align-items: center; gap: 8px; }
    .cupos-modal-cab input { height: 32px; border-radius: 8px; border: none; padding: 0 10px; font-size: 13px; font-family: inherit; cursor: pointer; }
    .cupos-modal-x { background: none; border: none; color: #fff; cursor: pointer; display: flex; padding: 0; }
    #cuposContenido { padding: 0 18px 18px; overflow-y: auto; }

    .cupos-tabs { display: flex; gap: 4px; border-bottom: 1px solid #e2e8f0; margin: 0 -18px 14px; padding: 10px 18px 0;
        background: #f8fafc; position: sticky; top: 0; z-index: 2; }
    .cupos-tab { border: none; background: none; padding: 8px 14px; font-size: 13px; font-weight: 600; color: #64748b; cursor: pointer;
        border-bottom: 3px solid transparent; font-family: inherit; display: flex; flex-direction: column; align-items: flex-start; }
    .cupos-tab small { font-size: 11px; font-weight: 400; }
    .cupos-tab.activo { color: #0f172a; border-bottom-color: var(--color); background: #fff; border-radius: 8px 8px 0 0; }

    .cupos-cifras { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
    .cupos-cifra { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 8px 12px; }
    .cupos-cifra small { display: block; font-size: 11px; color: #64748b; }
    .cupos-cifra b { font-size: 17px; color: #0f172a; }
    .cupos-nota { display: block; font-size: 11px; color: #94a3b8; }
    .cupos-cupo-ver { display: flex; align-items: center; gap: 6px; }
    .cupos-cupo-ver[hidden] { display: none; }
    .cupos-lapiz { border: none; background: none; cursor: pointer; color: #0067b1; padding: 0; display: flex; }
    .cupos-lapiz i { font-size: 17px; }
    .cupos-cupo-editar { display: flex; gap: 4px; align-items: center; margin: 2px 0; }
    .cupos-cupo-editar[hidden] { display: none; }
    .cupos-cupo-editar input { width: 100%; min-width: 0; height: 30px; border: 1px solid #0067b1; border-radius: 6px; padding: 0 8px;
        font-size: 13px; font-family: inherit; outline: none; box-sizing: border-box; }
    .cupos-mini { border: none; border-radius: 6px; background: #0067b1; color: #fff; height: 30px; width: 30px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
    .cupos-mini i { font-size: 17px; }
    .cupos-mini-gris { background: #e2e8f0; color: #475569; }
    .cupos-barra { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: 8px; }
    .cupos-barra i { display: block; height: 100%; border-radius: 3px; }
    .cupos-barra-fina { margin-top: 0; }

    .cupos-form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 14px 0 0; }
    .cupos-form input { height: 36px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fbfcfd; padding: 0 10px;
        font-size: 13px; font-family: inherit; outline: none; min-width: 0; box-sizing: border-box; }
    .cupos-form input:focus { border-color: #0067b1; }
    .cupos-form input[name=fecha] { width: 145px; }
    .cupos-form input[name=cisterna] { flex: 1; min-width: 150px; }
    .cupos-form input[name=litros] { width: 120px; }
    .cupos-form .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; }

    /* Tablas con el estilo de la Lista de Consumibles. */
    .cupos-tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 12px; }
    .cupos-tbl { width: 100%; border-collapse: collapse; font-size: 12px; min-width: 520px; }
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
    .cupos-tbl a { color: #0067b1; font-weight: 600; text-decoration: none; }
    .cupos-tbl tr.cupos-actual td { background: #e1effa !important; }
    .cupos-vacio { color: #94a3b8; font-style: italic; text-align: center; }
    .cupos-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 2px; display: inline-flex; }
    .cupos-borrar:hover { color: #dc2626; }
    .cupos-borrar i { font-size: 18px; }
    .cupos-sub { margin: 18px 0 0; font-size: 13px; color: #334155; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }
    @media (max-width: 600px) {
        .cupos-cifras { grid-template-columns: 1fr; }
        .cupos-form input[name=fecha], .cupos-form input[name=litros] { flex: 1 1 40%; width: auto; }
        .cupos-form input[name=cisterna], .cupos-form .btn-primary-maquinaria { flex: 1 1 100%; justify-content: center; }
    }
</style>

<div id="cuposSeccion" class="admin-card cupos-card">
    <div>
        <h3 class="imp-carga-titulo"><i class="material-icons">local_gas_station</i> Cupos de Gasoil</h3>
        <div class="cupos-res" id="cuposResumen">@include('admin.consumibles.partials.cupos_resumen')</div>
    </div>
    <button type="button" class="btn-primary-maquinaria" data-abrir-modal>
        <i class="material-icons">open_in_new</i> Ver cupos y cisternas
    </button>
</div>

<div id="cuposModal" class="modal-overlay" data-abrir="{{ request()->filled('mes') ? '1' : '' }}">
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
        var tarjeta = document.getElementById('cuposSeccion');

        function caja() { return document.getElementById('cuposContenido'); }
        function mesActual() { var d = document.getElementById('cuposDatos'); return d ? d.dataset.mes : ''; }
        function aviso(msg, tipo) { if (window.showToast) window.showToast(msg, tipo); else console.log(msg); }

        // La pestaña elegida se mantiene al repintar y al volver a abrir.
        function mostrarPatio(patio) {
            if (!modal.querySelector('[data-panel="' + patio + '"]')) patio = 'TIGRE';
            window.__cuposPatio = patio;
            modal.querySelectorAll('[data-tab]').forEach(function (t) { t.classList.toggle('activo', t.dataset.tab === patio); });
            modal.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== patio; });
        }
        mostrarPatio(window.__cuposPatio || 'TIGRE');

        function abrir() { modal.classList.add('active'); }
        function cerrar() { modal.classList.remove('active'); }
        if (modal.dataset.abrir) abrir();

        // Repinta el cuerpo del mes (sin recargar la página) y deja el mes en la dirección.
        function repintar(mes) {
            var c = caja();
            c.classList.add('cupos-ocupado');
            return window.apiFetch(URL_LISTA + '?parcial=1&mes=' + encodeURIComponent(mes), { headers: JSON_H })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) {
                    c.innerHTML = html;
                    mostrarPatio(window.__cuposPatio);
                    var nuevo = document.getElementById('cuposResumenNuevo');
                    if (nuevo) document.getElementById('cuposResumen').innerHTML = nuevo.innerHTML;
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

        tarjeta.addEventListener('click', function (e) { if (e.target.closest('[data-abrir-modal]')) abrir(); });

        modal.addEventListener('submit', function (e) {
            var f = e.target.closest('form[data-accion]');
            if (!f) return;
            e.preventDefault();
            var datos = new FormData(f);
            datos.append('mes', mesActual());
            var esCupo = f.dataset.accion === 'cupo';
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
            var tab = e.target.closest('[data-tab]');
            if (tab) { mostrarPatio(tab.dataset.tab); return; }
            var ir = e.target.closest('[data-mes-ir]');
            if (ir) { e.preventDefault(); repintar(ir.dataset.mesIr); caja().scrollTop = 0; return; }
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
