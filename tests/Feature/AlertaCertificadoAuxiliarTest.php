<?php

namespace Tests\Feature;

use App\Http\Controllers\DashboardController;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * El certificado de un AUXILIAR tiene que avisar igual que el de un equipo.
 *
 * El auxiliar no guarda sus papeles en la tabla `documentacion` sino en columnas propias
 * (LINK_CERTIFICADO + FECHA_VENCIMIENTO_CERT), y el panel de alertas solo miraba equipos:
 * seis certificados de auxiliares estaban fuera del radar, sin que nadie fuera a enterarse
 * de su vencimiento salvo abriendo la ficha a mano.
 *
 * Entra al panel con type_key 'adicional' A PROPOSITO: para el usuario ese papel se llama
 * "Certificado" tanto en el equipo (donde por dentro es el documento adicional) como en el
 * auxiliar, asi que la casilla alertas.ver.certificado gobierna los dos. Estas pruebas fijan
 * justo eso, que es lo que se rompería si alguien le pusiera una clave propia.
 */
class AlertaCertificadoAuxiliarTest extends MySqlTestCase
{
    /** Auxiliar de usar y tirar; la transacción del caso lo revierte al terminar. */
    private function auxiliarConCertificado(string $vence, string $estado = 'OPERATIVO'): EquipoAuxiliar
    {
        // Frente normal: los ESPECIAL quedan fuera del panel por decisión del propio panel.
        $frente = FrenteTrabajo::where('TIPO_FRENTE', '!=', 'ESPECIAL')->first();

        return EquipoAuxiliar::create([
            'TIPO'                   => 'CISTERNA',
            'MARCA'                  => 'PRUEBA',
            'MODELO'                 => 'CERT-TEST',
            'SERIAL'                 => 'AUXCERT' . random_int(100000, 999999),
            'ESTADO_OPERATIVO'       => $estado,
            'ID_FRENTE_ACTUAL'       => $frente?->ID_FRENTE,
            'LINK_CERTIFICADO'       => '/storage/google/certificado-de-prueba',
            'FECHA_VENCIMIENTO_CERT' => $vence,
        ]);
    }

    /** Usuario real que ve todo, para no depender de a quién le toque el primer registro. */
    private function usuarioQueVeTodo(): Usuario
    {
        $u = Usuario::whereNotNull('PERMISOS')->get()->first(function ($usr) {
            $p = array_map('strtolower', is_array($usr->PERMISOS) ? $usr->PERMISOS : []);
            foreach ($p as $c) {
                if (str_starts_with($c, 'alertas.ver.')) return false;
            }
            return in_array('super.admin', $p, true);
        });

        $this->assertNotNull($u, 'hace falta un super.admin sin claves alertas.ver.* para medir el universo');

        return $u;
    }

    private function alertasDe(Usuario $u)
    {
        $this->actingAs($u);

        return (new DashboardController())->generateAlertsList();
    }

    public function test_un_certificado_vencido_de_auxiliar_sale_en_el_panel(): void
    {
        $aux = $this->auxiliarConCertificado(now()->subDays(5)->toDateString());

        $alerta = $this->alertasDe($this->usuarioQueVeTodo())
            ->first(fn ($a) => ($a->origen ?? null) === 'auxiliar' && $a->equipo->ID_AUXILIAR === $aux->ID_AUXILIAR);

        $this->assertNotNull($alerta, 'el certificado vencido del auxiliar no llegó al panel');
        $this->assertSame('expired', $alerta->status);
        $this->assertSame('adicional', $alerta->type_key, 'debe compartir tipo con el Certificado del equipo');
        $this->assertSame('Certificado Vencido', $alerta->label);
        $this->assertNull($alerta->gestionado_por, 'la gestión de documentos es solo de equipos');
    }

    public function test_uno_por_vencer_avisa_y_uno_vigente_no(): void
    {
        $porVencer = $this->auxiliarConCertificado(now()->addDays(10)->toDateString());
        $vigente   = $this->auxiliarConCertificado(now()->addDays(200)->toDateString());

        $alertas = $this->alertasDe($this->usuarioQueVeTodo());

        $delPorVencer = $alertas->first(fn ($a) => ($a->origen ?? null) === 'auxiliar'
            && $a->equipo->ID_AUXILIAR === $porVencer->ID_AUXILIAR);
        $delVigente = $alertas->first(fn ($a) => ($a->origen ?? null) === 'auxiliar'
            && $a->equipo->ID_AUXILIAR === $vigente->ID_AUXILIAR);

        $this->assertNotNull($delPorVencer, 'a 10 días del vencimiento tiene que avisar');
        $this->assertSame('warning', $delPorVencer->status);
        $this->assertNull($delVigente, 'a 200 días no hay nada que avisar');
    }

    public function test_un_auxiliar_desincorporado_no_alerta(): void
    {
        $aux = $this->auxiliarConCertificado(now()->subDays(30)->toDateString(), 'DESINCORPORADO');

        $alerta = $this->alertasDe($this->usuarioQueVeTodo())
            ->first(fn ($a) => ($a->origen ?? null) === 'auxiliar' && $a->equipo->ID_AUXILIAR === $aux->ID_AUXILIAR);

        $this->assertNull($alerta, 'un auxiliar fuera de servicio no debe generar alertas');
    }

    public function test_la_casilla_de_certificado_gobierna_equipos_y_auxiliares(): void
    {
        $aux = $this->auxiliarConCertificado(now()->subDays(5)->toDateString());

        $base = $this->usuarioQueVeTodo();

        // Con la casilla del certificado: el auxiliar entra.
        $conCertificado = new Usuario($base->getAttributes());
        $conCertificado->exists = true;
        $conCertificado->PERMISOS = ['alertas.ver.certificado'];

        $vistas = $this->alertasDe($conCertificado);
        $this->assertNotNull(
            $vistas->first(fn ($a) => ($a->origen ?? null) === 'auxiliar' && $a->equipo->ID_AUXILIAR === $aux->ID_AUXILIAR),
            'alertas.ver.certificado tiene que mostrar también el certificado del auxiliar'
        );
        $this->assertTrue(
            $vistas->every(fn ($a) => $a->type_key === 'adicional'),
            'con esa única casilla no debe colarse ningún otro tipo'
        );

        // Con otra casilla cualquiera: el auxiliar se queda fuera.
        $soloPoliza = new Usuario($base->getAttributes());
        $soloPoliza->exists = true;
        $soloPoliza->PERMISOS = ['alertas.ver.poliza'];

        $this->assertNull(
            $this->alertasDe($soloPoliza)
                ->first(fn ($a) => ($a->origen ?? null) === 'auxiliar' && $a->equipo->ID_AUXILIAR === $aux->ID_AUXILIAR),
            'quien solo ve pólizas no debe ver certificados de auxiliares'
        );
    }
}
