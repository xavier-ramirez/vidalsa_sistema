<?php

namespace App\Http\Controllers;

use App\Models\CompresionPdf;
use App\Services\CompresorPdf;
use App\Support\EnlacesDocumentos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla "Compresion de PDF": lo que hizo docs:comprimir (y lo comprimido a mano), para
 * revisarlo en la mañana. Solo lectura. Acceso: super.admin (routes/web.php).
 */
class CompresionPdfController extends Controller
{
    public function index(Request $request)
    {
        // Filtros de arriba: estado, tipo de documento y buscador (serial o documento). Un
        // valor que no esta en su lista (p. ej. 'all', "todos") es como no filtrar.
        $estado = in_array($request->input('estado'), [CompresionPdf::COMPRIMIDO, CompresionPdf::SALTADO, CompresionPdf::ERROR], true)
            ? $request->input('estado') : null;
        $documentos = CompresionPdf::query()->distinct()->orderBy('DOCUMENTO')->pluck('DOCUMENTO');
        $documento  = $documentos->contains($request->input('documento')) ? $request->input('documento') : null;
        $buscar     = trim((string) $request->input('buscar', ''));

        $resumen = CompresionPdf::query()
            ->select('ESTADO', DB::raw('COUNT(*) as n'), DB::raw('SUM(BYTES_ANTES) as antes'), DB::raw('SUM(BYTES_DESPUES) as despues'))
            ->groupBy('ESTADO')->get()->keyBy('ESTADO');

        $ultimaNoche = CompresionPdf::where('ORIGEN', 'noche')->max('created_at');

        // Si la tarea corre en ESTE equipo y por que: es lo primero que hay que poder mirar
        // tras desplegar, sin entrar al servidor.
        [$activa, $motivoActiva] = EnlacesDocumentos::esBaseDelServidor();
        $ghostscript = app(CompresorPdf::class)->disponible();
        // La misma zona con la que el programador decide si ya son las 12: schedule_timezone y, si no, app.timezone.
        $zona = config('app.schedule_timezone', config('app.timezone'));
        $horaApp = now($zona);

        $filas = CompresionPdf::query()
            ->when($estado, fn ($q) => $q->where('ESTADO', $estado))
            ->when($documento, fn ($q) => $q->where('DOCUMENTO', $documento))
            ->when($buscar !== '', function ($q) use ($buscar) {
                $like = '%' . addcslashes($buscar, '%_\\') . '%';
                $q->where(fn ($w) => $w->where('SERIAL', 'like', $like)->orWhere('DOCUMENTO', 'like', $like));
            })
            ->orderByDesc('created_at')->orderByDesc('ID_REGISTRO')
            ->paginate(50)->withQueryString();

        return view('admin.compresion_pdf.index', compact(
            'resumen', 'ultimaNoche', 'filas', 'estado', 'documentos', 'documento', 'buscar',
            'activa', 'motivoActiva', 'ghostscript', 'zona', 'horaApp'
        ));
    }
}
