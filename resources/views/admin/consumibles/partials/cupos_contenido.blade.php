{{-- Cuerpo del modal Cupos de Gasoil (se repinta solo tras guardar). Arriba: el patio (desplegable) y
     su resumen del mes (cupo con lápiz, surtido, quedan). Debajo, UNA tabla: arriba las filas para
     cargar cisternas nuevas (fecha y litros; «Quedan» se calcula al escribir) y luego el historial
     mes a mes con sus cisternas. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $puede = auth()->user()->can('super.admin');
    $nombreMes = fn ($m) => \App\Http\Controllers\CuposCombustibleController::nombreMes($m);
    $rojo = fn ($n) => $n !== null && $n < 0 ? ' cupos-pasado' : '';
    $cols = $puede ? 4 : 3;
@endphp

<label class="cupos-patio">
    <span>Patio</span>
    <select data-elegir-patio>
        @foreach ($patios as $clave => $p)
            <option value="{{ $clave }}">{{ $p['nombre'] }}</option>
        @endforeach
    </select>
</label>

@foreach ($patios as $clave => $p)
    @php $pct = $p['cupo'] ? min(100, $p['surtido'] / $p['cupo'] * 100) : ($p['surtido'] > 0 ? 100 : 0); @endphp
    <div class="cupos-panel" data-panel="{{ $clave }}" style="--color: {{ $colores[$clave] }};" hidden
        data-mes="{{ $hoy->format('Y-m') }}" data-quedan="{{ $p['quedan'] ?? '' }}">

        {{-- Resumen del mes en curso: tres cifras en una franja, y la barra de lo gastado. --}}
        <div class="cupos-resumen" title="{{ $nombreMes($hoy) }}">
            <div class="cupos-dato">
                <small>Cupo mensual</small>
                <span class="cupos-cupo-ver">
                    <b>{{ $p['cupo'] !== null ? $lt($p['cupo']) . ' L' : 'Sin cargar' }}</b>
                    @if ($puede)
                        <button type="button" class="cupos-lapiz" data-editar-cupo title="{{ $p['cupo'] !== null ? 'Cambiar el cupo' : 'Cargar el cupo' }}"><i class="material-icons">edit</i></button>
                    @endif
                </span>
                @if ($puede)
                    <form class="cupos-cupo-editar" data-accion="cupo" hidden>
                        <input type="hidden" name="patio" value="{{ $clave }}">
                        <input type="text" name="litros" inputmode="decimal" required placeholder="Litros" autocomplete="off"
                            value="{{ $p['cupo'] !== null ? $lt($p['cupo']) : '' }}">
                        <button type="submit" class="cupos-mini" title="Guardar"><i class="material-icons">check</i></button>
                        <button type="button" class="cupos-mini cupos-mini-gris" data-cancelar-cupo title="Cancelar"><i class="material-icons">close</i></button>
                    </form>
                @endif
            </div>
            <div class="cupos-dato">
                <small>Surtido del mes</small>
                <b>{{ $lt($p['surtido']) }} L</b>
            </div>
            <div class="cupos-dato">
                <small>Quedan del mes</small>
                <b class="{{ $rojo($p['quedan']) }}">{{ $p['cupo'] !== null ? $lt($p['quedan']) . ' L' : '—' }}</b>
            </div>
        </div>
        <div class="cupos-barra"><i style="width: {{ round($pct, 1) }}%; background: {{ $p['cupo'] !== null && $p['quedan'] < 0 ? '#dc2626' : $colores[$clave] }};"></i></div>

        @if ($puede)<form class="cupos-tabla" data-accion="cisterna"><input type="hidden" name="patio" value="{{ $clave }}">
        @else<div class="cupos-tabla">@endif
            <table class="cupos-tbl">
                <colgroup><col><col style="width: 30%;"><col style="width: 30%;">@if ($puede)<col style="width: 40px;">@endif</colgroup>
                <thead><tr><th>Fecha</th><th class="num">Litros</th><th class="num">Quedan</th>@if ($puede)<th></th>@endif</tr></thead>

                @if ($puede)
                    {{-- Cisternas nuevas: una fila cada una; Enter u «Otra fila» agrega otra y se guardan juntas. --}}
                    <tbody class="cupos-nuevas" data-filas>
                        <tr data-fila>
                            <td><input type="date" data-campo="fecha" required value="{{ now()->toDateString() }}" aria-label="Fecha"
                                min="{{ \App\Http\Controllers\CuposCombustibleController::desde()->toDateString() }}" max="{{ now()->toDateString() }}"></td>
                            <td><input type="text" data-campo="litros" class="num" inputmode="decimal" placeholder="Litros" autocomplete="off" aria-label="Litros"></td>
                            <td class="num cupos-previo" data-previo>—</td>
                            <td class="cupos-acc"><button type="button" class="cupos-borrar" data-quitar-fila title="Quitar fila"><i class="material-icons">close</i></button></td>
                        </tr>
                    </tbody>
                    <tbody class="cupos-nuevas-pie">
                        <tr><td colspan="{{ $cols }}"><div class="cupos-pie">
                            <button type="button" class="cupos-otra" data-otra-fila><i class="material-icons">add</i> Otra fila</button>
                            <span class="cupos-lote-total" data-lote-total></span>
                            <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">save</i> Guardar cisternas</button>
                        </div></td></tr>
                    </tbody>
                @endif

                @forelse ($p['meses'] as $m)
                    <tbody>
                        <tr class="cupos-mes">
                            <td colspan="{{ $cols }}">
                                <b>{{ $nombreMes($m['mes']) }}</b>
                                <span>@if ($m['cupo'] !== $p['cupo'])Cupo {{ $m['cupo'] !== null ? $lt($m['cupo']) . ' L' : '—' }} · @endif{{ $m['filas']->count() === 1 ? '1 cisterna' : $m['filas']->count() . ' cisternas' }} · Surtido {{ $lt($m['surtido']) }} L · Quedó <b class="{{ $rojo($m['quedan']) }}">{{ $m['quedan'] !== null ? $lt($m['quedan']) . ' L' : '—' }}</b></span>
                            </td>
                        </tr>
                        @foreach ($m['filas'] as $f)
                            @php $k = $f['cisterna']; @endphp
                            <tr>
                                <td>{{ $k->FECHA->format('d/m/Y') }}</td>
                                <td class="num">{{ $lt($k->LITROS) }} L</td>
                                <td class="num{{ $rojo($f['quedan']) }}">{{ $f['quedan'] !== null ? $lt($f['quedan']) . ' L' : '—' }}</td>
                                @if ($puede)
                                    <td class="cupos-acc">
                                        <button type="button" class="cupos-borrar" title="Quitar" data-borrar="{{ $k->ID_CISTERNA }}"
                                            data-texto="{{ $p['nombre'] }} · {{ $k->FECHA->format('d/m/Y') }} · {{ $lt($k->LITROS) }} L">
                                            <i class="material-icons">delete_outline</i>
                                        </button>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                @empty
                    <tbody><tr><td colspan="{{ $cols }}" class="cupos-vacio">Todavía no hay cisternas de {{ $p['nombre'] }}.</td></tr></tbody>
                @endforelse
            </table>
        @if ($puede)</form>@else</div>@endif
    </div>
@endforeach
