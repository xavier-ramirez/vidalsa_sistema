/**
 * Interceptor GLOBAL de fetch: expiracion de sesion (419 / 401) y window.apiFetch.
 *
 * Movido TAL CUAL desde el <script> inline del <head> de estructura_base.blade.php
 * (2026-08-24). No cambia una linea de logica. Motivo: viajaba dentro del HTML, o sea
 * que se re-descargaba y re-parseaba en CADA carga completa Y en CADA navegacion SPA
 * (navegacion.js pide la pagina entera con cache:'no-store'). Como archivo lleva
 * ?v=filemtime y cae en el cache inmutable de nginx: se baja una vez por version.
 *
 * SIGUE EN EL <head> y SIGUE SIENDO SINCRONO (sin defer), en el mismo punto exacto:
 * los <script> inline de @yield('content') usan window.apiFetch al evaluarse, asi que
 * llegar mas tarde los dejaria con undefined. Misma regla que dom_helpers.js.
 */
// Interceptor GLOBAL de Fetch para manejar expiración de sesión (419, 401)
const originalFetch = window.fetch;
window.fetch = async function (...args) {
    try {
        let response = await originalFetch.apply(this, args);

        // ── 419 NO es una sesión muerta ────────────────────────────────────
        // 401 = no hay sesión. 419 = el TOKEN no vale, que es otra cosa: la
        // sesión puede estar perfectamente viva. Y con el Service Worker
        // sirviendo el HTML desde su caché, un token viejo es lo NORMAL —
        // basta con que el servidor haya rotado el suyo desde que se cacheó
        // la página.
        //
        // Tratarlos igual expulsaba al usuario con la sesión intacta: pulsaba
        // un botón, salía "Tu sesión expiró por seguridad" y al volver a
        // entrar todo funcionaba, porque nunca se había caído nada. El login
        // ya resolvía esto para SU formulario (handshake /refresh-csrf +
        // reintento); las pantallas de dentro no tenían nada.
        //
        // Aquí se hace lo mismo y UNA sola vez: se pide un token fresco, se
        // reescribe el <meta> —que es de donde lo lee getCsrf() para las
        // siguientes— y se repite la petición. Si el segundo intento vuelve a
        // dar 419, entonces sí: la sesión está muerta de verdad y se sigue al
        // bloque de abajo.
        if (response.status === 419 && !args[2]) {
            try {
                const tk = await originalFetch('/refresh-csrf', {
                    cache: 'no-store', credentials: 'same-origin'
                });
                if (tk.ok) {
                    const fresco = (await tk.text()).trim();
                    if (fresco) {
                        const meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) meta.setAttribute('content', fresco);
                        const conf = Object.assign({}, args[1] || {});
                        const cab = Object.assign({}, conf.headers || {});
                        for (const k in cab) {
                            if (k.toLowerCase() === 'x-csrf-token') delete cab[k];
                        }
                        cab['X-CSRF-TOKEN'] = fresco;
                        conf.headers = cab;

                        // El _token del CUERPO manda sobre la cabecera: Laravel mira
                        // primero input('_token') y solo despues la cabecera. Si el
                        // formulario lo lleva dentro (lo normal al mandar un <form>
                        // con FormData), refrescar solo la cabecera no arreglaba nada.
                        try {
                            const b = conf.body;
                            if (typeof FormData !== 'undefined' && b instanceof FormData && b.has('_token')) {
                                b.set('_token', fresco);
                            } else if (typeof URLSearchParams !== 'undefined' && b instanceof URLSearchParams && b.has('_token')) {
                                b.set('_token', fresco);
                            }
                        } catch (e) { /* body no manipulable: queda la cabecera */ }
                        // El tercer argumento marca el reintento: sin él, un 419
                        // persistente se reintentaría en bucle.
                        return window.fetch(args[0], conf, true);
                    }
                }
            } catch (e) { /* sin red: cae al manejo de abajo */ }
        }

        // Si la sesión expiró de verdad (401, o 419 que no se arregló)
        if (response.status === 401 || response.status === 419) {
            // DUEÑO ÚNICO de "la sesión murió". Corta aquí y devuelve una promesa
            // que no resuelve nunca, así que NINGÚN módulo llega a ver un 401/419:
            // por eso ya no existen las ramas de "sesión expirada" que tenían
            // equipos_index, form_logic y outbox-sync — no podían ejecutarse.
            //
            // Con ?aviso= el login explica POR QUÉ se cerró la sesión (un flash no
            // serviría: esa pantalla se sirve desde el caché del Service Worker).
            // Y si lo que falló fue la subida del outbox, es que HABÍA cambios sin
            // subir (drain() solo llama con la cola llena): el aviso lo dice, que
            // era lo único que se perdía al cortar la petición aquí.
            var _u = String((args[0] && args[0].url) || args[0] || '');
            window.location.href = _u.indexOf('/offline/sync') !== -1
                ? '/?aviso=sesion_expirada_pendientes'
                : '/?aviso=sesion_expirada';
            return new Promise(() => { }); // Promesa pendiente eterna
        }
        return response;
    } catch (err) {
        // Conexión rechazada (servidor caído, sin red, etc.).
        //
        // DUEÑO ÚNICO de "se fue la red", igual que lo es de "se cayó la sesión".
        // Un TypeError de fetch contra NUESTRO servidor = no se alcanzó (los errores
        // HTTP 4xx/5xx resuelven, no lanzan). Como aquí pasan TODAS las peticiones de
        // la app, con esto el aviso "Sin conexión" —y su botón "Trabajar sin
        // conexión"— sale hagas lo que hagas: filtrar, navegar, guardar.
        //
        // Antes esto estaba copiado a mano en CINCO módulos, así que solo aparecía si
        // el usuario tocaba justo una de esas pantallas; en cualquier otra se iba
        // internet y no pasaba nada. Y el evento 'offline' del navegador no cubre el
        // caso típico: navigator.onLine sigue en true mientras haya cualquier interfaz
        // levantada (wifi sin internet, ethernet, VPN).
        //
        // Los abortos (AbortController de las búsquedas) quedan fuera solos: lanzan un
        // DOMException, no un TypeError. Y las peticiones a otros dominios se descartan
        // por origen: que falle un servicio externo no dice nada de NUESTRO servidor.
        var _url = String((args[0] && args[0].url) || args[0] || '');
        var _propia = true;   // sin URL legible se asume nuestra (es lo normal)
        try { _propia = new URL(_url, location.href).origin === location.origin; } catch (e) {}
        if (err instanceof TypeError && _propia && window.netStatus
            && typeof window.netStatus.showOffline === 'function') {
            window.netStatus.showOffline();
        }
        // Se relanza para que el caller (ej. fetchNotifs) decida qué hacer.
        // El console.warn anterior generaba ruido en cada poll cuando no
        // habia red — eliminado.
        throw err;
    }
};
