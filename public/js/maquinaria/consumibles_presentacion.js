/**
 * Gráficos de consumibles → «Exportar»: pregunta Excel o PowerPoint.
 * - Excel: la descarga de siempre (descargarExcel() de graficos.blade.php).
 * - PowerPoint: arma en el navegador la presentación de gasoil con los datos del sistema
 *   (ConsumiblesController::presentacionData) y la descarga. Dos láminas con el estilo de la
 *   presentación de Combustible: (1) consumo por proyecto con el total; (2) un proyecto, que se elige
 *   aquí: su consumo de los últimos 6 meses y sus 4 equipos de mayor consumo.
 * Los gráficos van como gráficos de PowerPoint (se pueden editar). PptxGenJS se carga solo al usarlo.
 */
(function () {
    'use strict';

    var AZUL = '1F4E79', NARANJA = 'F26A21', ROJO = 'A8261F', GRIS = 'F2F4F7', GRIS_BARRA = 'DCE2EA', TINTA = '2B3440';
    var LIB = '/js/vendor/pptxgen-3.12.0.bundle.min.js';
    var LOGO = '/images/maquinaria/logo.png';

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

    // ── Láminas ──────────────────────────────────────────────────────────────
    // 13,33 × 7,5 pulgadas (16:9). Encabezado azul con el logo a la izquierda, como la presentación.
    function encabezado(pptx, lam, titulo, logo) {
        lam.background = { color: 'FFFFFF' };
        lam.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 13.333, h: 1.3, fill: { color: AZUL }, line: { color: AZUL } });
        lam.addShape(pptx.ShapeType.rect, { x: 0, y: 0, w: 3.3, h: 1.3, fill: { color: 'FFFFFF' }, line: { color: 'FFFFFF' } });
        if (logo) lam.addImage({ data: logo, x: 0.25, y: 0.4, w: 2.8, h: 0.5 });
        // Una sola línea: los nombres de proyecto largos bajan la letra.
        var tam = titulo.length <= 32 ? 26 : titulo.length <= 42 ? 21 : 17;
        lam.addText(titulo, { x: 3.5, y: 0.2, w: 9.5, h: 0.9, align: 'right', valign: 'middle', fontFace: 'Arial', fontSize: tam, bold: true,
            color: 'FFFFFF' });
    }

    function laminaProyectos(pptx, d, logo) {
        var lam = pptx.addSlide();
        encabezado(pptx, lam, 'CONSUMO DE GASOIL POR PROYECTO', logo);
        lam.addText('Del ' + d.desde + ' al ' + d.hasta, { x: 0.4, y: 1.4, w: 8, h: 0.35, fontFace: 'Arial', fontSize: 11, color: '4A5563' });
        lam.addText('CONSUMO POR PROYECTO (LITROS)', { x: 0.4, y: 1.8, w: 8.3, h: 0.45, align: 'center', fontFace: 'Arial', fontSize: 17, bold: true, color: '000000' });

        var ps = d.proyectos.slice(0, 15);
        if (ps.length) {
            // Dos series superpuestas (una en 0 donde va la otra) para el color: los 4 de mayor consumo en rojo, el resto en azul.
            var nombres = ps.map(function (p) { return p.nombre; });
            lam.addChart(pptx.ChartType.bar, [
                { name: 'Mayor consumo', labels: nombres, values: ps.map(function (p, k) { return k < 4 ? p.litros : 0; }) },
                { name: 'Resto', labels: nombres, values: ps.map(function (p, k) { return k < 4 ? 0 : p.litros; }) }
            ], {
                x: 0.4, y: 2.3, w: 8.3, h: 4.95, barDir: 'bar', barGrouping: 'clustered', barOverlapPct: 100, barGapWidthPct: 45,
                chartColors: [ROJO, AZUL], catAxisOrientation: 'maxMin', catAxisLabelFontFace: 'Arial', catAxisLabelFontSize: 10,
                catAxisLabelColor: TINTA, catAxisLineShow: false, valAxisHidden: true, valGridLine: { style: 'none' },
                showValue: true, dataLabelFormatCode: '#,##0;;;', dataLabelFontFace: 'Arial', dataLabelFontSize: 10, dataLabelFontBold: true,
                dataLabelColor: '000000', dataLabelPosition: 'outEnd', showLegend: false
            });
        } else {
            lam.addText('Sin gasoil en el período.', { x: 0.4, y: 3.8, w: 8.3, h: 0.5, align: 'center', fontFace: 'Arial', fontSize: 14, color: '4A5563' });
        }

        lam.addShape(pptx.ShapeType.line, { x: 8.95, y: 1.6, w: 0, h: 5.6, line: { color: 'D5DBE3', width: 0.75 } });
        lam.addShape(pptx.ShapeType.roundRect, { x: 9.25, y: 3.0, w: 3.7, h: 1.25, fill: { color: AZUL }, line: { color: AZUL }, rectRadius: 0.12 });
        lam.addText('CONSUMO TOTAL', { x: 9.45, y: 3.1, w: 3.3, h: 0.35, fontFace: 'Arial', fontSize: 11, bold: true, color: 'FFFFFF', charSpacing: 1 });
        lam.addText(mil(d.total) + ' L', { x: 9.45, y: 3.45, w: 3.3, h: 0.7, fontFace: 'Arial', fontSize: 32, bold: true, color: 'FFFFFF' });
        if (d.sin_frente > 0) {
            lam.addText('Incluye ' + mil(d.sin_frente) + ' L cargados sin proyecto.', { x: 9.25, y: 4.35, w: 3.7, h: 0.4, fontFace: 'Arial', fontSize: 10, color: '4A5563' });
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

    function descargarPowerPoint(url, idProyecto) {
        return Promise.all([cargarLib(), logoDataUrl(), pedirDatos(url, { id_proyecto: idProyecto })]).then(function (r) {
            var d = r[2], logo = r[1];
            if (d.vacio) throw new Error('No hay gasoil cargado.');
            var pptx = new window.PptxGenJS();
            pptx.layout = 'LAYOUT_WIDE';
            pptx.title = 'Consumo de gasoil';
            laminaProyectos(pptx, d, logo);
            if (d.proyecto) laminaUnProyecto(pptx, d, logo);
            return pptx.writeFile({ fileName: 'Consumo_Gasoil_' + d.hasta.split('/').reverse().join('-') + '.pptx' });
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
            '<label for="consExpProyecto">Proyecto de la segunda lámina</label>' +
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
