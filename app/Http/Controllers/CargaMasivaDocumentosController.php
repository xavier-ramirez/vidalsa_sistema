<?php

namespace App\Http\Controllers;

use App\Services\CargaMasivaDocumentos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Carga masiva de documentos (menu Acciones de Control de Auditoria).
 *
 * El trabajo de verdad esta en CargaMasivaDocumentos; aqui solo se valida lo que llega y se
 * traduce a JSON.
 *
 *   analizar()  UN archivo por peticion: lo sube a Drive y responde; la lectura (el OCR de
 *               Drive, ~8 s por archivo) sigue en segundo plano, lo que coincide se enlaza
 *               solo (no hay boton de Aplicar) y el resultado sale en la tabla de Revision
 *               de documentos.
 *   descartar() Borra de Drive el PDF de una propuesta que no se enlazo (super.admin).
 *   buscarEquipo() / atarVin()  Un VIN del BL que no caso con ninguna ficha (su serial difiere
 *               en algo) se ata a mano a la ficha que la persona elige.
 *
 * Permiso: SOLO 'docs.carga.masiva', que es EXCLUSIVO y ni super.admin hereda (ver
 * autorizar(), abajo). Con esa clave basta, sin super.admin: se entra a Auditoría de
 * Documentos solo a la revisión de lo cargado (ver Usuario::veAuditoriaDocumentos).
 * Todas piden lo mismo; descartar, ademas, super.admin.
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

    /** Fichas para atar un VIN que falta: lo mas parecido primero (ver CargaMasivaDocumentos::buscarEquipo). */
    public function buscarEquipo(Request $request)
    {
        $this->autorizar();
        $q = (string) $request->validate(['q' => 'required|string|max:60'])['q'];

        return response()->json(['success' => true, 'equipos' => $this->servicio->buscarEquipo($q)]);
    }

    /** Ata a mano un VIN del BL que no caso con ninguna ficha a la que la persona eligio. */
    public function atarVin(Request $request)
    {
        $this->autorizar();
        $datos = $request->validate([
            'link'      => 'required|string|starts_with:/storage/google/',
            'vin'       => 'required|string|max:40',
            'id_equipo' => ['required', 'integer', Rule::exists('equipos', 'ID_EQUIPO')->whereNull('deleted_at')],
        ], ['id_equipo.exists' => 'Ese equipo ya no existe.']);

        $r = $this->servicio->atarVin($datos['link'], $datos['vin'], (int) $datos['id_equipo']);
        return response()->json(['success' => $r['ok'], 'message' => $r['mensaje']], $r['ok'] ? 200 : 422);
    }

    /**
     * Ata a mano a la ficha elegida un PDF de la carga que no se enlazo solo. Si la ficha ya tiene
     * ese documento responde requiere_pisar y la pantalla pregunta antes de reemplazarlo.
     */
    public function atarDocumento(Request $request)
    {
        $this->autorizar();
        $datos = $request->validate([
            'link'       => 'required|string|starts_with:/storage/google/',
            'id_equipo'  => ['required', 'integer', Rule::exists('equipos', 'ID_EQUIPO')->whereNull('deleted_at')],
            'reemplazar' => 'nullable|boolean',
        ], ['id_equipo.exists' => 'Ese equipo ya no existe.']);

        $r = $this->servicio->atarDocumento($datos['link'], (int) $datos['id_equipo'], (bool) ($datos['reemplazar'] ?? false));
        return response()->json(['success' => $r['ok'], 'message' => $r['mensaje'],
            'requiere_pisar' => !empty($r['requiere_pisar'])], $r['ok'] ? 200 : 422);
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
