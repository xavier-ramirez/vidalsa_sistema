{{-- Distribución de inventario por categoría (sidebar). $distribucion = colección de {categoria,total,unidades} --}}
@php $dist = $distribucion ?? collect(); $totalDist = $dist->sum('total'); @endphp

<h4 style="margin:0 0 12px 0;font-size:13px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;">
    <i class="material-icons" style="font-size:18px;color:#3b82f6;">pie_chart</i>
    Distribución de Inventario
</h4>

@if($dist->count() > 0)
    <ul style="list-style:none;padding:0;margin:0;max-height:64vh;overflow-y:auto;overflow-x:visible;display:flex;flex-direction:column;gap:4px;" class="custom-scrollbar">
        @foreach($dist as $row)
            @php $pct = $totalDist > 0 ? ($row->total / $totalDist) * 100 : 0; @endphp
            <li onclick="window.almFilterByCategoria('{{ $row->categoria === 'SIN CATEGORÍA' ? '' : addslashes($row->categoria) }}')"
                style="padding:5px 6px;border-bottom:1px dashed #f1f5f9;cursor:pointer;border-radius:6px;transition:background 0.15s;"
                onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'"
                title="{{ rtrim(rtrim(number_format((float)$row->unidades,3,'.',','),'0'),'.') }} unidades en total">
                <div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;">
                    <span style="color:#334155;font-size:12.5px;font-weight:600;line-height:1.25;flex:1;text-transform:uppercase;">
                        {{ $row->categoria }}
                    </span>
                    <span style="font-weight:700;color:#1e293b;font-size:12.5px;background:#f1f5f9;padding:2px 8px;border-radius:4px;white-space:nowrap;">
                        {{ $row->total }}
                    </span>
                </div>
                <div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">
                    <div style="width:{{ $pct }}%;height:100%;background:linear-gradient(90deg,#3b82f6 0%,#2563eb 100%);"></div>
                </div>
            </li>
        @endforeach
    </ul>
@else
    <p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">Sin datos para mostrar.</p>
@endif
