/**
 * Llave de lo guardado en el equipo — window.LlaveLocal.
 *
 * La copia de los datos (copia-local.js) y la bandeja (bandeja.js) se guardan CIFRADAS
 * (AES-GCM 256): con el equipo en la mano, sin entrar al sistema, no se pueden leer.
 *
 * La llave de los datos es aleatoria, una por usuario y equipo, y se guarda ENVUELTA dos
 * veces en localStorage ('vidalsa_llaves'):
 *   · srv: con la llave que da el servidor solo con sesión abierta (GET /sync/llave). Con
 *     internet abre siempre, se haya entrado con clave o con huella.
 *   · pwd: con una llave sacada de la clave del usuario (PBKDF2). Es la que abre la copia
 *     SIN internet, en "Entrar sin conexión". Se rehace en cada inicio de sesión con clave.
 * Abierta, vive solo en sessionStorage (esta ventana): se borra al cerrar la app o el
 * navegador y al volver a la pantalla de inicio de sesión.
 *
 *   LlaveLocal.lista() → Promise<boolean>. Abre la llave (pidiéndola al servidor si hace falta).
 *   LlaveLocal.cifrar(valor) → Promise<{ iv, ct }> · descifrar({ iv, ct }) → Promise<valor>
 *   LlaveLocal.cifrarBytes(ArrayBuffer) / descifrarBytes({ iv, ct }) → para los archivos.
 *   LlaveLocal.preparar(id, clave) / confirmar() / descartar() → inicio de sesión con clave
 *       (los llama offline-auth.js, igual que su verificador).
 *   LlaveLocal.abrirConClave(id, clave) → Promise<boolean>. "Entrar sin conexión".
 *   LlaveLocal.cerrar() → olvida la llave abierta (pantalla de inicio de sesión).
 */
(function () {
    'use strict';
    if (window.LlaveLocal) return;

    const LLAVES = 'vidalsa_llaves';
    const SESION = 'vidalsa_llave_sesion';
    const PENDIENTE = 'vidalsa_llave_pendiente';
    const ITER = 120000; // el mismo costo que el verificador de offline-auth.js
    const sub = window.crypto && window.crypto.subtle;
    const usuario = () => (document.querySelector('meta[name="usuario-id"]') || {}).content || '';

    const b64 = (buf) => btoa(String.fromCharCode.apply(null, new Uint8Array(buf)));
    const deB64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
    const leer = (almacen, k) => { try { return JSON.parse(almacen.getItem(k) || 'null'); } catch (e) { return null; } };
    const escribir = (almacen, k, v) => { try { if (v == null) almacen.removeItem(k); else almacen.setItem(k, JSON.stringify(v)); } catch (e) {} };
    const aes = (raw) => sub.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
    // Dos ventanas abriendo a la vez la PRIMERA llave crearían dos distintas: una sola a la vez.
    const unaALaVez = (fn) => (navigator.locks ? navigator.locks.request('vidalsa-llave', fn) : fn());

    async function envolver(kek, dek) {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        return { iv: b64(iv), ct: b64(await sub.encrypt({ name: 'AES-GCM', iv }, await aes(kek), dek)) };
    }
    async function desenvolver(kek, w) {
        return new Uint8Array(await sub.decrypt({ name: 'AES-GCM', iv: deB64(w.iv) }, await aes(kek), deB64(w.ct)));
    }
    async function kekDeClave(id, clave, salt) {
        const material = await sub.importKey('raw', new TextEncoder().encode(id + ':' + String(clave)), { name: 'PBKDF2' }, false, ['deriveBits']);
        return new Uint8Array(await sub.deriveBits({ name: 'PBKDF2', salt, iterations: ITER, hash: 'SHA-256' }, material, 256));
    }
    const identidad = (id) => String(id || '').trim().toLowerCase();

    // ── Abrir ────────────────────────────────────────────────────────────────
    let llave = null;      // CryptoKey abierta en esta página
    let abriendo = null;

    async function abrir() {
        const u = usuario();
        if (!sub || !u) return false;
        const s = leer(sessionStorage, SESION);
        let dek = s && s.u === u ? deB64(s.k) : null;
        const pend = leer(sessionStorage, PENDIENTE);
        if (!dek || pend) {
            // Con internet: la del servidor abre la guardada (o se crea la primera vez) y, si se
            // acaba de entrar con clave, se envuelve también con ella para poder entrar sin red.
            let r = null;
            try {
                r = await (window.fetchSinInterceptor || window.fetch)('/sync/llave', {
                    credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
                });
            } catch (e) { r = null; }
            if (r && r.ok) {
                const srv = deB64((await r.json()).llave);
                dek = await unaALaVez(async () => {
                    const todas = leer(localStorage, LLAVES) || {};
                    const mia = todas[u] || {};
                    if (!dek && mia.srv) { try { dek = await desenvolver(srv, mia.srv); } catch (e) { dek = null; } }
                    // La del servidor ya no abre (cambió APP_KEY): la recupera la clave recién escrita.
                    if (!dek && pend && pend.dek && mia.pwd && mia.pwd.id === pend.id) dek = deB64(pend.dek);
                    if (!dek) {
                        dek = crypto.getRandomValues(new Uint8Array(32));
                        delete mia.pwd;   // envolvía otra llave: ya no abre nada
                    }
                    mia.srv = await envolver(srv, dek);
                    if (pend) mia.pwd = Object.assign({ id: pend.id, salt: pend.salt }, await envolver(deB64(pend.kek), dek));
                    todas[u] = mia;
                    escribir(localStorage, LLAVES, todas);
                    escribir(sessionStorage, PENDIENTE, null);
                    return dek;
                });
            }
        }
        if (!dek) return false;
        escribir(sessionStorage, SESION, { u, k: b64(dek) });
        llave = await aes(dek);
        return true;
    }

    function lista() {
        if (llave) return Promise.resolve(true);
        if (!abriendo) abriendo = abrir().catch(() => false).then((ok) => { abriendo = null; return ok; });
        return abriendo;
    }

    // ── Cifrar ───────────────────────────────────────────────────────────────
    async function cifrarBytes(buf) {
        if (!(await lista())) throw new Error('Sin llave');
        const iv = crypto.getRandomValues(new Uint8Array(12));
        return { iv, ct: await sub.encrypt({ name: 'AES-GCM', iv }, llave, buf) };
    }
    async function descifrarBytes(c) {
        if (!(await lista())) throw new Error('Sin llave');
        return sub.decrypt({ name: 'AES-GCM', iv: c.iv }, llave, c.ct);
    }

    // ── Inicio de sesión con clave ───────────────────────────────────────────
    let preparada = null;   // Promise<{ id, salt, kek }> mientras va el intento de login

    window.LlaveLocal = {
        lista,
        cifrarBytes,
        descifrarBytes,
        cifrar: (valor) => cifrarBytes(new TextEncoder().encode(JSON.stringify(valor))),
        descifrar: (c) => descifrarBytes(c).then((buf) => JSON.parse(new TextDecoder().decode(buf))),
        // Se calcula mientras va la petición, como el verificador: no añade espera. También
        // abre, si la hay, la llave que esta clave ya envolvía en este equipo (ver abrir()).
        preparar(id, clave) {
            if (!sub || !id || !clave) { preparada = null; return; }
            const ident = identidad(id);
            const salt = crypto.getRandomValues(new Uint8Array(16));
            const todas = leer(localStorage, LLAVES) || {};
            const previa = Object.keys(todas).map((u) => todas[u] && todas[u].pwd).find((w) => w && w.id === ident);
            preparada = Promise.all([
                kekDeClave(ident, clave, salt),
                previa ? kekDeClave(ident, clave, deB64(previa.salt)).then((k) => desenvolver(k, previa)).catch(() => null) : null,
            ])
                .then(([kek, dek]) => ({ id: ident, salt: b64(salt), kek: b64(kek), dek: dek ? b64(dek) : null }))
                .catch(() => null);
        },
        // El servidor aceptó la clave: se envuelve la llave también con ella. La abre la página
        // siguiente o, si ya está abierta (se volvió a entrar sin salir: sesion_caida.js), ahora.
        confirmar() {
            const p = preparada;
            preparada = null;
            return p ? p.then((v) => {
                if (!v) return;
                escribir(sessionStorage, PENDIENTE, v);
                if (llave) return abrir().catch(() => false);
            }) : Promise.resolve();
        },
        descartar() { preparada = null; },
        async abrirConClave(id, clave) {
            if (!sub) return false;
            const ident = identidad(id);
            const todas = leer(localStorage, LLAVES) || {};
            for (const u of Object.keys(todas)) {
                const w = todas[u] && todas[u].pwd;
                if (!w || w.id !== ident) continue;
                try {
                    const dek = await desenvolver(await kekDeClave(ident, clave, deB64(w.salt)), w);
                    escribir(sessionStorage, SESION, { u, k: b64(dek) });
                    return true;
                } catch (e) { /* otra clave: no abre */ }
            }
            return false;
        },
        cerrar() {
            llave = null;
            escribir(sessionStorage, SESION, null);
            escribir(sessionStorage, PENDIENTE, null);
        },
    };

    // Salir del sistema (los botones de la cabecera y del menú del teléfono): la llave se
    // olvida YA. La pantalla de inicio que venga después puede ser una copia vieja del service
    // worker que todavía no sepa cerrarla. Las páginas guardadas se quedan: son para entrar
    // sin internet, y se tiran si entra otra persona (ver DUENO en sw.js).
    document.addEventListener('submit', (e) => {
        const f = e.target;
        try {
            if (!f || !f.action || new URL(f.action, location.href).pathname !== '/logout') return;
            window.LlaveLocal.cerrar();
        } catch (err) {}
    }, true);
})();
