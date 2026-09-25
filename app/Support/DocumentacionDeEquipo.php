<?php

namespace App\Support;

use App\Models\Documentacion;
use Carbon\Carbon;

/**
 * Lo que se escribe en `documentacion` cuando un documento del equipo cambia de PDF
 * o de fecha, en UN solo sitio.
 *
 * Nacio dentro de EquipoController (uploadDoc/updateMetadata, donde sigue usandose
 * a traves de sus metodos privados) y se saco aqui al aparecer el segundo camino que
 * escribe lo mismo: la carga masiva (CargaMasivaDocumentos). Si las reglas de
 * vencimiento vivieran en dos sitios, un documento subido de uno en uno y el mismo
 * subido en lote podrian dejar la ficha distinta.
 */
class DocumentacionDeEquipo
{
    /** Los documentos que VENCEN y su columna de vencimiento. Propiedad no vence. */
    public const VENCIMIENTO = [
        'poliza'    => 'FECHA_VENC_POLIZA',
        'rotc'      => 'FECHA_ROTC',
        'racda'     => 'FECHA_RACDA',
        'adicional' => 'FECHA_ADICIONAL',
    ];

    /** Enlace, fecha de subida y autor de cada tipo. */
    public const COLUMNAS = [
        'propiedad'   => ['link' => 'LINK_DOC_PROPIEDAD',   'fecha' => 'PROPIEDAD_FECHA_SUBIDA',   'autor' => 'PROPIEDAD_SUBIDO_POR'],
        'poliza'      => ['link' => 'LINK_POLIZA_SEGURO',   'fecha' => 'POLIZA_FECHA_SUBIDA',      'autor' => 'POLIZA_SUBIDO_POR'],
        'rotc'        => ['link' => 'LINK_ROTC',            'fecha' => 'ROTC_FECHA_SUBIDA',        'autor' => 'ROTC_SUBIDO_POR'],
        'racda'       => ['link' => 'LINK_RACDA',           'fecha' => 'RACDA_FECHA_SUBIDA',       'autor' => 'RACDA_SUBIDO_POR'],
        'adicional'   => ['link' => 'LINK_DOC_ADICIONAL',   'fecha' => 'ADICIONAL_FECHA_SUBIDA',   'autor' => 'ADICIONAL_SUBIDO_POR'],
        'adicional_2' => ['link' => 'LINK_DOC_ADICIONAL_2', 'fecha' => 'ADICIONAL_2_FECHA_SUBIDA', 'autor' => 'ADICIONAL_2_SUBIDO_POR'],
    ];

    /** La fecha de emision de cada tipo, cuando el documento la trae. */
    public const EMISION = [
        'propiedad' => 'FECHA_EMISION_PROPIEDAD',
        'poliza'    => 'FECHA_EMISION_POLIZA',
        'rotc'      => 'FECHA_EMISION_ROTC',
        'racda'     => 'FECHA_EMISION_RACDA',
    ];

    /**
     * Lo que se escribe al fijar el vencimiento de $tipo: la fecha y, si es futura, el fin
     * de la gestion (frente que la tramitaba + fecha), que ya no aplica a un documento
     * vigente.
     *
     * Una fecha VACIA borra el vencimiento. Es lo que hace falta para limpiar las fechas
     * huerfanas —las que quedaron sin documento detras— desde el panel del visor. Antes
     * esto entraba igual en Carbon::parse(''), que revienta con un 500.
     */
    public static function datosVencimiento(string $tipo, ?string $fecha): array
    {
        if ($fecha === null || trim($fecha) === '') {
            return [self::VENCIMIENTO[$tipo] => null];
        }

        $datos = [self::VENCIMIENTO[$tipo] => $fecha];
        if (Carbon::parse($fecha)->isFuture()) {
            $datos[$tipo . '_gestion_frente_id'] = null;
            $datos[$tipo . '_gestion_fecha']     = null;
        }
        return $datos;
    }

    /**
     * Diff {antes,despues} de lo que se va a escribir (mismo esquema que EquipoObserver),
     * para que el historial muestre valor viejo y nuevo. Se llama ANTES de guardar. Los
     * campos que no cambian se omiten. $doc null = el equipo aun no tiene fila.
     */
    public static function diff(?Documentacion $doc, array $datos): array
    {
        $diff = [];
        foreach ($datos as $field => $newValue) {
            $oldValue = $doc ? $doc->getRawOriginal($field) : null;
            // Los datetime salen de BD como "Y-m-d 00:00:00" pero el input llega "Y-m-d":
            // se normalizan para comparar y mostrar en el mismo formato.
            $oldCmp = is_string($oldValue) ? preg_replace('/ 00:00:00$/', '', $oldValue) : $oldValue;
            $newCmp = is_string($newValue) ? preg_replace('/ 00:00:00$/', '', $newValue) : $newValue;
            if ((string) $oldCmp === (string) $newCmp) continue;
            $diff[$field] = ['antes' => $oldCmp, 'despues' => $newCmp];
        }
        return $diff;
    }
}
