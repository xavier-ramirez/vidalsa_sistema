<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Services\CorreccionNotaService;
use App\Services\DevolucionService;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Throwable;

/**
 * «Modificar» un producto de una Nota de Entrega (botón de cada salida con nota del Historial
 * de Movimientos): UN modal con dos operaciones que se eligen ahí mismo.
 *
 *   · DEVOLUCIÓN (App\Services\DevolucionService): el material salió y volvió. La nota no
 *     cambia; se anota lo devuelto. Clave almacen.movimiento (mueve stock).
 *   · CORRECCIÓN (App\Services\CorreccionNotaService): la nota se cargó mal. Pasa a decir lo
 *     que salió y queda la original. Clave almacen.nota.corregir.
 *
 * Por dentro siguen siendo dos operaciones —cada una con su permiso, su rastro y su ruta—;
 * lo que se une es la entrada: un botón, un GET con todo lo que necesita el modal.
 */
class AjusteNotaController extends Controller
{
    public function __construct(
        private DevolucionService $devoluciones,
        private CorreccionNotaService $correcciones,
    ) {
    }

    /**
     * GET almacen/ajuste-nota?numero=NE-…&id_producto=N → el producto en la nota (entregado,
     * devuelto, pendiente), qué se puede hacer con él —y por qué no, si no— y los historiales
     * de las dos operaciones. Pide al menos una de las dos claves.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $puedeDevolver = (bool) $user?->can('almacen.movimiento');
        $puedeCorregir = (bool) $user?->can('almacen.nota.corregir');
        if (!$puedeDevolver && !$puedeCorregir) {
            return response()->json(['message' => 'No tienes la clave «almacen.movimiento» ni «almacen.nota.corregir». Solicítala a un administrador.'], 403);
        }

        $numero = $this->numero($request->query('numero'));
        $idProducto = $request->integer('id_producto');
        if ($numero === '' || !$idProducto) {
            return response()->json(['message' => 'Falta el N° de la Nota de Entrega o el producto.'], 422);
        }

        $salidas = $this->devoluciones->salidasDeNota($numero);
        if ($salidas->isEmpty()) {
            return response()->json(['message' => "No hay ninguna Nota de Entrega con el N° {$numero}."], 404);
        }
        $cabecera = $salidas->first()->loadMissing(['almacen:ID_ALMACEN,NOMBRE', 'frente:ID_FRENTE,NOMBRE_FRENTE']);
        Almacen::assertVisibleOrFail($user, (int) $cabecera->ID_ALMACEN);

        $filas = $salidas->where('ID_PRODUCTO', $idProducto)->values();
        if ($filas->isEmpty()) {
            return response()->json(['message' => "Ese producto no está en la Nota {$numero}."], 404);
        }
        $linea = $this->devoluciones->lineas($filas)[0];

        // Por qué NO se puede cada cosa (null = sí se puede). El orden importa: primero lo que
        // es de la nota, después la clave del usuario.
        $noDevolver = $this->devoluciones->motivoNoDevolvible($salidas)
            ?? ($linea['pendiente'] <= InventarioService::EPS ? 'Ya se devolvió todo lo que se entregó de este producto.' : null)
            ?? ($puedeDevolver ? null : 'Registrar una devolución pide la clave «almacen.movimiento».');
        $noCorregir = $this->correcciones->motivoNoCorregible($salidas, $filas)
            ?? ($puedeCorregir ? null : 'Corregir una nota pide la clave «almacen.nota.corregir».');

        return response()->json([
            'numero'      => $numero,
            'fecha'       => optional($cabecera->FECHA)->format('d/m/Y'),
            'almacen'     => $cabecera->almacen?->NOMBRE,
            'proyecto'    => $cabecera->frente?->NOMBRE_FRENTE,
            'solicitante' => $cabecera->SOLICITANTE,
            'linea'       => $linea,
            'devolucion'  => [
                'motivo_no' => $noDevolver,
                'historial' => $this->devoluciones->historial($filas),
            ],
            'correccion'  => [
                'motivo_no' => $noCorregir,
                'historial' => $this->correcciones->historial($numero, $idProducto),
            ],
        ]);
    }

    /** POST almacen/devolucion → registra la devolución. */
    public function devolver(Request $request)
    {
        // Mueve stock, así que exige la misma clave que cualquier movimiento — con el mismo
        // mensaje que registrarMovimientoLote, que nombra la clave que falta.
        if (! $request->user()?->can('almacen.movimiento')) {
            return response()->json([
                'success'   => false,
                'forbidden' => true,
                'message'   => 'No tienes la clave de permiso «almacen.movimiento», necesaria para registrar movimientos de inventario. Solicítala a un administrador.',
            ], 403);
        }

        $data = $request->validate([
            'numero'               => 'required|string|max:30',
            'motivo'               => 'nullable|string|max:150',
            'lineas'               => 'required|array|min:1',
            'lineas.*.id_producto' => 'required|integer|distinct',
            'lineas.*.cantidad'    => 'required|numeric|gt:0',
        ], [
            'lineas.required'               => 'Indica cuánto se devuelve de al menos un producto.',
            'lineas.*.id_producto.distinct' => 'Un producto aparece dos veces en la devolución.',
            'lineas.*.cantidad.gt'          => 'Las cantidades a devolver deben ser mayores que cero.',
        ]);

        $numero = $this->numero($data['numero']);
        if ($falta = $this->assertNotaVisible($request, $numero)) {
            return $falta;
        }

        try {
            $this->devoluciones->registrar($numero, $data['lineas'], [
                'motivo'     => $data['motivo'] ?? null,
                'id_usuario' => $request->user()->ID_USUARIO,
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $n = count($data['lineas']);
        return response()->json(['message' => "Devolución registrada ({$n} producto" . ($n === 1 ? '' : 's') . ')'], 201);
    }

    /** POST almacen/correccion-nota → corrige y devuelve el N° para abrir la comparación. */
    public function corregir(Request $request)
    {
        $data = $request->validate([
            'numero'      => 'required|string|max:30',
            'id_producto' => 'required|integer',
            'cantidad'    => 'required|numeric|gt:0',
            'motivo'      => 'required|string|max:150',
        ], [
            'cantidad.gt'     => 'La cantidad corregida debe ser mayor que cero.',
            'motivo.required' => 'Escribe el motivo de la corrección: queda anotado en la nota original.',
        ]);

        $numero = $this->numero($data['numero']);
        if ($falta = $this->assertNotaVisible($request, $numero)) {
            return $falta;
        }

        try {
            $this->correcciones->corregir($numero, (int) $data['id_producto'], (float) $data['cantidad'], [
                'motivo'     => $data['motivo'],
                'id_usuario' => $request->user()->ID_USUARIO,
            ]);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => "Nota {$numero} corregida.", 'numero' => $numero], 201);
    }

    /** 404 si la nota no existe; aborta si su almacén no es visible para el usuario. */
    private function assertNotaVisible(Request $request, string $numero)
    {
        $idAlmacen = MovimientoInventario::where('NUMERO_NOTA', $numero)->value('ID_ALMACEN');
        if (!$idAlmacen) {
            return response()->json(['message' => "No hay ninguna Nota de Entrega con el N° {$numero}."], 404);
        }
        Almacen::assertVisibleOrFail($request->user(), (int) $idAlmacen);
        return null;
    }

    /** N° de nota como se guarda: sin espacios y en mayúsculas (se teclea "ne-2026-0123"). */
    private function numero($valor): string
    {
        return strtoupper(trim((string) $valor));
    }
}
