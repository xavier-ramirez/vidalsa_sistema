// Prueba de public/js/maquinaria/recepcion_entrada.js (Entrada por ODC, /admin/almacen/recepcion/nueva).
//   node tests/recepcion_entrada_js.cjs   → "ok" o el primer fallo (código de salida 1).
// Cubre que el resultado NO dependa del orden de las acciones:
//   · UM: Enter en el campo y agregar sin Enter dan la misma UM («m» → M, no AMPOLLA; «galon» → GALÓN).
//   · Nombre exacto de un producto que ya existe, sin elegir la sugerencia → usa ese, no lo duplica.
//   · Filtros con el mismo nombre (se distinguen por nº de parte) → no se adivina cuál, ni elegido
//     ni escrito a mano, ni en su UM ni en otra.
//   · Cancelar mientras se crea un producto → la línea no vuelve a la tabla cancelada.
//   · Empezar el siguiente producto mientras se crea el anterior → la barra ya está vacía y no se
//     borra lo que se escribe; si la creación falla, la barra vuelve como estaba.
//   · Volver a montar la pantalla (SPA) o irse a otro módulo con una creación pendiente → nada.
const fs = require('fs');
const path = require('path');
const assert = require('assert');

// ── DOM mínimo: solo lo que el módulo usa ─────────────────────────────────────
function elemento(id) {
    const clases = new Set();
    return {
        id, value: '', hidden: false, textContent: '', innerHTML: '', disabled: false, style: {}, isConnected: true,
        classList: {
            add: (c) => clases.add(c), remove: (c) => clases.delete(c),
            contains: (c) => clases.has(c), toggle: (c, on) => (on ? clases.add(c) : clases.delete(c)),
        },
        focus() {}, getAttribute() { return null; },
    };
}
const ids = ['entLineasTbody', 'entSearch', 'entSuggest', 'entSelectedBadge', 'entSelectedFoto', 'entSelectedCod', 'entSelectedNom',
    'entUm', 'entUmSuggest', 'entCant', 'entError', 'entDocError', 'entDocOverlay', 'entAlmacen', 'entResLineas',
    'entResUm', 'entEmpty', 'entFecha', 'entNotaEntrega', 'entProveedor', 'entSubmit'];
let dom = {};
function montarDom() {
    Object.values(dom).forEach((e) => { e.isConnected = false; });   // la página anterior sale
    dom = {};
    ids.forEach((i) => { dom[i] = elemento(i); });
    dom.entAlmacen.value = '1';
    dom.entDocOverlay.hidden = true;
    dom.entUm.value = 'UND';
}
global.document = {
    getElementById: (i) => dom[i] || null,
    querySelector: () => null,
    addEventListener: () => {},
    removeEventListener: () => {},
};
global.window = global;
// Los setTimeout del módulo (foco a Cantidad) se encolan y corren en tick(), no en línea.
const timers = [];
global.setTimeout = (f) => { timers.push(f); };

// ── Lo que da el layout ───────────────────────────────────────────────────────
eval(fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'maquinaria', 'fuzzy_search.js'), 'utf8'));
window.escapeHtml = (s) => String(s == null ? '' : s);
const toasts = [];
window.toast = (m, t) => { toasts.push([t || 'success', m]); return true; };
window.hoyISO = () => '2026-11-23';
window.confirmarAccion = (o, si) => si();
window.RecepcionDespacho = { montar: () => ({ abrir() {}, reiniciar() {} }) };
window.addEventListener = () => {};
eval(fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'maquinaria', 'almacen_foto.js'), 'utf8'));   // miniatura de cada línea

const CATALOGO = [
    { ID_PRODUCTO: 1, CODIGO: '000001', NOMBRE: 'TUBO PVC 1/2"', UM: 'UND', CATEGORIA: 'TUBERIA' },
    { ID_PRODUCTO: 2, CODIGO: '000002', NOMBRE: 'FILTRO DE AIRE', UM: 'UND', CATEGORIA: 'FILTROS', PARTE: 'P1' },
    { ID_PRODUCTO: 3, CODIGO: '000003', NOMBRE: 'FILTRO DE AIRE', UM: 'UND', CATEGORIA: 'FILTROS', PARTE: 'P2' },
    { ID_PRODUCTO: 4, CODIGO: '000004', NOMBRE: 'GUANTE', UM: 'PAR', CATEGORIA: 'EPP' },
];
// Creaciones de producto que esperan respuesta: se resuelven a mano para ordenar los hechos.
let pendientes = [];
let siguienteId = 100;
let falla = false;        // la próxima creación responde con error
let ultimoBody = null;    // lo último que se pidió crear
window.apiFetch = (url, opts) => {
    if (url === 'AUTO') return Promise.resolve({ ok: true, json: () => Promise.resolve(CATALOGO.map((p) => ({ ...p }))) });
    if (url === 'PROD') {
        const body = ultimoBody = JSON.parse(opts.body);
        if (falla) {
            falla = false;
            return new Promise((res) => pendientes.push(() => res({ ok: false, json: () => Promise.resolve({ message: 'Falló' }) })));
        }
        return new Promise((res) => pendientes.push(() => {
            const p = { ID_PRODUCTO: siguienteId++, CODIGO: '000' + siguienteId, NOMBRE: body.NOMBRE, UM: body.UM };
            res({ ok: true, json: () => Promise.resolve({ producto: p }) });
        }));
    }
    throw new Error('ruta inesperada ' + url);
};
const tick = () => new Promise((r) => setImmediate(() => { timers.splice(0).forEach((f) => f()); r(); }));
async function responder() { const f = pendientes.shift(); f(); for (let i = 0; i < 5; i++) await tick(); }

eval(fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'maquinaria', 'recepcion_entrada.js'), 'utf8'));
const CFG = { rutaAlmacenProductosAutocomplete: 'AUTO', rutaAlmacenProductosStore: 'PROD',
    unidadesMedida: ['AMPOLLA', 'CAJA', 'GALÓN', 'METRO', 'PAILA', 'PAQUETE', 'PAR', 'ROLLO', 'UND'],
    separaProyectos: false, idFrenteDestino: null, notaSalida: {} };
async function montar() { montarDom(); window.recepcionEntradaArrancar(CFG); await tick(); await tick(); }

const lineas = () => Number(dom.entResLineas.textContent);
const tabla = () => dom.entLineasTbody.innerHTML;
// Elegir una sugerencia: el clic que atiende el oyente de document.
function elegir(p) {
    const item = { getAttribute: (a) => ({ 'data-id': String(p.ID_PRODUCTO), 'data-cod': p.CODIGO, 'data-nom': p.NOMBRE,
        'data-um': p.UM, 'data-cat': p.CATEGORIA || '' })[a] };
    item.closest = (sel) => (sel.indexOf('ent-suggest-item') !== -1 && sel.indexOf('entUm') === -1 ? item : null);
    window.__entDocClick({ target: item, preventDefault() {} });
}
const enter = { key: 'Enter', preventDefault() {} };

(async () => {
    // 1) UM: el mismo resultado con Enter en el campo o agregando directo. Nunca se completa
    //    una UM a medias («rol» puede ser nueva, no ROLLO).
    await montar();
    for (const [escrito, esperado] of [['m', 'M'], ['galon', 'GALÓN'], [' Galón ', 'GALÓN'], ['gal', 'GAL'], ['rol', 'ROL'], ['par', 'PAR']]) {
        dom.entUm.value = escrito; window.entUmKey(enter);
        assert.strictEqual(dom.entUm.value, esperado, 'Enter en UM: ' + escrito);
    }
    // Sin Enter: escribir «galon» y agregar un producto nuevo → se pide con GALÓN, igual que con Enter.
    dom.entSearch.value = 'CABLE NUEVO'; dom.entUm.value = 'galon'; dom.entCant.value = '3';
    window.entAgregar();
    assert.strictEqual(ultimoBody.UM, 'GALÓN'); await responder();
    assert.ok(/CABLE NUEVO/.test(tabla()) && /GALÓN/.test(tabla()), 'UM resuelta al agregar: ' + tabla());
    // Una UM nueva se guarda tal cual («rol» → ROL, no ROLLO)…
    dom.entSearch.value = 'CINTA NUEVA'; dom.entUm.value = 'rol'; dom.entCant.value = '1'; window.entAgregar();
    assert.strictEqual(ultimoBody.UM, 'ROL'); await responder();
    // …y desde ahí se reconoce: elegir el producto con su UM no crea otra presentación.
    dom.entUm.value = 'rol'; window.entUmKey(enter); assert.strictEqual(dom.entUm.value, 'ROL');
    window.entClearSelected(); elegir({ ID_PRODUCTO: siguienteId - 1, CODIGO: 'x', NOMBRE: 'CINTA NUEVA', UM: 'ROL' });
    dom.entCant.value = '2'; window.entAgregar();
    assert.strictEqual(pendientes.length, 0, 'misma UM: no crea nada'); assert.strictEqual(lineas(), 2);

    // 2) Nombre exacto de uno que ya existe, sin elegir la sugerencia → ese mismo, sin crear.
    await montar();
    dom.entSearch.value = '  tubo  pvc 1/2"'; dom.entUm.value = 'und'; dom.entCant.value = '2';
    window.entAgregar();
    assert.strictEqual(pendientes.length, 0, 'no crea un duplicado');
    assert.strictEqual(lineas(), 1); assert.ok(/000001/.test(tabla()));
    // El mismo producto nuevo dos veces: la segunda usa el que creó la primera.
    dom.entSearch.value = 'MANGUERA X'; dom.entCant.value = '1'; window.entAgregar(); await responder();
    dom.entSearch.value = 'manguera x'; dom.entCant.value = '1'; window.entAgregar();
    assert.strictEqual(pendientes.length, 0, 'la segunda vez no crea otro');
    assert.strictEqual(lineas(), 2, 'suma en la misma línea');

    // 3) Filtros con el mismo nombre: no se adivina cuál.
    await montar();
    elegir(CATALOGO[1]); dom.entUm.value = 'CAJA'; dom.entCant.value = '1';
    window.entAgregar();
    assert.strictEqual(pendientes.length, 0, 'no crea una presentación sin nº de parte');
    assert.strictEqual(lineas(), 0); assert.ok(/nº de parte/.test(dom.entError.textContent));
    for (const um of ['UND', 'CAJA']) {   // escrito a mano, en su UM y en otra
        window.entClearSelected();
        dom.entSearch.value = 'filtro de aire'; dom.entUm.value = um; dom.entCant.value = '1';
        window.entAgregar();
        assert.strictEqual(pendientes.length, 0, 'a mano en ' + um); assert.strictEqual(lineas(), 0);
        assert.ok(/nº de parte/.test(dom.entError.textContent));
    }
    // En su UM sí entra normal (elegido de la lista).
    window.entClearSelected(); elegir(CATALOGO[2]); dom.entCant.value = '4'; window.entAgregar();
    assert.strictEqual(lineas(), 1); assert.ok(/000003/.test(tabla()));
    // Un producto de nombre único sí puede cambiar de presentación, elegido o escrito.
    elegir(CATALOGO[0]); dom.entUm.value = 'caja'; dom.entCant.value = '1'; window.entAgregar();
    assert.strictEqual(pendientes.length, 1, 'crea la presentación CAJA'); await responder();
    assert.strictEqual(lineas(), 2);
    dom.entSearch.value = 'guante'; dom.entUm.value = 'CAJA'; dom.entCant.value = '1'; window.entAgregar();
    assert.strictEqual(pendientes.length, 1, 'GUANTE en CAJA'); await responder();

    // 4) Cancelar mientras se crea: la línea no vuelve.
    await montar();
    dom.entSearch.value = 'PRODUCTO A'; dom.entCant.value = '1'; window.entAgregar();
    window.entCancelar();
    await responder();
    assert.strictEqual(lineas(), 0, 'tabla cancelada sigue vacía'); assert.strictEqual(tabla(), '');
    assert.ok(toasts.some(([t, m]) => t === 'info' && /se vació/.test(m)));
    // …y el producto quedó en el catálogo: escribirlo otra vez no lo duplica.
    dom.entSearch.value = 'producto a'; dom.entCant.value = '1'; window.entAgregar();
    assert.strictEqual(pendientes.length, 0); assert.strictEqual(lineas(), 1);

    // 5) Empezar el siguiente mientras se crea el anterior: la barra ya está vacía y no se borra.
    await montar();
    dom.entSearch.value = 'PRODUCTO B'; dom.entCant.value = '10'; window.entAgregar();
    assert.strictEqual(dom.entSearch.value, '', 'la barra se vacía al pedirlo');
    assert.strictEqual(dom.entCant.value, '', 'la cantidad también: no se pega a la siguiente');
    elegir(CATALOGO[3]); dom.entCant.value = '5';
    await responder();
    assert.strictEqual(lineas(), 1, 'la línea nueva entra');
    assert.ok(dom.entSelectedBadge.classList.contains('show'), 'el producto elegido sigue elegido');
    assert.strictEqual(dom.entCant.value, '5', 'la cantidad escrita sigue');
    window.entAgregar();
    assert.strictEqual(lineas(), 2, 'y se agrega normal'); assert.ok(/000004/.test(tabla()));
    // Si la creación falla, la barra vuelve como estaba (nadie escribió encima)…
    falla = true;
    dom.entSearch.value = 'PRODUCTO E'; dom.entUm.value = 'CAJA'; dom.entCant.value = '7'; window.entAgregar();
    assert.strictEqual(dom.entSearch.value, '');
    await responder();
    assert.strictEqual(dom.entSearch.value, 'PRODUCTO E'); assert.strictEqual(dom.entCant.value, '7');
    assert.strictEqual(dom.entUm.value, 'CAJA'); assert.strictEqual(dom.entError.textContent, 'Falló');
    // …también un producto elegido al que se le cambió la UM…
    falla = true;
    window.entClearSelected(); elegir(CATALOGO[0]); dom.entUm.value = 'PAILA'; dom.entCant.value = '3'; window.entAgregar();
    assert.ok(!dom.entSelectedBadge.classList.contains('show'));
    await responder();
    assert.ok(dom.entSelectedBadge.classList.contains('show'), 'vuelve elegido');
    assert.strictEqual(dom.entUm.value, 'PAILA'); assert.strictEqual(dom.entCant.value, '3');
    // …y si ya se escribió el siguiente, no se pisa.
    window.entClearSelected(); dom.entSearch.value = 'PRODUCTO G'; dom.entCant.value = '1';
    falla = true; window.entAgregar();
    dom.entSearch.value = 'OTRO'; await responder();
    assert.strictEqual(dom.entSearch.value, 'OTRO');

    // 6) Volver a montar con una creación pendiente: la respuesta vieja no pinta en la tabla nueva.
    await montar();
    dom.entSearch.value = 'PRODUCTO D'; dom.entCant.value = '1'; window.entAgregar();
    await montar();
    await responder();
    assert.strictEqual(lineas(), 0); assert.strictEqual(tabla(), '');
    // Irse a OTRO módulo (la tabla sale de la página sin montar otra): sin errores ni avisos.
    dom.entSearch.value = 'PRODUCTO F'; dom.entCant.value = '1'; window.entAgregar();
    Object.values(dom).forEach((e) => { e.isConnected = false; });
    const avisos = toasts.length;
    await responder();
    assert.strictEqual(toasts.length, avisos, 'ningún aviso de una pantalla que ya no está');

    console.log('ok');
})().catch((e) => { console.error(e); process.exit(1); });
