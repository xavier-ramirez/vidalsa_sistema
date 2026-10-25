<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Bloqueos por claves equivocadas. Fuente única: el login con clave y el de huella pasan por
 * registrarFallo/registrarExito, y el panel de Usuarios lista y libera con bloqueadas().
 *
 * Cada fallo cuenta en tres sitios, y cada uno frena solo a quien toca:
 *   · EQUIPO (el navegador, por su cookie): FALLOS_EQUIPO → bloqueado hasta que lo libere un
 *     administrador. Frena al que se equivoca sin tocar al resto de la oficina.
 *   · CUENTA: FALLOS_CUENTA seguidos → esa cuenta frenada MINUTOS_CUENTA, venga de donde
 *     venga. Protege a la víctima aunque el atacante borre la cookie o cambie de equipo. No
 *     frena a un equipo donde esa cuenta ya entró bien: si no, cualquiera dejaría fuera al
 *     dueño con 5 claves malas cada 15 minutos.
 *   · IP: solo un ataque masivo, FALLOS_IP sobre al menos CUENTAS_IP cuentas distintas sin
 *     una hora de pausa → bloqueada hasta que la liberen. La oficina sale a internet por UNA
 *     IP: bloquearla deja fuera a todos, así que no basta con equivocarse unas cuantas veces.
 *
 * Entrar bien descuenta SOLO los fallos de esa cuenta. Antes ponía el contador en 0, y quien
 * probaba claves de otro entraba con la suya y volvía a empezar sin que el bloqueo llegara.
 *
 * Una fila por origen: TIPO 'IP' (DIRECCION_IP = la IP) o 'EQUIPO' (DIRECCION_IP = id de la
 * cookie, ULTIMA_IP = desde dónde se vio). Quién falló va en la caché (fallosPorCuenta), y se
 * toca con un candado por origen: dos intentos a la vez no se pisan la cuenta.
 */
class BloqueoIp extends Model
{
    use HasFactory;

    protected $table = 'bloqueo_ip';
    protected $primaryKey = 'ID_BLOQUEO';

    protected $fillable = [
        'TIPO',
        'DIRECCION_IP',
        'ULTIMA_IP',
        'CANTIDAD_INTENTOS',
        'ULTIMO_INTENTO',
        'BLOQUEO_PERMANENTE',
    ];

    protected $casts = [
        'ULTIMO_INTENTO' => 'datetime',
        'BLOQUEO_PERMANENTE' => 'boolean',
    ];

    public const TIPO_IP = 'IP';
    public const TIPO_EQUIPO = 'EQUIPO';

    public const FALLOS_EQUIPO = 10;
    public const FALLOS_CUENTA = 5;
    public const MINUTOS_CUENTA = 15;
    public const FALLOS_IP = 30;
    public const CUENTAS_IP = 5;

    /** Cuánto se recuerda de quién falló (se renueva con cada fallo). */
    private const MEMORIA = [self::TIPO_EQUIPO => 24 * 60, self::TIPO_IP => 60];

    /** Cookie con el id del navegador. Dura para siempre: es lo que se bloquea. */
    public const COOKIE_EQUIPO = 'vidalsa_equipo';

    protected static function booted(): void
    {
        // Liberar (el panel borra la fila) también olvida quién había fallado.
        static::deleted(fn (self $b) => Cache::forget(self::claveFallos($b->TIPO ?? self::TIPO_IP, $b->DIRECCION_IP)));
    }

    /**
     * Id de este navegador, o null si todavía no tiene (se le da uno, lo guarda la respuesta).
     * Sin cookie no se cuenta como equipo: un programa que no las guarda estrenaría un id en
     * cada intento, llenando la tabla sin bloquearse nunca. A ese lo frenan la cuenta y la IP.
     */
    public static function equipo(Request $request): ?string
    {
        $id = (string) $request->cookie(self::COOKIE_EQUIPO);
        if (preg_match('/^[a-f0-9]{32}$/', $id)) {
            return $id;
        }
        Cookie::queue(cookie()->forever(self::COOKIE_EQUIPO, bin2hex(random_bytes(16)), null, null, null, true));
        return null;
    }

    /** El mensaje para la pantalla si este equipo o esta IP están bloqueados; null si no. */
    public static function motivoBloqueo(string $ip, ?string $equipo): ?string
    {
        $tipo = self::where('BLOQUEO_PERMANENTE', true)
            ->where(fn ($q) => $q->where(['TIPO' => self::TIPO_IP, 'DIRECCION_IP' => $ip])
                ->when($equipo, fn ($q) => $q->orWhere(fn ($q) => $q->where(['TIPO' => self::TIPO_EQUIPO, 'DIRECCION_IP' => $equipo]))))
            ->value('TIPO');

        return match ($tipo) {
            self::TIPO_EQUIPO => 'Este equipo fue bloqueado por demasiadas claves incorrectas. Contacte al administrador.',
            self::TIPO_IP     => 'Su conexión fue bloqueada por seguridad. Contacte al administrador.',
            default           => null,
        };
    }

    /** Segundos que le faltan a la cuenta para volver a probar desde este equipo (0 = puede). */
    public static function cuentaFrenada(string $cuenta, ?string $equipo): int
    {
        if ($equipo && Cache::has(self::claveConocido($equipo, $cuenta))) {
            return 0;
        }
        return RateLimiter::tooManyAttempts(self::claveCuenta($cuenta), self::FALLOS_CUENTA)
            ? RateLimiter::availableIn(self::claveCuenta($cuenta))
            : 0;
    }

    /**
     * Cuenta un fallo de $cuenta (correo, o "huella:<credencial>") desde ese equipo e IP.
     * Devuelve el mensaje de bloqueo si con este fallo quedó bloqueado algo.
     */
    public static function registrarFallo(string $ip, ?string $equipo, string $cuenta): ?string
    {
        RateLimiter::hit(self::claveCuenta($cuenta), self::MINUTOS_CUENTA * 60);

        if ($equipo) {
            self::contar(self::TIPO_EQUIPO, $equipo, $ip, $cuenta,
                fn (array $f) => array_sum($f) >= self::FALLOS_EQUIPO);
        }
        self::contar(self::TIPO_IP, $ip, null, $cuenta,
            fn (array $f) => array_sum($f) >= self::FALLOS_IP && count($f) >= self::CUENTAS_IP);

        return self::motivoBloqueo($ip, $equipo);
    }

    /**
     * Entró: se descuentan los fallos de esas cuentas (no los de las demás) y el equipo queda
     * como conocido de cada una, para que cuentaFrenada no lo frene.
     */
    public static function registrarExito(string $ip, ?string $equipo, string ...$cuentas): void
    {
        foreach ($cuentas as $cuenta) {
            RateLimiter::clear(self::claveCuenta($cuenta));
            if ($equipo) {
                Cache::forever(self::claveConocido($equipo, $cuenta), true);
            }
        }
        foreach (array_filter([self::TIPO_EQUIPO => $equipo, self::TIPO_IP => $ip]) as $tipo => $origen) {
            self::conCandado($tipo, $origen, function () use ($tipo, $origen, $cuentas) {
                $fila = self::where(['TIPO' => $tipo, 'DIRECCION_IP' => $origen])->first();
                if (!$fila || $fila->BLOQUEO_PERMANENTE) {
                    return;
                }
                $fallos = array_diff_key(self::fallosPorCuenta($tipo, $origen), array_flip($cuentas));
                self::recordar($tipo, $origen, $fallos);
                $fila->CANTIDAD_INTENTOS = array_sum($fallos);
                $fila->save();
            });
        }
    }

    /** Lo bloqueado (equipos e IPs), más reciente primero: el panel de Usuarios. */
    public function scopeBloqueadas($query)
    {
        return $query->where('BLOQUEO_PERMANENTE', true)->orderBy('ULTIMO_INTENTO', 'desc');
    }

    /** Cómo se muestra en el panel: la IP, o el equipo con la última IP desde la que entró. */
    public function getEtiquetaAttribute(): string
    {
        return $this->TIPO === self::TIPO_EQUIPO
            ? 'Equipo · ' . ($this->ULTIMA_IP ?: 'IP desconocida')
            : $this->DIRECCION_IP;
    }

    private static function contar(string $tipo, string $origen, ?string $ultimaIp, string $cuenta, callable $bloquea): void
    {
        self::conCandado($tipo, $origen, function () use ($tipo, $origen, $ultimaIp, $cuenta, $bloquea) {
            $fallos = self::fallosPorCuenta($tipo, $origen);
            $fallos[$cuenta] = ($fallos[$cuenta] ?? 0) + 1;
            self::recordar($tipo, $origen, $fallos);

            // createOrFirst: si otro intento la creó en el mismo instante, usa esa (índice único).
            $fila = self::createOrFirst(['TIPO' => $tipo, 'DIRECCION_IP' => $origen], ['ULTIMO_INTENTO' => now()]);
            $fila->CANTIDAD_INTENTOS = array_sum($fallos);
            $fila->ULTIMO_INTENTO = now();
            if ($ultimaIp !== null) {
                $fila->ULTIMA_IP = $ultimaIp;
            }
            if ($bloquea($fallos)) {
                $fila->BLOQUEO_PERMANENTE = true;
            }
            $fila->save();
        });
    }

    /** Un intento a la vez por origen: leer y reescribir el mapa de fallos no es atómico. */
    private static function conCandado(string $tipo, string $origen, callable $hacer): void
    {
        Cache::lock('candado:' . self::claveFallos($tipo, $origen), 10)->block(5, $hacer);
    }

    /** @return array<string,int> cuenta → fallos desde ese origen */
    private static function fallosPorCuenta(string $tipo, string $origen): array
    {
        return (array) Cache::get(self::claveFallos($tipo, $origen), []);
    }

    private static function recordar(string $tipo, string $origen, array $fallos): void
    {
        Cache::put(self::claveFallos($tipo, $origen), $fallos, now()->addMinutes(self::MEMORIA[$tipo]));
    }

    private static function claveFallos(string $tipo, string $origen): string
    {
        return 'bloqueo_fallos:' . $tipo . ':' . $origen;
    }

    private static function claveCuenta(string $cuenta): string
    {
        return 'login_cuenta:' . Str::lower($cuenta);
    }

    private static function claveConocido(string $equipo, string $cuenta): string
    {
        return 'bloqueo_conocido:' . $equipo . ':' . Str::lower($cuenta);
    }
}
