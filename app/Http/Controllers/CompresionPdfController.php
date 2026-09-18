<?php

namespace App\Http\Controllers;

use App\Models\VerificacionDocumento;
use App\Services\CorrectorFichaDocumento;
use App\Support\PanelDocumentos;
use Illuminate\Http\Request;

/**
 * Lo que se puede HACER desde las pestañas de documentos de Control de Auditoría
 * (/admin/historial-documentos): corregir una ficha con lo que dice su PDF.
 *
 * La pantalla la pinta HistorialDocumentosController con admin/compresion_pdf/panel.blade.php;
 * aqui solo vive la accion; quien escribe en la ficha es CorrectorFichaDocumento.
 * La direccion vieja /admin/compresion-pdf sigue funcionando: lleva a la pestaña (index()).
 */
class CompresionPdfController extends Controller
{
    /** La pantalla se mudo a Control de Auditoría; los enlaces viejos siguen llegando. */
    public function index(Request $request)
    {
        return redirect()->route('historial-documentos.index', [
            'pestana' => PanelDocumentos::esPestana($request->input('pestana'))
                ? $request->input('pestana') : PanelDocumentos::COMPRESION,
        ] + $request->except('pestana'));
    }

    /**
     * Pone en la ficha lo que dice el documento, solo con lo que el verificador marco como
     * distinto: lo que ya esta bien no se toca. Es lo MISMO que hace la revision de cada noche
     * —el trabajo lo hace CorrectorFichaDocumento, asi hay un solo sitio donde estos datos
     * cambian—; aqui queda ademas quien lo pidio, para el historial. Sirve para lo que la
     * noche dejo sin aplicar: lo que alguien habia corregido a mano entretanto.
     */
    public function aplicarDocumento(Request $request, int $id, CorrectorFichaDocumento $corrector)
    {
        $reg = VerificacionDocumento::findOrFail($id);
        $resultado = $corrector->aplicar($reg, $request->user()?->getKey());

        if (isset($resultado['error'])) {
            return back()->with('error', $resultado['error']);
        }
        $aviso = 'Ficha corregida: ' . implode(', ', $resultado['puestos']) . '.';
        if ($resultado['saltados']) {
            $aviso .= ' Se respetó lo que alguien ya había corregido a mano: ' . implode(', ', $resultado['saltados']) . '.';
        }
        return back()->with('success', $aviso);
    }
}
