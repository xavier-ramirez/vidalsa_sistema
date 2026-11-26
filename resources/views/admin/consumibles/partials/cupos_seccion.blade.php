{{-- Cargar consumibles → modal «Cupos de Gasoil»: arriba el patio y su cupo mensual (se edita ahí);
     debajo, una sola tabla con las cisternas del mes elegido. Cada cisterna empieza como solicitud
     (número y plan de despacho) y, al llegar, se le pone la llegada real, los litros y la foto en la
     misma fila. Los meses anteriores son el historial; Excel baja todo el patio. Lo abre «Cupos de
     gasoil» del menú Acciones; con ?cupos=1 en la dirección se abre solo. --}}
<style>
    /* Colores del sistema: azul #0067b1 para acciones, grises pizarra; --color = el del patio. */
    .cupos-modal { width: 96%; max-width: 760px; box-sizing: border-box; padding: 0; border-radius: 16px; overflow: hidden;
        background: #f8fafc; margin: auto; max-height: 92vh; display: flex; flex-direction: column; text-align: left; }
    .cupos-modal:focus { outline: none; }
    .cupos-modal-cab { position: relative; background: var(--maquinaria-dark-blue); color: #fff; padding: 14px 88px; text-align: center; flex-shrink: 0; }
    .cupos-modal-cab h2 { margin: 0; font-size: 15px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }
    /* A la derecha del encabezado, como en los otros modales: descargar (Excel del patio elegido) y cerrar. */
    .cupos-modal-acc { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); display: flex; gap: 6px; }
    .cupos-modal-x { width: 30px; height: 30px; border-radius: 8px; border: 1px solid rgba(255,255,255,.2); background: rgba(255,255,255,.15);
        color: #fff; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; transition: all .2s ease; }
    .cupos-modal-x:hover { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.45); }
    .cupos-modal-x .material-icons { font-size: 18px; }
    #cuposContenido { padding: 16px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }

    /* Fila de arriba, una al lado de la otra: patio, mes y el cupo del patio. Las dos listas son las de la
       aplicación (custom-dropdown, como los filtros de Gráficos). */
    .cupos-arriba { display: flex; align-items: flex-end; gap: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 10px 12px; }
    .cupos-campo { display: flex; flex-direction: column; gap: 2px; flex: 1 1 0; min-width: 0; }
    .cupos-dd { width: 100%; }
    .cupos-dd .cupos-dd-boton { display: flex; align-items: center; gap: 6px; height: 40px; padding: 0 6px 0 10px; border: 1px solid #cbd5e0;
        border-radius: 10px; background: #fbfcfd; cursor: pointer; box-sizing: border-box; outline: none; }
    .cupos-dd .cupos-dd-boton:focus-visible { border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0,103,177,.12); }
    .cupos-dd .cupos-dd-boton > i { font-size: 18px; color: #94a3b8; flex-shrink: 0; }
    .cupos-dd .cupos-dd-boton > i:first-child { color: #0067b1; }
    .cupos-dd [data-dd-texto] { flex: 1 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        font-size: 14px; font-weight: 700; color: #0f172a; }
    .cupos-dd .dropdown-content { max-height: 260px; overflow-y: auto; }
    .cupos-dd .dropdown-item { cursor: pointer; padding: 9px 12px; font-size: 13.5px; }
    .cupos-dd .dropdown-item.cupos-dd-foco { background: #e1effa; }
    /* El cupo: su etiqueta arriba del cuadro, en letra pequeña. */
    .cupos-cupo-lado { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; flex-shrink: 0; }
    .cupos-cupo-lado[hidden] { display: none; }
    .cupos-cupo-titulo { font-size: 10.5px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: .4px; white-space: nowrap; padding-left: 2px; }
    .cupos-cupo-valor { font-size: 18px; color: #0f172a; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .cupos-tenue { color: #94a3b8 !important; font-weight: 600; }
    .cupos-cupo { margin: 0; }
    .cupos-cupo-caja { position: relative; display: block; margin: 0; }
    .cupos-cupo-caja input { width: 160px; height: 40px; border: 1px solid #cbd5e0; border-radius: 10px; background: #fff; padding: 0 26px 0 10px;
        font-size: 17px; font-weight: 700; color: #0f172a; font-family: inherit; outline: none; box-sizing: border-box; text-align: right;
        font-variant-numeric: tabular-nums; }
    .cupos-cupo-caja input::placeholder { font-size: 13px; font-weight: 500; color: #94a3b8; }
    .cupos-cupo-caja input:focus { border-color: #0067b1; box-shadow: 0 0 0 3px rgba(0,103,177,.12); }
    .cupos-cupo-caja span { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); font-size: 13px; color: #94a3b8; pointer-events: none; }

    .cupos-panel[hidden] { display: none; }
    .cupos-bloque { background: #fff; border: 1px solid #e2e8f0; border-top: 3px solid var(--color); border-radius: 12px; padding: 12px 14px; margin: 0; }
    .cupos-bloque h3 { margin: 0; display: flex; align-items: center; gap: 6px; font-size: 14px; font-weight: 700; color: #0f172a; }
    .cupos-bloque h3 i { font-size: 18px; color: var(--color); }

    /* Cabecera de la tabla: el título con el mes que se ve. */
    .cupos-tbl-cab { display: flex; align-items: center; gap: 6px 12px; margin-bottom: 10px; flex-wrap: wrap; }
    .cupos-tbl-mes { color: #475569; font-weight: 600; }

    /* La tabla: N.º | Plan | Llegada | Litros | Foto | quitar. */
    .cupos-tbl-marco { border: 1px solid #e2e8f0; border-radius: 8px; overflow-x: auto; background: #fff; }
    .cupos-tbl { width: 100%; min-width: 600px; border-collapse: collapse; table-layout: fixed; font-variant-numeric: tabular-nums; }
    /* Cabecera como la de la Lista de consumibles (.tabla-cabecera): azul oscuro, letra blanca en mayúsculas. */
    .cupos-tbl th { background: #1e293b; color: #fff; font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px;
        text-align: left; padding: 9px 8px; white-space: nowrap; }
    .cupos-tbl th.num, .cupos-tbl td.num { text-align: right; }
    .cupos-tbl .cupos-c-num { width: 84px; }
    .cupos-tbl .cupos-c-acc { width: 44px; text-align: center; }
    .cupos-tbl td { padding: 0; border-bottom: 1px solid #e5e7eb; height: 40px; }
    .cupos-tbl tr:last-child td { border-bottom: none; }
    .cupos-tbl td + td { border-left: 1px solid #e5e7eb; }
    .cupos-tbl tbody tr:hover td { background: #f0f7fd; }
    .cupos-tbl td.cupos-txt { padding: 0 8px; font-size: 14px; color: #0f172a; }
    .cupos-tbl td.cupos-c-num { position: relative; padding-left: 14px; font-size: 14px; font-weight: 700; color: #0f172a; }
    .cupos-tbl input { height: 37px; width: 100%; box-sizing: border-box; border: none; background: transparent; padding: 0 8px;
        font-size: 14px; font-family: inherit; color: #0f172a; outline: none; }
    .cupos-tbl input[data-campo=numero] { font-weight: 700; padding-left: 2px; }
    .cupos-tbl input.num { text-align: right; }
    .cupos-tbl input:focus { background: #f5f9fd; box-shadow: inset 0 0 0 2px #0067b1; }
    .cupos-tbl input.cupos-mal, .cupos-foto-btn.cupos-mal { background: #fef2f2; box-shadow: inset 0 0 0 2px #dc2626; }
    .cupos-tbl tr.cupos-cambiada td { background: #fffbeb; }
    /* Estado de cada solicitud: verde = llegó; rojo = programada (como el cronograma). */
    .cupos-estado { position: absolute; left: 5px; top: 50%; width: 6px; height: 6px; margin-top: -3px; border-radius: 50%; background: transparent; }
    .cupos-llego .cupos-estado { background: #16a34a; }
    .cupos-programada .cupos-estado { background: #dc2626; }
    .cupos-tbl tr[data-nueva]:last-child .cupos-borrar { visibility: hidden; }
    .cupos-foto-btn { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 4px; display: inline-flex; justify-content: center;
        border-radius: 6px; margin: 0; }
    .cupos-foto-btn:hover { color: #0067b1; background: #e1effa; }
    .cupos-foto-btn i { font-size: 18px; }
    .cupos-foto-btn.cupos-foto-lista, .cupos-foto-tiene { color: #0067b1; }
    .cupos-borrar { border: none; background: none; cursor: pointer; color: #94a3b8; padding: 4px; display: inline-flex; justify-content: center; border-radius: 6px; }
    .cupos-borrar:hover { color: #dc2626; background: #fef2f2; }
    .cupos-borrar i { font-size: 18px; }
    .cupos-vacio { text-align: center; color: #94a3b8; font-size: 13px; padding: 16px 8px !important; height: auto !important; }
    .cupos-vacio i { display: block; font-size: 30px; color: #cbd5e0; margin: 0 auto 4px; }

    /* Pie: el resumen del mes y, debajo, Cancelar y Guardar centrados. */
    .cupos-pie { display: flex; align-items: center; justify-content: center; gap: 8px 10px; margin-top: 10px; flex-wrap: wrap; }
    .cupos-resumen-mes { font-size: 12px; color: #475569; }
    .cupos-resumen-mes b { color: #0f172a; }
    .cupos-cambios { font-size: 12px; font-weight: 600; color: #b45309; }
    .cupos-botones { flex: 1 1 100%; display: flex; justify-content: center; gap: 10px; }
    .cupos-pie .btn-primary-maquinaria { margin-left: 0; white-space: nowrap; height: 36px; min-width: 120px; justify-content: center; }
    .cupos-pie .cupos-cancelar { background: #e2e8f0; color: #475569; box-shadow: none; }
    .cupos-pie .cupos-cancelar:hover { background: #cbd5e1; }
    .cupos-pie .btn-primary-maquinaria:disabled { opacity: .45; cursor: default; }
    .cupos-ocupado { opacity: .55; pointer-events: none; }

    /* Visor de la foto: el mismo del Inventario (.alm-visor-foto): caja blanca centrada y, quien puede cargar,
       al pasar el mouse un velo con la cámara y «Cambiar foto» (tocar la foto = elegir otra). */
    .cupos-foto-visor { position: fixed; inset: 0; z-index: 100000; background: rgba(15,23,42,.8); display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 12px; padding: 24px; box-sizing: border-box; cursor: zoom-out; }
    .cupos-visor-caja { position: relative; display: inline-flex; border-radius: 12px; overflow: hidden; background: #fff;
        box-shadow: 0 25px 50px -12px rgba(0,0,0,.5); cursor: default; }
    .cupos-visor-caja.editable { cursor: pointer; }
    .cupos-visor-caja img { display: block; max-width: min(92vw, 900px); max-height: calc(86vh - 40px); background: #fff; object-fit: contain; }
    .cupos-visor-velo { position: absolute; inset: 0; background: rgba(15,23,42,.55); color: #fff; display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 8px; opacity: 0; transition: opacity .18s ease; font-size: 14px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .5px; pointer-events: none; }
    .cupos-visor-velo .material-icons { font-size: 44px; }
    .cupos-visor-caja.editable:hover .cupos-visor-velo, .cupos-visor-caja.editable:focus-visible .cupos-visor-velo { opacity: 1; }
    .cupos-foto-visor .cupos-visor-x { position: absolute; top: 14px; right: 18px; color: #fff; font-size: 30px; cursor: pointer; }
    .cupos-foto-visor > span { color: #fff; font-size: 13px; font-weight: 600; text-align: center; }

    @media (max-width: 600px) {
        #cuposContenido { padding: 10px; }
        .cupos-arriba { flex-wrap: wrap; }
        .cupos-campo { flex: 1 1 calc(50% - 5px); }
        .cupos-dd [data-dd-texto] { font-size: 13px; }
        .cupos-dd .cupos-dd-boton { gap: 4px; padding-left: 8px; }
        .cupos-cupo-lado { flex: 1 1 100%; }
        .cupos-cupo-lado .cupos-cupo, .cupos-cupo-caja input { width: 100%; }
        .cupos-bloque { padding: 10px; }
        .cupos-pie .btn-primary-maquinaria { flex: 1 1 0; justify-content: center; }
        /* Cada cisterna, una tarjeta de dos renglones: N.º | Plan | Llegada | foto, y debajo Litros | quitar. */
        .cupos-tbl-marco { overflow: visible; border: none; }
        .cupos-tbl { min-width: 0; display: block; }
        .cupos-tbl thead { display: none; }
        .cupos-tbl tbody { display: flex; flex-direction: column; gap: 8px; }
        .cupos-tbl tr { display: grid; grid-template-columns: 52px 1fr 1fr 40px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: #fff; }
        .cupos-tbl td { height: auto; border: none !important; position: relative; }
        .cupos-tbl td[data-l] { padding-top: 14px; }
        .cupos-tbl td[data-l]::before { content: attr(data-l); position: absolute; left: 8px; top: 3px; font-size: 9px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .3px; color: #94a3b8; }
        .cupos-tbl td.cupos-txt[data-l] { padding-top: 16px; padding-bottom: 6px; }
        .cupos-tbl td:nth-child(1) { grid-row: 1 / 3; grid-column: 1; display: flex; align-items: center; border-right: 1px solid #eef2f6 !important; }
        .cupos-tbl td:nth-child(2) { grid-row: 1; grid-column: 2; }
        .cupos-tbl td:nth-child(3) { grid-row: 1; grid-column: 3; border-left: 1px solid #eef2f6 !important; }
        .cupos-tbl td:nth-child(4) { grid-row: 2; grid-column: 2 / 4; border-top: 1px solid #eef2f6 !important; }
        .cupos-tbl td:nth-child(4) input { text-align: left; }
        .cupos-tbl td:nth-child(5) { grid-row: 1; grid-column: 4; display: flex; align-items: center; justify-content: center; }
        .cupos-tbl td:nth-child(6) { grid-row: 2; grid-column: 4; display: flex; align-items: center; justify-content: center; border-top: 1px solid #eef2f6 !important; }
        .cupos-tbl input { height: 30px; font-size: 13px; padding: 0 6px; }
        .cupos-tbl td.cupos-vacio { grid-column: 1 / -1; grid-row: auto; display: block; }
    }
</style>

<div id="cuposModal" class="modal-overlay" data-abrir="{{ request()->boolean('cupos') ? '1' : '' }}">
    <div class="modal-content cupos-modal" role="dialog" aria-labelledby="cuposModalTitulo" tabindex="-1">
        <div class="cupos-modal-cab">
            <h2 id="cuposModalTitulo"><i class="material-icons">local_gas_station</i> Cupos de Gasoil</h2>
            <div class="cupos-modal-acc">
                <button type="button" class="cupos-modal-x" data-excel-cupos title="Descargar en Excel las cisternas del patio"><i class="material-icons">download</i></button>
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
        var URL_PANEL = @json(route('consumibles.cupos', ['parcial' => 1]));
        var URL_CUPO = @json(route('consumibles.cupos.guardar'));
        var URL_CISTERNA = @json(route('consumibles.cupos.cisterna'));
        var URL_BORRAR = @json(route('consumibles.cupos.borrarCisterna', ['id' => '__ID__']));
        var URL_EXCEL = @json(route('consumibles.cupos.excel'));
        var URL_FOTO = @json(route('consumibles.cupos.fotoCisterna', ['id' => '__ID__']));
        var JSON_H = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
        var CAMPOS = ['numero', 'plan', 'llegada', 'litros'];
        var modal = document.getElementById('cuposModal');
        if (!modal) return;

        function aviso(msg, tipo) { if (window.showToast) window.showToast(msg, tipo); else console.log(msg); }
        function panelDe(patio) { return modal.querySelector('[data-panel="' + patio + '"]'); }

        // El patio elegido (su tabla y, arriba, su cupo) se mantiene al repintar y al volver a abrir.
        function mostrarPatio(patio) {
            if (!panelDe(patio)) patio = 'TIGRE';
            window.__cuposPatio = patio;
            ponerLista(modal.querySelector('[data-elegir-patio]'), patio);
            ponerLista(modal.querySelector('[data-elegir-mes]'), panelDe(patio).dataset.mes);   // el mes de la tabla de ese patio
            modal.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== patio; });
            modal.querySelectorAll('[data-cupo-de]').forEach(function (c) { c.hidden = c.dataset.cupoDe !== patio; });
        }
        // Una lista (custom-dropdown): muestra la opción de ese valor y la marca.
        function ponerLista(dd, valor) {
            if (!dd) return;
            var op = dd.querySelector('.dropdown-item[data-value="' + valor + '"]');
            dd.dataset.valor = valor;
            dd.querySelectorAll('.dropdown-item').forEach(function (i) { i.classList.toggle('selected', i === op); });
            if (op) dd.querySelector('[data-dd-texto]').textContent = op.textContent.trim();
        }
        mostrarPatio(window.__cuposPatio || 'TIGRE');
        modal.querySelectorAll('[data-panel]').forEach(function (p) { prepararPanel(p); });

        // Al abrir, el foco entra al modal (Escape lo cierra sin tocar antes con el ratón).
        function abrir() {
            modal.classList.add('active');
            modal.querySelector('.cupos-modal').focus();
            // Una tabla que se guardó y no se pudo repintar, se repinta ahora.
            modal.querySelectorAll('form[data-guardada]').forEach(function (f) { repintarPanel(f.closest('[data-panel]').dataset.panel); });
        }
        // Al cerrar se quita ?cupos de la dirección (recargar no lo abre de nuevo).
        function cerrar() {
            modal.classList.remove('active');
            var u = new URL(location.href);
            if (u.searchParams.has('cupos')) { u.searchParams.delete('cupos'); history.replaceState(history.state, '', u.toString()); }
        }
        if (modal.dataset.abrir) abrir();

        // Repinta la tabla de UN patio (con el mes dado); la del otro patio, con lo que tenga escrito, no se toca.
        function repintarPanel(patio, mes) {
            var viejo = panelDe(patio);
            var url = URL_PANEL + '&patio=' + encodeURIComponent(patio) + '&mes=' + encodeURIComponent(mes || viejo.dataset.mes);
            return window.apiFetch(url, { headers: JSON_H })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) {
                    var t = document.createElement('template');
                    t.innerHTML = html.trim();
                    var nuevo = t.content.querySelector('[data-panel]');
                    if (!nuevo || !viejo.isConnected) return;
                    viejo.replaceWith(nuevo);
                    prepararPanel(nuevo);
                    mostrarPatio(window.__cuposPatio);
                    return true;
                })
                .catch(function () { aviso('No se pudo actualizar. Revise la conexión.', 'error'); return false; });
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
        // requestSubmit no existe en Safari antes de iOS 16: ahí, el evento submit a mano.
        function enviarForm(f) {
            if (f.requestSubmit) f.requestSubmit();
            else f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
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

        // ── Filas ──
        function campo(tr, c) { return tr.querySelector('[data-campo=' + c + ']'); }
        function conFoto(tr) {
            var i = tr.querySelector('[data-foto-fila]');
            return !!(i && i.files && i.files[0]);
        }
        // Una fila nueva cuenta si tiene algo escrito o una foto; una guardada, si algo cambió.
        function llena(tr) {
            return conFoto(tr) || CAMPOS.some(function (c) { var i = campo(tr, c); return i && i.value.trim() !== ''; });
        }
        function cambiada(tr) {
            if (tr.hasAttribute('data-nueva')) return llena(tr);
            return conFoto(tr) || CAMPOS.some(function (c) { var i = campo(tr, c); return i && i.value !== i.defaultValue; });
        }
        // El punto de estado sigue a lo escrito: verde con llegada y litros; rojo si no.
        function marcarEstado(tr) {
            var llego = campo(tr, 'llegada') && campo(tr, 'llegada').value && numeroLitros(campo(tr, 'litros').value) > 0;
            tr.classList.toggle('cupos-llego', !!llego);
            tr.classList.toggle('cupos-programada', !llego && llena(tr));
        }
        // La última fila nueva siempre está vacía: al llenarla aparece otra (con el número siguiente de
        // sugerencia); si sobran vacías al final, se quitan.
        function filaAlFinal(tbody) {
            var nuevas = tbody.querySelectorAll('[data-nueva]');
            var ultima = nuevas[nuevas.length - 1];
            if (!ultima) return;
            if (llena(ultima)) {
                var otra = ultima.cloneNode(true);
                otra.classList.remove('cupos-llego', 'cupos-programada', 'cupos-cambiada');
                otra.querySelectorAll('input').forEach(function (i) {
                    i.classList.remove('cupos-mal');
                    i.value = '';
                });
                var n = campo(otra, 'numero'), previo = parseInt(campo(ultima, 'numero').value || campo(ultima, 'numero').placeholder, 10);
                if (!isNaN(previo)) n.placeholder = previo + 1;
                marcarFoto(otra.querySelector('[data-foto-fila]'));
                tbody.appendChild(otra);
            } else {
                while (nuevas.length > 1 && !llena(nuevas[nuevas.length - 1]) && !llena(nuevas[nuevas.length - 2])) {
                    nuevas[nuevas.length - 1].remove();
                    nuevas = tbody.querySelectorAll('[data-nueva]');
                }
            }
        }
        // Cuántas filas cambiaron: se avisa y Guardar se enciende.
        function contarCambios(form) {
            var n = Array.prototype.filter.call(form.querySelectorAll('[data-fila]'), function (tr) {
                var c = cambiada(tr);
                tr.classList.toggle('cupos-cambiada', c);
                return c;
            }).length;
            var t = form.querySelector('[data-cambios]'), g = form.querySelector('[data-guardar]');
            if (t) t.textContent = n ? (n === 1 ? '1 fila sin guardar' : n + ' filas sin guardar') : '';
            if (g) g.disabled = !n;
            return n;
        }
        function prepararPanel(p) {
            var tbody = p.querySelector('[data-filas]');
            if (tbody) filaAlFinal(tbody);
            var f = p.querySelector('form[data-accion=cisterna]');
            if (f) contarCambios(f);
        }
        function marcarFoto(input) {
            if (!input) return;
            var lista = !!(input.files && input.files[0]);
            var btn = input.closest('.cupos-foto-btn');
            btn.classList.toggle('cupos-foto-lista', lista);
            btn.classList.remove('cupos-mal');
            btn.title = lista ? 'Foto: ' + input.files[0].name + ' (sale con Guardar)' : 'Foto de la cisterna (sale con Guardar)';
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
        function mismoCupo(input) { return numeroLitros(input.value) === numeroLitros(input.defaultValue); }
        function sinGuardar(patio) {
            var f = panelDe(patio) && panelDe(patio).querySelector('form[data-accion=cisterna]');
            return f && !f.dataset.guardada ? contarCambios(f) : 0;
        }
        // Si hay filas sin guardar, pregunta antes de perderlas.
        function siPuedePerder(patio, hacer) {
            if (!sinGuardar(patio)) { hacer(); return; }
            window.confirmarAccion({
                title: 'Filas sin guardar',
                message: 'Hay cambios en la tabla que no se han guardado. ¿Dejarlos?',
                confirmText: 'Dejarlos', type: 'warning',
            }, hacer);
        }

        modal.addEventListener('submit', function (e) {
            var f = e.target.closest('form[data-accion]');
            if (!f) return;
            e.preventDefault();
            if (f.dataset.enviando) return;   // un envío a la vez (Enter dos veces no guarda dos veces)
            var patio = f.querySelector('[name=patio]').value;
            if (f.dataset.accion === 'cupo') { guardarCupo(f, patio); return; }
            // Ya se guardó y falló el repintado: estas filas siguen como nuevas y reenviarlas las duplicaría.
            if (f.dataset.guardada) { aviso('Ya está guardado. Actualizando la tabla…', 'info'); repintarPanel(patio); return; }

            var filas = Array.prototype.filter.call(f.querySelectorAll('[data-fila]'), cambiada);
            if (!filas.length) return;
            var datos = new FormData();
            datos.append('patio', patio);
            filas.forEach(function (tr, i) {
                var k = 'cisternas[' + i + ']';
                if (tr.dataset.id) datos.append(k + '[id]', tr.dataset.id);
                CAMPOS.forEach(function (c) { datos.append(k + '[' + c + ']', campo(tr, c).value.trim()); });
                var foto = tr.querySelector('[data-foto-fila]');
                if (foto && foto.files && foto.files[0]) datos.append(k + '[foto]', foto.files[0]);
            });
            f.querySelectorAll('.cupos-mal').forEach(function (i) { i.classList.remove('cupos-mal'); });
            f.dataset.enviando = '1';
            f.classList.add('cupos-ocupado');
            enviar(URL_CISTERNA, 'POST', datos)
                .then(function (b) {
                    var mes = f.closest('[data-panel]').dataset.mes, otros = [];
                    Object.keys(b.meses || {}).forEach(function (m) { if (m !== mes) otros.push(b.meses[m]); });
                    aviso((b.guardadas === 1 ? 'Cisterna guardada.' : b.guardadas + ' cisternas guardadas.')
                        + (otros.length ? ' Quedó en ' + otros.join(', ') + '.' : ''), 'success');
                    delete f.dataset.enviando;
                    f.dataset.guardada = '1';      // hasta que llegue la tabla nueva no se vuelve a enviar
                    return repintarPanel(patio).then(function (llego) {
                        // Sin la tabla nueva, la vieja queda bloqueada (lo escrito ya está guardado); al reabrir, se repinta.
                        if (!llego) aviso('Se guardó, pero la tabla no se actualizó. Cierre y vuelva a abrir Cupos.', 'warning');
                    });
                })
                .catch(function (m) {
                    // Marca en rojo el campo que falló (cisternas.N.campo) y dice el primero.
                    var errores = (m && m.errores) || {}, primero = '';
                    Object.keys(errores).forEach(function (k) {
                        var p = k.split('.');
                        if (p[0] !== 'cisternas' || !filas[p[1]]) return;
                        var tr = filas[p[1]];
                        var el = p[2] === 'foto' ? tr.querySelector('.cupos-foto-btn') : campo(tr, p[2]);
                        if (el) el.classList.add('cupos-mal');
                        var n = campo(tr, 'numero');
                        if (!primero) primero = 'Solicitud ' + (n.value || n.placeholder || (+p[1] + 1)) + ': ' + errores[k][0];
                    });
                    aviso(primero || (m && m.mensaje) || 'No se pudo guardar.', 'error');
                })
                .finally(function () { delete f.dataset.enviando; if (!f.dataset.guardada) f.classList.remove('cupos-ocupado'); });
        });

        function guardarCupo(f, patio) {
            var cupo = f.querySelector('input[name=litros]');
            if (cupo.value.trim() === '') cupo.value = cupo.defaultValue;   // vacío vuelve a lo que tenía
            if (mismoCupo(cupo)) return;
            var valor = cupo.value;
            f.dataset.enviando = '1';
            enviar(URL_CUPO, 'POST', new FormData(f))
                .then(function () {
                    cupo.defaultValue = valor;
                    aviso('Cupo guardado.', 'success');
                    // La nota «cupo de ese mes» de la tabla, al día (si no hay filas sin guardar).
                    if (!sinGuardar(patio)) repintarPanel(patio);
                })
                .catch(function (m) { aviso((m && m.mensaje) || 'No se pudo guardar el cupo.', 'error'); })
                .finally(function () {
                    delete f.dataset.enviando;
                    // Cambiado otra vez mientras se guardaba: se envía lo último.
                    if (cupo.value !== valor && !mismoCupo(cupo) && document.activeElement !== cupo) enviarForm(f);
                });
        }

        // Se cierra al tocar fuera solo si el toque EMPEZÓ fuera (no al soltar ahí una selección de texto).
        var abajoEnFondo = false;
        modal.addEventListener('mousedown', function (e) {
            abajoEnFondo = e.target === modal;
            // Tocar una opción de las listas no mueve el foco: si no, el foco pasa al modal y Cargar (que cierra
            // las listas al cambiar el foco) la cerraba antes del clic y no se elegía nada.
            if (e.target.closest('.cupos-dd .dropdown-content')) e.preventDefault();
        });
        modal.addEventListener('click', function (e) {
            if ((e.target === modal && abajoEnFondo) || e.target.closest('[data-cerrar-modal]')) { cerrar(); return; }
            var op = e.target.closest('.cupos-dd .dropdown-item');
            if (op) {
                var dd = op.closest('.cupos-dd'), valor = op.dataset.value;
                dd.classList.remove('active');
                if (dd.hasAttribute('data-elegir-patio')) { mostrarPatio(valor); return; }
                // El mes queda como estaba hasta que se pinte el elegido (si se cancela, no cambia).
                var patioMes = window.__cuposPatio || 'TIGRE';
                if (valor === panelDe(patioMes).dataset.mes) return;
                siPuedePerder(patioMes, function () { repintarPanel(patioMes, valor); });
                return;
            }
            var ver = e.target.closest('[data-ver-foto]');
            if (ver) { verFoto(ver); return; }
            var quitar = e.target.closest('[data-quitar-fila]');
            if (quitar) {
                var tr = quitar.closest('[data-fila]'), tbody = tr.parentNode, form = tr.closest('form');
                tr.remove();
                filaAlFinal(tbody);
                contarCambios(form);
                return;
            }
            // Descargar (encabezado): el Excel de todas las cisternas del patio que se está viendo.
            if (e.target.closest('[data-excel-cupos]')) {
                var patio = window.__cuposPatio || 'TIGRE', lista = modal.querySelector('[data-elegir-patio] [data-dd-texto]');
                var nombre = lista ? lista.textContent.trim() : patio;
                window.descargarArchivo(URL_EXCEL + '?patio=' + encodeURIComponent(patio), null,
                    'Cisternas_Cupo_Gasoil_' + nombre.replace(/\s+/g, '_') + '.xlsx', 'No se pudo descargar el Excel.');
                return;
            }
            // Cancelar: lo escrito sin guardar se deja (la tabla vuelve a lo guardado) y se cierra.
            var cancelar = e.target.closest('[data-cancelar]');
            if (cancelar) {
                var pc = cancelar.closest('[data-panel]');
                if (sinGuardar(pc.dataset.panel)) repintarPanel(pc.dataset.panel);
                cerrar();
                return;
            }
            var b = e.target.closest('[data-borrar]');
            if (!b) return;
            window.confirmarAccion({
                title: 'Quitar cisterna',
                message: 'Se quitará: <b>' + b.dataset.texto.replace(/</g, '&lt;') + '</b>',
                confirmText: 'Quitar', type: 'warning',
            }, function () {
                var fila = b.closest('[data-fila]'), panel = b.closest('[data-panel]');
                enviar(URL_BORRAR.replace('__ID__', b.dataset.borrar), 'DELETE')
                    .then(function () {
                        aviso('Cisterna quitada.', 'success');
                        // Con filas sin guardar en la tabla, solo se quita esa (lo escrito no se pierde).
                        fila.remove();
                        if (!sinGuardar(panel.dataset.panel)) repintarPanel(panel.dataset.panel);
                    })
                    .catch(function (m) { aviso(m && m.mensaje ? m.mensaje : 'No se pudo quitar.', 'error'); });
            });
        });

        modal.addEventListener('input', function (e) {
            var tr = e.target.closest && e.target.closest('[data-fila]');
            if (!tr) return;
            e.target.classList.remove('cupos-mal');
            marcarEstado(tr);
            filaAlFinal(tr.parentNode);
            contarCambios(tr.closest('form'));
        });
        modal.addEventListener('change', function (e) {
            if (!e.target.matches) return;
            if (e.target.matches('[data-foto-fila]')) {
                var tr = e.target.closest('[data-fila]');
                marcarFoto(e.target);
                filaAlFinal(tr.parentNode);
                contarCambios(tr.closest('form'));
                return;
            }
            // El cupo se guarda al salir del cuadro (o con Enter, que envía su formulario).
            if (e.target.matches('.cupos-cupo input[name=litros]')) enviarForm(e.target.form);
        });
        // Enter en la tabla: al cuadro siguiente de la fila y, al final, a la fila de abajo. Se guarda
        // solo con el botón.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('[data-campo]')) return;
            e.preventDefault();
            var tr = e.target.closest('[data-fila]');
            var i = CAMPOS.indexOf(e.target.dataset.campo);
            var sig = i < CAMPOS.length - 1 ? campo(tr, CAMPOS[i + 1]) : (tr.nextElementSibling && campo(tr.nextElementSibling, 'numero'));
            if (sig) sig.focus();
        });
        // Las listas de patio y mes con el teclado: Enter o espacio la abre; abierta, las flechas marcan una
        // opción y Enter (o espacio) la elige.
        modal.addEventListener('keydown', function (e) {
            if (!e.target.matches || !e.target.matches('.cupos-dd-boton')) return;
            var dd = e.target.closest('.cupos-dd'), items = [].slice.call(dd.querySelectorAll('.dropdown-item'));
            var foco = dd.querySelector('.dropdown-item.cupos-dd-foco');
            if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && items.length) {
                e.preventDefault();
                if (!dd.classList.contains('active')) e.target.click();
                // Desde la marcada; si no hay, desde la elegida (o desde un extremo).
                var k = items.indexOf(foco || dd.querySelector('.dropdown-item.selected'));
                if (k < 0) k = e.key === 'ArrowDown' ? -1 : items.length;
                k = Math.min(items.length - 1, Math.max(0, k + (e.key === 'ArrowDown' ? 1 : -1)));
                items.forEach(function (it, i) { it.classList.toggle('cupos-dd-foco', i === k); });
                items[k].scrollIntoView({ block: 'nearest' });
                return;
            }
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            if (dd.classList.contains('active') && foco) { foco.classList.remove('cupos-dd-foco'); foco.click(); return; }
            e.target.click();
        });
        // Escape: con una lista abierta, la cierra; en el cupo deshace lo escrito; si no, cierra el modal.
        modal.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape' || !modal.classList.contains('active')) return;
            e.stopPropagation();
            var abierta = modal.querySelector('.cupos-dd.active');
            if (abierta) { abierta.classList.remove('active'); return; }
            var cupo = e.target.matches && e.target.matches('.cupos-cupo input[name=litros]') ? e.target : null;
            if (cupo && cupo.value !== cupo.defaultValue) { cupo.value = cupo.defaultValue; return; }
            cerrar();
        });

        // La foto de una cisterna, encima del modal; un toque o Escape la cierra. Quien puede cargar,
        // la cambia desde ahí (se sube de una vez).
        function verFoto(btn) {
            var v = document.createElement('div');
            v.className = 'cupos-foto-visor';
            var x = document.createElement('i');
            x.className = 'material-icons cupos-visor-x'; x.title = 'Cerrar'; x.textContent = 'close';
            var caja = document.createElement('div');
            caja.className = 'cupos-visor-caja';
            var img = document.createElement('img');
            img.src = btn.dataset.verFoto; img.alt = 'Foto de la cisterna';
            caja.appendChild(img);
            var t = document.createElement('span');
            t.textContent = btn.dataset.texto || '';
            v.appendChild(x); v.appendChild(caja); v.appendChild(t);
            if (btn.dataset.idFoto) {
                // Como en el Inventario: al pasar el mouse, el velo «Cambiar foto»; tocar la foto la cambia.
                caja.classList.add('editable');
                caja.title = 'Cambiar foto'; caja.tabIndex = 0;
                caja.insertAdjacentHTML('beforeend', '<div class="cupos-visor-velo"><i class="material-icons">photo_camera</i><span>Cambiar foto</span></div>');
                var cambiar = function (e) { e.stopPropagation(); quitar(); cambiarFoto(btn); };
                caja.addEventListener('click', cambiar);
                caja.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cambiar(e); } });
            } else {
                caja.addEventListener('click', function (e) { e.stopPropagation(); });
            }
            function cerrarVisor(e) {
                if (e.type === 'keydown' && e.key !== 'Escape') return;
                e.stopPropagation();
                quitar();
            }
            function quitar() {
                v.remove();
                document.removeEventListener('keydown', cerrarVisor, true);
            }
            v.addEventListener('click', cerrarVisor);
            document.addEventListener('keydown', cerrarVisor, true);
            document.body.appendChild(v);
        }
        function cambiarFoto(btn) {
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
                enviar(URL_FOTO.replace('__ID__', btn.dataset.idFoto), 'POST', datos)
                    .then(function (b) { if (b.foto) btn.dataset.verFoto = b.foto; aviso('Foto cambiada.', 'success'); })
                    .catch(function (m) { aviso((m && m.mensaje) || 'No se pudo guardar la foto.', 'error'); })
                    .finally(function () { if (window.hidePreloader) window.hidePreloader(); });
            });
            input.addEventListener('cancel', function () { input.remove(); });
            input.click();
        }
    })();
</script>
