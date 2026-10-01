<?php

namespace Tests\Feature;

use App\Models\Documentacion;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\Usuario;
use Tests\MySqlTestCase;

/**
 * "Guardar Cambios" del visor de documentos NO puede borrar lo que no se le mandó.
 *
 * El formulario escribía TODOS los campos de su tipo tomando el que faltara como vacío, así que
 * una petición incompleta —un cliente viejo, un reintento a medias, una SPA que no pintó todos los
 * campos— dejaba la PLACA y el SERIAL_CHASIS en blanco sin que nadie se enterara. Y son justo los
 * dos datos con los que la verificación nocturna comprueba que un PDF es de este vehículo: sin
 * ellos, ese equipo deja de poder verificarse.
 *
 * Lo que sí debe seguir pasando: mandar un campo VACÍO a propósito lo borra. Esa es la forma de
 * limpiar una casilla desde el visor, y distinguir "vacío" de "no vino" es justo el arreglo.
 */
class GuardarMetadataNoBorraTest extends MySqlTestCase
{
    /** super.admin real sin cambio de clave pendiente (si no, el middleware lo desvía). */
    private function usuario(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');

        return $u;
    }

    /** Equipo propio con su documentación; la transacción del caso lo revierte. */
    private function equipo(): Equipo
    {
        $equipo = Equipo::create([
            'MARCA' => 'SINOTRUK', 'MODELO' => 'ZZ4257V324JB1', 'ANIO' => 2026,
            // En MAYUSCULAS ya: updateMetadata las pone en mayusculas, y con un serial en
            // minusculas el test compararia el valor transformado y pasaria por casualidad.
            'SERIAL_CHASIS' => mb_strtoupper('CHASIS-' . uniqid()),
            'SERIAL_DE_MOTOR' => mb_strtoupper('MOTOR-' . uniqid()),
        ]);
        Documentacion::create([
            'ID_EQUIPO'          => $equipo->ID_EQUIPO,
            // Unica: documentacion.PLACA tiene indice UNIQUE y el caso corre contra la base de
            // trabajo. Con una placa escrita a mano, el dia que exista en la base el test se cae.
            'PLACA'              => mb_strtoupper(substr('P' . uniqid(), 0, 8)),
            'NOMBRE_DEL_TITULAR' => 'CONSTRUCTORA VIDALSA 27 C.A.',
            'NRO_DE_DOCUMENTO'   => '123456789012',
        ]);

        return $equipo;
    }

    public function test_una_peticion_incompleta_no_borra_la_placa_ni_el_serial(): void
    {
        $equipo = $this->equipo();
        $antes = [
            'placa'   => $equipo->documentacion->PLACA,
            'titular' => $equipo->documentacion->NOMBRE_DEL_TITULAR,
            'nro'     => $equipo->documentacion->NRO_DE_DOCUMENTO,
            'marca'   => $equipo->MARCA,
            'modelo'  => $equipo->MODELO,
            'chasis'  => $equipo->SERIAL_CHASIS,
            'motor'   => $equipo->SERIAL_DE_MOTOR,
        ];

        // Solo llega el titular: lo que el usuario cambió. Nada más viene en la petición.
        $this->actingAs($this->usuario())
            ->post(route('equipos.updateMetadata', $equipo->ID_EQUIPO), [
                'doc_type' => 'propiedad',
                'titular'  => 'NUEVO TITULAR',
            ])->assertOk();

        $equipo->refresh();
        $doc = $equipo->documentacion->refresh();

        $this->assertSame('NUEVO TITULAR', $doc->NOMBRE_DEL_TITULAR, 'lo que sí se mandó tiene que guardarse');
        $this->assertSame($antes['placa'], $doc->PLACA, 'la PLACA no se mandó: no se toca');
        $this->assertSame($antes['nro'], $doc->NRO_DE_DOCUMENTO, 'el número de documento tampoco');
        $this->assertSame($antes['chasis'], $equipo->SERIAL_CHASIS, 'el SERIAL DE CHASIS no se mandó: no se toca');
        $this->assertSame($antes['motor'], $equipo->SERIAL_DE_MOTOR, 'ni el serial de motor');
        $this->assertSame($antes['marca'], $equipo->MARCA, 'ni la marca');
        $this->assertSame($antes['modelo'], $equipo->MODELO, 'ni el modelo');
    }

    public function test_mandar_un_campo_vacio_si_lo_borra(): void
    {
        $equipo = $this->equipo();

        // El formulario completo, con la placa y el número limpiados a propósito por el usuario.
        $this->actingAs($this->usuario())
            ->post(route('equipos.updateMetadata', $equipo->ID_EQUIPO), [
                'doc_type'      => 'propiedad',
                'titular'       => 'OTRO TITULAR',
                'placa'         => '',
                'nro_documento' => '',
                'marca'         => 'SINOTRUK',
                'modelo'        => 'ZZ4257V324JB1',
                'serial_chasis' => $equipo->SERIAL_CHASIS,
                'serial_motor'  => '',
            ])->assertOk();

        $equipo->refresh();
        $doc = $equipo->documentacion->refresh();

        $this->assertNull($doc->PLACA, 'mandar la placa vacía SÍ la borra');
        $this->assertNull($doc->NRO_DE_DOCUMENTO, 'y el número de documento también');
        $this->assertNull($equipo->SERIAL_DE_MOTOR, 'el serial de motor admite nulo y se borra');
        $this->assertSame('OTRO TITULAR', $doc->NOMBRE_DEL_TITULAR);
        // MARCA, MODELO y SERIAL_CHASIS no admiten NULL en la tabla: siguen con su valor.
        $this->assertSame('SINOTRUK', $equipo->MARCA);
    }

    public function test_las_columnas_que_no_admiten_nulo_se_vacian_con_cadena_vacia(): void
    {
        $equipo = $this->equipo();

        $this->actingAs($this->usuario())
            ->post(route('equipos.updateMetadata', $equipo->ID_EQUIPO), [
                'doc_type' => 'propiedad',
                'marca'    => '',   // MARCA es NOT NULL: se guarda '' y no revienta
            ])->assertOk();

        $equipo->refresh();
        $this->assertSame('', $equipo->MARCA, 'vaciar una columna NOT NULL guarda cadena vacía');
        $this->assertNotEmpty($equipo->SERIAL_CHASIS, 'y no arrastra a las que no se mandaron');
    }

    /**
     * Lo mismo para los AUXILIARES, que tenían el mismo fallo: su SERIAL es la columna con la que
     * CargaMasivaDocumentos comprueba de qué auxiliar es un PDF.
     */
    public function test_en_auxiliares_una_peticion_incompleta_tampoco_borra_el_serial(): void
    {
        $aux = EquipoAuxiliar::create([
            'TIPO'           => 'MAQUINA DE SOLDAR',
            'MARCA'          => 'LINCOLN',
            'MODELO'         => 'SCD500',
            'SERIAL'         => mb_strtoupper('AUXSER-' . uniqid()),
            'CODIGO_INTERNO' => mb_strtoupper('AUXCOD-' . uniqid()),
            'CAPACIDAD'      => '500A',
        ]);
        $antes = $aux->only(['SERIAL', 'CODIGO_INTERNO', 'TIPO', 'MODELO', 'CAPACIDAD']);

        // Solo llega la marca: lo que el usuario cambió.
        $this->actingAs($this->usuario())
            ->post(route('equipos-auxiliares.updateMetadata', $aux->ID_AUXILIAR), [
                'doc_type' => 'propiedad',
                'marca'    => 'MILLER',
            ])->assertOk();

        $aux->refresh();
        $this->assertSame('MILLER', $aux->MARCA, 'lo que sí se mandó tiene que guardarse');
        $this->assertSame($antes['SERIAL'], $aux->SERIAL, 'el SERIAL no se mandó: no se toca');
        $this->assertSame($antes['CODIGO_INTERNO'], $aux->CODIGO_INTERNO, 'ni el código interno');
        $this->assertSame($antes['TIPO'], $aux->TIPO, 'ni el tipo');
        $this->assertSame($antes['MODELO'], $aux->MODELO, 'ni el modelo');
        $this->assertSame($antes['CAPACIDAD'], $aux->CAPACIDAD, 'ni la capacidad');
    }

    public function test_en_auxiliares_mandar_un_campo_vacio_si_lo_borra(): void
    {
        $aux = EquipoAuxiliar::create([
            'TIPO'   => 'MAQUINA DE SOLDAR',
            'SERIAL' => mb_strtoupper('AUXSER-' . uniqid()),
        ]);

        $this->actingAs($this->usuario())
            ->post(route('equipos-auxiliares.updateMetadata', $aux->ID_AUXILIAR), [
                'doc_type' => 'propiedad',
                'serial'   => '',
                'tipo'     => '',
            ])->assertOk();

        $aux->refresh();
        $this->assertNull($aux->SERIAL, 'mandar el serial vacío SÍ lo borra');
        // TIPO es NOT NULL en la tabla: vaciarlo guarda cadena vacía, no null.
        $this->assertSame('', $aux->TIPO, 'y el tipo, que no admite nulo, queda en cadena vacía');
    }
}
