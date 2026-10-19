{{-- Una fila de las cards de Distribución de /admin/equipos ("Ubicación por Frente", por tipo de
     equipo y de auxiliar). Fuente ÚNICA de su markup: la usan distribution_stats y
     aux_distribution_stats, y equipos-offline.js (pintarDistribucion) arma el MISMO HTML con las
     mismas clases (.dist-*, en estilos_globales.css) — si cambia aquí, cambiar allá también.

     $alTocar   onclick (filtra por esa fila)       $nombre  texto de la fila
     $total     cantidad (lo DISPONIBLE)             $pct     ancho de la barra (0–100)
     $barra     color de la barra ('verde' | 'azul')
     $ventaFila FrenteTrabajo::avisoVentaDe() o null: la fila es un frente vendido o en trámite
                → su rótulo DELANTE del nombre ("EN TRÁMITE DE VENTA: NOMBRE") y la barra EN ROJO.
     $vendidos, $enTramite, $pctVenta  los de ese tipo que están en frentes en venta (0 si no):
                se anotan bajo el nombre y van como un tramo ROJO de la barra ($pctVenta), no
                en $total. La letra no cambia de color: lo rojo es solo la barra.
     TODOS se pasan siempre en el @include: un include HEREDA las variables de la vista que lo
     incluye (index.blade.php tiene su propio $venta, el del aviso del frente) y uno que faltara
     tomaría el de allí. --}}
@php
    $nota = array_filter([
        $vendidos > 0 ? $vendidos . ' ' . ($vendidos == 1 ? 'vendido' : 'vendidos') : null,
        $enTramite > 0 ? $enTramite . ' en trámite de venta' : null,
    ]);
@endphp
<li class="dist-fila" onclick="{{ $alTocar }}">
    <div class="dist-cab">
        <span class="dist-nombre">
            @if($ventaFila)<small class="dist-rotulo">{{ $ventaFila['etiqueta'] }}:</small> @endif{{ $nombre }}
            @if($nota)<small class="dist-nota">{{ implode(' · ', $nota) }}</small>@endif
        </span>
        <span class="dist-cant">{{ $total }}</span>
    </div>
    <div class="dist-barra"><div class="dist-barra-{{ $ventaFila ? 'roja' : $barra }}" style="width: {{ $pct }}%;"></div>@if($pctVenta > 0)<div class="dist-barra-roja" style="width: {{ $pctVenta }}%;"></div>@endif</div>
</li>
