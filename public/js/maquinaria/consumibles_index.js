/**
 * consumibles_index.js — pantalla Gestión de Consumibles (admin/consumibles/index.blade.php):
 * borrar, edición en la fila del identificador y del frente, y la lista al día sola.
 * El equipo de cada consumo lo identifica el servidor (MatchConsumibles), sin botón.
 */

// ═══════════════════════════════════════════════════════════════════
// ELIMINAR CONSUMIBLE DIRECTO (Sin preguntas, sin claves, sin estorbos)
// ═══════════════════════════════════════════════════════════════════
window.borrarDirecto = function(id, url, btn) {
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="material-icons" style="font-size:16px; animation:spin 1s linear infinite;">refresh</i>';
        btn.style.opacity = '0.5';
    }

    window.apiFetch(url, { headers: { 'Accept': 'application/json' },
        method: 'DELETE'})
    .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, data: data }; }); })
    .then(function(res) {
        var data = res.data || {};
        if (res.ok && data.ok) {
            var row = btn ? btn.closest('tr') : null;
            if (row) {
                row.style.transition = 'opacity 0.2s, transform 0.2s';
                row.style.opacity = '0';
                row.style.transform = 'scale(0.95)';
                setTimeout(function() { row.remove(); }, 200);
            }
            window.toast('Registro eliminado al instante.', 'success');
            // Los contadores de arriba cuentan este registro.
            consumiblesRefrescar();
        } else {
            window.toast(data.message || 'No se pudo eliminar', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-icons" style="font-size:16px;">close</i>'; btn.style.opacity = '1'; }
        }
    })
    .catch(function() {
        window.toast('Error de red', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-icons" style="font-size:16px;">close</i>'; btn.style.opacity = '1'; }
    });
};

// Vuelve a pedir los resultados con los filtros de la pantalla y en la misma página: la
// fila, el estado y los contadores quedan como los dejó el servidor.
// Con otra celda en edición no se repinta (se perdería lo escrito): queda pendiente.
function consumiblesEditando() {
    return !!document.querySelector('#consumiblesResultados input:not(:disabled), #consumiblesResultados select:not(:disabled)');
}
function consumiblesRefrescar() {
    if (consumiblesEditando()) { window.__consumiblesPendiente = true; return; }
    if (window.submitConsumiblesFilters) window.submitConsumiblesFilters(true);
}

// La lista al día sola: lo que carga o edita otro usuario y lo que el servidor identifica en
// segundo plano llega con el aviso en tiempo real (tiempo-real.js → 'consumibles-cambiaron')
// y, además, al volver a la pestaña. No se repinta con una celda en edición (se
// perdería lo escrito): queda pendiente y se hace al terminar.
(function () {
    if (window.__consumiblesAlDia) return;
    window.__consumiblesAlDia = true;
    var espera = null;
    function refrescar() {
        if (!document.getElementById('consumiblesResultados') || !window.cargarConsumibles) return;
        if (document.hidden || consumiblesEditando()) { window.__consumiblesPendiente = true; return; }
        window.__consumiblesPendiente = false;
        clearTimeout(espera);
        espera = setTimeout(function () {
            // Se vuelve a mirar al disparar: en estos 300 ms pudo abrirse un lápiz.
            if (document.hidden || consumiblesEditando()) { window.__consumiblesPendiente = true; return; }
            // Lo último que se pidió (filtro recién elegido) o, si no, lo que se ve.
            window.cargarConsumibles(window._consumiblesUltimaUrl || (location.pathname + location.search), true);
        }, 300);
    }
    window.addEventListener('consumibles-cambiaron', refrescar);
    // Volver a la pestaña pregunta siempre (como las demás pantallas): cubre lo que pasó sin aviso.
    window.addEventListener('focus', refrescar);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refrescar(); });
    // Terminó una edición en la fila (guardar o cancelar quita el campo): lo que esperaba, ahora.
    document.addEventListener('click', function () {
        if (window.__consumiblesPendiente) setTimeout(function () { if (!consumiblesEditando()) refrescar(); }, 0);
    });
})();

// ═══════════════════════════════════════════════════════════════════
// MÓDULO CONSUMIBLES — lógica de la página (edición inline)
// ═══════════════════════════════════════════════════════════════════
if (typeof window.ModuleManager !== 'undefined') {
    ModuleManager.register(
        'consumibles_index',
        function() { return document.getElementById('consumiblesAppRoot') !== null; },
        function() {
            var appRoot = document.getElementById('consumiblesAppRoot');
            if (!appRoot) return;

            // ── Edición inline — Identificador ───────────────────────
            // El identificador lo escribe cualquiera con permiso: se pinta SIEMPRE escapado y
            // el valor de antes vive en la celda (data-original), no dentro de un onclick.
            var esc = window.escapeHtml;
            // Solo se cambia la línea de la placa/serial: arriba, en la misma celda, va el tipo del equipo.
            // La tabla muestra el identificador del equipo (data-mostrado); el lápiz abre lo
            // escrito tal cual (data-original).
            function filaId(celda) { return celda.querySelector('.cons-id-fila'); }
            function pintarId(celda, id) {
                var fila = filaId(celda);
                if (!fila) return;
                fila.innerHTML =
                    '<span id="id-txt-' + id + '" style="flex:1; font-weight:600;">' + (esc(celda.dataset.mostrado || celda.dataset.original) || '—') + '</span>' +
                    '<button onclick="editarId(' + id + ')" class="cons-btn-edit" title="Ver y editar lo escrito">' +
                        '<i class="material-icons" style="font-size:14px;">edit</i>' +
                    '</button>';
            }

            window.editarId = function(id) {
                var celda = document.getElementById('id-cell-' + id);
                if (!celda) return;
                var valorActual = celda.dataset.original || '';
                var fila = filaId(celda);
                if (!fila) return;
                fila.innerHTML =
                    '<div style="display:flex;align-items:center;gap:4px;flex:1;">' +
                        '<input id="inp-id-' + id + '" type="text" maxlength="100" value="' + esc(valorActual) + '" ' +
                               'style="font-size:12px;padding:3px 6px;border:1px solid #0067b1;border-radius:6px;flex:1;min-width:0;outline:none;" ' +
                               'onkeydown="if(event.key===\'Enter\')guardarId(' + id + ');if(event.key===\'Escape\')cancelarId(' + id + ')">' +
                        '<button onclick="guardarId(' + id + ')" style="background:#0067b1;color:#fff;border:none;border-radius:6px;padding:3px 7px;cursor:pointer;font-size:11px;font-weight:700;">✓</button>' +
                        '<button onclick="cancelarId(' + id + ')" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e0;border-radius:6px;padding:3px 7px;cursor:pointer;font-size:11px;">✕</button>' +
                    '</div>';
                var inp = document.getElementById('inp-id-' + id);
                if (inp) inp.focus();
            };

            window.guardarId = function(id) {
                var inp = document.getElementById('inp-id-' + id);
                if (!inp) return;
                var nuevo = inp.value.trim().toUpperCase();
                if (!nuevo) { inp.style.border = '1px solid #ef4444'; return; }
                inp.disabled = true;

                // Con Accept JSON: si el servidor lo rechaza responde 422 con el motivo (sin él,
                // redirige a la página y el toast no decía nada).
                window.apiFetch('/admin/consumibles/' + id + '/identificador', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ identificador: nuevo })
                })
                .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, data: data }; }); })
                .then(function(res) {
                    var data = res.data || {};
                    if (!res.ok || !data.ok) {
                        inp.disabled = false;
                        inp.style.border = '1px solid #ef4444';
                        window.toast(data.message || 'No se pudo guardar el identificador', 'error');
                        return;
                    }
                    // El servidor ya buscó el equipo con el identificador nuevo: se recargan los
                    // resultados para que el estado, el equipo y los contadores lo digan.
                    window.toast('Identificador guardado.', 'success');
                    consumiblesRefrescar();
                })
                .catch(function() {
                    var inp2 = document.getElementById('inp-id-' + id);
                    if (inp2) { inp2.disabled = false; inp2.style.border = '1px solid #ef4444'; }
                    window.toast('No se pudo guardar el identificador. Revisa la conexión.', 'error');
                });
            };

            window.cancelarId = function(id) {
                var celda = document.getElementById('id-cell-' + id);
                if (celda) pintarId(celda, id);
            };

            // ── Edición inline — Frente ───────────────────────────────
            // Lo que tenia cada celda antes de editarla: cancelar la deja como estaba sin
            // volver a pedir la pagina entera.
            var celdaOriginal = {};

            // Se escribe y la lista sugiere (FuzzySearch, el buscador de todo el sistema); elegir un
            // frente lo guarda al momento. Sin botones: Esc o salir del campo deja la celda como estaba.
            function listaFrentes() {
                var l = document.getElementById('consFrenteLista');
                if (!l) {
                    l = document.createElement('div');
                    l.id = 'consFrenteLista';
                    l.className = 'cons-frente-lista';
                    document.body.appendChild(l);
                    // mousedown: gana al blur del campo (si no, se cerraba antes de elegir).
                    l.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        var op = e.target.closest('[data-frente-id]');
                        if (op && l._id) guardarFrente(l._id, op.dataset.frenteId, op.textContent);
                    });
                    // La lista es fija en la pantalla: al desplazar algo (que no sea ella) se cierra.
                    window.addEventListener('scroll', function(e) { if (e.target !== l) l.style.display = 'none'; }, { passive: true, capture: true });
                    window.addEventListener('resize', function() { l.style.display = 'none'; });
                }
                return l;
            }
            function pintarListaFrentes(inp, id) {
                var l = listaFrentes(), q = inp.value.trim(), todos = [];
                try { todos = JSON.parse(appRoot.dataset.frentes || '[]'); } catch(e) {}
                var lista = !q ? todos : window.FuzzySearch
                    ? window.FuzzySearch.filtrar(todos, q, function(f) { return f.nombre; })
                    : todos.filter(function(f) { return f.nombre.toUpperCase().indexOf(q.toUpperCase()) >= 0; });
                l._id = id;
                l._marcado = 0;
                l.innerHTML = lista.length
                    ? lista.map(function(f, k) {
                        return '<div data-frente-id="' + esc(f.id) + '"' + (k === 0 ? ' class="marcado"' : '') + '>' + esc(f.nombre) + '</div>';
                    }).join('')
                    : '<div class="vacio">Ningún frente se parece a «' + esc(q) + '»</div>';
                var r = inp.getBoundingClientRect();
                l.style.left = r.left + 'px';
                l.style.width = Math.max(r.width, 260) + 'px';
                l.style.display = 'block';
                l.scrollTop = 0;
                // Hacia abajo; si no cabe, encima del campo.
                var alto = Math.min(l.scrollHeight, 260);
                l.style.top = (r.bottom + alto + 4 > window.innerHeight ? Math.max(4, r.top - alto - 2) : r.bottom + 2) + 'px';
            }
            function marcarEnLista(paso) {
                var l = listaFrentes(), ops = l.querySelectorAll('[data-frente-id]');
                if (!ops.length) return;
                l._marcado = (l._marcado + paso + ops.length) % ops.length;
                ops.forEach(function(o, k) { o.classList.toggle('marcado', k === l._marcado); });
                ops[l._marcado].scrollIntoView({ block: 'nearest' });
            }

            window.editarFrente = function(id, idActual) {
                var celda = document.getElementById('frente-cell-' + id);
                if (!celda) return;
                // Se guarda lo que muestra AHORA, salvo que ya esté editándose.
                if (!celda.querySelector('#sel-frente-' + id)) celdaOriginal[id] = celda.innerHTML;
                var actual = ((document.getElementById('frente-txt-' + id) || {}).textContent || '').trim();
                if (actual === '—') actual = '';
                celda.innerHTML = '<input id="sel-frente-' + id + '" class="cons-frente-input" type="text" autocomplete="off"'
                    + ' placeholder="' + esc(actual || 'Escribe el frente…') + '"'
                    + ' title="Escribe y elige el frente: se guarda al elegirlo. Esc: dejarlo como estaba.">';
                var inp = document.getElementById('sel-frente-' + id);
                inp.dataset.actual = idActual == null ? '' : String(idActual);
                inp.addEventListener('input', function() { pintarListaFrentes(inp, id); });
                inp.addEventListener('keydown', function(e) {
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); marcarEnLista(e.key === 'ArrowDown' ? 1 : -1); }
                    else if (e.key === 'Enter') {
                        e.preventDefault();
                        var l = listaFrentes(), op = l.querySelectorAll('[data-frente-id]')[l._marcado || 0];
                        if (op) guardarFrente(id, op.dataset.frenteId, op.textContent);
                    } else if (e.key === 'Escape') { e.preventDefault(); cancelarFrente(id); }
                });
                // Salir del campo sin elegir: queda como estaba (no mientras se guarda).
                inp.addEventListener('blur', function() {
                    setTimeout(function() { if (inp.isConnected && !inp.disabled) cancelarFrente(id); }, 150);
                });
                inp.focus();
                pintarListaFrentes(inp, id);
            };

            window.guardarFrente = function(id, idFrente, nombre) {
                var sel = document.getElementById('sel-frente-' + id);
                listaFrentes().style.display = 'none';
                if (!sel || !idFrente) return;
                // El mismo que ya tenía: nada que guardar.
                if (String(idFrente) === sel.dataset.actual) { cancelarFrente(id); return; }
                sel.value = nombre || '';
                sel.disabled = true;

                window.apiFetch('/admin/consumibles/' + id + '/frente', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ id_frente: idFrente })
                })
                .then(function(r) { return r.json().then(function(data) { return { ok: r.ok, data: data }; }); })
                .then(function(res) {
                    var data = res.data || {};
                    if (!res.ok || !data.ok) {
                        sel.disabled = false;
                        sel.classList.add('error');
                        sel.focus();
                        window.toast(data.message || 'No se pudo cambiar el frente', 'error');
                        return;
                    }
                    delete celdaOriginal[id];
                    // Se recarga: el aviso «el equipo está en otro frente» y el resumen por
                    // frente dependen del frente nuevo.
                    window.toast('Frente cambiado a ' + (data.nombre_frente || '—') + '.', 'success');
                    consumiblesRefrescar();
                })
                .catch(function() {
                    sel.disabled = false;
                    sel.classList.add('error');
                    sel.focus();
                    window.toast('No se pudo cambiar el frente. Revisa la conexión.', 'error');
                });
            };

            window.cancelarFrente = function(id) {
                var l = document.getElementById('consFrenteLista');
                if (l && l._id === id) l.style.display = 'none';
                var celda = document.getElementById('frente-cell-' + id);
                if (celda && id in celdaOriginal) {
                    celda.innerHTML = celdaOriginal[id];
                    delete celdaOriginal[id];
                    return;
                }
                consumiblesRefrescar();
            };
        }
    );
}
