<?php

namespace App\Http\Controllers;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Cupos de gasoil (modal de Cargar consumibles). Se elige el patio y se ve su cupo fijo (se edita ahí),
 * se cargan las cisternas surtidas (fecha y litros), que son lo que consume el cupo, y su historial
 * mes a mes. Si el cupo cambia, rige desde el mes en curso. Ver lo puede cualquiera con sesión;
 * cargar y borrar, super.admin (las rutas).
 */
class CuposCombustibleController extends Controller
{
    /** Meses de historial que se muestran por patio. */
    private const MESES = 12;

    /**
     * GET consumibles/cupos?parcial=1 — el contenido del modal (se repinta tras guardar). El modal vive
     * en Cargar consumibles: sin parcial, se va allá y se abre solo.
     */
    public function index(Request $request)
    {
        if (!$request->boolean('parcial')) {
            return redirect()->route('consumibles.cargar', ['cupos' => 1]);
        }

        return view('admin.consumibles.partials.cupos_contenido', self::datos());
    }

    /**
     * Por patio: el cupo vigente, lo que queda este mes y el historial de los últimos meses con
     * cisternas (cada mes con su cupo, lo surtido, lo que quedó y sus cisternas por fecha).
     */
    public static function datos(): array
    {
        $hoy = now()->startOfMonth()->startOfDay();
        $desde = self::desde();
        $cupos = CupoCombustible::orderByDesc('MES')->get();
        $cisternas = CisternaCupo::where('FECHA', '>=', $desde->toDateString())
            ->orderBy('FECHA')->orderBy('ID_CISTERNA')->get();

        $patios = [];
        foreach (CupoCombustible::PATIOS as $clave => $nombre) {
            // El que regía ese mes; antes del primero que se cargó, ese primero (el cupo es fijo).
            $suyos = $cupos->where('PATIO', $clave);
            $cupoDe = fn (Carbon $mes) => optional($suyos->first(fn ($c) => $c->MES->lte($mes)) ?? $suyos->last())->LITROS;

            $meses = [];
            foreach ($cisternas->where('PATIO', $clave)->groupBy(fn ($k) => $k->FECHA->format('Y-m')) as $ym => $suyas) {
                $mes = Carbon::createFromFormat('!Y-m', $ym);
                $cupo = ($c = $cupoDe($mes)) !== null ? (float) $c : null;
                $surtido = 0.0;
                $filas = $suyas->values()->map(function ($k) use (&$surtido, $cupo) {
                    $surtido = round($surtido + (float) $k->LITROS, 2);

                    return ['cisterna' => $k, 'quedan' => $cupo !== null ? round($cupo - $surtido, 2) : null];
                });
                $meses[$ym] = ['mes' => $mes, 'cupo' => $cupo, 'surtido' => $surtido, 'filas' => $filas,
                    'quedan' => $cupo !== null ? round($cupo - $surtido, 2) : null];
            }
            krsort($meses);   // el mes más reciente arriba

            $cupo = ($c = $cupoDe($hoy)) !== null ? (float) $c : null;
            $surtidoMes = $meses[$hoy->format('Y-m')]['surtido'] ?? 0.0;
            $patios[$clave] = [
                'nombre'  => $nombre,
                'cupo'    => $cupo,
                'surtido' => $surtidoMes,
                'quedan'  => $cupo !== null ? round($cupo - $surtidoMes, 2) : null,
                'meses'   => $meses,
            ];
        }

        return ['hoy' => $hoy, 'patios' => $patios];
    }

    /** POST consumibles/cupos — el cupo de un patio (patio, litros); si cambia, rige desde el mes en curso. */
    public function guardarCupo(Request $request)
    {
        $request->merge(['litros' => self::litros($request->input('litros'))]);
        $v = $request->validate([
            'patio'  => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'litros' => ['required', 'numeric', 'min:0', 'max:9999999999'],
        ], [], ['litros' => 'cupo']);

        $mes = now()->startOfMonth()->toDateString();
        $vigente = CupoCombustible::where('PATIO', $v['patio'])->where('MES', '<=', $mes)->orderByDesc('MES')->first();
        if (!$vigente || (float) $vigente->LITROS !== (float) $v['litros']) {
            CupoCombustible::updateOrCreate(
                ['PATIO' => $v['patio'], 'MES' => $mes],
                ['LITROS' => $v['litros'], 'ID_USUARIO' => auth()->id()]
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * POST consumibles/cupos/cisternas — las cisternas surtidas de un patio (la tabla del modal):
     * patio y cisternas[] con fecha (no futura, dentro del historial) y litros. Todas o ninguna.
     */
    public function agregarCisternas(Request $request)
    {
        $filas = $request->input('cisternas');
        if (is_array($filas)) {
            $request->merge(['cisternas' => array_map(
                fn ($f) => is_array($f) ? ['litros' => self::litros($f['litros'] ?? null)] + $f : $f,
                $filas
            )]);
        }
        $v = $request->validate([
            'patio'              => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'cisternas'          => ['required', 'array', 'min:1', 'max:50'],
            // Dentro del historial que se ve (si no, quedaría guardada sin poder verse ni quitarse).
            'cisternas.*.fecha'  => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:' . self::desde()->toDateString()],
            'cisternas.*.litros' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
        ], [], ['cisternas.*.fecha' => 'fecha', 'cisternas.*.litros' => 'litros']);

        DB::transaction(function () use ($v) {
            foreach ($v['cisternas'] as $f) {
                CisternaCupo::create([
                    'PATIO' => $v['patio'], 'FECHA' => $f['fecha'], 'LITROS' => $f['litros'], 'ID_USUARIO' => auth()->id(),
                ]);
            }
        });

        return response()->json(['ok' => true, 'guardadas' => count($v['cisternas'])]);
    }

    /** DELETE consumibles/cupos/cisternas/{id} */
    public function borrarCisterna(int $id)
    {
        CisternaCupo::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** El primer día del historial: el día 1 de hace MESES − 1 meses. */
    public static function desde(): Carbon
    {
        return now()->startOfMonth()->startOfDay()->subMonths(self::MESES - 1);
    }

    /**
     * Los litros como se escriben en esta pantalla, que los muestra con punto de miles: «38.000» son
     * 38000 (no 38), «1.250,5» son 1250.5, «38,5» y «38.5» son 38.5.
     */
    private static function litros($t)
    {
        if (!is_string($t)) {
            return $t;
        }
        $t = str_replace(' ', '', trim($t));
        $coma = strrpos($t, ',');
        $punto = strrpos($t, '.');
        if ($coma !== false && $punto !== false) {
            $t = $coma > $punto ? str_replace(['.', ','], ['', '.'], $t) : str_replace(',', '', $t);
        } elseif ($coma !== false) {
            $t = substr_count($t, ',') > 1 ? str_replace(',', '', $t) : str_replace(',', '.', $t);
        } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $t)) {
            $t = str_replace('.', '', $t);
        }
        return $t;
    }

    public static function nombreMes(Carbon $mes): string
    {
        return ucfirst($mes->locale('es')->translatedFormat('F Y'));
    }
}
