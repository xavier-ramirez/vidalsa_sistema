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
