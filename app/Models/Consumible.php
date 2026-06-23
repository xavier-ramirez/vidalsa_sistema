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
        'RESP_NOMBRE',
        'RESP_CI',
        'CANTIDAD',
        'RAW_ORIGEN',
        // Bloque 2 — del formulario
        'TIPO_CONSUMIBLE',
        'ESPECIFICACION',   // Aceites: viscosidad (15W-40, SAE90). Caucho: medida (11R22.5)
        'UNIDAD',
        'ID_FRENTE',
        // Bloque 3 — resueltos después
        'ID_EQUIPO',
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
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function frente()
    {
        return $this->belongsTo(FrenteTrabajo::class, 'ID_FRENTE', 'ID_FRENTE');
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
            'REFRIGERANTE' => 'Refrigerante',
            'OTRO'         => 'Otro',
        ];
    }

    public function getTipoLabelAttribute(): string
    {
        return self::tiposLabel()[$this->TIPO_CONSUMIBLE] ?? $this->TIPO_CONSUMIBLE;
    }
}
