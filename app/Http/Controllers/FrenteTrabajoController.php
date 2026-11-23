<?php

namespace App\Http\Controllers;

use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;

class FrenteTrabajoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // El usuario prefiere trabajar exclusivamente desde el formulario de creación/edición
        // con el buscador integrado. Redirigimos siempre a CREATE.
        return redirect()->route('frentes.create');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return $this->vistaFormulario(new FrenteTrabajo());
    }

    /**
     * La pantalla del módulo (registro y edición son la misma vista). Lista del buscador
     * integrado: el formulario ES el módulo y el frente se elige aquí mismo; solo id y
     * nombre, el resto se pide al seleccionarlo. Categorías: el "Filtro Firma" de cada responsable.
     */
    private function vistaFormulario(FrenteTrabajo $frente)
    {
        $allFrentes = FrenteTrabajo::select('ID_FRENTE', 'NOMBRE_FRENTE')
            ->orderBy('NOMBRE_FRENTE')
            ->get();
        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];

        return view('admin.frentes.formulario', compact('frente', 'allFrentes', 'categorias'));
    }

    /**
     * Autocomplete de frentes para los formularios de OTROS módulos
     * (equipos/usuarios/almacén). Lo consume uicomponents.js → performFrentesFetch
     * vía GET /admin/frentes/buscar?query=. Devuelve un array
     * [{ID_FRENTE, NOMBRE_FRENTE, UBICACION}] (mismo set que el dropdown
     * precargado de create(), pero filtrado por nombre).
     *
     * ?activos=1 — variante para REFRESCAR los modales de movilización al abrirse
     * (equipos_index.js::refrescarFrentesMovilizacion): mismo universo que la
     * lista server-render de esos modales (solo ACTIVO + scope de frentes del
     * usuario) y sin recorte por query. Sin este refresh, un frente creado
     * después de cargar la página no aparecía en el modal hasta recargar.
     */
    public function search(Request $request)
    {
        $query   = trim((string) $request->input('query', ''));
        $activos = $request->boolean('activos');

        $q = FrenteTrabajo::select('ID_FRENTE', 'NOMBRE_FRENTE', 'UBICACION')
            ->when($query !== '', fn ($qq) => $qq->where('NOMBRE_FRENTE', 'like', "%{$query}%"))
            ->orderBy('NOMBRE_FRENTE');

        if ($activos) {
            $q->where('ESTATUS_FRENTE', 'ACTIVO');
            if ($user = auth()->user()) {
                $user->aplicarScopeFrentesEquipos($q, 'ID_FRENTE');
            }
            $q->limit(300); // lista completa para el modal (no es autocomplete)
        } else {
            $q->limit(50);
        }

        return response()->json($q->get());
    }

    /**
     * Store a newly created resource in storage.
     */    public function store(\App\Http\Requests\FrenteRequest $request)
    {
        $validated = $request->validated();
        // Un frente eliminado con el mismo nombre se revive con estos datos (y su historial).
        // Dos «Registrar» del mismo nombre a la vez (doble clic, dos personas) pasan los dos la
        // validación y el segundo choca con el índice UNIQUE: se contesta el aviso, no un 500.
        try {
            $frente = \Illuminate\Support\Facades\DB::transaction(function () use ($validated) {
                $frente = FrenteTrabajo::revivirPorNombre($validated['NOMBRE_FRENTE']);
                $frente ? $frente->update($validated) : $frente = FrenteTrabajo::create($validated);
                return $frente;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $this->nombreOcupado($request);
        }

        if ($request->wantsJson() || $request->has('json')) {
            return response()->json([
                'success' => true,
                'message' => 'Frente de trabajo creado correctamente.',
                'frente' => $frente,
                'redirect' => route('frentes.create')
            ]);
        }

        return redirect()->route('frentes.create')->with('success', 'Frente de trabajo creado correctamente.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, string $id)
    {
        $frente = $this->buscar($request, $id);

        if ($request->wantsJson() || $request->has('json')) {
            return response()->json($frente);
        }

        return $this->vistaFormulario($frente);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(\App\Http\Requests\FrenteRequest $request, string $id)
    {
        $frente = $this->buscar($request, $id);
        $validated = $request->validated();

        // Guard: bloquea pasar a FINALIZADO si el frente tiene equipos o
        // auxiliares asignados. La transicion solo se permite cuando el
        // frente esta vacio (preserva integridad referencial sin FK fisicas).
        $vaAFinalizar = ($validated['ESTATUS_FRENTE'] ?? null) === 'FINALIZADO'
                     && $frente->ESTATUS_FRENTE !== 'FINALIZADO';
        if ($vaAFinalizar) {
            $equiposAsignados    = \App\Models\Equipo::where('ID_FRENTE_ACTUAL', $id)->count();
            $auxiliaresAsignados = \App\Models\EquipoAuxiliar::where('ID_FRENTE_ACTUAL', $id)->count();
            if ($equiposAsignados > 0 || $auxiliaresAsignados > 0) {
                $partes = [];
                if ($equiposAsignados > 0)    $partes[] = "{$equiposAsignados} equipo(s)";
                if ($auxiliaresAsignados > 0) $partes[] = "{$auxiliaresAsignados} auxiliar(es)";
                $msg = 'No se puede marcar el frente como FINALIZADO: tiene ' .
                       implode(' y ', $partes) . ' asignados. Reasignelos primero a otro frente.';

                if ($request->wantsJson() || $request->has('json')) {
                    return response()->json([
                        'success' => false,
                        'message' => $msg,
                        'errors'  => ['ESTATUS_FRENTE' => [$msg]],
                        'equipos_asignados'    => $equiposAsignados,
                        'auxiliares_asignados' => $auxiliaresAsignados,
                    ], 422);
                }
                return redirect()->back()->with('error', $msg)->withInput();
            }
        }

        try {
            $frente->update($validated);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $this->nombreOcupado($request);
        }

        if ($request->wantsJson() || $request->has('json')) {
            return response()->json([
                'success' => true,
                'message' => 'Frente de trabajo actualizado correctamente.',
                'frente' => $frente
            ]);
        }

        return redirect()->route('frentes.create')->with('success', 'Frente de trabajo actualizado correctamente.');
    }


    /**
     * Elimina un frente con borrado LÓGICO (deleted_at): desaparece de listas y
     * selectores, pero la fila se queda para que el historial (movilizaciones,
     * movimientos de inventario, consumibles, traspasos) conserve el frente y su
     * nombre. Antes se borraba la fila y el historial lo perdía. Bloquea si tiene
     * equipos o auxiliares asignados.
     *
     * Para "archivar" un frente sin borrarlo el usuario tiene el campo
     * "Estatus del Proyecto" en el formulario, que permite marcarlo como
     * FINALIZADO manualmente.
     */
    public function destroy(Request $request, string $id)
    {
        $frente = $this->buscar($request, $id);

        $equiposAsignados    = \App\Models\Equipo::where('ID_FRENTE_ACTUAL', $id)->count();
        $auxiliaresAsignados = \App\Models\EquipoAuxiliar::where('ID_FRENTE_ACTUAL', $id)->count();
        // Stock de almacén repartido a este frente: eliminado, ya no se podría elegir el
        // frente para mover ese stock y quedaría colgado.
        $productosConStock   = \App\Models\AlmacenStock::where('ID_FRENTE', $id)->where('CANTIDAD', '>', 0)->count();
        // Envíos de almacén hacia este frente que aún no se reciben: al recibirlos el material
        // entraría a la bolsa de un frente eliminado, donde nada lo puede elegir.
        $enviosPendientes    = \App\Models\Traspaso::where('ID_FRENTE_DESTINO', $id)
            ->whereIn('ESTADO', [\App\Models\Traspaso::ESTADO_BORRADOR, \App\Models\Traspaso::ESTADO_ENVIADO])->count();
        // Usuarios activos con este frente asignado: un usuario LOCAL se quedaría sin nada que ver.
        $usuariosAsignados   = \App\Models\Usuario::where('ID_FRENTE_ASIGNADO', $id)->where('ESTATUS', 'ACTIVO')->count();

        if ($equiposAsignados > 0 || $auxiliaresAsignados > 0 || $productosConStock > 0
            || $enviosPendientes > 0 || $usuariosAsignados > 0) {
            $partes = [];
            if ($equiposAsignados > 0)    $partes[] = "{$equiposAsignados} equipo(s)";
            if ($auxiliaresAsignados > 0) $partes[] = "{$auxiliaresAsignados} auxiliar(es)";
            if ($productosConStock > 0)   $partes[] = "{$productosConStock} producto(s) con stock en almacén";
            if ($enviosPendientes > 0)    $partes[] = "{$enviosPendientes} envío(s) de almacén sin recibir";
            if ($usuariosAsignados > 0)   $partes[] = "{$usuariosAsignados} usuario(s)";
            $msg = 'No se puede eliminar el frente: tiene ' . implode(' y ', $partes) .
                   ' asignados. Reasignelos primero a otro frente.';

            if ($request->wantsJson() || $request->has('json')) {
                return response()->json([
                    'success' => false,
                    'message' => $msg,
                    'equipos_asignados'    => $equiposAsignados,
                    'auxiliares_asignados' => $auxiliaresAsignados,
                    'productos_con_stock'  => $productosConStock,
                    'envios_pendientes'    => $enviosPendientes,
                    'usuarios_asignados'   => $usuariosAsignados,
                ], 422);
            }
            return redirect()->route('frentes.create')->with('error', $msg);
        }

        $nombre = $frente->NOMBRE_FRENTE;
        // Lo que hacía el ON DELETE CASCADE de almacen_frentes con el borrado físico: el
        // frente deja de estar asociado a sus almacenes (es configuración, no historial). Un
        // almacén que así deja de separar por proyecto (le queda un frente) junta su saldo,
        // como al quitarle el frente en «Editar almacén» (InventarioService::juntarSaldosEnComun).
        \Illuminate\Support\Facades\DB::transaction(function () use ($frente) {
            $almacenes = \Illuminate\Support\Facades\DB::table('almacen_frentes')->where('ID_FRENTE', $frente->ID_FRENTE)->pluck('ID_ALMACEN');
            \Illuminate\Support\Facades\DB::table('almacen_frentes')->where('ID_FRENTE', $frente->ID_FRENTE)->delete();
            $frente->delete();
            foreach (\App\Models\Almacen::with('frentes')->whereIn('ID_ALMACEN', $almacenes)->get() as $almacen) {
                app(\App\Services\InventarioService::class)->juntarSaldosEnComun($almacen);
            }
        });

        if ($request->wantsJson() || $request->has('json')) {
            return response()->json([
                'success'  => true,
                'message'  => "Frente \"{$nombre}\" eliminado correctamente.",
                'redirect' => route('frentes.create'),
            ]);
        }
        return redirect()->route('frentes.create')->with('success', "Frente \"{$nombre}\" eliminado correctamente.");
    }

    /**
     * Cambia SOLO el tipo de un frente (OPERACION, RESGUARDO, ESPECIAL, VENDIDO, TRÁMITE DE
     * VENTA) desde Equipos → Acciones → "Tipo de frente", sin pasar por el formulario completo.
     * Guardar el modelo invalida las cachés (FrenteTrabajo::booted), así que los totales de la
     * flota y el listado sin conexión ven el cambio. Devuelve la etiqueta VENDIDO / EN TRÁMITE
     * DE VENTA ya armada con la MISMA plantilla del filtro de frentes (vacía si el tipo no es de
     * venta), para que la pantalla la cambie sin recargar y sin repetir ese HTML en el JS.
     */
    public function cambiarTipo(Request $request, string $id)
    {
        $data = $request->validate([
            'TIPO_FRENTE' => ['required', \Illuminate\Validation\Rule::in(array_keys(FrenteTrabajo::TIPOS))],
        ], [
            'TIPO_FRENTE.in' => 'Ese tipo de frente no existe.',
        ]);

        // Solo frentes que el usuario ve (LOCAL: los suyos; nadie, los bloqueados): la ruta pide
        // user.edit y no super.admin, así que el permiso solo no basta.
        $frente = $request->user()->aplicarScopeFrentesEquipos(FrenteTrabajo::query(), 'ID_FRENTE')->findOrFail($id);
        $frente->update(['TIPO_FRENTE' => $data['TIPO_FRENTE']]);

        return response()->json([
            'success' => true,
            'message' => "El frente \"{$frente->NOMBRE_FRENTE}\" ahora es " . FrenteTrabajo::TIPOS[$frente->TIPO_FRENTE] . '.',
            'tipo'     => $frente->TIPO_FRENTE,
            'etiqueta' => trim(view('admin.frentes.partials.etiqueta_venta', ['frente' => $frente])->render()),
        ]);
    }

    /**
     * Lista TODOS los frentes que no tienen NINGÚN equipo ni auxiliar asignado, stock de
     * almacén, envío sin recibir ni usuario activo (candidatos a eliminar: lo mismo que exige destroy()), SIN importar su estatus: incluye ACTIVOS y
     * FINALIZADOS. Lo consume el modal "Frentes sin equipos" del formulario, que
     * muestra el ESTATUS_FRENTE junto al nombre y ofrece Eliminar en cada fila.
     */
    public function sinEquipos(Request $request)
    {
        $frentes = FrenteTrabajo::whereDoesntHave('equipos')
            ->whereDoesntHave('equiposAuxiliares')
            ->whereNotExists(fn ($q) => $q->from('almacen_stock')
                ->whereColumn('almacen_stock.ID_FRENTE', 'frentes_trabajo.ID_FRENTE')
                ->where('almacen_stock.CANTIDAD', '>', 0))
            ->whereNotExists(fn ($q) => $q->from('traspasos')
                ->whereColumn('traspasos.ID_FRENTE_DESTINO', 'frentes_trabajo.ID_FRENTE')
                ->whereNull('traspasos.deleted_at')
                ->whereIn('traspasos.ESTADO', [\App\Models\Traspaso::ESTADO_BORRADOR, \App\Models\Traspaso::ESTADO_ENVIADO]))
            ->whereNotExists(fn ($q) => $q->from('usuarios')
                ->whereColumn('usuarios.ID_FRENTE_ASIGNADO', 'frentes_trabajo.ID_FRENTE')
                ->where('usuarios.ESTATUS', 'ACTIVO'))
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'UBICACION', 'TIPO_FRENTE', 'ESTATUS_FRENTE']);

        return response()->json([
            'success' => true,
            'count'   => $frentes->count(),
            'frentes' => $frentes->map(fn($f) => [
                'id'        => $f->ID_FRENTE,
                'nombre'    => $f->NOMBRE_FRENTE,
                'ubicacion' => $f->UBICACION,
                'tipo'      => $f->TIPO_FRENTE,
                'estatus'   => $f->ESTATUS_FRENTE,
            ]),
        ]);
    }

    /**
     * El frente pedido, o un aviso claro si ya no existe: otra persona lo eliminó mientras
     * esta pantalla lo tenía abierto. Antes salía el texto técnico en inglés
     * «No query results for model [App\Models\FrenteTrabajo] 123».
     */
    private function buscar(Request $request, string $id): FrenteTrabajo
    {
        $frente = FrenteTrabajo::find($id);
        if ($frente) {
            return $frente;
        }
        $msg = 'Ese frente ya no existe: lo eliminaron. Recarga la página.';
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            ($request->wantsJson() || $request->has('json'))
                ? response()->json(['success' => false, 'message' => $msg], 404)
                : redirect()->route('frentes.create')->with('error', $msg)
        );
    }

    /** El nombre lo acaba de ocupar otro registro (doble clic o dos personas a la vez). */
    private function nombreOcupado(Request $request)
    {
        $msg = 'Ya existe un frente de trabajo con este nombre.';
        if ($request->wantsJson() || $request->has('json')) {
            return response()->json(['success' => false, 'message' => $msg, 'errors' => ['NOMBRE_FRENTE' => [$msg]]], 422);
        }
        return redirect()->back()->withErrors(['NOMBRE_FRENTE' => $msg])->withInput();
    }
}
