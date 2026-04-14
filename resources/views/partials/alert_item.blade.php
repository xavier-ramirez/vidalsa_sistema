
@php
    $isExpired    = ($alert->status ?? 'expired') === 'expired';
    $iconColor    = $isExpired ? '#ef4444' : '#f59e0b';
    $iconBgColor  = $isExpired ? '#fee2e2' : '#fef3c7';
    $icon         = $isExpired ? 'error' : 'schedule';
    $gestionadoPor = $alert->gestionado_por ?? null;
    $equipoId     = $alert->equipo->ID_EQUIPO;
    $docType      = $alert->type_key;
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
     style="display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #f1f5f9; transition: background 0.15s; background: white; min-width: 0; max-width: 100%; box-sizing: border-box; overflow: hidden;"
     onmouseover="this.style.background='#f8fafc'"
     onmouseout="this.style.background='white'">

    {{-- Icono lateral --}}
    <div style="width: 34px; height: 34px; border-radius: 8px; background: {{ $iconBgColor }}; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
        <i class="material-icons" style="font-size: 18px; color: {{ $iconColor }};">{{ $icon }}</i>
    </div>

    {{-- Contenido principal --}}
    <div style="flex: 1; min-width: 0;">
        <div style="display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 1px;">
            <span style="font-size: 13px; font-weight: 700; color: #1e293b;">
                {{ $alert->equipo->tipo->nombre ?? 'Equipo' }}
            </span>
            <span style="font-size: 11px; font-weight: 800; color: #0067b1; background: #eff6ff; border-radius: 4px; padding: 1px 5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 120px; display: inline-block;">
                {{ $alert->equipo->MARCA }} {{ $alert->equipo->MODELO }}
            </span>
        </div>
        <p style="margin: 0 0 3px 0; font-size: 11px; font-weight: 700; color: #475569; font-family: monospace; text-transform: uppercase; letter-spacing: 0.5px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ $alert->equipo->documentacion->PLACA ?? ($alert->equipo->SERIAL_CHASIS ?? '—') }}
        </p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <span style="display: flex; align-items: center; gap: 2px; font-size: 10px; font-weight: 600; color: {{ $iconColor }};">
                <i class="material-icons" style="font-size: 11px;">{{ $isExpired ? 'priority_high' : 'update' }}</i>
                {{ $alert->label }}: {{ ucfirst(\Carbon\Carbon::parse($alert->fecha)->locale('es')->diffForHumans(null, true)) }}
            </span>
            @if($gestionadoPor)
                <span style="display: flex; align-items: center; gap: 2px; font-size: 10px; font-weight: 600; color: #0369a1;">
                    <i class="material-icons" style="font-size: 11px;">engineering</i>
                    En gestión: {{ explode(' ', $gestionadoPor)[0] }}
                </span>
            @endif
        </div>
    </div>

    {{-- Botones (Acciones) --}}
    <div style="flex-shrink: 0; display: flex; gap: 5px; align-items: center;">
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
            style="width: 32px; height: 32px; border-radius: 8px; border: none; background: transparent; color: #94a3b8; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s;"
            onmouseover="this.style.background='#f1f5f9'; this.style.color='#1e293b';"
            onmouseout="this.style.background='transparent'; this.style.color='#94a3b8';">
            <i class="material-icons" style="font-size: 20px; pointer-events: none;">visibility</i>
        </button>

        @if(!$gestionadoPor)
        <button type="button"
            onclick="window.iniciarGestionCustom('{{ $equipoId }}', '{{ $docType }}', event)"
            title="Iniciar gestión de este documento"
            style="width: 32px; height: 32px; border-radius: 8px; border: none; background: #00004d; color: white; display: flex; align-items: center; justify-content: center; transition: background 0.2s, transform 0.15s; cursor: pointer;"
            onmouseover="this.style.background='#0067b1'; this.style.transform='scale(1.08)';"
            onmouseout="this.style.background='#00004d'; this.style.transform='scale(1)';">
            <i class="material-icons" style="font-size: 18px; pointer-events: none;">manage_accounts</i>
        </button>
        @endif
    </div>
</div>
