<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Documentacion;
use Carbon\Carbon;

class HistorialDocumentosController extends Controller
{
    public function index()
    {
        // 1. Fetch all documentation that has at least one upload
        $docs = Documentacion::with(['equipo.tipo', 'equipo.frenteActual'])
            ->whereNotNull('PROPIEDAD_FECHA_SUBIDA')
            ->orWhereNotNull('POLIZA_FECHA_SUBIDA')
            ->orWhereNotNull('ROTC_FECHA_SUBIDA')
            ->orWhereNotNull('RACDA_FECHA_SUBIDA')
            ->get();

        // 2. Parse them into a flat array of "upload events"
        $events = collect();

        foreach ($docs as $doc) {
            $eName = $doc->equipo ? ($doc->equipo->tipo->nombre ?? 'Equipo') . ' ' . $doc->equipo->MARCA . ' ' . $doc->equipo->MODELO : 'Equipo Eliminado';
            $eId = $doc->equipo ? $doc->equipo->ID_EQUIPO : '#';

            if ($doc->PROPIEDAD_FECHA_SUBIDA && $doc->PROPIEDAD_SUBIDO_POR) {
                $events->push((object)[
                    'tipo' => 'Título de Propiedad',
                    'autor' => $doc->PROPIEDAD_SUBIDO_POR,
                    'fecha_raw' => $doc->PROPIEDAD_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->PROPIEDAD_FECHA_SUBIDA),
                    'link' => $doc->LINK_DOC_PROPIEDAD,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId
                ]);
            }
            if ($doc->POLIZA_FECHA_SUBIDA && $doc->POLIZA_SUBIDO_POR) {
                $events->push((object)[
                    'tipo' => 'Póliza de Seguro',
                    'autor' => $doc->POLIZA_SUBIDO_POR,
                    'fecha_raw' => $doc->POLIZA_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->POLIZA_FECHA_SUBIDA),
                    'link' => $doc->LINK_POLIZA_SEGURO,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId
                ]);
            }
            if ($doc->ROTC_FECHA_SUBIDA && $doc->ROTC_SUBIDO_POR) {
                $events->push((object)[
                    'tipo' => 'ROTC',
                    'autor' => $doc->ROTC_SUBIDO_POR,
                    'fecha_raw' => $doc->ROTC_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->ROTC_FECHA_SUBIDA),
                    'link' => $doc->LINK_ROTC,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId
                ]);
            }
            if ($doc->RACDA_FECHA_SUBIDA && $doc->RACDA_SUBIDO_POR) {
                $events->push((object)[
                    'tipo' => 'RACDA',
                    'autor' => $doc->RACDA_SUBIDO_POR,
                    'fecha_raw' => $doc->RACDA_FECHA_SUBIDA,
                    'fecha' => Carbon::parse($doc->RACDA_FECHA_SUBIDA),
                    'link' => $doc->LINK_RACDA,
                    'equipo_nombre' => $eName,
                    'equipo_id' => $eId
                ]);
            }
        }

        // 3. Sort descending by date
        $events = $events->sortByDesc('fecha_raw')->values();

        return view('admin.historial_documentos.index', compact('events'));
    }
}
