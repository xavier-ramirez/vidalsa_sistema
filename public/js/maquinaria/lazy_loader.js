/**
 * Carga PEREZOSA de scripts pesados que solo usan algunas pantallas.
 *
 * Los <script> del layout son síncronos, así que todo lo que se declara ahí se
 * descarga, parsea y ejecuta en CADA página — justo entre "ya se ve el menú" y "los
 * botones responden". Las librerías y módulos que solo sirven a una pantalla concreta
 * no tienen por qué pagar ese peaje en las demás: se piden desde aquí, cuando hacen
 * falta.
 *
 * Vive en el <head> —igual que dom_helpers.js y por el mismo motivo— porque los
 * <script> inline de @yield('content') se evalúan ANTES del bloque de scripts del
 * final del body, y alguna vista llama a ensureChartJS() al evaluarse.
 *
 * Expone:
 *   · window.cargarScriptUnaVez(src, yaCargado, enOrden) → Promise
 *   · window.cargarCssUnaVez(href)
 *   · window.cargarLeaflet()                    → Promise (Leaflet: /mapa y el modal de GPS)
 *   · window.cargarLeafletMapa()                → Promise (Leaflet + plugins de /mapa)
 *   · window.ensureChartJS()                    → Promise (Chart.js + DataLabels)
 */
(function () {
    'use strict';

    var enVuelo = {};

    /**
     * Inyecta un <script> una sola vez. Si ya se está cargando devuelve la MISMA
     * promesa, así que llamarlo desde varias pantallas a la vez no duplica descargas.
     *
     * @param {string}   src        URL del script.
     * @param {function} yaCargado  Devuelve true si el global que trae ya existe.
     * @param {boolean}  enOrden    true = se EJECUTA en el orden en que se pidió respecto de los
     *                              demás pedidos en orden (async=false), aunque se bajen a la vez:
     *                              para plugins que necesitan su librería (los de Leaflet).
     * @returns {Promise<void>}
     */
    window.cargarScriptUnaVez = function (src, yaCargado, enOrden) {
        if (typeof yaCargado === 'function' && yaCargado()) return Promise.resolve();
        if (enVuelo[src]) return enVuelo[src];

        enVuelo[src] = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = src;
            s.async = !enOrden;
            s.onload = function () { resolve(); };
            s.onerror = function () {
                delete enVuelo[src]; // libera el guard: la próxima visita puede reintentar
                reject(new Error('No se pudo cargar ' + src));
            };
            document.head.appendChild(s);
        });
        return enVuelo[src];
    };

    /** Inserta un <link rel=stylesheet> una sola vez. */
    window.cargarCssUnaVez = function (href) {
        if (document.querySelector('link[data-css-una-vez="' + href + '"]')) return;
        var l = document.createElement('link');
        l.rel = 'stylesheet';
        l.href = href;
        l.setAttribute('data-css-una-vez', href);
        document.head.appendChild(l);
    };

    /**
     * Leaflet (CSS + JS) desde /vendor/leaflet, UNA sola vez para toda la app: lo usan /mapa
     * (cargarLeafletMapa le suma sus plugins, en orden detrás de este) y el mapa del modal de
     * GPS de Equipos. Antes cada uno tenía su cargador y, si se cruzaban, Leaflet podía ejecutarse dos
     * veces y borrar los plugins de /mapa.
     */
    window.cargarLeaflet = function () {
        window.cargarCssUnaVez('/vendor/leaflet/leaflet.css');
        return window.cargarScriptUnaVez('/vendor/leaflet/leaflet.js',
            function () { return typeof L !== 'undefined' && !!L.map; }, true);
    };

    /**
     * Leaflet + los plugins de /mapa (geocoder, agrupador, proj4). Lo llaman el layout —AL
     * MISMO TIEMPO que pide mapa_index.js, para que no bajen uno detrás del otro— y el propio
     * mapa_index (que reusa esas descargas). Todo "en orden" (async=false): se baja a la vez
     * pero se ejecuta detrás de Leaflet. Resuelve cuando se puede dibujar el mapa: proj4 (solo
     * para buscar coordenadas UTM) termina de bajar por su cuenta, sin hacer esperar al mapa;
     * el agrupador, si no llegara, deja los equipos sueltos (la capa lo comprueba). Su CSS trae
     * solo las animaciones: el aspecto del grupo lo pone el sistema (.mapa-eq-grupo).
     */
    window.cargarLeafletMapa = function () {
        var cargar = window.cargarScriptUnaVez;
        window.cargarCssUnaVez('/vendor/leaflet/Control.Geocoder.css');
        window.cargarCssUnaVez('/vendor/leaflet/MarkerCluster.css');
        var pedidos = [
            window.cargarLeaflet(),
            cargar('/vendor/leaflet/Control.Geocoder.js', function () { return typeof L !== 'undefined' && L.Control && !!L.Control.Geocoder; }, true),
            cargar('/vendor/leaflet/leaflet.markercluster.js', function () { return typeof L !== 'undefined' && !!L.markerClusterGroup; }, true).catch(function () {})
        ];
        cargar('/vendor/leaflet/proj4.js', function () { return typeof proj4 !== 'undefined'; }, true).catch(function () {});
        return Promise.all(pedidos);
    };

    /** Base para armar URLs absolutas (el layout la publica en un <meta>). */
    function baseUrl() {
        var m = document.querySelector('meta[name="base-url"]');
        return m ? m.getAttribute('content') : '';
    }
    window.lazyBaseUrl = baseUrl;

    /**
     * Garantiza que `Chart` esté disponible. Lo usan el dashboard de
     * consumo y los gráficos de consumibles; antes chart.umd.min.js (205 KB) iba fijo
     * en el layout aunque no se abriera una sola gráfica.
     *
     * El plugin DataLabels se carga y registra también, pero si falla NO se rechaza la
     * promesa: las gráficas se dibujan igual, solo que sin las etiquetas de valor.
     *
     * @returns {Promise<void>} resuelta cuando `window.Chart` ya se puede usar.
     */
    window.ensureChartJS = function () {
        var base = baseUrl();
        var hayChart  = function () { return typeof window.Chart !== 'undefined'; };
        var hayLabels = function () { return typeof window.ChartDataLabels !== 'undefined'; };

        // Chart.js primero: el UMD del plugin exige que `Chart` exista al ejecutarse.
        return window.cargarScriptUnaVez(base + '/js/chart.umd.min.js', hayChart).then(function () {
            return window.cargarScriptUnaVez(base + '/js/chartjs-plugin-datalabels.min.js', hayLabels)
                .then(function () {
                    if (!hayLabels() || !hayChart()) return;
                    var items = window.Chart.registry && window.Chart.registry.plugins
                        && window.Chart.registry.plugins.items;
                    var registrado = items && Object.values(items).some(function (p) {
                        return p.id === 'datalabels';
                    });
                    if (!registrado) window.Chart.register(window.ChartDataLabels);
                })
                .catch(function (e) {
                    console.warn('DataLabels no cargó; las gráficas van sin etiquetas:', e.message);
                });
        });
    };
})();
