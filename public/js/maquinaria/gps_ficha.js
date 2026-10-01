/**
 * window.GpsFicha — la ficha de un equipo con GPS, en UN solo sitio.
 *
 * La pintan DOS pantallas y tienen que verse idénticas:
 *   · la ficha que sale al tocar un equipo en /mapa (capa Equipos), y
 *   · el modal "Rastreo Satelital en Vivo" del detalle de equipos.
 *
 * Antes cada una la armaba por su cuenta y no se parecían en nada: distinta maqueta, distintos
 * rótulos ("Ubic.", "F. act."), el motor y el voltaje juntos en un renglón, sin kilometraje, y
 * las coordenadas al revés. El cliente pidió que la del modal se viera como la del mapa, así
 * que la del mapa se extrajo aquí tal cual y ahora las dos llaman a lo mismo.
 *
 * El HTML usa las clases .mapa-eq* de estilos_globales.css, que es global: sirven igual dentro
 * del modal. La hora y el "hace" salen de dom_helpers.js (window.fechaHoraLocal / tiempoHace),
 * que ya eran compartidos por el mismo motivo.
 *
 * Se carga GLOBAL en estructura_base (como fuzzy_search.js o qr_scan.js): la SPA omite los
 * <script src> que van dentro de @section('content'), así que por vista no sobreviviría a la
 * navegación.
 *
 * SPA-safe: sin estado, idempotente.
 */
(function () {
    'use strict';

    var esc = function (s) { return window.escapeHtml(s == null ? '' : s); };
    var num = function (n, dec) { return Number(n).toLocaleString('es-VE', { maximumFractionDigits: dec || 0 }); };
    var hay = function (v) { return v !== null && v !== undefined; };

    // "1d11h42m" (lo que manda GPS51 tras "ACC Off") → "1 día 11 h". Con días no van los
    // minutos, y los segundos solo cuando no hay nada más grande: "50m45s" se lee "50 min" y
    // "45s" → "segundos". Corto a propósito: va en una celda angosta. Un formato que no se
    // entiende se devuelve tal cual, mejor que perder el dato.
    function duracion(t) {
        var txt = String(t || '');
        var m = txt.toLowerCase().match(/^(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/);
        if (!m || !m[0]) return txt;
        var d = +m[1] || 0, h = +m[2] || 0, mi = +m[3] || 0;
        var partes = [];
        if (d) partes.push(d + (d === 1 ? ' día' : ' días'));
        if (h) partes.push(h + ' h');
        if (mi && !d) partes.push(mi + ' min');
        return partes.length ? partes.join(' ') : 'segundos';
    }

    // La dirección de GPS51 para LEER: sin "Parroquia " / "Municipio ", sin el ", Venezuela" del
    // final y sin trozos repetidos ("Anaco, Parroquia Anaco, Municipio Anaco" → "Anaco"), que en
    // la ficha no dicen nada y la alargaban a tres renglones. La entera va al title. Si de la
    // dirección no queda nada (GPS51 a veces solo dice "Venezuela"), se deja como venía.
    function direccionCorta(t) {
        var vistos = Object.create(null);   // sin prototipo: un trozo "constructor" no se pierde
        var corta = String(t || '').split(',').map(function (p) {
            return p.replace(/^\s*(Parroquia|Municipio)\s+/i, '').trim();
        }).filter(function (p) {
            var k = p.toLowerCase();
            if (!p || k === 'venezuela' || vistos[k]) return false;
            vistos[k] = true;
            return true;
        }).join(', ');
        return corta || String(t || '');
    }

    function celda(rotulo, valor, detalle) {
        return '<div class="mapa-eq-cel"><span>' + esc(rotulo) + '</span>' +
               '<b title="' + esc(valor) + '">' + esc(valor) + '</b>' +
               (detalle ? '<small title="' + esc(detalle) + '">' + esc(detalle) + '</small>' : '') + '</div>';
    }

    window.GpsFicha = {
        /**
         * El HTML de la ficha, o cadena vacía si el equipo no tiene posición que pintar
         * (sin `gps`, con `gps.ok` falso o sin coordenadas).
         *
         * @param {object} eq  El equipo: { tipo, modelo, marca, ident, identPor, frente, color,
         *                     gps }. `ident` es CÓMO se llama (placa; si no, serial de chasis,
         *                     de motor, código o etiqueta) e `identPor` su rótulo ("Placa",
         *                     "Serial"…, vacío para "Equipo N"). Los dos llegan resueltos
         *                     del servidor (MapaController::identificar).
         *                     `gps` es lo que devuelve Gps51Service.
         * @param {object} op  Opcional:
         *                     · dudosa   true si el GPS la reporta fuera del país: pinta el
         *                                aviso de que ese punto no es donde está el equipo.
         *                     · direccion  la dirección escrita, o null mientras se busca.
         *                     · dirAttr  valor del data-eqdir: por ahí la rellenan los dos
         *                                cuando llega, sin repintar la ficha entera.
         *                     · sinDireccion  true para no reservarle sitio. Lo usa el modal
         *                                     cuando la posición es dudosa: la dirección de una
         *                                     coordenada de otro país no se llega a pedir, y el
         *                                     hueco se quedaría en "Buscando dirección…".
         */
        html: function (eq, op) {
            op = op || {};
            var g = eq.gps;
            // Sin posición no hay ficha que pintar: quien llama decide qué poner en su lugar (el
            // mapa no lista al equipo, el modal enseña el motivo). Se comprueba aquí para que un
            // tercer sitio que la use no se lleve un TypeError en g.lat.
            if (!g || !g.ok || g.lat == null || g.lng == null) return '';
            var comb = g.combustible || {};
            // El color va DENTRO de un atributo style, donde escapeHtml no protege: se acepta solo
            // si es un color de los que arma el sistema (#rgb / #rrggbb). Cualquier otra cosa, al gris.
            var color = /^#[0-9a-fA-F]{3,8}$/.test(String(eq.color || '')) ? eq.color : '#94a3b8';
            // Encabezado en el orden que pidió el cliente (30-09-2026): primero QUÉ es (tipo,
            // modelo y marca) y después CUÁL es (la placa y, si no tiene, el serial de chasis).
            // Por eso ya no va aparte la línea "Placa: … Serial chasis: …": repetía lo de arriba.
            var limpio = function (v) { return (v == null ? '' : String(v)).trim(); };
            var ident = limpio(eq.ident), identPor = limpio(eq.identPor);
            var rotulo = identPor ? identPor + ': ' : '';
            // Segundo renglón del encabezado, todo junto: "ZZ1168K621NC1 · SINOTRUK · Placa: A51EX9P"
            // (pedido del cliente, 01-10-2026: el encabezado en dos renglones, no en cuatro). El
            // title sale de los MISMOS trozos que lo que se ve.
            var modeloMarca = [limpio(eq.modelo), limpio(eq.marca)].filter(Boolean).join(' · ');
            var segundaTxt = [modeloMarca, ident ? rotulo + ident : ''].filter(Boolean).join(' · ');
            // "· Placa: X" va en un trozo que no se parte: si no cabe baja entero de renglón, sin
            // dejar el "·" colgando ni "Placa:" separada de su valor.
            var segunda = esc(modeloMarca) + (ident ? (modeloMarca ? ' ' : '') + '<span class="mapa-eq-cual">' +
                (modeloMarca ? '· ' : '') + esc(rotulo) + '<b>' + esc(ident) + '</b></span>' : '');
            var tipo = limpio(eq.tipo) || 'Sin tipo';
            var frente = limpio(eq.frente) || 'Sin frente';
            // "desde hace" es lo que GPS51 dijo AL CONSULTAR: con la última posición conocida
            // (`vieja`, de hasta un día) ya no es verdad, así que no se pone.
            var motorDesde = g.vieja ? '' : duracion(g.acc_tiempo);

            return '<div class="mapa-eq">' +
                // Lo primero: esta posición no es la real. El equipo se pinta igual (el cliente
                // quiere ver lo que el GPS dice), pero nadie debe salir a buscarlo ahí.
                (op.dudosa ? '<div class="mapa-eq-dudosa"><i class="material-icons">location_off</i>' +
                    '<span>El GPS la reporta FUERA de Venezuela: no es donde está el equipo. Hay que revisar ese GPS.</span></div>' : '') +
                '<div class="mapa-eq-head" style="border-left-color:' + color + '">' +
                    '<div class="mapa-eq-tit"><b title="' + esc(tipo) + '">' + esc(tipo) + '</b>' +
                        '<span class="mapa-eq-estado ' + (g.en_linea ? 'en-linea' : 'fuera') + '">' +
                        (g.en_linea ? 'En línea' : 'Sin conexión') + '</span></div>' +
                    (segunda ? '<div class="mapa-eq-desc" title="' + esc(segundaTxt) + '">' + segunda + '</div>' : '') +
                    '<div class="mapa-eq-frente"><i style="background:' + color + '"></i>' +
                        '<span title="' + esc(frente) + '">' + esc(frente) + '</span></div>' +
                '</div>' +
                '<div class="mapa-eq-grid">' +
                    celda('Velocidad', num(g.velocidad) + ' km/h') +
                    // "Apagado / desde hace 1 día 11 h": antes salía "1d11h42m" suelto, sin decir qué era.
                    celda('Motor', hay(g.acc) ? (g.acc ? 'Encendido' : 'Apagado') : '—',
                          hay(g.acc) && motorDesde ? 'desde hace ' + motorDesde : '') +
                    // El reparto por tanques solo si HAY auxiliar, y cada tanque con su propio
                    // dato: los dos vienen sueltos de GPS51 y uno puede faltar. Poniendo 0 donde
                    // no hay medida se leería como un tanque vacío.
                    celda('Combustible', hay(comb.total) ? num(comb.total) + ' L' : '—',
                          hay(comb.auxiliar)
                              ? 'P ' + (hay(comb.principal) ? num(comb.principal) : '—') + ' · A ' + num(comb.auxiliar)
                              : '') +
                    celda('Voltaje', hay(g.voltaje) ? num(g.voltaje, 1) + ' V' : '—') +
                    celda('Kilometraje', num(g.km_total) + ' km') +
                    celda('Última señal', window.tiempoHace(g.ultima_senal), window.fechaHoraLocal(g.ultima_senal)) +
                '</div>' +
                // Dónde está, en dos renglones como mucho: la dirección corta y, debajo, la coordenada.
                '<div class="mapa-eq-ubic"><i class="material-icons">place</i><div>' +
                    (op.sinDireccion ? '' :
                        '<div class="mapa-eq-dir"' + (op.dirAttr ? ' data-eqdir="' + esc(op.dirAttr) + '"' : '') +
                        (op.direccion ? ' title="' + esc(op.direccion) + '"' : '') + '>' +
                        esc(op.direccion ? direccionCorta(op.direccion) : 'Buscando dirección…') + '</div>') +
                    '<div class="mapa-eq-coord">' + g.lat.toFixed(6) + ', ' + g.lng.toFixed(6) + '</div>' +
                '</div></div>' +
            '</div>';
        },

        /**
         * Escribe la dirección cuando llega (va aparte porque tarda ~2 s) en el hueco que dejó
         * html() (data-eqdir), sin repintar la ficha. En pantalla se corta a dos renglones, así
         * que la entera queda en el title. Lo usan el mapa y el modal.
         */
        ponerDireccion: function (nodo, texto) {
            if (!nodo) return;
            nodo.textContent = direccionCorta(texto);
            nodo.title = texto;
        }
    };
})();
