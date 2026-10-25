<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Falla;
use App\Models\Usuario;
use App\Models\WebAuthnCredential;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\MySqlTestCase;

/**
 * Arreglos de la auditoría de seguridad del 25-10-2026:
 *  - Huella: solo se registra justo después de entrar CON CLAVE, el servidor comprueba el
 *    challenge y el origen, no reasigna huellas ajenas y cambiar la clave las borra.
 *  - Papelera de equipos: pide user.delete, como la de auxiliares.
 *  - Copia de Fallas: resta los reportes de frentes bloqueados para el usuario.
 * Corre en la transacción de la prueba.
 */
class SeguridadHuellaPapeleraFallasTest extends MySqlTestCase
{
    private const CLAVE = 'Clave#123456';

    /** Llave pública P-256 (SPKI en base64) cualquiera: el registro solo la valida. */
    private const LLAVE_PUBLICA = 'MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEvL7dtbwrqJnyBr4lTznC8Sq6vUsJrZN1ts7e+agvjqHdg9ZGh4kEKkbbRXGM/4IedZ591RXIROpe9wJgG+vlOA==';

    public function test_sin_entrar_con_clave_no_se_puede_registrar_una_huella(): void
    {
        // Una sesión abierta (p. ej. robada) sin haber escrito la clave en ella.
        $this->actingAs($this->usuario())->postJson('/webauthn/register-options')->assertStatus(403);
    }

    public function test_tras_entrar_con_clave_registra_y_solo_una_vez(): void
    {
        $u = $this->usuario();
        $this->entrar($u);

        $this->registrar(fn ($cd) => $cd)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, WebAuthnCredential::where('ID_USUARIO', $u->ID_USUARIO)->count());

        // La ventana se cierra con el primer registro.
        $this->postJson('/webauthn/register-options')->assertStatus(403);
    }

    public function test_rechaza_challenge_tipo_u_origen_falsos(): void
    {
        $this->entrar($this->usuario());

        foreach ([
            fn ($cd) => ['challenge' => 'otro'] + $cd,
            fn ($cd) => ['type' => 'webauthn.get'] + $cd,
            fn ($cd) => ['origin' => 'https://otro.sitio'] + $cd,
        ] as $falsear) {
            $this->registrar($falsear)->assertStatus(422);
        }
        $this->assertSame(0, WebAuthnCredential::where('ID_USUARIO', auth()->id())->count());
    }

    public function test_no_reasigna_la_huella_de_otra_persona(): void
    {
        $otro = $this->usuario();
        $id = 'AJENA-' . Str::random(12);
        $this->credencial($otro->ID_USUARIO, $id);

        $this->entrar($this->usuario());
        $this->registrar(fn ($cd) => $cd, $id)->assertStatus(422);

        $this->assertSame((int) $otro->ID_USUARIO, (int) WebAuthnCredential::where('credential_id', $id)->value('ID_USUARIO'));
    }

    public function test_cambiar_la_clave_borra_las_huellas(): void
    {
        $u = $this->usuario();
        $this->credencial($u->ID_USUARIO, 'MIA-' . Str::random(12));

        $u->establecerClave('Nueva#654321');
        $u->save();

        $this->assertSame(0, WebAuthnCredential::where('ID_USUARIO', $u->ID_USUARIO)->count());
    }

    public function test_papelera_de_equipos_pide_permiso(): void
    {
        $this->actingAs($this->usuario())->getJson(route('equipos.papelera'))->assertForbidden();
        $this->actingAs($this->usuario('user.delete'))->getJson(route('equipos.papelera'))->assertOk();
    }

    public function test_la_copia_de_fallas_resta_los_frentes_bloqueados(): void
    {
        $frente = DB::table('frentes_trabajo')->whereNull('deleted_at')->value('ID_FRENTE')
            ?? $this->markTestSkipped('No hay frentes.');
        $e = Equipo::create([
            'MARCA' => 'PRUEBA', 'MODELO' => 'PRUEBA-BLOQ', 'ANIO' => 2026, 'ESTADO_OPERATIVO' => 'OPERATIVO',
            'SERIAL_CHASIS' => 'TEST-BLOQ-' . uniqid(),
        ]);
        $e->ID_FRENTE_ACTUAL = $frente; // fuera de $fillable a propósito
        $e->save();
        $u = $this->usuario();
        $falla = Falla::create([
            'CODIGO_REPORTE' => 'RF-BLOQ-' . Str::random(6), 'TIPO_REPORTE' => 'corto', 'ACTIVO_TIPO' => 'equipo', 'ACTIVO_ID' => $e->ID_EQUIPO,
            'DESCRIPCION_AVERIA' => 'PRUEBA BLOQUEADOS', 'ESTADO_REPORTE' => 'abierto',
            'ID_USUARIO_REPORTA' => $u->ID_USUARIO, 'NOMBRE_REPORTA' => 'PRUEBA', 'FECHA_EMISION' => now(),
        ]);
        $ids = fn (Usuario $quien) => collect($this->actingAs($quien)->getJson('/sync/fallas')->assertOk()->json('datos.fallas'))->pluck('id');

        $this->assertContains($falla->ID_FALLA, $ids($u));

        $u->ID_FRENTE_BLOQUEADO = (string) $frente;
        $u->save();
        $this->assertNotContains($falla->ID_FALLA, $ids($u->fresh()));
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────

    /** Entra con clave por el login real: es lo que abre la ventana de registro. */
    private function entrar(Usuario $u): void
    {
        $this->postJson(route('login.post'), ['login_identifier' => $u->CORREO_ELECTRONICO, 'password' => self::CLAVE])
            ->assertOk()->assertJson(['success' => true]);
    }

    /** Pide las opciones y responde como el navegador, con $falsear aplicado al clientDataJSON. */
    private function registrar(callable $falsear, ?string $credentialId = null)
    {
        $challenge = $this->postJson('/webauthn/register-options')->assertOk()->json('challenge');
        $clientData = $falsear([
            'type'      => 'webauthn.create',
            'challenge' => rtrim(strtr($challenge, '+/', '-_'), '='),
            'origin'    => rtrim(url('/'), '/'),
        ]);

        return $this->postJson('/webauthn/register', [
            'credential_id'    => $credentialId ?? 'NUEVA-' . Str::random(12),
            'public_key_spki'  => self::LLAVE_PUBLICA,
            'client_data_json' => base64_encode(json_encode($clientData)),
        ]);
    }

    private function credencial(int $idUsuario, string $id): void
    {
        WebAuthnCredential::create([
            'ID_USUARIO' => $idUsuario, 'credential_id' => $id,
            'public_key_pem' => 'x', 'nombre_dispositivo' => 'Android', 'counter' => 0, 'created_at' => now(),
        ]);
    }

    /** Usuario de prueba creado DESDE CERO (nunca replicate) y solo dentro de la transacción. */
    private function usuario(string $permisos = ''): Usuario
    {
        $id = DB::table('usuarios')->insertGetId([
            'NOMBRE_COMPLETO'       => 'PRUEBA SEGURIDAD',
            'CORREO_ELECTRONICO'    => 'prueba.seguridad.' . strtolower(Str::random(8)) . '@local.test',
            'PASSWORD_HASH'         => Hash::make(self::CLAVE),
            'ID_ROL'                => DB::table('roles')->value('ID_ROL'),
            'NIVEL_ACCESO_EQUIPOS'  => 1,
            'NIVEL_ACCESO_ALMACEN'  => 1,
            'ESTATUS'               => 'ACTIVO',
            'REQUIERE_CAMBIO_CLAVE' => 0,
            'PERMISOS'              => $permisos,
            'created_at'            => now(),
            'updated_at'            => now(),
        ]);

        return Usuario::find($id);
    }
}
