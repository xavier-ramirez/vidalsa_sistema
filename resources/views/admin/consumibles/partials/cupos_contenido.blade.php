{{-- Cuerpo del modal Cupos de Gasoil. Arriba, en una fila, el patio y su cupo mensual; debajo, por
     patio, UNA tabla con las cisternas del mes elegido (cupos_panel: se repinta sola, por patio). --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $puede = auth()->user()->can('super.admin');
@endphp

<div class="cupos-arriba">
    <div class="cupos-patio">
        <i class="material-icons">location_on</i>
        <select data-elegir-patio aria-label="Patio">
            @foreach ($patios as $clave => $p)
                <option value="{{ $clave }}">{{ $p['nombre'] }}</option>
            @endforeach
        </select>
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
