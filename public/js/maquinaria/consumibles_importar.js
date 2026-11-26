/*
 * Cargar consumibles → Excel de gasoil: la subida y el Ctrl+V de la tabla completa
 * (partials/cargar_excel.blade.php + cargar.blade.php) y la revisión
 * (importar_revision.blade.php). La SPA no vuelve a ejecutar un <script src> ya cargado, así
 * que cada vista llama a window.consImpArrancar(CFG) en CADA apertura; lo de la petición
 * (rutas) viaja en CFG.
 */
(function () {
    'use strict';

    var CFG = {};
    var cambios = false;    // hay asignaciones sin guardar en la revisión
    var temporizador = null;
    var EXTENSIONES = /\.(xlsx|xlsm|xls)$/i;

    function aviso(tipo, titulo, mensaje) {
        window.showModal({ type: tipo, title: titulo, message: mensaje, confirmText: 'Entendido', hideCancel: true });
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function ir(url) {
        if (typeof window.navigateTo === 'function') window.navigateTo(url);
        else window.location.href = url;
    }

    /** fetch que espera JSON; resuelve {ok, body}. Los 401/419 los ataja el interceptor global. */
    function pedir(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});
        return window.apiFetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (body) { return { ok: r.ok, body: body }; });
        });
    }

    function mensajeDe(body, siFalla) {
        if (body && body.errors) return Object.values(body.errors).flat().join('<br>');
        return (body && body.message) || siFalla;
    }

    function conEspera(promesa) {
        if (window.showPreloader) window.showPreloader();
        return promesa.finally(function () { if (window.hidePreloader) window.hidePreloader(); });
    }

    // ── Subida (archivos o tabla pegada): las dos entran por el mismo lector ─────

    var leyendo = false;    // una lectura a la vez: dos a la par dejarían un borrador huérfano

    /** Manda a leer y, si salen despachos, abre su revisión. */
    function leer(url, opts, siFalla) {
        if (leyendo) {
            if (window.showToast) window.showToast('Espera: ya se está leyendo una carga.', 'warning');
            return;
        }
        leyendo = true;
        conEspera(pedir(url, opts)).then(function (r) {
            if (r.ok && r.body.url) { ir(r.body.url); return; }
            aviso('warning', 'No se pudo leer', mensajeDe(r.body, siFalla));
        }).catch(function (e) {
            if (e && e.message === 'Sesión expirada.') return;
            aviso('error', 'Error', 'No se pudo enviar. Revisa la conexión e intenta de nuevo.');
        }).finally(function () { leyendo = false; });
    }

    /** Los Excel elegidos o soltados en la zona se leen enseguida (sin botón de por medio). */
    function subirArchivos(lista) {
        var archivos = [], rechazados = [];
        Array.prototype.forEach.call(lista || [], function (f) {
            (EXTENSIONES.test(f.name) ? archivos : rechazados).push(f);
        });
        if (rechazados.length) {
            // Con Excel que leer, aviso que no tapa la lectura ni se pierde al abrir la revisión.
            var msj = 'No son archivos de Excel y se dejan fuera: ' + rechazados.map(function (f) { return f.name; }).join(', ');
            if (archivos.length && window.showToast) window.showToast(msj, 'warning');
            else aviso('warning', 'Solo Excel', esc(msj));
        }
        if (!archivos.length) return;
        var fd = new FormData();
        archivos.forEach(function (f) { fd.append('archivos[]', f); });
        leer(CFG.subir, { method: 'POST', body: fd }, 'No se pudieron leer los archivos.');
    }

    /**
     * Columnas de unas filas pegadas que las reglas no reconocieron, preguntadas a la IA
     * (ConsumiblesImportacionController::columnas). Resuelve {fila, mapa} (índices desde 0) o
     * null si no se pudo: quien llama pega entonces por posición, como siempre.
     */
    window.consImpColumnas = function (filas, espec) {
        if (!CFG.columnas) return Promise.resolve(null);
        if (window.showToast) window.showToast('Reconociendo las columnas con IA…', 'info');
        return conEspera(pedir(CFG.columnas, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ filas: filas.slice(0, 20).map(function (f) { return f.slice(0, 30); }), espec: !!espec }),
        })).then(function (r) {
            return r.ok && r.body && r.body.mapa ? r.body : null;
        }).catch(function () { return null; });
    };

    function prepararZona() {
        var zona = document.getElementById('impZona');
        var input = document.getElementById('impArchivos');
        if (!zona || !input || zona.dataset.listo) return;
        zona.dataset.listo = '1';
        input.addEventListener('change', function () { var l = Array.prototype.slice.call(input.files); input.value = ''; subirArchivos(l); });
        ['dragenter', 'dragover'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('arrastrando'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('arrastrando'); });
        });
        zona.addEventListener('drop', function (e) { subirArchivos(e.dataTransfer && e.dataTransfer.files); });
    }

    // ── Revisión: enseñar equipos y proyectos ───────────────────────────────

    window.consImpMarcarCambio = function () { cambios = true; };

    function cerrarSugerencias(excepto) {
        document.querySelectorAll('.imp-sugerencias').forEach(function (s) {
            if (s !== excepto) s.style.display = 'none';
        });
    }

    // turnoBusqueda: solo pinta la ÚLTIMA búsqueda; una anterior que llegue tarde no pisa
    // los resultados de lo que se escribió después.
    var turnoBusqueda = 0;
    window.consImpBuscar = function (input) {
        var caja = input.parentNode.querySelector('.imp-sugerencias');
        var q = input.value.trim();
        var turno = ++turnoBusqueda;
        clearTimeout(temporizador);
        if (q.length < 2) { caja.style.display = 'none'; return; }
        temporizador = setTimeout(function () {
            pedir(CFG.buscar + '?q=' + encodeURIComponent(q)).then(function (r) {
                if (turno !== turnoBusqueda) return;
                var lista = Array.isArray(r.body) ? r.body : [];
                caja.innerHTML = lista.length
                    ? lista.map(function (o) { return '<div data-ref="' + esc(o.ref) + '">' + esc(o.texto) + '</div>'; }).join('')
                    : '<div style="cursor:default;color:#94a3b8;">Sin resultados</div>';
                cerrarSugerencias(caja);
                caja.style.display = 'block';
            }).catch(function () { if (turno === turnoBusqueda) caja.style.display = 'none'; });
        }, 250);
    };

    function elegir(opcion) {
        var buscador = opcion.closest('.imp-buscador');
        var fila = opcion.closest('tr');
        var etiqueta = buscador.querySelector('.imp-elegido');
        fila.dataset.ref = opcion.dataset.ref;
        etiqueta.textContent = '✓ ' + opcion.textContent;
        etiqueta.hidden = false;
        buscador.querySelector('.imp-input').value = '';
        opcion.parentNode.style.display = 'none';
        var chk = fila.querySelector('.imp-no-equipo input');
        if (chk) chk.checked = false;
        cambios = true;
    }

    window.consImpNoEquipo = function (chk) {
        var fila = chk.closest('tr');
        var buscador = fila.querySelector('.imp-buscador');
        if (chk.checked) {
            fila.dataset.ref = 'NO';
            buscador.querySelector('.imp-elegido').hidden = true;
        } else if (fila.dataset.ref === 'NO') {
            delete fila.dataset.ref;
        }
        buscador.querySelector('.imp-input').disabled = chk.checked;
        cambios = true;
    };

    window.consImpVerTodos = function (btn) {
        document.querySelectorAll('#impTablaEquipos tr[data-oculta]').forEach(function (tr) { tr.hidden = false; });
        btn.remove();
    };

    function asignaciones() {
        var frentes = [], equipos = [];
        document.querySelectorAll('select[data-frente-clave]').forEach(function (s) {
            if (s.value) frentes.push({ clave: s.dataset.frenteClave, id: Number(s.value) });
        });
        document.querySelectorAll('tr[data-equipo-clave][data-ref]').forEach(function (tr) {
            equipos.push({ clave: tr.dataset.equipoClave, ref: tr.dataset.ref });
        });
        return frentes.length || equipos.length ? { frentes: frentes, equipos: equipos } : null;
    }

    function guardarAsignaciones() {
        var datos = asignaciones();
        if (!datos) return Promise.resolve(true);
        return pedir(CFG.ensenar, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos),
        }).then(function (r) {
            if (!r.ok) throw new Error(mensajeDe(r.body, 'No se guardó lo asignado.'));
            cambios = false;
            return true;
        });
    }

    window.consImpAplicar = function () {
        var marcados = Array.prototype.filter.call(document.querySelectorAll('#impMeses input'), function (c) { return c.checked; });
        if (!marcados.length) { aviso('warning', 'Meses', 'Marca al menos un mes.'); return; }
        var total = marcados.reduce(function (s, c) { return s + Number(c.dataset.filas || 0); }, 0);
        window.confirmarAccion({
            type: 'info',
            title: 'Cargar consumos',
            message: 'Se cargarán <b>' + total.toLocaleString('es-VE') + '</b> despachos de ' + marcados.length +
                (marcados.length === 1 ? ' mes' : ' meses') + (cambios ? ', con lo que asignaste' : '') + '.',
            confirmText: 'Cargar',
        }, function () {
            conEspera(guardarAsignaciones().then(function () {
                return pedir(CFG.aplicar, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ meses: marcados.map(function (c) { return c.value; }) }),
                });
            })).then(function (r) {
                if (!r.ok) { aviso('warning', 'No se cargó', mensajeDe(r.body, 'No se guardó nada.')); return; }
                if (window.showToast) window.showToast(r.body.message, 'success');
                ir(r.body.url);
            }).catch(function (e) {
                if (e && e.message === 'Sesión expirada.') return;
                aviso('error', 'No se cargó', (e && e.message) || 'No se guardó nada. Intenta de nuevo.');
            });
        });
    };

    // ── Descartar / deshacer (las dos vistas) ───────────────────────────────

    window.consImpDescartar = function (url, volver) {
        window.confirmarAccion({
            title: 'Descartar la carga',
            message: 'Se borra la lectura de estos archivos. No se cargó nada todavía, así que no se pierde ningún consumo.',
            confirmText: 'Descartar',
        }, function () {
            conEspera(pedir(url, { method: 'DELETE' })).then(function (r) {
                if (!r.ok) { aviso('warning', 'No se descartó', mensajeDe(r.body, 'Intenta de nuevo.')); return; }
                ir(volver ? CFG.cargas : window.location.href);
            }).catch(function (e) {
                if (e && e.message === 'Sesión expirada.') return;   // apiFetch ya llevó al login
                aviso('error', 'No se descartó', (e && e.message) || 'Revisa la conexión e intenta de nuevo.');
            });
        });
    };

    window.consImpDeshacer = function (url, n) {
        window.confirmarAccion({
            type: 'error',
            title: 'Deshacer la carga',
            message: 'Se borrarán los <b>' + Number(n).toLocaleString('es-VE') + '</b> consumos que entraron con esta carga. Lo aprendido (equipos y proyectos) se conserva.',
            confirmText: 'Deshacer',
        }, function () {
            conEspera(pedir(url, { method: 'POST' })).then(function (r) {
                if (!r.ok) { aviso('warning', 'No se deshizo', mensajeDe(r.body, 'Intenta de nuevo.')); return; }
                if (window.showToast) window.showToast(r.body.message, 'success');
                ir(window.location.href);
            }).catch(function (e) {
                if (e && e.message === 'Sesión expirada.') return;   // apiFetch ya llevó al login
                aviso('error', 'No se deshizo', (e && e.message) || 'Revisa la conexión e intenta de nuevo. No se borró nada.');
            });
        });
    };

    // ── Arranque ────────────────────────────────────────────────────────────

    window.consImpArrancar = function (cfg) {
        CFG = cfg || {};
        cambios = false;
        prepararZona();
        if (!window.__consImpClicsListos) {
            window.__consImpClicsListos = true;
            document.addEventListener('click', function (e) {
                var opcion = e.target.closest('.imp-sugerencias div[data-ref]');
                if (opcion) { elegir(opcion); return; }
                if (!e.target.closest('.imp-buscador')) cerrarSugerencias(null);
            });
        }
    };
})();
