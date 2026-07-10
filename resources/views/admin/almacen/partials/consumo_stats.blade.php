{{-- Ranking de Consumo (sidebar de la Bitácora). $consumo = colección de {id_producto,codigo,nombre,um,total,movimientos}.
     Mismo lenguaje visual que el partial admin.almacen.partials.distribucion_stats, pero los porcentajes
     se calculan relativo al MAYOR consumo (no al total acumulado) para que el top siempre llegue al 100%
     y la barra refleje "qué tan dominante es cada producto frente al más consumido del periodo". --}}
@php
    $cons     = $consumo ?? collect();
    $maxTotal = $cons->max('total') ?: 0;
    $fmt      = function ($v) {
        return rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
    };
@endphp

<h4 style="margin:0 0 12px 0;font-size:13px;text-transform:uppercase;color:#64748b;border-bottom:2px solid #f1f5f9;padding-bottom:8px;font-weight:700;display:flex;align-items:center;gap:8px;">
    <i class="material-icons" style="font-size:18px;color:#22c55e;">trending_down</i>
    Consumo de Inventario
</h4>

@if($cons->count() > 0)
    <ul style="list-style:none;padding:0;margin:0;max-height:67vh;overflow-y:auto;overflow-x:visible;display:flex;flex-direction:column;gap:4px;" class="custom-scrollbar">
        @foreach($cons as $row)
            @php $pct = $maxTotal > 0 ? ($row->total / $maxTotal) * 100 : 0; @endphp
            {{-- json_encode + {{ }} HTML-escapa las " del JSON → &quot;; necesario porque @json() emite
                 " literales que rompen el atributo onclick="" (causaba JS "Unexpected end of input"). --}}
            <li onclick="window.almMovFiltrarPorProducto && window.almMovFiltrarPorProducto({{ (int) $row->id_producto }}, {{ json_encode($row->nombre) }})"
                style="padding:5px 6px;border-bottom:1px dashed #f1f5f9;cursor:pointer;border-radius:6px;transition:background 0.15s;"
                onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'"
                title="{{ $row->movimientos }} {{ $row->movimientos == 1 ? 'movimiento' : 'movimientos' }} de salida — {{ $fmt($row->total) }} {{ $row->um }}">
                <div style="display:flex;justify-content:space-between;margin-bottom:2px;gap:4px;">
                    <span style="color:#334155;font-size:11px;font-weight:600;line-height:1.25;flex:1;text-transform:uppercase;word-break:break-word;">
                        {{ $row->nombre }}
                    </span>
                    <span style="font-weight:600;font-size:10.5px;color:#475569;white-space:nowrap;padding:2px 0;">
                        {{ $fmt($row->total) }} {{ $row->um }}
                    </span>
                </div>
                <div style="width:100%;height:4px;background:#e2e8f0;border-radius:2px;overflow:hidden;">
                    <div style="width:{{ $pct }}%;height:100%;background:linear-gradient(90deg,#4ade80 0%,#22c55e 100%);"></div>
                </div>
            </li>
        @endforeach
    </ul>
@else
    @php
        // Distinguimos "no hay filtros y la BD no tiene salidas" vs "los filtros no devuelven resultados".
        // Se listan TODOS los filtros que movimientos() interpreta; faltaban `nota` y `tipo`, así que
        // filtrar sólo por ellos mostraba "aún no se han registrado salidas" aunque sí las hubiera.
        // Para `id_frente`/`tipo` el valor 'all' es el centinela de "sin filtro" (igual que en el
        // controlador), y `id_almacen` cuenta como filtro: acota el ranking aunque venga por defecto.
        $noEsAll = fn ($k) => request()->filled($k) && request()->input($k) !== 'all';
        $hayFiltros = request()->filled('id_almacen') || $noEsAll('id_frente') || $noEsAll('tipo')
            || request()->filled('search') || request()->filled('desde') || request()->filled('hasta')
            || request()->filled('id_producto') || request()->filled('nota');
    @endphp
    <p style="color:#94a3b8;font-size:12px;margin:8px 0 0 0;">
        @if($hayFiltros)
            Sin salidas registradas para los filtros actuales.
        @else
            Aún no se han registrado salidas en el sistema. El ranking se generará automáticamente cuando se registren salidas o traspasos.
        @endif
    </p>
@endif
