/**
 * Vidalsa — Sincronización OFFLINE (Fase 1: consulta sin internet)
 * ------------------------------------------------------------------
 * Baja una COPIA de solo lectura de los datos del servidor (/offline/snapshot)
 * y la guarda en IndexedDB. Cuando no hay internet, los módulos leen de aquí en
 * vez de pedirle al servidor — así la página carga y muestra datos igual.
 *
 * NO toca nada del flujo online: solo descarga (GET) y guarda local. Si falla,
 * se queda con la última copia buena. El online sigue intacto.
 *
 * ¿CADA CUÁNTO se baja la copia?  (responde la pregunta del cliente)
 *   1) Al abrir la app CON internet: la PRIMERA vez (sin copia local) baja de una;
 *      las cargas siguientes, cuando el navegador esté ocioso (si hay datos nuevos).
 *   2) Cada CHECK_CADA_MS mientras haya internet, consultando primero /offline/version
 *      (barato): solo baja el snapshot completo si la versión cambió.
 *   3) Al volver la conexión (evento 'online').
 *   4) Manual: window.OfflineDB.sync(true) — para un botón "Actualizar datos".
 *
 * PRIORIDAD: la búsqueda del usuario manda. La bajada usa fetch priority:'low' y
 * los disparadores automáticos esperan inactividad (requestIdleCallback) para no
 * competir por red ni CPU con lo que el usuario esté haciendo.
 *
 * API pública (window.OfflineDB):
 *   .ready            Promise que resuelve cuando la BD local está abierta.
 *   .get(tabla)       -> Promise<Array> (stock, productos, movimientos, equipos,
 *                        movilizaciones, almacenes, frentes).
 *   .meta()           -> Promise<{version, generado, descargado}|null>.
 *   .sync(force)      -> descarga si hay versión nueva (o siempre si force=true).
 *   .estaListo()      -> Promise<bool> (¿hay copia local usable?).
 * Evento: window dispatch 'offline-datos-actualizados' tras cada descarga nueva.
 */
(function () {
    'use strict';

    const DB_NAME    = 'vidalsa_offline';
    const DB_VERSION = 1;
    const STORE      = 'kv'; // un solo object store clave→valor: 'meta','stock','productos',...
    const TABLAS     = ['almacenes', 'stock', 'productos', 'movimientos', 'equipos', 'movilizaciones', 'frentes'];
    const CHECK_CADA_MS = 10 * 60 * 1000; // revisar si hay datos nuevos cada 10 min (online)

    let dbPromise = null;

    function abrirDB() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = () => {
                const db = req.result;
                if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE);
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
        return dbPromise;
    }

    function tx(modo, fn) {
        return abrirDB().then((db) => new Promise((resolve, reject) => {
            const t = db.transaction(STORE, modo);
            const store = t.objectStore(STORE);
            let resultado;
            Promise.resolve(fn(store)).then((r) => { resultado = r; });
            t.oncomplete = () => resolve(resultado);
            t.onerror    = () => reject(t.error);
            t.onabort    = () => reject(t.error);
        }));
    }

    const idbGet = (clave) => tx('readonly', (s) => new Promise((res, rej) => {
        const r = s.get(clave); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error);
    }));
    const idbPut = (clave, valor) => tx('readwrite', (s) => { s.put(valor, clave); });

    // ── Descarga ────────────────────────────────────────────────────────────
    function fetchJson(url) {
        return fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin', // manda la cookie de sesión (la ruta es auth)
            priority: 'low',            // PRIORIDAD: que esta bajada NO le quite ancho de
                                        // banda a las búsquedas del usuario. El navegador
                                        // atiende primero las peticiones de prioridad normal.
        }).then((r) => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    let descargando = false;

    async function sync(force) {
        if (descargando) return false;
        if (!navigator.onLine) return false;
        descargando = true;
        try {
            const meta = await idbGet('meta');
            // Chequeo barato: ¿cambió la versión? Si no y no es forzado, no bajamos nada.
            if (!force && meta && meta.version) {
                try {
                    const v = await fetchJson('/offline/version');
                    if (v && v.version === meta.version) return false; // ya estamos al día
                } catch (_) { /* sin red: nada que hacer */ return false; }
            }

            const snap = await fetchJson('/offline/snapshot');
            if (!snap || !snap.version) return false;

            for (const tabla of TABLAS) {
                await idbPut(tabla, Array.isArray(snap[tabla]) ? snap[tabla] : []);
            }
            await idbPut('meta', {
                version:    snap.version,
                generado:   snap.generado || null,
                descargado: new Date().toISOString(),
            });

            window.dispatchEvent(new CustomEvent('offline-datos-actualizados', { detail: { version: snap.version } }));
            return true;
        } catch (e) {
            // Falla silenciosa: conservamos la última copia buena.
            if (window.console) console.warn('[offline] sync falló:', e && e.message);
            return false;
        } finally {
            descargando = false;
        }
    }

    // ── API pública ───────────────────────────────────────────────────────────
    window.OfflineDB = {
        ready: abrirDB().then(() => true).catch(() => false),
        get:   (tabla) => idbGet(tabla).then((v) => v || []),
        meta:  () => idbGet('meta').then((m) => m || null),
        sync:  (force) => sync(!!force),
        estaListo: () => idbGet('meta').then((m) => !!(m && m.version)),
    };

    // Ejecuta `fn` cuando el navegador esté OCIOSO (sin trabajo del usuario en
    // curso, ej. una búsqueda). Así el sync no compite por CPU/hilo principal con
    // lo que el usuario está haciendo. Fallback a setTimeout donde no exista la API.
    function enInactividad(fn, timeoutMs) {
        if (typeof requestIdleCallback === 'function') {
            requestIdleCallback(fn, { timeout: timeoutMs || 4000 });
        } else {
            setTimeout(fn, 300);
        }
    }

    // ── Disparadores automáticos ───────────────────────────────────────────────
    // Al cargar la app (con internet): asegura una copia fresca sin bloquear el render.
    // PRIMERA VEZ (recién iniciada la sesión, aún sin copia local) → baja de una
    // para que el offline quede listo cuanto antes. Cargas siguientes → cuando el
    // navegador esté ocioso. Siempre en segundo plano (sync es async/no-bloqueante,
    // la bajada usa priority:'low' y falla en silencio conservando la última copia).
    if (navigator.onLine) {
        idbGet('meta')
            .then((meta) => {
                const primeraVez = !meta || !meta.version;
                if (primeraVez) sync(false);
                else enInactividad(() => sync(false));
            })
            .catch(() => enInactividad(() => sync(false)));
    }
    // Revisión periódica mientras haya internet: SOLO cuando el navegador esté
    // ocioso, para no interrumpir una búsqueda que el usuario esté haciendo.
    setInterval(() => { if (navigator.onLine) enInactividad(() => sync(false)); }, CHECK_CADA_MS);
    // Al recuperar la conexión, intenta ponerse al día (también en inactividad).
    window.addEventListener('online', () => enInactividad(() => sync(false)));
})();
