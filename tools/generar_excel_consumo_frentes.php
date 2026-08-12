<?php
/**
 * Genera el Excel de consumo de gasoil por frente: una hoja por proyecto + un resumen.
 *
 * Cada hoja agrupa por TIPO **y por CONSUMO**: si dentro de un mismo tipo hay unidades
 * con litros distintos (p.ej. un chuto a 150 y otro a 50), salen en filas separadas —
 * juntarlas escondería justo la diferencia que importa.
 *
 * Suma las DOS tablas: `equipos` y `equipos_auxiliares`.
 */
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ProyeccionCombustible;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Frentes del reporte, en el orden en que van las hojas. Es el juego por defecto. */
const FRENTES_POR_DEFECTO = [43, 72, 47, 3, 74, 10, 46, 11, 4, 57, 20, 22, 24, 73, 8];

// Se puede pedir otro juego de frentes sin tocar el script:
//   php generar_excel_consumo_frentes.php 22,24,20 Consumo_Morichal   -> solo esos
//   php generar_excel_consumo_frentes.php todos    Consumo_Total      -> todos los que
//                                                                        tengan equipos
// Sin argumentos saca los frentes de obra de siempre.
$arg = isset($argv[1]) ? trim($argv[1]) : '';

if ($arg === 'todos') {
    // Se consultan en vivo —y no con una lista fija— para que un frente nuevo entre solo
    // en el reporte. Se ordenan por cantidad de equipos: los grandes primero.
    $frentes = DB::table('frentes_trabajo as f')
        ->selectRaw('f.ID_FRENTE, ('
            . '(SELECT COUNT(*) FROM equipos e WHERE e.ID_FRENTE_ACTUAL = f.ID_FRENTE AND e.deleted_at IS NULL)'
            . ' + (SELECT COUNT(*) FROM equipos_auxiliares a WHERE a.ID_FRENTE_ACTUAL = f.ID_FRENTE AND a.deleted_at IS NULL)'
            . ') as und')
        ->havingRaw('und > 0')->orderByDesc('und')->pluck('ID_FRENTE')->all();
} elseif ($arg !== '') {
    $frentes = array_values(array_filter(array_map('intval', explode(',', $arg))));
} else {
    $frentes = FRENTES_POR_DEFECTO;
}
$nombreArchivo = $argv[2] ?? 'Consumo_Gasoil_Por_Frente';

$AZUL = '00004D'; $GRIS = 'F1F5F9'; $VERDE = 'DCFCE7'; $AMBAR = 'FEF3C7'; $ROJO = 'FEE2E2';

/** Filas de un frente, agrupadas por (origen, tipo, combustible, consumo). */
function filasDe(int $idFrente): array
{
    $eq = DB::table('equipos as e')
        ->join('tipo_equipos as t', 't.id', '=', 'e.id_tipo_equipo')
        ->whereNull('e.deleted_at')->where('e.ID_FRENTE_ACTUAL', $idFrente)
        ->groupBy('t.nombre', 'e.COMBUSTIBLE', 'e.CONSUMO_PROMEDIO')
        ->select('t.nombre as tipo', 'e.COMBUSTIBLE as comb', 'e.CONSUMO_PROMEDIO as consumo',
                 DB::raw('COUNT(*) as und'), DB::raw("'EQUIPO' as origen"))->get();

    $aux = DB::table('equipos_auxiliares')
        ->whereNull('deleted_at')->where('ID_FRENTE_ACTUAL', $idFrente)
        ->groupBy('TIPO', 'COMBUSTIBLE', 'CONSUMO_PROMEDIO')
        ->select('TIPO as tipo', 'COMBUSTIBLE as comb', 'CONSUMO_PROMEDIO as consumo',
                 DB::raw('COUNT(*) as und'), DB::raw("'AUXILIAR' as origen"))->get();

    return $eq->concat($aux)
        ->sortBy([fn ($a, $b) => strcmp($a->origen, $b->origen), fn ($a, $b) => strcmp($a->tipo, $b->tipo)])
        ->values()->all();
}

$libro = new Spreadsheet();
$libro->removeSheetByIndex(0);
$resumen = [];

foreach ($frentes as $idFrente) {
    $frente = DB::table('frentes_trabajo')->where('ID_FRENTE', $idFrente)->first();
    if (!$frente) continue;
    $filas = filasDe($idFrente);

    // El nombre de hoja de Excel no admite : \ / ? * [ ] ni mas de 31 caracteres.
    $titulo = mb_substr(preg_replace('/[:\\\\\/\?\*\[\]]/', ' ', $frente->NOMBRE_FRENTE), 0, 31);
    $h = $libro->createSheet(); $h->setTitle($titulo);

    $h->setCellValue('A1', $frente->NOMBRE_FRENTE);
    $h->mergeCells('A1:F1');
    $h->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
    $h->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($AZUL);
    $h->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $h->getRowDimension(1)->setRowHeight(24);

    $cab = ['ORIGEN', 'TIPO DE EQUIPO', 'COMBUSTIBLE', 'CANT. (UND)', 'CONSUMO L/DÍA C/U', 'TOTAL L/DÍA'];
    foreach ($cab as $i => $txt) $h->setCellValue(chr(65 + $i) . '3', $txt);
    $h->getStyle('A3:F3')->getFont()->setBold(true);
    $h->getStyle('A3:F3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GRIS);

    $fila = 4; $totUnd = 0; $totGasoil = 0.0; $totGasolina = 0.0;
    $undGasoil = 0; $undGasolina = 0; $undPend = 0; $undNoAplica = 0;

    // La fila CHUTO se abre en varias segun lo que arrastre cada uno en este frente.
    $expandidas = [];
    foreach ($filas as $r) {
        if ($r->tipo === 'CHUTO' && $r->consumo !== null) {
            foreach (ProyeccionCombustible::filasChuto($idFrente, (int) $r->und, (float) $r->consumo) as [$etiqueta, $und, $litros]) {
                $expandidas[] = (object) ['tipo' => $etiqueta, 'comb' => $r->comb,
                                          'consumo' => $litros, 'und' => $und, 'origen' => $r->origen];
            }
            continue;
        }
        $expandidas[] = $r;
    }
    $filas = $expandidas;

    foreach ($filas as $r) {
        $comb    = $r->comb ?: 'POR VERIFICAR';
        $consumo = $r->consumo !== null ? (float) $r->consumo : null;
        $total   = $consumo !== null ? $consumo * $r->und : null;

        $h->setCellValue("A{$fila}", $r->origen);
        $h->setCellValue("B{$fila}", $r->tipo);
        $h->setCellValue("C{$fila}", $comb);
        $h->setCellValue("D{$fila}", (int) $r->und);
        $h->setCellValue("E{$fila}", $consumo ?? 'SIN CARGAR');
        $h->setCellValue("F{$fila}", $total ?? 'SIN CARGAR');

        // Color por combustible: se lee de un vistazo que suma gasoil y que no.
        $color = match ($comb) {
            'GASOIL'         => $VERDE,
            'GASOLINA'       => $AMBAR,
            'POR VERIFICAR'  => $ROJO,
            default          => null,
        };
        if ($color) $h->getStyle("C{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
        if ($consumo === null && $comb === 'GASOIL') {
            $h->getStyle("E{$fila}:F{$fila}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($ROJO);
        }

        $totUnd += $r->und;
        if ($comb === 'GASOIL')        { $undGasoil   += $r->und; $totGasoil += (float) ($total ?? 0); }
        elseif ($comb === 'GASOLINA')  { $undGasolina += $r->und; $totGasolina += (float) ($total ?? 0); }
        elseif ($comb === 'NO APLICA') { $undNoAplica += $r->und; }
        else                           { $undPend     += $r->und; }
        $fila++;
    }

    $h->getStyle("A3:F" . ($fila - 1))->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $fila++;
    $resumenFrente = [
        ['TOTAL DE EQUIPOS ASIGNADOS',        $totUnd],
        ['— Que consumen GASOIL',             $undGasoil],
        ['— Que consumen GASOLINA',           $undGasolina],
        ['— Sin motor (NO APLICA)',           $undNoAplica],
        ['— Combustible por verificar',       $undPend],
        ['CONSUMO TOTAL DE GASOIL (L/DÍA)',   $totGasoil],
        ['CONSUMO TOTAL DE GASOLINA (L/DÍA)',  $totGasolina],
    ];
    foreach ($resumenFrente as $r) {
        $h->setCellValue("B{$fila}", $r[0]);
        $h->setCellValue("D{$fila}", $r[1]);
        $h->getStyle("B{$fila}:D{$fila}")->getFont()->setBold(str_starts_with($r[0], 'TOTAL') || str_starts_with($r[0], 'CONSUMO'));
        $fila++;
    }
    $h->setCellValue("B{$fila}", 'Gasoil y gasolina van por separado: son dos combustibles distintos y no se suman entre si.');
    $h->getStyle("B{$fila}")->getFont()->setItalic(true)->setSize(9);

    foreach (range('A', 'F') as $c) $h->getColumnDimension($c)->setAutoSize(true);

    $resumen[] = [$frente->NOMBRE_FRENTE, $totUnd, $undGasoil, $undGasolina, $undNoAplica, $undPend, $totGasoil, $totGasolina];
}

// ── Hoja RESUMEN, de primera ──
$r = $libro->createSheet(); $r->setTitle('RESUMEN'); $libro->setIndexByName('RESUMEN', 0);
$r->setCellValue('A1', 'CONSUMO DE GASOIL POR FRENTE — RESUMEN');
$r->mergeCells('A1:H1');
$r->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('FFFFFF');
$r->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($AZUL);
$r->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$cab = ['FRENTE', 'TOTAL EQUIPOS', 'A GASOIL', 'A GASOLINA', 'SIN MOTOR', 'POR VERIFICAR', 'GASOIL L/DÍA', 'GASOLINA L/DÍA'];
foreach ($cab as $i => $txt) $r->setCellValue(chr(65 + $i) . '3', $txt);
$r->getStyle('A3:H3')->getFont()->setBold(true);
$r->getStyle('A3:H3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GRIS);

$f = 4; $g = [0, 0, 0, 0, 0, 0.0, 0.0];
foreach ($resumen as $row) {
    foreach ($row as $i => $v) $r->setCellValue(chr(65 + $i) . $f, $v);
    for ($i = 0; $i < 7; $i++) $g[$i] += $row[$i + 1];
    $f++;
}
$r->setCellValue("A{$f}", 'TOTAL GENERAL');
foreach ($g as $i => $v) $r->setCellValue(chr(66 + $i) . $f, $v);
$r->getStyle("A{$f}:H{$f}")->getFont()->setBold(true);
$r->getStyle("A{$f}:H{$f}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($GRIS);
$r->getStyle("A3:H{$f}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
foreach (range('A', 'H') as $c) $r->getColumnDimension($c)->setAutoSize(true);

$libro->setActiveSheetIndex(0);
$destino = 'C:/Users/dell12/Downloads/' . $nombreArchivo . '.xlsx';
(new Xlsx($libro))->save($destino);

echo "Generado: {$destino}\n";
echo "Hojas: " . ($libro->getSheetCount()) . " (RESUMEN + " . count($resumen) . " frentes)\n";
printf("TOTAL: %d equipos | GASOIL %s L/dia | GASOLINA %s L/dia
", $g[0], number_format($g[5], 0), number_format($g[6], 0));
