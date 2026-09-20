{{-- Papelera de vehículos y auxiliares: la abre el menú Acciones (partials/acciones).
     Vive aparte del Historial porque ese botón sale en las tres pestañas. --}}
@can('super.admin')
<style>
    #hdPapeleraOverlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2500; display: flex; justify-content: center; align-items: center; }
    .hd-pap-modal { background: #fff; border-radius: 14px; width: 92%; max-width: 480px; max-height: 82vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); }
    .hd-pap-head { background: #1e293b; padding: 12px 16px; color: #fff; display: flex; justify-content: center; align-items: center; gap: 8px; position: relative; }
    .hd-pap-head h2 { margin: 0; font-size: 14px; font-weight: 700; }
    .hd-pap-cerrar { position: absolute; right: 12px; background: transparent; border: none; color: #fff; cursor: pointer; opacity: 0.7; display: flex; padding: 2px; }
    .hd-pap-cerrar:hover { opacity: 1; }
    .hd-pap-tools { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 8px; flex-shrink: 0; }
    .hd-pap-buscar { display: flex; align-items: center; gap: 6px; border: 1px solid #cbd5e0; border-radius: 8px; background: #fbfcfd; padding: 0 10px; height: 36px; }
    .hd-pap-buscar:focus-within { border-color: #0067b1; background: #fff; }
    .hd-pap-buscar input { flex: 1; min-width: 0; border: none; outline: none; background: transparent; font-size: 13px; height: 100%; color: #1e293b; }
    .hd-pap-limpiar { font-size: 16px !important; color: #94a3b8; cursor: pointer; }
    .hd-pap-cuentas { display: flex; gap: 6px; }
    .hd-pap-cuenta { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 5px; height: 30px; padding: 0 6px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; color: #475569; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .hd-pap-cuenta .material-icons { font-size: 15px; }
    .hd-pap-cuenta[data-kind="eq"] .material-icons { color: #1e40af; }
    .hd-pap-cuenta[data-kind="aux"] .material-icons { color: #c2410c; }
    .hd-pap-list { overflow-y: auto; background: #f8fafc; padding: 10px; flex: 1; min-height: 160px; }
    .hd-pap-row { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #fff; border: 1px solid #e2e8f0; border-left-width: 3px; border-radius: 8px; margin-bottom: 5px; }
    .hd-pap-row[data-kind="eq"]  { border-left-color: #1e40af; }
    .hd-pap-row[data-kind="aux"] { border-left-color: #c2410c; }
    .hd-pap-media { width: 42px; height: 42px; border-radius: 6px; flex-shrink: 0; border: 1px solid #e2e8f0; background: #fff; object-fit: contain; display: flex; align-items: center; justify-content: center; }
    .hd-pap-media .material-icons { font-size: 20px; }
    .hd-pap-row[data-kind="eq"]  .hd-pap-ico { background: #eff6ff; color: #1e40af; }
    .hd-pap-row[data-kind="aux"] .hd-pap-ico { background: #fff7ed; color: #c2410c; }
    .hd-pap-info { flex: 1; min-width: 0; }
    .hd-pap-tit { font-weight: 700; color: #1e293b; font-size: 12px; text-transform: uppercase; line-height: 1.2; }
    .hd-pap-tit span { color: #64748b; font-weight: 500; }
    .hd-pap-sub { font-size: 11px; color: #64748b; margin-top: 2px; word-break: break-word; }
    .hd-pap-sub span { color: #f97316; }
    .hd-pap-autor { font-size: 10px; color: #94a3b8; margin-top: 2px; }
    .hd-pap-btns { display: flex; flex-direction: column; gap: 4px; flex-shrink: 0; }
    .hd-pap-btns button { padding: 5px 8px; color: #fff; border: none; border-radius: 6px; display: inline-flex; align-items: center; cursor: pointer; }
    .hd-pap-btns .material-icons { font-size: 13px; }
    .hd-pap-restaurar { background: #10b981; }
    .hd-pap-borrar { background: #ef4444; }
    .hd-pap-vacio { padding: 24px; text-align: center; color: #94a3b8; font-size: 12px; }
    .hd-pap-vacio .material-icons { font-size: 24px; display: block; margin: 0 auto 6px; }
    .hd-pap-aviso { padding: 8px 10px; margin-bottom: 8px; border-radius: 8px; background: #fef2f2; border: 1px solid #fecaca; color: #b91c1c; font-size: 12px; }
</style>
<script>
(function () {
    var esc = window.escapeHtml;   // helper central (dom_helpers.js)
    // norm/tokenize salen de window.FuzzySearch (fuzzy_search.js) y se leen AL USARLOS,
    // no aquí: ese script va al final del layout, así que en una carga completa (F5)
    // este bloque corre antes de que exista. Por SPA ya estaba y no se notaba.

    // Las dos fuentes. Cada endpoint trae su propia forma de JSON; normalizar()
    // las lleva a una sola para pintarlas mezcladas.
    var FUENTES = {
        eq:  { url: @json(route('equipos.papelera')),           base: @json(url('admin/equipos')),            nombre: 'vehículo', plural: 'Vehículos',  icono: 'directions_car' },
        aux: { url: @json(route('equipos-auxiliares.papelera')), base: @json(url('admin/equipos-auxiliares')), nombre: 'auxiliar', plural: 'Auxiliares', icono: 'construction' }
    };

    // turno: descarta la respuesta de una carga vieja si ya se pidió otra.
    var estado = { items: [], fallidas: [], term: '', cargado: false, turno: 0 };

    // "dd/mm/aaaa hh:mm" → "aaaammddhhmm", para ordenar las dos listas juntas.
    function claveFecha(s) {
        var m = /^(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2})/.exec(s || '');
        return m ? m[3] + m[2] + m[1] + m[4] + m[5] : '';
    }

    function compacto(s) { return s.replace(/[\s\-.\/]/g, ''); }

    function normalizar(it, kind) {
        var aux = kind === 'aux';
        var fecha = (aux ? it.deleted_at : it.eliminado_en) || '';
        var campos = [it.placa, it.serial_chasis, it.serial_motor, it.serial, it.codigo, it.tipo, it.marca, it.modelo]
            .filter(Boolean).map(function (c) { return window.FuzzySearch.norm(c); });
        return {
            kind:   kind,
            id:     it.id,
            tipo:   it.tipo || (aux ? 'AUXILIAR' : 'EQUIPO'),
            meta:   [it.marca, it.modelo].filter(Boolean).join(' '),
            ident:  (aux ? it.serial : (it.placa || it.serial_chasis || it.codigo)) || ('#' + it.id),
            frente: it.frente || '',
            foto:   it.foto_drive_id || '',
            // El endpoint de auxiliares manda null si no se sabe quién lo borró;
            // el de vehículos ya manda 'Desconocido'. Mismo texto para los dos.
            autor:  (aux ? it.deleted_by : it.eliminado_por) || 'Desconocido',
            fecha:  fecha,
            orden:  claveFecha(fecha),
            hay:    campos.join(' '),
            // Sin espacios/guiones/puntos CAMPO POR CAMPO, no todo junto: pegados,
            // placa AB12 + chasis 3CD… harían que "AB123" encontrara este registro.
            camposC: campos.map(compacto)
        };
    }

    // Cada palabra buscada tiene que estar en la placa, los seriales, el código,
    // el tipo, la marca o el modelo (sin acentos ni mayúsculas). También se compara
    // sin espacios, guiones ni puntos: "A12-EA6G" encuentra "A12EA6G". Es por
    // subcadena exacta y no con FuzzySearch.rank a propósito: con tolerancia a
    // typos, "82BD00152" traería también "82BD00576", y aquí se restaura o se
    // borra para siempre lo que sale en la lista.
    function coincide(it, tokens) {
        return tokens.every(function (t) {
            var tc = compacto(t);
            return it.hay.indexOf(t) !== -1 || it.camposC.some(function (c) { return c.indexOf(tc) !== -1; });
        });
    }

    function vacio(icono, texto) {
        return '<div class="hd-pap-vacio"><i class="material-icons">' + icono + '</i>' + texto + '</div>';
    }

    function icono(kind) {
        return '<div class="hd-pap-media hd-pap-ico"><i class="material-icons">' + FUENTES[kind].icono + '</i></div>';
    }

    function fila(it) {
        var media = it.foto
            ? '<img class="hd-pap-media" alt="" src="https://drive.google.com/thumbnail?id=' + encodeURIComponent(it.foto) + '&sz=w120">'
            : icono(it.kind);
        var ref = ' data-kind="' + it.kind + '" data-id="' + esc(String(it.id)) + '"';
        return '<div class="hd-pap-row" data-kind="' + it.kind + '">' + media +
            '<div class="hd-pap-info">' +
                '<div class="hd-pap-tit">' + esc(it.tipo) + (it.meta ? ' · <span>' + esc(it.meta) + '</span>' : '') + '</div>' +
                '<div class="hd-pap-sub">' + esc(it.ident) + (it.frente ? ' · <span>' + esc(it.frente) + '</span>' : '') + '</div>' +
                '<div class="hd-pap-autor">' + esc(it.autor) + (it.fecha ? ' · ' + esc(it.fecha) : '') + '</div>' +
            '</div>' +
            '<div class="hd-pap-btns">' +
                '<button type="button" class="hd-pap-restaurar" data-accion="restaurar"' + ref + ' title="Restaurar"><i class="material-icons">restore</i></button>' +
                '<button type="button" class="hd-pap-borrar" data-accion="borrar"' + ref + ' title="Eliminar permanentemente"><i class="material-icons">delete_forever</i></button>' +
            '</div>' +
        '</div>';
    }

    function pintar() {
        var list = document.getElementById('hdPapeleraList');
        if (!list || !estado.cargado) return;

        var tokens = window.FuzzySearch.tokenize(estado.term);
        var halladas = tokens.length
            ? estado.items.filter(function (it) { return coincide(it, tokens); })
            : estado.items;

        // Contadores (solo informan, no filtran): respetan la búsqueda.
        var n = { eq: 0, aux: 0 };
        halladas.forEach(function (it) { n[it.kind]++; });
        document.querySelectorAll('#hdPapeleraOverlay .hd-pap-cuenta').forEach(function (c) {
            c.querySelector('.n').textContent = n[c.getAttribute('data-kind')];
        });

        var aviso = estado.fallidas.length
            ? '<div class="hd-pap-aviso">No se pudo cargar: ' + estado.fallidas.join(' y ') + '.</div>'
            : '';
        var cuerpo;
        if (halladas.length) cuerpo = halladas.map(fila).join('');
        else if (tokens.length) cuerpo = vacio('search_off', 'Sin coincidencias.');
        else if (estado.fallidas.length) cuerpo = '';   // no decir "vacía" si no se pudo leer
        else cuerpo = vacio('inbox', 'Papelera vacía');
        list.innerHTML = aviso + cuerpo;
    }

    function cargar() {
        var turno = ++estado.turno;
        var kinds = Object.keys(FUENTES);
        Promise.all(kinds.map(function (k) {
            return window.apiFetch(FUENTES[k].url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                .then(function (d) { return (d.items || []).map(function (it) { return normalizar(it, k); }); })
                .catch(function () { return null; });
        })).then(function (listas) {
            if (turno !== estado.turno) return;
            estado.items = [];
            estado.fallidas = [];
            listas.forEach(function (l, i) {
                if (l === null) estado.fallidas.push(FUENTES[kinds[i]].plural);
                else estado.items = estado.items.concat(l);
            });
            // Lo borrado más reciente primero, sin importar de qué lista venga.
            estado.items.sort(function (a, b) { return a.orden < b.orden ? 1 : (a.orden > b.orden ? -1 : 0); });
            estado.cargado = true;
            pintar();
        });
    }

    function cerrar() {
        var o = document.getElementById('hdPapeleraOverlay');
        if (o) o.remove();
    }

    function ejecutar(it, restaurar) {
        var url = FUENTES[it.kind].base + '/' + encodeURIComponent(it.id) + (restaurar ? '/restore' : '/permanente');
        if (window.showPreloader) window.showPreloader();
        window.apiFetch(url, { method: restaurar ? 'PATCH' : 'DELETE', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (res.ok && res.body.success) {
                    window.toast(res.body.message || (restaurar ? 'Restaurado.' : 'Eliminado permanentemente.'), 'success');
                    cargar();
                } else {
                    window.toast(res.body.message || (restaurar ? 'No se pudo restaurar.' : 'No se pudo eliminar.'), 'error');
                }
            })
            .catch(function () { window.toast('Error de red.', 'error'); })
            .finally(function () { if (window.hidePreloader) window.hidePreloader(); });
    }

    function confirmar(it, restaurar) {
        var cual = 'el ' + FUENTES[it.kind].nombre + ' "' + esc(it.ident) + '"';
        window.showModal(restaurar ? {
            type: 'info',
            title: 'Restaurar',
            message: '¿Restaurar ' + cual + '?<br><br>Volverá al listado activo.',
            confirmText: 'Restaurar',
            cancelText: 'Cancelar',
            onConfirm: function () { ejecutar(it, true); }
        } : {
            type: 'danger',
            title: 'Eliminar permanentemente',
            message: '¿Eliminar ' + cual + ' de forma permanente?<br>Esta acción no se puede deshacer.',
            confirmText: 'Eliminar',
            cancelText: 'Cancelar',
            onConfirm: function () { ejecutar(it, false); }
        });
    }

    function contador(kind) {
        return '<span class="hd-pap-cuenta" data-kind="' + kind + '"><i class="material-icons">' + FUENTES[kind].icono + '</i>' +
            FUENTES[kind].plural + ' <span class="n">0</span></span>';
    }

    function construir() {
        cerrar();
        var ov = document.createElement('div');
        ov.id = 'hdPapeleraOverlay';
        ov.innerHTML =
            '<div class="hd-pap-modal" role="dialog" aria-modal="true" aria-label="Papelera">' +
                '<div class="hd-pap-head">' +
                    '<i class="material-icons" style="color:#f59e0b;font-size:18px;">delete_sweep</i><h2>Papelera</h2>' +
                    '<button type="button" class="hd-pap-cerrar" data-cerrar title="Cerrar"><i class="material-icons" style="font-size:18px;">close</i></button>' +
                '</div>' +
                '<div class="hd-pap-tools">' +
                    '<div class="hd-pap-buscar">' +
                        '<i class="material-icons" style="font-size:18px;color:#94a3b8;">search</i>' +
                        '<input type="text" id="hdPapeleraBuscar" placeholder="Buscar por placa, serial, código, tipo o modelo..." autocomplete="off">' +
                        '<i class="material-icons hd-pap-limpiar" data-limpiar title="Limpiar" style="display:none;">close</i>' +
                    '</div>' +
                    '<div class="hd-pap-cuentas">' + contador('eq') + contador('aux') + '</div>' +
                '</div>' +
                '<div class="hd-pap-list" id="hdPapeleraList">' +
                    '<div class="hd-pap-vacio"><i class="material-icons" style="animation:spin 1s linear infinite;">sync</i></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);

        var input = ov.querySelector('#hdPapeleraBuscar');
        var limpiar = ov.querySelector('[data-limpiar]');
        input.addEventListener('input', function () {
            estado.term = input.value;
            limpiar.style.display = input.value ? '' : 'none';
            pintar();
        });

        // Listeners sobre el propio overlay: nace y muere con el modal, así que
        // no se acumulan aunque la vista se vuelva a montar por la SPA.
        ov.addEventListener('click', function (e) {
            if (e.target === ov || e.target.closest('[data-cerrar]')) { cerrar(); return; }
            if (e.target.closest('[data-limpiar]')) {
                input.value = ''; estado.term = ''; limpiar.style.display = 'none';
                pintar(); input.focus();
                return;
            }
            var btn = e.target.closest('[data-accion]');
            if (!btn) return;
            var it = estado.items.find(function (x) { return x.kind === btn.dataset.kind && String(x.id) === btn.dataset.id; });
            if (it) confirmar(it, btn.dataset.accion === 'restaurar');
        });
        // Foto de Drive rota → ícono del tipo. 'error' no burbujea: va en captura.
        ov.addEventListener('error', function (e) {
            var img = e.target;
            if (img.tagName !== 'IMG' || !img.classList.contains('hd-pap-media')) return;
            var row = img.closest('.hd-pap-row');
            if (row) img.outerHTML = icono(row.getAttribute('data-kind'));
        }, true);

        // En PC el cursor va directo al buscador; en el teléfono no, para no
        // tapar la lista con el teclado nada más abrir.
        if (window.matchMedia('(hover: hover)').matches) input.focus();
    }

    // El botón es visible para super.admin, pero la papelera (operación destructiva)
    // exige el permiso literal user.delete. Sin él: toast moderno y NO abre.
    var canDelete = @can('user.delete') true @else false @endcan;

    window.abrirPapelera = function () {
        if (!canDelete) {
            window.showToast('No tienes permiso para gestionar la papelera (requiere "Eliminar Equipos").', 'error');
            return;
        }
        estado.items = [];
        estado.fallidas = [];
        estado.term = '';
        estado.cargado = false;
        construir();
        cargar();
    };

    // Si se navega (SPA, p. ej. con "atrás") con el modal abierto, no dejarlo
    // flotando sobre el módulo nuevo.
    if (!window.__hdPapeleraSpaBound) {
        window.__hdPapeleraSpaBound = true;
        window.addEventListener('spa:contentLoaded', function () {
            var o = document.getElementById('hdPapeleraOverlay');
            if (o) o.remove();
        });
    }
})();
</script>
@endcan
