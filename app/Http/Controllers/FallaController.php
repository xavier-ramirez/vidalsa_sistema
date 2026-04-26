<?php

namespace App\Http\Controllers;

use App\Models\Falla;
use App\Models\Equipo;
use App\Models\EquipoAuxiliar;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Modulo de Reportes de Fallas (/admin/fallas).
 *
 * Permiso global: equipos.edit (gateado en routes/web.php).
 *
 * Soporta dos modalidades:
 *   - corto:   solo descripcion + estado del equipo (registro rapido).
 *   - extenso: campos del formato corporativo + genera PDF acta.
 *
 * Cambio de estado SIN reporte: existe el endpoint changeEstado() para
 * actualizar OPERATIVO ↔ INOPERATIVO ↔ EN MANTENIMIENTO sin crear falla.
 * Queda registrado en fallas_audit_log con accion=change_estado.
 */
class FallaController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Todo el modulo gateado por equipos.edit. Gate::before resuelve super.admin.
        $this->middleware('can:equipos.edit');
    }

    /**
     * Listado con filtros y paginacion server-side.
     */
    public function index(Request $request)
    {
        $query = Falla::query();

        // Filtros
        if ($request->filled('estatus')) {
            $query->where('ESTADO_REPORTE', $request->estatus);
        }
        if ($request->filled('tipo_activo') && in_array($request->tipo_activo, ['equipo', 'equipo_auxiliar'])) {
            $query->where('ACTIVO_TIPO', $request->tipo_activo);
        }
        if ($request->filled('fecha_desde')) {
            $query->whereDate('FECHA_EMISION', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('FECHA_EMISION', '<=', $request->fecha_hasta);
        }
        if ($request->filled('responsable')) {
            $query->where('ID_USUARIO_REPORTA', $request->responsable);
        }
        if ($request->filled('search')) {
            $s = trim($request->input('search'));
            $query->where(function ($q) use ($s) {
                $q->where('CODIGO_REPORTE', 'like', "%{$s}%")
                  ->orWhere('DESCRIPCION_AVERIA', 'like', "%{$s}%")
                  ->orWhere('NOMBRE_REPORTA', 'like', "%{$s}%");
            });
        }

        $fallas = $query->orderByDesc('FECHA_EMISION')->paginate(50);

        // Hidrata el activo en cada fila para mostrar marca/modelo/foto
        $fallas->getCollection()->transform(function ($f) {
            $f->_activo = $f->activo();
            return $f;
        });

        // Stats globales del dashboard (TOTAL / INOPERATIVO / MANTENIMIENTO).
        // Mezcla equipos + auxiliares.
        $stats = $this->buildStats();

        if ($request->wantsJson()) {
            return response()->json([
                'html'  => view('admin.fallas.partials.table_rows', compact('fallas'))->render(),
                'stats' => $stats,
                'pagination' => $fallas->links('vendor.pagination.custom-sliding')->toHtml(),
            ]);
        }

        // Listas para filtros
        $responsables = Usuario::whereIn('ID_USUARIO',
                Falla::distinct()->pluck('ID_USUARIO_REPORTA')->filter()
            )->orderBy('NOMBRE_COMPLETO')->get(['ID_USUARIO', 'NOMBRE_COMPLETO']);

        return view('admin.fallas.index', compact('fallas', 'stats', 'responsables'));
    }

    /**
     * Crear reporte de falla (modalidad corto o extenso).
     * Pone el equipo en estado especificado (INOPERATIVO / EN MANTENIMIENTO).
     */
    public function store(Request $request)
    {
        $request->validate([
            'tipo_reporte'    => 'required|in:corto,extenso',
            'activo_tipo'     => 'required|in:equipo,equipo_auxiliar',
            'activo_id'       => 'required|integer',
            'estado_al_crear' => 'required|in:INOPERATIVO,EN MANTENIMIENTO',
            'descripcion'     => 'required_if:tipo_reporte,extenso|nullable|string|max:5000',
            'horometro'       => 'nullable|string|max:50',
            'sistema'         => 'nullable|in:MOTOR,HIDRAULICO,ELECTRICO,NEUMATICO,TRANSMISION,ESTRUCTURAL,FRENOS,OTROS',
            'prioridad'       => 'nullable|in:CRITICA,ALTA,MEDIA,BAJA',
            'tipo_intervencion' => 'nullable|in:CORRECTIVO_INMEDIATO,PROGRAMADO',
            'repuestos'       => 'nullable|string|max:5000',
            'observaciones'   => 'nullable|string|max:5000',
        ]);

        return DB::transaction(function () use ($request) {
            // Lock del activo y guarda estado previo
            $activo = $this->lockActivo($request->activo_tipo, $request->activo_id);
            if (!$activo) {
                return response()->json(['success' => false, 'message' => 'Activo no encontrado'], 404);
            }
            $estadoPrevio = $activo->ESTADO_OPERATIVO;

            $user = auth()->user();

            $falla = Falla::create([
                'CODIGO_REPORTE'         => $this->generateCodigoReporte(),
                'FECHA_EMISION'          => now(),
                'TIPO_REPORTE'           => $request->tipo_reporte,
                'ESTADO_REPORTE'         => 'abierto',
                'ACTIVO_TIPO'            => $request->activo_tipo,
                'ACTIVO_ID'              => $request->activo_id,
                'ESTADO_PREVIO'          => $estadoPrevio,
                'ESTADO_AL_CREAR'        => $request->estado_al_crear,
                'HOROMETRO_ACTUAL'       => $request->horometro,
                'DESCRIPCION_AVERIA'     => $request->descripcion,
                'SISTEMA_AFECTADO'       => $request->sistema,
                'PRIORIDAD'              => $request->prioridad,
                'TIPO_INTERVENCION'      => $request->tipo_intervencion,
                'REPUESTOS_ESTIMADOS'    => $request->repuestos,
                'OBSERVACIONES_MECANICO' => $request->observaciones,
                'ID_USUARIO_REPORTA'     => $user->ID_USUARIO,
                'NOMBRE_REPORTA'         => $user->NOMBRE_COMPLETO,
                'CARGO_REPORTA'          => optional($user->rol)->NOMBRE_ROL ?? '',
                'EMAIL_REPORTA'          => $user->CORREO_ELECTRONICO,
            ]);

            // Aplica el estado al activo
            $activo->ESTADO_OPERATIVO = $request->estado_al_crear;
            $activo->save();

            $this->logAction($falla->ID_FALLA, $request->activo_tipo, $request->activo_id, 'create_falla', [
                'codigo'           => $falla->CODIGO_REPORTE,
                'tipo'             => $falla->TIPO_REPORTE,
                'estado_previo'    => $estadoPrevio,
                'estado_al_crear'  => $request->estado_al_crear,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Reporte de falla creado.',
                'falla'   => $falla,
            ]);
        });
    }

    /**
     * Cierra un reporte de falla y opcionalmente regresa el equipo a OPERATIVO.
     */
    public function close(Request $request, $id)
    {
        $request->validate([
            'restaurar_estado' => 'nullable|boolean',
            'observaciones_cierre' => 'nullable|string|max:5000',
        ]);

        return DB::transaction(function () use ($request, $id) {
            $falla = Falla::lockForUpdate()->findOrFail($id);
            if ($falla->ESTADO_REPORTE === 'cerrado') {
                return response()->json(['success' => false, 'message' => 'El reporte ya esta cerrado'], 422);
            }

            $user = auth()->user();
            $falla->ESTADO_REPORTE       = 'cerrado';
            $falla->FECHA_CIERRE         = now();
            $falla->ID_USUARIO_CIERRA    = $user->ID_USUARIO;
            $falla->NOMBRE_CIERRA        = $user->NOMBRE_COMPLETO;
            $falla->CARGO_CIERRA         = optional($user->rol)->NOMBRE_ROL ?? '';
            $falla->OBSERVACIONES_CIERRE = $request->input('observaciones_cierre');
            $falla->save();

            // Restaurar estado del activo a OPERATIVO si el usuario lo pidio
            if ($request->boolean('restaurar_estado')) {
                $activo = $this->lockActivo($falla->ACTIVO_TIPO, $falla->ACTIVO_ID);
                if ($activo) {
                    $activo->ESTADO_OPERATIVO = 'OPERATIVO';
                    $activo->save();
                }
            }

            $this->logAction($falla->ID_FALLA, $falla->ACTIVO_TIPO, $falla->ACTIVO_ID, 'close_falla', [
                'restaurar_estado' => $request->boolean('restaurar_estado'),
            ]);

            return response()->json(['success' => true, 'message' => 'Reporte cerrado.']);
        });
    }

    /**
     * Cambio de estado SIN reporte (actualizacion simple). Pedido del
     * usuario: OPERATIVO ↔ INOPERATIVO ↔ EN MANTENIMIENTO sin crear falla.
     * Queda en fallas_audit_log para trazabilidad.
     */
    public function changeEstado(Request $request)
    {
        $request->validate([
            'activo_tipo' => 'required|in:equipo,equipo_auxiliar',
            'activo_id'   => 'required|integer',
            'nuevo_estado'=> 'required|in:OPERATIVO,INOPERATIVO,EN MANTENIMIENTO',
        ]);

        return DB::transaction(function () use ($request) {
            $activo = $this->lockActivo($request->activo_tipo, $request->activo_id);
            if (!$activo) {
                return response()->json(['success' => false, 'message' => 'Activo no encontrado'], 404);
            }
            $estadoPrevio = $activo->ESTADO_OPERATIVO;
            if ($estadoPrevio === $request->nuevo_estado) {
                return response()->json(['success' => false, 'message' => 'El equipo ya esta en ese estado'], 422);
            }

            $activo->ESTADO_OPERATIVO = $request->nuevo_estado;
            $activo->save();

            $this->logAction(null, $request->activo_tipo, $request->activo_id, 'change_estado', [
                'estado_previo' => $estadoPrevio,
                'estado_nuevo'  => $request->nuevo_estado,
            ]);

            return response()->json(['success' => true, 'message' => 'Estado actualizado.']);
        });
    }

    /**
     * Busqueda inteligente de activos por placa / serial / codigo.
     * Devuelve [{tipo, id, label, placa, serial, marca, modelo, estado, foto}, ...]
     */
    public function searchActivos(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        if (strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $qUpper = mb_strtoupper($q);
        $like = "%{$qUpper}%";

        $eqs = Equipo::query()
            ->leftJoin('documentacion AS d', 'equipos.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->select('equipos.ID_EQUIPO', 'equipos.CODIGO_PATIO', 'equipos.MARCA',
                     'equipos.MODELO', 'equipos.SERIAL_CHASIS', 'equipos.ESTADO_OPERATIVO',
                     'equipos.FOTO_EQUIPO', 'd.PLACA')
            ->where(function ($w) use ($like) {
                $w->where('equipos.CODIGO_PATIO', 'like', $like)
                  ->orWhere('equipos.SERIAL_CHASIS', 'like', $like)
                  ->orWhere('equipos.MARCA', 'like', $like)
                  ->orWhere('d.PLACA', 'like', $like);
            })->limit(15)->get();

        $aux = EquipoAuxiliar::query()
            ->where(function ($w) use ($like) {
                $w->where('SERIAL', 'like', $like)
                  ->orWhere('CODIGO_INTERNO', 'like', $like)
                  ->orWhere('MARCA', 'like', $like)
                  ->orWhere('MODELO', 'like', $like);
            })->limit(15)->get();

        $results = [];
        foreach ($eqs as $e) {
            $results[] = [
                'tipo'   => 'equipo',
                'id'     => $e->ID_EQUIPO,
                'label'  => trim(($e->MARCA ?? '') . ' ' . ($e->MODELO ?? '')),
                'placa'  => $e->PLACA ?? '',
                'serial' => $e->SERIAL_CHASIS ?? '',
                'codigo' => $e->CODIGO_PATIO ?? '',
                'estado' => $e->ESTADO_OPERATIVO ?? '',
                'foto'   => $e->FOTO_EQUIPO ?? '',
            ];
        }
        foreach ($aux as $a) {
            $results[] = [
                'tipo'   => 'equipo_auxiliar',
                'id'     => $a->ID_AUXILIAR,
                'label'  => trim(($a->MARCA ?? '') . ' ' . ($a->MODELO ?? '')),
                'placa'  => '',
                'serial' => $a->SERIAL ?? '',
                'codigo' => $a->CODIGO_INTERNO ?? '',
                'estado' => $a->ESTADO_OPERATIVO ?? '',
                'foto'   => $a->FOTO ?? '',
            ];
        }

        return response()->json(['results' => $results]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Lock pesimista del activo para evitar carreras al actualizar estado.
     * Retorna el modelo (Equipo o EquipoAuxiliar) o null si no existe.
     */
    private function lockActivo(string $tipo, int $id)
    {
        if ($tipo === 'equipo') {
            return Equipo::where('ID_EQUIPO', $id)->lockForUpdate()->first();
        }
        if ($tipo === 'equipo_auxiliar') {
            return EquipoAuxiliar::where('ID_AUXILIAR', $id)->lockForUpdate()->first();
        }
        return null;
    }

    /**
     * Genera RF-NNNNN secuencial. Numerador derivado del MAX actual + 1.
     * Llamar dentro de DB::transaction (lockForUpdate del MAX).
     */
    private function generateCodigoReporte(): string
    {
        $last = Falla::where('CODIGO_REPORTE', 'like', 'RF-%')
            ->lockForUpdate()
            ->orderByDesc('ID_FALLA')
            ->value('CODIGO_REPORTE');
        $n = 0;
        if ($last && preg_match('/RF-(\d+)/', $last, $m)) {
            $n = (int) $m[1];
        }
        return 'RF-' . str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Inserta una entrada en fallas_audit_log. La tabla NO tiene FK
     * enforced — guarda snapshot del usuario para que el log persista
     * aunque el usuario se borre.
     */
    private function logAction(?int $idFalla, string $activoTipo, int $activoId, string $accion, array $meta = []): void
    {
        $user = auth()->user();
        DB::table('fallas_audit_log')->insert([
            'ID_FALLA'      => $idFalla,
            'ACTIVO_TIPO'   => $activoTipo,
            'ACTIVO_ID'     => $activoId,
            'ACCION'        => $accion,
            'METADATA'      => json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ID_USUARIO'    => $user->ID_USUARIO,
            'NOMBRE_USUARIO'=> $user->NOMBRE_COMPLETO,
            'EMAIL_USUARIO' => $user->CORREO_ELECTRONICO,
            'CREADO_EN'     => now(),
        ]);
    }

    /**
     * Stats del sidebar: TOTAL flota (vehiculos + aux), INOPERATIVO,
     * EN MANTENIMIENTO. No filtra por scope LOCAL — los super.admin
     * ven todo, los locales tambien (la accion ya esta gateada por
     * equipos.edit).
     */
    private function buildStats(): array
    {
        $eqTotal = Equipo::where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')->count();
        $auxTotal = EquipoAuxiliar::where('ESTADO_OPERATIVO', '!=', 'DESINCORPORADO')->count();

        $eqIno = Equipo::where('ESTADO_OPERATIVO', 'INOPERATIVO')->count();
        $auxIno = EquipoAuxiliar::where('ESTADO_OPERATIVO', 'INOPERATIVO')->count();

        $eqMan = Equipo::where('ESTADO_OPERATIVO', 'EN MANTENIMIENTO')->count();
        $auxMan = EquipoAuxiliar::where('ESTADO_OPERATIVO', 'EN MANTENIMIENTO')->count();

        return [
            'total'         => $eqTotal + $auxTotal,
            'inoperativo'   => $eqIno + $auxIno,
            'mantenimiento' => $eqMan + $auxMan,
            'reportes_abiertos' => Falla::where('ESTADO_REPORTE', 'abierto')->count(),
        ];
    }

    /**
     * PDF acta del reporte (TCPDF). Solo aplica a TIPO_REPORTE=extenso.
     */
    public function pdf($id)
    {
        $falla = Falla::findOrFail($id);
        if ($falla->TIPO_REPORTE !== 'extenso') {
            return back()->withErrors(['error' => 'Solo los reportes extensos generan acta PDF.']);
        }

        $activo = $falla->activo();

        // Reusamos la clase ActaTrasladoPDF (definida al final de
        // MovilizacionController.php) — mismo namespace App\Http\Controllers,
        // mismo header/footer corporativo.
        $pdf = new ActaTrasladoPDF(
            PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false
        );
        $pdf->frenteOrigen = 'REPORTE DE FALLA';
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(15, 42, 15);
        $pdf->SetHeaderMargin(8);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        $html = view('admin.fallas.acta_falla_pdf', compact('falla', 'activo'))->render();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->Output('Reporte_Falla_' . $falla->CODIGO_REPORTE . '.pdf', 'D');
    }
}
