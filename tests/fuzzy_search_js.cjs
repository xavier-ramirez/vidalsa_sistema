// Prueba de public/js/maquinaria/fuzzy_search.js (sugerencias mientras se escribe).
//   node tests/fuzzy_search_js.cjs   → "ok" o el primer fallo (código de salida 1).
// Cubre: un número corto junto a otras palabras cuenta ENTERO, como en la tabla de Almacén
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
const rank = (q) => F.rank(items, q, (i) => ({ haystack: i.c + ' ' + i.n, label: i.n })).map((i) => i.n);

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

console.log('ok');
