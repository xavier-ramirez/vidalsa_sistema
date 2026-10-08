{{-- Resumen de la barra lateral del Historial de cambios, sobre la MISMA lista que muestra la
     tabla (ver HistorialDocumentosController::resumen). Cada fila filtra la tabla al pulsarla
     (window.hdFiltrarResumen, en historial_documentos_index.js). --}}
@php $fmt = fn ($n) => number_format($n, 0, ',', '.'); @endphp
<div class="hd-res-dos">
    <button type="button" class="hd-res-caja" onclick="window.hdFiltrarResumen({desde: @js($resumen['desde']['hoy'])})" title="Ver los de hoy">
        <small>Hoy</small>
        <strong>{{ $fmt($resumen['hoy']) }}</strong>
    </button>
    <button type="button" class="hd-res-caja" onclick="window.hdFiltrarResumen({desde: @js($resumen['desde']['semana'])})" title="Ver los de los últimos 7 días">
        <small>Últimos 7 días</small>
        <strong>{{ $fmt($resumen['semana']) }}</strong>
    </button>
</div>

@if ($resumen['tipos'])
    <div class="hd-res-caja hd-res-lista">
        <small><i class="material-icons">category</i> Por tipo de acción</small>
        @foreach ($resumen['tipos'] as $t)
            <button type="button" class="hd-res-fila" onclick="window.hdFiltrarResumen({tipo: @js($t['valor']), etiqueta: @js($t['etiqueta'])})">
                <span>{{ $t['etiqueta'] }}</span><b>{{ $fmt($t['n']) }}</b>
            </button>
        @endforeach
    </div>
@endif

@if ($resumen['autores'])
    <div class="hd-res-caja hd-res-lista">
        <small><i class="material-icons">group</i> Quién hizo más cambios</small>
        @foreach ($resumen['autores'] as $a)
            <button type="button" class="hd-res-fila" onclick="window.hdFiltrarResumen({correo: @js($a['correo'])})" title="{{ $a['correo'] }}">
                <span>{{ $a['nombre'] ?: $a['correo'] }}</span><b>{{ $fmt($a['n']) }}</b>
            </button>
        @endforeach
    </div>
@endif
