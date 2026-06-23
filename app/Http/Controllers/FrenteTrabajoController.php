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
        // Stats
        $stats = FrenteTrabajo::selectRaw("
            count(case when ESTATUS_FRENTE = 'ACTIVO' then 1 end) as activos, 
            count(case when ESTATUS_FRENTE = 'FINALIZADO' then 1 end) as finalizados
        ")->first();

        // Pre-load for Search Dropdown (Simple list)
        $allFrentes = FrenteTrabajo::select('ID_FRENTE', 'NOMBRE_FRENTE')
            ->orderBy('NOMBRE_FRENTE')
            ->get();

        // Create empty frente instance for the form
        $frente = new FrenteTrabajo();
        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];

        return view('admin.frentes.formulario', compact('frente', 'stats', 'allFrentes', 'categorias'));
    }

    /**
     * Autocomplete de frentes para los formularios de OTROS módulos
     * (equipos/usuarios/almacén). Lo consume uicomponents.js → performFrentesFetch
     * vía GET /admin/frentes/buscar?query=. Devuelve un array [{ID_FRENTE, NOMBRE_FRENTE}]
     * (mismo set que el dropdown precargado de create(), pero filtrado por nombre).
     */
    public function search(Request $request)
    {
        $query = trim((string) $request->input('query', ''));

        $frentes = FrenteTrabajo::select('ID_FRENTE', 'NOMBRE_FRENTE')
            ->when($query !== '', fn ($q) => $q->where('NOMBRE_FRENTE', 'like', "%{$query}%"))
            ->orderBy('NOMBRE_FRENTE')
            ->limit(50)
            ->get();

        return response()->json($frentes);
    }

    /**
     * Store a newly created resource in storage.
     */    public function store(\App\Http\Requests\FrenteRequest $request)
    {
        $validated = $request->validated();
        $frente = FrenteTrabajo::create($validated);

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
        $frente = FrenteTrabajo::findOrFail($id);

        if ($request->wantsJson() || $request->has('json')) {
            return response()->json($frente);
        }

        // Stats
        $stats = FrenteTrabajo::selectRaw("
            count(case when ESTATUS_FRENTE = 'ACTIVO' then 1 end) as activos, 
            count(case when ESTATUS_FRENTE = 'FINALIZADO' then 1 end) as finalizados
        ")->first();

        // Pre-load for Search Dropdown
        $allFrentes = FrenteTrabajo::select('ID_FRENTE', 'NOMBRE_FRENTE')
            ->orderBy('NOMBRE_FRENTE')
            ->get();

        $categorias = ['FLOTA LIVIANA', 'FLOTA PESADA'];

        return view('admin.frentes.formulario', compact('frente', 'stats', 'allFrentes', 'categorias'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(\App\Http\Requests\FrenteRequest $request, string $id)
    {
        $frente = FrenteTrabajo::findOrFail($id);
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

        $frente->update($validated);

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
     * Elimina un frente fisicamente de la BD. Bloquea si tiene equipos o
     * auxiliares asignados (preserva integridad referencial sin FK fisicas —
     * el campo ID_FRENTE_ACTUAL es nullable).
     *
     * Para "archivar" un frente sin borrarlo el usuario tiene el campo
     * "Estatus del Proyecto" en el formulario que permite marcarlo como
     * FINALIZADO manualmente; el modal "Finalizados" lista esos proyectos.
     */
    public function destroy(Request $request, string $id)
    {
        $frente = FrenteTrabajo::findOrFail($id);

        $equiposAsignados    = \App\Models\Equipo::where('ID_FRENTE_ACTUAL', $id)->count();
        $auxiliaresAsignados = \App\Models\EquipoAuxiliar::where('ID_FRENTE_ACTUAL', $id)->count();

        if ($equiposAsignados > 0 || $auxiliaresAsignados > 0) {
            $partes = [];
            if ($equiposAsignados > 0)    $partes[] = "{$equiposAsignados} equipo(s)";
            if ($auxiliaresAsignados > 0) $partes[] = "{$auxiliaresAsignados} auxiliar(es)";
            $msg = 'No se puede eliminar el frente: tiene ' . implode(' y ', $partes) .
                   ' asignados. Reasignelos primero a otro frente.';

            if ($request->wantsJson() || $request->has('json')) {
                return response()->json([
                    'success' => false,
                    'message' => $msg,
                    'equipos_asignados'    => $equiposAsignados,
                    'auxiliares_asignados' => $auxiliaresAsignados,
                ], 422);
            }
            return redirect()->route('frentes.create')->with('error', $msg);
        }

        $nombre = $frente->NOMBRE_FRENTE;
        $frente->delete();

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
     * Lista los frentes en estado FINALIZADO para el modal "Acciones >
     * Frentes Finalizados". Devuelve estructura JSON minima para render.
     */
    public function finalizados(Request $request)
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'FINALIZADO')
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'UBICACION', 'TIPO_FRENTE', 'updated_at']);
        return response()->json([
            'success' => true,
            'count'   => $frentes->count(),
            'frentes' => $frentes->map(fn($f) => [
                'id'            => $f->ID_FRENTE,
                'nombre'        => $f->NOMBRE_FRENTE,
                'ubicacion'     => $f->UBICACION,
                'tipo'          => $f->TIPO_FRENTE,
                'finalizado_en' => optional($f->updated_at)->format('d/m/Y H:i'),
            ]),
        ]);
    }

    /**
     * Recupera un frente FINALIZADO marcandolo ACTIVO de nuevo. Endpoint
     * usado desde el modal de finalizados.
     */
    public function restore(Request $request, string $id)
    {
        $frente = FrenteTrabajo::findOrFail($id);
        if ($frente->ESTATUS_FRENTE !== 'FINALIZADO') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden recuperar frentes en estado FINALIZADO.',
            ], 422);
        }
        $frente->update(['ESTATUS_FRENTE' => 'ACTIVO']);
        return response()->json([
            'success' => true,
            'message' => "Frente \"{$frente->NOMBRE_FRENTE}\" recuperado correctamente.",
            'frente'  => [
                'id'     => $frente->ID_FRENTE,
                'nombre' => $frente->NOMBRE_FRENTE,
            ],
        ]);
    }

    /**
     * Lista TODOS los frentes que no tienen NINGÚN equipo ni auxiliar asignado
     * (candidatos a eliminar o desactivar), SIN importar su estatus: incluye ACTIVOS,
     * FINALIZADOS y DESACTIVADOS. Se devuelve ESTATUS_FRENTE para que el modal muestre
     * el estado y oculte "Desactivar" en los que ya no están activos.
     */
    public function sinEquipos(Request $request)
    {
        $frentes = FrenteTrabajo::whereDoesntHave('equipos')
            ->whereDoesntHave('equiposAuxiliares')
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

    // ─── MOBILE API ────────────────────────────────────────────────────────────
    public function mobileIndex()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'TIPO_FRENTE', 'UBICACION']);
        return response()->json($frentes);
    }
    // ──────────────────────────────────────────────────────────────────────────
}
