<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Documentacion;
use App\Models\BloqueoIp;
use Carbon\Carbon;

class HistorialDocumentosController extends Controller
{
    /**
     * Construye el identificador de equipo para mostrar en la tabla del historial,
     * consistente entre los 3 loops (docs, equipos creados, audit logs). Prefiere
     * Placa → Serial Chasis → ID. Acepta tanto Equipo como (Equipo + placa string).
     */
    private function buildEquipoId($equipo, ?string $placa = null): string
    {
        if (!$equipo) return '#';
        if (!empty($placa)) {
            return 'Placa: ' . $placa;
        }
        // Fallback a la relacion documentacion si esta cargada.
        if (!empty(optional($equipo->documentacion ?? null)->PLACA)) {
            return 'Placa: ' . $equipo->documentacion->PLACA;
        }
        if (!empty($equipo->SERIAL_CHASIS)) {
            return 'Serial Chasis: ' . $equipo->SERIAL_CHASIS;
        }
        return 'ID: ' . $equipo->ID_EQUIPO;
    }

    /**
     * Mapeo tabla-driven de los 6 tipos de documento que la tabla `documentacion`
     * registra via flags FECHA_SUBIDA/SUBIDO_POR. Usado para evitar 6 bloques
     * foreach copiados-pegados en index(). Cada entrada describe la columna de
     * fecha, autor, link, la relacion del usuario y el doc_key/label legacy.
     */
    private const DOC_FIELD_MAP = [
        'propiedad' => [
            'fecha_col' => 'PROPIEDAD_FECHA_SUBIDA',
            'autor_col' => 'PROPIEDAD_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_PROPIEDAD',
            'user_rel'  => 'usuarioPropiedad',
            'label'     => 'Título de Propiedad',
        ],
        'poliza' => [
            'fecha_col' => 'POLIZA_FECHA_SUBIDA',
            'autor_col' => 'POLIZA_SUBIDO_POR',
            'link_col'  => 'LINK_POLIZA_SEGURO',
            'user_rel'  => 'usuarioPoliza',
            'label'     => 'Póliza de Seguro',
        ],
        'rotc' => [
            'fecha_col' => 'ROTC_FECHA_SUBIDA',
            'autor_col' => 'ROTC_SUBIDO_POR',
            'link_col'  => 'LINK_ROTC',
            'user_rel'  => 'usuarioRotc',
            'label'     => 'ROTC',
        ],
        'racda' => [
            'fecha_col' => 'RACDA_FECHA_SUBIDA',
            'autor_col' => 'RACDA_SUBIDO_POR',
            'link_col'  => 'LINK_RACDA',
            'user_rel'  => 'usuarioRacda',
            'label'     => 'RACDA',
        ],
        'adicional' => [
            'fecha_col' => 'ADICIONAL_FECHA_SUBIDA',
            'autor_col' => 'ADICIONAL_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_ADICIONAL',
            'user_rel'  => 'usuarioAdicional',
            'label'     => 'Certificado Asociado',
        ],
        'adicional_2' => [
            'fecha_col' => 'ADICIONAL_2_FECHA_SUBIDA',
            'autor_col' => 'ADICIONAL_2_SUBIDO_POR',
            'link_col'  => 'LINK_DOC_ADICIONAL_2',
            'user_rel'  => 'usuarioAdicional2',
            'label'     => 'Compraventa',
        ],
    ];

    public function index(Request $request)
    {
        // ── Scope LOCAL ─────────────────────────────────────────────────────
        // Usuarios NIVEL_ACCESO_EQUIPOS=2 (local) solo ven el historial de equipos en
        // los frentes que tienen asignados. Sin frentes => ven nada.
        // Los super.admin / global ven todo el historial.
        $user            = auth()->user();
        // null = ve todo (global) | [] = local sin frentes | [ids] (Usuario::frentesVisiblesEquiposIds).
        $frentesVisibles = $user ? $user->frentesVisiblesEquiposIds() : [];
        // Lista negra: frentes a OCULTAR siempre (también a GLOBAL).
        $frentesBloqueados = $user ? $user->getFrentesBloqueadosIds() : [];

        // Pre-filtros que se aplican en SQL (no en memoria) para escalar bien.
        // Si el dataset crece a decenas de miles, esto evita cargar todo a RAM.
        $fechaDesdeSql = $request->filled('fecha_desde')
            ? Carbon::parse($request->fecha_desde)->startOfDay()
            : null;
        $fechaHastaSql = $request->filled('fecha_hasta')
            ? Carbon::parse($request->fecha_hasta)->endOfDay()
            : null;
        $searchEquipoSql = trim((string) $request->input('search_equipo', ''));

        // Helper que aplica el scope LOCAL a un query Eloquent que tiene relacion
        // 'equipo'. Usado en Documentacion y EquipoAuditLog (que SI tienen relacion).
        $applyLocalScopeViaWhereHas = function ($query) use ($frentesVisibles, $frentesBloqueados) {
            // Lista blanca (whitelist LOCAL):
            if ($frentesVisibles !== null) {       // null = global → ve todo (sin whitelist)
                if (empty($frentesVisibles)) {
                    $query->whereRaw('1 = 0'); // local sin frentes => sin resultados
                    return;                    // ya no ve nada, la lista negra es irrelevante
                }
                $query->whereHas('equipo', function ($q) use ($frentesVisibles) {
                    $q->withTrashed()->whereIn('ID_FRENTE_ACTUAL', $frentesVisibles);
                });
            }
            // Lista negra (también para GLOBAL): ocultar historial de equipos en frentes bloqueados.
            if (!empty($frentesBloqueados)) {
                $query->whereHas('equipo', function ($q) use ($frentesBloqueados) {
                    $q->withTrashed()->whereNotIn('ID_FRENTE_ACTUAL', $frentesBloqueados);
                });
            }
        };

        // Helper que aplica el filtro search_equipo (placa/serial/codigo) en SQL.
        $applySearchEquipoViaWhereHas = function ($query) use ($searchEquipoSql) {
            if ($searchEquipoSql === '') return;
            $like = '%' . strtoupper($searchEquipoSql) . '%';
            $query->whereHas('equipo', function ($q) use ($like) {
                $q->withTrashed()->where(function ($w) use ($like) {
                    $w->whereRaw('UPPER(SERIAL_CHASIS) like ?', [$like])
                      ->orWhereRaw('UPPER(CODIGO_PATIO) like ?', [$like])
                      ->orWhereHas('documentacion', function ($qd) use ($like) {
                          $qd->whereRaw('UPPER(PLACA) like ?', [$like]);
                      });
                });
            });
        };

        // 1. Documentacion con upload — pre-filtros + LIMIT.
        $docsQuery = Documentacion::with([
                'equipo' => function ($q) { $q->withTrashed()->with('tipo'); },
                'usuarioPropiedad', 'usuarioPoliza', 'usuarioRotc',
                'usuarioRacda',     'usuarioAdicional', 'usuarioAdicional2',
            ])
            ->where(function ($q) {
                foreach (self::DOC_FIELD_MAP as $cfg) {
                    $q->orWhereNotNull($cfg['fecha_col']);
                }
            });

        $applyLocalScopeViaWhereHas($docsQuery);
        $applySearchEquipoViaWhereHas($docsQuery);

        // Filtro fecha en SQL: si hay rango, exigimos que AL MENOS una de las 6
        // fechas de subida caiga en el rango. Acelera muchisimo en datasets grandes.
        if ($fechaDesdeSql || $fechaHastaSql) {
            $docsQuery->where(function ($q) use ($fechaDesdeSql, $fechaHastaSql) {
                foreach (self::DOC_FIELD_MAP as $cfg) {
                    $q->orWhere(function ($qq) use ($cfg, $fechaDesdeSql, $fechaHastaSql) {
                        $qq->whereNotNull($cfg['fecha_col']);
                        if ($fechaDesdeSql) $qq->where($cfg['fecha_col'], '>=', $fechaDesdeSql);
                        if ($fechaHastaSql) $qq->where($cfg['fecha_col'], '<=', $fechaHastaSql);
                    });
                }
            });
        }

        $docs = $docsQuery->limit(5000)->get();

        // 2. Parse them into a flat array of "upload events".
        // Las 6 entradas de DOC_FIELD_MAP eliminan 6 bloques foreach copiados.
        // Las fechas vienen como Carbon gracias a los casts del modelo Documentacion
        // (PROPIEDAD_FECHA_SUBIDA, etc. = 'datetime'), no requiere Carbon::parse.
        $events = collect();

        foreach ($docs as $doc) {
            $eName = $doc->equipo
                ? ($doc->equipo->tipo->nombre ?? 'Equipo') . ' ' . $doc->equipo->MARCA . ' ' . $doc->equipo->MODELO
                : 'Equipo Eliminado';
            $eId   = $this->buildEquipoId($doc->equipo, $doc->PLACA ?? null);

            foreach (self::DOC_FIELD_MAP as $docKey => $cfg) {
                $fecha = $doc->{$cfg['fecha_col']};
                $autorId = $doc->{$cfg['autor_col']};
                if (!$fecha || !$autorId) continue;

                $autorRel = $doc->{$cfg['user_rel']};
                $autor = $autorRel ? $autorRel->CORREO_ELECTRONICO : $autorId;
                // Nombre del autor → permite filtrar/ubicar por nombre además del correo.
                $autorNombre = $autorRel ? ($autorRel->NOMBRE_COMPLETO ?? '') : '';

                $events->push((object)[
                    'doc_key'      => $docKey,
                    'tipo'         => $cfg['label'],
                    'autor'        => $autor,
                    'autor_nombre' => $autorNombre,
                    'fecha'        => $fecha,
                    'link'         => $doc->{$cfg['link_col']},
                    'equipo_nombre'=> $eName,
                    'equipo_id'    => $eId,
                    'equipo_db_id' => $doc->equipo ? $doc->equipo->ID_EQUIPO : null,
                    'cambios'      => [],
                    // NO es registro propio: el "evento" es una bandera en `documentacion`.
                    // deleteRegistro lo BLOQUEA (borrarlo quitaría el documento real).
                    'del_source'   => 'doc',
                    'del_id'       => null,
                ]);
            }
        }

        // Eventos de "Registro de Vehiculo" (creacion). Eager-load del creador
        // restringido a las columnas necesarias + pre-filtros en SQL + LIMIT.
        $equiposQuery = \App\Models\Equipo::with([
                'tipo:id,nombre',
                'creador:ID_USUARIO,CORREO_ELECTRONICO,NOMBRE_COMPLETO',
                'documentacion:ID_EQUIPO,PLACA',
            ])
            ->whereNotNull('CREADO_POR');

        // Scope en SQL: lista blanca (LOCAL) + lista negra de bloqueados (también GLOBAL).
        \App\Models\Usuario::aplicarScopeIds($equiposQuery, $frentesVisibles, 'ID_FRENTE_ACTUAL');
        \App\Models\Usuario::aplicarBloqueoIds($equiposQuery, $frentesBloqueados, 'ID_FRENTE_ACTUAL');

        // search_equipo en SQL (placa/serial/codigo).
        if ($searchEquipoSql !== '') {
            $like = '%' . strtoupper($searchEquipoSql) . '%';
            $equiposQuery->where(function ($q) use ($like) {
                $q->whereRaw('UPPER(SERIAL_CHASIS) like ?', [$like])
                  ->orWhereRaw('UPPER(CODIGO_PATIO) like ?', [$like])
                  ->orWhereHas('documentacion', function ($qd) use ($like) {
                      $qd->whereRaw('UPPER(PLACA) like ?', [$like]);
                  });
            });
        }

        // Filtro fecha en SQL aplicado a created_at del equipo.
        if ($fechaDesdeSql) $equiposQuery->where('created_at', '>=', $fechaDesdeSql);
        if ($fechaHastaSql) $equiposQuery->where('created_at', '<=', $fechaHastaSql);

        $equiposCreados = $equiposQuery->orderByDesc('created_at')->limit(5000)->get();

        foreach ($equiposCreados as $equipo) {
            $events->push((object)[
                'doc_key'      => 'creacion',
                'tipo'         => 'Registro de Vehículo',
                'autor'        => $equipo->creador ? $equipo->creador->CORREO_ELECTRONICO : 'Usuario Desconocido',
                'autor_nombre' => $equipo->creador ? ($equipo->creador->NOMBRE_COMPLETO ?? '') : '',
                'fecha'        => $equipo->created_at,
                'link'         => null,
                'equipo_nombre'=> ($equipo->tipo->nombre ?? 'Equipo') . ' ' . $equipo->MARCA . ' ' . $equipo->MODELO,
                'equipo_id'    => $this->buildEquipoId($equipo),
                'equipo_db_id' => $equipo->ID_EQUIPO,
                'cambios'      => [],
                // NO es registro propio: borrarlo sería borrar el VEHÍCULO. deleteRegistro lo BLOQUEA.
                'del_source'   => 'equipo_creacion',
                'del_id'       => $equipo->ID_EQUIPO,
            ]);
        }
        // IDs de equipos que YA produjeron una fila 'creacion' aquí. Sirve para no
        // duplicar la creación con el log 'create' del audit (ver loop de auditoría).
        $creacionEquipoIds = array_flip($equiposCreados->pluck('ID_EQUIPO')->all());

        // Eventos de AUDITORIA de equipos (ediciones, cambios de metadata, ubicacion).
        // Se cargan desde la tabla `equipo_audit_log` con eager loading de
        // equipo + tipo + documentacion + usuario para evitar N+1 y poder mostrar
        // PLACA en el equipo_id (consistente con los otros loops).
        try {
            // withTrashed: incluye equipos soft-deleted asi los logs de
            // tipo 'delete' tambien muestran tipo/marca/modelo del equipo
            // borrado en lugar de un generico "Equipo Eliminado".
            $auditQuery = \App\Models\EquipoAuditLog::with([
                    'equipo' => function ($q) { $q->withTrashed()->with(['tipo', 'documentacion']); },
                    'usuario',
                ])
                ->whereNull('ID_AUXILIAR')   // los logs de auxiliar se procesan en su propio loop
                ->where('ACCION', '!=', 'movilizacion')
                ->orderByDesc('created_at');

            // Scope LOCAL + search_equipo + rango de fechas en SQL.
            $applyLocalScopeViaWhereHas($auditQuery);
            $applySearchEquipoViaWhereHas($auditQuery);
            if ($fechaDesdeSql) $auditQuery->where('created_at', '>=', $fechaDesdeSql);
            if ($fechaHastaSql) $auditQuery->where('created_at', '<=', $fechaHastaSql);

            $auditLogs = $auditQuery->limit(5000)->get();
            foreach ($auditLogs as $log) {
                $eq = $log->equipo;

                // Dedup de la CREACIÓN: el log 'create' del audit duplica la fila 'creacion'
                // del loop anterior (mismo vehículo, misma hora). Si el equipo ya salió ahí, se
                // omite. Los equipos BORRADOS no aparecen en $equiposCreados (no withTrashed),
                // así que su 'create' SÍ se conserva → su creación no se pierde.
                if ($log->ACCION === 'create' && $eq && isset($creacionEquipoIds[$eq->ID_EQUIPO])) {
                    continue;
                }

                $eName = $eq ? (($eq->tipo->nombre ?? 'Equipo') . ' ' . $eq->MARCA . ' ' . $eq->MODELO) : 'Equipo Eliminado';
                $eId   = $this->buildEquipoId($eq);
                // Mapping cubre solo las ACCION values que el codigo realmente
                // registra (verificado por busqueda de EquipoAuditLog::registrar
                // en EquipoController + EquipoObserver). Si en el futuro se agregan
                // nuevas acciones (status_change, ubicacion individual, etc.) se
                // deben mapear aqui Y agregar como opcion al dropdown del filtro.
                $tipoLabel = [
                    'edit'                 => 'Edición de Datos',
                    'metadata_propiedad'   => 'Edición Metadata Propiedad',
                    'metadata_poliza'      => 'Edición Metadata Póliza',
                    'metadata_rotc'        => 'Edición Metadata ROTC',
                    'metadata_racda'       => 'Edición Metadata RACDA',
                    'metadata_adicional'   => 'Edición Metadata Certificado',
                    'metadata_adicional_2' => 'Edición Metadata Compraventa',
                    'upload_propiedad'     => 'Subida Propiedad',
                    'upload_poliza'        => 'Subida Póliza',
                    'upload_rotc'          => 'Subida ROTC',
                    'upload_racda'         => 'Subida RACDA',
                    'upload_adicional'     => 'Subida Certificado',
                    'upload_adicional_2'   => 'Subida Compraventa',
                    'delete_propiedad'     => 'Borrado Propiedad',
                    'delete_poliza'        => 'Borrado Póliza',
                    'delete_rotc'          => 'Borrado ROTC',
                    'delete_racda'         => 'Borrado RACDA',
                    'delete_adicional'     => 'Borrado Certificado',
                    'delete_adicional_2'   => 'Borrado Compraventa',
                    'bulk_ubicacion'       => 'Detalle Masivo',
                    'create'               => 'Registro de Vehículo',
                    'delete'               => 'Eliminación de Equipo',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $cambiosRaw = is_array($log->CAMBIOS) ? $log->CAMBIOS : (is_string($log->CAMBIOS) ? json_decode($log->CAMBIOS, true) : []);

                // El historial de documentos NO muestra CONFIRMACIONES (CONFIRMADO_EN_SITIO)
                // ni MOVILIZACIONES. Aparte del log explícito 'movilizacion' (ya excluido en
                // el query), el EquipoObserver registra el cambio de frente/ubicación al
                // movilizar como un 'edit'; lo filtramos de los cambios. Si un 'edit' se queda
                // sin cambios visibles tras el filtro, se omite por completo.
                if (is_array($cambiosRaw)) {
                    unset(
                        $cambiosRaw['CONFIRMADO_EN_SITIO'],
                        $cambiosRaw['ID_FRENTE_ACTUAL'],
                        $cambiosRaw['DETALLE_UBICACION_ACTUAL']
                    );
                }
                if ($log->ACCION === 'edit' && empty($cambiosRaw)) {
                    continue;
                }

                $events->push((object)[
                    'doc_key'       => $log->ACCION,
                    'tipo'          => $tipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ('Usuario #' . $log->ID_USUARIO),
                    'autor_nombre'  => $log->usuario ? ($log->usuario->NOMBRE_COMPLETO ?? '') : '',
                    'fecha'         => $log->created_at,
                    'link'          => null,
                    'equipo_nombre' => $eName,
                    'equipo_id'     => $eId,
                    'equipo_db_id'  => $eq ? $eq->ID_EQUIPO : null,
                    'cambios'       => $cambiosRaw,
                    // Borrado de registro (solo super.admin, ver deleteRegistro): esta fila
                    // SÍ es un registro propio (equipo_audit_log) → se puede borrar.
                    'del_source'    => 'equipo_audit',
                    'del_id'        => $log->ID_LOG,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            // Tabla no existente / driver distinto → no rompe la vista, solo skip.
            \Illuminate\Support\Facades\Log::warning('audit log read failed: ' . $e->getMessage());
        }

        // Eventos de AUDITORIA de equipos AUXILIARES (ediciones + subidas/borrados de PDF).
        // Viven en la MISMA tabla equipo_audit_log pero con ID_AUXILIAR != null (ID_EQUIPO
        // null): un auxiliar puede no estar anclado a ningún vehículo host. Por eso el scope
        // de visibilidad se resuelve por el FRENTE PROPIO del auxiliar, no por un equipo host.
        try {
            $auxAuditQuery = \App\Models\EquipoAuditLog::with([
                    'auxiliar' => function ($q) { $q->withTrashed(); },
                    'usuario',
                ])
                ->whereNotNull('ID_AUXILIAR')
                ->orderByDesc('created_at');

            // Scope LOCAL (lista blanca) + lista negra por el ID_FRENTE_ACTUAL del auxiliar.
            if ($frentesVisibles !== null) {
                if (empty($frentesVisibles)) {
                    $auxAuditQuery->whereRaw('1 = 0'); // local sin frentes => nada
                } else {
                    $auxAuditQuery->whereHas('auxiliar', function ($q) use ($frentesVisibles) {
                        $q->withTrashed()->whereIn('ID_FRENTE_ACTUAL', $frentesVisibles);
                    });
                }
            }
            if (!empty($frentesBloqueados)) {
                $auxAuditQuery->whereHas('auxiliar', function ($q) use ($frentesBloqueados) {
                    $q->withTrashed()->whereNotIn('ID_FRENTE_ACTUAL', $frentesBloqueados);
                });
            }
            // search_equipo por SERIAL / CÓDIGO INTERNO del propio auxiliar.
            if ($searchEquipoSql !== '') {
                $like = '%' . strtoupper($searchEquipoSql) . '%';
                $auxAuditQuery->whereHas('auxiliar', function ($q) use ($like) {
                    $q->withTrashed()->where(function ($w) use ($like) {
                        $w->whereRaw('UPPER(SERIAL) like ?', [$like])
                          ->orWhereRaw('UPPER(CODIGO_INTERNO) like ?', [$like]);
                    });
                });
            }
            if ($fechaDesdeSql) $auxAuditQuery->where('created_at', '>=', $fechaDesdeSql);
            if ($fechaHastaSql) $auxAuditQuery->where('created_at', '<=', $fechaHastaSql);

            // Labels de tipo de auxiliar (código → etiqueta legible), resueltos una vez.
            $auxTiposLabel = \App\Models\EquipoAuxiliar::tiposLabel();

            $auxAuditLogs = $auxAuditQuery->limit(5000)->get();
            foreach ($auxAuditLogs as $log) {
                $ax = $log->auxiliar;

                // Label "Edición de Datos (Auxiliar)" contiene "Edición de Datos" a propósito:
                // así el filtro de Tipo "Edición de Datos" (match por substring) lo incluye.
                $tipoLabel = [
                    'aux_edit'               => 'Edición de Datos (Auxiliar)',
                    'aux_upload_propiedad'   => 'Subida Propiedad (Auxiliar)',
                    'aux_upload_certificado' => 'Subida Certificado (Auxiliar)',
                    'aux_delete_propiedad'   => 'Borrado Propiedad (Auxiliar)',
                    'aux_delete_certificado' => 'Borrado Certificado (Auxiliar)',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $cambiosRaw = is_array($log->CAMBIOS) ? $log->CAMBIOS : (is_string($log->CAMBIOS) ? json_decode($log->CAMBIOS, true) : []);
                $auxLabel = $cambiosRaw['_aux_label'] ?? '';
                if (is_array($cambiosRaw)) {
                    unset(
                        $cambiosRaw['_aux_label'],
                        $cambiosRaw['CONFIRMADO_EN_SITIO'],
                        $cambiosRaw['ID_FRENTE_ACTUAL'],
                        $cambiosRaw['DETALLE_UBICACION_ACTUAL']
                    );
                }
                // Un aux_edit que solo tocó frente/confirmación queda sin cambios visibles → se omite.
                if ($log->ACCION === 'aux_edit' && empty($cambiosRaw)) {
                    continue;
                }

                if ($ax) {
                    $tipoTxt = $ax->TIPO ? ($auxTiposLabel[$ax->TIPO] ?? $ax->TIPO) : '';
                    $eName   = 'Auxiliar ' . trim($tipoTxt . ' ' . $ax->MARCA . ' ' . $ax->MODELO);
                    if (!empty($ax->CODIGO_INTERNO))      $eId = 'Código: ' . $ax->CODIGO_INTERNO;
                    elseif (!empty($ax->SERIAL))          $eId = 'Serial: ' . $ax->SERIAL;
                    else                                  $eId = 'ID Aux: #' . $log->ID_AUXILIAR;
                } else {
                    // Auxiliar borrado en duro: usar el label embebido como fallback.
                    $eName = $auxLabel !== '' ? ('Auxiliar ' . $auxLabel) : 'Auxiliar Eliminado';
                    $eId   = 'ID Aux: #' . $log->ID_AUXILIAR;
                }

                $events->push((object)[
                    'doc_key'       => $log->ACCION,
                    'tipo'          => $tipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ('Usuario #' . $log->ID_USUARIO),
                    'autor_nombre'  => $log->usuario ? ($log->usuario->NOMBRE_COMPLETO ?? '') : '',
                    'fecha'         => $log->created_at,
                    'link'          => null,
                    'equipo_nombre' => $eName,
                    'equipo_id'     => $eId,
                    'equipo_db_id'  => null,
                    'cambios'       => is_array($cambiosRaw) ? $cambiosRaw : [],
                    // Registro propio (equipo_audit_log) → borrable por super.admin.
                    'del_source'    => 'equipo_audit',
                    'del_id'        => $log->ID_LOG,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::warning('aux audit log read failed: ' . $e->getMessage());
        }

        // Eventos de AUDITORIA del CATÁLOGO (ediciones de modelos, subida de foto).
        // Se omiten cuando hay filtro search_equipo activo (catálogo no tiene equipo).
        if ($searchEquipoSql === '') {
        try {
            $catAuditQuery = \App\Models\CatalogoAuditLog::with(['usuario', 'modelo'])
                ->orderByDesc('created_at');

            if ($fechaDesdeSql) $catAuditQuery->where('created_at', '>=', $fechaDesdeSql);
            if ($fechaHastaSql) $catAuditQuery->where('created_at', '<=', $fechaHastaSql);

            $catAuditLogs = $catAuditQuery->limit(5000)->get();
            foreach ($catAuditLogs as $log) {
                $catTipoLabel = [
                    'create'          => 'Registro de Modelo',
                    'create_aux'      => 'Registro de Auxiliar',
                    'edit'            => 'Edición de Modelo',
                    'upload_foto'     => 'Foto de Modelo',
                    'upload_foto_aux' => 'Foto de Auxiliar',
                    'delete'          => 'Eliminación de Modelo',
                ][$log->ACCION] ?? ucfirst(str_replace('_', ' ', $log->ACCION));

                $cambios = is_array($log->CAMBIOS) ? $log->CAMBIOS : (is_string($log->CAMBIOS) ? json_decode($log->CAMBIOS, true) : []);
                $modeloNombre = $log->MODELO ?? ($log->modelo ? $log->modelo->MODELO : 'Modelo Eliminado');
                $tipoEquipo = $cambios['tipo'] ?? ($log->modelo ? $log->modelo->TIPO : null);
                $anioVal = $log->ANIO_ESPEC;

                $parts = array_filter([$tipoEquipo, $modeloNombre, $anioVal], fn($v) => $v !== null && $v !== '');
                $equipoLabel = implode(' · ', $parts) ?: 'Modelo Eliminado';

                $events->push((object)[
                    'doc_key'       => 'catalogo_' . $log->ACCION,
                    'tipo'          => $catTipoLabel,
                    'autor'         => $log->usuario ? $log->usuario->CORREO_ELECTRONICO : ('Usuario #' . $log->ID_USUARIO),
                    'autor_nombre'  => $log->usuario ? ($log->usuario->NOMBRE_COMPLETO ?? '') : '',
                    'fecha'         => $log->created_at,
                    'link'          => null,
                    'equipo_nombre' => $equipoLabel,
                    'equipo_id'     => '',
                    'equipo_db_id'  => null,
                    'cambios'       => $cambios,
                    // Registro propio (catalogo_audit_log) → borrable.
                    'del_source'    => 'catalogo_audit',
                    'del_id'        => $log->ID_LOG,
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::warning('catalogo audit log read failed: ' . $e->getMessage());
        }
        } // fin if searchEquipoSql === ''

        // (Las recepciones de notas de traspaso de ALMACÉN ya NO se listan aquí: el
        // historial de documentos es de DOCUMENTOS/equipos, no de confirmaciones ni
        // movimientos de inventario. La trazabilidad de recepciones vive en el módulo
        // de Almacén.)

        // ── DEDUPLICACION legacy ↔ audit log ──────────────────────────────────
        // Cada subida de documento genera DOS eventos:
        //   1) "Título de Propiedad" desde el flag PROPIEDAD_FECHA_SUBIDA (loop docs).
        //      Tiene link al PDF y label amigable.
        //   2) "Subida Propiedad"   desde el audit log (loop audit).
        //      Sin link al PDF (audit log no guarda URL).
        // Mantener el evento LEGACY (con link) y descartar el audit log "upload_X"
        // cuando exista equivalente en el legacy. El boton "Ver PDF" funciona
        // porque conserva el link del legacy. Si solo hay audit log (caso edge sin
        // legacy), el evento se preserva pero no muestra boton PDF.
        $legacyKeys = [];
        $uploadToLegacy = [
            'upload_propiedad'   => 'propiedad',
            'upload_poliza'      => 'poliza',
            'upload_rotc'        => 'rotc',
            'upload_racda'       => 'racda',
            'upload_adicional'   => 'adicional',
            'upload_adicional_2' => 'adicional_2',
        ];
        foreach ($events as $e) {
            if (in_array($e->doc_key, $uploadToLegacy, true)) {
                $legacyKeys[$e->equipo_db_id . '|' . $e->doc_key . '|' . $e->fecha->format('Y-m-d')] = true;
            }
        }
        $events = $events->filter(function ($e) use ($uploadToLegacy, $legacyKeys) {
            if (isset($uploadToLegacy[$e->doc_key])) {
                $key = $e->equipo_db_id . '|' . $uploadToLegacy[$e->doc_key] . '|' . $e->fecha->format('Y-m-d');
                return !isset($legacyKeys[$key]);
            }
            return true;
        });

        // 3. Sort descending by date
        $events = $events->sortByDesc('fecha')->values();

        // 4. Filtros finales en memoria sobre los eventos ya construidos.
        //    - search_correo y search_tipo: NO se pueden hacer en SQL porque
        //      'autor'/'autor_nombre' son correo/nombre derivados de 6 relaciones
        //      distintas + el label 'tipo' es un string construido en PHP (no en DB).
        //      search_correo ubica por nombre O correo (ver más abajo).
        //    - fecha_desde/hasta: se aplico en SQL para REDUCIR el dataset, pero
        //      hay que repetir aqui porque un Documentacion tiene 6 fechas y solo
        //      necesitamos que UNA caiga en rango para traerlo; los eventos de las
        //      OTRAS fechas del mismo doc deben filtrarse aqui.
        //    - search_equipo y scope LOCAL ya se filtraron en SQL completamente.
        $hasInMemoryFilter = $request->filled('search_correo')
                          || $request->filled('search_tipo')
                          || $fechaDesdeSql || $fechaHastaSql;
        if ($hasInMemoryFilter) {
            $normalize = fn ($s) => mb_strtolower(\Illuminate\Support\Str::ascii((string) $s));

            $search_correo = $normalize($request->search_correo);
            $search_tipo   = $normalize($request->search_tipo);

            // doc_key de las subidas legacy (loop docs). El dropdown agrupa las 6
            // subidas / 6 borrados / 6 ediciones de metadata en UNA opcion por accion
            // (valores 'cat_*'), reduciendo la lista de ~24 a ~9. Aqui se mapea cada
            // 'cat_*' al doc_key del evento. Cualquier OTRO valor (acciones del equipo
            // o deep-links antiguos con el label exacto) cae al match por substring.
            $catUploadKeys = ['propiedad', 'poliza', 'rotc', 'racda', 'adicional', 'adicional_2'];

            $events = $events->filter(function ($event) use ($normalize, $search_correo, $search_tipo, $fechaDesdeSql, $fechaHastaSql, $catUploadKeys) {
                // Ubicar por NOMBRE o CORREO del autor: el término casa si está en
                // cualquiera de los dos (autor = correo, autor_nombre = nombre completo).
                if ($search_correo
                    && strpos($normalize($event->autor), $search_correo) === false
                    && strpos($normalize($event->autor_nombre ?? ''), $search_correo) === false) {
                    return false;
                }
                if ($search_tipo && $search_tipo !== 'all') {
                    if ($search_tipo === 'cat_uploads') {
                        $okTipo = in_array($event->doc_key, $catUploadKeys, true)
                               || \Illuminate\Support\Str::startsWith($event->doc_key, 'upload_')
                               || \Illuminate\Support\Str::startsWith($event->doc_key, 'aux_upload_');
                    } elseif ($search_tipo === 'cat_borrados') {
                        $okTipo = \Illuminate\Support\Str::startsWith($event->doc_key, 'delete_')
                               || \Illuminate\Support\Str::startsWith($event->doc_key, 'aux_delete_');
                    } elseif ($search_tipo === 'cat_metadatos') {
                        $okTipo = \Illuminate\Support\Str::startsWith($event->doc_key, 'metadata_');
                    } else {
                        // Acciones del equipo (Registro / Edición de Datos / Detalle
                        // Masivo / Eliminación) o label exacto legacy → substring.
                        $okTipo = strpos($normalize($event->tipo), $search_tipo) !== false;
                    }
                    if (!$okTipo) return false;
                }
                if ($fechaDesdeSql && $event->fecha->lt($fechaDesdeSql)) return false;
                if ($fechaHastaSql && $event->fecha->gt($fechaHastaSql)) return false;
                return true;
            })->values();
        }

        // 4b. "Ver solo seleccionados" (contador de la barra flotante): whitelist de
        //     eventos por su hash md5 — el MISMO que genera el partial en data-hd-id
        //     (equipo_id + tipo + timestamp). Replica el ids_in del módulo Equipos para
        //     esta vista de eventos heterogéneos sin clave primaria única. La seguridad
        //     (scope por frentes permitidos) ya se aplicó en SQL y se mantiene.
        if ($request->filled('hd_ids')) {
            $hdIdsSet = array_flip(array_filter(array_map('trim', explode(',', (string) $request->input('hd_ids')))));
            if (!empty($hdIdsSet)) {
                $events = $events->filter(function ($event) use ($hdIdsSet) {
                    return isset($hdIdsSet[md5($event->equipo_id . $event->tipo . $event->fecha->timestamp)]);
                })->values();
            }
        }

        $total = $events->count();

        // 5. Paginación manual (LengthAwarePaginator) sobre la colección de eventos.
        $perPage = 15;
        $page = $request->input('page', 1);
        
        $paginatedEvents = new \Illuminate\Pagination\LengthAwarePaginator(
            $events->forPage($page, $perPage)->values(), // important: values() to reset keys for blade loop
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // IPs EFECTIVAMENTE bloqueadas (>= umbral de intentos fallidos), no las que aún
        // están en seguimiento. Criterio/umbral en un solo lugar: BloqueoIp::bloqueadas().
        $blockedIps = BloqueoIp::bloqueadas()->get();

        // Usuarios con sesion activa en los ultimos 30 min (driver database).
        // Se lee directamente la tabla `sessions` (Laravel la crea cuando SESSION_DRIVER=database).
        // NOTA: el JOIN usa `sessions.user_id = usuarios.ID_USUARIO` — esto funciona porque
        // App\Models\Usuario sobrescribe $primaryKey = 'ID_USUARIO', asi Auth::id() devuelve
        // ese valor y Laravel lo persiste en sessions.user_id. La FK formal del schema
        // apunta a la tabla default `users` que NO se usa en este proyecto.
        // Ambas columnas del filtro (user_id y last_activity) estan indexadas.
        // Fuente ÚNICA: App\Models\Usuario::sesionesActivas() (reutilizada por /admin/usuarios).
        $activeUsers = \App\Models\Usuario::sesionesActivas(30);

        if ($request->wantsJson()) {
            return response()->json([
                'html' => view('admin.historial_documentos.partials.table_rows', ['events' => $paginatedEvents])->render(),
                'pagination' => $paginatedEvents->links('vendor.pagination.custom-sliding')->toHtml(),
                'total' => $total
            ]);
        }

        // Autores (nombre + correo) → autocompletado del filtro "Buscar por nombre o
        // correo del autor". Se sugieren ambos para que el usuario ubique por cualquiera.
        $autoresSugeridos = \App\Models\Usuario::whereNotNull('CORREO_ELECTRONICO')
            ->where('CORREO_ELECTRONICO', '!=', '')
            ->orderBy('NOMBRE_COMPLETO')
            ->get(['NOMBRE_COMPLETO', 'CORREO_ELECTRONICO'])
            ->map(fn ($u) => [
                'nombre' => (string) ($u->NOMBRE_COMPLETO ?? ''),
                'correo' => (string) $u->CORREO_ELECTRONICO,
            ])
            ->values();

        return view('admin.historial_documentos.index', [
            'events'           => $paginatedEvents,
            'total'            => $total,
            'blockedIps'       => $blockedIps,
            'activeUsers'      => $activeUsers,
            'autoresSugeridos' => $autoresSugeridos,
        ]);
    }

    /**
     * Desbloquear IP. El permiso 'super.admin' ya se valida en el group de rutas
     * en routes/web.php (Route::middleware('can:super.admin')->group), por eso
     * no se duplica el check aqui.
     */
    public function unlockIp($id)
    {
        try {
            $bloqueo = BloqueoIp::findOrFail($id);
            $ip = $bloqueo->DIRECCION_IP;
            $bloqueo->delete();

            return response()->json([
                'success' => true,
                'message' => "La IP {$ip} ha sido desbloqueada exitosamente."
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('unlockIp failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al desbloquear la IP.'
            ], 500);
        }
    }

    /**
     * Eliminar un registro del historial (solo super.admin — gateado en routes/web.php).
     *
     * El historial mezcla 4 orígenes (ver index). SOLO los de AUDITORÍA son registros
     * propios borrables:
     *   - equipo_audit   → fila de `equipo_audit_log`.
     *   - catalogo_audit → fila de `catalogo_audit_log`.
     * Los otros dos se BLOQUEAN a propósito (borrarlos tocaría datos reales):
     *   - doc            → es una bandera en `documentacion`; borrarlo quitaría el documento.
     *   - equipo_creacion→ es el created_at del equipo; borrarlo sería borrar el vehículo.
     */
    public function deleteRegistro(Request $request)
    {
        $data = $request->validate([
            'source' => 'required|string|in:equipo_audit,catalogo_audit,doc,equipo_creacion',
            'id'     => 'nullable',
        ]);

        // Casos bloqueados: el botón aparece en todas las filas, pero estos no se borran
        // para no afectar datos reales (documento / vehículo). Mensaje claro al usuario.
        if ($data['source'] === 'doc') {
            return response()->json([
                'success' => false,
                'message' => 'Esta fila es una subida de documento real. Para quitarlo, bórralo desde el documento del equipo, no desde el historial.',
            ], 422);
        }
        if ($data['source'] === 'equipo_creacion') {
            return response()->json([
                'success' => false,
                'message' => 'Esta fila es el registro de creación de un vehículo. Para eliminarlo usa el módulo de Equipos.',
            ], 422);
        }

        try {
            $modelo = $data['source'] === 'equipo_audit'
                ? \App\Models\EquipoAuditLog::query()
                : \App\Models\CatalogoAuditLog::query();

            $borrados = $modelo->where('ID_LOG', $data['id'])->delete();

            if (!$borrados) {
                return response()->json(['success' => false, 'message' => 'El registro ya no existe.'], 404);
            }

            return response()->json(['success' => true, 'message' => 'Registro eliminado del historial.']);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('deleteRegistro failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'No se pudo eliminar el registro.'], 500);
        }
    }
}
