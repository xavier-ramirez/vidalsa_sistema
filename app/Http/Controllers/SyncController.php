<?php

namespace App\Http\Controllers;

use App\Models\Equipo;
use App\Models\Documentacion;
use App\Models\FrenteTrabajo;
use App\Models\CaracteristicaModelo;
use App\Models\Movilizacion;
use App\Models\Responsable;
use App\Models\TipoEquipo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sincronización PWA <-> Laravel.
 *
 * Endpoints (todos en sesión web autenticada — NO Sanctum):
 *  POST /sync/login-local-init  → tras login web, devuelve hash + perfil
 *                                  para que la PWA pueda hacer login offline.
 *  GET  /sync/dump              → snapshot completo del frente del usuario
 *                                  (carga inicial al instalar la PWA).
 *  GET  /sync/diff?since=ISO    → cambios desde una fecha (sync incremental).
 *  POST /sync/upload-outbox     → recibe acciones pendientes hechas offline
 *                                  y las aplica en orden.
 */
class SyncController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * POST /sync/login-local-init
     * Devuelve los datos que la PWA debe guardar en SQLite local para
     * permitir login OFFLINE futuro (correo + comparación bcrypt).
     * Solo se devuelve PASSWORD_HASH_FOR_OFFLINE — un hash bcrypt limitado
     * al uso local: la app lo usa con bcryptjs.compare(password, hash) en
     * el dispositivo, sin enviarlo de vuelta al servidor.
     */
    public function loginLocalInit(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'No autenticado.'], 401);

        $frentesIds = $user->getFrentesIds();
        $frentes = FrenteTrabajo::whereIn('ID_FRENTE', $frentesIds)
            ->select('ID_FRENTE','NOMBRE_FRENTE','TIPO_FRENTE','UBICACION')
            ->get();
        $permisos = $user->PERMISOS ?? [];
        $hasSuper = in_array('super.admin', $permisos);

        return response()->json([
            'user' => [
                'id'        => $user->ID_USUARIO,
                'correo'    => $user->CORREO_ELECTRONICO,
                'nombre'    => $user->NOMBRE_COMPLETO ?? $user->NOMBRE_USUARIO ?? '',
                'nivel'     => $user->NIVEL_ACCESO,
                'permisos'  => $permisos,
                'frentes'   => $frentes,
                // Hash bcrypt del usuario — NUNCA se envía al servidor de
                // vuelta. La PWA lo guarda en SQLite local solo para
                // bcryptjs.compare(password, hash) en login offline.
                'password_hash_for_offline' => $user->PASSWORD_HASH,
                'puede_editar'    => $hasSuper || in_array('user.edit', $permisos),
                'puede_movilizar' => $hasSuper || in_array('equipos.create', $permisos),
                'puede_estado'    => $hasSuper || in_array('equipos.edit', $permisos) || in_array('user.edit', $permisos),
                'es_super_admin'  => $hasSuper,
            ],
            'server_time' => now()->toIso8601String(),
        ]);
    }

    /**
     * GET /sync/dump
     * Snapshot completo de todo lo que la PWA necesita para trabajar
     * offline. Filtra por frente del usuario (LOCAL) o trae todo (GLOBAL).
     */
    public function dump(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['error' => 'No autenticado.'], 401);

        $frentesIds = $user->getFrentesIds();
        $isGlobal = (int) $user->NIVEL_ACCESO === 1;

        // Equipos del frente (o todos si es GLOBAL)
        $equiposQ = Equipo::with(['documentacion','tipo','frenteActual','especificaciones'])
            ->excludeEspecial();
        if (!$isGlobal && !empty($frentesIds)) {
            $equiposQ->whereIn('ID_FRENTE_ACTUAL', $frentesIds);
        }
        $equipos = $equiposQ->get()->map(fn($e) => $this->serializeEquipo($e));

        // IDs únicos de equipos para filtrar dependencias
        $idsEquipos = $equipos->pluck('ID_EQUIPO')->all();

        $documentacion = Documentacion::whereIn('ID_EQUIPO', $idsEquipos)->get()
            ->map(fn($d) => $d->toArray());

        // Frentes (todos los activos: el usuario puede ver destinos en movilización)
        $frentes = FrenteTrabajo::orderBy('NOMBRE_FRENTE')
            ->get(['ID_FRENTE','NOMBRE_FRENTE','TIPO_FRENTE','UBICACION','ESTATUS_FRENTE']);

        // Tipos de equipo (catálogo enum + custom)
        $tipos = TipoEquipo::orderBy('nombre')->get(['id','nombre','ROL_ANCLAJE']);

        // Catálogos de modelos (para el form de registro)
        $catalogos = CaracteristicaModelo::orderBy('MODELO')->get();

        // Movilizaciones de los últimos 90 días que afecten a estos equipos
        $movs = Movilizacion::with(['frenteOrigen','frenteDestino'])
            ->whereIn('ID_EQUIPO', $idsEquipos)
            ->where('FECHA_DESPACHO', '>=', now()->subDays(90))
            ->orderByDesc('FECHA_DESPACHO')
            ->get()
            ->map(function ($m) {
                return [
                    'ID_MOVILIZACION'   => $m->ID_MOVILIZACION,
                    'CODIGO_CONTROL'    => $m->formatted_codigo_control,
                    'ID_EQUIPO'         => $m->ID_EQUIPO,
                    'ID_FRENTE_ORIGEN'  => $m->ID_FRENTE_ORIGEN,
                    'NOMBRE_ORIGEN'    => optional($m->frenteOrigen)->NOMBRE_FRENTE,
                    'ID_FRENTE_DESTINO' => $m->ID_FRENTE_DESTINO,
                    'NOMBRE_DESTINO'   => optional($m->frenteDestino)->NOMBRE_FRENTE,
                    'FECHA_DESPACHO'    => optional($m->FECHA_DESPACHO)->toIso8601String(),
                    'TIPO_MOVIMIENTO'   => $m->TIPO_MOVIMIENTO,
                    'DETALLE_UBICACION' => $m->DETALLE_UBICACION,
                    'USUARIO_REGISTRO'  => $m->USUARIO_REGISTRO,
                ];
            });

        // Responsables actuales (últimos 2 por equipo)
        $responsables = Responsable::whereIn('ID_EQUIPO', $idsEquipos)
            ->orderByDesc('FECHA_ASIGNACION')
            ->get()
            ->map(function ($r) {
                return [
                    'ID_ASIGNACION'      => $r->ID_ASIGNACION,
                    'ID_EQUIPO'          => $r->ID_EQUIPO,
                    'PERSONA_ASIGNADA'   => $r->PERSONA_ASIGNADA,
                    'CEDULA_RESPONSABLE' => $r->CEDULA_RESPONSABLE,
                    'FECHA_ASIGNACION'   => optional($r->FECHA_ASIGNACION)->toIso8601String(),
                ];
            });

        return response()->json([
            'meta' => [
                'server_time' => now()->toIso8601String(),
                'user_id'     => $user->ID_USUARIO,
                'frentes_ids' => $frentesIds,
                'is_global'   => $isGlobal,
                'counts'      => [
                    'equipos'        => $equipos->count(),
                    'documentacion'  => $documentacion->count(),
                    'frentes'        => $frentes->count(),
                    'tipos'          => $tipos->count(),
                    'catalogos'      => $catalogos->count(),
                    'movilizaciones' => $movs->count(),
                    'responsables'   => $responsables->count(),
                ],
            ],
            'equipos'        => $equipos,
            'documentacion'  => $documentacion,
            'frentes'        => $frentes,
            'tipos'          => $tipos,
            'catalogos'      => $catalogos,
            'movilizaciones' => $movs,
            'responsables'   => $responsables,
        ]);
    }

    /** Serialización plana de un equipo (espejo de tabla). */
    private function serializeEquipo($e): array
    {
        return [
            'ID_EQUIPO'                => $e->ID_EQUIPO,
            'CODIGO_PATIO'             => $e->CODIGO_PATIO,
            'TIPO_EQUIPO'              => $e->TIPO_EQUIPO,
            'CATEGORIA_FLOTA'          => $e->CATEGORIA_FLOTA,
            'MARCA'                    => $e->MARCA,
            'MODELO'                   => $e->MODELO,
            'ANIO'                     => $e->ANIO,
            'SERIAL_CHASIS'            => $e->SERIAL_CHASIS,
            'SERIAL_DE_MOTOR'          => $e->SERIAL_DE_MOTOR,
            'NUMERO_ETIQUETA'          => $e->NUMERO_ETIQUETA,
            'ESTADO_OPERATIVO'         => $e->ESTADO_OPERATIVO,
            'ID_FRENTE_ACTUAL'         => $e->ID_FRENTE_ACTUAL,
            'DETALLE_UBICACION_ACTUAL' => $e->DETALLE_UBICACION_ACTUAL,
            'ID_ESPEC'                 => $e->ID_ESPEC,
            'LINK_GPS'                 => $e->LINK_GPS,
            'ID_ANCLAJE'               => $e->ID_ANCLAJE,
            'FOTO_EQUIPO'              => $e->FOTO_EQUIPO,
            'id_tipo_equipo'           => $e->id_tipo_equipo,
            'tipo_nombre'              => optional($e->tipo)->nombre,
            'frente_nombre'            => optional($e->frenteActual)->NOMBRE_FRENTE,
            'foto_referencial'         => optional($e->especificaciones)->FOTO_REFERENCIAL,
            'created_at'               => optional($e->created_at)->toIso8601String(),
            'updated_at'               => optional($e->updated_at)->toIso8601String(),
        ];
    }
}
