<?php

namespace App\Traits;

use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

trait ExcelLogoCorporativo
{
    /**
     * Inserta el logo corporativo centrado horizontal y verticalmente dentro del
     * rango de celdas mergeado (por defecto A1:B3). Calcula el offset dinámicamente
     * según el ancho real de las columnas del merge y la altura de las filas, en
     * vez de usar un pixel fijo que se descentra al cambiar el ancho de las columnas.
     *
     * $sheet       — hoja activa (las filas ya deben tener su altura seteada ANTES
     *                de llamar a este método para que el centrado vertical funcione).
     * $mergeCols   — array de letras de columna del merge, ej. ['A','B'].
     * $mergeRows   — array de filas del merge, ej. [1,2,3].
     * $logoHeight  — altura deseada del logo en px (afecta el ancho proporcional).
     */
    private function insertarLogoCorporativo(
        Worksheet $sheet,
        array $mergeCols = ['A','B'],
        array $mergeRows = [1,2,3],
        int $logoHeight = 120
    ): void {
        $logoPath = public_path('img/imagen_uno.jpg');
        if (!file_exists($logoPath)) return;

        $imgSize = @getimagesize($logoPath);
        if (!$imgSize) return;

        $ratio     = $imgSize[0] / $imgSize[1]; // 248/194 ≈ 1.278
        $logoWidth = (int) round($logoHeight * $ratio);

        // Ancho del merge en px: Excel usa ~7 px por unidad de ancho de columna (Arial 10).
        $pxPerChar = 7.0;
        $mergeWidthPx = 0;
        foreach ($mergeCols as $col) {
            $w = $sheet->getColumnDimension($col)->getWidth();
            if ($w <= 0) $w = 8.43; // default de Excel
            $mergeWidthPx += $w * $pxPerChar;
        }

        // Altura del merge en px: la altura de fila en PhpSpreadsheet ya es en puntos
        // (1 pt = 1.333 px a 96 DPI).
        $ptToPx = 96.0 / 72.0;
        $mergeHeightPx = 0;
        foreach ($mergeRows as $row) {
            $h = $sheet->getRowDimension($row)->getRowHeight();
            if ($h <= 0) $h = 15; // default
            $mergeHeightPx += $h * $ptToPx;
        }

        $offsetX = max(0, (int) round(($mergeWidthPx - $logoWidth) / 2));
        $offsetY = max(0, (int) round(($mergeHeightPx - $logoHeight) / 2));

        try {
            $drawing = new Drawing();
            $drawing->setName('Logo CVIDALSA');
            $drawing->setDescription('Logo');
            $drawing->setPath($logoPath);
            $drawing->setCoordinates($mergeCols[0] . $mergeRows[0]);
            $drawing->setOffsetX($offsetX);
            $drawing->setOffsetY($offsetY);
            $drawing->setHeight($logoHeight);
            $drawing->setWorksheet($sheet);
        } catch (\Throwable $e) {
            // si la imagen falla, el export continúa sin logo
        }
    }

    /**
     * Encabezado corporativo de las hojas de listado: el de "Exportación de Data" de Equipos, que
     * es el que usan todos los listados (Equipos, Auxiliares, Anclajes de auxiliares,
     * Movilizaciones, Alertas de documentos, Equipos con GPS del mapa). ÚNICO sitio que lo pinta.
     *   · filas 1-3 de $altoFila pt con el logo ($altoLogo px) centrado en A1:B3;
     *   · el título en negrita 14 de C1 a $finTitulo (un salto de línea lo parte en título / subtítulo);
     *   · EDICION / REVISION / FECHA a la derecha, de $inicioEdicion a $ultimaCol;
     *   · la fila 4 ($fila4, por defecto "Exportado por: Sistema…") en cursiva 9, a la derecha;
     *   · la cuadrícula A1:$ultimaCol4 con borde fino negro.
     * El logo se centra con el ancho que tengan A y B AL LLAMAR: si la hoja fija sus anchos,
     * conviene hacerlo antes. La tabla empieza en la fila 5 (ver cabeceraTablaCorporativa).
     */
    private function encabezadoCorporativo(
        Worksheet $sheet, string $titulo, string $finTitulo, string $inicioEdicion, string $ultimaCol,
        ?string $fila4 = null, float $altoFila = 40, int $altoLogo = 120, ?string $fecha = null
    ): void {
        $blanco = fn (string $rango) => $sheet->getStyle($rango)->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFFFF');
        $centro = fn (string $rango) => $sheet->getStyle($rango)->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        foreach ([1, 2, 3] as $fila) {
            $sheet->getRowDimension($fila)->setRowHeight($altoFila);
        }
        // El logo va DESPUÉS de fijar la altura de las filas: se centra con ellas.
        $this->insertarLogoCorporativo($sheet, ['A', 'B'], [1, 2, 3], $altoLogo);
        $sheet->mergeCells('A1:B3');
        $blanco('A1:B3');

        $sheet->mergeCells('C1:' . $finTitulo . '3');
        $sheet->setCellValue('C1', $titulo);
        $sheet->getStyle('C1')->getAlignment()->setWrapText(true);
        $centro('C1');
        $sheet->getStyle('C1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
        $blanco('C1:' . $finTitulo . '3');

        foreach ([1 => 'EDICION: 1', 2 => 'REVISION: 0', 3 => 'FECHA: ' . ($fecha ?? date('d/m/Y'))] as $fila => $texto) {
            $rango = $inicioEdicion . $fila . ':' . $ultimaCol . $fila;
            $sheet->mergeCells($rango);
            $sheet->setCellValue($inicioEdicion . $fila, $texto);
            $centro($inicioEdicion . $fila);
            $sheet->getStyle($inicioEdicion . $fila)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB(\PhpOffice\PhpSpreadsheet\Style\Color::COLOR_BLACK);
            $blanco($rango);
        }

        $sheet->mergeCells('A4:' . $ultimaCol . '4');
        $sheet->setCellValue('A4', $fila4 ?? 'Exportado por: Sistema de Gestión de Equipos Operacionales');
        $blanco('A4:' . $ultimaCol . '4');
        $sheet->getStyle('A4:' . $ultimaCol . '4')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A4:' . $ultimaCol . '4')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF333333');
        $sheet->getRowDimension(4)->setRowHeight(20);

        $sheet->getStyle('A1:' . $ultimaCol . '4')->applyFromArray(self::bordeFinoCorporativo());
    }

    /** Fila 5: cabeceras en blanco sobre azul 1B365D, negrita 10, centradas y en dos líneas si hace
        falta. La usan Equipos, Movilizaciones, Alertas de documentos y Equipos con GPS (los
        auxiliares llevan otro azul, 1E293B, y la suya propia). Devuelve la última columna. */
    private function cabeceraTablaCorporativa(Worksheet $sheet, array $cabeceras): string
    {
        $col = 'A';
        foreach ($cabeceras as $texto) {
            $sheet->setCellValue($col . '5', $texto);
            $ultima = $col;
            $col++;
        }
        $rango = 'A5:' . $ultima . '5';
        $sheet->getStyle($rango)->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER)
            ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle($rango)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($rango)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF1B365D');
        $sheet->getRowDimension(5)->setRowHeight(40);
        return $ultima;
    }

    /** Borde fino negro en toda la cuadrícula (encabezado y tabla), como en el export de Equipos. */
    private static function bordeFinoCorporativo(): array
    {
        return ['borders' => ['allBorders' => [
            'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
            'color'       => ['argb' => 'FF000000'],
        ]]];
    }
}
