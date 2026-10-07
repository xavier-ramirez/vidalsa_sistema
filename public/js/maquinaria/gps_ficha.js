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
    var limpio = function (v) { return (v == null ? '' : String(v)).trim(); };   // trim() quita también el espacio duro

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

    // El rótulo arriba y, en UN renglón debajo, el valor con su detalle al lado ("hace 1 min
    // 6/10/2026 20:15"): pedido del cliente, 06-10-2026; antes el detalle iba en otro renglón y la
    // celda llegaba a cuatro. `detalle` es un texto o una lista (un trozo por tanque); el que no
    // cabe al lado baja entero (ver .mapa-eq-val). `ancha`: ocupa toda la fila de la rejilla.
    function celda(rotulo, valor, detalle, ancha) {
        var trozos = [].concat(detalle || []).filter(Boolean);
        return '<div class="mapa-eq-cel' + (ancha ? ' ancha' : '') + '"><span>' + esc(rotulo) + '</span><div class="mapa-eq-val">' +
               '<b title="' + esc(valor) + '">' + esc(valor) + '</b>' +
               trozos.map(function (t) { return '<small title="' + esc(t) + '">' + esc(t) + '</small>'; }).join('') +
               '</div></div>';
    }

    // En marcha = más de 3 km/h, y solo con la posición al día: con la última conocida (`vieja`,
    // de hasta un día) la flecha diría que va en marcha AHORA.
    function enMarcha(g) { return !g.vieja && g.velocidad > 3; }

    // Cómo se reconoce el equipo, en este orden y uno al lado del otro (pedido del cliente,
    // 06-10-2026): "Placa: X" SIEMPRE, con "Sin placa" si no tiene —así se ve que falta—, y
    // "Chasis: X", que en campo es lo que se coteja contra la chapa. Si identificar() tuvo que
    // bajar más (sin placa ni chasis: serial de motor, código o etiqueta), ese va detrás con su
    // rótulo. Una sola regla para la ficha (mapa y modal) y la lista del panel de /mapa.
    // eq: { ident, identPor, chasis } tal como llegan del servidor (MapaController).
    function identificadores(eq) {
        var ident = limpio(eq.ident), por = limpio(eq.identPor);
        var chasis = limpio(eq.chasis) || (por === 'Chasis' ? ident : '');
        return [
            'Placa: ' + (por === 'Placa' ? ident : 'Sin placa'),
            chasis ? 'Chasis: ' + chasis : '',
            por && por !== 'Placa' && por !== 'Chasis' ? por + ': ' + ident : ''   // "Equipo N" (sin rótulo) no
        ].filter(Boolean);
    }

    window.GpsFicha = {
        /** Teselas del satélite de Esri: el mapa base de /mapa. */
        SATELITE: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
        /** Nombres de calles, carreteras y ciudades de Google (lyrs=h, fondo transparente), encima
            del satélite de /mapa: los mismos de Google Maps. */
        ETIQUETAS: 'https://{s}.google.com/vt/lyrs=h&x={x}&y={y}&z={z}',
        /** Satélite de Google CON los nombres ya puestos (lyrs=y): el mapa del modal de GPS de
            Equipos. En vez del de Esri porque el de Esri trae nubes en zonas de trabajo (p. ej.
            vía a Rincón de Monagas, Maturín; pedido del cliente, 01-10-2026). */
        HIBRIDO: 'https://{s}.google.com/vt/lyrs=y&x={x}&y={y}&z={z}',
        /** Subdominios de las teselas de Google ({s} de ETIQUETAS e HIBRIDO). */
        SUBDOMINIOS_GOOGLE: ['mt0', 'mt1', 'mt2', 'mt3'],

        /**
         * El icono del equipo en el mapa (Leaflet divIcon): el de "agriculture" del módulo
         * Equipos, más apagado si no está en línea y con una flecha del rumbo cuando va en
         * marcha. El mismo en /mapa y en el modal. Estilos .mapa-eq-pin en estilos_globales.css.
         */
        icono: function (g) {
            return L.divIcon({
                className: 'mapa-eq-pin' + (g.en_linea ? '' : ' fuera'),
                html: '<i class="material-icons mapa-eq-ico">agriculture</i>' +
                      (enMarcha(g) ? '<span class="mapa-eq-rumbo" style="transform:rotate(' + (+g.rumbo || 0) + 'deg)"></span>' : ''),
                iconSize: [26, 26], iconAnchor: [13, 13], popupAnchor: [0, -12]
            });
        },
        /** Lo que decide cómo se ve el icono: si no cambia, no hace falta rehacerlo. */
        iconoFirma: function (g) {
            return (g.en_linea ? 1 : 0) + '|' + (enMarcha(g) ? (+g.rumbo || 0) : '-');
        },

        /** ["Placa: …", "Chasis: …"…] para enseñar uno al lado del otro (ver identificadores).
            Lo usa también la lista del panel de /mapa. */
        identificadores: identificadores,

        /**
         * El HTML de la ficha, o cadena vacía si el equipo no tiene posición que pintar
         * (sin `gps`, con `gps.ok` falso o sin coordenadas).
         *
         * @param {object} eq  El equipo: { tipo, modelo, marca, ident, identPor, chasis, frente,
         *                     color, gps }. `ident` es CÓMO se llama (placa; si no, serial de chasis,
         *                     de motor, código o etiqueta) e `identPor` su rótulo ("Placa",
         *                     "Chasis"…, vacío para "Equipo N"). Los dos llegan resueltos
         *                     del servidor (MapaController::identificar). `chasis` es el
         *                     serial de chasis, que va en su propio renglón aunque el
         *                     equipo se identifique por la placa.
         *                     `gps` es lo que devuelve Gps51Service.
         * @param {object} op  Opcional:
         *                     · dudosa   true si el GPS la reporta fuera del país: pinta el
         *                                aviso de que ese punto no es donde está el equipo.
         *                     · sinRespuesta  true si GPS51 no contestó al refrescar una posición
         *                                `vieja`: el aviso lo dice en vez de "actualizando…".
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
            // Encabezado en tres renglones, todo en negro (pedido del cliente, 01-10-2026):
            //   1. el frente (proyecto), separado de lo de abajo por una raya fina; sin el rótulo
            //      "Asignado a" delante (pedido del cliente, 06-10-2026);
            //   2. QUÉ es: el tipo y, al lado, la marca y el modelo ("CHUTO  SINOTRUK  ZZ4257V324JB1");
            //   3. CUÁL es: "Placa: …  Chasis: …" (ver identificadores), en letra más chica.
            // Sin "·" entre los datos (pedido del cliente, 01-10-2026): los separa un hueco.
            var tipo = limpio(eq.tipo) || 'Sin tipo';
            var modelo = limpio(eq.modelo), marca = limpio(eq.marca);
            var frente = limpio(eq.frente) || 'Sin frente';
            var ids = identificadores(eq);
            // Cada dato es una .mapa-eq-parte y entre dos va un espacio (ahí puede partirse el
            // renglón) más el hueco del CSS. Los de "entero" (la marca, el modelo, "Placa: X",
            // "Chasis: X", la coordenada) no se parten por dentro: si no caben, bajan enteros. El
            // title sale de los MISMOS datos que lo que se ve.
            var parte = function (html, entero) {
                return '<span class="mapa-eq-parte' + (entero ? ' mapa-eq-entero' : '') + '">' + html + '</span>';
            };
            var segundaTxt = [tipo, marca, modelo].filter(Boolean).join('  ');
            var segunda = [parte(esc(tipo)), marca ? parte(esc(marca), true) : '',
                           modelo ? parte(esc(modelo), true) : ''].filter(Boolean).join(' ');
            var terceraTxt = ids.join('  ');
            var tercera = ids.map(function (t) { return parte(esc(t), true); }).join(' ');
            // "desde hace" es lo que GPS51 dijo AL CONSULTAR: con la última posición conocida
            // (`vieja`, de hasta un día) ya no es verdad, así que no se pone.
            var motorDesde = g.vieja ? '' : duracion(g.acc_tiempo);

            return '<div class="mapa-eq">' +
                '<div class="mapa-eq-head" style="border-left-color:' + color + '">' +
                    '<div class="mapa-eq-frente" title="' + esc(frente) + '">' + esc(frente) + '</div>' +
                    '<div class="mapa-eq-tit"><b title="' + esc(segundaTxt) + '">' + segunda + '</b></div>' +
                    // Sin "En línea / Sin conexión" (pedido del cliente, 01-10-2026): lo dice ya
                    // "Última señal", y el icono del mapa sale apagado si no está en línea.
                    '<div class="mapa-eq-desc" title="' + esc(terceraTxt) + '">' + tercera + '</div>' +
                '</div>' +
                // Con la última posición conocida (`vieja`) los datos de abajo son de cuando se
                // consultó, no de ahora: se dice, hasta que llegue la lectura nueva.
                (g.vieja ? '<div class="mapa-eq-vieja">Últimos datos conocidos: ' +
                    (op.sinRespuesta ? 'GPS51 no respondió' : 'actualizando…') + '</div>' : '') +
                '<div class="mapa-eq-grid">' +
                    // Arriba, los tres datos cortos en una fila; debajo, a TODO el ancho, los que
                    // llevan detalle (Motor, Combustible, Última señal): así el valor y su detalle
                    // caben en un renglón y cada celda queda en dos, rótulo y dato (pedido del
                    // cliente, 06-10-2026; en media fila llegaban a tres o cuatro).
                    celda('Velocidad', num(g.velocidad) + ' km/h') +
                    celda('Voltaje', hay(g.voltaje) ? num(g.voltaje, 1) + ' V' : '—') +
                    celda('Kilometraje', num(g.km_total) + ' km') +
                    // "Apagado  desde hace 1 día 11 h": antes salía "1d11h42m" suelto, sin decir qué era.
                    celda('Motor', hay(g.acc) ? (g.acc ? 'Encendido' : 'Apagado') : '—',
                          hay(g.acc) && motorDesde ? 'desde hace ' + motorDesde : '', true) +
                    // El reparto por tanques solo si HAY auxiliar, y cada tanque con su propio
                    // dato: los dos vienen sueltos de GPS51 y uno puede faltar. Poniendo 0 donde
                    // no hay medida se leería como un tanque vacío. Con el nombre entero del
                    // tanque: "P 603 · A 205" no se entendía (pedido del cliente, 01-10-2026).
                    celda('Combustible', hay(comb.total) ? num(comb.total) + ' L' : '—',
                          hay(comb.auxiliar)
                              ? ['Principal ' + (hay(comb.principal) ? num(comb.principal) + ' L' : '—'),
                                 'Auxiliar ' + num(comb.auxiliar) + ' L']   // un trozo por tanque
                              : '', true) +
                    celda('Última señal', window.tiempoHace(g.ultima_senal), window.fechaHoraLocal(g.ultima_senal), true) +
                '</div>' +
                // Dónde está: la dirección corta y, en el MISMO renglón justo detrás, la coordenada
                // (pedido del cliente, 01-10-2026; antes iba debajo), con la misma letra. Como
                // MÁXIMO dos renglones (pedido del cliente, 06-10-2026): si no caben juntas, la
                // dirección se queda con el primero (cortada con "…", entera en el title) y la
                // coordenada baja al segundo (ver .mapa-eq-ubic). Son dos trozos aparte porque
                // ponerDireccion reescribe solo el de la dirección cuando llega.
                '<div class="mapa-eq-ubic"><i class="material-icons">place</i><div>' +
                    (op.sinDireccion ? '' :
                        '<span class="mapa-eq-dir mapa-eq-parte"' + (op.dirAttr ? ' data-eqdir="' + esc(op.dirAttr) + '"' : '') +
                        (op.direccion ? ' title="' + esc(op.direccion) + '"' : '') + '>' +
                        esc(op.direccion ? direccionCorta(op.direccion) : 'Buscando dirección…') + '</span>') +
                    parte('<span class="mapa-eq-coord">' + g.lat.toFixed(6) + ', ' + g.lng.toFixed(6) + '</span>', true) +
                '</div></div>' +
                // Al final, debajo de la ubicación (pedido del cliente, 06-10-2026; antes iba arriba
                // del todo): esta posición no es la real. El equipo se pinta igual (el cliente
                // quiere ver lo que el GPS dice), pero nadie debe salir a buscarlo ahí.
                (op.dudosa ? '<div class="mapa-eq-dudosa"><i class="material-icons">location_off</i>' +
                    '<span>El GPS la reporta FUERA de Venezuela: no es donde está el equipo. Hay que revisar ese GPS.</span></div>' : '') +
            '</div>';
        },

        /**
         * Escribe la dirección cuando llega (va aparte porque tarda ~2 s) en el hueco que dejó
         * html() (data-eqdir), sin repintar la ficha. En pantalla va la versión corta
         * (direccionCorta) y la entera queda en el title. Lo usan el mapa y el modal.
         */
        ponerDireccion: function (nodo, texto) {
            if (!nodo) return;
            nodo.textContent = direccionCorta(texto);
            nodo.title = texto;
        }
    };
})();
