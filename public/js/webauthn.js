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
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf() },
            });
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
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrf() },
                body: JSON.stringify(body),
            });
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

    async function autenticar() {
        const credIds = getCredIds();
        if (!credIds.length) throw new Error('NO_CREDENTIALS');

        let options;
        try {
            const res = await fetch('/webauthn/login-options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ credential_ids: credIds }),
            });
            options = await res.json();
            if (!res.ok) {
                if (res.status === 419) {
                    window.location.reload();
                    return;
                }
                if (res.status === 404) {
                    localStorage.removeItem(STORAGE_KEY);
                    throw new Error('NO_CREDENTIALS');
                }
                throw new Error(options.error || 'Error obteniendo opciones');
            }
        } catch (e) {
            throw e;
        }

        const allowCredentials = options.allowCredentials.map(c => ({
            type: c.type,
            id:   base64UrlToBytes(c.id),
        }));

        const publicKey = {
            challenge:        base64UrlToBytes(options.challenge),
            timeout:          options.timeout,
            rpId:             options.rpId,
            allowCredentials: allowCredentials,
            userVerification: options.userVerification,
        };

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
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        // 419 (CSRF/sesión) y 422 (challenge expirado: la sesión perdió el desafío
        // entre login-options y login, p.ej. tras los 20 min de SESSION_LIFETIME)
        // son fallos TRANSITORIOS de sesión, no errores del usuario. En vez de
        // mostrar un mensaje amarillo de "token/challenge vencido", recargamos para
        // que el siguiente toque genere un challenge fresco. Los errores reales
        // (401/403/429) sí caen abajo y se muestran.
        if (res.status === 419 || res.status === 422) { window.location.reload(); return; }
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

    return { soportado, plataformaDisponible, registrar, autenticar, tieneCredenciales, limpiarCredenciales };
})();
