<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consumible extends Model
{
    protected $table      = 'consumibles';
    protected $primaryKey = 'ID_CONSUMIBLE';

    protected $fillable = [
        // Bloque 1 — del Excel
        'FECHA',
        'IDENTIFICADOR',
        'DESC_EQUIPO',
        'RESP_NOMBRE',
        'RESP_CI',
        'CANTIDAD',
        // Bloque 2 — del formulario
        'TIPO_CONSUMIBLE',
        'ESPECIFICACION',   // Aceites: viscosidad (15W-40, SAE90). Caucho: medida (11R22.5)
        'UNIDAD',
        'ID_FRENTE',
        // Bloque 3 — resueltos después
        'ID_EQUIPO',
        'ID_AUXILIAR',      // si el que surtió es un auxiliar (máquina de soldar, planta…)
        'ID_SUMINISTRO',
        'ESTADO_EQUIPO',
        'NOTAS',
    ];

    protected $casts = [
        'FECHA'    => 'date',
        'CANTIDAD' => 'decimal:2',
    ];

    // ── Relaciones ───────────────────────────────────────────────

    public function equipo()
    {
        // Con borrados: el consumo ya pasó con ese equipo; la lista, el export y el buscador lo muestran igual.
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO')->withTrashed();
    }

    public function auxiliar()
    {
        return $this->belongsTo(EquipoAuxiliar::class, 'ID_AUXILIAR', 'ID_AUXILIAR')->withTrashed();
    }

    public function frente()
    {
        // withTrashed: es historial; un frente eliminado (borrado lógico) sigue dando su nombre.
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE', 'ID_FRENTE')->withTrashed();
    }

    public function suministro()
    {
        return $this->belongsTo(SuministroOrigen::class, 'ID_SUMINISTRO', 'ID_SUMINISTRO');
    }

    // ── Labels útiles para vistas ────────────────────────────────

    public static function tiposLabel(): array
    {
        return [
            'GASOIL'       => 'Gasoil',
            'GASOLINA'     => 'Gasolina',
            'ACEITE'       => 'Aceite',
            'CAUCHO'       => 'Caucho',
        ];
    }

    public function getTipoLabelAttribute(): string
    {
        return self::tiposLabel()[$this->TIPO_CONSUMIBLE] ?? $this->TIPO_CONSUMIBLE;
    }
}
