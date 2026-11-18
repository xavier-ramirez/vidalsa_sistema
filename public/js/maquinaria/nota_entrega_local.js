/**
 * Nota de Entrega armada EN EL NAVEGADOR, para cuando no hay internet.
 *
 * Con red la Nota la hace el servidor (AlmacenController::renderNotaEntregaPdfBinary, TCPDF +
 * las vistas nota_entrega_pdf / nota_entrega_horizontal_pdf). Sin red no hay servidor que la
 * haga, y la salida sigue: este archivo dibuja LA MISMA hoja con pdf-lib, para la vista previa
 * y para descargarla al registrar. Lleva el número apartado para el equipo (NotasReservadas),
 * que es el definitivo; cuando la salida sube, la bandeja baja además la del servidor.
 *
 * Si se toca el diseño de la Nota en el servidor hay que tocarlo aquí también: mismas
 * medidas (mm), anchos de columna (%), letras y textos, y comparar las dos hojas con los
 * mismos datos (vista previa con red = la del servidor; sin red = esta).
 *
 *   NotaEntregaLocal.prepararSinRed() → con red, deja en la caché del SW la librería, el logo y,
 *                                       en el teléfono, el visor de la vista previa (pdf.js).
 *   NotaEntregaLocal.generar(datos)   → Promise<Blob> (application/pdf).
 *   NotaEntregaLocal.construir(PDFLib, datos, logoBytes) → Promise<Uint8Array> (sin DOM; Node).
 *
 * datos = { formato: 'VERTICAL'|'HORIZONTAL', numero, proyecto, contrato, fecha (d/m/Y), hora,
 *           rq, solicitante, departamento, almacen, entregado_por, cargo_entrega, motivo,
 *           transporte_vehiculo, transporte_placa, transporte_chofer, transporte_cedula,
 *           firmantes: [{ rol, nombre, cargo, cedula }],
 *           lineas: [{ clave, cantidad, um, nombre, codigo, numero_parte, destino }] }
 */
(function (raiz) {
    'use strict';

    // Con ?v=: el SW solo pasa de una versión de caché a la siguiente (cada despliegue) los
    // archivos de /js e /img que lo llevan (sePuedeConservar). Sin él, tras cada despliegue la
    // librería y el logo desaparecían de la caché y la Nota no se podía armar sin internet.
    // Si se cambia el logo, subir su v.
    const PDFLIB = '/js/vendor/pdf-lib-1.17.1.min.js?v=1.17.1';
    const LOGO = '/img/imagen_uno.jpg?v=1';
    // El visor de la vista previa en el teléfono (dom_helpers.js · cargarPdfJs).
    const PDFJS = ['/js/vendor/pdf-3.11.174.min.js?v=3.11.174', '/js/vendor/pdf.worker-3.11.174.min.js?v=3.11.174'];
    const MM = 72 / 25.4;
    // Medido contra el PDF del servidor: TCPDF toma los alto/padding del HTML como pt, y el
    // primer renglón de cada celda mide con la letra del documento (9,5) si la suya es menor.
    const PX = 1;
    const LETRA_DOC = 9.5;
    const FILAS = { VERTICAL: 20, HORIZONTAL: 12 };
    const EMPRESA = 'CONSTRUCTORA VIDALSA 27, C.A.';

    // ── Números: 3 decimales como máximo, coma decimal y punto de miles (como $fmt del blade).
    function fmt(n) {
        const v = Number(n) || 0;
        const partes = Math.abs(v).toFixed(3).split('.');
        const miles = partes[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        const dec = partes[1].replace(/0+$/, '');
        const txt = (v < 0 ? '-' : '') + miles + (dec ? ',' + dec : '');
        return txt === '-0' ? '0' : txt;
    }

    // Las líneas de un mismo producto, nº de parte y destino van en UN renglón con la suma
    // (salen de varios proyectos = varias líneas del lote; el papel lista productos).
    function agrupar(lineas) {
        const orden = [], por = {};
        (lineas || []).forEach((l) => {
            const k = [l.clave, l.numero_parte || '', l.destino || ''].join('|');
            if (!por[k]) { por[k] = Object.assign({}, l, { cantidad: 0 }); orden.push(k); }
            por[k].cantidad += Number(l.cantidad) || 0;
        });
        return orden.map((k) => por[k]);
    }

    async function construir(PDFLib, datos, logoBytes) {
        let LINEA = 1.25;                     // cell height ratio de TCPDF (0,9 en compacto)
        const { PDFDocument, StandardFonts, rgb } = PDFLib;
        const horizontal = String(datos.formato || '').toUpperCase() === 'HORIZONTAL';
        const doc = await PDFDocument.create();
        doc.setTitle('Nota de Entrega de Materiales');
        doc.setAuthor('Constructora Vidalsa 27, C.A.');
        doc.setCreator('Sistema de Gestión VIDALSA');
        const F = { r: await doc.embedFont(StandardFonts.Helvetica), b: await doc.embedFont(StandardFonts.HelveticaBold) };
        let logo = null;
        try { if (logoBytes) logo = await doc.embedJpg(logoBytes); } catch (e) { logo = null; }

        const NEGRO = rgb(0, 0, 0), ROJO = rgb(0xdc / 255, 0x26 / 255, 0x26 / 255), GRIS = rgb(0xd9 / 255, 0xd9 / 255, 0xd9 / 255);
        const pagW = (horizontal ? 297 : 210) * MM, pagH = (horizontal ? 210 : 297) * MM;
        const X0 = 10 * MM, ANCHO = pagW - 20 * MM;
        // 2 mm de holgura: TCPDF parte la hoja un poco antes del margen de 16 mm (medido).
        const TOP = 40 * MM, FONDO = pagH - 18 * MM;
        const GROSOR = 0.15 * MM;

        // Lo que Helvetica (WinAnsi) no puede escribir se cambia por algo parecido.
        const soporta = {};
        function limpio(t) {
            let s = String(t == null ? '' : t).replace(/[\r\t]/g, ' ').replace(/[‘’]/g, "'").replace(/[“”]/g, '"');
            let out = '';
            for (const ch of s) {
                if (ch === '\n') { out += ch; continue; }
                if (soporta[ch] === undefined) {
                    try { F.r.encodeText(ch); soporta[ch] = true; } catch (e) { soporta[ch] = false; }
                }
                out += soporta[ch] ? ch : (ch.normalize('NFD').replace(/[̀-ͯ]/g, '') || '?').replace(/[^\x20-\x7e]/g, '?');
            }
            return out;
        }

        // ── Celdas: { w (%), runs:[{t,b,size,color}], align, bg, minH (pt), pad (pt) } ──
        const run = (t, size, b, color) => ({ t: limpio(t), size, b: !!b, color: color || NEGRO });
        const cel = (w, runs, o) => Object.assign({ w, runs: [].concat(runs || []), align: 'L', pad: 2 * PX }, o || {});

        // Parte los runs en líneas que caben en `ancho` (palabra a palabra; una palabra más
        // larga que la celda se corta por letras).
        function lineasDe(runs, ancho) {
            const lineas = [[]];
            let usado = 0;
            const nueva = () => { lineas.push([]); usado = 0; };
            const ancho1 = (r, t) => (r.b ? F.b : F.r).widthOfTextAtSize(t, r.size);
            runs.forEach((r) => {
                r.t.split('\n').forEach((parrafo, ip) => {
                    if (ip > 0) nueva();
                    parrafo.split(/(\s+)/).forEach((pal) => {
                        if (!pal) return;
                        const esp = /^\s+$/.test(pal);
                        if (esp && usado === 0) return;
                        let w = ancho1(r, pal);
                        if (!esp && usado > 0 && usado + w > ancho) nueva();
                        if (!esp && w > ancho) {
                            let trozo = '';
                            for (const ch of pal) {
                                if (usado + ancho1(r, trozo + ch) > ancho && trozo) {
                                    lineas[lineas.length - 1].push({ r, t: trozo }); nueva(); trozo = '';
                                }
                                trozo += ch;
                            }
                            pal = trozo; w = ancho1(r, pal);
                        }
                        lineas[lineas.length - 1].push({ r, t: pal });
                        usado += w;
                    });
                });
            });
            // Sin espacios colgando al final de cada línea.
            lineas.forEach((l) => { while (l.length && /^\s+$/.test(l[l.length - 1].t)) l.pop(); });
            return lineas;
        }
        const tamano = (c) => c.runs.reduce((m, r) => Math.max(m, r.size), c.tamano || 8);
        function medir(c, anchoPt) {
            c._lineas = lineasDe(c.runs, Math.max(1, anchoPt - 2 * c.pad));
            const n = Math.max(1, c._lineas.length);
            c._alto = (Math.max(LETRA_DOC, tamano(c)) + (n - 1) * tamano(c)) * LINEA + 2 * c.pad;
            return c._alto;
        }
        function altoFila(fila) {
            let h = fila.minH || 0;
            fila.celdas.forEach((c) => { h = Math.max(h, medir(c, ANCHO * c.w / 100)); });
            return h;
        }

        function pintarCelda(page, c, x, y, w, h) {
            if (c.bg) page.drawRectangle({ x, y: pagH - y - h, width: w, height: h, color: c.bg });
            page.drawRectangle({ x, y: pagH - y - h, width: w, height: h, borderColor: NEGRO, borderWidth: GROSOR });
            const t = tamano(c), lh = t * LINEA, primera = Math.max(LETRA_DOC, t) * LINEA;
            const totalTxt = c.valign === 'M' ? c._lineas.length * lh : primera + (c._lineas.length - 1) * lh;
            let yy = c.valign === 'M' ? y + (h - totalTxt) / 2 : y + c.pad + (primera - lh);
            c._lineas.forEach((l) => {
                const anchoL = l.reduce((s, p) => s + (p.r.b ? F.b : F.r).widthOfTextAtSize(p.t, p.r.size), 0);
                let xx = c.align === 'C' ? x + (w - anchoL) / 2 : x + c.pad;
                const base = yy + (lh - t) / 2 + t * 0.78;
                // Los trozos seguidos con la misma letra van en UN texto, espacios incluidos: si
                // cada palabra se pone suelta, un visor que usa otra Helvetica las junta.
                const tramos = [];
                l.forEach((p) => {
                    const u = tramos[tramos.length - 1];
                    if (u && u.r === p.r) u.t += p.t; else tramos.push({ r: p.r, t: p.t });
                });
                tramos.forEach((p) => {
                    const f = p.r.b ? F.b : F.r;
                    if (p.t.trim()) page.drawText(p.t, { x: xx, y: pagH - base, size: p.r.size, font: f, color: p.r.color });
                    xx += f.widthOfTextAtSize(p.t, p.r.size);
                });
                yy += lh;
            });
        }

        // ── Cabezote (NotaEntregaPDF::Header) ──
        function cabezote(page, p, total) {
            const cx = X0, cy = 16 * MM, cw = ANCHO, ch = 24 * MM;
            const wLogo = cw * 0.20, wTit = cw * 0.52, wSel = cw * 0.28;
            const filas = horizontal
                ? [[run('N° de Nota: ', 9, true), run(datos.numero, 9, true, ROJO)],
                   [run('FECHA: ' + (datos.fecha || '') + ' HORA: ' + (datos.hora || ''), 10, true)]]
                : [[run('N° de Nota: ', 8, true), run(datos.numero, 8, true, ROJO)],
                   [run('FECHA EMIS: 01/10/19', 7)],
                   [run('REV: 2. FECHA REV: 06/09/26', 7)]];
            filas.push([run('PAG. ' + p + ' DE ' + total, horizontal ? 8 : 7)]);
            const hf = ch / filas.length;
            const borde = { borderColor: NEGRO, borderWidth: GROSOR };
            page.drawRectangle(Object.assign({ x: cx, y: pagH - cy - ch, width: wLogo, height: ch }, borde));
            page.drawRectangle(Object.assign({ x: cx + wLogo, y: pagH - cy - ch, width: wTit, height: ch }, borde));
            if (logo) {
                const bw = wLogo - 2 * MM, bh = ch - 2 * MM;
                const k = Math.min(bw / logo.width, bh / logo.height);
                const iw = logo.width * k, ih = logo.height * k;
                page.drawImage(logo, { x: cx + MM + (bw - iw) / 2, y: pagH - (cy + MM + (bh - ih) / 2) - ih, width: iw, height: ih });
            }
            const tit = 'NOTA DE ENTREGA DE MATERIALES', tt = 13;
            page.drawText(tit, { x: cx + wLogo + (wTit - F.b.widthOfTextAtSize(tit, tt)) / 2, y: pagH - (cy + ch / 2) - tt * 0.36, size: tt, font: F.b });
            filas.forEach((runs, i) => {
                const c = cel(100, runs, { align: 'C', valign: 'M' });
                medir(c, wSel);
                pintarCelda(page, c, cx + wLogo + wTit, cy + i * hf, wSel, hf);
            });
        }

        // ── Cuerpo: tablas de filas; la de ítems repite su encabezado en cada hoja ──
        function cuerpo(filasRelleno, compacto) {
            LINEA = compacto ? 0.9 : 1.25;
            const fi = compacto ? 7 : 8, pad = (compacto ? 1 : 2) * PX;
            const items = agrupar(datos.lineas);
            const tablas = [];
            const fila = (celdas, minH) => ({ celdas, minH: minH || 0 });
            const b = (t, s) => run(t, s, true);
            const r = (t, s) => run(t, s);
            const desc = (l) => (l.nombre || '') + (l.numero_parte ? '  —  ' + l.numero_parte : '');
            const vacia = (w) => cel(w, [r('', fi)], { pad, tamano: fi });

            if (!horizontal) {
                tablas.push({ filas: [
                    fila([cel(20, b('PROYECTO:', 9)), cel(80, b(datos.proyecto, 9))]),
                    fila([cel(20, b('CONTRATO Nº:', 9)), cel(80, r(datos.contrato, 9))]),
                    fila([cel(20, b('FECHA DE ENTREGA:', 9)), cel(20, r(datos.fecha, 9)), cel(10, b('RQ N°:', 9)),
                          cel(15, r(datos.rq, 9)), cel(13, b('SOLICITANTE:', 9)), cel(22, r(datos.solicitante, 9))]),
                    fila([cel(20, b('DEPARTAMENTO:', 9)), cel(80, r(datos.departamento, 9))]),
                ] });
                const W = [6, 10, 10, 62, 12];
                const cab = fila([cel(6, b('ITEM', 8), { align: 'C', bg: GRIS, pad }), cel(10, b('CANTIDAD', 8), { align: 'C', bg: GRIS, pad }),
                    cel(10, b('UNIDAD', 8), { align: 'C', bg: GRIS, pad }), cel(62, b('DESCRIPCIÓN', 8), { align: 'C', bg: GRIS, pad }),
                    cel(12, b('N° COLADA /\nSERIAL', 8), { align: 'C', bg: GRIS, pad })]);
                const filas = items.map((l, i) => fila([cel(6, r(String(i + 1), fi), { align: 'C', pad }), cel(10, r(fmt(l.cantidad), fi), { align: 'C', pad }),
                    cel(10, r(l.um, fi), { align: 'C', pad }), cel(62, r(desc(l), fi), { pad }), cel(12, r(l.codigo, fi), { align: 'C', pad })]));
                for (let j = items.length; j < filasRelleno; j++) {
                    filas.push(fila([cel(6, r(String(j + 1), fi), { align: 'C', pad }), vacia(W[1]), vacia(W[2]), vacia(W[3]), vacia(W[4])]));
                }
                tablas.push({ cab, filas, items: true });
                tablas.push({ filas: [
                    fila([cel(100, b('OBSERVACIONES:', 9))]),
                    fila([cel(100, r(datos.motivo || ' ', 9))], 42 * PX),
                ] });
                tablas.push({ filas: [
                    fila([cel(14, r('', 9)), cel(43, b('ENTREGADO POR:', 9), { align: 'C' }), cel(43, b('RECIBIDO POR:', 9), { align: 'C' })]),
                    fila([cel(14, b('NOMBRE:', 9)), cel(43, r(datos.entregado_por, 9), { align: 'C' }), cel(43, r('', 9))]),
                    fila([cel(14, b('CARGO:', 9)), cel(43, r(datos.cargo_entrega, 9), { align: 'C' }), cel(43, r('', 9))]),
                    fila([cel(14, b('FIRMA:', 9)), cel(43, r('', 9)), cel(43, r('', 9))], 24 * PX),
                    fila([cel(14, b('FECHA:', 9)), cel(43, r(String(datos.fecha || '').toUpperCase(), 9), { align: 'C' }), cel(43, r('', 9))]),
                ] });
                tablas.push(transporte(14, 36, true));
            } else {
                tablas.push({ filas: [
                    fila([cel(11, b('ALMACEN:', 8)), cel(39, b(datos.almacen, 8)), cel(11, b('PROYECTO:', 8)), cel(39, b(datos.proyecto, 8))]),
                    fila([cel(11, b('SOLICITANTE:', 8)), cel(39, r(datos.solicitante, 8)), cel(11, b('DEPARTAMENTO:', 8)), cel(39, r(datos.departamento, 8))]),
                ] });
                const W = [4, 40, 13, 8, 6, 29];
                const g = (w, t) => cel(w, b(t, 8), { align: 'C', bg: GRIS, pad });
                const cab = fila([g(4, 'N°'), g(40, 'DESCRIPCION'), g(13, 'CODIGO'), g(8, 'CANTIDAD'), g(6, 'UND'), g(29, 'DESTINO')]);
                const filas = items.map((l, i) => fila([cel(4, r(String(i + 1), fi), { align: 'C', pad }), cel(40, r(desc(l), fi), { pad }),
                    cel(13, r(l.codigo, fi), { align: 'C', pad }), cel(8, r(fmt(l.cantidad), fi), { align: 'C', pad }),
                    cel(6, r(l.um, fi), { align: 'C', pad }), cel(29, r(l.destino || '', fi), { pad })]));
                for (let j = items.length; j < filasRelleno; j++) {
                    filas.push(fila([cel(4, r(String(j + 1), fi), { align: 'C', pad })].concat(W.slice(1).map(vacia))));
                }
                tablas.push({ cab, filas, items: true });
                tablas.push({ filas: [fila([cel(100, [b('OBSERVACIONES:', 8), r(' ' + (datos.motivo || ''), 8)])], 34 * PX)] });
                tablas.push(transporte(12, 38, false));
                const fs = datos.firmantes || [];
                const wf = fs.length ? 100 / fs.length : 100;
                const dato = (et, v) => [b(et, 7), r(' ' + (v || ''), 7)];
                tablas.push({ filas: [
                    fila(fs.map((f) => cel(wf, b(f.rol, 8), { align: 'C', bg: GRIS }))),
                    fila(fs.map((f) => cel(wf, dato('NOMBRE:', f.nombre)))),
                    fila(fs.map((f) => cel(wf, dato('CARGO:', f.cargo)))),
                    fila(fs.map((f) => cel(wf, dato('CEDULA:', f.cedula)))),
                    fila(fs.map(() => cel(wf, b('FIRMA:', 7))), 24 * PX),
                ] });
            }
            return tablas;

            function transporte(wL, wV, centrar) {
                const v = (campo) => cel(wV, r(String(datos[campo] || '').trim(), 8), { align: centrar ? 'C' : 'L' });
                return { filas: [
                    fila([cel(50, b('DATOS DEL VEHICULO', 8), { align: 'C', bg: GRIS }), cel(50, b('DATOS DEL CHOFER', 8), { align: 'C', bg: GRIS })]),
                    fila([cel(wL, b('VEHICULO:', 8)), v('transporte_vehiculo'), cel(wL, b('NOMBRE:', 8)), v('transporte_chofer')]),
                    fila([cel(wL, b('PLACA:', 8)), v('transporte_placa'), cel(wL, b('CEDULA:', 8)), v('transporte_cedula')]),
                    fila([cel(wL, b('EMPRESA:', 8)), cel(wV, r(EMPRESA, 8), { align: centrar ? 'C' : 'L' }), cel(wL, b('FIRMA:', 8)), cel(wV, r('', 8))]),
                ] };
            }
        }

        // Reparte las filas en hojas: [{ y, fila }] por hoja. La de ítems repite su encabezado.
        function maquetar(tablas) {
            const hojas = [[]];
            let y = TOP;
            const poner = (f) => {
                const h = altoFila(f);
                if (y + h > FONDO && y > TOP) { hojas.push([]); y = TOP; return false; }
                hojas[hojas.length - 1].push({ y, h, fila: f }); y += h; return true;
            };
            tablas.forEach((t) => {
                if (t.cab) poner(t.cab) || poner(t.cab);
                t.filas.forEach((f) => {
                    if (!poner(f)) {
                        if (t.cab) poner(t.cab);
                        poner(f);
                    }
                });
            });
            return hojas;
        }

        // Una hoja si cabe (como el servidor): quitar relleno; si ni así, ítems compactos.
        const oficiales = FILAS[horizontal ? 'HORIZONTAL' : 'VERTICAL'];
        const nItems = agrupar(datos.lineas).length;
        // Cada intento recuerda si fue compacto: el interlineado se pinta con el mismo.
        const intento = (n, comp) => Object.assign(maquetar(cuerpo(n, comp)), { compacto: comp });
        let hojas = intento(oficiales, false);
        if (hojas.length > 1 && nItems < oficiales) {
            const sin = intento(nItems, false);
            if (sin.length === 1) {
                let cabe = nItems, noCabe = oficiales; hojas = sin;
                while (noCabe - cabe > 1) {
                    const medio = Math.floor((cabe + noCabe) / 2);
                    const prueba = intento(medio, false);
                    if (prueba.length === 1) { cabe = medio; hojas = prueba; } else { noCabe = medio; }
                }
            } else {
                hojas = sin;
            }
        }
        if (hojas.length > 1) {
            const comp = intento(nItems, true);
            if (comp.length <= hojas.length) hojas = comp;
        }

        LINEA = hojas.compacto ? 0.9 : 1.25;
        hojas.forEach((filas, i) => {
            const page = doc.addPage([pagW, pagH]);
            cabezote(page, i + 1, hojas.length);
            filas.forEach(({ y, h, fila }) => {
                let x = X0;
                fila.celdas.forEach((c) => {
                    const w = ANCHO * c.w / 100;
                    medir(c, w);
                    pintarCelda(page, c, x, y, w, h);
                    x += w;
                });
            });
        });
        return doc.save();
    }

    // ── Navegador ──
    function cargarLibreria() {
        if (raiz.PDFLib) return Promise.resolve(raiz.PDFLib);
        return raiz.cargarScriptUnaVez(PDFLIB, () => !!raiz.PDFLib).then(() => raiz.PDFLib);
    }
    function bajarLogo() {
        return fetch(LOGO, { credentials: 'same-origin' })
            .then((r) => (r.ok ? r.arrayBuffer() : null)).catch(() => null);
    }

    const api = {
        construir,
        fmt,
        // Con red: la librería, el logo y (teléfono) el visor quedan en la caché del service
        // worker (/js y /img son cache-first), así la Nota se arma y se ve sin internet.
        // Solo lo que falta: el SW revalida por detrás cada archivo que sirve de la caché, y
        // pedirlos siempre volvía a bajar la librería (~500 KB) en cada sesión.
        prepararSinRed() {
            if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
            const urls = [PDFLIB, LOGO].concat(raiz.pdfEsMovil && raiz.pdfEsMovil() ? PDFJS : []);
            const hay = (u) => (typeof caches !== 'undefined' ? caches.match(u).catch(() => null) : Promise.resolve(null));
            urls.forEach((u) => {
                hay(u).then((ya) => ya || fetch(u, { credentials: 'same-origin' })).catch(() => {});
            });
        },
        generar(datos) {
            return Promise.all([cargarLibreria(), bajarLogo()])
                .then(([lib, logo]) => construir(lib, datos, logo))
                .then((bytes) => new Blob([bytes], { type: 'application/pdf' }));
        },
    };

    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    else raiz.NotaEntregaLocal = api;
})(typeof window !== 'undefined' ? window : globalThis);
