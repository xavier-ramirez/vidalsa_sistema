// Prueba de public/js/maquinaria/nota_entrega_local.js en Node: la Nota se arma SIN internet.
//   node tests/nota_sin_red_js.cjs   → "ok" o el primer fallo (código de salida 1).
// Cubre: con red, prepararSinRed deja la librería del PDF y el logo en su caché propia
// ('nota-sin-red-v1', fuera de la limpieza del service worker) y no los vuelve a bajar; sin red
// y sin service worker que los entregue, generar() los lee de esa caché y saca un PDF real.
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const PUB = path.join(__dirname, '..', 'public');
const archivo = (u) => fs.readFileSync(path.join(PUB, u.split('?')[0]));

// Caché del navegador simulada: varias cachés con nombre, caches.match busca en todas.
const cajas = new Map();
const caja = (n) => {
    if (!cajas.has(n)) {
        const m = new Map();
        cajas.set(n, { _m: m, match: async (u) => m.get(u), put: async (u, r) => { m.set(u, r); } });
    }
    return cajas.get(n);
};
global.caches = {
    open: async (n) => caja(n),
    match: async (u) => { for (const c of cajas.values()) { const r = await c.match(u); if (r) return r; } return undefined; },
};

let red = true;
const bajadas = [];
global.fetch = async (u) => {
    if (!red) throw new TypeError('Failed to fetch');
    bajadas.push(u);
    return new Response(archivo(u), { status: 200 });
};
Object.defineProperty(global, 'navigator', { value: { get onLine() { return red; } }, configurable: true });
const blobs = new Map();
const scripts = [];
global.window = {
    pdfEsMovil: () => false,
    // Como el <script src>: sin red falla; un blob: (la copia guardada) se ejecuta.
    cargarScriptUnaVez: async (src) => {
        scripts.push(src);
        if (!src.startsWith('blob:') && !red) throw new Error('No se pudo cargar ' + src);
        const codigo = src.startsWith('blob:') ? await blobs.get(src).text() : archivo(src).toString('utf8');
        new Function(codigo).call(globalThis);
        window.PDFLib = globalThis.PDFLib;
    },
};
URL.createObjectURL = (b) => { const u = 'blob:prueba-' + blobs.size; blobs.set(u, b); return u; };
URL.revokeObjectURL = () => {};
const api = require(path.join(PUB, 'js', 'maquinaria', 'nota_entrega_local.js'));

(async () => {
    // 1) Con red: quedan en su caché propia, una sola vez.
    await api.prepararSinRed();
    const propia = cajas.get('nota-sin-red-v1');
    assert.ok(propia, 'caché nota-sin-red-v1 creada');
    assert.deepStrictEqual([...propia._m.keys()].sort(), ['/img/imagen_uno.jpg?v=1', '/js/vendor/pdf-lib-1.17.1.min.js?v=1.17.1']);
    bajadas.length = 0;
    await api.prepararSinRed();
    assert.deepStrictEqual(bajadas, [], 'no repite la descarga');

    // 2) Sin red y sin service worker: la Nota sale igual, con la librería y el logo guardados.
    red = false;
    const datos = {
        formato: 'VERTICAL', numero: 'NE-2026-0001', proyecto: 'PRUEBA', fecha: '19/11/2026', hora: '07:05 AM',
        almacen: 'ALMACEN PRUEBA', firmantes: [{ rol: 'ENTREGADO', nombre: 'A' }, { rol: 'RECIBIDO', nombre: '' }],
        lineas: [{ clave: 1, cantidad: 2, um: 'UND', nombre: 'TORNILLO', codigo: 'C1', numero_parte: '', destino: '' }],
    };
    const blob = await api.generar(datos);
    const bytes = new Uint8Array(await blob.arrayBuffer());
    assert.strictEqual(blob.type, 'application/pdf');
    assert.strictEqual(new TextDecoder().decode(bytes.slice(0, 5)), '%PDF-', 'es un PDF');
    const sinLogo = await api.construir(window.PDFLib, datos, null);
    assert.ok(bytes.length > sinLogo.length + 5000, 'lleva el logo (' + bytes.length + ' vs ' + sinLogo.length + ' bytes sin él)');
    assert.ok(scripts.some((s) => s.startsWith('blob:')), 'la librería salió de la caché');

    console.log('ok');
})().catch((e) => { console.error(e); process.exit(1); });
