/**
 * Bandeja de lo que se hace SIN INTERNET — window.Bandeja.
 *
 * Una operación (registrar una salida, cambiar el estado de un equipo, movilizar…) va al MISMO
 * endpoint de siempre, con o sin conexión: si la red no llega, se guarda aquí (IndexedDB
 * `vidalsa_bandeja`) y se manda sola cuando vuelve, en el orden en que se hizo. Cada una lleva
 * su clave (cabecera X-Clave-Operacion, App\Http\Middleware\OperacionUnica): si se corta la
 * red con la respuesta en camino y se manda otra vez, el servidor no la aplica dos veces.
 *
 *   Bandeja.enviar(url, { method, body }, meta) → Promise<{ ok, status, b, enBandeja }>
 *       body = objeto (va como JSON) o FormData (va como formulario, con sus archivos: los
 *       PDF quedan guardados en el equipo hasta que suben). meta = { titulo, tipo, datos }: lo
 *       que se ve en el panel y lo que las pantallas ponen encima de su copia mientras no suba
 *       (datos). Con conexión responde el servidor; sin ella resuelve { enBandeja: true }.
 *   Bandeja.pendientes() → array (síncrono): lo que todavía no subió.
 *   Bandeja.subir() → manda lo pendiente ya.
 *   Bandeja.sinConexion() → sin red, o la copia local no llegó al servidor la última vez.
 *   Bandeja.tomarNumeroNota() → el siguiente número de Nota apartado para este equipo (o null).
 *   Bandeja.numerosLibres() → cuántos números de Nota apartados le quedan a este equipo.
 *   Bandeja.apartarNumeros() → pide al servidor el bloque de números (con conexión).
 *   Evento 'bandeja' en window cada vez que cambia lo pendiente.
 *
 * Lo que el servidor rechaza (sin stock, falla abierta, sin permiso…) se queda en ROJO con su
 * motivo: no se vuelve a mandar solo. Desde el panel se puede reintentar o descartar. Un 5xx,
 * un 409 "en curso", la red caída o la sesión cerrada (401/419) se reintentan después, sin tocar
 * el orden.
 *
 * Una bandeja por usuario (meta usuario-id), igual que la copia local.
 *
 * Se guarda CIFRADA con la llave de llave-local.js, archivos incluidos: en el equipo solo queda
 * en claro de quién es, cuándo se hizo y su estado. Sin llave abierta se guarda tal cual antes
 * que perderlo, y se cifra la próxima vez que se lea. Si la sesión se cerró en el servidor
 * (401), lo que se guarda espera aquí como sin internet y sube al volver a entrar.
 */
(function () {
    'use strict';
    if (window.Bandeja) return;

    const DB_NOMBRE = 'vidalsa_bandeja';
    const CABECERA = 'X-Clave-Operacion';
    const RUTA_NOTAS = '/admin/almacen/notas-reservadas';
    const REINTENTO_MS = 30000;

    const usuario = () => (document.querySelector('meta[name="usuario-id"]') || {}).content || '';
    const toast = (m, t) => window.toast && window.toast(m, t);

    // ── IndexedDB: items { clave, u, url, metodo, cuerpo, formulario, titulo, tipo, datos, creado, estado, motivo }
    //    formulario = pares [nombre, valor] de un FormData (los File se guardan tal cual); cuerpo, si no.
    let dbPromesa = null;
    function db() {
        if (!dbPromesa) {
            dbPromesa = new Promise((resolve) => {
                let req;
                try { req = indexedDB.open(DB_NOMBRE, 1); } catch (e) { resolve(null); return; }
                req.onupgradeneeded = () => req.result.createObjectStore('items', { keyPath: 'clave' });
                req.onsuccess = () => resolve(req.result);
                req.onerror = req.onblocked = () => resolve(null);
            });
        }
        return dbPromesa;
    }
    const pedir = (req) => new Promise((ok, mal) => { req.onsuccess = () => ok(req.result); req.onerror = () => mal(req.error); });
    // Sin IndexedDB (ventana privada) la bandeja vive en memoria: se pierde al cerrar.
    const memoria = new Map();
    async function todos() {
        const b = await db();
        if (!b) return Array.from(memoria.values()).filter((it) => it.u === usuario()).sort((a, z) => a.creado - z.creado);
        const guardados = (await pedir(b.transaction('items').objectStore('items').getAll())).filter((r) => r.u === usuario());
        const lista = await Promise.all(guardados.map(abrir));
        // Lo que quedó en claro (de antes del cifrado, o guardado sin llave) se cifra ya.
        for (let i = 0; i < guardados.length; i++) {
            if (!guardados[i].sello && !lista[i].ilegible) await guardarItem(lista[i]);
        }
        return lista.sort((a, z) => a.creado - z.creado);
    }
    async function guardarItem(it) {
        const b = await db();
        if (b) {
            const registro = await sellar(it);   // se cifra ANTES de abrir la transacción
            await pedir(b.transaction('items', 'readwrite').objectStore('items').put(registro));
        } else memoria.set(it.clave, it);
    }

    // ── Cifrado: { clave, u, creado, estado, motivo } en claro; el resto en `sello` y los
    //    archivos del formulario, cada uno cifrado, en `archivos` ─────────────────────────
    const SELLADOS = ['url', 'metodo', 'cuerpo', 'formulario', 'titulo', 'tipo', 'datos'];
    const visible = (it) => ({ clave: it.clave, u: it.u, creado: it.creado, estado: it.estado, motivo: it.motivo });

    async function sellar(it) {
        const llave = window.LlaveLocal;
        if (!llave || !(await llave.lista())) return it;
        const contenido = {};
        SELLADOS.forEach((k) => { contenido[k] = it[k]; });
        const archivos = [];
        if (it.formulario) {
            contenido.formulario = [];
            for (const [k, v] of it.formulario) {
                if (!(v instanceof Blob)) { contenido.formulario.push([k, v]); continue; }
                archivos.push(await llave.cifrarBytes(await v.arrayBuffer()));
                contenido.formulario.push([k, { archivo: archivos.length - 1, nombre: v.name || 'archivo', tipo: v.type }]);
            }
        }
        return Object.assign(visible(it), { sello: await llave.cifrar(contenido), archivos });
    }

    async function abrir(registro) {
        if (!registro.sello) return registro;
        try {
            const llave = window.LlaveLocal;
            const c = await llave.descifrar(registro.sello);
            if (c.formulario) {
                for (const par of c.formulario) {
                    const v = par[1];
                    if (v && typeof v === 'object' && 'archivo' in v) {
                        par[1] = new File([await llave.descifrarBytes(registro.archivos[v.archivo])], v.nombre, { type: v.tipo });
                    }
                }
            }
            return Object.assign(visible(registro), c);
        } catch (e) {
            // No se puede abrir con la llave de ahora: se muestra en rojo y NO se toca lo guardado.
            return Object.assign(visible(registro), {
                estado: 'rechazada', ilegible: true, titulo: 'Cambio guardado sin internet',
                motivo: 'No se puede leer con la llave de ahora. Entra con tu clave para recuperarlo.',
            });
        }
    }
    async function borrarItem(clave) {
        const b = await db();
        if (b) await pedir(b.transaction('items', 'readwrite').objectStore('items').delete(clave));
        else memoria.delete(clave);
    }

    let cache = [];
    async function refrescar() {
        cache = await todos();
        window.dispatchEvent(new CustomEvent('bandeja', { detail: { pendientes: cache.length } }));
        pintarPanel();
    }

    const clave = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
        : 'op-' + Date.now().toString(16) + '-' + Math.random().toString(16).slice(2, 10);

    // ¿Llegó al servidor lo último que se le preguntó? Lo dicen la copia local (cada 15 s) y
    // lo que manda la propia bandeja.
    let conectado = true;
    function sinConexion() {
        return !navigator.onLine || !conectado;
    }

    function mandar(it) {
        const cabeceras = { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', [CABECERA]: it.clave };
        let cuerpo;
        if (it.formulario) {
            // Sin Content-Type: el navegador pone el multipart con su separador.
            cuerpo = new FormData();
            it.formulario.forEach(([k, v]) => cuerpo.append(k, v));
        } else {
            cabeceras['Content-Type'] = 'application/json';
            cuerpo = JSON.stringify(it.cuerpo || {});
        }
        return window.apiFetch(it.url, { method: it.metodo, headers: cabeceras, body: cuerpo })
            .then((r) => r.json().catch(() => ({})).then((b) => ({ r, b })));
    }

    // ── Enviar: al servidor si hay red; si no, a la bandeja ──────────────────
    async function enviar(url, opciones, meta) {
        meta = meta || {};
        const body = opciones && opciones.body;
        const esFormulario = typeof FormData !== 'undefined' && body instanceof FormData;
        const it = {
            clave: clave(), u: usuario(), url, metodo: String((opciones && opciones.method) || 'POST').toUpperCase(),
            cuerpo: esFormulario ? {} : (body || {}),
            // El _token del formulario se queda fuera: cuando suba ya será otro, y apiFetch
            // manda el vigente en la cabecera.
            formulario: esFormulario ? Array.from(body.entries()).filter(([k]) => k !== '_token') : null,
            titulo: meta.titulo || url, tipo: meta.tipo || '',
            datos: meta.datos || null, creado: Date.now(), estado: 'pendiente', motivo: '',
        };
        // Lo de antes va primero: si quedó algo en la bandeja, esto espera su turno.
        if (sinConexion() || cache.some((x) => x.estado === 'pendiente')) {
            await guardarItem(it);
            await refrescar();
            subir();
            return { ok: false, status: 0, b: {}, enBandeja: true };
        }
        try {
            const { r, b } = await mandar(it);
            conectado = true;
            // La sesión se cerró en el servidor (401, o 419 si ni el token nuevo sirvió): se
            // guarda aquí, sube al volver a entrar, y recién entonces al inicio de sesión.
            if (r.status === 401 || r.status === 419) {
                await guardarItem(it);
                await refrescar();
                window.irAlInicioDeSesion(r);
                return { ok: false, status: 0, b: {}, enBandeja: true };
            }
            return { ok: r.ok, status: r.status, b, enBandeja: false };
        } catch (e) {
            conectado = false;
            await guardarItem(it);
            await refrescar();
            return { ok: false, status: 0, b: {}, enBandeja: true };
        }
    }

    // ── Subir lo pendiente, en orden ─────────────────────────────────────────
    let subiendo = null;
    function subir() {
        if (!subiendo) subiendo = subirAhora().finally(() => { subiendo = null; });
        return subiendo;
    }
    async function subirAhora() {
        if (!navigator.onLine) return;
        let subidos = 0, rechazados = 0, sinSesion = null;
        for (const it of (await todos()).filter((x) => x.estado === 'pendiente')) {
            let res;
            try { res = await mandar(it); } catch (e) { conectado = false; break; }   // sin red: luego
            conectado = true;
            const { r, b } = res;
            if (r.ok) {
                await borrarItem(it.clave);
                subidos++;
                if (b && b.nota_url && window.descargarPdf) {
                    window.descargarPdf(b.nota_url, b.numero_nota ? 'Nota_' + b.numero_nota + '.pdf' : 'nota_entrega.pdf');
                }
            } else if (r.status === 401 || r.status === 419) {
                sinSesion = r;                                             // sube al volver a entrar
                break;
            } else if (r.status >= 500 || r.headers.get('X-Operacion-En-Curso')) {
                break;                                                     // reintento luego
            } else {
                rechazados++;
                await guardarItem(Object.assign({}, it, { estado: 'rechazada', motivo: motivoDe(b) }));
            }
        }
        await refrescar();
        // Las salidas que subieron gastaron números apartados: se repone el bloque.
        if (subidos && apartaNumeros()) apartarNumeros();
        if (subidos) toast(subidos === 1 ? 'Se subió 1 cambio hecho sin internet.' : 'Se subieron ' + subidos + ' cambios hechos sin internet.', 'success');
        if (rechazados) toast(rechazados === 1 ? '1 cambio no se pudo aplicar: revísalo en la bandeja.' : rechazados + ' cambios no se pudieron aplicar: revísalos en la bandeja.', 'error');
        if (sinSesion) window.irAlInicioDeSesion(sinSesion);
    }
    function motivoDe(b) {
        if (b && b.errors) return Object.values(b.errors).map((a) => [].concat(a).join(' ')).join(' ');
        return (b && b.message) || 'El servidor no lo aceptó.';
    }

    async function reintentar(c) {
        const it = cache.find((x) => x.clave === c);
        if (!it || it.ilegible) return;
        // Clave nueva: la vieja quedó guardada en el servidor con su rechazo.
        await borrarItem(c);
        await guardarItem(Object.assign({}, it, { clave: clave(), estado: 'pendiente', motivo: '' }));
        await refrescar();
        subir();
    }
    async function descartar(c) {
        const it = cache.find((x) => x.clave === c);
        await borrarItem(c);
        if (it && it.cuerpo && it.cuerpo.numero_nota_reservado) devolverNumero(it.cuerpo.numero_nota_reservado);
        await refrescar();
    }

    // ── Números de Nota apartados para este equipo (App\Support\NotasReservadas) ─
    function dispositivo() {
        try {
            let d = localStorage.getItem('vidalsa_dispositivo');
            if (!d) { d = clave(); localStorage.setItem('vidalsa_dispositivo', d); }
            return d;
        } catch (e) { return ''; }
    }
    const claveNumeros = () => 'vidalsa_notas_' + usuario();
    // Los números apartados de otro usuario que usó este equipo se borran: no le sirven a nadie
    // más y, si vuelve, el servidor le devuelve los suyos (apartarNumeros).
    function borrarNumerosDeOtros() {
        try {
            Object.keys(localStorage).forEach((k) => {
                if (k.indexOf('vidalsa_notas_') === 0 && k !== claveNumeros()) localStorage.removeItem(k);
            });
        } catch (e) { /* sin almacenamiento */ }
    }
    function numeros() {
        try { return JSON.parse(localStorage.getItem(claveNumeros()) || '[]'); } catch (e) { return []; }
    }
    // Solo quien ya apartó alguna vez (almacén con permiso de mover) los repone solo.
    function apartaNumeros() {
        try { return localStorage.getItem(claveNumeros()) !== null; } catch (e) { return false; }
    }
    function guardarNumeros(lista) {
        try { localStorage.setItem(claveNumeros(), JSON.stringify(lista)); } catch (e) { /* sin almacenamiento */ }
    }
    // Los libres según el servidor, menos los que ya lleva algo de la bandeja que no subió.
    function apartarNumeros() {
        const d = dispositivo();
        if (!d || sinConexion()) return Promise.resolve();
        return window.apiFetch(RUTA_NOTAS, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json' },
            body: JSON.stringify({ dispositivo: d }),
        }).then((r) => (r.ok ? r.json() : null)).then((b) => {
            if (!b || !Array.isArray(b.numeros)) return;
            const usados = new Set(cache.map((it) => it.cuerpo && it.cuerpo.numero_nota_reservado).filter(Boolean));
            guardarNumeros(b.numeros.filter((n) => !usados.has(n)));
        }).catch(() => {});
    }
    function tomarNumeroNota() {
        const lista = numeros();
        const n = lista.shift() || null;
        guardarNumeros(lista);
        return n;
    }
    function devolverNumero(n) {
        const lista = numeros();
        if (lista.indexOf(n) < 0) { lista.unshift(n); lista.sort(); guardarNumeros(lista); }
    }

    window.Bandeja = {
        enviar, subir, sinConexion, tomarNumeroNota, apartarNumeros,
        numerosLibres: () => numeros().length,
        pendientes: () => cache.slice(),
    };

    // ── Panel flotante (#outboxTray, en estructura_base) ─────────────────────
    const $ = (id) => document.getElementById(id);
    function pintarPanel() {
        const tray = $('outboxTray');
        if (!tray) return;
        if (!cache.length) {
            tray.style.display = 'none';
            $('outboxTrayPanel').style.display = 'none';
            return;
        }
        const esc = window.escapeHtml;
        tray.style.display = 'block';
        $('outboxTrayCount').textContent = cache.length;
        const hayRojo = cache.some((it) => it.estado === 'rechazada');
        $('outboxTrayBtn').style.background = hayRojo ? '#dc2626' : '#0067b1';
        $('outboxTrayList').innerHTML = cache.map((it) => {
            const rojo = it.estado === 'rechazada';
            const botones = rojo
                ? '<div style="display:flex;gap:8px;margin-top:6px;">'
                    + (it.ilegible ? '' : '<button data-reintentar="' + esc(it.clave) + '" style="background:#0067b1;color:#fff;border:none;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;">Reintentar</button>')
                    + '<button data-descartar="' + esc(it.clave) + '" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;border-radius:6px;padding:4px 9px;font-size:11px;font-weight:700;cursor:pointer;">Descartar</button></div>'
                : '';
            return '<div style="padding:11px 14px;border-bottom:1px solid #f1f5f9;">'
                + '<div style="font-size:12.5px;font-weight:700;color:#1e293b;">' + esc(it.titulo) + '</div>'
                + '<div style="font-size:11.5px;font-weight:700;color:' + (rojo ? '#dc2626' : '#0067b1') + ';margin-top:3px;">'
                + esc(rojo ? 'No se aplicó · ' + it.motivo : 'Por subir') + '</div>' + botones + '</div>';
        }).join('');
    }
    document.addEventListener('click', (e) => {
        const t = e.target;
        if (!t.closest) return;
        if (t.closest('#outboxTrayBtn')) {
            const p = $('outboxTrayPanel');
            p.style.display = p.style.display === 'none' ? 'block' : 'none';
            return;
        }
        if (t.closest('#outboxTraySubir')) { subir(); return; }
        const r = t.closest('[data-reintentar]');
        if (r) { reintentar(r.getAttribute('data-reintentar')); return; }
        const d = t.closest('[data-descartar]');
        if (d) { descartar(d.getAttribute('data-descartar')); return; }
        const panel = $('outboxTrayPanel');
        if (panel && panel.style.display === 'block' && !t.closest('#outboxTray')) panel.style.display = 'none';
    });

    // ── La cola del modo sin conexión anterior (IndexedDB vidalsa_offline) ────
    // Lo que quedó sin subir en un equipo con la versión vieja (cambio de estado o
    // movilización de equipos) pasa a la bandeja con los endpoints de siempre, y esa base se
    // borra. La movilización vieja guardaba el id del frente; el endpoint pide el nombre, que
    // venía en su rótulo («Movilizar N equipo(s) → NOMBRE»).
    const DB_VIEJA = 'vidalsa_offline';
    function heredarColaVieja() {
        return new Promise((listo) => {
            let req;
            try { req = indexedDB.open(DB_VIEJA); } catch (e) { listo(); return; }
            req.onupgradeneeded = () => req.transaction.abort();   // no existía: nada que heredar
            req.onerror = req.onblocked = () => listo();
            req.onsuccess = () => {
                const vieja = req.result;
                const borrar = () => { vieja.close(); try { indexedDB.deleteDatabase(DB_VIEJA); } catch (e) { /* queda para otra vez */ } };
                if (!vieja.objectStoreNames.contains('outbox')) { borrar(); listo(); return; }
                pedir(vieja.transaction('outbox').objectStore('outbox').getAll()).then(async (items) => {
                    for (const it of items || []) {
                        const n = aItemNuevo(it);
                        if (n) await guardarItem(n);
                    }
                    borrar();
                }).catch(() => vieja.close()).then(listo);
            };
        });
    }
    function aItemNuevo(it) {
        const p = it.payload || {};
        const base = { clave: clave(), u: usuario(), titulo: it.label || '', creado: it.created || Date.now(), estado: 'pendiente', motivo: '' };
        if (it.status === 'synced') return null;
        if (it.action === 'estado' && p.id_equipo) {
            return Object.assign(base, {
                url: '/admin/equipos/' + Number(p.id_equipo) + '/status', metodo: 'PATCH', cuerpo: { status: p.status },
                tipo: 'estado', datos: { id_equipo: Number(p.id_equipo), status: p.status },
            });
        }
        const destino = String(it.label || '').split('→ ')[1];
        if (it.action === 'movilizar' && Array.isArray(p.ids) && destino) {
            return Object.assign(base, {
                url: '/admin/equipos/bulk-mobilize', metodo: 'POST', cuerpo: { ids: p.ids, destination: destino.trim(), generar_pdf: false },
                tipo: 'movilizar', datos: { ids: p.ids, id_frente_destino: Number(p.id_frente_destino) },
            });
        }
        return null;
    }

    // ── Cuándo se sube: al abrir, al volver la red, cuando la copia vuelve a llegar al
    //    servidor y, mientras quede algo, cada 30 s. ─────────────────────────────
    window.addEventListener('online', () => subir());
    window.addEventListener('copia-local', (e) => {
        if (!e.detail || !e.detail.estado || !e.detail.estado.listo) return;
        conectado = !!e.detail.estado.conectado;
        if (conectado && cache.length) subir();
    });
    setInterval(() => { if (!document.hidden && cache.some((x) => x.estado === 'pendiente')) subir(); }, REINTENTO_MS);
    const arrancar = () => {
        borrarNumerosDeOtros();
        return heredarColaVieja().then(refrescar).then(() => { if (cache.length) subir(); });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
    else arrancar();
})();
