<?php

namespace Tests\Feature;

use App\Models\BloqueoIp;
use App\Models\Usuario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Los tres frenos de BloqueoIp: el EQUIPO (cookie del navegador) a los FALLOS_EQUIPO, la
 * CUENTA unos minutos a los FALLOS_CUENTA, y la IP solo ante un ataque masivo (FALLOS_IP
 * sobre CUENTAS_IP cuentas). La oficina sale por una IP: lo que haga un equipo no puede
 * dejar fuera a los demás. Las filas van en la transacción de la prueba; la caché no, y se
 * limpia en tearDown. Cada prueba usa su propia IP, equipos y cuentas.
 */
class BloqueoIpPorCuentaTest extends MySqlTestCase
{
    private const CLAVE = 'Clave#123456';

    private string $ip;
    private array $equipos = [];
    private array $cuentas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ip = '10.250.' . random_int(0, 255) . '.' . random_int(1, 254);
    }

    protected function tearDown(): void
    {
        // Borrar la fila olvida también quién había fallado (BloqueoIp::booted).
        BloqueoIp::whereIn('DIRECCION_IP', [$this->ip, ...$this->equipos])->get()->each->delete();
        foreach ($this->cuentas as $cuenta) {
            RateLimiter::clear('login_cuenta:' . $cuenta);
            foreach ($this->equipos as $equipo) {
                Cache::forget('bloqueo_conocido:' . $equipo . ':' . $cuenta);
            }
        }
        parent::tearDown();
    }

    public function test_el_equipo_que_falla_queda_bloqueado_y_los_demas_de_la_oficina_entran(): void
    {
        $equipo = $this->equipo();
        for ($i = 0; $i < BloqueoIp::FALLOS_EQUIPO; $i++) {
            $this->entrar($this->cuenta(), 'mala', $equipo)->assertStatus(422);
        }

        $ana = $this->usuario();
        $this->entrar($ana->CORREO_ELECTRONICO, self::CLAVE, $equipo)
            ->assertStatus(422)->assertJsonFragment(['message' => 'Este equipo fue bloqueado por demasiadas claves incorrectas. Contacte al administrador.']);

        // Misma IP, otro navegador: entra.
        $this->entrar($ana->CORREO_ELECTRONICO, self::CLAVE, $this->equipo())->assertOk();
        $this->assertFalse((bool) $this->fila(BloqueoIp::TIPO_IP, $this->ip)?->BLOQUEO_PERMANENTE);
    }

    public function test_la_cuenta_se_frena_aunque_cambien_de_equipo(): void
    {
        $victima = $this->usuario();
        for ($i = 0; $i < BloqueoIp::FALLOS_CUENTA; $i++) {
            $this->entrar($victima->CORREO_ELECTRONICO, 'mala', $this->equipo())->assertStatus(422);
        }

        $r = $this->entrar($victima->CORREO_ELECTRONICO, self::CLAVE, $this->equipo())->assertStatus(422);
        $this->assertStringStartsWith('Demasiadas claves incorrectas para esta cuenta.', $r->json('message'));

        $this->entrar($this->usuario()->CORREO_ELECTRONICO, self::CLAVE, $this->equipo())->assertOk();
    }

    public function test_el_equipo_donde_la_cuenta_ya_entro_no_se_frena(): void
    {
        // Si no, cualquiera dejaría fuera al dueño con FALLOS_CUENTA claves malas cada rato.
        $duena = $this->usuario();
        $suyo = $this->equipo();
        $this->entrar($duena->CORREO_ELECTRONICO, self::CLAVE, $suyo)->assertOk();
        for ($i = 0; $i < BloqueoIp::FALLOS_CUENTA; $i++) {
            $this->entrar($duena->CORREO_ELECTRONICO, 'mala', $this->equipo())->assertStatus(422);
        }

        $this->entrar($duena->CORREO_ELECTRONICO, self::CLAVE, $this->equipo())->assertStatus(422);
        $this->entrar($duena->CORREO_ELECTRONICO, self::CLAVE, $suyo)->assertOk();
    }

    public function test_la_ip_solo_se_bloquea_con_muchas_cuentas_distintas(): void
    {
        // FALLOS_IP fallos sobre una cuenta menos de las necesarias: no basta.
        for ($i = 0; $i < BloqueoIp::FALLOS_IP; $i++) {
            BloqueoIp::registrarFallo($this->ip, $this->equipo(), 'pocas' . ($i % (BloqueoIp::CUENTAS_IP - 1)));
        }
        $this->assertFalse($this->fila(BloqueoIp::TIPO_IP, $this->ip)->BLOQUEO_PERMANENTE);

        // Una cuenta más llega a CUENTAS_IP: bloqueada, y para todos los equipos.
        $motivo = BloqueoIp::registrarFallo($this->ip, $this->equipo(), 'una-mas');
        $this->assertSame('Su conexión fue bloqueada por seguridad. Contacte al administrador.', $motivo);
        $this->entrar($this->usuario()->CORREO_ELECTRONICO, self::CLAVE, $this->equipo())->assertStatus(422);

        // Entrar no lo libera: es permanente hasta que un administrador lo borre.
        BloqueoIp::registrarExito($this->ip, $this->equipo(), 'una-mas');
        $this->assertTrue($this->fila(BloqueoIp::TIPO_IP, $this->ip)->BLOQUEO_PERMANENTE);
    }

    public function test_entrar_descuenta_solo_los_fallos_de_esa_cuenta(): void
    {
        $equipo = $this->equipo();
        $ana = $this->usuario();
        $this->entrar($ana->CORREO_ELECTRONICO, 'mala', $equipo)->assertStatus(422);
        $this->entrar('victima@local.test', 'mala1', $equipo)->assertStatus(422);
        $this->entrar('victima@local.test', 'mala2', $equipo)->assertStatus(422);
        $this->cuentas[] = 'victima@local.test';

        $this->entrar($ana->CORREO_ELECTRONICO, self::CLAVE, $equipo)->assertOk();

        // Quedan los dos contra la víctima, en el equipo y en la IP.
        $this->assertSame(2, $this->fila(BloqueoIp::TIPO_EQUIPO, $equipo)->CANTIDAD_INTENTOS);
        $this->assertSame(2, $this->fila(BloqueoIp::TIPO_IP, $this->ip)->CANTIDAD_INTENTOS);
    }

    public function test_desbloquear_olvida_los_fallos_y_el_panel_lo_muestra(): void
    {
        $equipo = $this->equipo();
        for ($i = 0; $i < BloqueoIp::FALLOS_EQUIPO; $i++) {
            BloqueoIp::registrarFallo($this->ip, $equipo, 'a' . $i);
        }
        $fila = $this->fila(BloqueoIp::TIPO_EQUIPO, $equipo);
        $this->assertTrue(BloqueoIp::bloqueadas()->whereKey($fila->getKey())->exists());
        $this->assertSame('Equipo · ' . $this->ip, $fila->etiqueta);

        $fila->delete();
        BloqueoIp::registrarFallo($this->ip, $equipo, 'b');

        // Sin olvidar, los fallos viejos volverían a sumarse y lo bloquearían de nuevo.
        $this->assertSame(1, $this->fila(BloqueoIp::TIPO_EQUIPO, $equipo)->CANTIDAD_INTENTOS);
        $this->assertNull(BloqueoIp::motivoBloqueo($this->ip, $equipo));
    }

    public function test_sin_cookie_recibe_equipo_pero_no_cuenta_como_equipo(): void
    {
        // Un programa sin cookies estrenaría una fila por intento; a ese lo frenan cuenta e IP.
        $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
            ->postJson(route('login.post'), ['login_identifier' => $this->cuenta(), 'password' => 'mala'])
            ->assertStatus(422)->assertCookie(BloqueoIp::COOKIE_EQUIPO);

        $this->assertFalse(BloqueoIp::where(['TIPO' => BloqueoIp::TIPO_EQUIPO, 'ULTIMA_IP' => $this->ip])->exists());
        $this->assertSame(1, $this->fila(BloqueoIp::TIPO_IP, $this->ip)->CANTIDAD_INTENTOS);
    }

    private function entrar(string $correo, string $clave, string $equipo)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->ip])
            ->withCredentials() // sin esto postJson no manda cookies
            ->withCookie(BloqueoIp::COOKIE_EQUIPO, $equipo)
            ->postJson(route('login.post'), ['login_identifier' => $correo, 'password' => $clave]);
    }

    private function fila(string $tipo, string $origen): ?BloqueoIp
    {
        return BloqueoIp::where(['TIPO' => $tipo, 'DIRECCION_IP' => $origen])->first();
    }

    private function equipo(): string
    {
        return $this->equipos[] = bin2hex(random_bytes(16));
    }

    private function cuenta(): string
    {
        return $this->cuentas[] = 'nadie.' . strtolower(Str::random(8)) . '@local.test';
    }

    private function usuario(): Usuario
    {
        $correo = $this->cuentas[] = 'prueba.bloqueo.' . strtolower(Str::random(8)) . '@local.test';
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA BLOQUEO',
            'CORREO_ELECTRONICO'    => $correo,
            'PASSWORD_HASH'         => Hash::make(self::CLAVE),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => '',
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
