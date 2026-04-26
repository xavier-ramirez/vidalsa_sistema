@forelse($movilizaciones as $mov)
    @php
        // La movilizacion puede ser de un EQUIPO (ID_EQUIPO) o de un AUXILIAR
        // (ID_AUXILIAR) — son mutuamente excluyentes. La celda 1 cambia segun
        // cual venga poblado para que ambas se vean en la misma tabla.
        $isAux = !empty($mov->ID_AUXILIAR) && $mov->auxiliar;
        $equipoFoto = !$isAux ? optional(optional($mov->equipo)->especificaciones)->FOTO_REFERENCIAL : null;
        $auxFoto    = $isAux ? $mov->auxiliar->FOTO : null;
        $tiposAuxMap = $isAux ? \App\Models\EquipoAuxiliar::tiposLabel() : [];
        $auxTipoLabel = $isAux ? ($tiposAuxMap[$mov->auxiliar->TIPO] ?? $mov->auxiliar->TIPO) : null;
    @endphp
    <tr class="mv-row-card mv-selectable-row" data-mv-id="{{ $mov->ID_MOVILIZACION }}"
        data-equipo-codigo="{{ $isAux ? '' : ($mov->equipo->CODIGO_PATIO ?? '') }}"
        data-aux-id="{{ $isAux ? $mov->ID_AUXILIAR : '' }}">
        {{-- 1. Equipo / Auxiliar --}}
        <td class="mv-td-equipo">
            <div style="display: flex; align-items: center; justify-content: flex-start; gap: 10px;">
                @if($isAux)
                    @php
                        $auxDriveId = $auxFoto ? basename(str_replace('/storage/google/', '', explode('?', $auxFoto)[0])) : null;
                    @endphp
                    @if($auxDriveId)
                        <div style="width: 50px; height: 35px; border-radius: 4px; overflow: hidden; flex-shrink: 0; background: #fff7ed; border: 1px solid #fed7aa;">
                            <img src="{{ url('/storage/google/' . $auxDriveId . '?sz=w120') }}" alt="Foto" style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                    @else
                        <div style="width: 50px; height: 35px; border-radius: 4px; background: #fff7ed; display: flex; align-items: center; justify-content: center; color: #c2410c; flex-shrink: 0; border: 1px dashed #fed7aa;">
                            <i class="material-icons" style="font-size: 20px;">construction</i>
                        </div>
                    @endif
                    <div style="display: flex; flex-direction: column; flex: 1; min-width: 0;">
                        <span style="font-size: 11px; color: #c2410c; font-weight: 800; text-transform: uppercase; letter-spacing: 0.4px;">
                            <i class="material-icons" style="font-size: 11px; vertical-align: middle;">construction</i>
                            {{ $auxTipoLabel ?? 'AUXILIAR' }}
                        </span>
                        <div style="color: #4a5568; font-size: 13px;"><strong>S:</strong> {{ $mov->auxiliar->SERIAL ?? '—' }}</div>
                        <div style="color: #475569; font-size: 12.5px; text-transform: uppercase;">{{ $mov->auxiliar->MARCA }} {{ $mov->auxiliar->MODELO }}</div>
                    </div>
                @else
                    @if($equipoFoto)
                        <div style="width: 50px; height: 35px; border-radius: 4px; overflow: hidden; flex-shrink: 0; background: #f8fafc;">
                            <img src="{{ route('drive.file', ['path' => str_replace('/storage/google/', '', $equipoFoto)]) }}" alt="Foto" style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                    @else
                        <div style="width: 50px; height: 35px; border-radius: 4px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #cbd5e0; flex-shrink: 0; border: 1px dashed #e2e8f0;">
                            <i class="material-icons" style="font-size: 20px;">image_not_supported</i>
                        </div>
                    @endif
                    <div style="display: flex; flex-direction: column; flex: 1; min-width: 0;">
                        <span style="font-size: 13px; color: #718096; font-weight: 700; text-transform: uppercase;">{{ $mov->equipo->tipo->nombre ?? 'N/A' }}</span>
                        <div style="color: #4a5568; font-size: 13px;"><strong>S:</strong> {{ $mov->equipo->SERIAL_CHASIS ?? 'S/S' }}</div>
                        <div style="color: var(--maquinaria-blue); font-size: 13px;"><strong>P:</strong> {{ $mov->equipo->documentacion->PLACA ?? 'S/P' }}</div>
                        <div class="mv-id-field" style="color: #2d3748; font-size: 13px; font-weight: 600;"><strong>ID:</strong> {{ $mov->equipo->CODIGO_PATIO ?? 'N/D' }}</div>
                    </div>
                @endif
            </div>
        </td>

        {{-- 2. Trayecto (Origen → Destino) --}}
        <td class="mv-td-trayecto">
            <div class="mv-trayecto-container"
                style="display: flex; align-items: center; justify-content: center; gap: 12px;">
                <div class="mv-trayecto-item"
                    style="display: flex; flex-direction: column; align-items: center; max-width: 160px;">
                    <span class="mv-trayecto-label"
                        style="font-size: 11px; color: #64748b; font-weight: 800; text-transform: uppercase;">Origen</span>
                    <span class="mv-frente-nombre"
                        style="font-weight: 600; color: #4a5568; font-size: 13px; line-height: 1.2;">
                        {{ $mov->frenteOrigen->NOMBRE_FRENTE ?? 'Sin Origen' }}
                    </span>
                </div>
                <i class="material-icons mv-trayecto-arrow"
                    style="font-size: 18px; color: #cbd5e0; flex-shrink: 0;">east</i>
                <div class="mv-trayecto-item"
                    style="display: flex; flex-direction: column; align-items: center; max-width: 160px;">
                    <span class="mv-trayecto-label"
                        style="font-size: 11px; color: #0067b1; font-weight: 800; text-transform: uppercase;">Destino</span>
                    <span class="mv-frente-nombre"
                        style="font-weight: 700; color: var(--maquinaria-dark-blue); font-size: 13px; line-height: 1.2;">
                        {{ $mov->frenteDestino->NOMBRE_FRENTE ?? 'Sin Destino' }}
                    </span>
                </div>
            </div>
        </td>

        {{-- 3. Fechas --}}
        <td class="mv-td-fechas mv-mobile-hidden">
            <div
                style="display: flex; flex-direction: column; align-items: center; line-height: 1.2; justify-content: center; height: 100%; gap: 4px;">
                <div
                    style="display: flex; align-items: center; gap: 4px; background: #f1f5f9; padding: 4px 8px; border-radius: 6px; border: 1px solid #e2e8f0;">
                    <i class="material-icons" style="font-size: 16px; color: #64748b;">event</i>
                    <span
                        style="font-size: 13px; color: #334155; font-weight: 700;">{{ $mov->created_at ? $mov->created_at->format('d/m/Y') : '--' }}</span>
                </div>
                @if($mov->created_at)
                    <div style="font-size: 11px; color: #94a3b8; font-weight: 600; display: flex; align-items: center; gap: 3px;">
                        <i class="material-icons" style="font-size: 12px;">schedule</i>
                        {{ $mov->created_at->format('h:i A') }}
                    </div>
                @endif
            </div>
        </td>

        {{-- 4. N° Operación (oculto en mobile) --}}
        <td class="mv-col-op mv-mobile-hidden">
            <div style="display: flex; flex-direction: column; align-items: center; line-height: 1.2; gap: 2px;">
                @if($mov->CODIGO_CONTROL)
                    {{-- Defensa: stripear posible prefijo "MV-" en valor crudo
                         para evitar render "MV-MV-XXXXX" en registros antiguos
                         de aux que se guardaron con prefijo. --}}
                    @php $cc = preg_replace('/[^0-9]/', '', (string) $mov->CODIGO_CONTROL); @endphp
                    <span
                        style="font-weight: 800; color: #1e293b; font-size: 13px;">MV-{{ str_pad($cc, 5, '0', STR_PAD_LEFT) }}</span>
                @else
                    <span style="color: #94a3b8; font-size: 13px; font-weight: 600;">--</span>
                @endif
                <div
                    style="display: flex; align-items: center; gap: 4px; color: #64748b; font-size: 13px; font-weight: 600;">
                    <i class="material-icons" style="font-size: 15px;">person</i>
                    {{ $mov->usuario->NOMBRE_COMPLETO ?? $mov->USUARIO_REGISTRO }}
                </div>
            </div>
        </td>

        {{-- 5. Estado --}}
        <td class="mv-td-estado">
            <div style="display: flex; flex-direction: column; align-items: center; gap: 5px;">
                @if($mov->TIPO_MOVIMIENTO === 'RECEPCION_DIRECTA' || $mov->TIPO_MOVIMIENTO === 'ACT.')
                    <div
                        style="background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; padding: 4px 6px; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 4px; font-size: 10px; font-weight: 800;">
                        <i class="material-icons" style="font-size: 14px;">input</i>
                        <span>ACTUALIZACIÓN DE UBICACIÓN</span>
                    </div>
                @else
                    <div
                        style="background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; padding: 4px 6px; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 4px; font-size: 10px; font-weight: 800;">
                        <i class="material-icons" style="font-size: 14px;">swap_horiz</i>
                        <span>MOVILIZACIÓN</span>
                    </div>
                @endif
            </div>
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" style="padding: 60px 20px; border: none; background: transparent;">
            <div
                style="display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; color: #94a3b8; border: 1px dashed #cbd5e0; border-radius: 12px; padding: 60px 20px;">
                <i class="material-icons" style="font-size: 48px; opacity: 0.3;">local_shipping</i>
                <p style="font-weight: 600; font-size: 14px; margin: 0;">No se encontraron movilizaciones registradas.</p>
            </div>
        </td>
    </tr>
@endforelse