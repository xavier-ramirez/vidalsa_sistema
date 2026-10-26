<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use App\Models\Usuario;
use App\Models\BloqueoIp;

class LoginController extends Controller
{
    /**
     * Marca de "este navegador YA inició sesión aquí alguna vez".
     *
     * Existe solo para que el login sepa QUÉ decir cuando llega alguien sin sesión:
     * con la marca, la sesión existió y se venció → se explica; sin ella, o nunca
     * entró o cerró sesión a propósito → login limpio, sin asustar con un aviso de
     * algo que no pasó. La consulta el handler de AuthenticationException en
     * bootstrap/app.php, que es su único lector.
     *
     * NO guarda nada del usuario: es un "1" y nada más. No sirve para autenticar ni
     * para recordar quién era — de eso se encarga la sesión.
     */
    public const COOKIE_SESION_PREVIA = 'vidalsa_sesion_previa';

    public function login(Request $request)
    {
        // OJO: aquí NO se atrapa TokenMismatchException. El CSRF lo valida el middleware
        // VerifyCsrfToken ANTES de llegar a este método, así que un try/catch alrededor
        // del validate() nunca podía dispararse (era código muerto que además devolvía
        // una clave 'reload' que ningún front lee). El 419 lo maneja el handler global
        // de bootstrap/app.php, y el JS del login reintenta una vez con token fresco.
        $credentials = $request->validate([
            'login_identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $ip = $request->ip();
        $equipo = BloqueoIp::equipo($request);
        $cuenta = Str::lower($credentials['login_identifier']);

        // 0. Equipo o IP bloqueados, o la cuenta frenada por claves malas (BloqueoIp).
        if ($motivo = BloqueoIp::motivoBloqueo($ip, $equipo)) {
            return $this->respuestaLogin($request, false, null, $motivo);
        }
        if ($segundos = BloqueoIp::cuentaFrenada($cuenta, $equipo)) {
            return $this->respuestaLogin($request, false, null, 'Demasiadas claves incorrectas para esta cuenta. Intente de nuevo en ' . (int) ceil($segundos / 60) . ' min.');
        }

        try {
            // 2. Auth::attempt (Estándar de Laravel)
            // Busca usuario, hashea la clave y compara, todo en uno.
            // Mapeamos 'login_identifier' a 'CORREO_ELECTRONICO' que es tu columna real
            $authCredentials = [
                'CORREO_ELECTRONICO' => $credentials['login_identifier'],
                'password' => $credentials['password']
            ];

            if (Auth::attempt($authCredentials)) {
                $user = Auth::user();

                // 3. Verificación de Estatus (Lógica de Negocio)
                if ($user->ESTATUS === 'INACTIVO') {
                    Auth::logout(); // Cerramos la sesión que attempt acaba de abrir
                    BloqueoIp::registrarFallo($ip, $equipo, $cuenta); // Contamos como intento fallido para seguridad

                    return $this->respuestaLogin($request, false, null, 'Usuario inactivo. Contacte al administrador.');
                }

                // 4. Éxito: la sesión queda abierta como en toda entrada (abrirSesion).
                self::abrirSesion($request, $user);

                BloqueoIp::registrarExito($ip, $equipo, $cuenta); // Descuenta los fallos de esta cuenta

                // OPTIMIZATION: Direct redirect for password change
                if ($user->REQUIERE_CAMBIO_CLAVE) {
                    return $this->respuestaLogin($request, true, route('password.change'));
                }

                $request->session()->flash('webauthn_prompt', true);
                // Solo ahora, tras escribir la clave, se puede registrar una huella.
                $request->session()->put(WebAuthnController::SESION_REGISTRO, now()->timestamp + WebAuthnController::REGISTRO_TTL);

                return $this->respuestaLogin($request, true, route('menu'));
            }

            // 5. Fallo de Credenciales: cuenta para el equipo, la cuenta y la IP
            $motivo = BloqueoIp::registrarFallo($ip, $equipo, $cuenta);

            return $this->respuestaLogin($request, false, null, $motivo ?? 'Usuario o clave incorrecta.');

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Login Error: ' . $e->getMessage());
            return $this->respuestaLogin($request, false, null, 'Error del sistema al iniciar sesión. Intente nuevamente.');
        }
    }

    /**
     * Respuesta del login compatible con fetch (AJAX) y con navegación clásica.
     * Cuando el front envía por fetch (X-Requested-With/Accept JSON) devolvemos JSON
     * { success, redirect } o { success:false, message }, para que un bajón de red se
     * maneje en JS (quedarse en el login) en vez de disparar la página de error del
     * navegador. Sin AJAX, conserva el comportamiento clásico (redirect/back con errores).
     */
    private function respuestaLogin(Request $request, bool $exito, ?string $redirect = null, ?string $error = null)
    {
        if ($request->ajax() || $request->wantsJson()) {
            if (! $exito) {
                return response()->json(['success' => false, 'message' => $error], 422);
            }
            // clave_v: huella de la clave actual (ver Usuario::claveVersion). El login la
            // guarda junto al candado de acceso sin internet para poder detectar después
            // que la clave cambió.
            return response()->json([
                'success'  => true,
                'redirect' => $redirect,
                'clave_v'  => Auth::user()?->claveVersion(),
            ]);
        }
        if ($exito) {
            return redirect()->to($redirect);
        }
        return back()->withErrors(['login_error' => $error])->withInput($request->except('password'));
    }

    /**
     * Lo que sigue a autenticar, igual en las tres entradas: clave (login), huella
     * (WebAuthnController::login) y pase de reingreso (ReingresoController::reanudar). El
     * usuario ya tiene que estar autenticado (Auth::attempt o Auth::login).
     */
    public static function abrirSesion(Request $request, Usuario $user): void
    {
        // Token compartido por las sesiones del usuario (Usuario::tokenDeSesion): lo vigila
        // ValidarSesionUnica para cortar las sesiones de antes de un cambio de clave.
        $request->session()->regenerate();
        $request->session()->put('current_session_token', $user->tokenDeSesion());
        $request->session()->save();

        // Deja constancia de que en ESTE navegador sí hubo sesión, para que el día
        // que se venza el login pueda decir por qué (ver COOKIE_SESION_PREVIA).
        // Sobrevive a la sesión a propósito: si muriera con ella no serviría para
        // explicar su muerte. La borra el cierre de sesión.
        \Illuminate\Support\Facades\Cookie::queue(
            cookie()->forever(self::COOKIE_SESION_PREVIA, '1', null, null, null, true)
        );
    }

    /**
     * Cierra la sesión de este navegador. La usan logout y el login cuando llega del cierre
     * por inactividad con la sesión todavía abierta (SystemController::loginPage).
     */
    public static function cerrarSesion(Request $request): void
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Se va también la marca de "aquí hubo sesión": quien cierra sesión ya sabe por qué
        // está en el login y no hay que explicarle nada la próxima vez.
        \Illuminate\Support\Facades\Cookie::queue(
            \Illuminate\Support\Facades\Cookie::forget(self::COOKIE_SESION_PREVIA)
        );
    }

    public function logout(Request $request)
    {
        // El SESSION_TOKEN NO se borra aquí, a propósito: lo comparten todas las sesiones del
        // usuario (Usuario::tokenDeSesion), así que borrarlo sacaría también al teléfono o a la
        // otra PC donde sigue dentro. Además ValidarSesionUnica lee un token en null como "la
        // clave cambió" (lo deja así Usuario::establecerClave) y les diría eso, que es falso.
        // La sesión de ESTE navegador se invalida abajo y con eso basta.
        self::cerrarSesion($request);

        return redirect('/')->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
