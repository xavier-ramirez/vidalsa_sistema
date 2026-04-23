<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EquipoAuditLog extends Model
{
    protected $table      = 'equipo_audit_log';
    protected $primaryKey = 'ID_LOG';
    public    $timestamps = false; // solo created_at, sin updated_at

    protected $fillable = [
        'ID_EQUIPO',
        'ID_USUARIO',
        'ACCION',
        'CAMBIOS',
        'created_at',
    ];

    protected $casts = [
        'CAMBIOS'    => 'array',
        'created_at' => 'datetime',
    ];

    public function equipo()
    {
        return $this->belongsTo(Equipo::class, 'ID_EQUIPO', 'ID_EQUIPO');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO', 'ID_USUARIO');
    }

    /**
     * Helper: registra un evento de auditoria de forma segura. Silencioso ante
     * errores (ej. tabla inexistente en migracion intermedia) — nunca rompe el
     * flujo principal de la aplicacion.
     */
    public static function registrar(int $equipoId, string $accion, array $cambios = []): void
    {
        try {
            static::create([
                'ID_EQUIPO'  => $equipoId,
                'ID_USUARIO' => auth()->id(),
                'ACCION'     => $accion,
                'CAMBIOS'    => !empty($cambios) ? json_encode($cambios, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('EquipoAuditLog registrar() fallo: ' . $e->getMessage());
        }
    }
}
