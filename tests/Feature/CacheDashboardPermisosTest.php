<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * Cambiar las casillas de alertas de un usuario tiene que verse YA en su tablero.
 *
 * El payload de /menu se cachea 10 minutos con clave dashboard_user_data_{id}_v{ver}. Esa
 * versión la bumpean los observers de Equipo/Documentacion/FrenteTrabajo, y editar un
 * USUARIO no toca ninguna de esas tablas: quien marcaba "ver Pólizas" seguía viendo la
 * lista anterior —con los tipos que ya no le tocaban— y el número del badge tampoco se
 * movía. Se reportó exactamente así: "puse el permiso de pólizas y me salen 6 de ROTC y
 * Certificado".
 *
 * La clave lleva ahora una huella de los permisos y los frentes del usuario.
 */
class CacheDashboardPermisosTest extends MySqlTestCase
{
    private function usuarioDePrueba(): Usuario
    {
        $u = Usuario::all()->first(fn ($usr) => in_array(
            'super.admin',
            array_map('strtolower', is_array($usr->PERMISOS) ? $usr->PERMISOS : []),
            true
        ));
        $this->assertNotNull($u, 'hace falta un super.admin');

        return $u;
    }

    /** Cuenta las alertas que el tablero le entrega al usuario, por el camino real (/menu). */
    private function alertasEnElTablero(Usuario $u): array
    {
        $datos = $this->actingAs($u)->get('/menu')->assertOk()->original->getData();

        return [
            'total' => $datos['totalAlerts'] ?? null,
            'tipos' => collect($datos['expiredList'] ?? [])->groupBy('type_key')->map->count()->toArray(),
        ];
    }

    public function test_al_cambiar_la_casilla_de_alertas_el_tablero_se_rehace(): void
    {
        $u = $this->usuarioDePrueba();

        // 1) Solo pólizas.
        $u->PERMISOS = ['super.admin', 'alertas.ver.poliza'];
        $u->save();
        $soloPoliza = $this->alertasEnElTablero($u->fresh());

        $this->assertSame(['poliza'], array_keys($soloPoliza['tipos']),
            'con la casilla de pólizas no debe colarse ningún otro tipo');

        // 2) Se le cambia a solo certificados: el tablero tiene que reflejarlo en la
        //    siguiente carga, no diez minutos después.
        $u->PERMISOS = ['super.admin', 'alertas.ver.certificado'];
        $u->save();
        $soloCertificado = $this->alertasEnElTablero($u->fresh());

        $this->assertSame(['adicional'], array_keys($soloCertificado['tipos']),
            'la caché estaba sirviendo la lista del permiso anterior');
        $this->assertNotSame($soloPoliza['total'], $soloCertificado['total'],
            'el número del badge tampoco puede quedarse congelado');

        // 3) Sin ninguna casilla se vuelve a ver todo.
        $u->PERMISOS = ['super.admin'];
        $u->save();
        $sinCasillas = $this->alertasEnElTablero($u->fresh());

        $this->assertGreaterThan($soloCertificado['total'], $sinCasillas['total'],
            'sin casillas de alertas el usuario ve TODOS los tipos');
        $this->assertGreaterThan(1, count($sinCasillas['tipos']));
    }
}
