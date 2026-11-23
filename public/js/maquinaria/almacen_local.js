/**
 * Almacén desde la COPIA LOCAL — window.AlmacenLocal.
 *
 * El inventario (/admin/almacen), la bitácora (/admin/almacen/movimientos), el historial de
 * un producto y las Notas de Entrega (/admin/almacen/notas) se filtran, cuentan y pintan SOLO
 * aquí, con o sin internet: una sola lógica. Las filas salen de window.CopiaLocal (dominio
 * 'almacen', App\Support\Sincronizacion\DominioAlmacen): tablas productos / stock /
 * movimientos / correcciones y catálogos almacenes / frentes.
 *
 *   inventario(vista, f, offset, porDefecto, cuantos) → { html, hasMore, nextOffset,
 *       aproximada, stats, distribucionHtml } (stats y distribucionHtml solo con offset 0).
 *       Lotes de 120 filas; `cuantos` pide más de una vez desde el principio (el repintado).
 *       `f` = los filtros de la tabla (id_almacen, search, id_producto, id_producto_in,
 *       categoria, um, solo_bajo, solo_con_saldo, ver_todo, aprox, solo_filas).
 *   idsInventario(vista, f, porDefecto) → ids de producto que trae el Excel del inventario.
 *   panelOtros(vista, idProducto, idAlmacen) → html del panel «En otros almacenes».
 *   movimientos(vista, f, pagina, opciones) → { html, pagination, total, ids }. opciones:
 *       { mini, porDefecto, puede: { superAdmin, devolver, corregir } }; `ids` = todas las
 *       filas filtradas, en el orden de la tabla (el Excel de la bitácora).
 *   consumo(vista, f, porDefecto, conBusqueda) → html del ranking «Consumo de Inventario».
 *   notas(vista, f, pagina, porDefecto) → { html, pagination, total }.
 *   notasFiltro(vista, f, porDefecto) → N° de Nota de las salidas: con `f`, del almacén de
 *       ese filtro (autocomplete «Referencia» de la bitácora); sin él, de todos los visibles.
 *   listaProductos(vista) → el catálogo activo para los buscadores (forma de
 *       ProductoInventario::listaAutocomplete).
 *
 * Reglas (las mismas que tenía AlmacenController):
 *   · Comparar como la base (colación utf8mb4_unicode_ci): sin mayúsculas ni acentos.
 *   · La búsqueda de producto parte en palabras (sin "de", "la"…; un número de 1-2 cifras
 *     junto a otras palabras cuenta ENTERO, y sin él si con él no sale nada: ver buscador),
 *     exige todas, mira código, descripción y nºs de parte, y "botas" encuentra "bota". Si no
 *     encuentra nada y lo escrito no está en ningún producto, perdona UN error de tipeo en
 *     las palabras que no salen en NINGÚN producto (hasta 3 de 4 a 16 letras; las que sí
 *     existen se buscan tal cual, así que la frase puede ser larga) y avisa que son parecidos.
 *   · El inventario sin filtro abre VACÍO; el Consolidado es siempre del almacén entero.
 *   · Las cantidades se suman por producto (un almacén multi-proyecto tiene una fila por
 *     proyecto); el reparto por proyecto solo existe donde el almacén separa.
 */
(function () {
    'use strict';

    const POR_LOTE = 120;
    const POR_PAGINA = 50;
    const EPS = 0.0005;
    const MAX_PALABRAS_CON_PERDON = 3;
    const MAX_LETRAS_CON_PERDON = 16;
    const MAX_FORMAS_CON_PERDON = 200;
    const BOLSA_COMUN = 'Sin proyecto (común)';
    const BOLSA_COMUN_EN_FRASE = 'material sin asignar';
    const STOCK_INICIAL = 'STOCK INICIAL';
    const ENTRADAS = ['ENTRADA', 'TRASPASO_ENTRADA', 'DEVOLUCION'];
    const SALIDAS = ['SALIDA', 'TRASPASO_SALIDA'];
    // [label, color de la letra, icono] de cada TIPO en la bitácora. Un traspaso se lee como
    // Entrada/Salida normal; lo distingue el icono direccional. AJUSTE en BD = "Auditoría".
    const TIPO_META = {
        ENTRADA: ['Entrada', '#16a34a', 'add'],
        TRASPASO_ENTRADA: ['Entrada', '#16a34a', 'south_west'],
        SALIDA: ['Salida', '#dc2626', 'remove'],
        TRASPASO_SALIDA: ['Salida', '#dc2626', 'north_east'],
        AJUSTE: ['Auditoría', '#0067b1', 'fact_check'],
        DEVOLUCION: ['Devolución', '#0d9488', 'assignment_return'],
    };
    const TIPO_META_DEFAULT = ['?', '#475569', 'swap_vert'];
    const STOP = new Set(['de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas',
        'y', 'e', 'o', 'u', 'a', 'en', 'con', 'para', 'por']);
    const RUTA = '/admin/almacen';

    const h = (s) => window.escapeHtml(s);
    const hj = (s) => window.escapeAttrJs(s);

    // ── Comparar como la base ─────────────────────────────────────────────────
    const norm = (s) => String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
    const txt = (v) => String(v == null ? '' : v).trim();
    const lleno = (v) => txt(v) !== '';
    const si = (v) => ['1', 'true', 'on', 'yes'].indexOf(String(v == null ? '' : v).toLowerCase()) >= 0;
    const r3 = (n) => Math.round((Number(n) || 0) * 1000) / 1000;
    const orden = new Intl.Collator('und', { sensitivity: 'base' });
    const ordenNulos = (a, b) => (a == null ? (b == null ? 0 : -1) : (b == null ? 1 : orden.compare(String(a), String(b))));
    const ESPACIOS = /[ \t\n\r\f\v]+/;

    // LIKE de MySQL: % = cualquier cosa, _ = un carácter, \ escapa. Se compila una vez por patrón.
    const moldes = new Map();
    function molde(patron) {
        let fn = moldes.get(patron);
        if (fn) return fn;
        const piezas = [];
        const letras = Array.from(patron);
        for (let i = 0; i < letras.length; i++) {
            const c = letras[i];
            if (c === '\\' && i + 1 < letras.length) piezas.push({ lit: norm(letras[++i]) });
            else if (c === '%') piezas.push({ todo: true });
            else if (c === '_') piezas.push({ uno: true });
            else piezas.push({ lit: norm(c) });
        }
        const literal = (ps) => ps.every((p) => p.lit !== undefined) ? ps.map((p) => p.lit).join('') : null;
        const n = piezas.length;
        const medio = n >= 2 && piezas[0].todo && piezas[n - 1].todo ? literal(piezas.slice(1, -1)) : null;
        const inicio = n >= 1 && piezas[n - 1].todo ? literal(piezas.slice(0, -1)) : null;
        if (medio !== null) fn = (v) => v.indexOf(medio) >= 0;
        else if (inicio !== null) fn = (v) => v.startsWith(inicio);
        else {
            const re = new RegExp('^' + piezas.map((p) => (p.todo ? '[\\s\\S]*' : p.uno ? '[\\s\\S]'
                : p.lit.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))).join('') + '$');
            fn = (v) => re.test(v);
        }
        moldes.set(patron, fn);
        return fn;
    }
    const como = (v, patron) => v != null && molde(patron)(norm(v));
    const igual = (a, b) => a != null && norm(a).replace(/ +$/, '') === norm(b).replace(/ +$/, '');
    const trimSql = (s) => String(s == null ? '' : s).replace(/^ +| +$/g, '');

    // Textos de un producto ya normalizados, una vez por fila de la copia.
    const normProd = new WeakMap();
    function np(p) {
        let n = normProd.get(p);
        if (!n) {
            n = { cod: p.codigo == null ? null : norm(p.codigo), nom: p.nombre == null ? null : norm(p.nombre), partes: (p.partes || []).map(norm) };
            normProd.set(p, n);
        }
        return n;
    }

    // 5 → "5", 2.5 → "2,5", 1800 → "1.800" (InventarioService::num).
    function cifra(n) {
        const v = Number(n) || 0;
        const r = Math.round(Math.abs(v) * 1000 + 1e-7) / 1000;
        const [ent, dec] = r.toFixed(3).split('.');
        return (v < 0 && r !== 0 ? '-' : '') + ent.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + dec;
    }
    function fmt(n) {
        const q = cifra(n).replace(/0+$/, '').replace(/,$/, '');
        return q === '' || q === '-' ? '0' : q;
    }
    const pctTxt = (n) => String(Math.round(n * 10) / 10);
    const dma = (f) => (f ? f.slice(8, 10) + '/' + f.slice(5, 7) + '/' + f.slice(0, 4) : '');
    function hora(dt) {
        if (!dt || dt.length < 16) return '';
        const hh = Number(dt.slice(11, 13));
        return String(hh % 12 || 12).padStart(2, '0') + ':' + dt.slice(14, 16) + ' ' + (hh < 12 ? 'AM' : 'PM');
    }
    const urlNota = (n) => RUTA + '/nota-entrega?numero=' + encodeURIComponent(n);

    // ── Búsqueda de producto ──────────────────────────────────────────────────
    // Los números cortos («42», «3») también cuentan: antes se tiraban y «braga de 42» buscaba
    // solo «braga» (todas las tallas, con la 42 sin saldo al final). Cómo se buscan: entero() y
    // buscador(). Un número corto solo («42») se busca como siempre, como trozo.
    const corto = (x) => /^\d{1,2}$/.test(x);
    function palabras(term) {
        const bajo = String(term).toLowerCase();
        const t = bajo.split(ESPACIOS).filter((x) => x !== '' && !STOP.has(x));
        return t.some((x) => !corto(x)) ? t : [bajo];
    }
    // Número ENTERO: ni dentro de otro número (42 en 142 o en el código 000142) ni de una
    // fracción o decimal (el 2 de 1/2", 2,5 MM o 22.5).
    // Sin lookbehind (?<!…): Safari antes de 16.4 no lo entiende (misma regla que
    // FuzzySearch · posEntera).
    function entero(n) {
        const dig = (c) => c >= '0' && c <= '9';
        const sep = (c) => c === '.' || c === ',' || c === '/';
        return (v) => {
            for (let i = v.indexOf(n); i > -1; i = v.indexOf(n, i + 1)) {
                const a = v.charAt(i - 1), a2 = v.charAt(i - 2), d = v.charAt(i + n.length), d2 = v.charAt(i + n.length + 1);
                if (!(dig(a) || (sep(a) && dig(a2)) || dig(d) || (sep(d) && dig(d2)))) return true;
            }
            return false;
        };
    }
    const comoTexto = (s) => s.replace(/[%_\\]/g, '\\$&');

    function variantes(token) {
        const l = Array.from(token);
        if (l.length < 4 || l.length > MAX_LETRAS_CON_PERDON || /[%_\\]/.test(token)) return [];
        const v = [];
        for (let i = 0; i < l.length; i++) {
            const antes = l.slice(0, i).join(''), despues = l.slice(i + 1).join('');
            v.push(antes + '_' + despues, antes + despues);
        }
        for (let i = 0; i <= l.length; i++) v.push(l.slice(0, i).join('') + '_' + l.slice(i).join(''));
        // Dos letras seguidas al revés (lo más común al teclear rápido: «ABARZADERA», «PLSATICA»).
        for (let i = 0; i + 1 < l.length; i++) if (l[i] !== l[i + 1]) v.push(l.slice(0, i).join('') + l[i + 1] + l[i] + l.slice(i + 2).join(''));
        return Array.from(new Set(v));
    }

    // La palabra, su singular si venía en plural y, perdonando el tipeo, las variantes de las dos.
    function formas(token, tolerante) {
        const f = [comoTexto(token)];
        const raices = [token];
        const l = Array.from(token);
        if (l.length > 3 && l[l.length - 1] === 's') {
            const singular = l.slice(0, -1).join('');
            f.push(comoTexto(singular));
            raices.push(singular);
        }
        if (tolerante) raices.forEach((r) => f.push.apply(f, variantes(r)));
        return Array.from(new Set(f));
    }

    // Palabra que sale en el código, la descripción o algún nº de parte de un producto.
    function coincide(n, ms) {
        return ms.some((m) => (n.cod !== null && m(n.cod)) || (n.nom !== null && m(n.nom)) || n.partes.some(m));
    }

    // Las palabras a las que se les perdona el tipeo: SOLO las que no salen en ningún producto
    // activo (las demás ya están bien escritas). Antes se perdonaba todo o nada y con más de 3
    // palabras nada: «ABARZADERA 5" PARA MANGUERA DE ADMISIÓN JAC» no encontraba la abrazadera.
    // null = nada que perdonar, o demasiado (más de 3 palabras desconocidas —un texto pegado— o
    // demasiadas formas): se queda la búsqueda exacta.
    function palabrasAPerdonar(ctx, term) {
        const n = Array.from(ctx.productos.values()).filter((p) => p.activo).map(np);
        const raras = palabras(txt(term)).filter((t) => !corto(t)).filter((t) => {
            const ms = formas(t, false).map((f) => molde('%' + f + '%'));
            return !n.some((x) => coincide(x, ms));
        });
        if (!raras.length || raras.length > MAX_PALABRAS_CON_PERDON
            || Array.from(new Set(raras)).reduce((s, t) => s + formas(t, true).length, 0) > MAX_FORMAS_CON_PERDON) return null;
        return new Set(raras);
    }

    // AND entre palabras; cada palabra en código, descripción o algún nº de parte.
    // perdon: Set de palabras con perdón de tipeo (palabrasAPerdonar) o null.
    // ctx: con él se mira si cada número corto encuentra algo entre los productos que la
    // pantalla puede mostrar (dentro: por defecto los activos; la tabla le suma sus filtros, el
    // historial deja también los inactivos). El que no encuentra nada se deja fuera y queda en
    // `sinNumeros` para avisarlo («guante 10» sigue trayendo los guantes, como antes, pero la
    // pantalla dice que no hay talla 10). Si ni sin números sale nada, no se quita ninguno.
    function buscador(term, perdon, ctx, dentro) {
        const toks = palabras(term);
        const nums = toks.length > 1 ? Array.from(new Set(toks.filter(corto))) : [];
        const base = toks.filter((t) => nums.indexOf(t) < 0).map((t) => {
            const ms = formas(t, !!perdon && perdon.has(t)).map((f) => molde('%' + f + '%'));
            return (n) => coincide(n, ms);
        });
        // En el nombre y el código, entero; en un nº de parte también valen las 2 últimas
        // cifras de un número largo («filtro 31» encuentra el MIS0531: se suele teclear la
        // cola), no de uno corto (el 42 de la parte 142 no cuenta) ni una sola cifra.
        const delNumero = (t) => {
            const e = entero(t);
            const cola = t.length === 2 ? (x) => /[0-9]{4,}$/.test(x) && x.slice(-2) === t : () => false;
            return (n) => (n.nom !== null && e(n.nom)) || (n.cod !== null && e(n.cod))
                || n.partes.some((x) => e(x) || cola(x));
        };
        const armar = (gs) => (p) => { const n = np(p); return gs.every((g) => g(n)); };
        let grupos = base.concat(nums.map(delNumero));
        const sinNumeros = [];
        if (ctx && nums.length) {
            const puede = dentro || ((p) => p.activo);
            const encuentra = (gs) => {
                const b = armar(gs);
                for (const p of ctx.productos.values()) if (puede(p) && b(p)) return true;
                return false;
            };
            if (!encuentra(grupos) && encuentra(base)) {
                grupos = base.slice();
                nums.forEach((t) => {
                    const con = grupos.concat([delNumero(t)]);
                    if (encuentra(con)) grupos = con; else sinNumeros.push(t);
                });
            }
        }
        const b = armar(grupos);
        b.sinNumeros = sinNumeros;
        return b;
    }

    // Cercanía a lo escrito: la frase entera pesa más que cada palabra, y el inicio más que el medio.
    function puntuador(term, perdon) {
        const frase = txt(term);
        if (frase === '') return null;
        const f = comoTexto(frase.toLowerCase());
        const fr = [molde(f + '%'), molde('% ' + f + '%'), molde('%' + f + '%')];
        const toks = palabras(frase);
        const niveles = toks.map((t) => {
            const fs = formas(t, !!perdon && perdon.has(t));
            return ['@%', '% @%', '%@%'].map((m) => fs.map((x) => molde(m.replace('@', () => x)))).concat([fs.map((x) => molde('%' + x + '%'))]);
        });
        return (p) => {
            const n = np(p);
            const nom = n.nom === null ? '' : n.nom;
            const alguno = (ms, v) => v !== null && ms.some((m) => m(v));
            let s = n.nom !== null ? (fr[0](nom) ? 40 : fr[1](nom) ? 25 : fr[2](nom) ? 15 : 0) : 0;
            niveles.forEach((nv) => {
                s += alguno(nv[0], n.nom) ? 8 : alguno(nv[1], n.nom) ? 6 : alguno(nv[2], n.nom) ? 3 : alguno(nv[3], n.cod) ? 2 : 0;
            });
            return s;
        };
    }

    function idsCsv(csv) {
        return Array.from(new Set(String(csv == null ? '' : csv).split(',').map((s) => parseInt(txt(s), 10) || 0).filter((n) => n > 0)));
    }

    // Filtro de producto de la bitácora, el consumo y el historial: ids → id → texto.
    function filtroProductoMov(f, ctx, conBusqueda) {
        const ids = idsCsv(f.id_producto_in);
        if (ids.length) { const s = new Set(ids); return (m) => s.has(m.prod); }
        if (lleno(f.id_producto)) { const id = parseInt(f.id_producto, 10) || 0; return (m) => m.prod === id; }
        if (conBusqueda && lleno(f.search)) {
            const b = buscador(txt(f.search), null, ctx, () => true);
            const filtro = (m) => { const p = ctx.productos.get(m.prod); return !!p && b(p); };
            filtro.sinNumeros = b.sinNumeros;
            return filtro;
        }
        return null;
    }

    // ── Contexto: catálogos y saldos de la copia ──────────────────────────────
    // ── Lo que aún no subió (sin internet) va encima del stock de la copia ────
    // Una salida resta sus líneas de la bolsa elegida (sin elegir: la del destino o, si no
    // tiene, la que más tiene); una auditoría deja el total del producto en lo contado (la
    // diferencia va a la bolsa común). Al subir, la copia trae el saldo real del servidor.
    function stockConPendientes(vista) {
        const filas = vista.filas('stock');
        const pend = window.Bandeja
            ? window.Bandeja.pendientes().filter((it) => it.estado === 'pendiente' && (it.tipo === 'salida' || it.tipo === 'ajuste'))
            : [];
        if (!pend.length) return filas;
        const out = filas.map((s) => Object.assign({}, s));
        const delProducto = (alm, prod) => out.filter((s) => s.alm === alm && s.prod === prod);
        const bolsa = (alm, prod, frente) => {
            let f = out.find((s) => s.alm === alm && s.prod === prod && s.frente === frente);
            if (!f) { f = { id: null, alm, prod, frente, cant: 0, min: null, fecha: null }; out.push(f); }
            return f;
        };
        pend.forEach((it) => {
            const alm = Number(it.datos.id_almacen);
            it.datos.lineas.forEach((l) => {
                const prod = Number(l.id_producto), cant = Number(l.cantidad);
                if (it.tipo === 'ajuste') {
                    // Auditoría de UN proyecto (almacén que separa): esa bolsa queda en lo contado.
                    if (l.id_frente_saldo != null) { bolsa(alm, prod, Number(l.id_frente_saldo)).cant = r3(cant); return; }
                    const total = delProducto(alm, prod).reduce((t, s) => t + s.cant, 0);
                    const f = bolsa(alm, prod, 0);
                    f.cant = r3(f.cant + cant - total);
                    return;
                }
                let frente = l.id_frente_saldo;
                if (frente == null) {
                    const conSaldo = delProducto(alm, prod).filter((s) => s.cant > 0).sort((a, b) => b.cant - a.cant);
                    const delDestino = conSaldo.find((s) => s.frente === Number(it.datos.id_frente_destino));
                    frente = (delDestino || conSaldo[0] || { frente: 0 }).frente;
                }
                const f = bolsa(alm, prod, Number(frente));
                f.cant = r3(f.cant - cant);
            });
        });
        return out;
    }

    function contexto(vista) {
        const almacenes = vista.catalogo('almacenes');
        return {
            vista,
            stock: stockConPendientes(vista),
            almacenes,
            visibles: almacenes.filter((a) => a.visible),
            almById: new Map(almacenes.map((a) => [a.id, a])),
            frentes: new Map(vista.catalogo('frentes').map((fr) => [fr.id, fr])),
            productos: new Map(vista.filas('productos').map((p) => [p.id, p])),
        };
    }
    const nombreFrente = (ctx, id) => (ctx.frentes.get(id) || {}).nombre;
    const esComun = (id, nombre) => Number(id) === 0 || nombre == null;
    const rotuloBolsa = (id, nombre) => (esComun(id, nombre) ? BOLSA_COMUN : nombre);

    // Saldo de un almacén agregado por producto: suma de sus proyectos, mayor mínimo, última fecha.
    function saldos(ctx, idAlmacen) {
        const m = new Map();
        ctx.stock.forEach((s) => {
            if (s.alm !== idAlmacen) return;
            const a = m.get(s.prod) || { cant: 0, min: null, filas: [] };
            a.cant += s.cant;
            if (s.min !== null && (a.min === null || s.min > a.min)) a.min = s.min;
            a.filas.push(s);
            m.set(s.prod, a);
        });
        m.forEach((a) => { a.cant = r3(a.cant); });
        return m;
    }

    // Reparto de un producto entre las bolsas de un almacén: sin los ceros, del que más tiene.
    function reparto(ctx, filas) {
        return filas.filter((s) => s.cant > 0)
            .map((s) => ({ frente: s.frente, nombre: nombreFrente(ctx, s.frente) == null ? null : nombreFrente(ctx, s.frente), cant: s.cant }))
            .sort((a, b) => (b.cant - a.cant) || ordenNulos(a.nombre, b.nombre));
    }

    // Auditoría en un almacén que separa por proyecto: el conteo físico es de UNA bolsa, así que
    // se lista cada una con su saldo — los proyectos del almacén (aunque estén en cero: ahí
    // también se puede contar material) y la común. Una bolsa con saldo de un proyecto que ya
    // no está en el almacén se muestra, pero sin poder auditarla (el servidor la rechazaría).
    // null = el almacén no separa: la auditoría es del total, como siempre.
    function bolsasAuditoria(vista, idAlmacen, idProducto) {
        const ctx = contexto(vista);
        const alm = ctx.almById.get(Number(idAlmacen));
        if (!alm || !alm.separa) return null;
        const saldo = new Map();
        ctx.stock.forEach((s) => {
            if (s.alm === alm.id && s.prod === Number(idProducto)) saldo.set(s.frente, r3((saldo.get(s.frente) || 0) + s.cant));
        });
        const delAlmacen = new Set(alm.frentes || []);
        const ids = new Set([...delAlmacen, 0]);
        saldo.forEach((q, f) => { if (q !== 0) ids.add(f); });
        return [...ids].map((f) => {
            const nombre = f === 0 ? null : (nombreFrente(ctx, f) == null ? null : nombreFrente(ctx, f));
            return { f, n: rotuloBolsa(f, nombre), q: saldo.get(f) || 0, c: esComun(f, nombre), auditable: f === 0 || delAlmacen.has(f) };
        }).sort((a, b) => (a.c - b.c) || (b.auditable - a.auditable) || ordenNulos(a.n, b.n));
    }

    // Almacén elegido: el pedido si es visible, si no el del frente del usuario, si no el primero.
    function almacenElegido(ctx, pedido, porDefecto) {
        const ver = (id) => ctx.visibles.find((a) => a.id === Number(id)) || null;
        return (lleno(pedido) && ver(pedido)) || (porDefecto && ver(porDefecto)) || ctx.visibles[0] || null;
    }
    // Bitácora / Notas / Consumo: sin almacén pedido se toma el de por defecto; 'all' = todos los visibles.
    function almacenesDeMov(ctx, f, porDefecto) {
        let pedido = f.id_almacen;
        if (!lleno(pedido)) {
            const def = porDefecto || (ctx.visibles[0] || {}).id;
            if (def && ctx.visibles.some((a) => a.id === Number(def))) pedido = String(def);
        }
        if (lleno(pedido) && pedido !== 'all') {
            const id = parseInt(pedido, 10) || 0;
            return { filtrado: true, ids: new Set(ctx.visibles.some((a) => a.id === id) ? [id] : []) };
        }
        return { filtrado: false, ids: new Set(ctx.visibles.map((a) => a.id)) };
    }

    function periodo(desde, hasta) {
        let d = lleno(desde) ? txt(desde) : null;
        let h2 = lleno(hasta) ? txt(hasta) : null;
        if (d && h2 && d > h2) [d, h2] = [h2, d];
        if (d && d.length === 7) d += '-01';
        if (h2 && h2.length === 7) {
            const [y, m] = h2.split('-').map(Number);
            h2 += '-' + String(new Date(y, m, 0).getDate()).padStart(2, '0');
        }
        return (fecha) => (!d || (fecha && fecha >= d)) && (!h2 || (fecha && fecha <= h2));
    }

    // ── INVENTARIO ────────────────────────────────────────────────────────────
    function hayFiltroInventario(f) {
        return lleno(f.search) || lleno(f.id_producto) || lleno(f.id_producto_in) || lleno(f.categoria)
            || lleno(f.um) || si(f.solo_bajo) || si(f.solo_con_saldo) || si(f.ver_todo);
    }

    // Productos activos que deja la tabla (sin ordenar). perdon: palabras con perdón de tipeo.
    function filtrarInventario(ctx, sal, f, perdon) {
        const puntual = lleno(f.id_producto) || lleno(f.id_producto_in);
        const catalogo = si(f.ver_todo) || lleno(f.search) || lleno(f.categoria) || lleno(f.um);
        const exigeStock = !puntual && !catalogo;
        const ids = idsCsv(f.id_producto_in);
        let cond, b = null;
        if (ids.length) {
            const s = new Set(ids);
            cond = (p) => s.has(p.id);
        } else {
            const cs = [];
            if (lleno(f.id_producto)) { const id = parseInt(f.id_producto, 10) || 0; cs.push((p) => p.id === id); }
            if (lleno(f.categoria) && f.categoria !== 'all') { const pat = '%' + txt(f.categoria) + '%'; cs.push((p) => como(p.cat, pat)); }
            if (lleno(f.um)) { const um = txt(f.um); cs.push((p) => igual(p.um, um)); }
            if (si(f.solo_bajo)) cs.push((p) => { const a = sal.get(p.id); return !!a && a.min !== null && a.cant <= a.min; });
            if (si(f.solo_con_saldo)) cs.push((p) => { const a = sal.get(p.id); return !!a && a.cant > 0; });
            // El buscador mira los números entre lo que los OTROS filtros dejan (categoría, UM,
            // stock…): si no, «guante 10» con la categoría puesta se quedaba vacío sin aviso.
            if (!lleno(f.id_producto) && lleno(f.search)) {
                const otros = cs.slice();
                b = buscador(txt(f.search), perdon, ctx,
                    (p) => p.activo && (!exigeStock || sal.has(p.id)) && otros.every((c) => c(p)));
                cs.push(b);
            }
            cond = (p) => cs.every((c) => c(p));
        }
        const fuera = [];
        ctx.productos.forEach((p) => {
            if (p.activo && (!exigeStock || sal.has(p.id)) && cond(p)) fuera.push(p);
        });
        fuera.sinNumeros = b ? b.sinNumeros : [];   // números que se quitaron (buscador)
        return fuera;
    }

    function ordenarInventario(lista, sal, f, perdon) {
        const punt = puntuador(f.search, perdon);
        const conSaldoPrimero = lleno(f.search);
        const clave = new Map(lista.map((p) => [p, punt ? punt(p) : 0]));
        const sinSaldo = (p) => ((sal.get(p.id) || {}).cant || 0) <= 0;
        return lista.sort((a, b) => (conSaldoPrimero ? sinSaldo(a) - sinSaldo(b) : 0)
            || (clave.get(b) - clave.get(a))
            || ordenNulos(a.nombre, b.nombre) || (a.id - b.id));
    }

    // Busca exacto y, si lo escrito no está en ningún producto, perdonando un error de tipeo en
    // las palabras que no existen (palabrasAPerdonar). Devuelve [filas, perdon]: perdon es el
    // Set usado (búsqueda aproximada) o null (exacta).
    function conPerdonDeTipeo(ctx, f, offset, consulta) {
        if (si(f.aprox) && offset > 0) { const pd = palabrasAPerdonar(ctx, f.search); return [consulta(pd), pd]; }
        const exacto = consulta(null);
        if (exacto.length || !lleno(f.search) || lleno(f.id_producto) || lleno(f.id_producto_in)) return [exacto, null];
        const b = buscador(txt(f.search), null, ctx);
        for (const p of ctx.productos.values()) if (p.activo && b(p)) return [exacto, null];
        const pd = palabrasAPerdonar(ctx, f.search);
        const aprox = pd ? consulta(pd) : [];
        return aprox.length ? [aprox, pd] : [exacto, null];
    }

    function inventario(vista, f, offset, porDefecto, cuantos) {
        f = f || {};
        offset = Math.max(0, parseInt(offset, 10) || 0);
        const lote = offset === 0 && cuantos > POR_LOTE ? cuantos : POR_LOTE;
        const ctx = contexto(vista);
        const alm = almacenElegido(ctx, f.id_almacen, porDefecto);
        const sal = alm ? saldos(ctx, alm.id) : new Map();
        const hay = hayFiltroInventario(f);
        let filas = [], hasMore = false, perdon = null;   // perdon: Set si la búsqueda fue aproximada
        let sinNumeros = [];
        if (alm && hay) {
            // ordenarInventario ordena la misma lista: conserva su sinNumeros (filtrarInventario),
            // el de la consulta que quedó (exacta o aproximada).
            const [todas, pd] = conPerdonDeTipeo(ctx, f, offset,
                (conPerdon) => ordenarInventario(filtrarInventario(ctx, sal, f, conPerdon), sal, f, conPerdon));
            sinNumeros = todas.sinNumeros || [];
            perdon = pd;
            filas = todas.slice(offset, offset + lote);
            hasMore = todas.length > offset + lote;
        }
        const r = {
            html: offset > 0 && !filas.length ? '' : filasInventario(ctx, filas, alm, sal, !hay, f),
            hasMore,
            nextOffset: hasMore ? offset + lote : null,
            aproximada: !!perdon,
            // Números escritos que ningún producto tiene: se buscó sin ellos (buscador).
            sinNumeros,
        };
        if (offset === 0 && !si(f.solo_filas)) {
            r.stats = statsInventario(ctx, sal);
            r.distribucionHtml = htmlDistribucion(alm ? distribucion(ctx, sal, f, perdon) : []);
        }
        return r;
    }

    // Lo que trae el Excel: lo que filtra la tabla, sin partir en páginas.
    function idsInventario(vista, f, porDefecto) {
        const ctx = contexto(vista);
        const alm = almacenElegido(ctx, f.id_almacen, porDefecto);
        if (!alm) return [];
        const sal = saldos(ctx, alm.id);
        return conPerdonDeTipeo(ctx, f, 0, (conPerdon) => filtrarInventario(ctx, sal, f, conPerdon))[0].map((p) => p.id);
    }

    // Consolidado: siempre del almacén entero, sin los filtros de la tabla.
    function statsInventario(ctx, sal) {
        const s = { total: 0, con_saldo: 0, stock_bajo: 0 };
        sal.forEach((a, id) => {
            const p = ctx.productos.get(id);
            if (!p || !p.activo) return;
            s.total++;
            if (a.cant > 0) s.con_saldo++;
            if (a.min !== null && a.cant <= a.min) s.stock_bajo++;
        });
        return s;
    }

    // Lo que filtra la tabla con existencia, repartido por categoría.
    function distribucion(ctx, sal, f, perdon) {
        const grupos = new Map();
        filtrarInventario(ctx, sal, f, perdon).forEach((p) => {
            const a = sal.get(p.id);
            if (!a || !(a.cant > 0)) return;
            const cat = trimSql(p.cat) || 'SIN CATEGORÍA';
            const k = norm(cat).replace(/ +$/, '');
            const g = grupos.get(k) || { categoria: cat, total: 0, unidades: 0 };
            g.total++;
            g.unidades += a.cant;
            grupos.set(k, g);
        });
        return Array.from(grupos.values()).sort((a, b) => (b.total - a.total) || orden.compare(a.categoria, b.categoria));
    }

    function htmlDistribucion(dist) {
        const total = dist.reduce((s, r) => s + r.total, 0);
        let html = '<div class="alm-distribucion-cats"><h4 class="alm-panel-h4"><i class="material-icons" style="color:#3b82f6;">pie_chart</i> Distribución de Inventario'
            + '<button type="button" class="alm-cat-cam" onclick="window.descargarPanelHtmlFDM(document.querySelector(\'#almLateral #almDistribucionContainer\') ? \'almLateral\' : \'almDistWrapper\',\'distribucion_de_inventario\')" title="Descargar imagen" aria-label="Descargar imagen"><i class="material-icons">photo_camera</i></button></h4>';
        if (!dist.length) return html + '<p class="alm-panel-nota">Sin datos para mostrar.</p></div>';
        html += '<ul class="alm-panel-list scroll custom-scrollbar alm-cat-list">';
        dist.forEach((r) => {
            const pct = total > 0 ? (r.total / total) * 100 : 0;
            const filtrable = r.categoria !== 'SIN CATEGORÍA';
            html += '<li class="alm-cat-row' + (filtrable ? ' clicable' : '') + '"'
                + (filtrable ? ' onclick="window.almCatPick(\'' + hj(r.categoria) + '\')" title="Filtrar por ' + h(r.categoria) + '"' : '') + '>'
                + '<div class="alm-panel-row"><span class="nom">' + h(r.categoria) + '</span>'
                + '<span class="qty" title="' + h(fmt(r.unidades)) + ' unidades en total">' + r.total + '</span></div>'
                + '<div class="alm-cat-bar"><div style="width:' + pctTxt(pct) + '%;"></div></div></li>';
        });
        return html + '</ul></div>';
    }

    // Filas de la tabla del inventario (antes partials/table_rows).
    function filasInventario(ctx, filas, alm, sal, inicial, f) {
        if (!alm) {
            return '<tr><td colspan="6" class="alm-vacio"><i class="material-icons">warehouse</i>'
                + 'Aún no hay almacenes registrados. Crea el primero en "Acciones → Gestionar almacenes", con el botón de abajo.</td></tr>';
        }
        if (inicial && !filas.length) {
            return '<tr><td colspan="6" class="alm-vacio alm-vacio-alto"><i class="material-icons">filter_alt</i>'
                + 'Usa los filtros para ver el inventario de <strong>' + h(alm.nombre) + '</strong>.</td></tr>';
        }
        if (!filas.length) {
            return '<tr><td colspan="6" class="alm-vacio"><i class="material-icons">search_off</i>'
                + 'Sin coincidencias en <strong>' + h(alm.nombre) + '</strong>.'
                + (lleno(f.search) ? '<br><span class="alm-vacio-pista">Quizá existe en el catálogo pero sin movimientos aquí. Agrégalo con una entrada o traspaso.</span>' : '')
                + '</td></tr>';
        }
        return filas.map((p) => filaInventario(ctx, p, alm, sal.get(p.id))).join('');
    }

    function filaInventario(ctx, p, alm, a) {
        const saldo = a ? a.cant : 0;
        const minimo = a ? a.min : null;
        const bajo = minimo !== null && saldo <= minimo;
        const equivs = p.partes || [];
        let bolsas = null;
        if (alm.separa && a) {
            const rep = reparto(ctx, a.filas);
            if (rep.length) bolsas = JSON.stringify(rep.map((b) => ({ f: b.frente, n: rotuloBolsa(b.frente, b.nombre), q: b.cant, c: esComun(b.frente, b.nombre) })));
        }
        const foto = p.foto
            ? '<img src="' + h(p.foto + (p.foto.indexOf('?') >= 0 ? '&' : '?') + 'sz=w120') + '" alt="' + h(p.nombre) + '" class="alm-foto" loading="lazy" title="Ver la foto" onclick="event.stopPropagation(); window.almVerFoto(this.src)">'
            : '<span class="alm-foto alm-foto-sin" title="Sin foto"><i class="material-icons">inventory_2</i></span>';
        let nombre;
        if (equivs.length > 1) {
            nombre = '<span class="alm-nombre-txt">' + h(p.nombre) + '</span><div class="alm-parte-list">'
                + equivs.map((np2) => '<span class="alm-parte-opt" data-no-toggle data-parte="' + h(np2) + '" onclick="window.almRowPartePick && window.almRowPartePick(this)">' + h(np2) + '</span>').join('<span class="alm-parte-sep"> · </span>')
                + '</div>';
        } else if (equivs.length === 1) {
            nombre = '<span class="alm-nombre-txt">' + h(p.nombre) + '</span><div class="alm-equiv-linea">' + h(equivs[0]) + '</div>';
        } else {
            nombre = h(p.nombre);
        }
        const tip = tooltip(p);
        return '<tr class="alm-row ' + (bajo ? 'alm-row-bajo' : '') + ' alm-row-clickable"'
            + ' data-id-producto="' + p.id + '" data-codigo="' + h(p.codigo) + '" data-nombre="' + h(p.nombre) + '" data-um="' + h(p.um) + '" data-saldo="' + saldo + '"'
            + ' data-bajo="' + (bajo ? '1' : '0') + '" data-minimo="' + (minimo !== null ? minimo : '') + '"'
            + (equivs.length ? ' data-equiv="' + h(equivs.join('|')) + '" data-parte-sel="' + (equivs.length === 1 ? h(equivs[0]) : '') + '"' : '')
            + (bolsas ? ' data-bolsas="' + h(bolsas) + '"' : '') + '>'
            + '<td class="alm-td-foto">' + foto + '</td>'
            + '<td class="alm-td-nombre"><span class="alm-cod-mini">' + h(p.codigo) + '</span>' + nombre
            + (tip ? '<div class="tooltip-bubble">' + tip + '<div class="alm-tip-flecha"></div></div>' : '') + '</td>'
            + '<td class="alm-td-cat">' + h(p.cat || '—') + '</td>'
            + '<td class="alm-td-stock"><span class="alm-stock-num">' + fmt(saldo) + '<span class="alm-stock-um">' + h(p.um) + '</span>'
            + (bajo ? '<i class="material-icons alm-ico-minimo" title="Stock en o por debajo del mínimo">warning</i>' : '') + '</span></td>'
            + '<td class="alm-td-cant" data-no-toggle><div class="alm-cant-stepper">'
            + '<input type="text" inputmode="decimal" class="alm-row-cant" placeholder="0" name="cant_' + p.id + '" aria-label="Cantidad de salida de ' + h(p.nombre) + '" disabled autocomplete="off"'
            + ' onclick="event.stopPropagation(); window.almRowCantClick && window.almRowCantClick(this);" onkeydown="return (window.almRowCantKeyDown ? window.almRowCantKeyDown(event) : true)"'
            + ' onpaste="return (window.almRowCantPaste ? window.almRowCantPaste(event) : true)" oninput="window.almRowCantInput && window.almRowCantInput(this)">'
            + '<div class="alm-cant-flechas">'
            + '<button type="button" class="alm-cant-btn alm-cant-btn-sube" data-step="1" disabled onclick="event.stopPropagation(); window.almRowCantStep && window.almRowCantStep(this,1);">▲</button>'
            + '<button type="button" class="alm-cant-btn" data-step="-1" disabled onclick="event.stopPropagation(); window.almRowCantStep && window.almRowCantStep(this,-1);">▼</button>'
            + '</div></div></td>'
            + '<td class="alm-td-det" data-no-toggle><button type="button" class="btn-details-mini" title="Ver detalles del producto"'
            + ' onclick="window.almAbrirDetalle(' + p.id + ',\'' + hj(p.codigo) + '\',\'' + hj(p.nombre) + '\',\'' + hj(p.um) + '\',\'' + hj(p.cat || '') + '\',' + saldo + ',' + (minimo !== null ? minimo : 'null') + ',\'' + hj(p.ubi || '') + '\',\'' + hj(p.foto || '') + '\')">'
            + '<i class="material-icons alm-ico-detalle">visibility</i></button></td></tr>';
    }

    // Burbuja de la fila: ubicación y, sin repetidos, los primeros 6 equipos que lo usan.
    function tooltip(p) {
        const tip = [];
        if (p.ubi) tip.push('📍 ' + h(p.ubi));
        const eqs = p.equipos || [];
        if (eqs.length) {
            const may = (s) => String(s == null ? '' : s).trim().replace(/\s+/g, ' ').toUpperCase();
            const nombres = Array.from(new Set(eqs.map((e) => [may(e.t), may(e.m), may(e.mo)].filter((x) => x !== '').join(' · '))));
            const lineas = nombres.slice(0, 6).map((n) => '&bull; ' + h(n));
            const ocultos = nombres.length - lineas.length;
            if (ocultos > 0) lineas.push('<span class="alm-tip-mas">… y ' + ocultos + ' equipo' + (ocultos === 1 ? '' : 's') + ' más (ábrelo en Detalles)</span>');
            tip.push('🚜 Equipos asociados (' + nombres.length + '):<br>' + lineas.join('<br>'));
        }
        return tip.join('<div class="alm-tip-sep"></div>');
    }

    // ── Panel «En otros almacenes» de un producto ─────────────────────────────
    function panelOtros(vista, idProducto, idAlmacen) {
        const ctx = contexto(vista);
        idProducto = Number(idProducto);
        const actual = idAlmacen ? ctx.almById.get(Number(idAlmacen)) || null : null;
        // Sin aviso de stock bajo en el panel: ese rojo es solo el de la fila de la tabla
        // (pedido del cliente), así que aquí no hace falta el mínimo.
        const de = (alm) => {
            const filas = ctx.stock.filter((s) => s.alm === alm.id && s.prod === idProducto);
            return { cant: r3(filas.reduce((t, s) => t + s.cant, 0)), filas };
        };
        const aqui = actual ? de(actual) : null;
        const otros = ctx.visibles.filter((a) => !actual || a.id !== actual.id).map((a) => {
            const s = de(a);
            return { id: a.id, nombre: a.nombre, cant: s.cant, proyectos: a.separa ? reparto(ctx, s.filas) : [] };
        }).sort((a, b) => (b.cant - a.cant) || orden.compare(a.nombre, b.nombre));
        const max = Math.max(aqui ? aqui.cant : 0, otros.reduce((m, o) => Math.max(m, o.cant), 0));
        const barra = (n) => '<div class="alm-panel-barra" aria-hidden="true"><span style="width:' + (max > 0 ? pctTxt(100 * Math.max(0, n) / max) : '0') + '%"></span></div>';
        const sub = (lista) => '<ul class="alm-panel-sub">' + lista.map((b) => '<li><span class="nom' + (esComun(b.frente, b.nombre) ? ' comun' : '') + '">' + h(rotuloBolsa(b.frente, b.nombre)) + '</span>'
            + '<span class="guia" aria-hidden="true"></span><span class="qty">' + fmt(b.cant) + '</span></li>').join('') + '</ul>';

        let html = '<div class="alm-otros-almacenes">';
        if (aqui) {
            const proyectos = actual.separa ? reparto(ctx, aqui.filas) : [];
            html += '<div class="alm-panel-aqui"><div class="alm-panel-aqui-cab"><i class="material-icons">warehouse</i>'
                + '<span class="nom">' + h(actual.nombre) + '</span><span class="alm-panel-chip">Aquí</span><strong class="qty">' + fmt(aqui.cant) + '</strong></div>'
                + barra(aqui.cant) + (proyectos.length > 1 ? sub(proyectos) : '') + '</div>';
        }
        html += '<h4 class="alm-panel-h4 ' + (aqui ? 'sep' : '') + '"><i class="material-icons" style="color:#10b981;">place</i> En otros almacenes</h4>';
        if (!otros.length) return html + '<p class="alm-panel-nota">No tienes otros almacenes a la vista.</p></div>';
        html += '<ul class="alm-panel-list scroll custom-scrollbar">';
        otros.forEach((o) => {
            html += '<li class="alm-panel-row clicable" onclick="window.almVerProductoEnAlmacen(\'' + o.id + '\', \'' + hj(o.nombre) + '\', \'' + idProducto + '\')" title="Ver este producto en ' + h(o.nombre) + '">'
                + '<span class="nom">' + h(o.nombre) + '</span><span class="guia" aria-hidden="true"></span>'
                + '<span class="qty">' + fmt(o.cant) + '</span>' + barra(o.cant)
                + (o.proyectos.length > 1 ? sub(o.proyectos) : '') + '</li>';
        });
        return html + '</ul></div>';
    }

    // ── KARDEX: bitácora e historial de un producto ───────────────────────────
    // Lo que la copia sabe de TODO el kardex: lo devuelto de cada salida y qué notas existen.
    function datosKardex(vista) {
        const devuelto = new Map();
        const notas = new Set();
        const devueltas = new Set();
        const idsDevolucion = new Set();
        vista.filas('movimientos').forEach((m) => {
            if (m.nota) notas.add(m.nota);
            if (m.tipo === 'DEVOLUCION') {
                idsDevolucion.add(m.id);
                if (m.rel !== null) devuelto.set(m.rel, (devuelto.get(m.rel) || 0) + m.cant);
                if (m.ref) devueltas.add(m.ref);
            }
        });
        // Retorno de un envío devuelto (TRASPASO_SALIDA enlazada a su DEVOLUCION): el material
        // regresó al origen, no se consumió en el destino.
        const esRetorno = (m) => m.tipo === 'TRASPASO_SALIDA' && m.rel !== null && idsDevolucion.has(m.rel);
        return { devuelto, notas, devueltas, esRetorno };
    }
    const porDevolver = (kd, m) => Math.max(0, r3(m.cant - (kd.devuelto.get(m.id) || 0)));

    function filtrarMovimientos(ctx, f, porDefecto) {
        const alms = almacenesDeMov(ctx, f, porDefecto);
        const conds = [(m) => alms.ids.has(m.alm)];
        const prod = filtroProductoMov(f, ctx, true);
        if (prod) conds.push(prod);
        if (lleno(f.tipo) && f.tipo !== 'all') {
            const t = String(f.tipo);
            if (t === 'ENTRADAS') conds.push((m) => ENTRADAS.indexOf(m.tipo) >= 0 || (m.tipo === 'AJUSTE' && m.res > m.ant));
            else if (t === 'SALIDAS') conds.push((m) => SALIDAS.indexOf(m.tipo) >= 0 || (m.tipo === 'AJUSTE' && m.res < m.ant));
            else conds.push((m) => igual(m.tipo, t));
        }
        if (lleno(f.nota)) { const pat = '%' + txt(f.nota) + '%'; conds.push((m) => como(m.nota, pat) || como(m.ref, pat)); }
        if (lleno(f.id_frente) && f.id_frente !== 'all') { const id = parseInt(f.id_frente, 10) || 0; conds.push((m) => m.frente === id); }
        const enPeriodo = periodo(f.desde, f.hasta);
        conds.push((m) => enPeriodo(m.fecha));
        const lista = ctx.vista.filas('movimientos').filter((m) => conds.every((c) => c(m))).sort((a, b) => b.id - a.id);
        lista.sinNumeros = (prod && prod.sinNumeros) || [];   // números que se quitaron (buscador)
        return lista;
    }

    function movimientos(vista, f, pagina, op) {
        f = f || {};
        op = op || {};
        const ctx = contexto(vista);
        const todas = filtrarMovimientos(ctx, f, op.porDefecto);
        const ultima = Math.max(1, Math.ceil(todas.length / POR_PAGINA));
        const actual = Math.min(Math.max(1, parseInt(pagina, 10) || 1), ultima);
        const filas = todas.slice((actual - 1) * POR_PAGINA, actual * POR_PAGINA);
        const kd = datosKardex(vista);
        return {
            html: op.mini ? filasKardexMini(ctx, kd, filas) : filasKardex(ctx, kd, filas, op.puede || {}),
            pagination: window.paginadorHtml(actual, ultima),
            total: todas.length,
            ids: todas.map((m) => m.id),
            sinNumeros: todas.sinNumeros,
        };
    }

    // Bolsa de la que salió el saldo cuando NO es la del destino (y el almacén separa).
    function prestamo(ctx, m) {
        if (m.fsaldo === null || m.fsaldo === (m.frente || 0)) return null;
        const alm = ctx.almById.get(m.alm);
        return alm && alm.separa ? m.fsaldo : null;
    }
    function lineaBolsa(ctx, m, bolsa) {
        const devuelve = m.tipo === 'DEVOLUCION';
        const nombre = bolsa === 0 ? BOLSA_COMUN_EN_FRASE : (nombreFrente(ctx, bolsa) != null ? nombreFrente(ctx, bolsa) : 'proyecto #' + bolsa);
        return '<div class="mv-tomado-de" title="' + (devuelve
            ? 'Ese material se le había tomado prestado a esta bolsa del mismo almacén: la devolución se lo repone'
            : 'Ese proyecto no tenía saldo suficiente: la diferencia se tomó de esta otra bolsa del mismo almacén') + '">'
            + '<i class="material-icons">subdirectory_arrow_right</i><span>' + (devuelve ? 'devuelto a' : 'tomado de') + ' <strong>' + h(nombre) + '</strong></span></div>';
    }
    function signoYMagnitud(m) {
        const entra = ENTRADAS.indexOf(m.tipo) >= 0;
        if (m.tipo === 'AJUSTE') {
            const d = m.res - m.ant;
            return { entra, signo: d >= 0 ? '+' : '−', mag: Math.abs(d), suma: d >= 0 };
        }
        return { entra, signo: entra ? '+' : '−', mag: m.cant, suma: entra };
    }
    const esStockInicial = (m) => m.tipo === 'ENTRADA' && m.ref === STOCK_INICIAL;

    // Filas de la bitácora (antes partials/kardex_rows).
    function filasKardex(ctx, kd, filas, puede) {
        if (!filas.length) return '<tr><td colspan="6" class="mv-vacio"><i class="material-icons">receipt_long</i>No hay movimientos que coincidan con los filtros.</td></tr>';
        const corregidos = new Set(ctx.vista.filas('correcciones').map((c) => c.nota + '|' + c.prod));
        const visibles = new Set(ctx.visibles.map((a) => a.id));
        return filas.map((m) => {
            const meta = TIPO_META[m.tipo] || TIPO_META_DEFAULT;
            const sm = signoYMagnitud(m);
            const p = ctx.productos.get(m.prod) || null;
            const um = p ? p.um : '';
            // Toda Nota de Entrega: salida directa o envío a otro almacén (su retorno no lleva nota).
            const conNota = (m.tipo === 'SALIDA' || m.tipo === 'TRASPASO_SALIDA') && !!m.nota;
            const queda = conNota ? porDevolver(kd, m) : null;
            const devuelto = conNota ? r3(m.cant - queda) : 0;
            const registro = m.usuario ? m : null;

            let destino;
            if (m.frente !== null && ctx.frentes.has(m.frente)) {
                const bolsa = prestamo(ctx, m);
                destino = '<div class="mv-destino-frente">' + h(nombreFrente(ctx, m.frente)) + '</div>' + (bolsa !== null ? lineaBolsa(ctx, m, bolsa) : '');
            } else if (m.contra) {
                destino = h((ctx.almById.get(m.contra) || {}).nombre || '—');
            } else if (ctx.almById.has(m.alm)) {
                destino = h(ctx.almById.get(m.alm).nombre);
            } else {
                destino = '—';
            }

            let numNota = m.nota;
            if (!numNota && m.contra && /^NE-\d{4}-\d+$/.test(String(m.ref || '')) && visibles.has(m.contra)) numNota = m.ref;
            if (!numNota && m.tipo === 'DEVOLUCION' && kd.notas.has(m.ref)) numNota = m.ref;
            const directa = m.tipo === 'ENTRADA';

            let ref = '';
            if (numNota) {
                ref += '<a href="' + h(urlNota(numNota)) + '" class="mv-nota-link" data-pdf-url="' + h(urlNota(numNota)) + '" data-pdf-title="Nota ' + h(numNota) + '"'
                    + ' onclick="if (typeof window.openPdfPreview === \'function\') { event.preventDefault(); window.openPdfPreview(this.dataset.pdfUrl, \'nota_entrega\', this.dataset.pdfTitle, 0, \'\', true, \'almacen\'); }"'
                    + ' target="_blank" rel="noopener" title="Ver Nota de Entrega (PDF)"><i class="material-icons mv-nota-ico">description</i><span class="mv-nota-num">' + h(numNota) + '</span></a>';
            }
            if (queda !== null && (puede.corregir || (puede.devolver && queda > EPS))) {
                ref += '<button type="button" class="mv-undo-btn mv-modificar" onclick="event.stopPropagation(); window.almModificarNota(\'' + hj(m.nota) + '\', ' + m.prod + ');"'
                    + ' title="Devolver o corregir este producto de la Nota ' + h(m.nota) + '"><i class="material-icons">edit_note</i><span>Modificar</span></button>';
            }
            if (esStockInicial(m)) {
                ref += '<div class="mv-ref-referencia mv-ref-stock-inicial">' + h(m.ref) + '</div><div class="mv-ref-sub">Nuevo material</div>';
            } else if (m.ref && m.ref !== numNota) {
                ref += '<div class="mv-ref-referencia ' + (numNota ? 'mv-ref-apilado' : '') + '" title="Nota de entrega / referencia">' + h(m.ref) + '</div>';
            }
            if (directa && m.motivo) {
                ref += '<div class="mv-ref-proveedor ' + (m.nota || m.ref ? 'mv-ref-apilado' : '') + '" title="Proveedor"><span>' + h(m.motivo) + '</span></div>';
            }
            if (!m.nota && !m.ref && !(directa && m.motivo)) ref += '<span class="mv-ref-empty">—</span>';
            if (puede.superAdmin) {
                const base = RUTA + '/movimientos/' + m.id;
                ref += '<button type="button" class="alm-mov-purge" data-purge-url="' + base + '/solo-historial" title="Eliminar del historial sin tocar el stock (irreversible)" aria-label="Eliminar del historial"'
                    + ' onclick="event.stopPropagation(); window.almEliminarSoloHistorial(this);"><i class="material-icons">playlist_remove</i></button>'
                    + '<button type="button" class="alm-mov-undo" data-undo-url="' + base + '" data-impacto-url="' + base + '/impacto-deshacer" title="Deshacer este movimiento (irreversible)" aria-label="Deshacer movimiento"'
                    + ' onclick="event.stopPropagation(); window.almDeshacerMovimiento(this);"><i class="material-icons">undo</i></button>';
            }

            return '<tr class="alm-mov-row" style="--mov-color: ' + meta[1] + '">'
                + '<td class="mv-td-fecha" data-label="Fecha"><div>' + dma(m.fecha) + ' <span class="mv-hora">' + hora(m.creado) + '</span></div>'
                + '<span class="mv-tipo-inline"><i class="material-icons">' + meta[2] + '</i>' + meta[0] + '</span></td>'
                + '<td class="col-producto mv-td-producto" data-label="Producto">'
                + (p && p.codigo ? '<span class="mv-prod-codigo">' + h(p.codigo) + '</span> ' : '') + h(p ? p.nombre : '—')
                + (m.parte ? '<div class="mv-parte">' + h(m.parte) + '</div>' : '')
                + '<div class="tooltip-bubble"><span class="mv-tip-ico">👤 </span>'
                + (registro ? 'Registrado por: <span class="mv-tip-nombre">' + h(m.usuario) + '</span><span class="mv-tip-correo">' + h(m.correo || m.usuario) + '</span>' : ' Usuario no registrado ')
                + (m.notas ? '<div class="mv-tip-notas">📝 ' + h(m.notas) + '</div>' : '') + '<div class="mv-tip-flecha"></div></div></td>'
                + '<td class="mv-td-cantidad ' + (sm.suma ? 'mv-suma' : 'mv-resta') + '" data-label="Cantidad">' + sm.signo + fmt(sm.mag) + ' <span class="mv-um">' + h(um) + '</span>'
                + (devuelto > EPS ? '<span class="mv-devuelto" title="De lo entregado en esta línea ya volvieron ' + fmt(devuelto) + ' ' + h(um) + '"><i class="material-icons">assignment_return</i>Devuelto ' + fmt(devuelto) + '</span>' : '')
                + (m.nota && corregidos.has(m.nota + '|' + m.prod)
                    ? '<button type="button" class="mv-corregida" onclick="event.stopPropagation(); window.almVerCorreccion(\'' + hj(m.nota) + '\');" title="Cantidad corregida: ver la nota original y la corregida"><i class="material-icons">difference</i>Corregida</button>' : '')
                + '</td>'
                + '<td class="mv-td-stock" data-label="Stock" title="Antes: ' + fmt(m.ant) + ' → Después: ' + fmt(m.res) + '">' + fmt(m.res) + '</td>'
                + '<td class="mv-td-destino" data-label="Destino">' + destino + '</td>'
                + '<td class="mv-td-ref" data-label="Ref"' + (!directa && m.motivo ? ' title="' + h(m.motivo) + '"' : '') + '>' + ref + '</td></tr>';
        }).join('');
    }

    // Filas del historial de UN producto (antes partials/kardex_rows_mini).
    function filasKardexMini(ctx, kd, filas) {
        if (!filas.length) {
            return '<tr><td colspan="5" style="padding:0;"><div class="alm-kp-sinmov"><div class="alm-kp-sinmov-ic"><i class="material-icons">receipt_long</i></div>'
                + '<div class="alm-kp-sinmov-tit">Sin movimientos</div><div class="alm-kp-sinmov-sub">Este producto no registra movimientos con los filtros aplicados.</div></div></td></tr>';
        }
        return filas.map((m) => {
            const meta = TIPO_META[m.tipo] || TIPO_META_DEFAULT;
            const sm = signoYMagnitud(m);
            const p = ctx.productos.get(m.prod) || null;
            const numNota = m.nota || (m.tipo === 'DEVOLUCION' && kd.notas.has(m.ref) ? m.ref : null);
            const conFrente = m.frente !== null && ctx.frentes.has(m.frente);
            const ini = esStockInicial(m);
            const proveedor = m.tipo === 'ENTRADA' && m.motivo;

            let destino = '';
            if (conFrente) {
                const bolsa = prestamo(ctx, m);
                destino += '<div class="alm-kp-nombre">' + h(nombreFrente(ctx, m.frente)) + '</div>' + (bolsa !== null ? lineaBolsa(ctx, m, bolsa) : '');
            } else if (m.contra) {
                destino += '<div class="alm-kp-nombre">' + h((ctx.almById.get(m.contra) || {}).nombre || '—') + '</div>';
            } else if (ini) {
                destino += '<div class="alm-kp-sub">Nuevo material</div>';
            }
            if (proveedor) destino += '<div class="alm-kp-sub">Proveedor: ' + h(m.motivo) + '</div>';
            if (m.notas) destino += '<div class="alm-kp-sub alm-kp-notas" title="' + h(m.notas) + '"><i class="material-icons">sticky_note_2</i><span>' + h(m.notas) + '</span></div>';
            if (!conFrente && !m.contra && !ini && !proveedor && !m.notas) destino += '<span class="alm-kp-vacio">—</span>';

            let doc = '';
            if (numNota) {
                doc += '<a class="alm-kp-nota" href="' + h(urlNota(numNota)) + '" onclick="if (typeof window.openPdfPreview === \'function\') { event.preventDefault(); window.openPdfPreview(this.href, \'nota_entrega\', \'Nota \' + this.textContent.trim(), 0, \'\', true, \'almacen\'); }"'
                    + ' target="_blank" rel="noopener" title="Ver Nota de Entrega (PDF)">' + h(numNota) + '</a>';
            }
            if (ini) doc += '<span class="alm-kp-ref">' + h(m.ref) + '</span>';
            else if (m.ref && m.ref !== numNota) doc += '<span class="alm-kp-ref" title="Nota de entrega / referencia">Ref ' + h(m.ref) + '</span>';
            if (!numNota && !m.ref) doc += '<span class="alm-kp-vacio">—</span>';

            return '<tr class="alm-kp-fila"><td class="alm-kp-tipo" style="color:' + meta[1] + ';"><i class="material-icons">' + meta[2] + '</i>' + meta[0] + '</td>'
                + '<td class="alm-kp-cant" style="color:' + (sm.suma ? '#16a34a' : '#dc2626') + ';">' + sm.signo + fmt(sm.mag) + ' <span class="alm-kp-um">' + h(p ? p.um : '') + '</span></td>'
                + '<td class="alm-kp-stock" title="Antes: ' + fmt(m.ant) + ' → Después: ' + fmt(m.res) + '">' + fmt(m.res) + '</td>'
                + '<td class="alm-kp-destino">' + destino + '</td><td class="alm-kp-doc">' + doc + '</td></tr>';
        }).join('');
    }

    // ── Ranking «Consumo de Inventario» ───────────────────────────────────────
    // Salidas menos lo devuelto de cada una. Con un almacén elegido cuentan también los envíos
    // a otros almacenes; con todos, solo las salidas (el envío y la salida del destino serían
    // la misma unidad dos veces). conBusqueda=false en Notas, donde `search` es el N° de nota.
    function consumo(vista, f, porDefecto, conBusqueda) {
        f = f || {};
        const ctx = contexto(vista);
        const alms = almacenesDeMov(ctx, f, porDefecto);
        const tipos = alms.filtrado ? SALIDAS : ['SALIDA'];
        const conds = [(m) => tipos.indexOf(m.tipo) >= 0 && alms.ids.has(m.alm)];
        const prod = filtroProductoMov(f, ctx, conBusqueda !== false);
        if (prod) conds.push(prod);
        if (lleno(f.id_frente) && f.id_frente !== 'all') { const id = parseInt(f.id_frente, 10) || 0; conds.push((m) => m.frente === id); }
        if (lleno(f.categoria) && f.categoria !== 'all') {
            const pat = '%' + txt(f.categoria) + '%';
            conds.push((m) => { const p = ctx.productos.get(m.prod); return !!p && como(p.cat, pat); });
        }
        const enPeriodo = periodo(f.desde, f.hasta);
        conds.push((m) => enPeriodo(m.fecha));
        const kd = datosKardex(vista);
        const grupos = new Map();
        vista.filas('movimientos').forEach((m) => {
            const p = ctx.productos.get(m.prod);
            if (!p || kd.esRetorno(m) || !conds.every((c) => c(m))) return;
            const g = grupos.get(m.prod) || { id: m.prod, nombre: p.nombre, um: p.um, total: 0, movimientos: 0 };
            g.total += m.cant - (kd.devuelto.get(m.id) || 0);
            g.movimientos++;
            grupos.set(m.prod, g);
        });
        const lista = Array.from(grupos.values()).map((g) => Object.assign(g, { total: r3(g.total) }))
            .filter((g) => g.total > 0).sort((a, b) => (b.total - a.total) || (a.id - b.id)).slice(0, 30);
        return htmlConsumo(lista, lleno(f.id_almacen) || alms.filtrado || ['id_frente', 'tipo'].some((k) => lleno(f[k]) && f[k] !== 'all')
            || ['search', 'desde', 'hasta', 'id_producto', 'id_producto_in', 'nota'].some((k) => lleno(f[k])));
    }

    function htmlConsumo(lista, hayFiltros) {
        let html = '<h4 style="margin:0 0 12px 0;font-size:13px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;">'
            + '<i class="material-icons" style="font-size:18px;color:#22c55e;">trending_down</i> Consumo de Inventario</h4>';
        if (!lista.length) {
            return html + '<p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">' + (hayFiltros
                ? 'Sin salidas registradas para los filtros actuales.'
                : 'Aún no se han registrado salidas en el sistema. El ranking se generará automáticamente cuando se registren salidas o traspasos.') + '</p>';
        }
        const max = lista[0].total;
        html += '<ul style="list-style:none;padding:0;margin:0;max-height:67vh;overflow-y:auto;overflow-x:visible;display:flex;flex-direction:column;gap:4px;" class="custom-scrollbar">';
        lista.forEach((r) => {
            html += '<li onclick="window.almMovFiltrarPorProducto && window.almMovFiltrarPorProducto(' + r.id + ', ' + h(JSON.stringify(r.nombre)) + ')"'
                + ' style="padding:5px 6px;border-bottom:1px dashed #f1f5f9;cursor:pointer;border-radius:6px;transition:background 0.15s;"'
                + ' onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'transparent\'"'
                + ' title="' + r.movimientos + ' ' + (r.movimientos === 1 ? 'movimiento' : 'movimientos') + ' de salida — ' + fmt(r.total) + ' ' + h(r.um) + '">'
                + '<div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;">'
                + '<span style="color:#334155;font-size:11px;font-weight:600;line-height:1.25;flex:1;text-transform:uppercase;word-break:break-word;">' + h(r.nombre) + '</span>'
                + '<span style="font-weight:600;font-size:10.5px;color:#475569;white-space:nowrap;padding:2px 0;">' + fmt(r.total) + ' ' + h(r.um) + '</span></div>'
                + '<div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">'
                + '<div style="width:' + (max > 0 ? (r.total / max) * 100 : 0) + '%;height:100%;background:linear-gradient(90deg,#4ade80 0%,#22c55e 100%);"></div></div></li>';
        });
        return html + '</ul>';
    }

    // ── NOTAS DE ENTREGA: una fila por N° de nota ─────────────────────────────
    function notas(vista, f, pagina, porDefecto) {
        f = f || {};
        const ctx = contexto(vista);
        const alms = almacenesDeMov(ctx, f, porDefecto);
        const tipos = SALIDAS.indexOf(f.tipo) >= 0 ? [f.tipo] : SALIDAS;
        const conds = [(m) => tipos.indexOf(m.tipo) >= 0 && lleno(m.nota) && alms.ids.has(m.alm)];
        if (lleno(f.id_frente) && f.id_frente !== 'all') { const id = parseInt(f.id_frente, 10) || 0; conds.push((m) => m.frente === id); }
        if (lleno(f.search)) {
            const pat = '%' + txt(f.search) + '%';
            conds.push((m) => como(m.nota, pat) || como(m.rq, pat) || como(m.contrato, pat) || como(m.solicita, pat));
        }
        if (lleno(f.categoria) && f.categoria !== 'all') {
            const pat = '%' + txt(f.categoria) + '%';
            conds.push((m) => { const p = ctx.productos.get(m.prod); return !!p && como(p.cat, pat); });
        }
        const enPeriodo = periodo(f.desde, f.hasta);
        conds.push((m) => enPeriodo(m.fecha));

        const menor = (a, b) => (a === null ? b : b === null ? a : Math.min(a, b));
        const grupos = new Map();
        vista.filas('movimientos').forEach((m) => {
            if (!conds.every((c) => c(m))) return;
            const k = norm(m.nota).replace(/ +$/, '');
            const g = grupos.get(k);
            if (!g) { grupos.set(k, { nota: m.nota, fecha: m.fecha, tipo: m.tipo, alm: m.alm, contra: m.contra, frente: m.frente, ult: m.id }); return; }
            if (m.fecha && (!g.fecha || m.fecha < g.fecha)) g.fecha = m.fecha;
            if (m.tipo < g.tipo) g.tipo = m.tipo;
            g.alm = Math.min(g.alm, m.alm);
            g.contra = menor(g.contra, m.contra);
            g.frente = menor(g.frente, m.frente);
            g.ult = Math.max(g.ult, m.id);
        });
        const todas = Array.from(grupos.values()).sort((a, b) => b.ult - a.ult);
        const ultima = Math.max(1, Math.ceil(todas.length / POR_PAGINA));
        const actual = Math.min(Math.max(1, parseInt(pagina, 10) || 1), ultima);
        const devueltas = datosKardex(vista).devueltas;
        return {
            html: filasNotas(ctx, todas.slice((actual - 1) * POR_PAGINA, actual * POR_PAGINA), devueltas),
            pagination: window.paginadorHtml(actual, ultima),
            total: todas.length,
        };
    }

    function filasNotas(ctx, filas, devueltas) {
        if (!filas.length) {
            return '<tr><td colspan="5" class="anf-empty"><i class="material-icons">description</i>No hay Notas de Entrega que coincidan con los filtros.'
                + '<div style="font-size:12px;color:#94a3b8;margin-top:6px;">Las notas se generan automáticamente al registrar una Salida o un Traspaso desde <a href="' + RUTA + '" style="color:#0067b1;">Almacén</a>.</div></td></tr>';
        }
        return filas.map((n) => {
            const alm = ctx.almById.get(n.alm) || null;
            const contra = n.contra !== null ? ctx.almById.get(n.contra) || null : null;
            const fre = n.frente !== null ? ctx.frentes.get(n.frente) || null : null;
            const tipo = SALIDAS.indexOf(n.tipo) >= 0 ? '<span style="font-size:12px;font-weight:700;color:#dc2626;">Salida</span>'
                : ['ENTRADA', 'TRASPASO_ENTRADA'].indexOf(n.tipo) >= 0 ? '<span style="font-size:12px;font-weight:700;color:#16a34a;">Entrada</span>'
                    : '<span style="font-size:12px;font-weight:700;color:#475569;">Auditoría</span>';
            const sub = (t) => '<div style="font-size:11px;color:#94a3b8;font-weight:500;">' + h(t) + '</div>';
            const destino = contra ? h(contra.nombre) + (fre ? sub(fre.nombre) : '') : fre ? h(fre.nombre) : '<span style="color:#94a3b8;">—</span>';
            return '<tr><td style="white-space:nowrap;"><div>' + dma(n.fecha) + '</div><div style="margin-top:3px;">' + tipo + '</div></td>'
                + '<td><span style="font-weight:700;color:#334155;font-size:13px;">' + h(n.nota) + '</span>'
                + (devueltas.has(n.nota) ? '<div class="anf-con-dev"><i class="material-icons">assignment_return</i>Con devolución</div>' : '') + '</td>'
                + '<td>' + (alm ? h(alm.nombre) + sub(alm.tipo === 'GENERAL' ? 'Principal' : 'Proyecto') : '—') + '</td>'
                + '<td>' + destino + '</td>'
                + '<td><button type="button" class="anf-pdf-btn" onclick="window.openPdfPreview(\'' + hj(urlNota(n.nota)) + '\', \'nota_entrega\', \'Nota ' + hj(n.nota) + '\', 0, \'\', true, \'almacen\');" title="Ver Nota ' + h(n.nota) + ' (PDF)">'
                + '<i class="material-icons">description</i></button></td></tr>';
        }).join('');
    }

    // N° de Nota de las salidas, las más recientes primero (500). Con `f`, solo las del almacén
    // de ese filtro (el mismo criterio que la tabla); sin él, las de todos los visibles.
    function notasFiltro(vista, f, porDefecto) {
        const ctx = contexto(vista);
        const ver = f ? almacenesDeMov(ctx, f, porDefecto).ids : new Set(ctx.visibles.map((a) => a.id));
        const vistos = new Map();
        vista.filas('movimientos').forEach((m) => {
            if (m.tipo === 'SALIDA' && lleno(m.nota) && ver.has(m.alm)) vistos.set(norm(m.nota), m.nota);
        });
        return Array.from(vistos.values()).sort((a, b) => orden.compare(b, a)).slice(0, 500);
    }

    // Catálogo activo para los buscadores (ProductoInventario::listaAutocomplete).
    function listaProductos(vista) {
        return vista.filas('productos').filter((p) => p.activo)
            .sort((a, b) => ordenNulos(a.nombre, b.nombre) || (a.id - b.id))
            .map((p) => ({
                ID_PRODUCTO: p.id, CODIGO: p.codigo, NOMBRE: p.nombre, UM: p.um, CATEGORIA: p.cat,
                EQUIV: (p.partes || []).join(' '), PARTE: (p.partes || [])[0] || '', PARTES: p.partes || [],
            }));
    }

    // Texto del aviso de los números que se quitaron de la búsqueda (sinNumeros de inventario()
    // y movimientos()): uno solo para Inventario y el historial. '' si no hay ninguno.
    function avisoSinNumeros(nums) {
        if (!nums || !nums.length) return '';
        return '<i class="material-icons">info_outline</i><div>Ningún producto tiene '
            + nums.map((n) => '<b>' + h(n) + '</b>').join(', ')
            + ' junto a lo demás escrito: mostrando sin ese número.</div>';
    }

    window.AlmacenLocal = { inventario, idsInventario, panelOtros, movimientos, consumo, notas, notasFiltro, listaProductos, stockConPendientes, bolsasAuditoria, fmt, avisoSinNumeros };
})();
