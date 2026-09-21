<?php

namespace App\Http\Controllers;

use App\Models\VerificacionDocumento;
use App\Services\CorrectorFichaDocumento;
use App\Support\PanelDocumentos;
use Illuminate\Http\Request;

/**
 * Lo que se puede HACER desde las pestañas de documentos de Control de Auditoría
 * (/admin/historial-documentos): dar por revisada a mano una fila de la verificacion, o
 * varias de una vez eligiendo sus filas en la tabla.
 *
 * La pantalla la pinta HistorialDocumentosController con admin/compresion_pdf/panel.blade.php.
 * La ficha la corrige la persona en el panel del visor (equipos.updateMetadata) o, sola, la
 * tarea diaria (CorrectorFichaDocumento); aqui se deja constancia de la revision y se ponen
 * los datos que ese panel no tiene (CorrectorFichaDocumento::ponerAMano).
 * La direccion vieja /admin/compresion-pdf sigue funcionando: lleva a la pestaña (index()).
 */
class CompresionPdfController extends Controller
{
    /** Tope de filas por envio del boton "Revisado" (una pagina entera cabe de sobra). */
    private const MAX_REVISADOS = 200;

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
     * revisada por ella (ver VerificacionDocumento::marcarRevisadoPor). Lo que el panel tiene
     * ya lo guardo con su propia ruta (equipos.updateMetadata); aqui se pone lo que no tiene
     * (las fechas de emision...) y se deja constancia en Control de Auditoría.
     */
    public function marcarRevisado(Request $request, int $id, CorrectorFichaDocumento $corrector)
    {
        $reg = VerificacionDocumento::findOrFail($id);
        // Los datos que el panel del visor no tiene (fechas de emision...), con el valor que
        // la persona dejo en su campo al guardar. Vacio = solo dar la fila por revisada.
        $valores = (array) $request->input('campos', []);
        $resultado = $corrector->ponerAMano($reg, $valores, $request->user());

        if (isset($resultado['error'])) {
            return response()->json(['success' => false, 'message' => $resultado['error']], 422);
        }
        return response()->json(['success' => true, 'puestos' => $resultado['puestos'], 'motivo' => $reg->refresh()->MOTIVO]);
    }

    /** "Revisar ahora": la lectura arranca en el minuto siguiente (ver VerificarDocumentos::pedirAhora). */
    public function leerAhora()
    {
        \App\Console\Commands\VerificarDocumentos::pedirAhora();
        return response()->json(['success' => true]);
    }

    /**
     * Las filas elegidas en la tabla (clic en la fila), dadas por revisadas de una vez, sin abrir
     * el visor. La ficha NO cambia (ni los datos que el visor ofreceria poner): solo queda
     * constancia de quien las reviso (ver VerificacionDocumento::marcarRevisadoPor).
     */
    public function marcarRevisados(Request $request, CorrectorFichaDocumento $corrector)
    {
        $ids = $request->validate([
            'ids'   => ['required', 'array', 'max:' . self::MAX_REVISADOS],
            'ids.*' => ['integer'],
        ])['ids'];

        $hechas = 0;
        // Las que ya coinciden no se pueden elegir ni tienen nada que revisar: se dejan como estan.
        $filas = VerificacionDocumento::whereIn('ID_REGISTRO', array_unique($ids))
            ->where('ESTADO', '<>', VerificacionDocumento::COINCIDE)->get();
        foreach ($filas as $reg) {
            $corrector->ponerAMano($reg, [], $request->user());
            $hechas++;
        }
        return response()->json(['success' => true, 'revisadas' => $hechas]);
    }
}
