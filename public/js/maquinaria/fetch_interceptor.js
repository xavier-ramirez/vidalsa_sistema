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

/**
 * El fetch del navegador SIN este interceptor, para quien necesite preguntar por la red
 * sin que un fallo dispare el aviso global "Sin conexión".
 *
 * Lo usa el sondeo de estado_red.js: sin conexión pregunta cada 15 s si volvió el servidor,
 * y esa pregunta TIENE que poder fallar en silencio. Pasando por el interceptor, cada fallo
 * llamaba a showOffline() y repintaba el banner.
 *
 * No es un atajo para saltarse el manejo de sesión: quien haga peticiones de VERDAD debe
 * usar window.apiFetch, que es el que atrapa 401/419 y avisa de la caída de red.
 */
window.fetchSinInterceptor = originalFetch.bind(window);

/**
 * La misma petición con otro token CSRF: en la cabecera y, si el cuerpo lo lleva, en el
 * _token del cuerpo, que es el que manda (Laravel mira primero input('_token')).
 */
function conTokenNuevo(opciones, fresco) {
    const conf = Object.assign({}, opciones || {});
    const cab = {};
    const propias = conf.headers || {};
    if (typeof Headers !== 'undefined' && propias instanceof Headers) propias.forEach((v, k) => { cab[k] = v; });
    else Object.assign(cab, propias);
    for (const k in cab) {
        if (k.toLowerCase() === 'x-csrf-token') delete cab[k];
    }
    cab['X-CSRF-TOKEN'] = fresco;
    conf.headers = cab;
    try {
        const b = conf.body;
        if (typeof FormData !== 'undefined' && b instanceof FormData && b.has('_token')) {
            b.set('_token', fresco);
        } else if (typeof URLSearchParams !== 'undefined' && b instanceof URLSearchParams && b.has('_token')) {
            b.set('_token', fresco);
        }
    } catch (e) { /* body no manipulable: queda la cabecera */ }
    return conf;
}

/**
 * La sesión se cerró en el servidor: a la pantalla de inicio de sesión, nada de pedir la clave
 * dentro de la aplicación. Lo que quedó en la bandeja sin internet sigue guardado en el equipo
 * y sube solo al volver a entrar. `response` = el 401/419 que lo dijo.
 *
 * Si se trabajó SIN conexión (entró sin red, o la red se cayó trabajando) y el pase de
 * reingreso no sirvió (venció, cambió la clave, equipo sin pase) no se saca a nadie a mitad de
 * lo que está haciendo: una barra ofrece entrar (avisoEntrar) y mientras tanto se sigue como
 * sin internet. Devuelve true si se fue.
 */
window.irAlInicioDeSesion = async function (response) {
    // Con ?aviso= el login explica POR QUÉ se cerró la sesión (un flash no
    // serviría: esa pantalla se sirve desde el caché del Service Worker).
    // El MOTIVO real, si el servidor lo mandó. ValidarSesionUnica responde con
    // {motivo: 'clave_cambiada'}; antes esto se ignoraba y cualquier 401 llegaba
    // al login como "Tu sesión expiró por seguridad", así que a quien le cambiaron
    // la clave se le decía que su sesión caducó.
    // Solo se aceptan los códigos conocidos: el valor viaja a la URL. Lo pendiente en la
    // bandeja viaja aparte (avisoConPendientes).
    // Ya saliendo (window.salirDelSistema): ese inicio de sesión dice el motivo verdadero.
    if (window.__saliendo) return true;
    var _motivo = null;
    if (response && response.status === 401) {
        try {
            var _cuerpo = await response.clone().json();
            if (_cuerpo && (_cuerpo.motivo === 'clave_cambiada' || _cuerpo.motivo === 'usuario_inactivo')) {
                _motivo = _cuerpo.motivo;
            }
        } catch (e) { /* respuesta sin JSON: vale el aviso genérico */ }
    }
    var _local = !_motivo && !!window.LlaveLocal && typeof window.LlaveLocal.trabajaSinServidor === 'function'
        && window.LlaveLocal.trabajaSinServidor();
    var _aviso = window.avisoConPendientes(_motivo || (_local ? 'volvio_conexion' : 'sesion_expirada'));
    if (_local) { avisoEntrar('/?aviso=' + _aviso); return false; }
    window.location.href = '/?aviso=' + _aviso;
    return true;
};

/**
 * El aviso para el inicio de sesión (?aviso=), con "_pendientes" detrás si queda algo en la
 * bandeja sin subir: es lo único que le dice al usuario que esos cambios siguen guardados y
 * suben al volver a entrar. Lo usan irAlInicioDeSesion y window.salirDelSistema.
 */
window.avisoConPendientes = function (aviso) {
    var hay = window.Bandeja && window.Bandeja.pendientes().some(function (it) { return it.estado === 'pendiente'; });
    return hay ? aviso + '_pendientes' : aviso;
};

/** La barra "volvió la conexión: entra con tu clave", una sola aunque fallen varias peticiones. */
function avisoEntrar(destino) {
    var barra = document.getElementById('avisoEntrarConClave');
    if (!barra) {
        barra = document.createElement('div');
        barra.id = 'avisoEntrarConClave';
        barra.setAttribute('role', 'alert');
        barra.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:100000;display:flex;gap:12px;align-items:center;'
            + 'justify-content:center;flex-wrap:wrap;padding:12px max(16px, env(safe-area-inset-right, 0px)) calc(12px + env(safe-area-inset-bottom, 0px)) max(16px, env(safe-area-inset-left, 0px));'
            + 'background:#0b3d91;color:#fff;font:600 14px/1.35 Inter,system-ui,sans-serif;box-shadow:0 -4px 16px rgba(0,0,0,.25)';
        var texto = document.createElement('span');
        texto.textContent = 'Volvió la conexión. Entra con tu clave para subir tus cambios.';
        var boton = document.createElement('button');
        boton.type = 'button';
        boton.textContent = 'Entrar';
        boton.style.cssText = 'border:0;border-radius:8px;padding:8px 18px;background:#fff;color:#0b3d91;font:700 14px Inter,system-ui,sans-serif;cursor:pointer';
        barra.appendChild(texto);
        barra.appendChild(boton);
        (document.body || document.documentElement).appendChild(barra);
    }
    barra.querySelector('button').onclick = function () { window.location.href = destino; };
}
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
                        // El tercer argumento marca el reintento: sin él, un 419
                        // persistente se reintentaría en bucle.
                        return window.fetch(args[0], conTokenNuevo(args[1], fresco), 'token');
                    }
                }
            } catch (e) { /* sin red: cae al manejo de abajo */ }
        }

        // Si la sesión expiró de verdad (401, o 419 que no se arregló)
        if (response.status === 401 || response.status === 419) {
            // DUEÑO ÚNICO de "la sesión murió": ningún módulo llega a ver un 401/419
            // (solo la bandeja, ver abajo), por eso ya no existen las ramas de
            // "sesión expirada" que tenían equipos_index y form_logic.
            //
            // Trabajó sin conexión y volvió el servidor: la sesión se recupera con el pase de
            // reingreso (reingreso.js) y la petición se repite, sin pedir la clave otra vez
            // estando ya dentro. El tercer argumento (reintento) evita volver a probar.
            // Tras el reintento con token nuevo ('token') SÍ se prueba: sin sesión el
            // servidor contesta primero 419 y recién el reintento da el 401.
            if (args[2] !== 'reanudada' && window.Reingreso) {
                var _re = await window.Reingreso.reanudar();
                if (_re === true) return window.fetch(args[0], conTokenNuevo(args[1], window.getCsrf ? window.getCsrf() : ''), 'reanudada');
                // Se cortó otra vez en medio: es falta de red, no de sesión (ver el catch).
                if (_re === 'sin_red') throw new TypeError('Failed to fetch');
            }
            // La bandeja (cabecera X-Clave-Operacion) recibe el 401/419: primero guarda la
            // operación en el equipo y después llama a irAlInicioDeSesion. Si se saliera ya,
            // lo que se estaba guardando se perdería con la página.
            var _cab = (args[1] && args[1].headers) || {};
            var _deLaBandeja = typeof _cab.has === 'function' ? _cab.has('X-Clave-Operacion')
                : Object.keys(_cab).some(function (k) { return k.toLowerCase() === 'x-clave-operacion'; });
            if (_deLaBandeja) return response;
            if (await window.irAlInicioDeSesion(response)) return new Promise(() => { }); // se va: pendiente eterna
            // Se quedó (trabajó sin conexión, ver irAlInicioDeSesion): para quien pidió es como
            // sin internet, sin el aviso "Sin internet" (la red sí está).
            throw Object.assign(new TypeError('Sin sesión: entra con tu clave'), { sinSesion: true });
        }
        // AQUÍ NO SE ANUNCIA QUE VOLVIÓ LA RED, aunque la petición haya salido bien.
        //
        // Se intentó y estaba mal: una respuesta resuelta NO prueba que haya red. El
        // service worker sirve las navegaciones SPA y los estáticos desde su caché (ver
        // resources/sw.js), así que moverse entre módulos SIN conexión devolvía 200 y el
        // aviso cantaba "Conexión restaurada" con el teléfono todavía incomunicado.
        //
        // El único que decide que la red volvió es el sondeo de estado_red.js, que
        // pregunta de verdad al servidor y no puede confundirse con una copia cacheada.
        // "Se fue la red" sí se decide aquí (ver el catch de abajo): un fallo de fetch
        // contra nuestro origen es concluyente, un éxito no.

        // Algo se GUARDÓ (cualquier método que no sea GET/HEAD, con 2xx): las copias locales
        // en uso (copia-local.js) preguntan ya, y la pantalla muestra el cambio sin esperar
        // al siguiente sondeo de 15 s.
        const _metodo = String((args[1] && args[1].method) || (args[0] && args[0].method) || 'GET').toUpperCase();
        if (response.ok && _metodo !== 'GET' && _metodo !== 'HEAD' && window.CopiaLocal) window.CopiaLocal.alGuardar();
        return response;
    } catch (err) {
        // Conexión rechazada (servidor caído, sin red, etc.).
        //
        // DUEÑO ÚNICO de "se fue la red", igual que lo es de "se cayó la sesión".
        // Un TypeError de fetch contra NUESTRO servidor = no se alcanzó (los errores
        // HTTP 4xx/5xx resuelven, no lanzan). Como aquí pasan TODAS las peticiones de
        // la app, con esto el aviso "Sin internet" sale hagas lo que hagas: filtrar,
        // navegar, guardar.
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
        if (err instanceof TypeError && !err.sinSesion && _propia && window.netStatus
            && typeof window.netStatus.showOffline === 'function') {
            window.netStatus.showOffline();
        }
        // Se relanza para que quien pidió decida qué hacer.
        throw err;
    }
};
