<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\Falla;
use App\Models\FrenteTrabajo;
use App\Models\Movilizacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Modo OFFLINE Fase 2 — Sincronización del OUTBOX de la PWA.
 * ---------------------------------------------------------------------------
 * El usuario registra acciones del módulo equipos SIN internet (movilizar y
 * cambiar estado); cada una se encola en IndexedDB (cliente) y se sube AQUÍ al
 * volver la conexión. (La recepción directa NO se soporta offline: su entrada es
 * el modal del dashboard, que no es un módulo offline.)
 *
 * Esto NO es el flujo online (MovilizacionController::bulkStore /
 * EquipoController::changeStatus): añade IDEMPOTENCIA (client_uuid del lote)
 * y política LAST-WRITE-WINS — el servidor aplica cada operación sin comparar
 * versiones; el último cambio en llegar gana. Lo que no se puede aplicar
 * (equipo inexistente, frente borrado, falla abierta) se devuelve como 'error'
 * y el cliente reintenta. Cada operación va en su PROPIA transacción.
 *
 * Comparte únicamente el primitivo atómico MovilizacionController::generateNextCodigoControl().
 * Es ruta WEB (sesión + CSRF), no la API Sanctum del APK.
 */
class OfflineSyncController extends Controller
{
    /** Estados operativos válidos (igual que EquipoController::changeStatus). */
    private const ESTADOS_VALIDOS = ['OPERATIVO', 'INOPERATIVO', 'EN MANTENIMIENTO', 'DESINCORPORADO'];

    public function sync(Request $request)
    {
        $ops = $request->input('operations');
        if (!is_array($ops)) {
            return response()->json(['message' => 'Formato inválido: falta "operations".'], 422);
        }

        $user    = $request->user();
        $correo  = $user->CORREO_ELECTRONICO ?? 'SISTEMA';
        $results = [];

        foreach ($ops as $op) {
            $uuid   = is_array($op) ? ($op['client_uuid'] ?? null) : null;
            $action = is_array($op) ? ($op['action'] ?? null) : null;
            $payload = is_array($op) ? ($op['payload'] ?? []) : [];

            if (!$uuid || !$action) {
                $results[] = ['client_uuid' => $uuid, 'status' => 'error', 'reason' => 'datos_incompletos'];
                continue;
            }

            try {
                switch ($action) {
                    case 'movilizar':
                        $results[] = $this->opMovilizar($uuid, $payload, $user, $correo);
                        break;
                    case 'estado':
                        $results[] = $this->opEstado($uuid, $payload, $user);
                        break;
                    default:
                        $results[] = ['client_uuid' => $uuid, 'status' => 'error', 'reason' => 'accion_desconocida'];
                }
            } catch (\Throwable $e) {
                Log::error('offline-sync op falló: ' . $e->getMessage(), ['uuid' => $uuid, 'action' => $action]);
                $results[] = ['client_uuid' => $uuid, 'status' => 'error', 'reason' => 'error_servidor'];
            }
        }

        return response()->json(['results' => $results]);
    }

    // ── Helpers de resultado ─────────────────────────────────────────────────
    private function ok(string $uuid, $ref = null): array
    {
        return ['client_uuid' => $uuid, 'status' => 'synced', 'server_ref' => $ref];
    }
    private function error(string $uuid, string $reason): array
    {
        return ['client_uuid' => $uuid, 'status' => 'error', 'reason' => $reason];
    }

    // ── MOVILIZAR (bulk a un frente EXISTENTE) ───────────────────────────────
    // payload: { ids:[int], id_frente_destino:int }
    // Last-write-wins: se aplica sin importar dónde esté el equipo ahora.
    private function opMovilizar(string $uuid, array $payload, $user, string $correo): array
    {
        if (!$user->can('equipos.assign')) {
            return $this->error($uuid, 'sin_permiso');
        }

        $v = Validator::make($payload, [
            'ids'               => 'required|array|min:1',
            'ids.*'             => 'integer',
            'id_frente_destino' => 'required|integer',
        ]);
        if ($v->fails()) {
            return $this->error($uuid, 'payload_invalido');
        }

        $ids    = array_map('intval', $payload['ids']);
        $destId = (int) $payload['id_frente_destino'];

        return DB::transaction(function () use ($uuid, $ids, $destId, $correo) {
            $existentes = Movilizacion::where('client_uuid', $uuid)->pluck('ID_MOVILIZACION');
            if ($existentes->isNotEmpty()) {
                return $this->ok($uuid, ['movilizacion_ids' => $existentes->all(), 'duplicado' => true]);
            }

            $frente = FrenteTrabajo::find($destId);
            if (!$frente) {
                return $this->error($uuid, 'frente_destino_inexistente');
            }

            $equipos = Equipo::whereIn('ID_EQUIPO', $ids)
                ->lockForUpdate()
                ->get(['ID_EQUIPO', 'ID_FRENTE_ACTUAL'])
                ->keyBy('ID_EQUIPO');

            $aMovilizar = [];
            foreach ($ids as $id) {
                $eq = $equipos->get($id);
                if (!$eq) {
                    return $this->error($uuid, 'equipo_no_encontrado');
                }
                if ((int) ($eq->ID_FRENTE_ACTUAL ?? 0) === (int) $frente->ID_FRENTE) {
                    continue;
                }
                $aMovilizar[] = $eq;
            }

            if (empty($aMovilizar)) {
                return $this->ok($uuid, ['movilizacion_ids' => [], 'omitidos' => count($ids)]);
            }

            // Se genera CODIGO_CONTROL (acta disponible al sincronizar, TIPO DESPACHO).
            $codigo = MovilizacionController::generateNextCodigoControl();
            $now    = now();
            $movIds = [];
            foreach ($aMovilizar as $eq) {
                $mov = Movilizacion::create([
                    'CODIGO_CONTROL'    => $codigo,
                    'ID_EQUIPO'         => $eq->ID_EQUIPO,
                    'ID_FRENTE_ORIGEN'  => $eq->ID_FRENTE_ACTUAL ?? 1,
                    'ID_FRENTE_DESTINO' => $frente->ID_FRENTE,
                    'FECHA_DESPACHO'    => $now,
                    'TIPO_MOVIMIENTO'   => 'DESPACHO',
                    'USUARIO_REGISTRO'  => $correo,
                    'client_uuid'       => $uuid,
                ]);
                $movIds[] = $mov->ID_MOVILIZACION;
            }

            Equipo::whereIn('ID_EQUIPO', collect($aMovilizar)->pluck('ID_EQUIPO'))->update([
                'ID_FRENTE_ACTUAL'         => $frente->ID_FRENTE,
                // Despacho → pendiente de confirmar en el frente destino (se tilda al llegar).
                'CONFIRMADO_EN_SITIO'      => 0,
                'DETALLE_UBICACION_ACTUAL' => null,
            ]);
            // Mass-update sin eventos Eloquent → bump explícito del dashboard.
            \App\Http\Controllers\DashboardController::bumpDataVersion();

            return $this->ok($uuid, ['movilizacion_ids' => $movIds, 'codigo_control' => $codigo]);
        });
    }

    // ── CAMBIO DE ESTADO OPERATIVO ───────────────────────────────────────────
    // payload: { id_equipo:int, status:str }
    // Last-write-wins: aplica el estado sin importar el valor actual.
    private function opEstado(string $uuid, array $payload, $user): array
    {
        if (!$user->can('equipos.edit')) {
            return $this->error($uuid, 'sin_permiso');
        }

        $v = Validator::make($payload, [
            'id_equipo' => 'required|integer',
            'status'    => 'required|in:' . implode(',', self::ESTADOS_VALIDOS),
        ]);
        if ($v->fails()) {
            return $this->error($uuid, 'payload_invalido');
        }

        return DB::transaction(function () use ($uuid, $payload) {
            $equipo = Equipo::lockForUpdate()->find((int) $payload['id_equipo']);
            if (!$equipo) {
                return $this->error($uuid, 'equipo_no_encontrado');
            }

            $falla = Falla::where('ACTIVO_TIPO', 'equipo')
                ->where('ACTIVO_ID', $equipo->ID_EQUIPO)
                ->where('ESTADO_REPORTE', 'abierto')
                ->latest('FECHA_EMISION')
                ->first();
            if ($falla) {
                return $this->error($uuid, 'falla_abierta');
            }

            $equipo->ESTADO_OPERATIVO = $payload['status'];
            $equipo->save();

            return $this->ok($uuid, ['estado' => $equipo->ESTADO_OPERATIVO]);
        });
    }

}
