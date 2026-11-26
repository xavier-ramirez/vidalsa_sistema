/**
 * Láminas de la presentación de gasoil, IGUALES a las del maestro del usuario
 * (Downloads/PRESENTACIONES/presentacion_gasoil/Presentacion_Combustible_Vidalsa.html, servido en :8800):
 * el mismo HTML y CSS (consumibles_laminas.css), armado con los datos del sistema según las reglas de su
 * pedidos.py (reparto de fotos, tanques, histórico, cronograma). Con ESE mismo dibujo:
 *   - Gráficos muestra solo los gráficos (sin encabezado ni portada): GasoilLaminas.pintar();
 *   - «Exportar → PowerPoint» lo pasa a un PowerPoint editable con el MISMO convertidor del maestro
 *     (formas, textos, fotos y dibujos, cada uno como su objeto): GasoilLaminas.bajar().
 * Así lo que se ve y lo que se baja no pueden salir distintos. Si el usuario cambia el diseño de su original,
 * se vuelve a copiar aquí (markup) y en consumibles_laminas.css (estilos); no se rediseña por separado.
 * Excepción: «Consumo de gasoil por proyecto» es propia del sistema (no se copia del maestro).
 */
(function () {
    'use strict';

    var LOGO_PORTADA = '/images/maquinaria/logo_vertical.png';
    var LOGO_ENC = '/images/maquinaria/logo_encabezado.png';
    var FOTO_PORTADA = '/images/maquinaria/gasoil_portada.jpg';
    var LIB_PPTX = '/js/vendor/pptxgen-3.12.0.bundle.min.js';
    var LIB_IMG = '/js/vendor/html-to-image-1.11.11.min.js';
    var SEM = ['L', 'M', 'MI', 'J', 'V', 'S', 'D'];
    var CAMION = '<svg viewBox="0 0 20 14" aria-hidden="true"><path d="M1 2.5h10.5v7.5H1z M12.5 5h3.6l2.9 3v2H12.5z"/>' +
        '<circle cx="5" cy="11.2" r="1.9"/><circle cx="15.5" cy="11.2" r="1.9"/></svg>';

    // ── formatos (es-VE: punto de miles, coma decimal), como pedidos.py ──
    function miles(entero) { return String(entero).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
    function mil(n) { var r = Math.round(n || 0); return (r < 0 ? '-' : '') + miles(Math.abs(r)); }
    function dec(x) { var p = Math.abs(x || 0).toFixed(2).split('.'); return (x < 0 ? '-' : '') + miles(p[0]) + ',' + p[1]; }
    function plural(n, uno, varios) { return n + ' ' + (n === 1 ? uno : varios); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
    function may(s) { return esc(String(s || '').toUpperCase()); }

    // ── piezas comunes ──
    function encabezado(titulo, dosLineas) {
        return '<div class="enc"><div class="enc-logo"><img src="' + LOGO_ENC + '" alt="Constructora Vidalsa 27"></div>' +
            '<h2' + (dosLineas ? ' class="dos"' : '') + '>' + titulo + '</h2></div>';
    }
    function lamina(clase, cuerpo, tipo) { return { tipo: tipo, html: '<section class="lamina ' + clase + '">' + cuerpo + '</section>' }; }

    // ── 1. Portada (la del maestro: ola, banda en degradado, sello con el surtidor, foto y logo) ──
    function portada(mes) {
        return lamina('portada cb-port',
            '<svg class="pt-ola" viewBox="0 0 1280 720" aria-hidden="true"><path d="M0,365.76L0,722.29L1280,722.29L1280,673.8L1268.69,668.44C1228.41,649.99 1189.6,635.49 1157.83,627.55C995.17,586.88 680.5,714.88 477.83,669.55C313.17,632.71 128.48,431.5 11.19,371.11Z"/></svg>' +
            '<div class="pt-banda"></div>' +
            '<h1 class="pt-titulo"><span>GESTIÓN, CONTROL Y SUMINISTRO</span><span>DE COMBUSTIBLE</span></h1>' +
            '<img style="object-position: 100% 46.8%" class="cb-foto-portada" src="' + FOTO_PORTADA + '" alt="Cisterna de gasoil">' +
            '<div class="pt-sello"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16M3 21h13M7 7h5v4H7z"/><path d="M15 10h2a2 2 0 0 1 2 2v5a1.5 1.5 0 0 0 3 0V9l-3-3"/></svg></div>' +
            '<img class="pt-logo" src="' + LOGO_PORTADA + '" alt="Constructora Vidalsa 27">' +
            '<p class="cb-fecha">' + may(mes) + '</p>', 'portada');
    }

    // ── 2. Resumen de suministro de un patio (pedidos.py · armar_pedidos y reparto) ──
    var ZONA_W = 1232, ZONA_H = 562, HUECO = 16;
    function reparto(n) {
        var mejor = 0, colsMejor = 1;
        for (var cols = 1; cols <= n; cols++) {
            var filas = Math.ceil(n / cols);
            var ancho = (ZONA_W - (cols - 1) * HUECO) / cols, porAlto = (ZONA_H - (filas - 1) * HUECO) / filas * 16 / 9;
            if (Math.min(ancho, porAlto) > mejor) { mejor = Math.min(ancho, porAlto); colsMejor = cols; }
        }
        var w = Math.floor(mejor);
        return { w: w, h: Math.floor(w * 9 / 16), cols: colsMejor };
    }
    // Como el maestro hasta 12 pedidos: el total + 11 en la primera lámina y 12 en cada una de las siguientes.
    function suministro(patio, mes) {
        var cis = patio.recibidas, grupos = [cis.slice(0, 11)];
        for (var i = 11; i < cis.length; i += 12) grupos.push(cis.slice(i, i + 12));
        var mesSolo = may(String(mes).split(' ')[0]);
        return grupos.map(function (grupo, g) {
            var r = reparto(grupo.length + (g ? 0 : 1)), celdas = '';
            if (!g) {
                celdas = '<div class="cb-total"><small>GASOIL RECIBIDO EN EL MES</small><b>' + mil(patio.total_recibido) + ' L</b>' +
                    '<span>en ' + plural(cis.length, 'cisterna', 'cisternas') + '</span></div>';
            }
            grupo.forEach(function (c, j) {
                var k = (g ? 11 + (g - 1) * 12 : 0) + j + 1;
                celdas += '<div class="cb-ped">' +
                    (c.foto ? '<img style="object-position: 50% 50%" src="' + esc(c.foto) + '" alt="Pedido ' + esc(c.fecha) + '">'
                        : '<div class="cb-sinfoto">Sin foto</div>') +
                    '<div class="cb-pie"><span class="cb-num">' + k + '</span><small>PEDIDO · ' + esc(c.fecha) + '</small>' +
                    '<strong>' + mil(c.litros) + ' L</strong></div></div>';
            });
            return lamina('cb', encabezado('RESUMEN DE SUMINISTRO DE GASOIL<br>' + mesSolo + ' · ' + may(patio.nombre) + (g ? ' (CONT.)' : ''), true) +
                '<div class="cb-pedidos" style="--w:' + r.w + 'px;--h:' + r.h + 'px;--cols:' + r.cols + '">' + celdas + '</div>', 'suministro');
        });
    }

    // ── 3. Consumo por proyecto ──
    // Barras ordenadas: proyecto, barra sobre su pista (escala: el mayor = pista llena) y litros en columnas alineadas.
    // Tres colores con sentido: los 4 de mayor consumo en rojo; del resto, la mitad de más consumo en azul y la otra
    // mitad en gris. Revisados con el validador de dataviz (se distinguen también con daltonismo; el gris lleva sus litros
    // escritos al lado). A la derecha: el total y el consumo por división (en azul), con su %.
    var ROJO = '#C0392B', AZUL = '#2E75B6', GRIS = '#A3ACB9';
    var MESES = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
    // Período de la lámina: un mes completo (del 1 al último día) sale con su nombre; si no, «DEL … AL …».
    function periodoDe(desde, hasta) {
        var a = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(desde || ''), b = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(hasta || '');
        if (!a || !b) return '';
        var ultimo = new Date(+b[3], +b[2], 0).getDate();
        if (a[1] === '01' && a[2] === b[2] && a[3] === b[3] && +b[1] === ultimo) return MESES[+a[2] - 1] + ' ' + a[3];
        return 'DEL ' + desde + ' AL ' + hasta;
    }
    var MAX_FILAS = 25;   // con más proyectos, los últimos se suman en una fila «Otros» (más filas no se leen en la lámina)
    var ALTO_FILAS = 432; // alto para las filas dentro de la tarjeta (sin el título)
    function pct(parte, total) { return (total > 0 ? Math.round(parte / total * 1000) / 10 : 0).toFixed(1).replace('.', ',') + ' %'; }
    function proyectos(d) {
        var ps = d.proyectos.slice(0, d.proyectos.length > MAX_FILAS ? MAX_FILAS - 1 : MAX_FILAS), otros = d.proyectos.slice(ps.length);
        var mayor = Math.max.apply(null, ps.map(function (p) { return p.litros; }).concat([1]));
        // Con muchos proyectos las filas llenan el alto de la tarjeta y se achican; con pocos, son más grandes.
        var n = ps.length + (otros.length ? 1 : 0), paso = ALTO_FILAS / Math.max(n, 1);
        var med = function (f, min, max) { return Math.max(min, Math.min(max, paso * f)).toFixed(1) + 'px'; };
        var vars = '--pista:' + med(0.6, 5, 22) + ';--nombre:' + med(0.5, 8, 12) + ';--cifra:' + med(0.5, 9, 13) +
            // con pocas filas, centradas y con separación tope (no se reparten por toda la tarjeta)
            (n < 13 ? ';justify-content:center;gap:' + Math.min(26, (ALTO_FILAS - n * 24) / Math.max(n - 1, 1)).toFixed(1) + 'px' : '');
        var fila = function (nombre, litros, ancho, color, clase) {
            return '<div class="gp-fila' + (clase ? ' ' + clase : '') + '"><small>' + nombre + '</small>' +
                '<div class="gp-pista">' + (ancho > 0 ? '<i style="width:' + ancho.toFixed(2) + '%;background:' + color + '"></i>' : '') + '</div>' +
                '<b>' + mil(litros) + '</b></div>';
        };
        var medios = Math.ceil((ps.length - 4) / 2);   // del resto, los de más consumo van en azul
        var filas = ps.map(function (p, k) {
            return fila(may(p.nombre), p.litros, 100 * p.litros / mayor, k < 4 ? ROJO : k < 4 + medios ? AZUL : GRIS);
        }).join('');
        if (otros.length) filas += fila('OTROS ' + otros.length + ' PROYECTOS', otros.reduce(function (s, p) { return s + p.litros; }, 0), 0, '', 'gp-otros');
        var periodo = periodoDe(d.desde, d.hasta);
        if (periodo) periodo = ' · ' + esc(periodo);
        var ley = '<div class="gp-ley"><span><i style="background:' + ROJO + '"></i>' + (ps.length > 4 ? 'MAYOR CONSUMO' : 'PROYECTOS') + '</span>' +
            (medios > 0 ? '<span><i style="background:' + AZUL + '"></i>CONSUMO MEDIO</span>' : '') +
            (ps.length > 4 + medios ? '<span><i style="background:' + GRIS + '"></i>MENOR CONSUMO</span>' : '') + '</div>';
        // La leyenda va arriba, a la derecha del título.
        var izq = '<div class="gp-card"><div class="gp-cabeza"><div><div class="gp-tit">CONSUMO POR PROYECTO</div>' +
            '<div class="gp-sub">LITROS DE GASOIL' + periodo + '</div></div>' + (ps.length ? ley : '') + '</div>' +
            (ps.length
                ? '<div class="gp-filas" style="' + vars + '">' + filas + '</div>'
                : '<p class="gp-vacio">' + (d.total > 0 ? 'El gasoil del período se cargó sin proyecto.' : 'Sin gasoil en el período.') + '</p>') + '</div>';

        // Derecha: total y consumo por división. El % y la barra de cada división, sobre la suma de las divisiones (así
        // suman 100 %; el total de arriba incluye además lo cargado sin proyecto, que no es de ninguna división).
        var divs = (d.divisiones || []).filter(function (v) { return v.litros > 0 || v.clave !== 'OTROS'; });
        var sumaDiv = divs.reduce(function (s, v) { return s + v.litros; }, 0);
        var der = '<div class="gp-der">' +
            '<div class="gp-caja gp-total"><small>CONSUMO TOTAL · ' + plural(d.proyectos.length, 'PROYECTO', 'PROYECTOS') + '</small><b>' + mil(d.total) + ' L</b></div>' +
            (ps.length && divs.length ? '<div class="gp-caja"><div class="gp-caja-tit">CONSUMO POR DIVISIÓN</div>' + divs.map(function (v) {
                return '<div class="gp-div"><small>' + may(v.nombre) + '</small><b>' + mil(v.litros) + ' L</b><em>' + pct(v.litros, sumaDiv) + '</em>' +
                    '<span><i style="width:' + (sumaDiv > 0 ? v.litros / sumaDiv * 100 : 0).toFixed(2) + '%"></i></span></div>';
            }).join('') + '</div>' : '') + '</div>';
        return lamina('cb', encabezado('CONSUMO DE GASOIL POR PROYECTO') + izq + der, 'grafico');
    }

    // ── 4. Consumo de un proyecto: 6 meses y su TOP 4 de equipos ──
    function unProyecto(p) {
        var mx = Math.max.apply(null, p.meses.map(function (m) { return m.litros; }).concat([1])), n = p.meses.length;
        var cols = p.meses.map(function (m, k) {
            return '<div class="pm-col' + (k === n - 1 ? ' pm-mes' : '') + '"><b>' + (m.litros > 0 ? mil(m.litros) : '—') + '</b>' +
                '<i style="height:' + (m.litros / mx * 100).toFixed(2) + '%"></i><small>' + may(m.mes) + '</small></div>';
        }).join('');
        var top = p.top.length ? p.top.map(function (e, k) {
            return '<div class="pm-eq"><span>' + (k + 1) + '</span><div class="pm-eq-dato"><small>' + may(e.tipo) + '</small><em>' + esc(e.id) + '</em>' +
                '<u>⛽ ' + plural(e.despachos, 'despacho', 'despachos') + '</u></div><b>' + mil(e.litros) + '<i>LITROS</i></b>' +
                '<p><s style="width:' + (e.litros / p.top[0].litros * 100).toFixed(1) + '%"></s></p></div>';
        }).join('') : '<div class="pm-vacio">Sin equipos confirmados en el período.</div>';
        return lamina('cb', encabezado('CONSUMO DE GASOIL · ' + may(p.nombre)) +
            '<div class="cb-l3tit pm-tit">CONSUMO MENSUAL (LITROS)</div><div class="pm-graf">' + cols + '</div>' +
            '<div class="cb-sep" style="left:858px;top:150px;height:550px"></div><div class="pm-der">' +
            '<div class="cb-l3sub pm-sub">TOP 4 EQUIPOS DE MAYOR CONSUMO</div><div class="pm-top">' + top + '</div></div>', 'detalle');
    }

    // ── 5. Consumo del cupo: un tanque por patio y las tres sumas al centro (pedidos.py · armar_cupos) ──
    var TQ_X0 = 60, TQ_X1 = 260, TQ_Y0 = 48, TQ_Y1 = 330, TQ_RY = 12;
    function tanqueSvg(porc, sid) {
        var cx = (TQ_X0 + TQ_X1) / 2, rx = (TQ_X1 - TQ_X0) / 2, y = (TQ_Y1 - (TQ_Y1 - TQ_Y0) * porc / 100).toFixed(1);
        var arco = function (yy) { return 'M' + TQ_X0 + ',' + yy.toFixed(1) + ' A' + rx + ',' + TQ_RY + ' 0 0 0 ' + TQ_X1 + ',' + yy.toFixed(1); };
        var g = 'g' + sid, peld = '';
        for (var v = 70; v < 330; v += 18) peld += '<line x1="266" y1="' + v + '" x2="282" y2="' + v + '"/>';
        return '<svg class="cb-dibujo" viewBox="0 0 290 372" aria-hidden="true"><defs>' +
            '<linearGradient id="' + g + 'a"><stop offset="0" stop-color="#9AA5B1"/><stop offset=".3" stop-color="#F3F5F7"/>' +
            '<stop offset=".55" stop-color="#D5DBE2"/><stop offset="1" stop-color="#8793A0"/></linearGradient>' +
            '<linearGradient id="' + g + 'l"><stop offset="0" stop-color="#B8480C"/><stop offset=".3" stop-color="#F8904D"/>' +
            '<stop offset=".6" stop-color="#F26A21"/><stop offset="1" stop-color="#A9420B"/></linearGradient>' +
            '<linearGradient id="' + g + 't" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#E9EDF1"/>' +
            '<stop offset="1" stop-color="#AEB8C3"/></linearGradient></defs>' +
            '<rect x="44" y="356" width="232" height="10" rx="2" fill="#7D8794"/>' +
            '<rect x="80" y="326" width="14" height="32" fill="#5F6975"/><rect x="226" y="326" width="14" height="32" fill="#5F6975"/>' +
            '<rect x="153" y="330" width="14" height="28" fill="#5F6975"/>' +
            '<path d="M' + TQ_X0 + ',' + TQ_Y0 + ' V' + TQ_Y1 + ' A' + rx + ',' + TQ_RY + ' 0 0 0 ' + TQ_X1 + ',' + TQ_Y1 + ' V' + TQ_Y0 + ' Z" fill="url(#' + g + 'a)"/>' +
            (porc > 0 ? '<path d="M' + TQ_X0 + ',' + y + ' V' + TQ_Y1 + ' A' + rx + ',' + TQ_RY + ' 0 0 0 ' + TQ_X1 + ',' + TQ_Y1 + ' V' + y + ' Z" fill="url(#' + g + 'l)"/>' +
                '<ellipse cx="' + cx + '" cy="' + y + '" rx="' + rx + '" ry="' + TQ_RY + '" fill="#F9A266" stroke="#FCC39B" stroke-width="2"/>' : '') +
            '<g fill="none" stroke="#5F6975" stroke-opacity=".35" stroke-width="3"><path d="' + arco(TQ_Y0 + 94) + '"/><path d="' + arco(TQ_Y0 + 188) + '"/></g>' +
            '<rect x="84" y="' + TQ_Y0 + '" width="12" height="' + (TQ_Y1 - TQ_Y0) + '" fill="#fff" opacity=".22"/>' +
            '<path d="M' + TQ_X0 + ',' + TQ_Y0 + ' V' + TQ_Y1 + ' A' + rx + ',' + TQ_RY + ' 0 0 0 ' + TQ_X1 + ',' + TQ_Y1 + ' V' + TQ_Y0 + '" fill="none" stroke="#5F6975" stroke-width="3"/>' +
            '<ellipse cx="' + cx + '" cy="' + TQ_Y0 + '" rx="' + rx + '" ry="' + TQ_RY + '" fill="url(#' + g + 't)" stroke="#5F6975" stroke-width="3"/>' +
            '<rect x="' + (cx - 20) + '" y="22" width="40" height="' + (TQ_Y0 - 22) + '" fill="#8E99A6" stroke="#5F6975" stroke-width="2"/>' +
            '<ellipse cx="' + cx + '" cy="22" rx="20" ry="6" fill="#C3CBD4" stroke="#5F6975" stroke-width="2"/>' +
            '<g stroke="#5F6975" stroke-width="2.5" fill="none"><path d="M196,' + (TQ_Y0 - 8) + ' V14 H258 V' + (TQ_Y0 - 2) + '"/>' +
            '<line x1="196" y1="28" x2="258" y2="28"/></g>' +
            '<g stroke="#5F6975" stroke-width="2.5"><line x1="266" y1="14" x2="266" y2="356"/><line x1="282" y1="14" x2="282" y2="356"/>' + peld + '</g>' +
            '<rect x="34" y="304" width="28" height="10" fill="#7D8794" stroke="#5F6975" stroke-width="2"/>' +
            '<circle cx="38" cy="309" r="9" fill="#C00000" stroke="#7A0000" stroke-width="2"/></svg>';
    }
    // Un patio = un tanque: la placa es el cupo, el líquido lo consumido y el hueco lo que falta. Sin cupo cargado
    // el tanque queda vacío y las cifras del cupo dicen «SIN CARGAR».
    function patioTanque(c, izq) {
        var cupo = c.cupo, desp = c.consumido || 0, falta = cupo !== null ? Math.max(cupo - desp, 0) : null;
        var porc = cupo ? Math.min(desp / cupo * 100, 100) : 0;
        var libre = (100 - porc) / 100 * (TQ_Y1 - TQ_Y0) - 2 * TQ_RY, bajo = porc < 32;
        // Con poco líquido su etiqueta va ENCIMA del nivel (dentro del hueco): «por consumir» usa solo lo que queda
        // arriba de ella. En el maestro las dos se encimaban entre ~10 % y 32 %.
        if (bajo) libre -= 84;
        var lleno = libre < 34;
        var regla = [0, 25, 50, 75, 100].map(function (k) { return '<i style="bottom:' + k + '%"></i><small style="bottom:' + k + '%">' + k + ' %</small>'; }).join('');
        var faltaTxt = falta !== null ? mil(falta) + ' L' : '—';
        var hueco = lleno ? '' : '<div class="cb-hueco' + (libre < 60 ? ' cb-justo' : '') + '" style="top:' + TQ_RY + 'px;height:' + libre.toFixed(1) + 'px">' +
            '<small>POR CONSUMIR DEL CUPO</small><b>' + faltaTxt + '</b></div>';
        var debajo = lleno ? '<p class="cb-debajo"><small>POR CONSUMIR DEL CUPO</small><b>' + faltaTxt + '</b></p>' : '';
        var llenoCss = bajo ? 'bottom:calc(' + porc.toFixed(2) + '% + ' + (TQ_RY + 6) + 'px)' : 'height:calc(' + porc.toFixed(2) + '% - ' + TQ_RY + 'px)';
        return '<div class="cb-patio" style="left:' + izq + 'px"><b class="cb-ptit">' + may(c.nombre) + '</b>' +
            '<div class="cb-placa"><small>CUPO MENSUAL</small><b>' + (cupo !== null ? mil(cupo) + ' L' : 'SIN CARGAR') + '</b></div>' +
            '<div class="cb-tanque">' + tanqueSvg(porc, izq) + '<div class="cb-regla">' + regla + '</div><div class="cb-casco">' + hueco +
            '<div class="cb-lleno' + (bajo ? ' cb-bajo' : '') + '" style="' + llenoCss + '"><small>VOLUMETRÍA CONSUMIDA</small><em>' + mil(desp) + ' L</em>' +
            '<b>' + (cupo ? dec(porc) + ' % del cupo' : '—') + '</b></div></div></div>' + debajo + '</div>';
    }
    function cupo(cis) {
        var cs = cis.cupos.slice(0, 2), con = cs.filter(function (c) { return c.cupo !== null; });
        var nombres = cs.map(function (c) { return may(c.nombre); }).join(' + ');
        var suma = function (k) { return con.reduce(function (s, c) { return s + (c[k] || 0); }, 0); };
        var cupoT = suma('cupo'), despT = cs.reduce(function (s, c) { return s + (c.consumido || 0); }, 0);
        var tarjeta = function (clase, titulo, valor) {
            return '<div class="cb-suma ' + clase + '"><small>' + titulo + '</small><em>' + nombres + '</em><b>' + valor + '</b></div>';
        };
        return lamina('cb', encabezado('CONSUMO DEL CUPO DE GASOIL ' + may(cis.mes)) +
            (cs[0] ? patioTanque(cs[0], 40) : '') +
            '<div class="cb-sep" style="left:440px;top:150px;height:550px"></div><div class="cb-centro">' +
            tarjeta('cb-s-cupo', 'CUPO DISPONIBLE MENSUAL', con.length ? mil(cupoT) + ' L' : 'SIN CARGAR') +
            tarjeta('cb-s-desp', 'VOLUMEN CONSUMIDO DEL CUPO', mil(despT) + ' L') +
            tarjeta('cb-s-falta', 'VOLUMEN POR CONSUMIR DEL CUPO', con.length ? mil(Math.max(cupoT - suma('consumido'), 0)) + ' L' : '—') +
            '</div><div class="cb-sep" style="left:840px;top:150px;height:550px"></div>' +
            (cs[1] ? patioTanque(cs[1], 860) : ''), 'grafico');
    }

    // ── 6. Histórico de despacho y cronograma de solicitudes de un patio (armar_historico y armar_crono) ──
    // patio (solo la página): su nombre va en el título, porque ahí no se ve el encabezado de la lámina.
    function historico(h, patio) {
        var mx = Math.max.apply(null, h.map(function (m) { return m.litros; }).concat([1]));
        return '<div class="cb-tit" style="left:0;width:1280px;text-align:center;top:146px">HISTÓRICO DE DESPACHO DIESEL (LITROS)' +
            (patio ? ' · ' + may(patio) : '') + '</div>' +
            '<div class="cb-hist" style="grid-template-columns:repeat(' + h.length + ',minmax(0,1fr))">' +
            h.map(function (m, k) {
                return '<div class="cb-mes"><em>' + (m.cisternas ? plural(m.cisternas, 'CISTERNA', 'CISTERNAS') : '&nbsp;') + '</em>' +
                    '<b>' + (m.litros > 0 ? mil(m.litros) : '—') + '</b>' +
                    '<i' + (k === h.length - 1 ? ' class="cb-hoy"' : '') + ' style="height:' + Math.round(m.litros / mx * 130) + 'px"></i>' +
                    '<small>' + may(m.mes) + '</small></div>';
            }).join('') + '</div><div class="cb-base"></div>';
    }
    // sols: las filas de ESTA lámina; c.solicitudes: todas (para las guías hacia el camión de más abajo).
    // conLitros (solo la página): la solicitud ya surtida lleva sus litros al lado del camión; en la
    // presentación esos litros están en la lámina de fotos.
    function cronograma(c, sols, arriba, cont, conLitros) {
        var dias = c.dias, sol = {}, g = '<span></span>', d;
        // sol[día] = true solo si TODAS las de ese día llegaron (con una pendiente, el día va en rojo).
        c.solicitudes.forEach(function (s) { sol[s.dia] = (sol[s.dia] !== false) && !!s.llego; });
        var letra = function (dia) { return SEM[(c.primer_dia - 1 + dia - 1) % 7]; };
        for (d = 1; d <= dias; d++) g += '<small' + (/^(S|D)$/.test(letra(d)) ? ' class="cb-fin"' : '') + '>' + letra(d) + '</small>';
        g += '<span></span>';
        for (d = 1; d <= dias; d++) g += '<b' + (d in sol ? (sol[d] ? ' class="cb-dia cb-listo"' : ' class="cb-dia"') : '') + '>' + d + '</b>';
        sols.forEach(function (s) {
            var est = s.llego ? 'cb-hecha' : 'cb-prog', despues = c.solicitudes.slice(c.solicitudes.indexOf(s) + 1);
            g += '<div class="cb-sol ' + est + '">SOLICITUD ' + (s.numero != null ? esc(s.numero) : '') + '</div>';
            for (var dd = 1; dd <= dias; dd++) {
                var cl = 'cb-v ' + est + (dd === dias ? ' cb-ult' : '');
                if (dd === s.dia) {
                    // Al final del mes la cifra va a la izquierda del camión (si no, se saldría del cronograma).
                    var lt = conLitros && s.llego && s.litros ? '<span class="gl-lt' + (dd > dias - 5 ? ' gl-lt-izq' : '') + '">' + mil(s.litros) + ' L</span>' : '';
                    g += '<div class="' + cl + ' cb-cam"><i>' + CAMION + '</i>' + lt + '</div>';
                }
                else if ((dd in sol) && despues.some(function (o) { return o.dia === dd; })) {
                    // La guía baja hacia las de más abajo ese día: verde si todas esas llegaron.
                    var listo = despues.every(function (o) { return o.dia !== dd || o.llego; });
                    g += '<div class="' + cl + ' cb-guia' + (listo ? ' cb-guia-listo' : '') + '"><i></i></div>';
                }
                else g += '<div class="' + cl + '"></div>';
            }
        });
        var titulo = 'CRONOGRAMA DE SOLICITUD DE COMBUSTIBLE ' + may(c.mes) + (cont ? ' (CONT.)' : '');
        return (arriba ? '' : '<div class="cb-sep" style="left:90px;top:402px;height:1px;width:1100px"></div>') +
            '<div class="cb-tit" style="left:0;width:1280px;text-align:center;top:' + (arriba ? 146 : 420) + 'px">' + titulo + '</div>' +
            '<div class="cb-crono" style="grid-template-columns:112px repeat(' + dias + ',minmax(0,1fr))' + (arriba ? ';top:182px' : '') + '">' + g + '</div>' +
            (sols.length ? '' : '<p class="cb-sin-sol" style="top:' + (arriba ? 260 : 520) + 'px">Sin solicitudes programadas.</p>');
    }
    // Hasta 7 solicitudes van debajo del histórico (como el maestro); las demás, en láminas del cronograma solo (16 por lámina).
    function historicoYCrono(p, pagina) {
        // La página usa el cronograma del MES (con sus litros); el PowerPoint, el del mes siguiente si ya hay solicitudes.
        var titulo = 'HISTÓRICO DE DESPACHO Y CRONOGRAMA<br>DE SOLICITUD DE GASOIL ' + may(p.nombre),
            c = pagina && p.cronograma_mes ? p.cronograma_mes : p.cronograma, todas = c.solicitudes;
        var salida = [lamina('cb', encabezado(titulo, true) + historico(p.historico, pagina && p.nombre) + cronograma(c, todas.slice(0, 7), false, false, pagina), 'grafico')];
        for (var i = 7; i < todas.length; i += 16) {
            salida.push(lamina('cb', encabezado(titulo, true) + cronograma(c, todas.slice(i, i + 16), true, true, pagina), 'grafico'));
        }
        return salida;
    }

    // Todas las láminas, en el orden de la presentación. d = ConsumiblesController::presentacionData; pagina = para
    // la página de Gráficos (los litros en el cronograma). El cupo y el histórico con cronograma salen siempre, aunque
    // aún no haya datos (los tanques dicen «SIN CARGAR», el cronograma «Sin solicitudes»). El histórico y el
    // cronograma (y las fotos del suministro) son SOLO de Patio El Tigre: Maturín no lo controla la empresa (pedido del usuario 26-11); de
    // Maturín queda su tanque en la lámina del cupo.
    function armar(d, pagina) {
        var cis = d.cisternas, l = [portada(cis.mes)];
        cis.patios.forEach(function (p) { if (p.clave === 'TIGRE' && p.recibidas.length) l = l.concat(suministro(p, cis.mes)); });
        l.push(proyectos(d));
        if (d.proyecto) l.push(unProyecto(d.proyecto));
        l.push(cupo(cis));
        cis.patios.forEach(function (p) { if (p.clave === 'TIGRE') l = l.concat(historicoYCrono(p, pagina)); });
        return l;
    }

    // ── Ajustes con la lámina ya en la página (como los scripts del maestro) ──
    function ajustar(lam) {
        // Títulos de una línea: si no caben junto al logo, se achica la letra.
        lam.querySelectorAll('.enc h2:not(.dos)').forEach(function (h) {
            var max = 1280 - 166 - 30 - 48.6, px = 30.67;
            while (h.offsetWidth > max && px > 14) { px -= 0.5; h.style.fontSize = px + 'px'; }
        });
    }
    // Espera las fotos; la de una cisterna que no carga (borrada en Drive, sin red) pasa a «Sin foto».
    function esperarFotos(raiz) {
        var sinFoto = function (img) {
            if (img.naturalWidth || !img.closest('.cb-ped')) return;
            var d = document.createElement('div');
            d.className = 'cb-sinfoto'; d.textContent = 'Sin foto';
            img.replaceWith(d);
        };
        return Promise.all(Array.prototype.map.call(raiz.querySelectorAll('img'), function (img) {
            return (img.complete ? Promise.resolve() : new Promise(function (ok) { img.onload = img.onerror = function () { ok(); }; }))
                .then(function () { sinFoto(img); });
        }));
    }

    // ── En Gráficos: solo los gráficos (sin encabezado ni portada ni fotos), cada uno escalado al ancho ──
    function pintar(cont, d) {
        var graf = armar(d, true).filter(function (l) { return l.tipo === 'grafico'; });
        cont.innerHTML = '<div class="gl gl-vista">' + graf.map(function (l) {
            return '<div class="gl-hoja">' + l.html + '</div>';
        }).join('') + '</div>';
        var hojas = cont.querySelectorAll('.gl-hoja');
        hojas.forEach(function (h) { ajustar(h.firstChild); });
        var escalar = function () { hojas.forEach(function (h) { h.style.setProperty('--k', h.clientWidth / 1280); }); };
        escalar();
        if (window.ResizeObserver) { if (cont._obsGl) cont._obsGl.disconnect(); cont._obsGl = new ResizeObserver(escalar); cont._obsGl.observe(cont); }
        return graf.length;
    }

    // ══ Convertidor a PowerPoint: COPIA del «Descargar PowerPoint» del maestro (14-10, todo editable) ══
    // Cada cosa de la lámina sale como su propio objeto: cajas y barras -> formas; fotos -> imágenes recortadas igual;
    // tanques, ola, iconos -> imagen SVG (en PowerPoint: clic derecho > «Convertir en forma»); fondos con degradado ->
    // imagen de esa caja; textos -> cuadros de texto con la misma letra, tamaño, color y cortes de línea.
    var PX_PT = 0.75, PX_IN = 1 / 96;
    function hexDe(color) {
        return color.match(/[\d.]+/g).slice(0, 3).map(function (n) { return ('0' + Math.round(+n).toString(16)).slice(-2); }).join('').toUpperCase();
    }
    function alfaDe(color) { var m = color.match(/[\d.]+/g); return m && m.length > 3 ? +m[3] : 1; }
    function visible(color) { return color && color !== 'transparent' && alfaDe(color) > 0.01; }
    function opacidad(n, lam) { var o = 1; for (; n && n !== lam.parentNode; n = n.parentNode) if (n.nodeType === 1) o *= +getComputedStyle(n).opacity; return o; }
    function transparencia(color, op) { var a = alfaDe(color) * op; return a < 0.99 ? Math.round((1 - a) * 100) : 0; }
    function sombraDe(cs) {
        var m = cs.boxShadow.match(/(rgba?\([^)]+\))\s+([\d.-]+)px\s+([\d.-]+)px\s+([\d.]+)px/);
        if (!m || cs.boxShadow.indexOf('inset') >= 0) return null;
        var dx = +m[2], dy = +m[3];
        return { type: 'outer', color: hexDe(m[1]), opacity: Math.min(1, alfaDe(m[1]) * 1.6), blur: Math.round(+m[4] * PX_PT),
            offset: Math.round(Math.hypot(dx, dy) * PX_PT * 10) / 10, angle: Math.round((Math.atan2(dy, dx) * 180 / Math.PI + 360) % 360) };
    }
    function letraDe(cs) {
        var primera = (cs.fontFamily || '').split(',')[0].replace(/["']/g, '').trim().toLowerCase();
        return primera === 'arial black' ? 'Arial Black' : primera === 'calibri' ? 'Calibri' : 'Arial';
    }
    function letra(n, lam) {
        var cs = getComputedStyle(n), cara = letraDe(cs);
        var o = { fontFace: cara, fontSize: Math.round(parseFloat(cs.fontSize) * PX_PT * 10) / 10,
            bold: cara !== 'Arial Black' && parseInt(cs.fontWeight, 10) >= 600, color: hexDe(cs.color) };
        var alfa = opacidad(n, lam) * alfaDe(cs.color);
        if (alfa < 0.99) o.transparency = Math.round((1 - alfa) * 100);
        var espacio = parseFloat(cs.letterSpacing);
        if (espacio) o.charSpacing = Math.round(espacio * PX_PT * 10) / 10;
        return o;
    }
    function trozos(el, lam, sinCortes) {
        var salida = [], lineaFondo = null;
        function saltar() { if (salida.length) salida[salida.length - 1].options.breakLine = true; }
        (function recorrer(nodo) {
            var mayus = getComputedStyle(nodo).textTransform === 'uppercase';
            nodo.childNodes.forEach(function (h) {
                if (h.nodeType === 1) {
                    if (h.tagName === 'BR') { saltar(); lineaFondo = null; return; }
                    if (h.tagName.toLowerCase() === 'svg') return;
                    if (parseFloat(getComputedStyle(h).marginLeft) > 0 && salida.length) salida[salida.length - 1].text += ' ';
                    recorrer(h);
                    return;
                }
                if (h.nodeType !== 3) return;
                var texto = h.textContent, re = /[^\s ]+/g, m, rango = document.createRange();
                while ((m = re.exec(texto))) {
                    rango.setStart(h, m.index); rango.setEnd(h, m.index + m[0].length);
                    var caja = rango.getBoundingClientRect();
                    if (!sinCortes && lineaFondo !== null && (caja.top + caja.bottom) / 2 > lineaFondo) { saltar(); lineaFondo = null; }
                    else if (salida.length && !salida[salida.length - 1].options.breakLine &&
                        /[\s ]/.test(texto.charAt(m.index - 1) || (m.index === 0 ? ' ' : ''))) salida[salida.length - 1].text += ' ';
                    lineaFondo = Math.max(lineaFondo === null ? -Infinity : lineaFondo, caja.bottom);
                    var trozo = { text: mayus ? m[0].toUpperCase() : m[0], options: letra(nodo, lam) };
                    var previo = salida[salida.length - 1];
                    if (previo && !previo.options.breakLine && JSON.stringify(previo.options) === JSON.stringify(trozo.options)) previo.text += trozo.text;
                    else salida.push(trozo);
                }
            });
        })(el);
        return salida;
    }
    function efectosDe(cs) {
        var o = {}, sombra = cs.textShadow.match(/(rgba?\([^)]+\))\s+([\d.-]+)px\s+([\d.-]+)px\s+([\d.]+)px/);
        if (sombra) {
            var dx = +sombra[2], dy = +sombra[3];
            o.shadow = { type: 'outer', color: hexDe(sombra[1]), opacity: alfaDe(sombra[1]), blur: Math.round(+sombra[4] * PX_PT),
                offset: Math.round(Math.hypot(dx, dy) * PX_PT * 10) / 10, angle: Math.round((Math.atan2(dy, dx) * 180 / Math.PI + 360) % 360) };
        }
        var grosor = parseFloat(cs.webkitTextStrokeWidth);
        if (grosor) o.outline = { size: grosor * PX_PT, color: hexDe(cs.webkitTextStrokeColor) };
        return o;
    }
    function tieneTexto(el) {
        return Array.prototype.some.call(el.childNodes, function (h) { return h.nodeType === 3 && h.textContent.replace(/[\s ]/g, ''); });
    }
    function textosDe(lam, caja, s) {
        var lista = [];
        lam.querySelectorAll('*').forEach(function (el) {
            if (el.closest('svg') || !tieneTexto(el) || !el.getClientRects().length) return;
            for (var p = el.parentElement; p && p !== lam; p = p.parentElement) if (tieneTexto(p)) return;
            var cs = getComputedStyle(el);
            if (cs.visibility === 'hidden' || +cs.opacity === 0) return;
            var nodos = [], rec = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, { acceptNode: function (n) {
                return n.parentNode.closest('svg') || !n.textContent.replace(/[\s ]/g, '') ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT; } });
            while (rec.nextNode()) nodos.push(rec.currentNode);
            var rango = document.createRange();
            rango.setStart(nodos[0], 0); rango.setEnd(nodos[nodos.length - 1], nodos[nodos.length - 1].length);
            var r = rango.getBoundingClientRect();
            var x = (r.left - caja.left) / s, y = (r.top - caja.top) / s, w = r.width / s, h = r.height / s;
            var alinea = cs.textAlign === 'center' ? 'center' : (cs.textAlign === 'right' || cs.textAlign === 'end') ? 'right' : 'left';
            var holgura = Math.max(24, w * 0.15);
            if (alinea === 'center') x -= holgura / 2; else if (alinea === 'right') x -= holgura;
            w += holgura;
            if (x < 0) { w += x; x = 0; }
            if (x + w > 1280) w = 1280 - x;
            var opciones = { x: x * PX_IN, y: y * PX_IN, w: w * PX_IN, h: h * PX_IN, align: alinea, valign: 'middle',
                margin: 0, wrap: false, fit: 'none', objectName: 'Texto' };
            var interlineado = parseFloat(cs.lineHeight);
            if (interlineado) opciones.lineSpacing = Math.round(interlineado * PX_PT * 10) / 10;
            Object.assign(opciones, efectosDe(cs));
            var partes = trozos(el, lam, false);
            // En PowerPoint la letra es Arial/Arial Black/Calibri: si la página usó otra más angosta, se achica igual que en el maestro.
            if (!/^\s*["']?(arial|arial black|calibri)["']?\s*(,|$)/i.test(cs.fontFamily)) {
                var antes = el.style.getPropertyValue('font-family'), prioridad = el.style.getPropertyPriority('font-family');
                el.style.setProperty('font-family', 'Arial', 'important');
                var ancho = rango.getBoundingClientRect().width;
                el.style.setProperty('font-family', antes, prioridad);
                var factor = r.width / ancho;
                if (ancho && factor < 0.995) partes.forEach(function (t) { t.options.fontSize = Math.floor(t.options.fontSize * factor * 10) / 10; });
            }
            lista.push({ trozos: partes, opciones: opciones });
        });
        return lista;
    }
    function rectDe(r, caja, s) { return { x: (r.left - caja.left) / s, y: (r.top - caja.top) / s, w: r.width / s, h: r.height / s }; }
    function pulg(b) { return { x: b.x * PX_IN, y: b.y * PX_IN, w: b.w * PX_IN, h: b.h * PX_IN }; }
    function formaDe(cs, b, op, nombre) {
        var radio = parseFloat(cs.borderTopLeftRadius) || 0;
        var circulo = /%/.test(cs.borderTopLeftRadius) ? parseFloat(cs.borderTopLeftRadius) >= 50 : radio >= Math.min(b.w, b.h) / 2 - 0.5;
        var o = Object.assign(pulg(b), { objectName: nombre }), forma = 'rect';
        if (radio > 0.5) {
            if (circulo && Math.abs(b.w - b.h) < 1) forma = 'ellipse';
            else { forma = 'roundRect'; o.rectRadius = Math.min(radio, Math.min(b.w, b.h) / 2) * PX_IN; }
        }
        o.fill = visible(cs.backgroundColor) ? { color: hexDe(cs.backgroundColor), transparency: transparencia(cs.backgroundColor, op) } : { type: 'none' };
        var bw = [cs.borderTopWidth, cs.borderRightWidth, cs.borderBottomWidth, cs.borderLeftWidth].map(parseFloat);
        var bc = [cs.borderTopColor, cs.borderRightColor, cs.borderBottomColor, cs.borderLeftColor];
        var bs = [cs.borderTopStyle, cs.borderRightStyle, cs.borderBottomStyle, cs.borderLeftStyle];
        var clave = function (i) { return bw[i] > 0 && visible(bc[i]) && bs[i] !== 'none' ? bw[i] + '|' + bc[i] : ''; };
        var cuenta = {}, comun = '';
        [0, 1, 2, 3].forEach(function (i) { var k = clave(i); if (k) cuenta[k] = (cuenta[k] || 0) + 1; });
        Object.keys(cuenta).forEach(function (k) { if (cuenta[k] >= 2 && (!comun || cuenta[k] > cuenta[comun])) comun = k; });
        var extra = [];
        if (comun) {
            var lados = [0, 1, 2, 3].filter(function (i) { return clave(i) === comun; }), lw = bw[lados[0]], lc = comun.split('|')[1];
            o.line = { color: hexDe(lc), width: lw * PX_PT, transparency: transparencia(lc, op) };
            if (bs[lados[0]] === 'dashed') o.line.dashType = 'dash';
            Object.assign(o, pulg({ x: b.x + lw / 2, y: b.y + lw / 2, w: b.w - lw, h: b.h - lw }));
            if (o.rectRadius) o.rectRadius = Math.max(radio - lw / 2, 0) * PX_IN;
        }
        [[0, 0, b.w, bw[0]], [b.w - bw[1], 0, bw[1], b.h], [0, b.h - bw[2], b.w, bw[2]], [0, 0, bw[3], b.h]].forEach(function (d, i) {
            if (!clave(i) || clave(i) === comun) return;
            var f = { forma: radio > 0.5 ? 'roundRect' : 'rect', o: Object.assign(pulg({ x: b.x + d[0], y: b.y + d[1], w: d[2], h: d[3] }),
                { fill: { color: hexDe(bc[i]), transparency: transparencia(bc[i], op) }, objectName: nombre + ' (borde)' }) };
            if (radio > 0.5) f.o.rectRadius = Math.min(d[2], d[3]) / 2 * PX_IN;
            extra.push(f);
        });
        var sombra = sombraDe(cs);
        if (sombra) o.shadow = sombra;
        return (o.fill.type !== 'none' || o.line || sombra ? [{ forma: forma, o: o }] : []).concat(extra);
    }
    function pseudoDe(el, cual, bEl, op, nombre) {
        var cs = getComputedStyle(el, cual);
        if (!cs.content || cs.content === 'none' || cs.content === 'normal' || cs.display === 'none' || cs.position !== 'absolute') return [];
        var L = parseFloat(cs.left), R = parseFloat(cs.right), T = parseFloat(cs.top), B = parseFloat(cs.bottom);
        var W = parseFloat(cs.width), H = parseFloat(cs.height);
        var x = !isNaN(L) ? L : bEl.w - R - W, y = !isNaN(T) ? T : bEl.h - B - H;
        var w = W > 0 ? W : bEl.w - (L || 0) - (R || 0), h = H > 0 ? H : bEl.h - (T || 0) - (B || 0);
        if (!(w > 0 && h > 0)) return [];
        return formaDe(cs, { x: bEl.x + x, y: bEl.y + y, w: w, h: h }, op, nombre);
    }
    function fotoDe(img, b) {
        var cs = getComputedStyle(img), k = 1.6, cv = document.createElement('canvas');
        cv.width = Math.round(b.w * k); cv.height = Math.round(b.h * k);
        var c = cv.getContext('2d'), radio = (parseFloat(cs.borderTopLeftRadius) || 0) * k;
        if (radio > 0) { c.beginPath(); c.roundRect(0, 0, cv.width, cv.height, radio); c.clip(); }
        var nw = img.naturalWidth, nh = img.naturalHeight, ajuste = cs.objectFit;
        var escala = ajuste === 'cover' ? Math.max(cv.width / nw, cv.height / nh) : ajuste === 'contain' ? Math.min(cv.width / nw, cv.height / nh) : 0;
        if (escala) {
            var pos = cs.objectPosition.split(' ').map(parseFloat), dw = nw * escala, dh = nh * escala;
            c.drawImage(img, 0, 0, nw, nh, (cv.width - dw) * pos[0] / 100, (cv.height - dh) * pos[1] / 100, dw, dh);
        } else c.drawImage(img, 0, 0, nw, nh, 0, 0, cv.width, cv.height);
        return cv.toDataURL(radio > 0 || /\.png/i.test(img.src) ? 'image/png' : 'image/jpeg', 0.9);
    }
    function svgDe(svg, b) {
        var copia = svg.cloneNode(true), orig = svg.querySelectorAll('*'), dest = copia.querySelectorAll('*');
        [svg].concat(Array.prototype.slice.call(orig)).forEach(function (n, i) {
            var d = i ? dest[i - 1] : copia, cs = getComputedStyle(n);
            ['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-dasharray', 'opacity', 'fill-opacity',
                'stroke-opacity', 'font-family', 'font-size', 'font-weight', 'letter-spacing'].forEach(function (p) {
                var v = cs.getPropertyValue(p);
                if (v && v !== 'normal' && !(p === 'stroke-dasharray' && v === 'none')) d.setAttribute(p, v);
            });
        });
        copia.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        copia.setAttribute('width', Math.round(b.w * 2)); copia.setAttribute('height', Math.round(b.h * 2));
        copia.removeAttribute('style'); copia.removeAttribute('class');
        return 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(new XMLSerializer().serializeToString(copia))));
    }
    function fondoCajaDe(el) {
        return window.htmlToImage.toPng(el, {
            pixelRatio: 2, skipFonts: true,
            filter: function (n) { return n === el || !(n.parentNode && el.contains(n)); },
            style: { position: 'relative', left: '0', top: '0', right: 'auto', bottom: 'auto', margin: '0', transform: 'none', boxShadow: 'none' }
        });
    }
    function objetosDe(lam, caja, s) {
        var lista = [], pend = [];
        lam.querySelectorAll('*').forEach(function (el) {
            if (el.closest('svg') && el.tagName.toLowerCase() !== 'svg') return;
            if (!el.getClientRects().length) return;
            var cs = getComputedStyle(el);
            if (cs.visibility === 'hidden' || cs.display === 'none') return;
            var op = opacidad(el, lam);
            if (op < 0.01) return;
            var b = rectDe(el.getBoundingClientRect(), caja, s);
            if (b.w < 0.5 || b.h < 0.5) return;
            var tag = el.tagName.toLowerCase(), nombre = (el.getAttribute('class') || tag).split(' ')[0];
            if (tag === 'svg') { lista.push({ imagen: svgDe(el, b), o: Object.assign(pulg(b), { objectName: 'Dibujo ' + nombre }) }); return; }
            if (tag === 'img') {
                if (!el.complete || !el.naturalWidth) return;
                var oi = Object.assign(pulg(b), { objectName: 'Foto ' + (el.alt || nombre) });
                var sm = sombraDe(cs); if (sm) oi.shadow = sm;
                if (op < 0.99) oi.transparency = Math.round((1 - op) * 100);
                lista.push({ imagen: fotoDe(el, b), o: oi });
                return;
            }
            if (cs.backgroundImage && cs.backgroundImage !== 'none') {
                var hueco = { imagen: null, o: Object.assign(pulg(b), { objectName: 'Fondo ' + nombre }) };
                var sm2 = sombraDe(cs); if (sm2) hueco.o.shadow = sm2;
                lista.push(hueco);
                pend.push(fondoCajaDe(el).then(function (png) { hueco.imagen = png; }));
            } else {
                formaDe(cs, b, op, nombre).forEach(function (f) { lista.push(f); });
            }
            pseudoDe(el, '::before', b, op, nombre + ' ::before').forEach(function (f) { lista.push(f); });
            pseudoDe(el, '::after', b, op, nombre + ' ::after').forEach(function (f) { lista.push(f); });
        });
        return Promise.all(pend).then(function () { return lista.filter(function (x) { return x.forma || x.imagen; }); });
    }
    function convertir(laminas, nombreArchivo) {
        var pptx = new window.PptxGenJS();
        pptx.layout = 'LAYOUT_WIDE';
        pptx.theme = { headFontFace: 'Arial', bodyFontFace: 'Arial' };
        pptx.title = 'Gestión, control y suministro de combustible';
        return laminas.reduce(function (cadena, lam) {
            return cadena.then(function () {
                var caja = lam.getBoundingClientRect(), s = caja.width / 1280, textos = textosDe(lam, caja, s);
                return objetosDe(lam, caja, s).then(function (objetos) {
                    var diapo = pptx.addSlide(), fondo = getComputedStyle(lam).backgroundColor;
                    diapo.background = { color: visible(fondo) ? hexDe(fondo) : 'FFFFFF' };
                    objetos.forEach(function (x) {
                        if (x.imagen) diapo.addImage(Object.assign({ data: /svg/.test(x.imagen.slice(0, 20)) ? x.imagen : x.imagen.replace(/^data:/, '') }, x.o));
                        else diapo.addShape(x.forma, x.o);
                    });
                    textos.forEach(function (t) { diapo.addText(t.trozos, t.opciones); });
                });
            });
        }, Promise.resolve()).then(function () { return pptx.writeFile({ fileName: nombreArchivo }); });
    }
    function cargar(url, listo) {
        return window.cargarScriptUnaVez(url, listo).catch(function () { throw new Error('No se pudo cargar el generador de PowerPoint.'); });
    }
    // Todas las láminas (portada y encabezados incluidos) en una capa fuera de la vista, a tamaño real, y de ahí al PowerPoint.
    function bajar(d, nombreArchivo) {
        return Promise.all([cargar(LIB_PPTX, function () { return !!window.PptxGenJS; }), cargar(LIB_IMG, function () { return !!window.htmlToImage; })])
            .then(function () {
                var capa = document.createElement('div');
                capa.className = 'gl gl-armando';
                capa.innerHTML = armar(d).map(function (l) { return l.html; }).join('');
                document.body.appendChild(capa);
                var lams = Array.prototype.slice.call(capa.querySelectorAll('.lamina'));
                return esperarFotos(capa)
                    .then(function () { return document.fonts && document.fonts.ready; })
                    .then(function () { lams.forEach(ajustar); return convertir(lams, nombreArchivo); })
                    .then(function (r) { capa.remove(); return r; }, function (e) { capa.remove(); throw e; });
            });
    }

    window.GasoilLaminas = { pintar: pintar, bajar: bajar };
})();
