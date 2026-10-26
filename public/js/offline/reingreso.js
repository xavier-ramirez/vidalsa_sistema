/**
 * Pase de reingreso — window.Reingreso (App\Support\PaseReingreso).
 *
 * Quien entra SIN conexión ("Entrar sin conexión") comprueba su clave en el equipo y el
 * servidor no se entera: allá no tiene sesión. Al volver la red, en vez de mandarlo otra vez
 * al inicio de sesión estando ya dentro, se canjea un pase que el servidor dio cuando sí
 * había sesión, y lo pendiente sube solo.
 *
 * El pase se guarda CIFRADO con la llave local (localStorage 'vidalsa_pases', uno por
 * usuario): sin la clave no se puede leer. Solo se usa si en ESTA ventana se entró sin
 * servidor (LlaveLocal.entroSinServidor): una sesión que se cerró por tiempo, con la llave
 * todavía abierta, sigue yendo al inicio de sesión.
 *
 *   Reingreso.reanudar() → Promise<boolean>. Recupera la sesión con el pase. Lo llama
 *       fetch_interceptor.js ante un 401/419, antes de mandar al inicio de sesión.
 *   Con sesión, al cargar la página, se pide un pase nuevo una vez al día (el anterior se
 *   borra en el servidor).
 */
(function () {
    'use strict';
    if (window.Reingreso) return;

    const GUARDADOS = 'vidalsa_pases';
    const RENOVAR_MS = 24 * 3600 * 1000;
    // Lo que tarda en llegar la respuesta de las peticiones que salieron antes de reanudar:
    // su 401 ya no dice que la sesión falte.
    const RECIEN_MS = 30000;
    const usuario = () => (document.querySelector('meta[name="usuario-id"]') || {}).content || '';
    // Sin el interceptor: un 401 de aquí no tiene que llevar a ningún lado.
    const crudo = (url, opciones) => (window.fetchSinInterceptor || window.fetch)(url, Object.assign({ credentials: 'same-origin', cache: 'no-store' }, opciones));

    // cifrar() da binarios (iv, ct): en localStorage van en base64.
    const b64 = (buf) => btoa(String.fromCharCode.apply(null, new Uint8Array(buf)));
    const deB64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
    const todos = () => { try { return JSON.parse(localStorage.getItem(GUARDADOS) || '{}') || {}; } catch (e) { return {}; } };
    function guardarTodos(t) { try { localStorage.setItem(GUARDADOS, JSON.stringify(t)); } catch (e) {} }

    async function guardar(pase) {
        const t = todos();
        const c = await window.LlaveLocal.cifrar(pase);
        t[usuario()] = { sello: { iv: b64(c.iv), ct: b64(c.ct) }, emitido: Date.now() };
        guardarTodos(t);
    }
    function olvidar() {
        const t = todos();
        delete t[usuario()];
        guardarTodos(t);
    }
    async function leer() {
        const g = todos()[usuario()];
        if (!g) return null;
        try { return await window.LlaveLocal.descifrar({ iv: deB64(g.sello.iv), ct: deB64(g.sello.ct) }); } catch (e) { return null; }
    }
    const enviar = (url, csrf, cuerpo) => crudo(url, {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(cuerpo),
    });

    // ── Con sesión: tener el pase al día ─────────────────────────────────────
    async function renovar() {
        if (!usuario() || !window.LlaveLocal || !navigator.onLine || window.LlaveLocal.entroSinServidor()) return;
        const g = todos()[usuario()];
        if (g && Date.now() - g.emitido < RENOVAR_MS) return;
        if (!(await window.LlaveLocal.lista())) return;
        const anterior = g ? await leer() : null;
        const r = await enviar('/sesion/pase', window.getCsrf ? window.getCsrf() : '', { anterior });
        if (r.ok) await guardar((await r.json()).pase);
    }

    // ── Sin sesión y entrado sin servidor: recuperarla ───────────────────────
    let enCurso = null;
    let reanudada = 0;
    async function intentar() {
        if (!window.LlaveLocal || !window.LlaveLocal.entroSinServidor()) return false;
        const pase = await leer();
        if (!pase) return false;
        const tk = await crudo('/refresh-csrf');
        if (!tk.ok) return false;
        const r = await enviar('/sesion/reanudar', (await tk.text()).trim(), { pase });
        if (!r.ok) {
            if (r.status === 422) olvidar();   // gastado o ya no sirve: el inicio de sesión dará otro
            return false;
        }
        const b = await r.json();
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && b.csrf) meta.setAttribute('content', b.csrf);
        window.LlaveLocal.conServidor();
        await guardar(b.pase);
        reanudada = Date.now();
        return true;
    }

    window.Reingreso = {
        reanudar() {
            if (Date.now() - reanudada < RECIEN_MS) return Promise.resolve(true);
            if (!enCurso) enCurso = intentar().catch(() => false).finally(() => { enCurso = null; });
            return enCurso;
        },
    };

    const arrancar = () => { renovar().catch(() => {}); };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
    else arrancar();
})();
