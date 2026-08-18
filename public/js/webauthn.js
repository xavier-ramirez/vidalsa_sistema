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

    // El CSRF y las cabeceras de AJAX/JSON las pone window.apiFetch (dom_helpers.js).

    // Texto del error, venga con la clave que venga. El servidor no es uniforme: estas
    // rutas responden con `error`, pero los handlers globales de bootstrap/app.php y el
    // middleware de sesión usan `message`. Leer solo una era ver "Error de autenticación"
    // genérico en vez del motivo real; y renombrar en el servidor rompería el contrato
    // del endpoint móvil, que ya está publicado.
    function textoError(datos, porDefecto) {
        return (datos && (datos.error || datos.message)) || porDefecto;
    }

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
            const res = await window.apiFetch('/webauthn/register-options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' }
            });
            if (esRespuestaDeSesion(res)) { window.location.reload(); return false; }
            options = await res.json();
            if (!res.ok) throw new Error(textoError(options, 'Error obteniendo opciones'));
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
                displayName: options.user.displayName
            },
            pubKeyCredParams:       options.pubKeyCredParams,
            timeout:                options.timeout,
            authenticatorSelection: options.authenticatorSelection,
            attestation:            options.attestation
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
            nombre_dispositivo: detectarDispositivo()
        };

        try {
            const res = await window.apiFetch('/webauthn/register', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            if (esRespuestaDeSesion(res)) { window.location.reload(); return false; }
            const data = await res.json();
            if (!res.ok) throw new Error(textoError(data, 'Error al registrar'));
            saveCredId(body.credential_id);
            return true;
        } catch (e) {
            alert('Error al guardar la huella: ' + e.message);
            return false;
        }
    }

    // ─── AUTENTICACIÓN ───────────────────────────────────────────────

    // publicKey pre-cargado (challenge) para abrir el lector de huella SIN esperar
    // la red al tocar el botón. El servidor solo consume el challenge cuando llega
    // POST /webauthn/login (pull() en WebAuthnController::login): una cancelación
    // del lector lo deja válido, así que el precache se CONSERVA hasta consumirse
    // o reemplazarse por un refresh. Ver iniciarPrecarga() / autenticar().
    let _precachedPublicKey = null;
    let _precachedAt = 0;        // timestamp del último prefetch exitoso
    let _prefetchEnCurso = null; // promesa del fetch en vuelo (deduplica llamadas)
    let _precargaIniciada = false;
    // Pausa los refreshes mientras el lector está abierto: un refresh reemplazaría
    // en la sesión el challenge que el usuario está firmando en ese momento.
    let _autenticando = false;

    // Vigencia del precache: el servidor anuncia el TTL del challenge en
    // expires_in (WebAuthnController::loginOptions) y aquí se descuenta un margen
    // para no firmar uno a punto de vencer. El fallback aplica si el servidor
    // aún no manda expires_in.
    const PRECACHE_MARGEN_MS   = 2 * 60 * 1000;
    const PRECACHE_FALLBACK_MS = 8 * 60 * 1000;
    let _precacheMaxMs = PRECACHE_FALLBACK_MS;
    // Muy por debajo de cualquier SESSION_LIFETIME razonable: cada refresh además
    // mantiene viva la sesión guest que guarda el challenge.
    const REFRESH_MS       = 4 * 60 * 1000;
    const FETCH_TIMEOUT_MS = 6000;  // /webauthn/login-options
    const LOGIN_TIMEOUT_MS = 10000; // /webauthn/login

    // fetch con límite de tiempo: en redes malas evita esperas indefinidas. El
    // llamador convierte el abort/fallo de red en 'SIN_CONEXION'.
    function _fetchConTimeout(url, opts, ms) {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), ms);
        return window.apiFetch(url, { ...opts, signal: ctrl.signal }).finally(() => clearTimeout(timer));
    }

    // Pide /webauthn/login-options y arma el objeto publicKey listo para
    // navigator.credentials.get(). Devuelve null si la sesión caducó (recarga en curso).
    //
    // `silencioso` = la llamada viene de la precarga de fondo, no de que el usuario
    // tocara el botón. En ese caso NUNCA se borran las credenciales: un fallo del que
    // el usuario ni se entera no puede dejarlo sin huella (ver el 404 más abajo).
    async function _obtenerPublicKey(silencioso) {
        const credIds = getCredIds();
        if (!credIds.length) throw new Error('NO_CREDENTIALS');

        let resOpt;
        try {
            resOpt = await _fetchConTimeout('/webauthn/login-options', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ credential_ids: credIds })
            }, FETCH_TIMEOUT_MS);
        } catch {
            throw new Error('SIN_CONEXION');
        }
        // Sesión/CSRF caducada o HTML del login servido en vez de JSON → recargar.
        // OJO: se comprueba ANTES de res.json() para no parsear "<!DOCTYPE...".
        if (esRespuestaDeSesion(resOpt)) { window.location.reload(); return null; }
        if (resOpt.status === 404) {
            // Borrar aquí es IRREVERSIBLE: deja al usuario sin huella y obligado a
            // registrarla de cero. Así que solo se borra cuando se cumplen las DOS cosas:
            //   1) el intento lo pidió el usuario (no la precarga de fondo), y
            //   2) el servidor marcó explícitamente `code: 'sin_credenciales'`.
            // Sin la marca, un 404 puede ser pasajero — rutas recacheándose en pleno
            // despliegue, el móvil pegándole a otro host, o un proxy metiendo su página
            // de error — y eso borraba la huella de TODOS con cada actualización.
            let esBorradoReal = false;
            if (!silencioso) {
                try {
                    esBorradoReal = (await resOpt.clone().json()).code === 'sin_credenciales';
                } catch { esBorradoReal = false; }
            }
            if (esBorradoReal) localStorage.removeItem(STORAGE_KEY);
            throw new Error('NO_CREDENTIALS');
        }
        const options = await resOpt.json();
        if (!resOpt.ok) throw new Error(textoError(options, 'Error obteniendo opciones'));

        // Derivar la vigencia del TTL real del servidor (fuente única de verdad).
        if (typeof options.expires_in === 'number' && options.expires_in > 0) {
            _precacheMaxMs = Math.max(options.expires_in * 1000 - PRECACHE_MARGEN_MS, 60000);
        }

        return {
            challenge:        base64UrlToBytes(options.challenge),
            timeout:          options.timeout,
            rpId:             options.rpId,
            allowCredentials: options.allowCredentials.map(c => ({ type: c.type, id: base64UrlToBytes(c.id) })),
            userVerification: options.userVerification
        };
    }

    // Pre-carga/refresca el challenge. Deduplica: si ya hay un fetch en vuelo,
    // devuelve esa misma promesa (autenticar() la espera en vez de duplicar).
    // Sin retry propio: los fallos transitorios los curan el intervalo de
    // REFRESH_MS, el evento 'online', el visibilitychange y, en último caso,
    // el fetch de respaldo al tocar el botón.
    function _precargar(silencioso) {
        if (_prefetchEnCurso) return _prefetchEnCurso;
        _prefetchEnCurso = _obtenerPublicKey(silencioso)
            .then(pk => {
                if (pk) {
                    _precachedPublicKey = pk;
                    _precachedAt = Date.now();
                }
                return pk;
            })
            .finally(() => { _prefetchEnCurso = null; });
        return _prefetchEnCurso;
    }

    // Variante para llamadas en segundo plano (intervalo/eventos): nunca revienta.
    // No corre con el lector abierto NI con la pestaña oculta — el servidor guarda
    // UN solo challenge por sesión, y un refresh desde una pestaña de fondo
    // pisaría el que otra pestaña visible está por firmar.
    function _precargarSilencioso() {
        if (_autenticando || document.hidden) return;
        // silencioso=true: este fallo se traga con el catch de abajo, así que no puede
        // tener efectos destructivos. Es la llamada que borraba la huella en cada
        // despliegue sin que el usuario tocara nada.
        _precargar(true).catch(() => {});
    }

    function _precacheVigente() {
        return !!_precachedPublicKey && (Date.now() - _precachedAt) < _precacheMaxMs;
    }

    // Punto de entrada del LOGIN: prefetch inmediato + refresh periódico +
    // re-prefetch al recuperar conexión o volver a primer plano (justo cuando el
    // usuario va a tocar el botón). Idempotente. El layout (estructura_base)
    // también carga este módulo para el REGISTRO de huella y NO debe llamar esto:
    // solo lo invoca inicio_sesion.blade.php.
    function iniciarPrecarga() {
        if (_precargaIniciada) return;
        _precargaIniciada = true;

        _precargarSilencioso();
        setInterval(_precargarSilencioso, REFRESH_MS);
        window.addEventListener('online', _precargarSilencioso);
        document.addEventListener('visibilitychange', () => {
            // Al volver a primer plano (el intervalo no corre oculto), re-primar
            // solo si ya tocaría — sin esto cada alt-tab dispararía un POST
            // aunque el challenge precargado siga vigente.
            if (document.visibilityState === 'visible' && Date.now() - _precachedAt > REFRESH_MS) {
                _precargarSilencioso();
            }
        });
    }

    async function autenticar() {
        _autenticando = true;
        try {
            // Un refresh EN VUELO ya está reemplazando el challenge de la sesión
            // en el servidor: firmar el precache viejo garantizaría "Challenge no
            // coincide" + hit del rate-limiter. Se espera ese resultado (acotado
            // por FETCH_TIMEOUT_MS) y se firma el challenge vigente.
            let publicKey = null;
            if (_prefetchEnCurso) {
                publicKey = await _prefetchEnCurso.catch(() => null);
            }
            // Usa el challenge pre-cargado (lector instantáneo). NO se anula aquí:
            // el servidor solo lo consume en POST /webauthn/login, así que una
            // cancelación del lector lo deja listo para el siguiente toque.
            if (!publicKey) publicKey = _precacheVigente() ? _precachedPublicKey : null;
            if (!publicKey) {
                // Solo si los refreshes llevan rato fallando o nunca corrieron.
                if (!navigator.onLine) throw new Error('SIN_CONEXION'); // fallo rápido, sin colgarse
                publicKey = await _precargar(); // acotado por FETCH_TIMEOUT_MS
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
                signature:          bytesToBase64(new Uint8Array(assertion.response.signature))
            };

            _precachedPublicKey = null;
            _precachedAt = 0;

            let res;
            try {
                res = await _fetchConTimeout('/webauthn/login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                }, LOGIN_TIMEOUT_MS);
            } catch {
                // Timeout o bajón de red con el POST ya despachado: el botón no
                // debe quedarse en "Verificando...".
                throw new Error('SIN_CONEXION');
            }
            // 419 (CSRF/sesión), 422 (challenge expirado o vencido por el TTL de
            // 10 min del servidor) y una respuesta redirigida/no-JSON (HTML del
            // login servido en vez de JSON) son fallos TRANSITORIOS de sesión, no
            // errores del usuario. En vez de mostrar el críptico "Unexpected
            // token '<'" del JSON.parse sobre el HTML, recargamos para que el
            // siguiente toque genere un challenge fresco. Los errores reales
            // (401/403/429) sí caen abajo y se muestran.
            if (esRespuestaDeSesion(res)) { window.location.reload(); return; }
            const data = await res.json();
            if (!res.ok) throw new Error(textoError(data, 'Error de autenticación'));
            return data;
        } finally {
            _autenticando = false;
            // Re-primar si el challenge quedó consumido/vencido (el POST de login
            // lo consume en el servidor; una cancelación del lector lo deja
            // intacto y no dispara nada). Tras un login EXITOSO la página
            // redirige y este POST extra se descarta — costo asumido a cambio
            // de no mantener un flag más.
            if (!_precacheVigente()) _precargarSilencioso();
        }
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

    return { soportado, plataformaDisponible, registrar, autenticar, iniciarPrecarga, tieneCredenciales, limpiarCredenciales };
})();
