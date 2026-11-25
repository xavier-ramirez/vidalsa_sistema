<?php

namespace App\Http\Controllers;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Cupos de gasoil (sección de Cargar consumibles): un formulario para anotar las cisternas pedidas
 * de cada patio y un modal para el cupo mensual, que casi nunca cambia: rige desde el mes en que
 * se guarda hasta que se cambie. Ver lo puede cualquiera con sesión; cargar y borrar, super.admin.
 */
class CuposCombustibleController extends Controller
{
    /**
     * GET consumibles/cupos?mes=YYYY-MM&parcial=1 — el contenido de la sección (se repinta tras guardar
     * o al cambiar de mes). La sección vive en Cargar consumibles: sin parcial, se va allá.
     */
    public function index(Request $request)
    {
        if (!$request->boolean('parcial')) {
            return redirect()->route('consumibles.cargar', ['mes' => self::mes($request->query('mes'))->format('Y-m')]);
        }

        return view('admin.consumibles.partials.cupos_contenido', self::datos($request->query('mes')));
    }

    /** Lo que pinta la sección para un mes (YYYY-MM; si no es válido, el actual). */
    public static function datos($mesTexto): array
    {
        $mes = self::mes($mesTexto);
        $cupos = CupoCombustible::where('MES', '<=', $mes->toDateString())->orderByDesc('MES')->get();
        $cisternas = CisternaCupo::whereBetween('FECHA', [$mes->toDateString(), $mes->copy()->endOfMonth()->toDateString()])
            ->orderBy('FECHA')->orderBy('ID_CISTERNA')->get();

        $patios = [];
        foreach (CupoCombustible::PATIOS as $clave => $nombre) {
            $cupo = self::cupoVigente($cupos, $clave, $mes);
            $suyas = $cisternas->where('PATIO', $clave);
            $pedido = (float) $suyas->sum('LITROS');
            $patios[$clave] = [
                'nombre'     => $nombre,
                'cupo'       => $cupo ? (float) $cupo->LITROS : null,
                'desde'      => $cupo?->MES,
                'pedido'     => $pedido,
                'cisternas'  => $suyas->count(),
                'disponible' => $cupo ? (float) $cupo->LITROS - $pedido : null,
            ];
        }

        return ['mes' => $mes, 'patios' => $patios, 'cisternas' => $cisternas, 'historial' => self::historial($mes, $cupos)];
    }

    /**
     * POST consumibles/cupos — el cupo mensual de cada patio (cupos[TIGRE], cupos[MATURIN]) desde el
     * mes elegido. Uno vacío o igual al que ya rige no se toca.
     */
    public function guardarCupo(Request $request)
    {
        $v = $request->validate([
            'mes'     => ['required', 'date_format:Y-m'],
            'cupos'   => ['required', 'array'],
            'cupos.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ], [], ['cupos.*' => 'cupo']);

        $mes = self::mes($v['mes']);
        $cupos = CupoCombustible::where('MES', '<=', $mes->toDateString())->orderByDesc('MES')->get();
        foreach (array_keys(CupoCombustible::PATIOS) as $patio) {
            $litros = $v['cupos'][$patio] ?? null;
            if ($litros === null || $litros === '') {
                continue;
            }
            $vigente = self::cupoVigente($cupos, $patio, $mes);
            if ($vigente && (float) $vigente->LITROS === (float) $litros) {
                continue;
            }
            CupoCombustible::updateOrCreate(
                ['PATIO' => $patio, 'MES' => $mes->toDateString()],
                ['LITROS' => $litros, 'ID_USUARIO' => auth()->id()]
            );
        }

        return response()->json(['ok' => true]);
    }

    /** POST consumibles/cupos/cisternas — una cisterna pedida del cupo de un patio en el mes elegido. */
    public function agregarCisterna(Request $request)
    {
        $v = $request->validate([
            'patio'    => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'mes'      => ['required', 'date_format:Y-m'],
            'fecha'    => ['required', 'date_format:Y-m-d'],
            'cisterna' => ['nullable', 'string', 'max:60'],
            'litros'   => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ], [], ['patio' => 'patio']);

        $mes = self::mes($v['mes']);
        if (!Carbon::parse($v['fecha'])->isSameMonth($mes)) {
            $msg = 'La fecha debe ser de ' . self::nombreMes($mes) . '.';

            return response()->json(['message' => $msg, 'errors' => ['fecha' => [$msg]]], 422);
        }

        CisternaCupo::create([
            'PATIO'      => $v['patio'],
            'FECHA'      => $v['fecha'],
            'CISTERNA'   => ($c = trim((string) ($v['cisterna'] ?? ''))) === '' ? null : mb_strtoupper($c),
            'LITROS'     => $v['litros'],
            'ID_USUARIO' => auth()->id(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** DELETE consumibles/cupos/cisternas/{id} */
    public function borrarCisterna(int $id)
    {
        CisternaCupo::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    public static function nombreMes(Carbon $mes): string
    {
        return ucfirst($mes->locale('es')->translatedFormat('F Y'));
    }

    /** El día 1 del mes pedido (YYYY-MM); si no viene o no es válido, el mes actual. */
    private static function mes($texto): Carbon
    {
        if (is_string($texto) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $texto)) {
            return Carbon::createFromFormat('!Y-m', $texto);
        }

        return now()->startOfMonth()->startOfDay();
    }

    /** El cupo que rige en un mes: el último guardado desde ese mes o antes ($cupos, del más nuevo al más viejo). */
    private static function cupoVigente($cupos, string $patio, Carbon $mes): ?CupoCombustible
    {
        return $cupos->first(fn ($c) => $c->PATIO === $patio && $c->MES->lte($mes));
    }

    /** Los últimos 6 meses hasta el elegido: cupo que regía y litros pedidos de cada patio. */
    private static function historial(Carbon $mes, $cupos): array
    {
        $desde = $mes->copy()->subMonths(5);
        $pedidos = CisternaCupo::whereBetween('FECHA', [$desde->toDateString(), $mes->copy()->endOfMonth()->toDateString()])
            ->selectRaw("PATIO, DATE_FORMAT(FECHA, '%Y-%m') AS MES, SUM(LITROS) AS LITROS, COUNT(*) AS N")
            ->groupBy('PATIO', 'MES')->get();

        $r = [];
        for ($m = $mes->copy(); $m->gte($desde); $m->subMonth()) {
            $fila = ['mes' => $m->copy(), 'patios' => []];
            foreach (array_keys(CupoCombustible::PATIOS) as $p) {
                $cupo = self::cupoVigente($cupos, $p, $m);
                $ped = $pedidos->first(fn ($x) => $x->PATIO === $p && $x->MES === $m->format('Y-m'));
                $fila['patios'][$p] = ['cupo' => $cupo ? (float) $cupo->LITROS : null, 'pedido' => (float) ($ped->LITROS ?? 0), 'n' => (int) ($ped->N ?? 0)];
            }
            $r[] = $fila;
        }

        return $r;
    }
}
