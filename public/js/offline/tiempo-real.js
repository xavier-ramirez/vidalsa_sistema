/**
 * Aviso en TIEMPO REAL: lo hecho en otro equipo aparece aquí al instante.
 *
 * En el servidor, `php artisan cambios:avisar` vigila el registro de cambios y, en cuanto
 * crece, manda «hubo cambios» por Reverb (WebSocket). Aquí ese aviso le dice a la copia local
 * que pregunte YA (CopiaLocal.hayCambios); cada pantalla se repinta con el evento 'copia-local',
 * como siempre. El aviso no trae datos: se piden por /sync con la sesión y los permisos de cada
 * uno.
 *
 * Mientras está conectado la copia deja de preguntar cada 15 s (CopiaLocal.enVivo): pregunta
 * solo de respaldo. Si se cae (sin red, servidor reiniciando) vuelve a los 15 s y, al
 * reconectar, pregunta ya por lo que pudo pasar mientras tanto. Sin la librería o sin el meta
 * no pasa nada: queda la pregunta cada 15 s.
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
        // En vivo = suscrito al canal (no basta el socket abierto); se cae con la conexión.
        canal.bind('pusher:subscription_succeeded', () => window.CopiaLocal.enVivo(true));
        conexion.connection.bind('state_change', (s) => { if (s.current !== 'connected') window.CopiaLocal.enVivo(false); });
        canal.bind('cambios', () => window.CopiaLocal.hayCambios());
    }).catch(() => {});
})();
