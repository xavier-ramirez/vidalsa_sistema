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
        btn.innerHTML = '<i class="material-icons" style="font-size:17px; animation:spin 1s linear infinite;">refresh</i>';
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
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-icons" style="font-size:17px;">delete</i>'; btn.style.opacity = '1'; }
        }
    })
    .catch(function() {
        window.toast('Error de red', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-icons" style="font-size:17px;">delete</i>'; btn.style.opacity = '1'; }
    });
};

// Vuelve a pedir los resultados con los filtros de la pantalla y en la misma página: la
// fila, el estado y los contadores quedan como los dejó el servidor.
function consumiblesRefrescar() {
    if (window.submitConsumiblesFilters) window.submitConsumiblesFilters(true);
}

// La lista al día sola: lo que carga o edita otro usuario y lo que el servidor identifica en
// segundo plano llega con el aviso en tiempo real (tiempo-real.js → 'consumibles-cambiaron')
// y, además, al volver a la pestaña. No se repinta con una celda en edición (se
// perdería lo escrito): queda pendiente y se hace al terminar.
(function () {
    if (window.__consumiblesAlDia) return;
    window.__consumiblesAlDia = true;
    var pendiente = false, espera = null;
    function editando() {
        return !!document.querySelector('#consumiblesResultados input, #consumiblesResultados select');
    }
    function refrescar() {
        if (!document.getElementById('consumiblesResultados') || !window.cargarConsumibles) return;
        if (document.hidden || editando()) { pendiente = true; return; }
        pendiente = false;
        clearTimeout(espera);
        // Lo mismo que se ve (la URL: filtros aplicados y página), no un filtro a medio escribir.
        espera = setTimeout(function () { window.cargarConsumibles(location.pathname + location.search, true); }, 300);
    }
    window.addEventListener('consumibles-cambiaron', refrescar);
    // Volver a la pestaña pregunta siempre (como las demás pantallas): cubre lo que pasó sin aviso.
    window.addEventListener('focus', refrescar);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refrescar(); });
    // Terminó una edición en la fila (guardar o cancelar quita el campo): lo que esperaba, ahora.
    document.addEventListener('click', function () { if (pendiente) setTimeout(function () { if (!editando()) refrescar(); }, 0); });
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
            function pintarId(celda, id, valor) {
                celda.dataset.original = valor || '';
                celda.innerHTML =
                    '<div style="display:flex;align-items:center;gap:5px;">' +
                        '<span id="id-txt-' + id + '" style="flex:1;">' + (esc(valor) || '—') + '</span>' +
                        '<button onclick="editarId(' + id + ')" style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;" title="Editar">' +
                            '<i class="material-icons" style="font-size:14px;">edit</i>' +
                        '</button>' +
                    '</div>';
            }

            window.editarId = function(id) {
                var celda = document.getElementById('id-cell-' + id);
                if (!celda) return;
                var valorActual = celda.dataset.original || '';
                celda.innerHTML =
                    '<div style="display:flex;align-items:center;gap:4px;">' +
                        '<input id="inp-id-' + id + '" type="text" value="' + esc(valorActual) + '" ' +
                               'style="font-family:monospace;font-size:12px;padding:3px 6px;border:1px solid #0067b1;border-radius:6px;flex:1;min-width:0;outline:none;" ' +
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

                window.apiFetch('/admin/consumibles/' + id + '/identificador', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json'},
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
                });
            };

            window.cancelarId = function(id) {
                var celda = document.getElementById('id-cell-' + id);
                if (celda) pintarId(celda, id, celda.dataset.original);
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
                sel.disabled = true;

                window.apiFetch('/admin/consumibles/' + id + '/frente', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json'},
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
                .catch(function() { if (sel) { sel.disabled = false; sel.style.border = '1px solid #ef4444'; } });
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
