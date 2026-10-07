<?php

namespace App\Support;

use App\Models\Almacen;
use App\Models\FrenteTrabajo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Datos que alimentan el formulario de la NOTA DE ENTREGA de una salida
 * (partials/salida_nota_campos + js/maquinaria/almacen_salida_nota.js). Lo usan las dos
 * pantallas que lo muestran: el inventario (/admin/almacen, "Registrar salida") y la
 * recepción de materiales ("Registrar y despachar"). Un solo sitio para que las dos
 * ofrezcan los mismos proyectos, contratos y almacenes.
 */
class DatosNotaSalida
{
    /**
     * Proyectos (frentes ACTIVOS) del campo "Proyecto", con sus CONTRATOS para las
     * sugerencias del campo "Contrato N°" (se gestionan en /admin/frentes).
     */
    public static function frentes(): Collection
    {
        return FrenteTrabajo::where('ESTATUS_FRENTE', 'ACTIVO')
            ->orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE', 'NOMBRE_FRENTE', 'CONTRATOS']);
    }

    /** { ID_FRENTE: ["CTR-2026-0042", ...] } para el campo "Contrato N°". */
    public static function contratosPorFrente(Collection $frentes): Collection
    {
        return $frentes->mapWithKeys(fn ($f) => [
            $f->ID_FRENTE => array_values(array_filter((array) ($f->CONTRATOS ?? []))),
        ]);
    }

    /**
     * Almacenes PROYECTO de cada frente — alimenta el campo "Almacén destino". Un frente
     * puede estar asignado a VARIOS almacenes desde el modal "Editar almacén"; cuando pasa
     * eso el destino no se puede deducir y hay que preguntárselo al usuario en vez de
     * rechazar la salida. Hoy no hay ningún frente así, pero basta un clic en ese modal para
     * volver a haberlo. El JS descarta el almacén de origen de esta lista, por eso aquí van
     * todos: el mapa no depende del almacén que se esté viendo.
     */
    public static function almacenesPorFrente(): Collection
    {
        return DB::table('almacen_frentes as af')
            ->join('almacenes as a', 'a.ID_ALMACEN', '=', 'af.ID_ALMACEN')
            ->where('a.TIPO', Almacen::TIPO_PROYECTO)
            ->where('a.ESTATUS', 'ACTIVO')
            ->whereNull('a.deleted_at')
            ->orderBy('a.NOMBRE')
            ->get(['af.ID_FRENTE', 'a.ID_ALMACEN', 'a.NOMBRE'])
            ->groupBy('ID_FRENTE')
            ->map(fn ($filas) => $filas->map(fn ($f) => [
                'id'     => (int) $f->ID_ALMACEN,
                'nombre' => $f->NOMBRE,
            ])->values());
    }
}
