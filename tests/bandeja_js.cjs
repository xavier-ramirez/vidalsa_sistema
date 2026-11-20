// Prueba de public/js/offline/bandeja.js en Node, sin navegador: red y servidor simulados.
//   node tests/bandeja_js.cjs   → "ok" o el primer fallo (código de salida 1).
// Cubre: lo que queda guardado sin red sube marcado (marcarDiferido) y con su dueño
// (X-Usuario-Esperado); lo que ya subió deja de ponerse encima en medio de la tanda; descartar
// una salida con Nota ANULA el número (no vuelve a la lista); el panel deja volver a bajar la
// Nota; las demás pestañas se enteran (BroadcastChannel).
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const ls = new Map();
global.localStorage = {
    getItem: (k) => (ls.has(k) ? ls.get(k) : null), setItem: (k, v) => ls.set(k, String(v)),
    removeItem: (k) => ls.delete(k), key: (i) => Array.from(ls.keys())[i],
};
Object.defineProperty(localStorage, 'length', { get: () => ls.size });
Object.keys = ((orig) => (o) => (o === localStorage ? Array.from(ls.keys()) : orig(o)))(Object.keys);

const elementos = {};
const elemento = (id) => (elementos[id] = elementos[id] || { id, style: {}, innerHTML: '', textContent: '' });
['outboxTray', 'outboxTrayPanel', 'outboxTrayCount', 'outboxTrayBtn', 'outboxTrayList'].forEach(elemento);
const clics = [];
global.document = {
    readyState: 'complete', hidden: false,
    querySelector: (s) => (s === 'meta[name="usuario-id"]' ? { content: '7' } : null),
    getElementById: (id) => elementos[id] || null,
    addEventListener: (t, fn) => { if (t === 'click') clics.push(fn); },
};
const toasts = [];
const peticiones = [];
let responder = () => ({ status: 200, body: {} });
let red = true;
global.window = {
    addEventListener() {}, dispatchEvent() {},
    toast: (m, t) => toasts.push([m, t]),
    escapeHtml: (s) => String(s),
    irAlInicioDeSesion() {},
    apiFetch: async (url, op) => {
        if (!red) throw new TypeError('Failed to fetch');
        const p = { url, metodo: op.method, cabeceras: op.headers, cuerpo: op.body ? JSON.parse(op.body) : null };
        peticiones.push(p);
        const r = await responder(p);
        return { ok: r.status < 300, status: r.status, headers: { get: () => null }, json: async () => r.body };
    },
};
global.CustomEvent = class { constructor(t, o) { this.type = t; this.detail = o && o.detail; } };
Object.defineProperty(global, 'navigator', { value: { get onLine() { return red; } }, configurable: true });
global.setInterval = () => 0;

const escuchado = [];
const oyente = new BroadcastChannel('vidalsa-bandeja');
oyente.onmessage = (e) => escuchado.push(e.data);

eval(fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'offline', 'bandeja.js'), 'utf8'));
const B = window.Bandeja;
const esperar = (ms) => new Promise((r) => setTimeout(r, ms));
const clic = (attr, valor) => clics.forEach((fn) => fn({ target: { closest: (s) => (s === '[' + attr + ']' ? { getAttribute: () => valor } : null) } }));

(async () => {
    await esperar(10);

    // 1) Sin red: guardado, marcado como diferido solo si se pide.
    red = false;
    localStorage.setItem('vidalsa_notas_7', JSON.stringify(['NE-2026-0101', 'NE-2026-0102']));
    // Una salida de 3 Notas con 2 números: ninguno (medio juego dejaría papeles sin número).
    assert.deepStrictEqual(B.tomarNumerosNota(3), []);
    assert.strictEqual(B.numerosLibres(), 2, 'no gastó ninguno');
    const [n] = B.tomarNumerosNota(1);
    assert.strictEqual(n, 'NE-2026-0101');
    let r = await B.enviar('/admin/almacen/movimientos-lote', { method: 'POST', body: { tipo: 'AJUSTE', lineas: [] } }, { tipo: 'ajuste', marcarDiferido: true });
    assert.ok(r.enBandeja);
    await B.enviar('/admin/almacen/movimientos-lote', { method: 'POST', body: { tipo: 'SALIDA', numero_nota_reservado: n, lineas: [] } },
        { tipo: 'salida', titulo: 'Salida · Nota ' + n, datos: { nota: { numero: n, lineas: [] } } });
    const [aj, sal] = B.pendientes();
    assert.strictEqual(aj.cuerpo.en_diferido, true, 'la auditoría guardada sin red sube marcada');
    assert.strictEqual(sal.cuerpo.en_diferido, undefined, 'sin marcarDiferido no se toca el cuerpo');

    // 2) El panel ofrece volver a bajar la Nota mientras no suba.
    assert.ok(elementos.outboxTrayList.innerHTML.includes('data-nota="' + sal.clave + '"'), 'botón Nota en el panel');
    let bajada = null;
    // generar simulado; los números y el nombre del archivo, los del módulo de verdad.
    const notaLocal = require(path.join(__dirname, '..', 'public', 'js', 'maquinaria', 'nota_entrega_local.js'));
    window.NotaEntregaLocal = { generar: async (d) => ({ blob: d.numero }), numeros: notaLocal.numeros, archivo: notaLocal.archivo };
    window.descargarBlob = (b, nombre) => { bajada = nombre; };
    clic('data-nota', sal.clave);
    await esperar(5);
    assert.strictEqual(bajada, 'Nota_NE-2026-0101.pdf');

    // 3) Vuelve la red: sube en orden, con su dueño; lo subido deja de ponerse encima ya.
    red = true;
    let pendientesAlSubirLaSegunda = null;
    responder = (p) => {
        if (p.cuerpo && p.cuerpo.tipo === 'SALIDA') {
            pendientesAlSubirLaSegunda = B.pendientes().map((x) => x.cuerpo.tipo);
            return { status: 422, body: { message: 'Stock insuficiente.' } };
        }
        return { status: 201, body: {} };
    };
    await B.subir();
    assert.strictEqual(peticiones[0].cabeceras['X-Usuario-Esperado'], '7');
    assert.strictEqual(peticiones[0].cabeceras['X-Clave-Operacion'], aj.clave);
    assert.deepStrictEqual(pendientesAlSubirLaSegunda, ['SALIDA'], 'la auditoría ya subida no se resta otra vez');
    const rechazada = B.pendientes()[0];
    assert.strictEqual(rechazada.estado, 'rechazada');
    assert.ok(elementos.outboxTrayList.innerHTML.includes('descartarla ANULA esa Nota'), 'aviso en el panel');

    // 4) Descartar: el número queda anulado en el servidor y NO vuelve a la lista local.
    peticiones.length = 0;
    responder = () => ({ status: 200, body: { anulada: true } });
    clic('data-descartar', rechazada.clave);
    await esperar(10);
    assert.deepStrictEqual(JSON.parse(localStorage.getItem('vidalsa_notas_7')), ['NE-2026-0102'], 'el número no se devuelve');
    assert.strictEqual(peticiones[0].url, '/admin/almacen/notas-reservadas/anular');
    assert.deepStrictEqual(peticiones[0].cuerpo, { numero: 'NE-2026-0101' });
    assert.ok(toasts.some(([m]) => m.includes('NE-2026-0101 queda ANULADA')));
    assert.strictEqual(B.pendientes().length, 0);

    // 4b) Descartada SIN red: la anulación espera en la bandeja; si mientras tanto el servidor
    // todavía da ese número por libre, no vuelve a la lista (serían dos papeles con él).
    red = false;
    const [n2] = B.tomarNumerosNota(1);
    await B.enviar('/admin/almacen/movimientos-lote', { method: 'POST', body: { tipo: 'SALIDA', numero_nota_reservado: n2, lineas: [] } },
        { tipo: 'salida', titulo: 'Salida · Nota ' + n2 });
    const sal2 = B.pendientes().find((x) => x.cuerpo.numero_nota_reservado === n2);
    clic('data-descartar', sal2.clave);
    await esperar(10);
    assert.ok(B.pendientes().some((x) => x.tipo === 'anular-nota' && x.cuerpo.numero === n2), 'anulación en espera');
    red = true;
    responder = (p) => (p.url.includes('notas-reservadas') && !p.url.includes('anular')
        ? { status: 200, body: { numeros: [n2, 'NE-2026-0103'] } } : { status: 200, body: {} });
    await B.apartarNumeros();
    assert.deepStrictEqual(JSON.parse(localStorage.getItem('vidalsa_notas_7')), ['NE-2026-0103'], 'la anulada pendiente no vuelve');
    await B.subir();
    assert.strictEqual(B.pendientes().length, 0);

    // 5) Las demás pestañas se enteraron de los cambios.
    await esperar(10);
    assert.ok(escuchado.length > 0, 'BroadcastChannel avisa a las otras pestañas');

    console.log('ok');
    process.exit(0);
})().catch((e) => { console.error(e); process.exit(1); });
