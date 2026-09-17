<?php

namespace App\Models;

use App\Services\LectorDocumentoPdf;
use Illuminate\Database\Eloquent\Model;

/**
 * Una fila por equipo, tipo de documento y archivo que docs:verificar-documentos ya leyo.
 * Ver la migracion create_verificacion_documento_registro_table.
 */
class VerificacionDocumento extends Model
{
    protected $table = 'verificacion_documento_registro';
    protected $primaryKey = 'ID_REGISTRO';

    /** Tipos y estados viven en el lector, que es quien decide cual poner. */
    public const PROPIEDAD   = LectorDocumentoPdf::PROPIEDAD;
    public const POLIZA      = LectorDocumentoPdf::POLIZA;
    public const COINCIDE    = LectorDocumentoPdf::COINCIDE;
    public const DIFIERE     = LectorDocumentoPdf::DIFIERE;
    public const ILEGIBLE    = LectorDocumentoPdf::ILEGIBLE;
    public const SIN_ARCHIVO = LectorDocumentoPdf::SIN_ARCHIVO;
    public const ERROR       = LectorDocumentoPdf::ERROR;

    /** Los que pide revisar una persona: no se pudo leer, no hay archivo o fallo. */
    public const A_REVISAR = [self::ILEGIBLE, self::SIN_ARCHIVO, self::ERROR];

    /**
     * Cuantas noches se reintenta un documento que salio ilegible o con error. Drive devuelve
     * de vez en cuando el documento vacio, y un tropiezo no puede dejar marcado para siempre
     * un titulo que se lee bien (la compresion hace lo mismo con sus errores). Lo miran el
     * comando, para volver a encolarlo, y el panel, para contar lo que falta por leer.
     */
    public const MAX_INTENTOS = 3;

    /** Como se llama cada documento en la pantalla. */
    public const NOMBRES = [self::PROPIEDAD => 'Título de propiedad', self::POLIZA => 'Póliza'];

    protected $fillable = [
        'ID_EQUIPO', 'TIPO', 'PLACA', 'SERIAL', 'DRIVE_ID',
        'LEIDO', 'DIFERENCIAS', 'ESTADO', 'MOTIVO', 'CARACTERES', 'INTENTOS', 'APLICADO_POR', 'APLICADO_EN',
    ];

    protected $casts = [
        'LEIDO'       => 'array',
        'DIFERENCIAS' => 'array',
        'APLICADO_EN' => 'datetime',
    ];

    /**
     * ¿Se puede corregir la ficha con lo que dice este documento? Solo si hay diferencias que
     * aplicar y el documento es de ESTE vehiculo: cuando el PDF resulto ser de otra placa, lo
     * que hay que arreglar es el archivo enlazado, no copiar sus datos a esta ficha.
     */
    public function aplicable(): bool
    {
        return $this->ESTADO === self::DIFIERE
            && !empty($this->DIFERENCIAS)
            && !$this->esDeOtroVehiculo()
            && !$this->esLecturaParcial();
    }

    /** El documento se leyo a medias: lo que dice es MENOS que lo que tiene la ficha. */
    public function esLecturaParcial(): bool
    {
        return (bool) ($this->LEIDO['lectura_parcial'] ?? false);
    }

    public function esDeOtroVehiculo(): bool
    {
        return (bool) ($this->LEIDO['otra_placa'] ?? false);
    }

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }
}
