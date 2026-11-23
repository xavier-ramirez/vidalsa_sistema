<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una carga de Excel de gasoil: mientras es BORRADOR guarda las filas leídas (FILAS) para
 * revisarlas; al APLICAR se vacía FILAS y quedan los consumos con su ID_IMPORTACION, que
 * permiten DESHACER la carga entera.
 */
class ConsumibleImportacion extends Model
{
    public const BORRADOR = 'BORRADOR';
    public const APLICADA = 'APLICADA';
    public const DESHECHA = 'DESHECHA';

    protected $table = 'consumibles_importaciones';
    protected $primaryKey = 'ID_IMPORTACION';

    protected $fillable = ['ESTADO', 'ARCHIVOS', 'FILAS', 'INSERTADOS', 'LITROS', 'ID_USUARIO', 'APLICADA_EN'];

    protected $casts = [
        'ARCHIVOS'    => 'array',
        'LITROS'      => 'decimal:2',
        'APLICADA_EN' => 'datetime',
    ];

    /** No viaja en listados: puede pesar varios MB. */
    protected $hidden = ['FILAS'];

    /** Las filas se guardan comprimidas (≈8 veces menos): un par de Excel acumulados pasan de 4 MB. */
    public function filas(): array
    {
        if ($this->FILAS === null || $this->FILAS === '') {
            return [];
        }
        $json = gzdecode(base64_decode($this->FILAS));

        return $json === false ? [] : json_decode($json, true);
    }

    public function guardarFilas(?array $filas): void
    {
        $this->FILAS = $filas === null ? null : base64_encode(gzencode(json_encode($filas, JSON_UNESCAPED_UNICODE), 6));
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO', 'ID_USUARIO');
    }
}
