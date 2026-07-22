<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BloqueoIp extends Model
{
    use HasFactory;

    protected $table = 'bloqueo_ip';
    protected $primaryKey = 'ID_BLOQUEO';

    protected $fillable = [
        'DIRECCION_IP',
        'CANTIDAD_INTENTOS',
        'ULTIMO_INTENTO',
        'BLOQUEO_PERMANENTE',
    ];

    protected $casts = [
        'ULTIMO_INTENTO' => 'datetime',
        'BLOQUEO_PERMANENTE' => 'boolean',
    ];

    /**
     * Umbral de intentos fallidos a partir del cual una IP se considera
     * EFECTIVAMENTE bloqueada (no solo en seguimiento). Fuente única: si esta
     * regla cambia, se cambia aquí y ambos módulos (Auditoría y Usuarios) la heredan.
     */
    public const UMBRAL_BLOQUEO = 10;

    /**
     * IPs efectivamente bloqueadas (>= UMBRAL_BLOQUEO intentos), más recientes primero.
     * Reutilizado por HistorialDocumentosController y UserController para el panel
     * lateral "IPs Bloqueadas" — evita duplicar el criterio/umbral en cada controlador.
     */
    public function scopeBloqueadas($query)
    {
        return $query->where('CANTIDAD_INTENTOS', '>=', self::UMBRAL_BLOQUEO)
            ->orderBy('ULTIMO_INTENTO', 'desc');
    }
}
