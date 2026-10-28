<?php

namespace Tests\Feature;

use App\Models\CompresionPdf;
use App\Models\Usuario;
use App\Models\VerificacionDocumento;
use Tests\MySqlTestCase;

/**
 * Los filtros de Revisión y Compresión (Control de Auditoría) y sus cuentas.
 *
 * Cada filtro pide por AJAX solo los fragmentos del panel (cpdfFiltrar →
 * HistorialDocumentosController::index → PanelDocumentos::datos con $soloLista). Lo que fijan:
 * que cada filtro deja las filas que tiene que dejar, que "Documentos leídos" cuenta lo que
 * lista la tabla (y "de" cuántos hay), que las cuentas por estado siguen a los demás filtros
 * pero no al de estado, y que la respuesta no trae lo que no cambia con los filtros.
 * Sobre la base de trabajo y sin escribir en ella.
 */
class AuditoriaDocumentosFiltrosTest extends MySqlTestCase
{
    private function usuario(): Usuario
    {
        $u = Usuario::where('REQUIERE_CAMBIO_CLAVE', 0)->whereNotNull('PERMISOS')->get()
            ->first(fn ($usr) => in_array('super.admin', array_map('strtolower', $usr->PERMISOS), true));
        $this->assertNotNull($u, 'hace falta un super.admin activo');

        return $u;
    }

    /** Los fragmentos que devuelve un filtro de la pestaña. */
    private function filtrar(array $query): string
    {
        $html = $this->actingAs($this->usuario())
            ->getJson('/admin/historial-documentos?' . http_build_query($query))
            ->assertOk()->json('html');
        $this->assertIsString($html);

        return $html;
    }

    /** El número de una parte del lateral (su primer <strong>). */
    private function cifra(string $html, string $parte): int
    {
        $this->assertSame(1, preg_match('/data-cpdf-parte="' . $parte . '".*?<strong[^>]*>\s*([\d.]+)/s', $html, $m),
            "no está la parte {$parte}");

        return (int) str_replace('.', '', $m[1]);
    }

    private function filas(string $html): int
    {
        return substr_count($html, 'class="cpdf-fila-sel');
    }

    /** "Documentos leídos" = lo que lista la tabla con ESE filtro; filtrado, "de" el total. */
    private function assertCuenta(array $filtro, int $esperado): void
    {
        $html = $this->filtrar(['pestana' => 'documentos'] + $filtro);
        $this->assertSame($esperado, $this->cifra($html, 'hero'), 'la cuenta no sigue al filtro ' . json_encode($filtro));
        $this->assertSame(min($esperado, 50), $this->filas($html), 'la tabla no trae lo que dice la cuenta');
        if ($esperado !== VerificacionDocumento::count()) {
            $this->assertStringContainsString('de ' . number_format(VerificacionDocumento::count(), 0, ',', '.'), $html);
        }
    }

    public function test_cada_filtro_de_la_revision_deja_sus_filas_y_la_cuenta_lo_sigue(): void
    {
        $fila = VerificacionDocumento::whereNotNull('PLACA')->where('PLACA', '!=', '')->first();
        $this->assertNotNull($fila, 'sin lecturas no se puede probar');

        $this->assertCuenta([], VerificacionDocumento::count());
        $this->assertCuenta(['tipo_doc' => $fila->TIPO], VerificacionDocumento::where('TIPO', $fila->TIPO)->count());
        $this->assertCuenta(['estado_doc' => $fila->ESTADO], VerificacionDocumento::where('ESTADO', $fila->ESTADO)->count());
        $this->assertCuenta(['estado_doc' => 'revisar'], VerificacionDocumento::paraRevisar()->count());
        $this->assertCuenta(['tipo_doc' => $fila->TIPO, 'estado_doc' => $fila->ESTADO],
            VerificacionDocumento::where('TIPO', $fila->TIPO)->where('ESTADO', $fila->ESTADO)->count());
        $this->assertCuenta(['buscar' => $fila->PLACA], VerificacionDocumento::where(fn ($w) => $w
            ->where('PLACA', 'like', "%{$fila->PLACA}%")->orWhere('SERIAL', 'like', "%{$fila->PLACA}%")
            ->orWhere('MOTIVO', 'like', "%{$fila->PLACA}%")->orWhere('ARCHIVO', 'like', "%{$fila->PLACA}%")
            ->orWhere('LEIDO', 'like', "%{$fila->PLACA}%")
            ->orWhere(fn ($c) => $c->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)->where('PROPUESTA', 'like', "%{$fila->PLACA}%")))->count());
        $this->assertCuenta(['buscar' => 'ZZZ-NO-EXISTE-999'], 0);
        $this->assertCuenta(['fecha_desde' => '2099-01-01'], 0);
        $dia = $fila->updated_at->toDateString();
        $this->assertCuenta(['fecha_desde' => $dia, 'fecha_hasta' => $dia],
            VerificacionDocumento::whereBetween('updated_at', [$fila->updated_at->copy()->startOfDay(), $fila->updated_at->copy()->endOfDay()])->count());
        $this->assertCuenta(['ids' => (string) $fila->ID_REGISTRO], 1);
    }

    public function test_las_cuentas_por_estado_siguen_a_los_demas_filtros_y_no_al_de_estado(): void
    {
        $tipo = VerificacionDocumento::value('TIPO');
        $this->assertNotNull($tipo, 'sin lecturas no se puede probar');
        $porEstado = VerificacionDocumento::where('TIPO', $tipo)->selectRaw('ESTADO, COUNT(*) n')->groupBy('ESTADO')->pluck('n', 'ESTADO');

        $html = $this->filtrar(['pestana' => 'documentos', 'tipo_doc' => $tipo]);
        foreach ($porEstado as $estado => $n) {
            $this->assertMatchesRegularExpression('/data-value="' . preg_quote($estado, '/') . '"[^>]*\(' . number_format($n, 0, ',', '.') . '\)/',
                $html, "la opción {$estado} no lleva la cuenta del tipo");
        }
        $this->assertSame(VerificacionDocumento::where('TIPO', $tipo)->paraRevisar()->count(), $this->cifra($html, 'revisar'));

        // Elegir un estado no cambia las cuentas de los demás.
        $conEstado = $this->filtrar(['pestana' => 'documentos', 'tipo_doc' => $tipo, 'estado_doc' => $porEstado->keys()->first()]);
        $this->assertSame($this->cifra($html, 'revisar'), $this->cifra($conEstado, 'revisar'));
    }

    public function test_el_filtro_solo_trae_lo_que_cambia(): void
    {
        $html = $this->filtrar(['pestana' => 'documentos']);
        foreach (['tabla', 'paginas', 'hero', 'revisar', 'sin-enlazar', 'opciones-estado_doc', 'opciones-tipo_doc'] as $parte) {
            $this->assertStringContainsString('data-cpdf-parte="' . $parte . '"', $html);
        }
        // Ni "Por dónde va la revisión" (la consulta cara, no depende de los filtros) ni scripts.
        $this->assertStringNotContainsString('Por dónde va la revisión', $html);
        $this->assertStringNotContainsString('<script', $html);

        // La pantalla entera sí lo trae.
        $this->actingAs($this->usuario())->get('/admin/historial-documentos?pestana=documentos')
            ->assertOk()->assertSee('Por dónde va la revisión');
    }

    public function test_cada_filtro_de_la_compresion_deja_sus_filas_y_cuenta_por_estado(): void
    {
        $fila = CompresionPdf::whereNotNull('SERIAL')->first();
        if (!$fila) $this->markTestSkipped('sin registros de compresión');

        $casos = [
            [['estado' => $fila->ESTADO], CompresionPdf::where('ESTADO', $fila->ESTADO)->count()],
            [['documento' => $fila->DOCUMENTO], CompresionPdf::where('DOCUMENTO', $fila->DOCUMENTO)->count()],
            [['buscar' => $fila->SERIAL], CompresionPdf::where(fn ($w) => $w->where('SERIAL', 'like', "%{$fila->SERIAL}%")
                ->orWhere('DOCUMENTO', 'like', "%{$fila->SERIAL}%"))->count()],
            [['fecha_desde' => '2099-01-01'], 0],
            [['ids' => (string) $fila->ID_REGISTRO], 1],
        ];
        foreach ($casos as [$filtro, $n]) {
            $html = $this->filtrar(['pestana' => 'compresion'] + $filtro);
            $this->assertSame(min($n, 50), $this->filas($html), 'mal filtrado: ' . json_encode($filtro));
        }

        // Las tarjetas cuentan con el documento elegido; el estado no las cambia.
        $html = $this->filtrar(['pestana' => 'compresion', 'documento' => $fila->DOCUMENTO, 'estado' => $fila->ESTADO]);
        $base = CompresionPdf::where('DOCUMENTO', $fila->DOCUMENTO);
        $this->assertSame((clone $base)->where('ESTADO', CompresionPdf::COMPRIMIDO)->count(), $this->cifra($html, 'comprimidos'));
        $this->assertSame((clone $base)->where('ESTADO', CompresionPdf::SALTADO)->count(), $this->cifra($html, 'saltados'));
        $this->assertSame((clone $base)->where('ESTADO', CompresionPdf::ERROR)->count(), $this->cifra($html, 'con-error'));
    }
}
