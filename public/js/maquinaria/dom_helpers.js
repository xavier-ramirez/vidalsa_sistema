/**
 * Helpers DOM compartidos — se cargan ANTES que cualquier otro script de maquinaria
 * (ver estructura_base.blade.php). Centralizan dos patrones que estaban
 * reimplementados (y divergiendo) en ~16 archivos:
 *
 *   window.getCsrf()      Token CSRF SIEMPRE fresco desde <meta name="csrf-token">,
 *                         con guard: si el meta no existe devuelve '' en vez de
 *                         reventar (varios sitios hacían `.content` sin protección).
 *                         Se lee en cada llamada porque refreshCsrf() puede rotar
 *                         el token en runtime tras renovar la sesión.
 *
 *   window.escapeHtml()   Escape HTML del set completo  & < > " '  (superset seguro:
 *                         las versiones viejas escA/esc omitían ' y/o > ).
 *
 * SPA-safe: sin estado, idempotente (re-evaluarlo solo reasigna las mismas funciones).
 */
(function () {
    'use strict';

    window.getCsrf = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? (meta.getAttribute('content') || '') : '';
    };

    var ESC_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    window.escapeHtml = function (value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) { return ESC_MAP[c]; });
    };
})();
