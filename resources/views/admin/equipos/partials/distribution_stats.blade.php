{{-- OJO: esta lista está DUPLICADA en pintarDistribucion() de equipos-offline.js, que la
     reconstruye desde la copia local (sin internet el AJAX que la repinta no existe, y en
     teléfono esta card es lo único que se ve dentro del Dashboard de Flota). Si cambia la
     regla de qué lista se muestra, el markup o los onclick, hay que cambiar SIEMPRE LAS DOS
     o la vista sin conexión deja de parecerse a la web. --}}
{{-- Qué lista se muestra lo decide $showFrentes, que llega SIEMPRE del controlador
     (EquipoController::distribucionPorFrente): por FRENTE si se filtra por tipo o por modelo
     sin frente; si no, por TIPO. --}}

@if($showFrentes)
    {{-- Distribución por FRENTE (cuando filtra por tipo o por modelo sin frente) --}}
    <h4 style="margin: 0 0 12px 0; font-size: 13px; text-transform: uppercase; color: #64748b; border-bottom: 2px solid #f1f5f9; padding-bottom: 8px; font-weight: 700; display: flex; align-items: center; gap: 8px;">
        <i class="material-icons" style="font-size: 18px; color: #10b981;">map</i>
        Ubicación por Frente
        @include('admin.equipos.partials.distribution_stats_cam', ['nombre' => 'ubicacion_por_frente'])
    </h4>
    {{-- Sin filtro activo esta lista llega VACÍA y no se pinta nada: el controlador solo
         calcula tiposStats/frentesStats cuando hay filtro (ver EquipoController::index).
         Estuvo un tiempo pintándose siempre, para que la card sirviera de punto de partida,
         hasta que se midió lo que costaba: ~259 ms de GROUP BY y ~60 KB de HTML en CADA
         apertura, con la tabla todavía vacía. --}}
    <ul class="dist-lista custom-scrollbar">
        @php $totalFrentes = $frentesStats->sum('total'); @endphp
        @foreach($frentesStats as $stat)
            @php $percentage = $totalFrentes > 0 ? ($stat->total / $totalFrentes) * 100 : 0; @endphp
            {{-- Los frentes vendidos o en trámite (si el listado los trae): barra en rojo, al final. --}}
            @include('admin.equipos.partials.distribucion_fila', [
                'alTocar' => "selectOption('frenteFilterSelect', '" . $stat->ID_FRENTE_ACTUAL . "', '" . addslashes(trim($stat->NOMBRE_FRENTE ?? '')) . "'); loadEquipos();",
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
        <i class="material-icons" style="font-size: 18px; color: #3b82f6;">autorenew</i>
        Equipos y Maquinaria
        @include('admin.equipos.partials.distribution_stats_cam', ['nombre' => 'equipos_y_maquinaria'])
    </h4>
    <ul class="dist-lista custom-scrollbar">
        {{-- Llega vacía si no hay filtro activo: el controlador solo calcula la distribución
             cuando se ha filtrado (el GROUP BY son ~259 ms y ~60 KB, demasiado para pagarlo
             en cada apertura con la tabla todavía vacía). Sin filtro, este @foreach no pinta
             nada y la card queda en blanco a propósito. --}}
        {{-- La barra se mide sobre todo lo que trae la lista (disponible + en venta): el tramo
             rojo de cada tipo es lo suyo vendido o en trámite. --}}
        @php $totalStats = $tiposStats->sum(fn ($t) => $t->total + (int) ($t->vendidos ?? 0) + (int) ($t->en_tramite ?? 0)); @endphp
        @foreach($tiposStats as $stat)
            @php
                $percentage = $totalStats > 0 ? ($stat->total / $totalStats) * 100 : 0;
                $pctVenta   = $totalStats > 0 ? (((int) ($stat->vendidos ?? 0) + (int) ($stat->en_tramite ?? 0)) / $totalStats) * 100 : 0;
            @endphp
            {{-- 'total' es lo DISPONIBLE; lo de este tipo en frentes en venta, anotado y en rojo en la barra. --}}
            @include('admin.equipos.partials.distribucion_fila', [
                'alTocar'   => "selectOption('tipoFilterSelect', '" . $stat->id_tipo_equipo . "', '" . addslashes(trim($stat->nombre ?? '')) . "'); loadEquipos();",
                'nombre'    => $stat->nombre ?? 'Desconocido',
                'total'     => $stat->total, 'pct' => $percentage, 'barra' => 'azul',
                'vendidos'  => (int) ($stat->vendidos ?? 0), 'enTramite' => (int) ($stat->en_tramite ?? 0), 'pctVenta' => $pctVenta, 'ventaFila' => null,
            ])
        @endforeach
    </ul>
@endif
