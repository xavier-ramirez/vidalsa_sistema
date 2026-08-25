<?php
/**
 * Banco de pruebas del login (con y sin internet).
 *
 *   php tools/pruebas_login.php
 *
 * NO escribe nada en la base de datos: usa modelos en memoria y peticiones sintéticas.
 * Cubre lo que se rompió alguna vez y no debe volver a romperse: las rutas que el
 * middleware de sesión única NO puede cortar, el motivo con el que expulsa, los avisos
 * que el login tiene que saber traducir, que nadie cambie claves saltándose el modelo, y
 * los tres "dueños únicos" del cliente —sesión caída, red caída y pantalla completa—, que
 * ya estuvieron copiados a mano por media aplicación.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
chdir(__DIR__ . '/..');

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

$ok = 0; $fail = 0;
function check(string $nombre, $esperado, $real): void {
    global $ok, $fail;
    $bien = $esperado === $real;
    $bien ? $ok++ : $fail++;
    printf("%-5s %-52s esperado=%-22s real=%s\n", $bien ? 'OK' : 'FALLA', $nombre,
        var_export($esperado, true), var_export($real, true));
}

// ── A. Usuario::establecerClave — cambiar la clave revoca la sesión ──────────
$u = new App\Models\Usuario();
$u->PASSWORD_HASH = Hash::make('vieja');
$u->SESSION_TOKEN = 'token-vivo';
$hashViejo = $u->PASSWORD_HASH;
$versionVieja = $u->claveVersion();
$u->establecerClave('nueva');
check('establecerClave rehashea',            false, $u->PASSWORD_HASH === $hashViejo);
check('establecerClave acepta la nueva',     true,  Hash::check('nueva', $u->PASSWORD_HASH));
check('establecerClave rechaza la vieja',    false, Hash::check('vieja', $u->PASSWORD_HASH));
check('establecerClave revoca la sesion',    null,  $u->SESSION_TOKEN);

// ── B. Usuario::claveVersion — la huella que delata un cambio de clave ───────
check('claveVersion cambia con la clave',    false, $u->claveVersion() === $versionVieja);
check('claveVersion es estable',             true,  $u->claveVersion() === $u->claveVersion());
check('claveVersion son 16 hex',             1,     preg_match('/^[0-9a-f]{16}$/', $u->claveVersion()));

// ── C. ValidarSesionUnica — qué corta y con qué motivo ──────────────────────
function pasar(string $metodo, string $uri, ?string $tokenEnBd) {
    $u = new App\Models\Usuario();
    $u->ID_USUARIO    = 999999;
    $u->SESSION_TOKEN = $tokenEnBd;
    Auth::setUser($u);
    $req = Illuminate\Http\Request::create($uri, $metodo);
    $req->setLaravelSession(app('session.store'));
    $req->session()->put('current_session_token', 'token-de-esta-sesion');
    $res = (new App\Http\Middleware\ValidarSesionUnica())
        ->handle($req, fn () => new Illuminate\Http\Response('PASA', 200));
    return $res->getStatusCode() === 200 ? 'PASA' : $res->headers->get('Location');
}
// Estas rutas EXISTEN para volver a autenticarse: un token viejo no puede cortarlas, o el
// usuario tiene que pulsar "Entrar" dos veces (era el bug original).
foreach ([['POST', '/'], ['POST', '/webauthn/login'], ['POST', '/webauthn/login-options'],
          ['GET', '/refresh-csrf'], ['POST', '/logout']] as [$metodo, $uri]) {
    check("auth libre: $metodo $uri", 'PASA', pasar($metodo, $uri, 'otro-token'));
}
check('token distinto -> otro_dispositivo', 'http://localhost?aviso=otro_dispositivo', pasar('GET', '/menu', 'otro-token'));
check('token NULL -> clave_cambiada',       'http://localhost?aviso=clave_cambiada',   pasar('GET', '/menu', null));

// ── D. La pantalla de login responde en todas sus formas ────────────────────
Auth::logout();
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
foreach (['/', '/?aviso=clave_cambiada', '/?aviso=otro_dispositivo', '/?aviso=sesion_expirada'] as $uri) {
    check("GET $uri", 200, $kernel->handle(Illuminate\Http\Request::create($uri, 'GET'))->getStatusCode());
}
check('GET /login redirige a /', 'http://localhost',
    $kernel->handle(Illuminate\Http\Request::create('/login', 'GET'))->headers->get('Location'));

// ── E. Todo aviso que manda el servidor lo sabe traducir el login ───────────
$html = $kernel->handle(Illuminate\Http\Request::create('/', 'GET'))->getContent();
$php  = file_get_contents('app/Http/Middleware/ValidarSesionUnica.php')
      . file_get_contents('bootstrap/app.php')
      . file_get_contents('app/Http/Controllers/Auth/ChangePasswordController.php');
preg_match_all("/aviso=([a-z_]+)|'(sesion_expirada|otro_dispositivo|clave_cambiada)'/", $php, $m);
foreach (array_unique(array_filter(array_merge($m[1], $m[2]))) as $clave) {
    check("aviso '$clave' traducido en el blade", true, str_contains($html, $clave . ':'));
}

// ── F. Nadie cambia claves saltándose el modelo ─────────────────────────────
// El único permitido es UserController::store(): crea un usuario nuevo, no hay sesión que
// revocar. Cualquier otro sitio que asigne PASSWORD_HASH a mano sería una vía que se salta
// la revocación de sesión (y dejaría el candado de acceso sin internet viejo en el teléfono).
$aMano = [];
foreach (array_merge(glob('app/Http/Controllers/*.php'), glob('app/Http/Controllers/*/*.php')) as $f) {
    if (str_contains(file_get_contents($f), 'PASSWORD_HASH = Hash::make')) $aMano[] = basename($f);
}
check('claves cambiadas a mano (solo store)', ['UserController.php'], $aMano);

// ── G. Cliente y servidor hablan del mismo candado ──────────────────────────
$js    = file_get_contents('public/js/offline/offline-auth.js');
$login = file_get_contents('resources/views/auth/inicio_sesion.blade.php');
check('offline-auth expone sincronizar()',   true, str_contains($js, 'sincronizar: async function'));

// El boton correcto sin cerrar y reabrir la app.
// El evento 'online' NO basta: solo salta cuando el navegador pasa de "sin interfaz de
// red" a "con interfaz", y el caso que el acceso local existe para cubrir es el otro —
// wifi levantado y servidor inalcanzable—, donde navigator.onLine nunca fue false. Sin
// re-sondeo, al volver el servidor el login se quedaba ofreciendo "Entrar sin conexion"
// hasta cerrar y reabrir la PWA.
check('re-sondea mientras no hay servidor', true, str_contains($js, 'function ajustarResondeo()'));
check('el re-sondeo lo dispara un intervalo', true, str_contains($js, 'resondeo = setInterval('));
check('no sondea en segundo plano',          true, str_contains($js, 'if (document.hidden) return;'));
check('vuelve a preguntar al volver a la app', true, str_contains($js, "addEventListener('visibilitychange'"));
// La condicion "hay algo que decidir" la comparten el sondeo y el re-sondeo: una sola
// definicion, para que no puedan discrepar y dejar un temporizador girando en vano.
check('la guarda del sondeo vive en un solo sitio', 1, substr_count($js, 'function hayAlgoQueDecidir()'));
check('el login la llama en los 2 caminos',  2,    substr_count($login, '.sincronizar(data.clave_v)'));
foreach (['Auth/LoginController', 'Auth/WebAuthnController'] as $c) {
    check(basename($c) . " manda clave_v", true,
        str_contains(file_get_contents("app/Http/Controllers/$c.php"), "'clave_v'"));
}

// ── H. Sesion caida: un solo dueno en el cliente ────────────────────────────
// El interceptor global de estructura_base ataja los 401/419 y devuelve una promesa que
// no resuelve nunca, asi que ningun modulo puede reaccionar a esos codigos. Cualquier
// rama de "sesion expirada" que vuelva a aparecer detras de apiFetch seria codigo muerto.
// Exentos: la pantalla de login y webauthn.js, que corren SIN interceptor (esa vista no
// usa estructura_base), y el propio interceptor.
$clientes = array_merge(
    glob('public/js/*.js'), glob('public/js/*/*.js'),
    glob('resources/views/*/*.blade.php'), glob('resources/views/*/*/*.blade.php')
);
$exentos  = ['inicio_sesion.blade.php', 'fetch_interceptor.js', 'webauthn.js'];
$conRama  = [];
$conUrlLogin = [];
foreach ($clientes as $f) {
    $t = file_get_contents($f);
    $base = basename($f);
    if (!in_array($base, $exentos, true) && preg_match('/status\s*===?\s*(401|419)/', $t)) {
        $conRama[] = $base;
    }
    if (str_contains($t, "includes('/login')")) $conUrlLogin[] = $base;
}
check('ramas 401/419 fuera del interceptor', [], $conRama);
check("condicion muerta url.includes('/login')", [], $conUrlLogin);

// El aviso que manda el interceptor cuando lo que falla es subir el outbox
check('aviso de pendientes traducido', true, str_contains($html, 'sesion_expirada_pendientes'));

// webauthn.js no debe volver a adivinar con qué clave viene el error del servidor
$wa = file_get_contents('public/js/webauthn.js');
check('webauthn lee error Y message', true, str_contains($wa, 'function textoError'));
check('webauthn sin lecturas a pelo',  0,    substr_count($wa, ".error || '"));

// ── I. Sin conexion: un solo dueno tambien ──────────────────────────────────
// El interceptor global de fetch decide que se fue la red y saca el banner con su boton.
// Antes esa deteccion estaba copiada a mano en cinco modulos y solo se enteraba quien se
// acordara de ponerla. Ninguno debe volver a hacerlo por su cuenta.
$fuera = [];
foreach ($clientes as $f) {
    if (basename($f) === 'fetch_interceptor.js') continue;
    if (str_contains(file_get_contents($f), 'netStatus.showOffline(')) $fuera[] = basename($f);
}
check('detectores de red fuera del interceptor', [], $fuera);

// El interceptor global de fetch ya no vive inline en el layout: tiene archivo propio.
// Si vuelve a moverse, es ESTA ruta la que hay que cambiar, no las comprobaciones.
$interceptor = file_get_contents('public/js/maquinaria/fetch_interceptor.js');
// El sondeo de conexion se quedo en el modulo offline, que es quien decide si hay red.
$sondeo      = file_get_contents('public/js/maquinaria/offline_mode.js');
check('el interceptor saca el aviso', 1, substr_count($interceptor, 'window.netStatus.showOffline();'));
// Que falle un servicio externo (el buscador del mapa) no puede confundirse con que se
// haya caido NUESTRO servidor: se compara por ORIGEN, no por prefijo de texto.
check('descarta peticiones de otro origen', true,
    str_contains($interceptor, 'new URL(_url, location.href).origin === location.origin'));
// El sondeo va por apiFetch a proposito: si alguien lo pasa a fetch() a pelo se salta el
// interceptor y el banner deja de salir en la carga inicial sin conexion.
check('el sondeo pasa por apiFetch', true, str_contains($sondeo, "window.apiFetch('/offline/version'"));

// ── J. Pantalla completa: una sola respuesta ────────────────────────────────
// En pantalla completa el navegador solo pinta el subarbol del elemento que la ocupa, asi
// que lo que cuelga del body no se ve. La pregunta vive en dom_helpers (raizVisible) y
// nadie mas debe responderla por su cuenta: nueve sitios lo hacian y no todos igual.
$sueltos = [];
foreach ($clientes as $f) {
    if (basename($f) === 'dom_helpers.js') continue;   // ahi vive la unica definicion
    if (str_contains(file_get_contents($f), 'fullscreenElement')) $sueltos[] = basename($f);
}
check('comprobaciones de pantalla completa sueltas', [], $sueltos);
check('raizVisible tiene una sola definicion', 1,
    substr_count(file_get_contents('public/js/maquinaria/dom_helpers.js'), 'window.raizVisible = function'));

// ── K. Globales pisadas en la superficie SIEMPRE cargada ───────────────────
// Dos archivos que se cargan JUNTOS no pueden definir la misma window.X: la segunda pisa
// a la primera en silencio y gana la que quede mas abajo. Paso de verdad con
// window.clearFilter, que tenia DOS implementaciones distintas (una limpiaba solo la
// interfaz, la otra reaplicaba el filtro) y encima no la llamaba nadie.
//
// Solo se mira lo que carga SIEMPRE el layout: dos vistas distintas pueden repetir un
// nombre sin problema porque nunca coinciden en la misma pagina.
$layoutSrc = file_get_contents('resources/views/layouts/estructura_base.blade.php');
preg_match_all("~asset\('(js/[^']+\.js)'\)~", $layoutSrc, $m);
$siempre = ['resources/views/layouts/estructura_base.blade.php'];
foreach (array_unique($m[1]) as $rel) {
    if (is_file('public/' . $rel)) $siempre[] = 'public/' . $rel;
}
// navegacion.js ENVUELVE showPreloader/hidePreloader a proposito (watchdog anti-congelado):
// guarda las originales y las llama. No es una segunda definicion que pise, es un decorador.
$permitidas = ['showPreloader', 'hidePreloader'];
$globales = [];
foreach ($siempre as $f) {
    preg_match_all('/window\.([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?function/', file_get_contents($f), $g);
    foreach (array_unique($g[1]) as $nombre) $globales[$nombre][] = basename($f);
}
$pisadas = [];
foreach ($globales as $nombre => $archivos) {
    if (count($archivos) > 1 && !in_array($nombre, $permitidas, true)) {
        $pisadas[] = $nombre . ' (' . implode(', ', $archivos) . ')';
    }
}
check('globales pisadas entre archivos siempre cargados', [], $pisadas);
check('archivos de la superficie comun analizados', true, count($siempre) >= 5);

// ── L. El spinner no puede encenderse dos veces por un mismo envio ──────────
// El preloader lleva un CONTADOR de referencias: se va cuando todos los que lo
// encendieron lo apagan. Un <form onsubmit="showPreloader()"> cuyo envio ademas atrapa un
// listener con preventDefault() lo enciende DOS veces —una el atributo, otra el manejador—
// y solo se apaga una: el contador queda en 1 y el spinner tapa la pantalla para siempre.
// Y como el envio nativo esta cancelado, tampoco hay recarga que lo limpie.
//
// Paso de verdad en /admin/frentes ("le di guardar y se quedo cargando") y estaba tambien,
// sin reportar, en los filtros de /admin/consumibles. El manejador de JS es el dueno del
// spinner; el atributo sobra siempre.
$conOnsubmit = [];
foreach ($clientes as $f) {
    if (!str_ends_with($f, '.blade.php')) continue;
    if (preg_match('/onsubmit="[^"]*showPreloader/', file_get_contents($f))) {
        $conOnsubmit[] = basename($f);
    }
}
check('formularios que encienden el spinner en onsubmit', [], $conOnsubmit);

// ── El contador del spinner no puede cruzar de un modulo a otro ──────────────
//
// El preloader va contado por referencias y la SPA no recarga la pagina, asi que
// ese contador sobrevive al cambio de modulo. Sin un borron al empezar cada
// navegacion, un +1 sin su -1 en una pantalla deja el spinner girando en la
// SIGUIENTE, y solo lo apaga el watchdog de 8s (el "tarda burda" del reporte).
$nav = file_get_contents(__DIR__ . '/../public/js/maquinaria/navegacion.js');

check('la navegacion limpia el contador heredado', true,
    (bool) preg_match('/if \(!_inheritSpinner\) \{\s*if \(window\.hidePreloader\) window\.hidePreloader\(true\);/', $nav));

// ...pero sin pisar el handoff de guardar-redirigir, que SI hereda su +1.
check('el handoff de redirect se respeta', true,
    str_contains($nav, 'const _inheritSpinner = (window.__vidalsaRedirecting === true);'));

// Dos navegaciones seguidas: el apagado diferido de la primera no puede restarle
// una referencia a la segunda, que todavia esta cargando.
check('el apagado comprueba que sigue siendo su navegacion', true,
    str_contains($nav, 'if (_miNav !== _navSeq) return;'));

// Dentro de loadPage NINGUN camino puede apagar el spinner por su cuenta: todos
// (exito, 403, respuesta no-HTML, error de red y finally) tienen que pasar por
// _apagarSiSigueSiendoMia(), que es quien comprueba la propiedad. Un hide suelto
// ahi dentro le robaria la referencia a la navegacion siguiente.
$ini  = strpos($nav, 'async function loadPage(');
$fin  = strpos($nav, 'GUARD ANTI-SPINNER-CONGELADO');
$body = substr($nav, $ini, $fin - $ini);

// Se descuentan los que SI son legitimos: el del propio punto unico y el borron
// de arranque (hidePreloader(true), que reinicia el contador heredado).
$sueltos = substr_count($body, 'window.hidePreloader();') - 1;
check('apagados sueltos dentro de loadPage', 0, $sueltos);
check('el punto unico de apagado existe', true,
    str_contains($nav, 'const _apagarSiSigueSiendoMia = () => {'));

// ── Ninguna ruta puede apuntar a un metodo que no existe ─────────────────────
//
// Route::resource declara las SIETE acciones aunque el controlador no las tenga.
// El catalogo no tiene show(), asi que /admin/catalogo/{id} devolvia un 500
// ("Method ...::show does not exist") en vez de un error honesto. No lo ve ningun
// linter: la ruta se registra bien, revienta al entrar.
$rotas = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $ruta) {
    $accion = $ruta->getActionName();
    if (!str_contains($accion, '@')) continue;
    [$clase, $metodo] = explode('@', $accion);
    if (!class_exists($clase) || !method_exists($clase, $metodo)) $rotas[] = $accion;
}
check('rutas que apuntan a un metodo inexistente', [], $rotas);

// ── Toda casilla de permiso tiene que comprobarse en alguna parte ────────────
//
// /admin/usuarios ofrece marcar permisos. Uno que no se comprueba en ningun sitio
// es una casilla que promete algo y no concede nada: 'user.create' decia "Registrar
// Usuarios" y estaba marcada en 7 usuarios que NO podian crear, porque crear lo
// protege can:manage.users. No da error ni se ve en pantalla; solo se nota cuando
// alguien pregunta por que no le funciona.
$codigoTodo = '';
foreach (['app', 'routes', 'resources/views'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $dir));
    foreach ($it as $arch) {
        if (preg_match('/\.(php|blade\.php)$/', $arch->getFilename())) {
            $codigoTodo .= file_get_contents($arch->getPathname()) . "\n";
        }
    }
}

$sinComprobar = [];
foreach (array_keys(\App\Http\Controllers\UserController::availablePermissions()) as $clave) {
    $q = preg_quote($clave, '/');
    $veces = preg_match_all("/@can\(\s*'{$q}'/", $codigoTodo)
           + preg_match_all("/@cannot\(\s*'{$q}'/", $codigoTodo)
           + preg_match_all("/can:{$q}(?![a-z.])/", $codigoTodo)
           + preg_match_all("/->can\(\s*'{$q}'/", $codigoTodo)
           + preg_match_all("/->cannot\(\s*'{$q}'/", $codigoTodo)
           + preg_match_all("/Gate::(?:allows|denies|check)\(\s*'{$q}'/", $codigoTodo)
           + preg_match_all("/'{$q}'\s*=>\s*'[a-z]/", $codigoTodo);   // mapas tipo alertas.ver.*
    if ($veces === 0) $sinComprobar[] = $clave;
}
check('permisos que se ofrecen pero nadie comprueba', [], $sinComprobar);

// ── Un 419 NO puede tratarse como sesion muerta ──────────────────────────────
//
// 401 = no hay sesion. 419 = el TOKEN no vale, que es otra cosa: la sesion puede
// estar viva. Con el Service Worker sirviendo HTML del cache, un token viejo es lo
// normal. Tratarlos igual expulsaba al usuario con la sesion intacta ("Tu sesion
// expiro por seguridad") y al volver a entrar todo funcionaba, porque nada se habia
// caido. El interceptor pide un token fresco y reintenta UNA vez antes de rendirse.
check('el 419 refresca el token antes de rendirse', true,
    str_contains($interceptor, "if (response.status === 419 && !args[2]) {"));
check('el reintento pasa por /refresh-csrf', true,
    str_contains($interceptor, "originalFetch('/refresh-csrf', {"));
check('el reintento tambien refresca el _token del cuerpo', true,
    str_contains($interceptor, "b.set('_token', fresco);"));
// Sin la marca del tercer argumento, un 419 persistente se reintentaria en bucle.
check('el reintento se marca para no repetirse', true,
    str_contains($interceptor, 'return window.fetch(args[0], conf, true);'));


// ── Visor de PDF: correcciones anexas ───────────────────────────────────────
//
// Tres invariantes que ya se rompieron una vez o costarian caro romperse:
//
//  1. El boton rojo borra LO QUE SE VE. Cuando solo sabia borrar el principal se
//     escondia al abrir una correccion, y las correcciones quedaban sin forma de
//     deshacerse. Si alguien vuelve a esconderlo, esto lo canta.
//  2. Al apagar la comparacion, el iframe derecho se VACIA. Sin eso el panel
//     seguiria enseñando la correccion del documento anterior junto al siguiente.
//  3. El desmontaje de la comparacion se escribe UNA vez y lo usan los CUATRO
//     caminos que la apagan: el boton, el cierre del visor, el cambio de documento
//     y encoger la ventana por debajo del ancho minimo.
$vis = file_get_contents('public/js/maquinaria/layout_ui.js');

check('el boton de eliminar sigue visible en una correccion', 0,
    preg_match_all("/btnDel[^;]*style\.display = 'none'/", $vis));
check('borrar una correccion usa su propio endpoint', true,
    str_contains($vis, "'/admin/equipos/' + ctx.equipoId + '/anexos/' + correccion.id"));
check('apagar la comparacion vacia el iframe derecho', true,
    str_contains($vis, "if (frame) frame.src = 'about:blank';"));
check('el desmontaje de la comparacion tiene un solo dueno', 1,
    substr_count($vis, 'window._pdfComparaApagar = function'));
check('lo llaman los cuatro caminos', 4,
    substr_count($vis, 'window._pdfComparaApagar()'));

// El boton de anexar vive en la CABECERA desde que la barra es solo pestañas: si
// vuelve a la barra, sin correcciones habria que pintar una barra vacia para verlo.
$layoutVisor = file_get_contents('resources/views/layouts/estructura_base.blade.php');
$posAnexar = strpos($layoutVisor, 'id="pdfAnexarZona"');
$posBarra  = strpos($layoutVisor, 'id="pdfAnexosBar"');
check('el boton de anexar esta en la cabecera, no en la barra', true,
    $posAnexar !== false && $posBarra !== false && $posAnexar < $posBarra);

printf("\n%d OK, %d FALLAS\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
