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
}
