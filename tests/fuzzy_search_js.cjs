// Prueba de public/js/maquinaria/fuzzy_search.js (sugerencias mientras se escribe).
//   node tests/fuzzy_search_js.cjs   → "ok" o el primer fallo (código de salida 1).
// Cubre: con { enteros: true } (productos) un número corto junto a otras palabras cuenta ENTERO, como en la tabla de Almacén
// (almacen_local.js · entero): «braga 42» pone la 42 primero y no la trae por la talla 142
// ni por el 2 de 1/2"; un número solo sigue buscando como trozo (códigos).
const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = {};
eval(fs.readFileSync(path.join(__dirname, '..', 'public', 'js', 'maquinaria', 'fuzzy_search.js'), 'utf8'));
const F = window.FuzzySearch;
const p = (c, n) => ({ c, n });
const items = [p('000140', 'BRAGA TALLA 40'), p('000142', 'BRAGA TALLA 142'), p('000420', 'BRAGA TALLA 42'),
    p('X1', 'MANGUERA 1/2"'), p('X2', 'MANGUERA 2"'), p('X3', 'CABLE 2,5 MM')];
const rank = (q, opts = { enteros: true }) => F.rank(items, q, (i) => ({ haystack: i.c + ' ' + i.n, label: i.n }), opts).map((i) => i.n);

for (const q of ['braga de 42', '42 braga', 'braga 42']) assert.strictEqual(rank(q)[0], 'BRAGA TALLA 42', q);
// La 142 solo entra por «braga» (la mitad de las palabras), detrás de la 42 y sin puntos del 42.
const r = rank('braga 42');
assert.ok(r.indexOf('BRAGA TALLA 142') > r.indexOf('BRAGA TALLA 42'));
assert.strictEqual(rank('manguera 2')[0], 'MANGUERA 2"');
assert.ok(!F.scoreToken(['manguera', '1/2"'], 'manguera 1/2"', '2', true).hit, 'el 2 de 1/2 no cuenta');
assert.ok(!F.scoreToken(['cable', '2,5'], 'cable 2,5 mm', '2', true).hit, 'el 2 de 2,5 no cuenta');
// Un número solo: trozo de código, como siempre.
assert.ok(rank('0004').indexOf('BRAGA TALLA 42') >= 0);
assert.ok(F.scoreToken(['000142'], '000142 braga', '42', false).hit, 'sin otras palabras, trozo');

// Sin { enteros: true } (mapa, frentes…) nada cambia: el 42 de 142 sigue sumando.
const sin = rank('braga 42', null);
assert.ok(sin.indexOf('BRAGA TALLA 142') < sin.indexOf('BRAGA TALLA 40'), 'sin la opción, 142 suma por el 42');

// coincide: el filtro de las listas desplegables (Frente, Tipo… en uicomponents.js).
// Cada palabra en cualquier orden, sin acentos ni espacios dobles, una letra de margen en las largas.
const L = ["TUBERÍA DE 30'' VELADERO TRAMO I", 'ESTACIÓN  DE VALVULAS', 'CORTAFUEGO CARABOBO FASE II',
    'CORTAFUEGO AYACUCHO FASE II', 'TRANSVERSALES CARABOBO', 'CAMION CON BRAZO 6 TON', 'CHUTO CON BRAZO 16 TON',
    'CAMION PLATAF/B HIDRAULICO', 'PLATAFORMA / BRAZO HIDRAULICO', 'CAMION ELEVADOR 12 TON'];
// FuzzySearch.filtrar: LA regla de las listas (desplegables, Frente de destino, Frentes, proyecto
// de almacén): primero sin margen; si nada sale, con margen. Mantiene el orden de la lista.
const filtrarLista = (q, lista) => F.filtrar(lista, q, (t) => t);
const filtra = (q) => filtrarLista(q, L);
const eq = (q, esperado) => assert.deepStrictEqual(filtra(q), esperado, q);
eq('tramo i veladero', ["TUBERÍA DE 30'' VELADERO TRAMO I"]);                   // otro orden
eq('veladero tuberia', ["TUBERÍA DE 30'' VELADERO TRAMO I"]);                   // sin acento
eq('estacion de valvulas', ['ESTACIÓN  DE VALVULAS']);                           // dos espacios en el nombre
eq('carabobo cortafuego', ['CORTAFUEGO CARABOBO FASE II']);
eq('carabbo', ['CORTAFUEGO CARABOBO FASE II', 'TRANSVERSALES CARABOBO']);        // una letra de menos
eq('cortafuegos', ['CORTAFUEGO CARABOBO FASE II', 'CORTAFUEGO AYACUCHO FASE II']); // plural
eq('brazo camion', ['CAMION CON BRAZO 6 TON']);                                  // sin el «con»
eq('brazo 6 ton', ['CAMION CON BRAZO 6 TON']);                                   // el 6 no es el de 16
eq('hidraulico plataf', ['CAMION PLATAF/B HIDRAULICO', 'PLATAFORMA / BRAZO HIDRAULICO']); // la barra separa
eq('12', ['CAMION ELEVADOR 12 TON']);                                            // número solo: trozo
eq('ayacucho carabobo', []);                                                     // TODAS las palabras
// Las cortas no toleran: «cam» no trae lo que tenga «can», «car»…; una letra sola es trozo.
eq('cam', ['CAMION CON BRAZO 6 TON', 'CAMION PLATAF/B HIDRAULICO', 'CAMION ELEVADOR 12 TON']);
assert.strictEqual(filtra('v').length, 4, 'v: solo lo que lleva v (VELADERO, VALVULAS, TRANSVERSALES, ELEVADOR)');
// Lo de antes sigue entrando: el texto tal cual y en el mismo orden.
for (const t of L) assert.ok(F.coincide(t, t) && F.coincide(t.slice(2, 9), t), t);
assert.ok(F.coincide('', 'CUALQUIERA') && F.coincide('   ', 'CUALQUIERA'), 'vacío: todo');
// Primero sin margen: «cama» trae la CAMA BAJA y no también los CAMION (por «cami»); si no hay
// ninguna exacta, entra el margen (las de arriba: «carabbo», «cortafuegos»).
assert.deepStrictEqual(filtrarLista('cama', ['CAMA BAJA', 'CAMION CISTERNA', 'CAMION VOLTEO']), ['CAMA BAJA']);
assert.deepStrictEqual(filtrarLista('camoin', ['CAMA BAJA', 'CAMION CISTERNA']), ['CAMION CISTERNA']);
assert.ok(F.coincide('cama', 'CAMION CISTERNA') && !F.coincide('cama', 'CAMION CISTERNA', true), 'exacto quita el margen');
// La pasada exacta exige las palabras cortas («con»); la segunda las salta («de» de volteo).
const T = ['CAMION CON BRAZO 6 TON', 'CAMION CISTERNA', 'CAMION VOLTEO'];
assert.deepStrictEqual(filtrarLista('camion con', T), ['CAMION CON BRAZO 6 TON']);
assert.deepStrictEqual(filtrarLista('camion de volteo', T), ['CAMION VOLTEO']);
assert.deepStrictEqual(filtrarLista('volteo camoin', T), ['CAMION VOLTEO']);

// filtrar con objetos (Frente de destino: { nombre, id }), vacío = todos en su orden.
const frentes = [{ nombre: 'LOGÍSTICA DIVISION CARABOBO', id: 1 }, { nombre: "TUBERÍA DE 30'' VELADERO TRAMO I", id: 2 }, { nombre: 'TRANSVERSALES CARABOBO', id: 3 }];
const ids = (q) => F.filtrar(frentes, q, (f) => f.nombre).map((f) => f.id);
assert.deepStrictEqual(ids(''), [1, 2, 3]);
assert.deepStrictEqual(ids('  '), [1, 2, 3]);
assert.deepStrictEqual(ids('logistica'), [1]);
assert.deepStrictEqual(ids('tramo veladero'), [2]);
assert.deepStrictEqual(ids('carabobo'), [1, 3]);
assert.deepStrictEqual(ids('transversals carabobo'), [3]);
assert.deepStrictEqual(ids('nada parecido'), []);

console.log('ok');
