@forelse ($events as $event)
    <tr class="hd-selectable-row" data-hd-id="{{ md5($event->equipo_id . $event->tipo . $event->fecha->timestamp) }}">
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
                <div class="hd-cambios-detail" style="display:none;margin-top:6px;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:11px;line-height:1.5;">
                    @foreach($event->cambios as $campo => $val)
                        @php
                            $label = str_replace('_', ' ', $campo);
                            $antes = is_array($val) && isset($val['antes']) ? ($val['antes'] ?? '—') : null;
                            $despues = is_array($val) && isset($val['despues']) ? ($val['despues'] ?? '—') : $val;
                        @endphp
                        <div style="display:flex;gap:4px;flex-wrap:wrap;padding:2px 0;border-bottom:1px solid #f1f5f9;">
                            <span style="font-weight:700;color:#64748b;text-transform:uppercase;font-size:10px;min-width:90px;">{{ $label }}:</span>
                            @if($antes !== null)
                                <span style="color:#dc2626;text-decoration:line-through;">{{ $antes }}</span>
                                <span style="color:#94a3b8;">→</span>
                                <span style="color:#16a34a;font-weight:600;">{{ $despues }}</span>
                            @else
                                <span style="color:#334155;">{{ $despues }}</span>
                            @endif
                        </div>
                    @endforeach
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
