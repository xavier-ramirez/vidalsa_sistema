/**
 * WebAuthn (huella/biometría) para Vidalsa PWA.
 * Registro: tras login con contraseña, pregunta si activar huella.
 * Login: desde la pantalla de inicio, autenticación biométrica sin contraseña.
 */
const VidalsaWebAuthn = (() => {
    const STORAGE_KEY = 'vidalsa_webauthn_creds';

    function soportado() {
        return !!(window.PublicKeyCredential && navigator.credentials);
    }

    async function plataformaDisponible() {
        if (!soportado()) return false;
        try {
            return await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
        } catch { return false; }
    }

    function getCredIds() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]');
        } catch { return []; }
    }

    function saveCredId(id) {
        const ids = getCredIds();
        if (!ids.includes(id)) ids.push(id);
        localStorage.setItem(STORAGE_KEY, JSON.stringify(ids));
    }

    function base64UrlToBytes(b64) {
        const padded = b64.replace(/-/g, '+').replace(/_/g, '/');
        const bin = atob(padded);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return bytes;
    }

    function bytesToBase64(bytes) {
        let bin = '';
        const u8 = new Uint8Array(bytes);
        for (let i = 0; i < u8.length; i++) bin += String.fromCharCode(u8[i]);
        return btoa(bin);
    }

    function getCsrf() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Cabeceras que identifican la petición como AJAX/JSON. Sin esto, cuando el
    // servidor lanza una excepción (sesión/auth/CSRF caducada) sus handlers
    // (bootstrap/app.php) calculan wantsJson=false y devuelven un REDIRECT a /login
    // (HTML), no JSON. fetch sigue el redirect y res.json() revienta con el críptico
    // "Unexpected token '<', <!DOCTYPE...". Con estas cabeceras el servidor responde
    // JSON con su status real (401/419) y el flujo de recarga de abajo lo maneja.
    const JSON_HEADERS = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    // ¿La respuesta es realmente JSON? Un redirect a /login o una página de error
    // vienen como text/html y NO se deben pasar a res.json().
    function esJson(res) {
        return (res.headers.get('content-type') || '').includes('application/json');
    }

    // Una respuesta redirigida, 419/422 o no-JSON = sesión/CSRF caducada: el servidor
    // sirvió el HTML del login (o un redirect a él) en vez de JSON. Es un fallo
    // TRANSITORIO, no un error del usuario: recargamos para que el siguiente intento
    // tenga sesión y challenge frescos, en vez de mostrar el error de JSON.parse
    // sobre HTML ("Unexpected token '<'"). Los errores reales (401/403/429) vienen
    // como JSON y NO entran aquí: caen abajo y se muestran al usuario.
    function esRespuestaDeSesion(res) {
        return res.redirected || res.status === 419 || res.status === 422 || !esJson(res);
    }

    // ─── REGISTRO ────────────────────────────────────────────────────

    async function registrar() {
        if (!(await plataformaDisponible())) {
            alert('Este dispositivo no soporta autenticación biométrica.');
            return false;
        }

        let options;
        try {
            const res = await fetch('/webauthn/register-options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf(), ...JSON_HEADERS },
            });
            if (esRespuestaDeSesion(res)) { window.location.reload(); return false; }
            options = await res.json();
            if (!res.ok) throw new Error(options.error || 'Error obteniendo opciones');
        } catch (e) {
            alert('Error al preparar el registro biométrico: ' + e.message);
            return false;
        }

        const publicKey = {
            challenge:        base64UrlToBytes(options.challenge),
            rp:               options.rp,
            user: {
                id:          base64UrlToBytes(options.user.id),
                name:        options.user.name,
                displayName: options.user.displayName,
            },
            pubKeyCredParams:       options.pubKeyCredParams,
            timeout:                options.timeout,
            authenticatorSelection: options.authenticatorSelection,
            attestation:            options.attestation,
        };

        let credential;
        try {
            credential = await navigator.credentials.create({ publicKey });
        } catch {
            return false;
        }

        const spkiKey = credential.response.getPublicKey();
        if (!spkiKey) {
            alert('El navegador no soporta getPublicKey(). Actualice el navegador.');
            return false;
        }

        const body = {
            credential_id:      bytesToBase64(new Uint8Array(credential.rawId)),
            public_key_spki:    bytesToBase64(new Uint8Array(spkiKey)),
            nombre_dispositivo: detectarDispositivo(),
        };

        try {
            const res = await fetch('/webauthn/register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf(), ...JSON_HEADERS },
                body: JSON.stringify(body),
            });
            if (esRespuestaDeSesion(res)) { window.location.reload(); return false; }
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Error al registrar');
            saveCredId(body.credential_id);
            return true;
        } catch (e) {
            alert('Error al guardar la huella: ' + e.message);
            return false;
        }
    }

    // ─── AUTENTICACIÓN ───────────────────────────────────────────────

    // publicKey pre-cargado (challenge) para abrir el lector de huella SIN el
    // "cargando" del fetch de opciones. Single-use: el challenge se consume en cada
    // intento. Ver precargarOpciones() / autenticar().
    let _precachedPublicKey = null;

    // Pide /webauthn/login-options y arma el objeto publicKey listo para
    // navigator.credentials.get(). Devuelve null si la sesión caducó (recarga en curso).
    async function _obtenerPublicKey() {
        const credIds = getCredIds();
        if (!credIds.length) throw new Error('NO_CREDENTIALS');

        const resOpt = await fetch('/webauthn/login-options', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...JSON_HEADERS },
            body: JSON.stringify({ credential_ids: credIds }),
        });
        // Sesión/CSRF caducada o HTML del login servido en vez de JSON → recargar.
        // OJO: se comprueba ANTES de res.json() para no parsear "<!DOCTYPE...".
        if (esRespuestaDeSesion(resOpt)) { window.location.reload(); return null; }
        if (resOpt.status === 404) {
            localStorage.removeItem(STORAGE_KEY);
            throw new Error('NO_CREDENTIALS');
        }
        const options = await resOpt.json();
        if (!resOpt.ok) throw new Error(options.error || 'Error obteniendo opciones');

        return {
            challenge:        base64UrlToBytes(options.challenge),
            timeout:          options.timeout,
            rpId:             options.rpId,
            allowCredentials: options.allowCredentials.map(c => ({ type: c.type, id: base64UrlToBytes(c.id) })),
            userVerification: options.userVerification,
        };
    }

    // Pre-carga el challenge ANTES de tocar el botón. Al pulsar, autenticar() usa lo
    // pre-cargado y llama a navigator.credentials.get() de inmediato (sin esperar la
    // red): el lector de huella se abre al instante. Silencioso ante errores.
    async function precargarOpciones() {
        try { _precachedPublicKey = await _obtenerPublicKey(); }
        catch { _precachedPublicKey = null; }
    }

    async function autenticar() {
        // Usa el challenge pre-cargado si existe (huella instantánea); si no, lo pide ahora.
        let publicKey = _precachedPublicKey;
        _precachedPublicKey = null; // single-use: el challenge se consume
        if (!publicKey) {
            publicKey = await _obtenerPublicKey();
            if (!publicKey) return; // sesión caducada → recargando
        }

        let assertion;
        try {
            assertion = await navigator.credentials.get({ publicKey });
        } catch {
            throw new Error('USER_CANCELLED');
        }

        const body = {
            credential_id:      bytesToBase64(new Uint8Array(assertion.rawId)),
            authenticator_data: bytesToBase64(new Uint8Array(assertion.response.authenticatorData)),
            client_data_json:   bytesToBase64(new Uint8Array(assertion.response.clientDataJSON)),
            signature:          bytesToBase64(new Uint8Array(assertion.response.signature)),
        };

        const res = await fetch('/webauthn/login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', ...JSON_HEADERS },
            body: JSON.stringify(body),
        });
        // 419 (CSRF/sesión), 422 (challenge expirado: la sesión perdió el desafío
        // entre login-options y login, p.ej. tras los 20 min de SESSION_LIFETIME) y
        // una respuesta redirigida/no-JSON (HTML del login servido en vez de JSON)
        // son fallos TRANSITORIOS de sesión, no errores del usuario. En vez de
        // mostrar el críptico "Unexpected token '<'" del JSON.parse sobre el HTML,
        // recargamos para que el siguiente toque genere un challenge fresco. Los
        // errores reales (401/403/429) sí caen abajo y se muestran.
        if (esRespuestaDeSesion(res)) { window.location.reload(); return; }
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Error de autenticación');
        return data;
    }

    function tieneCredenciales() {
        return getCredIds().length > 0;
    }

    function detectarDispositivo() {
        const ua = navigator.userAgent;
        if (/iPhone/i.test(ua)) return 'iPhone';
        if (/iPad/i.test(ua))   return 'iPad';
        if (/Android/i.test(ua)) {
            const match = ua.match(/;\s*([^;)]+)\s*Build/);
            return match ? match[1].trim() : 'Android';
        }
        return 'Dispositivo';
    }

    function limpiarCredenciales() {
        localStorage.removeItem(STORAGE_KEY);
    }

    return { soportado, plataformaDisponible, registrar, autenticar, precargarOpciones, tieneCredenciales, limpiarCredenciales };
})();
