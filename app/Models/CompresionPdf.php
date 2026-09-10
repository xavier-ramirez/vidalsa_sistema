<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una fila por documento que docs:comprimir ya proceso (comprimido, saltado o con error).
 * Ver la migracion create_compresion_pdf_registro_table.
 */
class CompresionPdf extends Model
{
    protected $table = 'compresion_pdf_registro';
    protected $primaryKey = 'ID_REGISTRO';

    public const COMPRIMIDO = 'comprimido';
    public const SALTADO    = 'saltado';
    public const ERROR      = 'error';

    protected $fillable = [
        'TABLA', 'COLUMNA', 'FILA_ID', 'DOCUMENTO', 'SERIAL',
        'DRIVE_ID_VIEJO', 'DRIVE_ID_NUEVO', 'BYTES_ANTES', 'BYTES_DESPUES',
        'ESTADO', 'MOTIVO', 'ORIGEN',
    ];
}
