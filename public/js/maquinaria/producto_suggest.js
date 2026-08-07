/**
 * window.ProductoSuggest — Reglas COMPARTIDAS de los autocompletes de productos del
 * inventario (/admin/almacen y /admin/almacen/movimientos).
 *
 * Qué resuelve: los dos buscadores tienen que agrupar IGUAL. Desde que Recepción permite
 * la misma descripción en varias presentaciones (distinta UM = producto aparte), el
 * catálogo puede tener N productos con idéntico NOMBRE, y la lista debe mostrar UNA
 * entrada por descripción (con un badge de cuántas presentaciones tiene) en vez de
 * repetirla. Los FILTROS son la excepción: los del mismo nombre son MODELOS DISTINTOS
 * (distinto nº de parte), así que cada uno es su propia sugerencia.
 *
 * Ese algoritmo estaba copiado en las dos vistas; aquí vive una sola vez. Cada vista
 * conserva solo lo suyo: Inventario filtra además por la categoría activa y usa sus
 * propias clases CSS en el render.
 *
 * Se carga GLOBAL en estructura_base (como fuzzy_search.js, del que depende): la SPA
 * omite los <script src> que van dentro de @section('content').
 */
(function (w) {
    'use strict';

    // Script global: si la SPA vuelve a inyectarlo, no re-declaramos nada.
    if (w.ProductoSuggest) return;

    function norm(s) { return w.FuzzySearch.norm(s || ''); }

    // Criterio ÚNICO "es filtro": la CATEGORÍA contiene 'FILTRO' (así "FILTROS DE ACEITE" o
    // "FILTRO DE AIRE" también cuentan), coherente con el backend (updateProducto).
    function esCategoriaFiltro(cat) { return String(cat || '').toUpperCase().indexOf('FILTRO') !== -1; }
    function esFiltro(p) { return esCategoriaFiltro(p && p.CATEGORIA); }

    // Clave de agrupación/dedupe de una sugerencia: los filtros NO se agrupan (cada uno es
    // un modelo aparte → su propio id); el resto agrupa por descripción normalizada.
    function claveGrupo(p) {
        return esFiltro(p) ? ('__f' + p.ID_PRODUCTO) : norm(p.NOMBRE);
    }

    // Mapa { claveGrupo: { count, ids, items } } del catálogo. MEMOIZADO: el buscador lo pide
    // en CADA tecla y recorrerlo cuesta ~0,5 ms con los ~1200 productos del catálogo, lo que
    // en el caso "abrir el buscador sin escribir" (donde el ranking no hace nada) era el 100%
    // del trabajo. La caché se invalida sola si la vista REEMPLAZA el array; si lo muta en
    // sitio (Inventario hace push/asignación al crear o editar un producto) hay que llamar
    // invalidar() — si no, un producto nuevo o renombrado no se agruparía bien hasta el F5.
    var cacheLista = null, cacheGrupos = null;
    function invalidar() { cacheLista = null; cacheGrupos = null; }
    function agrupar(lista) {
        lista = lista || [];
        if (cacheLista === lista && cacheGrupos) return cacheGrupos;
        var grupos = {};
        for (var i = 0; i < lista.length; i++) {
            var k = claveGrupo(lista[i]);
            if (!grupos[k]) grupos[k] = { count: 0, ids: [], items: [] };
            grupos[k].count++;
            grupos[k].ids.push(lista[i].ID_PRODUCTO);
            grupos[k].items.push(lista[i]);
        }
        cacheLista = lista; cacheGrupos = grupos;
        return grupos;
    }

    // Ranking del catálogo por relevancia (FuzzySearch) con el haystack de producto: el
    // CODIGO y las EQUIV (nºs de parte equivalentes) entran en la búsqueda —teclear un
    // alterno ("MIS0531" / "1000FG" / "LF-3977") encuentra el producto aunque su
    // código/nombre no lo contenga— pero la etiqueta visible sigue siendo solo el NOMBRE.
    // Término vacío → catálogo en su orden natural.
    function rankear(lista, term) {
        return w.FuzzySearch.rank(lista || [], term, function (p) {
            return {
                haystack: (p.CODIGO || '') + ' ' + (p.NOMBRE || '') + ' ' + (p.EQUIV || ''),
                label: p.NOMBRE || '',
            };
        });
    }

    /**
     * Dedupe del ranking: una entrada por descripción (los filtros, una por producto),
     * hasta `limite`. `aceptar(producto, grupo)` es opcional y lo usa Inventario para
     * descartar los que no pertenecen a la categoría activa. `grupos` se pasa explícito
     * (el que devolvió agrupar) para no depender de estado oculto.
     */
    function dedupe(ranked, grupos, limite, aceptar) {
        var out = [], vistos = {};
        grupos = grupos || {};
        for (var i = 0; i < ranked.length && out.length < limite; i++) {
            var p = ranked[i], k = claveGrupo(p);
            if (vistos[k]) continue;
            if (typeof aceptar === 'function' && !aceptar(p, grupos[k])) continue;
            vistos[k] = true;
            out.push(p);
        }
        return out;
    }

    /**
     * Badge de "N presentaciones" (icono layers + número) para una sugerencia agrupada.
     * Devuelve '' si la descripción es única. `clase` la pone la vista porque el prefijo
     * CSS cambia por módulo (alm-suggest-cod / amf-suggest-cod).
     */
    function badgePresentaciones(grupo, clase) {
        if (!grupo || grupo.count <= 1) return '';
        return '<span class="' + clase + '" style="color:#0067b1;display:inline-flex;align-items:center;gap:1px;"'
             + ' title="' + grupo.count + ' presentaciones (distintas unidades)">'
             + '<i class="material-icons" style="font-size:15px;line-height:1;">layers</i>'
             + '<span style="font-size:10.5px;font-weight:700;line-height:1;">' + grupo.count + '</span>'
             + '</span>';
    }

    w.ProductoSuggest = {
        esCategoriaFiltro: esCategoriaFiltro,
        esFiltro: esFiltro,
        claveGrupo: claveGrupo,
        agrupar: agrupar,
        invalidar: invalidar,
        rankear: rankear,
        dedupe: dedupe,
        badgePresentaciones: badgePresentaciones,
    };
})(window);
