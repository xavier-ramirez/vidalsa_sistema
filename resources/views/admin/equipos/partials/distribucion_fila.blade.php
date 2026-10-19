{{-- Una fila de las cards de Distribución de /admin/equipos ("Ubicación por Frente", por tipo de
     equipo y de auxiliar). Fuente ÚNICA de su markup: la usan distribution_stats y
     aux_distribution_stats, y equipos-offline.js (pintarDistribucion) arma el MISMO HTML con las
     mismas clases (.dist-*, en estilos_globales.css) — si cambia aquí, cambiar allá también.

     $alTocar   onclick (filtra por esa fila)       $nombre  texto de la fila
     $total     cantidad (lo DISPONIBLE)             $pct     ancho de la barra (0–100)
     $barra     color de la barra ('verde' | 'azul')
     $venta     (opcional) FrenteTrabajo::avisoVentaDe(): la fila es un frente vendido o en
                trámite → en rojo, con su rótulo.
     $vendidos, $enTramite (opcionales) los de ese tipo que están en frentes en venta: se anotan
                en rojo bajo el nombre (no van en $total). --}}
@php
    $venta = $venta ?? null;
    $nota = array_filter([
        ($vendidos ?? 0) > 0 ? $vendidos . ' ' . (($vendidos ?? 0) == 1 ? 'vendido' : 'vendidos') : null,
        ($enTramite ?? 0) > 0 ? $enTramite . ' en trámite de venta' : null,
    ]);
@endphp
<li class="dist-fila{{ $venta ? ' dist-venta' : '' }}" onclick="{{ $alTocar }}">
    <div class="dist-cab">
        <span class="dist-nombre">
            {{ $nombre }}
            @if($venta)<small class="dist-rotulo">{{ $venta['etiqueta'] }}</small>@endif
            @if($nota)<small class="dist-nota">{{ implode(' · ', $nota) }}</small>@endif
        </span>
        <span class="dist-cant">{{ $total }}</span>
    </div>
    <div class="dist-barra"><div class="dist-barra-{{ $venta ? 'roja' : $barra }}" style="width: {{ $pct }}%;"></div></div>
</li>
