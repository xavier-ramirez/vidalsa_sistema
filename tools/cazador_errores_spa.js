/**
 * Cazador de errores de consola durante la navegación SPA.
 *
 * PARA QUÉ. Al navegar por la SPA, los scripts de cada vista se vuelven a ejecutar. Si algo
 * se engancha dos veces o busca un elemento que ya no está, salta un error en la consola —
 * pero se pierde entre el resto del ruido y nadie lo ve. Esto los recoge TODOS y los enseña
 * juntos al final, agrupados y contados.
 *
 * CÓMO USARLO
 *   1. Entra a la aplicación y abre F12 → pestaña "Console".
 *   2. Pega TODO este archivo y dale Enter. Debe responder "cazador activo".
 *   3. AHORA navega: Equipos → Almacén → Movilizaciones → Mapa → Equipos otra vez.
 *      Entra y sal de los mismos módulos VARIAS veces: los fallos de la SPA aparecen
 *      justamente a la segunda o tercera visita, no a la primera.
 *      Abre también un PDF y el modal de detalles de un equipo.
 *   4. Escribe en la consola:   reporteSPA()
 *   5. Mándame lo que salga.
 *
 * No cambia nada de la aplicación: solo escucha. Para desactivarlo, recarga la página.
 */
(function () {
    'use strict';

    if (window.__cazadorSPA) {
        console.log('El cazador ya estaba activo. Escribe reporteSPA() para ver lo recogido.');
        return;
    }

    var registro = [];   // [{tipo, texto, url, veces}]
    var navegaciones = [];

    function anotar(tipo, texto) {
        texto = String(texto || '').slice(0, 300);
        // Se agrupa por tipo+texto+pantalla: si el mismo error sale 40 veces interesa el
        // CONTADOR, no cuarenta líneas iguales.
        var url = location.pathname + location.search;
        var yaEsta = registro.find(function (r) {
            return r.tipo === tipo && r.texto === texto && r.url === url;
        });
        if (yaEsta) { yaEsta.veces++; return; }
        registro.push({ tipo: tipo, texto: texto, url: url, veces: 1 });
    }

    // 1) console.error y console.warn — se envuelven conservando el original, para que la
    //    consola siga mostrándolos como siempre.
    ['error', 'warn'].forEach(function (nivel) {
        var original = console[nivel];
        console[nivel] = function () {
            try {
                anotar(nivel, Array.prototype.map.call(arguments, function (a) {
                    if (a instanceof Error) return a.message;
                    if (typeof a === 'object') { try { return JSON.stringify(a); } catch (e) { return '[objeto]'; } }
                    return a;
                }).join(' '));
            } catch (e) { /* el cazador nunca debe romper nada */ }
            return original.apply(console, arguments);
        };
    });

    // 2) Errores de JavaScript que nadie atrapó
    window.addEventListener('error', function (ev) {
        if (ev.target && ev.target.tagName) {
            // Falló la carga de un recurso (imagen, script, css), no una excepción
            anotar('recurso', ev.target.tagName + ' no cargó: ' + (ev.target.src || ev.target.href || '?'));
            return;
        }
        anotar('excepcion', (ev.message || '') + '  @ ' + (ev.filename || '?') + ':' + (ev.lineno || '?'));
    }, true);

    // 3) Promesas rechazadas sin manejar (el fallo silencioso más típico)
    window.addEventListener('unhandledrejection', function (ev) {
        var r = ev.reason;
        anotar('promesa', (r && r.message) ? r.message : String(r));
    });

    // 4) Cada navegación SPA, para saber en qué orden se recorrió
    window.addEventListener('spa:contentLoaded', function () {
        navegaciones.push(location.pathname + location.search);
    });

    window.__cazadorSPA = true;

    window.reporteSPA = function () {
        console.log('%c── RECORRIDO ──', 'font-weight:bold');
        console.log('  Navegaciones SPA registradas: ' + navegaciones.length);
        navegaciones.forEach(function (u, i) { console.log('   ' + (i + 1) + '. ' + u); });

        console.log('%c── HALLAZGOS ──', 'font-weight:bold');
        if (!registro.length) {
            console.log('  ✅ Ni un error, ni un aviso, ni una promesa rechazada.');
            return 'limpio';
        }
        // Lo que más se repite primero: un error que sale 30 veces suele ser algo que se
        // engancha de más en cada visita.
        registro.sort(function (a, b) { return b.veces - a.veces; });
        console.table(registro.map(function (r) {
            return { tipo: r.tipo, veces: r.veces, pantalla: r.url, mensaje: r.texto };
        }));
        console.log('  Total distintos: ' + registro.length +
                    ' · repeticiones: ' + registro.reduce(function (s, r) { return s + r.veces; }, 0));
        return registro;
    };

    console.log('%ccazador activo.', 'color:#16a34a;font-weight:bold',
        'Navega entre módulos (entra y sal VARIAS veces de los mismos) y luego escribe: reporteSPA()');
})();
