<?php

namespace App\Models;

use App\Services\GoogleDriveService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class EquipoAuxiliar extends Model
{
    use SoftDeletes;

    protected $table      = 'equipos_auxiliares';
    protected $primaryKey = 'ID_AUXILIAR';
    // deleted_at + deleted_by para papelera con auditoria de quien borro.

    protected $fillable = [
        'TIPO', 'MARCA', 'MODELO', 'SERIAL', 'CODIGO_INTERNO', 'CAPACIDAD',
        'ANIO', 'COMBUSTIBLE', 'CONSUMO_PROMEDIO',
        'ESTADO_OPERATIVO', 'ID_FRENTE_ACTUAL', 'CONFIRMADO_EN_SITIO', 'DETALLE_UBICACION_ACTUAL',
        'ID_EQUIPO_HOST',
        'FOTO', 'OBSERVACIONES', 'CREADO_POR',
        'LINK_DOC_PROPIEDAD', 'LINK_CERTIFICADO', 'FECHA_VENCIMIENTO_CERT',
        'deleted_by',
    ];

    protected $casts = [
        'ANIO' => 'integer',
        'CONFIRMADO_EN_SITIO' => 'integer',
        'FECHA_VENCIMIENTO_CERT' => 'date',
    ];

    public static function tiposLabel(): array
    {
        return [
            // MAQUINA_DE_SOLDAR (no MAQUINA_SOLDAR): es la clave que tienen los registros
            // reales y la que produce la normalización "uppercase + guiones bajos" de
            // EquipoAuxiliarController. Tenerla distinta aquí generaba DOS tipos para lo
            // mismo — el del datalist y el del texto normalizado.
            'MAQUINA_DE_SOLDAR' => 'Máquina de Soldar',
            'LUMINARIA'        => 'Luminaria / Torre',
            'COMPRESOR'        => 'Compresor',
            'PLANTA_ELECTRICA' => 'Planta Eléctrica',
            'CONTAINER'        => 'Contenedor',
            'OTRO'             => 'Otro',
        ];
    }

    public static function tiposIcono(): array
    {
        return [
            'MAQUINA_DE_SOLDAR' => 'flash_on',
            'LUMINARIA'        => 'lightbulb',
            'COMPRESOR'        => 'compress',
            'PLANTA_ELECTRICA' => 'bolt',
            'CONTAINER'        => 'inventory_2',
            'OTRO'             => 'build',
        ];
    }

    public static function estadosLabel(): array
    {
        return [
            'OPERATIVO'      => 'Operativo',
            'INOPERATIVO'    => 'Inoperativo',
            'EN MANTENIMIENTO'=> 'En Mantenimiento',
            'EN_ALMACEN'     => 'En Almacén',
            'DESINCORPORADO' => 'Desincorporado',
        ];
    }

    public const ANCHOR_MAX_PER_HOST = 2;

    /**
     * Documentos PDF del auxiliar: tipo (el doc_type de la API) => columna de su link.
     * Fuente única para el botón del modal (uploadDoc/deleteDoc), el formulario de
     * crear/editar y la migración que sacó los viejos del disco 'public'.
     */
    public const DOCS = [
        'propiedad'   => 'LINK_DOC_PROPIEDAD',
        'certificado' => 'LINK_CERTIFICADO',
    ];

    /**
     * Sube un PDF del auxiliar y devuelve el link para su columna. Es el MISMO camino que
     * los documentos de equipos (GoogleDriveService::subirPdf): misma carpeta de Drive y
     * mismo proxy /storage/google/{id}, que exige sesión. Antes el formulario los guardaba
     * en el disco 'public', que nginx sirve SIN login.
     */
    public static function subirDocADrive(GoogleDriveService $drive, string $tipo, $archivo): string
    {
        return $drive->subirPdf($archivo, 'aux_' . $tipo . '_' . time() . '.pdf');
    }

    /**
     * Borra el archivo de un link de documento que ya no se usa: el reemplazado o eliminado
     * (llamar SOLO después de guardar la fila, para que si algo falla antes siga vivo) o uno
     * recién subido que no llegó a guardarse (para no dejarlo huérfano en Drive). Los de
     * Drive, como en equipos (GoogleDriveService::borrarTrasResponder); acepta también los
     * links viejos del disco 'public' (/storage/equipos_auxiliares/…).
     */
    public static function olvidarDoc(?string $link): void
    {
        if ($fileId = DocumentoAnexo::driveIdDeLink($link)) {
            GoogleDriveService::borrarTrasResponder($fileId);
        } elseif ($link && str_starts_with($link, '/storage/')) {
            $ruta = (string) parse_url($link, PHP_URL_PATH);
            Storage::disk('public')->delete(ltrim(substr($ruta, strlen('/storage/')), '/'));
        }
    }

    public function frente()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_ACTUAL', 'ID_FRENTE');
    }

    public function equipoHost()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO_HOST', 'ID_EQUIPO');
    }

    public function creador()
    {
        return $this->belongsTo(Usuario::class, 'CREADO_POR', 'ID_USUARIO');
    }
}
