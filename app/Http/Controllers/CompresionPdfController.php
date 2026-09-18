<?php

namespace App\Http\Controllers;

use App\Models\VerificacionDocumento;
use App\Support\PanelDocumentos;
use Illuminate\Http\Request;

/**
 * Lo que se puede HACER desde las pestañas de documentos de Control de Auditoría
 * (/admin/historial-documentos): dar por revisada a mano una fila de la verificacion.
 *
 * La pantalla la pinta HistorialDocumentosController con admin/compresion_pdf/panel.blade.php.
 * La ficha la corrige la persona en el panel del visor (equipos.updateMetadata) o, sola, la
 * tarea de la noche (CorrectorFichaDocumento); aqui solo se deja constancia de la revision.
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
     * La persona reviso el documento EN EL VISOR y guardo la ficha a mano: la fila queda como
     * revisada por ella (ver VerificacionDocumento::marcarRevisadoPor). La ficha ya la guardo
     * el panel del visor con su propia ruta (equipos.updateMetadata); aqui solo se deja
     * constancia en Control de Auditoría, para pasar a la siguiente.
     */
    public function marcarRevisado(Request $request, int $id)
    {
        $reg = VerificacionDocumento::findOrFail($id);
        $reg->marcarRevisadoPor($request->user());

        return response()->json(['success' => true, 'motivo' => $reg->MOTIVO]);
    }
}
