/**
 * Copia LOCAL de los datos — window.CopiaLocal.
 *
 * Cada pantalla que la usa pinta SIEMPRE desde aquí, con o sin internet: una sola lógica.
 * La copia vive en IndexedDB y se pone al día con GET /sync/{dominio}
 * (SincronizacionController): la primera vez baja entera y después solo lo que cambió.
 *
 *   Como WhatsApp: TODOS los dominios del usuario (<meta name="copia-dominios">) se ponen al
 *   día en segundo plano, esté abierto el módulo que sea, EN CUANTO el servidor avisa que hubo
 *   cambios (tiempo-real.js, por WebSocket). Además al cargar la página, al volver a la pestaña,
 *   al recuperar la red y cada `espera` segundos (15 s) como respaldo: con el aviso conectado,
 *   cada RESPALDO_EN_VIVO_S. Así abrir un módulo no le pregunta nada al servidor: la copia ya
 *   está al día.
 *
 *   CopiaLocal.usar(dominio, ancla) → Promise<vista>
 *       Abre la copia del dominio. Pregunta al servidor si no hay copia todavía o si la
 *       última pregunta tiene más de 3 s (abrir, filtrar). `ancla` (un elemento de la pantalla) mantiene al
 *       día también un dominio que no venga en el meta.
 *       vista.filas(tabla) → array · vista.catalogo(nombre) → array · vista.estado
 *   CopiaLocal.ahora(dominio) → Promise. Pregunta ya por ese dominio.
 *   CopiaLocal.alGuardar() → Promise. Pregunta ya por todos los dominios que mantiene; lo llaman
 *       fetch_interceptor.js (tras cada guardado que sale bien) y estado_red.js.
 *   CopiaLocal.alDia(dominio) → Promise. Se cumple cuando la copia ya trae el último guardado.
 *   CopiaLocal.hayCambios() → el servidor avisó (tiempo-real.js): pregunta ya por todos.
 *   CopiaLocal.enVivo(conectado) → llegan (o dejaron de llegar) avisos del servidor.
 *   Evento 'copia-local' en window, detail { dominio, cambio, estado }: cambio = true si
 *       llegaron datos nuevos (la pantalla se repinta); con false solo cambió el estado.
 *   estado = { listo, conectado, actualizado (ms) }.
 *
 * Una copia por usuario (meta usuario-id): en un equipo compartido nadie ve la de otro, y
 * al entrar otro usuario la del anterior se borra.
 *
 * No pregunta si el usuario lleva 2 minutos sin tocar nada: cada pregunta va con sesión y la
 * renovaría para siempre (misma marca 'vidalsa_last_activity' que session_timeout).
 *
 * Usa window.fetchSinInterceptor: sin red, cada sondeo fallaría y el interceptor sacaría el
 * aviso "Sin conexión" cada 15 s. El estado de la copia lo muestra cada pantalla.
 *
 * En el equipo se guarda CIFRADA con la llave de llave-local.js: cada tabla en BLOQUES (las
 * filas repartidas por id), y al llegar cambios solo se reescriben los bloques tocados, así
 * cifrar no se nota. Sin llave abierta (no debería pasar dentro del sistema) la copia vive
 * solo en memoria; si la guardada no se puede abrir (otra llave) se tira y se baja de nuevo.
 */
(function () {
    'use strict';
    if (window.CopiaLocal) return;

    const DB_NOMBRE = 'vidalsa_copia';
    // 2: cifrada por bloques. Al subir de la 1 se tira la copia vieja, que estaba en claro.
    const DB_VERSION = 2;
    const BLOQUES = 32;
    const CATALOGOS = '__catalogos';   // tabla aparte para los catálogos: cambian poco
    const TICK_MS = 5000;

    const usuario = () => (document.querySelector('meta[name="usuario-id"]') || {}).content || '';

    // ── IndexedDB ────────────────────────────────────────────────────────────
    // bloques: { u, d, t, b, iv, ct }   clave [u, d, t, b] · ct = las filas del bloque, cifradas
    // estado:  { u, d, iv, ct }         clave [u, d]       · ct = { hasta, firma, esquema, espera, actualizado }
    // Si el navegador no deja abrirla (ventana privada), la copia vive solo en memoria.
    let dbPromesa = null;
    function db() {
        if (!dbPromesa) {
            dbPromesa = new Promise((resolve) => {
                let req;
                try { req = indexedDB.open(DB_NOMBRE, DB_VERSION); } catch (e) { resolve(null); return; }
                req.onupgradeneeded = () => {
                    const b = req.result;
                    ['filas', 'estado'].forEach((n) => { if (b.objectStoreNames.contains(n)) b.deleteObjectStore(n); });
                    b.createObjectStore('bloques', { keyPath: ['u', 'd', 't', 'b'] });
                    b.createObjectStore('estado', { keyPath: ['u', 'd'] });
                };
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => resolve(null);
                req.onblocked = () => resolve(null);
            }).then((b) => (b ? borrarOtrosUsuarios(b).then(() => b) : null));
        }
        return dbPromesa;
    }

    const fin = (tx) => new Promise((ok, mal) => { tx.oncomplete = ok; tx.onerror = tx.onabort = () => mal(tx.error); });
    const pedir = (req) => new Promise((ok, mal) => { req.onsuccess = () => ok(req.result); req.onerror = () => mal(req.error); });
    // Todas las claves que empiezan por `prefijo` (un array siempre ordena después que un número o texto).
    const rango = (prefijo) => IDBKeyRange.bound(prefijo, prefijo.concat([[]]));

    function borrarOtrosUsuarios(b) {
        const u = usuario();
        const tx = b.transaction(['bloques', 'estado'], 'readwrite');
        const filas = tx.objectStore('bloques');
        const estado = tx.objectStore('estado');
        estado.getAllKeys().onsuccess = (e) => {
            new Set(e.target.result.map((k) => k[0])).forEach((otro) => {
                if (otro === u) return;
                filas.delete(rango([otro]));
                estado.delete(rango([otro]));
            });
        };
        return fin(tx).catch(() => {});
    }

    // ── Copias en memoria ────────────────────────────────────────────────────
    const copias = {};   // dominio → su copia en memoria (tablas como Map por id) y su estado

    function copia(d) {
        if (!copias[d]) {
            copias[d] = {
                tablas: {}, arrays: {}, catalogos: {}, meta: {},
                estado: { listo: false, conectado: true, actualizado: null },
                vista: null, lista: null, enCurso: null, siguiente: null, ultimo: 0, anclas: new Set(),
            };
            copias[d].vista = {
                filas: (t) => {
                    const c = copias[d];
                    if (!c.arrays[t]) c.arrays[t] = c.tablas[t] ? Array.from(c.tablas[t].values()) : [];
                    return c.arrays[t];
                },
                catalogo: (n) => copias[d].catalogos[n] || [],
                get estado() { return copias[d].estado; },
            };
        }
        return copias[d];
    }

    // ── Cifrado ──────────────────────────────────────────────────────────────
    const llave = window.LlaveLocal;
    const conLlave = () => (llave ? llave.lista() : Promise.resolve(false));

    // Bloque de una fila: el id repartido en BLOQUES (número o texto).
    function bloque(id) {
        if (typeof id === 'number') return Math.abs(id) % BLOQUES;
        let h = 0;
        const s = String(id);
        for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
        return Math.abs(h) % BLOQUES;
    }

    function cargar(d) {
        const c = copia(d);
        if (!c.lista) {
            const u = usuario();
            c.lista = Promise.all([db(), conLlave()]).then(([b, hayLlave]) => {
                if (!b || !hayLlave) return;
                const tx = b.transaction(['bloques', 'estado'], 'readonly');
                return Promise.all([
                    pedir(tx.objectStore('bloques').getAll(rango([u, d]))),
                    pedir(tx.objectStore('estado').get([u, d])),
                ]).then(([bloques, est]) => {
                    if (!est) return;
                    return Promise.all([llave.descifrar(est)].concat(bloques.map((r) => llave.descifrar(r))))
                        .then(([meta, ...filas]) => {
                            const tablas = {};
                            bloques.forEach((r, i) => {
                                if (r.t === CATALOGOS) { c.catalogos = filas[i]; return; }
                                const m = tablas[r.t] || (tablas[r.t] = new Map());
                                filas[i].forEach((f) => m.set(f.id, f));
                            });
                            c.tablas = tablas;
                            c.arrays = {};
                            c.meta = meta;
                            c.estado.actualizado = meta.actualizado || null;
                            c.estado.listo = true;
                        })
                        // Cifrada con otra llave: no se puede abrir, se tira y se baja de nuevo.
                        .catch(() => { c.tablas = {}; c.arrays = {}; c.catalogos = {}; c.meta = {}; return borrar(d); });
                });
            }).catch(() => {});
        }
        return c.lista;
    }

    function borrar(d) {
        const u = usuario();
        return db().then((b) => {
            if (!b) return;
            const tx = b.transaction(['bloques', 'estado'], 'readwrite');
            tx.objectStore('bloques').delete(rango([u, d]));
            tx.objectStore('estado').delete([u, d]);
            return fin(tx);
        }).catch(() => {});
    }

    // ── Sincronizar ──────────────────────────────────────────────────────────
    function avisar(d, cambio) {
        window.dispatchEvent(new CustomEvent('copia-local', { detail: { dominio: d, cambio, estado: copia(d).estado } }));
    }

    function aplicar(d, r) {
        const c = copia(d);
        const u = usuario();

        // Bloques que hay que reescribir: todos los de la copia si llegó entera; si no, los
        // de las filas que cambiaron o se borraron.
        const tocados = {};
        const tocar = (t, id) => (tocados[t] || (tocados[t] = new Set())).add(bloque(id));
        Object.entries(r.datos || {}).forEach(([t, fs]) => fs.forEach((f) => tocar(t, f.id)));
        Object.entries(r.borrados || {}).forEach(([t, ids]) => ids.forEach((id) => tocar(t, id)));

        if (r.completo) { c.tablas = {}; }
        Object.entries(r.datos || {}).forEach(([t, filas]) => {
            const m = c.tablas[t] || (c.tablas[t] = new Map());
            filas.forEach((f) => m.set(f.id, f));
        });
        Object.entries(r.borrados || {}).forEach(([t, ids]) => {
            const m = c.tablas[t];
            if (m) ids.forEach((id) => m.delete(id));
        });
        Object.assign(c.catalogos, r.catalogos || {});
        c.arrays = {};
        c.meta = { hasta: r.hasta, firma: r.firma, esquema: r.esquema, espera: r.espera };
        c.estado.actualizado = Date.now();
        c.estado.listo = true;

        return Promise.all([db(), conLlave()]).then(async ([b, hayLlave]) => {
            if (!b || !hayLlave) return;
            // Se cifra TODO antes de abrir la transacción: una espera ajena a IndexedDB la cierra.
            const escribir = [];   // [t, b, filas | null (bloque vacío: se borra)]
            Object.entries(tocados).forEach(([t, bs]) => {
                const grupos = {};
                bs.forEach((n) => { grupos[n] = []; });
                (c.tablas[t] || new Map()).forEach((f, id) => { const g = grupos[bloque(id)]; if (g) g.push(f); });
                Object.entries(grupos).forEach(([n, fs]) => escribir.push([t, Number(n), fs.length ? fs : null]));
            });
            if (r.completo || Object.keys(r.catalogos || {}).length) escribir.push([CATALOGOS, 0, c.catalogos]);
            const cifrados = await Promise.all(escribir.map(([, , v]) => (v ? llave.cifrar(v) : null)));
            const est = await llave.cifrar(Object.assign({ actualizado: c.estado.actualizado }, c.meta));

            const tx = b.transaction(['bloques', 'estado'], 'readwrite');
            const bloques = tx.objectStore('bloques');
            if (r.completo) bloques.delete(rango([u, d]));
            escribir.forEach(([t, n], i) => {
                if (cifrados[i]) bloques.put(Object.assign({ u, d, t, b: n }, cifrados[i]));
                else bloques.delete([u, d, t, n]);
            });
            tx.objectStore('estado').put(Object.assign({ u, d }, est));
            return fin(tx);
        }).catch((e) => console.warn('[CopiaLocal] no se pudo guardar la copia de', d, e));
    }

    // Sin permiso (403): la copia de ese dominio se borra y no se vuelve a preguntar en esta
    // página; con la sesión vencida (401) se conserva, pero deja de preguntar hasta que vuelva
    // a entrar.
    function olvidar(d) {
        const c = copia(d);
        c.sinPermiso = true;
        c.tablas = {}; c.arrays = {}; c.catalogos = {}; c.meta = {};
        c.estado.listo = false;
        return borrar(d);
    }

    function sincronizar(d) {
        const c = copia(d);
        if (c.enCurso) return c.enCurso;

        c.enCurso = cargar(d).then(() => {
            c.ultimo = Date.now();
            const q = new URLSearchParams({ desde: c.meta.hasta || '', firma: c.meta.firma || '', esquema: c.meta.esquema || '' });
            return (window.fetchSinInterceptor || window.fetch)('/sync/' + d + '?' + q, {
                credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' },
            }).then((res) => {
                if (res.status === 403) return olvidar(d).then(() => avisar(d, true));
                if (res.status === 401) {
                    c.sinSesion = true;
                    // Como ya no hace falta navegar para que la copia pregunte, es ella la que
                    // se entera de que la sesión murió: se repite por el fetch con interceptor,
                    // que lleva al inicio de sesión con el motivo (fetch_interceptor.js).
                    if (window.fetchSinInterceptor) window.fetch(res.url, { credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => {});
                    return;
                }
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.json().then((r) => {
                    const cambio = !!(r.completo || Object.keys(r.datos || {}).length
                        || Object.keys(r.borrados || {}).length || Object.keys(r.catalogos || {}).length);
                    c.estado.conectado = true;
                    return aplicar(d, r).then(() => avisar(d, cambio));
                });
            }).catch(() => {
                const antes = c.estado.conectado;
                c.estado.conectado = false;
                if (antes) avisar(d, false);
            });
        }).finally(() => {
            c.enCurso = null;
        });

        return c.enCurso;
    }

    // Una pregunta que salga DESPUÉS de ahora: la que ya va en camino pudo salir antes del
    // cambio (un guardado o un aviso), así que se encadena otra al terminar. Varias llamadas
    // mientras tanto esperan esa misma.
    function sincronizarTras(d) {
        const c = copias[d];
        if (!c.enCurso) return sincronizar(d);
        if (!c.siguiente) {
            const otra = () => {
                c.siguiente = null;
                return c.sinSesion || c.sinPermiso ? undefined : sincronizar(d);
            };
            c.siguiente = c.enCurso.then(otra, otra);
        }
        return c.siguiente;
    }

    // Dominios que se mantienen al día: los del usuario (meta) y los que tengan alguna ancla
    // todavía en la página.
    function enUso() {
        const meta = ((document.querySelector('meta[name="copia-dominios"]') || {}).content || '').split(',').filter(Boolean);
        meta.forEach(copia);
        return Object.keys(copias).filter((d) => {
            const c = copias[d];
            c.anclas.forEach((a) => { if (!a.isConnected) c.anclas.delete(a); });
            return (meta.includes(d) || c.anclas.size > 0) && !c.sinSesion && !c.sinPermiso;
        });
    }

    // Con el aviso en tiempo real conectado, preguntar solo es la red de seguridad: cada tanto.
    const RESPALDO_EN_VIVO_S = 120;
    let enVivo = false;
    // El servidor avisó mientras no se podía preguntar (pestaña oculta o 2 min sin tocar
    // nada): se pregunta en cuanto se pueda, sin esperar al respaldo.
    let avisoPendiente = false;

    const vencida = (c) => Date.now() - c.ultimo >= Math.max(c.meta.espera || 15, enVivo ? RESPALDO_EN_VIVO_S : 0) * 1000;
    // usar(): pintar desde la copia pregunta si la última pregunta tiene más de esto.
    const PREGUNTA_AL_USAR_MS = 3000;

    function revisar(forzar) {
        if (!usuario() || document.visibilityState !== 'visible' || !window.usuarioActivo()) return;
        const aviso = avisoPendiente;
        avisoPendiente = false;
        enUso().forEach((d) => {
            const c = copias[d];
            if (!forzar && !aviso && !vencida(c)) return;
            if (aviso) sincronizarTras(d);
            else sincronizar(d);
        });
    }

    // El aviso llega a todos a la vez: cada uno pregunta un poco después (0,1 a 0,5 s, al azar)
    // para no caer juntos sobre el servidor, y los avisos de ese rato van en una sola pregunta.
    let esperaAviso = null;
    function hayCambios() {
        avisoPendiente = true;
        if (esperaAviso) return;
        esperaAviso = setTimeout(() => { esperaAviso = null; revisar(true); }, 100 + Math.random() * 400);
    }

    // Hasta que llega la pregunta posterior al guardado, la copia no está al día (ver alDia).
    function alGuardar() {
        return Promise.all(enUso().map((d) => {
            const c = copias[d];
            const p = sincronizarTras(d);
            c.trasGuardar = p;
            return p.finally(() => { if (c.trasGuardar === p) c.trasGuardar = null; });
        }));
    }

    setInterval(() => revisar(false), TICK_MS);
    window.addEventListener('focus', () => revisar(true));
    window.addEventListener('online', () => revisar(true));
    document.addEventListener('visibilitychange', () => revisar(true));
    // Al cargar la página: todas las copias se ponen al día de una vez, antes de abrir nada.
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => revisar(false));
    else revisar(false);

    window.CopiaLocal = {
        usar(d, ancla) {
            const c = copia(d);
            if (ancla) c.anclas.add(ancla);
            return cargar(d).then(() => {
                // La primera vez (sin copia) se espera a la descarga; con copia se pinta ya
                // y, si la última pregunta tiene más de 3 s, se pregunta: la puesta al día
                // llega por el evento. Así abrir el módulo, filtrar o cambiar de página trae
                // lo hecho desde otro equipo sin esperar la vuelta de `espera`.
                if (c.estado.listo) {
                    if (Date.now() - c.ultimo > PREGUNTA_AL_USAR_MS && !c.sinSesion && !c.sinPermiso) sincronizar(d);
                    return c.vista;
                }
                return sincronizar(d).then(() => c.vista);
            });
        },
        ahora: sincronizar,
        alGuardar,
        hayCambios,
        // En vivo = llegan avisos del servidor (tiempo-real.js): preguntar queda de respaldo.
        enVivo(conectado) {
            enVivo = !!conectado;
        },
        alDia(d) {
            return copia(d).trasGuardar || Promise.resolve();
        },
    };
})();
