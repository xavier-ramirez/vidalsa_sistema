/**
 * Estado de red: banner #netStatusBanner y window.netStatus. Solo INFORMA: con o sin
 * internet la app trabaja igual, desde la copia local (offline/copia-local.js), y lo que se
 * guarda sin conexión espera en la bandeja (offline/bandeja.js) y sube solo.
 *
 * Va justo despues de preloader.js y justo antes de global_handlers.js (era un único
 * <script> inline del layout y ese es su orden). Sincrono, sin defer.
 *
 * DEPENDE DE dom_helpers.js Y TIENE QUE CARGAR DESPUES: el IIFE corre al evaluarse el
 * archivo y llama a comprobarConexion(), que usa window.apiFetch —definido en
 * dom_helpers.js—. dom_helpers va en el <head> y esto al final del body, asi que se
 * cumple; pero si alguien moviera cualquiera de los dos, esto reventaria con
 * "window.apiFetch is not a function" al cargar CADA pagina.
 */
(function () {
    const banner = document.getElementById('netStatusBanner');
    if (!banner) return;
    const icon = document.getElementById('netStatusIcon');
    const text = document.getElementById('netStatusText');
    let hideTimer = null;

    function showBanner(message, iconName, bgColor, autoHideMs) {
        if (hideTimer) { clearTimeout(hideTimer); hideTimer = null; }
        banner.style.background = bgColor;
        icon.textContent = iconName;
        text.textContent = message;
        banner.style.display = 'flex';
        // Empujar header + contenido hacia abajo la ALTURA REAL del banner para
        // que no lo tape (se re-mide en cada showBanner: el texto cambia entre
        // estados y en móvil puede ocupar 2 líneas). offsetHeight ya es válido
        // con display:flex; el transform del slide no lo afecta.
        document.documentElement.style.setProperty('--net-banner-h', banner.offsetHeight + 'px');
        document.body.classList.add('net-banner-active');
        requestAnimationFrame(() => {
            banner.style.transform = 'translateY(0)';
        });
        if (autoHideMs && autoHideMs > 0) {
            hideTimer = setTimeout(hideBanner, autoHideMs);
        }
    }
    function hideBanner() {
        banner.style.transform = 'translateY(-100%)';
        // Restaurar el layout: el header vuelve a top:5px (con su transición) y
        // el body a su padding normal — en sync con el slide-up del banner.
        document.body.classList.remove('net-banner-active');
        document.documentElement.style.removeProperty('--net-banner-h');
        setTimeout(() => { banner.style.display = 'none'; }, 300);
    }

    // NO usar navigator.onLine para decidir: miente con el servidor caído.
    let sinConexion = false;

    // ── DUEÑO ÚNICO de "se fue la red" (lo llama también el interceptor de fetch) ──
    // Ya avisado no se repinta: cada petición fallida lo volvería a medir y animar.
    function mostrarOffline() {
        if (sinConexion) return;
        sinConexion = true;
        showBanner('Sin internet · trabajando con la copia de este equipo', 'cloud_off', '#b45309', 0);
        ajustarResondeo();   // a partir de aqui hay que vigilar si vuelve
    }

    // ── DUEÑO ÚNICO de "volvió la red" ────────────────────────────────────────
    // Lo único que lo detecta es el sondeo de abajo (también tras el evento 'online'), y
    // entra por aquí. La copia local pregunta ya (no espera su sondeo de 15 s) y la bandeja sube
    // lo pendiente sola al ver que la copia volvió a llegar al servidor.
    function volvioLaRed() {
        if (!sinConexion) return;
        sinConexion = false;
        showBanner('Conexión restaurada', 'wifi', '#16a34a', 2500);
        ajustarResondeo();   // ya volvio: se apaga el temporizador
        if (window.CopiaLocal) window.CopiaLocal.alGuardar();
    }

    // ── Re-sondeo mientras no hay servidor ────────────────────────────────────
    // El evento 'online' NO basta, y este es el caso REAL de obra: el teléfono nunca
    // pierde el wifi ni los datos —lo que se cae es la salida a internet o el propio
    // servidor—, así que navigator.onLine jamás pasa por false y 'online' no se
    // dispara nunca. Sin esto, el aviso de "sin internet" no se iba nunca.
    //
    // Mismo patrón y misma cadencia que ajustarResondeo() en offline/offline-auth.js,
    // que ya resolvía esto para la pantalla de login. Sondea /up (la ruta de salud de
    // Laravel: sin sesión ni base de datos; el service worker nunca la cachea).
    //
    // Va SIN el interceptor (window.fetchSinInterceptor) y no por window.apiFetch a
    // propósito: dentro de este archivo 'fetch' es el que fetch_interceptor.js ya
    // reemplazo (se carga antes, en el <head>), que ante un fallo llama a showOffline()
    // y repintaría el banner cada 15 segundos. Este sondeo tiene que poder fallar en silencio.
    var MS_RESONDEO = 15000;
    var resondeo = null;
    function sondearServidor() {
        if (!navigator.onLine) return;              // sin interfaz: ni lo intentes
        var corta = (typeof AbortController === 'function') ? new AbortController() : null;
        var tope = corta ? setTimeout(function () { corta.abort(); }, 4000) : null;
        var pedir = window.fetchSinInterceptor || fetch;
        pedir('/up', {
            method: 'GET', cache: 'no-store', credentials: 'same-origin',
            signal: corta ? corta.signal : undefined
        }).then(function () {
            if (tope) clearTimeout(tope);
            volvioLaRed();                          // cualquier respuesta = hay servidor
        }, function () {
            if (tope) clearTimeout(tope);           // sigue sin servidor: en silencio
        });
    }
    function ajustarResondeo() {
        if (!sinConexion) {
            if (resondeo) { clearInterval(resondeo); resondeo = null; }
            return;
        }
        if (resondeo) return;                       // ya hay uno en marcha
        resondeo = setInterval(function () {
            if (document.hidden) return;            // en segundo plano no se gasta
            sondearServidor();
        }, MS_RESONDEO);
    }
    // Volver a la app (desbloquear el teléfono, cambiar de pestaña) es el momento con
    // más probabilidad de que la señal haya cambiado: se pregunta ya, sin esperar al
    // siguiente tic.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && sinConexion) sondearServidor();
    });

    // 'online' solo dice que volvió la INTERFAZ: se pregunta al servidor antes de cantar
    // "Conexión restaurada". Con la señal que va y viene, el aviso verde salía y a los pocos
    // segundos la siguiente petición lo volvía a poner en "Sin internet".
    window.addEventListener('offline', mostrarOffline);
    window.addEventListener('online', function () { if (sinConexion) sondearServidor(); });

    // ── Detección de conexión REAL al cargar ────────────────────────────────
    // navigator.onLine NO es confiable: suele decir "online" aunque el SERVIDOR esté
    // caído. Cualquier RESPUESTA de /up (aunque sea 500) significa que el servidor
    // responde; solo el fallo de red cuenta como sin conexión.
    function comprobarConexion() {
        if (!navigator.onLine) { mostrarOffline(); return; }
        // El aviso NO se saca aquí: esta petición va por window.apiFetch, o sea por el
        // interceptor global de fetch, que es el único que decide que se fue la red.
        // El catch existe solo para atender el rechazo (si no, queda una promesa
        // rechazada sin manejar en cada carga sin conexión).
        window.apiFetch('/up', { method: 'GET', cache: 'no-store' }).catch(function () {});
    }

    // Se publica ANTES de la primera comprobación: así el interceptor ya lo
    // encuentra si esa misma petición falla.
    window.netStatus = { showOffline: mostrarOffline };

    comprobarConexion();
})();
