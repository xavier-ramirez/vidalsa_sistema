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
 *   analizar()  UN archivo por peticion: lo sube a Drive y responde; la lectura (el OCR de
 *               Drive, ~8 s por archivo) sigue en segundo plano y su resultado sale en la
 *               tabla de Revision de documentos.
 *   aplicar()   Escribe en la ficha lo que el usuario aprobo, de una fila.
 *   descartar() Borra de Drive el PDF de una propuesta que el usuario no quiso (super.admin).
 *
 * Permiso: SOLO 'docs.carga.masiva', que es EXCLUSIVO y ni super.admin hereda (ver
 * autorizar(), abajo). Con esa clave basta, sin super.admin: se entra a Auditoría de
 * Documentos solo a la revisión de lo cargado (ver Usuario::veAuditoriaDocumentos).
 * Los tres pasos piden lo mismo; descartar, ademas, super.admin.
 */
class CargaMasivaDocumentosController extends Controller
{
    public function __construct(private CargaMasivaDocumentos $servicio) {}

    /** Sube un PDF y lo deja leyendose en segundo plano (sin tocar ninguna ficha). */
    public function analizar(Request $request)
    {
        $this->autorizar();

        $request->validate([
            // El techo lo manda GoogleDriveService::MAX_PDF_KB (la comprobacion central, que
            // vale para todas las puertas). Aqui se repite para avisar ANTES de procesar.
            'file' => 'required|file|mimes:pdf|max:' . \App\Services\GoogleDriveService::MAX_PDF_KB,
            // SIEMPRE se dice que documento se carga: el sistema busca ESE y lo que resulte ser
            // otro no se asocia (queda como "Otro documento", ver CargaMasivaDocumentos).
            'tipo' => ['required', Rule::in(CargaMasivaDocumentos::TIPOS)],
        ], [
            'tipo.required' => 'Elige primero qué documento vas a cargar.',
            'file.required' => 'Debe seleccionar un archivo.',
            'file.mimes'    => 'Solo se aceptan archivos en formato PDF.',
            // En KB, igual que el aviso de la comprobacion central: si uno dice "2,9 MB" y
            // el otro "3.000 KB" parecen dos limites distintos.
            'file.max'      => 'El archivo supera el tamaño máximo permitido ('
                               . number_format(\App\Services\GoogleDriveService::MAX_PDF_KB, 0, ',', '.') . ' KB).',
        ]);

        $archivo = $request->file('file');
        $tipo = $request->input('tipo');

        // Lo que la pantalla espera es solo la SUBIDA a Drive. Si no se pudo, no hay fila en
        // la tabla: se dice aqui, con su motivo, que es el unico sitio donde se va a leer.
        $subido = $this->servicio->subir($archivo, $tipo);
        if (isset($subido['estado'])) {
            return response()->json(['success' => true, 'propuesta' => $subido]);
        }

        // La LECTURA (OCR de Drive, ~8 s por PDF) va en segundo plano, despues de responder: la
        // persona no se queda mirando el modal y su resultado sale en la tabla de Revision de
        // documentos. Va a una fila con UN solo lector (ColaCargaMasiva): leer treinta a la vez
        // ocuparia todo el servidor.
        try {
            \App\Support\ColaCargaMasiva::encolar($archivo, $tipo, $subido);
        } catch (\Throwable $e) {
            // Sin fila nadie lo leeria: el PDF vuelve a la papelera de Drive y se dice que NO se subio.
            \Illuminate\Support\Facades\Log::error('Carga masiva: no se pudo dejar en la fila', ['archivo' => $subido['nombre'], 'error' => $e->getMessage()]);
            \App\Services\GoogleDriveService::borrarTrasResponder($subido['driveId']);
            return response()->json(['success' => true, 'propuesta' => [
                'archivo' => $subido['nombre'], 'link' => null, 'aviso' => 'No se pudo guardar el archivo para leerlo. Vuelve a subirlo.']]);
        }
        defer(fn () => \App\Support\ColaCargaMasiva::leer($this->servicio));

        // Content-Length: con el servidor de desarrollo (php artisan serve) el navegador da la
        // respuesta por terminada al recibirla, sin esperar a que acabe la lectura de atras.
        $respuesta = response()->json(['success' => true, 'en_segundo_plano' => true,
            'propuesta' => ['archivo' => $subido['nombre'], 'link' => $subido['link']]]);
        return $respuesta->header('Content-Length', (string) strlen($respuesta->getContent()));
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
            'vence'     => 'nullable|date_format:Y-m-d',
            'emision'   => 'nullable|date_format:Y-m-d',
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
        // Manda el PDF a la papelera de Drive: solo super.admin, como Eliminar un documento.
        abort_unless(auth()->user()->can('super.admin'), 403, 'Solo un super administrador puede descartar documentos.');

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
