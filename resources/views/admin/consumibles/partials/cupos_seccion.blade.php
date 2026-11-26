{{-- Cargar consumibles → modal «Cupos de Gasoil»: se elige el patio y de él se ve el cupo (se edita
     ahí), se cargan las cisternas surtidas (fecha y litros) y se ve su historial mes a mes. Lo abre
     «Cupos de gasoil» del menú Acciones; con ?cupos=1 en la dirección se abre solo (desde las otras
     pantallas). El cuerpo se repinta solo (consumibles.cupos?parcial=1). --}}
<style>
    .cupos-modal { width: 94%; max-width: 640px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; text-align: left; }
    .cupos-modal-cab { background: var(--maquinaria-dark-blue); color: #fff; padding: 12px 18px; display: flex;
        justify-content: space-between; align-items: center; gap: 10px; }
    .cupos-modal-cab { position: relative; justify-content: center; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 6px; }
    .cupos-modal:focus { outline: none; }
    .cupos-modal-x { background: none; border: none; color: #fff; cursor: pointer; display: flex; padding: 0;
        position: absolute; right: 18px; top: 50%; transform: translateY(-50%); }
    #cuposContenido { padding: 16px 18px 18px; overflow-y: auto; }

    .cupos-patio { display: flex; align-items: center; gap: 10px; }
    .cupos-patio span { font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
    .cupos-patio select { flex: 1; min-width: 0; height: 40px; border: 1px solid #cbd5e0; border-radius: 10px; background: #fff;
        padding: 0 12px; font-size: 14px; font-weight: 600; color: #0f172a; font-family: inherit; cursor: pointer; outline: none; }
    .cupos-patio select:focus { border-color: #0067b1; }

    .cupos-cifras { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 16px; }
    .cupos-cifra { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; text-align: center;
        min-width: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; }
    .cupos-cifra small { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; }
    .cupos-cifra b { font-size: 18px; color: #0f172a; white-space: nowrap; }
    .cupos-cupo-ver { display: flex; align-items: center; justify-content: center; gap: 4px; }
    .cupos-cupo-ver[hidden] { display: none; }
    .cupos-lapiz { border: none; background: none; cursor: pointer; color: #0067b1; padding: 0; display: flex; }
    .cupos-lapiz i { font-size: 18px; }
    .cupos-cupo-editar { display: flex; gap: 4px; align-items: center; width: 100%; }
    .cupos-cupo-editar[hidden] { display: none; }
    .cupos-cupo-editar input { flex: 1; min-width: 0; width: 0; height: 32px; border: 1px solid #0067b1; border-radius: 6px; padding: 0 8px;
        font-size: 13px; font-family: inherit; outline: none; box-sizing: border-box; }
    .cupos-mini { border: none; border-radius: 6px; background: #0067b1; color: #fff; height: 32px; width: 32px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
    .cupos-mini i { font-size: 18px; }
    .cupos-mini-gris { background: #e2e8f0; color: #475569; }
    .cupos-barra { height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; margin-top: 10px; }
    .cupos-barra i { display: block; height: 100%; border-radius: 4px; }

    /* Tabla para cargar varias cisternas de una vez. */
    .cupos-lote { margin-top: 16px; padding: 10px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; }
    .cupos-lote-titulo { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .4px; margin: 0 0 8px 2px; }
    .cupos-lote-tbl { width: 100%; border-collapse: separate; border-spacing: 0 6px; table-layout: fixed; margin-top: -6px; }
    .cupos-lote-tbl th { font-size: 11px; color: #64748b; font-weight: 600; text-align: left; padding: 0 4px; text-transform: uppercase; }
    .cupos-lote-tbl td { padding: 0 4px; }
    .cupos-lote-n { text-align: center; font-size: 12px; color: #94a3b8; font-weight: 700; }
    .cupos-lote-tbl input { height: 36px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fff; padding: 0 10px;
        font-size: 13px; font-family: inherit; outline: none; width: 100%; box-sizing: border-box; }
    .cupos-lote-tbl input:focus { border-color: #0067b1; }
    .cupos-lote-tbl input.cupos-mal { border-color: #dc2626; background: #fef2f2; }
    .cupos-lote-pie { display: flex; align-items: center; gap: 10px; margin-top: 4px; }
    .cupos-otra { border: 1px dashed #94a3b8; background: #fff; color: #0067b1; border-radius: 8px; height: 36px; padding: 0 12px;
        font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
    .cupos-otra i { font-size: 18px; }
    .cupos-otra:hover { border-color: #0067b1; }
    .cupos-lote-total { flex: 1; text-align: right; font-size: 12px; color: #475569; }
    .cupos-lote .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; height: 36px; justify-content: center; }

    /* Historial con el estilo de la tabla de la Lista de Consumibles; una fila por mes con su resumen. */
    .cupos-tbl-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e2e8f0; margin-top: 14px; }
    .cupos-tbl { width: 100%; border-collapse: collapse; font-size: 12px; table-layout: fixed; }
    .cupos-tbl th { background: #1e293b; color: #e2e8f0; font-weight: 700; padding: 8px; font-size: 11px; text-transform: uppercase;
        letter-spacing: .4px; white-space: nowrap; text-align: left; }
    .cupos-tbl td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; color: #1e293b; }
    .cupos-tbl tr.cupos-mes td { background: #eef4fa; color: #475569; border-left: 4px solid var(--color); }
    .cupos-tbl tr.cupos-mes b { color: #0f172a; }
    .cupos-tbl tr.cupos-mes span { float: right; font-size: 11px; }
    .cupos-tbl .num, .cupos-tbl th.num { text-align: right; white-space: nowrap; }
    .cupos-tbl .cupos-acc { width: 34px; text-align: center; }
    .cupos-vacio { color: #94a3b8; text-align: center; padding: 22px 8px !important; }
    .cupos-vacio i { display: block; width: max-content; margin: 0 auto 4px; font-size: 30px; color: #cbd5e1; }
    .cupos-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 2px; display: inline-flex; }
    .cupos-borrar:hover { color: #dc2626; }
    .cupos-borrar i { font-size: 18px; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }
    @media (max-width: 600px) {
        .cupos-cifra { padding: 8px 6px; }
        .cupos-cifra small { font-size: 10px; }
        .cupos-cifra b { font-size: 14px; }
        .cupos-lote { padding: 8px; }
        .cupos-lote-tbl col:first-child { width: 18px !important; }
        .cupos-lote-tbl col:last-child { width: 28px !important; }
        .cupos-lote-tbl td, .cupos-lote-tbl th { padding: 0 2px; }
        .cupos-lote-tbl input { padding: 0 6px; font-size: 12px; }
        .cupos-lote-pie { flex-wrap: wrap; }
        .cupos-lote .btn-primary-maquinaria { flex: 1 1 100%; }
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
            var sel = modal.querySelector('[data-elegir-patio]');
            if (sel) sel.value = patio;
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
                        if (!r.ok) return Promise.reject({ mensaje: b.message || 'No se pudo guardar.', errores: b.errors || {} });
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
            var datos = new FormData(f), filas = [];
            if (!esCupo) {
                // Solo las filas con litros (las vacías no cuentan); cada una con su número para marcar errores.
                filas = Array.prototype.filter.call(f.querySelectorAll('[data-fila]'), function (tr) {
                    return tr.querySelector('[data-campo=litros]').value.trim() !== '';
                });
                if (!filas.length) {
                    delete f.dataset.enviando;
                    aviso('Escriba los litros de al menos una cisterna.', 'error');
                    f.querySelector('[data-campo=litros]').focus();
                    return;
                }
                filas.forEach(function (tr, i) {
                    datos.append('cisternas[' + i + '][fecha]', tr.querySelector('[data-campo=fecha]').value);
                    datos.append('cisternas[' + i + '][litros]', tr.querySelector('[data-campo=litros]').value);
                });
                f.querySelectorAll('.cupos-mal').forEach(function (i) { i.classList.remove('cupos-mal'); });
            }
            f.classList.add('cupos-ocupado');
            enviar(esCupo ? URL_CUPO : URL_CISTERNA, 'POST', datos)
                .then(function (b) {
                    aviso(esCupo ? 'Cupo guardado.' : (b.guardadas === 1 ? 'Cisterna guardada.' : b.guardadas + ' cisternas guardadas.'), 'success');
                    return repintar();
                })
                .catch(function (m) {
                    // Marca en rojo la fila y el campo que fallaron (cisternas.N.campo) y dice la primera.
                    var errores = (m && m.errores) || {}, primero = '';
                    Object.keys(errores).forEach(function (k) {
                        var p = k.split('.');
                        if (p[0] !== 'cisternas' || !filas[p[1]]) return;
                        filas[p[1]].querySelector('[data-campo=' + p[2] + ']').classList.add('cupos-mal');
                        if (!primero) primero = 'Fila ' + (+p[1] + 1) + ': ' + errores[k][0];
                    });
                    aviso(primero || (m && m.mensaje) || 'No se pudo guardar.', 'error');
                })
                .finally(function () { delete f.dataset.enviando; f.classList.remove('cupos-ocupado'); });
        });

        // Se cierra al tocar fuera solo si el toque EMPEZÓ fuera (no al soltar ahí una selección de texto).
        var abajoEnFondo = false;
        modal.addEventListener('mousedown', function (e) { abajoEnFondo = e.target === modal; });
        modal.addEventListener('click', function (e) {
            if ((e.target === modal && abajoEnFondo) || e.target.closest('[data-cerrar-modal]')) { cerrar(); return; }
            var panel = e.target.closest('[data-panel]');
            if (e.target.closest('[data-otra-fila]')) { otraFila(panel.querySelector('[data-filas]')); return; }
            var quitar = e.target.closest('[data-quitar-fila]');
            if (quitar) { quitarFila(quitar.closest('[data-fila]')); return; }
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
                    .catch(function (m) { aviso(m && m.mensaje ? m.mensaje : 'No se pudo quitar.', 'error'); });
            });
        });

        // ── Tabla de cisternas: filas ──
        function numerar(tbody) {
            tbody.querySelectorAll('[data-fila]').forEach(function (tr, i) { tr.querySelector('.cupos-lote-n').textContent = i + 1; });
            totalLote(tbody);
        }
        // Una fila más, con la fecha de la última (las cisternas de un día suelen ir juntas).
        function otraFila(tbody) {
            var ultima = tbody.querySelector('[data-fila]:last-child');
            var nueva = ultima.cloneNode(true);
            nueva.querySelector('[data-campo=litros]').value = '';
            nueva.querySelector('[data-campo=fecha]').value = ultima.querySelector('[data-campo=fecha]').value;
            nueva.querySelectorAll('.cupos-mal').forEach(function (i) { i.classList.remove('cupos-mal'); });
            tbody.appendChild(nueva);
            numerar(tbody);
            nueva.querySelector('[data-campo=litros]').focus();
        }
        // Quitar la fila; si es la única, solo se limpia.
        function quitarFila(tr) {
            var tbody = tr.parentNode;
            if (tbody.querySelectorAll('[data-fila]').length > 1) tr.remove();
            else tr.querySelector('[data-campo=litros]').value = '';
            numerar(tbody);
        }
        // Los litros como en el servidor: «38.000» = 38000, «1.250,5» = 1250.5.
        function numeroLitros(t) {
            t = String(t || '').replace(/\s/g, '');
            var coma = t.lastIndexOf(','), punto = t.lastIndexOf('.');
            if (coma !== -1 && punto !== -1) t = coma > punto ? t.replace(/\./g, '').replace(',', '.') : t.replace(/,/g, '');
            else if (coma !== -1) t = t.split(',').length > 2 ? t.replace(/,/g, '') : t.replace(',', '.');
            else if (/^\d{1,3}(\.\d{3})+$/.test(t)) t = t.replace(/\./g, '');
            var n = parseFloat(t);
            return isNaN(n) ? 0 : n;
        }
        function totalLote(tbody) {
            var n = 0, suma = 0;
            tbody.querySelectorAll('[data-campo=litros]').forEach(function (i) {
                var v = numeroLitros(i.value);
                if (v > 0) { n++; suma += v; }
            });
            var t = tbody.closest('form').querySelector('[data-lote-total]');
            t.textContent = n ? n + (n === 1 ? ' cisterna · ' : ' cisternas · ') + suma.toLocaleString('es-VE', { maximumFractionDigits: 2 }) + ' L' : '';
        }
        modal.addEventListener('input', function (e) {
            if (e.target.matches && e.target.matches('[data-campo]')) {
                e.target.classList.remove('cupos-mal');
                if (e.target.dataset.campo === 'litros') totalLote(e.target.closest('[data-filas]'));
            }
        });
        modal.addEventListener('change', function (e) {
            if (e.target.matches && e.target.matches('[data-elegir-patio]')) mostrarPatio(e.target.value);
        });
        // Enter en los litros: pasa a la fila siguiente (o crea otra); se guarda con el botón.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('[data-campo=litros]')) return;
            e.preventDefault();
            if (e.target.value.trim() === '') return;
            var tr = e.target.closest('[data-fila]');
            var sig = tr.nextElementSibling;
            if (sig) sig.querySelector('[data-campo=litros]').focus(); else otraFila(tr.parentNode);
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
