{{-- Distribución de AUXILIARES en /admin/equipos (modo aux). Espeja
     admin.equipos.partials.distribution_stats:
     - Con un TIPO de aux seleccionado y SIN frente ($showFrentes): "Ubicación por
       Frente" → cuántos de ese tipo hay en CADA frente. Click filtra ese frente.
     - Si no: distribución por TIPO (navegar entre tipos). Click filtra 'tipo_aux:CODIGO'.
     $auxDistribucion = [['tipo','label','total','vendidos','en_tramite'], ...] ('total' = lo disponible);
     $auxDistribucionFrentes = Collection (con TIPO_FRENTE). Cada fila: distribucion_fila. --}}
@if(($showFrentes ?? false) && !empty($auxDistribucionFrentes) && count($auxDistribucionFrentes))
    {{-- Distribución por FRENTE (cuando filtra por tipo aux sin frente) --}}
    <h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="material-icons" style="font-size: 18px; color: #10b981;">map</i>
        Ubicación por Frente
    </h4>
    <ul class="dist-lista custom-scrollbar">
        @php $totalFrentesAux = collect($auxDistribucionFrentes)->sum('total'); @endphp
        @foreach($auxDistribucionFrentes as $stat)
            @php $percentage = $totalFrentesAux > 0 ? ($stat->total / $totalFrentesAux) * 100 : 0; @endphp
            {{-- Los frentes vendidos o en trámite (si el listado los trae): barra en rojo, al final. --}}
            @include('admin.equipos.partials.distribucion_fila', [
                'alTocar' => "selectOption('frenteFilterSelect', '" . $stat->ID_FRENTE_ACTUAL . "', '" . addslashes($stat->NOMBRE_FRENTE ?? 'Sin Asignar') . "'); loadEquipos();",
                'nombre'  => $stat->NOMBRE_FRENTE ?? 'Sin Asignar',
                'total'   => $stat->total, 'pct' => $percentage, 'barra' => 'verde',
                'ventaFila' => \App\Models\FrenteTrabajo::avisoVentaDe($stat->TIPO_FRENTE ?? null),
                'vendidos'  => 0, 'enTramite' => 0, 'pctVenta' => 0,
            ])
        @endforeach
    </ul>
@else
    {{-- Distribución por TIPO (vista por defecto) --}}
    <h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="material-icons" style="font-size: 18px; color: #10b981;">autorenew</i>
        Auxiliares
    </h4>
    <ul class="dist-lista custom-scrollbar">
        {{-- La barra se mide sobre todo lo que trae la lista (disponible + en venta): el tramo
             rojo de cada tipo es lo suyo vendido o en trámite. --}}
        @php $totalAux = collect($auxDistribucion)->sum(fn ($d) => $d['total'] + ($d['vendidos'] ?? 0) + ($d['en_tramite'] ?? 0)); @endphp
        @foreach($auxDistribucion as $d)
            @php
                $percentage = $totalAux > 0 ? ($d['total'] / $totalAux) * 100 : 0;
                $pctVenta   = $totalAux > 0 ? ((($d['vendidos'] ?? 0) + ($d['en_tramite'] ?? 0)) / $totalAux) * 100 : 0;
            @endphp
            {{-- 'total' es lo DISPONIBLE; lo de este tipo en frentes en venta, anotado y en rojo en la barra. --}}
            @include('admin.equipos.partials.distribucion_fila', [
                'alTocar'   => "selectOption('tipoFilterSelect', 'tipo_aux:" . $d['tipo'] . "', '" . addslashes($d['label']) . "'); loadEquipos();",
                'nombre'    => $d['label'],
                'total'     => $d['total'], 'pct' => $percentage, 'barra' => 'verde',
                'vendidos'  => $d['vendidos'] ?? 0, 'enTramite' => $d['en_tramite'] ?? 0, 'pctVenta' => $pctVenta, 'ventaFila' => null,
            ])
        @endforeach
    </ul>
@endif
