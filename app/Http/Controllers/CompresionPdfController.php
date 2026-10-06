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
     * ya lo guardo con su propia ruta (equipos.updateMetadata, incluida la fecha de emision);
     * aqui se pone lo que no tiene (el titular del ROTC) y se deja constancia en Control de
     * Auditoría.
     */
    public function marcarRevisado(Request $request, int $id, CorrectorFichaDocumento $corrector)
    {
        $reg = VerificacionDocumento::findOrFail($id);
        // Solo las lecturas de la noche, igual que marcarRevisados: una propuesta de la carga
        // masiva no esta en ninguna ficha todavia (se enlaza sola o se descarta con su boton).
        // Darla por revisada la dejaba "Coincide" sin haberse enlazado, y sin sus botones.
        if ($reg->ORIGEN !== VerificacionDocumento::DE_LA_NOCHE) {
            return response()->json(['success' => false,
                'message' => 'Este PDF viene de la carga masiva: se enlaza solo a su ficha o se descarta con su botón.'], 422);
        }
        // Los datos que el panel del visor no tiene (el titular del ROTC), con el valor que
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
     * el visor. De cada una se ponen en la ficha SOLO las fechas que tiene vacias y el documento
     * trae (CorrectorFichaDocumento::fechasVacias); lo demas no cambia. Queda constancia de quien
     * las reviso (ver VerificacionDocumento::marcarRevisadoPor).
     */
    public function marcarRevisados(Request $request, CorrectorFichaDocumento $corrector)
    {
        $ids = $request->validate([
            'ids'   => ['required', 'array', 'max:' . self::MAX_REVISADOS],
            'ids.*' => ['integer'],
        ])['ids'];

        $hechas = 0;
        // Las que ya coinciden no se pueden elegir ni tienen nada que revisar: se dejan como estan.
        //
        // Y de la carga masiva, SOLO lo que ya esta en su ficha y quedo para revisar (enlazado sin
        // fecha, o un BL sin numero): lo demas son PROPUESTAS de un PDF que todavia no esta en
        // ninguna ficha, pueden no tener ni equipo, y darlas por revisadas las dejaria como
        // resueltas sin que el documento hubiera llegado a la ficha.
        $filas = VerificacionDocumento::whereIn('ID_REGISTRO', array_unique($ids))
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('ORIGEN', VerificacionDocumento::DE_LA_NOCHE)
                    ->where('ESTADO', '<>', VerificacionDocumento::COINCIDE))
                ->orWhere(fn ($w) => $w->where('ORIGEN', VerificacionDocumento::DE_CARGA_MASIVA)
                    ->where('ESTADO', VerificacionDocumento::APLICADO)->where('A_MANO', true)))
            ->get();
        $fechasPuestas = 0;
        $fallaron = [];
        foreach ($filas as $reg) {
            // La de la carga ya esta en su ficha: solo sale de "para revisar".
            if ($reg->ORIGEN === VerificacionDocumento::DE_CARGA_MASIVA) {
                $reg->marcarRevisadoPor($request->user());
                $hechas++;
                continue;
            }
            // Cada fila va en su propia transacción y se cuenta aparte: una que reviente —la
            // ficha borrada entre medias, o un choque de bloqueos con la tarea nocturna— no puede
            // tumbar la tanda entera y dejar sin contar las que sí pasaron.
            try {
                $fechas = $corrector->fechasVacias($reg);
                $resultado = $corrector->ponerAMano($reg, $fechas, $request->user());
            } catch (\Throwable $e) {
                // El detalle va al log; al navegador solo un mensaje nuestro. getMessage() de
                // Eloquent o del driver se lee como "No query results for model [App\Models\...]"
                // —o trae el SQL entero— y acaba pintado en un aviso de la pantalla.
                report($e);
                $fallaron[] = 'No se pudo revisar la lectura ' . $reg->ID_REGISTRO . ': vuelve a intentarlo.';
                continue;
            }
            // Solo cuenta como revisada la que de verdad se revisó: ponerAMano puede devolver un
            // error y no marcar nada. Antes se sumaba igual y el usuario leía "N revisadas"
            // aunque alguna se hubiera quedado como estaba.
            if (isset($resultado['error'])) {
                $fallaron[] = $resultado['error'];
                continue;
            }
            $fechasPuestas += count($resultado['puestos'] ?? []);
            $hechas++;
        }
        return response()->json([
            'success'   => true,
            'revisadas' => $hechas,
            'fechas'    => $fechasPuestas,
            'fallaron'  => count($fallaron),
            'motivo'    => $fallaron[0] ?? null,   // el primero, para poder decir POR QUÉ
        ]);
    }
}
