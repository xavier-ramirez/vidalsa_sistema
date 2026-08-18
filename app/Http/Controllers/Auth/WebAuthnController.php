<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\WebAuthnCredential;
use App\Models\Usuario;
use App\Models\BloqueoIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Carbon\Carbon;

class WebAuthnController extends Controller
{
    private const RP_NAME = 'Vidalsa Sistema';

    /**
     * Vida útil del challenge de login (segundos). Se anuncia al cliente como
     * expires_in en loginOptions(): webauthn.js deriva de ahí cuándo descartar
     * su challenge pre-cargado (con margen), así el umbral vive en UN solo sitio.
     */
    private const LOGIN_CHALLENGE_TTL = 600;

    // ─── REGISTRO (usuario ya autenticado) ───────────────────────────────

    public function registerOptions(Request $request)
    {
        $user = $request->user();
        $challenge = random_bytes(32);
        $request->session()->put('webauthn_register_challenge', $challenge);

        $rpId = $this->getRpId($request);

        return response()->json([
            'rp' => [
                'name' => self::RP_NAME,
                'id'   => $rpId,
            ],
            'user' => [
                'id'          => base64_encode((string) $user->ID_USUARIO),
                'name'        => $user->CORREO_ELECTRONICO,
                'displayName' => $user->NOMBRE_COMPLETO,
            ],
            'challenge'            => base64_encode($challenge),
            'pubKeyCredParams'     => [
                ['type' => 'public-key', 'alg' => -7],   // ES256
                ['type' => 'public-key', 'alg' => -257],  // RS256
            ],
            'timeout'              => 60000,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'userVerification'        => 'required',
                'residentKey'             => 'discouraged',
            ],
            'attestation' => 'none',
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'credential_id'     => 'required|string',
            'public_key_spki'   => 'required|string',
            'nombre_dispositivo' => 'nullable|string|max:150',
        ]);

        $challenge = $request->session()->pull('webauthn_register_challenge');
        if (!$challenge) {
            return response()->json(['error' => 'Challenge expirado. Intente de nuevo.'], 422);
        }

        $credentialId = $request->input('credential_id');
        $spkiBytes    = base64_decode($request->input('public_key_spki'));

        if (!$spkiBytes || strlen($spkiBytes) < 32) {
            return response()->json(['error' => 'Llave pública inválida.'], 422);
        }

        $pem = $this->spkiToPem($spkiBytes);
        $pubKey = openssl_pkey_get_public($pem);
        if (!$pubKey) {
            return response()->json(['error' => 'No se pudo procesar la llave pública.'], 422);
        }

        $user = $request->user();

        WebAuthnCredential::updateOrCreate(
            ['credential_id' => $credentialId],
            [
                'ID_USUARIO'         => $user->ID_USUARIO,
                'public_key_pem'     => $pem,
                'nombre_dispositivo' => $request->input('nombre_dispositivo', 'Dispositivo móvil'),
                'counter'            => 0,
                'created_at'         => now(),
            ]
        );

        return response()->json(['success' => true, 'message' => 'Huella registrada correctamente.']);
    }

    // ─── AUTENTICACIÓN (usuario NO autenticado) ──────────────────────────

    public function loginOptions(Request $request)
    {
        $request->validate(['credential_ids' => 'required|array|min:1']);

        $credentials = WebAuthnCredential::whereIn('credential_id', $request->input('credential_ids'))->get();

        if ($credentials->isEmpty()) {
            // `code` es una MARCA EXPLÍCITA de "el servidor miró y de verdad no están".
            // El cliente solo borra las credenciales del teléfono cuando ve esta marca:
            // sin ella, cualquier 404 pasajero (rutas recacheándose durante un despliegue,
            // el móvil pegándole a otro host, un proxy devolviendo su propia página de
            // error) borraba la huella de todos y obligaba a registrarla de cero.
            return response()->json([
                'error' => 'No se encontraron credenciales registradas.',
                'code'  => 'sin_credenciales',
            ], 404);
        }

        $challenge = random_bytes(32);
        // issued_at da al challenge un TTL propio (ver login()): el cliente lo
        // mantiene pre-cargado entre refreshes y no debe firmar uno condenado.
        $request->session()->put('webauthn_login_challenge', [
            'bytes'     => $challenge,
            'issued_at' => now()->timestamp,
        ]);

        $rpId = $this->getRpId($request);

        $allowCredentials = $credentials->map(fn ($c) => [
            'type' => 'public-key',
            'id'   => $c->credential_id,
        ])->values()->toArray();

        return response()->json([
            'challenge'        => base64_encode($challenge),
            'timeout'          => 60000,
            'rpId'             => $rpId,
            'allowCredentials' => $allowCredentials,
            'userVerification' => 'required',
            'expires_in'       => self::LOGIN_CHALLENGE_TTL,
        ]);
    }

    public function login(Request $request)
    {
        $request->validate([
            'credential_id'     => 'required|string',
            'authenticator_data' => 'required|string',
            'client_data_json'  => 'required|string',
            'signature'         => 'required|string',
        ]);

        $ip = $request->ip();

        // Bloqueo permanente por IP
        $bloqueo = BloqueoIp::where('DIRECCION_IP', $ip)->first();
        if ($bloqueo && $bloqueo->BLOQUEO_PERMANENTE) {
            return response()->json(['error' => 'Su dirección IP ha sido bloqueada permanentemente.'], 403);
        }

        // Rate limiting
        $throttleKey = 'webauthn|' . $ip;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return response()->json(['error' => "Demasiados intentos. Intente en {$seconds} segundos."], 429);
        }

        // TTL explícito (LOGIN_CHALLENGE_TTL), independiente de la vida de la
        // sesión. El cliente descarta los suyos antes (expires_in menos margen),
        // así que un challenge pre-cargado vigente siempre pasa este umbral.
        $challengeData = $request->session()->pull('webauthn_login_challenge');
        $challenge = is_array($challengeData) ? ($challengeData['bytes'] ?? null) : null;
        if (!$challenge || (now()->timestamp - ($challengeData['issued_at'] ?? 0)) > self::LOGIN_CHALLENGE_TTL) {
            return response()->json(['error' => 'Challenge expirado. Recargue la página.'], 422);
        }

        $credential = WebAuthnCredential::where('credential_id', $request->input('credential_id'))->first();
        if (!$credential) {
            RateLimiter::hit($throttleKey);
            return response()->json(['error' => 'Credencial no encontrada.'], 401);
        }

        // Verificar clientDataJSON
        $clientDataJson = base64_decode($request->input('client_data_json'));
        $clientData     = json_decode($clientDataJson, true);

        if (!$clientData || ($clientData['type'] ?? '') !== 'webauthn.get') {
            RateLimiter::hit($throttleKey);
            return response()->json(['error' => 'Tipo de operación inválido.'], 401);
        }

        $expectedChallenge = rtrim(strtr(base64_encode($challenge), '+/', '-_'), '=');
        if (($clientData['challenge'] ?? '') !== $expectedChallenge) {
            RateLimiter::hit($throttleKey);
            return response()->json(['error' => 'Challenge no coincide.'], 401);
        }

        $expectedOrigin = $request->getSchemeAndHttpHost();
        if (($clientData['origin'] ?? '') !== $expectedOrigin) {
            RateLimiter::hit($throttleKey);
            return response()->json(['error' => 'Origen no válido.'], 401);
        }

        // Verificar firma
        $authenticatorData = base64_decode($request->input('authenticator_data'));
        $clientDataHash    = hash('sha256', $clientDataJson, true);
        $signedData        = $authenticatorData . $clientDataHash;
        $signature         = base64_decode($request->input('signature'));

        $pubKey = openssl_pkey_get_public($credential->public_key_pem);
        if (!$pubKey) {
            return response()->json(['error' => 'Error procesando la credencial.'], 500);
        }

        $valid = openssl_verify($signedData, $signature, $pubKey, OPENSSL_ALGO_SHA256);

        if ($valid !== 1) {
            RateLimiter::hit($throttleKey);
            $this->registrarFalloBd($ip, $bloqueo);
            return response()->json(['error' => 'Verificación biométrica fallida.'], 401);
        }

        // Actualizar counter (replay protection)
        $newCounter = unpack('N', substr($authenticatorData, 33, 4))[1] ?? 0;
        if ($newCounter > 0 && $newCounter <= $credential->counter) {
            return response()->json(['error' => 'Posible ataque de replay detectado.'], 401);
        }
        $credential->counter = $newCounter;
        $credential->save();

        // ─── Iniciar sesión (replica LoginController::login) ─────────
        $user = Usuario::find($credential->ID_USUARIO);
        if (!$user) {
            return response()->json(['error' => 'Usuario no encontrado.'], 401);
        }

        if ($user->ESTATUS === 'INACTIVO') {
            return response()->json(['error' => 'Usuario inactivo. Contacte al administrador.'], 403);
        }

        Auth::login($user);

        try {
            $sessionToken = bin2hex(random_bytes(32));
        } catch (\Exception $e) {
            $sessionToken = md5(uniqid(rand(), true));
        }

        $user->SESSION_TOKEN = $sessionToken;
        $user->save();

        $request->session()->regenerate();
        $request->session()->put('current_session_token', $sessionToken);
        $request->session()->save();

        RateLimiter::clear($throttleKey);
        if ($bloqueo) {
            $bloqueo->CANTIDAD_INTENTOS = 0;
            $bloqueo->save();
        }

        // route() y no URLs a mano: el LoginController resuelve el mismo destino igual,
        // y con rutas literales un cambio en routes/web.php dejaba el login biométrico
        // apuntando a una URL inexistente sin que nada avisara.
        $redirect = $user->REQUIERE_CAMBIO_CLAVE ? route('password.change') : route('menu');

        // clave_v igual que en el login con contraseña, pero aquí sirve para lo contrario:
        // la huella NO prueba que el usuario sepa la clave actual, así que el cliente la
        // usa para TIRAR el candado de acceso sin internet si ya no corresponde (típico:
        // un admin cambió la clave y este teléfono solo entra con huella).
        return response()->json([
            'success'  => true,
            'redirect' => $redirect,
            'clave_v'  => $user->claveVersion(),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /**
     * Dominio al que quedan atadas las huellas.
     *
     * Por defecto es el host de la petición, que funciona mientras se entre SIEMPRE por
     * la misma URL. Con dos formas de llegar (con y sin www, un dominio nuevo, una IP)
     * las credenciales se parten en dos juegos incompatibles. config/webauthn.php permite
     * fijarlo al dominio registrable para que deje de depender de por dónde entren.
     */
    private function getRpId(Request $request): string
    {
        $fijado = config('webauthn.rp_id');

        return is_string($fijado) && $fijado !== '' ? $fijado : $request->getHost();
    }

    private function spkiToPem(string $spkiDer): string
    {
        $base64 = base64_encode($spkiDer);
        $pem    = "-----BEGIN PUBLIC KEY-----\n";
        $pem   .= chunk_split($base64, 64, "\n");
        $pem   .= "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    private function registrarFalloBd(string $ip, ?BloqueoIp $bloqueo): void
    {
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
    }
}
