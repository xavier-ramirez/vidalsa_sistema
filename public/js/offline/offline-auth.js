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
    const PENDING = 'vidalsa_offline_auth_pending';
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

    window.OfflineAuth = {
        disponible: disponible,
        tieneOffline: function () {
            try { return !!JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) { return false; }
        },
        identidad: function () {
            try { return (JSON.parse(localStorage.getItem(KEY) || 'null') || {}).identifier || ''; } catch (e) { return ''; }
        },
        // Guarda un verificador PENDIENTE (al enviar el login). Se "confirma" solo cuando
        // se llega a una página autenticada (el servidor aceptó esas credenciales).
        guardarPendiente: async function (identifier, password) {
            if (!disponible() || !identifier || !password) return;
            try {
                const salt = crypto.getRandomValues(new Uint8Array(16));
                const hash = await derivar(identifier, password, salt);
                localStorage.setItem(PENDING, JSON.stringify({
                    identifier: String(identifier).trim().toLowerCase(), salt: hex(salt), hash: hash
                }));
            } catch (e) { /* sin crypto seguro: no se habilita offline-login */ }
        },
        confirmarPendiente: function () {
            try {
                const p = localStorage.getItem(PENDING);
                if (p) { localStorage.setItem(KEY, p); localStorage.removeItem(PENDING); }
            } catch (e) {}
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
            try { localStorage.removeItem(KEY); localStorage.removeItem(PENDING); } catch (e) {}
        }
    };

    // ── Comportamiento según la página ──────────────────────────────────────────
    const loginForm = document.getElementById('loginForm');

    if (!loginForm) {
        // Página AUTENTICADA: si hay un pendiente, lo confirmamos (el servidor nos dejó pasar).
        window.OfflineAuth.confirmarPendiente();
        return;
    }

    // Página de LOGIN: gestiona el botón "Entrar sin conexión".
    const btnOff = document.getElementById('btnOfflineLogin');
    const btnOn  = document.getElementById('btnOnlineLogin');
    const idEl   = document.getElementById('login_identifier');
    const pwEl   = document.getElementById('password');
    const msgEl  = document.getElementById('offlineLoginMsg');

    // ── ¿Hay SERVIDOR de verdad? ────────────────────────────────────────────────
    // navigator.onLine MIENTE: dice "online" con el wifi levantado y el servidor caído,
    // que es justo el caso que este botón existe para cubrir. El resto de la app ya dejó
    // de confiar en él (comprobarConexion() en estructura_base) y aquí se hace lo mismo:
    // se sondea /offline/version y SOLO un fallo de red cuenta como sin conexión —
    // cualquier respuesta HTTP significa servidor vivo, incluida la redirección a /login
    // que devuelve por no haber sesión todavía (la ruta va detrás del middleware auth).
    // El service worker nunca cachea /offline/, así que el sondeo siempre va a la red.
    // Se arranca en `!navigator.onLine` para no ofrecer el botón antes de saber nada: si
    // el navegador ya se declara sin red, no hace falta preguntar.
    let sinServidor = !navigator.onLine;
    function sondearServidor() {
        if (!navigator.onLine) { sinServidor = true; refrescarBoton(); return; }
        // Tope de 4s: sin él, una red que traga los paquetes sin contestar dejaría el
        // botón de acceso local sin aparecer nunca.
        const corta = (typeof AbortController === 'function') ? new AbortController() : null;
        const tope = corta ? setTimeout(function () { corta.abort(); }, 4000) : null;
        fetch('/offline/version', {
            method: 'GET', cache: 'no-store', credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: corta ? corta.signal : undefined
        })
            .then(function () { sinServidor = false; }, function () { sinServidor = true; })
            .then(function () { if (tope) clearTimeout(tope); refrescarBoton(); });
    }

    function refrescarBoton() {
        if (!btnOff) return;
        // Solo se ofrece el acceso offline si NO hay servidor y ya hay credencial local.
        const mostrarOffline = sinServidor && window.OfflineAuth.tieneOffline();
        btnOff.style.display = mostrarOffline ? 'flex' : 'none';
        // En ese caso ocultamos "Iniciar sesión" (requiere servidor y fallaría sin
        // internet) para no mostrar DOS botones: offline → solo "Entrar sin conexión",
        // online (o sin credencial local) → solo "Iniciar sesión".
        if (btnOn) btnOn.style.display = mostrarOffline ? 'none' : '';
    }
    function mostrarMsg(t) { if (msgEl) { msgEl.textContent = t; msgEl.style.display = 'block'; } }

    // Comodidad: precarga el correo de la última sesión local. Se hace en 'load'
    // (después del loginForm.reset() que corre en DOMContentLoaded) para que no se borre.
    window.addEventListener('load', function () {
        if (idEl && window.OfflineAuth.tieneOffline() && !idEl.value) {
            idEl.value = window.OfflineAuth.identidad();
            if (idEl.classList) idEl.classList.add('has-value'); // activa la etiqueta flotante
        }
        refrescarBoton();
    });

    if (btnOff) {
        btnOff.addEventListener('click', async function () {
            const id = (idEl && idEl.value || '').trim();
            const pw = (pwEl && pwEl.value || '');
            if (!id || !pw) { mostrarMsg('Escribe tu correo y tu clave.'); return; }
            btnOff.disabled = true;
            const ok = await window.OfflineAuth.verificar(id, pw).catch(function () { return false; });
            btnOff.disabled = false;
            if (ok) {
                // El menú omite el spinner general en esta primera carga tras login.
                if (window.marcarLoginReciente) window.marcarLoginReciente();
                window.location.href = '/menu';
            }
            else { mostrarMsg('Correo o clave no coinciden con tu última sesión en este equipo.'); }
        });
    }

    // 'online' vuelve a SONDEAR (que haya red no significa que el servidor conteste);
    // 'offline' es concluyente, así que ahí basta con repintar.
    window.addEventListener('online', sondearServidor);
    window.addEventListener('offline', function () { sinServidor = true; refrescarBoton(); });
    // Un solo arranque: sondearServidor() ya pinta en las dos ramas — sin red llama a
    // refrescarBoton() de inmediato, y con red la llama al resolverse el sondeo. Mientras
    // tanto no hay hueco: el blade ya renderiza "Iniciar sesión" visible y "Entrar sin
    // conexión" con display:none, que es exactamente el estado con internet.
    sondearServidor();
})();
