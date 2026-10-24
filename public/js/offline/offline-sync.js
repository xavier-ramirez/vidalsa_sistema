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
 *   2) Cada CHECK_CADA_MS mientras haya internet. Ese chequeo YA es barato de por sí
 *      (~0,5 KB si no cambió nada), así que no hace falta preguntar antes por la versión.
 *   3) Al volver la conexión (evento 'online').
 *   4) Forzada: window.OfflineDB.sync(true). YA NO hay un botón "Copia local" en el menú
 *      (se quitó); quedan los disparos que no pueden esperar al refresco de los 10 min:
 *      tras vaciar el outbox y al descartar una acción pendiente.
 *
 * PRIORIDAD: la búsqueda del usuario manda. La bajada usa fetch priority:'low' y
 * los disparadores automáticos esperan inactividad (requestIdleCallback) para no
 * competir por red ni CPU con lo que el usuario esté haciendo.
 *
 * Lo único que queda aquí son los KITS del almacén (dominio catalogos). El inventario, la
 * bitácora y las notas van en la copia local del almacén, y equipos, auxiliares y
 * movilizaciones en la de equipos (copia-local.js). Solo viaja a quien tiene el módulo
 * de almacén.
 *
 * El cliente guarda un CURSOR (meta.c) y se lo manda al servidor: si nada cambió la
 * respuesta pesa medio KB; si cambió, los kits llegan enteros (pocos KB).
 *
 * Si el cursor no vale (cambió el esquema, el usuario o sus permisos) el servidor
 * responde con la copia completa y todo se repara solo: no hay que migrar IndexedDB ni
 * subir DB_VERSION.
 *
 * API pública (window.OfflineDB):
 *   .ready            Promise que resuelve cuando la BD local está abierta.
 *   .get(tabla)       -> Promise<Array> (kits).
 *   .meta()           -> Promise<{version, generado, descargado, c}|null>.
 *   .sync(force)      -> sincroniza ahora; force=true además CANCELA la descarga
 *                        en curso para tomar prioridad (botón "Actualizar datos").
 *                        Resuelve con {ok, cambios}: `ok` es si la sincronización se
 *                        pudo completar y `cambios` si además trajo datos nuevos. Los
 *                        dos hacen falta — "ya estabas al día" y "no se pudo" son el
 *                        mismo resultado en datos pero lo contrario para el usuario.
 *                        Nunca rechaza: el fallo viaja en ok=false.
 *   .estaListo()      -> Promise<bool> (¿hay copia local usable?).
 * Evento: window dispatch 'offline-datos-actualizados' tras cada descarga nueva.
 */
(function () {
    'use strict';

    const DB_NAME    = 'vidalsa_offline';
    const DB_VERSION = 2; // v2: añade el store 'outbox' (Fase 2: escritura offline)
    const STORE      = 'kv';      // object store clave→valor: 'meta', 'kits'
    const OUTBOX     = 'outbox';  // cola de acciones hechas sin internet (keyPath: client_uuid)
    const CHECK_CADA_MS = 10 * 60 * 1000; // revisar si hay datos nuevos cada 10 min (online)

    // ── Las tablas ─────────────────────────────────────────────────────────────
    // Cada tabla se guarda como UN array bajo su clave en el store 'kv' (sin keyPath).
    //   dominio → de qué versión del servidor depende
    //   orden   → debe reproducir el ORDER BY del servidor
    const txt = (v) => String(v == null ? '' : v);
    const porTexto = (campo) => (a, b) => txt(a[campo]).localeCompare(txt(b[campo]), 'es', { sensitivity: 'base' });

    const TABLAS = {
        // Kits del almacén (KitAlmacenService::catalogo): viajan enteros con los catálogos.
        kits: { dominio: 'catalogos', orden: porTexto('nombre') },
    };
    const NOMBRES_TABLAS = Object.keys(TABLAS);
    // Tablas que ya no viajan (ahora en copia-local.js): se borran de la copia de quien las
    // bajó antes, para no dejar megas muertos en el teléfono.
    const RETIRADAS = ['equipos', 'frentes', 'almacenes', 'productos', 'movimientos', 'stock'];

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
            req.onsuccess = () => {
                const db = req.result;
                const t = db.transaction(STORE, 'readwrite');
                RETIRADAS.forEach((k) => t.objectStore(STORE).delete(k));
                resolve(db);
            };
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
        return window.apiFetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, // manda la cookie de sesión (la ruta es auth)
            priority: 'low',            // PRIORIDAD: que esta bajada NO le quite ancho de
                                        // banda a las búsquedas del usuario. El navegador
                                        // atiende primero las peticiones de prioridad normal.
            signal: signal || undefined
        }).then((r) => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    let cadena      = Promise.resolve(); // serializa las descargas: NUNCA corren dos a la vez
    let abortActual = null;              // AbortController de la descarga en curso (para cancelarla)

    // Descarga real. Si `signal` se aborta (porque una forzada la canceló), los
    // fetch lanzan AbortError y la copia anterior queda intacta.
    // Devuelve si HUBO cambios, o LANZA si la sincronización no se pudo completar. Que el
    // fallo se propague (en vez de devolver false) es lo que permite a sync() distinguir
    // "no había nada nuevo" de "no se pudo": son lo mismo en datos pero opuestos para el
    // usuario, y el botón manual llegó a decir "revisa tu conexión" con la copia al día.
    async function _syncReal(signal) {
        if (!navigator.onLine) throw new Error('sin conexión');
        const meta = await idbGet('meta');

        // SIEMPRE se manda un cursor, aunque no tengamos uno bueno: `{e:0}` significa
        // "hablo con cursor, pero el mío no vale" y el servidor responde con la copia
        // completa MÁS un cursor fresco. Sin esto, un cliente recién instalado recibiría
        // la forma histórica (que no lleva cursor), y al no tener cursor volvería a
        // pedirla la vez siguiente: se quedaría bajando la copia entera para siempre.
        // La ruta sin `?c=` queda SOLO para los clientes con el JS viejo en caché.
        //
        // Tampoco se consulta /offline/version antes: con cursor, el propio snapshot ya
        // contesta "sin_cambios" en medio KB, así que preguntar dos veces era un viaje
        // de más. (Ese endpoint sigue existiendo: offline_mode.js lo usa como sondeo de
        // conectividad, que es otra cosa.)
        const cursor = (meta && meta.c) || { e: 0 };
        const url    = '/offline/snapshot?c=' + encodeURIComponent(JSON.stringify(cursor));
        const snap   = await fetchJson(url, signal);
        if (!snap || !snap.version) throw new Error('respuesta inválida del servidor');

        // Respuesta sin `modos` (despliegue a medias): forma histórica, se sustituye todo. Es también la vía por la que una copia vieja se
        // repara sola y estrena cursor.
        if (!snap.modos) {
            for (const tabla of NOMBRES_TABLAS) {
                await idbPut(tabla, Array.isArray(snap[tabla]) ? snap[tabla] : []);
            }
            await guardarMeta(snap);
            avisar(snap.version);
            return true;
        }

        const datos = snap.datos || {};
        let   hubo  = false;

        for (const tabla of NOMBRES_TABLAS) {
            // 'full' trae la tabla entera; 'sin_cambios' ni se toca. Ojo: solo si la tabla
            // VIENE — sustituirla por [] borraría datos buenos.
            if (snap.modos[TABLAS[tabla].dominio] !== 'full' || !Array.isArray(datos[tabla])) continue;
            const filas = datos[tabla].slice();
            if (TABLAS[tabla].orden) filas.sort(TABLAS[tabla].orden);
            await idbPut(tabla, filas);
            hubo = true;
        }

        // El cursor se guarda SIEMPRE, aunque no haya cambiado ni una fila: es lo que
        // hace que el siguiente chequeo siga costando medio KB.
        await guardarMeta(snap);
        if (hubo) avisar(snap.version);
        return hubo;
    }

    function guardarMeta(snap) {
        return idbPut('meta', {
            version:    snap.version,
            generado:   snap.generado || null,
            descargado: new Date().toISOString(),
            c:          snap.c || null,
        });
    }

    function avisar(version) {
        window.dispatchEvent(new CustomEvent('offline-datos-actualizados', { detail: { version: version } }));
    }

    // Orquesta las descargas:
    //  - Serializa con `cadena` → nunca corren dos a la vez (no se pisan en IndexedDB).
    //  - Una descarga FORZADA (botón manual) CANCELA la que esté en curso para tener
    //    prioridad y bajar YA la copia que pide el usuario.
    function sync(force) {
        if (!navigator.onLine) return Promise.resolve({ ok: false, cambios: false });
        if (force && abortActual) abortActual.abort(); // cede el paso a la forzada
        const controller = (typeof AbortController === 'function') ? new AbortController() : null;
        const corre = cadena.then(async () => {
            abortActual = controller;
            try {
                return { ok: true, cambios: await _syncReal(controller ? controller.signal : undefined) };
            } catch (e) {
                // AbortError = la cancelamos a propósito; no es un fallo real.
                if (e && e.name !== 'AbortError' && window.console) {
                    console.warn('[offline] sync falló:', e && e.message);
                }
                return { ok: false, cambios: false, abortada: !!(e && e.name === 'AbortError') };
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

    // ¿Conexión demasiado lenta? En 2G una descarga de REFRESCO acapararía el ancho de banda de la página que el usuario está
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
    // El sync consulta /offline/snapshot, que es una ruta CON SESIÓN → cada consulta cuenta como
    // "actividad" y renueva la sesión del backend. Si lo hiciéramos cada 10 min sin importar la
    // actividad del usuario, la sesión NUNCA vencería por inactividad (anula el cierre que
    // session_timeout dice que garantiza el backend). Por eso, igual que el pingServer de sesión,
    // saltamos el sync si el usuario está inactivo. Fuente de actividad: la MISMA marca
    // 'vidalsa_last_activity' que escribe session_timeout en clic/tecla. Si no existe (esa
    // partial no cargó), asumimos activo para no romper el sync offline.
    const INACTIVIDAD_MAX_MS = 2 * 60 * 1000; // sin clic/tecla en 2 min = usuario ausente
    function usuarioActivo() {
        try {
            const t = parseInt(localStorage.getItem('vidalsa_last_activity'), 10);
            return !t || (Date.now() - t) < INACTIVIDAD_MAX_MS;
        } catch (e) { return true; }
    }

    function syncAutomatico() {
        if (!navigator.onLine) return;
        idbGet('meta')
            .then((meta) => {
                const primeraVez = !meta || !meta.version;
                // Ya hay snapshot Y el usuario está inactivo → NO consultamos el servidor, para
                // no renovar la sesión. La PRIMERA vez sí procede (deja el cache offline listo).
                if (!primeraVez && !usuarioActivo()) return;
                if (!primeraVez && conexionMuyLenta()) return;
                enInactividad(() => sync(false), primeraVez ? 2000 : undefined);
            })
            .catch(() => { if (usuarioActivo()) enInactividad(() => sync(false)); });
    }

    // Al cargar la app; revisión periódica; y al recuperar la conexión.
    syncAutomatico();
    setInterval(syncAutomatico, CHECK_CADA_MS);
    window.addEventListener('online', syncAutomatico);
})();
