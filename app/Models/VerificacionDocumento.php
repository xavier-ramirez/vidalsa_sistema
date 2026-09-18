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
    public const ROTC        = LectorDocumentoPdf::ROTC;
    public const RACDA       = LectorDocumentoPdf::RACDA;
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

    /** Como se llama cada documento en la pantalla, en el orden en que se revisan. */
    public const NOMBRES = [
        self::PROPIEDAD => 'Título de propiedad',
        self::POLIZA    => 'Póliza',
        self::ROTC      => 'ROTC',
        self::RACDA     => 'RACDA',
    ];

    /** [tipo => columna del enlace en documentacion]. Mismo orden que NOMBRES. */
    public const ENLACES = [
        self::PROPIEDAD => 'LINK_DOC_PROPIEDAD',
        self::POLIZA    => 'LINK_POLIZA_SEGURO',
        self::ROTC      => 'LINK_ROTC',
        self::RACDA     => 'LINK_RACDA',
    ];

    protected $fillable = [
        'ID_EQUIPO', 'TIPO', 'PLACA', 'SERIAL', 'DRIVE_ID',
        'LEIDO', 'DIFERENCIAS', 'ESTADO', 'MOTIVO', 'CARACTERES', 'INTENTOS', 'A_MANO', 'APLICADO_POR', 'APLICADO_EN',
    ];

    protected $casts = [
        'LEIDO'       => 'array',
        'DIFERENCIAS' => 'array',
        'A_MANO'      => 'boolean',
        'APLICADO_EN' => 'datetime',
    ];

    /** Lo que mira una persona: no se pudo leer, no hay archivo, fallo, o no hay boton que lo arregle. */
    public function scopeParaRevisar($q)
    {
        return $q->where(fn ($w) => $w->whereIn('ESTADO', self::A_REVISAR)->orWhere('A_MANO', true));
    }

    /** Lo que SI se corrige con el boton: hay diferencias y el documento es de este vehiculo. */
    public function scopeCorregibles($q)
    {
        return $q->where('ESTADO', self::DIFIERE)->where('A_MANO', false);
    }

    /**
     * Documentos que el comando todavia tiene que leer, de un tipo. UNA sola definicion de la
     * cola: la usan el comando (para su lote), el panel (para "faltan por leer") y las pruebas.
     * Quedan fuera los enlaces que no apuntan a un archivo de Drive —no hay nada que leer— y
     * lo ya revisado, salvo lo ilegible o fallido mientras le queden intentos.
     */
    public static function pendientes(string $tipo, string $columna)
    {
        $idEnlace = self::idEnlace($columna);

        return self::conEnlace($columna)
            ->whereNotExists(fn ($s) => $s->from('verificacion_documento_registro as v')
                ->whereColumn('v.ID_EQUIPO', 'd.ID_EQUIPO')
                ->where('v.TIPO', $tipo)
                ->whereRaw("v.DRIVE_ID = $idEnlace")
                ->where(fn ($w) => $w->whereNotIn('v.ESTADO', [self::ILEGIBLE, self::ERROR])
                    ->orWhere('v.INTENTOS', '>=', self::MAX_INTENTOS)));
    }

    /**
     * TODAS las fichas que tienen ese documento cargado en Drive, este leido o no. Es la misma
     * base que pendientes() —de ahi sale— y con las dos se sabe por donde va la revision:
     * "leidos X de Y". Sin esto, "faltan por leer" no dice si falta mucho o poco.
     */
    public static function conEnlace(string $columna)
    {
        return \Illuminate\Support\Facades\DB::table('documentacion as d')
            ->join('equipos as e', 'e.ID_EQUIPO', '=', 'd.ID_EQUIPO')
            ->whereNull('e.deleted_at')
            ->where("d.$columna", 'like', '/storage/google/%')
            ->whereRaw(self::idEnlace($columna) . " <> ''");
    }

    /** El id de Drive dentro del enlace guardado (/storage/google/ID?v=N), en SQL. */
    private static function idEnlace(string $columna): string
    {
        return "SUBSTRING_INDEX(SUBSTRING_INDEX(d.$columna, '/storage/google/', -1), '?', 1)";
    }

    /**
     * ¿Se puede corregir la ficha con lo que dice este documento? Solo si hay diferencias que
     * aplicar y el documento es de ESTE vehiculo: cuando el PDF resulto ser de otra placa, lo
     * que hay que arreglar es el archivo enlazado, no copiar sus datos a esta ficha.
     */
    public function aplicable(): bool
    {
        return $this->ESTADO === self::DIFIERE
            && !empty($this->DIFERENCIAS)
            && !$this->A_MANO;
    }

    /** El documento se leyo a medias: lo que dice es MENOS que lo que tiene la ficha. */
    public function esLecturaParcial(): bool
    {
        return (bool) ($this->LEIDO['lectura_parcial'] ?? false);
    }

    /** El PDF enlazado es de otro vehiculo (lo dicen su placa o su serial). */
    public function esDeOtroVehiculo(): bool
    {
        return (bool) ($this->LEIDO['otra_placa'] ?? false);
    }

    /** No se pudo leer ni la placa ni el serial: no hay forma de saber de quien es el PDF. */
    public function sinConfirmar(): bool
    {
        return (bool) ($this->LEIDO['sin_confirmar'] ?? false);
    }

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }
}
