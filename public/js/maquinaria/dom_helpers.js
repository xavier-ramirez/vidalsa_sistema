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
 * SPA-safe: sin estado, idempotente (re-evaluarlo solo reasigna las mismas funciones).
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
})();
