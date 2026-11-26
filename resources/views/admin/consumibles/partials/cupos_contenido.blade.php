{{-- Cuerpo del modal Cupos de Gasoil. Arriba, en una fila, el patio y su cupo mensual; debajo, por
     patio, UNA tabla con las cisternas del mes elegido (cupos_panel: se repinta sola, por patio). --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $puede = auth()->user()->can('super.admin');
@endphp

{{-- Patio y mes con las listas de la aplicación (custom-dropdown: uicomponents.js las abre y cierra, una a la
     vez); elegir una opción lo atiende cupos_seccion (data-elegir-patio / data-elegir-mes). --}}
@php $mesActual = $mes->format('Y-m'); @endphp
<div class="cupos-arriba">
    {{-- Cada lista con su etiqueta arriba, en letra pequeña, como el cupo. --}}
    <div class="cupos-campo">
    <span class="cupos-cupo-titulo">Patio</span>
    <div class="custom-dropdown cupos-dd" data-elegir-patio data-valor="TIGRE">
        <div class="dropdown-trigger cupos-dd-boton" tabindex="0" aria-label="Patio">
            <i class="material-icons">location_on</i><span data-dd-texto>{{ $patios['TIGRE']['nombre'] ?? '' }}</span><i class="material-icons">arrow_drop_down</i>
        </div>
        <div class="dropdown-content">
            @foreach ($patios as $clave => $p)
                <div class="dropdown-item" data-value="{{ $clave }}">{{ $p['nombre'] }}</div>
            @endforeach
        </div>
    </div>
    </div>
    {{-- El mes de la tabla: el actual para cargar; los anteriores son el historial; los que vienen, para programar. --}}
    <div class="cupos-campo">
    <span class="cupos-cupo-titulo">Mes</span>
    <div class="custom-dropdown cupos-dd" data-elegir-mes data-valor="{{ $mesActual }}">
        <div class="dropdown-trigger cupos-dd-boton" tabindex="0" aria-label="Mes">
            <i class="material-icons">event</i><span data-dd-texto>{{ $meses[$mesActual] ?? '' }}</span><i class="material-icons">arrow_drop_down</i>
        </div>
        <div class="dropdown-content">
            @foreach ($meses as $clMes => $nombreMes)
                <div class="dropdown-item{{ $clMes === $mesActual ? ' selected' : '' }}" data-value="{{ $clMes }}">{{ $nombreMes }}</div>
            @endforeach
        </div>
    </div>
    </div>
    {{-- El cupo del patio elegido, al lado (un cuadro por patio; se ve el del elegido). Se guarda con
         Enter o al salir del cuadro. --}}
    @foreach ($patios as $clave => $p)
        <div class="cupos-cupo-lado" data-cupo-de="{{ $clave }}" hidden>
            <span class="cupos-cupo-titulo">Cupo mensual</span>
            @if ($puede)
                <form class="cupos-cupo" data-accion="cupo">
                    <input type="hidden" name="patio" value="{{ $clave }}">
                    <label class="cupos-cupo-caja">
                        <input type="text" name="litros" inputmode="decimal" placeholder="Escriba el cupo" autocomplete="off"
                            aria-label="Cupo mensual de {{ $p['nombre'] }} en litros" value="{{ $p['cupo'] !== null ? $lt($p['cupo']) : '' }}">
                        <span>L</span>
                    </label>
                </form>
            @elseif ($p['cupo'] !== null)
                <b class="cupos-cupo-valor">{{ $lt($p['cupo']) }} L</b>
            @else
                <b class="cupos-cupo-valor cupos-tenue">Sin cargar</b>
            @endif
        </div>
    @endforeach
</div>

@foreach ($patios as $clave => $p)
    @include('admin.consumibles.partials.cupos_panel')
@endforeach
