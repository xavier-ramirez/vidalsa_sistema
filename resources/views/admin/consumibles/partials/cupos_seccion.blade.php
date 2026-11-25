{{-- Cargar consumibles → «Cupos de Gasoil»: el cupo del mes de cada patio (El Tigre, Maturín) y
 las cisternas pedidas de él (fecha, cisterna, litros). Se repinta solo tras guardar
 (consumibles.cupos?parcial=1). --}}
<style>
    .cupos-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; }
    .cupo-tarjeta { padding: 18px 20px; margin: 0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
    .cupo-titulo { border-left: 4px solid; padding-left: 10px; margin-bottom: 14px; display: flex; flex-direction: column; }
    .cupo-titulo span { font-size: 16px; font-weight: 700; color: #0f172a; }
    .cupo-titulo small { font-size: 12px; color: #64748b; }
    .cupo-form label { display: block; font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 5px; }
    .cupo-fila, .cupo-nueva { display: flex; gap: 8px; align-items: center; }
    .cupo-form input { height: 38px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fbfcfd; padding: 0 10px;
        font-size: 13px; font-family: inherit; outline: none; min-width: 0; box-sizing: border-box; }
    .cupo-form input:focus { border-color: #0067b1; }
    .cupo-fila input { flex: 1; }
    .cupo-form .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; }
    .cupo-cifras { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 16px 0 8px; }
    .cupo-cifras div { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; }
    .cupo-cifras small { display: block; font-size: 11px; color: #64748b; }
    .cupo-cifras b { font-size: 15px; color: #0f172a; }
    .cupo-barra { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
    .cupo-barra span { display: block; height: 100%; border-radius: 4px; }
    .cupo-aviso { color: #dc2626; font-size: 12px; font-weight: 600; margin-top: 6px; }
    .cupo-tabla { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 12px; }
    .cupo-tabla th { background: #1e293b; color: #e2e8f0; font-size: 11px; text-transform: uppercase; letter-spacing: .4px;
        padding: 7px 8px; text-align: left; white-space: nowrap; }
    .cupo-tabla td { padding: 6px 8px; border-bottom: 1px solid #e2e8f0; color: #0f172a; }
    .cupo-tabla tbody tr:nth-child(even) { background: #f8fafc; }
    .cupo-resumen td a { color: #0067b1; font-weight: 600; text-decoration: none; }
    .cupo-vacio { color: #94a3b8; font-style: italic; text-align: center; font-size: 12px; padding: 10px 0; }
    .cupo-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 2px; display: flex; }
    .cupo-borrar:hover { color: #dc2626; }
    .cupo-borrar i { font-size: 18px; }
    .cupo-nueva { margin-top: 12px; }
    .cupo-nueva input[name="fecha"] { width: 140px; }
    .cupo-nueva input[name="cisterna"] { flex: 1; }
    .cupo-nueva input[name="litros"] { width: 110px; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }
    @media (max-width: 600px) {
        .cupos-grid { grid-template-columns: 1fr; }
        .cupo-nueva { flex-wrap: wrap; }
        .cupo-nueva input[name="fecha"], .cupo-nueva input[name="litros"] { flex: 1 1 40%; width: auto; }
        .cupo-nueva input[name="cisterna"], .cupo-nueva .btn-primary-maquinaria { flex: 1 1 100%; justify-content: center; }
    }
</style>

<div id="cuposSeccion" class="admin-card imp-carga" style="margin-top:20px;">
<div>
    <h3 class="imp-carga-titulo"><i class="material-icons">local_gas_station</i> Cupos de Gasoil</h3>
    <p class="imp-carga-sub">El cupo del mes de cada patio y las cisternas que se piden de él, con su fecha y sus litros.</p>
</div>
<input type="month" id="cuposMes" class="native-date" value="{{ $mes->format('Y-m') }}" title="Mes del cupo"
    style="height:38px; border-radius:10px; border:1px solid #cbd5e0; background:#fbfcfd; padding:0 12px; font-size:13px; font-family:inherit; cursor:pointer;"
    onclick="try{this.showPicker()}catch(e){}">
</div>

<div id="cuposContenido">
@include('admin.consumibles.partials.cupos_contenido')
</div>

<script>
    (function () {
        var URL_LISTA = @json(route('consumibles.cupos'));
        var URL_CARGAR = @json(route('consumibles.cargar'));
        var URL_CUPO = @json(route('consumibles.cupos.guardar'));
        var URL_CISTERNA = @json(route('consumibles.cupos.cisterna'));
        var URL_BORRAR = @json(url('admin/consumibles/cupos/cisternas')) + '/';
        var JSON_H = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

        function caja() { return document.getElementById('cuposContenido'); }
        function mesActual() { var d = document.getElementById('cuposDatos'); return d ? d.dataset.mes : ''; }
        function aviso(msg, tipo) { if (window.showToast) window.showToast(msg, tipo); else console.log(msg); }

        // Repinta el contenido del mes (sin recargar la página) y deja el mes en la dirección.
        function repintar(mes) {
            var c = caja();
            if (!c) return Promise.resolve();
            c.classList.add('cupos-ocupado');
            return window.apiFetch(URL_LISTA + '?parcial=1&mes=' + encodeURIComponent(mes), { headers: JSON_H })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) {
                    c.innerHTML = html;
                    var sel = document.getElementById('cuposMes');
                    if (sel) sel.value = mes;
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

        var sel = document.getElementById('cuposMes');
        if (sel) sel.addEventListener('change', function () { if (/^\d{4}-\d{2}$/.test(this.value)) repintar(this.value); });

        var c = caja();
        if (!c) return;
        c.addEventListener('submit', function (e) {
            var f = e.target.closest('form[data-accion]');
            if (!f) return;
            e.preventDefault();
            var datos = new FormData(f);
            datos.append('mes', mesActual());
            var url = f.dataset.accion === 'cupo' ? URL_CUPO : URL_CISTERNA;
            f.classList.add('cupos-ocupado');
            enviar(url, 'POST', datos)
                .then(function () {
                    aviso(f.dataset.accion === 'cupo' ? 'Cupo guardado.' : 'Cisterna agregada.', 'success');
                    return repintar(mesActual());
                })
                .catch(function (m) { aviso(typeof m === 'string' ? m : 'No se pudo guardar.', 'error'); })
                .finally(function () { f.classList.remove('cupos-ocupado'); });
        });
        c.addEventListener('click', function (e) {
            var ir = e.target.closest('[data-mes-ir]');
            if (ir) { e.preventDefault(); repintar(ir.dataset.mesIr); document.getElementById('cuposSeccion').scrollIntoView({ behavior: 'smooth' }); return; }
            var b = e.target.closest('[data-borrar]');
            if (!b) return;
            window.confirmarAccion({
                title: 'Quitar cisterna',
                message: 'Se quitará del cupo: <b>' + b.dataset.texto.replace(/</g, '&lt;') + '</b>',
                confirmText: 'Quitar', type: 'warning',
            }, function () {
                enviar(URL_BORRAR + b.dataset.borrar, 'DELETE')
                    .then(function () { aviso('Cisterna quitada.', 'success'); return repintar(mesActual()); })
                    .catch(function (m) { aviso(typeof m === 'string' ? m : 'No se pudo quitar.', 'error'); });
            });
        });
    })();
</script>
