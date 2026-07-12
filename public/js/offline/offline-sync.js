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
    const DB_VERSION = 2; // v2: añade el store 'outbox' (Fase 2: escritura offline)
    const STORE      = 'kv';      // object store clave→valor: 'meta','stock','productos',...
    const OUTBOX     = 'outbox';  // cola de acciones hechas sin internet (keyPath: client_uuid)
    const TABLAS     = ['almacenes', 'stock', 'productos', 'movimientos', 'equipos', 'movilizaciones', 'frentes'];
    const CHECK_CADA_MS = 10 * 60 * 1000; // revisar si hay datos nuevos cada 10 min (online)

    let dbPromise = null;

    function abrirDB() {
        if (dbPromise) return dbPromise;
        dbPromise = new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onupgradeneeded = (ev) => {
                const db = req.result;
                // 'kv' (v1): NO se toca al subir de versión → la copia local se conserva.
                if (!db.objectStoreNames.contains(STORE)) db.createObjectStore(STORE);
                // 'outbox' (v2): cola de escritura offline. keyPath = client_uuid (1 registro
                // por acción/lote). Índices para listar por estado y por orden de creación.
                if (ev.oldVersion < 2 && !db.objectStoreNames.contains(OUTBOX)) {
                    const os = db.createObjectStore(OUTBOX, { keyPath: 'client_uuid' });
                    os.createIndex('status', 'status', { unique: false });
                    os.createIndex('created', 'created', { unique: false });
                }
            };
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
            // Otra pestaña tiene la BD abierta en v1 y bloquea el upgrade: avisar en consola.
            // La copia 'kv' se conserva igual; el outbox quedará disponible al cerrar esa pestaña.
            req.onblocked = () => { if (window.console) console.warn('[offline] upgrade de IndexedDB bloqueado por otra pestaña'); };
        });
        return dbPromise;
    }

    // tx(modo, fn, store=STORE): abre una transacción sobre el store indicado.
    function tx(modo, fn, storeName) {
        const nombre = storeName || STORE;
        return abrirDB().then((db) => new Promise((resolve, reject) => {
            const t = db.transaction(nombre, modo);
            const store = t.objectStore(nombre);
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

    // ── OUTBOX (Fase 2): cola local de acciones de escritura sin internet ────────
    // Registro: { client_uuid, action, payload, status, reason, created, label, optimistic }
    //   status: 'pending' (por subir) · 'syncing' · 'error'
    const outboxPut = (item) => tx('readwrite', (s) => { s.put(item); }, OUTBOX);
    const outboxAll = () => tx('readonly', (s) => new Promise((res, rej) => {
        const r = s.getAll(); r.onsuccess = () => res(r.result || []); r.onerror = () => rej(r.error);
    }), OUTBOX);
    const outboxDel = (uuid) => tx('readwrite', (s) => { s.delete(uuid); }, OUTBOX);

    function outboxList(status) {
        return outboxAll().then((arr) => {
            const list = status ? arr.filter((x) => x.status === status) : arr;
            return list.sort((a, b) => (a.created || 0) - (b.created || 0)); // orden de creación
        });
    }
    function outboxUpdate(uuid, patch) {
        return outboxAll().then((arr) => {
            const it = arr.find((x) => x.client_uuid === uuid);
            if (!it) return false;
            return outboxPut(Object.assign(it, patch)).then(() => true);
        });
    }

    // ── Descarga ────────────────────────────────────────────────────────────
    // `signal` (opcional) permite ABORTAR los fetch: si una descarga forzada
    // (botón manual) cancela la automática que estaba en curso.
    function fetchJson(url, signal) {
        return fetch(url, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin', // manda la cookie de sesión (la ruta es auth)
            priority: 'low',            // PRIORIDAD: que esta bajada NO le quite ancho de
                                        // banda a las búsquedas del usuario. El navegador
                                        // atiende primero las peticiones de prioridad normal.
            signal: signal || undefined,
        }).then((r) => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    let cadena      = Promise.resolve(); // serializa las descargas: NUNCA corren dos a la vez
    let abortActual = null;              // AbortController de la descarga en curso (para cancelarla)

    // Descarga real. Si `signal` se aborta (porque una forzada la canceló), los
    // fetch lanzan AbortError y la copia anterior queda intacta.
    async function _syncReal(force, signal) {
        if (!navigator.onLine) return false;
        const meta = await idbGet('meta');
        // Chequeo barato: ¿cambió la versión? Si no y no es forzado, no bajamos nada.
        if (!force && meta && meta.version) {
            try {
                const v = await fetchJson('/offline/version', signal);
                if (v && v.version === meta.version) return false; // ya estamos al día
            } catch (_) { /* sin red / abortado: nada que hacer */ return false; }
        }

        const snap = await fetchJson('/offline/snapshot', signal);
        if (!snap || !snap.version) return false;

        // SUSTITUYE la copia cacheada: idbPut sobrescribe cada clave (no acumula).
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
    }

    // Orquesta las descargas:
    //  - Serializa con `cadena` → nunca corren dos a la vez (no se pisan en IndexedDB).
    //  - Una descarga FORZADA (botón manual) CANCELA la que esté en curso para tener
    //    prioridad y bajar YA la copia que pide el usuario.
    function sync(force) {
        if (!navigator.onLine) return Promise.resolve(false);
        if (force && abortActual) abortActual.abort(); // cede el paso a la forzada
        const controller = (typeof AbortController === 'function') ? new AbortController() : null;
        const corre = cadena.then(async () => {
            abortActual = controller;
            try {
                return await _syncReal(force, controller ? controller.signal : undefined);
            } catch (e) {
                // AbortError = la cancelamos a propósito; no es un fallo real.
                if (e && e.name !== 'AbortError' && window.console) {
                    console.warn('[offline] sync falló:', e && e.message);
                }
                return false; // conservamos la última copia buena
            } finally {
                if (abortActual === controller) abortActual = null;
            }
        });
        cadena = corre.catch(() => {}); // la cadena nunca se rompe por un fallo
        return corre;
    }

    // ── API pública ───────────────────────────────────────────────────────────
    window.OfflineDB = {
        ready: abrirDB().then(() => true).catch(() => false),
        get:   (tabla) => idbGet(tabla).then((v) => v || []),
        put:   (tabla, valor) => idbPut(tabla, valor),   // sobrescribe una tabla en 'kv' (update optimista)
        meta:  () => idbGet('meta').then((m) => m || null),
        sync:  (force) => sync(!!force),
        estaListo: () => idbGet('meta').then((m) => !!(m && m.version)),
        // ── Outbox (Fase 2) ──
        enqueue:      (item) => outboxPut(item),
        outboxList:   (status) => outboxList(status),
        outboxUpdate: (uuid, patch) => outboxUpdate(uuid, patch),
        outboxRemove: (uuid) => outboxDel(uuid),
        countPending:  () => outboxList('pending').then((a) => a.length),
        countError:    () => outboxList('error').then((a) => a.length),
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

    // ¿Conexión demasiado lenta para el snapshot (varios MB)? En 2G una descarga
    // de REFRESCO acapararía el ancho de banda de la página que el usuario está
    // abriendo; se salta y quedan los reintentos del intervalo/evento 'online'.
    function conexionMuyLenta() {
        const t = navigator.connection && navigator.connection.effectiveType;
        return typeof t === 'string' && /(^|-)2g$/.test(t);
    }

    // ── Disparadores automáticos (punto ÚNICO) ─────────────────────────────────
    // Todos los disparos automáticos pasan por aquí: espera inactividad (sync es
    // async/no-bloqueante, la bajada usa priority:'low' y falla en silencio
    // conservando la última copia) y en 2G SOLO omite los refrescos — si aún no
    // hay copia local (primera vez), baja igual: el usuario de campo en 2G es
    // justamente quien más necesita el modo offline, y sin copia no hay nada que
    // consultar al perder señal. La primera vez usa un timeout de idle CORTO
    // (2s) para que el offline quede listo pronto sin pelear con la primera
    // página; los refrescos usan el default (4s), que sí pueden esperar.
    function syncAutomatico() {
        if (!navigator.onLine) return;
        idbGet('meta')
            .then((meta) => {
                const primeraVez = !meta || !meta.version;
                if (!primeraVez && conexionMuyLenta()) return;
                enInactividad(() => sync(false), primeraVez ? 2000 : undefined);
            })
            .catch(() => enInactividad(() => sync(false)));
    }

    // Al cargar la app; revisión periódica; y al recuperar la conexión.
    syncAutomatico();
    setInterval(syncAutomatico, CHECK_CADA_MS);
    window.addEventListener('online', syncAutomatico);
})();
