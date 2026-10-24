/**
 * Copia LOCAL de los datos — window.CopiaLocal.
 *
 * Cada pantalla que la usa pinta SIEMPRE desde aquí, con o sin internet: una sola lógica.
 * La copia vive en IndexedDB y se pone al día con GET /sync/{dominio}
 * (SincronizacionController): la primera vez baja entera y después solo lo que cambió.
 *
 *   CopiaLocal.usar(dominio, ancla) → Promise<vista>
 *       Abre la copia del dominio y la mantiene al día mientras `ancla` (un elemento de la
 *       pantalla) siga en la página: pregunta cada `espera` segundos que manda el servidor
 *       (15 s), al volver a la pestaña y al recuperar la red.
 *       vista.filas(tabla) → array · vista.catalogo(nombre) → array · vista.estado
 *   CopiaLocal.ahora(dominio) → Promise. Pregunta ya por ese dominio.
 *   CopiaLocal.alGuardar() → Promise. Pregunta ya por todos los dominios en uso; lo llaman
 *       fetch_interceptor.js (tras cada guardado que sale bien) y outbox-sync.js.
 *   CopiaLocal.alDia(dominio) → Promise. Se cumple cuando la copia ya trae el último guardado.
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
 */
(function () {
    'use strict';
    if (window.CopiaLocal) return;

    const DB_NOMBRE = 'vidalsa_copia';
    const DB_VERSION = 1;
    const TICK_MS = 5000;
    const INACTIVIDAD_MAX_MS = 2 * 60 * 1000;

    const usuario = () => (document.querySelector('meta[name="usuario-id"]') || {}).content || '';

    // ── IndexedDB ────────────────────────────────────────────────────────────
    // filas:  { u, d, t, id, f }            clave [u, d, t, id]
    // estado: { u, d, hasta, firma, esquema, espera, actualizado, catalogos }  clave [u, d]
    // Si el navegador no deja abrirla (ventana privada), la copia vive solo en memoria.
    let dbPromesa = null;
    function db() {
        if (!dbPromesa) {
            dbPromesa = new Promise((resolve) => {
                let req;
                try { req = indexedDB.open(DB_NOMBRE, DB_VERSION); } catch (e) { resolve(null); return; }
                req.onupgradeneeded = () => {
                    const b = req.result;
                    b.createObjectStore('filas', { keyPath: ['u', 'd', 't', 'id'] });
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
        const tx = b.transaction(['filas', 'estado'], 'readwrite');
        const filas = tx.objectStore('filas');
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
                vista: null, lista: null, enCurso: null, ultimo: 0, anclas: new Set(),
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

    function cargar(d) {
        const c = copia(d);
        if (!c.lista) {
            const u = usuario();
            c.lista = db().then((b) => {
                if (!b) return;
                const tx = b.transaction(['filas', 'estado'], 'readonly');
                return Promise.all([
                    pedir(tx.objectStore('filas').getAll(rango([u, d]))),
                    pedir(tx.objectStore('estado').get([u, d])),
                ]).then(([filas, meta]) => {
                    filas.forEach((r) => (c.tablas[r.t] || (c.tablas[r.t] = new Map())).set(r.id, r.f));
                    if (meta) {
                        c.meta = meta;
                        c.catalogos = meta.catalogos || {};
                        c.estado.actualizado = meta.actualizado || null;
                        c.estado.listo = true;
                    }
                });
            }).catch(() => {});
        }
        return c.lista;
    }

    // ── Sincronizar ──────────────────────────────────────────────────────────
    function avisar(d, cambio) {
        window.dispatchEvent(new CustomEvent('copia-local', { detail: { dominio: d, cambio, estado: copia(d).estado } }));
    }

    function aplicar(d, r) {
        const c = copia(d);
        const u = usuario();

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

        return db().then((b) => {
            if (!b) return;
            const tx = b.transaction(['filas', 'estado'], 'readwrite');
            const filas = tx.objectStore('filas');
            if (r.completo) filas.delete(rango([u, d]));
            Object.entries(r.datos || {}).forEach(([t, fs]) => fs.forEach((f) => filas.put({ u, d, t, id: f.id, f })));
            Object.entries(r.borrados || {}).forEach(([t, ids]) => ids.forEach((id) => filas.delete([u, d, t, id])));
            tx.objectStore('estado').put(Object.assign({ u, d, actualizado: c.estado.actualizado, catalogos: c.catalogos }, c.meta));
            return fin(tx);
        }).catch((e) => console.warn('[CopiaLocal] no se pudo guardar la copia de', d, e));
    }

    // Sin permiso (403): la copia de ese dominio se borra; con la sesión vencida (401) se
    // conserva, pero deja de preguntar hasta que vuelva a entrar.
    function olvidar(d) {
        const c = copia(d);
        c.tablas = {}; c.arrays = {}; c.catalogos = {}; c.meta = {};
        c.estado.listo = false;
        const u = usuario();
        return db().then((b) => {
            if (!b) return;
            const tx = b.transaction(['filas', 'estado'], 'readwrite');
            tx.objectStore('filas').delete(rango([u, d]));
            tx.objectStore('estado').delete([u, d]);
            return fin(tx);
        }).catch(() => {});
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
                if (res.status === 401) { c.sinSesion = true; return; }
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
        }).finally(() => { c.enCurso = null; });

        return c.enCurso;
    }

    function usuarioActivo() {
        try {
            const t = parseInt(localStorage.getItem('vidalsa_last_activity'), 10);
            return !t || (Date.now() - t) < INACTIVIDAD_MAX_MS;
        } catch (e) { return true; }
    }

    // Dominios en uso: los que tienen alguna ancla todavía en la página.
    function enUso() {
        return Object.keys(copias).filter((d) => {
            const c = copias[d];
            c.anclas.forEach((a) => { if (!a.isConnected) c.anclas.delete(a); });
            return c.anclas.size > 0 && !c.sinSesion;
        });
    }

    function revisar(forzar) {
        if (document.visibilityState !== 'visible' || !usuarioActivo()) return;
        enUso().forEach((d) => {
            const c = copias[d];
            const espera = (c.meta.espera || 15) * 1000;
            if (forzar || Date.now() - c.ultimo >= espera) sincronizar(d);
        });
    }

    // Si ya había una pregunta en curso se repite al terminar: pudo salir antes del guardado.
    // Mientras tanto la copia de ese dominio no está al día (ver alDia).
    function alGuardar() {
        return Promise.all(enUso().map((d) => {
            const c = copias[d];
            const p = c.enCurso ? c.enCurso.then(() => sincronizar(d)) : sincronizar(d);
            c.trasGuardar = p;
            return p.finally(() => { if (c.trasGuardar === p) c.trasGuardar = null; });
        }));
    }

    setInterval(() => revisar(false), TICK_MS);
    window.addEventListener('focus', () => revisar(true));
    window.addEventListener('online', () => revisar(true));
    document.addEventListener('visibilitychange', () => revisar(true));

    window.CopiaLocal = {
        usar(d, ancla) {
            const c = copia(d);
            if (ancla) c.anclas.add(ancla);
            return cargar(d).then(() => {
                // La primera vez (sin copia) se espera a la descarga; con copia se pinta ya
                // y la puesta al día llega por el evento.
                const pendiente = sincronizar(d);
                return (c.estado.listo ? Promise.resolve() : pendiente).then(() => c.vista);
            });
        },
        ahora: sincronizar,
        alGuardar,
        alDia(d) {
            return copia(d).trasGuardar || Promise.resolve();
        },
    };
})();
