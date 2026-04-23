<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogoAuditLog extends Model
{
    protected $table      = 'catalogo_audit_log';
    protected $primaryKey = 'ID_LOG';
    public    $timestamps = false;

    protected $fillable = [
        'ID_ESPEC',
        'ID_USUARIO',
        'ACCION',
        'MODELO',
        'ANIO_ESPEC',
        'CAMBIOS',
        'created_at',
    ];

    protected $casts = [
        'CAMBIOS'    => 'array',
        'created_at' => 'datetime',
    ];

    public function modelo()
    {
        return $this->belongsTo(CaracteristicaModelo::class, 'ID_ESPEC', 'ID_ESPEC');
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'ID_USUARIO', 'ID_USUARIO');
    }

    /**
     * Registra un evento de auditoria del catalogo. Silencioso ante errores:
     * nunca rompe el flujo principal (ej. tabla inexistente en migracion).
     */
    public static function registrar(?int $idEspec, string $accion, ?string $modelo, ?int $anio, array $cambios = []): void
    {
        try {
            static::create([
                'ID_ESPEC'   => $idEspec,
                'ID_USUARIO' => auth()->id(),
                'ACCION'     => $accion,
                'MODELO'     => $modelo,
                'ANIO_ESPEC' => $anio,
                'CAMBIOS'    => !empty($cambios) ? json_encode($cambios, JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('CatalogoAuditLog registrar() fallo: ' . $e->getMessage());
        }
    }
}
