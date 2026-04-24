<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\FrenteTrabajo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EquipoAuxiliarController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    // ═══════════════════════════════════════════════════════════
    // LISTADO
    // ═══════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        $query = EquipoAuxiliar::with(['frente', 'equipoHost.documentacion']);

        if ($request->filled('tipo') && $request->tipo !== 'all') {
            $query->where('TIPO', $request->tipo);
        }
        if ($request->filled('id_frente') && $request->id_frente !== 'all') {
            $query->where('ID_FRENTE_ACTUAL', $request->id_frente);
        }
        if ($request->filled('estado') && $request->estado !== 'all') {
            $query->where('ESTADO_OPERATIVO', $request->estado);
        }
        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('SERIAL', 'like', "%{$s}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$s}%")
                  ->orWhere('MARCA', 'like', "%{$s}%")
                  ->orWhere('MODELO', 'like', "%{$s}%");
            });
        }

        $auxiliares = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get();
        $tipos   = EquipoAuxiliar::tiposLabel();
        $estados = EquipoAuxiliar::estadosLabel();

        if ($request->wantsJson()) {
            return response()->json([
                'html'       => view('admin.equipos_auxiliares.partials.table_rows', compact('auxiliares'))->render(),
                'pagination' => $auxiliares->links('vendor.pagination.custom-sliding')->toHtml(),
                'count'      => $auxiliares->total(),
            ]);
        }

        return view('admin.equipos_auxiliares.index', compact('auxiliares', 'frentes', 'tipos', 'estados'));
    }

    public function count()
    {
        return response()->json(['total' => EquipoAuxiliar::count()]);
    }

    // ═══════════════════════════════════════════════════════════
    // CRUD
    // ═══════════════════════════════════════════════════════════
    public function create()
    {
        $frentes = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get();
        $tipos   = EquipoAuxiliar::tiposLabel();
        $estados = EquipoAuxiliar::estadosLabel();
        $auxiliar = new EquipoAuxiliar();
        return view('admin.equipos_auxiliares.create', compact('auxiliar', 'frentes', 'tipos', 'estados'));
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);

        $data['CREADO_POR'] = auth()->id();
        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD'] as $f) {
            if (!empty($data[$f])) $data[$f] = strtoupper(trim($data[$f]));
        }

        $auxiliar = EquipoAuxiliar::create($data);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar registrado correctamente.',
                'redirect' => route('equipos-auxiliares.index'),
            ]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar registrado correctamente.');
    }

    public function edit($id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $frentes  = FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')->orderBy('NOMBRE_FRENTE')->get();
        $tipos    = EquipoAuxiliar::tiposLabel();
        $estados  = EquipoAuxiliar::estadosLabel();
        return view('admin.equipos_auxiliares.edit', compact('auxiliar', 'frentes', 'tipos', 'estados'));
    }

    public function update(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $data = $this->validateData($request, false);

        foreach (['MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD'] as $f) {
            if (!empty($data[$f])) $data[$f] = strtoupper(trim($data[$f]));
        }

        $auxiliar->update($data);

        if ($request->wantsJson()) {
            return response()->json([
                'success'  => true,
                'message'  => 'Equipo auxiliar actualizado correctamente.',
                'redirect' => route('equipos-auxiliares.index'),
            ]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar actualizado correctamente.');
    }

    public function destroy(Request $request, $id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $auxiliar->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }
        return redirect()->route('equipos-auxiliares.index')->with('success', 'Equipo auxiliar eliminado.');
    }

    // ═══════════════════════════════════════════════════════════
    // ANCHOR 1:N (tope 2 auxiliares por equipo host)
    // ═══════════════════════════════════════════════════════════
    public function anchor(Request $request, $id)
    {
        $request->validate([
            'id_equipo_host' => 'required|exists:equipos,ID_EQUIPO',
        ]);

        try {
            DB::transaction(function () use ($request, $id) {
                $auxiliar = EquipoAuxiliar::lockForUpdate()->findOrFail($id);
                $hostId   = $request->id_equipo_host;

                // Verificar tope: no mas de N auxiliares por equipo host (excluyendo este mismo)
                $actuales = EquipoAuxiliar::where('ID_EQUIPO_HOST', $hostId)
                    ->where('ID_AUXILIAR', '!=', $id)
                    ->lockForUpdate()
                    ->count();

                if ($actuales >= EquipoAuxiliar::ANCHOR_MAX_PER_HOST) {
                    throw new \RuntimeException(
                        'El equipo host ya tiene el maximo permitido de ' .
                        EquipoAuxiliar::ANCHOR_MAX_PER_HOST . ' auxiliares anclados.'
                    );
                }

                $auxiliar->update(['ID_EQUIPO_HOST' => $hostId]);
            });

            return response()->json(['success' => true, 'message' => 'Equipo auxiliar anclado correctamente.']);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('EquipoAuxiliar anchor falló: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al anclar el equipo.'], 500);
        }
    }

    public function unanchor($id)
    {
        $auxiliar = EquipoAuxiliar::findOrFail($id);
        $auxiliar->update(['ID_EQUIPO_HOST' => null]);
        return response()->json(['success' => true, 'message' => 'Equipo auxiliar desanclado.']);
    }

    // ═══════════════════════════════════════════════════════════
    // SEARCH — autocomplete para anclaje
    // ═══════════════════════════════════════════════════════════
    public function search(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        $results = EquipoAuxiliar::with('equipoHost.documentacion')
            ->where(function ($w) use ($q) {
                $w->where('SERIAL', 'like', "%{$q}%")
                  ->orWhere('CODIGO_INTERNO', 'like', "%{$q}%")
                  ->orWhere('MARCA', 'like', "%{$q}%")
                  ->orWhere('MODELO', 'like', "%{$q}%");
            })
            ->limit(20)
            ->get()
            ->map(function ($a) {
                return [
                    'id'          => $a->ID_AUXILIAR,
                    'tipo'        => $a->TIPO,
                    'tipo_label'  => EquipoAuxiliar::tiposLabel()[$a->TIPO] ?? $a->TIPO,
                    'marca'       => $a->MARCA,
                    'modelo'      => $a->MODELO,
                    'serial'      => $a->SERIAL,
                    'host_id'     => $a->ID_EQUIPO_HOST,
                    'host_codigo' => optional($a->equipoHost)->CODIGO_PATIO,
                    'host_placa'  => optional(optional($a->equipoHost)->documentacion)->PLACA,
                ];
            });

        return response()->json($results);
    }

    public function searchHosts(Request $request)
    {
        $q = trim($request->get('q', ''));
        if (strlen($q) < 2) return response()->json([]);

        $results = Equipo::with('documentacion', 'tipo', 'equiposAuxiliares')
            ->where(function ($w) use ($q) {
                $w->where('CODIGO_PATIO', 'like', "%{$q}%")
                  ->orWhere('SERIAL_CHASIS', 'like', "%{$q}%")
                  ->orWhere('MARCA', 'like', "%{$q}%")
                  ->orWhere('MODELO', 'like', "%{$q}%");
            })
            ->limit(20)
            ->get()
            ->map(function ($e) {
                return [
                    'id'            => $e->ID_EQUIPO,
                    'codigo'        => $e->CODIGO_PATIO,
                    'placa'         => optional($e->documentacion)->PLACA,
                    'tipo'          => optional($e->tipo)->nombre,
                    'marca_modelo'  => trim(($e->MARCA ?? '') . ' ' . ($e->MODELO ?? '')),
                    'auxiliares_anclados' => $e->equiposAuxiliares->count(),
                    'disponible'    => $e->equiposAuxiliares->count() < EquipoAuxiliar::ANCHOR_MAX_PER_HOST,
                ];
            });

        return response()->json($results);
    }

    // ═══════════════════════════════════════════════════════════
    // ACTA DE ASIGNACION (HTML imprimible; sin PDF para minimizar deps)
    // ═══════════════════════════════════════════════════════════
    public function actaAsignacion($id)
    {
        $auxiliar = EquipoAuxiliar::with(['frente', 'equipoHost.documentacion', 'equipoHost.tipo', 'creador'])
            ->findOrFail($id);
        return view('admin.equipos_auxiliares.acta', compact('auxiliar'));
    }

    // ═══════════════════════════════════════════════════════════
    // VALIDATION
    // ═══════════════════════════════════════════════════════════
    private function validateData(Request $request, bool $isCreate = true): array
    {
        $rules = [
            'TIPO'             => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::tiposLabel())),
            'MARCA'            => 'nullable|string|max:80',
            'MODELO'           => 'nullable|string|max:80',
            'SERIAL'           => 'nullable|string|max:100',
            'CODIGO_INTERNO'   => 'nullable|string|max:50',
            'CAPACIDAD'        => 'nullable|string|max:80',
            'ANIO'             => 'nullable|integer|min:1950|max:2100',
            'ESTADO_OPERATIVO' => 'required|string|in:' . implode(',', array_keys(EquipoAuxiliar::estadosLabel())),
            'ID_FRENTE_ACTUAL' => 'nullable|exists:frentes_trabajo,ID_FRENTE',
            'ID_EQUIPO_HOST'   => 'nullable|exists:equipos,ID_EQUIPO',
            'OBSERVACIONES'    => 'nullable|string|max:500',
        ];

        if (!$isCreate) {
            foreach ($rules as $k => $v) {
                $rules[$k] = 'sometimes|' . $v;
            }
        }

        return $request->validate($rules);
    }
}
