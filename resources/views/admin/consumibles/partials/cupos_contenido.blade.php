{{-- Cuerpo del modal Cupos de Gasoil (se repinta solo tras guardar): arriba se elige el patio en el
     desplegable; de ese patio se ve el cupo (lápiz para cambiarlo) y lo que queda este mes, se cargan
     las cisternas surtidas en una tabla (una fila por cisterna: fecha y litros) y debajo está su
     historial mes a mes. --}}
@php
    $lt = fn ($n) => number_format((float) $n, fmod((float) $n, 1) ? 2 : 0, ',', '.');
    $colores = ['TIGRE' => '#1F4E79', 'MATURIN' => '#5B9BD5'];
    $puede = auth()->user()->can('super.admin');
    $nombreMes = fn ($m) => \App\Http\Controllers\CuposCombustibleController::nombreMes($m);
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
    @php
        $pasado = $p['cupo'] !== null && $p['quedan'] < 0;
        $pct = $p['cupo'] ? min(100, $p['surtido'] / $p['cupo'] * 100) : ($p['surtido'] > 0 ? 100 : 0);
    @endphp
    <div class="cupos-panel" data-panel="{{ $clave }}" style="--color: {{ $colores[$clave] }};" hidden>
        {{-- Tres cifras del mismo tamaño: el cupo (se edita con el lápiz), lo surtido y lo que queda en el mes en curso. --}}
        <div class="cupos-cifras">
            <div class="cupos-cifra">
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
            <div class="cupos-cifra">
                <small>Surtido del mes</small>
                <b>{{ $lt($p['surtido']) }} L</b>
            </div>
            <div class="cupos-cifra">
                <small>Quedan del mes</small>
                <b style="color: {{ $pasado ? '#dc2626' : 'inherit' }};">{{ $p['cupo'] !== null ? $lt($p['quedan']) . ' L' : '—' }}</b>
            </div>
        </div>
        <div class="cupos-barra"><i style="width: {{ round($pct, 1) }}%; background: {{ $pasado ? '#dc2626' : $colores[$clave] }};"></i></div>

        @if ($puede)
            {{-- Una fila por cisterna; «Otra fila» (o Enter en los litros) agrega más y se guardan todas juntas. --}}
            <form class="cupos-lote" data-accion="cisterna">
                <input type="hidden" name="patio" value="{{ $clave }}">
                <div class="cupos-lote-titulo">Cargar cisternas</div>
                <table class="cupos-lote-tbl">
                    <colgroup><col style="width: 34px;"><col><col><col style="width: 38px;"></colgroup>
                    <thead><tr><th>#</th><th>Fecha</th><th>Litros</th><th></th></tr></thead>
                    <tbody data-filas>
                        <tr data-fila>
                            <td class="cupos-lote-n">1</td>
                            <td><input type="date" data-campo="fecha" required value="{{ now()->toDateString() }}"
                                min="{{ \App\Http\Controllers\CuposCombustibleController::desde()->toDateString() }}" max="{{ now()->toDateString() }}"></td>
                            <td><input type="text" data-campo="litros" inputmode="decimal" placeholder="Litros" autocomplete="off"></td>
                            <td><button type="button" class="cupos-borrar" data-quitar-fila title="Quitar fila"><i class="material-icons">close</i></button></td>
                        </tr>
                    </tbody>
                </table>
                <div class="cupos-lote-pie">
                    <button type="button" class="cupos-otra" data-otra-fila><i class="material-icons">add</i> Otra fila</button>
                    <span class="cupos-lote-total" data-lote-total></span>
                    <button type="submit" class="btn-primary-maquinaria btn-compacto"><i class="material-icons">save</i> Guardar cisternas</button>
                </div>
            </form>
        @endif

        <div class="cupos-tbl-wrap">
            <table class="cupos-tbl">
                <colgroup><col><col style="width: 30%;"><col style="width: 30%;">@if ($puede)<col style="width: 40px;">@endif</colgroup>
                <thead><tr><th>Fecha</th><th class="num">Litros</th><th class="num">Quedan</th>@if ($puede)<th></th>@endif</tr></thead>
                @forelse ($p['meses'] as $m)
                    <tbody>
                        <tr class="cupos-mes">
                            <td colspan="{{ $puede ? 4 : 3 }}">
                                <b>{{ $nombreMes($m['mes']) }}</b>
                                <span>Cupo {{ $m['cupo'] !== null ? $lt($m['cupo']) . ' L' : '—' }} · Surtido {{ $lt($m['surtido']) }} L ({{ $m['filas']->count() }})
                                    · Quedó <b style="color: {{ $m['quedan'] !== null && $m['quedan'] < 0 ? '#dc2626' : 'inherit' }};">{{ $m['quedan'] !== null ? $lt($m['quedan']) . ' L' : '—' }}</b></span>
                            </td>
                        </tr>
                        @foreach ($m['filas'] as $f)
                            @php $k = $f['cisterna']; @endphp
                            <tr>
                                <td>{{ $k->FECHA->format('d/m/Y') }}</td>
                                <td class="num">{{ $lt($k->LITROS) }} L</td>
                                <td class="num" style="color: {{ $f['quedan'] !== null && $f['quedan'] < 0 ? '#dc2626' : 'inherit' }};">{{ $f['quedan'] !== null ? $lt($f['quedan']) . ' L' : '—' }}</td>
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
                    <tbody><tr><td colspan="{{ $puede ? 4 : 3 }}" class="cupos-vacio"><i class="material-icons">local_shipping</i>Todavía no hay cisternas de {{ $p['nombre'] }}.</td></tr></tbody>
                @endforelse
            </table>
        </div>
    </div>
@endforeach
