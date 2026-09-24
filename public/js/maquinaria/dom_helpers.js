/**
 * Helpers DOM compartidos — se cargan ANTES que cualquier otro script de maquinaria
 * (ver estructura_base.blade.php). Centralizan los patrones que estaban
 * reimplementados (y divergiendo) por toda la app:
 *
 *   window.getCsrf()        Token CSRF SIEMPRE fresco desde <meta name="csrf-token">,
 *                           con guard: si el meta no existe devuelve '' en vez de
 *                           reventar (varios sitios hacían `.content` sin protección).
 *                           Se lee en cada llamada porque refreshCsrf() puede rotar
 *                           el token en runtime tras renovar la sesión.
 *
 *   window.escapeHtml()     Escape HTML del set completo  & < > " '  (superset seguro:
 *                           las versiones viejas escA/esc omitían ' y/o > ).
 *
 *   window.escapeAttrJs()   Para el valor que va DENTRO de un literal JS que a su vez
 *                           va dentro de un atributo HTML: onclick="fn('AQUI')".
 *                           escapeHtml NO sirve ahí (ver el porqué abajo).
 *
 *   window.apiFetch()       fetch() con las cabeceras que TODA llamada a la app
 *                           necesitaba y cada sitio se armaba a mano.
 *
 *   window.toast()          Aviso al usuario, con el guard y el respaldo a alert()
 *                           que estaban repetidos en 6 envoltorios locales.
 *
 * SPA-safe: sin estado, idempotente (re-evaluarlo solo reasigna las mismas funciones).
 *
 * OJO: las pantallas que NO extienden layouts.estructura_base (auth/inicio_sesion,
 * auth/change_password, errors/*) no cargan este archivo y siguen usando fetch pelado.
 */
(function () {
    'use strict';

    window.getCsrf = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (meta) {
            var v = meta.getAttribute('content') || '';
            if (v) return v;
        }
        // Fallback al input oculto de un <form> Blade (@csrf): las pantallas que se
        // renderizan sin el <meta> —o antes de que exista— igual pueden postear.
        var input = document.querySelector('input[name="_token"]');
        return input ? (input.value || '') : '';
    };

    var ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    window.escapeHtml = function (value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return ESC_MAP[c]; });
    };

    /**
     * Escape de DOS capas para un valor que termina dentro de un literal JS entre
     * comillas simples, dentro de un atributo HTML entre comillas dobles:
     *
     *     `<a onclick="seleccionar('${escapeAttrJs(nombre)}')">`
     *
     * Por qué no vale escapeHtml aquí: convertiría  '  en  &#39;  y el navegador,
     * al parsear el atributo, lo decodifica de vuelta a  '  ANTES de que el JS
     * corra → el literal se cierra antes de tiempo y revienta con SyntaxError.
     *
     * Orden correcto (el navegador deshace las capas en sentido inverso):
     *   1. capa JS   : \ y ' se escapan con backslash, y los saltos de línea se van
     *                  (un literal JS de comillas simples no puede contener saltos).
     *   2. capa HTML : " y & y < > se escapan como entidades para no romper el atributo.
     */
    window.escapeAttrJs = function (value) {
        return String(value == null ? '' : value)
            .replace(/\\/g, '\\\\')
            .replace(/'/g, "\\'")
            .replace(/\r?\n/g, '\\n')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    };

    /**
     * Aviso al usuario. Delega en window.showToast (uicomponents.js) y centraliza
     * el guard que estaba copiado en 6 envoltorios `function toast(...)`.
     *
     * Por que el guard: uicomponents.js se carga al FINAL del body, asi que un
     * `var toast = window.showToast` evaluado en un <script> del contenido
     * guardaria undefined. Aqui se resuelve en tiempo de LLAMADA.
     *
     * DEVUELVE si se pudo mostrar. Esto es a proposito y no es un detalle: los
     * cuatro envoltorios de Almacen/Recepcion caian a alert() cuando no habia
     * toasts, y los de fetch_interceptor.js y outbox-sync NO. Metiendo el alert aqui
     * le habria aparecido un alert bloqueante a esos dos —y un alert nativo
     * congela la pestana, incluido el sincronizador que corre de fondo—. Con el
     * booleano, cada quien conserva su comportamiento sin repetir el guard.
     *
     * El tipo por defecto es 'info'; las pantallas que asumen otro lo declaran
     * en su alias de una linea.
     */
    window.toast = function (mensaje, tipo) {
        if (typeof window.showToast !== 'function') return false;
        window.showToast(mensaje, tipo || 'info');
        return true;
    };

    /**
     * Donde colgar algo que tiene que VERSE por encima de todo: avisos, menus
     * contextuales, barras flotantes.
     *
     * En PANTALLA COMPLETA el navegador solo pinta el subarbol del elemento que la
     * ocupa, asi que lo que cuelga del <body> existe pero no se ve. Se noto en /mapa:
     * los avisos que explicaban por que no se podia trazar la tuberia no aparecian y el
     * boton parecia muerto.
     *
     * Punto UNICO a proposito. La pregunta estaba repetida en 9 sitios y no todos
     * respondian igual: ocho miraban solo document.fullscreenElement, pero /mapa entra a
     * pantalla completa con webkitRequestFullscreen, asi que en ese camino la respuesta
     * correcta esta en webkitFullscreenElement y aquellos se equivocaban.
     *
     * fallback: donde colgarlo cuando NO hay pantalla completa (por defecto, el body).
     */
    window.raizVisible = function (fallback) {
        return document.fullscreenElement
            || document.webkitFullscreenElement
            || fallback
            || document.body;
    };

    var SIN_CSRF = { GET: 1, HEAD: 1, OPTIONS: 1 };   // los que VerifyCsrfToken no valida

    /**
     * fetch() que pone el CSRF por ti. Nacio para quitar los 81 sitios que rearmaban
     * el header a mano (alguno leyendo el <meta> sin guard).
     *
     *   X-CSRF-TOKEN   SIEMPRE fresco (getCsrf lee el <meta> en cada llamada, y el
     *                  ping de sesion lo rota en runtime). Solo en los metodos que
     *                  Laravel valida.
     *   credentials    la cookie de sesion viaja aunque el caller no lo piense.
     *
     * NO pone Accept ni X-Requested-With, y esto es deliberado: los dos cambian la
     * RESPUESTA del servidor. Laravel decide con wantsJson() (mira Accept) y con
     * expectsJson() (mira X-Requested-With) si devuelve la pagina HTML o solo datos
     * en JSON, y una docena de controladores —Almacen, Equipo, EquipoAuxiliar,
     * Traspaso…— tienen esa rama. Ponerlos por defecto hizo que la navegacion SPA
     * recibiera JSON en lugar de la pagina y cayera a recarga completa. Cada llamada
     * declara el Accept que de verdad espera; el helper no adivina.
     *
     * Devuelve la MISMA Promise<Response> que fetch, asi que es sustituible tal cual.
     * Pasa por window.fetch (no por el original) para no saltarse el interceptor
     * global de 401/419 (fetch_interceptor.js).
     *
     * Un Content-Type NO se pone solo: FormData necesita que lo ponga el navegador
     * con su boundary.
     */
    window.apiFetch = function (url, opts) {
        opts = opts || {};
        var metodo = String(opts.method || 'GET').toUpperCase();

        var headers = {};
        var propios = opts.headers || {};
        // Headers puede venir como objeto plano o como Headers(); se normaliza a plano.
        if (typeof Headers !== 'undefined' && propios instanceof Headers) {
            var plano = {};
            propios.forEach(function (v, k) { plano[k] = v; });
            propios = plano;
        }
        var yaTraeCsrf = false;
        for (var k in propios) {
            if (!Object.prototype.hasOwnProperty.call(propios, k)) continue;
            headers[k] = propios[k];
            if (k.toLowerCase() === 'x-csrf-token') yaTraeCsrf = true;
        }
        if (!SIN_CSRF[metodo] && !yaTraeCsrf) headers['X-CSRF-TOKEN'] = window.getCsrf();

        var conf = { credentials: 'same-origin' };
        for (var o in opts) {
            if (Object.prototype.hasOwnProperty.call(opts, o)) conf[o] = opts[o];
        }
        conf.headers = headers;
        return window.fetch(url, conf);
    };

    /**
     * POST de un formulario a una ruta que responde JSON { success, message, errors }.
     * `datos` es un objeto plano (los campos vacíos no se mandan). Resuelve con el cuerpo si
     * salió bien; si no, rechaza con un Error cuyo mensaje es el del servidor (el primer
     * error de validación, o `message`) o, en su defecto, `siFalla`.
     */
    window.apiPostForm = function (url, datos, siFalla) {
        var fd = new FormData();
        Object.keys(datos || {}).forEach(function (k) { if (datos[k] != null && datos[k] !== '') fd.append(k, datos[k]); });
        return window.apiFetch(url, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (b) {
                    if (!r.ok || !b.success) {
                        var err = b.errors ? Object.values(b.errors)[0][0] : null;
                        var e = new Error(err || b.message || siFalla);
                        // El cuerpo entero va colgado del Error: algunas respuestas traen
                        // banderas que quien llama necesita para decidir (p. ej.
                        // `requiere_pisar` de la carga masiva, que pregunta y reintenta).
                        // El mensaje sigue igual, así que nadie que solo lo lea se entera.
                        e.respuesta = b;
                        Object.keys(b || {}).forEach(function (k) { if (!(k in e)) e[k] = b[k]; });
                        throw e;
                    }
                    return b;
                });
            }, function () { throw new Error('Error de red. ' + siFalla); });
    };

    /**
     * Fecha de la base (aaaa-mm-dd) a como se lee en pantalla (dd/mm/aaaa).
     *
     * Vive AQUI porque la usan dos fichas distintas —el detalle de un equipo y el de un
     * auxiliar— y estaba escrita dos veces: una dentro de showDetailsImproved
     * (uicomponents.js), local a esa funcion y por tanto inalcanzable desde fuera, y otra
     * copiada a mano en el blade de auxiliares. Dos copias de cuatro lineas son dos
     * copias que un dia dejan de dar el mismo resultado.
     *
     * Lo que NO encaje con aaaa-mm-dd se devuelve tal cual: puede venir ya formateado.
     */
    window.formatearFecha = function (valor) {
        if (!valor || valor === 'N/A' || String(valor).trim() === '') return 'N/A';
        var partes = String(valor).split('-');
        return partes.length === 3 ? partes[2] + '/' + partes[1] + '/' + partes[0] : valor;
    };

    /**
     * Parte una etiqueta larga en varias lineas de como maximo maxChars caracteres,
     * cortando por espacios; una palabra mas larga que el limite se trocea a la fuerza.
     * Devuelve un ARRAY cuando hay que partir, o la cadena original si cabe.
     *
     * El array es justo lo que Chart.js espera para pintar un tick en varias lineas, asi
     * que sirve igual para el eje de cualquier grafico. Vive AQUI y no en el modulo de un
     * dashboard porque la usan dos pantallas distintas (el Dashboard de Flota y el de
     * Consumo del almacen) y no tiene sentido escribirla dos veces: dom_helpers.js va en
     * el <head>, antes que cualquiera de las dos.
     */
    window.wrapLabel = function (label, maxChars) {
        if (!label || String(label).length <= maxChars) return label;
        var palabras = String(label).split(' ');
        var lineas = [];
        var actual = '';
        palabras.forEach(function (w) {
            while (w.length > maxChars) {
                if (actual) { lineas.push(actual); actual = ''; }
                lineas.push(w.slice(0, maxChars));
                w = w.slice(maxChars);
            }
            var prueba = actual ? actual + ' ' + w : w;
            if (prueba.length <= maxChars) {
                actual = prueba;
            } else {
                if (actual) lineas.push(actual);
                actual = w;
            }
        });
        if (actual) lineas.push(actual);
        return lineas.length > 1 ? lineas : label;
    };

    /**
     * Vista previa de un PDF en <canvas> con PDF.js, para teléfono y tablet: sus navegadores no
     * pintan un PDF dentro de un <iframe>. La usan la previa del acta de movilización (equipos) y
     * la de la Nota de Entrega (almacén); antes cada una tenía su propia copia del cargador y del
     * dibujo. PDF.js está vendorizado y se descarga solo la primera vez que hace falta.
     */
    window.pdfEsMovil = function () {
        return window.innerWidth <= 768 || /iPhone|iPad|iPod|Android/i.test(navigator.userAgent);
    };
    var pdfJsPromesa = null;
    function cargarPdfJs() {
        if (window.pdfjsLib) return Promise.resolve();
        if (pdfJsPromesa) return pdfJsPromesa;
        pdfJsPromesa = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            // Versión EN el nombre del archivo: nginx sirve /js/* con caché inmutable de 1 año,
            // así que al actualizar la librería hay que renombrar ambos archivos (lib y worker
            // SIEMPRE de la misma versión).
            s.src = '/js/vendor/pdf-3.11.174.min.js';
            s.onload = function () {
                try { window.pdfjsLib.GlobalWorkerOptions.workerSrc = '/js/vendor/pdf.worker-3.11.174.min.js'; } catch (e) {}
                resolve();
            };
            s.onerror = function () { pdfJsPromesa = null; reject(new Error('No se pudo cargar el visor de PDF.')); };
            document.head.appendChild(s);
        });
        return pdfJsPromesa;
    }
    // Dibuja el blob PDF en el contenedor: una <canvas> por página, ajustada al ancho.
    window.pintarPdfEnCanvas = function (cont, blob) {
        if (!cont || !blob) return Promise.resolve();
        cont.innerHTML = '<div style="color:#cbd5e0;text-align:center;padding:30px;font-size:13px;">Cargando vista previa…</div>';
        return cargarPdfJs()
            .then(function () { return blob.arrayBuffer(); })
            .then(function (buf) { return window.pdfjsLib.getDocument({ data: buf }).promise; })
            .then(function (pdf) {
                cont.innerHTML = '';
                var dpr = window.devicePixelRatio || 1;
                var ancho = cont.clientWidth - 20; // descontar el padding del contenedor
                if (ancho <= 0) ancho = Math.min(window.innerWidth - 40, 900);
                var seq = Promise.resolve();
                for (var i = 1; i <= pdf.numPages; i++) {
                    (function (n) {
                        seq = seq.then(function () {
                            return pdf.getPage(n).then(function (page) {
                                var base = page.getViewport({ scale: 1 });
                                var vp = page.getViewport({ scale: (ancho / base.width) * dpr });
                                var canvas = document.createElement('canvas');
                                canvas.width = vp.width; canvas.height = vp.height;
                                canvas.style.width = '100%'; canvas.style.height = 'auto';
                                canvas.style.display = 'block'; canvas.style.margin = '0 auto 10px';
                                canvas.style.background = '#fff'; canvas.style.boxShadow = '0 1px 6px rgba(0,0,0,0.25)';
                                cont.appendChild(canvas);
                                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
                            });
                        });
                    })(i);
                }
                return seq;
            })
            .catch(function (e) {
                cont.innerHTML = '<div style="color:#fecaca;text-align:center;padding:30px;font-size:13px;">No se pudo mostrar la vista previa. '
                    + window.escapeHtml(e && e.message ? e.message : '') + '</div>';
            });
    };
})();
