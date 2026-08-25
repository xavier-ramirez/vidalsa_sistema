<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Correccion anexa a un documento del equipo.
 *
 * Convive con el documento principal (documentacion.LINK_*): los dos estan
 * vigentes. Ver la migracion create_documento_anexos_table para el porque.
 */
class DocumentoAnexo extends Model
{
    protected $table = 'documento_anexos';
    protected $primaryKey = 'ID_ANEXO';

    // Solo created_at: un anexo no se edita, se añade. Sin updated_at que mantener.
    public $timestamps = false;

    protected $fillable = [
        'ID_EQUIPO',
        'TIPO_DOC',
        'LINK',
        'DRIVE_FILE_ID',
        'ETIQUETA',
        'PRINCIPAL_DRIVE_ID',
        'SUBIDO_POR',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function autor()
    {
        return $this->belongsTo(Usuario::class, 'SUBIDO_POR', 'ID_USUARIO');
    }

    /**
     * El id de Drive que lleva dentro un LINK '/storage/google/<id>?v=<ts>'.
     * Vive aqui porque el mismo destripe hace falta al anexar y al comparar un
     * anexo con su principal.
     */
    public static function driveIdDeLink(?string $link): ?string
    {
        if (!$link || !str_starts_with($link, '/storage/google/')) return null;
        $ruta = parse_url($link, PHP_URL_PATH);
        $id = str_replace('/storage/google/', '', (string) $ruta);
        return $id !== '' ? $id : null;
    }
}
