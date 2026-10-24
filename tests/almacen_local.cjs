// Corre public/js/maquinaria/almacen_local.js en Node para las pruebas (Tests\AlmacenLocal).
// Entrada (stdin, JSON): { copia, accion, filtros, offset, cuantos, pagina, porDefecto, puede,
//   mini, conBusqueda, idProducto, idAlmacen, pendientes }
//   copia  → la respuesta completa de GET /sync/almacen
//   accion → 'inventario' | 'ids' | 'panelOtros' | 'movimientos' | 'consumo' | 'notas'
//            | 'notasFiltro' | 'listaProductos' | 'stock'
//   pendientes → lo que espera en la bandeja sin internet (forma de window.Bandeja.pendientes())
// Salida (stdout, JSON): lo que devuelve esa función de window.AlmacenLocal.
const fs = require('fs');
const path = require('path');

const js = path.join(__dirname, '..', 'public', 'js', 'maquinaria');
global.window = { addEventListener() {} };
global.document = { querySelector: () => null, getElementById: () => null };
eval(fs.readFileSync(path.join(js, 'dom_helpers.js'), 'utf8'));
eval(fs.readFileSync(path.join(js, 'almacen_local.js'), 'utf8'));

const e = JSON.parse(fs.readFileSync(0, 'utf8'));
window.Bandeja = { pendientes: () => e.pendientes || [] };
const vista = {
    filas: (t) => e.copia.datos[t] || [],
    catalogo: (n) => e.copia.catalogos[n] || [],
    estado: { listo: true, conectado: true },
};
const L = window.AlmacenLocal;
const f = e.filtros || {};
const acciones = {
    inventario: () => L.inventario(vista, f, e.offset || 0, e.porDefecto, e.cuantos || 0),
    ids: () => L.idsInventario(vista, f, e.porDefecto),
    panelOtros: () => L.panelOtros(vista, e.idProducto, e.idAlmacen),
    movimientos: () => L.movimientos(vista, f, e.pagina || 1, { mini: !!e.mini, porDefecto: e.porDefecto, puede: e.puede || {} }),
    consumo: () => L.consumo(vista, f, e.porDefecto, e.conBusqueda !== false),
    notas: () => L.notas(vista, f, e.pagina || 1, e.porDefecto),
    notasFiltro: () => L.notasFiltro(vista),
    listaProductos: () => L.listaProductos(vista),
    stock: () => L.stockConPendientes(vista),
};
process.stdout.write(JSON.stringify(acciones[e.accion]()));
