<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Models\Usuario;
use App\Models\BloqueoIp;
use Carbon\Carbon;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        // Handle expired CSRF token gracefully (419 error prevention)
        try {
            $credentials = $request->validate([
                'login_identifier' => ['required', 'string'],
                'password' => ['required', 'string'],
            ]);
        } catch (\Illuminate\Session\TokenMismatchException $e) {
            // Token expired. En fetch devolvemos JSON con 'reload' para refrescar el CSRF.
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json(['success' => false, 'reload' => true, 'message' => 'Su sesión expiró. Recargando…'], 419);
            }
            return redirect()->route('login')->with('info', 'Su sesión expiró. Por favor, inicie sesión nuevamente.');
        }

        $ip = $request->ip();

        // 0. BLOQUEO PERMANENTE (Base de Datos)
        $bloqueo = BloqueoIp::where('DIRECCION_IP', $ip)->first();
        if ($bloqueo && $bloqueo->BLOQUEO_PERMANENTE) {
            return $this->respuestaLogin($request, false, null, 'Su dirección IP ha sido bloqueada permanentemente por seguridad. Contacte al administrador.');
        }

        // 1. Rate Limiting (Protección contra fuerza bruta Temporal)
        // Usamos el email + IP como clave única para el bloqueo
        $throttleKey = Str::lower($credentials['login_identifier']) . '|' . $ip;

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            
            return $this->respuestaLogin($request, false, null, 'Demasiados intentos fallidos. Por favor intente de nuevo en ' . $seconds . ' segundos.');
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
                    RateLimiter::hit($throttleKey); // Contamos como intento fallido para seguridad
                    
                    return $this->respuestaLogin($request, false, null, 'Usuario inactivo. Contacte al administrador.');
                }

                // 4. Token de Sesión Única (Tu lógica personalizada)
                try {
                    $sessionToken = bin2hex(random_bytes(32));
                } catch (\Exception $e) {
                     $sessionToken = md5(uniqid(rand(), true)); 
                }
                
                $user->SESSION_TOKEN = $sessionToken;
                $user->save();

                // 5. Éxito: Regenerar sesión y limpiar Rate Limiter
                $request->session()->regenerate();
                $request->session()->put('current_session_token', $sessionToken);
                $request->session()->save(); 

                RateLimiter::clear($throttleKey); // Limpiamos el contador de fallos

                // Limpiar contador de bloqueo permanente si existe
                if ($bloqueo) {
                    $bloqueo->CANTIDAD_INTENTOS = 0;
                    $bloqueo->save();
                }

                // OPTIMIZATION: Direct redirect for password change
                if ($user->REQUIERE_CAMBIO_CLAVE) {
                    return $this->respuestaLogin($request, true, route('password.change'));
                }

                $request->session()->flash('webauthn_prompt', true);

                return $this->respuestaLogin($request, true, route('menu'));
            }

            // 6. Fallo de Credenciales
            RateLimiter::hit($throttleKey); // Incrementamos contador de fallos

            // REGISTRO DE FALLO EN BD (Para bloqueo permanente)
            if (!$bloqueo) {
                $bloqueo = new BloqueoIp();
                $bloqueo->DIRECCION_IP = $ip;
                $bloqueo->CANTIDAD_INTENTOS = 0;
            }
            
            $bloqueo->CANTIDAD_INTENTOS++;
            $bloqueo->ULTIMO_INTENTO = Carbon::now();
            
            if ($bloqueo->CANTIDAD_INTENTOS >= 10) {
                $bloqueo->BLOQUEO_PERMANENTE = true;
            }
            
            $bloqueo->save();

            if ($bloqueo->BLOQUEO_PERMANENTE) {
                 return $this->respuestaLogin($request, false, null, 'Ha excedido el límite de intentos. Su IP ha sido bloqueada permanentemente.');
            }

            return $this->respuestaLogin($request, false, null, 'Usuario o clave incorrecta.');

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
            return $exito
                ? response()->json(['success' => true, 'redirect' => $redirect])
                : response()->json(['success' => false, 'message' => $error], 422);
        }
        if ($exito) {
            return redirect()->to($redirect);
        }
        return back()->withErrors(['login_error' => $error])->withInput($request->except('password'));
    }

    // ─── MOBILE API ENDPOINTS ─────────────────────────────────────────────
    public function mobileLogin(Request $request)
    {
        $request->validate([
            'correo'   => 'required|string',
            'password' => 'required|string',
        ]);

        $ip = $request->ip();

        // 1. Bloqueo permanente por IP (misma tabla que el login web)
        $bloqueo = BloqueoIp::where('DIRECCION_IP', $ip)->first();
        if ($bloqueo && $bloqueo->BLOQUEO_PERMANENTE) {
            return response()->json(['error' => 'Su dirección IP ha sido bloqueada por seguridad. Contacte al administrador.'], 403);
        }

        // 2. Rate Limiting (máx. 5 intentos / 60 s por correo+IP)
        $throttleKey = Str::lower($request->correo) . '|' . $ip . '|mobile';
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json([
                'error' => 'Demasiados intentos fallidos. Intente de nuevo en ' . $seconds . ' segundos.'
            ], 429);
        }

        $user = \App\Models\Usuario::where('CORREO_ELECTRONICO', $request->correo)->first();

        if (!$user || !Hash::check($request->password, $user->PASSWORD_HASH)) {
            // Registrar intento fallido (Rate Limiter + BD)
            RateLimiter::hit($throttleKey);

            if (!$bloqueo) {
                $bloqueo = new BloqueoIp();
                $bloqueo->DIRECCION_IP = $ip;
                $bloqueo->CANTIDAD_INTENTOS = 0;
            }
            $bloqueo->CANTIDAD_INTENTOS++;
            $bloqueo->ULTIMO_INTENTO = Carbon::now();
            if ($bloqueo->CANTIDAD_INTENTOS >= 10) {
                $bloqueo->BLOQUEO_PERMANENTE = true;
            }
            $bloqueo->save();

            if ($bloqueo->BLOQUEO_PERMANENTE) {
                return response()->json(['error' => 'Ha excedido el límite de intentos. Su IP ha sido bloqueada permanentemente.'], 403);
            }

            return response()->json(['error' => 'Credenciales incorrectas.'], 401);
        }

        if ($user->ESTATUS === 'INACTIVO') {
            return response()->json(['error' => 'Usuario inactivo. Contacte al administrador.'], 403);
        }

        // Limpiar contadores de intento fallido al éxito
        RateLimiter::clear($throttleKey);
        if ($bloqueo) {
            $bloqueo->CANTIDAD_INTENTOS = 0;
            $bloqueo->save();
        }

        $token = $user->createToken('mobile-app')->plainTextToken;

        // Frentes asignados (claves para descarga selectiva de PDFs en la APK)
        $frentesIds = $user->getFrentesIds();
        $frentes = \App\Models\FrenteTrabajo::whereIn('ID_FRENTE', $frentesIds)
            ->select('ID_FRENTE', 'NOMBRE_FRENTE')
            ->get();

        // `nivel` se conserva por COMPATIBILIDAD con las APK ya instaladas, que lo leen de
        // este payload. Apunta al nivel de EQUIPOS, que es el que gobierna la flota (lo que
        // la app muestra). Las dos claves explícitas son las nuevas; cuando la APK migre a
        // ellas, `nivel` se puede eliminar de aquí.
        return response()->json([
            'token' => $token,
            'user'  => [
                'id'             => $user->ID_USUARIO,
                'nombre'         => $user->NOMBRE_COMPLETO,
                'correo'         => $user->CORREO_ELECTRONICO,
                'nivel'          => $user->NIVEL_ACCESO_EQUIPOS,
                'nivel_equipos'  => $user->NIVEL_ACCESO_EQUIPOS,
                'nivel_almacen'  => $user->NIVEL_ACCESO_ALMACEN,
                'frentes'        => $frentes,
            ]
        ]);
    }

    public function mobileLogout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }
    // ──────────────────────────────────────────────────────────────────────────

    public function logout(Request $request)
    {
        $user = Auth::user();
        if ($user) {
            $user->SESSION_TOKEN = null;
            $user->save();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->withHeaders([
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
