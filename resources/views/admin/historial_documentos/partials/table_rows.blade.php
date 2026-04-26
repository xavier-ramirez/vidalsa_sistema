@forelse ($events as $event)
    @php
        // Separar TIPO de MARCA/MODELO para que mobile pueda ocultar el resto
        // sin perder el TIPO. equipo_nombre = "TIPO MARCA MODELO" del controller.
        $partes = explode(' ', trim((string) $event->equipo_nombre), 2);
        $hdTipo  = $partes[0] ?? '—';
        $hdResto = $partes[1] ?? '';
    @endphp
    <tr class="hd-selectable-row"
        data-hd-id="{{ md5($event->equipo_id . $event->tipo . $event->fecha->timestamp) }}"
        data-hd-fecha="{{ $event->fecha->format('d/m/Y h:i A') }}"
        data-hd-autor="{{ $event->autor }}"
        data-hd-doctipo="{{ $event->tipo }}"
        data-hd-equipo="{{ $event->equipo_nombre }}"
        data-hd-equipo-id="{{ $event->equipo_id }}"
        data-hd-link="{{ $event->link ?? '' }}"
        data-hd-link-key="{{ $event->doc_key ?? '' }}"
        data-hd-equipo-db="{{ $event->equipo_db_id ?? '' }}"
        onclick="window.openHdEventDetails && window.openHdEventDetails(this)"
        style="cursor:pointer;">
        <td>
            <div style="display: flex; flex-direction: column;">
                <span style="font-weight: 600;">{{ $event->fecha->format('d/m/Y') }}</span>
                <span style="font-size: 12px; color: #94a3b8;">{{ $event->fecha->format('h:i A') }}</span>
            </div>
        </td>
        <td>
            <span class="badge-autor">
                <i class="material-icons" style="font-size: 16px;">person</i>
                {{ $event->autor }}
            </span>
        </td>
        <td>
            <span class="badge-doc">
                <i class="material-icons" style="font-size: 16px;">description</i>
                {{ $event->tipo }}
            </span>
        </td>
        <td>
            <div style="font-weight: 600; color: #334155; line-height: 1.3;">
                <span class="hd-equipo-tipo">{{ $hdTipo }}</span>
                @if($hdResto)
                    <span class="hd-equipo-marca-modelo">{{ ' ' . $hdResto }}</span>
                @endif
            </div>
            <div style="font-size: 12px; color: #94a3b8;">{{ $event->equipo_id }}</div>
        </td>
        <td style="text-align: center;">
            @if($event->link)
                <button type="button" class="btn-view-pdf" onclick="event.stopPropagation(); openPdfPreview('{{ $event->link }}', '{{ $event->doc_key }}', '{{ $event->tipo }}', '{{ $event->equipo_db_id ?? '' }}')" title="Visualizar Documento">
                    <i class="material-icons" style="font-size: 20px;">picture_as_pdf</i>
                </button>
            @else
                <span style="color: #cbd5e1; font-size: 12px;">N/A</span>
            @endif
        </td>
    </tr>
@empty
    <tr>
        <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
            <div style="display: flex; flex-direction: column; align-items: center; gap: 10px;">
                <i class="material-icons" style="font-size: 48px; opacity: 0.3; margin: 0 auto;">inbox</i>
                <span>No hay registro de documentos actualizados todavía.</span>
            </div>
        </td>
    </tr>
@endforelse
