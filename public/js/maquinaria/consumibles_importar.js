/*
 * Cargar consumibles → Excel de gasoil (partials/cargar_excel.blade.php): la subida, cuyos
 * despachos caen en la tabla de carga de abajo (window.consCargarFilasExcel, cargar.blade.php)
 * como lo pegado; el reporte de gasoil pegado con encabezados (por el mismo lector), las
 * columnas de lo pegado con IA y deshacer una carga. La SPA no vuelve a
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

    function ir(url) {
        if (typeof window.navigateTo === 'function') window.navigateTo(url);
        else window.location.href = url;
    }

    /** fetch que espera JSON; resuelve {ok, body}. Los 401/419 los ataja el interceptor global. */
    function pedir(url, opts) {
        opts = opts || {};
        opts.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});
        return window.apiFetch(url, opts).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (body) { return { ok: r.ok, status: r.status, body: body }; });
        });
    }

    function mensajeDe(body, siFalla) {
        if (body && body.errors) return Object.values(body.errors).flat().map(window.escapeHtml).join('<br>');
        return window.escapeHtml((body && body.message) || siFalla);
    }

    function conEspera(promesa) {
        if (window.showPreloader) window.showPreloader();
        return promesa.finally(function () { if (window.hidePreloader) window.hidePreloader(); });
    }

    // ── Subida: los despachos del Excel van a la tabla de carga ──────────────

    var leyendo = false;    // una lectura a la vez: la tabla recibiría las dos mezcladas

    /**
     * Manda a leer y pone los despachos en la tabla (se guardan con su Guardar). Resuelve true si
     * llegaron a la tabla (o no hace falta más). sinAviso: quien llama se encarga si no se pudo.
     */
    function leer(url, opts, siFalla, sinAviso) {
        if (leyendo) {
            if (window.showToast) window.showToast('Espera: ya se está leyendo una carga.', 'warning');
            return Promise.resolve(true);
        }
        leyendo = true;
        return conEspera(pedir(url, opts)).then(function (r) {
            if (r.ok && Array.isArray(r.body.filas)) {
                if (typeof window.consCargarFilasExcel === 'function') window.consCargarFilasExcel(r.body);
                return true;
            }
            // Con sinAviso, un rechazo con motivo (422) se muestra igual: pegarlo en la página no lo arregla.
            if (!sinAviso || r.status === 422) { aviso('warning', 'No se pudo leer', mensajeDe(r.body, siFalla)); return true; }
            return false;
        }).catch(function (e) {
            if (e && e.message === 'Sesión expirada.') return true;
            if (!sinAviso) aviso('error', 'Error', 'No se pudo enviar. Revisa la conexión e intenta de nuevo.');
            return false;
        }).finally(function () { leyendo = false; });
    }

    /**
     * El reporte de gasoil pegado con encabezados: por el mismo lector que un archivo, para que al
     * guardar se salten los despachos ya cargados (los reportes son acumulados). Resuelve true si
     * llegó a la tabla o el servidor dio su motivo; false si no hubo respuesta útil (la página lo
     * pega ella misma).
     */
    window.consImpPegar = function (texto) {
        if (!CFG.pegar) return Promise.resolve(false);
        return leer(CFG.pegar, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ texto: texto }),
        }, '', true);
    };

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
            else aviso('warning', 'Solo Excel', window.escapeHtml(msj));
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

    // ── Borrar una carga entera (lista «Cargas de Excel», botón «Borrar carga») ──

    window.consImpDeshacer = function (url, n, archivos) {
        var esc = window.escapeHtml || function (t) { return String(t).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };
        window.confirmarAccion({
            type: 'error',
            title: 'Borrar la carga de Excel',
            message: 'Se borrarán los <b>' + Number(n).toLocaleString('es-VE') + '</b> consumos que entraron con '
                + (archivos ? '<b>' + esc(archivos) + '</b>' : 'esta carga') + '. Después puedes volver a subir el archivo. Lo aprendido (equipos y proyectos) se conserva.',
            confirmText: 'Borrar carga',
        }, function () {
            conEspera(pedir(url, { method: 'POST' })).then(function (r) {
                if (!r.ok) { aviso('warning', 'No se borró', mensajeDe(r.body, 'Intenta de nuevo.')); return; }
                if (window.showToast) window.showToast(r.body.message, 'success');
                ir(window.location.href);
            }).catch(function (e) {
                if (e && e.message === 'Sesión expirada.') return;   // apiFetch ya llevó al login
                aviso('error', 'No se borró', (e && e.message) || 'Revisa la conexión e intenta de nuevo. No se borró nada.');
            });
        });
    };

    // ── Arranque ────────────────────────────────────────────────────────────

    window.consImpArrancar = function (cfg) {
        CFG = cfg || {};
        prepararZona();
    };
})();
