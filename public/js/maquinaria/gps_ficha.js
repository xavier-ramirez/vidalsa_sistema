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

    function celda(rotulo, valor, detalle, ancha) {
        return '<div class="mapa-eq-cel' + (ancha ? ' ancha' : '') + '"><span>' + esc(rotulo) + '</span>' +
               '<b>' + esc(valor) + '</b>' + (detalle ? '<small>' + esc(detalle) + '</small>' : '') + '</div>';
    }

    window.GpsFicha = {
        /**
         * El HTML de la ficha, o cadena vacía si el equipo no tiene posición que pintar
         * (sin `gps`, con `gps.ok` falso o sin coordenadas).
         *
         * @param {object} eq  El equipo: { ident, descripcion, frente, color, placa, codigo,
         *                     serial_chasis, gps }. `gps` es lo que devuelve Gps51Service.
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
            var ids = [['Placa', eq.placa], ['Código', eq.codigo], ['Serial chasis', eq.serial_chasis]]
                .filter(function (p) { return p[1]; })
                .map(function (p) { return '<span>' + esc(p[0]) + ': <b>' + esc(p[1]) + '</b></span>'; }).join('');

            return '<div class="mapa-eq">' +
                // Lo primero: esta posición no es la real. El equipo se pinta igual (el cliente
                // quiere ver lo que el GPS dice), pero nadie debe salir a buscarlo ahí.
                (op.dudosa ? '<div class="mapa-eq-dudosa"><i class="material-icons">location_off</i>' +
                    '<span>El GPS la reporta FUERA de Venezuela: no es donde está el equipo. Hay que revisar ese GPS.</span></div>' : '') +
                '<div class="mapa-eq-head" style="border-left-color:' + color + '">' +
                    '<div class="mapa-eq-tit"><b>' + esc(eq.ident) + '</b>' +
                        '<span class="mapa-eq-estado ' + (g.en_linea ? 'en-linea' : 'fuera') + '">' +
                        (g.en_linea ? 'En línea' : 'Sin conexión') + '</span></div>' +
                    (eq.descripcion ? '<div class="mapa-eq-desc">' + esc(eq.descripcion) + '</div>' : '') +
                    '<div class="mapa-eq-frente"><span style="background:' + color + '"></span>' +
                        esc(eq.frente || 'Sin frente') + '</div>' +
                '</div>' +
                '<div class="mapa-eq-grid">' +
                    celda('Velocidad', num(g.velocidad) + ' km/h') +
                    celda('Motor', hay(g.acc) ? (g.acc ? 'Encendido' : 'Apagado') : '—', g.acc_tiempo) +
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
                (ids ? '<div class="mapa-eq-ids">' + ids + '</div>' : '') +
                (op.sinDireccion ? '' :
                    '<div class="mapa-eq-dir"' + (op.dirAttr ? ' data-eqdir="' + esc(op.dirAttr) + '"' : '') + '>' +
                    esc(op.direccion || 'Buscando dirección…') + '</div>') +
                '<div class="mapa-eq-coord">' + g.lat.toFixed(6) + ', ' + g.lng.toFixed(6) + '</div>' +
            '</div>';
        }
    };
})();
