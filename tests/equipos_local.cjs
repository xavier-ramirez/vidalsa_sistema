// Corre public/js/maquinaria/equipos_local.js en Node para las pruebas (Tests\EquiposLocal).
// Entrada (stdin, JSON): { copia, accion, filtros, offset, puede, campo }
//   copia  → la respuesta completa de GET /sync/equipos
//   accion → 'consultar' | 'opciones' | 'ids' | 'hayFiltro'
// Salida (stdout, JSON): lo que devuelve esa función de window.EquiposLocal.
const fs = require('fs');
const path = require('path');

const js = path.join(__dirname, '..', 'public', 'js', 'maquinaria');
global.window = { addEventListener() {} };
global.document = { querySelector: () => null, getElementById: () => null };
eval(fs.readFileSync(path.join(js, 'dom_helpers.js'), 'utf8'));
eval(fs.readFileSync(path.join(js, 'equipos_local.js'), 'utf8'));

const e = JSON.parse(fs.readFileSync(0, 'utf8'));
const vista = {
    filas: (t) => e.copia.datos[t] || [],
    catalogo: (n) => e.copia.catalogos[n] || [],
    estado: { listo: true, conectado: true },
};
const L = window.EquiposLocal;
const r = e.accion === 'opciones' ? L.opcionesDocumento(vista, e.filtros, e.campo)
    : e.accion === 'ids' ? L.idsExportar(vista, e.filtros)
    : e.accion === 'hayFiltro' ? L.hayFiltro(e.filtros)
    : L.consultar(vista, e.filtros, e.offset || 0, e.puede);
process.stdout.write(JSON.stringify(r));
