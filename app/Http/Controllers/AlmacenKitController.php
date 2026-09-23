<?php

namespace App\Http\Controllers;

use App\Models\Almacen;
use App\Models\AlmacenKit;
use App\Services\KitAlmacenService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Kits del almacén (Acciones → Kits en /admin/almacen). La lógica vive en
 * App\Services\KitAlmacenService; aquí solo se valida la forma y se contesta JSON.
 *
 * Permisos:
 *   ver los kits y lo que alcanza  → cualquiera con el módulo (grupo 'auth'); la existencia
 *                                    solo se da de un almacén que el usuario puede ver.
 *   crear, editar, borrar          → almacen.productos (es parte del catálogo).
 *   cargar un kit en la salida     → no pasa por aquí: lo hace la pantalla, y la salida
 *                                    exige almacen.movimiento como siempre.
 */
class AlmacenKitController extends Controller
{
    public function __construct(private KitAlmacenService $kits)
    {
        $this->middleware('can:almacen.productos')->only(['modelos', 'sugeridos', 'store', 'update', 'destroy']);
    }

    /**
     * GET almacen/kits?id_almacen=N → { kits, existencias }. `existencias` es cuánto hay de
     * cada material en ese almacén ([id_producto => cantidad]); sin almacén va vacío.
     */
    public function index(Request $request)
    {
        $kits = $this->kits->catalogo();
        $existencias = [];
        $idAlmacen = (int) $request->query('id_almacen', 0);
        if ($idAlmacen > 0) {
            abort_unless(Almacen::visiblesPara($request->user())->whereKey($idAlmacen)->exists(), 403, 'No tienes acceso a ese almacén.');
            $ids = $kits->flatMap(fn ($k) => collect($k['items'])->pluck('id_producto'))->unique()->values()->all();
            $existencias = $this->kits->existencias($idAlmacen, $ids);
        }
        // Objeto siempre ({} y no [] cuando está vacío): la pantalla lo lee por id de producto.
        return response()->json(['kits' => $kits, 'existencias' => (object) $existencias]);
    }

    /** GET almacen/kits/modelos → los modelos de equipo para elegir en el editor. */
    public function modelos()
    {
        return response()->json(['modelos' => $this->kits->modelosParaElegir()]);
    }

    /** GET almacen/kits/sugeridos?origen=modelo&refs[]=… → productos ligados a ese modelo. */
    public function sugeridos(Request $request)
    {
        $data = $request->validate([
            'origen' => 'required|in:modelo,aux',
            'refs'   => 'required|array|min:1|max:20',
            'refs.*' => 'required|string|max:200',
        ]);
        return response()->json(['productos' => $this->kits->sugeridos($data['origen'], $data['refs'])]);
    }

    public function store(Request $request)
    {
        return $this->guardar($request, null);
    }

    public function update(Request $request, int $id)
    {
        return $this->guardar($request, AlmacenKit::findOrFail($id));
    }

    public function destroy(int $id)
    {
        $kit = AlmacenKit::findOrFail($id);
        $nombre = $kit->NOMBRE;
        $kit->delete();   // materiales y modelos se van por cascada
        return response()->json(['success' => true, 'message' => "Kit {$nombre} eliminado."]);
    }

    private function guardar(Request $request, ?AlmacenKit $kit)
    {
        $data = $request->validate([
            'nombre'               => ['required', 'string', 'max:120',
                                       Rule::unique('almacen_kits', 'NOMBRE')->ignore($kit?->ID_KIT, 'ID_KIT')],
            'descripcion'          => 'nullable|string|max:255',
            'items'                => 'required|array|min:1|max:' . KitAlmacenService::MAX_ITEMS,
            'items.*.id_producto'  => 'required|integer|distinct',
            'items.*.cantidad'     => 'required|numeric|gt:0|max:99999',
            'modelos'              => 'nullable|array|max:' . KitAlmacenService::MAX_MODELOS,
            'modelos.*.origen'     => 'required|in:modelo,aux',
            'modelos.*.refs'       => 'required|array|min:1|max:20',
            'modelos.*.refs.*'     => 'required|string|max:200',
        ], [
            'nombre.required'         => 'Ponle un nombre al kit.',
            'nombre.unique'           => 'Ya hay un kit con ese nombre.',
            'items.required'          => 'El kit necesita al menos un material.',
            'items.max'               => 'Un kit puede llevar hasta ' . KitAlmacenService::MAX_ITEMS . ' materiales.',
            'items.*.id_producto.distinct' => 'Un material está repetido: pon la cantidad total en una sola línea.',
            'items.*.cantidad.gt'     => 'Cada material tiene que llevar una cantidad mayor que cero.',
        ]);

        try {
            $kit = $this->kits->guardar($kit, $data, $request->user()?->getAuthIdentifier());
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'id' => $kit->ID_KIT, 'message' => "Kit {$kit->NOMBRE} guardado."]);
    }
}
