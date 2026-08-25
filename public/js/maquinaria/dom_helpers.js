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
})();
