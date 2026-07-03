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
                        <div style="width: 68px; height: 48px; border-radius: 4px; overflow: hidden; flex-shrink: 0; background: #fff;">
                            <img src="{{ url('/storage/google/' . $auxDriveId . '?sz=w120') }}" alt="Foto" style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                    @else
                        <div style="width: 68px; height: 48px; border-radius: 4px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #cbd5e0; flex-shrink: 0; border: 1px dashed #e2e8f0;">
                            <i class="material-icons" style="font-size: 20px;">image_not_supported</i>
                        </div>
                    @endif
                    <div style="display: flex; flex-direction: column; flex: 1; min-width: 0; word-break: break-word; overflow-wrap: break-word;">
                        <span style="font-size: 13px; color: #718096; font-weight: 700; text-transform: uppercase;">{{ $auxTipoLabel ?? 'AUXILIAR' }}</span>
                        <div style="color: #4a5568; font-size: 13px;"><strong>S:</strong> {{ $mov->auxiliar->SERIAL ?? '—' }}</div>
                        <div style="color: #475569; font-size: 12.5px; text-transform: uppercase;">{{ $mov->auxiliar->MARCA }} {{ $mov->auxiliar->MODELO }}</div>
                    </div>
                @else
                    @if($equipoFoto)
                        <div style="width: 68px; height: 48px; border-radius: 4px; overflow: hidden; flex-shrink: 0; background: #fff;">
                            <img src="{{ route('drive.file', ['path' => str_replace('/storage/google/', '', $equipoFoto)]) }}" alt="Foto" style="width: 100%; height: 100%; object-fit: contain;">
                        </div>
                    @else
                        <div style="width: 68px; height: 48px; border-radius: 4px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #cbd5e0; flex-shrink: 0; border: 1px dashed #e2e8f0;">
                            <i class="material-icons" style="font-size: 20px;">image_not_supported</i>
                        </div>
                    @endif
                    <div style="display: flex; flex-direction: column; flex: 1; min-width: 0; word-break: break-word; overflow-wrap: break-word;">
                        <span style="font-size: 13px; color: #718096; font-weight: 700; text-transform: uppercase;">{{ $mov->equipo->tipo->nombre ?? 'N/A' }}</span>
                        <div style="color: #4a5568; font-size: 13px;"><strong>S:</strong> {{ $mov->equipo->SERIAL_CHASIS ?? 'S/S' }}</div>
                        <div style="color: var(--maquinaria-blue); font-size: 13px;"><strong>P:</strong> {{ $mov->equipo->documentacion->PLACA ?? 'S/P' }}</div>
                    </div>
                @endif
            </div>
        </td>

        {{-- 2. Trayecto (Origen → Destino) --}}
        <td class="mv-td-trayecto">
            <div class="mv-trayecto-container"
                style="display: flex; align-items: center; justify-content: center; gap: 12px; word-break: break-word; overflow-wrap: break-word;">
                <div class="mv-trayecto-item"
                    style="display: flex; flex-direction: column; align-items: center; max-width: 160px; text-align: center;">
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
                    style="display: flex; flex-direction: column; align-items: center; max-width: 160px; text-align: center;">
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

        {{-- 4. N° Operación (oculto en mobile)
             Si hay CODIGO_CONTROL existe constancia (acta_traslado), por eso
             el MV-XXXXX se renderiza como <a> que abre el visor modal global
             (#pdfPreviewModal vía window.openPdfPreview), igual que las notas
             del Kardex de Almacén. El controller responde con Content-Disposition:
             inline, asi el iframe del modal lo renderiza directo. El boton
             "Descargar" del propio modal sigue disponible para el usuario. --}}
        <td class="mv-col-op mv-mobile-hidden">
            <div style="display: flex; flex-direction: column; align-items: center; line-height: 1.2; gap: 2px;">
                @if($mov->CODIGO_CONTROL)
                    {{-- Defensa: stripear posible prefijo "MV-" en valor crudo
                         para evitar render "MV-MV-XXXXX" en registros antiguos
                         de aux que se guardaron con prefijo. --}}
                    @php
                        $cc = preg_replace('/[^0-9]/', '', (string) $mov->CODIGO_CONTROL);
                        $mvLabel = 'MV-' . str_pad($cc, 5, '0', STR_PAD_LEFT);
                    @endphp
                    <a href="{{ route('movilizaciones.actaTraslado', $mov->ID_MOVILIZACION) }}"
                       onclick="if (typeof window.openPdfPreview === 'function') { event.preventDefault(); window.openPdfPreview(this.href, 'acta_traslado', 'Acta de Traslado {{ $mvLabel }}', 0, '', true, 'movilizaciones'); }"
                       data-no-spa="true"
                       target="_blank" rel="noopener"
                       title="Ver Acta de Traslado (PDF)"
                       style="font-weight: 800; color: #0067b1; font-size: 13px; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;"
                       onmouseover="this.style.textDecoration='underline'"
                       onmouseout="this.style.textDecoration='none'"><i class="material-icons" style="font-size: 16px;">picture_as_pdf</i>{{ $mvLabel }}</a>
                @else
                    <span style="color: #94a3b8; font-size: 13px; font-weight: 600;">--</span>
                @endif
                <div
                    style="display: flex; align-items: center; gap: 4px; color: #64748b; font-size: 13px; font-weight: 600;">
                    <i class="material-icons" style="font-size: 15px;">person</i>
                    {{ $mov->usuario->NOMBRE_COMPLETO ?? $mov->USUARIO_REGISTRO }}
                </div>
                {{-- Deshacer: devuelve el equipo a su frente de ORIGEN y borra el registro
                     (como si nunca ocurrió). Solo super.admin (acción destructiva). --}}
                @can('super.admin')
                <button type="button" onclick="window.movDeshacer({{ $mov->ID_MOVILIZACION }})"
                        title="Deshacer: devolver el equipo a su frente de origen"
                        style="margin-top:4px;display:inline-flex;align-items:center;gap:4px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:8px;padding:3px 9px;font-size:11.5px;font-weight:700;cursor:pointer;transition:background .15s;"
                        onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                    <i class="material-icons" style="font-size:14px;">undo</i>Deshacer
                </button>
                @endcan
            </div>
        </td>

        {{-- 5. Estado — sin contenedor azul/indigo (pedido del cliente),
             solo icono + texto. Color del texto diferencia los 2 tipos. --}}
        <td class="mv-td-estado">
            <div style="display: flex; align-items: center; justify-content: center; gap: 5px; font-size: 11px; font-weight: 800;">
                @if($mov->TIPO_MOVIMIENTO === 'RECEPCION_DIRECTA' || $mov->TIPO_MOVIMIENTO === 'ACT.')
                    <span style="color: #3730a3;">ACTUALIZACIÓN DE UBICACIÓN</span>
                @else
                    <i class="material-icons" style="font-size: 16px; color: #1e40af;">swap_horiz</i>
                    <span style="color: #1e40af;">MOVILIZACIÓN</span>
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