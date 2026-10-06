<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un documento de embarque (Bill of Lading) y los equipos que ampara.
 *
 * Ver la migracion create_embarques_tables para el porque de la tabla aparte. Se crea desde
 * la carga masiva (CargaMasivaDocumentos::aplicarEmbarque); sus datos se corrigen en el panel
 * del visor (EquipoController::guardarDatosEmbarque).
 */
class Embarque extends Model
{
    protected $table = 'embarques';
    protected $primaryKey = 'ID_EMBARQUE';

    /** TIPO_DOCUMENTO: 'BL' (el de la naviera, valor por omisión) o el certificado de origen del INTT. */
    public const TIPO_CERTIFICADO_ORIGEN = 'CERTIFICADO_ORIGEN';

    protected $fillable = [
        'NRO_BL', 'TIPO_DOCUMENTO', 'BUQUE', 'PUERTO_CARGA', 'PUERTO_DESCARGA', 'FECHA_EMBARQUE',
        'LINK', 'ARCHIVO', 'UNIDADES', 'SUBIDO_POR',
    ];

    protected $casts = [
        'FECHA_EMBARQUE' => 'date',
    ];

    public function esCertificadoOrigen(): bool
    {
        return $this->TIPO_DOCUMENTO === self::TIPO_CERTIFICADO_ORIGEN;
    }

    /** Nombre del documento tal como se muestra en el detalle del equipo y en el visor. */
    public function rotulo(): string
    {
        return $this->esCertificadoOrigen() ? 'Certificado de origen' : 'Embarque BL';
    }

    public function equipos()
    {
        return $this->belongsToMany(Equipo::class, 'embarque_equipo', 'ID_EMBARQUE', 'ID_EQUIPO')
                    ->withPivot('VIN', 'ASOCIADO_POR', 'created_at');
    }
}
