/**
 * Equipos desde la COPIA LOCAL — window.EquiposLocal.
 *
 * /admin/equipos se filtra, cuenta y pinta SOLO aquí, con o sin internet: una sola lógica.
 * Las filas salen de window.CopiaLocal (dominio 'equipos', App\Support\Sincronizacion\
 * DominioEquipos): tablas 'equipos' y 'auxiliares', catálogos frentes / tipos / tiposAux /
 * alcance.
 *
 *   EquiposLocal.consultar(vista, filtros, offset, puede, cuantos) → el objeto que pinta
 *       loadEquipos (equipos_index.js): html de las filas (y `filas`, el de cada <tr>), equiposData / auxData de los modales,
 *       Consolidado (stats y auxConsolidado), Distribución, Detalles y aviso de venta.
 *       `filtros` = window.eqFiltrosActivos() (+ ids_in); `puede` = { editar, superAdmin,
 *       subirPdf } de la página; `cuantos` = filas del lote (150 si no se dice).
 *   EquiposLocal.idsExportar(vista, filtros) → { equipos, auxiliares, modoAux }: lo que el
 *       Excel tiene que traer, en el orden de la tabla.
 *   EquiposLocal.opcionesDocumento(vista, filtros, campo) → nombres de las ventanas
 *       «Titular» / «Aseguradora» con los demás filtros puestos.
 *
 * Reglas (las mismas que tenía el servidor):
 *   · Sin ningún filtro la tabla abre VACÍA y las cifras en '--' (pedido del cliente).
 *   · "Todos los frentes" sin búsqueda ni filtro concreto deja fuera los frentes fuera de la
 *     flota (ESPECIAL, VENDIDO, TRÁMITE DE VENTA). Con un filtro concreto entran, pero sus
 *     cifras no suman (los de venta van aparte, en rojo, en la Distribución).
 *   · Elegir un tipo de auxiliar (tipo_aux:X) o categoría AUXILIARES = la tabla es de
 *     auxiliares. Con un eje compartido (frente, búsqueda, año, título o certificado) y sin
 *     un filtro solo de equipos, los auxiliares que coinciden salen al final de la tabla.
 *   · Comparar como la base (colación utf8mb4_unicode_ci): sin mayúsculas ni acentos, y la
 *     igualdad ignora los espacios del final.
 *   · La búsqueda respeta los frentes del usuario: la copia solo trae lo que puede ver.
 *
 * Sin conexión, el cambio de estado y la movilización van a la bandeja (window.Bandeja), a los
 * mismos endpoints de siempre, y se ven al instante: lo que aún no subió se aplica encima de la
 * copia (encima()).
 */
(function () {
    'use strict';

    const POR_LOTE = 150;
    const h = (s) => window.escapeHtml(s);
    const hj = (s) => window.escapeAttrJs(s);

    // ── Comparar como la base ─────────────────────────────────────────────────
    const norm = (s) => String(s == null ? '' : s).normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase();
    const txt = (v) => String(v == null ? '' : v).trim();
    const lleno = (v) => txt(v) !== '';
    const igual = (a, b) => a != null && norm(a).replace(/ +$/, '') === norm(b).replace(/ +$/, '');
    const como = (v, buscado) => v != null && norm(v).indexOf(norm(buscado)) >= 0;   // LIKE '%x%'
    const cargado = (link) => lleno(link);
    const mismoNumero = (a, b) => a != null && lleno(b) && Number(a) === Number(txt(b));
    const orden = new Intl.Collator('es', { sensitivity: 'base' });
    const ordenNulos = (a, b) => (a == null ? (b == null ? 0 : -1) : (b == null ? 1 : orden.compare(String(a), String(b))));

    // Documentos: parámetro del filtro → campo de la fila (Equipo::toDetailsPayload).
    const DOCS = {
        filter_propiedad: 'linkPropiedad', filter_poliza: 'linkSeguro', filter_rotc: 'linkRotc',
        filter_racda: 'linkRacda', filter_adicional: 'linkAdicional', filter_adicional_2: 'linkAdicional2',
    };
    const DOCS_ROTULO = {
        filter_propiedad: 'Propiedad', filter_poliza: 'Póliza', filter_rotc: 'ROTC',
        filter_racda: 'RACDA', filter_adicional: 'Certificado', filter_adicional_2: 'Compraventa',
    };
    // Los que los auxiliares también tienen (título de propiedad y certificado).
    const DOCS_COMPARTIDOS = ['filter_propiedad', 'filter_adicional'];

    // ── Contexto: catálogos de la copia ───────────────────────────────────────
    function contexto(vista) {
        const frentes = new Map(vista.catalogo('frentes').map((f) => [f.id, f]));
        const alcance = vista.catalogo('alcance')[0] || { local: false, frentes: [] };
        return {
            frentes,
            tipos: new Map(vista.catalogo('tipos').map((t) => [t.id, t])),
            tiposAux: new Map(vista.catalogo('tiposAux').map((t) => [t.id, t.nombre])),
            local: !!alcance.local,
            permitidos: (alcance.frentes || []).map(Number),
            fuera: (id) => id != null && !!(frentes.get(id) || {}).fueraDeFlota,
            venta: (id) => (id != null && (frentes.get(id) || {}).venta) || null,
        };
    }
    const etiquetaAux = (ctx, tipo) => ctx.tiposAux.get(tipo) || norm(String(tipo || '').replace(/_/g, ' '));
    const nombreTipo = (ctx, e) => (e.idTipo != null && ctx.tipos.has(e.idTipo) ? ctx.tipos.get(e.idTipo).nombre : null);

    // ── Qué pidió el usuario ──────────────────────────────────────────────────
    function tipoPedido(f) {
        let v = txt(f.id_tipo);
        if (v === '' || v === 'all' || v.indexOf('tipo_aux:') === 0) return null;
        if (v.indexOf('tipo_eq:') === 0) v = v.slice(8);
        return /^\d+$/.test(v) ? Number(v) : 0;
    }
    const siNo = (v) => ['SI', 'NO'].indexOf(txt(v).toUpperCase()) >= 0;
    const docsActivos = (f) => Object.keys(DOCS).filter((p) => f[p] === 'true');
    const presencia = (v) => (['con', 'sin', 'all'].indexOf(v) >= 0 ? v : 'con');

    function filtroConcreto(f) {
        if (lleno(f.search_query) || tipoPedido(f) !== null) return true;
        if (['modelo', 'marca', 'detalle_ubicacion', 'detalle', 'titular', 'aseguradora', 'combustible', 'anio', 'categoria', 'estado', 'color']
            .some((p) => lleno(f[p]))) return true;
        return siNo(f.gps) || siNo(f.confirmado) || docsActivos(f).length > 0;
    }
    const hayFiltro = (f) => lleno(f.id_frente) || lleno(f.id_tipo) || lleno(f.confirmado) || lleno(f.gps) || filtroConcreto(f);
    const modoAux = (f) => txt(f.id_tipo).indexOf('tipo_aux:') === 0 || norm(txt(f.categoria)) === 'AUXILIARES';

    // Un atributo de vehículo o un documento que solo tienen los equipos: sin auxiliares.
    function soloEquipos(f) {
        const cat = norm(txt(f.categoria));
        return (lleno(f.id_tipo) && txt(f.id_tipo) !== 'all')
            || ['modelo', 'marca', 'color', 'gps', 'combustible', 'detalle', 'titular', 'aseguradora'].some((p) => lleno(f[p]))
            || (cat !== '' && cat !== 'AUXILIARES')
            || docsActivos(f).some((p) => DOCS_COMPARTIDOS.indexOf(p) < 0);
    }
    const ejeCompartido = (f) => lleno(f.id_frente) || lleno(f.search_query) || lleno(f.anio)
        || DOCS_COMPARTIDOS.some((p) => f[p] === 'true');
    const porFrente = (f) => !(lleno(f.id_frente) && f.id_frente !== 'all')
        && ((lleno(f.id_tipo) && f.id_tipo !== 'all') || lleno(f.modelo));
    // Con "todos los frentes" las cifras van sin lo de frentes en venta; dentro de un frente, enteras.
    const restaVenta = (idFrente) => !/^\d+$/.test(txt(idFrente));

    // ── Filtro de EQUIPOS ─────────────────────────────────────────────────────
    function coincideBusqueda(e, texto) {
        const s = norm(txt(texto));
        if (!s) return true;
        if (s.indexOf('#') >= 0) return como(e.etiqueta, s.replace(/#/g, ''));
        if (como(e.chasis, s) || como(e.motorSerial, s) || como(e.codigo, s) || como(e.etiqueta, s)) return true;
        // La placa se teclea con letra O o con cero: se prueban las variantes.
        return [s, s.replace(/O/g, '0'), s.replace(/0/g, 'O'), s.replace(/O/g, '0').replace(/0/g, 'O')]
            .some((v) => como(e.placaReal, v));
    }

    // `sin`: ejes que no cuentan (para que una lista no se recorte por su propio filtro).
    function filtroEquipos(f, ctx, sin) {
        sin = sin || [];
        if (f.ids_in && f.ids_in.length && sin.indexOf('ids_in') < 0) {
            const ids = new Set(f.ids_in.map(Number));
            return (e) => ids.has(e.id);
        }
        const concreto = filtroConcreto(f);
        const tipo = sin.indexOf('id_tipo') < 0 ? tipoPedido(f) : null;
        const frente = txt(f.id_frente);
        const docs = docsActivos(f).filter((p) => sin.indexOf(p) < 0);
        const modo = presencia(f.doc_presence);
        const usa = (p) => sin.indexOf(p) < 0 && lleno(f[p]);
        const categoria = usa('categoria') && norm(txt(f.categoria)) !== 'AUXILIARES';
        const gps = usa('gps') ? txt(f.gps).toUpperCase() : '';
        const conf = usa('confirmado') ? txt(f.confirmado).toUpperCase() : '';

        return (e) => {
            if (sin.indexOf('id_frente') < 0) {
                if (frente === 'none') { if (e.frenteId != null) return false; }
                else if (frente !== '' && frente !== 'all') { if (String(e.frenteId) !== frente) return false; }
                else if (!concreto && ctx.fuera(e.frenteId)) return false;
            }
            if (tipo !== null && e.idTipo !== tipo) return false;
            if (usa('modelo') && !igual(e.modelo, f.modelo)) return false;
            if (usa('marca') && !igual(e.marca, f.marca)) return false;
            if (usa('detalle_ubicacion') && !igual(e.detalleUbicacion, f.detalle_ubicacion)) return false;
            if (usa('combustible') && !igual(e.combustibleReal, txt(f.combustible))) return false;
            if (usa('detalle') && !como(e.detalleUbicacion, txt(f.detalle))) return false;
            if (usa('titular') && !como(e.titularReal, txt(f.titular))) return false;
            if (usa('aseguradora') && !como(e.aseguradoraReal, txt(f.aseguradora))) return false;
            if (usa('anio') && !mismoNumero(e.anio, f.anio)) return false;
            if (categoria && !igual(e.categoria, f.categoria)) return false;
            if (usa('estado') && !igual(e.estado, f.estado)) return false;
            if (gps === 'SI' && !cargado(e.linkGps)) return false;
            if (gps === 'NO' && cargado(e.linkGps)) return false;
            if (usa('color') && !igual(e.colorReal, f.color)) return false;
            if (conf === 'SI' && e.confirmado !== 1) return false;
            if (conf === 'NO' && e.confirmado !== 0) return false;
            if (modo !== 'all' && docs.some((p) => cargado(e[DOCS[p]]) !== (modo === 'con'))) return false;
            return sin.indexOf('search_query') >= 0 || coincideBusqueda(e, f.search_query);
        };
    }

    // Orden de la tabla: nombre del tipo, código de patio y id (los vacíos primero, como la base).
    function ordenarEquipos(filas, ctx) {
        return filas.map((e) => [nombreTipo(ctx, e), e]).sort((a, b) =>
            ordenNulos(a[0], b[0]) || ordenNulos(a[1].codigo, b[1].codigo) || a[1].id - b[1].id
        ).map((x) => x[1]);
    }

    // ── Filtro de AUXILIARES ──────────────────────────────────────────────────
    // Los ejes de equipos que los auxiliares comparten, con sus nombres.
    function auxCompartido(f) {
        return {
            id_frente: f.id_frente, estado: f.estado, search: f.search_query,
            detalle_ubicacion: f.detalle_ubicacion, confirmado: f.confirmado, anio: f.anio,
            con_propiedad: f.filter_propiedad === 'true', con_certificado: f.filter_adicional === 'true',
            doc_presence: f.doc_presence,
        };
    }
    // Modo auxiliar: además el tipo (tipo_aux:X), la marca y el modelo.
    function auxModo(f) {
        return Object.assign(auxCompartido(f), {
            tipo: txt(f.id_tipo).indexOf('tipo_aux:') === 0 ? txt(f.id_tipo).slice(9) : '',
            marca: f.marca, modelo: f.modelo, doc_presence: null,
        });
    }
    const auxConcreto = (r) => ['search', 'marca', 'modelo', 'capacidad', 'anio', 'detalle_ubicacion'].some((p) => lleno(r[p]))
        || (lleno(r.tipo) && r.tipo !== 'all') || (lleno(r.estado) && r.estado !== 'all')
        || siNo(r.confirmado) || r.con_propiedad || r.con_certificado;

    function filtroAux(r, ctx, sin) {
        sin = sin || [];
        const porAtributo = lleno(r.marca) || lleno(r.modelo);   // la marca o el modelo cruzan tipos
        const tipo = sin.indexOf('tipo') < 0 && !porAtributo && lleno(r.tipo) && r.tipo !== 'all' ? r.tipo : null;
        const frente = txt(r.id_frente);
        const concreto = auxConcreto(r);
        const modo = presencia(r.doc_presence);
        const docs = [];
        if (r.con_propiedad && sin.indexOf('con_propiedad') < 0) docs.push('propiedad');
        if (r.con_certificado && sin.indexOf('con_certificado') < 0) docs.push('certificado');
        const conf = txt(r.confirmado).toUpperCase();
        const s = txt(r.search);

        return (a) => {
            if (tipo !== null && !igual(a.tipo, tipo)) return false;
            if (frente === 'none') { if (!ctx.local && a.idFrente != null) return false; }
            else if (frente !== '' && frente !== 'all') {
                // Un LOCAL que pide un frente que no es suyo: el filtro no aplica (como antes).
                if ((!ctx.local || ctx.permitidos.indexOf(Number(frente)) >= 0) && String(a.idFrente) !== frente) return false;
            } else if (!concreto && ctx.fuera(a.idFrente)) return false;
            if (lleno(r.detalle_ubicacion) && !igual(a.detalle, txt(r.detalle_ubicacion))) return false;
            if (lleno(r.estado) && r.estado !== 'all' && !igual(a.estado, r.estado)) return false;
            if (lleno(r.marca) && !como(a.marca, txt(r.marca))) return false;
            if (lleno(r.modelo) && !como(a.modelo, txt(r.modelo))) return false;
            if (lleno(r.capacidad) && !como(a.capacidad, txt(r.capacidad))) return false;
            if (lleno(r.anio) && !mismoNumero(a.anio, r.anio)) return false;
            if (modo !== 'all' && docs.some((d) => cargado(a[d]) !== (modo === 'con'))) return false;
            if (conf === 'SI' && a.confirmado !== 1) return false;
            if (conf === 'NO' && a.confirmado !== 0) return false;
            return !s || como(a.serial, s) || como(a.codigo, s) || como(a.marca, s) || como(a.modelo, s);
        };
    }
    const ordenarAux = (filas) => filas.slice().sort((x, y) =>
        (x.creado === y.creado ? y.id - x.id : (String(x.creado || '') < String(y.creado || '') ? 1 : -1)));

    // ── Cifras ────────────────────────────────────────────────────────────────
    function statsEquipos(todos, f, ctx) {
        const restar = restaVenta(f.id_frente);
        const disponible = (e) => !restar || !ctx.venta(e.frenteId);
        const base = todos.filter(filtroEquipos(f, ctx)).filter(disponible);
        const conDesinc = norm(txt(f.estado)) === 'DESINCORPORADO';
        const cuenta = (filas, estado) => filas.filter((e) => igual(e.estado, estado)).length;
        const stats = {
            total: conDesinc ? base.length : base.filter((e) => e.estado != null && !igual(e.estado, 'DESINCORPORADO')).length,
            activos: cuenta(base, 'OPERATIVO'),
            inactivos: cuenta(base, 'INOPERATIVO'),
            mantenimiento: cuenta(base, 'EN MANTENIMIENTO'),
            desincorporados: cuenta(base, 'DESINCORPORADO'),
        };
        // Con un documento tildado: cuántos lo tienen y cuántos no, sobre el universo sin ese recorte.
        const docs = docsActivos(f);
        if (docs.length) {
            const universo = todos.filter(filtroEquipos(f, ctx, Object.keys(DOCS))).filter(disponible)
                .filter((e) => conDesinc || (e.estado != null && !igual(e.estado, 'DESINCORPORADO')));
            stats.doc_mode = true;
            stats.doc_label = docs.length === 1 ? DOCS_ROTULO[docs[0]] : 'Documentos';
            stats.doc_total = universo.length;
            stats.doc_con = universo.filter((e) => docs.every((p) => cargado(e[DOCS[p]]))).length;
            stats.doc_sin = stats.doc_total - stats.doc_con;
        }
        return stats;
    }

    function statsAux(filas, r, ctx, docs) {
        const restar = restaVenta(r.id_frente);
        const disp = filas.filter((a) => !restar || !ctx.venta(a.idFrente));
        const stats = {
            total: disp.length,
            activos: disp.filter((a) => igual(a.estado, 'OPERATIVO')).length,
            inactivos: disp.filter((a) => igual(a.estado, 'INOPERATIVO')).length,
        };
        if (docs && docs.length) {
            stats.doc_mode = true;
            stats.doc_label = docs.length === 2 ? 'Documentos' : (docs[0] === 'propiedad' ? 'Propiedad' : 'Certificado');
            stats.doc_total = stats.total;
            stats.doc_con = disp.filter((a) => docs.every((d) => cargado(a[d]))).length;
            stats.doc_sin = stats.doc_total - stats.doc_con;
        }
        return stats;
    }

    // Consolidado de auxiliares de la card de abajo: sin el tipo ni el recorte del documento.
    function consolidadoAux(aux, r, ctx) {
        const base = aux.filter(filtroAux(r, ctx, ['tipo', 'con_propiedad', 'con_certificado']));
        const docs = [];
        if (r.con_propiedad) docs.push('propiedad');
        if (r.con_certificado) docs.push('certificado');
        return statsAux(base, r, ctx, docs);
    }

    // ── Distribución ──────────────────────────────────────────────────────────
    // Agrupa filas por una clave; con `restar`, las de frentes en venta van aparte (vendidos /
    // en trámite) y no suman en total.
    function agrupar(filas, clave, frenteDe, ctx, restar) {
        const grupos = new Map();
        filas.forEach((x) => {
            const k = clave(x);
            let g = grupos.get(k);
            if (!g) grupos.set(k, g = { clave: k, fila: x, total: 0, vendidos: 0, en_tramite: 0 });
            const venta = restar ? ctx.venta(frenteDe(x)) : null;
            if (venta && venta.clase === 'vendido') g.vendidos++;
            else if (venta) g.en_tramite++;
            else g.total++;
        });
        return Array.from(grupos.values());
    }
    const ventaAlFinal = (grupos, ctx) => grupos.filter((g) => !ctx.venta(g.clave)).concat(grupos.filter((g) => ctx.venta(g.clave)));

    function filaDistribucion(alTocar, nombre, total, pct, barra, ventaFila, vendidos, enTramite, pctVenta) {
        const nota = [];
        if (vendidos > 0) nota.push(vendidos + (vendidos === 1 ? ' vendido' : ' vendidos'));
        if (enTramite > 0) nota.push(enTramite + ' en trámite de venta');
        return '<li class="dist-fila" onclick="' + alTocar + '"><div class="dist-cab"><span class="dist-nombre">'
            + (ventaFila ? '<small class="dist-rotulo">' + h(ventaFila.etiqueta) + ':</small> ' : '') + h(nombre)
            + (nota.length ? '<small class="dist-nota">' + nota.join(' · ') + '</small>' : '')
            + '</span><span class="dist-cant">' + total + '</span></div>'
            + '<div class="dist-barra"><div class="dist-barra-' + (ventaFila ? 'roja' : barra) + '" style="width: ' + pct + '%;"></div>'
            + (pctVenta > 0 ? '<div class="dist-barra-roja" style="width: ' + pctVenta + '%;"></div>' : '') + '</div></li>';
    }
    function tituloDistribucion(icono, color, titulo, camara) {
        // La cámara descarga la card como imagen (fleet_dashboard.js · descargarPanelHtmlFDM).
        return '<h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">'
            + '<i class="material-icons" style="font-size: 18px; color: ' + color + ';">' + icono + '</i>' + titulo
            + (camara ? '<button type="button" onclick="event.stopPropagation(); window.descargarPanelHtmlFDM(\'distributionStatsContainer\', \'' + camara + '\')" title="Descargar imagen" class="fdm-cam" style="margin-left: auto;"><i class="material-icons">photo_camera</i></button>' : '')
            + '</h4>';
    }
    const filtrarFrente = (id, nombre) => "selectOption('frenteFilterSelect', '" + hj(id) + "', '" + hj(txt(nombre)) + "'); loadEquipos();";

    // Lista por frente (cada fila un frente; los de venta en rojo y al final).
    function listaFrentes(grupos, ctx, camara) {
        const suma = grupos.reduce((n, g) => n + g.total, 0);
        return tituloDistribucion('map', '#10b981', 'Ubicación por Frente', camara) + '<ul class="dist-lista custom-scrollbar">'
            + grupos.map((g) => {
                const nombre = g.clave != null && ctx.frentes.has(g.clave) ? ctx.frentes.get(g.clave).nombre : null;
                return filaDistribucion(filtrarFrente(g.clave == null ? '' : g.clave, nombre || 'Sin Asignar'), nombre || 'Sin Asignar',
                    g.total, suma > 0 ? (g.total / suma) * 100 : 0, 'verde', ctx.venta(g.clave), 0, 0, 0);
            }).join('') + '</ul>';
    }
    // Lista por tipo: la barra se mide sobre todo (disponible + en venta); lo en venta, en rojo.
    function listaTipos(grupos, icono, color, titulo, barra, camara, alTocar, nombre) {
        const suma = grupos.reduce((n, g) => n + g.total + g.vendidos + g.en_tramite, 0);
        return tituloDistribucion(icono, color, titulo, camara) + '<ul class="dist-lista custom-scrollbar">'
            + grupos.map((g) => filaDistribucion(alTocar(g), nombre(g), g.total, suma > 0 ? (g.total / suma) * 100 : 0, barra, null,
                g.vendidos, g.en_tramite, suma > 0 ? ((g.vendidos + g.en_tramite) / suma) * 100 : 0)).join('') + '</ul>';
    }

    function distribucionEquipos(todos, f, ctx) {
        if (porFrente(f)) {
            const filas = todos.filter(filtroEquipos(f, ctx, ['id_frente'])).filter((e) => e.frenteId != null);
            const grupos = agrupar(filas, (e) => e.frenteId, (e) => e.frenteId, ctx, false)
                .sort((a, b) => ordenNulos((ctx.frentes.get(a.clave) || {}).nombre, (ctx.frentes.get(b.clave) || {}).nombre));
            return listaFrentes(ventaAlFinal(grupos, ctx), ctx, 'ubicacion_por_frente');
        }
        const grupos = agrupar(todos.filter(filtroEquipos(f, ctx, ['id_tipo'])), (e) => e.idTipo, (e) => e.frenteId, ctx, restaVenta(f.id_frente))
            .map((g) => Object.assign(g, { nombre: nombreTipo(ctx, g.fila) }))
            .sort((a, b) => ordenNulos(a.nombre, b.nombre));
        return listaTipos(grupos, 'autorenew', '#3b82f6', 'Equipos y Maquinaria', 'azul', 'equipos_y_maquinaria',
            (g) => "selectOption('tipoFilterSelect', '" + (g.clave == null ? '' : g.clave) + "', '" + hj(txt(g.nombre)) + "'); loadEquipos();",
            (g) => g.nombre || 'Desconocido');
    }

    function distribucionAux(aux, r, ctx) {
        const filas = aux.filter(filtroAux(r, ctx, ['tipo'])).filter((a) => lleno(a.tipo));
        const porTipo = agrupar(filas, (a) => norm(a.tipo), (a) => a.idFrente, ctx, restaVenta(r.id_frente))
            .map((g) => Object.assign(g, { nombre: etiquetaAux(ctx, g.fila.tipo) }))
            .sort((a, b) => (b.total + b.vendidos + b.en_tramite) - (a.total + a.vendidos + a.en_tramite) || orden.compare(a.nombre, b.nombre));
        if (lleno(r.tipo) && r.tipo !== 'all' && !(lleno(r.id_frente) && r.id_frente !== 'all')) {
            const grupos = agrupar(aux.filter(filtroAux(r, ctx)), (a) => a.idFrente, (a) => a.idFrente, ctx, false)
                .sort((a, b) => b.total - a.total || ordenNulos((ctx.frentes.get(a.clave) || {}).nombre, (ctx.frentes.get(b.clave) || {}).nombre));
            if (grupos.length) return listaFrentes(ventaAlFinal(grupos, ctx), ctx, null);
        }
        return listaTipos(porTipo, 'autorenew', '#10b981', 'Auxiliares', 'verde', null,
            (g) => "selectOption('tipoFilterSelect', 'tipo_aux:" + hj(g.fila.tipo) + "', '" + hj(g.nombre) + "'); loadEquipos();",
            (g) => g.nombre);
    }

    // «Detalles» de un frente fuera de la flota: cuántos hay en cada DETALLE_UBICACION_ACTUAL.
    function detalles(todos, f, ctx) {
        const grupos = agrupar(todos.filter(filtroEquipos(f, ctx, ['detalle_ubicacion'])),
            (e) => (lleno(e.detalleUbicacion) ? norm(txt(e.detalleUbicacion)) : ''), () => null, ctx, false)
            .sort((a, b) => b.total - a.total);
        const suma = grupos.reduce((n, g) => n + g.total, 0);
        const lista = grupos.length ? grupos.map((g) => {
            const valor = g.clave === '' ? '' : txt(g.fila.detalleUbicacion);
            return '<li onclick="const f = document.getElementById(\'detalleUbicacionFilter\'); if (f) { f.value = \'' + hj(valor) + '\'; loadEquipos(); }"'
                + ' style="padding-bottom: 4px; border-bottom: 1px dashed #f1f5f9; transition: opacity 0.2s; cursor: ' + (valor ? 'pointer' : 'default') + ';"'
                + (valor ? ' onmouseover="this.style.opacity=\'0.7\'" onmouseout="this.style.opacity=\'1\'"' : '') + '>'
                + '<div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; gap: 4px;">'
                + '<span style="color: #334155; font-size: 12.5px; font-weight: 600; word-break: break-word; line-height: 1.25; flex: 1;">' + h(valor || 'Sin Especificación') + '</span>'
                + '<span style="font-weight: 700; color: #1e293b; font-size: 12.5px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; flex-shrink: 0; white-space: nowrap;">' + g.total + '</span></div>'
                + '<div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">'
                + '<div style="width: ' + (suma > 0 ? (g.total / suma) * 100 : 0) + '%; height: 100%; background: linear-gradient(90deg, #f97316 0%, #ea580c 100%); border-radius: 2px;"></div></div></li>';
        }).join('') : '<li style="color: #94a3b8; font-size: 12px; text-align: center; padding: 10px 0;">Sin datos</li>';
        // Arranca recogida: el encabezado, con el total, la despliega (eqToggleDetalles).
        return '<div class="eq-detalles is-recogido">'
            + '<h4 class="eq-detalles-toggle" role="button" tabindex="0" aria-expanded="false" title="Desplegar / recoger los detalles"'
            + ' onclick="eqToggleDetalles(this)" onkeydown="if (event.key === \'Enter\' || event.key === \' \') { event.preventDefault(); eqToggleDetalles(this); }"'
            + ' style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">'
            + '<i class="material-icons" style="font-size: 18px; color: #f97316;">place</i>Detalles'
            + '<span style="font-weight: 700; color: #1e293b; font-size: 12.5px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">' + suma + '</span>'
            + '<i class="material-icons eq-detalles-caret" style="font-size: 20px; color: #64748b; margin-left: auto;">expand_more</i></h4>'
            + '<ul class="dist-lista eq-detalles-lista custom-scrollbar">' + lista + '</ul></div>';
    }

    // ── Filas de la tabla ─────────────────────────────────────────────────────
    const ESTADO_EQ = {
        'OPERATIVO':        { color: '#16a34a', bg: '#f0fdf4', icon: 'check_circle', label: 'OPERATIVO' },
        'INOPERATIVO':      { color: '#dc2626', bg: '#fef2f2', icon: 'cancel',       label: 'INOPERATIVO' },
        'EN MANTENIMIENTO': { color: '#d97706', bg: '#fffbeb', icon: 'engineering',  label: 'MANTENIMIENTO' },
        'DESINCORPORADO':   { color: '#475569', bg: '#f1f5f9', icon: 'archive',      label: 'DESINCORP.' },
    };
    const ESTADO_AUX = {
        'OPERATIVO':        { color: '#16a34a', bg: '#f0fdf4', icon: 'check_circle', label: 'OPERATIVO' },
        'INOPERATIVO':      { color: '#dc2626', bg: '#fef2f2', icon: 'cancel',       label: 'INOPERATIVO' },
        'EN MANTENIMIENTO': { color: '#eab308', bg: '#fefce8', icon: 'build',        label: 'MANTENIMIENTO' },
        'EN_ALMACEN':       { color: '#1e40af', bg: '#eff6ff', icon: 'inventory_2',  label: 'EN ALMACÉN' },
        'DESINCORPORADO':   { color: '#475569', bg: '#f1f5f9', icon: 'block',        label: 'DESINCORP.' },
    };
    const etiquetaVenta = (venta) => (venta ? '<span class="etiqueta-venta etiqueta-venta--' + venta.clase + '">' + h(venta.etiqueta) + '</span>' : '');
    const finalizado = (frente) => (frente && frente.estatus === 'FINALIZADO');
    const burbuja = (detalle) => (lleno(detalle) ? '<div class="tooltip-bubble">📍 ' + h(detalle) + '<div class="eq-tip-flecha"></div></div>' : '');

    function filaEquipo(e, ctx, puede) {
        const est = ESTADO_EQ[e.estado] || ESTADO_EQ.DESINCORPORADO;
        const frente = ctx.frentes.get(e.frenteId);
        const cfd = e.confirmado ? 1 : 0;
        const tipo = nombreTipo(ctx, e);
        const placa = lleno(e.placaReal) ? e.placaReal : '';
        const chip = '<i class="material-icons confirm-sitio-chip' + (cfd ? ' eq-cfd' : '') + (puede.editar ? ' eq-click ' : '') + '"'
            + ' data-equipo-id="' + e.id + '" data-confirmado="' + cfd + '"'
            + (puede.editar
                ? ' onclick="event.stopPropagation(); window.toggleConfirmacionSitio(this)" title="' + (cfd ? 'Confirmado en sitio (click para quitar)' : 'Sin confirmar (click para confirmar)') + '"'
                : ' title="' + (cfd ? 'Confirmado en sitio' : 'Sin confirmar') + '"')
            + '>' + (cfd ? 'check_circle' : 'radio_button_unchecked') + '</i>';
        // super.admin: doble clic en la foto → "Vincular a una ficha" (vincular_ficha_modal).
        const foto = '<div class="table-image-wrapper ' + (e.fotoId ? 'eq-foto-wrap' : 'placeholder') + '"'
            + (puede.superAdmin ? ' data-vincular="' + e.id + '" data-espec="' + h(e.idEspec == null ? '' : e.idEspec) + '" data-color="' + h(e.color || '') + '" ondblclick="window.eqVincularFicha(this)" title="Doble clic: vincular a una ficha del catálogo"' : '') + '>'
            + (e.fotoId ? '<img data-src="/storage/google/' + encodeURIComponent(e.fotoId) + '?sz=w300" alt="Foto" class="eq-foto">' : '<span class="material-icons">image_not_supported</span>')
            + etiquetaVenta(ctx.venta(e.frenteId)) + '</div>';
        const falla = e.falla;
        const estado = puede.editar
            ? '<div class="status-trigger-lite" data-equipo-id="' + e.id + '" data-status="' + h(e.estado || '') + '"'
                + ' data-status-url="/admin/equipos/' + e.id + '/status"'
                + ' data-label="' + h(placa || e.chasis || e.codigo || ('Equipo #' + e.id)) + '"'
                + (falla ? ' data-falla-id="' + falla.id + '" data-falla-codigo="' + h(falla.codigo || '') + '" data-falla-tipo="' + h(falla.tipo || '') + '" data-falla-equipo="' + h(falla.equipo || '') + '" data-falla-detalle="' + h(falla.detalle || '') + '"' : '')
                + ' onclick="event.stopPropagation(); openSharedStatusMenu(this)" style="--eq-st-color: ' + est.color + '">'
                + '<div class="eq-status-izq"><i class="material-icons">' + est.icon + '</i><span class="eq-status-txt">' + est.label + '</span></div>'
                + '<i class="material-icons eq-status-chevron">expand_more</i></div>'
            : '<div class="eq-status-fijo" style="--eq-st-bg: ' + est.bg + '; --eq-st-color: ' + est.color + '"><i class="material-icons">' + est.icon + '</i><span>' + est.label + '</span></div>';

        return '<tr>'
            + '<td class="table-cell-custom table-cell-center eq-td-frente"><div class="tooltip-wrapper eq-frente-nom">'
            + '<span class="eq-frente-linea">' + h(frente ? frente.nombre : 'SIN ASIGNAR') + chip + '</span>'
            + (finalizado(frente) ? '<div class="eq-finalizado-wrap"><span class="eq-finalizado"><i class="material-icons">warning</i>FINALIZADO</span></div>' : '')
            + burbuja(e.detalleUbicacion) + '</div>' + foto + '</td>'
            + '<td class="table-cell-custom eq-td-tipo"><div class="eq-linea-fuerte">' + h(tipo || '—')
            + (lleno(e.etiqueta) ? '<span class="eq-etiqueta"><i class="material-icons">tag</i>' + h(e.etiqueta) + '</span>' : '') + '</div>'
            + (lleno(e.categoria) ? '<div class="eq-hide-mobile eq-sub">' + h(e.categoria) + '</div>' : '')
            + (lleno(e.capacidad) ? '<div class="eq-sub eq-sub-junto">' + h(e.capacidad) + '</div>' : '') + '</td>'
            + '<td class="table-cell-custom eq-td-marca"><div class="eq-linea-fuerte">' + h(e.marca || '—')
            + (lleno(e.modelo) ? '<span class="eq-modelo">' + h(e.modelo) + '</span>' : '') + '</div>'
            + (e.anio || e.color ? '<div class="eq-hide-mobile eq-anio">' + (e.anio ? 'Año: ' + h(e.anio) + ' ' : '')
                + (e.color ? '<span class="eq-color">' + (e.anio ? '· ' : '') + h(e.color) + '</span>' : '') + '</div>' : '') + '</td>'
            + '<td class="table-cell-custom eq-td-serial">'
            + '<div class="eq-ser-linea"><strong class="eq-lbl">S:</strong> <span class="eq-val">' + h(e.chasis || '—') + '</span></div>'
            + (lleno(e.motorSerial) ? '<div class="eq-ser-linea eq-ser-sep"><strong class="eq-lbl">M:</strong> <span class="eq-val">' + h(e.motorSerial) + '</span></div>' : '')
            + (placa ? '<div class="eq-ser-corta eq-ser-sep"><strong class="eq-lbl">P:</strong> <span class="eq-val-placa">' + h(placa) + '</span></div>'
                : '<div class="eq-ser-simple eq-ser-sep"><strong class="eq-lbl">P:</strong> <span class="eq-val-vacio">Sin Placa</span></div>')
            + (lleno(e.codigo) ? '<div class="eq-id-line eq-ser-corta eq-ser-sep"><strong class="eq-lbl">ID:</strong> <span class="eq-val-id">#' + h(e.codigo) + '</span></div>' : '')
            + '</td>'
            + '<td class="table-cell-custom eq-td-estatus"' + (falla ? ' data-falla-desc="' + h(falla.desc || '') + '" data-falla-resumen="' + h(falla.resumen || '') + '"' : '') + '>' + estado + '</td>'
            + '<td class="table-cell-center eq-td-acciones"><div class="eq-acciones-wrap"><button type="button"'
            + ' data-equipo-id="' + e.id + '" data-codigo="' + h(e.codigo || '') + '" data-chasis="' + h(e.chasis || '') + '"'
            + ' data-placa="' + h(e.placaReal == null ? 'N/A' : e.placaReal) + '" data-tipo="' + h(tipo || 'SIN TIPO') + '"'
            + ' data-anchor-id="' + h(e.anchorId) + '" data-frente-id="' + h(e.frenteId == null ? '' : e.frenteId) + '"'
            + ' data-rol-anclaje="' + h(e.rolAnclaje) + '" data-anchor-code="' + h(e.anchorCode) + '" data-anchor-placa="' + h(e.anchorPlaca) + '"'
            + ' data-anchor-serial="' + h(e.anchorSerial) + '" data-anchor-rol="' + h(e.anchorRol) + '" data-anchor-tipo-nombre="' + h(e.anchorTipoNombre) + '"'
            + ' data-confirmado="' + cfd + '" onclick="showDetailsImproved(this, event)" class="btn-details-mini" title="Ver Detalles">'
            + '<i class="material-icons">visibility</i></button></div></td></tr>';
    }

    function filaAux(a, ctx, puede) {
        const est = ESTADO_AUX[a.estado] || ESTADO_AUX.DESINCORPORADO;
        const frente = ctx.frentes.get(a.idFrente);
        const tipo = etiquetaAux(ctx, a.tipo);
        const cfd = a.confirmado ? 1 : 0;
        const etiqueta = a.serial || a.codigo || txt((a.marca || '') + ' ' + (a.modelo || '')) || ('Auxiliar #' + a.id);
        const venta = etiquetaVenta(ctx.venta(a.idFrente));
        const foto = a.fotoId
            ? '<div class="table-image-wrapper" style="cursor: default;"><img src="/storage/google/' + encodeURIComponent(a.fotoId) + '?sz=w300" alt="Foto" loading="lazy" decoding="async" style="width:100%; height:100%; object-fit:contain;" onerror="this.style.opacity=\'0.3\';">' + venta + '</div>'
            : '<div class="table-image-wrapper placeholder"><span class="material-icons">image_not_supported</span>' + venta + '</div>';
        const estado = puede.editar
            ? '<div class="aux-status-trigger" data-aux-id="' + a.id + '" data-status="' + h(a.estado || '') + '" data-status-url="/admin/equipos-auxiliares/' + a.id + '/estado"'
                + ' data-label="' + h(etiqueta) + '" onclick="event.stopPropagation(); window.openAuxStatusMenu(this)"'
                + ' style="padding: 5px 8px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; gap: 4px; font-size: 11px; font-weight: 700; background: white; border: 1px solid #e2e8f0; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">'
                + '<div style="display: flex; align-items: center; gap: 5px; color: ' + est.color + ';"><i class="material-icons" style="font-size: 14px;">' + est.icon + '</i>'
                + '<span class="aux-status-label" style="color: #334155; text-transform: uppercase;">' + est.label + '</span></div>'
                + '<i class="material-icons" style="font-size: 14px; color: #94a3b8;">expand_more</i></div>'
            : '<div style="padding: 5px 8px; border-radius: 8px; display: flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; background: ' + est.bg + '; border: 1px solid ' + est.bg + '; color: ' + est.color + '; text-transform: uppercase;">'
                + '<i class="material-icons" style="font-size: 14px;">' + est.icon + '</i><span>' + est.label + '</span></div>';
        const sub = (margen) => 'font-size: 12px; color: #64748b; font-weight: 600; text-transform: uppercase; margin-top: ' + margen + '; letter-spacing: 0.3px;';

        // aux-row-clickable siempre: seleccionar no pide permiso; las acciones lo comprueban.
        return '<tr data-aux-id="' + a.id + '" data-codigo="' + h(a.codigo || a.serial || '') + '" data-frente="' + h(frente ? frente.nombre : 'Sin Asignar') + '"'
            + ' data-frente-id="' + h(a.idFrente == null ? '' : a.idFrente) + '" data-tipo="' + h(tipo) + '" class="aux-row-selectable aux-row-clickable" style="cursor:pointer;">'
            + '<td class="table-cell-custom table-cell-center" style="padding: 6px 4px; width: 150px;">'
            + '<div class="tooltip-wrapper" style="font-size: 13px; color: #000; margin-bottom: 5px; line-height: 1.25; font-weight: 700; text-align: center; text-transform: uppercase; word-wrap: break-word; position: relative; cursor: default;">'
            + '<span style="display:inline-flex; align-items:center; gap:3px; justify-content:center;">' + h(frente ? frente.nombre : 'SIN ASIGNAR')
            + '<i class="material-icons confirm-sitio-chip-aux" data-aux-id="' + a.id + '" data-confirmado="' + cfd + '"'
            + (puede.editar
                ? ' onclick="event.stopPropagation(); window.toggleConfirmacionSitioAux(this)" title="' + (cfd ? 'Confirmado en sitio (click para quitar)' : 'Sin confirmar (click para confirmar)') + '"'
                : ' title="' + (cfd ? 'Confirmado en sitio' : 'Sin confirmar') + '"')
            + ' style="font-size:14px; color:' + (cfd ? '#16a34a' : '#cbd5e0') + ';' + (puede.editar ? ' cursor:pointer;' : '') + '">' + (cfd ? 'check_circle' : 'radio_button_unchecked') + '</i></span>'
            + (finalizado(frente) ? '<div style="display:flex; align-items:center; justify-content:center; gap:3px; margin-top:3px;"><span style="background:#fef2f2; color:#dc2626; padding:1px 6px; border-radius:8px; font-size:9px; font-weight:700; display:inline-flex; align-items:center; gap:2px; border:1px solid #fecaca;"><i class="material-icons" style="font-size:10px;">warning</i>FINALIZADO</span></div>' : '')
            + burbuja(a.detalle) + '</div>' + foto + '</td>'
            // Junto a los equipos, que llevan su categoría bajo el tipo, el auxiliar se rotula así.
            + '<td class="table-cell-custom" style="font-size: 14.5px; color: #000; word-wrap: break-word;">'
            + '<div style="font-weight: 700; text-transform: uppercase; line-height: 1.3;">' + h(tipo) + '</div>'
            + '<div style="' + sub('5px') + '">Equipos Auxiliares</div>'
            + (lleno(a.capacidad) ? '<div style="' + sub('3px') + '">' + h(a.capacidad) + '</div>' : '') + '</td>'
            + '<td class="table-cell-custom" style="font-size: 13px; color: #000; word-wrap: break-word;">'
            + '<div style="font-weight: 700; text-transform: uppercase; line-height: 1.3;">' + h(a.marca || '—') + '</div>'
            + (lleno(a.modelo) ? '<div style="font-size: 13.5px; color: #475569; font-weight: 500; text-transform: uppercase; margin-top: 3px; line-height: 1.3;">' + h(a.modelo) + '</div>' : '') + '</td>'
            + '<td class="table-cell-custom" style="font-size: 14px; color: #4a5568;">'
            + '<div style="line-height: 1.5; word-break: break-all;"><strong style="color:#64748b;">S:</strong> <span style="color:#1e293b; font-weight:600; text-transform:uppercase;">' + h(a.serial || '—') + '</span></div>'
            + (lleno(a.codigo) ? '<div style="line-height: 1.4; margin-top: 3px;"><strong style="color:#64748b;">Cod:</strong> <span style="color:#1e293b; font-weight:600; text-transform:uppercase;">#' + h(String(a.codigo).toUpperCase()) + '</span></div>' : '')
            + (a.anio ? '<div style="font-size: 12.5px; color: #64748b; font-weight: 500; margin-top: 3px;">Año: ' + h(a.anio) + '</div>' : '') + '</td>'
            + '<td class="table-cell-custom" style="padding: 8px 2px; width: 120px;">' + estado + '</td>'
            + '<td class="table-cell-center aux-action-cell" style="padding: 8px 5px; width: 72px; text-align: center; vertical-align: middle;">'
            + '<div style="display:flex; justify-content:center; align-items:center; gap:4px;"><button type="button" data-aux-id="' + a.id + '" data-tipo-label="' + h(tipo) + '"'
            + ' data-marca="' + h(a.marca || '') + '" data-modelo="' + h(a.modelo || '') + '" onclick="event.stopPropagation(); window.openAuxDetailsModal(this, event)" class="btn-details-mini" title="Ver Detalles">'
            + '<i class="material-icons">visibility</i></button></div></td></tr>';
    }

    const filaMensaje = (icono, texto) => '<tr><td colspan="6" class="table-empty-state"><i class="material-icons">' + icono + '</i>' + texto + '</td></tr>';

    // Datos del modal del auxiliar: lo de la copia más lo que depende de quién mira.
    const detalleAux = (a, puede) => Object.assign({}, a.det, {
        edit_url: '/admin/equipos-auxiliares/' + a.id + '/edit',
        can_upload_pdf: !!puede.subirPdf,
    });

    // ── Lo que aún no subió (sin conexión) va encima de la copia ──────────────
    function encima(filas, ctx) {
        const pendientes = window.Bandeja.pendientes().filter((it) => it.estado === 'pendiente' && it.datos);
        if (!pendientes.length) return filas;
        const cambios = new Map();
        pendientes.forEach((it) => {
            const p = it.datos;
            if (it.tipo === 'estado') {
                cambios.set(Number(p.id_equipo), Object.assign(cambios.get(Number(p.id_equipo)) || {}, { estado: p.status }));
            } else if (it.tipo === 'movilizar') {
                const destino = ctx.frentes.get(Number(p.id_frente_destino));
                (p.ids || []).forEach((id) => cambios.set(Number(id), Object.assign(cambios.get(Number(id)) || {}, {
                    frenteId: Number(p.id_frente_destino), ubicacion: destino ? destino.nombre : 'Sin Asignar', confirmado: 0,
                })));
            }
        });
        return filas.map((e) => (cambios.has(e.id) ? Object.assign({}, e, cambios.get(e.id)) : e));
    }

    // ── Consulta completa: lo que pinta loadEquipos ───────────────────────────
    function consultar(vista, f, offset, puede, cuantos) {
        const ctx = contexto(vista);
        const todos = encima(vista.filas('equipos'), ctx);
        const aux = vista.filas('auxiliares');
        const hay = hayFiltro(f);
        const enAux = modoAux(f);
        const inicio = offset === 0;
        const r = {
            mode: enAux ? 'aux' : 'equipos', html: '', filas: [], equiposData: {}, auxData: {}, hasMore: false, nextOffset: offset,
            stats: { total: 0, activos: 0, inactivos: 0, mantenimiento: 0 },
            distribution: '', auxDistribution: '', ubicaciones: '', showUbicaciones: false, frenteVenta: null,
            auxConsolidado: null, showAuxConsolidado: false,
        };
        const lote = (lista) => {
            const pagina = lista.slice(offset, offset + (cuantos || POR_LOTE));
            r.nextOffset = offset + pagina.length;
            r.hasMore = r.nextOffset < lista.length;
            return pagina;
        };
        const conAux = (lista) => lista.map((a) => { r.auxData[a.id] = detalleAux(a, puede); return filaAux(a, ctx, puede); });
        const frenteElegido = /^\d+$/.test(txt(f.id_frente)) ? ctx.frentes.get(Number(txt(f.id_frente))) : null;

        if (enAux) {
            const req = auxModo(f);
            const lista = ordenarAux(aux.filter(filtroAux(req, ctx)));
            const pagina = lote(lista);
            r.filas = pagina.length ? conAux(pagina) : [filaMensaje('search_off', 'NO SE ENCONTRARON EQUIPOS AUXILIARES CON LOS FILTROS APLICADOS.')];
            if (inicio) {
                r.stats = statsAux(lista, req, ctx);
                r.distribution = distribucionAux(aux, req, ctx);
            }
        } else if (hay) {
            const lista = ordenarEquipos(todos.filter(filtroEquipos(f, ctx)), ctx);
            const pagina = lote(lista);
            pagina.forEach((e) => { r.equiposData[e.id] = e; });
            let filas = pagina.map((e) => filaEquipo(e, ctx, puede));
            // Los auxiliares que coinciden van al final del último lote.
            if (!r.hasMore && !soloEquipos(f) && ejeCompartido(f)) {
                filas = filas.concat(conAux(ordenarAux(aux.filter(filtroAux(auxCompartido(f), ctx)))));
            }
            r.filas = filas.length ? filas : [filaMensaje('search_off', 'NO SE ENCONTRARON EQUIPOS CON LOS FILTROS APLICADOS.')];
            if (inicio) {
                r.stats = statsEquipos(todos, f, ctx);
                r.distribution = distribucionEquipos(todos, f, ctx);
                if (frenteElegido && frenteElegido.fueraDeFlota) {
                    r.showUbicaciones = true;
                    r.ubicaciones = detalles(todos, f, ctx);
                }
            }
        } else {
            r.filas = [filaMensaje('filter_alt', 'SELECCIONE UN FILTRO PARA VER LOS EQUIPOS.')];
            r.distribution = listaTipos([], 'autorenew', '#3b82f6', 'Equipos y Maquinaria', 'azul', 'equipos_y_maquinaria');
        }

        if (inicio) {
            if (frenteElegido && frenteElegido.venta) {
                r.frenteVenta = Object.assign({}, frenteElegido.venta, { nombre: frenteElegido.nombre });
            }
            const compartido = auxCompartido(f);
            r.auxConsolidado = consolidadoAux(aux, compartido, ctx);
            r.showAuxConsolidado = !enAux && !soloEquipos(f);
            if (r.showAuxConsolidado && hay) r.auxDistribution = distribucionAux(aux, compartido, ctx);
        }
        // Una fila (<tr>) por elemento: el repintado compara fila por fila (loadEquipos).
        r.html = r.filas.join('');
        return r;
    }

    // Lo que trae el Excel: todo lo que lista la tabla (no solo el lote pintado), en su orden.
    function idsExportar(vista, f) {
        const ctx = contexto(vista);
        const aux = vista.filas('auxiliares');
        if (modoAux(f)) {
            return { equipos: [], auxiliares: ordenarAux(aux.filter(filtroAux(auxModo(f), ctx))).map((a) => a.id), modoAux: true };
        }
        const equipos = ordenarEquipos(encima(vista.filas('equipos'), ctx).filter(filtroEquipos(f, ctx)), ctx).map((e) => e.id);
        const auxiliares = !soloEquipos(f) && ejeCompartido(f)
            ? ordenarAux(aux.filter(filtroAux(auxCompartido(f), ctx))).map((a) => a.id) : [];
        return { equipos, auxiliares, modoAux: false };
    }

    // Nombres de la ventana «Titular» / «Aseguradora»: los de los equipos que deja la tabla con los
    // DEMÁS filtros. Su propio eje cuenta como puesto (un filtro concreto incluye los frentes fuera
    // de la flota) pero no recorta, para no quedarse solo con el nombre ya elegido.
    function opcionesDocumento(vista, f, campo) {
        const ctx = contexto(vista);
        const puesto = Object.assign({}, f, { [campo]: '*' });
        const valor = campo === 'titular' ? 'titularReal' : 'aseguradoraReal';
        const vistos = new Map();
        encima(vista.filas('equipos'), ctx).filter(filtroEquipos(puesto, ctx, [campo])).forEach((e) => {
            if (lleno(e[valor]) && !vistos.has(norm(e[valor]))) vistos.set(norm(e[valor]), e[valor]);
        });
        return Array.from(vistos.values()).sort((a, b) => orden.compare(a, b));
    }

    // Sin red, o con red pero sin llegar al servidor la última vez: lo que se guarde va a la bandeja.
    const sinConexion = () => window.Bandeja.sinConexion();

    window.EquiposLocal = { consultar, idsExportar, opcionesDocumento, hayFiltro, sinConexion };

    // ── Sin conexión: cambio de estado y movilización ─────────────────────────
    // Van a la bandeja con los mismos endpoints de siempre; la tabla los muestra al instante
    // (encima()) y suben al volver la red. Los llaman changeStatusLite y openBulkModal
    // (equipos_index.js) si sinConexion().
    function repintar() {
        if (document.getElementById('equiposTableBody') && window.loadEquipos) window.loadEquipos(null, true);
    }

    window.eqOffSetEstado = function (id, status, label) {
        window.Bandeja.enviar('/admin/equipos/' + Number(id) + '/status', { method: 'PATCH', body: { status: status } }, {
            titulo: 'Estado · ' + (label || ('Equipo #' + id)) + ' → ' + status,
            tipo: 'estado', datos: { id_equipo: Number(id), status: status },
        });
        window.toast('Cambio guardado. Se subirá al volver internet.', 'success');
    };

    window.abrirModalMovilizarOffline = function (seleccion) {
        if (!seleccion || !seleccion.length) { window.toast('Selecciona equipos primero.', 'error'); return; }
        document.querySelectorAll('.eq-off-mov-modal').forEach((m) => m.remove());
        window.CopiaLocal.usar('equipos').then((vista) => {
            const frentes = vista.catalogo('frentes').filter((fr) => fr.estatus === 'ACTIVO');
            let elegido = null;
            const overlay = document.createElement('div');
            overlay.className = 'eq-off-mov-modal';
            overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:10001;display:flex;justify-content:center;align-items:center;backdrop-filter:blur(3px);';
            overlay.innerHTML = '<div style="background:white;border-radius:16px;width:90%;max-width:440px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 25px 50px -12px rgba(0,0,0,0.3);">'
                + '<div style="background:#1e293b;padding:16px;color:white;display:flex;align-items:center;justify-content:center;gap:8px;position:relative;">'
                + '<i class="material-icons" style="color:#f59e0b;font-size:20px;">cloud_off</i><h2 style="margin:0;font-size:15px;font-weight:700;">Movilizar sin conexión</h2>'
                + '<button type="button" data-cerrar style="position:absolute;right:12px;background:transparent;border:none;color:white;cursor:pointer;opacity:.8;"><i class="material-icons">close</i></button></div>'
                + '<div style="padding:18px 20px;display:flex;flex-direction:column;gap:14px;overflow-y:auto;">'
                + '<div style="font-size:13px;color:#475569;"><strong>' + seleccion.length + '</strong> equipo(s) a un frente <strong>existente</strong>. Se registra al volver internet.</div>'
                + '<div><label style="display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:6px;">Frente de destino *</label>'
                + '<input type="text" data-buscar placeholder="Buscar frente..." autocomplete="off" style="width:100%;box-sizing:border-box;border:2px solid #e2e8f0;border-radius:10px;padding:10px 12px;font-size:14px;outline:none;">'
                + '<div data-lista style="margin-top:6px;max-height:200px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:10px;"></div></div>'
                + '<div data-elegido style="font-size:12.5px;color:#0067b1;font-weight:700;min-height:16px;"></div></div>'
                + '<div style="padding:14px 20px;display:flex;gap:10px;justify-content:flex-end;border-top:1px solid #f1f5f9;">'
                + '<button type="button" data-cerrar class="btn-primary-maquinaria btn-secondary">Cancelar</button>'
                + '<button type="button" data-ok class="btn-primary-maquinaria" disabled style="opacity:.5;">Movilizar</button></div></div>';
            document.body.appendChild(overlay);

            const lista = overlay.querySelector('[data-lista]');
            const ok = overlay.querySelector('[data-ok]');
            const pintarLista = (q) => {
                const quedan = frentes.filter((fr) => !q || como(fr.nombre, q));
                lista.innerHTML = quedan.length
                    ? quedan.map((fr) => '<div data-id="' + fr.id + '" style="padding:10px 12px;cursor:pointer;font-size:13px;border-bottom:1px solid #f1f5f9;">' + h(fr.nombre) + '</div>').join('')
                    : '<div style="padding:12px;text-align:center;color:#94a3b8;font-size:12px;">Sin frentes en la copia local.</div>';
            };
            pintarLista('');
            overlay.querySelector('[data-buscar]').addEventListener('input', (ev) => pintarLista(txt(ev.target.value)));
            lista.addEventListener('click', (ev) => {
                const op = ev.target.closest('[data-id]');
                if (!op) return;
                elegido = frentes.find((fr) => fr.id === Number(op.dataset.id));
                overlay.querySelector('[data-elegido]').textContent = 'Destino: ' + elegido.nombre;
                ok.disabled = false; ok.style.opacity = '1';
            });
            // Un clic en el fondo NO lo cierra: solo la X o Cancelar, igual que en línea.
            overlay.querySelectorAll('[data-cerrar]').forEach((b) => { b.onclick = () => overlay.remove(); });
            ok.onclick = () => {
                if (!elegido) return;
                const ids = seleccion.map((s) => Number(s.id));
                window.Bandeja.enviar('/admin/equipos/bulk-mobilize', {
                    method: 'POST', body: { ids: ids, destination: elegido.nombre, generar_pdf: false },
                }, {
                    titulo: 'Movilizar ' + ids.length + ' equipo(s) → ' + elegido.nombre,
                    tipo: 'movilizar', datos: { ids: ids, id_frente_destino: elegido.id },
                });
                window.selectedEquipos = {};
                const barra = document.getElementById('bulkFloatingBar');
                if (barra) barra.classList.remove('active');
                window.toast('Movilización guardada. Se registra al volver internet.', 'success');
                overlay.remove();
            };
        });
    };

    // Guardar, subir o descartar algo cambia lo que va encima de la copia: se repinta.
    window.addEventListener('bandeja', repintar);
})();
