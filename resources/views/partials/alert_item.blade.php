@php
    $isExpired = ($alert->status ?? 'expired') === 'expired';
    $iconColor = $isExpired ? '#ef4444' : '#f59e0b';
    $icon = $isExpired ? 'error' : 'schedule';

    $gestionadoPor = $alert->gestionado_por ?? null;
    $diasGestion = null;
    if (!empty($alert->fecha_gestion)) {
        $diasGestion = (int) \Carbon\Carbon::parse($alert->fecha_gestion)->diffInDays(now());
    }

    $equipoId = $alert->equipo->ID_EQUIPO;
    $docType  = $alert->type_key;
@endphp

<div class="alert-card"
     data-equipo-id="{{ $equipoId }}"
     data-doc-type="{{ $docType }}"
     data-placa="{{ optional($alert->equipo->documentacion)->PLACA ?? '' }}"
     data-chasis="{{ $alert->equipo->SERIAL_CHASIS ?? '' }}"
     data-motor-serial="{{ $alert->equipo->SERIAL_DE_MOTOR ?? '' }}"
     data-marca="{{ $alert->equipo->MARCA ?? '' }}"
     data-modelo="{{ $alert->equipo->MODELO ?? '' }}"
     data-tipo="{{ $alert->equipo->tipo->nombre ?? 'Equipo' }}"
     style="padding: 12px; border-bottom: 1px solid #e5e7eb; background: white; transition: background 0.2s;">

    <div style="display: flex; align-items: flex-start; gap: 8px;">

        {{-- Icono de estado --}}
        <div style="flex-shrink: 0; padding-top: 2px;">
            <i class="material-icons" style="font-size: 22px; color: {{ $iconColor }};">{{ $icon }}</i>
        </div>

        {{-- Contenido principal --}}
        <div style="flex: 1; min-width: 0;">

            {{-- Nombre del equipo --}}
            <div style="font-size: 13px; font-weight: 700; color: #1f2937; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                {{ $alert->equipo->tipo->nombre ?? 'Equipo' }} {{ $alert->equipo->MARCA }} {{ $alert->equipo->MODELO }}
            </div>

            {{-- Línea de estado + botón ojo --}}
            <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 6px;">
                <span style="font-size: 12px; color: {{ $iconColor }}; font-weight: 600;">
                    {{ $alert->label }}: {{ ucfirst(\Carbon\Carbon::parse($alert->fecha)->locale('es')->diffForHumans(null, true)) }}
                </span>

                {{-- Botón Ver Detalles --}}
                <button type="button"
                    data-equipo-id="{{ $equipoId }}"
                    data-codigo="{{ $alert->equipo->CODIGO_PATIO }}"
                    data-marca="{{ $alert->equipo->MARCA }}"
                    data-modelo="{{ $alert->equipo->MODELO }}"
                    data-anio="{{ $alert->equipo->ANIO }}"
                    data-tipo="{{ $alert->equipo->tipo->nombre ?? 'N/A' }}"
                    data-categoria="{{ $alert->equipo->CATEGORIA_FLOTA }}"
                    data-ubicacion="{{ $alert->equipo->frenteActual->NOMBRE_FRENTE ?? 'Sin Asignar' }}"
                    data-motor-serial="{{ $alert->equipo->SERIAL_DE_MOTOR }}"
                    data-chasis="{{ $alert->equipo->SERIAL_CHASIS }}"
                    data-combustible="{{ $alert->equipo->especificaciones->COMBUSTIBLE ?? 'N/A' }}"
                    data-consumo="{{ $alert->equipo->especificaciones->CONSUMO_PROMEDIO ?? 'N/A' }}"
                    data-placa="{{ $alert->equipo->documentacion->PLACA ?? 'N/A' }}"
                    data-titular="{{ $alert->equipo->documentacion->NOMBRE_DEL_TITULAR ?? 'N/A' }}"
                    data-nro-doc="{{ $alert->equipo->documentacion->NRO_DE_DOCUMENTO ?? 'N/A' }}"
                    data-venc-seguro="{{ $alert->equipo->documentacion->FECHA_VENC_POLIZA ?? 'N/A' }}"
                    data-seguro="{{ $alert->equipo->documentacion->seguro->NOMBRE_ASEGURADORA ?? 'N/A' }}"
                    data-link-propiedad="{{ $alert->equipo->documentacion->LINK_DOC_PROPIEDAD ?? '' }}"
                    data-link-seguro="{{ $alert->equipo->documentacion->LINK_POLIZA_SEGURO ?? '' }}"
                    data-link-rotc="{{ $alert->equipo->documentacion->LINK_ROTC ?? '' }}"
                    data-fecha-rotc="{{ $alert->equipo->documentacion->FECHA_ROTC ?? '' }}"
                    data-link-racda="{{ $alert->equipo->documentacion->LINK_RACDA ?? '' }}"
                    data-fecha-racda="{{ $alert->equipo->documentacion->FECHA_RACDA ?? '' }}"
                    data-link-adicional="{{ $alert->equipo->documentacion->LINK_DOC_ADICIONAL ?? '' }}"
                    data-link-gps="{{ $alert->equipo->LINK_GPS ?? '' }}"
                    onclick="showDetailsImproved(this, event)"
                    title="Ver detalles del equipo"
                    style="flex-shrink: 0; background: transparent; border: none; width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; color: #9ca3af;">
                    <i class="material-icons" style="font-size: 19px; pointer-events: none;">visibility</i>
                </button>
            </div>

            {{-- Gestión --}}
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                @if($gestionadoPor)
                    <span style="display: inline-flex; align-items: center; gap: 4px; background: #e0f2fe; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; color: #0369a1; border: 1px solid #bae6fd;">
                        <i class="material-icons" style="font-size: 13px; pointer-events: none;">engineering</i>
                        En gestión: {{ $gestionadoPor }}@if($diasGestion !== null) ({{ $diasGestion }} {{ $diasGestion == 1 ? 'día' : 'días' }})@endif
                    </span>
                @else
                    <button
                        type="button"
                        onclick="window.iniciarGestionCustom('{{ $equipoId }}', '{{ $docType }}', event)"
                        title="Iniciar gestión de este documento"
                        style="background: transparent; border: 1px solid #d1d5db; padding: 3px 10px; border-radius: 6px; color: #4b5563; font-size: 11px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px;"
                        onmouseover="this.style.borderColor='#3b82f6'; this.style.color='#2563eb'; this.style.background='#eff6ff';"
                        onmouseout="this.style.borderColor='#d1d5db'; this.style.color='#4b5563'; this.style.background='transparent';">
                        <i class="material-icons" style="font-size: 13px; pointer-events: none;">manage_accounts</i>
                        <span style="pointer-events:none;">Gestionar</span>
                        <i class="material-icons" style="font-size: 12px; pointer-events: none;">arrow_forward</i>
                    </button>
                @endif
            </div>

        </div>
    </div>
</div>
