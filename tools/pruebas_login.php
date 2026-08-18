<?php
/**
 * Banco de pruebas del login (con y sin internet).
 *
 *   php tools/pruebas_login.php
 *
 * NO escribe nada en la base de datos: usa modelos en memoria y peticiones sintéticas.
 * Cubre lo que se rompió alguna vez y no debe volver a romperse: las rutas que el
 * middleware de sesión única NO puede cortar, el motivo con el que expulsa, los avisos
 * que el login tiene que saber traducir, y que nadie cambie claves saltándose el modelo.
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
check('el login la llama en los 2 caminos',  2,    substr_count($login, '.sincronizar(data.clave_v)'));
foreach (['Auth/LoginController', 'Auth/WebAuthnController'] as $c) {
    check(basename($c) . " manda clave_v", true,
        str_contains(file_get_contents("app/Http/Controllers/$c.php"), "'clave_v'"));
}

printf("\n%d OK, %d FALLAS\n", $ok, $fail);
exit($fail === 0 ? 0 : 1);
