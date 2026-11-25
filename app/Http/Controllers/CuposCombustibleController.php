<?php

namespace App\Http\Controllers;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Cupos de gasoil (modal de Cargar consumibles): cada patio tiene un cupo fijo por mes y lo que lo
 * consume son las cisternas surtidas (fecha y litros). Si el cupo cambia, rige desde el mes en que se
 * guarda. Ver lo puede cualquiera con sesión; cargar y borrar, super.admin (las rutas).
 */
class CuposCombustibleController extends Controller
{
    /**
     * GET consumibles/cupos?mes=YYYY-MM&parcial=1 — el contenido del modal (se repinta tras guardar o al
     * cambiar de mes). El modal vive en Cargar consumibles: sin parcial, se va allá (y se abre solo).
     */
    public function index(Request $request)
    {
        if (!$request->boolean('parcial')) {
            return redirect()->route('consumibles.cargar', ['mes' => self::mes($request->query('mes'))->format('Y-m')]);
        }

        return view('admin.consumibles.partials.cupos_contenido', self::datos($request->query('mes')));
    }

    /**
     * Para un mes (YYYY-MM; si no es válido, el actual): por patio, el cupo, lo surtido y lo que queda; y
     * las cisternas de los dos patios en una sola lista, por fecha, con lo que le quedaba a su patio.
     */
    public static function datos($mesTexto): array
    {
        $mes = self::mes($mesTexto);
        $cupos = CupoCombustible::where('MES', '<=', $mes->toDateString())->orderByDesc('MES')->get();
        $cisternas = CisternaCupo::whereBetween('FECHA', [$mes->toDateString(), $mes->copy()->endOfMonth()->toDateString()])
            ->orderBy('FECHA')->orderBy('ID_CISTERNA')->get();

        $patios = [];
        foreach (CupoCombustible::PATIOS as $clave => $nombre) {
            $cupo = $cupos->first(fn ($c) => $c->PATIO === $clave);
            $patios[$clave] = ['nombre' => $nombre, 'cupo' => $cupo ? (float) $cupo->LITROS : null, 'surtido' => 0.0, 'cisternas' => 0];
        }
        $filas = $cisternas->map(function ($k) use (&$patios) {
            $p = &$patios[$k->PATIO];
            $p['surtido'] += (float) $k->LITROS;
            $p['cisternas']++;

            return ['cisterna' => $k, 'quedan' => $p['cupo'] !== null ? $p['cupo'] - $p['surtido'] : null];
        });
        foreach ($patios as &$p) {
            $p['quedan'] = $p['cupo'] !== null ? $p['cupo'] - $p['surtido'] : null;
        }
        unset($p);

        return ['mes' => $mes, 'patios' => $patios, 'filas' => $filas];
    }

    /** POST consumibles/cupos — el cupo de un patio (patio, litros); si cambia, rige desde el mes elegido. */
    public function guardarCupo(Request $request)
    {
        $v = $request->validate([
            'patio'  => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'mes'    => ['required', 'date_format:Y-m'],
            'litros' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ], [], ['litros' => 'cupo']);

        $mes = self::mes($v['mes']);
        $vigente = CupoCombustible::where('PATIO', $v['patio'])->where('MES', '<=', $mes->toDateString())->orderByDesc('MES')->first();
        if (!$vigente || (float) $vigente->LITROS !== (float) $v['litros']) {
            CupoCombustible::updateOrCreate(
                ['PATIO' => $v['patio'], 'MES' => $mes->toDateString()],
                ['LITROS' => $v['litros'], 'ID_USUARIO' => auth()->id()]
            );
        }

        return response()->json(['ok' => true]);
    }

    /** POST consumibles/cupos/cisternas — una cisterna surtida: patio, fecha (del mes elegido) y litros. */
    public function agregarCisterna(Request $request)
    {
        $v = $request->validate([
            'patio'  => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'mes'    => ['required', 'date_format:Y-m'],
            'fecha'  => ['required', 'date_format:Y-m-d'],
            'litros' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ]);

        $mes = self::mes($v['mes']);
        if (!Carbon::parse($v['fecha'])->isSameMonth($mes)) {
            $msg = 'La fecha debe ser de ' . self::nombreMes($mes) . '.';

            return response()->json(['message' => $msg, 'errors' => ['fecha' => [$msg]]], 422);
        }

        CisternaCupo::create([
            'PATIO' => $v['patio'], 'FECHA' => $v['fecha'], 'LITROS' => $v['litros'], 'ID_USUARIO' => auth()->id(),
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
}
