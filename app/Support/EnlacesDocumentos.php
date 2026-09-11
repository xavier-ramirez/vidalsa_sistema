<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Donde viven los enlaces a los PDF de documentos (/storage/google/{id}?v=...) y las dos
 * reglas que protegen esos archivos de Drive. Un solo sitio para las usar desde la
 * compresion nocturna (docs:comprimir) y desde el borrado de archivos de la app
 * (DeleteGoogleDriveFile).
 *
 *   · Un archivo puede estar enlazado en VARIAS filas (hay 13 auxiliares con el mismo
 *     documento de propiedad): no se borra ni se reemplaza pensando que es de una sola.
 *   · El PC de desarrollo usa el MISMO Google Drive que el servidor pero OTRA base: lo que
 *     haga alli con los archivos le cambia los documentos al servidor.
 */
class EnlacesDocumentos
{
    /**
     * [tabla, columna del enlace, prefijo del nombre en Drive, nombre para mostrar].
     * Los prefijos son los de EquipoController::uploadDoc, EquipoAuxiliarController::uploadDoc
     * y EquipoController::anexarDoc ("correccion_<tipo>_": ahi el prefijo lleva el tipo).
     */
    public const DOCUMENTOS = [
        ['documentacion', 'LINK_DOC_PROPIEDAD',   'doc_propiedad_',   'Titulo de propiedad'],
        ['documentacion', 'LINK_POLIZA_SEGURO',   'poliza_seguro_',   'Poliza'],
        ['documentacion', 'LINK_ROTC',            'rotc_',            'ROTC'],
        ['documentacion', 'LINK_RACDA',           'racda_',           'RACDA'],
        ['documentacion', 'LINK_DOC_ADICIONAL',   'doc_adicional_',   'Certificado'],
        ['documentacion', 'LINK_DOC_ADICIONAL_2', 'doc_adicional_2_', 'Compraventa'],
        ['equipos_auxiliares', 'LINK_DOC_PROPIEDAD', 'aux_propiedad_',   'Doc. propiedad (auxiliar)'],
        ['equipos_auxiliares', 'LINK_CERTIFICADO',   'aux_certificado_', 'Certificado (auxiliar)'],
        ['documento_anexos', 'LINK', 'correccion_', 'Correccion anexa'],
    ];

    /**
     * ¿La base de datos de ESTA instalacion es la del servidor? Solo entonces se puede tocar
     * archivos de Drive (comprimirlos, borrarlos): el PC de desarrollo comparte el Drive
     * pero no la base, y alli cambiaria o borraria archivos que el servidor todavia usa.
     * Se mira la BASE, que es justo lo que no comparten:
     *   · base en este mismo equipo (127.0.0.1 / localhost) -> NO (PC de desarrollo);
     *   · base en otra maquina (el servicio MySQL del servidor) -> SI.
     * DRIVE_ES_SERVIDOR=true|false fuerza la respuesta si alguna vez hiciera falta.
     *
     * Devuelve [si/no, motivo legible].
     */
    public static function esBaseDelServidor(): array
    {
        $forzado = config('services.drive.es_servidor');
        if ($forzado !== null) {
            return [(bool) $forzado, $forzado ? 'fijado a mano como servidor (DRIVE_ES_SERVIDOR)' : 'fijado a mano como NO servidor (DRIVE_ES_SERVIDOR)'];
        }
        $conexion = config('database.default');
        $host = strtolower(trim((string) config("database.connections.$conexion.host")));
        if (in_array($host, ['127.0.0.1', 'localhost', '::1', ''], true)) {
            return [false, 'la base de datos esta en este mismo equipo (PC de desarrollo)'];
        }
        return [true, 'la base de datos es la del servidor'];
    }

    /** ¿Alguna fila (de cualquier documento, borrada o no) sigue enlazando este archivo? */
    public static function sigueEnUso(string $id): bool
    {
        foreach (self::DOCUMENTOS as [$tabla, $col]) {
            if (DB::table($tabla)->whereRaw(self::idEn($col) . ' = ?', [$id])->exists()) return true;
        }
        return DB::table('documento_anexos')->where('DRIVE_FILE_ID', $id)->exists();
    }

    /**
     * Cambia el archivo viejo por el nuevo en TODAS las filas que SIGUEN apuntandole —las de
     * equipos o auxiliares borrados incluidas: si se restauran, que abran— y devuelve cuantas
     * cambio. Una fila que se reemplazo entretanto ya no apunta al viejo y no se toca. Las
     * correcciones anexas que corrigen ese archivo (PRINCIPAL_DRIVE_ID) pasan a apuntar al
     * nuevo: la app compara ese ID con el del principal para saber si son del documento
     * vigente. Llamarlo dentro de una transaccion.
     */
    public static function cambiar(string $idViejo, string $idNuevo): int
    {
        $enlace = '/storage/google/' . $idNuevo . '?v=' . time();
        $total = 0;
        foreach (self::DOCUMENTOS as [$tabla, $col]) {
            $valores = [$col => $enlace] + ($tabla === 'documento_anexos'
                ? ['DRIVE_FILE_ID' => $idNuevo]      // la correccion guarda el ID tambien aparte
                : ['updated_at' => now()]);          // documento_anexos no tiene updated_at
            $total += DB::table($tabla)->whereRaw(self::idEn($col) . ' = ?', [$idViejo])->update($valores);
        }
        if ($total > 0) {
            DB::table('documento_anexos')->where('PRINCIPAL_DRIVE_ID', $idViejo)->update(['PRINCIPAL_DRIVE_ID' => $idNuevo]);
        }
        return $total;
    }

    /** SQL del ID de Drive dentro de un enlace "/storage/google/{id}?v=...". */
    private static function idEn(string $col): string
    {
        return "SUBSTRING_INDEX(SUBSTRING_INDEX($col, '/storage/google/', -1), '?', 1)";
    }
}
