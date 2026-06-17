{{-- Gráfico de tipo de mantenimiento (Consolidado de Fallas).
     Dona pura-CSS (conic-gradient + máscara radial) — sin dependencias.
     El JS (fallas_index.js → flRenderMantChart) actualiza por CLASES tras filtrar,
     por eso usa .fmc-* en vez de ids (puede haber 2 instancias: móvil y desktop). --}}
@php
    $p = (int) ($stats['preventivo'] ?? 0);
    $c = (int) ($stats['correctivo'] ?? 0);
    $r = (int) ($stats['rapidos'] ?? 0);
    $tot  = $p + $c + $r;
    $base = max(1, $tot);                       // evita división por cero
    $pPct = round($p / $base * 100, 2);
    $cPct = round(($p + $c) / $base * 100, 2);
    // Colores (deben coincidir con los del partial en flRenderMantChart del JS).
    $colPrev = '#34d399'; $colCorr = '#fbbf24'; $colRap = '#60a5fa';
    $grad = $tot > 0
        ? "conic-gradient({$colPrev} 0 {$pPct}%, {$colCorr} {$pPct}% {$cPct}%, {$colRap} {$cPct}% 100%)"
        : 'conic-gradient(rgba(255,255,255,0.15) 0 100%)';
    $mask = 'radial-gradient(circle, transparent 54%, #000 55%)';
@endphp
<div style="display:flex; align-items:center; gap:14px; margin-top:12px; padding-top:12px; border-top:1px solid rgba(255,255,255,0.15);">
    {{-- Dona --}}
    <div style="position:relative; width:74px; height:74px; flex:0 0 auto;">
        <div class="fmc-donut" style="width:74px; height:74px; border-radius:50%; background:{{ $grad }}; -webkit-mask:{{ $mask }}; mask:{{ $mask }};"></div>
        <div style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center;">
            <span class="fmc-total" style="font-size:18px; font-weight:800; color:#fff; line-height:1;">{{ $tot }}</span>
            <span style="font-size:7px; font-weight:700; letter-spacing:0.4px; opacity:0.75; text-transform:uppercase;">Reportes</span>
        </div>
    </div>
    {{-- Leyenda --}}
    <div style="flex:1; display:flex; flex-direction:column; gap:5px; min-width:0;">
        <div style="display:flex; align-items:center; gap:6px; font-size:11px;">
            <span style="width:9px; height:9px; border-radius:2px; background:{{ $colPrev }}; flex:0 0 auto;"></span>
            <span style="opacity:0.85; flex:1;">Preventivo</span>
            <strong class="fmc-prev" style="font-weight:800;">{{ $p }}</strong>
        </div>
        <div style="display:flex; align-items:center; gap:6px; font-size:11px;">
            <span style="width:9px; height:9px; border-radius:2px; background:{{ $colCorr }}; flex:0 0 auto;"></span>
            <span style="opacity:0.85; flex:1;">Correctivo</span>
            <strong class="fmc-corr" style="font-weight:800;">{{ $c }}</strong>
        </div>
        <div style="display:flex; align-items:center; gap:6px; font-size:11px;">
            <span style="width:9px; height:9px; border-radius:2px; background:{{ $colRap }}; flex:0 0 auto;"></span>
            <span style="opacity:0.85; flex:1;">Rápidos</span>
            <strong class="fmc-rap" style="font-weight:800;">{{ $r }}</strong>
        </div>
    </div>
</div>
