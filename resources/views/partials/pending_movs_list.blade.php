{{--
    Partial: Lista de Movilizaciones Pendientes (Por Confirmar Recepción)
    Variables requeridas: $recentActivity (Collection de Movilizacion con relaciones)
--}}
@php
    $authUser      = auth()->user();
    $authFrenteIds = $authUser ? $authUser->getFrentesIds() : [];
    $authEsGlobal  = ($authUser && $authUser->NIVEL_ACCESO == 1);
    // Regla de visibilidad:
    // - Si el usuario tiene frentes asignados (cualquier nivel), SOLO ve
    //   movilizaciones cuyo destino esta en esos frentes. Esto asegura que
    //   un usuario nivel 1 con frente=15 no reciba notificaciones de otros
    //   frentes donde no opera.
    // - Si es GLOBAL y NO tiene frentes asignados (super-admin master sin
    //   restricciones), ve todo.
    $authFrenteIdsStr = array_map('strval', $authFrenteIds);
    $verTodo = $authEsGlobal && count($authFrenteIds) === 0;
@endphp

@forelse($recentActivity as $activity)
    @php
        $esDestinatario = $verTodo
            || in_array((string)$activity->ID_FRENTE_DESTINO, $authFrenteIdsStr);
        $placa          = $activity->equipo->documentacion->PLACA ?? null;
        $serial         = $activity->equipo->SERIAL_CHASIS ?? null;
        $primaryId      = ($placa && strtoupper($placa) !== 'N/A') ? $placa : $serial;
        $nombreFrente   = $activity->frenteDestino->NOMBRE_FRENTE ?? 'Sin Frente';
        $tipoNombre     = $activity->equipo->tipo->nombre ?? 'Equipo';
        $etiqueta       = $activity->equipo->NUMERO_ETIQUETA ?? null;
        $diffHumans     = $activity->created_at ? $activity->created_at->locale('es')->diffForHumans() : 'Fecha desconocida';
    @endphp
    @if($esDestinatario)
    <div class="mov-pending-item" id="mov-item-{{ $activity->ID_MOVILIZACION }}"
         data-chasis="{{ $activity->equipo->SERIAL_CHASIS ?? '' }}"
         data-placa="{{ $activity->equipo->documentacion->PLACA ?? '' }}"
         data-etiqueta="{{ $activity->equipo->NUMERO_ETIQUETA ?? '' }}">

        {{-- Icono lateral --}}
        <div class="mov-icon-col">
            <i class="material-icons">local_shipping</i>
        </div>

        {{-- Contenido principal --}}
        <div class="mov-body">
            <div class="mov-top-row">
                <span class="mov-tipo">{{ $tipoNombre }}</span>
                @if($etiqueta)
                    <span class="mov-etiqueta">#{{ $etiqueta }}</span>
                @endif
            </div>
            <p class="mov-placa">{{ $primaryId ?? '—' }}</p>
            <div class="mov-meta">
                <span class="mov-frente">
                    <i class="material-icons">place</i>
                    {{ $nombreFrente }}
                </span>
                <span class="mov-time">
                    <i class="material-icons">schedule</i>
                    {{ $diffHumans }}
                </span>
            </div>
        </div>

        {{-- Botón confirmar --}}
        <div class="mov-action-col">
            <button type="button"
                onclick="iniciarRecepcionDesdeDashboard({{ $activity->ID_MOVILIZACION }}, '{{ addslashes($activity->frenteDestino->NOMBRE_FRENTE ?? '') }}', '{{ addslashes($activity->frenteDestino->SUBDIVISIONES ?? '') }}', {{ $activity->ID_FRENTE_DESTINO }}, {{ $activity->ID_EQUIPO }})"
                class="mov-confirm-btn"
                title="Confirmar recepción en {{ $nombreFrente }}">
                <i class="material-icons">check_circle</i>
            </button>
        </div>
    </div>
    @endif
@empty
    <div class="mov-empty-state">
        <i class="material-icons">inbox</i>
        <p>No hay movilizaciones por confirmar.</p>
    </div>
@endforelse

<style>
/* ── Lista de movilizaciones pendientes ── */
.mov-pending-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 14px;
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s;
}
.mov-pending-item:last-child { border-bottom: none; }
.mov-pending-item:hover { background: #f8fafc; }

/* Icono lateral */
.mov-icon-col {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #e8f0fe;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.mov-icon-col .material-icons {
    font-size: 18px;
    color: #0067b1;
}

/* Cuerpo */
.mov-body {
    flex: 1;
    min-width: 0;
}
.mov-top-row {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 1px;
}
.mov-tipo {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
}
.mov-etiqueta {
    font-size: 11px;
    font-weight: 800;
    color: #0067b1;
    background: #eff6ff;
    border-radius: 4px;
    padding: 1px 5px;
}
.mov-placa {
    margin: 0 0 3px 0;
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    font-family: monospace;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.mov-meta {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.mov-frente,
.mov-time {
    display: flex;
    align-items: center;
    gap: 2px;
    font-size: 10px;
    font-weight: 600;
    color: #64748b;
}
.mov-frente .material-icons,
.mov-time .material-icons {
    font-size: 11px;
    color: #94a3b8;
}

/* Botón confirmar */
.mov-action-col { flex-shrink: 0; }
.mov-confirm-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    background: #00004d;
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: default;
    transition: background 0.2s, transform 0.15s;
}
.mov-confirm-btn:hover {
    background: #0067b1;
    transform: scale(1.08);
}
.mov-confirm-btn .material-icons { font-size: 18px; }

/* Responsive mobile: compactar items para que no se desborden en pantallas pequeñas */
@media (max-width: 520px) {
    .mov-pending-item {
        padding: 8px 10px;
        gap: 8px;
        align-items: flex-start;
    }
    .mov-icon-col { width: 30px; height: 30px; border-radius: 7px; }
    .mov-icon-col .material-icons { font-size: 16px; }
    .mov-tipo { font-size: 12px; }
    .mov-etiqueta { font-size: 10px; }
    .mov-placa { font-size: 10px; }
    .mov-meta { gap: 6px; row-gap: 3px; }
    .mov-frente, .mov-time { font-size: 9.5px; }
    .mov-confirm-btn { width: 28px; height: 28px; border-radius: 7px; }
    .mov-confirm-btn .material-icons { font-size: 16px; }
}

/* Estado vacío */
.mov-empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 30px 20px;
    color: #94a3b8;
    gap: 8px;
}
.mov-empty-state .material-icons { font-size: 36px; }
.mov-empty-state p { margin: 0; font-size: 13px; font-weight: 500; }
</style>
