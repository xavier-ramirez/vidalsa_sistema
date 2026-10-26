/**
 * Aviso en TIEMPO REAL: lo hecho en otro equipo aparece aquí al instante.
 *
 * En el servidor, `php artisan cambios:avisar` vigila el registro de cambios y, en cuanto
 * crece, manda «hubo cambios» por Reverb (WebSocket). Aquí ese aviso le dice a la copia local
 * que pregunte YA (CopiaLocal.hayCambios); cada pantalla se repinta con el evento 'copia-local',
 * como siempre. El aviso no trae datos: se piden por /sync con la sesión y los permisos de cada
 * uno.
 *
 * «En vivo» (CopiaLocal.enVivo) = llegan avisos o latidos del vigía (uno cada 25 s): la copia
 * deja de preguntar cada 15 s y pregunta solo de respaldo. Si el socket se cae, o el vigía
 * deja de latir aunque el socket siga abierto, vuelve sola a los 15 s. Al suscribirse (también
 * al reconectar) pregunta ya por lo que pudo pasar mientras tanto. Sin la librería (sin red al
 * cargar) se reintenta al volver la red; sin el meta no pasa nada: queda la pregunta cada 15 s.
 *
 * <meta name="tiempo-real"> (solo con sesión): { clave, canal, puerto, libreria }. Sin puerto
 * se usa el de la página (en el servidor nginx pasa /app a Reverb).
 */
(function () {
    'use strict';

    const meta = document.querySelector('meta[name="tiempo-real"]');
    if (!meta || !meta.content || !window.CopiaLocal || window.__tiempoRealIniciado) return;
    window.__tiempoRealIniciado = true;

    let cfg;
    try { cfg = JSON.parse(meta.content); } catch (e) { return; }

    const tls = window.location.protocol === 'https:';
    const puerto = Number(cfg.puerto) || Number(window.location.port) || (tls ? 443 : 80);
    // Casi tres latidos sin noticias del vigía: se da por caído.
    const SIN_LATIDO_MS = 70000;

    let vence = null;
    function vivo() {
        clearTimeout(vence);
        window.CopiaLocal.enVivo(true);
        vence = setTimeout(caido, SIN_LATIDO_MS);
    }
    function caido() {
        clearTimeout(vence);
        window.CopiaLocal.enVivo(false);
    }

    function iniciar() {
        window.cargarScriptUnaVez(cfg.libreria, () => !!window.Pusher).then(() => {
            const conexion = new window.Pusher(cfg.clave, {
                cluster: '',
                wsHost: window.location.hostname,
                wsPort: puerto,
                wssPort: puerto,
                forceTLS: tls,
                enabledTransports: ['ws', 'wss'],
                disableStats: true,
            });
            const canal = conexion.subscribe(cfg.canal);
            canal.bind('pusher:subscription_succeeded', () => window.CopiaLocal.hayCambios());
            canal.bind('latido', vivo);
            canal.bind('cambios', () => { vivo(); window.CopiaLocal.hayCambios(); });
            conexion.connection.bind('state_change', (s) => { if (s.current !== 'connected') caido(); });
        }).catch(() => window.addEventListener('online', iniciar, { once: true }));
    }

    iniciar();
})();
