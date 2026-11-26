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

            window.editarFrente = function(id, idActual) {
                var celda = document.getElementById('frente-cell-' + id);
                if (!celda) return;
                // Se guarda lo que muestra AHORA, salvo que ya este editandose (el select).
                if (!celda.querySelector('#sel-frente-' + id)) celdaOriginal[id] = celda.innerHTML;
                var frentesList = [];
                try { frentesList = JSON.parse(appRoot.dataset.frentes || '[]'); } catch(e) {}

                var opciones = frentesList.map(function(f) {
                    return '<option value="' + esc(f.id) + '"' + (f.id == idActual ? ' selected' : '') + '>' + esc(f.nombre) + '</option>';
                }).join('');
                // Sin frente (o con uno ya no activo): que pida elegir, no que muestre el primero
                // de la lista como si fuera el suyo.
                if (!frentesList.some(function(f) { return f.id == idActual; })) {
                    opciones = '<option value="" disabled selected>— Elegir frente —</option>' + opciones;
                }

                celda.innerHTML =
                    '<div style="display:flex;flex-direction:column;gap:4px;">' +
                        '<select id="sel-frente-' + id + '" style="font-size:12px;padding:4px 6px;border:1px solid #0067b1;border-radius:6px;width:100%;">' + opciones + '</select>' +
                        '<div style="display:flex;gap:4px;">' +
                            '<button onclick="guardarFrente(' + id + ')" style="flex:1;background:#0067b1;color:#fff;border:none;border-radius:6px;padding:4px;cursor:pointer;font-size:11px;font-weight:700;">✓ Guardar</button>' +
                            '<button onclick="cancelarFrente(' + id + ')" style="flex:1;background:#f1f5f9;color:#475569;border:1px solid #cbd5e0;border-radius:6px;padding:4px;cursor:pointer;font-size:11px;">✕</button>' +
                        '</div>' +
                    '</div>';
            };

            window.guardarFrente = function(id) {
                var sel = document.getElementById('sel-frente-' + id);
                if (!sel) return;
                var idFrente = sel.value;
                if (!idFrente) { sel.style.border = '1px solid #ef4444'; return; }
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
                        sel.style.border = '1px solid #ef4444';
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
                    sel.style.border = '1px solid #ef4444';
                    window.toast('No se pudo cambiar el frente. Revisa la conexión.', 'error');
                });
            };

            window.cancelarFrente = function(id) {
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
