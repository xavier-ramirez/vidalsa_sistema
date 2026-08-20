<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Movilizacion extends Model
{
    /**
     * Snapshot offline: este modelo forma parte del dominio "equipos", asi que al
     * escribirlo hay que marcar esa version como obsoleta para que los clientes
     * que SI cachean ese dominio se traigan el cambio. Los que no lo cachean ni
     * se enteran, que es justo el objetivo de separar las versiones.
     */
    protected static function booted(): void
    {
        $marcar = static fn () => \App\Support\OfflineVersion::invalidar('equipos');
        static::saved($marcar);
        static::deleted($marcar);
    }

    use HasFactory;

    protected $table = 'movilizacion_historial';
    protected $primaryKey = 'ID_MOVILIZACION';

    protected $fillable = [
        'CODIGO_CONTROL',
        'ID_EQUIPO',
        'ID_AUXILIAR',             // Movilizacion de un EquipoAuxiliar (XOR con ID_EQUIPO)
        'ID_FRENTE_ORIGEN',
        'ID_FRENTE_DESTINO',
        // Nombre del frente CONGELADO al momento del movimiento (ver migración
        // add_frente_name_snapshot_to_movilizacion_historial). Sin esto, renombrar un
        // frente reescribía en silencio el nombre mostrado en TODO el historial pasado.
        'NOMBRE_FRENTE_ORIGEN_SNAPSHOT',
        'NOMBRE_FRENTE_DESTINO_SNAPSHOT',
        'DETALLE_UBICACION',       // Patio/Subdivisión específica de recepción
        'FECHA_DESPACHO',
        'TIPO_MOVIMIENTO',         // DESPACHO, RECEPCION_DIRECTA
        'USUARIO_REGISTRO',
        'client_uuid',             // Idempotencia offline (Fase 2): UUID del lote creado sin internet
    ];

    /**
     * Nombre del frente de origen tal como estaba EN EL MOMENTO del movimiento.
     * Fuente única para pintar el historial — usa el snapshot congelado; si es una
     * fila vieja creada antes de esta columna (snapshot null), cae a la relación en
     * vivo (mejor un nombre posiblemente desactualizado que nada).
     */
    public function getNombreOrigenAttribute(): ?string
    {
        return $this->NOMBRE_FRENTE_ORIGEN_SNAPSHOT ?: optional($this->frenteOrigen)->NOMBRE_FRENTE;
    }

    /** Espejo de getNombreOrigenAttribute() para el frente de destino. */
    public function getNombreDestinoAttribute(): ?string
    {
        return $this->NOMBRE_FRENTE_DESTINO_SNAPSHOT ?: optional($this->frenteDestino)->NOMBRE_FRENTE;
    }

    /**
     * CODIGO_CONTROL con formato (MV-0000X), o el rotulo del caso sin codigo.
     *
     * "R.D." es SOLO para las recepciones directas. Antes se devolvia para CUALQUIER
     * fila sin CODIGO_CONTROL, y esa equivalencia "sin codigo = recepcion directa" dejo
     * de ser cierta al llegar el tipo ACT. (movimiento registrado sin generar acta, que
     * tampoco lleva codigo). Hoy en la tabla hay 783 filas ACT. frente a 3 recepciones
     * directas: el rotulo estaba mal en 783 sitios.
     *
     * Devuelve null cuando no hay codigo NI es recepcion directa. Que pintar en ese
     * hueco lo decide cada pantalla: el listado de /admin/movilizaciones no muestra
     * nada, el historial offline pone "--" y el modal de detalles omite el renglon.
     *
     * OJO: quien lo consuma debe traer TIPO_MOVIMIENTO en su select. Si no, esta
     * comparacion ve null y ni las recepciones directas de verdad salen rotuladas.
     */
    public function getFormattedCodigoControlAttribute()
    {
        if ($this->CODIGO_CONTROL === null) {
            return $this->TIPO_MOVIMIENTO === 'RECEPCION_DIRECTA' ? 'R.D.' : null;
        }
        $code = preg_replace('/[^0-9]/', '', $this->CODIGO_CONTROL);
        if (empty($code)) return $this->CODIGO_CONTROL;
        return 'MV-' . str_pad($code, 5, '0', STR_PAD_LEFT);
    }

    protected $casts = [
        'FECHA_DESPACHO' => 'datetime',
    ];

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO');
    }

    public function auxiliar()
    {
        return $this->belongsTo(EquipoAuxiliar::class, 'ID_AUXILIAR', 'ID_AUXILIAR');
    }

    public function frenteOrigen()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_ORIGEN', 'ID_FRENTE');
    }

    public function frenteDestino()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE_DESTINO', 'ID_FRENTE');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'USUARIO_REGISTRO', 'CORREO_ELECTRONICO');
    }
}
