/**
 * Diagnóstico EN VIVO de lo que se tocó en esta tanda de cambios.
 *
 * Cómo usarlo:
 *   1. Entra a la aplicación con tu sesión y abre /admin/equipos (o /admin/almacen).
 *   2. Pulsa F12 → pestaña "Console".
 *   3. Pega TODO el contenido de este archivo y dale Enter.
 *   4. Copia la tabla que sale y mándamela.
 *
 * No cambia nada: solo mira. Lo único que "toca" es mostrar y esconder el aviso de
 * red durante un instante para comprobar que funciona (lo deja como estaba).
 */
(function () {
    'use strict';
    var r = [];
    function ok(nombre, cond, detalle) {
        r.push({ '': cond ? 'OK' : 'FALLA', prueba: nombre, detalle: detalle || '' });
    }

    // ── Helpers compartidos ────────────────────────────────────────────────
    ok('dom_helpers cargado', typeof window.apiFetch === 'function' && typeof window.getCsrf === 'function');
    ok('raizVisible existe', typeof window.raizVisible === 'function');
    ok('raizVisible responde el body sin pantalla completa',
        typeof window.raizVisible === 'function' && window.raizVisible() === document.body,
        typeof window.raizVisible === 'function' ? String(window.raizVisible().tagName) : 'no existe');

    // ── El interceptor global está puesto ──────────────────────────────────
    // Si window.fetch fuese el nativo, su toString diría "[native code]".
    var interceptado = typeof window.fetch === 'function' && !/\[native code\]/.test(String(window.fetch));
    ok('interceptor global de fetch instalado', interceptado);

    // ── Aviso de red ───────────────────────────────────────────────────────
    ok('netStatus expuesto', !!window.netStatus);
    ok('netStatus.showOffline', !!(window.netStatus && typeof window.netStatus.showOffline === 'function'));
    ok('netStatus.comprobar', !!(window.netStatus && typeof window.netStatus.comprobar === 'function'));
    var banner = document.getElementById('netStatusBanner');
    ok('banner de red en el DOM', !!banner);
    ok('botón del banner en el DOM', !!document.getElementById('netStatusAction'));

    // ── Modo sin internet: ¿este módulo tiene vista offline registrada? ────
    var tieneOffline = !!(window.OfflineMode && typeof window.OfflineMode.registrar === 'function');
    ok('OfflineMode disponible', tieneOffline);
    ok('copia local (IndexedDB) disponible', !!window.OfflineDB);

    // ── Código muerto que se eliminó: NO debe volver a existir ─────────────
    ok('clearFilter eliminado', typeof window.clearFilter === 'undefined',
        typeof window.clearFilter);
    ok('OfflineAuth NO se carga en páginas internas', typeof window.OfflineAuth === 'undefined',
        typeof window.OfflineAuth);

    // ── Visor de PDF ───────────────────────────────────────────────────────
    ok('openPdfPreview existe', typeof window.openPdfPreview === 'function');
    var iframePdf = document.getElementById('pdfPreviewFrame');
    ok('modal de PDF en el DOM', !!document.getElementById('pdfPreviewModal') && !!iframePdf);
    ok('loader propio del visor en el DOM', !!document.getElementById('pdfViewerLoader'));

    // ── Spinner contado por referencias ────────────────────────────────────
    ok('showPreloader / hidePreloader',
        typeof window.showPreloader === 'function' && typeof window.hidePreloader === 'function');

    // ── PRUEBA VIVA: el aviso de red se pinta y se quita ───────────────────
    var pintaBanner = false;
    try {
        if (window.netStatus && banner) {
            window.netStatus.showOffline();
            pintaBanner = getComputedStyle(banner).display !== 'none';
            window.netStatus.hide();          // se deja como estaba
        }
    } catch (e) { /* si falla, queda en FALLA abajo */ }
    ok('el aviso de red se pinta al pedirlo', pintaBanner,
        pintaBanner ? 'y se volvió a ocultar' : '');

    console.table(r);
    var fallas = r.filter(function (x) { return x[''] === 'FALLA'; });
    console.log(fallas.length
        ? '⚠ ' + fallas.length + ' FALLAS: ' + fallas.map(function (x) { return x.prueba; }).join(' · ')
        : '✅ ' + r.length + ' comprobaciones, todas OK — en ' + location.pathname);
    return fallas.length ? fallas : 'todo OK';
})();
