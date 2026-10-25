// Las páginas que guarda el service worker (resources/sw.js) son de UN usuario: si entra
// otro se tiran; lo guardado sin dueño se adopta. Corre el sw.js real con caches/self/fetch simulados.
// Lo lanza tests/Feature/ServiceWorkerPaginasPorUsuarioTest.php.
const fs = require('fs');
const assert = require('assert');
const fuente = fs.readFileSync(require('path').join(__dirname, '..', 'resources', 'sw.js'), 'utf8').replace('__CACHE_VERSION__', 'v2');

const ORIGEN = 'https://vidalsa.test';
const almacen = new Map();   // nombre -> Map(url -> Response)
const norm = (r) => new URL(typeof r === 'string' ? r : r.url, ORIGEN).toString();
function abrir(nombre) {
    if (!almacen.has(nombre)) almacen.set(nombre, new Map());
    const m = almacen.get(nombre);
    return {
        match: async (r) => { const x = m.get(norm(r)); return x ? x.clone() : undefined; },
        put: async (r, res) => { m.set(norm(r), res); },
        delete: async (r) => m.delete(norm(r)),
        keys: async () => [...m.keys()].map((u) => new Request(u)),
        addAll: async () => {},
    };
}
global.caches = {
    open: async (n) => abrir(n),
    keys: async () => [...almacen.keys()],
    delete: async (n) => almacen.delete(n),
    match: async (r) => { for (const n of almacen.keys()) { const x = await abrir(n).match(r); if (x) return x; } },
};
const oyentes = {};
global.self = { location: { origin: ORIGEN }, addEventListener: (t, f) => { oyentes[t] = f; }, skipWaiting() {}, clients: { claim() {} } };
const descargados = [];
global.fetch = async (u) => { descargados.push(new URL(typeof u === 'string' ? u : u.url, ORIGEN).pathname);
    return new Response('<html>pagina</html>', { status: 200, headers: { 'Content-Type': 'text/html' } }); };

new Function(fuente)();

async function mensaje(data) {
    let p;
    oyentes.message({ data, waitUntil: (x) => { p = x; } });
    await p;
}
const runtime = () => almacen.get('vidalsa-runtime-v2');
const rutas = () => [...runtime().keys()].map((u) => new URL(u).pathname + new URL(u).search).sort();
const html = (t) => new Response(t, { headers: { 'Content-Type': 'text/html' } });

(async () => {
    const c = abrir('vidalsa-runtime-v2');
    // Lo que dejó el usuario 7: login, módulos (uno de la SPA) y assets.
    await c.put('/', html('login'));
    await c.put('/admin/equipos', html('equipos de 7'));
    await c.put('/admin/equipos?__spa=1', html('corta de 7'));
    await c.put('/js/app.js?v=1', new Response('js'));
    await mensaje({ type: 'PAGINAS_SIN_RED', usuario: '7' });   // primera vez: sin dueño → se adopta
    assert.strictEqual(await (await c.match('/admin/equipos')).text(), 'equipos de 7', 'sin dueño no se tira nada');
    await c.put('/admin/fallas', html('fallas de 7'));

    // 1) El mismo usuario: no se tira nada (solo se bajan las que faltan).
    descargados.length = 0;
    await mensaje({ type: 'PAGINAS_SIN_RED', usuario: '7' });
    assert.ok(rutas().includes('/admin/fallas'), 'el mismo dueño conserva sus páginas');
    assert.ok(!descargados.includes('/admin/fallas'), 'no vuelve a bajar lo que ya tiene');

    // 2) Entra OTRO: lo de 7 se va, el login y los assets se quedan, y se baja lo del nuevo.
    await mensaje({ type: 'PAGINAS_SIN_RED', usuario: '9' });
    assert.strictEqual(await (await c.match('/admin/fallas'))?.text(), '<html>pagina</html>', 'la de 7 se sustituye por la de 9');
    assert.ok(!rutas().includes('/admin/equipos?__spa=1'), 'la copia corta de 7 también se va');
    assert.ok(rutas().includes('/') && rutas().includes('/js/app.js?v=1'), 'login y assets se quedan');
    assert.strictEqual(await (await c.match('/__dueno-paginas')).text(), '9');

    // 3) Sin usuario (página sin sesión) no se tira nada.
    await c.put('/admin/almacen', html('x'));
    await mensaje({ type: 'PAGINAS_SIN_RED', usuario: '' });
    assert.ok(rutas().includes('/admin/almacen'));

    console.log('ok');
})().catch((e) => { console.error(e); process.exit(1); });
