/**
 * Preloader global CON CONTADOR DE REFERENCIAS (window.showPreloader / hidePreloader).
 *
 * Extraido del <script> inline de estructura_base.blade.php (2026-08-24) junto con
 * offline_mode.js y global_handlers.js, que eran el MISMO bloque; se separaron porque
 * un archivo llamado "preloader" no puede esconder el modo offline entero. Los tres se
 * cargan seguidos y EN ESTE ORDEN en el layout, sincronos y sin defer, en el punto
 * exacto que ocupaba el bloque inline.
 *
 * ESTE ARCHIVO TIENE QUE CARGAR ANTES QUE navegacion.js. No es preferencia de estilo:
 * navegacion.js NO redefine showPreloader / hidePreloader, los ENVUELVE — se guarda
 * `_origShow = window.showPreloader` para montarles encima el watchdog anti-spinner-
 * congelado, y lo hace dentro de un `if (_origShow)`. Si este archivo llegara despues,
 * ese guard veria undefined, el wrapper no se instalaria SIN DAR ERROR y se perderia el
 * destrabado a los 8 s al volver a la pestana. El orden ya era ese cuando esto vivia
 * inline; el archivo ocupa exactamente el mismo lugar.
 */
// Global Preloader Controls — CON CONTADOR DE REFERENCIAS.
//
// Motivo: un ÚNICO spinner global es compartido por varias operaciones
// async. El caso más común: al entrar a un módulo (navegación SPA) se
// muestra el spinner, se inyecta el HTML del módulo y su script de init
// lanza un SEGUNDO window.apiFetch(los datos de la tabla/filtro). SIN contador, el
// hidePreloader de la navegación ocultaba el spinner apenas llegaba el
// cascarón HTML, aunque el fetch de datos siguiera en vuelo → con internet
// lento el spinner desaparecía y los datos filtrados aparecían unos
// segundos DESPUÉS. CON contador, cada show() suma y cada hide() resta: el
// spinner solo se oculta cuando TODAS las operaciones que lo pidieron
// terminaron (es decir, cuando los datos ya están pintados en pantalla).
//
// hidePreloader(true) FUERZA el reset del contador y oculta de inmediato:
// lo usan los watchdogs anti-congelado (ver navegacion.js).
let _preloaderRefs = 0;
// GENERACIÓN del contador: sube cada vez que un hidePreloader(true) lo pone a cero (una
// navegación nueva, un watchdog). Quien pidió el spinner ANTES y termina DESPUÉS no debe
// devolver su referencia: ya no existe, y restar se la quitaría a la operación nueva (el
// spinner se iría a media carga). window.preloaderGeneracion() deja comprobarlo.
let _preloaderGen = 0;
window.preloaderGeneracion = function () { return _preloaderGen; };

// El spinner SALE SIEMPRE, desde el primer milisegundo.
//
// Se probó a retrasarlo (para que en la oficina, donde un módulo abre en ~180 ms, no llegara
// a verse) y el usuario lo rechazó con razón: unas veces aparecía y otras no, y en la obra
// —que es donde hay que entender si algo está cargando o si se colgó— la duda es peor que el
// parpadeo. Aquí NO se decide por velocidad: si se pidió, se muestra.
//
// Lo que sí se dejó más fino es la SALIDA: se va en 0,18 s (antes el CSS decía medio segundo
// y el JS la cortaba a los 100 ms, así que la capa desaparecía de golpe a media transición).
function _preloaderPintar() {
    const preloader = document.getElementById('preloader');
    if (!preloader) return;
    preloader.classList.remove('fade-out');
    preloader.style.display = 'flex';
    // Se fuerzan estas tres para que aparezca por encima de todo.
    preloader.style.opacity = '1';
    preloader.style.visibility = 'visible';
    preloader.style.zIndex = '1000000';
}

function _preloaderQuitar() {
    const preloader = document.getElementById('preloader');
    if (!preloader) return;
    preloader.classList.add('fade-out');
    // Acompaña al tiempo del CSS (.preloader.fade-out): si se quitara antes, la capa
    // desaparecería de golpe a media transición.
    setTimeout(() => {
        if (preloader.classList.contains('fade-out')) {
            preloader.style.display = 'none';
        }
    }, 180);
}

window.showPreloader = function () {
    _preloaderRefs++;
    _preloaderPintar();
};

window.hidePreloader = function (force) {
    if (force === true) {
        _preloaderRefs = 0;
        _preloaderGen++;
    } else {
        _preloaderRefs = Math.max(0, _preloaderRefs - 1);
        // Aún hay operaciones en vuelo → mantener el spinner visible.
        if (_preloaderRefs > 0) return;
    }
    _preloaderQuitar();
};

// Ocultar el preloader INICIAL cuando todo (imágenes/iconos) haya cargado,
// PERO solo si ninguna operación lo está usando (refs===0). Si el script de
// init de un módulo ya pidió el spinner para su primer window.apiFetch(refs>0), NO lo
// tocamos aquí: se ocultará cuando ese fetch termine de pintar los datos.
// Sin esta guarda, en una carga de página COMPLETA (no-SPA) el window.load
// bajaba el contador del init y reaparecía el mismo bug (spinner antes que datos).
window.addEventListener('load', function() {
    if (_preloaderRefs === 0 && typeof window.hidePreloader === 'function') {
        window.hidePreloader(true);
    }
});
