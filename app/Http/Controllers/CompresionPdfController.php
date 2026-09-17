<?php

namespace App\Http\Controllers;

use App\Models\CatalogoSeguro;
use App\Models\Documentacion;
use App\Models\EquipoAuditLog;
use App\Models\VerificacionDocumento;
use App\Observers\DocumentacionObserver;
use App\Support\PanelDocumentos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se puede HACER desde las pestañas de documentos de Control de Auditoría
 * (/admin/historial-documentos): corregir una ficha con lo que dice su PDF.
 *
 * La pantalla la pinta HistorialDocumentosController con admin/compresion_pdf/panel.blade.php;
 * aqui solo vive la accion, que es el UNICO punto donde esos datos cambian.
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
     * Pone en la ficha lo que dice el documento (propietario, aseguradora o fechas), solo con
     * lo que el verificador marco como distinto: lo que ya esta bien no se toca.
     *
     * Antes de escribir se comprueba que la ficha SIGUE como estaba cuando se leyo el PDF: si
     * alguien la corrigio a mano entretanto, ese dato se respeta y se avisa (la lectura puede
     * ser de hace semanas). Todo dentro de una transaccion, con la fila bloqueada.
     */
    public function aplicarDocumento(Request $request, int $id)
    {
        $reg = VerificacionDocumento::findOrFail($id);

        if ($reg->esDeOtroVehiculo()) {
            return back()->with('error', 'Ese PDF es de otro vehículo: hay que corregir el archivo enlazado, no copiar sus datos.');
        }
        if ($reg->esLecturaParcial()) {
            return back()->with('error', 'Ese documento se leyó a medias: dice menos que la ficha. Ábrelo y corrígelo a mano.');
        }
        if ($reg->sinConfirmar()) {
            return back()->with('error', 'De ese PDF no se pudo leer la placa ni el serial: ábrelo y comprueba que sea de este vehículo.');
        }
        if (!$reg->aplicable()) {
            return back()->with('error', 'Ese documento ya coincide con la ficha: no hay nada que cambiar.');
        }

        // Los nombres del catalogo, para poder decir "MAMPRECA" y no "1" cuando lo que quedo
        // pendiente es la aseguradora.
        $aseguradoras = CatalogoSeguro::pluck('NOMBRE_ASEGURADORA', 'ID_SEGURO')->all();

        $resultado = DB::transaction(function () use ($reg, $request, $aseguradoras) {
            $doc = Documentacion::where('ID_EQUIPO', $reg->ID_EQUIPO)->lockForUpdate()->first();
            if (!$doc) return ['error' => 'La ficha de ese equipo ya no existe.'];

            $puestos = $saltados = $cambios = $quedan = [];
            foreach ($reg->DIFERENCIAS as $campo => $d) {
                // Lo que la ficha tenia cuando se leyo el PDF. Si ya no es eso, alguien lo
                // corrigio a mano despues: su correccion manda.
                $ahora = $doc->{$campo};
                if ($ahora instanceof \DateTimeInterface) $ahora = $ahora->format('Y-m-d');
                // La aseguradora se compara por su ID (ficha_valor), no por el nombre que se
                // muestra; los demas campos guardan el mismo texto que se enseña.
                $esperado = array_key_exists('ficha_valor', $d) ? $d['ficha_valor'] : ($d['ficha'] ?? '');
                if ((string) $ahora !== (string) $esperado) {
                    // Alguien lo corrigio a mano despues de leer el PDF: su correccion manda y
                    // la fila queda PARA REVISAR con esa diferencia a la vista. No se vuelve a
                    // ofrecer el boton para ese dato: quien decida tiene que mirar el documento
                    // (si se dejara, el siguiente clic pisaria la correccion de la persona).
                    $saltados[] = $d['etiqueta'];
                    $quedan[$campo] = [
                        'etiqueta'  => $d['etiqueta'],
                        'ficha'     => $campo === 'ID_SEGURO' ? ($aseguradoras[$ahora] ?? (string) $ahora) : (is_scalar($ahora) ? (string) $ahora : null),
                        'documento' => $d['documento'],
                        'a_mano'    => true,
                    ];
                    continue;
                }
                // La aseguradora se guarda por su ID del catalogo; el resto, tal cual se leyo.
                $doc->{$campo} = $campo === 'ID_SEGURO' ? (int) $d['valor'] : $d['documento'];
                $puestos[] = $d['etiqueta'];
                // Para el historial se guardan los nombres, no los IDs: es lo que se lee.
                $cambios[$campo] = ['antes' => $d['ficha'], 'despues' => $d['documento']];
            }

            if (!$puestos) {
                return ['error' => 'La ficha ya no dice lo que decía cuando se leyó el documento: revísala y vuelve a leer el PDF.'];
            }
            $doc->save();

            // Historial del equipo. DocumentacionObserver solo audita PLACA, NRO_DE_DOCUMENTO y
            // NOMBRE_DEL_TITULAR: los datos de la poliza (aseguradora y fechas) no dejarian
            // rastro, asi que se registran aqui — y solo esos, para no duplicar los suyos.
            $propios = array_diff_key($cambios, array_flip(DocumentacionObserver::AUDITED));
            if ($propios) {
                EquipoAuditLog::registrar($reg->ID_EQUIPO, 'edit', $propios + ['_origen' => 'Verificación de documentos']);
            }

            $reg->update([
                'ESTADO'       => $quedan ? VerificacionDocumento::DIFIERE : VerificacionDocumento::COINCIDE,
                // A_MANO: lo que queda ya no lo arregla ningun boton, lo decide una persona.
                'A_MANO'       => (bool) $quedan,
                'MOTIVO'       => $quedan
                    ? 'Se corrigio ' . implode(', ', $puestos) . '; lo demas lo cambio alguien a mano y hay que mirarlo'
                    : 'Corregido con lo que dice el documento',
                'DIFERENCIAS'  => $quedan ?: null,
                'APLICADO_POR' => $request->user()?->getKey(),
                'APLICADO_EN'  => now(),
            ]);

            return ['puestos' => $puestos, 'saltados' => $saltados];
        });

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
