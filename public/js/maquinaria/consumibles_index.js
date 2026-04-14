/**
 * consumibles_index.js – JS Logic for Consumibles (SPA Compatible)
 * v3.2
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

    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';

    fetch(url, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': csrfToken,
            'Accept': 'application/json',
        }
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.ok || data.success || !data.error) {
            var row = btn ? btn.closest('tr') : null;
            if (row) {
                row.style.transition = 'opacity 0.2s, transform 0.2s';
                row.style.opacity = '0';
                row.style.transform = 'scale(0.95)';
                setTimeout(function() { row.remove(); }, 200);
            }
            if (window.showToast) window.showToast('Registro eliminado al instante.', 'success');
        } else {
            console.error('Error al borrar', data);
            if (window.showToast) window.showToast('No se pudo eliminar', 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-icons" style="font-size:17px;">delete</i>'; btn.style.opacity = '1'; }
        }
    })
    .catch(function(e) {
        console.error(e);
        if (window.showToast) window.showToast('Error de red', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="material-icons" style="font-size:17px;">delete</i>'; btn.style.opacity = '1'; }
    });
};

// ═══════════════════════════════════════════════════════════════════
// MÓDULO CONSUMIBLES — lógica de la página (Match, edición inline)
// ═══════════════════════════════════════════════════════════════════
if (typeof window.ModuleManager !== 'undefined') {
    ModuleManager.register(
        'consumibles_index',
        function() { return document.getElementById('consumiblesAppRoot') !== null; },
        function() {
            var appRoot = document.getElementById('consumiblesAppRoot');
            if (!appRoot) return;

            window.CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

            // ── Match automático ──────────────────────────────────────
            window.ejecutarMatch = function() {
                var btn        = document.getElementById('btnMatch');
                var routeMatch = appRoot.dataset.routeMatch;

                if (btn) btn.disabled = true;
                if (window.showPreloader) window.showPreloader();

                fetch(routeMatch, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': window.CSRF, 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var confirmados = data.confirmados || 0;
                    var sinMatch    = data.sin_match   || 0;

                    var p  = document.getElementById('cnt-pendientes');  if (p)  p.textContent = 0;
                    var c  = document.getElementById('cnt-confirmados'); if (c)  c.textContent = confirmados;
                    var sm = document.getElementById('cnt-sinmatch');    if (sm) sm.textContent = sinMatch;

                    var msg = confirmados + ' equipos confirmados';
                    if (sinMatch > 0) msg += ' · ' + sinMatch + ' sin match';
                    // Show toast which will persist across SPA navigation
                    if (window.showToast) window.showToast(msg, 'success');

                    // Instantly reload without hiding the preloader, 
                    // avoiding the double-spinner glitch.
                    if (window.submitConsumiblesFilters) {
                        window.submitConsumiblesFilters();
                    } else {
                        location.reload();
                    }
                })
                .catch(function(err) {
                    if (window.hidePreloader) window.hidePreloader();
                    if (btn) btn.disabled = false;
                    console.error('Error match:', err);
                    if (window.showToast) window.showToast('Error al ejecutar match', 'error');
                });
            };

            // ── Edición inline — Identificador ───────────────────────
            window.editarId = function(id, valorActual) {
                var celda = document.getElementById('id-cell-' + id);
                if (!celda) return;
                var safe = String(valorActual).replace(/'/g, "\\'");
                celda.innerHTML =
                    '<div style="display:flex;align-items:center;gap:4px;">' +
                        '<input id="inp-id-' + id + '" type="text" value="' + valorActual + '" ' +
                               'style="font-family:monospace;font-size:12px;padding:3px 6px;border:1px solid #0067b1;border-radius:6px;flex:1;min-width:0;outline:none;" ' +
                               'onkeydown="if(event.key===\'Enter\')guardarId(' + id + ');if(event.key===\'Escape\')cancelarId(' + id + ',\'' + safe + '\')">' +
                        '<button onclick="guardarId(' + id + ')" style="background:#0067b1;color:#fff;border:none;border-radius:6px;padding:3px 7px;cursor:pointer;font-size:11px;font-weight:700;">✓</button>' +
                        '<button onclick="cancelarId(' + id + ',\'' + safe + '\')" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e0;border-radius:6px;padding:3px 7px;cursor:pointer;font-size:11px;">✕</button>' +
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

                fetch('/admin/consumibles/' + id + '/identificador', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CSRF },
                    body: JSON.stringify({ identificador: nuevo })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        var celda = document.getElementById('id-cell-' + id);
                        if (!celda) return;
                        celda.innerHTML =
                            '<div style="display:flex;align-items:center;gap:5px;">' +
                                '<span id="id-txt-' + id + '" style="flex:1;color:#059669;font-weight:700;">' + nuevo + '</span>' +
                                '<button onclick="editarId(' + id + ',\'' + nuevo.replace(/'/g, "\\'") + '\')" style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;" title="Editar">' +
                                    '<i class="material-icons" style="font-size:14px;">edit</i>' +
                                '</button>' +
                            '</div>';
                        setTimeout(function() {
                            var txt = document.getElementById('id-txt-' + id);
                            if (txt) txt.style.color = '#1e293b';
                        }, 2000);
                    }
                })
                .catch(function() {
                    var inp2 = document.getElementById('inp-id-' + id);
                    if (inp2) { inp2.disabled = false; inp2.style.border = '1px solid #ef4444'; }
                });
            };

            window.cancelarId = function(id, valorOriginal) {
                var celda = document.getElementById('id-cell-' + id);
                if (!celda) return;
                var safe = String(valorOriginal || '').replace(/'/g, "\\'");
                celda.innerHTML =
                    '<div style="display:flex;align-items:center;gap:5px;">' +
                        '<span id="id-txt-' + id + '" style="flex:1;">' + (valorOriginal || '—') + '</span>' +
                        '<button onclick="editarId(' + id + ',\'' + safe + '\')" style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;" title="Editar">' +
                            '<i class="material-icons" style="font-size:14px;">edit</i>' +
                        '</button>' +
                    '</div>';
            };

            // ── Edición inline — Frente ───────────────────────────────
            window.editarFrente = function(id, idActual) {
                var celda = document.getElementById('frente-cell-' + id);
                if (!celda) return;
                var frentesList = [];
                try { frentesList = JSON.parse(appRoot.dataset.frentes || '[]'); } catch(e) {}

                var opciones = frentesList.map(function(f) {
                    return '<option value="' + f.id + '"' + (f.id == idActual ? ' selected' : '') + '>' + f.nombre + '</option>';
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

                fetch('/admin/consumibles/' + id + '/frente', {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.CSRF },
                    body: JSON.stringify({ id_frente: idFrente })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.ok) {
                        var celda = document.getElementById('frente-cell-' + id);
                        if (!celda) return;
                        celda.innerHTML =
                            '<div style="display:flex;align-items:center;gap:4px;">' +
                                '<span id="frente-txt-' + id + '" style="flex:1;color:#059669;font-weight:700;">' + data.nombre_frente + '</span>' +
                                '<button onclick="editarFrente(' + id + ',' + idFrente + ')" style="background:none;border:none;cursor:pointer;padding:2px;color:#94a3b8;" title="Cambiar frente">' +
                                    '<i class="material-icons" style="font-size:14px;">edit</i>' +
                                '</button>' +
                            '</div>';
                        setTimeout(function() {
                            var txt = document.getElementById('frente-txt-' + id);
                            if (txt) txt.style.color = '#1e293b';
                        }, 2000);
                    }
                })
                .catch(function() { if (sel) { sel.disabled = false; sel.style.border = '1px solid #ef4444'; } });
            };

            window.cancelarFrente = function(id) {
                if (window.submitConsumiblesFilters) {
                    window.submitConsumiblesFilters();
                } else {
                    location.reload();
                }
            };
        }
    );
}
