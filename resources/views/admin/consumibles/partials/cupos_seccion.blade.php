{{-- Cargar consumibles → modal «Cupos de Gasoil»: se elige el patio y de él se ve el cupo (se edita
     ahí), se cargan las cisternas surtidas (fecha y litros) y se ve su historial mes a mes. Lo abre
     «Cupos de gasoil» del menú Acciones; con ?cupos=1 en la dirección se abre solo (desde las otras
     pantallas). El cuerpo se repinta solo (consumibles.cupos?parcial=1). --}}
<style>
    /* Modal en tres bloques: resumen del mes, carga de cisternas e historial.
       Colores del sistema: azul #0067b1 para acciones, grises pizarra; --color = el del patio. */
    .cupos-modal { width: 94%; max-width: 560px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #f8fafc; margin: auto; max-height: 92vh; display: flex; flex-direction: column; text-align: left; }
    .cupos-modal:focus { outline: none; }
    .cupos-modal-cab { position: relative; background: var(--maquinaria-dark-blue); color: #fff; padding: 14px 48px; text-align: center; flex-shrink: 0; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    .cupos-modal-x { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: none; border: none;
        color: #fff; cursor: pointer; display: flex; padding: 2px; border-radius: 6px; }
    .cupos-modal-x:hover { background: rgba(255,255,255,.12); }
    #cuposContenido { padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }

    /* Patio */
    .cupos-patio { position: relative; }
    .cupos-patio > i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); font-size: 20px; color: #0067b1; pointer-events: none; }
    .cupos-patio select { width: 100%; height: 44px; border: 1px solid #cbd5e0; border-radius: 12px; background-color: #fff;
        padding: 0 12px 0 40px; font-size: 15px; font-weight: 700; color: #0f172a; font-family: inherit; cursor: pointer; outline: none; }
    .cupos-patio select:focus { border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0,103,177,.12); }

    .cupos-panel { display: flex; flex-direction: column; gap: 12px; }
    .cupos-panel[hidden] { display: none; }

    /* Bloques blancos */
    .cupos-resumen, .cupos-bloque { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; }
    .cupos-bloque h3 { margin: 0 0 10px; font-size: 13px; font-weight: 700; color: #0f172a; }

    /* Resumen */
    .cupos-resumen { border-left: 4px solid var(--color); }
    .cupos-resumen-fila { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; }
    .cupos-dato { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
    .cupos-dato small { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; }
    .cupos-dato b { font-size: 22px; line-height: 1.2; color: #0f172a; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .cupos-tenue { color: #94a3b8 !important; font-weight: 600; }
    .cupos-pasado { color: #dc2626 !important; }
    /* Cupo: un cuadro de texto simple con su «L» (se guarda con Enter o al salir). */
    .cupos-cupo { margin: 0; }
    .cupos-cupo-caja { position: relative; display: block; margin: 0; }
    .cupos-cupo-caja input { width: 170px; height: 38px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fff; padding: 0 28px 0 10px;
        font-size: 18px; font-weight: 700; color: #0f172a; font-family: inherit; outline: none; box-sizing: border-box; text-align: right;
        font-variant-numeric: tabular-nums; }
    .cupos-cupo-caja input::placeholder { font-size: 14px; font-weight: 500; color: #94a3b8; }
    .cupos-cupo-caja input:focus { border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0,103,177,.12); }
    .cupos-cupo-caja span { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 14px; color: #94a3b8; pointer-events: none; }

    /* Tablita de cisternas: fecha | litros | foto | quitar. La última fila siempre está vacía
       (al llenarla aparece otra); esa no lleva la ×. */
    .cupos-tbl { width: 100%; border-collapse: separate; border-spacing: 0; table-layout: fixed; font-variant-numeric: tabular-nums;
        border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    .cupos-tbl th:nth-child(3) { width: 52px; text-align: center; }
    .cupos-tbl th:nth-child(4) { width: 36px; }
    .cupos-tbl th { background: #f1f5f9; color: #475569; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
        text-align: left; padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
    .cupos-tbl th.num, .cupos-tbl td.num { text-align: right; }
    .cupos-tbl td { padding: 0; border-bottom: 1px solid #eef2f6; }
    .cupos-tbl tr:last-child td { border-bottom: none; }
    .cupos-tbl td + td { border-left: 1px solid #eef2f6; }
    .cupos-tbl input { height: 36px; width: 100%; box-sizing: border-box; border: none; background: transparent; padding: 0 8px;
        font-size: 14px; font-family: inherit; color: #0f172a; outline: none; }
    .cupos-tbl input.num { text-align: right; }
    .cupos-tbl input:focus { background: #f5f9fd; box-shadow: inset 0 0 0 2px #0067b1; }
    .cupos-tbl input.cupos-mal { background: #fef2f2; box-shadow: inset 0 0 0 2px #dc2626; }
    .cupos-acc { text-align: center; }
    .cupos-tbl tr:last-child .cupos-borrar { visibility: hidden; }
    /* Foto de cada cisterna: gris sin foto, azul con foto elegida o guardada. */
    .cupos-foto-btn { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 4px; display: inline-flex; justify-content: center;
        border-radius: 6px; margin: 0; }
    .cupos-foto-btn:hover { color: #0067b1; background: #e1effa; }
    .cupos-foto-btn i { font-size: 18px; }
    .cupos-foto-btn.cupos-foto-lista, .cupos-foto-tiene { color: #0067b1; }
    .cupos-foto-visor { position: fixed; inset: 0; z-index: 100000; background: rgba(15,23,42,.85); display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 10px; padding: 16px; box-sizing: border-box; cursor: zoom-out; }
    .cupos-foto-visor img { max-width: 100%; max-height: calc(100% - 40px); border-radius: 8px; background: #fff; object-fit: contain; }
    .cupos-foto-visor span { color: #fff; font-size: 13px; font-weight: 600; }
    .cupos-pie { display: flex; align-items: center; gap: 10px; margin-top: 12px; }
    .cupos-lote-total { flex: 1; text-align: right; font-size: 12px; color: #475569; font-weight: 600; }
    .cupos-pie .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; height: 36px; }

    /* Historial */
    /* Cabecera del historial: título y, a la derecha, «Ver historial» y «Excel». */
    .cupos-hist-cab { display: flex; align-items: center; gap: 8px; }
    .cupos-hist-cab h3 { margin: 0 auto 0 0; }
    .cupos-hist-btn { display: inline-flex; align-items: center; gap: 4px; height: 32px; padding: 0 12px; border-radius: 8px;
        border: 1px solid #cbd5e0; background: #fff; color: #0f172a; font-size: 13px; font-weight: 600; font-family: inherit; cursor: pointer; }
    .cupos-hist-btn i { font-size: 18px; }
    .cupos-hist-btn:hover:not(:disabled) { background: #f1f5f9; }
    .cupos-hist-excel { border-color: #16a34a; color: #15803d; }
    .cupos-hist-excel:hover:not(:disabled) { background: #f0fdf4; }
    .cupos-hist-btn:disabled { opacity: .45; cursor: default; }
    .cupos-hist-lista { margin-top: 10px; }
    .cupos-mes + .cupos-mes { margin-top: 12px; }
    .cupos-mes-cab { display: flex; justify-content: space-between; align-items: baseline; gap: 8px; flex-wrap: wrap;
        background: #f1f5f9; border-radius: 8px; padding: 8px 10px; font-size: 12px; color: #475569; box-shadow: inset 3px 0 0 var(--color); }
    .cupos-mes-cab > b { color: #0f172a; font-size: 13px; }
    .cupos-mes-cab span b { color: #0f172a; }
    .cupos-hist { display: grid; grid-template-columns: 1fr 1fr 1.2fr 32px 32px; gap: 8px; align-items: center; padding: 6px 0 6px 10px;
        border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #1e293b; font-variant-numeric: tabular-nums; }
    .cupos-hist:last-child { border-bottom: none; }
    .cupos-hist-litros { text-align: right; font-weight: 600; }
    .cupos-hist-queda { text-align: right; color: #475569; white-space: nowrap; }
    .cupos-hist-queda small { color: #94a3b8; font-size: 11px; }
    .cupos-vacio { display: flex; flex-direction: column; align-items: center; gap: 6px; color: #94a3b8; font-size: 13px; padding: 14px 8px; text-align: center; }
    .cupos-vacio i { font-size: 32px; color: #cbd5e0; }
    .cupos-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 4px; display: inline-flex; justify-content: center; border-radius: 6px; }
    .cupos-borrar:hover { color: #dc2626; background: #fef2f2; }
    .cupos-borrar i { font-size: 18px; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }

    @media (max-width: 600px) {
        #cuposContenido { padding: 12px; }
        .cupos-resumen, .cupos-bloque { padding: 12px; }
        .cupos-dato b { font-size: 18px; }
        .cupos-cupo-caja input { width: 140px; font-size: 16px; }
        .cupos-tbl input { padding: 0 6px; font-size: 13px; }
        .cupos-pie { flex-wrap: wrap; }
        .cupos-lote-total { flex: 1 1 auto; }
        .cupos-pie .btn-primary-maquinaria { flex: 1 1 100%; justify-content: center; }
        .cupos-hist { grid-template-columns: 1fr 1fr 28px 28px; padding-left: 6px; }
        .cupos-hist-queda { grid-column: 1 / 3; grid-row: 2; text-align: left; font-size: 12px; }
        .cupos-hist .cupos-foto-btn, .cupos-hist .cupos-foto-vacia { grid-row: 1 / 3; grid-column: 3; }
        .cupos-hist .cupos-borrar { grid-row: 1 / 3; grid-column: 4; }
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
        var URL_EXCEL = @json(route('consumibles.cupos.excel'));
        var URL_FOTO = @json(route('consumibles.cupos.fotoCisterna', ['id' => '__ID__']));
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
            verHistorial(!!window.__cuposHistorial);
        }
        // «Ver historial» abre o cierra la lista (en los dos patios: queda igual al cambiar de patio y al repintar).
        function verHistorial(abierto) {
            window.__cuposHistorial = abierto;
            modal.querySelectorAll('[data-lista-historial]').forEach(function (l) { l.hidden = !abierto; });
            modal.querySelectorAll('[data-ver-historial]').forEach(function (b) {
                b.setAttribute('aria-expanded', abierto ? 'true' : 'false');
                b.querySelector('i').textContent = abierto ? 'visibility_off' : 'visibility';
                b.querySelector('span').textContent = abierto ? 'Ocultar historial' : 'Ver historial';
            });
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

        // Repinta el cuerpo (sin recargar la página). Lo escrito y aún sin guardar se copia AL
        // LLEGAR la respuesta (no antes: se puede seguir escribiendo mientras tanto) y se vuelve a
        // poner: las cisternas (salvo las del patio que se acaba de guardar), un cupo cambiado y no
        // enviado (se envía), y el cuadro donde estaba el cursor.
        function repintar(guardado) {
            var c = caja();
            return window.apiFetch(URL_LISTA, { headers: JSON_H })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) {
                    var pendientes = {}, cupos = {}, foco = null, act = document.activeElement;
                    c.querySelectorAll('[data-panel]').forEach(function (p) {
                        var patio = p.dataset.panel;
                        var cupo = p.querySelector('.cupos-cupo input[name=litros]');
                        if (cupo && cupo.value.trim() !== '' && !mismoCupo(cupo) && cupo.value !== cupo.dataset.enviado) cupos[patio] = cupo.value;
                        if (cupo && act === cupo) foco = { patio: patio, cupo: true };
                        if (patio === guardado) return;
                        var trs = p.querySelectorAll('[data-fila]');
                        Array.prototype.forEach.call(trs, function (tr, i) {
                            var campo = act && tr.contains(act) && act.dataset.campo;
                            if (campo) foco = { patio: patio, fila: i, campo: campo };
                        });
                        var filas = Array.prototype.map.call(trs, function (tr) {
                            return [tr.querySelector('[data-campo=fecha]').value, tr.querySelector('[data-campo=litros]').value];
                        }).filter(function (f) { return f[1].trim() !== ''; });
                        if (filas.length) pendientes[patio] = filas;
                    });
                    c.innerHTML = html;
                    mostrarPatio(window.__cuposPatio);
                    Object.keys(pendientes).forEach(function (patio) {
                        var tbody = c.querySelector('[data-panel="' + patio + '"] [data-filas]');
                        if (tbody) ponerFilas(tbody, pendientes[patio]);
                    });
                    Object.keys(cupos).forEach(function (patio) {
                        var cupo = c.querySelector('[data-panel="' + patio + '"] .cupos-cupo input[name=litros]');
                        if (!cupo) return;
                        cupo.value = cupos[patio];
                        if (!(foco && foco.cupo && foco.patio === patio)) enviarForm(cupo.form);   // ya salió del cuadro
                    });
                    if (foco) {
                        var p = c.querySelector('[data-panel="' + foco.patio + '"]');
                        var el = !p ? null : foco.cupo ? p.querySelector('.cupos-cupo input[name=litros]')
                            : (p.querySelectorAll('[data-fila]')[foco.fila] || p.querySelector('[data-fila]:last-child')).querySelector('[data-campo=' + foco.campo + ']');
                        if (el) el.focus();
                    }
                })
                .catch(function () { aviso('No se pudo actualizar. Revise la conexión.', 'error'); });
        }
        // El cupo escrito es el mismo que el guardado aunque cambie la forma («38000» = «38.000»).
        function mismoCupo(input) { return numeroLitros(input.value) === numeroLitros(input.defaultValue); }
        // requestSubmit no existe en Safari antes de iOS 16: ahí, el evento submit a mano.
        function enviarForm(f) {
            if (f.requestSubmit) f.requestSubmit();
            else f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
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
            // Un envío a la vez: Enter dos veces no guarda la misma cisterna dos veces. (Un cupo
            // cambiado mientras tanto no se pierde: lo vuelve a enviar repintar.)
            if (f.dataset.enviando) return;
            var esCupo = f.dataset.accion === 'cupo';
            var cupo = esCupo ? f.querySelector('input[name=litros]') : null;
            if (cupo) {
                // Sin cambios no se envía; vacío vuelve a lo que tenía.
                if (cupo.value.trim() === '') cupo.value = cupo.defaultValue;
                if (mismoCupo(cupo)) return;
                cupo.dataset.enviado = cupo.value;
            }
            f.dataset.enviando = '1';
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
                    var foto = tr.querySelector('[data-foto-fila]');
                    if (foto && foto.files && foto.files[0]) datos.append('cisternas[' + i + '][foto]', foto.files[0]);
                });
                f.querySelectorAll('.cupos-mal').forEach(function (i) { i.classList.remove('cupos-mal'); });
            }
            f.classList.add('cupos-ocupado');
            enviar(esCupo ? URL_CUPO : URL_CISTERNA, 'POST', datos)
                .then(function (b) {
                    // Ya guardado: si el repintado fallara, Escape no vuelve a un cupo que ya no es el guardado.
                    if (cupo) cupo.defaultValue = cupo.dataset.enviado;
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
            var ver = e.target.closest('[data-ver-foto]');
            if (ver) { verFoto(ver.dataset.verFoto, ver.dataset.texto); return; }
            var cargar = e.target.closest('[data-cargar-foto]');
            if (cargar) { elegirFoto(cargar.dataset.cargarFoto); return; }
            var quitar = e.target.closest('[data-quitar-fila]');
            if (quitar) { quitarFila(quitar.closest('[data-fila]')); return; }
            if (e.target.closest('[data-ver-historial]')) { verHistorial(!window.__cuposHistorial); return; }
            var xl = e.target.closest('[data-excel-historial]');
            if (xl) {
                window.descargarArchivo(URL_EXCEL + '?patio=' + encodeURIComponent(xl.dataset.excelHistorial), null,
                    xl.dataset.nombre, 'No se pudo descargar el Excel.');
                return;
            }
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

        // ── Tablita de cisternas: filas ──
        // La última fila siempre está vacía: al llenar sus litros aparece otra debajo (con la misma
        // fecha: las cisternas de un día suelen ir juntas); si quedan dos vacías al final, sobra una.
        function vacia(tr) { return tr.querySelector('[data-campo=litros]').value.trim() === ''; }
        function filaAlFinal(tbody) {
            var filas = tbody.querySelectorAll('[data-fila]');
            var ultima = filas[filas.length - 1];
            if (!vacia(ultima)) {
                var nueva = ultima.cloneNode(true);
                nueva.querySelector('[data-campo=litros]').value = '';
                nueva.querySelectorAll('.cupos-mal').forEach(function (i) { i.classList.remove('cupos-mal'); });
                // La foto es de cada cisterna: la fila nueva empieza sin foto.
                var fotoNueva = nueva.querySelector('[data-foto-fila]');
                if (fotoNueva) { fotoNueva.value = ''; fotoNueva.closest('.cupos-foto-btn').classList.remove('cupos-foto-lista'); }
                tbody.appendChild(nueva);
            } else {
                while (filas.length > 1 && vacia(filas[filas.length - 1]) && vacia(filas[filas.length - 2])) {
                    // La fecha que se haya elegido en la que sobra pasa a la que queda.
                    filas[filas.length - 2].querySelector('[data-campo=fecha]').value =
                        filas[filas.length - 1].querySelector('[data-campo=fecha]').value;
                    filas[filas.length - 1].remove();
                    filas = tbody.querySelectorAll('[data-fila]');
                }
            }
            recalcular(tbody);
        }
        // Vuelve a poner filas escritas ([[fecha, litros], ...]) en una tabla recién pintada.
        function ponerFilas(tbody, filas) {
            filas.forEach(function (f) {
                var tr = tbody.querySelector('[data-fila]:last-child');
                tr.querySelector('[data-campo=fecha]').value = f[0];
                tr.querySelector('[data-campo=litros]').value = f[1];
                filaAlFinal(tbody);
            });
        }
        // Quitar una fila llena (la vacía del final no tiene ×).
        function quitarFila(tr) {
            var tbody = tr.parentNode;
            if (tbody.querySelectorAll('[data-fila]').length > 1) tr.remove();
            filaAlFinal(tbody);
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
        // El total de lo escrito (cuántas cisternas y cuántos litros).
        function recalcular(tbody) {
            var n = 0, suma = 0;
            tbody.querySelectorAll('[data-fila]').forEach(function (tr) {
                var v = numeroLitros(tr.querySelector('[data-campo=litros]').value);
                if (v > 0) { n++; suma = Math.round((suma + v) * 100) / 100; }
            });
            tbody.closest('form').querySelector('[data-lote-total]').textContent =
                n ? n + (n === 1 ? ' cisterna · ' : ' cisternas · ') + litrosTexto(suma) : '';
        }
        modal.addEventListener('input', function (e) {
            if (e.target.matches && e.target.matches('[data-campo]')) {
                e.target.classList.remove('cupos-mal');
                filaAlFinal(e.target.closest('[data-filas]'));
            }
        });
        modal.addEventListener('change', function (e) {
            if (!e.target.matches) return;
            if (e.target.matches('[data-elegir-patio]')) mostrarPatio(e.target.value);
            // Foto elegida para una fila de la tablita: el botón queda azul (se sube con Guardar).
            else if (e.target.matches('[data-foto-fila]')) {
                var lista = !!(e.target.files && e.target.files[0]);
                var btn = e.target.closest('.cupos-foto-btn');
                btn.classList.toggle('cupos-foto-lista', lista);
                btn.title = lista ? 'Foto: ' + e.target.files[0].name : 'Foto de la cisterna (opcional)';
            }
            // El cupo se guarda al salir del cuadro (o con Enter, que envía el formulario).
            else if (e.target.matches('.cupos-cupo input[name=litros]')) enviarForm(e.target.form);
        });
        // Enter en la fecha: a los litros de esa fila. Enter en los litros: a la fila siguiente.
        // Se guarda solo con el botón.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('[data-campo]')) return;
            e.preventDefault();
            if (e.target.dataset.campo === 'fecha') { e.target.closest('[data-fila]').querySelector('[data-campo=litros]').focus(); return; }
            var sig = e.target.closest('[data-fila]').nextElementSibling;
            if (sig && e.target.value.trim() !== '') sig.querySelector('[data-campo=litros]').focus();
        });

        // Escape: en el cuadro del cupo deshace lo escrito sin guardar; si no, cierra el modal.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !modal.classList.contains('active')) return;
            e.stopPropagation();
            var cupo = e.target.matches && e.target.matches('.cupos-cupo input[name=litros]') ? e.target : null;
            if (cupo && cupo.value !== cupo.defaultValue) { cupo.value = cupo.defaultValue; return; }
            cerrar();
        });

        // Foto de una cisterna ya guardada: se elige y se sube de una vez.
        function elegirFoto(id) {
            var input = document.createElement('input');
            input.type = 'file'; input.accept = 'image/*'; input.hidden = true;
            modal.appendChild(input);
            input.addEventListener('change', function () {
                var archivo = input.files && input.files[0];
                input.remove();
                if (!archivo) return;
                var datos = new FormData();
                datos.append('foto', archivo);
                if (window.showPreloader) window.showPreloader();
                enviar(URL_FOTO.replace('__ID__', id), 'POST', datos)
                    .then(function () { aviso('Foto guardada.', 'success'); return repintar(); })
                    .catch(function (m) { aviso((m && m.mensaje) || 'No se pudo guardar la foto.', 'error'); })
                    .finally(function () { if (window.hidePreloader) window.hidePreloader(); });
            });
            input.addEventListener('cancel', function () { input.remove(); });
            input.click();
        }
        // La foto de una cisterna, encima del modal; un toque o Escape la cierra.
        function verFoto(url, texto) {
            var v = document.createElement('div');
            v.className = 'cupos-foto-visor';
            var img = document.createElement('img');
            img.src = url; img.alt = 'Foto de la cisterna';
            var t = document.createElement('span');
            t.textContent = texto || '';
            v.appendChild(img); v.appendChild(t);
            function cerrarVisor(e) {
                if (e.type === 'keydown' && e.key !== 'Escape') return;
                e.stopPropagation();
                v.remove();
                document.removeEventListener('keydown', cerrarVisor, true);
            }
            v.addEventListener('click', cerrarVisor);
            document.addEventListener('keydown', cerrarVisor, true);
            document.body.appendChild(v);
        }
    })();
</script>
