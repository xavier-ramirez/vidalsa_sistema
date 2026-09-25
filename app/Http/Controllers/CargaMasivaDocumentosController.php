<?php

namespace App\Http\Controllers;

use App\Services\CargaMasivaDocumentos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Carga masiva de documentos (menu Acciones de Control de Auditoria).
 *
 * Tres puertas, una por paso. El trabajo de verdad esta en CargaMasivaDocumentos; aqui
 * solo se valida lo que llega y se traduce a JSON.
 *
 *   analizar()  UN archivo por peticion. La pantalla los manda de uno en uno y va pintando
 *               cada resultado: treinta PDF en una sola peticion se caerian por timeout
 *               (el OCR de Drive tarda ~8 s por archivo).
 *   aplicar()   Escribe en la ficha lo que el usuario aprobo, de una fila.
 *   descartar() Borra de Drive el PDF de una propuesta que el usuario no quiso.
 *
 * Permiso: la pantalla es de super.admin (igual que el menu Acciones donde vive el boton) y
 * ademas hace falta 'docs.carga.masiva', que es EXCLUSIVO y ni super.admin hereda (ver
 * autorizar(), abajo). Los tres pasos piden lo mismo.
 */
class CargaMasivaDocumentosController extends Controller
{
    public function __construct(private CargaMasivaDocumentos $servicio) {}

    /** Sube un PDF, lo lee y devuelve la propuesta (sin tocar ninguna ficha). */
    public function analizar(Request $request)
    {
        $this->autorizar();

        $request->validate([
            // El techo lo manda GoogleDriveService::MAX_PDF_KB (la comprobacion central, que
            // vale para todas las puertas). Aqui se repite para avisar ANTES de procesar.
            'file' => 'required|file|mimes:pdf|max:' . \App\Services\GoogleDriveService::MAX_PDF_KB,
            'tipo' => ['nullable', Rule::in(CargaMasivaDocumentos::TIPOS)],
        ], [
            'file.required' => 'Debe seleccionar un archivo.',
            'file.mimes'    => 'Solo se aceptan archivos en formato PDF.',
            // En KB, igual que el aviso de la comprobacion central: si uno dice "2,9 MB" y
            // el otro "3.000 KB" parecen dos limites distintos.
            'file.max'      => 'El archivo supera el tamaño máximo permitido ('
                               . number_format(\App\Services\GoogleDriveService::MAX_PDF_KB, 0, ',', '.') . ' KB).',
        ]);

        // Leer con Drive tarda; el limite por defecto de PHP no da para un PDF pesado.
        set_time_limit(180);

        return response()->json([
            'success'   => true,
            'propuesta' => $this->servicio->analizar($request->file('file'), $request->input('tipo') ?: null),
        ]);
    }

    /** Escribe en la ficha del equipo el documento ya analizado. */
    public function aplicar(Request $request)
    {
        $this->autorizar();

        // El id es de una ficha u otra segun 'auxiliar', asi que su tabla se comprueba
        // aparte (Rule::exists con la tabla que toque) en vez de con un exists fijo.
        $esAux = $request->boolean('auxiliar');
        $datos = $request->validate([
            'auxiliar'  => 'nullable|boolean',
            'id_equipo' => ['required', 'integer', $esAux
                ? Rule::exists('equipos_auxiliares', 'ID_AUXILIAR')->whereNull('deleted_at')
                : Rule::exists('equipos', 'ID_EQUIPO')->whereNull('deleted_at')],
            'tipo'      => ['required', Rule::in(CargaMasivaDocumentos::TIPOS)],
            'link'      => 'required|string|starts_with:/storage/google/',
            'vence'     => 'nullable|date',
            'emision'   => 'nullable|date',
            'pisar'     => 'nullable|boolean',
            // Modo ensayo: comprueba y dice que haria, pero no escribe nada.
            'ensayo'    => 'nullable|boolean',
            // Si esta es la ULTIMA ficha de la propuesta (un RACDA se enlaza a varias, de una en
            // una): solo entonces la fila pasa a "Aplicado". Sin el campo, se cierra (una ficha).
            'cerrar'    => 'nullable|boolean',
        ], [
            'id_equipo.exists' => $esAux ? 'Ese equipo auxiliar ya no existe.' : 'Ese equipo ya no existe.',
        ]);

        // Solo se enlaza un PDF que se subio por esta pantalla, y a una ficha y como el tipo que
        // su propuesta dice. El enlace llega del navegador: sin esto se podia enganchar
        // cualquier archivo de Drive (el documento de OTRO equipo) o una propuesta a cualquier
        // ficha.
        if (!$this->servicio->propuestaAdmite($datos['link'], (int) $datos['id_equipo'], $esAux, $datos['tipo'])) {
            return response()->json(['success' => false,
                'message' => 'Ese PDF no es una propuesta de la carga masiva para esta ficha (o ya se descartó). Recarga la tabla.'], 422);
        }
        $cerrar = !$request->has('cerrar') || $request->boolean('cerrar');

        $r = $this->servicio->aplicar(
            (int) $datos['id_equipo'],
            $datos['tipo'],
            $datos['link'],
            $datos['vence'] ?? null,
            $datos['emision'] ?? null,
            (bool) ($datos['pisar'] ?? false),
            (bool) ($datos['ensayo'] ?? false),
            $esAux,
            $cerrar,
        );

        // La ULTIMA ficha no entro (documento anterior, no quiso reemplazar...) pero alguna de
        // las anteriores si: la propuesta igualmente queda resuelta. Si no, se quedaria "Por
        // aplicar" con el PDF (o sus partes, en un ROTC de flota) ya en uso.
        if (!$r['ok'] && $cerrar && empty($datos['ensayo']) && $this->servicio->yaSeAplicoAlgo($datos['link'])) {
            $this->servicio->cerrarPropuesta($datos['link']);
        }

        return response()->json([
            'success' => $r['ok'],
            'message' => $r['mensaje'],
        ] + (isset($r['requiere_pisar']) ? ['requiere_pisar' => true] : [])
          + (isset($r['ensayo']) ? ['ensayo' => true] : []), $r['ok'] ? 200 : 422);
    }

    /** El usuario descarto la propuesta: su PDF sale de Drive. */
    public function descartar(Request $request)
    {
        $this->autorizar();

        $datos = $request->validate([
            'link' => 'required|string|starts_with:/storage/google/',
        ]);

        if (!$this->servicio->descartar($datos['link'])) {
            return response()->json(['success' => false,
                'message' => 'Ese PDF ya está en una ficha (o no es de la carga masiva): no se descarta. Recarga la tabla.'], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Esta pantalla tiene SU PROPIO permiso, 'docs.carga.masiva', y es de los EXCLUSIVOS
     * (Usuario::PERMISOS_EXPLICITOS): ni super.admin lo hereda, hay que marcarlo a mano en la
     * ficha del usuario. Por que aparte: subir de uno en uno (uploadDoc, 'user.edit') toca UNA
     * ficha que el usuario esta mirando; esto sube PDF en lote y los engancha SOLO a las
     * fichas que reconoce, asi que puede cambiarle la documentacion a media flota de un tiron.
     */
    private function autorizar(): void
    {
        abort_unless(auth()->user()?->can('docs.carga.masiva'), 403, 'No tiene permiso para la carga masiva de documentos.');
    }
}
