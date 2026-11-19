// Prueba de public/js/offline/llave-local.js y offline-auth.js en Node (WebCrypto real).
//   node tests/sesion_sin_red_js.cjs   → "ok" o el primer fallo (código de salida 1).
// Cubre: cambió la APP_KEY y se entra con huella → la huella recupera la llave de antes (lo
// guardado sin subir se sigue leyendo); olvidar las huellas de un usuario no toca las de otro;
// entrar B con el candado sin internet de A no tira el de A ni ninguna huella; si es el MISMO
// usuario con la clave cambiada, se tira su candado y solo sus huellas.
const fs = require('fs');
const path = require('path');
const assert = require('assert');

function almacen() {
    const m = new Map();
    return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k), _m: m };
}
global.localStorage = almacen();
global.sessionStorage = almacen();
let usuarioMeta = '5';
global.document = {
    querySelector: (s) => (s === 'meta[name="usuario-id"]' ? { content: usuarioMeta } : null),
    getElementById: () => null,
};
global.location = { pathname: '/menu', search: '', replace() {}, hostname: 'localhost' };
global.window = global;
Object.defineProperty(global, 'navigator', { value: { onLine: true }, configurable: true });

const sub = crypto.subtle;
const b64 = (buf) => Buffer.from(new Uint8Array(buf)).toString('base64');
const aes = (raw) => sub.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
async function envolver(kek, dek) {
    const iv = crypto.getRandomValues(new Uint8Array(12));
    return { iv: b64(iv), ct: b64(await sub.encrypt({ name: 'AES-GCM', iv }, await aes(kek), dek)) };
}
const rnd = (n) => crypto.getRandomValues(new Uint8Array(n));

const js = path.join(__dirname, '..', 'public', 'js', 'offline');
let llaveServidor = rnd(32);
global.fetch = async (url) => {
    assert.strictEqual(url, '/sync/llave');
    return { ok: true, json: async () => ({ llave: b64(llaveServidor) }) };
};
eval(fs.readFileSync(path.join(js, 'llave-local.js'), 'utf8'));
const L = window.LlaveLocal;

(async () => {
    // ── 1) Cambió la APP_KEY, se entra con huella: la llave de antes se recupera ──
    const dek = rnd(32);
    const prf = rnd(32);
    const salt = b64(rnd(32));
    localStorage.setItem('vidalsa_llaves', JSON.stringify({ 5: { srv: await envolver(llaveServidor, dek) } }));
    localStorage.setItem('vidalsa_llaves_huella', JSON.stringify({
        credA: Object.assign({ u: '5', rpId: 'localhost', salt }, await envolver(prf, dek)),
        credB: Object.assign({ u: '6', rpId: 'localhost', salt: b64(rnd(32)) }, await envolver(rnd(32), rnd(32))),
    }));
    // Algo cifrado con la llave de siempre (lo pendiente en la bandeja).
    const iv = rnd(12);
    const guardado = { iv, ct: await sub.encrypt({ name: 'AES-GCM', iv }, await aes(dek), new TextEncoder().encode(JSON.stringify({ pendiente: 1 }))) };

    llaveServidor = rnd(32);   // APP_KEY nueva: la envoltura srv ya no abre
    sessionStorage.setItem('vidalsa_llave_pendiente_huella', JSON.stringify({ cred: 'credA', rpId: 'localhost', salt, kek: b64(prf) }));
    assert.ok(await L.lista(), 'abre la llave');
    assert.deepStrictEqual(await L.descifrar(guardado), { pendiente: 1 }, 'lo guardado antes se sigue leyendo');

    // ── 2) Olvidar las huellas de un usuario no toca las de otro ──
    L.olvidarHuellas('5');
    let hs = JSON.parse(localStorage.getItem('vidalsa_llaves_huella'));
    assert.deepStrictEqual(Object.keys(hs), ['credB']);
    L.olvidarHuellas();
    assert.strictEqual(localStorage.getItem('vidalsa_llaves_huella'), null, 'sin usuario: todas');

    // ── 3) offline-auth.sincronizar: el candado de OTRO usuario no se toca ──
    const llamadas = [];
    window.LlaveLocal = { confirmar: async () => {}, olvidarHuellas: (u) => llamadas.push(u === undefined ? 'todas' : String(u)), cerrar() {} };
    eval(fs.readFileSync(path.join(js, 'offline-auth.js'), 'utf8'));
    const A = window.OfflineAuth;
    const candadoA = { identifier: 'a@x', salt: '00', hash: '11', claveV: 'v-vieja', u: '6' };
    localStorage.setItem('vidalsa_offline_auth', JSON.stringify(candadoA));
    await A.sincronizar('v-de-B', 5);
    assert.deepStrictEqual(JSON.parse(localStorage.getItem('vidalsa_offline_auth')), candadoA, 'el candado de A se queda');
    assert.deepStrictEqual(llamadas, [], 'ninguna huella tirada');

    // ── 4) Mismo usuario, clave cambiada: se tira su candado y solo sus huellas ──
    localStorage.setItem('vidalsa_offline_auth', JSON.stringify(Object.assign({}, candadoA, { u: '5' })));
    await A.sincronizar('v-nueva', 5);
    assert.strictEqual(localStorage.getItem('vidalsa_offline_auth'), null);
    assert.deepStrictEqual(llamadas, ['5']);

    // ── 4b) A cambió su clave en otro equipo y el candado de aquí es de B: las huellas de A
    // se tiran igual (el servidor ya las borró), y el candado de B se queda.
    llamadas.length = 0;
    await A.sincronizar('a-v1', 6);
    const candadoB = { identifier: 'b@x', salt: '00', hash: '11', claveV: 'b-v1', u: '7' };
    localStorage.setItem('vidalsa_offline_auth', JSON.stringify(candadoB));
    await A.sincronizar('a-v2', 6);
    assert.deepStrictEqual(llamadas, ['6'], 'huellas de A tiradas');
    assert.deepStrictEqual(JSON.parse(localStorage.getItem('vidalsa_offline_auth')), candadoB, 'el candado de B se queda');

    // ── 5) Candado de antes (sin `u`): como siempre, se compara la versión ──
    llamadas.length = 0;
    localStorage.setItem('vidalsa_offline_auth', JSON.stringify({ identifier: 'a@x', salt: '00', hash: '11', claveV: 'v1' }));
    await A.sincronizar('v1', 5);
    assert.ok(localStorage.getItem('vidalsa_offline_auth'), 'misma versión: se queda');
    await A.sincronizar('v2', 5);
    assert.strictEqual(localStorage.getItem('vidalsa_offline_auth'), null);

    console.log('ok');
})().catch((e) => { console.error(e); process.exit(1); });
