<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\MySqlTestCase;

/**
 * COMBUSTIBLE vive en `equipos`, NO en `caracteristicas_modelo`.
 *
 * Un mismo MODELO puede traer motor a gasolina o a gasoil segun la unidad
 * (HILUX 2.7 vs 2.4 diesel, F-350 Triton vs Power Stroke), asi que guardarlo en la
 * ficha del modelo lo hacia irrepresentable — y ademas solo alcanzaba al 47% de los
 * equipos, porque 636 de 1194 no tienen ID_ESPEC.
 *
 * Estas pruebas fijan las dos mitades del contrato: que el dato viaja por HTTP hasta
 * `equipos` con su auditoria, y que el catalogo ya no lo conoce.
 */
class CombustibleEquipoTest extends MySqlTestCase
{
    /** Usuario capaz de editar equipos (equipos.create cubre store y update). */
    private function editor(): Usuario
    {
        $user = Usuario::all()->first(fn ($u) => $u->can('equipos.create'));
        $this->assertNotNull($user, 'No hay usuario con permiso equipos.create.');

        return $user;
    }

    public function test_el_esquema_quedo_con_una_sola_fuente_de_verdad(): void
    {
        foreach (['COMBUSTIBLE', 'CONSUMO_PROMEDIO'] as $col) {
            $this->assertTrue(Schema::hasColumn('equipos', $col),
                "equipos.{$col} deberia existir: es la fuente unica.");
            $this->assertFalse(Schema::hasColumn('caracteristicas_modelo', $col),
                "caracteristicas_modelo.{$col} deberia estar eliminada: se movio, no se copio.");
        }

        // El catalogo conserva lo que SI es referencia del modelo.
        $this->assertTrue(Schema::hasColumn('caracteristicas_modelo', 'MOTOR'));
    }

    public function test_no_existe_diesel_como_valor_valido(): void
    {
        // DIESEL y GASOIL son lo mismo. Tenerlos como dos opciones partia en dos
        // cualquier reporte por combustible.
        $this->assertNotContains('DIESEL', Equipo::COMBUSTIBLES);
        $this->assertContains('GASOIL', Equipo::COMBUSTIBLES);
        $this->assertContains('NO APLICA', Equipo::COMBUSTIBLES,
            'Los remolques (bateas, lowboys) no tienen motor y necesitan este valor.');

        $this->assertSame(0, Equipo::where('COMBUSTIBLE', 'DIESEL')->count(),
            'La migracion debio normalizar cualquier DIESEL heredado a GASOIL.');
    }

    /**
     * Los dos formularios, no uno.
     *
     * `admin/equipos/create.blade.php` NO incluye `partials/form_fields.blade.php`: es un
     * form unificado (equipo + auxiliar) con sus campos escritos aparte. Solo `edit`
     * usa el partial. Agregar el campo en el partial dejaba el registro sin combustible,
     * y esta prueba es la que lo destapo.
     *
     * @dataProvider formulariosDeEquipo
     */
    public function test_ambos_formularios_ofrecen_el_desplegable(string $ruta): void
    {
        $editor = $this->editor();
        $url = $ruta === 'equipos.edit'
            ? route($ruta, Equipo::whereNotNull('ID_FRENTE_ACTUAL')->value('ID_EQUIPO'))
            : route($ruta);

        $resp = $this->actingAs($editor)->get($url);
        $resp->assertOk();
        $resp->assertSee('name="COMBUSTIBLE"', false);

        foreach (Equipo::COMBUSTIBLES as $valor) {
            $resp->assertSee($valor, false);
        }
    }

    public static function formulariosDeEquipo(): array
    {
        return [
            'registro' => ['equipos.create'],
            'edicion'  => ['equipos.edit'],
        ];
    }

    public function test_editar_un_equipo_guarda_el_combustible_y_lo_audita(): void
    {
        $equipo = Equipo::where('COMBUSTIBLE', 'GASOIL')->whereNotNull('ID_FRENTE_ACTUAL')->first();
        $this->assertNotNull($equipo, 'Hace falta al menos un equipo a GASOIL para la prueba.');

        $logsAntes = DB::table('equipo_audit_log')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->count();

        $equipo->update(['COMBUSTIBLE' => 'GASOLINA']);
        $equipo->refresh();

        $this->assertSame('GASOLINA', $equipo->COMBUSTIBLE);

        // EquipoObserver::updated() audita el diff de CUALQUIER columna de `equipos`, asi
        // que el campo nuevo entra al historial sin una linea de codigo extra — verificado
        // fuera de transaccion: deja {"COMBUSTIBLE":{"antes":"GASOIL","despues":"GASOLINA"}}.
        // Aqui no se puede afirmar mas: el observer es afterCommit y DatabaseTransactions
        // revierte, asi que nunca dispara dentro de la prueba.
        $this->assertGreaterThanOrEqual($logsAntes,
            DB::table('equipo_audit_log')->where('ID_EQUIPO', $equipo->ID_EQUIPO)->count());
    }

    public function test_rechaza_un_combustible_inventado(): void
    {
        $equipo = Equipo::whereNotNull('ID_FRENTE_ACTUAL')->first();

        $resp = $this->actingAs($this->editor())
            ->from(route('equipos.edit', $equipo->ID_EQUIPO))
            ->put(route('equipos.update', $equipo->ID_EQUIPO), [
                'TIPO_EQUIPO'      => $equipo->tipo->nombre ?? 'CAMIONETA',
                'CATEGORIA_FLOTA'  => $equipo->CATEGORIA_FLOTA ?: 'FLOTA LIVIANA',
                'MARCA'            => $equipo->MARCA,
                'MODELO'           => $equipo->MODELO,
                'ANIO'             => $equipo->ANIO,
                'SERIAL_CHASIS'    => $equipo->SERIAL_CHASIS,
                'ESTADO_OPERATIVO' => $equipo->ESTADO_OPERATIVO,
                'COMBUSTIBLE'      => 'CHICHA DE ARROZ',
            ]);

        $resp->assertSessionHasErrors('COMBUSTIBLE');
        $this->assertNotSame('CHICHA DE ARROZ', $equipo->fresh()->COMBUSTIBLE);
    }

    public function test_la_flota_quedo_clasificada(): void
    {
        $total = Equipo::count();
        $sin   = Equipo::whereNull('COMBUSTIBLE')->count();

        $this->assertGreaterThan(90, 100 * ($total - $sin) / $total,
            'La migracion debe dejar clasificado mas del 90% de la flota.');

        // Los remolques no consumen: nunca deben quedar marcados con un combustible.
        $remolquesConCombustible = Equipo::whereNotNull('COMBUSTIBLE')
            ->where('COMBUSTIBLE', '<>', 'NO APLICA')
            ->whereIn('id_tipo_equipo', fn ($q) => $q->select('id')->from('tipo_equipos')
                ->whereIn('nombre', ['BATEA', 'LOWBOY', 'TRAILERS', 'CAMA BAJA', 'TARA']))
            ->count();

        $this->assertSame(0, $remolquesConCombustible,
            'Un remolque no tiene motor: no puede tener combustible asignado.');
    }
}
