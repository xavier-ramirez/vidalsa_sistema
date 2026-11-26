/**
 * Gráficos de consumibles → «Exportar»: pregunta Excel o PowerPoint.
 * - Excel: la descarga de siempre (descargarExcel() de graficos.blade.php).
 * - PowerPoint: arma en el navegador la presentación de gasoil con los datos del sistema
 *   (ConsumiblesController::presentacionData) y la descarga, con las láminas de la presentación de
 *   Combustible: portada; resumen de suministro de cada patio (las cisternas que llegaron en el mes,
 *   con su foto: cualquier cantidad, repartidas en las láminas que haga falta); consumo por proyecto;
 *   un proyecto (se elige aquí: 6 meses y sus 4 equipos de mayor consumo); consumo del cupo de los
 *   dos patios; e histórico de despacho y cronograma de solicitudes de cada patio. Las cisternas,
 *   cupos y solicitudes salen del modal «Cupos de Gasoil» (CuposCombustibleController::presentacion).
 * Las barras van como figuras (para que se vean como en la presentación original); el gráfico de meses
 * del proyecto, como gráfico de PowerPoint. PptxGenJS se carga solo al usarlo.
 */
(function () {
    'use strict';

    var AZUL = '1F4E79', NARANJA = 'F26A21', ROJO = 'A8261F', GRIS = 'F2F4F7', GRIS_BARRA = 'DCE2EA', TINTA = '2B3440';
    var LIB = '/js/vendor/pptxgen-3.12.0.bundle.min.js';
    var LOGO = '/images/maquinaria/logo.png';
    var PORTADA = '/images/maquinaria/gasoil_portada.jpg';

    var mil = function (n) { return Math.round(n || 0).toLocaleString('es-VE'); };
    var esc = window.escapeHtml || function (s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); };

    function cargarLib() {
        if (window.PptxGenJS) return Promise.resolve();
        return window.cargarScriptUnaVez(LIB, function () { return !!window.PptxGenJS; })
            .catch(function () { throw new Error('No se pudo cargar el generador de PowerPoint.'); });
    }

    function logoDataUrl() {
        return fetch(LOGO).then(function (r) { return r.blob(); }).then(function (b) {
            return new Promise(function (ok) { var fr = new FileReader(); fr.onload = function () { ok(fr.result); }; fr.readAsDataURL(b); });
        }).catch(function () { return null; });
    }

    var leerFiltros = function () { return new URLSearchParams(); };   // lo pone consumiblesExportar (getParams de la pantalla)

    function parametros(extra) {
        var p = leerFiltros();
        p.delete('tipo');                       // la presentación es siempre de gasoil
        Object.keys(extra || {}).forEach(function (k) { if (extra[k]) p.set(k, extra[k]); });
        return p;
    }

    function pedirDatos(url, extra) {
        var f = window.apiFetch || fetch;
        return f(url + '?' + parametros(extra).toString(), { headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error('El servidor no devolvió los datos (' + r.status + ').'); return r.json(); });
    }

    // ── Láminas (3 y 4: proyectos) ───────────────────────────────────────────
    // 13,33 × 7,5 pulgadas (16:9). Encabezado azul con el logo a la izquierda, como la presentación.
    function encabezado(pptx, lam, titulo, logo) {
        lam.background = { color: 'FFFFFF' };
        lam.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 13.333, h: 1.3, fill: { color: AZUL }, line: { color: AZUL } });
        lam.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 3.3, h: 1.3, fill: { color: 'FFFFFF' }, line: { color: 'FFFFFF' } });
        if (logo) lam.addImage({ data: logo, x: 0.25, y: 0.4, w: 2.8, h: 0.5 });
        // Una o dos líneas (\n); los nombres de proyecto largos bajan la letra.
        var largo = Math.max.apply(null, titulo.split('\n').map(function (l) { return l.length; }));
        var tam = largo <= 32 ? 26 : largo <= 42 ? 21 : 17;
        lam.addText(titulo, { x: 3.5, y: 0.2, w: 9.5, h: 0.9, align: 'right', valign: 'middle', fontFace: 'Arial', fontSize: tam, bold: true,
            color: 'FFFFFF' });
    }

    // Lámina 3, como la del original (26-11): tarjeta blanca con sombra y el título dentro; de mayor a
    // menor, nombre a la izquierda y barra redondeada con los litros en negrita al final. Los 4 de
    // mayor consumo en rojos (de vino a rojo vivo); los demás en azul que se aclara. Leyenda abajo a la
    // derecha (donde las barras son cortas) y, a la derecha, el consumo total.
    var ROJOS = ['8E1B1B', 'A72A26', 'C03930', 'D9483B'];
    function azulDe(k, n) {
        var a = [0x17, 0x3F, 0x66], b = [0x9F, 0xBC, 0xDB], t = n > 1 ? k / (n - 1) : 0;
        return a.map(function (v, i) { return ('0' + Math.round(v + (b[i] - v) * t).toString(16)).slice(-2); }).join('').toUpperCase();
    }
    // Nueva en cada figura: PptxGenJS cambia el objeto de la sombra al usarlo (si se repite, crece sin fin).
    function sombra() { return { type: 'outer', color: '142846', opacity: 0.18, blur: 4, offset: 1.5, angle: 90 }; }
    function laminaProyectos(pptx, d, logo) {
        var lam = pptx.addSlide();
        encabezado(pptx, lam, 'CONSUMO DE GASOIL POR PROYECTO', logo);
        lam.addShape(pptx.ShapeType.roundRect, { x: 0.42, y: 1.77, w: 8.29, h: 5.35, fill: { color: 'FFFFFF' }, line: { color: 'DDE3EA', width: 0.75 },
            rectRadius: 0.15, shadow: { type: 'outer', color: '142846', opacity: 0.12, blur: 12, offset: 4, angle: 90 } });
        lam.addText('CONSUMO POR PROYECTO (LITROS)', T({ x: 0.73, y: 1.98, w: 7.67, h: 0.36, align: 'center', valign: 'middle', fontSize: 16,
            bold: true, color: '1F2A37', charSpacing: 1.5 }));
        lam.addShape(pptx.ShapeType.line, { x: 0.73, y: 2.42, w: 7.67, h: 0, line: { color: 'E3E7ED', width: 0.75 } });
        lam.addText('Del ' + d.desde + ' al ' + d.hasta, T({ x: 0.73, y: 2.46, w: 7.67, h: 0.22, align: 'center', fontSize: 8, color: '8A94A3' }));

        // Hasta 15 barras: si hay más proyectos, los últimos se nombran juntos en una nota al pie (no
        // como barra: su suma desordenaría el gráfico).
        var ps = d.proyectos.slice(0, d.proyectos.length > 15 ? 14 : 15), otros = d.proyectos.slice(ps.length);
        if (ps.length) {
            var mayor = Math.max.apply(null, ps.map(function (p) { return p.litros; }).concat([1]));
            var y0 = 2.78, y1 = otros.length ? 6.62 : 6.92, paso = Math.min(0.42, (y1 - y0) / ps.length), alto = Math.min(0.21, paso * 0.55);
            var azules = Math.max(0, ps.length - 4), finMax = 0;
            ps.forEach(function (p, k) {
                var yc = y0 + paso * k + paso / 2, w = Math.max(alto, 5.46 * 0.7 * p.litros / mayor);
                var col = k < 4 ? ROJOS[k] : azulDe(k - 4, azules);
                lam.addText(String(p.nombre).toUpperCase(), T({ x: 0.55, y: yc - 0.15, w: 2.3, h: 0.3, align: 'right', valign: 'middle',
                    fontSize: 9.5, bold: true, color: TINTA, charSpacing: 0.5, fit: 'shrink' }));
                lam.addShape(pptx.ShapeType.roundRect, { x: 3.0, y: yc - alto / 2, w: w, h: alto, fill: { color: col }, line: { color: col, width: 0 },
                    rectRadius: alto / 2, shadow: sombra() });
                lam.addText(mil(p.litros), T({ x: 3.0 + w + 0.1, y: yc - 0.15, w: 1.3, h: 0.3, valign: 'middle', fontSize: 13, bold: true, color: '000000' }));
                // Lo que ocupa cada fila que cae a la altura de la leyenda (barra + cifra).
                if (yc + 0.15 > 6.0) finMax = Math.max(finMax, 3.0 + w + 0.1 + 0.12 * mil(p.litros).length);
            });
            if (otros.length) {
                lam.addText('Otros ' + otros.length + ' proyectos: ' + mil(otros.reduce(function (s, p) { return s + p.litros; }, 0)) + ' L',
                    T({ x: 0.55, y: 6.66, w: 5.0, h: 0.26, valign: 'middle', fontSize: 9, bold: true, color: '4A5563' }));
            }
            // La leyenda abajo a la derecha de la tarjeta (donde las barras son cortas); si alguna llega
            // hasta ahí, va en la columna derecha, debajo del total.
            // Medidas del original (1280×720 → pulgadas): left 556, top 580; cuadritos de 14 px, letra de 13 px.
            var adentro = finMax < 5.75, lx = adentro ? 5.79 : 9.2, ly = adentro ? 6.04 : 5.6;
            lam.addShape(pptx.ShapeType.roundRect, { x: lx, y: ly, w: 2.75, h: 0.66, fill: { color: 'F5F7FA' }, line: { color: 'E3E7ED', width: 0.75 }, rectRadius: 0.1 });
            [['A8261F', 'ROJO: MAYOR CONSUMO'], [AZUL, 'AZUL: RESTO DE PROYECTOS']].forEach(function (l, i) {
                var y = ly + 0.1 + i * 0.25;
                lam.addShape(pptx.ShapeType.roundRect, { x: lx + 0.16, y: y + 0.03, w: 0.15, h: 0.15, fill: { color: l[0] }, line: { color: l[0] }, rectRadius: 0.04 });
                lam.addText(l[1], T({ x: lx + 0.38, y: y, w: 2.32, h: 0.21, valign: 'middle', fontSize: 9.75, bold: true, color: TINTA, charSpacing: 0.5 }));
            });
        } else {
            lam.addText('Sin gasoil en el período.', T({ x: 0.42, y: 4.0, w: 8.29, h: 0.5, align: 'center', fontSize: 14, color: '4A5563' }));
        }

        lam.addShape(pptx.ShapeType.line, { x: 8.94, y: 1.77, w: 0, h: 5.35, line: { color: 'D5DBE3', width: 0.75 } });
        // Columna derecha (left 878, top 170, alto 514): el total centrado en lo alto.
        lam.addShape(pptx.ShapeType.roundRect, { x: 9.2, y: 3.78, w: 3.85, h: 1.35, fill: { color: AZUL }, line: { color: AZUL }, rectRadius: 0.12 });
        lam.addText([
            { text: 'CONSUMO TOTAL', options: { fontSize: 9, bold: true, color: 'FFFFFF', charSpacing: 1.5, breakLine: true } },
            { text: mil(d.total) + ' L', options: { fontSize: 39, bold: true, color: 'FFFFFF' } }
        ], T({ x: 9.45, y: 3.78, w: 3.45, h: 1.35, valign: 'middle', fit: 'shrink' }));
        if (d.sin_frente > 0) {
            lam.addText('Incluye ' + mil(d.sin_frente) + ' L cargados sin proyecto.', T({ x: 9.2, y: 5.2, w: 3.85, h: 0.3, fontSize: 9, color: '4A5563' }));
        }
    }

    function laminaUnProyecto(pptx, d, logo) {
        var p = d.proyecto, lam = pptx.addSlide();
        encabezado(pptx, lam, 'CONSUMO DE GASOIL · ' + p.nombre, logo);
        lam.addText('CONSUMO MENSUAL (LITROS)', { x: 0.4, y: 1.6, w: 8.3, h: 0.45, align: 'center', fontFace: 'Arial', fontSize: 17, bold: true, color: '000000' });
        var n = p.meses.length, etiquetas = p.meses.map(function (m) { return m.mes; });
        // El último mes en naranja, los demás en azul (dos series superpuestas).
        lam.addChart(pptx.ChartType.bar, [
            { name: 'Meses', labels: etiquetas, values: p.meses.map(function (m, k) { return k < n - 1 ? m.litros : 0; }) },
            { name: 'Último mes', labels: etiquetas, values: p.meses.map(function (m, k) { return k === n - 1 ? m.litros : 0; }) }
        ], {
            x: 0.4, y: 2.15, w: 8.3, h: 5.0, barDir: 'col', barGrouping: 'clustered', barOverlapPct: 100, barGapWidthPct: 40, chartColors: [AZUL, NARANJA],
            catAxisLabelFontFace: 'Arial', catAxisLabelFontSize: 11, catAxisLabelColor: TINTA, catAxisLineShow: true,
            valAxisHidden: true, valGridLine: { style: 'none' }, showValue: true, dataLabelFormatCode: '#,##0;;;',
            dataLabelFontFace: 'Arial', dataLabelFontSize: 12, dataLabelFontBold: true, dataLabelColor: '000000', dataLabelPosition: 'outEnd',
            showLegend: false
        });

        lam.addShape(pptx.ShapeType.line, { x: 8.95, y: 1.6, w: 0, h: 5.6, line: { color: 'D5DBE3', width: 0.75 } });
        lam.addText('TOP 4 EQUIPOS DE MAYOR CONSUMO', { x: 9.2, y: 1.6, w: 3.85, h: 0.45, fontFace: 'Arial', fontSize: 13, bold: true, color: '000000' });
        var mayor = p.top.length ? p.top[0].litros : 0;
        if (!p.top.length) {
            lam.addText('Sin equipos confirmados en el período.', { x: 9.2, y: 2.3, w: 3.85, h: 0.5, fontFace: 'Arial', fontSize: 11, color: '4A5563' });
        }
        p.top.forEach(function (e, k) {
            var y = 2.2 + k * 1.22, acento = k === 0 ? NARANJA : AZUL;
            lam.addShape(pptx.ShapeType.roundRect, { x: 9.2, y: y, w: 3.85, h: 1.08, fill: { color: GRIS }, line: { color: GRIS }, rectRadius: 0.1 });
            lam.addShape(pptx.ShapeType.rect, { x: 9.2, y: y, w: 0.06, h: 1.08, fill: { color: acento }, line: { color: acento } });
            lam.addShape(pptx.ShapeType.ellipse, { x: 9.4, y: y + 0.27, w: 0.4, h: 0.4, fill: { color: acento }, line: { color: acento } });
            lam.addText(String(k + 1), { x: 9.4, y: y + 0.27, w: 0.4, h: 0.4, align: 'center', valign: 'middle', fontFace: 'Arial', fontSize: 13, bold: true, color: 'FFFFFF' });
            lam.addText([
                { text: e.tipo, options: { bold: true, fontSize: 11, color: '000000', breakLine: true } },
                { text: e.id, options: { bold: true, fontSize: 10, color: '4D4D4D', breakLine: true } },
                { text: e.despachos + (e.despachos === 1 ? ' despacho' : ' despachos'), options: { fontSize: 9, color: '4D4D4D' } }
            ], { x: 9.95, y: y + 0.08, w: 1.9, h: 0.78, fontFace: 'Arial', valign: 'middle', fit: 'shrink' });
            lam.addText([
                { text: mil(e.litros), options: { bold: true, fontSize: 18, color: '000000', breakLine: true } },
                { text: 'LITROS', options: { bold: true, fontSize: 8, color: '4D4D4D', charSpacing: 1 } }
            ], { x: 11.75, y: y + 0.12, w: 1.2, h: 0.7, align: 'right', valign: 'middle', fontFace: 'Arial' });
            lam.addShape(pptx.ShapeType.rect, { x: 9.4, y: y + 0.88, w: 3.5, h: 0.07, fill: { color: GRIS_BARRA }, line: { color: GRIS_BARRA } });
            var ancho = mayor ? Math.max(0.02, 3.5 * e.litros / mayor) : 0.02;
            lam.addShape(pptx.ShapeType.rect, { x: 9.4, y: y + 0.88, w: ancho, h: 0.07, fill: { color: acento }, line: { color: acento } });
        });
    }

    // ── Imágenes ─────────────────────────────────────────────────────────────
    // Una foto del sistema (las de cisternas están en WebP, que PowerPoint no abre) pasada a JPEG, con
    // su tamaño real (para recortarla sin deformar). Si no se puede leer, null: la lámina pone un recuadro.
    function fotoJpeg(url, ancho) {
        if (!url) return Promise.resolve(null);
        return fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(); return r.blob(); })
            .then(function (b) {
                return new Promise(function (ok, mal) {
                    var u = URL.createObjectURL(b), img = new Image();
                    img.onload = function () {
                        var k = Math.min(1, (ancho || 1400) / img.naturalWidth);
                        var c = document.createElement('canvas');
                        c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
                        var cx = c.getContext('2d');
                        cx.fillStyle = '#FFFFFF';   // JPEG no tiene transparencia: lo transparente, blanco (no negro)
                        cx.fillRect(0, 0, c.width, c.height);
                        cx.drawImage(img, 0, 0, c.width, c.height);
                        URL.revokeObjectURL(u);
                        ok({ data: c.toDataURL('image/jpeg', 0.85), w: c.width, h: c.height });
                    };
                    img.onerror = function () { URL.revokeObjectURL(u); mal(); };
                    img.src = u;
                });
            })
            .catch(function () { return null; });
    }
    // La foto llena el recuadro (recortada al centro, sin deformarse).
    function ponerFoto(lam, f, x, y, w, h) {
        lam.addImage({ data: f.data, x: x, y: y, w: w, h: w * f.h / f.w, sizing: { type: 'cover', w: w, h: h } });
    }
    var T = function (extra) { var o = { fontFace: 'Arial', margin: 0 }; Object.keys(extra).forEach(function (k) { o[k] = extra[k]; }); return o; };

    // ── 1. Portada ───────────────────────────────────────────────────────────
    function laminaPortada(pptx, mes, logo, foto) {
        var lam = pptx.addSlide();
        lam.background = { color: 'FFFFFF' };
        if (logo) lam.addImage({ data: logo, x: 0.5, y: 0.45, w: 2.8, h: 0.5 });
        lam.addShape(pptx.ShapeType.rect, { x: 0, y: 6.55, w: 13.333, h: 0.95, fill: { color: AZUL }, line: { color: AZUL } });
        lam.addShape(pptx.ShapeType.rect, { x: 0, y: 2.45, w: 7.6, h: 2.75, fill: { color: NARANJA }, line: { color: NARANJA } });
        lam.addText('GESTIÓN, CONTROL Y SUMINISTRO\nDE COMBUSTIBLE', T({ x: 0.4, y: 2.85, w: 6.8, h: 1.3, align: 'center', valign: 'middle',
            fontSize: 28, bold: true, color: 'FFFFFF' }));
        lam.addText(mes, T({ x: 0.4, y: 4.25, w: 6.8, h: 0.45, align: 'center', fontSize: 15, bold: true, color: 'FFFFFF', charSpacing: 4 }));
        if (foto) ponerFoto(lam, foto, 7.6, 2.25, 5.733, 3.15);
        else lam.addShape(pptx.ShapeType.rect, { x: 7.6, y: 2.25, w: 5.733, h: 3.15, fill: { color: GRIS }, line: { color: GRIS } });
    }

    // ── 2. Resumen de suministro de un patio: tarjeta del total + fotos de las cisternas ──
    // Seis recuadros por lámina (3 × 2): en la primera, la tarjeta y 5 cisternas; en las siguientes, 6.
    // Con pocas, recuadros más grandes (2 × 1 o 2 × 2).
    function laminasSuministro(pptx, mes, patio, fotos, logo) {
        var cis = patio.recibidas, grupos = [], i = 0;
        grupos.push(cis.slice(0, 5)); i = 5;
        while (i < cis.length) { grupos.push(cis.slice(i, i + 6)); i += 6; }
        var mesSolo = mes.split(' ')[0];
        grupos.forEach(function (grupo, g) {
            var lam = pptx.addSlide();
            encabezado(pptx, lam, 'RESUMEN DE SUMINISTRO DE GASOIL\n' + mesSolo + ' · ' + patio.nombre.toUpperCase() + (g ? ' (CONT.)' : ''), logo);
            var celdas = grupo.length + (g ? 0 : 1);
            var cols = celdas <= 2 ? 2 : celdas <= 4 ? 2 : 3, filas = Math.ceil(celdas / cols);
            var X0 = 0.35, Y0 = 1.6, W = 12.63, H = 5.6, sep = 0.22;
            var cw = (W - sep * (cols - 1)) / cols, ch = Math.min(2.85, (H - sep * (filas - 1)) / filas);
            var Y1 = Y0 + (H - (ch * filas + sep * (filas - 1))) / 2;   // el bloque centrado en lo alto
            var pos = function (k) { return { x: X0 + (k % cols) * (cw + sep), y: Y1 + Math.floor(k / cols) * (ch + sep) }; };
            var k0 = 0;
            if (!g) {
                var p = pos(0);
                lam.addShape(pptx.ShapeType.roundRect, { x: p.x, y: p.y, w: cw, h: ch, fill: { color: AZUL }, line: { color: AZUL }, rectRadius: 0.1 });
                lam.addShape(pptx.ShapeType.rect, { x: p.x, y: p.y + 0.1, w: 0.07, h: ch - 0.2, fill: { color: NARANJA }, line: { color: NARANJA } });
                lam.addText([
                    { text: 'GASOIL RECIBIDO EN EL MES', options: { fontSize: 11, bold: true, color: 'FFFFFF', charSpacing: 1, breakLine: true } },
                    { text: mil(patio.total_recibido) + ' L', options: { fontSize: 36, bold: true, color: 'FFFFFF', breakLine: true } },
                    { text: 'en ' + cis.length + (cis.length === 1 ? ' cisterna' : ' cisternas'), options: { fontSize: 14, color: 'FFFFFF' } }
                ], T({ x: p.x + 0.35, y: p.y + 0.2, w: cw - 0.6, h: ch - 0.4, valign: 'middle' }));
                k0 = 1;
            }
            grupo.forEach(function (c, j) {
                var n = (g ? 5 + (g - 1) * 6 : 0) + j, p = pos(k0 + j), f = fotos[n];
                if (f) ponerFoto(lam, f, p.x, p.y, cw, ch);
                else {
                    lam.addShape(pptx.ShapeType.rect, { x: p.x, y: p.y, w: cw, h: ch, fill: { color: GRIS }, line: { color: GRIS_BARRA } });
                    lam.addText('Sin foto', T({ x: p.x, y: p.y, w: cw, h: ch - 0.5, align: 'center', valign: 'middle', fontSize: 12, color: '8A94A3' }));
                }
                var bh = 0.5, by = p.y + ch - bh;
                lam.addShape(pptx.ShapeType.rect, { x: p.x, y: by, w: cw, h: bh, fill: { color: '1F2D3D', transparency: 20 }, line: { color: '1F2D3D', transparency: 100 } });
                lam.addShape(pptx.ShapeType.ellipse, { x: p.x + 0.15, y: by + 0.11, w: 0.28, h: 0.28, fill: { color: NARANJA }, line: { color: 'FFFFFF', width: 1 } });
                lam.addText(String(n + 1), T({ x: p.x + 0.15, y: by + 0.11, w: 0.28, h: 0.28, align: 'center', valign: 'middle', fontSize: 10, bold: true, color: 'FFFFFF' }));
                lam.addText((c.numero ? 'SOLICITUD ' + c.numero : 'PEDIDO') + ' · ' + c.fecha,
                    T({ x: p.x + 0.55, y: by, w: cw - 2.2, h: bh, valign: 'middle', fontSize: 9, bold: true, color: 'FFFFFF', fit: 'shrink' }));
                lam.addText(mil(c.litros) + ' L', T({ x: p.x + cw - 1.75, y: by, w: 1.6, h: bh, align: 'right', valign: 'middle', fontSize: 16, bold: true, color: 'FFFFFF' }));
            });
        });
    }

    // ── 5. Consumo del cupo: un tanque por patio y, al centro, los totales ──
    function laminaCupo(pptx, d, logo) {
        var lam = pptx.addSlide();
        encabezado(pptx, lam, 'CONSUMO DEL CUPO DE GASOIL ' + d.mes, logo);
        var conCupo = d.cupos.filter(function (c) { return c.cupo !== null; });
        var nombres = d.cupos.map(function (c) { return c.nombre.toUpperCase(); }).join(' + ');
        var xs = [0.7, 9.75];
        d.cupos.slice(0, 2).forEach(function (c, i) {
            var x = xs[i], w = 2.9, ty = 2.75, th = 3.9;
            lam.addText(c.nombre.toUpperCase(), T({ x: x - 0.3, y: 1.55, w: w + 0.6, h: 0.35, align: 'center', fontSize: 14, bold: true, color: '000000' }));
            lam.addShape(pptx.ShapeType.roundRect, { x: x + 0.45, y: 1.98, w: w - 0.9, h: 0.6, fill: { color: TINTA }, line: { color: TINTA }, rectRadius: 0.08 });
            lam.addText([
                { text: 'CUPO MENSUAL', options: { fontSize: 7, bold: true, color: 'FFFFFF', charSpacing: 1, breakLine: true } },
                { text: c.cupo !== null ? mil(c.cupo) + ' L' : 'Sin cargar', options: { fontSize: 14, bold: true, color: 'FFFFFF' } }
            ], T({ x: x + 0.45, y: 1.98, w: w - 0.9, h: 0.6, align: 'center', valign: 'middle' }));
            // El tanque: gris (lo que queda) y, desde abajo, naranja (lo consumido).
            var pct = c.cupo ? c.consumido / c.cupo : (c.consumido > 0 ? 1 : 0), lleno = Math.min(1, pct) * th;
            lam.addShape(pptx.ShapeType.roundRect, { x: x, y: ty, w: w, h: th, fill: { color: 'E4E8EE' }, line: { color: '9AA5B1', width: 1.25 }, rectRadius: 0.18 });
            if (lleno > 0.05) lam.addShape(pptx.ShapeType.roundRect, { x: x, y: ty + th - lleno, w: w, h: lleno, fill: { color: NARANJA }, line: { color: '9AA5B1', width: 1.25 }, rectRadius: Math.min(0.18, lleno / 2) });
            lam.addShape(pptx.ShapeType.rect, { x: x + 0.3, y: ty + th, w: 0.12, h: 0.25, fill: { color: '7B8794' }, line: { color: '7B8794' } });
            lam.addShape(pptx.ShapeType.rect, { x: x + w - 0.42, y: ty + th, w: 0.12, h: 0.25, fill: { color: '7B8794' }, line: { color: '7B8794' } });
            lam.addShape(pptx.ShapeType.rect, { x: x - 0.1, y: ty + th + 0.25, w: w + 0.2, h: 0.07, fill: { color: '7B8794' }, line: { color: '7B8794' } });
            var arriba = Math.max(0.75, th - lleno);
            if (c.cupo !== null) {
                lam.addText([
                    { text: c.por_consumir < 0 ? 'SE PASÓ DEL CUPO' : 'POR CONSUMIR DEL CUPO', options: { fontSize: 8, bold: true, color: c.por_consumir < 0 ? ROJO : '000000', breakLine: true } },
                    { text: mil(Math.abs(c.por_consumir)) + ' L', options: { fontSize: 18, bold: true, color: c.por_consumir < 0 ? ROJO : '000000' } }
                ], T({ x: x, y: ty + 0.1, w: w, h: Math.min(0.8, arriba - 0.1), align: 'center', valign: 'middle' }));
            }
            var cy = Math.min(ty + th - 1.2, ty + th - lleno + 0.15);
            lam.addText([
                { text: 'VOLUMETRÍA CONSUMIDA', options: { fontSize: 8, bold: true, color: lleno > 1.1 ? 'FFFFFF' : '000000', breakLine: true } },
                { text: mil(c.consumido) + ' L', options: { fontSize: 22, bold: true, color: lleno > 1.1 ? 'FFFFFF' : '000000', breakLine: !!c.cupo } },
                c.cupo ? { text: (pct * 100).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' % del cupo',
                    options: { fontSize: 10, bold: true, color: lleno > 1.1 ? 'FFFFFF' : '000000' } } : { text: '' }
            ], T({ x: x, y: cy, w: w, h: 1.05, align: 'center', valign: 'middle' }));
        });
        lam.addShape(pptx.ShapeType.line, { x: 4.35, y: 1.6, w: 0, h: 5.6, line: { color: 'D5DBE3', width: 0.75 } });
        lam.addShape(pptx.ShapeType.line, { x: 8.95, y: 1.6, w: 0, h: 5.6, line: { color: 'D5DBE3', width: 0.75 } });
        var suma = function (k) { return conCupo.reduce(function (s, c) { return s + (c[k] || 0); }, 0); };
        var consumidoTodo = d.cupos.reduce(function (s, c) { return s + c.consumido; }, 0);
        [
            { t: 'CUPO DISPONIBLE MENSUAL', v: conCupo.length ? mil(suma('cupo')) + ' L' : 'Sin cargar', fondo: TINTA, letra: 'FFFFFF', franja: '8A94A3' },
            { t: 'VOLUMEN CONSUMIDO DEL CUPO', v: mil(consumidoTodo) + ' L', fondo: GRIS, letra: '000000', franja: NARANJA },
            { t: 'VOLUMEN POR CONSUMIR DEL CUPO', v: conCupo.length ? mil(suma('por_consumir')) + ' L' : '—', fondo: GRIS, letra: '000000', franja: '8A94A3' }
        ].forEach(function (k, i) {
            var y = 2.5 + i * 1.45;
            lam.addShape(pptx.ShapeType.roundRect, { x: 4.75, y: y, w: 3.8, h: 1.2, fill: { color: k.fondo }, line: { color: k.fondo }, rectRadius: 0.08 });
            lam.addShape(pptx.ShapeType.rect, { x: 4.75, y: y + 0.08, w: 0.06, h: 1.04, fill: { color: k.franja }, line: { color: k.franja } });
            lam.addText([
                { text: k.t, options: { fontSize: 10, bold: true, color: k.letra, breakLine: true } },
                { text: nombres, options: { fontSize: 7, bold: true, color: NARANJA, breakLine: true } },
                { text: k.v, options: { fontSize: 22, bold: true, color: k.letra } }
            ], T({ x: 4.85, y: y, w: 3.6, h: 1.2, align: 'center', valign: 'middle' }));
        });
    }

    // ── 6. Histórico de despacho (8 meses) y cronograma de solicitudes de un patio ──
    var LETRAS = ['L', 'M', 'MI', 'J', 'V', 'S', 'D'];
    function historico(pptx, lam, patio, y0, alto) {
        lam.addText('HISTÓRICO DE DESPACHO DIESEL (LITROS)', T({ x: 0.5, y: y0, w: 12.3, h: 0.35, align: 'center', fontSize: 13, bold: true, color: '000000' }));
        var h = patio.historico, n = h.length, x0 = 0.6, W = 12.1, paso = W / n, bw = Math.min(1.0, paso * 0.6);
        var base = y0 + alto - 0.3, maxH = alto - 1.15;
        var mayor = Math.max.apply(null, h.map(function (m) { return m.litros; }).concat([1]));
        h.forEach(function (m, i) {
            var cx = x0 + i * paso + paso / 2, bh = Math.max(0.02, maxH * m.litros / mayor), col = i === n - 1 ? NARANJA : AZUL;
            if (m.litros > 0) lam.addShape(pptx.ShapeType.rect, { x: cx - bw / 2, y: base - bh, w: bw, h: bh, fill: { color: col }, line: { color: col } });
            lam.addText(m.cisternas ? [
                { text: m.cisternas === 1 ? '1 CISTERNA' : m.cisternas + ' CISTERNAS', options: { fontSize: 7, color: '000000', breakLine: true } },
                { text: mil(m.litros), options: { fontSize: 11, bold: true, color: '000000' } }
            ] : [{ text: '—', options: { fontSize: 11, color: '8A94A3' } }], T({ x: cx - paso / 2, y: base - bh - 0.5, w: paso, h: 0.45, align: 'center', valign: 'bottom' }));
            lam.addText(m.mes, T({ x: cx - paso / 2, y: base + 0.05, w: paso, h: 0.25, align: 'center', fontSize: 8, bold: true, color: '000000' }));
        });
        lam.addShape(pptx.ShapeType.line, { x: x0, y: base, w: W, h: 0, line: { color: 'B8C0CC', width: 0.75 } });
    }
    function cronograma(pptx, lam, crono, sols, y0, cont) {
        lam.addText('CRONOGRAMA DE SOLICITUD DE COMBUSTIBLE ' + crono.mes + (cont ? ' (CONT.)' : ''),
            T({ x: 0.5, y: y0, w: 12.3, h: 0.35, align: 'center', fontSize: 13, bold: true, color: '000000' }));
        var X = 1.85, W = 11.0, dw = W / crono.dias, yl = y0 + 0.45, yn = yl + 0.24;
        var planeado = {}, llego = {};
        crono.solicitudes.forEach(function (s) { if (s.llego) llego[s.dia] = true; else planeado[s.dia] = true; });
        for (var dia = 1; dia <= crono.dias; dia++) {
            var x = X + (dia - 1) * dw, color = llego[dia] && !planeado[dia] ? '3F8F3F' : planeado[dia] ? ROJO : TINTA;
            lam.addShape(pptx.ShapeType.rect, { x: x + 0.01, y: yl, w: dw - 0.02, h: 0.2, fill: { color: 'E4E8EE' }, line: { color: 'E4E8EE' } });
            lam.addText(LETRAS[(crono.primer_dia - 1 + dia - 1) % 7], T({ x: x, y: yl, w: dw, h: 0.2, align: 'center', valign: 'middle', fontSize: 6, bold: true, color: '4A5563' }));
            lam.addShape(pptx.ShapeType.rect, { x: x + 0.01, y: yn, w: dw - 0.02, h: 0.22, fill: { color: color }, line: { color: color } });
            lam.addText(String(dia), T({ x: x, y: yn, w: dw, h: 0.22, align: 'center', valign: 'middle', fontSize: 7, bold: true, color: 'FFFFFF' }));
        }
        if (!sols.length) {
            lam.addText('Sin solicitudes programadas.', T({ x: X, y: yn + 0.4, w: W, h: 0.4, align: 'center', fontSize: 11, color: '4A5563' }));
            return;
        }
        var y = yn + 0.32, rh = Math.min(0.3, (7.25 - y) / sols.length);
        sols.forEach(function (s, i) {
            var ry = y + i * rh, col = s.llego ? '3F8F3F' : ROJO, x = X + (s.dia - 1) * dw;
            lam.addShape(pptx.ShapeType.rect, { x: 0.5, y: ry + 0.02, w: X + W - 0.5, h: rh - 0.04, fill: { color: GRIS }, line: { color: GRIS } });
            lam.addText(s.numero ? 'SOLICITUD ' + s.numero : 'SOLICITUD', T({ x: 0.55, y: ry, w: 1.3, h: rh, valign: 'middle', fontSize: Math.min(8, rh * 28), bold: true, color: s.llego ? '000000' : ROJO }));
            if (!s.llego) lam.addShape(pptx.ShapeType.line, { x: x + dw / 2, y: yn + 0.22, w: 0, h: ry - yn - 0.2, line: { color: ROJO, width: 0.75 } });
            lam.addShape(pptx.ShapeType.roundRect, { x: x + 0.02, y: ry + 0.04, w: dw - 0.04, h: rh - 0.08, fill: { color: col }, line: { color: col }, rectRadius: 0.03 });
        });
    }
    // Hasta 7 solicitudes van con el histórico en la misma lámina; más, el cronograma va aparte (16 por lámina).
    function laminasHistorico(pptx, patio, logo) {
        var titulo = 'HISTÓRICO DE DESPACHO Y CRONOGRAMA\nDE SOLICITUD DE GASOIL ' + patio.nombre.toUpperCase();
        var sols = patio.cronograma.solicitudes, lam = pptx.addSlide();
        encabezado(pptx, lam, titulo, logo);
        if (sols.length <= 7) {
            historico(pptx, lam, patio, 1.45, 2.65);
            lam.addShape(pptx.ShapeType.line, { x: 0.5, y: 4.2, w: 12.33, h: 0, line: { color: 'E4E8EE', width: 0.75 } });
            cronograma(pptx, lam, patio.cronograma, sols, 4.3, false);
            return;
        }
        historico(pptx, lam, patio, 1.6, 5.4);
        for (var i = 0; i < sols.length; i += 16) {
            var l = pptx.addSlide();
            encabezado(pptx, l, titulo, logo);
            cronograma(pptx, l, patio.cronograma, sols.slice(i, i + 16), 1.5, i > 0);
        }
    }

    function descargarPowerPoint(url, idProyecto) {
        return Promise.all([cargarLib(), logoDataUrl(), pedirDatos(url, { id_proyecto: idProyecto }), fotoJpeg(PORTADA, 1400)]).then(function (r) {
            var d = r[2], logo = r[1], portada = r[3];
            if (d.vacio) throw new Error('No hay gasoil cargado.');
            var cis = d.cisternas;
            // Las fotos de todas las cisternas recibidas, por patio (en paralelo).
            return Promise.all(cis.patios.map(function (p) {
                return Promise.all(p.recibidas.map(function (c) { return fotoJpeg(c.foto, 1400); }));
            })).then(function (fotos) {
                var pptx = new window.PptxGenJS();
                pptx.layout = 'LAYOUT_WIDE';
                pptx.title = 'Gestión, control y suministro de combustible';
                laminaPortada(pptx, cis.mes, logo, portada);
                cis.patios.forEach(function (p, i) { if (p.recibidas.length) laminasSuministro(pptx, cis.mes, p, fotos[i], logo); });
                laminaProyectos(pptx, d, logo);
                if (d.proyecto) laminaUnProyecto(pptx, d, logo);
                if (cis.cupos.some(function (c) { return c.cupo !== null || c.consumido > 0; })) laminaCupo(pptx, cis, logo);
                cis.patios.forEach(function (p) {
                    var hay = p.cronograma.solicitudes.length || p.historico.some(function (m) { return m.litros > 0; });
                    if (hay) laminasHistorico(pptx, p, logo);
                });
                return pptx.writeFile({ fileName: 'Gestion_Combustible_' + cis.mes.replace(/\s+/g, '_') + '.pptx' });
            });
        });
    }

    // ── Diálogo «Exportar» ───────────────────────────────────────────────────
    window.consumiblesExportar = function (opc) {
        leerFiltros = opc.filtros;
        var viejo = document.getElementById('consExportarModal');
        if (viejo) viejo.remove();
        var m = document.createElement('div');
        m.id = 'consExportarModal';
        m.className = 'cons-exp-fondo';
        m.innerHTML =
            '<div class="cons-exp-caja" role="dialog" aria-modal="true" aria-labelledby="consExpTitulo">' +
            '<div class="cons-exp-cab"><span id="consExpTitulo">Exportar</span>' +
            '<button type="button" class="cons-exp-x" data-cerrar title="Cerrar"><i class="material-icons">close</i></button></div>' +
            '<div class="cons-exp-opciones">' +
            '<button type="button" class="cons-exp-op" data-excel><i class="material-icons" style="color:#16a34a;">table_view</i><b>Excel</b><small>Los datos en una hoja</small></button>' +
            '<button type="button" class="cons-exp-op" data-ppt><i class="material-icons" style="color:#F26A21;">slideshow</i><b>PowerPoint</b><small>Presentación de gasoil</small></button>' +
            '</div>' +
            '<div class="cons-exp-ppt" hidden>' +
            '<label for="consExpProyecto">Proyecto para la lámina de detalle</label>' +
            '<select id="consExpProyecto" disabled><option>Cargando…</option></select>' +
            '<div class="cons-exp-nota" data-nota></div>' +
            '<button type="button" class="btn-primary-maquinaria cons-exp-bajar" data-bajar disabled><i class="material-icons">download</i> Descargar PowerPoint</button>' +
            '</div></div>';
        document.body.appendChild(m);

        var cerrar = function () { m.remove(); document.removeEventListener('keydown', tecla); };
        var tecla = function (e) { if (e.key === 'Escape') cerrar(); };
        document.addEventListener('keydown', tecla);
        m.addEventListener('click', function (e) { if (e.target === m || e.target.closest('[data-cerrar]')) cerrar(); });

        m.querySelector('[data-excel]').addEventListener('click', function () { cerrar(); opc.excel(); });

        var sel = m.querySelector('#consExpProyecto'), nota = m.querySelector('[data-nota]'), bajar = m.querySelector('[data-bajar]');
        m.querySelector('[data-ppt]').addEventListener('click', function () {
            m.querySelector('.cons-exp-ppt').hidden = false;
            m.querySelectorAll('.cons-exp-op').forEach(function (b) { b.classList.toggle('activo', b.hasAttribute('data-ppt')); });
            pedirDatos(opc.urlDatos).then(function (d) {
                if (d.vacio || !d.proyectos.length) { sel.innerHTML = '<option>Sin gasoil en el período</option>'; return; }
                sel.innerHTML = d.proyectos.map(function (p) {
                    return '<option value="' + p.id + '"' + (d.proyecto && d.proyecto.id === p.id ? ' selected' : '') + '>' +
                        esc(p.nombre) + ' · ' + mil(p.litros) + ' L</option>';
                }).join('');
                sel.disabled = false; bajar.disabled = false;
                nota.textContent = 'Período: del ' + d.desde + ' al ' + d.hasta + '.';
            }).catch(function (e) { nota.textContent = e.message; });
        });

        bajar.addEventListener('click', function () {
            bajar.disabled = true;
            nota.textContent = 'Armando la presentación…';
            descargarPowerPoint(opc.urlDatos, sel.value).then(cerrar).catch(function (e) {
                bajar.disabled = false;
                nota.textContent = e.message || 'No se pudo armar la presentación.';
            });
        });
    };
})();
