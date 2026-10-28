/**
 * Vidalsa — Login OFFLINE (desbloqueo local, Fase 1)
 * --------------------------------------------------
 * Permite ENTRAR sin internet a la versión cacheada, SIN guardar la clave en texto.
 *
 * Cómo: al iniciar sesión CON internet (cuando escribes tu clave), se calcula un
 * HASH irreversible (PBKDF2-SHA256 con sal) de correo+clave y se guarda local. Sin
 * internet, escribes tu correo+clave, se recalcula el hash y se compara. Si coincide,
 * entras a las páginas cacheadas. La clave real NUNCA se almacena; el hash solo no
 * sirve sin conocer la clave (hay que escribirla). Es un "desbloqueo", no da acceso
 * nuevo al servidor (offline no hay servidor): solo abre lo ya cacheado + IndexedDB.
 *
 * Seguridad: si roban el equipo, no hay clave en claro; para entrar hay que saberla.
 * Para olvidar el acceso offline de este equipo: window.OfflineAuth.olvidar().
 */
(function () {
    'use strict';

    const KEY     = 'vidalsa_offline_auth';
    // Clave HEREDADA: antes el verificador "pendiente" se guardaba en localStorage y se
    // confirmaba al llegar a una página autenticada. Eso dejaba basura y un agujero real:
    // un intento con la clave EQUIVOCADA dejaba su pendiente ahí, y el siguiente acceso
    // que llegara al menú por OTRA vía (huella, o el cambio de clave obligatorio) lo
    // promovía a verificador bueno — el acceso offline quedaba pidiendo una clave errónea,
    // o peor, la que tecleó otra persona en este equipo. Ahora el pendiente vive SOLO en
    // memoria y lo resuelve la propia pantalla de login, que ya sabe si el servidor aceptó
    // las credenciales. Esta constante queda únicamente para borrar los restos viejos.
    const PENDING_LEGACY = 'vidalsa_offline_auth_pending';
    // La página interna que mandó aquí por no poder abrir la llave sin red (llave-local.js):
    // entrar sin servidor vuelve a ella en vez de al menú. Solo rutas propias ('/x', no '//x').
    const VOLVER_A = 'vidalsa_volver_a';
    function tomarVolverA() {
        let v = null;
        try { v = sessionStorage.getItem(VOLVER_A); sessionStorage.removeItem(VOLVER_A); } catch (e) {}
        return v && /^\/(?![\/\\])/.test(v) ? v : null;
    }
    const ITER    = 120000; // iteraciones PBKDF2 (lento a propósito, dificulta fuerza bruta)

    function disponible() {
        return !!(window.crypto && window.crypto.subtle && window.localStorage);
    }
    function hex(buf) {
        return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    function unhex(s) {
        const a = new Uint8Array(s.length / 2);
        for (let i = 0; i < a.length; i++) a[i] = parseInt(s.substr(i * 2, 2), 16);
        return a;
    }
    async function derivar(identifier, password, salt) {
        const enc = new TextEncoder();
        const material = await crypto.subtle.importKey(
            'raw', enc.encode(String(identifier).trim().toLowerCase() + ':' + String(password)),
            { name: 'PBKDF2' }, false, ['deriveBits']
        );
        const bits = await crypto.subtle.deriveBits(
            { name: 'PBKDF2', salt: salt, iterations: ITER, hash: 'SHA-256' }, material, 256
        );
        return hex(bits);
    }

    // Verificador a medio hacer: vive SOLO aquí, en memoria, mientras dura el intento de
    // login de esta página. Nunca toca localStorage hasta que sincronizar() lo asciende.
    let _preparado = null; // Promise<{identifier,salt,hash}|null>

    // ¿El candado guardado se hizo con OTRA clave? `claveV` es la huella de la clave
    // actual que manda el servidor (Usuario::claveVersion). Un candado sin `claveV` es de
    // antes de esta comprobación y tampoco se puede dar por bueno.
    function candadoDesfasado(claveV) {
        let v;
        try { v = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return false; }
        return !!v && v.claveV !== claveV;
    }

    // `disponible()` NO se exporta: solo lo usa este módulo por dentro. Cada método
    // público ya se protege solo cuando falta WebCrypto o localStorage.
    window.OfflineAuth = {
        tieneOffline: function () {
            try { return !!JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return false; }
        },
        identidad: function () {
            try { return (JSON.parse(localStorage.getItem(KEY) || 'null') || {}).identifier || ''; } catch (e) { return ''; }
        },
        // Calcula el verificador al ENVIAR el login (en paralelo con la ida y vuelta a la
        // red, así el PBKDF2 no añade espera). Todavía no se guarda: solo queda listo.
        preparar: function (identifier, password) {
            // La llave de lo guardado en el equipo se prepara a la vez (llave-local.js).
            if (window.LlaveLocal) window.LlaveLocal.preparar(identifier, password);
            if (!disponible() || !identifier || !password) { _preparado = null; return null; }
            _preparado = (async function () {
                try {
                    const salt = crypto.getRandomValues(new Uint8Array(16));
                    const hash = await derivar(identifier, password, salt);
                    return { identifier: String(identifier).trim().toLowerCase(), salt: hex(salt), hash: hash };
                } catch (e) { return null; } // sin crypto seguro: no se habilita offline-login
            })();
            return _preparado;
        },
        // El servidor aceptó el acceso. UNA sola entrada para los dos caminos:
        //  · con contraseña → había un verificador preparándose: se guarda ya, sellado con
        //    la versión de la clave con la que se acaba de entrar.
        //  · con huella → no hay nada preparado (no se escribió ninguna clave), así que lo
        //    único que se puede hacer es comprobar que el candado guardado siga
        //    correspondiendo a la clave de hoy. Si no —típico: la cambió un admin desde
        //    otro equipo— se tira, y lo rehace el próximo inicio de sesión con contraseña,
        //    que es el que sí demuestra saberla.
        sincronizar: async function (claveV) {
            tomarVolverA();   // entró con servidor: va a donde lo lleve el inicio de sesión
            const p = _preparado;
            _preparado = null;
            if (window.LlaveLocal) await window.LlaveLocal.confirmar();
            if (p) {
                try {
                    const v = await p;
                    if (v) { v.claveV = claveV || null; localStorage.setItem(KEY, JSON.stringify(v)); return; }
                } catch (e) {}
            }
            if (claveV && candadoDesfasado(claveV)) this.olvidar();
        },
        // El intento no prosperó (credenciales rechazadas, red caída, o la clave está a
        // punto de cambiarse): el verificador a medio hacer se tira y el guardado de antes
        // se queda como estaba.
        descartar: function () {
            _preparado = null;
            if (window.LlaveLocal) window.LlaveLocal.descartar();
        },
        verificar: async function (identifier, password) {
            if (!disponible()) return false;
            let v;
            try { v = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return false; }
            if (!v || !v.salt || !v.hash) return false;
            if (String(identifier).trim().toLowerCase() !== v.identifier) return false;
            const h = await derivar(identifier, password, unhex(v.salt));
            if (h.length !== v.hash.length) return false;
            let dif = 0; // comparación de tiempo ~constante
            for (let i = 0; i < h.length; i++) dif |= h.charCodeAt(i) ^ v.hash.charCodeAt(i);
            return dif === 0;
        },
        olvidar: function () {
            _preparado = null;
            try { localStorage.removeItem(KEY); } catch (e) {}
        }
    };

    // Restos de la versión anterior (pendiente en localStorage). Se borran una vez.
    try { localStorage.removeItem(PENDING_LEGACY); } catch (e) {}

    // ── Comportamiento según la página ──────────────────────────────────────────
    // Todo lo de abajo es de la pantalla de LOGIN (prepara/confirma el verificador y
    // decide qué accesos se ofrecen). Otras pantallas cargan este archivo solo por la API
    // de arriba —cambiar la clave llama a olvidar()— y salen aquí. Por eso NO se carga en
    // el layout general: ahí no hay nada que hacer.
    const loginForm = document.getElementById('loginForm');
    if (!loginForm) return;

    // En el inicio de sesión no hay nadie dentro: la llave abierta de lo guardado se olvida.
    if (window.LlaveLocal) window.LlaveLocal.cerrar();

    // Página de LOGIN. Hay UN solo botón, «Iniciar sesión», y con qué se entra lo decide la
    // app: con servidor, el inicio de sesión normal; sin él (o si se cae a mitad del
    // intento), la comprobación en el equipo contra el verificador de la última sesión.
    // El usuario no elige: no hay un segundo botón «sin conexión».
    const btnBio = document.getElementById('btnBiometricLogin');
    const nota   = document.getElementById('loginSinRedNota');
    const idEl   = document.getElementById('login_identifier');
    const pwEl   = document.getElementById('password');

    // La huella la HABILITA el blade (cuando el dispositivo la soporta y hay credencial
    // registrada), pero quien la PINTA es refrescarAccesos(): firmar el challenge necesita
    // servidor, así que sin conexión el ícono no debe ni aparecer — antes salía y al
    // tocarlo solo podía dar "sin conexión".
    let huellaHabilitada = false;

    // ── ¿Hay SERVIDOR de verdad? ────────────────────────────────────────────────
    // navigator.onLine MIENTE: dice "online" con el wifi levantado y el servidor caído,
    // que es justo el caso que hay que cubrir. El resto de la app ya dejó de confiar en él
    // (comprobarConexion() en estado_red.js) y aquí se hace lo mismo: se sondea /up (la
    // ruta de salud de Laravel, sin sesión ni base de datos) y SOLO un fallo de red cuenta
    // como sin conexión — cualquier respuesta HTTP significa servidor vivo. El service
    // worker nunca cachea /up, así que el sondeo siempre va a la red.
    // Se arranca en `!navigator.onLine`: si el navegador ya se declara sin red, no hace
    // falta preguntar.
    let sinServidor = !navigator.onLine;
    // ¿Llegó a lanzarse el sondeo? El de la carga se salta cuando no hay nada que decidir;
    // si la huella se habilita después (llega por una promesa), ahí sí lo hay y se lanza.
    let sondeoLanzado = false;
    // ¿El sondeo cambia algo de lo que se ve? Solo si hay acceso local (la nota «sin
    // internet: entrarás con los datos guardados») o huella habilitada (que se oculta
    // cuando el servidor no responde). En la mayoría de las sesiones —equipo nuevo, o
    // quien nunca entró aquí— no hay nada que decidir, y sondear sería una petición de
    // más y un arranque de Laravel de más en CADA carga del login.
    function hayAlgoQueDecidir() {
        return window.OfflineAuth.tieneOffline() || huellaHabilitada;
    }

    // ¿Contesta el servidor? Con tope: sin él, una red que traga los paquetes sin contestar
    // dejaría la pantalla esperando para siempre.
    function hayServidor(ms) {
        if (!navigator.onLine) return Promise.resolve(false);
        const corta = (typeof AbortController === 'function') ? new AbortController() : null;
        const tope = corta ? setTimeout(function () { corta.abort(); }, ms) : null;
        return fetch('/up', {
            method: 'GET', cache: 'no-store', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: corta ? corta.signal : undefined
        })
            .then(function () { return true; }, function () { return false; })
            .finally(function () { if (tope) clearTimeout(tope); });
    }

    function sondearServidor() {
        if (!hayAlgoQueDecidir()) return;
        sondeoLanzado = true;
        hayServidor(4000).then(function (hay) { sinServidor = !hay; refrescarAccesos(); });
    }

    // Punto ÚNICO que decide qué se ve según haya servidor.
    function refrescarAccesos() {
        // Sin servidor y con acceso local: se avisa ANTES de pulsar con qué se va a entrar.
        if (nota) nota.style.display = (sinServidor && window.OfflineAuth.tieneOffline()) ? 'block' : 'none';
        // La huella SIEMPRE necesita servidor.
        if (btnBio) btnBio.style.display = (huellaHabilitada && !sinServidor) ? 'flex' : 'none';
        // Mientras no haya servidor hay que seguir preguntando: puede volver con el usuario
        // mirando esta pantalla. Va aquí porque este es el punto que YA conoce el estado.
        ajustarResondeo();
    }

    // ── Re-sondeo mientras no hay servidor ────────────────────────────────────
    // El evento 'online' NO basta: solo salta cuando el navegador pasa de "sin interfaz de
    // red" a "con interfaz", y el caso típico es el otro: wifi levantado y servidor
    // inalcanzable (router sin salida, portal cautivo, VPN, servidor caído). Ahí
    // navigator.onLine nunca fue false y al volver el servidor no se dispara nada.
    //
    // Coste: mientras no hay servidor las peticiones NI LLEGAN (fallan en red), o sea
    // que no arrancan Laravel; en cuanto una responde, sinServidor pasa a false y el
    // temporizador se apaga solo. Y no se sondea en segundo plano.
    const MS_RESONDEO = 15000;
    let resondeo = null;
    function ajustarResondeo() {
        if (!(sinServidor && hayAlgoQueDecidir())) {
            if (resondeo) { clearInterval(resondeo); resondeo = null; }
            return;
        }
        if (resondeo) return;                       // ya hay uno en marcha
        resondeo = setInterval(function () {
            if (document.hidden) return;            // en segundo plano no se gasta
            sondearServidor();
        }, MS_RESONDEO);
    }

    // Volver a la app (cambiar de pestaña, desbloquear el teléfono) es el momento con
    // más probabilidad de que la conexión haya cambiado: se pregunta ya.
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && sinServidor) sondearServidor();
    });

    // La llama el blade cuando ya sabe que el dispositivo tiene lector y credencial.
    window.OfflineAuth.habilitarHuella = function () {
        huellaHabilitada = true;
        refrescarAccesos();
        if (!sondeoLanzado) sondearServidor(); // ahora sí hay algo que decidir con el sondeo
    };
    // Lo contrario: la huella recuperada del servidor resultó ser de OTRO teléfono
    // (webauthn.js la descarta) y el ícono ya no tiene nada que firmar.
    window.OfflineAuth.deshabilitarHuella = function () {
        huellaHabilitada = false;
        refrescarAccesos();
    };
    // Los mensajes salen por window.mostrarMsgLogin (inicio_sesion.blade.php): es el
    // único sitio que escribe #offlineLoginMsg y además apaga el spinner y rehabilita
    // "Iniciar sesión".

    // Comodidad: precarga el correo de la última sesión local. Se hace en 'load'
    // (después del loginForm.reset() que corre en DOMContentLoaded) para que no se borre.
    window.addEventListener('load', function () {
        if (idEl && window.OfflineAuth.tieneOffline() && !idEl.value) {
            idEl.value = window.OfflineAuth.identidad();
            if (idEl.classList) idEl.classList.add('has-value'); // activa la etiqueta flotante
        }
        refrescarAccesos();
    });

    // ¿Se intenta el inicio de sesión normal? Lo pregunta el submit. Si la pantalla ya sabe
    // que no hay servidor, se vuelve a preguntar con algo más de paciencia: el sondeo pudo
    // fallar solo porque el servidor tardó (recién desplegado, red lenta), y entrar en local
    // teniendo servidor deja la sesión cerrada allá: la primera consulta devolvería al login.
    // Si la pantalla cree que sí hay, no se pregunta: el propio inicio de sesión lo descubre
    // (y si no llega, entrarSinServidor).
    window.OfflineAuth.conServidor = function () {
        if (!sinServidor) return Promise.resolve(true);
        return hayServidor(6000).then(function (hay) { sinServidor = !hay; refrescarAccesos(); return hay; });
    };

    // Un intento no llegó al servidor (el inicio de sesión o la huella): desde aquí cuenta
    // como sin servidor —sale la nota, se esconde la huella y se vuelve a sondear—.
    window.OfflineAuth.noContesto = function () {
        sinServidor = true;
        refrescarAccesos();
    };

    // No hay servidor (sin red, o el inicio de sesión normal no llegó): se entra con lo
    // guardado en el equipo si se puede. true = sigue la entrada local; false = en este
    // equipo no hay acceso local y quien llama avisa "sin conexión".
    window.OfflineAuth.entrarSinServidor = function () {
        window.OfflineAuth.noContesto();
        window.OfflineAuth.descartar();   // el verificador a medio hacer: nadie lo validó
        if (!window.OfflineAuth.tieneOffline()) return false;
        entrarLocal((idEl && idEl.value || '').trim(), (pwEl && pwEl.value || ''));
        return true;
    };

    // La comprobación en el equipo, sin servidor.
    async function entrarLocal(id, pw) {
        const ok = await window.OfflineAuth.verificar(id, pw).catch(function () { return false; });
        // La misma clave abre la copia cifrada que quedó en el equipo.
        if (ok && window.LlaveLocal) await window.LlaveLocal.abrirConClave(id, pw).catch(function () { return false; });
        if (ok) {
            // El menú omite el spinner general en esta primera carga tras login.
            if (window.marcarLoginReciente) window.marcarLoginReciente();
            window.location.href = tomarVolverA() || '/menu';
        } else {
            window.mostrarMsgLogin('Sin conexión, y el correo o la clave no coinciden con la última vez que se entró en este equipo.', true);
        }
    }

    // 'online' vuelve a SONDEAR (que haya red no significa que el servidor conteste);
    // 'offline' es concluyente, así que ahí basta con repintar.
    //
    // Estos dos eventos NO son suficientes por sí solos: solo saltan cuando cambia la
    // INTERFAZ de red, y el caso típico —wifi levantado y servidor inalcanzable— no la
    // cambia nunca. De la reconexión se encarga ajustarResondeo(); esto es solo el atajo
    // para cuando el evento sí llega. Repintar en 'offline' arma además ese re-sondeo:
    // mientras no hay red el tic no gasta nada, pero recupera el caso en que
    // navigator.onLine vuelve a true sin disparar 'online' (algunos teléfonos).
    window.addEventListener('online', sondearServidor);
    window.addEventListener('offline', function () { sinServidor = true; refrescarAccesos(); });
    // Un solo arranque: sondearServidor() ya pinta al resolverse. Si se salta por no haber
    // nada que decidir, tampoco hay que pintar: el blade ya trae la nota y la huella con
    // display:none, que es el estado de un equipo sin acceso local ni huella registrada.
    // (Cuando la huella sí existe, habilitarHuella() lanza el sondeo que faltaba.)
    sondearServidor();
})();
