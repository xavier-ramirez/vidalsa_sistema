<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\MovimientoInventario;
use App\Services\DevolucionService;
use Illuminate\Http\Request;
use Throwable;

/**
 * Devolución de material de una Nota de Entrega (modal "Devolución de material", que abre
 * el botón "Devolver" de cada salida del Historial de Movimientos). La lógica vive en
 * App\Services\DevolucionService; aquí solo se valida, se comprueba el acceso y se da forma
 * a la respuesta.
 */
class DevolucionMaterialController extends Controller
{
    public function __construct(private DevolucionService $devoluciones)
    {
    }

    /**
     * GET almacen/devolucion?numero=NE-…[&id_producto=N]  → lo entregado con la nota, lo ya
     * devuelto y lo que queda por devolver, más las devoluciones anteriores. Con id_producto
     * (el botón "Devolver" de cada movimiento del Historial) solo ese producto. Solo lectura:
     * mismo acceso que ver la nota (el almacén tiene que ser visible para el usuario).
     */
    public function show(Request $request)
    {
        $numero = $this->numero($request->query('numero'));
        if ($numero === '') {
            return response()->json(['message' => 'Falta el N° de la Nota de Entrega.'], 422);
        }

        $salidas = $this->devoluciones->salidasDeNota($numero);
        if ($salidas->isEmpty()) {
            return response()->json(['message' => "No hay ninguna Nota de Entrega con el N° {$numero}."], 404);
        }
        $cabecera = $salidas->first()->loadMissing(['almacen:ID_ALMACEN,NOMBRE', 'frente:ID_FRENTE,NOMBRE_FRENTE']);
        Almacen::assertVisibleOrFail($request->user(), (int) $cabecera->ID_ALMACEN);

        if ($motivo = $this->devoluciones->motivoNoDevolvible($salidas)) {
            return response()->json(['message' => $motivo], 422);
        }
        // Filas del producto pedido (varias si salió de más de una bolsa), o toda la nota.
        if ($idProducto = $request->integer('id_producto')) {
            $salidas = $salidas->where('ID_PRODUCTO', $idProducto)->values();
            if ($salidas->isEmpty()) {
                return response()->json(['message' => "Ese producto no está en la Nota {$numero}."], 404);
            }
        }

        $historial = MovimientoInventario::with(['producto:ID_PRODUCTO,NOMBRE,UM', 'usuario:ID_USUARIO,NOMBRE_COMPLETO'])
            ->where('TIPO', MovimientoInventario::TIPO_DEVOLUCION)
            ->whereIn('ID_MOVIMIENTO_RELACIONADO', $salidas->pluck('ID_MOVIMIENTO'))
            ->orderBy('ID_MOVIMIENTO')
            ->get()
            ->map(fn ($d) => [
                'fecha'    => optional($d->FECHA)->format('d/m/Y'),
                'producto' => $d->producto?->NOMBRE ?? '—',
                'cantidad' => round((float) $d->CANTIDAD, 3),
                'um'       => $d->producto?->UM,
                'motivo'   => $d->MOTIVO,
                'usuario'  => $d->usuario?->NOMBRE_COMPLETO,
            ]);

        return response()->json([
            'numero'      => $numero,
            'fecha'       => optional($cabecera->FECHA)->format('d/m/Y'),
            'almacen'     => $cabecera->almacen?->NOMBRE,
            'proyecto'    => $cabecera->frente?->NOMBRE_FRENTE,
            'solicitante' => $cabecera->SOLICITANTE,
            'lineas'      => $this->devoluciones->lineas($salidas),
            'historial'   => $historial,
        ]);
    }

    /** POST almacen/devolucion → registra la devolución. */
    public function store(Request $request)
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
            'numero'                      => 'required|string|max:30',
            'motivo'                      => 'nullable|string|max:150',
            'lineas'                      => 'required|array|min:1',
            'lineas.*.id_producto'        => 'required|integer|distinct',
            'lineas.*.cantidad'           => 'required|numeric|gt:0',
        ], [
            'lineas.required'               => 'Indica cuánto se devuelve de al menos un producto.',
            'lineas.*.id_producto.distinct' => 'Un producto aparece dos veces en la devolución.',
            'lineas.*.cantidad.gt'          => 'Las cantidades a devolver deben ser mayores que cero.',
        ]);

        $numero = $this->numero($data['numero']);
        $idAlmacen = MovimientoInventario::where('NUMERO_NOTA', $numero)->value('ID_ALMACEN');
        if (!$idAlmacen) {
            return response()->json(['message' => "No hay ninguna Nota de Entrega con el N° {$numero}."], 404);
        }
        Almacen::assertVisibleOrFail($request->user(), (int) $idAlmacen);

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

    /** N° de nota como se guarda: sin espacios y en mayúsculas (se teclea "ne-2026-0123"). */
    private function numero($valor): string
    {
        return strtoupper(trim((string) $valor));
    }
}
