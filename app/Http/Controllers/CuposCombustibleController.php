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
    use \App\Traits\ExcelLogoCorporativo;

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

    /**
     * GET consumibles/cupos/excel?patio=TIGRE — el historial de un patio en Excel: lo mismo que el
     * modal (mes a mes, del más reciente al más viejo; cada cisterna con lo que quedaba del cupo
     * tras surtirla y, al cierre de cada mes, su total).
     */
    public function excel(Request $request)
    {
        $patio = $request->validate(['patio' => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))]])['patio'];
        $p = self::datos()['patios'][$patio];

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $libro->getProperties()->setCreator('Sistema VIDALSA')->setTitle('Historial de cupo de gasoil')
            ->setCompany('Constructora Vidalsa 27, C.A.');
        $libro->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Historial');
        foreach (['A' => 14, 'B' => 16, 'C' => 18, 'D' => 18, 'E' => 18, 'F' => 18] as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
        $this->encabezadoCorporativo($hoja, "HISTORIAL DE CUPO DE GASOIL\n" . mb_strtoupper($p['nombre']), 'D', 'E', 'F');
        $this->cabeceraTablaCorporativa($hoja, ['FECHA', 'MES', 'CUPO DEL MES (L)', 'LITROS (L)', 'SURTIDO EN EL MES (L)', 'QUEDAN DEL CUPO (L)']);

        $f = 6;
        $totales = [];
        foreach ($p['meses'] as $m) {
            $surtido = 0.0;
            foreach ($m['filas'] as $fila) {
                $k = $fila['cisterna'];
                $surtido = round($surtido + (float) $k->LITROS, 2);
                $hoja->fromArray([\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($k->FECHA->copy()->startOfDay()), self::nombreMes($m['mes']),
                    $m['cupo'], (float) $k->LITROS, $surtido, $fila['quedan']], null, 'A' . $f, true);
                $f++;
            }
            $n = $m['filas']->count();
            $hoja->fromArray(['TOTAL ' . mb_strtoupper(self::nombreMes($m['mes'])) . ' (' . ($n === 1 ? '1 cisterna' : $n . ' cisternas') . ')',
                null, $m['cupo'], $m['surtido'], $m['surtido'], $m['quedan']], null, 'A' . $f, true);
            $hoja->mergeCells("A{$f}:B{$f}");
            $totales[] = $f;
            $f++;
        }
        if ($f === 6) {
            $hoja->setCellValue('A6', 'Todavía no hay cisternas de ' . $p['nombre'] . '.');
            $hoja->mergeCells('A6:F6');
            $f = 7;
        }
        $ultima = $f - 1;

        $this->rellenarFilas($hoja, $totales, 'F', 'FFE2E8F0');   // antes de alinear (copia el estilo entero)
        foreach ($totales as $t) {
            $hoja->getStyle("A{$t}:F{$t}")->getFont()->setBold(true);
        }
        $hoja->getStyle("A6:F{$ultima}")->applyFromArray(self::bordeFinoCorporativo());
        $hoja->getStyle("A6:F{$ultima}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $hoja->getStyle("A6:B{$ultima}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("A6:A{$ultima}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $hoja->getStyle("C6:F{$ultima}")->getNumberFormat()->setFormatCode('#,##0.##');
        // Lo pasado del cupo, en rojo (como en el modal).
        $rojo = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
        $rojo->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_CELLIS)
            ->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_LESSTHAN)->addCondition('0');
        $rojo->getStyle()->getFont()->getColor()->setARGB('FFDC2626');
        $hoja->getStyle("F6:F{$ultima}")->setConditionalStyles([$rojo]);
        $hoja->freezePane('A6');

        $nombre = 'Historial_Cupo_Gasoil_' . str_replace(' ', '_', $p['nombre']) . '_' . date('Y-m-d') . '.xlsx';
        $escritor = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro);

        return response()->streamDownload(fn () => $escritor->save('php://output'), $nombre, [
            'Content-Type'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
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
