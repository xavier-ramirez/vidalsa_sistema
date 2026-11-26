/*
 * Cargar consumibles → Excel de gasoil (partials/cargar_excel.blade.php): la subida, cuyos
 * despachos caen en la tabla de carga de abajo (window.consCargarFilasExcel, cargar.blade.php)
 * como lo pegado; las columnas de lo pegado con IA y deshacer una carga. La SPA no vuelve a
 * ejecutar un <script src> ya cargado, así que la vista llama a window.consImpArrancar(CFG) en
 * CADA apertura; lo de la petición (rutas) viaja en CFG.
 */
(function () {
    'use strict';

    var CFG = {};
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

    // ── Subida: los despachos del Excel van a la tabla de carga ──────────────

    var leyendo = false;    // una lectura a la vez: la tabla recibiría las dos mezcladas

    /** Manda a leer y pone los despachos en la tabla (se guardan con su Guardar). */
    function leer(url, opts, siFalla) {
        if (leyendo) {
            if (window.showToast) window.showToast('Espera: ya se está leyendo una carga.', 'warning');
            return;
        }
        leyendo = true;
        conEspera(pedir(url, opts)).then(function (r) {
            if (r.ok && Array.isArray(r.body.filas)) {
                if (typeof window.consCargarFilasExcel === 'function') window.consCargarFilasExcel(r.body);
                return;
            }
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
            // Con Excel que leer, un aviso que no tapa la lectura.
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

    // ── Deshacer una carga (lista «Cargas de Excel») ───────────────────────

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
        prepararZona();
    };
})();
