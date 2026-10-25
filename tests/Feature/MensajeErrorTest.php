<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\TraspasoService;
use App\Support\MensajeError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\MySqlTestCase;

/**
 * MensajeError decide qué texto de una excepción llega a la pantalla: los avisos de negocio
 * que lanzan los servicios sí; el SQL, los nombres de modelos y lo que venga de vendor/ no
 * (eso va al log). Corre en la transacción de la prueba.
 */
class MensajeErrorTest extends MySqlTestCase
{
    private function capturar(callable $fn): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('No lanzó nada');
    }

    public function test_el_aviso_de_un_servicio_llega_tal_cual(): void
    {
        $e = $this->capturar(fn () => app(TraspasoService::class)->validarOrigenDestino(7, 7));

        $this->assertSame('El almacén de origen y destino no pueden ser el mismo.', MensajeError::para($e));
    }

    public function test_un_error_de_la_base_no_ensena_el_sql(): void
    {
        Log::spy();
        $e = $this->capturar(fn () => DB::select('select CLAVE_SECRETA from tabla_que_no_existe_xyz'));

        $msg = MensajeError::para($e);

        $this->assertSame(MensajeError::GENERICO, $msg);
        $this->assertStringNotContainsString('tabla_que_no_existe_xyz', $msg);
        // El detalle no se pierde: queda en el log.
        Log::shouldHaveReceived('error')->once();
    }

    public function test_un_modelo_que_no_existe_no_ensena_la_clase(): void
    {
        Log::spy();
        $e = $this->capturar(fn () => Usuario::findOrFail(-1));

        $this->assertSame(MensajeError::REINTENTAR, MensajeError::para($e, MensajeError::REINTENTAR));
        $this->assertStringNotContainsString('App\\Models', MensajeError::para($e));
    }

    public function test_texto_no_escribe_en_el_log(): void
    {
        // Para el catch que ya tiene su propio Log::error: no se registra dos veces.
        Log::spy();
        $e = $this->capturar(fn () => DB::select('select 1 from tabla_que_no_existe_xyz'));

        $this->assertSame(MensajeError::REINTENTAR, MensajeError::texto($e, MensajeError::REINTENTAR));
        Log::shouldNotHaveReceived('error');
    }

    public function test_abort_y_validacion_son_textos_escritos_a_proposito(): void
    {
        $this->assertSame('Nota anulada.', MensajeError::para($this->capturar(fn () => abort(422, 'Nota anulada.'))));

        $v = ValidationException::withMessages(['x' => 'Falta la cantidad.']);
        $this->assertSame('Falta la cantidad.', MensajeError::para($v));
    }

    public function test_lo_que_no_sale_de_app_no_es_nuestro(): void
    {
        Log::spy();
        // Lanzada desde aquí (tests/), igual que si saliera de vendor/.
        $this->assertSame(MensajeError::GENERICO, MensajeError::para(new \RuntimeException('cURL error 28: https://www.googleapis.com/...')));
        // Una Error de PHP (TypeError, división por cero...) tampoco.
        $this->assertSame(MensajeError::GENERICO, MensajeError::para(new \TypeError('Argument #1 must be of type int')));
    }
}
