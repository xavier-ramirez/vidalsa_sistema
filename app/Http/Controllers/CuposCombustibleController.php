<?php

namespace App\Http\Controllers;

use App\Models\CisternaCupo;
use App\Models\CupoCombustible;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cupos de gasoil (modal de Cargar consumibles). Arriba el patio y su cupo fijo (se edita ahí); debajo,
 * UNA tabla con las cisternas del mes elegido: cada una es una solicitud (número y fecha del plan de
 * despacho) a la que, al llegar, se le pone la fecha real de llegada, los litros y la foto. Solo las
 * que llegaron consumen el cupo. Si el cupo cambia, rige desde el mes en curso. Ver lo puede
 * cualquiera con sesión; cargar y borrar, super.admin (las rutas). Los mismos datos arman las láminas
 * de cisternas, cupo, histórico y cronograma del PowerPoint de Gráficos (presentacion()).
 */
class CuposCombustibleController extends Controller
{
    use \App\Traits\ExcelLogoCorporativo;
    use \App\Traits\ConvertsImageToWebp;

    /** Ancho tope (px) de la foto de una cisterna: alcanza para leer la guía o el contador. */
    private const FOTO_ANCHO = 1600;

    /** Meses hacia atrás que se pueden ver y cargar (con el actual). */
    private const MESES = 12;

    /** Meses hacia adelante para programar solicitudes. */
    private const ADELANTE = 3;

    /**
     * GET consumibles/cupos?parcial=1[&mes=YYYY-MM][&patio=X] — el contenido del modal; con patio, solo
     * el panel de ese patio (se repinta tras guardar sin tocar lo escrito en el otro). Sin parcial, se va
     * a Cargar consumibles y el modal se abre solo.
     */
    public function index(Request $request)
    {
        if (!$request->boolean('parcial')) {
            return redirect()->route('consumibles.cargar', ['cupos' => 1]);
        }
        $datos = self::datos($request->query('mes'));
        $patio = $request->query('patio');
        if ($patio && isset($datos['patios'][$patio])) {
            return view('admin.consumibles.partials.cupos_panel', [
                'clave' => $patio, 'p' => $datos['patios'][$patio], 'mes' => $datos['mes'], 'meses' => $datos['meses'],
            ]);
        }

        return view('admin.consumibles.partials.cupos_contenido', $datos);
    }

    /**
     * Para el mes pedido (por defecto el actual): por patio, el cupo vigente hoy, el del mes, las
     * cisternas del mes (por plan; las que llegaron, por su llegada), lo recibido y el número que sigue.
     */
    public static function datos(?string $mes = null): array
    {
        $mes = self::mesValido($mes);
        $hoy = now()->startOfMonth()->startOfDay();
        $cupos = CupoCombustible::orderByDesc('MES')->get();
        $delMes = self::delMes($mes)->get();

        $patios = [];
        foreach (CupoCombustible::PATIOS as $clave => $nombre) {
            $suyas = $delMes->where('PATIO', $clave)->values();
            $recibidas = $suyas->filter->llego();
            $patios[$clave] = [
                'nombre'    => $nombre,
                'cupo'      => self::cupoDe($cupos, $clave, $hoy),
                'cupo_mes'  => self::cupoDe($cupos, $clave, $mes),
                'filas'     => $suyas,
                'recibido'  => round((float) $recibidas->sum('LITROS'), 2),
                'recibidas' => $recibidas->count(),
                'siguiente' => (int) CisternaCupo::where('PATIO', $clave)->max('NUMERO') + 1,
            ];
        }

        return ['mes' => $mes, 'meses' => self::meses(), 'patios' => $patios];
    }

    /** Las cisternas de un mes: las que llegaron por su llegada; las que no, por su plan. En orden de plan. */
    private static function delMes(Carbon $mes)
    {
        $rango = [$mes->toDateString(), $mes->copy()->endOfMonth()->toDateString()];

        return CisternaCupo::where(fn ($q) => $q->whereBetween('FECHA', $rango)
                ->orWhere(fn ($q) => $q->whereNull('FECHA')->whereBetween('FECHA_PLAN', $rango)))
            ->orderByRaw('COALESCE(FECHA_PLAN, FECHA)')->orderBy('NUMERO')->orderBy('ID_CISTERNA');
    }

    /** El cupo que regía ese mes; antes del primero que se cargó, ese primero (el cupo es fijo). */
    private static function cupoDe(Collection $cupos, string $patio, Carbon $mes): ?float
    {
        $suyos = $cupos->where('PATIO', $patio);
        $c = optional($suyos->first(fn ($c) => $c->MES->lte($mes)) ?? $suyos->last())->LITROS;

        return $c !== null ? (float) $c : null;
    }

    /** Los meses que se pueden elegir: del más nuevo (los de solicitudes por venir) al más viejo. */
    public static function meses(): array
    {
        $meses = [];
        for ($m = now()->startOfMonth()->addMonths(self::ADELANTE); $m->gte(self::desde()); $m->subMonth()) {
            $meses[$m->format('Y-m')] = self::nombreMes($m);
        }

        return $meses;
    }

    private static function mesValido(?string $mes): Carbon
    {
        if ($mes && preg_match('/^\d{4}-\d{2}$/', $mes)) {
            $m = Carbon::createFromFormat('!Y-m', $mes);
            if ($m->gte(self::desde()) && $m->lte(now()->startOfMonth()->addMonths(self::ADELANTE))) {
                return $m;
            }
        }

        return now()->startOfMonth()->startOfDay();
    }

    /**
     * GET consumibles/cupos/excel?patio=TIGRE — todas las cisternas del patio, mes a mes del más reciente
     * al más viejo: solicitud, plan, llegada, litros, lo recibido en el mes y lo que quedaba del cupo
     * (solo cuentan las que llegaron); al cierre de cada mes, su total.
     */
    public function excel(Request $request)
    {
        $patio = $request->validate(['patio' => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))]])['patio'];
        $nombrePatio = CupoCombustible::PATIOS[$patio];
        $cupos = CupoCombustible::orderByDesc('MES')->get();
        $todas = CisternaCupo::where('PATIO', $patio)
            ->where(fn ($q) => $q->where('FECHA', '>=', self::desde()->toDateString())
                ->orWhere(fn ($q) => $q->whereNull('FECHA')->where('FECHA_PLAN', '>=', self::desde()->toDateString())))
            ->orderByRaw('COALESCE(FECHA_PLAN, FECHA)')->orderBy('NUMERO')->get()
            ->groupBy(fn ($k) => $k->dia()->format('Y-m'))->sortKeysDesc();

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $libro->getProperties()->setCreator('Sistema VIDALSA')->setTitle('Cisternas y cupo de gasoil')
            ->setCompany('Constructora Vidalsa 27, C.A.');
        $libro->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Cisternas');
        foreach (['A' => 12, 'B' => 16, 'C' => 16, 'D' => 14, 'E' => 16, 'F' => 18, 'G' => 18, 'H' => 18] as $col => $ancho) {
            $hoja->getColumnDimension($col)->setWidth($ancho);
        }
        $this->encabezadoCorporativo($hoja, "CISTERNAS Y CUPO DE GASOIL\n" . mb_strtoupper($nombrePatio), 'F', 'G', 'H');
        $this->cabeceraTablaCorporativa($hoja, ['SOLICITUD', 'PLAN DE DESPACHO', 'LLEGADA', 'ESTADO', 'LITROS (L)',
            'CUPO DEL MES (L)', 'RECIBIDO EN EL MES (L)', 'QUEDAN DEL CUPO (L)']);

        $xlFecha = fn ($f) => $f ? \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($f->copy()->startOfDay()) : null;
        $f = 6;
        $totales = [];
        foreach ($todas as $ym => $suyas) {
            $mes = Carbon::createFromFormat('!Y-m', $ym);
            $cupo = self::cupoDe($cupos, $patio, $mes);
            $recibido = 0.0;
            foreach ($suyas as $k) {
                $llego = $k->llego();
                if ($llego) {
                    $recibido = round($recibido + (float) $k->LITROS, 2);
                }
                $hoja->fromArray([$k->NUMERO, $xlFecha($k->FECHA_PLAN), $xlFecha($k->FECHA), $llego ? 'Recibida' : 'Programada',
                    $llego ? (float) $k->LITROS : null, $cupo, $llego ? $recibido : null,
                    $llego && $cupo !== null ? round($cupo - $recibido, 2) : null], null, 'A' . $f, true);
                $f++;
            }
            $n = $suyas->filter->llego()->count();
            $hoja->fromArray(['TOTAL ' . mb_strtoupper(self::nombreMes($mes)) . ' (' . ($n === 1 ? '1 recibida' : $n . ' recibidas') . ')',
                null, null, null, $recibido, $cupo, $recibido, $cupo !== null ? round($cupo - $recibido, 2) : null], null, 'A' . $f, true);
            $hoja->mergeCells("A{$f}:D{$f}");
            $totales[] = $f;
            $f++;
        }
        if ($f === 6) {
            $hoja->setCellValue('A6', 'Todavía no hay cisternas de ' . $nombrePatio . '.');
            $hoja->mergeCells('A6:H6');
            $f = 7;
        }
        $ultima = $f - 1;

        $this->rellenarFilas($hoja, $totales, 'H', 'FFE2E8F0');   // antes de alinear (copia el estilo entero)
        foreach ($totales as $t) {
            $hoja->getStyle("A{$t}:H{$t}")->getFont()->setBold(true);
        }
        $hoja->getStyle("A6:H{$ultima}")->applyFromArray(self::bordeFinoCorporativo());
        $hoja->getStyle("A6:H{$ultima}")->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $hoja->getStyle("A6:D{$ultima}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle("B6:C{$ultima}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $hoja->getStyle("E6:H{$ultima}")->getNumberFormat()->setFormatCode('#,##0.##');
        // Lo pasado del cupo, en rojo (como en el modal).
        $rojo = new \PhpOffice\PhpSpreadsheet\Style\Conditional();
        $rojo->setConditionType(\PhpOffice\PhpSpreadsheet\Style\Conditional::CONDITION_CELLIS)
            ->setOperatorType(\PhpOffice\PhpSpreadsheet\Style\Conditional::OPERATOR_LESSTHAN)->addCondition('0');
        $rojo->getStyle()->getFont()->getColor()->setARGB('FFDC2626');
        $hoja->getStyle("H6:H{$ultima}")->setConditionalStyles([$rojo]);
        $hoja->freezePane('A6');

        $nombre = 'Cisternas_Cupo_Gasoil_' . str_replace(' ', '_', $nombrePatio) . '_' . date('Y-m-d') . '.xlsx';
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
     * POST consumibles/cupos/cisternas — las filas nuevas y cambiadas de la tabla de un patio: patio y
     * cisternas[] con id (si ya existe), numero, plan (fecha del plan de despacho), llegada (fecha real),
     * litros y foto. Una fila necesita el plan o la llegada; llegada y litros van juntos (la llegada no
     * puede ser futura; el plan sí). Todas o ninguna.
     */
    public function guardarCisternas(Request $request)
    {
        $filas = $request->input('cisternas');
        if (is_array($filas)) {
            $request->merge(['cisternas' => array_map(
                fn ($f) => is_array($f) ? ['litros' => self::litros($f['litros'] ?? null)] + $f : $f,
                $filas
            )]);
        }
        $desde = self::desde()->toDateString();
        $tope = now()->startOfMonth()->addMonths(self::ADELANTE)->endOfMonth()->toDateString();
        $v = $request->validate([
            'patio'               => ['required', Rule::in(array_keys(CupoCombustible::PATIOS))],
            'cisternas'           => ['required', 'array', 'min:1', 'max:60'],
            'cisternas.*.id'      => ['nullable', 'integer'],
            'cisternas.*.numero'  => ['nullable', 'integer', 'min:1', 'max:999999', 'distinct'],
            // El «desde» de los meses que se ven se revisa abajo, solo en filas nuevas o fechas cambiadas.
            'cisternas.*.plan'    => ['nullable', 'required_without:cisternas.*.llegada', 'date_format:Y-m-d', 'before_or_equal:' . $tope],
            'cisternas.*.llegada' => ['nullable', 'required_with:cisternas.*.litros', 'date_format:Y-m-d', 'before_or_equal:today'],
            'cisternas.*.litros'  => ['nullable', 'required_with:cisternas.*.llegada', 'numeric', 'gt:0', 'max:9999999999'],
            'cisternas.*.foto'    => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:12288'],
        ], [
            'cisternas.*.plan.required_without'    => 'Ponga la fecha del plan de despacho (o la de llegada).',
            'cisternas.*.llegada.required_with'    => 'Ponga la fecha en que llegó.',
            'cisternas.*.litros.required_with'     => 'Ponga los litros que llegaron.',
            'cisternas.*.llegada.before_or_equal'  => 'La llegada no puede ser una fecha futura.',
            'cisternas.*.numero.distinct'          => 'Ese número de solicitud está repetido en la tabla.',
        ], ['cisternas.*.plan' => 'plan', 'cisternas.*.llegada' => 'llegada', 'cisternas.*.litros' => 'litros',
            'cisternas.*.foto' => 'foto', 'cisternas.*.numero' => 'solicitud']);

        // validate() arma las filas campo por campo (primero las que tienen plan): en el orden de la tabla.
        ksort($v['cisternas']);
        $patio = $v['patio'];
        $ids = collect($v['cisternas'])->pluck('id')->filter()->map(fn ($i) => (int) $i);
        $existentes = CisternaCupo::where('PATIO', $patio)->whereIn('ID_CISTERNA', $ids)->get()->keyBy('ID_CISTERNA');
        $errores = [];
        foreach ($v['cisternas'] as $i => $f) {
            $k = !empty($f['id']) ? $existentes->get((int) $f['id']) : null;
            if (!empty($f['id']) && !$k) {
                $errores["cisternas.$i.plan"] = 'Esa cisterna ya no existe; actualice la tabla.';
                continue;
            }
            // Una fecha nueva o cambiada va dentro de los meses que se ven (si no, quedaría guardada sin poder
            // verse ni quitarse). La de una cisterna ya guardada, sin tocar, se deja como está.
            foreach (['plan' => 'FECHA_PLAN', 'llegada' => 'FECHA'] as $c => $col) {
                $nueva = $f[$c] ?? null;
                $antes = $k && $k->$col ? Carbon::parse($k->$col)->toDateString() : null;
                if ($nueva && $nueva < $desde && $nueva !== $antes) {
                    $errores["cisternas.$i.$c"] = 'La fecha no puede ser anterior al ' . Carbon::parse($desde)->format('d/m/Y') . '.';
                }
            }
            // Una solicitud guardada no se queda sin número.
            if ($k && empty($f['numero']) && $k->NUMERO) {
                $errores["cisternas.$i.numero"] = 'Ponga el número de la solicitud.';
            }
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        // Las fotos se suben ANTES de la transacción (Drive no va dentro: una caída de red tumbaría el guardado).
        // Si algo falla después, las que ya subieron van a la papelera de Drive (no quedan sueltas).
        $fotos = [];
        $viejas = [];
        try {
            foreach ($v['cisternas'] as $i => $f) {
                if (!empty($f['foto'])) {
                    $fotos[$i] = $this->subirFoto($f['foto'], $patio);
                }
            }
            $this->guardarFilas($v['cisternas'], $patio, $fotos, $existentes, $ids, $viejas);
        } catch (\Throwable $e) {
            foreach ($fotos as $foto) {
                self::fotoAPapelera($foto);
            }
            throw $e;
        }
        foreach ($viejas as $foto) {
            self::fotoAPapelera($foto);
        }

        // Los meses donde quedaron (la pantalla avisa si alguna no es del mes que se está viendo).
        $meses = collect($v['cisternas'])->map(fn ($f) => substr($f['llegada'] ?? $f['plan'], 0, 7))->unique()
            ->mapWithKeys(fn ($m) => [$m => self::nombreMes(Carbon::createFromFormat('!Y-m', $m))]);

        return response()->json(['ok' => true, 'guardadas' => count($v['cisternas']), 'meses' => $meses]);
    }

    /** Las filas de guardarCisternas, todas o ninguna. Las fotos que se reemplazan quedan en $viejas. */
    private function guardarFilas(array $filas, string $patio, array $fotos, $existentes, $ids, array &$viejas): void
    {
        DB::transaction(function () use ($filas, $patio, $fotos, $existentes, $ids, &$viejas) {
            // Bloquea las cisternas del patio: dos guardados a la vez no sacan ni repiten el mismo número.
            $siguiente = max((int) CisternaCupo::where('PATIO', $patio)->lockForUpdate()->max('NUMERO'),
                (int) collect($filas)->max('numero')) + 1;
            // El número no se repite en el patio (salvo en la misma cisterna o en otra de esta tabla).
            $errores = [];
            foreach ($filas as $i => $f) {
                if (!empty($f['numero']) && CisternaCupo::where('PATIO', $patio)->where('NUMERO', $f['numero'])
                        ->whereNotIn('ID_CISTERNA', $ids)->exists()) {
                    $errores["cisternas.$i.numero"] = 'La solicitud ' . $f['numero'] . ' ya existe en ' . CupoCombustible::PATIOS[$patio] . '.';
                }
            }
            if ($errores) {
                throw ValidationException::withMessages($errores);
            }
            foreach ($filas as $i => $f) {
                if (empty($f['id']) && empty($f['numero'])) {
                    $f['numero'] = $siguiente++;
                }
                $datos = [
                    // Sin plan (se cargó ya llegada): el plan es la llegada (así sale en el cronograma).
                    'NUMERO' => $f['numero'] ?? null, 'FECHA_PLAN' => ($f['plan'] ?? null) ?: $f['llegada'],
                    'FECHA' => $f['llegada'] ?? null, 'LITROS' => $f['litros'] ?? null,
                ];
                if (isset($fotos[$i])) {
                    $datos['FOTO'] = $fotos[$i];
                }
                $k = !empty($f['id']) ? $existentes[(int) $f['id']] : null;
                if ($k) {
                    if (isset($fotos[$i]) && $k->FOTO) {
                        $viejas[] = $k->FOTO;
                    }
                    $k->update($datos);
                } else {
                    CisternaCupo::create($datos + ['PATIO' => $patio, 'ID_USUARIO' => auth()->id()]);
                }
            }
        });
    }

    /** POST consumibles/cupos/cisternas/{id}/foto — pone o cambia la foto de una cisterna ya guardada. */
    public function fotoCisterna(Request $request, int $id)
    {
        $cisterna = CisternaCupo::findOrFail($id);
        $request->validate(['foto' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:12288']], [
            'foto.required' => 'Elija una foto.',
            'foto.image'    => 'El archivo no es una imagen.',
            'foto.mimes'    => 'Se aceptan JPG, PNG o WebP.',
            'foto.max'      => 'La foto pesa más de 12 MB.',
        ]);

        $anterior = $cisterna->FOTO;
        $cisterna->update(['FOTO' => $this->subirFoto($request->file('foto'), $cisterna->PATIO)]);
        self::fotoAPapelera($anterior);

        return response()->json(['ok' => true, 'foto' => $cisterna->FOTO]);
    }

    /** DELETE consumibles/cupos/cisternas/{id} */
    public function borrarCisterna(int $id)
    {
        $cisterna = CisternaCupo::findOrFail($id);
        $cisterna->delete();
        self::fotoAPapelera($cisterna->FOTO);

        return response()->json(['ok' => true]);
    }

    /**
     * Lo que el PowerPoint de Gráficos necesita de las cisternas en un mes:
     * - cupos: por patio, cupo del mes, lo consumido (lo recibido) y lo que queda por consumir;
     * - patios: por patio, las cisternas que llegaron en el mes (con su foto) y su total; el histórico
     *   de los 8 meses que terminan en el mes (litros y cuántas); y el cronograma: las solicitudes del
     *   mes siguiente (si no hay, las del mismo mes), con el día del plan y si ya llegaron.
     * Sirve para cualquier cantidad de cisternas: la presentación reparte las fotos en las láminas que haga falta.
     */
    public static function presentacion(\Carbon\CarbonInterface $mes): array
    {
        $mes = Carbon::instance($mes)->startOfMonth()->startOfDay();
        $cupos = CupoCombustible::orderByDesc('MES')->get();
        $rango = fn (Carbon $m) => [$m->toDateString(), $m->copy()->endOfMonth()->toDateString()];
        // Las que llegaron, de los 8 meses del histórico (una sola consulta).
        $inicio = $mes->copy()->subMonths(7);
        $llegadas = CisternaCupo::whereNotNull('LITROS')->whereBetween('FECHA', [$inicio->toDateString(), $rango($mes)[1]])
            ->orderBy('FECHA')->orderBy('NUMERO')->get();
        $mesSig = $mes->copy()->addMonth();
        $planes = CisternaCupo::whereBetween('FECHA_PLAN', [$mes->toDateString(), $rango($mesSig)[1]])
            ->orderBy('FECHA_PLAN')->orderBy('NUMERO')->get();
        $mayus = fn (Carbon $m, string $f) => mb_strtoupper($m->locale('es')->translatedFormat($f));

        $resumen = [];
        $patios = [];
        foreach (CupoCombustible::PATIOS as $clave => $nombre) {
            $suyas = $llegadas->where('PATIO', $clave);
            $delMes = $suyas->filter(fn ($k) => $k->FECHA->format('Y-m') === $mes->format('Y-m'))->values();
            $cupo = self::cupoDe($cupos, $clave, $mes);
            $consumido = round((float) $delMes->sum('LITROS'), 2);
            $resumen[] = ['clave' => $clave, 'nombre' => $nombre, 'cupo' => $cupo, 'consumido' => $consumido,
                'por_consumir' => $cupo !== null ? round($cupo - $consumido, 2) : null];

            $historico = [];
            for ($m = $inicio->copy(); $m->lte($mes); $m->addMonth()) {
                $deEse = $suyas->filter(fn ($k) => $k->FECHA->format('Y-m') === $m->format('Y-m'));
                $historico[] = ['mes' => $mayus($m, 'F'), 'litros' => round((float) $deEse->sum('LITROS'), 2), 'cisternas' => $deEse->count()];
            }

            $mesCrono = $mesSig;
            $solicitudes = $planes->where('PATIO', $clave)->filter(fn ($k) => $k->FECHA_PLAN->format('Y-m') === $mesSig->format('Y-m'));
            if ($solicitudes->isEmpty()) {
                $mesCrono = $mes;
                $solicitudes = $planes->where('PATIO', $clave)->filter(fn ($k) => $k->FECHA_PLAN->format('Y-m') === $mes->format('Y-m'));
            }

            $patios[] = [
                'clave' => $clave,
                'nombre' => $nombre,
                'recibidas' => $delMes->map(fn ($k) => [
                    'numero' => $k->NUMERO, 'fecha' => $k->FECHA->format('d/m/Y'), 'litros' => (float) $k->LITROS, 'foto' => $k->FOTO,
                ])->values(),
                'total_recibido' => $consumido,
                'historico' => $historico,
                'cronograma' => [
                    'mes' => $mayus($mesCrono, 'F'),
                    'anio' => (int) $mesCrono->year,
                    'dias' => $mesCrono->daysInMonth,
                    'primer_dia' => $mesCrono->dayOfWeekIso,   // 1 = lunes … 7 = domingo
                    'solicitudes' => $solicitudes->map(fn ($k) => [
                        'numero' => $k->NUMERO, 'dia' => $k->FECHA_PLAN->day, 'llego' => $k->llego(),
                    ])->values(),
                ],
            ];
        }

        return ['mes' => $mayus($mes, 'F Y'), 'cupos' => $resumen, 'patios' => $patios];
    }

    /**
     * Sube la foto a Drive en WebP (carpeta privada de fotos; el navegador la ve por /storage/google
     * con sesión) y devuelve su enlace. Si Drive no la acepta, corta con un 503 que dice qué pasó.
     */
    private function subirFoto($archivo, string $patio): string
    {
        @set_time_limit(120);
        $convertida = $this->convertToWebp($archivo, self::FOTO_ANCHO);
        try {
            $file = $convertida['file'];
            $ext = $file->getMimeType() === 'image/webp' ? 'webp' : ($file->guessExtension() ?: 'jpg');
            $nombre = 'cisterna_' . $patio . '_' . (int) (microtime(true) * 1000) . '.' . $ext;
            try {
                $drive = \App\Services\GoogleDriveService::getInstance();
                $subido = $drive->uploadFile(config('filesystems.disks.google.product_folder'), $file, $nombre, $file->getMimeType());
            } catch (\Throwable $e) {
                Log::error('Cupos: Drive rechazó la foto de la cisterna', ['error' => $e->getMessage()]);
                abort(503, 'No se pudo guardar la foto en Drive. Revise la conexión e intente de nuevo.');
            }
            if (!$subido || !isset($subido->id)) {
                abort(503, 'Drive no aceptó la foto. Intente de nuevo.');
            }
            $bytes = @file_get_contents($file->getRealPath());
            if ($bytes) {
                \App\Services\GoogleDriveService::prepararCopiaLocal($subido->id, $bytes);
            }

            return '/storage/google/' . $subido->id;
        } finally {
            if (!empty($convertida['tempPath'])) {
                @unlink($convertida['tempPath']);
            }
        }
    }

    /** La foto que dejó de usarse va a la papelera de Drive (en local no se toca Drive). */
    private static function fotoAPapelera(?string $foto): void
    {
        $id = \App\Models\DocumentoAnexo::driveIdDeLink($foto);
        if ($id && !app()->environment('local')) {
            \App\Services\GoogleDriveService::borrarTrasResponder($id);
        }
    }

    /** El primer día que se puede ver y cargar: el día 1 de hace MESES − 1 meses. */
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
        if ($t === '') {
            return null;
        }
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
