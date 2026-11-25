<?php

namespace App\Http\Controllers;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cupos de gasoil (Consumibles → Cupos): el cupo del mes de cada patio y las cisternas que se
 * piden de él. Ver lo puede cualquiera con sesión; cargar y borrar, super.admin (las rutas).
 */
class CuposCombustibleController extends Controller
{
    /** GET consumibles/cupos?mes=YYYY-MM — con parcial=1 devuelve solo el contenido (se repinta tras guardar). */
    public function index(Request $request)
    {
        $mes = self::mes($request->query('mes'));
        $cupos = CupoCombustible::with('cisternas')->whereDate('MES', $mes)->get()->keyBy('PATIO');

        $patios = [];
        foreach (CupoCombustible::PATIOS as $clave => $nombre) {
            $cupo = $cupos->get($clave);
            $pedido = $cupo ? (float) $cupo->cisternas->sum('LITROS') : 0.0;
            $patios[$clave] = [
                'nombre'     => $nombre,
                'cupo'       => $cupo,
                'pedido'     => $pedido,
                'disponible' => $cupo ? (float) $cupo->LITROS - $pedido : null,
            ];
        }

        $datos = ['mes' => $mes, 'patios' => $patios, 'resumen' => self::resumen($mes)];

        return $request->boolean('parcial')
            ? view('admin.consumibles.partials.cupos_contenido', $datos)
            : view('admin.consumibles.cupos', $datos);
    }

    /** POST consumibles/cupos — el cupo (litros) de un patio en un mes; si ya estaba, se cambia. */
    public function guardarCupo(Request $request)
    {
        $v = $request->validate([
            'patio'  => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'mes'    => ['required', 'date_format:Y-m'],
            'litros' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ], [], ['litros' => 'cupo']);

        CupoCombustible::updateOrCreate(
            ['PATIO' => $v['patio'], 'MES' => self::mes($v['mes'])->toDateString()],
            ['LITROS' => $v['litros'], 'ID_USUARIO' => auth()->id()]
        );

        return response()->json(['ok' => true]);
    }

    /** POST consumibles/cupos/cisternas — una cisterna pedida del cupo del patio en ese mes. */
    public function agregarCisterna(Request $request)
    {
        $v = $request->validate([
            'patio'    => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'mes'      => ['required', 'date_format:Y-m'],
            'fecha'    => ['required', 'date_format:Y-m-d'],
            'cisterna' => ['nullable', 'string', 'max:60'],
            'litros'   => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ]);

        $mes = self::mes($v['mes']);
        if (!Carbon::parse($v['fecha'])->isSameMonth($mes)) {
            return self::error('fecha', 'La fecha debe ser de ' . self::nombreMes($mes) . '.');
        }
        $cupo = CupoCombustible::where('PATIO', $v['patio'])->whereDate('MES', $mes)->first();
        if (!$cupo) {
            return self::error('litros', 'Primero cargue el cupo de ' . CupoCombustible::PATIOS[$v['patio']]
                . ' para ' . self::nombreMes($mes) . '.');
        }

        $cupo->cisternas()->create([
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

    /** El día 1 del mes pedido (YYYY-MM); si no viene o no es válido, el mes actual. */
    private static function mes($texto): Carbon
    {
        if (is_string($texto) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $texto)) {
            return Carbon::createFromFormat('!Y-m', $texto);
        }

        return now()->startOfMonth()->startOfDay();
    }

    public static function nombreMes(Carbon $mes): string
    {
        return ucfirst($mes->locale('es')->translatedFormat('F Y'));
    }

    /** Los últimos 6 meses hasta el elegido: cupo, litros pedidos y cisternas de cada patio. */
    private static function resumen(Carbon $mes): array
    {
        $desde = $mes->copy()->subMonths(5);
        $filas = DB::table('cupos_combustible as c')
            ->leftJoin('cupos_cisternas as k', 'k.ID_CUPO', '=', 'c.ID_CUPO')
            ->whereBetween('c.MES', [$desde->toDateString(), $mes->toDateString()])
            ->groupBy('c.ID_CUPO', 'c.PATIO', 'c.MES', 'c.LITROS')
            ->select('c.PATIO', 'c.MES', 'c.LITROS', DB::raw('COALESCE(SUM(k.LITROS),0) AS PEDIDO'), DB::raw('COUNT(k.ID_CISTERNA) AS CISTERNAS'))
            ->get();

        $r = [];
        for ($m = $mes->copy(); $m->gte($desde); $m->subMonth()) {
            $fila = ['mes' => $m->copy(), 'patios' => []];
            foreach (array_keys(CupoCombustible::PATIOS) as $p) {
                $f = $filas->first(fn ($x) => $x->PATIO === $p && substr($x->MES, 0, 7) === $m->format('Y-m'));
                $fila['patios'][$p] = $f ? ['cupo' => (float) $f->LITROS, 'pedido' => (float) $f->PEDIDO, 'cisternas' => (int) $f->CISTERNAS] : null;
            }
            $r[] = $fila;
        }

        return $r;
    }

    private static function error(string $campo, string $msg)
    {
        return response()->json(['message' => $msg, 'errors' => [$campo => [$msg]]], 422);
    }
}
