@forelse ($events as $event)
    <tr class="hd-selectable-row {{ !empty($event->cambios) ? 'hd-has-cambios' : '' }}" data-hd-id="{{ md5($event->equipo_id . $event->tipo . $event->fecha->timestamp) }}">
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
            <div style="font-weight: 600; color: #334155; line-height: 1.3;">{{ $event->equipo_nombre }}</div>
            @if($event->equipo_id)<div style="font-size: 12px; color: #475569; font-weight: 600;">{{ $event->equipo_id }}</div>@endif
            @if(!empty($event->cambios))
                <div class="hd-cambios-detail" style="display:none;margin-top:8px;">
                    <div style="background:#1e293b;border-radius:10px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                        <div style="padding:8px 12px;display:flex;align-items:center;gap:6px;border-bottom:1px solid #334155;">
                            <i class="material-icons" style="font-size:14px;color:#38bdf8;">history</i>
                            <span style="font-size:11px;font-weight:700;color:#cbd5e1;text-transform:uppercase;letter-spacing:0.5px;">Cambios realizados</span>
                        </div>
                        <div style="padding:8px 12px;">
                            @foreach($event->cambios as $campo => $val)
                                @php
                                    $label = str_replace('_', ' ', $campo);
                                    $esDiff = is_array($val) && array_key_exists('antes', $val);
                                    $antes = $esDiff ? ($val['antes'] !== null && $val['antes'] !== '' ? $val['antes'] : '(vacío)') : null;
                                    $despues = $esDiff ? ($val['despues'] !== null && $val['despues'] !== '' ? $val['despues'] : '(vacío)') : $val;
                                @endphp
                                <div style="display:flex;align-items:baseline;gap:6px;padding:4px 0;border-bottom:1px solid rgba(255,255,255,0.06);flex-wrap:wrap;">
                                    <span style="font-weight:700;color:#94a3b8;text-transform:uppercase;font-size:10px;min-width:80px;flex-shrink:0;">{{ $label }}</span>
                                    @if($antes !== null)
                                        <span style="color:#fca5a5;text-decoration:line-through;font-size:12px;">{{ $antes }}</span>
                                        <i class="material-icons" style="font-size:12px;color:#64748b;">arrow_forward</i>
                                        <span style="color:#86efac;font-weight:700;font-size:12px;">{{ $despues }}</span>
                                    @else
                                        <span style="color:#e2e8f0;font-size:12px;">{{ $despues }}</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </td>
        <td style="text-align: center;">
            @if($event->link)
                <button type="button" class="btn-view-pdf" onclick="openPdfPreview('{{ $event->link }}', '{{ $event->doc_key }}', '{{ $event->tipo }}', '{{ $event->equipo_db_id ?? '' }}')" title="Visualizar Documento">
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
