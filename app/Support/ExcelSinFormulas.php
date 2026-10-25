<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Ningún texto se vuelve fórmula al exportar a Excel.
 *
 * PhpSpreadsheet guarda como FÓRMULA todo texto que empiece con "=": un serial, una placa o un
 * nombre escritos como =HYPERLINK("http://…","ver") o =WEBSERVICE(…) se ejecutarían al abrir
 * el Excel descargado. Las exportaciones solo vuelcan datos (ninguna escribe fórmulas en las
 * celdas: las de las listas desplegables van por setFormula1, que no pasa por aquí), así que
 * esos textos se guardan como texto y en la celda se ve lo que se escribió.
 *
 * "+", "-" y "@" solo son peligrosos en un CSV; en el .xlsx la celda va marcada como texto y
 * Excel no la evalúa. Se instala para toda la app en AppServiceProvider::boot. Los CSV no pasan
 * por aquí: cada valor se escribe con celdaCsv().
 */
class ExcelSinFormulas extends DefaultValueBinder
{
    /** Un CSV no tiene tipos: lo que Excel leería como fórmula va precedido de un apóstrofo. */
    public static function celdaCsv(mixed $valor): mixed
    {
        if (is_string($valor) && $valor !== '' && str_contains("=+-@\t\r", $valor[0])) {
            return "'" . $valor;
        }
        return $valor;
    }

    public static function dataTypeForValue(mixed $value): string
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            return DataType::TYPE_STRING;
        }
        return parent::dataTypeForValue($value);
    }
}
