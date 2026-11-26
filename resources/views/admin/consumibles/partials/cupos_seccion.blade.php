{{-- Cargar consumibles → modal «Cupos de Gasoil»: se elige el patio y de él se ve el cupo (se edita
     ahí), se cargan las cisternas surtidas (fecha y litros) y se ve su historial mes a mes. Lo abre
     «Cupos de gasoil» del menú Acciones; con ?cupos=1 en la dirección se abre solo (desde las otras
     pantallas). El cuerpo se repinta solo (consumibles.cupos?parcial=1). --}}
<style>
    /* Un solo bloque blanco: patio, franja de resumen (sin cajas) y UNA tabla (filas nuevas arriba, historial debajo).
       Colores del sistema: azul #0067b1 para acciones, encabezado de tabla #1e293b, grises pizarra. */
    .cupos-modal { width: 94%; max-width: 640px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #fff; margin: auto; max-height: 92vh; display: flex; flex-direction: column; text-align: left; }
    .cupos-modal:focus { outline: none; }
    .cupos-modal-cab { position: relative; background: var(--maquinaria-dark-blue); color: #fff; padding: 13px 48px; text-align: center; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .cupos-modal-x { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none;
        color: #fff; cursor: pointer; display: flex; padding: 2px; border-radius: 6px; }
    .cupos-modal-x:hover { background: rgba(255,255,255,.12); }
    #cuposContenido { padding: 18px 20px 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 14px; }

    .cupos-patio { display: flex; align-items: center; gap: 10px; }
    .cupos-patio span, .cupos-dato small { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
    .cupos-patio select { flex: 1; min-width: 0; height: 40px; border: 1px solid #cbd5e0; border-radius: 10px; background: #fff;
        padding: 0 12px; font-size: 14px; font-weight: 600; color: #0f172a; font-family: inherit; cursor: pointer; outline: none; }
    .cupos-patio select:focus { border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0,103,177,.12); }

    .cupos-panel { display: flex; flex-direction: column; gap: 14px; }
    .cupos-panel[hidden] { display: none; }

    /* Franja de resumen: tres cifras separadas por una línea, y la barra justo debajo. */
    .cupos-resumen { display: grid; grid-template-columns: repeat(3, 1fr); }
    .cupos-dato { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 2px 8px; min-width: 0; text-align: center; }
    .cupos-dato + .cupos-dato { border-left: 1px solid #e2e8f0; }
    .cupos-dato b { font-size: 20px; color: #0f172a; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .cupos-dato small { white-space: nowrap; }
    .cupos-pasado { color: #dc2626 !important; }
    .cupos-cupo-ver { display: flex; align-items: center; gap: 4px; }
    .cupos-cupo-ver[hidden], .cupos-cupo-editar[hidden] { display: none; }
    .cupos-lapiz { border: none; background: none; cursor: pointer; color: #0067b1; padding: 2px; display: flex; border-radius: 6px; }
    .cupos-lapiz:hover { background: #eff6ff; }
    .cupos-lapiz i { font-size: 17px; }
    .cupos-cupo-editar { display: flex; gap: 4px; align-items: center; width: 100%; }
    .cupos-cupo-editar input { flex: 1; min-width: 0; width: 0; height: 32px; border: 1px solid #0067b1; border-radius: 8px; padding: 0 8px;
        font-size: 13px; font-family: inherit; outline: none; box-sizing: border-box; text-align: right; }
    .cupos-mini { border: none; border-radius: 8px; background: #0067b1; color: #fff; height: 32px; width: 32px; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; }
    .cupos-mini i { font-size: 18px; }
    .cupos-mini-gris { background: #e2e8f0; color: #475569; }
    .cupos-barra { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; margin-top: -4px; }
    .cupos-barra i { display: block; height: 100%; border-radius: 3px; }

    /* La tabla: mismo encabezado que la Lista de Consumibles. */
    .cupos-tabla { border: 1px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
    .cupos-tbl { width: 100%; border-collapse: collapse; font-size: 13px; table-layout: fixed; font-variant-numeric: tabular-nums; }
    .cupos-tbl th { background: #1e293b; color: #e2e8f0; font-weight: 700; padding: 9px 10px; font-size: 11px; text-transform: uppercase;
        letter-spacing: .4px; white-space: nowrap; text-align: left; }
    .cupos-tbl td { padding: 8px 10px; border-bottom: 1px solid #eef2f6; color: #1e293b; }
    .cupos-tbl .num { text-align: right; white-space: nowrap; }
    .cupos-tbl .cupos-acc { text-align: center; padding: 4px 0; }

    /* Filas nuevas: fondo azul muy claro, campos del alto de la fila. */
    .cupos-nuevas td { background: #f5f9fd; padding: 6px 6px; border-bottom: 1px solid #e3edf7; }
    .cupos-nuevas td:first-child { padding-left: 10px; }
    .cupos-nuevas input { height: 34px; width: 100%; box-sizing: border-box; border: 1px solid #cbd5e0; border-radius: 8px; background: #fff;
        padding: 0 10px; font-size: 13px; font-family: inherit; outline: none; }
    .cupos-nuevas input.num { text-align: right; }
    .cupos-nuevas input:focus { border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0,103,177,.12); }
    .cupos-nuevas input.cupos-mal { border-color: #dc2626; background: #fef2f2; }
    .cupos-previo { color: #64748b; padding-right: 10px !important; }
    .cupos-nuevas-pie td { background: #f5f9fd; padding: 8px 10px; border-bottom: 2px solid #e2e8f0; }
    .cupos-pie { display: flex; align-items: center; gap: 10px; }
    .cupos-otra { border: none; background: none; color: #0067b1; padding: 0 4px; height: 34px; font-size: 13px; font-weight: 600;
        font-family: inherit; cursor: pointer; display: inline-flex; align-items: center; gap: 2px; border-radius: 8px; }
    .cupos-otra:hover { background: #eaf2fb; }
    .cupos-otra i { font-size: 18px; }
    .cupos-lote-total { flex: 1; text-align: right; font-size: 12px; color: #475569; font-weight: 600; }
    .cupos-pie .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; height: 34px; }

    /* Historial: una fila por mes con su resumen, luego sus cisternas. */
    .cupos-tbl tr.cupos-mes td { background: #f1f5f9; color: #475569; font-size: 12px; box-shadow: inset 3px 0 0 var(--color); }
    .cupos-tbl tr.cupos-mes td > b { color: #0f172a; font-size: 13px; }
    .cupos-tbl tr.cupos-mes span { float: right; }
    .cupos-tbl tr.cupos-mes span b { color: #0f172a; }
    .cupos-vacio { color: #94a3b8; text-align: center; padding: 22px 8px !important; }
    .cupos-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 4px; display: inline-flex; border-radius: 6px; }
    .cupos-borrar:hover { color: #dc2626; background: #fef2f2; }
    .cupos-borrar i { font-size: 18px; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }

    @media (max-width: 600px) {
        #cuposContenido { padding: 14px 12px 16px; }
        .cupos-dato { padding: 2px 4px; }
        .cupos-dato small { font-size: 9.5px; letter-spacing: .2px; }
        .cupos-dato b { font-size: 14px; }
        .cupos-tbl th, .cupos-tbl td { padding-left: 6px; padding-right: 6px; }
        .cupos-tbl col:last-child { width: 32px !important; }
        .cupos-tbl col:nth-child(2), .cupos-tbl col:nth-child(3) { width: 26% !important; }
        .cupos-nuevas td { padding: 5px 3px; }
        .cupos-nuevas input { padding: 0 6px; font-size: 12px; }
        .cupos-pie { flex-wrap: wrap; }
        .cupos-pie .btn-primary-maquinaria { flex: 1 1 100%; justify-content: center; }
        .cupos-tbl tr.cupos-mes span { float: none; display: block; margin-top: 2px; }
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
        var URL_CUPO = @json(route('consumibles.cupos.guardar'));
        var URL_CISTERNA = @json(route('consumibles.cupos.cisterna'));
        var URL_BORRAR = @json(route('consumibles.cupos.borrarCisterna', ['id' => '__ID__']));
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
        // Al cerrar se quita ?cupos de la dirección (recargar no lo abre de nuevo); lo demás queda igual.
        function cerrar() {
            modal.classList.remove('active');
            var u = new URL(location.href);
            if (u.searchParams.has('cupos')) { u.searchParams.delete('cupos'); history.replaceState(history.state, '', u.toString()); }
        }
        if (modal.dataset.abrir) abrir();

        // Repinta el cuerpo (sin recargar la página). Las cisternas escritas y aún sin guardar se
        // conservan (quitar una del historial o cambiar el cupo no las borra), salvo las del patio
        // que se acaba de guardar.
        function repintar(guardado) {
            var c = caja();
            var pendientes = {};
            c.querySelectorAll('[data-panel]').forEach(function (p) {
                if (p.dataset.panel === guardado) return;
                var filas = Array.prototype.map.call(p.querySelectorAll('[data-fila]'), function (tr) {
                    return [tr.querySelector('[data-campo=fecha]').value, tr.querySelector('[data-campo=litros]').value];
                }).filter(function (f) { return f[1].trim() !== ''; });
                if (filas.length) pendientes[p.dataset.panel] = filas;
            });
            c.classList.add('cupos-ocupado');
            return window.apiFetch(URL_LISTA, { headers: JSON_H })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) {
                    c.innerHTML = html;
                    mostrarPatio(window.__cuposPatio);
                    Object.keys(pendientes).forEach(function (patio) {
                        var tbody = c.querySelector('[data-panel="' + patio + '"] [data-filas]');
                        if (tbody) ponerFilas(tbody, pendientes[patio]);
                    });
                })
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
                    return repintar(esCupo ? null : f.querySelector('[name=patio]').value);
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
                enviar(URL_BORRAR.replace('__ID__', b.dataset.borrar), 'DELETE')
                    .then(function () { aviso('Cisterna quitada.', 'success'); return repintar(); })
                    .catch(function (m) { aviso(m && m.mensaje ? m.mensaje : 'No se pudo quitar.', 'error'); });
            });
        });

        // ── Tabla de cisternas: filas ──
        // Una fila más, con la fecha de la última (las cisternas de un día suelen ir juntas).
        function otraFila(tbody) {
            var ultima = tbody.querySelector('[data-fila]:last-child');
            var nueva = ultima.cloneNode(true);
            nueva.querySelector('[data-campo=litros]').value = '';
            nueva.querySelector('[data-campo=fecha]').value = ultima.querySelector('[data-campo=fecha]').value;
            nueva.querySelectorAll('.cupos-mal').forEach(function (i) { i.classList.remove('cupos-mal'); });
            tbody.appendChild(nueva);
            recalcular(tbody);
            nueva.querySelector('[data-campo=litros]').focus();
        }
        // Vuelve a poner filas escritas ([[fecha, litros], ...]) en una tabla recién pintada.
        function ponerFilas(tbody, filas) {
            filas.forEach(function (f, i) {
                if (i > 0) otraFila(tbody);
                var tr = tbody.querySelector('[data-fila]:last-child');
                tr.querySelector('[data-campo=fecha]').value = f[0];
                tr.querySelector('[data-campo=litros]').value = f[1];
            });
            recalcular(tbody);
        }
        // Quitar la fila; si es la única, solo se limpia.
        function quitarFila(tr) {
            var tbody = tr.parentNode;
            if (tbody.querySelectorAll('[data-fila]').length > 1) tr.remove();
            else tr.querySelector('[data-campo=litros]').value = '';
            recalcular(tbody);
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
        function litrosTexto(n) { return n.toLocaleString('es-VE', { maximumFractionDigits: 2 }) + ' L'; }
        // El total de lo escrito y, fila por fila, lo que quedaría del cupo de este mes (las de otro mes: «—»).
        function recalcular(tbody) {
            var panel = tbody.closest('[data-panel]');
            var queda = panel.dataset.quedan === '' ? null : parseFloat(panel.dataset.quedan);
            var n = 0, suma = 0;
            tbody.querySelectorAll('[data-fila]').forEach(function (tr) {
                var v = numeroLitros(tr.querySelector('[data-campo=litros]').value);
                var previo = tr.querySelector('[data-previo]');
                var delMes = tr.querySelector('[data-campo=fecha]').value.slice(0, 7) === panel.dataset.mes;
                if (v > 0) { n++; suma = Math.round((suma + v) * 100) / 100; }
                if (v > 0 && delMes && queda !== null) {
                    queda = Math.round((queda - v) * 100) / 100;
                    previo.textContent = litrosTexto(queda);
                    previo.classList.toggle('cupos-pasado', queda < 0);
                } else {
                    previo.textContent = '—';
                    previo.classList.remove('cupos-pasado');
                }
            });
            tbody.closest('form').querySelector('[data-lote-total]').textContent =
                n ? n + (n === 1 ? ' cisterna · ' : ' cisternas · ') + litrosTexto(suma) : '';
        }
        modal.addEventListener('input', function (e) {
            if (e.target.matches && e.target.matches('[data-campo]')) {
                e.target.classList.remove('cupos-mal');
                recalcular(e.target.closest('[data-filas]'));
            }
        });
        modal.addEventListener('change', function (e) {
            if (e.target.matches && e.target.matches('[data-elegir-patio]')) mostrarPatio(e.target.value);
        });
        // Enter en la fecha: a los litros de esa fila. Enter en los litros: a la fila siguiente (o crea
        // otra). Se guarda solo con el botón.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('[data-campo]')) return;
            e.preventDefault();
            if (e.target.dataset.campo === 'fecha') { e.target.closest('[data-fila]').querySelector('[data-campo=litros]').focus(); return; }
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
