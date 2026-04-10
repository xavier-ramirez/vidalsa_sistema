<?php

namespace App\Observers;

use App\Models\RegistroFalla;
use App\Models\HistorialEstadoEquipo;
use Illuminate\Support\Facades\Cache;

class RegistroFallaObserver
{
    public $afterCommit = true;

    public function created(RegistroFalla $falla): void
    {
        $equipo = $falla->equipo;

        if (!$equipo) return;

        $estadoAnterior = $equipo->ESTADO_OPERATIVO;

        // Marcar equipo como INOPERATIVO
        if ($estadoAnterior !== 'INOPERATIVO') {
            $equipo->update(['ESTADO_OPERATIVO' => 'INOPERATIVO']);

            HistorialEstadoEquipo::create([
                'ID_EQUIPO' => $falla->ID_EQUIPO,
                'ESTADO_ANTERIOR' => $estadoAnterior,
                'ESTADO_NUEVO' => 'INOPERATIVO',
                'ID_USUARIO' => $falla->ID_USUARIO_REGISTRA,
                'ID_FALLA' => $falla->ID_FALLA,
                'MOTIVO' => 'Falla registrada: ' . mb_substr($falla->DESCRIPCION_FALLA, 0, 200),
            ]);
        }

        $this->refreshCache();
    }

    public function updated(RegistroFalla $falla): void
    {
        // Solo actuar cuando la falla se marca como RESUELTA
        if (!$falla->wasChanged('ESTADO_FALLA') || $falla->ESTADO_FALLA !== 'RESUELTA') {
            return;
        }

        $equipo = $falla->equipo;
        if (!$equipo) return;

        // Verificar si el equipo tiene otras fallas abiertas
        $fallasAbiertas = RegistroFalla::where('ID_EQUIPO', $falla->ID_EQUIPO)
            ->where('ID_FALLA', '!=', $falla->ID_FALLA)
            ->whereIn('ESTADO_FALLA', ['ABIERTA', 'EN_PROCESO'])
            ->exists();

        // Solo restaurar OPERATIVO si no hay más fallas abiertas
        if (!$fallasAbiertas && $equipo->ESTADO_OPERATIVO === 'INOPERATIVO') {
            $equipo->update(['ESTADO_OPERATIVO' => 'OPERATIVO']);

            HistorialEstadoEquipo::create([
                'ID_EQUIPO' => $falla->ID_EQUIPO,
                'ESTADO_ANTERIOR' => 'INOPERATIVO',
                'ESTADO_NUEVO' => 'OPERATIVO',
                'ID_USUARIO' => $falla->ID_USUARIO_REGISTRA,
                'ID_FALLA' => $falla->ID_FALLA,
                'MOTIVO' => 'Falla resuelta: ' . mb_substr($falla->DESCRIPCION_RESOLUCION ?? '', 0, 200),
            ]);
        }

        $this->refreshCache();
    }

    private function refreshCache(): void
    {
        Cache::forget('dashboard_total_alerts');
        Cache::forget('dashboard_expired_list_v3');
    }
}
