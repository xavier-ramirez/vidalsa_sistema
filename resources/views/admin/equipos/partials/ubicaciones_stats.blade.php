{{-- Distribución por DETALLE_UBICACION_ACTUAL — solo se muestra cuando el frente filtrado es fuera de la flota (ESPECIAL, VENDIDO o TRÁMITE DE VENTA).
     Mantiene la MISMA forma/tipografía que la card "Equipos y Maquinaria" (partials/distribution_stats):
     header 13px, ítems 12.5px, badge 2px 8px — para que ambas cards se vean idénticas.
     Arranca RECOGIDA (.is-recogido): el encabezado, con el total, es el botón que despliega la lista
     (eqToggleDetalles en equipos_index.js). --}}
@php
    $ubicacionesStats = $ubicacionesStats ?? collect();
    $totalUbicaciones = $ubicacionesStats->sum('total');
@endphp
<div class="eq-detalles is-recogido">
<h4 class="eq-detalles-toggle" role="button" tabindex="0" aria-expanded="false" title="Desplegar / recoger los detalles"
    onclick="eqToggleDetalles(this)" onkeydown="if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); eqToggleDetalles(this); }"
    style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
    <i class="material-icons" style="font-size: 18px; color: #f97316;">place</i>
    Detalles
    <span style="font-weight: 700; color: #1e293b; font-size: 12.5px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px;">{{ $totalUbicaciones }}</span>
    <i class="material-icons eq-detalles-caret" style="font-size: 20px; color: #64748b; margin-left: auto;">expand_more</i>
</h4>
<ul class="dist-lista eq-detalles-lista custom-scrollbar">
    @if($ubicacionesStats->isEmpty())
        <li style="color: #94a3b8; font-size: 12px; text-align: center; padding: 10px 0;">Sin datos</li>
    @else
        @foreach($ubicacionesStats as $stat)
            @php
                $percentage = $totalUbicaciones > 0 ? ($stat->total / $totalUbicaciones) * 100 : 0;
                $clickValue = $stat->detalle === 'Sin Especificación' ? '' : $stat->detalle;
            @endphp
            <li onclick="const f = document.getElementById('detalleUbicacionFilter'); if (f) { f.value = '{{ addslashes($clickValue) }}'; loadEquipos(); }"
                style="padding-bottom: 4px; border-bottom: 1px dashed #f1f5f9; transition: opacity 0.2s; cursor: {{ $clickValue !== '' ? 'pointer' : 'default' }};"
                @if($clickValue !== '') onmouseover="this.style.opacity='0.7'" onmouseout="this.style.opacity='1'" @endif>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 2px; gap: 4px;">
                    <span style="color: #334155; font-size: 12.5px; font-weight: 600; word-break: break-word; line-height: 1.25; flex: 1;">
                        {{ $stat->detalle }}
                    </span>
                    <span style="font-weight: 700; color: #1e293b; font-size: 12.5px; background: #f1f5f9; padding: 2px 8px; border-radius: 4px; flex-shrink: 0; white-space: nowrap;">
                        {{ $stat->total }}
                    </span>
                </div>
                <div style="width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden;">
                    <div style="width: {{ $percentage }}%; height: 100%; background: linear-gradient(90deg, #f97316 0%, #ea580c 100%); border-radius: 2px;"></div>
                </div>
            </li>
        @endforeach
    @endif
</ul>
</div>
